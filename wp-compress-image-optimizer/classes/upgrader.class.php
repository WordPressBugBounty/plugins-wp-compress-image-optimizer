<?php


class wps_ic_upgrader extends wps_ic
{
    public static $options;

    public function __construct()
    {
        if (!$this->is_latest() || !empty($_GET['force_update'])) {


            // Skipped when ?force_update is set (manual override).
            if (empty($_GET['force_update'])) {
                if (get_transient('wpc_legacy_upgrade_lock')) {
                    return;
                }
                set_transient('wpc_legacy_upgrade_lock', 1, 5 * MINUTE_IN_SECONDS);

                $wpc_tries_key = 'wpc_legacy_upgrade_tries_' . md5((string) parent::$version);
                $wpc_tries = (int) get_option($wpc_tries_key, 0);
                if ($wpc_tries >= 3) {
                    // v7.10.717 — giving up on the work must not leave the edge serving HTML
                    // rendered by the old version until TTL: the edge purge is the one piece
                    // that still matters, is fully try-caught, and passes the admission gate
                    // via the same version-change exemption the work lane uses.
                    foreach (['wpc_purge_allow_foreign_ajax', 'wpc_purge_allow_heartbeat', 'wpc_purge_allow_low_value'] as $gate_filter) {
                        add_filter($gate_filter, '__return_true');
                    }
                    try {
                        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                            wps_ic_cache::cfPurgeAllHtml(true, true);
                        }
                    } catch (\Throwable $e) {
                    } finally {
                        foreach (['wpc_purge_allow_foreign_ajax', 'wpc_purge_allow_heartbeat', 'wpc_purge_allow_low_value'] as $gate_filter) {
                            remove_filter($gate_filter, '__return_true');
                        }
                    }
                    update_option('wpc_version', parent::$version);
                    delete_option($wpc_tries_key);
                    return;
                }
                update_option($wpc_tries_key, $wpc_tries + 1, false);
            }

            // Operator-only: anonymous ?force_update ran purge fan-out + opcache_reset
            // per request — an unauthenticated pool-wide DoS lever. Unauthorized
            // force_update RETURNS (it skipped the lock/tries above, so falling
            // through would run the deferred work untracked — the same DoS detached).
            if (!empty($_GET['force_update'])) {
                if ((function_exists('current_user_can') && current_user_can('manage_options'))
                    || (defined('WPC_PERF_DEBUG_TOKEN') && isset($_GET['t'])
                        && hash_equals((string) WPC_PERF_DEBUG_TOKEN, (string) $_GET['t']))) {
                    $this->run_upgrade_work(); // explicit manual run stays synchronous
                }
                return;
            }
            // The update purge fan-out (CF + Varnish + integrations + store walk) taxed
            // the TRIGGERING request 15-60s — and that request is usually a visitor's.
            // With a detach available the work runs post-response on this worker; WITHOUT
            // one (mod_php — staging receipt: shutdown ran inline, client waited 20-60s)
            // it rides an immediate cron event instead. Lock/tries above are already set
            // inline, so concurrent requests during the deferral never re-enter.
            if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) {
                register_shutdown_function(function () {
                    wpc_finish_request();
                    @set_time_limit(180);
                    try {
                        $this->run_upgrade_work();
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

    public function run_upgrade_work()
    {
        // v7.10.717 — the admission gate refuses purges whose deciding request is foreign
        // (heartbeat, third-party ajax, low-value). Correct everywhere EXCEPT here: a version
        // change invalidated every cached page as a first-party fact, and the request that
        // happens to carry the deferred work is just the messenger. Receipted on the .716
        // install: the delta tripped on a pys ajax request, the gate refused cfPurgeAllHtml,
        // and the edge served stale mints until a manual purge. Exemption is scoped to this
        // lane only — the lane fires once per real upgrade behind the lock and tries cap.
        $allow_callback = '__return_true';
        foreach (['wpc_purge_allow_foreign_ajax', 'wpc_purge_allow_heartbeat', 'wpc_purge_allow_low_value'] as $gate_filter) {
            add_filter($gate_filter, $allow_callback);
        }
        try {
            $this->run_upgrade_work_inner();
        } finally {
            foreach (['wpc_purge_allow_foreign_ajax', 'wpc_purge_allow_heartbeat', 'wpc_purge_allow_low_value'] as $gate_filter) {
                remove_filter($gate_filter, $allow_callback);
            }
        }
    }

    public function run_upgrade_work_inner()
    {
        // New plugin files are already on disk when the version delta trips — without this,
        // hosts with lazy opcache serve MIXED old/new bytecode until FPM restarts (liam:
        // three installs invisible). Shimmed no-op when opcache is absent (wp-compress.php:28).
        wpc_opcache_refresh('upgrade');

        self::$options = get_option(WPS_IC_OPTIONS);

        if (file_exists(WPS_IC_LOG . 'local_script_decode.txt')) {
            unlink(WPS_IC_LOG . 'local_script_decode.txt');
        }

        if (file_exists(WPS_IC_LOG . 'local_script_encode_2.txt')) {
            unlink(WPS_IC_LOG . 'local_script_encode_2.txt');
        }

        // Purge CDN
        $this->purge_cdn();

        // Upgrade CDN
        $this->update_to_latest();

        // v7.20.09 — env-fingerprint heal, fleet-wide on upgrade. env_changed suppresses the
        // whole CDN, but ensure_bg only acts when force/unprov/moved already hold — a
        // never-stamped fingerprint (site provisioned before the mechanism, or cloned)
        // suppresses FOREVER with a healthy zone. Arm the bounded chain: reset sets
        // force_provision, ensure_bg dispatches the deferred config sync, a 2xx re-stamps
        // the fingerprint and suppression clears. 12-attempt cap + 2-min pacing inside.
        if (function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed()
            && function_exists('wpc_v2_provision_reset_for_env') && function_exists('wpc_v2_provision_ensure_bg')
            && apply_filters('wpc_env_heal_armtrip', true)) {
            wpc_v2_provision_reset_for_env();
            wpc_v2_provision_ensure_bg('upgrade-env');
        }

        // Notify API
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

        // Setup URI
        $uri = WPS_IC_KEYSURL . '?action=upgrade_notify&apikey=' . $apikey . '&site_type=' . $site_type . '&domain=' . $siteurl . '&zone_name=' . $zone_name . '&plugin_version=' . self::$version . '&hash=' . md5(time()) . '&time_hash=' . time();


        $get = wp_remote_get($uri, ['timeout' => 3, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($get) == 200) {
            $body = wp_remote_retrieve_body($get);
            $body = json_decode($body);
            $zonename = $body->data;

            if ($body->success) {
                if (!empty($zonename) && $zonename != '') {
                    #update_option('ic_cdn_zone_name', $zonename);
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

        $options['lazy_hash'] = substr(md5(microtime(true) . 'lz'), 0, 10);
        if (class_exists('wps_ic_asset_version')) { wps_ic_asset_version::reset(); }

        if (!class_exists('wps_ic_log')) {
            include_once WPS_IC_DIR . 'classes/log.class.php';
        }

        if (class_exists('wps_ic_log')) {
            $log = new wps_ic_log();
            $log->logCachePurging($oldOptions, $options, 'update_to_latest');
        }

        update_option(WPS_IC_OPTIONS, $options);

        // The one purge this code runs for its own version change. It is what covers a change
        // the upgrader hook never saw: files copied into place by a host deploy or SFTP, and
        // every update from a release older than wpc_upgrader_purge, whose code is the one in
        // memory while the dashboard updates it. After a dashboard update from a newer release,
        // wpc_upgrader_purge purged hard seconds earlier and the 90 s coalescer turns this one
        // into a stale mark (purge-local-all-coalesced).
        if (class_exists('wps_ic_cache_integrations')) {
            wps_ic_cache_integrations::purgeAll(false, true, false, true, true, true);
        }

        // v7.10.503 — purgeAll() contains NO Cloudflare purge (67 lines, zero cf* calls), so the one
        // event that GUARANTEES every cached page is stale — a plugin version change — was the only
        // one that left the CF edge untouched. The site then runs new code while CF serves HTML
        // rendered by the old version until TTL. Receipted on wpcompress.com: the page emitted
        // delay-v3-loader-7.10.253.min.js while the plugin was current, and the filename is built
        // from WPC_PLUGIN_VERSION at render time, so it can only have come from .253-rendered HTML.
        // One wpc-html tag purge covers every cached page; same idiom as the other 8 call sites.
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
    }


    public function is_latest()
    {


        $running = (string) parent::$version;
        if (!preg_match('/^\d+\.\d+/', $running)) {
            return true;
        }

        $plugin_version = get_option('wpc_version');

        if (empty($plugin_version) || version_compare($plugin_version, $running, '<')) {
            // Must Upgrade
            return false;
        } else {
            return true;
        }
    }


    public function purge_cdn()
    {
        // The foreign page caches only. Our own page cache is purged once, by the purgeAll() in
        // update_to_latest(): this lane used to delete cache/wp-cio wholesale here as well, which
        // took the receipt journal with it on every update (rig, 2026-09-24: an update's
        // receipts were gone by the time anyone looked).
        self::purgeBreeze();


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
    }


    public static function purgeBreeze()
    {
        if (defined('BREEZE_VERSION')) {
            wpc_fs_remove_tree(breeze_get_cache_base_path(is_network_admin(), true), true);

            if (function_exists('wp_cache_flush')) {
                if (function_exists('wpc_object_cache_flush')) { wpc_object_cache_flush('breeze'); } else { @wp_cache_flush(); }
            }
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

// Cron lane for no-detach hosts: the constructor's lock transient makes the re-entered
// constructor a no-op, then the work runs here on a cron worker — never a visitor's.
add_action('wpc_legacy_upgrade_lane', function () {
    try {
        @set_time_limit(180);
        if (!class_exists('wps_ic_upgrader')) {
            return;
        }
        $upgrader = new wps_ic_upgrader();
        if ($upgrader->is_latest() && empty($_GET['force_update'])) {
            return; // a previous lane run already completed the work
        }
        $upgrader->run_upgrade_work();
    } catch (\Throwable $e) {
    }
});
