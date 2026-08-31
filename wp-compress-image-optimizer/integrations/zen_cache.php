<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/zen_cache.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_zen_cache extends wps_ic_integrations {

    public function is_active() {
        return is_callable(['zencache', 'clear']);
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
        if (is_callable(['zencache', 'clear'])) {
            call_user_func(['zencache', 'clear']);
        }
    }

}
