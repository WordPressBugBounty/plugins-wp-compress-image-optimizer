<?php

defined('ABSPATH') || exit;
define('WP_COMPRESS_ADVANCED_CACHE', true);

#WPC_CACHE_DEVELOPER_MODE_START

#WPC_CACHE_DEVELOPER_MODE_END

#WPC_CACHE_LOGGED_IN_START
define('WPC_CACHE_LOGGED_IN', false);
#WPC_CACHE_LOGGED_IN_END

#WPC_CACHE_COOKIES_START
define('WPC_CACHE_COOKIES', false);
#WPC_CACHE_COOKIES_END

#WPC_EXCLUDE_COOKIES_START
define('WPC_EXCLUDE_COOKIES', false);
#WPC_EXCLUDE_COOKIES_END

#WPC_MANDATORY_COOKIES_START
define('WPC_MANDATORY_COOKIES', false);
#WPC_MANDATORY_COOKIES_END

#WPC_URL_EXCLUDES_START
define('WPC_URL_EXCLUDES', false);
#WPC_URL_EXCLUDES_END

#WPC_CACHE_EXCLUDES_START
define('WPC_CACHE_EXCLUDES', false);
#WPC_CACHE_EXCLUDES_END

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

//Not needed? Removed 2.6.2025
//$config = new wps_ic_config();
//include_once $config->getConfigPath();


foreach($_COOKIE as $key => $value) {
  if (strpos($key, 'wordpress_logged_in_') === 0 && !WPC_CACHE_LOGGED_IN) {
    return; // Don't cache for logged-in users
  }
}

// Check for excluded cookies
if (defined('WPC_EXCLUDE_COOKIES')) {
    if (WPC_EXCLUDE_COOKIES !== false && is_array(WPC_EXCLUDE_COOKIES)) {
        foreach ($_COOKIE as $cookieName => $cookieValue) {
            foreach (WPC_EXCLUDE_COOKIES as $excludedCookie) {

                // Trailing "_" means: treat as wildcard prefix (e.g. "wp-postpass_")
                if (substr($excludedCookie, -1) === '_') {
                    if (stripos($cookieName, $excludedCookie) === 0) {
                        define('DONOTCACHEPAGE', true);
                        return; // Don't cache if excluded cookie prefix is detected
                    }
                } else {
                    // Exact match (case-insensitive)
                    if (strcasecmp($cookieName, $excludedCookie) === 0) {
                        define('DONOTCACHEPAGE', true);
                        return; // Don't cache if exact excluded cookie is detected
                    }
                }
            }
        }
    }
}

// Check for mandatory cookies - if any are missing, bypass cache entirely
if (defined('WPC_MANDATORY_COOKIES')) {
    if (WPC_MANDATORY_COOKIES !== false && is_array(WPC_MANDATORY_COOKIES)) {
        foreach (WPC_MANDATORY_COOKIES as $mandatoryCookie) {
            // Trailing "_" means: treat as wildcard prefix
            if (substr($mandatoryCookie, -1) === '_') {
                $found = false;
                foreach ($_COOKIE as $cookieName => $cookieValue) {
                    if (strpos($cookieName, $mandatoryCookie) === 0 && !empty($cookieValue)) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    define('DONOTCACHEPAGE', true);
                    return; // Mandatory cookie prefix not present, bypass cache
                }
            } else {
                if (empty($_COOKIE[$mandatoryCookie])) {
                    define('DONOTCACHEPAGE', true);
                    return; // Mandatory cookie not set, bypass cache
                }
            }
        }
    }
}

// Don't cache for POST requests
if (!empty($_SERVER['REQUEST_METHOD'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
        return;
    }
}

// Don't cache if critical combine, cache disable headers, or disableWPC GET param are present
if (isset($_SERVER['HTTP_CRITICALCOMBINE']) || isset($_SERVER['HTTP_DISABLEWPC']) || !empty($_GET['disableWPC'])) {
    return;
}

// URL bypass — cache exclude (wpc-excludes['cache']) AND full plugin bypass (wpc-url-excludes).
// (v7.10.06) ZERO-DB hit path: the exclude lists are BAKED into this drop-in as constants
// (WPC_URL_EXCLUDES / WPC_CACHE_EXCLUDES) by htaccess.class.php::setAdvancedCache, re-baked on every
// settings/excludes save. That removes the 2 SELECTs this file used to run on EVERY request before
// serving. DB fallback only if the constants aren't baked yet (a drop-in written before this upgrade,
// until its next settings save re-templates it) — so excludes are still honoured during migration.
$check_url = ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
$check_url = explode('?', $check_url)[0];

$wpc_match_pattern = function ($url, $pattern) {
    $pattern = trim($pattern);
    if ($pattern === '' || $pattern[0] === '#') return false;
    $pattern = ltrim($pattern, '/');
    if (strpos($pattern, '*') !== false || strpos($pattern, '?') !== false) {
        $regex = preg_quote($pattern, '#');
        $regex = str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '.'], $regex);
        return (bool) @preg_match('#' . $regex . '#i', $url);
    }
    return stripos($url, $pattern) !== false;
};

// Resolve each exclude list: baked constant wins (zero DB); null means "not baked → read DB".
$wpc_url_excludes_list   = defined('WPC_URL_EXCLUDES')   ? ((WPC_URL_EXCLUDES   === false) ? [] : WPC_URL_EXCLUDES)   : null;
$wpc_cache_excludes_list = defined('WPC_CACHE_EXCLUDES') ? ((WPC_CACHE_EXCLUDES === false) ? [] : WPC_CACHE_EXCLUDES) : null;

if (($wpc_url_excludes_list === null || $wpc_cache_excludes_list === null) && isset($GLOBALS['wpdb'])) {
    if ($wpc_url_excludes_list === null) {
        $raw = $GLOBALS['wpdb']->get_var("SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'wpc-url-excludes'");
        $u = !empty($raw) ? @maybe_unserialize($raw) : [];
        $wpc_url_excludes_list = (!empty($u['exclude-url-from-all']) && is_array($u['exclude-url-from-all'])) ? $u['exclude-url-from-all'] : [];
    }
    if ($wpc_cache_excludes_list === null) {
        $raw = $GLOBALS['wpdb']->get_var("SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'wpc-excludes'");
        $c = !empty($raw) ? @maybe_unserialize($raw) : [];
        $wpc_cache_excludes_list = (!empty($c['cache']) && is_array($c['cache'])) ? $c['cache'] : [];
    }
}

if (is_array($wpc_url_excludes_list)) {
    foreach ($wpc_url_excludes_list as $pattern) {
        if ($wpc_match_pattern($check_url, $pattern)) {
            return; // Bypass cache (plugin will also be bypassed via dontRunif)
        }
    }
}
if (is_array($wpc_cache_excludes_list)) {
    foreach ($wpc_cache_excludes_list as $pattern) {
        if ($wpc_match_pattern($check_url, $pattern)) {
            return; // Skip cache for this URL (plugin still optimizes)
        }
    }
}

// Don't cache if DONOTCACHEPAGE constant is defined
if (defined('DONOTCACHEPAGE') && DONOTCACHEPAGE){
	return;
}

$prefix = '';
$cache = new wps_advancedCache();

$mobile = $cache->is_mobile();
$webp = (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false);

if ($mobile && $webp) $prefix = 'mobile-webp';
elseif ($mobile) $prefix = 'mobile';
elseif ($webp) $prefix = 'webp';

if (!$cache->byPass() && $cache->cacheExists($prefix)) {
  $isCacheExpired = $cache->cacheExpired();

  // Not required as get cache sorts this
  $isCacheValid = $cache->cacheValid();

  if (!$isCacheExpired && $isCacheValid) {
    $cache->getCache($prefix);
    die();
  }
}

//If cache wasn't served
function wps_ic_early_buffer_callback($html) {
		global $wps_ic_cdn_instance;

		if (isset($wps_ic_cdn_instance) && method_exists($wps_ic_cdn_instance, 'saveCache')) {
			return $wps_ic_cdn_instance->saveCache($html);
		}

		return $html;
}


//Start the buffer
define('WPS_IC_CACHE_BUFFER_STARTED', true);
ob_start('wps_ic_early_buffer_callback');