<?php
global $ic_running;
global $wps_ic_cdn_instance;


if (!defined('WPC_ERROR_CAPTURE_DISABLED')) {
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        try {
            // Honor the @-operator: what the code suppressed is not logged. PHP 8 no longer sets
            // error_reporting() to 0 under @, it masks it (4437: only fatal classes remain), so
            // the old `=== 0` test never matched and every suppressed warning was logged
            // (webdesign4u.com.au, 2026-09-28: 50 @file_get_contents misses on CleanTalk's REST
            // route filled wpc_error_debug_log). A level outside the current mask is suppressed.
            if (!(error_reporting() & $errno)) {
                return false;
            }
            // ONLY capture errors from our plugin directory — skip everything else
            if (strpos($errfile, 'wp-compress') === false) {
                return false;
            }
            $types = [E_WARNING => 'WARNING', E_NOTICE => 'NOTICE', E_DEPRECATED => 'DEPRECATED'];
            if (!isset($types[$errno])) {
                return false;
            }

            // Deduplicate: same file+line+message per request = skip
            static $seen = [];
            $key = $errfile . ':' . $errline . ':' . $errstr;
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            // Cap static array at 50 to prevent memory growth on long requests
            if (count($seen) > 50) {
                return false;
            }

            $log = get_option('wpc_error_debug_log', []);
            $log[] = date('Y-m-d H:i:s') . ' | ' . $types[$errno] . ' | ' . basename($errfile) . ':' . $errline . ' | ' . $errstr;
            update_option('wpc_error_debug_log', array_slice($log, -50), false);
        } catch (\Throwable $e) {
            // Never let the error handler itself cause issues
        }
        return false; // CRITICAL: always return false = PHP still handles error normally
    }, E_WARNING | E_NOTICE | E_DEPRECATED);
}


if (!function_exists('wpc_url_matches_pattern')) {
    function wpc_url_matches_pattern($url, $pattern) {
        $pattern = trim($pattern);
        if ($pattern === '' || $pattern[0] === '#') return false;

        // Strip leading slash for normalization (URL has host prefix, patterns may not)
        $pattern = ltrim($pattern, '/');

        // Wildcard pattern → build regex
        if (strpos($pattern, '*') !== false || strpos($pattern, '?') !== false) {
            // Escape regex meta chars first, then convert wildcards back
            $regex = preg_quote($pattern, '#');
            $regex = str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '.'], $regex);
            return (bool) @preg_match('#' . $regex . '#i', $url);
        }

        // No wildcards → case-insensitive substring match
        return stripos($url, $pattern) !== false;
    }
}

if (!function_exists('wpc_url_is_excluded')) {
    function wpc_url_is_excluded($currentUrl, $patterns) {
        if (empty($patterns) || !is_array($patterns)) return false;
        foreach ($patterns as $pattern) {
            if (wpc_url_matches_pattern($currentUrl, $pattern)) {
                return $pattern; // Return matched pattern for logging
            }
        }
        return false;
    }
}

/**
 * Diagnostic logger — info-level feature-tracking events surfaced in the Debug
 * Tool so customers can verify behavior without SSH. Writes to the
 * `wpc_diagnostic_log` option (capped at 100), deduped and per-tag sampled so a
 * high-image page can't flood it.
 */
if (!function_exists('wpc_diagnostic_log')) {
    function wpc_diagnostic_log($tag, $detail = '') {
        try {
            static $seen = [];
            static $tagCounts = [];
            static $buf = [];
            static $hooked = false;

            static $armed = null;
            if ($armed === null) {
                $armed = function_exists('get_option') && (int) get_option('wpc_diag_until', 0) >= time();
            }
            if (function_exists('apply_filters') ? !apply_filters('wpc_diagnostic_log_enabled', $armed) : !$armed) return;

            // Per-tag sample cap: only log first 5 of each tag per request
            $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            if ($tagCounts[$tag] > 5) return;

            // Per-request dedupe on exact tag+detail
            $key = $tag . '|' . $detail;
            if (isset($seen[$key])) return;
            $seen[$key] = true;

            // Hard memory cap
            if (count($seen) > 100) return;

            $buf[] = date('Y-m-d H:i:s') . ' | ' . $tag . ' | ' . $detail;

            if (!$hooked && function_exists('register_shutdown_function')) {
                $hooked = true;
                register_shutdown_function(function () use (&$buf) {
                    try {
                        if (empty($buf) || !function_exists('get_option')) return;
                        if (function_exists('is_admin') && !is_admin()
                            && function_exists('get_transient') && get_transient('wpc_diag_flush_lock')) {
                            return;
                        }
                        if (function_exists('set_transient')) set_transient('wpc_diag_flush_lock', 1, 60);
                        $log = get_option('wpc_diagnostic_log', []);
                        if (!is_array($log)) $log = [];
                        foreach ($buf as $line) { $log[] = $line; }
                        update_option('wpc_diagnostic_log', array_slice($log, -100), false);
                    } catch (\Throwable $e) {
                    }
                });
            }
        } catch (\Throwable $e) {
            // Never let diagnostic logging itself break things
        }
    }
}

/**
 * The diagnostic log's window. wpc_diagnostic_log() writes only while `wpc_diag_until` is in
 * the future, and these three functions are the only writers of that option: a person arms it
 * from the Debug tool (a POST with a nonce, manage_options), it closes itself when the window
 * ends, and the same tool or the upgrade pass switches it off. Observed (ticket 12006 follow-up):
 * the Debug tool's template wrote now + 7 days on every render of the settings page, for every
 * admin (the tab's content renders for everyone, only its link is hidden), so the log was armed
 * on every site whose settings page anyone opened, and each upgrade re-armed it for 72 h.
 */
if (!defined('WPC_DIAG_WINDOW_SECONDS')) {
    define('WPC_DIAG_WINDOW_SECONDS', 7 * 86400);
}
if (!function_exists('wpc_diag_window_arm')) {
    function wpc_diag_window_arm($user_id)
    {
        $until = time() + WPC_DIAG_WINDOW_SECONDS;
        update_option('wpc_diag_until', $until, true);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('diag-armed', '', '', ['until' => $until, 'by_user' => (int) $user_id]);
        }
        return $until;
    }
}
if (!function_exists('wpc_diag_window_disarm')) {
    /** Closes the window; logs only when it was open, so a pass that runs twice logs once. */
    function wpc_diag_window_disarm($reason)
    {
        $was = (int) get_option('wpc_diag_until', 0);
        if ($was <= 0) {
            return false;
        }
        delete_option('wpc_diag_until');
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('diag-disarmed', '', '', ['reason' => (string) $reason, 'was_until' => $was]);
        }
        return true;
    }
}
if (!function_exists('wpc_diag_window_request')) {
    /**
     * The Debug tool's switch (admin-post.php?action=wpc_diag_window): `window=on` arms, anything
     * else switches off. Answers 'armed', 'disarmed' or 'refused' (no manage_options, or no valid
     * nonce for the action 'wpc_diag_window').
     */
    function wpc_diag_window_request(array $post)
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')
            || empty($post['_wpnonce']) || !wp_verify_nonce((string) $post['_wpnonce'], 'wpc_diag_window')) {
            return 'refused';
        }
        if (isset($post['window']) && $post['window'] === 'on') {
            wpc_diag_window_arm(function_exists('get_current_user_id') ? get_current_user_id() : 0);
            return 'armed';
        }
        wpc_diag_window_disarm('debug-tool');
        return 'disarmed';
    }
}
if (function_exists('add_action')) {
    add_action('admin_post_wpc_diag_window', function () {
        if (wpc_diag_window_request($_POST) === 'refused') {
            wp_die(esc_html__('You do not have permission to perform this action.', 'wp-compress-image-optimizer'), 403);
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=wpcompress'));
        exit;
    });
}

include_once __DIR__ . '/debug.php';
include_once __DIR__ . '/defines.php';
include_once __DIR__ . '/addons/cache/wpc-fs.php';

if (!function_exists('wpc_crit_meta_write')) {
    // Mixed-tree belt: canonical writer lives in defines.php — a stale/truncated
    // defines.php degrades to a plain write here, never a fatal (law 10).
    function wpc_crit_meta_write($path, $value)
    {
        try {
            return wpc_fs_put($path, (string) $value) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

include_once WPS_IC_DIR . 'addons/cdn/cdn-rewrite.php';
include_once WPS_IC_DIR . 'classes/speculation_rules.class.php';
include_once WPS_IC_DIR . 'addons/cdn/modern-delivery.php';
include_once WPS_IC_DIR . 'addons/cdn/delivery-resolver.php';
include_once WPS_IC_DIR . 'addons/cdn/corp-guard.php';
include_once WPS_IC_DIR . 'addons/cdn/fast404-guard.php';
include_once WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php';
include_once WPS_IC_DIR . 'addons/legacy/compress.php';
include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';


include_once WPS_IC_DIR . 'addons/v2/v2-bootstrap.php';


include_once WPS_IC_DIR . 'addons/v2/v2-natural-url-buffer.php';

//TRAITS
include WPS_IC_DIR . 'traits/agency.php';


/**
 * Get all locally-optimized attachment IDs as a flipped array for O(1) lookup.
 * Uses transient cache (5 min) + static cache per request.
 */
function wpc_get_local_optimized_ids() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = get_transient('wpc_local_optimized_ids');
    if ($cache !== false) return $cache;

    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ic_status' AND meta_value = 'compressed'"
    );
    $cache = array_flip($ids);
    set_transient('wpc_local_optimized_ids', $cache, 300);
    return $cache;
}

/**
 * Resolve an image URL to its WordPress attachment ID.
 * Strips size suffixes (-300x200) to find the base attachment.
 * Static cache per request to avoid repeated DB lookups.
 */
function wpc_url_to_attachment_id($url) {
    static $id_cache = [];

    // Normalize URL — strip query strings and fragments
    $clean_url = strtok($url, '?#');

    // Strip size suffix to get base URL (e.g., photo-300x200.jpg → photo.jpg)
    $base_url = preg_replace('/-\d+x\d+(?=\.\w{3,4}$)/', '', $clean_url);

    if (isset($id_cache[$base_url])) return $id_cache[$base_url];

    $id = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_att_id'))
        ? (int) wps_rewriteLogic::wpc_att_id($base_url)
        : attachment_url_to_postid($base_url);
    $id_cache[$base_url] = $id ?: false;
    return $id_cache[$base_url];
}

/**
 * Invalidate the local optimized IDs cache.
 * Call this whenever ic_status changes (optimize, restore, delete).
 */
function wpc_invalidate_local_cache() {
    delete_transient('wpc_local_optimized_ids');
}


function wpc_bulk_heartbeat_touch() {
    // 5 min TTL. Bulk actions are seconds apart (per image ~12s, per slice <=30s),
    // so this never lapses mid-run; once the driver dies it expires within 5 min.
    set_transient('wpc_bulk_heartbeat', time(), 300);
}


/**
 * The run flag as the bulk page and the settings pages read it: the run while one is active, false
 * otherwise. A run with no heartbeat for 5 minutes is one nothing advanced: a v2 compress run
 * with images left is carried by the server (the cron event and the loopback chain), so it is
 * resumed and stays shown as running, with receipt `bulk-drain-stalled {reason: no-heartbeat}`;
 * a run of another driver (the JS-driven sequential loop, restore) is ended as before.
 * Observed (ticket 12006, finde-online.de; reproduced on wpctest.hprime.eu): a run whose drain
 * had stalled was deleted by the first page load, which showed the start view while
 * wps_ic_isBulkRunning had just answered `compressing`, and orphaned its queue.
 */
function wpc_bulk_process_active() {
    $bp = get_option('wps_ic_bulk_process');
    if (empty($bp)) {
        return false;
    }
    if (get_transient('wpc_bulk_heartbeat')) {
        return $bp;
    }
    if (is_array($bp) && ($bp['driver'] ?? '') === 'v2' && ($bp['status'] ?? '') === 'compressing'
        && class_exists('wps_ic_ajax') && wps_ic_ajax::wpc_bulk_has_work()) {
        wps_ic_ajax::wpc_bulk_drain_stalled('no-heartbeat');
        wps_ic_ajax::wpc_bulk_v2_fire_loopback();
        return $bp;
    }
    // No heartbeat for the full TTL → the driver is dead. Mirror its terminal cleanup.
    delete_option('wps_ic_bulk_process');
    delete_transient('wps_ic_bulk_running');
    return false;
}

/**
 * Purge CDN cache for a specific image and all its thumbnails.
 * Calls the MC pod per-URL purge endpoint + Cloudflare purge if connected.
 * Non-blocking — does not slow down the restore flow.
 */
function wpc_purge_cdn_urls($attachment_id) {
    $options = get_option(WPS_IC_OPTIONS);
    if (empty($options['api_key'])) return;

    $path = get_post_meta($attachment_id, '_wp_attached_file', true);
    if (!$path) return;

    // Collect all URLs to purge: original + unscaled + all thumbnails
    $urls_to_purge = ["/wp-content/uploads/{$path}"];

    // Unscaled version (if exists)
    $unscaled_path = str_replace('-scaled.', '.', $path);
    if ($unscaled_path !== $path) {
        $urls_to_purge[] = "/wp-content/uploads/{$unscaled_path}";
    }

    // All thumbnail sizes
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!empty($metadata['sizes'])) {
        $base_dir = dirname($path);
        foreach ($metadata['sizes'] as $data) {
            $urls_to_purge[] = "/wp-content/uploads/{$base_dir}/{$data['file']}";
        }
    }

    // Also purge WebP/AVIF variants
    foreach ($urls_to_purge as $url) {
        $pathinfo = pathinfo($url);
        $webp = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '.webp';
        $avif = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '.avif';
        if (!in_array($webp, $urls_to_purge)) $urls_to_purge[] = $webp;
        if (!in_array($avif, $urls_to_purge)) $urls_to_purge[] = $avif;
    }

    // Purge MC pod cache — per-URL, non-blocking
    foreach ($urls_to_purge as $url) {
        wp_remote_get(
            "https://cdn-mc.zapwp.net/health/cache-purge?apikey=" . urlencode($options['api_key']) . "&url=" . urlencode($url),
            ['timeout' => 5, 'blocking' => false, 'sslverify' => false]
        );
    }


    $site_url = site_url();
    $ladder_widths = [150, 221, 300, 400, 442, 480, 640, 720, 755, 768, 800,
                      960, 1100, 1132, 1200, 1280, 1366, 1440, 1510, 1536,
                      1600, 1800, 1887, 2048, 2560];
    $cdn_zone = '';
    $custom_cname = get_option('ic_custom_cname');
    $cdn_zone = !empty($custom_cname) ? $custom_cname : (string) get_option('ic_cdn_zone_name');
    if ($cdn_zone !== '') {


        $u_hosts = ['https://' . $cdn_zone];
        if (rtrim($site_url, '/') !== rtrim($u_hosts[0], '/')) {
            $u_hosts[] = $site_url;
        }
        foreach ($urls_to_purge as $rel_url) {
            // Skip the WebP/AVIF derivatives — only purge transforms of the JPG/PNG originals
            // (cdn-mc keys transforms by the underlying source URL).
            if (preg_match('/\.(webp|avif)$/i', $rel_url)) continue;
            foreach ($u_hosts as $u_host_for_purge) {
                $full_u = $u_host_for_purge . $rel_url;
                foreach ([0, 1, 2] as $wp_fmt) {
                    foreach ($ladder_widths as $w) {
                        $transform_path = '/q:i/r:0/wp:' . $wp_fmt . '/w:' . $w . '/u:' . $full_u;
                        wp_remote_get(
                            "https://cdn-mc.zapwp.net/health/cache-purge?apikey=" . urlencode($options['api_key']) . "&url=" . urlencode($transform_path),
                            ['timeout' => 5, 'blocking' => false, 'sslverify' => false]
                        );
                    }
                }
            }
        }
    }

    // Purge Cloudflare (if connected)
    $cf = get_option(WPS_IC_CF);
    if (!empty($cf['token']) && !empty($cf['zone'])) {
        $site_url = site_url();
        $full_urls = array_map(function($u) use ($site_url) {
            return $site_url . $u;
        }, $urls_to_purge);
        if (class_exists('WPC_CloudflareAPI')) {
            $cfsdk = new WPC_CloudflareAPI($cf['token']);
            $cfsdk->purgeFiles($cf['zone'], $full_urls);
        }
    }
}


function wpc_purge_cdn_urls_single($attachment_id, $abs_path) {
    $options = get_option(WPS_IC_OPTIONS);
    if (empty($options['api_key'])) return;
    if (!is_string($abs_path) || $abs_path === '') return;

    // Convert absolute path → /wp-content/uploads/<rel> URL path.
    $uploads = wp_upload_dir();
    $basedir = isset($uploads['basedir']) ? $uploads['basedir'] : (WP_CONTENT_DIR . '/uploads');
    $basedir = rtrim($basedir, '/');
    if (strpos($abs_path, $basedir) !== 0) return;
    $rel  = ltrim(substr($abs_path, strlen($basedir)), '/');
    if ($rel === '') return;
    $url  = '/wp-content/uploads/' . $rel;

    // MC pod purge — non-blocking single GET.
    wp_remote_get(
        "https://cdn-mc.zapwp.net/health/cache-purge?apikey=" . urlencode($options['api_key']) . "&url=" . urlencode($url),
        ['timeout' => 5, 'blocking' => false, 'sslverify' => false]
    );

    // Cloudflare zone purge — single URL only.
    $cf = get_option(WPS_IC_CF);
    if (!empty($cf['token']) && !empty($cf['zone']) && class_exists('WPC_CloudflareAPI')) {
        $cfsdk = new WPC_CloudflareAPI($cf['token']);
        $cfsdk->purgeFiles($cf['zone'], [site_url() . $url]);
    }

    error_log(sprintf(
        '[WPC PurgeSingle] imageID=%d url=%s',
        (int) $attachment_id,
        $url
    ));
}

/**
 * Get whitelabel support URL. Checks WL plugin header, then $whtlbl global, then default.
 */
function wpc_get_whitelabel_url($fallback = 'https://www.wpcompress.com/') {
    static $cached = null;
    if ($cached !== null) return $cached;

    if (class_exists('wps_ic') && !empty(wps_ic::$slug)) {
        $wl_file = WP_PLUGIN_DIR . '/' . wps_ic::$slug . '/whitelabel.php';
        if (file_exists($wl_file)) {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $wl_data = get_plugin_data($wl_file, false, false);
            if (!empty($wl_data['AuthorURI'])) {
                $cached = $wl_data['AuthorURI'];
                return $cached;
            }
        }
    }

    global $whtlbl;
    if (isset($whtlbl) && property_exists($whtlbl, 'author_url') && !empty($whtlbl->author_url)) {
        $cached = $whtlbl->author_url;
        return $cached;
    }

    $cached = $fallback;
    return $cached;
}

/**
 * White-label asset mirror re-sync. The WL companion copies our admin JS/CSS into its
 * files/ dir only when a file is MISSING (copy_file_if_needed) — never on update — so
 * WL admin pages run assets frozen at WL-activation day (dead settings buttons class).
 * One scan per plugin-version change, admin requests only, fail-open.
 */
function wpc_wl_mirror_sync() {
    if (!class_exists('whtlbl_whitelabel_plugin') || !defined('WHITE_LABEL_DIR')) {
        return;
    }
    $ver = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '0';
    if (get_option('wpc_wl_mirror_ver') === $ver) {
        return;
    }
    $mirror = rtrim((string) WHITE_LABEL_DIR, '/') . '/files/';
    if (!@is_dir($mirror)) {
        update_option('wpc_wl_mirror_ver', $ver);
        return;
    }
    // v4 wins basename collisions (scripts.js exists in v2 AND v4; WL admin runs v4).
    $sourceByName = [];
    // v7.21.255 — assets/js/dist/ was missing from this map: the WL mirror served the
    // frontend pixel frozen at WL-activation day (no wpcLWS/__wpcPixelAlive on wpwarp
    // sites -> the never-blank belt thought the pixel dead and restored below-fold
    // images at 5s on every no-gesture lab run).
    foreach (['assets/v4/js/', 'assets/v4/css/', 'assets/js/admin/', 'assets/js/dist/', 'assets/css/', 'assets/js/'] as $assetDir) {
        foreach (['*.js', '*.css'] as $pattern) {
            foreach ((array) @glob(WPS_IC_DIR . $assetDir . $pattern) as $pluginFile) {
                $basename = basename((string) $pluginFile);
                if ($basename !== '' && !isset($sourceByName[$basename])) {
                    $sourceByName[$basename] = $pluginFile;
                }
            }
        }
    }
    $copied = 0;
    foreach (['*.js', '*.css'] as $pattern) {
        foreach ((array) @glob($mirror . $pattern) as $mirrorFile) {
            $basename = basename((string) $mirrorFile);
            if (!isset($sourceByName[$basename])) {
                continue;
            }
            $sourceFile = $sourceByName[$basename];
            if (@filesize($sourceFile) === @filesize($mirrorFile) && (int) @filemtime($mirrorFile) >= (int) @filemtime($sourceFile)) {
                continue;
            }
            if (@copy($sourceFile, $mirrorFile)) {
                $copied++;
            }
        }
    }
    update_option('wpc_wl_mirror_ver', $ver);
    if ($copied && function_exists('wpc_cache_first_log')) {
        wpc_cache_first_log('wl-mirror-refresh', '', '', ['n' => $copied, 'ver' => $ver]);
    }
}
add_action('admin_init', 'wpc_wl_mirror_sync');

/** v7.21.257 — land the missing-variant .htaccess rung once per plugin version (admin requests only, fail-open). */
function wpc_sync_variant_fallback_rewrite() {
    $ver = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '0';
    if (get_option('wpc_mvf_ver') === $ver) {
        return;
    }
    try {
        if (!class_exists('wps_ic_htaccess')) {
            @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
        }
        if (class_exists('wps_ic_htaccess') && method_exists('wps_ic_htaccess', 'applyMissingVariantFallback')) {
            $installed = (new wps_ic_htaccess())->applyMissingVariantFallback();
            // The rung answers in Apache, where no receipt can be written; whether this version
            // has it at all is decided here, once per version, so that is what is logged.
            if (function_exists('wpc_belt_receipt')) {
                wpc_belt_receipt('variant-fallback-sync', ['ok' => $installed ? 1 : 0, 'ver' => (string) $ver], false, '');
            }
        }
    } catch (\Throwable $e) {
    }
    update_option('wpc_mvf_ver', $ver);
}
add_action('admin_init', 'wpc_sync_variant_fallback_rewrite');

/** v7.21.267 — VIEWPORT LAZY IS THE DEFAULT (James, 08-28). One-time seed: a site whose
 *  lazy intent is already declared (nativeLazy on) but still on native-only gets Viewport
 *  mode — native lazy fetches near-viewport images in every lab run (huge Chrome
 *  threshold) while Viewport parks them behind four verified restore paths. ONE stamp,
 *  never per-version: a user who deliberately flips back stays flipped. Lazy-off sites
 *  untouched; Safe Mode preset unchanged. */
function wpc_seed_viewport_lazy_mode() {
    if (get_option('wpc_vl_seed267')) {
        return;
    }
    update_option('wpc_vl_seed267', 1, false);
    try {
        $s = get_option(WPS_IC_SETTINGS);
        if (is_array($s) && !empty($s['nativeLazy']) && $s['nativeLazy'] == '1'
            && (empty($s['lazy']) || $s['lazy'] != '1')
            && apply_filters('wpc_viewport_lazy_default', true)) {
            $s['lazy'] = '1';
            update_option(WPS_IC_SETTINGS, $s);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('viewport-lazy-seeded', '', '', []);
            }
        }
    } catch (\Throwable $e) {
    }
}
add_action('admin_init', 'wpc_seed_viewport_lazy_mode');

/**
 * Get the whitelabel-aware plugin display name.
 * Reads from the Settings submenu (which whitelabel plugins override), falls back to 'WP Compress'.
 */
function wpc_get_plugin_name() {
    static $cached = null;
    if ($cached !== null) return $cached;

    if (class_exists('whtlbl_whitelabel_plugin')) {
        try {
            $whitelabelClass = new ReflectionClass('whtlbl_whitelabel_plugin');
            $whitelabelDefaults = $whitelabelClass->getDefaultProperties();
            if (!empty($whitelabelDefaults['whitelabel_menu_name']) && is_string($whitelabelDefaults['whitelabel_menu_name'])) {
                $cached = wp_strip_all_tags($whitelabelDefaults['whitelabel_menu_name']);
                return $cached;
            }
        } catch (Throwable $e) {
        }
    }

    global $submenu;
    if (isset($submenu['options-general.php']) && class_exists('wps_ic')) {
        foreach ($submenu['options-general.php'] as $item) {
            if (isset($item[2]) && $item[2] === wps_ic::$slug) {
                $cached = wp_strip_all_tags($item[0]);
                return $cached;
            }
        }
    }

    $whitelabelName = function_exists('get_option') ? get_option('wpc_wl_menu_name') : '';
    if (is_string($whitelabelName) && $whitelabelName !== '') {
        $cached = $whitelabelName;
        return $cached;
    }

    $cached = __('WP Compress', 'wp-compress-image-optimizer');
    return $cached;
}


if (!function_exists('wpc_settings_page_url')) {
    function wpc_settings_page_url($wpc_extra = '') {
        $wpc_slug = (class_exists('wps_ic') && !empty(wps_ic::$slug)) ? wps_ic::$slug : 'wpcompress';
        $wpc_base = 'options-general.php';
        $wpc_cap  = 'manage_wpc_settings';
        global $menu, $submenu;
        $wpc_hit = false;
        if (!empty($submenu['options-general.php']) && is_array($submenu['options-general.php'])) {
            foreach ($submenu['options-general.php'] as $wpc_it) {
                if (isset($wpc_it[1], $wpc_it[2]) && $wpc_it[1] === $wpc_cap && strpos((string) $wpc_it[2], '-mu') === false) {
                    $wpc_slug = $wpc_it[2]; $wpc_base = 'options-general.php'; $wpc_hit = true; break;
                }
            }
        }
        if (!$wpc_hit && !empty($menu) && is_array($menu)) {
            foreach ($menu as $wpc_it) {
                if (isset($wpc_it[1], $wpc_it[2]) && $wpc_it[1] === $wpc_cap && strpos((string) $wpc_it[2], '-mu') === false) {
                    $wpc_slug = $wpc_it[2]; $wpc_base = 'admin.php'; $wpc_hit = true; break;
                }
            }
        }
        return admin_url($wpc_base . '?page=' . $wpc_slug . $wpc_extra);
    }
}


function wpc_v2_rewrite_img_to_natural_urls($img_tag, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url) {
    if (empty($cdn_zone) || empty($img_tag)) return $img_tag;
    if (strpos($img_tag, $cdn_zone) === false) return $img_tag;

    // Attributes that may contain URLs. Each is handled with srcset-awareness.
    $attrs = [
        ['name' => 'src',         'is_srcset' => false],
        ['name' => 'srcset',      'is_srcset' => true],
        ['name' => 'data-src',    'is_srcset' => false],
        ['name' => 'data-srcset', 'is_srcset' => true],
    ];

    foreach ($attrs as $a) {
        $name = $a['name'];
        // Match attr="value" — accommodating values containing : (URLs do).
        if (!preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $img_tag, $m)) continue;
        $original_value = $m[1];
        if ($original_value === '') continue;

        $new_value = $a['is_srcset']
            ? wpc_v2_rewrite_srcset_value($original_value, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url)
            : wpc_v2_rewrite_single_url_to_natural($original_value, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url);

        if ($new_value !== $original_value) {
            // Replace only the first match to avoid collisions across attrs.
            $img_tag = preg_replace(
                '/(\b' . preg_quote($name, '/') . '\s*=\s*")' . preg_quote($original_value, '/') . '(")/i',
                '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $new_value) . '$2',
                $img_tag,
                1
            );
        }
    }

    return $img_tag;
}

function wpc_v2_rewrite_srcset_value($srcset, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url) {
    if (empty($srcset)) return $srcset;
    $entries = explode(',', $srcset);
    foreach ($entries as &$entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        // Entry is "URL [descriptor]" — split on the first whitespace so URLs
        // with embedded colons stay intact.
        if (preg_match('/^(\S+)(\s+.+)?$/', $entry, $em)) {
            $url = $em[1];
            $descriptor = isset($em[2]) ? $em[2] : '';
            $new_url = wpc_v2_rewrite_single_url_to_natural($url, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url);
            $entry = $new_url . $descriptor;
        }
    }
    unset($entry);
    return implode(', ', $entries);
}

function wpc_v2_rewrite_single_url_to_natural($url, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url) {
    if (empty($url) || empty($cdn_zone)) return $url;

    // Only rewrite our own CDN zone's transform URLs. External hosts pass through.
    if (strpos($url, $cdn_zone) === false) return $url;


    if (!preg_match('#/(?:u:|m:0/a:)(https?://[^\s]+)$#', $url, $m)) return $url;

    $origin_url = $m[1];
    $origin_clean = preg_replace('/\?.*$/', '', $origin_url);
    $query = '';
    if (strpos($origin_url, '?') !== false) {
        $query = substr($origin_url, strpos($origin_url, '?'));
    }

    // Map origin URL → disk path. Try uploads first (most common), then
    // any path under site_url.
    $disk = null;
    if ($upload_baseurl && strpos($origin_clean, $upload_baseurl) === 0) {
        $relative = substr($origin_clean, strlen($upload_baseurl));
        $disk = rtrim($upload_basedir, '/\\') . '/' . ltrim($relative, '/');
    } elseif ($site_url && strpos($origin_clean, $site_url) === 0) {
        $relative = substr($origin_clean, strlen(rtrim($site_url, '/')));
        $disk = rtrim(ABSPATH, '/\\') . '/' . ltrim($relative, '/');
    }

    if ($disk === null || !@file_exists($disk)) {
        // No mapping or file missing — leave the transform URL as fallback.
        return $url;
    }

    // Build natural URL via CDN passthrough.
    $path_after_site = str_replace($site_url, '', $origin_clean);
    return 'https://' . $cdn_zone . $path_after_site . $query;
}


if (!function_exists('wpc_picture_should_inject_lazy')) {

function wpc_picture_should_inject_lazy($img_tag, $settings)
{


    if (isset($settings['nativeLazy']) && (string) $settings['nativeLazy'] !== '1') {
        return (bool) apply_filters('wpc_picture_inject_lazy', false, $img_tag, $settings);
    }
    // Don't double-inject if loading= is already present
    if (preg_match('/\sloading\s*=\s*["\'][^"\']*["\']/i', $img_tag)) {
        return (bool) apply_filters('wpc_picture_inject_lazy', false, $img_tag, $settings);
    }


    $is_eager_lcp = wpc_picture_is_eager_lcp_marker($img_tag);
    return (bool) apply_filters('wpc_picture_inject_lazy', !$is_eager_lcp, $img_tag, $settings);
}
}

if (!function_exists('wpc_picture_is_eager_lcp_marker')) {

function wpc_picture_is_eager_lcp_marker($img_tag)
{
    // If the IMG is already lazy, leave sizes alone — auto will work correctly
    if (preg_match('/\sloading\s*=\s*["\']lazy["\']/i', $img_tag)) return false;

    // Fast path: explicit eager-LCP signals when they happen to be present
    if (preg_match('/fetchpriority\s*=\s*["\']high["\']/i', $img_tag)) return true;

    // Structural signal: wide image (width ≥ 1200) with 100vw in sizes
    if (!preg_match('/\swidth\s*=\s*["\'](\d+)["\']/i', $img_tag, $wm)) return false;
    if ((int) $wm[1] < 1200) return false;
    if (!preg_match('/\ssizes\s*=\s*["\']([^"\']*)["\']/i', $img_tag, $sm)) return false;
    return (stripos($sm[1], '100vw') !== false);
}
}

if (!function_exists('wpc_picture_compute_lcp_sizes')) {

if (!function_exists('wpc_get_theme_content_width')) {

function wpc_get_theme_content_width()
{
    $w = 0;
    if (function_exists('wp_get_global_settings')) {
        $layout = wp_get_global_settings(['layout']);
        foreach (['wideSize', 'contentSize'] as $layout_key) {
            if (!empty($layout[$layout_key])) {
                $px = (int) preg_replace('/[^0-9]/', '', (string) $layout[$layout_key]);
                if ($px >= 320 && $px <= 2000) { $w = $px; break; }
            }
        }
    }
    if ($w === 0 && !empty($GLOBALS['content_width']) && (int) $GLOBALS['content_width'] >= 320) {
        $w = (int) $GLOBALS['content_width'];
    }
    return (int) apply_filters('wpc_lcp_content_width', $w);
}
}

/**
 * The `sizes` the <picture> lane writes on an eager LCP image: '' (keep what the tag has)
 * unless the tag has none or carries the capped ladder this plugin used to print, in which
 * case the image's own-width ladder. The rule and the customer case (acrystalglass.com, a
 * ~2,000 px hero served the 640 rung) are on wps_ic_atf_observation::fallback_sizes().
 */
function wpc_picture_compute_lcp_sizes($img_tag)
{
    if (!wpc_picture_is_eager_lcp_marker($img_tag) || !class_exists('wps_ic_atf_observation')) {
        return '';
    }
    $pageSizes = preg_match('/\ssizes\s*=\s*["\']([^"\']*)["\']/i', $img_tag, $sm) ? trim($sm[1]) : '';
    $widthAttr = preg_match('/\swidth\s*=\s*["\'](\d+)["\']/i', $img_tag, $wm) ? $wm[1] : '';
    $srcset = preg_match('/\ssrcset\s*=\s*["\']([^"\']*)["\']/i', $img_tag, $ssm) ? $ssm[1] : '';
    if ($pageSizes !== '') {
        $replaced = wps_ic_atf_observation::replace_retired_capped_ladder($pageSizes, $widthAttr, $srcset);
        return $replaced !== $pageSizes ? $replaced : '';
    }
    return wps_ic_atf_observation::fallback_sizes($widthAttr, $srcset);
}
}

if (!function_exists('wpc_picture_apply_sizes_to_img')) {
/**
 * Replace (or add) the sizes= attribute on an IMG tag string.
 *
 * @return string updated IMG tag
 */
function wpc_picture_apply_sizes_to_img($img_tag, $sizes_value)
{
    if (preg_match('/\ssizes\s*=\s*["\'][^"\']*["\']/i', $img_tag)) {
        return preg_replace(
            '/\ssizes\s*=\s*["\'][^"\']*["\']/i',
            ' sizes="' . $sizes_value . '"',
            $img_tag, 1
        );
    }
    return preg_replace('/<img\b/i', '<img sizes="' . $sizes_value . '"', $img_tag, 1);
}
}

/**
 * Wrap locally-optimized <img> tags in <picture> elements with WebP/AVIF sources.
 * Runs on the_content filter at low priority (after other plugins).
 */
function wpc_inject_picture_tags($content) {
    if (is_admin() || empty($content)) return $content;


    if (function_exists('is_feed') && is_feed()) return $content;
    if (function_exists('is_amp_endpoint') && is_amp_endpoint()) return $content;
    if (function_exists('amp_is_request') && amp_is_request()) return $content;
    if (defined('REST_REQUEST') && REST_REQUEST) {
        $wpc_route = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if (strpos($wpc_route, '/wp/v2/block-renderer/') === false) return $content;
    }


    if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) {
        return $content;
    }

    // Respect "Use Picture Tags" toggle — same setting controls CDN and local mode
    $settings = get_option(WPS_IC_SETTINGS);
    if (empty($settings['picture_webp']) || $settings['picture_webp'] != '1') return $content;


    $wpc_avif_ok = !class_exists('WPC_Delivery_Resolver')
                   || WPC_Delivery_Resolver::effective_ceiling($settings) === 'avif';


    $wpc_webp_ok = !class_exists('WPC_Delivery_Resolver')
                   || WPC_Delivery_Resolver::effective_ceiling($settings) !== 'off';


    $wpc_cdn_imgs_on = !class_exists('WPC_Negotiated_Delivery')
                       || WPC_Negotiated_Delivery::cdn_images_enabled($settings);
    if (!empty($settings['live-cdn']) && (string) $settings['live-cdn'] === '1' && $wpc_cdn_imgs_on) {
        return $content;
    }

    // Guard against double-wrapping (caching plugins, REST, nested filters)
    if (strpos($content, 'wpc-picture') !== false) return $content;

    $optimized = wpc_get_local_optimized_ids();
    if (empty($optimized)) return $content;

    // Stash existing <picture> blocks (restored after) so we don't nest ours
    // inside a third-party one (Performance Lab, ShortPixel, etc.)
    $picture_placeholders = [];
    $content = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$picture_placeholders) {
        $key = '<!--WPC_PICTURE_' . count($picture_placeholders) . '-->';
        $picture_placeholders[$key] = $m[0];
        return $key;
    }, $content);

    // Pre-resolve disk roots once; reused in the callback.
    $upload_dir_for_rewrite = wp_get_upload_dir();
    $upload_basedir_for_rewrite = isset($upload_dir_for_rewrite['basedir']) ? $upload_dir_for_rewrite['basedir'] : '';
    $upload_baseurl_for_rewrite = isset($upload_dir_for_rewrite['baseurl']) ? $upload_dir_for_rewrite['baseurl'] : '';
    $site_url_for_rewrite = site_url();

    // Match <img> tags with wp-image-{ID} class (WordPress standard)
    $content = preg_replace_callback(
        '/<img\b[^>]*class="[^"]*wp-image-(\d+)[^"]*"[^>]*>/i',
        function ($matches) use ($optimized, $cdn_zone, $upload_basedir_for_rewrite, $upload_baseurl_for_rewrite, $site_url_for_rewrite, $settings, $wpc_avif_ok, $wpc_webp_ok) {
            $img_tag = $matches[0];
            $attachment_id = (int) $matches[1];

            if (!isset($optimized[$attachment_id])) return $img_tag;

            // Skip SVG, GIF, ICO — matches CDN behavior
            if (preg_match('/\.(svg|gif|ico)[\s"\'?]/i', $img_tag)) return $img_tag;


            if ($cdn_zone) {
                $img_tag = wpc_v2_rewrite_img_to_natural_urls(
                    $img_tag,
                    $cdn_zone,
                    $upload_basedir_for_rewrite,
                    $upload_baseurl_for_rewrite,
                    $site_url_for_rewrite
                );
            }

            $variants = get_post_meta($attachment_id, 'ic_local_variants', true);
            if (empty($variants) || !is_array($variants)) return $img_tag;

            // Use data-srcset for lazy-loaded imgs (matches CDN behavior)
            $srcsetAttr = (strpos($img_tag, 'data-srcset=') !== false) ? 'data-srcset' : 'srcset';

            // Build srcset per format
            $webp_srcset = [];
            $avif_srcset = [];

            // Get upload directory info for building local URLs from filenames
            $upload_dir = wp_get_upload_dir();
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            $upload_subdir = $attached_file ? dirname($attached_file) : '';
            $upload_basedir = isset($upload_dir['basedir']) ? $upload_dir['basedir'] : '';

            foreach ($variants as $label => $data) {
                if (empty($data['url'])) continue;

                // Extract width from filename: -WIDTHxHEIGHT.ext or -scaled.ext
                $filename = basename($data['url']);
                $width = 0;
                if (preg_match('/-(\d+)x\d+\.\w+$/', $filename, $wm)) {
                    $width = (int) $wm[1];
                } elseif (preg_match('/-scaled\.\w+$/', $filename)) {
                    $width = 2560;
                } elseif ($label === 'unscaled-webp' || $label === 'unscaled-avif' || $label === 'unscaled') {
                    $meta = wp_get_attachment_metadata($attachment_id);
                    $width = !empty($meta['width']) ? (int) $meta['width'] : 4000;
                }
                if ($width <= 0) continue;


                $disk_path = $upload_basedir . '/' . $upload_subdir . '/' . $filename;
                if (!@file_exists($disk_path)) {
                    continue;
                }

                // Dimensional validity gate (disable via WPC_SKIP_PICTURE_VARIANT_VALIDATION).
                // A next-gen <source> is type-pinned with NO onerror, so one


                if (!defined('WPC_SKIP_PICTURE_VARIANT_VALIDATION') || !WPC_SKIP_PICTURE_VARIANT_VALIDATION) {
                    // (1) Filename-only — no decode → catches -1x1 / -Nx<=2 / -<=2xN.
                    if (preg_match('/-(\d+)x(\d+)\.\w+$/', $filename, $dm)
                        && ((int) $dm[1] <= 2 || (int) $dm[2] <= 2)) {
                        continue;
                    }
                    // (2) Byte-validation — only when getimagesize() decodes the file.
                    $vdims = @getimagesize($disk_path);
                    if (is_array($vdims) && !empty($vdims[0]) && !empty($vdims[1])) {
                        $real_w = (int) $vdims[0];
                        $real_h = (int) $vdims[1];
                        if ($real_w <= 2 || $real_h <= 2) {
                            continue;
                        }


                        if ($width > 0 && abs($real_w - $width) > max(8, (int) ($width * 0.10))) {
                            continue;
                        }
                    }
                }

                // Build local URL (postmeta URLs are service download URLs, not local paths)
                $local_url = $upload_dir['baseurl'] . '/' . $upload_subdir . '/' . $filename;

                // If CDN active, serve via the CDN natural URL (edge passthrough)
                if ($cdn_zone) {
                    $local_url = 'https://' . $cdn_zone . str_replace(site_url(), '', $local_url);
                }
                $entry = esc_url($local_url) . ' ' . $width . 'w';

                if (strpos($label, '-avif') !== false) {
                    $avif_srcset[$width] = $entry;
                } elseif (strpos($label, '-webp') !== false) {
                    $webp_srcset[$width] = $entry;
                }
            }


            if (function_exists('wpc_v2_sized_trigger_queue')
                && function_exists('wpc_get_theme_content_width')
                && !preg_match('/\b(alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $img_tag)) {
                $pa_cap = (int) wpc_get_theme_content_width();
                if ($pa_cap > 0) {
                    $pa_existing = array_keys($webp_srcset + $avif_srcset);
                    // Per-image targets from this tag's own sizes attribute;
                    // content-width model as fallback for sizes-less tags.
                    $pa_sizes  = preg_match('/sizes="([^"]*)"/i', $img_tag, $pa_sm) ? $pa_sm[1] : '';
                    $pa_targets = function_exists('wpc_v2_ideal_targets_from_sizes')
                        ? wpc_v2_ideal_targets_from_sizes($pa_sizes, $pa_cap)
                        : array_unique([(int) round(206 * 1.75), 412, (int) round(206 * 3), $pa_cap, (int) round($pa_cap * 1.75), $pa_cap * 2]);
                    foreach ($pa_targets as $pa_t) {
                        if ($pa_t < 200) continue;


                        $pa_near = false;
                        foreach ($pa_existing as $pa_e) {
                            if ($pa_e >= $pa_t && ($pa_e - $pa_t) / $pa_t < 0.08) { $pa_near = true; break; }
                        }
                        if (!$pa_near) {
                            wpc_v2_sized_trigger_queue($attachment_id, $pa_t, $pa_t);
                            $pa_existing[] = $pa_t;
                        }
                    }
                }
            }


            $lazy_enabled_for_optimistic = function_exists('wpc_v2_get_lazy_enabled')
                                            && wpc_v2_get_lazy_enabled();


            $cdn_live_for_optimistic = !empty($settings['live-cdn']) && (string) $settings['live-cdn'] === '1';
            if ($lazy_enabled_for_optimistic && $cdn_live_for_optimistic && $cdn_zone) {
                $meta_for_lazy = wp_get_attachment_metadata($attachment_id);
                if (is_array($meta_for_lazy) && !empty($meta_for_lazy['sizes'])) {
                    // $upload_basedir already declared at the top of this filter
                    foreach ($meta_for_lazy['sizes'] as $size_name => $size_data) {
                        if (empty($size_data['file']) || empty($size_data['width'])) continue;
                        $w = (int) $size_data['width'];
                        if ($w <= 0) continue;

                        // Only emit if this width isn't already covered by
                        // ic_local_variants. Real variants take precedence.
                        $needs_webp = !isset($webp_srcset[$w]);
                        $needs_avif = !isset($avif_srcset[$w]);
                        if (!$needs_webp && !$needs_avif) continue;

                        $base_filename = (string) $size_data['file'];
                        $base_no_ext   = preg_replace('/\.[^.]+$/', '', $base_filename);
                        if ($base_no_ext === '' || $base_no_ext === null) continue;

                        // Origin sub-size JPG URL (always exists — WP generates these)
                        $jpg_origin_url = $upload_dir['baseurl'] . '/' . $upload_subdir . '/' . $base_filename;

                        // Disk paths for file_exists() check
                        $webp_disk = $upload_basedir . '/' . $upload_subdir . '/' . $base_no_ext . '.webp';
                        $avif_disk = $upload_basedir . '/' . $upload_subdir . '/' . $base_no_ext . '.avif';

                        if ($needs_webp) {
                            if (file_exists($webp_disk)) {
                                // Variant landed — use natural URL (CDN serves directly)
                                $webp_url = 'https://' . $cdn_zone . str_replace(site_url(), '', $upload_dir['baseurl']) . '/' . $upload_subdir . '/' . $base_no_ext . '.webp';
                            } else {
                                // Not yet on disk — CDN transforms JPG→WebP on-the-fly
                                $webp_url = 'https://' . $cdn_zone . '/q:i/r:0/wp:1/w:' . $w . '/u:' . $jpg_origin_url;
                            }
                            $webp_srcset[$w] = esc_url($webp_url) . ' ' . $w . 'w';
                        }
                        if ($needs_avif) {
                            if (file_exists($avif_disk)) {
                                // Variant landed — use natural URL (CDN serves directly)
                                $avif_url = 'https://' . $cdn_zone . str_replace(site_url(), '', $upload_dir['baseurl']) . '/' . $upload_subdir . '/' . $base_no_ext . '.avif';
                            } else {
                                // Not on disk — CDN serves WebP placeholder, encodes
                                // AVIF async; lazy_cdn lands the natural file later.
                                $avif_url = 'https://' . $cdn_zone . '/q:i/r:0/wp:2/w:' . $w . '/u:' . $jpg_origin_url;
                            }
                            $avif_srcset[$w] = esc_url($avif_url) . ' ' . $w . 'w';
                        }
                    }
                    // Also add the full-size (unscaled) AVIF/WebP if metadata has it.
                    if (!empty($meta_for_lazy['file']) && !empty($meta_for_lazy['width'])) {
                        $w = (int) $meta_for_lazy['width'];
                        if ($w > 0 && (!isset($avif_srcset[$w]) || !isset($webp_srcset[$w]))) {
                            $parent_file = basename((string) $meta_for_lazy['file']);
                            $parent_no_ext = preg_replace('/\.[^.]+$/', '', $parent_file);
                            if ($parent_no_ext !== '' && $parent_no_ext !== null) {
                                $jpg_full_url = $upload_dir['baseurl'] . '/' . $upload_subdir . '/' . $parent_file;
                                $webp_full_disk = $upload_basedir . '/' . $upload_subdir . '/' . $parent_no_ext . '.webp';
                                $avif_full_disk = $upload_basedir . '/' . $upload_subdir . '/' . $parent_no_ext . '.avif';

                                if (!isset($webp_srcset[$w])) {
                                    if (file_exists($webp_full_disk)) {
                                        $webp_full = 'https://' . $cdn_zone . str_replace(site_url(), '', $upload_dir['baseurl']) . '/' . $upload_subdir . '/' . $parent_no_ext . '.webp';
                                    } else {
                                        $webp_full = 'https://' . $cdn_zone . '/q:i/r:0/wp:1/w:' . $w . '/u:' . $jpg_full_url;
                                    }
                                    $webp_srcset[$w] = esc_url($webp_full) . ' ' . $w . 'w';
                                }
                                if (!isset($avif_srcset[$w])) {
                                    if (file_exists($avif_full_disk)) {
                                        $avif_full = 'https://' . $cdn_zone . str_replace(site_url(), '', $upload_dir['baseurl']) . '/' . $upload_subdir . '/' . $parent_no_ext . '.avif';
                                    } else {
                                        $avif_full = 'https://' . $cdn_zone . '/q:i/r:0/wp:2/w:' . $w . '/u:' . $jpg_full_url;
                                    }
                                    $avif_srcset[$w] = esc_url($avif_full) . ' ' . $w . 'w';
                                }
                            }
                        }
                    }
                }
            }

            if (empty($webp_srcset) && empty($avif_srcset)) return $img_tag;


            $lazy_enabled_for_uni_ladder = function_exists('wpc_v2_get_lazy_enabled')
                                            && wpc_v2_get_lazy_enabled();
            if ($lazy_enabled_for_uni_ladder && $cdn_zone && is_array($meta_for_lazy)) {
                $maxW_uni = !empty($settings['maxWidth']) ? (int) $settings['maxWidth'] : 2560;
                if ($maxW_uni < 100) $maxW_uni = 2560;
                $effective_max_uni = $maxW_uni;
                if (!empty($meta_for_lazy['width']) && !empty($meta_for_lazy['height'])) {
                    $sw_uni = (int) $meta_for_lazy['width'];
                    $sh_uni = (int) $meta_for_lazy['height'];
                    if ($sh_uni > $sw_uni && $sh_uni > 0) {
                        $effective_max_uni = (int) floor($maxW_uni * ($sw_uni / $sh_uni));
                    }
                }
                // Base ladder + retina doubles of any width already in srcset
                $ladder_uni = [400, 480, 640, 720, 800, 960, 1100, 1200, 1280, 1366, 1440, 1600, 1800, 2048, 2560];
                foreach (array_merge(array_keys($webp_srcset), array_keys($avif_srcset)) as $existing_w) {
                    $ladder_uni[] = (int) $existing_w * 2;
                }
                // Mobile srcset cap applied at final assembly below, so it covers
                // widths added by every loop, not just this one.
                $ladder_uni = array_values(array_unique(array_map(function ($w) use ($effective_max_uni) {
                    return min($w, $effective_max_uni);
                }, $ladder_uni)));
                sort($ladder_uni);

                // u: base = the unscaled original (highest-quality encoder source).
                $orig_u_url_uni = '';
                if (function_exists('wp_get_original_image_url') && function_exists('wp_get_original_image_path')) {
                    $orig_url_try = wp_get_original_image_url($attachment_id);
                    $orig_path_try = wp_get_original_image_path($attachment_id);
                    if ($orig_url_try && $orig_path_try && @file_exists($orig_path_try)) {
                        $orig_u_url_uni = $orig_url_try;
                    }
                }
                $u_base_uni = $orig_u_url_uni !== ''
                    ? $orig_u_url_uni
                    : ($upload_dir['baseurl'] . '/' . $upload_subdir . '/' . basename((string) $meta_for_lazy['file']));
                $base_no_ext_uni = preg_replace('/\.(jpe?g|png|webp)$/i', '', $u_base_uni);

                foreach ($ladder_uni as $w_uni) {
                    if ($w_uni <= 0) continue;
                    // Skip widths already covered by both formats.
                    if (isset($webp_srcset[$w_uni]) && isset($avif_srcset[$w_uni])) continue;

                    // AVIF entry
                    if (!isset($avif_srcset[$w_uni])) {
                        $natural_avif = $base_no_ext_uni . '-' . $w_uni . 'w.avif';
                        $natural_avif_disk = str_replace(trailingslashit($upload_dir['baseurl']), trailingslashit($upload_basedir) . '', $natural_avif);
                        if (@file_exists($natural_avif_disk)) {
                            $avif_url = 'https://' . $cdn_zone . str_replace(site_url(), '', $natural_avif);
                        } else {
                            $u_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . $cdn_zone, $u_base_uni);
                            $avif_url = 'https://' . $cdn_zone . '/q:i/r:0/wp:2/w:' . $w_uni . '/u:' . $u_via_cdn;
                        }
                        $avif_srcset[$w_uni] = esc_url($avif_url) . ' ' . $w_uni . 'w';
                    }
                    // WebP entry
                    if (!isset($webp_srcset[$w_uni])) {
                        $natural_webp = $base_no_ext_uni . '-' . $w_uni . 'w.webp';
                        $natural_webp_disk = str_replace(trailingslashit($upload_dir['baseurl']), trailingslashit($upload_basedir) . '', $natural_webp);
                        if (@file_exists($natural_webp_disk)) {
                            $webp_url = 'https://' . $cdn_zone . str_replace(site_url(), '', $natural_webp);
                        } else {
                            $u_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . $cdn_zone, $u_base_uni);
                            $webp_url = 'https://' . $cdn_zone . '/q:i/r:0/wp:1/w:' . $w_uni . '/u:' . $u_via_cdn;
                        }
                        $webp_srcset[$w_uni] = esc_url($webp_url) . ' ' . $w_uni . 'w';
                    }
                }
            }

            // Activate sizes="auto" via lazy injection + smart LCP sizes override
            // (see the helper docblocks above for the why).
            if (wpc_picture_should_inject_lazy($img_tag, $settings)) {
                $img_tag = preg_replace('/<img\b/i', '<img loading="lazy"', $img_tag, 1);
                if (function_exists('wpc_diagnostic_log')) {
                    wpc_diagnostic_log('PICTURE_LAZY',
                        'injected loading=lazy on non-LCP IMG id=' . (int) $matches[1]);
                }
            }
            $smart_lcp_sizes = wpc_picture_compute_lcp_sizes($img_tag);
            if ($smart_lcp_sizes !== '') {
                $img_tag = wpc_picture_apply_sizes_to_img($img_tag, $smart_lcp_sizes);
                if (function_exists('wpc_diagnostic_log')) {
                    wpc_diagnostic_log('PICTURE_LCP_SIZES',
                        'override id=' . (int) $matches[1] . ' sizes="' . $smart_lcp_sizes . '"');
                }
            }

            // Extract sizes from <img> tag, pass through to <source>
            $sizes = '100vw';
            if (preg_match('/sizes="([^"]*)"/', $img_tag, $sz)) {
                $sizes = $sz[1];
            }


            $img_is_lazy_for_auto = (stripos($img_tag, 'loading="lazy"') !== false);
            if ($img_is_lazy_for_auto && stripos($sizes, 'auto') === false) {
                $sizes = 'auto, ' . $sizes;
            }


            $is_mobile_for_cap = class_exists('wps_ic_rewriteLogic')
                ? (bool) wps_ic_rewriteLogic::$isMobile
                : (function_exists('wp_is_mobile') && wp_is_mobile());
            $is_adaptive_for_cap = !empty($settings['generate_adaptive'])
                && (string) $settings['generate_adaptive'] === '1';
            if ($is_mobile_for_cap && $is_adaptive_for_cap) {
                $mob_cap_final = (int) apply_filters('wpc_mobile_srcset_cap',
                    (int) get_option('wpc-min-mobile-width', 400),
                    isset($img_tag) ? (string) $img_tag : '');
                if ($mob_cap_final > 0) {
                    $avif_srcset = array_filter($avif_srcset, function ($_, $w) use ($mob_cap_final) {
                        return (int) $w <= $mob_cap_final;
                    }, ARRAY_FILTER_USE_BOTH);
                    $webp_srcset = array_filter($webp_srcset, function ($_, $w) use ($mob_cap_final) {
                        return (int) $w <= $mob_cap_final;
                    }, ARRAY_FILTER_USE_BOTH);
                }
            }

            $sources = '';
            if ($wpc_avif_ok && !empty($avif_srcset)) {
                ksort($avif_srcset);
                $sources .= '<source type="image/avif" ' . $srcsetAttr . '="' . implode(', ', $avif_srcset) . '" sizes="' . esc_attr($sizes) . '">';
            }
            if ($wpc_webp_ok && !empty($webp_srcset)) {
                ksort($webp_srcset);
                $sources .= '<source type="image/webp" ' . $srcsetAttr . '="' . implode(', ', $webp_srcset) . '" sizes="' . esc_attr($sizes) . '">';
            }

            // No next-gen source → don't wrap in an empty <picture>; return the
            // plain <img> so the visitor still gets the optimized original.
            if ($sources === '') {
                return $img_tag;
            }
            return '<picture class="wpc-picture">' . $sources . $img_tag . '</picture>';
        },
        $content
    );

    // Restore protected <picture> blocks
    if (!empty($picture_placeholders)) {
        $content = str_replace(array_keys($picture_placeholders), array_values($picture_placeholders), $content);
    }

    return $content;
}
add_filter('the_content', 'wpc_inject_picture_tags', 999);

// Inline CSS for <picture> tags — inherit img dimensions, prevent layout shifts
function wpc_picture_tag_css() {
    $settings = get_option(WPS_IC_SETTINGS);
    if (empty($settings['picture_webp']) || $settings['picture_webp'] != '1') return;


    // v7.10.640 — mirrored pictures ([data-wpc-mir]) are EXCLUDED: display:contents
    // generates no paint box, so the visual state the .631 mirror moves onto the
    // wrapper (opacity/filter via the site's sibling selectors) can never render —
    // getComputedStyle reports it while the pixels stay unchanged (thepttv repeat,
    // James's eyes vs my computed-style receipts, 2026-07-31). A mirrored wrapper
    // must be a real box; unmirrored wrappers stay layout-transparent.
    echo '<style>.wpc-picture:not([data-wpc-mir]){display:contents;}</style>' . "\n";
}
add_action('wp_head', 'wpc_picture_tag_css', 1);


function wpc_no_404_guess_for_upload_images($do_guess)
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri !== '' && preg_match('#/wp-content/uploads/[^?]+\.(avif|webp|jpe?g|png|gif|svg|ico)(\?|$)#i', $uri)) {
        return false;
    }
    return $do_guess;
}
add_filter('do_redirect_guess_404_permalink', 'wpc_no_404_guess_for_upload_images');


function wpc_hard_404_for_upload_images()
{
    if (!function_exists('is_404') || !is_404()) {
        return;
    }


    $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = $uri !== '' ? (string) parse_url($uri, PHP_URL_PATH) : '';
    if ($path === '' || !preg_match('#\.(avif|webp|jpe?g|png|gif|svg|ico|bmp|tiff?)$#i', $path)) {
        return;
    }
    status_header(404);
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header('X-WPC-Fast-404: tr');
    echo 'Not Found';
    exit;
}
add_action('template_redirect', 'wpc_hard_404_for_upload_images', 1);


function wpc_early_404_for_missing_upload_images()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri === '' || !preg_match('#^/wp-content/uploads/([^?\#]+\.(?:avif|webp|jpe?g|png|gif|svg|ico))(?:[?\#]|$)#i', $uri, $m)) {
        return;
    }
    $rel = rawurldecode($m[1]);
    if (strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
        return;
    }
    // A missing -WxH rung must survive to template_redirect: wpc_v2_rung_intercept streams the
    // exact file or 302s to the nearest on-disk rung and queues the real one. This init-stage
    // guard predates the intercept and was answering first, leaving it unreachable.
    if (preg_match('/-\d+x\d+\.(?:avif|webp|jpe?g|png)$/i', $rel)
        && !(defined('WPC_RUNG_INTERCEPT_OFF') && WPC_RUNG_INTERCEPT_OFF)) {
        return;
    }
    $file = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content') . '/uploads/' . $rel;
    if (@file_exists($file)) {
        return;
    }
    status_header(404);
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}
add_action('init', 'wpc_early_404_for_missing_upload_images', 0);
add_action('wpc_upgrade_remote_lane', ['wps_ic', 'wpc_upgrade_remote_lane']);
add_action('admin_init', ['wps_ic_plan', 'admin_requests'], 5);
add_action('admin_init', ['wps_ic_plan', 'poll_if_due'], 6);

//CUSTOM_INCLUDE_HERE
spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'wps_ic_') !== false) {
        $class_nameBase = str_replace('wps_ic_', '', $class_name);
        $class_name = $class_nameBase . '.class.php';
        $class_name_underscore = str_replace('_', '-', $class_name);
        if (file_exists(WPS_IC_DIR . 'classes/' . $class_name)) {
            include_once __DIR__ . '/classes/' . $class_name;
        } elseif (file_exists(WPS_IC_DIR . 'classes/' . $class_name_underscore)) {
            include_once __DIR__ . '/classes/' . $class_name_underscore;
        } elseif (file_exists(WPS_IC_DIR . 'addons/' . $class_nameBase . '/' . $class_name)) {
            include_once __DIR__ . '/addons/' . $class_nameBase . '/' . $class_name;
        }
    }
});

if (!function_exists('wpc_caps_store')) {
    /**
     * v7.10.505 — DURABLE CAPABILITIES. The plan gate lived in 5-minute transients written ONLY on the
     * branch that restricts something. On an unrestricted plan the service sends no
     * packageConfiguration, the writer's "// Show all options" branch wrote NOTHING, and every reader
     * treats absence as DENIED — so a full-plan site locks itself out. Any cache flush did the same.
     *
     * Once a check succeeds the verdict is banked here and survives flushes, updates and cron
     * failures. It is revoked ONLY by a later successful check saying so — never by absence.
     * (A 5-minute transient was never an anti-abuse control: forging it is no harder than forging an
     * option. The real control is that the service decides and the plugin re-polls.)
     */
    function wpc_caps_store($caps, $unrestricted = false)
    {
        $rec = [
            'v'   => 1,
            't'   => time(),
            'un'  => $unrestricted ? 1 : 0,
            'caps' => [],
        ];
        foreach ((array) $caps as $k => $v) {
            $rec['caps'][(string) $k] = ((string) $v === '0') ? '0' : '1';
        }
        update_option('wpc_caps', $rec, false);
        // Keep the transients as the fast path so existing hot code stays hot.
        foreach ($rec['caps'] as $k => $v) {
            set_transient($k . 'Enabled', $v, 5 * 60);
        }
        return $rec;
    }
}

if (!function_exists('wpc_caps_enabled')) {
    /**
     * THE single capability reader. Order matters:
     *   agency            -> allowed (unchanged behaviour)
     *   explicit '0'      -> DENIED, whether from transient or the durable record
     *   unrestricted plan -> allowed
     *   known '1'         -> allowed
     *   never checked     -> DENIED (a site with no successful check gets nothing)
     */
    function wpc_caps_enabled($featureName)
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return true;
        }
        $name = (string) $featureName;

        // Fast path, but ONLY trustworthy as a positive/negative when actually present.
        $t = get_transient($name . 'Enabled');
        if ($t !== false && $t !== null) {
            return !((string) $t === '0');
        }

        $rec = get_option('wpc_caps');
        if (!is_array($rec) || empty($rec['t'])) {
            return false; // never had a successful check
        }
        if (isset($rec['caps'][$name])) {
            return ((string) $rec['caps'][$name] !== '0');
        }
        // Present in the record but not named => the plan does not restrict it.
        return !empty($rec['un']) || empty($rec['caps']);
    }
}

if (!function_exists('wpc_spawn_cron')) {
    /**
     * v7.10.506 — ONE throttled gateway for every cron loopback. There were 18 bare spawn_cron()
     * call sites, each able to turn a single visitor render into a second PHP request in the SAME
     * FPM pool. WordPress throttles spawn_cron() with the `doing_cron` TRANSIENT — but that is
     * object-cached, and our own purge fan-out flushes it, so the guard evaporates and every render
     * spawns again. Receipted on wpcompress.com: three wp-cron.php loopbacks inside two seconds on
     * the visitor lane, with boot 81-380ms and tpl 105-403ms but 31-61 SECOND total requests —
     * time spent queued for a worker, not computing (load 0.6, mem 52M).
     *
     * The floor is a durable OPTION precisely because the thing that breaks WP's guard is losing
     * the object cache. Scheduled events still run: WP fires due events on the next request anyway;
     * spawn_cron() is only an accelerator, never the delivery mechanism.
     */
    function wpc_spawn_cron($ctx = '')
    {
        if (!function_exists('spawn_cron') || !apply_filters('wpc_spawn_cron_on', true)) {
            return false;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return false; // never loopback from inside cron
        }
        $min  = (int) apply_filters('wpc_spawn_cron_min_interval', 60);
        $last = (int) get_option('wpc_cron_spawn_at', 0);
        $now  = time();
        if ($min > 0 && ($now - $last) < $min) {
            return false;
        }
        update_option('wpc_cron_spawn_at', $now, false);
        spawn_cron();
        return true;
    }
}

class wps_ic
{
    use wps_ic_agency_trait;

    public static $slug;
    public static $version;

    public static $api_key;
    public static $response_key;

    public static $settings;
    public static $zone_name;
    public static $quality;
    public static $options;
    public static $js_debug;
    public static $debug;
    public static $local;
    public static $media_lib_ajax;
    private static $accountStatus;
    public $integrations;
    public $upgrader;
    public $cache;
    public $cacheLogic;
    public $remote_restore;
    public $comms;
    public $notices;
    public $enqueues;
    public $templates;
    public $menu;
    public $ajax;
    public $media_library;
    public $compress;
    public $controller;
    public $log;
    public $bulk;
    public $queue;
    public $stats;
    public $cdn;
    public $mu;
    public $mainwp;
    public $offloading;
    public static $accStatusChecked;
    protected $excludes_class;

    /**
     * Our main class constructor
     */
    public function __construct()
    {
        global $wps_ic;
        self::debug_log('Constructor');

        // Basic plugin info
        self::$slug = 'wpcompress';
        self::$version = '7.25.00';

        $development = get_option('wps_ic_development');
        if (!empty($development) && $development == 'true') {
            // A debug toggle must never become a standing production storm: the flag
            // self-expires after 24h, and while on, the version busts at most every
            // 10 minutes (a per-second version churns version-keyed work every request).
            $wpc_dev_seen = (int) get_option('wpc_dev_flag_seen');
            if (!$wpc_dev_seen) {
                $wpc_dev_seen = time();
                update_option('wpc_dev_flag_seen', $wpc_dev_seen, false);
            }
            if (time() - $wpc_dev_seen > 86400) {
                delete_option('wps_ic_development');
                delete_option('wpc_dev_flag_seen');
            } else {
                self::$version = (string) (600 * (int) floor(time() / 600));
            }
        }

        $wps_ic = $this;
        self::$accStatusChecked = false;


        // Load translations
        load_plugin_textdomain('wp-compress-image-optimizer', false, dirname(plugin_basename(WPC_CC_PLUGIN_FILE)) . '/langs');

        if ((!empty($_GET['wpc_visitor_mode']) && sanitize_text_field($_GET['wpc_visitor_mode']))) {
            //It has to be here, init() is too late
            new wps_ic_visitor_mode();
        }


        if (!empty($_GET['preload_mode'])) {
            die('Preloaded');
        }

        $isPostConnectivityTest = isset($_POST['action']) && sanitize_text_field($_POST['action']) === 'connectivityTest';
        $isGetConnectivityTest = isset($_GET['action']) && sanitize_text_field($_GET['action']) === 'connectivityTest';

        $isHeaderConnectivityTest = false;
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $isHeaderConnectivityTest = isset($headers['Action']) && $headers['Action'] === 'connectivityTest';
        }

        if ($isPostConnectivityTest || $isGetConnectivityTest || $isHeaderConnectivityTest) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_start();
            echo json_encode(['message' => 'Connectivity Test passed.']);
            die();
        }


        if (!class_exists('wps_ic_cache')) {
            include_once WPS_IC_DIR . 'classes/cache.class.php';
        }

        // v7.21.337 — TORN-UPDATE WINDOW BELT (falknerei fatal: an admin-ajax request landed
        // mid-zip-overwrite — core.php new, cache.class.php not yet on disk; include_once of
        // a missing file leaves the class absent and `new` was a hard fatal). One request
        // rides degraded instead of white-screening; the next request finds the full tree.
        if (class_exists('wps_ic_cache')) {
            $cache = new wps_ic_cache();
            $cache->purgeHooks();
        }

        if (!class_exists('wps_ic_integrations')) {
            $this->integrations = null;
            return;
        }
        $this->integrations = new wps_ic_integrations();


        if (!defined('WPC_IS_LIGHT_AJAX') || !WPC_IS_LIGHT_AJAX) {
            $this->integrations->add_admin_hooks();
            $this->integrations->apply_admin_filters();
        }

        if (class_exists('WpeCommon')) {
            add_action('wpe_cache_flush', function() {
                $log = get_option('wpc_purge_debug_log', []);
                $log[] = date('Y-m-d H:i:s') . ' | WPE "Clear all caches" fired (wpe_cache_flush)';
                update_option('wpc_purge_debug_log', array_slice($log, -20), false);
            });
        }

        // Light-ajax skip: preload_warmup only registers cron handlers, which
        // don't fire on admin-ajax — the light handlers don't need it.
        if (!defined('WPC_IS_LIGHT_AJAX') || !WPC_IS_LIGHT_AJAX) {
            $preload = new wps_ic_preload_warmup();
            $preload->setupCronPreload();
        }

        //Temporary in 6.10.13. we changed where cname is saved, this is for users upgrading
        $cfCname = get_option(WPS_IC_CF_CNAME);
        $cf = get_option(WPS_IC_CF);
        if (!empty($cf) && !empty($cf['custom_cname']) && $cfCname === false) {
            wpc_cf_cname_persist($cf['custom_cname'], 'legacy-migration');
        }


        //$cache_warmup = new wps_ic_cache_warmup();
        //$cache_warmup->add_hooks();
    }


    public static function debug_log($message)
    {
        if (get_option('ic_debug') == 'log') {
            $log_file = WPS_IC_LOG . 'debug-log-' . date('d-m-Y') . '.txt';
            $time = current_time('mysql');

            if (!file_exists($log_file)) {
                fopen($log_file, 'a');
            }

            $log = file_get_contents($log_file);
            $log .= '[' . $time . '] - ' . $message . "\r\n";
            wpc_fs_put($log_file, $log);
        }
    }

    public static function generate_critical_cron()
    {
        $criticalCSS = new wps_criticalCss();
        if (method_exists($criticalCSS, 'generate_critical_cron')) { $criticalCSS->generate_critical_cron(); }
    }

    /**
     * If Plugin Version Changed, do...
     * @return void
     */
    public static function checkPluginVersion()
    {


        if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
            return;
        }


        // (unbounded wp_options bloat). A non-dotted running version is never a real
        // upgrade — skip the installer pass entirely (dev cache-busting is unaffected).
        if (!preg_match('/^\d+\.\d+/', (string) self::$version)) {
            return;
        }
        if (is_admin()) {
            $installed_version = get_option('wpc_core_version');


            if (!is_string($installed_version) || !preg_match('/^\d+(\.\d+)+$/', $installed_version)) {
                if (function_exists('wpc_cache_first_log') && !empty($installed_version)) {
                    wpc_cache_first_log('upgrade-version-garbage', '', '', ['v' => substr((string) $installed_version, 0, 24)]);
                }
                $installed_version = '0';
            }

            // The upgrade lane compares the REAL release version, never the dev-mode
            // time() alias — with the alias, every latch write stores a timestamp, the
            // garbage guard resets it to '0', and the pass (and its update window)
            // re-fires every 300s forever: the site stays no-store in perpetuity.
            $releaseVersion = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : self::$version;
            if (version_compare($installed_version, $releaseVersion, '<') || !empty($_GET['simulateVersionChange'])) {


                // Concurrent admin requests (or a looping caller) skip instead of stacking
                // concurrent passes; the pass is self-resuming, so the next window retries.
                if (get_transient('wpc_upgrade_lock')) {
                    return;
                }
                set_transient('wpc_upgrade_lock', 1, 300);

                // An upgrade closes the diagnostic log's window: the settings page used to arm it
                // on every render, so an open window on an upgrading site says nothing about
                // whether a person asked for it. Only the Debug tool's switch arms it.
                if (function_exists('wpc_diag_window_disarm')) {
                    wpc_diag_window_disarm('upgrade');
                }


                // v7.22.70 — THE WINDOW IS FOR REFRESHES, NOT FOR FIRST LIGHT. On a fresh install
                // there is no artifact to refresh and no crit yet, so every render inside the
                // 180s window was unarmed = never stored: each visitor hit and each warm variant
                // was a full page build. davisfamilyarbor (cPanel, 1-CPU cap): 98% CPU, 503s,
                // ~5 minutes after activation. No prior version recorded = no window.
                $firstInstall = (get_option('wpc_core_version') === false);
                if (function_exists('wpc_update_window_open') && !$firstInstall) {
                    wpc_update_window_open();
                } elseif ($firstInstall && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('update-window-skipped-first-install', '', '', ['v' => $releaseVersion]);
                }
                if ($firstInstall && function_exists('wpc_apply_fresh_install_smart_delivery')) {
                    wpc_apply_fresh_install_smart_delivery();
                }
                if (function_exists('wpc_arm_selfcheck_request')) {
                    wpc_arm_selfcheck_request();
                }
                self::wpc_cf_rules_schedule_if_needed();
                if (function_exists('wpc_fonts_htaccess_ensure_all')) {
                    wpc_fonts_htaccess_ensure_all();
                }


                $wpc_up_tries = (int) get_option("wpc_upgrade_attempts_" . md5($releaseVersion), 0);
                if ($wpc_up_tries >= 3) {
                    update_option("wpc_core_version", $releaseVersion, false);
                    delete_option("wpc_upgrade_attempts_" . md5($releaseVersion));
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('upgrade-degraded', '', '', ['tries' => $wpc_up_tries]);
                    }
                    return;
                }
                update_option("wpc_upgrade_attempts_" . md5($releaseVersion), $wpc_up_tries + 1, false);


                $wpc_upgrade_done = false;
                if (function_exists('register_shutdown_function')) {
                    register_shutdown_function(function () use (&$wpc_upgrade_done) {
                        if ($wpc_upgrade_done) {
                            return;
                        }
                        $wpc_le = function_exists('error_get_last') ? error_get_last() : null;
                        if (is_array($wpc_le) && isset($wpc_le['type'])
                            && in_array($wpc_le['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                            error_log('[WPC Upgrade] HARD-FATAL during upgrade pass — wpc_core_version left UNBUMPED, next admin load will retry: '
                                . $wpc_le['message'] . ' @ ' . $wpc_le['file'] . ':' . $wpc_le['line']);
                        }
                    });
                }

                try {

                // The page cache is not purged here. A dashboard update purges every HTML layer
                // hard from the upgrader hook (wpc_upgrader_purge, which also marks the crit
                // stale), and a version change the upgrader never saw (files copied by hand, or
                // an update from a release older than that hook) is purged by the version lane in
                // wps_ic_upgrader::update_to_latest(). This pass purged a third and fourth time
                // for the same event. The used-CSS drop list trusts only crit written after this.
                set_transient('wpc_crit_stale_' . md5('upgrade'), time(), 6 * HOUR_IN_SECONDS);

                // One-time settings migrations for the new version (pillar riders, the delay-v3
                // pair, the v3 exclude seed, parked-attempt reset). They ran on the first request
                // of any kind through a version stamp on init; they belong to the upgrade pass.
                if (function_exists('wpc_doctrine_reconcile')) {
                    wpc_doctrine_reconcile(false);
                }

                // Purge Object Cache
                $cacheObject = new wps_ic_cache();
                $cacheObject->purgeObjectCache();

                // RE-PROVE, never blindly revoke, the durable CSS/JS asset-MIME proof on
                // upgrade. The old invalidate-on-upgrade dropped a PROVEN state with no
                // replacement verdict, so every plugin upload regressed the whole site to
                // transform URLs until some later probe happened to run. The probe itself is
                // the re-verification: refreshes the stamp on success, and the standing
                // 2-strike revocation owns definitive failures. If the probe can't run here,
                // the proven state stands and the hourly converge tick re-checks it.
                if (function_exists('wpc_v2_asset_mime_probe_run')) {
                    try {
                        delete_transient('wpc_v2_cf_asset_mime_retry');
                        delete_transient('wpc_v2_asset_probe_inflight');
                        wpc_v2_asset_mime_probe_run();
                    } catch (\Throwable $e) {
                    }
                }


                // Rule: a deleted cron handler takes its queued events with it. Observed failure:
                // events queued for a hook nothing handles any more sit in the cron array until
                // due and then fire into nothing. The first two belonged to the v1
                // compress tail (the variant download and the transient-failure retry), queued per
                // image with [$imageID] args, which wp_clear_scheduled_hook() without those args
                // does not match; wp_unschedule_hook() removes every event of the hook. The third
                // was the shutdown drain's 120 s re-arm; the 5-minute wpc_v2_pull_cron replaces it.
                foreach (['wpc_download_variants', 'wpc_retry_compress', 'wpc_v2_shutdown_drain_tick'] as $deleted_cron_hook) {
                    if (function_exists('wp_unschedule_hook')) {
                        wp_unschedule_hook($deleted_cron_hook);
                    } elseif (function_exists('wp_clear_scheduled_hook')) {
                        wp_clear_scheduled_hook($deleted_cron_hook);
                    }
                }

                if (function_exists('update_option'))    update_option('wpc_v2_force_provision', 1, false);
                if (function_exists('delete_option'))    delete_option('wpc_v2_selfheal_attempts');
                if (function_exists('delete_transient')) delete_transient('wpc_v2_selfheal_backoff');
                if (function_exists('wpc_v2_provision_ensure_bg')) {
                    wpc_v2_provision_ensure_bg('upgrade');
                }


                $wpc_cache_settings = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
                if (!empty($wpc_cache_settings['cache']['advanced']) && $wpc_cache_settings['cache']['advanced'] == '1') {
                    if (!class_exists('wps_ic_htaccess')) {
                        @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
                    }
                    if (class_exists('wps_ic_htaccess')) {
                        $htaccess = new wps_ic_htaccess();
                        $htaccess->setWPCache(true);
                        $htaccess->setAdvancedCache();
                    }
                }


                update_option('wpc_v2_force_provision', 1, false);
                // An update arms /v2/config until a 2xx lands, whatever else the pass decided.
                if (function_exists('wpc_belt_receipt')) {
                    wpc_belt_receipt('provision-armed', ['why' => 'upgrade'], false, '');
                }
                if (function_exists('wpc_v2_schedule_config_sync')) {
                    wpc_v2_schedule_config_sync();
                } elseif (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_v2_deferred_config_sync')) {
                    wp_schedule_single_event(time(), 'wpc_v2_deferred_config_sync');
                }

                // Auto-enable picture_webp for existing users who have WebP enabled


                $migrateSettings = get_option(WPS_IC_SETTINGS);
                $migrateDirty = false;
                if (is_array($migrateSettings)
                    && !empty($migrateSettings['generate_webp']) && $migrateSettings['generate_webp'] == '1' && !isset($migrateSettings['picture_webp'])) {
                    $migrateSettings['picture_webp'] = '1';
                    $migrateDirty = true;
                }


                $ng = is_array($migrateSettings) && isset($migrateSettings['wpc_nextgen']) ? strtolower((string) $migrateSettings['wpc_nextgen']) : '';
                $ngUnchosen = ($ng === '' || $ng === 'auto');
                $gwOn = is_array($migrateSettings) && !empty($migrateSettings['generate_webp']) && (string) $migrateSettings['generate_webp'] === '1';
                $paOn = is_array($migrateSettings) && !empty($migrateSettings['picture_avif']) && (string) $migrateSettings['picture_avif'] === '1';
                if (is_array($migrateSettings) && $gwOn && $ngUnchosen && !$paOn) {
                    $migrateSettings['picture_avif'] = '1';
                    if (empty($migrateSettings['picture_webp'])) {
                        $migrateSettings['picture_webp'] = '1';
                    }
                    $migrateSettings['wpc_nextgen'] = 'auto';
                    $migrateDirty = true;
                }

                // The fixture exporter no longer ships (it is a separate developer plugin now,
                // tests/tools/fixture-exporter/ in the repo): its Debug-tab key and the two options
                // the built-in route kept are retired, so no site carries state nothing reads.
                if (is_array($migrateSettings) && array_key_exists('fixture-export', $migrateSettings)) {
                    unset($migrateSettings['fixture-export']);
                    $migrateDirty = true;
                }
                delete_option('wpc_fixture_export_armed_at');
                delete_option('wpc_fixture_export_last');

                if ($migrateDirty) {
                    update_option(WPS_IC_SETTINGS, $migrateSettings);
                }

                // Connected sites pick up the link-preset levers on update too — set-if-unset,
                // explicit user values always win, foreign-cache gate applies.
                $pluginOptions = get_option(WPS_IC_OPTIONS);
                if (is_array($pluginOptions) && !empty($pluginOptions['api_key'])
                    && function_exists('wpc_apply_link_preset')
                    && apply_filters('wpc_upgrade_apply_preset', true)) {
                    wpc_apply_link_preset('upgrade');
                }


                // Remote work never runs inside the visitor's request.
                update_option('wpc_upgrade_prev_version', (string) $installed_version, false);
                if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
                    if (!wp_next_scheduled('wpc_upgrade_remote_lane')) {
                        wp_schedule_single_event(time(), 'wpc_upgrade_remote_lane');
                    }
                    if (function_exists('spawn_cron')) {
                        wpc_spawn_cron();
                    }
                } else {
                    self::wpc_upgrade_remote_lane();
                }


                try {
                    $staticServeSettings = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
                    if (is_array($staticServeSettings) && !empty($staticServeSettings['static-serve']) && $staticServeSettings['static-serve'] == '1') {
                        if (!class_exists('wps_ic_htaccess')) {
                            @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
                        }
                        if (class_exists('wps_ic_htaccess')) {
                            (new wps_ic_htaccess())->applyStaticServe();
                        }
                    }
                } catch (\Throwable $e) {
                }


                update_option("wpc_core_version", $releaseVersion, false);
                delete_option("wpc_upgrade_attempts_" . md5($releaseVersion));


                // Mark the pass complete so the shutdown trap below stays silent on a clean run.
                $wpc_upgrade_done = true;

                } catch (\Throwable $wpc_upgrade_err) {


                    error_log('[WPC Upgrade] CAUGHT upgrade-pass error — wpc_core_version left UNBUMPED, next admin load will retry: '
                        . $wpc_upgrade_err->getMessage() . ' @ ' . $wpc_upgrade_err->getFile() . ':' . $wpc_upgrade_err->getLine());
                }
            }

            // One-time CDN bypass rule + IP whitelist — remote, so it rides the background lane.
            if (empty(get_option('wpc_cf_bypass_v7'))) {
                if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
                    if (!wp_next_scheduled('wpc_upgrade_remote_lane')) {
                        wp_schedule_single_event(time(), 'wpc_upgrade_remote_lane');
                    }
                } else {
                    self::wpc_upgrade_remote_lane();
                }
            }


            if (get_option('wpc_avif_natural_default_v70') !== '1') {
                $avifNatSettings = get_option(WPS_IC_SETTINGS);
                if (is_array($avifNatSettings)) {
                    $avifNatSettings['avif-natural-source'] = '1';
                    update_option(WPS_IC_SETTINGS, $avifNatSettings);
                }
                update_option('wpc_avif_natural_default_v70', '1');
            }
        }
    }

    /**
     * Rule: on a version change the cache rules converge only when the shipped definitions moved
     * since this zone last converged (fingerprint compare against wpc_cf_rules_converged, no
     * network); the converge itself runs in cron, never on the admin request. Observed failure:
     * the once-per-site reassert (wpc_cf_bypass_v7) left perkzilla.com on July's override_origin
     * rules for two months while the code shipped respect_origin.
     */
    public static function wpc_cf_rules_schedule_if_needed()
    {
        try {
            $cf = get_option(WPS_IC_CF);
            if (!is_array($cf) || empty($cf['token']) || empty($cf['zone'])) { return; }
            if (!class_exists('wps_ic_cf_rules')) { return; }
            if (!wps_ic_cf_rules::needs_converge($cf['zone'])) { return; }
            if (!function_exists('wp_schedule_single_event') || !function_exists('wp_next_scheduled')) { return; }
            if (wp_next_scheduled('wpc_cf_rules_converge')) { return; }
            wp_schedule_single_event(time(), 'wpc_cf_rules_converge');
            if (function_exists('wpc_spawn_cron')) { wpc_spawn_cron('cf-rules-converge'); }
        } catch (\Throwable $e) {
            // A failed check must not break the upgrade pass; the next version change asks again.
        }
    }

    /**
     * Post-upgrade remote work, cron context: keys registration, edge purge, renderer allow,
     * one-time bypass provisioning, artifact re-pull. Cache rules converge from the upgrade pass
     * itself (wpc_cf_rules_schedule_if_needed), only when their fingerprint moved.
     */
    public static function wpc_upgrade_remote_lane()
    {
        // Natural-assets proof, armed at the moment fleet rollouts actually happen. The other
        // lanes (cold-render loopback, warm heartbeat, hourly tick) all depend on traffic or a
        // working scheduler; this one runs off the upgrade itself, already outside any visitor
        // request, so a site with dead cron and a week-deep edge cache still converges on install.
        try {
            if (function_exists('wpc_v2_asset_mime_probe_run')
                && (string) get_option('wpc_v2_cf_asset_mime_ok', '') !== '1') {
                $settings = get_option(WPS_IC_SETTINGS);
                if (is_array($settings) && !empty($settings['live-cdn']) && (string) $settings['live-cdn'] === '1'
                    && !(function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed())
                    && wpc_v2_asset_mime_probe_run()
                    && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                    wps_ic_cache::removeHtmlCacheFiles('all');
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('natural-converged', '', '', ['src' => 'upgrade']);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        try {
            $cfReassert = get_option(WPS_IC_CF);
            if (!empty($cfReassert['token']) && !empty($cfReassert['zone'])) {
                if (!class_exists('WPC_CloudflareAPI')) {
                    require_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
                }
                if (class_exists('WPC_CloudflareAPI')) {
                    $cfReassertSdk = new WPC_CloudflareAPI($cfReassert['token']);
                    // Keys-service registration first — purge paths depend on it being current.
                    $cfSettings = isset($cfReassert['settings']) && is_array($cfReassert['settings']) ? $cfReassert['settings'] : [];
                    if (method_exists($cfReassertSdk, 'configureCF')) {
                        $cfReassertSdk->configureCF(
                            isset($cfSettings['edge-cache']) ? (string) $cfSettings['edge-cache'] : 'home',
                            !empty($cfSettings['assets']) && (string) $cfSettings['assets'] === '1'
                        );
                    }
                    // Crossing from a pre-flagship version: edge copies reference a retired
                    // asset pipeline — one full purge, then never again for this threshold.
                    $previousVersion = (string) get_option('wpc_upgrade_prev_version', '');
                    if ($previousVersion !== '' && version_compare($previousVersion, apply_filters('wpc_edge_reset_below', '7.10.210'), '<')
                        && method_exists($cfReassertSdk, 'purgeCacheAsync')) {
                        $cfReassertSdk->purgeCacheAsync($cfReassert['zone']);
                        if (function_exists('wpc_auto_journal')) {
                            wpc_auto_journal('edge-reset-on-upgrade', ['from' => substr($previousVersion, 0, 16)]);
                        }
                    }
                    // The Cloudflare HTML of an update is purged by the update's owners (the
                    // upgrader hook's hard purge and the version lane's cfPurgeAllHtml); this lane
                    // purged the wpc-html tag a further time for the same update.
                    if (method_exists($cfReassertSdk, 'addRendererAllowRule')) {
                        try { $cfReassertSdk->addRendererAllowRule($cfReassert['zone']); } catch (\Throwable $e) {}
                    }
                    // v7.21.08 — VERSIONED one-shot: the v5 flag was permanent, so the .07 in-place
                    // phase upgrade inside addCdnBypassRule could never reach a site that had already
                    // provisioned — dead code on exactly the fleet it was built for. Bumping the flag
                    // re-runs provisioning once per shape generation; both calls are presence-checked
                    // and idempotent, so the re-run is one GET + at most one PATCH per site.
                    if (empty(get_option('wpc_cf_bypass_v7'))) {
                        $cfReassertSdk->addCdnBypassRule($cfReassert['zone']);
                        $cfReassertSdk->whitelistIPs($cfReassert['zone']);
                        // Mark done even on failure — retrying would block every run on CF API errors
                        update_option('wpc_cf_bypass_v7', '1');
                    }
                }
            } elseif (empty(get_option('wpc_cf_bypass_v7'))) {
                update_option('wpc_cf_bypass_v7', '1');
            }
        } catch (\Throwable $e) {
            error_log('[WPC Upgrade] remote lane CF step failed: ' . $e->getMessage());
        }
        // ONE HOMEPAGE ASK PER UPDATE. The update resync below is it (once per version, when
        // critical CSS is on); when it does not run, the warm lanes' homepage step asks instead,
        // and only when the homepage has no crit. A homepage kick (src upgrade) used to fire here
        // as well, before the resync, on every run of this lane: a second ask for the same page in
        // the same minute. Collecting the homepage's existing artifacts is the repull the update
        // window schedules (wpc_artifact_refresh_on_update, 90 s after the update).
        $resynced = false;
        try {
            // v7.10.830 — OUT-OF-THE-BOX CONVERGENCE ON UPDATE. The update window's repull re-pulls
            // the EXISTING shelf; after a version change the render fails open (plain scripts,
            // origin assets) until a genuinely fresh gen lands — wpcompress.com sat at that
            // floor until someone pressed Pull Latest. The update now runs the same resync
            // itself: one force+sync gen (busts the service template cache) + the two follow-up
            // waves, detached, once per version, killable via filter.
            $resyncSettings = get_option(WPS_IC_SETTINGS);
            $resyncVersion = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : 'x';
            if (is_array($resyncSettings) && !empty($resyncSettings['critical']['css']) && (string) $resyncSettings['critical']['css'] === '1'
                && apply_filters('wpc_upgrade_resync', true)
                && (string) get_option('wpc_upgrade_resync_v', '') !== $resyncVersion) {
                update_option('wpc_upgrade_resync_v', $resyncVersion, false);
                if (function_exists('wpc_crit_purge_redispatch')) {
                    wpc_crit_purge_redispatch(true);
                }
                if (function_exists('wpc_pl_sched') && class_exists('wps_ic_url_key') && function_exists('home_url')) {
                    $homeKey = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
                    if ($homeKey !== '') {
                        foreach ([150, 330] as $waveDelay) {
                            wpc_pl_sched(time() + $waveDelay, 'wpc_crit_resync_wave', [$homeKey, (int) $waveDelay]);
                        }
                    }
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('upgrade-resync', '', '', ['v' => $resyncVersion]);
                }
                $resynced = true;
            }
        } catch (\Throwable $e) {
        }
        try {
            if (!$resynced && function_exists('wpc_warm_home_dispatch_queue')) {
                wpc_warm_home_dispatch_queue('upgrade');
            }
        } catch (\Throwable $e) {
        }
        // v7.21.22 — cost receipt for the whole remote lane (same reason as the one-shot stamp:
        // a 5-minute customer hang was unattributable because no upgrade line said what IT cost).
        error_log(sprintf('[WPC Upgrade] remote lane done [t=%dms peak=%.0fM]',
            (int) round((microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true))) * 1000),
            memory_get_peak_usage(true) / 1048576));
    }

    public static function deleteTests()
    {
        // Remove Tests
        delete_transient('wpc_test_running');
        delete_transient('wpc_initial_test');
        delete_option(WPC_WARMUP_LOG_SETTING);
        delete_option('wps_ic_gen_hp_url');
    }


    public static function get_wp_filesize($imageID)
    {
        $filepath = get_attached_file($imageID);
        $filesize = filesize($filepath);
        $filesize = wps_ic_format_bytes($filesize, null, null, false);

        return $filesize;
    }

    public static function getAccountQuota($data, $quotaType)
    {
        $proSite = get_option('wps_ic_prosite');
        $options = get_option(WPS_IC_OPTIONS);

        if (empty($data) || empty($options['response_key'])) {
            return ['local' => 0, 'live' => 0, 'liveQuota' => 0, 'localQuota' => 0, 'liveShared' => 0, 'localShared' => 0];
        }

        $liveShared = 0;
        $localShared = 0;

        if (!empty($data->account->liveShared)) {
            $liveShared = $data->account->liveShared;
        }

        if (!empty($data->account->localShared)) {
            $localShared = $data->account->localShared;
        }

        $liveQuota = 0;

        if ($data->account->quotaType == 'requests' || $data->account->quotaType == 'requests-combined') {
            // Requests
            $liveCredits = $data->account->leftover . ' Requests Left';

            if (empty($data->liveCredits)) {
                $data->liveCredits = (object)['formatted' => '', 'value' => 0];
            }

            if (!empty($data->liveCredits->value)) {
                $liveQuota = $data->liveCredits->value;
            }

            if (!empty($proSite) && $proSite) {
                $localCredits = 'Unlimited';
                $localQuota = 'Unlimited';
            } else {
                $localCredits = $data->liveCredits->formatted . ' Images Left';
                $localQuota = $data->liveCredits->value;
            }
        } else {
            // Bandwidth
            $liveCredits = $data->account->leftover . ' Left';

            if (!empty($data->liveCredits->value)) {
                $liveQuota = $data->liveCredits->value;
            }

            if (!empty($proSite) && $proSite) {
                $localCredits = 'Unlimited';
                $localQuota = 'Unlimited';
            } else {
                #$localCredits = $data->localCredits->formatted->number . ' ' . $data->localCredits->formatted->unit . ' Left';
                #$localQuota = $data->localCredits->value;
                $localCredits = 0;
                $localQuota = 0;
            }
        }

        if (empty($proSite)) {
            if ($localShared) {
                $localCredits = 'Shared Credits';
                $localCredits = 'Shared';
            }

            if ($liveShared) {
                $liveShared = 'Shared Credits';
                $liveCredits = 'Shared';
            }
        } else {
            $localCredits = 'Unlimited &infin;';
            $localCredits = 'Unlimited &infin;';
            $liveShared = 'Unlimited &infin;';
            $liveCredits = 'Unlimited &infin;';
        }

        return ['local' => $localCredits, 'live' => $liveCredits, 'liveQuota' => $liveQuota, 'localQuota' => $localQuota, 'liveShared' => $liveShared, 'localShared' => $localShared];
    }


    public static function getAccountStatusMemory($force = false)
    {
        if (!empty($_GET['refresh']) || $force) {
            delete_transient('wps_ic_account_status');
        }

        $transient_data = get_transient('wps_ic_account_status');

        if (!$transient_data || empty($transient_data)) {
            self::debug_log('Not In Memory');
            self::$accountStatus = self::check_account_status();

            return self::$accountStatus;
        } else {
            self::debug_log('In Memory');
            self::debug_log(print_r($transient_data, true));

            return $transient_data;
        }
    }

    /**
     * v7.10.469 — the ONE decision point for gating live-cdn on account status.
     *
     * Replaces two copies of `if ($account_status != 'active') { $settings['live-cdn'] = '0'; }`
     * (the `// TODO: Fix` at :2041 and :2212). $account_status came straight off
     * $body->account->status with no validation, so a MISSING key, an empty value, a partial
     * response, or any status the API adds later ('trialing', 'past_due', 'grace') all satisfied
     * != 'active' and disabled a paying customer's CDN — with nothing anywhere to turn it back
     * on. That is the shape .411/.412 shipped for: a non-authoritative response causing a
     * destructive local write, fleet-wide.
     *
     * Two rules: only an EXPLICIT known-bad status may disable, and whatever we disable we can
     * restore. Returns true when $settings was changed and the caller should persist it.
     */
    public static function wpc_account_gate_live_cdn($account_status, &$settings)
    {
        if (!is_array($settings)) {
            return false;
        }
        $st = is_string($account_status) ? strtolower(trim($account_status)) : '';
        $cur = isset($settings['live-cdn']) ? (string) $settings['live-cdn'] : '';
        $kill = (array) apply_filters('wpc_account_status_disables_cdn',
            ['suspended', 'cancelled', 'canceled', 'expired', 'inactive', 'deleted', 'terminated']);

        // Unknown / empty / unrecognised is NO INFORMATION, never a reason to disable.
        if ($st === '') {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('account-gate-no-status', '', '', ['cur' => $cur, 'action' => 'none']);
            }
            return false;
        }

        if (in_array($st, $kill, true)) {
            if ($cur === '0') {
                return false; // already off — no write, no purge
            }
            // Restore point, so the disable is reversible. Written BEFORE the change.
            update_option('wpc_live_cdn_pre_gate', $cur, false);
            $settings['live-cdn'] = '0';
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('account-gate-cdn-off', '', '', ['status' => $st, 'was' => $cur]);
            }
            return true;
        }

        if ($st === 'active') {
            $prev = get_option('wpc_live_cdn_pre_gate', null);
            $have = ($prev !== null && $prev !== false && $prev !== '');
            if ($have && $cur === '0') {
                $settings['live-cdn'] = (string) $prev;
                delete_option('wpc_live_cdn_pre_gate');
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('account-gate-cdn-restored', '', '', ['to' => (string) $prev]);
                }
                return true;
            }
            if ($have) {
                // Active and the customer already set it themselves — drop the stale restore
                // point so a LATER suspension cannot resurrect a value they since changed.
                delete_option('wpc_live_cdn_pre_gate');
            }
            return false;
        }

        // A known status that is neither active nor known-bad (trialing, past_due, grace…):
        // report it and change nothing. Adding it to the kill list is a deliberate act.
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('account-gate-status-unhandled', '', '', ['status' => $st, 'action' => 'none']);
        }
        return false;
    }

    public static function check_account_status($ignore_transient = false)
    {
        //Call once every admin load
        self::debug_log('Check Account Status');

        if (!empty($_GET['refresh']) || $ignore_transient) {
            delete_transient('wps_ic_account_status');
        }

        $transient_data = get_transient('wps_ic_account_status');
        if (!empty($transient_data) && $transient_data !== 'no-site-found' && self::$accStatusChecked) {
            self::debug_log('Check Account Status - In Transient');

            return $transient_data;
        }

        // Durable rate floor: with fresh data on hand, re-poll the API at most once per 60s
        if (!empty($transient_data) && $transient_data !== 'no-site-found' && !$ignore_transient && empty($_GET['refresh'])) {
            $credits_checked_at = (int) get_option('wpc_credits_checked_at');
            if (time() - $credits_checked_at < 60) {
                self::$accStatusChecked = true;

                return $transient_data;
            }
        }

        // v7.10.403: a re-poll must NEVER block the render. We only reach here because the
        // 60s floor lapsed — but the hour-long transient is still valid data. Hand back the
        // cached copy and refresh in the BACKGROUND (cron loopback); the synchronous apiv3
        // call below then runs only on an explicit refresh or in the cron/bg lane. This ends
        // the FPM worker-parking that hangs wp-admin when apiv3 is slow or unreachable.
        if (!empty($transient_data) && $transient_data !== 'no-site-found'
            && !$ignore_transient && empty($_GET['refresh'])
            && !(defined('DOING_CRON') && DOING_CRON)
            && apply_filters('wpc_account_status_async', true)) {
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_account_status_refresh')) {
                wp_schedule_single_event(time() + 2, 'wpc_account_status_refresh');
                wpc_spawn_cron();
            }
            self::$accStatusChecked = true;
            return $transient_data;
        }

        self::debug_log('Check Account Status - Call API');

        $options = get_option(WPS_IC_OPTIONS);
        $settings = get_option(WPS_IC_SETTINGS);

        /**
         * Site is not connected
         */
        if (!$options || empty($options['api_key'])) {
            $data = [];
            $data['account']['allow_local'] = false;
            $data['account']['allow_live'] = false;
            $data['account']['allow_cname'] = false;
            $data['account']['type'] = 'shared';
            $data['account']['projected_flag'] = 1;

            $data['account'] = (object)$data['account'];

            $data['bytes']['leftover'] = '0';
            $data['bytes']['cdn_bandwidth'] = '0';
            $data['bytes']['cdn_requests'] = '0';
            $data['bytes']['bandwidth_savings'] = '0';
            $data['bytes']['bandwidth_savings_bytes'] = '0';
            $data['bytes']['original_bandwidth'] = '0';
            $data['bytes']['projected'] = '0';
            // Local
            $data['bytes']['local_requests'] = '0';
            $data['bytes']['local_savings'] = '0';
            $data['bytes']['local_original'] = '0';
            $data['bytes']['local_optimized'] = '0';

            $data['bytes'] = (object)$data['bytes'];

            $data['formatted']['leftover'] = '0 MB';
            $data['formatted']['cdn_bandwidth'] = '0 MB';
            $data['formatted']['cdn_requests'] = '0';
            $data['formatted']['bandwidth_savings'] = '0 MB';
            $data['formatted']['bandwidth_savings_bytes'] = '0 MB';
            $data['formatted']['package_without_extra'] = '0';
            $data['formatted']['original_bandwidth'] = '0 MB';
            $data['formatted']['projected'] = '0 MB';

            // Local
            $data['formatted']['local_requests'] = '0';
            $data['formatted']['local_savings'] = '0 MB';
            $data['formatted']['local_original'] = '0 MB';
            $data['formatted']['local_optimized'] = '0 MB';

            $data['formatted'] = (object)$data['formatted'];

            $data = (object)$data;

            $body = ['success' => true, 'data' => $data];
            $body = (object)$body;

            return $data;
        }

        // Check if we have saved results from a previous successful call
        $saved_credits_call = get_option('wps_ic_credits_call');

        // Set timeout based on whether we have saved results
        $api_timeout = !empty($saved_credits_call) ? 2 : 5;

        // Stamp before the call so concurrent requests can't pile onto the API
        update_option('wpc_credits_checked_at', time(), false);

        // Check privileges
        $url = 'https://apiv3.wpcompress.com/api/site/credits';
        $call = wp_remote_get($url, ['timeout' => $api_timeout, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $options['api_key'], 'plugin-version' => self::$version]]);

        if (wp_remote_retrieve_response_code($call) == 200) {

            $json = $body = wp_remote_retrieve_body($call);

            $body = json_decode($body);

            // Save successful API call results
            if (!empty($body) && $body !== 'no-site-found') {
                update_option('wps_ic_credits_call', $body);
            }

            set_transient('wps_ic_account_status_call', $body, WPS_IC_ACCOUNT_STATUS_MEMORY);

            if (!empty($body) && $body !== 'no-site-found') {
                // Vars
                $body = self::createObjectFromJson($json);

                //Check if url changed
                $site_url = trim(site_url());
                $api_url  = trim(($body->site->site_url ?? ''));

                if (!empty($site_url) && !empty($api_url) && $api_url !== $site_url) {
                    // Append to log
                    $logs = get_option('wps_ic_url_changed_log', []);
                    if (!is_array($logs)) {
                        $logs = [];
                    }
                    if (count($logs) > 20) {
                        $logs = array_slice($logs, -20);
                    }

                    $logs[] = [
                            'ts'          => current_time('mysql'),
                            'site_url'    => $site_url,
                            'api_url'     => $api_url,
                            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                            'host'        => isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '',
                    ];

                    update_option('wps_ic_url_changed_log', $logs, false);

                    // Disconnect, prompt url changed msg
                    $options = get_option(WPS_IC_OPTIONS);
                    if (!is_array($options)) {
                        $options = [];
                    }

                    $options['api_key'] = '';
                    $options['response_key'] = '';
                    $options['orp'] = '';
                    $options['regExUrl'] = '';
                    $options['regexpDirectories'] = '';

                    update_option(WPS_IC_OPTIONS, $options);
                    update_option('wps_ic_url_changed', true);

                    if ( ! isset($_GET['_wpc_refreshed']) ) {
                        $url = add_query_arg('_wpc_refreshed', '1', wp_get_referer() ?: admin_url());
                        wp_safe_redirect($url);
                        exit;
                    }

                    return false;
                }


                $account_status = $body->account->status;

                $allow_local = $body->account->allowLocal;
                $allow_live = $body->account->allowLive;
                $quota_type = $body->account->quotaType;
                $proSite = $body->account->proSite;

                if ($quota_type == 'pageviews') {
                    $package_caps = isset($body->packageConfiguration) ? (array) $body->packageConfiguration : [];

                    $data = [];
                    $data['account']['quotaType'] = 'pageviews';

                    $data['account'] = (object)$data['account'];

                    $data['bytes']['bandwidth_savings'] = $body->bytes->bandwidth_savings;
                    $data['formatted']['bandwidth_savings'] = $body->formatted->bandwidth_savings;
                    //
                    $data['bytes']['original_bandwidth'] = $body->bytes->original_bandwidth;
                    $data['formatted']['original_bandwidth'] = $body->formatted->original_bandwidth;

                    $data['bytes']['pageviews'] = $body->pageviews;
                    $data['bytes']['usedPageviews'] = $body->usedPageviews;
                    $data['bytes']['monthly']['requests'] = $body->monthly->requests;
                    $data['bytes']['monthly']['bytes'] = $body->monthly->bytes;
                    $data['bytes']['leftover'] = $data['bytes']['pageviews'] - $data['bytes']['usedPageviews'];

                    $data['bytes'] = (object)$data['bytes'];


                    $data['formatted']['pageviews'] = $body->pageviews;
                    $data['formatted']['usedPageviews'] = $body->usedPageviews;
                    $data['formatted']['monthly']['requests'] = $body->monthly->formatted->requests;
                    $data['formatted']['monthly']['bytes'] = $body->monthly->formatted->bytes;
                    $data['formatted']['leftover'] = $data['formatted']['pageviews'] - $data['formatted']['usedPageviews'];

                    $data['formatted'] = (object)$data['formatted'];
                    $data = (object)$data;

                    $body = ['success' => true, 'data' => $data];
                    $body = (object)$body;

                    // Account Status Transient
                    set_transient('wps_ic_account_status', $body->data, WPS_IC_ACCOUNT_STATUS_MEMORY);
                    self::$accStatusChecked = true;
                    // v7.10.760 — BANK CAPS ON THIS BRANCH TOO. The .505 durable-caps writer sat
                    // below the pageviews early-return, so pageviews-quota accounts never wrote
                    // wpc_caps: transients expired in 5 minutes and every reader fell through to
                    // "never had a successful check = DENIED" — a valid key with every feature
                    // PRO-locked (heritagepavingltd live receipt, 2026-08-05).
                    if (function_exists('wpc_caps_store')) {
                        if (empty($package_caps)) {
                            wpc_caps_store([], true);
                        } else {
                            wpc_caps_store($package_caps, false);
                        }
                    }
                    return $body->data;
                }
                else {

                    // If pro site,raise flag
                    if (!empty($proSite) && $proSite == '1') {
                        update_option('wps_ic_prosite', true);
                    } else {
                        update_option('wps_ic_prosite', false);
                    }

                    // Account Status Transient
                    set_transient('wps_ic_account_status', $body, WPS_IC_ACCOUNT_STATUS_MEMORY);
                    self::$accStatusChecked = true;

                    list($allow_local, $allow_live) = wpc_allow_flags_from_api($body);
                    list($updated_local, $updated_live) = wpc_allow_flags_apply($allow_local, $allow_live, 'account-status');

                    // If Local or Live Capabilities Changed, Purge
                    if ($updated_local || $updated_live) {
                        $cache = new wps_ic_cache_integrations();
                        $cache::purgeAll();
                    }

                    // Is account active? Gated through ONE reversible decision point (.469).
                    if (self::wpc_account_gate_live_cdn($account_status, $settings)) {
                        update_option(WPS_IC_SETTINGS, $settings);
                    }
                }

                // Account configuration
                if (empty($body->packageConfiguration)) {
                    // Unrestricted plan — record it EXPLICITLY. Writing nothing here is what
                    // locked full-plan sites out, because every reader reads absence as denied.
                    if (function_exists('wpc_caps_store')) { wpc_caps_store([], true); }
                }
                else {
                    // Block some options
                    $packageConfig = (array)$body->packageConfiguration;
                    // v7.10.505 — bank the restricted map durably as well, so a cache flush can never be
                    // mistaken for a downgrade. Revocation only ever comes from a LATER successful check.
                    if (function_exists('wpc_caps_store')) { wpc_caps_store($packageConfig, false); }
                    if (!empty($packageConfig)) {
                        foreach ($packageConfig as $key => $value) {
                            set_transient($key . 'Enabled', $value, 5 * 60); // fast path

                            if ($value == '0') {
                                switch ($key) {
                                    case 'cdn':
                                        $settings['live-cdn'] = 0;
                                        $settings['serve'] = ['jpg' => 0, 'png' => 0, 'gif' => 0, 'svg' => 0, 'css' => 0, 'js' => 0, 'fonts' => 0];
                                        $settings['css'] = 0;
                                        $settings['js'] = 0;
                                        $settings['fonts'] = 0;
                                        break;
                                    case 'adaptive':
                                        $settings['generate_adaptive'] = 0;
                                        $settings['generate_webp'] = 0;
                                        $settings['retina'] = 0;
                                        $settings['background-sizing'] = 0;
                                        break;
                                    case 'lazy':
                                        $settings['lazy'] = 0;
                                        $settings['nativeLazy'] = 0;
                                        $settings['lazySkipCount'] = 4;
                                        break;
                                    case 'local':
                                        $settings['local'] = ['media-library' => 0];
                                        $settings['on-upload'] = 0;
                                        break;
                                    case 'caching':
                                        $settings['cache'] = ['advanced' => 0, 'mobile' => 0, 'minify' => 0];
                                        break;
                                    case 'css':
                                        $settings['critical']['css'] = 0;
                                        break;
                                    case 'js':
                                        $settings['inline-js'] = 0;
                                        break;
                                    case 'delay-js':
                                        $settings['delay-js'] = 0;
                                        break;

                                }
                            }
                        }
                    }
                }

                return $body;
            } else {
                // 200 but empty / 'no-site-found' body = a transient service glitch (endpoint
                // moved, registry not synced yet), NOT a disconnect. NEVER wipe credentials on
                // it — keep the key and fall back to the last good account data so the site
                // self-heals on the next good call.
                if (!empty($saved_credits_call)) {
                    set_transient('wps_ic_account_status_call', $saved_credits_call, WPS_IC_ACCOUNT_STATUS_MEMORY);
                    return $saved_credits_call;
                }
                return false;
            }
        } else if (wp_remote_retrieve_response_code($call) == 401) {
            // A single 401 is often transient (endpoint move, auth-service blip). NEVER wipe
            // credentials on it — keep the key so the site self-heals when auth is valid again.
            // Real revocation is enforced by the explicit `suspended` signal + the service
            // refusing to serve.
            if (!empty($saved_credits_call)) {
                return $saved_credits_call;
            }
            return false;
        } else {
            // If API call failed but we have saved results, use them
            if (!empty($saved_credits_call)) {
                self::debug_log('Check Account Status - Using Saved Results');

                $body = $saved_credits_call;
                $json = json_encode($body);

                set_transient('wps_ic_account_status_call', $body, WPS_IC_ACCOUNT_STATUS_MEMORY);

                if (!empty($body) && $body !== 'no-site-found') {
                    // Vars
                    $body = self::createObjectFromJson($json);
                    $account_status = $body->account->status;

                    $allow_local = $body->account->allowLocal;
                    $allow_live = $body->account->allowLive;
                    $quota_type = $body->account->quotaType;
                    $proSite = $body->account->proSite;

                    if ($quota_type == 'pageviews') {

                        $data = [];
                        $data['account']['quotaType'] = 'pageviews';

                        $data['account'] = (object)$data['account'];

                        $data['bytes']['bandwidth_savings'] = $body->bytes->bandwidth_savings;
                        $data['formatted']['bandwidth_savings'] = $body->formatted->bandwidth_savings;
                        //
                        $data['bytes']['original_bandwidth'] = $body->bytes->original_bandwidth;
                        $data['formatted']['original_bandwidth'] = $body->formatted->original_bandwidth;

                        $data['bytes']['pageviews'] = $body->pageviews;
                        $data['bytes']['usedPageviews'] = $body->usedPageviews;
                        $data['bytes']['monthly']['requests'] = $body->monthly->requests;
                        $data['bytes']['monthly']['bytes'] = $body->monthly->bytes;
                        $data['bytes']['leftover'] = $data['bytes']['pageviews'] - $data['bytes']['usedPageviews'];

                        $data['bytes'] = (object)$data['bytes'];


                        $data['formatted']['pageviews'] = $body->pageviews;
                        $data['formatted']['usedPageviews'] = $body->usedPageviews;
                        $data['formatted']['monthly']['requests'] = $body->monthly->formatted->requests;
                        $data['formatted']['monthly']['bytes'] = $body->monthly->formatted->bytes;
                        $data['formatted']['leftover'] = $data['formatted']['pageviews'] - $data['formatted']['usedPageviews'];

                        $data['formatted'] = (object)$data['formatted'];
                        $data = (object)$data;

                        $body = ['success' => true, 'data' => $data];
                        $body = (object)$body;

                        // Account Status Transient
                        set_transient('wps_ic_account_status', $body->data, WPS_IC_ACCOUNT_STATUS_MEMORY);
                        self::$accStatusChecked = true;

                        return $body->data;
                    } else {

                        // If pro site,raise flag
                        if (!empty($proSite) && $proSite == '1') {
                            update_option('wps_ic_prosite', true);
                        } else {
                            update_option('wps_ic_prosite', false);
                        }

                        // Account Status Transient
                        set_transient('wps_ic_account_status', $body, WPS_IC_ACCOUNT_STATUS_MEMORY);
                        self::$accStatusChecked = true;

                        list($allow_local, $allow_live) = wpc_allow_flags_from_api($body);
                        list($updated_local, $updated_live) = wpc_allow_flags_apply($allow_local, $allow_live, 'account-status');

                        // If Local or Live Capabilities Changed, Purge
                        if ($updated_local || $updated_live) {
                            $cache = new wps_ic_cache_integrations();
                            $cache::purgeAll();
                        }

                        // Is account active? Gated through ONE reversible decision point (.469).
                        if (self::wpc_account_gate_live_cdn($account_status, $settings)) {
                            update_option(WPS_IC_SETTINGS, $settings);
                        }
                    }
                    // Account configuration
                    if (empty($body->packageConfiguration)) {
                        // Unrestricted plan — record it EXPLICITLY (see wpc_caps_store).
                        if (function_exists('wpc_caps_store')) { wpc_caps_store([], true); }
                    } else {
                        // Block some options
                        $packageConfig = (array)$body->packageConfiguration;
                        if (function_exists('wpc_caps_store')) { wpc_caps_store($packageConfig, false); }
                        if (!empty($packageConfig)) {
                            foreach ($packageConfig as $key => $value) {
                                set_transient($key . 'Enabled', $value, 5 * 60); // fast path

                                if ($value == '0') {
                                    switch ($key) {
                                        case 'cdn':
                                            $settings['live-cdn'] = 0;
                                            $settings['serve'] = ['jpg' => 0, 'png' => 0, 'gif' => 0, 'svg' => 0, 'css' => 0, 'js' => 0, 'fonts' => 0];
                                            $settings['css'] = 0;
                                            $settings['js'] = 0;
                                            $settings['fonts'] = 0;
                                            break;
                                        case 'adaptive':
                                            $settings['generate_adaptive'] = 0;
                                            $settings['generate_webp'] = 0;
                                            $settings['retina'] = 0;
                                            $settings['background-sizing'] = 0;
                                            break;
                                        case 'lazy':
                                            $settings['lazy'] = 0;
                                            $settings['nativeLazy'] = 0;
                                            $settings['lazySkipCount'] = 4;
                                            break;
                                        case 'local':
                                            $settings['local'] = ['media-library' => 0];
                                            $settings['on-upload'] = 0;
                                            break;
                                        case 'caching':
                                            $settings['cache'] = ['advanced' => 0, 'mobile' => 0, 'minify' => 0];
                                            break;
                                        case 'css':
                                            $settings['critical']['css'] = 0;
                                            break;
                                        case 'js':
                                            $settings['inline-js'] = 0;
                                            break;
                                        case 'delay-js':
                                            $settings['delay-js'] = 0;
                                            break;

                                    }
                                }
                            }
                        }
                    }

                    return $body;
                }
            }

            // No saved results available, return default data
            $data = [];
            $data['account']['allow_local'] = false;
            $data['account']['allow_live'] = false;
            $data['account']['allow_cname'] = false;
            $data['account']['type'] = 'shared';
            $data['account']['projected_flag'] = 1;

            $data['account'] = (object)$data['account'];

            $data['bytes']['leftover'] = '0';
            $data['bytes']['cdn_bandwidth'] = '0';
            $data['bytes']['cdn_requests'] = '0';
            $data['bytes']['bandwidth_savings'] = '0';
            $data['bytes']['bandwidth_savings_bytes'] = '0';
            $data['bytes']['original_bandwidth'] = '0';
            $data['bytes']['projected'] = '0';

            // Local
            $data['bytes']['local_requests'] = '0';
            $data['bytes']['local_savings'] = '0';
            $data['bytes']['local_original'] = '0';
            $data['bytes']['local_optimized'] = '0';

            $data['bytes'] = (object)$data['bytes'];

            $data['formatted']['leftover'] = '0';
            $data['formatted']['cdn_bandwidth'] = '0';
            $data['formatted']['cdn_requests'] = '0';
            $data['formatted']['bandwidth_savings'] = '0';
            $data['formatted']['bandwidth_savings_bytes'] = '0';
            $data['formatted']['package_without_extra'] = '0';
            $data['formatted']['original_bandwidth'] = '0';
            $data['formatted']['projected'] = '0';

            // Local
            $data['formatted']['local_requests'] = '0';
            $data['formatted']['local_savings'] = '0 MB';
            $data['formatted']['local_original'] = '0 MB';
            $data['formatted']['local_optimized'] = '0 MB';

            $data['formatted'] = (object)$data['formatted'];
            $data = (object)$data;

            $body = ['success' => true, 'data' => $data];
            $body = (object)$body;

            // Account Status Transient
            set_transient('wps_ic_account_status', $body->data, WPS_IC_ACCOUNT_STATUS_MEMORY);
            self::$accStatusChecked = true;

            update_option('wps_ic_allow_local', false);

            return $body->data;
        }
    }

    public static function createObjectFromJson($json)
    {
        $data = json_decode($json);

        // Create the object structure
        $object = new stdClass();

        // ASite object
        $object->site = new stdClass();
        $object->site->site_url = $data->site_url;

        // Account object
        $object->account = new stdClass();
        $object->account->status = "active";
        $object->account->quotaType = $data->quotaType ?? 'bandwidth';
        $object->account->proSite = $data->proSite;
        $object->account->allowLocal = $data->local_enabled;
        $object->account->allowLive = $data->cdn_enabled;
        $object->account->liveShared = $data->live_shared;
        $object->account->quota = $data->credits;
        $object->account->leftover = $data->display->leftover;
        $object->account->displayQuota = $data->display->credits;
        $object->account->suspended = $data->suspended;
        //$object->account->localShared = "1";

        // Bytes object
        $object->bytes = new stdClass();
        $object->bytes->cdn_requests = $data->requests;
        $object->bytes->cdn_bandwidth = $data->bytes;
        //$object->bytes->projected = $data->bytes * 2.5; // Just an example calculation for projected
        $object->bytes->bandwidth_savings_bytes = $data->savedBytes;
        $object->bytes->bandwidth_savings = $data->savings * 100;
        $object->bytes->original_bandwidth = $data->originalBytes;

        // Formatted
        $object->formatted = new stdClass();
        $object->formatted->cdn_requests = (string)$data->requests;
        $object->formatted->cdn_bandwidth = $data->display->bytes;
        $object->formatted->bandwidth_savings_bytes = $data->display->savedBytes;
        $object->formatted->bandwidth_savings = $data->savings * 100;
        $object->formatted->original_bandwidth = $data->display->originalBytes;

        // Monthly Stats
        $object->monthly = new stdClass();
        $object->monthly->requests = $data->requests;
        $object->monthly->bytes = $data->bytes;
        $object->monthly->formatted = new stdClass();
        $object->monthly->formatted->requests = $data->requests;
        $object->monthly->formatted->bytes = $data->display->bytes;

        // Package Configuration
        $object->packageConfiguration = new stdClass();
        foreach ($data->configuration as $key => $value) {
            $object->packageConfiguration->$key = $value;
        }

        return $object;
    }

    /**
     * Activation of the plugin
     */
    /**
     * Snapshot of active feature toggles — sent with PageSpeed test requests
     * so the MC can correlate score deltas with enabled features.
     */
    public static function getActiveFeatures() {
        $settings = get_option(WPS_IC_SETTINGS);
        $options  = get_option(WPS_IC_OPTIONS);
        $cf       = get_option(WPS_IC_CF);

        return [
            'critical_css'    => !empty($settings['critical']) && !empty($settings['critical']['css']),
            'delay_js'        => !empty($settings['delay-js-v2']) || !empty($settings['delay-js']),
            'cdn'             => !empty($settings['live-cdn']) || !empty($settings['cdn']),
            'lazy_load'       => !empty($settings['lazy']),
            'native_lazy'     => !empty($settings['nativeLazy']),
            'webp'            => !empty($settings['generate_webp']) || !empty($settings['picture_webp']),
            'avif'            => !empty($settings['picture_avif']),
            'minify_js'       => !empty($settings['js_minify']),
            'combine_js'      => !empty($settings['js_combine']),
            'defer_js'        => !empty($settings['js_defer']),
            'cloudflare'      => !empty($cf['zone']),
            'cache'           => !empty($settings['cache']) && !empty($settings['cache']['advanced']),
            'local_compress'  => !empty($settings['local']) && !empty($settings['local']['media-library']),
            'plugin_version'  => self::$version,
        ];
    }

    public static function activation()
    {


        if (get_option('wpc_install_fresh') === false) {
            $wpc_af_settings = get_option(WPS_IC_SETTINGS);
            $wpc_af_fresh = get_option('wpc_settings_initialized') !== '1'
                && (!is_array($wpc_af_settings) || count($wpc_af_settings) <= 3)
                && get_option('wpc_link_preset_applied') === false;
            update_option('wpc_install_fresh', $wpc_af_fresh ? '1' : '0', false);
        }

        // Reset loopback status so it re-tests on next upload
        delete_option('wpc_loopback_status');


        if (function_exists('delete_transient')) {
            delete_transient('wpc_font_rescan_lock');
        }
        $fontSettings = get_option(WPS_IC_SETTINGS);
        if (is_array($fontSettings) && (isset($fontSettings['replace-fonts']) ? $fontSettings['replace-fonts'] : '') === 'local'
            && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_font_rescan')) {
            wp_schedule_single_event(time() + 30, 'wpc_font_rescan');
        }

        // Ensure the telemetry table exists. Also runs on plugins_loaded each
        // request (idempotent, version-gated) for upgrades that skip the hook.
        if (class_exists('WPC_Modern_Delivery') && method_exists('WPC_Modern_Delivery', 'maybe_create_emissions_table')) {
            WPC_Modern_Delivery::maybe_create_emissions_table();
        }


        update_option('wpc_v2_force_provision', 1, false);
        // Activation arms /v2/config until a 2xx lands.
        if (function_exists('wpc_belt_receipt')) {
            wpc_belt_receipt('provision-armed', ['why' => 'activation'], false, '');
        }
        if (function_exists('wpc_v2_schedule_config_sync')) {
            wpc_v2_schedule_config_sync();
        } elseif (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_v2_deferred_config_sync')) {
            wp_schedule_single_event(time(), 'wpc_v2_deferred_config_sync');
        }

        // Purge Object Cache
        $cache = new wps_ic_cache();
        $cache->purgeObjectCache();

        // Setup User Privileges
        $users = new wps_ic_users();

        if (!class_exists('wps_ic_htaccess')) {
            include_once WPS_IC_DIR . 'classes/htaccess.class.php';
        }

        // Add WP_CACHE to wp-config.php
        $htaccess = new wps_ic_htaccess();

        // Setup config file
        $config = new wps_ic_config();
        $config->generateCacheConfig();


        $wpc_cache_settings = get_option(WPS_IC_SETTINGS);
        if (!empty($wpc_cache_settings['cache']['advanced']) && $wpc_cache_settings['cache']['advanced'] == '1') {
            $htaccess->setWPCache(true);
            $htaccess->setAdvancedCache();
        }

        // Setup inline JS Defaults
        $wpc_excludes = get_option('wpc-inline');
        $wpc_excludes['inline_js'] = explode(',', "jquery.min,adaptive,jquery-migrate,wp-includes");
        update_option('wpc-inline', $wpc_excludes);

        // Remove generateCriticalCSS Options
        delete_option('wps_ic_gen_hp_url');
        update_option('wpsShowAdvanced', 'true');

        // Purge All
        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll();

        if (is_multisite()) {
            // Nothing
        } else {
            $options = get_option(WPS_IC_OPTIONS);

            if (!$options || empty($options['api_key'])) {
                return;
            } else {

                self::check_account_status(true);

                // Setup Default Options
                $options = new wps_ic_options();
                $settings = get_option(WPS_IC_SETTINGS);


                $wpc_settings_init = get_option('wpc_settings_initialized') === '1';
                if (!$wpc_settings_init) {
                    if (!$settings || count($settings) <= 3) {
                        $options->set_defaults();
                    }
                    update_option('wpc_settings_initialized', '1', false);
                }

                $purge_rules = get_option('wps_ic_purge_rules');

                if ($purge_rules === false) {
                    $purge_rules = $options->get_preset('purge_rules');
                    update_option('wps_ic_purge_rules', $purge_rules, false);
                }

                $cache_cookies = get_option('wps_ic_cache_cookies');

                if ($cache_cookies === false) {
                    $cache_cookies = $options->get_preset('cache_cookies');
                    update_option('wps_ic_cache_cookies', $cache_cookies);
                }

                if (!file_exists(WPS_IC_DIR . 'cache')) {
                    // Folder does not exist
                    mkdir(WPS_IC_DIR . 'cache', 0755);
                } else {
                    // Folder exists
                    if (!is_writable(WPS_IC_DIR . 'cache')) {
                        chmod(WPS_IC_DIR . 'cache', 0755);
                    }
                }
            }
        }
    }

    /**
     * Deactivation of the plugin
     * Notify our API the plugin is disconnected
     */
    public static function deactivation($plugin)
    {
        if ($plugin === 'wp-compress-image-optimizer/wp-compress.php') {
            // Remove cron jobs
            $timestamp = wp_next_scheduled('runCronPreload');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'runCronPreload');
            }

            if (!class_exists('wps_ic_htaccess')) {
                include_once WPS_IC_DIR . 'classes/htaccess.class.php';
            }

            // Remove HtAccess Rules
            $htaccess = new wps_ic_htaccess();
            $htaccess->removeHtaccessRules();
            // Also strip the static-serve block + clear the TTFB auto-arm flag (v7.10.357):
            // a deactivated plugin must not leave zero-PHP serve rules or resume static serve
            // on reactivation without a fresh host self-test. The zone purge_everything below
            // flushes any untagged mirror HTML already at the CF edge.
            $htaccess->removeStaticServe();

            // Clear our recurring v2 events: leftovers reference the custom
            // wpc_v2_5min interval, which no longer registers once we're
            // deactivated — WP then logs invalid_schedule every cron pass.
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook('wpc_v2_journal_drain_cron');
                wp_clear_scheduled_hook('wpc_v2_pull_cron');
                wp_clear_scheduled_hook('wpc_v2_provheal_cron');
            }

            // Add WP_CACHE to wp-config.php
            $htaccess->setWPCache(false);
            $htaccess->removeAdvancedCache();


            static $wpc_deact_cf_purged = false;
            if (!$wpc_deact_cf_purged && !empty(get_option(WPS_IC_CF))) {
                $wpc_deact_cf_purged = true;
                if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR') && file_exists(WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php')) {
                    @include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
                }
                $wpc_cf = get_option(WPS_IC_CF);
                if (class_exists('WPC_CloudflareAPI') && !empty($wpc_cf['token']) && !empty($wpc_cf['zone'])) {
                    try {
                        $wpc_cfapi = new WPC_CloudflareAPI($wpc_cf['token']);
                        if ($wpc_cfapi) {
                            // Fire-and-forget (blocking=false, timeout=0.01) — dispatches the zone
                            // purge_everything without delaying the deactivation HTTP response.
                            if (method_exists($wpc_cfapi, 'purgeCacheAsync')) {
                                $wpc_cfapi->purgeCacheAsync($wpc_cf['zone']);
                            } else {
                                $wpc_cfapi->purgeCache($wpc_cf['zone']);
                            }
                        }
                    } catch (\Throwable $e) {
                        // A CF API error (bad token / network) must never block or fatal deactivation.
                    }
                }
            }

            // Purge Cached Files
            $cacheLogic = new wps_ic_cache();
            if (file_exists(WPS_IC_CACHE)) {
                $cacheLogic::deleteFolder(WPS_IC_CACHE);
            }


            if (file_exists(WPS_IC_COMBINE)) {
                $cacheLogic::deleteFolder(WPS_IC_COMBINE);
            }
            // v7.22.38 — A DEACTIVATED PLUGIN MUST NOT LEAVE ITS MARKUP IN SOMEONE ELSE'S CACHE.
            // aliiadventureshack: LiteSpeed kept serving our rendered HTML for its full 7-day TTL
            // after the folders above were deleted — a preload for a wp-cio sheet that no longer
            // existed (404 -> ORB-blocked), the loader beaconing to an action nobody registers
            // (admin-ajax 400), every "our" console line on a site we were no longer running on.
            // Cloudflare was already purged here; the page-cache fan-out was not.
            try {
                if (method_exists('wps_ic_cache', 'purgeOtherCache')) {
                    wps_ic_cache::purgeOtherCache(false);
                }
            } catch (\Throwable $e) {
            }

            // Remove Stats Transients
            delete_transient('wps_ic_live_stats');
            delete_transient('wps_ic_local_stats');

            // Remove generateCriticalCSS Options
            delete_option('wps_ic_gen_hp_url');
            delete_option(WPS_IC_GUI);
            delete_option('wps_log_critCombine');

            // Multisite Settings
            $settings = get_option(WPS_IC_MU_SETTINGS);
            $settings['hide_compress'] = 0;
            update_option(WPS_IC_MU_SETTINGS, $settings);

            // Remove from active on API
            $options = get_option(WPS_IC_OPTIONS);
            $site = site_url();
            $apikey = $options['api_key'];

            $newOptions = $options;
            $newOptions['regExUrl'] = '';
            $newOptions['regexpDirectories'] = '';
            update_option(WPS_IC_OPTIONS, $newOptions);

            // Setup URI
            $uri = WPS_IC_KEYSURL . '?action=disconnect&apikey=' . $apikey . '&site=' . urlencode($site);

            // Verify API Key is our database and user has is confirmed getresponse
            $get = wp_remote_get($uri, ['timeout' => 5, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
        }
    }

    public static function checkQuotaStatus()
    {
        if (get_transient('wps_icQuotaStatus')) {
            return;
        }
        // Durable floor: a flushed object cache must not re-fire this per admin request
        if (time() - (int) get_option('wpc_quota_checked_at') < 300) {
            return;
        }
        $settings = get_option(WPS_IC_OPTIONS);
        if (empty($settings['api_key'])) {
            return;
        }
        update_option('wpc_quota_checked_at', time(), false);
        // Fire-and-forget stats nudge — never block the admin footer on the KEYSURL
        // account ping. Detach to a one-shot background event (the .403 pattern); the
        // result renders nothing, so the page owes it no wait.
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_quota_status_refresh')
            && apply_filters('wpc_quota_status_async', true)) {
            wp_schedule_single_event(time() + 2, 'wpc_quota_status_refresh');
            if (function_exists('spawn_cron')) {
                wpc_spawn_cron();
            }
        }
    }

    public static function checkQuotaStatusRefresh()
    {
        $settings = get_option(WPS_IC_OPTIONS);
        if (empty($settings['api_key'])) {
            return;
        }
        $call = wp_remote_get(WPS_IC_KEYSURL . '?action=get_account_status_v6&apikey=' . $settings['api_key'] . '&range=month&hash=' . md5(mt_rand(999, 9999)), ['timeout' => 10, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
        if (wp_remote_retrieve_response_code($call) == 200) {
            set_transient('wps_icQuotaStatus', 'true', 60 * 30);
        }
    }

    /**
     * Popup on plugin deactivation button
     * @return void
     */
    public static function deactivate_script()
    {
        wp_enqueue_style('wp-pointer');
        wp_enqueue_script('wp-pointer');
        wp_enqueue_script('utils');
        $nonceVar = wp_create_nonce('wps_ic_nonce_action');
        ?>
        <script type="text/javascript">
            function deactivateButton() {
                var row = jQuery('tr:has(span.wps-ic-reconnect)');  // Targets rows containing the 'wps-ic-reconnect' span
                var span_deactivate = jQuery('span.deactivate', row);
                var link = jQuery('a', span_deactivate);
                var pointer = '';

                // Get the original deactivate URL
                var deactivateHref = jQuery(link).attr('href');

                var url = new URL(deactivateHref, window.location.origin);
                url.searchParams.set("action", "deactivate_and_disconnect");
                // Remove protocol + domain
                var updatedDeactivateHref = (url.pathname + url.search).replace(/^\//, "");

                jQuery(link).on('click', function (e) {
                    e.preventDefault();
                    jQuery('.wp-pointer').hide();

                    pointer = jQuery(this).pointer({
                        content: '<h3>Are you sure you want to deactivate?</h3>' +
                            '<div class="wpc-boxed-outter">' +
                            '<p>Deactivating may cause the following:</p>' +
                            '<ul style="padding:0px 15px;margin:0px 10px;' +
                            'list-style:disc;">'
                            + '<li>Significantly higher bounce rates</li>'
                            + '<li>Slow loading images for incoming visitors</li>'
                            + '<li>Backups removed from our cloud</li>'
                            + '<li>Our team crying that you’ve left... <?php echo '<img src="' . WPS_IC_URI . '/assets/crying.png" style="width:19px;" />';?></li>'
                            + '</ul>'
                            + '<div class="wpc-boxed">If you have any questions or issues, please contact us. We\'ll be happy to make sure everything is running fast and smooth for you!</div>'
                            + '</div>'
                            + '<div class="wpc-boxed-footer">'
                            + '<a id="wps-ic-leave-active" class="button ' + 'button-primary" href="#">Keep Active</a>'
                            + '<div class="tooltip-container">'
                            + '<a id="everything" class="button ' + 'button-secondary" ' + 'href="' + jQuery(link).attr('href') + '">Temporarily Deactivate</a>'
                            + '<span class="tooltip-text">This will just turn off the plugin. All your settings and cloud-connected images will be saved for when you reactivate.</span>'
                            + '</div>'
                            + '<div class="tooltip-container align-right">'
                            + '<a id="wps-ic-delete" class="" ' + 'href="' + updatedDeactivateHref + '" style="font-size: 10px;">Disconnect & Deactivate</a>'
                            + '<span class="tooltip-text">This will turn off the plugin, disconnect your site from our service, and may remove your backups from the cloud.</span>'
                            + '</div>'
                            + '</div>',
                        position: {
                            my: 'left top',
                            at: 'left top',
                            offset: '0 0',
                        },
                        close: function () {
                            //
                        }
                    }).pointer('open');

                    var $p = jQuery(pointer).pointer('widget');
                    $p.addClass('wps-ic-pointer');

                    $p[0].style.setProperty('display', 'block', 'important');
                    $p[0].style.setProperty('visibility', 'visible', 'important');
                    $p[0].style.setProperty('opacity', '1', 'important');
                    $p[0].style.setProperty('z-index', '999999', 'important');


                    // Apply width after opening
                    jQuery('.wp-pointer').css({
                        width: '440px',
                        maxWidth: '440px'
                    });

                    jQuery('.wp-pointer').addClass('wpc-custom-pointer');

                    jQuery('#wps-ic-leave-active', '.wp-pointer-content').on('click', function (e) {
                        e.preventDefault();
                        jQuery(pointer).pointer('close');
                        return false;
                    });

                    jQuery('#wps-ic-leave-active', '.wp-pointer-content').on('click', function (e) {
                        e.preventDefault();
                        jQuery(pointer).pointer('close');
                        return false;
                    });

                    jQuery('.wp-pointer-buttons').hide();

                    return false;
                });
            }

            function reconnectButton() {
                var row = jQuery('tr:has(span.wps-ic-reconnect)');  // Targets rows containing the 'wps-ic-reconnect' span
                var span_reconnect = jQuery('span.wps-ic-reconnect', row);
                var link = jQuery('a', span_reconnect);
                var pointer = '';

                jQuery(link).on('click', function (e) {
                    e.preventDefault();
                    jQuery('.wp-pointer').hide();

                    pointer = jQuery(this).pointer({
                        content: '<h3>Are You Sure...</h3>' +
                            '<div class="wpc-boxed-outter">' +
                            '<p>If you continue, you will need your API Key in order to Reconnect the plugin.</p>' +
                            '<p class="wps-ic-helpdesk-link">If you have any questions or issues, please visit our <a href="https://help.wpcompress.com/en-us/" target="_blank">helpdesk</a>.</p>' +
                            '</div>' +
                            '<div class="wpc-boxed-footer">' +
                            '<a id="wps-ic-leave-active" class="button button-primary" href="#">Leave Connected</a>' +
                            '<a id="wps-ic-reconnect-confirm" class="button button-secondary wps-ic-reconnect-confirm" href="' + jQuery(link).attr('href') + '">Reconnect Anyway</a>' +
                            '</div>',
                        position: {
                            my: 'left top',
                            at: 'left top',
                            offset: '0 0'
                        },
                        close: function () {
                            //
                        }
                    }).pointer('open');

                    var $p = jQuery(pointer).pointer('widget');
                    $p.addClass('wps-ic-pointer');

                    $p[0].style.setProperty('display', 'block', 'important');
                    $p[0].style.setProperty('visibility', 'visible', 'important');
                    $p[0].style.setProperty('opacity', '1', 'important');
                    $p[0].style.setProperty('z-index', '999999', 'important');

                    // Apply width + custom styling after opening (match deactivate pointer)
                    jQuery('.wp-pointer').css({
                        width: '440px',
                        maxWidth: '440px'
                    });
                    jQuery('.wp-pointer').addClass('wpc-custom-pointer');

                    jQuery('#wps-ic-reconnect-confirm', '.wp-pointer-content').on('click', function (e) {
                        e.preventDefault();
                        jQuery.post(ajaxurl, {action: 'wps_ic_remove_key', wps_ic_nonce: '<?php echo $nonceVar; ?>'}, function (response) {
                            if (response.success) {
                                window.location.reload();
                            }
                        });
                        return false;
                    });

                    jQuery('#wps-ic-leave-active', '.wp-pointer-content').on('click', function (e) {
                        e.preventDefault();
                        jQuery(pointer).pointer('close');
                        return false;
                    });

                    jQuery('.wp-pointer-buttons').hide();

                    return false;
                });
            }

            jQuery(document).ready(function ($) {
                deactivateButton();
                reconnectButton();
            });
        </script><?php
    }

    public function offloaderHooks()
    {
        $offloader = new wps_ic_offloading();
    }

    /**
     * WP Init helper
     */
    public function init()
    {
        if (!is_admin()) {
            // Raise memory limit
            if (ini_get('memory_limit') !== '-1' && wpc_convert_to_bytes(ini_get('memory_limit')) < 1024 * 1024 * 1024) {
                ini_set('memory_limit', '1024M');
            }
        }

        //Display notice if site url changed
        add_action('admin_init', function () {
            if (!function_exists('wpc_set_state_notice')) { return; }
            if (!get_option('wps_ic_url_changed')) { wpc_clear_state_notice('url_changed'); return; }
            wpc_set_state_notice('url_changed', 'error', __('Your site address changed. Reconnect with a new API key to resume optimization.', 'wp-compress-image-optimizer'), wpc_settings_page_url(), __('Reconnect', 'wp-compress-image-optimizer'));
        }, 30);

        // Critical API
        $this->fetchCritical();
        $this->fetchPageSpeed();

        /**
         * Force Show WP Compress
         */
        if (!empty($_GET['show_optimizer'])) {
            $settings = get_option(WPS_IC_SETTINGS);
            $settings['hide_compress'] = '0';
            update_option(WPS_IC_SETTINGS, $settings);
        }

        if (!empty($_GET['getPagesJSON'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->getPagesJSON();
            die();
        }

        if (!empty($_GET['updateStatus'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->updateStatus();
            die();
        }


        if (!empty($_GET['deliverError'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->deliverError();
            die();
        }

        if (!empty($_GET['desktopCritUrl'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->downloadDesktopCrit();
            die();
        }

        if (!empty($_GET['mobileCritUrl'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->downloadMobileCrit();
            die();
        }

        if (!empty($_GET['getWarmupLog'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->getWarmupLog();
            die();
        }

        if (!empty($_GET['override_version'])) {
            self::$version = mt_rand(100, 999);
        }

        if (is_admin() || !empty($_GET['_locale'])) {

            self::$local = new wps_local_compress();
            wps_local_compress::register_hooks();
        }

        // Get Options
        $this::$js_debug = get_option('wps_ic_js_debug');
        $this::$settings = get_option(WPS_IC_SETTINGS);
        $this::$options = get_option(WPS_IC_OPTIONS);

        // Add User Capabilities
        $user = new wps_ic_users();

        if (empty($this::$settings)) {
            $this::$settings = [];
        }


        if (empty($this::$options)) {
            $this::$options = [];
        }


        //CUSTOM_CONSTRUCT_HERE

        if (!empty($_GET['ignore_ic'])) {
            return;
        }


        if (!empty($_GET['wpc_optimization_done']) && sanitize_text_field($_GET['apikey']) == self::$options['api_key']) {

            delete_transient('wpc-page-optimizations-status');
            die('Ended');
        }

        if (!empty($_GET['wpc_start_test']) && sanitize_text_field($_GET['apikey']) == self::$options['api_key']) {
            $id = sanitize_text_field($_GET['id']);
            if (get_transient('wpc-page-optimizations-status') !== false) {
                set_transient('wpc-page-optimizations-status', ['id' => $id, 'status' => 'test'], 60 * 2);
            }
            $warmup = new wps_ic_preload_warmup();
            $warmup->doTest($id, true);
            die('Test done?');
        }

        if (!empty($_GET['fetchTest']) && sanitize_text_field($_GET['apikey']) == self::$options['api_key']) {
            $warmup = new wps_ic_preload_warmup();
            $testUrl = $warmup::$apiUrl . 'tests/' . $_GET['fetchTest'];
            $download = wp_remote_get($testUrl, ['timeout' => 10, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (!is_wp_error($download)) {
                $body = wp_remote_retrieve_body($download);
                $body = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $tests = get_option(WPS_IC_TESTS);
                    $tests['home'] = $body;

                    delete_transient('wpc_initial_test');

                    update_option(WPS_IC_TESTS, $tests);
                    update_option(WPS_IC_LITE_GPS, ['result' => $body, 'failed' => false, 'lastRun' => time()]);


                    if (!empty($body['insights'])
                        && (int) ($body['insights']['schema_version'] ?? 0) === 1
                        && empty($body['insights']['error'])) {
                        update_option('wpc_psi_insights', [
                            'data'           => $body['insights'],
                            'schema_version' => 1,
                            'lastRun'        => time(),
                        ], false);
                    }

                    if (!empty($body['testID'])) {
                        $warmupLog = get_option(WPC_WARMUP_LOG_SETTING, []);
                        $warmupLog[$body['testID']] = ['ended' => date('Y-m-d H:i:s')];
                        update_option(WPC_WARMUP_LOG_SETTING, $warmupLog);
                    }
                    wp_send_json_success($tests);
                } else {
                    wp_send_json_error('json-error');
                }
            }
            wp_send_json_error('download-error');
        }


        if (!empty($_GET['show_wpcompress_plugin'])) {
            delete_option('hide_wpcompress_plugin');
            delete_option('pause_wpcompress_plugin');
        }


        // v7.21.138 — ORPHANED WHITE-LABEL HIDE SELF-HEALS. The hide flag is set by the WL
        // wrapper (class whtlbl_whitelabel_plugin); uninstalling the wrapper leaves the option
        // behind and a plain repo install then hides itself from the plugins list forever,
        // with no visible way out (the ?show_wpcompress_plugin=1 hatch requires knowing it
        // exists). No wrapper loaded = nobody owns the hide = clear it.
        if (get_option('hide_wpcompress_plugin')
            && !class_exists('whtlbl_whitelabel_plugin') && !defined('WHITE_LABEL_DIR')) {
            // DEACTIVATED is not DELETED: an agency toggling the wrapper off for a debug
            // session must not expose the plugin to the client — the wrapper's files still
            // on disk keep the hide. Only a wrapper GONE FROM DISK (deleted) is ownerless.
            // The glob runs only in this rare state (flag set + wrapper not loaded).
            // A glob ERROR (false) is uncertainty, and uncertainty keeps the hide — only a
            // real empty scan proves the wrapper is gone.
            $whitelabelWrappers = defined('WP_PLUGIN_DIR') ? glob(WP_PLUGIN_DIR . '/*/whitelabel.php') : false;
            if (is_array($whitelabelWrappers) && count($whitelabelWrappers) === 0) {
                delete_option('hide_wpcompress_plugin');
                delete_option('pause_wpcompress_plugin');
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('wl-hide-orphan-healed', '', '', []);
                }
            }
        }

        if (get_option('hide_wpcompress_plugin')) {
            function whitelabel_hide_specific_plugin($plugins)
            {
                // Check if the specific plugin is set in the list
                if (isset($plugins['wp-compress-image-optimizer/wp-compress.php'])) {
                    // Remove the specific plugin from the list
                    unset($plugins['wp-compress-image-optimizer/wp-compress.php']);
                }

                return $plugins;
            }

            add_filter('all_plugins', 'whitelabel_hide_specific_plugin');
        }


        if (self::dontRunif()) {
            return;
        }

        if ((!empty($_GET['wps_ic_action']) || !empty($_GET['run_restore']) || !empty($_GET['run_compress'])) && !empty($_GET['apikey'])) {
            $options = get_option(WPS_IC_OPTIONS);
            $apikey = sanitize_text_field($_GET['apikey']);
            if ($apikey !== $options['api_key']) {
                die('Hacking?');
            }
        }

        $this::$settings = $this->fillMissingSettings($this::$settings);


        if (empty($this::$settings['live-cdn']) || $this::$settings['live-cdn'] != '1') {
            $cfSettings = get_option(WPS_IC_CF);
            if (!empty($cfSettings['settings']['cdn']) && $cfSettings['settings']['cdn'] == '1') {
                $this::$settings['live-cdn'] = '1';
            } else {
                $cdnOn = false;
                if (!empty($this::$settings['serve'])) {
                    foreach ($this::$settings['serve'] as $v) {
                        if ($v == '1') { $cdnOn = true; break; }
                    }
                }
                if (!$cdnOn && !empty($this::$settings['css']) && $this::$settings['css'] == '1') $cdnOn = true;
                if (!$cdnOn && !empty($this::$settings['js']) && $this::$settings['js'] == '1') $cdnOn = true;
                if (!$cdnOn && !empty($this::$settings['fonts']) && $this::$settings['fonts'] == '1') $cdnOn = true;
                if ($cdnOn) $this::$settings['live-cdn'] = '1';
            }
        }

        /**
         * Figure out ZoneName
         */
        if (empty($this::$settings['cname']) || !$this::$settings['cname']) {
            $this::$zone_name = get_option('ic_cdn_zone_name');
        } else {
            $custom_cname = get_option('ic_custom_cname');
            $this::$zone_name = $custom_cname;
        }

        /**
         * Figure out Quality
         */
        if (empty($this::$settings['optimization']) || $this::$settings['optimization'] == '' || $this::$settings['optimization'] == '0') {
            $this::$quality = 'intelligent';
        } else {
            $this::$quality = $this::$settings['optimization'];
        }

        if (empty($this::$options['css_hash'])) {
            $this::$options['css_hash'] = 5021;
        }

        if (!empty($_GET['random_css_hash'])) {
            define('WPS_IC_HASH', substr(md5(microtime(true)), 0, 6));
        } elseif (!defined('WPS_IC_HASH')) {
            define('WPS_IC_HASH', $this::$options['css_hash']);
        }

        if (empty($this::$options['js_hash'])) {
            $this::$options['js_hash'] = 5021;
        }

        if (!empty($_GET['random_js_hash'])) {
            define('WPS_IC_JS_HASH', substr(md5(microtime(true)), 0, 6));
        } elseif (!defined('WPS_IC_JS_HASH')) {
            define('WPS_IC_JS_HASH', $this::$options['js_hash']);
        }

        // Plugin Settings
        if (empty($this::$options['api_key'])) {
            self::$api_key = '';
        } else {
            self::$api_key = $this::$options['api_key'];
        }

        // Required to Extract Key - DO NOT REMOVE!
        $this->isAgencyPortal();

        if (empty($this::$options['response_key'])) {
            self::$response_key = '';
        } else {
            self::$response_key = $this::$options['response_key'];
        }

        #$this->offloading = new wps_ic_offloading();
        $this->upgrader = new wps_ic_upgrader();
        $this->mainwp = new wps_ic_mainwp();

        if ($this->isAgencyPortal()) {

            #$this->inAdmin();
            $this->enqueues = new wps_ic_enqueues();
            $this->ajax = new wps_ic_ajax();

            // Output the #select-mode popup template in wp_footer (agency runs in frontend context)
            $modes = new wps_ic_modes();
            add_action('wp_footer', [$modes, 'showPopup']);

        } else {

            if (is_admin()) {
                $this->inAdmin();
            } else {
                // Add Elementor Bg Lazy
                $bgLazy = new wps_ic_bgLazy();
                $this->inFrontEnd();
            }

        }

        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return;
        }

        // Change PHP Limits
        $wps_ic = $this;
        do_action('wps_ic_init');
    }


    public function inAgency() {
        $this->enqueues = new wps_ic_enqueues();
    }


    public function fetchCritical()
    {
        if (!empty($_GET['criticalDone'])) {
            $jobStatus = [];


            $wpc_cb_body = json_decode((string) @file_get_contents('php://input'), true);
            if (!is_array($wpc_cb_body)) { $wpc_cb_body = []; }
            // The consolidated callback carries its fields in the JSON body (the epoch the
            // service stamped on the generation among them, as the webhook's body does); the
            // legacy ping carries them in the query. One set of names serves both.
            foreach (['uuid', 'apikey', 'pageUrl', 'lcp_url', 'delay_url', 'used_css_url', 'tpl_key', 'ready', 'epoch'] as $wpc_ck) {
                if ((!isset($_GET[$wpc_ck]) || $_GET[$wpc_ck] === '') && isset($wpc_cb_body[$wpc_ck])
                    && is_scalar($wpc_cb_body[$wpc_ck]) && $wpc_cb_body[$wpc_ck] !== '') {
                    $_GET[$wpc_ck] = (string) $wpc_cb_body[$wpc_ck];
                }
            }

            // Atomic Generation Contract (spec v1, frozen 2026-08-02) — Law 3 webhook. ONE
            // event per pointer flip, idempotent and replayable, fired only after the manifest
            // HEAD-verified. This branch is the manifest-aware consume; legacy callbacks keep
            // the unchanged flow below for non-manifest gens and old service versions.
            if ((string) ($wpc_cb_body['event'] ?? '') === 'generation_complete'
                && !empty($wpc_cb_body['manifest_url']) && !empty($wpc_cb_body['gen_id'])
                && apply_filters('wpc_manifest_webhook', true)) {
                $wpc_manifest_options = get_option(WPS_IC_OPTIONS);
                $wpc_manifest_api_key = is_array($wpc_manifest_options) && !empty($wpc_manifest_options['api_key']) ? (string) $wpc_manifest_options['api_key'] : '';
                // Sig: the existing recipe, unchanged — HMAC-SHA256(apikey, "gen_id|url_key|ts").
                // The apikey-param equality is the migration fallback (proxies strip headers).
                $wpc_manifest_sig = (string) ($_SERVER['HTTP_X_WPC_SIG'] ?? ($wpc_cb_body['sig'] ?? ''));
                $wpc_manifest_expected_sig = $wpc_manifest_api_key === '' ? '' : hash_hmac('sha256',
                    (string) $wpc_cb_body['gen_id'] . '|' . (string) ($wpc_cb_body['url_key'] ?? '') . '|' . (string) ($wpc_cb_body['ts'] ?? ''),
                    $wpc_manifest_api_key);
                $wpc_manifest_authorized = ($wpc_manifest_expected_sig !== '' && $wpc_manifest_sig !== '' && hash_equals($wpc_manifest_expected_sig, $wpc_manifest_sig))
                    || ($wpc_manifest_api_key !== '' && !empty($_GET['apikey']) && $wpc_manifest_api_key === sanitize_text_field((string) $_GET['apikey']));
                if (!$wpc_manifest_authorized) {
                    // v7.21.137 — crit-team ask: a rejected webhook must be a real non-2xx so the
                    // service's manifest_webhook_failed metric sees the truth (their .115 accepts
                    // either, but 403 makes the failure visible fleet-wide without our logs).
                    wp_send_json_error('sig-failure', 403);
                }
                @ignore_user_abort(true);
                if (function_exists('set_time_limit')) { @set_time_limit(180); }
                if (!headers_sent()) { http_response_code(200); }
                if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) { wpc_finish_request(); }
                if (!class_exists('wps_ic_url_key')) {
                    include_once WPS_IC_DIR . 'traits/url_key.php';
                }
                // url_key is the page identity (host + path, query-free) — same LAND-KEY LAW
                // derivation as the legacy branch; header fallback for an empty field.
                $wpc_manifest_page_url = (string) strtok((string) ($wpc_cb_body['url_key'] ?? ''), '?');
                if ($wpc_manifest_page_url === '' && !empty($_SERVER['HTTP_HOST'])) {
                    $wpc_manifest_page_url = (string) $_SERVER['HTTP_HOST'] . (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
                }
                $wpc_manifest_url_key = (new wps_ic_url_key())->setup($wpc_manifest_page_url);
                $wpc_manifest_result = 0;
                if (!empty($wpc_manifest_url_key) && function_exists('wpc_manifest_consume')) {
                    $wpc_manifest_result = (int) wpc_manifest_consume((string) $wpc_manifest_url_key, (string) $wpc_cb_body['manifest_url'], (string) $wpc_cb_body['gen_id'], $wpc_manifest_page_url, $wpc_cb_body['epoch'] ?? null);
                }
                wp_send_json_success(['manifest' => $wpc_manifest_result]);
            }

            $uuid = sanitize_text_field($_GET['uuid'] ?? '');
            $apikey = sanitize_text_field($_GET['apikey'] ?? '');

            if (!empty($uuid) && !empty($apikey)) {
                $options = get_option(WPS_IC_OPTIONS);
                $dbApiKey = $options['api_key'];

                // The consolidated callback CARRIES data (delay.json, lcp.json ride its body) and the service signs
                // every one (server.js:17419): X-WPC-Sig = HMAC-SHA256(apikey, "uuid|url_key|ts"), X-WPC-Ts in ms.
                // WHAT THE CHECK PROVES, exactly: the sender holds this site's API key, and the uuid, the url_key
                // and the timestamp are bound to each other (so neither identifier can be swapped and the POST
                // cannot be replayed outside the 600 s window). It does NOT cover the inline bodies — they are not
                // signed material — so their integrity in transit is TLS's, not this signature's. The uuid-derived
                // land keeps the apikey equality (it re-derives every URL); the inline consume needs the sig plus
                // the url_key binding below, which is what ties the signed identifiers to the page written here.
                // FAIL OPEN on a missing header: a service build or a proxy that strips it still lands crit, and a
                // dropped inline body is logged (callback-unsigned) so the fleet's header-stripping rate is
                // measurable before the require-sig filter below is ever used to tighten.
                $callbackSig = strtolower((string) ($_SERVER['HTTP_X_WPC_SIG'] ?? ''));
                $callbackTs  = (string) ($_SERVER['HTTP_X_WPC_TS'] ?? '');
                $callbackSigned = false;
                if ((string) $dbApiKey !== '' && preg_match('/^[a-f0-9]{64}$/', $callbackSig) && ctype_digit($callbackTs)) {
                    $callbackWhen = (float) $callbackTs;
                    if ($callbackWhen > 20000000000) { $callbackWhen = $callbackWhen / 1000; }
                    $callbackSigned = abs(time() - $callbackWhen) <= 600
                        && hash_equals(hash_hmac('sha256', $uuid . '|' . (string) ($wpc_cb_body['url_key'] ?? '') . '|' . $callbackTs, (string) $dbApiKey), $callbackSig);
                }

                if ($dbApiKey == $apikey) {

                    // Detach: the service needs the 200, not our grind — artifacts save in
                    // the background; the storage-pointer watcher is the real receipt
                    @ignore_user_abort(true);
                    if (function_exists('set_time_limit')) { @set_time_limit(180); }
                    if (!headers_sent()) { http_response_code(200); }
                    if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) { wpc_finish_request(); }

                    if (!empty($_GET['debug'])) {
                        ini_set('display_errors', 1);
                        error_reporting(E_ALL);
                    }

                    if (!class_exists('wps_ic_url_key')) {
                        include_once WPS_IC_DIR . 'traits/url_key.php';
                    }

                    $urlKey = new wps_ic_url_key();
                    $pageUrl = sanitize_url(urldecode($_GET['pageUrl'] ?? ''));
                    // LAND-KEY LAW (drill receipt 2026-07-20): the callback's transport params
                    // (criticalDone/uuid/apikey) are not page identity — keying on the request
                    // URI landed crit at "...criticaldone-true" where no render reads it.
                    // Generated URLs are admission-canonical (query-free), so the land key is
                    // derived query-free; empty pageUrl falls back to host + path only.
                    if ($pageUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
                        $pageUrl = (string) $_SERVER['HTTP_HOST'] . (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
                    }
                    $urlKey = $urlKey->setup((string) strtok($pageUrl, '?'));

                    // v7.10.389 — INLINE CONSUMPTION on the legacy criticalDone callback. The
                    // service routes observation_late HERE (not the action=wpc_crit_published
                    // webhook), and this handler only hoisted locator URLs — so lcp_json/delay
                    // inline bodies were dropped and the lcp fell to the load-gated repull, which
                    // a permanently-pinned box (busyprosai) defers forever. Consuming inline is
                    // zero-HTTP: the fresh lcp lands past the load gate. url_key is derived
                    // query-free above (own setup), so writes never ghost-dir.
                    // These two bodies are the only artifacts this callback CARRIES, so they are the
                    // only part of it that needs more than apikey equality: they consume on a verified
                    // X-WPC-Sig (computed above) whose signed url_key resolves to the key written here.
                    // A signature for another page is a valid signature — without the binding it would
                    // authorise planting that page's bodies in this directory. An unsigned callback, and
                    // one signed for a different key, still land crit below.
                    $callbackSignedPageKey = $callbackSigned
                        ? ltrim((string) (new wps_ic_url_key())->setup((string) strtok((string) ($wpc_cb_body['url_key'] ?? ''), '?')), '/')
                        : '';
                    $callbackBoundToPage = $callbackSigned && $callbackSignedPageKey !== ''
                        && $callbackSignedPageKey === ltrim((string) $urlKey, '/');
                    if ($callbackSigned && !$callbackBoundToPage && function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('callback-key-mismatch', (string) $urlKey, '',
                            ['signed_key' => $callbackSignedPageKey, 'page_key' => ltrim((string) $urlKey, '/')]);
                    }
                    if (defined('WPS_IC_CRITICAL') && function_exists('wpc_crit_meta_write')) {
                        $wpc_cbdir = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim((string) $urlKey, '/') . '/';
                        if (!is_dir($wpc_cbdir) && function_exists('wp_mkdir_p')) { @wp_mkdir_p($wpc_cbdir); }
                        if (!apply_filters('wpc_callback_require_sig', true) || $callbackBoundToPage) {
                            if (!empty($wpc_cb_body['delay_inline']) && !empty($wpc_cb_body['delay']) && is_array($wpc_cb_body['delay'])
                                && function_exists('wpc_delay_inline_fresher') && wpc_delay_inline_fresher($wpc_cbdir . 'delay.json', $wpc_cb_body['delay'])
                                && class_exists('wps_ic_js_delay_v3') && wps_ic_js_delay_v3::wpc_delay_measured_shape($wpc_cb_body['delay'])
                                && apply_filters('wpc_delay_inline_consume', true)) {
                                $wpc_delay_inline_json = wp_json_encode($wpc_cb_body['delay']);
                                if (is_string($wpc_delay_inline_json) && $wpc_delay_inline_json !== '' && strlen($wpc_delay_inline_json) <= 524288) {
                                    wpc_crit_meta_write($wpc_cbdir . 'delay.json', $wpc_delay_inline_json);
                                    delete_option('wpc_delay_v3_manifest_off');
                                    delete_option('wpc_delay_v3_promoted');
                                    if (function_exists('wpc_delay_aggr_rearm')) { wpc_delay_aggr_rearm(); }
                                    if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('delay-inline-landed', (string) $urlKey, '', ['via' => 'criticalDone', 'bytes' => strlen($wpc_delay_inline_json)]); }
                                }
                            }
                            $wpc_callback_lcp = (isset($wpc_cb_body['lcp_json']) && is_array($wpc_cb_body['lcp_json'])) ? $wpc_cb_body['lcp_json']
                                : ((isset($wpc_cb_body['lcp']) && is_array($wpc_cb_body['lcp'])) ? $wpc_cb_body['lcp'] : null);
                            if (!empty($wpc_cb_body['lcp_inline']) && is_array($wpc_callback_lcp)
                                && (isset($wpc_callback_lcp['lcp_element']) || isset($wpc_callback_lcp['hints']))
                                && apply_filters('wpc_lcp_inline_consume', true)) {
                                $wpc_lcp_inline_json = wp_json_encode($wpc_callback_lcp);
                                if (is_string($wpc_lcp_inline_json) && $wpc_lcp_inline_json !== '' && strlen($wpc_lcp_inline_json) <= 524288) {
                                    $wpc_previous_lcp_auth = function_exists('wpc_lcp_first_auth')
                                        ? wpc_lcp_first_auth(json_decode((string) @file_get_contents($wpc_cbdir . 'lcp.json'), true)) : null;
                                    wpc_crit_meta_write($wpc_cbdir . 'lcp.json', $wpc_lcp_inline_json);
                                    @unlink($wpc_cbdir . 'lcp_none.txt');
                                    // bare->skip flip: sync CF eviction, past the batched queue a pinned box defers.
                                    if ($wpc_previous_lcp_auth !== false && function_exists('wpc_lcp_first_auth') && wpc_lcp_first_auth($wpc_callback_lcp) === false
                                        && function_exists('wpc_lcp_edge_flip_purge')) {
                                        wpc_lcp_edge_flip_purge((string) strtok((string) $pageUrl, '?'));
                                    }
                                    if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                                        function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced((string) $urlKey, '', 'lcp-inline-criticalDone') : wps_ic_cache_integrations::purgeUrlHtml((string) $urlKey, '', ['context' => 'lcp-inline-criticalDone']);
                                    }
                                    if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('lcp-inline-landed', (string) $urlKey, '', ['via' => 'criticalDone', 'bytes' => strlen($wpc_lcp_inline_json)]); }
                                }
                            }
                        } elseif (!empty($wpc_cb_body['delay_inline']) || !empty($wpc_cb_body['lcp_inline'])) {
                            if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('callback-unsigned', (string) $urlKey, '', ['have_sig' => $callbackSig === '' ? 0 : 1]); }
                        }
                    }

                    // UUID
                    $uuidPart = substr($uuid, 0, 4);

                    // Mobile CSS
                    $mobileCriticalCSS = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/' . $uuid . '-mobile.css';

                    // Desktop CSS
                    $desktopCriticalCSS = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/' . $uuid . '-desktop.css';

                    if (!class_exists('wps_criticalCss')) {
                        include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
                    }

                    $criticalCSS = new wps_criticalCss();

                    // LCP-enabled domains) — read it from $_GET and pass it so saveCriticalCss stashes it for the
                    // render-side healer. This covers the PULL path (the callback fires here); the SMART/push

                    $wpc_cb_lcp_url = !empty($_GET['lcp_url']) ? sanitize_url(urldecode($_GET['lcp_url'])) : '';

                    $wpc_cb_delay_url = !empty($_GET['delay_url']) ? sanitize_url(urldecode($_GET['delay_url'])) : '';


                    $wpc_cb_used_css = !empty($_GET['used_css_url']) ? sanitize_url(urldecode($_GET['used_css_url'])) : '';
                    // .470: the service echoes on the LEGACY GET path too — same ambiguity lived here.
                    if (function_exists('wpc_used_css_echo_note')) {
                        wpc_used_css_echo_note('legacy-get', $_GET);
                    }
                    $wpc_cb_tpl_key  = !empty($_GET['tpl_key']) ? sanitize_text_field(urldecode($_GET['tpl_key'])) : '';


                    if ($wpc_cb_tpl_key !== '' && function_exists('wpc_used_css_store_sheets')) {


                        if (!empty($wpc_cb_body['used_css_sheets']) && is_array($wpc_cb_body['used_css_sheets'])) {
                            wpc_used_css_store_sheets($wpc_cb_tpl_key, $wpc_cb_body['used_css_sheets']);
                        }
                    }


                    try {

                        $wpc_callback_fonts = (!empty($wpc_cb_body['fonts']) && is_array($wpc_cb_body['fonts']))
                            ? $wpc_cb_body['fonts']
                            : ((!empty($_GET['fonts']) && is_array(json_decode(urldecode((string) $_GET['fonts']), true))) ? json_decode(urldecode((string) $_GET['fonts']), true) : []);
                        if (!empty($wpc_callback_fonts) && defined('WPS_IC_FONTS_DIR')
                            && apply_filters('wpc_fonts_artifact_consume', true)) {
                            if (!is_dir(WPS_IC_FONTS_DIR)) {
                                @wp_mkdir_p(WPS_IC_FONTS_DIR);
                            }
                            $wpc_f_n = 0;
                            $wpc_font_metrics = [];
                            foreach ($wpc_callback_fonts as $wpc_fe) {
                                if ($wpc_f_n >= 6 || !is_array($wpc_fe) || empty($wpc_fe['url'])) {
                                    continue;
                                }
                                $wpc_fu = (string) $wpc_fe['url'];
                                $wpc_fh = (string) parse_url($wpc_fu, PHP_URL_HOST);
                                $wpc_fb = basename((string) parse_url($wpc_fu, PHP_URL_PATH));
                                if (stripos($wpc_fh, 'critical-css-mc.b-cdn.net') === false
                                    || !preg_match('/^[A-Za-z0-9._-]+\.woff2$/', $wpc_fb)) {
                                    continue;
                                }
                                $wpc_dst = rtrim(WPS_IC_FONTS_DIR, '/') . '/' . $wpc_fb;
                                if (!file_exists($wpc_dst) || (int) @filesize($wpc_dst) !== (int) ($wpc_fe['bytes'] ?? -1)) {
                                    $wpc_fr = wp_remote_get($wpc_fu, ['timeout' => 8]);
                                    $wpc_fbody = (!is_wp_error($wpc_fr) && wp_remote_retrieve_response_code($wpc_fr) === 200)
                                        ? wp_remote_retrieve_body($wpc_fr) : '';
                                    if ($wpc_fbody !== '' && strlen($wpc_fbody) <= 65536 && strncmp($wpc_fbody, 'wOF2', 4) === 0) {
                                        $wpc_ftmp = $wpc_dst . '.tmp.' . getmypid();
                                        if (wpc_crit_meta_write($wpc_ftmp, $wpc_fbody) !== false) {
                                            @rename($wpc_ftmp, $wpc_dst);
                                            $wpc_f_n++;
                                        }
                                    }
                                } else {
                                    $wpc_f_n++;
                                }
                                if (!empty($wpc_fe['fallback']) && is_array($wpc_fe['fallback']) && !empty($wpc_fe['family'])) {
                                    $wpc_font_metrics[(string) $wpc_fe['family']] = $wpc_fe['fallback'];
                                }
                            }
                            if (!empty($wpc_font_metrics) && defined('WPS_IC_CRITICAL') && !empty($urlKey)) {
                                $wpc_md = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
                                if (!is_dir($wpc_md)) {
                                    @wp_mkdir_p($wpc_md);
                                }
                                wpc_crit_meta_write($wpc_md . 'font-metrics.json', wp_json_encode($wpc_font_metrics));
                            }
                            if ($wpc_f_n > 0 && function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('fonts-landed', $urlKey, '', ['n' => $wpc_f_n, 'metrics' => count($wpc_font_metrics)]);
                            }


                            if (apply_filters('wpc_atf_subset_inline', true) && defined('WPS_IC_CRITICAL') && !empty($urlKey)) {
                                $wpc_sub_css = '';
                                $wpc_sub_n   = 0;


                                // (legacy budget), 6 for v2 (one face per ATF-used weight by contract).
                                $wpc_all_v2  = true;
                                foreach ($wpc_callback_fonts as $wpc_sfe) {
                                    $wpc_e_v2 = is_array($wpc_sfe) && (int) ($wpc_sfe['subset_v'] ?? 1) >= 2;
                                    $wpc_subset_cap = $wpc_e_v2 ? 6 : 2;
                                    if ($wpc_sub_n >= $wpc_subset_cap || !is_array($wpc_sfe) || empty($wpc_sfe['url']) || empty($wpc_sfe['family'])) {
                                        continue;
                                    }
                                    if ((int) ($wpc_sfe['bytes'] ?? 999999) > 12288) {
                                        continue;
                                    }
                                    $wpc_sfb = basename((string) parse_url((string) $wpc_sfe['url'], PHP_URL_PATH));
                                    $wpc_sfp = rtrim(WPS_IC_FONTS_DIR, '/') . '/' . $wpc_sfb;
                                    if (!preg_match('/^[A-Za-z0-9._-]+\.woff2$/', $wpc_sfb) || !@is_readable($wpc_sfp)) {
                                        continue;
                                    }
                                    $wpc_sfw = @file_get_contents($wpc_sfp);
                                    if ($wpc_sfw === false || $wpc_sfw === '' || strncmp($wpc_sfw, 'wOF2', 4) !== 0) {
                                        continue;
                                    }
                                    $wpc_sfam = str_replace(["'", "\\", "\r", "\n", '<', '>'], '', (string) $wpc_sfe['family']);

                                    // (v2 + variable:true — a real clamped fvar axis is the truthful form);
                                    // everything else collapses to a single exact weight.
                                    $wpc_swt = trim(preg_replace('/[^0-9 ]/', '', (string) ($wpc_sfe['weight'] ?? '400')));
                                    if (!($wpc_e_v2 && !empty($wpc_sfe['variable']) && preg_match('/^\d{2,4} \d{2,4}$/', $wpc_swt))) {
                                        $wpc_swt = strtok($wpc_swt, ' ');
                                    }
                                    $wpc_sst  = (strtolower((string) ($wpc_sfe['style'] ?? 'normal')) === 'italic') ? 'italic' : 'normal';
                                    $wpc_sur  = preg_replace('/[^0-9A-Fa-fUu+,\- ]/', '', (string) ($wpc_sfe['unicode_range'] ?? ''));
                                    // remote_range (service v3.98.0) = the COMPLEMENT of the subset's glyphs,
                                    // applied verbatim to the kept original face so the browser only fetches it
                                    // when a glyph outside the subset actually paints. Never derived here — a
                                    // hand-computed complement is the one way to open a gap and render tofu.
                                    $wpc_srr  = preg_replace('/[^0-9A-Fa-fUu+,\- ]/', '', (string) ($wpc_sfe['remote_range'] ?? ''));
                                    if ($wpc_sfam === '' || $wpc_swt === '') {
                                        continue;
                                    }
                                    if (!isset($wpc_rr_map)) { $wpc_rr_map = []; }
                                    // DIAG (.431): record what actually ARRIVED per entry. The map went
                                    // stale while fonts.json provably carried remote_range, so the open
                                    // question is whether the field survives the callback projection.
                                    if (!isset($wpc_rr_diag)) { $wpc_rr_diag = []; }
                                    $wpc_rr_diag[] = strtolower($wpc_sfam) . '|' . $wpc_swt . '|' . $wpc_sst
                                        . ' keys=' . implode(',', array_keys($wpc_sfe))
                                        . ' ur=' . ($wpc_sur !== '' ? $wpc_sur : '(none)')
                                        . ' rr=' . ($wpc_srr !== '' ? $wpc_srr : '(NONE)');
                                    if ($wpc_srr !== '' && $wpc_sur !== '') {
                                        $wpc_rr_map[strtolower($wpc_sfam) . '|' . $wpc_swt . '|' . $wpc_sst] = $wpc_srr;
                                    }


                                    if (!$wpc_e_v2) { $wpc_all_v2 = false; }
                                    $wpc_sub_css .= "@font-face{font-family:'" . $wpc_sfam . "';font-weight:" . $wpc_swt
                                        . ';font-style:' . $wpc_sst . ';src:url(data:font/woff2;base64,' . base64_encode($wpc_sfw)
                                        . ") format('woff2');" . ($wpc_sur !== '' ? 'unicode-range:' . $wpc_sur . ';' : '')
                                        . 'font-display:block}';
                                    $wpc_sub_n++;
                                }
                                $wpc_sub_path = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/font-subsets.css';
                                if ($wpc_sub_css !== '' && $wpc_all_v2 && $wpc_sub_n > 0) {
                                    $wpc_sub_css = '/*wpc-subsets-v2*/' . $wpc_sub_css;
                                }
                                if ($wpc_sub_css !== '') {
                                    if (!is_dir(dirname($wpc_sub_path))) { @wp_mkdir_p(dirname($wpc_sub_path)); }
                                    wpc_crit_meta_write($wpc_sub_path, $wpc_sub_css);
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('font-subset-built', $urlKey, '', ['n' => $wpc_sub_n, 'bytes' => strlen($wpc_sub_css)]);
                                    }
                                } elseif (@is_readable($wpc_sub_path)) {
                                    @unlink($wpc_sub_path);
                                }
                                // family|weight|style => remote_range, for the @font-face rewriter. Font-scoped
                                // (not page-scoped) so one small non-autoloaded option serves every render, and
                                // only written alongside a real subset — no subset, no gating, original untouched.
                                if (!empty($wpc_rr_diag)) {
                                    update_option('wpc_fonts_consume_diag', ['t' => time(), 'src' => 'core-callback', 'rows' => array_slice($wpc_rr_diag, 0, 8)], false);
                                }
                                if (!empty($wpc_rr_map) && $wpc_sub_css !== '') {
                                    if (get_option('wpc_font_remote_ranges') !== $wpc_rr_map) {
                                        update_option('wpc_font_remote_ranges', $wpc_rr_map, false);
                                        if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('font-remote-ranges', (string) $urlKey, '', ['n' => count($wpc_rr_map), 'src' => 'core']); }
                                    }
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                    $wpc_cb_ready = !empty($_GET['ready']) ? sanitize_text_field(urldecode($_GET['ready'])) : '';
                    if ($wpc_cb_ready !== '' && function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('land-bundle-' . $wpc_cb_ready, $urlKey, (string) $pageUrl, []);
                    }


                    if ($wpc_cb_ready !== '' && function_exists('set_transient')) {
                        set_transient('wpc_land_ready_' . md5((string) $urlKey), $wpc_cb_ready, 600);
                    }
                    $jobStatus[] = $callbackLandResult = $criticalCSS->saveCriticalCss($urlKey, ['url' => ['desktop' => $desktopCriticalCSS, 'mobile' => $mobileCriticalCSS], 'lcp_url' => $wpc_cb_lcp_url, 'lcp_src' => 'callback', 'epoch' => (isset($_GET['epoch']) && is_numeric($_GET['epoch'])) ? (int) $_GET['epoch'] : null, 'delay_url' => $wpc_cb_delay_url, 'used_css_url' => $wpc_cb_used_css, 'tpl_key' => $wpc_cb_tpl_key, 'ready' => $wpc_cb_ready], 'meta', $pageUrl);

                    // Check if LCP Exists
                    $mobileLCP = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/lcp-' . $uuid . '-mobile';
                    $desktopLCP = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/lcp-' . $uuid . '-desktop';

                    $jobStatus[] = $criticalCSS->saveLCP($urlKey, ['url' => ['desktop' => $desktopLCP, 'mobile' => $mobileLCP]]);

                    // §2 (v7.10.679) — consume the wire.json manifest AFTER the crit + LCP saves above,
                    // never before. The manifest fetch is secondary capture; it must not add latency to,
                    // or risk timing out (cold callback + PHP max_execution_time), the crit save that is
                    // this callback's whole purpose. Own try, guarded call, rev 0 / empty url = no-op.
                    // wire_rev/wire_sig are new on the callback in service v3.176.0 (body, GET fallback).
                    try {
                        $wpc_wire_url = !empty($wpc_cb_body['wire_url']) ? sanitize_url((string) $wpc_cb_body['wire_url'])
                            : (!empty($_GET['wire_url']) ? sanitize_url(urldecode((string) $_GET['wire_url'])) : '');
                        $wpc_wire_rev = isset($wpc_cb_body['wire_rev']) ? (int) $wpc_cb_body['wire_rev']
                            : (isset($_GET['wire_rev']) ? (int) $_GET['wire_rev'] : 0);
                        $wpc_wire_sig = !empty($wpc_cb_body['wire_sig']) ? (string) $wpc_cb_body['wire_sig']
                            : (!empty($_GET['wire_sig']) ? sanitize_text_field(urldecode((string) $_GET['wire_sig'])) : '');
                        if (($wpc_wire_url !== '' || $wpc_wire_rev > 0) && function_exists('wpc_consume_wire_artifact')) {
                            wpc_consume_wire_artifact($urlKey, $wpc_wire_url, $wpc_wire_rev, $wpc_wire_sig);
                        }
                    } catch (\Throwable $e) {
                    }

                    if (apply_filters('wpc_lcp_async', true) && defined('WPS_IC_CRITICAL')
                        && !@is_readable(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/lcp.json')
                        && @is_readable(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/lcp_url.txt')
                        && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                        && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, 1])) {
                        if (function_exists('wpc_pl_sched')) {
                            wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, 1]);
                        } else {
                            wp_schedule_single_event(time() + 45, 'wpc_lcp_repull', [$urlKey, 1]);
                        }
                    }

                    // v7.22.55 — CRIT LANDING CONTRACT. (a) remember what was announced so the next
                    // page view can re-poll if it never landed; (b) a reannounce purges NOW — the
                    // coalescer defers a second purge inside 240s to cron, and the verifier looks 60s
                    // later (stale_final = the bytes are here, the visitor's cache is not);
                    // (c) ack {stored, purged, cache} so the service can tell "not purged" from "not stored".
                    $wpc_land_ack = ['stored' => false, 'purged' => false, 'cache' => ''];
                    try {
                        $wpc_announced_uuid = preg_replace('/[^A-Za-z0-9-]/', '', (string) $uuid);
                        $wpc_landed_uuid = defined('WPS_IC_CRITICAL')
                            ? preg_replace('/[^A-Za-z0-9-]/', '', (string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/land_uuid.txt')) : '';
                        // Only an announce the land on disk has not overtaken is remembered for the
                        // re-poll: an older one, re-polled, replaced the newer generation.
                        if ($wpc_announced_uuid !== '' && function_exists('set_transient')
                            && !(function_exists('wpc_gen_dispatched_before') && wpc_gen_dispatched_before((string) $urlKey, $wpc_announced_uuid, $wpc_landed_uuid))) {
                            set_transient('wpc_land_announced55_' . md5((string) $urlKey), $wpc_announced_uuid, 900);
                        }
                        $wpc_land_ack['stored'] = ($wpc_announced_uuid !== '' && $wpc_landed_uuid === $wpc_announced_uuid);
                        // The ack purges what the land purged: a delivery that changed no served
                        // bytes (the same generation again, an older one refused) leaves the copies,
                        // which on a test site were purged by every re-delivery of the crit on disk.
                        // A reannounce always purges: the service saw the page serve other bytes.
                        $callbackLandLeftServedAlone = isset($callbackLandResult) && is_array($callbackLandResult)
                            && isset($callbackLandResult['purged']) && !$callbackLandResult['purged'];
                        if ($callbackLandLeftServedAlone && $wpc_cb_ready !== 'reannounce') {
                            $wpc_land_ack['cache'] = 'unchanged';
                        } elseif (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                            $wpc_purge_layers = wps_ic_cache_integrations::purgeUrlHtml($urlKey, (string) $pageUrl, ['context' => $wpc_cb_ready === 'reannounce' ? 'crit-reannounce55' : 'crit-ack55', 'warm' => true, 'force' => true]);
                            $wpc_purged_layers = [];
                            foreach ((array) $wpc_purge_layers as $wpc_layer_name => $wpc_layer_state) {
                                if ($wpc_layer_state === true || $wpc_layer_state === 'rebuild' || $wpc_layer_state === 'queued') { $wpc_purged_layers[] = (string) $wpc_layer_name; }
                            }
                            $wpc_land_ack['purged'] = in_array('local', $wpc_purged_layers, true);
                            $wpc_land_ack['cache']  = implode(',', $wpc_purged_layers);
                        }
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('land-ack55', (string) $urlKey, (string) $pageUrl, $wpc_land_ack + ['ready' => (string) $wpc_cb_ready]);
                        }
                    } catch (\Throwable $e) {
                    }
                    wp_send_json_success($wpc_land_ack + ['jobs' => $jobStatus]);
                }

                wp_send_json_error('uuid-apikey-failure');
            }

            wp_send_json_error('failed');
        }
    }

    public function fetchPageSpeed()
    {
        if (!empty($_GET['pagespeedDone'])) {

            $jobStatus = [];
            $uuid = sanitize_text_field($_GET['uuid']);
            $apikey = sanitize_text_field($_GET['apikey']);

            if (!empty($uuid) && !empty($apikey)) {

                $this->debugPageSpeed('PageSpeed Started');

                $options = get_option(WPS_IC_OPTIONS);
                $dbApiKey = $options['api_key'];

                if ($dbApiKey == $apikey) {

                    // Detach: the service needs the 200, not our grind — artifacts save in
                    // the background; the storage-pointer watcher is the real receipt
                    @ignore_user_abort(true);
                    if (function_exists('set_time_limit')) { @set_time_limit(180); }
                    if (!headers_sent()) { http_response_code(200); }
                    if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) { wpc_finish_request(); }

                    if (!empty($_GET['debug'])) {
                        ini_set('display_errors', 1);
                        error_reporting(E_ALL);
                    }

                    if (!class_exists('wps_ic_url_key')) {
                        include_once WPS_IC_DIR . 'traits/url_key.php';
                    }

                    $urlKey = new wps_ic_url_key();
                    $pageUrl = sanitize_url(urldecode($_GET['pageUrl']));
                    $urlKey = $urlKey->setup($pageUrl);

                    // UUID
                    $uuidPart = substr($uuid, 0, 4);

                    // Mobile CSS
                    $mobileCriticalCSS = 'https://critical-css.b-cdn.net/' . $uuidPart . '/' . $uuid . '-mobile.css';

                    // Desktop CSS
                    $desktopCriticalCSS = 'https://critical-css.b-cdn.net/' . $uuidPart . '/' . $uuid . '-desktop.css';

                    if (!class_exists('wps_criticalCss')) {
                        include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
                    }

                    $criticalCSS = new wps_criticalCss();

                    $jobStatus[] = $criticalCSS->saveBenchmark($urlKey, $uuid);

                    $this->debugPageSpeed('Pagespeed Done with uuid ' . $uuid . '!');
                    wp_send_json_success($jobStatus);
                }

                $this->debugPageSpeed('Apikey not matching!');
                wp_send_json_error('uuid-apikey-failure');
            }

            wp_send_json_error('failed');
        }
    }

    public function debugPageSpeed($message)
    {
        if (get_option('wps_ps_debug') == 'true') {
            $log_file = WPS_IC_LOG . 'pagespeed-log-' . date('d-m-Y') . '.txt';
            $time = current_time('mysql');

            if (!touch($log_file)) {
                error_log("Failed to create log file: $log_file");
            }

            $log = file_get_contents($log_file);
            $log .= '[' . $time . '] - ' . $message . "\r\n";
            wpc_fs_put($log_file, $log);
        }
    }


    /**
     * Various checks if the plugin should not be running
     * @return bool
     */
    public static function dontRunif()
    {

        if (self::hiddenAdminArea()) {
            return true;
        }

        if (get_option('pause_wpcompress_plugin')) {
            return true;
        }

        if (self::isPageBuilder()) {
            return true;
        }

        if (self::isPageBuilderFE()) {
            return true;
        }

        // Fix for Feedzy RSS Feed
        if (!empty($_POST['action']) && ($_POST['action'] == 'feedzy' || $_POST['action'] == 'action' || $_POST['action'] == 'elementor')) {
            return true;
        }

        if (!empty($_GET['wps_ic_action'])) {
            return true;
        }

        if (strpos($_SERVER['REQUEST_URI'], 'xmlrpc') !== false || strpos($_SERVER['REQUEST_URI'], 'wp-json') !== false) {
            return true;
        }

        if (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") {
            return true;
        }

        if (!empty($_GET['tatsu']) || !empty($_GET['tatsu-header']) || !empty($_GET['tatsu-footer'])) {
            return true;
        }

        if ((!empty($_GET['page']) && sanitize_text_field($_GET['page']) == 'livecomposer_editor')) {
            return true;
        }

        if (!empty($_GET['PageSpeed'])) {
            return true;
        }

        if (!empty($_GET['pagelayer-live'])) {
            return true;
        }

        //GiveWP routes
        if (isset($_GET['givewp-route'])) {
            return true;
        }

        return false;
    }

    public static function hiddenAdminArea()
    {

        // AIOS
        if (class_exists('AIO_WP_Security')) {
            // Hide Login Exists
            $configs = get_option('aio_wp_security_configs');
            if (!empty($configs['aiowps_login_page_slug'])) {
                if (strpos($_SERVER['REQUEST_URI'], $configs['aiowps_login_page_slug']) !== false) {
                    return true;
                }
            }
        }

        // WPS Hide Login
        if (class_exists('WPS\WPS_Hide_Login\Plugin')) {
            // Hide Login Exists
            $loginPage = get_option('whl_page');
            if (!empty($loginPage)) {
                if (strpos($_SERVER['REQUEST_URI'], '/' . $loginPage) !== false) {
                    return true;
                }
            }
        }

        // Hide My WP - Ghost
        if (class_exists('HMWP_Classes_ObjController')) {
            $option = get_option('hmwp_options');

            if (!empty($option)) {
                $option = json_decode($option, true);
                $loginPage = $option['hmwp_login_url'];
                if (!empty($loginPage)) {
                    if (strpos($_SERVER['REQUEST_URI'], $loginPage) !== false) {
                        return true;
                    }
                }
            }
        }

    }


    /**
     * FrontEnd Editors Detection for various page builders
     * @return bool
     */
    public static function isPageBuilder()
    {
        $page_builders = ['run_compress',
                'run_restore',
                'bwc',
                'elementor-preview',
                'fl_builder',
                'et_fb',
                'preview', //WP Preview
                'builder',
                'brizy',
                'fb-edit',
                'bricks',
                'ct_template',
                'ct_builder',
                'cs-render',
                'tatsu',
                'trp-edit-translation',
                'brizy-edit-iframe',
                'ct_builder',
                'livecomposer_editor',
                'tatsu',
                'tatsu-header',
                'tatsu-footer',
                'tve',
                'is-editor-iframe',
                'pagelayer-live'];

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return true;
        }

        if (!empty($_POST['_cs_nonce'])) {
            return false;
        }

        if (!empty($_GET['page']) && sanitize_text_field($_GET['page']) == 'bwc') {
            return false;
        }

        if ((!empty($_GET['action']) && $_GET['action'] == 'in-front-editor')) {

            return true;
        }

        if ((!empty($_GET['action']) && sanitize_text_field($_GET['action']) == 'edit#op-builder') || !empty($_GET['op3editor'])) {

            return true;
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], 'wp-json') || strpos($_SERVER['REQUEST_URI'], 'rest_route')) {
                return false;
            }
        }

        if (!empty($page_builders)) {
            foreach ($page_builders as $page_builder) {
                if (isset($_GET[$page_builder])) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * FrontEnd Editors Detection for various page builders
     * @return bool
     */
    public static function isPageBuilderFE()
    {
        if (class_exists('BT_BB_Root')) {
            if (is_user_logged_in() && !is_admin()) {
                return true;
            }
        }

        return false;
    }


    public function fillMissingSettings($settings)
    {
        if (!class_exists('wps_ic_options')) {
            require_once 'classes/options.class.php';
        }

        $foundMissing = false;
        $options = new wps_ic_options();
        $defaultSettings = $options->getDefault();

        $resetAll = false;
        if (empty($settings) || count($settings) <= 3) {
            $resetAll = !empty($settings);
            $settings = [];
        }
        $filledKeys = [];

        foreach ($defaultSettings as $option_key => $option_value) {
            if (is_array($option_value)) {
                foreach ($option_value as $option_value_k => $option_value_v) {
                    if (!isset($settings[$option_key][$option_value_k])) {
                        if (!isset($settings[$option_key])) {
                            $settings[$option_key] = [];
                        }
                        $settings[$option_key][$option_value_k] = $option_value_v;
                        $foundMissing = true;
                        $filledKeys[] = $option_key . '.' . $option_value_k;
                    }
                }
            } else {
                if (!isset($settings[$option_key])) {
                    $settings[$option_key] = $option_value;
                    $foundMissing = true;
                    $filledKeys[] = (string) $option_key;
                }
            }
        }

        if ($foundMissing) {
            update_option(WPS_IC_SETTINGS, $settings);
            // Rule: every setting a release adds has a value. With no per-release migration this
            // request writes the defaults (the whole row when it held three keys or fewer); the
            // write happens once, so each one is logged.
            if (function_exists('wpc_belt_receipt')) {
                wpc_belt_receipt('settings-filled', ['n' => count($filledKeys), 'reset' => $resetAll ? 1 : 0,
                    'keys' => substr(implode(',', $filledKeys), 0, 240)], false, '');
            }
        }

        return $settings;
    }


    public function inAdmin()
    {
        add_action('current_screen', function () {
            if ( wp_doing_ajax() || ( defined('WP_CLI') && WP_CLI ) ) {
                return;
            }
            self::check_account_status();
        });

        if (!empty($_GET['resetHistory'])) {
            delete_option(WPS_IC_LITE_GPS_HISTORY);
        }

        if (!empty($_GET['testHistory'])) {
            $history = get_option(WPS_IC_LITE_GPS_HISTORY);
            var_dump($history);
        }

        $this->enqueues = new wps_ic_enqueues();
        $this->runInitialTest();

        // Force Disable Elementor Element Cache — the ONLY value Elementor honors as off is
        // the literal 'disable'. The old write stored false ('' in the DB): TTL silently reset
        // to default, the settings dropdown corrupted, and the write re-fired on EVERY admin
        // request, stomping any TTL the admin deliberately chose. Set 'disable' exactly once
        // (stamped); after that the admin's own choice always wins.
        $elementCache = get_option('elementor_element_cache_ttl');
        if ($elementCache !== false && !get_option('wpc_elementor_ec_set')) {
            if ((string) $elementCache !== 'disable') {
                update_option('elementor_element_cache_ttl', 'disable');
            }
            update_option('wpc_elementor_ec_set', 1, false);
        }


        if (current_user_can('manage_wpc_settings') && !empty($this::$options['api_key'])) {
            if (!class_exists('wps_ic_htaccess')) {
                include_once WPS_IC_DIR . 'classes/htaccess.class.php';
            }

            // Htaccess
            $htaccess = new wps_ic_htaccess();
            // Integrations
            if ($this->integrations) {
                $this->integrations->init();
            }
        }


        if (!empty($this::$options['api_key']) && empty($this::$zone_name) && get_option('wps_ic_allow_live') !== false
            && !(function_exists('wp_doing_ajax') && wp_doing_ajax())
            && (time() - (int) get_option('wpc_zone_backfill_at')) > HOUR_IN_SECONDS) {
            // Stamp BEFORE the call: concurrent admin screens must not stampede the API
            update_option('wpc_zone_backfill_at', time(), false);
            $url = 'https://apiv3.wpcompress.com/api/site/credits';
            $call = wp_remote_get($url, ['timeout' => 5, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $this::$options['api_key'], 'plugin-version' => self::$version]]);

            if (wp_remote_retrieve_response_code($call) == 200) {
                $body = wp_remote_retrieve_body($call);
                $body = json_decode($body, true);

                if (!empty($body['zone_name'])) {
                    self::$zone_name = $body['zone_name'];
                    update_option('ic_cdn_zone_name', $body['zone_name']);
                }
            }
        }

        // Run Multisite
        if (is_multisite()) {
            $this->mu = new wps_ic_mu();
        }

        // Setup Plugin Settings if Empty
        if (!$this::$settings) {
            $options = new wps_ic_options();
            $options->set_recommended_options();
        }

        // Fix to enabled preload-scripts on all sites!
        $settings = get_option(WPS_IC_SETTINGS);
        if (empty($this::$settings['preload-scripts'])) {
            $settings['preload-scripts'] = '1';
            update_option(WPS_IC_SETTINGS, $settings);
        }

        // Is cache enabled?
        if (!empty(self::$settings['cache']['advanced']) && self::$settings['cache']['advanced'] == '1') {
            if (!class_exists('wps_ic_htaccess')) {
                include_once WPS_IC_DIR . 'classes/htaccess.class.php';
            }

            //Check if another plugin set it to false
            $htacces = new wps_ic_htaccess();

            if (!empty($options['cache']['compatibility']) && $options['cache']['compatibility'] == '1' && $htacces->isApache) {
                // Modify HTAccess
                #$htacces->checkHtaccess();
            } else {
                $htacces->removeHtaccessRules();
            }


            $htacces->syncWebpReplace(self::$settings);

            // Add WP_CACHE to wp-config.php
            $htacces->setWPCache(true);
            $htacces->setAdvancedCache();

            // Add mod_Deflate to Htaccess
            if ($htacces->isApache()) {
                $htacces->addGzip();
            }
        }


        // Deactivate Notification
        add_action('admin_footer', ['wps_ic', 'deactivate_script']);
        add_action('admin_footer', ['wps_ic', 'checkQuotaStatus']);
        add_action('wpc_quota_status_refresh', ['wps_ic', 'checkQuotaStatusRefresh']);

        $this->cache = new wps_ic_cache_integrations();
        $this->cacheLogic = new wps_ic_cache();
        $this->ajax = new wps_ic_ajax();
        $this->menu = new wps_ic_menu();

        if (!class_exists('wps_ic_log')) {
            include_once WPS_IC_DIR . 'classes/log.class.php';
        }

        if (class_exists('wps_ic_log')) {
            $this->log = new wps_ic_log();
        }

        $this->templates = new wps_ic_templates();
        $this->notices = new wps_ic_notices();

        // Elementor Purge Integration
        add_action('elementor/document/after_save', [$this->cacheLogic, 'purgeElementorCache'], 10, 2);

        // Select Modes
        $modes = new wps_ic_modes();
        add_action('admin_footer', [$modes, 'showPopup']);

        // Purge Hooks
        $this->cacheLogic->purgeHooks();

        add_filter('big_image_size_threshold', [$this, 'maxImageWidth'], 999, 1);

        // Connect to API Notice
        $this->notices->connect_api_notice();

        // Ajax
        if (empty(self::$settings['css']) && empty(self::$settings['js']) && empty(self::$settings['serve']['jpg']) && empty(self::$settings['serve']['png']) && empty(self::$settings['serve']['gif']) && empty(self::$settings['serve']['svg'])) {
            $this->localMode();
        } else {
            if (!empty(self::$api_key)) {
                $this->media_library = new wps_ic_media_library_live();
                $this->stats = new wps_ic_stats();
                $this->comms = new wps_ic_comms();
            }
        }

        if (!empty($_GET['reset_compress'])) {
            $this->reset_local_compress();
            die('Reset Done');
        }

        if (!empty($_GET['ic_stats'])) {
            $this->stats->fetch_live_stats();
            die();
        }

        $this::$settings = $this->fillMissingSettings($this::$settings);

        if (empty($this::$settings['live-cdn']) || $this::$settings['live-cdn'] == '0') {
            // Is it some remote call?
            if (!empty($_GET['apikey'])) {
                if (self::$api_key !== sanitize_text_field($_GET['apikey'])) {
                    die('Bad Call');
                }
            }

            if (is_admin()) {
                if (!empty($_GET['deauth'])) {
                    $this->ajax->wps_ic_deauthorize_api();
                    wp_safe_redirect(wpc_settings_page_url());
                    die();
                }
            }
        }
    }

    public function runInitialTest()
    {

        if (!empty($_GET['forceInitial'])) {
            // Set flag to run the test
            set_transient('wpc_run_initial_test', 'true', 5 * 60);
        }

        if (!empty($_GET['resetTest'])) {
            delete_transient('wpc_initial_test');
        }

        // Flag should we force run test?
        $initial = get_transient('wpc_run_initial_test');

        // Flag if the test is running
        $initialTestRunning = get_transient('wpc_initial_test');

        // Get previous score (if any)
        $initialPageSpeedScore = get_option(WPS_IC_LITE_GPS);

        // Get Settings
        $options = get_option(WPS_IC_OPTIONS);

        // Don't run if api_key not existing!
        if (empty($options['api_key'])) {
            return false;
        }

        if ((!empty($initial) && $initial === 'true') || (empty($initialPageSpeedScore) && empty($initialTestRunning))) {

            $apikey = $options['api_key'];

            // Set the flag that test is ran
            set_transient('wpc_initial_test', 'true', 24 * 60 * 60);

            // Delete flag which forces the run of the test
            delete_transient('wpc_run_initial_test');

            // Save history of tests
            $history = get_option(WPS_IC_LITE_GPS_HISTORY);
            if (empty($history)) {
                $history = [];
            }
            $history[time()] = get_option(WPS_IC_LITE_GPS);
            update_option(WPS_IC_LITE_GPS_HISTORY, $history);

            // Remove Tests
            delete_option(WPS_IC_TESTS);
            delete_option(WPS_IC_LITE_GPS);
            delete_option(WPC_WARMUP_LOG_SETTING);
            delete_option('wpc_psi_insights');

            $requests = new wps_ic_requests();


            $psiUuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(8));
            set_transient('wpc_psi_uuid', $psiUuid, 30 * 60);

            // Test
            $args = ['url' => home_url(), 'version' => self::$version, 'plugin_version' => self::$version, 'uuid' => $psiUuid, 'hash' => $psiUuid, 'apikey' => $apikey];
            $args['features'] = self::getActiveFeatures();


            if (apply_filters('wpc_psi_clean_after', true)) {
                $args['clean_after'] = 1;
            }
            // Fire-and-forget dispatch; the plugin PULLS get-results/{uuid} (no push callback exists).
            $requests->POST(WPS_IC_PAGESPEED_API_URL_HOME, $args, ['timeout' => 2, 'blocking' => false, 'headers' => array('Content-Type' => 'application/json')]);
        }
    }

    public function localMode()
    {
        $this->queue = new wps_ic_queue();
        $this->compress = new wps_ic_compress();
        $this->controller = new wps_ic_controller();
        $this->remote_restore = new wps_ic_remote_restore();
        $this->comms = new wps_ic_comms();
        $this::$media_lib_ajax = $this->media_library = new wps_ic_media_library_live();
        $this->mu = new wps_ic_mu();
    }

    /**
     * Reset local image status
     */
    public function reset_local_compress()
    {
        $queue = $this->media_library->find_compressed_images();

        $compressed_images_queue = get_transient('wps_ic_restore_queue');

        if ($compressed_images_queue['queue']) {
            foreach ($compressed_images_queue['queue'] as $i => $image) {
                $attID = $image;
                delete_post_meta($attID, 'ic_status');
                delete_post_meta($attID, 'ic_stats');
                delete_post_meta($attID, 'ic_compressed_images');
            }
        }
    }

    /**
     * In Frontend Area
     */
    public function inFrontEnd()
    {
        add_action('wp', [$this, 'do_enqueues']);

        wps_local_compress::register_hooks();

        /**
         * Integrations
         */
        if ($this->integrations) {
            $this->integrations->apply_frontend_filters();
        }

        /**
         * Disable oEmbed if Enabled
         */
        if (!empty($this::$settings['disable-oembeds']) && $this::$settings['disable-oembeds'] == '1') {
            $oEmbed = new wps_ic_oEmbed();
            $oEmbed->run();
        }

        /**
         * Disable Dashicons if Enabled
         */
        if (!empty($this::$settings['disable-dashicons']) && $this::$settings['disable-dashicons'] == '1') {
            add_action('wp_enqueue_scripts', [$this, 'disableDashicons'], 999);
        }

        /**
         * Disable Gutenberg if Enabled
         */
        if (!empty($this::$settings['disable-gutenberg']) && $this::$settings['disable-gutenberg'] == '1') {
            add_action('wp_enqueue_scripts', [$this, 'disableGutenberg'], 1);
        }


        /**
         * Run API Critical CSS Generating
         * - Our API calls url with this GET parameter so that it runs critical generating
         */
        if (!empty($_GET['apiGenerateCritical'])) {
            $wpc_agc_opts = get_option(WPS_IC_OPTIONS);
            $wpc_agc_key = isset($_GET['apikey']) ? (string) $_GET['apikey'] : '';
            if (empty($wpc_agc_opts['api_key']) || $wpc_agc_key === '' || !hash_equals((string) $wpc_agc_opts['api_key'], $wpc_agc_key)
                || ((time() - (int) get_option('wpc_apigen_at')) < 60 && !update_option('wpc_apigen_at', time(), false))) {
                wp_send_json_error('unauthorized');
            }
            update_option('wpc_apigen_at', time(), false);
            // Service asked for a gen explicitly; it is apikey-gated and 60s-throttled above,
            // so a second debounce at the service can only return the stale artifact (v7.10.496).
            $GLOBALS['wpc_critical_generate_forced'] = 1;
            $criticalCSS = new wps_criticalCss();
            $criticalCSS->sendCriticalUrl('', 0);
            wp_send_json_success();
        }

        /**
         * Run Preloader API
         * - Our API calls url with this GET parameter so that it runs critical generating
         */
        if (!empty($_GET['apiPreload'])) {
            $wpc_apl_opts = get_option(WPS_IC_OPTIONS);
            $wpc_apl_key = isset($_GET['apikey']) ? (string) $_GET['apikey'] : '';
            if (empty($wpc_apl_opts['api_key']) || $wpc_apl_key === '' || !hash_equals((string) $wpc_apl_opts['api_key'], $wpc_apl_key)) {
                wp_send_json_error('unauthorized');
            }
            $criticalCSS = new wps_criticalCss();
            $criticalCSS->sendCriticalUrl('', 0);
            wp_send_json_success();
        }

        // v7.22.70 — the ajax class (443 KB) is NOT built on the front end: its constructor registers
        // nothing off admin, and every static lane autoloads it on first use. On a host whose opcache
        // is full it was recompiled on every anonymous render.

        /**
         * Run only if Current URL is not login or register
         * TODO: Maybe add some way to recognize custom login/register urls?
         */
        if (!in_array($_SERVER['PHP_SELF'], ['/wp-login.php', '/wp-register.php'])) {
            $this->menu = new wps_ic_menu();

            /**
             * Live CDN is Disabled
             */
            if (self::$settings['css'] == 0 && self::$settings['js'] == 0 && self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) {
                //Moved this to buffer_callback_v3 because here we dont have page ID yet
                $this->comms = new wps_ic_comms();
            } else {
                if (!empty(self::$api_key)) {
                    $this->comms = new wps_ic_comms();
                }
            }
        }
    }


    public function do_enqueues()
    {
        global $post;
        $wpc_excludes = get_option('wpc-excludes', []);
        if ($this->is_home_url()) {
            $page_excludes = isset($wpc_excludes['page_excludes']['home']) ? $wpc_excludes['page_excludes']['home'] : [];
        } else if (!empty(get_queried_object_id())) {
            $page_excludes = isset($wpc_excludes['page_excludes'][get_queried_object_id()]) ? $wpc_excludes['page_excludes'][get_queried_object_id()] : [];
        } elseif (!empty($post->ID)) {
            $page_excludes = isset($wpc_excludes['page_excludes'][$post->ID]) ? $wpc_excludes['page_excludes'][$post->ID] : [];
        } else {
            $page_excludes = [];
        }

        if (!empty($page_excludes)) {
            if (isset($page_excludes['cdn'])) {
                self::$settings['css'] = $page_excludes['cdn'];
                self::$settings['js'] = $page_excludes['cdn'];
                self::$settings['fonts'] = $page_excludes['cdn'];
                self::$settings['serve']['jpg'] = $page_excludes['cdn'];
                self::$settings['serve']['png'] = $page_excludes['cdn'];
                self::$settings['serve']['gif'] = $page_excludes['cdn'];
                self::$settings['serve']['svg'] = $page_excludes['cdn'];
            }


            // 'delay_js_v2' storage key.
            if (isset($page_excludes['delay_js'])) {
                self::$settings['delay-js-v2'] = $page_excludes['delay_js'];
            } elseif (isset($page_excludes['delay_js_v2'])) {
                self::$settings['delay-js-v2'] = $page_excludes['delay_js_v2'];
            }

            if (isset($page_excludes['adaptive'])) {
                self::$settings['generate_adaptive'] = $page_excludes['adaptive'];
            }
        }


        $this->enqueues = new wps_ic_enqueues();
    }

    public function is_home_url()
    {
        $home_url = rtrim(home_url(), '/');
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $current_url = rtrim($current_url, '/');

        return $home_url === $current_url;
    }

    /**
     * Remove Dashicons if the admin bar is not showing and user is not in customizer
     * @return void
     */
    public function disableDashicons()
    {
        if (!is_admin_bar_showing() && !is_customize_preview()) {
            if (!apply_filters('wpc_dequeue_graph_safe', true)) {
                wp_dequeue_style('dashicons');
                wp_deregister_style('dashicons');
                return;
            }
            wp_dequeue_style('dashicons');
            add_action('wp_footer', [$this, 'wpc_dequeue_dashicons'], 1);
        }
    }

    public function wpc_dequeue_dashicons()
    {
        wp_dequeue_style('dashicons');
    }

    /**
     * Remove Gutenberg CSS Block
     * @return void
     */
    public function disableGutenberg()
    {
        if (!apply_filters('wpc_dequeue_graph_safe', true)) {
            wp_deregister_style('wp-block-library');
            wp_dequeue_style('wp-block-library');
            wp_deregister_style('wp-block-library-theme');
            wp_dequeue_style('wp-block-library-theme');
            wp_deregister_style('global-styles');
            wp_dequeue_style('global-styles');
            remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
            remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
            return;
        }

        remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
        remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');

        add_action('wp_enqueue_scripts', [$this, 'wpc_dequeue_gutenberg_block_styles'], 999);
        add_action('wp_footer', [$this, 'wpc_dequeue_gutenberg_block_styles'], 1);
    }

    public function wpc_dequeue_gutenberg_block_styles()
    {
        wp_dequeue_style('wp-block-library');
        wp_dequeue_style('wp-block-library-theme');
        wp_dequeue_style('global-styles');
    }

    public function maxImageWidth()
    {
        if (empty(self::$settings['max-original-width'])) {
            return 2560;
        }

        return self::$settings['max-original-width'];
    }


    public function geoLocateAjax()
    {
        if (!is_multisite()) {
            $siteurl = site_url();
        } else {
            $siteurl = network_site_url();
        }

        $call = wp_remote_get('https://cdn.zapwp.net/?action=geo_locate&domain=' . urlencode($siteurl), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($call) == 200) {
            $body = wp_remote_retrieve_body($call);
            $body = json_decode($body);

            if ($body->success) {
                update_option('wps_ic_geo_locate_v2', $body->data);
            } else {
                update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
            }

            wp_send_json_success($body->data);
        } else {
            update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
        }

        return false;
    }


    /**
     * GeoLocation which is required for Local to work faster
     * @return void
     */
    public function geoLocate()
    {
        $call = wp_remote_get('https://cdn.zapwp.net/?action=geo_locate&domain=' . urlencode(site_url()), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($call) == 200) {
            $body = wp_remote_retrieve_body($call);
            $body = json_decode($body);

            if ($body->success) {
                update_option('wps_ic_geo_locate_v2', $body->data);
            } else {
                update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
            }
        } else {
            update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
        }
    }

}

include WPS_IC_DIR . 'traits/excludes.php';

// Guarded like every other helper in this file: a loader that already declared it must not make
// requiring this file a redeclare fatal. The test harness declares it, because the render path
// calls it (cdn-rewrite.php's memory shed) in suites that never load this file. On a live site
// nothing else declares it, so this is the declaration that runs.
if (!function_exists('wpc_convert_to_bytes')) {
function wpc_convert_to_bytes($value) {
    $value = trim($value);
    $last = strtolower($value[strlen($value) - 1]);
    $num = (int)$value;

    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }

    return $num;
}
}


function wps_ic_format_bytes($bytes, $force_unit = null, $format = null, $si = false)
{
    // Format string
    $format = ($format === null) ? '%01.2f %s' : (string)$format;

    // IEC prefixes (binary)
    if (!$si or strpos($force_unit, 'i') !== false) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $mod = 1000;
    } // SI prefixes (decimal)
    else {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $mod = 1000;
    }
    // Determine unit to use
    if (($power = array_search((string)$force_unit, $units)) === false) {
        $power = ($bytes > 0) ? floor(log($bytes, $mod)) : 0;
    }

    return sprintf($format, $bytes / pow($mod, $power), $units[$power]);
}


function wps_ic_size_format($bytes, $decimals)
{
    $quant = ['TB' => 1000 * 1000 * 1000 * 1000, 'GB' => 1000 * 1000 * 1000, 'MB' => 1000 * 1000, 'KB' => 1000, 'B' => 1,];

    if ($bytes == 0) {
        return '0 MB';
    }

    if ($bytes === 0) {
        return number_format_i18n(0, $decimals) . ' B';
    }

    foreach ($quant as $unit => $mag) {
        if ((float)$bytes >= $mag) {
            return number_format_i18n($bytes / $mag, $decimals) . ' ' . $unit;
        }
    }

    return false;
}


if (defined('WPC_IS_BG_SWAP') && WPC_IS_BG_SWAP) {
    return;
}

// THE LOADING BOUNDARY OF THIS FILE, the same one wp-compress.php carries. Everything below is
// include-time boot: the plugin instance, the CDN instance, every hook registration, the
// activation/uninstall hooks and the tail includes. A loader that wants only what this file
// DECLARES — the wps_ic class and the helpers around it, so the test harness can call
// wps_ic::fetchCritical() or wpc_apply_fresh_install_smart_delivery() directly — defines
// WPC_DECLARATIONS_ONLY and stops here. On a live site only the cron lane defines it, and only
// inside the bulk drain's cron event (wp-compress-cron.php), where this file is otherwise never
// loaded; every other request runs the whole file exactly as before.
// Rule: a function declared below this line stays a plain top-level declaration, because PHP
// binds those at compile time and they survive this return. Wrapping one in function_exists()
// hides it from a declarations-only load, which is wanted only for the two helpers the harness
// declares itself (wpc_convert_to_bytes, wpcGetHeader) and for nothing else.
if (defined('WPC_DECLARATIONS_ONLY') && WPC_DECLARATIONS_ONLY) {
    return;
}

// TODO: Maybe it's required on some themes?
// Backend
$wpsIc = new wps_ic();
add_action('init', [$wpsIc, 'init'], 100);

// Frontend do replace
if (!class_exists('wps_cdn_rewrite', false)) {
    $cdn_file = __DIR__ . '/addons/cdn/cdn-rewrite.php';
    if (is_readable($cdn_file)) {
        include_once $cdn_file;
    }
}

if (!$wpsIc->isAgencyPortal() && class_exists('wps_cdn_rewrite', false)) {
    $cdn = new wps_cdn_rewrite();
    $wps_ic_cdn_instance = $cdn;
} else {
    // Fail closed: prevent fatal if CDN module is unavailable or agency portal is active
    $wps_ic_cdn_instance = null;
}

// Check if plugin is connected with API
if (isset($cdn) && $cdn->isActive()) {
    add_action('plugins_loaded', [$cdn, 'checkCache_plugins_loaded'], 1);
    add_action('init', [$cdn, 'checkCache'], 1);
    add_action('wp', [$cdn, 'buffer_callback_v3'], 1);

    $elementor = new wps_ic_elementor();
    add_action('template_redirect', [$elementor, 'intercept_css_404'], 1);
}

// Upgrader - After Install
// upgrader_post_install is a filter: every callback must hand the install result on. Both
// callbacks return nothing, so every later callback (other plugins' updaters) received null
// instead of the result (found 2026-09-28 while tracing the "could not be reactivated" message
// on acrystalglass.com, whose updater library acts on this filter's value). updateCSSHash()
// treats the non-numeric value it used to receive as 0,
// so calling it without an argument does the same work.
add_filter('upgrader_post_install', function ($response) {
    wps_ic_cache::updateCSSHash();
    return $response;
}, 1);
add_filter('upgrader_post_install', function ($response) use ($wpsIc) {
    $wpsIc->deleteTests();
    return $response;
}, 1);

// Upgrader - On Complete
add_action('upgrader_process_complete', ['wps_ic_cache', 'updateCSSHash'], 1);
add_action('upgrader_process_complete', ['wps_ic_cache', 'purgeCDNUpdate'], 1);
add_action('wpc_update_hash_retry922', ['wps_ic_cache', 'updateCSSHash'], 1);
add_action('wpc_update_hash_retry922', ['wps_ic_cache', 'purgeCDNUpdate'], 2);

// One-time CF bypass rule migration (async via WP Cron)
add_action('wpc_migrate_cf_bypass', function() {
    $cf = get_option(WPS_IC_CF);
    if (!empty($cf['token']) && !empty($cf['zone'])) {
        $cfsdk = new WPC_CloudflareAPI($cf['token']);
        $cfsdk->addCdnBypassRule($cf['zone']);
    }
});

// Activation of Plugin
add_action('activate_plugin', ['wps_ic_cache', 'updateCSSHash'], 1);
add_action('activate_plugin', [$wpsIc, 'deleteTests'], 1);
add_action('activated_plugin', ['wps_ic_cache', 'purgeCDNUpdate'], 1);

// Deactivation of Plugin
add_action('deactivate_plugin', [$wpsIc, 'deactivation'], 1, 1);

// On Plugins Loaded - Every build of WP-Admin


add_action('admin_init', [$wpsIc, 'checkPluginVersion'], 1);
add_action('plugins_loaded', 'wpcCheckCredits', PHP_INT_MAX);

// WP Core Hooks
register_activation_hook(WPC_CC_PLUGIN_FILE, [$wpsIc, 'activation']);
register_deactivation_hook(WPC_CC_PLUGIN_FILE, [$wpsIc, 'deactivation']);
register_uninstall_hook(WPC_CC_PLUGIN_FILE, 'wpcUninstall');

// Register API Hooks
add_action('rest_api_init', function () {
    // Rest API
    wps_local_compress::register_hooks();
    $local = new wps_local_compress();
    $local->registerEndpoints();
});

// Re-test loopback whenever plugin settings change
add_action('update_option_' . WPS_IC_SETTINGS, function () {
    delete_option('wpc_loopback_status');


    delete_transient('wpc_loopback_test_at');
});

// Test loopback once on admin load (non-blocking, cached after first run)
add_action('admin_init', function () {


    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    if (get_option('wpc_loopback_status', '') !== '') return;
    // Detached: the 3s blocking self-loopback must not ride the first admin render
    // after a settings save (its result is only consumed later via option)
    add_action('shutdown', function () {
        // Detach where possible; on mod_php shutdown runs post-output anyway, so the
        // 3s worst case delays only the connection close, never the visible render
        if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) { wpc_finish_request(); }
        if (function_exists('ignore_user_abort')) { ignore_user_abort(true); }
        $local = new wps_local_compress();
        $local->testLoopback();
    }, PHP_INT_MAX);
}, 99);


add_action('admin_init', function () {
    if (get_option('wpc_serve_keys_reconciled_v70121')) return;
    $s = get_option(WPS_IC_SETTINGS);
    if (is_array($s) && !empty($s['serve']) && is_array($s['serve'])) {
        $any = false;
        foreach (['jpg', 'png', 'gif', 'svg'] as $k) {
            if (!empty($s['serve'][$k]) && (string) $s['serve'][$k] === '1') { $any = true; break; }
        }
        $v = $any ? '1' : '0';
        if ((string) ($s['serve']['jpg'] ?? '') !== $v || (string) ($s['serve']['png'] ?? '') !== $v
            || (string) ($s['serve']['gif'] ?? '') !== $v || (string) ($s['serve']['svg'] ?? '') !== $v) {
            $s['serve']['jpg'] = $s['serve']['png'] = $s['serve']['gif'] = $s['serve']['svg'] = $v;
            update_option(WPS_IC_SETTINGS, $s);
        }
    }
    update_option('wpc_serve_keys_reconciled_v70121', 1);
}, 98);


// Tiered cache is EARNED, never blanket-enabled: the CF selftest turns it on only after
// purge-eviction re-verifies with tiered active (unconditional enable = un-purgeable zones)


add_filter('wpc_src_hint_enabled', function ($on) {
    $s = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : null;
    if (is_array($s) && isset($s['emit-src-hints'])) {
        $v = (string) $s['emit-src-hints'];
        if ($v === '1') return true;
        if ($v === '0') return false;
    }
    return $on;
}, 20);


// ─── Backup cleanup: delete files older than 30 days ─────────────
// TODO: Enable when backup cleanup is a toggle in plugin settings


function wpc_do_cleanup_backups() {
    $backupDir = WP_CONTENT_DIR . '/wpc-backups/';
    if (!is_dir($backupDir)) return;

    $maxAge = 30 * DAY_IN_SECONDS;
    $now = time();
    $deleted = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($backupDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && ($now - $file->getMTime()) > $maxAge) {
            @unlink($file->getPathname());
            $deleted++;
        }
    }

    // Clean up empty directories
    $dirs = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($backupDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($dirs as $dir) {
        if ($dir->isDir()) {
            @rmdir($dir->getPathname()); // Only removes if empty
        }
    }

    if ($deleted > 0) {
        error_log('[WPC Cleanup] Deleted ' . $deleted . ' backup files older than 30 days');
    }
}


// Fired when someone clicks "Deactivate (keep data)"
add_action('admin_action_deactivate_and_disconnect', 'wpc_deactivate_delete_date');

add_action( 'init', 'wps_ic_load_textdomain' );

function wps_ic_load_textdomain() {
    load_plugin_textdomain(
            WPS_IC_TEXTDOMAIN,
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}

// Purge HTML cache when redirect plugins save rules (admin only)
add_action('update_option_wf301_redirect_rules', 'wpc_purge_redirect_cache', 10, 2);
add_action('update_option_301_redirects', 'wpc_purge_redirect_cache', 10, 2);
add_action('update_option_ts_301_redirection', 'wpc_purge_redirect_cache', 10, 2);
add_action('redirection_redirect_updated', 'wpc_purge_all_html_cache');
add_action('srm_redirect_saved', 'wpc_purge_all_html_cache');

function wpc_purge_redirect_cache($old_value, $new_value) {
    if (!is_array($new_value)) return;
    $url_key_class = new wps_ic_url_key();
    foreach ($new_value as $rule) {
        $source = isset($rule['url']) ? $rule['url'] : (isset($rule['request']) ? $rule['request'] : '');
        if (empty($source)) continue;
        $url_key = $url_key_class->setup(site_url($source));
        if (is_dir(WPS_IC_CACHE . $url_key)) {
            wps_ic_cache_integrations::purgeCacheFiles($url_key);
        }
    }
}

function wpc_purge_all_html_cache() {
    wps_ic_cache_integrations::purgeCacheFiles();
}

function wpcUninstall()
{
    try {
        $settings = get_option(WPS_IC_SETTINGS);
        $options = get_option(WPS_IC_OPTIONS);
        $connectivity = get_option('wpc-connectivity-status');
        $url = get_home_url();

        $data = ['settings' => $settings, 'options' => $options, 'connectivity' => $connectivity, 'url' => $url];

        $json_data = json_encode($data);

        $url = 'https://frankfurt.zapwp.net/uninstall/uninstall.php'; // Replace with your actual URL

        $args = ['body' => $json_data, 'timeout' => '5', 'redirection' => '5', 'httpversion' => '1.0', 'blocking' => true, 'headers' => ['Content-Type' => 'application/json',],];

        $response = wp_remote_post($url, $args);
    } catch (Exception $e) {
        error_log($e->getMessage());
    }
}

// Guarded for the same reason as wpc_convert_to_bytes above: the test harness declares this
// one too, so requiring this file must not be a redeclare fatal. Nothing on a live site
// declares it, so this is the declaration that runs there.
if (!function_exists('wpcGetHeader')) {
function wpcGetHeader($headerName)
{
    $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($headerName));
    return $_SERVER[$headerKey] ?? null;
}
}

function wpc_allow_flags_from_api($data)
{
    $allow_local = true;
    $allow_live = true;
    if (!is_object($data)) {
        return [$allow_local, $allow_live];
    }
    $acct = (isset($data->account) && is_object($data->account)) ? $data->account : $data;
    if (!empty($acct->suspended) && (int) $acct->suspended === 1) {
        $allow_local = false;
        $allow_live = false;
    }
    if (isset($data->cdn_enabled) && !$data->cdn_enabled) {
        $allow_live = false;
    }
    if (isset($data->local_enabled) && !$data->local_enabled) {
        $allow_local = false;
    }
    return [$allow_local, $allow_live];
}

function wpc_allow_flags_apply($allow_local, $allow_live, $source = '')
{
    $was_local = (bool) get_option('wps_ic_allow_local');
    $was_live = (bool) get_option('wps_ic_allow_live');
    $updated_local = ((bool) $allow_local !== $was_local) ? update_option('wps_ic_allow_local', (bool) $allow_local) : false;
    $updated_live = ((bool) $allow_live !== $was_live) ? update_option('wps_ic_allow_live', (bool) $allow_live) : false;
    if (function_exists('wpc_cache_first_log')) {
        wpc_cache_first_log('allow-flags', (string) $source, '', ['local' => (int) (bool) $allow_local, 'live' => (int) (bool) $allow_live, 'changed' => (int) ($updated_local || $updated_live)]);
    }
    return [(bool) $updated_local, (bool) $updated_live];
}

function wpc_apply_fresh_install_smart_delivery()
{
    if (!defined('WPS_IC_SETTINGS') || !apply_filters('wpc_fresh_install_smart_delivery', true)) {
        return false;
    }
    if (get_option('wpc_core_version') !== false || get_option('wpc_fresh_sd24', '') !== '') {
        return false;
    }
    $s = get_option(WPS_IC_SETTINGS, []);
    $s = is_array($s) ? $s : [];
    if (!empty($s['wpc_optimization_mode']) || (string) get_option('wpc_optimization_mode', '') !== '') {
        update_option('wpc_fresh_sd24', 'kept', false);
        return false;
    }
    $s['wpc_optimization_mode'] = 'lazy_cdn';
    update_option(WPS_IC_SETTINGS, $s);
    update_option('wpc_fresh_sd24', 'set', false);
    if (function_exists('wpc_cache_first_log')) {
        wpc_cache_first_log('fresh-install-smart-delivery', '', '', ['v' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '']);
    }
    return true;
}

function wpcCheckCredits()
{

    // Never on a visitor request: this is account housekeeping, admin lanes only
    if (!is_admin()) {
        return;
    }

    $transient_key = 'wps_ic_credits_check';
    if (get_transient($transient_key)) {
        return;
    }

    // Durable floor (survives object-cache flush) + stamp BEFORE the call so
    // concurrent requests at expiry can't stampede the API
    $lastCheckAt = (int) get_option('wpc_credits_check_at');
    $plugin_version = defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : '';
    if (time() - $lastCheckAt < 12 * HOUR_IN_SECONDS && (string) get_option('wpc_credits_check_v', '') === $plugin_version) {
        return;
    }
    update_option('wpc_credits_check_v', $plugin_version, false);

    $options = get_option(WPS_IC_OPTIONS);

    if (empty($options) || empty($options['api_key'])) {
        return;
    }

    update_option('wpc_credits_check_at', time(), false);

    $url = 'https://apiv3.wpcompress.com/api/site/credits';


    $call = wp_remote_get($url, ['timeout' => (int) apply_filters('wpc_credits_check_timeout', 2), 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $options['api_key'], 'plugin-version' => wps_ic::$version]]);

    $retry_at = time() - 12 * HOUR_IN_SECONDS + 15 * MINUTE_IN_SECONDS;
    if (is_wp_error($call)) {
        set_transient($transient_key, true, MINUTE_IN_SECONDS);
        update_option('wpc_credits_check_at', $retry_at, false);
        update_option('wpc_credits_check_err', ['t' => time(), 'err' => $call->get_error_message(), 'http' => 0], false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('credits-check-failed', '', '', ['err' => substr($call->get_error_message(), 0, 160), 'http' => 0]);
        }
        return;
    }

    $body = wp_remote_retrieve_body($call);
    $response_code = wp_remote_retrieve_response_code($call);

    if ($response_code !== 200) {
        set_transient($transient_key, true, MINUTE_IN_SECONDS);
        update_option('wpc_credits_check_at', $retry_at, false);
        update_option('wpc_credits_check_err', ['t' => time(), 'err' => '', 'http' => (int) $response_code], false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('credits-check-failed', '', '', ['err' => '', 'http' => (int) $response_code]);
        }
        return;
    }

    $data = json_decode($body);

    if (json_last_error() !== JSON_ERROR_NONE) {
        set_transient($transient_key, true, 15 * MINUTE_IN_SECONDS);
        update_option('wpc_credits_check_at', $retry_at, false);
        update_option('wpc_credits_check_err', ['t' => time(), 'err' => 'bad_json', 'http' => 200], false);
        return;
    }
    delete_option('wpc_credits_check_err');

    list($allow_local, $allow_live) = wpc_allow_flags_from_api($data);
    list($updated_local, $updated_live) = wpc_allow_flags_apply($allow_local, $allow_live, 'credits-check');

    // If Local or Live Capabilities Changed, Purge
    if ($updated_local || $updated_live) {
        if (class_exists('wps_ic_cache_integrations')) {
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll();
        }
    }

    set_transient($transient_key, true, 43200);
}


add_action('admin_init', function () {
    if (!apply_filters('wpc_autoload_debloat', true)) {
        return;
    }
    $ver = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '0';
    if (get_option('wpc_autoload_debloat_v') === $ver) {
        return;
    }
    global $wpdb;
    $names = ['wps_ic_purge_rules', 'wps_ic_parsed_images', 'wps_ic_excluded_list', 'wps-ic-background-compress-queue', 'wps_ic_mu_site_list'];
    $in = implode(',', array_map(function ($n) { return "'" . esc_sql($n) . "'"; }, $names));
    $wpdb->query("UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ($in) AND autoload IN ('yes','on','auto','auto-on','auto-yes')");
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete('alloptions', 'options');
    }
    update_option('wpc_autoload_debloat_v', $ver, false);
}, 1);
add_action('admin_init', function () {
    if (!apply_filters('wpc_autoload_seed69', true)) {
        return;
    }
    $ver = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '0';
    if (get_option('wpc_autoload_seed69_v') === $ver) {
        return;
    }
    global $wpdb;
    $names = ['wpc_core_version', 'wpc_loopback_status', 'wpc_apiv3_reconnect_done', 'wpc_ss_retry921', 'wpc_vl_seed267', 'wpc_autoload_debloat_v', 'wpc_credits_check_at', 'wpc_credits_checked_at', 'wpc_elementor_ec_set', 'wpc_zone_backfill_at', 'wpc_v2_postupdate_sync_ver', 'wpc_admin_drain_idle_at', 'wpc_ladder_gen_queue_has_items', 'wpc_fbstitch_v', 'wpc_ucss_resan394', 'wpc_fd_rebake_v', 'wpc_fd_auto_migr', 'wpc_artifact_refresh_v', 'wpc_used_css_flip644', 'wpc_font_metrics_present', 'wpc_auto_bootstrapped', 'wpc_lane_notice', 'wpc_lane_recover_notice', 'wpc_admin_tick69'];
    $in = implode(',', array_map(function ($n) { return "'" . esc_sql($n) . "'"; }, $names));
    $wpdb->query("UPDATE {$wpdb->options} SET autoload = 'yes' WHERE option_name IN ($in) AND autoload IN ('no','off','auto-off','auto-no')");
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete('alloptions', 'options');
    }
    update_option('wpc_autoload_seed69_v', $ver, true);
}, 2);

// Fired when someone clicks "Deactivate & delete data"
function wpc_deactivate_delete_date()
{
    $plugin = isset($_GET['plugin']) ? sanitize_text_field(wp_unslash($_GET['plugin'])) : '';
    $c = check_admin_referer('deactivate-plugin_' . $plugin);

    if ($plugin === 'wp-compress-image-optimizer/wp-compress.php') {
        wpc_delete_and_remove_data();
    }
}

function wpc_delete_and_remove_data()
{
    // Remove cron jobs
    $timestamp = wp_next_scheduled('runCronPreload');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'runCronPreload');
    }

    if (!class_exists('wps_ic_htaccess')) {
        include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }

    // Remove HtAccess Rules
    $htaccess = new wps_ic_htaccess();
    $htaccess->removeHtaccessRules();

    // Add WP_CACHE to wp-config.php
    $htaccess->setWPCache(false);
    $htaccess->removeAdvancedCache();

    // Purge Cached Files
    $cacheLogic = new wps_ic_cache();
    if (file_exists(WPS_IC_CACHE)) {
        $cacheLogic::deleteFolder(WPS_IC_CACHE);
    }

    if (file_exists(WPS_IC_CRITICAL)) {
        $cacheLogic::deleteFolder(WPS_IC_CRITICAL);
    }

    if (file_exists(WPS_IC_COMBINE)) {
        $cacheLogic::deleteFolder(WPS_IC_COMBINE);
    }
    try {
        if (method_exists('wps_ic_cache', 'purgeOtherCache')) {
            wps_ic_cache::purgeOtherCache(false);
        }
    } catch (\Throwable $e) {
    }

    // Remove Stats Transients
    delete_transient('wps_ic_live_stats');
    delete_transient('wps_ic_local_stats');

    // Remove generateCriticalCSS Options
    delete_option('wps_ic_gen_hp_url');
    delete_option(WPS_IC_GUI);
    delete_option('wps_log_critCombine');

    // Remove Tests
    delete_option(WPS_IC_TESTS);
    delete_transient('wpc_test_running');
    delete_transient('wpc_initial_test');
    delete_option(WPS_IC_LITE_GPS);
    delete_option(WPC_WARMUP_LOG_SETTING);
    delete_option('wpc_psi_insights');

    // Multisite Settings
    $settings = get_option(WPS_IC_MU_SETTINGS);
    $settings['hide_compress'] = 0;
    update_option(WPS_IC_MU_SETTINGS, $settings);

    // Remove from active on API
    $options = get_option(WPS_IC_OPTIONS);
    $site = site_url();
    $apikey = $options['api_key'];

    unset($options['api_key']);
    $newOptions = $options;
    $newOptions['regExUrl'] = '';
    $newOptions['regexpDirectories'] = '';
    update_option(WPS_IC_OPTIONS, $newOptions);

    $cfSettings = get_option(WPS_IC_CF);

    if (!empty($cfSettings)) {
        $zone = $cfSettings['zone'];
        $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
        $cfapi->removeCacheRules($zone);
    }

    // Setup URI
    $uri = WPS_IC_KEYSURL . '?action=disconnect&apikey=' . $apikey . '&site=' . urlencode($site);

    // Verify API Key is our database and user has is confirmed getresponse
    $get = wp_remote_get($uri, ['timeout' => 5, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

    deactivate_plugins('wp-compress-image-optimizer/wp-compress.php');

    if (get_option('pause_wpcompress_plugin_full_delete')){

        delete_option('pause_wpcompress_plugin_full_delete');
        delete_plugins(['wp-compress-image-optimizer/wp-compress.php']);

        $active_plugins = get_option('active_plugins');
        $plugin_slug = 'wp-compress-image-optimizer/wp-compress.php';
        $key = array_search($plugin_slug, $active_plugins);
        if ($key !== false) {
            unset($active_plugins[$key]);
            update_option('active_plugins', $active_plugins);
        }
    }
    wp_safe_redirect(admin_url('plugins.php?deactivate=true'));
}

add_action('do_faviconico', function () {
    if (!apply_filters('wpc_favicon_optimize', true) || headers_sent()) {
        return;
    }
    $icon = function_exists('get_site_icon_url') ? (string) get_site_icon_url(32) : '';
    if ($icon !== '') {
        header('Cache-Control: public, max-age=86400');
        wp_redirect($icon, 301);
        exit;
    }
    $f = ABSPATH . 'wp-includes/images/w-logo-blue-white-bg.png';
    if (@is_file($f)) {
        status_header(200);
        header('Content-Type: image/png');
        header('Content-Length: ' . (string) filesize($f));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($f);
        exit;
    }
    status_header(204);
    header('Cache-Control: public, max-age=86400');
    exit;
}, 1);


/**
 * Format/delivery settings changes purge the page cache. The format-fill scanner
 * runs in the output buffer, so toggling Generate WebP on a cached page did
 * nothing until an unrelated cache miss. Watches the format keys; purges once per
 * real change.
 */
add_action('update_option_' . WPS_IC_SETTINGS, function ($old, $new) {
    if (!is_array($old)) $old = [];
    if (!is_array($new)) $new = [];
    foreach (['generate_webp', 'picture_avif', 'wpc_nextgen'] as $k) {
        if ((string) ($old[$k] ?? '') !== (string) ($new[$k] ?? '')) {
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_sitechange_trailing')) {
                wp_schedule_single_event(time() + 8, 'wpc_sitechange_trailing');
            }
            do_action('breeze_clear_all_cache');
            if (function_exists('error_log')) {
                error_log('[WPC FormatToggle] ' . $k . ' changed — page cache purged (format-fill can scan fresh renders)');
            }
            break;
        }
    }
}, 10, 2);


$wpc_rebake_dropin_excludes = function () {
    $s = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
    if (empty($s['cache']['advanced']) || $s['cache']['advanced'] != '1') {
        return;
    }


    $wpc_dropin = ABSPATH . 'wp-content/advanced-cache.php';
    if (!file_exists($wpc_dropin)) {
        return;
    }
    $wpc_dropin_head = @file_get_contents($wpc_dropin, false, null, 0, 256);
    if ($wpc_dropin_head === false || strpos($wpc_dropin_head, 'WP_COMPRESS_ADVANCED_CACHE') === false) {
        return;
    }
    if (!class_exists('wps_ic_htaccess')) {
        @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }
    if (class_exists('wps_ic_htaccess')) {
        try {
            $htaccess = new wps_ic_htaccess();
            $htaccess->setAdvancedCache();
        } catch (\Throwable $e) {}
    }
};
add_action('update_option_wpc-excludes', $wpc_rebake_dropin_excludes);
add_action('add_option_wpc-excludes', $wpc_rebake_dropin_excludes);
add_action('update_option_wpc-url-excludes', $wpc_rebake_dropin_excludes);
add_action('add_option_wpc-url-excludes', $wpc_rebake_dropin_excludes);


add_filter('wpc_static_serve', function ($v) {
    if ($v) {
        return $v; // WPC_STATIC_SERVE constant / higher-priority filter wins
    }
    $s = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
    return is_array($s) && !empty($s['static-serve']) && $s['static-serve'] == '1';
});
add_action('update_option_' . WPS_IC_SETTINGS, function ($old, $new) {
    static $reentry = false;
    if ($reentry) {
        return;
    }
    $wasOn = is_array($old) && !empty($old['static-serve']) && $old['static-serve'] == '1';
    $isOn  = is_array($new) && !empty($new['static-serve']) && $new['static-serve'] == '1';
    if ($wasOn === $isOn) {
        return;
    }
    if (!class_exists('wps_ic_htaccess')) {
        @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }
    if (!class_exists('wps_ic_htaccess')) {
        return;
    }
    try {
        $h = new wps_ic_htaccess();
        if ($isOn) {
            $res = $h->applyStaticServe();
            if (empty($res['ok'])) {
                // Couldn't enable → record why + flip the toggle back off so it reflects reality.
                update_option('wpc_static_serve_failed', isset($res['reason']) ? $res['reason'] : 'failed', false);
                if (strpos((string) ($res['reason'] ?? ''), 'litespeed-family') !== false) {
                    update_option('wpc_ss_retry921', time(), false);
                }
                if (is_array($new)) {
                    $new['static-serve'] = '0';
                    $reentry = true;
                    update_option(WPS_IC_SETTINGS, $new);
                    $reentry = false;
                }
            }
        } else {
            // A user deliberately turning static-serve OFF ($wasOn, this else branch) opts
            // out of the TTFB auto-arm too — else the actuator would silently re-arm it ~an
            // hour later. (Keying off wpc_ttfb_ss_auto was wrong: a manually-enabled serve
            // never sets that flag, so the opt-out never recorded.) (v7.10.357)
            $staticServeWasLive = ($wasOn || get_option('wpc_ttfb_ss_auto') === '1' || get_option('wpc_static_serve_active') == 1);
            if ($wasOn) {
                update_option('wpc_ttfb_ss_optout', 1, false);
            }
            $h->removeStaticServe();
            // Tearing down a live zero-PHP static serve leaves untagged mirror HTML pinned
            // at the CF edge (no Cache-Tag header). Force the host-purge widening NOW —
            // after teardown cfUntaggedServesPossible() reads false, so a later tag-purge
            // would skip it. forceHosts=true bypasses that gate; no-op when CF isn't set.
            if ($staticServeWasLive && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                try { wps_ic_cache::cfPurgeAllHtml(false, true); } catch (\Throwable $e) {}
            }
        }
    } catch (\Throwable $e) {}
}, 10, 2);

// v7.10.921 — OLS SELF-HEAL. OpenLiteSpeed applies freshly-written rewrite rules only at
// server restart, so the manual Advanced-Cache toggle's static-serve selftest fails once
// and the fast path stayed off forever (the hourly TTFB auto-arm skips user-armed sites).
// When the failure was litespeed-family, re-run the selftest daily; the first run after an
// OLS restart passes, the fast path arms, and the user's original intent is restored.
// Respects the deliberate opt-out; kill filter wpc_ss_ols_retry.
add_action('admin_init', function () {
    if (!(int) get_option('wpc_ss_retry921')) {
        return;
    }
    if (get_option('wpc_ttfb_ss_optout') || get_option('wpc_static_serve_active') == 1) {
        delete_option('wpc_ss_retry921');
        return;
    }
    if (!apply_filters('wpc_ss_ols_retry', true) || get_transient('wpc_ss_retry_t921')) {
        return;
    }
    set_transient('wpc_ss_retry_t921', 1, DAY_IN_SECONDS);
    if (!class_exists('wps_ic_htaccess')) {
        @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }
    if (!class_exists('wps_ic_htaccess')) {
        return;
    }
    try {
        $htaccess = new wps_ic_htaccess();
        if (method_exists($htaccess, 'isApache')) {
            $htaccess->isApache();
        }
        $result = $htaccess->applyStaticServe();
        if (!empty($result['ok'])) {
            delete_option('wpc_ss_retry921');
            delete_option('wpc_static_serve_failed');
            $settings = get_option(WPS_IC_SETTINGS);
            if (is_array($settings)) {
                $settings['static-serve'] = '1';
                update_option(WPS_IC_SETTINGS, $settings);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('ss-ols-selfheal-armed', '', '', []);
            }
        }
    } catch (\Throwable $e) {}
}, 35);


add_action('update_option_' . WPS_IC_SETTINGS, function ($old, $new) {
    static $bcReentry = false;
    if ($bcReentry) {
        return;
    }
    $wasOn = is_array($old) && !empty($old['browser-cache-headers']) && $old['browser-cache-headers'] == '1';
    $isOn  = is_array($new) && !empty($new['browser-cache-headers']) && $new['browser-cache-headers'] == '1';
    if ($wasOn === $isOn) {
        return;
    }
    if (!class_exists('wps_ic_htaccess')) {
        @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }
    if (!class_exists('wps_ic_htaccess')) {
        return;
    }
    try {
        $h = new wps_ic_htaccess();
        if ($isOn) {
            $bcReentry = true;
            $h->wpcApplyBrowserCache();
            $bcReentry = false;
        } else {
            $h->wpcRemoveBrowserCache();
        }
    } catch (\Throwable $e) {
        $bcReentry = false;
    }
}, 10, 2);


/**
 * The Delay JS report beacon (admin-ajax `wpc_delay_v3_report`, open to visitors): what the delay
 * loader and the LCP tracer saw in a real browser. It tunes this site's delay lane (manifest off,
 * Delay JS excludes, the timer demote) and keeps the LCP and replay-duration telemetry.
 *
 * Every exit and its receipt:
 *   over 200 reports this hour             delay-report-refused {why: rate}         429
 *   Origin/Referer host is not the site    delay-report-refused {why: bad-origin}
 *   no payload, over 2048 bytes, not JSON  delay-report-refused {why: bad-payload}
 *   stamp missing or not the path's        delay-report-refused {why: bad-stamp}    403
 *   no report kind in the payload          delay-report-refused {why: bad-payload}
 *   accepted                               delay-report-rx {why: bootfail|bootretr|lcp|errors|stats},
 *                                          one in 20 (the first of every hour always)
 * An accepted report then answers from its branch (retracted, lcptrace, lcpok, lcpmx) or with
 * the plain success at the end.
 */
function wpc_delay_v3_report_handler()
{
    // Rule: every refusal is receipted where it is decided and is never sampled. A run of
    // refusals is the signal that reports are being forged, or that pages are served without
    // their stamp, and sampling would hide the first of either.
    $refuse = function ($why, $path = '', $status = null) {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('delay-report-refused', '', (string) $path, ['why' => $why]);
        }
        wp_send_json_error($why, $status);
    };

    $rate = (int) get_transient('wpc_delay_v3_report_rate');
    if ($rate > 200) {
        $refuse('rate', '', 429);
    }
    set_transient('wpc_delay_v3_report_rate', $rate + 1, HOUR_IN_SECONDS);

    // A cheap first filter, not the trust check: any client sets Origin and Referer at will.
    // The trust check is the stamp below.
    $wpc_src = !empty($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');
    $wpc_sh  = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
    $wpc_oh  = strtolower((string) parse_url($wpc_src, PHP_URL_HOST));
    $wpc_strip = function ($h) { return strpos($h, 'www.') === 0 ? substr($h, 4) : $h; };
    if ($wpc_oh === '' || $wpc_strip($wpc_oh) !== $wpc_strip($wpc_sh)) {
        $refuse('bad-origin');
    }
    // GET accepted as well as POST: sendBeacon's queued POST can be dropped when Lighthouse
    // tears the page down, so the tracer falls back to an image GET. Same checks, same rate
    // limit, same sanitising below — the method does not change the trust model.
    $raw = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload'])
         : (isset($_GET['payload']) ? (string) wp_unslash($_GET['payload']) : '');
    if ($raw === '' || strlen($raw) > 2048) {
        $refuse('bad-payload');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $refuse('bad-payload');
    }

    // Rule: a report is believed only when it carries the stamp the render minted for the path it
    // names (wpc_delay_report_stamp), checked before the payload is read for intent, and a refused
    // report records nothing: no strike, no error row, no stats. Observed failure: with the Origin
    // check as the only gate, a forged header plus the right JSON switched the delay manifest off
    // site-wide, appended scripts to the operator's Delay JS excludes, demoted the site to the
    // timer and emptied the whole page cache. A copy cached before the stamp existed carries none
    // and is refused until that page is rendered again (the plugin update purges every copy).
    // WPC_DELAY_REPORT_STAMP_OFF skips this check alone; the page-scoped purge and the receipts
    // stay.
    $wpc_report_path = isset($data['u']) && is_string($data['u']) ? $data['u'] : '';
    $wpc_report_path_logged = sanitize_text_field(substr($wpc_report_path, 0, 120));
    if (!(defined('WPC_DELAY_REPORT_STAMP_OFF') && WPC_DELAY_REPORT_STAMP_OFF)) {
        $wpc_stamp = isset($data['s']) && is_string($data['s']) ? $data['s'] : '';
        if ($wpc_report_path === '' || $wpc_stamp === '' || !function_exists('wpc_delay_report_stamp')
            || !hash_equals(wpc_delay_report_stamp($wpc_report_path), $wpc_stamp)) {
            $refuse('bad-stamp', $wpc_report_path_logged, 403);
        }
    }

    // Rule: a report purges the cached copy of the page it reported from and nothing else; a
    // setting it moved reaches the other pages as their copies are rendered again. Observed
    // failure: the manifest-off and excludes branches called removeHtmlCacheFiles('all'), so one
    // unauthenticated report emptied the whole page cache. The boot watchdog's purge of its
    // striking paths goes through the same helper.
    // The path is location.pathname, which already carries a subdirectory install's prefix, so the
    // URL is the home URL's scheme, host and port plus the path; home_url($path) purged
    // /blog/blog/page/ on a site at example.com/blog and missed the page.
    $wpc_purge_page = function ($path, $context) {
        $path = (string) $path;
        if ($path === '' || strpos($path, '/') !== 0) {
            return;
        }
        try {
            if (class_exists('wps_ic_url_key') && class_exists('wps_ic_cache_integrations')
                && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                $wpc_home = parse_url(home_url());
                if (empty($wpc_home['host'])) {
                    return;
                }
                $wpc_page_url = (isset($wpc_home['scheme']) ? $wpc_home['scheme'] : 'https') . '://' . $wpc_home['host']
                    . (isset($wpc_home['port']) ? ':' . (int) $wpc_home['port'] : '') . $path;
                $wpc_page_key = (new wps_ic_url_key())->setup($wpc_page_url);
                if ($wpc_page_key) {
                    wps_ic_cache_integrations::purgeUrlHtml($wpc_page_key, '', ['context' => $context]);
                }
            }
        } catch (\Throwable $e) {
        }
    };

    $boot_failed = isset($data['b']) && (int) $data['b'] === 0;
    $boot_retracted = isset($data['b']) && (int) $data['b'] === 1;
    $lcp_mismatch = !empty($data['lcpmx']);
    $lcp_confirmed = !empty($data['lcpok']);
    $lcp_trace = !empty($data['lcptrace']);
    // v7.22.38 — the error-free completion report ({u,e:[],d,n}: replay duration for the
    // wpc_delay_v3_stats consumer below) was rejected here as bad-payload since the .360
    // flag set went in: every clean pageview sent a beacon the server threw away and the
    // duration stats never accumulated. A positive duration is a valid report on its own.
    $has_duration = isset($data['d']) && (int) $data['d'] > 0 && (int) $data['d'] < 60000;
    $wpc_has_errors = !empty($data['e']) && is_array($data['e']);
    if (!$wpc_has_errors && !$boot_failed && !$boot_retracted && !$lcp_mismatch && !$lcp_confirmed && !$lcp_trace && !$has_duration) {
        $refuse('bad-payload', $wpc_report_path_logged);
    }
    // The accepted receipt names the branch the report takes. The handler admits up to 200
    // reports an hour, so it is sampled one in 20 on the hour's own counter (the first report of
    // every hour is always written); refusals above are never sampled.
    if ($rate % 20 === 0 && function_exists('wpc_cache_first_log')) {
        $wpc_branch = $boot_failed ? 'bootfail' : ($boot_retracted ? 'bootretr'
            : (($lcp_trace || $lcp_confirmed || $lcp_mismatch) ? 'lcp' : ($wpc_has_errors ? 'errors' : 'stats')));
        wpc_cache_first_log('delay-report-rx', '', $wpc_report_path_logged, ['why' => $wpc_branch]);
    }
    if (!isset($data['e']) || !is_array($data['e'])) {
        $data['e'] = [];
    }
    // Boot-watchdog demote: a gesture started the delayed boot and it never
    // completed on an AGGRESSIVE page (the watchdog arms only on cfg.aggr).
    // 3 distinct-path strikes inside 24h demote THIS site to the safe timer
    // (wpc_delay_aggr_off, read by the js_delay_v3 flip); the striking paths
    // get a TARGETED purge — never purge-all, an unauthenticated beacon must
    // not hold a site-wide purge lever. b:1 = late-boot RETRACTION (the boot
    // finished after the deadline: slow network, not broken — strike voided).
    // u[] capped at 10; a fresh-gen land re-arms (damped + capped).
    if ($boot_failed || $boot_retracted) {
        $boot_fails = get_option('wpc_delay_v3_bootfails', []);
        if (!is_array($boot_fails) || (isset($boot_fails['t']) && time() - (int) $boot_fails['t'] > DAY_IN_SECONDS)) {
            $boot_fails = [];
        }
        if (empty($boot_fails)) {
            $boot_fails = ['t' => time(), 'u' => [], 'p' => []];
        }
        if (!isset($boot_fails['p']) || !is_array($boot_fails['p'])) {
            $boot_fails['p'] = [];
        }
        $boot_path = isset($data['u']) ? sanitize_text_field(substr((string) $data['u'], 0, 120)) : '';
        $boot_path_hash = substr(md5($boot_path), 0, 8);
        if ($boot_retracted) {
            $strike_index = array_search($boot_path_hash, (array) $boot_fails['u'], true);
            if ($strike_index !== false) {
                array_splice($boot_fails['u'], (int) $strike_index, 1);
                unset($boot_fails['p'][$boot_path_hash]);
                update_option('wpc_delay_v3_bootfails', $boot_fails, false);
                // A late boot voids its strike (slow network, not a broken replay).
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('delay-bootfail-retracted', '', $wpc_report_path_logged, ['strikes' => count((array) $boot_fails['u'])]);
                }
            }
        } elseif (count((array) $boot_fails['u']) < 10 && !in_array($boot_path_hash, (array) $boot_fails['u'], true)) {
            $boot_fails['u'][] = $boot_path_hash;
            if (count($boot_fails['p']) < 3 && $boot_path !== '' && strpos($boot_path, '/') === 0) {
                $boot_fails['p'][$boot_path_hash] = $boot_path;
            }
            update_option('wpc_delay_v3_bootfails', $boot_fails, false);
            // A delayed boot that a gesture started never completed on an aggressive page; three
            // distinct paths in 24 h demote the site to the timer. One line per new path.
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('delay-bootfail-strike', '', $wpc_report_path_logged, ['strikes' => count((array) $boot_fails['u'])]);
            }
        }
        if ($boot_failed && count((array) $boot_fails['u']) >= 3 && !get_option('wpc_delay_aggr_off')) {
            $demotions = (int) get_option('wpc_delay_aggr_fails', 0);
            update_option('wpc_delay_aggr_off', time(), false);
            update_option('wpc_delay_aggr_fails', $demotions + 1, false);
            foreach ((array) $boot_fails['p'] as $strike_path) {
                $wpc_purge_page($strike_path, 'aggr-demote');
            }
            delete_option('wpc_delay_v3_bootfails');
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('delay-aggr-demoted', '', $wpc_report_path_logged, [
                    'strikes' => count((array) $boot_fails['u']),
                    'purged' => count((array) $boot_fails['p']),
                    'demotions' => $demotions + 1,
                ]);
            }
            if (function_exists('wpc_diagnostic_log')) {
                wpc_diagnostic_log('DELAY_AGGR_DEMOTED', 'boot watchdog: 3 distinct aggr paths failed to boot — demoted to timer (targeted purge)');
            }
        }
    }
    if ($boot_retracted && empty($data['e'])) {
        wp_send_json_success('retracted');
    }
    // LCP preload correctness. The browser reports which element it ACTUALLY chose as LCP;
    // when that is not what we preloaded, the preload spent the LCP's bandwidth at
    // fetchpriority="high" on the wrong resource — worse than no preload, and invisible in
    // aggregate without this signal. The beacon fires ONLY on mismatch and once per session,
    // so a correct fleet reports nothing and costs nothing.
    // Positive confirmation (1% sampled, once per browser): a real browser reported that
    // the element it chose as LCP WAS the one we preloaded. Without this, "no mismatch
    // reported" is indistinguishable from "no browser ever checked" — the blind spot the
    // beacon exists to close. One timestamp + counter, non-autoload.
    // FCP->LCP trace beaconed back from a Lighthouse/PSI run — console output is
    // unreachable there, and PSI is the ONLY environment where the gap exists at all (a real
    // browser reports gap=0). Keeps the last 3 reports in one non-autoload option.
    if ($lcp_trace) {
        $trace_reports = get_option('wpc_lcp_trace_reports', []);
        if (!is_array($trace_reports)) { $trace_reports = []; }
        $to_int = function ($v) { return is_numeric($v) ? (int) $v : 0; };
        $trace_reports[] = [
            't'   => time(),
            'u'   => sanitize_text_field(substr((string) ($data['u'] ?? ''), 0, 60)),
            'fcp' => $to_int($data['fcp'] ?? 0),
            'lcp' => $to_int($data['lcp'] ?? 0),
            'gap' => $to_int($data['gap'] ?? 0),
            'own' => $to_int($data['own'] ?? 0),
            'pct' => $to_int($data['pct'] ?? 0),
            'v'   => sanitize_text_field(substr((string) ($data['v'] ?? ''), 0, 20)),
            'ch'  => in_array(($data['ch'] ?? ''), ['b', 'i'], true) ? (string) $data['ch'] : '?',
            'hum' => isset($data['hum']) ? (int) $data['hum'] : -1,
            'ltn' => $to_int($data['ltn'] ?? 0),
            'ltms'=> $to_int($data['ltms'] ?? 0),
            'top' => isset($data['top']) ? (int) $data['top'] : -1,
            'vh'  => isset($data['vh'])  ? (int) $data['vh']  : -1,
            'inv' => isset($data['inv']) ? (int) $data['inv'] : -1,
            'el'  => sanitize_text_field(substr((string) ($data['el'] ?? ''), 0, 32)),
            'url' => sanitize_text_field(substr((string) ($data['url'] ?? ''), 0, 48)),
            'r'   => is_array($data['r'] ?? null) ? array_map($to_int, array_slice($data['r'], 0, 8)) : [],
            'net' => is_array($data['net'] ?? null) ? array_slice($data['net'], 0, 6) : [],
            'lt'  => is_array($data['lt'] ?? null) ? array_slice($data['lt'], 0, 5) : [],
        ];
        // 3 was too tight for the job: comparing environments needs a PSI capture and a
        // browser capture side by side, and each page load can send twice — so a browser run
        // could evict the PSI trace, which is the one that cannot be obtained any other way.
        // 8 rows is ~1.6KB in one non-autoload option.
        update_option('wpc_lcp_trace_reports',
            array_slice($trace_reports, -(int) apply_filters('wpc_lcp_trace_keep', 8)), false);
        if (empty($data['e'])) {
            wp_send_json_success('lcptrace');
        }
    }
    if ($lcp_confirmed) {
        $preload_ok = get_option('wpc_lcp_preload_ok', []);
        if (!is_array($preload_ok)) { $preload_ok = []; }
        $preload_ok = [
            't' => time(),
            'n' => isset($preload_ok['n']) ? min((int) $preload_ok['n'] + 1, 1000000) : 1,
        ];
        update_option('wpc_lcp_preload_ok', $preload_ok, false);
        if (empty($data['e'])) {
            wp_send_json_success('lcpok');
        }
    }
    if ($lcp_mismatch) {
        $mismatches = get_option('wpc_lcp_preload_mismatch', []);
        if (!is_array($mismatches)) { $mismatches = []; }
        $mismatch_url = isset($data['u'])    ? sanitize_text_field(substr((string) $data['u'], 0, 120)) : '';
        $mismatch_got = isset($data['got'])  ? sanitize_text_field(substr((string) $data['got'], 0, 80)) : '';
        $mismatch_want = isset($data['want']) ? sanitize_text_field(substr((string) $data['want'], 0, 80)) : '';
        if ($mismatch_want !== '') {
            $mismatch_key = substr(md5($mismatch_url . '|' . $mismatch_got . '|' . $mismatch_want), 0, 10);
            $mismatches[$mismatch_key] = [
                't'    => time(),
                'u'    => $mismatch_url,
                'got'  => $mismatch_got,
                'want' => $mismatch_want,
                'n'    => isset($mismatches[$mismatch_key]['n']) ? (int) $mismatches[$mismatch_key]['n'] + 1 : 1,
            ];
            update_option('wpc_lcp_preload_mismatch', array_slice($mismatches, -20, null, true), false);
        }
        if (empty($data['e'])) {
            wp_send_json_success('lcpmx');
        }
    }
    $log = get_option('wpc_delay_v3_errors', []);
    if (!is_array($log)) {
        $log = [];
    }
    $url = isset($data['u']) ? sanitize_text_field(substr((string) $data['u'], 0, 120)) : '';
    foreach (array_slice($data['e'], 0, 10) as $e) {
        if (!is_array($e)) {
            continue;
        }
        $msg  = isset($e['m']) ? sanitize_text_field(substr((string) $e['m'], 0, 180)) : '';
        $file = isset($e['f']) ? sanitize_text_field(substr((string) $e['f'], 0, 160)) : '';
        if ($msg === '') {
            continue;
        }
        $key = md5($msg . '|' . $file);
        $log[$key] = ['t' => time(), 'm' => $msg, 'f' => $file, 'u' => $url, 'n' => isset($log[$key]['n']) ? (int) $log[$key]['n'] + 1 : 1];
    }
    update_option('wpc_delay_v3_errors', array_slice($log, -30, null, true), false);


    $wpc_dur = isset($data['d']) ? (int) $data['d'] : 0;
    if ($wpc_dur > 0 && $wpc_dur < 60000) {
        $stats = get_option('wpc_delay_v3_stats', []);
        if (!is_array($stats)) {
            $stats = [];
        }
        $stats[] = $wpc_dur;
        update_option('wpc_delay_v3_stats', array_slice($stats, -50), false);
    }


    $wpc_promoted = get_option('wpc_delay_v3_promoted', []);
    if (!is_array($wpc_promoted)) {
        $wpc_promoted = [];
    }
    if (!empty($wpc_promoted) && !get_option('wpc_delay_v3_manifest_off')
        && apply_filters('wpc_delay_v3_autotune', true)) {
        foreach ($log as $entry) {
            if (empty($entry['f']) || (int) $entry['n'] < 2) {
                continue;
            }
            $wpc_m = (string) $entry['m'];
            if (stripos($wpc_m, 'is not defined') === false
                && stripos($wpc_m, "can't find variable") === false) {
                continue; // ReferenceError signatures only (Chrome + Safari phrasings)
            }
            $wpc_fh = strtolower((string) parse_url((string) $entry['f'], PHP_URL_HOST));
            if ($wpc_fh !== '' && $wpc_strip($wpc_fh) !== $wpc_strip($wpc_sh)
                && strpos($wpc_fh, 'zapwp') === false && strpos($wpc_fh, 'b-cdn') === false) {
                continue;
            }
            $wpc_pb = basename((string) parse_url((string) $entry['f'], PHP_URL_PATH));
            if ($wpc_pb === '' || !in_array($wpc_pb, $wpc_promoted, true)) {
                continue;
            }
            update_option('wpc_delay_v3_manifest_off', time(), false);
            set_transient('wpc_delay_v3_manifest_notice', $wpc_pb, WEEK_IN_SECONDS);
            $wpc_purge_page($wpc_report_path, 'delay-manifest-off');
            // A script the manifest promoted to eager throws "is not defined" twice: its keep list
            // missed a dependency, so the measured manifest goes off site-wide.
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('delay-manifest-off', '', $wpc_report_path_logged, ['script' => substr($wpc_pb, 0, 80), 'errors' => (int) $entry['n']]);
            }
            break;
        }
    }


    if (apply_filters('wpc_delay_v3_autotune', true)) {
        $tuned = get_option('wpc_delay_v3_autotuned', []);
        if (!is_array($tuned)) {
            $tuned = [];
        }
        if (count($tuned) < 5) {
            foreach ($log as $entry) {
                if ((int) $entry['n'] < 3 || empty($entry['f'])) {
                    continue;
                }
                // A jQuery-capability failure names a VICTIM, not a culprit: the erroring
                // script hit a poisoned/fake window.jQuery (queue-stub replay class), and
                // excluding it runs it at parse against the same fake — still broken, plus
                // an eager render-blocking chain (sppf: intlTelInput/countrySelect were
                // quarantined for exactly this and kept erroring). Leave the environment
                // failure to the root-cause keeps; never quarantine the messenger.
                if (preg_match('/\.\s*(?:on|each|extend|hasclass|ready|ajax|fn)\b[^a-z]{0,4}is not a function|pseudos|jquery is not|\$ is not/i', (string) $entry['m'])
                    && !apply_filters('wpc_autotune_jqenv_ok', false, (string) $entry['f'])) {
                    continue;
                }


                $wpc_fh = strtolower((string) parse_url((string) $entry['f'], PHP_URL_HOST));
                if ($wpc_fh !== '' && $wpc_strip($wpc_fh) !== $wpc_strip($wpc_sh)
                    && strpos($wpc_fh, 'zapwp') === false && strpos($wpc_fh, 'b-cdn') === false) {
                    continue;
                }
                $base = basename((string) parse_url($entry['f'], PHP_URL_PATH));

                if ($base === '' || strlen($base) < 6 || strpos($base, 'delay-v3-loader') !== false || strpos($base, 'optimize') === 0) {
                    continue;
                }


                if ((strpos($base, 'jquery') !== false || preg_match('/-js-(after|before)$/', $base))
                    && !apply_filters('wpc_autotune_jquery_ok', false, $base)) {
                    continue;
                }

                // "excluding" one here would run it at parse, which IS the failing state.
                if (in_array($base, $wpc_promoted, true)) {
                    continue;
                }
                if (isset($tuned[$base])) {
                    continue;
                }
                $ex = get_option('wpc-excludes', []);
                if (!is_array($ex)) {
                    $ex = [];
                }
                // Into the one Delay JS exclude list (`delay_js_v3`), where both boxes show it and
                // a removal from either box removes it. It wrote `delay_js_v2`, which only the old
                // union reader served and the site's own box never showed.
                $ex = wpc_delay_excludes_fold($ex);
                if (empty($ex['delay_js_v3']) || !is_array($ex['delay_js_v3'])) {
                    $ex['delay_js_v3'] = [];
                }
                $autotuneAdded = !in_array($base, $ex['delay_js_v3'], true);
                if ($autotuneAdded) {
                    $ex['delay_js_v3'][] = $base;
                    update_option('wpc-excludes', $ex);
                    set_transient('wpc_delay_v3_autotune_notice', $base, WEEK_IN_SECONDS);
                    $wpc_purge_page($wpc_report_path, 'delay-autotune');
                }
                // A same-site script that errors three times under replay joins the Delay JS
                // excludes (at most five); the replay breaks it and nothing predicts which.
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('delay-autotune-excluded', '', $wpc_report_path_logged, [
                        'script' => substr($base, 0, 80),
                        'errors' => (int) $entry['n'],
                        'added' => $autotuneAdded ? 1 : 0,
                    ]);
                }
                $tuned[$base] = time();
                update_option('wpc_delay_v3_autotuned', $tuned, false);
                break;
            }
        }
    }
    wp_send_json_success();
}
add_action('wp_ajax_wpc_delay_v3_report', 'wpc_delay_v3_report_handler');
add_action('wp_ajax_nopriv_wpc_delay_v3_report', 'wpc_delay_v3_report_handler');
// v7.22.14 — SELF-HEAL NOTICES ARE NOT NEWS (the .929 rule: a notice that reads as a
// problem when nothing is wrong). All three delay self-heal notices now default to a
// log receipt only; wpc_delay_admin_notices => true restores them for support.
add_action('admin_notices', function () {
    $base = get_transient('wpc_delay_v3_autotune_notice');
    if (empty($base)) {
        return;
    }
    if (!apply_filters('wpc_delay_admin_notices', false)) {
        if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('delay-notice-muted', '', '', ['kind' => 'autotune', 'base' => substr((string) $base, 0, 80)]); }
        delete_transient('wpc_delay_v3_autotune_notice');
        return;
    }
    echo '<div class="notice notice-info is-dismissible"><p><strong>WP Compress — JavaScript delay self-tuned:</strong> visitors repeatedly hit errors from <code>'
        . esc_html($base) . '</code> while it was delayed, so it was automatically added to your "Scripts to Exclude" list (Optimize JavaScript → Excludes) and the cached copy of the page that reported it was refreshed (other pages pick it up as they are cached again). You can remove it there any time.</p></div>';
    delete_transient('wpc_delay_v3_autotune_notice');
});
add_action('admin_notices', function () {
    $wpc_pb = get_transient('wpc_delay_v3_manifest_notice');
    if (empty($wpc_pb)) {
        return;
    }
    if (!apply_filters('wpc_delay_admin_notices', false)) {
        if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('delay-notice-muted', '', '', ['kind' => 'manifest', 'base' => substr((string) $wpc_pb, 0, 80)]); }
        delete_transient('wpc_delay_v3_manifest_notice');
        return;
    }
    echo '<div class="notice notice-info is-dismissible"><p><strong>WP Compress — JavaScript delay self-healed:</strong> visitors hit errors from <code>'
        . esc_html($wpc_pb) . '</code> after the render analysis moved it earlier in the load, so this site was automatically reverted to the standard (safe) delay behavior and the cached copy of the page that reported it was refreshed (other pages pick it up as they are cached again). It re-evaluates automatically after the next page analysis; nothing needs your attention.</p></div>';
    delete_transient('wpc_delay_v3_manifest_notice');
});
add_action('admin_notices', function () {
    if (!get_option('wpc_delay_aggr_off') || !current_user_can('manage_options')) {
        return;
    }
    if (!apply_filters('wpc_delay_admin_notices', false)) {
        return;
    }
    echo '<div class="notice notice-info"><p><strong>WP Compress — instant-boot mode paused:</strong> visitor reports showed delayed scripts failing to finish booting on a few pages, so this site was automatically switched back to the standard (timed) delay behavior. It re-arms on the next optimization refresh; nothing needs your attention.</p></div>';
});
// v7.10.929 — the direct-entry-403 admin notice is GONE (hdavid receipt: it reads as a
// problem when nothing is wrong — the fallback path serves everything; direct entry is a
// silent optimization). The wpc_v2_direct_entry_403s counter still records the state for
// the debug tool; a working fallback must never surface a host lecture to the user.


if (!function_exists('wpc_first_run_home_crit_exists')) {
    function wpc_first_run_home_crit_exists()
    {
        if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
            return true;
        }
        $homePage = function_exists('get_option') ? get_option('page_on_front') : 0;
        $url = (!empty($homePage) && function_exists('get_permalink')) ? get_permalink($homePage) : home_url('/');
        $key = (new wps_ic_url_key())->setup($url);
        if ($key === '') {
            return true;
        }
        $f = rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/critical_desktop.css';
        return @file_exists($f) && @filesize($f) > 0;
    }
}
if (!function_exists('wpc_first_run_dispatch_now')) {
    function wpc_first_run_dispatch_now()
    {
        if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) {
            wpc_finish_request();
        }
        if (!class_exists('wps_criticalCss')) {
            @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
        }
        if (class_exists('wps_criticalCss')) {
            try {
                $c = new wps_criticalCss();
                $c->generateCriticalCSS('home');
            } catch (\Throwable $e) {}
        }
    }
}
// v7.10.403: background account-status refresh. Scheduled by check_account_status() on a
// normal render so the synchronous apiv3 call lands here (cron loopback) instead of on the
// page-load worker — ignore_transient=true bypasses the async short-circuit and does the pull.
add_action('wpc_account_status_refresh', function () {
    if (class_exists('wps_ic') && method_exists('wps_ic', 'check_account_status')) {
        wps_ic::check_account_status(true);
    }
});

// One-time recovery for the apiv3-DNS mass-disconnect (v7.10.411). If an old-build wipe
// blanked the canonical key but the api_key survived in a secondary option, re-register with
// it (connectWithKey restores api_key + response_key + clears the flag) against the now-fixed
// service. connectWithKey blocks up to 60s, so it runs detached in a one-shot bg event.
if (!function_exists('wpc_apiv3_recover_surviving_key')) {
    function wpc_apiv3_recover_surviving_key() {
        $key = '';
        foreach (['wps_ic_options', 'wps_ic_settings'] as $src) {
            $o = get_option($src);
            if (is_array($o) && !empty($o['api_key'])) { $key = (string) $o['api_key']; break; }
        }
        return $key;
    }
    function wpc_apiv3_recover_needed() {
        $canon = get_option('wps_ic');
        return !(is_array($canon) && !empty($canon['api_key']) && !empty($canon['response_key']));
    }
}
add_action('admin_init', function () {
    if (get_option('wpc_apiv3_reconnect_done')) { return; }
    if (!wpc_apiv3_recover_needed()) { update_option('wpc_apiv3_reconnect_done', 1, false); return; }
    if (wpc_apiv3_recover_surviving_key() === '') { return; }   // no local key -> service push / manual
    if (get_transient('wpc_apiv3_reconnect_backoff')) { return; }
    if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
        && !wp_next_scheduled('wpc_apiv3_reconnect')) {
        set_transient('wpc_apiv3_reconnect_backoff', 1, 15 * MINUTE_IN_SECONDS);
        wp_schedule_single_event(time() + 5, 'wpc_apiv3_reconnect');
        wpc_spawn_cron();
    }
}, 5);
add_action('wpc_apiv3_reconnect', function () {
    if (get_option('wpc_apiv3_reconnect_done')) { return; }
    if (!wpc_apiv3_recover_needed()) { update_option('wpc_apiv3_reconnect_done', 1, false); return; }
    $key = wpc_apiv3_recover_surviving_key();
    if ($key === '') { update_option('wpc_apiv3_reconnect_done', 1, false); return; }
    if (class_exists('wps_ic_connect')) {
        $res = (new wps_ic_connect())->connectWithKey($key);
        if (is_array($res) && !empty($res['success'])) {
            update_option('wpc_apiv3_reconnect_done', 1, false);   // reconnected — never runs again
        }
        // on failure the done-flag stays unset; the 15-min backoff reschedules a retry
    }
});
add_action('admin_init', function () {
    $opts = function_exists('get_option') ? get_option(WPS_IC_OPTIONS) : [];
    if (empty($opts['api_key'])) {
        return;
    }
    if (wpc_first_run_home_crit_exists()) {
        if (get_option('wpc_first_run_attempts') !== false) {
            delete_option('wpc_first_run_dispatched_at');
            delete_option('wpc_first_run_attempts');
            delete_option('wpc_first_run_failed');
        }
        return;
    }
    $dispatchedAt = (int) get_option('wpc_first_run_dispatched_at');
    $attempts     = (int) get_option('wpc_first_run_attempts');
    $timeout      = (int) apply_filters('wpc_first_run_timeout_seconds', 180);
    $maxAttempts  = (int) apply_filters('wpc_first_run_max_attempts', 5);
    // Fast retries while under the cap; then an hourly backstop — never permanently give up, never hammer.
    $interval = ($attempts < $maxAttempts) ? $timeout : 3600;
    if ($dispatchedAt > 0 && (time() - $dispatchedAt) < $interval) {
        return;
    }
    if ($attempts >= $maxAttempts && !get_option('wpc_first_run_failed')) {
        update_option('wpc_first_run_failed', 1, false); // UI: surface a retry/error, not a spinner
    }
    update_option('wpc_first_run_dispatched_at', time(), false);
    update_option('wpc_first_run_attempts', $attempts + 1, false);
    register_shutdown_function('wpc_first_run_dispatch_now');
}, 20);


if (!function_exists('wpc_first_run_psi_now')) {
    function wpc_first_run_psi_now()
    {
        if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) {
            wpc_finish_request();
        }
        $opts = get_option(WPS_IC_OPTIONS);
        if (empty($opts['api_key'])) {
            return;
        }
        $uuid = function_exists('get_transient') ? get_transient('wpc_psi_uuid') : '';
        if (!empty($uuid)) {
            // PULL: poll get-results/{uuid}; saveBenchmark() fills WPS_IC_LITE_GPS when the run is complete.
            if (!class_exists('wps_criticalCss')) {
                @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
            }
            if (class_exists('wps_criticalCss') && class_exists('wps_ic_url_key')) {
                try {
                    $homePage = get_option('page_on_front');
                    $url = (!empty($homePage) && function_exists('get_permalink')) ? get_permalink($homePage) : home_url('/');
                    $key = (new wps_ic_url_key())->setup($url);
                    (new wps_criticalCss())->saveBenchmark($key, $uuid);
                } catch (\Throwable $e) {}
            }
            return;
        }
        // No uuid stashed → dispatch a fresh run keyed on a plugin uuid (pull-recoverable next cycle).
        $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(8));
        set_transient('wpc_psi_uuid', $uuid, 30 * 60);
        try {
            $requests = new wps_ic_requests();
            $args = [
                'url'            => home_url(),
                'uuid'           => $uuid,
                'hash'           => $uuid,
                'apikey'         => $opts['api_key'],
                'version'        => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
                'plugin_version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
            ];
            $requests->POST(WPS_IC_PAGESPEED_API_URL_HOME, $args, ['timeout' => 2, 'blocking' => false, 'headers' => ['Content-Type' => 'application/json']]);
        } catch (\Throwable $e) {}
    }
}
add_action('admin_init', function () {
    $opts = function_exists('get_option') ? get_option(WPS_IC_OPTIONS) : [];
    if (empty($opts['api_key'])) {
        return;
    }
    $gps = get_option(WPS_IC_LITE_GPS);
    if (!empty($gps['result'])) {
        if (get_option('wpc_first_run_psi_attempts') !== false) {
            delete_option('wpc_first_run_psi_at');
            delete_option('wpc_first_run_psi_attempts');
        }
        return; // PageSpeed card is populated — done
    }
    $at          = (int) get_option('wpc_first_run_psi_at');
    $attempts    = (int) get_option('wpc_first_run_psi_attempts');
    $timeout     = (int) apply_filters('wpc_first_run_timeout_seconds', 180);
    $maxAttempts = (int) apply_filters('wpc_first_run_max_attempts', 5);
    $interval    = ($attempts < $maxAttempts) ? $timeout : 3600;
    if ($at > 0 && (time() - $at) < $interval) {
        return;
    }
    if ($attempts >= $maxAttempts && !get_option('wpc_first_run_failed')) {
        update_option('wpc_first_run_failed', 1, false);
    }
    update_option('wpc_first_run_psi_at', time(), false);
    update_option('wpc_first_run_psi_attempts', $attempts + 1, false);
    register_shutdown_function('wpc_first_run_psi_now');
}, 21);


include_once WPS_IC_DIR . 'addons/cache/warm.php';
include_once WPS_IC_DIR . 'addons/rail/rail.php';
include_once WPS_IC_DIR . 'addons/vitals/vitals.php';

// + Auto Mode toggle); with the gate closed loading is a boolean check, zero HTTP/DB.
include_once WPS_IC_DIR . 'addons/cache/beacon.php';


include_once WPS_IC_DIR . 'addons/cache/link-preset.php';

include_once WPS_IC_DIR . 'addons/debug/db-health.php';

// The doctor (addons/doctor/): read-only reports for support tickets. Loaded in the admin
// (admin-ajax included) and for the agency relay; never on a visitor's render.
if ((function_exists('is_admin') && is_admin()) || isset($_GET['comms_action']) || isset($_POST['comms_action'])) {
    include_once WPS_IC_DIR . 'addons/doctor/doctor.php';
}
