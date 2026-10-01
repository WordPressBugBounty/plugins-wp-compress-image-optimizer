<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_swift_performance extends wps_ic_integrations {

    public function is_active() {
        return is_callable(['Swift_Performance_Cache', 'clear_all_cache']);
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
        if (is_callable(['Swift_Performance_Cache', 'clear_all_cache'])) {
            call_user_func(['Swift_Performance_Cache', 'clear_all_cache']);
        }
    }

}
