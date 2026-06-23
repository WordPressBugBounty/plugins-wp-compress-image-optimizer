<?php
/**
 * WP Compress v7.02 — Direct-entry probe + URL selection + journal drain.
 *
 * Plugin-side companion to /api/v2/*.php direct-entry handlers. Responsibilities:
 *
 *   1. Probe direct-entry health on plugin activation + apikey rotation +
 *      manual admin re-detect. Stores result in wpc_v2_direct_entry_healthy.
 *
 *   2. URL selection helper: wpc_v2_callback_url() returns the active
 *      callback URL (direct-entry .php if healthy, REST endpoint if not).
 *      Called by v2-client.php when building the /optimize-v2 callback envelope.
 *
 *   3. Journal drain AJAX handler (wpc_v2_journal_drain) — loopback-fired by
 *      direct-entry handlers when journal file count crosses threshold, OR
 *      by the WP cron safety net. Single-chain via GET_LOCK. Consolidates
 *      per-image journal entries into ic_local_variants postmeta in batched
 *      writes; runs wpc_v2_recompute_savings once per image after merge.
 *
 *   4. WP cron registration (every 5 min) as safety net for sites with low
 *      traffic where the inbound loopback fire rarely triggers.
 *
 * @see SPEC-direct_entry.md
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_callback_url')) {

/**
 * Returns the active callback URL for the /optimize-v2 envelope. Caches the
 * result per-request so multiple v2-client calls in the same request don't
 * thrash get_option.
 */
function wpc_v2_callback_url($endpoint = 'bg_swap') {
    static $cache = [];
    if (isset($cache[$endpoint])) return $cache[$endpoint];

    $healthy = (bool) get_option('wpc_v2_direct_entry_healthy', false);
    if ($healthy) {
        $cache[$endpoint] = plugins_url('api/v2/' . $endpoint . '.php', WPC_CC_PLUGIN_FILE);
    } else {
        $cache[$endpoint] = rest_url('wpc/v2/' . $endpoint);
    }
    return $cache[$endpoint];
}

/**
 * One-shot probe: posts a random token to /api/v2/health.php and checks the
 * echo matches. If yes, host supports direct-entry path → enable.
 *
 * Side effects:
 *   - wpc_v2_direct_entry_healthy (option): 1 = active, 0 = fallback
 *   - wpc_v2_direct_entry_probe_at (option): unix timestamp of last probe
 *   - wpc_v2_direct_entry_last_error (option): error message if probe failed
 *   - wpc_v2_direct_entry_journal_ok (option): journal-writable flag from header
 *
 * @param bool $force  If true, runs even if probed within last hour. Used by
 *                     admin "Re-detect" button.
 * @return array       ['ok' => bool, 'reason' => string|null, 'detail' => string|null]
 */
function wpc_v2_probe_direct_entry($force = false) {
    // Rate-limit re-probes to once per hour unless forced.
    if (!$force) {
        $last_at = (int) get_option('wpc_v2_direct_entry_probe_at', 0);
        if ($last_at > 0 && (time() - $last_at) < HOUR_IN_SECONDS) {
            return [
                'ok'     => (bool) get_option('wpc_v2_direct_entry_healthy', false),
                'reason' => 'cached',
                'detail' => 'last probe ' . human_time_diff($last_at) . ' ago',
            ];
        }
    }

    $token = wp_generate_password(32, false, false);
    set_transient('wpc_v2_probe_token', $token, MINUTE_IN_SECONDS);

    $url = plugins_url('api/v2/health.php', WPC_CC_PLUGIN_FILE);
    $r   = wp_remote_post($url, [
        'timeout'   => 5,
        'sslverify' => false,
        'blocking'  => true,
        'headers'   => ['X-WPC-Probe' => '1'],
        'body'      => ['probe_token' => $token],
    ]);

    // Always update probe-at so we don't re-probe on every page load.
    update_option('wpc_v2_direct_entry_probe_at', time(), false);

    if (is_wp_error($r)) {
        update_option('wpc_v2_direct_entry_healthy', 0, false);
        update_option('wpc_v2_direct_entry_last_error', 'wp_error: ' . $r->get_error_message(), false);
        return ['ok' => false, 'reason' => 'wp_error', 'detail' => $r->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($r);
    $body = trim((string) wp_remote_retrieve_body($r));

    if ($code !== 200) {
        update_option('wpc_v2_direct_entry_healthy', 0, false);
        update_option('wpc_v2_direct_entry_last_error', 'http_' . $code, false);
        return ['ok' => false, 'reason' => 'http_status', 'detail' => 'got ' . $code];
    }

    if ($body !== $token) {
        // Host didn't run the PHP (returned source/text) or the round-trip
        // broke (transient lost, SHORTINIT crash). Either way: no direct entry.
        update_option('wpc_v2_direct_entry_healthy', 0, false);
        update_option('wpc_v2_direct_entry_last_error', 'token_mismatch', false);
        return ['ok' => false, 'reason' => 'token_mismatch', 'detail' => 'body=' . substr($body, 0, 100)];
    }

    // Optional journal-writable flag from probe response header
    $headers = wp_remote_retrieve_headers($r);
    $journal_ok = '1';
    if (is_object($headers) || is_array($headers)) {
        $h = is_object($headers) ? $headers->getAll() : $headers;
        if (isset($h['x-wpc-journal-writable'])) $journal_ok = (string) $h['x-wpc-journal-writable'];
    }
    update_option('wpc_v2_direct_entry_journal_ok', $journal_ok === '1' ? 1 : 0, false);

    // If direct entry works but uploads/journal isn't writable, we still fall
    // back to REST — batch handler needs to write bytes + journal entries.
    if ($journal_ok !== '1') {
        update_option('wpc_v2_direct_entry_healthy', 0, false);
        update_option('wpc_v2_direct_entry_last_error', 'journal_not_writable', false);
        return ['ok' => false, 'reason' => 'journal_not_writable', 'detail' => 'PHP works but uploads dir is locked'];
    }

    update_option('wpc_v2_direct_entry_healthy', 1, false);
    update_option('wpc_v2_direct_entry_last_error', '', false);
    return ['ok' => true, 'reason' => null, 'detail' => 'direct-entry healthy'];
}

// ─── Trigger probes ──────────────────────────────────────────────────────

// Plugin activation hook (fires once on activate)
register_activation_hook(WPC_CC_PLUGIN_FILE, function () {
    wpc_v2_probe_direct_entry(true);
});

// Re-probe when apikey is saved/rotated (option update hook)
add_action('update_option_wps_ic_options', function ($old_value, $new_value) {
    $old_key = is_array($old_value) ? ($old_value['api_key'] ?? '') : '';
    $new_key = is_array($new_value) ? ($new_value['api_key'] ?? '') : '';
    if ($old_key !== $new_key) {
        wpc_v2_probe_direct_entry(true);
    }
}, 10, 2);

// Auto-probe on first admin page load if we've never probed (e.g. updated from
// a version without this feature — option doesn't exist yet). Synchronous, not
// wp-cron: cron runs under DOING_CRON where wp-compress-core (and this file)
// isn't loaded, so the probe handler wouldn't be registered there. Costs one
// ~1-2s delay on the first post-update page load, then cached for the hour.
add_action('admin_init', function () {
    // Probe is a blocking loopback — skip it on admin-ajax; it'll run on the
    // next regular page load instead.
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    if (get_option('wpc_v2_direct_entry_probe_at', null) === null) {
        wpc_v2_probe_direct_entry(true);
    }
}, 999);

// Manual re-detect AJAX (for admin "Re-detect" button)
add_action('wp_ajax_wpc_v2_redetect_direct_entry', function () {
    if (!current_user_can('manage_wpc_settings')) {
        wp_send_json_error('forbidden');
    }
    $res = wpc_v2_probe_direct_entry(true);
    wp_send_json_success($res);
});

// ─── Journal drain AJAX handler ──────────────────────────────────────────

add_action('wp_ajax_wpc_v2_journal_drain',        'wpc_v2_journal_drain_handler');
add_action('wp_ajax_nopriv_wpc_v2_journal_drain', 'wpc_v2_journal_drain_handler');

/**
 * Drain chain. Same pattern as wpc_bulk_v2_drain in classes/ajax.class.php:
 * single GET_LOCK enforces one chain at a time; processes journal files in
 * iterations bounded by an 8s wall budget; self-chains via loopback if more
 * work remains.
 *
 * Auth: HMAC(t.wpc_v2_drain) with apikey, posted as form fields. NOT cookie
 * auth — direct-entry handlers don't have cookies to forward. The HMAC
 * scheme proves the trigger came from a process that has the apikey, i.e.
 * came from this site.
 */
function wpc_v2_journal_drain_handler() {
    // Auth via HMAC. Canonical helper also reads 'wps_ic_settings' (where the
    // settings UI writes the api_key on fresh installs).
    $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
    if ($apikey === '') {
        wp_die('', '', ['response' => 200]); // silent — nothing to do without apikey
    }
    $ts  = isset($_POST['t']) ? (int) $_POST['t'] : 0;
    $sig = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
    if ($ts <= 0 || $sig === '' || abs(time() - $ts) > 120) {
        wp_die('', '', ['response' => 200]);
    }
    $expected = hash_hmac('sha256', 'wpc_v2_drain.' . $ts, $apikey);
    if (!hash_equals($expected, $sig)) {
        error_log('[wpc_v2_journal_drain] auth_rejected sig_mismatch');
        wp_die('', '', ['response' => 200]);
    }

    wpc_v2_journal_drain_run();
    wp_die('', '', ['response' => 200]);
}

/**
 * The actual drain loop. Extracted so the WP cron safety net can call it
 * directly (no HMAC needed when triggered by cron).
 */
function wpc_v2_journal_drain_run() {
    @ini_set('memory_limit', '256M');
    @ini_set('max_execution_time', '30');
    @ignore_user_abort(true);

    // Yield to a foreground bulk restore. The journal drain is the heaviest
    // background worker (pull + merge) and was starving restores. Gate sits
    // BEFORE GET_LOCK so the drain never even contends for the lock; deferred
    // variants persist as journal files and drain once the restore clears.
    // Keys only on bulk_process==='restoring', so bulk COMPRESS is unaffected.
    if (function_exists('wpc_v2_active_restore_count') && wpc_v2_active_restore_count() > 0) {
        error_log('[wpc_v2_journal_drain] yield_to_restore — deferring journal drain (bulk restore active)');
        return;
    }

    global $wpdb;
    $got = (int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", 'wpc_v2_journal_drain', 0));
    if (!$got) {
        // Another drain chain is already running. Bail silently.
        return;
    }

    try {
        $started = microtime(true);
        $wall_budget_s = 8.0;
        $total_files_drained = 0;
        $total_images = 0;
        $total_files_retained = 0;
        $total_files_abandoned = 0;

        // TTL cleanup: a file stuck failing to pull for >TTL is abandoned, else
        // it retries forever while the CDN edge stays cold. 30min (not the old
        // 5min) because unreliable drain firing on shared hosts was abandoning
        // files before the primary + heartbeat triggers could catch them; files
        // are tiny so a long TTL is cheap.
        $ttl_seconds = defined('WPC_V2_JOURNAL_FILE_TTL_S') ? max(60, (int) WPC_V2_JOURNAL_FILE_TTL_S) : 1800;
        foreach (wpc_v2_journal_list_files(200) as $stale_check) {
            if (file_exists($stale_check) && (time() - filemtime($stale_check)) > $ttl_seconds) {
                $age = time() - filemtime($stale_check);
                @unlink($stale_check);
                $total_files_abandoned++;
                error_log(sprintf(
                    '[wpc_v2_journal_drain] abandoned stale file=%s age_s=%d (TTL=%d)',
                    basename($stale_check), $age, $ttl_seconds
                ));
            }
        }

        // Don't re-pull a retained file within this same 8s budget; retries
        // happen across drain_runs (separate processes) where the edge may
        // have warmed.
        $attempted_this_run = [];

        while ((microtime(true) - $started) < $wall_budget_s) {
            $files = wpc_v2_journal_list_files(50);
            if (!empty($attempted_this_run)) {
                $files = array_values(array_diff($files, $attempted_this_run));
            }
            if (empty($files)) break;

            // Group entries by imageID
            $by_image = [];
            foreach ($files as $file) {
                $raw = @file_get_contents($file);
                if ($raw === false) {
                    @unlink($file);
                    continue;
                }
                $payload = json_decode($raw, true);
                if (!is_array($payload) || empty($payload['imageID'])) {
                    @unlink($file);
                    continue;
                }
                $imageID = (int) $payload['imageID'];
                if (!isset($by_image[$imageID])) {
                    $by_image[$imageID] = [
                        'jobId'   => isset($payload['jobId']) ? (string) $payload['jobId'] : '',
                        'entries' => [],
                        'files'   => [],
                    ];
                }
                // Each file's payload['entries'] is a wrapped structure:
                //   ['flush_reason' => ..., 'received_ms' => ..., 'entries' => [...actual entries...]]
                if (isset($payload['entries']) && is_array($payload['entries'])) {
                    $inner = isset($payload['entries']['entries']) ? $payload['entries']['entries'] : $payload['entries'];
                    if (is_array($inner)) {
                        foreach ($inner as $entry) {
                            $by_image[$imageID]['entries'][] = $entry;
                        }
                    }
                }
                $by_image[$imageID]['files'][] = $file;
                $attempted_this_run[] = $file;  // mark seen so we don't re-pull in this drain run
            }

            // Prefetch: collect every 'persisted_pending_bytes' URL across all
            // images this iteration and fire ONE curl_multi. The long-lived
            // drain process keeps the TLS connection to the CDN warm, so per-pull
            // cost amortizes to ~50ms after the first handshake. URL keys dedupe
            // same-URL-across-images to a single pull. parallel_pull handles
            // integrity + fallback.
            $pulled_bytes_by_url = [];
            $iter_pull_ms = 0;
            $iter_pulls_attempted = 0;
            $iter_pulls_succeeded = 0;
            if (function_exists('wpc_v2_parallel_pull')) {
                $pulls_by_url = [];
                $pull_meta_by_url = [];
                foreach ($by_image as $imageID => $group) {
                    foreach ($group['entries'] as $entry) {
                        if (!is_array($entry)) continue;
                        if (($entry['type'] ?? '') !== 'persisted_pending_bytes') continue;
                        $url = isset($entry['fetch_url']) ? (string) $entry['fetch_url'] : '';
                        if ($url === '' || isset($pulls_by_url[$url])) continue;
                        $pulls_by_url[$url] = $url;
                        $pull_meta_by_url[$url] = [
                            'size'   => isset($entry['bytes_size'])   ? (int) $entry['bytes_size']   : null,
                            'sha256' => isset($entry['bytes_sha256']) ? (string) $entry['bytes_sha256'] : null,
                        ];
                    }
                }
                if (!empty($pulls_by_url)) {
                    $t_pull_start  = microtime(true);
                    $urls_indexed  = array_values($pulls_by_url);
                    $meta_indexed  = array_values($pull_meta_by_url);
                    $pulled        = wpc_v2_parallel_pull($urls_indexed, $meta_indexed);
                    foreach ($urls_indexed as $i => $u) {
                        if (isset($pulled[$i])) {
                            $pulled_bytes_by_url[$u] = $pulled[$i];
                        }
                    }
                    $iter_pull_ms         = (int) round((microtime(true) - $t_pull_start) * 1000);
                    $iter_pulls_attempted = count($urls_indexed);
                    $iter_pulls_succeeded = count($pulled_bytes_by_url);
                    error_log(sprintf(
                        '[wpc_v2_journal_drain_pull] pulled=%d/%d wall_ms=%d',
                        $iter_pulls_succeeded,
                        $iter_pulls_attempted,
                        $iter_pull_ms
                    ));
                }
            }

            foreach ($by_image as $imageID => $group) {
                // No ic_status discard guard here. A blunt "drop if ic_status==restored"
                // check was tried and reverted: a fresh re-compress of a restored image
                // stays 'restored' until Phase A flips it, and Phase B variants routinely
                // win that race — so the guard discarded legit re-compress variants (bulk
                // not landing, BGRetry hammering). A correct guard would be jobId-based
                // (the stale-job rejection the PUSH path uses), not ic_status. TODO.
                $result = wpc_v2_journal_merge_for_image($imageID, $group['jobId'], $group['entries'], $pulled_bytes_by_url);
                // Only delete this image's files when EVERY entry merged cleanly.
                // Any pull_missing → retain for the next iteration to re-attempt
                // (CDN cold-start, usually resolves in 1-2 retries); the TTL
                // cleanup above catches terminally stale files. Already-pulled
                // entries in a retained file get re-pulled, but off a warm edge.
                if ($result['ok'] && empty($result['any_pull_failed'])) {
                    foreach ($group['files'] as $file) {
                        @unlink($file);
                        $total_files_drained++;
                    }
                    $total_images++;
                } else if ($result['ok'] && !empty($result['any_pull_failed'])) {
                    $total_files_retained += count($group['files']);
                    error_log(sprintf(
                        '[wpc_v2_journal_drain] retaining_for_retry imageID=%d files=%d',
                        $imageID, count($group['files'])
                    ));
                }
            }
        }

        error_log(sprintf(
            '[wpc_v2_journal_drain] iter_files_drained=%d images=%d retained=%d abandoned_ttl=%d wall_ms=%d',
            $total_files_drained,
            $total_images,
            $total_files_retained,
            $total_files_abandoned,
            (int) round((microtime(true) - $started) * 1000)
        ));
    } finally {
        $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", 'wpc_v2_journal_drain'));
    }

    // Self-chain if files remain OR a pending-fire marker is set. The marker is
    // set when a batch handler hits the fire throttle and skips firing; without
    // checking it here, that throttled batch's file orphans if no later batch
    // arrives to wake the drain.
    $pending_fire = (int) get_transient('wpc_v2_journal_pending_fire');
    $file_count   = wpc_v2_journal_count_files();
    if ($file_count > 0 || $pending_fire > 0) {
        if ($pending_fire > 0) {
            delete_transient('wpc_v2_journal_pending_fire');
        }
        wpc_v2_journal_fire_loopback_from_wp();
    }
}

/**
 * Merge N journal entries for one image into ic_local_variants under a single
 * GET_LOCK + single update_post_meta. Then recompute_savings if appropriate.
 *
 * @return array ['ok' => bool, 'reason' => string|null, 'merged' => int]
 */
function wpc_v2_journal_merge_for_image($imageID, $jobId, array $entries, array $pulled_bytes_by_url = []) {
    if (empty($entries)) {
        return ['ok' => true, 'merged' => 0, 'reason' => 'no_entries'];
    }
    global $wpdb;
    $lock = 'wpc_bg_meta_' . (int) $imageID;
    // 15s timeout (not 5s) — long enough to win the merge race with v2-client.
    $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $lock, 15));
    $got_lock = ($got === '1' || $got === 1);
    if (!$got_lock) {
        error_log(sprintf('[WPC V2] journal_merge lock_acquire_failed imageID=%d entries=%d — proceeding unlocked (race possible)', (int) $imageID, count($entries)));
    }

    $merged = 0;
    $any_drain_complete_signal = false;
    $any_pull_failed = false;  // v7.02.2 — signals drain_run to keep files for retry
    try {
        wp_cache_delete($imageID, 'post_meta');
        $existing = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($existing)) $existing = [];

        $now = time();
        $now_ms = (int) round(microtime(true) * 1000);
        $t0_ms = (int) get_transient('wpc_v2_t0_ms_' . $imageID);

        foreach ($entries as $e) {
            if (!is_array($e) || empty($e['sizeLabel']) || empty($e['format'])) continue;

            // Second line of defense: a lazy_cdn entry should be intercepted
            // upstream in queue_for_drain, but if one reaches here, dispatch it
            // to the lazy_cdn handler and skip the postmeta path entirely.
            if (isset($e['source']) && $e['source'] === 'lazycdn') {
                if (function_exists('wpc_v2_lazy_cdn_ingest')) {
                    wpc_v2_lazy_cdn_ingest($e);
                }
                continue;
            }

            $sz  = (string) $e['sizeLabel'];
            $fmt = (string) $e['format'];
            // Canonical key — must match wpc_v2_variant_key() (Phase A's
            // record_pending + apply_response). If it doesn't, Phase A's dedup
            // check sees the variant as missing and re-adds it to the pending
            // list forever (chip stuck at a partial count).
            $key = function_exists('wpc_v2_variant_key')
                ? wpc_v2_variant_key($sz, $fmt)
                : ($fmt === 'jpeg' || $fmt === 'jpg' ? $sz : $sz . '-' . $fmt);
            $type = isset($e['type']) ? (string) $e['type'] : 'persisted';

            if ($type === 'no_improvement') {
                $entry = [
                    'bg_no_improvement'     => true,
                    'no_improvement_reason' => isset($e['reason']) ? (string) $e['reason'] : 'no_improvement',
                    'baseline_kb'           => isset($e['baselineKb']) ? (float) $e['baselineKb'] : 0.0,
                    'phase_b_v2'            => true,
                    'phase_b_direct_entry'  => true,
                    'bg_upgraded'           => $now,
                    'bg_upgraded_ms'        => $now_ms,
                ];
                $existing[$key] = array_merge($existing[$key] ?? [], $entry);
                $any_drain_complete_signal = true;
                continue;
            }
            if ($type === 'idempotent_noop') {
                // Already on disk; no meta change needed beyond touching bg_upgraded_ms
                // so heartbeat picks up the re-arrival.
                $existing[$key] = array_merge($existing[$key] ?? [], [
                    'bg_upgraded'    => $now,
                    'bg_upgraded_ms' => $now_ms,
                ]);
                continue;
            }
            // Pull-mode entry. Bytes were already fetched by the drain-level
            // prefetch; look them up by URL, atomic-write to disk, then patch
            // the entry into a 'persisted' shape and fall through below.
            if ($type === 'persisted_pending_bytes') {
                $url = isset($e['fetch_url']) ? (string) $e['fetch_url'] : '';
                $raw = ($url !== '' && isset($pulled_bytes_by_url[$url])) ? $pulled_bytes_by_url[$url] : null;
                if ($raw === null || !is_string($raw) || $raw === '') {
                    error_log(sprintf(
                        '[wpc_v2_journal_merge] pull_missing imageID=%d sizeLabel=%s format=%s url_tail=%s (will retry)',
                        $imageID, $sz, $fmt, $url !== '' ? substr($url, -50) : '-'
                    ));
                    $any_pull_failed = true;
                    continue;
                }
                $dest_dir = isset($e['dest_dir']) ? (string) $e['dest_dir'] : '';
                $filename = isset($e['filename']) ? (string) $e['filename'] : '';
                if ($dest_dir === '' || $filename === '') {
                    error_log(sprintf('[wpc_v2_journal_merge] missing_dest_or_filename imageID=%d sizeLabel=%s format=%s', $imageID, $sz, $fmt));
                    continue;
                }
                // SECURITY: $filename is manifest-supplied and gets written to
                // disk — '../../../wp-config.php' = arbitrary overwrite, 'shell.php'
                // = RCE in uploads. Contract (same as the lazy_cdn sink): basename
                // (kills traversal), no null/leading-dot, final segment must be an
                // image ext, and no interior segment may be an executable type
                // (blocks shell.php.avif). Malformed → skip, never write a hostile
                // name. Benign timestamp dots (…-12.43.25-PM-…) pass.
                $filename = basename($filename);
                $j_segs   = explode('.', strtolower($filename));
                $j_last   = end($j_segs);
                $j_danger = ['php','php3','php4','php5','php6','php7','php8','phps','pht','phtml','phar','shtml','xhtml','html','htm','svg','svgz','js','mjs','jsp','asp','aspx','cgi','pl','py','sh','exe','dll','htaccess','ini','sql','phpt'];
                $j_unsafe = ($filename === '' || $filename[0] === '.' || strpos($filename, "\0") !== false
                    || count($j_segs) < 2
                    || !in_array($j_last, ['jpg','jpeg','png','gif','webp','avif'], true));
                if (!$j_unsafe) {
                    foreach (array_slice($j_segs, 0, -1) as $j_seg) {
                        if (in_array($j_seg, $j_danger, true)) { $j_unsafe = true; break; }
                    }
                }
                if ($j_unsafe) {
                    error_log(sprintf('[wpc_v2_journal_merge] reject_unsafe_filename imageID=%d fn=%s', (int) $imageID, substr($filename, 0, 60)));
                    continue;
                }
                $dest = $dest_dir . '/' . $filename;

                // Idempotent fast path: if same bytes already on disk, no write.
                $skip_write = false;
                if (file_exists($dest) && filesize($dest) === strlen($raw)
                    && hash_file('sha256', $dest) === hash('sha256', $raw)) {
                    $skip_write = true;
                }
                if (!$skip_write) {
                    $tmp = $dest . '.wpc_v2_tmp_' . wp_generate_password(8, false);
                    // Log errno + bytes on failure so disk-full vs perms-denied vs
                    // missing-dir are distinguishable.
                    if (@file_put_contents($tmp, $raw) === false) {
                        $err = error_get_last();
                        error_log(sprintf(
                            '[wpc_v2_journal_merge] write_failed imageID=%d sz=%s fmt=%s bytes=%d dest_tail=%s msg=%s',
                            (int) $imageID, (string) $sz, (string) $fmt, strlen($raw),
                            substr($dest, -60), $err['message'] ?? '-'
                        ));
                        continue;
                    }
                    if (!@rename($tmp, $dest)) {
                        $err = error_get_last();
                        error_log(sprintf(
                            '[wpc_v2_journal_merge] rename_failed imageID=%d sz=%s fmt=%s dest_tail=%s msg=%s',
                            (int) $imageID, (string) $sz, (string) $fmt,
                            substr($dest, -60), $err['message'] ?? '-'
                        ));
                        @unlink($tmp);
                        continue;
                    }
                    if (!@chmod($dest, 0644)) {
                        $err = error_get_last();
                        error_log(sprintf(
                            '[wpc_v2_journal_merge] chmod_failed imageID=%d dest_tail=%s msg=%s',
                            (int) $imageID, substr($dest, -60), $err['message'] ?? '-'
                        ));
                    }
                }
                // Patch into an inline-bytes shape so the 'persisted' block below
                // handles it. bytes_size from actual bytes, not service-supplied.
                $e['bytes_path'] = $dest;
                $e['bytes_size'] = strlen($raw);
                // type now effectively 'persisted' — falls through.
            }
            // Default: 'persisted'
            $orig_size = isset($e['originalSize']) ? (int) $e['originalSize'] : 0;
            $bytes_size = isset($e['bytes_size']) ? (int) $e['bytes_size'] : 0;
            $savings = ($orig_size > 0 && $bytes_size > 0)
                ? max(0, (int) round((1 - ($bytes_size / $orig_size)) * 100))
                : 0;
            $url = '';
            if (!empty($e['bytes_path'])) {
                $up = wp_get_upload_dir();
                $rel = ltrim(str_replace($up['basedir'], '', $e['bytes_path']), '/');
                $url = $up['baseurl'] . '/' . $rel;
            }
            // bg_upgraded_ms must be PERSISTENCE time, not the encoder's
            // encode-complete ms. The encoder ms for a pull variant is often
            // earlier than push variants already landed on the same image, so
            // the bulk-heartbeat cursor (ms <= since_ms) would drop these as
            // "stale" — visible in ML but missing from the bulk feed. Encoder
            // ms is kept separately as encoded_at_ms for telemetry.
            $entry = [
                'size'                => $bytes_size,
                'originalSize'        => $orig_size,
                'url'                 => $url,
                'local'               => true,
                'skipped'             => false,
                'savings'             => $savings,
                'bg_upgraded'         => $now,
                'bg_upgraded_ms'      => $now_ms,
                'encoded_at_ms'       => isset($e['ms']) && $e['ms'] > 0 ? (int) $e['ms'] : 0,
                'bg_t_from_click_ms'  => ($t0_ms > 0 && isset($e['ms']) && $e['ms'] > $t0_ms) ? ((int) $e['ms'] - $t0_ms) : 0,
                'kb_reported'         => isset($e['kb']) ? (float) $e['kb'] : 0.0,
                'butter'              => isset($e['butter']) ? (float) $e['butter'] : 0.0,
                'phase_b_v2'          => true,
                'phase_b_direct_entry' => true,
            ];
            // Persist bytes_sha256 so pull-manifest can dedupe pre-flight (skip
            // variants already on disk via push).
            if (!empty($e['bytes_sha256'])) {
                $entry['bytes_sha256'] = (string) $e['bytes_sha256'];
            }
            // delivery_method passes through for the telemetry split.
            if (!empty($e['delivery_method'])) {
                $entry['delivery_method'] = (string) $e['delivery_method'];
            }
            if (!empty($e['source'])) {
                $entry['journal_source'] = (string) $e['source'];
            }
            if (isset($e['q']))      $entry['q']      = (int) $e['q'];
            if (isset($e['bumped'])) $entry['bumped'] = (string) $e['bumped'];
            $existing[$key] = array_merge($existing[$key] ?? [], $entry);
            $merged++;
            $any_drain_complete_signal = true;

            // Climb ic_savings on each variant arrival (mirrors the push callback)
            // so the headline number updates live instead of waiting for the
            // shutdown recompute. Monotonic — only raises, never lowers.
            if ($orig_size > 0 && $bytes_size > 0 && $savings > 0) {
                $cur_savings = (float) get_post_meta($imageID, 'ic_savings', true);
                if ((float) $savings > $cur_savings) {
                    update_post_meta($imageID, 'ic_savings',          round((float) $savings, 1));
                    update_post_meta($imageID, 'ic_savings_format',   $fmt);
                    update_post_meta($imageID, 'ic_savings_bytes',    max(0, $orig_size - $bytes_size));
                    update_post_meta($imageID, 'ic_savings_baseline', $orig_size);
                }
            }

            // Heartbeat on each merged variant so the chip animation ticks up
            // in real time during pull-driven landings.
            $chip_fmt  = strtoupper((string) $fmt);
            $chip_size = ucfirst(str_replace(['_', '-'], ' ', (string) $sz));
            $compressing = get_post_meta($imageID, 'ic_compressing', true);
            $current_status = (is_array($compressing) && !empty($compressing['status']))
                ? (string) $compressing['status'] : 'optimizing';

            // Eager-flip to 'compressed' (mirrors the push callback, flag-gated).
            // Phase A's promote only fires when it returns with all variants, which
            // it never does for pull (Phase B lands via manifest). The chip update
            // is gated on the card's 'compressed' class, so without this flip the
            // card stays 'optimizing' and the chip never refreshes.
            $eager = function_exists('wpc_v2_use_eager_compressed_flip')
                && wpc_v2_use_eager_compressed_flip();
            if ($eager && $current_status !== 'compressed') {
                wpc_v2_ic_compressing_set_status($imageID, 'compressed');
                delete_transient('wps_ic_compress_' . $imageID);
                $current_status = 'compressed';
            }

            set_transient('wps_ic_heartbeat_' . $imageID, [
                'imageID'         => $imageID,
                'status'          => $current_status,
                'event'           => 'bg_variant_arrived',
                'time'            => time(),
                'bg_variant_fmt'  => $chip_fmt,
                'bg_variant_size' => $chip_size,
            ], 300);

            // Remove from wpc_v2_pending_$id (mirrors REST handler's behavior)
            if (function_exists('wpc_v2_remove_pending')) {
                $drain_complete = wpc_v2_remove_pending($imageID, $sz, $fmt);
                if ($drain_complete) {
                    $any_drain_complete_signal = true;

                    // On full drain, flip to 'compressed' even with the eager flag
                    // off — otherwise a pull-only image with all variants on disk
                    // sits visually 'optimizing' forever (Phase A's promote never
                    // fires for manifest-delivered variants).
                    if ($current_status !== 'compressed') {
                        wpc_v2_ic_compressing_set_status($imageID, 'compressed');
                        delete_transient('wps_ic_compress_' . $imageID);
                    }

                    // Extend the alive window 10s past drain_complete so the worker
                    // keeps polling for late variants (observed up to +10s). Bust
                    // the options cache first — a long-lived FPM worker reading its
                    // own startup snapshot could otherwise write back a smaller
                    // value than another worker set, shrinking the window.
                    wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
                    $now_ms = (int) (microtime(true) * 1000);
                    $extend_to = $now_ms + 10000;
                    $current_deadline = (int) get_option('wpc_v2_drain_alive_until_ms', 0);
                    if ($extend_to > $current_deadline) {
                        update_option('wpc_v2_drain_alive_until_ms', $extend_to, false);
                    }

                    // Log which specific variants are missing, by diffing on-disk
                    // keys against the full expected size×format matrix.
                    $img_variants = get_post_meta($imageID, 'ic_local_variants', true);
                    if (is_array($img_variants)) {
                        $cnt_j = 0; $cnt_w = 0; $cnt_a = 0;
                        foreach ($img_variants as $vk => $ve) {
                            if (!is_array($ve)) continue;
                            if (!empty($ve['bg_no_improvement'])) continue;
                            if (empty($ve['size'])) continue;
                            if (strpos((string) $vk, '-avif') !== false)      $cnt_a++;
                            elseif (strpos((string) $vk, '-webp') !== false)  $cnt_w++;
                            else                                              $cnt_j++;
                        }
                        $total = $cnt_j + $cnt_w + $cnt_a;
                        $expected_sizes = ['thumbnail','medium','medium_large','large','1536x1536','2048x2048','scaled','original'];
                        $missing_keys = [];
                        foreach ($expected_sizes as $sz_label) {
                            foreach (['jpeg', 'webp', 'avif'] as $fmt_label) {
                                $expected_key = function_exists('wpc_v2_variant_key')
                                    ? wpc_v2_variant_key($sz_label, $fmt_label)
                                    : ($fmt_label === 'jpeg' ? $sz_label : $sz_label . '-' . $fmt_label);
                                if (!isset($img_variants[$expected_key])
                                    || !is_array($img_variants[$expected_key])
                                    || empty($img_variants[$expected_key]['size'])) {
                                    $missing_keys[] = $expected_key;
                                }
                            }
                        }
                        if ($total < 22) {
                            error_log(sprintf(
                                '[WPC DrainComplete] imageID=%d INCOMPLETE total=%d J=%d W=%d A=%d missing=[%s]',
                                $imageID, $total, $cnt_j, $cnt_w, $cnt_a, implode(', ', $missing_keys)
                            ));
                        } elseif (!empty($missing_keys)) {
                            error_log(sprintf(
                                '[WPC DrainComplete] imageID=%d near_complete total=%d J=%d W=%d A=%d missing=[%s]',
                                $imageID, $total, $cnt_j, $cnt_w, $cnt_a, implode(', ', $missing_keys)
                            ));
                        } else {
                            error_log(sprintf(
                                '[WPC DrainComplete] imageID=%d ok total=%d J=%d W=%d A=%d',
                                $imageID, $total, $cnt_j, $cnt_w, $cnt_a
                            ));
                        }

                        // Fire BGRetry from the server-side drain, not just the bulk
                        // heartbeat — the heartbeat polls only while the bulk UI is open,
                        // so missing variants were never re-requested for single/lazy
                        // compresses or a backgrounded tab. The drain runs regardless of
                        // UI. Shares the heartbeat's one-shot guard transient so it can't
                        // hammer, and BGRetry self-caps at 3 attempts → retry_exhausted.
                        // BGRetry RE-PULLS the manifest (recovers late-landed variants); it
                        // does NOT re-encode, so a variant the encoder never produced ends
                        // in retry_exhausted and needs a re-compress.
                        if (!empty($missing_keys) && function_exists('wpc_v2_fire_image_bg_retry')) {
                            $dc_retry_guard = 'wpc_v2_bg_retry_fired_' . $imageID;
                            if (!get_transient($dc_retry_guard)) {
                                set_transient($dc_retry_guard, 1, 60);
                                error_log(sprintf(
                                    '[WPC DrainComplete] imageID=%d firing server-side BGRetry — %d missing',
                                    $imageID, count($missing_keys)
                                ));
                                wpc_v2_fire_image_bg_retry($imageID);
                            }
                        }
                    }
                }
            }
        }
        update_post_meta($imageID, 'ic_local_variants', $existing);

        // Status-promotion parity with the lazy_cdn sink. This sink writes real
        // variants to disk but never promoted ic_status, so a re-optimize that
        // isn't tagged source=lazycdn (e.g. after a restore → edge re-encode →
        // pull lands here) ended up optimized on disk yet stuck on "Compress" in
        // the ML row (the renderer gates on ic_status, not ic_local_variants).
        // GUARD (same as that sink): only when not already 'compressed' AND no
        // Phase A in flight — so this can never mark a half-done optimize complete
        // or clobber the pull-vs-Phase-A race. $merged>0 = a real variant landed.
        if ($merged > 0) {
            $promote_status = get_post_meta($imageID, 'ic_status', true);
            if ($promote_status !== 'compressed') {
                $promote_cmp        = get_post_meta($imageID, 'ic_compressing', true);
                $promote_cmp_status = (is_array($promote_cmp) && !empty($promote_cmp['status']))
                    ? (string) $promote_cmp['status']
                    : '';
                if ($promote_cmp_status !== 'optimizing' && $promote_cmp_status !== 'queueing') {
                    update_post_meta($imageID, 'ic_status', 'compressed');
                    if (function_exists('wpc_invalidate_local_cache')) wpc_invalidate_local_cache();
                    if ($promote_cmp_status !== 'compressed') {
                        update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
                    }
                }
            }
        }
    } finally {
        if ($got_lock) {
            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock));
        }
    }

    // Recompute savings once per image after merge (defer via shutdown so we
    // don't hold the drain-worker on it).
    if ($any_drain_complete_signal && function_exists('wpc_v2_recompute_savings')) {
        $imageID_for_shutdown = (int) $imageID;
        add_action('shutdown', function () use ($imageID_for_shutdown) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (function_exists('wpc_v2_recompute_savings')) {
                wpc_v2_recompute_savings($imageID_for_shutdown);
            }
            // Invalidate HTML cache for pages referencing this attachment so the
            // next render emits natural URLs, not stale CDN transform URLs.
            if (function_exists('wpc_v2_purge_html_for_attachment')) {
                wpc_v2_purge_html_for_attachment($imageID_for_shutdown, 'direct-entry');
            }
        }, 0);
    }

    return ['ok' => true, 'merged' => $merged, 'reason' => null, 'any_pull_failed' => $any_pull_failed];
}

/**
 * List up to $limit .jsonl files in the journal dir, oldest first.
 */
function wpc_v2_journal_list_files($limit = 50) {
    $up = wp_get_upload_dir();
    if (empty($up['basedir'])) return [];
    $dir = rtrim($up['basedir'], '/\\') . '/wpci-journal';
    if (!is_dir($dir)) return [];
    $files = [];
    $dh = @opendir($dir);
    if (!$dh) return [];
    while (($f = readdir($dh)) !== false) {
        if (substr($f, -6) !== '.jsonl') continue;
        $files[] = $dir . '/' . $f;
        if (count($files) >= $limit) break;
    }
    closedir($dh);
    sort($files); // filename embeds unix-ms so sort = chronological order
    return $files;
}

/**
 * Cheap count of .jsonl files (no full readdir loop — just for the
 * "should we self-chain?" check).
 */
function wpc_v2_journal_count_files() {
    $up = wp_get_upload_dir();
    if (empty($up['basedir'])) return 0;
    $dir = rtrim($up['basedir'], '/\\') . '/wpci-journal';
    if (!is_dir($dir)) return 0;
    $n = 0;
    $dh = @opendir($dir);
    if (!$dh) return 0;
    while (($f = readdir($dh)) !== false) {
        if (substr($f, -6) === '.jsonl') $n++;
        if ($n > 1000) break; // sanity cap
    }
    closedir($dh);
    return $n;
}

/**
 * Fire loopback drain from within WP context (used by self-chain + cron).
 * Direct-entry handlers use their own wpc_v2_journal_fire_loopback() since
 * they may not have wp_remote_post available in SHORTINIT.
 */
function wpc_v2_journal_fire_loopback_from_wp() {
    // Canonical helper (reads 'wps_ic_settings', falls back to 'wps_ic_options').
    $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
    if ($apikey === '') return;
    $ts = time();
    $sig = hash_hmac('sha256', 'wpc_v2_drain.' . $ts, $apikey);
    // Local-vhost loopback, NOT a wp_remote_post to the public host: on a
    // datacenter-IP site that host is the CDN/WAF edge, so the self-POST returns
    // truthy but never lands on local PHP-FPM. Also runs under cron, where core's
    // autoloader isn't registered and wps_ic_ajax may be absent — so prefer the
    // shared helper when present, else inline the same connect (127.0.0.1 →
    // localhost → public host; Host/SNI = public host).
    $jw_parts = wp_parse_url(admin_url('admin-ajax.php'));
    if (!empty($jw_parts['host'])) {
        $jw_https = (!empty($jw_parts['scheme']) && $jw_parts['scheme'] === 'https');
        $jw_port  = !empty($jw_parts['port']) ? (int) $jw_parts['port'] : ($jw_https ? 443 : 80);
        $jw_host  = (string) $jw_parts['host'];
        $jw_path  = (!empty($jw_parts['path']) ? $jw_parts['path'] : '/') . '?action=wpc_v2_journal_drain';
        $jw_body  = http_build_query(['t' => $ts, 'sig' => $sig]);
        $jw_req   = "POST {$jw_path} HTTP/1.1\r\nHost: {$jw_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                  . "Content-Length: " . strlen($jw_body) . "\r\nConnection: close\r\nUser-Agent: WPCJournalDrain/1.0\r\n\r\n" . $jw_body;
        $jw_fp = false;
        if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) {
            $jw_fp = wps_ic_ajax::wpc_loopback_open_socket($jw_host, $jw_port, $jw_https, 0.2);
        } else {
            $jw_ctx = $jw_https ? stream_context_create(['ssl' => ['peer_name' => $jw_host, 'SNI_enabled' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]) : null;
            foreach (['127.0.0.1', 'localhost', $jw_host] as $jw_chost) {
                $jw_errno = 0; $jw_errstr = '';
                $jw_remote = ($jw_https ? 'tls://' : 'tcp://') . $jw_chost . ':' . $jw_port;
                $jw_sock   = $jw_ctx
                    ? @stream_socket_client($jw_remote, $jw_errno, $jw_errstr, 0.2, STREAM_CLIENT_CONNECT, $jw_ctx)
                    : @stream_socket_client($jw_remote, $jw_errno, $jw_errstr, 0.2);
                if ($jw_sock) { $jw_fp = $jw_sock; break; }
            }
        }
        if ($jw_fp) { @stream_set_timeout($jw_fp, 0, 100000); @fwrite($jw_fp, $jw_req); @fclose($jw_fp); }
    }
}

// ─── WP cron safety net (every 5 min) ────────────────────────────────────

add_action('wpc_v2_journal_drain_cron', 'wpc_v2_journal_drain_run');

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['wpc_v2_5min'])) {
        $schedules['wpc_v2_5min'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 minutes (WPC v2 journal drain safety net)',
        ];
    }
    return $schedules;
});

add_action('init', function () {
    // Register the drain cron only when direct entry is healthy. The REST journal
    // path can't use a cron: wp-compress-core is gated off under DOING_CRON, so
    // the callback + cron_schedules filter aren't loaded at fire time (→
    // "invalid_schedule", no-op event). It relies on the per-batch loopback fire
    // instead, which keeps the chain hot during an active bulk. A true safety net
    // for the REST path would need a stub in wp-compress-cron.php (loaded under
    // DOING_CRON) — follow-up.
    $direct_entry_ok = (bool) get_option('wpc_v2_direct_entry_healthy', false);
    $scheduled = wp_next_scheduled('wpc_v2_journal_drain_cron');
    if ($direct_entry_ok && !$scheduled) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'wpc_v2_5min', 'wpc_v2_journal_drain_cron');
    } elseif (!$direct_entry_ok && $scheduled) {
        wp_unschedule_event($scheduled, 'wpc_v2_journal_drain_cron');
    }
}, 100);

// Cleanup on plugin deactivation
register_deactivation_hook(WPC_CC_PLUGIN_FILE, function () {
    $ts = wp_next_scheduled('wpc_v2_journal_drain_cron');
    if ($ts) wp_unschedule_event($ts, 'wpc_v2_journal_drain_cron');
});

} // end if (!function_exists('wpc_v2_callback_url'))
