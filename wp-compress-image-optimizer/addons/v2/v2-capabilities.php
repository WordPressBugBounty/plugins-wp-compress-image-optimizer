<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-capabilities.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_use_v2_protocol')) {


if (!defined('WPC_V2_CAPS_CACHE_KEY'))     define('WPC_V2_CAPS_CACHE_KEY',     'wpc_v2_capabilities');
if (!defined('WPC_V2_CAPS_TTL'))           define('WPC_V2_CAPS_TTL',           86400);   
if (!defined('WPC_V2_CANARY_OPTION_KEY'))  define('WPC_V2_CANARY_OPTION_KEY',  'wpc_v2_canary_pct');
if (!defined('WPC_V2_CANARY_DEFAULT_PCT')) define('WPC_V2_CANARY_DEFAULT_PCT', 0);       


if (!function_exists('wpc_v2_get_apikey')) {
    function wpc_v2_get_apikey()
    {
        
        $canon = get_option('wps_ic');
        if (is_array($canon) && !empty($canon['api_key'])) {
            return (string) $canon['api_key'];
        }
        
        $migration = get_option('wps_ic_options');
        if (is_array($migration) && !empty($migration['api_key'])) {
            return (string) $migration['api_key'];
        }
        
        
        $settings = get_option('wps_ic_settings');
        if (is_array($settings) && !empty($settings['api_key'])) {
            return (string) $settings['api_key'];
        }
        return '';
    }
}





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

    
    $override = apply_filters('wpc_v2_orchestrator_url', '');
    if ($override !== '') return rtrim((string) $override, '/');


    $valid_hosts = apply_filters('wpc_v2_orchestrator_valid_hosts', [
        'local-mc.zapwp.net',
    ]);
    $geo = get_option('wps_ic_geo_locate_v2');
    if (is_array($geo) && !empty($geo['server'])) {
        $server = trim((string) $geo['server'], '/');
        
        $host_only = preg_replace('#^https?://#i', '', $server);
        if (in_array($host_only, $valid_hosts, true)) {
            if (preg_match('#^https?://#i', $server)) return $server;
            return 'https://' . $server;
        }


    }


    return 'https://local-mc.zapwp.net';
}





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






if (!function_exists('wpc_lazy_mode_active')) {
    function wpc_lazy_mode_active()
    {
        return strpos(wpc_get_optimization_mode(), 'lazy_') === 0;
    }
}






if (!function_exists('wpc_auto_encoding_disabled')) {
    function wpc_auto_encoding_disabled()
    {
        return wpc_get_optimization_mode() !== 'legacy';
    }
}


if (!function_exists('wpc_v2_lazy_cdn_use_original')) {
    function wpc_v2_lazy_cdn_use_original($attachment_id = 0)
    {
        
        if ($attachment_id > 0) {
            $override = get_post_meta($attachment_id, '_wpc_lazy_use_sub_size', true);
            if ($override === 'yes') {
                return (bool) apply_filters('wpc_v2_lazy_cdn_use_original', false, $attachment_id);
            }
        }
        
        $enabled = ((int) get_option('wpc_v2_lazy_cdn_use_original', 1) === 1);
        return (bool) apply_filters('wpc_v2_lazy_cdn_use_original', $enabled, $attachment_id);
    }
}


if (!function_exists('wpc_v2_store_broken_path197')) {
    function wpc_v2_store_broken_path197()
    {
        $up = wp_get_upload_dir();
        if (empty($up['basedir'])) return '';
        $dir = rtrim($up['basedir'], '/\\') . '/wpc-cache';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir . '/wpc-store-broken.json';
    }

    function wpc_v2_store_broken_note197($failed)
    {
        $p = wpc_v2_store_broken_path197();
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
        @file_put_contents($p, wp_json_encode($st), LOCK_EX);
        error_log('[WPC StoreVerify] marker_verify_failed consecutive=' . $st['n']);
    }

    function wpc_v2_store_broken_active197()
    {
        $p = wpc_v2_store_broken_path197();
        if ($p === '' || !@is_file($p)) return false;
        $j = json_decode((string) @file_get_contents($p), true);
        if (!is_array($j)) return false;
        $n  = isset($j['n']) ? (int) $j['n'] : 0;
        $ts = isset($j['ts']) ? (int) $j['ts'] : 0;
        return ($n >= 3 && (time() - $ts) < 12 * HOUR_IN_SECONDS);
    }
}

if (!function_exists('wpc_v2_parked_path197')) {
    function wpc_v2_parked_path197()
    {
        $up = wp_get_upload_dir();
        if (empty($up['basedir'])) return '';
        $dir = rtrim($up['basedir'], '/\\') . '/wpc-cache';
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        return $dir . '/wpc-parked-images.json';
    }

    function wpc_v2_parked_list197()
    {
        $p = wpc_v2_parked_path197();
        if ($p === '' || !@is_file($p)) return [];
        $j = json_decode((string) @file_get_contents($p), true);
        return is_array($j) ? array_map('intval', array_values($j)) : [];
    }

    function wpc_v2_parked_set197($id, $add)
    {
        $p = wpc_v2_parked_path197();
        if ($p === '') return;
        $list = wpc_v2_parked_list197();
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
        @file_put_contents($p, wp_json_encode($list), LOCK_EX);
    }
}

if (!function_exists('wpc_v2_attempts_admit197')) {
    function wpc_v2_attempts_admit197($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        
        
        
        
        
        $wpc_ic349 = get_post_meta($attachment_id, 'ic_compressing', true);
        if (is_array($wpc_ic349) && !empty($wpc_ic349['status'])
            && ($wpc_ic349['status'] === 'optimizing' || $wpc_ic349['status'] === 'queueing')
            && !empty($wpc_ic349['time'])
            && (time() - (int) $wpc_ic349['time']) > (int) apply_filters('wpc_v2_inflight_stale_secs', 7200)) {
            if (function_exists('wpc_v2_ic_compressing_set_status')) {
                wpc_v2_ic_compressing_set_status($attachment_id, 'failed');
            } else {
                update_post_meta($attachment_id, 'ic_compressing', ['status' => 'failed', 'time' => time()]);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('media-stale-inflight-clear', (string) $attachment_id, '', ['age' => time() - (int) $wpc_ic349['time']]);
            }
        }
        $a = get_post_meta($attachment_id, 'ic_v2_attempts', true);
        $n    = (is_array($a) && isset($a['n']))    ? (int) $a['n']    : 0;
        $last = (is_array($a) && isset($a['last'])) ? (int) $a['last'] : 0;
        if ($n <= 0) return true;
        if ((time() - $last) > 7 * DAY_IN_SECONDS) {
            delete_post_meta($attachment_id, 'ic_v2_attempts');
            wpc_v2_parked_set197($attachment_id, false);
            return true;
        }
        $cap = (int) apply_filters('wpc_v2_attempt_cap', 4);
        if ($n >= $cap) {
            wpc_v2_parked_set197($attachment_id, true);
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

    function wpc_v2_attempts_bump197($attachment_id, $reason)
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
            wpc_v2_store_broken_note197(true);
        }
        return $n;
    }

    function wpc_v2_attempts_reset197($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        delete_post_meta($attachment_id, 'ic_v2_attempts');
        wpc_v2_parked_set197($attachment_id, false);
    }
}

if (!function_exists('wpc_v2_landing_admin_notice197')) {
    function wpc_v2_landing_admin_notice197()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_wpc_settings')) return;
        if (function_exists('wpc_v2_store_broken_active197') && wpc_v2_store_broken_active197()) {
            echo '<div class="notice notice-error"><p><strong>WP Compress:</strong> database writes are not persisting on this host (optimization markers vanish after saving). Image optimization is paused to prevent an encode loop — please contact your host about database/object-cache write failures.</p></div>';
        }
        $parked = function_exists('wpc_v2_parked_list197') ? wpc_v2_parked_list197() : [];
        if (!empty($parked)) {
            $show = array_slice($parked, 0, 5);
            echo '<div class="notice notice-warning"><p><strong>WP Compress:</strong> ' . count($parked) . ' image(s) failed to finish optimizing after 4 attempts and are parked (IDs: ' . esc_html(implode(', ', $show)) . (count($parked) > 5 ? ', …' : '') . '). Re-optimize them from the Media Library once the underlying issue is resolved.</p></div>';
        }
    }
    add_action('admin_notices', 'wpc_v2_landing_admin_notice197');
}

if (!function_exists('wpc_lazy_trigger_v2')) {

    function wpc_lazy_trigger_v2($attachment_id, array $needed_widths = [], $upgrade_partial_lazy = false, array $trigger_opts = [])
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return false;

        if (function_exists('wpc_v2_store_broken_active197') && wpc_v2_store_broken_active197()) {
            return false;
        }
        if (function_exists('wpc_v2_journal_has_image') && wpc_v2_journal_has_image($attachment_id)) {
            error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' bailed Gate 1b (journal pending merge — drain owns it)');
            return false;
        }
        $wpc_adm197 = function_exists('wpc_v2_attempts_admit197') ? wpc_v2_attempts_admit197($attachment_id) : true;
        if ($wpc_adm197 !== true) {
            error_log('[WPC LazyV2 trigger] image=' . $attachment_id . ' bailed Gate 3 (' . $wpc_adm197 . ')');
            return false;
        }


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
            
            
            delete_transient('wpc_lazy_v2_widths_' . $attachment_id);
        }

        $wpc_reason197 = isset($trigger_opts['reason']) && $trigger_opts['reason'] !== '' ? (string) $trigger_opts['reason'] : 'new';
        $wpc_attempt197 = function_exists('wpc_v2_attempts_bump197')
            ? wpc_v2_attempts_bump197($attachment_id, $wpc_reason197)
            : 1;
        $wpc_ctx197 = [
            'reason'  => $wpc_reason197,
            'attempt' => $wpc_attempt197,
        ];
        if (!empty($trigger_opts['formats']) && is_array($trigger_opts['formats'])) {
            $wpc_ctx197['formats'] = array_values(array_map('strval', $trigger_opts['formats']));
        }
        set_transient('wpc_lazy_v2_ctx_' . $attachment_id, $wpc_ctx197, 600);

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

            
            
            $lz_fp = (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) ? wps_ic_ajax::wpc_loopback_open_socket($lz_host, $lz_port, $lz_https, 0.2) : false;
            if ($lz_fp) { @stream_set_timeout($lz_fp, 0, 100000); @fwrite($lz_fp, $lz_req); @fclose($lz_fp); }
        }

        return true;
    }
}


if (!function_exists('wpc_v2_variants_all_lazy')) {
    




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
        $wpc_ctx197d = get_transient('wpc_lazy_v2_ctx_' . $attachment_id);
        if (is_array($wpc_ctx197d)) {
            delete_transient('wpc_lazy_v2_ctx_' . $attachment_id);
            if (!empty($wpc_ctx197d['formats']) && is_array($wpc_ctx197d['formats'])) {
                $option_overrides['formats'] = array_values(array_map('strval', $wpc_ctx197d['formats']));
            }
            if (!empty($wpc_ctx197d['reason']))  $option_overrides['resubmit_reason'] = (string) $wpc_ctx197d['reason'];
            if (!empty($wpc_ctx197d['attempt'])) $option_overrides['attempt']         = (int) $wpc_ctx197d['attempt'];
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







if (!function_exists('wpc_lazy_v2_compress_handler')) {
    function wpc_lazy_v2_compress_handler($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return;

        
        
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

        
        $needed_widths = get_transient('wpc_lazy_v2_widths_' . $attachment_id);
        if (is_array($needed_widths) && !empty($needed_widths)) {
            delete_transient('wpc_lazy_v2_widths_' . $attachment_id);
            $option_overrides = ['needed_widths' => array_values(array_map('intval', $needed_widths))];
        } else {
            $option_overrides = [];
        }
        $wpc_ctx197c = get_transient('wpc_lazy_v2_ctx_' . $attachment_id);
        if (is_array($wpc_ctx197c)) {
            delete_transient('wpc_lazy_v2_ctx_' . $attachment_id);
            if (!empty($wpc_ctx197c['formats']) && is_array($wpc_ctx197c['formats'])) {
                $option_overrides['formats'] = array_values(array_map('strval', $wpc_ctx197c['formats']));
            }
            if (!empty($wpc_ctx197c['reason']))  $option_overrides['resubmit_reason'] = (string) $wpc_ctx197c['reason'];
            if (!empty($wpc_ctx197c['attempt'])) $option_overrides['attempt']         = (int) $wpc_ctx197c['attempt'];
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
    
    set_site_transient('wpc_v2_clock_last', [
        'skew_s'  => $skew,
        'checked' => time(),
    ], DAY_IN_SECONDS * 2);
}
add_action('wpc_v2_clock_check', 'wpc_v2_clock_check_cron');





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
                return; 
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
                
                
                
                $wpc_healed644['canon'] = substr(md5($wpc_key644), 0, 8);
                wpc_cache_first_log('apikey-healed', '', '', $wpc_healed644);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_autopurge_sweep', 'wpc_apikey_canonicalize644', 5);
}







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
        
        if (get_option('wpc_cb_enforce652') !== '1') {
            return;
        }
        
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
