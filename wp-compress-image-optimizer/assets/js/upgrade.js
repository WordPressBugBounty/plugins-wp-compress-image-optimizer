jQuery(document).ready(function ($) {

    window.lockedPopup = function lockedPopup() {
        WPCSwal.fire({
            title: '',
            showClass: {
                popup: 'in'
            },
            html: jQuery('.wps-ic-lite-locked-form').html(),
            width: 900,
            position: 'center',
            customClass: {
                container: 'in',
                popup: 'wps-ic-lite-connect-popup'
            }, //customClass:'wps-ic-connect-popup',
            showCloseButton: false,
            showCancelButton: false,
            showConfirmButton: false,
            allowOutsideClick: true,
            onOpen: function () {

                $('.wps-ic-connect-retry').on('click', function (e) {
                    e.preventDefault();
                    lockedPopup();
                    return false;
                });

                var swal_container = $('.swal2-container');
                var form = $('#wps-ic-connect-form', swal_container);
                var submitBtn = $('.wps-ic-submit-btn', swal_container);

                $('.wps-ic-lite-input-container', swal_container).on('click', function () {
                    $('.wps-ic-lite-input-container', swal_container).removeClass('wpc-error');
                });


                var form_container = $('.wps-lite-form-container', swal_container);
                var success_message = $('.wps-ic-success-message-container', swal_container);
                var error_message_container = $('.wps-ic-error-message-container', swal_container);
                var error_message_text = $('.wps-ic-invalid-apikey', swal_container);
                var already_connected = $('.wps-ic-site-already-connected', swal_container);
                var success_message_text = $('.wps-ic-success-message-container-text', swal_container);
                var success_message_choice_text = $('.wps-ic-success-message-choice-container-text', swal_container);
                var success_message_buttons = $('.wps-ic-success-message-choice-container-text a', swal_container);
                var finishing = $('.wps-ic-finishing-container', swal_container);
                var loader = $('.wps-ic-loading-container', swal_container);
                var loaderLite = $('.wpc-loading-lite', swal_container);
                var tests = $('.wps-ic-tests-container', swal_container);
                var init = $('.wps-ic-init-container', swal_container);

                $('.wps-use-lite', swal_container).on('click', function(e){
                    e.preventDefault();
                    WPCSwal.close();
                    return false;
                });

                $('#wps-ic-connect-form', swal_container).on('submit', function (e) {

                    var nonce = $('input[name="nonce"]', swal_container).val();
                    var apikey = $('input[name="apikey"]', form_container).val();

                    if (apikey == '' || typeof apikey == "undefined") {
                        $('.wps-ic-lite-input-container', swal_container).addClass('wpc-error');
                        //$('.wps-ic-lite-input-field-error', swal_container).show();
                        return false;
                    }

                    $(already_connected).hide();
                    $(error_message_text).hide();
                    $(success_message_text).hide();
                    $(error_message_container).hide();
                    $(init, swal_container).hide();
                    $(form_container).hide();
                    $(loader).show();
                    $(loaderLite).hide();
                    $(tests).hide();

                    $.post(ajaxurl, {
                        action: 'wps_ic_live_connect',
                        //action: 'wps_lite_connect',
                        apikey: apikey,
                        nonce: nonce,
                        timeout: 60000
                    }, function (response) {
                        if (response.success) {
                            // Connect
                            $('.wps-ic-connect-inner').addClass('padded');
                            WPCSwal.close();
                            window.location.reload();
                        } else {
                            // Not OK
                            // msg = 'Your api key does not match our records.';
                            //                 title = 'API Key Validation';

                            if (response.data.msg == 'site-already-connected') {
                                $(already_connected).show();
                                $(error_message_container).show();
                                $(error_message_text).hide();
                                $(success_message_choice_text).hide();
                                $(success_message_text).hide();
                                $(success_message).hide();
                                $(loader).hide();
                                $(tests).hide();
                            } else {
                                $(error_message_text).show();
                                $(error_message_container).show();
                                $(success_message_text).hide();
                                $(success_message_choice_text).hide();
                                $(success_message).hide();
                                $(loader).hide();
                                $(tests).hide();
                            }

                            // $('.wps-ic-connect-retry', swal_container).bind('click');

                        }
                    });

                    return false;
                });

            }
        });
    }


    window.liteConnectPopup = function liteConnectPopup() {
        WPCSwal.fire({
            title: '',
            showClass: {
                popup: 'in'
            },
            html: jQuery('.wps-ic-lite-connect-form').html(),
            width: 900,
            position: 'center',
            customClass: {
                container: 'in',
                popup: 'wps-ic-lite-connect-popup'
            }, //customClass:'wps-ic-connect-popup',
            showCloseButton: false,
            showCancelButton: false,
            showConfirmButton: false,
            allowOutsideClick: true,
            onOpen: function () {

                $('.wps-ic-connect-retry').on('click', function (e) {
                    e.preventDefault();
                    liteConnectPopup();
                    return false;
                });

                var swal_container = $('.swal2-container');
                var form = $('#wps-ic-connect-form', swal_container);
                var submitBtn = $('.wps-ic-submit-btn', swal_container);

                $('.wps-ic-lite-input-container', swal_container).on('click', function () {
                    $('.wps-ic-lite-input-container', swal_container).removeClass('wpc-error');
                    //$('.wps-ic-lite-input-field-error', swal_container).fadeOut(500);
                });


                var form_container = $('.wps-lite-form-container', swal_container);
                var success_message = $('.wps-ic-success-message-container', swal_container);
                var error_message_container = $('.wps-ic-error-message-container', swal_container);
                var error_message_text = $('.wps-ic-invalid-apikey', swal_container);
                var already_connected = $('.wps-ic-site-already-connected', swal_container);
                var success_message_text = $('.wps-ic-success-message-container-text', swal_container);
                var success_message_choice_text = $('.wps-ic-success-message-choice-container-text', swal_container);
                var success_message_buttons = $('.wps-ic-success-message-choice-container-text a', swal_container);
                var finishing = $('.wps-ic-finishing-container', swal_container);
                var loader = $('.wps-ic-loading-container', swal_container);
                var loaderLite = $('.wpc-loading-lite', swal_container);
                var tests = $('.wps-ic-tests-container', swal_container);
                var init = $('.wps-ic-init-container', swal_container);


                $('.wps-use-lite').on('click', function (e) {
                    e.preventDefault();
                    WPCSwal.close();
                    return false;
                });


                $('#wps-ic-connect-form', swal_container).on('submit', function (e) {

                    var nonce = $('input[name="nonce"]', swal_container).val();
                    var apikey = $('input[name="apikey"]', form_container).val();

                    if (apikey == '' || typeof apikey == "undefined") {
                        $('.wps-ic-lite-input-container', swal_container).addClass('wpc-error');
                        //$('.wps-ic-lite-input-field-error', swal_container).show();
                        return false;
                    }

                    $(already_connected).hide();
                    $(error_message_text).hide();
                    $(success_message_text).hide();
                    $(error_message_container).hide();
                    $(init, swal_container).hide();
                    $(form_container).hide();
                    $(loader).show();
                    $(loaderLite).hide();
                    $(tests).hide();

                    $.post(ajaxurl, {
                        action: 'wps_ic_live_connect',
                        //action: 'wps_lite_connect',
                        apikey: apikey,
                        nonce: nonce,
                        timeout: 60000
                    }, function (response) {
                        if (response.success) {
                            // Connect
                            $('.wps-ic-connect-inner').addClass('padded');
                            WPCSwal.close();
                            window.location.reload();
                        } else {
                            // Not OK
                            // msg = 'Your api key does not match our records.';
                            //                 title = 'API Key Validation';

                            if (response.data.msg == 'site-already-connected') {
                                $(already_connected).show();
                                $(error_message_container).show();
                                $(error_message_text).hide();
                                $(success_message_choice_text).hide();
                                $(success_message_text).hide();
                                $(success_message).hide();
                                $(loader).hide();
                                $(tests).hide();
                            } else {
                                $(error_message_text).show();
                                $(error_message_container).show();
                                $(success_message_text).hide();
                                $(success_message_choice_text).hide();
                                $(success_message).hide();
                                $(loader).hide();
                                $(tests).hide();
                            }

                            // $('.wps-ic-connect-retry', swal_container).bind('click');

                        }
                    });

                    return false;
                });

            }
        });
    }


    $('.wpc-add-access-key-btn,.wpc-add-access-key-btn-pro').on('click', function (e) {
        e.preventDefault();
        liteConnectPopup();
        return false;
    });


    $('.wpc-lite-locked-advanced').on('click', function (e) {
        e.preventDefault();
        lockedPopup();
        return false;
    });


});