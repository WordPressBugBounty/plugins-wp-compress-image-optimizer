<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-rung-intercept.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */

if (!defined('ABSPATH')) exit;


if (!function_exists('wpc_v2_rung_intercept')) {
    function wpc_v2_rung_intercept()
    {
        if (!is_404()) return;
        if (defined('WPC_RUNG_INTERCEPT_OFF') && WPC_RUNG_INTERCEPT_OFF) return;

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if ($uri === '' || strpos($uri, '/uploads/') === false) return;
        $path = (string) parse_url($uri, PHP_URL_PATH);
        if (!preg_match('#^(.*/)([^/]+)-(\d{2,4})x(\d{2,4})\.(avif|webp|jpe?g|png)$#i', $path, $m)) return;
        if (!apply_filters('wpc_rung_intercept_enabled', true)) return;

        $dir_url  = $m[1];                   
        $stem     = $m[2];                   
        $want_w   = (int) $m[3];
        $req_ext  = strtolower($m[5]);


        if (!function_exists('attachment_url_to_postid')) return;
        $site = untrailingslashit(site_url());
        $att  = 0;
        foreach (['.jpg', '.jpeg', '.png', '-scaled.jpg', '-scaled.jpeg', '-scaled.png'] as $cand_ext) {
            $att = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_att_id'))
                ? (int) wps_rewriteLogic::wpc_att_id($site . $dir_url . $stem . $cand_ext)
                : (int) attachment_url_to_postid($site . $dir_url . $stem . $cand_ext);
            if ($att > 0) break;
        }
        if ($att <= 0) return;

        $meta = wp_get_attachment_metadata($att);
        if (!is_array($meta) || empty($meta['file'])) return;
        $up = wp_get_upload_dir();
        if (empty($up['basedir']) || empty($up['baseurl'])) return;


        $exact_abs = rtrim($up['basedir'], '/') . (strpos($path, '/uploads/') !== false ? substr($path, strpos($path, '/uploads/') + strlen('/uploads')) : '');
        if ($exact_abs !== rtrim($up['basedir'], '/') && @is_file($exact_abs) && !headers_sent()) {
            $mime = ['avif' => 'image/avif', 'webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
            status_header(200);
            header('Content-Type: ' . $mime[$req_ext === 'jpeg' ? 'jpg' : $req_ext]);
            header('Content-Length: ' . (string) filesize($exact_abs));
            header('Cache-Control: public, max-age=31536000, immutable');
            header('X-WPC-Rung: exact-stream');
            readfile($exact_abs);
            exit;
        }
        $subdir = (strpos($meta['file'], '/') !== false) ? substr($meta['file'], 0, strrpos($meta['file'], '/') + 1) : '';
        $base   = rtrim($up['basedir'], '/') . '/' . $subdir;

        
        
        $widths = [];
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sz) {
                if (!empty($sz['file']) && !empty($sz['width'])) $widths[(int) $sz['width']] = basename((string) $sz['file']);
            }
        }
        if (!empty($meta['width'])) $widths[(int) $meta['width']] = basename((string) $meta['file']);
        $lv = get_post_meta($att, 'ic_local_variants', true);
        if (is_array($lv)) {
            foreach ($lv as $lk => $unused) {
                if (preg_match('/^(\d+)x(\d+)(?:-[a-z0-9]+)?$/', (string) $lk, $am)) {
                    $aw = (int) $am[1];
                    if ($aw > 0 && !isset($widths[$aw])) $widths[$aw] = $stem . '-' . $am[1] . 'x' . $am[2] . '.x';
                }
            }
        }
        if (empty($widths)) return;
        ksort($widths);


        $orig_ext  = strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION));
        $try_exts  = array_values(array_unique([$req_ext === 'jpeg' ? 'jpg' : $req_ext, 'webp', $orig_ext]));
        $pick      = '';
        foreach ([true, false] as $want_geq) {
            $ws = array_keys($widths);
            if (!$want_geq) rsort($ws);
            foreach ($ws as $w) {
                if ($want_geq ? ($w < $want_w) : ($w >= $want_w)) continue;
                $fname_base = preg_replace('/\.[^.]+$/', '', (string) $widths[$w]);
                foreach ($try_exts as $xt) {
                    if ($xt !== '' && @file_exists($base . $fname_base . '.' . $xt)) {
                        $pick = $subdir . $fname_base . '.' . $xt;
                        break 3;
                    }
                }
            }
        }
        if ($pick === '') return;


        if (function_exists('wpc_v2_sized_trigger_queue')) {
            wpc_v2_sized_trigger_queue($att, $want_w, $want_w);
        }
        if (!headers_sent()) {
            header('Cache-Control: no-store, max-age=0');
            header('X-WPC-Rung: nearest;want=' . $want_w);
            wp_redirect(rtrim($up['baseurl'], '/') . '/' . ltrim($pick, '/'), 302);
            exit;
        }
    }
    add_action('template_redirect', 'wpc_v2_rung_intercept', 0);
}
