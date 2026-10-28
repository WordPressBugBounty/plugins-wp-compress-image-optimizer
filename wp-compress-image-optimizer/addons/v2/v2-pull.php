<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_pull_delivery_enabled')) {


function wpc_v2_pull_delivery_enabled() {
    $enabled = ((int) get_option('wpc_v2_pull_delivery_enabled', 1) === 1);
    return (bool) apply_filters('wpc_v2_pull_delivery_enabled', $enabled);
}


if (!function_exists('wpc_v2_pull_now')) {

    function wpc_v2_pull_now() {
        return microtime(true);
    }
}

if (!function_exists('wpc_v2_pull_open')) {

    function wpc_v2_pull_open() {
        $ctx       = new stdClass();
        $ctx->mh   = curl_multi_init();
        $ctx->live = [];
        $ctx->seq  = 0;
        return $ctx;
    }
}

if (!function_exists('wpc_v2_pull_add')) {

    function wpc_v2_pull_add($ctx, $idx, $url, array $o) {
        $ch = curl_init();
        if (!$ch) {
            return false;
        }
        $copts = [
            CURLOPT_URL             => $url,
            CURLOPT_RETURNTRANSFER  => true,
            CURLOPT_CONNECTTIMEOUT_MS => $o['connect_ms'],
            CURLOPT_TIMEOUT_MS      => $o['total_ms'],
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_ENCODING        => '',
            CURLOPT_USERAGENT       => 'WPCV2Pull/1.0',
            CURLOPT_HTTPHEADER      => ['Accept: */*'],
            CURLOPT_PRIVATE         => (string) $ctx->seq,
        ];
        if (!empty($o['max_bytes'])) {
            $copts[CURLOPT_MAXFILESIZE] = (int) $o['max_bytes'];
            $copts[CURLOPT_NOPROGRESS] = false;
            $maxBytes = (int) $o['max_bytes'];
            $copts[CURLOPT_PROGRESSFUNCTION] = function ($handle, $dlTotal, $dlNow) use ($maxBytes) {
                return $dlNow > $maxBytes ? 1 : 0;
            };
        }
        if ($o['prefer_h2'] && defined('CURL_HTTP_VERSION_2TLS')) {
            $copts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
        }
        curl_setopt_array($ch, $copts);
        if (curl_multi_add_handle($ctx->mh, $ch) !== 0) {
            curl_close($ch);
            return false;
        }
        $ctx->live[$ctx->seq] = [$idx, $ch];
        $ctx->seq++;
        return true;
    }
}

if (!function_exists('wpc_v2_pull_harvest')) {

    function wpc_v2_pull_harvest($ctx) {
        $done = [];
        while ($info = curl_multi_info_read($ctx->mh)) {
            $ch  = $info['handle'];
            $tok = (int) curl_getinfo($ch, CURLINFO_PRIVATE);
            if (!isset($ctx->live[$tok])) {
                continue;
            }
            $done[$ctx->live[$tok][0]] = [
                'body' => curl_multi_getcontent($ch),
                'code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'err'  => curl_error($ch),
                'loc'  => (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL),
                'res'  => isset($info['result']) ? (int) $info['result'] : 0,
            ];
            curl_multi_remove_handle($ctx->mh, $ch);
            curl_close($ch);
            unset($ctx->live[$tok]);
        }
        return $done;
    }
}

if (!function_exists('wpc_v2_pull_wait')) {

    function wpc_v2_pull_wait($ctx, $max_s) {
        $active = 0;
        $status = CURLM_OK;
        do {
            $status = curl_multi_exec($ctx->mh, $active);
        } while ($status === CURLM_CALL_MULTI_PERFORM);
        $done = wpc_v2_pull_harvest($ctx);

        if ($done === [] && $active && $status === CURLM_OK) {
            if (curl_multi_select($ctx->mh, $max_s) === -1) {
                usleep(1000);
            }
            do {
                $status = curl_multi_exec($ctx->mh, $active);
            } while ($status === CURLM_CALL_MULTI_PERFORM);
            $done = wpc_v2_pull_harvest($ctx);
        }

        if ($ctx->live && ($status !== CURLM_OK || !$active)) {
            foreach ($ctx->live as $tok => $pair) {
                $done[$pair[0]] = ['body' => '', 'code' => 0, 'err' => 'curl_multi status ' . (int) $status];
                curl_multi_remove_handle($ctx->mh, $pair[1]);
                curl_close($pair[1]);
                unset($ctx->live[$tok]);
            }
        }
        return $done;
    }
}

if (!function_exists('wpc_v2_pull_close')) {

    function wpc_v2_pull_close($ctx) {
        foreach ($ctx->live as $tok => $pair) {
            curl_multi_remove_handle($ctx->mh, $pair[1]);
            curl_close($pair[1]);
            unset($ctx->live[$tok]);
        }
        curl_multi_close($ctx->mh);
    }
}

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

    $overall_ms = isset($opts['overall_timeout_ms']) ? max(1000, (int) $opts['overall_timeout_ms']) : $total_ms;
    $deadline   = wpc_v2_pull_now() + ($overall_ms / 1000);

    $guard_on  = function_exists('wpc_v2_pull_host_guard_on') && wpc_v2_pull_host_guard_on();
    $max_bytes = ($guard_on && function_exists('wpc_v2_pull_max_bytes')) ? wpc_v2_pull_max_bytes() : 0;
    $fetch_opts = [
        'connect_ms' => $connect_ms,
        'total_ms'   => $total_ms,
        'max_redirs' => $max_redirs,
        'prefer_h2'  => $prefer_h2,
        'max_bytes'  => $max_bytes,
    ];
    $hops = [];

    $todo = [];
    foreach ($urls as $idx => $url) {
        if (!is_string($url) || $url === '') {
            continue;
        }
        if ($guard_on && !wpc_v2_pull_fetch_allowed($url)) {
            error_log(sprintf('[wpc_v2_parallel_pull] idx=%s foreign_host host=%s', $idx, substr((string) parse_url($url, PHP_URL_HOST), 0, 80)));
            continue;
        }
        $todo[$idx] = $url;
    }

    if (empty($todo)) {
        return [];
    }

    $cap     = max(1, (int) apply_filters('wpc_v2_pull_concurrency', 24));
    $slot_ms = max(4000, (int) floor($overall_ms / 2));
    $order   = array_keys($todo);
    $total   = count($order);
    $next    = 0;
    $flying  = 0;
    $open    = true;
    $fetched = [];
    $ctx     = wpc_v2_pull_open();
    while (true) {
        while ($open && $flying < $cap && $next < $total) {
            $remaining_ms = (int) floor(($deadline - wpc_v2_pull_now()) * 1000);
            if ($remaining_ms < 500) {
                $open = false;
                break;
            }
            $idx = $order[$next++];
            $one = $fetch_opts;
            $one['connect_ms'] = min($connect_ms, $remaining_ms);
            $one['total_ms']   = min($total_ms, $remaining_ms, $slot_ms);
            if (wpc_v2_pull_add($ctx, $idx, $todo[$idx], $one)) {
                $flying++;
            } else {
                $fetched[$idx] = ['code' => 0, 'body' => '', 'err' => 'handle_not_added'];
            }
        }
        if ($flying === 0) {
            break;
        }
        foreach (wpc_v2_pull_wait($ctx, 0.5) as $idx => $got) {
            $loc = isset($got['loc']) ? (string) $got['loc'] : '';
            if ($loc !== '' && $got['code'] >= 300 && $got['code'] < 400 && $max_redirs > 0) {
                $hops[$idx] = isset($hops[$idx]) ? $hops[$idx] + 1 : 1;
                $remaining_ms = (int) floor(($deadline - wpc_v2_pull_now()) * 1000);
                if ($hops[$idx] > $max_redirs) {
                    $got = ['code' => 0, 'body' => '', 'err' => 'too_many_redirects'];
                } elseif ($guard_on && !wpc_v2_pull_fetch_allowed($loc)) {
                    $got = ['code' => 0, 'body' => '', 'err' => 'redirect_foreign_host'];
                } elseif ($remaining_ms >= 500) {
                    $one = $fetch_opts;
                    $one['connect_ms'] = min($connect_ms, $remaining_ms);
                    $one['total_ms']   = min($total_ms, $remaining_ms, $slot_ms);
                    if (wpc_v2_pull_add($ctx, $idx, $loc, $one)) {
                        continue;
                    }
                    $got = ['code' => 0, 'body' => '', 'err' => 'handle_not_added'];
                } else {
                    $got = ['code' => 0, 'body' => '', 'err' => 'deadline_not_attempted'];
                }
            }
            $fetched[$idx] = $got;
            $flying--;
        }
    }
    wpc_v2_pull_close($ctx);

    $results = [];
    foreach ($todo as $idx => $unused_url) {
        $got  = isset($fetched[$idx]) ? $fetched[$idx] : ['code' => 0, 'body' => '', 'err' => 'deadline_not_attempted'];
        $body = $got['body'];
        $code = (int) $got['code'];
        $err  = (string) $got['err'];

        if ($max_bytes > 0 && ((int) ($got['res'] ?? 0) === 42 || (int) ($got['res'] ?? 0) === 63 || (is_string($body) && strlen($body) > $max_bytes))) {
            error_log(sprintf('[wpc_v2_parallel_pull] idx=%s body_too_large cap=%d', $idx, $max_bytes));
            continue;
        }
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
        $r = wpc_v2_pull_remote_get($url, ['timeout' => $total_s, 'sslverify' => true]);
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
