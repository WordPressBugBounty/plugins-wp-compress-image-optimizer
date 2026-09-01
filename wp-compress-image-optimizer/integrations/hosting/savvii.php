<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/hosting/savvii.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_savvii extends wps_ic_integrations {

    public function is_active() {
        return defined('SAVVII_VERSION') || class_exists('Savvii\CacheFlusherPlugin');
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
        
        do_action('warpdrive_domain_flush');
    }

}
