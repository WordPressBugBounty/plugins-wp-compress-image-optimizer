<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/studiopress.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_studiopress extends wps_ic_integrations {

    public function is_active() {
        return isset($GLOBALS['sp_accel_nginx_proxy_cache_purge'])
            && is_a($GLOBALS['sp_accel_nginx_proxy_cache_purge'], 'SP_Accel_Nginx_Proxy_Cache_Purge');
    }

    public function do_checks() {
        
    }

    public function fix_setting($setting) {
        
    }

    public function add_admin_hooks() {
        return [
            'wps_ic_purge_all_cache' => [
                'callback' => 'purge_cache',
                'priority' => 10,
                'args' => 1
            ]
        ];
    }

    public function purge_cache($url_key = false) {
        if (isset($GLOBALS['sp_accel_nginx_proxy_cache_purge'])
            && is_a($GLOBALS['sp_accel_nginx_proxy_cache_purge'], 'SP_Accel_Nginx_Proxy_Cache_Purge')) {
            $GLOBALS['sp_accel_nginx_proxy_cache_purge']->cache_flush_theme();
        }
    }

}
