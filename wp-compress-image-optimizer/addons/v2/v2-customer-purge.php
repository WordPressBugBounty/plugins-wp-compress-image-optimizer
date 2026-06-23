<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Customer Purge v1: unified fleet cache invalidation.
 *
 * Spec: "Customer Purge — Unified Fleet Invalidation (v1)" (2026-06-03).
 *
 * There's one entry point, wpc_customer_purge(), which fans out to two
 * independent operations and aggregates them into a single result:
 *
 *   1. CloudFlare  (plugin-local, customer-owned token in option WPS_IC_CF)
 *      via the existing WPC_CloudflareAPI SDK — only if the CF integration
 *      is connected.
 *   2. orch POST {orchestrator}/v2/customer-purge — the WPC stack:
 *      Bunny customer PZ + cdn-mc endpoint PZ + the pod-fs LRU fleet
 *      (Redis fan-out). The plugin never holds the Bunny key / endpoint PZ
 *      id / CDN stats key — orch owns those layers.
 *
 * Why this exists, and why it isn't the old wpc_purge_cdn_urls: restore
 * deletes the optimized variants from the origin disk but, ever since
 * compress.php's cleanRestoreMeta landed, purges no cache layer at all. The
 * `?v=` lazy cache-buster only bypasses the Bunny PZ; it never touches the
 * path-keyed cdn-mc pod-fs LRU, which keeps serving the fossilized AVIF for
 * hours or days (confirmed empirically on 2026-06-03: pod node 1163 served
 * bunny-native AVIF while the origin disk held only .jpg, surviving both a WP
 * restore and a PZ purge). This module reaches that layer with one orch call
 * (a fleet broadcast) instead of the ~1,500 sequential per-URL HTTP calls that
 * made the old inline purge cost around 50s per image.
 *
 * Auth is HMAC-signed, identical to v2-config-sync: X-WPC-Sig: t=<ts>,v1=<hmac>
 * over `ts . '.' . sha256(body)` keyed by the apikey, with the apikey echoed in
 * the body. orch verifies with the existing wpc_v2_verify_hmac().
 *
 * Blocking vs fire-and-forget: restore-triggered purges must not block. They
 * pass $blocking=false so orch and CF fire non-blocking (wp_remote_post
 * 'blocking'=>false, no retry, no result), so a restore never stalls, even on
 * the rare sync fallthrough where cleanRestoreMeta runs in the click worker.
 * The blocking, result-returning path ($blocking=true, the default) is for
 * manual purges and the smoke test, where the caller wants the aggregated
 * per-layer result. This version collects the two legs serially (wp_remote_*
 * has no native parallel collect; typical wall is around 1s, within the
 * p95<1.5s SLO).
 *
 * The whole thing is flag-gated behind wpc_unified_purge_enabled() (option
 * `wpc_unified_purge_enabled`, which has defaulted to TRUE since the v7.01.04
 * GA). Orch /v2/customer-purge has been GA-clean since v3.22.6/.7, where the
 * cdn-tag was scoped per-apikey as `cust:<apikey:12>` and the urls_failed
 * idempotency bug was fixed. Set the option to '0', or use the
 * 'wpc_unified_purge_enabled' filter, to disable, canary, or kill it without a
 * deploy. The three restore call sites are try/catch-guarded so a purge error
 * can't 500 a restore.
 */

/* ---------------------------------------------------------------------------
 * Config / gating
 * ------------------------------------------------------------------------- */

if (!function_exists('wpc_unified_purge_enabled')) {
    /**
     * Master feature flag. The option has defaulted to TRUE since the v7.01.04
     * GA: unset means on, an explicit '0'/false means off (!empty handles
     * both). The 'wpc_unified_purge_enabled' filter overrides per-request, for
     * canary or kill without a deploy.
     */
    function wpc_unified_purge_enabled()
    {
        $opt = get_option('wpc_unified_purge_enabled', true);
        return (bool) apply_filters('wpc_unified_purge_enabled', !empty($opt));
    }
}

if (!function_exists('wpc_customer_purge_endpoint')) {
    /**
     * Resolve the orch /v2/customer-purge endpoint from the same base the rest
     * of the v2 stack uses (constant override, then filter, then geo). Returns
     * '' if the orchestrator base is unknown.
     */
    function wpc_customer_purge_endpoint()
    {
        $base = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        if ($base === '') {
            return '';
        }
        $url = rtrim($base, '/') . '/v2/customer-purge';
        return (string) apply_filters('wpc_customer_purge_endpoint', $url);
    }
}

/* ---------------------------------------------------------------------------
 * Public entry points
 * ------------------------------------------------------------------------- */

if (!function_exists('wpc_purge_compat')) {
    /**
     * Flag-gated entry point that callers (restore handlers, config change)
     * use. With the flag off it's a no-op, which is today's behavior of restore
     * purging nothing; with the flag on it runs the unified purge.
     *
     * @param string $mode     'urls' | 'all'
     * @param array  $urls     required when mode='urls'
     * @param string $reason   enum (restore_image|restore_all|config_changed|...)
     * @param string $apikey   optional — defaults to this site's apikey
     * @param bool   $blocking false = fire-and-forget (restore paths; never blocks)
     * @return array
     */
    function wpc_purge_compat($mode, $urls = [], $reason = 'manual_purge', $apikey = '', $blocking = true)
    {
        if (!wpc_unified_purge_enabled()) {
            return ['ok' => true, 'skipped' => 'flag_off'];
        }
        return wpc_customer_purge($apikey, $mode, $urls, $reason, $blocking);
    }
}

if (!function_exists('wpc_customer_purge')) {
    /**
     * Unified customer-purge — clears CloudFlare (if connected) + the WPC
     * stack in one aggregated call.
     *
     * @param string $apikey   Customer API key. '' → resolve this site's key.
     * @param string $mode     'urls' | 'all'.
     * @param array  $urls     Required if mode='urls'. Absolute or root-relative.
     * @param string $reason   Enum — see spec.
     * @param bool   $blocking false → fire-and-forget (no wait/retry/result).
     * @return array Aggregated response (see spec "Aggregated plugin response shape").
     */
    function wpc_customer_purge($apikey, $mode, $urls = [], $reason = 'manual_purge', $blocking = true)
    {
        $t0 = microtime(true);

        $apikey = (string) $apikey;
        if ($apikey === '' && function_exists('wpc_v2_get_apikey')) {
            $apikey = (string) wpc_v2_get_apikey();
        }

        $mode = ($mode === 'all') ? 'all' : 'urls';
        if ($mode === 'urls') {
            $urls = array_values(array_unique(array_filter(array_map('strval', (array) $urls), 'strlen')));
        } else {
            $urls = [];
        }

        $blocking = (bool) $blocking;

        // Build the handles first (no I/O), then collect. This keeps the shape
        // ready for a curl_multi parallel variant; for now we collect serially.
        $cf_handle   = wpc_purge_cf_async($apikey, $mode, $urls, $blocking);
        $orch_handle = wpc_purge_orch_async($apikey, $mode, $urls, $reason, $blocking);

        $cf_result   = wpc_collect_cf($cf_handle);
        $orch_result = wpc_collect_orch($orch_handle);

        $cf_ok      = !empty($cf_result['ok']);
        $orch_ok    = !empty($orch_result['ok']);
        $any_failed = !$cf_ok || !$orch_ok;

        $orch_layers = (isset($orch_result['layers']) && is_array($orch_result['layers']))
            ? $orch_result['layers']
            : [];

        $result = [
            'ok'            => !$any_failed,
            'apikey_prefix' => substr($apikey, 0, 12),
            'mode'          => $mode,
            'reason'        => (string) $reason,
            'blocking'      => $blocking,
            'duration_ms'   => (int) round((microtime(true) - $t0) * 1000),
            'layers'        => array_merge(['cloudflare' => $cf_result], $orch_layers),
        ];

        error_log(sprintf(
            '[WPC CustomerPurge] ok=%d mode=%s reason=%s urls=%d block=%d dur=%dms cf=%s orch=%s(http=%s) apikey=%s',
            $result['ok'] ? 1 : 0,
            $mode,
            (string) $reason,
            count($urls),
            $blocking ? 1 : 0,
            $result['duration_ms'],
            $cf_ok ? (isset($cf_result['dispatched']) ? 'sent' : 'ok') : (isset($cf_result['connected']) && !$cf_result['connected'] ? 'skip' : 'fail'),
            $orch_ok ? (isset($orch_result['dispatched']) ? 'sent' : 'ok') : 'fail',
            isset($orch_result['http']) ? (string) $orch_result['http'] : '0',
            substr($apikey, 0, 12)
        ));

        return $result;
    }
}

/* ---------------------------------------------------------------------------
 * CloudFlare branch
 * ------------------------------------------------------------------------- */

if (!function_exists('wpc_purge_cf_async')) {
    /**
     * Build a CF purge handle if the customer's CF integration is connected.
     * No I/O here — wpc_collect_cf() fires the API call(s).
     *
     * @return array handle ['type'=>'cf'|'skipped', ...]
     */
    function wpc_purge_cf_async($apikey, $mode, $urls, $blocking = true)
    {
        if (!class_exists('wps_ic_cloudflare') || !class_exists('WPC_CloudflareAPI')) {
            return ['type' => 'skipped', 'reason' => 'cf_sdk_missing'];
        }

        $cf_int = new wps_ic_cloudflare();
        if (!$cf_int->is_active()) {
            return ['type' => 'skipped', 'reason' => 'not_connected'];
        }

        $cf_settings = get_option(WPS_IC_CF);
        if (empty($cf_settings['zone']) || empty($cf_settings['token'])) {
            return ['type' => 'skipped', 'reason' => 'incomplete_cf_settings'];
        }

        return [
            'type'       => 'cf',
            'zone'       => (string) $cf_settings['zone'],
            'token'      => (string) $cf_settings['token'],
            'mode'       => $mode,
            'blocking'   => (bool) $blocking,
            'urls'       => $mode === 'urls' ? wpc_normalize_urls_for_cf($urls) : [],
            'started_at' => microtime(true),
        ];
    }
}

if (!function_exists('wpc_collect_cf')) {
    /**
     * Fire the CF purge for a handle from wpc_purge_cf_async().
     *
     * When blocking=false, these are fire-and-forget chunked POSTs with no wait
     * and no retry. When blocking=true, it's a single purge_everything (for
     * mode=all) or serial chunks of 30 (CF's per-call limit), with one 500ms
     * back-off retry per chunk on a rate-limit error.
     */
    function wpc_collect_cf($handle)
    {
        if (!is_array($handle) || !isset($handle['type']) || $handle['type'] !== 'cf') {
            return [
                'connected' => false,
                'ok'        => true, // being not connected isn't a failure
                'reason'    => is_array($handle) && isset($handle['reason']) ? $handle['reason'] : 'not_connected',
            ];
        }

        // Fire-and-forget path for restore-triggered purges: this never blocks.
        if (empty($handle['blocking'])) {
            $chunks = wpc_cf_fire_nonblocking($handle['token'], $handle['zone'], $handle['mode'], $handle['urls']);
            return [
                'connected'    => true,
                'ok'           => true,
                'dispatched'   => true,
                'chunks_fired' => $chunks,
                'zone'         => substr((string) $handle['zone'], 0, 8),
            ];
        }

        $cf = new WPC_CloudflareAPI($handle['token']);
        $t0 = isset($handle['started_at']) ? $handle['started_at'] : microtime(true);

        $purged       = 0;
        $chunks_fired = 0;
        $errors       = [];

        try {
            if ($handle['mode'] === 'all') {
                $resp = $cf->purgeCache($handle['zone']);
                $chunks_fired = 1;
                if (wpc_cf_response_ok($resp)) {
                    $purged = 'all';
                } else {
                    $errors[] = wpc_cf_extract_error($resp);
                }
            } else {
                $chunks = array_chunk($handle['urls'], 30);
                foreach ($chunks as $chunk) {
                    $resp = $cf->purgeFiles($handle['zone'], $chunk);
                    $chunks_fired++;
                    if (wpc_cf_response_ok($resp)) {
                        $purged += count($chunk);
                        continue;
                    }
                    $err = wpc_cf_extract_error($resp);
                    $errors[] = $err;
                    // On a rate-limit error, retry once after a 500ms back-off.
                    if (wpc_cf_is_rate_limit_error($err)) {
                        usleep(500000);
                        $resp = $cf->purgeFiles($handle['zone'], $chunk);
                        if (wpc_cf_response_ok($resp)) {
                            $purged += count($chunk);
                            array_pop($errors); // the retry worked, so drop the error
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }

        return [
            'connected'      => true,
            'ok'             => empty($errors),
            'urls_purged'    => $purged,
            'chunks_fired'   => $chunks_fired,
            'cf_response_ms' => (int) round((microtime(true) - $t0) * 1000),
            'zone'           => substr((string) $handle['zone'], 0, 8),
            'errors'         => $errors ?: null,
        ];
    }
}

if (!function_exists('wpc_cf_fire_nonblocking')) {
    /**
     * Fire CF purge_cache fire-and-forget style (blocking=false). This bypasses
     * the blocking SDK so a restore-triggered purge never waits on CloudFlare.
     * mode=all sends purge_everything; mode=urls sends one non-blocking POST per
     * chunk of 30. Returns the number of POSTs fired.
     */
    function wpc_cf_fire_nonblocking($token, $zone, $mode, $urls)
    {
        $endpoint = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode((string) $zone) . '/purge_cache';
        $headers  = [
            'Authorization' => 'Bearer ' . (string) $token,
            'Content-Type'  => 'application/json',
        ];
        $fire = function ($payload) use ($endpoint, $headers) {
            wp_remote_post($endpoint, [
                'headers'  => $headers,
                'body'     => wp_json_encode($payload),
                'blocking' => false,
                'timeout'  => 1,
            ]);
        };

        if ($mode === 'all') {
            $fire(['purge_everything' => true]);
            return 1;
        }

        $chunks = array_chunk((array) $urls, 30);
        foreach ($chunks as $chunk) {
            $fire(['files' => array_values($chunk)]);
        }
        return count($chunks);
    }
}

if (!function_exists('wpc_normalize_urls_for_cf')) {
    /**
     * CloudFlare wants absolute URLs, so root-relative paths resolve against
     * this site's URL (the plugin is single-site; $apikey is kept for spec
     * parity but goes unused).
     */
    function wpc_normalize_urls_for_cf($urls, $apikey = '')
    {
        $site_url = rtrim(function_exists('site_url') ? site_url() : '', '/');
        $out = [];
        foreach ((array) $urls as $u) {
            $u = (string) $u;
            if ($u === '') {
                continue;
            }
            if (preg_match('#^https?://#i', $u)) {
                $out[] = $u;
            } else {
                $out[] = $site_url . (substr($u, 0, 1) === '/' ? $u : '/' . $u);
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('wpc_cf_response_ok')) {
    /**
     * WPC_CloudflareAPI returns a decoded array on success, or a WP_Error on
     * HTTP/CF errors (see cf-sdk.php processResponse). A non-error array counts
     * as success; if a 'success' key is present, honor it.
     */
    function wpc_cf_response_ok($resp)
    {
        if (empty($resp) || (function_exists('is_wp_error') && is_wp_error($resp))) {
            return false;
        }
        if (is_array($resp) && array_key_exists('success', $resp)) {
            return (bool) $resp['success'];
        }
        return is_array($resp);
    }
}

if (!function_exists('wpc_cf_extract_error')) {
    function wpc_cf_extract_error($resp)
    {
        if (function_exists('is_wp_error') && is_wp_error($resp)) {
            return $resp->get_error_message();
        }
        if (is_array($resp) && !empty($resp['errors'])) {
            $first = $resp['errors'][0];
            if (is_array($first)) {
                return isset($first['message']) ? (string) $first['message'] : 'unknown CF error';
            }
            return (string) $first;
        }
        return 'unknown CF response shape';
    }
}

if (!function_exists('wpc_cf_is_rate_limit_error')) {
    function wpc_cf_is_rate_limit_error($err)
    {
        $err = (string) $err;
        return stripos($err, 'rate limit') !== false || strpos($err, '429') !== false;
    }
}

/* ---------------------------------------------------------------------------
 * orch branch (HMAC-signed, matches v2-config-sync)
 * ------------------------------------------------------------------------- */

if (!function_exists('wpc_purge_orch_async')) {
    /**
     * Build the orch purge handle (body only — no I/O).
     */
    function wpc_purge_orch_async($apikey, $mode, $urls, $reason, $blocking = true)
    {
        $body = [
            'apikey' => (string) $apikey,
            'mode'   => $mode,
            'reason' => (string) $reason,
        ];
        if ($mode === 'urls') {
            $body['urls'] = array_values($urls);
        }

        // The v3.22.8 self-contained-purge contract: carry the customer's own
        // Bunny pull-zone id and site_url in the HMAC-signed body so the orch
        // can skip its agencySites apikey->site DB lookup. That lookup was
        // hanging (host up, auth OK, then no response, proven via a blocking
        // repro of this exact request), which wedged every restore purge so
        // that everything stayed stale. This is backwards-compatible: a
        // pre-v3.22.8 orch ignores these fields and falls back to the DB lookup,
        // and the HMAC already covers them so they can't be tampered with.
        //   - zone_id: send it only when numeric. wpc_v2_get_zone_id() returns
        //     the raw wpc_v2_zone_id option (a string, with legacy fallbacks)
        //     and can transiently hold a non-numeric/unresolved value; sending
        //     that would mis-target Bunny, so we omit it (the orch's length>0
        //     check then fails and it falls back to the DB) rather than ship
        //     garbage.
        //   - site_url: the same value /v2/config sync sends (site_url(), over
        //     in v2-config-sync.php, not home_url()) so the orch's mode=urls
        //     host check matches the host it already cached from the config sync.
        $zid = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
        if ($zid !== '' && ctype_digit($zid)) {
            $body['zone_id'] = $zid;
        }
        if (function_exists('site_url')) {
            $body['site_url'] = (string) site_url();
        }

        return [
            'type'       => 'orch',
            'apikey'     => (string) $apikey,
            'blocking'   => (bool) $blocking,
            'body'       => $body,
            'started_at' => microtime(true),
        ];
    }
}

if (!function_exists('wpc_collect_orch')) {
    /**
     * POST the orch purge. It's HMAC-signed exactly like v2-config-sync so
     * orch's wpc_v2_verify_hmac() validates it.
     *
     * With blocking=false it's one fire-and-forget POST (no wait, retry, or
     * parse). With blocking=true it waits for the response and does a single
     * jittered retry on a 429.
     *
     * Returns ['ok'=>bool, 'layers'=>array, 'duration_ms'=>int, 'http'=>int].
     * 'layers' is orch's per-layer report, or a synthetic failure-layers map so
     * the aggregated response keeps the canonical shape when orch is
     * unreachable.
     */
    function wpc_collect_orch($handle)
    {
        $t0       = isset($handle['started_at']) ? $handle['started_at'] : microtime(true);
        $endpoint = wpc_customer_purge_endpoint();
        $apikey   = isset($handle['apikey']) ? (string) $handle['apikey'] : '';

        if ($endpoint === '' || $apikey === '') {
            return [
                'ok'          => false,
                'layers'      => wpc_orch_failure_layers('plugin_misconfigured', 'no_endpoint_or_apikey'),
                'duration_ms' => 0,
                'http'        => 0,
            ];
        }

        $body_raw = wp_json_encode($handle['body']);
        if ($body_raw === false) {
            return [
                'ok'          => false,
                'layers'      => wpc_orch_failure_layers('json_encode_failed', ''),
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                'http'        => 0,
            ];
        }

        // Fire-and-forget path for restore-triggered purges: sign, fire, return.
        if (empty($handle['blocking'])) {
            $ts  = time();
            $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);
            wp_remote_post($endpoint, [
                'timeout'   => 1,
                'blocking'  => false,
                'sslverify' => true,
                'headers'   => [
                    'Content-Type' => 'application/json',
                    'x-wpc-apikey' => $apikey, // orch reads the apikey from this header to recover the HMAC key
                    'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                    'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.00.09'),
                ],
                'body' => $body_raw,
            ]);
            return [
                'ok'          => true,
                'dispatched'  => true,
                'layers'      => [],
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                'http'        => 0,
            ];
        }

        $post = function () use ($endpoint, $apikey, $body_raw) {
            $ts  = time();
            $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);
            return wp_remote_post($endpoint, [
                'timeout'   => 8,
                'sslverify' => true,
                'headers'   => [
                    'Content-Type' => 'application/json',
                    'x-wpc-apikey' => $apikey, // orch reads the apikey from this header to recover the HMAC key
                    'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                    'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.00.09'),
                ],
                'body' => $body_raw,
            ]);
        };

        $resp = $post();
        if (is_wp_error($resp)) {
            return [
                'ok'          => false,
                'layers'      => wpc_orch_failure_layers('orch_unreachable', $resp->get_error_message()),
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                'http'        => 0,
            ];
        }

        $code  = (int) wp_remote_retrieve_response_code($resp);
        $rbody = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($rbody)) {
            $rbody = [];
        }

        if ($code === 429) {
            $retry_after = (int) wp_remote_retrieve_header($resp, 'retry-after');
            if ($retry_after <= 0) {
                $retry_after = 2;
            }
            sleep(min($retry_after, 5));
            $resp = $post();
            if (!is_wp_error($resp)) {
                $code  = (int) wp_remote_retrieve_response_code($resp);
                $rbody = json_decode((string) wp_remote_retrieve_body($resp), true);
                if (!is_array($rbody)) {
                    $rbody = [];
                }
            }
        }

        // A 200 means all orch layers are ok; a 207 means partial. Both carry
        // layers[], so we trust orch's own ok flag for the aggregate verdict.
        $ok = ($code >= 200 && $code < 300) && !empty($rbody['ok']);

        $layers = (isset($rbody['layers']) && is_array($rbody['layers']))
            ? $rbody['layers']
            : wpc_orch_failure_layers('http_' . $code, $rbody);

        return [
            'ok'          => $ok,
            'layers'      => $layers,
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'http'        => $code,
        ];
    }
}

if (!function_exists('wpc_orch_failure_layers')) {
    /**
     * Synthetic per-layer failure map so the aggregated response always keeps
     * the canonical {customer_pz, cdn_mc_pz, pod_fs_fleet} shape even when orch
     * never answered.
     */
    function wpc_orch_failure_layers($error, $detail)
    {
        $entry = ['ok' => false, 'error' => (string) $error];
        if ($detail !== '' && $detail !== null) {
            $entry['detail'] = is_scalar($detail) ? (string) $detail : $detail;
        }
        return [
            'customer_pz'  => $entry,
            'cdn_mc_pz'    => $entry,
            'pod_fs_fleet' => $entry,
        ];
    }
}

/* ---------------------------------------------------------------------------
 * Restore-wiring helper
 * ------------------------------------------------------------------------- */

if (!function_exists('wpc_customer_purge_attachment_urls')) {
    /**
     * Build the set of root-relative paths that a restore of $imageID
     * invalidates: the source files (full size, every registered sub-size, and
     * the unscaled original) plus their .webp/.avif siblings, which are the
     * exact paths the CDN layers may have cached. It's derived from attachment
     * metadata, so it works whether the variant files are unlinked yet or not.
     *
     * The paths come back root-relative (e.g. /wp-content/uploads/.../foo.webp):
     * orch resolves them against the apikey's registered site_url (so no
     * www/protocol/migration host-match 403, and immune to any
     * wp_get_attachment_url CDN-host filter), while wpc_normalize_urls_for_cf()
     * re-absolutizes them against site_url for CloudFlare.
     *
     * @return array root-relative paths (deduped).
     */
    function wpc_customer_purge_attachment_urls($imageID)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) {
            return [];
        }

        $main = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($imageID) : '';
        if (!$main) {
            return [];
        }
        $base_url = preg_replace('#/[^/]+$#', '', $main); // the dir URL, with no trailing slash

        $files = [basename($main)];
        $meta  = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($imageID) : [];
        if (is_array($meta)) {
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $sz) {
                    if (!empty($sz['file'])) {
                        $files[] = (string) $sz['file'];
                    }
                }
            }
            if (!empty($meta['original_image'])) {
                $files[] = (string) $meta['original_image'];
            }
        }

        // Adaptive / lazy-backfilled variants (the universal srcset ladder
        // 400..2560 plus retina-doubles of every WP sub-size, e.g. -442x300 or
        // -1132x1536) live only in ic_local_variants postmeta, not in
        // meta['sizes'], so without this the surgical mode=urls purge would leave
        // the exact high-res files browsers actually fetch cached after a
        // restore. Each variant's on-disk name is basename($v['url'])
        // (-{W}x{H}.webp/.avif), and the stem-to-sibling loop below expands each
        // to its .webp/.avif siblings. This runs at restore time while the
        // postmeta still exists (cleanRestoreMeta only deletes ic_local_variants
        // afterward). It's additive: if the postmeta is already gone, we fall
        // back to the meta['sizes'] coverage with no regression.
        $local_variants = function_exists('get_post_meta') ? get_post_meta($imageID, 'ic_local_variants', true) : [];
        if (is_array($local_variants)) {
            foreach ($local_variants as $v) {
                if (is_array($v) && !empty($v['url'])) {
                    $files[] = basename((string) $v['url']);
                }
            }
        }

        // The disk-glob belt (a user caught a 442x600 avif whose meta write had
        // failed still serving from the edge cache through several restores).
        // Adaptive files that exist on disk but in no registry (a meta-write
        // failure, races) would otherwise keep their edge-cached URLs alive past
        // restore for the full cache TTL. This helper runs while the files still
        // exist (cleanRestoreMeta unlinks afterward), so a stem glob catches
        // every -WxH next-gen sibling regardless of registry state. Additive
        // only.
        $att_file_g = function_exists('get_attached_file') ? get_attached_file($imageID) : '';
        if ($att_file_g) {
            $stem_g = pathinfo((function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : '') ?: $att_file_g, PATHINFO_FILENAME);
            foreach ((array) glob(dirname($att_file_g) . '/' . $stem_g . '-*.{avif,webp}', GLOB_BRACE) as $gf) {
                if ($gf) $files[] = basename($gf);
            }
        }

        $abs = [$main];
        foreach (array_unique($files) as $f) {
            $stem = preg_replace('#\.(jpe?g|png|gif|webp|avif)$#i', '', $f);
            $abs[] = $base_url . '/' . $f;            // the source file itself
            $abs[] = $base_url . '/' . $stem . '.webp';
            $abs[] = $base_url . '/' . $stem . '.avif';
            // local-mc strips a -scaled suffix for the full-size next-gen
            // variant, so hero-scaled.jpg becomes hero.webp / hero.avif. Emit
            // that sibling too, otherwise the full-size fossil never gets purged.
            if (preg_match('#-scaled$#', $stem)) {
                $unscaled = preg_replace('#-scaled$#', '', $stem);
                $abs[] = $base_url . '/' . $unscaled . '.webp';
                $abs[] = $base_url . '/' . $unscaled . '.avif';
            }
        }

        $out = [];
        foreach (array_unique(array_filter($abs)) as $u) {
            $rel = function_exists('wp_make_link_relative') ? wp_make_link_relative($u) : $u;
            if ($rel !== '') {
                $out[] = $rel;
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('wpc_restore_cdn_purge')) {
    /**
     * Fire-and-forget CDN invalidation on image restore, as one async POST.
     *
     * v7.08.31 removed the old ~1,500-request inline purge fan-out from the
     * restore paths in favor of the legacy `?v=` cache-buster, but clean-URL
     * delivery (natural/nd, since v7.01.20) carries no version params, so a
     * restored image kept serving its cached optimized variants from the zone
     * until TTL (this was user-reported). This is the Customer-Purge-v1 wiring
     * that was designed for exactly this case: one non-blocking POST with the
     * attachment's root-relative path set (source, sub-sizes, and .webp/.avif
     * siblings), after which orch fans out to customer-PZ, cdn-mc, and pod-fs,
     * with CF purged too when connected. The reason 'restore_single' is on
     * orch's whitelist. The static guard keeps this to once per image per
     * request, since several restore flows write ic_status='restored' more than
     * once.
     */
    function wpc_restore_cdn_purge($imageID)
    {
        static $done = [];
        $imageID = (int) $imageID;
        if ($imageID <= 0 || isset($done[$imageID])) return;
        $done[$imageID] = true;
        if (!function_exists('wpc_customer_purge') || !function_exists('wpc_v2_get_apikey')) return;
        $apikey = (string) wpc_v2_get_apikey();
        if ($apikey === '') return;
        $urls = function_exists('wpc_customer_purge_attachment_urls')
            ? wpc_customer_purge_attachment_urls($imageID)
            : [];
        if (empty($urls)) return;
        wpc_customer_purge($apikey, 'urls', $urls, 'restore_single', false);
    }
}
