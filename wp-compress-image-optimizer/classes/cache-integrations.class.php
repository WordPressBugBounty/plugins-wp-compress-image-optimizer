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


    public function purge_id($post_id, $critical = true)
    {
        $url = get_permalink($post_id);
        if (!$url) {
            return false;
        }

        $url_key_class = new wps_ic_url_key();
        $url_key = $url_key_class->setup($url);

        if ($critical) {
            self::invalidateCritical($url_key, 'integration-purge-post', 'wipe');
        }

        self::purgeAll($url_key, true);

        return true;
    }


    public function purge_url($url, $critical = true)
    {
        $url_key_class = new wps_ic_url_key();
        $url_key = $url_key_class->setup($url);

        if ($critical) {
            self::invalidateCritical($url_key, 'integration-purge-url', 'wipe');
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
            self::invalidateCritical('all', 'integration-purge-site', 'wipe');
        }

        self::purgeAll(false, true);

        return true;
    }

    /**
     * Crit invalidation for the callers in this class and the ones that hold only an instance of
     * it. The rule and the modes are wpc_crit_invalidate()'s; this only resolves it when warm.php
     * is not loaded, in which case there is no crit pipeline to invalidate.
     */
    public static function invalidateCritical($scope, $reason, $mode)
    {
        if (!function_exists('wpc_crit_invalidate')) {
            return 0;
        }
        return wpc_crit_invalidate($scope, $reason, $mode);
    }

    public static function purgePreloads()
    {
        delete_option('wps_ic_preloadsMobile');
        delete_option('wps_ic_preloads');
    }

    public function remove_key($path = 'remove_key')
    {
        if (function_exists('wpc_record_key_removal')) {
            wpc_record_key_removal($path);
        }
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
            // The parked-CSS bundles under critical/combined/ are not deleted here: every
            // site-wide caller sits beside a purge that may be serve-stale, and a stale copy
            // links them. wpc_delete_combined_bundles() owns that delete (defines.php).
        } else {
            self::removeDirectory($cache_dir . $url_key);
        }

        $oldOptions = $options = get_option(WPS_IC_OPTIONS);

        if (class_exists('wps_ic_asset_version')) { wps_ic_asset_version::reset(); }

        if (!class_exists('wps_ic_log') && defined('WPS_IC_DIR')) {
            @include_once WPS_IC_DIR . 'classes/log.class.php';
        }
        // Logging must never fatal a purge (customer receipt: deactivate-path E_ERROR).
        if (class_exists('wps_ic_log')) {
            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'purgeCombinedFiles');
        }

        update_option(WPS_IC_OPTIONS, $options);
        return true;
    }

    public static function removeDirectory($path)
    {
        wpc_fs_remove_tree($path);
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
                is_dir($file) ? wpc_fs_remove_tree($file, true) : @unlink($file);
            }
        }
    }

    public static function purgeAll($url_key = false, $varnish = false, $critSave = false, $purgeJS = true, $forcePurge = false, $preserve_assets = false, $purge_mode = null)
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

                return;
            }
        }

        // Allow integrations to modify parameters
        $url_key = apply_filters('wps_ic_purge_all_url_key', $url_key, $critSave); //If set to false purge all cache
        $varnish = apply_filters('wps_ic_purge_all_varnish', $varnish, $url_key); //Allow enabling/disabling varnish purge
        $purgeJS = apply_filters('wps_ic_purge_all_purge_js', $purgeJS);

        // Change CSS Hash
        $oldOptions = $options = get_option(WPS_IC_OPTIONS);

        if (class_exists('wps_ic_asset_version')) { wps_ic_asset_version::reset(); }

        if (!class_exists('wps_ic_log') && defined('WPS_IC_DIR')) {
            @include_once WPS_IC_DIR . 'classes/log.class.php';
        }
        if (class_exists('wps_ic_log')) {
            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'purgeAll');
        }

        update_option(WPS_IC_OPTIONS, $options);

        // Purge internal cache files (preserve content-addressed optimized CSS/JS on plugin update)
        self::purgeCacheFiles($url_key, $preserve_assets, $purge_mode);

        // Action hook for all integrations to clear their cache
        wpc_purge_foreign_caches($url_key, 'integrations');

        // v7.22.16 — WP ENGINE IS A CACHE LAYER TOO. sproduce.com: plugin updated to 7.22.14,
        // origin rendering crit + measured lane, yet PSI (and every visitor) got the 7.21.28
        // copy for a day — WPE's page cache (x-cacheable: YES:86400) and the edge in front of
        // it never heard our update/land/purge-all. The settings-save path already flushes
        // WpeCommon; purgeAll never did. Site-wide: varnish + memcached (+cdn) flush, throttled
        // 60s so a purge storm cannot hammer the platform API. Per-URL: WPE's own post purge
        // when the key resolves to a post (WPE purges on post save itself, so a miss is safe).
        if (method_exists(__CLASS__, 'wpc_purge_wp_engine')) { self::wpc_purge_wp_engine($url_key); }

        if ($varnish) {
            self::purgeVarnish(0, ($url_key === false));
        }

        // Final action hook after all purges
        do_action('wps_ic_purge_all_complete', $url_key, $varnish, $critSave, $purgeJS);
    }

    public static function wpc_purge_wp_engine($url_key = false)
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

    /**
     * Deletes crit files; it is not an invalidation. A removal goes through wpc_crit_invalidate()
     * in 'wipe' mode, which raises the removal epoch before it calls this.
     */
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
                $repull_key = $url_key ? (string) $url_key : '';
                if ($repull_key === '' && class_exists('wps_ic_url_key') && function_exists('home_url')) {
                    $repull_key = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
                }
                if ($repull_key !== '' && !wp_next_scheduled('wpc_lcp_repull', [$repull_key, 1])) {
                    wpc_pl_sched(time() + 60, 'wpc_lcp_repull', [$repull_key, 1]);
                }
            }
        } catch (\Throwable $e) {
        }
        return true;
    }


    public static function wipeCriticalPreservingStores($dir)
    {
        $dir  = rtrim((string) $dir, '/');
        // combined/ holds the parked-CSS bundles the stored page copies link. A crit wipe runs
        // beside a purge that may keep those copies to serve stale (the nuclear Purge Critical,
        // removeCriticalFiles('all') on a menu or theme save), so it is not the call that may
        // delete them; wpc_delete_combined_bundles() is (defines.php).
        $keep = (array) apply_filters('wpc_crit_wipe_preserve', ['used-css', 'inv2', '.kicklocks', 'sidecar', 'combined']);
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
            if (@is_link($p) && is_dir($p)) {
                continue;
            }
            if (is_dir($p)) {
                // Page dirs are emptied via the keep-list, NOT deleted: tpl.txt/url.txt are
                // PAGE IDENTITY, and the purge-all wholesale delete was the true tpl_key
                // regression (post-purge redispatch read an empty tpl → dispatched keyless →
                // service echoed None → used-css store never marked done → refetch churn).
                self::removeFiles($p);
                foreach ((array) @glob($p . '/*', GLOB_ONLYDIR) as $wpc_s1) {
                    if (@is_link($wpc_s1)) {
                        continue;
                    }
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

    /**
     * Deletes the files directly in $path except the page's identity files; directories, and
     * what is under them, stay. A symbolic link in $path is removed itself (a link to a
     * directory stays, like a directory); $path itself a link is left alone and nothing is
     * deleted through it.
     */
    public static function removeFiles($path)
    {
        $keep = (array) apply_filters('wpc_crit_purge_preserve', ['tpl.txt', 'url.txt', 'used_tpl.txt', 'page.html_gzip', 'page_mobile.html_gzip']);
        $path = rtrim($path, '/');
        if (@is_link($path)) {
            return;
        }
        $files = glob($path . '/*');
        if (!empty($files)) {
            foreach ($files as $file) {
                if (!is_file($file) || in_array(basename($file), $keep, true)) {
                    continue;
                }
                // The park record keeps the page's removal epoch through every wipe.
                if (basename($file) === 'gen_fails.json' && function_exists('wpc_crit_park_reset')) {
                    wpc_crit_park_reset($path);
                    continue;
                }
                unlink($file);
            }
        }
    }

    // TODO: Maybe it will cause errors with non SSL sites?

    public static function purgeBreeze()
    {
        do_action('breeze_clear_all_cache');

        if (defined('BREEZE_VERSION')) {
            wpc_fs_remove_tree(breeze_get_cache_base_path(is_network_admin(), true), true);

            if (function_exists('wp_cache_flush')) {
                if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('breeze'); } else { @wp_cache_flush(); }
            }
        }
    }

    public static function purgeCacheFiles($url_key = false, $preserve_assets = false, $purge_mode = null)
    {
        $cache_dir = WPS_IC_CACHE;

        if (!$url_key) {
            $is_soft = function_exists('wpc_purge_is_soft') && wpc_purge_is_soft($purge_mode);
            // Ask the coalescer before logging, so a purge folded into another one's window is
            // receipted once, as purge-local-all-coalesced, not also as a hard purge-local-all.
            if (!$is_soft && function_exists('wpc_purge_all_should_coalesce') && wpc_purge_all_should_coalesce()) {
                return true;
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('purge-local-all', '', '', [
                    'src' => (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'wpc_purge_src')) ? 'purgeCacheFiles<' . wps_ic_cache::wpc_purge_src() : 'purgeCacheFiles',
                    'mode' => $is_soft ? 'stale' : 'hard',
                ]);
            }
            if ($is_soft) {
                wpc_mark_site_stale();
                if (class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'wpc_stale_walk_after_response')) {
                    wps_cacheHtml::wpc_stale_walk_after_response();
                }
                return true;
            }
            if ($preserve_assets) {


                self::removeDirectoryExcept($cache_dir, ['css', 'js', 'wpc-cflog.php']);
            } else {
                self::removeDirectoryExcept($cache_dir, ['wpc-cflog.php']);
            }
            if (function_exists('wpc_stale_hard_purge_done')) {
                wpc_stale_hard_purge_done();
            }
        } else {
            self::removeFiles($cache_dir . $url_key);
            // v7.10.643 — THE COOKIE-VARIANT GHOST (team atlas card; James's "homepage
            // crit never purges, only inner pages"): logged-in variants (<md5>/<key>)
            // and cookie variants (<key>_suffix) were invisible to every per-URL purge
            // and to the autopurge clock's eviction — the operator's own variant copy
            // of the homepage was precisely the copy nothing could evict, while bare-key
            // visitors saw fresh renders. The glob can over-match an underscore-slug
            // sibling page ('&'→'_' in keys, WP slugs allow '_'): accepted — an extra
            // HTML re-render is fail-safe, an un-evictable stale page is not.
            foreach ([
                $cache_dir . $url_key . '_*',
                $cache_dir . '*/' . $url_key,
                $cache_dir . '*/' . $url_key . '_*',
            ] as $pattern) {
                foreach ((array) @glob($pattern, GLOB_ONLYDIR) as $variant_dir) {
                    if (wpc_fs_link_below($cache_dir, $variant_dir)) {
                        continue;
                    }
                    self::removeFiles($variant_dir);
                }
            }
        }

        return true;
    }

    public static function wpc_purgeCF($return = false)
    {
        return false; // dead lane — never wired (audit-confirmed); heavy body skipped

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


        $varnish_ips = apply_filters('wps_ic_varnish_ips', []);

        // If no IPs specified, use empty string to use the host
        if (empty($varnish_ips)) {
            $varnish_ips = [''];
        } elseif (is_string($varnish_ips)) {
            $varnish_ips = (array) $varnish_ips;
        }


        // Rule: a PURGE goes out only when a Varnish is known to be in front of this site; see
        // varnishInFront(). Observed failure (staging, 2026-09-27): with no Varnish in front, every
        // purge sent `PURGE /.*` to the site's host and to 127.0.0.1, both reached the plugin's own
        // advanced-cache drop-in, which answered each with a stored copy and wrote
        // `nonget-refused {what: hit-kick|rewarm, method: PURGE}`, 9 times in 4 minutes.
        $varnishReason = self::varnishInFront($varnish_ips);
        if ($varnishReason === '') {
            if (function_exists('wpc_belt_receipt')) {
                wpc_belt_receipt('varnish-purge-skipped', ['why' => 'no-varnish', 'full' => $full_site ? 1 : 0], true, '');
            }
            return false;
        }

        // Loopback fallback, but never blindly. Three receipted problems with appending a bare
        // 127.0.0.1 (busy: `worst: https://127.0.0.1/.* 5002ms`, 9 calls / 9520ms in one request):
        //  1. the check was an EXACT match, so a host integration that already declares
        //     127.0.0.1:8080 (Cloudways) did not match and a SECOND, wrong entry was added;
        //  2. $args carries no 'timeout', so WP's 5s default applies — and 'blocking' => false
        //     does not help because cURL still blocks through connect. TLS to loopback :443 with
        //     no listener therefore costs the full 5s, on every purge;
        //  3. the failure was never remembered, so each purge paid it again.
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

            $ipArgs = $args;
            $wpc_is_lb = (is_string($ip) && strpos($ip, '127.0.0.1') !== false);
            if ($wpc_is_lb) {
                // TLS to the loopback IP can never match the site cert — the Host header (set
                // above) is what routes it inside nginx/Varnish.
                $ipArgs['sslverify'] = false;
                // A Varnish on localhost answers in single-digit ms. Anything slower means nothing
                // is listening, so cap the wait instead of inheriting WP's 5s default.
                $ipArgs['timeout'] = max(1, (int) apply_filters('wpc_varnish_loopback_timeout', 2));
            }

            try {
                $wpc_lb_t0 = microtime(true);
                $wpc_pr = wp_remote_request($purge_url, $ipArgs);
                // Remember a dead loopback so the next purge does not pay for it again. Only the
                // auto-appended bare IP is ever written off — a user-configured host stays.
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
                // Continue to next IP on error
                continue;
            }
        }

        return true;
    }


    /**
     * Why this site is known to have a Varnish in front of it, or '' when it is not known:
     * 'configured' when a host integration or the site's own code named the Varnish addresses
     * (the wps_ic_varnish_ips filter: Cloudways when its Varnish is on, DreamPress, Flywheel,
     * o2switch, WP Serveur), 'seen' when a request through a Varnish reached this site in the last
     * 30 days (observeVarnishFront(): Varnish adds X-Varnish, and usually Via, to every request it
     * passes to the origin). The filter wpc_varnish_in_front answers for a site neither covers.
     */
    public static function varnishInFront($varnishIps)
    {
        $reason = '';
        foreach ((array) $varnishIps as $varnishIp) {
            if (is_string($varnishIp) && trim($varnishIp) !== '') {
                $reason = 'configured';
                break;
            }
        }
        if ($reason === '') {
            $seenAt = function_exists('get_option') ? (int) get_option('wpc_varnish_seen', 0) : 0;
            if ($seenAt > 0 && time() - $seenAt < 30 * DAY_IN_SECONDS) {
                $reason = 'seen';
            }
        }
        return function_exists('apply_filters') ? (string) apply_filters('wpc_varnish_in_front', $reason) : $reason;
    }

    /**
     * Records that a request reached this site through a Varnish (the X-Varnish request header, or
     * a Via header naming varnish). Written at most once a day, so the verdict costs one option
     * read per request and no write while it is current.
     */
    public static function observeVarnishFront()
    {
        $via = isset($_SERVER['HTTP_VIA']) ? strtolower((string) $_SERVER['HTTP_VIA']) : '';
        if (empty($_SERVER['HTTP_X_VARNISH']) && strpos($via, 'varnish') === false) {
            return;
        }
        if (time() - (int) get_option('wpc_varnish_seen', 0) < DAY_IN_SECONDS) {
            return;
        }
        update_option('wpc_varnish_seen', time(), false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('varnish-seen', '', '', ['via' => substr($via, 0, 60), 'xv' => empty($_SERVER['HTTP_X_VARNISH']) ? 0 : 1]);
        }
    }

    private static $purgedUrls = [];


    public static function purgeUrlHtml($url_key, $url = '', $opts = [])
    {
        $opts   = array_merge(['context' => '', 'warm' => true, 'varnish' => true], is_array($opts) ? $opts : []);
        $layers = [];
        if (empty($url_key) || !is_string($url_key)) {
            return $layers;
        }
        if (empty($opts['force']) && isset(self::$purgedUrls[$url_key])) {
            return self::$purgedUrls[$url_key];
        }

        // Resolve + validate the URL. sanitizeSameHostUrl is the security boundary: everything
        // below feeds wp_remote requests / CDN purge APIs, so only same-host URLs ever pass.
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

        // 1. Local hashed page cache. Rebuild mode: leave in place — the warm overwrite replaces it.
        if ($rebuild) {
            $layers['local'] = 'rebuild';
        } else {
            $layers['local'] = (bool) self::purgeCacheFiles($url_key);
        }

        if ($clean === '') {
            // Map miss (older install, hand-cleared crit dir): local-only and STOP. Never full-purge.
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('url-map-miss', $url_key, '', ['context' => $opts['context']]);
            }
            self::$purgedUrls[$url_key] = $layers;
            return $layers;
        }

        // 2. Static URI-mirror (zero-PHP serve tree — a DIFFERENT tree than the hashed slug dir;
        //    purgeCacheFiles can't reach it). Self-gates on the Advanced Cache toggle.
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

        // 3. Varnish by URL — non-blocking PURGE; a 4xx/405 on non-Varnish hosts is harmless.
        if (!empty($opts['varnish'])) {
            try {
                $layers['varnish'] = (bool) self::purgeVarnish(0, false, $clean);
            } catch (\Throwable $e) {
                $layers['varnish'] = false;
            }
        }

        // 4. Cloudflare HTML-only, scoped (≤30 files/call; no-op when CF isn't connected; never
        //    purge_everything). Both slash forms — CF purge-by-URL is exact-match.


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

        // 5. Third-party page caches that support per-URL purging (full-only ones are coalesced below).
        $third_party_layers = self::purgeThirdPartyUrl($clean);
        $layers = array_merge($layers, $third_party_layers);

        // 6. Full-only local page caches (no per-URL API): bounded coalesced full purge —
        //    skipping any cache the per-page adapters above already handled (hit-rate law).
        $layers['fullonly'] = self::maybeFullPurgeFullOnlyLayers(array_keys(array_filter($third_party_layers)));

        // 7. Extensibility — deliberately NOT the legacy wps_ic_purge_all_cache hook (its listeners

        do_action('wps_ic_purge_url_html', $clean, $url_key, $opts['context']);

        // 8. Re-warm so the next visitor HITs an optimized page instead of paying the render.
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

    /**
     * Per-URL purges for third-party page caches that expose one. Every call is existence-guarded
     * and Throwable-wrapped: co-installed plugins change APIs, and a purge helper must never fatal.
     */
    private static function purgeThirdPartyUrl($url)
    {
        $out = [];
        try { // WP Rocket
            if (function_exists('rocket_clean_files')) {
                rocket_clean_files($url);
                $out['rocket'] = true;
            }
        } catch (\Throwable $e) {
            $out['rocket'] = false;
        }
        try { // W3 Total Cache
            if (function_exists('w3tc_flush_url')) {
                w3tc_flush_url($url);
                $out['w3tc'] = true;
            }
        } catch (\Throwable $e) {
            $out['w3tc'] = false;
        }
        try { // WP Super Cache
            if (function_exists('wpsc_delete_url_cache')) {
                wpsc_delete_url_cache($url);
                $out['wpsc'] = true;
            }
        } catch (\Throwable $e) {
            $out['wpsc'] = false;
        }
        try { // LiteSpeed Cache plugin (official per-URL API)
            if (defined('LSCWP_V') || class_exists('\LiteSpeed\Purge')) {
                do_action('litespeed_purge_url', $url);
                $out['litespeed'] = true;
            } elseif (!headers_sent() && !empty($_SERVER['SERVER_SOFTWARE'])
                && stripos($_SERVER['SERVER_SOFTWARE'], 'litespeed') !== false) {
                // LiteSpeed SERVER cache without the plugin: the documented response-header directive.
                $path = (string) parse_url($url, PHP_URL_PATH);
                if ($path !== '') {
                    header('X-LiteSpeed-Purge: ' . $path, false);
                    $out['litespeed'] = true;
                }
            }
            // BOTH mechanisms are response-header transports — useless from background lanes
            // (detached land callbacks, cron, collectors), where the clean URL then serves a
            // stale LS HIT that never runs PHP (hawkeye receipt 2026-07-20). The purge-ping is
            // the no-cron primary: one non-blocking cache-miss loopback whose OWN response
            // carries the purge directive for the clean path.
            if (function_exists('wpc_ls_purge_ping')) {
                $out['ls_ping'] = (bool) wpc_ls_purge_ping($url);
            }
        } catch (\Throwable $e) {
            $out['litespeed'] = false;
        }
        try { // WP Cloudflare Super Page Cache (swcfpc) — its listener takes an array of URLs
            if (has_action('swcfpc_purge_cache')) {
                do_action('swcfpc_purge_cache', [$url]);
                $out['swcfpc'] = true;
            }
        } catch (\Throwable $e) {
            $out['swcfpc'] = false;
        }
        try { // Kinsta edge — per-URL when available (same pattern as criticalCss-v2 initCritical)
            if (isset($GLOBALS['kinsta_cache']) && !empty($GLOBALS['kinsta_cache']->kinsta_cache_purge)
                && method_exists($GLOBALS['kinsta_cache']->kinsta_cache_purge, 'purge_url')) {
                $GLOBALS['kinsta_cache']->kinsta_cache_purge->purge_url($url);
                $out['kinsta'] = true;
            }
        } catch (\Throwable $e) {
            $out['kinsta'] = false;
        }

        // Per-PAGE adapters for caches previously stuck in the full-only pool (hit-rate law:
        // a land costs ONE page's cache, never the site's). Every call existence-guarded —
        // a wrong/renamed API is a silent no-op and the plugin stays in the full-only pool.
        $post_id = 0;
        // The url→post_id resolve is a DB query — only pay it when one of the five
        // per-page-adapter caches is actually installed (most of the fleet has none).
        $has_page_adapters = defined('WPHB_VERSION') || function_exists('wpfc_clear_post_cache_by_id')
            || is_callable(['comet_cache', 'clearPost'])
            || is_callable(['Swift_Performance_Cache', 'clear_post_cache'])
            || is_callable(['Swift_Performance_Cache', 'clear_permalink_cache']);
        try {
            if ($has_page_adapters && function_exists('url_to_postid')) {
                $post_id = (int) url_to_postid($url);
            }
            if ($has_page_adapters && $post_id === 0 && function_exists('home_url')
                && untrailingslashit((string) parse_url($url, PHP_URL_PATH)) === untrailingslashit((string) parse_url(home_url('/'), PHP_URL_PATH))) {
                $post_id = (int) get_option('page_on_front');
            }
        } catch (\Throwable $e) {
        }
        if ($post_id > 0) {
            try { // Hummingbird — the same action WITH a post id is per-page
                if (defined('WPHB_VERSION')) {
                    do_action('wphb_clear_page_cache', $post_id);
                    $out['wphb_page'] = true;
                }
            } catch (\Throwable $e) {
            }
            try { // WP Fastest Cache
                if (function_exists('wpfc_clear_post_cache_by_id')) {
                    wpfc_clear_post_cache_by_id($post_id);
                    $out['wpfc_page'] = true;
                }
            } catch (\Throwable $e) {
            }
            try { // Comet Cache
                if (is_callable(['comet_cache', 'clearPost'])) {
                    call_user_func(['comet_cache', 'clearPost'], $post_id);
                    $out['comet_page'] = true;
                }
            } catch (\Throwable $e) {
            }
            try { // Swift Performance (name varied across versions — try both, guarded)
                if (is_callable(['Swift_Performance_Cache', 'clear_post_cache'])) {
                    call_user_func(['Swift_Performance_Cache', 'clear_post_cache'], $post_id);
                    $out['swift_page'] = true;
                } elseif (is_callable(['Swift_Performance_Cache', 'clear_permalink_cache'])) {
                    call_user_func(['Swift_Performance_Cache', 'clear_permalink_cache'], $post_id);
                    $out['swift_page'] = true;
                }
            } catch (\Throwable $e) {
            }
        }
        try { // WP-Optimize — per-URL API when present
            if (is_callable(['WPO_Page_Cache', 'delete_cache_by_url'])) {
                call_user_func(['WPO_Page_Cache', 'delete_cache_by_url'], $url);
                $out['wpo_page'] = true;
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /**
     * Page caches with NO per-URL API (they'd otherwise pin the stale crit-less HTML for their full
     * TTL): a coalesced full purge, at most once per window (default 10 min) — bounded blast instead
     * of today's full purge on EVERY crit-land. Calls mirror the in-repo integrations/*.php exactly.
     * Opt out: add_filter('wpc_purge_fullonly_on_crit', '__return_false').
     */
    private static function maybeFullPurgeFullOnlyLayers($handled = [])
    {
        if (!apply_filters('wpc_purge_fullonly_on_crit', true)) {
            return 'off';
        }
        $handled = is_array($handled) ? $handled : [];
        // A cache whose per-page adapter just ran is NOT full-only for this land.
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
            return 'scoped'; // everything present was handled per-page — zero full purges
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

if (function_exists('add_action')) {
    add_action('init', ['wps_ic_cache_integrations', 'observeVarnishFront'], 1);
}
