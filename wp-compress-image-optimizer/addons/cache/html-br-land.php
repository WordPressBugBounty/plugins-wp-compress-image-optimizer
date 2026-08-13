<?php
/**
 * v7.10.656 — Brotli HTML land handler (spec §3).
 *
 * Lives in its own file for the same two reasons v2-store.php does, both real:
 *
 * 1. SECURITY. The bytes now reach disk through wpc_v2_store_bytes655(), which enforces
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

if (!function_exists('wpc_html_br_land647')) {
    function wpc_html_br_land647()
    {
        try {
            if (!apply_filters('wpc_html_br', true) || !defined('WPS_IC_CACHE')) {
                wp_send_json_error('off');
            }
            $wpc_ak647 = isset($_POST['apikey']) ? (string) $_POST['apikey'] : '';
            $wpc_ck647 = function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '';
            if ($wpc_ak647 === '' || $wpc_ck647 === '' || !hash_equals($wpc_ck647, $wpc_ak647)) {
                wp_send_json_error('auth');
            }
            $wpc_key647 = isset($_POST['url_key']) ? basename((string) $_POST['url_key']) : '';
            $wpc_md647 = isset($_POST['html_md5']) ? strtolower(trim((string) $_POST['html_md5'])) : '';
            $wpc_b64647 = isset($_POST['br_b64']) ? (string) $_POST['br_b64'] : '';
            if ($wpc_key647 === '' || !preg_match('/^[a-f0-9]{32}$/', $wpc_md647) || $wpc_b64647 === '') {
                wp_send_json_error('args');
            }
            $wpc_dir647 = rtrim(WPS_IC_CACHE, '/') . '/' . $wpc_key647 . '/';
            $wpc_side647 = strtolower(trim((string) @file_get_contents($wpc_dir647 . 'index.html_md5')));
            if (!@is_file($wpc_dir647 . 'index.html_gzip') || $wpc_side647 !== $wpc_md647) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('br-stale-discard', $wpc_key647, '', []);
                }
                wp_send_json_success(['mode' => 'stale-discard']);
            }
            $wpc_br647 = base64_decode($wpc_b64647, true);
            $wpc_gz647 = (int) @filesize($wpc_dir647 . 'index.html_gzip');
            if ($wpc_br647 === false || strlen($wpc_br647) < 512
                || strlen($wpc_br647) > 2097152 || ($wpc_gz647 > 0 && strlen($wpc_br647) >= $wpc_gz647)) {
                wp_send_json_error('size');
            }
            if (!function_exists('wpc_v2_store_bytes655')) {
                @include_once __DIR__ . '/../v2/v2-store.php';
            }
            if (!function_exists('wpc_v2_store_bytes655')) {
                wp_send_json_error('write');
            }
            // html_br is not an image: the image default is widened explicitly, and the
            // root is pinned to the cache tree so no url_key can place bytes outside it.
            $wpc_put647 = wpc_v2_store_bytes655($wpc_br647, $wpc_dir647 . 'index.html_br', [
                'root' => WPS_IC_CACHE,
                'exts' => ['html_br'],
            ]);
            if (empty($wpc_put647['ok'])) {
                wp_send_json_error('write');
            }
            // R1 belt: re-check AFTER the write — a render that raced us rewrote the
            // sidecar; our blob pairs with the OLD html and must not survive.
            if (strtolower(trim((string) @file_get_contents($wpc_dir647 . 'index.html_md5'))) !== $wpc_md647) {
                @unlink($wpc_dir647 . 'index.html_br');
                wp_send_json_success(['mode' => 'race-discard']);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('br-land', $wpc_key647, '', ['bytes' => strlen($wpc_br647)]);
            }
            wp_send_json_success(['mode' => 'landed', 'bytes' => strlen($wpc_br647)]);
        } catch (\Throwable $e) {
            wp_send_json_error('err');
        }
    }
    add_action('wp_ajax_nopriv_wpc_html_br_land', 'wpc_html_br_land647');
    add_action('wp_ajax_wpc_html_br_land', 'wpc_html_br_land647');
}
