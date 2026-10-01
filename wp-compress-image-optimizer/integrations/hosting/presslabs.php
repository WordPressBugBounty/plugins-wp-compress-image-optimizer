<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_presslabs extends wps_ic_integrations {

    public function is_active() {
        return (defined( 'PL_INSTANCE_REF' ) && class_exists( '\Presslabs\Cache\CacheHandler' ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ));
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
        if (class_exists('\Presslabs\Cache\CacheHandler')) {
            $cache_handler = new \Presslabs\Cache\CacheHandler();
            $key_class = new wps_ic_url_key();

            if (!empty($url_key)) {
                $url = $key_class->getUrlFromKey($url_key);
                $cache_handler->invalidate_url($url, true);
                $cache_handler->purge_cache('listing');
            } else {
                $cache_handler->invalidate_url(home_url('/'), true);
            }
        }
    }

}
