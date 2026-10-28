<?php

include __DIR__ . '/debug.php';
include_once __DIR__ . '/defines.php';

if (!function_exists('wpc_service_sslverify')) {
    function wpc_service_sslverify($url = '')
    {
        return false;
    }
}


include_once __DIR__ . '/addons/cdn/delivery-resolver.php';


include_once __DIR__ . '/addons/cache/warm.php';
include_once __DIR__ . '/addons/rail/rail.php';
include_once __DIR__ . '/addons/vitals/vitals.php';

// DOING_CRON). Hard-gated; loading is behavior-free.
include_once __DIR__ . '/addons/cache/beacon.php';

include_once __DIR__ . '/addons/cache/link-preset.php';

if (!function_exists('wpc_cron_lane_load_core')) {
    // The cron lane never loads the plugin core; an event that needs its classes loads the core's
    // declarations only (wps_ic_ajax extends wps_ic). The core's includes register hooks against
    // the plugin file (wp-compress.php defines it only on the lanes that load the core).
    function wpc_cron_lane_load_core()
    {
        if (class_exists('wps_ic', false)) {
            return;
        }
        if (!defined('WPC_DECLARATIONS_ONLY')) {
            define('WPC_DECLARATIONS_ONLY', true);
        }
        if (!defined('WPC_CC_PLUGIN_FILE')) {
            define('WPC_CC_PLUGIN_FILE', __DIR__ . '/wp-compress.php');
        }
        include_once __DIR__ . '/wp-compress-core.php';
    }
}

foreach ([
    'wpc_v2_pull_cron', 'wpc_szt_drain79', 'wpc_landed_purge_drain88', 'wpc_landed_pages_drain26', 'wpc_policy23_resync', 'wpc_policy23_poll', 'wpc_policy23_materialize',
    'wpc_v2_variant_forget23', 'wpc_twin_floor_run', 'wpc_variant_identity_sweep', 'wpc_variant_identity_purge', 'wpc_variant_identity_quarantine', 'wpc_v2_journal_drain_cron',
    'wpc_v2_deferred_config_sync', 'wpc_origin_reach_act', 'wpc_origin_reach_verify',
    'wpc_upgrade_remote_lane', 'wpc_ladder_gen_cron', 'wpc_regen_sweep12', 'wpc_regen_thumbs', 'wpc_sweep_stale_backfill_locks',
    'wpc_v2_clock_check', 'wpc_v2_signed_header_refresh',
    'wpc_v2_postupdate_purge', 'wpc_v2_cdn_liveness_cron', 'wpc_autopurge_sweep', 'wpc_natural_converge_hook', 'wpc_sd_restore_release', 'wpc_v2_master_protected_release',
] as $wpc_cron_lane_hook) {
    add_action($wpc_cron_lane_hook, 'wpc_cron_lane_load_core', 1);
}
unset($wpc_cron_lane_hook);

// The cron carriers of events the core schedules and attaches only on its own boot, which
// DOING_CRON never runs: each loads the declarations-only core, then runs the callback the core
// attaches.
add_action('wpc_quota_status_refresh', function () {
    wpc_cron_lane_load_core();
    if (class_exists('wps_ic', false)) {
        wps_ic::checkQuotaStatusRefresh();
    }
});

add_action('wpc_account_status_refresh', function () {
    wpc_cron_lane_load_core();
    if (function_exists('wpc_account_status_refresh_run')) {
        wpc_account_status_refresh_run();
    }
});

add_action('wpc_apiv3_reconnect', function () {
    wpc_cron_lane_load_core();
    if (function_exists('wpc_apiv3_reconnect_run')) {
        wpc_apiv3_reconnect_run();
    }
});

add_action('wpc_legacy_upgrade_lane', function () {
    wpc_cron_lane_load_core();
    class_exists('wps_ic_upgrader');
}, 1);

add_action('wpc_update_hash_retry922', function () {
    wpc_cron_lane_load_core();
    if (class_exists('wps_ic_cache', false)) {
        wps_ic_cache::updateCSSHash();
        wps_ic_cache::purgeCDNUpdate();
    }
});

add_action('wpc_tpl_purge_all312_ev', function () {
    wpc_cron_lane_load_core();
    if (class_exists('wps_ic_cache', false) && wps_ic_cache::purgeHooksArmed()) {
        wps_ic_cache::wpc_purge_all_on_template_change_event();
    }
});

add_action('wpc_reoptimize_edited_image_event', function ($attachment_id) {
    wpc_cron_lane_load_core();
    if (class_exists('wps_local_compress', false)) {
        (new wps_local_compress())->wpc_run_edited_reoptimize($attachment_id);
    }
}, 10, 1);

add_action('runCronPreload', function () {
    wpc_cron_lane_load_core();
    if (class_exists('wps_ic_preload_warmup', false) && wps_ic_preload_warmup::cronPreloadArmed()) {
        (new wps_ic_preload_warmup())->startOptimizationsCron();
    }
});

// The cron carrier of a v2 compress run (wps_ic_ajax::wpc_bulk_drain_cron_arm): one slice a minute
// while the run has work, so a started run finishes without an open admin tab when no loopback
// reaches the site (ticket 12006): the event is queued only while a bulk run is active.
add_action('wpc_bulk_drain_cron', function () {
    wpc_cron_lane_load_core();
    if (class_exists('wps_ic_ajax')) {
        wps_ic_ajax::wpc_bulk_drain_cron_run();
    }
});

// The cron carrier of the on-upload compress queue (wps_local_compress::queue_cron_arm): runs the
// worker in-process when the loopback did not start one.
add_action('wpc_compress_queue_cron', function () {
    wpc_cron_lane_load_core();
    if (class_exists('wps_local_compress')) {
        wps_local_compress::queue_cron_run();
    }
});

// The daily trim of the delay registry sidecars and the stored script scans
// (wps_ic_js_delay_v3::wpc_registry_sidecar_trim). The cron lane loads the delay engine's two
// class files for it, nothing else.
add_action('wpc_delay_sidecar_trim_hook', function () {
    if (!class_exists('wps_ic_js_delay_v2', false)) {
        include_once __DIR__ . '/classes/js_delay_v2.class.php';
    }
    if (!class_exists('wps_ic_js_delay_v3', false)) {
        include_once __DIR__ . '/classes/js_delay_v3.class.php';
    }
    if (class_exists('wps_ic_js_delay_v3', false)) {
        wps_ic_js_delay_v3::wpc_registry_sidecar_trim();
    }
});

// The daily sweep of the folders of signed-in visitors idle longer than a login lasts
// (wpc_user_folder_sweep, warm.php).
add_action('wpc_user_folder_sweep_hook', function () {
    if (function_exists('wpc_user_folder_sweep')) {
        wpc_user_folder_sweep();
    }
});

// The daily sweep of what visitors' reports changed site-wide (wpc_report_effects_sweep): the timer
// demote through its re-arm, and a reserve rule marked not-unique seven days after it was last seen.
add_action('wpc_report_effects_sweep_hook', function () {
    wpc_cron_lane_load_core();
    if (!class_exists('wps_ic_js_delay_v2', false)) {
        include_once __DIR__ . '/classes/js_delay_v2.class.php';
    }
    if (!class_exists('wps_ic_js_delay_v3', false)) {
        include_once __DIR__ . '/classes/js_delay_v3.class.php';
    }
    if (function_exists('wpc_report_effects_sweep')) {
        wpc_report_effects_sweep();
    }
});

add_action('wpc_psi_dispatch_due', function () {
    wpc_cron_lane_load_core();
    if (function_exists('wpc_psi_dispatch_due')) {
        wpc_psi_dispatch_due();
    }
});

add_action('wpc_psi_poll', function ($urlKey, $uuid) {
    wpc_cron_lane_load_core();
    if (function_exists('wpc_psi_poll_run')) {
        wpc_psi_poll_run($urlKey, $uuid);
    }
}, 10, 2);

add_action('wpc_hero_land_scan', function ($key) {
    wpc_cron_lane_load_core();
    if (class_exists('wps_ic_hero_land')) {
        wps_ic_hero_land::scan($key);
    }
}, 10, 1);

add_action('wpc_hero_land_tick', function ($id, $file, $phase) {
    wpc_cron_lane_load_core();
    if (class_exists('wps_ic_hero_land')) {
        wps_ic_hero_land::tick($id, $file, $phase);
    }
}, 10, 3);

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['wpc_v2_5min'])) {
        $schedules['wpc_v2_5min'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (WPC v2)'];
    }
    return $schedules;
});

class wps_ic_cron
{

    public $cache;

    public function __construct()
    {
        include_once __DIR__ . '/classes/cache-integrations.class.php';
        include_once __DIR__ . '/classes/cache.class.php';
        include_once __DIR__ . '/classes/requests.class.php';
        include_once __DIR__ . '/classes/preload_warmup.class.php';
        include_once __DIR__ . '/addons/cf-sdk/cf-sdk.php';
        include_once __DIR__ . '/addons/cache/cacheHtml.php';
        include_once __DIR__ . '/traits/url_key.php';

        $this->cache = new wps_ic_cache();
        $this->cache->init();

        // Hook purgeCache to plugins_loaded instead of calling directly.
        // Externally hittable (wp-cron.php?runPurge=1) — durable 5-min rate limit;
        // callers presenting the api key (&key=) bypass it
        if (!empty($_GET['runPurge'])) {
            $wpc_rp_authed = wpc_site_key_matches(wpc_get_string('key'));
            // v7.22.66 — the key is REQUIRED. The 300s anonymous path let anyone keep the
            // whole cache cold with one request every five minutes (proven on staging:
            // wpc_runpurge_at written, purgeAll scheduled).
            if ($wpc_rp_authed) {
                update_option('wpc_runpurge_at', time(), false);
                add_action('plugins_loaded', [$this, 'purgeCache']);
            }
        }

        add_action('transition_post_status', [$this->cache, 'purge_cache_on_post_changes'], 10, 3);
        // Add action to handle the scheduled purge
        add_action('wps_ic_scheduled_purge_hook', [$this, 'purgeCache']);
        // Add action to handle purge on post save — deferred to shutdown, after the response
        // has been flushed. This file loads under REST too, so a Gutenberg publish was paying
        // for purgeAll + a blocking home warm inside the save request (editor sat 5-9s; same
        // shape as the Elementor lanes .920 already deferred).
        add_action('save_post', [$this, 'purgeCacheDeferred']);
        $purge_rules = get_option('wps_ic_purge_rules');
        if ($purge_rules && !empty($purge_rules['scheduled'])) {

            $time = $purge_rules['scheduled'];

            // v7.21.345 — 'today HH:MM' is PAST for most of the day, and this block ran on
            // every cron boot: the unconditional clear+reschedule re-armed an already-due
            // event each time, so the "daily" purge fired on EVERY wp-cron tick (~60s) and
            // the whole HTML cache lived under a minute (falknerei 2s-TTFB receipt:
            // purge-local-all hook:wps_ic_scheduled_purge_hook each tick; homepage only
            // survived because warming re-wrote it). Schedule the NEXT future occurrence,
            // and leave a schedule alone when it already fires at the configured time.
            $date = new DateTime('today ' . $time, wp_timezone());
            $timestamp = $date->getTimestamp();
            if ($timestamp <= time()) {
                $timestamp += DAY_IN_SECONDS;
            }
            $nextRun = (int) wp_next_scheduled('wps_ic_scheduled_purge_hook');
            $keepSchedule = false;
            if ($nextRun > time()) {
                try {
                    $nextRunTime = (new DateTime('@' . $nextRun))->setTimezone(wp_timezone())->format('H:i');
                    $keepSchedule = ($nextRunTime === $date->format('H:i'));
                } catch (\Throwable $e) {
                }
            }
            if (!$keepSchedule) {
                wp_clear_scheduled_hook('wps_ic_scheduled_purge_hook');
                wp_schedule_event($timestamp, 'daily', 'wps_ic_scheduled_purge_hook');
            }
        }

        // Daily apikey check
        add_action('wps_ic_check_key_hook', [$this, 'checkKey']);
        if (!wp_next_scheduled('wps_ic_check_key_hook')) {
            wp_schedule_event(time(), 'daily', 'wps_ic_check_key_hook');
        }

        // Delay registry sidecars nobody can reference any more, and old stored script scans
        // (the handler is registered at file level above)
        if (!wp_next_scheduled('wpc_delay_sidecar_trim_hook')) {
            wp_schedule_event(time() + 600, 'daily', 'wpc_delay_sidecar_trim_hook');
        }

        if (!wp_next_scheduled('wpc_report_effects_sweep_hook')) {
            wp_schedule_event(time() + 1200, 'daily', 'wpc_report_effects_sweep_hook');
        }

        // Signed-in visitors' folders idle longer than a login lasts
        if (!wp_next_scheduled('wpc_user_folder_sweep_hook')) {
            wp_schedule_event(time() + 900, 'daily', 'wpc_user_folder_sweep_hook');
        }

        // Natural-assets convergence: the MIME proof's other arming paths are all opportunistic
        // (an admin visit, a cold cache-miss render) — on an edge-cached site with s-maxage in
        // days, neither may happen for weeks, so a zone the service enables for natural statics
        // never gets discovered and every asset stays in its working form. This tick guarantees
        // convergence within the hour, unconditionally of traffic shape.
        add_action('wpc_natural_converge_hook', [$this, 'naturalConverge']);
        if (!wp_next_scheduled('wpc_natural_converge_hook')) {
            wp_schedule_event(time(), 'hourly', 'wpc_natural_converge_hook');
        }

        //Divi scheduled purge
        add_action('et_core_page_resource_auto_clear', [$this, 'purgeCache']);
    }

    public function naturalConverge()
    {
        if (!apply_filters('wpc_natural_converge_cron', true)) { return; }
        if (!function_exists('wpc_v2_asset_mime_probe_run')) { return; }
        // Already proven: the render-path TTL re-prove owns freshness; this tick only exists to
        // turn an UNPROVEN install proven without waiting for a human or a cache miss.
        if ((string) get_option('wpc_v2_cf_asset_mime_ok', '') === '1') { return; }
        $settings = get_option(WPS_IC_SETTINGS);
        if (!is_array($settings) || empty($settings['live-cdn']) || (string) $settings['live-cdn'] !== '1') { return; }
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) { return; }
        $proven = wpc_v2_asset_mime_probe_run();
        // THE FLIP IS AN EVENT, NOT A STATE: cached pages carry the working form and keep serving
        // it forever unless the flip purges them — the panel path purges on proof for exactly this
        // reason, and a proof that converges without purging converges for nobody.
        if ($proven && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('natural-converged', '', '', ['src' => 'cron']);
            }
        }
    }

    public function wpc_purgeCF($return = false)
    {
        $cfSettings = get_option(WPS_IC_CF);

        $zone = $cfSettings['zone'];
        $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
        if ($cfapi) {
            $cfapi->purgeCache($zone);
        }

        if ($return) {
            return true;
        } else {
            wp_send_json_success();
        }
    }

    public function purgeCacheDeferred()
    {
        static $armed = false;
        if ($armed) {
            return;
        }
        $armed = true;
        if (!function_exists('apply_filters') || !apply_filters('wpc_save_purge_defer', true)) {
            $this->purgeCache();
            return;
        }
        $self = $this;
        add_action('shutdown', function () use ($self) {
            if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) {
                wpc_finish_request();
            }
            if (function_exists('ignore_user_abort')) {
                @ignore_user_abort(true);
            }
            if (function_exists('set_time_limit')) {
                @set_time_limit(60);
            }
            $self->purgeCache();
        }, PHP_INT_MAX - 1);
    }

    public function purgeCache()
    {
        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll(false, true, false, false);

        // Todo: maybe remove?
        $cache::purgeCombinedFiles();

        set_transient('wps_ic_purging_cdn', 'true', 30);


        // Lite Speed
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // HummingBird
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }


        $this->cache::removeHtmlCacheFiles('all', '', '', 'soft'); // Purge & Preload
        $warmup_class = new wps_ic_preload_warmup();
        $warmup_class->cacheLocally('home');

        // No in-request sleep on a save: the purging flag has its own TTL and the
        // CDN purge is fire-and-forget, so clearing it now costs the editor nothing.
        delete_transient('wps_ic_purging_cdn');
    }

    public function checkKey()
    {
        $options = get_option(WPS_IC_OPTIONS);

        $url = 'https://apiv3.wpcompress.com/api/site/credits';


        $apikey         = (is_array($options) && isset($options['api_key'])) ? $options['api_key'] : '';
        if ($apikey === '') {
            return;
        }
        $plugin_version = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '';
        $call = wp_remote_get($url, ['timeout' => 30, 'sslverify' => wpc_service_sslverify($url), 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $apikey, 'plugin-version' => $plugin_version]]);

        // A 401 is recorded, never acted on. apiv3 answers 401 when its agencySites lookup comes
        // back empty, so a database blip reads exactly like a deleted site; this check emptied the
        // key on one such answer and customers met a "site URL has changed" modal on sites nobody
        // touched (ticket 12217: amamiespresso.com, 717events.com). Same contract as
        // check_account_status since 7.10.411: revocation is the service refusing to serve.
        if (wp_remote_retrieve_response_code($call) == 401) {
            wpc_key_rejected_receipt('daily-key-check', $call);
        }
    }

}

$WPSIC_CRON = new wps_ic_cron();