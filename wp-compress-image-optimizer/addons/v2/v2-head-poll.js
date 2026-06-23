/**
 * WP Compress v7.03.0 — /admin/customer-activity HEAD poller.
 *
 * Belt-and-suspenders cache-bust for `wpc_bulk_library_counts`. The orch
 * publishes the latest Phase B callback timestamp via a HEAD response
 * header; when that timestamp advances past the value we last saw, the
 * server-side handler deletes the transient so the splash count snaps
 * fresh on the next render.
 *
 * Cadence: 5s while a bulk job is on-screen, 30s on the splash, off
 * (still POSTs once after page load) otherwise. Backoff to 60s on
 * three consecutive errors. Stops entirely if the handler reports
 * enabled:false (flag gate respected even if the script was enqueued).
 *
 * Enqueued only when wpc_v2_head_poll_enabled() is true.
 */
(function () {
    'use strict';

    if (typeof window === 'undefined' || !window.jQuery) return;
    var $ = window.jQuery;
    var ajaxurl = (window.wpc_ajaxVar && window.wpc_ajaxVar.ajaxurl) || window.ajaxurl;
    var nonce   = (window.wpc_ajaxVar && window.wpc_ajaxVar.nonce)   || '';
    if (!ajaxurl) return;

    var TICK_ACTIVE_MS = 5000;
    var TICK_IDLE_MS   = 30000;
    var TICK_BACKOFF_MS = 60000;

    var errCount = 0;
    var stopped  = false;
    var timer    = null;

    function pickInterval() {
        // Active = a bulk surface is visible on the page.
        var $surface = $('.wpc-bulk-v2-surface, .wpc-restore-surface');
        if ($surface.length && $surface.filter(':visible').length) {
            return TICK_ACTIVE_MS;
        }
        return TICK_IDLE_MS;
    }

    function schedule(ms) {
        if (stopped) return;
        if (timer) clearTimeout(timer);
        timer = setTimeout(tick, ms);
    }

    function tick() {
        if (stopped) return;
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            // v7.03.0 (post-audit fix) — server reads $_POST['wps_ic_nonce']
            // (convention across the plugin's admin AJAX endpoints). Sending it
            // under any other key fails the nonce check silently.
            data: { action: 'wps_ic_check_customer_activity', wps_ic_nonce: nonce },
            timeout: 6000,
            success: function (resp) {
                var d = (resp && resp.data) || {};
                if (d.enabled === false) {
                    // Server says flag is off — stand down for the session.
                    stopped = true;
                    return;
                }
                errCount = 0;
                if (d.busted) {
                    try {
                        window.dispatchEvent(new CustomEvent('wpc:bulk-counts-busted', { detail: d }));
                    } catch (e) { /* legacy IE swallow */ }

                    // v7.04 — Activity timestamp advanced on orch side.
                    // Fire a short-poll pull-manifest tick. The long-poll
                    // loop (pullLongPoll below) is the primary path when
                    // wpc_v2_pull_enabled=1; this short-poll fires as a
                    // belt-and-suspenders signal that also kicks the drain
                    // immediately when activity is detected from another
                    // surface.
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: { action: 'wps_ic_pull_manifest', wps_ic_nonce: nonce, wait_ms: 0 },
                        timeout: 12000
                    });
                }
                schedule(pickInterval());
            },
            error: function () {
                errCount++;
                schedule(errCount >= 3 ? TICK_BACKOFF_MS : pickInterval());
            }
        });
    }

    $(function () { schedule(2000); });
    $(window).on('beforeunload', function () { stopped = true; if (timer) clearTimeout(timer); });

    // ─── v7.04 v1.2 — Continuous long-poll loop ──────────────────────────
    //
    // When wpc_v2_pull_enabled=1, plugin holds a single GET /optimize-v2/manifest
    // connection open via wp_remote_get with wait_ms=25000. Orch returns
    // immediately on first new variant. Plugin re-fires the next long-poll
    // on every response — no setTimeout between cycles. Push-equivalent
    // latency (~50ms transport + encode_wall_ms) without inbound POSTs.
    //
    // Dropped connections (pod restart, network blip) → immediate reconnect
    // with short backoff to avoid hammering during a fleet rolling restart.
    //
    // The server returns enabled:false if the flag is off; loop stops itself
    // for the session.
    var pullStopped         = false;
    var pullErrCount        = 0;
    var pullEnabled         = null;  // null=unknown, true/false set on first response
    var PULL_WAIT_MS        = 25000;
    var PULL_RECONNECT_MS   = 250;   // immediate reconnect on clean drop
    var PULL_BACKOFF_MS     = 5000;  // after 3 consecutive errors

    function pullLongPoll() {
        if (pullStopped) return;

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            // Long-poll: server holds for up to 25s. Add 5s client buffer.
            timeout: PULL_WAIT_MS + 5000,
            data: {
                action: 'wps_ic_pull_manifest',
                wps_ic_nonce: nonce,
                wait_ms: PULL_WAIT_MS,
                limit: 100
            },
            success: function (resp) {
                var d = (resp && resp.data) || {};
                if (d.enabled === false) {
                    pullEnabled = false;
                    pullStopped = true;  // flag is off — stand down for the session
                    return;
                }
                pullEnabled = true;
                pullErrCount = 0;

                if (d.queued && d.queued > 0) {
                    try {
                        window.dispatchEvent(new CustomEvent('wpc:pull-manifest-landed', { detail: d }));
                    } catch (e) { /* swallow */ }
                    // Cursor advanced + variants journaled — also bust the
                    // splash-count transient since the head-poll's busted
                    // signal subsumes this when wait_ms responses arrive.
                    try {
                        window.dispatchEvent(new CustomEvent('wpc:bulk-counts-busted', { detail: d }));
                    } catch (e) { /* swallow */ }
                }

                // Reconnect immediately — long-poll model. The server holds
                // when there's nothing new; the response IS the signal.
                setTimeout(pullLongPoll, PULL_RECONNECT_MS);
            },
            error: function (xhr, status) {
                pullErrCount++;
                // Pod restart / network blip: reconnect immediately. Standard
                // long-poll pattern. Plugin's existing retry-backoff on
                // dropped connections is fine but we add a small ceiling so
                // a flapping orch doesn't get hammered.
                var delay = pullErrCount >= 3 ? PULL_BACKOFF_MS : PULL_RECONNECT_MS * 4;
                setTimeout(pullLongPoll, delay);
            }
        });
    }

    // Start the long-poll loop after a brief grace period so the page can
    // finish initial render + the head-poll above gets first-tick visibility.
    $(function () { setTimeout(pullLongPoll, 3000); });
    $(window).on('beforeunload', function () { pullStopped = true; });
})();
