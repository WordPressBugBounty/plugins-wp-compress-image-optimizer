<?php


class wps_ic_cache_integrations
{


    public function __construct()
    {
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

    public static function purgeAll($url_key = false, $varnish = false, $critSave = false, $purgeJS = true)
    {
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

        // Purge internal cache files
        self::purgeCacheFiles($url_key);

        // Action hook for all integrations to clear their cache
        do_action('wps_ic_purge_all_cache', $url_key);

        // Varnish
        if ($varnish) {
            self::purgeVarnish();
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

    public static function purgeCacheFiles($url_key = false)
    {
        $cache_dir = WPS_IC_CACHE;

        if (!$url_key) {
            self::removeDirectory($cache_dir);
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

    public static function purgeVarnish($post_id = 0)
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