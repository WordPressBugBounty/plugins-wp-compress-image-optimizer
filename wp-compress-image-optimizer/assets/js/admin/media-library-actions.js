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
     */
    var WPCHeartbeat = setInterval(function () {
        heartbeat();
    }, 8000);

    wpcHBCounter = 0;
    var heartbeat = function () {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wps_ic_media_library_heartbeat'},
            success: function (response) {
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

                    wpcHBCounter = 0;
                } else {
                    if (wpcHBCounter == 5) {
                        console.log('Removing WPC Heartbeat');
                        //clearInterval(WPCHeartbeat);
                    }
                    wpcHBCounter++;
                }
            }
        });
    };


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
     * Restore Live
     */
    $('body').on('click', '.wps-ic-restore-live', function (e) {

        e.preventDefault();

        var button = $(this);
        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var loading = $('.wps-ic-image-loading-' + attachment_id);

        $(parent).hide();
        $(loading).show();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_restore_live',
                attachment_id: attachment_id,
            },
            success: function (response) {
                heartbeat();
            },
            error: function (xhr, ajaxOptions, thrownError) {
                $(parent).show();
                $(loading).hide();

                // Failure Pop Up
                WPCSwal.fire({
                    title: '',
                    html: $('#' + response.data.msg).html(),
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
     * Compress Live
     */
    $('body').on('click', '.wps-ic-compress-live', function (e) {
        e.preventDefault();

        allowRefresh = false;
        var button = $(this);
        var attachment_id = $(button).data('attachment_id');
        var parent = $('.wps-ic-media-actions-' + attachment_id);
        var loading = $('.wps-ic-image-loading-' + attachment_id);

        $(parent).hide();
        $(loading).show();

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
                    } else {
                        heartbeat();
                    }
                } else {
                    $(loading).hide();
                    $(parent).html(response.data.html).show();

                    // Failure Pop Up
                    WPCSwal.fire({
                        title: '',
                        html: $('#' + response.data.msg).html(),
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


                }
            },
            error: function (xhr, ajaxOptions, thrownError) {
                allowRefresh = true;
                $(loading).hide();
                $(parent).html(response.data.html).show();
            }
        });

    });


});

window.onbeforeunload = function () {
    if (!allowRefresh) {
        return "Data will be lost if you leave the page, are you sure?";
    }
};