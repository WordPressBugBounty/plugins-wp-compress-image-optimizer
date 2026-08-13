<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_wpengine extends wps_ic_integrations {

    public function is_active() {
        return class_exists('WpeCommon');
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
            ],
            // When WPE clears their cache, also purge ours
            'wpe_cache_flush' => [
                'callback' => 'on_wpe_purge',
                'priority' => 10,
                'args' => 0
            ]
        ];
    }

    public function on_wpe_purge() {
        if (!class_exists('wps_ic_cache_integrations')) {
            return;
        }

        // Only purge HTML cache — critical CSS and CDN are separate concerns
        $cache = new wps_ic_cache_integrations();
        $cache::purgeCacheFiles();
    }

    public function purge_cache($url_key = false) {
        if (class_exists('WpeCommon')) {
            $methods = [];
            if (method_exists('WpeCommon', 'purge_memcached')) {
                WpeCommon::purge_memcached();
                $methods[] = 'memcached';
            }
            if (method_exists('WpeCommon', 'purge_varnish_cache')) {
                WpeCommon::purge_varnish_cache();
                $methods[] = 'varnish';
            }
            if (method_exists('WpeCommon', 'clear_cdn_cache')) {
                WpeCommon::clear_cdn_cache();
                $methods[] = 'cdn';
            }

            $log = get_option('wpc_purge_debug_log', []);
            $log[] = date('Y-m-d H:i:s') . ' | WPE purged: ' . implode(', ', $methods);
            $log = array_slice($log, -20);
            update_option('wpc_purge_debug_log', $log, false);
        }
    }

}
