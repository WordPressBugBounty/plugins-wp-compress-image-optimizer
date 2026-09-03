<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/upgrader.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */



class wps_ic_upgrader extends wps_ic
{
    public static $options;

    public function __construct()
    {
        if (!$this->is_latest() || !empty($_GET['force_update'])) {


            
            if (empty($_GET['force_update'])) {
                if (get_transient('wpc_legacy_upgrade_lock')) {
                    return;
                }
                set_transient('wpc_legacy_upgrade_lock', 1, 5 * MINUTE_IN_SECONDS);

                $wpc_tries_key = 'wpc_legacy_upgrade_tries_' . md5((string) parent::$version);
                $wpc_tries = (int) get_option($wpc_tries_key, 0);
                if ($wpc_tries >= 3) {
                    
                    
                    
                    
                    foreach (['wpc_purge_allow_foreign_ajax', 'wpc_purge_allow_heartbeat', 'wpc_purge_allow_low_value'] as $wpc_g717b) {
                        add_filter($wpc_g717b, '__return_true');
                    }
                    try {
                        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                            wps_ic_cache::cfPurgeAllHtml(true, true);
                        }
                    } catch (\Throwable $e) {
                    } finally {
                        foreach (['wpc_purge_allow_foreign_ajax', 'wpc_purge_allow_heartbeat', 'wpc_purge_allow_low_value'] as $wpc_g717b) {
                            remove_filter($wpc_g717b, '__return_true');
                        }
                    }
                    update_option('wpc_version', parent::$version);
        update_option('wpc_diag_until', time() + 72 * 3600, true);
                    delete_option($wpc_tries_key);
                    return;
                }
                update_option($wpc_tries_key, $wpc_tries + 1, false);
            }

            
            
            
            
            if (!empty($_GET['force_update'])) {
                if ((function_exists('current_user_can') && current_user_can('manage_options'))
                    || (defined('WPC_PERF_DEBUG_TOKEN') && isset($_GET['t'])
                        && hash_equals((string) WPC_PERF_DEBUG_TOKEN, (string) $_GET['t']))) {
                    $this->run_upgrade_work334(); 
                }
                return;
            }
            
            
            
            
            
            
            if (function_exists('fastcgi_finish_request')) {
                register_shutdown_function(function () {
                    @fastcgi_finish_request();
                    @set_time_limit(180);
                    try {
                        $this->run_upgrade_work334();
                    } catch (\Throwable $e) {
                    }
                });
            } else {
                if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_legacy_upgrade_lane')) {
                    wp_schedule_single_event(time(), 'wpc_legacy_upgrade_lane');
                    if (function_exists('spawn_cron')) {
                        register_shutdown_function('spawn_cron');
                    }
                }
            }
        }
    }

    public function run_upgrade_work334()
    {
        
        
        
        
        
        
        
        $wpc_allow717 = '__return_true';
        foreach (['wpc_purge_allow_foreign_ajax', 'wpc_purge_allow_heartbeat', 'wpc_purge_allow_low_value'] as $wpc_g717) {
            add_filter($wpc_g717, $wpc_allow717);
        }
        try {
            $this->run_upgrade_work334_inner717();
        } finally {
            foreach (['wpc_purge_allow_foreign_ajax', 'wpc_purge_allow_heartbeat', 'wpc_purge_allow_low_value'] as $wpc_g717) {
                remove_filter($wpc_g717, $wpc_allow717);
            }
        }
    }

    public function run_upgrade_work334_inner717()
    {
        
        
        
        wpc_opcache_refresh('upgrade');

        self::$options = get_option(WPS_IC_OPTIONS);

        if (file_exists(WPS_IC_LOG . 'local_script_decode.txt')) {
            unlink(WPS_IC_LOG . 'local_script_decode.txt');
        }

        if (file_exists(WPS_IC_LOG . 'local_script_encode_2.txt')) {
            unlink(WPS_IC_LOG . 'local_script_encode_2.txt');
        }

        
        $this->purge_cdn();

        
        $this->update_to_latest();

        
        
        
        
        
        
        
        
        if (apply_filters('wpc_settings_coherence_heal', true)) {
            $wpc_sh934 = get_option(WPS_IC_SETTINGS);
            if (is_array($wpc_sh934)
                && isset($wpc_sh934['delay-js-v2']) && $wpc_sh934['delay-js-v2'] == '1'
                && isset($wpc_sh934['delay-js-v3']) && $wpc_sh934['delay-js-v3'] === '0') {
                $wpc_sh934['delay-js-v3'] = '1';
                update_option(WPS_IC_SETTINGS, $wpc_sh934);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('coherence-heal', '', '', ['k' => 'delay-js-v3', 'to' => '1']);
                }
            }
        }

        
        
        
        
        
        
        if (function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed()
            && function_exists('wpc_v2_provision_reset_for_env') && function_exists('wpc_v2_provision_ensure_bg')
            && apply_filters('wpc_env_heal_armtrip', true)) {
            wpc_v2_provision_reset_for_env();
            wpc_v2_provision_ensure_bg('upgrade-env');
        }

        
        $this->api_notify();


        delete_option('wpc_legacy_upgrade_tries_' . md5((string) parent::$version));
    }


    public function api_notify()
    {


        if (get_transient('wpc_api_notify_done')) {
            return;
        }
        set_transient('wpc_api_notify_done', 1, 4 * HOUR_IN_SECONDS);

        $apikey = self::$options['api_key'];
        $siteurl = urlencode(site_url());
        $zone_name = get_option('ic_cdn_zone_name');
        $site_type = is_multisite() ? 'multisite' : 'single';

        
        $uri = WPS_IC_KEYSURL . '?action=upgrade_notify&apikey=' . $apikey . '&site_type=' . $site_type . '&domain=' . $siteurl . '&zone_name=' . $zone_name . '&plugin_version=' . self::$version . '&hash=' . md5(time()) . '&time_hash=' . time();


        $get = wp_remote_get($uri, ['timeout' => 3, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($get) == 200) {
            $body = wp_remote_retrieve_body($get);
            $body = json_decode($body);
            $zonename = $body->data;

            if ($body->success) {
                if (!empty($zonename) && $zonename != '') {
                    
                }
            }
        }
    }


    public function upgrade()
    {
        return;
        $old_settings = get_option(WPS_IC_SETTINGS);
        $default_Settings = [
            'js' => '0',
            'css' => '0',
            'css_image_urls' => '0',
            'external-url' => '0',
            'replace-all-link' => '0',
            'emoji-remove' => '0',
            'remove-duplicated-fontawesome' => 0,
            'disable-oembeds' => '0',
            'disable-gutenber' => '0',
            'disable-dashicons' => '0',
            'on-upload' => '1',
            'defer-js' => '0',
            'serve' => ['jpg' => '1', 'png' => '1', 'gif' => '1', 'svg' => '1'],
            'search-through' => 'html',
            'preserve-exif' => '0',
            'background-sizing' => '0',
            'remove-render-blocking' => '0',
            'minify-css' => '0',
            'minify-js' => '0',
            'fonts' => '0',
        ];

        foreach ($default_Settings as $name => $defaultValue) {
            if (!isset($old_settings[$name]) || empty($old_settings[$name])) {
                $old_settings[$name] = $defaultValue;
            }
        }

        update_option(WPS_IC_SETTINGS, $old_settings);
    }


    public function update_to_latest()
    {
        $oldOptions = $options = get_option(WPS_IC_OPTIONS);

        $CSSHash = substr(md5(microtime(true)), 0, 6);
        $JSHash = strrev($CSSHash);

        $options['css_hash'] = $CSSHash;
        $options['js_hash'] = $JSHash;
        $options['lazy_hash'] = substr(md5($CSSHash . 'lz'), 0, 10);

        if (!class_exists('wps_ic_log')) {
            include_once WPS_IC_DIR . 'classes/log.class.php';
        }

        if (class_exists('wps_ic_log')) {
            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'update_to_latest');
        }

        update_option(WPS_IC_OPTIONS, $options);


        if (class_exists('wps_ic_cache_integrations')) {
            wps_ic_cache_integrations::purgeAll(false, true, false, true, true, true);
        }

        
        
        
        
        
        
        
        if (apply_filters('wpc_purge_cf_on_upgrade', true)
            && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
            try {
                wps_ic_cache::cfPurgeAllHtml(true, true);
            } catch (\Throwable $e) {
            }
        }

        
        
        
        
        
        
        
        if (apply_filters('wpc_purge_cf_on_upgrade', true)
            && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
            try {
                wps_ic_cache::cfPurgeAllHtml(true, true);
            } catch (\Throwable $e) {
            }
        }


        if (get_option('wpc_settings_initialized') !== '1') {
            $wpc_s = get_option(WPS_IC_SETTINGS);
            if (is_array($wpc_s) && count($wpc_s) > 3) {
                update_option('wpc_settings_initialized', '1', false);
            }
        }

        update_option('wpc_version', parent::$version);
        update_option('wpc_diag_until', time() + 72 * 3600, true);
    }


    public function is_latest()
    {


        $running = (string) parent::$version;
        if (!preg_match('/^\d+\.\d+/', $running)) {
            return true;
        }

        $plugin_version = get_option('wpc_version');

        if (empty($plugin_version) || version_compare($plugin_version, $running, '<')) {
            
            return false;
        } else {
            return true;
        }
    }


    public function purge_cdn()
    {
        self::purgeBreeze();
        self::purge_cache_files();


        
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }
    }


    public static function purgeBreeze()
    {
        if (defined('BREEZE_VERSION')) {
            global $wp_filesystem;
            require_once(ABSPATH . 'wp-admin/includes/file.php');

            WP_Filesystem();

            $cache_path = breeze_get_cache_base_path(is_network_admin(), true);
            $wp_filesystem->rmdir(untrailingslashit($cache_path), true);

            if (function_exists('wp_cache_flush')) {
                if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('breeze'); } else { @wp_cache_flush(); }
            }
        }
    }


    public static function purge_cache_files()
    {
        $cache_dir = WPS_IC_CACHE;


        if (class_exists('wps_ic_cache_integrations')) {
            wps_ic_cache_integrations::removeDirectoryExcept($cache_dir, ['css', 'js']);
        } else {
            self::removeDirectory($cache_dir);
        }

        return true;
    }


    public static function removeDirectory($path)
    {
        $path = rtrim($path, '/');
        $files = glob($path . '/*');
        foreach ($files as $file) {
            is_dir($file) ? self::removeDirectory($file) : unlink($file);
        }
    }


    public function upgrade_cdn()
    {
        $url = 'https://keys.wpmediacompress.com/?action=updateCDN&apikey=' . self::$options['api_key'] . '&site=' . site_url();

        $call = wp_remote_get($url, [
            'timeout' => 10,
            'sslverify' => 'false',
            'user-agent' => WPS_IC_API_USERAGENT
        ]);
    }


}



add_action('wpc_legacy_upgrade_lane', function () {
    try {
        @set_time_limit(180);
        if (!class_exists('wps_ic_upgrader')) {
            return;
        }
        $wpc_u334 = new wps_ic_upgrader();
        if ($wpc_u334->is_latest() && empty($_GET['force_update'])) {
            return; 
        }
        $wpc_u334->run_upgrade_work334();
    } catch (\Throwable $e) {
    }
});
