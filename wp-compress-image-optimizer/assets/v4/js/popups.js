jQuery(document).ready(function ($) {

    // Copy defaults button handler
    $(document).on('click', '.wpc-copy-defaults-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();
        var btn = $(this);
        var text = btn.siblings('.wpc-hooks-defaults-box').text().trim();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function() {
                btn.addClass('wpc-copied');
                btn.find('span').text('Copied!');
                setTimeout(function() {
                    btn.removeClass('wpc-copied');
                    btn.find('span').text('Copy all');
                }, 1500);
            });
        }
    });

    // Auto-grow textareas in purge settings (no scrollbar)
    function autoGrowTextarea(el) {
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }
    $(document).on('input', '.popup-purge-settings .hooks-list-textarea-value', function() {
        autoGrowTextarea(this);
    });

    // Track which configure button was clicked (for override tint)
    var lastConfigureTrigger = null;

    function CustomCnameClose() {
        var popup = $('.custom-cname-popup');
        var save = $('[name="save"]', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var top = $('.cdn-popup-top', popup);
        var steps = $('.custom-cdn-steps', popup);
        var step_1 = $('.custom-cdn-step-1', steps);
        var step_2 = $('.custom-cdn-step-2', steps);
        var step_1_retry = $('.custom-cdn-step-1-retry', steps);
        var step_2_img = $('.custom-cdn-step-2-img', steps);

        $(step_1).show();
        $(step_2).hide();
        $(step_1_retry).hide();
    }

    function CustomCname() {
        var popup = $('.swal2-container .custom-cname-popup');
        var popupData = $('.swal2-container .custom-cname-popup');
        var form = $('form', popup);
        var save = $('[name="save"]', popup);
        var cant_see = $('.btn-i-cant-see', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var top = $('.cdn-popup-top', popup);
        var steps = $('.custom-cdn-steps', popup);
        var step_1 = $('.custom-cdn-step-1', steps);
        var step_2 = $('.custom-cdn-step-2', steps);
        var step_2_img = $('.custom-cdn-step-2-img', steps);
        var step_1_retry = $('.custom-cdn-step-1-retry', steps);
        var configure = $('.setting-configure');
        var configured = $('.setting-configured');
        var cname_enabled = $('.cname-enabled');
        var cname_disabled = $('.cname-disabled');
        var label_enabled = $('.label-enabled');
        var label_disabled = $('.label-disabled');
        var cname_configured = $('.cname-configured');

        $(save).on('click', function (e) {
            e.preventDefault();
            var cname_field = $('[name="custom-cdn"]', popupData).val();

            if (cname_field == '') {
                //wps-ic-mu-popup-empty-cname
                $('[name="custom-cdn"]', popupData).addClass('empty');
                $(form).find('.error').remove(); // Remove existing error before adding new one
                $(form).prepend('<p class="error">You must fill out the CNAME.</p>');
                return false;
            }

            $(top).fadeOut(150);
            $(content).fadeOut(150, function() {
                $(loading).fadeIn(150);
            });

            $('h4', loading).show();

            $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_cname_add', cname: cname_field, wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                $(top).fadeIn(150);
                $(step_1_retry).hide();
                $('h4', loading).hide();

                if (response.success) {
                    $(loading).fadeOut(150, function() {
                        $(content).fadeIn(150);
                    });

                    $(cname_disabled).hide();
                    $(label_disabled).hide();
                    $(cname_enabled).show();
                    $(label_enabled).show();
                    $(configure).hide();
                    $(configured).show();
                    $(step_1).hide();
                    //$(step_2_img).attr('src', response.data.image);
                    $('.check-cdn-link', step_2).attr('href', response.data.image);

                    $('.wpc-dns-error-text', step_2).hide();

                    $(step_2).show();
                    var countdown = 6;
                    $('.btn-i-cant-see', step_2).addClass('disabled');

                    var btnCountdown = setInterval(function() {
                        countdown--;
                        if (countdown==0) {
                            $('.btn-i-cant-see', step_2).html('I can\'t see the above image').removeClass('disabled');
                            clearInterval(btnCountdown);
                        } else {
                            $('.btn-i-cant-see', step_2).html('I can\'t see the above image (' + countdown + ')');
                        }
                    }, 1100);

                    setTimeout(function () {
                        $(cname_configured).html(response.data.configured).show();

                        $('.btn-close').on('click', function (e) {
                            e.preventDefault();
                            WPCSwal.close();
                            return false;
                        });
                    }, 1000);
                }
                else {
                    $(loading).fadeOut(150, function() {
                        $(content).fadeIn(150);
                    });

                    $(cname_enabled).hide();
                    $(label_enabled).hide();
                    $(cname_configured).html('').hide();
                    $(cname_disabled).show();
                    $(label_disabled).show();
                    $(configure).show();
                    $(configured).hide();
                    $(step_1).show();

                    if (response.data == 'invalid-dns-prop') {
                        $('.wpc-dns-error-text', step_2).addClass('custom-cdn-error-message').show();
                        $('.custom-cdn-error-message', popup).html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> Seems like DNS is not set correctly...');
                    }
                    else if (response.data == 'dns-api-not-working') {
                        $('.custom-cdn-error-message', popup).html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> Seems like DNS API is not working, please contact support...');
                    }
                    else {
                        $('.custom-cdn-error-message', popup).html('<span class="icon-container close-toggle"><i class="icon-cancel"></i></span> This domain is invalid, please link a new domain...');
                    }

                    //$('.wpc-dns-error-text', popup).show();
                    $('.custom-cdn-error-message', popup).show();
                    $(step_2).hide();
                    $(step_1_retry).hide();
                }
            });
        });

        $(cant_see).on('click', function (e) {
            e.preventDefault();

            var configure = $('.setting-configure');
            var configured = $('.setting-configured');

            $(configure).show();
            $(configured).hide();

            $(content).fadeOut(150, function() { $(loading).fadeIn(150); });


            $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_cname_retry', wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                $(top).fadeOut(150);
                $(content).fadeOut(150, function() { $(loading).fadeIn(150); });
                $('h4', loading).show();

                if (response.success) {
                    $(loading).fadeOut(150, function() { $(content).fadeIn(150); });

                    $(cname_disabled).hide();
                    $(label_disabled).hide();
                    $(cname_enabled).show();
                    $(label_enabled).show();
                    $(configure).hide();
                    $(configured).show();
                    $(step_1).hide();
                    $(step_2_img).attr('src', response.data.image);

                    setTimeout(function () {
                        $(step_2).show();
                        $(cname_configured).html(response.data.configured).show();
                        $('.btn-close').on('click', function (e) {
                            e.preventDefault();
                            WPCSwal.close();
                            return false;
                        });
                    }, 1000);

                }
                else {
                    $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_remove_cname', wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                        if (response.success) {
                            $(loading).fadeOut(150, function() { $(content).fadeIn(150); });
                            $(cname_enabled).hide();
                            $(cname_disabled).show();
                            $(step_1_retry).show();
                            $(step_1).hide();
                            $(step_2).hide();
                        }
                    });
                }
            });

            return false;
        });
    }

    function RemoveCustomCname() {
        var popup = $('.remove-cname-popup');
        var popupData = $('.swal2-container .remove-cname-popup');
        var save = $('[name="save"]', popup);
        var cant_see = $('.btn-i-cant-see', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var top = $('.cdn-popup-top', popup);
        var steps = $('.custom-cdn-steps', popup);
        var step_1 = $('.custom-cdn-step-1', steps);
        var step_2 = $('.custom-cdn-step-2', steps);
        var step_2_img = $('.custom-cdn-step-2-img', steps);
        var step_1_retry = $('.custom-cdn-step-1-retry', steps);
        var configure = $('.setting-configure');
        var configured = $('.setting-configured');
        var cname_enabled = $('.cname-enabled');
        var cname_disabled = $('.cname-disabled');
        var label_enabled = $('.label-enabled');
        var label_disabled = $('.label-disabled');

        $(loading).show();
        $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_remove_cname', wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
            if (response.success) {
                $(configure).show();
                $(configured).hide();
                $(cname_enabled).hide();
                $(label_enabled).hide();
                $(cname_disabled).show();
                $(label_disabled).show();
                WPCSwal.close();
            }
        });
    }


    $('.wps-ic-configure-popup').on('click', function (e) {
        e.preventDefault();

        if ($(this).hasClass('LockedTooltip')) {
            return false;
        }

        lastConfigureTrigger = $(this);
        var popupID = $(this).data('popup');
        var popupWidth = $(this).data('popup-width');


        WPCSwal.fire({
            title: '', html: jQuery('#' + popupID).html(), width: popupWidth, showCloseButton: true, showCancelButton: false, showConfirmButton: false, allowOutsideClick: true, allowEscapeKey: true, customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup',
                popup: 'popup-' + popupID,
                content: ' popup-' + popupID,
            }, onOpen: function () {

                if (popupID == 'custom-cdn') {
                    CustomCname();
                }
                else if (popupID == 'remove-custom-cdn') {
                    RemoveCustomCname();
                } else if (popupID == 'purge-settings'){
                    purgeSettingsPopup();
                } else if (popupID == 'cache-cookies'){
                    cacheCookiesPopup();
                } else if (popupID == 'cf-cdn'){
                    cfCdnPopup();
                }
                else {
                    var popup = $('.swal2-container .ajax-settings-popup');
                    var form = $('form', popup);
                    var loading = $('.cdn-popup-loading', popup);
                    var content = $('.cdn-popup-content', popup);

                    $('input[type="text"],textarea', form).each(function (i, item) {
                        var settingName = $(item).data('setting-name');
                        var settingSubset = $(item).data('setting-subset');

                        $.post(wpc_ajaxVar.ajaxurl, {action: 'wps_ic_get_setting', name: settingName, subset: settingSubset, wps_ic_nonce: wpc_ajaxVar.nonce}, function (response) {
                            $(loading).fadeOut(150, function() {
                                $(content).fadeIn(150);
                            });
                            $(item).val(response.data.value);

                            if (response.data.exclude_third == '1') {
                                $('.wps-exclude-third', form).prop("checked", true);
                            } else {
                                $('.wps-exclude-third', form).prop("checked", false);
                            }


                            if (response.data.default_excludes == '1') {
                                $('.wps-default-excludes', form).prop("checked", true);
                            } else {
                                $('.wps-default-excludes', form).prop("checked", false);
                            }

                            if (response.data.exclude_themes == '1') {
                                $('.wps-exclude-themes', form).prop("checked", true);
                            } else {
                                $('.wps-exclude_themes', form).prop("checked", false);
                            }

                            if (response.data.exclude_plugins == '1') {
                                $('.wps-exclude-plugins', form).prop("checked", true);
                            } else {
                                $('.wps-exclude-plugins', form).prop("checked", false);
                            }

                            if (response.data.exclude_wp == '1') {
                                $('.wps-exclude-wp', form).prop("checked", true);
                            } else {
                                $('.wps-exclude-wp', form).prop("checked", false);
                            }

                            if (response.data.min_mobile_width) {
                                $('.wps-min-mobile-width', form).val(response.data.min_mobile_width);
                            }
                        });

                    });

                    savePopup(popup);

                    // Popup tab switching
                    $('.wpc-popup-tab', popup).on('click', function(e) {
                        e.preventDefault();
                        var targetId = $(this).data('tab-target');
                        $('.wpc-popup-tab', popup).removeClass('active');
                        $(this).addClass('active');
                        $('.wpc-popup-tab-content', popup).hide().removeClass('active');
                        $('.wpc-popup-tab-content[data-tab-id="' + targetId + '"]', popup).show().addClass('active');
                    });

                }
            }, onClose: function () {

            }
        });

        return false;
    });


    $('.btn-close').on('click', function (e) {
        e.preventDefault();
        WPCSwal.close();
        return false;
    });


    function savePopup(popup) {
        var save = $('.btn-save', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var form = $('.wpc-save-popup-data', popup);

        $(save).on('click', function (e) {
            e.preventDefault();
            $(content).fadeOut(150, function() {
                $(loading).fadeIn(150);
            });

            var default_enabled = '0';
            var exclude_themes = '0';
            var exclude_plugins = '0';
            var exclude_wp = '0';
            var exclude_third = '0'

            if( $('.wps-exclude-third', popup).is(':checked') ){
                exclude_third = 1;
            }

            if( $('.wps-default-excludes', popup).is(':checked') ){
                default_enabled = 1;
            }

            if( $('.wps-exclude-themes', popup).is(':checked') ){
                exclude_themes = 1;
            }
            if( $('.wps-exclude-plugins', popup).is(':checked') ){
                exclude_plugins = 1;
            }
            if( $('.wps-exclude-wp', popup).is(':checked') ){
                exclude_wp = 1;
            }

            var setting_group = $('input[type="text"],textarea', popup).first().data('setting-name');
            var setting_name = $('input[type="text"],textarea', popup).first().data('setting-subset');
            var excludes = $('input[type="text"],textarea', popup).first().val();

            // Collect configure tab data if present (JS Delay popup saves both tabs at once)
            var lastLoadScripts = '';
            var deferScripts = '';
            var $lastLoadTextarea = $('textarea[data-setting-subset="lastLoadScript"]', popup);
            if ($lastLoadTextarea.length) {
                lastLoadScripts = $lastLoadTextarea.val();
            }
            var $deferTextarea = $('textarea[data-setting-subset="deferScript"]', popup);
            if ($deferTextarea.length) {
                deferScripts = $deferTextarea.val();
            }

            var min_mobile_width = $('.wps-min-mobile-width', popup).length > 0 ? $('.wps-min-mobile-width', popup).val() : false;


            $.post(wpc_ajaxVar.ajaxurl, {
                action: 'wps_ic_save_excludes_settings',
                nonce: wpc_ajaxVar.nonce,
                group_name: setting_group,
                setting_name: setting_name,
                excludes: excludes,
                lastLoadScript: lastLoadScripts,
                deferScript: deferScripts,
                default_enabled: default_enabled,
                exclude_themes: exclude_themes,
                exclude_plugins: exclude_plugins,
                exclude_wp: exclude_wp,
                exclude_third: exclude_third,
                min_mobile_width: min_mobile_width
            }, function(response) {
                if (response.success) {
                    // Toggle override tint on the configure link
                    if (lastConfigureTrigger && lastConfigureTrigger.length) {
                        var hasData = excludes && excludes.trim().length > 0;
                        lastConfigureTrigger.toggleClass('wpc-has-overrides', hasData);
                    }
                    WPCSwal.close();
                }
            });

            return false;
        });
    }


    function purgeSettingsPopup(){
        var popup = $('.swal2-container .ajax-settings-popup');
        var form = $('form', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);

        $.post(wpc_ajaxVar.ajaxurl, {
            action: 'wps_ic_get_purge_rules',
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, function (response) {
            if (response.success) {

                // Set the hooks textarea value
                $('.hooks-list-textarea-value', popup).val(response.data.hooks);

                // Set checkbox values based on response data
                if (response.data.all_pages == 1) {
                    $('.wps-all-pages', popup).prop('checked', true);
                }

                if (response.data.home_page == 1) {
                    $('.wps-home-page', popup).prop('checked', true);
                }

                if (response.data.recent_posts_widget == 1) {
                    $('.wps-recent-posts-widget', popup).prop('checked', true);
                }

                if (response.data.archive_pages == 1) {
                    $('.wps-archive-pages', popup).prop('checked', true);
                }

                if (response.data.scheduled) {
                    $('.wps-scheduled-purge', popup).val(response.data.scheduled);
                }

                // Show the user's local time
                var now = new Date();
                var localTime = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                $('.wpc-local-time', popup).text(localTime);
            }
            $(loading).fadeOut(150, function() {
                $(content).fadeIn(150, function() {
                    // Auto-grow hooks textarea after content is visible
                    var ta = $('.hooks-list-textarea-value', popup)[0];
                    if (ta) { ta.style.height = 'auto'; ta.style.height = ta.scrollHeight + 'px'; }
                });
            });
        });

        savePurgeSettingsPopup(popup);
    }

    function savePurgeSettingsPopup(popup) {
        var save = $('.btn-save', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var form = $('.wpc-save-popup-data', popup);

        $(save).on('click', function (e) {
            e.preventDefault();
            $(content).fadeOut(150, function() {
                $(loading).fadeIn(150);
            });

            var all_pages = '0';
            var home_page = '0';
            var recent_posts_widget = '0';
            var archive_pages = '0';

            $('.wps-default-excludes-enabled-checkbox', popup).each(function() {
                if ($(this).hasClass('wps-all-pages')) {
                    all_pages = $(this).is(':checked') ? '1' : '0';
                }
                else if ($(this).hasClass('wps-home-page')) {
                    home_page = $(this).is(':checked') ? '1' : '0';
                }
                else if ($(this).hasClass('wps-recent-posts-widget')) {
                    recent_posts_widget = $(this).is(':checked') ? '1' : '0';
                }
                else if ($(this).hasClass('wps-archive-pages')) {
                    archive_pages = $(this).is(':checked') ? '1' : '0';
                }
            });

            var setting_group = $('input[type="text"],textarea', popup).data('setting-name');
            var setting_name = $('input[type="text"],textarea', popup).data('setting-subset');
            var hooks = $('.hooks-list-textarea-value', popup).val();
            var scheduled = $('.wps-scheduled-purge', popup).val();

            $.post(wpc_ajaxVar.ajaxurl, {
                action: 'wps_ic_save_purge_hooks_settings',
                group_name: setting_group,
                setting_name: setting_name,
                hooks: hooks,
                all_pages: all_pages,
                home_page: home_page,
                recent_posts_widget: recent_posts_widget,
                archive_pages: archive_pages,
                scheduled: scheduled,
                wps_ic_nonce: wpc_ajaxVar.nonce
            }, function (response) {
                if (response.success){
                    WPCSwal.close();
                }
            });

            return false;
        });
    }

    $('.wpc-cf-cname-popup').on('click',function(){
        WPCSwal.fire({
            title: '',
            html: jQuery('#cf-cdn').html(),
            width: 600,
            showCloseButton: true,
            showCancelButton: false,
            showConfirmButton: false,
            allowOutsideClick: true,
            allowEscapeKey: true,
            customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup',
            },
            onOpen: function () {
                cfCdnPopup();
            }
        });

    });
    
    function cfCdnPopup(){
        var popup = $('.swal2-container .ajax-settings-popup');
        var form = $('form', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);

        $.post(wpc_ajaxVar.ajaxurl, {
            action: 'wps_ic_get_cf_cdn',
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, function (response) {
            if (response.success) {

                $('#wpc-custom-cdn', popup).val(response.data.cname);

            }
            $(loading).fadeOut(150, function() { $(content).fadeIn(150); });
        });
        
        saveCfCdnPopup(popup);
    }

    function saveCfCdnPopup(popup) {
        var save = $('.btn-save', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var form = $('.wpc-save-popup-data', popup);

        $(save).on('click', function (e) {
            e.preventDefault();
            $(content).fadeOut(150, function() {
                $(loading).fadeIn(150);
            });

            var cname = $('input[type="text"],textarea', popup).val();

            $.post(wpc_ajaxVar.ajaxurl, {
                action: 'wps_ic_save_cf_cdn',
                wps_ic_nonce: wpc_ajaxVar.nonce,
                cname: cname
            }, function (response) {
                if (response.success){
                    WPCSwal.close();
                }
            });

            return false;
        });
    }

    function cacheCookiesPopup(){
        var popup = $('.swal2-container .ajax-settings-popup');
        var form = $('form', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);

        $.post(wpc_ajaxVar.ajaxurl, {
            action: 'wps_ic_get_cache_cookies',
            wps_ic_nonce: wpc_ajaxVar.nonce
        }, function (response) {
            if (response.success) {

                // Set the hooks textarea value
                $('.cache-cookies-textarea-value', popup).val(response.data.cache_cookies);
                $('.exclude-cookies-textarea-value', popup).val(response.data.exclude_cookies);

            }
            $(loading).fadeOut(150, function() { $(content).fadeIn(150); });
        });

        saveCacheCookiesPopup(popup);
    }

    function saveCacheCookiesPopup(popup) {
        var save = $('.btn-save', popup);
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);
        var form = $('.wpc-save-popup-data', popup);

        $(save).on('click', function (e) {
            e.preventDefault();
            $(content).fadeOut(150, function() {
                $(loading).fadeIn(150);
            });

            var setting_name = $('input[type="text"],textarea', popup).data('setting-subset');
            var cache_cookies = $('.cache-cookies-textarea-value', popup).val();
            var exclude_cookies = $('.exclude-cookies-textarea-value', popup).val();

            $.post(wpc_ajaxVar.ajaxurl, {
                action: 'wps_ic_save_cache_cookies_settings',
                exclude_cookies: exclude_cookies,
                cache_cookies: cache_cookies,
                setting_name: setting_name,
                wps_ic_nonce: wpc_ajaxVar.nonce
            }, function (response) {
                if (response.success){
                    WPCSwal.close();
                }
            });

            return false;
        });
    }

    //Export button
    $('#wpc-export-button').on('click', function(e) {
        e.preventDefault()
        const exportSettings = $('.wps-export-settings').prop('checked');
        const exportExcludes = $('.wps-export-excludes').prop('checked');
        const exportCache = $('.wps-export-cache').prop('checked');
        const exportCookies = $('.wps-export-cache-cookies').prop('checked');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_export_settings',
                settings: exportSettings,
                excludes: exportExcludes,
                cache: exportCache,
                cookies: exportCookies,
                wps_ic_nonce: wpc_ajaxVar.nonce
            },
            success: function(response) {
                if (response.success) {
                    const blob = new Blob([JSON.stringify(response.data)], {type: 'application/json'});

                    // Create download link
                    const url = window.URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.style.display = 'none';
                    a.href = url;
                    var site = window.location.hostname;
                    site = site.replace(/^www\./, '').split('.')[0];
                    a.download = 'Settings-' + site + '.json';

                    // Append to the document, trigger click, and clean up
                    document.body.appendChild(a);
                    a.click();
                    window.URL.revokeObjectURL(url);
                    document.body.removeChild(a);
                } else {

                }
            },
            error: function(xhr, status, error) {

            }
        });
    });

    //Import button
    $('#wpc-import-button').on('click', function(e) {
        e.preventDefault()
        $('#wpc-import-file').trigger('click');
    });

    $('#wpc-import-file').on('change', function(event) {
        const file = event.target.files[0];
        if (!file) return;

        const reader = new FileReader();
        reader.onload = function(e) {
            const importData = JSON.parse(e.target.result);

            WPCSwal.fire({
                title: '', html: jQuery('#import-popup').html(), width: 600, showCancelButton: false, showConfirmButton: false, allowOutsideClick: true, showCloseButton: true, customClass: {
                    container: 'no-padding-popup-bottom-bg switch-legacy-popup',
                },onOpen: function () {
                    importSettings(importData);
                },onClose: function () {
                    window.location.reload();
                }
            });
        };
        reader.readAsText(file);
    });

    //reset to default button
    $('#wpc-set-default-button').on('click', function(e) {
        e.preventDefault();

        WPCSwal.fire({
            title: '', html: '<h2>Reset everything to default?</h2>', width: 600, showCancelButton: true, showConfirmButton: true, allowOutsideClick: true, showCloseButton: true, customClass: {
                container: 'no-padding-popup-bottom-bg switch-legacy-popup',
            },onOpen: function () {

            },onClose: function () {

            },
            confirmButtonText: 'Yes, reset it!',
            preConfirm: function() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wps_ic_set_default_settings',
                        wps_ic_nonce: wpc_ajaxVar.nonce
                    },
                    success: function(response) {
                        window.location.reload();
                    },
                    error: function(xhr, status, error) {

                    }
                });
            }
        });

    });

    function importSettings(importData){
        var popup = $('.wpc-import-popup');
        var loading = $('.cdn-popup-loading', popup);
        var content = $('.cdn-popup-content', popup);

        $(loading).show();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'wps_ic_import_settings',
                importData: importData,
                wps_ic_nonce: wpc_ajaxVar.nonce
            },
            success: function (response) {
                if (response.success) {
                    window.location.reload();
                }
            },
            error: function (xhr, status, error) {

            }
        });

    }

    // Toggle example lists in popups (accordion)
    $(document).on('click', '.wps-example-toggle-btn', function () {
        var $btn = $(this);
        var $section = $btn.closest('.wps-example-section');
        var $list = $section.find('.wps-example-list');
        var isOpening = !$btn.hasClass('wps-example-open');
        $btn.toggleClass('wps-example-open');
        if (isOpening) {
            $section.addClass('wps-examples-visible');
            $list.slideDown(250);
        } else {
            $list.slideUp(250, function() {
                $section.removeClass('wps-examples-visible');
            });
        }
    });

});