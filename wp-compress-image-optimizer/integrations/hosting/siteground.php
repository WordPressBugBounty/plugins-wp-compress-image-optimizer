<?php
if (!defined('ABSPATH')) {
    exit; 
}

class wps_ic_siteground extends wps_ic_integrations {

    public function is_active() {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        
        if ( is_plugin_active( 'sg-cachepress/sg-cachepress.php' ) ) {
            return true;
        }

        
        return self::is_siteground_server();
    }

    



    public static function is_siteground_server() {
        if ( ! empty( ini_get( 'open_basedir' ) ) ) {
            return false;
        }
        return @file_exists( '/etc/yum.repos.d/baseos.repo' ) && @file_exists( '/Z' );
    }

    public function do_checks() {
        
    }

    public function fix_setting($setting) {
        
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


        
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            self::bust_object_cache();
            return;
        }

        
        
        if (class_exists('\SiteGround_Optimizer\Supercacher\Supercacher') &&
            method_exists('\SiteGround_Optimizer\Supercacher\Supercacher', 'purge_cache')) {
            \SiteGround_Optimizer\Supercacher\Supercacher::purge_cache();
            self::bust_object_cache();
            return;
        }

        
        if (isset($GLOBALS['sg_cachepress_supercacher']) &&
            $GLOBALS['sg_cachepress_supercacher'] instanceof \SG_CachePress_Supercacher &&
            method_exists($GLOBALS['sg_cachepress_supercacher'], 'purge_cache')) {
            $GLOBALS['sg_cachepress_supercacher']->purge_cache();
            self::bust_object_cache();
            return;
        }

        
        if (self::purge_via_socket()) {
            self::bust_object_cache();
            return;
        }

        
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

    





    private static function purge_via_socket() {
        $socket_file = '/chroot/tmp/site-tools.sock';

        if ( ! @file_exists( $socket_file ) ) {
            return false;
        }

        
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

        
        fwrite( $fp, json_encode( $request, JSON_FORCE_OBJECT ) . "\n" );
        $response = fgets( $fp, 32 * 1024 );
        fclose( $fp );

        $result = @json_decode( $response, true );

        
        if ( false === $result || isset( $result['err_code'] ) ) {
            return false;
        }

        return true;
    }

    



    private static function purge_file_cache() {
        $cache_dir = WP_CONTENT_DIR . '/cache/sgo-cache/';

        if ( is_dir( $cache_dir ) && class_exists( 'wps_ic_cache_integrations' ) ) {
            wps_ic_cache_integrations::removeDirectory( $cache_dir );
        }
    }

}
