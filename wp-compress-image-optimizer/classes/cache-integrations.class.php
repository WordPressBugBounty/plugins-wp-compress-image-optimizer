<?php
//This file has to be self-contained, include everything used
include_once __DIR__ . '/../traits/url_key.php';
include_once __DIR__ . '/../defines.php';

class wps_ic_cache_integrations
{
    // Tracks what has been purged in the current request to avoid duplicate purges.
    private static $purged = ['all' => false, 'keys' => []];

    public function __construct()
    {
    }

    /**
     * Purge cache for a specific post by ID.
     *
     * Usage: (new wps_ic_cache_integrations())->purge_id(42);
     *        (new wps_ic_cache_integrations())->purge_id(42, false);
     *
     * @param int  $post_id  WordPress post ID whose cache should be purged.
     * @param bool $critical Whether to also purge the critical CSS for this page. Default true.
     * @return bool          True on success, false if the permalink could not be resolved.
     */
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

    /**
     * Purge cache for a specific URL.
     *
     * Usage: (new wps_ic_cache_integrations())->purge_url('https://example.com/my-page/');
     *        (new wps_ic_cache_integrations())->purge_url('https://example.com/my-page/', false);
     *
     * @param string $url      Full URL of the page whose cache should be purged.
     * @param bool   $critical Whether to also purge the critical CSS for this page. Default true.
     * @return bool            Always true.
     */
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

    /**
     * Purge cache for the entire site.
     *
     * Usage: (new wps_ic_cache_integrations())->purge_site();
     *        (new wps_ic_cache_integrations())->purge_site(false);
     *
     * @param bool $critical Whether to also purge all critical CSS files. Default true.
     * @return bool          Always true.
     */
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

        // v7.02.07 — clear the provisioning witnesses so a later reconnect re-provisions cleanly for
        // this host (the env fingerprint + host baseline shouldn't survive a disconnect).
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

        if (!class_exists('wps_ic_log')) {
            include_once WPS_IC_DIR . 'classes/log.class.php';
        }

        $log = new wps_ic_log();
        $log->logCachePurging($oldOptions, $options, 'purgeCombinedFiles');

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
            rmdir($path);
        }
    }

    /**
     * Remove a directory's contents EXCEPT named top-level entries (kept verbatim). The root dir
     * itself is kept (it holds the preserved subdir). Used by the plugin-update purge to clear
     * cached HTML pages under wp-cio/ while PRESERVING the content-addressed optimized CSS
     * (wp-cio/css): cached HTML at ANY layer (CF/Varnish/local) may still reference those exact
     * filenames, so deleting them on update while that HTML lives = a 404 until the page is purged.
     * Stale preserved assets are content-addressed (a real CSS change = a new filename) and are
     * harmless; GC them by age separately if needed.
     */
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
        // Deduplicate: skip if this url_key (or full site) was already purged in this request
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
                //Do not purge if cache OFF, or cache purge OFF or developer mode ON
                return;
            }
        }

        // Allow integrations to modify parameters
        $url_key = apply_filters('wps_ic_purge_all_url_key', $url_key, $critSave); //If set to false purge all cache
        $varnish = apply_filters('wps_ic_purge_all_varnish', $varnish, $url_key); //Allow enabling/disabling varnish purge
        $purgeJS = apply_filters('wps_ic_purge_all_purge_js', $purgeJS);

        // Change CSS Hash
        $oldOptions = $options = get_option(WPS_IC_OPTIONS);

        $CSSHash = substr(md5(microtime(true)), 0, 6);
        $JSHash = strrev($CSSHash);

        $options['css_hash'] = $CSSHash;

        if ($purgeJS) {
            $options['js_hash'] = $JSHash;
        }

        if (!class_exists('wps_ic_log')) {
            include_once WPS_IC_DIR . 'classes/log.class.php';
        }

        $log = new wps_ic_log();
        $log->logCachePurging($oldOptions, $options, 'purgeAll');

        update_option(WPS_IC_OPTIONS, $options);

        // Purge internal cache files (preserve content-addressed optimized CSS/JS on plugin update)
        self::purgeCacheFiles($url_key, $preserve_assets);

        // Action hook for all integrations to clear their cache
        do_action('wps_ic_purge_all_cache', $url_key);

        // Varnish — full-site eviction when this is a site-wide purge ($url_key === false), else the
        // configured single path. A site-wide purge (e.g. crit purge) must evict EVERY cached page, or
        // interior pages keep serving a stale crit-less HIT and their regen stays walled.
        if ($varnish) {
            self::purgeVarnish(0, ($url_key === false));
        }

        // Final action hook after all purges
        do_action('wps_ic_purge_all_complete', $url_key, $varnish, $critSave, $purgeJS);
    }

    public static function purgeCriticalFiles($url_key = false)
    {
        $cache_dir = WPS_IC_CRITICAL;

        if (!$url_key) {
            self::removeDirectory($cache_dir);
        } else {
            self::removeFiles($cache_dir . $url_key);
        }
        return true;
    }

    public static function removeFiles($path)
    {
        $path = rtrim($path, '/');
        $files = glob($path . '/*');
        if (!empty($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
        }
    }

    // TODO: Maybe it will cause errors with non SSL sites?

    public static function purgeBreeze()
    {
        do_action('breeze_clear_all_cache'); //working

        if (defined('BREEZE_VERSION')) {
            global $wp_filesystem;
            require_once(ABSPATH . 'wp-admin/includes/file.php');

            WP_Filesystem();

            $cache_path = breeze_get_cache_base_path(is_network_admin(), true);
            $wp_filesystem->rmdir(untrailingslashit($cache_path), true);

            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }
    }

    public static function purgeCacheFiles($url_key = false, $preserve_assets = false)
    {
        $cache_dir = WPS_IC_CACHE;

        if (!$url_key) {
            if ($preserve_assets) {
                // Plugin-update purge: clear cached HTML pages but PRESERVE the content-addressed
                // optimized CSS (wp-cio/css) + JS. Cached HTML (CF/Varnish/local) still references
                // those exact filenames; deleting them on update = a 404 until that HTML is purged.
                self::removeDirectoryExcept($cache_dir, ['css', 'js']);
            } else {
                self::removeDirectory($cache_dir);
            }
        } else {
            self::removeFiles($cache_dir . $url_key);
        }

        return true;
    }

    public static function wpc_purgeCF($return = false)
    {
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

    public static function purgeVarnish($post_id = 0, $full_site = false)
    {
        global $wpdb, $current_blog;

        if ($post_id != 0) {
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

        // (v7.03.91) Full-site eviction for a SITE-WIDE purge (e.g. a critical-CSS purge): a single-path
        // PURGE only evicts the homepage, leaving interior pages walled (a cached crit-less HIT blocks the
        // crit regen because WP never re-renders). A regex purge against every path clears them all. Filters
        // below can still override per host (some Varnish VCLs use BAN instead of an X-Purge-Method: regex).
        if ($full_site) {
            $x_purge_method = 'regex';
            $regex = '.*';
        }


        // Filter the HTTP protocol (scheme) for Varnish purge
        $scheme = apply_filters('wps_ic_varnish_purge_scheme', isset($parseUrl['scheme']) ? $parseUrl['scheme'] : 'http');

        //Filter the Varnish purge method
        $x_purge_method = apply_filters('wps_ic_varnish_purge_method', $x_purge_method);

        //Filter the regex pattern for Varnish purge
        $regex = apply_filters('wps_ic_varnish_purge_regex', $regex);

        //Filter the headers to send with the Varnish purge request
        $headers = apply_filters(
            'wps_ic_varnish_purge_headers',
            [
                'host'           => apply_filters('wps_ic_varnish_purge_request_host', $parseUrl['host']),
                'X-Purge-Method' => $x_purge_method
            ]
        );


        //Filter the arguments passed to the Varnish purge request
        $args = apply_filters(
            'wps_ic_varnish_purge_request_args',
            [
                'method'      => 'PURGE',
                'blocking'    => false,
                'redirection' => 0,
                'headers'     => $headers,
            ]
        );

        //Filter the Varnish IP addresses to send purge requests to
        $varnish_ips = apply_filters('wps_ic_varnish_ips', []);

        // If no IPs specified, use empty string to use the host
        if (empty($varnish_ips)) {
            $varnish_ips = [''];
        } elseif (is_string($varnish_ips)) {
            $varnish_ips = (array) $varnish_ips;
        }

        // Send purge request to each Varnish IP
        foreach ($varnish_ips as $ip) {
            $host = !empty($ip) ? $ip : $parseUrl['host'];
            $purge_url_main = $scheme . '://' . $host . $parseUrl['path'];

            /**
             * Filter the final purge URL
             * @param string $purge_url_full Full URL with regex pattern
             * @param string $purge_url_main Main purge URL without additions
             * @param string $regex          Regex string
             */
            $purge_url = apply_filters(
                'wps_ic_varnish_purge_url',
                $purge_url_main . $regex,
                $purge_url_main,
                $regex
            );

            try {
                wp_remote_request($purge_url, $args);
            } catch (exception $e) {
                // Continue to next IP on error
                continue;
            }
        }

        return true;
    }

}