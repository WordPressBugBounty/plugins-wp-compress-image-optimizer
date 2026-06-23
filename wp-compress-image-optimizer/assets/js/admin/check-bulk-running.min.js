jQuery(document).ready(function ($) {

    function fetchRestoreData() {
        // v7.02 — Resume case (user reloaded page mid-restore). WPCRestore
        // takes over with the polished view-switcher; no need to inject
        // legacy HTML or peek-and-render before the heartbeat loop starts.
        $('.bulk-area-inner').show();
        $('.wps-ic-stop-bulk-restore').show();
        $('#bulk-start-container').hide();
        $('.bulk-preparing-restore').hide();
        $('.bulk-preparing-optimize').hide();
        $('.bulk-compress-status-progress-prepare').hide();
        $('.bulk-compress-status-progress').hide();
        $('.bulk-status').hide();
        $('.bulk-status-progress-bar').hide();
        if (typeof WPCBulk !== 'undefined' && WPCBulk.restorePreparing) {
            WPCBulk.restorePreparing('Resuming restore session…');
        }
        bulkRestoreHeartbeat();
    }

    function fetchCompressData(driver, response) {
        $('.wps-ic-stop-bulk-compress').show();
        $('.bulk-area-inner').show();
        $('#bulk-start-container').hide();
        // v7.03 — On page-load resume mid-bulk, KEEP the prep visible until the
        // first heartbeat data arrives (renderTally's firstDataArrived gate
        // hides it then). And DON'T show the legacy `.bulk-status` empty div
        // (was rendering as an empty white card during the waiting window).
        // The v2 surface is the sole running-state visual.

        // v7.03.2 — Driver routing:
        //   'sequential' — JS-driven per-image loop. On resume we have the
        //     remaining queue in response.queue (from wps_ic_isBulkRunning).
        //     Restart the loop AND start heartbeat polling for the tally.
        //   'v2' — old drain chain.
        //   '<anything else>' — legacy V1.
        if (driver === 'sequential') {
            bulkCompressV2Poll(driver);
            // Continue the loop with whatever queue is left server-side.
            var resumeQueue = (typeof response !== 'undefined' && response && response.queue)
                ? response.queue : (window.__wpcSeqQueue || []);
            if (window.WPCBulkSeq && typeof window.WPCBulkSeq.run === 'function' && resumeQueue.length > 0) {
                window.WPCBulkSeq.run(resumeQueue);
            }
        } else if (driver === 'v2') {
            bulkCompressV2Poll(driver);
        } else {
            bulkCompressHeartbeat();
        }
    }

    function resetToStartView() {
        // Defensive reset: PHP usually renders this correctly, but stale
        // browser state (back/forward, sw cache, etc.) can leave the prep
        // markup visible. Forcing the start container and hiding every
        // bulk-running view guarantees the user lands on the start screen
        // whenever the server says no bulk is running.
        $('.bulk-area-inner').hide();
        $('.bulk-preparing-optimize').hide();
        $('.bulk-preparing-restore').hide();
        $('.bulk-compress-status-progress-prepare').hide();
        $('.bulk-compress-status-progress').hide();
        $('.bulk-status').hide();
        $('.bulk-status-progress-bar').hide();
        $('.wpc-bulk-v2-surface').hide();
        $('.wpc-restore-surface').hide();
        $('.wps-ic-stop-bulk-compress').hide();
        $('.wps-ic-stop-bulk-restore').hide();
        $('#bulk-start-container').show();
    }

    function fetchingBulkData() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_isBulkRunning'
            },
            // success fires on 200 even when wp_send_json_error sets success:false
            // in the body — handle both with a single complete callback.
            complete: function (xhr) {
                var response = {};
                try { response = JSON.parse(xhr.responseText || '{}'); } catch (e) {}
                // The bare-string contract: response.data === 'compressing'/'restoring'/'not-running'
                var status = response && response.data;
                var driver = (response && response.driver) || 'v1';

                if (!status || status === 'not-running') {
                    console.log('WPC Bulk is Not Running — resetting to start view');
                    resetToStartView();
                    return;
                }

                console.log('Bulk is Running (driver=' + driver + ')');
                if (status === 'compressing') {
                    fetchCompressData(driver, response);
                } else {
                    fetchRestoreData();
                }
            }
        });
    }

    // v7.02 — V2 bulk progress poller (resume case). Mirrors the new poller
    // in media-library-bulk.js. Polls every 1 s for state until terminal
    // (queue_empty + no Phase B drain in flight) for 2 consecutive ticks.
    function bulkCompressV2Poll(driver) {
        if (typeof WPCBulk !== 'undefined') WPCBulk.resetCompletionList();

        $('.bulk-preparing-restore').hide();
        $('.bulk-preparing-placholders').hide();
        $('.wps-ic-stop-bulk-restore').hide();
        $('.wps-ic-stop-bulk-compress').show();
        // Defense: force-hide the legacy bars in case prior page state
        // (back/forward cache) left them visible.
        $('.bulk-status').hide();
        $('.bulk-status-progress-bar').hide();
        $('.bulk-compress-status-progress-prepare').hide();

        // v7.03.2 (2026-05-25 #4) — Skip the skeleton entirely. Show the v2
        // surface immediately + hide the prep. The surface's own empty-state
        // ("Encoding variants — first results in ~5 seconds…") is the
        // loading UI. Removes every blank-screen failure mode that came
        // from the skeleton-→-surface transition (opacity fades stacking,
        // gate timing, prep-hide / surface-show race).
        $('.bulk-preparing-optimize').hide();
        $('.wpc-bulk-v2-surface').show();

        var terminalCount = 0;
        var _wpcResumeDrainTick = 0;

        function _tick() {
            // v7.04.70 — Audit hardening (P0-5): pause polling when tab
            // hidden. Same rationale as the main bulk poll (see media-
            // library-bulk.js for full comment). Revert: delete this if.
            if (typeof document !== 'undefined' && document.hidden) return;

            // v7.01.95 LAYER 2 — guaranteed tab-open drain kick (compress-RESUME path; was the lone
            // drain-chain poller missing it — restore-resume + compress fresh-start already have it).
            // The tab HAS a live admin cookie → the PRIV wpc_bulk_v2_drain passes current_user_can;
            // cannot 403, cannot be edge-cookie-stripped. Fire-and-forget; the server slice's
            // GET_LOCK('wpc_bulk_v2_chain',0) no-ops if a peer slice is alive. ~1/4s (poll is 1000ms).
            // DRIVER-GATED to 'v2': under the DEFAULT 'sequential' driver the JS per-image loop
            // (WPCBulkSeq.run → wps_ic_compress_live) owns dispatch and the wps_ic_compress_queue
            // transient is intentionally left fully populated (no start loopback). Firing the server
            // drain there spins a chain that dequeues + re-dispatches the SAME IDs the JS loop is
            // walking — and since no peer drain slice exists in sequential mode, GET_LOCK does NOT
            // no-op → wasted encodes + redundant Phase-A POSTs + FPM contention. Only the 'v2' driver
            // relies on the server drain chain, so only it gets the kick.
            if (driver === 'v2' && (_wpcResumeDrainTick++ % 4) === 0) {
                $.ajax({ url: ajaxurl, type: 'POST', timeout: 30000, data: { action: 'wpc_bulk_v2_drain' } });
            }

            var sinceMs = (typeof WPCBulk !== 'undefined' && WPCBulk.getLastVariantMs)
                ? WPCBulk.getLastVariantMs() : 0;
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {action: 'wps_ic_bulkCompressHeartbeat', nonce: ajaxVar.nonce, since_ms: sinceMs},
                success: function (response) {
                    if (response.success === false) {
                        clearInterval(pollInterval);
                        return;
                    }
                    var d = response.data || {};

                    var hint = '';
                    if ((!d.active || d.active.length === 0)) {
                        if (!d.queue_empty) hint = 'loading';
                        else if (d.pending_drain > 0) hint = 'finalizing';
                    }
                    if (typeof WPCBulk !== 'undefined') {
                        WPCBulk.renderTally(d);
                        WPCBulk.renderActiveTitles(d.active || [], hint, d.up_next || []);
                        WPCBulk.renderActiveThumbs(d.active || [], hint, d.up_next || []);
                        WPCBulk.renderVariantStream(d.new_variants || []);
                    }

                    var terminal = d.queue_empty && d.pending_drain === 0;
                    if (terminal) {
                        terminalCount++;
                        if (terminalCount >= 2) {
                            clearInterval(pollInterval);
                            if (typeof WPCBulk !== 'undefined') WPCBulk.renderFinalReveal(d);
                            $.post(ajaxurl, {
                                action: 'wps_ic_bulkCompressCleanup',
                                nonce:  ajaxVar.nonce
                            });
                            $('.bulk-preparing-optimize').hide();
                            $('.bulk-status-progress-bar').hide();
                            $('.bulk-status').hide();
                        }
                    } else {
                        terminalCount = 0;
                    }
                }
            });
        }

        // v7.03 — Fire the FIRST tick immediately. setInterval-only meant
        // the user waited 1 full second for the first heartbeat after
        // page-load resume, with nothing to look at but the forced prep
        // skeleton. Tick once now, then schedule the recurring poll.
        _tick();
        var pollInterval = setInterval(_tick, 1000);
    }

    //bulkCompressHeartbeat();
    fetchingBulkData();

    var lastProgress = 0;
    function bulkRestoreHeartbeat() {
        if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreReset) WPCBulk.restoreReset();
        // Hide all legacy preparing/status markup so WPCRestore's polished view-switcher takes over.
        $('.bulk-preparing-optimize').hide();
        $('.bulk-preparing-restore').hide();
        $('.bulk-compress-status-progress-prepare').hide();
        $('.bulk-compress-status-progress').hide();
        $('.bulk-status').hide();
        $('.bulk-status-progress-bar').hide();

        var _wpcRestoreDrainTick = 0;
        var heartbeatBulkRestore = setInterval(function(){
            // LAYER 2 — guaranteed tab-open drain kick. The tab HAS a live admin cookie → the PRIV
            // action passes current_user_can; cannot 403, cannot be edge-cookie-stripped. GET_LOCK(...,0)
            // no-ops if a slice is alive. Fire-and-forget. ~1/4s (poll is 1s).
            if ((_wpcRestoreDrainTick++ % 4) === 0) {
                $.ajax({ url: ajaxurl, type: 'POST', timeout: 30000, data: { action: 'wpc_bulk_v2_restore_drain' } });
            }
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {action: 'wps_ic_bulkRestoreHeartbeat', lastProgress: lastProgress},
                success: function (response) {
                    var d = response.data || {};

                    if (d.status === 'parsing') {
                        if (typeof WPCBulk !== 'undefined' && WPCBulk.restorePreparing) WPCBulk.restorePreparing(d.message || '');
                        return;
                    }

                    if (d.status === 'done') {
                        $('.wps-ic-stop-bulk-restore').hide();
                        $('.wps-ic-stop-bulk-compress').hide();
                        if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreCompleted) WPCBulk.restoreCompleted(d);
                        clearInterval(heartbeatBulkRestore);
                        return;
                    }

                    if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreProcessing) WPCBulk.restoreProcessing(d);
                    lastProgress = d.progress || 0;
                }
            });
        }, 1000);
    }



    function bulkCompressHeartbeat() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 180000,
            data: {action: 'wps_ic_doBulkCompress', nonce: ajaxVar.nonce},
            success: function (response) {
                if (response.success == false) return;

                if (response.data.finished === true) {
                    var bulkFinished = $('.bulk-finished');
                    $.ajax({
                        url: ajaxurl, type: 'POST',
                        data: {action: 'wps_ic_getBulkStats', type: 'compress'},
                        success: function (r) {
                            $('.bulk-preparing-optimize').hide();
                            $('.bulk-status-progress-bar').hide();
                            $('.wps-ic-stop-bulk-compress').hide();
                            $('.bulk-status-settings').hide();
                            $('.bulk-status').fadeOut(600, function () {
                                $(bulkFinished).hide().html(r.data.html).fadeIn(800);
                            });
                        }
                    });
                    return;
                }

                $('.bulk-preparing-optimize').hide();
                $('.bulk-status').show();
                setTimeout(bulkCompressHeartbeat, 200);
            },
            error: function () {
                setTimeout(bulkCompressHeartbeat, 3000);
            }
        });
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

});