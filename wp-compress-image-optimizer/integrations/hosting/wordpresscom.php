<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/hosting/wordpresscom.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_wordpresscom extends wps_ic_integrations {

    public function is_active() {
        if (defined('IS_WPCOM') && IS_WPCOM) return true;
        if (defined('ATOMIC_SITE_ID')) return true;
        if (isset($_SERVER['ATOMIC_SITE_ID'])) return true;
        return false;
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
            if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('wpcom'); } else { @wp_cache_flush(); }
        }
    }

}
