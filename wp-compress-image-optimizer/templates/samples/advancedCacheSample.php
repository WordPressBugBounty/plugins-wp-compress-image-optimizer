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
foreach($_COOKIE as $key => $value) {
  if (strpos($key, 'wordpress_logged_in_') === 0) {
    return; // Don't cache for logged-in users
  }
}

// Don't cache for POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    return;
}

// Don't cache if critical combine or cache disable headers are present
if (isset($_SERVER['HTTP_CRITICALCOMBINE']) || isset($_SERVER['HTTP_DISABLEWPC'])) {
    return;
}

// Don't cache if DONOTCACHEPAGE constant is defined
if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE){
	return;
}

// Check Cache-Control headers
//if (isset($_SERVER['HTTP_CACHE_CONTROL'])) {
//    $cacheControl = strtolower($_SERVER['HTTP_CACHE_CONTROL']);
//
//    // Skip caching if no-cache, no-store, or private directives are present
//    if (strpos($cacheControl, 'no-cache') !== false ||
//        strpos($cacheControl, 'no-store') !== false ||
//        strpos($cacheControl, 'private') !== false) {
//        #return;
//    }
//}


$prefix = '';
$cache = new wps_advancedCache();
$mobile = $cache->is_mobile();

if ($mobile) $prefix = 'mobile';

if (!$cache->byPass() && $cache->cacheExists($prefix)) {
  $isCacheExpired = $cache->cacheExpired();

  // Not required as get cache sorts this
  $isCacheValid = $cache->cacheValid();

  if (!$isCacheExpired && $isCacheValid) {
    $cache->getCache($prefix);
    die();
  }
}