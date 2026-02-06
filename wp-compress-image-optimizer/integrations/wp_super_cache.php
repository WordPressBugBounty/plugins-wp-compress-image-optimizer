<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_wp_super_cache extends wps_ic_integrations {

    public function is_active() {
        return function_exists('wp_cache_clear_cache');
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
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
        }
    }

}
