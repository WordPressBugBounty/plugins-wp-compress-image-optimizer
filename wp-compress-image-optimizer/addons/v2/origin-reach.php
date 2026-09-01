<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/origin-reach.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.353
 */

if (!defined('ABSPATH')) {
    exit;
}







if (!function_exists('wpc_origin_reach_state68')) {
    
    
    function wpc_origin_reach_state68()
    {
        if (!function_exists('wpc_v2_get_zone_id')) {
            return null;
        }
        $zid = (string) wpc_v2_get_zone_id();
        if ($zid === '') {
            return null;
        }
        $m = get_option('wpc_v2_origin_reach_' . sanitize_key($zid));
        if (!is_array($m) || empty($m['v']) || empty($m['t'])) {
            return null;
        }
        $ttl = ($m['v'] === 'ok') ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
        if ((time() - (int) $m['t']) > (int) apply_filters('wpc_origin_reach_ttl', $ttl, (string) $m['v'])) {
            return null;
        }
        return (string) $m['v'];
    }
}

if (!function_exists('wpc_origin_reach_act68')) {
    function wpc_origin_reach_act68()
    {
        if (!apply_filters('wpc_origin_reach_act', true)) {
            return 'off';
        }
        $state = wpc_origin_reach_state68();
        if ($state === null || $state === 'ok') {
            return 'no-op';
        }
        if ($state === 'blocked') {
            
            return wpc_origin_lane_switch68('blocked');
        }

        
        
        
        if (get_option('wpc_origin_heal_off') === '1' || !apply_filters('wpc_origin_heal', true)) {
            return 'heal-off';
        }
        $zid = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
        $hs_key = 'wpc_origin_heal_state_' . sanitize_key($zid);
        $hs = get_option($hs_key);
        $hs = is_array($hs) ? $hs : [];
        if (!empty($hs['at']) && (time() - (int) $hs['at']) < DAY_IN_SECONDS) {
            
            
            return 'heal-cooldown';
        }

        $options = get_option(WPS_IC_OPTIONS);
        $apikey = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        $zone_name = trim((string) get_option('ic_cdn_zone_name'));
        $cname = trim((string) get_option(defined('WPS_IC_CF_CNAME') ? WPS_IC_CF_CNAME : 'wps_ic_cf_cname', ''));
        if ($cname === '') {
            $cname = trim((string) get_option('ic_custom_cname', ''));
        }
        update_option($hs_key, ['at' => time(), 'verified' => null, 'state' => $state], false);
        if ($apikey === '' || $zone_name === '' || $cname === '') {
            
            
            wpc_origin_reach_log68('heal-skip', $state, ['why' => 'no-cname-or-key']);
        } else {
            wp_remote_get(WPS_IC_KEYSURL . '?action=cdn_setcname_v6&apikey=' . urlencode($apikey)
                . '&cname=' . urlencode($cname) . '&zone_name=' . urlencode($zone_name)
                . '&time=' . time() . '&no_cache=' . md5((string) mt_rand(999, 9999)),
                ['timeout' => 25, 'sslverify' => false,
                 'user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'wpc']);
            wpc_origin_reach_log68('heal-fired', $state, ['cname' => $cname]);
        }
        
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_origin_reach_verify')) {
            wp_schedule_single_event(time() + 90, 'wpc_origin_reach_verify');
        }
        return 'heal-fired';
    }
}

if (!function_exists('wpc_origin_reach_verify68')) {
    
    
    
    function wpc_origin_reach_verify68()
    {
        $state = wpc_origin_reach_state68();
        if ($state === null || $state === 'ok' || $state === 'blocked') {
            return 'no-op';
        }
        $zid = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
        $hs_key = 'wpc_origin_heal_state_' . sanitize_key($zid);
        $hs = get_option($hs_key);
        $hs = is_array($hs) ? $hs : [];
        $options = get_option(WPS_IC_OPTIONS);
        $apikey = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        if ($apikey === '' || !function_exists('wp_upload_dir')) {
            return 'unknown';
        }
        $up = wp_upload_dir();
        if (empty($up['basedir']) || !is_writable($up['basedir'])) {
            return 'unknown';
        }
        $nonce = function_exists('wp_generate_password')
            ? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', wp_generate_password(32, false)))
            : md5(microtime(true) . mt_rand());
        $file = $up['basedir'] . '/wpc-verify-' . $nonce . '.txt';
        if (@file_put_contents($file, $nonce) === false) {
            return 'unknown';
        }
        $r = wp_remote_get(WPS_IC_KEYSURL . '?action=test_origin_fetch&apikey=' . urlencode($apikey)
            . '&nonce=' . urlencode($nonce) . '&time=' . time(),
            ['timeout' => (int) apply_filters('wpc_origin_fetch_timeout', 25), 'sslverify' => false,
             'user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'wpc']);
        @unlink($file);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            return 'unknown';
        }
        $b = json_decode((string) wp_remote_retrieve_body($r), true);
        $d = (is_array($b) && isset($b['data']) && is_array($b['data'])) ? $b['data'] : (is_array($b) ? $b : []);
        $st = isset($d['status']) ? (int) $d['status'] : 0;
        if ($st === 200 && !empty($d['body_match'])) {
            $hs['verified'] = 'ok';
            update_option($hs_key, $hs, false);
            wpc_origin_reach_log68('healed', $state, ['pod' => 200]);
            return 'healed';
        }
        if ($st === 403 || $st === 503 || !empty($d['cf_mitigated'])) {
            $hs['verified'] = 'fail';
            update_option($hs_key, $hs, false);
            return wpc_origin_lane_switch68($state . ':heal-failed');
        }
        return 'unknown';
    }
}

if (!function_exists('wpc_origin_lane_switch68')) {
    
    
    
    function wpc_origin_lane_switch68($reason)
    {
        if (get_option('wpc_delivery_lane') === 'local') {
            
            
            
            
            $wpc_cur68 = get_option(WPS_IC_SETTINGS);
            if (!is_array($wpc_cur68) || empty($wpc_cur68['live-cdn']) || $wpc_cur68['live-cdn'] === '0') {
                return 'already-local';
            }
        }
        if (get_option('wpc_lane_autoswitch_off') === '1' || !apply_filters('wpc_lane_autoswitch', true)) {
            return 'switch-off';
        }
        $s = get_option(WPS_IC_SETTINGS);
        if (!is_array($s)) {
            return 'no-settings';
        }
        $s['serve'] = ['jpg' => '0', 'png' => '0', 'gif' => '0', 'svg' => '0', 'fonts' => '0'];
        $s['css'] = 0;
        $s['js'] = 0;
        $s['fonts'] = 0;
        $s['live-cdn'] = '0';
        if (!isset($s['local']) || !is_array($s['local'])) {
            $s['local'] = [];
        }
        $s['local']['media-library'] = '1';
        update_option(WPS_IC_SETTINGS, $s);
        update_option('wpc_delivery_lane', 'local', false);
        update_option('wpc_delivery_lane_reason', (string) $reason . '@' . time(), false);
        update_option('wpc_lane_notice', '1', false);
        if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeAll')) {
            wps_ic_cache_integrations::purgeAll(false, true, false, true, true);
        }
        wpc_origin_reach_log68('lane-switch', (string) $reason, []);
        return 'switched';
    }
}

if (!function_exists('wpc_origin_reach_recovered68')) {
    
    
    function wpc_origin_reach_recovered68()
    {
        if (get_option('wpc_delivery_lane') === 'local' && get_option('wpc_lane_recover_seen') !== '1') {
            update_option('wpc_lane_recover_notice', '1', false);
        }
        
        $zid = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
        if ($zid !== '') {
            delete_option('wpc_origin_heal_state_' . sanitize_key($zid));
        }
    }
}

if (!function_exists('wpc_origin_reach_log68')) {
    function wpc_origin_reach_log68($ev, $state, $data = [])
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('origin-reach', $ev, (string) $state, is_array($data) ? $data : []);
        }
        if (function_exists('error_log')) {
            error_log('[WPC origin-reach] ' . $ev . ' state=' . $state);
        }
    }
}

add_action('wpc_origin_reach_act', 'wpc_origin_reach_act68');
add_action('wpc_origin_reach_verify', 'wpc_origin_reach_verify68');



add_action('admin_init', function () {
    if (wp_doing_ajax() || get_transient('wpc_origin_reach_tick68')) {
        return;
    }
    set_transient('wpc_origin_reach_tick68', 1, 6 * HOUR_IN_SECONDS);
    $state = function_exists('wpc_origin_reach_state68') ? wpc_origin_reach_state68() : null;
    if ($state === null || $state === 'ok') {
        return;
    }
    if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
        && !wp_next_scheduled('wpc_origin_reach_act')) {
        wp_schedule_single_event(time() + 10, 'wpc_origin_reach_act');
    }
}, 30);


add_action('admin_init', function () {
    if (empty($_GET['wpc_lane_notice_dismiss']) || !current_user_can('manage_options')
        || !wp_verify_nonce((string) ($_GET['_wpcnonce'] ?? ''), 'wpc_lane_notice')) {
        return;
    }
    if ($_GET['wpc_lane_notice_dismiss'] === 'switch') {
        delete_option('wpc_lane_notice');
    } elseif ($_GET['wpc_lane_notice_dismiss'] === 'recover') {
        delete_option('wpc_lane_recover_notice');
        update_option('wpc_lane_recover_seen', '1', false);
    }
}, 5);

add_action('admin_notices', function () {
    if (!current_user_can('manage_options') && !current_user_can('manage_wpc_settings')) {
        return;
    }
    $dismiss = function ($which) {
        return esc_url(wp_nonce_url(add_query_arg('wpc_lane_notice_dismiss', $which), 'wpc_lane_notice', '_wpcnonce'));
    };
    if (get_option('wpc_lane_notice') === '1') {
        echo '<div class="notice notice-info"><p>'
            . esc_html__('Your host blocks our optimization servers, so WP Compress switched this site to Smart Delivery (serves optimized files from your server). Nothing to do — or allowlist our fetchers and switch back in Settings.', WPS_IC_TEXTDOMAIN)
            . ' <a href="' . $dismiss('switch') . '">' . esc_html__('Dismiss', WPS_IC_TEXTDOMAIN) . '</a></p></div>';
    }
    if (get_option('wpc_lane_recover_notice') === '1') {
        echo '<div class="notice notice-success"><p>'
            . esc_html__('Origin reachable again — you can switch back to CDN delivery in Settings.', WPS_IC_TEXTDOMAIN)
            . ' <a href="' . $dismiss('recover') . '">' . esc_html__('Dismiss', WPS_IC_TEXTDOMAIN) . '</a></p></div>';
    }
});
