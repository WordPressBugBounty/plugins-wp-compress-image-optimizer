<?php
global $ic_running;
global $wps_ic_cdn_instance;

// Logs plugin PHP warnings to the DB for the debug tool. Only captures our own
// files, dedupes, caps at 50 entries, always returns false so PHP still handles
// the error normally.
if (!defined('WPC_ERROR_CAPTURE_DISABLED')) {
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        try {
            // Honor the @-operator: error_reporting() is 0 during a suppressed
            // call, and we shouldn't log what the developer chose to suppress.
            if (error_reporting() === 0) {
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

// WPC URL Pattern Matcher — shared by dontRunif() and advanced-cache.php
// Wildcard syntax: * = single path segment, ** = any depth, ? = single char,
// plain text = case-insensitive substring (backwards compatible with old exact entries)
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

            // Per-tag sample cap: only log first 5 of each tag per request
            $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            if ($tagCounts[$tag] > 5) return;

            // Per-request dedupe on exact tag+detail
            $key = $tag . '|' . $detail;
            if (isset($seen[$key])) return;
            $seen[$key] = true;

            // Hard memory cap
            if (count($seen) > 100) return;

            $log = get_option('wpc_diagnostic_log', []);
            if (!is_array($log)) $log = [];
            $log[] = date('Y-m-d H:i:s') . ' | ' . $tag . ' | ' . $detail;
            update_option('wpc_diagnostic_log', array_slice($log, -100), false);
        } catch (\Throwable $e) {
            // Never let diagnostic logging itself break things
        }
    }
}

include_once __DIR__ . '/debug.php';
include_once __DIR__ . '/defines.php';
include_once WPS_IC_DIR . 'addons/cdn/cdn-rewrite.php';
include_once WPS_IC_DIR . 'addons/cdn/modern-delivery.php';
include_once WPS_IC_DIR . 'addons/cdn/delivery-resolver.php'; // load before negotiated — its is_active() consults the resolver
include_once WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php'; // inert until EMISSION_READY; gated by the resolver
include_once WPS_IC_DIR . 'addons/legacy/compress.php';
include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';

// v2 protocol bootstrap. Guards on the `wpc_protocol_version` option (default
// 'v1' = no-op); when non-'v1' it loads the WPS_LocalV2 client, the
// /wpc/v2/bg_swap REST endpoint, and the capability probe.
include_once WPS_IC_DIR . 'addons/v2/v2-bootstrap.php';

// Outermost natural-URL buffer. Catches /m:N/a: asset transform URLs printed
// past a mid-page flush() (import-maps, late footers, prefetch JSON) that
// cdnRewriter's buffer can't reach. Inert unless negotiated delivery is GA.
include_once WPS_IC_DIR . 'addons/v2/v2-natural-url-buffer.php';

//TRAITS
include WPS_IC_DIR . 'traits/agency.php';

// ─── Local Optimization Helpers ──────────────────────────────────────────

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

    $id = attachment_url_to_postid($base_url);
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

/**
 * Bulk liveness heartbeat — bump on every unit of bulk progress.
 *
 * The "Local is running" badge is driven by the autoloaded option
 * wps_ic_bulk_process (no TTL), which doubles as the worker's "keep going" flag.
 * If the driver dies mid-run — tab closed (JS-sequential), FPM/OOM kill — its
 * terminal cleanup never runs and the flag is orphaned, pinning the badge forever.
 *
 * This short-TTL transient is the liveness signal: each bulk action (per image /
 * per drain slice) refreshes it, so a genuinely-advancing bulk never lets it lapse.
 * Bump it ONLY on bulk progress — not on unrelated local actions (a manual single
 * compress later must not resurrect a dead bulk's heartbeat).
 */
function wpc_bulk_heartbeat_touch() {
    // 5 min TTL. Bulk actions are seconds apart (per image ~12s, per slice <=30s),
    // so this never lapses mid-run; once the driver dies it expires within 5 min.
    set_transient('wpc_bulk_heartbeat', time(), 300);
}

/**
 * Read wps_ic_bulk_process with an orphan self-heal.
 *
 * Returns the bulk-process array while genuinely live (heartbeat fresh), or false
 * once the driver has died and the heartbeat lapsed — clearing the orphaned flag
 * at read time (which always runs), since the worker's own cleanup didn't.
 *
 * Use this anywhere the UI decides whether Local optimization is "running" — a raw
 * get_option can't tell a live bulk from a dead one.
 *
 * @return array|false The bulk-process array while genuinely live, else false.
 */
function wpc_bulk_process_active() {
    $bp = get_option('wps_ic_bulk_process');
    if (empty($bp)) {
        return false;
    }
    if (get_transient('wpc_bulk_heartbeat')) {
        return $bp; // a recent bulk action kept it alive → genuinely running
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

    // Also purge the CDN-transform variants. The cdn-mc purge above only clears the
    // natural origin URL, but images are fetched via transform URLs
    // (…/q:i/r:0/wp:2/w:1510/u:<origin>) — separate edge cache entries the origin purge
    // doesn't touch. Purge each ladder width × format (jpg/webp/avif), non-blocking.
    // `u:` needs the full URL including host (cdn-mc keys transforms by it).
    $site_url = site_url();
    $ladder_widths = [150, 221, 300, 400, 442, 480, 640, 720, 755, 768, 800,
                      960, 1100, 1132, 1200, 1280, 1366, 1440, 1510, 1536,
                      1600, 1800, 1887, 2048, 2560];
    $cdn_zone = '';
    $custom_cname = get_option('ic_custom_cname');
    $cdn_zone = !empty($custom_cname) ? $custom_cname : (string) get_option('ic_cdn_zone_name');
    if ($cdn_zone !== '') {
        // Iterate both the origin-host and cdn-zone-host forms of `u:`: uForCdn() can
        // rewrite the `u:` host from origin to cdn-zone, so the same image+width has two
        // distinct cache keys — purging only one leaves the other served stale.
        $u_hosts = ['https://' . $cdn_zone];
        if (rtrim($site_url, '/') !== rtrim($u_hosts[0], '/')) {
            $u_hosts[] = $site_url;  // origin host as separate variant
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

/**
 * Targeted single-URL CDN purge for bg-swap callbacks. Purges just the file we
 * rewrote, not the 16+ URL fan-out of wpc_purge_cdn_urls — callbacks fire 5-15×
 * per compress, so the full-attachment helper would burn 16×N requests.
 *
 * @param int    $attachment_id Image post ID (used to attribute the purge in logs).
 * @param string $abs_path      Absolute filesystem path of the just-written file.
 *                              Helper converts to uploads-relative URL internally.
 */
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
 * Get the whitelabel-aware plugin display name.
 * Reads from the Settings submenu (which whitelabel plugins override), falls back to 'WP Compress'.
 */
function wpc_get_plugin_name() {
    static $cached = null;
    if ($cached !== null) return $cached;

    global $submenu;
    if (isset($submenu['options-general.php']) && class_exists('wps_ic')) {
        foreach ($submenu['options-general.php'] as $item) {
            if (isset($item[2]) && $item[2] === wps_ic::$slug) {
                $cached = wp_strip_all_tags($item[0]);
                return $cached;
            }
        }
    }

    $cached = __('WP Compress', 'wp-compress-image-optimizer');
    return $cached;
}

/**
 * Rewrite the CDN-transform URLs in an <img>'s src/srcset/data-* to natural
 * (CDN-passthrough) URLs when the underlying file exists on disk. The natural
 * file is preferred (higher quality, lower CDN load); the transform URL is only
 * useful as a fallback, so it's kept when the file is missing.
 *
 * Already-natural / external / data: URLs pass through. srcset width descriptors
 * are preserved exactly. Query strings are stripped for the disk lookup but kept
 * in the output URL.
 */
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

    // Two CDN URL shapes carry the origin URL as a suffix (running to end of
    // string, since the attr/srcset parsers already trimmed it):
    //   /u:<origin_url>      — img-tag adaptive transforms (q:i/r:0/wp:N/w:N/u:URL)
    //   /m:0/a:<origin_url>  — picture <source> AVIF wraps + asset passthroughs
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

// ─── Picture-wrap srcset-selection helpers ───────────────────────────────────
//
// Make sizes="auto" actually fire on the <source> tags from
// wpc_inject_picture_tags(). Per HTML spec auto is ignored unless the sibling
// <img> is loading="lazy", and WP only lazies the first 1-2 images — leaving
// every other content image eager and our auto hint dead.
//
// Both helpers are filterable so power users can opt out without disabling the
// master picture_webp toggle:
//   - wpc_picture_inject_lazy   (bool)  — gate the loading="lazy" injection
//   - wpc_picture_lcp_sizes     (string)— override the smart-sizes ladder

if (!function_exists('wpc_picture_should_inject_lazy')) {
/**
 * Decide whether to add loading="lazy" to a content IMG inside the picture wrap.
 * Skip when it's the LCP target, already has loading=, or the user turned the
 * master lazy setting off (don't silently re-enable lazy they disabled).
 *
 * @param string $img_tag  raw <img …> tag captured by the picture-wrap regex
 * @param array  $settings full plugin settings array (reads lazy-load gate)
 * @return bool true ⇒ caller should inject loading="lazy"
 */
function wpc_picture_should_inject_lazy($img_tag, $settings)
{
    // The "Native Lazy" toggle key is `nativeLazy` — NOT `lazy-load` (phantom
    // key) and NOT `lazy` (that's the separate JS viewport-lazy loader). Unset
    // is treated as default-on, matching the options.class.php presets.
    if (isset($settings['nativeLazy']) && (string) $settings['nativeLazy'] !== '1') {
        return (bool) apply_filters('wpc_picture_inject_lazy', false, $img_tag, $settings);
    }
    // Don't double-inject if loading= is already present
    if (preg_match('/\sloading\s*=\s*["\'][^"\']*["\']/i', $img_tag)) {
        return (bool) apply_filters('wpc_picture_inject_lazy', false, $img_tag, $settings);
    }
    // Skip lazy injection for eager-LCP IMGs. Lazying the LCP image to get a
    // sizes="auto" pick saves ~30KB but costs ~700ms LCP under throttling with
    // render-blocking fonts (the intersection observer waits on layout, layout
    // waits on font CSS). The smart-sizes ladder gets near-optimal picks anyway,
    // and an eager img is preload-scanned immediately. Non-LCP content images
    // still get lazy here (below the fold, so the observer delay is harmless).
    // Filter wpc_picture_inject_lazy → __return_true to opt back into lazy LCP.
    $is_eager_lcp = wpc_picture_is_eager_lcp_marker($img_tag);
    return (bool) apply_filters('wpc_picture_inject_lazy', !$is_eager_lcp, $img_tag, $settings);
}
}

if (!function_exists('wpc_picture_is_eager_lcp_marker')) {
/**
 * Decide whether an IMG looks like an eager LCP candidate needing the
 * smart-sizes override (override applies; lazy injection skipped).
 *
 * Can't rely on class markers (wpc-lcp-optimized etc.) or fetchpriority="high":
 * rewriteLogic's ob_start runs after the_content where we run, so the class
 * isn't set yet, and fp=high may be stripped/never-added. The signal available
 * here is structural: width ≥ 1200 + sizes containing "100vw" — WP's full-width
 * hero pattern, which resolves to 100vw and over-fetches on mobile (wrong for
 * every theme that constrains hero width, i.e. all default block themes).
 *
 * Trade-off: also fires for wide images that will become lazy (where auto would
 * measure layout) — we over-override on bandwidth-correct but non-adaptive
 * picks. Mitigated by bailing when loading="lazy" is already present.
 *
 * @return bool true ⇒ smart-sizes override should apply (and lazy injection
 *              should NOT happen — these images are explicitly eager hero)
 */
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
/**
 * Compute a viewport-aware sizes ladder for eager LCP IMGs (per
 * wpc_picture_is_eager_lcp_marker) whose WP fallback would resolve to 100vw on
 * mobile. Returns '' to leave WP's sizes untouched. Desktop cap defaults to 1200
 * (the typical block-theme container) — larger caps overshoot hero images that
 * don't fill the window. Filterable via wpc_picture_lcp_sizes.
 *
 * @param string $img_tag  raw <img …> tag
 * @param array  $settings full plugin settings (reads maxWidth)
 * @return string sizes value to write, or '' to leave WP's sizes alone
 */
if (!function_exists('wpc_get_theme_content_width')) {
/**
 * Resolve the theme's real content-column width server-side — the width PSI
 * measures as the LCP image's displayed size on desktop. Using it as the desktop
 * sizes tier (vs a hardcoded 1200 guess) makes the browser request a
 * correctly-sized image and clears the "Improve image delivery" diagnostic.
 *
 * Sources: block-theme theme.json contentSize, then the classic $content_width
 * global. Returns 0 when unknown (caller falls back to the 1200 cap).
 * Filter wpc_lcp_content_width overrides per-site.
 */
function wpc_get_theme_content_width()
{
    $w = 0;
    if (function_exists('wp_get_global_settings')) {
        $layout = wp_get_global_settings(['layout']);
        if (!empty($layout['contentSize'])) {
            $px = (int) preg_replace('/[^0-9]/', '', (string) $layout['contentSize']);
            if ($px >= 320 && $px <= 2000) $w = $px;
        }
    }
    if ($w === 0 && !empty($GLOBALS['content_width']) && (int) $GLOBALS['content_width'] >= 320) {
        $w = (int) $GLOBALS['content_width'];
    }
    return (int) apply_filters('wpc_lcp_content_width', $w);
}
}

function wpc_picture_compute_lcp_sizes($img_tag, $settings)
{
    if (!wpc_picture_is_eager_lcp_marker($img_tag)) {
        return (string) apply_filters('wpc_picture_lcp_sizes', '', $img_tag, $settings);
    }
    $intrinsic_w = 0;
    if (preg_match('/\swidth\s*=\s*["\'](\d+)["\']/i', $img_tag, $wm)) {
        $intrinsic_w = (int) $wm[1];
    }
    // Below ~1200, WP's own width-hint fallback already self-limits on mobile.
    if ($intrinsic_w < 1200) {
        return (string) apply_filters('wpc_picture_lcp_sizes', '', $img_tag, $settings);
    }
    $max_w       = !empty($settings['maxWidth']) ? (int) $settings['maxWidth'] : 2560;
    // Prefer the theme's real content width for the desktop tier; fall back to
    // the 1200 cap when the theme doesn't declare one.
    $content_w   = function_exists('wpc_get_theme_content_width') ? wpc_get_theme_content_width() : 0;
    $desktop_cap = $content_w > 0 ? $content_w : min(1200, max(400, $max_w));
    // Phone/tablet vw kept low (50/40) so DPR-4 profiles don't overshoot the
    // ladder. Mild softness on full-bleed themes is the trade-off (override via
    // the filter). Keep in lockstep with the equivalent value in rewriteLogic.
    $smart       = '(max-width: 600px) 50vw, (max-width: 1024px) 40vw, ' . $desktop_cap . 'px';
    return (string) apply_filters('wpc_picture_lcp_sizes', $smart, $img_tag, $settings);
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

    // Feed/AMP/REST bypass: <picture> must not leak into RSS/JSON/AMP where it's
    // invalid or chokes strict parsers. Allow the Gutenberg block-renderer
    // through so editor previews still deliver.
    if (function_exists('is_feed') && is_feed()) return $content;
    if (function_exists('is_amp_endpoint') && is_amp_endpoint()) return $content;
    if (function_exists('amp_is_request') && amp_is_request()) return $content;
    if (defined('REST_REQUEST') && REST_REQUEST) {
        $wpc_route = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if (strpos($wpc_route, '/wp/v2/block-renderer/') === false) return $content;
    }

    // Single-authority delivery: once the resolver verifies CDN-edge, negotiated
    // owns images as clean <img> + edge Accept-negotiation. This injector runs
    // upstream of cdnRewriter, so it must stand down too — otherwise it wraps the
    // <img> in <picture> before negotiated sees them. Inert until negotiated is
    // GA, so legacy behavior is unchanged on non-CDN-edge sites.
    if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) {
        return $content;
    }

    // Respect "Use Picture Tags" toggle — same setting controls CDN and local mode
    $settings = get_option(WPS_IC_SETTINGS);
    if (empty($settings['picture_webp']) || $settings['picture_webp'] != '1') return $content;

    // AVIF ceiling gate: only emit an AVIF <source> when the next-gen ceiling is
    // 'avif'. A 'webp'/'off' ceiling must not ship AVIF even if -avif variants
    // exist on disk (intent violation). Falls open if the resolver is absent.
    $wpc_avif_ok = !class_exists('WPC_Delivery_Resolver')
                   || WPC_Delivery_Resolver::effective_ceiling($settings) === 'avif';

    // WebP ceiling gate, parallel to the AVIF one. Without it, ceiling='off'
    // still wrapped the <img> with a webp <source> (file on disk → browser picks
    // webp) — the CDN-off + Next-Gen-off "served WebP not JPEG" leak. 'off' must
    // emit neither avif nor webp. Falls open if resolver absent.
    $wpc_webp_ok = !class_exists('WPC_Delivery_Resolver')
                   || WPC_Delivery_Resolver::effective_ceiling($settings) !== 'off';

    // Single authority on CDN sites: when live CDN is on, the output-buffer
    // rewriteLogic (path B) owns <picture> for all images — it resolves the zone,
    // builds the full ladder, and gates AVIF. This injector (path A) runs upstream
    // and never resolves $cdn_zone, so it would emit degraded markup that path B
    // then locks in via its stash. Stand down so content images route through B;
    // local/non-CDN sites keep path A below as the only picture emitter.
    //
    // BUT only stand down when CDN images are actually on. With the "Images" tile
    // OFF, path B doesn't touch content images and negotiated is gated off — so
    // path A must still emit the origin <picture>, otherwise Images-off +
    // Next-Gen-on would mean plain <img> with no next-gen at all.
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

            // Rewrite the <img>'s transform URLs to natural ones first (before
            // the variants-empty bail), so even an optimized image with no
            // variants at least gets natural URLs.
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

                // Only emit a natural-URL entry when the file exists on disk:
                // ic_local_variants can list files that were restored/deleted but
                // not cleared from postmeta, and emitting those 404s (Chrome
                // ERR_BLOCKED_BY_ORB). Missing widths get the never-404 transform
                // URL from the hybrid block below instead.
                $disk_path = $upload_basedir . '/' . $upload_subdir . '/' . $filename;
                if (!@file_exists($disk_path)) {
                    continue;
                }

                // Dimensional validity gate (disable via WPC_SKIP_PICTURE_VARIANT_VALIDATION).
                // A next-gen <source> is type-pinned with NO onerror, so one
                // degenerate variant poisons the render with no fallback to <img>:
                // e.g. a -1x1 minted from a width=1 label, or a full-size encode
                // emitted under a tiny width descriptor. file_exists() can't catch
                // these; rejecting empties the srcset and the $sources==='' escape
                // returns the bare (good) <img>.
                //
                // Fail-safe: the plugin downloads AVIF, never encodes it locally,
                // so a host without an AVIF decoder makes getimagesize() return
                // false for a VALID .avif. So: (1) a filename-only degenerate
                // check runs on every host; (2) byte-validation runs only when
                // getimagesize() actually decodes — false ⇒ keep, never drop.
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
                            continue; // decoded as degenerate
                        }
                        // name vs real-bytes mismatch; tolerance absorbs normal
                        // sub-size rounding without false-rejecting good variants.
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

            // Ideal-width generator, path-A leg. In Images-off / CDN-off modes
            // path A is the emitter and nothing regenerates adaptive rungs after
            // a restore wipes them, so the ladder is registered-only and the
            // browser rounds up (a 1240-class need grabs 1510). Queue the same
            // ideal widths the nd builder would; every per-width guard lives in
            // the queue helper. Full-bleed/builder classes are excluded.
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
                        // asymmetric: only a rung AT/ABOVE the target within 8% satisfies it
                        // (a smaller rung can't — the browser rounds up past it).
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

            // Per-srcset-entry hybrid emission. For each WP sub-size, per format,
            // emit the natural URL if the variant is on disk, else the CDN
            // transform URL which serves the format on-the-fly (and for wp:2
            // encodes AVIF in the background). Transform shapes:
            //   wp:0 → JPG, wp:1 → WebP, wp:2 → AVIF (WebP placeholder first load,
            //   real AVIF once encoded).
            // A natural .avif that doesn't exist yet would 404 and trip
            // ERR_BLOCKED_BY_ORB; the transform URL never 404s, so the srcset
            // migrates entry-by-entry to natural URLs as each variant lands.
            $lazy_enabled_for_optimistic = function_exists('wpc_v2_get_lazy_enabled')
                                            && wpc_v2_get_lazy_enabled();
            // Lazy is the gate for sight-unseen AVIF rungs: without the zone's
            // lazy flag the pod serves webp-interim but fires NO backfill trigger
            // (opt_out), so the rung stays interim forever. A site enabling lazy
            // flips that flag via its own /v2/config save, so the rung lands ≤60s.
            // Also require a LIVE CDN: $cdn_zone is just the zone name (set even
            // when live-cdn=0, possibly disconnected), and srcset rungs have no
            // onerror, so a dead-zone transform rung renders broken. CDN-off
            // interim = registered naturals only; backfill fills the rest in ~90s.
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

            // Universal fine-grained ladder: a sparse srcset over-fetches 4-10×
            // on high-DPR devices, so add LCP-style widths + retina doubles to
            // every image. Encoding stays on-demand via lazy_cdn (only requested
            // widths get encoded). Portrait images cap width at maxWidth × aspect
            // so the encoded height doesn't blow past the configured size cap
            // (same maxdim rule as rewriteLogic's buildLcpSrcset).
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
            $smart_lcp_sizes = wpc_picture_compute_lcp_sizes($img_tag, $settings);
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
            // Prepend `auto,` so browsers measure rendered size and pick exact —
            // but only when the IMG is lazy. auto is invalid on eager images and
            // on the LCP hero it poisons sizes → Chrome falls back to 100vw and
            // over-fetches the top of the ladder. Eager imgs keep their auto-free
            // sizes so the picker resolves the same content-width slot.
            $img_is_lazy_for_auto = (stripos($img_tag, 'loading="lazy"') !== false);
            if ($img_is_lazy_for_auto && stripos($sizes, 'auto') === false) {
                $sizes = 'auto, ' . $sizes;
            }

            // Mobile srcset cap. Gates on `generate_adaptive` (the "Resize by
            // Incoming Device" toggle) — the same key rewriteLogic uses, NOT the
            // separate `adaptive` section header. Applied at final assembly so it
            // filters widths from every loop above, not just one.
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
            if ($wpc_avif_ok && !empty($avif_srcset)) { // ceiling-gated: webp/off → no AVIF source
                ksort($avif_srcset);
                $sources .= '<source type="image/avif" ' . $srcsetAttr . '="' . implode(', ', $avif_srcset) . '" sizes="' . esc_attr($sizes) . '">';
            }
            if ($wpc_webp_ok && !empty($webp_srcset)) { // ceiling-gated: off → no WebP source
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
    // display:contents makes <picture> invisible to layout, so the child <img>
    // inherits parent CSS as if the wrapper weren't there.
    echo '<style>.wpc-picture{display:contents;}</style>' . "\n";
}
add_action('wp_head', 'wpc_picture_tag_css', 1);

// Missing uploads-image variants must 404 clean, never 302. WP core's 404
// permalink-guess matches a missing "…-1024x501.avif" to the attachment and 302s
// to the original ".png" — the CDN edge then receives PNG bytes under the .avif
// URL and has to sniff-reject them. A true 404 is a clean miss signal and keeps
// the edge's self-heal honest. Scoped to uploads images; page/post guessing
// untouched.
function wpc_no_404_guess_for_upload_images($do_guess)
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri !== '' && preg_match('#/wp-content/uploads/[^?]+\.(avif|webp|jpe?g|png|gif|svg|ico)(\?|$)#i', $uri)) {
        return false;
    }
    return $do_guess;
}
add_filter('do_redirect_guess_404_permalink', 'wpc_no_404_guess_for_upload_images');

// Hard-stop the 404 for uploads images before redirect plugins run. The guard
// above stops WP core's redirect, but SEO/redirect plugins (template_redirect@10)
// still 302 missing variants to the homepage, feeding the edge HTML bytes on its
// .avif fetch. Priority 0 wins: send the honest 404 and exit. Fires only when WP
// already ruled the request a 404 on an uploads image (real files never reach PHP).
function wpc_hard_404_for_upload_images()
{
    if (!function_exists('is_404') || !is_404()) {
        return;
    }
    // is_404() means WP's full request handling (incl. any offload integration) ALREADY ruled this a
    // 404 — so a bare reply here is offload-safe (never a false-404 on a live image). Extend it to ANY
    // image-ext path (uploads, /storage, page-builder, offloaded) — not just /wp-content/uploads — so a
    // missing image stops rendering the theme's 404 template (often a multi-hundred-KB page: acrystalglass
    // /storage misses were 447 KB / ~4 s). Returning ~9 bytes and exiting frees the FPM worker far sooner
    // and slashes the per-miss bytes. This is the "make the 404 worker efficient" win for the paths the
    // pre-core fast-404 (mu-plugin / advanced-cache) can't verify on local disk (offloaded /storage).
    $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = $uri !== '' ? (string) parse_url($uri, PHP_URL_PATH) : '';
    if ($path === '' || !preg_match('#\.(avif|webp|jpe?g|png|gif|svg|ico|bmp|tiff?)$#i', $path)) {
        return;
    }
    status_header(404);
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header('X-WPC-Fast-404: tr'); // template_redirect skip-theme path (distinct from the mu-plugin's :1)
    echo 'Not Found';
    exit;
}
add_action('template_redirect', 'wpc_hard_404_for_upload_images', 0);

// Some redirect plugins run before template_redirect, so also catch this at
// init@0 with a file-existence check. An uploads-image URI reaching PHP means
// the web server found no file (statics never hit PHP); verify on disk and 404.
// Anchored to the literal /wp-content/uploads/ prefix, so relocated-uploads
// sites simply don't match (defense stays at template_redirect there).
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
    $file = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content') . '/uploads/' . $rel;
    if (@file_exists($file)) {
        return; // real file — let whatever routed it here proceed
    }
    status_header(404);
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}
add_action('init', 'wpc_early_404_for_missing_upload_images', 0);

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
        self::$version = '7.10.09';

        $development = get_option('wps_ic_development');
        if (!empty($development) && $development == 'true') {
            self::$version = time();
        }

        $wps_ic = $this;
        self::$accStatusChecked = false;
        // Do NOT swap self::$slug to the whitelabel slug. The whitelabel companion
        // (cache-commander) hardcodes a list of `wpcompress-*` enqueue handles to
        // find/copy/re-enqueue from its own /files/ dir; swapping the slug changed
        // our handles so they never matched, leaving wp-compress-* asset URLs (and
        // 404s in some multisite topologies). Keeping 'wpcompress' lets that
        // pipeline match. The ?page redirect chain still works — one extra hop.

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

        $cache = new wps_ic_cache();
        $cache->purgeHooks();

        $this->integrations = new wps_ic_integrations();
        // Light-ajax skip: the high-frequency compress handlers (variant_count,
        // heartbeat) fire 4-6×/sec, and add_admin_hooks/apply_admin_filters cost
        // 200-1500ms instantiating every plugin-check class to register admin_init/
        // admin_menu callbacks that don't fire on admin-ajax anyway. Skip the
        // registration; keep the instance for later $this->integrations access.
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
            update_option(WPS_IC_CF_CNAME, $cf['custom_cname']);
        }


        //$cache_warmup = new wps_ic_cache_warmup();
        //$cache_warmup->add_hooks();
    }

    /**
     * Write Debug Log
     *
     * @param $message
     *
     * @return void
     */
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
            file_put_contents($log_file, $log);
        }
    }

    public static function generate_critical_cron()
    {
        $criticalCSS = new wps_criticalCss();
        $criticalCSS->generate_critical_cron();
    }

    /**
     * If Plugin Version Changed, do...
     * @return void
     */
    public static function checkPluginVersion()
    {
        if (is_admin()) {
            $installed_version = get_option('wpc_core_version');

            if (version_compare($installed_version, self::$version, '<') || !empty($_GET['simulateVersionChange'])) {

                // (v7.10.04) CRASH-PROOF UPGRADE PASS — world-class reliable, cron-free.
                //
                // Every upgrade-time step below is wrapped so that NO error can white-screen
                // the admin/settings page (the symptom customers saw on a bad upgrade). Two layers:
                //
                //   1. try/catch (\Throwable) — catches any Error/Exception thrown by the upgrade
                //      work and swallows it (logged, not rethrown) → the page still renders.
                //   2. register_shutdown_function — traps a HARD fatal (OOM / max_execution_time /
                //      a fatal the catch can't see) and logs it.
                //
                // Self-resuming WITHOUT cron: wpc_core_version is bumped ONLY after a full clean
                // pass (moved to the very end of this block). If the pass dies partway — caught
                // error OR hard fatal — the version stays behind, so the NEXT admin page load
                // re-enters and runs the whole pass again. Every individual step here is
                // idempotent (cache purges are repeatable; the one-time steps self-guard with
                // their own done-flags: wpc_cf_bypass_v5, wpc_avif_natural_default_v70, the
                // wpc_cf_cname_verified sentinel), so replay-on-retry is safe and converges.
                // No behavior change on a healthy upgrade — same work, same order, just guarded.
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

                // Purge Cache
                $cache = new wps_ic_cache_integrations();
                $cache::purgeAll(false, false, false, true, false, true); // preserve wp-cio/css on update
                $cache::purgeCriticalFiles();
                $cache::purgeCacheFiles(false, true); // preserve wp-cio/css on update

                // Purge Object Cache
                $cacheObject = new wps_ic_cache();
                $cacheObject->purgeObjectCache();

                // Invalidate the durable CSS/JS asset-MIME proof on upgrade: the
                // edge rules or our emit host may have changed across versions, so
                // re-verify the live edge from scratch rather than trust a stale verdict.
                if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'invalidate_asset_mime_proof')) {
                    wps_rewriteLogic::invalidate_asset_mime_proof();
                }

                // (v7.03.119) Force a DELIVERY (image-edge) re-verify on upgrade — the automatic
                // equivalent of clicking "Re-check now", so every site self-promotes to the Bunny CDN-edge
                // tier on update without anyone touching it. Above only re-verified the CSS/JS asset lane;
                // this covers the IMAGE lane. Promote-on-proof: force_provision makes the next resolve run a
                // fresh verify (re-promotes to edge IF it verifies, else stays on the safe tier — never
                // forces a bad edge), and ensure_bg re-asserts Bunny provisioning (AVIF Vary + edge rules,
                // non-blocking). Reset the self-heal counter/backoff so the upgrade attempt isn't throttled.
                if (function_exists('update_option'))    update_option('wpc_v2_force_provision', 1, false);
                if (function_exists('delete_option'))    delete_option('wpc_v2_selfheal_attempts');
                if (function_exists('delete_transient')) delete_transient('wpc_v2_selfheal_backoff');
                if (function_exists('wpc_v2_provision_ensure_bg')) {
                    wpc_v2_provision_ensure_bg('upgrade');
                }

                // Regen advanced-cache.php from the updated template so new
                // features take effect on upgrade without a settings save. Also
                // re-assert WP_CACHE=true — some upgrade paths (uploader, managed-
                // host snapshots) skip activation() so the constant can drift false.
                //
                // (v7.10.04) GATED on WPC page caching being enabled. Previously this ran
                // unconditionally on every update, so it clobbered another caching plugin's
                // advanced-cache.php (e.g. W3TC) and forced WP_CACHE=true on sites that
                // deliberately run WPC for images/CDN only with caching OFF. When caching is
                // OFF we now leave advanced-cache.php AND WP_CACHE untouched. The gated
                // admin-load re-assert (checkHtaccess, ~3633) restores the drop-in the moment
                // caching is turned back on, so nothing is lost by deferring here.
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

                // (v7.10.04) The stored-version bump MOVED to the very end of this block
                // (after the final step) so the upgrade pass is atomic + self-resuming: the
                // version only advances once a full clean pass completes. Bumping it HERE
                // (mid-block) is what let a fatal in a later step abandon the remaining work
                // AND suppress retry (version already matched → block never re-ran).

                // Verified-gate backfill: the cdn-rewrite emit-gate now requires
                // wpc_cf_cname_verified before emitting the CF cname. A currently-
                // serving zone (cname set + cf.cdn on) is live by definition, so
                // mark it verified on upgrade — else the gate blanks its host on
                // the first post-update pageview. Only backfill a NEVER-SET flag,
                // not an explicit '0' (a mid-change suppress from a cname save);
                // the sentinel default distinguishes the two, so an update during
                // an in-flight cname change can't promote a broken host to verified.
                if (get_option('wpc_cf_cname_verified', '__unset__') === '__unset__') {
                    $wpc_cf_bf  = (defined('WPS_IC_CF')) ? get_option(WPS_IC_CF) : false;
                    $wpc_cfc_bf = (defined('WPS_IC_CF_CNAME')) ? trim((string) get_option(WPS_IC_CF_CNAME)) : '';
                    if ($wpc_cfc_bf !== '' && is_array($wpc_cf_bf) && !empty($wpc_cf_bf['settings']['cdn'])) {
                        update_option('wpc_cf_cname_verified', 1, false);
                    }
                }

                // Fire the orch /v2/config provisioning sync on every update, for
                // every zone — lays the Bunny Edge Rules + AVIF/WebP Vary + re-signs
                // the config blob, unconditionally, so a zone the install/heartbeat
                // never reached gets provisioned. Background cron, never blocks admin.
                // The force-provision flag re-provisions past the heartbeat throttle
                // and persists until a 2xx lands — the can't-be-stranded backstop.
                update_option('wpc_v2_force_provision', 1, false);
                if (function_exists('wpc_v2_schedule_config_sync')) {
                    wpc_v2_schedule_config_sync();
                } elseif (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_v2_deferred_config_sync')) {
                    wp_schedule_single_event(time(), 'wpc_v2_deferred_config_sync');
                }

                // Auto-enable picture_webp for existing users who have WebP enabled
                // (v7.10.04) GUARD: only operate if the stored option is the expected ARRAY
                // shape. A legacy/corrupt install can hold WPS_IC_SETTINGS as a string (or other
                // scalar); the writes below ($migrateSettings['picture_webp'] = '1' etc.) on a
                // string are a PHP-8 string-offset assignment = FATAL. This is the kind of
                // "happens once on upgrade against the real DB" fault that white-screens on the
                // first post-update admin load then never recurs (and can't be reproduced without
                // restoring that exact malformed option). is_array() makes the whole migrate block
                // a no-op on a bad shape instead of fataling. No change for the normal array case.
                $migrateSettings = get_option(WPS_IC_SETTINGS);
                $migrateDirty = false;
                if (is_array($migrateSettings)
                    && !empty($migrateSettings['generate_webp']) && $migrateSettings['generate_webp'] == '1' && !isset($migrateSettings['picture_webp'])) {
                    $migrateSettings['picture_webp'] = '1';
                    $migrateDirty = true;
                }

                // Self-heal: re-couple picture_avif to the single Next-Gen switch.
                // Lite writers set generate_webp=1 without picture_avif/wpc_nextgen,
                // so the ceiling derives 'webp' even though the switch shows ON and
                // defaults ship avif — leaving these installs stuck at the webp
                // ceiling with the front-end AVIF block never running. Add the avif
                // intent, but only when next-gen is on AND the user never explicitly
                // chose a ceiling AND picture_avif is off; a deliberate webp/off
                // choice is left untouched.
                //
                // Safe: this only enables the AVIF *block*. Whether a <source> emits
                // a natural .avif vs the never-404 wp:2 transform is the independent,
                // proof-based per-zone flavor gate — an un-converged zone falls back
                // to the transform, so this heal can't produce a 404ing source.
                // (v7.10.04) GUARD: same array-shape protection as the picture_webp migrate
                // above — the writes below assign string keys on $migrateSettings, which fatal
                // if a legacy/corrupt install holds WPS_IC_SETTINGS as a scalar. is_array() short-
                // circuits to a no-op on a bad shape; identical behavior for the normal array case.
                $ng = is_array($migrateSettings) && isset($migrateSettings['wpc_nextgen']) ? strtolower((string) $migrateSettings['wpc_nextgen']) : '';
                $ngUnchosen = ($ng === '' || $ng === 'auto');
                $gwOn = is_array($migrateSettings) && !empty($migrateSettings['generate_webp']) && (string) $migrateSettings['generate_webp'] === '1';
                $paOn = is_array($migrateSettings) && !empty($migrateSettings['picture_avif']) && (string) $migrateSettings['picture_avif'] === '1';
                if (is_array($migrateSettings) && $gwOn && $ngUnchosen && !$paOn) {
                    $migrateSettings['picture_avif'] = '1';
                    if (empty($migrateSettings['picture_webp'])) {
                        $migrateSettings['picture_webp'] = '1';
                    }
                    $migrateSettings['wpc_nextgen'] = 'auto'; // pin the intent so re-renders + future saves are coherent
                    $migrateDirty = true;
                }

                if ($migrateDirty) {
                    update_option(WPS_IC_SETTINGS, $migrateSettings);
                }

                // Re-assert respect-origin on the CF static-assets rule on update.
                // An old 30d override_origin pins not-yet-landed .avif interims (and
                // wrong-MIME css/js) for 30 days on a vary-blind CF edge. PATCH-only
                // (flips TTL modes, keeps expression + domains); no-op without CF.
                $cfReassert = get_option(WPS_IC_CF);
                if (!empty($cfReassert['token']) && !empty($cfReassert['zone'])) {
                    if (!class_exists('WPC_CloudflareAPI')) {
                        require_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
                    }
                    if (class_exists('WPC_CloudflareAPI')) {
                        $cfReassertSdk = new WPC_CloudflareAPI($cfReassert['token']);
                        $cfReassertSdk->patchStaticAssetsRespectOrigin($cfReassert['zone']);

                        // Flush the CF edge HTML cache so post-update delivery markup regenerates.
                        // HTML-ONLY — we must NEVER purge_everything on a plugin update: that wipes every
                        // edge-cached IMAGE too, and the CDN then re-probes the (often slow) origin for
                        // every image on the next visit → PHP-worker saturation → 40-60s HTML (the v7.02.x
                        // incident root cause). A plugin update only changes delivery MARKUP, so purge just
                        // the HTML entry points; images stay edge-cached. Non-blocking. Filter
                        // wpc_cf_html_purge_urls to add pages (e.g. shop/landing); wpc_purge_cf_on_update
                        // to opt out entirely.
                        if (apply_filters('wpc_purge_cf_on_update', true)
                            && method_exists($cfReassertSdk, 'purgeFilesAsync')) {
                            $wpc_html_purge_urls = apply_filters('wpc_cf_html_purge_urls', [home_url('/')]);
                            $cfReassertSdk->purgeFilesAsync($cfReassert['zone'], (array) $wpc_html_purge_urls);
                        }
                    }
                }

                // (v7.10.04) FINAL STEP — bump the stored version ONLY after the whole pass
                // above ran clean. Doing it last (was mid-block) makes the upgrade atomic +
                // self-resuming: any earlier failure leaves the version behind so the next
                // admin load retries the full (idempotent) pass. No cron involved.
                update_option('wpc_core_version', self::$version);

                // Mark the pass complete so the shutdown trap below stays silent on a clean run.
                $wpc_upgrade_done = true;

                } catch (\Throwable $wpc_upgrade_err) {
                    // Any Error/Exception in the upgrade work lands here — logged, NOT rethrown,
                    // so the admin/settings page renders normally. wpc_core_version stays UNBUMPED
                    // (we never reached the bump above), so the next admin page load re-runs the
                    // whole pass. This is the guard that stops a bad upgrade from white-screening
                    // the settings page the way customers reported.
                    error_log('[WPC Upgrade] CAUGHT upgrade-pass error — wpc_core_version left UNBUMPED, next admin load will retry: '
                        . $wpc_upgrade_err->getMessage() . ' @ ' . $wpc_upgrade_err->getFile() . ':' . $wpc_upgrade_err->getLine());
                }
            }

            // One-time: create CDN bypass rule + whitelist IPs for existing CF connections
            if (empty(get_option('wpc_cf_bypass_v5'))) {
                $cf = get_option(WPS_IC_CF);
                if (!empty($cf['token']) && !empty($cf['zone'])) {
                    require_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
                    $cfsdk = new WPC_CloudflareAPI($cf['token']);
                    $cfsdk->addCdnBypassRule($cf['zone']);
                    $cfsdk->whitelistIPs($cf['zone']);
                }
                // Mark done even on failure — retrying would block every admin load on CF API errors
                update_option('wpc_cf_bypass_v5', '1');
            }

            // One-time: default-on the natural-AVIF picture source for existing
            // installs. setMissingSettings() forces absent keys to '0', so a preset
            // default alone won't enable it on upgrade — set it explicitly, once
            // (a new feature, so no prior user choice to clobber). Safe: this is
            // only the master toggle; avif_natural_source_ok() still gates each
            // rung on the edge witness and falls back to the never-404 transform.
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

    public static function deleteTests()
    {
        // Remove Tests
        delete_transient('wpc_test_running');
        delete_transient('wpc_initial_test');
        delete_option(WPC_WARMUP_LOG_SETTING);
        delete_option('wps_ic_gen_hp_url');
    }

    /***
     * Get file size from WP filesystem
     *
     * @param $imageID
     *
     * @return string
     */
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

    /**
     * Retrieve account information from memory IF it's in memory
     *
     * @param $force
     *
     * @return false|mixed|object
     */
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

                if (!empty($site_url) && $api_url !== $site_url) {
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

                    if (!empty($body->account->suspended)) {
                        if ($body->account->suspended == 1) {
                            $allow_local = false;
                            $allow_live = false;
                        }
                    }

                    // Allow Local
                    $updated_local = update_option('wps_ic_allow_local', $allow_local);
                    $updated_live = update_option('wps_ic_allow_live', $allow_live);

                    // If Local or Live Capabilities Changed, Purge
                    if ($updated_local || $updated_live) {
                        $cache = new wps_ic_cache_integrations();
                        $cache::purgeAll();
                    }

                    // Is account active?
                    if ($account_status != 'active') {
                        $settings['live-cdn'] = '0'; // TODO: Fix
                        update_option(WPS_IC_SETTINGS, $settings);
                    }
                }

                // Account configuration
                if (empty($body->packageConfiguration)) {
                    // Show all options
                }
                else {
                    // Block some options
                    $packageConfig = (array)$body->packageConfiguration;
                    if (!empty($packageConfig)) {
                        foreach ($packageConfig as $key => $value) {
                            set_transient($key . 'Enabled', $value, 5 * 60); // 5 Minutes

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
                                        $settings['inline-css'] = 0;
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
                $options = get_option(WPS_IC_OPTIONS);
                $options['api_key'] = '';
                $options['response_key'] = '';
                $options['orp'] = '';
                $options['regExUrl'] = '';
                $options['regexpDirectories'] = '';
                update_option(WPS_IC_OPTIONS, $options);
                return false;
            }
        } else if (wp_remote_retrieve_response_code($call) == 401) {
            $cache = new wps_ic_cache_integrations();
            $cache->remove_key();
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

                        if (!empty($body->account->suspended)) {
                            if ($body->account->suspended == 1) {
                                $allow_local = false;
                                $allow_live = false;
                            }
                        }

                        // Allow Local
                        $updated_local = update_option('wps_ic_allow_local', $allow_local);
                        $updated_live = update_option('wps_ic_allow_live', $allow_live);

                        // If Local or Live Capabilities Changed, Purge
                        if ($updated_local || $updated_live) {
                            $cache = new wps_ic_cache_integrations();
                            $cache::purgeAll();
                        }

                        // Is account active?
                        if ($account_status != 'active') {
                            $settings['live-cdn'] = '0'; // TODO: Fix
                            update_option(WPS_IC_SETTINGS, $settings);
                        }
                    }
                    // Account configuration
                    if (empty($body->packageConfiguration)) {
                        // Show all options
                    } else {
                        // Block some options
                        $packageConfig = (array)$body->packageConfiguration;
                        if (!empty($packageConfig)) {
                            foreach ($packageConfig as $key => $value) {
                                set_transient($key . 'Enabled', $value, 5 * 60); // 5 Minutes

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
                                            $settings['inline-css'] = 0;
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
            'minify_css'      => !empty($settings['css_minify']),
            'combine_css'     => !empty($settings['css_combine']),
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
        // Reset loopback status so it re-tests on next upload
        delete_option('wpc_loopback_status');

        // Ensure the telemetry table exists. Also runs on plugins_loaded each
        // request (idempotent, version-gated) for upgrades that skip the hook.
        if (class_exists('WPC_Modern_Delivery') && method_exists('WPC_Modern_Delivery', 'maybe_create_emissions_table')) {
            WPC_Modern_Delivery::maybe_create_emissions_table();
        }

        // Fire the orch /v2/config provisioning sync on activation. Registration
        // creates the agencySites row but never provisions — only /v2/config lays
        // the Edge Rules + AVIF/WebP Vary + signed blob, so without this a fresh
        // zone could live un-provisioned forever. Background cron, never blocks.
        // The force-provision flag persists/retries until a 2xx so the zone can't
        // be stranded if the self-loopback is blocked and wp-cron never ticks.
        update_option('wpc_v2_force_provision', 1, false);
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

        // (v7.10.04) Only take over advanced-cache.php + WP_CACHE when WPC page caching is
        // enabled — otherwise (re)activation would clobber another caching plugin's drop-in
        // (e.g. W3TC) on a site that runs WPC for images/CDN only. A fresh install has no
        // settings yet here, so this skips; the admin-load re-assert (checkHtaccess, ~3633),
        // which first writes the recommended defaults, then installs the drop-in on the next
        // admin load if the default has caching on.
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
                // check if site was removed from portal when plugin was not active
                self::check_account_status(true);

                // Setup Default Options
                $options = new wps_ic_options();
                $settings = get_option(WPS_IC_SETTINGS);

                if (!$settings || count($settings) <= 3) {
                    $options->set_defaults();
                }

                $purge_rules = get_option('wps_ic_purge_rules');

                if ($purge_rules === false) {
                    $purge_rules = $options->get_preset('purge_rules');
                    update_option('wps_ic_purge_rules', $purge_rules);
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

            // Add WP_CACHE to wp-config.php
            $htaccess->setWPCache(false);
            $htaccess->removeAdvancedCache();

            // Purge the CF edge before wiping the local cache. After deactivate
            // the plugin stops rewriting, but CF keeps serving its cached optimized
            // HTML against an origin that no longer produces it → broken pages until
            // TTL. Fire the zone purge directly (not via purgeAll, whose integration
            // hooks may already be torn down). Differs from wpc_purgeCF(): no
            // sleep(6) (a clicked Deactivate must not hang the response), and a
            // one-shot guard since deactivation() is wired to two hooks and can run
            // twice per request. No-op without CF.
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

            if (file_exists(WPS_IC_CRITICAL)) {
                $cacheLogic::deleteFolder(WPS_IC_CRITICAL);
            }

            if (file_exists(WPS_IC_COMBINE)) {
                $cacheLogic::deleteFolder(WPS_IC_COMBINE);
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
        // Update Stats
        $lastUpdate = get_transient('wps_icQuotaStatus');
        if (empty($lastUpdate) || !$lastUpdate) {
            $settings = get_option(WPS_IC_OPTIONS);
            if (!empty($settings['api_key'])) {
                // Check Quota Status
                $call = wp_remote_get(WPS_IC_KEYSURL . '?action=get_account_status_v6&apikey=' . $settings['api_key'] . '&range=month&hash=' . md5(mt_rand(999, 9999)), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

                // Set transient only if the response is 200 for stats update
                if (wp_remote_retrieve_response_code($call) == 200) {
                    set_transient('wps_icQuotaStatus', 'true', 60 * 30);
                }
            }
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
        wp_enqueue_script('utils'); // for user settings
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
        if (get_option('wps_ic_url_changed')){
            add_action('admin_notices', function () {
                $class   = 'notice notice-error';
                $reconnect_url = admin_url('options-general.php?page=' . self::$slug);

                $message = sprintf(
                        '<strong>Error!</strong> Seems like your URL changed, please reconnect with a new apikey. <a href="%s">Reconnect</a>',
                        esc_url($reconnect_url)
                );

                printf(
                        '<div class="%1$s"><p>%2$s</p></div>',
                        esc_attr($class),
                        $message
                );
            });
        }

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
            //to hook on_upload in block editor
            self::$local = new wps_local_compress();
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

        /***
         * Local Remote Hooks
         * TODO: Make Pretty
         */


        if (!empty($_GET['wpc_optimization_done']) && sanitize_text_field($_GET['apikey']) == self::$options['api_key']) {
            //todo set it to done and scheck in js
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

                    // PSI-MC v1.47+: structured audit insights extracted server-side.
                    // Stored locally for admin UI + customer-visible stats. Service backend
                    // already has the underlying data from running the test, so no plugin→backend
                    // forwarding needed.
                    //
                    // Compat gates (per service team contract 2026-04-17):
                    //   1. Plugin consumes schema_version === 1 only. Future v2+ shapes ignored
                    //      until plugin gets explicit support (prevents silent corruption if
                    //      service bumps to v2 before plugin updated).
                    //   2. error === false required. If service couldn't extract (missing audits,
                    //      PSI timeout, etc.), skip save to keep any previous good data intact.
                    //   3. Graceful fallback: pre-v1.47 responses have no insights field, same
                    //      condition evaluates false and plugin continues unchanged.
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


        //hide plugin if it's whitelabel
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

        // Sync live-cdn from actual state: CF CDN or any CDN file type on
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
            $uuid = sanitize_text_field($_GET['uuid']);
            $apikey = sanitize_text_field($_GET['apikey']);

            if (!empty($uuid) && !empty($apikey)) {
                $options = get_option(WPS_IC_OPTIONS);
                $dbApiKey = $options['api_key'];

                if ($dbApiKey == $apikey) {

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
                    $mobileCriticalCSS = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/' . $uuid . '-mobile.css';

                    // Desktop CSS
                    $desktopCriticalCSS = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/' . $uuid . '-desktop.css';

                    if (!class_exists('wps_criticalCss')) {
                        include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
                    }

                    $criticalCSS = new wps_criticalCss();
                    // (v7.03.86) lcp_url rides the criticalDone callback too now (crit-push v3.25.10, gated to
                    // LCP-enabled domains) — read it from $_GET and pass it so saveCriticalCss stashes it for the
                    // render-side healer. This covers the PULL path (the callback fires here); the SMART/push
                    // path is covered by the /status poll (.85). Empty on non-LCP domains → no stash → inert.
                    $wpc_cb_lcp_url = !empty($_GET['lcp_url']) ? sanitize_url(urldecode($_GET['lcp_url'])) : '';
                    $jobStatus[] = $criticalCSS->saveCriticalCss($urlKey, ['url' => ['desktop' => $desktopCriticalCSS, 'mobile' => $mobileCriticalCSS], 'lcp_url' => $wpc_cb_lcp_url, 'lcp_src' => 'callback']);

                    // Check if LCP Exists
                    $mobileLCP = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/lcp-' . $uuid . '-mobile';
                    $desktopLCP = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/lcp-' . $uuid . '-desktop';

                    $jobStatus[] = $criticalCSS->saveLCP($urlKey, ['url' => ['desktop' => $desktopLCP, 'mobile' => $mobileLCP]]);

                    wp_send_json_success($jobStatus);
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
            file_put_contents($log_file, $log);
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
        $page_builders = ['run_compress', //wpc
                'run_restore', //wpc
                'bwc', //bwc
                'elementor-preview', //elementor
                'fl_builder', //beaver builder
                'et_fb', //divi
                'preview', //WP Preview
                'builder', //builder
                'brizy', //brizy
                'fb-edit', //avada
                'bricks', //bricks
                'ct_template', //ct_template
                'ct_builder', //ct_builder
                'cs-render', //cs-render
                'tatsu', //tatsu
                'trp-edit-translation', //thrive
                'brizy-edit-iframe', //brizy
                'ct_builder', //oxygen
                'livecomposer_editor', //livecomposer
                'tatsu', //tatsu
                'tatsu-header', //tatsu-header
                'tatsu-footer', //tatsu-footer
                'tve',//thrive
                'is-editor-iframe',//thrive
                'pagelayer-live'];

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return true;
        }

        if (!empty($_POST['_cs_nonce'])) { //cornerstone
            return false;
        }

        if (!empty($_GET['page']) && sanitize_text_field($_GET['page']) == 'bwc') {
            return false;
        }

        if ((!empty($_GET['action']) && $_GET['action'] == 'in-front-editor')) {
            //brizyFrontend fix
            return true;
        }

        if ((!empty($_GET['action']) && sanitize_text_field($_GET['action']) == 'edit#op-builder') || !empty($_GET['op3editor'])) {
            //optimizePress builder fix
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

        if (empty($settings) || count($settings) <= 3) {
            $settings = [];
        }

        foreach ($defaultSettings as $option_key => $option_value) {
            if (is_array($option_value)) {
                foreach ($option_value as $option_value_k => $option_value_v) {
                    if (!isset($settings[$option_key][$option_value_k])) {
                        if (!isset($settings[$option_key])) {
                            $settings[$option_key] = [];
                        }
                        $settings[$option_key][$option_value_k] = $option_value_v;
                        $foundMissing = true;
                    }
                }
            } else {
                if (!isset($settings[$option_key])) {
                    $settings[$option_key] = $option_value;
                    $foundMissing = true;
                }
            }
        }

        if ($foundMissing) {
            update_option(WPS_IC_SETTINGS, $settings);
        }

        return $settings;
    }

    /***
     * In Admin Area
     */
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

        // Force Disable Elementor Cache
        $elementCache = get_option('elementor_element_cache_ttl');
        if (!empty($elementCache)) {
            if (!empty($elementCache) && $elementCache !== 'disable') {
                update_option('elementor_element_cache_ttl', false);
            }
        }

        // Connectivity probe removed (v7.03.31): simpleConnectivityTest() blocked the admin render up to
        // 30s on first link to set wpc-connectivity-status. That verdict is now orphaned — critical CSS
        // moved to crit-push.zapwp.net (which self-checks reachability from its own vantage) and nothing
        // in warmup/bulk reads it. Do NOT re-add a blocking reachability probe on the render path here.


        if (current_user_can('manage_wpc_settings') && !empty($this::$options['api_key'])) {
            if (!class_exists('wps_ic_htaccess')) {
                include_once WPS_IC_DIR . 'classes/htaccess.class.php';
            }

            // Htaccess
            $htaccess = new wps_ic_htaccess();
            // Integrations
            $this->integrations->init();
        }


        //check if zone name needs fixing
        if (!empty($this::$options['api_key']) && empty($this::$zone_name) && get_option('wps_ic_allow_live') !== false) {
            $url = 'https://apiv3.wpcompress.com/api/site/credits';
            $call = wp_remote_get($url, ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $this::$options['api_key'], 'plugin-version' => self::$version]]);

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

            // On CDN sites the edge owns format negotiation, so an origin
            // .htaccess webp rewrite would double-negotiate (and silently re-add
            // the block each admin load). Emit the origin rule only on non-CDN
            // sites; strip it when live CDN is on (mirrors the settings-save writer).
            $wpc_livecdn = !empty(self::$settings['live-cdn']) && self::$settings['live-cdn'] == '1';
            if (!$wpc_livecdn && !empty(self::$settings['generate_webp']) && self::$settings['generate_webp'] == '1') {
                $htacces->addWebpReplace(); // SHould be addWebP
            } else {
                $htacces->removeWebpReplace();
            }

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

        $this->cache = new wps_ic_cache_integrations();
        $this->cacheLogic = new wps_ic_cache();
        $this->ajax = new wps_ic_ajax();
        $this->menu = new wps_ic_menu();

        if (!class_exists('wps_ic_log')) {
            include_once WPS_IC_DIR . 'classes/log.class.php';
        }

        $this->log = new wps_ic_log();

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
                    wp_safe_redirect(admin_url('admin.php?page=' . self::$slug));
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

            // (v7.10.08) Thread a PLUGIN-generated uuid end-to-end so the fire-and-forget result is
            // POLLABLE. The old dispatch sent a throwaway `hash` the server ignored (it self-assigned an
            // id + the worker overwrote it with its own uuidv4), so get-results was keyed on an id nothing
            // outside the worker could ever learn — the plugin waited on a push callback that doesn't exist
            // → the card hung forever (activeJobs:0). pagespeed-mc v1.49.0 accepts this client uuid|hash and
            // threads it to the file name. We stash it so the first-run self-heal can pull
            // get-results/{uuid} status-first (symmetric with crit's /status?uuid=). Backward-compatible:
            // an older server keys on its own id, get-results 404s, and the self-heal re-dispatches.
            $psiUuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(8));
            set_transient('wpc_psi_uuid', $psiUuid, 30 * 60);

            // Test
            $args = ['url' => home_url(), 'version' => self::$version, 'plugin_version' => self::$version, 'uuid' => $psiUuid, 'hash' => $psiUuid, 'apikey' => $apikey];
            $args['features'] = self::getActiveFeatures();
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

        $local = new wps_local_compress();
        $local->routes();

        /**
         * Integrations
         */
        $this->integrations->apply_frontend_filters();

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
            $criticalCSS = new wps_criticalCss();
            $criticalCSS->sendCriticalUrl('', 0);
            wp_send_json_success();
        }

        /**
         * Run Preloader API
         * - Our API calls url with this GET parameter so that it runs critical generating
         */
        if (!empty($_GET['apiPreload'])) {
            $criticalCSS = new wps_criticalCss();
            $criticalCSS->sendCriticalUrl('', 0);
            wp_send_json_success();
        }

        $this->ajax = new wps_ic_ajax();

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

            // Per-page Force On / Off / Global for the JavaScript row in Smart
            // Optimization writes to $page_excludes['delay_js']. The active V2 placeholder
            // gate at enqueues.class.php:181 reads self::$settings['delay-js-v2'], so the
            // override has to drive THAT key (not the dead V1 'delay-js' setting). Backward
            // compat fallback handles any pre-7.01.13 installs that saved under the legacy
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

        //enqueue inherits these settings
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
            wp_dequeue_style('dashicons');
            wp_deregister_style('dashicons');
        }
    }

    /**
     * Remove Gutenberg CSS Block
     * @return void
     */
    public function disableGutenberg()
    {
        // blocks
        wp_deregister_style('wp-block-library');
        wp_dequeue_style('wp-block-library');
        wp_deregister_style('wp-block-library-theme');
        wp_dequeue_style('wp-block-library-theme');

        // theme.json
        wp_deregister_style('global-styles');
        wp_dequeue_style('global-styles');

        // svg
        remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
        remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
    }

    public function maxImageWidth()
    {
        if (empty(self::$settings['max-original-width'])) {
            return 2560;
        }

        return self::$settings['max-original-width']; // new threshold
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


// Bootstrap-skip gate for /wpc/v2/bg_swap REST callbacks. Everything the handler
// needs is already loaded above (helpers, the REST route, the autoloader, the
// wps_ic class def). Below this point we'd instantiate wps_ic + the CDN rewriter
// + 40+ hooks, none of which the bg_swap callback uses — it runs purely from the
// REST route and WP core. Returning here saves the ~5-7s of that bootstrap.
// Universal-host safe; no behavioral change for any other request type.
if (defined('WPC_IS_BG_SWAP') && WPC_IS_BG_SWAP) {
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
add_filter('upgrader_post_install', ['wps_ic_cache', 'updateCSSHash'], 1);
add_filter('upgrader_post_install', [$wpsIc, 'deleteTests'], 1);

// Upgrader - On Complete
add_action('upgrader_process_complete', ['wps_ic_cache', 'updateCSSHash'], 1);
add_action('upgrader_process_complete', ['wps_ic_cache', 'purgeCDNUpdate'], 1);

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
add_action('plugins_loaded', [$wpsIc, 'checkPluginVersion'], PHP_INT_MAX);
add_action('plugins_loaded', 'wpcCheckCredits', PHP_INT_MAX);

// WP Core Hooks
register_activation_hook(WPC_CC_PLUGIN_FILE, [$wpsIc, 'activation']);
register_deactivation_hook(WPC_CC_PLUGIN_FILE, [$wpsIc, 'deactivation']);
register_uninstall_hook(WPC_CC_PLUGIN_FILE, 'wpcUninstall');

// Register API Hooks
add_action('rest_api_init', function () {
    // Rest API
    $local = new wps_local_compress();
    $local->registerEndpoints();
});

// Re-test loopback whenever plugin settings change
add_action('update_option_' . WPS_IC_SETTINGS, function () {
    delete_option('wpc_loopback_status');
    // testLoopback() caches via a 1h transient to avoid a blocking re-POST on
    // every admin pageview; clear it on a save so the re-test runs fresh (the
    // hourly cap only suppresses unrelated pageviews).
    delete_transient('wpc_loopback_test_at');
});

// Test loopback once on admin load (non-blocking, cached after first run)
add_action('admin_init', function () {
    // testLoopback() is a blocking ~5s POST. Never run it on admin-ajax — it
    // would stall the post-save round-trip; it runs on the next regular page load.
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    if (get_option('wpc_loopback_status', '') !== '') return;
    $local = new wps_local_compress();
    $local->testLoopback();
}, 99);

// One-time reconcile of the legacy per-format image serve-keys to the single
// "Images" tile. Old installs could persist a split state (jpg=1, png=0); the
// tile binds to serve[jpg] but the rewriters read each format key separately, so
// a split would show the tile ON while other formats serve from origin. Collapse
// all four to one value (ON if any was on). Idempotent; self-disables via the flag.
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

// v7.02.48 — Auto-enable CloudFlare's FREE Tiered Cache on the connected zone (once per site).
// Tiered Cache serves every CF edge POP from a warm regional upper-tier instead of hitting the origin
// per-POP, so the homepage origin warm (.47) propagates to the whole edge off ONE origin fetch. The CF
// PATCH is idempotent ('on' when already on = no-op); runs once (flag), backs off 6h on failure, and is
// opt-out via the wpc_cf_tiered_cache filter. Only touches zones the customer already connected to WPC.
add_action('admin_init', function () {
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    if (get_option('wpc_cf_tiered_cache_done')) return;
    if (!apply_filters('wpc_cf_tiered_cache', true)) return;
    if (get_transient('wpc_cf_tiered_cache_retry')) return; // failure backoff — don't hammer the CF API
    $cf = get_option(WPS_IC_CF);
    if (empty($cf['zone']) || empty($cf['token'])) return; // CF not connected → nothing to enable
    if (!defined('WPS_IC_DIR') || !file_exists(WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php')) return;
    require_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php';
    if (!class_exists('WPC_CloudflareAPI')) return;
    try {
        $api = new WPC_CloudflareAPI($cf['token']);
        // The SDK already has setTieredCache($zoneId, $enabled) — but its only caller
        // (updateWPCCacheConfig) is dead code, so Tiered Cache never actually got turned on. This
        // admin_init pass is what makes it real for connected zones (idempotent CF PATCH).
        $res = $api->setTieredCache($cf['zone'], true);
        if (!is_wp_error($res) && !empty($res)) {
            update_option('wpc_cf_tiered_cache_done', time(), false); // success → run once
        } else {
            set_transient('wpc_cf_tiered_cache_retry', 1, 6 * HOUR_IN_SECONDS);
        }
    } catch (\Throwable $e) {
        set_transient('wpc_cf_tiered_cache_retry', 1, 6 * HOUR_IN_SECONDS);
    }
}, 97);

// v7.02.51 — "Source Hints" toggle (Other Optimizations → Media & Assets), binary to match the grid.
// settings['emit-src-hints'] = '1' (ON) | '0' (OFF) | unset (default → the baked-ON baseline). It rides
// the wpc_src_hint_enabled filter, which src_hint_enabled() applies LAST — after the per-zone orch mirror
// and the baked-ON global baseline — so an explicit ON/OFF beats the orch; unset = no override.
add_filter('wpc_src_hint_enabled', function ($on) {
    $s = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : null;
    if (is_array($s) && isset($s['emit-src-hints'])) {
        $v = (string) $s['emit-src-hints'];
        if ($v === '1') return true;   // toggle ON  → emit hints
        if ($v === '0') return false;  // toggle OFF → escape hatch (beats the orch)
    }
    return $on; // unset → baked-on baseline / orch decision
}, 20);


// ─── Backup cleanup: delete files older than 30 days ─────────────
// TODO: Enable when backup cleanup is a toggle in plugin settings
/*
add_action('wpc_cleanup_backups', 'wpc_do_cleanup_backups');
if (!wp_next_scheduled('wpc_cleanup_backups')) {
    wp_schedule_event(time(), 'daily', 'wpc_cleanup_backups');
}
*/

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

function wpcGetHeader($headerName)
{
    $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($headerName));
    return $_SERVER[$headerKey] ?? null;
}

function wpcCheckCredits()
{

    $transient_key = 'wps_ic_credits_check';
    if (get_transient($transient_key)) {
        return;
    }

    $options = get_option(WPS_IC_OPTIONS);

    if (empty($options) || empty($options['api_key'])) {
        return;
    }

    $url = 'https://apiv3.wpcompress.com/api/site/credits';
    $call = wp_remote_get($url, ['timeout' => 5, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $options['api_key'], 'plugin-version' => wps_ic::$version]]);

    if (is_wp_error($call)) {
        // Short cooldown so a sick API isn't re-hit on every request
        set_transient($transient_key, true, MINUTE_IN_SECONDS);
        return;
    }

    $body = wp_remote_retrieve_body($call);
    $response_code = wp_remote_retrieve_response_code($call);

    if ($response_code !== 200) {
        // Short cooldown so a sick API isn't re-hit on every request
        set_transient($transient_key, true, MINUTE_IN_SECONDS);
        return;
    }

    $data = json_decode($body);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return;
    }

    $allow_local = true;
    $allow_live = true;

    if (!empty($data->suspended) && $data->suspended == 1) {
        $allow_local = false;
        $allow_live = false;
    }

    $updated_local = update_option('wps_ic_allow_local', $allow_local);
    $updated_live = update_option('wps_ic_allow_live', $allow_live);

    // If Local or Live Capabilities Changed, Purge
    if ($updated_local || $updated_live) {
        if (class_exists('wps_ic_cache_integrations')) {
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll();
        }
    }

    set_transient($transient_key, true, 43200);
}

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
        //request from WL
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
/**
 * Favicon micro-optimization — core's /favicon.ico is an uncached 302 to the
 * w-logo png (two requests per session). Only fires when WP handles the favicon
 * (a physical favicon.ico is served by the webserver and never reaches here):
 *   - Site Icon set: 301 (cacheable a day) instead of core's 302;
 *   - no Site Icon:  stream the default w-logo png directly with a 1-year cache.
 * Disable via the wpc_favicon_optimize filter.
 */
add_action('do_faviconico', function () {
    if (!apply_filters('wpc_favicon_optimize', true) || headers_sent()) {
        return; // fall through to core's default redirect
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
 * Universal LCP sizes floor. With CDN + Next-Gen off no plugin pass runs, so
 * core's "100vw, {W}px" default makes the hero over-fetch. Hooking
 * wp_calculate_image_sizes (WP's own content-image sizing) is the mode-
 * independent floor: plain mode gets the ladder, path-A sources inherit it, and
 * the nd/legacy emitters still override downstream. Scope: front-end,
 * optimize-lcp on, the first wide (≥1200w) image per request only.
 */
add_filter('wp_calculate_image_sizes', function ($sizes, $size, $image_src, $image_meta, $attachment_id) {
    static $done = false;
    if ($done || is_admin()) return $sizes;
    $s = get_option(WPS_IC_SETTINGS);
    if (!is_array($s) || empty($s['optimize-lcp'])) return $sizes;
    $w = 0;
    if (is_array($size) && !empty($size[0])) $w = (int) $size[0];
    if ($w <= 0 && is_array($image_meta) && !empty($image_meta['width'])) $w = (int) $image_meta['width'];
    if ($w < 1200) return $sizes; // small slot: core's width-hint form is already right
    $done = true;
    $maxW = !empty($s['maxWidth']) ? (int) $s['maxWidth'] : 2560;
    $cw   = function_exists('wpc_get_theme_content_width') ? (int) wpc_get_theme_content_width() : 0;
    $cap  = $cw > 0 ? $cw : min(1200, max(400, $maxW));
    $ladder = '(max-width: 600px) 50vw, (max-width: 1024px) 40vw, ' . $cap . 'px';
    return (string) apply_filters('wpc_picture_lcp_sizes', $ladder, ['width' => $w], $s);
}, 20, 5);

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
            if (class_exists('wps_ic_cache')) {
                try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
            }
            do_action('breeze_clear_all_cache');
            if (function_exists('error_log')) {
                error_log('[WPC FormatToggle] ' . $k . ' changed — page cache purged (format-fill can scan fresh renders)');
            }
            break;
        }
    }
}, 10, 2);

/**
 * (v7.10.06) Keep the drop-in's baked exclude constants (WPC_URL_EXCLUDES / WPC_CACHE_EXCLUDES) in
 * sync with the options. The zero-DB hit path in advanced-cache.php reads those constants, so a stale
 * bake would silently honour an out-of-date exclude list. Re-template on ANY change to either option —
 * this catches the save paths (per-page settings, import, recovery) that don't call setAdvancedCache
 * directly. No-ops unless page caching is on and the drop-in exists (setAdvancedCache guards both), and
 * only writes when the rendered content actually changed.
 */
$wpc_rebake_dropin_excludes = function () {
    $s = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
    if (empty($s['cache']['advanced']) || $s['cache']['advanced'] != '1') {
        return;
    }
    // COMPATIBILITY: only ever re-bake OUR OWN drop-in. If advanced-cache.php is absent (activation
    // installs it) or belongs to another cache plugin (WP Rocket / W3TC / LiteSpeed / WP Super Cache —
    // they don't carry our WP_COMPRESS_ADVANCED_CACHE marker), do NOT touch it. Prevents clobbering a
    // co-installed cache plugin's drop-in when WPC caching was left on.
    $wpc_dropin = ABSPATH . 'wp-content/advanced-cache.php';
    if (!file_exists($wpc_dropin)) {
        return;
    }
    $wpc_dropin_head = @file_get_contents($wpc_dropin, false, null, 0, 256);
    if ($wpc_dropin_head === false || strpos($wpc_dropin_head, 'WP_COMPRESS_ADVANCED_CACHE') === false) {
        return; // not our drop-in — leave it alone
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

/**
 * (v7.10.07) FIRST-RUN SELF-HEAL — a fresh site's Critical CSS + PageSpeed can never stay stuck.
 *
 * A freshly-provisioned site's initial crit run rides ONE crit-push /generate round-trip (which also
 * populates the PageSpeed card, criticalCss-v2.php:535). The render-time trigger is disabled, so that
 * first run depends on the dashboard AJAX firing AND returning — if it's dropped (dispatched-but-lost,
 * or completed-but-callback-lost), crit never generates and "Analyzing…" spins forever. This runs on
 * admin load and, when the site is provisioned but the home has no crit, (re)dispatches the run —
 * bounded, non-blocking, and self-healing:
 *   - re-dispatches a STUCK run once it passes the round-trip timeout (default 180s), so a lost run heals;
 *   - re-dispatch goes through the plugin's real initCritical, which polls /status for the in-flight uuid
 *     — so it also recovers a completed-but-UNRETURNED run, not just a never-dispatched one;
 *   - after N fast attempts (default 5) it sets wpc_first_run_failed so the UI can show a retry/error
 *     instead of an infinite spinner, but KEEPS retrying hourly so a transient crit-push outage recovers
 *     on its own (never a permanent stuck state);
 *   - clears its state the instant crit lands.
 * The dispatch runs on shutdown AFTER the response has flushed (fastcgi_finish_request), so it adds zero
 * latency to the admin page. On a healthy site it's a single file_exists per admin load (early return).
 * Tunables: filters wpc_first_run_timeout_seconds / wpc_first_run_max_attempts.
 */
if (!function_exists('wpc_first_run_home_crit_exists')) {
    function wpc_first_run_home_crit_exists()
    {
        if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
            return true; // can't tell → treat as present (fail-safe: never hammer)
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
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request(); // flush the response first — the admin page is never blocked
        }
        if (!class_exists('wps_criticalCss')) {
            @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
        }
        if (class_exists('wps_criticalCss')) {
            try {
                $c = new wps_criticalCss();
                $c->generateCriticalCSS('home', true); // real round-trip; /status poll recovers a lost callback
            } catch (\Throwable $e) {}
        }
    }
}
add_action('admin_init', function () {
    $opts = function_exists('get_option') ? get_option(WPS_IC_OPTIONS) : [];
    if (empty($opts['api_key'])) {
        return; // not provisioned yet — nothing to dispatch
    }
    if (wpc_first_run_home_crit_exists()) {
        if (get_option('wpc_first_run_attempts') !== false) {
            delete_option('wpc_first_run_dispatched_at');
            delete_option('wpc_first_run_attempts');
            delete_option('wpc_first_run_failed');
        }
        return; // crit is there — done
    }
    $dispatchedAt = (int) get_option('wpc_first_run_dispatched_at');
    $attempts     = (int) get_option('wpc_first_run_attempts');
    $timeout      = (int) apply_filters('wpc_first_run_timeout_seconds', 180);
    $maxAttempts  = (int) apply_filters('wpc_first_run_max_attempts', 5);
    // Fast retries while under the cap; then an hourly backstop — never permanently give up, never hammer.
    $interval = ($attempts < $maxAttempts) ? $timeout : 3600;
    if ($dispatchedAt > 0 && (time() - $dispatchedAt) < $interval) {
        return; // a dispatch is in flight / backing off — let it be
    }
    if ($attempts >= $maxAttempts && !get_option('wpc_first_run_failed')) {
        update_option('wpc_first_run_failed', 1, false); // UI: surface a retry/error, not a spinner
    }
    update_option('wpc_first_run_dispatched_at', time(), false);
    update_option('wpc_first_run_attempts', $attempts + 1, false);
    register_shutdown_function('wpc_first_run_dispatch_now');
}, 20);

/**
 * (v7.10.08) PSI first-run self-heal — the PageSpeed card twin of the crit self-heal above.
 * PSI is a SEPARATE run-pagespeed dispatch + get-results poll (NOT part of the crit round-trip — verified
 * with the pagespeed team). There is no push callback, and pagespeed-mc v1.49.0 keys the benchmark on the
 * plugin-supplied uuid (stashed as wpc_psi_uuid at dispatch). So recovery is PULL: poll get-results/{uuid}
 * status-first; if no uuid is stashed (never dispatched / expired), dispatch a fresh run with a new uuid.
 * Bounded (5 fast, then hourly), non-blocking (shutdown), no-op once the card is populated.
 */
if (!function_exists('wpc_first_run_psi_now')) {
    function wpc_first_run_psi_now()
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
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
