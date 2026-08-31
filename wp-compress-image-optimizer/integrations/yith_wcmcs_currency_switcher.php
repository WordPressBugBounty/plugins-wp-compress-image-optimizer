<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/yith_wcmcs_currency_switcher.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_yith_wcmcs_currency_switcher extends wps_ic_integrations {

    const COOKIE_NAME = 'yith_wcmcs_currency';

    public function is_active() {
        return defined('YITH_WCMCS_VERSION') || function_exists('yith_wcmcs_init');
    }

    public function do_checks() {
        
    }

    public function fix_setting($setting) {
        return false;
    }

    public function add_admin_hooks() {
        return [];
    }


    public function do_admin_filters() {
        if (get_option('yith_wcmcs_change_currency_method') !== 'ajax') {
            return [];
        }

        return [
            'wps_ic_cache_cookies' => [
                'callback' => 'add_currency_cookie',
                'priority' => 10,
                'args'     => 1,
            ],
            'wps_ic_mandatory_cookies' => [
                'callback' => 'add_currency_cookie',
                'priority' => 10,
                'args'     => 1,
            ],
        ];
    }

    public function add_currency_cookie( $cookies ) {
        if ( ! in_array( self::COOKIE_NAME, $cookies, true ) ) {
            $cookies[] = self::COOKIE_NAME;
        }
        return $cookies;
    }

}
