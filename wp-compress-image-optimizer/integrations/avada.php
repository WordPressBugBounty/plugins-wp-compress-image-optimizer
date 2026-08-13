<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_avada extends wps_ic_integrations {

    public function is_active() {
        return $this->is_avada_theme();
    }

    public function do_checks() {
        // No specific checks needed
    }

    public function fix_setting($setting) {
        // No specific fixes needed
    }

    private function is_avada_theme() {
        $current_theme = wp_get_theme();
        return 'avada' === strtolower($current_theme->get('Name')) || 'avada' === strtolower($current_theme->get_template());
    }

    public function add_admin_hooks() {
        return [
            'avada_clear_dynamic_css_cache' => [
                'callback' => 'purge_cache',
                'priority' => 10,
                'args' => 1
            ],
            'fusion_cache_reset_after' => [
                'callback' => 'purge_cache',
                'priority' => 10,
                'args' => 1
            ]
        ];
    }

    public function purge_cache($arg = false) {
        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll();
    }

}
