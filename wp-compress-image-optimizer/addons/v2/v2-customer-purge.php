<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-customer-purge.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */

if (!defined('ABSPATH')) {
    exit; 
}


if (!function_exists('wpc_unified_purge_enabled')) {

    function wpc_unified_purge_enabled()
    {
        $opt = get_option('wpc_unified_purge_enabled', true);
        return (bool) apply_filters('wpc_unified_purge_enabled', !empty($opt));
    }
}

if (!function_exists('wpc_customer_purge_endpoint')) {

    function wpc_customer_purge_endpoint()
    {
        $base = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        if ($base === '') {
            return '';
        }
        $url = rtrim($base, '/') . '/v2/customer-purge';
        return (string) apply_filters('wpc_customer_purge_endpoint', $url);
    }
}


if (!function_exists('wpc_purge_compat')) {

    function wpc_purge_compat($mode, $urls = [], $reason = 'manual_purge', $apikey = '', $blocking = true)
    {
        if (!wpc_unified_purge_enabled()) {
            return ['ok' => true, 'skipped' => 'flag_off'];
        }
        return wpc_customer_purge($apikey, $mode, $urls, $reason, $blocking);
    }
}

if (!function_exists('wpc_customer_purge')) {

    function wpc_customer_purge($apikey, $mode, $urls = [], $reason = 'manual_purge', $blocking = true)
    {
        $t0 = microtime(true);

        $apikey = (string) $apikey;
        if ($apikey === '' && function_exists('wpc_v2_get_apikey')) {
            $apikey = (string) wpc_v2_get_apikey();
        }

        $mode = ($mode === 'all') ? 'all' : 'urls';
        if ($mode === 'urls') {
            $urls = array_values(array_unique(array_filter(array_map('strval', (array) $urls), 'strlen')));
        } else {
            $urls = [];
        }

        $blocking = (bool) $blocking;

        
        
        $cf_handle   = wpc_purge_cf_async($apikey, $mode, $urls, $blocking);
        $orch_handle = wpc_purge_orch_async($apikey, $mode, $urls, $reason, $blocking);

        $cf_result   = wpc_collect_cf($cf_handle);
        $orch_result = wpc_collect_orch($orch_handle);

        $cf_ok      = !empty($cf_result['ok']);
        $orch_ok    = !empty($orch_result['ok']);
        $any_failed = !$cf_ok || !$orch_ok;

        $orch_layers = (isset($orch_result['layers']) && is_array($orch_result['layers']))
            ? $orch_result['layers']
            : [];

        $result = [
            'ok'            => !$any_failed,
            'apikey_prefix' => substr($apikey, 0, 12),
            'mode'          => $mode,
            'reason'        => (string) $reason,
            'blocking'      => $blocking,
            'duration_ms'   => (int) round((microtime(true) - $t0) * 1000),
            'layers'        => array_merge(['cloudflare' => $cf_result], $orch_layers),
        ];

        error_log(sprintf(
            '[WPC CustomerPurge] ok=%d mode=%s reason=%s urls=%d block=%d dur=%dms cf=%s orch=%s(http=%s) apikey=%s',
            $result['ok'] ? 1 : 0,
            $mode,
            (string) $reason,
            count($urls),
            $blocking ? 1 : 0,
            $result['duration_ms'],
            $cf_ok ? (isset($cf_result['dispatched']) ? 'sent' : 'ok') : (isset($cf_result['connected']) && !$cf_result['connected'] ? 'skip' : 'fail'),
            $orch_ok ? (isset($orch_result['dispatched']) ? 'sent' : 'ok') : 'fail',
            isset($orch_result['http']) ? (string) $orch_result['http'] : '0',
            substr($apikey, 0, 12)
        ));

        return $result;
    }
}


if (!function_exists('wpc_purge_cf_async')) {
    





    function wpc_purge_cf_async($apikey, $mode, $urls, $blocking = true)
    {
        if (!class_exists('wps_ic_cloudflare') || !class_exists('WPC_CloudflareAPI')) {
            return ['type' => 'skipped', 'reason' => 'cf_sdk_missing'];
        }

        $cf_int = new wps_ic_cloudflare();
        if (!$cf_int->is_active()) {
            return ['type' => 'skipped', 'reason' => 'not_connected'];
        }

        $cf_settings = get_option(WPS_IC_CF);
        if (empty($cf_settings['zone']) || empty($cf_settings['token'])) {
            return ['type' => 'skipped', 'reason' => 'incomplete_cf_settings'];
        }

        return [
            'type'       => 'cf',
            'zone'       => (string) $cf_settings['zone'],
            'token'      => (string) $cf_settings['token'],
            'mode'       => $mode,
            'blocking'   => (bool) $blocking,
            'urls'       => $mode === 'urls' ? wpc_normalize_urls_for_cf($urls) : [],
            'started_at' => microtime(true),
        ];
    }
}

if (!function_exists('wpc_collect_cf')) {

    function wpc_collect_cf($handle)
    {
        if (!is_array($handle) || !isset($handle['type']) || $handle['type'] !== 'cf') {
            return [
                'connected' => false,
                'ok'        => true,
                'reason'    => is_array($handle) && isset($handle['reason']) ? $handle['reason'] : 'not_connected',
            ];
        }

        
        if (empty($handle['blocking'])) {
            $chunks = wpc_cf_fire_nonblocking($handle['token'], $handle['zone'], $handle['mode'], $handle['urls']);
            return [
                'connected'    => true,
                'ok'           => true,
                'dispatched'   => true,
                'chunks_fired' => $chunks,
                'zone'         => substr((string) $handle['zone'], 0, 8),
            ];
        }

        $cf = new WPC_CloudflareAPI($handle['token']);
        $t0 = isset($handle['started_at']) ? $handle['started_at'] : microtime(true);

        $purged       = 0;
        $chunks_fired = 0;
        $errors       = [];

        try {
            if ($handle['mode'] === 'all') {
                $resp = $cf->purgeCache($handle['zone']);
                $chunks_fired = 1;
                if (wpc_cf_response_ok($resp)) {
                    $purged = 'all';
                } else {
                    $errors[] = wpc_cf_extract_error($resp);
                }
            } else {
                $chunks = array_chunk($handle['urls'], 30);
                foreach ($chunks as $chunk) {
                    $resp = $cf->purgeFiles($handle['zone'], $chunk);
                    $chunks_fired++;
                    if (wpc_cf_response_ok($resp)) {
                        $purged += count($chunk);
                        continue;
                    }
                    $err = wpc_cf_extract_error($resp);
                    $errors[] = $err;
                    
                    if (wpc_cf_is_rate_limit_error($err)) {
                        usleep(500000);
                        $resp = $cf->purgeFiles($handle['zone'], $chunk);
                        if (wpc_cf_response_ok($resp)) {
                            $purged += count($chunk);
                            array_pop($errors);
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }

        return [
            'connected'      => true,
            'ok'             => empty($errors),
            'urls_purged'    => $purged,
            'chunks_fired'   => $chunks_fired,
            'cf_response_ms' => (int) round((microtime(true) - $t0) * 1000),
            'zone'           => substr((string) $handle['zone'], 0, 8),
            'errors'         => $errors ?: null,
        ];
    }
}

if (!function_exists('wpc_cf_fire_nonblocking')) {
    





    function wpc_cf_fire_nonblocking($token, $zone, $mode, $urls)
    {
        $endpoint = 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode((string) $zone) . '/purge_cache';
        $headers  = [
            'Authorization' => 'Bearer ' . (string) $token,
            'Content-Type'  => 'application/json',
        ];
        $fire = function ($payload) use ($endpoint, $headers) {
            wp_remote_post($endpoint, [
                'headers'  => $headers,
                'body'     => wp_json_encode($payload),
                'blocking' => false,
                'timeout'  => 1,
            ]);
        };

        if ($mode === 'all') {
            $fire(['purge_everything' => true]);
            return 1;
        }

        $chunks = array_chunk((array) $urls, 30);
        foreach ($chunks as $chunk) {
            $fire(['files' => array_values($chunk)]);
        }
        return count($chunks);
    }
}

if (!function_exists('wpc_normalize_urls_for_cf')) {

    function wpc_normalize_urls_for_cf($urls, $apikey = '')
    {
        $site_url = rtrim(function_exists('site_url') ? site_url() : '', '/');
        $out = [];
        foreach ((array) $urls as $u) {
            $u = (string) $u;
            if ($u === '') {
                continue;
            }
            if (preg_match('#^https?://#i', $u)) {
                $out[] = $u;
            } else {
                $out[] = $site_url . (substr($u, 0, 1) === '/' ? $u : '/' . $u);
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('wpc_cf_response_ok')) {
    




    function wpc_cf_response_ok($resp)
    {
        if (empty($resp) || (function_exists('is_wp_error') && is_wp_error($resp))) {
            return false;
        }
        if (is_array($resp) && array_key_exists('success', $resp)) {
            return (bool) $resp['success'];
        }
        return is_array($resp);
    }
}

if (!function_exists('wpc_cf_extract_error')) {
    function wpc_cf_extract_error($resp)
    {
        if (function_exists('is_wp_error') && is_wp_error($resp)) {
            return $resp->get_error_message();
        }
        if (is_array($resp) && !empty($resp['errors'])) {
            $first = $resp['errors'][0];
            if (is_array($first)) {
                return isset($first['message']) ? (string) $first['message'] : 'unknown CF error';
            }
            return (string) $first;
        }
        return 'unknown CF response shape';
    }
}

if (!function_exists('wpc_cf_is_rate_limit_error')) {
    function wpc_cf_is_rate_limit_error($err)
    {
        $err = (string) $err;
        return stripos($err, 'rate limit') !== false || strpos($err, '429') !== false;
    }
}


if (!function_exists('wpc_purge_orch_async')) {

    function wpc_purge_orch_async($apikey, $mode, $urls, $reason, $blocking = true)
    {
        if (function_exists('wpc_purge_record')) {
            wpc_purge_record('orch', (string) $mode, $mode === 'urls' ? 'url' : 'zone',
                is_array($urls) ? count($urls) : 1, true, (string) $reason);
        }
        $body = [
            'apikey' => (string) $apikey,
            'mode'   => $mode,
            'reason' => (string) $reason,
        ];
        if ($mode === 'urls') {
            $body['urls'] = array_values($urls);
        }


        $zid = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
        if ($zid !== '' && ctype_digit($zid)) {
            $body['zone_id'] = $zid;
        }
        if (function_exists('site_url')) {
            $body['site_url'] = (string) site_url();
        }

        return [
            'type'       => 'orch',
            'apikey'     => (string) $apikey,
            'blocking'   => (bool) $blocking,
            'body'       => $body,
            'started_at' => microtime(true),
        ];
    }
}

if (!function_exists('wpc_collect_orch')) {

    function wpc_collect_orch($handle)
    {
        $t0       = isset($handle['started_at']) ? $handle['started_at'] : microtime(true);
        $endpoint = wpc_customer_purge_endpoint();
        $apikey   = isset($handle['apikey']) ? (string) $handle['apikey'] : '';

        if ($endpoint === '' || $apikey === '') {
            return [
                'ok'          => false,
                'layers'      => wpc_orch_failure_layers('plugin_misconfigured', 'no_endpoint_or_apikey'),
                'duration_ms' => 0,
                'http'        => 0,
            ];
        }

        $body_raw = wp_json_encode($handle['body']);
        if ($body_raw === false) {
            return [
                'ok'          => false,
                'layers'      => wpc_orch_failure_layers('json_encode_failed', ''),
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                'http'        => 0,
            ];
        }

        
        if (empty($handle['blocking'])) {
            $ts  = time();
            $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);
            wp_remote_post($endpoint, [
                'timeout'   => 1,
                'blocking'  => false,
                'sslverify' => true,
                'headers'   => [
                    'Content-Type' => 'application/json',
                    'x-wpc-apikey' => $apikey,
                    'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                    'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.00.09'),
                ],
                'body' => $body_raw,
            ]);
            return [
                'ok'          => true,
                'dispatched'  => true,
                'layers'      => [],
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                'http'        => 0,
            ];
        }

        $post = function () use ($endpoint, $apikey, $body_raw) {
            $ts  = time();
            $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);
            return wp_remote_post($endpoint, [
                'timeout'   => 8,
                'sslverify' => true,
                'headers'   => [
                    'Content-Type' => 'application/json',
                    'x-wpc-apikey' => $apikey,
                    'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                    'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.00.09'),
                ],
                'body' => $body_raw,
            ]);
        };

        $resp = $post();
        if (is_wp_error($resp)) {
            return [
                'ok'          => false,
                'layers'      => wpc_orch_failure_layers('orch_unreachable', $resp->get_error_message()),
                'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
                'http'        => 0,
            ];
        }

        $code  = (int) wp_remote_retrieve_response_code($resp);
        $rbody = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($rbody)) {
            $rbody = [];
        }

        if ($code === 429) {
            $retry_after = (int) wp_remote_retrieve_header($resp, 'retry-after');
            if ($retry_after <= 0) {
                $retry_after = 2;
            }
            sleep(min($retry_after, 5));
            $resp = $post();
            if (!is_wp_error($resp)) {
                $code  = (int) wp_remote_retrieve_response_code($resp);
                $rbody = json_decode((string) wp_remote_retrieve_body($resp), true);
                if (!is_array($rbody)) {
                    $rbody = [];
                }
            }
        }


        $ok = ($code >= 200 && $code < 300) && !empty($rbody['ok']);

        $layers = (isset($rbody['layers']) && is_array($rbody['layers']))
            ? $rbody['layers']
            : wpc_orch_failure_layers('http_' . $code, $rbody);

        return [
            'ok'          => $ok,
            'layers'      => $layers,
            'duration_ms' => (int) round((microtime(true) - $t0) * 1000),
            'http'        => $code,
        ];
    }
}

if (!function_exists('wpc_orch_failure_layers')) {

    function wpc_orch_failure_layers($error, $detail)
    {
        $entry = ['ok' => false, 'error' => (string) $error];
        if ($detail !== '' && $detail !== null) {
            $entry['detail'] = is_scalar($detail) ? (string) $detail : $detail;
        }
        return [
            'customer_pz'  => $entry,
            'cdn_mc_pz'    => $entry,
            'pod_fs_fleet' => $entry,
        ];
    }
}


if (!function_exists('wpc_customer_purge_attachment_urls')) {

    function wpc_customer_purge_attachment_urls($imageID)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) {
            return [];
        }

        $main = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($imageID) : '';
        if (!$main) {
            return [];
        }
        $base_url = preg_replace('#/[^/]+$#', '', $main);

        $files = [basename($main)];
        $meta  = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($imageID) : [];
        if (is_array($meta)) {
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $sz) {
                    if (!empty($sz['file'])) {
                        $files[] = (string) $sz['file'];
                    }
                }
            }
            if (!empty($meta['original_image'])) {
                $files[] = (string) $meta['original_image'];
            }
        }


        $local_variants = function_exists('get_post_meta') ? get_post_meta($imageID, 'ic_local_variants', true) : [];
        if (is_array($local_variants)) {
            foreach ($local_variants as $v) {
                if (is_array($v) && !empty($v['url'])) {
                    $files[] = basename((string) $v['url']);
                }
            }
        }


        $att_file_g = function_exists('get_attached_file') ? get_attached_file($imageID) : '';
        if ($att_file_g) {
            $stem_g = pathinfo((function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : '') ?: $att_file_g, PATHINFO_FILENAME);
            foreach ((array) glob(dirname($att_file_g) . '/' . $stem_g . '-*.{avif,webp}', GLOB_BRACE) as $gf) {
                if ($gf) $files[] = basename($gf);
            }
        }

        $abs = [$main];
        foreach (array_unique($files) as $f) {
            $stem = preg_replace('#\.(jpe?g|png|gif|webp|avif)$#i', '', $f);
            $abs[] = $base_url . '/' . $f;
            $abs[] = $base_url . '/' . $stem . '.webp';
            $abs[] = $base_url . '/' . $stem . '.avif';


            if (preg_match('#-scaled$#', $stem)) {
                $unscaled = preg_replace('#-scaled$#', '', $stem);
                $abs[] = $base_url . '/' . $unscaled . '.webp';
                $abs[] = $base_url . '/' . $unscaled . '.avif';
            }
        }

        $out = [];
        foreach (array_unique(array_filter($abs)) as $u) {
            $rel = function_exists('wp_make_link_relative') ? wp_make_link_relative($u) : $u;
            if ($rel !== '') {
                $out[] = $rel;
            }
        }
        return array_values(array_unique($out));
    }
}

if (!function_exists('wpc_restore_cdn_purge')) {

    function wpc_restore_cdn_purge($imageID)
    {
        static $done = [];
        $imageID = (int) $imageID;
        if ($imageID <= 0 || isset($done[$imageID])) return;
        $done[$imageID] = true;
        if (!function_exists('wpc_customer_purge') || !function_exists('wpc_v2_get_apikey')) return;
        $apikey = (string) wpc_v2_get_apikey();
        if ($apikey === '') return;
        $urls = function_exists('wpc_customer_purge_attachment_urls')
            ? wpc_customer_purge_attachment_urls($imageID)
            : [];
        if (empty($urls)) return;
        wpc_customer_purge($apikey, 'urls', $urls, 'restore_single', false);
    }
}
