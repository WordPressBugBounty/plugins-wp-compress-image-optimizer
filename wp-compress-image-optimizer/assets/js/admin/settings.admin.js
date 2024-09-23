jQuery(document).ready(function ($) {

    var tooltips = 0;




    /*
     * Circle
     */
    $('#circle-big').circleProgress({
        size: 120,
        startAngle:-Math.PI / 6 * 3,
        lineCap:'round',
        thickness:'3',
        fill: {
            gradient: ["#1c87f1", "#3c4cdf"],
            gradientAngle: Math.PI / 7
        }
    });

    /**
     * Question tooltips
     */
    $('.ic-tooltip').tooltipster({
        maxWidth:'300'
    });

    $('.button-primary.button-disabled').on('click', function (e) {
       e.preventDefault();

       return false;
    });

    /**
     * @since 3.3.0
     */
    if ($('.wps-ic-trigger_connect').length) {
        var link = $('.wps-ic-authorize-api').attr('href');
        $.ajaxSetup({async: false, cache: false});
        $.post(ajaxurl, {action: 'wps_ic_authorize_api'}, function (response) {
            if (response.success) {
                window.location.href = link;
            } else {
                alert(response.data);
            }
        });
    }


    /**
     * Enable Tooltips
     */
    $('.button-tooltips').on('click', function (e) {
        e.preventDefault;

        var link = $(this);
        var tooltips_on = $(this).data('tooltips');

        if (tooltips_on == '0') {
            // Turn  it on
            $('span', link).html('On');
            tooltips = 1;
        } else {
            $('span', link).html('Off');
            tooltips = 0;
        }

        return false;
    });


    /**
     * Activate Form
     */
    var ajax_run = true;

    $('form#wps_ic_activate_form').submit(function (e) {
        e.preventDefault();

        var error = false;
        var form = $(this);
        var inner = $('.wps_ic_activate_form', form);
        var loading = $('.wps-ic-form-loading-container');

        var apikey = $('input[name="apikey"]', form).val();
        var fail_test = $('input[name="fail_test"]', form).val();

        if (apikey == '') {
            $('input[name="apikey"]', form).addClass('ic_required');
            error = true;
        }

        if (error == false) {

            $(form).hide(function () {
                $(loading).show();

                Swal.fire({
                    title: '',
                    html: $('#wps-ic-connection-tests').html(),
                    showConfirmButton: false,
                    showCloseButton: false,
                    allowOutsideClick: false,
                    onOpen: function () {
                        jQuery('body').on('click', '.wps-ic-swal-close', function (e) {
                            e.preventDefault();

                            swal.close();
                        });
                    }
                });

                $.post(ajaxurl, {action: 'wps_ic_api_test', test_id: 'verify_api_key', apikey: apikey, fail_test: fail_test}, function (response) {
                    if (response.success == true) {

                        test_finished(form, loading, 'verify_api_key', 'success');

                        if (run_Ajax()) {
                            $.post(ajaxurl, {action: 'wps_ic_api_test', test_id: 'communication_with_portal', fail_test: fail_test}, function (response) {
                                if (response.success == true) {

                                    test_finished(form, loading, 'communication_with_portal', 'success');

                                    if (run_Ajax()) {
                                        $.post(ajaxurl, {action: 'wps_ic_api_test', test_id: 'image_compress', fail_test: fail_test}, function (response) {
                                            if (response.success == true) {

                                                test_finished(form, loading, 'image_compress', 'success');

                                                if (run_Ajax()) {
                                                    $.post(ajaxurl, {action: 'wps_ic_api_test', test_id: 'image_restore', fail_test: fail_test}, function (response) {
                                                        if (response.success == true) {

                                                            test_finished(form, loading, 'image_restore', 'success');

                                                            if (run_Ajax()) {
                                                                $.post(ajaxurl, {action: 'wps_ic_api_connect', apikey: apikey, fail_test: fail_test}, function (response) {
                                                                    if (response.success == true) {

                                                                        test_finished(form, loading, 'finalization', 'success');

                                                                        $(form).hide();
                                                                        $(loading).hide();
                                                                        /*var swal = $('#swal2-content');*/
                                                                        swal.close();

                                                                        Swal.fire({
                                                                            title: '',
                                                                            html: $('#wps-ic-connection-tests-done').html(),
                                                                            showConfirmButton: false,
                                                                            showCloseButton: false,
                                                                            allowOutsideClick: false,
                                                                            onOpen: function () {
                                                                                jQuery('body').on('click', '.wps-ic-swal-close', function (e) {
                                                                                    e.preventDefault();

                                                                                    swal.close();
                                                                                });
                                                                            }
                                                                        });

                                                                    } else {
                                                                        test_finished(form, loading, 'finalization', 'failed');
                                                                    }
                                                                });
                                                            }

                                                        } else {
                                                            test_finished(form, loading, 'image_restore', 'failed', response.data.code);
                                                        }
                                                    });
                                                }

                                            } else {
                                                test_finished(form, loading, 'image_compress', 'failed', response.data.code);
                                            }
                                        });
                                    }

                                } else {
                                    test_finished(form, loading, 'communication_with_portal', 'failed', response.data.code);
                                }
                            });
                        }
                    } else {
                        test_finished(form, loading, 'verify_api_key', 'failed', response.data.code);
                    }

                });

            });

        }

        return false;
    });

    function run_Ajax() {
        if (ajax_run == false) {
            console.log('Ajax Canceled');
            return false;
        } else {
            return true;
        }
    }


    function test_finished(form, loading, test, status, message) {
        var swalcontainer = $('#swal2-content');
        var msg = '';
        var title = '';

        if (status == 'failed') {
            $(loading).hide();
            $(form).show();
            $('.wps-ic-swal-close', swalcontainer).attr('value', 'Close');


            $('ul>li[data-test="' + test + '"] span', swalcontainer).removeClass('running').addClass('failed');
            $('ul>li[data-test="' + test + '"] span', swalcontainer).removeClass('fa-dot-circle').addClass('fa-check');

            if (test == 'verify_api_key') {
                msg = 'Your api key does not match our records.';
                title = 'API Key Validation';
            } else if (test == 'communication_with_portal') {
                msg = 'Your site cannot communicate with the portal.';
                title = 'Portal Communication';
            } else if (test == 'image_compress') {

                if (message == 'unable_upload') {
                    msg = 'We were not able to upload & optimize a test image.';
                } else if (message == 'unable_compress') {
                    msg = 'We were not able to compress the test image.';
                } else if (message == 'no_attachments') {
                    msg = 'We did not find any attachments to compress.';
                }

                title = 'Image Compress';
            } else if (test == 'image_restore') {

                if (message == 'unable_restore') {
                    msg = 'We were not able to restore the test image.';
                }

                title = 'Image Restore';
            }

            swal.close();

            $('ul>li', '#wps-ic-test-error').html('<span class="fas"></span> ' + title);
            $('ul>li span', '#wps-ic-test-error').addClass('failed');
            $('ul>li span', '#wps-ic-test-error').addClass('fa-times');
            $('.ic-error-msg', '#wps-ic-test-error').html(msg);

            Swal.fire({
                title: '',
                html: $('#wps-ic-test-error').html(),
                showConfirmButton: false,
                showCloseButton: true,
                allowOutsideClick: false,
                onOpen: function () {
                    jQuery('body').on('click', '.wps-ic-swal-close', function (e) {
                        e.preventDefault();
                        window.location.reload();
                        swal.close();
                    });

                },
                onClose: function () {
                    window.location.reload();
                }
            });

        } else {
            $('ul>li[data-test="' + test + '"] span', swalcontainer).removeClass('running').addClass('success');
            $('ul>li[data-test="' + test + '"] span', swalcontainer).removeClass('fa-dot-circle').addClass('fa-check');
        }
    }


    /**
     * Authorize with remote API
     * @since 3.3.0
     */
    $('.wps-ic-authorize-api').on('click', function (e) {

        $.ajaxSetup({async: false, cache: false});
        $.post(ajaxurl, {action: 'wps_ic_authorize_api'}, function (response) {
            if (response.success) {
                window.location.reload();
            } else {
                alert(response.data);
            }
        });

    });


    /**
     * Deauthorize with remote API
     * @since 3.3.0
     */
    $('.wps-ic-deauthorize-api').on('click', function (e) {

        $.ajaxSetup({async: false, cache: false});
        $.post(ajaxurl, {action: 'wps_ic_deauthorize_api'}, function (response) {
            if (response.success) {
                window.location.reload();
            } else {
                alert(response.data);
            }
        });

    });



    /**
     * Change Optimization
     */
    $('.wps-ic-change-optimization').on('click', function (e) {
        e.preventDefault();

        var link = $(this);
        var value = $(link).data('optimization_level');

        $('input#wp-ic-setting-optimization').attr('value', value);

        $.post(ajaxurl, {action: 'wps_ic_settings_change', what: 'optimization', value: value}, function (response) {
            if (response.success) {
                // Nothing
            } else {
                alert('Oops! We weren\'t able to save your settings! :(');
            }
        });
    });

    /**
     * Change Backup Location
     */
    $('.wps-ic-change-backup-location').on('click', function (e) {
        e.preventDefault();

        var link = $(this);
        var value = $(link).data('backup-location');

        $('input#wp-ic-backup-location').attr('value', value);

        $.post(ajaxurl, {action: 'wps_ic_settings_change', what: 'backup-location', value: value}, function (response) {
            if (response.success) {
                // Nothing
            } else {
                alert('Oops! We weren\'t able to save your settings! :(');
            }
        });
    });


    /**
     * Change Otto
     */
    $('.wps-ic-change-otto').on('click', function (e) {
        e.preventDefault();

        saving_settings = true;
        var link = $(this);
        var value = $(link).data('otto');

        $('input#wp-ic-setting-otto').attr('value', value);

        $.post(ajaxurl, {action: 'wps_ic_settings_change', what: 'otto', value: value}, function (response) {
            if (response.success) {
                // Nothing
                saving_settings = false;
            } else {
                alert('Oops! We weren\'t able to save your settings! :(');
            }
        });
    });


    /**
     * Change Setting
     */
    $('.wps-ic-change-setting').on('click', function (e) {

        var input = $(this);
        var setting = $(input).data('setting_name');
        var value = $(input).data('setting_value');
        var checked = 0;

        if (setting != 'thumbnails') {

            if ($(input).is(':checkbox')) {
                if ($(input).is(':checked')) {
                    var value = '1';
                    var checked = $(input).is(':checked');
                    $(input).addClass('active');
                    $(input).attr('checked', 'checked');
                } else {
                    var value = '0';
                    var checked = $(input).is(':checked');
                    $(input).removeClass('active');
                    $(input).removeAttr('checked');
                }
            }

        } else {

            var checked = $(input).is(':checked');
            value = $(input).data('setting_value');

        }

        $.post(ajaxurl, {action: 'wps_ic_settings_change', what: setting, value: value, checked: checked}, function (response) {
            if (response.success) {
                // Nothing
            } else {
                alert('Oops! We weren\'t able to save your settings! :(');
            }
        });

    });


    /**
     * GDPR
     */
    $('.wps-ic-change-gdpr').on('click', function (e) {


        var input = $(this);
        var setting = $(input).data('setting_name');
        var value = $(input).data('setting_value');

        if ($(input).is(':checkbox')) {
            if ($(input).is(':checked')) {
                $(input).addClass('active');
                $(input).attr('checked', 'checked');

                $('.subscribe_btn').removeClass('disabled');
                $('.subscribe_btn').removeAttr('disabled');
            } else {

                $(input).removeClass('active');
                $(input).removeAttr('checked');

                $('.subscribe_btn').addClass('disabled');
                $('.subscribe_btn').attr('disabled', 'disabled');
            }
        }


    });

    $('.wpc-live-btn').on('click', function(e){
        e.preventDefault();
        
        var btn = $(this);
        if ($(btn).hasClass('wpc-disabled-option')) {
            return false;
        }

        var url = this.href;
        var form = $('<form action="' + url + '" method="post">' +
            '<input type="text" name="set_optimization" value="live" />' +
            '</form>');
        $('body').append(form);
        form.submit();
    });

    $('.wpc-local-btn').on('click', function(e){
        e.preventDefault();

        var btn = $(this);
        if ($(btn).hasClass('wpc-disabled-option')) {
            return false;
        }

        var url = this.href;
        var form = $('<form action="' + url + '" method="post">' +
            '<input type="text" name="set_optimization" value="local" />' +
            '</form>');
        $('body').append(form);
        form.submit();
    });

});