<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_rendered_width_beacon_enabled')) {
    function wpc_v2_rendered_width_beacon_enabled()
    {
        $opt = function_exists('get_option') ? get_option('wpc_v2_rendered_width_beacon_enabled', 0) : 0;
        return (bool) apply_filters('wpc_v2_rendered_width_beacon_enabled', !empty($opt));
    }
}

if (!function_exists('wpc_v2_rw_beacon_receive')) {

    function wpc_v2_rw_beacon_receive()
    {
        if (!wpc_v2_rendered_width_beacon_enabled()) {
            wp_send_json_error('disabled', 403);
        }

        // Light validation only: don't die on a bad/expired nonce (anonymous page caches), just bail.
        if (function_exists('check_ajax_referer') && !check_ajax_referer('wpc_rw_beacon', 'nonce', false)) {

        }

        $raw     = isset($_POST['samples']) ? wp_unslash($_POST['samples']) : '';
        $samples = json_decode((string) $raw, true);
        if (!is_array($samples) || empty($samples)) {
            wp_send_json_error('no-samples');
        }

        $store = get_option('wpc_v2_rw_beacon_samples', []);
        if (!is_array($store)) {
            $store = [];
        }

        $now   = time();
        $added = 0;
        foreach ($samples as $s) {
            if (!is_array($s)) {
                continue;
            }
            $url      = isset($s['u']) ? esc_url_raw((string) $s['u']) : '';
            $rendered = isset($s['r']) ? (int) $s['r'] : 0;
            $natural  = isset($s['n']) ? (int) $s['n'] : 0;
            $dpr      = isset($s['d']) ? round((float) $s['d'], 2) : 1.0;
            if ($url === '' || $rendered <= 0) {
                continue;
            }
            $store[] = ['u' => $url, 'r' => $rendered, 'n' => $natural, 'd' => $dpr, 't' => $now];
            if (++$added >= 50) {
                break;
            }
        }

        // Rolling window (newest last), hard-capped so the option can't grow unbounded.
        if (count($store) > 500) {
            $store = array_slice($store, -500);
        }
        update_option('wpc_v2_rw_beacon_samples', $store, false);

        wp_send_json_success(['stored' => $added]);
    }
}

// Register the endpoint ONLY when enabled (priv + nopriv — most visitors are anonymous).
if (wpc_v2_rendered_width_beacon_enabled()) {
    add_action('wp_ajax_wpc_v2_rw_beacon',        'wpc_v2_rw_beacon_receive');
    add_action('wp_ajax_nopriv_wpc_v2_rw_beacon', 'wpc_v2_rw_beacon_receive');
}
