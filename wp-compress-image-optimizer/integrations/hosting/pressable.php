<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/hosting/pressable.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_pressable extends wps_ic_integrations {

    public function is_active() {
        return defined('IS_PRESSABLE') && IS_PRESSABLE;
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
        if (function_exists('wp_cache_flush')) {
            if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('pressable'); } else { @wp_cache_flush(); }
        }
    }

}
