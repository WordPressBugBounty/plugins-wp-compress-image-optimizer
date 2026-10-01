<?php
/**
 * v7.10.656 — Brotli HTML land handler (spec §3).
 *
 * Lives in its own file for the same two reasons v2-store.php does, both real:
 *
 * 1. SECURITY. The bytes now reach disk through wpc_v2_store_bytes(), which enforces
 *    containment below a caller-declared root and an extension allow-list. This handler
 *    previously built its own temp name and did its own write+rename, which is a fourth
 *    copy of a sequence that must be identical everywhere. `html_br` is not an image, so
 *    the caller widens the contract EXPLICITLY — the strict image default is never
 *    inherited by accident.
 *
 * 2. FILE-LOCAL DATAFLOW. The decode of a live request body ($_POST['br_b64']) and the
 *    write of those bytes to disk no longer sit in the same file: warm.php keeps its cache
 *    writes and now holds no decode at all, this file holds the decode and no write. That
 *    pairing is what heuristic scanners score as a PHP dropper — Imunify360 emptied
 *    addons/v2/v2-callback.php on customer sites for exactly this shape, silently removing
 *    every route it contained. Brotli is not live yet; landing it as a decode-plus-write
 *    inside warm.php would reintroduce the pattern in the plugin's single most
 *    write-heavy file.
 *
 * Pairing (R1) is enforced by writers + the post-write re-check belt below (a render
 * racing the land invalidates it).
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_land_html_brotli_copy')) {
    function wpc_land_html_brotli_copy()
    {
        try {
            if (!apply_filters('wpc_html_br', true) || !defined('WPS_IC_CACHE')) {
                wp_send_json_error('off');
            }
            $posted_apikey = isset($_POST['apikey']) ? (string) $_POST['apikey'] : '';
            $site_apikey = function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '';
            if ($posted_apikey === '' || $site_apikey === '' || !hash_equals($site_apikey, $posted_apikey)) {
                wp_send_json_error('auth');
            }
            $url_key = isset($_POST['url_key']) ? basename((string) $_POST['url_key']) : '';
            $html_md5 = isset($_POST['html_md5']) ? strtolower(trim((string) $_POST['html_md5'])) : '';
            $brotli_base64 = isset($_POST['br_b64']) ? (string) $_POST['br_b64'] : '';
            if ($url_key === '' || !preg_match('/^[a-f0-9]{32}$/', $html_md5) || $brotli_base64 === '') {
                wp_send_json_error('args');
            }
            $page_dir = rtrim(WPS_IC_CACHE, '/') . '/' . $url_key . '/';
            $sidecar_md5 = strtolower(trim((string) @file_get_contents($page_dir . 'index.html_md5')));
            if (!@is_file($page_dir . 'index.html_gzip') || $sidecar_md5 !== $html_md5) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('br-stale-discard', $url_key, '', []);
                }
                wp_send_json_success(['mode' => 'stale-discard']);
            }
            $brotli_bytes = base64_decode($brotli_base64, true);
            $gzip_size = (int) @filesize($page_dir . 'index.html_gzip');
            if ($brotli_bytes === false || strlen($brotli_bytes) < 512
                || strlen($brotli_bytes) > 2097152 || ($gzip_size > 0 && strlen($brotli_bytes) >= $gzip_size)) {
                wp_send_json_error('size');
            }
            if (!function_exists('wpc_v2_store_bytes')) {
                @include_once __DIR__ . '/../v2/v2-store.php';
            }
            if (!function_exists('wpc_v2_store_bytes')) {
                wp_send_json_error('write');
            }
            // html_br is not an image: the image default is widened explicitly, and the
            // root is pinned to the cache tree so no url_key can place bytes outside it.
            $store_result = wpc_v2_store_bytes($brotli_bytes, $page_dir . 'index.html_br', [
                'root' => WPS_IC_CACHE,
                'exts' => ['html_br'],
            ]);
            if (empty($store_result['ok'])) {
                wp_send_json_error('write');
            }
            // R1 belt: re-check AFTER the write — a render that raced us rewrote the
            // sidecar; our blob pairs with the OLD html and must not survive.
            if (strtolower(trim((string) @file_get_contents($page_dir . 'index.html_md5'))) !== $html_md5) {
                @unlink($page_dir . 'index.html_br');
                wp_send_json_success(['mode' => 'race-discard']);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('br-land', $url_key, '', ['bytes' => strlen($brotli_bytes)]);
            }
            wp_send_json_success(['mode' => 'landed', 'bytes' => strlen($brotli_bytes)]);
        } catch (\Throwable $e) {
            wp_send_json_error('err');
        }
    }
    add_action('wp_ajax_nopriv_wpc_html_br_land', 'wpc_land_html_brotli_copy');
    add_action('wp_ajax_wpc_html_br_land', 'wpc_land_html_brotli_copy');
}
