jQuery(document).ready(function ($) {

    //Simulate Logged out User
    $('body').on('click', '.wp-compress-view-as-visitor>a', function (e) {
        e.preventDefault();

        var url = new URL(window.location.href);
        url.searchParams.set('wpc_visitor_mode', 'true');
        url = url.toString();

        window.open(url);

    });


    // Purge HTML Cache
    $('body').on('click', '.wp-compress-bar-generate-critical-css>a', function (e) {
        e.preventDefault();

        var li = $('#wp-admin-bar-wp-compress');
        var old_html = $(li).html();
        $(li).html('<span class="wp-compress-admin-bar-icon"></span><span style="padding-left: 30px;">Generating Critical...</span>');

        $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_generate_critical_css',wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                $(li).html(old_html);
            }
            else {

            }
        });

        return false;
    });


    // Purge HTML Cache
    $('body').on('click', '.wp-compress-bar-purge-html-cache>a', function (e) {
        e.preventDefault();

        var li = $('#wp-admin-bar-wp-compress');
        var old_html = $(li).html();
        $(li).html('<span class="wp-compress-admin-bar-icon"></span><span style="padding-left: 30px;">Purging cache...</span>');

        $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_purge_html', wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                $(li).html(old_html);
                if ($('#optimizationTable').find('div').length > 0) {
                    location.reload();
                }
            }
            else {

            }
        });

        return false;
    });

    // Purge Critical Cache
    $('body').on('click', '.wp-compress-bar-purge-critical-css>a', function (e) {
        e.preventDefault();

        var li = $('#wp-admin-bar-wp-compress');
        var old_html = $(li).html();
        $(li).html('<span class="wp-compress-admin-bar-icon"></span><span style="padding-left: 30px;">Purging cache...</span>');

        $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_purge_critical_css', wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                $(li).html(old_html);
            }
            else {

            }
        });

        return false;
    });

    // Purge CDN Cache
    $('body').on('click', '.wp-compress-bar-clear-cache>a', function (e) {
        e.preventDefault();

        var li = $('#wp-admin-bar-wp-compress');
        var old_html = $(li).html();
        $(li).html('<span class="wp-compress-admin-bar-icon"></span><span style="padding-left: 30px;">Purging cache...</span>');

        $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_purge_cdn', wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                $(li).html(old_html);
            }
            else {

            }
        });

        return false;
    });


    // Preload Page
    $('body').on('click', '.wp-compress-bar-preload-cache>a', function (e) {
        e.preventDefault();

        var li = $('#wp-admin-bar-wp-compress');
        var old_html = $(li).html();
        $(li).html('<span class="wp-compress-admin-bar-icon"></span><span style="padding-left: 30px;">Preloading page...</span>');

        $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_preload_page'}, function (response) {
            if (response.success) {
                $(li).html(old_html);
            }
            else {

            }
        });

        return false;
    });

});