<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/varnish_http_purge.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_varnish_http_purge extends wps_ic_integrations {

    public function is_active() {
        return class_exists('VarnishPurger');
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
        if (!class_exists('VarnishPurger')) {
            return;
        }

        $url = home_url('/?vhp-regex');
        $p = wp_parse_url($url);
        $path = '';
        $pregex = '.*';

        
        if (defined('VHP_VARNISH_IP') && VHP_VARNISH_IP) {
            $varniship = VHP_VARNISH_IP;
        } else {
            $varniship = get_option('vhp_varnish_ip');
        }

        if (isset($p['path'])) {
            $path = $p['path'];
        }

        $schema = apply_filters('varnish_http_purge_schema', 'http://');

        
        if (!empty($varniship)) {
            $purgeme = $schema . $varniship . $path . $pregex;
        } else {
            $purgeme = $schema . $p['host'] . $path . $pregex;
        }

        wp_remote_request(
            $purgeme,
            [
                'method' => 'PURGE',
                'blocking' => false,
                'headers' => [
                    'host' => $p['host'],
                    'X-Purge-Method' => 'regex',
                ],
            ]
        );
    }

}
