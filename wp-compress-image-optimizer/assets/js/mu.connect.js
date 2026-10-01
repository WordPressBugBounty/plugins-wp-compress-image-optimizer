jQuery(document).ready(function ($) {

    var siteContainer = $('.wpc-ic-mu-site-container');
    var loadingContainer = $('.wps-ic-mu-site-saving');
    var overlay = $('.wp-compress-mu-content-overlay');
    var overlayForm = $('.wpc-ic-mu-overlay-settting-form');
    var siteListContainer = $('.wpc-ic-mu-bulk-site-list-container');

    var listTable = $('.wpc-ic-mu-list-table');

    var bulkConnectSites = 0;
    var selectedSites = [];

    // Question tooltips
    $('.ic-tooltip').tooltipster({
        maxWidth:'300',
        delay:50,
    });

    /**
     * @since 3.3.0
     * Status: Required 5.00.00
     */
    $('.setting-value.ic-custom-tooltip').tooltipster({
        maxWidth: '235',
        position: 'left'
    });

    $('.setting-label', '.setting-option').hover(function (e) {
        var parent = $(this).parent();
        $('.setting-value.ic-custom-tooltip', parent).tooltipster('show');
    });

    $('.setting-label', '.setting-option').mouseleave(function (e) {
        var parent = $(this).parent();
        $('.setting-value.ic-custom-tooltip', parent).tooltipster('hide');
    });

    $('body').on('click', '.disabled-checkbox', function (e) {
        e.preventDefault();
        return false;
    });

    // Live CDN Toggle - Default Settings
    $('body').on('click', '.label-live-cdn-toggle', function(e){
       var label = $(this);
       var parent = $(this).parent().parent();
       var input = $('#live-cdn-toggle', parent);
       var serving_cdn = $('.settings-area-serve-cdn-images');
       var serving_checkboxes = $('input[type="checkbox"]', serving_cdn);

       if ($(input).is(':checked')) {
           // Then it's turned off
           $(serving_checkboxes).addClass('disabled-checkbox').removeAttr('checked');
       } else {
           // On
           $(serving_checkboxes).removeClass('disabled-checkbox').prop('checked', 'checked');
       }
    });

    // Select ALL checkbox in Bulk List Sites
    $('body').on('change', 'input[name="wpc-ic-mu-select-all"]', function (e) {
        var select_all = $(this);
        var table = $(this).parents('table');
        var tbody = $('tbody', table);
        var checkboxes = $('.wpc-ic-mu-sites-checkbox', tbody);

        $(checkboxes).each(function (index, item) {
            if ($(item).attr('disabled') == 'disabled') {
                // Nothing, for now
            }
            else {
                if ($(select_all).is(':checked')) {
                    bulkConnectSites++;
                    $(item).prop('checked', true);
                }
                else {
                    bulkConnectSites--;
                    $(item).prop('checked', false);
                }
            }
        });

        return false;
    });

    // Bulk Configure
    $('body').on('click', '.wps-ic-mu-bulk-configure', function (e) {
        var checkboxes = $('.wpc-ic-mu-sites-checkbox', listTable);
        var selectedSites = [];

        if (!$('.wpc-ic-mu-sites-checkbox', listTable).is(':checked')) {
            return false;
        }

        $(checkboxes).each(function (i, item) {
            if ($(item).is(':checked')) {
                selectedSites.push($(item).val());
            }
        });


        if (selectedSites.length == 0) {
            WPCSwal.fire({
                title: '', position: 'top', html: jQuery('.wps-ic-mu-popup-select-sites').html(), width: 600, showCloseButton: true, showCancelButton: false, showConfirmButton: false, allowOutsideClick: true, customClass: {
                    container: 'no-padding-popup-bottom-bg switch-legacy-popup',
                }, onOpen: function () {

                }
            });
            return false;
        }
        else {
            // wps-ic-mu-bulk-reconfigure-settings
            $('input[name="apply_to_sites"]').val(selectedSites);
            $('.wpc-ic-mu-bulk-site-list-container').hide();
            $('.wps-ic-mu-bulk-reconfigure-settings').show();
        }

    });

    // Disconnect All
    $('body').on('click', '.wps-ic-mu-bulk-disconnect-all', function (e) {
        var checkboxes = $('.wpc-ic-mu-sites-checkbox', listTable);
        var selectedSites = [];
        var sidebarList = $('.wp-compress-mu-site-list');

        if (!$('.wpc-ic-mu-sites-checkbox', listTable).is(':checked')) {
            return false;
        }

        $(checkboxes).each(function (i, item) {
            if ($(item).is(':checked') && $(item).attr('data-status') == 'connected') {
                selectedSites.push($(item).val());
            }
        });

        if (selectedSites.length == 0) {
            WPCSwal.fire({
                title: '', position: 'top', html: jQuery('.wps-ic-mu-popup-empty-sites').html(), width: 600, showCloseButton: true, showCancelButton: false, showConfirmButton: false, allowOutsideClick: false, customClass: {
                    container: 'wps-ic-mu-popup-no-padding',
                }, onOpen: function () {
                    $('.wps-ic-mu-popup-all-sites-disconnected', '.swal2-container').show();
                }
            });
            return false;
        }
        else {
            WPCSwal.fire({
                title: '', position: 'top', html: jQuery('div.wps-ic-mu-bulk-disconnect-all').html(), width: 600, showCloseButton: true, showCancelButton: false, showConfirmButton: true, allowOutsideClick: true, confirmButtonText:'Yes, disconnect selected sites!', cancelButtonText:'No, leave the sites connected!', customClass: {
                    container: 'wps-ic-mu-popup-no-padding wps-ic-mu-popup-actions wps-ic-mu-popup-disconnect-all',
                }, onOpen: function () {

                }
            }).then((result) => {
                if (result.value) {

                    $(selectedSites).each(function (i, item) {
                        // Bulk Disconnect
                        var tableRow = $('tr.wpc-ic-mu-row-site-' + item, '.wpc-ic-mu-list-table');

                        $('.wps-ic-mu-status-actions', tableRow).hide();
                        $('.wps-ic-mu-status-loading .wps-ic-mu-status-text', tableRow).html('Disconnecting...');
                        $('.wps-ic-mu-status-loading', tableRow).show();

                        var siteID = item;
                        var sidebarItem = $('.wp-mu-site-' + siteID, sidebarList);

                        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_disconnect_single_site', siteID: siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                            if (response.success) {
                                $('.wpc-ic-mu-list-checkbox input[type="checkbox"]',tableRow).removeAttr('checked');
                                $('.wpc-ic-mu-list-checkbox input[type="checkbox"]',tableRow).attr('data-status', 'disconnected');
                                $(sidebarItem).removeClass('wps-ic-mu-connected').addClass('wps-ic-mu-not-connected');
                                $('.wpc-ic-mu-list-actions>span', tableRow).attr('class', 'wps-ic-mu-tag-not-connected');
                                $('.wpc-ic-mu-sites-checkbox', tableRow).removeAttr('disabled');
                                $('.wps-ic-mu-status-actions', tableRow).html(response.data.html_status).show();
                                $('.wps-ic-mu-status-loading', tableRow).hide();
                            }
                            else {
                                // Error
                            }
                        });

                    });

                    $('input[name="wpc-ic-mu-select-all"]').removeAttr('checked');
                    $(checkboxes).removeAttr('checked');

                }
                else {

                }
            });
        }

    });

    // Connect All
    $('body').on('click', '.wps-ic-mu-bulk-connect-all', function (e) {
        var checkboxes = $('.wpc-ic-mu-sites-checkbox', listTable);

        if (!$('.wpc-ic-mu-sites-checkbox', listTable).is(':checked')) {
            return false;
        }

        $(checkboxes).each(function (i, item) {
            if ($(item).is(':checked') && $(item).attr('data-status') == 'disconnected') {
                selectedSites.push($(item).val());
            }
        });

        if (selectedSites.length == 0) {
            WPCSwal.fire({
                title: '', position: 'top', html: jQuery('.wps-ic-mu-popup-empty-sites').html(), width: 600, showCloseButton: true, showCancelButton: false, showConfirmButton: false, allowOutsideClick: false, customClass: {
                    container: 'no-padding-popup-bottom-bg switch-legacy-popup',
                }, onOpen: function () {
                    $('.wps-ic-mu-popup-all-sites-connected', '.swal2-container').show();
                }
            });
            return false;
        }
        else {
            $('.wpc-ic-mu-bulk-site-list-container').hide();
            $('.wps-ic-mu-bulk-configure-settings').fadeIn(500);
        }

    });

    // Bulk Reconfigure
    $('body').on('click', '.wpc-mu-bulk-reconfigure', function(e){
        e.preventDefault();

        var sites = $('input[name="apply_to_sites"]').val();
        var form_serialize = $('.wpc-ic-mu-bulk-settting-reconfigure-form').serialize();

        $(overlay).show();

        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_reconfigure_sites', sites: sites, settings: form_serialize,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                $(overlay).hide();
                $('input[type="checkbox"]', '.wpc-ic-mu-bulk-site-list-container').removeAttr('checked');
                $('.wps-ic-mu-bulk-reconfigure-settings').hide();
                $('.wpc-ic-mu-bulk-site-list-container').show();
            }
            else {
                // Error
            }
        });

        return false;
    });

    // Apply & Bulk Connect
    $('body').on('click', '.wpc-mu-bulk-connect', function (e) {
        e.preventDefault();

        var bulkConnectStatus = $('.wps-ic-mu-bulk-connect-status');
        var bulkStatus = $('h3', bulkConnectStatus);

        $('.wps-ic-mu-bulk-configure-settings').hide();
        $('.wps-ic-mu-bulk-connecting').fadeIn(500);

        var bulkProgressBar = $('.wps-ic-mu-bulk-connect-progress-bar', bulkConnectStatus);
        var bulkProgressBarPercentage = $('.wps-ic-mu-bulk-connect-progress-percent', bulkProgressBar);
        $(bulkProgressBarPercentage).css('width', '0%');

        var totalSites = selectedSites.length;
        var doneSites = 0;
        $(bulkStatus).html('0 out of ' + totalSites + ' sites done.');

        var form_serialize = $('.wpc-ic-mu-bulk-settting-form').serialize();
        var bulkPercentage = 0;

        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_connect_bulk_prepare', sites: JSON.stringify(selectedSites), settings: form_serialize,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                connectSingleSite(response.data, doneSites, totalSites);
            }
            else {
                // Error
            }
        });


        return false;
    });

    function connectSingleSite(siteID, doneSites, totalSites) {
        var sidebarList = $('.wp-compress-mu-site-list');
        var checkboxes = $('.wpc-ic-mu-sites-checkbox', '.wpc-ic-mu-list-table');
        var bulkConnectStatus = $('.wps-ic-mu-bulk-connect-status');
        var bulkStatus = $('h3', bulkConnectStatus);

        var bulkProgressBar = $('.wps-ic-mu-bulk-connect-progress-bar', bulkConnectStatus);
        var bulkProgressBarPercentage = $('.wps-ic-mu-bulk-connect-progress-percent', bulkProgressBar);
        var bulkPercentage = 0;
        var sidebarItem = $('.wp-mu-site-' + siteID, sidebarList);

        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_connect_single_site', bulk: 'true', siteID: siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                $(sidebarItem).removeClass('wps-ic-mu-not-connected').addClass('wps-ic-mu-connected');
                doneSites++;
            }
            else {
                doneSites++;
            }

            if (response.data == 'done') {
                doneSites = totalSites;
                $(bulkProgressBarPercentage).css('width', '100%');
            }
            else {
                bulkPercentage = (doneSites / totalSites) * 100;
                bulkPercentage = bulkPercentage.toFixed(1);
                $(bulkProgressBarPercentage).css('width', bulkPercentage + '%');
            }

            if (doneSites < totalSites) {
                connectSingleSite(response.data, doneSites, totalSites);
            }

            $(bulkStatus).html(doneSites + ' out of ' + totalSites + ' sites done.');

            if (response.data == 'done') {
                $(checkboxes).removeAttr('checked');
                $('h3', bulkConnectStatus).hide();
                $('.wps-ic-mu-bulk-saving', bulkConnectStatus).hide();
                $('.wps-ic-mu-bulk-done', bulkConnectStatus).show();
                $('h1', bulkConnectStatus).html('All websites are successfully linked!').css('padding-bottom', '30px');
            }
        });
    }

    $('body').on('click', '.checkbox-container-v2>div', function (e) {
        e.preventDefault();

        var parent = $(this).parent();
        var input = $('input', parent);
        var checked = $(input).is(':checked');
        var informative = $('span', parent);
        var div = $(parent);

        if ($(input).is(':checked')) {
            $(input).prop('checked', false);
            $(informative).html('OFF');
            value = 0;
        }
        else {
            $(input).prop('checked', true);
            $(informative).html('ON');
            value = 1;
        }

    });

    $('body').on('click', '.wp-ic-select-box a', function (e) {
        e.preventDefault();

        var link = $(this);
        var li = $(this).parent();
        var ul = $(li).parent();
        var div = $(ul).parent();
        var input = $('input[type="hidden"]', div);
        var search_through = $(link).data('value');
        var value = $(link).data('value');

        if ($(div).hasClass('disabled')) {
            return false;
        }

        if ($(link).hasClass('wps-ic-change-optimization')) {
            $(input).val($(link).data('optimization_level'));
        }

        var checkbox = '';
        if (search_through == 'html' || search_through == 'html+css' || search_through == 'all') {
            checkbox = $('#css-toggle').parent();
            checkbox = $('label', checkbox);
        }

        if (search_through == 'html') {

            if ($('#css-toggle').is(':checked')) {
                $(checkbox).trigger('click');
            }

        }
        else if (search_through == 'html+css') {

            if (!$('#css-toggle').is(':checked')) {
                $(checkbox).trigger('click');
            }

        }
        else if (search_through == 'all') {
            if (!$('#css-toggle').is(':checked')) {
                $(checkbox).trigger('click');
            }

        }

        $(input).attr('value', value);
        $('li', ul).removeClass('current');
        $(link).parent().addClass('current');
    });

    $('body').on('submit', '.wpc-ic-mu-settting-form', function (e) {
        e.preventDefault();

        var siteID = wpc_siteID;
        var form_serialize = $('.wpc-ic-mu-settting-form').serialize();

        $(siteContainer).hide();
        $(overlay).show();
        $(overlayForm).hide();

        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_save_site_settings', siteID: siteID, form: form_serialize,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            setTimeout(function () {
                $(siteContainer).show();
                $(overlay).hide();
            }, 1500);
        });

        return false;
    });

    $('body').on('submit', '.wpc-ic-mu-default-settting-form', function (e) {
        e.preventDefault();

        var form_serialize = $('.wpc-ic-mu-default-settting-form').serialize();

        //$(siteContainer).hide();
        $(overlay).show();
        $(overlayForm).hide();

        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_save_default_settings', form: form_serialize,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            setTimeout(function () {
                $(siteContainer).show();
                $(overlay).hide();
            }, 1500);
        });

        return false;
    });

    // Popup
    $('body').on('click', '.wps-ic-configure-popup', function (e) {
        e.preventDefault();

        if ($(this).hasClass('LockedTooltip')) {
            return false;
        }

        var popup = $(this).data('popup');
        var popupwidth = $(this).data('popup-width');

        openConfigurePopup(popup,popupwidth);

        return false;
    });


    function openConfigurePopup(popup,popupwidth) {
        WPCSwal.fire({
            title: '', html: jQuery('#' + popup).html(), width: popupwidth, showCloseButton: true, showCancelButton: false, showConfirmButton: false, allowOutsideClick: false, customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup',
            }, onOpen: function () {
                if (popup == 'geo-location') {
                    GeoLocation();
                }
                else if (popup == 'exclude-list') {
                    ExcludeList();
                }
                else if (popup == 'custom-cdn') {
                    CustomCname(popup);
                }
                else if (popup == 'remove-custom-cdn') {
                    RemoveCustomCname();
                }
            }, onClose: function () {
                if (popup == 'geo-location') {

                }
                else if (popup == 'exclude-list') {

                }
                else if (popup == 'custom-cdn') {
                    CustomCnameClose();
                }
                else if (popup == 'remove-custom-cdn') {
                    CustomCnameClose();
                }
            }
        });
    }

    // Configure from table list
    $('body').on('click', '.wps-ic-mu-configure', function (e) {
        var link = $(this);
        var siteID = $(link).data('site-id');
        $('.wp-compress-mu-site-list li a.wpc-ic-mu-site-list-item-' + siteID).trigger('click');
    });

    // Select a site from list menu in sidebar
    $('body').on('click', '.wp-compress-mu-site-list li a', function (e) {

        return true;

        var link = $(this);
        var siteID = $(link).data('site-id');
        var content = $('.wp-compress-mu-content-inner');

        if ($(this).hasClass('wpc-ic-mu-ignore')) {
            return true;
        }

        $('.wp-compress-mu-notice').hide();
        $('.wp-compress-mu-site-list li a.active').removeClass('active');
        $(link).addClass('active');

        $(overlay).show();
        $(overlayForm).show();
        $(content).hide();
        $(siteContainer).hide();
        //$(loadingContainer).show();

        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_get_site_settings', siteID: siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            window.location.hash = '#mu-' + siteID;
            $(content).html(response.data);

            setTimeout(function () {
                $(overlay).hide();
                $(overlayForm).hide();
                $(siteContainer).show();
                $(content).show();
                // Question tooltips
                $('.ic-tooltip').tooltipster({
                    maxWidth:'300'
                });
                // Tooltip Custom
                $('.setting-value.ic-custom-tooltip').tooltipster({
                    maxWidth: '235',
                    position: 'left'
                });
                $('.setting-label', '.setting-option').hover(function (e) {
                    var parent = $(this).parent();
                    $('.setting-value.ic-custom-tooltip', parent).tooltipster('show');
                });

                $('.setting-label', '.setting-option').mouseleave(function (e) {
                    var parent = $(this).parent();
                    $('.setting-value.ic-custom-tooltip', parent).tooltipster('hide');
                });
                //$(loadingContainer).hide();
            }, 500);
        });


    });

    $('body').on('click', '.wps-ic-search-through', function (e) {
        e.preventDefault();

        var link = $(this);
        var ul = $(link).parent().parent();
        var search_through = $(link).data('value');
        var parent = $(ul).parent();
        var input = $('input', parent);

        var checkbox = $('#css-toggle').parent();
        checkbox = $('label', checkbox);

        if (search_through == 'html') {

            if ($('#css-toggle').is(':checked')) {
                $(checkbox).trigger('click');
            }

        }
        else if (search_through == 'html+css') {
            if (!$('#css-toggle').is(':checked')) {
                console.log('click');
                $(checkbox).trigger('click');
            }
            else {
                console.log('not click');
            }

        }
        else if (search_through == 'all') {
            if (!$('#css-toggle').is(':checked')) {
                $(checkbox).trigger('click');
            }

        }

        $(input).attr('value', search_through);
        $('li', ul).removeClass('current');
        $(link).parent().addClass('current');

        return false;
    });

    $('body').on('click', '.settings-area-serve-cdn .setting-option label', function (e) {
        e.preventDefault();

        var parent = $(this).parent();
        var input = $('input', parent);

        if ($(input).hasClass('disabled-checkbox')) {
            return false;
        }

        $(input).trigger('click');

        return false;
    });

    $('body').on('click', '.settings-area-additional-settings .setting-option .setting-label', function (e) {
        e.preventDefault();

        var parent = $(this).parent();
        $('label', parent).trigger('click');

        return false;
    });

    $('.wpc-mu-api-connect-form').on('submit', function (e) {
        e.preventDefault();

        $('.wpc-mu-api-connect-form>input[type="submit"]').hide();
        $('.wpc-mu-api-connect-form>input[type="text"]').hide();

        $('.wps-ic-mu-connecting-logo').show();
        $('.wps-ic-mu-button-connecting').show();

        var form = $(this);
        var token = $('input[name="api_token"]', form).val();

        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_connect', token: token,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {

            if (response.success) {
                // Popup
                window.location.reload();
            }
            else {
                $('.wps-ic-mu-connecting-logo').hide();
                $('.wps-ic-mu-button-connecting').hide();

                $('.wpc-mu-api-connect-form>input[type="submit"]').show();
                $('.wpc-mu-api-connect-form>input[type="text"]').show();
                //alert('Failure to conenct');

                WPCSwal.fire({
                    title: '', position: 'top', html: jQuery('.wpc-mu-connect-failure').html(), width: 600, showCloseButton: true, showCancelButton: false, showConfirmButton: false, allowOutsideClick: false, customClass: {
                        container: 'no-padding-popup-bottom-bg switch-legacy-popup',
                    }, onOpen: function () {

                    }
                });

            }

        });

        return false;
    });

    $('body').on('change', '.wpc-ic-mu-setting-checkbox', function(e){
        var checked = $(this).is(':checked');
        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_autoconnect_setting', checked:checked,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
        });
    });

    $('body').on('click', '.wpc-mu-individual-disconnect', function (e) {
        e.preventDefault();

        var sidebarList = $('.wp-compress-mu-site-list');
        var siteID = $(this).data('site-id');
        var sidebarItem = $('.wp-mu-site-' + siteID, sidebarList);

        WPCSwal.fire({
            title: '', position: 'top', html: jQuery('#wps-ic-mu-disconnect-popup').html(), width: 600, showCloseButton: true, showCancelButton: false, showConfirmButton: true, allowOutsideClick: true, confirmButtonText:'Yes, disconnect the site!', cancelButtonText:'No, leave the site connected!', customClass: {
                container: 'wps-ic-mu-popup-no-padding wps-ic-mu-popup-actions wps-ic-mu-popup-disconnect-all',
            }, onOpen: function () {

            }
        }).then((result) => {
            if (result.value) {

                $(siteContainer).hide();
                $(overlay).show();
                $(overlayForm).hide();

                $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_disconnect_single_site', siteID: siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                    if (response.success) {
                        window.location.reload();
                    }
                    else {
                        // Error
                    }
                });


            }
            else {

            }
        });


        return false;
    });

    $('body').on('click', '.wpc-mu-individual-disconnect-bulk', function (e) {
        e.preventDefault();

        var tableRow = $(this).parents('tr');
        var tableColumn = $(this).parents('td');
        var sidebarList = $('.wp-compress-mu-site-list');

        WPCSwal.fire({
            title: '', position: 'top', html: jQuery('#wps-ic-mu-disconnect-popup').html(), width: 600, showCloseButton: true, showCancelButton: false, showConfirmButton: true, allowOutsideClick: true, confirmButtonText:'Yes, disconnect the site!', cancelButtonText:'No, leave the site connected!', customClass: {
                container: 'wps-ic-mu-popup-no-padding wps-ic-mu-popup-actions wps-ic-mu-popup-disconnect-all',
            }, onOpen: function () {

            }
        }).then((result) => {
            if (result.value) {

                $('.wps-ic-mu-status-actions', tableRow).hide();
                $('.wps-ic-mu-status-loading .wps-ic-mu-status-text', tableRow).html('Disconnecting...');
                $('.wps-ic-mu-status-loading', tableRow).show();

                var siteID = $(this).data('site-id');
                var sidebarItem = $('.wp-mu-site-' + siteID, sidebarList);

                $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_disconnect_single_site', siteID: siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                    if (response.success) {
                        $('.wpc-ic-mu-list-checkbox input[type="checkbox"]', tableRow).attr('data-status', 'disconnected');
                        $(sidebarItem).removeClass('wps-ic-mu-connected').addClass('wps-ic-mu-not-connected');
                        $('.wpc-ic-mu-list-actions>span', tableRow).attr('class', 'wps-ic-mu-tag-not-connected');
                        $('.wpc-ic-mu-sites-checkbox', tableRow).removeAttr('disabled');
                        $('.wps-ic-mu-status-actions', tableRow).html(response.data.html_status).show();
                        $('.wps-ic-mu-status-loading', tableRow).hide();

                        $('.ic-tooltip').tooltipster({
                            maxWidth: '300',
                            delay: 50,
                        });
                    } else {
                        // Error
                    }
                });
            }
        });

        return false;
    });

    $('body').on('click', '.wpc-mu-individual-connect-bulk', function (e) {
        e.preventDefault();

        var tableRow = $(this).parents('tr');
        var tableColumn = $(this).parents('td');
        var sidebarList = $('.wp-compress-mu-site-list');

        $('.wps-ic-mu-status-actions', tableRow).hide();
        $('.wps-ic-mu-status-loading .wps-ic-mu-status-text', tableRow).html('Connecting...');
        $('.wps-ic-mu-status-loading', tableRow).show();

        var siteID = $(this).data('site-id');
        var sidebarItem = $('.wp-mu-site-' + siteID, sidebarList);

        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_connect_single_site', siteID: siteID, single: 'true',wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                $('.wpc-ic-mu-list-checkbox input[type="checkbox"]',tableRow).attr('data-status', 'connected');
                $(sidebarItem).removeClass('wps-ic-mu-not-connected').addClass('wps-ic-mu-connected');
                $('.wpc-ic-mu-list-actions>span', tableRow).attr('class', 'wps-ic-mu-tag-connected');
                $('.wpc-ic-mu-sites-checkbox', tableRow).removeAttr('disabled');
                $('.wps-ic-mu-status-actions', tableRow).html(response.data.html_status).show();
                $('.wps-ic-mu-status-loading', tableRow).hide();

                // Question tooltips
                $('.ic-tooltip').tooltipster({
                    maxWidth:'300'
                });
            }
            else {
                // Error
                alert('Your token is invalid!');
            }
        });

        return false;
    });

    $('body').on('click', '.wpc-mu-individual-connect', function (e) {
        e.preventDefault();

        var row = $(this).parents('tr');
        var content = $('.wp-compress-mu-content-inner');
        var table = $('.wpc-mu-list-table');
        var sidebarList = $('.wp-compress-mu-site-list');

        $(table).hide();
        $(this).hide();
        //$(loadingContainer).show();
        $('.wps-ic-mu-single-site-connecting-loading').show();
        $('.wps-ic-mu-single-site-not-connected').hide();

        var siteID = $(this).data('site-id');
        var sidebarItem = $('.wp-mu-site-' + siteID, sidebarList);

        $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_connect_single_site', siteID: siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                $('.wpc-ic-mu-list-checkbox input[type="checkbox"]',row).attr('data-status', 'connected');
                $(sidebarItem).removeClass('wps-ic-mu-not-connected').addClass('wps-ic-mu-connected');
                $('.wps-ic-mu-single-site-connecting-loading').hide();
                $(content).html(response.data).show();

                // Question tooltips
                $('.ic-tooltip').tooltipster({
                    maxWidth:'300'
                });
            }
            else {
                // Error
                alert('Your token is invalid!');
            }
        });

        return false;
    });


    function CustomCnameClose() {
        var popup = $('.custom-cname-popup');
        var save = $('[name="save"]', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var top = $('.cdn-popup-top', popup);
        var steps = $('.custom-cdn-steps', popup);
        var step_1 = $('.custom-cdn-step-1', steps);
        var step_2 = $('.custom-cdn-step-2', steps);
        var step_1_retry = $('.custom-cdn-step-1-retry', steps);
        var step_2_img = $('.custom-cdn-step-2-img', steps);

        $(step_1).show();
        $(step_2).hide();
        $(step_1_retry).hide();
    }

    function CustomCname(popup_modal) {
        var popup = $('.swal2-container #cdn-popup-inner-cname');
        var popupData = $('.swal2-container .custom-cname-popup');
        var save = $('[name="save"]', popup);
        var cant_see = $('.btn-i-cant-see', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var top = $('.cdn-popup-top', popup);
        var steps = $('.custom-cdn-steps', popup);
        var step_1 = $('.custom-cdn-step-1', steps);
        var step_2 = $('.custom-cdn-step-2', steps);
        var step_2_img = $('.custom-cdn-step-2-img', steps);
        var step_1_retry = $('.custom-cdn-step-1-retry', steps);
        var configure = $('.setting-configure');
        var configured = $('.setting-configured');
        var cname_enabled = $('.cname-enabled');
        var cname_disabled = $('.cname-disabled');
        var label_enabled = $('.label-enabled');
        var label_disabled = $('.label-disabled');
        var cname_configured = $('.cname-configured');


        $(save).on('click', function (e) {
            e.preventDefault();
            $(top).hide();
            $(content).hide();
            $(loading).show();

            var cname_field = $('[name="custom-cdn"]', popupData).val();

            if (cname_field == '') {
                //wps-ic-mu-popup-empty-cname
                WPCSwal.fire({
                    title: '', position: 'center', html: jQuery('.wps-ic-mu-popup-empty-cname').html(), width: 600, showCloseButton: true, showCancelButton: false, showConfirmButton: false, allowOutsideClick: true, customClass: {
                        container: 'no-padding-popup-bottom-bg switch-legacy-popup',
                    }, onOpen: function () {

                    }, onClose: function () {
                        openConfigurePopup(popup_modal);
                    }
                });
                return false;
            }

            $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_cname_add', cname: cname_field, siteID: wpc_siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                $(top).show();
                $(step_1_retry).hide();

                if (response.success) {
                    setTimeout(function () {
                        $(loading).hide();
                        $(content).show();

                        $(cname_disabled).hide();
                        $(label_disabled).hide();
                        $(cname_enabled).show();
                        $(label_enabled).show();
                        $(configure).hide();
                        $(configured).show();
                        $(step_1).hide();
                        $(step_2_img).attr('src', response.data.image);

                        setTimeout(function(){
                            $(step_2).show();
                            $(cname_configured).html(response.data.configured).show();
                            $('.btn-close').on('click', function (e) {
                                e.preventDefault();
                                WPCSwal.close();
                                return false;
                            });
                        }, 1000);

                    }, 4000);
                }
                else {
                    $(loading).hide();
                    $(content).show();

                    $(cname_enabled).hide();
                    $(label_enabled).hide();
                    $(cname_configured).html('').hide();
                    $(cname_disabled).show();
                    $(label_disabled).show();
                    $(configure).show();
                    $(configured).hide();
                    $(step_1).show();

                    if (response.data == 'invalid-dns-prop') {
                        $('.custom-cdn-error-message', popup).html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> Seems like DNS is not set correctly...');
                    } else if (response.data == 'dns-api-not-working') {
                        $('.custom-cdn-error-message', popup).html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> Seems like DNS API is not working, please contact support...');
                    } else {
                        $('.custom-cdn-error-message', popup).html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> This domain is invalid, please link a new domain...');
                    }

                    $('.custom-cdn-error-message', popup).show();
                    $(step_2).hide();
                    $(step_1_retry).hide();
                }
            });
        });

        $(cant_see).on('click', function (e) {
            e.preventDefault();

            var configure = $('.setting-configure');
            var configured = $('.setting-configured');

            $(configure).show();
            $(configured).hide();

            $(loading).show();
            $(content).hide();


            $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_cname_retry', siteID: wpc_siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                $(top).hide();
                $(content).hide();
                $(loading).show();
                $('h4', loading).show();

                if (response.success) {
                    $(loading).hide();
                    $(content).show();

                    $(cname_disabled).hide();
                    $(label_disabled).hide();
                    $(cname_enabled).show();
                    $(label_enabled).show();
                    $(configure).hide();
                    $(configured).show();
                    $(step_1).hide();
                    $(step_2_img).attr('src', response.data.image);

                    setTimeout(function(){
                        $(step_2).show();
                        $(cname_configured).html(response.data.configured).show();
                        $('.btn-close').on('click', function (e) {
                            e.preventDefault();
                            WPCSwal.close();
                            return false;
                        });
                    }, 1000);

                } else {
                    $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_remove_cname',siteID: wpc_siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                        if (response.success) {
                            $(loading).hide();
                            $(content).show();
                            $(cname_enabled).hide();
                            $(cname_disabled).show();
                            $(step_1_retry).show();
                            $(step_1).hide();
                            $(step_2).hide();
                        }
                    });
                }
            });
        });

    }

    function RemoveCustomCname() {
        var popup = $('.remove-cname-popup');
        var popupData = $('.swal2-container .remove-cname-popup');
        var save = $('[name="save"]', popup);
        var cant_see = $('.btn-i-cant-see', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var top = $('.cdn-popup-top', popup);
        var steps = $('.custom-cdn-steps', popup);
        var step_1 = $('.custom-cdn-step-1', steps);
        var step_2 = $('.custom-cdn-step-2', steps);
        var step_2_img = $('.custom-cdn-step-2-img', steps);
        var step_1_retry = $('.custom-cdn-step-1-retry', steps);
        var configure = $('.setting-configure');
        var configured = $('.setting-configured');
        var cname_enabled = $('.cname-enabled');
        var cname_disabled = $('.cname-disabled');
        var label_enabled = $('.label-enabled');
        var label_disabled = $('.label-disabled');

        $(loading).show();
        $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_remove_cname', siteID: wpc_siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                $(configure).show();
                $(configured).hide();
                $(cname_enabled).hide();
                $(label_enabled).hide();
                $(cname_disabled).show();
                $(label_disabled).show();
                WPCSwal.close();
            }
        });
    }

    function ExcludeList() {
        var popup = $('.exclude-list-popup');
        var popupData = $('.swal2-container .exclude-list-popup');
        var save = $('.btn-save', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var top = $('.cdn-popup-top', popup);
        $(save).on('click', function (e) {
            e.preventDefault();
            $(top).hide();
            $(content).hide();
            $(loading).show();

            var excludeList = $('[name="exclude-list-textarea"]', popupData).val();
            var lazyExcludeList = $('[name="exclude-lazy-textarea"]', popupData).val();
            var delayExcludeList = $('[name="delay-js-exclude-list-textarea"]', popupData).val();

            $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_exclude_list', excludeList: excludeList, lazyExcludeList:lazyExcludeList, delayExcludeList: delayExcludeList, siteID: wpc_siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                if (response.success) {
                    $('.exclude-list-textarea-value').text(excludeList);
                    $('.exclude-lazy-textarea-value').text(lazyExcludeList);
                    $('.delay-js-exclude-list-textarea-value').text(delayExcludeList);
                    $(top).show();
                    $(content).show();
                    $(loading).hide();
                    WPCSwal.close();
                }
            });

            return false;
        });
    }

    function GeoLocation() {
        var popup = $('.swal2-container .geo-location-popup');
        var save = $('.btn-save-location', popup);
        var find = $('.btn-i-dont-know', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var top = $('.cdn-popup-top', popup);

        $(save).on('click', function (e) {
            e.preventDefault();
            $(top).hide();
            $(content).hide();
            $(loading).show();
            $(overlay).show();

            $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_geolocation_force', location: $('select[name="location-select"]', popup).val(), siteID: wpc_siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                if (response.success) {

                    var continent = response.data.continent;
                    var country_name = response.data.country_name;

                    $('select[name="location-select"] option').removeAttr('selected');
                    $('select[name="location-select"] option[value="' + continent + '"]').attr('selected', 'selected');
                    $('select[name="location-select"]').val(continent);

                    $('.wpc-dynamic-text', popup).html('We have detected that your server is located in ' + country_name + ' (' + continent + '), if that\'s not correct, please select the nearest region below.');

                    $.post(wpc_ajaxVar.ajaxurl, {action: 'mu_get_site_settings', siteID: wpc_siteID,wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                        var content = $('.wp-compress-mu-content-inner');

                        window.location.hash = '#mu-' + wpc_siteID;
                        $(content).html(response.data);

                        setTimeout(function () {
                            $(overlay).hide();
                            $(overlayForm).hide();
                            $(siteContainer).show();
                            $(content).show();
                            // Question tooltips
                            $('.ic-tooltip').tooltipster({
                                maxWidth:'300'
                            });
                            // Tooltip Custom
                            $('.setting-value.ic-custom-tooltip').tooltipster({
                                maxWidth: '235',
                                position: 'left'
                            });
                            $('.setting-label', '.setting-option').hover(function (e) {
                                var parent = $(this).parent();
                                $('.setting-value.ic-custom-tooltip', parent).tooltipster('show');
                            });

                            $('.setting-label', '.setting-option').mouseleave(function (e) {
                                var parent = $(this).parent();
                                $('.setting-value.ic-custom-tooltip', parent).tooltipster('hide');
                            });
                            //$(loadingContainer).hide();
                        }, 500);
                    });

                    // OK
                    $(top).hide();
                    //$(content).show();
                    $(loading).hide();
                    WPCSwal.close();

                    //window.location.reload();
                }
                else {
                    // Error Popup
                }
            });

            return false;
        });

        $(find).on('click', function (e) {
            e.preventDefault();
            $(top).hide();
            $(content).hide();
            $(loading).show();

            $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_geolocation',wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                console.log(response.data);
                if (response.success) {
                    var continent = response.data.continent;
                    var country_name = response.data.country_name;
                    $('select[name="location-select"] option').removeAttr('selected');
                    $('select[name="location-select"] option[value="' + continent + '"]').attr('selected', 'selected');
                    $('select[name="location-select"]').val(continent);

                    $('.wpc-dynamic-text', popup).html('We have detected that your server is located in ' + country_name + ' (' + continent + '), if that\'s not correct, please select the nearest region below.');

                    // OK
                    $(top).show();
                    $(content).show();
                    $(loading).hide();
                }
                else {
                    // Error Popup
                }
            });

            return false;
        });
    }


    // On page load get hash
    function hashLoad() {
        var hash = window.location.hash;
        if (hash !== '') {
            hash = hash.replace('#mu-', '');
            jQuery('.wp-compress-mu-site-list li a.wp-mu-site-' + hash + '').trigger('click');
        }
    }

    hashLoad();

});