<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_siteground extends wps_ic_integrations {

    public function is_active() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active( 'sg-cachepress/sg-cachepress.php' );
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
        // Try SiteGround Optimizer (new version)
        if (class_exists('\SiteGround_Optimizer\Supercacher\Supercacher') &&
            method_exists('\SiteGround_Optimizer\Supercacher\Supercacher', 'purge_cache')) {
            \SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
            return;
        }

        // Try SG CachePress (older version)
        if (isset($GLOBALS['sg_cachepress_supercacher']) &&
            $GLOBALS['sg_cachepress_supercacher'] instanceof SG_CachePress_Supercacher &&
            method_exists($GLOBALS['sg_cachepress_supercacher'], 'purge_cache')) {
            $GLOBALS['sg_cachepress_supercacher']->purge_cache();
            return;
        }

        // Try function
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
        }
    }

}
