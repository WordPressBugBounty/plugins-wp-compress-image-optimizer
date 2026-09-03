<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/hosting/flywheel.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_flywheel extends wps_ic_integrations {

    public function is_active() {
        return defined('FLYWHEEL_CONFIG_DIR');
    }

    public function do_checks() {
        
    }

    public function fix_setting($setting) {
        
    }

    public function do_admin_filters() {
        return [
            'wps_ic_varnish_ips' => [
                'callback' => 'add_varnish_ip',
                'priority' => 10,
                'args' => 1
            ],
            'wps_ic_purge_all_varnish' => [
                'callback' => 'enable_varnish_purge',
                'priority' => 10,
                'args' => 2
            ]
        ];
    }

    public function add_varnish_ip($varnish_ips) {
        if (!is_array($varnish_ips)) {
            $varnish_ips = (array) $varnish_ips;
        }
        $varnish_ips[] = '127.0.0.1';
        return $varnish_ips;
    }

    public function enable_varnish_purge($varnish, $url_key) {
        return true;
    }

}
