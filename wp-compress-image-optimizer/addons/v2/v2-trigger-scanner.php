<?php
/**
 * Lazy trigger scanner.
 *
 * Server-side, deterministic lazy-trigger detection. Hooks into WordPress
 * lifecycle events that already see the full rendered HTML and queue lazy
 * compression for any uncompressed imageIDs found there. Replaces the
 * fragile "PHP-on-render only" trigger that breaks behind page caches.
 *
 * Why this works:
 *   - When WPC's own page cache writes a page, the scanner fires once on
 *     that cache-miss render and queues every uncompressed image on the
 *     page. Subsequent cache-hits for the same page don't need PHP to run
 *     — the trigger was queued during the write. Caching-friendly.
 *   - save_post fires on every content publish/update — catches images in
 *     post content BEFORE the first reader visits the page. Proactive.
 *
 * Both run server-side. No browser involvement. No JS dependency. No
 * tracking pixels. Survives every cache layer (WPC own, Varnish, Cloudflare,
 * browser cache) because the trigger is wired at the page-write moment, not
 * at the page-view moment.
 *
 * Phase 1 (this file): triggers full v2 ladder for each detected imageID.
 * Phase 2 (deferred): parse srcset for specific widths and trigger only
 *   those — true "smart lazy" that encodes ONLY what's actually rendered.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_defer_lazy_trigger')) {
    /**
     * Post-response trigger drain. Teammate caught 31 serial in-request loopbacks
     * costing 30s TTFB on /case-studies/ after a bulk restore; worst on Smart
     * Delivery WITHOUT a zone, where wpc_v2_get_lazy_enabled() returns false and the
     * lazy_cdn scanner kill-switch disengages. The scan loop queues image IDs here;
     * ONE shutdown handler closes the client connection (fastcgi_finish_request /
     * litespeed_finish_request) and then dispatches the whole batch. TTFB impact is
     * ~0 on FPM/LiteSpeed; on mod_php the response is already flushed, so the page
     * renders progressively while the drain runs.
     */
    function wpc_v2_defer_lazy_trigger($id, $widths = [], $upgrade_partial = false, $release_sig = '')
    {
        global $wpc_v2_deferred_triggers;
        if (!is_array($wpc_v2_deferred_triggers)) {
            $wpc_v2_deferred_triggers = [];
            add_action('shutdown', 'wpc_v2_run_deferred_lazy_triggers', PHP_INT_MAX);
        }
        // Keyed by id = same-request dedupe (buffer pass + cache-write hook overlap).
        $wpc_v2_deferred_triggers[(int) $id] = [(int) $id, (array) $widths, (bool) $upgrade_partial, (string) $release_sig];
    }

    function wpc_v2_run_deferred_lazy_triggers()
    {
        global $wpc_v2_deferred_triggers;
        if (empty($wpc_v2_deferred_triggers) || !function_exists('wpc_lazy_trigger_v2')) {
            return;
        }
        $batch = $wpc_v2_deferred_triggers;
        $wpc_v2_deferred_triggers = [];
        // Close the connection BEFORE the loopback POSTs — they cost real wall time
        // (TLS-to-self + saturated pool) and must never hold the response open.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }
        $fired = 0;
        foreach ($batch as $t) {
            try {
                if (wpc_lazy_trigger_v2($t[0], $t[1], $t[2])) {
                    $fired++;
                } elseif ($t[3] !== '') {
                    // format-fill admit didn't fire (e.g. the 10-min storm lock), so release
                    // the 24h guard so the next reload retries instead of burning a day.
                    delete_transient($t[3]);
                }
            } catch (\Throwable $e) {
                error_log('[WPC TriggerScan] deferred trigger image=' . $t[0] . ' threw: ' . $e->getMessage());
            }
        }
        error_log('[WPC TriggerScan] deferred drain post-response queued=' . count($batch) . ' fired=' . $fired);
    }
}

if (!function_exists('wpc_v2_extract_srcset_widths')) {
    /**
     * Phase 2 smart-lazy: parse rendered HTML for per-image srcset widths.
     * Returns a map [imageID => [w1, w2, w3, ...]] of widths actually needed
     * on this page, so the lazy encode envelope can be trimmed to ONLY those
     * widths instead of the full 8-size ladder (storage + encoder cost win).
     *
     * Handles both <img class="wp-image-N" srcset="..."> attribute orders.
     * Ignores <picture><source> srcset since modern-delivery only wraps in
     * <picture> AFTER variants exist on disk — first emission (no variants
     * yet) outputs a plain <img> with the srcset we want to parse.
     */
    function wpc_v2_extract_srcset_widths($html)
    {
        if (!is_string($html) || $html === '') return [];

        $map = []; // [id => [widths]]

        // Pattern 1: class="wp-image-N" appears BEFORE srcset
        if (preg_match_all(
            '/<img\b[^>]*?\bclass="[^"]*\bwp-image-(\d+)\b[^"]*"[^>]*?\bsrcset="([^"]+)"/i',
            $html, $matches, PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $id     = (int) $m[1];
                $srcset = (string) $m[2];
                if ($id <= 0) continue;
                if (preg_match_all('/\s(\d+)w(?:[\s,]|$)/', $srcset, $w)) {
                    $widths = array_map('intval', $w[1]);
                    if (!isset($map[$id])) $map[$id] = [];
                    $map[$id] = array_values(array_unique(array_merge($map[$id], $widths)));
                }
            }
        }

        // Pattern 2: srcset appears BEFORE class="wp-image-N"
        if (preg_match_all(
            '/<img\b[^>]*?\bsrcset="([^"]+)"[^>]*?\bclass="[^"]*\bwp-image-(\d+)\b[^"]*"/i',
            $html, $matches, PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $id     = (int) $m[2];
                $srcset = (string) $m[1];
                if ($id <= 0) continue;
                if (preg_match_all('/\s(\d+)w(?:[\s,]|$)/', $srcset, $w)) {
                    $widths = array_map('intval', $w[1]);
                    if (!isset($map[$id])) $map[$id] = [];
                    $map[$id] = array_values(array_unique(array_merge($map[$id], $widths)));
                }
            }
        }

        return $map;
    }
}

if (!function_exists('wpc_v2_scan_html_for_lazy_triggers')) {
    /**
     * Scan rendered HTML for wp-image-N classes and fire wpc_lazy_trigger_v2
     * for every imageID whose ic_local_variants is empty. Uses the existing
     * 10-min trigger transient for storm dedup so calling this multiple times
     * per page (cache write + save_post + manual) is harmless.
     *
     * @param string $html     The rendered HTML to scan.
     * @param string $source   Telemetry label (cache-write|save_post|...).
     * @param array  $opts     Optional: ['skip_if_compressed' => bool (default true)]
     * @return int             Number of imageIDs for which a trigger fired.
     */
    function wpc_v2_scan_html_for_lazy_triggers($html, $source = 'unknown', array $opts = [])
    {
        if (!is_string($html) || $html === '') return 0;
        if (!function_exists('wpc_lazy_trigger_v2')) return 0;
        if (!function_exists('wpc_lazy_mode_active') || !wpc_lazy_mode_active()) return 0;

        // Scanner kill switch. In lazy_cdn mode the canonical path
        // is CDN-driven (CDN sees 404 → fires /optimize-v2 for the SPECIFIC
        // (size, format) the browser requested → orch encodes just that one
        // variant → manifest pull). The scanner is Defense Layer 4 fallback
        // for sites where Imunify360/managed-host WAF blocks CDN→plugin
        // POSTs. On healthy CDN sites the scanner queues too aggressively
        // (all srcset widths instead of just the rendered one).
        //
        // Default behavior:
        //   - lazy_cdn enabled  → scanner OFF (CDN drives encoding)
        //   - other lazy_*      → scanner ON (existing Defense Layer 4)
        //
        // Filterable for both directions:
        //   - Re-enable on WAF-blocked lazy_cdn sites:
        //       add_filter('wpc_v2_lazy_trigger_scanner_enabled', '__return_true');
        //   - Force-disable elsewhere:
        //       add_filter('wpc_v2_lazy_trigger_scanner_enabled', '__return_false');
        $default_enabled = !(function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled());

        // CDN-OFF lazy backfill. The kill switch above assumes the CDN drives
        // encoding, which is true ONLY while image requests actually ride the zone. With the
        // CDN connection OFF (live-cdn != 1), or the Images tile OFF (the strict gate keeps
        // image bytes off the zone), the edge never sees an image request → no trigger can
        // EVER fire → Smart Delivery silently does nothing and uncompressed images stay
        // uncompressed forever. In that state the scanner is the ONLY driver, so re-enable it,
        // and request the FULL ladder (parity with clicking Compress on the single image)
        // instead of the srcset-trimmed widths, since path-A's origin <picture> serves best
        // with the complete on-disk variant set.
        $wpc_llb_full_ladder = false;
        if (!$default_enabled) {
            $wpc_s = get_option('wps_ic_settings');
            $wpc_cdn_drives = is_array($wpc_s) && !empty($wpc_s['live-cdn']) && (string) $wpc_s['live-cdn'] === '1'
                && (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled($wpc_s));
            if (!$wpc_cdn_drives) {
                $default_enabled = true;
                $wpc_llb_full_ladder = true;
            }
        }
        if (!apply_filters('wpc_v2_lazy_trigger_scanner_enabled', $default_enabled)) return 0;

        $skip_if_compressed = !isset($opts['skip_if_compressed']) || (bool) $opts['skip_if_compressed'];

        // Per-request memoization. Multiple trigger surfaces can
        // fire for the same render (cache-write + ob_start + save_post can
        // overlap). The regex scan + srcset width extraction is ~5-15ms on a
        // typical page; running it 3× per request burns 15-45ms for the same
        // result. Static cache keyed by the HTML's hash means we run scan
        // once per unique HTML body per request. Subsequent surfaces hit
        // the cache instantly.
        static $request_cache = [];
        $cache_key = sha1($html);
        if (isset($request_cache[$cache_key])) {
            return $request_cache[$cache_key];
        }

        // Extract attachment IDs from WP convention class="wp-image-N".
        // This catches every img tag emitted by WP core, Gutenberg blocks,
        // shortcodes that use wp_get_attachment_image(), ACF image fields,
        // theme calls to the_post_thumbnail(), and modern-delivery's own
        // <picture><img class="wp-image-N"> wrap.
        if (!preg_match_all('/\bwp-image-(\d+)\b/', $html, $m)) {
            $request_cache[$cache_key] = 0;
            return 0;
        }

        $ids = array_unique(array_map('intval', $m[1]));
        $ids = array_filter($ids, function ($id) { return $id > 0; });
        if (empty($ids)) return 0;

        // Phase 2 smart-lazy: per-image widths needed by THIS page's srcset.
        // Empty array for an imageID = full ladder (fallback to legacy lazy_full).
        $widths_map = wpc_v2_extract_srcset_widths($html);

        $triggered = 0;
        $skipped   = 0;
        foreach ($ids as $id) {
            $wpc_upgrade_partial = false;
            if ($skip_if_compressed) {
                $variants = get_post_meta($id, 'ic_local_variants', true);
                if (is_array($variants) && !empty($variants)) {
                    // CDN-off backfill: an all-lazy partial (on-demand avif only, no
                    // Phase-A parents) can never complete itself with the CDN off, so upgrade it
                    // to a full compress instead of skipping. Real compresses still skip.
                    if ($wpc_llb_full_ladder && function_exists('wpc_v2_variants_all_lazy') && wpc_v2_variants_all_lazy($variants)) {
                        $wpc_upgrade_partial = true;
                    } else {
                        // FORMAT-DELTA TRICKLE, for when a format gets turned ON
                        // later: a compressed image whose variant set LACKS a format the
                        // current derivation (delivery need > toggles) includes gets ONE
                        // upgrade-recompress, so viewed images fill within ~90s of a reload,
                        // no manual bulk pass needed. Guarded per-(image, format-signature)
                        // for 24h, plus the trigger's own 10-min storm lock.
                        $fmt_gap = false;
                        if ((string) get_option('wpc_envelope_formats_v2', '1') === '1') {
                            $s_fg = get_option(WPS_IC_SETTINGS);
                            $s_fg = is_array($s_fg) ? $s_fg : [];
                            $ceil_fg = class_exists('WPC_Delivery_Resolver') ? WPC_Delivery_Resolver::effective_ceiling($s_fg) : 'avif';
                            $want_w_fg = ($ceil_fg === 'webp' || $ceil_fg === 'avif' || !empty($s_fg['generate_webp']));
                            $want_a_fg = ($ceil_fg === 'avif' || !empty($s_fg['picture_avif']));
                            $have_w_fg = $have_a_fg = false;
                            foreach (array_keys($variants) as $vk_fg) {
                                if (substr($vk_fg, -5) === '-webp') $have_w_fg = true;
                                elseif (substr($vk_fg, -5) === '-avif') $have_a_fg = true;
                            }
                            if (($want_w_fg && !$have_w_fg) || ($want_a_fg && !$have_a_fg)) {
                                $sig_fg = 'wpc_fmtfill_' . $id . '_' . ($want_w_fg ? 'w' : '') . ($want_a_fg ? 'a' : '');
                                if (!get_transient($sig_fg)) {
                                    set_transient($sig_fg, 1, DAY_IN_SECONDS);
                                    $fmt_gap = true;
                                    error_log('[WPC TriggerScan] format_fill_admit image=' . $id . ' want=' . ($want_w_fg ? 'W' : '') . ($want_a_fg ? 'A' : ''));
                                }
                            }
                        }
                        if ($fmt_gap) {
                            $wpc_upgrade_partial = true; // force past Gate-1, same as the partial upgrade
                        } else {
                            $skipped++;
                            continue;
                        }
                    }
                }
            }
            // CDN-off backfill encodes the FULL ladder (empty widths = full),
            // matching a manual Compress click; smart-trimmed widths stay for the WAF-fallback case.
            $needed_widths = $wpc_llb_full_ladder ? [] : (isset($widths_map[$id]) ? (array) $widths_map[$id] : []);
            // wpc_lazy_trigger_v2 has its own gates:
            //  - Gate 1 bails if variants already present (idempotent re-entry safe)
            //  - Gate 2 bails on the 10-min trigger lock (storm dedup)
            //
            // DEFERRED, after a teammate caught a 30s TTFB storm: each trigger fires a
            // loopback POST whose blocking=>false still pays DNS+TCP+TLS synchronously
            // (~0.4-1s under a saturated FPM pool), and this loop runs INSIDE the output
            // buffer, so 31 restored images cost ~30s before first byte. Queue here,
            // dispatch ONCE post-response (shutdown + fastcgi_finish_request). The trigger
            // re-checks every gate when it runs, so deferred execution is idempotent-safe;
            // the format-fill guard release rides along, handled by the drain runner.
            wpc_v2_defer_lazy_trigger($id, $needed_widths, $wpc_upgrade_partial,
                (!empty($sig_fg) && $fmt_gap) ? $sig_fg : '');
            $triggered++; // counts QUEUED — per-image dispatch verdicts now logged by the drain
        }

        if ($triggered > 0 || $skipped > 0) {
            error_log(sprintf(
                '[WPC TriggerScan] source=%s scanned=%d triggered=%d skipped_compressed=%d',
                $source,
                count($ids),
                $triggered,
                $skipped
            ));
        }

        $request_cache[$cache_key] = $triggered;
        return $triggered;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Trigger source: WPC page cache write
// ─────────────────────────────────────────────────────────────────────────
//
// Fires once per cache-miss render — when WPC writes a freshly-generated page
// to the file cache. The HTML at this point is the FINAL rendered output
// (after the_content + theme + every filter), so we see every image on the
// page regardless of where it came from (post content, widgets, sidebar,
// theme template, ACF, custom blocks, etc.).
//
// This is THE primary trigger surface for lazy mode. After the first
// cache-miss, the lazy queue has every imageID on the page; subsequent
// cache hits don't need PHP to fire anything because the work is already
// scheduled.

add_action('wpc_cache_buffer_ready', function ($buffer, $url, $prefix) {
    wpc_v2_scan_html_for_lazy_triggers((string) $buffer, 'cache-write:' . (string) $url);
}, 10, 3);


// ─────────────────────────────────────────────────────────────────────────
// Trigger source: post save/publish
// ─────────────────────────────────────────────────────────────────────────
//
// Catches images at the moment the customer publishes or updates a post —
// BEFORE any reader visits the page, BEFORE the page cache is generated.
// By the time the first reader hits the page, lazy compression for every
// in-content image is already queued or done.
//
// Skips revisions, autosaves, and non-published statuses (no point firing
// for drafts or trashed posts).

add_action('save_post', function ($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (!is_object($post) || $post->post_status !== 'publish') return;

    // Only scan post_content here — theme templates, widgets etc. will be
    // caught by the cache-write hook on first render. Cheap regex scan; no
    // need to rehydrate the full rendered HTML at save time.
    wpc_v2_scan_html_for_lazy_triggers(
        (string) $post->post_content,
        'save_post:' . (int) $post_id
    );
}, 20, 3);


// ─────────────────────────────────────────────────────────────────────────
// Trigger source: universal output buffer (cache-plugin agnostic)
// ─────────────────────────────────────────────────────────────────────────
//
// The WPC cache-write hook only fires when WPC's own page cache is enabled.
// On sites that use a different cache plugin (Breeze, WP Rocket, W3 Total
// Cache, LiteSpeed) OR no plugin cache + Cloudflare/Varnish in front, our
// hook never fires.
//
// This output buffer catches the rendered HTML at the END of every front-end
// PHP request — so on any cache-miss (regardless of which cache plugin
// regenerates), we see the final HTML and fire the scanner. Future cache
// hits don't run PHP at all, but the work is already queued from the miss.
//
// Costs:
//   - One regex scan over the page HTML per cache-miss request. With dedup
//     transients in wpc_lazy_trigger_v2, repeated scans of the same image
//     no-op after the first fire.
//   - ob_start callback runs synchronously at request end — does not block
//     response (returns the buffer unchanged).
//
// Bypass conditions: admin, AJAX, cron, REST (already isolated by
// wpc_lazy_mode_active gates inside the scanner).

add_action('template_redirect', function () {
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON)) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (function_exists('is_feed') && is_feed()) return;
    if (!function_exists('wpc_lazy_mode_active') || !wpc_lazy_mode_active()) return;

    ob_start(function ($html) {
        // Scanner is fully gated internally — safe to call unconditionally.
        // Any errors are caught so we never break the page response.
        try {
            wpc_v2_scan_html_for_lazy_triggers((string) $html, 'output-buffer');
        } catch (\Throwable $e) {
            // Silent — page render must not break on trigger-scan errors.
        }
        return $html;
    });
}, 1);
