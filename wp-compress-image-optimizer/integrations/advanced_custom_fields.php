<?php
if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_advanced_custom_fields extends wps_ic_integrations {

    public function is_active() {
        return class_exists('ACF') || function_exists('acf');
    }

    public function do_checks() {
        
    }

    public function fix_setting($setting) {
        
    }

    public function add_admin_hooks() {
        return [
            'acf/save_post' => [
                'callback' => 'purge_cache_on_options_save',
                'priority' => 10,
                'args' => 1
            ]
        ];
    }

    public function purge_cache_on_options_save($post_id) {
        
        if ($post_id === 'options') {
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll();
        }
    }

}
