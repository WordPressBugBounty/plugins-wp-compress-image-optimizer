<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/w3_total_cache.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_w3_total_cache extends wps_ic_integrations {

    public function is_active() {
        return function_exists('w3tc_pgcache_flush') || class_exists('W3_Plugin_TotalCacheAdmin');
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
        
        if (function_exists('w3tc_pgcache_flush')) {
            w3tc_pgcache_flush();
        }

        
        if (class_exists('W3_Plugin_TotalCacheAdmin')) {
            $plugin_totalcacheadmin = &w3_instance('W3_Plugin_TotalCacheAdmin');
            $plugin_totalcacheadmin->flush_all();
        }
    }

}
