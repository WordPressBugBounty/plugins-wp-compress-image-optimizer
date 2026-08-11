jQuery(document).ready(function ($) {

    
    
    
    
    
    
    
    
    if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
        document.addEventListener('visibilitychange', function () {
            if (document.hidden) return;
            
            
            
            
            
            
            
        });
    }

    
    
    
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

        
        
        
        var isRestore = $(this).hasClass('wps-ic-stop-bulk-restore');
        var self = this;
        _wpcConfirmStop(isRestore).then(function (result) {
            
            var confirmed = result && (result.isConfirmed === true || result.value === true);
            if (!confirmed) return;
            _doStopBulk.call(self, e);
        });
        return false;
    });

    function _doStopBulk(e) {

        
        
        
        
        
        
        
        
        
        bulkCompressStopped = true;
        
        
        $('body').addClass('wpc-bulk-is-stopping');
        $('.bulk-status-progress-bar').hide();
        $('.bulk-status').hide();
        $('.bulk-area-inner').hide();
        $('.wpc-bulk-v2-surface').hide();
        $('.wpc-restore-surface').hide();
        
        
        
        var $stopBtns = $('.wps-ic-stop-bulk-restore, .wps-ic-stop-bulk-compress').filter(':visible');
        $stopBtns.addClass('is-stopping');
        $stopBtns.find('.wpc-action-btn-label').text('Stopping…');
        

        
        
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
            
            
            
            _redirectFresh();
        }).fail(_redirectFresh);

        
        
        
        
        setTimeout(_redirectFresh, 10000);
        return false;
    }
    

    $('.button-start-bulk-restore').on('click', function (e) {
        e.preventDefault();

        
        
        
        
        
        
        
        
        var $btn = $(this);
        if ($btn.prop('disabled')) return false;
        $btn.prop('disabled', true).addClass('is-debouncing');
        var _wpcRestoreDebounceFailsafe = setTimeout(function () {
            $btn.prop('disabled', false).removeClass('is-debouncing');
        }, 8000);

        
        
        
        var restoreCountText = ($('.wpc-bulk-splash-card--restore .wpc-bulk-splash-count-num').first().text() || '0').replace(/[^0-9]/g, '');
        var restoreCount = parseInt(restoreCountText, 10) || 0;

        
        
        
        
        try { console.log('[WPC RestoreClick] handler fired, count=', restoreCount); } catch (e) {}

        _wpcConfirmRestoreStart(restoreCount).then(function (result) {
            try { console.log('[WPC RestoreClick] popup resolved, isConfirmed=', result && result.isConfirmed, 'value=', result && result.value, 'result=', result); } catch (e) {}
            
            
            
            var confirmed = result && (result.isConfirmed === true || result.value === true);
            if (!confirmed) {
                
                clearTimeout(_wpcRestoreDebounceFailsafe);
                $btn.prop('disabled', false).removeClass('is-debouncing');
                return;
            }
            
            
            
            
            _doStartRestore($btn, _wpcRestoreDebounceFailsafe);
        }).catch(function (err) {
            try { console.error('[WPC RestoreClick] popup error', err); } catch (e) {}
            
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

        
        
        
        
        var _wpcRestoreT0 = Date.now();
        try { console.log('[WPC RestoreTiming] start AJAX sent at', new Date().toISOString()); } catch (e) {}

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wpc_ic_start_bulk_restore', nonce: nonce},
            
            
            
            
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
                
                
                
                
                
                
                if (response && response.success === false) {
                    var msg = (response.data && response.data.msg)
                        ? response.data.msg
                        : 'Could not start bulk restore. Check console for details.';
                    try { console.error('[WPC StartBulkRestore] failed', response); } catch (e) {}
                    if (typeof WPCSwal !== 'undefined') {
                        
                        
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

        
        
        
        
        var $cBtn = $(this);
        if ($cBtn.prop('disabled')) return false;
        $cBtn.prop('disabled', true).addClass('is-debouncing');
        var _wpcCompressDebounceFailsafe = setTimeout(function () {
            $cBtn.prop('disabled', false).removeClass('is-debouncing');
        }, 8000);

        $('.wps-ic-stop-bulk-compress').show();
        $('.bulk-area-inner').show();
        $('#bulk-start-container').hide();
        
        
        
        
        
        
        
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
            
            
            
            
            
            complete: function () {
                clearTimeout(_wpcCompressDebounceFailsafe);
                $cBtn.prop('disabled', false).removeClass('is-debouncing');
            },
            success: function (response) {

                if (response.success == true) {
                    
                    
                    
                    
                    
                    if (response.driver === 'sequential') {
                        var queue = (response.data && response.data.queue) || [];
                        bulkCompressSequentialLoop(queue);
                        
                        
                        bulkCompressProgressPoll(response.driver);
                    } else if (response.driver === 'v2') {
                        bulkCompressProgressPoll(response.driver);
                    } else {
                        bulkCompressHeartbeat();
                    }
                } else {

                    
                    $('.bulk-status-progress-bar').hide();
                    $('.wps-ic-stop-bulk-compress').hide();
                    $('.bulk-status-settings').hide();
                    $('.bulk-status').hide();
                    
                    $('.wps-ic-stop-bulk-compress').hide();
                    $('.bulk-area-inner').hide();
                    $('#bulk-start-container').show();
                    $('.bulk-preparing-optimize').hide();

                    if (response.data.msg == '' || response.data.msg == null) {
                        response.data.msg = 'unknown-error';
                    }

                    
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
        
        
        
        
        
        
        var restoreGraceStartMs = 0;
        var restoreFinalShown   = false;
        
        
        
        
        
        
        
        
        
        var restoreLingerStartMs = 0;
        var RESTORE_LINGER_MS    = 5000;
        
        
        var pollErrorCount = 0;

        var _wpcMlRestHbBusy = false;
        var heartbeatBulkRestore = setInterval(function () {
            
            
            
            
            if (typeof document !== 'undefined' && document.hidden) return;

            if (_wpcMlRestHbBusy) return;
            _wpcMlRestHbBusy = true;
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 10000,
                data: {
                    action: 'wps_ic_bulkRestoreHeartbeat',
                    lastProgress: lastProgress
                },
                complete: function () { _wpcMlRestHbBusy = false; },
                success: function (response) {
                    pollErrorCount = 0;

                    if (response.success == false) {
                        clearInterval(heartbeatBulkRestore);

                        
                        $('.bulk-status-progress-bar').hide();
                        $('.wps-ic-stop-bulk-compress').hide();
                        $('.bulk-status-settings').hide();
                        $('.bulk-status').hide();
                        
                        $('.wps-ic-stop-bulk-compress').hide();
                        $('.bulk-area-inner').hide();
                        $('#bulk-start-container').show();
                        $('.bulk-preparing-optimize').hide();

                        
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

                        
                        
                        
                        
                        
                        if (restoreLingerStartMs === 0) {
                            restoreLingerStartMs = Date.now();
                            
                            
                            if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreProcessing) {
                                WPCBulk.restoreProcessing(d);
                            }
                            return;
                        }
                        if (!restoreFinalShown && (Date.now() - restoreLingerStartMs < RESTORE_LINGER_MS)) {
                            
                            if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreProcessing) {
                                WPCBulk.restoreProcessing(d);
                            }
                            return;
                        }

                        
                        
                        
                        
                        
                        
                        if (!restoreFinalShown) {
                            if (typeof WPCBulk !== 'undefined' && WPCBulk.restoreCompleted) {
                                WPCBulk.restoreCompleted(d);
                            }
                            restoreFinalShown   = true;
                            restoreGraceStartMs = Date.now();
                            return;
                        }

                        
                        
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
        }, 1000);  
    }


    var bulkCompressStopped = false;

    function bulkCompressHeartbeat() {
        if (bulkCompressStopped) return;
        
        
        
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

                
                updateStatusProgressBar(d.progress);

                
                if (d.title) {
                    $('.bulk-process-file-name').html(d.title);
                }
                $('.bulk-status').show();

                
                setTimeout(bulkCompressHeartbeat, 200);
            },
            error: function () {
                if (bulkCompressStopped) return;
                
                setTimeout(bulkCompressHeartbeat, 3000);
            }
        });
    }


    
    
    
    
    
    
    
    
    
    
    
    
    
    window.WPCBulkSeq = window.WPCBulkSeq || {};
    window.WPCBulkSeq.run = function (queue) { bulkCompressSequentialLoop(queue); };
    function bulkCompressSequentialLoop(queue) {
        if (!Array.isArray(queue) || queue.length === 0) return;
        
        
        
        
        
        
        
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
                
                
                timeout: 60000
            }).done(function (resp) {
                console.log('[wpc-seq] image ' + idx + ' phase-A done', resp && resp.success);
            }).fail(function (xhr, status) {
                console.warn('[wpc-seq] image ' + idx + ' phase-A failed:', status);
            }).always(function () {
                
                
                
                
                
                
                
                
                waitForPhaseB(imageId, 0, function () { setTimeout(next, 100); });
            });
        }

        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
        
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
                
                if (newCount !== prevCount) {
                    prevCount   = newCount;
                    prevCountAt = now;
                }
                var stallMs = now - prevCountAt;
                
                
                
                
                if (newCount >= 24) {
                    fireCleanup('full set 24/24 — advancing immediately');
                    return;
                }
                
                
                
                
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


    
    
    
    
    
    
    function bulkCompressProgressPoll(driver) {
        if (bulkCompressStopped) return;

        if (typeof WPCBulk !== 'undefined') WPCBulk.resetCompletionList();

        
        
        
        
        
        
        
        
        
        
        $('.bulk-preparing-restore').hide();
        $('.wps-ic-stop-bulk-restore').hide();
        $('.wps-ic-stop-bulk-compress').show();

        var terminalCount = 0;
        var pollErrorCount = 0;
        var graceStartMs = 0;
        
        
        
        
        
        
        
        var lingerStartMs = 0;
        var _wpcCompressDrainTick = 0;

        var _wpcMlHbBusy = false;
        var pollInterval = setInterval(function () {
            if (bulkCompressStopped) {
                clearInterval(pollInterval);
                return;
            }

            
            
            
            
            
            
            
            
            
            
            
            
            if (typeof document !== 'undefined' && document.hidden) return;

            
            
            
            
            
            
            
            
            
            
            if (driver === 'v2' && (_wpcCompressDrainTick++ % 3) === 0) {
                $.ajax({ url: ajaxurl, type: 'POST', timeout: 30000, data: { action: 'wpc_bulk_v2_drain' } });
            }

            
            var sinceMs = (typeof WPCBulk !== 'undefined' && WPCBulk.getLastVariantMs)
                ? WPCBulk.getLastVariantMs() : 0;

            if (_wpcMlHbBusy) return;
            _wpcMlHbBusy = true;
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 10000,
                data: {action: 'wps_ic_bulkCompressHeartbeat', nonce: ajaxVar.nonce, since_ms: sinceMs},
                complete: function () { _wpcMlHbBusy = false; },
                success: function (response) {
                    
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

                    
                    
                    
                    
                    
                    
                    var hint = '';
                    if ((!d.active || d.active.length === 0)) {
                        if (!d.queue_empty) hint = 'loading';
                        else if (d.pending_drain > 0) hint = 'finalizing';
                    }
                    if (typeof WPCBulk !== 'undefined') {
                        WPCBulk.renderTally(d);
                        WPCBulk.renderActiveTitles(d.active || [], hint, d.up_next || []);
                        WPCBulk.renderActiveThumbs(d.active || [], hint, d.up_next || []);
                        
                        
                        
                        
                        
                        
                        
                        
                        if (typeof WPCBulk.setLastCompletedServer === 'function') {
                            WPCBulk.setLastCompletedServer(d.completed || []);
                        }
                        WPCBulk.renderVariantStream(d.new_variants || []);
                        
                        
                        
                        
                        
                        
                        
                        
                        
                        
                        if (typeof WPCBulk.renderHero === 'function') {
                            WPCBulk.renderHero([]);
                        }
                        
                        
                        
                        
                        
                        
                        
                        
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

                    
                    
                    
                    
                    
                    var terminal = d.queue_empty && d.pending_drain === 0;
                    if (terminal) {
                        terminalCount++;

                        
                        
                        
                        
                        
                        if (terminalCount === 2 && lingerStartMs === 0) {
                            lingerStartMs = Date.now();
                            
                            
                            
                        } else if (lingerStartMs > 0 && graceStartMs === 0) {
                            
                            
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
                            
                            
                            
                            if (typeof WPCBulk !== 'undefined' && WPCBulk.compressCompleted) {
                                WPCBulk.compressCompleted(d);
                            }
                            
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
        }, 1500);  
                   
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


    function updateStatusProgressBar(progress_percent) {
        var progress = $('.bulk-status-progress-bar');
        var progressBar = $('.progress-bar-inner', '.bulk-status-progress-bar');
        $(progress).show();
        $(progressBar).css('width', progress_percent + '%');
    }

});