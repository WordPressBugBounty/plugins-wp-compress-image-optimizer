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
	      $this->cache->init();

        if (!empty($_GET['runPurge'])) {
            $this->purgeCache(0);
        }

	      add_action('transition_post_status', [$this->cache, 'purge_cache_on_post_changes'], 10, 3);
// Add action to handle the scheduled purge
	    add_action('wps_ic_scheduled_purge_hook', [$this,'purgeCache']);
	      $purge_rules = get_option('wps_ic_purge_rules');
				if ($purge_rules && !empty($purge_rules['scheduled'])){

					$time = $purge_rules['scheduled'];

					// Remove any existing scheduled events for this hook
					wp_clear_scheduled_hook('wps_ic_scheduled_purge_hook');

					$date = new DateTime('today ' . $time, wp_timezone());
					$timestamp = $date->getTimestamp();

					// Schedule new event with current time
					wp_schedule_event(
						$timestamp,
						'daily',
						'wps_ic_scheduled_purge_hook'
					);
				}

		    // Daily apikey check
		    add_action('wps_ic_check_key_hook', [$this, 'checkKey']);
		    if (!wp_next_scheduled('wps_ic_check_key_hook')) {
			    wp_schedule_event(
				    time(),
				    'daily',
				    'wps_ic_check_key_hook'
			    );
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

        $this->cache::removeHtmlCacheFiles('all'); // Purge & Preload
        $this->cache::preloadPage('all'); // Purge & Preload

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

		public function checkKey()
		{
			$options = get_option(WPS_IC_OPTIONS);

			$url = 'https://apiv3.wpcompress.com/api/site/credits';
			$call = wp_remote_get($url, [
				'timeout' => 30,
				'sslverify' => false,
				'user-agent' => WPS_IC_API_USERAGENT,
				'headers' => [
					'apikey' => $options['api_key'],
				]
			]);

			if (wp_remote_retrieve_response_code($call) == 401) {
				$cache = new wps_ic_cache_integrations();
				$cache->remove_key();
			}
		}

}

$WPSIC_CRON = new wps_ic_cron();