<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_use_v2_protocol')) {


if (!defined('WPC_V2_CAPS_CACHE_KEY'))     define('WPC_V2_CAPS_CACHE_KEY',     'wpc_v2_capabilities');
if (!defined('WPC_V2_CAPS_TTL'))           define('WPC_V2_CAPS_TTL',           86400);   // 24h
if (!defined('WPC_V2_CANARY_OPTION_KEY'))  define('WPC_V2_CANARY_OPTION_KEY',  'wpc_v2_canary_pct');
if (!defined('WPC_V2_CANARY_DEFAULT_PCT')) define('WPC_V2_CANARY_DEFAULT_PCT', 0);       // 0 until staged rollout begins


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
 * Authoritative gate. Returns true iff the calling code should use the v2
 * protocol for outbound POSTs + accept callbacks at /wpc/v2/bg_swap.
 */
function wpc_use_v2_protocol()
{
    static $cached = null;
    if ($cached !== null) return $cached;


    $mode = get_option('wpc_protocol_version', 'v2');

    if ($mode === 'v1' || $mode === 'shadow') {
        $cached = false;
        return $cached;
    }
    if ($mode === 'v2') {
        $cached = true;
        return $cached;
    }

    $caps = wpc_probe_orchestrator_capabilities();
    if (empty($caps['v2_optimize'])) {
        $cached = false;
        return $cached;
    }
    $cached = wpc_v2_canary_cohort_active();
    return $cached;
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

        'status_poll_supported'    => !empty($body['status_poll_supported']),
        'source_cache_enabled'     => !empty($body['source_cache_enabled']),
        'signed_urls_supported'    => !empty($body['signed_urls_supported']),
        'redeliver_supported'      => !empty($body['redeliver_supported']),
        'probed_at'                => time(),
        'probe_source'             => 'live',
    ];

    set_site_transient(WPC_V2_CAPS_CACHE_KEY, $caps, WPC_V2_CAPS_TTL);
    return $caps;
}


function wpc_v2_canary_cohort_active()
{
    $apikey = wpc_v2_get_apikey();
    if ($apikey === '') return false;

    $canary_pct = (int) get_option(WPC_V2_CANARY_OPTION_KEY, WPC_V2_CANARY_DEFAULT_PCT);
    if ($canary_pct <= 0) return false;
    if ($canary_pct >= 100) return true;

    return (crc32($apikey) % 100) < $canary_pct;
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
 * Safe fallback when probe fails AND no prior cache exists. Marks v2 as
 * unavailable so wpc_use_v2_protocol() falls back to v1.
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
        $valid = ['manual', 'legacy', 'lazy_full', 'lazy_smart', 'lazy_cdn'];
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


if (!function_exists('wpc_lazy_trigger_v2')) {

    function wpc_lazy_trigger_v2($attachment_id, array $needed_widths = [], $upgrade_partial_lazy = false)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return false;


        if (get_transient('wpc_restoring_' . $attachment_id)) {
            error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' bailed Gate 0 (restore in flight)');
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


        update_post_meta($attachment_id, 'ic_compressing', [
            'status' => 'optimizing',
            'time'   => time(),
            'source' => 'lazy_v2',
        ]);
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

        if (!class_exists('wps_ic_ajax') || !method_exists('wps_ic_ajax', 'run_v2_optimize')) {
            error_log('[WPC LazyV2 drain] image=' . $attachment_id . ' run_v2_optimize unavailable');
            delete_transient('wpc_lazy_v2_trigger_' . $attachment_id);
            wp_die('handler unavailable', 500);
        }


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

        $t_start = microtime(true);
        $result  = wps_ic_ajax::run_v2_optimize($attachment_id, $option_overrides);
        $wall_ms = (int) round((microtime(true) - $t_start) * 1000);
        error_log(sprintf(
            '[WPC LazyV2 drain] image=%d result=%s wall_ms=%d %s',
            $attachment_id,
            !empty($result['ok']) ? 'SUCCESS' : 'FAILED',
            $wall_ms,
            !empty($result['error']) ? 'error=' . $result['error'] : ''
        ));


        if (empty($result['ok'])) {
            if (!empty($result['error']) && $result['error'] === 'already_in_flight') {
                error_log('[WPC LazyV2 drain] image=' . $attachment_id . ' bailed: already_in_flight — preserving state for in-flight run');
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
 * optimize path the manual Compress button uses (wps_ic_ajax::run_v2_optimize).
 * The result is returned synchronously to the cron worker — Phase A's parents
 * write to disk, Phase B callbacks land asynchronously via /wpc/v2/bg_swap.
 */
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

        if (!class_exists('wps_ic_ajax') || !method_exists('wps_ic_ajax', 'run_v2_optimize')) {
            error_log('[WPC LazyV2] failed image=' . $attachment_id . ' — run_v2_optimize unavailable');
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

        $t_start = microtime(true);
        $result  = wps_ic_ajax::run_v2_optimize($attachment_id, $option_overrides);
        $wall_ms = (int) round((microtime(true) - $t_start) * 1000);

        error_log(sprintf(
            '[WPC LazyV2] image=%d result=%s wall_ms=%d %s',
            $attachment_id,
            !empty($result['ok']) ? 'SUCCESS' : 'FAILED',
            $wall_ms,
            !empty($result['error']) ? 'error=' . $result['error'] : ''
        ));


        if (empty($result['ok'])) {
            if (!empty($result['error']) && $result['error'] === 'already_in_flight') {
                error_log('[WPC LazyV2] image=' . $attachment_id . ' bailed: already_in_flight');
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


function wpc_v2_invalidate_splash_count($meta_id, $object_id, $meta_key)
{
    if ($meta_key !== 'ic_local_variants' && $meta_key !== 'ic_status') return;


    $now_ms = (int) (microtime(true) * 1000);

    $last_bust_ms = (int) get_option('wpc_v2_last_splash_bust_at', 0);
    if (($now_ms - $last_bust_ms) >= 2000) {
        delete_transient('wpc_bulk_library_counts');
        update_option('wpc_v2_last_splash_bust_at', $now_ms, false);
    }

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
if (!function_exists('wpc_apikey_canonicalize644')) {
    function wpc_apikey_canonicalize644()
    {
        try {
            if (!apply_filters('wpc_apikey_canonicalize', true)) {
                return;
            }
            $wpc_canon644 = get_option('wps_ic');
            $wpc_key644 = (is_array($wpc_canon644) && !empty($wpc_canon644['api_key'])) ? (string) $wpc_canon644['api_key'] : '';
            if ($wpc_key644 === '') {
                return; // no canonical key — never invent one
            }
            $wpc_healed644 = [];
            foreach (['wps_ic_options', 'wps_ic_settings'] as $wpc_opt644) {
                $wpc_v644 = get_option($wpc_opt644);
                if (is_array($wpc_v644) && isset($wpc_v644['api_key'])
                    && $wpc_v644['api_key'] !== '' && $wpc_v644['api_key'] !== $wpc_key644) {
                    $wpc_healed644[$wpc_opt644] = substr(md5((string) $wpc_v644['api_key']), 0, 8);
                    $wpc_v644['api_key'] = $wpc_key644;
                    update_option($wpc_opt644, $wpc_v644, false);
                }
            }
            if (!empty($wpc_healed644) && function_exists('wpc_cache_first_log')) {
                // v7.10.646 — BOTH fingerprints (service wave-one guard): a heal onto a
                // key with no dispatch history orphans the site's artifacts; the join
                // must distinguish "migrated identity" from "layering hurt it".
                $wpc_healed644['canon'] = substr(md5($wpc_key644), 0, 8);
                wpc_cache_first_log('apikey-healed', '', '', $wpc_healed644);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_autopurge_sweep', 'wpc_apikey_canonicalize644', 5);
}

// v7.10.650 — DEDICATED CALLBACK SECRET (CVE-2026-18518 structural follow-up).
// The api_key identifies the site to the CDN and travels widely — dashboards, support
// tickets, config payloads, and wp_options (readable by any other plugin on the site, or
// by a SQL-injection in one). Using it as the HMAC secret meant any disclosure of that
// identifier authorized writing files to disk. This secret does ONE job, is never
// rendered, never leaves over an unauthenticated channel, and is cheap to rotate.
if (!function_exists('wpc_v2_callback_secret650')) {
    function wpc_v2_callback_secret650($create = true)
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
    function wpc_v2_callback_strict650()
    {
        if (!apply_filters('wpc_v2_hmac_allow_apikey_fallback', true)) {
            return true;
        }
        return get_option('wpc_cb_strict650') === '1';
    }

    function wpc_v2_callback_maybe_harden652()
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

    function wpc_v2_callback_note_secret_use650()
    {
        if (get_option('wpc_cb_strict650') === '1') {
            return;
        }
        update_option('wpc_cb_seen650', (int) get_option('wpc_cb_seen650', 0) + 1, false);
        wpc_v2_callback_maybe_harden652();
    }
}
