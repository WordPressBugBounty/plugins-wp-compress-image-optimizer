<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_simplecustomcss extends wps_ic_integrations {

    public function is_active() {
        return defined('SCCSS_FILE');
    }

    public function do_checks() {
        // No specific checks needed
    }

    public function fix_setting($setting) {
        // No specific fixes needed
    }

    public function add_admin_hooks() {
        return [
            'update_option_sccss_settings' => [
                'callback' => 'purge_cache',
                'priority' => 10,
                'args' => 2
            ]
        ];
    }

    public function purge_cache($old_value = null, $new_value = null) {
        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll();
    }

}
