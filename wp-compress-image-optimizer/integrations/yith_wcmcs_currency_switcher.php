<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_yith_wcmcs_currency_switcher extends wps_ic_integrations {

    const COOKIE_NAME = 'yith_wcmcs_currency';

    public function is_active() {
        return defined('YITH_WCMCS_VERSION') || function_exists('yith_wcmcs_init');
    }

    public function do_checks() {
        // No conflicting settings to auto-fix for this plugin
    }

    public function fix_setting($setting) {
        return false;
    }

    public function add_admin_hooks() {
        return [];
    }

    /**
     * When YITH uses the AJAX currency-switching method it sets the
     * yith_wcmcs_currency cookie on the client.  We need to:
     *  1. Make it a cache-cookie  → separate cache files per currency value.
     *  2. Make it mandatory       → never serve a cached page when the cookie
     *                               is absent (visitor has no known currency).
     */
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
