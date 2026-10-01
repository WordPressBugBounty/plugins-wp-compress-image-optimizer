<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_use_v2_protocol')) {


if (!defined('WPC_V2_CAPS_CACHE_KEY'))     define('WPC_V2_CAPS_CACHE_KEY',     'wpc_v2_capabilities');
if (!defined('WPC_V2_CAPS_TTL'))           define('WPC_V2_CAPS_TTL',           86400);   // 24h


if (!function_exists('wpc_v2_get_apikey')) {
    function wpc_v2_get_apikey()
    {
        // 1) Canonical — `wps_ic` option (WPS_IC_OPTIONS).
        $canon = get_option('wps_ic');
        if (is_array($canon) && !empty($canon['api_key'])) {
            return (string) $canon['api_key'];
        }
        // 2) Migration-staging option `wps_ic_options` (WPS_IC_OPTIONS_V2).
        $migration = get_option('wps_ic_options');
        if (is_array($migration) && !empty($migration['api_key'])) {
            return (string) $migration['api_key'];
        }
        // 3) Settings option `wps_ic_settings` — `api_key` field is rarely
        //    populated there but check as last resort.
        $settings = get_option('wps_ic_settings');
        if (is_array($settings) && !empty($settings['api_key'])) {
            return (string) $settings['api_key'];
        }
        return '';
    }
}

/**
 * True: the v2 orchestrator is the only compression protocol. Rule: nothing switches a site off
 * it. Observed failure: the gate read `wpc_protocol_version` (v1 / shadow / auto), which no
 * UI writes; `wp wpcompress v2-test` wrote `auto`, and auto asked the capability probe and
 * then a canary cohort that defaults to 0 %, so running the test turned v2 off on that site
 * and sent its images to the retired v1 routes (404).
 */
function wpc_use_v2_protocol()
{
    return true;
}


function wpc_probe_orchestrator_capabilities($force = false)
{
    $cached = get_site_transient(WPC_V2_CAPS_CACHE_KEY);
    if (is_array($cached) && !$force) {
        return $cached;
    }

    $orchestrator_url = wpc_v2_orchestrator_url();
    if ($orchestrator_url === '') {
        return wpc_v2_safe_fallback_caps('no_orchestrator_url');
    }

    $response = wp_remote_get($orchestrator_url . '/capabilities', [
        'timeout' => 5,
        'headers' => ['Accept' => 'application/json'],
    ]);

    if (is_wp_error($response)) {
        error_log('[WPC V2Caps] probe transport failure: ' . $response->get_error_message());
        return is_array($cached) ? $cached : wpc_v2_safe_fallback_caps('transport_error');
    }
    $code = (int) wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code !== 200 || !is_array($body)) {
        error_log('[WPC V2Caps] probe non-200: code=' . $code);
        return is_array($cached) ? $cached : wpc_v2_safe_fallback_caps('non_200');
    }

    $caps = [
        'v1_optimize'              => !empty($body['v1_optimize']),
        'v2_optimize'              => !empty($body['v2_optimize']),
        'v2_callback_endpoint'     => isset($body['v2_callback_endpoint']) ? (string) $body['v2_callback_endpoint'] : '/wpc/v2/bg_swap',
        'max_inline_bytes'         => isset($body['max_inline_bytes']) ? (int) $body['max_inline_bytes'] : 26214400,
        'max_callback_bytes'       => isset($body['max_callback_bytes']) ? (int) $body['max_callback_bytes'] : 4194304,
        'max_callbacks_per_second' => isset($body['max_callbacks_per_second']) ? (int) $body['max_callbacks_per_second'] : 10,
        // The most megapixels the service encodes from; a larger original answers 413 (read by
        // WPS_LocalV2::build_request_body()).
        'max_source_mp'            => isset($body['max_source_mp']) ? (float) $body['max_source_mp'] : 0.0,

        'status_poll_supported'    => !empty($body['status_poll_supported']),
        'source_cache_enabled'     => !empty($body['source_cache_enabled']),
        'signed_urls_supported'    => !empty($body['signed_urls_supported']),
        'redeliver_supported'      => !empty($body['redeliver_supported']),
        // The host the service pulls variants from, when it declares one: the inbound verifier
        // allows fetch URLs on it (wpc_v2_inbound_fetch_hosts).
        'variants_host'            => isset($body['variants_host']) ? (string) $body['variants_host'] : '',
        'probed_at'                => time(),
        'probe_source'             => 'live',
    ];

    set_site_transient(WPC_V2_CAPS_CACHE_KEY, $caps, WPC_V2_CAPS_TTL);
    return $caps;
}


function wpc_v2_orchestrator_url()
{

    if (defined('WPC_V2_ORCHESTRATOR_URL') && WPC_V2_ORCHESTRATOR_URL !== '') {
        return rtrim((string) WPC_V2_ORCHESTRATOR_URL, '/');
    }

    // 2) Filter override.
    $override = apply_filters('wpc_v2_orchestrator_url', '');
    if ($override !== '') return rtrim((string) $override, '/');


    $valid_hosts = apply_filters('wpc_v2_orchestrator_valid_hosts', [
        'local-mc.zapwp.net',
    ]);
    $geo = get_option('wps_ic_geo_locate_v2');
    if (is_array($geo) && !empty($geo['server'])) {
        $server = trim((string) $geo['server'], '/');
        // Strip scheme for the whitelist check; preserve original for return.
        $host_only = preg_replace('#^https?://#i', '', $server);
        if (in_array($host_only, $valid_hosts, true)) {
            if (preg_match('#^https?://#i', $server)) return $server;
            return 'https://' . $server;
        }


    }


    return 'https://local-mc.zapwp.net';
}

/**
 * The signature headers of a request to the image service's signed plugin routes (the manifest
 * GET, ack and purge, and the variants list and delete). $message is what the route verifies:
 * the canonical query for a GET, the raw body for a POST.
 *
 * Rule: X-WPC-Sig = HMAC-SHA256(apikey, "<X-WPC-Timestamp>.<message>"), the timestamp being the
 * exact header string sent, as verifyHmacRawBytes() in the orchestrator's lib/manifestPull.js
 * checks its v2 form. Observed: the v1 form HMAC(apikey, message) bound nothing to the timestamp,
 * so a captured request replayed with a fresh X-WPC-Timestamp still verified; the service
 * accepts v2 since v3.24.143 and drops v1 once its sig_form counter shows v1 near zero (hub ask
 * 047, item 1).
 */
function wpc_v2_service_sign($apikey, $message, $ts = null)
{
    $ts = (string) ($ts === null ? time() : (int) $ts);
    return [
        'X-WPC-Sig'       => hash_hmac('sha256', $ts . '.' . (string) $message, (string) $apikey),
        'X-WPC-Timestamp' => $ts,
    ];
}

/**
 * The capabilities answered when the probe fails and no prior cache exists (read by
 * `wp wpcompress v2-probe`; the protocol gate no longer asks).
 */
function wpc_v2_safe_fallback_caps($reason)
{
    return [
        'v1_optimize'              => true,
        'v2_optimize'              => false,
        'v2_callback_endpoint'     => '/wpc/v2/bg_swap',
        'max_inline_bytes'         => 26214400,
        'max_callback_bytes'       => 4194304,
        'max_callbacks_per_second' => 10,
        'status_poll_supported'    => false,
        'source_cache_enabled'     => false,
        'signed_urls_supported'    => false,
        'redeliver_supported'      => false,
        'probed_at'                => time(),
        'probe_source'             => 'fallback',
        'fallback_reason'          => $reason,
    ];
}

/**
 * Admin-side hook: force-refresh on plugin upgrade. Add to upgrader_process_complete.
 */
function wpc_v2_invalidate_caps_on_upgrade($upgrader_object, $options)
{
    if (!is_array($options) || empty($options['action']) || $options['action'] !== 'update') return;
    if (empty($options['type']) || $options['type'] !== 'plugin') return;
    if (empty($options['plugins']) || !is_array($options['plugins'])) return;
    foreach ($options['plugins'] as $plugin) {
        if (strpos((string) $plugin, 'wp-compress') !== false) {
            delete_site_transient(WPC_V2_CAPS_CACHE_KEY);
            break;
        }
    }
}
add_action('upgrader_process_complete', 'wpc_v2_invalidate_caps_on_upgrade', 10, 2);


function wpc_v2_use_eager_compressed_flip()
{
    $opt = get_site_option('wpc_v2_eager_compressed_flip', false);
    return (bool) apply_filters('wpc_v2_eager_compressed_flip', !empty($opt));
}


if (!function_exists('wpc_get_optimization_mode')) {
    function wpc_get_optimization_mode()
    {


        $settings = get_option(WPS_IC_SETTINGS, []);
        $mode = is_array($settings) && !empty($settings['wpc_optimization_mode'])
            ? (string) $settings['wpc_optimization_mode']
            : (string) get_option('wpc_optimization_mode', 'legacy');
        if ($mode === 'lazy_full' || $mode === 'lazy_smart') {
            $mode = 'lazy_cdn';
        }
        $valid = ['manual', 'legacy', 'lazy_cdn'];
        if (!in_array($mode, $valid, true)) {
            $mode = 'legacy';
        }
        return (string) apply_filters('wpc_optimization_mode', $mode);
    }
}

/**
 * True when a `lazy_*` mode is active (lazy_full, lazy_smart, lazy_cdn).
 * Used to gate the lazy first-view trigger in modern-delivery.
 * Manual + Legacy modes return FALSE here — neither does lazy first-view encoding.
 */
if (!function_exists('wpc_lazy_mode_active')) {
    function wpc_lazy_mode_active()
    {
        return strpos(wpc_get_optimization_mode(), 'lazy_') === 0;
    }
}

/**
 * True when auto-on-upload should be disabled. Any mode other than 'legacy'
 * means the customer opted out of upload-time encoding (manual = nothing
 * auto; lazy_* = encode on view instead of upload).
 */
if (!function_exists('wpc_auto_encoding_disabled')) {
    function wpc_auto_encoding_disabled()
    {
        if (class_exists('wps_ic_plan') && !wps_ic_plan::allows('on_upload')) {
            return true;
        }
        return wpc_get_optimization_mode() !== 'legacy';
    }
}


if (!function_exists('wpc_v2_lazy_cdn_use_original')) {
    function wpc_v2_lazy_cdn_use_original($attachment_id = 0)
    {
        // Per-attachment override (advanced — for hero/hand-edited images).
        if ($attachment_id > 0) {
            $override = get_post_meta($attachment_id, '_wpc_lazy_use_sub_size', true);
            if ($override === 'yes') {
                return (bool) apply_filters('wpc_v2_lazy_cdn_use_original', false, $attachment_id);
            }
        }
        // Global toggle: default ON (best quality).
        $enabled = ((int) get_option('wpc_v2_lazy_cdn_use_original', 1) === 1);
        return (bool) apply_filters('wpc_v2_lazy_cdn_use_original', $enabled, $attachment_id);
    }
}


if (!function_exists('wpc_v2_store_broken_marker_path')) {
    function wpc_v2_store_broken_marker_path()
    {
        $up = wp_get_upload_dir();
        if (empty($up['basedir'])) return '';
        $dir = rtrim($up['basedir'], '/\\') . '/wpc-cache';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir . '/wpc-store-broken.json';
    }

    function wpc_v2_note_store_broken($failed)
    {
        $p = wpc_v2_store_broken_marker_path();
        if ($p === '') return;
        if (!$failed) {
            if (@is_file($p)) @unlink($p);
            return;
        }
        $st = ['n' => 0, 'ts' => 0];
        if (@is_file($p)) {
            $j = json_decode((string) @file_get_contents($p), true);
            if (is_array($j)) $st = array_merge($st, $j);
        }
        $st['n']  = (int) $st['n'] + 1;
        $st['ts'] = time();
        wpc_fs_put($p, wp_json_encode($st), LOCK_EX);
        error_log('[WPC StoreVerify] marker_verify_failed consecutive=' . $st['n']);
    }

    function wpc_v2_is_store_broken_active()
    {
        $p = wpc_v2_store_broken_marker_path();
        if ($p === '' || !@is_file($p)) return false;
        $j = json_decode((string) @file_get_contents($p), true);
        if (!is_array($j)) return false;
        $n  = isset($j['n']) ? (int) $j['n'] : 0;
        $ts = isset($j['ts']) ? (int) $j['ts'] : 0;
        if (!($n >= 3 && (time() - $ts) < 12 * HOUR_IN_SECONDS)) {
            return false;
        }
        // A stored verdict re-evaluates itself: while the file says broken, a live write/read-back
        // on the SAME store (postmeta, cache dropped between write and read) runs at most once per
        // 10 minutes, and a pass clears the file. Reinstall and Safe Mode never touched the file
        // (madda.org.au), so a host that had been fixed kept the warning and the pause forever.
        if (get_transient('wpc_store_probe51_at')) {
            return true;
        }
        set_transient('wpc_store_probe51_at', 1, 10 * MINUTE_IN_SECONDS);
        if (wpc_v2_probe_meta_readback()) {
            wpc_v2_note_store_broken(false);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('media-store-recovered', '', '', ['n' => $n, 'age' => time() - $ts]);
            }
            return false;
        }
        return true;
    }
    function wpc_v2_probe_meta_readback()
    {
        global $wpdb;
        try {
            $nonce = 'p51-' . (function_exists('wp_generate_password') ? wp_generate_password(12, false) : md5(uniqid('', true)));
            $id = isset($wpdb) ? (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID DESC LIMIT 1") : 0;
            if ($id > 0) {
                update_post_meta($id, '_wpc_store_probe51', $nonce);
                wp_cache_delete($id, 'post_meta');
                $rb = (string) get_post_meta($id, '_wpc_store_probe51', true);
                delete_post_meta($id, '_wpc_store_probe51');
                return $rb === $nonce;
            }
            update_option('wpc_store_probe51', $nonce, false);
            wp_cache_delete('wpc_store_probe51', 'options');
            $rbo = (string) get_option('wpc_store_probe51', '');
            delete_option('wpc_store_probe51');
            return $rbo === $nonce;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_reset_parked_attempts')) {
    function wpc_reset_parked_attempts()
    {
        if (!function_exists('delete_metadata') || !defined('WPC_PLUGIN_VERSION')) {
            return 0;
        }
        if ((string) get_option('wpc_park_reset52_v', '') === (string) WPC_PLUGIN_VERSION) {
            return 0;
        }
        update_option('wpc_park_reset52_v', WPC_PLUGIN_VERSION, false);
        $n = 0;
        global $wpdb;
        if (isset($wpdb)) {
            $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'ic_v2_attempts'");
        }
        delete_metadata('post', 0, 'ic_v2_attempts', '', true);
        $p = function_exists('wpc_v2_parked_list_path') ? wpc_v2_parked_list_path() : '';
        if ($p !== '' && @is_file($p)) {
            @unlink($p);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('media-park-reset', '', '', ['attempts_cleared' => $n]);
        }
        return $n;
    }
}
if (!function_exists('wpc_v2_parked_list_path')) {
    function wpc_v2_parked_list_path()
    {
        $up = wp_get_upload_dir();
        if (empty($up['basedir'])) return '';
        $dir = rtrim($up['basedir'], '/\\') . '/wpc-cache';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir . '/wpc-parked-images.json';
    }

    function wpc_v2_parked_list()
    {
        $p = wpc_v2_parked_list_path();
        if ($p === '' || !@is_file($p)) return [];
        $j = json_decode((string) @file_get_contents($p), true);
        return is_array($j) ? array_map('intval', array_values($j)) : [];
    }

    function wpc_v2_set_parked($id, $add)
    {
        $p = wpc_v2_parked_list_path();
        if ($p === '') return;
        $list = wpc_v2_parked_list();
        $id   = (int) $id;
        if ($add) {
            if (!in_array($id, $list, true)) $list[] = $id;
            if (count($list) > 200) $list = array_slice($list, -200);
        } else {
            $list = array_values(array_diff($list, [$id]));
        }
        if (empty($list)) {
            if (@is_file($p)) @unlink($p);
            return;
        }
        wpc_fs_put($p, wp_json_encode($list), LOCK_EX);
    }
}

if (!function_exists('wpc_v2_admit_optimize_attempt')) {
    function wpc_v2_admit_optimize_attempt($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        // v7.21.349 — STALE-INFLIGHT SELF-HEAL at the shared admission seam (eleven-ecu:
        // a row can sit "Optimizing..." forever when its in-flight meta outlives every
        // realistic pull window; the queue flows around it but every UI reads the meta).
        // Older than the grace? The flight is dead: mark failed so the badge recovers
        // and the image re-enters the queue as a normal candidate.
        $compressing_meta = get_post_meta($attachment_id, 'ic_compressing', true);
        if (is_array($compressing_meta) && !empty($compressing_meta['status'])
            && ($compressing_meta['status'] === 'optimizing' || $compressing_meta['status'] === 'queueing')
            && !empty($compressing_meta['time'])
            && (time() - (int) $compressing_meta['time']) > (int) apply_filters('wpc_v2_inflight_stale_secs', 7200)) {
            if (function_exists('wpc_v2_ic_compressing_set_status')) {
                wpc_v2_ic_compressing_set_status($attachment_id, 'failed');
            } else {
                update_post_meta($attachment_id, 'ic_compressing', ['status' => 'failed', 'time' => time()]);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('media-stale-inflight-clear', (string) $attachment_id, '', ['age' => time() - (int) $compressing_meta['time']]);
            }
        }
        $a = get_post_meta($attachment_id, 'ic_v2_attempts', true);
        $n    = (is_array($a) && isset($a['n']))    ? (int) $a['n']    : 0;
        $last = (is_array($a) && isset($a['last'])) ? (int) $a['last'] : 0;
        if ($n <= 0) return true;
        if ((time() - $last) > 7 * DAY_IN_SECONDS) {
            delete_post_meta($attachment_id, 'ic_v2_attempts');
            wpc_v2_set_parked($attachment_id, false);
            return true;
        }
        $cap = (int) apply_filters('wpc_v2_attempt_cap', 4);
        if ($n >= $cap) {
            wpc_v2_set_parked($attachment_id, true);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('media-admit', (string) $attachment_id, '', ['v' => 'parked_attempt_cap', 'n' => $n]);
            }
            return 'parked_attempt_cap';
        }
        $spacing = apply_filters('wpc_v2_attempt_spacing', [600, 1800, 7200]);
        $wait    = isset($spacing[$n - 1]) ? (int) $spacing[$n - 1] : 7200;
        if ((time() - $last) < $wait) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('media-admit', (string) $attachment_id, '', ['v' => 'backoff_wait', 'n' => $n, 'wait_left' => $wait - (time() - $last)]);
            }
            return 'backoff_wait';
        }
        return true;
    }

    function wpc_v2_bump_attempts($attachment_id, $reason)
    {
        $attachment_id = (int) $attachment_id;
        $a = get_post_meta($attachment_id, 'ic_v2_attempts', true);
        $n = (is_array($a) && isset($a['n'])) ? (int) $a['n'] : 0;
        $n++;
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('media-attempt', (string) $attachment_id, '', ['n' => $n, 'why' => (string) $reason]);
        }
        update_post_meta($attachment_id, 'ic_v2_attempts', ['n' => $n, 'last' => time(), 'reason' => (string) $reason]);
        wp_cache_delete($attachment_id, 'post_meta');
        $rb = get_post_meta($attachment_id, 'ic_v2_attempts', true);
        if (!is_array($rb) || (int) ($rb['n'] ?? 0) !== $n) {
            wpc_v2_note_store_broken(true);
        }
        return $n;
    }

    function wpc_v2_reset_attempts($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        delete_post_meta($attachment_id, 'ic_v2_attempts');
        wpc_v2_set_parked($attachment_id, false);
    }
}

if (!function_exists('wpc_v2_render_landing_admin_notice')) {
    function wpc_v2_render_landing_admin_notice()
    {
        if (!function_exists('wpc_cache_first_log')) return;
        $hold = function_exists('wpc_is_admin_lane_held') && function_exists('wpc_hold_admin_lane');
        if (function_exists('wpc_v2_is_store_broken_active') && wpc_v2_is_store_broken_active()) {
            if (!$hold || !wpc_is_admin_lane_held('wpc_media_store_paused80')) {
                if ($hold) wpc_hold_admin_lane('wpc_media_store_paused80', DAY_IN_SECONDS);
                wpc_cache_first_log('media-store-paused', '', '', ['v' => 'markers_not_persisting']);
            }
        }
        $parked = function_exists('wpc_v2_parked_list') ? wpc_v2_parked_list() : [];
        if (!empty($parked) && (!$hold || !wpc_is_admin_lane_held('wpc_media_parked80'))) {
            if ($hold) wpc_hold_admin_lane('wpc_media_parked80', DAY_IN_SECONDS);
            wpc_cache_first_log('media-parked', '', '', ['n' => count($parked), 'ids' => implode(',', array_slice($parked, 0, 5))]);
        }
    }
    add_action('admin_init', 'wpc_v2_render_landing_admin_notice', 20);
}

if (!function_exists('wpc_lazy_trigger_v2')) {

    function wpc_lazy_trigger_v2($attachment_id, array $needed_widths = [], $upgrade_partial_lazy = false, array $trigger_opts = [])
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return false;

        if (function_exists('wpc_v2_is_store_broken_active') && wpc_v2_is_store_broken_active()) {
            return false;
        }
        // The dispatch door answers whether the image may go (attempt back-off, lock, parked,
        // journal, a pending job, a restore); asked here too so a refused image fires no loopback.
        $refused_by = wps_ic_image_optimize::refusal($attachment_id, 'lazy');
        if ($refused_by !== null) {
            error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' refused (' . $refused_by . ')');
            return false;
        }


        $variants = get_post_meta($attachment_id, 'ic_local_variants', true);
        if (is_array($variants) && !empty($variants)) {


            if (!$upgrade_partial_lazy) {
                error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' bailed Gate 1 (variants exist count=' . count($variants) . ')');
                return false;
            }
            // Mark for the drain's race-protection re-check (separate request) so it also
            // admits this upgrade instead of skipping on "variants present".
            set_transient('wpc_lazy_v2_full_' . $attachment_id, 1, 600);
            error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' UPGRADE admit (variants=' . count($variants) . ') — full compress queued');
        }


        if (get_transient('wpc_lazy_v2_failbo_' . $attachment_id)) {
            return false;
        }
        $lock_key = 'wpc_lazy_v2_trigger_' . $attachment_id;
        if (get_transient($lock_key)) {
            $compressing = get_post_meta($attachment_id, 'ic_compressing', true);
            if (!is_array($compressing) || empty($compressing['status'])) {
                delete_transient($lock_key);
                error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' cleared stale Gate 2 lock (no ic_compressing — orphaned)');

            } else {
                error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' bailed Gate 2 (lock held, ic_compressing=' . (string) $compressing['status'] . ')');
                return false;
            }
        }
        set_transient($lock_key, time(), 600);

        // ic_compressing is written by the dispatch when the request goes out. Observed: the
        // trigger's own 'optimizing' write made the door's pending-job gate refuse the drain
        // this trigger fires, and a loopback that never connected left the badge spinning
        // until the 2-hour stale self-heal.
        set_transient('wps_ic_compress_' . $attachment_id, [
            'imageID' => $attachment_id,
            'status'  => 'compressing',
            'time'    => time(),
        ], 300);


        $widths_clean = [];
        foreach ($needed_widths as $w) {
            $w = (int) $w;
            if ($w > 0) $widths_clean[] = $w;
        }
        if (!empty($widths_clean)) {
            $widths_clean = array_values(array_unique($widths_clean));
            set_transient('wpc_lazy_v2_widths_' . $attachment_id, $widths_clean, 600);
        } else {
            // Clear any stale per-image widths if this trigger doesn't have any
            // (avoid a previous trigger's widths leaking into a new lazy run).
            delete_transient('wpc_lazy_v2_widths_' . $attachment_id);
        }

        // The attempt is counted by the dispatch door when the drain sends the request.
        $trigger_ctx = [
            'reason' => isset($trigger_opts['reason']) && $trigger_opts['reason'] !== '' ? (string) $trigger_opts['reason'] : 'new',
        ];
        if (!empty($trigger_opts['formats']) && is_array($trigger_opts['formats'])) {
            $trigger_ctx['formats'] = array_values(array_map('strval', $trigger_opts['formats']));
        }
        set_transient('wpc_lazy_v2_ctx_' . $attachment_id, $trigger_ctx, 600);

        error_log('[WPC LazyV2] queued image=' . $attachment_id . ' mode=' . wpc_get_optimization_mode() . ' smart_widths=' . (empty($widths_clean) ? 'all' : implode(',', $widths_clean)));


        $options  = get_option(WPS_IC_OPTIONS);
        $apikey   = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        $nonce    = substr(hash('sha256', $apikey . '|' . $attachment_id . '|' . floor(time() / 60)), 0, 32);
        $ajax_url = admin_url('admin-ajax.php');


        $lzp = wp_parse_url($ajax_url);
        if (!empty($lzp['host'])) {
            $lz_https = (!empty($lzp['scheme']) && $lzp['scheme'] === 'https');
            $lz_port  = !empty($lzp['port']) ? (int) $lzp['port'] : ($lz_https ? 443 : 80);
            $lz_host  = (string) $lzp['host'];
            $lz_path  = (!empty($lzp['path']) ? $lzp['path'] : '/') . '?action=wpc_lazy_v2_drain';
            $lz_body  = http_build_query(['attachment_id' => $attachment_id, 'nonce' => $nonce]);
            $lz_req   = "POST {$lz_path} HTTP/1.1\r\nHost: {$lz_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($lz_body) . "\r\nConnection: close\r\nUser-Agent: WPCLazyDrain/1.0\r\n\r\n" . $lz_body;

            // (v2-pull-manifest.php + v2-direct-entry.php), which both guard this — defends against a
            // partial-bootstrap context where wps_ic_ajax isn't loaded.
            $lz_fp = (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) ? wps_ic_ajax::wpc_loopback_open_socket($lz_host, $lz_port, $lz_https, 0.2) : false;
            if ($lz_fp) { @stream_set_timeout($lz_fp, 0, 100000); @fwrite($lz_fp, $lz_req); @fclose($lz_fp); }
        }

        return true;
    }
}


if (!function_exists('wpc_v2_variants_all_lazy')) {
    /**
     * TRUE when every ic_local_variants entry is a lazy_cdn ingest (the partial
     * "0J 0W 1A" state: on-demand avif(s) only, no Phase-A jpeg parents). Distinguishes a
     * lazy partial (upgrade-eligible under CDN-off backfill) from a real compress (never touch).
     */
    function wpc_v2_variants_all_lazy($variants)
    {
        if (!is_array($variants) || empty($variants)) return false;
        foreach ($variants as $entry) {
            if (!is_array($entry) || empty($entry['lazy_cdn'])) return false;
        }
        return true;
    }
}

if (!function_exists('wpc_lazy_v2_drain_ajax')) {
    function wpc_lazy_v2_drain_ajax()
    {
        $attachment_id = isset($_POST['attachment_id']) ? (int) $_POST['attachment_id'] : 0;
        $nonce         = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
        if ($attachment_id <= 0 || $nonce === '') {
            wp_die('invalid', 400);
        }


        $options = get_option(WPS_IC_OPTIONS);
        $apikey  = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        $now_min = floor(time() / 60);
        $valid = false;
        foreach ([$now_min, $now_min - 1] as $bucket) {
            $expected = substr(hash('sha256', $apikey . '|' . $attachment_id . '|' . $bucket), 0, 32);
            if (hash_equals($expected, $nonce)) { $valid = true; break; }
        }
        if (!$valid) {
            wp_die('bad nonce', 403);
        }


        $existing = get_post_meta($attachment_id, 'ic_local_variants', true);
        if (is_array($existing) && !empty($existing)) {
            $wpc_full_flag = get_transient('wpc_lazy_v2_full_' . $attachment_id);


            if ($wpc_full_flag) {
                delete_transient('wpc_lazy_v2_full_' . $attachment_id);
                error_log('[WPC LazyV2 drain] image=' . $attachment_id . ' UPGRADE admitted (variants=' . count($existing) . ')');
            } else {
                error_log('[WPC LazyV2 drain] image=' . $attachment_id . ' skipped — variants already present');
                wp_die('ok', 200);
            }
        }

        @ignore_user_abort(true);
        @set_time_limit(180);


        if (class_exists('wps_local_compress')) {
            $compress = new wps_local_compress();
            if (method_exists($compress, 'backup_all_sizes')) {
                $compress->backup_all_sizes($attachment_id);
            }
        }


        $needed_widths = get_transient('wpc_lazy_v2_widths_' . $attachment_id);
        if (is_array($needed_widths) && !empty($needed_widths)) {
            delete_transient('wpc_lazy_v2_widths_' . $attachment_id);
            $option_overrides = ['needed_widths' => array_values(array_map('intval', $needed_widths))];
        } else {
            $option_overrides = [];
        }
        $option_overrides = wpc_lazy_v2_take_trigger_ctx($attachment_id, $option_overrides);

        $t_start = microtime(true);
        $result  = wps_ic_image_optimize::dispatch($attachment_id, 'lazy', $option_overrides);
        $wall_ms = (int) round((microtime(true) - $t_start) * 1000);
        error_log(sprintf(
            '[WPC LazyV2 drain] image=%d result=%s wall_ms=%d %s',
            $attachment_id,
            !empty($result['ok']) ? 'SUCCESS' : 'FAILED',
            $wall_ms,
            !empty($result['error']) ? 'error=' . $result['error'] : ''
        ));


        if (empty($result['ok'])) {
            if (!empty($result['refused'])) {
                // A gate refused it (another dispatch owns the image, or its back-off holds):
                // nothing was sent, so the state belongs to whoever holds the image.
                error_log('[WPC LazyV2 drain] image=' . $attachment_id . ' refused: ' . $result['reason'] . ' — preserving state');
            } else {
                delete_transient('wpc_lazy_v2_trigger_' . $attachment_id);
                delete_post_meta($attachment_id, 'ic_compressing');
                delete_transient('wps_ic_compress_' . $attachment_id);


                set_transient('wpc_lazy_v2_failbo_' . $attachment_id, 1, 6 * HOUR_IN_SECONDS);
            }
        }

        wp_die('done', 200);
    }
}


add_action('wp_ajax_wpc_lazy_v2_drain',        'wpc_lazy_v2_drain_ajax');
add_action('wp_ajax_nopriv_wpc_lazy_v2_drain', 'wpc_lazy_v2_drain_ajax');

/**
 * Cron handler for the v2 lazy trigger. Calls the same self-contained v2
 * optimize path the manual Compress button uses (wps_ic_image_optimize::dispatch).
 * The result is returned synchronously to the cron worker — Phase A's parents
 * write to disk, Phase B callbacks land asynchronously via /wpc/v2/bg_swap.
 */
if (!function_exists('wpc_lazy_v2_take_trigger_ctx')) {
    /** The trigger's reason and formats, handed to the drain in a transient; read once. */
    function wpc_lazy_v2_take_trigger_ctx($attachment_id, array $option_overrides)
    {
        $ctx = get_transient('wpc_lazy_v2_ctx_' . $attachment_id);
        if (!is_array($ctx)) {
            return $option_overrides;
        }
        delete_transient('wpc_lazy_v2_ctx_' . $attachment_id);
        if (!empty($ctx['formats']) && is_array($ctx['formats'])) {
            $option_overrides['formats'] = array_values(array_map('strval', $ctx['formats']));
        }
        if (!empty($ctx['reason'])) {
            $option_overrides['resubmit_reason'] = (string) $ctx['reason'];
        }
        return $option_overrides;
    }
}

if (!function_exists('wpc_lazy_v2_compress_handler')) {
    function wpc_lazy_v2_compress_handler($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return;

        // Sanity re-check: variants may have landed via another path in the
        // ~1s between trigger queue and cron fire.
        $variants = get_post_meta($attachment_id, 'ic_local_variants', true);
        if (is_array($variants) && !empty($variants)) {
            error_log('[WPC LazyV2] skipped image=' . $attachment_id . ' — variants now present');
            delete_transient('wpc_lazy_v2_trigger_' . $attachment_id);
            return;
        }

        if (class_exists('wps_local_compress')) {
            $compress = new wps_local_compress();
            if (method_exists($compress, 'backup_all_sizes')) {
                $compress->backup_all_sizes($attachment_id);
            }
        }

        // Phase 2 smart-lazy: pick up cron-context needed widths too.
        $needed_widths = get_transient('wpc_lazy_v2_widths_' . $attachment_id);
        if (is_array($needed_widths) && !empty($needed_widths)) {
            delete_transient('wpc_lazy_v2_widths_' . $attachment_id);
            $option_overrides = ['needed_widths' => array_values(array_map('intval', $needed_widths))];
        } else {
            $option_overrides = [];
        }
        $option_overrides = wpc_lazy_v2_take_trigger_ctx($attachment_id, $option_overrides);

        $t_start = microtime(true);
        $result  = wps_ic_image_optimize::dispatch($attachment_id, 'lazy', $option_overrides);
        $wall_ms = (int) round((microtime(true) - $t_start) * 1000);

        error_log(sprintf(
            '[WPC LazyV2] image=%d result=%s wall_ms=%d %s',
            $attachment_id,
            !empty($result['ok']) ? 'SUCCESS' : 'FAILED',
            $wall_ms,
            !empty($result['error']) ? 'error=' . $result['error'] : ''
        ));


        if (empty($result['ok'])) {
            if (!empty($result['refused'])) {
                error_log('[WPC LazyV2] image=' . $attachment_id . ' refused: ' . $result['reason']);
            } else {
                delete_transient('wpc_lazy_v2_trigger_' . $attachment_id);
            }
        }
    }
}
add_action('wpc_lazy_v2_compress', 'wpc_lazy_v2_compress_handler', 10, 1);


function wpc_v2_probe_orchestrator_clock()
{
    $orchestrator_url = wpc_v2_orchestrator_url();
    if ($orchestrator_url === '') {
        return ['ok' => false, 'skew_s' => 0.0, 'reason' => 'no_orchestrator_url'];
    }

    $r = wp_remote_get(rtrim($orchestrator_url, '/') . '/clock', [
        'timeout'   => 5,
        'sslverify' => false,
    ]);
    if (is_wp_error($r)) {
        return ['ok' => false, 'skew_s' => 0.0, 'reason' => 'transport:' . $r->get_error_message()];
    }
    if ((int) wp_remote_retrieve_response_code($r) !== 200) {
        return ['ok' => false, 'skew_s' => 0.0, 'reason' => 'http_' . wp_remote_retrieve_response_code($r)];
    }

    $body = json_decode(wp_remote_retrieve_body($r), true);
    if (!is_array($body) || empty($body['unix_ms'])) {
        return ['ok' => false, 'skew_s' => 0.0, 'reason' => 'malformed_clock_response'];
    }

    $skew_s = abs(((float) $body['unix_ms'] / 1000.0) - (float) time());
    return ['ok' => true, 'skew_s' => $skew_s, 'reason' => ''];
}

/**
 * Daily cron — surface excessive clock skew. >30s warns (HMAC may flake under
 * load); >60s errors (callbacks WILL 401). Logs to debug.log only; admin
 * notice is a future-session deliverable.
 */
function wpc_v2_clock_check_cron()
{
    $result = wpc_v2_probe_orchestrator_clock();
    if (!$result['ok']) {
        error_log('[WPC V2Clock] probe failed reason=' . $result['reason']);
        return;
    }
    $skew = (float) $result['skew_s'];
    if ($skew > 60) {
        error_log(sprintf('[WPC V2Clock ERROR] skew=%.1fs exceeds 60s HMAC window — callbacks WILL be rejected', $skew));
    } elseif ($skew > 30) {
        error_log(sprintf('[WPC V2Clock WARN] skew=%.1fs approaching 60s HMAC window', $skew));
    }
    // Cache last good probe for diagnostics endpoint.
    set_site_transient('wpc_v2_clock_last', [
        'skew_s'  => $skew,
        'checked' => time(),
    ], DAY_IN_SECONDS * 2);
}
add_action('wpc_v2_clock_check', 'wpc_v2_clock_check_cron');

/**
 * Schedule the daily cron if not already armed. Hooks `init` so it lands on
 * any admin request and self-heals if the cron was cleared.
 */
function wpc_v2_clock_check_schedule()
{
    if (!wp_next_scheduled('wpc_v2_clock_check')) {
        wp_schedule_event(time() + 60, 'daily', 'wpc_v2_clock_check');
    }
}
add_action('init', 'wpc_v2_clock_check_schedule');


/**
 * Stamp the last write of an image's set or status (`wpc_v2_last_meta_write_at`, at most every
 * 500 ms): Stop waits for it to go quiet before it answers the library counts.
 */
function wpc_v2_invalidate_splash_count($meta_id, $object_id, $meta_key)
{
    if ($meta_key !== 'ic_local_variants' && $meta_key !== 'ic_status') return;

    $now_ms = (int) (microtime(true) * 1000);
    $last_write_ms = (int) get_option('wpc_v2_last_meta_write_at', 0);
    if (($now_ms - $last_write_ms) >= 500) {
        update_option('wpc_v2_last_meta_write_at', $now_ms, false);
    }
}
add_action('updated_post_meta', 'wpc_v2_invalidate_splash_count', 10, 3);
add_action('added_post_meta',   'wpc_v2_invalidate_splash_count', 10, 3);


function wpc_v2_formats_consumer_enabled()
{
    $override = apply_filters('wpc_v2_formats_consumer_enabled', null);
    if ($override !== null) return (bool) $override;
    return (bool) get_option('wpc_v2_formats_consumer_enabled', 0);
}


function wpc_v2_predictor_consumer_enabled()
{
    $override = apply_filters('wpc_v2_predictor_consumer_enabled', null);
    if ($override !== null) return (bool) $override;
    return (bool) get_option('wpc_v2_predictor_consumer_enabled', 0);
}


function wpc_v2_aimd_tuned_enabled()
{
    $override = apply_filters('wpc_v2_aimd_tuned_enabled', null);
    if ($override !== null) return (bool) $override;
    return (bool) get_option('wpc_v2_aimd_tuned_enabled', 0);
}


function wpc_v2_head_poll_enabled()
{
    $override = apply_filters('wpc_v2_head_poll_enabled', null);
    if ($override !== null) return (bool) $override;
    return (bool) get_option('wpc_v2_head_poll_enabled', 0);
}

}

// v7.10.644 — ONE APIKEY PER SITE (service: staging AND thepttv each carry two keys;
// artifacts split across them and neither half is complete). The getter's fallback
// chain MASKED store divergence instead of healing it: wps_ic, wps_ic_options and
// wps_ic_settings can each hold a different api_key, and different subsystems read
// different stores. Canonical is wps_ic; the janitor rewrites the other two to match
// and journals every heal. Runs on the daily sweep + once per version bump.
if (!function_exists('wpc_canonicalize_apikey')) {
    function wpc_canonicalize_apikey()
    {
        try {
            if (!apply_filters('wpc_apikey_canonicalize', true)) {
                return;
            }
            $canonical_options = get_option('wps_ic');
            $canonical_key = (is_array($canonical_options) && !empty($canonical_options['api_key'])) ? (string) $canonical_options['api_key'] : '';
            if ($canonical_key === '') {
                return; // no canonical key — never invent one
            }
            $healed_fingerprints = [];
            foreach (['wps_ic_options', 'wps_ic_settings'] as $option_name) {
                $option_value = get_option($option_name);
                if (is_array($option_value) && isset($option_value['api_key'])
                    && $option_value['api_key'] !== '' && $option_value['api_key'] !== $canonical_key) {
                    $healed_fingerprints[$option_name] = substr(md5((string) $option_value['api_key']), 0, 8);
                    $option_value['api_key'] = $canonical_key;
                    update_option($option_name, $option_value, false);
                }
            }
            if (!empty($healed_fingerprints) && function_exists('wpc_cache_first_log')) {
                // v7.10.646 — BOTH fingerprints (service wave-one guard): a heal onto a
                // key with no dispatch history orphans the site's artifacts; the join
                // must distinguish "migrated identity" from "layering hurt it".
                $healed_fingerprints['canon'] = substr(md5($canonical_key), 0, 8);
                wpc_cache_first_log('apikey-healed', '', '', $healed_fingerprints);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_autopurge_sweep', 'wpc_canonicalize_apikey', 5);
}

// v7.10.650 — DEDICATED CALLBACK SECRET (CVE-2026-18518 structural follow-up).
// The api_key identifies the site to the CDN and travels widely — dashboards, support
// tickets, config payloads, and wp_options (readable by any other plugin on the site, or
// by a SQL-injection in one). Using it as the HMAC secret meant any disclosure of that
// identifier authorized writing files to disk. This secret does ONE job, is never
// rendered, never leaves over an unauthenticated channel, and is cheap to rotate.
if (!function_exists('wpc_v2_callback_secret')) {
    function wpc_v2_callback_secret($create = true)
    {
        $s = (string) get_option('wpc_cb_secret650', '');
        if ($s === '' && $create) {
            try {
                $s = function_exists('random_bytes') ? bin2hex(random_bytes(32)) : '';
            } catch (\Throwable $e) {
                $s = '';
            }
            if ($s === '' && function_exists('wp_generate_password')) {
                $s = hash('sha256', wp_generate_password(64, true, true) . microtime(true));
            }
            if ($s !== '') {
                update_option('wpc_cb_secret650', $s, false);
                delete_option('wpc_cb_seen650');
                delete_option('wpc_cb_strict650');
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('cb-secret-minted', '', '', ['fp' => substr(hash('sha256', $s), 0, 8)]);
                }
            }
        }
        return $s;
    }

    /**
     * Strict = the api_key is no longer accepted as a signing secret.
     * Flipped by OBSERVED EFFECT, never by a flag day: a site hardens only once it has
     * seen the orchestrator sign successfully with the dedicated secret, so an
     * unmigrated site keeps working and a migrated one stops accepting the old key
     * without anyone scheduling a cutover.
     */
    /**
     * v7.10.652 — HARDENING NEEDS TWO INDEPENDENT SIGNALS, and the sender's is the
     * authoritative one. The orchestrator showed that observation alone is unsafe: THREE
     * services sign bg_swap (orchestrator, jpgwebp pod, avif pod) and the pods learn their
     * credential from the job envelope, not from /v2/config. Three good orchestrator
     * callbacks would have hardened a site and then permanently rejected every pod
     * callback — most of the delivery volume — with no way back.
     *
     * Only the sender knows when ALL of its services are migrated, so the sender declares
     * it: the orchestrator echoes cb_enforce=1 on the config-sync response. The plugin
     * still refuses to harden until it has ALSO observed the dedicated secret working on a
     * write route, so a mis-set flag cannot brick callbacks. Both signals, or no hardening.
     */
    function wpc_v2_is_callback_auth_strict()
    {
        if (!apply_filters('wpc_v2_hmac_allow_apikey_fallback', true)) {
            return true;
        }
        return get_option('wpc_cb_strict650') === '1';
    }

    function wpc_v2_maybe_harden_callback_auth()
    {
        if (get_option('wpc_cb_strict650') === '1') {
            return;
        }
        // Signal 1: the sender declares every one of its services migrated.
        if (get_option('wpc_cb_enforce652') !== '1') {
            return;
        }
        // Signal 2: this site has actually seen the dedicated secret verify a WRITE.
        $n = (int) get_option('wpc_cb_seen650', 0);
        if ($n < (int) apply_filters('wpc_v2_hmac_strict_after', 3)) {
            return;
        }
        update_option('wpc_cb_strict650', '1', false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('cb-secret-strict', '', '', ['after' => $n, 'declared' => 1]);
        }
    }

    function wpc_v2_note_callback_secret_use()
    {
        if (get_option('wpc_cb_strict650') === '1') {
            return;
        }
        update_option('wpc_cb_seen650', (int) get_option('wpc_cb_seen650', 0) + 1, false);
        wpc_v2_maybe_harden_callback_auth();
    }
}
