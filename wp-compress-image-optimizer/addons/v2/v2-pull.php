<?php
/**
 * WP Compress — Pull-delivery helper.
 *
 * Parallel byte fetcher for service-team's PULL DELIVERY architecture
 * (v3.18.27+). Service uploads encoded variant bytes to BunnyCDN and ships
 * tiny URL-only payloads in /bg_swap{,_batch} callbacks. Plugin pulls the
 * bytes from CDN via parallel HTTP fetches.
 *
 * Why parallel: sequential wp_remote_get() per variant in a 25-variant batch
 * would be ~25 × ~300 ms = ~7.5 s of FPM-worker hold time per batch — net
 * regression vs current ~200 ms inline base64 decode. curl_multi() with
 * HTTP/2 keep-alive collapses that to ~max(per-pull) ≈ 500 ms-1 s per batch.
 *
 * Schema received from service (per fetchUrl variant entry):
 *
 *   {
 *     "sizeLabel":     "thumbnail",
 *     "format":        "avif",
 *     "fetchUrl":      "https://wpc-v2-variants.b-cdn.net/v2/.../avif-thumbnail.avif",
 *     "bytesUrl":      "https://wpc-v2-variants.b-cdn.net/...",   // transitional alias
 *     "bytesSize":     1742,
 *     "bytesSha256":   "a7b3...",
 *     ...other normal variant metadata
 *   }
 *
 * Fallbacks: if a variant ships with BOTH fetchUrl AND bytesB64, base64 wins
 * (no network call). If fetchUrl pull fails (timeout, 5xx, integrity
 * mismatch), service has a retry path that will re-send the variant with
 * inline bytesB64 — plugin just drops the bad pull and reports rejected.
 *
 * Path A scope: pulls happen INLINE in the REST handler (collected first
 * pass, single curl_multi, second pass writes bytes to disk). Adds ~500 ms-1 s
 * to per-batch handler time vs current inline path, but pays it back via 200×
 * smaller POST payload + per-variant network elimination.
 *
 * Path B follow-up will move the pull from the handler into the journal
 * drain — handler returns in ~50 ms (journal write only), drain absorbs the
 * curl_multi work. Same helper, different caller. ~30 LOC diff from Path A.
 *
 * @see addons/v2/v2-callback.php  — batch + single handler callers
 * @see addons/v2/v2-client.php    — advertises deliveryMode in envelope
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_pull_delivery_enabled')) {

/**
 * Flag check. Default flipped 0 → 1 after the service team confirmed
 * orch v3.18.27+ is fully ready (verified on staging 2026-05-24). Existing
 * customers retain their saved value; only fresh installs land on pull-on
 * by default. Filter `wpc_v2_pull_delivery_enabled` for runtime override
 * (e.g. hosts with weird CDN/fetchUrl behavior can force off via add_filter
 * without a plugin downgrade).
 */
function wpc_v2_pull_delivery_enabled() {
    $enabled = ((int) get_option('wpc_v2_pull_delivery_enabled', 1) === 1);
    return (bool) apply_filters('wpc_v2_pull_delivery_enabled', $enabled);
}

/**
 * Parallel HTTP fetch via curl_multi. Returns [idx => bytes] for successful
 * pulls; missing keys = pulls that failed (caller treats as rejected).
 *
 * @param array $urls      [idx => 'https://...']  variant-keyed URL map
 * @param array $meta      [idx => ['size' => int|null, 'sha256' => string|null]]
 *                         Integrity check inputs; null/missing = skip check
 * @param array $opts      Tuning overrides:
 *                          - connect_timeout_ms (default 1000)
 *                          - total_timeout_ms   (default 10000)
 *                          - max_redirs         (default 2)
 *                          - prefer_http2       (default true)
 * @return array           [idx => bytes]  Pulls that succeeded + verified.
 */
function wpc_v2_parallel_pull(array $urls, array $meta = [], array $opts = []) {
    if (empty($urls)) {
        return [];
    }
    if (!function_exists('curl_multi_init')) {
        // Fallback: sequential wp_remote_get. Slow but correct.
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
            CURLOPT_ENCODING        => '',                           // accept gzip/deflate/br
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

    // Drive the multi exec loop. curl_multi_select blocks briefly waiting on
    // socket activity; cap at 0.5 s per iteration so we don't hang on a
    // single slow pull (the per-handle CURLOPT_TIMEOUT_MS is the real cap).
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

        // Integrity checks against service-advertised size + sha256.
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

/**
 * Fallback used only when curl_multi is unavailable (extremely rare — every
 * mainstream PHP build has it). Sequential wp_remote_get, no parallelism.
 * Logged as a warning so we can spot hosts that need attention.
 */
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

}  // end if (!function_exists)
