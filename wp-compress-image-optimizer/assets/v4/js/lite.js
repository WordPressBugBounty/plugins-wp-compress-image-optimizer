jQuery(document).ready(function ($) {


    $('.wpc-lite-toggle-advanced').on('click', function (e) {
        e.preventDefault();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wpsChangeGui',
                view: 'advanced',
                nonce: wpc_ajaxVar.nonce,
            },
            success: function (response) {
                window.location.reload();
            }
        });

        return false;
    });


    $('.wpc-custom-btn.wpc-custom-btn-locked').on('click', function (e) {
        e.preventDefault();
        lockedPopup();
        return false;
    });

    // ─── Track changes for save pill visibility ──────────────────────────
    var initialStates = {};
    var pendingChanges = {}; // settings that differ from initial

    $('.wpc-box-for-checkbox-lite .wpc-ic-settings-v4-checkbox').each(function() {
        initialStates[$(this).attr('name')] = $(this).is(':checked');
    });

    function checkForChanges() {
        var hasChanges = Object.keys(pendingChanges).length > 0;
        if (hasChanges) {
            $('.save-button').fadeIn(400);
        } else {
            $('.save-button').fadeOut(250);
        }
    }

    // ─── Toggle click — instant visual, track pending ──────────────────
    $('.wpc-box-for-checkbox-lite').on('click', function (e) {
        e.preventDefault();

        if ($(this).hasClass('wpc-locked')) {
            lockedPopup();
            return false;
        }

        var parent = $(this);
        var checkbox = $('.wpc-ic-settings-v4-checkbox', parent);
        var name = checkbox.attr('name');
        var wasChecked = checkbox.is(':checked');

        // Toggle
        if (wasChecked) {
            checkbox.removeAttr('checked').prop('checked', false);
        } else {
            checkbox.attr('checked', 'checked').prop('checked', true);
        }

        var nowChecked = checkbox.is(':checked');

        // Track: if different from initial, add to pending; if back to initial, remove
        if (nowChecked !== initialStates[name]) {
            pendingChanges[name] = nowChecked ? '1' : '0';
        } else {
            delete pendingChanges[name];
        }

        checkForChanges();
        return false;
    });

    // ─── Save — AJAX, no page reload ──────────────────────────────────
    $('.save-button-lite').on('click', function(e){
        e.preventDefault();
        var $btn = $(this);
        var $pill = $('.save-button');
        var btnOrigHTML = $btn.html();
        var changeKeys = Object.keys(pendingChanges);

        if (changeKeys.length === 0) return false;

        // Phase 1: Saving
        $btn.addClass('wpc-saving').css('pointer-events', 'none');
        $btn.html('<span class="wpc-save-pill-spinner"></span> ' + (wpc_ajaxVar.saving || 'Saving...'));

        // Save each changed setting via AJAX
        var completed = 0;
        var total = changeKeys.length;
        var hadError = false;

        changeKeys.forEach(function(settingName) {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wps_ic_ajax_checkbox',
                    setting_name: settingName,
                    value: pendingChanges[settingName],
                    checked: pendingChanges[settingName] === '1' ? 'true' : 'false',
                    wps_ic_nonce: wpc_ajaxVar.nonce
                },
                success: function() {
                    completed++;
                    if (completed === total) onAllSaved();
                },
                error: function() {
                    hadError = true;
                    completed++;
                    if (completed === total) onAllSaved();
                }
            });
        });

        function onAllSaved() {
            if (hadError) {
                // Error state — shake + revert
                $btn.removeClass('wpc-saving').css('pointer-events', '');
                $btn.html(btnOrigHTML);
                $pill.css('animation', 'headShake 0.5s');
                setTimeout(function(){ $pill.css('animation', ''); }, 600);
                return;
            }

            // Phase 2: Success
            $btn.removeClass('wpc-saving').addClass('wpc-saved');
            $btn.html('<span class="wpc-save-pill-check-ico"></span> ' + (wpc_ajaxVar.saved || 'Saved'));

            // Update initial states so we know what's "saved" now
            changeKeys.forEach(function(name) {
                initialStates[name] = pendingChanges[name] === '1';
            });
            pendingChanges = {};

            // Phase 3: Slide away
            setTimeout(function(){
                $pill.css({
                    'transition': 'all 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
                    'opacity': '0',
                    'transform': 'translateY(-8px) scale(0.98)'
                });

                setTimeout(function(){
                    // Reset everything for next change
                    $pill.hide().css({
                        'opacity': '',
                        'transform': '',
                        'transition': ''
                    });
                    $btn.removeClass('wpc-saved').css('pointer-events', '');
                    $btn.html(btnOrigHTML);
                }, 500);
            }, 1000);
        }

        return false;
    });


    $('.wps-ic-initial-retest').on('click', function (e) {
        e.preventDefault();

        var $btn = $(this);
        var $boxContent = $btn
            .closest('.wpc-rounded-box')
            .find('.wpc-box-content');

        // Button loading state
        $btn.addClass('wpc-v2-retest-loading');
        $btn.find('img').addClass('wpc-spin');
        $btn.css('pointer-events', 'none');

        // Swap Optimization Stats to skeleton
        var $statsRow = $('.wpc-v2-stats-row').first();
        if ($statsRow.length) {
            var labels = ['Page Size', 'Requests', 'Server Speed'];
            var skeletonHtml = '';
            for (var i = 0; i < labels.length; i++) {
                skeletonHtml += '<div class="wpc-v2-stat-box wpc-v2-skeleton-box">'
                    + '<span class="wpc-v2-stat-label">' + labels[i] + '</span>'
                    + '<div class="wpc-v2-skeleton-value"><div class="loading-icon"><div class="inner"></div></div></div>'
                    + '<div class="wpc-v2-skeleton-badge"><div class="wpc-ic-small-thick-placeholder" style="width:90px;"></div></div>'
                    + '<div class="wpc-v2-stat-sep"></div>'
                    + '<div class="wpc-v2-skeleton-before"><div class="wpc-ic-small-thick-placeholder" style="width:70px;"></div></div>'
                    + '</div>';
            }
            $statsRow.addClass('wpc-v2-skeleton-row').html(skeletonHtml);
        }

        // Hide everything inside PageSpeed card
        $boxContent.children().hide();

        // Show the loading div
        $boxContent.find('.wpc-pagespeed-preparing').show();

        // V2 PageSpeed card: swap scores for loading state
        var $v2Card = $btn.closest('.wpc-v2-card');
        if ($v2Card.length) {
            $v2Card.find('.wpc-v2-scores, .wpc-v2-score-footer').hide();
            var $existingLoader = $v2Card.find('.wpc-v2-scores-loading');
            if ($existingLoader.length) {
                $existingLoader.show();
            } else {
                var barsSvg = '<svg width="135" height="140" viewBox="0 0 135 140" xmlns="http://www.w3.org/2000/svg" fill="#3990ef" style="width:40px;height:40px"><rect y="10" width="15" height="120" rx="6"><animate attributeName="height" begin="0.5s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/><animate attributeName="y" begin="0.5s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/></rect><rect x="30" y="10" width="15" height="120" rx="6"><animate attributeName="height" begin="0.25s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/><animate attributeName="y" begin="0.25s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/></rect><rect x="60" width="15" height="140" rx="6"><animate attributeName="height" begin="0s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/><animate attributeName="y" begin="0s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/></rect><rect x="90" y="10" width="15" height="120" rx="6"><animate attributeName="height" begin="0.25s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/><animate attributeName="y" begin="0.25s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/></rect><rect x="120" y="10" width="15" height="120" rx="6"><animate attributeName="height" begin="0.5s" dur="1s" values="120;110;100;90;80;70;60;50;40;140;120" calcMode="linear" repeatCount="indefinite"/><animate attributeName="y" begin="0.5s" dur="1s" values="10;15;20;25;30;35;40;45;50;0;10" calcMode="linear" repeatCount="indefinite"/></rect></svg>';
                $v2Card.find('.wpc-v2-card-header').after(
                    '<div class="wpc-v2-scores-loading">' +
                    barsSvg +
                    '<span>Analyzing performance...</span>' +
                    '</div>'
                );
            }
        }

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_resetTest',
                nonce: wpc_ajaxVar.nonce,
            },
            success: function (response) {
                // Poll for results instead of blocking reload
                var retestPoll = setInterval(function() {
                    $.post(ajaxurl, { action: 'wps_fetchInitialTest' }, function(res) {
                        if (res.success) {
                            clearInterval(retestPoll);
                            window.location.reload();
                        }
                    });
                }, 5000);
            }
        });
        return false;
    });

    function countUp(element, target, duration) {
        var startTime = performance.now();
        function animate(currentTime) {
            var elapsed = currentTime - startTime;
            var progress = Math.min(elapsed / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
            element.textContent = Math.round(eased * target);
            if (progress < 1) requestAnimationFrame(animate);
        }
        requestAnimationFrame(animate);
    }

    function initializeCircleProgressBars() {
        // Find all elements with the class 'page-stats-circle'
        const circles = document.querySelectorAll('.page-stats-circle');

        // Loop through each circle
        circles.forEach(circle => {
            const progressBar = circle.querySelector('.circle-progress-bar-lite');
            const textElement = circle.querySelector('.stats-circle-text h5');

            // Get the data-value attribute
            const value = parseFloat(progressBar.getAttribute('data-value'));

            // Determine the gradient based on the value
            let gradient;
            let bgFill;
            if (value <= 0.55) {
                gradient = ['#FF0000', '#FF6347']; // Red gradient
                bgFill = '#FFE6E6';
            } else if (value <= 0.89) {
                gradient = ['#FFD700', '#FFA500'];
                bgFill = '#FEF7ED';
            } else if (value <= 0.89) {
                gradient = ['#FFD700', '#FFA500'];
                bgFill = '#FEF7ED';
            } else {
                gradient = ['#22c55e', '#059669']; // green-500 → emerald-600
                bgFill = '#dcfce7'; // green-100 visible track
            }

            // Initialize the circle progress bar with the determined gradient
            $(progressBar).circleProgress({
                value: value,
                size: 100,
                thickness: 8,
                startAngle: -Math.PI / 2,
                lineCap: 'round',
                fill: {gradient: gradient},
                emptyFill: bgFill
            });

            // Update the text element
            countUp(textElement, Math.round(value * 100), 800); // Animated count-up synced with ring fill
        });
    }

    initializeCircleProgressBars();

    // ─── V3 Circle Progress Bars ────────────────────────────────────────
    function initializeV3CircleProgressBars() {
        var v3Rings = document.querySelectorAll('.wpc-v3-ring');
        if (!v3Rings.length) return;
        v3Rings.forEach(function(ring) {
            var progressBar = ring.querySelector('.circle-progress-bar-v3');
            if (!progressBar) return;
            var value = parseFloat(progressBar.getAttribute('data-value'));
            var isLarge = ring.classList.contains('wpc-v3-ring-lg');
            var size = 260;
            var gradient, bgFill;
            if (value <= 0.55) {
                gradient = ['#ef4444', '#f87171'];
                bgFill = '#fee2e2';
            } else if (value <= 0.89) {
                gradient = ['#f59e0b', '#fbbf24'];
                bgFill = '#fef3c7';
            } else {
                gradient = ['#22c55e', '#059669'];
                bgFill = '#dcfce7';
            }
            $(progressBar).circleProgress({
                value: value,
                size: size,
                thickness: isLarge ? 14 : 6,
                startAngle: -Math.PI / 2,
                lineCap: 'round',
                fill: {gradient: gradient},
                emptyFill: bgFill
            });
        });
    }
    initializeV3CircleProgressBars();

    // V2 Circle Progress Bars — now rendered server-side as SVG in lite_settings.php
    // (no jQuery circleProgress needed)

    $('.wpc-cf-link').on('click', function() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wpsChangeGui',
                view: 'advanced',
                nonce: wpc_ajaxVar.nonce,
            },
            success: function (response) {
                window.location.href = window.location.pathname + window.location.search + '#integrations';
                window.location.reload();
            }
        });
    });

});