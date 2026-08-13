<?php

include __DIR__ . '/debug.php';
include_once __DIR__ . '/defines.php';


include_once __DIR__ . '/addons/cdn/delivery-resolver.php';


include_once __DIR__ . '/addons/cache/warm.php';
include_once __DIR__ . '/addons/rail/rail.php';
include_once __DIR__ . '/addons/vitals/vitals.php';

// DOING_CRON). Hard-gated; loading is behavior-free.
include_once __DIR__ . '/addons/cache/beacon.php';

include_once __DIR__ . '/addons/cache/link-preset.php';
// (inv2) invalidation engine — cron context too (land handlers run under DOING_CRON and
// call wpc_inv2_on_land). Default OFF; loading is behavior-free.
include_once __DIR__ . '/addons/cache/invalidation.php';


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
            $wpc_rp_key = isset($_GET['key']) ? (string) $_GET['key'] : '';
            $wpc_rp_opts = get_option(WPS_IC_OPTIONS);
            $wpc_rp_api = (is_array($wpc_rp_opts) && !empty($wpc_rp_opts['api_key'])) ? (string) $wpc_rp_opts['api_key'] : '';
            $wpc_rp_authed = ($wpc_rp_api !== '' && $wpc_rp_key !== '' && hash_equals($wpc_rp_api, $wpc_rp_key));
            $wpc_rp_last = (int) get_option('wpc_runpurge_at');
            // update_option returns false for same-value writes — a parallel burst in the
            // same second collapses to ONE winner (closes the read-then-write race)
            if ($wpc_rp_authed || ((time() - $wpc_rp_last) >= 300 && update_option('wpc_runpurge_at', time(), false))) {
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

            // Remove any existing scheduled events for this hook
            wp_clear_scheduled_hook('wps_ic_scheduled_purge_hook');

            $date = new DateTime('today ' . $time, wp_timezone());
            $timestamp = $date->getTimestamp();

            // Schedule new event with current time
            wp_schedule_event($timestamp, 'daily', 'wps_ic_scheduled_purge_hook');
        }

        // Daily apikey check
        add_action('wps_ic_check_key_hook', [$this, 'checkKey']);
        if (!wp_next_scheduled('wps_ic_check_key_hook')) {
            wp_schedule_event(time(), 'daily', 'wps_ic_check_key_hook');
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
        $wpc_s819 = get_option(WPS_IC_SETTINGS);
        if (!is_array($wpc_s819) || empty($wpc_s819['live-cdn']) || (string) $wpc_s819['live-cdn'] !== '1') { return; }
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) { return; }
        $wpc_ok819 = wpc_v2_asset_mime_probe_run();
        // THE FLIP IS AN EVENT, NOT A STATE: cached pages carry the working form and keep serving
        // it forever unless the flip purges them — the panel path purges on proof for exactly this
        // reason, and a proof that converges without purging converges for nobody.
        if ($wpc_ok819 && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
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
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
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


        $this->cache::removeHtmlCacheFiles('all'); // Purge & Preload
        #$this->cache::preloadPage('all'); // Purge & Preload
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
        $plugin_version = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '';
        $call = wp_remote_get($url, ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $apikey, 'plugin-version' => $plugin_version]]);

        if (wp_remote_retrieve_response_code($call) == 401) {
            $cache = new wps_ic_cache_integrations();
            $cache->remove_key();
        }
    }

}

$WPSIC_CRON = new wps_ic_cron();