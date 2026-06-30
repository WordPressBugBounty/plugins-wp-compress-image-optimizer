<?php
/*
 * Plugin name: WP Compress – Instant Performance & Speed Optimization
 * Plugin URI: https://www.wpcompress.com
 * Author: WP Compress
 * Author URI: https://www.wpcompress.com
 * Version: 7.10.04
 * Description: Automatically compress and optimize images to shrink image file size, improve  times and boost SEO ranks - all without lifting a finger after setup.
 * Text Domain: wp-compress-image-optimizer
 * Domain Path: /languages
 */

if (!defined('WPC_PLUGIN_VERSION')) {
    define('WPC_PLUGIN_VERSION', '7.10.04');
}

$wpc_disabled_fns = array_filter(array_map('trim', explode(',', (string) (function_exists('ini_get') ? ini_get('disable_functions') : ''))));
$wpc_can_shim = function ($fn) use ($wpc_disabled_fns) {
    if (function_exists($fn)) return false;                                              // native present → use it
    if (PHP_VERSION_ID < 80000 && in_array($fn, $wpc_disabled_fns, true)) return false;  // <8 + disabled → redeclare = FATAL, skip
    return true;                                                                         // genuinely absent, or 8+ (shim needed + safe)
};
if ($wpc_can_shim('getmypid'))           { function getmypid() { return 0; } }
if ($wpc_can_shim('set_time_limit'))     { function set_time_limit($seconds) { return false; } }
if ($wpc_can_shim('ignore_user_abort'))  { function ignore_user_abort($enable = null) { return 0; } }
if ($wpc_can_shim('opcache_reset'))      { function opcache_reset() { return false; } }
if ($wpc_can_shim('opcache_invalidate')) { function opcache_invalidate($filename, $force = false) { return false; } }
if ($wpc_can_shim('opcache_get_status')) { function opcache_get_status($include_scripts = true) { return false; } }


if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
    @ignore_user_abort(true);
    @set_time_limit(60);
}


if (!function_exists('wpc_request_is_https')) {
    function wpc_request_is_https()
    {
        if (function_exists('is_ssl') && is_ssl()) {
            return true;
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') {
            return true;
        }
        if (!empty($_SERVER['HTTP_CF_VISITOR'])
            && strpos((string) $_SERVER['HTTP_CF_VISITOR'], 'https') !== false) {
            return true;
        }
        // Backstop: a site whose home_url is https should never emit http asset
        // URLs regardless of how the origin sees the request. Same-host https
        // resources are valid even on an http page view, so this is safe.
        if (function_exists('home_url') && strpos((string) home_url(), 'https://') === 0) {
            return true;
        }
        return false;
    }
}
if (!function_exists('wpc_request_scheme')) {
    function wpc_request_scheme()
    {
        return wpc_request_is_https() ? 'https' : 'http';
    }
}
if (!function_exists('wpc_heal_mixed_content')) {
    // On an https request, upgrade SAME-HOST http:// references to https:// so a
    // proxy-misdetected core enqueue can't leave blocked mixed-content CSS/JS on
    // the page. Same-host only (home_url / site_url / HTTP_HOST): an https site
    // always serves its own assets over https, so this never touches genuinely
    // external http resources. Cheap str_replace, guarded by a strpos pre-check.
    function wpc_heal_mixed_content($html)
    {
        if (!is_string($html) || $html === '' || !wpc_request_is_https()) {
            return $html;
        }
        $hosts = [];
        if (function_exists('home_url')) {
            $h = parse_url((string) home_url(), PHP_URL_HOST);
            if ($h) {
                $hosts[] = $h;
            }
        }
        if (function_exists('site_url')) {
            $h = parse_url((string) site_url(), PHP_URL_HOST);
            if ($h) {
                $hosts[] = $h;
            }
        }
        if (!empty($_SERVER['HTTP_HOST'])) {
            $hosts[] = (string) $_SERVER['HTTP_HOST'];
        }
        $hosts = array_unique(array_filter($hosts));
        foreach ($hosts as $host) {
            if (strpos($html, 'http://' . $host) !== false) {
                $html = str_replace('http://' . $host, 'https://' . $host, $html);
            }
        }
        return $html;
    }
}

// Early detection of /wpc/v2/bg_swap REST callbacks. Set BEFORE any
// plugin code loads so wp-compress-core.php can early-return before the wps_ic
// constructor (integrations, preload_warmup, CF rocket check), CDN rewrite,
// elementor, and admin/frontend hook registration — all of which are wasted
// work on the bg_swap endpoint.
//
// WordPress defines REST_REQUEST inside parse_request (after plugins load), so
// we can't gate on it here. URL-substring detection runs before WP bootstraps
// anything. Service-team data 2026-05-20: bootstrap was the dominant remaining
// cost after the v2-callback.php lock fix — ~5-7 s of every callback wall.
if (!empty($_SERVER['REQUEST_URI'])) {
    $wpc_req_uri = (string) $_SERVER['REQUEST_URI'];
    // Extended to cover /wpc/v2/healthcheck (Q10 cold-start probe
    // from LS team's lazy_cdn flow). LS hits this once per new customer + caches
    // 24h, so volume is low — but each probe under full bootstrap would burn
    // 5-7s for no reason. Healthcheck handler only needs wps_ic::$version + a
    // function_exists check; the WPC_IS_BG_SWAP early-return in core gives it
    // the same fast path the bg_swap callback gets.
    if (strpos($wpc_req_uri, '/wp-json/wpc/v2/bg_swap') !== false
        || strpos($wpc_req_uri, '/wp-json/wpc/v2/healthcheck') !== false
        || strpos($wpc_req_uri, 'rest_route=/wpc/v2/bg_swap') !== false
        || strpos($wpc_req_uri, 'rest_route=/wpc/v2/healthcheck') !== false
        || strpos($wpc_req_uri, 'rest_route=%2Fwpc%2Fv2%2Fbg_swap') !== false
        || strpos($wpc_req_uri, 'rest_route=%2Fwpc%2Fv2%2Fhealthcheck') !== false) {
        if (!defined('WPC_IS_BG_SWAP')) {
            define('WPC_IS_BG_SWAP', true);
        }
    }
    unset($wpc_req_uri);
}


if (!empty($_POST['action'])) {
    $wpc_ajax_action = (string) $_POST['action'];

    if ($wpc_ajax_action === 'wps_ic_variant_count'
        || $wpc_ajax_action === 'wps_ic_media_library_heartbeat'
        || $wpc_ajax_action === 'wps_ic_bulkCompressHeartbeat'
        || $wpc_ajax_action === 'wps_ic_image_stats'
        || $wpc_ajax_action === 'wpc_ic_start_bulk_compress'
        || $wpc_ajax_action === 'wps_ic_compress_live') {
        if (!defined('WPC_IS_LIGHT_AJAX')) {
            define('WPC_IS_LIGHT_AJAX', true);
        }
    }
    unset($wpc_ajax_action);
}

if (!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC'])){
    include __DIR__ . '/classes/cache-integrations.class.php';
}

if ((!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC']) && ((defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)))) {
    // Required for Scheduled Posts
    include __DIR__ . '/wp-compress-cron.php';
}

// Register wp-cli commands when running under WP_CLI. The CLI handler
// itself bootstraps wp-compress-core.php on demand, so this include is a no-op
// outside cli context.
if (defined('WP_CLI') && WP_CLI && !isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC'])) {
    include __DIR__ . '/wp-compress-cli.php';
}

if (!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC']) && !(defined('DOING_CRON') && DOING_CRON) && !(defined('WP_CLI') && WP_CLI) && !(defined('REST_REQUEST') && REST_REQUEST)) {
    // CRON fix for WPvivid scheduled backups
    if (get_option('pause_wpcompress_plugin')) {
        add_action('admin_init', 'pause_wpcompress_plugin_deactivate_delete');
        require_once(ABSPATH . 'wp-includes/pluggable.php');
        wp_safe_redirect(admin_url('plugins.php'));
    } else if (get_option('pause_wpcompress_plugin_full_delete')) {
        define('WPC_CC_PLUGIN_FILE', __FILE__);
        include_once __DIR__ . '/wp-compress-core.php';
        add_action('admin_init', 'wpc_delete_and_remove_data');
    } else {
        define('WPC_CC_PLUGIN_FILE', __FILE__);
        include_once __DIR__ . '/wp-compress-core.php';
    }

    function pause_wpcompress_plugin_deactivate_delete()
    {
        if (!function_exists('deactivate_plugins') || !function_exists('delete_plugins')) {
            require_once(ABSPATH . 'wp-admin/includes/plugin.php');
        }

        define('WPC_CC_PLUGIN_FILE', __FILE__);
        include_once __DIR__ . '/wp-compress-core.php';
        deactivate_plugins('wp-compress-image-optimizer/wp-compress.php');

        delete_plugins(['wp-compress-image-optimizer/wp-compress.php']);

        $active_plugins = get_option('active_plugins');
        $plugin_slug = 'wp-compress-image-optimizer/wp-compress.php';
        $key = array_search($plugin_slug, $active_plugins);
        if ($key !== false) {
            unset($active_plugins[$key]);
            update_option('active_plugins', $active_plugins);
        }

        delete_option('pause_wpcompress_plugin');
    }
}