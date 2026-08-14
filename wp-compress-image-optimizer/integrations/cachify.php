<?php
if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_cachify extends wps_ic_integrations {

    public function is_active() {
        return function_exists('cachify_flush_cache');
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
        if (function_exists('cachify_flush_cache')) {
            cachify_flush_cache();
        }
    }

}
