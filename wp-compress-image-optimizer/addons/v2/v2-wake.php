<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-wake.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_wake_register_route')) {
    function wpc_v2_wake_register_route()
    {
        register_rest_route('wpc/v2', '/wake', [
            'methods'             => 'POST',
            'callback'            => 'wpc_v2_wake_handler',
            'permission_callback' => '__return_true',  
        ]);
    }
}
add_action('rest_api_init', 'wpc_v2_wake_register_route');


if (!function_exists('wpc_v2_wake_is_rate_limited')) {
    function wpc_v2_wake_is_rate_limited($ip)
    {
        $ip_hash = substr(hash('sha256', (string) $ip), 0, 16);
        $throttle_key = 'wpc_wake_thr_' . $ip_hash;
        if (get_transient($throttle_key)) {
            return true;
        }
        return false;
    }
}

if (!function_exists('wpc_v2_wake_record_auth_failure')) {
    function wpc_v2_wake_record_auth_failure($ip)
    {
        $ip_hash = substr(hash('sha256', (string) $ip), 0, 16);
        $counter_key = 'wpc_wake_rl_' . $ip_hash;
        $count = (int) get_transient($counter_key);
        $count++;
        set_transient($counter_key, $count, 300);  

        
        if ($count >= 3) {
            $throttle_key = 'wpc_wake_thr_' . $ip_hash;
            set_transient($throttle_key, 1, 300);
            error_log(sprintf(
                '[WPC Wake] rate_limit_engaged ip_hash=%s failures=%d',
                $ip_hash, $count
            ));
        }
    }
}

if (!function_exists('wpc_v2_wake_clear_auth_failures')) {
    function wpc_v2_wake_clear_auth_failures($ip)
    {
        $ip_hash = substr(hash('sha256', (string) $ip), 0, 16);
        delete_transient('wpc_wake_rl_'  . $ip_hash);
        delete_transient('wpc_wake_thr_' . $ip_hash);
    }
}


if (!function_exists('wpc_v2_wake_note')) {
    


    function wpc_v2_wake_note($outcome, $extra = [])
    {

        if (function_exists('wpc_v2_ingest_diag_on') && wpc_v2_ingest_diag_on()) {
            update_option('wpc_v2_last_wake', array_merge(['t' => time(), 'outcome' => (string) $outcome], $extra), false);
        }
    }
}
if (!function_exists('wpc_v2_wake_handler')) {
    function wpc_v2_wake_handler($request)
    {
        $start_t = microtime(true);
        $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
            : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0');

        


        if (wpc_v2_wake_is_rate_limited($ip)) {
            return new WP_REST_Response(['error' => 'rate_limited'], 429);
        }

        $raw_body   = $request->get_body();
        $sig_header = $request->get_header('X-WPC-Sig');


        $verify = wpc_v2_verify_hmac($sig_header, $raw_body, 300);
        if (!$verify['ok']) {
            wpc_v2_wake_record_auth_failure($ip);
            error_log(sprintf(
                '[WPC Wake] hmac_fail ip_hash=%s reason=%s',
                substr(hash('sha256', $ip), 0, 16),
                isset($verify['reason']) ? $verify['reason'] : 'unknown'
            ));

            return new WP_REST_Response(['error' => 'hmac_fail'], 401);
        }

        
        wpc_v2_wake_clear_auth_failures($ip);


        if (function_exists('wpc_v2_pull_breaker_reset')) {
            wpc_v2_pull_breaker_reset();
        }


        if (function_exists('wpc_v2_pull_enabled')
            && !wpc_v2_pull_enabled()
            && get_site_option('wpc_v2_pull_enabled', null) === null) {
            update_site_option('wpc_v2_pull_enabled', 1);
            error_log('[WPC Wake] self_heal pull_enabled re-enabled (verified wake + option absent + gate-off = DB-wipe signature)');
            wpc_v2_wake_note('self_heal_pull_enabled');
        }

        
        


        


        $body_parsed = !empty($raw_body) ? json_decode($raw_body, true) : null;


        $wake_items = [];
        $norm_item = function ($src) {
            return [
                'imageID'   => isset($src['imageID'])   ? (string) $src['imageID']   : '',
                'sizeLabel' => isset($src['sizeLabel']) ? (string) $src['sizeLabel'] : '',
                'format'    => isset($src['format'])    ? (string) $src['format']    : '',
                'trace_id'  => isset($src['trace_id'])  ? (string) $src['trace_id']  : '',
            ];
        };
        if (is_array($body_parsed) && !empty($body_parsed['items']) && is_array($body_parsed['items'])) {
            foreach ($body_parsed['items'] as $it) {
                if (is_array($it)) $wake_items[] = $norm_item($it);
            }
        } elseif (is_array($body_parsed) && (isset($body_parsed['imageID']) || isset($body_parsed['sizeLabel']))) {
            $wake_items[] = $norm_item($body_parsed);
        }
        
        $t2_imageID    = !empty($wake_items) ? $wake_items[0]['imageID']   : '';
        $t2_sizeLabel  = !empty($wake_items) ? $wake_items[0]['sizeLabel'] : '';
        $t2_format     = !empty($wake_items) ? $wake_items[0]['format']    : '';
        $t2_trace_id   = !empty($wake_items) ? $wake_items[0]['trace_id']  : '';
        $t2_wake_ms    = (int) (microtime(true) * 1000);  
        $t2_orch_trace = $request->get_header('X-Orch-Trace');


        $target_deadline = $t2_wake_ms + 60000;
        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        $current_deadline = (int) get_option('wpc_v2_drain_alive_until_ms', 0);
        if ($target_deadline > $current_deadline) {
            update_option('wpc_v2_drain_alive_until_ms', $target_deadline, false);
        }


        $dispatched = false;
        if (function_exists('wpc_v2_pull_drain_fire')) {
            $dispatched = (bool) wpc_v2_pull_drain_fire($wake_items);
        }
        wpc_v2_wake_note('ok', ['dispatched' => $dispatched, 'items' => is_array($wake_items) ? count($wake_items) : 0]);


        
        


        if (function_exists('wpc_v2_pull_drain_loop_handler')) {
            $wpc_wake_items_for_inline = $wake_items;
            add_action('shutdown', function () use ($wpc_wake_items_for_inline) {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    @litespeed_finish_request();
                }


                if (get_transient('wpc_v2_inline_drain_pending')) {
                    return;
                }
                set_transient('wpc_v2_inline_drain_pending', 1, 8);
                @ignore_user_abort(true);
                @set_time_limit(150);
                wpc_diag_sleep(3, 'v2-wake');
                if (get_transient('wpc_v2_drain_worker_started')) {
                    return;
                }
                error_log('[WPC Wake] loopback_worker_never_started — running drain inline');
                $apikey_inline = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
                if ($apikey_inline === '') {
                    return;
                }
                $_POST['t']   = (string) time();
                $_POST['sig'] = hash_hmac('sha256', 'wpc_v2_pull_drain.' . $_POST['t'], $apikey_inline);
                if (is_array($wpc_wake_items_for_inline) && !empty($wpc_wake_items_for_inline)) {
                    $items_inline = wp_json_encode(array_slice(array_values($wpc_wake_items_for_inline), 0, 50));
                    if (is_string($items_inline)) {
                        $_POST['items'] = $items_inline;
                    }
                }
                wpc_v2_pull_drain_loop_handler();
            }, PHP_INT_MAX);
        }

        $wall_ms = (int) round((microtime(true) - $start_t) * 1000);

        
        error_log(sprintf(
            '[WPC Wake] ok dispatched=%d wall_ms=%d',
            $dispatched ? 1 : 0, $wall_ms
        ));


        
        
        error_log(sprintf(
            '[WPC Wake] T2 wake_ms=%d trace_id=%s imageID=%s sizeLabel=%s format=%s dispatched=%d orch_trace_hdr=%s items=%d%s',
            $t2_wake_ms,
            $t2_trace_id !== '' ? $t2_trace_id : '(missing)',
            $t2_imageID !== '' ? $t2_imageID : '(missing)',
            $t2_sizeLabel !== '' ? $t2_sizeLabel : '(missing)',
            $t2_format !== '' ? $t2_format : '(missing)',
            $dispatched ? 1 : 0,
            $t2_orch_trace !== null ? $t2_orch_trace : '(missing)',
            count($wake_items),


            empty($wake_items) ? ' shape=old' : ' shape=new'
        ));

        return new WP_REST_Response([
            'ok'         => true,
            'dispatched' => $dispatched,
            'wall_ms'    => $wall_ms,


            't2_wake_ms' => $t2_wake_ms,
            'trace_id'   => $t2_trace_id,
        ], 200);
    }
}
