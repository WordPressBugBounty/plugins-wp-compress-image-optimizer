<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/bridge.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_bridge extends wps_ic_integrations {

    public function is_active() {
        return $this->is_bridge_theme();
    }

    public function do_checks() {
        
    }

    public function fix_setting($setting) {
        
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

        
        if (isset($old_value['custom_css'], $new_value['custom_css']) &&
            $old_value['custom_css'] !== $new_value['custom_css']) {
            $clear = true;
        }

        
        if (isset($old_value['custom_svg_css'], $new_value['custom_svg_css']) &&
            $old_value['custom_svg_css'] !== $new_value['custom_svg_css']) {
            $clear = true;
        }

        
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
