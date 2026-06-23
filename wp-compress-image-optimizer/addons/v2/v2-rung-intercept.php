<?php
if (!defined('ABSPATH')) exit;

/**
 * Missing-variant rung intercept (Pixel-Optimal spec, Piece 4b / Strategy 1).
 *
 * A request for a NON-EXISTENT `-{W}x{H}.{ext}` file under uploads reaches WP as a 404
 * (nginx try_files falls through to index.php — verified on both pilot hosts). Today that
 * renders the full WP 404 page → in an <img>/srcset context the visitor gets a BROKEN image
 * (the ERR_BLOCKED_BY_ORB class), and the server pays the most expensive page type to say so.
 * This happens for real traffic: post-restore cached pages, stale srcset references, hotlinks.
 *
 * The intercept answers with a 302 to the NEAREST on-disk rung instead — the same graceful
 * interim semantics the CDN edge uses (Mode-B's 302/interim states), executed by the origin.
 * Working image, ~1-2ms, strictly cheaper than the 404 render it replaces. It is also the
 * foundation for origin-as-edge on-demand generation (the spec's Plan B): once ideal-width
 * rungs are emitted before their files exist, this is what serves the first picker.
 *
 * ULTRA-FAST CONTRACT (the request is already in WP's 404 path, so every branch below is
 * cheaper than the status quo, but still):
 *   - fires ONLY on is_404() — zero cost on every normal request;
 *   - strpos + one regex gate before anything touches the DB;
 *   - one attachment lookup (attachment_url_to_postid — indexed query; garbage stems return
 *     0 and fall through to the normal 404);
 *   - candidate scan = a handful of file_exists() stats against registered metadata sizes
 *     (+ the deterministic -WxH adaptive names already on disk via ic_local_variants);
 *   - 302 with Cache-Control: no-store (nothing may pin the interim past the real file
 *     landing) + an X-WPC-Rung header (observability + the capability probe for Plan B).
 *
 * It never invents content: same-attachment files only, smallest rung ≥ the requested width
 * (else the largest available). If nothing usable exists, it falls through to the normal 404
 * — behaviour identical to today. Kill switch: WPC_RUNG_INTERCEPT_OFF or the filter.
 */
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

        $dir_url  = $m[1];                   // e.g. /wp-content/uploads/2026/05/
        $stem     = $m[2];                   // e.g. puscas-adryan-..._E-unsplash
        $want_w   = (int) $m[3];
        $req_ext  = strtolower($m[5]);

        // Resolve the attachment from the stem (canonical .jpg first, -scaled retry — the same
        // resolution dance the lazy derive uses). Garbage stems cost one indexed query, then
        // fall through to the normal 404.
        if (!function_exists('attachment_url_to_postid')) return;
        $site = untrailingslashit(site_url());
        $att  = 0;
        foreach (['.jpg', '.jpeg', '.png', '-scaled.jpg', '-scaled.jpeg', '-scaled.png'] as $cand_ext) {
            $att = (int) attachment_url_to_postid($site . $dir_url . $stem . $cand_ext);
            if ($att > 0) break;
        }
        if ($att <= 0) return;

        $meta = wp_get_attachment_metadata($att);
        if (!is_array($meta) || empty($meta['file'])) return;
        $up = wp_get_upload_dir();
        if (empty($up['basedir']) || empty($up['baseurl'])) return;

        // Exact-exists guard: if the REQUESTED file is actually on disk but the
        // request still reached WP's 404 path (perm/MIME/handoff edge), STREAM it with the
        // correct Content-Type instead of scanning rungs — a nearest-scan here could pick the
        // exact same path and 302 the URL to itself. Long cache (it's the real static asset).
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

        // Candidate widths: registered sizes + the main file + on-disk adaptive names from
        // ic_local_variants ({W}x{H}[-fmt] labels — the deterministic naming).
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
                    if ($aw > 0 && !isset($widths[$aw])) $widths[$aw] = $stem . '-' . $am[1] . 'x' . $am[2] . '.x'; // ext resolved below
                }
            }
        }
        if (empty($widths)) return;
        ksort($widths);

        // Pick the smallest on-disk rung ≥ requested width in the REQUESTED ext, falling back
        // through webp→source ext (a browser that asked for any modern format decodes both);
        // if none ≥, take the largest available. Each candidate is one stat().
        $orig_ext  = strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION));
        $try_exts  = array_values(array_unique([$req_ext === 'jpeg' ? 'jpg' : $req_ext, 'webp', $orig_ext]));
        $pick      = '';
        foreach ([true, false] as $want_geq) { // pass 1: ≥ width ascending; pass 2: < width descending
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
        if ($pick === '') return; // nothing on disk → normal 404 (identical to today)

        // Phase 2 (live wiring): queue on-demand generation of the EXACT requested width —
        // plugin-signed sized-trigger → orch encode → pull lands the file → the next request
        // for this URL is a plain 200 static file. Origin-as-edge, complete: this visitor gets
        // the nearest rung NOW (302 below), the next one gets the exact width. Self-guarded
        // (natural-width cap, skip-if-on-disk, 15-min burst guard, ≤6/req batch) and soft-fails
        // until orch v3.22.21 deploys the endpoint.
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
