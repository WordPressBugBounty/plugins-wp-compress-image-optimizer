<?php
if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_hummingbird extends wps_ic_integrations {

    public function is_active() {
        return defined('WPHB_VERSION');
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
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }
    }

}
