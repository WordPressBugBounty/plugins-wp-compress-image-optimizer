<?php
/**
 * WP Compress pull-manifest adapter.
 *
 * The pull-architecture answer to managed-WP-host WAFs blocking orch's
 * outbound POSTs to /wpc/v2/bg_swap_batch. Instead of orch initiating the
 * inbound request, plugin polls GET /optimize-v2/manifest from the customer
 * host. Same outbound channel customer firewalls already allow.
 *
 * Spec: ~/Downloads/wpc-v704-v705-plugin-driven-architecture-spec.md v1.1
 *
 * This file is intentionally narrow: it adapts manifest entries into the
 * existing 'persisted_pending_bytes' journal-entry shape and lets the
 * journal drain (added in v7.02.2) do the heavy lifting (curl_multi pull, sha256
 * verify, atomic disk write, ic_local_variants merge). No new merge logic
 * required — the drain doesn't care whether the entry came from a push
 * callback or a pull-manifest poll.
 *
 * Phase 1: pull endpoint coexists with push; dedupe-by-sha256 in the bg_swap
 * handlers makes "whichever lands first wins" idempotent.
 *
 * Coordinated with LS v3.18.69 manifest contract (newest-first sort,
 * pagination via limit, Redis ZSET data layer, 3s in-pod thundering-herd
 * cache, delivery_method field).
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Feature flag — pull infrastructure on/off for this site.
 *
 *   Phase 1 (default OFF): manifest endpoint exists on orch, plugin code
 *     ships dormant. No customer-visible change.
 *   Phase 2 (flag ON for staging): pull alongside push. Dedupe by sha256;
 *     whichever lands first wins. Defense in depth — if WAF blocks POSTs,
 *     pull catches the variants ~30 s later.
 *   Phase 3 (flag ON for production batches): push disabled per-site;
 *     pull-only.
 *
 * Site option `wpc_v2_pull_enabled` (1/0). Filterable for runtime override.
 */
if (!function_exists('wpc_v2_pull_enabled')) {
    function wpc_v2_pull_enabled()
    {
        $opt = get_site_option('wpc_v2_pull_enabled', null);
        if ($opt === null) {
            // Default ON for Smart Delivery sites (zone + CDN live): push POSTs
            // from pod IPs get WAF-blocked on many hosts, so pull is the only
            // reliable ingestion lane. Only fires when the option row is ABSENT
            // — an explicit stored 0 (operator opt-out) is respected.
            $zone_ok = function_exists('wpc_v2_get_zone_id') && wpc_v2_get_zone_id();
            $s_pull  = get_option(WPS_IC_SETTINGS);
            $cdn_on  = is_array($s_pull) && !empty($s_pull['live-cdn']) && (string) $s_pull['live-cdn'] === '1';
            return (bool) apply_filters('wpc_v2_pull_enabled', (bool) ($zone_ok && $cdn_on));
        }
        return (bool) apply_filters('wpc_v2_pull_enabled', !empty($opt));
    }
}

/**
 * HMAC signing for /optimize-v2/manifest endpoints.
 *
 * Contract (LS v3.18.69):
 *   - Headers (BOTH required, case-insensitive — send canonical case):
 *       X-WPC-Sig:       lowercase hex HMAC-SHA256
 *       X-WPC-Timestamp: Unix SECONDS (not ms)
 *   - Algorithm: HMAC-SHA256, output as lowercase hex
 *   - Secret:   the plugin's apikey itself (NOT derived)
 *   - Replay window: 60 s either direction
 *
 *   GET canonical:  apikey=<apikey>&since=<since>&limit=<limit>&wait_ms=<wait_ms>
 *                   - literal string, no URL-encoding
 *                   - exact order, normalized defaults (since=0, limit=100, wait_ms=0)
 *                   - apikey verbatim (not URL-encoded — URL-encoding is transport-only)
 *
 *   POST canonical: the raw request body bytes (JSON string as transmitted).
 *                   CRITICAL: sign exact bytes — do NOT re-encode after signing.
 *
 * Two helpers below. Always send all three params in GET URL + canonical so
 * they match by construction (no normalization drift).
 */
if (!function_exists('wpc_v2_manifest_sign_get')) {
    function wpc_v2_manifest_sign_get($apikey, $since, $limit, $wait_ms)
    {
        $canonical = sprintf(
            'apikey=%s&since=%d&limit=%d&wait_ms=%d',
            (string) $apikey, (int) $since, (int) $limit, (int) $wait_ms
        );
        return [
            'X-WPC-Sig'       => hash_hmac('sha256', $canonical, (string) $apikey),
            'X-WPC-Timestamp' => (string) time(),
        ];
    }
}
if (!function_exists('wpc_v2_manifest_sign_body')) {
    function wpc_v2_manifest_sign_body($apikey, $body_raw)
    {
        return [
            'X-WPC-Sig'       => hash_hmac('sha256', (string) $body_raw, (string) $apikey),
            'X-WPC-Timestamp' => (string) time(),
        ];
    }
}

/**
 * Per-apikey cursor — last completed_at_ms we've successfully drained.
 *
 * Stored as a site option (not a transient) so it survives cache flushes.
 * Cursor crash recovery is handled at the orch layer: a since-value newer
 * than the latest manifest entry returns an empty array, no error. Plugin
 * can safely re-bootstrap from `since=0` if its persisted value is lost.
 */
if (!function_exists('wpc_v2_pull_get_cursor')) {
    function wpc_v2_pull_get_cursor()
    {
        return (int) get_option('wpc_v2_pull_cursor_ms', 0);
    }
}
if (!function_exists('wpc_v2_pull_set_cursor')) {
    function wpc_v2_pull_set_cursor($ms)
    {
        $ms = (int) $ms;
        if ($ms <= 0) {
            return;
        }
        update_option('wpc_v2_pull_cursor_ms', $ms, false);
    }
}

/**
 * Fetch manifest from orch, paginate while has_more.
 *
 * Returns the full list of new variant entries across all pages, or empty
 * on transport failure. Caller must filter by what's still relevant (e.g.
 * dedupe against already-on-disk sha256).
 *
 * Manifest is sorted newest-first per LS contract — paginating with limit
 * means most-recent completions reach plugin first if the page count is
 * capped.
 *
 * @param int $since_ms  Inclusive lower bound on completed_at_ms.
 * @param int $limit     Page size (default 100, max 500). Orch caps anyway.
 * @return array {
 *   ok: bool,
 *   variants: array<int, array>,   // raw manifest entries
 *   cursor_high_water_ms: int,     // newest completed_at_ms seen
 *   pages_fetched: int,
 *   error?: string
 * }
 */
if (!function_exists('wpc_v2_pull_manifest_fetch')) {
    function wpc_v2_pull_manifest_fetch($since_ms = 0, $limit = 100, $wait_ms = 0)
    {
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($orch_url === '' || $apikey === '') {
            return ['ok' => false, 'variants' => [], 'cursor_high_water_ms' => 0, 'pages_fetched' => 0, 'error' => 'no_orch_or_apikey'];
        }

        $since_ms = max(0, (int) $since_ms);
        $limit    = max(1, min(500, (int) $limit));
        // Long-poll: orch holds open until a variant lands OR wait_ms elapses;
        // 0 = short-poll. Capped at 25s to stay under the shared-host 30s
        // max_execution_time default.
        $wait_ms  = max(0, min(25000, (int) $wait_ms));

        $all_variants  = [];
        $high_water    = $since_ms;
        $pages_fetched = 0;
        $next_since    = $since_ms;
        // Safety cap on the paginated walk (20 pages × 500 = 10K/tick). More
        // than one tick should ever drain; the rest waits for next tick.
        $max_pages     = 20;

        while ($pages_fetched < $max_pages) {
            // Only the first page long-polls; later pages drain immediately
            // (orch already has them buffered).
            $page_wait_ms = ($pages_fetched === 0) ? $wait_ms : 0;

            // Always send all 4 params even at default — matches orch's canonical
            // normalization so URL and HMAC match by construction (no drift).
            $url = rtrim($orch_url, '/') . '/optimize-v2/manifest'
                 . '?apikey='  . rawurlencode($apikey)
                 . '&since='   . $next_since
                 . '&limit='   . $limit
                 . '&wait_ms=' . $page_wait_ms;

            // Timeout = wait_ms + 5s transport buffer; floor 8s for short-poll.
            $http_timeout = $page_wait_ms > 0
                ? (int) ceil(($page_wait_ms + 5000) / 1000)
                : 8;

            $sig_headers = wpc_v2_manifest_sign_get($apikey, $next_since, $limit, $page_wait_ms);

            $resp = wp_remote_get($url, [
                'timeout' => $http_timeout,
                'headers' => array_merge([
                    'Accept' => 'application/json',
                ], $sig_headers),
            ]);

            if (is_wp_error($resp)) {
                error_log(sprintf('[WPC PullManifest] http_error since=%d page=%d err=%s', $next_since, $pages_fetched, $resp->get_error_message()));
                return ['ok' => false, 'variants' => $all_variants, 'cursor_high_water_ms' => $high_water, 'pages_fetched' => $pages_fetched, 'error' => 'http_error'];
            }
            $code = (int) wp_remote_retrieve_response_code($resp);
            if ($code !== 200) {
                // Honor 429 with backoff — without it the drain self-chain
                // hammers orch at full speed (each iter spawns another loopback).
                // Set a cool-off lock; honor Retry-After if sent (clamped 10-300s).
                if ($code === 429) {
                    $retry_after = 60;
                    $hdr = wp_remote_retrieve_header($resp, 'retry-after');
                    if ($hdr !== '' && is_numeric($hdr)) {
                        $retry_after = max(10, min(300, (int) $hdr));
                    }
                    // Extending the drain-running transient's TTL pauses all
                    // drain dispatches (wpc_v2_pull_drain_fire checks it).
                    set_transient('wpc_v2_drain_running', time(), $retry_after);
                    error_log(sprintf(
                        '[WPC PullManifest] http_429_backoff retry_after=%ds since=%d',
                        $retry_after, $next_since
                    ));
                    return ['ok' => false, 'variants' => $all_variants, 'cursor_high_water_ms' => $high_water, 'pages_fetched' => $pages_fetched, 'error' => 'rate_limited', 'retry_after' => $retry_after];
                }
                error_log(sprintf('[WPC PullManifest] http_status=%d since=%d page=%d', $code, $next_since, $pages_fetched));
                return ['ok' => false, 'variants' => $all_variants, 'cursor_high_water_ms' => $high_water, 'pages_fetched' => $pages_fetched, 'error' => 'http_status_' . $code];
            }

            $body = json_decode(wp_remote_retrieve_body($resp), true);
            if (!is_array($body)) {
                return ['ok' => false, 'variants' => $all_variants, 'cursor_high_water_ms' => $high_water, 'pages_fetched' => $pages_fetched, 'error' => 'invalid_json'];
            }

            $pages_fetched++;
            $variants = isset($body['variants']) && is_array($body['variants']) ? $body['variants'] : [];

            if (!empty($variants)) {
                foreach ($variants as $v) {
                    $all_variants[] = $v;
                }
            }
            if (isset($body['cursor_high_water_ms']) && (int) $body['cursor_high_water_ms'] > $high_water) {
                $high_water = (int) $body['cursor_high_water_ms'];
            }

            // Page via next_cursor_ms (oldest completed_at_ms in this page,
            // since entries are newest-first); continue only while has_more.
            if (empty($body['has_more']) || empty($body['next_cursor_ms'])) {
                break;
            }
            $next = (int) $body['next_cursor_ms'];
            // Don't loop forever if orch echoes the same cursor.
            if ($next <= $next_since) {
                break;
            }
            $next_since = $next;
        }

        return [
            'ok'                   => true,
            'variants'             => $all_variants,
            'cursor_high_water_ms' => $high_water,
            'pages_fetched'        => $pages_fetched,
        ];
    }
}

/**
 * Adapt manifest entries into per-image journal files.
 *
 * The journal drain (wpc_v2_journal_drain_handler in v2-direct-entry.php)
 * already knows how to:
 *   - curl_multi-fetch all 'persisted_pending_bytes' URLs in one TLS session
 *   - verify bytes_size + bytes_sha256
 *   - atomic temp+rename disk write
 *   - call wpc_v2_merge_variant() to fold into ic_local_variants
 *   - self-chain via loopback if more files remain
 *
 * So the pull-manifest path is just a different SOURCE of journal entries.
 * Group by imageID, write one journal file per image, kick the drain.
 *
 * @param array $variants  Raw manifest entries from wpc_v2_pull_manifest_fetch().
 * @return array {
 *   queued: int,           // count of variants successfully journaled
 *   skipped_dedup: int,    // count of variants already on disk with matching sha256
 *   skipped_invalid: int,  // count of malformed manifest entries
 *   imageIDs: array<int>,  // distinct imageIDs touched (for telemetry)
 * }
 */
if (!function_exists('wpc_v2_pull_manifest_queue_for_drain')) {
    function wpc_v2_pull_manifest_queue_for_drain(array $variants)
    {
        if (!function_exists('wpc_v2_journal_write_batch')) {
            return ['queued' => 0, 'skipped_dedup' => 0, 'skipped_invalid' => 0, 'imageIDs' => []];
        }

        // Bucket by imageID. Each bucket becomes one journal file.
        $by_image        = [];
        $skipped_dedup   = 0;
        $skipped_invalid = 0;

        // Lazy-CDN ingest stats — separate from journal counts because lazy_cdn
        // entries skip the journal (direct disk write, no postmeta).
        $lazycdn_acked   = [];   // sha256 strings for ack batch
        $lazycdn_failed  = 0;
        $lazycdn_ingested = 0;
        // Smallest completed_at_ms among RETRY-ELIGIBLE failures. Caller clamps
        // the cursor BELOW this so failures re-fetch next tick instead of being
        // skipped past (silent loss). skipped_invalid isn't tracked here — not
        // retry-eligible, and tracking it would re-poll tightly.
        $min_failed_ms   = 0;

        foreach ($variants as $v) {
            // Refresh the drain lock before each variant. A page can hold ~100
            // variants, each a 30s-timeout fetch — far over the 15s lock TTL, so
            // without a mid-page refresh the lock expires while still fetching and
            // a second worker spawns to re-fetch the same range (double FPM /
            // bandwidth / purge). 40s TTL = fetch + margin.
            if (function_exists('set_transient')) {
                set_transient('wpc_v2_drain_running', time(), 40);
            }
            if (!is_array($v)) {
                $skipped_invalid++;
                continue;
            }

            // Source-route BEFORE imageID validation. Lazy_cdn entries carry a
            // derived sha1 imageID (not a real attachment ID) and need no
            // postmeta — they must bypass the journal entirely and go to
            // wpc_v2_lazy_cdn_ingest (direct disk write, its own sha256 dedup
            // guards push+pull double-process). Detect via three shapes, since
            // orch's manifest format has drifted:
            //   1. $v['source'] === 'lazycdn'         (primary — orch sets it on every path)
            //   2. $v['tags']['source'] === 'lazycdn'
            //   3. imageID prefix 'lazycdn'           (fallback; covers both 'lazycdn-{hash}'
            //      and 'lazycdn:{type}:{jobId}' shapes — nothing else uses that prefix)
            $entry_source = '';
            $entry_imageID_str = isset($v['imageID']) ? (string) $v['imageID'] : '';
            if (isset($v['source'])) {
                $entry_source = (string) $v['source'];
            } elseif (isset($v['tags']['source'])) {
                $entry_source = (string) $v['tags']['source'];
            } elseif ($entry_imageID_str !== '' && strpos($entry_imageID_str, 'lazycdn') === 0) {
                $entry_source = 'lazycdn';
            }
            if ($entry_source === 'lazycdn') {
                // Hoist origin_url + origin_host to top-level — the ingest
                // function reads them from top-level only.
                if (!isset($v['origin_url']) && isset($v['tags']['origin_url'])) {
                    $v['origin_url'] = (string) $v['tags']['origin_url'];
                }
                if (!isset($v['origin_host']) && isset($v['tags']['origin_host'])) {
                    $v['origin_host'] = (string) $v['tags']['origin_host'];
                }
                if (function_exists('wpc_v2_lazy_cdn_ingest')) {
                    $sha_for_ack = isset($v['sha256']) ? (string) $v['sha256'] : '';
                    // Per-ingest timing: each ingest serially fetches the staged
                    // bytes; a cold staging-CDN MISS takes seconds. Log slow ones
                    // so a long tick is attributable to a specific fetch.
                    $t_i0 = microtime(true);
                    if (wpc_v2_lazy_cdn_ingest($v)) {
                        $t_i_ms = (int) round((microtime(true) - $t_i0) * 1000);
                        if ($t_i_ms > 1500) {
                            error_log(sprintf('[WPC PullManifest] slow_ingest size=%s fmt=%s ms=%d',
                                isset($v['sizeLabel']) ? (string) $v['sizeLabel'] : '?',
                                isset($v['format']) ? (string) $v['format'] : '?', $t_i_ms));
                        }
                        if ($sha_for_ack !== '') $lazycdn_acked[] = $sha_for_ack;
                        $lazycdn_ingested++;
                        update_option('wpc_v2_last_drain_stats', ['t' => time(), 'ingested' => $lazycdn_ingested, 'failed' => $lazycdn_failed], false);
                    } else {
                        $lazycdn_failed++;
                        update_option('wpc_v2_last_drain_stats', ['t' => time(), 'ingested' => $lazycdn_ingested, 'failed' => $lazycdn_failed], false);
                        $f_ms = isset($v['completed_at_ms']) ? (int) $v['completed_at_ms'] : 0;
                        // Retry-eligible failure: keep the cursor below it so it
                        // re-fetches next pass. EXCEPT a STALE PERMANENT failure
                        // (bytes_fetch 404/410 = staged bytes expired): retrying
                        // never succeeds, and clamping would pin the cursor below
                        // it forever, stranding every newer entry. So clamp only
                        // TRANSIENT or RECENT failures (orch may still be staging
                        // — encode/stage race) and advance past stale permanent
                        // ones. If orch re-stages, it re-adds a fresher entry that
                        // ingests normally. Narrow: only bytes_fetch 4xx > 30min.
                        $wpc_clamp = true;
                        if (function_exists('get_option')) {
                            $wpc_lif    = get_option('wpc_v2_last_ingest_fail');
                            $wpc_reason = (is_array($wpc_lif) && isset($wpc_lif['reason'])) ? (string) $wpc_lif['reason'] : '';
                            $wpc_detail = (is_array($wpc_lif) && isset($wpc_lif['detail'])) ? (string) $wpc_lif['detail'] : '';
                            // 404/410 only = definitively permanent (bytes gone).
                            // 429/408/403 and all 5xx stay retryable so a transient
                            // blip never abandons a recoverable entry.
                            $wpc_perm   = (strpos($wpc_reason, 'bytes_fetch_non_200') === 0 && (strpos($wpc_detail, 'code=404') !== false || strpos($wpc_detail, 'code=410') !== false));
                            $wpc_age_ms = ($f_ms > 0) ? ((int) round(microtime(true) * 1000) - $f_ms) : 0;
                            if ($wpc_perm && $wpc_age_ms > 1800000) {
                                $wpc_clamp = false;
                                error_log('[WPC PullManifest] cursor-advance past stale permanent failure reason=' . $wpc_reason . ' ' . $wpc_detail . ' age_ms=' . $wpc_age_ms);
                            }
                        }
                        if ($wpc_clamp && $f_ms > 0 && ($min_failed_ms === 0 || $f_ms < $min_failed_ms)) $min_failed_ms = $f_ms;
                    }
                } else {
                    // Defensive: if the lazy_cdn module isn't loaded for any
                    // reason, count as invalid rather than passing through to
                    // the journal path (which would 100% fail at imageID<=0).
                    $skipped_invalid++;
                }
                continue;
            }

            $imageID  = isset($v['imageID'])  ? (int) $v['imageID']  : 0;
            $size     = isset($v['sizeLabel']) ? (string) $v['sizeLabel'] : '';
            $format   = isset($v['format'])    ? (string) $v['format']    : '';
            $url      = isset($v['fetchUrl'])  ? (string) $v['fetchUrl']  : '';
            $size_b   = isset($v['bytes'])     ? (int) $v['bytes']        : 0;
            $sha256   = isset($v['sha256'])    ? (string) $v['sha256']    : '';

            if ($imageID <= 0 || $size === '' || $format === '' || $url === '' || $size_b <= 0 || $sha256 === '') {
                // Dump the entry shape (first 3) to show WHY validation failed.
                // Common cause: a lazy_cdn entry missing source='lazycdn' falls
                // through here and fails the imageID>0 check (its sha1 ID casts to 0).
                static $dbg_invalid_dumped = 0;
                if ($dbg_invalid_dumped < 3) {
                    error_log(sprintf(
                        '[WPC PullManifest] skip_invalid_entry keys=%s imageID=%s sizeLabel=%s format=%s fetchUrl_len=%d bytes=%s sha256_len=%d source=%s tags=%s',
                        is_array($v) ? implode(',', array_keys($v)) : 'not_array',
                        (string) ($v['imageID'] ?? '(missing)'),
                        (string) ($v['sizeLabel'] ?? '(missing)'),
                        (string) ($v['format'] ?? '(missing)'),
                        isset($v['fetchUrl']) ? strlen((string) $v['fetchUrl']) : -1,
                        (string) ($v['bytes'] ?? '(missing)'),
                        isset($v['sha256']) ? strlen((string) $v['sha256']) : -1,
                        isset($v['source']) ? (string) $v['source'] : '(no_source_tag)',
                        isset($v['tags']) ? wp_json_encode($v['tags']) : '(no_tags)'
                    ));
                    $dbg_invalid_dumped++;
                }
                $skipped_invalid++;
                continue;
            }

            // Already on disk (matching sha256)? The pull would be idempotent
            // but skipping it saves the bandwidth + round trip.
            if (wpc_v2_pull_manifest_already_on_disk($imageID, $size, $format, $sha256)) {
                $skipped_dedup++;
                continue;
            }

            // dest_dir tells the journal drain where to write the bytes.
            $abs_parent = get_attached_file($imageID);
            if (!$abs_parent) {
                $skipped_invalid++;
                continue;
            }
            $dest_dir = dirname($abs_parent);

            if (!isset($by_image[$imageID])) {
                $by_image[$imageID] = [
                    'jobId'   => isset($v['jobId']) ? (string) $v['jobId'] : '',
                    'entries' => [],
                ];
            }

            $entry = [
                'sizeLabel'    => $size,
                'format'       => $format,
                'type'         => 'persisted_pending_bytes',
                'fetch_url'    => $url,
                'bytes_size'   => $size_b,
                'bytes_sha256' => $sha256,
                'filename'     => isset($v['filename']) ? (string) $v['filename'] : '',
                'dest_dir'     => $dest_dir,
                'originalSize' => isset($v['originalSize']) ? (int) $v['originalSize'] : 0,
                'ms'           => isset($v['completed_at_ms']) ? (int) $v['completed_at_ms'] : (int) round(microtime(true) * 1000),
                // Surfaced so journal-drain telemetry can split push vs
                // lazy_first_render vs backfill in logs.
                'delivery_method' => isset($v['delivery_method']) ? (string) $v['delivery_method'] : 'push',
                'source'       => 'pull_manifest',
            ];

            $by_image[$imageID]['entries'][] = $entry;
        }

        $queued    = 0;
        $imageIDs  = [];
        // Track sha256s whose journal write failed so the caller can EXCLUDE
        // them from the ack batch — else orch prunes them from its ZSET and the
        // entry is lost with no retry. Excluded → orch keeps it, plugin retries
        // next tick (manifest TTL 8d).
        $journal_failed_sha256s = [];
        foreach ($by_image as $imageID => $group) {
            if (empty($group['entries'])) {
                continue;
            }
            if (wpc_v2_journal_write_batch($imageID, $group['jobId'], $group['entries'], 'pull_manifest')) {
                $queued     += count($group['entries']);
                $imageIDs[]  = $imageID;
            } else {
                // Journal write failed — mark all entries' sha256s do-not-ack.
                foreach ($group['entries'] as $entry) {
                    // Read 'bytes_sha256', NOT 'sha256': the $entry build above
                    // sets the former. Keying on 'sha256' left this set empty so
                    // failures got acked+pruned anyway (silent loss).
                    if (!empty($entry['bytes_sha256'])) {
                        $journal_failed_sha256s[(string) $entry['bytes_sha256']] = true;
                    }
                    // Retry-eligible failure: keep the cursor below it.
                    $f_ms = isset($entry['ms']) ? (int) $entry['ms'] : 0;
                    if ($f_ms > 0 && ($min_failed_ms === 0 || $f_ms < $min_failed_ms)) $min_failed_ms = $f_ms;
                }
            }
        }

        // Stamp drain stats on EVERY pass, not just per-ingest: the in-loop
        // writes never fire on an empty manifest, so the healthcheck would show
        // a prior drain's numbers and falsely read as "still landing". `seen`
        // (entries this pass) disambiguates: seen:0 vs ingested:N is unambiguous.
        update_option('wpc_v2_last_drain_stats', [
            't'        => time(),
            'ingested' => $lazycdn_ingested,
            'failed'   => $lazycdn_failed,
            'seen'     => is_array($variants) ? count($variants) : 0,
        ], false);

        return [
            'queued'          => $queued,
            'skipped_dedup'   => $skipped_dedup,
            'skipped_invalid' => $skipped_invalid,
            'imageIDs'        => $imageIDs,
            // Lazy-CDN ack-allowlist: caller acks ONLY entries whose sha256 is
            // in lazycdn_acked_sha256s (ingest succeeded). Failed ones stay
            // un-acked so orch keeps them for retry next tick.
            'lazycdn_ingested'      => $lazycdn_ingested,
            'lazycdn_failed'        => $lazycdn_failed,
            'lazycdn_acked_sha256s' => array_values(array_unique($lazycdn_acked)),
            // Journal-write failures (same pattern) — caller excludes from ack.
            'journal_failed_sha256s' => array_keys($journal_failed_sha256s),
            // Oldest retry-eligible failure ms (0 = none); caller clamps cursor below.
            'min_failed_completed_ms' => $min_failed_ms,
        ];
    }
}

/**
 * Dedupe check — is a variant with this sha256 already on disk for this image?
 *
 * Used to skip pulling bytes the plugin already received via the push path
 * during Phase 2 push+pull coexistence. The bg_swap callback writes sha256
 * into ic_local_variants[$key]['bytes_sha256'] on every successful merge.
 *
 * Cheap: one postmeta read per image, then in-memory loop.
 */
if (!function_exists('wpc_v2_pull_manifest_already_on_disk')) {
    function wpc_v2_pull_manifest_already_on_disk($imageID, $size, $format, $sha256)
    {
        if ($sha256 === '') {
            return false;
        }
        $variants = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($variants) || empty($variants)) {
            return false;
        }
        // Canonical key (jpeg = no suffix, all sizes). Must match wpc_v2_variant_key().
        $key = function_exists('wpc_v2_variant_key')
            ? wpc_v2_variant_key($size, $format)
            : ($format === 'jpeg' || $format === 'jpg' ? $size : $size . '-' . $format);
        if (!isset($variants[$key]) || !is_array($variants[$key])) {
            return false;
        }
        $on_disk_sha = isset($variants[$key]['bytes_sha256']) ? (string) $variants[$key]['bytes_sha256'] : '';
        return ($on_disk_sha !== '' && hash_equals($on_disk_sha, (string) $sha256));
    }
}

/**
 * Outbound ack — confirm receipt of variants so orch can early-cleanup staging.
 *
 * Fire-and-forget. If ack fails, orch's 7d TTL backstops the cleanup; we
 * just wasted a few KB of staging until then. Not a correctness issue.
 *
 * @param array $acks  Each item: ['imageID' => int, 'sizeLabel' => str, 'format' => str, 'sha256' => str]
 * @return bool        true on transport success, false otherwise.
 */
if (!function_exists('wpc_v2_pull_manifest_ack')) {
    function wpc_v2_pull_manifest_ack(array $acks)
    {
        if (empty($acks)) {
            return true;
        }
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($orch_url === '' || $apikey === '') {
            return false;
        }

        // HMAC body signing — build the JSON once and sign those exact bytes;
        // re-encoding after signing would break the HMAC.
        $body_raw = wp_json_encode(['acks' => array_values($acks)]);
        $sig_headers = wpc_v2_manifest_sign_body($apikey, $body_raw);

        $url = rtrim($orch_url, '/') . '/optimize-v2/manifest/ack?apikey=' . rawurlencode($apikey);
        $resp = wp_remote_post($url, [
            'timeout' => 4,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $sig_headers),
            'body' => $body_raw,
        ]);
        if (is_wp_error($resp)) {
            error_log('[WPC PullManifest] ack_http_error: ' . $resp->get_error_message());
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        return ($code >= 200 && $code < 300);
    }
}

/**
 * Outbound purge — drop manifest entries for an image (or all entries).
 *
 * Use cases:
 *   - Force-recompress: plugin clears stale manifest before re-triggering
 *   - Lazy_cdn disable / API key disconnect: plugin clears all entries
 *
 * @param int    $imageID  0 means purge all entries for this apikey.
 * @param string $reason   'force_recompress' | 'customer_disconnect' | 'manual'
 * @return bool
 */
if (!function_exists('wpc_v2_pull_manifest_purge')) {
    function wpc_v2_pull_manifest_purge($imageID = 0, $reason = 'manual')
    {
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($orch_url === '' || $apikey === '') {
            return false;
        }
        $body_arr = ['reason' => (string) $reason];
        if ((int) $imageID > 0) {
            $body_arr['imageID'] = (int) $imageID;
        }
        // HMAC body signing — sign the exact bytes; don't re-encode after.
        $body_raw    = wp_json_encode($body_arr);
        $sig_headers = wpc_v2_manifest_sign_body($apikey, $body_raw);

        $url = rtrim($orch_url, '/') . '/optimize-v2/manifest/purge?apikey=' . rawurlencode($apikey);
        $resp = wp_remote_post($url, [
            'timeout' => 4,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $sig_headers),
            'body' => $body_raw,
        ]);
        if (is_wp_error($resp)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        return ($code >= 200 && $code < 300);
    }
}

/**
 * Deadline-based drain. Replaces the old state-machine
 * needs_continuation design with a single deadline timestamp. Drain worker
 * runs while now < drain_alive_until_ms. Any activity (click or variant
 * arrival) extends the deadline. Drain dies cleanly N seconds after the
 * last activity — no guessing about "is more coming?", no eager-flip race,
 * no pending-transient race.
 *
 * Two pieces of state:
 *   - wpc_v2_drain_alive_until_ms (option) — deadline timestamp
 *   - wpc_v2_drain_running (transient, 15s TTL refreshed per iteration) —
 *     "is a worker currently active?" marker, prevents duplicate workers
 *
 * Click handler:    set deadline = now+60s, fire drain
 * Variant arrival:  set deadline = now+30s (extends life if not already past)
 * Drain worker:     loop while now < deadline; on iteration budget hit,
 *                   self-chain (delete marker, fire next, exit)
 *
 * Why this is robust:
 *   - "When was last activity?" is trivially correct
 *   - "Is more coming?" is hard (eager flip, race conditions, etc) — we don't ask
 *   - Bulk clicks just keep bumping the deadline forward
 *   - Single-click drain dies exactly when it should
 */
if (!function_exists('wpc_v2_pull_drain_fire')) {
    function wpc_v2_pull_drain_fire($wake_items = [])
    {
        // Optional $wake_items[] (the wake's ready-variant set) is forwarded to
        // the worker via the loopback body so it HOLDS for those named items
        // (see body build below). Default [] = zero behavior change.
        // Every early-return path logs the specific gate it hit, so a wake's
        // dispatched=0 is attributable to one of several conditions.
        if (!wpc_v2_pull_enabled()) {
            error_log('[WPC DrainFire] skip reason=pull_disabled');
            update_option('wpc_v2_last_drain_skip', ['t'=>time(),'reason'=>'pull_disabled'], false);
            return false;
        }
        // Honor the rate-limit cool-off the drain loop sets on 429.
        if (($cooloff_ts = (int) get_transient('wpc_v2_pull_cooloff')) > 0) {
            error_log(sprintf('[WPC DrainFire] skip reason=cooloff_active until=%d', $cooloff_ts));
            update_option('wpc_v2_last_drain_skip', ['t'=>time(),'reason'=>'cooloff_active','until'=>$cooloff_ts], false);
            return false;
        }
        // Skip if another drain worker is already running. Short TTL (15s)
        // ensures a queued/dropped worker doesn't permanently block.
        if (($lock_ts = (int) get_transient('wpc_v2_drain_running')) > 0) {
            // redrain-pending dirty flag: a wake arrived mid-drain. Dropping the
            // fire would make its variant wait for the running worker's own poll
            // cadence. Instead set the flag — the worker re-passes at idle-exit
            // and counts it as work for the self-chain. Cleared once a fresh
            // worker is dispatched (it covers the pending wake).
            set_transient('wpc_v2_redrain_pending', time(), 60);
            error_log(sprintf('[WPC DrainFire] skip reason=drain_running_lock_held since=%d age_s=%d redrain_pending=1', $lock_ts, time() - $lock_ts));
            update_option('wpc_v2_last_drain_skip', ['t'=>time(),'reason'=>'drain_running_lock_held','redrain_pending'=>1,'lock_age_s'=>time()-$lock_ts], false);
            return false;
        }
        set_transient('wpc_v2_drain_running', time(), 15);

        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($apikey === '') {
            error_log('[WPC DrainFire] skip reason=empty_apikey');
            delete_transient('wpc_v2_drain_running');
            return false;
        }

        $ts  = time();
        $sig = hash_hmac('sha256', 'wpc_v2_pull_drain.' . $ts, $apikey);

        $url   = admin_url('admin-ajax.php');
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            error_log(sprintf('[WPC DrainFire] skip reason=bad_admin_url url=%s', $url));
            delete_transient('wpc_v2_drain_running');
            return false;
        }
        $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
        $port     = !empty($parts['port']) ? (int) $parts['port'] : ($is_https ? 443 : 80);
        $host     = (string) $parts['host'];
        $path     = (!empty($parts['path']) ? $parts['path'] : '/') . '?action=wpc_v2_pull_drain_loop';

        // Carry the ready-variant set to the worker. The loopback sig covers
        // ONLY the timestamp, not the body, so items[] needs no sig change; it's
        // a non-authoritative HINT (the manifest fetch is independently signed to
        // orch), so a malformed/absent items[] just yields a generic drain. Cap 50.
        $body_params = ['t' => $ts, 'sig' => $sig];
        if (is_array($wake_items) && !empty($wake_items)) {
            $items_json = wp_json_encode(array_slice(array_values($wake_items), 0, 50));
            if (is_string($items_json)) {
                $body_params['items'] = $items_json;
            }
        }
        $body = http_build_query($body_params);
        $req  = "POST {$path} HTTP/1.1\r\n"
              . "Host: {$host}\r\n"
              . "Content-Type: application/x-www-form-urlencoded\r\n"
              . "Content-Length: " . strlen($body) . "\r\n"
              . "Connection: close\r\n"
              . "User-Agent: WPCV2PullDrain/1.0\r\n"
              . "\r\n"
              . $body;

        // Local-vhost loopback. Connecting to the PUBLIC host hits the CDN/WAF
        // edge on a datacenter-IP site, so the self-POST never lands on local
        // PHP-FPM (stalling the pull chain). Connect 127.0.0.1→localhost→public
        // host with Host/SNI = public host. Prefer the shared helper; fall back
        // to an inline connect when the ajax class isn't loaded (cron/wake context).
        $errno  = 0;
        $errstr = '';
        $fp     = false;
        if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) {
            $fp = wps_ic_ajax::wpc_loopback_open_socket($host, $port, $is_https, 0.2);
        } else {
            $pd_ctx = $is_https ? stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]) : null;
            foreach (['127.0.0.1', 'localhost', $host] as $pd_chost) {
                $pd_remote = ($is_https ? 'tls://' : 'tcp://') . $pd_chost . ':' . $port;
                $pd_sock   = $pd_ctx
                    ? @stream_socket_client($pd_remote, $errno, $errstr, 0.2, STREAM_CLIENT_CONNECT, $pd_ctx)
                    : @stream_socket_client($pd_remote, $errno, $errstr, 0.2);
                if ($pd_sock) { $fp = $pd_sock; break; }
            }
        }
        if (!$fp) {
            // fsockopen blocked — clear marker so the next click can retry.
            delete_transient('wpc_v2_drain_running');
            error_log('[WPC PullDrain] fsockopen_failed errno=' . $errno . ' err=' . $errstr);
            return false;
        }
        @stream_set_timeout($fp, 0, 100000);
        @fwrite($fp, $req);
        @fclose($fp);
        // Fresh worker dispatched — it covers any wake flagged while a previous
        // worker held the lock.
        delete_transient('wpc_v2_redrain_pending');
        return true;
    }
}

/**
 * Deferred pull-drain fire — flush the response to the browser FIRST, then run the
 * loopback dispatch. wpc_v2_pull_drain_fire()'s local-vhost connect can cost up to
 * ~0.6s (3-rung 127.0.0.1 -> localhost -> public-host fallback); firing it inline on
 * the render path (the init page-load tick) or before the buffer flush (the shutdown
 * reconcile tick) held the VISITOR's FPM worker for that long, adding directly to TTFB.
 * Run via register_shutdown_function so this executes AFTER WP finalizes + flushes
 * output (fastcgi_finish_request detaches the worker first). The 15s wpc_v2_drain_running
 * lock inside wpc_v2_pull_drain_fire() makes a double-register (init + shutdown both
 * deferring in one request) harmless. Pattern mirrors v2-shutdown-drain.php.
 */
if (!function_exists('wpc_v2_deferred_pull_drain_fire')) {
    function wpc_v2_deferred_pull_drain_fire()
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        if (function_exists('wpc_v2_pull_drain_fire')) {
            wpc_v2_pull_drain_fire();
        }
        // (v7.03.81) Loopback-independent fallback — the page-load twin of the wake's (.80). On a host where the
        // loopback self-POST can't land (Cloudways/datacenter-IP/WAF), the fire above does nothing and the
        // page-load drain silently no-ops, leaving the WAKE as the ONLY working trigger — a single point of
        // failure (a wake that never arrives → the manifest backs up forever). After a 3s grace, if no worker
        // stamped wpc_v2_drain_worker_started, run the drain loop INLINE here. The handler stamps on entry, so a
        // healthy-loopback host stands down (no double-drain); a blocked one drains on the page-load itself. The
        // response is already flushed above (fastcgi_finish_request), so this is zero user-facing latency.
        // (v7.03.82) FLEET RESOURCE SAFETY gate. Only hold a worker for the 3s grace + inline drain when there
        // is genuinely a pending backlog — wpc_v2_drain_alive_until_ms (set by the optimize/wake, NOT the
        // page-load tick anymore) still in the future. The tick already gates registration on this, but the
        // window can expire between register and shutdown, so we re-check here: a no-backlog shutdown returns
        // instantly instead of sleeping 3s and long-polling the manifest on the fleet's 10k shared hosts.
        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        $wpc_drain_window_open = ((int) get_option('wpc_v2_drain_alive_until_ms', 0) > (int) round(microtime(true) * 1000));
        if ($wpc_drain_window_open && function_exists('wpc_v2_pull_drain_loop_handler')) {
            @ignore_user_abort(true);
            @set_time_limit(150);
            sleep(3);
            if (!get_transient('wpc_v2_drain_worker_started')) {
                $apikey_inline = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
                if ($apikey_inline !== '') {
                    error_log('[WPC PageLoadDrain] loopback_worker_never_started — running drain inline');
                    $_POST['t']   = (string) time();
                    $_POST['sig'] = hash_hmac('sha256', 'wpc_v2_pull_drain.' . $_POST['t'], $apikey_inline);
                    wpc_v2_pull_drain_loop_handler();
                }
            }
        }
    }
}
if (!function_exists('wpc_v2_register_deferred_pull_drain')) {
    function wpc_v2_register_deferred_pull_drain()
    {
        static $registered = false;
        if ($registered) {
            return; // once per request — both tick sites can call this
        }
        $registered = true;
        register_shutdown_function('wpc_v2_deferred_pull_drain_fire');
    }
}

/**
 * Drain loop worker. Registered as AJAX action wpc_v2_pull_drain_loop.
 * HMAC-protected (apikey + timestamp), same envelope as journal drain.
 *
 * Runs a budgeted loop:
 *   1. Long-poll manifest (up to 25 s wait)
 *   2. Process any returned variants (journal drain handles bytes pull + merge)
 *   3. Re-check pending across site
 *   4. If pending = 0 → release lock, exit
 *   5. If wall budget remaining → loop
 *   6. If budget exhausted + pending remaining → fire self-chain, exit
 *
 * Wall budget: 25 s per call (under default PHP-FPM 30 s with safety).
 * Self-chain extends total drain time indefinitely until pending empty.
 */
if (!function_exists('wpc_v2_pull_drain_loop_handler')) {
    function wpc_v2_pull_drain_loop_handler()
    {
        // HMAC auth — matches journal drain pattern.
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $ts     = isset($_POST['t']) ? (int) $_POST['t'] : 0;
        $sig    = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
        if ($apikey === '' || $ts <= 0 || $sig === '' || abs(time() - $ts) > 60) {
            http_response_code(401);
            exit('auth');
        }
        $expected = hash_hmac('sha256', 'wpc_v2_pull_drain.' . $ts, $apikey);
        if (!hash_equals($expected, $sig)) {
            http_response_code(401);
            exit('sig');
        }

        // Stamp every accepted external drain-loop fire (orch's reconcile path
        // for stranded inventory). With last_drain_stats {seen,ingested} it's a
        // disk-proof receipt of each orch-forced landing; &src= tags the caller.
        update_option('wpc_v2_last_extdrain', [
            't'   => time(),
            'src' => isset($_POST['src']) ? substr(preg_replace('/[^a-z0-9_.-]/i', '', (string) $_POST['src']), 0, 24) : 'ext',
            'ua'  => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 40) : '',
        ], false);

        // Close client connection immediately — work runs in background.
        if (function_exists('fastcgi_finish_request')) {
            http_response_code(200);
            echo 'queued';
            fastcgi_finish_request();
        }
        // For non-FPM hosts, ignore_user_abort lets us continue past client close.
        @ignore_user_abort(true);
        @set_time_limit(60);

        // Worker-start stamp. The wake handler's inline fallback checks this
        // after a grace period: present = the loopback reached a worker, so the
        // inline pass stands down. On hosts that kill the self-POST it never
        // stamps → the wake request itself drains. Concurrent wakes dedupe on it.
        set_transient('wpc_v2_drain_worker_started', time(), 90);

        // Self-arm. The loop runs while now < drain_alive_until_ms, which only
        // the wake/click handlers arm — so a signed external fire could answer
        // 200 then exit with ZERO iterations on a stale deadline. A signed fire
        // IS intent to drain: if the deadline is unarmed/near-expired, arm 45s.
        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        $arm_now_ms = (int) (microtime(true) * 1000);
        if ((int) get_option('wpc_v2_drain_alive_until_ms', 0) < $arm_now_ms + 5000) {
            update_option('wpc_v2_drain_alive_until_ms', $arm_now_ms + 45000, false);
        }

        // Deadline-based drain. The loop runs while now < drain_alive_until_ms;
        // activity extends it (click → now+60s, each variant arrival → now+30s).
        // On iter-budget hit it self-chains; the next worker's first act is the
        // deadline check. The deadline is the single source of truth — no "is
        // more coming?" guessing, so no eager-flip or pending-transient race.
        //
        // Optional items[] (the wake's ready set, forwarded via the loopback
        // body — sig covers only t, so it's a non-authoritative HINT): when
        // present the loop HOLDS (keeps long-polling) instead of idle-fast-exiting
        // on the first empty tick. This closes the lazy_cdn fast-exit race — a
        // lazy_cdn variant has no pending_* transient, so needs_continuation() is
        // false and the worker would bail before the ready variant is fetch-visible.
        // Coarse count match, bounded by the iter budget; absent → identical to
        // every other caller.
        $wake_items_raw = isset($_POST['items']) ? wp_unslash((string) $_POST['items']) : '';
        // Guard the raw length before decoding + clamp the count (mirror the
        // send-side cap) so a bloated sender can't force an unbounded decode.
        $wake_decoded   = ($wake_items_raw !== '' && strlen($wake_items_raw) <= 65536) ? json_decode($wake_items_raw, true) : null;
        $wake_expect    = is_array($wake_decoded) ? min(count($wake_decoded), 50) : 0;
        // Track wake progress against lazy_cdn ingests, NOT $total_queued: the
        // named items are lazy_cdn variants counted as lazycdn_ingested and never
        // touch $queued (journal-only). Tracking $total_queued left the count
        // stuck → the hold never released + the drop-log false-fired.
        $wake_ingested  = 0;

        $started        = microtime(true);
        $polls          = 0;
        $total_queued   = 0;
        // Iter budget 25s — the full encoder window (final AVIF lands ~10s in);
        // a tighter budget let tail variants race a self-chain failure. Worker is
        // mostly idle on the long-poll wait; the site-wide lock keeps FPM at 1.
        $iter_budget_s  = 25.0;

        while ((microtime(true) - $started) < $iter_budget_s) {
            $now_ms = (int) (microtime(true) * 1000);
            // Invalidate the per-request option cache before each read — a
            // long-running worker otherwise sees the deadline snapshot from
            // startup and misses later clicks that extended it.
            wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
            $deadline_ms = (int) get_option('wpc_v2_drain_alive_until_ms', 0);
            if ($deadline_ms > 0 && $now_ms >= $deadline_ms) {
                // Deadline reached — exit clean, no self-chain.
                error_log(sprintf(
                    '[WPC PullDrain] deadline_reached iter=%d queued_total=%d wall_ms=%d',
                    $polls, $total_queued, (int) round((microtime(true) - $started) * 1000)
                ));
                delete_transient('wpc_v2_drain_running');
                exit;
            }

            $polls++;

            // Honor bulk STOP (hard) — stand down and clear the deadline so
            // nothing re-fires.
            if (get_transient('wpc_bulk_stop_signal')) {
                error_log('[WPC PullDrain] stop_signal — standing down (bulk stopped)');
                delete_option('wpc_v2_drain_alive_until_ms');
                delete_transient('wpc_v2_drain_running');
                exit;
            }

            $restoring = wpc_v2_active_restore_count();
            // Yield FPM workers to an active restore (soft): a throttle cap
            // wasn't enough — drain iters still starved the foreground restore.
            // Defer the drain entirely while a restore runs, but BUMP the deadline
            // +60s so the pipeline resumes once restore clears (the next tick re-fires).
            if ($restoring > 0) {
                update_option('wpc_v2_drain_alive_until_ms', (int) (microtime(true) * 1000) + 60000, false);
                error_log(sprintf('[WPC PullDrain] yield_to_restore restoring=%d queued_total=%d — defer (deadline bumped)', $restoring, $total_queued));
                delete_transient('wpc_v2_drain_running');
                exit;
            }
            $wait_ms   = 3000;

            $tick = wpc_v2_pull_manifest_tick(100, $wait_ms);
            // On rate-limit, exit WITHOUT deleting the drain_running transient:
            // fetch()'s 429 handler already extended its TTL, so the lock stays
            // held for the cool-off and no other caller spawns a drain. Skip the
            // self-chain too. (fetch() sets error='rate_limited'; tick wraps it
            // as reason='rate_limited'.)
            $tick_reason = isset($tick['reason']) ? (string) $tick['reason'] : '';
            if ($tick_reason === 'rate_limited') {
                $retry_after = isset($tick['retry_after']) ? (int) $tick['retry_after'] : 60;
                error_log(sprintf(
                    '[WPC PullDrain] rate_limited_exit iter=%d queued_total=%d retry_after=%ds',
                    $polls, $total_queued, $retry_after
                ));
                // Separate cool-off lock — the drain_running transient gets
                // refreshed each iter, so it can't carry the backoff. drain_fire
                // checks this dedicated one too.
                set_transient('wpc_v2_pull_cooloff', time(), max(10, $retry_after));
                delete_transient('wpc_v2_drain_running');
                exit;
            }
            $queued_this = isset($tick['queued']) ? (int) $tick['queued'] : 0;
            $total_queued += $queued_this;
            // Count lazy_cdn ingests (not journal $queued) toward wake progress
            // so an unrelated compress's journal variants can't false-confirm a
            // wake. Coarse: a different lazy_cdn image's ingests and dedup hits
            // also count — but both err toward "satisfied" (release the hold
            // sooner), never toward a false incomplete.
            $wake_ingested += isset($tick['lazycdn_ingested']) ? (int) $tick['lazycdn_ingested'] : 0;

            if ($queued_this > 0) {
                // Variants landed — extend deadline 30s from now.
                $new_deadline = $now_ms + 30000;
                if ($new_deadline > $deadline_ms) {
                    update_option('wpc_v2_drain_alive_until_ms', $new_deadline, false);
                }
                if (function_exists('wpc_v2_journal_drain_run')) {
                    $drain_cap = ($restoring > 0) ? 0.5 : 1.5;
                    wpc_v2_journal_drain_run($drain_cap);
                }
            } else {
                // Fast idle-exit: this tick drained nothing, so exit the instant
                // no work is in flight. needs_continuation() is the PRECISE signal
                // (pending_* transients, or queueing/optimizing ic_compressing) and
                // stays TRUE for the whole compress lifecycle, so exiting on
                // !needs_continuation() can NOT abandon an active compress.
                //
                // We deliberately do NOT also gate on the deadline timer: it
                // lingers ~30-60s after the final variant, holding an FPM worker
                // for nothing — exactly when a restore-after-compress needs one.
                // needs_continuation() already covers "compress in flight", and a
                // genuine new variant re-fires the drain on demand.
                //
                // Exception: keep holding while we still owe named wake items. A
                // lazy_cdn variant has no pending_* transient (needs_continuation
                // is false) so the worker would bail before it's fetch-visible.
                // Coarse count match, bounded by the deadline/iter-budget.
                $still_owe_wake_items = ($wake_expect > 0 && $wake_ingested < $wake_expect);
                // redrain-pending: a wake arrived while this worker held the lock
                // (its fire was skipped). Don't idle-exit past it — clear the flag
                // and run one more pass so the next long-poll sees the new entry.
                if ((int) get_transient('wpc_v2_redrain_pending') > 0) {
                    delete_transient('wpc_v2_redrain_pending');
                    error_log('[WPC PullDrain] redrain_pending_continue — wake arrived mid-drain; running another pass');
                    set_transient('wpc_v2_drain_running', time(), 15);
                    continue;
                }
                if (!wpc_v2_pull_drain_needs_continuation() && !$still_owe_wake_items) {
                    error_log(sprintf(
                        '[WPC PullDrain] idle_fast_exit iter=%d queued_total=%d wall_ms=%d — freeing worker (no pending work)',
                        $polls, $total_queued, (int) round((microtime(true) - $started) * 1000)
                    ));
                    // Clear the stale deadline too, so a page-load-poll/wake-ping
                    // doesn't re-spawn a drain off a deadline that's already served
                    // its purpose. A genuine new variant re-arms it on its own.
                    delete_option('wpc_v2_drain_alive_until_ms');
                    delete_transient('wpc_v2_drain_running');
                    exit;
                }
            }

            // Refresh running marker each iteration (15s TTL).
            set_transient('wpc_v2_drain_running', time(), 15);
        }

        // Iteration budget hit. Only self-chain if there's REAL work
        // left: either this worker drained variants (total_queued>0) OR there's
        // pending/active compress that will still deliver more
        // (wpc_v2_pull_drain_needs_continuation()).
        //
        // Pre-fix: the worker self-chained UNCONDITIONALLY here, so on an EMPTY
        // queue it kept spawning a fresh ~28s FPM-holding loopback every iteration
        // until the time-based deadline expired — burning a PHP-FPM worker for
        // nothing. On small FPM pools (2-5 workers) this saturates the pool and
        // STARVES foreground requests: a restore whose own work is milliseconds
        // measured ~48s (was 2-3s before lazy_cdn) purely waiting for a worker.
        // Lazy/Phase-B work re-fires the drain on demand via the pull-tick /
        // page-load-poll / wake-ping, so stopping the idle chain never strands
        // real work — the next genuine variant restarts it.
        // Phase 2 plugin-side end-to-end drop signal: this worker held for named wake
        // items but the expected count didn't ingest within its budget. Pairs with orch
        // wake_stats.no_meta_dropped — means orch said-ready but the manifest didn't surface them in
        // time (a real lazy_cdn pipeline signal, not a plugin bug). NOTE: if has_work self-chains, the
        // chained worker is generic (no items[]) — so this is the FIRST worker's outcome.
        if ($wake_expect > 0 && $wake_ingested < $wake_expect) {
            error_log(sprintf('[WPC PullDrain] wake_items_incomplete expect=%d ingested=%d', $wake_expect, $wake_ingested));
        }
        $has_work = ($total_queued > 0) || wpc_v2_pull_drain_needs_continuation()
            // redrain_pending: a wake arrived mid-drain — that IS work, so self-chain
            // (the successful dispatch clears the flag).
            || ((int) get_transient('wpc_v2_redrain_pending') > 0);
        error_log(sprintf(
            '[WPC PullDrain] iter_budget_hit iter=%d queued_total=%d wall_ms=%d — %s',
            $polls, $total_queued, (int) round((microtime(true) - $started) * 1000),
            $has_work ? 'self-chain' : 'idle_exit'
        ));
        delete_transient('wpc_v2_drain_running');
        if ($has_work) {
            wpc_v2_pull_drain_fire();
        }
        exit;
    }
}

/**
 * Active-restore count. Drain worker uses this to yield FPM
 * slots back to the pool when restores are running.
 *
 * Restores set wps_ic_compress_$id transient with status='restoring'
 * ([ajax.class.php:4380]). Bulk restore + single restore both use this
 * transient. We count rows with the restoring marker — under 1 ms on
 * indexed option_name LIKE.
 */
if (!function_exists('wpc_v2_active_restore_count')) {
    function wpc_v2_active_restore_count()
    {
        // Yield the drain to a BULK RESTORE in progress. Single cheap
        // option read. wps_ic_bulk_process = ['status' => 'restoring'] is written on
        // bulk-restore start (ajax.class.php:3929) and CLEARED on completion (4699)
        // and on Stop (3548) — a clean, bounded lifecycle, so the drain resumes the
        // moment the bulk restore finishes.
        //
        // An earlier version (reverted) also counted `_transient_wps_ic_restore_<id>`
        // transients. Those are set with set_transient(..., 0) — NO expiry
        // (ajax.class.php:5080), deleted only at 5141. A crash before that delete
        // would leave the transient forever → the drain would yield PERMANENTLY,
        // killing lazy + compress ingestion site-wide. Dropped that count. Single
        // (non-bulk) restores don't trigger the yield, which is fine — they're one
        // image (negligible contention); the yield exists for the bulk case.
        //
        // Bust the per-request option cache before reading. The drain worker
        // is long-running and caches wps_ic_bulk_process at startup. If the worker spawned
        // a few seconds BEFORE the bulk restore flipped this option to 'restoring' (exactly
        // what the debug.log showed: drain started 18:43:01, restore started 18:43:05),
        // get_option returns the STALE pre-restore snapshot → status != 'restoring' → the
        // yield never fires → the drain holds an FPM worker for its full 25s budget,
        // starving the restore. Same staleness class already handled for
        // drain_alive_until_ms (line ~799). Invalidate, then read live.
        wp_cache_delete('wps_ic_bulk_process', 'options');
        $bp = get_option('wps_ic_bulk_process');
        if (is_array($bp) && isset($bp['status']) && $bp['status'] === 'restoring') {
            return 1;
        }
        return 0;
    }
}

/**
 * Continuation check — should drain worker self-chain?
 *
 * Returns true if EITHER:
 *   - Any image has wpc_v2_pending_* transient (Phase A response written it,
 *     Phase B variants still in flight)
 *   - Any image is in 'queueing' or 'optimizing' ic_compressing state
 *     (Phase A still in flight — more variants will land soon)
 *
 * Both queries are indexed-lookup-cheap.
 */
if (!function_exists('wpc_v2_pull_drain_needs_continuation')) {
    function wpc_v2_pull_drain_needs_continuation()
    {
        global $wpdb;
        $pending = $wpdb->get_var(
            "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpc_v2_pending_%' LIMIT 1"
        );
        if ($pending) {
            return true;
        }
        $active = $wpdb->get_var(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key='ic_compressing' "
            . "AND (meta_value LIKE '%queueing%' OR meta_value LIKE '%optimizing%') LIMIT 1"
        );
        return (bool) $active;
    }
}

/**
 * Pending check — is there ANY image on this site with active
 * wpc_v2_pending_* transient? Drain loop exits when this returns false.
 *
 * Implementation: scan wp_options for transient_wpc_v2_pending_*. Cheap
 * because the option table is indexed on option_name. For a busy site
 * with many concurrent compresses, returns true; for a quiet site,
 * returns false fast.
 */
if (!function_exists('wpc_v2_pull_drain_pending_anywhere')) {
    function wpc_v2_pull_drain_pending_anywhere()
    {
        global $wpdb;
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpc_v2_pending_%' LIMIT 1"
        );
        return $count > 0;
    }
}

/**
 * Full pull tick — fetch, queue for drain, kick the drain, persist cursor.
 *
 * Called by:
 *   - wps_ic_pull_manifest AJAX handler (head-poll triggered)
 *   - WP-cron fallback (slow heartbeat if JS isn't running)
 *
 * Returns telemetry. Caller can echo to the JS response.
 */
if (!function_exists('wpc_v2_pull_manifest_tick')) {
    function wpc_v2_pull_manifest_tick($limit = 100, $wait_ms = 0)
    {
        if (!wpc_v2_pull_enabled()) {
            return ['ok' => false, 'reason' => 'flag_off'];
        }

        // Honor bulk STOP (hard): don't fetch/queue/drain after Stop.
        if (get_transient('wpc_bulk_stop_signal')) {
            return ['ok' => false, 'reason' => 'bulk_stopped'];
        }
        // Yield to an active restore (soft): skip the FPM-heavy fetch+
        // drain while a restore runs, but keep the deadline alive so the pipeline
        // resumes once restore clears (the orch's manifest entries persist).
        if (wpc_v2_active_restore_count() > 0) {
            update_option('wpc_v2_drain_alive_until_ms', (int) (microtime(true) * 1000) + 60000, false);
            return ['ok' => false, 'reason' => 'restore_active_yield'];
        }

        $started = microtime(true);
        $since   = wpc_v2_pull_get_cursor();

        $fetch = wpc_v2_pull_manifest_fetch($since, $limit, $wait_ms);
        // Per-phase telemetry: split the tick wall into fetch (incl. long-poll wait) /
        // queue+ingest / ack so a slow tick is attributable without guessing (e.g. the 11s tick on
        // staging-2 was unexplainable from wall_ms alone: long-poll? serial staging fetch? slow ack?).
        $t_fetch_ms = (int) round((microtime(true) - $started) * 1000);
        if (empty($fetch['ok'])) {
            // Forward retry_after so the drain loop can set
            // its cool-off lock with the right TTL.
            $ret = [
                'ok'      => false,
                'reason'  => isset($fetch['error']) ? $fetch['error'] : 'fetch_failed',
                'wall_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
            if (isset($fetch['retry_after'])) {
                $ret['retry_after'] = (int) $fetch['retry_after'];
            }
            return $ret;
        }

        $variants = $fetch['variants'];
        if (empty($variants)) {
            // Even on empty response, advance cursor to high-water if orch sent one.
            // Prevents re-asking the same range every tick when nothing is new.
            if (!empty($fetch['cursor_high_water_ms'])) {
                wpc_v2_pull_set_cursor((int) $fetch['cursor_high_water_ms']);
            }
            return [
                'ok'      => true,
                'queued'  => 0,
                'pages'   => (int) $fetch['pages_fetched'],
                'wall_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }

        $t_q0  = microtime(true);
        $queue = wpc_v2_pull_manifest_queue_for_drain($variants);
        $t_queue_ms = (int) round((microtime(true) - $t_q0) * 1000);

        // Cursor ALWAYS advances on successful fetch, regardless of
        // queue outcome. It once only advanced on queued>0 OR skipped_dedup>0,
        // which left it stuck if returned variants all hit skipped_invalid
        // (e.g., get_attached_file false for an edge case). Resulting tight
        // loop hammered orch with identical since= for 150+ polls.
        // The cursor represents "we've seen up to this point in the manifest"
        // — orthogonal to whether each entry was processable locally.
        // EXCEPT: clamp the cursor BELOW the oldest retry-eligible failure (lazycdn
        // ingest / journal write) so those entries are re-fetched next tick rather than skipped past
        // (the silent-loss bug). skipped_invalid/dedup do NOT clamp — preserving the always-advance behavior.
        if (!empty($fetch['cursor_high_water_ms'])) {
            $cursor_to  = (int) $fetch['cursor_high_water_ms'];
            $min_failed = (int) ($queue['min_failed_completed_ms'] ?? 0);
            if ($min_failed > 0 && ($min_failed - 1) < $cursor_to) {
                $cursor_to = $min_failed - 1;
            }
            wpc_v2_pull_set_cursor($cursor_to);
        }

        // Ack every variant we attempted (queued OR dedup-skipped).
        // Orch needs acks to prune its ZSET — without them, manifest grows
        // unbounded. skipped_invalid entries: we can't ack them (we don't know
        // their sha256), but cursor advance still moves us past them. Orch's
        // 7d TTL backstops cleanup for entries we can't ack.
        //
        // Lazy-CDN entries must respect ingest outcome. queue_for_drain
        // returns lazycdn_acked_sha256s (entries whose ingest succeeded). Failed
        // lazy_cdn ingests should NOT be acked so the orch retries them on the
        // next pull tick (manifest TTL is 8d, plenty of retry window).
        $lazycdn_ack_set = [];
        if (isset($queue['lazycdn_acked_sha256s']) && is_array($queue['lazycdn_acked_sha256s'])) {
            foreach ($queue['lazycdn_acked_sha256s'] as $s) {
                $lazycdn_ack_set[(string) $s] = true;
            }
        }
        // Journal-write-failure exclude set. Entries that failed
        // to land on disk get skipped from the ack batch so orch keeps the
        // manifest entry for retry on the next pull tick. See
        // queue_for_drain return shape for the source of journal_failed_sha256s.
        $journal_failed_set = [];
        if (isset($queue['journal_failed_sha256s']) && is_array($queue['journal_failed_sha256s'])) {
            foreach ($queue['journal_failed_sha256s'] as $s) {
                $journal_failed_set[(string) $s] = true;
            }
        }
        $acks = [];
        foreach ($variants as $v) {
            if (!is_array($v) || empty($v['sha256'])) continue;
            $sha = (string) $v['sha256'];
            // Check three shapes (see queue_for_drain comment for details).
            $is_lazycdn_entry = (isset($v['source']) && $v['source'] === 'lazycdn')
                             || (isset($v['tags']['source']) && $v['tags']['source'] === 'lazycdn')
                             || (isset($v['imageID']) && is_string($v['imageID']) && strpos($v['imageID'], 'lazycdn') === 0);
            if ($is_lazycdn_entry) {
                // Only ack if ingest succeeded
                if (!isset($lazycdn_ack_set[$sha])) continue;
                // imageID for lazy_cdn is orch-derived; cast to int may be
                // lossy for sha1-string-shaped IDs, but the orch's ack handler
                // keys on sha256, so the imageID field is informational.
                $acks[] = [
                    'imageID'   => isset($v['imageID']) ? (int) $v['imageID'] : 0,
                    'sizeLabel' => isset($v['sizeLabel']) ? (string) $v['sizeLabel'] : '',
                    'format'    => isset($v['format'])    ? (string) $v['format']    : '',
                    'sha256'    => $sha,
                ];
                continue;
            }
            // Standard (non-lazy_cdn) ack path
            if (empty($v['imageID'])) continue;
            // Skip ack if journal write failed for this sha. Orch's
            // 8d manifest TTL gives plenty of retry window.
            if (isset($journal_failed_set[$sha])) continue;
            $acks[] = [
                'imageID'   => (int) $v['imageID'],
                'sizeLabel' => isset($v['sizeLabel']) ? (string) $v['sizeLabel'] : '',
                'format'    => isset($v['format'])    ? (string) $v['format']    : '',
                'sha256'    => $sha,
            ];
        }
        $t_a0 = microtime(true);
        if (!empty($acks) && function_exists('wpc_v2_pull_manifest_ack')) {
            wpc_v2_pull_manifest_ack($acks);
        }
        $t_ack_ms = (int) round((microtime(true) - $t_a0) * 1000);

        // Kick the drain. Fast loopback so this AJAX returns immediately.
        if ($queue['queued'] > 0 && function_exists('wpc_v2_journal_fire_loopback_fast')) {
            wpc_v2_journal_fire_loopback_fast();
        }

        // Surface lazy_cdn counters in the tick log. On staging-2
        // (and any lazy_cdn-dominant site) `queued` reflects only journal-
        // bound entries. The dominant runtime work — lazy_cdn ingests —
        // was invisible. Without these fields a "queued=0" tick could mean
        // either "orch returned nothing" or "successfully ingested N lazy_cdn
        // variants" — operationally indistinguishable. Per postmortem item p0.
        $lazycdn_ingested = (int) ($queue['lazycdn_ingested'] ?? 0);
        $lazycdn_failed   = (int) ($queue['lazycdn_failed']   ?? 0);

        error_log(sprintf(
            '[WPC PullManifest] tick since=%d high=%d pages=%d variants=%d queued=%d skip_dedup=%d skip_invalid=%d lazycdn_ingested=%d lazycdn_failed=%d images=%d wall_ms=%d fetch_ms=%d queue_ms=%d ack_ms=%d',
            $since,
            (int) $fetch['cursor_high_water_ms'],
            (int) $fetch['pages_fetched'],
            count($variants),
            (int) $queue['queued'],
            (int) $queue['skipped_dedup'],
            (int) $queue['skipped_invalid'],
            $lazycdn_ingested,
            $lazycdn_failed,
            count($queue['imageIDs']),
            (int) round((microtime(true) - $started) * 1000),
            $t_fetch_ms,
            $t_queue_ms,
            $t_ack_ms
        ));

        // WARNING-level log when all variants on a page were
        // rejected (skipped_invalid == total received). Converts the silent
        // "ack-and-move-on" data-loss case into a grep-able signal. Customer
        // support can filter by this string to find affected sites quickly.
        $total_variants = count($variants);
        if ($total_variants > 0
            && (int) $queue['skipped_invalid'] === $total_variants
            && (int) $queue['queued'] === 0
            && $lazycdn_ingested === 0) {
            error_log(sprintf(
                '[WPC PullManifest] WARNING all_variants_rejected since=%d high=%d variants=%d — cursor advanced past entries that 100%% failed validation; orch 7d TTL backstops re-delivery but this is the silent-skip case',
                $since,
                (int) $fetch['cursor_high_water_ms'],
                $total_variants
            ));
        }

        return [
            'ok'              => true,
            'queued'          => (int) $queue['queued'],
            'skipped_dedup'   => (int) $queue['skipped_dedup'],
            'skipped_invalid' => (int) $queue['skipped_invalid'],
            'lazycdn_ingested' => $lazycdn_ingested,
            'lazycdn_failed'   => $lazycdn_failed,
            'imageIDs'        => $queue['imageIDs'],
            'pages'           => (int) $fetch['pages_fetched'],
            'cursor'          => (int) $fetch['cursor_high_water_ms'],
            'wall_ms'         => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}

// RECONCILIATION BACKSTOP (the missed-wake cure, plugin half; orch's
// level re-wake is the other). Everything upstream is edge-triggered: a wake
// fires when an entry is WRITTEN, and a missed edge (six historical examples
// this release) strands inventory forever. This is the level check: on normal
// frontend traffic, at most once per 15 minutes, fire the drain — it long-polls
// once and idles instantly when the manifest is empty (~96 cheap GETs/day/site
// worst case, the capacity class orch already ack'd for pull-by-default). The
// worker's self-arm gives the fired worker its iteration window.
if (!function_exists('wpc_v2_pull_reconcile_tick')) {
    function wpc_v2_pull_reconcile_tick()
    {
        // Admin/ajax still excluded, but NOT rest/cron: the healthcheck
        // (REST) and the cron tick below are exactly the request types that keep
        // running on a fully page-cached site where frontend HTML never executes
        // PHP. Excluding them earlier is what made the drain "only fire when you
        // check it" — those WERE the only PHP-executing hits on idle staging.
        if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return;
        }
        if (!function_exists('wpc_v2_pull_enabled') || !wpc_v2_pull_enabled()) {
            return;
        }
        // 5-min throttle (was 15): a single page-cache MISS or healthcheck open
        // every few minutes now keeps the manifest draining; the cron is the floor.
        if (get_transient('wpc_v2_pull_reconcile_throttle')) {
            return;
        }
        set_transient('wpc_v2_pull_reconcile_throttle', time(), 5 * MINUTE_IN_SECONDS);
        // Defer behind the response flush (register_shutdown_function +
        // fastcgi_finish_request) so the loopback connect can't delay the visitor's
        // flush on this shutdown tick. Falls back to inline if the helper is absent.
        if (function_exists('wpc_v2_register_deferred_pull_drain')) {
            wpc_v2_register_deferred_pull_drain();
        } elseif (function_exists('wpc_v2_pull_drain_fire')) {
            wpc_v2_pull_drain_fire();
        }
    }
    add_action('shutdown', 'wpc_v2_pull_reconcile_tick', 5);
}

// WP-CRON DRAIN FLOOR. The shutdown tick above only runs when a
// request reaches PHP — on a fully page-cached, low-traffic site (staging is
// exactly this) that can be NEVER, so AVIFs encoded + staged by orch sit in the
// manifest undrained until someone hits an uncached URL. WP-cron is the floor:
// it fires on the first uncached hit OR a real system cron, independent of page
// cache, so the manifest drains on a guaranteed ~5-min cadence with zero traffic
// assumptions. The handler is self-contained (loads its own deps) because cron
// fires under DOING_CRON, where the normal v2 bootstrap is skipped (wp-compress.php
// line ~199). Mirrors the journal-drain cron pattern (v2-direct-entry.php).
if (!function_exists('wpc_v2_pull_cron_run')) {
    function wpc_v2_pull_cron_run()
    {
        if (!function_exists('wpc_v2_pull_enabled')) {
            // Under DOING_CRON the v2 stack isn't bootstrapped — self-load it.
            if (defined('WPS_IC_DIR') && @is_file(WPS_IC_DIR . 'addons/v2/v2-bootstrap.php')) {
                include_once WPS_IC_DIR . 'addons/v2/v2-bootstrap.php';
            }
        }
        if (!function_exists('wpc_v2_pull_enabled') || !wpc_v2_pull_enabled()) {
            return;
        }
        if (function_exists('wpc_v2_pull_drain_fire')) {
            wpc_v2_pull_drain_fire();
        }
    }
}
add_action('wpc_v2_pull_cron', 'wpc_v2_pull_cron_run');
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['wpc_v2_5min'])) {
        $schedules['wpc_v2_5min'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (WPC v2)'];
    }
    return $schedules;
});
add_action('init', function () {
    if (!function_exists('wpc_v2_pull_enabled') || !wpc_v2_pull_enabled()) {
        // Pull off → make sure no stale event lingers.
        if (function_exists('wp_next_scheduled') && wp_next_scheduled('wpc_v2_pull_cron')) {
            wp_clear_scheduled_hook('wpc_v2_pull_cron');
        }
        return;
    }
    if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_v2_pull_cron')) {
        // C3 (fleet audit): per-site jitter so ~10k sites don't sync-fire the
        // 5-min drain cron at the same wall-clock second (thundering herd of manifest GETs
        // + outbound loopback self-POSTs at orch). Offset is STABLE per site (md5(site_url)),
        // spreading the fleet deterministically across the full 300s window — not re-jittered
        // on every reschedule (which random would be).
        $wpc_cron_jit = 0;
        if (function_exists('site_url')) {
            $wpc_cron_jit = (int) (hexdec(substr(md5((string) site_url()), 0, 6)) % 300);
        }
        wp_schedule_event(time() + 60 + $wpc_cron_jit, 'wpc_v2_5min', 'wpc_v2_pull_cron');
    }
}, 20);
