jQuery(document).ready(function ($) {

    /**
     * Hide After
     */
    $('.wps-ic-hide').each(function (i, item) {
        var after = $(item).data('after');
        setTimeout(function () {
            $(item).fadeOut(500);
        }, after * 1000);
    });

    /**
     * Activate Form
     */
    $('form#wps_ic_mu_activate_form').submit(function (e) {
        e.preventDefault();

        var error = false;
        var form = $(this);
        var inner = $('.wps_ic_activate_form', form);
        var loading = $('.wps-ic-form-loading-container');

        var apikey = $('input[name="apikey"]', form).val();

        if (apikey == '') {
            $('input[name="apikey"]', form).addClass('ic_required');
            error = true;
        }

        if (error == false) {

            $(form).hide(function () {
                $(loading).show();

                $.post(ajaxurl, {action: 'wps_ic_api_mu_connect', apikey: apikey}, function (response) {
                    if (response.success == true) {
                        $(form).hide();

                        var success = $('.wps-ic-form-success-container');
                        var msg = $('.wps-ic-form-connect');

                        $(loading).hide();

                        Swal.fire({
                            title: '',
                            text: 'You have successfully connected to the Compression Cloud!',
                            imageUrl: 'https://www.wpcompress.com/confirmed.png',
                            imageWidth: 180,
                            imageHeight: 180,
                            imageAlt: 'Custom image',
                            showConfirmButton: false,
                            showCloseButton: true
                        }).then(function (dismiss) {
                            window.location.reload();
                        });

                        setTimeout(function () {
                            window.location.reload();
                        }, 2000);

                    } else {

                        var success = $('.wps-ic-form-success-container');
                        var error = $('.wps-ic-form-error');


                        $(loading).hide();

                        Swal.fire({
                            title: '',
                            html: response.data.msg,
                            imageUrl: 'https://www.wpcompress.com/error.png',
                            imageWidth: 180,
                            imageHeight: 180,
                            imageAlt: 'Custom image',
                            showConfirmButton: false,
                            showCloseButton: true
                        }).then(function (dismiss) {
                        });

                        $(form).show();
                    }
                });
            });

        }

        return false;
    });


});