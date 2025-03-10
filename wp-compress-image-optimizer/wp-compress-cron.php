<?php

include 'debug.php';
include_once 'defines.php';

class wps_ic_cron {

    public $cache;

    public function __construct()
    {
        include_once 'classes/cache-integrations.class.php';
        include_once 'classes/cache.class.php';
        include_once 'classes/requests.class.php';
        include_once 'classes/preload_warmup.class.php';
        include_once 'addons/cf-sdk/cf-sdk.php';
        include_once 'addons/cache/cacheHtml.php';
        include_once 'traits/url_key.php';
        $this->cache = new wps_ic_cache();

        if (!empty($_GET['runPurge'])) {
            $this->purgeCache(0);
        }

        add_action( 'publish_future_post', [$this, 'purgeCache'], 100, 1 );
    }


    public function purgeCache($post_id)
    {
        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options['api_key'])) {
            wp_send_json_error('API Key empty!');
        }

        delete_transient('wps_ic_css_cache');
        delete_option('wps_ic_modified_css_cache');
        delete_option('wps_ic_css_combined_cache');

        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll(false, true);

        // Todo: maybe remove?
        $cache::purgeCombinedFiles();

        set_transient('wps_ic_purging_cdn', 'true', 30);

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

        $this->wpc_purgeCF(true);
        sleep(6);

        $this->cache::removeHtmlCacheFiles(0); // Purge & Preload
        $this->cache::preloadPage(0); // Purge & Preload

        sleep(3);
        delete_transient('wps_ic_purging_cdn');
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

}

$WPSIC_CRON = new wps_ic_cron();