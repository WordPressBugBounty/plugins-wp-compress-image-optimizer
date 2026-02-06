<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_pagely extends wps_ic_integrations {

    public function is_active() {
        return class_exists('PagelyCachePurge');
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
        if (class_exists('PagelyCachePurge')) {
            $purger = new PagelyCachePurge();
            if (method_exists($purger, 'purgeAll')) {
                $purger->purgeAll();
            }
        }
    }

}
