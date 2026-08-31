<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-callback.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_register_callback_route')) {

function wpc_v2_register_callback_route()
{
    register_rest_route('wpc/v2', '/bg_swap', [
        'methods'             => 'POST',
        'callback'            => 'wpc_v2_handle_bg_swap',
        'permission_callback' => '__return_true', 
    ]);


    register_rest_route('wpc/v2', '/bg_swap_single', [
        'methods'             => 'POST',
        'callback'            => 'wpc_v2_handle_bg_swap_single',
        'permission_callback' => '__return_true', 
    ]);


    register_rest_route('wpc/v2', '/healthcheck', [


        'methods'             => ['GET', 'POST'],
        'callback'            => 'wpc_v2_handle_healthcheck',
        'permission_callback' => '__return_true',
    ]);


    register_rest_route('wpc/v2', '/fetch-variant', [
        'methods'             => 'POST',
        'callback'            => 'wpc_v2_handle_fetch_variant',
        'permission_callback' => '__return_true', 
    ]);
}
add_action('rest_api_init', 'wpc_v2_register_callback_route');


if (!function_exists('wpc_v2_dest_ext_ok')) {
    
    
    function wpc_v2_dest_ext_ok($path)
    {
        return (bool) preg_match('/\\.(webp|avif|jpe?g|png|gif)$/i', (string) $path);
    }
}

function wpc_v2_verify_and_unsuppress($reason = '')

{
    if (!class_exists('WPC_Delivery_Resolver') || !method_exists('WPC_Delivery_Resolver', 'pick_real_image_probe')) {
        return ['ok' => false, 'verify_cdn_ok' => false, 'stamped' => false, 'reason' => 'resolver_unavailable'];
    }
    $targets = method_exists('WPC_Delivery_Resolver', 'pick_real_image_probes')
        ? WPC_Delivery_Resolver::pick_real_image_probes(3) : [];
    if (empty($targets)) {
        $one = WPC_Delivery_Resolver::pick_real_image_probe();
        if (is_array($one) && !empty($one['cdn_webp_url'])) { $targets = [$one]; }
    }
    if (empty($targets)) {
        return ['ok' => true, 'verify_cdn_ok' => false, 'stamped' => false, 'reason' => 'no_real_image_to_verify'];
    }

    
    
    
    $budget   = (int) apply_filters('wpc_v2_verify_probe_budget', 4);
    $pass     = null;
    $last     = ['code' => 0, 'fmt' => '', 'ctype' => ''];
    $last_url = '';
    foreach ($targets as $t) {
        $url = isset($t['cdn_webp_url']) ? (string) $t['cdn_webp_url'] : '';
        if ($url === '') continue;
        $last_url = $url;
        foreach ([0, 1] as $bust) {
            if ($budget-- <= 0) break 2;
            $u = $bust
                ? ($url . (strpos($url, '?') === false ? '?' : '&') . 'wpcv=' . substr(md5($url . '|' . time()), 0, 8))
                : $url;
            $p     = WPC_Delivery_Resolver::probe($u, 'image/avif,image/webp,*/*');
            $code  = is_array($p) ? (int) ($p['code'] ?? 0) : 0;
            $fmt   = is_array($p) ? (string) ($p['fmt'] ?? '') : '';
            $ctype = is_array($p) ? (string) ($p['ctype'] ?? '') : '';
            $last  = ['code' => $code, 'fmt' => $fmt, 'ctype' => $ctype];
            if ($code === 200 && ($fmt === 'webp' || $fmt === 'avif')) { $pass = $t; break 2; }
            
            if ($code !== 404 && $code !== 403 && $code !== 502 && $code !== 504) break;
        }
    }
    if ($pass === null) {
        return ['ok' => true, 'verify_cdn_ok' => false, 'stamped' => false, 'reason' => 'edge_not_serving_webp',
                'probe' => $last, 'probe_url' => $last_url];
    }
    $target = $pass;


    if (function_exists('update_option') && function_exists('wpc_v2_env_fingerprint')) {
        update_option('wpc_v2_provisioned_fingerprint', wpc_v2_env_fingerprint(), false);
    }
    if (function_exists('delete_option')) {
        delete_option('wpc_v2_force_provision');
        delete_option('wpc_v2_provheal_tries');
        delete_option('wpc_v2_provheal_last');
    }
    if (method_exists('WPC_Delivery_Resolver', 'resolve')) { WPC_Delivery_Resolver::resolve(true); }
    if (function_exists('error_log')) { error_log('[WPC v2] verify_and_unsuppress(' . (string) $reason . '): real-content 200 webp -> fingerprint stamped -> UN-SUPPRESSED'); }
    return ['ok' => true, 'verify_cdn_ok' => true, 'stamped' => true, 'reason' => 'un_suppressed', 'attachment_id' => (int) ($target['attachment_id'] ?? 0)];
}


function wpc_v2_handle_provisioned_ping(WP_REST_Request $request)
{
    $body_raw = (string) $request->get_body();
    $sig      = (string) $request->get_header('X-WPC-Sig');

    
    
    if ($sig === '' || !function_exists('wpc_v2_verify_hmac') || !wpc_v2_verify_hmac($sig, $body_raw)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'bad_signature'], 403);
    }

    $data = json_decode($body_raw, true);
    if (!is_array($data)) {
        return new WP_REST_Response(['ok' => false, 'error' => 'bad_body'], 400);
    }


    $zone = isset($data['zone_id']) ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $data['zone_id']) : '';
    if ($zone !== '' && array_key_exists('nav', $data) && function_exists('update_option')) {
        update_option('wpc_v2_orch_nav_' . $zone, !empty($data['nav']) ? '1' : '0', false);
    }

    if (empty($data['provisioned'])) {
        return new WP_REST_Response(['ok' => true, 'action' => 'noop', 'reason' => 'provisioned_false'], 200);
    }

    
    if (function_exists('wpc_v2_provision_env_changed') && !wpc_v2_provision_env_changed()) {
        return new WP_REST_Response(['ok' => true, 'action' => 'noop', 'reason' => 'already_provisioned'], 200);
    }

    
    $r = wpc_v2_verify_and_unsuppress('orch_trigger');
    if (($r['reason'] ?? '') === 'resolver_unavailable') {
        return new WP_REST_Response(['ok' => false, 'error' => 'resolver_unavailable'], 200);
    }
    $action = !empty($r['stamped']) ? 'un_suppressed'
            : ((($r['reason'] ?? '') === 'no_real_image_to_verify') ? 'deferred' : 'stay_suppressed');
    return new WP_REST_Response(array_merge(['ok' => true, 'action' => $action], $r), 200);
}

function wpc_v2_handle_healthcheck(WP_REST_Request $request)
{


    if ($request->get_method() === 'POST') {
        return wpc_v2_handle_provisioned_ping($request);
    }


    if (defined('WPC_PLUGIN_VERSION')) {
        $plugin_version = (string) WPC_PLUGIN_VERSION;
    } elseif (class_exists('wps_ic') && isset(wps_ic::$version)) {
        $plugin_version = (string) wps_ic::$version;
    } else {
        $plugin_version = 'unknown';
    }

    


    if (function_exists('get_option')) {
        $hc_force   = (bool) get_option('wpc_v2_force_provision', false);
        $hc_cfc     = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $hc_cfver   = get_option('wpc_cf_cname_verified');
        $hc_cf_pend = ($hc_cfc !== '' && $hc_cfver !== '1' && $hc_cfver !== 1);
        if (($hc_force || $hc_cf_pend) && function_exists('get_transient') && !get_transient('wpc_v2_hc_fire_bk')) {
            if (function_exists('set_transient')) set_transient('wpc_v2_hc_fire_bk', 1, 20);
            if ($hc_force && function_exists('wpc_v2_run_deferred_config_sync')) { wpc_v2_run_deferred_config_sync(); }
            if ($hc_cf_pend && function_exists('wpc_v2_cf_cname_reverify')) { wpc_v2_cf_cname_reverify(false); }
        }
        
        
        
        if (function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed()
            && function_exists('wpc_v2_zone_origin_proved') && !wpc_v2_zone_origin_proved()
            && function_exists('wpc_v2_zone_origin_probe_run')) {
            wpc_v2_zone_origin_probe_run();
        }
    }


    $hc_is_cf = (defined('WPS_IC_CF_CNAME') && function_exists('get_option')) ? (trim((string) get_option(WPS_IC_CF_CNAME, '')) !== '') : false;
    if (!$hc_is_cf && function_exists('get_option') && !get_option('wpc_v2_force_provision', false)
        && class_exists('WPC_Delivery_Resolver') && class_exists('WPC_Negotiated_Delivery')
        && function_exists('get_transient') && !get_transient('wpc_v2_hc_reverify_bk')
        && !WPC_Negotiated_Delivery::is_active()) {
        if (function_exists('set_transient')) set_transient('wpc_v2_hc_reverify_bk', 1, 300);
        WPC_Delivery_Resolver::resolve(true);
    }

    


    
    $supports_lazy_cdn = function_exists('wpc_v2_handle_bg_swap_single')
        && function_exists('wpc_v2_invalidate_splash_count');


    $supports_fetch_variant = function_exists('wpc_v2_handle_fetch_variant');


    $tier_name = ''; $emission_mode = 'auto';
    if (class_exists('WPC_Delivery_Resolver')) {
        $rv_hc = WPC_Delivery_Resolver::resolve_verbose();
        $tier_name     = isset($rv_hc['tier_name']) ? (string) $rv_hc['tier_name'] : '';
        $emission_mode = isset($rv_hc['override'])  ? (string) $rv_hc['override']  : 'auto';
    }
    $images_on_hc = !class_exists('WPC_Negotiated_Delivery')
        || WPC_Negotiated_Delivery::cdn_images_enabled();
    $cf_detected = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']);


    $supports_batch = function_exists('wpc_v2_handle_bg_swap_batch');


    


    $apikey_fp = '';
    if (function_exists('wpc_v2_get_apikey')) {
        $hc_key = (string) wpc_v2_get_apikey();
        if ($hc_key !== '') {
            $apikey_fp = substr(hash('sha256', $hc_key), 0, 12);
        }
    }

    
    
    
    
    
    
    
    
    $hc_full = (function_exists('current_user_can') && current_user_can('manage_options'));
    if (!$hc_full) {
        $hc_sig = (string) $request->get_header('x_wpc_sig');
        if ($hc_sig !== '') {
            $hc_v = wpc_v2_verify_hmac($hc_sig, (string) $request->get_body(), 300, '');
            $hc_full = !empty($hc_v['ok']);
        }
    }
    $hc_full = (bool) apply_filters('wpc_hc_full_anon', $hc_full);

    $hc_arr = [
        'plugin_version'          => $plugin_version,
        'supports_bg_swap_single' => $supports_lazy_cdn,
        'supports_lazy_cdn'       => $supports_lazy_cdn,
        'supports_fetch_variant'  => $supports_fetch_variant,
        'supports_bg_swap_batch'  => $supports_batch,
        'apikey_fp'               => $apikey_fp,


        'pull_enabled'            => function_exists('wpc_v2_pull_enabled') ? (bool) wpc_v2_pull_enabled() : false,
        


        'debug' => [
            
            


            'asset_probe_ran_now' => (static function () {


                if ((string) get_option('wpc_v2_cf_asset_mime_ok', '') === '1') {
                    return false;
                }
                if (get_transient('wpc_v2_cf_asset_mime_retry') !== false) {
                    return false;
                }
                $cf_hc = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']) || get_option('wpc_v2_cf_assets_seen', 0);


                $cf_cname_hc  = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
                $cf_set_hc    = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
                $cf_cdn_on_hc = is_array($cf_set_hc) && !empty($cf_set_hc['settings']['cdn']);
                $zone_hc = ($cf_cname_hc !== '' && $cf_cdn_on_hc)
                    ? $cf_cname_hc
                    : (trim((string) get_option('ic_custom_cname', '')) ?: (string) get_option('ic_cdn_zone_name', ''));
                if (!$cf_hc || $zone_hc === '') {
                    return false;
                }
                $r_hc  = wp_remote_get('https://' . $zone_hc . '/wp-includes/css/dist/block-library/style.min.css', ['timeout' => 3, 'sslverify' => false, 'redirection' => 2, 'limit_response_size' => 8192]);
                $ct_hc = is_wp_error($r_hc) ? '' : strtolower((string) wp_remote_retrieve_header($r_hc, 'content-type'));
                $ok_hc = ((int) wp_remote_retrieve_response_code($r_hc) === 200) && (strpos($ct_hc, 'text/css') === 0);
                
                $pv_hc = is_wp_error($r_hc) ? '' : (string) wp_remote_retrieve_header($r_hc, 'x-cdn-version');
                if ($pv_hc !== '') { set_transient('wpc_v2_cf_pod_version', $pv_hc, 12 * HOUR_IN_SECONDS); }


                if ($ok_hc) {
                    update_option('wpc_v2_cf_asset_mime_ok', '1', true);
                    delete_transient('wpc_v2_cf_asset_mime_retry');
                } else {
                    set_transient('wpc_v2_cf_asset_mime_retry', '0', 2 * HOUR_IN_SECONDS);
                }
                return $ct_hc !== '' ? $ct_hc : 'fetch_error';
            })(),
            'mode'                 => function_exists('wpc_get_optimization_mode') ? (string) wpc_get_optimization_mode() : '?',
            'nd_active'            => class_exists('WPC_Negotiated_Delivery') && method_exists('WPC_Negotiated_Delivery', 'is_active') && WPC_Negotiated_Delivery::is_active(),


            'provisioning'         => [
                'env_fingerprint'         => function_exists('wpc_v2_env_fingerprint') ? wpc_v2_env_fingerprint() : null,
                'env_fingerprint_stamped' => (string) get_option('wpc_v2_provisioned_fingerprint', ''),
                'env_changed'             => function_exists('wpc_v2_provision_env_changed') ? wpc_v2_provision_env_changed() : null,
                'cdn_suppressed'          => function_exists('wpc_v2_zone_cdn_suppressed') ? wpc_v2_zone_cdn_suppressed() : null,
                'config_synced_at'        => (int) get_option('wpc_v2_config_synced_at', 0),
                'force_provision'         => (bool) get_option('wpc_v2_force_provision', false),
                'force_provision_fails'   => (int) get_option('wpc_v2_force_provision_fails', 0),
                'selfheal_attempts'       => (int) get_option('wpc_v2_selfheal_attempts', 0),
                'provisioned_site_url'    => (string) get_option('wpc_v2_provisioned_site_url', ''),
                'site_url'                => function_exists('site_url') ? (string) site_url() : '',
                'zone_id'                 => function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '',
                'orch_nav'                => function_exists('wpc_v2_get_zone_id') ? (string) get_option('wpc_v2_orch_nav_' . sanitize_key((string) wpc_v2_get_zone_id()), '') : '',
                'cf_cname_configured'     => defined('WPS_IC_CF_CNAME') ? (string) get_option(WPS_IC_CF_CNAME, '') : '',
                'cf_cdn_on'               => (static function () {
                    $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
                    return is_array($cf) && !empty($cf['settings']['cdn']);
                })(),
                'cf_cname_verified'       => (string) get_option('wpc_cf_cname_verified', 'legacy'),
            ],


            'delivery'             => (function () {
                if (!class_exists('WPC_Delivery_Resolver')) return null;
                $rv   = WPC_Delivery_Resolver::resolve_verbose();
                $vc   = (isset($rv['verify']['cdn']) && is_array($rv['verify']['cdn'])) ? $rv['verify']['cdn'] : null;
                $caps = (isset($rv['capabilities']) && is_array($rv['capabilities'])) ? $rv['capabilities'] : [];
                return [
                    'tier'          => isset($rv['tier_name']) ? $rv['tier_name'] : (isset($rv['tier']) ? $rv['tier'] : '?'),
                    'ceiling'       => isset($rv['ceiling']) ? $rv['ceiling'] : '?',
                    'override'      => isset($rv['override']) ? $rv['override'] : '?',
                    'verify_cdn_ok' => $vc !== null ? !empty($vc['ok']) : null,
                    'verify_cdn'    => $vc,
                    'cdn_on'        => !empty($caps['cdn_on']),
                    'has_variants'  => !empty($caps['has_variants']),
                ];
            })(),
            'lazy_enabled'         => function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled(),
            'cf_seen'              => (bool) get_option('wpc_v2_cf_assets_seen', 0),
            'asset_probe'          => ((string) get_option('wpc_v2_cf_asset_mime_ok', '') === '1') ? '1' : (get_transient('wpc_v2_cf_asset_mime_retry') !== false ? '0' : false),
            'drain_worker_started' => (int) get_transient('wpc_v2_drain_worker_started'),
            'drain_alive_until_ms' => (int) get_option('wpc_v2_drain_alive_until_ms', 0),
            'pull_cursor_ms'       => (int) get_option('wpc_v2_pull_cursor_ms', 0),


            'last_ingest_fail'     => get_option('wpc_v2_last_ingest_fail', null),
            'last_drain_stats'     => get_option('wpc_v2_last_drain_stats', null),
            'last_wake'            => get_option('wpc_v2_last_wake', null),
            'last_drain_skip'     => get_option('wpc_v2_last_drain_skip', null),
            'last_extdrain'       => get_option('wpc_v2_last_extdrain', null),
            'ingest_outcomes'     => get_option('wpc_v2_ingest_outcomes', null),
            'last_ingest_trace'   => get_option('wpc_v2_last_ingest_trace', null),
            'bytes_egress'         => (static function () {
                $r_eg = wp_remote_head('https://wpc-v2-variants.b-cdn.net/', ['timeout' => 5, 'sslverify' => false]);
                return is_wp_error($r_eg) ? ('ERR: ' . substr($r_eg->get_error_message(), 0, 80)) : (int) wp_remote_retrieve_response_code($r_eg);
            })(),
        ],
        'tier'                    => $tier_name,
        'emission_mode'           => $emission_mode,
        'images_on'               => (bool) $images_on_hc,
        'cf_detected'             => (bool) $cf_detected,
        'time'                    => time(),
    ];
    if (!$hc_full) {
        unset($hc_arr['apikey_fp'], $hc_arr['debug']);
    }
    $resp = new WP_REST_Response($hc_arr, 200);
    $resp->header('Cache-Control', 'no-store, max-age=0');
    $resp->header('X-Wpc-Plugin-Version', $plugin_version);
    return $resp;
}


function wpc_v2_handle_fetch_variant(WP_REST_Request $request)
{
    if (!apply_filters('wpc_v2_fetch_variant_enabled', true)) {
        return wpc_v2_respond(403, ['error' => 'disabled']);
    }

    $body_raw = (string) $request->get_body();
    $v = wpc_v2_verify_hmac($request->get_header('X-WPC-Sig'), $body_raw);
    if (empty($v['ok'])) {
        return wpc_v2_respond(401, ['error' => 'hmac', 'reason' => isset($v['reason']) ? $v['reason'] : '']);
    }

    $body = json_decode($body_raw, true);
    $path = (is_array($body) && isset($body['path'])) ? (string) $body['path'] : '';
    if ($path === '') {
        return wpc_v2_respond(400, ['error' => 'no_path']);
    }

    
    $path = preg_replace('/[?#].*$/', '', $path);
    $path = rawurldecode($path);
    if (strpos($path, "\0") !== false
        || preg_match('#(^|[/\\\\])\.\.([/\\\\]|$)#', $path)
        || preg_match('#^[a-z][a-z0-9+.\-]*://#i', $path)) {
        return wpc_v2_respond(400, ['error' => 'bad_path']);
    }
    
    if (!preg_match('/\.(avif|webp|jpe?g|png|gif)$/i', $path)) {
        return wpc_v2_respond(415, ['error' => 'unsupported_ext']);
    }
    if (!function_exists('wp_get_upload_dir')) {
        return wpc_v2_respond(500, ['error' => 'no_upload_api']);
    }

    $ud       = wp_get_upload_dir();
    $basedir  = (is_array($ud) && !empty($ud['basedir'])) ? rtrim((string) $ud['basedir'], '/') : '';
    $realbase = $basedir !== '' ? realpath($basedir) : false;
    if ($realbase === false) {
        return wpc_v2_respond(500, ['error' => 'no_basedir']);
    }
    
    $baseurl_path = (is_array($ud) && !empty($ud['baseurl'])) ? (string) parse_url((string) $ud['baseurl'], PHP_URL_PATH) : '';
    $rel = $path;
    if ($baseurl_path !== '' && strpos($rel, $baseurl_path) === 0) {
        $rel = substr($rel, strlen($baseurl_path));
    }
    $rel  = ltrim($rel, '/');
    $real = realpath($realbase . '/' . $rel);
    
    if ($real === false
        || strpos($real, $realbase . DIRECTORY_SEPARATOR) !== 0
        || !is_file($real) || !is_readable($real)) {
        return wpc_v2_respond(404, ['error' => 'not_found']);
    }
    $size = (int) @filesize($real);
    if ($size <= 0 || $size > 25 * 1024 * 1024) {
        return wpc_v2_respond(413, ['error' => 'bad_size']);
    }

    $ext    = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
    $ctypes = ['avif' => 'image/avif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg',
               'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
    $ctype  = isset($ctypes[$ext]) ? $ctypes[$ext] : 'application/octet-stream';

    
    if (!headers_sent()) {
        status_header(200);
        header('Content-Type: ' . $ctype);
        header('Content-Length: ' . $size);
        header('Cache-Control: public, max-age=31536000, immutable');
        header('X-WPC-Fetch-Variant: 1');
    }
    @readfile($real);
    exit;
}











if (!function_exists('wpc_v2_bare_alias274')) {
    
    
    
    
    
    
    
    
    function wpc_v2_bare_alias274($imageID, $dest, $format, $raw)
    {
        try {
            $fmt274 = strtolower((string) $format);
            if ($fmt274 !== 'avif' && $fmt274 !== 'webp') { return false; }
            if (!apply_filters('wpc_bare_full_alias', true)) { return false; }
            if (!preg_match('/-(\d+)x(\d+)\.(avif|webp)$/i', (string) $dest, $m274)) { return false; }
            $meta274 = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata((int) $imageID) : null;
            $fw274 = (is_array($meta274) && !empty($meta274['width'])) ? (int) $meta274['width'] : 0;
            $fh274 = (is_array($meta274) && !empty($meta274['height'])) ? (int) $meta274['height'] : 0;
            if ($fw274 <= 0 || (int) $m274[1] !== $fw274 || (int) $m274[2] !== $fh274) { return false; }
            $att274 = function_exists('get_attached_file') ? (string) get_attached_file((int) $imageID) : '';
            if ($att274 === '') { return false; }
            $bn274 = basename($att274);
            $dot274 = strrpos($bn274, '.');
            if ($dot274 === false) { return false; }
            $bare274 = dirname((string) $dest) . '/' . substr($bn274, 0, $dot274) . '.' . strtolower($m274[3]);
            if ($bare274 === (string) $dest) { return false; }
            if (function_exists('wpc_v2_dest_ext_ok649') && !wpc_v2_dest_ext_ok649($bare274)) { return false; }
            $put274 = function_exists('wpc_v2_store_bytes655') ? wpc_v2_store_bytes655($raw, $bare274) : ['ok' => false];
            if (!empty($put274['ok'])) {
                @chmod($bare274, 0644);
                return true;
            }
            return false;
        } catch (\Throwable $e274) {
            return false;
        }
    }
}
if (!function_exists('wpc_v2_safe_variant_filename649')) {
    function wpc_v2_ext_for_format649($format)
    {
        $map = [
            'jpeg' => 'jpg', 'jpg' => 'jpg', 'webp' => 'webp',
            'avif' => 'avif', 'png' => 'png', 'gif' => 'gif',
        ];
        $f = strtolower((string) $format);
        return isset($map[$f]) ? $map[$f] : '';
    }

    function wpc_v2_safe_variant_filename649($filename, $format)
    {
        $ext = wpc_v2_ext_for_format649($format);
        if ($ext === '') {
            return '';
        }
        $name = basename((string) $filename);
        if ($name === '' || strpos($name, "\0") !== false || strpos($name, '/') !== false
            || strpos($name, '\\') !== false || $name[0] === '.') {
            return '';
        }
        if (function_exists('sanitize_file_name')) {
            $name = sanitize_file_name($name);
        }
        
        
        
        
        
        $dot = strrpos($name, '.');
        $stem = ($dot === false) ? $name : substr($name, 0, $dot);
        
        
        $given = ($dot === false) ? '' : strtolower(substr($name, $dot + 1));
        $stem = preg_replace('/[^A-Za-z0-9_.\-]/', '', (string) $stem);
        $stem = trim((string) $stem, '.');
        if ($stem === '' || strlen($stem) > 180 || strpos($stem, '..') !== false) {
            return '';
        }
        $bad = ['php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
            'cgi', 'pl', 'py', 'rb', 'sh', 'asp', 'aspx', 'jsp', 'js', 'html', 'htm', 'htaccess'];
        
        
        
        
        if ($given !== '' && in_array(rtrim($given, '_'), $bad, true)) {
            return '';
        }
        foreach (explode('.', $stem) as $seg) {
            if (in_array(rtrim(strtolower($seg), '_'), $bad, true)) {
                return '';
            }
        }
        return $stem . '.' . $ext;
    }

    
    
    function wpc_v2_dest_ext_ok649($dest)
    {
        $e = strtolower((string) pathinfo((string) $dest, PATHINFO_EXTENSION));
        return in_array($e, ['jpg', 'jpeg', 'webp', 'avif', 'png', 'gif'], true);
    }
}

function wpc_v2_handle_bg_swap(WP_REST_Request $request)
{
    $entry_t = microtime(true);


    $request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : $entry_t;


    $sig_hdr = (string) $request->get_header('x_wpc_sig');
    $ct      = (string) $request->get_header('content_type');
    $clen    = (string) $request->get_header('content_length');
    $body_peek = $request->get_body();
    error_log(sprintf(
        '[WPC V2BgSwap ENTRY] body_len=%d content_type=%s content_length=%s sig_present=%s body_head=%s',
        strlen((string) $body_peek),
        $ct,
        $clen,
        $sig_hdr !== '' ? 'yes' : 'no',
        substr((string) $body_peek, 0, 200)
    ));

    
    $body_raw = $body_peek;
    if (!is_string($body_raw) || $body_raw === '') {
        return wpc_v2_respond(400, ['error' => 'empty_body']);
    }

    
    $sig_header = (string) $request->get_header('x_wpc_sig');
    $hmac_check = wpc_v2_verify_hmac($sig_header, $body_raw, 60, 'write');
    if (!$hmac_check['ok']) {
        error_log('[WPC V2BgSwap] auth_rejected reason=' . $hmac_check['reason']);
        return wpc_v2_respond(401, ['error' => 'auth', 'reason' => $hmac_check['reason']]);
    }

    
    $body = json_decode($body_raw, true);
    if (!is_array($body)) {
        return wpc_v2_respond(400, ['error' => 'invalid_json']);
    }
    $imageID    = isset($body['imageID']) ? (int) $body['imageID'] : 0;
    $size_label = isset($body['sizeLabel']) ? sanitize_key((string) $body['sizeLabel']) : '';
    $format     = isset($body['format']) ? strtolower(sanitize_key((string) $body['format'])) : '';
    $filename   = isset($body['filename']) ? wpc_v2_safe_variant_filename649($body['filename'], $format) : '';
    $b64        = isset($body['bytesB64']) ? (string) $body['bytesB64'] : '';


    $fetch_url  = '';
    if (isset($body['fetchUrl']) && is_string($body['fetchUrl']) && $body['fetchUrl'] !== '') {
        $fetch_url = (string) $body['fetchUrl'];
    } elseif (isset($body['bytesUrl']) && is_string($body['bytesUrl']) && $body['bytesUrl'] !== '') {
        $fetch_url = (string) $body['bytesUrl'];
    }
    $expected_size   = isset($body['bytesSize'])   ? (int) $body['bytesSize']     : 0;
    $expected_sha256 = isset($body['bytesSha256']) ? (string) $body['bytesSha256'] : '';


    $is_parent  = !empty($body['parent']);
    $job_id     = isset($body['jobId']) ? (string) $body['jobId'] : '';

    if (!$imageID || get_post_type($imageID) !== 'attachment') {
        return wpc_v2_respond(410, ['error' => 'unknown_image', 'imageID' => $imageID]);
    }
    if ($size_label === '' || $format === '') {
        return wpc_v2_respond(400, ['error' => 'missing_fields']);
    }
    if (!in_array($format, ['jpeg', 'jpg', 'webp', 'avif'], true)) {
        return wpc_v2_respond(400, ['error' => 'invalid_format']);
    }
    if ($format === 'jpg') $format = 'jpeg';


    if (get_transient('wpc_v2_callbacks_blocked_' . $imageID) !== false) {
        error_log(sprintf(
            '[WPC V2BgSwap restored_reject] imageID=%d size=%s fmt=%s job=%s',
            $imageID, $size_label, $format,
            $job_id !== '' ? substr($job_id, 0, 8) : '-'
        ));
        return wpc_v2_respond(410, ['error' => 'image_restored', 'imageID' => $imageID]);
    }


    if ($job_id !== '') {
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (is_array($pending) && !empty($pending['jobId']) && (string) $pending['jobId'] !== $job_id) {
            error_log(sprintf(
                '[WPC V2BgSwap stale_job] imageID=%d cb_job=%s pending_job=%s size=%s fmt=%s',
                $imageID, substr($job_id, 0, 8), substr((string) $pending['jobId'], 0, 8),
                $size_label, $format
            ));
            return wpc_v2_respond(410, ['error' => 'stale_job', 'cb_jobId' => $job_id, 'pending_jobId' => (string) $pending['jobId']]);
        }
    }


    if ($filename === '') {
        $abs_parent_for_name = get_attached_file($imageID);
        if (!$abs_parent_for_name) {
            return wpc_v2_respond(500, ['error' => 'parent_file_missing_for_derive']);
        }
        $filename = wpc_v2_derive_variant_filename($abs_parent_for_name, $imageID, $size_label, $format);
        if ($filename === '') {
            return wpc_v2_respond(400, ['error' => 'filename_derive_failed', 'sizeLabel' => $size_label]);
        }
    }

    
    if (!empty($body['noImprovement'])) {
        $reason = isset($body['reason']) ? sanitize_text_field((string) $body['reason']) : 'no_improvement';
        wpc_v2_record_no_improvement($imageID, $size_label, $format, $reason, $body);
        if (wpc_v2_remove_pending($imageID, $size_label, $format)) {
            wpc_v2_recompute_savings($imageID);
        }
        return wpc_v2_respond(200, ['ok' => true, 'kind' => 'no_improvement']);
    }


    if (isset($body['bumped']) && (string) $body['bumped'] === 'source_already_optimal') {
        error_log(sprintf(
            '[WPC V2BgSwap source_already_optimal] imageID=%d size=%s fmt=%s job=%s',
            $imageID, $size_label, $format,
            $job_id !== '' ? substr($job_id, 0, 8) : '-'
        ));
        wpc_v2_record_no_improvement($imageID, $size_label, $format, 'source_already_optimal', $body);
        if (wpc_v2_remove_pending($imageID, $size_label, $format)) {
            wpc_v2_recompute_savings($imageID);
        }
        return wpc_v2_respond(200, ['ok' => true, 'kind' => 'source_already_optimal']);
    }

    
    $write_start_t = microtime(true);

    
    
    if ($b64 !== '') {
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            return wpc_v2_respond(400, ['error' => 'invalid_base64']);
        }
    } elseif ($fetch_url !== '') {
        $get = wp_remote_get($fetch_url, ['timeout' => 15, 'sslverify' => true]);
        if (is_wp_error($get) || (int) wp_remote_retrieve_response_code($get) !== 200) {
            return wpc_v2_respond(502, ['error' => 'fetch_url_failed']);
        }
        $raw = wp_remote_retrieve_body($get);
        if (!is_string($raw) || $raw === '') {
            return wpc_v2_respond(502, ['error' => 'fetch_url_empty']);
        }
        
        if ($expected_size > 0 && strlen($raw) !== $expected_size) {
            return wpc_v2_respond(502, ['error' => 'fetch_url_size_mismatch', 'got' => strlen($raw), 'expected' => $expected_size]);
        }
        if ($expected_sha256 !== '' && !hash_equals($expected_sha256, hash('sha256', $raw))) {
            return wpc_v2_respond(502, ['error' => 'fetch_url_sha256_mismatch']);
        }
    } else {
        return wpc_v2_respond(400, ['error' => 'missing_bytes_or_fetchUrl']);
    }

    if (function_exists('wpc_is_valid_image_bytes')
        && !wpc_is_valid_image_bytes($raw, $format, $imageID, 'bg_swap_v2', ['size_label' => $size_label])) {
        return wpc_v2_respond(400, ['error' => 'invalid_image_bytes']);
    }

    
    $abs_parent = get_attached_file($imageID);
    if (!$abs_parent) {
        return wpc_v2_respond(500, ['error' => 'parent_file_missing']);
    }
    $dest_dir = dirname($abs_parent);
    $dest     = $dest_dir . '/' . $filename;
    
    
    if (!wpc_v2_dest_ext_ok649($dest)) {
        return wpc_v2_respond(400, ['error' => 'invalid_filename']);
    }

    
    if (file_exists($dest) && filesize($dest) === strlen($raw) && hash_file('sha256', $dest) === hash('sha256', $raw)) {
        if (wpc_v2_remove_pending($imageID, $size_label, $format)) {
            wpc_v2_recompute_savings($imageID);
        }
        return wpc_v2_respond(200, ['ok' => true, 'kind' => 'idempotent_noop']);
    }

    if (!wpc_v2_dest_ext_ok($dest)) {
        return wpc_v2_respond(400, ['error' => 'bad_extension']);
    }

    $wpc_put655 = wpc_v2_store_bytes655($raw, $dest);
    if (empty($wpc_put655['ok'])) {
        error_log(sprintf(
            '[WPC V2Callback] %s imageID=%d size_label=%s format=%s bytes=%d dest_tail=%s msg=%s',
            (string) $wpc_put655['error'], (int) $imageID, (string) $size_label, (string) $format,
            strlen($raw), substr($dest, -60), (string) $wpc_put655['msg']
        ));
        return wpc_v2_respond(500, ['error' => (string) $wpc_put655['error']]);
    }


    if (!@chmod($dest, 0644)) {
        $err = error_get_last();
        error_log(sprintf(
            '[WPC V2Callback] chmod_failed imageID=%d dest_tail=%s msg=%s (may still be served if umask/inherited perms OK)',
            (int) $imageID, substr($dest, -60), $err['message'] ?? '-'
        ));
    }
    if (function_exists('wpc_v2_bare_alias274')) {
        wpc_v2_bare_alias274($imageID, $dest, $format, $raw);
    }


    if (function_exists('wpc_v2_enqueue_landed_purge') && function_exists('wpc_v2_get_apikey') && (string) wpc_v2_get_apikey() !== '') {
        wpc_v2_enqueue_landed_purge($dest);
    }

    
    $t_after_write = microtime(true);

    
    $variant_key = wpc_v2_variant_key($size_label, $format);
    $upload_dir  = wp_get_upload_dir();


    $orig_size = isset($body['originalSize']) ? (int) $body['originalSize'] : 0;
    $kb        = isset($body['kb'])     ? (float) $body['kb']     : 0.0;
    $butter    = isset($body['butter']) ? (float) $body['butter'] : 0.0;
    $savings   = ($orig_size > 0) ? max(0, (int) round((1 - (strlen($raw) / $orig_size)) * 100)) : 0;


    $t0_ms      = (int) get_transient('wpc_v2_t0_ms_' . $imageID);
    $entry_ms   = (int) round($entry_t * 1000);
    $from_click_ms = ($t0_ms > 0) ? max(0, $entry_ms - $t0_ms) : 0;

    $entry = [
        'size'              => strlen($raw),
        'originalSize'      => $orig_size,
        'url'               => $upload_dir['baseurl'] . '/' . ltrim(str_replace($upload_dir['basedir'], '', $dest), '/'),
        'local'             => true,
        'skipped'           => false,
        'savings'           => $savings,
        'bg_upgraded'       => time(),


        'bg_upgraded_ms'    => (int) round(microtime(true) * 1000),
        'bg_t_from_click_ms' => $from_click_ms,
        'kb_reported'       => $kb,
        'butter'            => $butter,
        'phase_b_v2'        => true,
    ];
    if (isset($body['q']))      $entry['q']      = (int) $body['q'];
    if (isset($body['bumped'])) $entry['bumped'] = sanitize_text_field((string) $body['bumped']);
    if (isset($body['telemetry']) && is_array($body['telemetry'])) {
        $entry['telemetry'] = $body['telemetry'];
    }
    wpc_v2_merge_variant($imageID, $variant_key, $entry);

    
    $t_after_merge = microtime(true);


    $is_bulk_session = false;
    $bulk_session_ids = get_transient('wpc_bulk_session_ids');
    if (is_array($bulk_session_ids) && in_array((int) $imageID, array_map('intval', $bulk_session_ids), true)) {
        $is_bulk_session = true;
    }
    if (!$is_bulk_session && $orig_size > 0 && $savings > 0) {


        global $wpdb;
        $sav_lock = 'wpc_ic_savings_' . $imageID;
        $got_sav  = wpc_worker_lock($sav_lock, 200);
        if ($got_sav) {
            try {
                $cur_savings = (float) get_post_meta($imageID, 'ic_savings', true);
                if ((float) $savings > $cur_savings) {
                    $opt_bytes = strlen($raw);
                    update_post_meta($imageID, 'ic_savings',          round((float) $savings, 1));
                    update_post_meta($imageID, 'ic_savings_format',   $format);
                    update_post_meta($imageID, 'ic_savings_bytes',    max(0, $orig_size - $opt_bytes));
                    update_post_meta($imageID, 'ic_savings_baseline', $orig_size);
                }
            } finally {
                wpc_worker_unlock($sav_lock);
            }
        }
    }

    $drain_complete = wpc_v2_remove_pending($imageID, $size_label, $format);
    if ($drain_complete) {
        wpc_v2_recompute_savings($imageID);
    }


    $ic_compressing = get_post_meta($imageID, 'ic_compressing', true);
    $current_status = (is_array($ic_compressing) && !empty($ic_compressing['status']))
        ? (string) $ic_compressing['status']
        : 'optimizing';

    $eager = function_exists('wpc_v2_use_eager_compressed_flip')
        && wpc_v2_use_eager_compressed_flip();

    if ($eager && $current_status !== 'compressed') {
        
        wpc_v2_ic_compressing_set_status($imageID, 'compressed');


        delete_transient('wps_ic_compress_' . $imageID);
        $current_status = 'compressed';
        error_log('[WPC V2BgSwap eager_flip] imageID=' . $imageID . ' status flipped to compressed on size=' . $size_label . ' fmt=' . $format);
    }

    
    
    if (get_transient('wpc_v2_warming_' . $imageID) !== false) {
        delete_transient('wpc_v2_warming_' . $imageID);
    }


    if (!$is_bulk_session) {
        $chip_fmt  = strtoupper($format);
        $chip_size = ucfirst(str_replace(['_', '-'], ' ', $size_label));
        set_transient('wps_ic_heartbeat_' . $imageID, [
            'imageID'         => $imageID,
            'status'          => $current_status,
            'event'           => 'bg_variant_arrived',
            'time'            => time(),
            'bg_variant_fmt'  => $chip_fmt,
            'bg_variant_size' => $chip_size,
        ], 300);
    }

    $t_handler_end = microtime(true);
    $total_ms      = (int) round(($t_handler_end - $entry_t) * 1000);


    $t_req_start  = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : $entry_t;
    $bootstrap_ms = ($entry_t - $t_req_start) * 1000;

    $orch_server_time = isset($body['serverTime']) ? (int) $body['serverTime'] : 0;
    $clock_skew_ms    = $orch_server_time > 0
        ? (int) round(($entry_t * 1000) - $orch_server_time)
        : null;

    $write_ms     = isset($write_start_t, $t_after_write)
        ? ($t_after_write - $write_start_t) * 1000
        : 0.0;
    $merge_ms     = isset($t_after_write, $t_after_merge)
        ? ($t_after_merge - $t_after_write) * 1000
        : 0.0;
    $recompute_ms = isset($t_after_merge)
        ? ($t_handler_end - $t_after_merge) * 1000
        : 0.0;

    error_log(sprintf(
        '[WPC V2BgSwap ACK] imageID=%d size=%s fmt=%s parent=%s bytes=%d orig=%d savings=%d%% q=%s butter=%.2f t_from_click_ms=%d total_ms=%d job=%s',
        $imageID, $size_label, $format,
        $is_parent ? 'Y' : 'N',
        strlen($raw),
        $orig_size,
        $savings,
        isset($entry['q']) ? (string) $entry['q'] : '-',
        $butter,
        $from_click_ms,
        $total_ms,
        $job_id !== '' ? substr($job_id, 0, 8) : '-'
    ));


    $inbound_to_complete_ms = ($t_handler_end - $request_arrival_t) * 1000;

    error_log(sprintf(
        '[wpc_v2_bg_swap_timing] imageID=%d variant=%s codec=%s bytes_in=%d clock_skew_ms=%s bootstrap_ms=%.1f write_ms=%.1f merge_ms=%.1f recompute_ms=%.1f total_handler_ms=%.1f inbound_to_complete_ms=%.1f',
        $imageID,
        $size_label,
        $format,
        strlen($b64),
        $clock_skew_ms === null ? 'n/a' : (string) $clock_skew_ms,
        max(0.0, $bootstrap_ms),
        max(0.0, $write_ms),
        max(0.0, $merge_ms),
        max(0.0, $recompute_ms),
        ($t_handler_end - $entry_t) * 1000,
        max(0.0, $inbound_to_complete_ms)
    ));

    
    if (function_exists('wpc_v2_record_handler_timing')) {
        wpc_v2_record_handler_timing('single', max(0.0, $inbound_to_complete_ms));
    }


    if (function_exists('wpc_v2_purge_html_for_attachment_deferred')) {
        wpc_v2_purge_html_for_attachment_deferred($imageID, 'v2-callback');
    }

    return wpc_v2_respond(200, ['ok' => true, 'kind' => 'persisted', 'bytes' => strlen($raw)]);
}









function wpc_v2_verify_hmac($sig_header, $body_raw, $replay_window_s = 60, $scope = '')
{
    if (!is_string($sig_header) || $sig_header === '') {
        return ['ok' => false, 'reason' => 'missing_sig'];
    }
    if (!function_exists('hash_hmac')) {
        return ['ok' => false, 'reason' => 'hash_hmac_unavailable'];
    }

    $parts = [];
    foreach (explode(',', $sig_header) as $kv) {
        $kv = trim($kv);
        $eq = strpos($kv, '=');
        if ($eq === false) continue;
        $parts[substr($kv, 0, $eq)] = substr($kv, $eq + 1);
    }
    if (empty($parts['t']) || empty($parts['v1'])) {
        return ['ok' => false, 'reason' => 'malformed_sig'];
    }

    $ts = (int) $parts['t'];
    $now = time();
    if (abs($now - $ts) > (int) $replay_window_s) {
        return ['ok' => false, 'reason' => 'replay_window_exceeded'];
    }

    $payload = $ts . '.' . hash('sha256', $body_raw);
    $given   = (string) $parts['v1'];

    
    
    
    
    $wpc_write652 = ($scope === 'write');
    $secret = function_exists('wpc_v2_callback_secret650') ? wpc_v2_callback_secret650(false) : '';
    if ($secret !== '' && hash_equals(hash_hmac('sha256', $payload, $secret), $given)) {
        if ($wpc_write652 && function_exists('wpc_v2_callback_note_secret_use650')) {
            wpc_v2_callback_note_secret_use650();
        }
        return ['ok' => true, 'via' => 'cb_secret'];
    }

    if ($wpc_write652 && function_exists('wpc_v2_callback_strict650') && wpc_v2_callback_strict650()) {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('cb-sig-strict-reject', '', '', []);
        }
        return ['ok' => false, 'reason' => 'sig_mismatch_strict'];
    }

    
    $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
    if ($apikey === '') {
        return ['ok' => false, 'reason' => 'plugin_no_apikey'];
    }

    if (!hash_equals(hash_hmac('sha256', $payload, $apikey), $given)) {
        return ['ok' => false, 'reason' => 'sig_mismatch'];
    }

    return ['ok' => true, 'via' => 'apikey_legacy'];
}


function wpc_v2_merge_variant($imageID, $variant_key, array $entry)
{
    global $wpdb;
    $lock_name = 'wpc_bg_meta_' . (int) $imageID;
    
    
    $got_lock = wpc_worker_lock($lock_name);
    if (!$got_lock) {
        error_log(sprintf('[WPC V2] wpc_v2_merge_variant lock_unavailable imageID=%d variant=%s — proceeding unlocked (race possible)', (int) $imageID, $variant_key));
    }

    try {


        wp_cache_delete($imageID, 'post_meta');
        $existing = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($existing)) $existing = [];
        $existing[$variant_key] = array_merge($existing[$variant_key] ?? [], $entry);
        update_post_meta($imageID, 'ic_local_variants', $existing);
    } finally {
        if ($got_lock) {
            wpc_worker_unlock($lock_name);
        }
    }
}







function wpc_v2_recompute_savings($imageID)
{
    if (!function_exists('wpc_compute_best_savings')) return;

    global $wpdb;
    $lock_name = 'wpc_bg_meta_' . (int) $imageID;
    
    $got_lock = wpc_worker_lock($lock_name);
    if (!$got_lock) {
        error_log(sprintf('[WPC V2] wpc_v2_recompute_savings lock_unavailable imageID=%d — proceeding unlocked', (int) $imageID));
    }

    try {
        wp_cache_delete($imageID, 'post_meta');
        $existing = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($existing) || empty($existing)) return;

        $best = wpc_compute_best_savings($existing, $imageID);
        if (!empty($best['orig']) && !empty($best['pct'])) {
            update_post_meta($imageID, 'ic_savings',          round((float) $best['pct'], 1));
            update_post_meta($imageID, 'ic_savings_format',   (string) $best['format']);
            update_post_meta($imageID, 'ic_savings_bytes',    (int) $best['orig'] - (int) $best['opt']);
            update_post_meta($imageID, 'ic_savings_baseline', (int) $best['orig']);
        }
    } finally {
        if ($got_lock) {
            wpc_worker_unlock($lock_name);
        }
    }
}

function wpc_v2_record_no_improvement($imageID, $size_label, $format, $reason, array $body)
{
    $variant_key = wpc_v2_variant_key($size_label, $format);
    wpc_v2_merge_variant($imageID, $variant_key, [
        'bg_no_improvement'      => true,
        'no_improvement_reason'  => (string) $reason,
        'baseline_kb'            => isset($body['baselineKb']) ? (float) $body['baselineKb'] : 0.0,
        'widen_alt_kbs'          => isset($body['widenAltKbs']) && is_array($body['widenAltKbs']) ? $body['widenAltKbs'] : [],
        'phase_b_v2'             => true,
    ]);
}


function wpc_v2_remove_pending($imageID, $size_label, $format)
{
    $payload = get_transient('wpc_v2_pending_' . $imageID);
    if (!is_array($payload)) return false;

    
    
    if (!isset($payload['pending']) || !is_array($payload['pending'])) {
        $payload = [
            'jobId'       => '',
            'pending'     => $payload,
            'recorded_at' => time(),
        ];
    }

    $key = wpc_v2_variant_key($size_label, $format);
    if (!isset($payload['pending'][$key])) return false;

    unset($payload['pending'][$key]);

    if (empty($payload['pending'])) {
        

        delete_transient('wpc_v2_pending_' . $imageID);


        set_transient('wpc_v2_phase_a_done_' . $imageID, time(), 3600);
        return true;
    }

    set_transient('wpc_v2_pending_' . $imageID, $payload, 600);
    return false;
}


function wpc_v2_ic_compressing_set_status($imageID, $new_status)
{
    $imageID = (int) $imageID;
    if ($imageID <= 0) return;
    $existing = get_post_meta($imageID, 'ic_compressing', true);
    if (!is_array($existing)) $existing = [];
    $existing['status'] = (string) $new_status;
    if (empty($existing['time'])) $existing['time'] = time();
    update_post_meta($imageID, 'ic_compressing', $existing);
}







function wpc_v2_ic_compressing_set_expected($imageID, $expected_variants)
{
    $imageID = (int) $imageID;
    $expected_variants = (int) $expected_variants;
    if ($imageID <= 0 || $expected_variants <= 0) return;
    $existing = get_post_meta($imageID, 'ic_compressing', true);
    if (!is_array($existing)) $existing = [];
    $existing['expected_variants'] = $expected_variants;
    update_post_meta($imageID, 'ic_compressing', $existing);
}


function wpc_v2_compute_expected_variants($imageID, $format_count = 3)
{
    $imageID = (int) $imageID;
    if ($imageID <= 0) return 0;
    $meta = wp_get_attachment_metadata($imageID);
    if (!is_array($meta)) return 0;
    $subsizes = is_array($meta['sizes'] ?? null) ? $meta['sizes'] : [];
    $subsize_count = count($subsizes);
    
    
    $has_scaled_in_subsizes   = isset($subsizes['scaled']);
    $has_original_in_subsizes = isset($subsizes['original']);
    $slots = $subsize_count
        + ($has_scaled_in_subsizes   ? 0 : 1)
        + ($has_original_in_subsizes ? 0 : 1);
    return $slots * max(1, (int) $format_count);
}

function wpc_v2_variant_key($size_label, $format)
{
    $size_label = (string) $size_label;
    $format     = strtolower((string) $format);
    if ($format === 'jpg') $format = 'jpeg';
    if ($format === 'jpeg') return $size_label;
    return $size_label . '-' . $format;
}


function wpc_v2_cleanup_stale_compressing()
{
    
    if (wp_doing_ajax()) return;

    
    if (get_transient('wpc_v2_stale_cleanup_lock')) return;
    $wpc_scl179 = (int) get_option('wpc_v2_stale_cleanup_at');
    if (time() - $wpc_scl179 < 5 * MINUTE_IN_SECONDS) return;
    update_option('wpc_v2_stale_cleanup_at', time(), false);
    set_transient('wpc_v2_stale_cleanup_lock', 1, 5 * MINUTE_IN_SECONDS);

    
    
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT post_id, meta_value
         FROM {$wpdb->postmeta}
         WHERE meta_key = 'ic_compressing'
           AND (meta_value LIKE '%\"status\";s:8:\"queueing\"%'
                OR meta_value LIKE '%\"status\";s:10:\"optimizing\"%')
         LIMIT 50"
    );
    if (empty($rows)) return;

    $now = time();
    $cleared = 0;
    foreach ($rows as $row) {
        $imageID = (int) $row->post_id;
        if ($imageID <= 0) continue;

        $ic = maybe_unserialize($row->meta_value);
        if (!is_array($ic)) continue;
        $status = (string) ($ic['status'] ?? '');
        $time   = (int)    ($ic['time']   ?? 0);
        if ($status !== 'queueing' && $status !== 'optimizing') continue;

        
        if ($time > 0 && ($now - $time) < (10 * MINUTE_IN_SECONDS)) continue;

        
        
        $variants = get_post_meta($imageID, 'ic_local_variants', true);
        if (is_array($variants) && !empty($variants)) continue;


        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (!empty($pending) && !empty($pending['pending'])) continue;

        delete_post_meta($imageID, 'ic_compressing');
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wpc_v2_warming_' . $imageID);
        $cleared++;
    }

    if ($cleared > 0) {
        error_log(sprintf('[WPC StaleCleanup] cleared ic_compressing on %d stale image(s) (>10 min, no variants, no pending)', $cleared));
    }
}
add_action('admin_init', 'wpc_v2_cleanup_stale_compressing');

function wpc_v2_respond($status, array $body)
{
    return new WP_REST_Response($body, $status);
}


function wpc_v2_derive_variant_filename($abs_path, $imageID, $size_label, $format)
{
    $ext = ($format === 'jpeg' || $format === 'jpg') ? 'jpg' : strtolower($format);

    
    if ($size_label === 'scaled' || $size_label === '') {
        $base = basename($abs_path);
        $dot  = strrpos($base, '.');
        if ($dot === false) return '';
        return substr($base, 0, $dot) . '.' . $ext;
    }


    if ($size_label === 'original') {
        $orig_path = function_exists('wp_get_original_image_path')
            ? wp_get_original_image_path($imageID)
            : $abs_path;
        if (!$orig_path) $orig_path = $abs_path;
        $base = basename($orig_path);
        $dot  = strrpos($base, '.');
        if ($dot === false) return '';
        return substr($base, 0, $dot) . '.' . $ext;
    }


    if (preg_match('/^\d{2,4}x\d{2,4}$/', (string) $size_label)) {
        $orig_p = ($imageID > 0 && function_exists('wp_get_original_image_path'))
            ? wp_get_original_image_path((int) $imageID) : '';
        if (!$orig_p) $orig_p = $abs_path;
        $b_a = basename($orig_p);
        $d_a = strrpos($b_a, '.');
        $stem_a = preg_replace('/-scaled$/', '', $d_a === false ? $b_a : substr($b_a, 0, $d_a));
        return $stem_a . '-' . $size_label . '.' . $ext;
    }

    
    $meta = wp_get_attachment_metadata($imageID);
    if (!is_array($meta) || empty($meta['sizes'][$size_label]['file'])) {
        return '';
    }
    $sub = (string) $meta['sizes'][$size_label]['file'];
    $dot = strrpos($sub, '.');
    if ($dot === false) return $sub . '.' . $ext;
    return substr($sub, 0, $dot) . '.' . $ext;
}


function wpc_v2_register_callback_batch_route()
{
    register_rest_route('wpc/v2', '/bg_swap_batch', [
        'methods'             => 'POST',
        'callback'            => 'wpc_v2_handle_bg_swap_batch',
        'permission_callback' => '__return_true', 
    ]);
}
add_action('rest_api_init', 'wpc_v2_register_callback_batch_route');


function wpc_v2_handle_bg_swap_batch(WP_REST_Request $request)
{
    $entry_t = microtime(true);
    
    $request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : $entry_t;
    $body_raw = $request->get_body();

    
    
    $bootstrap_skip = (defined('WPC_IS_BG_SWAP') && WPC_IS_BG_SWAP);

    
    $sig_header = (string) $request->get_header('x_wpc_sig');
    $hmac_check = wpc_v2_verify_hmac($sig_header, (string) $body_raw, 60, 'write');
    if (!$hmac_check['ok']) {
        error_log('[WPC V2BgSwapBatch] auth_rejected reason=' . $hmac_check['reason']);
        return wpc_v2_respond(401, ['error' => 'auth', 'reason' => $hmac_check['reason']]);
    }

    $body = json_decode($body_raw, true);
    if (!is_array($body)) {
        return wpc_v2_respond(400, ['error' => 'invalid_json']);
    }
    if (empty($body['variants']) || !is_array($body['variants'])) {
        return wpc_v2_respond(400, ['error' => 'missing_variants']);
    }
    $variants = $body['variants'];
    $variant_n = count($variants);
    if ($variant_n > 25) {
        return wpc_v2_respond(413, ['error' => 'batch_too_large', 'max' => 25, 'got' => $variant_n]);
    }

    $imageID = isset($body['imageID']) ? (int) $body['imageID'] : 0;
    if (!$imageID || get_post_type($imageID) !== 'attachment') {
        return wpc_v2_respond(410, ['error' => 'unknown_image', 'imageID' => $imageID]);
    }
    $jobId        = isset($body['jobId']) ? (string) $body['jobId'] : '';
    $serverTime   = isset($body['serverTime']) ? (int) $body['serverTime'] : 0;
    $clockSkewMs  = $serverTime > 0 ? (int) round(($entry_t * 1000) - $serverTime) : null;
    $flushReason  = isset($body['flush_reason']) ? sanitize_key((string) $body['flush_reason']) : '';

    
    
    $wrap_size   = isset($body['sizeLabel']) ? sanitize_key((string) $body['sizeLabel']) : '';
    $wrap_orig   = isset($body['originalSize']) ? (int) $body['originalSize'] : 0;
    $wrap_parent = !empty($body['parent']);
    $wrap_fname  = isset($body['filename']) ? $body['filename'] : null;

    
    
    if (get_transient('wpc_v2_callbacks_blocked_' . $imageID) !== false) {
        error_log(sprintf(
            '[WPC V2BgSwapBatch restored_reject] imageID=%d variants=%d job=%s',
            $imageID, $variant_n,
            $jobId !== '' ? substr($jobId, 0, 8) : '-'
        ));
        return wpc_v2_respond(410, ['error' => 'image_restored', 'imageID' => $imageID]);
    }

    
    
    if ($jobId !== '') {
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (is_array($pending) && !empty($pending['jobId']) && (string) $pending['jobId'] !== $jobId) {
            error_log(sprintf(
                '[WPC V2BgSwapBatch stale_job] imageID=%d batch_job=%s pending_job=%s variants=%d',
                $imageID, substr($jobId, 0, 8), substr((string) $pending['jobId'], 0, 8), $variant_n
            ));
            return wpc_v2_respond(410, ['error' => 'stale_job', 'cb_jobId' => $jobId, 'pending_jobId' => (string) $pending['jobId']]);
        }
    }


    $t_after_validate = microtime(true);
    $entries_to_merge = [];   
    $results          = [];
    $any_drain_complete = false;
    $persisted_count = $rejected_count = $duplicate_count = 0;


    $use_journal     = function_exists('wpc_v2_rest_journal_enabled') && wpc_v2_rest_journal_enabled();
    $journal_entries = [];


    $pulled_bytes = [];
    $pull_ms      = 0.0;
    $defer_pulls_to_drain = $use_journal
        && function_exists('wpc_v2_pull_delivery_enabled')
        && wpc_v2_pull_delivery_enabled();

    if (!$defer_pulls_to_drain && function_exists('wpc_v2_parallel_pull')) {
        
        
        $pulls_needed = [];
        $pull_meta    = [];
        foreach ($variants as $idx => $v) {
            if (!is_array($v) || !empty($v['bytesB64'])) continue;
            $url = '';
            if (!empty($v['fetchUrl']) && is_string($v['fetchUrl'])) {
                $url = (string) $v['fetchUrl'];
            } elseif (!empty($v['bytesUrl']) && is_string($v['bytesUrl'])) {
                $url = (string) $v['bytesUrl'];
            }
            if ($url === '') continue;
            $pulls_needed[$idx] = $url;
            $pull_meta[$idx] = [
                'size'   => isset($v['bytesSize'])   ? (int) $v['bytesSize']     : null,
                'sha256' => isset($v['bytesSha256']) ? (string) $v['bytesSha256'] : null,
            ];
        }
        if (!empty($pulls_needed)) {
            $t_pull_start = microtime(true);
            $pulled_bytes = wpc_v2_parallel_pull($pulls_needed, $pull_meta);
            $pull_ms = (microtime(true) - $t_pull_start) * 1000;
            error_log(sprintf(
                '[wpc_v2_pull] imageID=%d jobId=%s pulled=%d/%d wall_ms=%.1f mode=inline',
                $imageID,
                $jobId !== '' ? substr($jobId, 0, 8) : '-',
                count($pulled_bytes),
                count($pulls_needed),
                $pull_ms
            ));
        }
    }
    $pending_pull_count = 0;

    foreach ($variants as $idx => $v) {
        if (!is_array($v)) {
            $results[]       = ['ok' => false, 'kind' => 'rejected', 'error' => 'malformed_item', 'index' => $idx];
            $rejected_count++;
            continue;
        }


        $sz   = isset($v['sizeLabel']) ? sanitize_key((string) $v['sizeLabel']) : $wrap_size;
        $fmt  = isset($v['format']) ? strtolower(sanitize_key((string) $v['format'])) : '';
        if ($sz === '' || $fmt === '') {
            $results[]       = ['ok' => false, 'kind' => 'rejected', 'error' => 'missing_size_or_format', 'index' => $idx];
            $rejected_count++;
            continue;
        }
        if (!in_array($fmt, ['jpeg', 'jpg', 'webp', 'avif'], true)) {
            $results[]       = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_format', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
        if ($fmt === 'jpg') $fmt = 'jpeg';

        
        if (!empty($v['noImprovement']) || (isset($v['bumped']) && (string) $v['bumped'] === 'source_already_optimal')) {
            $reason = !empty($v['noImprovement'])
                ? (isset($v['reason']) ? sanitize_text_field((string) $v['reason']) : 'no_improvement')
                : 'source_already_optimal';
            if ($use_journal) {
                $journal_entries[] = [
                    'sizeLabel'  => $sz,
                    'format'     => $fmt,
                    'type'       => 'no_improvement',
                    'reason'     => $reason,
                    'baselineKb' => isset($v['baselineKb']) ? (float) $v['baselineKb'] : 0.0,
                ];
            } else {
                wpc_v2_record_no_improvement($imageID, $sz, $fmt, $reason, $v);
            }
            if (wpc_v2_remove_pending($imageID, $sz, $fmt)) {
                $any_drain_complete = true;
            }
            $results[] = ['ok' => true, 'kind' => $reason === 'source_already_optimal' ? 'source_already_optimal' : 'no_improvement', 'sizeLabel' => $sz, 'format' => $fmt];
            $persisted_count++;
            continue;
        }


        $filename = '';
        if (isset($v['filename']) && is_string($v['filename'])) {
            $filename = wpc_v2_safe_variant_filename649($v['filename'], $fmt);
        } elseif ($sz === $wrap_size && is_array($wrap_fname) && isset($wrap_fname[$fmt])) {
            $filename = wpc_v2_safe_variant_filename649($wrap_fname[$fmt], $fmt);
        }
        if ($filename === '') {
            $abs_parent_for_name = get_attached_file($imageID);
            if (!$abs_parent_for_name) {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'parent_file_missing', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
            $filename = wpc_v2_derive_variant_filename($abs_parent_for_name, $imageID, $sz, $fmt);
            if ($filename === '') {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'filename_derive_failed', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
        }


        $b64       = isset($v['bytesB64']) ? (string) $v['bytesB64'] : '';
        $fetch_url = '';
        if (!empty($v['fetchUrl']) && is_string($v['fetchUrl'])) {
            $fetch_url = (string) $v['fetchUrl'];
        } elseif (!empty($v['bytesUrl']) && is_string($v['bytesUrl'])) {
            $fetch_url = (string) $v['bytesUrl'];
        }

        
        
        if ($defer_pulls_to_drain && $fetch_url !== '' && $b64 === '') {
            $expected_size   = isset($v['bytesSize'])   ? (int) $v['bytesSize']     : 0;
            $expected_sha256 = isset($v['bytesSha256']) ? (string) $v['bytesSha256'] : '';
            $orig_size = isset($v['originalSize']) ? (int) $v['originalSize']
                        : (isset($v['orig_size']) ? (int) $v['orig_size'] : $wrap_orig);
            $kb        = isset($v['kb']) ? (float) $v['kb'] : 0.0;
            $butter    = isset($v['butter']) ? (float) $v['butter'] : 0.0;
            $abs_parent = get_attached_file($imageID);
            if (!$abs_parent) {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'parent_file_missing', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
            $dest_dir = dirname($abs_parent);


            $je = [
                'sizeLabel'      => $sz,
                'format'         => $fmt,
                'type'           => 'persisted_pending_bytes',
                'fetch_url'      => $fetch_url,
                'bytes_size'     => $expected_size,
                'bytes_sha256'   => $expected_sha256,
                'filename'       => $filename,
                'dest_dir'       => $dest_dir,
                'originalSize'   => $orig_size,
                'ms'             => (int) round(microtime(true) * 1000),
                'kb'             => $kb,
                'butter'         => $butter,
            ];
            if (isset($v['q']))      $je['q']      = (int) $v['q'];
            if (isset($v['bumped'])) $je['bumped'] = sanitize_text_field((string) $v['bumped']);
            $journal_entries[] = $je;

            if (wpc_v2_remove_pending($imageID, $sz, $fmt)) {
                $any_drain_complete = true;
            }


            if ($orig_size > 0 && $expected_size > 0) {
                $savings = max(0, (int) round((1 - ($expected_size / $orig_size)) * 100));
                $cur_savings = (float) get_post_meta($imageID, 'ic_savings', true);
                if ((float) $savings > $cur_savings) {
                    update_post_meta($imageID, 'ic_savings',          round((float) $savings, 1));
                    update_post_meta($imageID, 'ic_savings_format',   $fmt);
                    update_post_meta($imageID, 'ic_savings_bytes',    max(0, $orig_size - $expected_size));
                    update_post_meta($imageID, 'ic_savings_baseline', $orig_size);
                }
            }

            $results[] = ['ok' => true, 'kind' => 'persisted_pending_bytes', 'sizeLabel' => $sz, 'format' => $fmt, 'bytes' => $expected_size];
            $persisted_count++;
            $pending_pull_count++;
            continue;
        }

        $raw = null;
        if (isset($pulled_bytes[$idx]) && is_string($pulled_bytes[$idx]) && $pulled_bytes[$idx] !== '') {
            $raw = $pulled_bytes[$idx];
        } elseif ($b64 !== '') {
            $raw = base64_decode($b64, true);
            if ($raw === false) {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_base64', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
        } elseif ($fetch_url !== '') {


            $get = wp_remote_get($fetch_url, ['timeout' => 15]);
            if (is_wp_error($get) || (int) wp_remote_retrieve_response_code($get) !== 200) {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'fetch_url_failed', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
            $raw = wp_remote_retrieve_body($get);
            if (!is_string($raw) || $raw === '') {
                $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'fetch_url_empty', 'sizeLabel' => $sz, 'format' => $fmt];
                $rejected_count++;
                continue;
            }
        } else {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'missing_bytes_or_fetchUrl', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }

        if (function_exists('wpc_is_valid_image_bytes')
            && !wpc_is_valid_image_bytes($raw, $fmt, $imageID, 'bg_swap_v2_batch', ['size_label' => $sz])) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_image_bytes', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }

        
        
        $abs_parent = get_attached_file($imageID);
        if (!$abs_parent) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'parent_file_missing', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
        $dest_dir = dirname($abs_parent);
        $dest     = $dest_dir . '/' . $filename;
        if (!wpc_v2_dest_ext_ok649($dest)) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_filename', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }

        if (file_exists($dest) && filesize($dest) === strlen($raw) && hash_file('sha256', $dest) === hash('sha256', $raw)) {
            if (wpc_v2_remove_pending($imageID, $sz, $fmt)) {
                $any_drain_complete = true;
            }
            $results[] = ['ok' => true, 'kind' => 'idempotent_noop', 'sizeLabel' => $sz, 'format' => $fmt];
            $duplicate_count++;
            continue;
        }

        if (!wpc_v2_dest_ext_ok($dest)) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'bad_extension', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }

        
        
        $wpc_put655 = wpc_v2_store_bytes655($raw, $dest);
        if (empty($wpc_put655['ok'])) {
            error_log(sprintf(
                '[WPC V2Batch] %s imageID=%d sz=%s fmt=%s bytes=%d dest_tail=%s msg=%s',
                (string) $wpc_put655['error'], (int) $imageID, (string) $sz, (string) $fmt,
                strlen($raw), substr($dest, -60), (string) $wpc_put655['msg']
            ));
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => (string) $wpc_put655['error'], 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
        if (!@chmod($dest, 0644)) {
            $err = error_get_last();
            error_log(sprintf(
                '[WPC V2Batch] chmod_failed imageID=%d dest_tail=%s msg=%s',
                (int) $imageID, substr($dest, -60), $err['message'] ?? '-'
            ));
        }
        if (function_exists('wpc_v2_bare_alias274')) {
            wpc_v2_bare_alias274($imageID, $dest, $fmt, $raw);
        }

        
        $orig_size = isset($v['originalSize']) ? (int) $v['originalSize']
                    : (isset($v['orig_size']) ? (int) $v['orig_size'] : $wrap_orig);
        $kb        = isset($v['kb']) ? (float) $v['kb'] : 0.0;
        $butter    = isset($v['butter']) ? (float) $v['butter'] : 0.0;
        $savings   = ($orig_size > 0) ? max(0, (int) round((1 - (strlen($raw) / $orig_size)) * 100)) : 0;
        $upload_dir = wp_get_upload_dir();
        $t0_ms     = (int) get_transient('wpc_v2_t0_ms_' . $imageID);
        $entry_ms  = (int) round($entry_t * 1000);
        $from_click_ms = ($t0_ms > 0) ? max(0, $entry_ms - $t0_ms) : 0;

        $entry = [
            'size'              => strlen($raw),
            'originalSize'      => $orig_size,
            'url'               => $upload_dir['baseurl'] . '/' . ltrim(str_replace($upload_dir['basedir'], '', $dest), '/'),
            'local'             => true,
            'skipped'           => false,
            'savings'           => $savings,
            'bg_upgraded'       => time(),
            
            
            'bg_upgraded_ms'    => (int) round(microtime(true) * 1000),
            'bg_t_from_click_ms' => $from_click_ms,
            'kb_reported'       => $kb,
            'butter'            => $butter,
            'phase_b_v2'        => true,
            'phase_b_batch'     => true,
        ];
        if (isset($v['q']))      $entry['q']      = (int) $v['q'];
        if (isset($v['bumped'])) $entry['bumped'] = sanitize_text_field((string) $v['bumped']);
        if (isset($v['telemetry']) && is_array($v['telemetry'])) {
            $entry['telemetry'] = $v['telemetry'];
        }

        $variant_key = wpc_v2_variant_key($sz, $fmt);
        $entries_to_merge[$variant_key] = $entry;


        if ($use_journal) {
            $je = [
                'sizeLabel'    => $sz,
                'format'       => $fmt,
                'type'         => 'persisted',
                'originalSize' => $orig_size,
                'bytes_size'   => strlen($raw),
                'bytes_path'   => $dest,
                'ms'           => (int) round(microtime(true) * 1000),
                'kb'           => $kb,
                'butter'       => $butter,
            ];
            if (isset($v['q']))      $je['q']      = (int) $v['q'];
            if (isset($v['bumped'])) $je['bumped'] = sanitize_text_field((string) $v['bumped']);
            $journal_entries[] = $je;
        }

        if (wpc_v2_remove_pending($imageID, $sz, $fmt)) {
            $any_drain_complete = true;
        }


        if ($orig_size > 0 && $savings > 0) {
            $cur_savings = (float) get_post_meta($imageID, 'ic_savings', true);
            if ((float) $savings > $cur_savings) {
                $opt_bytes = strlen($raw);
                update_post_meta($imageID, 'ic_savings',          round((float) $savings, 1));
                update_post_meta($imageID, 'ic_savings_format',   $fmt);
                update_post_meta($imageID, 'ic_savings_bytes',    max(0, $orig_size - $opt_bytes));
                update_post_meta($imageID, 'ic_savings_baseline', $orig_size);
            }
        }

        $results[] = ['ok' => true, 'kind' => 'persisted', 'sizeLabel' => $sz, 'format' => $fmt, 'bytes' => strlen($raw)];
        $persisted_count++;
    }

    $t_after_loop = microtime(true);


    $journal_written = false;
    if ($use_journal && !empty($journal_entries)
        && function_exists('wpc_v2_journal_write_batch')) {
        $journal_written = wpc_v2_journal_write_batch($imageID, $jobId, $journal_entries, $flushReason);
        if ($journal_written) {


            if (function_exists('wpc_v2_journal_fire_loopback_fast')) {
                wpc_v2_journal_fire_loopback_fast();
            }
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                if (function_exists('wpc_v2_journal_drain_run')) {


                    wpc_v2_journal_drain_run();
                }
            });
        }
    }
    if (!$journal_written && !empty($entries_to_merge)) {
        wpc_v2_merge_variants_batch($imageID, $entries_to_merge);
    }


    if ($use_journal && !$journal_written && !empty($journal_entries)) {
        foreach ($journal_entries as $je) {
            if (($je['type'] ?? '') === 'no_improvement') {
                wpc_v2_record_no_improvement(
                    $imageID,
                    (string) $je['sizeLabel'],
                    (string) $je['format'],
                    (string) ($je['reason'] ?? 'no_improvement'),
                    ['baselineKb' => (float) ($je['baselineKb'] ?? 0)]
                );
            }
        }
    }
    $t_after_merge = microtime(true);


    if ($persisted_count > 0) {
        $ic_compressing = get_post_meta($imageID, 'ic_compressing', true);
        $current_status = (is_array($ic_compressing) && !empty($ic_compressing['status']))
            ? (string) $ic_compressing['status']
            : 'optimizing';
        $eager = function_exists('wpc_v2_use_eager_compressed_flip')
            && wpc_v2_use_eager_compressed_flip();
        if ($eager && $current_status !== 'compressed') {
            wpc_v2_ic_compressing_set_status($imageID, 'compressed');


            delete_transient('wps_ic_compress_' . $imageID);
            error_log(sprintf(
                '[WPC V2BgSwap eager_flip] imageID=%d batched first-variant flip (batch_size=%d job=%s)',
                $imageID,
                $persisted_count,
                $jobId !== '' ? substr($jobId, 0, 8) : '-'
            ));
        }
        
        
        if (get_transient('wpc_v2_warming_' . $imageID) !== false) {
            delete_transient('wpc_v2_warming_' . $imageID);
        }
    }


    $should_recompute = $any_drain_complete
        || $flushReason === 'all_in';
    if (!$should_recompute && $persisted_count > 0) {
        $pending_now = get_transient('wpc_v2_pending_' . $imageID);
        if (!is_array($pending_now) || empty($pending_now['pending'])) {
            $should_recompute = true;
        }
    }


    if ($journal_written) {
        $should_recompute = false;
    }


    if ($should_recompute) {
        $imageID_for_shutdown = $imageID;
        add_action('shutdown', function () use ($imageID_for_shutdown) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            wpc_v2_recompute_savings($imageID_for_shutdown);
        }, 0);
    } else if (function_exists('fastcgi_finish_request')) {


        add_action('shutdown', function () { fastcgi_finish_request(); }, 0);
    }

    $t_handler_end = microtime(true);
    $total_ms      = ($t_handler_end - $entry_t) * 1000;
    $bootstrap_ms  = 0; 


    $validate_ms   = ($t_after_validate - $entry_t) * 1000;
    $loop_ms       = ($t_after_loop - $t_after_validate) * 1000;
    $merge_ms      = ($t_after_merge - $t_after_loop) * 1000;
    $recompute_ms  = ($t_handler_end - $t_after_merge) * 1000;

    
    $inbound_to_complete_ms = ($t_handler_end - $request_arrival_t) * 1000;


    error_log(sprintf(
        '[wpc_v2_bg_swap_batch_timing] imageID=%d jobId=%s variant_count=%d persisted=%d rejected=%d duplicates=%d drain_complete=%s flush_reason=%s clock_skew_ms=%s bootstrap_skip=%s validate_ms=%.1f loop_ms=%.1f merge_ms=%.1f recompute_ms=%.1f total_handler_ms=%.1f inbound_to_complete_ms=%.1f cap=%d journal=%s journal_entries=%d pull_ms=%.1f pulled=%d pending_pull=%d',
        $imageID,
        $jobId !== '' ? substr($jobId, 0, 8) : '-',
        $variant_n,
        $persisted_count,
        $rejected_count,
        $duplicate_count,
        $any_drain_complete ? 'yes' : 'no',
        $flushReason !== '' ? $flushReason : '-',
        $clockSkewMs === null ? 'n/a' : (string) $clockSkewMs,
        $bootstrap_skip ? 'yes' : 'NO',
        max(0.0, $validate_ms),
        max(0.0, $loop_ms),
        max(0.0, $merge_ms),
        max(0.0, $recompute_ms),
        max(0.0, $total_ms),
        max(0.0, $inbound_to_complete_ms),
        function_exists('wpc_v2_ac_get_cap') ? wpc_v2_ac_get_cap('batch') : 0,
        $journal_written ? 'yes' : ($use_journal ? 'fallback' : 'off'),
        count($journal_entries),
        max(0.0, $pull_ms),
        count($pulled_bytes),
        $pending_pull_count
    ));

    
    if (function_exists('wpc_v2_record_handler_timing')) {
        wpc_v2_record_handler_timing('batch', max(0.0, $inbound_to_complete_ms));
    }


    if (function_exists('wpc_v2_telemetry_record')) {
        $queue_wait_ms = (int) round(max(0.0, ($entry_t - $request_arrival_t) * 1000));
        $work_ms       = (int) round(max(0.0, ($t_handler_end - $entry_t) * 1000));
        wpc_v2_telemetry_record('batch', (int) round(max(0.0, $inbound_to_complete_ms)), [
            'image_id'      => $imageID,
            'variant_count' => $variant_n,
            'persisted'     => $persisted_count,
            'queue_wait_ms' => $queue_wait_ms,
            'work_ms'       => $work_ms,
            'flush_reason'  => $flushReason,
        ]);
    }

    return wpc_v2_respond(200, [
        'ok'      => true,
        'imageID' => $imageID,
        'jobId'   => $jobId,
        'summary' => [
            'persisted'  => $persisted_count,
            'rejected'   => $rejected_count,
            'duplicates' => $duplicate_count,
        ],
        'results' => $results,
    ]);
}






function wpc_v2_merge_variants_batch($imageID, array $entries)
{
    if (empty($entries)) return;

    global $wpdb;
    $lock_name = 'wpc_bg_meta_' . (int) $imageID;
    
    
    $got_lock = wpc_worker_lock($lock_name);
    if (!$got_lock) {
        error_log(sprintf('[WPC V2] wpc_v2_merge_variants_batch lock_unavailable imageID=%d entries=%d — proceeding unlocked (race possible)', (int) $imageID, count($entries)));
    }

    try {


        wp_cache_delete($imageID, 'post_meta');
        $existing = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($existing)) $existing = [];
        foreach ($entries as $variant_key => $entry) {
            $existing[$variant_key] = array_merge($existing[$variant_key] ?? [], $entry);
        }
        update_post_meta($imageID, 'ic_local_variants', $existing);
    } finally {
        if ($got_lock) {
            wpc_worker_unlock($lock_name);
        }
    }
}


function wpc_v2_register_announce_route()
{
    register_rest_route('wpc/v2', '/bg_swap_announce', [
        'methods'             => 'POST',
        'callback'            => 'wpc_v2_handle_bg_swap_announce',
        'permission_callback' => '__return_true', 
    ]);
}
add_action('rest_api_init', 'wpc_v2_register_announce_route');


function wpc_v2_handle_bg_swap_announce(WP_REST_Request $request)
{
    $entry_t  = microtime(true);
    

    $request_arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : $entry_t;
    $body_raw = $request->get_body();

    
    $bootstrap_skip = (defined('WPC_IS_BG_SWAP') && WPC_IS_BG_SWAP);

    
    $sig_header = (string) $request->get_header('x_wpc_sig');
    $hmac_check = wpc_v2_verify_hmac($sig_header, (string) $body_raw);
    if (!$hmac_check['ok']) {
        error_log('[WPC V2BgSwapAnnounce] auth_rejected reason=' . $hmac_check['reason']);
        return wpc_v2_respond(401, ['error' => 'auth', 'reason' => $hmac_check['reason']]);
    }

    $body = json_decode($body_raw, true);
    if (!is_array($body)) {
        return wpc_v2_respond(400, ['error' => 'invalid_json']);
    }
    if (empty($body['items']) || !is_array($body['items'])) {
        return wpc_v2_respond(400, ['error' => 'missing_items']);
    }
    $items   = $body['items'];
    $item_n  = count($items);
    if ($item_n > 25) {
        return wpc_v2_respond(413, ['error' => 'batch_too_large', 'max' => 25, 'got' => $item_n]);
    }

    $imageID = isset($body['imageID']) ? (int) $body['imageID'] : 0;
    if (!$imageID || get_post_type($imageID) !== 'attachment') {
        return wpc_v2_respond(410, ['error' => 'unknown_image', 'imageID' => $imageID]);
    }
    $jobId       = isset($body['jobId']) ? (string) $body['jobId'] : '';
    $serverTime  = isset($body['serverTime']) ? (int) $body['serverTime'] : 0;
    $clockSkewMs = $serverTime > 0 ? (int) round(($entry_t * 1000) - $serverTime) : null;


    if (get_transient('wpc_v2_callbacks_blocked_' . $imageID) !== false) {
        error_log(sprintf(
            '[WPC V2BgSwapAnnounce restored_reject] imageID=%d items=%d job=%s',
            $imageID, $item_n,
            $jobId !== '' ? substr($jobId, 0, 8) : '-'
        ));
        return wpc_v2_respond(410, ['error' => 'image_restored', 'imageID' => $imageID]);
    }

    
    if ($jobId !== '') {
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (is_array($pending) && !empty($pending['jobId']) && (string) $pending['jobId'] !== $jobId) {
            error_log(sprintf(
                '[WPC V2BgSwapAnnounce stale_job] imageID=%d announce_job=%s pending_job=%s items=%d',
                $imageID, substr($jobId, 0, 8), substr((string) $pending['jobId'], 0, 8), $item_n
            ));
            return wpc_v2_respond(410, ['error' => 'stale_job', 'cb_jobId' => $jobId, 'pending_jobId' => (string) $pending['jobId']]);
        }
    }


    $persisted = get_post_meta($imageID, 'ic_local_variants', true);
    if (!is_array($persisted)) $persisted = [];


    $announced = get_transient('wpc_v2_announced_' . $imageID);
    if (!is_array($announced)) $announced = [];

    $now_ms  = (int) round(microtime(true) * 1000);
    $now_s   = time();
    $results = [];
    $announced_count = $discarded_count = $rejected_count = 0;

    foreach ($items as $idx => $item) {
        if (!is_array($item)) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'malformed_item', 'index' => $idx];
            $rejected_count++;
            continue;
        }
        $sz  = isset($item['sizeLabel']) ? sanitize_key((string) $item['sizeLabel']) : '';
        $fmt = isset($item['format']) ? strtolower(sanitize_key((string) $item['format'])) : '';
        if ($sz === '' || $fmt === '') {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'missing_size_or_format', 'index' => $idx];
            $rejected_count++;
            continue;
        }
        if (!in_array($fmt, ['jpeg', 'jpg', 'webp', 'avif'], true)) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_format', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
        if ($fmt === 'jpg') $fmt = 'jpeg';


        $item_ok      = isset($item['ok']) ? (bool) $item['ok'] : true;
        $item_no_impr = !empty($item['noImprovement']);
        if (!$item_ok && !$item_no_impr) {
            error_log(sprintf(
                '[WPC V2BgSwapAnnounce spec_violation] imageID=%d job=%s sizeLabel=%s format=%s ok=false_without_noImprovement',
                $imageID, $jobId !== '' ? substr($jobId, 0, 8) : '-', $sz, $fmt
            ));
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'spec_violation_ok_false_no_no_improvement', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }

        $variant_key = wpc_v2_variant_key($sz, $fmt);


        if (isset($persisted[$variant_key])) {
            $results[] = ['ok' => true, 'kind' => 'discarded_already_persisted', 'sizeLabel' => $sz, 'format' => $fmt];
            $discarded_count++;
            continue;
        }


        $orig_size  = isset($item['originalSize']) ? (int) $item['originalSize'] : 0;
        $kb         = isset($item['kb']) ? (float) $item['kb'] : 0.0;
        $bytes_est  = (int) round($kb * 1024);
        $savings    = ($orig_size > 0 && $bytes_est > 0)
                      ? max(0, (int) round((1 - ($bytes_est / $orig_size)) * 100))
                      : 0;
        $item_ms    = isset($item['ms']) ? (int) $item['ms'] : $now_ms;
        $no_improv  = !empty($item['noImprovement']);

        $announced[$variant_key] = [
            'sizeLabel'      => $sz,
            'format'         => $fmt,
            'kb'             => $kb,
            'originalSize'   => $orig_size,
            'bytes_est'      => $bytes_est,
            'savings'        => $savings,
            'noImprovement'  => $no_improv,
            'reason'         => isset($item['reason']) ? sanitize_text_field((string) $item['reason']) : '',
            'announced_ms'   => $item_ms,


            'expires_at'     => $now_s + 30,
        ];
        $results[] = [
            'ok'        => true,
            'kind'      => $no_improv ? 'announced_no_improvement' : 'announced',
            'sizeLabel' => $sz,
            'format'    => $fmt,
        ];
        $announced_count++;
    }


    $eager_flip_done = false;
    if ($announced_count > 0
        && function_exists('wpc_v2_use_eager_compressed_flip')
        && wpc_v2_use_eager_compressed_flip()) {
        $ic_compressing = get_post_meta($imageID, 'ic_compressing', true);
        $current_status = (is_array($ic_compressing) && !empty($ic_compressing['status']))
            ? (string) $ic_compressing['status']
            : 'optimizing';


        $best_announced = null;
        foreach ($announced as $a_vkey => $a_entry) {
            if (!empty($a_entry['noImprovement'])) continue;
            $a_sv = isset($a_entry['savings']) ? (float) $a_entry['savings'] : 0.0;
            $a_orig = isset($a_entry['originalSize']) ? (int) $a_entry['originalSize'] : 0;
            if ($a_sv <= 0 || $a_orig <= 0) continue;
            if ($best_announced === null || $a_sv > $best_announced['sv']) {
                $best_announced = [
                    'sv'    => $a_sv,
                    'fmt'   => isset($a_entry['format']) ? (string) $a_entry['format'] : '',
                    'orig'  => $a_orig,
                    'bytes' => isset($a_entry['bytes_est']) ? (int) $a_entry['bytes_est'] : 0,
                ];
            }
        }
        
        
        if ($best_announced !== null) {
            $cur_savings = (float) get_post_meta($imageID, 'ic_savings', true);
            if ($best_announced['sv'] > $cur_savings) {
                update_post_meta($imageID, 'ic_savings',          round($best_announced['sv'], 1));
                update_post_meta($imageID, 'ic_savings_format',   $best_announced['fmt']);
                update_post_meta($imageID, 'ic_savings_bytes',    max(0, $best_announced['orig'] - $best_announced['bytes']));
                update_post_meta($imageID, 'ic_savings_baseline', $best_announced['orig']);
            }
        }


        if ($current_status !== 'compressed') {
            wpc_v2_ic_compressing_set_status($imageID, 'compressed');
            delete_transient('wps_ic_compress_' . $imageID);
            set_transient('wpc_v2_warming_' . $imageID, 1, 90);
            $eager_flip_done = true;
            error_log(sprintf(
                '[WPC V2BgSwapAnnounce eager_flip] imageID=%d announce-driven first-flip (items=%d job=%s)',
                $imageID, $announced_count,
                $jobId !== '' ? substr($jobId, 0, 8) : '-'
            ));
        }
    }

    
    
    set_transient('wpc_v2_announced_' . $imageID, $announced, 5 * MINUTE_IN_SECONDS);


    if (function_exists('fastcgi_finish_request')) {
        add_action('shutdown', function () { fastcgi_finish_request(); }, 0);
    }

    $t_handler_end = microtime(true);
    $total_ms = ($t_handler_end - $entry_t) * 1000;
    
    $inbound_to_complete_ms = ($t_handler_end - $request_arrival_t) * 1000;


    error_log(sprintf(
        '[wpc_v2_bg_swap_announce_timing] imageID=%d jobId=%s item_count=%d announced=%d discarded=%d rejected=%d clock_skew_ms=%s bootstrap_skip=%s total_handler_ms=%.1f inbound_to_complete_ms=%.1f cap=%d',
        $imageID,
        $jobId !== '' ? substr($jobId, 0, 8) : '-',
        $item_n,
        $announced_count,
        $discarded_count,
        $rejected_count,
        $clockSkewMs === null ? 'n/a' : (string) $clockSkewMs,
        $bootstrap_skip ? 'yes' : 'NO',
        max(0.0, $total_ms),
        max(0.0, $inbound_to_complete_ms),
        function_exists('wpc_v2_ac_get_cap') ? wpc_v2_ac_get_cap('announce') : 0
    ));

    
    if (function_exists('wpc_v2_record_handler_timing')) {
        wpc_v2_record_handler_timing('announce', max(0.0, $inbound_to_complete_ms));
    }

    return wpc_v2_respond(200, [
        'ok'      => true,
        'imageID' => $imageID,
        'jobId'   => $jobId,
        'summary' => [
            'announced' => $announced_count,
            'discarded' => $discarded_count,
            'rejected'  => $rejected_count,
        ],
        'results' => $results,
    ]);
}


function wpc_v2_resolve_imageid_from_origin_path($origin_path)
{
    $origin_path = ltrim((string) $origin_path, '/');
    if ($origin_path === '') return 0;

    
    $origin_path = (string) strtok($origin_path, '?#');

    
    $cache_key = 'wpc_v2_path2id_' . md5($origin_path);
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        return (int) $cached;
    }

    global $wpdb;
    $id = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta}
         WHERE meta_key = '_wp_attached_file' AND meta_value = %s
         LIMIT 1",
        $origin_path
    ));

    
    
    if ($id <= 0 && strpos($origin_path, '-scaled.') !== false) {
        $unscaled = preg_replace('/-scaled(\.[^.]+)$/', '$1', $origin_path);
        $id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_wp_attached_file' AND meta_value = %s
             LIMIT 1",
            $unscaled
        ));
    }

    set_transient($cache_key, $id, HOUR_IN_SECONDS);
    return (int) $id;
}


function wpc_v2_resolve_sizelabel_from_dims($imageID, $width, $height, $requested_path = '')
{
    $width = (int) $width;
    $height = (int) $height;

    
    if ($requested_path !== '' && preg_match('/-scaled\.[^.]+$/i', $requested_path)) {
        return 'scaled';
    }

    $meta = wp_get_attachment_metadata($imageID);
    if (empty($meta['sizes']) || !is_array($meta['sizes'])) {
        
        if ((int) ($meta['width'] ?? 0) === $width && (int) ($meta['height'] ?? 0) === $height) {
            return 'original';
        }
        return 'lazy-' . $width . 'x' . $height;
    }

    
    $matches = [];
    foreach ($meta['sizes'] as $label => $info) {
        if ((int) ($info['width'] ?? 0) === $width && (int) ($info['height'] ?? 0) === $height) {
            $matches[$label] = $info;
        }
    }
    if (count($matches) === 1) {
        return (string) array_key_first($matches);
    }

    
    if (count($matches) > 1 && $requested_path !== '') {
        $req_base = pathinfo($requested_path, PATHINFO_FILENAME);
        foreach ($matches as $label => $info) {
            $size_base = pathinfo($info['file'] ?? '', PATHINFO_FILENAME);
            if ($size_base !== '' && strpos($req_base, $size_base) !== false) {
                return $label;
            }
        }
        return (string) array_key_first($matches);
    }

    
    if ((int) ($meta['width'] ?? 0) === $width && (int) ($meta['height'] ?? 0) === $height) {
        return 'original';
    }

    
    return 'lazy-' . $width . 'x' . $height;
}





function wpc_v2_record_lazy_cdn_outcome($outcome, $arrival_t, array $meta = [])
{
    if (!function_exists('wpc_v2_telemetry_record')) return;
    $ms = (int) round((microtime(true) - $arrival_t) * 1000);
    wpc_v2_telemetry_record('lazy_cdn_callback', $ms, array_merge(['outcome' => $outcome], $meta));
}


function wpc_v2_handle_bg_swap_single(WP_REST_Request $request)
{
    $arrival_t = isset($_SERVER['REQUEST_TIME_FLOAT'])
        ? (float) $_SERVER['REQUEST_TIME_FLOAT']
        : microtime(true);

    $body_raw = $request->get_body();
    $sig_header = (string) $request->get_header('x_wpc_sig');
    $hmac_check = wpc_v2_verify_hmac($sig_header, (string) $body_raw, 60, 'write');
    if (!$hmac_check['ok']) {
        wpc_v2_record_lazy_cdn_outcome('auth_rejected_' . $hmac_check['reason'], $arrival_t, []);
        error_log('[WPC LazyCDNCallback] auth_rejected reason=' . $hmac_check['reason']);
        return wpc_v2_respond(401, ['error' => 'auth', 'reason' => $hmac_check['reason']]);
    }

    $body = json_decode($body_raw, true);
    if (!is_array($body)) {
        wpc_v2_record_lazy_cdn_outcome('invalid_json', $arrival_t, []);
        return wpc_v2_respond(400, ['error' => 'invalid_json']);
    }

    $origin_path    = isset($body['origin_path'])    ? (string) $body['origin_path']    : '';
    $requested_path = isset($body['requested_path']) ? (string) $body['requested_path'] : '';
    $width          = isset($body['width'])          ? (int) $body['width']             : 0;
    $height         = isset($body['height'])         ? (int) $body['height']            : 0;
    $format         = isset($body['format'])         ? sanitize_key((string) $body['format']) : '';
    $bytes_url      = isset($body['bytes_url'])      ? (string) $body['bytes_url']      : '';
    $original_size  = isset($body['original_size'])  ? (int) $body['original_size']     : 0;

    
    if ($format === 'jpg') $format = 'jpeg';

    
    if ($origin_path === '' || $width <= 0 || $height <= 0 || $bytes_url === ''
        || !in_array($format, ['jpeg', 'webp', 'avif', 'png'], true)) {
        wpc_v2_record_lazy_cdn_outcome('missing_fields', $arrival_t, [
            'origin_path' => $origin_path, 'format' => $format,
            'width' => $width, 'height' => $height,
        ]);
        return wpc_v2_respond(400, ['error' => 'missing_required_fields']);
    }

    
    if (strpos($origin_path, '..') !== false
        || strpos($origin_path, "\0") !== false
        || strpos($origin_path, '://') !== false
        || strpos($origin_path, '\\') !== false
        || preg_match('#^/#', $origin_path)) {
        wpc_v2_record_lazy_cdn_outcome('path_traversal_rejected', $arrival_t, ['origin_path' => $origin_path]);
        error_log('[WPC LazyCDNCallback] path_traversal_rejected origin_path=' . $origin_path);
        return wpc_v2_respond(400, ['error' => 'invalid_origin_path']);
    }


    $resolution_start = microtime(true);
    $imageID = 0;
    $imageID_source = 'fallback';
    $hinted_imageID = isset($body['image_id']) ? (int) $body['image_id'] : 0;
    if ($hinted_imageID > 0) {
        $hinted_post = get_post($hinted_imageID);
        if ($hinted_post instanceof WP_Post
            && $hinted_post->post_type === 'attachment'
            && $hinted_post->post_status !== 'trash') {
            $imageID = $hinted_imageID;
            $imageID_source = 'ls_hint';
        }
    }
    if ($imageID <= 0) {
        $imageID = wpc_v2_resolve_imageid_from_origin_path($origin_path);
    }
    if ($imageID <= 0) {
        wpc_v2_record_lazy_cdn_outcome('image_id_unresolved', $arrival_t, [
            'origin_path'    => $origin_path,
            'hinted'         => $hinted_imageID,
            'imageID_source' => $imageID_source,
        ]);
        
        return wpc_v2_respond(410, ['error' => 'unknown_image', 'origin_path' => $origin_path]);
    }

    
    $sizeLabel = wpc_v2_resolve_sizelabel_from_dims($imageID, $width, $height, $requested_path);
    $resolution_ms = (int) round((microtime(true) - $resolution_start) * 1000);

    
    $variant_key = ($format === 'jpeg') ? $sizeLabel : ($sizeLabel . '-' . $format);
    $existing = get_post_meta($imageID, 'ic_local_variants', true);
    if (is_array($existing) && !empty($existing[$variant_key]['bg_upgraded'])) {
        wpc_v2_record_lazy_cdn_outcome('already_present', $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
            'size_label' => $sizeLabel, 'format' => $format,
        ]);
        return wpc_v2_respond(200, ['ok' => true, 'already_present' => true,
            'imageID' => $imageID, 'sizeLabel' => $sizeLabel, 'format' => $format]);
    }

    
    $pull_start = microtime(true);
    $resp = wp_remote_get($bytes_url, [
        'timeout'   => 30,
        'sslverify' => apply_filters('https_local_ssl_verify', false),
    ]);
    $pull_ms = (int) round((microtime(true) - $pull_start) * 1000);
    if (is_wp_error($resp)) {
        wpc_v2_record_lazy_cdn_outcome('pull_failed_transport', $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
            'pull_ms' => $pull_ms, 'error' => $resp->get_error_message(),
        ]);
        return wpc_v2_respond(503, ['error' => 'pull_failed', 'retry_after' => 30]);
    }
    $http_code = (int) wp_remote_retrieve_response_code($resp);
    if ($http_code !== 200) {
        wpc_v2_record_lazy_cdn_outcome('pull_failed_http_' . $http_code, $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
            'pull_ms' => $pull_ms, 'http_code' => $http_code,
        ]);
        return wpc_v2_respond(503, ['error' => 'pull_failed', 'http_code' => $http_code]);
    }
    $bytes = (string) wp_remote_retrieve_body($resp);
    $bytes_size = strlen($bytes);
    if ($bytes_size <= 0) {
        wpc_v2_record_lazy_cdn_outcome('pull_failed_empty', $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
        ]);
        return wpc_v2_respond(503, ['error' => 'empty_body']);
    }

    
    $original_filename = basename($origin_path);
    $base = preg_replace('/\.[^.]+$/', '', $original_filename);
    
    $base = preg_replace('/-scaled$/', '', $base);
    $ext = ($format === 'jpeg') ? 'jpg' : $format;
    if (!preg_match('/^(webp|avif|jpe?g|png|gif)$/i', (string) $ext)) {
        return wpc_v2_respond(400, ['error' => 'bad_format']);
    }
    
    if ($sizeLabel === 'original') {
        $output_filename = $base . '.' . $ext;
    } elseif ($sizeLabel === 'scaled') {
        $output_filename = $base . '-scaled.' . $ext;
    } else {
        $output_filename = $base . '-' . $width . 'x' . $height . '.' . $ext;
    }

    
    $upload_dir = wp_get_upload_dir();
    $rel_dir = dirname($origin_path);
    $rel_dir = ($rel_dir === '.' || $rel_dir === '') ? '' : trailingslashit($rel_dir);
    $output_path = trailingslashit($upload_dir['basedir']) . $rel_dir . $output_filename;
    $output_url  = trailingslashit($upload_dir['baseurl'])  . $rel_dir . $output_filename;

    
    $write_start = microtime(true);
    $wpc_put655 = wpc_v2_store_bytes655($bytes, $output_path);
    if (empty($wpc_put655['ok'])) {
        wpc_v2_record_lazy_cdn_outcome('disk_' . (string) $wpc_put655['error'], $arrival_t, [
            'image_id' => $imageID, 'imageID_source' => $imageID_source,
            'output_path' => $output_path,
        ]);
        error_log('[WPC LazyCDNCallback] ' . (string) $wpc_put655['error'] . ' imageID=' . $imageID . ' path=' . $output_path);
        return wpc_v2_respond(503, ['error' => 'disk_' . (string) $wpc_put655['error']]);
    }
    $write_ms = (int) round((microtime(true) - $write_start) * 1000);

    
    if (function_exists('wpc_v2_merge_variant')) {
        wpc_v2_merge_variant($imageID, $variant_key, [
            'sizeLabel'      => $sizeLabel,
            'format'         => $format,
            'size'           => $bytes_size,
            'originalSize'   => $original_size > 0 ? $original_size : null,
            'savings'        => ($original_size > 0)
                                  ? max(0, (int) round((1 - ($bytes_size / $original_size)) * 100))
                                  : 0,
            'bg_upgraded'    => 'lazy_cdn',
            'bg_upgraded_ms' => (int) round(microtime(true) * 1000),
        ]);
    }


    wpc_v2_record_lazy_cdn_outcome('success', $arrival_t, [
        'image_id'       => $imageID,
        'imageID_source' => $imageID_source,
        'size_label'     => $sizeLabel,
        'format'         => $format,
        'bytes_size'     => $bytes_size,
        'original_size'  => $original_size,
        'resolution_ms'  => $resolution_ms,
        'pull_ms'        => $pull_ms,
        'write_ms'       => $write_ms,
    ]);

    return wpc_v2_respond(200, [
        'ok'         => true,
        'imageID'    => $imageID,
        'sizeLabel'  => $sizeLabel,
        'format'     => $format,
        'bytes_size' => $bytes_size,
        'url'        => $output_url,
    ]);
}


add_action('delete_attachment', function ($attachment_id) {
    $file = get_post_meta($attachment_id, '_wp_attached_file', true);
    if ($file) {
        delete_transient('wpc_v2_path2id_' . md5(ltrim((string) $file, '/')));
    }
});

}
