<?php


if (!defined('ABSPATH')) {
    exit;
}


if (!function_exists('wpc_v2_normalize_quality')) {
    function wpc_v2_normalize_quality($raw)
    {
        switch (strtolower(trim((string) $raw))) {
            case 'lossless':
            case 'l':
                return 'lossless';
            case 'ultra':
            case 'u':
            case 'maximum':
                return 'ultra';
            case 'intelligent':
            case 'i':
            default:
                return 'intelligent';
        }
    }
}


if (!function_exists('wpc_v2_local_image_config')) {
    function wpc_v2_local_image_config()
    {
        $settings = get_option(WPS_IC_SETTINGS);
        if (!is_array($settings)) {
            $settings = [];
        }

        $quality = wpc_v2_normalize_quality($settings['optimization'] ?? 'intelligent');

        $max_width = (int) ($settings['maxWidth'] ?? 0);
        if ($max_width <= 0) {
            $max_width = 2560;
        }

        return [
            'local_quality'   => $quality,
            'local_max_width' => $max_width,
        ];
    }
}

if (!function_exists('wpc_v2_delivery_config')) {

    function wpc_v2_delivery_config()
    {
        $settings = get_option(WPS_IC_SETTINGS);
        if (!is_array($settings)) {
            $settings = [];
        }

        $natural = (class_exists('WPC_Negotiated_Delivery')
            && method_exists('WPC_Negotiated_Delivery', 'emission_ready')
            && WPC_Negotiated_Delivery::emission_ready());

        $hl_mode = isset($settings['host_lock_mode']) ? sanitize_key((string) $settings['host_lock_mode']) : 'off';
        if (!in_array($hl_mode, ['off', 'lock', 'allow'], true)) {
            $hl_mode = 'off';
        }
        $hl_allow = isset($settings['host_lock_allow']) ? sanitize_text_field((string) $settings['host_lock_allow']) : '';


        $edge_origin = !empty($settings['wpc_edge_origin_bytes']) && (string) $settings['wpc_edge_origin_bytes'] === '1';
        $has_zone    = function_exists('get_option')
                       && (!empty(get_option('ic_cdn_zone_name')) || !empty(get_option('ic_custom_cname')));
        $redirect_target = ($edge_origin && $has_zone) ? 'origin' : 'samehost';


        $nextgen = isset($settings['wpc_nextgen']) ? (string) $settings['wpc_nextgen'] : 'auto';
        if (!in_array($nextgen, ['auto', 'webp', 'off'], true)) $nextgen = 'auto';
        $images_on = !class_exists('WPC_Negotiated_Delivery')
            || !method_exists('WPC_Negotiated_Delivery', 'cdn_images_enabled')
            || WPC_Negotiated_Delivery::cdn_images_enabled($settings);
        $tier = '';
        if (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'resolve_verbose')) {
            $rv_dc = WPC_Delivery_Resolver::resolve_verbose();
            $tier  = isset($rv_dc['tier_name']) ? (string) $rv_dc['tier_name'] : '';
        }
        if (!empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR'])) {
            update_option('wpc_v2_cf_seen_ts', time(), false);
        }
        $cf_seen_ts  = (int) get_option('wpc_v2_cf_seen_ts', 0);
        $cf_detected = $cf_seen_ts > 0 && (time() - $cf_seen_ts) < 7 * DAY_IN_SECONDS;


        $hints = wpc_v2_compute_probe_hints();


        $cfc = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME)) : '';
        $cfs = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
        if ($cfc !== '' && is_array($cfs) && !empty($cfs['settings']['cdn'])) {
            $cname = $cfc;                                   
        } else {
            $cname = trim((string) get_option('ic_custom_cname'));
        }


        $opt_mode = function_exists('get_option') ? (string) get_option('wpc_optimization_mode', '') : '';
        $writes_variants = in_array($opt_mode, ['lazy_cdn', 'local'], true);

        return [
            'site_url'        => function_exists('site_url') ? site_url() : '',
            'cname'           => $cname,
            'delivery_mode'   => $natural ? 'natural_url' : 'transform_url',
            'writes_variants' => $writes_variants,
            'host_lock_mode'  => $hl_mode,
            'host_lock_allow' => $hl_allow,
            'redirect_target' => $redirect_target,
            'nextgen'         => $nextgen,
            'tier'            => $tier,
            'images_on'       => (bool) $images_on,  
            'cf_detected'     => (bool) $cf_detected, 
            'srcx'            => $hints['srcx'],
            'wpsz'            => $hints['wpsz'],
            'lzf'             => $hints['lzf'],
        ];
    }
}

if (!function_exists('wpc_v2_compute_probe_hints')) {
    




    function wpc_v2_compute_probe_hints()
    {


        $widths = [];
        if (function_exists('wp_get_registered_image_subsizes')) {
            foreach ((array) wp_get_registered_image_subsizes() as $sz) {
                if (!empty($sz['width'])) $widths[] = (int) $sz['width'];
            }
        } elseif (function_exists('get_intermediate_image_sizes')) {
            
            global $_wp_additional_image_sizes;
            foreach ((array) get_intermediate_image_sizes() as $name) {
                $w = (int) get_option($name . '_size_w');
                if ($w <= 0 && isset($_wp_additional_image_sizes[$name]['width'])) {
                    $w = (int) $_wp_additional_image_sizes[$name]['width'];
                }
                if ($w > 0) $widths[] = $w;
            }
        }
        $widths = array_values(array_unique(array_filter($widths)));
        sort($widths, SORT_NUMERIC);


        $srcx = 'jpg,png';
        if (function_exists('wp_get_upload_dir') && function_exists('home_url')) {
            $ud = wp_get_upload_dir();
            $up_host  = !empty($ud['baseurl']) ? wp_parse_url($ud['baseurl'], PHP_URL_HOST) : '';
            $org_host = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($up_host && $org_host && strcasecmp($up_host, $org_host) !== 0) {
                $srcx = '';
            }
        }


        $set = function_exists('get_option') && defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
        $set = is_array($set) ? $set : [];
        $lzf = [];
        $formats_on = (string) get_option('wpc_envelope_formats_v2', '1') === '1';
        $images_on  = empty($set['serve']) || !is_array($set['serve'])
            ? false
            : (!empty($set['serve']['jpg']) || !empty($set['serve']['png']));
        if ($formats_on && $images_on) {
            $lzf[] = 'webp';

            $ceiling = '';
            if (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'effective_ceiling')) {
                $ceiling = (string) WPC_Delivery_Resolver::effective_ceiling($set);
            }
            if ($ceiling !== 'webp') $lzf[] = 'avif';
        }
        $lzf = array_values(array_unique($lzf));

        $out = [
            'srcx' => (string) $srcx,
            'wpsz' => implode(',', $widths),
            'lzf'  => implode(',', $lzf),
        ];
        $out = (array) apply_filters('wpc_v2_probe_hints', $out, $set);


        $srcx_cap = (int) apply_filters('wpc_v2_srcx_cap', 32);
        if (isset($out['srcx']) && strlen((string) $out['srcx']) > $srcx_cap) {
            $out['srcx'] = '';
        }
        return $out;
    }
}

if (!function_exists('wpc_v2_upload_base_paths')) {

    function wpc_v2_upload_base_paths()
    {
        $paths = [];
        if (function_exists('wp_get_upload_dir')) {
            $ud = wp_get_upload_dir();
            $baseurl = (is_array($ud) && !empty($ud['baseurl'])) ? (string) $ud['baseurl'] : '';
            if ($baseurl !== '' && function_exists('wp_parse_url')) {
                $p = (string) wp_parse_url($baseurl, PHP_URL_PATH);
                if ($p !== '') $paths[] = '/' . trim($p, '/');
            }
        }
        $paths[] = '/wp-content/uploads';
        $paths[] = '/storage';
        $paths = array_values(array_unique(array_filter($paths)));
        return (array) apply_filters('wpc_v2_upload_paths', $paths);
    }
}


if (!function_exists('wpc_v2_detect_host')) {
    function wpc_v2_detect_host()
    {
        if (class_exists('WpeCommon')) return 'wpengine';
        if (isset($GLOBALS['kinsta_cache'])) return 'kinsta';
        if (defined('FLYWHEEL_CONFIG_DIR')) return 'flywheel';
        if (function_exists('pantheon_wp_clear_edge_all')) return 'pantheon';
        if (class_exists('\\WPaas\\Plugin')) return 'godaddy';
        $ab = defined('ABSPATH') ? ABSPATH : '';
        $dr = isset($_SERVER['DOCUMENT_ROOT']) ? (string) $_SERVER['DOCUMENT_ROOT'] : '';
        if (strpos($ab, 'cloudwaysapps.com') !== false || strpos($dr, 'cloudwaysapps.com') !== false) return 'cloudways';
        if (function_exists('is_plugin_active') && is_plugin_active('sg-cachepress/sg-cachepress.php')) return 'siteground';
        return '';
    }
}


if (!function_exists('wpc_v2_report_lifecycle_event')) {
    function wpc_v2_report_lifecycle_event($event)
    {
        if ($event !== 'activated' && $event !== 'deactivated') return;
        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        if ($apikey === '' || $orch_url === '') return;

        $body_payload = ['apikey' => $apikey, 'event' => $event];
        if (defined('WPC_PLUGIN_VERSION')) {
            $body_payload['plugin_version'] = (string) WPC_PLUGIN_VERSION;
        }
        $body_raw = wp_json_encode($body_payload);
        if ($body_raw === false) return;
        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);

        

        wp_remote_post(rtrim($orch_url, '/') . '/v2/config', [
            'timeout'     => 2,
            'blocking'    => false,
            'redirection' => 0,
            'sslverify'   => true,
            'headers'     => [
                'Content-Type' => 'application/json',
                'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.05.1'),
            ],
            'body' => $body_raw,
        ]);
    }
}




if (defined('WPC_CC_PLUGIN_FILE')) {
    register_activation_hook(WPC_CC_PLUGIN_FILE, function () {
        wpc_v2_report_lifecycle_event('activated');
    });
    register_deactivation_hook(WPC_CC_PLUGIN_FILE, function () {
        wpc_v2_report_lifecycle_event('deactivated');
    });
}


if (!function_exists('wpc_v2_config_sync_zones')) {
    function wpc_v2_config_sync_zones(array $zones)
    {
        if (empty($zones)) {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'no_zones_provided'];
        }

        
        
        
        
        
        
        
        if (!(defined('DOING_CRON') && DOING_CRON)) {
            $wpc_defer517 = false;
            if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
                $wpc_act517 = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
                $wpc_defer517 = (strpos($wpc_act517, 'wps_ic_') !== 0 && strpos($wpc_act517, 'wpc_') !== 0);
            } else {
                
                
                
                
                
                
                $wpc_defer517 = empty($_REQUEST['wps_ic_nonce']);
            }
            if (!$wpc_defer517 && function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                $wpc_defer517 = true;
            }
            if ($wpc_defer517) {
                if (function_exists('wpc_v2_schedule_config_sync')) {
                    wpc_v2_schedule_config_sync();
                } elseif (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_v2_deferred_config_sync')) {
                    wp_schedule_single_event(time() + 5, 'wpc_v2_deferred_config_sync');
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('config-sync-deferred', '', '', [
                        'why' => (function_exists('wp_doing_ajax') && wp_doing_ajax()) ? 'foreign-ajax' : 'pressure',
                    ]);
                }
                return ['ok' => false, 'http_code' => 0, 'reason' => 'deferred_off_request_path'];
            }
        }

        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        if ($apikey === '' || $orch_url === '') {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'plugin_misconfigured'];
        }


        $img_cfg = wpc_v2_local_image_config();
        $del_cfg = wpc_v2_delivery_config();

        
        
        $clean = [];
        foreach ($zones as $z) {
            if (!is_array($z) || empty($z['zone_id'])) continue;
            $entry = [
                'zone_id'         => (string) $z['zone_id'],
                'site_url'        => $del_cfg['site_url'],
                'lazy_enabled'    => !empty($z['lazy_enabled']),
                'local_quality'   => $img_cfg['local_quality'],
                'local_max_width' => $img_cfg['local_max_width'],
                'delivery_mode'   => $del_cfg['delivery_mode'],
                'writes_variants' => $del_cfg['writes_variants'],
                'host_lock_mode'  => $del_cfg['host_lock_mode'],
                'host_lock_allow' => $del_cfg['host_lock_allow'],
                'redirect_target' => $del_cfg['redirect_target'],
                'nextgen'         => $del_cfg['nextgen'],
                'tier'            => $del_cfg['tier'],
                'images_on'       => $del_cfg['images_on'],       
                'cf_detected'     => $del_cfg['cf_detected'],     


                
                
                
                'cb_secret'    => function_exists('wpc_v2_callback_secret650') ? wpc_v2_callback_secret650() : '',

                'srcx'         => isset($del_cfg['srcx']) ? $del_cfg['srcx'] : '',
                'wpsz'         => isset($del_cfg['wpsz']) ? $del_cfg['wpsz'] : '',
                'lzf'          => isset($del_cfg['lzf']) ? $del_cfg['lzf'] : '',
                'upload_paths' => function_exists('wpc_v2_upload_base_paths') ? wpc_v2_upload_base_paths() : [],
            ];


            if ($del_cfg['cname'] !== '') {
                $entry['cname'] = $del_cfg['cname'];
            }
            $clean[] = $entry;
        }
        if (empty($clean)) {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'no_valid_zones'];
        }
        if (count($clean) > 100) {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'too_many_zones'];
        }


        $wake_url = function_exists('rest_url') ? rest_url('wpc/v2/wake') : '';


        $body_payload = [
            'apikey' => $apikey,
            'zones'  => $clean,
        ];


        if (defined('WPC_PLUGIN_VERSION')) {
            $body_payload['plugin_version'] = (string) WPC_PLUGIN_VERSION;
        }
        $body_payload['php_version'] = PHP_VERSION;
        if (function_exists('get_bloginfo')) {
            $body_payload['wp_version'] = (string) get_bloginfo('version');
        }
        $wpc_host = function_exists('wpc_v2_detect_host') ? wpc_v2_detect_host() : '';
        if ($wpc_host !== '') {
            $body_payload['host'] = $wpc_host;
        }
        if ($wake_url !== '') {
            $body_payload['wake_url'] = $wake_url;
        }

        
        


        if (function_exists('wpc_v2_zone_auto_disabled') && wpc_v2_zone_auto_disabled()
            && function_exists('wpc_v2_cdn_suppression_reason')) {
            $wpc_sr = wpc_v2_cdn_suppression_reason();
            if (is_array($wpc_sr) && !empty($wpc_sr['reason'])) {
                $body_payload['cdn_suppressed'] = [
                    'reason' => (string) $wpc_sr['reason'],
                    'class'  => (string) ($wpc_sr['class'] ?? ''),
                    'since'  => (int) ($wpc_sr['t'] ?? 0),
                ];
            }
        }
        $body_raw = wp_json_encode($body_payload);
        if ($body_raw === false) {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'json_encode_failed'];
        }
        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);


        
        
        if (function_exists('wpc_mc_up') && !wpc_mc_up()) {
            return ['ok' => false, 'http_code' => 0, 'reason' => 'mc_breaker_open'];
        }
        $url = rtrim($orch_url, '/') . '/v2/config?sync=1';
        $resp = wp_remote_post($url, [


            
            'timeout'   => (defined('DOING_CRON') && DOING_CRON) ? 30 : 8,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.05.1'),
            ],
            'body' => $body_raw,
        ]);
        if (is_wp_error($resp)) {
            error_log('[WPC ConfigSync] http_error: ' . $resp->get_error_message());
            if (function_exists('wpc_mc_trip') && preg_match('/timed? ?out|cURL error (?:28|7|6)|could not resolve/i', (string) $resp->get_error_message())) {
                wpc_mc_trip($resp->get_error_message());
            }
            return ['ok' => false, 'http_code' => 0, 'reason' => 'http_error'];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);


        if ($code === 202) {
            $b202 = json_decode((string) wp_remote_retrieve_body($resp), true);
            error_log(sprintf(
                '[WPC ConfigSync] 202 queued=%s status_url=%s (edge propagation async; DB committed)',
                is_array($b202) && isset($b202['queued']) ? (string) $b202['queued'] : '?',
                is_array($b202) && isset($b202['status_url']) ? (string) $b202['status_url'] : '?'
            ));
        }
        if ($code < 200 || $code >= 300) {
            error_log(sprintf(
                '[WPC ConfigSync] http_%d resp=%s',
                $code, substr((string) wp_remote_retrieve_body($resp), 0, 200)
            ));
            return ['ok' => false, 'http_code' => $code, 'reason' => 'http_non_2xx'];
        }


        $rbody = json_decode((string) wp_remote_retrieve_body($resp), true);


        if (function_exists('update_option')) {
            $zc = null;
            if (is_array($rbody) && !empty($rbody['zones']) && is_array($rbody['zones'])) {
                foreach ($rbody['zones'] as $rz) {
                    if (is_array($rz) && !empty($rz['zone_id_corrected']) && is_array($rz['zone_id_corrected'])
                        && isset($rz['zone_id_corrected']['to'])) { $zc = $rz['zone_id_corrected']; break; }
                }
            }
            if ($zc === null && is_array($rbody) && !empty($rbody['zone_id_corrected'])
                && is_array($rbody['zone_id_corrected']) && isset($rbody['zone_id_corrected']['to'])) {
                $zc = $rbody['zone_id_corrected'];
            }
            if (is_array($zc)) {
                $zc_to  = preg_replace('/[^0-9]/', '', (string) $zc['to']);
                $zc_now = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
                if ($zc_to !== '' && $zc_to !== $zc_now) {
                    $zc_from = isset($zc['from']) ? (string) $zc['from'] : $zc_now;
                    update_option('wpc_v2_zone_id', $zc_to, false);
                    if (function_exists('error_log')) error_log('[WPC ConfigSync] orch zone_id_corrected: adopted ' . $zc_to . ' (was ' . $zc_from . ') — clone-drift auto-heal');
                }
            }
        }

        if (is_array($rbody) && !empty($rbody['failed']) && is_array($rbody['failed'])) {
            $reasons = [];
            $db_deferred = false;
            foreach ($rbody['failed'] as $f) {
                $reason = is_array($f) ? (string) ($f['reason'] ?? $f['error'] ?? $f['code'] ?? '') : (string) $f;
                if ($reason !== '') $reasons[] = $reason;
                if (stripos($reason, 'db_error') === 0) $db_deferred = true;
            }
            error_log(sprintf(
                '[WPC ConfigSync] http_%d but failed=[%s] — NOT caching mirror (%s).',
                $code, implode(',', $reasons), $db_deferred ? 'deferred, will retry' : 'reported failure'
            ));
            if ($db_deferred && function_exists('update_option')) {
                


                update_option('wpc_v2_config_sync_pending', 1, false);
            }
            return ['ok' => false, 'http_code' => $code, 'reason' => ($db_deferred ? 'deferred:' : 'failed:') . implode(',', $reasons)];
        }


        $nav_by_zone = [];
        if (is_array($rbody) && !empty($rbody['zones']) && is_array($rbody['zones'])) {
            foreach ($rbody['zones'] as $rz) {
                if (is_array($rz) && !empty($rz['zone_id']) && array_key_exists('native_accept_vary', $rz)) {
                    $nav_by_zone[(string) $rz['zone_id']] = !empty($rz['native_accept_vary']);
                }
            }
        }


        $cdn_disabled_by_zone = [];
        if (is_array($rbody) && !empty($rbody['zones']) && is_array($rbody['zones'])) {
            foreach ($rbody['zones'] as $rz) {
                if (is_array($rz) && !empty($rz['zone_id']) && array_key_exists('cdn_disabled', $rz)) {
                    $cdn_disabled_by_zone[(string) $rz['zone_id']] = !empty($rz['cdn_disabled']);
                }
            }
        }


        $src_hints_by_zone = [];
        if (is_array($rbody) && !empty($rbody['zones']) && is_array($rbody['zones'])) {
            foreach ($rbody['zones'] as $rz) {
                if (is_array($rz) && !empty($rz['zone_id']) && array_key_exists('emit_src_hints', $rz)) {
                    $src_hints_by_zone[(string) $rz['zone_id']] = !empty($rz['emit_src_hints']);
                }
            }
        }

        
        
        $cdn_disabled_changed = false;
        foreach ($clean as $z) {
            $zid = (string) $z['zone_id'];
            update_option(
                'wpc_v2_lazy_enabled_' . sanitize_key($zid),
                $z['lazy_enabled'] ? '1' : '0',
                false
            );


            $nav_key = 'wpc_v2_orch_nav_' . sanitize_key($zid);
            $nav_written = null;
            $nav_old     = function_exists('get_option') ? get_option($nav_key, null) : null;
            if (array_key_exists($zid, $nav_by_zone)) {
                $nav_written = $nav_by_zone[$zid] ? '1' : '0';
                update_option($nav_key, $nav_written, false);
            } elseif (count($clean) === 1 && count($nav_by_zone) === 1) {
                $nav_written = reset($nav_by_zone) ? '1' : '0';
                update_option($nav_key, $nav_written, false);
            } elseif (function_exists('delete_option')) {
                delete_option($nav_key);
            }


            if ($nav_written === '1') {
                if (function_exists('delete_option')) delete_option('wpc_v2_selfheal_attempts');
                if (function_exists('site_url'))      update_option('wpc_v2_provisioned_site_url', (string) site_url(), false);


                if ((string) $nav_old !== '1'
                    && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_delivery_verify')) {
                    wp_schedule_single_event(time() + 20, 'wpc_delivery_verify');
                }
            }


            $cdn_key = 'wpc_v2_orch_cdn_disabled_' . sanitize_key($zid);
            $cdn_old = get_option($cdn_key, null);
            if (array_key_exists($zid, $cdn_disabled_by_zone)) {
                $cdn_new = $cdn_disabled_by_zone[$zid] ? '1' : '0';
                if ((string) $cdn_old !== $cdn_new) { $cdn_disabled_changed = true; }
                update_option($cdn_key, $cdn_new, false);
            } elseif (count($clean) === 1 && count($cdn_disabled_by_zone) === 1) {
                $cdn_new = reset($cdn_disabled_by_zone) ? '1' : '0';
                if ((string) $cdn_old !== $cdn_new) { $cdn_disabled_changed = true; }
                update_option($cdn_key, $cdn_new, false);
            } elseif (function_exists('delete_option')) {
                if ($cdn_old !== null && $cdn_old !== false) { $cdn_disabled_changed = true; }
                delete_option($cdn_key);
            }


            $sh_key = 'wpc_v2_orch_src_hints_' . sanitize_key($zid);
            if (array_key_exists($zid, $src_hints_by_zone)) {
                update_option($sh_key, $src_hints_by_zone[$zid] ? '1' : '0', false);
            } elseif (count($clean) === 1 && count($src_hints_by_zone) === 1) {
                update_option($sh_key, reset($src_hints_by_zone) ? '1' : '0', false);
            } elseif (function_exists('delete_option')) {
                delete_option($sh_key);
            }
        }

        
        
        
        
        if (is_array($rbody) && array_key_exists('cb_enforce', $rbody)) {
            if (!empty($rbody['cb_enforce'])) {
                update_option('wpc_cb_enforce652', '1', false);
                if (function_exists('wpc_v2_callback_maybe_harden652')) {
                    wpc_v2_callback_maybe_harden652();
                }
            } else {
                delete_option('wpc_cb_enforce652');
                delete_option('wpc_cb_strict650');
            }
        }

        
        if (!empty($cdn_disabled_by_zone)) {
            error_log('[WPC cdn_disabled] /v2/config echoed ' . count($cdn_disabled_by_zone)
                . ' zone(s): ' . wp_json_encode($cdn_disabled_by_zone));
        }
        
        if (!empty($src_hints_by_zone)) {
            error_log('[WPC emit_src_hints] /v2/config echoed ' . count($src_hints_by_zone)
                . ' zone(s): ' . wp_json_encode($src_hints_by_zone));
        }


        if ($cdn_disabled_changed) {
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                wps_ic_cache::removeHtmlCacheFiles('all');
            } elseif (function_exists('do_action')) {
                wpc_foreign_purge610(false, 'config-sync');
            }
        }

        
        
        update_option(
            'wpc_v2_synced_image_config',
            $img_cfg['local_quality'] . '|' . $img_cfg['local_max_width']
                . '|' . $del_cfg['delivery_mode'] . '|' . $del_cfg['host_lock_mode'] . '|' . $del_cfg['host_lock_allow']
                . ($del_cfg['redirect_target'] === 'origin' ? '|origin' : ''),
            false
        );

        
        if (function_exists('delete_option')) {
            delete_option('wpc_v2_config_sync_pending');
        }


        update_option('wpc_v2_config_synced_at', time(), false);


        if (function_exists('wpc_v2_env_fingerprint')) {
            update_option('wpc_v2_provisioned_fingerprint', wpc_v2_env_fingerprint(), false);
        }

        return ['ok' => true, 'http_code' => $code];
    }
}


if (!function_exists('wpc_v2_config_sync_lazy_enabled')) {
    function wpc_v2_config_sync_lazy_enabled($zone_id, $enabled)
    {


        $sd_marker = 'wpc_v2_lazy_synced_on_' . sanitize_key((string) $zone_id);
        $sd_seen   = get_option($sd_marker, null);
        if ($sd_seen === null) {
            $sd_mirror = (string) get_option('wpc_v2_lazy_enabled_' . sanitize_key((string) $zone_id), '');
            $sd_was_on = ($sd_mirror === '1');
        } else {
            $sd_was_on = (bool) $sd_seen;
        }

        $result = wpc_v2_config_sync_zones([
            ['zone_id' => (string) $zone_id, 'lazy_enabled' => (bool) $enabled],
        ]);


        if (!empty($result['ok'])
            && function_exists('wpc_customer_purge')
            && function_exists('wpc_v2_get_apikey')) {
            if ((bool) $enabled && !$sd_was_on) {
                update_option($sd_marker, 1, false);
                $sd_apikey = (string) wpc_v2_get_apikey();
                if ($sd_apikey !== '') {


                    wpc_customer_purge($sd_apikey, 'all', [], 'config_changed', false);
                }
            } elseif (!(bool) $enabled && $sd_was_on) {
                update_option($sd_marker, 0, false);
            }
        }

        return $result;
    }
}


if (!function_exists('wpc_v2_schedule_config_sync')) {
    function wpc_v2_schedule_config_sync()
    {
        $have_cron = function_exists('wp_next_scheduled') && function_exists('wp_schedule_single_event');
        if ($have_cron) {
            if (!wp_next_scheduled('wpc_v2_deferred_config_sync')) {
                wp_schedule_single_event(time(), 'wpc_v2_deferred_config_sync');
            }
        }


        if (function_exists('admin_url')
            && function_exists('set_transient') && function_exists('get_transient')
            && !get_transient('wpc_v2_provision_loopback_sent')) {
            set_transient('wpc_v2_provision_loopback_sent', 1, 30);


            if (function_exists('wp_generate_password')) {
                $token = wp_generate_password(32, false);
            } elseif (function_exists('random_bytes')) {
                $token = bin2hex(random_bytes(16));
            } else {
                $token = sha1(uniqid('', true) . (function_exists('wp_rand') ? wp_rand() : mt_rand()));
            }
            set_transient('wpc_v2_provision_token', $token, 60);


            $sent = false;
            if (class_exists('wps_ic_ajax')
                && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')
                && function_exists('wp_parse_url')) {
                $url   = admin_url('admin-ajax.php');
                $parts = wp_parse_url($url);
                if (is_array($parts) && !empty($parts['host'])) {
                    $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
                    
                    
                    $port = !empty($parts['port']) ? (int) $parts['port'] : ($is_https ? 443 : 80);
                    $host = (string) $parts['host'];


                    $path = (!empty($parts['path']) ? $parts['path'] : '/admin-ajax.php');

                    $body = http_build_query(['action' => 'wpc_v2_provision_now', 'token' => $token]);
                    $req  = "POST {$path} HTTP/1.1\r\n"
                          . "Host: {$host}\r\n"
                          . "Content-Type: application/x-www-form-urlencoded\r\n"
                          . "Content-Length: " . strlen($body) . "\r\n"
                          . "Connection: close\r\n"
                          . "User-Agent: WPCProvisionNow/1.0\r\n"
                          . "\r\n"
                          . $body;

                    
                    $fp = wps_ic_ajax::wpc_loopback_open_socket($host, $port, $is_https, 0.2);
                    if ($fp) {
                        @stream_set_timeout($fp, 0, 100000);
                        @fwrite($fp, $req);
                        @fclose($fp);
                        $sent = true;
                    } else {
                        error_log('[WPC ProvisionNow] loopback_connect_failed host=' . $host . ' port=' . $port . ' — cron+heartbeat backstops carry');
                    }
                }
            }
            
            
            if ($sent) {
                return;
            }
            
            
        }

        
        if (!$have_cron) {
            wpc_v2_run_deferred_config_sync();
        }
    }
}


if (!function_exists('wpc_v2_provision_now_handler')) {
    function wpc_v2_provision_now_handler()
    {
        $token    = isset($_POST['token']) ? (string) (function_exists('wp_unslash') ? wp_unslash($_POST['token']) : $_POST['token']) : '';
        $expected = function_exists('get_transient') ? get_transient('wpc_v2_provision_token') : false;
        if ($token === '' || $expected === false || !hash_equals((string) $expected, $token)) {
            if (function_exists('status_header')) status_header(403);
            exit;
        }
        if (function_exists('delete_transient')) delete_transient('wpc_v2_provision_token');
        if (function_exists('wpc_v2_run_deferred_config_sync')) {
            wpc_v2_run_deferred_config_sync();
        }
        if (function_exists('status_header')) status_header(200);
        exit;
    }
    add_action('wp_ajax_wpc_v2_provision_now', 'wpc_v2_provision_now_handler');
    add_action('wp_ajax_nopriv_wpc_v2_provision_now', 'wpc_v2_provision_now_handler');
}
if (!function_exists('wpc_v2_run_deferred_config_sync')) {
    function wpc_v2_run_deferred_config_sync()
    {
        if (!function_exists('wpc_v2_get_zone_id') || !function_exists('wpc_v2_config_sync_lazy_enabled')) {
            return;
        }


        if (function_exists('get_transient') && get_transient('wpc_v2_deferred_sync_lock')) {
            return;
        }
        if (function_exists('set_transient')) {
            set_transient('wpc_v2_deferred_sync_lock', 1, 60);
        }

        $zone_id     = (string) wpc_v2_get_zone_id();
        $has_numeric = ($zone_id !== '' && ctype_digit($zone_id));

        


        $cname = trim((string) get_option('ic_custom_cname'));
        if ($cname === '') {
            $cname = trim((string) get_option('ic_cdn_zone_name'));
        }
        $zid = $has_numeric ? $zone_id : $cname;
        if ($zid === '') {
            return;
        }

        
        
        $before = (int) get_option('wpc_v2_config_synced_at', 0);

        wpc_v2_config_sync_lazy_enabled(
            $zid,
            function_exists('wpc_v2_get_lazy_enabled') ? wpc_v2_get_lazy_enabled() : false
        );


        if ((int) get_option('wpc_v2_config_synced_at', 0) > $before) {
            delete_option('wpc_v2_force_provision');
            delete_option('wpc_v2_force_provision_fails');


        } elseif (get_option('wpc_v2_force_provision')) {


            $fp_fails = (int) get_option('wpc_v2_force_provision_fails', 0) + 1;
            if ($fp_fails >= 3) {
                delete_option('wpc_v2_force_provision');
                delete_option('wpc_v2_force_provision_fails');
                error_log('[WPC v2] force-provision circuit-breaker tripped after ' . $fp_fails . ' failed /v2/config attempts — backing off the inline admin_init retry (cron + manual refresh still retry).');
            } else {
                update_option('wpc_v2_force_provision_fails', $fp_fails, false);
            }
        }
    }
}
add_action('wpc_v2_deferred_config_sync', 'wpc_v2_run_deferred_config_sync');


if (!function_exists('wpc_v2_zone_unprovisioned')) {

    
    
    function wpc_v2_zone_unprovisioned()
    {
        if (!function_exists('wpc_v2_get_zone_id') || !function_exists('get_option')) return false;
        $zid = (string) wpc_v2_get_zone_id();
        if ($zid === '' || !ctype_digit($zid)) return false;
        return get_option('wpc_v2_orch_nav_' . sanitize_key($zid)) !== '1';
    }
}

if (!function_exists('wpc_v2_provision_host_changed')) {


    function wpc_v2_provision_host_changed()
    {
        if (!function_exists('site_url') || !function_exists('get_option')) return false;
        $confirmed = (string) get_option('wpc_v2_provisioned_site_url', '');
        if ($confirmed === '') return false;
        
        
        return preg_replace('#^https?://#i', '', $confirmed) !== preg_replace('#^https?://#i', '', (string) site_url());
    }
}

if (!function_exists('wpc_v2_env_fingerprint')) {


    function wpc_v2_env_fingerprint()
    {
        global $wpdb;


        $home = function_exists('get_option') ? (string) get_option('home') : '';
        if ($home === '' && function_exists('home_url')) $home = (string) home_url();
        $url = preg_replace('#^https?://#i', '', $home);
        $db  = defined('DB_NAME') ? (string) DB_NAME : '';
        $pfx = (isset($wpdb) && isset($wpdb->prefix)) ? (string) $wpdb->prefix : '';
        return sha1($url . '|' . $db . '|' . $pfx);
    }
}
if (!function_exists('wpc_v2_provision_env_changed')) {


    function wpc_v2_provision_env_changed()
    {
        if (!function_exists('get_option')) return false;
        return (string) get_option('wpc_v2_provisioned_fingerprint', '') !== wpc_v2_env_fingerprint();
    }
}
if (!function_exists('wpc_v2_provision_reset_for_env')) {


    function wpc_v2_provision_reset_for_env()
    {
        if (!function_exists('get_option')) return;
        if (function_exists('wpc_v2_get_zone_id') && function_exists('delete_option')) {
            $zid = (string) wpc_v2_get_zone_id();
            if ($zid !== '') delete_option('wpc_v2_orch_nav_' . sanitize_key($zid));
        }
        if (function_exists('delete_option')) {
            delete_option('wpc_v2_provisioned_site_url');
            delete_option('wpc_v2_selfheal_attempts');
        }


        if (function_exists('update_option')) update_option('wpc_cf_cname_verified', '0', false);
        if (function_exists('delete_transient')) {
            delete_transient('wpc_v2_selfheal_backoff');
            delete_transient('wpc_v2_config_force_backoff');
        }
        if (function_exists('update_option')) update_option('wpc_v2_force_provision', 1, false);
    }
}

if (!function_exists('wpc_v2_provision_ensure_bg')) {


    function wpc_v2_provision_ensure_bg($reason)
    {
        if (!function_exists('wpc_v2_run_deferred_config_sync') || !function_exists('wpc_v2_get_zone_id')) {
            return false;
        }


        $force  = (bool) get_option('wpc_v2_force_provision');
        $unprov = wpc_v2_zone_unprovisioned();
        $moved  = wpc_v2_provision_host_changed();
        if (!$force && !$unprov && !$moved) return false;

        $attempts = (int) get_option('wpc_v2_selfheal_attempts', 0);
        if ($attempts >= 12) return false;

        
        if (function_exists('get_transient') && get_transient('wpc_v2_selfheal_backoff')) return false;
        if (function_exists('set_transient')) set_transient('wpc_v2_selfheal_backoff', 1, 120);

        if ($moved) {
            


            if (function_exists('delete_option')) delete_option('wpc_v2_provisioned_site_url');
        }
        update_option('wpc_v2_force_provision', 1, false);
        update_option('wpc_v2_selfheal_attempts', $attempts + 1, false);
        error_log(sprintf('[WPC v2 selfheal] ensure_bg reason=%s force=%d unprov=%d moved=%d attempt=%d',
            (string) $reason, $force ? 1 : 0, $unprov ? 1 : 0, $moved ? 1 : 0, $attempts + 1));

        wpc_v2_run_deferred_config_sync();


        return true;
    }
}


if (function_exists('add_filter')) {
    add_filter('heartbeat_received', function ($response, $data) {
        if (function_exists('current_user_can') && current_user_can('manage_options')
            && function_exists('wpc_v2_provision_ensure_bg')) {
            wpc_v2_provision_ensure_bg('heartbeat');
        }
        return $response;
    }, 10, 2);
}





if (!function_exists('wpc_v2_cf_cname_reverify')) {
    function wpc_v2_cf_cname_reverify($throttle = true)
    {
        if (!function_exists('get_option')) return false;
        $v = get_option('wpc_cf_cname_verified');
        if ($v === '1' || $v === 1) return false;
        $cfc = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME)) : '';
        if ($cfc === '') return false;
        $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
        if (!is_array($cf) || empty($cf['settings']['cdn'])) return false; 
        if ($throttle && function_exists('get_transient') && get_transient('wpc_cf_reverify_bk')) return false;
        
        $wpc_rvat = (int) get_option('wpc_cf_reverify_at');
        if (time() - $wpc_rvat < 120) return false;
        update_option('wpc_cf_reverify_at', time(), false);
        if (function_exists('set_transient')) set_transient('wpc_cf_reverify_bk', 1, 120);
        if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) { @include_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php'; }
        if (!class_exists('WPC_CloudflareAPI')) return false;
        $api = new WPC_CloudflareAPI(isset($cf['token']) ? $cf['token'] : '');
        if ($api && $api->verifyCfCnameLive($cfc, 1, 3)) {
            update_option('wpc_cf_cname_verified', 1, false); 
            if (function_exists('site_url')) update_option('wpc_v2_provisioned_site_url', (string) site_url(), false);


            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                wps_ic_cache::removeHtmlCacheFiles('all');
            } elseif (function_exists('do_action')) {
                wpc_foreign_purge610(false, 'config-sync');
            }
            return true;
        }
        return false;
    }
}


if (function_exists('add_action')) {
    add_action('wp_ajax_wpc_v2_force_provision_now', function () {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')
            || !function_exists('check_ajax_referer') || !check_ajax_referer('wpc_v2_force_provision_now', '_n', false)) {
            wp_send_json_error('forbidden');
        }
        
        
        if (function_exists('delete_option'))    { delete_option('wpc_v2_selfheal_attempts'); }
        if (function_exists('delete_transient')) { delete_transient('wpc_v2_selfheal_backoff'); delete_transient('wpc_v2_config_force_backoff'); }
        if (function_exists('update_option'))    { update_option('wpc_v2_force_provision', 1, false); }
        if (function_exists('wpc_v2_run_deferred_config_sync')) { wpc_v2_run_deferred_config_sync(); }
        if (function_exists('wpc_v2_cf_cname_reverify')) { wpc_v2_cf_cname_reverify(false); }
        wp_send_json_success([
            'env_changed' => function_exists('wpc_v2_provision_env_changed') ? wpc_v2_provision_env_changed() : null,
            'synced_at'   => (int) get_option('wpc_v2_config_synced_at', 0),
            'force'       => (bool) get_option('wpc_v2_force_provision', false),
        ]);
    });
    add_action('admin_footer', function () {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) return;
        if (!function_exists('wpc_v2_provision_env_changed') || !wpc_v2_provision_env_changed()) return;
        if (!(bool) get_option('wpc_v2_force_provision', false)) return;
        if (!function_exists('wp_create_nonce') || !function_exists('admin_url')) return;
        $nonce = wp_create_nonce('wpc_v2_force_provision_now');
        $ajax  = admin_url('admin-ajax.php');
        echo "\n<script>(function(){try{var f=new FormData();f.append('action','wpc_v2_force_provision_now');f.append('_n'," . wp_json_encode($nonce) . ");fetch(" . wp_json_encode($ajax) . ",{method:'POST',body:f,credentials:'same-origin',keepalive:true});}catch(e){}})();</script>\n";
    });
}


if (!function_exists('wpc_v2_get_zone_id')) {
    function wpc_v2_get_zone_id()
    {


        $z = (string) get_option('wpc_v2_zone_id', '');
        if ($z !== '') return $z;


        $legacy_keys = ['wpc_zone_id', 'wpc_cdn_zone_id', 'wps_ic_zone_id'];
        foreach ($legacy_keys as $k) {
            $v = (string) get_option($k, '');
            if ($v !== '') return $v;
        }

        return '';
    }
}


if (!function_exists('wpc_v2_orch_witness_cname_keys')) {
    function wpc_v2_orch_witness_cname_keys()
    {
        if (!function_exists('get_option')) return [];
        $cands = [];

        
        if (defined('WPS_IC_CF_CNAME')) {
            $cf = trim((string) get_option(WPS_IC_CF_CNAME));
            if ($cf !== '') $cands[] = $cf;
        }
        
        $cc = trim((string) get_option('ic_custom_cname'));
        if ($cc !== '') {
            $cands[] = $cc;
        } else {
            $zn = trim((string) get_option('ic_cdn_zone_name'));
            if ($zn !== '') $cands[] = $zn;
        }

        return array_values(array_unique(array_filter($cands, 'strlen')));
    }
}


if (!function_exists('wpc_v2_zone_cdn_disabled')) {
    function wpc_v2_zone_cdn_disabled()
    {
        if (!function_exists('get_option') || !function_exists('wpc_v2_get_zone_id')) return false;
        $sk = function_exists('sanitize_key');
        
        
        $zone = (string) wpc_v2_get_zone_id();
        if ($zone !== '') {
            $v = get_option('wpc_v2_orch_cdn_disabled_' . ($sk ? sanitize_key($zone) : $zone), null);
            
            if ($v !== null) {
                return ($v === '1' || $v === 1 || $v === true);
            }
        }


        if (function_exists('wpc_v2_orch_witness_cname_keys')) {
            foreach (wpc_v2_orch_witness_cname_keys() as $cn) {
                $v = get_option('wpc_v2_orch_cdn_disabled_' . ($sk ? sanitize_key($cn) : $cn), null);
                if ($v !== null) {
                    return ($v === '1' || $v === 1 || $v === true);
                }
            }
        }
        return false;
    }
}

if (!function_exists('wpc_v2_zone_src_hints')) {

    function wpc_v2_zone_src_hints()
    {
        if (!function_exists('get_option') || !function_exists('wpc_v2_get_zone_id')) return null;
        $sk = function_exists('sanitize_key');
        $zone = (string) wpc_v2_get_zone_id();
        if ($zone !== '') {
            $v = get_option('wpc_v2_orch_src_hints_' . ($sk ? sanitize_key($zone) : $zone), null);
            if ($v !== null) return ($v === '1' || $v === 1 || $v === true);
        }
        if (function_exists('wpc_v2_orch_witness_cname_keys')) {
            foreach (wpc_v2_orch_witness_cname_keys() as $cn) {
                $v = get_option('wpc_v2_orch_src_hints_' . ($sk ? sanitize_key($cn) : $cn), null);
                if ($v !== null) return ($v === '1' || $v === 1 || $v === true);
            }
        }
        return null;
    }
}


if (!function_exists('wpc_v2_auto_disable_enabled')) {
    function wpc_v2_auto_disable_enabled()
    {
        
        
        if (defined('WPC_DISABLE_AUTO_RESILIENCE') && WPC_DISABLE_AUTO_RESILIENCE) return false;
        if (!function_exists('get_site_option')) return false;


        return (bool) apply_filters('wpc_cdn_auto_disable', (bool) get_site_option('wpc_cdn_auto_disable', false));
    }
}

if (!function_exists('wpc_v2_zone_auto_disabled')) {
    
    function wpc_v2_zone_auto_disabled()
    {
        if (!wpc_v2_auto_disable_enabled() || !function_exists('wpc_v2_get_zone_id')) return false;
        $zone = (string) wpc_v2_get_zone_id();
        if ($zone === '') return false;
        $key = 'wpc_v2_auto_disable_' . (function_exists('sanitize_key') ? sanitize_key($zone) : $zone);
        return get_option($key, 'up') === 'down';
    }
}

if (!function_exists('wpc_cdn_norm_host')) {
    
    function wpc_cdn_norm_host($h)
    {
        $h = strtolower(trim((string) $h));
        return preg_replace('/^www\./', '', $h);
    }
}

if (!function_exists('wpc_cdn_zone_naming_affinity_ok')) {

    function wpc_cdn_zone_naming_affinity_ok($zone, $host)
    {
        $label = strtok(strtolower((string) $zone), '.');
        if ($label === false || $label === '') return true;
        $host = strtolower(trim((string) $host));


        $bare = preg_replace('/^www\./', '', $host);
        $candidates = array_unique([
            preg_replace('/[^a-z0-9]/', '', $host),
            preg_replace('/[^a-z0-9]/', '', $bare),
            preg_replace('/[^a-z0-9]/', '', 'www' . $bare),
        ]);
        foreach ($candidates as $key) {
            if ((string) $key === '') continue;
            $n = min(strlen($label), strlen($key));
            $lcp = 0;
            while ($lcp < $n && $label[$lcp] === $key[$lcp]) $lcp++;
            if (!((strlen($label) - $lcp) > 10 && $lcp < 4)) return true;
        }
        return false;
    }
}

if (!function_exists('wpc_cdn_zone_is_foreign')) {

    function wpc_cdn_zone_is_foreign()
    {
        if (!function_exists('get_option') || !function_exists('home_url')) return false;
        $zone = strtolower(trim((string) get_option('ic_cdn_zone_name', '')));
        if ($zone === '' || !preg_match('/\.zapwp\.(?:com|net)$/', $zone)) return false;
        if (trim((string) get_option('ic_custom_cname', '')) !== '') return false;
        $cfc = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $cf  = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
        if ($cfc !== '' && is_array($cf) && !empty($cf['settings']['cdn'])) return false;
        if (!apply_filters('wpc_zone_affinity_check', true, $zone)) return false;
        $host_raw = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if ($host_raw === '') return false;
        $stamp = wpc_cdn_norm_host((string) get_option('ic_cdn_zone_name_host', ''));
        if ($stamp !== '') return ($stamp !== wpc_cdn_norm_host($host_raw));
        return !wpc_cdn_zone_naming_affinity_ok($zone, $host_raw);
    }
}

if (!function_exists('wpc_v2_zone_cdn_suppressed')) {

    function wpc_v2_zone_cdn_suppressed()
    {


        static $account_cdn_off = null;
        if ($account_cdn_off === null) {
            $allow_live = function_exists('get_option') ? get_option('wps_ic_allow_live', 'unset') : 'unset';
            $account_cdn_off = ($allow_live !== 'unset' && !$allow_live);
        }
        if ($account_cdn_off) return true;


        
        
        static $env = null;
        if ($env === null) {
            $env = (function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed());
        }
        if ($env) return true;
        


        static $cfwait = null;
        if ($cfwait === null) {
            $cfc = (defined('WPS_IC_CF_CNAME') && function_exists('get_option')) ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
            $cf  = (defined('WPS_IC_CF') && function_exists('get_option')) ? get_option(WPS_IC_CF) : false;
            $ver = function_exists('get_option') ? get_option('wpc_cf_cname_verified') : '1';
            $cfwait = ($cfc !== '' && is_array($cf) && !empty($cf['settings']['cdn']) && $ver !== '1' && $ver !== 1);
        }
        if ($cfwait) return true;


        static $foreign = null;
        if ($foreign === null) {
            $foreign = (function_exists('wpc_cdn_zone_is_foreign') && wpc_cdn_zone_is_foreign());
        }
        if ($foreign) return true;
        return (function_exists('wpc_v2_zone_cdn_disabled') && wpc_v2_zone_cdn_disabled())
            || (function_exists('wpc_v2_zone_auto_disabled') && wpc_v2_zone_auto_disabled());
    }
}

if (!function_exists('wpc_v2_cdn_canary_url')) {

    function wpc_v2_cdn_canary_url()
    {
        $cached = get_option('wpc_v2_cdn_canary', '');
        if (is_string($cached) && $cached !== '') return $cached;
        $zone = (string) get_option('ic_cdn_zone_name', '');
        if ($zone === '' || !function_exists('get_posts')) return '';


        $ids = get_posts(['post_type' => 'attachment', 'post_mime_type' => 'image', 'numberposts' => 20, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true]);
        foreach ((array) $ids as $id) {
            $path = function_exists('get_attached_file') ? get_attached_file($id) : '';
            if ($path && @is_file($path)) {
                $url = wp_get_attachment_url($id);
                if ($url) {
                    $canary = 'https://' . $zone . '/m:0/a:' . $url;
                    update_option('wpc_v2_cdn_canary', $canary, false);
                    return $canary;
                }
            }
        }
        return '';
    }
}

if (!function_exists('wpc_v2_auto_disable_purge')) {
    
    function wpc_v2_auto_disable_purge()
    {
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        } elseif (function_exists('do_action')) {
            wpc_foreign_purge610(false, 'config-sync');
        }
    }
}

if (!function_exists('wpc_v2_record_liveness')) {

    function wpc_v2_record_liveness($zone, $ok, $class = '')
    {
        $zk = function_exists('sanitize_key') ? sanitize_key($zone) : $zone;
        $state_key   = 'wpc_v2_auto_disable_' . $zk;
        $fail_key    = 'wpc_v2_auto_disable_fails_' . $zk;
        $ok_key      = 'wpc_v2_auto_disable_oks_' . $zk;
        $flip_key    = 'wpc_v2_auto_disable_flipped_' . $zk;
        $lastfail_key = 'wpc_v2_auto_disable_lastfail_' . $zk;
        $reason_key  = 'wpc_v2_cdn_suppress_reason_' . $zk;
        $state = get_option($state_key, 'up');
        $now = time();
        
        

        $wpc_demote = function () use ($state_key, $flip_key, $fail_key, $reason_key, $now, $zone, $class) {
            if (get_option($state_key, 'up') !== 'up') return;
            update_option($state_key, 'down', false);
            update_option($flip_key, $now, false);
            update_option($fail_key, 0, false);
            update_option($reason_key, ['reason' => 'cdn_unhealthy_selfcheck', 'class' => (string) $class, 't' => $now], false);
            error_log('[WPC auto-disable] zone ' . $zone . ' DEMOTED to origin (cdn_unhealthy_selfcheck; class=' . (string) $class . ')');
            wpc_v2_auto_disable_purge();
        };

        if ($ok) {
            update_option($fail_key, 0, false);
            if ($state === 'down') {
                $oks = (int) get_option($ok_key, 0) + 1;
                update_option($ok_key, $oks, false);
                $since_flip = $now - (int) get_option($flip_key, 0);
                $since_fail = $now - (int) get_option($lastfail_key, 0);


                if ($oks >= 2 && $since_flip >= 300 && $since_fail >= 300 && get_option($state_key, 'up') === 'down') {
                    update_option($state_key, 'up', false);
                    update_option($flip_key, $now, false);
                    update_option($ok_key, 0, false);
                    delete_option($reason_key); 
                    error_log('[WPC auto-disable] zone ' . $zone . ' RE-PROMOTED (CDN recovered)');
                    wpc_v2_auto_disable_purge();
                }
            }
        } else {
            update_option($ok_key, 0, false);
            $prev_fail = (int) get_option($lastfail_key, 0);
            update_option($lastfail_key, $now, false);


            if ($state === 'up' && $class === 'cdn_5xx' && apply_filters('wpc_liveness_hard5xx_fastpath', true)) {
                $wpc_demote();
                return;
            }


            if ($state === 'up' && ($now - $prev_fail) >= 60) {
                $fails = (int) get_option($fail_key, 0) + 1;
                update_option($fail_key, $fails, false);
                if ($fails >= 3) {
                    $wpc_demote();
                }
            }
        }
    }
}

if (!function_exists('wpc_v2_cdn_selfcheck_admin_notice')) {
    




    function wpc_v2_cdn_selfcheck_admin_notice()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')) return;
        if (!function_exists('wpc_v2_zone_auto_disabled') || !wpc_v2_zone_auto_disabled()) return;
        if (!function_exists('wpc_v2_cdn_suppression_reason')) return;
        $r = wpc_v2_cdn_suppression_reason();
        if (!is_array($r) || ($r['reason'] ?? '') !== 'cdn_unhealthy_selfcheck') return;
        $class = isset($r['class']) ? (string) $r['class'] : '';
        $map = [
            'cdn_5xx'         => 'the CDN returned server errors (5xx)',
            'cdn_timeout'     => 'the CDN was unreachable (timeouts)',
            'cdn_wrong_bytes' => 'the CDN returned non-image content (a firewall/challenge page)',
            'cdn_down'        => 'the CDN failed its health check',
        ];
        $why = isset($map[$class]) ? $map[$class] : 'the CDN failed its health check';
        echo '<div class="notice notice-warning"><p><strong>WP Compress:</strong> CDN delivery is temporarily bypassed for this site because ' . esc_html($why) . '. '
           . 'Your pages are serving images from your own server — <strong>working, just not CDN-accelerated</strong>. '
           . 'This restores automatically the moment the CDN passes its health check again (usually minutes). '
           . 'If this persists, ask your host to allow outbound requests to the CDN hostname, and check any firewall/security plugin isn\'t challenging image requests.</p></div>';
    }
    add_action('admin_notices', 'wpc_v2_cdn_selfcheck_admin_notice');
}

if (!function_exists('wpc_v2_cdn_suppression_reason')) {

    function wpc_v2_cdn_suppression_reason()
    {
        if (!function_exists('wpc_v2_get_zone_id')) return '';
        $zone = (string) wpc_v2_get_zone_id();
        if ($zone === '') return '';
        $zk = function_exists('sanitize_key') ? sanitize_key($zone) : $zone;
        $r = get_option('wpc_v2_cdn_suppress_reason_' . $zk, '');
        return is_array($r) ? $r : '';
    }
}

if (!function_exists('wpc_v2_maybe_probe_cdn_liveness')) {

    function wpc_v2_maybe_probe_cdn_liveness()
    {
        if (!wpc_v2_auto_disable_enabled() || !function_exists('wpc_v2_get_zone_id')) return;
        $zone = (string) wpc_v2_get_zone_id();
        if ($zone === '' || (string) get_option('ic_cdn_zone_name', '') === '') return;


        $s = defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
        if (!is_array($s) || empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') return;
        $zk = function_exists('sanitize_key') ? sanitize_key($zone) : $zone;
        if (get_transient('wpc_v2_cdn_liveness_check_' . $zk) !== false) return;

        
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            return;
        }

        
        $wpc_lva179 = (int) get_option('wpc_v2_liveness_probe_at');
        if (time() - $wpc_lva179 < 300) return;
        update_option('wpc_v2_liveness_probe_at', time(), false);

        $ttl = max(60, (int) apply_filters('wpc_liveness_lockout_ttl', 300)) + mt_rand(0, 120);
        set_transient('wpc_v2_cdn_liveness_check_' . $zk, time(), $ttl);


        $lvp = wp_parse_url(admin_url('admin-ajax.php'));
        if (!empty($lvp['host'])) {
            $lv_https = (!empty($lvp['scheme']) && $lvp['scheme'] === 'https');
            $lv_port  = !empty($lvp['port']) ? (int) $lvp['port'] : ($lv_https ? 443 : 80);
            $lv_host  = (string) $lvp['host'];
            $lv_path  = (!empty($lvp['path']) ? $lvp['path'] : '/') . '?action=wpc_cdn_liveness_probe';
            $lv_body  = http_build_query(['zone_id' => $zone, 'nonce' => wp_create_nonce('wpc_cdn_liveness')]);
            $lv_req   = "POST {$lv_path} HTTP/1.1\r\nHost: {$lv_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($lv_body) . "\r\nConnection: close\r\nUser-Agent: WPCLiveness/1.0\r\n\r\n" . $lv_body;
            
            
            $lv_fp = (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket'))
                ? wps_ic_ajax::wpc_loopback_open_socket($lv_host, $lv_port, $lv_https, 0.2) : false;
            if ($lv_fp) { @stream_set_timeout($lv_fp, 0, 100000); @fwrite($lv_fp, $lv_req); @fclose($lv_fp); }
        }
    }
    add_action('template_redirect', 'wpc_v2_maybe_probe_cdn_liveness', 99);
}

if (!function_exists('wpc_v2_cdn_liveness_cron_boot')) {

    function wpc_v2_cdn_liveness_cron_boot()
    {
        if (!function_exists('wpc_v2_auto_disable_enabled') || !wpc_v2_auto_disable_enabled()) return;
        if (!wp_next_scheduled('wpc_v2_cdn_liveness_cron')) {
            wp_schedule_event(time() + 300, 'wpc_v2_15min', 'wpc_v2_cdn_liveness_cron');
        }
    }
    add_action('init', 'wpc_v2_cdn_liveness_cron_boot', 100);
    add_action('wpc_v2_cdn_liveness_cron', 'wpc_v2_maybe_probe_cdn_liveness');
    add_filter('cron_schedules', function ($s) {
        if (!isset($s['wpc_v2_15min'])) $s['wpc_v2_15min'] = ['interval' => 900, 'display' => 'WPC 15 min'];
        return $s;
    });
}

if (!function_exists('wpc_v2_cdn_liveness_probe_handler')) {
    



    function wpc_v2_cdn_liveness_probe_handler()
    {
        if (!wpc_v2_auto_disable_enabled()) wp_die('', '', ['response' => 200]);
        if (!check_ajax_referer('wpc_cdn_liveness', 'nonce', false)) wp_die('', '', ['response' => 200]);


        if (get_option('wpc_v2_loopback_ok', '0') !== '1') { update_option('wpc_v2_loopback_ok', '1', false); }
        $zone = isset($_POST['zone_id']) ? sanitize_text_field(wp_unslash($_POST['zone_id'])) : '';
        if ($zone === '') wp_die('', '', ['response' => 200]);
        $canary = wpc_v2_cdn_canary_url();
        if ($canary === '') wp_die('', '', ['response' => 200]);


        $timeout = (float) apply_filters('wpc_liveness_probe_timeout', 1.0);
        if ($timeout < 0.3) $timeout = 0.3;


        $r = wp_remote_get($canary, [
            'timeout'            => $timeout,
            'sslverify'          => false,
            'redirection'        => 0,
            'limit_response_size' => 2048,
        ]);
        $code  = (int) wp_remote_retrieve_response_code($r);
        $ctype = strtolower((string) wp_remote_retrieve_header($r, 'content-type'));
        $loc   = strtolower((string) wp_remote_retrieve_header($r, 'location'));
        $body  = (string) wp_remote_retrieve_body($r);
        $down  = is_wp_error($r) || $code >= 500 || $code === 0;


        $poison = '';
        if (!$down && $code !== 404) {
            $is_img    = (strpos($ctype, 'image/') === 0);
            $is_markup = ($ctype !== '' && (strpos($ctype, 'text/html') !== false || strpos($ctype, 'application/json') !== false));
            $chal_loc  = ($loc !== '' && (strpos($loc, '/.well-known/') !== false || strpos($loc, 'captcha') !== false || strpos($loc, 'challenge') !== false));
            $chal_body = ($body !== '' && (stripos($body, 'captcha') !== false || stripos($body, '.well-known') !== false || stripos($body, 'challenge') !== false || stripos($body, '"error"') !== false));
            if (!$is_img && ($is_markup || $chal_loc || $chal_body)) {
                $poison = $chal_loc ? 'challenge-redirect' : ($is_markup ? ('non-image-ctype:' . ($ctype !== '' ? $ctype : 'empty')) : 'challenge-body');
                $down   = true;
            }
        }
        if ($poison !== '') {
            update_option('wpc_v2_cdn_poison_reason', $poison, false);
        } elseif (!$down) {
            delete_option('wpc_v2_cdn_poison_reason');
        }

        
        
        if (!$down && $code === 404) {
            $n404 = (int) get_option('wpc_v2_cdn_canary_404', 0) + 1;
            if ($n404 >= 3) { delete_option('wpc_v2_cdn_canary'); delete_option('wpc_v2_cdn_canary_404'); }
            else { update_option('wpc_v2_cdn_canary_404', $n404, false); }
        } elseif (!$down) {
            delete_option('wpc_v2_cdn_canary_404');
        }


        
        $wpc_class = '';
        if ($down) {
            if ($poison !== '')                       $wpc_class = 'cdn_wrong_bytes';
            elseif ($code >= 500)                     $wpc_class = 'cdn_5xx';
            elseif (is_wp_error($r) || $code === 0)   $wpc_class = 'cdn_timeout';
            else                                      $wpc_class = 'cdn_down';
        }
        wpc_v2_record_liveness($zone, !$down, $wpc_class);


        if (!get_transient('wpc_v2_foreign_cdn_checked') && function_exists('home_url')) {
            set_transient('wpc_v2_foreign_cdn_checked', 1, DAY_IN_SECONDS);
            $oh = wp_remote_head(home_url('/'), ['timeout' => 4, 'sslverify' => false, 'redirection' => 0, 'user-agent' => 'Mozilla/5.0 (WPCompress conflict-check)']);
            if (!is_wp_error($oh)) {
                $fc = '';
                if (wp_remote_retrieve_header($oh, 'x-sg-cdn') !== '')                $fc = 'SiteGround SG-CDN';
                elseif (wp_remote_retrieve_header($oh, 'cf-ray') !== '')              $fc = 'Cloudflare';
                elseif (wp_remote_retrieve_header($oh, 'x-amz-cf-id') !== '')         $fc = 'AWS CloudFront';
                elseif (wp_remote_retrieve_header($oh, 'x-fastly-request-id') !== '') $fc = 'Fastly';
                if ($fc !== '') { update_option('wpc_v2_foreign_cdn', $fc, false); }
                else { delete_option('wpc_v2_foreign_cdn'); }
            }
        }

        wp_die('', '', ['response' => 200]);
    }
    add_action('wp_ajax_nopriv_wpc_cdn_liveness_probe', 'wpc_v2_cdn_liveness_probe_handler');
    add_action('wp_ajax_wpc_cdn_liveness_probe', 'wpc_v2_cdn_liveness_probe_handler');
}

if (!function_exists('wpc_v2_asset_mime_probe_run')) {

    function wpc_v2_asset_mime_probe_run($probe_zone = '')
    {
        $probe_zone = preg_replace('#/.*$#', '', trim((string) $probe_zone));
        if ($probe_zone === '') {
            
            
            $cf_cname  = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
            $cf_set    = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
            $cf_cdn_on = is_array($cf_set) && !empty($cf_set['settings']['cdn']);
            $probe_zone = ($cf_cname !== '' && $cf_cdn_on)
                ? $cf_cname
                : (trim((string) get_option('ic_custom_cname', '')) ?: (string) get_option('ic_cdn_zone_name', ''));
            $probe_zone = preg_replace('#/.*$#', '', trim((string) $probe_zone));
        }
        if ($probe_zone === '') { delete_transient('wpc_v2_asset_probe_inflight'); return false; }
        
        
        $probe_r  = wp_remote_get('https://' . $probe_zone . '/wp-includes/css/dist/block-library/style.min.css', ['timeout' => 3, 'sslverify' => false, 'redirection' => 2, 'limit_response_size' => 8192]);
        $probe_ct = is_wp_error($probe_r) ? '' : strtolower((string) wp_remote_retrieve_header($probe_r, 'content-type'));
        $probe_ok = ((int) wp_remote_retrieve_response_code($probe_r) === 200) && (strpos($probe_ct, 'text/css') === 0);
        delete_transient('wpc_v2_asset_probe_inflight');
        
        
        
        
        if (function_exists('update_option')) {
            update_option('wpc_v2_cf_asset_mime_last', [
                'at'   => time(),
                'zone' => $probe_zone,
                'code' => is_wp_error($probe_r) ? 0 : (int) wp_remote_retrieve_response_code($probe_r),
                'ct'   => $probe_ct,
                'err'  => is_wp_error($probe_r) ? substr((string) $probe_r->get_error_message(), 0, 120) : '',
                'srv'  => is_wp_error($probe_r) ? '' : substr((string) wp_remote_retrieve_header($probe_r, 'server'), 0, 40),
                'ok'   => $probe_ok ? 1 : 0,
            ], false);
        }
        if ($probe_ok) {
            update_option('wpc_v2_cf_asset_mime_ok', '1', true);
            update_option('wpc_v2_cf_asset_mime_ts', time(), true);
            delete_option('wpc_v2_cf_asset_mime_strikes');
            delete_transient('wpc_v2_cf_asset_mime_retry');
        } else {
            set_transient('wpc_v2_cf_asset_mime_retry', '0', 2 * HOUR_IN_SECONDS);
            
            
            
            $probe_code = is_wp_error($probe_r) ? 0 : (int) wp_remote_retrieve_response_code($probe_r);
            $probe_def  = ($probe_code >= 400) || ($probe_code === 200 && strpos($probe_ct, 'text/css') !== 0);
            if ($probe_def && (string) get_option('wpc_v2_cf_asset_mime_ok', '') === '1') {
                $wpc_str735 = (int) get_option('wpc_v2_cf_asset_mime_strikes', 0) + 1;
                if ($wpc_str735 >= (int) apply_filters('wpc_natural_proof_strikes', 2)) {
                    delete_option('wpc_v2_cf_asset_mime_ok');
                    delete_option('wpc_v2_cf_asset_mime_ts');
                    delete_option('wpc_v2_cf_asset_mime_strikes');
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('natural-proof', '', '', ['revoked' => 1, 'code' => $probe_code]);
                    }
                } else {
                    update_option('wpc_v2_cf_asset_mime_strikes', $wpc_str735, false);
                }
            }
        }
        error_log('[WPC NaturalAssets] cf_asset_mime_probe ct=' . ($probe_ct !== '' ? $probe_ct : 'error') . ' ok=' . (int) $probe_ok);
        return (bool) $probe_ok;
    }
}

if (!function_exists('wpc_v2_asset_mime_probe_handler')) {
    





    function wpc_v2_asset_mime_probe_handler()
    {
        if (!check_ajax_referer('wpc_asset_mime', 'nonce', false)) wp_die('', '', ['response' => 200]);
        
        if (get_option('wpc_v2_loopback_ok', '0') !== '1') { update_option('wpc_v2_loopback_ok', '1', false); }
        wpc_v2_asset_mime_probe_run();
        wp_die('', '', ['response' => 200]);
    }
    add_action('wp_ajax_nopriv_wpc_asset_mime_probe', 'wpc_v2_asset_mime_probe_handler');
    add_action('wp_ajax_wpc_asset_mime_probe', 'wpc_v2_asset_mime_probe_handler');
}


if (!function_exists('wpc_v2_get_lazy_enabled')) {
    function wpc_v2_get_lazy_enabled()
    {


        if (!function_exists('wpc_get_optimization_mode')
            || wpc_get_optimization_mode() !== 'lazy_cdn') {
            return false;
        }
        $zone_id = function_exists('wpc_v2_get_zone_id') ? wpc_v2_get_zone_id() : '';
        return $zone_id !== '';
    }
}


if (!function_exists('wpc_v2_ajax_lazy_cdn_toggle')) {
    function wpc_v2_ajax_lazy_cdn_toggle()
    {
        if (!current_user_can('manage_wpc_settings')
            || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }

        $zone_id = wpc_v2_get_zone_id();
        if ($zone_id === '') {
            wp_send_json_error([
                'msg' => 'No zone_id configured for this site. Reconnect your API key in Settings → Connect to register the zone.',
                'reason' => 'no_zone_id',
            ], 400);
        }

        $enabled = !empty($_POST['enabled']) && $_POST['enabled'] !== '0';


        if ($enabled) {
            $current_pull_enabled = (bool) get_site_option('wpc_v2_pull_enabled', false);
            if (!$current_pull_enabled) {
                update_site_option('wpc_v2_pull_enabled', 1);
                error_log('[WPC ConfigSync] auto-enabled wpc_v2_pull_enabled because lazy_cdn was turned on for zone=' . $zone_id);
            }
        }

        $result  = wpc_v2_config_sync_lazy_enabled($zone_id, $enabled);

        if (empty($result['ok'])) {
            wp_send_json_error([
                'msg' => sprintf(
                    'Could not sync to orchestrator (%s). Please retry; if it persists, contact support.',
                    isset($result['reason']) ? $result['reason'] : 'unknown'
                ),
                'http_code' => isset($result['http_code']) ? $result['http_code'] : 0,
            ], 502);
        }

        wp_send_json_success([
            'zone_id' => $zone_id,
            'enabled' => $enabled,
        ]);
    }
}







if (defined('WP_CLI') && WP_CLI && !class_exists('WPC_V2_LazyCDN_CLI')) {
    class WPC_V2_LazyCDN_CLI
    {
        





        public function enable()
        {
            $this->_toggle(true);
        }

        


        public function disable()
        {
            $this->_toggle(false);
        }

        


        public function status()
        {
            $zone = wpc_v2_get_zone_id();
            $enabled = wpc_v2_get_lazy_enabled();
            \WP_CLI::log('zone_id:      ' . ($zone !== '' ? $zone : '(not configured)'));
            \WP_CLI::log('lazy_enabled: ' . ($enabled ? 'YES' : 'no'));
        }


        public function resync()
        {
            $zone = wpc_v2_get_zone_id();
            if ($zone === '') {
                \WP_CLI::error('No zone_id configured. Reconnect your API key first.');
                return;
            }
            $enabled = function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled();
            
            
            $res = wpc_v2_config_sync_lazy_enabled($zone, $enabled);
            if (!empty($res['ok'])) {
                \WP_CLI::success(sprintf(
                    'Delivered lazy_enabled=%s for zone %s (orch HTTP %s). agencySites.lazy_cdn_active should now read %d.',
                    $enabled ? '1' : '0',
                    $zone,
                    isset($res['http_code']) ? $res['http_code'] : '2xx',
                    $enabled ? 1 : 0
                ));
            } else {
                \WP_CLI::error(sprintf(
                    'Re-sync did NOT land (orch %s / %s) — the orch never received lazy_enabled=%s. Retry, or check connectivity to the orchestrator.',
                    isset($res['http_code']) ? $res['http_code'] : '0',
                    isset($res['reason']) ? $res['reason'] : 'unknown',
                    $enabled ? '1' : '0'
                ));
            }
        }

        private function _toggle($enable)
        {
            $zone = wpc_v2_get_zone_id();
            if ($zone === '') {
                \WP_CLI::error('No zone_id configured. Reconnect your API key first.');
                return;
            }


            if (!defined('WPS_IC_SETTINGS')) {
                \WP_CLI::error('WPS_IC_SETTINGS not defined.');
                return;
            }
            $settings = get_option(WPS_IC_SETTINGS, []);
            if (!is_array($settings)) {
                $settings = [];
            }
            $was_lazy_cdn = (($settings['wpc_optimization_mode'] ?? '') === 'lazy_cdn');
            if ($enable) {
                $settings['wpc_optimization_mode'] = 'lazy_cdn';
            } elseif ($was_lazy_cdn) {
                $settings['wpc_optimization_mode'] = 'legacy';
            }
            update_option(WPS_IC_SETTINGS, $settings);

            $now = function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled();
            \WP_CLI::success(
                ($enable ? 'Enabled' : 'Disabled') . ' lazy_cdn (Optimization Strategy) for zone '
                . $zone . ' — lazy_enabled now ' . ($now ? 'YES' : 'no')
                . '. Orch sync + pull handled by the settings hook.'
            );
        }
    }
    \WP_CLI::add_command('wpc lazy-cdn', 'WPC_V2_LazyCDN_CLI');
}


if (!function_exists('wpc_v2_lazy_cdn_admin_notice')) {
    function wpc_v2_lazy_cdn_admin_notice()
    {
        if (!current_user_can('manage_wpc_settings')) return;


        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !isset($screen->id)) return;
        $allowed_screens = ['dashboard', 'plugins'];
        if (!in_array((string) $screen->id, $allowed_screens, true)) return;

        
        $dismissed = (int) get_user_meta(get_current_user_id(), 'wpc_v2_lazy_cdn_notice_dismissed', true);
        if ($dismissed) return;

        $zone_id = wpc_v2_get_zone_id();
        $enabled = wpc_v2_get_lazy_enabled();
        $nonce   = wp_create_nonce('wps_ic_nonce_action');

        $status_label = $enabled ? 'Enabled' : 'Disabled';
        $action_label = $enabled ? 'Disable' : 'Enable';
        $next_state   = $enabled ? '0' : '1';
        $css_class    = $enabled ? 'notice-success' : 'notice-info';

        $not_configured = ($zone_id === '');


        $brand_name = function_exists('wpc_get_plugin_name')
            ? wpc_get_plugin_name()
            : __('WP Compress', 'wp-compress-image-optimizer');

        
        ?>
        <div class="notice <?php echo esc_attr($css_class); ?> is-dismissible" data-wpc-v2-lazy-cdn-notice style="padding:14px 16px;">
            <div style="margin:0 0 6px;font-size:14px;">
                <strong style="font-size:14px;"><?php echo esc_html($brand_name); ?> — Lazy Backfill</strong>
                <span style="color:#646970;margin-left:8px;">Status: <strong style="color:<?php echo $enabled ? '#00855a' : '#646970'; ?>;"><?php echo esc_html($status_label); ?></strong></span>
            </div>
            <p style="margin:0 0 10px;color:#3c434a;">
                Optimized variants are generated on first browser request and cached for everyone. Saves storage on unused image sizes.
            </p>
            <p style="margin:0;">
                <?php if ($not_configured) : ?>
                    <em style="color:#646970;">Not configured — please reconnect your API key in <strong>Settings → Connect</strong> to register this site's zone.</em>
                <?php else : ?>
                    <button type="button"
                            class="button button-primary"
                            data-wpc-v2-lazy-cdn-toggle
                            data-next-state="<?php echo esc_attr($next_state); ?>"
                            data-nonce="<?php echo esc_attr($nonce); ?>">
                        <?php echo esc_html($action_label); ?> Lazy Backfill
                    </button>
                <?php endif; ?>
            </p>
        </div>
        <script>
        (function(){
            var btn = document.querySelector('[data-wpc-v2-lazy-cdn-toggle]');
            if (!btn) return;
            btn.addEventListener('click', function(e){
                e.preventDefault();
                btn.disabled = true;
                var orig = btn.textContent;
                btn.textContent = 'Saving…';
                var fd = new FormData();
                fd.append('action', 'wpc_v2_lazy_cdn_toggle');
                fd.append('nonce', btn.dataset.nonce);
                fd.append('enabled', btn.dataset.nextState);
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        if (j && j.success) {
                            // Reload so notice re-renders with new state
                            window.location.reload();
                        } else {
                            var msg = (j && j.data && j.data.msg) ? j.data.msg : 'Toggle failed. Please retry.';
                            alert(msg);
                            btn.disabled = false;
                            btn.textContent = orig;
                        }
                    })
                    .catch(function(){
                        alert('Network error. Please retry.');
                        btn.disabled = false;
                        btn.textContent = orig;
                    });
            });
            // Dismiss → remember in user meta via AJAX
            var notice = document.querySelector('[data-wpc-v2-lazy-cdn-notice]');
            if (notice) {
                notice.addEventListener('click', function(e){
                    if (e.target && e.target.classList && e.target.classList.contains('notice-dismiss')) {
                        var fd = new FormData();
                        fd.append('action', 'wpc_v2_lazy_cdn_notice_dismiss');
                        fd.append('nonce', btn ? btn.dataset.nonce : '');
                        fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
                    }
                });
            }
        })();
        </script>
        <?php
    }
}






if (!function_exists('wpc_v2_ajax_lazy_cdn_notice_dismiss')) {
    function wpc_v2_ajax_lazy_cdn_notice_dismiss()
    {
        if (!current_user_can('manage_wpc_settings')
            || !wp_verify_nonce($_POST['nonce'] ?? '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        update_user_meta(get_current_user_id(), 'wpc_v2_lazy_cdn_notice_dismissed', 1);
        wp_send_json_success(['dismissed' => true]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_cdn_notice_dismiss', 'wpc_v2_ajax_lazy_cdn_notice_dismiss');


if (!function_exists('wpc_v2_maybe_sync_image_config')) {
    function wpc_v2_maybe_sync_image_config($old_value = null, $new_value = null)
    {


        $admin_ctx = (function_exists('is_admin') && is_admin())
            || (defined('WP_CLI') && WP_CLI)
            || (defined('DOING_CRON') && DOING_CRON);
        if (!$admin_ctx) {
            return;
        }
        if (!function_exists('wpc_v2_get_zone_id')) {
            return;
        }
        $zone_id = wpc_v2_get_zone_id();
        if ($zone_id === '' && function_exists('wpc_v2_resolve_zone_id')) {
            $zone_id = wpc_v2_resolve_zone_id();
        }


        if ($zone_id === '' || !ctype_digit((string) $zone_id)) {
            if ($zone_id !== '') {
                error_log('[WPC ConfigSync] skip sync: zone_id not a numeric Bunny PZ (' . $zone_id . ')');
            } else {

                error_log('[WPC ConfigSync] skip sync: zone_id empty (not provisioned — the /v2/zone healer resolves it on the next admin page load)');
            }
            return;
        }


        $cfg     = wpc_v2_local_image_config();
        $del     = wpc_v2_delivery_config();
        $new_sig = $cfg['local_quality'] . '|' . $cfg['local_max_width']
            . '|' . $del['delivery_mode'] . '|' . $del['host_lock_mode'] . '|' . $del['host_lock_allow']
            . ($del['redirect_target'] === 'origin' ? '|origin' : '')


            . '|ng:' . $del['nextgen'] . '|img:' . ($del['images_on'] ? '1' : '0');
        $last    = (string) get_option('wpc_v2_synced_image_config', '');


        $lazy_was = is_array($old_value) && ((($old_value['wpc_optimization_mode'] ?? '')) === 'lazy_cdn');
        $lazy_now = is_array($new_value) && ((($new_value['wpc_optimization_mode'] ?? '')) === 'lazy_cdn');
        $lazy_changed = ($lazy_was !== $lazy_now);


        $sync_pending = (bool) get_option('wpc_v2_config_sync_pending', false);

        if ($new_sig === $last && !$lazy_changed && !$sync_pending) {
            return;
        }


        if ($lazy_now && !$lazy_was && !get_site_option('wpc_v2_pull_enabled', false)) {
            update_site_option('wpc_v2_pull_enabled', 1);
            error_log('[WPC ConfigSync] enabled wpc_v2_pull_enabled (lazy_cdn turned on via settings)');
        }


        if ($lazy_changed) {
            update_option('wpc_v2_force_provision', 1, false);
        }


        
        
        wpc_v2_schedule_config_sync();
    }
}
if (defined('WPS_IC_SETTINGS')) {
    add_action('update_option_' . WPS_IC_SETTINGS, 'wpc_v2_maybe_sync_image_config', 20, 2);
}

if (!function_exists('wpc_v2_purge_html_on_delivery_change')) {

    function wpc_v2_purge_html_on_delivery_change($old_value = null, $new_value = null)
    {


        $admin_ctx = (function_exists('is_admin') && is_admin())
            || (defined('WP_CLI') && WP_CLI)
            || (defined('DOING_CRON') && DOING_CRON);
        if (!$admin_ctx || !is_array($new_value)) {
            return;
        }
        $old = is_array($old_value) ? $old_value : [];

        
        $delivery_keys = [
            'wpc_nextgen', 'picture_webp', 'picture_avif', 'generate_webp', 'generate_adaptive',
            'adaptive', 'live-cdn', 'wpc_optimization_mode', 'modern_image_delivery',
            'nativeLazy', 'lazySkipCount', 'lazy-load', 'maxWidth', 'retina', 'retina-in-srcset',
            'css', 'js', 'fonts', 'wpc_edge_origin_bytes',
        ];
        $changed = false;
        foreach ($delivery_keys as $k) {
            $o = array_key_exists($k, $old) ? (is_scalar($old[$k]) ? (string) $old[$k] : wp_json_encode($old[$k])) : null;
            $n = array_key_exists($k, $new_value) ? (is_scalar($new_value[$k]) ? (string) $new_value[$k] : wp_json_encode($new_value[$k])) : null;
            if ($o !== $n) { $changed = true; break; }
        }
        
        if (!$changed) {
            $os = isset($old['serve']) ? wp_json_encode($old['serve']) : null;
            $ns = isset($new_value['serve']) ? wp_json_encode($new_value['serve']) : null;
            if ($os !== $ns) { $changed = true; }
        }
        if (!$changed) {
            return;
        }


        if (function_exists('wp_cache_delete') && defined('WPS_IC_SETTINGS')) {
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete(WPS_IC_SETTINGS, 'options');
        }


        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            if (function_exists('wp_schedule_single_event') && !wp_next_scheduled('wpc_sitechange_trailing')) { wp_schedule_single_event(time() + 8, 'wpc_sitechange_trailing'); } 
        } elseif (function_exists('do_action')) {
            wpc_foreign_purge610(false, 'config-sync');
        }
        error_log('[WPC DeliveryCachePurge] delivery setting changed -> full HTML cache purge');
    }
}
if (defined('WPS_IC_SETTINGS')) {
    add_action('update_option_' . WPS_IC_SETTINGS, 'wpc_v2_purge_html_on_delivery_change', 21, 2);
}


if (!function_exists('wpc_v2_resolve_zone_id')) {
    function wpc_v2_resolve_zone_id($force = false)
    {
        


        if (!$force && function_exists('wpc_v2_get_zone_id')) {
            $existing = wpc_v2_get_zone_id();
            if ($existing !== '' && ctype_digit((string) $existing)) {


                $host_foreign = function_exists('wpc_cdn_zone_is_foreign') && wpc_cdn_zone_is_foreign();
                if (!$host_foreign) return $existing;
                $hh_last = (int) get_option('ic_cdn_zone_hostheal_bk', 0);
                if ((time() - $hh_last) < 600) return $existing;
                update_option('ic_cdn_zone_hostheal_bk', time());
            }
        }

        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        if ($apikey === '' || $orch_url === '') return '';


        if (!$force && function_exists('get_transient') && get_transient('wpc_v2_zone_resolve_backoff')) {
            return '';
        }
        $backoff = function () {
            if (function_exists('set_transient')) {
                set_transient('wpc_v2_zone_resolve_backoff', 1, defined('HOUR_IN_SECONDS') ? 6 * HOUR_IN_SECONDS : 21600);
            }
        };

        $body_raw = wp_json_encode(['apikey' => $apikey]);
        if ($body_raw === false) { $backoff(); return ''; }
        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);

        $resp = wp_remote_post(rtrim($orch_url, '/') . '/v2/zone', [
            'timeout'   => 10,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.08.42'),
            ],
            'body' => $body_raw,
        ]);
        if (is_wp_error($resp)) { $backoff(); return ''; }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code < 200 || $code >= 300) { $backoff(); return ''; }

        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        $zone_id = '';
        if (is_array($data)) {
            
            
            foreach (['zone_id', 'zoneId', 'id'] as $zk) {
                if (!empty($data[$zk])) { $zone_id = (string) $data[$zk]; break; }
            }
            if ($zone_id === '' && !empty($data['zones'][0]['zone_id'])) {
                $zone_id = (string) $data['zones'][0]['zone_id'];
            }
        }


        if (is_array($data)) {
            $zn = '';
            foreach (['zone_name', 'zone_hostname', 'hostname'] as $hk) {
                if (!empty($data[$hk]) && is_string($data[$hk])) { $zn = strtolower(trim($data[$hk])); break; }
            }


            $zn_src = isset($data['zone_name_source']) ? (string) $data['zone_name_source'] : '';
            if ($zn !== '' && $zn_src !== 'stored_fallback' && preg_match('~^[a-z0-9.-]+\.zapwp\.(?:com|net)$~', $zn)) {
                $cur_zone = strtolower(trim((string) get_option('ic_cdn_zone_name', '')));
                if ($zn !== $cur_zone) {
                    update_option('ic_cdn_zone_name', $zn);
                    error_log('[WPC ConfigSync] healed ic_cdn_zone_name ' . $cur_zone . ' -> ' . $zn . ' via /v2/zone (' . ($zn_src ?: 'authoritative') . ')');


                    if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'updateCSSHash')) {
                        wps_ic_cache::updateCSSHash(0);
                    } elseif (function_exists('do_action')) {
                        wpc_foreign_purge610(false, 'config-sync');
                    }
                }
                if (function_exists('home_url') && function_exists('wpc_cdn_norm_host')) {
                    update_option('ic_cdn_zone_name_host', wpc_cdn_norm_host((string) wp_parse_url(home_url(), PHP_URL_HOST)));
                }
            }
        }
        
        $zone_id = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $zone_id);
        if ($zone_id === '') { $backoff(); return ''; }

        update_option('wpc_v2_zone_id', $zone_id, false);
        if (function_exists('delete_transient')) delete_transient('wpc_v2_zone_resolve_backoff');
        error_log('[WPC ConfigSync] auto-resolved zone_id=' . $zone_id . ' from apikey via /v2/zone');
        return $zone_id;
    }
}



add_action('admin_init', function () {
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    $cur = defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : '';
    if ($cur === '' || (string) get_option('wpc_v2_postupdate_sync_ver', '') === $cur) return;
    update_option('wpc_v2_postupdate_sync_ver', $cur, false);
    if (function_exists('delete_transient')) {
        delete_transient('wpc_v2_zone_resolve_backoff');
    }
    if (!wp_next_scheduled('wpc_v2_deferred_config_sync')) {
        wp_schedule_single_event(time() + 30, 'wpc_v2_deferred_config_sync');
    }
    
    
    if (!wp_next_scheduled('wpc_v2_postupdate_purge')) {
        wp_schedule_single_event(time() + 60, 'wpc_v2_postupdate_purge');
    }
    
    
    if (defined('LSCWP_V')) {
        do_action('litespeed_purge_all');
    }
    
    
    if (function_exists('spawn_cron')) {
        wpc_spawn_cron();
    }
    
    
    add_action('shutdown', 'wpc_v2_postupdate_purge_shutdown', PHP_INT_MAX);


    
    if (function_exists('delete_option')) {
        delete_option('wpc-connectivity-status');
    }


    if (function_exists('get_option') && function_exists('update_option') && defined('WPS_IC_SETTINGS')) {
        $wpc_sh_set = get_option(WPS_IC_SETTINGS);
        if (is_array($wpc_sh_set) && !array_key_exists('emit-src-hints', $wpc_sh_set)) {
            $wpc_sh_set['emit-src-hints'] = '1';
            update_option(WPS_IC_SETTINGS, $wpc_sh_set);
        }
    }
    error_log('[WPC ConfigSync] post-update one-shot (v' . $cur . '): healer backoff reset + deferred sync scheduled + html cache flushed + object cache flushed (if persistent) + stale connectivity verdict cleared + src-hints baked-on backfill');
}, 19);



if (!function_exists('wpc_v2_postupdate_purge_shutdown')) {
    function wpc_v2_postupdate_purge_shutdown()
    {
        $fin = false;
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
            $fin = true;
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
            $fin = true;
        }
        if (!$fin) {
            return;
        }
        if (function_exists('ignore_user_abort')) {
            ignore_user_abort(true);
        }
        @set_time_limit(120);
        do_action('wpc_v2_postupdate_purge');
    }
}


add_action('wpc_v2_postupdate_purge', function () {
    try {
        
        $wpc_pp_at = (int) get_option('wpc_v2_pupurge_at', 0);
        if (time() - $wpc_pp_at < 120) {
            return;
        }
        update_option('wpc_v2_pupurge_at', time(), false);
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        } elseif (function_exists('do_action')) {
            wpc_foreign_purge610(false, 'config-sync');
        }
        if (function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache() && function_exists('wp_cache_flush')) {
            if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('v2-postpurge'); } else { @wp_cache_flush(); }
        }
        
        
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }
        if (defined('W3TC') && function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache();
        }
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }
        if (defined('BREEZE_VERSION')) {
            do_action('breeze_clear_all_cache');
        }
        if (class_exists('SiteGround_Optimizer\\Supercacher\\Supercacher')
            && is_callable(['SiteGround_Optimizer\\Supercacher\\Supercacher', 'purge_cache'])) {
            \SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
        }
        
        
        if (defined('WPS_IC_CACHE')) {
            $wpc_gc_n = 0;
            foreach (['css', 'js'] as $wpc_gc_d) {
                $wpc_gc_dir = rtrim(WPS_IC_CACHE, '/') . '/' . $wpc_gc_d;
                if (!is_dir($wpc_gc_dir)) { continue; }
                
                
                foreach (array_merge((array) @glob($wpc_gc_dir . '/*'), (array) @glob($wpc_gc_dir . '/*/*')) as $wpc_gc_f) {
                    if ($wpc_gc_n >= 500) { break 2; }
                    if (is_file($wpc_gc_f) && (time() - (int) @filemtime($wpc_gc_f)) > 14 * DAY_IN_SECONDS) {
                        if (@unlink($wpc_gc_f)) { $wpc_gc_n++; }
                    }
                }
            }
        }
        
        
        if (function_exists('_get_cron_array') && function_exists('_set_cron_array')) {
            
            
            $wpc_cron12 = (array) _get_cron_array();
            $wpc_dirty12 = false;
            $wpc_kept_warm = 0;
            foreach ($wpc_cron12 as $wpc_ts => $wpc_hooks) {
                foreach ((array) $wpc_hooks as $wpc_h => $wpc_events) {
                    
                    
                    $wpc_drop_all = in_array($wpc_h, ['wpc_lcp_repull', 'wpc_combine_fonts_fetch', 'wpc_autopurge_check', 'run_precache_cron_job'], true);
                    $wpc_is_warm  = ($wpc_h === 'wpc_url_warm');
                    if (!$wpc_drop_all && !$wpc_is_warm) {
                        continue;
                    }
                    foreach ((array) $wpc_events as $wpc_sig => $wpc_ev) {
                        if ($wpc_is_warm && $wpc_kept_warm < 5 && (!function_exists('wpc_pipeline_key_junk') || empty($wpc_ev['args'][0]) || !wpc_pipeline_key_junk((string) $wpc_ev['args'][0]))) {
                            $wpc_kept_warm++;
                            continue;
                        }
                        unset($wpc_cron12[$wpc_ts][$wpc_h][$wpc_sig]);
                        $wpc_dirty12 = true;
                        if (empty($wpc_cron12[$wpc_ts][$wpc_h])) {
                            unset($wpc_cron12[$wpc_ts][$wpc_h]);
                        }
                        if (empty($wpc_cron12[$wpc_ts]) || (count($wpc_cron12[$wpc_ts]) === 0)) {
                            unset($wpc_cron12[$wpc_ts]);
                        }
                    }
                }
            }
            if ($wpc_dirty12) {
                _set_cron_array($wpc_cron12);
            }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('postupdate-purge-deferred', '', '', []);
        }
    } catch (\Throwable $e) {
    }
});




add_action('admin_init', function () {


    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    if (!function_exists('wpc_v2_get_zone_id')) return;
    $z = (string) wpc_v2_get_zone_id();
    

    if ($z === '' || !ctype_digit($z)) {
        wpc_v2_resolve_zone_id();
        return;
    }


    if (function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed()
        && !(function_exists('get_transient') && get_transient('wpc_v2_zone_reresolve_bk'))) {
        if (function_exists('set_transient')) set_transient('wpc_v2_zone_reresolve_bk', 1, 600);
        $healed = wpc_v2_resolve_zone_id(true);
        if ($healed !== '' && $healed !== $z && function_exists('error_log')) {
            error_log('[WPC ConfigSync] clone-drift heal: re-resolved stale zone_id ' . $z . ' → ' . $healed . ' (apikey owns the live zone)');
        }
    }


    if (function_exists('wpc_cdn_zone_is_foreign') && wpc_cdn_zone_is_foreign()) {
        wpc_v2_resolve_zone_id();
    }
}, 20);


add_action('admin_init', function () {
    if (!function_exists('wpc_v2_maybe_sync_image_config')) return;
    
    if (!get_option('wpc_v2_config_sync_pending', false)) return;
    
    
    if (function_exists('get_transient') && get_transient('wpc_v2_config_sync_retry_backoff')) return;
    if (function_exists('set_transient')) {
        set_transient('wpc_v2_config_sync_retry_backoff', 1, defined('MINUTE_IN_SECONDS') ? 15 * MINUTE_IN_SECONDS : 900);
    }
    wpc_v2_maybe_sync_image_config();
}, 21);


add_action('admin_init', function () {
    if (!function_exists('wpc_v2_config_sync_lazy_enabled') || !function_exists('wpc_v2_get_zone_id')) return;

    
    if (get_option('wpc_v2_config_sync_pending', false)) return;

    


    $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
    $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
    if ($apikey === '' || $orch_url === '') return;
    $zone_id     = (string) wpc_v2_get_zone_id();
    $has_numeric = ($zone_id !== '' && ctype_digit($zone_id));
    $hb_cname    = trim((string) get_option('ic_custom_cname'));
    if ($hb_cname === '') $hb_cname = trim((string) get_option('ic_cdn_zone_name'));
    if (!$has_numeric && $hb_cname === '') return;


    if (function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed()
        && function_exists('wpc_v2_provision_reset_for_env')) {
        wpc_v2_provision_reset_for_env();
    }


    $force = (bool) get_option('wpc_v2_force_provision', false);

    $ttl  = defined('DAY_IN_SECONDS') ? 180 * DAY_IN_SECONDS : 15552000;
    $last = (int) get_option('wpc_v2_config_synced_at', 0);


    if (!$force && $last > 0 && (time() - $last) < $ttl) return;


    if ($force) {
        if (function_exists('get_transient') && get_transient('wpc_v2_config_force_backoff')) return;
        if (function_exists('set_transient')) {
            set_transient('wpc_v2_config_force_backoff', 1, defined('MINUTE_IN_SECONDS') ? 2 * MINUTE_IN_SECONDS : 120);
        }
    } else {
        if (function_exists('get_transient') && get_transient('wpc_v2_config_heartbeat_backoff')) return;
        if (function_exists('set_transient')) {
            set_transient('wpc_v2_config_heartbeat_backoff', 1, defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600);
        }
    }

    


    wpc_v2_schedule_config_sync();
}, 22);


add_action('admin_init', function () {


    if (function_exists('wpc_v2_provision_host_changed') && wpc_v2_provision_host_changed()
        && function_exists('delete_option')) {
        delete_option('wpc_cf_cname_verified');
    }
    if (get_option('wpc_cf_cname_verified')) return;
    $cfc = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME)) : '';
    if ($cfc === '') return;
    $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
    if (!is_array($cf) || empty($cf['settings']['cdn']) || empty($cf['token'])) return;
    if (function_exists('get_transient') && get_transient('wpc_cf_reverify_bk')) return;
    $wpc_rvat23 = (int) get_option('wpc_cf_reverify_at');
    if (time() - $wpc_rvat23 < 120) return;
    update_option('wpc_cf_reverify_at', time(), false);
    if (function_exists('set_transient')) {
        set_transient('wpc_cf_reverify_bk', 1, 120);
    }
    if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
        @include_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php';
    }
    if (!class_exists('WPC_CloudflareAPI')) return;
    $wpc_cf_api = new WPC_CloudflareAPI($cf['token']);
    if ($wpc_cf_api && $wpc_cf_api->verifyCfCnameLive($cfc, 1, 3)) {
        update_option('wpc_cf_cname_verified', 1, false); 
        update_option('wpc_v2_force_provision', 1, false);
        
        if (function_exists('site_url')) update_option('wpc_v2_provisioned_site_url', (string) site_url(), false);
        if (function_exists('wpc_v2_schedule_config_sync')) {
            wpc_v2_schedule_config_sync();
        }
        
        
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        } elseif (function_exists('do_action')) {
            wpc_foreign_purge610(false, 'config-sync');
        }
    }
}, 23);


add_action('admin_init', function () {
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (!function_exists('get_option') || !class_exists('WPC_Delivery_Resolver')) return;
    if (get_option('wpc_v2_force_provision', false)) return;
    if (!function_exists('wpc_v2_get_zone_id') || !wpc_v2_get_zone_id()) return;
    if (class_exists('wps_rewriteLogic') && wps_rewriteLogic::zone_is_cf()) return;   
    if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) return;
    if (function_exists('get_transient') && get_transient('wpc_v2_admin_reverify_bk')) return;   
    if (method_exists('WPC_Delivery_Resolver', 'resolve_verbose')) {
        $rv = WPC_Delivery_Resolver::resolve_verbose();
        if (isset($rv['tier_name']) && $rv['tier_name'] === 'cdn-edge') return;
    }
    if (function_exists('set_transient')) set_transient('wpc_v2_admin_reverify_bk', 1, 60);
    add_action('shutdown', function () {
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        if (class_exists('WPC_Delivery_Resolver')) { WPC_Delivery_Resolver::resolve(true); }
    });
}, 24);




if (!function_exists('wpc_v2_provheal_due')) {
    function wpc_v2_provheal_due()
    {
        $steps = [60, 180, 600, 3600, 21600, 86400];
        $n     = (int) get_option('wpc_v2_provheal_tries', 0);
        $last  = (int) get_option('wpc_v2_provheal_last', 0);
        return (time() - $last) >= $steps[min(max($n, 0), count($steps) - 1)];
    }
}

if (!function_exists('wpc_v2_provheal_run')) {
    function wpc_v2_provheal_run($why = 'auto')
    {
        if (!function_exists('wpc_v2_verify_and_unsuppress')) return false;
        if (!function_exists('wpc_v2_provision_env_changed') || !wpc_v2_provision_env_changed()) return false;
        if (!function_exists('wpc_v2_get_zone_id') || (string) wpc_v2_get_zone_id() === '') return false;
        if (function_exists('wpc_v2_get_apikey') && (string) wpc_v2_get_apikey() === '') return false;
        if (!wpc_v2_provheal_due()) return false;
        
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) return false;
        if (function_exists('wpc_worker_lock') && !wpc_worker_lock('prov_selfheal', 0)) return false;

        update_option('wpc_v2_provheal_last', time(), false);
        $ok = false;
        try {
            $r  = wpc_v2_verify_and_unsuppress($why);
            $ok = is_array($r) && !empty($r['stamped']);
            if (!$ok) {
                update_option('wpc_v2_provheal_tries', (int) get_option('wpc_v2_provheal_tries', 0) + 1, false);
                error_log('[WPC provheal] ' . $why . ' not passed ('
                    . (is_array($r) ? (string) ($r['reason'] ?? '?') : '?') . ') — backing off');
            } else {
                error_log('[WPC provheal] ' . $why . ' PASSED — fingerprint stamped, CDN un-suppressed');
            }
        } catch (\Throwable $e) {
            error_log('[WPC provheal] error: ' . $e->getMessage());
        }
        if (function_exists('wpc_worker_unlock')) { wpc_worker_unlock('prov_selfheal'); }
        return $ok;
    }
}

add_action('wpc_v2_provheal_cron', function () { wpc_v2_provheal_run('cron'); });

add_action('admin_init', function () {
    if (defined('DOING_AJAX') && DOING_AJAX) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event')
        && !wp_next_scheduled('wpc_v2_provheal_cron')) {
        wp_schedule_event(time() + 600, 'hourly', 'wpc_v2_provheal_cron');
    }
    if (!function_exists('wpc_v2_provision_env_changed') || !wpc_v2_provision_env_changed()) return;
    if (!wpc_v2_provheal_due()) return;
    add_action('shutdown', function () {
        if (function_exists('fastcgi_finish_request'))       { @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
        wpc_v2_provheal_run('admin');
    }, 99);
}, 25);


add_action('admin_init', function () {
    if (function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled()
        && !get_site_option('wpc_v2_pull_enabled', false)) {
        update_site_option('wpc_v2_pull_enabled', 1);
        error_log('[WPC ConfigSync] enabled wpc_v2_pull_enabled (lazy_cdn strategy active)');
    }
}, 23);


add_action('admin_init', function () {
    if (get_option('wpc_v2_lazy_strategy_migrated', false)) return;
    if (!function_exists('wpc_v2_get_zone_id') || !function_exists('wpc_get_optimization_mode')
        || !defined('WPS_IC_SETTINGS')) {
        return;
    }
    $zone = (string) wpc_v2_get_zone_id();
    if ($zone === '') {
        return;
    }
    $flag_on = (get_option('wpc_v2_lazy_enabled_' . sanitize_key($zone), '0') === '1');
    if ($flag_on && wpc_get_optimization_mode() !== 'lazy_cdn') {
        $settings = get_option(WPS_IC_SETTINGS, []);
        if (!is_array($settings)) {
            $settings = [];
        }
        $settings['wpc_optimization_mode'] = 'lazy_cdn';
        update_option(WPS_IC_SETTINGS, $settings);
        error_log('[WPC ConfigSync] migrated legacy lazy flag → lazy_cdn strategy for zone ' . $zone);
    }
    update_option('wpc_v2_lazy_strategy_migrated', 1, false);
}, 21);


if (defined('WPC_RENDER_TIMING') && WPC_RENDER_TIMING
    && (!function_exists('wp_doing_ajax') || !wp_doing_ajax())
    && !(defined('DOING_CRON') && DOING_CRON)
    && function_exists('is_admin') && is_admin()
    && !defined('WPC_RTIME_BOOTED')) {

    define('WPC_RTIME_BOOTED', 1);
    $GLOBALS['wpc_rtime_t0']   = microtime(true);
    $GLOBALS['wpc_rtime_http'] = null;
    $GLOBALS['wpc_rtime_log']  = [];

    
    
    add_filter('http_request_args', function ($args, $url) {
        $GLOBALS['wpc_rtime_http'] = microtime(true);
        return $args;
    }, PHP_INT_MAX, 2);

    add_action('http_api_debug', function ($response, $type, $class, $args, $url) {
        if (!isset($GLOBALS['wpc_rtime_http']) || $GLOBALS['wpc_rtime_http'] === null) return;
        $ms = (int) round((microtime(true) - $GLOBALS['wpc_rtime_http']) * 1000);
        $GLOBALS['wpc_rtime_http'] = null;
        $err = (function_exists('is_wp_error') && is_wp_error($response)) ? ' [ERR]' : '';
        $GLOBALS['wpc_rtime_log'][] = sprintf('%7dms%s  %s', $ms, $err, is_string($url) ? $url : '(unknown url)');
    }, PHP_INT_MAX, 5);

    add_action('shutdown', function () {
        if (empty($GLOBALS['wpc_rtime_t0'])) return;
        $total = (int) round((microtime(true) - $GLOBALS['wpc_rtime_t0']) * 1000);
        $calls = isset($GLOBALS['wpc_rtime_log']) && is_array($GLOBALS['wpc_rtime_log']) ? $GLOBALS['wpc_rtime_log'] : [];
        $sum = 0;
        foreach ($calls as $c) { if (preg_match('/^\s*(\d+)ms/', $c, $m)) $sum += (int) $m[1]; }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $out = "[WPC render-timing] ===== " . $uri . "\n"
             . "[WPC render-timing] total render " . $total . "ms | " . count($calls)
             . " HTTP call(s) summing " . $sum . "ms | non-HTTP " . max(0, $total - $sum) . "ms";
        foreach ($calls as $c) { $out .= "\n[WPC render-timing]   " . $c; }
        error_log($out);
    }, PHP_INT_MAX);
}









if (!function_exists('wpc_v2_config_republish652')) {
    function wpc_v2_config_republish652()
    {
        try {
            if (!apply_filters('wpc_v2_config_republish', true)) {
                return;
            }
            $iv = (int) apply_filters('wpc_v2_config_republish_interval', DAY_IN_SECONDS);
            if ($iv < 3600) {
                $iv = 3600;
            }
            $last = (int) get_option('wpc_v2_config_republish_at', 0);
            if ($last > 0 && (time() - $last) < $iv) {
                return;
            }
            update_option('wpc_v2_config_republish_at', time(), false);
            
            if (function_exists('wpc_v2_schedule_config_sync')) {
                wpc_v2_schedule_config_sync();
            } elseif (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_v2_deferred_config_sync')) {
                wp_schedule_single_event(time() + 30, 'wpc_v2_deferred_config_sync');
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('cb-secret-republish', '', '', []);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_autopurge_sweep', 'wpc_v2_config_republish652', 40);
}
