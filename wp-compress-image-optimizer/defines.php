<?php

define('WPS_IC_MAXWIDTH', 3000);
define('WPS_IC_QUEUE_EXECUTION_TIME', 360);
define('WPS_IC_LOCAL_V', 4);
if (empty($_GET['min_debug'])) {
  define('WPS_IC_MIN', '.min'); // .min => script.min.js
} else {
  define('WPS_IC_MIN', ''); // .min => script.min.js
}

define('WPS_IC_CF', 'wps-ic-cf');
define('WPS_IC_GB', 1000000000);
define('WPC_IC_CACHE_EXPIRE', 86400); // 24 hours

// Local API
define('WPS_IC_LOCAL_API', 'https://frankfurt.zapwp.net/local/v3/index.php');
define('WPS_IC_API_USERAGENT', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36');

define('WPS_IC_APIURL', 'https://legacy-eu.wpcompress.com/');
define('WPS_IC_KEYSURL', 'https://keys.wpmediacompress.com/');

// Real URL
// Not required?
define('WPS_IC_CRITICAL_API_URL_PING', 'https://critical-api.wpcompress.com/post.php');

####define('WPS_IC_CRITICAL_API_URL', 'https://critical-api.wpcompress.com/penthouse.php');
define('WPS_IC_CRITICAL_API_URL', 'https://loadbalancer-critical.zapwp.net/ajax-call.php');
define('WPS_IC_CRITICAL_API_HOMEPAGE_URL', 'https://loadbalancer-critical.zapwp.net/pagespeed.php');
#define('WPS_IC_CRITICAL_API_URL', 'https://critical-ded4.wpcompress.com/pagespeed.php');

define('WPS_IC_CRITICAL_API_ASSETS_URL', 'https://loadbalancer-critical.zapwp.net/assets.php');
#define('WPS_IC_CRITICAL_API_ASSETS_URL', 'https://critical-ded4.wpcompress.com/assets.php');

define('WPS_IC_PRELOADER_API_URL', 'https://preloader.wpcompress.com/v2/index.php');
#define('WPS_IC_PRELOADER_API_URL', 'http://49.13.42.114/v2/index.php');

define('WPS_IC_IN_BULK', 'wps_ic_in_bulk');
define('WPS_IC_MU_SETTINGS', 'wps_ic_mu_settings');


// How many tests can fail before it's marked as failuire?
define('WPS_IC_TEST_FAILURES', 80);


define('WPS_IC_TESTS', 'wpc-tests');
define('WPS_IC_LITE_GPS', 'wps_ic_initial_gps');
define('WPS_IC_GUI', 'wps_ic_gui');
define('WPS_IC_SETTINGS', 'wps_ic_settings');
if (!defined('WPS_IC_CACHE')) {
	define('WPS_IC_CACHE', WP_CONTENT_DIR . '/cache/wp-cio/');
}

define('WPS_IC_CACHE_URL', WP_CONTENT_URL . '/cache/wp-cio/');

define('WPS_IC_PRESET', 'wps_ic_preset_setting');
define('WPS_IC_OPTIONS', 'wps_ic');
define('WPS_IC_OPTIONS_V2', 'wps_ic_options');

define('WPS_IC_BULK', 'wps_ic_bulk');

$plugin_dir = str_replace(site_url('/', 'https'), '', WP_PLUGIN_URL);
$plugin_dir = str_replace(site_url('/', 'http'), '', $plugin_dir);

define('WPS_IC_URI', plugin_dir_url(__FILE__));
define('WPS_IC_DIR', plugin_dir_path(__FILE__));
define('WPS_IC_ASSETS', WPS_IC_URI . 'assets');

// IP Whitelisting
define('WPC_API_WHITELIST', WPS_IC_DIR . 'whitelist-ip.txt');

define('WPS_IC_IMAGES', $plugin_dir . '/wp-compress-image-optimizer/assets/images');
define('WPS_IC_TEMPLATES', plugin_dir_path(__FILE__) . 'templates/');

define('WPS_IC_UPLOADS_DIR', WP_CONTENT_DIR . '/uploads');

define('WPS_IC_CRITICAL', WP_CONTENT_DIR . '/cache/critical/');
define('WPS_IC_CRITICAL_URL', WP_CONTENT_URL . '/cache/critical/');

define('WPS_IC_COMBINE', WP_CONTENT_DIR . '/cache/combine/');
define('WPS_IC_COMBINE_URL', WP_CONTENT_URL . '/cache/combine/');

define('WPS_IC_LOG', WPS_IC_DIR . 'logs/');
define('WPS_IC_LOG_URL', WPS_IC_URI . 'logs/');
define('WPC_WARMUP_LOG_SETTING', 'wps_ic_warmup_log');

if (!file_exists(WP_CONTENT_DIR . '/cache')) {
  mkdir(WP_CONTENT_DIR . '/cache');
}

if (!file_exists(WPS_IC_CACHE)) {
  mkdir(rtrim(WPS_IC_CACHE, '/'));
}

if (!file_exists(WPS_IC_CRITICAL)) {
  mkdir(rtrim(WPS_IC_CRITICAL, '/'));
}

if (!file_exists(WPS_IC_LOG)) {
  mkdir(rtrim(WPS_IC_LOG, '/'));
}

// Stats v2
define('WPS_IC_STATS_BULK_FILES', 'wps_ic_stats_bulk_files');
define('WPS_IC_STATS_BULK_TOTAL_FILES', 'wps_ic_stats_bulk_total_files');
define('WPS_IC_STATS_BULK_SAVINGS', 'wps_ic_stats_bulk_savings');
define('WPS_IC_STATS_BULK_AVG', 'wps_ic_stats_bulk_avg');
define('WPS_IC_STATS_FILES', 'wps_ic_files_processed');
define('WPS_IC_STATS_BYTES', 'wps_ic_bytes_saved');
define('WPS_IC_STATS_AVG_REDUCTION', 'wps_ic_avg_reduction');