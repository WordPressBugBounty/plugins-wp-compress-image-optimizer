jQuery(document).ready(function ($) {
    var currentPage = 1;
    var itemsPerPage = 10;
    var fetchRunning = false;
    var searchPending = false;
    var searchTerm = '';


    // Fancy Dropdown
    $('.wpc-cf-zone-list').on('click', function(){
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
        searchTerm = $('#live-search').val();
        if (fetchRunning === false) {
            fetchPosts(selectedTypes, currentPage);
        } else {
            searchPending = true;
        }
    }

    $('.wpc-change-ui-to-simple').on('click', function (e){
        e.preventDefault();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wpsChangeGui',
                view:'lite',
                nonce: wpc_ajaxVar.nonce,
            },
            success: function (response) {
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
            data: {action: 'wps_ic_StopBulk',nonce: wpc_ajaxVar.nonce},
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
                    html.find('span.status').addClass('active').html('Active');
                } else {
                    html.find('span.status').addClass('disabled').html('Disabled');
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
            width: 750,
            showCloseButton: true,
            showCancelButton: false,
            showConfirmButton: false,
            allowOutsideClick: false,
            customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup',
            },
            onOpen: function () {

                var popup = $('.swal2-container .ajax-settings-popup');
                $('.cdn-popup-loading', popup).show();

                $.post(wpc_ajaxVar.ajaxurl, {
                    action: 'wps_ic_get_per_page_settings_html',
                    id: ID,
                    nonce: wpc_ajaxVar.nonce
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

        $(button).html('Updating...');

        $.post(ajaxurl, {
            action: 'wps_ic_pull_stats',
            wps_ic_nonce: wpc_ajaxVar.nonce
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
        console.log(process_all)
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
            action: 'wps_ic_critical_get_assets',
            pageID: pageID,
            nonce: wpc_ajaxVar.nonce
        }, function (response) {
            var files = JSON.parse(response.data);

            assets_count_img.html(files.img);
            assets_count_css.html(files.css);
            assets_count_js.html(files.js);

            $.post(ajaxurl, {
                action: 'wps_ic_critical_run',
                pageID: pageID,
                wps_ic_nonce: wpc_ajaxVar.nonce
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
        var newValue = 1;

        if (beforeValue == 'checked') {
            // It was already active, remove checked
            $(this).removeAttr('checked');
            $('.circle-check', parent).removeClass('active');
        } else {
            // It's not active, activate
            $(this).attr('checked', 'checked');
            $('.circle-check', parent).addClass('active');
        }

        $('.save-button').fadeIn(500);
        //$('.wpc-preset-dropdown>option').removeAttr('selected').prop('selected', false);
        //$('.wpc-preset-dropdown>option:eq(2)').attr('selected', 'selected').prop('selected', true);

        $('input[name="wpc_preset_mode"]').val('custom');
        $('a', '.wpc-dropdown-menu').removeClass('active');
        $('button', '.wpc-dropdown').html('Custom');
        $('a[data-value="custom"]', '.wpc-dropdown-menu').addClass('active');

        //var selectedValue = $('.wpc-preset-dropdown').val();
        $.post(ajaxurl, {
            action: 'wpc_ic_ajax_set_preset',
            value: 'custom',
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, function (response) {

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
            width: 750,
            showCloseButton: true,
            showCancelButton: false,
            showConfirmButton: false,
            allowOutsideClick: false,
            customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup',
            },
            onOpen: function () {

                var popup = $('.swal2-container .ajax-settings-popup');
                $('.cdn-popup-loading', popup).show();

                $.post(wpc_ajaxVar.ajaxurl, {
                    action: 'wps_ic_get_page_excludes_popup_html',
                    id: ID,
                    setting: setting,
                    nonce: wpc_ajaxVar.nonce
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

            console.log('lol')
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
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_get_optimization_status_pages',
                post_type: selected,
                post_status: selectedStatuses,
                nonce: wpc_ajaxVar.nonce,
                page: page,
                offset: offset,
                search: searchTerm
            },
            success: function (response) {
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
                        pagesHtml += `<div class="wpc-dropdown-row-arrow">
                                                            <i class="icon-down-open"></i>
                                                    </div>`;
                        pagesHtml += `${createRetestButton(item)}`;
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
                        console.log('644');
                        pagesHtml += insertResultsRow(item)
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
                            gradient: ["#61CB70", "#61CB70"],
                            gradientAngle: Math.PI / 7
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
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_get_optimization_status_pages',
                post_type: selectedTypes,
                post_status: selectedStatuses,
                nonce: wpc_ajaxVar.nonce,
                page: page,
                offset: offset,
                search: searchTerm
            },
            success: function (response) {
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

                    console.log('985');
                    var newTestResultsHtml = insertResultsRow(newItem);
                    var $testResultsElement = $row.find('.test-results');
                    var shouldUpdate =
                        $testResultsElement.find('.test-is-running').css('display') !== $('<div>').html(newTestResultsHtml).find('.test-is-running').css('display') ||
                        $testResultsElement.find('.test-not-runned').css('display') !== $('<div>').html(newTestResultsHtml).find('.test-not-runned').css('display') ||
                        $testResultsElement.find('.wpc-test-results').css('display') !== $('<div>').html(newTestResultsHtml).find('.wpc-test-results').css('display') ||
                        $testResultsElement.find('.wpc-test-redo').css('display') !== $('<div>').html(newTestResultsHtml).find('.wpc-test-redo').css('display');

                    if (shouldUpdate && local === false) {
                        $testResultsElement.html(newTestResultsHtml);

                        // Circle Progress Bar
                        setTimeout(function () {
                            $('.circle-progress-bar', $testResultsElement).circleProgress({
                                size: 50,
                                startAngle: -Math.PI / 6 * 3,
                                lineCap: 'round',
                                thickness: '5',
                                fill: {
                                    gradient: ["#61CB70", "#61CB70"],
                                    gradientAngle: Math.PI / 7
                                },
                                emptyFill: 'rgba(176,224,176,0.5)'
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

        if ($(this).data('setting_name') === 'critical_css' || $(this).data('setting_name') === 'advanced_cache') {
            dropdownHtml += `<a href="#" class="dropdown-item" data-action="purge"><span class="icon purge-icon"></span>Purge</a>`;
        }

        dropdownHtml += `</div>`;

        // Append and position the dropdown
        $(this).after(dropdownHtml);
        var dropdownMenu = $(this).next('.dropdown-menu');
        dropdownMenu.css({
            top: $(this).position().top + $(this).outerHeight(),
            left: $(this).position().left
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
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wps_ic_save_optimization_status',
                    id: ID,
                    setting_name: settingName,
                    setting_action: action,
                    nonce: wpc_ajaxVar.nonce,
                },
                success: function (response) {
                    updateOptimizationStatus();
                    updatePosts(selectedTypes, currentPage);
                },
                error: function (xhr, status, error) {
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
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wps_ic_run_single_optimization',
                    id: ID,
                    nonce: wpc_ajaxVar.nonce,
                },
                success: function (response) {
                    updateOptimizationStatus();
                    optimizationCheckInterval = setInterval(updateOptimizationStatus, 5000);
                },
                error: function (response) {
                    $('.test-is-running', testResultsRow).hide();
                    $('.test-not-runned', testResultsRow).show();
                }
            });
        } else {
            setTimeout(function () {
                updatePosts(selectedTypes, currentPage);
            }, 1000)
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wps_ic_run_tests',
                    id: ID,
                    retest: retest,
                    nonce: wpc_ajaxVar.nonce,
                },
                success: function (response) {
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
                                gradient: ["#61CB70", "#61CB70", "#b0e0b0"],
                                gradientAngle: Math.PI / 7
                            },
                            emptyFill: 'rgba(176,224,176,0.8)'
                        });
                    }, 200); // 200ms timeout
                },
                error: function (xhr, status, error) {
                    testResultsRow.html('<p>Error loading test results.</p>');
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
            statusMessages = [
                'Scanning for optimization opportunities...',
                'Warming up the Cache...',
                'Finalizing Performance Optimizations...',
                'Finalizing Performance Optimizations...',
            ];
        } else {
            statusMessages = [
                'Scanning for optimization opportunities...',
                'Verifying Real-Time Image Optimization...',
                'Analyzing Adaptive Performance...',
                'Warming up the Cache...',
                'Minifying HTML...',
                'Enhancing Server Response time...',
                'Optimizing CSS Files...',
                'Optimizing JavaScript Files...',
                'Finalizing Performance Optimizations...',
                'Finalizing Performance Optimizations...',
            ];
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
                messageContainer.html('Preparing optimization...');
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
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wps_ic_check_optimization_status',
                    optimize: selectedOptimizes,
                    nonce: wpc_ajaxVar.nonce,
                },
                success: function (response) {

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
                                },
                                {
                                    duration: 3000,
                                    easing: 'swing',
                                    step: function () {
                                        $this.text(Math.floor(this.countNum).toLocaleString());
                                    },
                                    complete: function () {
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


                },
                error: function (error) {
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
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_start_optimizations',
                nonce: wpc_ajaxVar.nonce,
            },
            success: function () {
                updateOptimizationStatus();
                optimizationCheckInterval = setInterval(updateOptimizationStatus, 5000);
            }
        });

    });

    $('.wpc-optimization-page-button').on('click', '.wpc-stop-page-optimizations', function () {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_stop_optimizations',
                nonce: wpc_ajaxVar.nonce,
            },
            success: function () {
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
            type: 'POST',
            data: {
                action: 'wps_ic_test_api_connectivity',
                nonce: wpc_ajaxVar.nonce,
            },
            success: function (response) {
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
                    title: 'API Test Results',
                    html: tableHtml,
                    width: '500px'
                });
            },
            error: function (error) {
                // Display error in SweetAlert
                WPCSwal.fire({
                    title: 'Error!',
                    text: 'An error occurred while fetching the data.',
                    icon: 'error'
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
            'width': '15%',
            'margin-left': marginLeft + '%'
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
        e.stopPropagation(); // Prevent event bubbling
        var $this = $(this);
        $this.toggleClass('selected');

        var value = $this.data('value');
        var dropdownType = $this.closest('.dropdown').data('dropdown');

        // Update the appropriate selection array
        if ($this.hasClass('selected')) {
            if (dropdownType === 'type') {
                selectedTypes.push(value);
            } else if (dropdownType === 'status') {
                selectedStatuses.push(value);
            } else if (dropdownType === 'optimize') {
                if (!Array.isArray(selectedOptimizes)) {
                    //if it was not an array nothing was selected to be optimized
                    selectedOptimizes = [];
                }
                selectedOptimizes.push(value);
                updateOptimizationStatus();
            }
        } else {
            if (dropdownType === 'type') {
                selectedTypes = selectedTypes.filter(function (item) {
                    return item !== value;
                });
            } else if (dropdownType === 'status') {
                selectedStatuses = selectedStatuses.filter(function (item) {
                    return item !== value;
                });
            } else if (dropdownType === 'optimize') {
                selectedOptimizes = selectedOptimizes.filter(function (item) {
                    return item !== value;
                });
                updateOptimizationStatus();
            }
        }
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