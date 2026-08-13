<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_o2switch extends wps_ic_integrations {

    public function is_active() {
        return defined('O2SWITCH_VARNISH_PURGE_KEY');
    }

    public function do_checks() {
        // No specific checks needed
    }

    public function fix_setting($setting) {
        // No specific fixes needed
    }

    public function do_admin_filters() {
        return [
            'wps_ic_varnish_purge_headers' => [
                'callback' => 'add_purge_headers',
                'priority' => 10,
                'args' => 1
            ],
            'wps_ic_varnish_purge_url' => [
                'callback' => 'remove_regex_from_url',
                'priority' => 10,
                'args' => 3
            ],
            'wps_ic_purge_all_varnish' => [
                'callback' => 'enable_varnish_purge',
                'priority' => 10,
                'args' => 2
            ]
        ];
    }

    public function add_purge_headers($headers) {
        if (!defined('O2SWITCH_VARNISH_PURGE_KEY')) {
            return $headers;
        }

        $headers['X-VC-Purge-Key'] = O2SWITCH_VARNISH_PURGE_KEY;

        // O2Switch uses X-Purge-Regex header instead of regex in URL
        if (isset($headers['X-Purge-Method']) && 'regex' === $headers['X-Purge-Method']) {
            $headers['X-Purge-Regex'] = '.*';
            unset($headers['X-Purge-Method']);
        }

        return $headers;
    }

    public function remove_regex_from_url($full_url, $main_url, $regex) {
        // O2Switch handles regex via headers, not URL
        return $main_url;
    }

    public function enable_varnish_purge($varnish, $url_key) {
        return true;
    }

}
