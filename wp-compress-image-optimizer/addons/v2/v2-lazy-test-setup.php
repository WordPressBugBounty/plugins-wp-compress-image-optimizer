<?php
/**
 * WP Compress v7.08.1 — Lazy-CDN support tools (apikey-gated nopriv endpoints).
 *
 * Production diagnostic + recovery utilities used by support workflows.
 * Every endpoint requires the customer's apikey via hash_equals (constant-time
 * compare against wpc_v2_get_apikey()). Without it, requests get HTTP 403.
 *
 * Endpoints exposed:
 *   - wpc_v2_lazy_opcache_invalidate    Reset opcache on deploys (needed on
 *                                       hosts with validate_timestamps=0 such
 *                                       as Cloudways production)
 *   - wpc_v2_lazy_backfill_postmeta     One-shot postmeta backfill for sites
 *                                       upgrading from pre-v7.08 plugin
 *                                       (writes ic_local_variants entries
 *                                       for lazy_cdn files that landed under
 *                                       the old code path)
 *   - wpc_v2_lazy_inspect_disk          Read-only postmeta + disk inspector
 *                                       for support diagnostics
 *   - wpc_v2_lazy_force_drain           Manual drain trigger (escalation
 *                                       path when wake-ping + cron belt
 *                                       both fail)
 *   - wpc_v2_lazy_force_config_sync     Re-syncs /v2/config to orch
 *   - wpc_v2_ajax_lazy_force_miss       Force a CDN cache-miss on a specific
 *                                       variant (for testing the
 *                                       encode-then-pull cycle)
 *
 * Revert: delete this file + remove the require_once line in v2-bootstrap.php.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_lazy_test_check_apikey')) {
    /**
     * Apikey-gate via query param OR POST body. Returns true if matches the
     * plugin's stored apikey (constant-time compare).
     */
    function wpc_v2_lazy_test_check_apikey()
    {
        $provided = '';
        if (isset($_REQUEST['apikey'])) $provided = (string) $_REQUEST['apikey'];
        if ($provided === '') return false;

        $stored = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($stored === '') return false;

        return hash_equals($stored, $provided);
    }
}

// The canary-only test-page creator (wpc_v2_ajax_create_lazy_test_page) has
// been removed for production. The diagnostic/support endpoints below stay in
// place: they're apikey-gated and useful for ongoing customer support
// workflows.

/**
 * Force a CDN cache miss for one image's AVIF variant by deleting that
 * variant from disk. Apikey-gated. Returns the deleted path + the expected
 * CDN URL so the caller can verify post-test.
 *
 * Body: { apikey, image_id, size?: 'medium'|'large'|... }
 */
if (!function_exists('wpc_v2_ajax_lazy_force_miss')) {
    function wpc_v2_ajax_lazy_force_miss()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        $image_id = isset($_REQUEST['image_id']) ? (int) $_REQUEST['image_id'] : 0;
        $size     = isset($_REQUEST['size']) ? (string) $_REQUEST['size'] : 'large';
        if ($image_id <= 0) {
            wp_send_json_error(['msg' => 'missing_image_id'], 400);
        }

        // Resolve the sub-size path for AVIF + WebP variants.
        $src = wp_get_attachment_image_src($image_id, $size);
        if (!$src) {
            wp_send_json_error(['msg' => 'size_not_available'], 404);
        }
        $url       = $src[0];
        $abs_jpg   = str_replace(wp_get_upload_dir()['baseurl'], wp_get_upload_dir()['basedir'], $url);
        $abs_avif  = preg_replace('/\.[^.]+$/', '.avif', $abs_jpg);
        $abs_webp  = preg_replace('/\.[^.]+$/', '.webp', $abs_jpg);

        $deleted = [];
        foreach ([$abs_avif, $abs_webp] as $p) {
            if ($p && file_exists($p)) {
                if (@unlink($p)) $deleted[] = $p;
            }
        }

        wp_send_json_success([
            'image_id'   => $image_id,
            'size'       => $size,
            'jpg_url'    => $url,
            'avif_path'  => $abs_avif,
            'webp_path'  => $abs_webp,
            'deleted'    => $deleted,
            'message'    => count($deleted) > 0
                ? 'Forced cache miss — next request for AVIF/WebP variants will hit origin 404, should trigger lazy_cdn.'
                : 'Nothing to delete (variants did not exist on disk anyway — still a cache-miss scenario).',
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_force_miss',        'wpc_v2_ajax_lazy_force_miss');
add_action('wp_ajax_nopriv_wpc_v2_lazy_force_miss', 'wpc_v2_ajax_lazy_force_miss');

/**
 * Dump the plugin settings + state relevant to image-delivery behavior.
 * Apikey-gated. Used by support to diagnose why a customer's images render at
 * unexpected widths.
 */
if (!function_exists('wpc_v2_ajax_lazy_inspect_settings')) {
    function wpc_v2_ajax_lazy_inspect_settings()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        $s = function_exists('get_option') ? get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings') : [];
        if (!is_array($s)) $s = [];
        $keys_of_interest = [
            'fetchpriority-high',
            'lazySkipCount',
            'optimize-lcp',
            'maxWidth',           // ← cap for buildLcpSrcset ladder (default 2560)
            'retina',
            'imageWidth',         // ← max image-tag width
            'live-cdn',
            'picture_webp',
            'picture_avif',
            'lazy-load',
            'native-lazy',
            'retina-in-srcset',
            'adaptive',
            'generate_webp',
            'generate_adaptive',
            'add-image-sizes',
        ];
        $picked = [];
        foreach ($keys_of_interest as $k) {
            $picked[$k] = array_key_exists($k, $s) ? $s[$k] : '(unset)';
        }
        // `?all=1` dumps ALL setting keys, not just the image-related 16. Used
        // for the comprehensive setting/code-path audit when we suspect a UI
        // toggle isn't wired through to where we read it. One such case: a UI
        // label vs DB key mismatch where "Resize by Incoming Device" saves to
        // `generate_adaptive` not `adaptive`, so the mobile cap was gating on
        // the wrong key.
        $all_keys = !empty($_REQUEST['all']);
        $response = [
            'settings'                          => $picked,
            'wpc_plugin_version'                => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '(unknown)',
            'wpc_v2_lazy_enabled'               => function_exists('wpc_v2_get_lazy_enabled') ? (bool) wpc_v2_get_lazy_enabled() : null,
            'cdn_zone_name'                     => get_option('ic_cdn_zone_name'),
            'cdn_custom_cname'                  => get_option('ic_custom_cname'),
            'sample_settings_total_keys'        => count($s),
        ];
        if ($all_keys) {
            // Flatten nested arrays for easier audit reading; preserve raw structure too.
            ksort($s);
            $response['all_settings_raw'] = $s;
        }
        wp_send_json_success($response);
    }
}
add_action('wp_ajax_wpc_v2_lazy_inspect_settings',        'wpc_v2_ajax_lazy_inspect_settings');
add_action('wp_ajax_nopriv_wpc_v2_lazy_inspect_settings', 'wpc_v2_ajax_lazy_inspect_settings');

/**
 * Patch plugin settings + force HTML cache purge in one call. Apikey-gated.
 * Accepts `set[key]=value` form-encoded params.
 *
 *   POST set[lazySkipCount]=1&set[fetchpriority-high]=0&apikey=...
 *
 * Whitelists keys to delivery-affecting options only. Always fires an HTML
 * cache purge after the merge, since the caller is explicitly requesting it.
 * Returns a before/after diff so support can confirm the change took effect.
 */
if (!function_exists('wpc_v2_ajax_lazy_patch_setting')) {
    function wpc_v2_ajax_lazy_patch_setting()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        $set = isset($_REQUEST['set']) ? $_REQUEST['set'] : [];
        if (!is_array($set) || empty($set)) {
            wp_send_json_error(['msg' => 'set_array_required'], 400);
        }
        $key_name = defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings';
        $existing = get_option($key_name);
        if (!is_array($existing)) $existing = [];

        $allowed = [
            'fetchpriority-high', 'lazySkipCount', 'lazy-load',
            'native-lazy', 'picture_webp', 'picture_avif',
            'live-cdn', 'retina-in-srcset', 'optimize-lcp',
            'adaptive', 'add-image-sizes', 'generate_webp',
            'generate_adaptive', 'maxWidth', 'retina', 'imageWidth',
        ];

        $changes = [];
        foreach ($set as $k => $v) {
            // Sanitize: strip everything except alphanumerics + dash/underscore.
            // We can't use sanitize_key() because it lowercases, which breaks
            // camelCase keys like `lazySkipCount`. That actually failed to match
            // the whitelist on first deploy.
            $k = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $k);
            if ($k === '' || !in_array($k, $allowed, true)) continue;
            $val = is_scalar($v) ? (string) $v : '';
            $before = array_key_exists($k, $existing) ? $existing[$k] : null;
            $existing[$k] = $val;
            $changes[$k] = ['before' => $before, 'after' => $val];
        }

        if (empty($changes)) {
            wp_send_json_error(['msg' => 'no_whitelisted_keys_in_set'], 400);
        }

        update_option($key_name, $existing);

        $purged = false;
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
            $purged = true;
        }

        wp_send_json_success([
            'changes'           => $changes,
            'html_cache_purged' => $purged,
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_patch_setting',        'wpc_v2_ajax_lazy_patch_setting');
add_action('wp_ajax_nopriv_wpc_v2_lazy_patch_setting', 'wpc_v2_ajax_lazy_patch_setting');

/**
 * Tail recent WPC log entries. Apikey-gated. Returns last N lines matching
 * WPC patterns. For monitoring during canary tests when SSH access isn't
 * available.
 */
if (!function_exists('wpc_v2_ajax_lazy_log_tail')) {
    function wpc_v2_ajax_lazy_log_tail()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        $lines   = isset($_REQUEST['lines']) ? min(500, max(10, (int) $_REQUEST['lines'])) : 100;
        $log_path = WP_CONTENT_DIR . '/debug.log';
        if (!file_exists($log_path) || !is_readable($log_path)) {
            wp_send_json_error(['msg' => 'no_debug_log', 'path' => $log_path], 404);
        }
        // Read just the tail (last ~256KB) so we don't load the whole file
        $fp = @fopen($log_path, 'r');
        if (!$fp) wp_send_json_error(['msg' => 'log_open_failed'], 500);
        fseek($fp, 0, SEEK_END);
        $size = ftell($fp);
        $tail_bytes = min(262144, $size);
        fseek($fp, -$tail_bytes, SEEK_END);
        $chunk = fread($fp, $tail_bytes);
        fclose($fp);

        // Filter WPC-related lines
        $all = explode("\n", (string) $chunk);
        $wpc = [];
        foreach ($all as $line) {
            if (strpos($line, 'WPC ') !== false
             || strpos($line, '[WPC') !== false
             || strpos($line, 'wpc_v2_') !== false
             || strpos($line, 'lazy_cdn') !== false
             || strpos($line, 'LazyCDN') !== false) {
                $wpc[] = $line;
            }
        }
        $wpc = array_slice($wpc, -$lines);
        wp_send_json_success([
            'count'   => count($wpc),
            'lines'   => $wpc,
            'now_utc' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_log_tail',        'wpc_v2_ajax_lazy_log_tail');
add_action('wp_ajax_nopriv_wpc_v2_lazy_log_tail', 'wpc_v2_ajax_lazy_log_tail');

/**
 * One-shot local-mirror setter. For staging environments where orch flipped
 * agencySites.lazy_cdn_active=1 directly in DB (bypassing the plugin's
 * /v2/config sync), the plugin's local options wpc_v2_zone_id +
 * wpc_v2_lazy_enabled_<zone_id> won't be set. This endpoint lets us seed
 * them so wpc_v2_get_lazy_enabled() returns true → rewriteLogic.php
 * optimistic AVIF emission gate passes.
 *
 * Apikey-gated. Removed when this whole test-setup file is removed.
 *
 * Body: { apikey, zone_id, enabled }
 */
if (!function_exists('wpc_v2_ajax_lazy_set_local_mirror')) {
    function wpc_v2_ajax_lazy_set_local_mirror()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        $zone_id = isset($_REQUEST['zone_id']) ? sanitize_key($_REQUEST['zone_id']) : '';
        $enabled = !empty($_REQUEST['enabled']) && $_REQUEST['enabled'] !== '0';
        if ($zone_id === '') {
            wp_send_json_error(['msg' => 'missing_zone_id'], 400);
        }
        update_option('wpc_v2_zone_id', $zone_id, false);
        update_option('wpc_v2_lazy_enabled_' . $zone_id, $enabled ? '1' : '0', false); // legacy mirror (no longer read)
        // lazy_cdn is the Optimization Strategy now, the single source of truth:
        // wpc_v2_get_lazy_enabled() ignores the per-zone flag above and keys off
        // the mode. Seed the strategy so this tool actually flips lazy on/off.
        // update_option fires wpc_v2_maybe_sync_image_config(), which handles the
        // orch /v2/config sync + pull. Disable only downgrades when currently
        // lazy_cdn, so it never clobbers a manual/legacy mode.
        if (defined('WPS_IC_SETTINGS')) {
            $s = get_option(WPS_IC_SETTINGS, []);
            if (!is_array($s)) $s = [];
            $was_lazy_cdn = (($s['wpc_optimization_mode'] ?? '') === 'lazy_cdn');
            if ($enabled) {
                $s['wpc_optimization_mode'] = 'lazy_cdn';
            } elseif ($was_lazy_cdn) {
                $s['wpc_optimization_mode'] = 'legacy';
            }
            update_option(WPS_IC_SETTINGS, $s); // fires maybe_sync (orch + pull)
        }
        // Backstop: keep the explicit pull coupling the production path also enforces.
        if ($enabled) {
            $cur = (bool) get_site_option('wpc_v2_pull_enabled', false);
            if (!$cur) update_site_option('wpc_v2_pull_enabled', 1);
        }
        wp_send_json_success([
            'zone_id'          => $zone_id,
            'enabled'          => $enabled,
            'pull_enabled'     => (bool) get_site_option('wpc_v2_pull_enabled', false),
            'lazy_enabled_now' => function_exists('wpc_v2_get_lazy_enabled') ? (bool) wpc_v2_get_lazy_enabled() : null,
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_set_local_mirror',        'wpc_v2_ajax_lazy_set_local_mirror');
add_action('wp_ajax_nopriv_wpc_v2_lazy_set_local_mirror', 'wpc_v2_ajax_lazy_set_local_mirror');

/**
 * Inspect plugin settings + optionally flip a key. Apikey-gated.
 * Body: { apikey, set?: "key=value", get?: 1 }
 */
if (!function_exists('wpc_v2_ajax_lazy_inspect_settings')) {
    function wpc_v2_ajax_lazy_inspect_settings()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        // The actual option key is 'wps_ic_settings' (the WPS_IC_SETTINGS
        // constant, defined at defines.php:83). 'wps_ic' is the class name, not
        // the option.
        $opt_key  = defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings';
        $settings = get_option($opt_key, []);
        if (!is_array($settings)) $settings = [];

        $flip = isset($_REQUEST['set']) ? (string) $_REQUEST['set'] : '';
        if ($flip !== '' && strpos($flip, '=') !== false) {
            list($k, $v) = explode('=', $flip, 2);
            $k = sanitize_key($k);
            if ($k !== '') {
                $settings[$k] = (string) $v;
                update_option($opt_key, $settings);
            }
        }

        // Only return relevant picture/cdn-mode keys to keep response tight.
        $interesting = [
            'picture_webp', 'picture_avif', 'generate_webp', 'generate_adaptive',
            'live-cdn', 'cdn', 'webp', 'adaptive_images',
            'wpc_optimization_mode',
        ];
        $relevant = [];
        foreach ($interesting as $k) {
            $relevant[$k] = isset($settings[$k]) ? $settings[$k] : null;
        }
        wp_send_json_success([
            'relevant'     => $relevant,
            'wpc_v2_zone_id'    => get_option('wpc_v2_zone_id', '(not set)'),
            'pull_enabled'      => (bool) get_site_option('wpc_v2_pull_enabled', false),
            'lazy_enabled_fn'   => function_exists('wpc_v2_get_lazy_enabled') ? (bool) wpc_v2_get_lazy_enabled() : null,
            'optimization_mode' => function_exists('wpc_get_optimization_mode') ? wpc_get_optimization_mode() : '(fn-missing)',
            'lazy_mode_active'  => function_exists('wpc_lazy_mode_active') ? (bool) wpc_lazy_mode_active() : null,
            'wpc_optimization_mode_option' => get_option('wpc_optimization_mode', '(not set)'),
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_inspect_settings',        'wpc_v2_ajax_lazy_inspect_settings');
add_action('wp_ajax_nopriv_wpc_v2_lazy_inspect_settings', 'wpc_v2_ajax_lazy_inspect_settings');

/**
 * Purge WPC HTML cache (wp-content/cache/wp-cio/ + wp-preload/).
 * Required to see rendered HTML changes after rewriteLogic.php updates.
 */
if (!function_exists('wpc_v2_ajax_lazy_purge_cache')) {
    function wpc_v2_ajax_lazy_purge_cache()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        $purged = [];
        $dirs = [
            WP_CONTENT_DIR . '/cache/wp-cio/',
            WP_CONTENT_DIR . '/cache/wp-preload/',
            WP_CONTENT_DIR . '/cache/breeze-cache/',
        ];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $count = 0;
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iter as $f) {
                if ($f->isFile()) {
                    if (@unlink($f->getRealPath())) $count++;
                }
            }
            $purged[$dir] = $count;
        }
        wp_send_json_success(['purged' => $purged]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_purge_cache',        'wpc_v2_ajax_lazy_purge_cache');
add_action('wp_ajax_nopriv_wpc_v2_lazy_purge_cache', 'wpc_v2_ajax_lazy_purge_cache');

/**
 * Verify deployed code state for debugging. Reports whether the v7.05.2 AVIF
 * optimistic-emission patch is actually present in the deployed
 * rewriteLogic.php, in case an SFTP upload silently failed, opcache is stale,
 * or staging-2's file sits on a different path.
 */
if (!function_exists('wpc_v2_ajax_lazy_verify_patch')) {
    function wpc_v2_ajax_lazy_verify_patch()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        $rewriter_path = WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/cdn/rewriteLogic.php';
        $cdn_rewrite_path = WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/cdn/cdn-rewrite.php';
        $exists  = file_exists($rewriter_path);
        $content = $exists ? @file_get_contents($rewriter_path) : '';
        $has_optimistic = $exists && strpos($content, '$optimistic_avif') !== false;
        $has_v7052_marker = $exists && strpos($content, 'v7.05.2') !== false;
        // Check cdn-rewrite.php patch too
        $cdn_content = file_exists($cdn_rewrite_path) ? @file_get_contents($cdn_rewrite_path) : '';
        $cdn_has_optimistic = strpos($cdn_content, '$optimistic_avif') !== false;
        $cdn_mtime = file_exists($cdn_rewrite_path) ? gmdate('Y-m-d H:i:s', filemtime($cdn_rewrite_path)) : null;
        $picture_avif_enabled_in_static = null;
        $picture_webp_enabled_in_static = null;
        foreach (['wps_rewriteLogic', 'wps_ic_rewriteLogic', 'wpc_image_proxy_rewriter'] as $cls) {
            if (class_exists($cls) && property_exists($cls, 'pictureAvifEnabled')) {
                $picture_avif_enabled_in_static = $cls::$pictureAvifEnabled;
                $picture_webp_enabled_in_static = $cls::$pictureWebpEnabled;
                break;
            }
        }
        wp_send_json_success([
            'rewriter_path'   => $rewriter_path,
            'rewriter_exists' => $exists,
            'rewriter_size'   => $exists ? filesize($rewriter_path) : 0,
            'rewriter_mtime'  => $exists ? gmdate('Y-m-d H:i:s', filemtime($rewriter_path)) : null,
            'has_optimistic_avif_marker' => $has_optimistic,
            'has_v7052_marker'           => $has_v7052_marker,
            'cdn_rewrite_has_optimistic' => $cdn_has_optimistic,
            'cdn_rewrite_mtime'          => $cdn_mtime,
            'pictureAvifEnabled_static'  => $picture_avif_enabled_in_static,
            'pictureWebpEnabled_static'  => $picture_webp_enabled_in_static,
            'lazy_enabled_now'           => function_exists('wpc_v2_get_lazy_enabled') ? wpc_v2_get_lazy_enabled() : null,
            'plugin_version'             => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : null,
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_verify_patch',        'wpc_v2_ajax_lazy_verify_patch');
add_action('wp_ajax_nopriv_wpc_v2_lazy_verify_patch', 'wpc_v2_ajax_lazy_verify_patch');

/**
 * Force opcache invalidation on rewriteLogic.php (workaround for no-SSH
 * staging where touched files aren't picked up by stale opcache).
 */
if (!function_exists('wpc_v2_ajax_lazy_opcache_invalidate')) {
    function wpc_v2_ajax_lazy_opcache_invalidate()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        $files = [
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/cdn/rewriteLogic.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/cdn/cdn-rewrite.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-bootstrap.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-callback.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-capabilities.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-client.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-config-sync.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-direct-entry.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-html-cache-purge.php',
            // Critical lazy_cdn files used to be missing from this list.
            // Without them an opcache_invalidate-only path would skip the
            // primary lazy_cdn ingest + manifest pull files on deploys where
            // opcache_reset() is restricted. Cloudways production typically
            // succeeds at the full reset, but this is defense-in-depth for hosts
            // that disable it.
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-lazy-cdn.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-pull-manifest.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-wake.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-page-load-poll.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-trigger-scanner.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-lazy-test-setup.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/addons/v2/v2-natural-url-buffer.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/wp-compress.php',
            WP_CONTENT_DIR . '/plugins/wp-compress-image-optimizer/wp-compress-core.php',
        ];
        $invalidated = [];
        $reset_full = false;
        if (function_exists('opcache_reset')) {
            $reset_full = (bool) opcache_reset();
        }
        if (function_exists('opcache_invalidate')) {
            foreach ($files as $f) {
                if (file_exists($f)) {
                    $invalidated[$f] = (bool) opcache_invalidate($f, true);
                }
            }
        }
        wp_send_json_success([
            'opcache_reset_full' => $reset_full,
            'per_file_invalidated' => $invalidated,
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_opcache_invalidate',        'wpc_v2_ajax_lazy_opcache_invalidate');
add_action('wp_ajax_nopriv_wpc_v2_lazy_opcache_invalidate', 'wpc_v2_ajax_lazy_opcache_invalidate');

/**
 * One-shot postmeta backfill for orphaned lazy_cdn variants.
 *
 * Pre-v7.08 lazy_cdn ingest wrote files to disk but never updated
 * ic_local_variants — so the ML chip stayed empty for any variant that
 * landed under that code path. This endpoint scans an attachment's uploads
 * subdir for .avif/.webp siblings of the main JPG + sub-size JPGs and
 * writes the missing postmeta entries via wpc_v2_lazy_cdn_write_postmeta.
 *
 * Usage:
 *   POST action=wpc_v2_lazy_backfill_postmeta&apikey=…&image_id=18
 *   POST action=wpc_v2_lazy_backfill_postmeta&apikey=…&all=1     (scan ALL
 *                                                                  attachments
 *                                                                  with .avif
 *                                                                  on disk)
 *
 * Returns per-attachment count of postmeta entries written. Idempotent —
 * re-running just re-merges the same data (no duplicates, no corruption).
 */
if (!function_exists('wpc_v2_ajax_lazy_backfill_postmeta')) {
    function wpc_v2_ajax_lazy_backfill_postmeta()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        if (!function_exists('wpc_v2_lazy_cdn_write_postmeta')) {
            wp_send_json_error(['msg' => 'wpc_v2_lazy_cdn_write_postmeta_unavailable'], 500);
        }

        $upload_dir = wp_get_upload_dir();
        $basedir    = rtrim((string) $upload_dir['basedir'], '/');
        $baseurl    = rtrim((string) $upload_dir['baseurl'], '/');

        // Resolve target attachment(s)
        $target_ids = [];
        $image_id_arg = isset($_REQUEST['image_id']) ? (int) $_REQUEST['image_id'] : 0;
        $all_arg      = !empty($_REQUEST['all']);
        if ($image_id_arg > 0) {
            $target_ids = [$image_id_arg];
        } elseif ($all_arg) {
            // Find every attachment whose subdir contains at least one .avif/.webp.
            // Keep this bounded — cap at 500 attachments per call so an
            // accidental run on a 100K-image library doesn't pin a worker.
            $atts = get_posts([
                'post_type'      => 'attachment',
                'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
                'post_status'    => 'inherit',
                'posts_per_page' => 500,
                'fields'         => 'ids',
            ]);
            $target_ids = is_array($atts) ? array_map('intval', $atts) : [];
        } else {
            wp_send_json_error(['msg' => 'image_id_or_all_required'], 400);
        }

        $report = [];
        $total_written = 0;
        foreach ($target_ids as $att_id) {
            $att_id = (int) $att_id;
            if ($att_id <= 0) continue;

            $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($att_id) : false;
            if (!is_array($meta) || empty($meta['file'])) continue;

            $main_rel = (string) $meta['file'];
            $main_dir = dirname($main_rel);
            $att_dir  = ($main_dir !== '' && $main_dir !== '.') ? $basedir . '/' . $main_dir : $basedir;
            if (!is_dir($att_dir)) continue;

            // Build the set of "candidate basenames" we should look for AVIF/WebP
            // siblings of:
            //   (a) main file (scaled or un-scaled original) basename, no ext
            //   (b) each sub-size's file basename, no ext
            //   (c) un-scaled original basename if -scaled.* (so we catch
            //       use_original lazy_cdn outputs)
            $candidates_no_ext = [];
            $candidates_no_ext[] = preg_replace('/\.[^.]+$/', '', basename($main_rel));
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $sz_data) {
                    if (empty($sz_data['file'])) continue;
                    $candidates_no_ext[] = preg_replace('/\.[^.]+$/', '', basename((string) $sz_data['file']));
                }
            }
            // Un-scaled original (when WP scaled at upload time)
            if (function_exists('wp_get_original_image_path')) {
                $orig_path = (string) wp_get_original_image_path($att_id);
                if ($orig_path !== '') {
                    $candidates_no_ext[] = preg_replace('/\.[^.]+$/', '', basename($orig_path));
                }
            }
            $candidates_no_ext = array_filter(array_unique($candidates_no_ext));

            // Prune stale ic_local_variants entries that don't belong to this
            // attachment. These came from an earlier glob over-match, e.g.
            // image-12's un-scaled basename greedily matching image-18's
            // `-4-Nw.avif` files. For each existing entry, verify its filename
            // matches one of THIS attachment's candidate basenames exactly:
            // either the same basename, or the `<basename>-<digits>w` /
            // `<basename>-<digits>x<digits>` adaptive form. Drop it otherwise.
            $existing = function_exists('get_post_meta')
                ? get_post_meta($att_id, 'ic_local_variants', true)
                : [];
            if (is_array($existing) && !empty($existing)) {
                $kept_variants = [];
                $pruned_keys = [];
                foreach ($existing as $vk => $ventry) {
                    if (empty($ventry['url'])) { $kept_variants[$vk] = $ventry; continue; }
                    $url_path = parse_url((string) $ventry['url'], PHP_URL_PATH);
                    if (!$url_path) { $kept_variants[$vk] = $ventry; continue; }
                    $file_no_ext = preg_replace('/\.[^.]+$/', '', basename($url_path));
                    $belongs = false;
                    foreach ($candidates_no_ext as $cand) {
                        if ($file_no_ext === $cand) { $belongs = true; break; }
                        $prefix = $cand . '-';
                        if (strpos($file_no_ext, $prefix) === 0) {
                            $suffix = substr($file_no_ext, strlen($prefix));
                            if (preg_match('/^\d+w$/', $suffix)
                                || preg_match('/^\d+x\d+$/', $suffix)) {
                                $belongs = true;
                                break;
                            }
                        }
                    }
                    if ($belongs) {
                        $kept_variants[$vk] = $ventry;
                    } else {
                        $pruned_keys[] = $vk;
                    }
                }
                if (!empty($pruned_keys)) {
                    update_post_meta($att_id, 'ic_local_variants', $kept_variants);
                }
            }

            // origin_url is the main file's URL: the canonical site-host form
            // is what write_postmeta expects.
            $origin_url = $baseurl . '/' . ltrim($main_rel, '/');

            $written = 0;
            $entries = [];
            foreach (['avif', 'webp'] as $ext) {
                foreach ($candidates_no_ext as $no_ext) {
                    $candidate_path = $att_dir . '/' . $no_ext . '.' . $ext;
                    if (!@is_file($candidate_path)) continue;
                    $size_bytes = (int) @filesize($candidate_path);
                    if ($size_bytes <= 0) continue;
                    // Also look for adaptive-maximized siblings under
                    // <no_ext>-{N}w.{ext} and <no_ext>-{W}x{H}.{ext}. Globbing
                    // once outside this inner loop would be cleaner, but the
                    // candidate count is small (<=20) so the cost is negligible.
                    // write_postmeta is idempotent, so re-runs are harmless.
                    $ok = wpc_v2_lazy_cdn_write_postmeta(
                        $origin_url,
                        $candidate_path,
                        $size_bytes,
                        ($ext === 'jpg' ? 'jpeg' : $ext),
                        $att_id
                    );
                    if ($ok) {
                        $written++;
                        $entries[] = basename($candidate_path);
                    }
                }
                // Adaptive-maximized: glob <basename>-*w.{ext} and -*x*.{ext}
                // for any extras the lazy_cdn fallback created. Iterate ALL
                // candidate basenames (scaled, un-scaled, sub-size bases), not
                // just the first. Adaptive files from use_original=on land under
                // the UN-scaled basename, not the scaled one, so a glob against
                // only the first candidate (typically the scaled basename)
                // misses them.
                $seen_paths = [];
                foreach ($candidates_no_ext as $no_ext) {
                    if (!$no_ext) continue;
                    $glob_patterns = [
                        $att_dir . '/' . $no_ext . '-*w.' . $ext,
                        $att_dir . '/' . $no_ext . '-*x*.' . $ext,
                    ];
                    foreach ($glob_patterns as $pat) {
                        $hits = glob($pat);
                        if (!is_array($hits)) continue;
                        foreach ($hits as $candidate_path) {
                            if (isset($seen_paths[$candidate_path])) continue;
                            $seen_paths[$candidate_path] = true;
                            // Strict suffix validation. Glob `<base>-*w.{ext}`
                            // also matches `<base>-4-3020w.{ext}` (greedy *),
                            // which actually belongs to a different attachment
                            // carrying a `-4` duplicate suffix. Require the part
                            // after `<base>-` to be EXACTLY `<digits>w` or
                            // `<digits>x<digits>`, with no extra hyphens.
                            $cand_base    = basename($candidate_path);
                            $cand_no_ext  = preg_replace('/\.[^.]+$/', '', $cand_base);
                            $expected_pfx = $no_ext . '-';
                            if (strpos($cand_no_ext, $expected_pfx) !== 0) continue;
                            $suffix = substr($cand_no_ext, strlen($expected_pfx));
                            if (!preg_match('/^\d+w$/', $suffix)
                                && !preg_match('/^\d+x\d+$/', $suffix)) {
                                continue; // belongs to different attachment
                            }
                            if (!@is_file($candidate_path)) continue;
                            $size_bytes = (int) @filesize($candidate_path);
                            if ($size_bytes <= 0) continue;
                            $ok = wpc_v2_lazy_cdn_write_postmeta(
                                $origin_url,
                                $candidate_path,
                                $size_bytes,
                                ($ext === 'jpg' ? 'jpeg' : $ext),
                                $att_id
                            );
                            if ($ok) {
                                $written++;
                                $entries[] = basename($candidate_path);
                            }
                        }
                    }
                }
            }

            $total_written += $written;
            $report[$att_id] = [
                'attachment_id' => $att_id,
                'entries_written' => $written,
                'files' => $entries,
            ];
        }

        wp_send_json_success([
            'total_entries_written' => $total_written,
            'per_attachment'        => $report,
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_backfill_postmeta',        'wpc_v2_ajax_lazy_backfill_postmeta');
add_action('wp_ajax_nopriv_wpc_v2_lazy_backfill_postmeta', 'wpc_v2_ajax_lazy_backfill_postmeta');

/**
 * Disk + postmeta inspector. Pass ?image_id=N to get:
 *   - ic_local_variants postmeta (what plugin THINKS exists)
 *   - directory listing of the image's uploads subdir (what REALLY exists)
 *   - per-sub-size disk-presence check for .avif/.webp siblings
 */
if (!function_exists('wpc_v2_ajax_lazy_inspect_disk')) {
    function wpc_v2_ajax_lazy_inspect_disk()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }

        $image_id = isset($_REQUEST['image_id']) ? (int) $_REQUEST['image_id'] : 0;
        $out = [];

        $upload_dir = wp_get_upload_dir();
        $out['upload_basedir'] = $upload_dir['basedir'];

        if ($image_id > 0) {
            $variants = get_post_meta($image_id, 'ic_local_variants', true);
            $out['ic_local_variants'] = $variants;

            $attached_file = get_post_meta($image_id, '_wp_attached_file', true);
            $out['_wp_attached_file'] = $attached_file;

            $meta = wp_get_attachment_metadata($image_id);
            $out['attachment_metadata_summary'] = [
                'file' => isset($meta['file']) ? $meta['file'] : null,
                'width' => isset($meta['width']) ? $meta['width'] : null,
                'height' => isset($meta['height']) ? $meta['height'] : null,
                'sizes_keys' => isset($meta['sizes']) ? array_keys($meta['sizes']) : [],
            ];

            $subdir = $attached_file ? dirname($attached_file) : '';
            $abs_dir = $upload_dir['basedir'] . '/' . $subdir;
            $out['abs_dir'] = $abs_dir;
            $out['abs_dir_exists'] = is_dir($abs_dir);

            if (is_dir($abs_dir)) {
                $entries = scandir($abs_dir);
                $entries = array_values(array_filter($entries, function ($e) {
                    return $e !== '.' && $e !== '..';
                }));
                $out['directory_listing'] = $entries;

                // Per sub-size: does .avif sibling exist? .webp sibling?
                $per_size = [];
                if (isset($meta['sizes'])) {
                    foreach ($meta['sizes'] as $size_name => $size_data) {
                        if (empty($size_data['file'])) continue;
                        $base_filename = (string) $size_data['file'];
                        $base_no_ext = preg_replace('/\.[^.]+$/', '', $base_filename);
                        $webp_disk = $abs_dir . '/' . $base_no_ext . '.webp';
                        $avif_disk = $abs_dir . '/' . $base_no_ext . '.avif';
                        $per_size[$size_name] = [
                            'jpg_basename' => $base_filename,
                            'jpg_exists' => file_exists($abs_dir . '/' . $base_filename),
                            'webp_path' => $webp_disk,
                            'webp_exists' => file_exists($webp_disk),
                            'avif_exists' => file_exists($avif_disk),
                            'width' => isset($size_data['width']) ? (int) $size_data['width'] : 0,
                        ];
                    }
                }
                $out['per_size_disk_check'] = $per_size;
            }
        }

        wp_send_json_success($out);
    }
}
add_action('wp_ajax_wpc_v2_lazy_inspect_disk',        'wpc_v2_ajax_lazy_inspect_disk');
add_action('wp_ajax_nopriv_wpc_v2_lazy_inspect_disk', 'wpc_v2_ajax_lazy_inspect_disk');

/**
 * Cache layer / plugin inspector — what's listening for purges?
 */
if (!function_exists('wpc_v2_ajax_lazy_inspect_cache_layer')) {
    function wpc_v2_ajax_lazy_inspect_cache_layer()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        global $wp_filter;

        $out = [];
        $out['active_plugins'] = (array) get_option('active_plugins', []);

        $out['mu_plugins'] = [];
        $mu_dir = WPMU_PLUGIN_DIR;
        if (is_dir($mu_dir)) {
            foreach (scandir($mu_dir) as $f) {
                if (substr($f, -4) === '.php') {
                    $out['mu_plugins'][] = $f;
                }
            }
        }

        $hooks_to_check = [
            'clean_post_cache', 'save_post', 'transition_post_status',
            'w3tc_flush_post', 'rocket_clean_post', 'wp_cache_post_change',
            'litespeed_purge_post', 'cf_clear_post',
        ];
        $hook_listeners = [];
        foreach ($hooks_to_check as $h) {
            if (isset($wp_filter[$h]) && is_object($wp_filter[$h])) {
                $callbacks = [];
                foreach ($wp_filter[$h]->callbacks as $priority => $listeners) {
                    foreach ($listeners as $listener) {
                        $cb = $listener['function'];
                        if (is_string($cb)) $callbacks[] = "p={$priority} {$cb}";
                        elseif (is_array($cb) && isset($cb[0], $cb[1])) {
                            $cls = is_object($cb[0]) ? get_class($cb[0]) : (string) $cb[0];
                            $callbacks[] = "p={$priority} {$cls}::{$cb[1]}";
                        }
                        elseif ($cb instanceof \Closure) $callbacks[] = "p={$priority} <Closure>";
                    }
                }
                $hook_listeners[$h] = $callbacks;
            } else {
                $hook_listeners[$h] = [];
            }
        }
        $out['hook_listeners'] = $hook_listeners;

        $out['breeze_class_exists']      = class_exists('\\Breeze_Admin');
        $out['breeze_post_purge_exists'] = function_exists('breeze_post_purge');
        $out['rocket_clean_post_exists'] = function_exists('rocket_clean_post');
        $out['wp_cache_post_change_exists'] = function_exists('wp_cache_post_change');

        // Capture Varnish-related headers from a local request to check.
        $out['varnish_seen_via_localhost'] = null;
        if (function_exists('wp_remote_head')) {
            $r = wp_remote_head(home_url('/sample-page/'), ['timeout' => 5, 'sslverify' => false]);
            if (!is_wp_error($r)) {
                $headers_obj = wp_remote_retrieve_headers($r);
                $hdrs = [];
                if (is_object($headers_obj) && method_exists($headers_obj, 'getAll')) {
                    foreach (['x-cache', 'age', 'x-varnish', 'cache-control', 'via', 'server'] as $k) {
                        $v = $headers_obj[$k] ?? null;
                        if ($v !== null) $hdrs[$k] = is_array($v) ? implode(', ', $v) : (string) $v;
                    }
                }
                $out['varnish_seen_via_localhost'] = ['code' => wp_remote_retrieve_response_code($r), 'headers' => $hdrs];
            } else {
                $out['varnish_seen_via_localhost'] = ['error' => $r->get_error_message()];
            }
        }

        wp_send_json_success($out);
    }
}
add_action('wp_ajax_wpc_v2_lazy_inspect_cache_layer',        'wpc_v2_ajax_lazy_inspect_cache_layer');
add_action('wp_ajax_nopriv_wpc_v2_lazy_inspect_cache_layer', 'wpc_v2_ajax_lazy_inspect_cache_layer');

/**
 * Force /v2/config sync with current state. Used to push the new wake_url
 * field to orch without needing the admin UI toggle.
 */
if (!function_exists('wpc_v2_ajax_lazy_force_config_sync')) {
    function wpc_v2_ajax_lazy_force_config_sync()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        if (!function_exists('wpc_v2_config_sync_lazy_enabled')) {
            wp_send_json_error(['msg' => 'config_sync_helper_missing'], 500);
        }
        if (!function_exists('wpc_v2_get_zone_id')) {
            wp_send_json_error(['msg' => 'zone_id_helper_missing'], 500);
        }
        $zone_id = wpc_v2_get_zone_id();
        if ($zone_id === '') {
            wp_send_json_error(['msg' => 'no_zone_id'], 400);
        }
        // Sync the STRATEGY-derived lazy_cdn state, the single source of truth,
        // not the dead per-zone flag. Reading the stale flag here could push the
        // orch a lazy_cdn_active that contradicts wpc_optimization_mode, the
        // exact split that was removed. wpc_v2_get_lazy_enabled() now resolves to
        // (mode === 'lazy_cdn' && zone).
        $cur = function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled();
        $result = wpc_v2_config_sync_lazy_enabled($zone_id, $cur);
        wp_send_json_success([
            'zone_id' => $zone_id,
            'lazy_enabled' => $cur,
            'wake_url' => function_exists('rest_url') ? rest_url('wpc/v2/wake') : '',
            'sync_result' => $result,
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_force_config_sync',        'wpc_v2_ajax_lazy_force_config_sync');
add_action('wp_ajax_nopriv_wpc_v2_lazy_force_config_sync', 'wpc_v2_ajax_lazy_force_config_sync');

/**
 * Force pull-drain fire bypassing the 5-min page-load-poll throttle.
 * Extends drain_alive_until_ms first so the loop actually polls.
 */
if (!function_exists('wpc_v2_ajax_lazy_force_drain')) {
    function wpc_v2_ajax_lazy_force_drain()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        if (!function_exists('wpc_v2_pull_drain_fire')) {
            wp_send_json_error(['msg' => 'pull_drain_helper_missing'], 500);
        }
        // Extend deadline so loop actually polls
        $now_ms = (int) (microtime(true) * 1000);
        $target = $now_ms + 60000;
        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        $current = (int) get_option('wpc_v2_drain_alive_until_ms', 0);
        if ($target > $current) {
            update_option('wpc_v2_drain_alive_until_ms', $target, false);
        }
        // Bypass throttle too
        delete_option('wpc_v2_last_pull_check_ms');
        $dispatched = (bool) wpc_v2_pull_drain_fire();
        wp_send_json_success([
            'dispatched' => $dispatched,
            'deadline_now_ms' => $target,
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_force_drain',        'wpc_v2_ajax_lazy_force_drain');
add_action('wp_ajax_nopriv_wpc_v2_lazy_force_drain', 'wpc_v2_ajax_lazy_force_drain');


// ─── Diagnostic wrapper for orch /admin/recent-manifest-writes ──────────────
//
// Wraps the orch admin ring buffer (manifest-write events, 2000-cap / 7d TTL,
// per the orch v3.18.96 spec). This lets plugin-side T2 logs be correlated
// against T1 (manifest-write-completed-at-Redis) without the operator having to
// know the admin STATS_KEY or the orch hostname.
//
// The orch supports these server-side filters: n, apikey_hash, since, result.
// Per-event fields (imageID, sizeLabel, format, trace_id, via) are NOT
// server-filterable, so we pull then filter client-side here.
//
// Auth: same WPC_LAZY_TEST_KEY apikey gate as the other lazy_* endpoints.
// Stats key: read from option `wpc_v2_orch_admin_stats_key` (must be set
// out-of-band by operator: `wp option update wpc_v2_orch_admin_stats_key <key>`).
//
// Caveats surfaced in response:
//   - trimmed_likely=true when oldest returned ts > since-floor (means buffer
//     was rotated and our window is a sample, not census).
//   - For high-volume apikeys, supply since=<ms> + n=500 and accept sampling.
//
// Request params (POST or GET):
//   n               default 50, max 500
//   apikey_hash     default sha256(apikey)[0..16] — auto from local apikey
//   since           ms epoch, optional — narrow the window
//   result          one of ok|failed|threw|no_handlers|init_failed
//   imageID         client-side filter on events[].imageID
//   sizeLabel       client-side filter on events[].sizeLabel
//   format          client-side filter on events[].format (avif|webp|jpg)
//   trace_id        client-side filter on events[].trace_id
//   exclude_dedup   "1" to drop events with via=dedup_republish (legit silent
//                   skips per spec — usually noise for race-detection)
//   orch_host       override orch hostname (default reads option
//                   wpc_v2_orch_admin_host or falls back to first known
//                   region; operator should set out-of-band).
//
// Returns:
//   { ok, orch_url, status, returned_raw, returned_filtered,
//     trimmed_likely, oldest_ts, newest_ts, now_ms,
//     summary: { ok, failed, threw }, events: [...] }
if (!function_exists('wpc_v2_ajax_lazy_check_orch_writes')) {
    function wpc_v2_ajax_lazy_check_orch_writes()
    {
        if (!wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }

        $stats_key = (string) get_option('wpc_v2_orch_admin_stats_key', '');
        if ($stats_key === '') {
            wp_send_json_error([
                'msg' => 'admin_stats_key_not_configured',
                'fix' => "wp option update wpc_v2_orch_admin_stats_key '<orch-STATS_KEY>'",
            ], 503);
        }

        $orch_host = isset($_REQUEST['orch_host']) && $_REQUEST['orch_host'] !== ''
            ? (string) $_REQUEST['orch_host']
            : (string) get_option('wpc_v2_orch_admin_host', '');
        if ($orch_host === '') {
            wp_send_json_error([
                'msg' => 'orch_host_not_configured',
                'fix' => "wp option update wpc_v2_orch_admin_host '<orch.region.host>' (or pass ?orch_host=)",
            ], 503);
        }
        // Strip any scheme the operator pasted in.
        $orch_host = preg_replace('#^https?://#', '', $orch_host);
        $orch_host = trim($orch_host, "/ \t");

        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $auto_hash = $apikey !== '' ? substr(hash('sha256', $apikey), 0, 16) : '';

        $n = isset($_REQUEST['n']) ? max(1, min(500, (int) $_REQUEST['n'])) : 50;

        // Build server-side query string with only the allowlisted params.
        $server_params = ['key' => $stats_key, 'n' => $n];
        $server_params['apikey_hash'] = isset($_REQUEST['apikey_hash']) && $_REQUEST['apikey_hash'] !== ''
            ? (string) $_REQUEST['apikey_hash']
            : $auto_hash;
        if (isset($_REQUEST['since']) && $_REQUEST['since'] !== '') {
            $server_params['since'] = (int) $_REQUEST['since'];
        }
        if (isset($_REQUEST['result']) && $_REQUEST['result'] !== '') {
            $server_params['result'] = (string) $_REQUEST['result'];
        }

        $orch_url = 'https://' . $orch_host . '/admin/recent-manifest-writes?' . http_build_query($server_params);

        $resp = wp_remote_get($orch_url, [
            'timeout'   => 8,
            'sslverify' => true,
        ]);
        if (is_wp_error($resp)) {
            wp_send_json_error([
                'msg'      => 'orch_request_failed',
                'orch_url' => preg_replace('/key=[^&]+/', 'key=***', $orch_url),
                'wp_error' => $resp->get_error_message(),
            ], 502);
        }
        $status = (int) wp_remote_retrieve_response_code($resp);
        $body   = (string) wp_remote_retrieve_body($resp);
        $parsed = json_decode($body, true);

        if ($status !== 200 || !is_array($parsed)) {
            wp_send_json_error([
                'msg'         => 'orch_bad_response',
                'orch_url'    => preg_replace('/key=[^&]+/', 'key=***', $orch_url),
                'status'      => $status,
                'body_excerpt' => substr($body, 0, 400),
            ], 502);
        }

        $events_raw = isset($parsed['events']) && is_array($parsed['events']) ? $parsed['events'] : [];

        // Client-side filters: the orch v3.18.96 spec doesn't support these server-side.
        $f_imageID   = isset($_REQUEST['imageID'])   ? (string) $_REQUEST['imageID']   : '';
        $f_sizeLabel = isset($_REQUEST['sizeLabel']) ? (string) $_REQUEST['sizeLabel'] : '';
        $f_format    = isset($_REQUEST['format'])    ? (string) $_REQUEST['format']    : '';
        $f_trace_id  = isset($_REQUEST['trace_id'])  ? (string) $_REQUEST['trace_id']  : '';
        $f_excl_ddp  = !empty($_REQUEST['exclude_dedup']);

        $events = [];
        foreach ($events_raw as $ev) {
            if (!is_array($ev)) continue;
            if ($f_imageID   !== '' && (string) ($ev['imageID']   ?? '') !== $f_imageID)   continue;
            if ($f_sizeLabel !== '' && (string) ($ev['sizeLabel'] ?? '') !== $f_sizeLabel) continue;
            if ($f_format    !== '' && (string) ($ev['format']    ?? '') !== $f_format)    continue;
            if ($f_trace_id  !== '' && (string) ($ev['trace_id']  ?? '') !== $f_trace_id)  continue;
            if ($f_excl_ddp  && (string) ($ev['via'] ?? '') === 'dedup_republish')         continue;
            $events[] = $ev;
        }

        // Trim-detection: if caller supplied `since`, buffer is "complete" only
        // when oldest_ts <= since (i.e. the window was fully covered). If
        // oldest_ts > since, the ring rotated past our floor — sample, not census.
        $oldest_ts = !empty($events_raw) ? (int) ($events_raw[count($events_raw) - 1]['ts'] ?? 0) : 0;
        $newest_ts = !empty($events_raw) ? (int) ($events_raw[0]['ts'] ?? 0) : 0;
        $since_arg = isset($server_params['since']) ? (int) $server_params['since'] : 0;
        $trimmed_likely = $since_arg > 0 && $oldest_ts > 0 && $oldest_ts > $since_arg;

        wp_send_json_success([
            'orch_url'          => preg_replace('/key=[^&]+/', 'key=***', $orch_url),
            'status'            => $status,
            'returned_raw'      => count($events_raw),
            'returned_filtered' => count($events),
            'trimmed_likely'    => $trimmed_likely,
            'oldest_ts'         => $oldest_ts,
            'newest_ts'         => $newest_ts,
            'now_ms'            => (int) (microtime(true) * 1000),
            'summary'           => isset($parsed['summary']) ? $parsed['summary'] : null,
            'orch_filters'      => isset($parsed['filters']) ? $parsed['filters'] : null,
            'events'            => $events,
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_check_orch_writes',        'wpc_v2_ajax_lazy_check_orch_writes');
add_action('wp_ajax_nopriv_wpc_v2_lazy_check_orch_writes', 'wpc_v2_ajax_lazy_check_orch_writes');
