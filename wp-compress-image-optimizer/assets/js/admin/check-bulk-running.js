jQuery(document).ready(function ($) {

    function fetchRestoreData() {
        $('.bulk-area-inner').show();
        $('.wps-ic-stop-bulk-restore').show();
        $('#bulk-start-container').hide();
        $('.bulk-preparing-restore').show();
        $('.bulk-compress-status-progress-prepare').hide();

        lastProgress = 0;
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_bulkRestoreHeartbeat',
                lastProgress: lastProgress
            },
            success: function (response) {


                if (response.data.status == 'done') {
                    $('.wps-ic-stop-bulk-restore').hide();
                    $('.wps-ic-stop-bulk-compress').hide();
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

        bulkRestoreHeartbeat();
    }

    function fetchCompressData() {
        $('.wps-ic-stop-bulk-compress').show();
        $('.bulk-area-inner').show();
        $('#bulk-start-container').hide();
        $('.bulk-preparing-optimize').show();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {action: 'wps_ic_bulkCompressHeartbeat'},
            error: function(response) {
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
                Swal.fire({
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
            },
            success: function (response) {

                if (response.data.status != 'done' && response.data.status != 'parsing') {
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

                    var progress = $('.bulk-compress-status-progress');
                    var compressedImages = $('.bulk-images-compressed>div.data', progress);
                    var compressedThumbs = $('.bulk-thumbs-compressed>div.data', progress);
                    var totalSavings = $('.bulk-total-savings>div.data', progress);
                    var thumbSavings = $('.bulk-thumbs-savings>div.data', progress);
                    var avgReduction = $('.bulk-avg-reduction>div.data', progress);

                    $(compressedImages).html(response.data.progressCompressedImages);
                    $(compressedThumbs).html(response.data.progressCompressedThumbs);
                    $(totalSavings).html(response.data.progressTotalSavings);
                    $(avgReduction).html(response.data.progressAvgReduction);
                    $(progress).show();

                } else {
                    clearInterval(heartbeatBulkCompress);
                    var bulkFinished = $('.bulk-finished');

                    setTimeout(function () {
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wps_ic_getBulkStats',
                                type: 'compress',
                                in:'fetchData'
                            },
                            success: function (response) {
                                $('.bulk-preparing-optimize').hide();
                                $('.bulk-preparing-restore').hide();

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

        bulkCompressHeartbeat();
    }

    function fetchingBulkData() {
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_isBulkRunning'
            },
            success: function (response) {

                if (response.data == 'not-running') {
                    // Not running
                    console.log('WPC Bulk is Not Running');
                } else {
                    // Bulk is Running
                    console.log('Bulk is Running');
                    if (response.data == 'compressing') {
                        fetchCompressData();
                    } else {
                        fetchRestoreData();
                    }
                }


            }
        });


    }

    //bulkCompressHeartbeat();
    fetchingBulkData();

    var lastProgress = 0;
    function bulkRestoreHeartbeat() {
        var heartbeatBulkRestore = setInterval(function(){
            console.log('da');
            $.ajax({
                url: ajaxurl, type: 'POST', data: {action: 'wps_ic_bulkRestoreHeartbeat', lastProgress:lastProgress}, success: function (response) {


                    if (response.data.status == 'done') {
                        $('.wps-ic-stop-bulk-restore').hide();
                        $('.wps-ic-stop-bulk-compress').hide();

                        var bulkFinished = $('.bulk-finished');

                        setTimeout(function(){
                            $.ajax({
                                url: ajaxurl, type: 'POST', data: {action: 'wps_ic_getBulkStats', type: 'compress', in:'bulkRestore'}, success: function (response) {
                                    $('.bulk-preparing-optimize').hide();
                                    $('.bulk-preparing-restore').hide();

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
                    }
                    else {
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
        }, 8000);
    }



    function bulkCompressHeartbeat() {
        var heartbeatBulkCompress = setInterval(function(){
            $.ajax({
                url: ajaxurl, type: 'POST', data: {action: 'wps_ic_bulkCompressHeartbeat'}, success: function (response) {

                    if (response.data.status != 'done' && response.data.status != 'parsing') {
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

                        updateCompressStatusProgressCount(response.data);

                        // Modify Design
                        //clearInterval(heartbeatBulkCompress);

                    } else {
                        clearInterval(heartbeatBulkCompress);
                        var bulkFinished = $('.bulk-finished');

                        setTimeout(function(){
                            $.ajax({
                                url: ajaxurl, type: 'POST', data: {action: 'wps_ic_getBulkStats', type: 'compress', in:'bulkCompress'}, success: function (response) {
                                    $('.bulk-preparing-optimize').hide();
                                    $('.bulk-preparing-restore').hide();

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

});