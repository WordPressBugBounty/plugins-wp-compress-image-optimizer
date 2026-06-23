<?php
/**
 * WP Compress — HTML cache invalidation on variant landing.
 *
 * Why this exists
 * ===============
 * When an AVIF/WebP variant lands on disk (via Phase B callback or
 * pull-manifest direct-entry), any page HTML that references the
 * attachment is stale: picture sources emit CDN transform URLs (wp:1/wp:2)
 * for variants that didn't exist when the HTML rendered. The browser
 * downloads via CDN transform forever even though the natural URL is now
 * 200-OK on disk.
 *
 * This module hooks the variant-landing event and invalidates HTML caches
 * for affected posts, so the NEXT visitor gets a fresh render with natural
 * URLs in picture sources.
 *
 * Cascade design
 * ==============
 * Reuses the plugin's existing wps_ic_cache::removeHtmlCacheFiles($post_id),
 * which is the single authoritative purge entry point already wired to:
 *   - WPC page cache file removal (wps_cacheHtml::removeCacheFiles)
 *   - Direct Varnish PURGE (wps_ic_cache_integrations::purgeVarnish, honors
 *     wps_ic_varnish_ips and wps_ic_varnish_purge_* filters)
 *   - do_action('wps_ic_purge_all_cache', $permalink), to which Breeze,
 *     LiteSpeed, SG Optimizer, and similar integrators are subscribed
 * Plus the customer hook wpc_variant_landed_purge_html for further extension.
 *
 * Throttling: 10s cooldown per attachment, so a burst of 24 variants
 * (typical full encode) coalesces into 1-3 purges instead of 24. Tuneable
 * via wpc_v2_html_purge_throttle_seconds filter.
 *
 * Discovery: post_ids that reference the attachment are found via
 *   - post_content LIKE '%wp-image-N%' (img embeds, Gutenberg blocks)
 *   - _thumbnail_id postmeta = N (featured images)
 *   - wpc_v2_referencing_posts filter (extension point for ACF, page
 *     builders, custom CPTs, etc.)
 *
 * Cached for 5 minutes per attachment to avoid LIKE-query thrash on large
 * sites. Acceptable staleness: if a post stops embedding the image, we'll
 * purge it one extra time. No correctness impact.
 *
 * Revert: remove the require_once line in v2-bootstrap.php and the two
 * wire calls in v2-callback.php (line 646) and v2-direct-entry.php
 * (shutdown handler). Or set filter wpc_v2_html_purge_enabled to false.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_should_purge_html')) {
    /**
     * Kill-switch / gating. Default: enabled when picture_avif or
     * picture_webp is on (the picture sources benefit from cache purge);
     * disabled otherwise (no picture HTML → no stale URLs).
     */
    function wpc_v2_should_purge_html($image_id)
    {
        $image_id = (int) $image_id;
        if ($image_id <= 0) return false;

        $settings = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        $picture_active = (!empty($settings['picture_avif']) && $settings['picture_avif'] == '1')
                       || (!empty($settings['picture_webp']) && $settings['picture_webp'] == '1');

        // When picture is off, the stale-HTML problem doesn't apply.
        if (!$picture_active) return false;

        return (bool) apply_filters('wpc_v2_html_purge_enabled', true, $image_id);
    }
}

if (!function_exists('wpc_v2_discover_referencing_posts')) {
    /**
     * Find post_ids that embed an attachment via:
     *   - post_content containing class="wp-image-{N}" (WP standard convention)
     *   - _thumbnail_id postmeta == N (featured images)
     *   - wpc_v2_referencing_posts filter (extension point)
     *
     * Capped at 200 per source for safety on huge sites.
     * Cached 5 minutes per attachment via transient.
     */
    function wpc_v2_discover_referencing_posts($image_id)
    {
        $image_id = (int) $image_id;
        if ($image_id <= 0) return [];

        $cache_key = 'wpc_html_purge_posts_' . $image_id;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        global $wpdb;
        $post_ids = [];

        // 1. post_content reference. The wp-image-{N} class is on every
        // <img> that WP generates via wp_get_attachment_image() / Gutenberg
        // image block / classic editor. Safe LIKE pattern (esc_like + prepare).
        $like = '%' . $wpdb->esc_like('wp-image-' . $image_id) . '%';
        $content_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
              WHERE post_status = 'publish'
                AND post_type NOT IN ('revision','nav_menu_item','attachment')
                AND post_content LIKE %s
              LIMIT 200",
            $like
        ));
        if (!empty($content_ids)) {
            foreach ($content_ids as $id) {
                $post_ids[(int) $id] = true;
            }
        }

        // 2. Featured-image meta. Single integer compare — fast.
        $thumb_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
              WHERE meta_key = '_thumbnail_id'
                AND meta_value = %d
              LIMIT 200",
            $image_id
        ));
        if (!empty($thumb_ids)) {
            foreach ($thumb_ids as $id) {
                $post_ids[(int) $id] = true;
            }
        }

        $post_ids = array_keys($post_ids);

        // Extension point for ACF image fields, custom post builders,
        // theme-emitted markup that doesn't use the wp-image-N class, etc.
        $post_ids = apply_filters('wpc_v2_referencing_posts', $post_ids, $image_id);

        // Final sanitize.
        $post_ids = array_values(array_unique(array_filter(array_map('intval', (array) $post_ids))));

        set_transient($cache_key, $post_ids, 300);
        return $post_ids;
    }
}

if (!function_exists('wpc_v2_fire_clean_post_cache_cascade')) {
    /**
     * Per-post cache invalidation cascade. Each step is independent and
     * silently no-ops if the target plugin isn't installed.
     */
    function wpc_v2_fire_clean_post_cache_cascade($post_id)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return;

        // The existing plugin's purge entry point handles the entire stack:
        //   - WPC's own page cache files removed via wps_cacheHtml::removeCacheFiles($post_id)
        //   - Varnish PURGE request fired via wps_ic_cache_integrations::purgeVarnish($post_id)
        //     (respects wps_ic_varnish_ips / wps_ic_varnish_purge_* filters)
        //   - do_action('wps_ic_purge_all_cache', $permalink) which Breeze,
        //     LiteSpeed, SG Optimizer, and other integrators listen to
        //   - Internal once-per-request guard so concurrent landings for the
        //     same image don't double-purge inside one PHP request
        //
        // Reuse it directly — no parallel cascade to drift out of sync.
        if (class_exists('wps_ic_cache')
            && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            try {
                wps_ic_cache::removeHtmlCacheFiles($post_id);
                return;
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[WPC HtmlPurge] wps_ic_cache::removeHtmlCacheFiles failed post_id=%d msg=%s',
                    $post_id, $e->getMessage()
                ));
                // Fall through to minimal fallback below.
            }
        }

        // Fallback for stripped-down installs where wps_ic_cache isn't
        // present (white-label builds that exclude it). Minimum viable
        // invalidation: WP core post cache + the public purge action that
        // most third-party purgers subscribe to.
        if (function_exists('clean_post_cache')) {
            clean_post_cache($post_id);
        }
        do_action('wps_ic_purge_all_cache', get_permalink($post_id));
    }
}

if (!function_exists('wpc_v2_purge_html_for_attachment')) {
    /**
     * Main entry. Throttled per-attachment to coalesce variant bursts.
     *
     * @param int    $image_id
     * @param string $source   Telemetry label (callback|direct-entry|test|...)
     * @return bool|null  true=purged, false=throttled-or-no-posts, null=disabled
     */
    function wpc_v2_purge_html_for_attachment($image_id, $source = 'unknown')
    {
        $image_id = (int) $image_id;
        if (!wpc_v2_should_purge_html($image_id)) {
            return null;
        }

        // Defer purges during bulk runs. On a 1000-image bulk
        // restore/optimize, firing per-image purges = 1000 Varnish PURGE
        // requests + 1000 LIKE-queries on wp_posts. The bulk-finish hook
        // fires ONE global purge at the end. Tracked attachments deferred
        // here are dropped; the global purge clears everything.
        $bulk = get_option('wps_ic_bulk_process');
        if (is_array($bulk) && !empty($bulk['status']) && in_array($bulk['status'], ['restoring', 'optimizing', 'running'], true)) {
            // Mark that a bulk-deferred purge is pending so the end-of-bulk
            // hook knows to fire (option, not transient, so it survives if
            // request crashes mid-bulk).
            update_option('wpc_v2_html_purge_pending_bulk', 1, false);
            return false;
        }

        // Throttle: coalesce a burst of variant landings into a small
        // number of purges. Typical: 24 variants land within ~30s → 1-3
        // purges instead of 24. Tunable.
        $throttle_seconds = (int) apply_filters('wpc_v2_html_purge_throttle_seconds', 10, $image_id);
        if ($throttle_seconds < 1) $throttle_seconds = 1;

        $throttle_key = 'wpc_html_purge_throttle_' . $image_id;
        if (get_transient($throttle_key)) {
            return false;
        }
        // Set throttle BEFORE doing work, so concurrent callbacks race-safe.
        set_transient($throttle_key, 1, $throttle_seconds);

        $post_ids = wpc_v2_discover_referencing_posts($image_id);
        if (empty($post_ids)) {
            // Attachment not embedded anywhere we can find. Nothing to purge.
            // (Theme/widget references aren't discoverable; filter
            // wpc_v2_referencing_posts can extend.)
            error_log(sprintf(
                '[WPC HtmlPurge] source=%s image_id=%d no_referencing_posts',
                (string) $source, $image_id
            ));
            return false;
        }

        $purged = 0;
        foreach ($post_ids as $post_id) {
            try {
                wpc_v2_fire_clean_post_cache_cascade((int) $post_id);
                $purged++;
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[WPC HtmlPurge] post_id=%d image_id=%d error=%s',
                    (int) $post_id, $image_id, $e->getMessage()
                ));
            }
        }

        // Extension hook for customer integrations (Cloudflare API purge,
        // custom CDN purge, multisite-network-wide purge, etc.).
        do_action('wpc_variant_landed_purge_html', $image_id, $post_ids, $source);

        error_log(sprintf(
            '[WPC HtmlPurge] source=%s image_id=%d posts_purged=%d throttle_s=%d',
            (string) $source, $image_id, $purged, $throttle_seconds
        ));

        return true;
    }
}

if (!function_exists('wpc_v2_purge_html_for_attachment_deferred')) {
    /**
     * Defer the purge to shutdown so we don't add latency to the callback.
     * Uses fastcgi_finish_request when available so the encoder gets its
     * 200 ACK immediately while we do the cache work in the background.
     */
    function wpc_v2_purge_html_for_attachment_deferred($image_id, $source = 'unknown')
    {
        $image_id = (int) $image_id;
        if ($image_id <= 0) return;

        // Capture for closure.
        $captured_id = $image_id;
        $captured_src = (string) $source;

        add_action('shutdown', function () use ($captured_id, $captured_src) {
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            wpc_v2_purge_html_for_attachment($captured_id, $captured_src);
        }, 5);
    }
}

// ─────────────────────────────────────────────────────────────────────
// Test-only AJAX endpoint — apikey-gated. Lets us verify the cascade
// manually before relying on Phase B callback wiring. Mirrors the other
// helpers in v2-lazy-test-setup.php. Safe to leave in v7.05.x; remove
// once cache-invalidation is stable in production.
// ─────────────────────────────────────────────────────────────────────
if (!function_exists('wpc_v2_ajax_lazy_test_purge_html')) {
    function wpc_v2_ajax_lazy_test_purge_html()
    {
        if (!function_exists('wpc_v2_lazy_test_check_apikey') || !wpc_v2_lazy_test_check_apikey()) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }

        $image_id = isset($_REQUEST['image_id']) ? (int) $_REQUEST['image_id'] : 0;
        if ($image_id <= 0) {
            wp_send_json_error(['msg' => 'image_id required'], 400);
        }

        // Force-clear throttle for testing.
        delete_transient('wpc_html_purge_throttle_' . $image_id);
        delete_transient('wpc_html_purge_posts_' . $image_id);

        $referencing_posts = wpc_v2_discover_referencing_posts($image_id);
        $result = wpc_v2_purge_html_for_attachment($image_id, 'test');

        wp_send_json_success([
            'image_id' => $image_id,
            'fired' => $result,
            'referencing_posts' => $referencing_posts,
            'permalinks' => array_map(function ($id) { return get_permalink($id); }, $referencing_posts),
        ]);
    }
}
add_action('wp_ajax_wpc_v2_lazy_test_purge_html',        'wpc_v2_ajax_lazy_test_purge_html');
add_action('wp_ajax_nopriv_wpc_v2_lazy_test_purge_html', 'wpc_v2_ajax_lazy_test_purge_html');

// End-of-bulk hook. Per-image purges are skipped during bulk
// (see early bail in wpc_v2_purge_html_for_attachment). When bulk finishes
// (the wps_ic_bulk_process option is deleted), fire ONE global purge.
// 'all' clears the entire HTML cache + Varnish + integrators in a single
// call — much cheaper than N per-attachment purges, and the right
// behavior since bulk likely touched many posts.
add_action('delete_option', function ($option_name) {
    if ($option_name !== 'wps_ic_bulk_process') return;
    if (!get_option('wpc_v2_html_purge_pending_bulk')) return;
    delete_option('wpc_v2_html_purge_pending_bulk');
    if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
        try {
            wps_ic_cache::removeHtmlCacheFiles('all');
            error_log('[WPC HtmlPurge] end-of-bulk global purge fired');
        } catch (\Throwable $e) {
            error_log('[WPC HtmlPurge] end-of-bulk failed: ' . $e->getMessage());
        }
    }
}, 5);
