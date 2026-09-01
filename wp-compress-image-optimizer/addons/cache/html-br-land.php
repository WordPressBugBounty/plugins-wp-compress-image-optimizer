<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cache/html-br-land.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.353
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
            
            
            $wpc_put647 = wpc_v2_store_bytes655($wpc_br647, $wpc_dir647 . 'index.html_br', [
                'root' => WPS_IC_CACHE,
                'exts' => ['html_br'],
            ]);
            if (empty($wpc_put647['ok'])) {
                wp_send_json_error('write');
            }
            
            
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
