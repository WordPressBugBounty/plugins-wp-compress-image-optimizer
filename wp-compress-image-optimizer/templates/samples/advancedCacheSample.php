<?php
defined('ABSPATH') || exit;
define('WP_COMPRESS_ADVANCED_CACHE', true);

$pluginExists = __DIR__ . '/plugins/wp-compress-image-optimizer/';
$pluginCachePath = __DIR__ . '/cache/wp-cio/';

if (version_compare(phpversion(), '7.2', '<')
  || !file_exists($pluginExists)
  || !file_exists($pluginCachePath)) {
  define('WP_COMPRESS_CACHE_PROBLEM', true);
  return;
}

if (!file_exists($pluginExists . 'addons/cache/advancedCache.php')) {
  return;
}

include_once $pluginExists . 'traits/url_key.php';
include_once $pluginExists . 'classes/config.class.php';
include_once $pluginExists . 'addons/cache/advancedCache.php';

$config = new wps_ic_config();
include_once $config->getConfigPath();
if (isset($_COOKIE[$wpcio_logged_in_cookie])) {
  return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	return;
}

if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE){
	return;
}

$prefix = '';
$cache = new wps_advancedCache();
$mobile = $cache->is_mobile();

if ($mobile) $prefix = 'mobile';

if ($cache->cacheExists($prefix)) {
  $isCacheExpired = $cache->cacheExpired();

  // Not required as get cache sorts this
  $isCacheValid = $cache->cacheValid();

  if (!$isCacheExpired && $isCacheValid) {
    $cache->getCache($prefix);
    die();
  }
}