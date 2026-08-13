<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_siteground extends wps_ic_integrations {

    public function is_active() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // SG Optimizer plugin active — use plugin APIs
        if ( is_plugin_active( 'sg-cachepress/sg-cachepress.php' ) ) {
            return true;
        }

        // Native: detect SiteGround hosting without plugin
        return self::is_siteground_server();
    }

    /**
     * Detect SiteGround hosting environment.
     * Uses the same check as SG's own Helper_Service::is_siteground().
     */
    public static function is_siteground_server() {
        if ( ! empty( ini_get( 'open_basedir' ) ) ) {
            return false;
        }
        return @file_exists( '/etc/yum.repos.d/baseos.repo' ) && @file_exists( '/Z' );
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


        // 1. Official all-layers purge (Dynamic + File + Memcached) — the correct single call.
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            self::bust_object_cache();
            return;
        }

        // 2. Modern Supercacher (dynamic/file) — older builds without the public function. This does NOT
        //    flush Memcached, so we bust the object cache explicitly below (no early return before it).
        if (class_exists('\SiteGround_Optimizer\Supercacher\Supercacher') &&
            method_exists('\SiteGround_Optimizer\Supercacher\Supercacher', 'purge_cache')) {
            \SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
            self::bust_object_cache();
            return;
        }

        // 3. SG CachePress (older version) — also dynamic-only, so bust the object cache too.
        if (isset($GLOBALS['sg_cachepress_supercacher']) &&
            $GLOBALS['sg_cachepress_supercacher'] instanceof \SG_CachePress_Supercacher &&
            method_exists($GLOBALS['sg_cachepress_supercacher'], 'purge_cache')) {
            $GLOBALS['sg_cachepress_supercacher']->purge_cache();
            self::bust_object_cache();
            return;
        }

        // 4. Native fallback: UNIX socket to SiteGround Site Tools service (dynamic cache).
        if (self::purge_via_socket()) {
            self::bust_object_cache();
            return;
        }

        // 5. Last resort: delete file cache directly + bust object cache.
        self::purge_file_cache();
        self::bust_object_cache();
    }


    private static function bust_object_cache() {
        if (function_exists('wp_cache_delete')) {
            @wp_cache_delete('alloptions', 'options');
            if (defined('WPS_IC_SETTINGS')) {
                @wp_cache_delete(WPS_IC_SETTINGS, 'options');
            }
        }
    }

    /**
     * Purge SiteGround dynamic cache via Site Tools UNIX socket.
     * Mirrors SG's own Supercacher::flush_dynamic_cache() implementation.
     *
     * @return bool True if socket call succeeded.
     */
    private static function purge_via_socket() {
        $socket_file = '/chroot/tmp/site-tools.sock';

        if ( ! @file_exists( $socket_file ) ) {
            return false;
        }

        // Extract hostname without www (same as SG's get_site_tools_matching_domain)
        $hostname = wp_parse_url( home_url(), PHP_URL_HOST );
        if ( empty( $hostname ) ) {
            return false;
        }
        $hostname = preg_replace( '/^www\./i', '', $hostname );

        $request = array(
            'api'      => 'domain-all',
            'cmd'      => 'update',
            'settings' => array( 'json' => 1 ),
            'params'   => array(
                'flush_cache' => '1',
                'id'          => $hostname,
                'path'        => '(.*)',
            ),
        );

        $fp = @stream_socket_client( 'unix://' . $socket_file, $errno, $errstr, 5 );
        if ( false === $fp ) {
            return false;
        }

        // SG uses JSON_FORCE_OBJECT flag
        fwrite( $fp, json_encode( $request, JSON_FORCE_OBJECT ) . "\n" );
        $response = fgets( $fp, 32 * 1024 );
        fclose( $fp );

        $result = @json_decode( $response, true );

        // Check for errors (matches SG's own error handling)
        if ( false === $result || isset( $result['err_code'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Delete SiteGround file cache directory directly.
     * Fallback when socket is unavailable.
     */
    private static function purge_file_cache() {
        $cache_dir = WP_CONTENT_DIR . '/cache/sgo-cache/';

        if ( is_dir( $cache_dir ) && class_exists( 'wps_ic_cache_integrations' ) ) {
            wps_ic_cache_integrations::removeDirectory( $cache_dir );
        }
    }

}
