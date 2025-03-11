jQuery(document).ready(function ($) {


    $('.wpc-cf-token-check').on('click', function(e){
        e.preventDefault();

        var cFToken = $('input[name="wpc-cf-token"]').val();
        if (cFToken === '') {
            $('.wpc-cf-loader-error').html('Cloudflare API Error: Token field is empty.').show();
            console.error('Cloudflare API Error: Token field is empty.');
            return false; // Stop further execution
        }

        $('.wpc-cf-token-hide-on-load').hide();
        $('.wpc-cf-loader').show();
        $('.wpc-cf-loader-error').hide();

        $.post(ajaxurl, {
            action: 'wpc_ic_checkCFToken',
            token: cFToken,
            _nonce: Math.random().toString(36).substr(2, 9),
            wps_ic_nonce: ajaxVar.nonce,
        }, function (response) {

            if (response.success) {
                $('.wpc-cf-zone-list-items', '#wpc-cf-zone-list-holder').html(response.data);
                $('.wpc-cf-loader').hide(function () {
                    $('#wpc-cf-zone-list-holder').show();
                });

                $('#wpc-cf-zone-list-holder').on('click', '.wpc-cf-zone-list-items>div', function () {
                    var selected = $(this);
                    var selectedValue = $(selected).data('selected-zone');
                    var selectedID = $(selected).data('selected-zone-id');
                    var selectedIndicator = $('.wpc-cf-zone-list-selected');

                    $('.wpc-cf-zone-list-items').hide();
                    $('input[name="wpc-cf-zone"]').val(selectedID);
                    $(selectedIndicator).html(selectedValue);

                    console.log(selectedValue);
                    return false;
                });

            } else {
                $('.wpc-cf-token-hide-on-load').show();
                $('.wpc-cf-loader').hide();
                $('.wpc-cf-loader-error>span').html(response.data);
                $('.wpc-cf-loader-error').show();
            }
        });

        return false;
    });


    $('.wpc-cf-token-connect').on('click', function (e){
        e.preventDefault();

        $('.wpc-cf-loader-error').html('').hide();
        var cFToken = $('input[name="wpc-cf-token"]').val();
        var cFZone = $('input[name="wpc-cf-zone"]').val();

        if (cFToken === '') {
            $('.wpc-cf-loader-error').html('Cloudflare API Error: Token field is empty.').show();
            console.error('Cloudflare API Error: Token field is empty.');
            return; // Stop further execution
        }

        if (cFZone === '') {
            $('.wpc-cf-loader-error').html('Cloudflare API Error: You haven\'t selected a zone.').show();
            console.error('Cloudflare API Error: You haven\'t selected a zone.');
            return; // Stop further execution
        }

        $('.wpc-cf-token-hide-on-load').hide();
        $('#wpc-cf-zone-list-holder').hide();
        $('.wpc-cf-loader-zone').show();

        $.post(ajaxurl, {
            action: 'wpc_ic_checkCFConnect',
            token: cFToken,
            zone: cFZone,
            _nonce: Math.random().toString(36).substr(2, 9),
            wps_ic_nonce: ajaxVar.nonce,
        }, function (response) {

            if(response.success) {
                $.post(ajaxurl, {
                    action: 'wpc_ic_setupCF',
                    token: cFToken,
                    zone: cFZone,
                    wps_ic_nonce: ajaxVar.nonce,
                    _nonce: Math.random().toString(36).substr(2, 9), // Add a random hash
                }, function (response) {
                    if (response.success) {
                        $('.wpc-cf-loader-zone').hide();
                        window.location.reload();
                    } else {
                        $('.wpc-cf-loader-zone').hide();
                        $('.wpc-cf-insert-token-step').show();
                        $('.wpc-cf-loader-error').html('Looks like your API Token does not have correct privileges or it\'s invalid').show();
                    }
                });
            } else {
                $('.wpc-cf-loader-zone').hide();
                $('.wpc-cf-insert-token-step').show();
                $('.wpc-cf-loader-error').html('Looks like your API Token does not have correct privileges or it\'s invalid').show();
            }


        });

        return false;
    });


    $('.wpc-cf-token-disconnect').on('click', function(e){
        $('.wpc-cf-token-hide-on-load').hide();
        $('.wpc-cf-token-connected').hide();
        $('.wpc-cf-loader-disconnecting').show();
        $('.wpc-cf-loader-error').hide();

        e.preventDefault();
        $.post(ajaxurl, {
            action: 'wpc_ic_checkCFDisconnect',
            wps_ic_nonce: ajaxVar.nonce,
            _nonce: Math.random().toString(36).substr(2, 9), // Add a random hash
        }, function (response) {
            window.location.reload();
        });
        return false;
    });


    $('.wpc-save-button').on('click', function (e) {
        $('.save-button').hide();
        $('.wpc-loading-spinner').show();
    });


    function showSaveButton() {
        $('.save-button').fadeIn(500);
        //$('.wpc-preset-dropdown>option').removeAttr('selected').prop('selected', false);
        //$('.wpc-preset-dropdown>option:eq(2)').attr('selected', 'selected').prop('selected', true);

        $('input[name="wpc_preset_mode"]').val('custom');
        $('a', '.wpc-dropdown-menu').removeClass('active');
        $('button', '.wpc-dropdown').html('Custom');
        $('a[data-value="custom"]', '.wpc-dropdown-menu').addClass('active');

        //var selectedValue = $('.wpc-preset-dropdown').val();
        $.post(ajaxurl, {
            action: 'wpc_ic_ajax_set_preset',
            wps_ic_nonce: ajaxVar.nonce,
            value: 'custom',
        }, function (response) {

        });

    }


    function hideSaveButton() {
        $('.save-button').fadeOut(500);
    }


    /**
     * Slider Click on Text
     */
    $('.wpc-slider-text>div').on('click', function (e) {
        e.preventDefault();
        var selectedValue = $(this).data('value');
        var rangeMin = $('.wpc-range-slider>input', '.wpc-slider').attr('min');
        var rangeMax = $('.wpc-range-slider>input', '.wpc-slider').attr('max');

        const newValue = Number((selectedValue - rangeMin) * 100 / (rangeMax - rangeMin)),
            newPosition = 16 - (newValue * 0.32);
        document.documentElement.style.setProperty("--range-progress", `calc(${newValue}% + (${newPosition}px))`);

        $('.wpc-range-slider input').prop('value', selectedValue).attr('value', selectedValue);

        var newSettingsSate = getSettingsState();

        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }

        return false;
    });


    /**
     * Dropdown Button
     */
    $('button', '.wpc-dropdown').on('mouseenter', function (e) {
        e.preventDefault();
        return false;
        var parent = $(this).parent();

        $('.wpc-dropdown-menu', parent).show();
        $('.dropdown-item', '.wpc-dropdown-menu').bind('click');

        return false;
    }).on('mouseleave', function (e) {
        return false;
        if (!$(e.relatedTarget).hasClass('wpc-dropdown-menu') && !$(e.relatedTarget).hasClass('dropdown-item')) {
            $('.wpc-dropdown-menu').hide();
        }
    }).on('click', function (e) {
        e.preventDefault();
        return false;

        var parent = $(this).parent();

        $('.wpc-dropdown-menu', parent).show();
        $('.dropdown-item', '.wpc-dropdown-menu').bind('click');

        return false;
    });

    $('.wpc-dropdown-menu').on('mouseleave', function (e) {
        return false;

        var menu = $(this);
        $(menu).hide();
    });


    $('.dropdown-item', '.wpc-dropdown-menu').on('click', function (e) {
        e.preventDefault();
        return false;

        var item = $(this);
        var value = $(this).data('value');
        var presetTitle = $(this).data('preset-title');
        $('input[name="wpc_preset_mode"]').val(value);

        $('.dropdown-item', '.wpc-dropdown-menu').removeClass('active');
        $(item).addClass('active');

        $('.wpc-dropdown-menu').hide();
        $('.wpc-dropdown>button').html(presetTitle);

        $.post(ajaxurl, {
            action: 'wpc_ic_ajax_set_preset',
            value: value,
            wps_ic_nonce: ajaxVar.nonce
        }, function (response) {
            var configuration = response.data;
            $.each(configuration, function (index, element) {
                var iconCheckbox = false;
                var iconCheckboxParent = false;

                if (Object.keys(element).length > 1) {

                    $.each(element, function (subindex, subelement) {
                        iconCheckbox = $('input[name="options[' + index + '][' + subindex + ']"]');

                        if (subelement == 1 || subelement == '1') {
                            $('input[name="options[' + index + '][' + subindex + ']"]').attr('checked', 'checked').prop('checked', true);

                            if ($(iconCheckbox).hasClass('wpc-ic-settings-v4-iconcheckbox')) {
                                iconCheckboxParent = $(iconCheckbox).parents('.wpc-iconcheckbox');
                                iconCheckboxParent.addClass('active');
                            }
                        } else {
                            $('input[name="options[' + index + '][' + subindex + ']"]').removeAttr('checked').prop('checked', false);
                            if ($(iconCheckbox).hasClass('wpc-ic-settings-v4-iconcheckbox')) {
                                iconCheckboxParent = $(iconCheckbox).parents('.wpc-iconcheckbox');
                                iconCheckboxParent.removeClass('active');
                            }
                        }
                    });

                } else {
                    if (index == 'live-cdn') {
                        if (element == 1 || element == '1') {
                            $('input[name="options[' + index + ']"]').val('1');
                        } else {
                            $('input[name="options[' + index + ']"]').val('0');
                        }
                    } else {
                        iconCheckbox = $('input[name="options[' + index + ']"]');

                        if (element == 1 || element == '1') {
                            $('input[name="options[' + index + ']"]').attr('checked', 'checked').prop('checked', true);
                            if ($(iconCheckbox).hasClass('wpc-ic-settings-v4-iconcheckbox')) {
                                iconCheckboxParent = $(iconCheckbox).parents('.wpc-iconcheckbox');
                                iconCheckboxParent.addClass('active');
                            }
                        } else {
                            $('input[name="options[' + index + ']"]').removeAttr('checked').prop('checked', false);
                            if ($(iconCheckbox).hasClass('wpc-ic-settings-v4-iconcheckbox')) {
                                iconCheckboxParent = $(iconCheckbox).parents('.wpc-iconcheckbox');
                                iconCheckboxParent.removeClass('active');
                            }
                        }
                    }
                }
            });

            //set qualityLevel slider and value
            var rangeMin = $('.wpc-range-slider>input', '.wpc-slider').attr('min');
            var rangeMax = $('.wpc-range-slider>input', '.wpc-slider').attr('max');

            const newValue = Number((configuration.qualityLevel - rangeMin) * 100 / (rangeMax - rangeMin)),
                newPosition = 16 - (newValue * 0.32);
            document.documentElement.style.setProperty("--range-progress", `calc(${newValue}% + (${newPosition}px))`);

            $('#optimizationLevel').prop('value', configuration.qualityLevel).attr('value', configuration.qualityLevel);

            $('.save-button').fadeIn(500);
        });

        return false;
    });

    // Listen to the doc click
    window.addEventListener('click', function (e) {
        // Close the menu if click happen outside menu
        if (e.target.closest('.wpc-dropdown') === null) {
            // Close the opend dropdown
            // $('.wpc-dropdown-menu', '.wpc-dropdown').hide();
        }
    });

    /***
     * IconBox click on container
     */
    $('.wpc-box-for-checkbox').on('click', function (e) {
        var box = $(this);
        var circle = $('.circle-check', box);
        var checkbox = $('.wpc-ic-settings-v4-checkbox', box);
        var connectedOption = $(checkbox).data('connected-slave-option');
        var outerParent = $(checkbox).parents('.wpc-tab-content-box');
        var id = $(outerParent).attr('id');

        console.log('Checked-box');

        if ($(box).hasClass('wpc-locked')) {
            return false;
        }

        var showPopup = $(checkbox).hasClass('wpc-show-popup');
        var popupID = $(checkbox).data('popup');
        var popupCustomButtons = $(checkbox).data('custom-buttons');

        var showConfirmButton = true;
        var popupClass = '';

        if (popupCustomButtons == true) {
            showConfirmButton = false;
            popupClass = 'wpc-popup-custom-padding';
        }

        if ($(e.target).is('span')) {
            // nothing it's label click
            e.preventDefault();
        }

        //$('.wpc-ic-settings-v4-iconcheckbox+label', box).trigger('click');

        var beforeValue = $('.wpc-ic-settings-v4-checkbox', box).attr('checked');

        if (beforeValue == 'checked') {
            $('.wpc-ic-settings-v4-checkbox', box).removeAttr('checked').prop('checked', false);
            // It was already active, remove checked
            $(circle).removeClass('active');
        } else {

            if (showPopup && popupID != '') {
                WPCSwal.fire({
                    title: '',
                    html: jQuery('#' + popupID + '-popup').html(),
                    width: 600,
                    showCloseButton: true,
                    showCancelButton: false,
                    showConfirmButton: showConfirmButton,
                    allowOutsideClick: false,
                    customClass: {
                        container: 'no-padding-popup-bottom-bg switch-legacy-popup ' + popupClass,
                    },
                    onOpen: () => {

                        if (!showConfirmButton) {
                            $('.wpc-popup-cancel').on('click', function(e){
                                e.preventDefault();
                                WPCSwal.clickCancel();
                                window.open('https://wpcompress.com/support/', '_blank');
                                return false;
                            });

                            $('.wpc-popup-confirm').on('click', function(e){
                                e.preventDefault();
                                WPCSwal.clickConfirm();
                                return false;
                            });
                        }
                    }
                }).then((result) => {

                    if (result.value) {
                        $('.wpc-ic-settings-v4-checkbox', box).attr('checked', 'checked').prop('checked', true);
                        // It was already active, remove checked
                        $(circle).addClass('active');
                        
                        var newSettingsSate = getSettingsState();

                        if (didSettingsChanged(settingsState, newSettingsSate)) {
                            showSaveButton();
                        } else {
                            hideSaveButton();
                        }
                    } else {

                    }
                });

            } else {
                $('.wpc-ic-settings-v4-checkbox', box).attr('checked', 'checked').prop('checked', true);
                // It was already active, remove checked
                $(circle).addClass('active');
            }
        }

        if ($('input[data-connected-option="' + connectedOption + '"]').length) {
            var slaveOption = $('input[data-connected-option="' + connectedOption + '"]');
            if (beforeValue == 'checked') {
                $(slaveOption).removeAttr('checked').prop('checked', false);
            } else {
                $(slaveOption).attr('checked', 'checked').prop('checked', true);
            }
        }

        checkIfAllSelected($(outerParent), '', 'select-all-' + id);

        var newSettingsSate = getSettingsState();

        if ($(this).closest('.wp-compress-mu-content').length > 0) {
            return false;
        }

        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    });


    $('.wpc-input-holder>input,.wpc-input-holder>textarea').on('keyup', function(e){
        // if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        // } else {
        //     hideSaveButton();
        // }
    });


    /***
     * IconBox click on container
     */
    $('.wpc-iconcheckbox').on('click', function (e) {
        var box = $(this);

        if ($(e.target).is('span')) {
            // nothing it's label click
            e.preventDefault();
        }

        if ($(box).hasClass('wpc-locked-checkbox-container')) {
            return false;
        }

        var beforeValue = $('.wpc-ic-settings-v4-iconcheckbox', box).attr('checked');

        if (beforeValue == 'checked') {
            $('.wpc-ic-settings-v4-iconcheckbox', box).removeAttr('checked').prop('checked', false);
            $(box).removeClass('active');
        } else {
            $('.wpc-ic-settings-v4-iconcheckbox', box).attr('checked', 'checked').prop('checked', true);
            $(box).addClass('active');
        }

        var newSettingsSate = getSettingsState();

        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    });


    /**
     * Preset dropdown change
     */
    $('.wpc-preset-dropdown').on('change', function (e) {
        var presetValue = $(this).val();
        $.post(ajaxurl, {
            action: 'wpc_ic_ajax_set_preset',
            value: presetValue,
            wps_ic_nonce: ajaxVar.nonce
        }, function (response) {
            $('.save-button').fadeIn(500);

            var configuration = response.data;
            $.each(configuration, function (index, element) {
                var iconCheckbox = false;
                var iconCheckboxParent = false;

                if (Object.keys(element).length > 1) {

                    $.each(element, function (subindex, subelement) {
                        iconCheckbox = $('input[name="options[' + index + '][' + subindex + ']"]');

                        if (subelement == 1 || subelement == '1') {
                            $('input[name="options[' + index + '][' + subindex + ']"]').attr('checked', 'checked').prop('checked', true);

                            if ($(iconCheckbox).hasClass('wpc-ic-settings-v4-iconcheckbox')) {
                                iconCheckboxParent = $(iconCheckbox).parents('.wpc-iconcheckbox');
                                iconCheckboxParent.addClass('active');
                            }
                        } else {
                            $('input[name="options[' + index + '][' + subindex + ']"]').removeAttr('checked').prop('checked', false);
                            if ($(iconCheckbox).hasClass('wpc-ic-settings-v4-iconcheckbox')) {
                                iconCheckboxParent = $(iconCheckbox).parents('.wpc-iconcheckbox');
                                iconCheckboxParent.removeClass('active');
                            }
                        }
                    });

                } else {
                    if (index == 'live-cdn') {
                        if (element == 1 || element == '1') {
                            $('input[name="options[' + index + ']"]').val('1');
                        } else {
                            $('input[name="options[' + index + ']"]').val('0');
                        }
                    } else {
                        iconCheckbox = $('input[name="options[' + index + ']"]');

                        if (element == 1 || element == '1') {
                            $('input[name="options[' + index + ']"]').attr('checked', 'checked').prop('checked', true);
                            if ($(iconCheckbox).hasClass('wpc-ic-settings-v4-iconcheckbox')) {
                                iconCheckboxParent = $(iconCheckbox).parents('.wpc-iconcheckbox');
                                iconCheckboxParent.addClass('active');
                            }
                        } else {
                            $('input[name="options[' + index + ']"]').removeAttr('checked').prop('checked', false);
                            if ($(iconCheckbox).hasClass('wpc-ic-settings-v4-iconcheckbox')) {
                                iconCheckboxParent = $(iconCheckbox).parents('.wpc-iconcheckbox');
                                iconCheckboxParent.removeClass('active');
                            }
                        }
                    }
                }
            });

        });
    });


    /**
     * Function to remember loaded settings
     */
    var settingsState = [];

    function setSettingsState() {
        var debug = [];
        settingsState = [];
        $('input[type="checkbox"],input[type="range"]', '.wpc-settings-body').each(function (i, item) {
            var checkbox = $(item);
            var state = 0;
            if (!$(checkbox).hasClass('wpc-checkbox-select-all') && !$(checkbox).hasClass('wpc-checkbox-connected-option')) {
                if (!$(item).is('input[type="range"]') && $(item).is('input[type="checkbox"]')) {
                    if ($(checkbox).is(':checked')) {
                        settingsState.push(1);
                    } else {
                        settingsState.push(0);
                    }
                } else {
                    debug.push([$(item), state]);
                    if ($(item).is('input[type="range"]')) {
                        state = $(item).attr('value');
                        state = parseInt(state);
                        //settingsState.push([$(item),state]);
                        settingsState.push(state);
                    }
                }

            }
        });
    }

    function getSettingsState() {
        var debug = [];
        var getSettingsState = [];
        $('input[type="checkbox"],input[type="range"]', '.wpc-settings-body').each(function (i, item) {
            var checkbox = $(item);
            var state = 0;
            if (!$(checkbox).hasClass('wpc-checkbox-select-all') && !$(checkbox).hasClass('wpc-checkbox-connected-option')) {
                //console.log($(item));

                if (!$(item).is('input[type="range"]') && $(item).is('input[type="checkbox"]')) {
                    if ($(checkbox).is(':checked')) {
                        getSettingsState.push(1);
                    } else {
                        getSettingsState.push(0);
                    }
                } else {
                    debug.push([$(item), state]);
                    if ($(item).is('input[type="range"]')) {
                        state = $(item).attr('value');
                        //console.log(state);
                        state = parseInt(state);
                        getSettingsState.push(state);
                    }
                }
            }
        });
        return getSettingsState;
    }

    setSettingsState();

    function didSettingsChanged(o, n) {

        // Comparing each element of array
        for (var i = 0; i < o.length; i++) {
            if (o[i] != n[i]) {
                return true;
            }
        }

        return false;
    }


    $('li>a', '.wpc-settings-tab-list').on('click', function (e) {
        e.preventDefault();

        var link = $(this);
        if ($(link).hasClass('active')) {
            return;
        }

        if ($(link).hasClass('wpc-locked-checkbox')) {
            return false;
        }

        var data = $(link).data('tab');
        var currentActiveContent = $('div.active-tab', '.wpc-settings-tab-content');

        //window.location.hash = data;
        history.pushState({}, "", "#" + data);

        $('.wpc-settings-tab-list li>a.active').removeClass('active');
        $(link).addClass('active');


        $('.wpc-settings-tab-content-inner>div.wpc-tab-content').hide();

        $('.wpc-tab-content-box', '#' + data).each(function (i, item) {
            checkIfAllSelected($(item), data);
        });

        $('div#' + data, '.wpc-settings-tab-content').addClass('active-tab').fadeIn(400);
        $(currentActiveContent).removeClass('active-tab');


        return false;
    });

    var hash = window.location.hash;
    if (hash != '') {
        var clean_hash = hash.replace('#', '');
        $('.wpc-settings-tab-list li>a[data-tab="' + clean_hash + '"]').trigger('click');
    }


    $('.wpc-ic-settings-v4-iconcheckbox').on('change', function (e) {
        e.preventDefault();

        console.log('v4-iconcheckbox');

        var allSelected = true;
        var tab = $(this).parents('.wpc-tab-content');
        var tabID = $(tab).attr('id');

        var parent = $(this).parents('.wpc-iconcheckbox');
        var beforeValue = $(this).attr('checked');

        if ($(this).hasClass('wpc-locked-checkbox')) {
            return false;
        }


        if (beforeValue == 'checked') {
            // Remove Select All
            $('.wpc-checkbox-select-all', tab).removeAttr('checked').prop('checked', false);

            // It was already active, remove checked
            $(this).removeAttr('checked').prop('checked', false);
            $(parent).removeClass('active');

            // Check if all are checked
            $('input[type="checkbox"]', '#' + tabID).each(function (i, item) {
                if (typeof $(item).data('for-div-id') == 'undefined') {
                    if (!$(item).is(':checked')) {
                        allSelected = false;
                    }
                }
            });

            if (allSelected) {
                $('input[data-for-div-id="' + tabID + '"]').removeAttr('checked').prop('checked', false);
            }
        } else {
            // It's not active, activate
            $(this).attr('checked', 'checked').prop('checked', true);
            $(parent).addClass('active');

            // Check if all are checked
            $('input[type="checkbox"]', '#' + tabID).each(function (i, item) {
                if (typeof $(item).data('for-div-id') == 'undefined') {
                    if (!$(item).is(':checked')) {
                        allSelected = false;
                    }
                }
            });

            if (allSelected) {
                $('input[data-for-div-id="' + tabID + '"]').attr('checked', 'checked').prop('checked', true);
            }
        }

        var newSettingsSate = getSettingsState();

        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }

        return false;
    });


    /**
     * Checkbox Container Click
     */
    // $('.wpc-box-check,.wpc-iconcheckbox-toggle').on('click', function (e) {
    //     var parent = $(this);
    //     var checkbox = $('input[type="checkbox"]', parent);
    //
    //     var beforeValue = $(checkbox).attr('checked');
    //     if (beforeValue == 'checked') {
    //         // It was already active, remove checked
    //         $(checkbox).removeAttr('checked');
    //     } else {
    //         // It's not active, activate
    //         $(checkbox).attr('checked', 'checked');
    //     }
    // });


    /**
     * Single Checkbox
     */
    $('input[type="checkbox"].wpc-ic-settings-v4-checkbox').on('change', function () {
        var checkbox = $(this);
        var parent = $(checkbox).parents('.wpc-box-for-checkbox');
        var circle = $('.circle-check', parent);
        var beforeValue = $(checkbox).attr('checked');
        var showPopup = $(this).hasClass('wpc-show-popup');
        var popupID = $(this).data('popup');

        console.log('Checked');

        if ($(checkbox).hasClass('wpc-locked-checkbox')) {
            return false;
        }

        var connectedOption = $(checkbox).data('connected-slave-option');

        var outerParent = $(checkbox).parents('.wpc-tab-content-box');
        var id = $(outerParent).attr('id');
        var tabID = $(outerParent).attr('id');

        if (beforeValue == 'checked') {
            // It was already active, remove checked
            $(circle).removeClass('active');

            // It was already active, remove checked
            $(this).removeAttr('checked').prop('checked', false);
            $(parent).removeClass('active');
        } else {
            // It's not active, activate
            $(circle).addClass('active');

            // It's not active, activate
            $(this).attr('checked', 'checked').prop('checked', true);
            $(parent).addClass('active');
        }

        if ($('input[data-connected-option="' + connectedOption + '"]').length) {
            var slaveOption = $('input[data-connected-option="' + connectedOption + '"]');
            if (beforeValue == 'checked') {
                $(slaveOption).removeAttr('checked').prop('checked', false);
            } else {
                $(slaveOption).attr('checked', 'checked').prop('checked', true);
            }
        }

        checkIfAllSelected($(outerParent), '', 'select-all-' + id);

        //var previousSettingsState = settingsState;
        var newSettingsSate = getSettingsState();

        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }


    });


    /**
     * Connected Switch
     * - switch that is connected to change status of another switch
     */
    $('.wpc-checkbox-connected-option').on('change', function (e) {
        var beforeValue = $(this).attr('checked');
        var connectedOption = $(this).data('connected-option');
        var input = $('input[type="checkbox"].wpc-ic-settings-v4-checkbox#' + connectedOption);
        var parent = $(input).parents('.wpc-box-for-checkbox');
        var circle = $('.circle-check', parent);


        if (beforeValue == 'checked') {
            // It was already active, remove checked
            $(this).removeAttr('checked');
            $('input[type="checkbox"].wpc-ic-settings-v4-checkbox#' + connectedOption).removeAttr('checked').prop('checked', false);
            // Change Circle
            $(circle).removeClass('active');
        } else {
            // It's not active, activate
            $(this).attr('checked', 'checked');
            $('input[type="checkbox"].wpc-ic-settings-v4-checkbox#' + connectedOption).attr('checked', 'checked').prop('checked', true);
            // Change Circle
            $(circle).addClass('active');
        }

        var newSettingsSate = getSettingsState();

        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    });


    /**
     * Select All Checkbox
     */
    $('.wpc-checkbox-select-all').on('change', function (e) {
        var beforeValue = $(this).attr('checked');
        var divID = $(this).data('for-div-id');

        console.log('e');

        if ($(this).hasClass('wpc-locked-checkbox')) {
            return false;
        }

        console.log($(this));
        console.log($(this).hasClass('wpc-locked-checkbox'));

        if (beforeValue == 'checked') {
            // It was already active, remove checked
            $('.wpc-iconcheckbox', '#' + divID).removeClass('active');
            $(this).removeAttr('checked');
            $('input[type="checkbox"].wpc-ic-settings-v4-checkbox,input[type="checkbox"].wpc-ic-settings-v4-iconcheckbox', '#' + divID).removeAttr('checked').prop('checked', false);

            // Change Circle
            var circle = $('.circle-check', '#' + divID);
            $(circle).removeClass('active');
        } else {
            // It's not active, activate
            $('.wpc-iconcheckbox', '#' + divID).addClass('active');
            $(this).attr('checked', 'checked');
            $('input[type="checkbox"].wpc-ic-settings-v4-checkbox,input[type="checkbox"].wpc-ic-settings-v4-iconcheckbox', '#' + divID).attr('checked', 'checked').prop('checked', true);

            // Change Circle
            var circle = $('.circle-check', '#' + divID);
            $(circle).addClass('active');
        }


        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    });


    /**
     * Check if all checkboxes in div are selected
     * @param divID
     */
    function checkIfAllSelected(div, divID, allCheck = '') {
        var allSelected = true;
       
        $('input[type="checkbox"]', div).each(function (i, item) {
            if (typeof $(item).data('for-div-id') == 'undefined') {
                if ($(item).is(':checked') == false) {
                    allSelected = false;
                }
            }
        });

        if (allCheck != '') {
            if (allSelected) {
                $('input#' + allCheck).attr('checked', 'checked').prop('checked', true);
            } else {
                $('input#' + allCheck).removeAttr('checked').prop('checked', false);
            }
        } else {
            if (allSelected) {
                $('input.wpc-checkbox-select-all', div).attr('checked', 'checked').prop('checked', true);
            } else {
                $('input.wpc-checkbox-select-all', div).removeAttr('checked').prop('checked', false);
            }
        }
    }


});