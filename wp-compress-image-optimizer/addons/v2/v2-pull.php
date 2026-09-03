<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-pull.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_pull_delivery_enabled')) {


function wpc_v2_pull_delivery_enabled() {
    $enabled = ((int) get_option('wpc_v2_pull_delivery_enabled', 1) === 1);
    return (bool) apply_filters('wpc_v2_pull_delivery_enabled', $enabled);
}


function wpc_v2_parallel_pull(array $urls, array $meta = [], array $opts = []) {
    if (empty($urls)) {
        return [];
    }
    if (!function_exists('curl_multi_init')) {
        
        return wpc_v2_pull_sequential_fallback($urls, $meta, $opts);
    }

    $connect_ms = isset($opts['connect_timeout_ms']) ? max(100, (int) $opts['connect_timeout_ms']) : 1000;
    $total_ms   = isset($opts['total_timeout_ms'])   ? max(1000, (int) $opts['total_timeout_ms']) : 10000;
    $max_redirs = isset($opts['max_redirs'])         ? max(0, (int) $opts['max_redirs']) : 2;
    $prefer_h2  = !isset($opts['prefer_http2']) || (bool) $opts['prefer_http2'];

    $mh      = curl_multi_init();
    $handles = [];

    foreach ($urls as $idx => $url) {
        if (!is_string($url) || $url === '') {
            continue;
        }
        $ch = curl_init();
        $copts = [
            CURLOPT_URL             => $url,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT_MS => $connect_ms,
            CURLOPT_TIMEOUT_MS      => $total_ms,
            CURLOPT_FOLLOWLOCATION  => $max_redirs > 0,
            CURLOPT_MAXREDIRS       => $max_redirs,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_ENCODING        => '',
            CURLOPT_USERAGENT       => 'WPCV2Pull/1.0',
            CURLOPT_HTTPHEADER      => ['Accept: */*'],
        ];
        if ($prefer_h2 && defined('CURL_HTTP_VERSION_2TLS')) {
            $copts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }
        curl_setopt_array($ch, $copts);
        curl_multi_add_handle($mh, $ch);
        $handles[$idx] = $ch;
    }

    if (empty($handles)) {
        curl_multi_close($mh);
        return [];
    }


    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh, 0.5);
        }
    } while ($active && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $idx => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($code !== 200 || !is_string($body) || $body === '') {
            error_log(sprintf(
                '[wpc_v2_parallel_pull] idx=%s http=%d err=%s',
                $idx, $code, $err !== '' ? substr($err, 0, 80) : '-'
            ));
            continue;
        }

        
        $exp_size   = isset($meta[$idx]['size'])   ? (int) $meta[$idx]['size']   : null;
        $exp_sha256 = isset($meta[$idx]['sha256']) ? (string) $meta[$idx]['sha256'] : null;

        if ($exp_size !== null && $exp_size > 0 && strlen($body) !== $exp_size) {
            error_log(sprintf(
                '[wpc_v2_parallel_pull] idx=%s size_mismatch got=%d expected=%d',
                $idx, strlen($body), $exp_size
            ));
            continue;
        }
        if ($exp_sha256 !== null && $exp_sha256 !== '' && !hash_equals($exp_sha256, hash('sha256', $body))) {
            error_log(sprintf('[wpc_v2_parallel_pull] idx=%s sha256_mismatch', $idx));
            continue;
        }

        $results[$idx] = $body;
    }

    curl_multi_close($mh);
    return $results;
}






function wpc_v2_pull_sequential_fallback(array $urls, array $meta = [], array $opts = []) {
    error_log('[wpc_v2_parallel_pull] curl_multi unavailable, falling back to sequential wp_remote_get');
    $total_s = isset($opts['total_timeout_ms']) ? max(1, (int) round($opts['total_timeout_ms'] / 1000)) : 10;

    $results = [];
    foreach ($urls as $idx => $url) {
        if (!is_string($url) || $url === '') {
            continue;
        }
        $r = wp_remote_get($url, ['timeout' => $total_s, 'sslverify' => true]);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            continue;
        }
        $body = wp_remote_retrieve_body($r);
        if (!is_string($body) || $body === '') {
            continue;
        }
        $exp_size   = isset($meta[$idx]['size'])   ? (int) $meta[$idx]['size']   : null;
        $exp_sha256 = isset($meta[$idx]['sha256']) ? (string) $meta[$idx]['sha256'] : null;
        if ($exp_size !== null && $exp_size > 0 && strlen($body) !== $exp_size) {
            continue;
        }
        if ($exp_sha256 !== null && $exp_sha256 !== '' && !hash_equals($exp_sha256, hash('sha256', $body))) {
            continue;
        }
        $results[$idx] = $body;
    }
    return $results;
}

}
