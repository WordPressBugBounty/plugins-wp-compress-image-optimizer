<?php


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

        $attached = get_post_meta($image_id, '_wp_attached_file', true);
        $stem = is_string($attached) ? preg_replace('/\.[^.\/]+$/', '', ltrim($attached, '/')) : '';
        $stem_likes = [];
        if ($stem !== '') {
            $stem_likes[] = '%' . $wpdb->esc_like('/' . $stem . '.') . '%';
            $stem_likes[] = '%' . $wpdb->esc_like('\\/' . str_replace('/', '\\/', $stem) . '.') . '%';
        }

        $id_likes = [
            '%' . $wpdb->esc_like('"id":' . $image_id . ',') . '%',
            '%' . $wpdb->esc_like('"id":' . $image_id . '}') . '%',
            '%' . $wpdb->esc_like('"id":"' . $image_id . '"') . '%',
            '%' . $wpdb->esc_like('s:2:"id";i:' . $image_id . ';') . '%',
            '%' . $wpdb->esc_like('s:5:"photo";i:' . $image_id . ';') . '%',
        ];
        $builder_keys = ['_elementor_data', '_fl_builder_data', '_bricks_page_content_2', 'ct_builder_shortcodes'];
        $builder_likes = array_merge($id_likes, $stem_likes);
        $builder_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
               INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
              WHERE pm.meta_key IN (" . implode(',', array_fill(0, count($builder_keys), '%s')) . ")
                AND p.post_status = 'publish'
                AND p.post_type NOT IN ('revision','nav_menu_item','attachment','elementor_library','bricks_template','ct_template','fl-builder-template')
                AND (" . implode(' OR ', array_fill(0, count($builder_likes), 'pm.meta_value LIKE %s')) . ")
              LIMIT 200",
            ...array_merge($builder_keys, $builder_likes)
        ));
        if (!empty($builder_ids)) {
            foreach ($builder_ids as $id) {
                $post_ids[(int) $id] = true;
            }
        }

        if ($stem_likes) {
            $divi_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                  WHERE post_status = 'publish'
                    AND post_type NOT IN ('revision','nav_menu_item','attachment')
                    AND post_content LIKE %s
                    AND (post_content LIKE %s OR post_content LIKE %s)
                  LIMIT 200",
                '%' . $wpdb->esc_like('et_pb_') . '%',
                $stem_likes[0],
                $stem_likes[1]
            ));
            if (!empty($divi_ids)) {
                foreach ($divi_ids as $id) {
                    $post_ids[(int) $id] = true;
                }
            }

            $front = get_option('show_on_front') === 'page' ? (int) get_option('page_on_front') : 0;
            if ($front > 0) {
                $front_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                      WHERE ID = %d
                        AND post_status = 'publish'
                        AND (post_content LIKE %s OR post_content LIKE %s)
                      LIMIT 1",
                    $front,
                    $stem_likes[0],
                    $stem_likes[1]
                ));
                if (!empty($front_id)) {
                    $post_ids[(int) $front_id] = true;
                }
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
    function wpc_v2_fire_clean_post_cache_cascade($post_id, $mode = null)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return;


        if ($mode !== 'hard' && function_exists('wpc_p2_land_post') && wpc_p2_land_post($post_id)) {
            return;
        }

        if (class_exists('wps_ic_cache')
            && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            try {
                wps_ic_cache::removeHtmlCacheFiles($post_id, '', '', $mode);
                return;
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[WPC HtmlPurge] wps_ic_cache::removeHtmlCacheFiles failed post_id=%d msg=%s',
                    $post_id, $e->getMessage()
                ));
                // Fall through to minimal fallback below.
            }
        }


        if (function_exists('clean_post_cache')) {
            clean_post_cache($post_id);
        }
        wpc_purge_foreign_caches(get_permalink($post_id), 'v2-html');
    }
}

if (!function_exists('wpc_v2_purge_page_within_budget')) {
    /**
     * One page's cascade inside this request's page budget (wpc_v2_landed_pages_budget): 'purged', 'memo'
     * when this request already purged the page as hard as asked, or 'queued' once the budget is spent
     * (wpc_landed_pages_pending, taken by wpc_landed_pages_drain).
     */
    function wpc_v2_purge_page_within_budget($post_id, $mode = null)
    {
        $post_id = (int) $post_id;
        if ($post_id <= 0) return 'memo';

        $rank = $mode === 'hard' ? 2 : 1;
        if ((int) ($GLOBALS['wpc_v2_html_purged_pages'][$post_id] ?? 0) >= $rank) {
            return 'memo';
        }
        if (function_exists('wpc_v2_landed_pages_budget') && function_exists('wpc_v2_landed_pages_defer_post')
            && wpc_v2_landed_pages_budget() <= 0) {
            wpc_v2_landed_pages_defer_post($post_id, $mode);
            return 'queued';
        }
        if (function_exists('wpc_v2_landed_pages_budget')) {
            wpc_v2_landed_pages_budget(1);
        }
        wpc_v2_fire_clean_post_cache_cascade($post_id, $mode);
        $GLOBALS['wpc_v2_html_purged_pages'][$post_id] = $rank;
        return 'purged';
    }
}

if (!function_exists('wpc_v2_purge_html_for_attachment')) {

    function wpc_v2_purge_html_for_attachment($image_id, $source = 'unknown', $mode = null, $any_delivery = false)
    {
        $image_id = (int) $image_id;
        if ($any_delivery ? ($image_id <= 0 || !apply_filters('wpc_v2_html_purge_enabled', true, $image_id)) : !wpc_v2_should_purge_html($image_id)) {
            return null;
        }


        $bulk = get_option('wps_ic_bulk_process');
        if (is_array($bulk) && !empty($bulk['status']) && in_array($bulk['status'], ['restoring', 'optimizing', 'running'], true)) {


            $wpc_bulk_hard = ($mode === 'hard' || $bulk['status'] === 'restoring') ? 'hard' : '1';
            if ($wpc_bulk_hard === 'hard' || get_option('wpc_v2_html_purge_pending_bulk') !== 'hard') {
                update_option('wpc_v2_html_purge_pending_bulk', $wpc_bulk_hard, false);
            }
            return false;
        }


        $throttle_seconds = (int) apply_filters('wpc_v2_html_purge_throttle_seconds', 10, $image_id);
        if ($throttle_seconds < 1) $throttle_seconds = 1;

        $throttle_key = 'wpc_html_purge_throttle_' . $image_id;
        if ($mode === 'hard') {
            delete_transient($throttle_key);
            delete_transient('wpc_html_purge_posts_' . $image_id);
        }
        if (get_transient($throttle_key)) {
            return false;
        }
        // Set throttle BEFORE doing work, so concurrent callbacks race-safe.
        set_transient($throttle_key, 1, $throttle_seconds);

        $post_ids = wpc_v2_discover_referencing_posts($image_id);
        if (empty($post_ids)) {


            error_log(sprintf(
                '[WPC HtmlPurge] source=%s image_id=%d no_referencing_posts',
                (string) $source, $image_id
            ));
            return false;
        }

        $purged = 0;
        $queued = 0;
        foreach ($post_ids as $post_id) {
            try {
                $took = wpc_v2_purge_page_within_budget((int) $post_id, $mode);
                if ($took === 'purged') $purged++;
                if ($took === 'queued') $queued++;
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
            '[WPC HtmlPurge] source=%s image_id=%d posts_purged=%d posts_queued=%d throttle_s=%d',
            (string) $source, $image_id, $purged, $queued, $throttle_seconds
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
            if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) {
                wpc_finish_request();
            }
            wpc_v2_purge_html_for_attachment($captured_id, $captured_src);
        }, 5);
    }
}


// Test-only AJAX endpoint — apikey-gated. Lets us verify the cascade
// manually before relying on Phase B callback wiring. Mirrors the other


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
// v7.21.112 — same QA-harness gate as v2-lazy-test-setup.php (WPC_LAZY_TEST_ENDPOINTS,
// default off): this test purge endpoint shares the bare-api_key test gate and has no
// production caller. Registered only when the test constant/filter is enabled.
if ((defined('WPC_LAZY_TEST_ENDPOINTS') && WPC_LAZY_TEST_ENDPOINTS)
    || (function_exists('apply_filters') && apply_filters('wpc_lazy_test_endpoints', false))) {
    add_action('wp_ajax_wpc_v2_lazy_test_purge_html',        'wpc_v2_ajax_lazy_test_purge_html');
    add_action('wp_ajax_nopriv_wpc_v2_lazy_test_purge_html', 'wpc_v2_ajax_lazy_test_purge_html');
}


add_action('delete_option', function ($option_name) {
    if ($option_name !== 'wps_ic_bulk_process') return;
    $wpc_pending = get_option('wpc_v2_html_purge_pending_bulk');
    if (!$wpc_pending) return;
    delete_option('wpc_v2_html_purge_pending_bulk');
    if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
        try {
            wps_ic_cache::removeHtmlCacheFiles('all', '', '', $wpc_pending === 'hard' ? 'hard' : 'soft');
            error_log('[WPC HtmlPurge] end-of-bulk global purge fired');
        } catch (\Throwable $e) {
            error_log('[WPC HtmlPurge] end-of-bulk failed: ' . $e->getMessage());
        }
    }
}, 5);
