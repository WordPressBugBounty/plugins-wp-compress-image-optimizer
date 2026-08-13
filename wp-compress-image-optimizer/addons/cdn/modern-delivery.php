<?php


if (!defined('ABSPATH')) exit;

class WPC_Modern_Delivery
{
    // Request-scoped caches (G11) — reduce N×DB hits to 1 per attachment per request
    private static $attachment_cache = [];
    private static $metadata_cache = [];
    private static $offload_cache = [];
    private static $file_exists_cache = [];
    private static $source_width_cache = [];
    private static $lcp_candidate = null;
    private static $lcp_img_count = 0;
    private static $serving_base_url_cache = null; // CDN or origin base URL, resolved once per request

    /**
     * Is modern mode globally active + context allows rewrite?
     * Covers S2 + L14 — REST/AMP/admin/feed/cron/ajax bypass with SSR allow-list.
     */
    public static function is_active()
    {


        if (class_exists('WPC_Negotiated_Delivery')
            && (WPC_Negotiated_Delivery::is_active() || WPC_Negotiated_Delivery::is_active_jpeg())) {
            return false;
        }


        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            return false;
        }

        $settings = get_option(WPS_IC_SETTINGS);
        if (empty($settings['modern_image_delivery']) || $settings['modern_image_delivery'] != '1') {
            return false;
        }


        if (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled($settings)) {
            return false;
        }


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
            self::$attachment_cache[$key] = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_att_id'))
                ? (int) wps_rewriteLogic::wpc_att_id($resolve_url)
                : (int) attachment_url_to_postid($resolve_url);
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
                return false;
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


        $wpc_settings = function_exists('get_option') ? get_option('wps_ic_settings', []) : [];
        $prefer_local = !empty($wpc_settings['modern_delivery_prefer_local']);


        if (!empty($meta['file']) && strpos($meta['file'], '-scaled') !== false) {
            $ext = ($format === 'jpg') ? pathinfo($meta['file'], PATHINFO_EXTENSION) : $format;
            if ($format === 'jpg') {
                $scaled_name = pathinfo($meta['file'], PATHINFO_FILENAME);
            } else {
                // Strip -scaled suffix — AVIF/WebP at base filename
                $scaled_name = preg_replace('/-scaled$/', '', pathinfo($meta['file'], PATHINFO_FILENAME));
            }
            $scaled_file = $rel_dir . '/' . $scaled_name . '.' . $ext;
            if (!self::variant_was_skipped($variants, 'scaled', $format)) {


                if ($format !== 'jpg') {
                    $disk_path = $base_dir . '/' . $scaled_file;
                    if (!isset(self::$file_exists_cache[$disk_path])) {
                        self::$file_exists_cache[$disk_path] = file_exists($disk_path);
                    }
                    if (!self::$file_exists_cache[$disk_path]) {
                        // Fallback: try legacy naming with -scaled preserved
                        $legacy_base = pathinfo($meta['file'], PATHINFO_FILENAME);
                        $legacy_file = $rel_dir . '/' . $legacy_base . '.' . $format;
                        $legacy_disk = $base_dir . '/' . $legacy_file;
                        if (!isset(self::$file_exists_cache[$legacy_disk])) {
                            self::$file_exists_cache[$legacy_disk] = file_exists($legacy_disk);
                        }
                        if (self::$file_exists_cache[$legacy_disk]) {
                            $scaled_file = $legacy_file;
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


        // <source> srcsets too, so it's uniform across ALL delivery modes. Same gate as negotiated/legacy


        if ($format !== 'jpg'
            && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'src_hint_enabled')
            && wps_rewriteLogic::src_hint_enabled()) {
            $sh_oe  = !empty($meta['file']) ? strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION)) : '';
            $sh_src = in_array($sh_oe, ['png', 'gif', 'webp', 'jpg', 'jpeg'], true) ? $sh_oe : ''; // literal ext: jpg and jpeg are DISTINCT files at origin
            if ($sh_src !== '') {
                foreach ($entries as $sh_w => $sh_entry) {
                    $sh_sp = strpos($sh_entry, ' ');
                    if ($sh_sp === false) continue;
                    $sh_u = substr($sh_entry, 0, $sh_sp);
                    if (stripos($sh_u, 'src=') !== false) continue;
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


    public static function resolve_variant_filename($meta, $width, $format, $source_width_override = 0)
    {
        $width = (int) $width;
        if ($width <= 0) return null;
        if (empty($meta) || empty($meta['file'])) return null;

        $meta_width = (int) ($meta['width'] ?? 0);
        if ($meta_width <= 0) return null;


        $abs_cap = ((int) $source_width_override) > 0 ? (int) $source_width_override : $meta_width;
        if ($width > $abs_cap) return null;

        $rel_dir  = dirname($meta['file']);
        $basename = pathinfo($meta['file'], PATHINFO_FILENAME);
        $base_stripped = preg_replace('/-scaled$/', '', $basename);
        $ext_fmt = ($format === 'jpg') ? pathinfo($meta['file'], PATHINFO_EXTENSION) : $format;


        if ($width === $meta_width) {
            if ($format === 'jpg') {
                // Use WP's actual filename (-scaled or plain based on big_image_size_threshold)
                $filename = basename($meta['file']);
            } else {

                $filename = $base_stripped . '.' . $format;
            }
            return [
                'size_label' => 'scaled',
                'filename'   => $filename,
                'rel_path'   => $rel_dir . '/' . $filename,
            ];
        }

        // Case 2: exact match to a WP-registered size → reuse WP's filename.
        // v7.10.468 — WIDTH ALONE IS NOT A MATCH. A hard-CROPPED registered size has the
        // right width and the wrong framing: on busyprosai the 1200x950 hero has Divi's
        // et-pb-portfolio-module-image at 510x382, while the aspect-preserving 510 is
        // 510x404 (510 * 950/1200 = 403.75). Reusing the crop serves a differently-framed
        // image under a name that promises a scale — and every other width on that
        // attachment (412x326, 576x456, 823x652 …) follows the aspect exactly, so the
        // crop was the sole outlier and it silently won the 510 rung.
        $wpc_mh468 = (int) ($meta['height'] ?? 0);
        $wpc_eh468 = ($meta_width > 0 && $wpc_mh468 > 0)
            ? (int) round($width * $wpc_mh468 / $meta_width) : 0;
        // Rounding across the ladder is ±1px; a crop differs by far more. 1% with a 1px
        // floor separates them without inventing a new derivation.
        $wpc_tol468 = max(1, (int) round($wpc_eh468 * (float) apply_filters('wpc_variant_aspect_tolerance', 0.01)));
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $size_label => $info) {
                if (empty($info['file']) || empty($info['width'])) continue;
                if ((int) $info['width'] !== $width) continue;
                $wpc_ih468 = (int) ($info['height'] ?? 0);
                if ($wpc_eh468 > 0 && $wpc_ih468 > 0 && abs($wpc_ih468 - $wpc_eh468) > $wpc_tol468) {
                    continue;
                }

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


        $filename = $base_stripped . '-' . $width . '.' . $ext_fmt;
        return [
            'size_label' => 'wpc_' . $width,
            'filename'   => $filename,
            'rel_path'   => $rel_dir . '/' . $filename,
        ];
    }


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

        //  1170 iPhone 14/15           (390 × 3)
        //  1290 iPhone 14 Pro Max      (430 × 3)


        if ($max_width >= 700) {
            foreach ([721, 1170, 1290, 1350, 1920] as $t) {
                if ($t <= $max_width) $final[] = $t;
            }
        }


        foreach ([3072, 3840] as $t) {
            if ($t > $max_width) continue;
            if (!self::has_close_width($final, $t, 1.10)) {
                $final[] = $t;
            }
        }


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
            if ($resolved === null) continue;

            $disk_path = $base_dir . '/' . $resolved['rel_path'];
            if (!file_exists($disk_path)) {
                $missing[] = $width;
            }
        }

        return $missing;
    }


    public static function build_gapfill_srcset($attachment_id, $variants, $meta, $source_format, $upload_url_base)
    {
        $upload_dir = wp_upload_dir();
        $origin_base = rtrim($upload_dir['baseurl'], '/');
        $base_url = self::get_serving_base_url($origin_base);
        $base_dir = rtrim($upload_dir['basedir'], '/');


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


        $rel_dir = !empty($meta['file']) ? dirname($meta['file']) : '';
        $basename_stripped = !empty($meta['file'])
            ? preg_replace('/-scaled$/', '', pathinfo($meta['file'], PATHINFO_FILENAME))
            : '';

        foreach ($ladder as $target_width) {
            $matched = false;
            // Try each format in the priority chain — disk lookup only
            foreach ($chain as $fmt) {
                $resolved = self::resolve_variant_filename($meta, $target_width, $fmt, $source_width);
                if ($resolved === null) continue;


                if (self::variant_was_skipped($variants, $resolved['size_label'], $fmt)) continue;

                // Check file exists on disk (request-scoped cache)
                $disk_path = $base_dir . '/' . $resolved['rel_path'];
                if (!isset(self::$file_exists_cache[$disk_path])) {
                    self::$file_exists_cache[$disk_path] = file_exists($disk_path);
                }

                $emit_rel_path = $resolved['rel_path'];
                $found = self::$file_exists_cache[$disk_path];


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


                $emit_disk = $base_dir . '/' . $emit_rel_path;
                $bytes = @filesize($emit_disk);


                if ($fmt !== 'jpg' && $bytes > 0 && $bytes < 1024) {
                    continue;
                }

                $url = self::build_variant_url($base_url, $emit_rel_path, $attachment_id);
                $entries[$target_width] = ['url' => $url, 'bytes' => (int) $bytes];
                $matched = true;
                break;
            }


            if (!$matched && ($source_format === 'avif' || $source_format === 'webp')) {
                $origin_file_url = self::get_origin_file_url($meta, $attachment_id);
                if ($origin_file_url) {
                    // For lazy_cdn aggressive: emit URL for the source's preferred format.
                    // For other modes: fall back to webp (always-supported by CDN today).
                    $cdn_format = $lazy_cdn_aggressive ? $source_format : 'webp';
                    $url = self::build_ondemand_url($target_width, $origin_file_url, $cdn_format, $attachment_id);

                    $entries[$target_width] = ['url' => $url, 'bytes' => 0];
                }
            }
        }

        if (empty($entries)) return ['srcset' => '', 'widths_emitted' => []];
        ksort($entries);


        $pareto = [];
        $min_bytes_above = PHP_INT_MAX;
        foreach (array_reverse(array_keys($entries), true) as $w) {
            $bytes = $entries[$w]['bytes'];
            if ($bytes <= 0) {

                $pareto[$w] = $entries[$w]['url'];
                continue;
            }
            if ($bytes <= $min_bytes_above) {
                $pareto[$w] = $entries[$w]['url'];
                $min_bytes_above = $bytes;
            }

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


    private static function build_ondemand_url($target_width, $origin_url, $format, $attachment_id)
    {


        $origin_url = self::force_https_if_needed($origin_url);
        $custom_cname = trim((string) get_option('ic_custom_cname'));
        $cdn_zone = $custom_cname ?: trim((string) get_option('ic_cdn_zone_name'));
        if (empty($cdn_zone)) {
            // CDN OFF — fall back to bare origin URL (browsers fetch unoptimized but works)
            return self::append_version($origin_url, $attachment_id);
        }
        // Per-format CDN encode parameter (naming corrected per

        //   /wp:0 = JPG passthrough (resize only, no recompress)
        //   /wp:1 = WebP on-demand encode (verified 2026-04-28: ~37% smaller than wp:0)


        if ($format === 'avif') {
            $fmt_param = '/wp:2';
        } elseif ($format === 'webp') {


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
        if (self::$lcp_img_count > 1) return false;

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
        if (self::$lcp_candidate !== null) return;
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


        $att_url = function_exists('wp_get_attachment_url') ? (string) wp_get_attachment_url($attachment_id) : '';
        if ($att_url !== '') {
            $att_host  = (string) wp_parse_url($att_url, PHP_URL_HOST);
            $site_host = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
            if ($att_host !== '' && $site_host !== ''
                && strcasecmp(preg_replace('/^www\./i', '', $att_host), preg_replace('/^www\./i', '', $site_host)) !== 0) {
                return null;
            }
        }

        $meta = self::resolve_metadata($attachment_id);
        if (empty($meta) || empty($meta['file']) || empty($meta['sizes'])) return null;

        $variants = get_post_meta($attachment_id, 'ic_local_variants', true);
        if (!is_array($variants)) $variants = [];


        $upload_dir = wp_upload_dir();
        $width  = (int) ($meta['width'] ?? 0);
        $height = (int) ($meta['height'] ?? 0);


        $display_width = (int) ($preserved_attrs['width'] ?? 0);
        $sizes_hint = ($display_width > 0 && $display_width < $width) ? $display_width : $width;
        $sizes = self::resolve_sizes($preserved_attrs['sizes'] ?? '', $sizes_hint);


        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_census_slot_sizes')) {
            $wpc_msz131 = wps_rewriteLogic::wpc_census_slot_sizes((string) $meta['file'], ' width="' . (int) $sizes_hint . '"');
            if ($wpc_msz131 !== '') { $sizes = $wpc_msz131; }
        }


        // Only original-attr sizes may take auto — invented sizes render the pre-layout
        // UA fallback (3000px) on CSS-auto themes.
        if (!$is_lcp && ($preserved_attrs['sizes'] ?? '') !== '' && strpos($sizes, 'auto') === false) {
            $sizes = 'auto, ' . $sizes;
        }


        $settings = get_option(WPS_IC_SETTINGS);


        $delivery_ceiling = class_exists('WPC_Delivery_Resolver')
            ? WPC_Delivery_Resolver::effective_ceiling($settings)
            : (!empty($settings['generate_webp']) && $settings['generate_webp'] == '1' ? 'avif' : 'off');
        $avif_enabled = ($delivery_ceiling === 'avif');


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


        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_census_format_rungs')) {
            $wpc_merge123 = function ($srcset, $extras) {
                if (empty($extras)) { return $srcset; }
                $have = [];
                foreach (preg_split('/\s*,\s*/', (string) $srcset) as $c) {
                    if (preg_match('/\s(\d+)w$/', trim($c), $m)) { $have[(int) $m[1]] = 1; }
                }
                $add = [];
                foreach ($extras as $e) {
                    if (preg_match('/\s(\d+)w$/', $e, $m) && !isset($have[(int) $m[1]])) {
                        $add[] = $e;
                        $have[(int) $m[1]] = 1;
                    }
                }
                if (empty($add)) { return $srcset; }
                return $srcset === '' ? implode(', ', $add) : ($srcset . ', ' . implode(', ', $add));
            };
            if ($avif_enabled) {
                $avif_srcset = $wpc_merge123($avif_srcset, wps_rewriteLogic::wpc_census_format_rungs($original_src, 'avif'));
            }
            if ($webp_enabled) {
                $webp_srcset = $wpc_merge123($webp_srcset, wps_rewriteLogic::wpc_census_format_rungs($original_src, 'webp'));
            }
        }

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


        if (function_exists('wpc_log_variant_emitted')) {
            $widths_emitted = array_unique(array_merge($avif_result['widths_emitted'], $webp_result['widths_emitted']));
            if (!empty($widths_emitted)) wpc_log_variant_emitted($attachment_id, $widths_emitted);
        }


        if (empty($variants) && function_exists('wpc_backfill_local_variants')) {
            wpc_backfill_local_variants($attachment_id);
        }

        // No next-gen formats found on disk — don't wrap in <picture>
        if (empty($avif_srcset) && empty($webp_srcset)) {


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


        if (empty($avif_srcset) && empty($webp_srcset)) return null;


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
        $html .= '<img data-wpc-md="1" src="' . esc_url($wp_native_src) . '"';
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
        self::$serving_base_url_cache = null;
        self::$file_exists_cache = [];
        self::$source_width_cache = [];

        $pattern = '#<img([^>]+)/?>#i';
        $buffer = preg_replace_callback($pattern, [__CLASS__, 'rewrite_img_callback'], $buffer);


        if (strpos($buffer, 'modern-delivery') !== false) {
            $buffer = preg_replace_callback(
                '#<picture class="wpc-picture">(?:(?!</picture>).)*?(<picture class="wpc-picture modern-delivery">.*?</picture>)(?:(?!</picture>).)*?</picture>#s',
                function ($m) { return $m[1]; },
                $buffer
            );
        }


        if (self::$lcp_candidate !== null) {
            $preload = self::build_lcp_preload_tag();
            if ($preload && strpos($buffer, '</head>') !== false) {
                $buffer = str_replace('</head>', $preload . '</head>', $buffer);
            }
        }

        return $buffer;
    }


    public static function build_picture_offloaded($original_tag, $src, $attrs)
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return null;
        $flag_default = defined('WPC_OFFLOADED_PICTURE') ? (bool) WPC_OFFLOADED_PICTURE : true;
        if (!apply_filters('wpc_offloaded_picture', $flag_default, $src)) return null;
        if (!is_string($src) || $src === '' || stripos($src, 'data:') === 0) return null;

        // The src may already be a /q:i/.../u:ORIGIN transform (cdn-rewrite ran first) — recover the origin.
        $origin_src = $src;
        if (strpos($src, '/u:') !== false && preg_match('~/u:(https?://[^?#\s"\']+)~', $src, $m)) {
            $origin_src = $m[1];
        }
        $clean = preg_replace('/[?#].*$/', '', (string) $origin_src);
        $ext   = strtolower((string) pathinfo($clean, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) return null;

        // SAME-ORIGIN only — never route a foreign host through the zone.
        $origin_host = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
        $src_host    = (string) wp_parse_url($clean, PHP_URL_HOST);
        if ($origin_host === '' || $src_host === '' || strcasecmp($src_host, $origin_host) !== 0) return null;

        // Zone host: ic_custom_cname → ic_cdn_zone_name (host only).
        $cdn_host = trim((string) get_option('ic_custom_cname'));
        if ($cdn_host === '') $cdn_host = trim((string) get_option('ic_cdn_zone_name'));
        $cdn_host = rtrim(preg_replace('#^https?://#', '', $cdn_host), '/');
        if ($cdn_host === '' || strcasecmp($cdn_host, $origin_host) === 0) return null;


        $ceiling = class_exists('WPC_Delivery_Resolver')
            ? WPC_Delivery_Resolver::effective_ceiling(get_option(WPS_IC_SETTINGS)) : 'avif';
        if ($ceiling === 'off') return null; // Next-Gen off → nothing to gain, keep the transform
        $forced  = function_exists('wpc_force_natural') && wpc_force_natural();
        $avif_ok = ($ceiling === 'avif') && ($forced || (class_exists('wps_rewriteLogic')
            && method_exists('wps_rewriteLogic', 'avif_natural_source_ok') && wps_rewriteLogic::avif_natural_source_ok()));
        $webp_ok = $forced || (class_exists('wps_cdn_rewrite')
            && method_exists('wps_cdn_rewrite', 'wpc_webp_immediate_ok') && wps_cdn_rewrite::wpc_webp_immediate_ok());
        if (!$avif_ok && !$webp_ok) return null;


        if (strpos($clean, '/wp-content/uploads/') === false) return null;

        // Natural base = host-swap origin → zone (exact origin path preserved). Anchored ext-swap (mangle-safe).
        $zone_base = preg_replace('#^https?://[^/]+#', 'https://' . $cdn_host, $clean);
        $swap = function ($fmt) use ($zone_base) {
            return class_exists('wps_rewriteLogic')
                ? wps_rewriteLogic::swap_ext_to($zone_base, $fmt)
                : preg_replace('/\.(jpe?g|png)(?=[?#]|$)/', '.' . $fmt, $zone_base);
        };
        // ?src= on-disk-format hint — uniform with the other lanes.
        $hint = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'src_hint_enabled')
            && wps_rewriteLogic::src_hint_enabled()) ? ('?src=' . $ext) : ''; // literal ext: never normalize jpeg to jpg

        // Preserve original attrs on the fallback <img> (minus the ones we own).
        $skip = ['src', 'srcset', 'sizes', 'data-src', 'data-srcset', 'loading', 'decoding', 'onerror', 'data-wpc-fb'];
        $extra = '';
        foreach ($attrs as $k => $v) {
            if (in_array($k, $skip, true)) continue;
            $extra .= ' ' . $k . '="' . esc_attr($v) . '"';
        }
        $loading = (isset($attrs['loading']) && $attrs['loading'] === 'eager') ? 'eager' : 'lazy';

        $html  = '<picture class="wpc-picture modern-delivery wpc-offloaded">';
        if ($avif_ok) $html .= '<source type="image/avif" srcset="' . esc_attr($swap('avif') . $hint) . '">';
        if ($webp_ok) $html .= '<source type="image/webp" srcset="' . esc_attr($swap('webp') . $hint) . '">';
        $html .= '<img src="' . esc_url($zone_base) . '"';
        $html .= ' data-wpc-fb="' . esc_attr($clean) . '"';      // STATIC onerror (no URL in JS → no injection surface)
        $html .= ' data-wpc-md="1"';

        // <source> outranks the <img> src forever, so the old fallback never took effect

        $html .= ' onerror="this.onerror=null;var p=this.parentNode;if(p&&p.tagName===\'PICTURE\'){var s;while(s=p.getElementsByTagName(\'source\')[0])s.parentNode.removeChild(s);}this.removeAttribute(\'srcset\');this.src=this.getAttribute(\'data-wpc-fb\');"';
        $html .= ' loading="' . $loading . '" decoding="async"';
        $html .= $extra;
        $html .= ' />';
        $html .= '</picture>';
        return $html;
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

        if ($attachment_id <= 0) {

            // (witness-gated; null when it doesn't apply → keep the original transform tag verbatim).
            $off = self::build_picture_offloaded($original_tag, $src, $attrs);
            return $off !== null ? $off : $original_tag;
        }

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
            true,
        ];
    }

    private static $downsize_recursion = false;


    public static function is_cdn_mode_enabled()
    {
        $custom_cname = trim((string) get_option('ic_custom_cname'));
        if ($custom_cname !== '') return true;
        $cdn_zone = trim((string) get_option('ic_cdn_zone_name'));
        return $cdn_zone !== '';
    }


    public static function v2_mode()
    {
        $mode = get_option('wpc_modern_delivery_v2', 'off');
        if ($mode !== 'off' && $mode !== 'shadow' && $mode !== 'on') {
            return 'off';
        }
        return $mode;
    }


    public static function v2_enabled()
    {
        return self::v2_mode() === 'on';
    }


    public static function maybe_create_emissions_table()
    {
        global $wpdb;

        $schema_version = '1';
        $stored_version = get_option('wpc_emissions_table_version', '0');
        if ($stored_version === $schema_version) {
            return false;
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


    /** @var array<int, array<string, int>> Request-scoped size backfill queue */
    private static $pending_size_backfill = [];

    /** Default URL stability threshold (5%). Tunable via wpc_byte_optimal_swap_delta. */
    const URL_STABILITY_DELTA_DEFAULT = 0.05;


    public static function eligible_formats_for_source($source_type)
    {
        switch ($source_type) {
            case 'avif': return ['avif', 'webp', 'jpeg'];
            case 'webp': return ['webp', 'jpeg'];
            case 'jpeg': return ['jpeg'];
            default: return [];
        }
    }


    public static function lookup_keys_for_width_format($width, $format)
    {
        $width = (int) $width;
        $jpeg_suffix = ($format === 'jpeg') ? '' : '-' . $format;
        return [
            $width . 'w' . $jpeg_suffix,
            'wpc_' . $width . $jpeg_suffix,
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


    public static function flush_size_backfill_on_shutdown()
    {
        if (empty(self::$pending_size_backfill)) return;
        // Never make a visitor wait on best-effort meta backfill: flush the response to the
        // client first, then yield entirely under load (the stale-lock sweep cron catches up).
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) { self::$pending_size_backfill = []; return; }
        global $wpdb;

        foreach (self::$pending_size_backfill as $aid => $size_map) {
            $aid = (int) $aid;
            if ($aid <= 0) continue;

            $lock_name = 'wpc_bg_meta_' . $aid;
            // Non-blocking (budget 0 = single attempt, no retry): never pin this visitor's
            // worker waiting on the shared bg-meta lock. If another writer holds it, skip —
            // the next render or the stale-lock sweep backfills this size.
            $locked = wpc_worker_lock($lock_name, 0);
            if (!$locked) { continue; }

            try {
                // Re-read inside the lock so we capture any bg-swap writes that
                // landed between this handler queuing the backfill and now.
                $variants = get_post_meta($aid, 'ic_local_variants', true);
                if (!is_array($variants)) continue;

                $changed = false;
                foreach ($size_map as $key => $size) {
                    if (!isset($variants[$key]) || !is_array($variants[$key])) continue;


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
                    wpc_worker_unlock($lock_name);
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
                if (empty($entry['url'])) continue;

                $size = self::size_or_backfill($attachment_id, $key, $entry);
                if ($size <= 0) continue;

                $candidates[] = [
                    'url'  => $entry['url'],
                    'size' => $size,
                    'fmt'  => $fmt,
                    'key'  => $key,
                ];
                break;
            }
        }

        usort($candidates, function ($a, $b) {
            return $a['size'] - $b['size'];
        });
        return $candidates;
    }


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


    /** @var array<string, true> Tier 1 dedupe — request-scoped memo */
    private static $backfill_request_memo = [];

    /** Default safety-net TTL for backfill locks (10 min). Tunable via wpc_backfill_lock_ttl. */
    const BACKFILL_LOCK_TTL_DEFAULT = 600;


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
                    return false;
                }
            }
        }

        // Tier 1: request-scoped dedupe
        $memo_key = $aid . ':' . $width . ':' . $format;
        if (isset(self::$backfill_request_memo[$memo_key])) return false;
        self::$backfill_request_memo[$memo_key] = true;


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
    private static $emit_buf = [];
    private static $emit_hooked = false;

    private static function log_emission_to_table($attachment_id, $width, $format, $page_url)
    {
        $aid = (int) $attachment_id;
        $width = (int) $width;
        if ($aid <= 0 || $width <= 0) return;
        if ($format !== 'avif' && $format !== 'webp' && $format !== 'jpeg') return;
        $page_hash = substr(md5((string) $page_url), 0, 8);
        $tk = $aid . ':' . $width . ':' . $format . ':' . $page_hash;
        if (isset(self::$emit_buf[$tk])) return;
        static $throttle = null;
        if ($throttle === null) {
            $throttle = function_exists('get_transient') ? get_transient('wpc_emit_seen') : [];
            if (!is_array($throttle)) $throttle = [];
        }
        if (isset($throttle[$tk])) return;
        self::$emit_buf[$tk] = [$aid, $width, $format, $page_hash];
        if (!self::$emit_hooked && function_exists('register_shutdown_function')) {
            self::$emit_hooked = true;
            register_shutdown_function(['WPC_Modern_Delivery', 'flush_emissions']);
        }
    }

    public static function flush_emissions()
    {
        global $wpdb;
        if (empty(self::$emit_buf) || !isset($wpdb)) return;
        $table = $wpdb->prefix . 'wpcompress_emissions';
        $now = current_time('mysql');
        $rows = [];
        foreach (self::$emit_buf as $e) {
            $rows[] = $wpdb->prepare('(%d, %d, %s, %s, 1, %s, %s)', $e[0], $e[1], $e[2], $e[3], $now, $now);
        }
        $wpdb->suppress_errors(true);
        $wpdb->query(
            "INSERT INTO {$table}
                (attachment_id, width, format, page_url_hash, emit_count, first_seen, last_seen)
             VALUES " . implode(',', $rows) . "
             ON DUPLICATE KEY UPDATE emit_count = emit_count + 1, last_seen = VALUES(last_seen)"
        );
        $wpdb->suppress_errors(false);
        if (function_exists('get_transient') && function_exists('set_transient')) {
            $th = get_transient('wpc_emit_seen');
            if (!is_array($th)) $th = [];
            foreach (self::$emit_buf as $tk2 => $e2) { $th[$tk2] = 1; }
            if (count($th) > 2000) { $th = array_slice($th, -2000, null, true); }
            set_transient('wpc_emit_seen', $th, 600);
        }
        self::$emit_buf = [];
    }

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


    private static function kick_single_variant_encode($attachment_id, $width, $format)
    {
        if (!function_exists('wpc_generate_ladder_widths')) return false;


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


// Idempotent + version-gated so no per-request overhead after first run.
// Activation hook in wp-compress-core.php also calls this for new installs.
add_action('plugins_loaded', ['WPC_Modern_Delivery', 'maybe_create_emissions_table'], 21);


// One update_post_meta per attachment regardless of variant count migrated.
// No-op when queue is empty (vast majority of requests).
add_action('shutdown', ['WPC_Modern_Delivery', 'flush_size_backfill_on_shutdown']);


// Self-schedules on plugins_loaded if not already scheduled. Fires the
// `wpc_sweep_stale_backfill_locks` action which clears any backfill lock


add_action('plugins_loaded', function () {
    if (!wp_next_scheduled('wpc_sweep_stale_backfill_locks')) {
        wp_schedule_event(time() + 600, 'hourly', 'wpc_sweep_stale_backfill_locks');
    }
}, 22);
add_action('wpc_sweep_stale_backfill_locks', ['WPC_Modern_Delivery', 'sweep_stale_backfill_locks']);
