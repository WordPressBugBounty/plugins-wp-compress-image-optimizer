<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_wp_cloudflare_page_cache extends wps_ic_integrations {

    public function is_active() {
        return is_plugin_active('wp-cloudflare-page-cache/wp-cloudflare-super-page-cache.php');
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
        if (is_plugin_active('wp-cloudflare-page-cache/wp-cloudflare-super-page-cache.php')) {
            if (!empty($url_key)) {
                $key_class = new wps_ic_url_key();
                $url = $key_class->getUrlFromKey($url_key);
                do_action('swcfpc_purge_cache', [$url]);
            } else {
                do_action('swcfpc_purge_cache');
            }
        }
    }

}
