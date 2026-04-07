jQuery(document).ready(function ($) {

    var allowRefresh = true;

    // ─── Action Queue — process one compress/restore at a time ────────────
    var wpcActionQueue = [];
    var wpcActionRunning = false;

    function wpcEnqueue(fn) {
        wpcActionQueue.push(fn);
        wpcProcessQueue();
    }

    function wpcProcessQueue() {
        if (wpcActionRunning || wpcActionQueue.length === 0) return;
        wpcActionRunning = true;
        var next = wpcActionQueue.shift();
        // Safety timeout: unlock queue after 60s even if done() never called (network hang)
        var safetyTimer = setTimeout(function () {
            wpcActionRunning = false;
            wpcProcessQueue();
        }, 60000);
        next(function () {
            clearTimeout(safetyTimer);
            wpcActionRunning = false;
            setTimeout(wpcProcessQueue, 1500);
        });
    }
    $('.ic-tooltip').tooltipster({
        maxWidth: '300',
        delay: 100,
        trigger: 'hover',
        theme: 'tooltipster-shadow',
        position: 'top',
    });

    /**
     * Media Library Button Group
     */
    $('body').on('click', '.wpc-show-btn-group', function (e) {
        e.preventDefault();

        var group = $(this).parent();

        if (!$(group).hasClass('visible')) {
            var hidden = $('.wpc-dropdown-item-hidden', group);

            $(group).addClass('visible');
            $(hidden).removeClass('wpc-dropdown-item-hidden');
            $('.wpc-show-btn-group>i', group).removeClass('icon-angle-down').addClass('icon-angle-up');
            $(hidden).addClass('wpc-dropdown-item-visible');
        } else {
            var hidden = $('.wpc-dropdown-item-visible', group);

            $(group).removeClass('visible');
            $(hidden).removeClass('wpc-dropdown-item-visible');
            $('.wpc-show-btn-group>i', group).addClass('icon-angle-down').removeClass('icon-angle-up');
            $(hidden).addClass('wpc-dropdown-item-hidden');
        }

        return false;
    });

    /**
     * Media Library - Heartbeat
     * Normal: 8s interval. After compress/restore: switches to 3s for 60s, then back to 8s.
     */
    var wpcHBInterval = 8000;
    var wpcHBTimer = null;
    var wpcHBBurstTimeout = null;
    var wpcHBRunning = false;

    function wpcStartHeartbeat(interval) {
        if (wpcHBTimer) clearInterval(wpcHBTimer);
        wpcHBInterval = interval;
        wpcHBTimer = setInterval(heartbeat, interval);
    }

    // Start normal heartbeat
    wpcStartHeartbeat(8000);

    var heartbeat = function () {
        if (wpcHBRunning) return; // Prevent overlapping requests
        wpcHBRunning = true;
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wps_ic_media_library_heartbeat'},
            success: function (response) {
                wpcHBRunning = false;
                if (response.success == true) {
                    $.each(response.data.html, function (index, value) {
                        var parent = $('.wps-ic-media-actions-' + index);
                        var loading = $('.wps-ic-image-loading-' + index);
                        $(loading).hide();
                        $(parent).html(value).show();
                    });

                    $('.ic-tooltip').tooltipster({
                        maxWidth: '300',
                    });
                }
            },
            error: function () {
                wpcHBRunning = false;
            }
        });
    };

    // Switch to fast polling for 60s after an action, then back to normal
    function heartbeatBurst() {
        if (wpcHBInterval !== 3000) {
            wpcStartHeartbeat(3000);
        }
        // Reset the 60s countdown on each new action
        if (wpcHBBurstTimeout) clearTimeout(wpcHBBurstTimeout);
        wpcHBBurstTimeout = setTimeout(function () {
            wpcStartHeartbeat(8000);
            wpcHBBurstTimeout = null;
        }, 60000);
        // Immediate poll
        heartbeat();
    }


    /**
     * Exclude Live
     */
    $('body').on('click', '.wps-ic-exclude-live,.wps-ic-include-live', function (e) {
        e.preventDefault();

        var button = $(this);
        var attachment_id = $(button).data('attachment_id');
        var action = $(button).data('action');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var loading = $('.wps-ic-image-loading-' + attachment_id);

        $(parent).hide();
        $(loading).show();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_exclude_live',
                attachment_id: attachment_id,
                do_action: action
            },
            success: function (response) {
                //heartbeat();
                // Image data
                $('.wps-ic-media-actions-' + attachment_id).html(response.data.html);
                $(parent).show();
                $(loading).hide();

                $('.ic-tooltip').tooltipster({
                    maxWidth: '300',
                });
            },
            error: function (xhr, ajaxOptions, thrownError) {
                console.log(xhr.status);
                console.log(xhr.responseText);
                console.log(thrownError);
            }
        });

    });

    /**
     * Restore Live — queued, one at a time
     */
    $('body').on('click', '.wps-ic-restore-live', function (e) {

        e.preventDefault();

        var button = $(this);
        if (button.hasClass('wpc-action-pending')) return;
        button.addClass('wpc-action-pending');

        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var loading = $('.wps-ic-image-loading-' + attachment_id);

        $(parent).hide();
        $(loading).show();

        wpcEnqueue(function (done) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 90000,
                data: {
                    action: 'wps_ic_restore_live',
                    attachment_id: attachment_id,
                },
                success: function (response) {
                    heartbeatBurst();
                    button.removeClass('wpc-action-pending');
                    done();
                },
                error: function (xhr, ajaxOptions, thrownError) {
                    $(parent).show();
                    $(loading).hide();
                    button.removeClass('wpc-action-pending');

                    var msg = '';
                    try { msg = JSON.parse(xhr.responseText).data.msg; } catch(e) {}
                    if (msg && $('#' + msg).length) {
                        WPCSwal.fire({
                            title: '',
                            html: $('#' + msg).html(),
                            width: 500,
                            showConfirmButton: false,
                            allowOutsideClick: true,
                            customClass: { container: 'no-padding-popup-bottom-bg switch-legacy-popup wpc-popup-v6' },
                        });
                    }
                    done();
                }
            });
        });

    });

    /**
     * Exclude Link
     * wps-ic-exclude-live-link
     */
    $('.wps-ic-exclude-live-link,.wps-ic-include-live-link').on('click', function (e) {
        e.preventDefault();

        var link = $(this);
        var action = $(link).data('action');
        var attachment_id = $(link).data('attachment_id');
        var loading = $('#wp-ic-image-loading-' + attachment_id);

        $(link).hide();
        $(loading).show();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_exclude_live',
                attachment_id: attachment_id,
                do_action: action
            },
            success: function (response) {

                if (action == 'exclude') {
                    // Show include
                    $('#wps-ic-exclude-live-link-' + attachment_id).hide();
                    $('#wps-ic-include-live-link-' + attachment_id).show();
                } else {
                    // Show exclude
                    $('#wps-ic-exclude-live-link-' + attachment_id).show();
                    $('#wps-ic-include-live-link-' + attachment_id).hide();
                }

                $(loading).hide();
            },
            error: function (xhr, ajaxOptions, thrownError) {
                console.log(xhr.status);
                console.log(xhr.responseText);
                console.log(thrownError);
            }
        });

    });

    /**
     * Compress Live
     */
    $('body').on('click', '.wps-ic-compress-live-no-credits', function (e) {
        e.preventDefault();

        allowRefresh = false;
        var button = $(this);
        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var loading = $('.wps-ic-image-loading-' + attachment_id);

        // Load Popup
        WPCSwal.fire({
            title: '',
            html: $('#no-credits-popup').html(),
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

        return false;
    });

    /**
     * Compress Live — queued, one at a time
     */
    $('body').on('click', '.wps-ic-compress-live', function (e) {
        e.preventDefault();

        var button = $(this);
        if (button.hasClass('wpc-action-pending')) return;
        button.addClass('wpc-action-pending');

        allowRefresh = false;
        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var loading = $('.wps-ic-image-loading-' + attachment_id);

        $(parent).hide();
        $(loading).show();

        wpcEnqueue(function (done) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wps_ic_compress_live',
                    bulk: false,
                    attachment_id: attachment_id
                },
                success: function (response) {

                    if (response.success) {
                        if (response.data == 'no-credits') {
                            WPCSwal.fire({
                                title: '',
                                html: $('#no-credits-popup').html(),
                                width: 500,
                                showConfirmButton: false,
                                allowOutsideClick: true,
                                customClass: { container: 'no-padding-popup-bottom-bg switch-legacy-popup wpc-popup-v6' },
                            });
                        } else {
                            heartbeatBurst();
                        }
                    } else {
                        $(loading).hide();
                        if (response.data && response.data.html) {
                            $(parent).html(response.data.html).show();
                        } else {
                            $(parent).show();
                        }

                        var msg = (response.data && response.data.msg) ? response.data.msg : 'unknown-error';
                        if ($('#' + msg).length) {
                            WPCSwal.fire({
                                title: '',
                                html: $('#' + msg).html(),
                                width: 500,
                                showConfirmButton: false,
                                allowOutsideClick: true,
                                customClass: { container: 'no-padding-popup-bottom-bg switch-legacy-popup wpc-popup-v6' },
                            });
                        }


                    }
                    button.removeClass('wpc-action-pending');
                    done();
                },
                error: function (xhr, ajaxOptions, thrownError) {
                    allowRefresh = true;
                    $(loading).hide();
                    $(parent).show();
                    button.removeClass('wpc-action-pending');
                    done();
                }
            });
        });

    });


});

window.onbeforeunload = function () {
    if (!allowRefresh) {
        return "Data will be lost if you leave the page, are you sure?";
    }
};