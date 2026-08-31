<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-pull-manifest.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */



if (!defined('ABSPATH')) {
    exit;
}


if (!function_exists('wpc_v2_pull_enabled')) {
    function wpc_v2_pull_enabled()
    {
        $opt = get_site_option('wpc_v2_pull_enabled', null);
        if ($opt === null) {
            return (bool) apply_filters('wpc_v2_pull_enabled', true);
        }
        return (bool) apply_filters('wpc_v2_pull_enabled', !empty($opt));
    }
}


if (!function_exists('wpc_v2_manifest_sign_get')) {
    function wpc_v2_manifest_sign_get($apikey, $since, $limit, $wait_ms)
    {
        $canonical = sprintf(
            'apikey=%s&since=%d&limit=%d&wait_ms=%d',
            (string) $apikey, (int) $since, (int) $limit, (int) $wait_ms
        );
        return [
            'X-WPC-Sig'       => hash_hmac('sha256', $canonical, (string) $apikey),
            'X-WPC-Timestamp' => (string) time(),
        ];
    }
}
if (!function_exists('wpc_v2_manifest_sign_body')) {
    function wpc_v2_manifest_sign_body($apikey, $body_raw)
    {
        return [
            'X-WPC-Sig'       => hash_hmac('sha256', (string) $body_raw, (string) $apikey),
            'X-WPC-Timestamp' => (string) time(),
        ];
    }
}


if (!function_exists('wpc_v2_pull_get_cursor')) {
    function wpc_v2_pull_get_cursor()
    {
        return (int) get_option('wpc_v2_pull_cursor_ms', 0);
    }
}
if (!function_exists('wpc_v2_pull_set_cursor')) {
    function wpc_v2_pull_set_cursor($ms)
    {
        $ms = (int) $ms;
        if ($ms <= 0) {
            return;
        }
        update_option('wpc_v2_pull_cursor_ms', $ms, false);
    }
}


if (!function_exists('wpc_v2_pull_manifest_fetch')) {
    function wpc_v2_pull_manifest_fetch($since_ms = 0, $limit = 100, $wait_ms = 0)
    {
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($orch_url === '' || $apikey === '') {
            return ['ok' => false, 'variants' => [], 'cursor_high_water_ms' => 0, 'pages_fetched' => 0, 'error' => 'no_orch_or_apikey'];
        }

        $since_ms = max(0, (int) $since_ms);
        $limit    = max(1, min(500, (int) $limit));

        
        
        $wait_ms  = max(0, min(25000, (int) $wait_ms));

        $all_variants  = [];
        $high_water    = $since_ms;
        $pages_fetched = 0;
        $next_since    = $since_ms;
        
        
        $max_pages     = 20;

        while ($pages_fetched < $max_pages) {
            

            $page_wait_ms = ($pages_fetched === 0) ? $wait_ms : 0;


            $url = rtrim($orch_url, '/') . '/optimize-v2/manifest'
                 . '?apikey='  . rawurlencode($apikey)
                 . '&since='   . $next_since
                 . '&limit='   . $limit
                 . '&wait_ms=' . $page_wait_ms;

            
            $http_timeout = $page_wait_ms > 0
                ? (int) ceil(($page_wait_ms + 5000) / 1000)
                : 8;

            $sig_headers = wpc_v2_manifest_sign_get($apikey, $next_since, $limit, $page_wait_ms);

            $resp = wp_remote_get($url, [
                'timeout' => $http_timeout,
                'headers' => array_merge([
                    'Accept' => 'application/json',
                ], $sig_headers),
            ]);

            if (is_wp_error($resp)) {
                error_log(sprintf('[WPC PullManifest] http_error since=%d page=%d err=%s', $next_since, $pages_fetched, $resp->get_error_message()));
                return ['ok' => false, 'variants' => $all_variants, 'cursor_high_water_ms' => $high_water, 'pages_fetched' => $pages_fetched, 'error' => 'http_error'];
            }
            $code = (int) wp_remote_retrieve_response_code($resp);
            if ($code !== 200) {
                

                
                if ($code === 429) {
                    $retry_after = 60;
                    $hdr = wp_remote_retrieve_header($resp, 'retry-after');
                    if ($hdr !== '' && is_numeric($hdr)) {
                        $retry_after = max(10, min(300, (int) $hdr));
                    }
                    
                    
                    set_transient('wpc_v2_drain_running', time(), $retry_after);
                    error_log(sprintf(
                        '[WPC PullManifest] http_429_backoff retry_after=%ds since=%d',
                        $retry_after, $next_since
                    ));
                    return ['ok' => false, 'variants' => $all_variants, 'cursor_high_water_ms' => $high_water, 'pages_fetched' => $pages_fetched, 'error' => 'rate_limited', 'retry_after' => $retry_after];
                }
                error_log(sprintf('[WPC PullManifest] http_status=%d since=%d page=%d', $code, $next_since, $pages_fetched));
                return ['ok' => false, 'variants' => $all_variants, 'cursor_high_water_ms' => $high_water, 'pages_fetched' => $pages_fetched, 'error' => 'http_status_' . $code];
            }

            $body = json_decode(wp_remote_retrieve_body($resp), true);
            if (!is_array($body)) {
                return ['ok' => false, 'variants' => $all_variants, 'cursor_high_water_ms' => $high_water, 'pages_fetched' => $pages_fetched, 'error' => 'invalid_json'];
            }

            $pages_fetched++;
            $variants = isset($body['variants']) && is_array($body['variants']) ? $body['variants'] : [];

            if (!empty($variants)) {
                foreach ($variants as $v) {
                    $all_variants[] = $v;
                }
            }
            if (isset($body['cursor_high_water_ms']) && (int) $body['cursor_high_water_ms'] > $high_water) {
                $high_water = (int) $body['cursor_high_water_ms'];
            }

            
            
            if (empty($body['has_more']) || empty($body['next_cursor_ms'])) {
                break;
            }
            $next = (int) $body['next_cursor_ms'];

            if ($next <= $next_since) {
                break;
            }
            $next_since = $next;
        }

        return [
            'ok'                   => true,
            'variants'             => $all_variants,
            'cursor_high_water_ms' => $high_water,
            'pages_fetched'        => $pages_fetched,
        ];
    }
}


if (!function_exists('wpc_v2_pull_manifest_queue_for_drain')) {
    function wpc_v2_pull_manifest_queue_for_drain(array $variants)
    {
        if (!function_exists('wpc_v2_journal_write_batch')) {
            return ['queued' => 0, 'skipped_dedup' => 0, 'skipped_invalid' => 0, 'imageIDs' => []];
        }

        
        $by_image        = [];
        $skipped_dedup   = 0;
        $skipped_invalid = 0;

        
        
        $lazycdn_acked   = [];
        $lazycdn_failed  = 0;
        $lazycdn_ingested = 0;


        $min_failed_ms   = 0;
        $failed_entries197 = [];
        $fail197 = function ($v, $reason) use (&$failed_entries197) {
            if (count($failed_entries197) >= 50) return;
            $failed_entries197[] = [
                'imageID'   => isset($v['imageID'])   ? (string) $v['imageID']   : '',
                'sizeLabel' => isset($v['sizeLabel']) ? (string) $v['sizeLabel'] : '',
                'format'    => isset($v['format'])    ? (string) $v['format']    : '',
                'reason'    => (string) $reason,
            ];
        };

        foreach ($variants as $v) {


            if (function_exists('set_transient')
                && (!function_exists('wpc_v2_telemetry_throttle') || wpc_v2_telemetry_throttle('drain_running_refresh', 10))) {


                set_transient('wpc_v2_drain_running', time(), 40);
            }
            if (!is_array($v)) {
                $skipped_invalid++;
                continue;
            }


            $entry_source = '';
            $entry_imageID_str = isset($v['imageID']) ? (string) $v['imageID'] : '';
            if (isset($v['source'])) {
                $entry_source = (string) $v['source'];
            } elseif (isset($v['tags']['source'])) {
                $entry_source = (string) $v['tags']['source'];
            } elseif ($entry_imageID_str !== '' && strpos($entry_imageID_str, 'lazycdn') === 0) {
                $entry_source = 'lazycdn';
            }
            if ($entry_source === 'lazycdn') {
                
                
                if (!isset($v['origin_url']) && isset($v['tags']['origin_url'])) {
                    $v['origin_url'] = (string) $v['tags']['origin_url'];
                }
                if (!isset($v['origin_host']) && isset($v['tags']['origin_host'])) {
                    $v['origin_host'] = (string) $v['tags']['origin_host'];
                }
                if (function_exists('wpc_v2_lazy_cdn_ingest')) {
                    $sha_for_ack = isset($v['sha256']) ? (string) $v['sha256'] : '';


                    $t_i0 = microtime(true);
                    if (wpc_v2_lazy_cdn_ingest($v)) {
                        $t_i_ms = (int) round((microtime(true) - $t_i0) * 1000);
                        if ($t_i_ms > 1500) {
                            error_log(sprintf('[WPC PullManifest] slow_ingest size=%s fmt=%s ms=%d',
                                isset($v['sizeLabel']) ? (string) $v['sizeLabel'] : '?',
                                isset($v['format']) ? (string) $v['format'] : '?', $t_i_ms));
                        }
                        if ($sha_for_ack !== '') $lazycdn_acked[] = $sha_for_ack;
                        $lazycdn_ingested++;
                        if (function_exists('wpc_v2_pull_failcount') && $sha_for_ack !== '') { wpc_v2_pull_failcount($sha_for_ack, 0); }


                    } else {
                        $lazycdn_failed++;
                        $fail197($v, 'lazycdn_ingest_failed');

                        $f_ms = isset($v['completed_at_ms']) ? (int) $v['completed_at_ms'] : 0;


                        $wpc_clamp = true;
                        if (function_exists('get_option')) {


                            $wpc_lif    = isset($GLOBALS['wpc_v2_lif_mem']) && is_array($GLOBALS['wpc_v2_lif_mem'])
                                          ? $GLOBALS['wpc_v2_lif_mem'] : get_option('wpc_v2_last_ingest_fail');
                            $wpc_reason = (is_array($wpc_lif) && isset($wpc_lif['reason'])) ? (string) $wpc_lif['reason'] : '';
                            $wpc_detail = (is_array($wpc_lif) && isset($wpc_lif['detail'])) ? (string) $wpc_lif['detail'] : '';


                            $wpc_perm   = (strpos($wpc_reason, 'bytes_fetch_non_200') === 0 && (strpos($wpc_detail, 'code=404') !== false || strpos($wpc_detail, 'code=410') !== false));
                            $wpc_age_ms = ($f_ms > 0) ? ((int) round(microtime(true) * 1000) - $f_ms) : 0;
                            if ($wpc_perm && $wpc_age_ms > 1800000) {
                                $wpc_clamp = false;
                                error_log('[WPC PullManifest] cursor-advance past stale permanent failure reason=' . $wpc_reason . ' ' . $wpc_detail . ' age_ms=' . $wpc_age_ms);
                            }
                        }


                        if ($wpc_clamp && $sha_for_ack !== '' && function_exists('wpc_v2_pull_failcount')) {
                            $wpc_fc = wpc_v2_pull_failcount($sha_for_ack, 1);
                            if ($wpc_fc >= (int) apply_filters('wpc_v2_pull_failcap', 10)) {
                                $wpc_clamp = false;
                                error_log('[WPC PullManifest] cursor-advance past PARKED entry sha=' . substr((string) $sha_for_ack, 0, 12) . ' fails=' . $wpc_fc);
                            }
                        }
                        if ($wpc_clamp && $f_ms > 0 && ($min_failed_ms === 0 || $f_ms < $min_failed_ms)) $min_failed_ms = $f_ms;
                    }
                } else {


                    $skipped_invalid++;
                }
                continue;
            }

            $imageID  = isset($v['imageID'])  ? (int) $v['imageID']  : 0;
            $size     = isset($v['sizeLabel']) ? (string) $v['sizeLabel'] : '';
            $format   = isset($v['format'])    ? (string) $v['format']    : '';
            $url      = isset($v['fetchUrl'])  ? (string) $v['fetchUrl']  : '';
            $size_b   = isset($v['bytes'])     ? (int) $v['bytes']        : 0;
            $sha256   = isset($v['sha256'])    ? (string) $v['sha256']    : '';

            if ($imageID <= 0 || $size === '' || $format === '' || $url === '' || $size_b <= 0 || $sha256 === '') {


                static $dbg_invalid_dumped = 0;
                if ($dbg_invalid_dumped < 3) {
                    error_log(sprintf(
                        '[WPC PullManifest] skip_invalid_entry keys=%s imageID=%s sizeLabel=%s format=%s fetchUrl_len=%d bytes=%s sha256_len=%d source=%s tags=%s',
                        is_array($v) ? implode(',', array_keys($v)) : 'not_array',
                        (string) ($v['imageID'] ?? '(missing)'),
                        (string) ($v['sizeLabel'] ?? '(missing)'),
                        (string) ($v['format'] ?? '(missing)'),
                        isset($v['fetchUrl']) ? strlen((string) $v['fetchUrl']) : -1,
                        (string) ($v['bytes'] ?? '(missing)'),
                        isset($v['sha256']) ? strlen((string) $v['sha256']) : -1,
                        isset($v['source']) ? (string) $v['source'] : '(no_source_tag)',
                        isset($v['tags']) ? wp_json_encode($v['tags']) : '(no_tags)'
                    ));
                    $dbg_invalid_dumped++;
                }
                $skipped_invalid++;
                $fail197($v, 'invalid_entry');
                continue;
            }

            
            
            if (wpc_v2_pull_manifest_already_on_disk($imageID, $size, $format, $sha256)) {
                $skipped_dedup++;
                continue;
            }


            $abs_parent = get_attached_file($imageID);
            if (!$abs_parent) {
                $skipped_invalid++;
                $fail197($v, 'no_attached_file');
                continue;
            }
            $dest_dir = dirname($abs_parent);

            if (!isset($by_image[$imageID])) {
                $by_image[$imageID] = [
                    'jobId'   => isset($v['jobId']) ? (string) $v['jobId'] : '',
                    'entries' => [],
                ];
            }

            $entry = [
                'sizeLabel'    => $size,
                'format'       => $format,
                'type'         => 'persisted_pending_bytes',
                'fetch_url'    => $url,
                'bytes_size'   => $size_b,
                'bytes_sha256' => $sha256,
                'filename'     => isset($v['filename']) ? (string) $v['filename'] : '',
                'dest_dir'     => $dest_dir,
                'originalSize' => isset($v['originalSize']) ? (int) $v['originalSize'] : 0,
                'ms'           => isset($v['completed_at_ms']) ? (int) $v['completed_at_ms'] : (int) round(microtime(true) * 1000),
                
                
                'delivery_method' => isset($v['delivery_method']) ? (string) $v['delivery_method'] : 'push',
                'source'       => 'pull_manifest',
            ];

            $by_image[$imageID]['entries'][] = $entry;
        }

        $queued    = 0;
        $imageIDs  = [];
        


        $journal_failed_sha256s = [];
        foreach ($by_image as $imageID => $group) {
            if (empty($group['entries'])) {
                continue;
            }
            if (wpc_v2_journal_write_batch($imageID, $group['jobId'], $group['entries'], 'pull_manifest')) {
                $queued     += count($group['entries']);
                $imageIDs[]  = $imageID;
            } else {
                
                foreach ($group['entries'] as $entry) {

                    $fail197(['imageID' => $imageID, 'sizeLabel' => $entry['sizeLabel'] ?? '', 'format' => $entry['format'] ?? ''], 'journal_write_failed');
                    if (!empty($entry['bytes_sha256'])) {
                        $journal_failed_sha256s[(string) $entry['bytes_sha256']] = true;
                    }
                    
                    $f_ms = isset($entry['ms']) ? (int) $entry['ms'] : 0;
                    if ($f_ms > 0 && ($min_failed_ms === 0 || $f_ms < $min_failed_ms)) $min_failed_ms = $f_ms;
                }
            }
        }


        if (wpc_v2_ingest_diag_on() && wpc_v2_telemetry_throttle('drain_stats', 15)) {


            update_option('wpc_v2_last_drain_stats', [
                't'        => time(),
                'ingested' => $lazycdn_ingested,
                'failed'   => $lazycdn_failed,
                'seen'     => is_array($variants) ? count($variants) : 0,
            ], false);
        }

        return [
            'queued'          => $queued,
            'skipped_dedup'   => $skipped_dedup,
            'skipped_invalid' => $skipped_invalid,
            'imageIDs'        => $imageIDs,
            
            

            'lazycdn_ingested'      => $lazycdn_ingested,
            'lazycdn_failed'        => $lazycdn_failed,
            'lazycdn_acked_sha256s' => array_values(array_unique($lazycdn_acked)),
            
            'journal_failed_sha256s' => array_keys($journal_failed_sha256s),
            
            'min_failed_completed_ms' => $min_failed_ms,
            'failed_entries197'       => $failed_entries197,
        ];
    }
}


if (!function_exists('wpc_v2_pull_manifest_already_on_disk')) {
    function wpc_v2_pull_manifest_already_on_disk($imageID, $size, $format, $sha256)
    {
        if ($sha256 === '') {
            return false;
        }
        $variants = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($variants) || empty($variants)) {
            return false;
        }
        
        $key = function_exists('wpc_v2_variant_key')
            ? wpc_v2_variant_key($size, $format)
            : ($format === 'jpeg' || $format === 'jpg' ? $size : $size . '-' . $format);
        if (!isset($variants[$key]) || !is_array($variants[$key])) {
            return false;
        }
        $on_disk_sha = isset($variants[$key]['bytes_sha256']) ? (string) $variants[$key]['bytes_sha256'] : '';
        return ($on_disk_sha !== '' && hash_equals($on_disk_sha, (string) $sha256));
    }
}


if (!function_exists('wpc_v2_pull_manifest_ack')) {
    function wpc_v2_pull_manifest_ack(array $acks, array $receipt = [])
    {
        if (empty($acks) && empty($receipt)) {
            return true;
        }
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($orch_url === '' || $apikey === '') {
            return false;
        }

        
        
        $wpc_body197 = ['acks' => array_values($acks)];
        if (!empty($receipt)) {
            $wpc_body197['receipt'] = $receipt;
        }
        $body_raw = wp_json_encode($wpc_body197);
        $sig_headers = wpc_v2_manifest_sign_body($apikey, $body_raw);

        $url = rtrim($orch_url, '/') . '/optimize-v2/manifest/ack?apikey=' . rawurlencode($apikey);
        $resp = wp_remote_post($url, [
            'timeout' => 4,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $sig_headers),
            'body' => $body_raw,
        ]);
        if (is_wp_error($resp)) {
            error_log('[WPC PullManifest] ack_http_error: ' . $resp->get_error_message());
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        return ($code >= 200 && $code < 300);
    }
}


if (!function_exists('wpc_v2_pull_manifest_purge')) {
    function wpc_v2_pull_manifest_purge($imageID = 0, $reason = 'manual')
    {
        $orch_url = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey   = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($orch_url === '' || $apikey === '') {
            return false;
        }
        $body_arr = ['reason' => (string) $reason];
        if ((int) $imageID > 0) {
            $body_arr['imageID'] = (int) $imageID;
        }

        $body_raw    = wp_json_encode($body_arr);
        $sig_headers = wpc_v2_manifest_sign_body($apikey, $body_raw);

        $url = rtrim($orch_url, '/') . '/optimize-v2/manifest/purge?apikey=' . rawurlencode($apikey);
        $resp = wp_remote_post($url, [
            'timeout' => 4,
            'headers' => array_merge([
                'Content-Type' => 'application/json',
            ], $sig_headers),
            'body' => $body_raw,
        ]);
        if (is_wp_error($resp)) {
            return false;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        return ($code >= 200 && $code < 300);
    }
}


if (!function_exists('wpc_v2_pull_drain_fire')) {
    function wpc_v2_pull_drain_fire($wake_items = [])
    {


        if (!wpc_v2_pull_enabled()) {
            error_log('[WPC DrainFire] skip reason=pull_disabled');
            if (wpc_v2_ingest_diag_on()) update_option('wpc_v2_last_drain_skip', ['t'=>time(),'reason'=>'pull_disabled'], false);
            return false;
        }
        
        if (($cooloff_ts = (int) get_transient('wpc_v2_pull_cooloff')) > 0) {
            error_log(sprintf('[WPC DrainFire] skip reason=cooloff_active until=%d', $cooloff_ts));
            if (wpc_v2_ingest_diag_on()) update_option('wpc_v2_last_drain_skip', ['t'=>time(),'reason'=>'cooloff_active','until'=>$cooloff_ts], false);
            return false;
        }
        
        
        if (($lock_ts = (int) get_transient('wpc_v2_drain_running')) > 0) {


            set_transient('wpc_v2_redrain_pending', time(), 60);


            $wpc_skips = (int) get_transient('wpc_v2_drain_skips_win');
            if (!get_transient('wpc_v2_drain_skip_logged')) {
                set_transient('wpc_v2_drain_skip_logged', 1, 60);
                set_transient('wpc_v2_drain_skips_win', 0, 120);
                error_log(sprintf('[WPC DrainFire] skip reason=drain_running_lock_held since=%d age_s=%d redrain_pending=1 skips_last_window=%d (windowed 1/min)', $lock_ts, time() - $lock_ts, $wpc_skips));
                if (wpc_v2_ingest_diag_on()) update_option('wpc_v2_last_drain_skip', ['t'=>time(),'reason'=>'drain_running_lock_held','redrain_pending'=>1,'lock_age_s'=>time()-$lock_ts,'skips_last_window'=>$wpc_skips], false);
            } else {
                set_transient('wpc_v2_drain_skips_win', $wpc_skips + 1, 120);
            }
            return false;
        }
        set_transient('wpc_v2_drain_running', time(), 15);

        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($apikey === '') {
            error_log('[WPC DrainFire] skip reason=empty_apikey');
            delete_transient('wpc_v2_drain_running');
            return false;
        }

        $ts  = time();
        $sig = hash_hmac('sha256', 'wpc_v2_pull_drain.' . $ts, $apikey);

        $url   = admin_url('admin-ajax.php');
        $parts = wp_parse_url($url);
        if (empty($parts['host'])) {
            error_log(sprintf('[WPC DrainFire] skip reason=bad_admin_url url=%s', $url));
            delete_transient('wpc_v2_drain_running');
            return false;
        }
        $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
        $port     = !empty($parts['port']) ? (int) $parts['port'] : ($is_https ? 443 : 80);
        $host     = (string) $parts['host'];
        $path     = (!empty($parts['path']) ? $parts['path'] : '/') . '?action=wpc_v2_pull_drain_loop';


        $body_params = ['t' => $ts, 'sig' => $sig];
        if (is_array($wake_items) && !empty($wake_items)) {
            $items_json = wp_json_encode(array_slice(array_values($wake_items), 0, 50));
            if (is_string($items_json)) {
                $body_params['items'] = $items_json;
            }
        }
        $body = http_build_query($body_params);
        $req  = "POST {$path} HTTP/1.1\r\n"
              . "Host: {$host}\r\n"
              . "Content-Type: application/x-www-form-urlencoded\r\n"
              . "Content-Length: " . strlen($body) . "\r\n"
              . "Connection: close\r\n"
              . "User-Agent: WPCV2PullDrain/1.0\r\n"
              . "\r\n"
              . $body;


        $errno  = 0;
        $errstr = '';
        $fp     = false;
        if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) {
            $fp = wps_ic_ajax::wpc_loopback_open_socket($host, $port, $is_https, 0.2);
        } else {
            $pd_ctx = $is_https ? stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]) : null;
            foreach (['127.0.0.1', 'localhost', $host] as $pd_chost) {
                $pd_remote = ($is_https ? 'tls://' : 'tcp://') . $pd_chost . ':' . $port;
                $pd_sock   = $pd_ctx
                    ? @stream_socket_client($pd_remote, $errno, $errstr, 0.2, STREAM_CLIENT_CONNECT, $pd_ctx)
                    : @stream_socket_client($pd_remote, $errno, $errstr, 0.2);
                if ($pd_sock) { $fp = $pd_sock; break; }
            }
        }
        if (!$fp) {

            delete_transient('wpc_v2_drain_running');
            error_log('[WPC PullDrain] fsockopen_failed errno=' . $errno . ' err=' . $errstr);
            return false;
        }
        @stream_set_timeout($fp, 0, 100000);
        @fwrite($fp, $req);
        @fclose($fp);
        
        
        delete_transient('wpc_v2_redrain_pending');
        return true;
    }
}


if (!function_exists('wpc_v2_deferred_pull_drain_fire')) {
    function wpc_v2_deferred_pull_drain_fire()
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        if (function_exists('wpc_v2_pull_drain_fire')) {
            wpc_v2_pull_drain_fire();
        }


        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        $wpc_drain_window_open = ((int) get_option('wpc_v2_drain_alive_until_ms', 0) > (int) round(microtime(true) * 1000));
        if ($wpc_drain_window_open && function_exists('wpc_v2_pull_drain_loop_handler')) {
            @ignore_user_abort(true);
            @set_time_limit(150);
            wpc_diag_sleep(3, 'pull-drain');
            if (!get_transient('wpc_v2_drain_worker_started')) {
                $apikey_inline = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
                if ($apikey_inline !== '') {
                    error_log('[WPC PageLoadDrain] loopback_worker_never_started — running drain inline');
                    $_POST['t']   = (string) time();
                    $_POST['sig'] = hash_hmac('sha256', 'wpc_v2_pull_drain.' . $_POST['t'], $apikey_inline);
                    wpc_v2_pull_drain_loop_handler();
                }
            }
        }
    }
}
if (!function_exists('wpc_v2_register_deferred_pull_drain')) {
    function wpc_v2_register_deferred_pull_drain()
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;
        register_shutdown_function('wpc_v2_deferred_pull_drain_fire');
    }
}






if (!function_exists('wpc_v2_pull_state_file')) {
    function wpc_v2_pull_state_file() {
        $up = wp_get_upload_dir();
        if (empty($up['basedir'])) return '';
        $dir = rtrim($up['basedir'], '/\\') . '/wpci-journal';
        if (!is_dir($dir) && function_exists('wp_mkdir_p')) { @wp_mkdir_p($dir); }
        return is_dir($dir) ? $dir . '/.pull_state' : '';
    }
    function wpc_v2_pull_backoff_until() {
        $f = wpc_v2_pull_state_file();
        if ($f === '' || !@is_file($f)) return 0;
        $s = json_decode((string) @file_get_contents($f), true);
        return is_array($s) ? (int) (isset($s['until']) ? $s['until'] : 0) : 0;
    }
    function wpc_v2_pull_breaker_reset() {
        $f = wpc_v2_pull_state_file();
        if ($f !== '' && @is_file($f)) { @unlink($f); }
    }
    function wpc_v2_pull_note_progress($queued, $has_work) {
        $f = wpc_v2_pull_state_file();
        if ($f === '') return;
        if ((int) $queued > 0 || !$has_work) { @unlink($f); return; }
        $s = json_decode((string) @file_get_contents($f), true);
        $n = (is_array($s) ? (int) (isset($s['noprog']) ? $s['noprog'] : 0) : 0) + 1;
        $until = 0;
        if ($n >= 3) {
            $until = time() + (int) min(900 * pow(2, $n - 3), 7200);
            error_log(sprintf('[WPC PullDrain] no-progress x%d — backing off until %s', $n, gmdate('H:i:s', $until)));
        }
        @file_put_contents($f, json_encode(['noprog' => $n, 'until' => $until]));
    }
}

if (!function_exists('wpc_v2_telemetry_throttle')) {


    function wpc_v2_telemetry_throttle($key, $min_s = 15) {
        static $mem = [];
        $now = time();
        if (isset($mem[$key]) && ($now - $mem[$key]) < $min_s) { return false; }
        $up  = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : ['basedir' => ''];
        $dir = rtrim((string) (isset($up['basedir']) ? $up['basedir'] : ''), '/\\') . '/wpci-journal';
        if (!is_dir($dir) && function_exists('wp_mkdir_p')) { @wp_mkdir_p($dir); }
        if (!is_dir($dir)) { $mem[$key] = $now; return true; }
        $f = $dir . '/.tt_' . preg_replace('/[^a-z0-9_]/', '', (string) $key);
        if (@is_file($f) && ($now - (int) @filemtime($f)) < $min_s) { $mem[$key] = $now; return false; }
        @touch($f);
        $mem[$key] = $now;
        return true;
    }
}

if (!function_exists('wpc_v2_ingest_diag_on')) {


    function wpc_v2_ingest_diag_on() {
        return apply_filters('wpc_v2_ingest_diag', get_option('wpc_v2_ingest_diag') === '1');
    }
}

if (!function_exists('wpc_v2_pull_failcount')) {


    function wpc_v2_pull_failcount_file() {
        if (!function_exists('wp_get_upload_dir')) { return ''; }
        $up = wp_get_upload_dir();
        $dir = rtrim((string) (isset($up['basedir']) ? $up['basedir'] : ''), '/\\') . '/wpci-journal';
        if (!is_dir($dir) && function_exists('wp_mkdir_p')) { @wp_mkdir_p($dir); }
        return is_dir($dir) ? $dir . '/.pull_failcounts' : '';
    }
    
    function wpc_v2_pull_failcount($sha, $delta) {
        $f = wpc_v2_pull_failcount_file();
        if ($f === '' || (string) $sha === '') { return 0; }
        $m = @is_file($f) ? json_decode((string) @file_get_contents($f), true) : [];
        if (!is_array($m)) { $m = []; }
        $k = substr((string) $sha, 0, 16);
        if ((int) $delta === 0) {
            unset($m[$k]);
        } else {
            $c = (isset($m[$k]) && is_array($m[$k])) ? (int) $m[$k][0] : 0;
            $m[$k] = [$c + (int) $delta, time()];
        }
        if (count($m) > 64) {
            uasort($m, function ($a, $b) { return (int) (isset($b[1]) ? $b[1] : 0) <=> (int) (isset($a[1]) ? $a[1] : 0); });
            $m = array_slice($m, 0, 64, true);
        }
        @file_put_contents($f, json_encode($m));
        return (isset($m[$k]) && is_array($m[$k])) ? (int) $m[$k][0] : 0;
    }
}

if (!function_exists('wpc_v2_pull_drain_loop_handler')) {
    function wpc_v2_pull_drain_loop_handler()
    {
        
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $ts     = isset($_POST['t']) ? (int) $_POST['t'] : 0;
        $sig    = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
        if ($apikey === '' || $ts <= 0 || $sig === '' || abs(time() - $ts) > 60) {
            http_response_code(401);
            exit('auth');
        }
        $expected = hash_hmac('sha256', 'wpc_v2_pull_drain.' . $ts, $apikey);
        if (!hash_equals($expected, $sig)) {
            http_response_code(401);
            exit('sig');
        }


        if (wpc_v2_pull_backoff_until() > time()) {
            error_log('[WPC PullDrain] breaker backoff active — idle exit');
            exit('backoff');
        }


        if (wpc_v2_ingest_diag_on()) update_option('wpc_v2_last_extdrain', [
            't'   => time(),
            'src' => isset($_POST['src']) ? substr(preg_replace('/[^a-z0-9_.-]/i', '', (string) $_POST['src']), 0, 24) : 'ext',
            'ua'  => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 40) : '',
        ], false);

        
        if (function_exists('fastcgi_finish_request')) {
            http_response_code(200);
            echo 'queued';
            fastcgi_finish_request();
        }
        
        @ignore_user_abort(true);
        @set_time_limit(60);


        set_transient('wpc_v2_drain_worker_started', time(), 90);


        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        $arm_now_ms = (int) (microtime(true) * 1000);
        if ((int) get_option('wpc_v2_drain_alive_until_ms', 0) < $arm_now_ms + 5000) {
            update_option('wpc_v2_drain_alive_until_ms', $arm_now_ms + 45000, false);
        }


        $wake_items_raw = isset($_POST['items']) ? wp_unslash((string) $_POST['items']) : '';
        
        
        $wake_decoded   = ($wake_items_raw !== '' && strlen($wake_items_raw) <= 65536) ? json_decode($wake_items_raw, true) : null;
        $wake_expect    = is_array($wake_decoded) ? min(count($wake_decoded), 50) : 0;


        $wake_ingested  = 0;

        $started        = microtime(true);
        $polls          = 0;
        $total_queued   = 0;


        $iter_budget_s  = 25.0;

        while ((microtime(true) - $started) < $iter_budget_s) {
            $now_ms = (int) (microtime(true) * 1000);


            wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
            $deadline_ms = (int) get_option('wpc_v2_drain_alive_until_ms', 0);
            if ($deadline_ms > 0 && $now_ms >= $deadline_ms) {
                
                error_log(sprintf(
                    '[WPC PullDrain] deadline_reached iter=%d queued_total=%d wall_ms=%d',
                    $polls, $total_queued, (int) round((microtime(true) - $started) * 1000)
                ));
                delete_transient('wpc_v2_drain_running');
                exit;
            }

            $polls++;

            
            
            if (get_transient('wpc_bulk_stop_signal')) {
                error_log('[WPC PullDrain] stop_signal — standing down (bulk stopped)');
                delete_option('wpc_v2_drain_alive_until_ms');
                delete_transient('wpc_v2_drain_running');
                exit;
            }

            $restoring = wpc_v2_active_restore_count();
            

            
            
            if ($restoring > 0) {
                update_option('wpc_v2_drain_alive_until_ms', (int) (microtime(true) * 1000) + 60000, false);
                error_log(sprintf('[WPC PullDrain] yield_to_restore restoring=%d queued_total=%d — defer (deadline bumped)', $restoring, $total_queued));
                delete_transient('wpc_v2_drain_running');
                exit;
            }
            $wait_ms   = 3000;

            $tick = wpc_v2_pull_manifest_tick(100, $wait_ms);


            $tick_reason = isset($tick['reason']) ? (string) $tick['reason'] : '';
            if ($tick_reason === 'rate_limited') {
                $retry_after = isset($tick['retry_after']) ? (int) $tick['retry_after'] : 60;
                error_log(sprintf(
                    '[WPC PullDrain] rate_limited_exit iter=%d queued_total=%d retry_after=%ds',
                    $polls, $total_queued, $retry_after
                ));


                set_transient('wpc_v2_pull_cooloff', time(), max(10, $retry_after));
                delete_transient('wpc_v2_drain_running');
                exit;
            }
            $queued_this = isset($tick['queued']) ? (int) $tick['queued'] : 0;
            $total_queued += $queued_this;


            $wake_ingested += isset($tick['lazycdn_ingested']) ? (int) $tick['lazycdn_ingested'] : 0;

            if ($queued_this > 0) {
                
                $new_deadline = $now_ms + 30000;
                if ($new_deadline > $deadline_ms) {
                    update_option('wpc_v2_drain_alive_until_ms', $new_deadline, false);
                }
                if (function_exists('wpc_v2_journal_drain_run')) {
                    $drain_cap = ($restoring > 0) ? 0.5 : 1.5;
                    wpc_v2_journal_drain_run($drain_cap);
                }
            } else {


                $still_owe_wake_items = ($wake_expect > 0 && $wake_ingested < $wake_expect);


                if ((int) get_transient('wpc_v2_redrain_pending') > 0) {
                    delete_transient('wpc_v2_redrain_pending');
                    error_log('[WPC PullDrain] redrain_pending_continue — wake arrived mid-drain; running another pass');
                    set_transient('wpc_v2_drain_running', time(), 15);
                    continue;
                }
                if (!wpc_v2_pull_drain_needs_continuation() && !$still_owe_wake_items) {
                    error_log(sprintf(
                        '[WPC PullDrain] idle_fast_exit iter=%d queued_total=%d wall_ms=%d — freeing worker (no pending work)',
                        $polls, $total_queued, (int) round((microtime(true) - $started) * 1000)
                    ));


                    delete_option('wpc_v2_drain_alive_until_ms');
                    delete_transient('wpc_v2_drain_running');
                    exit;
                }
            }

            
            set_transient('wpc_v2_drain_running', time(), 15);
        }


        if ($wake_expect > 0 && $wake_ingested < $wake_expect) {
            error_log(sprintf('[WPC PullDrain] wake_items_incomplete expect=%d ingested=%d', $wake_expect, $wake_ingested));
        }
        $has_work = ($total_queued > 0) || wpc_v2_pull_drain_needs_continuation()


            || ((int) get_transient('wpc_v2_redrain_pending') > 0);
        error_log(sprintf(
            '[WPC PullDrain] iter_budget_hit iter=%d queued_total=%d wall_ms=%d — %s',
            $polls, $total_queued, (int) round((microtime(true) - $started) * 1000),
            $has_work ? 'self-chain' : 'idle_exit'
        ));
        delete_transient('wpc_v2_drain_running');


        wpc_v2_pull_note_progress((int) ($total_queued + $wake_ingested), (bool) $has_work);
        if ($has_work && wpc_v2_pull_backoff_until() <= time()) {
            wpc_v2_pull_drain_fire();
        }
        exit;
    }
}


if (!function_exists('wpc_v2_active_restore_count')) {
    function wpc_v2_active_restore_count()
    {


        wp_cache_delete('wps_ic_bulk_process', 'options');
        $bp = get_option('wps_ic_bulk_process');
        if (is_array($bp) && isset($bp['status']) && $bp['status'] === 'restoring') {
            return 1;
        }
        return 0;
    }
}


if (!function_exists('wpc_v2_pending_live_count')) {
    







    function wpc_v2_pending_live_count($cap = 8)
    {
        global $wpdb;
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d",
            $wpdb->esc_like('_transient_wpc_v2_pending_') . '%', max(1, (int) $cap)
        ));
        $live = 0;
        foreach ((array) $rows as $row) {
            $key = substr((string) $row, strlen('_transient_'));
            if ($key !== '' && get_transient($key) !== false) {
                $live++;
            }
        }
        return $live;
    }
}

if (!function_exists('wpc_v2_pull_drain_needs_continuation')) {
    function wpc_v2_pull_drain_needs_continuation()
    {
        global $wpdb;
        if (wpc_v2_pending_live_count(8) > 0) {
            return true;
        }
        
        
        
        wp_cache_delete('wps_ic_bulk_process', 'options');
        $wpc_bp834 = get_option('wps_ic_bulk_process');
        $wpc_bulk834 = is_array($wpc_bp834) && isset($wpc_bp834['status'])
            && in_array((string) $wpc_bp834['status'], ['queueing', 'optimizing', 'restoring'], true);
        if (!$wpc_bulk834) {
            return false;
        }
        $active = $wpdb->get_var(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key='ic_compressing' "
            . "AND (meta_value LIKE '%queueing%' OR meta_value LIKE '%optimizing%') LIMIT 1"
        );
        return (bool) $active;
    }
}


if (!function_exists('wpc_v2_pull_drain_pending_anywhere')) {
    function wpc_v2_pull_drain_pending_anywhere()
    {
        return wpc_v2_pending_live_count(8) > 0;
    }
}


if (!function_exists('wpc_v2_pull_manifest_tick')) {
    function wpc_v2_pull_manifest_tick($limit = 100, $wait_ms = 0)
    {
        if (!wpc_v2_pull_enabled()) {
            return ['ok' => false, 'reason' => 'flag_off'];
        }

        
        if (get_transient('wpc_bulk_stop_signal')) {
            return ['ok' => false, 'reason' => 'bulk_stopped'];
        }
        
        

        if (wpc_v2_active_restore_count() > 0) {
            update_option('wpc_v2_drain_alive_until_ms', (int) (microtime(true) * 1000) + 60000, false);
            return ['ok' => false, 'reason' => 'restore_active_yield'];
        }

        $started = microtime(true);
        $since   = wpc_v2_pull_get_cursor();

        $fetch = wpc_v2_pull_manifest_fetch($since, $limit, $wait_ms);


        $t_fetch_ms = (int) round((microtime(true) - $started) * 1000);
        if (empty($fetch['ok'])) {
            
            
            $ret = [
                'ok'      => false,
                'reason'  => isset($fetch['error']) ? $fetch['error'] : 'fetch_failed',
                'wall_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
            if (isset($fetch['retry_after'])) {
                $ret['retry_after'] = (int) $fetch['retry_after'];
            }
            return $ret;
        }

        $variants = $fetch['variants'];
        if (empty($variants)) {

            
            if (!empty($fetch['cursor_high_water_ms'])) {
                wpc_v2_pull_set_cursor((int) $fetch['cursor_high_water_ms']);
            }
            return [
                'ok'      => true,
                'queued'  => 0,
                'pages'   => (int) $fetch['pages_fetched'],
                'wall_ms' => (int) round((microtime(true) - $started) * 1000),
            ];
        }

        $t_q0  = microtime(true);
        $queue = wpc_v2_pull_manifest_queue_for_drain($variants);
        $t_queue_ms = (int) round((microtime(true) - $t_q0) * 1000);


        if (!empty($fetch['cursor_high_water_ms'])) {
            $cursor_to  = (int) $fetch['cursor_high_water_ms'];
            $min_failed = (int) ($queue['min_failed_completed_ms'] ?? 0);
            if ($min_failed > 0 && ($min_failed - 1) < $cursor_to) {
                $cursor_to = $min_failed - 1;
            }
            wpc_v2_pull_set_cursor($cursor_to);
        }

        


        $lazycdn_ack_set = [];
        if (isset($queue['lazycdn_acked_sha256s']) && is_array($queue['lazycdn_acked_sha256s'])) {
            foreach ($queue['lazycdn_acked_sha256s'] as $s) {
                $lazycdn_ack_set[(string) $s] = true;
            }
        }
        


        $journal_failed_set = [];
        if (isset($queue['journal_failed_sha256s']) && is_array($queue['journal_failed_sha256s'])) {
            foreach ($queue['journal_failed_sha256s'] as $s) {
                $journal_failed_set[(string) $s] = true;
            }
        }
        $acks = [];
        foreach ($variants as $v) {
            if (!is_array($v) || empty($v['sha256'])) continue;
            $sha = (string) $v['sha256'];
            
            $is_lazycdn_entry = (isset($v['source']) && $v['source'] === 'lazycdn')
                             || (isset($v['tags']['source']) && $v['tags']['source'] === 'lazycdn')
                             || (isset($v['imageID']) && is_string($v['imageID']) && strpos($v['imageID'], 'lazycdn') === 0);
            if ($is_lazycdn_entry) {
                
                if (!isset($lazycdn_ack_set[$sha])) continue;


                $acks[] = [
                    'imageID'   => isset($v['imageID']) ? (int) $v['imageID'] : 0,
                    'sizeLabel' => isset($v['sizeLabel']) ? (string) $v['sizeLabel'] : '',
                    'format'    => isset($v['format'])    ? (string) $v['format']    : '',
                    'sha256'    => $sha,
                ];
                continue;
            }
            
            if (empty($v['imageID'])) continue;

            
            if (isset($journal_failed_set[$sha])) continue;
            $acks[] = [
                'imageID'   => (int) $v['imageID'],
                'sizeLabel' => isset($v['sizeLabel']) ? (string) $v['sizeLabel'] : '',
                'format'    => isset($v['format'])    ? (string) $v['format']    : '',
                'sha256'    => $sha,
            ];
        }
        $wpc_failed197 = isset($queue['failed_entries197']) && is_array($queue['failed_entries197'])
            ? $queue['failed_entries197'] : [];
        $wpc_rejected_all197 = (count($variants) > 0
            && (int) $queue['skipped_invalid'] === count($variants)
            && (int) $queue['queued'] === 0
            && (int) ($queue['lazycdn_ingested'] ?? 0) === 0);
        if ($wpc_rejected_all197 && count($wpc_failed197) < 50) {
            $wpc_failed197[] = ['imageID' => '', 'sizeLabel' => '', 'format' => '', 'reason' => 'all_variants_rejected_cursor_advanced'];
        }
        $wpc_receipt197 = [
            'seen'     => count($variants),
            'ingested' => (int) $queue['queued'] + (int) ($queue['lazycdn_ingested'] ?? 0),
            'failed'   => $wpc_failed197,
        ];
        $t_a0 = microtime(true);
        if ((!empty($acks) || !empty($wpc_failed197)) && function_exists('wpc_v2_pull_manifest_ack')) {
            wpc_v2_pull_manifest_ack($acks, $wpc_receipt197);
        }
        $t_ack_ms = (int) round((microtime(true) - $t_a0) * 1000);

        
        if ($queue['queued'] > 0 && function_exists('wpc_v2_journal_fire_loopback_fast')) {
            wpc_v2_journal_fire_loopback_fast();
        }


        $lazycdn_ingested = (int) ($queue['lazycdn_ingested'] ?? 0);
        $lazycdn_failed   = (int) ($queue['lazycdn_failed']   ?? 0);

        error_log(sprintf(
            '[WPC PullManifest] tick since=%d high=%d pages=%d variants=%d queued=%d skip_dedup=%d skip_invalid=%d lazycdn_ingested=%d lazycdn_failed=%d images=%d wall_ms=%d fetch_ms=%d queue_ms=%d ack_ms=%d',
            $since,
            (int) $fetch['cursor_high_water_ms'],
            (int) $fetch['pages_fetched'],
            count($variants),
            (int) $queue['queued'],
            (int) $queue['skipped_dedup'],
            (int) $queue['skipped_invalid'],
            $lazycdn_ingested,
            $lazycdn_failed,
            count($queue['imageIDs']),
            (int) round((microtime(true) - $started) * 1000),
            $t_fetch_ms,
            $t_queue_ms,
            $t_ack_ms
        ));


        $total_variants = count($variants);
        if ($total_variants > 0
            && (int) $queue['skipped_invalid'] === $total_variants
            && (int) $queue['queued'] === 0
            && $lazycdn_ingested === 0) {
            error_log(sprintf(
                '[WPC PullManifest] WARNING all_variants_rejected since=%d high=%d variants=%d — cursor advanced past entries that 100%% failed validation; orch 7d TTL backstops re-delivery but this is the silent-skip case',
                $since,
                (int) $fetch['cursor_high_water_ms'],
                $total_variants
            ));
        }

        return [
            'ok'              => true,
            'queued'          => (int) $queue['queued'],
            'skipped_dedup'   => (int) $queue['skipped_dedup'],
            'skipped_invalid' => (int) $queue['skipped_invalid'],
            'lazycdn_ingested' => $lazycdn_ingested,
            'lazycdn_failed'   => $lazycdn_failed,
            'imageIDs'        => $queue['imageIDs'],
            'pages'           => (int) $fetch['pages_fetched'],
            'cursor'          => (int) $fetch['cursor_high_water_ms'],
            'wall_ms'         => (int) round((microtime(true) - $started) * 1000),
        ];
    }
}


if (!function_exists('wpc_v2_pull_reconcile_tick')) {
    function wpc_v2_pull_reconcile_tick()
    {


        if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
            return;
        }
        if (!function_exists('wpc_v2_pull_enabled') || !wpc_v2_pull_enabled()) {
            return;
        }
        
        
        if (get_transient('wpc_v2_pull_reconcile_throttle')) {
            return;
        }
        
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            return;
        }

        
        $wpc_prt5 = (int) get_option('wpc_v2_pull_reconcile_at');
        if (time() - $wpc_prt5 < 5 * MINUTE_IN_SECONDS) {
            return;
        }
        update_option('wpc_v2_pull_reconcile_at', time(), false);
        set_transient('wpc_v2_pull_reconcile_throttle', time(), 5 * MINUTE_IN_SECONDS);


        if (function_exists('wpc_v2_register_deferred_pull_drain')) {
            wpc_v2_register_deferred_pull_drain();
        } elseif (function_exists('wpc_v2_pull_drain_fire')) {
            wpc_v2_pull_drain_fire();
        }
    }
    add_action('shutdown', 'wpc_v2_pull_reconcile_tick', 5);
}


if (!function_exists('wpc_v2_pull_cron_run')) {
    function wpc_v2_pull_cron_run()
    {
        if (!function_exists('wpc_v2_pull_enabled')) {
            
            if (defined('WPS_IC_DIR') && @is_file(WPS_IC_DIR . 'addons/v2/v2-bootstrap.php')) {
                include_once WPS_IC_DIR . 'addons/v2/v2-bootstrap.php';
            }
        }
        if (!function_exists('wpc_v2_pull_enabled') || !wpc_v2_pull_enabled()) {
            return;
        }
        if (function_exists('wpc_v2_pull_drain_fire')) {
            wpc_v2_pull_drain_fire();
        }
    }
}
add_action('wpc_v2_pull_cron', 'wpc_v2_pull_cron_run');
add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['wpc_v2_5min'])) {
        $schedules['wpc_v2_5min'] = ['interval' => 5 * MINUTE_IN_SECONDS, 'display' => 'Every 5 minutes (WPC v2)'];
    }
    return $schedules;
});
add_action('init', function () {
    if (!function_exists('wpc_v2_pull_enabled') || !wpc_v2_pull_enabled()) {
        
        if (function_exists('wp_next_scheduled') && wp_next_scheduled('wpc_v2_pull_cron')) {
            wp_clear_scheduled_hook('wpc_v2_pull_cron');
        }
        return;
    }
    if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_v2_pull_cron')) {

        


        $wpc_cron_jit = 0;
        if (function_exists('site_url')) {
            $wpc_cron_jit = (int) (hexdec(substr(md5((string) site_url()), 0, 6)) % 300);
        }
        wp_schedule_event(time() + 60 + $wpc_cron_jit, 'wpc_v2_5min', 'wpc_v2_pull_cron');
    }
}, 20);
