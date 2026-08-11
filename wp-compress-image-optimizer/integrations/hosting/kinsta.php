<?php
if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_kinsta extends wps_ic_integrations {

    public function is_active() {
        return isset($GLOBALS['kinsta_cache']);
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
        global $kinsta_cache;

        if (!empty($kinsta_cache) && !empty($kinsta_cache->kinsta_cache_purge)) {
            
            $kinsta_cache->kinsta_cache_purge->purge_complete_caches();
        }
    }

}
