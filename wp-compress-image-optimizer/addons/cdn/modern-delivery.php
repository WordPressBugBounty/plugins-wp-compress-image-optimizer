<?php
/**
 * Modern Image Delivery (7.01.0)
 *
 * JS-free native <picture> + srcset emission using existing local-mc variant files.
 * Gated entirely behind the `modern_image_delivery` setting — when OFF, this module is a no-op.
 *
 * Covers all 14 plan items: G1-G14 (gap closure), S1-S7 (safety pass), L1-L14 (local team feedback).
 *
 * @since 7.01.0
 */

if (!defined('ABSPATH')) exit;

class WPC_Modern_Delivery
{
    // Request-scoped caches (G11) — reduce N×DB hits to 1 per attachment per request
    private static $attachment_cache = [];
    private static $metadata_cache = [];
    private static $offload_cache = [];
    private static $file_exists_cache = []; // avif/webp variant disk-existence, checked once per file per request
    private static $source_width_cache = []; // authoritative source width per attachment (getimagesize on largest on-disk file)
    private static $lcp_candidate = null;
    private static $lcp_img_count = 0;
    private static $serving_base_url_cache = null; // CDN or origin base URL, resolved once per request

    /**
     * Is modern mode globally active + context allows rewrite?
     * Covers S2 + L14 — REST/AMP/admin/feed/cron/ajax bypass with SSR allow-list.
     */
    public static function is_active()
    {
        // Negotiated delivery (clean <img> + edge Accept-negotiation) and this
        // modern <picture> builder are mutually-exclusive delivery strategies. When negotiated
        // is active for the request, modern-delivery stands down COMPLETELY. This single gate
        // covers EVERY hook — the image_downsize/render filters (which wrap <img> into <picture>
        // at content-render time, UPSTREAM of cdnRewriter) as well as the buffer dispatch — so
        // no <picture> ever reaches the page when negotiated owns delivery.
        // is_active_jpeg() (the Next-Gen-off clean .jpg path) also owns image delivery when
        // it fires; this <picture> builder must stand down for it too (else a ceiling=off site with
        // the picture toggle on would wrap images in <picture> AND emit a natural <img> — a conflict).
        if (class_exists('WPC_Negotiated_Delivery')
            && (WPC_Negotiated_Delivery::is_active() || WPC_Negotiated_Delivery::is_active_jpeg())) {
            return false;
        }

        // orch cdn_disabled master kill: stand down so no <picture> with CDN-zone
        // sources is emitted. (Unlike negotiated-delivery, this builder does NOT key off the
        // resolver, so it needs its own guard.) Inert by default.
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            return false;
        }

        $settings = get_option(WPS_IC_SETTINGS);
        if (empty($settings['modern_image_delivery']) || $settings['modern_image_delivery'] != '1') {
            return false;
        }

        // Images-master gate. This <picture> builder is image-CDN delivery, so it must
        // stand down when the "Images" tile is OFF (no image serve-type on) — else Images-off still
        // emits zone <picture> avif/webp sources. Path A (wp-compress-core) serves the origin
        // <picture> instead. Mirrors the negotiated/path-A/cdnRewriter Images-master stand-downs.
        if (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled($settings)) {
            return false;
        }

        // live-cdn master gate (grand-final catch): every <source> this builder
        // emits is a ZONE transform URL, so with the CDN off (live-cdn=0) it must stand down
        // entirely — srcset entries have no onerror, and a disconnected zone means broken
        // renditions. Path A serves the origin <picture> in that state.
        if (empty($settings['live-cdn']) || (string) $settings['live-cdn'] !== '1') {
            return false;
        }

        // Admin / AJAX / Cron / Feed bypass (S2)
        if (is_admin()) return false;
        if (defined('DOING_AJAX') && DOING_AJAX) return false;
        if (defined('DOING_CRON') && DOING_CRON) return false;
        if (function_exists('is_feed') && is_feed()) return false;

        // AMP bypass
        if (function_exists('is_amp_endpoint') && is_amp_endpoint()) return false;
        if (function_exists('amp_is_request') && amp_is_request()) return false;

        // REST bypass — but allow SSR block renders (L14)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            $route = $_SERVER['REQUEST_URI'] ?? '';
            $is_ssr = strpos($route, '/wp/v2/block-renderer/') !== false;
            if (!$is_ssr) return false;
        }

        return true;
    }

    /**
     * Request-scoped attachment URL → post ID resolver (G11).
     * Handles zapwp.com CDN URLs by extracting origin from /u: segment.
     */
    public static function resolve_attachment_id($img_url, $class_attr = '')
    {
        if (empty($img_url)) return 0;

        // Fast path: WP convention — class="wp-image-{id}" is authoritative
        if (!empty($class_attr) && preg_match('/\bwp-image-(\d+)\b/', $class_attr, $m)) {
            return (int) $m[1];
        }

        $key = md5($img_url);
        if (!isset(self::$attachment_cache[$key])) {
            $resolve_url = $img_url;
            // Extract origin from WPC CDN URL pattern: .../u:https://origin/path
            // Use ~ delimiter to avoid conflict with # inside character class [^?#\s]
            if (strpos($img_url, '/u:') !== false) {
                if (preg_match('~/u:(https?://[^?#\s]+)~', $img_url, $m)) {
                    $resolve_url = $m[1];
                }
            }
            self::$attachment_cache[$key] = (int) attachment_url_to_postid($resolve_url);
        }
        return self::$attachment_cache[$key];
    }

    /**
     * Request-scoped metadata resolver (G11).
     */
    public static function resolve_metadata($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return [];
        if (!isset(self::$metadata_cache[$attachment_id])) {
            self::$metadata_cache[$attachment_id] = wp_get_attachment_metadata($attachment_id) ?: [];
        }
        return self::$metadata_cache[$attachment_id];
    }

    /**
     * Detect offloaded attachments (L12) — S3/R2/Spaces etc.
     * Returns true if attachment is offloaded (skip modern path).
     */
    public static function is_offloaded($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return false;
        if (isset(self::$offload_cache[$attachment_id])) return self::$offload_cache[$attachment_id];

        // WP Offload Media (AS3CF)
        if (get_post_meta($attachment_id, 'amazonS3_info', true)) {
            return self::$offload_cache[$attachment_id] = true;
        }
        // WP Stateless
        if (get_post_meta($attachment_id, 'sm_cloud', true)) {
            return self::$offload_cache[$attachment_id] = true;
        }
        // Generic: file doesn't exist locally
        $local = get_attached_file($attachment_id, true); // $unfiltered = true
        if (!$local || !file_exists($local)) {
            return self::$offload_cache[$attachment_id] = true;
        }

        return self::$offload_cache[$attachment_id] = false;
    }

    /**
     * Is attachment processable at all for modern mode?
     * Skips SVG, animated GIF, offloaded.
     */
    public static function is_processable($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) return false;
        if (self::is_offloaded($attachment_id)) return false;

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return false;

        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'svg' || $ext === 'svgz') return false;

        // Animated GIF detection — read first frame count
        if ($ext === 'gif') {
            $bytes = @file_get_contents($file, false, null, 0, 1024 * 1024);
            if ($bytes && preg_match_all('#\x00\x21\xF9\x04#s', $bytes) > 1) {
                return false; // animated
            }
        }

        return true;
    }

    /**
     * Force HTTPS on emitted URLs when request is HTTPS (S/G8).
     */
    public static function force_https_if_needed($url)
    {
        if (empty($url)) return $url;
        // Proxy-aware check. This was a bare is_ssl(), which no-ops at the origin
        // behind Cloudflare/WP Engine exactly when this upgrader is needed.
        if (wpc_request_is_https() && strpos($url, 'http://') === 0) {
            return 'https://' . substr($url, 7);
        }
        return $url;
    }

    /**
     * Resolve the base URL for serving variant files.
     * CDN ON  → swap origin hostname for CDN hostname (zapwp zone or custom CNAME).
     * CDN OFF → use origin as-is (files served directly from customer's server).
     * Priority: ic_custom_cname → ic_cdn_zone_name → origin fallback.
     * Cached per-request so settings are read once only.
     */
    public static function get_serving_base_url($origin_base_url)
    {
        if (self::$serving_base_url_cache !== null) {
            return self::$serving_base_url_cache;
        }

        $settings = get_option(WPS_IC_SETTINGS);
        $cdn_enabled = !empty($settings['live-cdn']) && $settings['live-cdn'] == '1';

        if (!$cdn_enabled) {
            return self::$serving_base_url_cache = $origin_base_url;
        }

        // CDN hostname: custom CNAME takes priority, fallback to zapwp zone name
        $custom_cname = get_option('ic_custom_cname');
        $cdn_host = !empty($custom_cname) ? trim($custom_cname) : trim((string) get_option('ic_cdn_zone_name'));

        if (empty($cdn_host)) {
            return self::$serving_base_url_cache = $origin_base_url;
        }

        // Swap the origin scheme+host for the CDN host, keep the path (/wp-content/uploads/...)
        $cdn_base = preg_replace('#^https?://[^/]+#', 'https://' . $cdn_host, $origin_base_url);
        return self::$serving_base_url_cache = $cdn_base;
    }

    /**
     * URL-encode the filename portion of a natural URL (G9).
     * Handles spaces, parens, non-ASCII in attachment filenames.
     */
    public static function encode_url($url)
    {
        if (empty($url)) return $url;
        // Parse the URL, encode the path per-segment, rebuild
        $parts = parse_url($url);
        if (empty($parts['path'])) return $url;
        $encoded_path = implode('/', array_map('rawurlencode', array_map('rawurldecode', explode('/', $parts['path']))));
        $rebuilt = (isset($parts['scheme']) ? $parts['scheme'] . '://' : '') .
                   (isset($parts['host']) ? $parts['host'] : '') .
                   (isset($parts['port']) ? ':' . $parts['port'] : '') .
                   $encoded_path .
                   (isset($parts['query']) ? '?' . $parts['query'] : '') .
                   (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
        return $rebuilt;
    }

    /**
     * Append ?v={mtime} cache-bust (S7) using attachment post_modified timestamp.
     */
    public static function append_version($url, $attachment_id)
    {
        if (empty($url)) return $url;
        $post = get_post($attachment_id);
        $v = $post ? strtotime($post->post_modified_gmt ?: $post->post_modified) : 0;
        if ($v <= 0) return $url;
        $sep = strpos($url, '?') !== false ? '&' : '?';
        return $url . $sep . 'v=' . $v;
    }

    /**
     * Compute sizes attribute (G2 precedence):
     * 1. Preserve existing sizes if set
     * 2. Infer from attachment width if declared
     * 3. Default 100vw
     */
    public static function resolve_sizes($original_sizes, $declared_width)
    {
        if (!empty($original_sizes)) return $original_sizes;
        $declared_width = (int) $declared_width;
        if ($declared_width > 0 && $declared_width < 2560) {
            return '(max-width: ' . $declared_width . 'px) 100vw, ' . $declared_width . 'px';
        }
        return '100vw';
    }

    /**
     * Build srcset string for a specific format (avif/webp/jpg) from variants + meta.
     * Returns empty string if no variants available for this format.
     */
    public static function build_srcset_for_format($attachment_id, $variants, $meta, $format, $upload_url_base)
    {
        $entries = [];
        $upload_dir = wp_upload_dir();
        $origin_base = rtrim($upload_dir['baseurl'], '/');
        // CDN ON → use CDN hostname; CDN OFF → use origin directly
        $base_url = self::get_serving_base_url($origin_base);
        $base_dir = rtrim($upload_dir['basedir'], '/');
        $rel_dir = !empty($meta['file']) ? dirname($meta['file']) : '';

        // Per-variant local-vs-CDN URL preference. When the customer has opted in
        // to "prefer local for confirmed-on-disk variants", use the origin URL for variants
        // where ic_local_variants[$key]['local'] === true. CDN-only variants stay on CDN.
        // Off by default (CDN URLs everywhere) — preserves existing behavior.
        $wpc_settings = function_exists('get_option') ? get_option('wps_ic_settings', []) : [];
        $prefer_local = !empty($wpc_settings['modern_delivery_prefer_local']);

        // Scaled (if present). Naming convention:
        //  - JPEG keeps "-scaled" suffix (WP convention — hero-scaled.jpg)
        //  - AVIF/WebP generated by local-mc use BASE name without "-scaled" suffix (hero.avif, hero.webp)
        if (!empty($meta['file']) && strpos($meta['file'], '-scaled') !== false) {
            $ext = ($format === 'jpg') ? pathinfo($meta['file'], PATHINFO_EXTENSION) : $format;
            if ($format === 'jpg') {
                $scaled_name = pathinfo($meta['file'], PATHINFO_FILENAME); // keeps -scaled
            } else {
                // Strip -scaled suffix — AVIF/WebP at base filename
                $scaled_name = preg_replace('/-scaled$/', '', pathinfo($meta['file'], PATHINFO_FILENAME));
            }
            $scaled_file = $rel_dir . '/' . $scaled_name . '.' . $ext;
            if (!self::variant_was_skipped($variants, 'scaled', $format)) {
                // For AVIF/WebP verify file exists on disk — local-mc may not have generated it.
                // Two naming conventions exist:
                //   local-mc (7.01.0+): strips -scaled → hero.avif
                //   legacy pipeline (pre-7.01.0): simple ext-replace → hero-scaled.avif
                // Try both and use whichever is found.
                if ($format !== 'jpg') {
                    $disk_path = $base_dir . '/' . $scaled_file;
                    if (!isset(self::$file_exists_cache[$disk_path])) {
                        self::$file_exists_cache[$disk_path] = file_exists($disk_path);
                    }
                    if (!self::$file_exists_cache[$disk_path]) {
                        // Fallback: try legacy naming with -scaled preserved
                        $legacy_base = pathinfo($meta['file'], PATHINFO_FILENAME); // keeps -scaled
                        $legacy_file = $rel_dir . '/' . $legacy_base . '.' . $format;
                        $legacy_disk = $base_dir . '/' . $legacy_file;
                        if (!isset(self::$file_exists_cache[$legacy_disk])) {
                            self::$file_exists_cache[$legacy_disk] = file_exists($legacy_disk);
                        }
                        if (self::$file_exists_cache[$legacy_disk]) {
                            $scaled_file = $legacy_file; // use legacy path for URL construction below
                        } else {
                            goto skip_scaled;
                        }
                    }
                }
                // Origin URL when the variant is confirmed local on disk and the setting is on.
                $scaled_key = 'scaled' . ($format === 'jpg' ? '' : '-' . $format);
                $scaled_serving = ($prefer_local && !empty($variants[$scaled_key]['local'])) ? $origin_base : $base_url;
                $scaled_url = self::encode_url($scaled_serving . '/' . $scaled_file);
                $scaled_url = self::force_https_if_needed($scaled_url);
                $scaled_url = self::append_version($scaled_url, $attachment_id);
                $w = !empty($meta['width']) ? (int) $meta['width'] : 2560;
                $entries[$w] = $scaled_url . ' ' . $w . 'w';
            }
            skip_scaled:;
        }

        // WP-registered sizes
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_name => $size_info) {
                if (empty($size_info['file']) || empty($size_info['width'])) continue;
                if (self::variant_was_skipped($variants, $size_name, $format)) continue;
                $size_base = pathinfo($size_info['file'], PATHINFO_FILENAME);
                $ext = ($format === 'jpg') ? pathinfo($size_info['file'], PATHINFO_EXTENSION) : $format;
                $size_file = $rel_dir . '/' . $size_base . '.' . $ext;
                // For AVIF/WebP verify file exists on disk — local-mc may skip thumbnail variants
                if ($format !== 'jpg') {
                    $disk_path = $base_dir . '/' . $size_file;
                    if (!isset(self::$file_exists_cache[$disk_path])) {
                        self::$file_exists_cache[$disk_path] = file_exists($disk_path);
                    }
                    if (!self::$file_exists_cache[$disk_path]) continue;
                }
                // Origin URL when the variant is confirmed local on disk and the setting is on.
                $size_key = $size_name . ($format === 'jpg' ? '' : '-' . $format);
                $size_serving = ($prefer_local && !empty($variants[$size_key]['local'])) ? $origin_base : $base_url;
                $size_url = self::encode_url($size_serving . '/' . $size_file);
                $size_url = self::force_https_if_needed($size_url);
                $size_url = self::append_version($size_url, $attachment_id);
                $w = (int) $size_info['width'];
                if (!isset($entries[$w])) {
                    $entries[$w] = $size_url . ' ' . $w . 'w';
                }
            }
        }

        if (empty($entries)) return '';
        ksort($entries);

        // ?src source-format hint (v7.03.38) — wire the shared "Source Hints" toggle into modern <picture>
        // <source> srcsets too, so it's uniform across ALL delivery modes. Same gate as negotiated/legacy
        // (wps_rewriteLogic::src_hint_enabled — orch per-zone echo + global default + UI toggle). Best-effort:
        // only the next-gen formats (avif/webp) carry a hint — jpg entries ARE the source ext; src = the
        // original format from meta['file'] (jpg/png/gif only; webp/avif are outputs, never sources).
        // NOTE: modern emits EXISTING on-disk variant files, so the edge serves them by path without a
        // format-probe — the hint is largely belt-and-suspenders here, but it keeps the toggle uniform and
        // is non-keying + harmless. Idempotent (skips an entry that already carries a hint).
        if ($format !== 'jpg'
            && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'src_hint_enabled')
            && wps_rewriteLogic::src_hint_enabled()) {
            $sh_oe  = !empty($meta['file']) ? strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION)) : '';
            $sh_src = ($sh_oe === 'png' || $sh_oe === 'gif') ? $sh_oe : (($sh_oe === 'jpg' || $sh_oe === 'jpeg') ? 'jpg' : '');
            if ($sh_src !== '') {
                foreach ($entries as $sh_w => $sh_entry) {
                    $sh_sp = strpos($sh_entry, ' ');
                    if ($sh_sp === false) continue;
                    $sh_u = substr($sh_entry, 0, $sh_sp);
                    if (stripos($sh_u, 'src=') !== false) continue; // already hinted
                    $sh_u .= (strpos($sh_u, '?') === false ? '?' : '&') . 'src=' . $sh_src;
                    $entries[$sh_w] = $sh_u . substr($sh_entry, $sh_sp);
                }
            }
        }
        return implode(', ', $entries);
    }

    /**
     * Extract the largest width descriptor from a srcset string.
     * Returns 0 if srcset is empty or has no parseable width descriptors.
     */
    private static function srcset_max_width($srcset)
    {
        if (empty($srcset)) return 0;
        $max = 0;
        if (preg_match_all('/\s(\d+)w/', $srcset, $m)) {
            foreach ($m[1] as $w) {
                $w = (int) $w;
                if ($w > $max) $max = $w;
            }
        }
        return $max;
    }

    /**
     * Phase 1 single-source-of-truth: resolve the expected filename + rel_path for a variant.
     *
     * Called from every consumer that needs to know "where should variant at width W in format F live?"
     * — srcset emission (build_gapfill_srcset)
     * — existence check (find_missing_ladder_widths)
     * — backfill worker filenames payload (wpc_generate_ladder_widths)
     * — future reconciliation cron (Phase 3)
     *
     * If these four disagree on filename convention you get silent cache misses where files exist
     * but lookups go to a different path. This function is the authority.
     *
     * Returns null when the variant can't exist for this attachment (width > original, missing meta, etc.)
     *
     * Three resolution cases:
     * 1. Width ≥ $meta['width']            → scaled/original variant (stripped -scaled for non-jpg)
     * 2. Width matches a WP-registered size → use WP's filename (swap extension for avif/webp)
     * 3. Width is between WP sizes          → wpc_{width} convention: {basename}-{width}.{ext}
     *
     * @param array  $meta   WP attachment metadata
     * @param int    $width  Target width
     * @param string $format 'avif' | 'webp' | 'jpg'
     * @return array|null  ['size_label', 'filename', 'rel_path'] or null
     */
    public static function resolve_variant_filename($meta, $width, $format, $source_width_override = 0)
    {
        $width = (int) $width;
        if ($width <= 0) return null;
        if (empty($meta) || empty($meta['file'])) return null;

        $meta_width = (int) ($meta['width'] ?? 0);
        if ($meta_width <= 0) return null;

        // Absolute cap honors the true source size when provided (retina widths above
        // meta['width'] are generated from the unscaled source bytes uploaded via the
        // postOptimize() unscaled-preference path). Fall back to meta['width'] for
        // legacy callers that don't supply a source probe.
        $abs_cap = ((int) $source_width_override) > 0 ? (int) $source_width_override : $meta_width;
        if ($width > $abs_cap) return null;

        $rel_dir  = dirname($meta['file']);
        $basename = pathinfo($meta['file'], PATHINFO_FILENAME);
        $base_stripped = preg_replace('/-scaled$/', '', $basename);
        $ext_fmt = ($format === 'jpg') ? pathinfo($meta['file'], PATHINFO_EXTENSION) : $format;

        // Case 1: width matches the scaled/root file dimension → reuse that file's name.
        // Widths ABOVE meta_width (up to abs_cap) fall through to Case 3 (wpc_{W})
        // because the -scaled.jpg / -root.jpg file is only meta_width pixels wide.
        if ($width === $meta_width) {
            if ($format === 'jpg') {
                // Use WP's actual filename (-scaled or plain based on big_image_size_threshold)
                $filename = basename($meta['file']);
            } else {
                // local-mc (7.01.0+) strips -scaled for avif/webp
                $filename = $base_stripped . '.' . $format;
            }
            return [
                'size_label' => 'scaled',
                'filename'   => $filename,
                'rel_path'   => $rel_dir . '/' . $filename,
            ];
        }

        // Case 2: exact match to a WP-registered size → reuse WP's filename
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_label => $info) {
                if (empty($info['file']) || empty($info['width'])) continue;
                if ((int) $info['width'] !== $width) continue;

                if ($format === 'jpg') {
                    $filename = $info['file'];
                } else {
                    // e.g. hero-300x200.jpg → hero-300x200.avif
                    $filename = pathinfo($info['file'], PATHINFO_FILENAME) . '.' . $format;
                }
                return [
                    'size_label' => $size_label,
                    'filename'   => $filename,
                    'rel_path'   => $rel_dir . '/' . $filename,
                ];
            }
        }

        // Case 3: ladder-only width (between WP sizes, no match) → wpc_{width} convention
        // Naming: {basename-stripped}-{width}.{ext} — e.g. hero-480.avif, hero-1280.webp
        // Our namespace, never collides with WP-registered sizes (which always use -WxH format).
        $filename = $base_stripped . '-' . $width . '.' . $ext_fmt;
        return [
            'size_label' => 'wpc_' . $width,
            'filename'   => $filename,
            'rel_path'   => $rel_dir . '/' . $filename,
        ];
    }

    /**
     * Authoritative source width resolver. Returns the LARGEST pixel width actually
     * available on disk for this attachment — never trusts meta['width'] alone, because
     * WP's scaled/root lifecycle plus plugin restore cycles can leave the "original"
     * file dimensionally SMALLER than -scaled.jpg or even individual thumbnails.
     *
     * Candidates (largest wins):
     *   1. wp_get_original_image_path() — WP's declared unscaled original (may be stale)
     *   2. get_attached_file() — usually -scaled.jpg when big_image_size_threshold fired
     *   3. $meta['width'] — metadata fallback
     *
     * We probe (1) and (2) with getimagesize() for ground truth. Fast enough — typical
     * LCP image touched once per render, cached per-request.
     *
     * This is the pattern used by Cloudinary / imgix / Cloudflare Polish: pick the
     * biggest-pixel "master" actually on disk, generate all variants from there, never
     * upscale beyond it.
     */
    public static function get_source_width($attachment_id, $meta)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id > 0 && isset(self::$source_width_cache[$attachment_id])) {
            return self::$source_width_cache[$attachment_id];
        }

        $max = (int) ($meta['width'] ?? 0);

        // Probe the unscaled original WP stores (may differ from get_attached_file)
        if ($attachment_id > 0 && function_exists('wp_get_original_image_path')) {
            $orig = wp_get_original_image_path($attachment_id);
            if ($orig && file_exists($orig) && is_readable($orig)) {
                $info = @getimagesize($orig);
                if (!empty($info[0])) $max = max($max, (int) $info[0]);
            }
        }

        // Probe the currently attached file (usually -scaled.jpg when WP scaled)
        if ($attachment_id > 0) {
            $attached = get_attached_file($attachment_id);
            if ($attached && file_exists($attached) && is_readable($attached)) {
                $info = @getimagesize($attached);
                if (!empty($info[0])) $max = max($max, (int) $info[0]);
            }
        }

        // Probe wp-content/wpc-backups/<year>/<month>/<basename-without-scaled>.<ext>
        // for the canonical pre-compress original. WP's wp_get_original_image_path() can be
        // smaller than the upload original after destructive restore cycles overwrite the
        // unscaled in place; the backup file survives because it's only deleted by explicit
        // `cleanup_backups`. Per service team: "we start with the original ... and use the
        // backup file on processing" — the source-of-truth for max source width.
        if ($attachment_id > 0 && defined('WP_CONTENT_DIR')) {
            $relative = (string) get_post_meta($attachment_id, '_wp_attached_file', true);
            if ($relative !== '') {
                $rel_dir   = dirname($relative);
                $rel_base  = pathinfo($relative, PATHINFO_FILENAME);
                $rel_ext   = pathinfo($relative, PATHINFO_EXTENSION) ?: 'jpg';
                $rel_strip = preg_replace('/-scaled$/', '', $rel_base);
                $candidate = WP_CONTENT_DIR . '/wpc-backups/' . trim($rel_dir, '/') . '/' . $rel_strip . '.' . $rel_ext;
                if (file_exists($candidate) && is_readable($candidate)) {
                    $info = @getimagesize($candidate);
                    if (!empty($info[0])) $max = max($max, (int) $info[0]);
                }
            }
        }

        if ($attachment_id > 0) {
            self::$source_width_cache[$attachment_id] = $max;
        }
        return $max;
    }

    /**
     * Canonical originalSize lookup for a variant's base-size label.
     *
     * The stored `ic_local_variants[entry].originalSize` field drifts because the
     * plugin overwrites WP-disk JPEG sub-sizes during compression — the next
     * compress's variant entry records the post-overwrite size, not the WP-regen
     * baseline. Reading from WP metadata + WPC backup dir provides stable values
     * that don't shift between compress cycles.
     *
     * 4-tier authoritative lookup (ported from the modal's `$canonical_orig`
     * closure at ajax.class.php:3203-3227 which has worked since v7.01.11):
     *
     *   Tier 1: WPC backup file (`wp-content/wpc-backups/<rel>`) — pristine
     *           pre-compress baseline. Only for `original` and `scaled` bases.
     *   Tier 2: WP metadata actual file size — `wp_get_original_image_path()`
     *           filesize for `original`, `$meta['filesize']` for `scaled`,
     *           `$meta['sizes'][$base]['filesize']` for registered sub-sizes.
     *   Tier 3: stored `ic_local_variants[$base].originalSize` — drift-prone but
     *           it's what we have when both above miss.
     *   Tier 4: sibling-derive — any same-base entry with non-zero originalSize
     *           (handles bg-swap-delivered entries that come in with originalSize=0).
     *
     * Returns int bytes, or 0 if no source available.
     *
     * `$meta` and `$variants` are optional — fetched on-demand when null. Pass
     * them explicitly when looping over many bases for the same image to avoid
     * re-querying post_meta per call.
     */
    public static function canonical_original_size($imageID, $base, $meta = null, $variants = null)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0 || $base === '') return 0;

        if ($meta === null)     $meta     = wp_get_attachment_metadata($imageID);
        if ($variants === null) $variants = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($meta))     $meta     = [];
        if (!is_array($variants)) $variants = [];

        $wp_orig_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : null;
        $backup_dir   = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/wpc-backups/' : '';
        $attached_rel = !empty($meta['file']) ? $meta['file'] : '';

        // Tier 1: WPC backup directory (pristine pre-compress) — original/scaled only
        if ($backup_dir !== '') {
            if ($base === 'original' && $wp_orig_path) {
                $rel = ltrim(str_replace(WP_CONTENT_DIR . '/uploads/', '', $wp_orig_path), '/');
                $bkp = $backup_dir . $rel;
                if (file_exists($bkp) && filesize($bkp) > 0) return (int) filesize($bkp);
            }
            if ($base === 'scaled' && $attached_rel !== '') {
                $bkp = $backup_dir . 'wp-content/uploads/' . $attached_rel;
                if (file_exists($bkp) && filesize($bkp) > 0) return (int) filesize($bkp);
                $bkp_alt = $backup_dir . $attached_rel;
                if (file_exists($bkp_alt) && filesize($bkp_alt) > 0) return (int) filesize($bkp_alt);
            }
        }

        // Tier 2: WP metadata actual file size (refreshed every regen)
        if ($base === 'original' && $wp_orig_path && file_exists($wp_orig_path)) {
            return (int) filesize($wp_orig_path);
        }
        if ($base === 'scaled' && !empty($meta['filesize'])) {
            return (int) $meta['filesize'];
        }
        if (isset($meta['sizes'][$base]['filesize'])) {
            return (int) $meta['sizes'][$base]['filesize'];
        }

        // Tier 3: stored ic_local_variants originalSize (the drift-prone one)
        if (isset($variants[$base]['originalSize']) && (int) $variants[$base]['originalSize'] > 0) {
            return (int) $variants[$base]['originalSize'];
        }

        // Tier 4: sibling-derive from any same-base entry
        foreach ($variants as $skey => $sdata) {
            $sbase = preg_replace('/-(avif|webp|jpe?g|png)$/i', '', $skey);
            if ($sbase === $base && (int) ($sdata['originalSize'] ?? 0) > 0) {
                return (int) $sdata['originalSize'];
            }
        }

        return 0;
    }

    /**
     * Phase 1: Compute optimal per-image srcset ladder with LCP-first two-tier thresholds.
     *
     * - LCP-critical widths [1280, 1920, 2560]: tight 1.15× threshold (every 10KB = ~50-100ms LCP)
     * - Retina LCP widths [3072, 3840]: DPR 2 × standard viewports — industry standard
     *   (matches Next.js deviceSizes default). Emits only when source supports — never upscales.
     * - Content widths [320, 480, 640, 960]: loose 1.5× threshold (Lighthouse "Properly size images")
     * - Small images (<1200px): skip LCP tier entirely (thumbnails never become LCP)
     * - Always keep WP-existing widths (zero-cost reuse, files already on disk)
     *
     * $max_width is the authoritative source width (get_source_width) — may exceed
     * $meta['width'] when WP's scaled variant is larger than the "original" on disk.
     *
     * Returns sorted unique width array for this attachment.
     */
    public static function get_optimal_ladder($attachment_id, $meta)
    {
        $max_width = self::get_source_width($attachment_id, $meta);
        if ($max_width <= 0) return [];

        // Start with WP-existing widths (zero-cost reuse) — clamped to authoritative source
        // so stale meta['sizes'] entries (e.g. 2048x1366 listed on a 1560w source) don't leak.
        $existing = [$max_width];
        foreach (($meta['sizes'] ?? []) as $size_info) {
            $w = (int) ($size_info['width'] ?? 0);
            if ($w > 0 && $w <= $max_width) $existing[] = $w;
        }
        $existing = array_unique(array_filter($existing, function ($w) { return $w > 0; }));
        sort($existing);

        $final = $existing;

        // LCP-critical widths (only for images large enough to be LCP candidates)
        if ($max_width >= 1200) {
            $lcp_widths = [1280, 1920, 2560];
            foreach ($lcp_widths as $t) {
                if ($t > $max_width) continue;
                if (!self::has_close_width($existing, $t, 1.15)) {
                    $final[] = $t;
                }
            }
        }

        // Pixel-exact LCP widths — hit common viewport × DPR combos with zero oversize.
        //  721  Moto G Power mobile Lighthouse (412 CSS × DPR 1.75)
        //  1170 iPhone 14/15           (390 × 3)
        //  1290 iPhone 14 Pro Max      (430 × 3)
        //  1350 Desktop Lighthouse     (1350 × 1)
        //  1920 Desktop 1080p          (1920 × 1)
        // No close-width check — these widths are INTENTIONALLY close to the LCP tier widths
        // (1290 vs 1280, 1170 vs 1024, 721 vs 768). Suppressing them on proximity defeats the
        // whole point: the browser srcset picker wants the smallest entry ≥ (css × dpr), and
        // having 1290 AND 1280 lets iPhone Pro Max (needs 1290) pick exactly 1290 instead of
        // jumping to 1536. Deduplication via array_unique at the end handles identical values.
        if ($max_width >= 700) {
            foreach ([721, 1170, 1290, 1350, 1920] as $t) {
                if ($t <= $max_width) $final[] = $t;
            }
        }

        // Retina LCP tier — DPR 2 × {1536, 1920} CSS viewports.
        // Only emits when source actually has that many pixels. Never synthesizes (no upscale).
        // Matches Next.js deviceSizes industry standard for retina desktop.
        foreach ([3072, 3840] as $t) {
            if ($t > $max_width) continue;
            if (!self::has_close_width($final, $t, 1.10)) {
                $final[] = $t;
            }
        }

        // Content widths (1.5× threshold — Lighthouse passes)
        $content_widths = [320, 480, 640, 960];
        foreach ($content_widths as $t) {
            if ($t > $max_width) continue;
            if (!self::has_close_width($final, $t, 1.5)) {
                $final[] = $t;
            }
        }

        $final = array_unique($final);
        sort($final);
        return array_values($final);
    }

    /**
     * Check if any width in the array is within threshold ratio of target.
     * threshold=1.2 means within [target/1.2, target*1.2].
     */
    private static function has_close_width($widths, $target, $threshold)
    {
        foreach ($widths as $w) {
            if ($w <= 0) continue;
            $ratio = max($w / $target, $target / $w);
            if ($ratio <= $threshold) return true;
        }
        return false;
    }

    /**
     * Phase 1: Find ladder widths that don't have a file on disk in the target format.
     * Uses resolve_variant_filename() as single source of truth — filename convention
     * MUST match build_gapfill_srcset() and wpc_generate_ladder_widths() exactly.
     *
     * Returns widths needing backfill.
     */
    public static function find_missing_ladder_widths($attachment_id, $meta, $format = 'avif')
    {
        $ladder = self::get_optimal_ladder($attachment_id, $meta);
        if (empty($ladder)) return [];

        $source_width = self::get_source_width($attachment_id, $meta);
        $upload_dir = wp_upload_dir();
        $base_dir = rtrim($upload_dir['basedir'], '/');
        $missing = [];

        foreach ($ladder as $width) {
            $resolved = self::resolve_variant_filename($meta, $width, $format, $source_width);
            if ($resolved === null) continue; // impossible (> source, invalid meta)

            $disk_path = $base_dir . '/' . $resolved['rel_path'];
            if (!file_exists($disk_path)) {
                $missing[] = $width;
            }
        }

        return $missing;
    }

    /**
     * Phase 1: Build gap-fill srcset by iterating the LADDER (not $meta['sizes']).
     *
     * For each ladder width, try formats in priority chain:
     *   AVIF source: AVIF → WebP → JPG    (best-available picked per-width)
     *   WebP source: WebP → AVIF → JPG    (best-available picked per-width)
     *   JPG  source: JPG only
     *
     * Mixed bytes inside a single <source> are BROWSER-CONFIRMED SAFE via the 42/42 matrix at
     * /mixed-srcset-test.html (6 cases — pure AVIF, mixed AVIF+WebP, mixed AVIF+JPG, 100% WebP,
     * 100% JPG, all-3-formats — all inside type="image/avif" — × 7 browser contexts). Browsers
     * decode based on Content-Type response header; the `type` attribute is a preference HINT.
     *
     * During a restore→backfill window when AVIF/WebP variants aren't yet generated,
     * the chain falls through to on-demand WebP via CDN proxy (q:i/r:0/wp:1/w:N/u:origin).
     * The CDN encoder fix landed 2026-04-28 — wp:1 now produces correctly-smaller bytes than
     * wp:0 JPG (verified ~37% smaller on staging). The render-time lazy-fill trigger
     * (wpc_maybe_trigger_ladder_gen at line ~971) generates static-path optimized variants in
     * the background; next page load serves the static path. AVIF source falls back to WebP
     * (always supported by AVIF browsers); WebP source falls back to on-demand WebP.
     *
     * Uses resolve_variant_filename() as single source of truth for file paths.
     *
     * Returns array: ['srcset' => 'url1 w, url2 w, ...', 'widths_emitted' => [320, 480, ...]]
     * Second field is used by instrumentation to log ACTUAL widths (not ladder aspirations).
     */
    public static function build_gapfill_srcset($attachment_id, $variants, $meta, $source_format, $upload_url_base)
    {
        $upload_dir = wp_upload_dir();
        $origin_base = rtrim($upload_dir['baseurl'], '/');
        $base_url = self::get_serving_base_url($origin_base);
        $base_dir = rtrim($upload_dir['basedir'], '/');

        // Mode-aware chain priorities. Critical correction:
        // browsers do NOT fall back between `<source>` elements in `<picture>`
        // on URL 404. They only fall through if a `<source>` has no matching
        // width OR the `type` is unsupported. Once a source is picked, a 404
        // is a BROKEN IMAGE (no graceful fallback to next <source>).
        //
        // Therefore the within-source srcset MUST always emit a URL that the
        // CDN will actually serve. Two modes:
        //
        //   safe (default for ALL lazy modes including lazy_cdn):
        //     Mixed-type chain AVIF→WebP→JPG. JPG always exists via WP's
        //     auto sub-sizes → chain always matches a local file → URL
        //     always serves. Browser decodes by Content-Type regardless of
        //     <source type> hint (verified across browsers).
        //
        //   aggressive (lazy_cdn + wpc_v2_lazy_cdn_aggressive option ON):
        //     Short chain (just the exact format) → exhausted → CDN on-demand
        //     URL emits per-format request. Requires CDN team's per-format
        //     encode endpoint to be VERIFIED LIVE (wp:2 for AVIF). When live,
        //     visitors get true AVIF on first visit. When NOT live, broken
        //     images. Customer opts in only when confirmed.
        //
        // Render-time scan (v2-trigger-scanner.php) queues background lazy
        // encoding for missing AVIF/WebP regardless of mode — local variants
        // land within ~30s and the chain finds them first on next render.
        // Once local exists, $base_url (CDN passthrough URL) serves the local
        // file via CDN's static cache — no more transform encoding needed.
        $optimization_mode = function_exists('wpc_get_optimization_mode')
            ? wpc_get_optimization_mode()
            : 'legacy';
        $lazy_cdn_aggressive = ($optimization_mode === 'lazy_cdn')
            && (int) get_option('wpc_v2_lazy_cdn_aggressive', 0) === 1;

        if ($source_format === 'avif') {
            $chain = $lazy_cdn_aggressive ? ['avif'] : ['avif', 'webp', 'jpg'];
        } elseif ($source_format === 'webp') {
            $chain = $lazy_cdn_aggressive ? ['webp'] : ['webp', 'jpg'];
        } else {
            $chain = ['jpg'];
        }

        // Iterate the LADDER, not $meta['sizes']. Ladder is capped at authoritative source width
        // (may exceed $meta['width'] when -scaled.jpg is larger than the "original" on disk).
        $ladder = self::get_optimal_ladder($attachment_id, $meta);
        if (empty($ladder)) return ['srcset' => '', 'widths_emitted' => []];

        $source_width = self::get_source_width($attachment_id, $meta);
        $entries = [];

        // For retina widths at the scaled-file dimension, the service sometimes writes the
        // variant under the legacy "-scaled" filename convention instead of our wpc_{W} convention.
        // Precompute that fallback rel_path per format so we can recognize it on disk.
        $rel_dir = !empty($meta['file']) ? dirname($meta['file']) : '';
        $basename_stripped = !empty($meta['file'])
            ? preg_replace('/-scaled$/', '', pathinfo($meta['file'], PATHINFO_FILENAME))
            : '';

        foreach ($ladder as $target_width) {
            $matched = false;
            // Try each format in the priority chain — disk lookup only
            foreach ($chain as $fmt) {
                $resolved = self::resolve_variant_filename($meta, $target_width, $fmt, $source_width);
                if ($resolved === null) continue; // impossible (> source, missing meta)

                // Check service-side regression-guard skip
                if (self::variant_was_skipped($variants, $resolved['size_label'], $fmt)) continue;

                // Check file exists on disk (request-scoped cache)
                $disk_path = $base_dir . '/' . $resolved['rel_path'];
                if (!isset(self::$file_exists_cache[$disk_path])) {
                    self::$file_exists_cache[$disk_path] = file_exists($disk_path);
                }

                $emit_rel_path = $resolved['rel_path'];
                $found = self::$file_exists_cache[$disk_path];

                // Fallback for retina widths at source dim: service pipeline may have written
                // the variant under the legacy "-scaled" filename instead of wpc_{W}.
                // Only activates for widths > meta['width'] when wpc_{W} isn't on disk.
                // Uses a local emit path — never mutates the cached existence of wpc_{W}.
                if (!$found
                    && $target_width === (int) $source_width
                    && $target_width > (int) ($meta['width'] ?? 0)
                    && $basename_stripped !== ''
                    && $fmt !== 'jpg') {
                    $legacy_rel = $rel_dir . '/' . $basename_stripped . '-scaled.' . $fmt;
                    $legacy_disk = $base_dir . '/' . $legacy_rel;
                    if (!isset(self::$file_exists_cache[$legacy_disk])) {
                        self::$file_exists_cache[$legacy_disk] = file_exists($legacy_disk);
                    }
                    if (self::$file_exists_cache[$legacy_disk]) {
                        $emit_rel_path = $legacy_rel;
                        $found = true;
                    }
                }

                if (!$found) continue;

                // Found: record URL + bytes so the Pareto prune below can reject any entry
                // whose file is larger than a larger-width file (AVIF encoder content-variance
                // can produce e.g. 1920w > 2560w on certain sources).
                $emit_disk = $base_dir . '/' . $emit_rel_path;
                $bytes = @filesize($emit_disk);

                // Defensive: reject implausibly small AVIF/WebP files (< 1 KB).
                // Observed corrupt placeholders at exactly 678 bytes (failed-encoder sentinel
                // writes). Real AVIF/WebP at any meaningful image dimension is ≥ 1-2 KB;
                // placeholders here pollute the Pareto prune (line ~770) by appearing as
                // "smallest" and causing legitimate larger thumbnails to be dropped as
                // "dominated". Treat as not-on-disk so the chain falls through to next format
                // or on-demand WebP via CDN.
                if ($fmt !== 'jpg' && $bytes > 0 && $bytes < 1024) {
                    continue; // try next format in chain
                }

                $url = self::build_variant_url($base_url, $emit_rel_path, $attachment_id);
                $entries[$target_width] = ['url' => $url, 'bytes' => (int) $bytes];
                $matched = true;
                break; // first match wins — skip remaining formats in chain
            }

            // Chain exhausted. Only fires when:
            //   (a) lazy_cdn aggressive mode (chain stripped to source format
            //       only) — CDN encodes per source format. THE WORLD-CLASS
            //       lazy_cdn loop: visitor browses, CDN cache-miss → CDN
            //       encodes <300ms → caches → serves. Plugin's render-time
            //       scan ALSO queues local encode in parallel; ~30s later
            //       local file exists, next render uses CDN-static URL for
            //       the local file (no more transform encoding for that
            //       variant ever).
            //   (b) mixed-type chain didn't find any local file — rare edge
            //       case (WP didn't generate the sub-size). Falls back to
            //       CDN on-demand for SOMETHING viewable.
            //
            // Per-format CDN params:
            //   wp:0 = JPG passthrough
            //   wp:1 = WebP on-demand encode
            //   wp:2 = AVIF on-demand encode (service-team add; coordinate
            //          rollout via wpc_v2_lazy_cdn_aggressive option)
            if (!$matched && ($source_format === 'avif' || $source_format === 'webp')) {
                $origin_file_url = self::get_origin_file_url($meta, $attachment_id);
                if ($origin_file_url) {
                    // For lazy_cdn aggressive: emit URL for the source's preferred format.
                    // For other modes: fall back to webp (always-supported by CDN today).
                    $cdn_format = $lazy_cdn_aggressive ? $source_format : 'webp';
                    $url = self::build_ondemand_url($target_width, $origin_file_url, $cdn_format, $attachment_id);
                    // bytes=0 — Pareto prune treats as "keep" (filesize unknowable for proxy URLs)
                    $entries[$target_width] = ['url' => $url, 'bytes' => 0];
                }
            }
        }

        if (empty($entries)) return ['srcset' => '', 'widths_emitted' => []];
        ksort($entries);

        // Pareto prune: walk widths descending, tracking the smallest-bytes seen so far.
        // Any entry whose bytes exceed a larger-width entry's bytes is dominated — drop it,
        // and the browser picks the larger-but-smaller variant instead (more pixels AND fewer
        // bytes). AVIF compression is content-dependent, so this inversion is possible even
        // with a well-behaved encoder. Makes the srcset byte-monotonic across widths.
        $pareto = [];
        $min_bytes_above = PHP_INT_MAX;
        foreach (array_reverse(array_keys($entries), true) as $w) {
            $bytes = $entries[$w]['bytes'];
            if ($bytes <= 0) {
                // filesize failed (permissions, race) — keep it, we can't judge
                $pareto[$w] = $entries[$w]['url'];
                continue;
            }
            if ($bytes <= $min_bytes_above) {
                $pareto[$w] = $entries[$w]['url'];
                $min_bytes_above = $bytes;
            }
            // else: dominated — skip. Browser picks next Pareto-optimal width ≥ viewport need.
        }
        // Re-sort ascending for emission
        ksort($pareto);

        $srcset_parts = [];
        $widths_emitted = [];
        foreach ($pareto as $w => $url) {
            $srcset_parts[] = $url . ' ' . $w . 'w';
            $widths_emitted[] = $w;
        }

        return [
            'srcset'         => implode(', ', $srcset_parts),
            'widths_emitted' => $widths_emitted,
        ];
    }

    /**
     * Assemble final variant URL with HTTPS + version token.
     */
    private static function build_variant_url($base_url, $relative_path, $attachment_id)
    {
        $url = self::encode_url($base_url . '/' . ltrim($relative_path, '/'));
        $url = self::force_https_if_needed($url);
        $url = self::append_version($url, $attachment_id);
        return $url;
    }

    /**
     * Resolve the parent's origin URL for CDN proxy.
     * Returns the WP-uploaded file URL on the customer's origin domain (NOT the CDN-rewritten URL).
     * The CDN proxy fetches this origin URL and applies q:i + width resize + format conversion.
     */
    private static function get_origin_file_url($meta, $attachment_id = 0)
    {
        // Lazy_cdn quality fix. $meta['file'] is the WP "attached
        // file" which is `-scaled.jpg` for images >big_image_size_threshold
        // (2560px default). That file is already q82 compressed by WP — Local
        // Service re-encoding from it produces double-compressed bytes,
        // visibly blurrier than re-encoding from the unscaled original.
        //
        // Toggle `wpc_v2_lazy_cdn_use_original` (default ON) emits the
        // unscaled original URL via wp_get_original_image_url(). Fallback
        // chain handles deleted-original, missing-function, and per-attachment
        // override cases gracefully.
        if ($attachment_id > 0
            && function_exists('wpc_v2_lazy_cdn_use_original')
            && wpc_v2_lazy_cdn_use_original($attachment_id)
            && function_exists('wp_get_original_image_url')
            && function_exists('wp_get_original_image_path')) {
            $orig_url = wp_get_original_image_url($attachment_id);
            $orig_path = wp_get_original_image_path($attachment_id);
            if ($orig_url && $orig_path && file_exists($orig_path)) {
                return self::encode_url($orig_url);
            }
            // Fallback signal: log once per request per image so admins can
            // see when always-original is bypassed (deleted original, etc).
            if ($orig_url && (!$orig_path || !file_exists($orig_path))) {
                static $logged = [];
                if (!isset($logged[$attachment_id])) {
                    error_log('[WPC LazyOrigin] imageID=' . $attachment_id . ' wp_get_original_image_url=' . $orig_url . ' but file missing — falling back to $meta[file]');
                    $logged[$attachment_id] = true;
                }
            }
        }

        if (empty($meta['file'])) return null;
        $upload_dir = wp_upload_dir();
        $origin_base = rtrim($upload_dir['baseurl'], '/');
        return self::encode_url($origin_base . '/' . ltrim($meta['file'], '/'));
    }

    /**
     * Build CDN on-demand transformation URL.
     * Pattern: https://{cdn-zone}/q:i/r:0/wp:{N}/w:{width}/u:{origin-url}
     * - q:i = intelligent quality
     * - r:0 = no retina
     * - wp:1 = on-demand WebP encode (encoder fixed 2026-04-28; verified ~37% smaller than wp:0)
     * - wp:0 = no WebP encode (JPG passthrough with q:i resize)
     * - w:N = resize to width N
     *
     * Used for AVIF/WebP source srcsets when a variant isn't yet on disk. The render-time
     * lazy-fill trigger generates the static-path optimized file in the background.
     */
    private static function build_ondemand_url($target_width, $origin_url, $format, $attachment_id)
    {
        // Proxy-aware https on the origin URL before it's embedded in
        // the CDN u: segment (or returned bare when CDN off). wp_upload_dir()
        // baseurl derives its scheme from is_ssl(), which is false at the origin
        // behind Cloudflare/WP Engine — without this the CDN would fetch origin
        // over http. force_https_if_needed() is itself proxy-aware now.
        $origin_url = self::force_https_if_needed($origin_url);
        $custom_cname = trim((string) get_option('ic_custom_cname'));
        $cdn_zone = $custom_cname ?: trim((string) get_option('ic_cdn_zone_name'));
        if (empty($cdn_zone)) {
            // CDN OFF — fall back to bare origin URL (browsers fetch unoptimized but works)
            return self::append_version($origin_url, $attachment_id);
        }
        // Per-format CDN encode parameter (naming corrected per
        // service team: wp:N for the transform pipeline, where N denotes format).
        //   /wp:0 = JPG passthrough (resize only, no recompress)
        //   /wp:1 = WebP on-demand encode (verified 2026-04-28: ~37% smaller than wp:0)
        //   /wp:2 = AVIF on-demand encode (service team build in progress;
        //          aggressive lazy_cdn behavior gated by option flag
        //          `wpc_v2_lazy_cdn_aggressive` until verified live)
        //
        // CORRECTED ARCHITECTURE NOTE: browsers do NOT fall through `<source>`
        // elements on URL 404. Once a `<source>` is picked, a missing URL
        // is a broken image — no graceful fallback. Therefore:
        //   - default chain (mixed-type AVIF→WebP→JPG): always emits a URL
        //     that resolves to a local file. Never 404. Safe on all hosts.
        //   - aggressive lazy_cdn chain (gated by option): emits per-format
        //     CDN transform URL when local missing. ONLY safe when service
        //     team's wp:2 endpoint is verified live.
        if ($format === 'avif') {
            $fmt_param = '/wp:2';
        } elseif ($format === 'webp') {
            // On a CF chain, emit the wp:1 WebP transform ONLY when the pod is
            // proven webp-capable (>=2.89.18.2 via wpc_webp_immediate_ok); otherwise a legacy
            // browser's jpg-downgrade can cache-pin under this .webp URL on an old pod (the
            // same poisoning lane the gate was built to close). Bunny and proven pods → wp:1;
            // unproven CF → wp:0 (JPG passthrough, resize-only — renders fine, never poisons).
            $fmt_param = (class_exists('wps_cdn_rewrite') && wps_cdn_rewrite::wpc_webp_immediate_ok()) ? '/wp:1' : '/wp:0';
        } else {
            $fmt_param = '/wp:0';
        }
        $url = 'https://' . $cdn_zone . '/q:i/r:0' . $fmt_param . '/w:' . (int) $target_width . '/u:' . $origin_url;
        return self::append_version($url, $attachment_id);
    }

    /**
     * Check if service marked this (size, format) as skipped (L3).
     */
    private static function variant_was_skipped($variants, $size_label, $format)
    {
        if (empty($variants) || !is_array($variants)) return false;
        if (!isset($variants[$size_label])) return false;
        $entry = $variants[$size_label];
        if (!is_array($entry)) return false;
        // Size-level regression: whole size skipped
        if (!empty($entry['skipped'])) return true;
        // Format-level skip (from service skippedFormats)
        if (!empty($entry['skipped_formats']) && is_array($entry['skipped_formats'])) {
            return in_array($format, $entry['skipped_formats'], true);
        }
        return false;
    }

    /**
     * Decide LCP eligibility for an image (G1 — tighter logo rule).
     * Count increments per render; first N eligible images get preload treatment.
     */
    public static function is_lcp_candidate($img_attrs, $meta)
    {
        self::$lcp_img_count++;
        if (self::$lcp_img_count > 1) return false; // only first eligible

        $class = $img_attrs['class'] ?? '';
        $role = $img_attrs['role'] ?? '';
        $aria_hidden = $img_attrs['aria-hidden'] ?? '';

        // Exclusions (G1)
        if ($role === 'presentation' || $aria_hidden === 'true') return false;
        if (preg_match('/\b(avatar|icon|emoji)\b/i', $class)) return false;

        // Logo rule: name matches 'logo' word boundary AND width < 400
        $width = (int) ($meta['width'] ?? $img_attrs['width'] ?? 0);
        if (preg_match('/\blogo\b/i', $class) && $width > 0 && $width < 400) return false;

        // Width sanity — < 200px is likely a thumbnail/icon
        if ($width > 0 && $width < 200) return false;

        return true;
    }

    /**
     * Capture LCP candidate data for later preload emission in <head>.
     */
    public static function set_lcp_candidate($attachment_id, $meta, $variants, $sizes_attr)
    {
        if (self::$lcp_candidate !== null) return; // already captured
        self::$lcp_candidate = [
            'attachment_id' => $attachment_id,
            'meta'          => $meta,
            'variants'      => $variants,
            'sizes'         => $sizes_attr,
        ];
    }

    /**
     * Build <link rel="preload"> string for LCP candidate (G1/L6).
     * Called from rewrite_buffer() AFTER img processing — not from wp_head.
     * wp_head fires before the output buffer is processed, so $lcp_candidate is
     * always null at that point. Injected via str_replace('</head>', ...) instead.
     */
    public static function build_lcp_preload_tag()
    {
        if (self::$lcp_candidate === null) return '';

        $c = self::$lcp_candidate;
        $attachment_id = $c['attachment_id'];
        $meta = $c['meta'];
        $variants = $c['variants'];
        $sizes = $c['sizes'];
        $upload_dir = wp_upload_dir();

        // Prefer AVIF, fall back WebP, then JPG.
        // Use the SAME builder as the <picture> source srcset so preload scanner picks
        // an identical URL to what the browser resolves from <source> — single fetch,
        // cache hit on second lookup. Mismatched ladders caused double-fetches in earlier
        // Phase 1 (preload used WP-registered-only; picture used full ladder with custom widths).
        $format = null;
        $preload_srcset = '';
        foreach (['avif', 'webp', 'jpg'] as $f) {
            $result = self::build_gapfill_srcset($attachment_id, $variants, $meta, $f, $upload_dir['baseurl']);
            if (!empty($result['srcset'])) {
                $format = $f;
                $preload_srcset = $result['srcset'];
                break;
            }
        }
        if (!$format) return '';

        $mime = ($format === 'avif') ? 'image/avif' : (($format === 'webp') ? 'image/webp' : 'image/jpeg');
        // Pick a middle-width entry as the primary href (neither smallest nor largest)
        $entries = array_map('trim', explode(',', $preload_srcset));
        $href_entry = $entries[intval(count($entries) / 2)] ?? $entries[0];
        $href = trim(preg_replace('/\s+\d+w$/', '', $href_entry));

        return "\n<link rel=\"preload\" as=\"image\" fetchpriority=\"high\"" .
               " href=\"" . esc_url($href) . "\"" .
               " imagesrcset=\"" . esc_attr($preload_srcset) . "\"" .
               " imagesizes=\"" . esc_attr($sizes) . "\"" .
               " type=\"" . esc_attr($mime) . "\" />\n";
    }

    /**
     * Build the full <picture> HTML for an <img>. Core of the modern rewrite.
     * Returns null if modern path doesn't apply (caller should emit legacy).
     */
    public static function build_picture($img_tag_html, $attachment_id, $original_src, $preserved_attrs, $is_lcp)
    {
        if (!self::is_processable($attachment_id)) return null;

        $meta = self::resolve_metadata($attachment_id);
        if (empty($meta) || empty($meta['file']) || empty($meta['sizes'])) return null;

        $variants = get_post_meta($attachment_id, 'ic_local_variants', true);
        if (!is_array($variants)) $variants = [];
        // Note: ic_local_variants is absent on images compressed before 7.01.0.
        // We don't gate on it here — build_srcset_for_format() uses file_exists() to
        // confirm what's actually on disk, so old images with AVIF/WebP variants already
        // present get <picture> output immediately without needing re-optimization.
        // After the first successful render we backfill ic_local_variants so subsequent
        // renders skip the disk checks (wpc_backfill_local_variants in compress.php).

        $upload_dir = wp_upload_dir();
        $width  = (int) ($meta['width'] ?? 0);
        $height = (int) ($meta['height'] ?? 0);

        // Resolve sizes (G2).
        // The CDN rewriter strips the WP-emitted sizes attr before we run (rewriteLogic line 2266),
        // so $preserved_attrs['sizes'] is usually empty. Fall back to the <img> width attribute
        // (display width, e.g. 1024 for size-large) rather than $meta['width'] (intrinsic file
        // width, always the full resolution). This prevents over-fetching for size-large images
        // in narrow content columns — WordPress emits width="1024" for size-large even when the
        // file is 1707px intrinsically.
        $display_width = (int) ($preserved_attrs['width'] ?? 0);
        $sizes_hint = ($display_width > 0 && $display_width < $width) ? $display_width : $width;
        $sizes = self::resolve_sizes($preserved_attrs['sizes'] ?? '', $sizes_hint);

        // For lazy-loaded images, prepend "auto" so Chrome/Firefox/Edge 123+ use the actual
        // rendered container width to pick from srcset (fixes over-fetch when image is in a
        // narrow column but sizes says "100vw"). LCP images stay eager — "auto" requires lazy.
        // Safari falls back silently to the explicit rule. No harm done.
        if (!$is_lcp && strpos($sizes, 'auto') === false) {
            $sizes = 'auto, ' . $sizes;
        }

        // Build per-format srcsets with gap-fill — respect format toggles from settings
        // Phase 1: each <source> gets full-coverage srcset via format priority chain
        // (AVIF → WebP → JPEG) so no sparse srcsets trap browsers into blurry variants.
        // Iterates the LADDER (not $meta['sizes']) so ladder-added widths (1280, 2560, etc.) appear.
        $settings = get_option(WPS_IC_SETTINGS);
        // Gate AVIF on the resolved Next-Gen ceiling, not the raw legacy
        // picture_avif key (matches cdn-rewrite.php), so a stale picture_avif=1 under
        // wpc_nextgen='webp' never emits AVIF.
        // Drop the raw picture_avif/picture_webp GENERATION-flag conjuncts (mirror the
        // cdn-rewrite.php:2872 decouple). picture_avif is the "Generate Smart AVIF" LOCAL-FILE flag —
        // gating DELIVERY on it meant a Next-Gen-ON site with picture_avif unset/0 (de-sync) delivered
        // zero avif here too. Key purely on effective_ceiling(): ON⇒avif, deliberate wpc_nextgen='webp'
        // ⇒ webp, off⇒off. Safe — per-format srcsets are built only from file_exists variants below
        // (build_gapfill_srcset), so an un-generated format simply yields an empty srcset (no 404).
        $delivery_ceiling = class_exists('WPC_Delivery_Resolver')
            ? WPC_Delivery_Resolver::effective_ceiling($settings)
            : (!empty($settings['generate_webp']) && $settings['generate_webp'] == '1' ? 'avif' : 'off');
        $avif_enabled = ($delivery_ceiling === 'avif');
        // Keep the generate_webp conjunct on webp for byte-parity with cdn-rewrite.php
        // ($pictureWebpEnabled also requires self::$webp_enabled): in the self-contradictory state
        // wpc_nextgen='webp' + generate_webp=0 (migration-healed / default-unreachable), this matches
        // cdn-rewrite (no webp source) instead of diverging. Harmless either way (gap-fill never 404s),
        // but parity avoids a documented asymmetry across the two path-B emitters.
        $webp_enabled = ($delivery_ceiling !== 'off')
            && !empty($settings['generate_webp']) && $settings['generate_webp'] == '1';

        $avif_result = $avif_enabled
            ? self::build_gapfill_srcset($attachment_id, $variants, $meta, 'avif', $upload_dir['baseurl'])
            : ['srcset' => '', 'widths_emitted' => []];
        $webp_result = $webp_enabled
            ? self::build_gapfill_srcset($attachment_id, $variants, $meta, 'webp', $upload_dir['baseurl'])
            : ['srcset' => '', 'widths_emitted' => []];
        $jpg_result  = self::build_gapfill_srcset($attachment_id, $variants, $meta, 'jpg', $upload_dir['baseurl']);

        $avif_srcset = $avif_result['srcset'];
        $webp_srcset = $webp_result['srcset'];
        $jpg_srcset  = $jpg_result['srcset'];

        // Phase 1: detect ladder widths missing from disk and queue background generation.
        // Per-attachment batched — all missing widths go in ONE local-mc POST (decoded buffer reuse).
        if (function_exists('wpc_maybe_trigger_ladder_gen')) {
            $missing = [];
            if ($avif_enabled) {
                $missing = array_merge($missing, self::find_missing_ladder_widths($attachment_id, $meta, 'avif'));
            }
            if ($webp_enabled) {
                $missing = array_merge($missing, self::find_missing_ladder_widths($attachment_id, $meta, 'webp'));
            }
            $missing = array_unique($missing);
            if (!empty($missing)) {
                wpc_maybe_trigger_ladder_gen($attachment_id, $missing);
            }
        }

        // Usage instrumentation (Phase 2.5 data collection) — track widths ACTUALLY EMITTED
        // Previously logged the ladder (aspiration), which inflated counts for widths that
        // never reached the browser. Now logs the union of widths in either AVIF or WebP srcset.
        if (function_exists('wpc_log_variant_emitted')) {
            $widths_emitted = array_unique(array_merge($avif_result['widths_emitted'], $webp_result['widths_emitted']));
            if (!empty($widths_emitted)) wpc_log_variant_emitted($attachment_id, $widths_emitted);
        }

        // Backfill ic_local_variants if missing — one-time disk scan per attachment.
        // Writes metadata so future renders skip file_exists() checks entirely (fast path).
        // Handles pre-7.01.0 images that were optimized before this metadata key existed.
        if (empty($variants) && function_exists('wpc_backfill_local_variants')) {
            wpc_backfill_local_variants($attachment_id);
        }

        // No next-gen formats found on disk — don't wrap in <picture>
        if (empty($avif_srcset) && empty($webp_srcset)) {
            // Trigger optimization only for genuinely unprocessed images.
            // ic_local_variants being empty is a proxy for "never processed by 7.01.0 pipeline".
            // Already-compressed images (pre-7.01.0) have their Gate 1 handled by
            // wpc_maybe_trigger_optimize, but if they've been tried before (ic_local_variants
            // set but no variants found) we skip the trigger to avoid unnecessary queue noise.
            //
            // Two-path trigger handling, to avoid double-firing:
            //
            //   * In LAZY modes, the trigger fires from the ladder path above
            //     (wpc_maybe_trigger_ladder_gen → ladder worker →
            //     wpc_run_lazy_variant_ladder → wpc_lazy_trigger_v2). The
            //     fallback below would fire a SECOND time → two concurrent
            //     /optimize-v2 POSTs with different jobIds → the second one
            //     overwrites pending state, and callbacks for the first get
            //     stale_job-rejected (losing variants). So in lazy modes we
            //     skip this fallback entirely; the ladder path is canonical.
            //
            //   * In LEGACY/MANUAL modes, the ladder redirect doesn't fire,
            //     so we keep wpc_maybe_trigger_optimize as the fallback for
            //     images that never got encoded.
            if (empty($variants)) {
                $is_lazy = function_exists('wpc_lazy_mode_active') && wpc_lazy_mode_active();
                if (!$is_lazy && function_exists('wpc_maybe_trigger_optimize')) {
                    wpc_maybe_trigger_optimize($attachment_id);
                }
            }
            return null;
        }

        if (empty($jpg_srcset) && empty($avif_srcset) && empty($webp_srcset)) return null;

        // Build preserved attrs string for inner <img> (L13)
        $skip_attrs = ['src', 'srcset', 'sizes', 'data-src', 'data-srcset', 'data-sizes', 'width', 'height', 'loading', 'fetchpriority'];
        $extra_attrs = '';
        foreach ($preserved_attrs as $k => $v) {
            if (in_array($k, $skip_attrs, true)) continue;
            if (strpos($k, 'data-wpc') === 0) continue;
            $extra_attrs .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
        }

        // Determine <img> fallback src — use a mid-size jpeg variant, or original
        $fallback_src = '';
        if (!empty($jpg_srcset)) {
            $entries = array_map('trim', explode(',', $jpg_srcset));
            $mid_entry = $entries[intval(count($entries) / 2)] ?? $entries[0];
            $fallback_src = trim(preg_replace('/\s+\d+w$/', '', $mid_entry));
        } else {
            $fallback_src = $original_src;
        }

        // Loading + fetchpriority
        $loading = $is_lcp ? 'eager' : 'lazy';
        $fetch = $is_lcp ? ' fetchpriority="high"' : '';

        // Capture LCP for preload emission
        if ($is_lcp) {
            self::set_lcp_candidate($attachment_id, $meta, $variants, $sizes);
        }

        // Phase 1: emit BOTH <source> tags with gap-fill coverage.
        // Browser-confirmed safe (42/42 tests) — mixed formats in single <source> decode correctly.
        // Both sources have full width coverage (gap-filled) so browser never gets trapped.
        //
        // If gap-fill produced no entries for either next-gen format, skip <picture> entirely
        // (legacy path returns original <img>).
        if (empty($avif_srcset) && empty($webp_srcset)) return null;

        // Build universal <img> fallback — prefer WP-native srcset (original <img>'s srcset
        // pointing to WP JPEG thumbnails). These files are guaranteed to exist on disk
        // regardless of our plugin state. Worst-case behavior = bare WordPress.
        $wp_native_src    = $preserved_attrs['src']    ?? $original_src;
        $wp_native_srcset = $preserved_attrs['srcset'] ?? '';
        $wp_native_sizes  = $preserved_attrs['sizes']  ?? $sizes;

        // If WP-native srcset wasn't captured (stripped by earlier pipeline), fall back to
        // our JPG gap-fill srcset (which uses WP JPEG files on disk — same end result).
        if (empty($wp_native_srcset) && !empty($jpg_srcset)) {
            $wp_native_srcset = $jpg_srcset;
        }

        // Build <picture> — emit both sources if available, then universal <img>
        $html = '<picture class="wpc-picture modern-delivery">';
        if (!empty($avif_srcset)) {
            $html .= '<source type="image/avif" srcset="' . esc_attr($avif_srcset) . '" sizes="' . esc_attr($sizes) . '">';
        }
        if (!empty($webp_srcset)) {
            $html .= '<source type="image/webp" srcset="' . esc_attr($webp_srcset) . '" sizes="' . esc_attr($sizes) . '">';
        }
        $html .= '<img src="' . esc_url($wp_native_src) . '"';
        if (!empty($wp_native_srcset)) {
            $html .= ' srcset="' . esc_attr($wp_native_srcset) . '"';
            $html .= ' sizes="' . esc_attr($wp_native_sizes) . '"';
        }
        if ($width > 0)  $html .= ' width="' . $width . '"';
        if ($height > 0) $html .= ' height="' . $height . '"';
        $html .= ' loading="' . $loading . '"' . $fetch;
        $html .= ' decoding="async"';
        $html .= $extra_attrs;
        $html .= ' />';
        $html .= '</picture>';

        return $html;
    }

    /**
     * Rewrite all <img> tags in a buffer to <picture>.
     * Called by buffer filter when modern mode is active.
     */
    public static function rewrite_buffer($buffer)
    {
        if (!self::is_active()) return $buffer;
        if (empty($buffer) || strpos($buffer, '<img') === false) return $buffer;

        // Reset per-request state
        self::$lcp_candidate = null;
        self::$lcp_img_count = 0;
        self::$serving_base_url_cache = null; // re-resolve CDN URL each buffer pass
        self::$file_exists_cache = [];        // re-check variant files each buffer pass
        self::$source_width_cache = [];       // re-probe source width each buffer pass

        $pattern = '#<img([^>]+)/?>#i';
        $buffer = preg_replace_callback($pattern, [__CLASS__, 'rewrite_img_callback'], $buffer);

        // Post-process: unwrap legacy <picture class="wpc-picture"> that contains our
        // <picture class="wpc-picture modern-delivery"> inside. Prevents invalid nested
        // <picture> markup when legacy pipeline already wrapped the <img>.
        if (strpos($buffer, 'modern-delivery') !== false) {
            $buffer = preg_replace_callback(
                '#<picture class="wpc-picture">(?:(?!</picture>).)*?(<picture class="wpc-picture modern-delivery">.*?</picture>)(?:(?!</picture>).)*?</picture>#s',
                function ($m) { return $m[1]; },
                $buffer
            );
        }

        // Inject LCP preload into <head> (G1). Must happen here — wp_head fires before buffer
        // processing, so $lcp_candidate is always null at that hook. We inject via str_replace
        // on the already-rewritten buffer after all <img> tags have been processed.
        if (self::$lcp_candidate !== null) {
            $preload = self::build_lcp_preload_tag();
            if ($preload && strpos($buffer, '</head>') !== false) {
                $buffer = str_replace('</head>', $preload . '</head>', $buffer);
            }
        }

        return $buffer;
    }

    /**
     * preg_replace_callback handler for individual <img> tags.
     */
    public static function rewrite_img_callback($matches)
    {
        $original_tag = $matches[0];
        $attrs_str = $matches[1];

        // Skip if already inside a <picture> we emitted (nested protection)
        // Skip if has data-wpc-skip attribute
        if (strpos($attrs_str, 'data-wpc-skip') !== false) return $original_tag;
        if (strpos($attrs_str, 'modern-delivery') !== false) return $original_tag;

        // Parse attrs
        $attrs = self::parse_img_attrs($attrs_str);
        $src = $attrs['src'] ?? $attrs['data-src'] ?? '';
        if (empty($src)) return $original_tag;

        // Resolve attachment (prefers wp-image-{id} class, falls back to URL resolution)
        $attachment_id = self::resolve_attachment_id($src, $attrs['class'] ?? '');

        if ($attachment_id <= 0) return $original_tag; // external / unknown

        // Is this LCP candidate?
        $is_lcp = self::is_lcp_candidate($attrs, self::resolve_metadata($attachment_id));

        $picture = self::build_picture($original_tag, $attachment_id, $src, $attrs, $is_lcp);
        if ($picture === null) return $original_tag;

        return $picture;
    }

    /**
     * Lightweight attr parser for <img> tags.
     */
    private static function parse_img_attrs($attrs_str)
    {
        $attrs = [];
        if (preg_match_all('#([a-zA-Z0-9_\-:]+)\s*=\s*(["\'])(.*?)\2#s', $attrs_str, $m, PREG_SET_ORDER)) {
            foreach ($m as $match) {
                $attrs[strtolower($match[1])] = $match[3];
            }
        }
        return $attrs;
    }

    /**
     * Bootstrap — register hooks.
     */
    public static function init()
    {
        // Emit supporting CSS for <picture> layout neutralization.
        // LCP preload is injected inside rewrite_buffer() via str_replace('</head>', ...)
        // because wp_head fires before the output buffer is processed ($lcp_candidate = null there).
        add_action('wp_head', [__CLASS__, 'emit_picture_css'], 1);

        // Phase 1 — image_downsize filter (covers admin media library, Gutenberg editor, REST API).
        // Gated on the setting directly (not is_active() which bypasses admin).
        add_filter('image_downsize', [__CLASS__, 'filter_image_downsize'], 10, 3);
    }

    public static function emit_picture_css()
    {
        if (!self::is_active()) return;
        echo '<style id="wpc-modern-picture-css">picture.modern-delivery{display:contents}</style>' . "\n";
    }

    /**
     * Phase 1 — image_downsize filter for WP API compat.
     *
     * Covers admin media library, Gutenberg editor, REST API, oEmbed, email templates,
     * and any plugin/theme that calls wp_get_attachment_image_src() or wp_get_attachment_image().
     *
     * Strategy: if WP's default would return a file that doesn't exist on disk (because
     * it's an auto-registered theme/plugin size never generated, or was deleted), trigger
     * ladder backfill and return a safe fallback URL (the full-size image).
     *
     * Returns false = let WP handle default behavior.
     */
    public static function filter_image_downsize($downsize, $attachment_id, $size)
    {
        // Only engage in Smart mode (local-only). Phase 2/3 will add R2 URL return here.
        $settings = get_option(WPS_IC_SETTINGS);
        if (empty($settings['modern_image_delivery']) || $settings['modern_image_delivery'] != '1') {
            return $downsize;
        }

        // Don't interfere with string sizes that WP resolves itself (small overhead)
        if (!is_string($size)) return $downsize;

        // Skip already-processed (prevent recursion)
        if (!empty(self::$downsize_recursion)) return $downsize;

        $meta = self::resolve_metadata($attachment_id);
        if (empty($meta) || empty($meta['file'])) return $downsize;

        $size_info = $meta['sizes'][$size] ?? null;
        if (!$size_info || empty($size_info['file'])) return $downsize;

        // Check if the expected file actually exists
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/' . dirname($meta['file']) . '/' . $size_info['file'];
        if (file_exists($file_path)) return $downsize; // WP default will work

        // File missing — triggers ladder gen for this attachment, returns safe URL meanwhile
        if (function_exists('wpc_maybe_trigger_ladder_gen')) {
            wpc_maybe_trigger_ladder_gen($attachment_id, [(int) $size_info['width']]);
        }

        // Return full-size attachment URL as safe fallback (always exists on disk)
        self::$downsize_recursion = true;
        $full_url = wp_get_attachment_url($attachment_id);
        self::$downsize_recursion = false;

        if (empty($full_url)) return $downsize;

        return [
            $full_url,
            (int) ($size_info['width'] ?? $meta['width'] ?? 0),
            (int) ($size_info['height'] ?? $meta['height'] ?? 0),
            true, // is_intermediate
        ];
    }

    private static $downsize_recursion = false;

    // ─── v7.02 Foundation (PR 1) ─────────────────────────────────────────────
    // The methods below are infrastructure for the v7.02 lazy-everything
    // architecture. They MUST default to no-op behavior (v2 flag off) so PR 1
    // can ship without changing any visitor-visible output. Subsequent PRs
    // (2-7) layer behavior on top, all gated by v2_enabled().

    /**
     * Is the CDN proxy configured for this site?
     *
     * Returns true iff a CDN zone is configured (custom CNAME OR built-in zone).
     * Used by v7.02 paths to branch on whether on-demand WebP via wp:1 is available.
     *
     * Mirrors the resolution order at modern-delivery.php:186-187 and :864-865.
     *
     * @since 7.02.0
     * @return bool
     */
    public static function is_cdn_mode_enabled()
    {
        $custom_cname = trim((string) get_option('ic_custom_cname'));
        if ($custom_cname !== '') return true;
        $cdn_zone = trim((string) get_option('ic_cdn_zone_name'));
        return $cdn_zone !== '';
    }

    /**
     * Read the v7.02 feature flag.
     *
     * Tri-state option `wpc_modern_delivery_v2`:
     *   'off'    — v7.01.15 behavior (default for both fresh installs and upgrades)
     *   'shadow' — v7.02 paths run but write to parallel meta; emitted HTML unchanged
     *   'on'     — v7.02 paths active end-to-end
     *
     * Any unexpected value normalizes to 'off' (defensive against corrupt data).
     *
     * @since 7.02.0
     * @return string One of 'off' | 'shadow' | 'on'
     */
    public static function v2_mode()
    {
        $mode = get_option('wpc_modern_delivery_v2', 'off');
        if ($mode !== 'off' && $mode !== 'shadow' && $mode !== 'on') {
            return 'off';
        }
        return $mode;
    }

    /**
     * Convenience: is v7.02 fully enabled (mode === 'on')?
     *
     * Most PR-2-through-PR-7 code branches gate on this. Shadow mode reads
     * v2_mode() directly so it can take its parallel path.
     *
     * @since 7.02.0
     * @return bool
     */
    public static function v2_enabled()
    {
        return self::v2_mode() === 'on';
    }

    /**
     * Create or upgrade the wp_wpcompress_emissions telemetry table.
     *
     * Called on plugin activation and on version-bump check. Idempotent via
     * dbDelta(). Safe to call multiple times. Tracked under option
     * `wpc_emissions_table_version` so we only run dbDelta when the schema
     * version changes (avoids per-request overhead).
     *
     * Schema:
     *   id              BIGINT UNSIGNED PK AUTO_INCREMENT
     *   attachment_id   BIGINT UNSIGNED NOT NULL
     *   width           SMALLINT UNSIGNED NOT NULL
     *   format          VARCHAR(8) NOT NULL          -- 'avif' | 'webp' | 'jpeg'
     *   page_url_hash   CHAR(8) NOT NULL              -- crc32(page_url) hex
     *   emit_count      INT UNSIGNED NOT NULL DEFAULT 1
     *   first_seen      DATETIME NOT NULL
     *   last_seen       DATETIME NOT NULL
     *
     * INDEX (attachment_id, last_seen) — for backfill lookup
     * INDEX (width, format, last_seen) — for aggregate ladder analysis
     * UNIQUE KEY (attachment_id, width, format, page_url_hash) — upsert target
     *
     * @since 7.02.0
     * @return bool True if table created/updated this call, false if no-op
     */
    public static function maybe_create_emissions_table()
    {
        global $wpdb;

        $schema_version = '1';  // bump when columns/indexes change
        $stored_version = get_option('wpc_emissions_table_version', '0');
        if ($stored_version === $schema_version) {
            return false;  // already current
        }

        $table_name = $wpdb->prefix . 'wpcompress_emissions';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            attachment_id BIGINT UNSIGNED NOT NULL,
            width SMALLINT UNSIGNED NOT NULL,
            format VARCHAR(8) NOT NULL,
            page_url_hash CHAR(8) NOT NULL,
            emit_count INT UNSIGNED NOT NULL DEFAULT 1,
            first_seen DATETIME NOT NULL,
            last_seen DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY emission_tuple (attachment_id, width, format, page_url_hash),
            KEY attachment_lookup (attachment_id, last_seen),
            KEY ladder_analysis (width, format, last_seen)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        update_option('wpc_emissions_table_version', $schema_version, false);
        return true;
    }

    // ─── v7.02 PR 2: Byte-optimal selection helpers ──────────────────────────
    // PRESENT but NOT YET CONSUMED by the live render path. PR 7 wires these in.
    //
    // Design principles (per plan §What "world-class" means here):
    //   1. Smallest bytes wins per (width, source-slot), regardless of format.
    //   2. URL stability: 5% delta required to swap to a smaller candidate.
    //   3. Write-amplification prevention: size-backfill writes are deferred
    //      to shutdown action and batched (one update_post_meta per attachment).
    //   4. Backward-compat: reads BOTH legacy `wpc_{N}` and future `{N}w` keys.
    //   5. PHP 7.4 compatible (no match expression, no str_ends_with).

    /** @var array<int, array<string, int>> Request-scoped size backfill queue */
    private static $pending_size_backfill = [];

    /** Default URL stability threshold (5%). Tunable via wpc_byte_optimal_swap_delta. */
    const URL_STABILITY_DELTA_DEFAULT = 0.05;

    /**
     * Format eligibility per source-slot.
     * AVIF source: any format (browser decodes by Content-Type, not declared type).
     * WebP source: WebP or JPEG (some WebP-capable browsers don't support AVIF).
     * JPEG source: JPEG only (legacy fallback for IE11/old Safari).
     *
     * @param string $source_type One of 'avif', 'webp', 'jpeg'.
     * @return array<string>
     */
    public static function eligible_formats_for_source($source_type)
    {
        switch ($source_type) {
            case 'avif': return ['avif', 'webp', 'jpeg'];
            case 'webp': return ['webp', 'jpeg'];
            case 'jpeg': return ['jpeg'];
            default: return [];
        }
    }

    /**
     * Possible variant keys for a (width, format) tuple.
     *
     * Returns BOTH the legacy `wpc_{N}` form AND the future `{N}w` form so the
     * helper transparently works during the PR 6 naming migration window. First
     * matching key wins at the lookup site (caller's responsibility).
     *
     * @return array<string>
     */
    public static function lookup_keys_for_width_format($width, $format)
    {
        $width = (int) $width;
        $jpeg_suffix = ($format === 'jpeg') ? '' : '-' . $format;
        return [
            $width . 'w' . $jpeg_suffix,           // future (post PR 6 migration)
            'wpc_' . $width . $jpeg_suffix,        // current
        ];
    }

    /**
     * Queue a size-backfill write to be flushed on `shutdown` action.
     * Avoids per-variant DB writes during render. See plan §Pillar 3.
     */
    public static function queue_size_backfill($attachment_id, $key, $size)
    {
        $aid = (int) $attachment_id;
        if ($aid <= 0 || empty($key) || (int) $size <= 0) return;
        if (!isset(self::$pending_size_backfill[$aid])) {
            self::$pending_size_backfill[$aid] = [];
        }
        self::$pending_size_backfill[$aid][$key] = (int) $size;
    }

    /**
     * Flush queued size-backfill writes to ic_local_variants.
     * Hooked to `shutdown`; one update_post_meta per attachment.
     *
     * Wrap the read-modify-write in GET_LOCK (the same `wpc_bg_meta_{N}`
     * lock used by bg-swap callback writers, downloadImages, singleCompressV4
     * Phase A, and downloadVariants). Prior version had an unlocked window
     * where a concurrent bg-swap callback's write to ic_local_variants could
     * be clobbered by this handler's stale-snapshot write-back.
     *
     * Race scenario (caught by service team 2026-04-30):
     *   T1: shutdown handler reads $variants (no thumbnail-avif yet)
     *   T2: bg-swap callback (under lock) writes thumbnail-avif with bg_upgraded
     *   T3: shutdown handler writes stale snapshot back → thumbnail-avif LOST
     *
     * Fix mirrors the v7.01.16 pattern at compress.php:4243. 5s lock acquisition
     * timeout. If we can't acquire the lock, skip this attachment's flush — the
     * size-backfill is best-effort idempotent: the next render that touches the
     * same variant will re-queue the size from filesize() and try again.
     */
    public static function flush_size_backfill_on_shutdown()
    {
        if (empty(self::$pending_size_backfill)) return;
        global $wpdb;

        foreach (self::$pending_size_backfill as $aid => $size_map) {
            $aid = (int) $aid;
            if ($aid <= 0) continue;

            $lock_name = 'wpc_bg_meta_' . $aid;
            // Raised from 5s to 15s; see [v2-client.php:merge_variants] for the race rationale.
            $got_lock  = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 15)", $lock_name));
            $locked    = ($got_lock === '1' || $got_lock === 1);
            if (!$locked) {
                error_log(sprintf('[WPC ModernDelivery] flush_size_backfill lock_acquire_failed aid=%d — proceeding unlocked', (int) $aid));
            }

            try {
                // Re-read inside the lock so we capture any bg-swap writes that
                // landed between this handler queuing the backfill and now.
                $variants = get_post_meta($aid, 'ic_local_variants', true);
                if (!is_array($variants)) continue;

                $changed = false;
                foreach ($size_map as $key => $size) {
                    if (!isset($variants[$key]) || !is_array($variants[$key])) continue;
                    // Only write if existing size is missing/zero (idempotent).
                    // Never overwrite a size that's already populated — that
                    // value came from a more authoritative source (Phase A
                    // service response or bg-swap callback's strlen($bytes)).
                    $existing = (int) (isset($variants[$key]['size']) ? $variants[$key]['size'] : 0);
                    if ($existing === 0 && $size > 0) {
                        $variants[$key]['size'] = $size;
                        $changed = true;
                    }
                }
                if ($changed) {
                    update_post_meta($aid, 'ic_local_variants', $variants);
                }
            } finally {
                if ($locked) {
                    $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
                }
            }
        }
        self::$pending_size_backfill = [];
    }

    /**
     * For a single variant entry, return its byte size — backfilling via
     * filesize() if missing (queued, not written immediately).
     *
     * @return int Size in bytes; 0 if cannot determine.
     */
    private static function size_or_backfill($attachment_id, $key, array $entry)
    {
        // Prefer in-memory queued size from earlier same-request lookup
        $aid = (int) $attachment_id;
        if (isset(self::$pending_size_backfill[$aid][$key])) {
            return self::$pending_size_backfill[$aid][$key];
        }

        $size = (int) (isset($entry['size']) ? $entry['size'] : 0);
        if ($size > 0) return $size;

        // Try filesize() if URL points at a local file
        if (empty($entry['url'])) return 0;
        $local_path = self::url_to_local_path_safe($entry['url']);
        if ($local_path === '' || !is_file($local_path)) return 0;

        $size = (int) @filesize($local_path);
        if ($size > 0) {
            self::queue_size_backfill($attachment_id, $key, $size);
        }
        return $size;
    }

    /**
     * Resolve a URL to a local filesystem path IF the URL is in our uploads dir.
     * Returns empty string for CDN URLs or anything outside uploads.
     */
    private static function url_to_local_path_safe($url)
    {
        if (empty($url) || !is_string($url)) return '';
        $uploads = wp_get_upload_dir();
        if (empty($uploads['baseurl']) || empty($uploads['basedir'])) return '';
        $base_url = rtrim($uploads['baseurl'], '/');
        if (strpos($url, $base_url) !== 0) return '';
        $rel = substr($url, strlen($base_url));
        if ($rel === false || $rel === '') return '';
        return rtrim($uploads['basedir'], '/') . $rel;
    }

    /**
     * Find on-disk variant candidates for a (attachment_id, width) tuple,
     * filtered by source-slot eligibility.
     *
     * @return array<int, array{url: string, size: int, fmt: string, key: string}>
     *         Sorted ASC by size; empty if no candidates.
     */
    public static function find_candidates_for_width(
        $attachment_id,
        array $variants,
        $width,
        array $eligible_formats
    ) {
        $candidates = [];
        $seen_keys = [];

        foreach ($eligible_formats as $fmt) {
            foreach (self::lookup_keys_for_width_format($width, $fmt) as $key) {
                if (isset($seen_keys[$key])) continue;
                $seen_keys[$key] = true;
                if (!isset($variants[$key]) || !is_array($variants[$key])) continue;
                $entry = $variants[$key];
                if (empty($entry['url'])) continue;  // skip empty-URL bg-callback edge case

                $size = self::size_or_backfill($attachment_id, $key, $entry);
                if ($size <= 0) continue;

                $candidates[] = [
                    'url'  => $entry['url'],
                    'size' => $size,
                    'fmt'  => $fmt,
                    'key'  => $key,
                ];
                break;  // first matching format-key wins; don't double-count migration aliases
            }
        }

        usort($candidates, function ($a, $b) {
            return $a['size'] - $b['size'];
        });
        return $candidates;
    }

    /**
     * Apply 5% URL stability threshold to candidate set + persist winner.
     *
     * Reads previous winner from `ic_local_variants_chosen` post_meta (per
     * (width, source_type) key). New candidate must beat previous winner by
     * ≥ wpc_byte_optimal_swap_delta (default 0.05) to swap.
     *
     * @return string Selected URL; empty if no valid candidates.
     */
    public static function apply_stability_threshold(
        array $candidates,
        $attachment_id,
        $width,
        $source_type
    ) {
        if (empty($candidates)) return '';
        $smallest = $candidates[0];

        $aid = (int) $attachment_id;
        $chosen = get_post_meta($aid, 'ic_local_variants_chosen', true);
        if (!is_array($chosen)) $chosen = [];

        $key = (int) $width . '_' . $source_type;

        if (!isset($chosen[$key]) || !is_array($chosen[$key]) || empty($chosen[$key]['url'])) {
            // First-time selection — straight smallest wins.
            $chosen[$key] = ['url' => $smallest['url'], 'size' => $smallest['size']];
            update_post_meta($aid, 'ic_local_variants_chosen', $chosen);
            return $smallest['url'];
        }

        $prev = $chosen[$key];
        $prev_size = (int) (isset($prev['size']) ? $prev['size'] : 0);

        // If the previous winner is no longer in the candidate set (file deleted,
        // variant pruned), fall back to current smallest and update the chosen entry.
        $prev_present = false;
        foreach ($candidates as $c) {
            if ($c['url'] === $prev['url']) {
                $prev_present = true;
                break;
            }
        }
        if (!$prev_present) {
            $chosen[$key] = ['url' => $smallest['url'], 'size' => $smallest['size']];
            update_post_meta($aid, 'ic_local_variants_chosen', $chosen);
            return $smallest['url'];
        }

        // Stability threshold check.
        $delta = (float) get_option('wpc_byte_optimal_swap_delta', self::URL_STABILITY_DELTA_DEFAULT);
        $delta = ($delta < 0 || $delta > 0.5) ? self::URL_STABILITY_DELTA_DEFAULT : $delta;
        $threshold = $prev_size * (1.0 - $delta);

        if ($prev_size > 0 && $smallest['size'] < $threshold) {
            // New candidate beats threshold — swap.
            $chosen[$key] = ['url' => $smallest['url'], 'size' => $smallest['size']];
            update_post_meta($aid, 'ic_local_variants_chosen', $chosen);
            return $smallest['url'];
        }

        // Keep previous winner.
        return $prev['url'];
    }

    /**
     * WP-native JPEG sub-size URL closest to the requested width (≤ width when possible).
     * Used as the JPEG-source fallback when no on-disk JPEG variant matches.
     *
     * @return string URL or empty string if attachment has no usable sub-sizes.
     */
    public static function nearest_wp_native_jpeg_url($attachment_id, $width)
    {
        $width = (int) $width;
        $aid = (int) $attachment_id;
        $meta = wp_get_attachment_metadata($aid);
        if (!is_array($meta)) return '';

        $best_size_name = null;
        $best_width = 0;
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_name => $info) {
                $w = (int) (isset($info['width']) ? $info['width'] : 0);
                if ($w === 0) continue;
                // Prefer largest width ≤ requested
                if ($w <= $width && $w > $best_width) {
                    $best_width = $w;
                    $best_size_name = $size_name;
                }
            }
        }

        if ($best_size_name !== null) {
            $src = wp_get_attachment_image_src($aid, $best_size_name);
            if (is_array($src) && !empty($src[0])) return $src[0];
        }

        // Fallback to full-size URL
        $src = wp_get_attachment_image_src($aid, 'full');
        return (is_array($src) && !empty($src[0])) ? $src[0] : '';
    }

    /**
     * Top-level byte-optimal URL resolver for one (attachment, width, source_type).
     *
     * Returns the URL that should populate the `<source>` srcset entry for the
     * given source slot, choosing the smallest valid bytes available on disk
     * with 5% URL stability. Falls back to:
     *   - on-demand WebP CDN URL for AVIF/WebP slots when no on-disk variant
     *   - nearest WP-native JPEG sub-size for JPEG slot
     *
     * Per-render result is cached in $resolved static cache.
     *
     * Returns empty string if v2_mode() === 'off' — caller should use legacy path.
     *
     * @param int $attachment_id
     * @param int $width
     * @param string $source_type 'avif' | 'webp' | 'jpeg'
     * @param string $origin_url  Optional origin URL for on-demand fallback construction
     * @return string URL or '' if disabled / unresolvable
     */
    public static function resolve_source_srcset_url($attachment_id, $width, $source_type, $origin_url = '')
    {
        // Strict gate: do nothing on v2_mode='off'. Caller falls back to legacy.
        if (self::v2_mode() === 'off') return '';

        $aid = (int) $attachment_id;
        $width = (int) $width;
        if ($aid <= 0 || $width <= 0) return '';

        // Per-render result memoization
        static $resolved = [];
        $cache_key = $aid . '|' . $width . '|' . $source_type;
        if (isset($resolved[$cache_key])) return $resolved[$cache_key];

        $variants = get_post_meta($aid, 'ic_local_variants', true);
        if (!is_array($variants)) $variants = [];

        $eligible = self::eligible_formats_for_source($source_type);
        if (empty($eligible)) return $resolved[$cache_key] = '';

        $candidates = self::find_candidates_for_width($aid, $variants, $width, $eligible);

        if (!empty($candidates)) {
            $url = self::apply_stability_threshold($candidates, $aid, $width, $source_type);
            return $resolved[$cache_key] = $url;
        }

        // No on-disk candidate — fall back per source-type
        if ($source_type === 'jpeg') {
            return $resolved[$cache_key] = self::nearest_wp_native_jpeg_url($aid, $width);
        }

        // AVIF/WebP: on-demand WebP via existing wp:1
        if ($origin_url !== '') {
            return $resolved[$cache_key] = self::build_ondemand_url($width, $origin_url, 'webp', $aid);
        }
        return $resolved[$cache_key] = '';
    }

    // ─── v7.02 PR 3: Telemetry-driven backfill ──────────────────────────────
    // PRESENT but emission entry-point not yet called by render path (that's PR 7).
    // Bg-swap-callback lock release IS wired in PR 3 (compress.php), but releasing
    // a never-acquired lock is a harmless no-op until the queue path activates.
    //
    // Modes:
    //   v2_mode='off'     → all paths short-circuit; zero behavior change
    //   v2_mode='shadow'  → telemetry table writes (collect data, no encode kicks)
    //   v2_mode='on'      → telemetry + actual single-variant encode kicks via service

    /** @var array<string, true> Tier 1 dedupe — request-scoped memo */
    private static $backfill_request_memo = [];

    /** Default safety-net TTL for backfill locks (10 min). Tunable via wpc_backfill_lock_ttl. */
    const BACKFILL_LOCK_TTL_DEFAULT = 600;

    /**
     * Render-path entry point: emission of a (attachment, width, format) tuple.
     *
     * In shadow/on mode: writes to `wp_wpcompress_emissions` for telemetry.
     * In on mode only: applies two-tier dedupe + acquires backfill lock + kicks
     * a single-variant encode via the existing service-side bg pipeline.
     *
     * Idempotent on the variant-already-on-disk fast path (skips encode kick if
     * `ic_local_variants` already has a `local: true` entry for the tuple).
     *
     * @param int    $attachment_id
     * @param int    $width
     * @param string $format          'avif' | 'webp' | 'jpeg'
     * @param string $page_url        Optional. Used for telemetry attribution.
     * @return bool true if a backfill encode was kicked; false otherwise.
     */
    public static function queue_backfill_for_emission($attachment_id, $width, $format, $page_url = '')
    {
        $aid = (int) $attachment_id;
        $width = (int) $width;
        if ($aid <= 0 || $width <= 0) return false;
        if ($format !== 'avif' && $format !== 'webp' && $format !== 'jpeg') return false;

        $mode = self::v2_mode();
        if ($mode === 'off') return false;

        // Telemetry write (shadow + on)
        self::log_emission_to_table($aid, $width, $format, $page_url);

        // Encode kicks ONLY in 'on' mode
        if ($mode !== 'on') return false;

        // No-op on CDN-OFF sites — they pre-encode everything at upload.
        if (!self::is_cdn_mode_enabled()) return false;

        // Fast path: variant already on disk → no need to encode
        $variants = get_post_meta($aid, 'ic_local_variants', true);
        if (is_array($variants)) {
            foreach (self::lookup_keys_for_width_format($width, $format) as $key) {
                if (isset($variants[$key])
                    && !empty($variants[$key]['url'])
                    && !empty($variants[$key]['local'])) {
                    return false; // already on disk
                }
            }
        }

        // Tier 1: request-scoped dedupe
        $memo_key = $aid . ':' . $width . ':' . $format;
        if (isset(self::$backfill_request_memo[$memo_key])) return false;
        self::$backfill_request_memo[$memo_key] = true;

        // Tier 2: DB-level lock via wp_cache_add (atomic on persistent object cache)
        // Lock key uses the legacy `wpc_{N}` size_label form — bg-swap callback's
        // sizeLabel matches this exactly, so release_backfill_lock can find it.
        $size_label = 'wpc_' . $width;
        $lock_key   = self::backfill_lock_key($aid, $size_label, $format);
        $ttl        = (int) get_option('wpc_backfill_lock_ttl', self::BACKFILL_LOCK_TTL_DEFAULT);
        if ($ttl < 60 || $ttl > 3600) $ttl = self::BACKFILL_LOCK_TTL_DEFAULT;

        $now = time();
        $cache_acquired = wp_cache_add($lock_key, $now, 'wpc_backfill', $ttl);
        if (!$cache_acquired) {
            // Another request holds the lock; skip this enqueue
            return false;
        }
        // Cross-request fallback: write a transient too. Enables the stale-lock
        // sweep to find and clear orphans on sites without persistent object cache.
        set_transient($lock_key, $now, $ttl);

        $kicked = self::kick_single_variant_encode($aid, $width, $format);
        if (!$kicked) {
            // Service kick failed — release the lock so a future emission can retry
            self::release_backfill_lock($aid, $size_label, $format);
            return false;
        }
        return true;
    }

    /**
     * Telemetry write: upsert one (attachment, width, format, page_url_hash) tuple.
     * Fires whenever v2_mode is 'shadow' OR 'on'. Idempotent: same tuple in same
     * page bumps `emit_count` and updates `last_seen` only.
     */
    private static function log_emission_to_table($attachment_id, $width, $format, $page_url)
    {
        global $wpdb;
        $aid = (int) $attachment_id;
        $width = (int) $width;
        if ($aid <= 0 || $width <= 0) return;
        if ($format !== 'avif' && $format !== 'webp' && $format !== 'jpeg') return;

        $table = $wpdb->prefix . 'wpcompress_emissions';
        $page_hash = substr(md5((string) $page_url), 0, 8);
        $now = current_time('mysql');

        // Suppress errors — table may not exist yet on a site that hasn't run
        // maybe_create_emissions_table (e.g., race between activation hook and
        // first emission). Idempotent retries on the next emission.
        $wpdb->suppress_errors(true);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table}
                (attachment_id, width, format, page_url_hash, emit_count, first_seen, last_seen)
             VALUES (%d, %d, %s, %s, 1, %s, %s)
             ON DUPLICATE KEY UPDATE
                emit_count = emit_count + 1,
                last_seen = %s",
            $aid, $width, $format, $page_hash, $now, $now, $now
        ));
        $wpdb->suppress_errors(false);
    }

    /**
     * Release a backfill lock for a (attachment, size_label, format) tuple.
     * Called from the bg-swap callback in compress.php once a variant has landed
     * AND been persisted to ic_local_variants. Releasing a non-existent lock is
     * a harmless no-op (returns false from wp_cache_delete + delete_transient).
     *
     * Early-returns when v2_mode is 'off' so customer sites that haven't opted
     * in pay zero per-bg-swap-callback cost (no DB hit for delete_transient).
     */
    public static function release_backfill_lock($attachment_id, $size_label, $format)
    {
        if (self::v2_mode() === 'off') return;

        $aid = (int) $attachment_id;
        if ($aid <= 0) return;
        $size_label = (string) $size_label;
        $format = (string) $format;
        if ($size_label === '' || $format === '') return;

        $lock_key = self::backfill_lock_key($aid, $size_label, $format);
        wp_cache_delete($lock_key, 'wpc_backfill');
        delete_transient($lock_key);
    }

    /**
     * Hourly cron: find any backfill lock transient older than 2× TTL and clear it.
     * Guards against locks orphaned by missed bg-swap callbacks (network failure,
     * plugin restart, etc.). Without this sweep, an orphaned lock would block
     * re-queues for the (attachment, width, format) tuple until the lock's natural
     * TTL expired — could be 10+ min in worst case.
     */
    public static function sweep_stale_backfill_locks()
    {
        // Skip the DB scan entirely on sites where v2 is off — no locks could exist.
        if (self::v2_mode() === 'off') return 0;

        global $wpdb;
        $ttl = (int) get_option('wpc_backfill_lock_ttl', self::BACKFILL_LOCK_TTL_DEFAULT);
        if ($ttl < 60 || $ttl > 3600) $ttl = self::BACKFILL_LOCK_TTL_DEFAULT;
        $stale_cutoff = time() - ($ttl * 2);

        $like = $wpdb->esc_like('_transient_wpc_backfill_lock_') . '%';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ));
        if (empty($rows)) return 0;

        $cleared = 0;
        foreach ($rows as $row) {
            $stored_ts = (int) $row->option_value;
            if ($stored_ts > 0 && $stored_ts < $stale_cutoff) {
                $transient_key = preg_replace('/^_transient_/', '', $row->option_name);
                delete_transient($transient_key);
                wp_cache_delete($transient_key, 'wpc_backfill');
                $cleared++;
            }
        }
        if ($cleared > 0) {
            error_log('[WPC BackfillSweep] cleared=' . $cleared . ' threshold_age_sec=' . ($ttl * 2));
        }
        return $cleared;
    }

    /**
     * Compose a canonical backfill lock key. Used by both queue_backfill_for_emission
     * and release_backfill_lock so the same tuple maps to the same lock.
     */
    private static function backfill_lock_key($attachment_id, $size_label, $format)
    {
        return 'wpc_backfill_lock_' . (int) $attachment_id . '_' . $size_label . '_' . $format;
    }

    /**
     * Kick a single-variant encode via the existing service-side bg pipeline.
     * Reuses wpc_generate_ladder_widths(...) which builds crops + filenames +
     * triggerContext correctly. Service produces (avif, webp, jpeg) outputs;
     * the matching bg-swap callback for the requested format will release our
     * lock when its bytes land.
     *
     * Per the plan's service-side contract (Surface 1), this fires a single-
     * tuple encode. The service team confirmed in their reply that the existing
     * batch endpoint accepts single-entry crops without code change. If the
     * solo-tuple smoke test exposes a guard, the wpc_backfill_batch_seconds
     * option can flip to 60s batched-window fallback.
     */
    private static function kick_single_variant_encode($attachment_id, $width, $format)
    {
        if (!function_exists('wpc_generate_ladder_widths')) return false;
        // wpc_generate_ladder_widths internally requests all 3 formats (avif/webp/jpeg)
        // for the requested width — so a single-width emission produces all formats
        // in one service round-trip, which is more efficient than per-format kicks
        // and matches the byte-optimal architecture (we want all 3 to compare).
        $kicked = wpc_generate_ladder_widths(
            (int) $attachment_id,
            [(int) $width],
            'backfill_lazy_' . $format
        );
        return (bool) $kicked;
    }
}

// Bootstrap on plugins_loaded so settings option is available
add_action('plugins_loaded', ['WPC_Modern_Delivery', 'init'], 20);

// v7.02 Foundation: ensure emissions table exists on plugins_loaded.
// Idempotent + version-gated so no per-request overhead after first run.
// Activation hook in wp-compress-core.php also calls this for new installs.
add_action('plugins_loaded', ['WPC_Modern_Delivery', 'maybe_create_emissions_table'], 21);

// v7.02 PR 2: Flush deferred size-backfill writes on shutdown.
// One update_post_meta per attachment regardless of variant count migrated.
// No-op when queue is empty (vast majority of requests).
add_action('shutdown', ['WPC_Modern_Delivery', 'flush_size_backfill_on_shutdown']);

// v7.02 PR 3: Stale-lock sweeper (hourly cron).
// Self-schedules on plugins_loaded if not already scheduled. Fires the
// `wpc_sweep_stale_backfill_locks` action which clears any backfill lock
// transients older than 2× their TTL — guards against locks orphaned by
// a missed bg-swap callback (network failure, plugin restart mid-flight).
add_action('plugins_loaded', function () {
    if (!wp_next_scheduled('wpc_sweep_stale_backfill_locks')) {
        wp_schedule_event(time() + 600, 'hourly', 'wpc_sweep_stale_backfill_locks');
    }
}, 22);
add_action('wpc_sweep_stale_backfill_locks', ['WPC_Modern_Delivery', 'sweep_stale_backfill_locks']);
