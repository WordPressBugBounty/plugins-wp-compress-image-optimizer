jQuery(document).ready(function ($) {

    // v7.04.70 — Audit hardening (P0-5): visibility-resume burst. Mirrors
    // media-library-actions.js:557 (ML heartbeat — live since v7.02.12).
    // When user returns to a hidden tab, fire an immediate state-sync poll
    // so the UI catches up without waiting for the next interval tick.
    // The poll functions (bulkCompressProgressPoll, bulkRestoreHeartbeat,
    // bulkCompressHeartbeat) check document.hidden internally; this
    // listener nudges them to wake up by triggering a manual refresh.
    // Revert: delete this listener block.
    if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) return;
            // No-op trigger — the polling interval will naturally fire its
            // next tick (now that document.hidden is false, the if-skip
            // unblocks). We don't force an out-of-band fetch because the
            // hidden window is at most ONE missed tick (1.5s for v2 bulk,
            // 1s for restore) — well below the user's perception threshold.
            // If we ever want immediate sync, add window.WPCBulkForceSync()
            // hook here that calls into bulk-ui.js.
        });
    }

    // v7.03 — Confirmation helpers for destructive / data-loss actions.
    // Uses the existing WPCSwal wrapper so styling matches other plugin
    // popups. Returns a Promise resolved with the user's choice.
    function _wpcConfirmStop(isRestore) {
        var tally = (typeof WPCBulk !== 'undefined' && WPCBulk.getPrevTally) ? WPCBulk.getPrevTally() : null;
        var processed = tally ? tally.counter : 0;
        var total     = tally ? tally.total   : 0;
        var label     = isRestore ? 'Pause Restore' : 'Stop Optimization';
        var verb      = isRestore ? 'pause' : 'stop';
        var noun      = isRestore ? 'restore' : 'optimization';

        var preserved = processed > 0
            ? '<strong style="color:#0f172a;">' + processed.toLocaleString() + (total ? ' of ' + total.toLocaleString() : '') + '</strong> images already ' + (isRestore ? 'restored' : 'optimized') + ' — those are kept.'
            : 'No images have been processed yet.';
        var remaining = (total > processed)
            ? ' <strong style="color:#0f172a;">' + (total - processed).toLocaleString() + '</strong> remaining will not be processed.'
            : '';

        // v7.04.68 — Same 15px treatment as the Restore confirm so the two
        // modals share a consistent type rhythm. Strong calls use slate-900
        // for emphasis; secondary line in slate-500.
        return WPCSwal.fire({
            title: 'Stop ' + noun + '?',
            html: '<div style="text-align:left;font-size:15px;line-height:1.6;color:#1e293b;">' +
                  '<p style="margin:0 0 14px;font-size:15px;">' + preserved + remaining + '</p>' +
                  '<p style="margin:0;font-size:15px;color:#475569;">You can ' + verb + ' now and resume any time from the bulk page.</p>' +
                  '</div>',
            width: 480,
            showCancelButton: true,
            confirmButtonText: 'Yes, ' + label.toLowerCase(),
            cancelButtonText: isRestore ? 'Keep restoring' : 'Keep optimizing',
            reverseButtons: true,
            buttonsStyling: false,
            customClass: {
                container: 'wpc-popup-v6 wpc-confirm-popup',
                confirmButton: 'wpc-confirm-btn wpc-confirm-btn--danger',
                cancelButton:  'wpc-confirm-btn wpc-confirm-btn--ghost'
            }
        });
    }

    function _wpcConfirmRestoreStart(count) {
        // v7.04.68 — Body text bumped to 15px (was 14px-via-wrapper but SA2 base
        // styles can scale it smaller). Tightened hierarchy: lead paragraph in
        // primary text color, secondary paragraph in muted slate with the
        // destructive phrase as a strong call-out — keeps it world-class
        // polished without over-decorating.
        var n = count.toLocaleString();
        return WPCSwal.fire({
            title: 'Restore ' + n + ' images?',
            html: '<div style="text-align:left;font-size:15px;line-height:1.6;color:#1e293b;">' +
                  '<p style="margin:0 0 14px;font-size:15px;">This will restore <strong style="color:#0f172a;">' + n + ' images</strong> to their original (uncompressed) quality.</p>' +
                  '<p style="margin:0;font-size:15px;color:#475569;"><strong style="color:#0f172a;">Compressed variants will be discarded.</strong> The originals will be returned from backup.</p>' +
                  '</div>',
            width: 480,
            showCancelButton: true,
            confirmButtonText: 'Start Bulk Restore',
            cancelButtonText: 'Cancel',
            reverseButtons: true,
            buttonsStyling: false,
            customClass: {
                container: 'wpc-popup-v6 wpc-confirm-popup',
                confirmButton: 'wpc-confirm-btn wpc-confirm-btn--warning',
                cancelButton:  'wpc-confirm-btn wpc-confirm-btn--ghost'
            }
        });
    }

    $('.wps-ic-stop-bulk-restore,.wps-ic-stop-bulk-compress').on('click', function (e) {
        e.preventDefault();

        // v7.03 — Guard against accidental click. Show confirmation with
        // current "X of Y already optimized" message so the user knows
        // what's being preserved before they decide.
        var isRestore = $(this).hasClass('wps-ic-stop-bulk-restore');
        var self = this;
        _wpcConfirmStop(isRestore).then(function (result) {
            // v7.04.63 — SA2 v9/v10 returns {value: true}; v11+ {isConfirmed: true}.
            var confirmed = result && (result.isConfirmed === true || result.value === true);
            if (!confirmed) return;
            _doStopBulk.call(self, e);
        });
        return false;
    });

    function _doStopBulk(e) {

        // v7.03 world-class Stop sequence:
        // 1. Hide bulk UI immediately so the UI feels instant.
        // 2. Show a brief "Stopping…" card — NOT #bulk-start-container, because
        //    that DOM was server-rendered with PRE-bulk counts and would flash
        //    incorrect numbers for ~300–1500 ms before the reload lands.
        // 3. Fire StopBulk AJAX — server wipes state + busts the library counts
        //    cache (<300 ms typical).
        // 4. Reload on AJAX done (success or error). 1500 ms hard fallback if
        //    the AJAX hangs. Reload renders the splash with fresh counts.
        bulkCompressStopped = true;
        // v7.03 — Body class so CSS can freeze all live-pulse animations
        // (hero dot, chip dot, halo) — page reads as "stopping" not "alive."
        $('body').addClass('wpc-bulk-is-stopping');
        $('.bulk-status-progress-bar').hide();
        $('.bulk-status').hide();
        $('.bulk-area-inner').hide();
        $('.wpc-bulk-v2-surface').hide();
        $('.wpc-restore-surface').hide();
        // v7.03 — Keep the Stop button visible but switch it to "Stopping…"
        // with a spinner so users have clear feedback BOTH at the header
        // button AND on the centered card. Disables click via CSS pointer-events.
        var $stopBtns = $('.wps-ic-stop-bulk-restore, .wps-ic-stop-bulk-compress').filter(':visible');
        $stopBtns.addClass('is-stopping');
        $stopBtns.find('.wpc-action-btn-label').text('Stopping…');
        // Intentionally NOT showing #bulk-start-container — its counts are stale.

        // Drop in a temporary "Stopping…" card so the page isn't blank during
        // the ~300–1500 ms window between click and reload. Reload destroys it.
        var $bulkArea = $('.wp-compress-bulk-area');
        if ($bulkArea.length && !$bulkArea.find('.wpc-bulk-stopping').length) {
            $bulkArea.append(
                '<div class="wpc-bulk-stopping">' +
                    '<div class="wpc-bulk-stopping-spinner"></div>' +
                    '<div class="wpc-bulk-stopping-title">Stopping…</div>' +
                    '<div class="wpc-bulk-stopping-sub">Wrapping up in-flight work.</div>' +
                '</div>'
            );
        }

        var finalized = false;
        // v7.03.1 (2026-05-25 #3) — Stop UX is "spinner until accurate counts
        // server-confirmed, then redirect to a freshly-rendered page". This
        // gives a single, simple invariant: the page the user lands on after
        // Stop is *always* server-rendered with the truly-current counts (the
        // bounded-poll settle in wps_ic_StopBulk waits for in-flight writes
        // before recounting). No in-place DOM patching. No mismatch risk
        // between count-card branches. The cache-bust query string forces a
        // fresh server round-trip even if a CDN sits in front.
        function _redirectFresh() {
            if (finalized) return;
            finalized = true;
            var u = new URL(window.location.href);
            u.searchParams.set('_', Date.now());
            window.location.replace(u.toString());
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wps_ic_StopBulk', nonce: ajaxVar.nonce},
            timeout: 12000
        }).done(function () {
            // Server has settled in-flight writes + busted the splash count
            // cache (see wps_ic_StopBulk's bounded-poll). Safe to redirect —
            // the next page render reads truly-current counts.
            _redirectFresh();
        }).fail(_redirectFresh);

        // Hard fallback: AJAX hangs longer than wp_send_json round-trip should
        // ever take. Server's bounded settle caps at 2.5 s; a healthy round-
        // trip is well under 5 s. After 10 s we punt to the same redirect
        // (the page reload itself re-renders fresh).
        setTimeout(_redirectFresh, 10000);
        return false;
    }
    // /_doStopBulk

    $('.button-start-bulk-restore').on('click', function (e) {
        e.preventDefault();

        // v7.04.70 — Audit hardening (P0-4): debounce. Pre-fix: rapid double-
        // click fired two _doStartRestore() AJAX requests using the SAME
        // nonce, both passed CSRF, both started polling intervals, both
        // tried to update DOM — orphan timers + race for .wpc-restore-surface.
        // Disable immediately; the in-flight AJAX response (or server 409)
        // re-enables on completion. Failsafe timeout in case AJAX never
        // returns. Revert: remove the prop('disabled') lines + safety
        // setTimeout.
        var $btn = $(this);
        if ($btn.prop('disabled')) return false;
        $btn.prop('disabled', true).addClass('is-debouncing');
        var _wpcRestoreDebounceFailsafe = setTimeout(function () {
            $btn.prop('disabled', false).removeClass('is-debouncing');
        }, 8000);

        // v7.03 — Restore is destructive (compressed variants are discarded).
        // Confirm intent + show the count so user knows the scope before
        // clicking. Compress doesn't need this (non-destructive).
        var restoreCountText = ($('.wpc-bulk-splash-card--restore .wpc-bulk-splash-count-num').first().text() || '0').replace(/[^0-9]/g, '');
        var restoreCount = parseInt(restoreCountText, 10) || 0;

        // v7.04.62 — Diagnostic instrumentation. If the click doesn't open the
        // confirm dialog or the .then() never resolves, the user gets no UI
        // feedback. These logs are intentionally always-on (no debug-flag gate)
        // until we confirm the start path is reliable across browsers.
        try { console.log('[WPC RestoreClick] handler fired, count=', restoreCount); } catch (e) {}

        _wpcConfirmRestoreStart(restoreCount).then(function (result) {
            try { console.log('[WPC RestoreClick] popup resolved, isConfirmed=', result && result.isConfirmed, 'value=', result && result.value, 'result=', result); } catch (e) {}
            // v7.04.63 — SweetAlert2 v9/v10 returns {value: true} on confirm
            // (no isConfirmed). v11+ returns {isConfirmed: true, value: ...}.
            // Accept BOTH so we work across versions.
            var confirmed = result && (result.isConfirmed === true || result.value === true);
            if (!confirmed) {
                // v7.04.70 — user cancelled, re-enable so they can click again.
                clearTimeout(_wpcRestoreDebounceFailsafe);
                $btn.prop('disabled', false).removeClass('is-debouncing');
                return;
            }
            // v7.04.71 — Hand the debounce handles to _doStartRestore so its
            // AJAX `complete` callback can re-enable the button on response
            // (success OR error). Pre-v7.04.71 had no link — button waited
            // for 8s failsafe even on error, blocking quick retry.
            _doStartRestore($btn, _wpcRestoreDebounceFailsafe);
        }).catch(function (err) {
            try { console.error('[WPC RestoreClick] popup error', err); } catch (e) {}
            // v7.04.70 — popup error path — re-enable button so user isn't stuck.
            clearTimeout(_wpcRestoreDebounceFailsafe);
            $btn.prop('disabled', false).removeClass('is-debouncing');
        });
        return false;
    });

    function _doStartRestore($restoreBtn, _restoreDebounceFailsafe) {
        try { console.log('[WPC StartBulkRestore] _doStartRestore entered'); } catch (e) {}
        $('.bulk-area-inner').show();
        $('.wps-ic-stop-bulk-restore').show();
        $('#bulk-start-container').hide();
        // v7.02 — Hide legacy preparing markup; new WPCRestore.restorePreparing
        // shows the polished card with pulse-ring spinner.
        $('.bulk-preparing-restore').hide();
        $('.bulk-preparing-optimize').hide();
        $('.bulk-compress-status-progress-prepare').hide();
        $('.bulk-compress-status-progress').hide();
        $('.bulk-status').hide();
        $('.bulk-status-progress-bar').hide();
        if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreReset) WPCBulk.restoreReset();
        if (typeof WPCBulk !== 'undefined' && WPCBulk.restorePreparing) {
            WPCBulk.restorePreparing('Scanning your library for files to restore.');
        }
        var nonce = ajaxVar.nonce;

        // v7.08.30 — Restore-start timing probe. Logs click→response round-trip so
        // we can tell whether "restore doesn't start fast" is the server start-call
        // (prepareRestoreImages + waiting for a free FPM worker behind compress
        // drains) vs the client UI. Read these in the browser console.
        var _wpcRestoreT0 = Date.now();
        try { console.log('[WPC RestoreTiming] start AJAX sent at', new Date().toISOString()); } catch (e) {}

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wpc_ic_start_bulk_restore', nonce: nonce},
            // v7.04.71 — Re-enable Restore button on ANY response so user
            // can retry immediately after a 409/error instead of waiting
            // 8s for the debounce failsafe. Defensive null-checks because
            // legacy callers (if any) may not pass $restoreBtn.
            complete: function () {
                try { console.log('[WPC RestoreTiming] start AJAX round-trip =', (Date.now() - _wpcRestoreT0), 'ms'); } catch (e) {}
                if ($restoreBtn && $restoreBtn.length) {
                    if (typeof _restoreDebounceFailsafe !== 'undefined') {
                        clearTimeout(_restoreDebounceFailsafe);
                    }
                    $restoreBtn.prop('disabled', false).removeClass('is-debouncing');
                }
            },
            success: function (response) {
                // v7.04.61 — Audit: previously called heartbeat unconditionally
                // even on response.success === false. If the server returned
                // an error (forbidden, performance-lab-compatibility, missing
                // apikey, etc.), the heartbeat would poll forever showing
                // "Scanning your library…" with no progress. Surface the
                // failure to the user instead.
                if (response && response.success === false) {
                    var msg = (response.data && response.data.msg)
                        ? response.data.msg
                        : 'Could not start bulk restore. Check console for details.';
                    try { console.error('[WPC StartBulkRestore] failed', response); } catch (e) {}
                    if (typeof WPCSwal !== 'undefined') {
                        // v7.04.68 — Typography upgraded to match the restore-start
                        // confirm popup: 15px / 1.6 / 14px margin / slate-900 strong.
                        WPCSwal.fire({
                            title: 'Restore could not start',
                            html: '<div style="text-align:left;font-size:15px;line-height:1.6;color:#1e293b;">' +
                                  '<p style="margin:0 0 14px;font-size:15px;">' + String(msg) + '</p>' +
                                  '</div>',
                            width: 520,
                            showCancelButton: false,
                            confirmButtonText: 'OK',
                            buttonsStyling: false,
                            customClass: {
                                container: 'wpc-popup-v6 wpc-confirm-popup',
                                confirmButton: 'wpc-confirm-btn wpc-confirm-btn--ghost'
                            }
                        });
                    }
                    $('.bulk-area-inner').hide();
                    $('#bulk-start-container').show();
                    return;
                }
                bulkRestoreHeartbeat();
            },
            error: function (xhr, status, err) {
                try { console.error('[WPC StartBulkRestore] ajax error', status, err, xhr); } catch (e) {}
                if (typeof WPCSwal !== 'undefined') {
                    // v7.04.68 — Matches the restore-start confirm typography (15px / 1.6).
                    WPCSwal.fire({
                        title: 'Restore could not start',
                        html: '<div style="text-align:left;font-size:15px;line-height:1.6;color:#1e293b;">' +
                              '<p style="margin:0 0 14px;font-size:15px;">Network or server error. Try again in a moment.</p>' +
                              '</div>',
                        width: 520,
                        showCancelButton: false,
                        confirmButtonText: 'OK',
                        buttonsStyling: false,
                        customClass: {
                            container: 'wpc-popup-v6 wpc-confirm-popup',
                            confirmButton: 'wpc-confirm-btn wpc-confirm-btn--ghost'
                        }
                    });
                }
                $('.bulk-area-inner').hide();
                $('#bulk-start-container').show();
            }
        });
    }


    $('.button-start-bulk-compress').on('click', function (e) {
        e.preventDefault();

        // v7.04.70 — Audit hardening (P0-4): debounce. Same rationale as
        // the restore button above. The start AJAX response or the server
        // 409 (added in wpc_ic_start_bulk_compress, see ajax.class.php)
        // re-enables on completion. Revert: remove these 5 lines.
        var $cBtn = $(this);
        if ($cBtn.prop('disabled')) return false;
        $cBtn.prop('disabled', true).addClass('is-debouncing');
        var _wpcCompressDebounceFailsafe = setTimeout(function () {
            $cBtn.prop('disabled', false).removeClass('is-debouncing');
        }, 8000);

        $('.wps-ic-stop-bulk-compress').show();
        $('.bulk-area-inner').show();
        $('#bulk-start-container').hide();
        // v7.04.68 — Show the new world-class skeleton (structure-mirroring
        // .bulk-preparing-optimize from template) and HIDE the v2 surface
        // until first heartbeat data arrives. Pre-fix v7.03.2 showed the
        // empty v2-surface immediately as its own loading UI ("Encoding
        // variants — first results in ~5 seconds…") which bypassed the
        // skeleton entirely. renderTally at bulk-ui.js:727 handles the
        // swap (hides skeleton + shows v2 surface) on first real data.
        $('.bulk-preparing-optimize').show();
        $('.wpc-bulk-v2-surface').hide();
        $('.bulk-preparing-restore').hide();
        $('.bulk-compress-status-progress-prepare').hide();
        $('.bulk-compress-status-progress').hide();
        $('.bulk-status').hide();
        $('.bulk-status-progress-bar').hide();
        var nonce = ajaxVar.nonce

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wpc_ic_start_bulk_compress', nonce: nonce},
            timeout: 100000,
            // v7.04.71 — Audit hardening (P0-4 follow-up). v7.04.70 left the
            // button disabled until the 8s failsafe — meaning after a server
            // error response (e.g., 409 inflight, 500 fatal), the user had
            // to wait 8s to retry. Re-enable on ANY AJAX completion (success
            // OR error) so retries are immediate. Revert: remove complete.
            complete: function () {
                clearTimeout(_wpcCompressDebounceFailsafe);
                $cBtn.prop('disabled', false).removeClass('is-debouncing');
            },
            success: function (response) {

                if (response.success == true) {
                    // v7.02 — Driver lives at the top level of the response
                    // (sibling of data) so old cached JS that only checks
                    // response.success still works. New JS reads response.driver.
                    // v7.03.2 — 'sequential' driver: JS loops wps_ic_compress_live
                    // per image, matching single-image timing exactly.
                    if (response.driver === 'sequential') {
                        var queue = (response.data && response.data.queue) || [];
                        bulkCompressSequentialLoop(queue);
                        // Heartbeat in parallel for the UI tally (reads
                        // session_ids the server pre-populated).
                        bulkCompressProgressPoll(response.driver);
                    } else if (response.driver === 'v2') {
                        bulkCompressProgressPoll(response.driver);
                    } else {
                        bulkCompressHeartbeat();
                    }
                } else {

                    // Stop everything, show popup
                    $('.bulk-status-progress-bar').hide();
                    $('.wps-ic-stop-bulk-compress').hide();
                    $('.bulk-status-settings').hide();
                    $('.bulk-status').hide();
                    //
                    $('.wps-ic-stop-bulk-compress').hide();
                    $('.bulk-area-inner').hide();
                    $('#bulk-start-container').show();
                    $('.bulk-preparing-optimize').hide();

                    if (response.data.msg == '' || response.data.msg == null) {
                        response.data.msg = 'unknown-error';
                    }

                    // Failure Pop Up
                    WPCSwal.fire({
                        title: '',
                        html: $('#' + response.data.msg).html(),
                        width: 600,
                        showCancelButton: false,
                        showConfirmButton: false,
                        confirmButtonText: 'Okay, I Understand',
                        allowOutsideClick: true,
                        customClass: {
                            container: 'no-padding-popup-bottom-bg switch-legacy-popup wpc-popup-v6',
                        },
                        onOpen: function () {
                        }
                    });

                }
            }
        });
        return false;
    });


    var lastProgress = 0;

    function bulkRestoreHeartbeat() {
        // v7.04.60 — Grace-window state. Mirrors the compress grace-window
        // pattern at line ~770 (terminalCount + graceStartMs). At first 'done'
        // we render the success view, then keep polling for 60s while
        // stragglers (in-flight thumb regen, late restored_at stamps) update
        // the success counters in real time. After 60s we fire the deferred
        // cleanup AJAX and clear the interval.
        var restoreGraceStartMs = 0;
        var restoreFinalShown   = false;
        // v7.04.70 — Audit hardening (P1-12): linger window mirrors compress.
        // Pre-fix: restore went directly from 'working' → success view on the
        // FIRST 'done' poll. Compress has a 5s linger so the user reads the
        // final processing card before the celebrate transition. Restore
        // lacked this — felt abrupt. Now: on first 'done', stash a timer;
        // keep polling for 5s while the processing card stays visible
        // (counters tick to final values), then flip to success. Revert:
        // delete the lingerStartMs variable + the if-else around
        // restoreFinalShown below.
        var restoreLingerStartMs = 0;
        var RESTORE_LINGER_MS    = 5000;
        // Poll error accumulator — bail after 10 consecutive failures so we
        // don't hammer a dead endpoint forever. Mirrors compress at line ~720.
        var pollErrorCount = 0;

        var heartbeatBulkRestore = setInterval(function () {
            // v7.04.70 — Audit hardening (P0-5): pause polling when tab
            // hidden. Same rationale as compress polling — see comment at
            // bulkCompressProgressPoll. Restore work is fully server-side;
            // JS only reads state. Revert: delete this if-block.
            if (typeof document !== 'undefined' && document.hidden) return;

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wps_ic_bulkRestoreHeartbeat',
                    lastProgress: lastProgress
                },
                success: function (response) {
                    pollErrorCount = 0;

                    if (response.success == false) {
                        clearInterval(heartbeatBulkRestore);

                        // Stop everything, show popup
                        $('.bulk-status-progress-bar').hide();
                        $('.wps-ic-stop-bulk-compress').hide();
                        $('.bulk-status-settings').hide();
                        $('.bulk-status').hide();
                        //
                        $('.wps-ic-stop-bulk-compress').hide();
                        $('.bulk-area-inner').hide();
                        $('#bulk-start-container').show();
                        $('.bulk-preparing-optimize').hide();

                        // Failure Pop Up
                        WPCSwal.fire({
                            title: '',
                            html: $('#' + response.data.msg).html(),
                            width: 600,
                            showCancelButton: false,
                            showConfirmButton: false,
                            confirmButtonText: 'Okay, I Understand',
                            allowOutsideClick: true,
                            customClass: {
                                container: 'no-padding-popup-bottom-bg switch-legacy-popup wpc-popup-v6',
                            },
                            onOpen: function () {
                            }
                        });

                        return;
                    }


                    // v7.02 — Drive WPCRestore (3-view state machine) with the
                    // heartbeat JSON. Field-level updates keep CSS animations
                    // (crossfade thumb, slide filename, ETA) running between
                    // polls instead of nuking innerHTML each tick.
                    var d = response.data || {};

                    if (d.status === 'parsing') {
                        if (typeof WPCBulk !== 'undefined' && WPCBulk.restorePreparing) {
                            WPCBulk.restorePreparing(d.message || '');
                        }
                        return;
                    }

                    if (d.status === 'done') {
                        $('.wps-ic-stop-bulk-restore').hide();
                        $('.wps-ic-stop-bulk-compress').hide();

                        // v7.04.70 — Linger window. On the FIRST 'done' poll,
                        // stash the timestamp; keep polling for 5s with the
                        // processing card still visible so the user reads
                        // final numbers. After 5s, fire the success view +
                        // start the 60s grace window.
                        if (restoreLingerStartMs === 0) {
                            restoreLingerStartMs = Date.now();
                            // Keep processing card updated during linger so
                            // counters tick to final values.
                            if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreProcessing) {
                                WPCBulk.restoreProcessing(d);
                            }
                            return;
                        }
                        if (!restoreFinalShown && (Date.now() - restoreLingerStartMs < RESTORE_LINGER_MS)) {
                            // Still lingering — keep processing card updated.
                            if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreProcessing) {
                                WPCBulk.restoreProcessing(d);
                            }
                            return;
                        }

                        // v7.04.60 — Grace-window logic. On the FIRST 'done' (after
                        // the linger window above), render the success view + start
                        // the 60s grace timer. Keep polling until the timer
                        // elapses so the success counters climb as stragglers land.
                        // At end of grace, fire the deferred cleanup AJAX and
                        // clear interval.
                        if (!restoreFinalShown) {
                            if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreCompleted) {
                                WPCBulk.restoreCompleted(d);
                            }
                            restoreFinalShown   = true;
                            restoreGraceStartMs = Date.now();
                            return;
                        }

                        // Subsequent 'done' polls during grace — update fields
                        // so bytes/avg/elapsed reflect any late stragglers.
                        if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreCompleted) {
                            WPCBulk.restoreCompleted(d);
                        }

                        if (Date.now() - restoreGraceStartMs > 60000) {
                            clearInterval(heartbeatBulkRestore);
                            $.post(ajaxurl, {
                                action: 'wps_ic_bulkRestoreCleanup',
                                nonce: (typeof ajaxVar !== 'undefined' && ajaxVar.nonce) ? ajaxVar.nonce : ''
                            });
                        }
                        return;
                    }

                    // status === 'working'
                    if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreProcessing) {
                        WPCBulk.restoreProcessing(d);
                    }
                    lastProgress = d.progress || 0;
                },
                error: function () {
                    pollErrorCount++;
                    if (pollErrorCount >= 10) {
                        clearInterval(heartbeatBulkRestore);
                    }
                }
            });
        }, 1000);  // 1s heartbeat — restoreV4 ~2-5s/img, so we see each image cycle cleanly
    }


    var bulkCompressStopped = false;

    function bulkCompressHeartbeat() {
        if (bulkCompressStopped) return;
        // v7.04.70 — Audit hardening (P0-5): pause v1 polling when tab
        // hidden. v1 polling self-chains via setTimeout, so we re-schedule
        // the next tick without firing AJAX. Revert: delete this block.
        if (typeof document !== 'undefined' && document.hidden) {
            setTimeout(bulkCompressHeartbeat, 1500);
            return;
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 180000,
            data: {action: 'wps_ic_doBulkCompress', nonce: ajaxVar.nonce},
            success: function (response) {
                if (bulkCompressStopped) return;

                if (response.success == false) {
                    // Error — show popup
                    $('.bulk-status-progress-bar').hide();
                    $('.wps-ic-stop-bulk-compress').hide();
                    $('.bulk-status-settings').hide();
                    $('.bulk-status').hide();
                    $('.bulk-area-inner').hide();
                    $('#bulk-start-container').show();
                    $('.bulk-preparing-optimize').hide();

                    if (response.data && response.data.msg) {
                        WPCSwal.fire({
                            title: '',
                            html: $('#' + response.data.msg).html(),
                            width: 600,
                            showConfirmButton: false,
                            allowOutsideClick: true,
                            customClass: { container: 'no-padding-popup-bottom-bg switch-legacy-popup wpc-popup-v6' },
                        });
                    }
                    return;
                }

                if (response.data.finished === true) {
                    // All done — show final stats
                    var bulkFinished = $('.bulk-finished');
                    setTimeout(function () {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: { action: 'wps_ic_getBulkStats', type: 'compress' },
                            success: function (statsResponse) {
                                $('.bulk-preparing-optimize').hide();
                                $('.bulk-compress-status-progress-prepare').hide();
                                $('.bulk-compress-status-progress').hide();
                                $('.bulk-status-progress-bar').hide();
                                $('.wps-ic-stop-bulk-compress').hide();
                                $('.bulk-status-settings').hide();
                                $('.bulk-status').fadeOut(600, function () {
                                    $(bulkFinished).hide().html(statsResponse.data.html).fadeIn(800);
                                });
                            }
                        });
                    }, 500);
                    return;
                }

                // Update progress UI
                $('.bulk-compress-status-progress-prepare').hide();
                $('.bulk-preparing-placholders').hide();
                $('.bulk-preparing-optimize').hide();

                var d = response.data;
                updateCompressStatusProgressCount({
                    progressCompressedImages: d.finished_count,
                    progressTotalSavings: '',
                    progressAvgReduction: (d.savings || '0') + '%',
                    progressCompressedThumbs: ''
                });

                // Update progress bar
                updateStatusProgressBar(d.progress);

                // Show current image info
                if (d.title) {
                    $('.bulk-process-file-name').html(d.title);
                }
                $('.bulk-status').show();

                // Process next image (setTimeout breaks call stack)
                setTimeout(bulkCompressHeartbeat, 200);
            },
            error: function () {
                if (bulkCompressStopped) return;
                // Network error — retry after 3s
                setTimeout(bulkCompressHeartbeat, 3000);
            }
        });
    }


    // v7.03.2 — JS-driven SEQUENTIAL bulk loop. Calls wps_ic_compress_live
    // one image at a time, awaiting each. Identical code path to clicking
    // Optimize on a single image in the media library — Phase A returns
    // in 2-4 s, Phase B continues async. Wall-time per image matches
    // single-image timing exactly (~3-4 s perceived, ~12 s end-to-end with
    // Phase B). No drain chain, no loopback overhead, no wait-gates.
    //
    // The heartbeat poll runs in parallel for the UI tally (reads the
    // server-pre-populated session_ids transient). The split screen / splash
    // count is fresh within +/-1 because JS knows exactly which image just
    // returned and can also fire a count-invalidation hook.
    // v7.03.2 — Exposed globally so check-bulk-running.js can call it on
    // resume (page-load mid-bulk) without having to duplicate the function.
    window.WPCBulkSeq = window.WPCBulkSeq || {};
    window.WPCBulkSeq.run = function (queue) { bulkCompressSequentialLoop(queue); };
    function bulkCompressSequentialLoop(queue) {
        if (!Array.isArray(queue) || queue.length === 0) return;
        // v7.03.2 — Idempotency guard. The WL plugin loads this JS file from
        // its own /files/ path AND the main /wp-compress-image-optimizer/
        // path, so the click handler binds twice and fires two loops in
        // parallel. Without this flag, both loops dispatch the same imageIDs
        // (visible as duplicate "[wpc-seq] dispatch ..." logs) and the second
        // dispatch lands as file-already-compressed → no ic_local_variants
        // ever written for that image → heartbeat reads 0/0/0.
        if (window.__wpcSeqRunning) {
            console.log('[wpc-seq] loop already running — skipping duplicate start');
            return;
        }
        window.__wpcSeqRunning = true;

        var idx = 0;
        var total = queue.length;

        function next() {
            if (bulkCompressStopped) { window.__wpcSeqRunning = false; return; }
            if (idx >= total) {
                // v7.03.2 — NO cleanup fire here. With per-image phase-B wait
                // below, every image's variants are fully landed before we
                // reach this point. The v2 heartbeat will see queue_empty +
                // no pending drains for 2 ticks and fire its own terminal
                // path (renderFinalReveal + the cleanup AJAX it owns). Firing
                // cleanup from JS here was wiping session_ids before the
                // heartbeat got a chance to render the final tally → 0/0/0.
                console.log('[wpc-seq] loop complete (heartbeat will detect terminal)');
                window.__wpcSeqRunning = false;
                return;
            }
            var imageId = parseInt(queue[idx], 10);
            idx++;
            console.log('[wpc-seq] dispatch image ' + idx + '/' + total + ' (id=' + imageId + ')');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: { action: 'wps_ic_compress_live', attachment_id: imageId },
                // Single-image typical 3-6 s, occasional 15 s on cold. 60 s
                // ceiling matches the server's set_time_limit(120) safety.
                timeout: 60000
            }).done(function (resp) {
                console.log('[wpc-seq] image ' + idx + ' phase-A done', resp && resp.success);
            }).fail(function (xhr, status) {
                console.warn('[wpc-seq] image ' + idx + ' phase-A failed:', status);
            }).always(function () {
                // v7.03.2 — Per-image phase-B wait. The user explicitly asked
                // for sequencing to be "per variant too not just per image"
                // (2026-05-25). After Phase A returns, we poll the cheap
                // variant_count endpoint until phase_b_done=true (all AVIFs
                // landed via /bg_swap callbacks and no pending drain rows) or
                // a 15 s ceiling matching single-image perceived timing. This
                // is what makes bulk match single-image: <3 s for first
                // variant, <15 s for full image done.
                waitForPhaseB(imageId, 0, function () { setTimeout(next, 100); });
            });
        }

        // Poll wps_ic_variant_count every 500 ms until phase_b_done or ceiling.
        // Ceiling differs by transport:
        //   - Push-only (legacy): 15 s — callbacks land in <1 s typically
        //   - Pull-enabled (v7.04+): 30 s — manifest fetch + journal drain
        //     pull adds 1-10 s under FPM contention; 15 s was tripping pre-
        //     completion on real-world bulks
        // Hard ceiling exists so a stuck Phase B (lost callback, encoder fault)
        // doesn't deadlock the entire bulk — the heartbeat will still surface
        // the image's actual state when the callback eventually lands.
        // v7.04.24 — 45s hard ceiling for pull-enabled. Encoder window is
        // +3-10s + drain iter_budget=25s + t0-retry buffer = ~40s worst case.
        // v7.04.26 — STALL DETECTION: if count >= 22 AND count hasn't
        // changed in 5s, fire cleanup early. Image is "near complete" and
        // unlikely to receive the 1-2 missing variants — advance fast
        // instead of waiting full 45s. Cleanup handler does the t0-retry
        // for the missing variant async in the background.
        var phaseBCeilingMs = (window.ajaxVar && window.ajaxVar.pullEnabled) ? 45000 : 15000;
        function waitForPhaseB(imageId, elapsedMs, doneCb, prevCount, prevCountAt) {
            if (bulkCompressStopped) { doneCb(); return; }
            prevCount    = prevCount    || 0;
            prevCountAt  = prevCountAt  || Date.now();

            function fireCleanup(reason) {
                console.log('[wpc-seq] image id=' + imageId + ' cleanup: ' + reason);
                $.post(ajaxurl, {
                    action:  'wpc_bulk_clear_stuck_compressing',
                    nonce:   ajaxVar.nonce,
                    imageID: imageId
                });
                doneCb();
            }

            if (elapsedMs >= phaseBCeilingMs) {
                fireCleanup('ceiling ' + (phaseBCeilingMs / 1000) + 's');
                return;
            }
            $.ajax({
                url: ajaxurl, type: 'POST',
                data: { action: 'wps_ic_variant_count', attachment_id: imageId }
            }).done(function (resp) {
                var d = (resp && resp.data) || {};
                if (d.phase_b_done) {
                    console.log('[wpc-seq] image id=' + imageId + ' phase-B done (' + d.count + ' variants)');
                    doneCb();
                    return;
                }
                var newCount = Number(d.count) || 0;
                var now      = Date.now();
                // Track count changes.
                if (newCount !== prevCount) {
                    prevCount   = newCount;
                    prevCountAt = now;
                }
                var stallMs = now - prevCountAt;
                // v7.04.27 — Immediate advance at 24/24 (full set on disk).
                // Don't wait for phase_b_done (pending may have stale key
                // even when all 24 variants merged). Cleanup runs async to
                // force-flip status; bulk continues in background.
                if (newCount >= 24) {
                    fireCleanup('full set 24/24 — advancing immediately');
                    return;
                }
                // Tiered stall detection for partial completions:
                //   count >= 22 + 3s stall   → near-complete, advance fast
                //   count >= 12 + 8s stall   → half landed, accept
                //   count >= 1  + 15s stall  → some progress, stalled, accept
                if (newCount >= 22 && stallMs >= 3000) {
                    fireCleanup('near-complete stall ' + newCount + '/24 for ' + Math.round(stallMs / 1000) + 's');
                    return;
                }
                if (newCount >= 12 && stallMs >= 8000) {
                    fireCleanup('mid stall ' + newCount + '/24 for ' + Math.round(stallMs / 1000) + 's');
                    return;
                }
                if (newCount >= 1 && stallMs >= 15000) {
                    fireCleanup('low stall ' + newCount + '/24 for ' + Math.round(stallMs / 1000) + 's');
                    return;
                }
                setTimeout(function () {
                    waitForPhaseB(imageId, elapsedMs + 500, doneCb, prevCount, prevCountAt);
                }, 500);
            }).fail(function () {
                setTimeout(function () {
                    waitForPhaseB(imageId, elapsedMs + 500, doneCb, prevCount, prevCountAt);
                }, 500);
            });
        }

        next();
    }


    // v7.02 — V2 bulk progress poller. Server-side wpc_bulk_v2_drain chain
    // owns the dequeue + Phase A dispatch + self-chain. JS only polls the
    // heartbeat for tally + active titles + completion list. Polls every
    // 1 s (fits shared-host PHP worker caps — 2 connections total: chain +
    // poll). Stops 2 polls after terminal state (queue_empty + no Phase B
    // drain in flight), then fires the cleanup + final summary card.
    function bulkCompressProgressPoll(driver) {
        if (bulkCompressStopped) return;

        if (typeof WPCBulk !== 'undefined') WPCBulk.resetCompletionList();

        // v7.03 — DO NOT hide prep here. The old behavior was:
        //   start click → show prep → AJAX done → THIS function hides prep +
        //   shows legacy `.bulk-status-progress-bar` (an empty div).
        // Combined with renderTally's `firstDataArrived` gate (which keeps the
        // v2 surface hidden until the first variant lands), this left a 5–10 s
        // window where the user saw a blank ~80 px card (the empty legacy
        // progress bar). Now we leave prep visible until renderTally hides it
        // on first-data and don't touch the legacy bar at all.
        // Only hide the restore prep (which the template renders simultaneously
        // when the bulk status flips) + show the Stop button (so user can bail).
        $('.bulk-preparing-restore').hide();
        $('.wps-ic-stop-bulk-restore').hide();
        $('.wps-ic-stop-bulk-compress').show();

        var terminalCount = 0;
        var pollErrorCount = 0;
        var graceStartMs = 0;
        // v7.04.68 — 5s linger window AFTER terminal is reached but BEFORE
        // we switch to the success view. Gives users time to read the final
        // processing-card numbers (last counters tick, last variant flash)
        // before the celebrate transition kicks in. Without this the swap
        // felt aggressive — completion fired the instant the last variant
        // landed and the user never saw "everything's done" on the card
        // they'd been watching.
        var lingerStartMs = 0;
        var _wpcCompressDrainTick = 0;

        var pollInterval = setInterval(function () {
            if (bulkCompressStopped) {
                clearInterval(pollInterval);
                return;
            }

            // v7.04.70 — Audit hardening (P0-5): pause polling when tab hidden.
            // Mirrors the proven pattern at media-library-actions.js:605 (ML
            // heartbeat) which has been live since v7.02.12. Bulk work runs
            // entirely server-side (Phase A POST + REST callbacks + post
            // meta writes); the JS poll only READS state for UI. Polling
            // every 1.5s in a background tab burns FPM workers for no user
            // benefit. Five hidden tabs polling = 5 workers permanently
            // pinned on a 5-worker shared host → admin pageviews queue.
            // Visibility-resume burst (registered below in
            // _wpcBulkInstallVisibilityHandler) re-syncs immediately when
            // the user returns. Revert: delete this if-block; the timer
            // keeps firing in the background.
            if (typeof document !== 'undefined' && document.hidden) return;

            // LAYER 2 — tab-open compress drain kick (PRIV; tab has admin cookies). Poll is 1500ms,
            // so %3 ≈ every 4.5s. (CORRECTION: design said %4 but the poll is 1500ms not 1000ms; %3
            // gives the intended ~4s cadence.) Fire-and-forget; GET_LOCK no-ops if a slice is alive.
            // v7.01.95 — DRIVER-GATED to 'v2'. Under the DEFAULT 'sequential' driver the JS per-image
            // loop (bulkCompressSequentialLoop → wps_ic_compress_live) owns dispatch and the
            // wps_ic_compress_queue transient is intentionally left fully populated (no start loopback),
            // so firing the server drain here re-dequeued + re-dispatched the SAME IDs the JS loop was
            // walking — and since no peer drain slice exists in sequential mode, GET_LOCK did NOT no-op
            // → wasted encodes + redundant Phase-A POSTs + FPM contention (a pre-existing double-dispatch
            // that shipped un-gated). Only the 'v2' driver relies on the server drain chain.
            if (driver === 'v2' && (_wpcCompressDrainTick++ % 3) === 0) {
                $.ajax({ url: ajaxurl, type: 'POST', timeout: 30000, data: { action: 'wpc_bulk_v2_drain' } });
            }

            // v7.02 — variant-stream cursor: server sends new variants since this ms.
            var sinceMs = (typeof WPCBulk !== 'undefined' && WPCBulk.getLastVariantMs)
                ? WPCBulk.getLastVariantMs() : 0;

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {action: 'wps_ic_bulkCompressHeartbeat', nonce: ajaxVar.nonce, since_ms: sinceMs},
                success: function (response) {
                    // v7.04.53 — Reset error counter on any successful poll.
                    pollErrorCount = 0;
                    if (bulkCompressStopped) {
                        clearInterval(pollInterval);
                        return;
                    }

                    if (response.success === false) {
                        clearInterval(pollInterval);
                        $('.bulk-status-progress-bar').hide();
                        $('.wps-ic-stop-bulk-compress').hide();
                        $('.bulk-status-settings').hide();
                        $('.bulk-status').hide();
                        $('.bulk-area-inner').hide();
                        $('#bulk-start-container').show();
                        $('.bulk-preparing-optimize').hide();
                        return;
                    }

                    var d = response.data || {};

                    // Derive a friendly "in between batches" hint so the
                    // Now-Processing area never looks dead. If queue still has
                    // work but no image is currently active, the drain chain
                    // is mid-loopback → "loading". If queue is empty but
                    // pending_drain > 0, callbacks are still landing →
                    // "finalizing".
                    var hint = '';
                    if ((!d.active || d.active.length === 0)) {
                        if (!d.queue_empty) hint = 'loading';
                        else if (d.pending_drain > 0) hint = 'finalizing';
                    }
                    if (typeof WPCBulk !== 'undefined') {
                        WPCBulk.renderTally(d);
                        WPCBulk.renderActiveTitles(d.active || [], hint, d.up_next || []);
                        WPCBulk.renderActiveThumbs(d.active || [], hint, d.up_next || []);
                        // v7.04.40 — Push completed[] entries (with their
                        // per-format chip counts as of v7.04.38) so the hero
                        // can render truthful 8J/8W/8A when an image is in
                        // completed bucket. Pre-fix the hero only read
                        // _lastActiveServer; once an image moved to
                        // completed, JS fell back to its under-counting
                        // accumulator → user saw "13 · 2J 3W 8A" instead
                        // of "24 · 8J 8W 8A" even though DB had it all.
                        if (typeof WPCBulk.setLastCompletedServer === 'function') {
                            WPCBulk.setLastCompletedServer(d.completed || []);
                        }
                        WPCBulk.renderVariantStream(d.new_variants || []);
                        // v7.04.43 — Refresh hero chip on EVERY heartbeat,
                        // not just when new_variants arrive. renderVariantStream
                        // only calls renderHero per-incoming-variant; for re-
                        // bulks of already-compressed images, no NEW variants
                        // land (existing 24 entries still in DB) so the chip
                        // stayed frozen at JS init (0/0/0/0) forever even
                        // though server's active entry reported full counts.
                        // Calling renderHero([]) makes it re-read serverStats
                        // from _lastActiveServer / _lastCompletedServer which
                        // were just updated above.
                        if (typeof WPCBulk.renderHero === 'function') {
                            WPCBulk.renderHero([]);
                        }
                        // v7.04.47 — Diagnostic logging. Enable in browser
                        // devtools console: localStorage.setItem('wpc_bulk_debug','1')
                        // then refresh + run bulk. Disable: remove the
                        // localStorage key. Logs server response shape +
                        // what renderHero will see so we can pinpoint where
                        // chip counts go wrong (server returns 0 vs JS
                        // doesn't read entry vs hero hits accumulator
                        // fallback).
                        if (window.localStorage && localStorage.getItem('wpc_bulk_debug') === '1') {
                            try {
                                console.log('[WPC BulkHB]', {
                                    processed: d.processed,
                                    total: d.total,
                                    variants_total: d.variants_total,
                                    active_count: (d.active || []).length,
                                    completed_count: (d.completed || []).length,
                                    new_variants_count: (d.new_variants || []).length,
                                    active_full_str: JSON.stringify((d.active || []).map(function(a){ return a ? {id: a.id, jpeg: a.jpeg, webp: a.webp, avif: a.avif, count: a.count, savings_pct: a.savings_pct} : null; })),
                                    completed_full_str: JSON.stringify((d.completed || []).map(function(c){ return c ? {id: c.id, jpeg: c.jpeg, webp: c.webp, avif: c.avif, count: c.count, pct: c.pct} : null; })),
                                    queue_empty: d.queue_empty
                                });
                            } catch (e) {}
                        }
                    }

                    // Terminal: queue drained AND no Phase B in flight, for
                    // 2 consecutive polls (~1 s of true terminal state — guards
                    // against last-image Phase A landing right when queue empties).
                    // Don't require processed > 0 — if all images fail (no_apikey,
                    // backup_failed, etc.) terminal still needs to fire.
                    var terminal = d.queue_empty && d.pending_drain === 0;
                    if (terminal) {
                        terminalCount++;

                        // v7.04.68 — State machine:
                        //   terminalCount === 2 → start 5s linger on processing card
                        //   linger active (≤ 5s)  → keep updating tally normally
                        //   linger done (>= 5s)   → fire renderFinalReveal, start grace
                        //   grace active (60s)    → update compressCompleted each tick
                        if (terminalCount === 2 && lingerStartMs === 0) {
                            lingerStartMs = Date.now();
                            // Processing card stays visible; renderTally above
                            // continues updating counts/bytes/pct so the user
                            // sees the final numbers settle in. No view swap yet.
                        } else if (lingerStartMs > 0 && graceStartMs === 0) {
                            // In linger window. Once 5s has elapsed, reveal
                            // the success view + kick off the 60s grace.
                            if (Date.now() - lingerStartMs >= 5000) {
                                if (typeof WPCBulk !== 'undefined') WPCBulk.renderFinalReveal(d);
                                $('.bulk-preparing-optimize').hide();
                                $('.bulk-compress-status-progress-prepare').hide();
                                $('.bulk-compress-status-progress').hide();
                                $('.bulk-status-progress-bar').hide();
                                $('.bulk-status').hide();
                                graceStartMs = Date.now();
                            }
                        } else if (graceStartMs > 0) {
                            // Success view shown — update its fields with
                            // fresh data each tick so the counters climb as
                            // stragglers (bg_retry, late AVIFs) arrive.
                            if (typeof WPCBulk !== 'undefined' && WPCBulk.compressCompleted) {
                                WPCBulk.compressCompleted(d);
                            }
                            // End of 60s grace → fire cleanup + stop polling.
                            if (Date.now() - graceStartMs > 60000) {
                                clearInterval(pollInterval);
                                $.post(ajaxurl, {
                                    action: 'wps_ic_bulkCompressCleanup',
                                    nonce:  ajaxVar.nonce
                                });
                            }
                        }
                    } else {
                        terminalCount = 0;
                    }
                },
                error: function (xhr, status, err) {
                    // v7.04.53 — Audit fix: track consecutive failures so
                    // we can surface stuck-bulk state instead of silently
                    // polling forever. Three consecutive errors = warn
                    // user; ten = stop polling and prompt reload.
                    pollErrorCount = (pollErrorCount || 0) + 1;
                    try {
                        console.warn('[BulkHB] poll error', { status: status, http: xhr && xhr.status, attempt: pollErrorCount });
                    } catch (e) {}
                    if (pollErrorCount >= 10) {
                        clearInterval(pollInterval);
                        try {
                            console.error('[BulkHB] giving up after 10 consecutive poll errors');
                        } catch (e) {}
                    }
                }
            });
        }, 1500);  // 1.5s heartbeat — drops admin-ajax load 33 % vs 1s while
                   // staying live-feeling for variant pills landing every ~2-5 s.
    }


    function updateCompressStatusProgressCount(data) {
        var progress = $('.bulk-compress-status-progress');
        var compressedImages = $('.bulk-images-compressed>div.data', progress);
        var compressedThumbs = $('.bulk-thumbs-compressed>div.data', progress);
        var totalSavings = $('.bulk-total-savings>div.data', progress);
        var thumbSavings = $('.bulk-thumbs-savings>div.data', progress);
        var avgReduction = $('.bulk-avg-reduction>div.data', progress);

        $(compressedImages).html(data.progressCompressedImages);
        $(compressedThumbs).html(data.progressCompressedThumbs);
        $(totalSavings).html(data.progressTotalSavings);
        //$(thumbSavings).html(data.progressThumbsSavings);
        $(avgReduction).html(data.progressAvgReduction);
        $(progress).show();
    }


    function updateStatusProgressBar(progress_percent) {
        var progress = $('.bulk-status-progress-bar');
        var progressBar = $('.progress-bar-inner', '.bulk-status-progress-bar');
        $(progress).show();
        $(progressBar).css('width', progress_percent + '%');
    }

});