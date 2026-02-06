<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_godaddy extends wps_ic_integrations {

    private $vip_url = '';

    public function __construct() {
        parent::__construct();
        $this->vip_url = method_exists('\WPaas\Plugin', 'vip') ? \WPaas\Plugin::vip() : '';
    }

    public function is_active() {
        return class_exists('\WPaas\Plugin');
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
        if (empty($this->vip_url)) {
            return;
        }

        $url = !empty($url_key) ? $this->get_url_from_key($url_key) : home_url();
        $host = wp_parse_url($url, PHP_URL_HOST);

        $url = untrailingslashit(set_url_scheme(str_replace($host, $this->vip_url, $url), 'http'));

        // Flush object cache
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }

        // Update option to flush APC cache across server
        update_option('gd_system_last_cache_flush', time());

        // Purge Varnish with BAN method
        wp_remote_request(
            esc_url_raw($url),
            [
                'method'   => 'BAN',
                'blocking' => false,
                'headers'  => [
                    'Host' => $host,
                ],
            ]
        );
    }

    private function get_url_from_key($url_key) {
        if (class_exists('wps_ic_url_key')) {
            $key_class = new wps_ic_url_key();
            return $key_class->getUrlFromKey($url_key);
        }
        return home_url();
    }

}
