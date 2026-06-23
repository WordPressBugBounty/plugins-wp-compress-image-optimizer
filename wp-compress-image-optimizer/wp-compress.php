<?php
/*
 * Plugin name: WP Compress – Instant Performance & Speed Optimization
 * Plugin URI: https://www.wpcompress.com
 * Author: WP Compress
 * Author URI: https://www.wpcompress.com
 * Version: 7.03.39
 * Description: Automatically compress and optimize images to shrink image file size, improve  times and boost SEO ranks - all without lifting a finger after setup.
 * Text Domain: wp-compress-image-optimizer
 * Domain Path: /languages
 */

// Plugin version available globally without bootstrapping the wps_ic
// class. Needed because wps_ic::$version is assigned in __construct(), which
// doesn't run on the WPC_IS_BG_SWAP fast-path early-return below. Healthcheck
// endpoint + any other pre-bootstrap consumer reads this. Keep in sync with the
// plugin header `Version:` field above + wp-compress-core.php's self::$version.
if (!defined('WPC_PLUGIN_VERSION')) {
    define('WPC_PLUGIN_VERSION', '7.03.39');
}

// PHP-environment compat shims (production hardening).
// Hardened/shared hosts remove functions via `disable_functions`, which on
// PHP 8+ makes them genuinely UNDEFINED — calling one throws
// "Call to undefined function" (a thrown Error, FATAL), and the `@` operator
// does NOT catch it (it suppresses warnings, not thrown Errors). A real crash
// was hit on a TasteWP sandbox: getmypid() undefined in process_css_for_fonts()
// → fatal on every front-end render. These side-effect functions are called in
// ~55 places across the plugin; rather than guard each site, define a guarded
// no-op shim ONCE here, in the always-loaded entry (runs in every context), so
// an absent/disabled function degrades to a harmless no-op instead of a fatal.
// Each shim is defined ONLY when the function is truly absent (function_exists
// false — which is also false for PHP 8+ disabled functions), so on a normal
// host the native function is used unchanged: zero behavior change. All six are
// pure side-effects whose return value the plugin treats as best-effort, so a
// no-op return is semantically safe.
if (!function_exists('getmypid'))           { function getmypid() { return 0; } }
if (!function_exists('set_time_limit'))     { function set_time_limit($seconds) { return false; } }
if (!function_exists('ignore_user_abort'))  { function ignore_user_abort($enable = null) { return 0; } }
if (!function_exists('opcache_reset'))      { function opcache_reset() { return false; } }
if (!function_exists('opcache_invalidate')) { function opcache_invalidate($filename, $force = false) { return false; } }
if (!function_exists('opcache_get_status')) { function opcache_get_status($include_scripts = true) { return false; } }

// v7.02.47 — Cache-warm loopback survival. A homepage warm (wps_ic_cache::fireHomepageWarm) is a
// fire-and-forget local-vhost GET carrying X-WPC-Cache-Warm: the client socket closes the instant the
// request is written, so the render that produces the cached HTML must NOT abort when the peer
// disconnects — otherwise the buffer-save never runs and nothing is cached. ignore_user_abort keeps the
// render alive to completion; a bounded time limit stops a hung render from pinning a worker. Gated
// strictly on the warm header, so normal visitor requests are completely unaffected.
if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
    @ignore_user_abort(true);
    @set_time_limit(60);
}

// Proxy-aware HTTPS detection + mixed-content heal.
// WordPress core is_ssl() only inspects $_SERVER['HTTPS'] / SERVER_PORT. When
// TLS terminates at a reverse proxy or CDN (Cloudflare Flexible-or-Full SSL,
// WP Engine, load balancers) the origin connection is plain http, so is_ssl()
// returns FALSE on a genuinely https request. Enqueue helpers then emit http://
// asset URLs the browser blocks as mixed content on the https page (unstyled
// page + "wp is not defined" when the http i18n script is blocked). These
// helpers add the standard proxy signals (X-Forwarded-Proto, Cloudflare
// CF-Visitor) plus an https-home_url backstop, mirroring detection already used
// in addons/legacy/compress.php and fixCss.php. Defined here in the always-
// loaded plugin entry (NOT wp-compress-core.php, which is skipped under
// WP_CLI/cron/REST) so every WPC scheme decision shares one source of truth in
// every context. Functions only call WP APIs at call-time, so defining them
// before WP fully boots is safe.
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

// Light-ajax detection. Two specific admin-ajax actions
// (wps_ic_variant_count, wps_ic_media_library_heartbeat) fire 4-6× per second
// during a compress and do NOT need the 10-15 plugin-check integration class
// instantiations from wps_ic_integrations::add_admin_hooks/apply_admin_filters
// (those register admin_init/admin_menu hooks that don't fire on admin-ajax
// anyway). They also don't need wps_ic_preload_warmup's cron setup. Skipping
// those for these two actions cuts ~200-1500 ms of per-request bootstrap cost
// without touching the handlers' own dependencies (ajax + media_library still
// initialize via the existing inAdmin path).
//
// $_POST['action'] is set by WP's admin-ajax router; we read it directly here
// (before WP bootstraps further) — same way URL-prefix detection works above.
if (!empty($_POST['action'])) {
    $wpc_ajax_action = (string) $_POST['action'];
    // Allow-list of high-frequency read-only admin-ajax actions whose handlers
    // do NOT depend on the wps_ic_integrations admin-hook registrations or the
    // preload_warmup cron setup. Adding an action here cuts ~200-1500 ms of
    // per-request bootstrap; only add ones verified safe.
    //   - wps_ic_variant_count            — chip+badge poller (250 ms cadence)
    //   - wps_ic_media_library_heartbeat  — single-image card swap (500 ms burst)
    //   - wps_ic_bulkCompressHeartbeat    — bulk variant-stream poller (1.5 s)
    //   - wps_ic_image_stats              — Details popup render (one-shot, but
    //                                       perceived latency matters; popup
    //                                       opens instantly with placeholder
    //                                       and swaps in content when this
    //                                       endpoint returns)
    //
    // Click-trigger actions. Audit 2026-05-20: neither handler reads
    // $this->integrations or instantiates wps_ic_preload_warmup. Both rely on
    // $this->media_library (init'd unconditionally in inAdmin), self::$local
    // (wps_local_compress static), and WP core. Skipping the heavy integration
    // hook registration + cron setup cuts the WP bootstrap BEFORE T0 by
    // ~500-1500 ms, bringing perceived click→first-pill wall from ~7-8 s down
    // to ~5-6 s — recovers ~half the gap I previously called "unavoidable".
    //   - wpc_ic_start_bulk_compress      — bulk start (one click per session,
    //                                       perceived delay matters most here)
    //   - wps_ic_compress_live            — single-image compress click
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