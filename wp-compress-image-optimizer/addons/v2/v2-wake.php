<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_wake_register_route')) {
    function wpc_v2_wake_register_route()
    {
        register_rest_route('wpc/v2', '/wake', [
            'methods'             => 'POST',
            'callback'            => 'wpc_v2_wake_handler',
            'permission_callback' => '__return_true',  // HMAC is the auth
        ]);
    }
}
add_action('rest_api_init', 'wpc_v2_wake_register_route');


if (!function_exists('wpc_v2_wake_bad_spent')) {
    function wpc_v2_wake_bad_spent()
    {
        return (int) get_transient('wpc_wake_bad') >= max(1, (int) apply_filters('wpc_v2_wake_bad_budget', 20));
    }
}

if (!function_exists('wpc_v2_wake_bad_note')) {
    function wpc_v2_wake_bad_note()
    {
        $count = (int) get_transient('wpc_wake_bad') + 1;
        set_transient('wpc_wake_bad', $count, 300);
        return $count;
    }
}


if (!function_exists('wpc_v2_wake_failed')) {
    /**
     * Rule: every way the wake cannot run leaves image-wake-failed {reason}. Observed failure:
     * a refused or undispatchable wake answered with an error_log line only, so a site whose
     * wakes never reached the drain looked, from the receipts, like a site that got none.
     */
    function wpc_v2_wake_failed($reason)
    {
        wpc_cache_first_log('image-wake-failed', '', '', ['reason' => (string) $reason]);
    }
}

if (!function_exists('wpc_v2_wake_note')) {
    // Remote wake observability: the last wake's outcome, kept by the pull-state owner for the healthcheck.


    function wpc_v2_wake_note($outcome, $extra = [])
    {
        if (function_exists('wpc_v2_pull_state_note')) {
            wpc_v2_pull_state_note('wake', array_merge(['outcome' => (string) $outcome, 'reason' => (string) $outcome], $extra));
        }
    }
}
if (!function_exists('wpc_v2_wake_policy_drain')) {
    /**
     * The policy pull a signed wake owes, run once per request after the response is released,
     * by the wake's last shutdown callback. The image-drain fallback before it hands control
     * back (wpc_pull_loop_inline) instead of exiting, so this callback always runs.
     */
    function wpc_v2_wake_policy_drain()
    {
        if (!empty($GLOBALS['wpc_wake_policy_drained']) || !function_exists('wpc_policy_drain_owed')) {
            return;
        }
        $GLOBALS['wpc_wake_policy_drained'] = true;
        if (function_exists('wpc_finish_request')) {
            wpc_finish_request();
        }
        @ignore_user_abort(true);
        @set_time_limit(150);
        $GLOBALS['wpc_signed_wake_request'] = true;
        wpc_policy_drain_owed('wake');
        unset($GLOBALS['wpc_signed_wake_request']);
    }
}
if (!function_exists('wpc_v2_wake_handler')) {
    function wpc_v2_wake_handler($request)
    {
        $start_t = microtime(true);

        $raw_body   = $request->get_body();
        $sig_header = $request->get_header('X-WPC-Sig');


        $verify = wpc_v2_inbound_verify('wake', $sig_header, $raw_body, ['window' => 300, 'auth_status' => 401]);
        if (!$verify['ok']) {
            wpc_v2_wake_failed((string) $verify['reason']);
            if ($verify['reason'] !== 'bad-signature') {
                return new WP_REST_Response(['error' => $verify['reason']], (int) $verify['status']);
            }
            if (wpc_v2_wake_bad_spent()) {
                wpc_v2_wake_failed('rate-limited');
                return new WP_REST_Response(['error' => 'rate_limited'], 429);
            }
            $bad = wpc_v2_wake_bad_note();
            error_log(sprintf(
                '[WPC Wake] hmac_fail reason=%s bad_in_window=%d',
                isset($verify['detail']) ? $verify['detail'] : 'unknown',
                $bad
            ));

            return new WP_REST_Response(['error' => 'hmac_fail'], 401);
        }


        if (function_exists('wpc_policy_note_wake')) {
            wpc_policy_note_wake();
        }

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

        // T2 capture for cross-system race verification.
        //


        //


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
        // T2 telemetry reads the first item (back-compat with the prior single-event log line).
        $t2_imageID    = !empty($wake_items) ? $wake_items[0]['imageID']   : '';
        $t2_sizeLabel  = !empty($wake_items) ? $wake_items[0]['sizeLabel'] : '';
        $t2_format     = !empty($wake_items) ? $wake_items[0]['format']    : '';
        $t2_trace_id   = !empty($wake_items) ? $wake_items[0]['trace_id']  : '';
        $t2_wake_ms    = (int) (microtime(true) * 1000);  // T2 — wall-clock at handler arrival
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
        if (!$dispatched && function_exists('wpc_v2_pull_enabled') && !wpc_v2_pull_enabled()) {
            wpc_v2_wake_failed('pull-disabled');
        }
        $fire_reason = $dispatched ? 'dispatched' : 'no_fire';
        if (function_exists('wpc_v2_pull_state')) {
            $wake_ps = wpc_v2_pull_state();
            if (isset($wake_ps['fire']['reason']) && (int) $wake_ps['fire']['t'] >= (int) $start_t) {
                $fire_reason = (string) $wake_ps['fire']['reason'];
            }
        }
        wpc_v2_wake_note('ok', ['dispatched' => $dispatched, 'reason' => $fire_reason, 'items' => is_array($wake_items) ? count($wake_items) : 0]);
        if (function_exists('wpc_policy_schedule_resync')) {
            wpc_policy_schedule_resync(5);
        }


        // $dispatched (= the fsockopen self-POST was at least WRITTEN), but on a host where fsockopen can't
        // open the loopback socket AT ALL, $dispatched is false — so the fallback meant to cover a dead


        unset($GLOBALS['wpc_wake_policy_drained']);
        if (function_exists('wpc_policy_drain_owed')) {
            add_action('shutdown', 'wpc_v2_wake_policy_drain', PHP_INT_MAX);
        }

        if (function_exists('wpc_v2_pull_drain_loop_handler')) {
            $wpc_wake_items_for_inline = $wake_items;
            add_action('shutdown', function () use ($wpc_wake_items_for_inline) {
                if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) {
                    wpc_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    @litespeed_finish_request();
                }

                if (get_transient('wpc_v2_inline_drain_pending')) {
                    wpc_v2_pull_state_note('inline', ['reason' => 'already_pending']);
                    return;
                }
                set_transient('wpc_v2_inline_drain_pending', 1, 8);
                @ignore_user_abort(true);
                @set_time_limit(150);
                wpc_diag_sleep(3, 'v2-wake');
                if (get_transient('wpc_v2_drain_worker_started')) {
                    wpc_v2_pull_state_note('inline', ['reason' => 'worker_started']);
                    return;
                }
                error_log('[WPC Wake] loopback_worker_never_started — running drain inline');
                $apikey_inline = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
                if ($apikey_inline === '') {
                    wpc_v2_wake_failed('no-apikey');
                    wpc_v2_pull_state_note('inline', ['reason' => 'no_apikey']);
                    return;
                }
                $_POST['t']   = (string) time();
                $_POST['src'] = 'inline';
                $_POST['sig'] = hash_hmac('sha256', 'wpc_v2_pull_drain.' . $_POST['t'], $apikey_inline);
                if (is_array($wpc_wake_items_for_inline) && !empty($wpc_wake_items_for_inline)) {
                    $items_inline = wp_json_encode(array_slice(array_values($wpc_wake_items_for_inline), 0, 50));
                    if (is_string($items_inline)) {
                        $_POST['items'] = $items_inline;
                    }
                }
                wpc_v2_pull_state_note('inline', ['reason' => 'ran']);
                $GLOBALS['wpc_pull_loop_inline'] = true;
                wpc_v2_pull_drain_loop_handler();
                unset($GLOBALS['wpc_pull_loop_inline']);
            }, PHP_INT_MAX - 1);
        }

        $wall_ms = (int) round((microtime(true) - $start_t) * 1000);

        // Legacy summary line — keep for back-compat with any log scraping
        error_log(sprintf(
            '[WPC Wake] ok dispatched=%d wall_ms=%d',
            $dispatched ? 1 : 0, $wall_ms
        ));


        // T1 (write completion). Diff(T2 - T1) is the cross-system race
        // window. If T2 < T1 anywhere, the await was bypassed.
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

        $answer = [
            'ok'         => true,
            'dispatched' => $dispatched,
            'wall_ms'    => $wall_ms,


            't2_wake_ms' => $t2_wake_ms,
            'trace_id'   => $t2_trace_id,
        ];
        if (!$dispatched) {
            $answer['reason'] = $fire_reason;
        }
        if (function_exists('wpc_v2_pull_state_last')) {
            $answer = array_merge($answer, wpc_v2_pull_state_last());
        }
        return new WP_REST_Response($answer, 200);
    }
}
