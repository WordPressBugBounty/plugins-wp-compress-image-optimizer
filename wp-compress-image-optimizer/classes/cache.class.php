<?php


/**
 * Class - Cache
 * Handles CSS Caching
 */
class wps_ic_cache
{

    public static $cache_option = 'wps_ic_modified_css_cache';
    public static $cache;
    public static $options;
    public static $purge_rules;
    public static $Requests;


    public function __construct()
    {
        self::$Requests = new wps_ic_requests();
    }


    public static function init()
    {
        self::$cache = get_option(self::$cache_option);
        self::$options = get_option(WPS_IC_OPTIONS);

        if (!empty($_GET['wpc_action'])) {
            self::purge_actions();
        }
    }


    /**
     * Purge OPCache and other caches
     */
    public function purgeObjectCache() {
        // (1) PHP OPcache reset — invalidates all cached scripts for this PHP-FPM pool
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // (2) APCu (if used)
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }

        // (3) WordPress object cache (e.g., Redis or Memcached)
        if (function_exists('wp_cache_flush')) {
            @wp_cache_flush();
        }

        // (4) Transients
        if (function_exists('delete_expired_transients')) {
            @delete_expired_transients();
        }
    }


    public static function purge_actions()
    {
        if (!empty($_GET['wpc_action']) && empty($_GET['apikey'])) {
            wp_send_json_error();
        }

        if (!empty($_GET['wpc_action'])) {
            $apikey = sanitize_text_field($_GET['apikey']);
            if ($apikey !== self::$options['api_key']) {
                wp_send_json_error('Bad API Key');
            }

            if ($_GET['wpc_action'] == 'purge_other_cache') {
                $oldOptions = $options = get_option(WPS_IC_OPTIONS);

                $CSSHash = substr(md5(microtime(true)), 0, 6);
                $JSHash = strrev($CSSHash);

                $options['css_hash'] = $CSSHash;
                $options['js_hash'] = $JSHash;

                if (!class_exists('wps_ic_log')) {
                    include_once WPS_IC_DIR . 'classes/log.class.php';
                }

                $log = new wps_ic_log();
                $log->logCachePurging($oldOptions, $options, 'purge_actions');

                update_option(WPS_IC_OPTIONS, $options);

                self::purgeOtherCache();
            }
        }
    }

    public static function purgeOtherCache($json = true)
    {

        // Rocket - Clear cache
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // Lite Speed
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // HummingBird
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        // Breeze
        self::purgeBreeze();

        // Others
        self::purgeSuperCache();
        self::purgeFastestCache();
        self::purge_cache_files();

        if ($json) {
            wp_send_json_success('Purged Other Cache');
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
                wp_cache_flush();
            }
        }
    }

    public static function purgeSuperCache()
    {
        if (defined('WPCACHEHOME')) {
            global $file_prefix;
            wp_cache_clean_cache($file_prefix, !empty($params['all']));
        }
    }

    public static function purgeFastestCache()
    {
        if (defined('WPFC_WP_CONTENT_BASENAME')) {
            global $file_prefix;
            wp_cache_clean_cache($file_prefix, !empty($params['all']));
        }
    }

    public static function purge_cache_files()
    {
        $cache_dir = WPS_IC_CACHE;

        self::removeDirectory($cache_dir);

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

    public static function purgeHooks()
    {
        self::$options = get_option(WPS_IC_SETTINGS);

        if (!empty(self::$options['cache']['advanced']) && self::$options['cache']['advanced'] == '1') {

            self::$purge_rules = get_option('wps_ic_purge_rules');

            if (!empty(self::$options['cache']['purge-hooks']) && self::$options['cache']['purge-hooks'] == '1') {

                if (!empty(self::$purge_rules) && !empty(self::$purge_rules['hooks'])) {

                    // List of hooks to also clear crit, combine, new cdn hashes
                    $full_param_hooks = ['switch_theme',
                        'wp_update_nav_menu',
                        'update_option_theme_mods_' . get_option('stylesheet'),
                        'et_core_static_resources_removed',
                        'fl_builder_cache_cleared',
                        ''];
                    foreach (self::$purge_rules['hooks'] as $hook) {
                        if (in_array($hook, $full_param_hooks)) {
                            self::purgeHook($hook, 1, 1, 1, 1);
                        } else {
                            // For other hooks only clear cache
                            self::purgeHook($hook);
                        }
                    }

                    //Post publish hooks
                    add_action('save_post', ['wps_ic_cache', 'removeCriticalFiles'], 10, 1); //always purge crit
                    add_action('save_post', ['wps_ic_cache', 'resetHashes'], 10, 1); //always reset hashes
                    add_action('save_post', ['wps_ic_cache', 'removeHtmlCacheFiles'], 10, 1); //always purge cache

                    if (!empty(self::$purge_rules['post-publish'])) {
                        if (!empty(self::$purge_rules['post-publish']['all-pages']) || !empty(self::$purge_rules['post-publish']['home-page']) || !empty(self::$purge_rules['post-publish']['recent-posts-widget']) || !empty(self::$purge_rules['post-publish']['archive-pages'])) {
                            add_action('transition_post_status', ['wps_ic_cache', 'purge_cache_on_post_changes'], 10, 3);
                        }
                    }

                }
            }
        }

        //per page purge hooks from smart-opt screen scheckboxes
        add_action('publish_post', ['wps_ic_cache', 'purgeCachePerPage'], 10, 1);
        add_action('wp_trash_post', ['wps_ic_cache', 'purgeCachePerPage'], 10, 1);
        add_action('delete_post', ['wps_ic_cache', 'purgeCachePerPage'], 10, 1);

        add_action('comment_post', ['wps_ic_cache', 'purgeOnCommentPost'], 10, 2);
        add_action('edit_comment', ['wps_ic_cache', 'purgeOnCommentAction'], 10, 1);
        add_action('transition_comment_status', ['wps_ic_cache', 'purgeOnCommentStatusChange'], 10, 3);
        add_action('deleted_comment', ['wps_ic_cache', 'purgeOnCommentAction'], 10, 1);
        add_action('trashed_comment', ['wps_ic_cache', 'purgeOnCommentAction'], 10, 1);
        add_action('untrashed_comment', ['wps_ic_cache', 'purgeOnCommentAction'], 10, 1);
        add_action('spammed_comment', ['wps_ic_cache', 'purgeOnCommentAction'], 10, 1);
        add_action('unspammed_comment', ['wps_ic_cache', 'purgeOnCommentAction'], 10, 1);
    }

    public static function purgeHook($hook, $cache = 1, $combined = 0, $critical = 0, $hash = 0)
    {
        //accepted_args 0 to force clearing all cache
        if ($hash) {
            add_action($hook, ['wps_ic_cache', 'resetHashes'], 10, 0);
        }

        if ($cache) {
            add_action($hook, ['wps_ic_cache', 'removeHtmlCacheFiles'], 10, 0);
        }

        if ($combined) {
            add_action($hook, ['wps_ic_cache', 'removeCombinedFiles'], 10, 0);
        }

        if ($critical) {
            add_action($hook, ['wps_ic_cache', 'removeCriticalFiles'], 10, 0);
        }
    }

    public static function purgeCachePerPage()
    {
        // Get the excludes option
        $wpc_excludes = get_option('wpc-excludes', []);

        // Check if per_page_settings exists
        if (!isset($wpc_excludes['per_page_settings']) || empty($wpc_excludes['per_page_settings'])) {
            return;
        }

        // Loop through each page settings
        foreach ($wpc_excludes['per_page_settings'] as $page_id => $settings) {
            // Check if purge_on_new_post is enabled
            if (isset($settings['purge_on_new_post']) && $settings['purge_on_new_post'] !== 'false') {
                self::removeHtmlCacheFiles($page_id);
            }
        }
    }

    public static function removeHtmlCacheFiles($post_id = 'all', $post = '', $update = '')
    {
        if (!is_int($post_id) && $post_id !== 'all' && $post_id !== 'home') {
            $post_id = 'all';
        }

        if (self::is_cache_cleared()) {
            //if we cleared all, ignore subsequent clears in the same request
            return;
        }

        $cacheHtml = new wps_cacheHtml();
        $cacheHtml->removeCacheFiles($post_id);

        $cache_integrations = new wps_ic_cache_integrations();
        // (v7.03.92) Match the Varnish scope to the clear scope: per-post → that URL; 'home' → homepage;
        // 'all' → FULL-SITE (regex). Before this, 'all' purged only the homepage path, so interior pages
        // kept serving stale markup from host Varnish until TTL — the same homepage-only gap crit had. Most
        // site-wide events (settings/delivery/CDN changes, bulk-end) route through here, so this upgrades
        // them all to full-site at once.
        if (is_int($post_id)) {
            $cache_integrations->purgeVarnish($post_id);           // per-URL
        } elseif ($post_id === 'home') {
            $cache_integrations->purgeVarnish(0);                  // homepage only
        } else {
            $cache_integrations->purgeVarnish(0, true);            // 'all' → full-site
        }

        // Fire integration hook once per request (Breeze, LiteSpeed, SG, etc.)
        static $integrations_fired = false;
        if (!$integrations_fired) {
            $integrations_fired = true;
            do_action('wps_ic_purge_all_cache', is_int($post_id) ? get_permalink($post_id) : false);
        }

        // Was causing problems with save_post function? because we call there wpc_purgecf?
//        if (!self::is_cf_cache_cleared()) {
//            //since it clears all cache, we dont have to call it multiple times in a request
//            //can add other cache clears here
//            self::wpc_purgeCF(true);
//            self::mark_cf_cache_cleared();
//        }

        if ($post_id === 'all') {
            self::mark_cache_cleared();
        }

        // v7.02.47 — Warm the homepage after a homepage-affecting purge so the FIRST visitor gets a cache
        // HIT instead of the full cold render. Non-blocking, debounced, jittered, fail-safe (see method).
        // Scope: whole-cache / home / front-page purges only — a single non-front post purge doesn't warm.
        if ($post_id === 'all' || $post_id === 'home' || $post_id === 0
            || (is_int($post_id) && $post_id > 0 && $post_id === (int) get_option('page_on_front'))) {
            self::maybeWarmHomepageAfterPurge();
        }
    }

    /**
     * v7.02.47 — Warm the homepage cache after a homepage-affecting purge, so the FIRST visitor after a
     * purge gets a static cache HIT instead of eating the full cold render (the "purge → 4-7s" gap).
     *
     * Safe by construction:
     *   - Non-blocking: schedules a fire-and-forget local-vhost loopback GET at shutdown, AFTER
     *     fastcgi_finish_request() — the triggering request's response is already sent. Reuses the proven
     *     wpc_loopback_open_socket transport (hits the LOCAL vhost, not the public/CF host).
     *   - Single render: the homepage only — a page guaranteed to get traffic, so the warm render is one
     *     that would have happened on the next visit anyway (it MOVES load earlier, doesn't add it).
     *   - Debounced: a transient lock coalesces a burst of purges into ONE warm per window.
     *   - Jittered: a small random pre-fire delay so a fleet-wide purge (thousands of sites at once)
     *     spreads the re-renders across a window instead of a synchronized spike on shared infra.
     *   - Loop-proof: the warm request carries X-WPC-Cache-Warm; this no-ops on such a request.
     *   - Fail-safe: every failure is swallowed — the page just stays cold until a visitor warms it
     *     (today's behaviour), never worse.
     *   - Gated: HTML caching must be ON; opt out via the wpc_warm_homepage_on_purge option/filter or the
     *     WPC_WARM_HOMEPAGE_DISABLE constant; skipped on Basic-Auth (the loopback can't land there).
     */
    public static function maybeWarmHomepageAfterPurge()
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        // Loop-guard: a warm render must never schedule another warm.
        if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
            return;
        }
        if (defined('WPC_WARM_HOMEPAGE_DISABLE') && WPC_WARM_HOMEPAGE_DISABLE) {
            return;
        }
        if (!apply_filters('wpc_warm_homepage_on_purge', (bool) get_option('wpc_warm_homepage_on_purge', true))) {
            return;
        }
        // Only meaningful when WPC HTML caching is actually ON (else the warm render won't be cached).
        if (!class_exists('wps_cacheHtml')) {
            return;
        }
        $ch = new wps_cacheHtml();
        if (method_exists($ch, 'cacheEnabled') && !$ch->cacheEnabled()) {
            return;
        }
        // The loopback can't land on a Basic-Auth site; the cron/visitor backstop still warms it.
        if (function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth()) {
            return;
        }
        // Debounce: coalesce a purge burst into one warm per window. Set BEFORE registering (race-safe).
        $debounce = (int) apply_filters('wpc_warm_homepage_debounce_seconds', 30);
        if ($debounce < 1) {
            $debounce = 1;
        }
        if (get_transient('wpc_warm_homepage_lock')) {
            return;
        }
        set_transient('wpc_warm_homepage_lock', 1, $debounce);

        $registered = true;
        register_shutdown_function(['wps_ic_cache', 'fireHomepageWarm']);
    }

    /**
     * Shutdown handler for maybeWarmHomepageAfterPurge(): flush the triggering request's response, jitter
     * (fleet-spread), then fire the non-blocking local-vhost loopback GET that renders + caches the
     * homepage. The receiver survives this client-close via the X-WPC-Cache-Warm guard in wp-compress.php
     * (ignore_user_abort). Never throws.
     */
    public static function fireHomepageWarm()
    {
        try {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            // Fleet jitter: spread re-renders when many sites purge at once. Post-flush, so the already-
            // finished triggering request's user is unaffected.
            $jitter_ms = (int) apply_filters('wpc_warm_homepage_jitter_ms', 2000);
            if ($jitter_ms > 0) {
                @usleep(mt_rand(0, $jitter_ms) * 1000);
            }
            $parts = wp_parse_url(home_url('/'));
            if (empty($parts['host'])) {
                return;
            }
            $https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
            $port  = !empty($parts['port']) ? (int) $parts['port'] : ($https ? 443 : 80);
            $host  = (string) $parts['host'];
            $path  = !empty($parts['path']) ? $parts['path'] : '/';
            if (!class_exists('wps_ic_ajax') || !method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) {
                return;
            }
            // Browser-like UA (no bot token) so the request caches exactly like a visitor; the
            // X-WPC-Cache-Warm header is what identifies it (loop-guard + disconnect-survival).
            $req = "GET {$path} HTTP/1.1\r\n"
                 . "Host: {$host}\r\n"
                 . "User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0\r\n"
                 . "Accept: text/html\r\n"
                 . "X-WPC-Cache-Warm: 1\r\n"
                 . "Connection: close\r\n\r\n";
            $fp = wps_ic_ajax::wpc_loopback_open_socket($host, $port, $https, 0.2);
            if ($fp) {
                @stream_set_timeout($fp, 0, 100000);
                @fwrite($fp, $req);
                @fclose($fp);
            }
        } catch (\Throwable $e) {
            // Fail-safe: warming is best-effort. A failure just leaves the page to warm on first visit.
        }
    }

    private static function is_cache_cleared()
    {
        global $wps_ic_cache_cleared;
        return !empty($wps_ic_cache_cleared);
    }

    private static function is_cf_cache_cleared()
    {
        global $wps_ic_cf_cache_cleared;
        return !empty($wps_ic_cf_cache_cleared);
    }

    public static function wpc_purgeCF($return = false)
    {

        $cfSettings = get_option(WPS_IC_CF);

        if (!empty($cfSettings)) {
            $zone = $cfSettings['zone'];
            $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
            if ($cfapi) {
                $cfapi->purgeCache($zone);
                sleep(6);
            }
        }

        if ($return) {
            return true;
        } else {
            wp_send_json_success();
        }
    }

    public static function purgeCache()
    {

        self::$options = get_option(WPS_IC_OPTIONS);
        set_transient('wps_ic_purging_cdn', 'true', 10);

        $call = self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => self::$options['api_key'], 'callback' => site_url(), 'hash' => md5(microtime())]);

        // Purge Cached Files
        $cache_dir = WPS_IC_CACHE;
        if (file_exists($cache_dir)) {
            self::removeDirectory($cache_dir);
        }
    }

    private static function mark_cf_cache_cleared()
    {
        global $wps_ic_cf_cache_cleared;
        $wps_ic_cf_cache_cleared = true;
    }

    private static function mark_cache_cleared()
    {
        global $wps_ic_cache_cleared;
        $wps_ic_cache_cleared = true;
    }

    public static function purgeAllCache()
    {
        $cacheHtml = new wps_cacheHtml();
        $cacheHtml->removeCacheFiles('all');
    }

    public static function purgeElementorCache($document)
    {
        $cacheHtml = new wps_cacheHtml();
        $cacheHtml->removeCacheFiles($document->get_post()->ID);
    }

    public static function purgeCDNUpdate()
    {
        // Add User Capabilities
        $users = new wps_ic_users();
        $cacheLogic = new wps_ic_cache();
        $cache = new wps_ic_cache_integrations();

        $oldOptions= $options = get_option(WPS_IC_OPTIONS);
        if (!is_array($options)) $oldOptions = $options = [];

        $cacheLogic->purgeObjectCache();

        $cache::purgeAll(false, true, false, false, false, true); // preserve wp-cio/css on update (cached HTML still references it)
        $cache::purgeCombinedFiles();

        // Purge HTML Cache
        $cacheLogic::removeHtmlCacheFiles(0); // Purge & Preload
        $cacheLogic::preloadPage(0); // Purge & Preload

        $CSSHash = substr(md5(microtime(true)), 0, 6);
        $JSHash = strrev($CSSHash);

        $options['css_hash'] = $CSSHash;
        $options['js_hash'] = $JSHash;

        if (!class_exists('wps_ic_log')) {
            include_once WPS_IC_DIR . 'classes/log.class.php';
        }

        $log = new wps_ic_log();
        $log->logCachePurging($oldOptions, $options, 'purgeCdnUpdate');

        $options['updated_hash'] = time();
        update_option(WPS_IC_OPTIONS, $options);

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        // (v7.03.50) NO CDN purge on plugin update/activation. This runs on upgrader_process_complete +
        // activated_plugin; the old full `action=cdn_purge` here wiped EVERY edge-cached image on every
        // update — a fleet-wide cold-miss storm (the edge re-probes the slow origin for each image →
        // PHP-worker saturation → 40-60s pages), the exact failure cf-sdk::purgeFilesAsync documents avoiding.
        // It's also redundant: the css_hash/js_hash bump above re-stamps every CSS/JS CDN URL with a fresh
        // ?icv=/?js_icv= (cdn-rewrite.php:575/579) → new cache key → the edge refetches CSS/JS on its own.
        // Images don't change on a plugin update, so they MUST stay cached. The HTML/object/local caches are
        // purged above (+ the page-cache fan-out below) — that's all an update needs. Genuine zone changes
        // still purge the CDN (cname add/remove, the manual purge button) — those are separate paths.

        // Clear cache.
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // Lite Speed
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // HummingBird
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        delete_transient('wps_ic_purging_cdn');
    }

    public static function preloadPage($post_id, $post = '', $update = '')
    {

        if ($post_id != 0) {
            $url = get_permalink($post_id);
        } else {
            $url = home_url();
        }

        #$call = wp_remote_post(WPS_IC_PRELOADER_API_URL, ['body' => ['single_url' => $url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']]]);
        $warmup_class = new wps_ic_preload_warmup();
        $warmup_class->cacheLocally($post_id);
    }

    public static function updateCSSHash($post_id = 0)
    {
        // TODO: Sometimes $post_id is ObjectClass, does this fix it? (occurs on plugin manual zip update)
        if (!is_int($post_id) && !is_string($post_id)) {
            $post_id = 0;
        }

        if (!function_exists('get_option')) {
            require_once ABSPATH . 'wp-admin/includes/option.php';
        }

        $CSSHash = substr(md5(microtime(true)), 0, 6);
        $JSHash = strrev($CSSHash);

        if (is_multisite()) {
            $current_blog_id = get_current_blog_id();
            switch_to_blog($current_blog_id);
            $oldOptions = $options = get_option(WPS_IC_OPTIONS);

            // Update CSS / JS Hash
            $options['css_hash'] = $CSSHash;
            $options['js_hash'] = $JSHash;


            if (!class_exists('wps_ic_log')) {
                include_once WPS_IC_DIR . 'classes/log.class.php';
            }

            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'updateCSSHash-MU');

            update_option(WPS_IC_OPTIONS, $options);
        } else {
            // Reset Cache
            $cacheLogic = new wps_ic_cache();
            $cacheLogic::removeHtmlCacheFiles($post_id); // Purge & Preload

            // Preload on plugin update, just 1 time in 3 minutes
            if (!get_transient('wpc_update_css_preload')) {
                set_transient('wpc_update_css_preload', 'true', 60 * 3);
                $cacheLogic::preloadPage($post_id); // Purge & Preload => Causing Issue with memory
            }

            $oldOptions = $options = get_option(WPS_IC_OPTIONS);

            // Update CSS / JS Hash
            $options['css_hash'] = $CSSHash;
            $options['js_hash'] = $JSHash;

            if (!class_exists('wps_ic_log')) {
                include_once WPS_IC_DIR . 'classes/log.class.php';
            }

            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'updateCSSHash');

            update_option(WPS_IC_OPTIONS, $options);
        }
    }


    public static function deleteFolder($folderPath)
    {
        if (is_dir($folderPath)) {
            $contents = scandir($folderPath);
            foreach ($contents as $item) {
                if ($item != "." && $item != "..") {
                    $itemPath = $folderPath . DIRECTORY_SEPARATOR . $item;
                    if (is_dir($itemPath)) {
                        // Recursively delete subdirectories and their contents
                        self::deleteFolder($itemPath);
                    } else {
                        // Delete files
                        unlink($itemPath);
                    }
                }
            }

            // Delete the empty folder
            if (is_dir($folderPath)) {
                rmdir($folderPath);
            }

        }

        return true;
    }


    // Store cached file

    public static function purge_cache_on_post_changes($new_status, $old_status, $post)
    {
        // Skip conditions - no purging needed
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (wp_is_post_revision($post->ID)) {
            return;
        }
        if (empty(self::$purge_rules)) {
            self::$purge_rules = get_option('wps_ic_purge_rules');
        }

        if ($new_status == 'publish' || ($old_status == 'publish' && $new_status != 'publish')) {
            // If configured to clear all pages
            if (!empty(self::$purge_rules['post-publish']['all-pages']) && self::$purge_rules['post-publish']['all-pages'] == '1') {
                self::removeHtmlCacheFiles('all');
            } // Otherwise, selectively clear based on settings
            else {
                // Clear home page if enabled
                if (!empty(self::$purge_rules['post-publish']['home-page']) && self::$purge_rules['post-publish']['home-page'] == '1') {
                    self::removeHtmlCacheFiles('home');
                }

                // Clear archive pages if enabled
                $cacheHtml = new wps_cacheHtml();

                if (!empty(self::$purge_rules['post-publish']['archive-pages']) && self::$purge_rules['post-publish']['archive-pages'] == '1') {
                    if (!empty(self::$purge_rules['type-lists']['archive-pages'])) {
                        foreach (self::$purge_rules['type-lists']['archive-pages'] as $urlKey) {
                            $cacheHtml->removeCacheFilesByKey($urlKey);
                            self::$purge_rules['type-lists']['archive-pages'] = [];
                        }
                    }
                }

                // Clear archive pages if enabled
                if (!empty(self::$purge_rules['post-publish']['recent-posts-widget']) && self::$purge_rules['post-publish']['recent-posts-widget'] == '1') {
                    if (!empty(self::$purge_rules['type-lists']['recent-posts-widget'])) {
                        foreach (self::$purge_rules['type-lists']['recent-posts-widget'] as $urlKey) {
                            $cacheHtml->removeCacheFilesByKey($urlKey);
                            self::$purge_rules['type-lists']['recent-posts-widget'] = [];
                        }
                    }
                }
                update_option('wps_ic_purge_rules', self::$purge_rules);
            }
        }

    }

    public function cronPurgeAll()
    {
        self::resetHashes();
        self::removeHtmlCacheFiles();
        self::removeCombinedFiles();
        self::removeCriticalFiles();
    }

    public static function resetHashes()
    {
        if (!function_exists('get_option')) {
            require_once ABSPATH . 'wp-admin/includes/option.php';
        }

        $CSSHash = substr(md5(microtime(true)), 0, 6);
        $JSHash = strrev($CSSHash);

        if (is_multisite()) {
            $current_blog_id = get_current_blog_id();
            switch_to_blog($current_blog_id);
            $oldOptions = $options = get_option(WPS_IC_OPTIONS);

            $options['css_hash'] = $CSSHash;
            $options['js_hash'] = $JSHash;

            if (!class_exists('wps_ic_log')) {
                include_once WPS_IC_DIR . 'classes/log.class.php';
            }

            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'resetHashes');

            update_option(WPS_IC_OPTIONS, $options);
            restore_current_blog();
        } else {
            $oldOptions = $options = get_option(WPS_IC_OPTIONS);

            $options['css_hash'] = $CSSHash;
            $options['js_hash'] = $JSHash;

            if (!class_exists('wps_ic_log')) {
                include_once WPS_IC_DIR . 'classes/log.class.php';
            }

            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'resetHashes');

            update_option(WPS_IC_OPTIONS, $options);
        }
    }

    // Check if cache was cleared already

    public static function removeCombinedFiles($post_id = 'all', $post = '', $update = '')
    {
        if (!is_int($post_id) && $post_id !== 'all') {
            $post_id = 'all';
        }
        $cacheHtml = new wps_cacheHtml();
        $cacheHtml->removeCombinedFiles($post_id);
    }

    // Mark cache as cleared for this request

    public static function removeCriticalFiles($post_id = 'all', $post = '', $update = '')
    {
        if (!is_int($post_id) && $post_id !== 'all') {
            $post_id = 'all';
        }
        $cacheHtml = new wps_cacheHtml();
        $cacheHtml->removeCriticalFiles($post_id);
    }

    // Check if cf cache was cleared already

    public function purgeCDN($purgeJS = true)
    {
        $oldOptions = $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

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
        $log->logCachePurging($oldOptions, $options, 'purgeCDN');

        $options['updated_hash'] = time();
        update_option(WPS_IC_OPTIONS, $options);

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        set_transient('wps_ic_purging_cdn', 'true', 30);

        $call = self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'cdn_purge', 'apikey' => $options['api_key']], ['timeout' => 10]);

        // Clear cache.
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // Lite Speed
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // HummingBird
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        delete_transient('wps_ic_purging_cdn');
    }

    // Mark cf cache as cleared for this request

    public function is_page_cached($pageID)
    {
        if (isset(self::$cache[$pageID]) && !empty(self::$cache[$pageID])) {
            return true;
        } else {
            return false;
        }
    }

    public function is_post_cached($postID)
    {
        if (isset(self::$cache[$postID]) && !empty(self::$cache[$postID])) {
            return true;
        } else {
            return false;
        }
    }

    public function get_cache($ID)
    {
        if (isset(self::$cache[$ID]) && !empty(self::$cache[$ID])) {
            return self::$cache[$ID];
        } else {
            return [];
        }
    }

    /**
     * Purge cache when comment is posted (only if approved)
     */
    public static function purgeOnCommentPost($comment_id, $approved)
    {
      if ($approved === 1 || $approved === 'approve') {
        $comment = get_comment($comment_id);
        if ($comment) {
          self::removeHtmlCacheFiles($comment->comment_post_ID);
        }
      }
    }

    /**
     * Purge cache for any comment action (edit, delete, trash, spam, etc.)
     * Only purges if comment is/was approved
     */
    public static function purgeOnCommentAction($comment_id)
    {
      $comment = get_comment($comment_id);
      if ($comment && $comment->comment_approved == '1') {
        self::removeHtmlCacheFiles($comment->comment_post_ID);
      }
    }

    /**
     * Purge cache when comment status changes
     */
    public static function purgeOnCommentStatusChange($new_status, $old_status, $comment)
    {
      if ($new_status !== $old_status) {
        if ($new_status === 'approved' || $old_status === 'approved') {
          self::removeHtmlCacheFiles($comment->comment_post_ID);
        }
      }
    }

}