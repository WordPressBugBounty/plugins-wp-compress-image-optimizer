jQuery(document).ready(function ($) {


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

    function CustomCname() {
        var popup = $('.swal2-container .custom-cname-popup');
        var popupData = $('.swal2-container .custom-cname-popup');
        var form = $('form', popup);
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
            var cname_field = $('[name="custom-cdn"]', popupData).val();

            if (cname_field == '') {
                //wps-ic-mu-popup-empty-cname
                $('[name="custom-cdn"]', popupData).addClass('empty');
                $(form).prepend('<p class="error">You must fill out the CNAME.</p>');
                return false;
            }

            $(top).hide();
            $(content).hide();
            $(loading).show();

            $('h4', loading).show();

            $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_cname_add', cname: cname_field, wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                $(top).show();
                $(step_1_retry).hide();
                $('h4', loading).hide();

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
                    //$(step_2_img).attr('src', response.data.image);
                    $('.check-cdn-link', step_2).attr('href', response.data.image);

                    $('.wpc-dns-error-text', step_2).hide();

                    $(step_2).show();
                    countdown = 6;
                    $('.btn-i-cant-see', step_2).addClass('disabled');

                    var btnCountdown = setInterval(function() {
                        countdown--;
                        if (countdown==0) {
                            $('.btn-i-cant-see', step_2).html('I can\'t see the above image').removeClass('disabled');
                            clearInterval(btnCountdown);
                        } else {
                            $('.btn-i-cant-see', step_2).html('I can\'t see the above image (' + countdown + ')');
                        }
                    }, 1100);

                    setTimeout(function () {
                        $(cname_configured).html(response.data.configured).show();

                        $('.btn-close').on('click', function (e) {
                            e.preventDefault();
                            WPCSwal.close();
                            return false;
                        });
                    }, 1000);
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
                        $('.wpc-dns-error-text', step_2).addClass('custom-cdn-error-message').show();
                        $('.custom-cdn-error-message', popup).html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> Seems like DNS is not set correctly...');
                    }
                    else if (response.data == 'dns-api-not-working') {
                        $('.custom-cdn-error-message', popup).html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> Seems like DNS API is not working, please contact support...');
                    }
                    else {
                        $('.custom-cdn-error-message', popup).html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> This domain is invalid, please link a new domain...');
                    }

                    //$('.wpc-dns-error-text', popup).show();
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


            $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_cname_retry', wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
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

                    setTimeout(function () {
                        $(step_2).show();
                        $(cname_configured).html(response.data.configured).show();
                        $('.btn-close').on('click', function (e) {
                            e.preventDefault();
                            WPCSwal.close();
                            return false;
                        });
                    }, 1000);

                }
                else {
                    $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_remove_cname', wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
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

            return false;
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
        $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_remove_cname', wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
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


    $('.wps-ic-configure-popup').on('click', function (e) {
        e.preventDefault();

        if ($(this).hasClass('LockedTooltip')) {
            return false;
        }

        var popupID = $(this).data('popup');
        var popupWidth = $(this).data('popup-width');


        WPCSwal.fire({
            title: '', html: jQuery('#' + popupID).html(), width: popupWidth, showCloseButton: true, showCancelButton: false, showConfirmButton: false, allowOutsideClick: false, customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup',
            }, onOpen: function () {

                if (popupID == 'custom-cdn') {
                    CustomCname();
                }
                else if (popupID == 'remove-custom-cdn') {
                    RemoveCustomCname();
                }
                else {
                    var popup = $('.swal2-container .ajax-settings-popup');
                    var form = $('form', popup);
                    var loading = $('.cdn-popup-loading', popup);
                    var content = $('.cdn-popup-content', popup);

                    $('input[type="text"],textarea', form).each(function (i, item) {
                        var settingName = $(item).data('setting-name');
                        var settingSubset = $(item).data('setting-subset');

                        $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_get_setting', name: settingName, subset: settingSubset, wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                            $(content).show();
                            $(loading).hide();
                            $(item).val(response.data.value);

                            if (response.data.exclude_third == '1') {
                                $('.wps-exclude-third', form).prop("checked", true);
                            } else {
                                $('.wps-exclude-third', form).prop("checked", false);
                            }


                            if (response.data.default_excludes == '1') {
                                $('.wps-default-excludes', form).prop("checked", true);
                            } else {
                                $('.wps-default-excludes', form).prop("checked", false);
                            }

                            if (response.data.exclude_themes == '1') {
                                $('.wps-exclude-themes', form).prop("checked", true);
                            } else {
                                $('.wps-exclude_themes', form).prop("checked", false);
                            }

                            if (response.data.exclude_plugins == '1') {
                                $('.wps-exclude-plugins', form).prop("checked", true);
                            } else {
                                $('.wps-exclude-plugins', form).prop("checked", false);
                            }

                            if (response.data.exclude_wp == '1') {
                                $('.wps-exclude-wp', form).prop("checked", true);
                            } else {
                                $('.wps-exclude-wp', form).prop("checked", false);
                            }
                        });

                    });

                    savePopup(popup);

                }
            }, onClose: function () {

            }
        });

        return false;
    });


    $('.btn-close').on('click', function (e) {
        e.preventDefault();
        WPCSwal.close();
        return false;
    });


    function savePopup(popup) {
        var save = $('.btn-save', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var form = $('.wpc-save-popup-data', popup);

        console.log(popup);
        console.log($('.wps-exclude-themes', popup).is(':checked'));

        $(save).on('click', function (e) {
            e.preventDefault();
            $(content).hide();
            $(loading).show();

            var default_enabled = '0';
            var exclude_themes = '0';
            var exclude_plugins = '0';
            var exclude_wp = '0';
            var exclude_third = '0';

            if( $('.wps-exclude-third', popup).is(':checked') ){
                exclude_third = 1;
            }

            if( $('.wps-default-excludes', popup).is(':checked') ){
                default_enabled = 1;
            }

            if( $('.wps-exclude-themes', popup).is(':checked') ){
                exclude_themes = 1;
            }
            if( $('.wps-exclude-plugins', popup).is(':checked') ){
                exclude_plugins = 1;
            }
            if( $('.wps-exclude-wp', popup).is(':checked') ){
                exclude_wp = 1;
            }

            var setting_group = $('input[type="text"],textarea', popup).data('setting-name');
            var setting_name = $('input[type="text"],textarea', popup).data('setting-subset');
            var excludes = $('.exclude-list-textarea-value', popup).val();

// Check if this is the lastLoadScript setting
            var deferScripts = '';
            if (setting_name === 'lastLoadScript') {
                // Get the value from defer scripts textarea
                deferScripts = $('.exclude-list-textarea-value-defer', popup).val();
            }

            console.log($('.exclude-list-textarea-value', popup).val());

            $.post(wpc_ajaxVar.ajaxurl, {
                action: 'wps_ic_save_excludes_settings',
                nonce: wpc_ajaxVar.nonce,
                group_name: setting_group,
                setting_name: setting_name,
                excludes: excludes,
                deferScript: deferScripts, // Add the deferScripts value to the POST data
                default_enabled: default_enabled,
                exclude_themes: exclude_themes,
                exclude_plugins: exclude_plugins,
                exclude_wp: exclude_wp,
                exclude_third: exclude_third,
            }, function(response) {
                if (response.success) {
                    WPCSwal.close();
                }
            });

            return false;
        });
    }

});