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
        if (function_exists('wpc_opcache_refresh')) {
            wpc_opcache_refresh('purge-object');
        }

        // (2) APCu (if used)
        if (function_exists('apcu_clear_cache')) {
            @apcu_clear_cache();
        }

        // (3) WordPress object cache (e.g., Redis or Memcached)
        if (function_exists('wp_cache_flush')) {
            if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('purge-object'); } else { @wp_cache_flush(); }
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
                $options['lazy_hash'] = substr(md5($CSSHash . 'lz'), 0, 10);

                if (!class_exists('wps_ic_log')) {
                    include_once WPS_IC_DIR . 'classes/log.class.php';
                }

                if (class_exists('wps_ic_log')) {
                    $log = new wps_ic_log();
                    $log->logCachePurging($oldOptions, $options, 'purge_actions');
                }

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
                if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('breeze'); } else { @wp_cache_flush(); }
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
                    add_action('save_post', ['wps_ic_cache', 'wpc_save_purge_defer920'], 10, 1);
                    add_action('save_post', ['wps_ic_cache', 'resetHashes'], 10, 1);

                    if (!empty(self::$purge_rules['post-publish'])) {
                        if (!empty(self::$purge_rules['post-publish']['all-pages']) || !empty(self::$purge_rules['post-publish']['home-page']) || !empty(self::$purge_rules['post-publish']['recent-posts-widget']) || !empty(self::$purge_rules['post-publish']['archive-pages'])) {
                            add_action('transition_post_status', ['wps_ic_cache', 'purge_cache_on_post_changes'], 10, 3);
                        }
                    }

                }
            }
        }


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

    // Journal forensics: name the code path + hook behind a purge (only runs on purge events)
    public static function wpc_purge_src()
    {
        $src = '';
        try {
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10) as $f) {
                $fl = isset($f['file']) ? basename((string) $f['file']) : '';
                if ($fl === 'cache.class.php' || $fl === '') {
                    continue;
                }
                $src = (isset($f['class']) ? $f['class'] . '::' : '') . ($f['function'] ?? '') . '@' . $fl;
                break;
            }
            if (function_exists('current_action') && current_action()) {
                $src .= '|hook:' . current_action();
            }
        } catch (\Throwable $e) {
        }
        return $src;
    }

    public static function removeHtmlCacheFiles($post_id = 'all', $post = '', $update = '')
    {
        if (!is_int($post_id) && $post_id !== 'all' && $post_id !== 'home') {
            $post_id = 'all';
        }

        if (self::is_cache_cleared()) {

            return;
        }

        if ($post_id === 'all' && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('purge-local-all', '', '', [
                'src' => self::wpc_purge_src(),
                'uri' => isset($_SERVER['REQUEST_URI']) ? substr((string) $_SERVER['REQUEST_URI'], 0, 60) : '',
            ]);
        }

        $cacheHtml = new wps_cacheHtml();
        $cacheHtml->removeCacheFiles($post_id);

        $cache_integrations = new wps_ic_cache_integrations();


        if (is_int($post_id)) {
            $cache_integrations->purgeVarnish($post_id);
        } elseif ($post_id === 'home') {
            $cache_integrations->purgeVarnish(0);
        } else {
            $cache_integrations->purgeVarnish(0, true);            // 'all' → full-site
        }

        // Fire integration hook once per request (Breeze, LiteSpeed, SG, etc.)
        static $integrations_fired = false;
        if (!$integrations_fired) {
            $integrations_fired = true;
            if (is_int($post_id)) {
                // Listeners treat this as purge-EVERYTHING — a single-post purge must not
                // nuke LiteSpeed/Nginx/Varnish more than once per 2 min
                $wpc_tp120 = (int) get_option('wpc_tp_purge_at');
                if (time() - $wpc_tp120 >= 120 && update_option('wpc_tp_purge_at', time(), false)) {
                    wpc_foreign_purge610(get_permalink($post_id), 'post-purge');
                }
            } else {
                wpc_foreign_purge610(false, 'purge-all');
            }
        }


        if ($post_id === 'all') {
            self::mark_cache_cleared();
        }


        // HIT instead of the full cold render. Non-blocking, debounced, jittered, fail-safe (see method).
        // Scope: whole-cache / home / front-page purges only — a single non-front post purge doesn't warm.
        if ($post_id === 'all' || $post_id === 'home' || $post_id === 0
            || (is_int($post_id) && $post_id > 0 && $post_id === (int) get_option('page_on_front'))) {
            self::maybeWarmHomepageAfterPurge();
        }
    }


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
        // v7.10.521 — PARITY, same gap .520 closed in wpc_warm_url_fire(): this schedules a
        // LOOPBACK render, so a warm request reaching it can fan out another warm. queue()
        // has always refused; fire() didn't until .520; this never did.
        if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
            return;
        }
        // A request that cannot be cached must not trigger a warm — a hard refresh sends
        // no-cache, saveCache bails, nothing is stored, and the warm re-fires forever.
        $wpc_cc521 = strtolower((string) ($_SERVER['HTTP_CACHE_CONTROL'] ?? ''));
        if ($wpc_cc521 !== '' && (strpos($wpc_cc521, 'no-cache') !== false
            || strpos($wpc_cc521, 'no-store') !== false)) {
            return;
        }
        // Debounce: coalesce a purge burst into one warm per window.
        $debounce = (int) apply_filters('wpc_warm_homepage_debounce_seconds', 30);
        if ($debounce < 1) {
            $debounce = 1;
        }
        // The old comment here claimed "Set BEFORE registering (race-safe)". It was NOT:
        // get_transient()-then-set_transient() is a read-then-write race, and concurrent
        // requests all pass it. GET_LOCK(name,0) is atomic, so exactly one caller wins.
        if (function_exists('wpc_worker_lock') && !wpc_worker_lock('warm_home_gate')) {
            return;
        }
        if (get_transient('wpc_warm_homepage_lock')) {
            if (function_exists('wpc_worker_unlock')) { wpc_worker_unlock('warm_home_gate'); }
            return;
        }
        set_transient('wpc_warm_homepage_lock', 1, $debounce);
        if (function_exists('wpc_worker_unlock')) {
            wpc_worker_unlock('warm_home_gate');
        }

        $registered = true;
        register_shutdown_function(['wps_ic_cache', 'fireHomepageWarm']);
    }


    public static function fireHomepageWarm()
    {
        try {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }


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
        return false; // dead lane — never wired (audit-confirmed); heavy body skipped


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


        $post_id = 0;
        if (is_object($document) && method_exists($document, 'get_post')) {
            $p = $document->get_post();
            if (is_object($p) && !empty($p->ID)) {
                $post_id = (int) $p->ID;
            }
        }

        $cacheHtml = new wps_cacheHtml();


        if ($post_id !== 0 && function_exists('get_post_type') && get_post_type($post_id) === 'elementor_library') {
            if (function_exists('apply_filters') && !apply_filters('wpc_save_purge_defer', true)) {
                $cacheHtml->removeCacheFiles('all');
                $cacheHtml->removeCriticalFiles('all');
                self::purgeEdgeHtmlUrls([home_url('/')]);
                return;
            }
            self::wpc_save_purge_queue920(0);
            return;
        }


        if ($post_id === 0) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('elementor-save-noid', '', '', []);
            }
            return;
        }

        // Normal page/post: scope to THIS page — its HTML cache AND its critical CSS.
        self::wpc_save_purge_defer920($post_id);
    }


    public static function purgeEdgeHtmlUrls($urls, $inline = false)
    {
        if (empty($urls) || !is_array($urls)) {
            return;
        }
        if (function_exists('wpc_purge_request_allowed') && !wpc_purge_request_allowed('edge-html')) {
            if (function_exists('wpc_purge_gate_log604')) { wpc_purge_gate_log604('edge-html'); }
            return;
        }
        $urls = array_values(array_unique(array_filter(array_map('strval', $urls))));
        $urls = apply_filters('wpc_cf_html_purge_urls', $urls);
        if (empty($urls)) {
            return;
        }


        static $wpc_cf_queued = [];
        $urls = array_values(array_diff($urls, $wpc_cf_queued));
        if (empty($urls)) {
            return true;
        }
        foreach ($urls as $wpc_qu) {
            $wpc_cf_queued[] = $wpc_qu;
        }

        // v7.10.604 — CROSS-REQUEST COALESCE. The static above only dedupes within one request,
        // but ~19 artifact types plus N image variants land for a URL at different times and each
        // landing purges: measured on wpcompress.com, 48.8% of same-URL purges arrive within 10
        // SECONDS of the previous one and 54.8% within 60s (median gap 14s). The second purge
        // inside that window evicts nothing the first did not.
        $wpc_co604 = (int) apply_filters('wpc_cf_purge_coalesce_s', 60);
        if ($wpc_co604 > 0 && function_exists('get_transient')) {
            $wpc_keep604 = [];
            $wpc_drop604 = 0;
            foreach ($urls as $wpc_cu604) {
                $wpc_ck604 = 'wpc_cfp_' . md5((string) $wpc_cu604);
                if (get_transient($wpc_ck604)) {
                    $wpc_drop604++;
                    continue;
                }
                set_transient($wpc_ck604, 1, $wpc_co604);
                $wpc_keep604[] = $wpc_cu604;
            }
            if ($wpc_drop604 > 0 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('cf-purge-coalesced', '', '', ['dropped' => $wpc_drop604, 'win' => $wpc_co604]);
            }
            if (empty($wpc_keep604)) {
                return true;
            }
            $urls = $wpc_keep604;
        }

        $cf = get_option(WPS_IC_CF);
        if (empty($cf['token']) || empty($cf['zone'])) {
            return; // Cloudflare not connected → nothing to evict at the edge
        }

        $send = function () use ($cf, $urls) {
            try {
                if (!class_exists('WPC_CloudflareAPI')) {
                    @include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
                }
                if (!class_exists('WPC_CloudflareAPI')) {
                    return;
                }
                $sdk = new WPC_CloudflareAPI($cf['token']);
                $ok  = true;


                $wpc_reset105 = self::cfTagResetOnce($sdk, $cf['zone']);
                $wpc_tags105 = [];
                $wpc_prefixes105 = [];
                $wpc_root_hosts105 = [];
                foreach ($urls as $wpc_u105) {
                    if (function_exists('wpc_cf_url_tag')) {
                        $wpc_tags105[wpc_cf_url_tag($wpc_u105)] = 1;
                    }
                    $wpc_pu105 = parse_url((string) $wpc_u105);
                    $wpc_ph105 = strtolower((string) (isset($wpc_pu105['host']) ? $wpc_pu105['host'] : ''));
                    $wpc_pp105 = trim((string) (isset($wpc_pu105['path']) ? $wpc_pu105['path'] : ''), '/');
                    if ($wpc_ph105 !== '' && $wpc_pp105 !== '') {
                        $wpc_alt105 = (strpos($wpc_ph105, 'www.') === 0) ? substr($wpc_ph105, 4) : ('www.' . $wpc_ph105);
                        $wpc_prefixes105[$wpc_ph105 . '/' . $wpc_pp105]  = 1;
                        $wpc_prefixes105[$wpc_alt105 . '/' . $wpc_pp105] = 1;
                    } elseif ($wpc_ph105 !== '') {
                        $wpc_root_hosts105[$wpc_ph105] = 1;
                    }
                }
                // v7.10.670 — ALWAYS the device-clearing path. The old empty($wpc_tags105) branch
                // fell back to purgeFilesAsync (exact-URL files API) — a NO-OP on
                // cache_by_device_type variants AND blind to the tagless static mirror — and it fired
                // whenever wpc_cf_url_tag() (warm.php) was not loaded, so purge safety was
                // load-order-dependent. tag + prefix below both evict device variants + the mirror on
                // every CF plan, and each self-guards on an empty set, so run them unconditionally.
                {
                    foreach (array_chunk(array_keys($wpc_tags105), 100) as $chunk) {
                        $res = $sdk->purgeByTags($cf['zone'], $chunk);
                        $hit = !is_wp_error($res) && !empty($res['success']);
                        // v7.10.665 — one retry for a TRANSIENT failure, but NEVER on a 429: an
                        // immediate re-fire against a rate-limited bucket only deepens the breach.
                        if (!$hit && !(is_wp_error($res) && $res->get_error_code() === 'cloudflare_rate_limited')) {
                            $res = $sdk->purgeByTags($cf['zone'], $chunk);
                            $hit = !is_wp_error($res) && !empty($res['success']);
                        }
                        if (!$hit) {
                            $ok  = false;
                            $err = is_wp_error($res) ? $res->get_error_message()
                                : (is_array($res) && !empty($res['errors'][0]['message']) ? $res['errors'][0]['message'] : 'unknown');
                            $plog   = get_option('wpc_purge_debug_log', []);
                            $plog[] = date('Y-m-d H:i:s') . ' | CF tag-purge FAIL (' . count($chunk) . ' tags): ' . substr((string) $err, 0, 120);
                            update_option('wpc_purge_debug_log', array_slice($plog, -20), false);
                        }
                    }

                    foreach (array_chunk(array_keys($wpc_prefixes105), 30) as $chunk) { // v7.10.667 CF prefix cap = 30/request
                        $res = $sdk->purgeByPrefixes($cf['zone'], $chunk);
                        if (is_wp_error($res) || empty($res['success'])) {
                            $wpc_perr105 = is_wp_error($res) ? $res->get_error_message()
                                : (is_array($res) && !empty($res['errors'][0]['message']) ? $res['errors'][0]['message'] : 'unknown');
                            $plog   = get_option('wpc_purge_debug_log', []);
                            $plog[] = date('Y-m-d H:i:s') . ' | CF prefix-belt skip: ' . substr((string) $wpc_perr105, 0, 100);
                            update_option('wpc_purge_debug_log', array_slice($plog, -20), false);
                            break;
                        }
                    }


                    // v7.10.604 — A HOMEPAGE PURGE MUST NOT WIPE THE ZONE. The homepage has no
                    // path, so it cannot be expressed as a prefix and fell through to
                    // purgeByHosts — purging BOTH hostnames entirely to invalidate one page.
                    // The homepage is also our most-purged URL, so with the 10-minute lock this
                    // was up to 6 whole-site edge wipes an hour: 438 cf-purge in 66.7h on
                    // wpcompress.com, and an edge that can never accumulate hits (HIT 0.104s vs
                    // MISS 1.024s). The tag purge above is already EXACT for that URL.
                    // Escalate on OUTCOME, not on capability: only when the exact purge failed,
                    // or during the untagged-serve transition where old untagged copies really
                    // can still be at the edge.
                    $wpc_esc604 = (!$ok || $wpc_reset105 !== '');
                    if (!$wpc_esc604 && function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('cf-roothost-skipped', '', '', ['n' => count($wpc_root_hosts105)]);
                    }
                    if (!empty($wpc_root_hosts105) && $wpc_esc604
                        && apply_filters('wpc_cf_roothost_escalate', true)
                        && self::cfUntaggedServesPossible()
                        && method_exists($sdk, 'purgeByHosts') && !get_transient('wpc_cf_roothost_lock')) {
                        set_transient('wpc_cf_roothost_lock', 1, 10 * MINUTE_IN_SECONDS);
                        $wpc_rh105 = [];
                        foreach (array_keys($wpc_root_hosts105) as $wpc_rhh105) {
                            $wpc_rh105[$wpc_rhh105] = 1;
                            $wpc_rh105[(strpos($wpc_rhh105, 'www.') === 0) ? substr($wpc_rhh105, 4) : ('www.' . $wpc_rhh105)] = 1;
                        }
                        $sdk->purgeByHosts($cf['zone'], array_keys($wpc_rh105));
                    }
                }
                if ($wpc_reset105 !== '') {
                    $plog   = get_option('wpc_purge_debug_log', []);
                    $plog[] = date('Y-m-d H:i:s') . ' | CF tag-transition ' . $wpc_reset105;
                    update_option('wpc_purge_debug_log', array_slice($plog, -20), false);
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('cf-purge', '', (string) $urls[0], ['n' => count($urls), 'ok' => $ok ? 1 : 0]);
                }
                return $ok;
            } catch (\Throwable $e) {
                // Edge purge is best-effort; a CF/SDK hiccup must never break the caller.
                return false;
            }
        };

        if ($inline) {
            return $send();
        }
        register_shutdown_function(function () use ($send) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            $send();
        });
    }


    public static function cfTagResetOnce($sdk, $zone)
    {
        if (get_option('wpc_cf_tagpurge_reset') === 'v1' || get_transient('wpc_cf_tagreset_backoff')) {
            return '';
        }
        // Durable fail-state: non-Enterprise zones fail purgeByHosts forever, so the success
        // latch never sets — without this, every purge event re-pays a failing CF call
        $wpc_trf = (int) get_option('wpc_cf_tagreset_fail_at');
        if ($wpc_trf && (time() - $wpc_trf) < DAY_IN_SECONDS) {
            return '';
        }
        $h = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if ($h === '' || !method_exists($sdk, 'purgeByHosts')) {
            return '';
        }
        // v7.10.523 — SCOPE THE HOST FALLBACK. CF's purgeByHosts has no content-type filter,
        // so on a site whose images are served from the PAGE host this evicts every image too.
        // The CDN team's read: that is not merely wasted bandwidth — each image drops to cold
        // and re-runs the full relay + encode path, i.e. a self-inflicted origin spike at
        // exactly the moment the site is being purged. A host-wide purge is the single most
        // expensive operation in the system, so it only runs when images demonstrably live
        // somewhere else, and never more than once an hour.
        $wpc_imgh523 = strtolower(trim((string) get_option('ic_custom_cname')));
        if ($wpc_imgh523 === '') {
            $wpc_imgh523 = strtolower(trim((string) get_option('ic_cdn_zone_name')));
        }
        $wpc_offhost523 = ($wpc_imgh523 !== '' && strpos($wpc_imgh523, $h) === false);
        if (!$wpc_offhost523 && !apply_filters('wpc_cf_host_purge_when_images_local', false)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('cf-host-purge-skipped', '', '', ['why' => 'images-on-page-host', 'host' => $h]);
            }
            return 'host-reset-skipped';
        }
        if (get_transient('wpc_cf_hostpurge_rl523')) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('cf-host-purge-ratelimited', '', '', ['host' => $h]);
            }
            return 'host-reset-ratelimited';
        }
        set_transient('wpc_cf_hostpurge_rl523', 1, (int) apply_filters('wpc_cf_host_purge_min_interval', HOUR_IN_SECONDS));
        $alt = (strpos($h, 'www.') === 0) ? substr($h, 4) : ('www.' . $h);
        $res = $sdk->purgeByHosts($zone, [$h, $alt]);
        if (!is_wp_error($res) && !empty($res['success'])) {
            update_option('wpc_cf_tagpurge_reset', 'v1', false);
            delete_option('wpc_cf_tagreset_fail_at');
            return 'host-reset';
        }
        update_option('wpc_cf_tagreset_fail_at', time(), false);
        set_transient('wpc_cf_tagreset_backoff', 1, HOUR_IN_SECONDS);
        return 'host-reset-fail';
    }


    public static function cfUntaggedServesPossible()
    {
        // Breeze + Cache Enabler serve via their own drop-ins with their own headers (no

        // Doctor EVICTED while the button left an untagged homepage fill standing).
        $risk = defined('WP_ROCKET_VERSION') || defined('W3TC') || defined('WPCACHEHOME')
            || defined('WPFC_MAIN_PATH') || defined('BREEZE_VERSION') || defined('CE_VERSION')
            // Hummingbird page cache serves from its own drop-in with its own headers and was
            // missing here, so a CF tag-purge silently missed those copies on every WPHB site.
            || defined('WPHB_VERSION');
        if (!$risk) {
            $settings = get_option(WPS_IC_SETTINGS);
            // Manual static-serve OR the TTFB auto-arm (v7.10.357): both serve zero-PHP
            // mirror HTML that carries no Cache-Tag header, so a CF tag-purge misses those
            // copies — the broad host-purge fallback must fire, or stale HTML pins at edge.
            $risk = (is_array($settings) && !empty($settings['static-serve']) && (string) $settings['static-serve'] === '1')
                || get_option('wpc_ttfb_ss_auto') === '1';
        }
        return (bool) apply_filters('wpc_cf_untagged_serves_possible', $risk);
    }


    public static function cfPurgeAllHtml($inline = false, $forceHosts = false)
    {
        if (function_exists('wpc_purge_request_allowed') && !wpc_purge_request_allowed('all-html')) {
            if (function_exists('wpc_purge_gate_log604')) { wpc_purge_gate_log604('all-html'); }
            return null;
        }
        $cf = get_option(WPS_IC_CF);
        if (empty($cf['token']) || empty($cf['zone'])) {
            return null;
        }
        // PER-REQUEST DEDUPE — a Purge-HTML press reaches here twice (purgeAll's integration
        // fan-out + the handler's direct leg): one wpc-html tag purge covers both. The inline


        static $wpc_state105 = ''; // '' | 'deferred' | 'sent'
        // forceHosts is STICKY across same-request calls (captured by-ref into the deferred
        // $send): a later forceHosts=true (e.g. static-serve teardown) must upgrade an
        // already-registered purge whose first call had forceHosts=false — else the +hosts
        // widening the teardown needs is lost to the per-request dedupe. (v7.10.357)
        static $wpc_fh105 = false;
        if ($forceHosts) { $wpc_fh105 = true; }
        if ($inline && $wpc_state105 === 'sent') {
            return 'deduped';
        }
        if (!$inline && $wpc_state105 !== '') {
            return true;
        }
        $wpc_src105 = self::wpc_purge_src();
        $wpc_uri105 = isset($_SERVER['REQUEST_URI']) ? substr((string) $_SERVER['REQUEST_URI'], 0, 60) : '';
        $send = function () use ($cf, &$wpc_fh105, $wpc_src105, $wpc_uri105) {
            try {
                if (!class_exists('WPC_CloudflareAPI')) {
                    @include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
                }
                if (!class_exists('WPC_CloudflareAPI')) {
                    return false;
                }
                $sdk   = new WPC_CloudflareAPI($cf['token']);
                $reset = self::cfTagResetOnce($sdk, $cf['zone']);
                $res   = method_exists($sdk, 'purgeByTags') ? $sdk->purgeByTags($cf['zone'], ['wpc-html']) : null;
                $ok    = !is_wp_error($res) && !empty($res['success']);
                // v7.10.665 — retry a transient failure once, but not a 429 (would deepen the breach).
                if (!$ok && $res !== null && !(is_wp_error($res) && $res->get_error_code() === 'cloudflare_rate_limited')) {
                    $res = $sdk->purgeByTags($cf['zone'], ['wpc-html']);
                    $ok  = !is_wp_error($res) && !empty($res['success']);
                }
                $method  = 'tag';
                $wpc_h   = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
                $wpc_alt = (strpos($wpc_h, 'www.') === 0) ? substr($wpc_h, 4) : ('www.' . $wpc_h);
                if (!$ok && method_exists($sdk, 'purgeByHosts')) {
                    $r2  = $sdk->purgeByHosts($cf['zone'], [$wpc_h, $wpc_alt]);
                    $ok  = !is_wp_error($r2) && !empty($r2['success']);
                    $method = 'hosts-fallback';
                } elseif ($ok && ($wpc_fh105 || self::cfUntaggedServesPossible()) && method_exists($sdk, 'purgeByHosts')) {


                    $r3 = $sdk->purgeByHosts($cf['zone'], [$wpc_h, $wpc_alt]);
                    if (!is_wp_error($r3) && !empty($r3['success'])) {
                        $method .= '+hosts';
                    } else {
                        $method .= '+hosts-FAIL';
                    }
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('cf-purge-allhtml', '', '', ['ok' => $ok ? 1 : 0, 'method' => $method, 'reset' => $reset, 'src' => $wpc_src105, 'uri' => $wpc_uri105]);
                }
                // A successful edge purge IS the crown proof: long edge TTL is safe exactly
                // because purge-on-land works — stamp the verification from the live receipt.
                if ($ok) {
                    update_option('wpc_cf_purge_verified', ['t' => time(), 'm' => $method], false);
                }
                return $ok ? ($method . ($reset !== '' ? '+' . $reset : '')) : false;
            } catch (\Throwable $e) {
                return false;
            }
        };
        if ($inline) {
            $wpc_state105 = 'sent';
            return $send();
        }
        $wpc_state105 = 'deferred';
        $wpc_state_ref = &$wpc_state105;
        register_shutdown_function(function () use ($send, &$wpc_state_ref) {
            if ($wpc_state_ref === 'sent') {
                return;
            }
            $wpc_state_ref = 'sent';
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            $send();
        });
        return true;
    }


    public static function wpcHtmlUrlList($cap = 0)
    {
        $cap  = $cap > 0 ? (int) $cap : (int) apply_filters('wpc_cf_html_purge_max', 300);
        $urls = [];
        $home = rtrim((string) home_url('/'), '/');
        if ($home !== '') {
            $urls[$home . '/'] = 1;
            $urls[$home]       = 1;
        }
        $trees = [];
        if (defined('WPS_IC_CACHE'))    { $trees[] = rtrim(WPS_IC_CACHE, '/') . '/'; }
        if (defined('WPS_IC_CRITICAL')) { $trees[] = rtrim(WPS_IC_CRITICAL, '/') . '/'; }
        $canSanitize = class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'sanitizeSameHostUrl');
        $dropped = 0;
        foreach ($trees as $tree) {
            foreach ((array) @glob($tree . '*/url.txt') as $f) {
                if (count($urls) >= $cap) { $dropped++; continue; }
                $raw = trim((string) @file_get_contents($f));
                if ($raw === '') { continue; }
                $clean = $canSanitize ? (string) wps_ic_url_key::sanitizeSameHostUrl($raw) : '';
                if ($clean !== '') {
                    $urls[$clean] = 1;
                }
            }
        }
        if ($dropped > 0 && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('cf-scope-capped', '', '', ['kept' => count($urls), 'dropped' => $dropped]);
        }
        return array_keys($urls);
    }

    public static function purgeCDNUpdate()
    {
        if (self::wpc_hash_maint_defer922()) {
            return;
        }

        if (function_exists('wpc_update_window_open')) {
            wpc_update_window_open();
        }

        // Add User Capabilities
        $users = new wps_ic_users();
        $cacheLogic = new wps_ic_cache();
        $cache = new wps_ic_cache_integrations();

        $oldOptions= $options = get_option(WPS_IC_OPTIONS);
        if (!is_array($options)) $oldOptions = $options = [];

        $cacheLogic->purgeObjectCache();


        // Full purge runs OFF this request via the deferred event; scheduling it HERE covers
        // background auto-updates (the admin_init one-shot never fires for those) and the
        // update-window gate that dead-armed the old inline purgeAll. spawn closes cron lag.
        if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_v2_postupdate_purge')) {
            wp_schedule_single_event(time() + 5, 'wpc_v2_postupdate_purge');
        }
        if (function_exists('spawn_cron')) {
            wpc_spawn_cron();
        }
        // No cron reliance on the upgrader path either — detach-and-purge at shutdown
        if (function_exists('wpc_v2_postupdate_purge_shutdown')) {
            add_action('shutdown', 'wpc_v2_postupdate_purge_shutdown', PHP_INT_MAX);
        }


        $cacheLogic::preloadPage(0); // Purge & Preload

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
            $log->logCachePurging($oldOptions, $options, 'purgeCdnUpdate');
        }

        $options['updated_hash'] = time();
        update_option(WPS_IC_OPTIONS, $options);

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');


        // (dead update-window-gated rocket_clean_domain removed — Rocket purge now rides
        // the deferred wpc_v2_postupdate_purge event scheduled above)

        // Cheap server-flag purges fire on EVERY update path — the update-window gate
        // had orphaned these entirely
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }
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

    // v7.10.922 — MAINTENANCE-WINDOW HASH GUARD (ridgeway.church, Bricks + custom CDN domain).
    // upgrader_post_install fires while WP's .maintenance file is ACTIVE. Bumping css_hash
    // then changes every ?icv= asset URL, and the CDN zone mints the new keys by pulling
    // origin — which is serving the maintenance page. The zone caches that response under
    // the .css key with max-age=1y immutable: broken CSS pinned until the NEXT hash bump
    // (why the Advanced Cache toggle "fixed" it and cache purges never did). Never bump the
    // hash during maintenance — defer, re-check, bump when origin is healthy.
    public static function wpc_hash_maint_defer922()
    {
        if (!@file_exists(ABSPATH . '.maintenance')
            || (function_exists('apply_filters') && !apply_filters('wpc_hash_maintenance_guard', true))) {
            delete_option('wpc_hash_maint_tries922');
            return false;
        }
        $tries = (int) get_option('wpc_hash_maint_tries922', 0);
        if ($tries >= 5) {
            delete_option('wpc_hash_maint_tries922');
            return false;
        }
        update_option('wpc_hash_maint_tries922', $tries + 1, false);
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_update_hash_retry922')) {
            wp_schedule_single_event(time() + 180, 'wpc_update_hash_retry922');
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('hash-bump-deferred-maintenance', '', '', ['try' => $tries + 1]);
        }
        return true;
    }

    public static function updateCSSHash($post_id = 0)
    {
        if (self::wpc_hash_maint_defer922()) {
            return;
        }
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

            if (class_exists('wps_ic_log')) {
                $log = new wps_ic_log();
                $log->logCachePurging($oldOptions, $options, 'updateCSSHash-MU');
            }

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

            if (class_exists('wps_ic_log')) {
                $log = new wps_ic_log();
                $log->logCachePurging($oldOptions, $options, 'updateCSSHash');
            }

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
                @rmdir($folderPath);
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
                update_option('wps_ic_purge_rules', self::$purge_rules, false);
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

            if (class_exists('wps_ic_log')) {
                $log = new wps_ic_log();
                $log->logCachePurging($oldOptions, $options, 'resetHashes');
            }

            update_option(WPS_IC_OPTIONS, $options);
            restore_current_blog();
        } else {
            $oldOptions = $options = get_option(WPS_IC_OPTIONS);

            $options['css_hash'] = $CSSHash;
            $options['js_hash'] = $JSHash;

            if (!class_exists('wps_ic_log')) {
                include_once WPS_IC_DIR . 'classes/log.class.php';
            }

            if (class_exists('wps_ic_log')) {
                $log = new wps_ic_log();
                $log->logCachePurging($oldOptions, $options, 'resetHashes');
            }

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

    // v7.10.920 — SAVE-PATH PURGE DEFERRAL (Elementor 5-9s publishes, double-click Publish).
    // save_post fired two synchronous wipes and elementor/document/after_save a third plus a
    // BLOCKING edge-purge HTTP call, all inside the save request: the customer's telemetry
    // showed admin work done at 588ms and 4.7s more before the response. All save-path purge
    // work now queues per-request (three hooks on one save dedupe to ONE wipe set) and runs
    // at shutdown AFTER fastcgi_finish_request releases the response. Revisions never queue
    // (save_post fires for them; wiping on a revision was pure waste).
    public static function wpc_save_purge_defer920($post_id)
    {
        if (!is_numeric($post_id)) {
            return;
        }
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }
        if (function_exists('wp_is_post_revision') && wp_is_post_revision($post_id)) {
            return;
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_save_purge_defer', true)) {
            self::removeHtmlCacheFiles($post_id);
            self::removeCriticalFiles($post_id);
            $wpc_u920 = get_permalink($post_id);
            if (!empty($wpc_u920)) {
                self::purgeEdgeHtmlUrls([$wpc_u920]);
            }
            return;
        }
        self::wpc_save_purge_queue920($post_id);
    }

    public static function wpc_save_purge_queue920($post_id)
    {
        if (!isset($GLOBALS['wpc_savepurge920'])) {
            $GLOBALS['wpc_savepurge920'] = [];
            if (function_exists('add_action')) {
                add_action('shutdown', ['wps_ic_cache', 'wpc_save_purge_run920'], PHP_INT_MAX - 2);
            }
        }
        $GLOBALS['wpc_savepurge920'][(int) $post_id] = 1;
    }

    public static function wpc_save_purge_run920()
    {
        $wpc_ids920 = array_keys((array) ($GLOBALS['wpc_savepurge920'] ?? []));
        if (empty($wpc_ids920)) {
            return;
        }
        $GLOBALS['wpc_savepurge920'] = [];
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        if (function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }
        $wpc_urls920 = [];
        foreach ($wpc_ids920 as $wpc_pid920) {
            if ($wpc_pid920 === 0) {
                self::removeHtmlCacheFiles('all');
                self::removeCriticalFiles('all');
                $wpc_urls920[] = home_url('/');
                continue;
            }
            self::removeHtmlCacheFiles($wpc_pid920);
            self::removeCriticalFiles($wpc_pid920);
            $wpc_pu920 = get_permalink($wpc_pid920);
            if (!empty($wpc_pu920)) {
                $wpc_urls920[] = $wpc_pu920;
            }
        }
        if (!empty($wpc_urls920)) {
            self::purgeEdgeHtmlUrls(array_values(array_unique($wpc_urls920)));
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('save-purge-deferred', '', '', ['n' => count($wpc_ids920)]);
        }
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

        if (class_exists('wps_ic_log')) {
            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'purgeCDN');
        }

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