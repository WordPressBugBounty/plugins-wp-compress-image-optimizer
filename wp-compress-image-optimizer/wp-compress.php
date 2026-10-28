<?php
/*
 * Plugin name: WP Compress – Instant Performance & Speed Optimization
 * Plugin URI: https://www.wpcompress.com
 * Author: WP Compress
 * Author URI: https://www.wpcompress.com
 * Version: 7.30.00
 * Description: Automatically compress and optimize images to shrink image file size, improve  times and boost SEO ranks - all without lifting a finger after setup.
 * Text Domain: wp-compress-image-optimizer
 * Domain Path: /languages
 */


if (!defined('WPC_PLUGIN_VERSION')) {
    define('WPC_PLUGIN_VERSION', '7.30.00');
}


if (!function_exists('wpc_tier_override_from_request')) {
    function wpc_tier_override_from_request($allow_capability = true)
    {
        if (!isset($_GET['wpc_tier']) || !is_string($_GET['wpc_tier'])) {
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
        if (!$authorised && isset($_GET['wpc_key']) && is_string($_GET['wpc_key'])
            && class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'tierKey')) {
            // Lookup mode: never mint on an unauthenticated request, or the first anonymous
            // caller could bring the secret into existence by supplying it.
            $tier_key = wps_ic_url_key::tierKey(false);
            $authorised = ($tier_key !== '' && hash_equals($tier_key, $_GET['wpc_key']));
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


if (isset($_SERVER['HTTP_X_WPC_CACHE_WARM']) && !function_exists('wpc_warm_request') && @is_readable(__DIR__ . '/addons/cache/wpc-fs.php')) {
    include_once __DIR__ . '/addons/cache/wpc-fs.php';
}
if (function_exists('wpc_warm_request') && wpc_warm_request()) {
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

if (!function_exists('wpc_plugin_tree_missing_files')) {
    /**
     * The plugin files missing from this install, as paths relative to the plugin folder; an
     * empty list means the tree is complete enough to boot. The one owner of "is the tree
     * loadable": the loader asks it once, before it includes or hooks anything but the
     * behaviour-free cron interval, and nothing after that point re-checks a file or a class.
     *
     * The list is the closure of this file's includes: every file an include or require outside a
     * function body names (on any lane: cron, CLI, admin, debug), and every class that a file of
     * the closure names with new, ::, extends or implements, resolved to the file that declares it
     * (the classes under integrations/ are optional by design and count only when named while a
     * file loads). It is generated, never edited by hand: `php tests/tools/boot-files.php` prints
     * it, and t1600 section F fails when the list and the tree's own includes disagree. A file only
     * a template, an ajax action or a cron job includes inside a function is not checked.
     * include_once of a missing file only warns and require_once of one is a fatal, so without this
     * check the request ran on until the first `new` of an undeclared class or the first
     * require_once of a file that is gone and answered the critical-error page:
     * `Class "wps_local_compress" not found` from update-core.php with addons/legacy/compress.php
     * absent (ticket 11945); cache.class.php absent mid-update (falknerei, v7.21.337). Checked
     * before any include, so a torn tree registers no hook.
     */
    function wpc_plugin_tree_missing_files()
    {
        static $missing = null;
        if ($missing !== null) {
            return $missing;
        }
        $files = [
            'addons/bgLazy/bgLazy.class.php',
            'addons/cache/advancedCache.php',
            'addons/cache/beacon.php',
            'addons/cache/cacheHtml.php',
            'addons/cache/html-br-land.php',
            'addons/cache/link-preset.php',
            'addons/cache/warm.php',
            'addons/cache/wpc-fs.php',
            'addons/cdn/cdn-rewrite.php',
            'addons/cdn/corp-guard.php',
            'addons/cdn/delivery-resolver.php',
            'addons/cdn/fast404-guard.php',
            'addons/cdn/modern-delivery.php',
            'addons/cdn/negotiated-delivery.php',
            'addons/cdn/render-pipeline.php',
            'addons/cdn/rewriteLogic.php',
            'addons/cf-sdk/cf-sdk.php',
            'addons/criticalCss/criticalCss-v2.php',
            'addons/criticalCss/criticalCss.php',
            'addons/debug/db-health.php',
            'addons/doctor/compartment.php',
            'addons/doctor/compartments/assets.php',
            'addons/doctor/compartments/cache.php',
            'addons/doctor/compartments/crit.php',
            'addons/doctor/compartments/logs.php',
            'addons/doctor/compartments/render.php',
            'addons/doctor/compartments/site.php',
            'addons/doctor/context.php',
            'addons/doctor/doctor.php',
            'addons/fonts/fonts.class.php',
            'addons/legacy/compress.php',
            'addons/minify/html.php',
            'addons/offloading/offloading.class.php',
            'addons/rail/rail.php',
            'addons/v2/origin-reach.php',
            'addons/v2/v2-bootstrap.php',
            'addons/v2/v2-callback.php',
            'addons/v2/v2-capabilities.php',
            'addons/v2/v2-client.php',
            'addons/v2/v2-concurrency.php',
            'addons/v2/v2-config-sync.php',
            'addons/v2/v2-customer-purge.php',
            'addons/v2/v2-direct-entry.php',
            'addons/v2/v2-fast-404.php',
            'addons/v2/v2-html-cache-purge.php',
            'addons/v2/v2-inbound.php',
            'addons/v2/v2-journal.php',
            'addons/v2/v2-landed.php',
            'addons/v2/v2-lazy-cdn.php',
            'addons/v2/v2-lcp-health.php',
            'addons/v2/v2-lcp-nocache.php',
            'addons/v2/v2-level.php',
            'addons/v2/v2-natural-url-buffer.php',
            'addons/v2/v2-policy.php',
            'addons/v2/v2-pull-manifest.php',
            'addons/v2/v2-pull.php',
            'addons/v2/v2-recovery.php',
            'addons/v2/v2-rendered-width-beacon.php',
            'addons/v2/v2-report-stamp.php',
            'addons/v2/v2-rung-intercept.php',
            'addons/v2/v2-sd-activity.php',
            'addons/v2/v2-service.php',
            'addons/v2/v2-signed-header.php',
            'addons/v2/v2-sized-spool.php',
            'addons/v2/v2-sized-trigger.php',
            'addons/v2/v2-sse.php',
            'addons/v2/v2-store.php',
            'addons/v2/v2-telemetry.php',
            'addons/v2/v2-trigger-scanner.php',
            'addons/v2/v2-variant-identity.php',
            'addons/v2/v2-wake.php',
            'addons/vitals/vitals.php',
            'classes/ajax.class.php',
            'classes/amp.class.php',
            'classes/asset_version.class.php',
            'classes/atf_observation.class.php',
            'classes/cache-integrations.class.php',
            'classes/cache.class.php',
            'classes/cf_rules.class.php',
            'classes/cname.class.php',
            'classes/combine_css.class.php',
            'classes/combine_js.class.php',
            'classes/comms.class.php',
            'classes/compress.class.php',
            'classes/config.class.php',
            'classes/connect.class.php',
            'classes/controller.class.php',
            'classes/crit_corpus.class.php',
            'classes/enqueues.class.php',
            'classes/hero_land.class.php',
            'classes/htaccess.class.php',
            'classes/image_doctor.class.php',
            'classes/image_library.class.php',
            'classes/image_optimize.class.php',
            'classes/image_sizing.class.php',
            'classes/image_variants.class.php',
            'classes/integrations.class.php',
            'classes/js_delay.class.php',
            'classes/js_delay_v2.class.php',
            'classes/js_delay_v3.class.php',
            'classes/lazy_verdict.class.php',
            'classes/local.class.php',
            'classes/log.class.php',
            'classes/logger.class.php',
            'classes/mainwp.class.php',
            'classes/media_library_live.class.php',
            'classes/menu.class.php',
            'classes/modes.class.php',
            'classes/mu.class.php',
            'classes/notices.class.php',
            'classes/oEmbed.class.php',
            'classes/options.class.php',
            'classes/plan.class.php',
            'classes/preload_warmup.class.php',
            'classes/queue.class.php',
            'classes/remote_restore.class.php',
            'classes/requests.class.php',
            'classes/script_graph.class.php',
            'classes/speculation_rules.class.php',
            'classes/stats.class.php',
            'classes/templates.class.php',
            'classes/upgrader.class.php',
            'classes/users.class.php',
            'classes/visitor_mode.class.php',
            'debug.php',
            'defines.php',
            'integrations/elementor.php',
            'traits/agency.php',
            'traits/excludes.php',
            'traits/url_key.php',
            'wp-compress-cli.php',
            'wp-compress-core.php',
            'wp-compress-cron.php',
        ];
        $missing = [];
        foreach ($files as $file) {
            if (!is_readable(__DIR__ . '/' . $file)) {
                $missing[] = $file;
            }
        }
        return $missing;
    }
}
if (!function_exists('wpc_plugin_tree_incomplete_notice')) {
    /** The admin notice text while files are missing (plain text; the caller escapes it). */
    function wpc_plugin_tree_incomplete_notice(array $missing)
    {
        return sprintf(
            'WP Compress is not running: its plugin files are incomplete (missing: %s). Reinstall the plugin (delete it and install it again, or upload a fresh copy) to restore it.',
            implode(', ', $missing)
        );
    }
}
if (!function_exists('wpc_plugin_tree_stand_down')) {
    /**
     * The plugin does not boot on a tree with missing files: one receipt per admin request and
     * one admin notice for users who can activate plugins, nothing else registered. A visitor
     * request stands down without a receipt (it would be a write per page view). defines.php is
     * not loaded at this point, so unless the page-cache drop-in defined WPS_IC_CACHE the receipt
     * lands in the log's option fallback (wpc_cache_first_log, the last 40 events), which the
     * Debug tool shows.
     */
    function wpc_plugin_tree_stand_down(array $missing)
    {
        if (!function_exists('wpcUninstall')) {
            function wpcUninstall()
            {
            }
        }
        if (function_exists('is_admin') && is_admin()) {
            if (@is_readable(__DIR__ . '/addons/cache/wpc-fs.php')) {
                include_once __DIR__ . '/addons/cache/wpc-fs.php';
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('plugin-tree-incomplete', '', '', ['missing' => $missing]);
            } else {
                error_log('WP Compress: plugin-tree-incomplete, missing ' . implode(', ', $missing));
            }
        }
        if (function_exists('add_action')) {
            add_action('admin_notices', function () use ($missing) {
                if (!function_exists('current_user_can') || !current_user_can('activate_plugins')) {
                    return;
                }
                echo '<div class="notice notice-error"><p>'
                    . esc_html(wpc_plugin_tree_incomplete_notice($missing)) . '</p></div>';
            });
        }
    }
}
if (!function_exists('wpc_plugin_boots_core')) {
    /**
     * Whether this request runs wp-compress-core.php: it is not bypassed (?disableWPC=..., the
     * DISABLEWPC header) and is not the cron, WP-CLI or REST lane, which load wp-compress-cron.php or
     * nothing instead. The one predicate the boot below and the early answer to a speed report share.
     */
    function wpc_plugin_boots_core()
    {
        return !isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC'])
            && !(defined('DOING_CRON') && DOING_CRON) && !(defined('WP_CLI') && WP_CLI) && !(defined('REST_REQUEST') && REST_REQUEST);
    }
}
if (!function_exists('wpc_vitals_early_ready')) {
    /**
     * Whether this request is a visitor's speed report on the admin-ajax lane that can be recorded
     * now, before the rest of WordPress loads: addons/vitals/v.php needs no WordPress, only its own
     * config file and the file writer. False, and the request runs as it always did, for a request that
     * is not a POST of the action (read as WordPress reads it: the body before the query), a report
     * that says the direct address failed (fb=1, which the full door turns into a channel switch), any
     * request or site on which the plugin stands down or boots differently (bypassed by ?disableWPC or
     * the DISABLEWPC header, the cron, WP-CLI and REST lanes, a tree missing a file wpc_plugin_tree_missing_files()
     * lists or v.php, the pause and delete options), a site that turned the early answer off (constant
     * WPC_VITALS_EARLY_OFF, or the stored switch policy.off_vitals_early writes), and a site with a
     * WP-Cron event due that no spawn is looking after: unless cron runs from the system, the request
     * that finds an event due and the doing_cron lock absent or older than WP_CRON_LOCK_TIMEOUT (60 s
     * where the constant is not yet defined) stays on the full path, which is the one spawn_cron()
     * would start; a fresh lock means that spawn is under way and the report is answered.
     */
    function wpc_vitals_early_ready()
    {
        if (!defined('DOING_AJAX') || !DOING_AJAX || !isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
            return false;
        }
        $action = isset($_POST['action']) ? $_POST['action'] : (isset($_GET['action']) ? $_GET['action'] : '');
        if ($action !== 'wpc_v' || (defined('WPC_VITALS_EARLY_OFF') && WPC_VITALS_EARLY_OFF)) {
            return false;
        }
        if (!wpc_plugin_boots_core() || !is_readable(__DIR__ . '/addons/vitals/v.php') || wpc_plugin_tree_missing_files()) {
            return false;
        }
        include_once __DIR__ . '/addons/cache/wpc-fs.php';
        if (!function_exists('wpc_request_body_head')) {
            return false;
        }
        $body = wpc_request_body_head(1024);
        if (strpos($body, 'fb=1') !== false || (isset($_POST['fb']) && $_POST['fb'] === '1')) {
            return false;
        }
        if (function_exists('get_option') && (get_option('pause_wpcompress_plugin') || get_option('pause_wpcompress_plugin_full_delete'))) {
            return false;
        }
        if (function_exists('wp_load_alloptions')) {
            $options = wp_load_alloptions();
            if (isset($options['wpc_vitals_early_off']) && $options['wpc_vitals_early_off'] === '1') {
                return false;
            }
        }
        if (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) && function_exists('_get_cron_array')) {
            $crons = _get_cron_array();
            if (is_array($crons) && $crons) {
                reset($crons);
                if ((int) key($crons) <= time()) {
                    $now = microtime(true);
                    $lock = function_exists('get_transient') ? (float) get_transient('doing_cron') : 0;
                    if ($lock > $now + 600) {
                        $lock = 0;
                    }
                    if ($lock + (defined('WP_CRON_LOCK_TIMEOUT') ? (int) WP_CRON_LOCK_TIMEOUT : 60) <= $now) {
                        return false;
                    }
                }
            }
        }
        return true;
    }
}
if (!function_exists('wpc_vitals_early_answer')) {
    /** Records the speed report with addons/vitals/v.php and ends the request when wpc_vitals_early_ready() says it can be answered now. */
    function wpc_vitals_early_answer()
    {
        if (wpc_vitals_early_ready()) {
            include __DIR__ . '/addons/vitals/v.php';
            exit;
        }
    }
}
if (!function_exists('wpc_off_door_cache_headers')) {
    /** The headers a request with the plugin switched off (the DISABLEWPC header or ?disableWPC) answers with so no page cache or CDN stores it; none for any other request. */
    function wpc_off_door_cache_headers()
    {
        if (!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC'])) {
            return [];
        }
        if (defined('WPC_TIER_ACTIVE') && class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'tierCacheOn') && wps_ic_url_key::tierCacheOn()) {
            return [];
        }
        return ['Cache-Control: no-store, private, max-age=0', 'Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT', 'X-LiteSpeed-Cache-Control: no-cache', 'Vary: DisableWPC'];
    }
}
if (!function_exists('wpc_off_door_no_store')) {
    /** Sends wpc_off_door_cache_headers() and tells page-cache plugins (DONOTCACHEPAGE, LiteSpeed) not to store this response. */
    function wpc_off_door_no_store()
    {
        $headers = wpc_off_door_cache_headers();
        if (!$headers) {
            return;
        }
        if (!defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
        if (function_exists('do_action')) {
            do_action('litespeed_control_set_nocache', 'wpc: plugin switched off for this request');
        }
        if (!headers_sent()) {
            foreach ($headers as $header) {
                header($header, stripos($header, 'Vary:') !== 0);
            }
        }
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


if (!empty($_POST['action']) && is_string($_POST['action'])) {
    $wpc_ajax_action = $_POST['action'];


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

wpc_vitals_early_answer();

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

// A tree with missing files does not boot: nothing below is included or hooked (the cron and CLI
// lanes, the cache integrations and wp-compress-core.php all include files from the list), and
// the admin sees one notice naming the missing files. See wpc_plugin_tree_missing_files().
if (wpc_plugin_tree_missing_files()) {
    wpc_plugin_tree_stand_down(wpc_plugin_tree_missing_files());
    return;
}

if (isset($_GET['wpc_script_graph'])) {
    require_once __DIR__ . '/classes/script_graph.class.php';
    wps_ic_script_graph::arm();
}

if (function_exists('add_action')) {
    add_action('send_headers', 'wpc_off_door_no_store', PHP_INT_MAX);
}

if (!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC'])){
    include __DIR__ . '/classes/cache-integrations.class.php';
}

// REST_REQUEST is defined by WordPress at parse_request, after plugins have loaded, so for an
// ordinary /wp-json/ request its clauses here and in the core include below are false: the cron
// file is not included for it and wp-compress-core.php boots as on any other request. They only
// hold when something defines REST_REQUEST before plugins load.
if ((!isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC']) && ((defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI)))) {
    // Required for Scheduled Posts
    include __DIR__ . '/wp-compress-cron.php';
}


if (defined('WP_CLI') && WP_CLI && !isset($_SERVER['HTTP_DISABLEWPC']) && empty($_GET['disableWPC'])) {
    include __DIR__ . '/wp-compress-cli.php';
}

if (wpc_plugin_boots_core()) {
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