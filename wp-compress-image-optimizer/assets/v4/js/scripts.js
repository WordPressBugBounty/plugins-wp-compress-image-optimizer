jQuery(document).ready(function ($) {
    var currentPage = 1;
    var itemsPerPage = 10;
    var fetchRunning = false;
    var searchPending = false;
    var searchTerm = '';

    // Tooltip hover intent — enable tooltips after 300ms on card
    $('.wpc-perf-grid').on('mouseenter', '.wpc-box-for-checkbox, .wpc-box-for-input, .wpc-box-for-dropdown', function () {
        var $card = $(this);
        $card.data('tooltip-timer', setTimeout(function () {
            $card.addClass('wpc-tooltip-ready');
        }, 500));
    }).on('mouseleave', '.wpc-box-for-checkbox, .wpc-box-for-input, .wpc-box-for-dropdown', function () {
        clearTimeout($(this).data('tooltip-timer'));
        $(this).removeClass('wpc-tooltip-ready');
    });

    /**
     * Scan Fonts (AJAX)
     */
    $('#wpc-scan-trigger').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $input = $('#wpc-scan-url');
        var url = $input.val();
        if (!url) return;

        var originalHtml = $btn.html();
        $btn.prop('disabled', true).html('<span class="wpc-btn-spinner"></span> ' + (wpc_ajaxVar.scanning || 'Scanning\u2026'));
        $input.prop('disabled', true);
        $('.wpc-scan-result').remove();

        $.ajax({
            url: ajaxurl, type: 'POST',
            data: { action: 'wpsScanFonts', scanUrl: url, nonce: wpc_ajaxVar.nonce },
            success: function (response) {
                var found = response.data && response.data.found;
                if (found) {
                    // Reload and scroll to scanner to see results
                    var newUrl = window.location.pathname + window.location.search.replace(/&fontScanResult=[^&]*/g, '') + '&fontScanResult=found#scan-fonts';
                    if (window.location.href === newUrl || window.location.href.indexOf('fontScanResult=found') !== -1) {
                        window.location.href = newUrl;
                        window.location.reload();
                    } else {
                        window.location.href = newUrl;
                    }
                } else {
                    // Show inline "not found" message
                    $btn.prop('disabled', false).html(originalHtml);
                    $input.prop('disabled', false);
                    var $msg = $('<div class="wpc-scan-result wpc-scan-result-empty"><svg width="14" height="14" viewBox="0 0 512 512" fill="currentColor" style="opacity:.5"><path d="M256 512A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM176 384l160 0 0-48-160 0 0 48zm-16-128a32 32 0 1 1 64 0 32 32 0 1 1 -64 0zm192-32a32 32 0 1 1 0 64 32 32 0 1 1 0-64z"/></svg> ' + (wpc_ajaxVar.noFontsDetected || 'No Google Fonts detected on this page.') + '</div>');
                    $('.wpc-scan-input-group').after($msg);
                    $msg.hide().fadeIn(300);
                    setTimeout(function(){ $msg.fadeOut(400, function(){ $msg.remove(); }); }, 6000);
                }
            },
            error: function () {
                $btn.prop('disabled', false).html(originalHtml);
                $input.prop('disabled', false);
                var $msg = $('<div class="wpc-scan-result wpc-scan-result-error">' + (wpc_ajaxVar.scanFailed || 'Scan failed. Please try again.') + '</div>');
                $('.wpc-scan-input-group').after($msg);
                $msg.hide().fadeIn(300);
                setTimeout(function(){ $msg.fadeOut(400, function(){ $msg.remove(); }); }, 5000);
            }
        });
    });

    /**
     * Remove Scanned Fonts
     */
    $('.wpc-remove-fonts').on('click', function (e) {
        e.preventDefault();

        var fontID = $(this).data('font-id');
        $.ajax({
            url: ajaxurl, type: 'POST', data: {
                action: 'wpsRemoveFont', fontId: fontID, nonce: wpc_ajaxVar.nonce,
            }, success: function (response) {
                $('.wpc-font-item[data-font-row="'+fontID+'"]').fadeOut(500, function(){
                   $(this).remove();
                });
            }
        });

        return false;
    });

    // Fancy Dropdown
    $('.wpc-cf-zone-list').on('click', function () {
        $('.wpc-cf-zone-list-items').toggle();
    });


    $(document).on('input', '#live-search', function () {
        doSearch()
    });

    $(document).on('keypress', '#live-search', function (e) {
        if (e.which === 13) {
            e.preventDefault();
        }
    });

    function doSearch() {
        var raw = $('#live-search').val().trim();
        // Strip URL prefixes to extract the slug for smarter matching
        searchTerm = raw.replace(/^https?:\/\/[^\/]+\/?/, '').replace(/\/$/, '').replace(/\//g, ' ');
        // If stripping yielded nothing, fall back to original input
        if (!searchTerm && raw) searchTerm = raw;
        currentPage = 1;
        if (fetchRunning === false) {
            fetchPosts(selectedTypes, currentPage);
        } else {
            searchPending = true;
        }
    }

    // Icon style toggle: gradient → tint → brand → brand A → brand B → default
    var iconStyles = ['', 'wpc-icon-tint', 'wpc-icon-brand', 'wpc-icon-brand wpc-brand-a', 'wpc-icon-brand wpc-brand-b'];
    var iconStyleIndex = 0;
    var iconStyleLabels = ['Gradient', 'Soft Tint', 'Brand', 'Brand A', 'Brand B'];
    var allIconClasses = 'wpc-icon-tint wpc-icon-brand wpc-brand-a wpc-brand-b';
    $(document).on('click', '.wpc-icon-style-toggle', function () {
        $('body').removeClass(allIconClasses);
        iconStyleIndex = (iconStyleIndex + 1) % iconStyles.length;
        if (iconStyles[iconStyleIndex]) $('body').addClass(iconStyles[iconStyleIndex]);
        $('.wpc-icon-style-label', this).text(iconStyleLabels[iconStyleIndex]);
    });

    $(document).on('click', '.wpc-change-ui-to-simple', function (e) {
        e.preventDefault();

        $.ajax({
            url: ajaxurl, type: 'POST', data: {
                action: 'wpsChangeGui', view: 'lite', nonce: wpc_ajaxVar.nonce,
            }, success: function (response) {
                window.location.reload();
            }
        });

        return false;
    });


    $('.wps-ic-stop-bulk-restore,.wps-ic-stop-bulk-compress').on('click', function (e) {
        e.preventDefault();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wps_ic_StopBulk', nonce: wpc_ajaxVar.nonce},
            success: function (response) {
                if (response.success == true) {
                    window.location.reload();
                }
            }
        });

        return false;
    });

    function initTooltipster() {
        $('.OptimizationPageTooltip:not(.tooltipstered)').tooltipster({
            minWidth: 220,
            delay: 50,
            trigger: 'hover',
            theme: 'tooltipster-shadow',
            position: 'top',
            contentAsHTML: true,
            functionInit: function (instance, helper) {
                var divID = $(helper.origin).data('setting_name');
                var content = $('#wpc-tooltip-' + divID).html();
                instance.content(content);
            },
            functionBefore: function (instance, helper) {
                // Close other tooltips before opening a new one
                $.tooltipster.instances().forEach(function (item) {
                    if (item !== instance) {
                        item.close();
                    }
                });

                var settingState = $(helper.origin).data('setting_state');

                // find the HTML of the tooltip
                var html = $(instance.__Content);

                // Change HTML of Tooltip
                if (settingState == '1') {
                    html.find('span.status').addClass('active').html(wpc_ajaxVar.active || 'Active');
                } else {
                    html.find('span.status').addClass('disabled').html(wpc_ajaxVar.disabled || 'Disabled');
                }

                instance.content(html);

                return true;
            },
        });

        $('.OptimizationErrorTooltip:not(.tooltipstered)').tooltipster({
            minWidth: 150,
            delay: 50,
            trigger: 'hover',
            theme: 'tooltipster-shadow',
            position: 'top',
            contentAsHTML: true,
            functionInit: function (instance, helper) {
                var content = $('#wpc-tooltip-error').html(); // Assuming 'wpc-tooltip-error' is your default tooltip content ID
                instance.content(content);
            },
            functionBefore: function (instance, helper) {
                // Close other tooltips before opening a new one
                $.tooltipster.instances().forEach(function (item) {
                    if (item !== instance) {
                        item.close();
                    }
                });

                // Get data attributes
                var errorCode = $(helper.origin).data('code');
                var errorText = $(helper.origin).data('text');


                // Clone the HTML of the default tooltip content
                var html = $(instance.__Content);

                // Update HTML based on data-code and data-text
                html.find('span.errorCode').html(errorCode);
                html.find('span.errorText').html(errorText);

                // Set the updated content for the tooltip
                instance.content(html);

                return true;
            },
        });


        const queryParams = new URLSearchParams(window.location.search);
        if (queryParams.get('page') === 'wpcompress') {

            $('.LockedTooltip:not(.tooltipstered)').tooltipster({
                minWidth: 150,
                delay: 50,
                trigger: 'hover',
                theme: 'tooltipster-noir',
                position: 'top',
                contentAsHTML: true,
                functionInit: function (instance, helper) {
                    var content = $('#wpc-locked-tooltip').html();
                    instance.content(content);

                },
                functionBefore: function (instance, helper) {
                    // Close other tooltips before opening a new one
                    $.tooltipster.instances().forEach(function (item) {
                        if (item !== instance) {
                            item.close();
                        }
                    });

                    // Get data attributes
                    var popText = $(helper.origin).data('pop-text');

                    // Clone the HTML of the default tooltip content
                    var html = $(instance.__Content);

                    // Update HTML based on data-code and data-text
                    html.find('span.pop-text').html(popText);

                    // Set the updated content for the tooltip
                    instance.content(html);

                    return true;
                },
            });

            $(document).on('click', '.LockedTooltip', function (event) {
                window.open('https://wpcompress.com/optimize/', '_blank');
                event.preventDefault();
                event.stopPropagation();
            });

            $('.wpc-locked-checkbox-container').on('click', function (e) {
                if ($(this).find('.LockedTooltip').length > 0) {
                    window.open('https://wpcompress.com/optimize/', '_blank');
                    event.preventDefault();
                    event.stopPropagation();
                }
            });


            $('.wpc-box-check').on('click', function (e) {
                if ($(this).find('.LockedTooltip').length > 0) {
                    window.open('https://wpcompress.com/optimize/', '_blank');
                    event.preventDefault();
                    event.stopPropagation();
                }
            });

        }
    }

    initTooltipster();


    $('#optimizationTable').on('click', '.wpc-dropdown-row-header', function (e) {
        return;
        if (local === false) {
            // Check if the clicked element or any of its parents has the class .dropdown-item
            if ($(e.target).closest('.dropdown-item').length === 0 && $(e.target).closest('.wpc-test-redo').length === 0 && $(e.target).closest('.per-page-settings-cog').length === 0) {
                var parent = $(this).parent();
                var box = $('.wpc-dropdown-row-data', parent);
                var icon = $('.wpc-dropdown-row-arrow', parent);

                if ($(box).is(':visible')) {
                    $(box).slideUp(300);
                    $('i', icon).removeAttr('class').addClass('icon-down-open');
                } else {
                    $(box).slideDown(300);
                    $('i', icon).removeAttr('class').addClass('icon-up-open');
                }
            }
        }

    });


    $('#optimizationTable').on('click', '.per-page-settings-cog', function (e) {
        e.preventDefault();
        var ID = $(this).closest('.wpc-dropdown-row').attr('id');

        var popupHtml = '<div id="' + ID + '" class="cdn-popup-inner ajax-settings-popup bottom-border exclude-list-popup">' + $('#custom-cdn .cdn-popup-loading').html() + '</div>';

        WPCSwal.fire({
            title: '',
            html: popupHtml,
            width: 600,
            showCloseButton: true,
            showCancelButton: false,
            showConfirmButton: false,
            allowOutsideClick: false,
            customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup', popup: 'popup-per-page-settings', content: 'popup-per-page-settings',
            },
            onOpen: function () {

                var popup = $('.swal2-container .ajax-settings-popup');
                $('.cdn-popup-loading', popup).show();

                $.post(wpc_ajaxVar.ajaxurl, {
                    action: 'wps_ic_get_per_page_settings_html', id: ID, nonce: wpc_ajaxVar.nonce
                }, function (response) {
                    popup.html(response.data.html);
                    savePerPageExcludes(popup, ID);
                });

            },
            onClose: function () {

            }
        });

        return false;
    });

    function savePerPageExcludes(popup, ID) {
        var save = $('.btn-save', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var form = $('.wpc-save-popup-data', popup);


        $(save).on('click', function (e) {
            e.preventDefault();
            $(content).hide();
            $(loading).show();

            var skip_lazy = $('.per_page_lazy_skip', popup).val();
            var purge_on_new_post = $('.wps-purge-on-new-post', popup).is(':checked');

            $.post(wpc_ajaxVar.ajaxurl, {
                action: 'wps_ic_save_per_page_settings',
                nonce: wpc_ajaxVar.nonce,
                id: ID,
                skip_lazy: skip_lazy,
                purge_on_new_post: purge_on_new_post,
            }, function (response) {
                if (response.success) {
                    updatePosts(selectedTypes, currentPage);
                    WPCSwal.close();
                }
            });

            return false;
        });
    }


    $('.wpc-pull-stats').on('click', function (e) {
        e.preventDefault();
        let button = $(this);
        let state = $(button).html();

        $(button).html(wpc_ajaxVar.updating || 'Updating...');

        $.post(ajaxurl, {
            action: 'wps_ic_pull_stats', wps_ic_nonce: wpc_ajaxVar.nonce
        }, function (response) {
            $(button).html(state);
        });

        return false;
    });


    var links = $('.ajax-run-critical'); //We can add data-status to links to know which ones need to be run?
    var processed_links = 0;
    var process_all = 0;

    const range = document.getElementById('optimizationLevel');
    const setValue = () => {

        $('.wpc-slider-text>div[data-value="' + range.value + '"]').trigger('click');

        const newValue = Number((range.value - range.min) * 100 / (range.max - range.min)),
            newPosition = 16 - (newValue * 0.32);
        document.documentElement.style.setProperty("--range-progress", `calc(${newValue}% + (${newPosition}px))`);
    };

    function rangeSlider() {
        const newValue = Number((range.value - range.min) * 100 / (range.max - range.min)),
            newPosition = 16 - (newValue * 0.32);
        document.documentElement.style.setProperty("--range-progress", `calc(${newValue}% + (${newPosition}px))`);
    }

    if (range) {
        rangeSlider();


        document.addEventListener("DOMContentLoaded", setValue);
        //rangeImg.addEventListener('input', setValueImg);
        range.addEventListener('input', setValue);
    }

    function process_next_link() {
        links[processed_links].click();
        if (processed_links < links.length - 1) {
            processed_links = processed_links + 1;
        } else {
            process_all = 0;
        }
    }

    $('.ajax-run-critical-all').on('click', function (e) {
        e.preventDefault();
        process_all = 1;
        process_next_link();
    });

    $('.ajax-run-critical').on('click', function (e) {
        e.preventDefault();
        var pageID = $(this).data('page-id');

        var link = this;
        var status = $('#status_' + pageID);
        var assets_count_img = $('#assets_img_' + pageID);
        var assets_count_css = $('#assets_css_' + pageID);
        var assets_count_js = $('#assets_js_' + pageID);
        link.text = 'In Progress';
        $.post(ajaxurl, {
            action: 'wps_ic_critical_get_assets', pageID: pageID, nonce: wpc_ajaxVar.nonce
        }, function (response) {
            var files = JSON.parse(response.data);

            assets_count_img.html(files.img);
            assets_count_css.html(files.css);
            assets_count_js.html(files.js);

            $.post(ajaxurl, {
                action: 'wps_ic_critical_run', pageID: pageID, wps_ic_nonce: wpc_ajaxVar.nonce
            }, function (response) {
                if (response.success) {
                    link.text = 'Done';
                    status.html('<div class="wpc-critical-circle wpc-done"></div>');
                } else {
                    link.text = 'Error';
                    status.html('<div class="wpc-critical-circle wpc-error"></div>');
                    $(link).after('<div class="wpc-custom-tooltip"><i class="tooltip-icon" title="' + response.data.msg + '"></i></div>')
                }

                if (process_all === 1) {
                    process_next_link();
                }
            });

        });

        return false;
    });


    $('#optimizationLevel').on('change', function (e) {
        e.preventDefault();

        $('.action-buttons').fadeOut(500, function () {
            $('.save-button').fadeIn(500);
        });

        return false;
    });

    $('#optimizationLevel_img').on('change', function (e) {
        e.preventDefault();

        $('.action-buttons').fadeOut(500, function () {
            $('.save-button').fadeIn(500);
        });

        return false;
    });


    $('.wpc-ic-settings-v2-checkbox').on('change', function (e) {
        e.preventDefault();

        var parent = $(this).parents('.option-item');
        var beforeValue = $(this).attr('checked');
        var optionName = $(this).data('option-name');

        if (beforeValue == 'checked') {
            $(this).removeAttr('checked');
            $('.circle-check', parent).removeClass('active');
        } else {
            $(this).attr('checked', 'checked');
            $('.circle-check', parent).addClass('active');
        }

        var isNowChecked = $(this).attr('checked') === 'checked';

        // Show save pill
        $('.save-button').fadeIn(400);

        // Emit change event for AJAX save tracking
        $(document).trigger('wpc-setting-changed', [optionName, isNowChecked]);

        // Set preset to custom
        $('input[name="wpc_preset_mode"]').val('custom');
        $('a', '.wpc-dropdown-menu').removeClass('active');
        $('button', '.wpc-dropdown').html('Custom');
        $('a[data-value="custom"]', '.wpc-dropdown-menu').addClass('active');

        $.post(ajaxurl, {
            action: 'wpc_ic_ajax_set_preset', value: 'custom', wps_ic_nonce: wpc_ajaxVar.nonce
        });

        return false;
    });


    $('.wpc-ic-settings-v2-checkbox-ajax-save').on('change', function (e) {
        e.preventDefault();

        var parent = $(this).parents('.option-item');
        var beforeValue = $(this).attr('checked');
        var optionName = $(this).data('option-name');
        var newValue = 1;

        if (beforeValue == 'checked') {
            // It was already active, remove checked
            $(this).removeAttr('checked');
            newValue = 0;
        } else {
            // It's not active, activate
            $(this).attr('checked', 'checked');
        }

        $.post(ajaxurl, {
            action: 'wps_ic_ajax_v2_checkbox',
            optionName: optionName,
            optionValue: newValue,
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, function (response) {
            if (response.data.newValue == '1') {
                $('.circle-check', parent).addClass('active');
            } else {
                $('.circle-check', parent).removeClass('active');
            }
        });

        return false;
    });

    $(document).on('click', '.wps-ic-exclude-on-pages', function (e) {
        e.preventDefault();
        var ID = $(this).closest('.wpc-dropdown-row').attr('id');
        var setting = $(this).parent().parent().find('.exclude_dropdown_anchor').data('setting_name');

        var popupHtml = '<div id="' + ID + '" class="cdn-popup-inner ajax-settings-popup bottom-border exclude-list-popup">' + $('#custom-cdn .cdn-popup-loading').html() + '</div>';

        WPCSwal.fire({
            title: '',
            html: popupHtml,
            width: 650,
            showCloseButton: true,
            showCancelButton: false,
            showConfirmButton: false,
            allowOutsideClick: false,
            customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup',
                popup: 'popup-per-page-excludes',
                content: 'popup-per-page-excludes',
            },
            onOpen: function () {

                var popup = $('.swal2-container .ajax-settings-popup');
                $('.cdn-popup-loading', popup).show();

                $.post(wpc_ajaxVar.ajaxurl, {
                    action: 'wps_ic_get_page_excludes_popup_html', id: ID, setting: setting, nonce: wpc_ajaxVar.nonce
                }, function (response) {
                    popup.html(response.data.html);
                    savePageExcludePopup(popup, ID, setting);
                });

            },
            onClose: function () {

            }
        });

        return false;
    });

    function savePageExcludePopup(popup, ID, setting) {
        var save = $('.btn-save', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var form = $('.wpc-save-popup-data', popup);

        $(save).on('click', function (e) {
            e.preventDefault();
            $(content).hide();
            $(loading).show();

            var default_enabled = '0';
            var exclude_themes = '0';
            var exclude_plugins = '0';
            var exclude_wp = '0';
            var exclude_third = '0';

            if ($('.wps-exclude-third', popup).is(':checked')) {
                exclude_third = 1;
            }

            if ($('.wps-default-excludes', popup).is(':checked')) {
                default_enabled = 1;
            }

            if ($('.wps-exclude-themes', popup).is(':checked')) {
                exclude_themes = 1;
            }
            if ($('.wps-exclude-plugins', popup).is(':checked')) {
                exclude_plugins = 1;
            }
            if ($('.wps-exclude-wp', popup).is(':checked')) {
                exclude_wp = 1;
            }


            var excludes = $('.exclude-list-textarea-value', popup).val();

            $.post(wpc_ajaxVar.ajaxurl, {
                action: 'wps_ic_save_page_excludes_popup',
                nonce: wpc_ajaxVar.nonce,
                id: ID,
                setting: setting,
                excludes: excludes,
                default_enabled: default_enabled,
                exclude_themes: exclude_themes,
                exclude_plugins: exclude_plugins,
                exclude_wp: exclude_wp,
                exclude_third: exclude_third
            }, function (response) {
                if (response.success) {
                    WPCSwal.close();
                }
            });

            return false;
        });
    }

    //END PAGE EXCLUDES
    var globalSettings;
    var locked;

    function fetchPosts(selected, page, optimizedResponse) {
        var offset = (page - 1) * itemsPerPage;
        var pagesHtml = '';
        var selectedTypesOnThisCall = selected;
        var selectedStatusesOnThisCall = selectedStatuses;
        fetchRunning = true;
        $.ajax({
            url: ajaxurl, type: 'POST', data: {
                action: 'wps_ic_get_optimization_status_pages',
                post_type: selected,
                post_status: selectedStatuses,
                nonce: wpc_ajaxVar.nonce,
                page: page,
                offset: offset,
                search: searchTerm
            }, success: function (response) {
                globalSettings = response.data.global_settings;
                locked = response.data['locked'];
                $.each(response.data.pages, function (index, item) {
                    pagesHtml += `
                        <div class="wpc-dropdown-row" id="${item.id}">
                            <div class="wpc-dropdown-row-header">
                                                    <div class="wpc-dropdown-row-left-side">
                                                    ${createPreloadedIndicator(item)}
                                                        ${item.title}    
                                                        ${createPreloadStatus(item)}`;
                    if (local === false) {
                        /*
                        pagesHtml += `<div class="wpc-dropdown-row-arrow">
                                                            <i class="icon-down-open"></i>
                                                    </div>`;
                        pagesHtml += `${createRetestButton(item)}`;
                        */
                    }
                    pagesHtml += `</div>
                                                    <div class="wpc-dropdown-row-right-side">
                                                        <ul>`;
                    if (response.data.allow_live === '1') {
                        pagesHtml += `<li> ${createIndicator(item, 'cdn', globalSettings['live-cdn'], 'live-cdn-tooltip', locked['cdn'])}</li>`;
                    }
                    pagesHtml += `<li> ${createIndicator(item, 'adaptive', globalSettings['generate_adaptive'], 'generate-adaptive-tooltip', locked['adaptive'])}</li>
                                                         <li> ${createIndicator(item, 'advanced_cache', globalSettings.cache.advanced, 'advanced-cache-tooltip', locked['caching'])}</li>`;
                    if (local === false) {
                        pagesHtml += `<li> ${createIndicator(item, 'critical_css', globalSettings['critical']['css'], 'critical-css-tooltip', locked['css'])}</li>`;
                    }
                    pagesHtml += `<li>  ${createIndicator(item, 'delay_js_v2', globalSettings['delay-js-v2'], 'delay-js-tooltip', locked['delay-js'])}</li>
                                                        </ul>
                                                        <div class="per-page-settings-cog"></div>
                                                    </div>
                                                </div>`;


                    if (item.id === 'home' && (!!(item?.test?.desktop ?? false) || !!(item?.test?.mobile ?? false))) {
                        pagesHtml += `<div class="test-results wpc-dropdown-row-data" style="display:flex">`;
                    } else {
                        pagesHtml += ` <div class="test-results wpc-dropdown-row-data" style="display:flex;display:none">`;
                    }

                    if (local === false) {
                        //pagesHtml += insertResultsRow(item)
                    }
                    pagesHtml += '</div></div>';
                });

                $('#optimizationTable').html(pagesHtml);

                // Circle Progress Bar
                setTimeout(function () {
                    $('.circle-progress-bar').circleProgress({
                        animation: 'false',
                        size: 50,
                        startAngle: -Math.PI / 6 * 3,
                        lineCap: 'round',
                        thickness: '5',
                        fill: {
                            gradient: ["#61CB70", "#61CB70"], gradientAngle: Math.PI / 7
                        },
                        emptyFill: 'rgba(176,224,176,0.5)'
                    });
                }, 200); // 200ms timeout

                var totalPages = response.data.total_pages;
                $('#pagination').html(createPaginationHtml(currentPage, totalPages));

                initTooltipster();

                // $('.OptimizationPageTooltip').tooltipster({
                //     maxWidth: '300',
                //     delay: 50,
                //     theme: 'tooltipster-shadow',
                //     trigger:'click',
                //     position: 'top-left',
                // });

                if (selectedTypesOnThisCall !== selectedTypes || selectedStatusesOnThisCall !== selectedStatuses) {
                    //something was clikcked before call finished, call again
                    updateOptimizationStatus();
                }
                fetchRunning = false;
                if (searchPending === true) {
                    doSearch();
                    searchPending = false;
                }
            }
        });
    }

    function createPaginationHtml(currentPage, totalPages) {
        var paginationHtml = '';
        var maxPagesToShow = 5; // maximum number of pagination links to show
        var startPage, endPage;

        if (totalPages <= maxPagesToShow) {
            // total pages less than max so show all pages
            startPage = 1;
            endPage = totalPages;
        } else {
            // more pages than max so calculate start and end pages
            var maxPagesBeforeCurrentPage = Math.floor(maxPagesToShow / 2);
            var maxPagesAfterCurrentPage = Math.ceil(maxPagesToShow / 2) - 1;
            if (currentPage <= maxPagesBeforeCurrentPage) {
                // current page near the start
                startPage = 1;
                endPage = maxPagesToShow;
            } else if (currentPage + maxPagesAfterCurrentPage >= totalPages) {
                // current page near the end
                startPage = totalPages - maxPagesToShow + 1;
                endPage = totalPages;
            } else {
                // current page somewhere in the middle
                startPage = currentPage - maxPagesBeforeCurrentPage;
                endPage = currentPage + maxPagesAfterCurrentPage;
            }
        }

        // Add 'First' and 'Previous' buttons
        if (currentPage > 1) {
            paginationHtml += '<a href="#" class="optimization-status-pagination-link" data-page="1">First</a>';
            paginationHtml += '<a href="#" class="optimization-status-pagination-link" data-page="' + (currentPage - 1) + '">Previous</a>';
        }

        if (startPage > 1) {
            paginationHtml += ' ... '
        }

        for (var i = startPage; i <= endPage; i++) {

            var activeClass = i === currentPage ? 'active' : '';
            paginationHtml += '<a href="#" class="optimization-status-pagination-link ' + activeClass + '" data-page="' + i + '">' + i + '</a>';

        }

        if (endPage < totalPages) {
            paginationHtml += ' ... '
        }

        // Add 'Next' and 'Last' buttons
        if (currentPage < totalPages) {
            paginationHtml += '<a href="#" class="optimization-status-pagination-link" data-page="' + (currentPage + 1) + '">Next</a>';
            paginationHtml += '<a href="#" class="optimization-status-pagination-link" data-page="' + totalPages + '">Last</a>';
        }

        return paginationHtml;
    }

    function insertResultsRow(item) {
        var html = '';

        // console.log(response);
// Determine if a test has been run
        var testRun = item.test && (item.test.desktop || item.test.mobile);

// Retest Button and Results
        if (testRun && item.running !== '1' && (item.cacheGenerated === '1' && item.critGenerated === '1')) {
            //html += `<button class="wpc-run-test wpc-test-redo" data-retest="true">Retest</button>`;
            html += displayResults(item.test);
        } else if (testRun && item.runningOther === '1' && (item.cacheGenerated === '1' && item.critGenerated === '1')) {
            html += displayResults(item.test);
        } else {
            //html += '<button class="wpc-run-test wpc-test-redo" style="display: none;" data-retest="true">Retest</button>';
        }

// Test is Running
        var isRunningDisplay = ((item.running === '1' || item.runningOther === '1') && !testRun) ? 'block' : 'none';
        html += `<div class="test-is-running" style="display: ${isRunningDisplay};"><p>We are running optimizations, please wait...</p></div>`;

        // if (response.data && response.data.optimizationStatus && response.data.optimizationStatus.status) {
        //     html += `<div class="current-status" style="display: ${isRunningDisplay};">
        //         <p>Current Status: ${response.data.optimizationStatus.status}</p>
        //      </div>`;
        // }


// Test Not Run - Optimize and Run Performance Test button
        if ((item.running === '0' && item.runningOther === '0') && (item.cacheGenerated !== '1' || item.critGenerated !== '1')) {
            html += '<div class="test-not-runned"><p>Compare page performance before and after optimization</p><button class="wpc-run-test" data-optimize="1">Optimize and Run Performance Test</button></div>';
        } else {
            html += '<div class="test-not-runned" style="display: none;"><p>Compare page performance before and after optimization</p><button class="wpc-run-test" data-optimize="1">Optimize and Run Performance Test</button></div>';
        }

// Test Not Run - Run Performance Test button
        if (!testRun && (item.running === '0' && item.runningOther === '0') && (item.cacheGenerated === '1' && item.critGenerated === '1')) {
            html += '<div class="test-not-runned"><p>Compare page performance before and after optimization</p><button class="wpc-run-test">Run Performance Test</button></div>';
        } else {
            html += '<div class="test-not-runned" style="display: none;"><p>Compare page performance before and after optimization</p><button class="wpc-run-test">Run Performance Test</button></div>';
        }

        return html;


    }


    function createRetestButton(item) {
        var testRun = item.test && (item.test.desktop || item.test.mobile);
        var html = '';
        if (testRun && item.running !== '1' && item.preloaded === '1' && item.runningOther !== '1') {
            html += `<button class="wpc-run-test wpc-test-redo" data-retest="true"></button>`;
        } else {
            html += `<button class="wpc-run-test wpc-test-redo" data-retest="true" style="display: none;"></button>`;
        }
        return html;
    }

    function createPreloadStatus(item) {
        /*
        if (Array.isArray(item.errors) && item.errors[0].length === 3) {
            return `<div class="wpc-page-status-gray OptimizationErrorTooltip" title="wpc-tooltip-error" data-code="`+item.errors[0]+`" data-text="`+httpErrorCodes[item.errors[0]]+`">
               <i class="icon-gray"></i>
               Skipped
               </div>`;
        }
        */
        if (typeof item.errors === 'object' && item.errors !== null && 'skip' in item.errors) {
            return `<div class="wpc-page-status-gray">
                <i class="icon-gray"></i>
                ` + item.errors.skip + `: ` + httpErrorCodes[item.errors.skip] + `
                </div>`;
        }

        //if (item.preloaded === '1') {
        if (true) {
            return `<div class="wpc-page-status">
                <i class="wpc-icon-check"></i>
                Optimized
  <script>
    (function() {
      window.popped = window.popped || [];
      var parentId = '` + item.id + `';
      var parentDiv = document.getElementById(parentId);
      if (parentDiv) {
        var newDiv = parentDiv.querySelector('.wpc-page-status');
        if (newDiv && newDiv.classList.contains('wpc-page-status')) {
          triggerPopEffect(newDiv, parentId);
        }
      }
    })();
  </script>
                </div>`;
        } else {
            return '<div class="wpc-page-status" style="display:none"></div>';
            // return `<div class="wpc-page-status pending">
            //     <i class="icon-check"></i>
            //     Pending Optimizations
            //     </div>`;
        }
    }

    function createPreloadedIndicator(pageItem) {
        var preloaded = pageItem['preloaded'];
        if (lastResponse.data.optimizationStatus) {

            if (Number.isInteger(lastResponse.data.optimizationStatus.id)) {
                lastResponse.data.optimizationStatus.id = lastResponse.data.optimizationStatus.id.toString();
            }

            if (Number.isInteger(pageItem.id)) {
                pageItem.id = pageItem.id.toString();
            }

            if (lastResponse.data.optimizationStatus.id === pageItem.id) {
                return '<div class="wpc-circle-status"><div class="lds-ring"><div></div><div></div><div></div><div></div></div></div>';
            }
        }
        //if (preloaded === '1') {
        if (true) {
            return '<div class="wpc-circle-status"><div class="wpc-critical-circle wpc-done"></div></div>';
        }
        return '<div class="wpc-circle-status"><div class="wpc-critical-circle"></div></div>';
    }

    function createIndicator(pageItem, settingName, globalSetting, tooltipText, locked = false) {
        var pageSetting = pageItem[settingName];

        if (locked) {
            return `<div class="${settingName}"><div class="page_off exclude_dropdown_anchor LockedTooltip" data-setting_state="0" data-pop-text="<i class='wpc-sparkle-icon'></i> Optimize Plan Required"></div></div>`;
        }

        var classStatus = getClassStatus(pageSetting, globalSetting, pageItem, settingName);

        if (typeof pageSetting == 'undefined' || pageSetting === 'undefined') {
            // Check global first
            if (globalSetting == '0') {
                pageSetting = '0';
            } else {
                pageSetting = '1';
            }
        }

        var disabled = '';
        if (pageItem.running === '1') {
            disabled = 'wpc-disable-clickable'
        }

        return `<div class="${settingName}"><div class="${classStatus} exclude_dropdown_anchor OptimizationPageTooltip ${disabled}" data-setting_name="${settingName}" title="${tooltipText}" data-setting_state="${pageSetting}"></div></div>`;
    }

    function getClassStatus(pageSetting, globalSetting, pageItem, settingName) {
        if (settingName === 'advanced_cache') {
            if (pageSetting == '1' && pageItem.cacheGenerated == '0') {
                return 'page_not_generated';
            }
            if (globalSetting == '1' && typeof pageSetting === 'undefined' && pageItem.cacheGenerated == '0') {
                return 'page_not_generated';
            }
        }
        if (settingName === 'critical_css') {
            if (pageItem.critGenerating == '1') {
                return 'page_not_generated page_generating';
            }
            if (pageSetting == '1' && pageItem.critGenerated == '0') {
                return 'page_not_generated';
            }
            if (globalSetting == '1' && typeof pageSetting === 'undefined' && pageItem.critGenerated == '0') {
                return 'page_not_generated';
            }
        }
        if (pageSetting == '0') return 'page_excluded';
        if (globalSetting == '0' && typeof pageSetting === 'undefined') return 'page_off';
        return 'page_ready';
    }


    function updatePosts(selectedTypes, page) {
        var offset = (page - 1) * itemsPerPage;
        var selectedTypesOnThisCall = selectedTypes;
        var selectedStatusesOnThisCall = selectedStatuses;
        fetchRunning = true;

        $.ajax({
            url: ajaxurl, type: 'POST', data: {
                action: 'wps_ic_get_optimization_status_pages',
                post_type: selectedTypes,
                post_status: selectedStatuses,
                nonce: wpc_ajaxVar.nonce,
                page: page,
                offset: offset,
                search: searchTerm
            }, success: function (response) {
                globalSettings = response.data.global_settings;
                locked = response.data['locked'];
                $.each(response.data.pages, function (index, newItem) {
                    var $row = $('#optimizationTable').find(`#${newItem.id}`);

                    var newPreloadedHtml = createPreloadedIndicator(newItem);
                    var $preloadedElement = $row.find('.wpc-dropdown-row-left-side .wpc-circle-status');
                    if ($preloadedElement.html() !== newPreloadedHtml) {
                        $preloadedElement.replaceWith(newPreloadedHtml);
                    }

                    var newPreloadedStatus = createPreloadStatus(newItem);
                    var $preloadedStatus = $row.find('.wpc-dropdown-row-left-side .wpc-page-status');
                    if ($preloadedStatus.html() !== newPreloadedStatus) {
                        $preloadedStatus.replaceWith(newPreloadedStatus);
                    }

                    var newRetestButton = createRetestButton(newItem);
                    var $retestButton = $row.find('.wpc-test-redo');
                    if ($retestButton.html() !== newRetestButton) {
                        $retestButton.replaceWith(newRetestButton);
                    }


                    var newStatusHtml = '';
                    if (response.data.allow_live === '1') {
                        newStatusHtml += `<li> ${createIndicator(newItem, 'cdn', globalSettings['live-cdn'], 'live-cdn-tooltip', locked['csn'])}</li>`;
                    }
                    newStatusHtml += `
                    <li> ${createIndicator(newItem, 'adaptive', globalSettings['generate_adaptive'], 'generate-adaptive-tooltip', locked['adaptive'])}</li>
                    <li> ${createIndicator(newItem, 'advanced_cache', globalSettings.cache.advanced, 'advanced-cache-tooltip', locked['caching'])}</li>`;
                    if (local === false) {
                        newStatusHtml += `<li> ${createIndicator(newItem, 'critical_css', globalSettings['critical']['css'], 'critical-css-tooltip', locked['css'])}</li>`;
                    }
                    newStatusHtml += `
                    <li> ${createIndicator(newItem, 'delay_js_v2', globalSettings['delay-js-v2'], 'delay-js-tooltip', locked['delay-js'])}</li>
                `;
                    var $statusElement = $row.find('.wpc-dropdown-row-right-side ul');
                    if ($statusElement.html().trim() !== newStatusHtml.trim()) {
                        $statusElement.html(newStatusHtml);
                    }

                    var newTestResultsHtml = insertResultsRow(newItem);
                    var $testResultsElement = $row.find('.test-results');
                    var shouldUpdate = $testResultsElement.find('.test-is-running').css('display') !== $('<div>').html(newTestResultsHtml).find('.test-is-running').css('display') || $testResultsElement.find('.test-not-runned').css('display') !== $('<div>').html(newTestResultsHtml).find('.test-not-runned').css('display') || $testResultsElement.find('.wpc-test-results').css('display') !== $('<div>').html(newTestResultsHtml).find('.wpc-test-results').css('display') || $testResultsElement.find('.wpc-test-redo').css('display') !== $('<div>').html(newTestResultsHtml).find('.wpc-test-redo').css('display');

                    if (shouldUpdate && local === false) {
                        $testResultsElement.html(newTestResultsHtml);

                        // Circle Progress Bar
                        setTimeout(function () {
                            $('.circle-progress-bar', $testResultsElement).circleProgress({
                                size: 50, startAngle: -Math.PI / 6 * 3, lineCap: 'round', thickness: '5', fill: {
                                    gradient: ["#61CB70", "#61CB70"], gradientAngle: Math.PI / 7
                                }, emptyFill: 'rgba(176,224,176,0.5)'
                            });
                        }, 200); // 200ms timeout
                    }
                });
                initTooltipster()

                var totalPages = response.data.total_pages;
                var paginationHtml = createPaginationHtml(currentPage, totalPages);


                var $paginationElement = $('#pagination');
                if ($paginationElement.html().trim() !== paginationHtml.trim()) {
                    $paginationElement.html(paginationHtml);
                }

                if (selectedTypesOnThisCall !== selectedTypes || selectedStatusesOnThisCall !== selectedStatuses) {
                    //something was clikcked before call finished, call again
                    updateOptimizationStatus();
                }
                fetchRunning = false;
                if (searchPending === true) {
                    doSearch();
                    searchPending = false;
                }
            }

        });
    }


    var dropdownTimeout;
    var currentDropdownAnchor = null;

    $('#optimizationTable').on('click', '.exclude_dropdown_anchor', function (e) {
        e.stopPropagation(); // Prevent event bubbling

        if ($(this).hasClass('LockedTooltip')) {
            return;
        }

        // Check if the dropdown is already open for this anchor
        if (currentDropdownAnchor && currentDropdownAnchor.is($(this)) && $(this).next('.dropdown-menu').length) {
            return;
        }

        // Remove any existing dropdowns and clear timeouts
        $('.dropdown-menu').slideUp(200);
        clearTimeout(dropdownTimeout);

        // Set current anchor
        currentDropdownAnchor = $(this);

        // Create dropdown menu HTML
        var dropdownHtml = `
            <div class="dropdown-menu" style="display: none;">`;

        if ($(this).data('setting_name') !== 'advanced_cache') {
            dropdownHtml += `<a href="#" class="dropdown-item" data-action="force_on"><span class="icon force-on-icon"></span>Force On</a>`;
        }

        dropdownHtml += `<a href="#" class="dropdown-item" data-action="force_off"><span class="icon force-off-icon"></span>Force Off</a>
                <a href="#" class="dropdown-item" data-action="global"><span class="icon global-icon"></span>Global</a>
                <a href="#" class="dropdown-item wps-ic-exclude-on-pages" data-action="excludes"><span class="icon exclude-icon"></span>Excludes</a>`;

        if ($(this).data('setting_name') === 'advanced_cache') {
            dropdownHtml += `<a href="#" class="dropdown-item" data-action="purge"><span class="icon purge-icon"></span>Purge</a>`;
        }

        if ($(this).data('setting_name') === 'critical_css') {
            if ($(this).hasClass('page_generating')) {
                dropdownHtml += `<a href="#" class="dropdown-item" style="pointer-events: none;" data-action="generate"><span class="icon purge-icon"></span>Generating...</a>`;
            } else if ($(this).data('setting_name') === 'critical_css' && $(this).hasClass('page_not_generated')) {
                dropdownHtml += `<a href="#" class="dropdown-item" data-action="generate"><span class="icon purge-icon"></span>Generate</a>`;
            } else {
                dropdownHtml += `<a href="#" class="dropdown-item" data-action="purge"><span class="icon purge-icon"></span>Purge</a>`;
            }
        }

        dropdownHtml += `</div>`;

        // Append and position the dropdown
        $(this).after(dropdownHtml);
        var dropdownMenu = $(this).next('.dropdown-menu');
        dropdownMenu.css({
            top: $(this).position().top + $(this).outerHeight(), left: $(this).position().left
        }).slideDown(200);

        // Setup mouseleave event for the anchor and dropdown
        setupDropdownEvents($(this), dropdownMenu);
    });

    // Close the dropdown when clicking outside
    $(document).on('click', function () {
        $('.dropdown-menu').slideUp(200);
        currentDropdownAnchor = null;
    });

    function setupDropdownEvents(anchor, dropdownMenu) {
        anchor.on('mouseleave', function () {
            dropdownTimeout = setTimeout(function () {
                dropdownMenu.slideUp(200);
                currentDropdownAnchor = null;
            }, 500); // Delay for moving between anchor and dropdown
        });

        dropdownMenu.on('mouseenter', function () {
            clearTimeout(dropdownTimeout);
        }).on('mouseleave', function () {
            dropdownTimeout = setTimeout(function () {
                dropdownMenu.slideUp(200);
                currentDropdownAnchor = null;
            }, 500); // Delay before hiding the dropdown
        });
    }

    // Prevent the dropdown from closing when clicking on it
    $(document).on('click', '.dropdown-menu', function (e) {
        e.stopPropagation();
    });

    // Handle dropdown item clicks for AJAX
    $(document).on('click', '.dropdown-item', function (e) {
        e.preventDefault();
        var action = $(this).data('action');
        var ID = $(this).closest('.wpc-dropdown-row').attr('id');
        var settingName = $(this).parent().parent().find('.exclude_dropdown_anchor').data('setting_name');

        if (action != 'excludes') {
            // Perform AJAX call
            $.ajax({
                url: ajaxurl, type: 'POST', data: {
                    action: 'wps_ic_save_optimization_status',
                    id: ID,
                    setting_name: settingName,
                    setting_action: action,
                    nonce: wpc_ajaxVar.nonce,
                }, success: function (response) {
                    updateOptimizationStatus();
                    updatePosts(selectedTypes, currentPage);
                }, error: function (xhr, status, error) {
                    // Handle error
                }
            });
        }
    });

    $('#optimizationTable').on('click', '.wpc-run-test', function (e) {
        e.preventDefault();
        var ID = $(this).closest('.wpc-dropdown-row').attr('id');
        var testResultsRow = $(this).closest('.wpc-dropdown-row').find('.test-results');
        var retest = $(this).data('retest');
        var optimize = $(this).data('optimize');

        $('.wpc-test-results', testResultsRow).remove();
        $('.test-is-running', testResultsRow).show();
        $('.test-not-runned', testResultsRow).hide();
        $('.wpc-test-redo').hide();

        if (optimize === 1) {
            $.ajax({
                url: ajaxurl, type: 'POST', data: {
                    action: 'wps_ic_run_single_optimization', id: ID, nonce: wpc_ajaxVar.nonce,
                }, success: function (response) {
                    updateOptimizationStatus();
                    optimizationCheckInterval = setInterval(updateOptimizationStatus, 5000);
                }, error: function (response) {
                    $('.test-is-running', testResultsRow).hide();
                    $('.test-not-runned', testResultsRow).show();
                }
            });
        } else {
            setTimeout(function () {
                updatePosts(selectedTypes, currentPage);
            }, 1000)
            $.ajax({
                url: ajaxurl, type: 'POST', data: {
                    action: 'wps_ic_run_tests', id: ID, retest: retest, nonce: wpc_ajaxVar.nonce,
                }, success: function (response) {
                    clearInterval(optimizationCheckInterval);
                    updateOptimizationStatus();
                    updatePosts(selectedTypes, currentPage);
                    //var htmlContent = displayResults(response.data)
                    //testResultsRow.html(htmlContent);
                    // Circle Progress Bar
                    setTimeout(function () {
                        $('.circle-progress-bar', testResultsRow).circleProgress({
                            animation: 'false',
                            size: 50,
                            startAngle: -Math.PI / 6 * 3,
                            lineCap: 'round',
                            thickness: '5',
                            fill: {
                                gradient: ["#61CB70", "#61CB70", "#b0e0b0"], gradientAngle: Math.PI / 7
                            },
                            emptyFill: 'rgba(176,224,176,0.8)'
                        });
                    }, 200); // 200ms timeout
                }, error: function (xhr, status, error) {
                    testResultsRow.html('<p>' + (wpc_ajaxVar.errorLoadingResults || 'Error loading test results.') + '</p>');
                }

            });
        }
    });

    function calculateGains(beforeOptimization, afterOptimization, metricType) {
        if (afterOptimization < beforeOptimization) {
            switch (metricType) {
                case 'pageSize':
                    let percentageReduction = (1 - (afterOptimization / beforeOptimization)) * 100;
                    return percentageReduction.toFixed(1);
                case 'requests':
                    let difference = beforeOptimization - afterOptimization;
                    return difference;
                default: // 'ttfb' and other cases
                    let result = beforeOptimization / afterOptimization;
                    return (result < 10) ? result.toFixed(1) : Math.round(result);
            }
        } else {
            return false;
        }
    }

    function badgeMarkup(gain, metricType) {
        let text;
        switch (metricType) {
            case 'ttfb':
                text = `faster`;
                return gain ? `<span class="wpc-infobox-badge"><span class="icon-arrow-improvement wpc-faster"></span>${gain}x ${text}</span>` : '';
            case 'pageSize':
                text = `smaller`;
                return gain ? `<span class="wpc-infobox-badge"><span class="icon-arrow-improvement wpc-smaller"></span>${gain}% ${text}</span>` : '';
            case 'requests':
                text = `less`;
                return gain ? `<span class="wpc-infobox-badge"><span class="icon-arrow-improvement wpc-less"></span>${gain} ${text}</span>` : '';
            default:
                return '';
        }
    }

    function displayResults(results) {
        var ttfb = calculateGains(results.desktop.before.ttfb, results.desktop.after.ttfb, 'ttfb');
        var pageSize = calculateGains(results.desktop.before.pageSize, results.desktop.after.pageSize, 'pageSize');
        var requests = calculateGains(results.desktop.before.requests, results.desktop.after.requests, 'requests');


        return `
        <div class='wpc-test-results' style="display: flex; width: 100%;">
            <div class="wpc-dropdown-box-bigger">
                <div class="wpc-dropdown-infobox">
                    <div class="wpc-dropdown-infobox-header">
                        <h5>Server Response (TTFB)</h5>
                        ${badgeMarkup(ttfb, 'ttfb')}
                    </div>
                    <div class="wpc-dropdown-infobox-data">
                        <div class="wpc-dropdown-progress-bar">
                            <div class="wpc-dropdown-progress-bar-green">
                                <span class="circle-container">
                                    <span class="circle"></span>
                                    <span class="text"><span class="icon-progress-bar-after"></span>${formatTime(results.desktop.after.ttfb)}</span>
                                </span>
                            </div>
                            <div class="wpc-dropdown-progress-bar-orange">
                            </div>
                            <div class="wpc-dropdown-progress-bar-red">
                                <span class="circle-container">
                                    <span class="circle"></span>
                                    <span class="text"><span class="icon-progress-bar-before"></span>${formatTime(results.desktop.before.ttfb)}</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="wpc-dropdown-box-bigger">
                <div class="wpc-dropdown-infobox">
                    <div class="wpc-dropdown-infobox-header">
                        <h5>Page Size</h5>
                        ${badgeMarkup(pageSize, 'pageSize')}
                    </div>
                    <div class="wpc-dropdown-infobox-data">
                        <div class="wpc-dropdown-progress-bar">
                            <div class="wpc-dropdown-progress-bar-green">
                                <span class="circle-container">
                                    <span class="circle"></span>
                                    <span class="text"><span class="icon-progress-bar-after"></span>${formatBytes(results.desktop.after.pageSize)}</span>
                                </span>
                            </div>
                            <div class="wpc-dropdown-progress-bar-orange">
                            </div>
                            <div class="wpc-dropdown-progress-bar-red">
                                <span class="circle-container">
                                    <span class="circle"></span>
                                    <span class="text"><span class="icon-progress-bar-before"></span>${formatBytes(results.desktop.before.pageSize)}</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="wpc-dropdown-box-bigger">
                <div class="wpc-dropdown-infobox">
                    <div class="wpc-dropdown-infobox-header">
                        <h5>Requests</h5>
                        ${badgeMarkup(requests, 'requests')}
                    </div>
                    <div class="wpc-dropdown-infobox-data">
                        <div class="wpc-dropdown-progress-bar">
                            <div class="wpc-dropdown-progress-bar-green">
                                <span class="circle-container">
                                    <span class="circle"></span>
                                    <span class="text"><span class="icon-progress-bar-after"></span>${results.desktop.after.requests}</span>
                                </span>
                            </div>
                            <div class="wpc-dropdown-progress-bar-orange">
                            </div>
                            <div class="wpc-dropdown-progress-bar-red">
                                <span class="circle-container">
                                    <span class="circle"></span>
                                    <span class="text"><span class="icon-progress-bar-before"></span>${results.desktop.before.requests}</span>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        
        <div class="wpc-dropdown-box-smaller">
            <div class="progress-bar-container">
              
                    <span class="wpc-mobile-icon"></span>
                
                <div class="page-stats-circle">
                    <div class="circle-progress-bar" data-value="${results.mobile.after.performanceScore / 100}"></div>
                    <div class="stats-circle-text">
                        <h5>${results.mobile.after.performanceScore}</h5>
                    </div>
                </div>
            </div>
            <div class="progress-bar-container">
            
                    <span class="wpc-desktop-icon"></span>
           
                <div class="page-stats-circle">
                    <div class="circle-progress-bar" data-value="${results.desktop.after.performanceScore / 100}"></div>
                    <div class="stats-circle-text">
                       <h5>${results.desktop.after.performanceScore}</h5>
                    </div>
                </div>
            </div>
        </div>
        </div>
    `;
    }


    $(document).on('click', '.optimization-status-pagination-link', function (e) {
        e.preventDefault();
        currentPage = $(this).data('page'); // Keep track of the current page
        fetchPosts(selectedTypes, currentPage);
    });

    $('#optimization-status-posts-filter').on('change', function () {
        currentPage = 1; // Reset to the first page when the filter changes
        fetchPosts($(this).val(), currentPage);
    });

    var messageDisplayInterval;
    var currentMessageIndex = 0;
    var lastOptimizationId;
    var optimizationCheckInterval;
    var lastResponse;
    var isPreparing = true;
    updateOptimizationStatus();

    function updateMessageCycle(response) {
        var statusMessages;
        if (local === true) {
            statusMessages = [wpc_ajaxVar.statusScanning, wpc_ajaxVar.statusWarmingCache, wpc_ajaxVar.statusFinalizing, wpc_ajaxVar.statusFinalizing];
        } else {
            statusMessages = [wpc_ajaxVar.statusScanning, wpc_ajaxVar.statusVerifyingImages, wpc_ajaxVar.statusAdaptive, wpc_ajaxVar.statusWarmingCache, wpc_ajaxVar.statusMinifyHtml, wpc_ajaxVar.statusServerResponse, wpc_ajaxVar.statusOptimizeCss, wpc_ajaxVar.statusOptimizeJs, wpc_ajaxVar.statusFinalizing, wpc_ajaxVar.statusFinalizing];
        }

        var newOptimizationId = response.data.optimizationStatus.id;
        var newPageTitle = response.data.optimizationStatus.pageTitle;
        var pageTitleContainer = $('.optimizations-progress-bar-text .wpc-page-title');
        var messageContainer = $('.optimizations-progress-bar-text .wpc-status-message');

        if (lastOptimizationId !== newOptimizationId) {
            if (messageDisplayInterval) {
                clearInterval(messageDisplayInterval);
            }

            currentMessageIndex = 0;
            lastOptimizationId = newOptimizationId;

            // Update and animate the page title only if not preparing
            if (!isPreparing) {
                pageTitleContainer.addClass('wpc-message-exit');
                setTimeout(function () {
                    pageTitleContainer.html(newPageTitle + ': ').removeClass('wpc-message-exit').addClass('wpc-message-enter');
                    setTimeout(function () {
                        pageTitleContainer.removeClass('wpc-message-enter');
                    }, 500);
                }, 500);
            }
        }

        isPreparing = response.data.optimizationStatus.status === 'started';

        function displayNextMessage() {
            if (isPreparing) {
                pageTitleContainer.html('');
                messageContainer.html(wpc_ajaxVar.preparingOptimization || 'Preparing optimization...');
            } else {
                if (pageTitleContainer.text() !== newPageTitle + ': ' && !isPreparing) {
                    pageTitleContainer.addClass('wpc-message-exit');
                    setTimeout(function () {
                        pageTitleContainer.html(newPageTitle + ': ').removeClass('wpc-message-exit').addClass('wpc-message-enter');
                        setTimeout(function () {
                            pageTitleContainer.removeClass('wpc-message-enter');
                        }, 500);
                    }, 500);
                }

                messageContainer.addClass('wpc-message-exit');

                setTimeout(function () {
                    var message = statusMessages[currentMessageIndex];
                    messageContainer.html(message).removeClass('wpc-message-exit').addClass('wpc-message-enter');

                    setTimeout(function () {
                        messageContainer.removeClass('wpc-message-enter');
                    }, 500);

                    if (currentMessageIndex < statusMessages.length - 1) {
                        currentMessageIndex++;
                    }
                }, 500);
            }
        }

        var timeout = local === true ? 2000 : 2000;

        if (lastOptimizationId !== newOptimizationId || currentMessageIndex === 0) {
            displayNextMessage();
            messageDisplayInterval = setInterval(function () {
                if (currentMessageIndex < statusMessages.length - 1) {
                    displayNextMessage();
                }
            }, timeout);
        }
    }


    var local = false
    var callInProgress = false

    function updateOptimizationStatus() {
        // Make an AJAX call to check the optimization status
        if (!callInProgress) {
            callInProgress = true;
            if (typeof selectedOptimizes === "undefined" || selectedOptimizes === null) {
                //We are not on our settings page
                selectedOptimizes = false;
            } else if (selectedOptimizes.length === 0) {
                selectedOptimizes = 'do-not-optimize';
            }

            var selectedOptimizesOnThisCall = selectedOptimizes;
            $.ajax({
                url: ajaxurl, type: 'POST', data: {
                    action: 'wps_ic_check_optimization_status', optimize: selectedOptimizes, nonce: wpc_ajaxVar.nonce,
                }, success: function (response) {

                    if (response.success == false) {
                        return true;
                    }

                    callInProgress = false;
                    if (response.data.connectivity === 'failed') {
                        local = true;
                    }

                    var optimized;
                    var total;
                    if ($('#optimizationTable').find('div').length === 0 && $('.wpc-optimization-status').find('div').length > 0) {
                        //if table not populatd - first load
                        fetchPosts(selectedTypes, currentPage, response);
                        //Optimization bar update
                        optimized = response.data.optimized || 0;
                        total = response.data.total || 0;
                        $('.optimized-pages-text').each(function () {
                            var $this = $(this);
                            var countTo = total;
                            $this.text('0'); // Ensure it starts from 0
                            $({countNum: $this.text()}).animate({
                                countNum: countTo
                            }, {
                                duration: 3000, easing: 'swing', step: function () {
                                    $this.text(Math.floor(this.countNum).toLocaleString());
                                }, complete: function () {
                                    $this.text(this.countNum.toLocaleString());
                                }
                            });
                        });
                        $('.optimized-pages-bottom-text').text('Pages Optimized');

                    } else if (lastResponse && lastResponse.data && lastResponse.data.optimizationStatus.id !== response.data.optimizationStatus.id) {
                        //optimizatin running and another page was optimized
                        updatePosts(selectedTypes, currentPage);

                        optimized = response.data.optimized || 0;
                        total = response.data.total || 0;
                        //$('.optimized-pages-text').text(optimized);
                        $('.optimized-pages-text').text(total);
                        $('.optimized-pages-bottom-text').text('Pages Optimized');
                        $('.wpc-smart-optimization-title').text('Smart Optimization in Progress...');
                    }

                    if (response.data.optimizationStatus) {
                        if (response.data.optimizationStatus.pageTitle && response.data.optimizationStatus.pageTitle.trim() !== '') {
                            $('.wpc-smart-optimization-text').html('We’re warming up the cache and running optimizations for <span style="font-weight: 600; color: #4C4C4C">' + limitStringLength(response.data.optimizationStatus.pageTitle, 35) + '</span>');
                        }
                        $('.wpc-page-optimizations-running').show();
                        $('.wpc-start-optimizations, .wpc-optimization-complete, .wpc-preparing-optimization, .wpc-optimization-locked').hide();
                        if (animationFrameId === null) {
                            startBarAnimation()
                        }
                        if (typeof optimizationCheckInterval === 'undefined') {
                            optimizationCheckInterval = setInterval(updateOptimizationStatus, 5000);
                        }
                    } else if ($('.wpc-optimization-status').find('div').length === 0) {
                        //Not our settings page
                    } else {

                        stopBarAnimation();
                        clearInterval(optimizationCheckInterval);
                        fetchPosts(selectedTypes, currentPage);
                        optimized = response.data.optimized || 0;
                        total = response.data.total || 0;
                        //$('.optimized-pages-text').text(optimized);
                        $('.optimized-pages-text').text(total);
                        $('.optimized-pages-bottom-text').text('Pages Optimized');
                        $('.wpc-smart-optimization-title').text('Smart Optimization + Performance');
                        $('.wpc-smart-optimization-text').text('No need to lift a finger, your website is intelligently optimized around the clock based on demand.');
                        $('.optimizations-progress-bar-text').hide();
                        $('.wpc-optimizer-running').hide();
                        if (response.data.optimized < response.data.total) {
                            $('.wpc-start-optimizations').show();
                            $('.wpc-optimization-complete').hide();
                        } else {
                            $('.wpc-optimization-complete').show();
                            $('.wpc-start-optimizations').hide();
                        }
                    }


                    lastResponse = response;
                    if (selectedOptimizesOnThisCall !== selectedOptimizes) {
                        //something was clikcked before call finished, call again
                        updateOptimizationStatus();
                    }


                }, error: function (error) {
                    callInProgress = false;
                    console.error('Error:', error);
                }
            });
        }
    }

    // Start the heartbeat when the optimization button is clicked
    $('.wpc-optimization-page-button').on('click', '.wpc-start-optimizations', function () {
        // Set active tab
        $('a[data-tab="smart-optimization"]').trigger('click');

        //change button
        $('.wpc-start-optimizations').hide()
        $('.wpc-preparing-optimization').show()

        $.ajax({
            url: ajaxurl, type: 'POST', data: {
                action: 'wps_ic_start_optimizations', nonce: wpc_ajaxVar.nonce,
            }, success: function () {
                updateOptimizationStatus();
                optimizationCheckInterval = setInterval(updateOptimizationStatus, 5000);
            }
        });

    });

    $('.wpc-optimization-page-button').on('click', '.wpc-stop-page-optimizations', function () {
        $.ajax({
            url: ajaxurl, type: 'POST', data: {
                action: 'wps_ic_stop_optimizations', nonce: wpc_ajaxVar.nonce,
            }, success: function () {
                updateOptimizationStatus();
                stopBarAnimation();
            }
        });

    });

    function formatTime(timeInMs) {
        if (timeInMs < 1000) {
            return `${timeInMs.toFixed(0)}ms`;
        } else {
            // Convert to seconds and round to two decimal places
            let timeInSeconds = (timeInMs / 1000).toFixed(1);
            return `${timeInSeconds}s`;
        }
    }

    function calculateBarSize(before, after) {
        if (after >= before) {
            return "100%";
        } else {
            let percentage = (after / before) * 100;
            return `${percentage.toFixed(0)}%`;
        }
    }

    function formatBytes(bytes) {
        if (bytes < 1024) {
            return bytes + 'B'; // Bytes
        } else if (bytes < 1024 * 1024) {
            return (bytes / 1024).toFixed(1) + 'KB'; // Kilobytes
        } else {
            return (bytes / (1024 * 1024)).toFixed(1) + 'MB'; // Megabytes
        }
    }

    function limitStringLength(str, maxLength) {
        if (str.length > maxLength) {
            return str.substring(0, maxLength) + '...';
        }
        return str;
    }

    $('.test-api-button').on('click', function (e) {
        e.preventDefault();
        $.ajax({
            url: ajaxurl, // WordPress AJAX URL
            type: 'POST', data: {
                action: 'wps_ic_test_api_connectivity', nonce: wpc_ajaxVar.nonce,
            }, success: function (response) {
                // Building the HTML table for the first section
                let tableHtml = '<h3>Outbound tests</h3><table border="1" style="width:100%"><tr><th>Test Case</th><th>Result</th></tr>';
                let keys = Object.keys(response.data);

                for (let i = 0; i < keys.length - 1; i++) {
                    let key = keys[i];
                    let test = response.data[key];
                    let result = test.success ? 'Passed' : 'Failed';

                    tableHtml += `<tr><td>${key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</td><td>${result}</td></tr>`;
                }
                tableHtml += '</table>';

                // Building the HTML table for the final test section
                if (response.data.final_test) {
                    let finalTest = response.data.final_test;
                    tableHtml += '<h3>Inbound tests</h3><table border="1" style="width:100%"><tr><th>Test Case</th><th>Result</th></tr>';

                    for (let innerKey in finalTest.response.data) {
                        let innerResult = finalTest.response.data[innerKey];
                        let result;

                        if (innerResult.success) {
                            result = 'Passed';
                        } else {
                            let blob = new Blob([innerResult.response], {type: 'text/html'});
                            let url = URL.createObjectURL(blob);

                            result = `<a href="${url}" target="_blank">View Response</a>`;
                        }

                        tableHtml += `<tr><td>${innerKey.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase())}</td><td>${result}</td></tr>`;
                    }
                }
                tableHtml += '</table>';

                // Display the tables in a SweetAlert
                WPCSwal.fire({
                    title: 'API Test Results', html: tableHtml, width: '500px'
                });
            }, error: function (error) {
                // Display error in SweetAlert
                WPCSwal.fire({
                    title: 'Error!', text: 'An error occurred while fetching the data.', icon: 'error'
                });
            }
        });
    });

    var progressBar = $('#optimizations-progress-bar');
    var marginLeft = 0; // Margin left for moving the bar
    var movingRight = true; // Direction of movement
    var animationFrameId = null;

    function step() {
        if (movingRight) {
            marginLeft += 0.5; // Adjust speed as needed
            if (marginLeft >= 85) {
                marginLeft = 85;
                movingRight = false;
            }
        } else {
            marginLeft -= 0.5; // Adjust speed as needed
            if (marginLeft <= 0) {
                marginLeft = 0;
                movingRight = true;
            }
        }

        progressBar.css({
            'width': '15%', 'margin-left': marginLeft + '%'
        });
        animationFrameId = requestAnimationFrame(step);
    }

    function startBarAnimation() {
        $('.wpc-smart-monitor-img').addClass('wpc-smart-monitor-img-animated');
        $('.shimmer-container').show();
        $('.pulse-container').show();
        if (!animationFrameId) {
            progressBar.css('width', '5%');
            progressBar.css('margin-left', '0%');
            animationFrameId = requestAnimationFrame(step);
        }
    }

    function stopBarAnimation() {
        $('.wpc-smart-monitor-img').removeClass('wpc-smart-monitor-img-animated');
        $('.shimmer-container').hide();
        $('.pulse-container').hide();
        if (animationFrameId) {
            cancelAnimationFrame(animationFrameId);
            animationFrameId = null;
            progressBar.css('margin-left', '0%');
            progressBar.css('width', '100%');
        }
    }


    //DROPDOWN SELECTORS
    $('.selector-dropdown').on('click', '.dropdown-header', function (e) {
        e.stopPropagation(); // Prevent event bubbling
        var $dropdownMenu = $(this).next('.dropdown-menu');

        $('.selector-dropdown .dropdown-menu').not($dropdownMenu).slideUp(200);
        clearTimeout(dropdownTimeout);

        $dropdownMenu.slideToggle(200);
    });

    $('.selector-dropdown').on('click', '.dropdown-item', function (e) {
        e.stopPropagation();
        var $this = $(this);
        $this.toggleClass('selected');

        var value = $this.data('value');
        var filterType = $this.data('filter');

        // Update the appropriate selection array
        if ($this.hasClass('selected')) {
            if (filterType === 'type') {
                selectedTypes.push(value);
            } else if (filterType === 'status') {
                selectedStatuses.push(value);
            }
        } else {
            if (filterType === 'type') {
                selectedTypes = selectedTypes.filter(function (item) {
                    return item !== value;
                });
            } else if (filterType === 'status') {
                selectedStatuses = selectedStatuses.filter(function (item) {
                    return item !== value;
                });
            }
        }

        // Update filter count badge
        var count = selectedTypes.length + selectedStatuses.length;
        $('.wpc-filter-count').text(count > 0 ? '(' + count + ')' : '');

        fetchPosts(selectedTypes, currentPage);
    });


    $('.selector-dropdown').on('mouseleave', function () {
        var $dropdownMenu = $(this).find('.dropdown-menu');
        dropdownTimeout = setTimeout(function () {
            $dropdownMenu.slideUp(200);
        }, 500);
    });

    $('.selector-dropdown').on('mouseenter', '.dropdown-menu', function () {
        clearTimeout(dropdownTimeout);
    });

    $('.selector-dropdown').on('mouseleave', '.dropdown-menu', function () {
        var $dropdownMenu = $(this);
        dropdownTimeout = setTimeout(function () {
            $dropdownMenu.slideUp(200);
        }, 500);
    });

    $(document).on('click', function () {
        $('.selector-dropdown .dropdown-menu').slideUp(200);
    });

    // $(document).on('click', function() {
    //     console.log('Selected Types:', selectedTypes);
    //     console.log('Selected Statuses:', selectedStatuses);
    //     console.log('Selected Optimizes:', selectedOptimizes);
    // });

    $('.textareaChange').blur(function () {
        $('.action-buttons').fadeOut(500, function () {
        });

        $('.save-button').fadeIn(500);
    });

    const httpErrorCodes = {
        300: 'Multiple Choices',
        301: 'Moved Permanently',
        302: 'Redirect',
        303: 'See Other',
        304: 'Not Modified',
        305: 'Use Proxy',
        307: 'Temporary Redirect',
        308: 'Permanent Redirect',
        400: 'Bad Request',
        401: 'Unauthorized',
        402: 'Payment Required',
        403: 'Forbidden',
        404: 'Not Found',
        405: 'Method Not Allowed',
        406: 'Not Acceptable',
        407: 'Proxy Authentication Required',
        408: 'Request Timeout',
        409: 'Conflict',
        410: 'Gone',
        411: 'Length Required',
        412: 'Precondition Failed',
        413: 'Payload Too Large',
        414: 'URI Too Long',
        415: 'Unsupported Media Type',
        416: 'Range Not Satisfiable',
        417: 'Expectation Failed',
        418: 'I\'m a teapot', // April Fools' joke in RFC 2324
        421: 'Misdirected Request',
        422: 'Unprocessable Entity',
        423: 'Locked',
        424: 'Failed Dependency',
        425: 'Too Early',
        426: 'Upgrade Required',
        428: 'Precondition Required',
        429: 'Too Many Requests',
        431: 'Request Header Fields Too Large',
        451: 'Unavailable For Legal Reasons',
        500: 'Internal Server Error',
        501: 'Not Implemented',
        502: 'Bad Gateway',
        503: 'Service Unavailable',
        504: 'Gateway Timeout',
        505: 'HTTP Version Not Supported',
        506: 'Variant Also Negotiates',
        507: 'Insufficient Storage',
        508: 'Loop Detected',
        510: 'Not Extended',
        511: 'Network Authentication Required'
    };

});

// Function to trigger the pop effect on the new div
function triggerPopEffect(newDiv, parentId) {
    if (!window.popped.includes(parentId)) {
        window.popped.push(parentId);
        newDiv.classList.add('pop');
        // Remove the pop class after the animation is complete
        setTimeout(() => {
            newDiv.classList.remove('pop');
        }, 500); // Match the duration of the animation
    }
}

jQuery(document).ready(function ($) {
    // Sidebar nav tooltips — disabled (full text labels always visible)
    // Tooltipster was used for icon-only collapsed sidebar; no longer needed.

    /**
     * Contextual Purge Buttons
     */
    function handlePurge(el, action, successText) {
        var $el = $(el);
        if ($el.hasClass('wpc-purging')) return false;

        $el.addClass('wpc-purging');
        var originalText = $el.text();

        $el.text('Purging...');

        $.post(wpc_ajaxVar.ajaxurl, {
            action: action,
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, function (response) {
            if (response.success) {
                $el.addClass('wpc-purge-success');
                $el.text(successText || 'Purged');
                setTimeout(function () {
                    $el.removeClass('wpc-purging wpc-purge-success');
                    $el.text(originalText);
                }, 2000);
            } else {
                $el.removeClass('wpc-purging');
                $el.text(originalText);
            }
        }).fail(function () {
            $el.removeClass('wpc-purging');
            $el.text(originalText);
        });
    }

    $(document).on('click', '.wpc-purge-html-cache', function (e) {
        e.preventDefault();
        e.stopPropagation();
        handlePurge(this, 'wps_ic_purge_html', 'Cache Purged');
        return false;
    });

    $(document).on('click', '.wpc-purge-cdn-cache', function (e) {
        e.preventDefault();
        e.stopPropagation();
        handlePurge(this, 'wps_ic_purge_cdn', 'CDN Purged');
        return false;
    });

    $(document).on('click', '.wpc-purge-critical-css', function (e) {
        e.preventDefault();
        e.stopPropagation();
        handlePurge(this, 'wps_ic_purge_critical_css', 'CSS Purged');
        return false;
    });

    // ── Mobile hamburger menu ─────────────────────────────
    // Scoped to .wpc-advanced-settings-container-v4 — no WP core changes
    (function initMobileMenu() {
        // Always inject elements — CSS handles visibility via min-width:769px hide rule
        var $wrapper = $('.wpc-advanced-settings-container-v4');
        if (!$wrapper.length) return;

        // Inject hamburger button at far right of header
        var $header = $('.wpc-header').first();
        if (!$header.length) $header = $wrapper.find('.wpc-advanced-settings-header').first();
        if ($header.length && !$('#wpc-mobile-menu-toggle').length) {
            $header.append(
                '<button id="wpc-mobile-menu-toggle" type="button" aria-label="Open menu" aria-expanded="false">' +
                '<span></span><span></span><span></span></button>'
            );
        }

        // Inject backdrop
        if (!$('#wpc-mobile-backdrop').length) {
            $wrapper.append('<div id="wpc-mobile-backdrop"></div>');
        }

        // Inject drawer header (logo + close) at top of sidebar
        var $tabList = $wrapper.find('.wpc-settings-tab-list');
        if ($tabList.length && !$('#wpc-drawer-header').length) {
            var logoSrc = $tabList.closest('[data-plugin-url]').attr('data-plugin-url');
            if (!logoSrc) {
                // Fallback: grab from the main header logo
                var $mainLogo = $('.wpc-header-logo img').first();
                logoSrc = $mainLogo.length ? $mainLogo.attr('src') : '';
            } else {
                logoSrc += 'assets/v4/images/main-logo.svg';
            }
            // Use header logo alt text for whitelabel support
            var brandName = $('.wpc-header-logo img').first().attr('alt') || 'WP Compress';
            $tabList.prepend(
                '<div id="wpc-drawer-header">' +
                    '<div class="wpc-drawer-logo">' +
                        (logoSrc ? '<img src="' + logoSrc + '" alt="' + brandName + '" />' : '<span>' + brandName + '</span>') +
                    '</div>' +
                    '<button id="wpc-drawer-close" type="button" aria-label="Close menu">' +
                        '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">' +
                            '<path d="M15 5L5 15M5 5l10 10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>' +
                        '</svg>' +
                    '</button>' +
                '</div>'
            );
        }

        // Inject divider + Simple Settings link at bottom of drawer
        if (!$tabList.find('#wpc-drawer-footer').length) {
            var simpleBtn = $('.wpc-change-ui-to-simple').first();
            var footerLabel = simpleBtn.length ? simpleBtn.text().trim() : 'Simple Settings';
            $tabList.find('ul').after(
                '<div id="wpc-drawer-footer">' +
                    '<div class="wpc-drawer-divider"></div>' +
                    '<a href="#" class="wpc-drawer-simple-link wpc-change-ui-to-simple">' +
                        '<span class="wpc-icon-container"><span class="wpc-icon">' +
                            '<svg width="20" height="20" viewBox="0 0 576 512" xmlns="http://www.w3.org/2000/svg">' +
                                '<path fill="currentColor" d="M176.1 105.7l-89.9-59.9-8.4 8.4 59.9 89.9 38.3 0 0-38.3zm48-25.7l0 78.1 97.9 97.9 94.1 0c99.4 99.4 152.7 152.7 160 160l-128 128c-7.3-7.3-60.6-60.6-160-160l0-94.1-97.9-97.9-78.1 0-96-144 64-64 144 96zm112 284.1l112 112 60.1-60.1-112-112-60.1 0 0 60.1zM180.7 250.3l33.9 33.9-125.1 125.1-22.6 22.6 45.3 45.3c1.3-1.3 44-44 128-128l0 67.9c-121.5 121.5-116.4 116.4-128 128-.9-.9-33-33-96.2-96.2L-1 432c3.7-3.7 98.1-98.2 181.7-181.7zm155.5-48.2l0-92.1 7-7 53.3-53.3c-6.6-1.1-13.4-1.7-20.3-1.7-44.5 0-83.3 24.2-104 60.1l0-72C300.7 13.5 336.9 0 376.1 0l.8 0c26.8 .1 52.2 6.5 74.7 17.9l1.2 .6c9.3 4.8 18 10.3 26.2 16.7-5.4 5.4-37 37-94.8 94.8l0 30.1 30.1 0c57.9-57.9 89.5-89.5 94.8-94.8 6.3 8.2 11.9 16.9 16.7 26.2l.6 1.2c10.4 20.6 16.6 43.6 17.7 67.9 .1 2.5 .2 5 .2 7.6 0 41.3-14.9 79.2-39.7 108.4l-34.1-34.1c16.1-20.4 25.8-46.3 25.8-74.3 0-6.9-.6-13.7-1.7-20.3l-53.3 53.3-7 7-92.1 0-5.8-5.8z"/>' +
                            '</svg>' +
                        '</span></span>' +
                        '<span>' + footerLabel + '</span>' +
                    '</a>' +
                '</div>'
            );
        }

        function openDrawer() {
            $wrapper.addClass('wpc-mobile-menu-open');
            $('#wpc-mobile-menu-toggle').attr('aria-expanded', 'true');
            document.body.style.overflow = 'hidden';
        }

        function closeDrawer() {
            $wrapper.removeClass('wpc-mobile-menu-open');
            $('#wpc-mobile-menu-toggle').attr('aria-expanded', 'false');
            document.body.style.overflow = '';
        }

        // Toggle drawer
        $(document).on('click', '#wpc-mobile-menu-toggle', function () {
            $wrapper.hasClass('wpc-mobile-menu-open') ? closeDrawer() : openDrawer();
        });

        // Close on drawer header close button
        $(document).on('click', '#wpc-drawer-close', function () {
            closeDrawer();
        });

        // Close on backdrop click
        $(document).on('click', '#wpc-mobile-backdrop', function () {
            closeDrawer();
        });

        // Close on Escape
        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && $wrapper.hasClass('wpc-mobile-menu-open')) {
                closeDrawer();
            }
        });

        // Close when a tab link is clicked
        $(document).on('click', '.wpc-settings-tab-list ul li a', function () {
            closeDrawer();
        });

        // Swipe-left to close drawer
        (function () {
            var tabList = document.querySelector('.wpc-settings-tab-list');
            if (!tabList) return;
            var startX = 0, startY = 0;

            tabList.addEventListener('touchstart', function (e) {
                startX = e.touches[0].clientX;
                startY = e.touches[0].clientY;
            }, { passive: true });

            tabList.addEventListener('touchend', function (e) {
                var dx = e.changedTouches[0].clientX - startX;
                var dy = e.changedTouches[0].clientY - startY;
                // Swipe left: negative dx, more horizontal than vertical
                if (dx < -60 && Math.abs(dy) < Math.abs(dx)) {
                    closeDrawer();
                }
            }, { passive: true });
        })();
    })();

    // Safety net: ensure body scroll is restored after any SweetAlert2 popup closes.
    // On this page SA2 hides the container (display:none) but doesn't remove
    // swal2-shown from body, leaving overflow:hidden permanently.
    function cleanupSwalBodyLock() {
        if (!document.body.classList.contains('swal2-shown')) return;
        var container = document.querySelector('.swal2-container');
        // SA2 hides via CSS class (computed none) not inline style
        var isVisible = container && getComputedStyle(container).display !== 'none';
        if (!isVisible) {
            document.body.classList.remove('swal2-shown', 'swal2-height-auto');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
    }

    // Catch all popup close paths: close button, escape, backdrop, action buttons
    $(document).on('click', '.swal2-close, .swal2-container, .btn-close, .btn-cdn-config, .cdn-popup-save-btn, .btn-exclude-save', function () {
        setTimeout(cleanupSwalBodyLock, 300);
        setTimeout(cleanupSwalBodyLock, 800);
        setTimeout(cleanupSwalBodyLock, 1500);
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            setTimeout(cleanupSwalBodyLock, 300);
            setTimeout(cleanupSwalBodyLock, 800);
        }
    });

    // Also hook into WPCSwal.close if available
    if (typeof WPCSwal !== 'undefined' && WPCSwal.close) {
        var origClose = WPCSwal.close.bind(WPCSwal);
        WPCSwal.close = function () {
            origClose.apply(this, arguments);
            setTimeout(cleanupSwalBodyLock, 300);
            setTimeout(cleanupSwalBodyLock, 800);
        };
    }

    // Click-to-copy for CNAME domain values
    $(document).on('click', '.wpc-copy-on-click', function () {
        var text = $(this).text().trim();
        var $el = $(this);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                var orig = $el.text();
                $el.text('Copied!');
                setTimeout(function () { $el.text(orig); }, 1500);
            });
        }
    });

    // Export/Import: clicking anywhere on the card toggles the checkbox
    function updateExportCardStates() {
        $('#export_settings .cdn-popup-inner').each(function () {
            var isChecked = $(this).find('input[type="checkbox"]').prop('checked');
            $(this).toggleClass('export-card-checked', isChecked);
        });
    }

    $('#export_settings .cdn-popup-inner').on('click', function (e) {
        if ($(e.target).is('input[type="checkbox"]')) {
            // Checkbox was clicked directly — just update card states
            updateExportCardStates();
            return;
        }
        var cb = $(this).find('input[type="checkbox"]');
        cb.prop('checked', !cb.prop('checked'));
        updateExportCardStates();
    }).css('cursor', 'pointer');

    // Also handle direct checkbox clicks
    $('#export_settings input[type="checkbox"]').on('change', function () {
        updateExportCardStates();
    });

    // Set initial states
    updateExportCardStates();

    // ─── V3 Circle Progress Bars — 260px retina, thinner BEFORE, thicker AFTER ─
    (function initializeV3CircleProgressBars() {
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
    })();

    // ─── V2 Circle Progress Bars — Flexbox-centered text ────────────────
    (function initializeV2CircleProgressBars() {
        var v2Rings = document.querySelectorAll('.wpc-v2-ring');
        if (!v2Rings.length) return;
        v2Rings.forEach(function(ring) {
            var progressBar = ring.querySelector('.circle-progress-bar-v2');
            if (!progressBar) return;

            var value = parseFloat(progressBar.getAttribute('data-value'));
            var isHero = ring.classList.contains('wpc-v2-ring-hero');
            var size = 200;

            var gradient, bgFill;
            if (value <= 0.55) {
                gradient = ['#FF0000', '#FF6347'];
                bgFill = '#FFE6E6';
            } else if (value <= 0.89) {
                gradient = ['#FFD700', '#FFA500'];
                bgFill = '#FEF7ED';
            } else {
                gradient = ['#22c55e', '#059669'];
                bgFill = '#dcfce7';
            }

            $(progressBar).circleProgress({
                value: value,
                size: size,
                thickness: isHero ? 12 : 10,
                startAngle: -Math.PI / 2,
                lineCap: 'round',
                fill: {gradient: gradient},
                emptyFill: bgFill
            });
        });
    })();

});