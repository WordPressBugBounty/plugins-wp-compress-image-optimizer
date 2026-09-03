<?php
/*
 * Plugin name: WP Compress – Instant Performance & Speed Optimization
 * Plugin URI: https://www.wpcompress.com
 * Author: WP Compress
 * Author URI: https://www.wpcompress.com
 * Version: 7.22.38
 * Description: Automatically compress and optimize images to shrink image file size, improve  times and boost SEO ranks - all without lifting a finger after setup.
 * Text Domain: wp-compress-image-optimizer
 * Domain Path: /languages
 */


if (!defined('WPC_PLUGIN_VERSION')) {
    define('WPC_PLUGIN_VERSION', '7.22.38');
}


if (!function_exists('wpc_tier_override736')) {
    function wpc_tier_override736($wpc_cap736 = true)
    {
        if (!isset($_GET['wpc_tier'])) {
            return '';
        }
        $wpc_t736 = strtolower(preg_replace('/[^A-Za-z]/', '', (string) $_GET['wpc_tier']));
        if (!in_array($wpc_t736, ['control', 'free', 'local', 'edge'], true)) {
            return '';
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_tier_override_enabled', true)) {
            return '';
        }
        
        
        
        
        
        
        $wpc_auth736 = ($wpc_cap736 && function_exists('wp_get_current_user')
            && function_exists('current_user_can') && current_user_can('manage_options'));
        if (!$wpc_auth736 && isset($_GET['wpc_key'])
            && class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'tierKey')) {
            
            
            $wpc_tk743 = wps_ic_url_key::tierKey(false);
            $wpc_auth736 = ($wpc_tk743 !== '' && hash_equals($wpc_tk743, (string) $_GET['wpc_key']));
        }
        if (!$wpc_auth736) {
            return '';
        }
        if (!defined('WPC_TIER_ACTIVE')) {
            define('WPC_TIER_ACTIVE', true);
        }
        $wpc_tc739 = (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'tierCacheOn'))
            ? wps_ic_url_key::tierCacheOn() : false;
        if (!$wpc_tc739 && !defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if ($wpc_t736 === 'control') {
            $_GET['disableWPC'] = 'true';
            return $wpc_t736;
        }
        if ($wpc_t736 === 'free') {
            $_GET['crit'] = '0';
        }
        $wpc_map736 = [
            'free'  => ['live-cdn' => '0', 'modern_image_delivery' => '0', 'used-css' => '0', 'picture_avif' => '0', 'generate_webp' => '0', 'generate_adaptive' => '0'],
            'local' => ['live-cdn' => '0', 'modern_image_delivery' => '1', 'used-css' => '1', 'picture_avif' => '1', 'generate_webp' => '1'],
            'edge'  => ['live-cdn' => '1', 'modern_image_delivery' => '1', 'used-css' => '1', 'picture_avif' => '1', 'generate_webp' => '1'],
        ];
        $wpc_ov736 = $wpc_map736[$wpc_t736];
        $wpc_opt736 = defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings';
        add_filter('option_' . $wpc_opt736, function ($wpc_v736) use ($wpc_ov736) {
            if (!is_array($wpc_v736)) {
                return $wpc_v736;
            }
            foreach ($wpc_ov736 as $wpc_k736 => $wpc_val736) {
                $wpc_v736[$wpc_k736] = $wpc_val736;
            }
            return $wpc_v736;
        }, PHP_INT_MAX);
        
        
        
        
        if ($wpc_t736 !== 'edge') {
            
            
            add_filter('option_wps_ic_allow_live', function () { return '0'; }, PHP_INT_MAX);
            add_filter('default_option_wps_ic_allow_live', function () { return '0'; }, PHP_INT_MAX);
        }
        return $wpc_t736;
    }
    
    
    
    
    
    if (wpc_tier_override736(false) === '' && function_exists('add_action')) {
        add_action('plugins_loaded', 'wpc_tier_override736', 0);
    }
}


$wpc_disabled_fns = array_filter(array_map('trim', explode(',', (string) (function_exists('ini_get') ? ini_get('disable_functions') : ''))));
$wpc_can_shim = function ($fn) use ($wpc_disabled_fns) {
    if (function_exists($fn)) return false;
    if (PHP_VERSION_ID < 80000 && in_array($fn, $wpc_disabled_fns, true)) return false;  
    return true;
};
if ($wpc_can_shim('getmypid'))           { function getmypid() { return 0; } }
if ($wpc_can_shim('set_time_limit'))     { function set_time_limit($seconds) { return false; } }
if ($wpc_can_shim('ignore_user_abort'))  { function ignore_user_abort($enable = null) { return 0; } }
if ($wpc_can_shim('opcache_reset'))      { function opcache_reset() { return false; } }
if ($wpc_can_shim('opcache_invalidate')) { function opcache_invalidate($filename, $force = false) { return false; } }
if ($wpc_can_shim('opcache_get_status')) { function opcache_get_status($include_scripts = true) { return false; } }


if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
    
    
    
    if (function_exists('sys_getloadavg')) {
        $wpc_l329 = @sys_getloadavg();
        if (is_array($wpc_l329) && isset($wpc_l329[0])) {
            $wpc_c329 = 0;
            $wpc_ci329 = @is_readable('/proc/cpuinfo') ? (string) @file_get_contents('/proc/cpuinfo') : '';
            if ($wpc_ci329 !== '' && preg_match_all('/^processor\s*:/m', $wpc_ci329, $wpc_pm329)) {
                $wpc_c329 = max(1, count($wpc_pm329[0]));
            } else {
                $wpc_cg329 = @is_readable('/sys/fs/cgroup/cpu.max') ? trim((string) @file_get_contents('/sys/fs/cgroup/cpu.max')) : '';
                if ($wpc_cg329 !== '' && preg_match('/^(\d+)\s+(\d+)$/', $wpc_cg329, $wpc_cm329) && (int) $wpc_cm329[2] > 0) {
                    $wpc_c329 = max(1, (int) ceil((int) $wpc_cm329[1] / (int) $wpc_cm329[2]));
                } else {
                    $wpc_q329 = @is_readable('/sys/fs/cgroup/cpu/cpu.cfs_quota_us') ? (int) trim((string) @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_quota_us')) : 0;
                    $wpc_p329 = @is_readable('/sys/fs/cgroup/cpu/cpu.cfs_period_us') ? (int) trim((string) @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_period_us')) : 0;
                    if ($wpc_q329 > 0 && $wpc_p329 > 0) {
                        $wpc_c329 = max(1, (int) ceil($wpc_q329 / $wpc_p329));
                    }
                }
            }
            if ($wpc_c329 > 0 && (float) $wpc_l329[0] > $wpc_c329 * 2.0) {
                @header('Retry-After: 30');
                if (function_exists('http_response_code')) { http_response_code(503); }
                exit;
            }
        }
    }
    @ignore_user_abort(true);
    @set_time_limit(60);
}


if (!function_exists('wpc_request_is_https')) {
    function wpc_request_is_https()
    {
        if (function_exists('is_ssl') && is_ssl()) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_CF_VISITOR'])
            && strpos((string) $_SERVER['HTTP_CF_VISITOR'], 'https') !== false) {
            return true;
        }


        if (function_exists('home_url') && strpos((string) home_url(), 'https://') === 0) {
            return true;
        }
        return false;
    }
}
if (!function_exists('wpc_request_scheme')) {
    function wpc_request_scheme()
    {
        return wpc_request_is_https() ? 'https' : 'http';
    }
}
if (!function_exists('wpc_heal_mixed_content')) {


    function wpc_heal_mixed_content($html)
    {
        if (!is_string($html) || $html === '' || !wpc_request_is_https()) {
            return $html;
        }
        $hosts = [];
        if (function_exists('home_url')) {
            $h = parse_url((string) home_url(), PHP_URL_HOST);
            if ($h) {
                $hosts[] = $h;
            }
        }
        if (function_exists('site_url')) {
            $h = parse_url((string) site_url(), PHP_URL_HOST);
            if ($h) {
                $hosts[] = $h;
            }
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            $hosts[] = (string) $_SERVER['HTTP_HOST'];
        }
        $hosts = array_unique(array_filter($hosts));
        foreach ($hosts as $host) {
            if (strpos($html, 'http://' . $host) !== false) {
                $html = str_replace('http://' . $host, 'https://' . $host, $html);
            }
        }
        return $html;
    }
}


if (!empty($_SERVER['REQUEST_URI'])) {
    $wpc_req_uri = (string) $_SERVER['REQUEST_URI'];


    if (strpos($wpc_req_uri, '/wp-json/wpc/v2/bg_swap') !== false
        || strpos($wpc_req_uri, '/wp-json/wpc/v2/healthcheck') !== false
        || strpos($wpc_req_uri, 'rest_route=/wpc/v2/bg_swap') !== false
        || strpos($wpc_req_uri, 'rest_route=/wpc/v2/healthcheck') !== false
        || strpos($wpc_req_uri, 'rest_route=%2Fwpc%2Fv2%2Fbg_swap') !== false
        || strpos($wpc_req_uri, 'rest_route=%2Fwpc%2Fv2%2Fhealthcheck') !== false) {
        if (!defined('WPC_IS_BG_SWAP')) {
            define('WPC_IS_BG_SWAP', true);
        }
    }
    unset($wpc_req_uri);
}


if (!empty($_POST['action'])) {
    $wpc_ajax_action = (string) $_POST['action'];


    if ($wpc_ajax_action === 'wps_ic_variant_count'
        || $wpc_ajax_action === 'wps_ic_media_library_heartbeat'
        || $wpc_ajax_action === 'wps_ic_bulkCompressHeartbeat'
        || $wpc_ajax_action === 'wps_ic_image_stats'
        || $wpc_ajax_action === 'wpc_ic_start_bulk_compress'
        || $wpc_ajax_action === 'wps_ic_compress_live') {
        if (!defined('WPC_IS_LIGHT_AJAX')) {
            define('WPC_IS_LIGHT_AJAX', true);
        }
    }
    unset($wpc_ajax_action);
}





if (function_exists('add_filter')) {
    add_filter('cron_schedules', function ($schedules) {
        if (is_array($schedules) && !isset($schedules['wpc_v2_5min'])) {
            $schedules['wpc_v2_5min'] = ['interval' => 300, 'display' => 'Every 5 minutes (WPC v2)'];
        }
        return $schedules;
    });
}

if (!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC'])){
    include __DIR__ . '/classes/cache-integrations.class.php';
}

if ((!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC']) && ((defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)))) {
    
    include __DIR__ . '/wp-compress-cron.php';
}


if (defined('WP_CLI') && WP_CLI && !isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC'])) {
    include __DIR__ . '/wp-compress-cli.php';
}

if (!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC']) && !(defined('DOING_CRON') && DOING_CRON) && !(defined('WP_CLI') && WP_CLI) && !(defined('REST_REQUEST') && REST_REQUEST)) {
    
    if (get_option('pause_wpcompress_plugin')) {
        add_action('admin_init', 'pause_wpcompress_plugin_deactivate_delete');
        require_once(ABSPATH . 'wp-includes/pluggable.php');
        wp_safe_redirect(admin_url('plugins.php'));
    } else if (get_option('pause_wpcompress_plugin_full_delete')) {
        define('WPC_CC_PLUGIN_FILE', __FILE__);
        include_once __DIR__ . '/wp-compress-core.php';
        add_action('admin_init', 'wpc_delete_and_remove_data');
    } else {
        define('WPC_CC_PLUGIN_FILE', __FILE__);
        include_once __DIR__ . '/wp-compress-core.php';
    }

    function pause_wpcompress_plugin_deactivate_delete()
    {
        if (!function_exists('deactivate_plugins') || !function_exists('delete_plugins')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        define('WPC_CC_PLUGIN_FILE', __FILE__);
        include_once __DIR__ . '/wp-compress-core.php';
        deactivate_plugins('wp-compress-image-optimizer/wp-compress.php');

        delete_plugins(['wp-compress-image-optimizer/wp-compress.php']);

        $active_plugins = get_option('active_plugins');
        $plugin_slug = 'wp-compress-image-optimizer/wp-compress.php';
        $key = array_search($plugin_slug, $active_plugins);
        if ($key !== false) {
            unset($active_plugins[$key]);
            update_option('active_plugins', $active_plugins);
        }

        delete_option('pause_wpcompress_plugin');
    }
}