jQuery(document).ready(function ($) {

    var allowRefresh = true;
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

                        // Clear any pending timeouts for this image
                        if (window['wpcTimeout_' + index]) {
                            clearTimeout(window['wpcTimeout_' + index]);
                            delete window['wpcTimeout_' + index];
                        }
                        if (window['wpcLongTimeout_' + index]) {
                            clearTimeout(window['wpcLongTimeout_' + index]);
                            delete window['wpcLongTimeout_' + index];
                        }
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
     * On page load: if there are images being compressed, switch to fast polling
     * so the UI updates as soon as the background process finishes.
     */
    if ($('.wpc-ml-card.is-compressing').length > 0) {
        heartbeatBurst();
    }

    /**
     * Fallback: If loopback failed, detect images stuck in "compressing"
     * and trigger compression via AJAX. Runs every 15s (recurring).
     * Each image only gets one attempt to avoid duplicate compression.
     */
    var wpcFallbackChecked = {};
    var wpcFallbackInterval = null;

    function wpcCheckFallbackCompress() {
        var stuck = $('.wpc-ml-card.is-compressing');
        if (stuck.length === 0) {
            // No more stuck images — stop polling
            if (wpcFallbackInterval) {
                clearInterval(wpcFallbackInterval);
                wpcFallbackInterval = null;
            }
            return;
        }

        stuck.each(function () {
            var card = $(this);
            var parent = card.closest('[class*="wps-ic-media-actions-"]');
            if (!parent.length) return;
            var match = parent.attr('class').match(/wps-ic-media-actions-(\d+)/);
            if (!match) return;
            var id = match[1];

            if (wpcFallbackChecked[id]) return;
            wpcFallbackChecked[id] = true;

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                timeout: 180000,
                data: {
                    action: 'wps_ic_compress_live',
                    attachment_id: id,
                    bulk: false
                },
                success: function (response) {
                    if (response.success && response.data && response.data.html) {
                        parent.html(response.data.html);
                        parent.find('.wpc-ml-card').addClass('is-compressed');
                    }
                }
            });
        });
    }

    // Start fallback check 20s after load, then every 15s
    setTimeout(function () {
        wpcCheckFallbackCompress();
        wpcFallbackInterval = setInterval(wpcCheckFallbackCompress, 15000);
    }, 20000);


    /**
     * Exclude/Include — toggle class, badge animates via CSS, body swaps with fade
     */
    $('body').on('click', '.wps-ic-exclude-live,.wps-ic-include-live', function (e) {
        e.preventDefault();
        var button = $(this);
        if (button.hasClass('wpc-action-pending')) return;
        button.addClass('wpc-action-pending');

        var attachment_id = $(button).data('attachment_id');
        var action = $(button).data('action') || 'exclude';
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var card = parent.find('.wpc-ml-card');
        var body = parent.find('.wpc-ml-body');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_exclude_live',
                do_action: action,
                attachment_id: attachment_id
            },
            success: function (response) {
                if (response.success && response.data && response.data.html) {
                    var newHtml = $(response.data.html);
                    var newBody = newHtml.find('.wpc-ml-body').html();
                    var isExcluded = newHtml.hasClass('is-excluded');

                    // Toggle state — CSS transitions handle badge + icon box
                    if (isExcluded) {
                        card.addClass('is-excluded wpc-ml-card--excluded').removeClass('wpc-ml-card--uncompressed');
                    } else {
                        card.removeClass('is-excluded wpc-ml-card--excluded').addClass('wpc-ml-card--uncompressed');
                        // Re-trigger bump animation on un-exclude
                        var iconBox = card.find('.wpc-ml-card-icon');
                        iconBox.css('animation', 'none');
                        iconBox[0].offsetHeight;
                        iconBox.css('animation', '');
                    }

                    // Swap body content with fade-in-up
                    body.html('<div class="fade-in-up">' + newBody + '</div>');
                }
                button.removeClass('wpc-action-pending');
            },
            error: function () {
                button.removeClass('wpc-action-pending');
            }
        });
    });

    /**
     * Restore Live — direct, no queue
     */
    $('body').on('click', '.wps-ic-restore-live', function (e) {

        e.preventDefault();

        var button = $(this);
        if (button.hasClass('wpc-action-pending')) return;
        button.addClass('wpc-action-pending');

        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var card = parent.find('.wpc-ml-card');

        // Toggle to restoring state — engine spins CCW
        card.removeClass('wpc-ml-card--compressed wpc-ml-card--uncompressed is-compressed is-compressing').addClass('is-restoring');
        card.find('.wpc-ml-body').html('<div class="fade-in-up"><div class="wpc-ml-title">' + (wpc_ajaxVar.statusRestoring || 'Restoring') + '...</div><div class="wpc-skeleton"><div class="wpc-skeleton-bar w-short"></div><div class="wpc-skeleton-bar w-long"></div></div></div>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 90000,
            data: {
                action: 'wps_ic_restore_live',
                attachment_id: attachment_id,
            },
            success: function (response) {
                if (response.success && response.data && response.data.html) {
                    parent.html(response.data.html);
                    // Trigger restored pop animation
                    parent.find('.wpc-ml-card').addClass('is-restored');
                } else {
                    card.removeClass('is-restoring');
                    var msg = (response.data && response.data.msg) ? response.data.msg : '';
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
                }
                button.removeClass('wpc-action-pending');
            },
            error: function () {
                card.removeClass('is-restoring');
                button.removeClass('wpc-action-pending');
                heartbeatBurst();
            }
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
     * Compress Live — fire and forget, uses queue
     */
    $('body').on('click', '.wps-ic-compress-live', function (e) {
        e.preventDefault();

        var button = $(this);
        if (button.hasClass('wpc-action-pending')) return;
        button.addClass('wpc-action-pending');

        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var card = parent.find('.wpc-ml-card');

        // Show spinner immediately
        card.removeClass('wpc-ml-card--uncompressed wpc-ml-card--compressed is-restored is-restoring').addClass('is-compressing');
        card.find('.wpc-ml-body').html('<div class="fade-in-up"><div class="wpc-ml-title">Optimizing...</div><div class="wpc-skeleton"><div class="wpc-skeleton-bar w-long"></div><div class="wpc-skeleton-bar w-short"></div></div></div>');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 10000,
            data: {
                action: 'wps_ic_compress_live',
                bulk: false,
                attachment_id: attachment_id
            },
            success: function (response) {
                if (response.success && response.data && response.data.queued) {
                    // Queued — heartbeat will pick up the result
                    heartbeatBurst();
                } else if (response.success && response.data && response.data.html) {
                    // Immediate result (already compressed)
                    parent.html(response.data.html);
                    parent.find('.wpc-ml-card').addClass('is-compressed');
                } else if (!response.success && response.data && response.data.msg === 'no-credits') {
                    card.removeClass('is-compressing');
                    WPCSwal.fire({
                        title: '',
                        html: $('#no-credits-popup').html(),
                        width: 500,
                        showConfirmButton: false,
                        allowOutsideClick: true,
                        customClass: { container: 'no-padding-popup-bottom-bg switch-legacy-popup wpc-popup-v6' },
                    });
                } else {
                    // Error — switch to heartbeat polling as fallback
                    heartbeatBurst();
                }
                button.removeClass('wpc-action-pending');
            },
            error: function () {
                // Network error — heartbeat will catch up
                heartbeatBurst();
                button.removeClass('wpc-action-pending');
            }
        });
    });


    // ─── Stats Detail Modal ─────────────────────────────────────
    $(document).on('click', '.wpc-stats-trigger', function (e) {
        e.preventDefault();
        var attachment_id = $(this).data('attachment_id');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_image_stats',
                attachment_id: attachment_id,
                wps_ic_nonce: wpc_ajaxVar.nonce
            },
            success: function (response) {
                if (response.success && response.data && response.data.html) {
                    WPCSwal.fire({
                        title: '',
                        html: response.data.html,
                        width: 680,
                        showConfirmButton: false,
                        showCloseButton: true,
                        allowOutsideClick: true,
                        customClass: {
                            container: 'no-padding-popup-bottom-bg wpc-popup-v6',
                            popup: 'wpc-stats-swal'
                        },
                        onOpen: function () {
                            // Staggered row entrance + bar fill animation
                            var rows = document.querySelectorAll('.wpc-row-enter');
                            rows.forEach(function (row, i) {
                                setTimeout(function () {
                                    row.classList.add('wpc-row-active');
                                    var bar = row.querySelector('.wpc-bar-fill');
                                    if (bar) {
                                        setTimeout(function () {
                                            bar.style.width = bar.getAttribute('data-target') + '%';
                                        }, 200);
                                    }
                                }, i * 30 + 100);
                            });

                            // Initialize before/after comparison slider
                            var wrap = document.querySelector('.wpc-compare-wrap');
                            if (!wrap) return;

                            var handle = wrap.querySelector('.wpc-compare-handle');
                            var before = wrap.querySelector('.wpc-compare-before');
                            var beforeImg = before.querySelector('img');
                            var dragging = false;

                            // Set before image width to match container
                            beforeImg.style.width = wrap.offsetWidth + 'px';

                            function updateSlider(x) {
                                var rect = wrap.getBoundingClientRect();
                                var pct = Math.max(0, Math.min(1, (x - rect.left) / rect.width));
                                handle.style.left = (pct * 100) + '%';
                                before.style.width = (pct * 100) + '%';
                            }

                            handle.addEventListener('mousedown', function (e) {
                                e.preventDefault();
                                dragging = true;
                            });
                            handle.addEventListener('touchstart', function () {
                                dragging = true;
                            }, {passive: true});

                            document.addEventListener('mousemove', function (e) {
                                if (dragging) updateSlider(e.clientX);
                            });
                            document.addEventListener('touchmove', function (e) {
                                if (dragging) updateSlider(e.touches[0].clientX);
                            }, {passive: true});

                            document.addEventListener('mouseup', function () { dragging = false; });
                            document.addEventListener('touchend', function () { dragging = false; });

                            // Click anywhere on image to reposition
                            wrap.addEventListener('click', function (e) {
                                updateSlider(e.clientX);
                            });
                        }
                    });
                }
            }
        });
    });

});

window.onbeforeunload = function () {
    if (!allowRefresh) {
        return "Data will be lost if you leave the page, are you sure?";
    }
};