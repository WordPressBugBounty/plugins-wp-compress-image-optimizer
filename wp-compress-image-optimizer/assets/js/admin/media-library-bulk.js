jQuery(document).ready(function ($) {

    $('.wps-ic-stop-bulk-restore,.wps-ic-stop-bulk-compress').on('click', function (e) {
        e.preventDefault();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wps_ic_StopBulk'},
            success: function (response) {
                console.log(response.success);
                if (response.success == true) {
                    window.location.reload();
                }
            }
        });

        return false;
    });

    $('.button-start-bulk-restore').on('click', function (e) {
        e.preventDefault();

        $('.bulk-area-inner').show();
        $('.wps-ic-stop-bulk-restore').show();
        $('#bulk-start-container').hide();
        $('.bulk-preparing-restore').show();
        $('.bulk-compress-status-progress-prepare').hide();
        var nonce = ajaxVar.nonce

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wpc_ic_start_bulk_restore', nonce: nonce},
            success: function (response) {

                bulkRestoreHeartbeat();
            }
        });
        return false;
    });


    $('.button-start-bulk-compress').on('click', function (e) {
        e.preventDefault();

        $('.wps-ic-stop-bulk-compress').show();
        $('.bulk-area-inner').show();
        $('#bulk-start-container').hide();
        $('.bulk-preparing-optimize').show();
        var nonce = ajaxVar.nonce

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wpc_ic_start_bulk_compress', nonce: nonce},
            timeout: 100000,
            success: function (response) {

                if (response.success == true) {
                    bulkCompressHeartbeat();
                } else {

                    // Stop everything, show popup
                    $('.bulk-status-progress-bar').hide();
                    $('.wps-ic-stop-bulk-compress').hide();
                    $('.bulk-status-settings').hide();
                    $('.bulk-status').hide();
                    //
                    $('.wps-ic-stop-bulk-compress').hide();
                    $('.bulk-area-inner').hide();
                    $('#bulk-start-container').show();
                    $('.bulk-preparing-optimize').hide();

                    if (response.data.msg == '' || response.data.msg == null) {
                        response.data.msg = 'unknown-error';
                    }

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
            }
        });
        return false;
    });


    var lastProgress = 0;

    function bulkRestoreHeartbeat() {
        var heartbeatBulkRestore = setInterval(function () {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wps_ic_bulkRestoreHeartbeat',
                    lastProgress: lastProgress
                },
                success: function (response) {

                    if (response.success == false) {
                        clearInterval(heartbeatBulkRestore);

                        // Stop everything, show popup
                        $('.bulk-status-progress-bar').hide();
                        $('.wps-ic-stop-bulk-compress').hide();
                        $('.bulk-status-settings').hide();
                        $('.bulk-status').hide();
                        //
                        $('.wps-ic-stop-bulk-compress').hide();
                        $('.bulk-area-inner').hide();
                        $('#bulk-start-container').show();
                        $('.bulk-preparing-optimize').hide();

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

                        return;
                    }


                    if (response.data.status == 'parsing') {
                        // Nothing...
                    } else if (response.data.status == 'done') {
                        $('.wps-ic-stop-bulk-restore').hide();
                        $('.wps-ic-stop-bulk-compress').hide();

                        var bulkFinished = $('.bulk-finished');

                        setTimeout(function () {
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'wps_ic_getBulkStats',
                                    type: 'restore'
                                },
                                success: function (response) {
                                    $('.bulk-status-progress-bar').hide();
                                    $('.wps-ic-stop-bulk-compress').hide();
                                    $('.bulk-status-settings').hide();
                                    $('.bulk-status').fadeOut(600, function () {
                                        $(bulkFinished).hide().html(response.data.html).fadeIn(800);
                                    });
                                }
                            });
                        }, 500);

                        clearInterval(heartbeatBulkRestore);
                    } else {
                        $('.bulk-compress-status-progress-prepare').hide();
                        $('.bulk-preparing-placholders').hide();
                        $('.bulk-preparing-optimize').hide();
                        $('.bulk-preparing-restore').hide();
                        $('.bulk-status').html(response.data.html);
                        $('.bulk-restore-status-top-right>h3', '.wps-ic-bulk-html-wrapper').html(response.data.finished + ' / ' + response.data.total);
                        $('.bulk-restore-preview-image-holder img', '.wps-ic-bulk-html-wrapper').animate({opacity: 1});

                        var progress = $('.bulk-status-progress-bar', '.wps-ic-bulk-html-wrapper');
                        var progressBar = $('.progress-bar-inner', progress);

                        $(progress).show();
                        $(progressBar).css('width', response.data.progress + '%');
                        lastProgress = response.data.progress;

                        $('.bulk-status').show();
                    }


                }
            });
        }, 4000);
    }


    function bulkCompressHeartbeat() {
        var heartbeatBulkCompress = setInterval(function () {
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {action: 'wps_ic_bulkCompressHeartbeat'},

                success: function (response) {

                    if (response.success == false) {
                        clearInterval(heartbeatBulkCompress);

                        // Stop everything, show popup
                        $('.bulk-status-progress-bar').hide();
                        $('.wps-ic-stop-bulk-compress').hide();
                        $('.bulk-status-settings').hide();
                        $('.bulk-status').hide();
                        //
                        $('.wps-ic-stop-bulk-compress').hide();
                        $('.bulk-area-inner').hide();
                        $('#bulk-start-container').show();
                        $('.bulk-preparing-optimize').hide();

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

                        return;
                    }

                    if (response.data.status == 'parsing') {
                        // Nothing...
                    } else if (response.data.status != 'done') {
                        $('.bulk-compress-status-progress-prepare').hide();
                        $('.bulk-preparing-placholders').hide();
                        $('.bulk-preparing-optimize').hide();
                        $('.bulk-compress-status-progress-prepare').hide();
                        $('.bulk-status-settings').html(response.data.status).fadeIn(300);
                        $('.bulk-status').html(response.data.html);
                        $('.bulk-process-file-name').html(response.data.lastFileName);
                        //$('.bulk-process-status').html(response.data.progress + '%');
                        $('.wps-ic-bulk-before img', '.wps-ic-bulk-html-wrapper').animate({opacity: 1});
                        $('.wps-ic-bulk-after img', '.wps-ic-bulk-html-wrapper').animate({opacity: 1});
                        $('.bulk-status').fadeIn(300);

                        //updateStatusProgressBar(response.data.progress);
                        updateCompressStatusProgressCount(response.data);

                    } else {
                        clearInterval(heartbeatBulkCompress);
                        var bulkFinished = $('.bulk-finished');

                        setTimeout(function () {
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'wps_ic_getBulkStats',
                                    type: 'compress'
                                },
                                success: function (response) {
                                    $('.bulk-status-progress-bar').hide();
                                    $('.wps-ic-stop-bulk-compress').hide();
                                    $('.bulk-status-settings').hide();
                                    $('.bulk-status').fadeOut(600, function () {
                                        $(bulkFinished).hide().html(response.data.html).fadeIn(800);
                                    });
                                }
                            });
                        }, 1500);
                    }

                }
            });
        }, 5000);
    }


    function updateCompressStatusProgressCount(data) {
        var progress = $('.bulk-compress-status-progress');
        var compressedImages = $('.bulk-images-compressed>div.data', progress);
        var compressedThumbs = $('.bulk-thumbs-compressed>div.data', progress);
        var totalSavings = $('.bulk-total-savings>div.data', progress);
        var thumbSavings = $('.bulk-thumbs-savings>div.data', progress);
        var avgReduction = $('.bulk-avg-reduction>div.data', progress);

        $(compressedImages).html(data.progressCompressedImages);
        $(compressedThumbs).html(data.progressCompressedThumbs);
        $(totalSavings).html(data.progressTotalSavings);
        //$(thumbSavings).html(data.progressThumbsSavings);
        $(avgReduction).html(data.progressAvgReduction);
        $(progress).show();
    }


    function updateStatusProgressBar(progress_percent) {
        var progress = $('.bulk-status-progress-bar');
        var progressBar = $('.progress-bar-inner', '.bulk-status-progress-bar');
        $(progress).show();
        $(progressBar).css('width', progress_percent + '%');
    }

});