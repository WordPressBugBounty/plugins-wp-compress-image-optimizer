<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/hosting/wp_serveur.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_wp_serveur extends wps_ic_integrations {

    public function is_active() {
        return defined('WP_SERVEUR');
    }

    public function do_checks() {
        
    }

    public function fix_setting($setting) {
        
    }

    public function do_admin_filters() {
        return [
            'wps_ic_purge_all_varnish' => [
                'callback' => 'enable_varnish_purge',
                'priority' => 10,
                'args' => 2
            ]
        ];
    }

    public function enable_varnish_purge($varnish, $url_key) {
        return true;
    }

}
