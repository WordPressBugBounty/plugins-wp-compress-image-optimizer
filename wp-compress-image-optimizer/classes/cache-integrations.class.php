<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/cache-integrations.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */


include_once __DIR__ . '/../traits/url_key.php';
include_once __DIR__ . '/../defines.php';

class wps_ic_cache_integrations
{
    
    private static $purged = ['all' => false, 'keys' => []];

    public function __construct()
    {
    }


    public function purge_id($post_id, $critical = true)
    {
        $url = get_permalink($post_id);
        if (!$url) {
            return false;
        }

        $url_key_class = new wps_ic_url_key();
        $url_key = $url_key_class->setup($url);

        if ($critical) {
            self::purgeCriticalFiles($url_key);
        }

        self::purgeAll($url_key, true);

        return true;
    }


    public function purge_url($url, $critical = true)
    {
        $url_key_class = new wps_ic_url_key();
        $url_key = $url_key_class->setup($url);

        if ($critical) {
            self::purgeCriticalFiles($url_key);
        }

        self::purgeAll($url_key, true);

        return true;
    }


    public function purge_site($critical = true)
    {
        if (self::$purged['all']) {
            return true;
        }

        if ($critical) {
            self::purgeCriticalFiles();
        }

        self::purgeAll(false, true);

        return true;
    }

    public static function purgePreloads()
    {
        delete_option('wps_ic_preloadsMobile');
        delete_option('wps_ic_preloads');
    }

    public function remove_key()
    {
        $options = get_option(WPS_IC_OPTIONS);

        delete_transient('wpc_test_running');
        delete_transient('wpc_initial_test');
        delete_option('wpsShowAdvanced');

        $options['api_key'] = '';
        $options['response_key'] = '';
        $options['orp'] = '';
        $options['regExUrl'] = '';
        $options['regexpDirectories'] = '';

        update_option(WPS_IC_OPTIONS, $options);


        delete_option('wpc_v2_provisioned_fingerprint');
        delete_option('wpc_v2_provisioned_site_url');

        self::purgeCombinedFiles(false);
        self::purgeAll(false, true);
        return true;
    }

    public static function purgeCombinedFiles($url_key = false)
    {
        $cache_dir = WPS_IC_COMBINE;

        if (!$url_key) {
            self::removeDirectory($cache_dir);
        } else {
            self::removeDirectory($cache_dir . $url_key);
        }

        $oldOptions = $options = get_option(WPS_IC_OPTIONS);

        $CSSHash = substr(md5(microtime(true)), 0, 6);
        $JSHash = strrev($CSSHash);

        $options['css_hash'] = $CSSHash;
        $options['js_hash'] = $JSHash;

        if (!class_exists('wps_ic_log') && defined('WPS_IC_DIR')) {
            @include_once WPS_IC_DIR . 'classes/log.class.php';
        }
        
        if (class_exists('wps_ic_log')) {
            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'purgeCombinedFiles');
        }

        update_option(WPS_IC_OPTIONS, $options);
        return true;
    }

    public static function removeDirectory($path)
    {
        $path = rtrim($path, '/');
        $files = glob($path . '/*');
        if (!empty($files)) {
            foreach ($files as $file) {
                is_dir($file) ? self::removeDirectory($file) : unlink($file);
            }
        }

        if (is_dir($path)) {
            
            @rmdir($path);
        }
    }


    public static function removeDirectoryExcept($path, $except = [])
    {
        $path = rtrim($path, '/');
        $except = array_map('strval', (array) $except);
        $files = glob($path . '/*');
        if (!empty($files)) {
            foreach ($files as $file) {
                if (in_array(basename($file), $except, true)) {
                    continue;
                }
                is_dir($file) ? self::removeDirectory($file) : unlink($file);
            }
        }
    }

    public static function purgeAll($url_key = false, $varnish = false, $critSave = false, $purgeJS = true, $forcePurge = false, $preserve_assets = false)
    {
        
        if ($url_key === false) {
            if (self::$purged['all']) {
                return;
            }
            self::$purged['all'] = true;
        } else {
            if (self::$purged['all'] || isset(self::$purged['keys'][$url_key])) {
                return;
            }
            self::$purged['keys'][$url_key] = true;
        }

        if (!$forcePurge && !$critSave) {
            $settings = get_option(WPS_IC_SETTINGS);
            if (empty($settings['cache']['advanced']) ||
                $settings['cache']['advanced'] == '0' ||
                empty($settings['cache']['purge-hooks']) ||
                $settings['cache']['purge-hooks'] == '0' ||
                (!empty($settings['developer_mode']) && $settings['developer_mode'] == '1')) {

                return;
            }
        }

        
        $url_key = apply_filters('wps_ic_purge_all_url_key', $url_key, $critSave); 
        $varnish = apply_filters('wps_ic_purge_all_varnish', $varnish, $url_key); 
        $purgeJS = apply_filters('wps_ic_purge_all_purge_js', $purgeJS);

        
        $oldOptions = $options = get_option(WPS_IC_OPTIONS);

        $CSSHash = substr(md5(microtime(true)), 0, 6);
        $JSHash = strrev($CSSHash);

        $options['css_hash'] = $CSSHash;

        if ($purgeJS) {
            $options['js_hash'] = $JSHash;
        }

        if (!class_exists('wps_ic_log') && defined('WPS_IC_DIR')) {
            @include_once WPS_IC_DIR . 'classes/log.class.php';
        }
        if (class_exists('wps_ic_log')) {
            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'purgeAll');
        }

        update_option(WPS_IC_OPTIONS, $options);

        
        self::purgeCacheFiles($url_key, $preserve_assets);

        
        wpc_foreign_purge610($url_key, 'integrations');

        
        
        
        
        
        
        
        if (method_exists(__CLASS__, 'wpc_purge_wpe116')) { self::wpc_purge_wpe116($url_key); }

        if ($varnish) {
            self::purgeVarnish(0, ($url_key === false));
        }

        
        do_action('wps_ic_purge_all_complete', $url_key, $varnish, $critSave, $purgeJS);
    }

    public static function wpc_purge_wpe116($url_key = false)
    {
        try {
            if (!class_exists('WpeCommon') || !apply_filters('wpc_purge_wpe', true)) {
                return;
            }
            if ($url_key === false) {
                if (function_exists('get_transient') && get_transient('wpc_wpe_flush116')) {
                    return;
                }
                if (function_exists('set_transient')) { set_transient('wpc_wpe_flush116', 1, 60); }
                if (method_exists('WpeCommon', 'purge_memcached'))     { WpeCommon::purge_memcached(); }
                if (method_exists('WpeCommon', 'purge_varnish_cache')) { WpeCommon::purge_varnish_cache(); }
                if (method_exists('WpeCommon', 'clear_cdn_cache'))     { WpeCommon::clear_cdn_cache(); }
                if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('wpe-purge', 'all', '', []); }
                return;
            }
            $pid = 0;
            if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'getUrlFromKey') && function_exists('url_to_postid')) {
                $u = (string) wps_ic_url_key::getUrlFromKey((string) $url_key);
                if ($u !== '') { $pid = (int) url_to_postid($u); }
                if ($pid === 0 && function_exists('home_url') && function_exists('get_option')
                    && rtrim($u, '/') === rtrim((string) home_url('/'), '/')) {
                    $pid = (int) get_option('page_on_front');
                }
            }
            if ($pid > 0 && method_exists('WpeCommon', 'purge_varnish_cache')) {
                WpeCommon::purge_varnish_cache($pid);
                if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('wpe-purge', (string) $url_key, '', ['pid' => $pid]); }
            }
        } catch (\Throwable $e) {
        }
    }

    public static function purgeCriticalFiles($url_key = false)
    {


        if (function_exists('wpc_cache_first_log')) {
            $wpc_tw_via = [];
            foreach (array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 9), 1, 7) as $wpc_tw_f) {
                $wpc_tw_via[] = (isset($wpc_tw_f['class']) ? $wpc_tw_f['class'] . '::' : '') . ($wpc_tw_f['function'] ?? '?');
            }
            wpc_cache_first_log('crit-wipe', $url_key ? (string) $url_key : 'all', '', [
                'hook' => function_exists('current_action') ? (string) current_action() : '',
                'via'  => implode('<', $wpc_tw_via),
            ]);
        }
        $cache_dir = WPS_IC_CRITICAL;

        if (!$url_key) {
            self::wipeCriticalPreservingStores($cache_dir);


            if (function_exists('wpc_land_cooldown_clear')) { wpc_land_cooldown_clear('all'); }
        } else {
            self::removeFiles($cache_dir . $url_key);
            if (function_exists('wpc_land_cooldown_clear')) { wpc_land_cooldown_clear((string) $url_key); }
        }


        try {
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
                $wpc_rk133 = $url_key ? (string) $url_key : '';
                if ($wpc_rk133 === '' && class_exists('wps_ic_url_key') && function_exists('home_url')) {
                    $wpc_rk133 = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
                }
                if ($wpc_rk133 !== '' && !wp_next_scheduled('wpc_lcp_repull', [$wpc_rk133, 1])) {
                    wpc_pl_sched(time() + 60, 'wpc_lcp_repull', [$wpc_rk133, 1]);
                }
            }
        } catch (\Throwable $e) {
        }
        return true;
    }


    public static function wipeCriticalPreservingStores($dir)
    {
        $dir  = rtrim((string) $dir, '/');
        $keep = (array) apply_filters('wpc_crit_wipe_preserve', ['used-css', 'inv2', '.kicklocks', 'sidecar']);
        if (empty($keep)) {
            self::removeDirectory($dir);
            return;
        }
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $it) {
            if ($it === '.' || $it === '..' || in_array($it, $keep, true)) {
                continue;
            }
            $p = $dir . '/' . $it;
            if (is_dir($p)) {
                
                
                
                
                self::removeFiles($p);
                foreach ((array) @glob($p . '/*', GLOB_ONLYDIR) as $wpc_s1) {
                    self::removeFiles($wpc_s1);
                    foreach ((array) @glob($wpc_s1 . '/*', GLOB_ONLYDIR) as $wpc_s2) {
                        self::removeFiles($wpc_s2);
                    }
                }
            } else {
                @unlink($p);
            }
        }
    }

    public static function removeFiles($path)
    {


        $keep = (array) apply_filters('wpc_crit_purge_preserve', ['tpl.txt', 'url.txt', 'used_tpl.txt']);
        $path = rtrim($path, '/');
        $files = glob($path . '/*');
        if (!empty($files)) {
            foreach ($files as $file) {
                if (is_file($file) && !in_array(basename($file), $keep, true)) {
                    unlink($file);
                }
            }
        }
    }

    

    public static function purgeBreeze()
    {
        do_action('breeze_clear_all_cache');

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

    public static function purgeCacheFiles($url_key = false, $preserve_assets = false)
    {
        $cache_dir = WPS_IC_CACHE;

        if (!$url_key) {
            if ($preserve_assets) {


                self::removeDirectoryExcept($cache_dir, ['css', 'js']);
            } else {
                self::removeDirectory($cache_dir);
            }
        } else {
            self::removeFiles($cache_dir . $url_key);
            
            
            
            
            
            
            
            
            foreach ([
                $cache_dir . $url_key . '_*',
                $cache_dir . '*/' . $url_key,
                $cache_dir . '*/' . $url_key . '_*',
            ] as $wpc_pat643) {
                foreach ((array) @glob($wpc_pat643, GLOB_ONLYDIR) as $wpc_v643) {
                    self::removeFiles($wpc_v643);
                }
            }
        }

        return true;
    }

    public static function wpc_purgeCF($return = false)
    {
        return false; 

        $cfSettings = get_option(WPS_IC_CF);

        if (!empty($cfSettings)) {
            $zone = $cfSettings['zone'];
            $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
            if ($cfapi) {
                $cfapi->purgeCache($zone);
                sleep(3);
            }
        }

        if ($return) {
            return true;
        }

        wp_send_json_success();
    }

    public static function purgeVarnish($post_id = 0, $full_site = false, $url = '')
    {
        global $wpdb, $current_blog;


        if (!empty($url) && is_string($url)) {
            $parseUrl = parse_url($url);
        } elseif ($post_id != 0) {
            $parseUrl = parse_url(get_permalink($post_id));
        } else {
            $parseUrl = parse_url(site_url());
        }

        if (empty($parseUrl['path'])) {
            $parseUrl['path'] = '/';
        }

        if (empty($parseUrl['host'])) {
            return false;
        }

        $x_purge_method = 'default';
        $regex = '';


        if ($full_site) {
            $x_purge_method = 'regex';
            $regex = '.*';
        }


        
        $scheme = apply_filters('wps_ic_varnish_purge_scheme', isset($parseUrl['scheme']) ? $parseUrl['scheme'] : 'http');

        
        $x_purge_method = apply_filters('wps_ic_varnish_purge_method', $x_purge_method);

        
        $regex = apply_filters('wps_ic_varnish_purge_regex', $regex);

        
        $headers = apply_filters(
            'wps_ic_varnish_purge_headers',
            [
                'host'           => apply_filters('wps_ic_varnish_purge_request_host', $parseUrl['host']),
                'X-Purge-Method' => $x_purge_method
            ]
        );


        
        $args = apply_filters(
            'wps_ic_varnish_purge_request_args',
            [
                'method'      => 'PURGE',
                'blocking'    => false,
                'redirection' => 0,
                'headers'     => $headers,
            ]
        );


        $varnish_ips = apply_filters('wps_ic_varnish_ips', []);

        
        if (empty($varnish_ips)) {
            $varnish_ips = [''];
        } elseif (is_string($varnish_ips)) {
            $varnish_ips = (array) $varnish_ips;
        }


        
        
        
        
        
        
        
        
        $wpc_lb_has = false;
        foreach ($varnish_ips as $wpc_lb_ip) {
            if (is_string($wpc_lb_ip) && strpos($wpc_lb_ip, '127.0.0.1') !== false) {
                $wpc_lb_has = true;
                break;
            }
        }
        $wpc_lb_dead = function_exists('get_transient') ? (bool) get_transient('wpc_varnish_lb_dead') : false;
        if (!$wpc_lb_has && !$wpc_lb_dead && apply_filters('wpc_varnish_loopback_fallback', true)) {
            $varnish_ips[] = '127.0.0.1';
        }

        
        foreach ($varnish_ips as $ip) {
            $host = !empty($ip) ? $ip : $parseUrl['host'];
            $purge_url_main = $scheme . '://' . $host . $parseUrl['path'];

            





            $purge_url = apply_filters(
                'wps_ic_varnish_purge_url',
                $purge_url_main . $regex,
                $purge_url_main,
                $regex
            );

            $ipArgs = $args;
            $wpc_is_lb = (is_string($ip) && strpos($ip, '127.0.0.1') !== false);
            if ($wpc_is_lb) {
                
                
                $ipArgs['sslverify'] = false;
                
                
                $ipArgs['timeout'] = max(1, (int) apply_filters('wpc_varnish_loopback_timeout', 2));
            }

            try {
                $wpc_lb_t0 = microtime(true);
                $wpc_pr = wp_remote_request($purge_url, $ipArgs);
                
                
                if ($wpc_is_lb && $ip === '127.0.0.1' && function_exists('set_transient')
                    && (is_wp_error($wpc_pr) || (microtime(true) - $wpc_lb_t0) > 1.5)) {
                    set_transient('wpc_varnish_lb_dead', 1,
                        (int) apply_filters('wpc_varnish_lb_dead_ttl', 1800));
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('varnish-loopback-dead', '', '', [
                            'ms'  => (int) round((microtime(true) - $wpc_lb_t0) * 1000),
                            'ttl' => (int) apply_filters('wpc_varnish_lb_dead_ttl', 1800),
                        ]);
                    }
                }
            } catch (exception $e) {
                
                continue;
            }
        }

        return true;
    }


    private static $purgedUrls = [];


    public static function purgeUrlHtml($url_key, $url = '', $opts = [])
    {
        $opts   = array_merge(['context' => '', 'warm' => true, 'varnish' => true], is_array($opts) ? $opts : []);
        $layers = [];
        if (empty($url_key) || !is_string($url_key)) {
            return $layers;
        }
        if (isset(self::$purgedUrls[$url_key])) {
            return self::$purgedUrls[$url_key];
        }

        
        
        $clean = '';
        if (class_exists('wps_ic_url_key')) {
            if (!empty($url) && method_exists('wps_ic_url_key', 'sanitizeSameHostUrl')) {
                $clean = wps_ic_url_key::sanitizeSameHostUrl($url);
            }
            if ($clean === '' && method_exists('wps_ic_url_key', 'getUrlFromKey')) {
                $raw = wps_ic_url_key::getUrlFromKey($url_key);
                if (!empty($raw) && method_exists('wps_ic_url_key', 'sanitizeSameHostUrl')) {
                    $clean = wps_ic_url_key::sanitizeSameHostUrl($raw);
                }
            }
            if ($clean !== '' && method_exists('wps_ic_url_key', 'persistKeyUrl')) {
                wps_ic_url_key::persistKeyUrl($url_key, $clean);
            }
        }


        $rebuild = !empty($opts['warm']) && $clean !== ''
            && function_exists('wpc_warm_url_queue')
            && function_exists('wpc_cache_first_enabled') && wpc_cache_first_enabled()
            && apply_filters('wpc_build_before_purge', false);

        
        if ($rebuild) {
            $layers['local'] = 'rebuild';
        } else {
            $layers['local'] = (bool) self::purgeCacheFiles($url_key);
        }

        if ($clean === '') {
            
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('url-map-miss', $url_key, '', ['context' => $opts['context']]);
            }
            self::$purgedUrls[$url_key] = $layers;
            return $layers;
        }

        
        
        try {
            if ($rebuild) {
                $layers['mirror'] = 'rebuild';
            } elseif (class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'removeStaticMirror')) {
                wps_cacheHtml::removeStaticMirror($clean);
                $layers['mirror'] = true;
            }
        } catch (\Throwable $e) {
            $layers['mirror'] = false;
        }

        
        if (!empty($opts['varnish'])) {
            try {
                $layers['varnish'] = (bool) self::purgeVarnish(0, false, $clean);
            } catch (\Throwable $e) {
                $layers['varnish'] = false;
            }
        }

        
        


        try {
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeEdgeHtmlUrls')) {
                $forms   = [$clean];
                $forms[] = (substr($clean, -1) === '/') ? rtrim($clean, '/') : $clean . '/';
                wps_ic_cache::purgeEdgeHtmlUrls(array_filter($forms));
                $layers['cf'] = 'queued';
            }
        } catch (\Throwable $e) {
            $layers['cf'] = false;
        }

        
        $wpc_tp325 = self::purgeThirdPartyUrl($clean);
        $layers = array_merge($layers, $wpc_tp325);

        
        
        $layers['fullonly'] = self::maybeFullPurgeFullOnlyLayers(array_keys(array_filter($wpc_tp325)));

        

        do_action('wps_ic_purge_url_html', $clean, $url_key, $opts['context']);

        
        if (!empty($opts['warm']) && function_exists('wpc_warm_url_queue')) {
            wpc_warm_url_queue($clean, $opts['context']);
        }


        if ($rebuild && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {


            $wpc_sw_delay = 75;
            if (function_exists('get_transient') && get_transient('wpc_land_ready_' . md5((string) $url_key)) === 'crit_only') {
                $wpc_sw_delay = 240;
            }
            if (!wp_next_scheduled('wpc_land_second_wave', [$clean])) {
                if (function_exists('wpc_pl_sched')) {
                    wpc_pl_sched(time() + $wpc_sw_delay, 'wpc_land_second_wave', [$clean]);
                } else {
                    wp_schedule_single_event(time() + $wpc_sw_delay, 'wpc_land_second_wave', [$clean]);
                }
            }
        }

        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($opts['context'] !== '' ? $opts['context'] : 'purge-url', $url_key, $clean, $layers);
        }
        self::$purgedUrls[$url_key] = $layers;
        return $layers;
    }

    



    private static function purgeThirdPartyUrl($url)
    {
        $out = [];
        try { 
            if (function_exists('rocket_clean_files')) {
                rocket_clean_files($url);
                $out['rocket'] = true;
            }
        } catch (\Throwable $e) {
            $out['rocket'] = false;
        }
        try { 
            if (function_exists('w3tc_flush_url')) {
                w3tc_flush_url($url);
                $out['w3tc'] = true;
            }
        } catch (\Throwable $e) {
            $out['w3tc'] = false;
        }
        try { 
            if (function_exists('wpsc_delete_url_cache')) {
                wpsc_delete_url_cache($url);
                $out['wpsc'] = true;
            }
        } catch (\Throwable $e) {
            $out['wpsc'] = false;
        }
        try { 
            if (defined('LSCWP_V') || class_exists('\LiteSpeed\Purge')) {
                do_action('litespeed_purge_url', $url);
                $out['litespeed'] = true;
            } elseif (!headers_sent() && !empty($_SERVER['SERVER_SOFTWARE'])
                && stripos($_SERVER['SERVER_SOFTWARE'], 'litespeed') !== false) {
                
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($path !== '') {
                    header('X-LiteSpeed-Purge: ' . $path, false);
                    $out['litespeed'] = true;
                }
            }
            
            
            
            
            
            if (function_exists('wpc_ls_purge_ping')) {
                $out['ls_ping'] = (bool) wpc_ls_purge_ping($url);
            }
        } catch (\Throwable $e) {
            $out['litespeed'] = false;
        }
        try { 
            if (has_action('swcfpc_purge_cache')) {
                do_action('swcfpc_purge_cache', [$url]);
                $out['swcfpc'] = true;
            }
        } catch (\Throwable $e) {
            $out['swcfpc'] = false;
        }
        try { 
            if (isset($GLOBALS['kinsta_cache']) && !empty($GLOBALS['kinsta_cache']->kinsta_cache_purge)
                && method_exists($GLOBALS['kinsta_cache']->kinsta_cache_purge, 'purge_url')) {
                $GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_url($url);
                $out['kinsta'] = true;
            }
        } catch (\Throwable $e) {
            $out['kinsta'] = false;
        }

        
        
        
        $wpc_pid325 = 0;
        
        
        $wpc_adapters325 = defined('WPHB_VERSION') || function_exists('wpfc_clear_post_cache_by_id')
            || is_callable(['comet_cache', 'clearPost'])
            || is_callable(['Swift_Performance_Cache', 'clear_post_cache'])
            || is_callable(['Swift_Performance_Cache', 'clear_permalink_cache']);
        try {
            if ($wpc_adapters325 && function_exists('url_to_postid')) {
                $wpc_pid325 = (int) url_to_postid($url);
            }
            if ($wpc_adapters325 && $wpc_pid325 === 0 && function_exists('home_url')
                && untrailingslashit((string) parse_url($url, PHP_URL_PATH)) === untrailingslashit((string) parse_url(home_url('/'), PHP_URL_PATH))) {
                $wpc_pid325 = (int) get_option('page_on_front');
            }
        } catch (\Throwable $e) {
        }
        if ($wpc_pid325 > 0) {
            try { 
                if (defined('WPHB_VERSION')) {
                    do_action('wphb_clear_page_cache', $wpc_pid325);
                    $out['wphb_page'] = true;
                }
            } catch (\Throwable $e) {
            }
            try { 
                if (function_exists('wpfc_clear_post_cache_by_id')) {
                    wpfc_clear_post_cache_by_id($wpc_pid325);
                    $out['wpfc_page'] = true;
                }
            } catch (\Throwable $e) {
            }
            try { 
                if (is_callable(['comet_cache', 'clearPost'])) {
                    call_user_func(['comet_cache', 'clearPost'], $wpc_pid325);
                    $out['comet_page'] = true;
                }
            } catch (\Throwable $e) {
            }
            try { 
                if (is_callable(['Swift_Performance_Cache', 'clear_post_cache'])) {
                    call_user_func(['Swift_Performance_Cache', 'clear_post_cache'], $wpc_pid325);
                    $out['swift_page'] = true;
                } elseif (is_callable(['Swift_Performance_Cache', 'clear_permalink_cache'])) {
                    call_user_func(['Swift_Performance_Cache', 'clear_permalink_cache'], $wpc_pid325);
                    $out['swift_page'] = true;
                }
            } catch (\Throwable $e) {
            }
        }
        try { 
            if (is_callable(['WPO_Page_Cache', 'delete_cache_by_url'])) {
                call_user_func(['WPO_Page_Cache', 'delete_cache_by_url'], $url);
                $out['wpo_page'] = true;
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    





    private static function maybeFullPurgeFullOnlyLayers($handled = [])
    {
        if (!apply_filters('wpc_purge_fullonly_on_crit', true)) {
            return 'off';
        }
        $handled = is_array($handled) ? $handled : [];
        
        $need = [
            'breeze'  => (class_exists('Breeze_PurgeCache') || defined('BREEZE_VERSION')),
            'wphb'    => defined('WPHB_VERSION') && !in_array('wphb_page', $handled, true),
            'wpfc'    => function_exists('wpfc_clear_all_cache') && !in_array('wpfc_page', $handled, true),
            'cachify' => function_exists('cachify_flush_cache'),
            'comet'   => is_callable(['comet_cache', 'clear']) && !in_array('comet_page', $handled, true),
            'swift'   => is_callable(['Swift_Performance_Cache', 'clear_all_cache']) && !in_array('swift_page', $handled, true),
            'wpo'     => class_exists('WP_Optimize') && !in_array('wpo_page', $handled, true),
        ];
        $need = array_filter($need);
        if (empty($need)) {
            return 'scoped'; 
        }
        if (get_transient('wpc_fullonly_purge_lock')) {
            return 'coalesced';
        }
        set_transient('wpc_fullonly_purge_lock', 1, max(60, (int) apply_filters('wpc_fullonly_purge_window', 600)));
        try {
            if (!empty($need['breeze'])) {
                do_action('breeze_clear_all_cache');
                if (class_exists('Breeze_PurgeCache') && is_callable(['Breeze_PurgeCache', 'breeze_cache_flush'])) {
                    call_user_func(['Breeze_PurgeCache', 'breeze_cache_flush']);
                }
            }
            if (!empty($need['wphb'])) {
                do_action('wphb_clear_page_cache');
            }
            if (!empty($need['wpfc'])) {
                wpfc_clear_all_cache();
            }
            if (!empty($need['cachify'])) {
                cachify_flush_cache();
            }
            if (!empty($need['comet'])) {
                call_user_func(['comet_cache', 'clear']);
            }
            if (!empty($need['swift'])) {
                call_user_func(['Swift_Performance_Cache', 'clear_all_cache']);
            }
            if (!empty($need['wpo'])) {
                WP_Optimize()->get_page_cache()->purge();
            }
        } catch (\Throwable $e) {
            return 'error';
        }
        return 'purged:' . implode(',', array_keys($need));
    }

}