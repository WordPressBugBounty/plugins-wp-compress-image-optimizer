<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/beaverbuilder.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_beaverbuilder extends wps_ic_integrations {

    public function is_active() {
        return defined('FL_BUILDER_VERSION');
    }

    public function do_checks() {
        
    }

    public function fix_setting($setting) {
        
    }

    public function add_admin_hooks() {
        return [
            'fl_builder_before_save_layout' => [
                'callback' => 'purge_cache',
                'priority' => 10,
                'args' => 1
            ],
            'fl_builder_cache_cleared' => [
                'callback' => 'purge_cache',
                'priority' => 10,
                'args' => 1
            ]
        ];
    }

    public function purge_cache($post_id = false) {
        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll();
    }

}
