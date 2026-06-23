<?php
/**
 * WP Compress v7.05.0 — Lazy-CDN wake-ping endpoint.
 *
 * Receives tiny (~200 byte) HMAC-signed POSTs from orch's wake-ping
 * dispatcher (orch v3.18.74+) when a manifest entry tagged source=lazycdn
 * has new bytes ready. Plugin's job here is intentionally minimal:
 *
 *   1. HMAC-verify the request (300s replay window matches LAZY_CDN_WAKE_REPLAY_WINDOW_S env)
 *   2. Per-IP rate-limit on auth failures so an attacker who finds the
 *      URL can't burn FPM workers hammering HMAC verifies
 *   3. Idempotently kick the EXISTING pull-drain infrastructure
 *      (wpc_v2_pull_drain_fire) which has its own transient lock
 *   4. Return 200 in <50ms so the orch's wake-retry queue doesn't spin
 *
 * Why we reuse wpc_v2_pull_drain_fire vs Action Scheduler:
 *   - The existing drain has battle-tested fsockopen loopback dispatch
 *     with idempotent transient locking (wpc_v2_drain_running, 15s TTL)
 *   - It already calls wpc_v2_pull_manifest_fetch → queue_for_drain →
 *     journal drain, which is where source-routing for lazycdn will be added
 *   - Zero new infrastructure dependency. Action Scheduler bundling can be
 *     evaluated in v7.06.0 if telemetry shows fsockopen failures are common
 *
 * Spec reference: LAZY_CDN_FINAL_SPEC v2.0, plugin team scope section.
 * Revert: delete this file + remove require_once from v2-bootstrap.php.
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
            'permission_callback' => '__return_true',  // HMAC is the auth
        ]);
    }
}
add_action('rest_api_init', 'wpc_v2_wake_register_route');

/**
 * Per-IP rate limit gate. Returns true if request should be REJECTED.
 *
 * Logic:
 *   - Auth-failure counter at wpc_wake_rl_{ip_hash16} (300s TTL)
 *   - After 3 consecutive auth failures from same IP, throttle that
 *     IP to 60 req/min for 5min (set throttle marker, reject all
 *     requests until expiry)
 *   - Successful auth resets the counter
 *
 * Why transient-based: works on every WP host (no Redis dependency).
 * Cost per request: 2 transient reads + 1 write on failure path.
 * Hot path (auth success): 1 transient read + 1 delete. Sub-1ms.
 */
if (!function_exists('wpc_v2_wake_is_rate_limited')) {
    function wpc_v2_wake_is_rate_limited($ip)
    {
        $ip_hash = substr(hash('sha256', (string) $ip), 0, 16);
        $throttle_key = 'wpc_wake_thr_' . $ip_hash;
        if (get_transient($throttle_key)) {
            return true;  // hard-blocked
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
        set_transient($counter_key, $count, 300);  // 5min counter window

        // After 3 failures, install hard-block for 5min
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

/**
 * Wake-ping endpoint handler.
 *
 * Expected request:
 *   POST /wp-json/wpc/v2/wake
 *   Headers: X-WPC-Sig: t=<unix_seconds>,v1=<hmac-sha256-hex>
 *   Body: { "apikey": "...", "since": <ms>, "count": <int> }
 *
 * Response: 200 {ok:true} | 401 hmac_fail | 429 rate_limited
 *
 * Worker time target: <50ms total. Path:
 *   - parse body / sig
 *   - rate limit check (1 transient read)
 *   - HMAC verify (sha256 over body + ts compare)
 *   - dispatch existing pull drain (sets 1 transient, fires fsockopen)
 *   - return
 */
if (!function_exists('wpc_v2_wake_note')) {
    // Remote wake observability: record the last wake's outcome on the healthcheck.
    // Closes the "is the wake even arriving?" question without needing orch logs. The
    // wake is the PRIMARY drain path (cron is only the ~5min floor), so seeing it land matters.
    function wpc_v2_wake_note($outcome, $extra = [])
    {
        update_option('wpc_v2_last_wake', array_merge(['t' => time(), 'outcome' => (string) $outcome], $extra), false);
    }
}
if (!function_exists('wpc_v2_wake_handler')) {
    function wpc_v2_wake_handler($request)
    {
        $start_t = microtime(true);
        $ip = isset($_SERVER['HTTP_X_FORWARDED_FOR'])
            ? trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
            : (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '0.0.0.0');

        // Hard-block by IP if previously rate-limited
        if (wpc_v2_wake_is_rate_limited($ip)) {
            wpc_v2_wake_note('rate_limited');
            return new WP_REST_Response(['error' => 'rate_limited'], 429);
        }

        $raw_body   = $request->get_body();
        $sig_header = $request->get_header('X-WPC-Sig');

        // HMAC verify with 300s replay window (matches orch's
        // LAZY_CDN_WAKE_REPLAY_WINDOW_S=300 env). The helper at
        // v2-callback.php:654 accepts an optional 3rd arg as of v7.05.0.
        $verify = wpc_v2_verify_hmac($sig_header, $raw_body, 300);
        if (!$verify['ok']) {
            wpc_v2_wake_record_auth_failure($ip);
            error_log(sprintf(
                '[WPC Wake] hmac_fail ip_hash=%s reason=%s',
                substr(hash('sha256', $ip), 0, 16),
                isset($verify['reason']) ? $verify['reason'] : 'unknown'
            ));
            wpc_v2_wake_note('hmac_fail', ['reason' => isset($verify['reason']) ? (string) $verify['reason'] : 'unknown']);
            return new WP_REST_Response(['error' => 'hmac_fail'], 401);
        }

        // Auth success — clear any previous failure counter for this IP
        wpc_v2_wake_clear_auth_failures($ip);

        // T2 capture for cross-system race verification.
        //
        // Orch's v3.18.96 dedup-republish fix awaits writeManifestEntry
        // before firing the wake, but the only way to PROVE the fix is
        // working in production (vs just "writes are succeeding") is to
        // correlate, per-wake, the wall-clock arrival of the wake at the
        // plugin's handler (T2) against the manifest_write_result
        // event on orch (now in the ring buffer at
        // /admin/recent-manifest-writes). Without per-wake T2, we'd
        // only see aggregate "writes succeed" and have no way to catch a
        // silent regression where the await is bypassed on a sibling
        // code path that wasn't fixed in lockstep.
        //
        // Required fields from the wake POST body (orch sends these
        // since v3.18.96+):
        //   - imageID         (e.g. lazycdn-f9e6019bbef66faa)
        //   - sizeLabel       (e.g. 1510w)
        //   - format          (e.g. avif)
        //   - trace_id        (orch's jobId; correlates to manifest_write_result)
        //
        // Fallback: when orch sends the old shape (no per-event fields,
        // just apikey/since/count), the T2 line still fires with the
        // available fields and notes the missing ones — so we can
        // detect old-shape wakes vs new-shape during the rollout.
        $body_parsed = !empty($raw_body) ? json_decode($raw_body, true) : null;

        // Normalize the wake into $wake_items[]. The newer shape (orch wake-on-ready,
        // v3.21.4+ per reference_orch_wake_spec) carries an items[] array of ready variants, each
        // {imageID, sizeLabel, format, trace_id}; an intermediate shape put a single event at the top
        // level; the old on-request shape has neither (items=[]). We read items[] first, fall back to a
        // single top-level event, else empty. This is the structure the targeted-ingest path will
        // consume (thread into the drain loopback); today it also drives accurate shape telemetry.
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
        $t2_orch_trace = $request->get_header('X-Orch-Trace');  // future-proof for header trace propagation

        // Extend the pull-drain alive deadline before firing the
        // drain. Without this, the drain loop checks
        // wpc_v2_drain_alive_until_ms, finds it 0 or past, and exits
        // immediately with `deadline_reached iter=0`: the wake-ping
        // arrives but no manifest poll happens, defeating the whole
        // purpose. A wake-ping means orch knows there's a fresh manifest
        // entry to pull, so give the loop 60s to find it (each
        // successful poll extends by 30s, so a steady stream keeps the
        // loop alive until the manifest is drained).
        $target_deadline = $t2_wake_ms + 60000;
        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        $current_deadline = (int) get_option('wpc_v2_drain_alive_until_ms', 0);
        if ($target_deadline > $current_deadline) {
            update_option('wpc_v2_drain_alive_until_ms', $target_deadline, false);
        }

        // Idempotent dispatch: wpc_v2_pull_drain_fire has its own
        // wpc_v2_drain_running transient lock (15s TTL), so concurrent
        // wake calls don't multiply work. Safe to call unconditionally.
        //
        // Phase 2: hand the ready-variant set ($wake_items) to the drain so the worker
        // HOLDS (keeps long-polling within the deadline) for these named items instead of
        // idle-fast-exiting on the first empty tick. With empty $wake_items (an old/on-request wake)
        // the drain behaves exactly as before. Requires orch wake-on-ready (v3.21.4) to carry items[].
        $dispatched = false;
        if (function_exists('wpc_v2_pull_drain_fire')) {
            $dispatched = (bool) wpc_v2_pull_drain_fire($wake_items);
        }
        wpc_v2_wake_note('ok', ['dispatched' => $dispatched, 'items' => is_array($wake_items) ? count($wake_items) : 0]);

        // Loopback-independent drain fallback. A live staging find (from orch
        // receipts): the wake returned 200 {dispatched:true} but ZERO manifest GETs ever reached
        // orch. dispatched only proves the fsockopen self-POST was WRITTEN; the
        // host's security layer killed the unauthenticated admin-ajax request and
        // the fire-and-forget can't see it die. So the fallback: after THIS wake response
        // is flushed (orch still gets its ~50ms 200), wait 3s, and if no worker stamped
        // wpc_v2_drain_worker_started, run the drain loop INLINE on this request,
        // self-signed with the same timestamp HMAC the loopback uses. The handler
        // stamps on entry, so a healthy-loopback host makes this a no-op while a
        // blocked-loopback host drains on the wake request itself.
        if ($dispatched && function_exists('wpc_v2_pull_drain_loop_handler')) {
            $wpc_wake_items_for_inline = $wake_items;
            add_action('shutdown', function () use ($wpc_wake_items_for_inline) {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                } elseif (function_exists('litespeed_finish_request')) {
                    @litespeed_finish_request();
                }
                @ignore_user_abort(true);
                @set_time_limit(150);
                sleep(3); // grace period: let a healthy loopback worker start and stamp
                if (get_transient('wpc_v2_drain_worker_started')) {
                    return; // loopback worker is alive, stand down
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

        // Legacy summary line — keep for back-compat with any log scraping
        error_log(sprintf(
            '[WPC Wake] ok dispatched=%d wall_ms=%d',
            $dispatched ? 1 : 0, $wall_ms
        ));

        // T2 capture line. Single, structured, grep-able. The
        // ring buffer at orch's /admin/recent-manifest-writes provides
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
            // Shape is keyed off the normalized items[]. It used to key off top-level field presence,
            // which false-flagged a new items[]-shape wake as 'old' because the fields are nested, not
            // top level. 'shape=old' now means a genuine on-request wake with no ready-variant items.
            empty($wake_items) ? ' shape=old' : ' shape=new'
        ));

        return new WP_REST_Response([
            'ok'         => true,
            'dispatched' => $dispatched,
            'wall_ms'    => $wall_ms,
            // Echo T2 wake_ms in the response so orch can record
            // T2-T1 deltas at the source. The service team's ring buffer
            // can ingest these on the response side if desired.
            't2_wake_ms' => $t2_wake_ms,
            'trace_id'   => $t2_trace_id,
        ], 200);
    }
}
