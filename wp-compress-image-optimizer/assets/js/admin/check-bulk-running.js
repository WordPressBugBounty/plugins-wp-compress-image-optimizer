jQuery(document).ready(function ($) {

    function fetchRestoreData() {
        
        
        
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
        
        
        
        
        

        
        
        
        
        
        
        if (driver === 'sequential') {
            bulkCompressV2Poll(driver);
            
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

    function fetchingBulkData(attempt) {
        attempt = attempt || 0;
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            
            
            timeout: 15000,
            data: {
                action: 'wps_ic_isBulkRunning'
            },
            
            
            complete: function (xhr) {
                
                
                
                if ((!xhr || !xhr.status) && attempt < 2) {
                    setTimeout(function () { fetchingBulkData(attempt + 1); }, 3000);
                    return;
                }
                var response = {};
                try { response = JSON.parse(xhr.responseText || '{}'); } catch (e) {}
                
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

    
    
    
    function bulkCompressV2Poll(driver) {
        if (typeof WPCBulk !== 'undefined') WPCBulk.resetCompletionList();

        $('.bulk-preparing-restore').hide();
        $('.bulk-preparing-placholders').hide();
        $('.wps-ic-stop-bulk-restore').hide();
        $('.wps-ic-stop-bulk-compress').show();
        
        
        $('.bulk-status').hide();
        $('.bulk-status-progress-bar').hide();
        $('.bulk-compress-status-progress-prepare').hide();

        
        
        
        
        
        
        $('.bulk-preparing-optimize').hide();
        $('.wpc-bulk-v2-surface').show();

        var terminalCount = 0;
        var _wpcResumeDrainTick = 0;
        var _wpcHbBusy = false;
        var _wpcHbErr = 0;

        function _tick() {
            
            
            
            if (typeof document !== 'undefined' && document.hidden) return;

            
            
            
            
            
            
            
            
            
            
            
            
            if (driver === 'v2' && (_wpcResumeDrainTick++ % 4) === 0) {
                $.ajax({ url: ajaxurl, type: 'POST', timeout: 30000, data: { action: 'wpc_bulk_v2_drain' } });
            }

            
            
            if (_wpcHbBusy) return;
            _wpcHbBusy = true;
            var sinceMs = (typeof WPCBulk !== 'undefined' && WPCBulk.getLastVariantMs)
                ? WPCBulk.getLastVariantMs() : 0;
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 10000,
                data: {action: 'wps_ic_bulkCompressHeartbeat', nonce: ajaxVar.nonce, since_ms: sinceMs},
                complete: function () { _wpcHbBusy = false; },
                success: function (response) {
                    if (response.success === false) {
                        clearInterval(pollInterval);
                        return;
                    }
                    _wpcHbErr = 0;
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
                },
                error: function (xhr) {
                    var s = (xhr && xhr.status) || 0;
                    if (s === 400 || s === 401 || s === 403 || s === 404 || s === 410) { clearInterval(pollInterval); return; }
                    if (++_wpcHbErr >= 10) { clearInterval(pollInterval); }
                }
            });
        }

        
        
        
        
        _tick();
        var pollInterval = setInterval(_tick, 1000);
    }

    
    fetchingBulkData();

    var lastProgress = 0;
    function bulkRestoreHeartbeat() {
        if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreReset) WPCBulk.restoreReset();
        
        $('.bulk-preparing-optimize').hide();
        $('.bulk-preparing-restore').hide();
        $('.bulk-compress-status-progress-prepare').hide();
        $('.bulk-compress-status-progress').hide();
        $('.bulk-status').hide();
        $('.bulk-status-progress-bar').hide();

        var _wpcRestoreDrainTick = 0;
        var _wpcRestHbBusy = false;
        var _wpcRestErr = 0;
        var heartbeatBulkRestore = setInterval(function(){
            
            
            
            if ((_wpcRestoreDrainTick++ % 4) === 0) {
                $.ajax({ url: ajaxurl, type: 'POST', timeout: 30000, data: { action: 'wpc_bulk_v2_restore_drain' } });
            }
            if (_wpcRestHbBusy) return;
            _wpcRestHbBusy = true;
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 10000,
                data: {action: 'wps_ic_bulkRestoreHeartbeat', lastProgress: lastProgress},
                complete: function () { _wpcRestHbBusy = false; },
                success: function (response) {
                    var d = response.data || {};
                    _wpcRestErr = 0;

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
                },
                error: function (xhr) {
                    var s = (xhr && xhr.status) || 0;
                    if (s === 400 || s === 401 || s === 403 || s === 404 || s === 410) { clearInterval(heartbeatBulkRestore); return; }
                    if (++_wpcRestErr >= 10) { clearInterval(heartbeatBulkRestore); }
                }
            });
        }, 1000);
    }



    var _wpcBchErr = 0;
    function bulkCompressHeartbeat() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 180000,
            data: {action: 'wps_ic_doBulkCompress', nonce: ajaxVar.nonce},
            success: function (response) {
                if (response.success == false) return;
                _wpcBchErr = 0;

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
            error: function (xhr) {
                var s = (xhr && xhr.status) || 0;
                if (s === 400 || s === 401 || s === 403 || s === 404 || s === 410) return;
                if (++_wpcBchErr >= 10) return;
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
        
        $(avgReduction).html(data.progressAvgReduction);
        $(progress).show();
    }

});