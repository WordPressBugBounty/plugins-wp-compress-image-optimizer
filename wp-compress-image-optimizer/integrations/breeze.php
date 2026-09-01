<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/breeze.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_breeze extends wps_ic_integrations {

    public function is_active() {
        return class_exists('Breeze_PurgeCache') || defined('BREEZE_VERSION');
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
        
        do_action('breeze_clear_all_cache');

        
        if (class_exists('Breeze_PurgeCache') && is_callable(['Breeze_PurgeCache', 'breeze_cache_flush'])) {
            call_user_func(['Breeze_PurgeCache', 'breeze_cache_flush']);
        }

        
        if (defined('BREEZE_VERSION')) {
            global $wp_filesystem;
            require_once(ABSPATH . 'wp-admin/includes/file.php');

            WP_Filesystem();

            $cache_path = breeze_get_cache_base_path(is_network_admin(), true);
            $wp_filesystem->rmdir(untrailingslashit($cache_path), true);

            if (function_exists('wp_cache_flush')) {
                if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('breeze'); } else { @wp_cache_flush(); }
            }
        }
    }

}
