<?php

include __DIR__ . '/debug.php';
include_once __DIR__ . '/defines.php';
// v7.01.22 — the delivery resolver registers the 'wpc_delivery_verify' cron handler at file
// scope, but core (its normal loader) doesn't load under DOING_CRON — so every scheduled
// re-verify was a silent no-op and a tile/Next-Gen save could leave the site on the legacy
// fallback until the next admin action. The resolver is standalone-safe (guards all
// cross-file calls, self-includes negotiated-delivery when it needs it).
include_once __DIR__ . '/addons/cdn/delivery-resolver.php';

// v7.03.34 — Define the WPC v2 5-minute cron interval under DOING_CRON. wp-compress-core (which normally
// registers it via v2-direct-entry.php / v2-pull-manifest.php) is gated OFF under DOING_CRON, so when
// wp-cron fired the recurring wpc_v2_pull_cron / wpc_v2_journal_drain_cron events it couldn't find the
// 'wpc_v2_5min' schedule → wp_reschedule_event() logged "invalid_schedule" and dropped the event on every
// run (the log spam). This file IS loaded under DOING_CRON, so defining the interval here lets the reschedule
// succeed and the events stay armed. Same idempotent shape + identical interval as the in-core registrations
// (both guard isset()), so it's a pure no-op when core already defined it. We deliberately do NOT load the
// pull/drain handlers here: that work is driven by the per-batch loopback + admin-init re-arm (cheaper than
// running it every 5 min under cron on every site), so this only defines the interval, adding zero work.
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

        // Hook purgeCache to plugins_loaded instead of calling directly
        if (!empty($_GET['runPurge'])) {
            add_action('plugins_loaded', [$this, 'purgeCache']);
        }

        add_action('transition_post_status', [$this->cache, 'purge_cache_on_post_changes'], 10, 3);
        // Add action to handle the scheduled purge
        add_action('wps_ic_scheduled_purge_hook', [$this, 'purgeCache']);
        // Add action to handle purge on post save
        add_action('save_post', [$this, 'purgeCache']);
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

        //Divi scheduled purge
        add_action('et_core_page_resource_auto_clear', [$this, 'purgeCache']);
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

        // Clear cache. // Was already done in purgeall() !!
//    if (function_exists('rocket_clean_domain')) {
//      rocket_clean_domain();
//    }

        // Lite Speed
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // HummingBird
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        // Was already done in purgeAll()!!!
//    $this->wpc_purgeCF(true);
//    sleep(6);

        $this->cache::removeHtmlCacheFiles('all'); // Purge & Preload
        #$this->cache::preloadPage('all'); // Purge & Preload
        $warmup_class = new wps_ic_preload_warmup();
        $warmup_class->cacheLocally('home');

        sleep(3);
        delete_transient('wps_ic_purging_cdn');
    }

    public function checkKey()
    {
        $options = get_option(WPS_IC_OPTIONS);

        $url = 'https://apiv3.wpcompress.com/api/site/credits';
        // (v7.03.120) FATAL FIX: use the WPC_PLUGIN_VERSION global (defined early in wp-compress.php,
        // class-independent) instead of wps_ic::$version. This runs via WP-Cron, where the wps_ic class
        // is not guaranteed loaded — which threw "Uncaught Error: Class \"wps_ic\" not found" here on
        // several sites. Also guard $options so a missing key is '' rather than an undefined-index notice.
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