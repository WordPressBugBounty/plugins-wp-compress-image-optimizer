<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/hosting/spinupwp.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_spinupwp extends wps_ic_integrations {

    public function is_active() {
        return function_exists('spinupwp_purge_site');
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
        if (function_exists('spinupwp_purge_site')) {
            spinupwp_purge_site();
        }
    }

}
