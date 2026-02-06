<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_cloudways extends wps_ic_integrations {

    public function is_active() {
        return $this->is_varnish_running();
    }

    public function do_checks() {
        // No specific checks needed
    }

    public function fix_setting($setting) {
        // No specific fixes needed
    }

    private function is_varnish_running() {
        if (!isset($_SERVER['HTTP_X_VARNISH'])) {
            return false;
        }

        if (!isset($_SERVER['HTTP_X_APPLICATION'])) {
            return false;
        }

        return ('varnishpass' !== trim(strtolower($_SERVER['HTTP_X_APPLICATION'])));
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
        if (!$this->is_varnish_running()) {
            return $varnish_ips;
        }

        if (!is_array($varnish_ips)) {
            $varnish_ips = (array) $varnish_ips;
        }

        $varnish_ips[] = '127.0.0.1:8080';

        return $varnish_ips;
    }

    public function enable_varnish_purge($varnish, $url_key) {
        if ($this->is_varnish_running()) {
            return true;
        }
        return $varnish;
    }

}
