jQuery(document).ready(function ($) {

    // ─── Floating save pill — detach when header scrolls out of view ───
    var $wpcHeader = $('.wpc-header');
    var $wpcPill = $('.wpc-save-pill');
    var wpcSaveActive = false;
    var wpcPillDocking = false;
    var wpcPillInterval = null;

    function isHeaderOutOfView() {
        if (!$wpcHeader.length) return false;
        // Float as soon as header top reaches the viewport top (generous threshold)
        var rect = $wpcHeader[0].getBoundingClientRect();
        return rect.top + rect.height * 0.5 < 0; // header is 50%+ scrolled off
    }

    function checkPillFloat() {
        if (!wpcSaveActive || wpcPillDocking) return;
        var shouldFloat = isHeaderOutOfView();

        if (shouldFloat && !$wpcPill.hasClass('wpc-save-pill-floating')) {
            $wpcPill.addClass('wpc-save-pill-floating');
        } else if (!shouldFloat && $wpcPill.hasClass('wpc-save-pill-floating')) {
            wpcPillDocking = true;
            $wpcPill.addClass('wpc-save-pill-docking');
            setTimeout(function() {
                $wpcPill.removeClass('wpc-save-pill-floating wpc-save-pill-docking');
                $wpcPill.addClass('wpc-save-pill-landed');
                setTimeout(function() { $wpcPill.removeClass('wpc-save-pill-landed'); }, 600);
                wpcPillDocking = false;
            }, 350);
        }
    }

    function startPillWatch() {
        if (wpcPillInterval || window.innerWidth < 769) return; // skip on mobile
        checkPillFloat();
        wpcPillInterval = setInterval(checkPillFloat, 150);
    }

    function stopPillWatch() {
        if (wpcPillInterval) { clearInterval(wpcPillInterval); wpcPillInterval = null; }
    }

    // ─── CF Zone Picker — event delegation (works before/after AJAX) ───

    // Prevent Enter from submitting form anywhere in CF connect form
    $(document).on('keydown', '.wpc-cf-connect-form', function (e) {
        if (e.key === 'Enter' && !$(e.target).is('.wpc-cf-zone-search')) {
            e.preventDefault();
            // If zone holder is visible and a zone is selected, trigger zone Connect
            if ($('#wpc-cf-zone-list-holder').is(':visible') && $('input[name="wpc-cf-zone"]').val()) {
                $('.wpc-cf-token-connect').trigger('click');
            } else {
                // Otherwise trigger token Connect
                $('.wpc-cf-token-check').trigger('click');
            }
        }
    });

    // Zone search — keyboard navigation (ArrowDown/Up to highlight, Enter to select)
    $(document).on('keydown', '.wpc-cf-zone-search', function (e) {
        var $dropdown = $(this).closest('.wpc-cf-zone-list-dropdown');
        var $items = $dropdown.find('.wpc-cf-zone-list-items > div:visible');
        var $active = $items.filter('.wpc-cf-zone-hover');
        var idx = $items.index($active);

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            $items.removeClass('wpc-cf-zone-hover');
            idx = idx < $items.length - 1 ? idx + 1 : 0;
            $items.eq(idx).addClass('wpc-cf-zone-hover');
            // Scroll into view
            $items.eq(idx)[0].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            $items.removeClass('wpc-cf-zone-hover');
            idx = idx > 0 ? idx - 1 : $items.length - 1;
            $items.eq(idx).addClass('wpc-cf-zone-hover');
            $items.eq(idx)[0].scrollIntoView({ block: 'nearest' });
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if ($active.length) {
                $active.trigger('click');
            }
        } else if (e.key === 'Escape') {
            $dropdown.hide();
        }
    });

    // Toggle dropdown on click
    $(document).on('click', '.wpc-cf-zone-list-selected', function (e) {
        e.stopPropagation();
        var $dropdown = $(this).siblings('.wpc-cf-zone-list-dropdown');
        var isOpening = !$dropdown.is(':visible');
        $dropdown.toggle();
        if (isOpening) {
            // Reset search and show all items
            var $search = $dropdown.find('.wpc-cf-zone-search');
            $search.val('');
            $dropdown.find('.wpc-cf-zone-list-items > div').show();
            $dropdown.find('.wpc-cf-zone-no-results').hide();
        }
    });

    // Search/filter zones
    $(document).on('input', '.wpc-cf-zone-search', function () {
        var query = $(this).val().toLowerCase();
        var $dropdown = $(this).closest('.wpc-cf-zone-list-dropdown');
        var $items = $dropdown.find('.wpc-cf-zone-list-items > div');
        var visible = 0;
        $items.removeClass('wpc-cf-zone-hover'); // clear keyboard selection on type
        $items.each(function () {
            var match = $(this).text().toLowerCase().indexOf(query) > -1;
            $(this).toggle(match);
            if (match) visible++;
        });
        $dropdown.find('.wpc-cf-zone-no-results').toggle(visible === 0);
    });

    // Select a zone
    $(document).on('click', '.wpc-cf-zone-list-items > div', function () {
        var selectedValue = $(this).data('selected-zone');
        var selectedID = $(this).data('selected-zone-id');

        $('.wpc-cf-zone-list-dropdown').hide();
        $('input[name="wpc-cf-zone"]').val(selectedID);
        $('.wpc-cf-zone-text').text(selectedValue);
        $('.wpc-cf-zone-list-selected').addClass('has-value');

        return false;
    });

    // Close dropdown on outside click
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.wpc-cf-zone-list').length) {
            $('.wpc-cf-zone-list-dropdown').hide();
        }
    });

    // Prevent zone search clicks from closing dropdown
    $(document).on('click', '.wpc-cf-zone-list-dropdown', function (e) {
        e.stopPropagation();
    });

    // ─── CF Token Check ───

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
            wps_ic_nonce: wpc_ajaxVar.nonce,
        }, function (response) {

            if (response.success) {
                $('.wpc-cf-zone-list-items', '#wpc-cf-zone-list-holder').html(response.data);
                $('.wpc-cf-loader').hide(function () {
                    $('#wpc-cf-zone-list-holder').show();
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
            wps_ic_nonce: wpc_ajaxVar.nonce,
        }, function (response) {

            if(response.success) {
                $.post(ajaxurl, {
                    action: 'wpc_ic_setupCF',
                    token: cFToken,
                    zone: cFZone,
                    wps_ic_nonce: wpc_ajaxVar.nonce,
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

                if (response.data.msg){
                    $('.wpc-cf-loader-error').html(response.data.msg).show();

                } else {
                    $('.wpc-cf-loader-error').html('Looks like your API Token does not have correct privileges or it\'s invalid').show();
                }
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
            wps_ic_nonce: wpc_ajaxVar.nonce,
            _nonce: Math.random().toString(36).substr(2, 9), // Add a random hash
        }, function (response) {
            window.location.reload();
        });
        return false;
    });



    $('.wpc-cf-token-verify').on('click', function(e){
        $('.wpc-cf-token-hide-on-load').hide();
        $('.wpc-cf-token-connected').hide();
        $('.wpc-cf-loader-disconnecting').hide();
        $('.wpc-cf-loader-refreshing').show();
        $('.wpc-cf-loader-error').hide();

        e.preventDefault();
        $.post(ajaxurl, {
            action: 'wpc_ic_refreshCFConnection',
            wps_ic_nonce: wpc_ajaxVar.nonce,
            timeout:120,
            _nonce: Math.random().toString(36).substr(2, 9), // Add a random hash
        }, function (response) {
            window.location.reload();
        });

        return false;
    });





    // ─── AJAX Save — world-class pill animation ──────────────────────────
    var wpcAdvInitialStates = {};
    var wpcAdvPendingChanges = {};

    // Capture initial states for ALL checkbox types + dropdown hidden inputs
    $('.wpc-ic-settings-v2-checkbox, .wpc-ic-settings-v4-iconcheckbox, .wpc-ic-settings-v4-checkbox, .wpc-eu-routing-checkbox').each(function() {
        var name = $(this).data('option-name') || $(this).attr('name');
        if (name) wpcAdvInitialStates[name] = $(this).prop('checked');
    });
    $('input[type="hidden"]', '.wpc-settings-body .wpc-box-check').each(function() {
        var name = $(this).attr('name');
        if (name) wpcAdvInitialStates[name] = $(this).val();
    });

    // Track changes from v2 checkbox toggles
    $(document).on('wpc-setting-changed', function(e, name, isChecked) {
        if (wpcAdvInitialStates[name] !== undefined && isChecked !== wpcAdvInitialStates[name]) {
            wpcAdvPendingChanges[name] = isChecked ? '1' : '0';
        } else {
            delete wpcAdvPendingChanges[name];
        }
    });

    $('.wpc-save-button').on('click', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $pill = $('.save-button');
        var btnOrigHTML = $btn.html();

        if ($btn.hasClass('saving')) return false;
        $btn.addClass('saving');


        // Phase 1: Saving settings — disable all toggles to prevent race conditions
        $btn.addClass('wpc-saving').css('pointer-events', 'none');
        $btn.html('<span class="wpc-save-pill-spinner"></span> ' + (wpc_ajaxVar.saving || 'Saving...'));
        $('.wpc-settings-body').css('pointer-events', 'none').css('opacity', '0.7');

        // Collect ONLY changed checkboxes (compare to initial state)
        var changes = [];
        $('.wpc-ic-settings-v2-checkbox, .wpc-ic-settings-v4-iconcheckbox, .wpc-ic-settings-v4-checkbox, .wpc-eu-routing-checkbox').each(function() {
            var name = $(this).data('option-name') || $(this).attr('name');
            var checked = $(this).prop('checked');
            if (name) {
                var ajaxName = name.replace(/^options\[/, '').replace(/\]/g, '').replace(/\[/g, ',');
                var initialValue = wpcAdvInitialStates[name];
                // Only include if changed from initial state
                if (initialValue === undefined || initialValue !== checked) {
                    changes.push({
                        name: ajaxName,
                        value: checked ? '1' : '0',
                        checked: checked ? 'true' : 'false'
                    });
                }
            }
        });

        // Collect dropdown hidden inputs — only if value changed
        $('input[type="hidden"]', '.wpc-settings-body .wpc-box-check').each(function() {
            var name = $(this).attr('name');
            var value = $(this).val();
            if (name) {
                var ajaxName = name.replace(/^options\[/, '').replace(/\]/g, '').replace(/\[/g, ',');
                var initialValue = wpcAdvInitialStates[name];
                if (initialValue === undefined || initialValue !== value) {
                    changes.push({
                        name: ajaxName,
                        value: value,
                        checked: 'false'
                    });
                }
            }
        });

        // Also grab quality levels
        var optLevel = $('#optimizationLevel').val();
        var optLevelImg = $('#optimizationLevel_img').val();

        // Batch save all settings in a single request (prevents race conditions)
        var hadError = false;

        if (changes.length === 0) {
            // Nothing changed — dismiss pill without saving or purging
            $btn.removeClass('wpc-saving saving').css('pointer-events', '');
            $btn.html(btnOrigHTML);
            $pill.fadeOut(300);
            return false;
        }

        var changeKeys = changes.map(function(c) { return c.name; });
        var saveStartTime = Date.now();

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            timeout: 120000,
            data: {
                action: 'wps_ic_ajax_v2_checkbox_batch',
                changes: JSON.stringify(changes),
                wps_ic_nonce: wpc_ajaxVar.nonce
            },
            success: function() { onAllSaved(); },
            error: function() { hadError = true; onAllSaved(); }
        });

        function onAllSaved() {
            if (hadError) {
                $btn.removeClass('wpc-saving saving').css('pointer-events', '');
                $btn.html(btnOrigHTML);
                $('.wpc-settings-body').css('pointer-events', '').css('opacity', '');
                return;
            }

            // Saved — update initial states
            wpcAdvPendingChanges = {};
            $('.wpc-ic-settings-v2-checkbox, .wpc-ic-settings-v4-iconcheckbox, .wpc-ic-settings-v4-checkbox, .wpc-eu-routing-checkbox').each(function() {
                var name = $(this).data('option-name') || $(this).attr('name');
                if (name) wpcAdvInitialStates[name] = $(this).prop('checked');
            });
            $('input[type="hidden"]', '.wpc-settings-body .wpc-box-check').each(function() {
                var name = $(this).attr('name');
                if (name) wpcAdvInitialStates[name] = $(this).val();
            });
            setSettingsState();

            // Show refresh badge for admin bar settings
            var adminBarSettings = ['options_status_hide_in_admin_bar', 'options_status_show_admin_bar_title'];
            changeKeys.forEach(function(name) {
                if (adminBarSettings.indexOf(name) !== -1) {
                    var $card = $('.wpc-ic-settings-v4-checkbox[name="' + name + '"]').closest('.wpc-box-for-checkbox');
                    if (!$card.find('.wpc-refresh-badge').length) {
                        $card.find('.wpc-checkbox-title-holder h4').append(
                            '<span class="wpc-refresh-badge">' + (wpc_ajaxVar.refreshRequired || 'Refresh required') + '</span>'
                        );
                    }
                }
            });

            // Purge cache silently in background (pass changed keys so server can decide what to purge)
            $.post(ajaxurl, {
                action: 'wps_ic_purge_after_save',
                wps_ic_nonce: wpc_ajaxVar.nonce,
                changed_keys: changeKeys
            });

            // Ensure minimum 1.5s of "Saving..." before showing "Saved!"
            var elapsed = Date.now() - saveStartTime;
            var savingDelay = Math.max(0, 800 - elapsed);

            setTimeout(function(){
                $btn.removeClass('wpc-saving').addClass('wpc-saved');
                $btn.html('<span class="wpc-save-pill-check-ico"></span> ' + (wpc_ajaxVar.saved || 'Saved') + '!');

                // Reload page if CF CDN or Static Assets changed
                var cfReloadSettings = ['cf,cdn', 'cf,assets', 'status,show_admin_bar_title'];
                var needsReload = changeKeys.some(function(k) { return cfReloadSettings.indexOf(k) !== -1; });

                if (needsReload) {
                    setTimeout(function(){ window.location.reload(); }, 1000);
                    return;
                }

                // Show "Saved!" for 1s then slide away
                setTimeout(function(){
                    $pill.css({
                        'transition': 'all 0.5s cubic-bezier(0.16, 1, 0.3, 1)',
                        'opacity': '0',
                        'transform': 'translateY(-8px) scale(0.98)'
                    });

                    setTimeout(function(){
                        $pill.hide().css({ 'opacity': '', 'transform': '', 'transition': '' });
                        $btn.removeClass('wpc-saved saving').css('pointer-events', '');
                        $btn.html(btnOrigHTML);
                        $('.wpc-settings-body').css('pointer-events', '').css('opacity', '');
                        wpcSaveActive = false;
                        stopPillWatch();
                        $wpcPill.removeClass('wpc-save-pill-floating');
                    }, 500);
                }, 1000);
            }, savingDelay);
        }

        return false;
    });


    function showSaveButton() {
        wpcSaveActive = true;
        $('.save-button').fadeIn(500);
        startPillWatch();
        //$('.wpc-preset-dropdown>option').removeAttr('selected').prop('selected', false);
        //$('.wpc-preset-dropdown>option:eq(2)').attr('selected', 'selected').prop('selected', true);

        $('input[name="wpc_preset_mode"]').val('custom');
        $('a', '.wpc-dropdown-menu').removeClass('active');
        $('button', '.wpc-dropdown').html('Custom');
        $('a[data-value="custom"]', '.wpc-dropdown-menu').addClass('active');

        //var selectedValue = $('.wpc-preset-dropdown').val();
        $.post(ajaxurl, {
            action: 'wpc_ic_ajax_set_preset',
            wps_ic_nonce: wpc_ajaxVar.nonce,
            value: 'custom',
        }, function (response) {

        });

    }


    function hideSaveButton() {
        wpcSaveActive = false;
        stopPillWatch();
        $wpcPill.removeClass('wpc-save-pill-floating');
        $('.save-button').fadeOut(500);
    }


    /**
     * Range slider — update fill + active label
     */
    function updateSlider(input) {
        if (!input) return;
        var val = Number(input.value);
        var min = Number(input.min) || 1;
        var max = Number(input.max) || 3;
        var pct = ((val - min) / (max - min)) * 100;

        // Set CSS custom property for ::before fill width
        var container = input.closest('.wpc-range-slider');
        if (container) container.style.setProperty('--slider-pos', pct + '%');

        // Update active label
        var texts = input.closest('.wpc-opt-level-slider') || input.closest('.wpc-slider');
        if (texts) {
            $(texts).find('.wpc-slider-text>div').removeClass('active');
            $(texts).find('.wpc-slider-text>div[data-value="' + val + '"]').addClass('active');
        }
    }

    // Init + live drag
    (function() {
        var range = document.getElementById('optimizationLevel');
        if (range) {
            updateSlider(range);
            range.addEventListener('input', function() { updateSlider(range); });
        }
        // localQualityLevel is now a custom dropdown, no slider init needed
    })();

    /**
     * Slider Click on Text
     */
    $('.wpc-slider-text>div').on('click', function (e) {
        e.preventDefault();
        var selectedValue = $(this).data('value');
        var $slider = $(this).closest('.wpc-opt-level-slider');
        var range = $slider.find('input[type="range"]')[0] || document.getElementById('optimizationLevel');

        if (range) {
            range.value = selectedValue;
            updateSlider(range);
            $(range).trigger('change');
        }

        // Legacy support
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
     * Dropdown Button — click to toggle
     */
    $('button', '.wpc-dropdown').on('click', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var menu = $(this).siblings('.wpc-dropdown-menu');
        var isOpen = menu.hasClass('show');

        // Close any other open dropdowns first
        $('.wpc-dropdown-menu').removeClass('show');

        if (!isOpen) {
            menu.addClass('show');
        }
    });

    // Close dropdown when clicking outside
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.wpc-dropdown').length) {
            $('.wpc-dropdown-menu').removeClass('show');
        }
    });

    $('.dropdown-item', '.wpc-dropdown-menu').on('click', function (e) {
        e.preventDefault();

        var item = $(this);
        var value = $(this).data('value');
        var presetTitle = $(this).data('preset-title');
        $('input[name="wpc_preset_mode"]').val(value);

        $('.dropdown-item', '.wpc-dropdown-menu').removeClass('active');
        $(item).addClass('active');

        $('.wpc-dropdown-menu').removeClass('show');
        $('.wpc-dropdown>button').text(presetTitle);

        $.post(ajaxurl, {
            action: 'wpc_ic_ajax_set_preset',
            value: value,
            wps_ic_nonce: wpc_ajaxVar.nonce
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

    // Full-card click for standalone toggle cards (EU Routing, Auto-Optimize)
    $('.wpc-tab-content-eu-routing, .wpc-auto-optimize-card').on('click', function (e) {
        if ($(e.target).is('input, label, .wpc-switch, .wpc-switch-slider')) return;
        var $cb = $('input[type="checkbox"]', this);
        $cb.prop('checked', !$cb.prop('checked')).trigger('change');
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

                var support_url = (typeof whtlbl_vars !== 'undefined' && whtlbl_vars.author_url)
                    ? whtlbl_vars.author_url
                    : 'https://wpcompress.com/support/';

                var compatPopups = ['delay-js', 'combine-js', 'combine-css', 'connectivity'];
                var swalWidth = (popupID === 'hide_compress') ? 480 : (compatPopups.indexOf(popupID) !== -1 ? 680 : 600);

                WPCSwal.fire({
                    title: '',
                    html: jQuery('#' + popupID + '-popup').html(),
                    width: swalWidth,
                    showCloseButton: true,
                    showCancelButton: false,
                    showConfirmButton: showConfirmButton,
                    allowOutsideClick: false,
                    customClass: {
                        container: 'no-padding-popup-bottom-bg switch-legacy-popup ' + popupClass,
                        popup: 'popup-' + popupID,
                    },
                    onOpen: () => {
                        // Force width for SaaS-style popups
                        if (popupID === 'hide_compress') {
                            $('.swal2-popup').css('width', '480px');
                        }

                        if (!showConfirmButton) {
                            $('.wpc-popup-cancel').on('click', function(e){
                                e.preventDefault();
                                WPCSwal.clickCancel();
                                window.open(support_url, '_blank');
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

        // Update Select All / Deselect All button text
        var tab = $(box).parents('.wpc-tab-content');
        var tabID = $(tab).attr('id');
        if (tabID) {
            updateSelectAllButton(tabID);
        }

        var newSettingsSate = getSettingsState();

        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    });


    /***
     * Dropdown Change on container
     */
    $('.wpc-dropdown-setting').on('change', function (e) {
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
            wps_ic_nonce: wpc_ajaxVar.nonce
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
                        settingsState.push(state);
                    }
                }
            }
        });

        // Handle ALL dropdown hidden inputs (CF, font-display, icon-font-display, replace-fonts, etc.)
        $('input[type="hidden"]', '.wpc-settings-body .wpc-box-check').each(function() {
            settingsState.push($(this).val());
        });
    }

    function getSettingsState() {
        var debug = [];
        var getSettingsState = [];

        // Handle checkboxes and range inputs
        $('input[type="checkbox"],input[type="range"]', '.wpc-settings-body').each(function (i, item) {
            var checkbox = $(item);
            var state = 0;
            if (!$(checkbox).hasClass('wpc-checkbox-select-all') && !$(checkbox).hasClass('wpc-checkbox-connected-option')) {
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
                        state = parseInt(state);
                        getSettingsState.push(state);
                    }
                }
            }
        });

        // Handle ALL dropdown hidden inputs (CF, font-display, icon-font-display, replace-fonts, etc.)
        $('input[type="hidden"]', '.wpc-settings-body .wpc-box-check').each(function() {
            getSettingsState.push($(this).val());
        });

        return getSettingsState;
    }

    setSettingsState();

    // EU Routing toggle — trigger save pill on change
    $('.wpc-eu-routing-checkbox').on('change', function () {
        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    });

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

        // Flash tooltip for 3s on click (touch device support)
        $('.wpc-settings-tab-list li>a').removeClass('wpc-tooltip-flash');
        $(link).addClass('wpc-tooltip-flash');
        setTimeout(function() {
            $(link).removeClass('wpc-tooltip-flash');
        }, 3000);


        $('.wpc-settings-tab-content-inner>div.wpc-tab-content').hide();

        $('.wpc-tab-content-box', '#' + data).each(function (i, item) {
            checkIfAllSelected($(item), data);
        });

        $('div#' + data, '.wpc-settings-tab-content').addClass('active-tab').fadeIn(400, function() {
            // Recreate chart fresh when switching to dashboard — fixes left-slide animation
            if (data === 'dashboard' && window.wpcInitChart) {
                window.wpcInitChart(true);
            } else if (window.myLine) {
                window.myLine.resize();
            }
        });
        $(currentActiveContent).removeClass('active-tab');

        // Close mobile drawer after tab switch
        var $wrapper = $('.wpc-advanced-settings-container-v4');
        if ($wrapper.hasClass('wpc-mobile-menu-open')) {
            $wrapper.removeClass('wpc-mobile-menu-open');
            $('#wpc-mobile-menu-toggle').attr('aria-expanded', 'false');
            document.body.style.overflow = '';
        }

        // Smooth scroll to plugin container so user sees the new section heading
        var pluginTop = document.querySelector('.wpc-advanced-settings-container-v4');
        if (pluginTop) {
            pluginTop.scrollIntoView({ behavior: 'smooth' });
        }

        return false;
    });

    var hash = window.location.hash;
    // Activate the correct tab (from hash or default to dashboard)
    // Strip any query params that may leak into the hash (e.g. #tab&foo=bar)
    var targetTab = (hash != '') ? hash.replace('#', '').split('&')[0].split('?')[0] : 'dashboard';

    // Initial tab activation — show instantly (no fadeIn) to avoid flash
    var $initLink = $('.wpc-settings-tab-list li>a[data-tab="' + targetTab + '"]');
    $initLink.addClass('active');
    $('div#' + targetTab, '.wpc-settings-tab-content').addClass('active-tab').show();
    history.replaceState({}, "", "#" + targetTab);

    // Init chart after initial tab is visible (handles dashboard on direct load)
    if (window.wpcInitChart) { setTimeout(window.wpcInitChart, 250); }

    // Reveal the settings body with entrance animation
    requestAnimationFrame(function() {
        $('.wpc-settings-body').addClass('wpc-tabs-ready');
    });


    $('.wpc-ic-settings-v4-iconcheckbox').on('change', function (e) {
        e.preventDefault();

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
            updateSelectAllButton(tabID);
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
            updateSelectAllButton(tabID);
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
     * Select All Button — toggles all checkboxes in the target section
     */
    $(document).on('click', '.wpc-select-all-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var divID = $btn.data('for-div-id');
        var $checkbox = $btn.closest('.form-check').find('.wpc-checkbox-select-all');

        if ($checkbox.hasClass('wpc-locked-checkbox')) {
            return false;
        }

        var isChecked = $checkbox.attr('checked') === 'checked';

        if (isChecked) {
            // Deselect all
            $('.wpc-iconcheckbox', '#' + divID).removeClass('active');
            $checkbox.removeAttr('checked').prop('checked', false);
            $('input[type="checkbox"].wpc-ic-settings-v4-checkbox,input[type="checkbox"].wpc-ic-settings-v4-iconcheckbox', '#' + divID).removeAttr('checked').prop('checked', false);
            $('.circle-check', '#' + divID).removeClass('active');
            $btn.text(wpc_ajaxVar.selectAll).removeClass('active');
        } else {
            // Select all
            $('.wpc-iconcheckbox', '#' + divID).addClass('active');
            $checkbox.attr('checked', 'checked').prop('checked', true);
            $('input[type="checkbox"].wpc-ic-settings-v4-checkbox,input[type="checkbox"].wpc-ic-settings-v4-iconcheckbox', '#' + divID).attr('checked', 'checked').prop('checked', true);
            $('.circle-check', '#' + divID).addClass('active');
            $btn.text(wpc_ajaxVar.deselectAll).addClass('active');
        }

        var newSettingsSate = getSettingsState();
        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    });

    // Keep legacy .wpc-checkbox-select-all change handler for programmatic triggers
    $('.wpc-checkbox-select-all').on('change', function (e) {
        var divID = $(this).data('for-div-id');
        if ($(this).hasClass('wpc-locked-checkbox')) return false;
        updateSelectAllButton(divID);
    });


    /**
     * Update Select All button text based on checkbox states
     */
    function updateSelectAllButton(divID) {
        var allSelected = true;
        $('input[type="checkbox"].wpc-ic-settings-v4-checkbox,input[type="checkbox"].wpc-ic-settings-v4-iconcheckbox', '#' + divID).each(function () {
            if (!$(this).is(':checked')) {
                allSelected = false;
                return false;
            }
        });

        var $btn = $('.wpc-select-all-btn[data-for-div-id="' + divID + '"]');
        var $checkbox = $('input.wpc-checkbox-select-all[data-for-div-id="' + divID + '"]');

        if (allSelected) {
            $checkbox.attr('checked', 'checked').prop('checked', true);
            $btn.text(wpc_ajaxVar.deselectAll).addClass('active');
        } else {
            $checkbox.removeAttr('checked').prop('checked', false);
            $btn.text(wpc_ajaxVar.selectAll).removeClass('active');
        }
    }

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

        // Also update button text
        var sectionDivID = $(div).attr('id') || divID;
        if (sectionDivID) {
            updateSelectAllButton(sectionDivID);
        }
    }

    // On page load — set correct button text for all Select All buttons
    $('.wpc-select-all-btn').each(function () {
        var divID = $(this).data('for-div-id');
        if (divID) {
            updateSelectAllButton(divID);
        }
    });

    // Toggle dropdown visibility for CF dropdown
    $(document).on('click', '.wpc-cf-dropdown .wpc-cf-dropdown-toggle', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $dropdown = $(this).closest('.wpc-cf-dropdown');
        var isExpanded = $(this).attr('aria-expanded') === 'true';

        // Close all other CF dropdowns
        $('.wpc-cf-dropdown').removeClass('show');
        $('.wpc-cf-dropdown .wpc-cf-dropdown-toggle').attr('aria-expanded', 'false');
        $('.wpc-cf-dropdown-menu').removeClass('show');

        // Toggle current dropdown
        if (!isExpanded) {
            $dropdown.addClass('show');
            $(this).attr('aria-expanded', 'true');
            $dropdown.find('.wpc-cf-dropdown-menu').addClass('show');
        }
    });

    // Handle dropdown item selection for CF dropdown
    // Toggle CF dropdown
    $(document).on('click', '.wpc-cf-select-button', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $dropdown = $(this).closest('.wpc-cf-select-dropdown');
        var isOpen = $dropdown.hasClass('is-open');

        // Close all CF dropdowns and reset z-index lift
        $('.wpc-cf-select-dropdown').removeClass('is-open');
        $('.wpc-tab-content-box').removeClass('wpc-dropdown-lifted');

        // Toggle current dropdown
        if (!isOpen) {
            $dropdown.addClass('is-open');
            // Lift parent card above siblings so menu isn't clipped by stacking context
            $dropdown.closest('.wpc-tab-content-box').addClass('wpc-dropdown-lifted');
        }
    });

    // Handle CF dropdown item selection
    $(document).on('click', '.wpc-cf-select-item', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $item = $(this);
        var $dropdown = $item.closest('.wpc-cf-select-dropdown');
        var $button = $dropdown.find('.wpc-cf-select-button');
        var $hiddenInput = $dropdown.closest('.wpc-box-check').find('input[type="hidden"]');

        var value = $item.data('value');
        var title = $item.data('preset-title');

        // Update hidden input value
        $hiddenInput.val(value);

        // Update button selected text (leave chevron SVG intact)
        $button.find('.selected-text').text(title);

        // Update active state
        $dropdown.find('.wpc-cf-select-item').removeClass('wpc-cf-active');
        $item.addClass('wpc-cf-active');

        // Close dropdown and reset z-index lift
        $dropdown.removeClass('is-open');
        $dropdown.closest('.wpc-tab-content-box').removeClass('wpc-dropdown-lifted');

        var newSettingsSate = getSettingsState();


        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    });

    // Close CF dropdown when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.wpc-cf-select-dropdown').length) {
            $('.wpc-cf-select-dropdown').removeClass('is-open');
            $('.wpc-tab-content-box').removeClass('wpc-dropdown-lifted');
        }
    });

    // Toggle dropdown visibility for font dropdown
    $(document).on('click', '.wpc-font-dropdown .wpc-font-dropdown-toggle', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $dropdown = $(this).closest('.wpc-font-dropdown');
        var isExpanded = $(this).attr('aria-expanded') === 'true';

        // Close all other font dropdowns
        $('.wpc-font-dropdown').removeClass('show');
        $('.wpc-font-dropdown .wpc-font-dropdown-toggle').attr('aria-expanded', 'false');
        $('.wpc-font-dropdown-menu').removeClass('show');

        // Toggle current dropdown
        if (!isExpanded) {
            $dropdown.addClass('show');
            $(this).attr('aria-expanded', 'true');
            $dropdown.find('.wpc-font-dropdown-menu').addClass('show');
        }
    });

    // Handle dropdown item selection for font dropdown
    // Toggle font dropdown
    $(document).on('click', '.wpc-font-select-button', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $dropdown = $(this).closest('.wpc-font-select-dropdown');
        var $menu = $dropdown.find('.wpc-font-select-menu');
        var isOpen = $menu.is(':visible');

        // Close all font dropdowns
        $('.wpc-font-select-menu').hide();

        // Toggle current dropdown
        if (!isOpen) {
            $menu.show();
        }
    });

    // Handle font dropdown item selection
    $(document).on('click', '.wpc-font-select-item', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $item = $(this);
        var $dropdown = $item.closest('.wpc-font-select-dropdown');
        var $button = $dropdown.find('.wpc-font-select-button');
        var $hiddenInput = $dropdown.closest('.wpc-box-check').find('input[type="hidden"]');

        var value = $item.data('value');
        var title = $item.data('preset-title');

        // Update hidden input value
        $hiddenInput.val(value);

        // Update button text
        $button.text(title);

        // Update active state
        $dropdown.find('.wpc-font-select-item').removeClass('wpc-font-active');
        $item.addClass('wpc-font-active');

        // Close dropdown
        $dropdown.find('.wpc-font-select-menu').hide();

        var newSettingsSate = getSettingsState();

        if (didSettingsChanged(settingsState, newSettingsSate)) {
            showSaveButton();
        } else {
            hideSaveButton();
        }
    });

    // Close font dropdown when clicking outside
    $(document).on('click', function(e) {
        if (!$(e.target).closest('.wpc-font-select-dropdown').length) {
            $('.wpc-font-select-menu').hide();
        }
    });


    // ─── Shared: Inject tooltips + configure labels into perf-grid cards ──
    function injectPerfGridEnhancements(container) {
        var infoSvg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><path d="M12 16v-4"></path><path d="M12 8h.01"></path></svg>';

        $(container).find('.wpc-perf-grid > .wpc-box-for-checkbox, .wpc-perf-grid > .wpc-box-for-input').each(function() {
            var $p = $(this).find('.wpc-box-content > p');
            var $h4 = $(this).find('.wpc-checkbox-title-holder h4');
            if ($p.length && $h4.length && !$h4.find('.wpc-info-trigger').length) {
                var tooltipText = $p.text().trim();
                if (tooltipText) {
                    var trigger = $('<span class="wpc-info-trigger">' + infoSvg + '<span class="wpc-info-tooltip">' + $p.html() + '</span></span>');
                    $h4.append(trigger);
                    $p.remove();
                }
            }
        });

        $(container).find('.wpc-perf-grid .wps-ic-configure-popup .wpc-gear-icon').each(function() {
            var $link = $(this).closest('.wps-ic-configure-popup');
            if (!$link.find('.wpc-configure-label').length) {
                $link.append('<span class="wpc-configure-label">Configure</span>');
            }
        });
    }

    // ─── Image Optimization: Perf-grid enhancements ───────────────────────
    (function() {
        var container = document.getElementById('image-optimization-options');
        if (container) injectPerfGridEnhancements(container);
    })();

    // ─── Font Optimization: Perf-grid enhancements ──────────────────────
    (function() {
        var container = document.getElementById('scan-fonts');
        if (container) injectPerfGridEnhancements(container);
    })();

    // ─── UX Settings: Perf-grid enhancements ──────────────────────────────
    (function() {
        var container = document.getElementById('ux-settings-options');
        if (container) injectPerfGridEnhancements(container);
    })();

    // ─── Performance Tweaks: Compact Mode ─────────────────────────────────
    (function() {
        var container = document.getElementById('performance-tweaks-options');
        if (!container) return;

        // Inject section counter pills into each header
        $(container).find('.wpc-compact-rows .tab-title-checkbox').each(function() {
            if (!$(this).find('.wpc-section-counter').length) {
                $(this).append('<span class="wpc-section-counter"></span>');
            }
        });

        // Counter updates — global + per-section
        function updatePerfCounters() {
            var totalActive = 0, totalAll = 0;

            $(container).find('.wpc-compact-rows').each(function() {
                var boxes = $(this).find('.wpc-switch input[type="checkbox"]');
                var checked = $(this).find('.wpc-switch input[type="checkbox"]:checked');
                var counter = $(this).find('.wpc-section-counter');
                if (counter.length) counter.text(checked.length + ' / ' + boxes.length);
                totalActive += checked.length;
                totalAll += boxes.length;
            });

            var activeEl = document.getElementById('wpc-perf-active');
            var totalEl = document.getElementById('wpc-perf-total');
            if (activeEl) activeEl.textContent = totalActive;
            if (totalEl) totalEl.textContent = totalAll;
        }

        // Listen for any toggle change
        $(container).on('change', '.wpc-switch input', updatePerfCounters);

        // Initial count
        setTimeout(updatePerfCounters, 500);

        // Row click → expand/collapse description
        $(container).on('click', '.wpc-compact-rows .wpc-box-for-checkbox, .wpc-compact-rows .wpc-box-for-input', function(e) {
            // Don't toggle if clicking switch, configure, or cog
            if ($(e.target).closest('.wpc-switch, .wpc-box-check, .wps-ic-configure-popup, .wpc-box-button').length) return;
            $(this).toggleClass('wpc-row-expanded');
        });

        // Inject info-tooltip triggers + configure labels into perf-grid cards
        injectPerfGridEnhancements(container);

        // Global show/hide descriptions
        var toggleBtn = document.getElementById('wpc-toggle-perf-descriptions');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', function() {
                var parent = document.getElementById('performance-tweaks-options');
                parent.classList.toggle('wpc-show-descriptions');
                var showing = parent.classList.contains('wpc-show-descriptions');
                this.textContent = showing ? 'Hide descriptions' : 'Show descriptions';
                this.classList.toggle('active', showing);
            });
        }
    })();


});