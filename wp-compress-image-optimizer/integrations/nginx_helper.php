<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_nginx_helper extends wps_ic_integrations {

    public function is_active() {
        return class_exists('Nginx_Helper');
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
        if (class_exists('Nginx_Helper')) {
            global $nginx_purger;
            if (isset($nginx_purger) && method_exists($nginx_purger, 'purge_all')) {
                $nginx_purger->purge_all();
            }
        }
    }

}
