<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_bridge extends wps_ic_integrations {

    public function is_active() {
        return $this->is_bridge_theme();
    }

    public function do_checks() {
        // No specific checks needed
    }

    public function fix_setting($setting) {
        // No specific fixes needed
    }

    private function is_bridge_theme() {
        $current_theme = wp_get_theme();
        return 'Bridge' === $current_theme->get('Name');
    }

    public function add_admin_hooks() {
        return [
            'update_option_qode_options_proya' => [
                'callback' => 'purge_cache',
                'priority' => 10,
                'args' => 2
            ]
        ];
    }

    public function purge_cache($old_value = [], $new_value = []) {
        $clear = false;

        // Check if custom CSS changed
        if (isset($old_value['custom_css'], $new_value['custom_css']) &&
            $old_value['custom_css'] !== $new_value['custom_css']) {
            $clear = true;
        }

        // Check if custom SVG CSS changed
        if (isset($old_value['custom_svg_css'], $new_value['custom_svg_css']) &&
            $old_value['custom_svg_css'] !== $new_value['custom_svg_css']) {
            $clear = true;
        }

        // Check if custom JS changed
        if (isset($old_value['custom_js'], $new_value['custom_js']) &&
            $old_value['custom_js'] !== $new_value['custom_js']) {
            $clear = true;
        }

        if ($clear) {
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll();
        }
    }

}
