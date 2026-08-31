<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/wp_fastest_cache.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_wp_fastest_cache extends wps_ic_integrations {

    public function is_active() {
        return function_exists('wpfc_clear_all_cache');
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
        if (function_exists('wpfc_clear_all_cache')) {
            wpfc_clear_all_cache();
        }
    }

}
