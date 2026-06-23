jQuery(document).ready(function ($) {
    $('.wps-ic-dismiss-notice').on('click', function(){
        var id = $(this).data('tag');

        $('.wps-ic-tag-'+id).slideUp();

        $.post(ajaxurl, {action: 'wps_ic_dismiss_notice', id: id, nonce: wps_ic_notices_vars.nonce}, function () {

        });
    });


    $('.wps-ic-fix-notice').on('click', function(){
        var setting = $(this).data('setting');
        var plugin = $(this).data('plugin');
        var id = $(this).data('tag');

        $('.wps-ic-tag-'+id).slideUp();

        $.post(ajaxurl, {action: 'wps_ic_fix_notice', setting: setting, plugin: plugin, nonce: wps_ic_notices_vars.nonce}, function () {

        });
    });
});