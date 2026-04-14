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
        $('.bulk-preparing-optimize').hide();
        $('.bulk-status').show();

        // Resume the queue-based compress flow
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
                                url: ajaxurl, type: 'POST', data: {action: 'wps_ic_getBulkStats', type: 'restore', in:'bulkRestore'}, success: function (response) {
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
        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 180000,
            data: {action: 'wps_ic_doBulkCompress', nonce: ajaxVar.nonce},
            success: function (response) {
                if (response.success == false) return;

                if (response.data.finished === true) {
                    var bulkFinished = $('.bulk-finished');
                    $.ajax({
                        url: ajaxurl, type: 'POST',
                        data: {action: 'wps_ic_getBulkStats', type: 'compress'},
                        success: function (r) {
                            $('.bulk-preparing-optimize').hide();
                            $('.bulk-status-progress-bar').hide();
                            $('.wps-ic-stop-bulk-compress').hide();
                            $('.bulk-status-settings').hide();
                            $('.bulk-status').fadeOut(600, function () {
                                $(bulkFinished).hide().html(r.data.html).fadeIn(800);
                            });
                        }
                    });
                    return;
                }

                $('.bulk-preparing-optimize').hide();
                $('.bulk-status').show();
                setTimeout(bulkCompressHeartbeat, 200);
            },
            error: function () {
                setTimeout(bulkCompressHeartbeat, 3000);
            }
        });
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