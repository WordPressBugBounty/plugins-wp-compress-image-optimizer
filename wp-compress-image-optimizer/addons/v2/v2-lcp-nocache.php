<?php
if (!defined('ABSPATH')) {
    exit;
}


add_action('send_headers', function () {


    if (function_exists('wpc_cache_first_enabled') && wpc_cache_first_enabled()) {
        return;
    }

    if (is_admin()
        || (defined('REST_REQUEST') && REST_REQUEST)
        || (defined('DOING_CRON') && DOING_CRON)
        || (defined('WP_CLI') && WP_CLI)) {
        return;
    }
    if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string) $_SERVER['REQUEST_METHOD']) !== 'GET') {
        return;
    }
    if (function_exists('is_user_logged_in') && is_user_logged_in()) {
        return;
    }
    if (headers_sent() || !class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
        return;
    }


    $wpc_shared_cache = apply_filters('wpc_has_shared_cache', !(defined('WPC_NO_SHARED_CACHE') && WPC_NO_SHARED_CACHE));
    if (!$wpc_shared_cache) {
        return;
    }

    
    $url = (is_ssl() ? 'https://' : 'http://')
        . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
        . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?');
    $key = (new wps_ic_url_key())->setup($url);
    if ($key === '') {
        return;
    }
    $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/';

    
    


    if (!@file_exists($dir . 'critical_desktop.css')) {
        $wpc_crit_pending = function_exists('get_transient') && (
            get_transient('wpc_crit_regen_pending')
            || get_transient('wpc_critical_key_' . $key)
        );
        if ($wpc_crit_pending) {
            
            
            $wpc_pin0 = (int) get_option('wpc_crit_pin_started');
            if ($wpc_pin0 && (time() - $wpc_pin0) > 3600) {
                delete_option('wpc_crit_pin_started');
                $wpc_pin0 = 0;
            }
            if (!$wpc_pin0) {
                update_option('wpc_crit_pin_started', time());
                $wpc_pin0 = time();
            }
            if ((time() - $wpc_pin0) <= 600) {
                if (!defined('DONOTCACHEPAGE')) {
                    define('DONOTCACHEPAGE', true);
                }
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
                header('Pragma: no-cache');
            }
        }
        return;
    }


    if (apply_filters('wpc_lcp_async', true)) {
        return;
    }
    if (@is_readable($dir . 'lcp.json')) {
        return;
    }
    if (!@is_readable($dir . 'lcp_url.txt')) {
        return;
    }
    $lcp_url = trim((string) @file_get_contents($dir . 'lcp_url.txt'));
    if ($lcp_url === '') {
        return;
    }
    if (function_exists('get_transient') && (int) get_transient('wpc_lcp_healn_' . md5($lcp_url)) >= 15) {
        return;
    }


    $wpc_crit_age_cap = (int) apply_filters('wpc_lcp_nocache_max_seconds', 120);
    $wpc_crit_mtime   = @filemtime($dir . 'critical_desktop.css');
    if ($wpc_crit_age_cap > 0 && $wpc_crit_mtime && (time() - $wpc_crit_mtime) > $wpc_crit_age_cap) {
        return;
    }

    
    if (!defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);           
    }
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
    header('Pragma: no-cache');
}, 0);
