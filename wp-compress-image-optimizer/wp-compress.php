<?php
/*
 * Plugin name: WP Compress – Instant Performance & Speed Optimization
 * Plugin URI: https://www.wpcompress.com
 * Author: WP Compress
 * Author URI: https://www.wpcompress.com
 * Version: 7.25.00
 * Description: Automatically compress and optimize images to shrink image file size, improve  times and boost SEO ranks - all without lifting a finger after setup.
 * Text Domain: wp-compress-image-optimizer
 * Domain Path: /languages
 */


if (!defined('WPC_PLUGIN_VERSION')) {
    define('WPC_PLUGIN_VERSION', '7.25.00');
}


if (!function_exists('wpc_tier_override_from_request')) {
    function wpc_tier_override_from_request($allow_capability = true)
    {
        if (!isset($_GET['wpc_tier'])) {
            return '';
        }
        $tier = strtolower(preg_replace('/[^A-Za-z]/', '', (string) $_GET['wpc_tier']));
        if (!in_array($tier, ['control', 'free', 'local', 'edge'], true)) {
            return '';
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_tier_override_enabled', true)) {
            return '';
        }
        // v7.10.745 — current_user_can() delegates to wp_get_current_user(), which lives in
        // pluggable.php and does NOT exist while plugin files are being included. Calling it at
        // this scope is a FATAL, which is exactly what shipped: every valid ?wpc_tier= returned a
        // 500 while an invalid one returned 200, because the invalid one bailed before this line.
        // The key path needs no capability, so it still runs here at the earliest possible moment;
        // the capability path is re-run on plugins_loaded, once pluggable exists.
        $authorised = ($allow_capability && function_exists('wp_get_current_user')
            && function_exists('current_user_can') && current_user_can('manage_options'));
        if (!$authorised && isset($_GET['wpc_key'])
            && class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'tierKey')) {
            // Lookup mode: never mint on an unauthenticated request, or the first anonymous
            // caller could bring the secret into existence by supplying it.
            $tier_key = wps_ic_url_key::tierKey(false);
            $authorised = ($tier_key !== '' && hash_equals($tier_key, (string) $_GET['wpc_key']));
        }
        if (!$authorised) {
            return '';
        }
        if (!defined('WPC_TIER_ACTIVE')) {
            define('WPC_TIER_ACTIVE', true);
        }
        $tier_cache_on = (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'tierCacheOn'))
            ? wps_ic_url_key::tierCacheOn() : false;
        if (!$tier_cache_on && !defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if ($tier === 'control') {
            $_GET['disableWPC'] = 'true';
            return $tier;
        }
        if ($tier === 'free') {
            $_GET['crit'] = '0';
        }
        $tier_overrides = [
            'free'  => ['live-cdn' => '0', 'modern_image_delivery' => '0', 'used-css' => '0', 'picture_avif' => '0', 'generate_webp' => '0', 'generate_adaptive' => '0'],
            'local' => ['live-cdn' => '0', 'modern_image_delivery' => '1', 'used-css' => '1', 'picture_avif' => '1', 'generate_webp' => '1'],
            'edge'  => ['live-cdn' => '1', 'modern_image_delivery' => '1', 'used-css' => '1', 'picture_avif' => '1', 'generate_webp' => '1'],
        ];
        $overrides = $tier_overrides[$tier];
        $settings_option = defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings';
        add_filter('option_' . $settings_option, function ($settings) use ($overrides) {
            if (!is_array($settings)) {
                return $settings;
            }
            foreach ($overrides as $key => $value) {
                $settings[$key] = $value;
            }
            return $settings;
        }, PHP_INT_MAX);
        // live-cdn=0 alone does not silence the zone: zone-keyed lanes (negotiated delivery,
        // svg/raster zoneify, combine's serve flags) read their own gates, and cdn.* URLs leaked
        // into the free/local arms. wps_ic_allow_live is the one lever every zone lane already
        // honors (the suspension kill-chain), so the non-edge arms ride it for this request.
        if ($tier !== 'edge') {
            // BOTH filters: option_{name} fires only when the row EXISTS; a site that never set
            // allow_live resolves through default_option_{name} instead. Either alone is a hole.
            add_filter('option_wps_ic_allow_live', function () { return '0'; }, PHP_INT_MAX);
            add_filter('default_option_wps_ic_allow_live', function () { return '0'; }, PHP_INT_MAX);
        }
        return $tier;
    }
    // Key path first, at file scope, so the override lands before anything reads settings.
    // Only if that did NOT arm do we ask again once pluggable.php exists — no latch needed, and
    // the option filter can never be registered twice. NOTE: wp-compress.php's own disableWPC
    // check runs at file scope, so the CONTROL arm needs the key; an admin without one still
    // gets free/local/edge.
    if (wpc_tier_override_from_request(false) === '' && function_exists('add_action')) {
        add_action('plugins_loaded', 'wpc_tier_override_from_request', 0);
    }
}


$wpc_disabled_fns = array_filter(array_map('trim', explode(',', (string) (function_exists('ini_get') ? ini_get('disable_functions') : ''))));
$wpc_can_shim = function ($fn) use ($wpc_disabled_fns) {
    if (function_exists($fn)) return false;
    if (PHP_VERSION_ID < 80000 && in_array($fn, $wpc_disabled_fns, true)) return false;  // <8 + disabled → redeclare = FATAL, skip
    return true;
};
if ($wpc_can_shim('getmypid'))           { function getmypid() { return 0; } }
if ($wpc_can_shim('set_time_limit'))     { function set_time_limit($seconds) { return false; } }
if ($wpc_can_shim('ignore_user_abort'))  { function ignore_user_abort($enable = null) { return 0; } }
if ($wpc_can_shim('opcache_reset'))      { function opcache_reset() { return false; } }
if ($wpc_can_shim('opcache_invalidate')) { function opcache_invalidate($filename, $force = false) { return false; } }
if ($wpc_can_shim('opcache_get_status')) { function opcache_get_status($include_scripts = true) { return false; } }


if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
    // Background renders yield to humans: re-check load at RECEIVE time (the fire-time
    // governor reads a ~1min-lagging average); the +60s cron backstop re-fires what
    // yields here, so a 503 loses nothing.
    if (function_exists('sys_getloadavg')) {
        $wpc_warm_load = @sys_getloadavg();
        if (is_array($wpc_warm_load) && isset($wpc_warm_load[0])) {
            $wpc_warm_cpus = 0;
            $wpc_cpuinfo = @is_readable('/proc/cpuinfo') ? (string) @file_get_contents('/proc/cpuinfo') : '';
            if ($wpc_cpuinfo !== '' && preg_match_all('/^processor\s*:/m', $wpc_cpuinfo, $wpc_cpuinfo_matches)) {
                $wpc_warm_cpus = max(1, count($wpc_cpuinfo_matches[0]));
            } else {
                $wpc_cgroup_cpu_max = @is_readable('/sys/fs/cgroup/cpu.max') ? trim((string) @file_get_contents('/sys/fs/cgroup/cpu.max')) : '';
                if ($wpc_cgroup_cpu_max !== '' && preg_match('/^(\d+)\s+(\d+)$/', $wpc_cgroup_cpu_max, $wpc_cgroup_max_parts) && (int) $wpc_cgroup_max_parts[2] > 0) {
                    $wpc_warm_cpus = max(1, (int) ceil((int) $wpc_cgroup_max_parts[1] / (int) $wpc_cgroup_max_parts[2]));
                } else {
                    $wpc_cfs_quota = @is_readable('/sys/fs/cgroup/cpu/cpu.cfs_quota_us') ? (int) trim((string) @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_quota_us')) : 0;
                    $wpc_cfs_period = @is_readable('/sys/fs/cgroup/cpu/cpu.cfs_period_us') ? (int) trim((string) @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_period_us')) : 0;
                    if ($wpc_cfs_quota > 0 && $wpc_cfs_period > 0) {
                        $wpc_warm_cpus = max(1, (int) ceil($wpc_cfs_quota / $wpc_cfs_period));
                    }
                }
            }
            if ($wpc_warm_cpus > 0 && (float) $wpc_warm_load[0] > $wpc_warm_cpus * 2.0) {
                @header('Retry-After: 30');
                if (function_exists('http_response_code')) { http_response_code(503); }
                exit;
            }
        }
    }
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
        $healed = 0;
        foreach ($hosts as $host) {
            if (strpos($html, 'http://' . $host) !== false) {
                $html = str_replace('http://' . $host, 'https://' . $host, $html, $n);
                $healed += (int) $n;
            }
        }
        // An https page must not name its own host over http. When it does, the site's home/siteurl
        // data (a TLS proxy WordPress does not know about) is the cause; sampled, because such a
        // site hands every render the same urls.
        if ($healed > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('mixed-content-healed', ['n' => $healed], true);
        }
        return $html;
    }
}

// THE LOADING BOUNDARY OF THIS FILE. Everything above declares a function behind
// function_exists() (or the version constant behind defined()) and has no other effect;
// everything below is request-time work — constants read off the live request, the cron/CLI
// includes, and the include of wp-compress-core.php, which boots the whole plugin. A loader
// that wants only the helpers declared above — wpc_heal_mixed_content() and
// wpc_request_is_https() are the two the render stage table calls unguarded, so a suite that
// renders a page through the real table has to have them — stops here.
// Rule: nothing may be added above this line that does anything but declare a function.
if (defined('WPC_DECLARATIONS_ONLY') && WPC_DECLARATIONS_ONLY) {
    return;
}

if (!empty($_SERVER['REQUEST_URI'])) {
    $wpc_req_uri = (string) $_SERVER['REQUEST_URI'];


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

// Registered UNCONDITIONALLY (behavior-free filter): our recurring events
// reference this interval, and any context where the conditional modules
// don't load (older v2 gating, mid-load fatal, deactivation leftovers)
// spams "invalid_schedule" reschedule errors every cron pass (evoque.io).
if (function_exists('add_filter')) {
    add_filter('cron_schedules', function ($schedules) {
        if (is_array($schedules) && !isset($schedules['wpc_v2_5min'])) {
            $schedules['wpc_v2_5min'] = ['interval' => 300, 'display' => 'Every 5 minutes (WPC v2)'];
        }
        return $schedules;
    });
}

if (!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC'])){
    include __DIR__ . '/classes/cache-integrations.class.php';
}

if ((!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC']) && ((defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)))) {
    // Required for Scheduled Posts
    include __DIR__ . '/wp-compress-cron.php';
}


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