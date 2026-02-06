<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_breeze extends wps_ic_integrations {

    public function is_active() {
        return class_exists('Breeze_PurgeCache') || defined('BREEZE_VERSION');
    }

    public function do_checks() {
        // No specific checks needed
    }

    public function fix_setting($setting) {
        // No specific fixes needed
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
        // Action hook
        do_action('breeze_clear_all_cache');

        // Class method
        if (class_exists('Breeze_PurgeCache') && is_callable(['Breeze_PurgeCache', 'breeze_cache_flush'])) {
            call_user_func(['Breeze_PurgeCache', 'breeze_cache_flush']);
        }

        // Full cache clear
        if (defined('BREEZE_VERSION')) {
            global $wp_filesystem;
            require_once(ABSPATH . 'wp-admin/includes/file.php');

            WP_Filesystem();

            $cache_path = breeze_get_cache_base_path(is_network_admin(), true);
            $wp_filesystem->rmdir(untrailingslashit($cache_path), true);

            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }
    }

}
