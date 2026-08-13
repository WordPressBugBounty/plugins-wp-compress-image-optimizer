jQuery(document).ready(function ($) {
    $('a').on('click', function(e) {
        e.preventDefault();

        var newUrl = $(this).attr('href');

        if (newUrl.indexOf('?') !== -1) {
            newUrl += '&wpc_visitor_mode=true';
        } else {
            newUrl += '?wpc_visitor_mode=true';
        }

        // Redirect to the new URL
        window.location.href = newUrl;
    });
});