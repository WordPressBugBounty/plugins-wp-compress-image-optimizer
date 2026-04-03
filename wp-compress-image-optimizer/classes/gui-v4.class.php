<?php

class wpc_gui_v4 extends wps_ic
{


    public static $options;
    public static $default;
    public static $safe;
    public static $stats_local;
    public static $stats_local_sum;
    public static $stats_live;
    public static $user_credits;
    public static $accountQuota;
    public static $slug;

    // Popup ID → [option_group, option_key] for override detection
    private static $popup_option_map = [
        'exclude-critical-css'          => ['wpc-excludes', 'critical_css'],
        'exclude-js-delay-v2'           => ['wpc-excludes', 'delay_js_v2'],
        'exclude-advanced-caching-popup' => ['wpc-excludes', 'cache'],
        'exclude-simple-caching'        => ['wpc-excludes', 'simple_caching'],
        'exclude-inline-css'            => ['wpc-excludes', 'inline_css'],
        'exclude-minify-html'           => ['wpc-excludes', 'minify_html'],
        'exclude-js-defer'              => ['wpc-excludes', 'defer_js'],
        'exclude-js-combine'            => ['wpc-excludes', 'combine_js'],
        'exclude-js-minify'             => ['wpc-excludes', 'js_minify'],
        'exclude-css-minify'            => ['wpc-excludes', 'css_minify'],
        'exclude-css-render-blocking'   => ['wpc-excludes', 'css_render_blocking'],
        'exclude-css-combine'           => ['wpc-excludes', 'css_combine'],
        'exclude-scripts-to-footer'     => ['wpc-excludes', 'exclude-scripts-to-footer'],
        'delay-js-configuration'        => ['wpc-excludes', 'lastLoadScript'],
        'exclude-lazy-popup'            => ['wpc-excludes', 'lazy'],
        'exclude-adaptive-popup'        => ['wpc-excludes', 'adaptive'],
        'exclude-webp-popup'            => ['wpc-excludes', 'webp'],
        'exclude-cdn-popup'             => ['wpc-excludes', 'cdn'],
    ];

    private static $excludes_cache = null;

    /**
     * Check if a configure popup has stored overrides (non-empty exclude list)
     */
    public static function hasPopupOverrides($popup_id) {
        if (empty($popup_id) || !isset(self::$popup_option_map[$popup_id])) {
            return false;
        }

        $map = self::$popup_option_map[$popup_id];

        if (self::$excludes_cache === null) {
            self::$excludes_cache = [
                'wpc-excludes'     => get_option('wpc-excludes', []),
                'wpc-url-excludes' => get_option('wpc-url-excludes', []),
            ];
        }

        $group = $map[0];
        $key   = $map[1];
        $data  = isset(self::$excludes_cache[$group][$key]) ? self::$excludes_cache[$group][$key] : [];

        if (is_array($data)) {
            $data = array_filter($data, function($v) { return trim($v) !== ''; });
            return !empty($data);
        }

        return !empty($data);
    }

    public function __construct($options = [])
    {
        self::$user_credits = parent::getAccountStatusMemory();
        self::$slug = parent::$slug;

        if (is_multisite()) {
            $current_blog_id = get_current_blog_id();
            switch_to_blog($current_blog_id);
        }

        $options_class = new wps_ic_options();
        self::$default = $options_class->getDefault();
        self::$safe = $options_class->getSafe();

        if (empty($options)) {
            self::$options = get_option(WPS_IC_SETTINGS);
        } else {
            self::$options = $options;
        }

        // Update Stats
        /*
        $lastUpdate = get_transient('wps_ic_stats_update');
        if (empty($lastUpdate) || !$lastUpdate) {
            $settings = get_option(WPS_IC_OPTIONS);
            if (!empty($settings['api_key'])) {
                $getStats = wp_remote_get(WPS_IC_KEYSURL . '?apikey=' . $settings['api_key'] . '&action=pullStats', ['timeout' => 10, 'sslverify' => 'false', 'user-agent' => WPS_IC_API_USERAGENT]);

                // Set transient only if the response is 200 for stats update
                if (wp_remote_retrieve_response_code($getStats) == 200) {
                    set_transient('wps_ic_stats_update', 'true', 60 * 5);
                }
            }
        }
        */

        $statsclass = new wps_ic_stats();


        $stats_live = $statsclass->fetch_live_stats();

        if (isset ($stats_live) && !empty($stats_live)) {
            self::$stats_live = $stats_live;
        }


        if (!empty(self::$user_credits)) {
            if (empty(self::$user_credits->account->quotaType)) {
                self::$user_credits->account->quotaType = 'requests';
            }

            // Get Account Quota
            self::$accountQuota = parent::getAccountQuota(self::$user_credits, self::$user_credits->account->quotaType);
        }
    }

    public static function optimizeMediaLibrary($title = 'Demo', $id = 'optimizationLevel', $description = 'Demo', $icon = '', $notify = '', $option = 'default', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left')
    {
        $html = '';

        $lockedClass = '';
        if ($locked) {
            $lockedClass = 'wpc-locked-setting';
        }

        $bulkProcess = get_option('wps_ic_bulk_process');

        if (!is_array($option)) {
            $tooltipID = 'option_tooltip_' . $option;
        } else {
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];
        }

        $html .= '<div class="d-flex align-items-top gap-3 option-box optimization-level wpc-checkbox-description-outer-v1 ' . $lockedClass . '">';
        $html .= '<div class="wpc-checkbox-icon"><img src="' . WPS_IC_ASSETS . '/v4/images/' . $icon . '" /></div>';

        $html .= '<div class="wpc-checkbox-description">
                  <h4 class="fs-500 text-dark-300 fw-500 p-inline bp-10">' . $title . '</h4>';

        if ($tooltip) {
            $html .= '<span class="wpc-custom-tooltip" data-tooltip-id="' . $tooltipID . '" data-tooltip-position="left"><i class="tooltip-icon"></i></span>';
        }

        if (!empty($configure) && $configure !== false) {
            $html .= '<p class="fs-200 text-dark-300 fw-400 p-inline p-float-right"><a href="#" class="wps-ic-configure-popup" data-popup="' . $configure . '" data-popup-width="750">Configure</a></p>';
        }

        if (!$tooltip) {
            $html .= '<p class="fs-300 text-secondary-400 fw-400">' . $description . '</p>';
        } else {
            $html .= '<div id="' . $tooltipID . '" class="wpc-ic-popup wpc-ic-popup-position-' . $tooltipPosition . '" style="display: none;">';

            if (!empty($title)) {
                $html .= '<div class="pop-header">
                      ' . $title . '
                    </div>';
            }

            $html .= '<p class="pop-text">
                      ' . $description . '
                    </p>
                  </div>';
        }

        if (!empty($notify)) {
            $html .= '<div class="activate-notification" style="display:none;">
                    <img src="' . WPS_IC_URI . 'assets/v2/assets/images/notification.png" alt="">
                    <p>' . $notify . '</p>
                  </div>';
        }

        $html .= '</div>';

        if ($locked) {
            $html .= '<div class="wpc-box-check">';
            $html .= '<div class="wpc-box-check LockedTooltip" data-pop-text="<i class=\'wpc-sparkle-icon\'></i> Optimize Plan Required" data-tooltip-position="top">';
            $html .= '<a href="#" class="wps-ic-configure-popup wpc-locked-configure-popup" style="pointer-events:none"><i class="wpc-gray-lock"></i>Locked</a>';
            $html .= '</div>';
        } else {

            $html .= '<div class="form-check">';
            $html .= '<a class="btn btn-gradient text-white fw-400 btn-radius btn-flex" href="' . admin_url('options-general.php?page=' . parent::$slug . '&view=bulk') . '">';
            if (!$bulkProcess || empty($bulkProcess)) {
                $html .= '<span><img src="' . WPS_IC_ASSETS . '/v4/images/menu-icons/image-optimization.svg"/></span>';
                $html .= '<span style="display: none;" class="wpc-optimizer-running">';
                $html .= '<img src="' . WPS_IC_ASSETS . '/v4/images/loading-icon-media.svg"/>';
                $html .= '</span>';
            } else {
                $html .= '<span style="display: none;">';
                $html .= '<img src="' . WPS_IC_ASSETS . '/v4/images/menu-icons/image-optimization.svg"/>';
                $html .= '</span>';
                $html .= '<span class="wpc-optimizer-running">';
                $html .= '<img src="' . WPS_IC_ASSETS . '/v4/images/loading-icon-media.svg"/>';
                $html .= '</span>';
            }

            $html .= 'Optimize Media Library';
            $html .= '</a>';

        }
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public static function optimizationLevel($title = 'Demo', $id = 'optimizationLevel', $description = 'Demo', $icon = '', $notify = '', $option = 'default', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left')
    {
        $html = '';

        $active = false;
        $circleActive = '';

        $lockedClass = '';
        if ($locked) {
            $lockedClass = 'wpc-locked-setting';
        }


        if (!is_array($option)) {
            $tooltipID = 'option_tooltip_' . $option;
        } else {
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];
        }

        $html .= '<div class="d-flex align-items-top gap-3 option-box optimization-level wpc-checkbox-description-outer-v1 ' . $lockedClass . '">';
        $html .= '<div class="wpc-checkbox-icon"><img src="' . WPS_IC_ASSETS . '/v4/images/' . $icon . '" /></div>';

        $html .= '<div class="wpc-checkbox-description">
                  <h4 class="fs-500 text-dark-300 fw-500 p-inline bp-10">' . $title . '</h4>';

        if ($tooltip) {
            $html .= '<span class="wpc-custom-tooltip" data-tooltip-id="' . $tooltipID . '" data-tooltip-position="left"><i class="tooltip-icon"></i></span>';
        }

        if (!empty($configure) && $configure !== false) {
            $html .= '<p class="fs-200 text-dark-300 fw-400 p-inline p-float-right"><a href="#" class="wps-ic-configure-popup" data-popup="' . $configure . '" data-popup-width="750">Configure</a></p>';
        }

        if (!$tooltip) {
            $html .= '<p class="fs-300 text-secondary-400 fw-400">' . $description . '</p>';
        } else {
            $html .= '<div id="' . $tooltipID . '" class="wpc-ic-popup wpc-ic-popup-position-' . $tooltipPosition . '" style="display: none;">';

            if (!empty($title)) {
                $html .= '<div class="pop-header">
                      ' . $title . '
                    </div>';
            }

            $html .= '<p class="pop-text">
                      ' . $description . '
                    </p>
                  </div>';
        }

        if (!empty($notify)) {
            $html .= '<div class="activate-notification" style="display:none;">
                    <img src="' . WPS_IC_URI . 'assets/v2/assets/images/notification.png" alt="">
                    <p>' . $notify . '</p>
                  </div>';
        }

        $html .= '</div>';

        if ($locked) {
            $html .= '<div class="wpc-box-check LockedTooltip" data-pop-text="<i class=\'wpc-sparkle-icon\'></i> Optimize Plan Required" data-tooltip-position="top">';
            $html .= '<a href="#" class="wps-ic-configure-popup wpc-locked-configure-popup" style="pointer-events:none"><i class="wpc-gray-lock"></i>Locked</a>';
            $html .= '</div>';
        } else {

            $html .= '<div class="form-check">';

            $qualityLevel = 1;
            switch (self::$options['optimization']) {
                case '1':
                case 'lossless':
                    $qualityLevel = 1;
                    break;
                case '2':
                case 'intelligent':
                    $qualityLevel = 2;
                    break;
                case '3':
                case 'ultra':
                    $qualityLevel = 3;
                    break;
            }


            $html .= '<div class="wpc-slider">
                    <div class="wpc-range-slider">
                        <input id="' . $id . '" name="options[qualityLevel]" type="range" step="1" value="' . $qualityLevel . '" min="1" max="3">
                    </div>
                    <div class="wpc-slider-text d-flex align-items-center justify-content-between">
                        <div class="text-min" data-value="1">Lossless</div>
                        <div class="text-middle" data-value="2">Intelligent</div>
                        <div class="text-max" data-value="3">Ultra</div>
                    </div>
                </div>';

            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    public static function checkboxDescription($title = 'Demo', $description = 'Demo', $icon = '', $notify = '', $option = 'default', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left')
    {
        $html = '';

        $lockedClass = '';
        if ($locked) {
            $lockedClass = 'wpc-locked-setting';
        }

        $active = false;
        $circleActive = '';

        if (!is_array($option)) {
            #$optionName = $option;
            $optionName = 'options[' . $option . ']';
            $tooltipID = 'option_tooltip_' . $option;

            if (isset(self::$options[$option]) && self::$options[$option] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option]) && self::$default[$option] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option]) && self::$safe[$option] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        } else {
            #$optionName = $option[0].','.$option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];

            if (isset(self::$options[$option[0]][$option[1]]) && self::$options[$option[0]][$option[1]] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option[0]][$option[1]]) && self::$default[$option[0]][$option[1]] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option[0]][$option[1]]) && self::$safe[$option[0]][$option[1]] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        }

        $html .= '<div class="d-flex align-items-top gap-3 option-box wpc-checkbox-description-outer-v1 ' . $lockedClass . '">';
        $html .= '<div class="wpc-checkbox-icon"><img src="' . WPS_IC_ASSETS . '/v4/images/' . $icon . '" /></div>';

        $html .= '<div class="wpc-checkbox-description">
                  <h4 class="fs-500 text-dark-300 fw-600 bp-10 p-inline">' . $title . '</h4>';

        if ($tooltip) {
            $html .= '<span class="wpc-custom-tooltip" data-tooltip-id="' . $tooltipID . '" data-tooltip-position="left"><i class="tooltip-icon"></i></span>';
        }

        if (!empty($configure) && $configure !== false) {
            $html .= '<p class="fs-200 text-dark-300 fw-400 p-inline p-float-right"><a href="#" class="wps-ic-configure-popup" data-popup="' . $configure . '" data-popup-width="750">Configure</a></p>';
        }

        if (!$tooltip) {
            $html .= '<p class="fs-400 text-secondary-400 fw-400">' . $description . '</p>';
        } else {
            $html .= '<div id="' . $tooltipID . '" class="wpc-ic-popup wpc-ic-popup-position-' . $tooltipPosition . '" style="display: none;">';

            if (!empty($title)) {
                $html .= '<div class="pop-header">
                      ' . $title . '
                    </div>';
            }

            $html .= '<p class="pop-text">
                      ' . $description . '
                    </p>
                  </div>';
        }

        if (!empty($notify)) {
            $html .= '<div class="activate-notification" style="display:none;">
                    <img src="' . WPS_IC_URI . 'assets/v2/assets/images/notification.png" alt="">
                    <p>' . $notify . '</p>
                  </div>';
        }

        $html .= '</div>';
        /*
        $html .= '<div class="form-check">';

        if ($active) {
          $html .= '<input class="form-check-input checkbox mt-0 wpc-ic-settings-v2-checkbox" data-option-name="' . $optionName . '" type="checkbox" checked="checked" value="1" id="' . $optionName . '" name="' . $optionName . '"  data-recommended="' . $default . '" data-safe="' . $safe . '">';
          $html .= '<label for="' . $optionName . '"><span></span></label>';
        } else {
          $html .= '<input class="form-check-input checkbox mt-0 wpc-ic-settings-v2-checkbox" data-option-name="' . $optionName . '"  type="checkbox" value="1" id="' . $optionName . '" name="' . $optionName . '"  data-recommended="' . $default . '" data-safe="' . $safe . '">';
          $html .= '<label for="' . $optionName . '"><span></span></label>';
        }

        $html .= '</div>';
        */

        $html .= '<div class="wpc-switch-holder">';
        if ($locked) {
            $html .= '<div class="wpc-switch">';
            $html .= '<span class="wpc-switch-slider wpc-switch-disabled wpc-switch-round LockedTooltip" data-pop-text="<i class=\'wpc-sparkle-icon\'></i> Optimize Plan Required" data-tooltip-position="top"></span>';
            $html .= '</div>';
        } else {

            if ($active) {
                $html .= '<label class="wpc-switch">
  <input type="checkbox" class="wpc-ic-settings-v2-checkbox" data-option-name="' . $optionName . '" checked="checked" value="1" id="' . $optionName . '" name="' . $optionName . '"  data-recommended="' . $default . '" data-safe="' . $safe . '"/>
  <span class="wpc-switch-slider wpc-switch-round"></span>
  </label>';
            } else {
                $html .= '<label class="wpc-switch">
  <input type="checkbox" class="wpc-ic-settings-v2-checkbox" data-option-name="' . $optionName . '" value="1" id="' . $optionName . '" name="' . $optionName . '"  data-recommended="' . $default . '" data-safe="' . $safe . '"/>
  <span class="wpc-switch-slider wpc-switch-round"></span>
  </label>';
            }

        }

        $html .= '</div>';
        $html .= '</div>';


        return $html;
    }

    public static function checkboxTabTitle_Horizontal($title = 'Demo', $description = '', $icon = '', $notify = '', $option = 'default', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left')
    {
        $html = '';

        $active = false;

        $html .= '<div class="d-flex align-items-top gap-3 tab-title-checkbox">';
        $html .= '<div class="wpc-checkbox-icon"><img src="' . WPS_IC_ASSETS . '/v4/images/' . $icon . '" /></div>';

        $html .= '<div class="wpc-checkbox-description">
                <div class="wpc-checkbox-description-inner">
                  <h4 class="fs-500 text-dark-300 fw-500 p-inline">' . $title . '</h4>';

        $html .= '<div class="form-check wpc-horizontal">';
        $html .= '<input class="form-check-input checkbox mt-0 wpc-checkbox-select-all" data-for-div-id="' . $option . '" type="checkbox" value="1" id="select-all-' . $option . '" name="select-all-' . $option . '">';
        $html .= '<label class="with-label" for="select-all-' . $option . '"><div>Select All</div><span></span></label>';
        $html .= '</div>';
        $html .= '</div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }


        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * @param array $args
     * $args = [
     * 'title' => Title of box
     * 'description' => Description
     * 'icon' => icon inside v4/images folder
     * 'optionID' => some unique option id
     * 'connected_to' => option name of other checkbox to connect to
     * ]
     * @return string
     */
    public static function checkboxTabTitle_connected(array $args)
    {
        $html = '<div class="d-flex align-items-top gap-3 tab-title-checkbox">';
        $html .= '<div class="wpc-checkbox-icon"><img src="' . WPS_IC_ASSETS . '/v4/images/' . $args['icon'] . '" /></div>';

        $html .= '<div class="wpc-checkbox-description">
                  <h4 class="fs-500 text-dark-300 fw-500 p-inline">' . $args['title'] . '</h4>';

        if (!empty($args['description'])) {
            $html .= '<p>' . $args['description'] . '</p>';
        }

        $html .= '</div>';


        /**
         * Check if connected option is active or not
         */
        $active = false;

        if (is_array($args['connected_to'])) {
            $option = $args['connected_to'];
            $optionNameClean = 'options_' . $option[0] . '_' . $option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';
            if (isset(self::$options[$option[0]][$option[1]]) && self::$options[$option[0]][$option[1]] == '1') {
                // Active
                $active = true;
            } else {
                // Not Active
            }
        } else {
            $option = $args['connected_to'];
            $optionNameClean = 'options_' . $option;
            $optionName = 'options[' . $option . ']';
            if (isset(self::$options[$option]) && self::$options[$option] == '1') {
                // Active
                $active = true;
            } else {
                // Not Active
            }
        }


        if (!empty($args['connected_to'])) {
            if ($active) {
                $html .= '<label class="wpc-switch" for="connected-option-' . $args['optionID'] . '">';
                $html .= '<input type="checkbox" data-connected-option="' . $optionNameClean . '" class="form-check-input checkbox mt-0 wpc-checkbox-connected-option" value="1" checked="checked" id="connected-option-' . $args['optionID'] . '" name="connected-option-' . $args['optionID'] . '"/>';
                $html .= '<span class="wpc-switch-slider wpc-switch-round"></span>';
                $html .= '</label>';
            } else {
                $html .= '<label class="wpc-switch" for="connected-option-' . $args['optionID'] . '">';
                $html .= '<input type="checkbox" data-connected-option="' . $optionNameClean . '" class="form-check-input checkbox mt-0 wpc-checkbox-connected-option" value="1" id="connected-option-' . $args['optionID'] . '" name="connected-option-' . $args['optionID'] . '"/>';
                $html .= '<span class="wpc-switch-slider wpc-switch-round"></span>';
                $html .= '</label>';
            }
        }

        $html .= '</div>';

        return $html;
    }

    public static function checkboxTabTitleCheckbox($title = 'Demo', $description = '', $icon = '', $notify = '', $option = 'default', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left')
    {
        $html = '<div class="d-flex align-items-top gap-3 tab-title-checkbox">';
        if (strpos($icon, '<svg') === 0) {
            $html .= '<div class="wpc-checkbox-icon">' . $icon . '</div>';
        } else {
            $html .= '<div class="wpc-checkbox-icon"><img src="' . WPS_IC_ASSETS . '/v4/images/' . $icon . '" /></div>';
        }

        $html .= '<div class="wpc-checkbox-description">';

        if (!$configure) {
            $html .= '<h4 class="fs-500 text-dark-300 fw-500 p-inline">' . $title . '</h4>';
        } else {
            $html .= '<h4 class="fs-500 text-dark-300 fw-500 p-inline" style="display:flex;align-items:center;">' . $title;
            $html .= '<a href="#" class="wps-ic-configure-popup" data-popup="' . $configure . '" data-popup-width="750" style="margin-left:10px">';
            $html .= '<svg class="wpc-gear-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M200.1-16l112 0 19.7 95.5c14.1 6 27.3 13.7 39.3 22.8l92.6-30.7 56 97-72.9 64.8c.9 7.4 1.3 15 1.3 22.7s-.5 15.3-1.3 22.7l72.9 64.8-56 97-92.6-30.7c-12.1 9.1-25.3 16.7-39.3 22.8l-19.7 95.5-112 0-19.7-95.5c-14.1-6-27.2-13.7-39.3-22.8l-92.6 30.7-56-97 72.9-64.8c-.9-7.4-1.3-15-1.3-22.7s.5-15.3 1.3-22.7l-72.9-64.8 56-97 92.6 30.7c12.1-9.1 25.3-16.7 39.3-22.8L200.1-16zm56 352a80 80 0 1 0 -.1-160 80 80 0 1 0 .1 160z"/></svg>';
            $html .= '</a>';
            $html .= '</h4>';
        }

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }


        $html .= '</div>';

        $active = false;
        $circleActive = '';

        if (!is_array($option)) {
            #$optionName = $option;
            $optionName = 'options[' . $option . ']';
            $tooltipID = 'option_tooltip_' . $option;

            if (isset(self::$options[$option]) && self::$options[$option] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option]) && self::$default[$option] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option]) && self::$safe[$option] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        } else {
            #$optionName = $option[0].','.$option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];

            if (isset(self::$options[$option[0]][$option[1]]) && self::$options[$option[0]][$option[1]] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option[0]][$option[1]]) && self::$default[$option[0]][$option[1]] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option[0]][$option[1]]) && self::$safe[$option[0]][$option[1]] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        }


        if (!empty($option) && !$locked) {
            $html .= '<div class="form-check">';
            $html .= '<input class="form-check-input checkbox mt-0 wpc-checkbox-select-all" data-for-div-id="' . $option . '" type="checkbox" value="1" id="select-all-' . $option . '" name="select-all-' . $option . '">';
            $html .= '<button type="button" class="wpc-select-all-btn" data-for-div-id="' . $option . '" data-checkbox-id="select-all-' . $option . '">Select All</button>';
            $html .= '</div>';
        } else if ($locked) {
            $html .= '<div class="form-check">';
            $html .= '<input class="form-check-input checkbox mt-0 wpc-checkbox-select-all wpc-locked-checkbox" data-for-div-id="' . $option . '" type="checkbox" value="0" id="select-all-' . $option . '" name="select-all-' . $option . '">';
            $html .= '<button type="button" class="wpc-select-all-btn disabled" data-for-div-id="' . $option . '">Select All</button>';
            $html .= '</div>';
        }


        $html .= '</div>';

        return $html;
    }

    public static function checkboxTabTitle($title = 'Demo', $description = '', $icon = '', $notify = '', $option = '', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left', $additionalConfigure = false, $helpBtn = false, $helpBtnText = false, $purgeAction = false)
    {
        $html = '<div class="d-flex align-items-top gap-3 tab-title-checkbox">';


        if (strpos($icon, '<svg') === 0) {
            $html .= '<div class="wpc-checkbox-icon">' . $icon . '</div>';
        } elseif ($icon == 'cf-logo.png') {
            $html .= '<div class="wpc-checkbox-icon"><img src="' . WPS_IC_ASSETS . '/v4/images/' . $icon . '" style="height:auto !important;padding-bottom: 12px !important;margin-right: 15px;" /></div>';
        } else {
            $html .= '<div class="wpc-checkbox-icon"><img src="' . WPS_IC_ASSETS . '/v4/images/' . $icon . '" /></div>';
        }

        $html .= '<div class="wpc-checkbox-description">';

        if (!$configure) {
            $html .= '<h4 class="fs-500 text-dark-300 fw-500 p-inline">' . $title . '</h4>';
        } else {
            $html .= '<h4 class="fs-500 text-dark-300 fw-500 p-inline" style="display:flex;align-items:center;">' . $title;
            $html .= '<a href="#" class="wps-ic-configure-popup" data-popup="' . $configure . '" data-popup-width="750" style="margin-left:10px">';
            $html .= '<svg class="wpc-gear-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M200.1-16l112 0 19.7 95.5c14.1 6 27.3 13.7 39.3 22.8l92.6-30.7 56 97-72.9 64.8c.9 7.4 1.3 15 1.3 22.7s-.5 15.3-1.3 22.7l72.9 64.8-56 97-92.6-30.7c-12.1 9.1-25.3 16.7-39.3 22.8l-19.7 95.5-112 0-19.7-95.5c-14.1-6-27.2-13.7-39.3-22.8l-92.6 30.7-56-97 72.9-64.8c-.9-7.4-1.3-15-1.3-22.7s.5-15.3 1.3-22.7l-72.9-64.8 56-97 92.6 30.7c12.1-9.1 25.3-16.7 39.3-22.8L200.1-16zm56 352a80 80 0 1 0 -.1-160 80 80 0 1 0 .1 160z"/></svg>';
            $html .= '</a>';
            $html .= '</h4>';
        }

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }

        $html .= '</div>';

        $active = false;
        $circleActive = '';

        if (!is_array($option)) {
            #$optionName = $option;
            $optionName = 'options[' . $option . ']';
            $tooltipID = 'option_tooltip_' . $option;

            if (isset(self::$options[$option]) && self::$options[$option] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option]) && self::$default[$option] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option]) && self::$safe[$option] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        } else {
            #$optionName = $option[0].','.$option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];

            if (isset(self::$options[$option[0]][$option[1]]) && self::$options[$option[0]][$option[1]] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option[0]][$option[1]]) && self::$default[$option[0]][$option[1]] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option[0]][$option[1]]) && self::$safe[$option[0]][$option[1]] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        }

        if (!empty($option)) {
            //    $html .= '<div class="form-check">';
            //      $html .= '<input class="form-check-input checkbox mt-0 wpc-checkbox-select-all" data-for-div-id="' . $option . '" type="checkbox" value="1" id="select-all-' . $option . '" name="select-all-' . $option . '">';
            //      $html .= '<label class="with-label" for="select-all-' . $option . '"><div>Select All</div><span></span></label>';
            //      $html .= '</div>';

            $html .= '<label class="wpc-switch" for="select-all-' . $option . '">';
            $html .= '<input type="checkbox" data-for-div-id="' . $option . '" class="form-check-input checkbox mt-0 wpc-checkbox-select-all" value="1" id="select-all-' . $option . '" name="select-all-' . $optionName . '"/>';
            $html .= '<span class="wpc-switch-slider wpc-switch-round"></span>';
            $html .= '</label>';
        }

        if (!empty($additionalConfigure)) {
            $html .= '<div class="form-check">';
            $html .= '<a href="#" class="wps-ic-configure-popup" data-popup="' . $additionalConfigure . '" data-popup-width="750">Configure</a>';
            $html .= '</div>';
        }

        if (!empty($purgeAction)) {
            $html .= '<div class="form-check">';
            $html .= '<a href="#" class="wpc-purge-action ' . $purgeAction . '">Purge Cache</a>';
            $html .= '</div>';
        }


        // Hide for Whitelabel users
        if (!class_exists('whtlbl_whitelabel_plugin')) {
            if (!empty($helpBtn)) {
                $html .= '<div class="form-check" style="max-width:120px;">';
                $html .= '<a href="' . $helpBtn . '" target="_blank" class="wps-ic-help-btn">' . $helpBtnText . '</a>';
                $html .= '</div>';
            }
        }


        $html .= '</div>';

        return $html;
    }

    public static function textArea_v4($title = 'Demo', $description = 'Demo', $labelTitle = '', $labelValue = '', $notify = '', $option = 'default', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left', $beta = false)
    {
        $html = '';

        $optionValue = '';
        $active = false;
        $circleActive = '';

        if (!is_array($option)) {
            #$optionName = $option;
            $optionName_cleaned = 'options_' . $option;
            $optionName = 'options[' . $option . ']';
            $tooltipID = 'option_tooltip_' . $option;

            if (!empty(self::$options[$option])) {
                $optionValue = self::$options[$option];
            }

        } else {
            $optionName_cleaned = 'options_' . $option[0] . '_' . $option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];


            if (!empty(self::$options[$option[0]][$option[1]])) {
                $optionValue = self::$options[$option[0]][$option[1]];
            }
        }

        $cssClass = '';
        if (empty($description)) {
            $cssClass = 'no-description';
        }

        $html = '<div class="wpc-box-for-textarea ' . $cssClass . '">
                                       <div class="wpc-box-content">
                                       <div class="wpc-checkbox-title-holder">
                                           <div class="circle-check active"></div>
                                           ';

        if (!empty($configure) && $configure !== false) {
            $hasOverrides = self::hasPopupOverrides($configure);
            $overrideClass = $hasOverrides ? ' wpc-has-overrides' : '';
            $html .= '<h4>' . $title;
            if ($beta) {
                $html .= '<span class="wpc-beta-badge">' . (is_string($beta) ? $beta : 'BETA') . '</span>';
            }
            $html .= '<a href="#" class="wps-ic-configure-popup' . $overrideClass . '" data-popup="' . $configure . '" data-popup-width="750">';
            $html .= '<svg class="wpc-gear-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M200.1-16l112 0 19.7 95.5c14.1 6 27.3 13.7 39.3 22.8l92.6-30.7 56 97-72.9 64.8c.9 7.4 1.3 15 1.3 22.7s-.5 15.3-1.3 22.7l72.9 64.8-56 97-92.6-30.7c-12.1 9.1-25.3 16.7-39.3 22.8l-19.7 95.5-112 0-19.7-95.5c-14.1-6-27.2-13.7-39.3-22.8l-92.6 30.7-56-97 72.9-64.8c-.9-7.4-1.3-15-1.3-22.7s.5-15.3 1.3-22.7l-72.9-64.8 56-97 92.6 30.7c12.1-9.1 25.3-16.7 39.3-22.8L200.1-16zm56 352a80 80 0 1 0 -.1-160 80 80 0 1 0 .1 160z"/></svg>';
            $html .= '</a>';
            $html .= '</h4>';
        } else {
            $html .= '<h4>' . $title;
            if ($beta) {
                $html .= '<span class="wpc-beta-badge">' . (is_string($beta) ? $beta : 'BETA') . '</span>';
            }
            $html .= '</h4>';
        }

        $html .= '</div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }

        $html .= '</div>';

        $html .= '<div class="wpc-box-textarea">';


        $html .= '<div class="wpc-input-holder">';
        $html .= '<textarea name="' . $optionName . '">' . $optionValue . '</textarea>';
        $html .= '</div>';

        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    public static function inputDescription_v4($title = 'Demo', $description = 'Demo', $labelTitle = '', $labelValue = '', $notify = '', $option = 'default', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left', $beta = false)
    {
        $html = '';

        $optionValue = '';
        $active = false;
        $circleActive = '';

        if (!is_array($option)) {
            #$optionName = $option;
            $optionName_cleaned = 'options_' . $option;
            $optionName = 'options[' . $option . ']';
            $tooltipID = 'option_tooltip_' . $option;

            if (!empty(self::$options[$option])) {
                $optionValue = self::$options[$option];
            }

        } else {
            $optionName_cleaned = 'options_' . $option[0] . '_' . $option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];


            if (!empty(self::$options[$option[0]][$option[1]])) {
                $optionValue = self::$options[$option[0]][$option[1]];
            }
        }

        $cssClass = '';
        if (empty($description)) {
            $cssClass = 'no-description';
        }

        // Is it locked?
        $lockedCss = '';
        if ($locked) {
            $circleActive = '';
            $configure = '';
            $lockedCss = 'wpc-locked';
        }

        $html = '<div class="wpc-box-for-input ' . $cssClass . ' ' . $lockedCss . '">
                                       <div class="wpc-box-content">
                                       <div class="wpc-checkbox-title-holder">';

        if (!$locked) {
            $html .= '<div class="circle-check active"></div>';
        } else {
            $html .= '<div class="circle-check"></div>';
        }

        if (!empty($configure) && $configure !== false) {
            $hasOverrides = self::hasPopupOverrides($configure);
            $overrideClass = $hasOverrides ? ' wpc-has-overrides' : '';
            $html .= '<h4>' . $title;
            if ($beta) {
                $html .= '<span class="wpc-beta-badge">' . (is_string($beta) ? $beta : 'BETA') . '</span>';
            }
            $html .= '<a href="#" class="wps-ic-configure-popup' . $overrideClass . '" data-popup="' . $configure . '" data-popup-width="750">';
            $html .= '<svg class="wpc-gear-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M200.1-16l112 0 19.7 95.5c14.1 6 27.3 13.7 39.3 22.8l92.6-30.7 56 97-72.9 64.8c.9 7.4 1.3 15 1.3 22.7s-.5 15.3-1.3 22.7l72.9 64.8-56 97-92.6-30.7c-12.1 9.1-25.3 16.7-39.3 22.8l-19.7 95.5-112 0-19.7-95.5c-14.1-6-27.2-13.7-39.3-22.8l-92.6 30.7-56-97 72.9-64.8c-.9-7.4-1.3-15-1.3-22.7s.5-15.3 1.3-22.7l-72.9-64.8 56-97 92.6 30.7c12.1-9.1 25.3-16.7 39.3-22.8L200.1-16zm56 352a80 80 0 1 0 -.1-160 80 80 0 1 0 .1 160z"/></svg>';
            $html .= '</a>';
            $html .= '</h4>';
        } else {
            $html .= '<h4>' . $title;
            if ($beta) {
                $html .= '<span class="wpc-beta-badge">' . (is_string($beta) ? $beta : 'BETA') . '</span>';
            }
            $html .= '</h4>';
        }

        $html .= '</div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }

        $html .= '</div>';

        if (!$locked) {
            $html .= '<div class="wpc-box-check">';
            $html .= '<div class="wpc-input-holder">';
            $html .= '<span>' . $labelTitle . '</span>';
            $html .= '<input type="text" name="' . $optionName . '" value="' . $optionValue . '" placeholder="' . $value . '" />';
            $html .= '<span>' . $labelValue . '</span>';
            $html .= '</div>';
            $html .= '</div>';
        } else {
            $html .= '<div class="wpc-box-check LockedTooltip" data-pop-text="<i class=\'wpc-sparkle-icon\'></i> Optimize Plan Required" data-tooltip-position="top">';
            $html .= '<a href="#" class="wps-ic-configure-popup wpc-locked-configure-popup" style="pointer-events:none"><i class="wpc-gray-lock"></i>Locked</a>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }


    public static function buttonAction($title, $description, $actionTitle = '', $icon = '', $actionLink = '', $actionCall = '', $tooltip = false, $tooltipPosition = 'left', $beta = false)
    {
        $html = '<div class="wpc-box-for-checkbox">
                                       <div class="wpc-box-content">
                                       <div class="wpc-checkbox-title-holder">
                                           <div class="circle-check active"></div>
                                           ';


        $html .= '<h4>' . $title;
        if ($beta) {
            $html .= '<span class="wpc-beta-badge">BETA</span>';
        }
        $html .= '</h4>';
        $html .= '</div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }


        $html .= '<div class="wpc-box-button">';
        if (empty($actionLink)) {
            $html .= '<a href="#" class="wps-ic-button-action" data-action-call="' . $actionCall . '">';
        } else {
            $html .= '<a href="'.$actionLink.'" class="wps-ic-button-action" data-action-call="' . $actionCall . '">';
        }

        $html .= $actionTitle;
        $html .= '</a>';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }


    public static function buttonDescription_v4($title = 'Demo', $description = 'Demo', $icon = '', $notify = '', $option = 'default', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left', $beta = false)
    {
        $html = '';

        $active = false;
        $circleActive = '';

        if (!is_array($option)) {
            #$optionName = $option;
            $optionName_cleaned = 'options_' . $option;
            $optionName = 'options[' . $option . ']';
            $tooltipID = 'option_tooltip_' . $option;

            if (isset(self::$options[$option]) && self::$options[$option] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option]) && self::$default[$option] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option]) && self::$safe[$option] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        } else {
            $optionName_cleaned = 'options_' . $option[0] . '_' . $option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];

            $value = get_option($option[0]);
            if (!empty($value[$option[1]][0])) {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option[0]][$option[1]]) && self::$default[$option[0]][$option[1]] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option[0]][$option[1]]) && self::$safe[$option[0]][$option[1]] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        }

        $cssClass = '';
        if (empty($description)) {
            $cssClass = 'no-description';
        }

        // Is it locked?
        $lockedCss = '';
        if ($locked) {
            $circleActive = '';
            $configure = '';
            $lockedCss = 'wpc-locked';
        }

        $html = '<div class="wpc-box-for-checkbox ' . $cssClass . ' ' . $lockedCss . '">
                                       <div class="wpc-box-content">
                                       <div class="wpc-checkbox-title-holder">
                                           <div class="circle-check ' . $circleActive . '"></div>
                                           ';


        $html .= '<h4>' . $title;
        if ($beta) {
            $html .= '<span class="wpc-beta-badge">BETA</span>';
        }
        $html .= '</h4>';

        $html .= '</div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }

        $html .= '</div>';

        $popupData = '';
        $popup = false;
        if (!empty($notify)) {
            $popupData = $notify;
            $popup = 'wpc-show-popup wpc-popup-' . $notify;
        }

        $contactSupport = 'data-custom-buttons="false"';
        if ($notify == 'delay-js' || $notify == 'combine-css' || $notify == 'combine-js') {
            $contactSupport = 'data-custom-buttons="true"';
        }

        if (!$locked) {
            $html .= '<div class="wpc-box-button">';
            $html .= '<a href="#" class="wps-ic-configure-popup" data-popup="' . $configure . '" data-popup-width="750">';
            $html .= 'Configure';
            $html .= '</a>';
            $html .= '</div>';
        } else {
            $html .= '<div class="wpc-box-check LockedTooltip" data-pop-text="<i class=\'wpc-sparkle-icon\'></i> Optimize Plan Required" data-tooltip-position="top">';
            $html .= '<a href="#" class="wps-ic-configure-popup wpc-locked-configure-popup" style="pointer-events:none"><i class="wpc-gray-lock"></i>Locked</a>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    public static function checkboxDescription_v4($title = 'Demo', $description = 'Demo', $icon = '', $notify = '',
                                                  $option = 'default', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left', $beta = false)
    {
        $html = '';

        $active = false;
        $circleActive = '';

        if (!is_array($option)) {
            #$optionName = $option;
            $optionName_cleaned = 'options_' . $option;
            $optionName = 'options[' . $option . ']';
            $tooltipID = 'option_tooltip_' . $option;

            if (isset(self::$options[$option]) && self::$options[$option] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option]) && self::$default[$option] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option]) && self::$safe[$option] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        } else {
            $optionName_cleaned = 'options_' . $option[0] . '_' . $option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];

            if (isset(self::$options[$option[0]][$option[1]]) && self::$options[$option[0]][$option[1]] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option[0]][$option[1]]) && self::$default[$option[0]][$option[1]] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option[0]][$option[1]]) && self::$safe[$option[0]][$option[1]] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        }


        // Is it locked?
        $lockedCss = '';
        if ($locked) {
            $circleActive = '';
            $configure = '';
            $lockedCss = 'wpc-locked';
        }


        $cssClass = '';
        if (empty($description)) {
            $cssClass = 'no-description';
        }

        $html = '<div class="wpc-box-for-checkbox ' . $cssClass . ' ' . $lockedCss . '">
                                       <div class="wpc-box-content">
                                       <div class="wpc-checkbox-title-holder">
                                           <div class="circle-check ' . $circleActive . '"></div>
                                           ';

        if (!empty($configure) && $configure !== false) {
            $hasOverrides = self::hasPopupOverrides($configure);
            $overrideClass = $hasOverrides ? ' wpc-has-overrides' : '';
            $html .= '<h4>' . $title;
            if ($beta) {
                $html .= '<span class="wpc-beta-badge">' . (is_string($beta) ? $beta : 'BETA') . '</span>';
            }
            $html .= '<a href="#" class="wps-ic-configure-popup' . $overrideClass . '" data-popup="' . $configure . '" data-popup-width="750">';
            $html .= '<svg class="wpc-gear-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M200.1-16l112 0 19.7 95.5c14.1 6 27.3 13.7 39.3 22.8l92.6-30.7 56 97-72.9 64.8c.9 7.4 1.3 15 1.3 22.7s-.5 15.3-1.3 22.7l72.9 64.8-56 97-92.6-30.7c-12.1 9.1-25.3 16.7-39.3 22.8l-19.7 95.5-112 0-19.7-95.5c-14.1-6-27.2-13.7-39.3-22.8l-92.6 30.7-56-97 72.9-64.8c-.9-7.4-1.3-15-1.3-22.7s.5-15.3 1.3-22.7l-72.9-64.8 56-97 92.6 30.7c12.1-9.1 25.3-16.7 39.3-22.8L200.1-16zm56 352a80 80 0 1 0 -.1-160 80 80 0 1 0 .1 160z"/></svg>';
            $html .= '</a>';
            $html .= '</h4>';
        } else {
            $html .= '<h4>' . $title;
            if ($beta) {
                $html .= '<span class="wpc-beta-badge">' . (is_string($beta) ? $beta : 'BETA') . '</span>';
            }
            $html .= '</h4>';
        }

        $html .= '</div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }

        $html .= '</div>';

        $html .= '<div class="wpc-box-check">';


        $popupData = '';
        $popup = false;
        if (!empty($notify)) {
            $popupData = $notify;
            $popup = 'wpc-show-popup wpc-popup-' . $notify;
        }

        $contactSupport = 'data-custom-buttons="false"';
        if ($notify == 'delay-js' || $notify == 'combine-css' || $notify == 'combine-js') {
            $contactSupport = 'data-custom-buttons="true"';
        }

        if ($locked) {
            $html .= '<label class="wpc-switch">
  <input type="checkbox" class="wpc-ic-settings-v4-checkbox" value="0" id="' . $optionName_cleaned . '" name="' . $optionName . '" disabled />
  <span class="wpc-switch-slider wpc-switch-disabled wpc-switch-round"></span>
  </label>';

        } else {

            if ($active) {
                $html .= '<label class="wpc-switch">
  <input type="checkbox" class="wpc-ic-settings-v4-checkbox ' . $popup . '" data-popup="' . $popupData . '" ' . $contactSupport . ' checked="checked" value="1" id="' . $optionName_cleaned . '" name="' . $optionName . '"  data-recommended="' . $default . '" data-safe="' . $safe . '" data-connected-slave-option="' . $optionName_cleaned . '"/>
  <span class="wpc-switch-slider wpc-switch-round"></span>
  </label>';
            } else {
                $html .= '<label class="wpc-switch">
  <input type="checkbox" class="wpc-ic-settings-v4-checkbox ' . $popup . '" data-popup="' . $popupData . '" ' . $contactSupport . ' value="1" id="' . $optionName_cleaned . '" name="' . $optionName . '"  data-recommended="' . $default . '" data-safe="' . $safe . '" data-connected-slave-option="' . $optionName_cleaned . '"/>
  <span class="wpc-switch-slider wpc-switch-round"></span>
  </label>';
            }
        }

        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    public static function getSetting($name)
    {
        return self::$options[$name];
    }

    public static function iconCheckBox($title = 'Demo', $icon = '', $option = 'default', $locked = false)
    {
        $html = '';

        $active = false;
        $circleActive = '';
        if (!is_array($option)) {
            $optionName = 'options[' . $option . ']';

            if (!empty(self::$options[$option]) && self::$options[$option] == '1') {
                $active = true;
                $circleActive = 'active';
            }


            if (!empty(self::$default[$option]) && self::$default[$option] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (!empty(self::$safe[$option]) && self::$safe[$option] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        } else {
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';

            if (!empty(self::$options[$option[0]][$option[1]]) && self::$options[$option[0]][$option[1]] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (!empty(self::$default[$option[0]][$option[1]]) && self::$default[$option[0]][$option[1]] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (!empty(self::$safe[$option[0]][$option[1]]) && self::$safe[$option[0]][$option[1]] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        }

        $lockedCss = '';
        if ($locked) {
            $lockedCss = 'wpc-locked-checkbox-container';
            $circleActive = '';
        }

        // Inline SVG for dynamic coloring via CSS currentColor
        $iconPath = WPS_IC_DIR . 'assets/v4/images/' . $icon;
        $svgContent = '';
        if (file_exists($iconPath) && pathinfo($iconPath, PATHINFO_EXTENSION) === 'svg') {
            $svgContent = file_get_contents($iconPath);
            // Strip XML declaration and comments, add class for CSS targeting
            $svgContent = preg_replace('/<!--.*?-->/s', '', $svgContent);
            $svgContent = preg_replace('/<\?xml[^>]*\?>/', '', $svgContent);
            $svgContent = str_replace('<svg ', '<svg class="wpc-iconcheckbox-svg" ', $svgContent);
        }

        $html .= '<div class="wpc-iconcheckbox ' . $circleActive . ' ' . $lockedCss . '">
    <div class="wpc-iconcheckbox-icon">';

        if ($svgContent) {
            $html .= $svgContent;
        } else {
            $html .= '<img src="' . WPS_IC_ASSETS . '/v4/images/' . $icon . '"/>';
        }

        $html .= '
    </div>
    <div class="wpc-iconcheckbox-title">
        ' . $title . '
    </div>';

        if ($locked) {
            $html .= '<div class="wpc-iconcheckbox-toggle LockedTooltip" data-pop-text="<i class=\'wpc-sparkle-icon\'></i> Optimization Credits Required">
								<input class="form-check-input checkbox mt-0 wpc-ic-settings-v4-iconcheckbox wpc-locked-checkbox" data-option-name="' . $optionName . '" type="checkbox" value="0" id="' . $optionName . '" name="' . $optionName . '"
                         data-recommended="' . $default . '" data-safe="' . $safe . '">';
            $html .= '<label for="' . $optionName . '"><span></span></label>';
        } else {
            $html .= '<div class="wpc-iconcheckbox-toggle">';
            if ($active) {
                $html .= '<input class="form-check-input checkbox mt-0 wpc-ic-settings-v4-iconcheckbox" data-option-name="' . $optionName . '" type="checkbox" checked="checked" value="1" id="' . $optionName . '" name="' . $optionName . '"
                         data-recommended="' . $default . '" data-safe="' . $safe . '">';
                $html .= '<label for="' . $optionName . '"><span></span></label>';
            } else {
                $html .= '<input class="form-check-input checkbox mt-0 wpc-ic-settings-v4-iconcheckbox" data-option-name="' . $optionName . '" type="checkbox" value="1" id="' . $optionName . '" name="' . $optionName . '"
                         data-recommended="' . $default . '" data-safe="' . $safe . '">';
                $html .= '<label for="' . $optionName . '"><span></span></label>';
            }

        }

        $html .= '
    </div>
</div>';

        return $html;
    }


    public static function simpleCheckbox($title = 'Demo', $description = 'Demo', $icon = '', $notify = '',
                                          $option = 'default', $locked = false, $value = '1', $configure = false, $tooltip = false, $tooltipPosition = 'left', $beta = false)
    {
        $html = '';
        $active = false;
        $circleActive = '';

        if (!is_array($option)) {
            #$optionName = $option;
            $optionName_cleaned = 'options_' . $option;
            $optionName = 'options[' . $option . ']';
            $tooltipID = 'option_tooltip_' . $option;

            if (isset(self::$options[$option]) && self::$options[$option] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option]) && self::$default[$option] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option]) && self::$safe[$option] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        } else {
            $optionName_cleaned = 'options_' . $option[0] . '_' . $option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];

            if (isset(self::$options[$option[0]][$option[1]]) && self::$options[$option[0]][$option[1]] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option[0]][$option[1]]) && self::$default[$option[0]][$option[1]] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option[0]][$option[1]]) && self::$safe[$option[0]][$option[1]] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        }


        // Is it locked?
        $lockedCss = '';
        if ($locked) {
            $circleActive = '';
            $configure = '';
            $lockedCss = 'wpc-locked';
        }


        $cssClass = '';
        if (empty($description)) {
            $cssClass = 'no-description';
        }

        $html = '<div class="wpc-box-setting-option wpc-box-for-checkbox-lite ' . $cssClass . ' ' . $lockedCss . '">
                                       <div class="wpc-box-content">
                                       <div class="wpc-checkbox-title-holder">';

        if (!empty($configure) && $configure !== false) {
            $hasOverrides = self::hasPopupOverrides($configure);
            $overrideClass = $hasOverrides ? ' wpc-has-overrides' : '';
            $html .= '<span>' . $title;
            if ($beta) {
                $html .= '<span class="wpc-beta-badge">' . (is_string($beta) ? $beta : 'BETA') . '</span>';
            }
            $html .= '<a href="#" class="wps-ic-configure-popup' . $overrideClass . '" data-popup="' . $configure . '" data-popup-width="750">';
            $html .= '<svg class="wpc-gear-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M200.1-16l112 0 19.7 95.5c14.1 6 27.3 13.7 39.3 22.8l92.6-30.7 56 97-72.9 64.8c.9 7.4 1.3 15 1.3 22.7s-.5 15.3-1.3 22.7l72.9 64.8-56 97-92.6-30.7c-12.1 9.1-25.3 16.7-39.3 22.8l-19.7 95.5-112 0-19.7-95.5c-14.1-6-27.2-13.7-39.3-22.8l-92.6 30.7-56-97 72.9-64.8c-.9-7.4-1.3-15-1.3-22.7s.5-15.3 1.3-22.7l-72.9-64.8 56-97 92.6 30.7c12.1-9.1 25.3-16.7 39.3-22.8L200.1-16zm56 352a80 80 0 1 0 -.1-160 80 80 0 1 0 .1 160z"/></svg>';
            $html .= '</a>';
            $html .= '</span>';
        } else {
            $html .= '<span>' . $title;
            if ($beta) {
                $html .= '<span class="wpc-beta-badge">' . (is_string($beta) ? $beta : 'BETA') . '</span>';
            }
            $html .= '</span>';
        }

        $html .= '</div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }

        $html .= '</div>';

        $html .= '<div class="wpc-box-check">';


        $popupData = '';
        $popup = false;
        if (!empty($notify)) {
            $popupData = $notify;
            $popup = 'wpc-show-popup wpc-popup-' . $notify;
        }

        $contactSupport = 'data-custom-buttons="false"';
        if ($notify == 'delay-js' || $notify == 'combine-css' || $notify == 'combine-js') {
            $contactSupport = 'data-custom-buttons="true"';
        }

        if ($locked) {
            $html .= '<label class="wpc-switch">
  <input type="checkbox" class="wpc-ic-settings-v4-checkbox" value="0" id="' . $optionName_cleaned . '" name="' . $optionName . '" disabled />
  <span class="wpc-switch-slider wpc-switch-disabled wpc-switch-round"></span>
  </label>';

        } else {

            if ($active) {
                $html .= '<label class="wpc-switch">
  <input type="checkbox" class="wpc-ic-settings-v4-checkbox ' . $popup . '" data-popup="' . $popupData . '" ' . $contactSupport . ' checked="checked" value="1" id="' . $optionName_cleaned . '" name="' . $optionName . '"  data-recommended="' . $default . '" data-safe="' . $safe . '" data-connected-slave-option="' . $optionName_cleaned . '"/>
  <span class="wpc-switch-slider wpc-switch-round"></span>
  </label>';
            } else {
                $html .= '<label class="wpc-switch">
  <input type="checkbox" class="wpc-ic-settings-v4-checkbox ' . $popup . '" data-popup="' . $popupData . '" ' . $contactSupport . ' value="1" id="' . $optionName_cleaned . '" name="' . $optionName . '"  data-recommended="' . $default . '" data-safe="' . $safe . '" data-connected-slave-option="' . $optionName_cleaned . '"/>
  <span class="wpc-switch-slider wpc-switch-round"></span>
  </label>';
            }
        }

        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }


    public static function checkBoxOption($title = 'Demo', $option = 'default', $locked = false, $value = '1', $align = 'right', $description = '', $tooltip = false, $tooltipPosition = 'top')
    {
        $html = '';

        $active = false;
        $circleActive = '';

        if (!is_array($option)) {
            #$optionName = $option;
            $optionName = 'options[' . $option . ']';
            $tooltipID = 'option_tooltip_' . $option;
            if (isset(self::$options[$option]) && self::$options[$option] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option]) && self::$default[$option] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option]) && self::$safe[$option] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        } else {
            #$optionName = $option[0].','.$option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';
            $tooltipID = 'option_tooltip_' . $option[0] . '_' . $option[1];

            if (isset(self::$options[$option[0]][$option[1]]) && self::$options[$option[0]][$option[1]] == '1') {
                $active = true;
                $circleActive = 'active';
            }

            if (isset(self::$default[$option[0]][$option[1]]) && self::$default[$option[0]][$option[1]] == '1') {
                $default = 1;
            } else {
                $default = 0;
            }

            if (isset(self::$safe[$option[0]][$option[1]]) && self::$safe[$option[0]][$option[1]] == '1') {
                $safe = 1;
            } else {
                $safe = 0;
            }
        }

        if ($align == 'right') {
            $html .= '
<div class="accordion-item option-item option-box">
    <h2 class="accordion-header d-flex align-items-center justify-content-between gap-2 fs-400" id="flush-headingOne">
        <div class="d-flex align-items-center gap-2">
            <div class="circle-check ' . $circleActive . '"></div>
            <p class="fs-300 text-dark-300">' . $title . '</p>';

            if ($tooltip) {
                $html .= '<span class="wpc-custom-tooltip" data-tooltip-id="' . $tooltipID . '"
                            data-tooltip-position="' . $tooltipPosition . '"><i class="tooltip-icon"></i></span>';
            }

            $html .= '
        </div>
        <div class="form-check">';

            if ($locked) {
                $html .= '<input class="form-check-input checkbox mt-0 locked-checkbox" type="checkbox" value="1"
                             id="flexCheckDefault">';
            } else {
                if ($active) {
                    $html .= '<input class="form-check-input checkbox mt-0 wpc-ic-settings-v2-checkbox"
                             data-option-name="' . $optionName . '" type="checkbox" checked="checked" value="1"
                             id="' . $optionName . '" name="' . $optionName . '" data-recommended="' . $default . '"
                             data-safe="' . $safe . '">';
                    $html .= '<label for="' . $optionName . '"><span></span></label>';
                } else {
                    $html .= '<input class="form-check-input checkbox mt-0 wpc-ic-settings-v2-checkbox"
                             data-option-name="' . $optionName . '" type="checkbox" value="1" id="' . $optionName . '"
                             name="' . $optionName . '" data-recommended="' . $default . '" data-safe="' . $safe . '">';
                    $html .= '<label for="' . $optionName . '"><span></span></label>';
                }
            }

            if ($tooltip) {

                $html .= '
            <div id="' . $tooltipID . '" class="wpc-ic-popup wpc-ic-popup-position-' . $tooltipPosition . '"
                 style="display: none;">';

                if (!empty($title)) {
                    $html .= '
                <div class="pop-header">
                    ' . $title . '
                </div>
                ';
                }

                $html .= '<p class="pop-text">
                    ' . $description . '
                </p>
            </div>
            ';
            }

            $html .= '
        </div>
    </h2>
</div>';
        } else {
            $html .= '
<div class="accordion-item option-item">
    <h2 class="accordion-header d-flex align-items-center justify-content-between gap-2 fs-400" id="flush-headingOne">
        <div class="d-flex align-items-center gap-2">';

            if ($locked) {
                $html .= '<input class="form-check-input checkbox mt-0 locked-checkbox" type="checkbox" value="1"
                             id="flexCheckDefault">';
            } else {
                if ($active) {
                    $html .= '<input class="form-check-input checkbox mt-0 wpc-ic-settings-v2-checkbox"
                             data-option-name="' . $optionName . '" type="checkbox" checked="checked" value="1"
                             id="' . $optionName . '" name="' . $optionName . '" data-recommended="' . $default . '"
                             data-safe="' . $safe . '">';
                    $html .= '<label for="' . $optionName . '"><span></span></label>';
                } else {
                    $html .= '<input class="form-check-input checkbox mt-0 wpc-ic-settings-v2-checkbox"
                             data-option-name="' . $optionName . '" type="checkbox" value="1" id="' . $optionName . '"
                             name="' . $optionName . '" data-recommended="' . $default . '" data-safe="' . $safe . '">';
                    $html .= '<label for="' . $optionName . '"><span></span></label>';
                }
            }

            $html .= '<p class="text-dark-300">' . $title . '</p>';
            $html .= '
        </div>
        <div class="form-check">';

            $html .= '
        </div>
    </h2>
</div>';
        }

        return $html;
    }

    public static function presetModes()
    {
        $html = '<div class="wpc-preset-modes-container">
                                <div class="wpc-preset-modes-container-inner">
                                <div class="wpc-preset-modes-icon">
                                  <img src="' . WPS_IC_URI . 'assets/v4/images/preset-modes.svg" style="width:80px;margin-left:20px;margin-right:40px;"/>
                                </div>';


        $html .= '<div class="wpc-preset-mode-title">
                    <div>
                       <h4 class="fs-500 text-dark-300 fw-500 p-inline mb-10" style="margin-top:0;margin-bottom:10px;">'.esc_html__('Preset Optimization Modes', WPS_IC_TEXTDOMAIN).'</h4>
                    </div>
                    <div class="setting-value setting-configure">
                         <p style="margin:0;">'.esc_html__('One-click configure recommended image optimization settings and performance tweaks based on your preferences and website compatibility.', WPS_IC_TEXTDOMAIN).'</p>
                    </div>
                </div>';


        $preset_config = get_option(WPS_IC_PRESET);
        $preset = ['recommended' => 'Recommended Mode',
            'safe' => esc_html__('Safe Mode', WPS_IC_TEXTDOMAIN),
            'aggressive' => esc_html__('Aggressive Mode', WPS_IC_TEXTDOMAIN),
            'custom' => esc_html__('Custom', WPS_IC_TEXTDOMAIN)];

        if (empty($preset_config)) {
            update_option('wps_ic_preset_setting', 'aggressive');
            $preset_config = 'aggressive';
        }

        $html .= '<input type="hidden" name="wpc_preset_mode" value="' . $preset_config . '" />
<div class="wpc-dropdown wpc-dropdown-trigger-popup">
  <button class="btn btn-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
    ' . $preset[$preset_config] . '
  </button>
  <div class="wpc-dropdown-menu">';

        foreach ($preset as $k => $v) {
            $s = '';
            if ($k == $preset_config) {
                $s = 'active';
            }
            $html .= '<a class="dropdown-item ' . $s . '" data-preset-title="' . $v . '" data-value="' . $k . '">' . $v . '</a>';
        }

        $html .= '</div>
</div>';


        $html .= '</div>
            </div>';

        return $html;
    }

    public static function cname()
    {
        $cnameEnabled = self::isFeatureEnabled('cname');
        $cnameLocked = false;
        $lockedClass = '';
        if (!$cnameEnabled) {
            $cnameLocked = true;
            $lockedClass = 'wpc-locked-setting';
        }

        $zone_name = get_option('ic_custom_cname');

        $popup = 'custom-cdn';
        $cfSettings = get_option(WPS_IC_CF);
        $cfCdnActive = !empty($cfSettings['settings']['cdn']) && $cfSettings['settings']['cdn'] == '1';

        // Show CF CNAME when CF is connected AND CF CDN is active
        $isCfActive = !empty($cfSettings) && $cfCdnActive;
        if ($isCfActive) {
            $popup = 'cf-cdn';
            $zone_name = get_option(WPS_IC_CF_CNAME, '');
        }

        $html = '<div class="wpc-tab-content-box wpc-tab-content-cname ' . $lockedClass . '" 
    style="display:flex;align-items:center;justify-content: space-between;">
                                <div style="display:flex;align-items:center;">
                                    <div class="wpc-cname-icon"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512"><path fill="currentColor" d="M296 288c57.4 0 104-46.6 104-104S353.4 80 296 80L152 80c-57.4 0-104 46.6-104 104 0 34 16.3 64.1 41.4 83.1-5.2 16.2-8.3 33.3-9.2 50.9-47.8-25.6-80.2-76-80.2-134 0-83.9 68.1-152 152-152l144 0c83.9 0 152 68.1 152 152S379.9 336 296 336l-72 0 0-48 72 0zm232 40c0-31-13.6-58.9-35.2-78 5.7-16.3 9.4-33.7 10.6-51.6 43.5 26.7 72.5 74.8 72.5 129.6 0 83.9-68.1 152-152 152l-144 0c-83.9 0-152-68.1-152-152s68.1-152 152-152l72 0 0 48-72 0c-57.4 0-104 46.6-104 104s46.6 104 104 104l144 0c57.4 0 104-46.6 104-104z"/></svg></div>';


        if (!empty($zone_name)) {
            $html .= '<div style="flex-direction: column;display: flex;justify-content: center;">
                    <div>
                       <h4 class="fs-500 text-dark-300 fw-500 p-inline mb-10" style="margin-top:0;margin-bottom:10px;">' . esc_html__('Custom CDN Domain', WPS_IC_TEXTDOMAIN) . '</h4>
                    </div>
                    <div class="setting-value setting-configured cname-configured">
                       <strong class="wpc-cname-label">' . esc_html__('Connected Domain:', WPS_IC_TEXTDOMAIN) . '</strong> ' . esc_html($zone_name) . '<br/>
                    </div>
                    <div class="setting-value setting-configure" style="display: none;">
                         <p style="margin:0;">' . __('Use <strong>any domain</strong> you own to serve images and assets.', WPS_IC_TEXTDOMAIN) . '</p>
                    </div>
                </div>';

        } else {
            $html .= '<div style="flex-direction: column;display: flex;justify-content: center;">
                    <div>
                        <h4 class="fs-500 text-dark-300 fw-500 p-inline" style="margin-top:0;margin-bottom:10px;">' . esc_html__('Custom CDN Domain', WPS_IC_TEXTDOMAIN) . '</h4>
                    </div>
                    <div class="setting-value setting-configured cname-configured" style="display: none;">
                        <strong class="wpc-cname-label">' . esc_html__('Connected Domain:', WPS_IC_TEXTDOMAIN) . '</strong> ' . esc_html($zone_name) . '<br/>
                    </div>
                    <div class="setting-value setting-configure">
                        <p style="margin:0;">' . __('Use <strong>any domain</strong> you own to serve images and assets.', WPS_IC_TEXTDOMAIN) . '</p>
                    </div>
                </div>';
        }

        $html .= '</div>
              <div>';

        if (!empty($zone_name) && $isCfActive) {
            // CF is active with a CNAME — show Configure (CF popup), not Remove
            $html .= '<a href="#" class="wps-ic-configure-popup setting-configured" data-popup-width="600" data-popup="cf-cdn">' . esc_html__('Configure', WPS_IC_TEXTDOMAIN) . '</a>';

        } elseif (!empty($zone_name)) {
            // Generic/Bunny CNAME is set — show Remove + hidden Configure
            $html .= '<a href="#" class="wps-ic-configure-popup setting-configured" data-popup="remove-custom-cdn">
                <i class="icon-trash"></i> ' . esc_html__('Remove', WPS_IC_TEXTDOMAIN) . '</a>
                <a href="#" class="wps-ic-configure-popup setting-configure" data-popup-width="600" data-popup="' . $popup . '" style="display:none;">' . esc_html__('Configure', WPS_IC_TEXTDOMAIN) . '</a>';

        } else {

            if ($cnameLocked) {
                $html .= '<div class="wpc-box-check LockedTooltip" data-pop-text="<i class=\'wpc-sparkle-icon\'></i> ' . esc_attr__('Optimize Plan Required', WPS_IC_TEXTDOMAIN) . '" data-tooltip-position="top">';
                $html .= '<a href="#" class="wps-ic-configure-popup wpc-locked-configure-popup" style="pointer-events:none"><i class="wpc-gray-lock"></i>' . esc_html__('Locked', WPS_IC_TEXTDOMAIN) . '</a>';
                $html .= '</div>';
            } else {
                $html .= '
    <a href="#" class="wps-ic-configure-popup setting-configured" data-popup="remove-custom-cdn" style="display: none;">
    <i class="icon-trash"></i> ' . esc_html__('Remove', WPS_IC_TEXTDOMAIN) . '</a>
    <a href="#" class="wps-ic-configure-popup setting-configure" data-popup-width="600" data-popup="'.$popup.'">' . esc_html__('Configure', WPS_IC_TEXTDOMAIN) . '</a>';
            }

        }

        $html .= '</div>
            </div>';

        return $html;
    }

    public static function isFeatureEnabled($featureName)
    {
        $feature = get_transient($featureName . 'Enabled');
        if (!$feature || $feature == '0') {
            return false;
        }

        return true;
    }

    public static function usageGraph()
    {

        include WPS_IC_DIR . 'templates/admin/partials/v4/chart.php';

    }

    public static function CFGraph()
    {

        include WPS_IC_DIR . 'templates/admin/partials/v4/CFChart.php';

    }

    public static function usageLiteGraph()
    {

        include WPS_IC_DIR . 'templates/admin/partials/v4/liteChart.php';

    }


    public static function usageStats()
    {
        if (self::$user_credits->account->quotaType == 'pageviews') {
            include WPS_IC_DIR . 'templates/admin/partials/v4/pageview-stats.php';
        } else {
            include WPS_IC_DIR . 'templates/admin/partials/lite/stats.php';
        }
    }


    public static function dropdown($optionName = '', $title, $description = '', $values = [], $recommended = '')
    {

        if (empty(self::$options[$optionName])) {
            self::$options[$optionName] = '';
        }

        $option = self::$options[$optionName];
        $optionName_cleaned = sanitize_title($optionName);
        $optionName = 'options['.$optionName.']';

        $cssClass = '';
        if (empty($description)) {
            $cssClass = 'no-description';
        }

        $currentSetting = $option;
        if (empty($option)){
            $currentSetting = array_key_first($values);
        }


        $html = '<div class="wpc-box-for-dropdown ' . $cssClass . '">
                   <div class="wpc-box-content">
                   <div class="wpc-checkbox-title-holder">
                   <div class="circle-check active"></div>
                       <h4>' . $title . '</h4>
                   </div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }

        $html .= '</div>';

        $html .= '<div class="wpc-box-check">';

        // Clean parenthesized text from button label
        $buttonLabel = $values[$currentSetting];
        if (preg_match('/^(.+?)\s*\(.+?\)\s*$/', $buttonLabel, $bm)) {
            $buttonLabel = trim($bm[1]);
        }

        // Generate dropdown HTML with unique classes
        $html .= '<input type="hidden" class="wpc-dropdown-setting" name="' . $optionName . '" id="' . $optionName_cleaned . '_hidden" value="' . $currentSetting . '" />
<div class="wpc-cf-select-dropdown" id="' . $optionName_cleaned . '_dropdown">
  <button class="wpc-cf-select-button" type="button">
    <span class="selected-text">' . esc_html($buttonLabel) . '</span>
    <svg class="wpc-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
  </button>';

        $html .= '<div class="wpc-cf-select-menu">';

        foreach ($values as $k => $v) {
            $s = '';
            if ($k == $currentSetting) {
                $s = 'wpc-cf-active';
            }
            // Extract parenthesized text as badge
            $badge = '';
            $label = $v;
            if (preg_match('/^(.+?)\s*\((.+?)\)\s*$/', $v, $m)) {
                $label = trim($m[1]);
                $badgeClass = (stripos($m[2], 'Recommended') !== false) ? 'wpc-recommended-badge' : 'wpc-info-badge';
                $badge = '<span class="' . $badgeClass . '">' . esc_html($m[2]) . '</span>';
            }
            // Explicit recommended param overrides
            if ($recommended !== '' && $k === $recommended && empty($badge)) {
                $badge = '<span class="wpc-recommended-badge">' . esc_html__('Recommended', WPS_IC_TEXTDOMAIN) . '</span>';
            }
            $html .= '<a class="wpc-cf-select-item ' . $s . '" data-preset-title="' . esc_attr($label) . '" data-value="' . $k . '">' . esc_html($label) . $badge . '</a>';
        }

        $html .= '</div></div>';

        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    public static function cf_dropdown($title = 'Demo', $description = 'Demo')
    {
        // Dropdown options
        $cf_preset = ['off' => 'Off', 'home' => 'Home Page', 'all' => 'Full Site'];

        // Fixed option path
        $option = ['cf', 'edge-cache'];
        $optionName_cleaned = 'options_cf_edge-cache';
        $optionName = 'options[cf][edge-cache]';


        $cfOptions = get_option(WPS_IC_CF);

        // Get current value
        $cf_preset_config = $cfOptions['settings']['edge-cache'] ?? 'off';

        if (empty($cf_preset_config)) {
            $cf_preset_config = 'off';
        }

        $cssClass = '';
        if (empty($description)) {
            $cssClass = 'no-description';
        }

        $html = '<div class="wpc-box-for-checkbox ' . $cssClass . '">
                   <div class="wpc-box-content">
                   <div class="wpc-checkbox-title-holder">
                   <div class="circle-check active"></div>
                       <h4>' . $title . '</h4>
                   </div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }

        $html .= '</div>';

        $html .= '<div class="wpc-box-check">';

        // Generate dropdown HTML with unique classes
        $html .= '<input type="hidden" name="' . $optionName . '" id="' . $optionName_cleaned . '_hidden" value="' . $cf_preset_config . '" />
<div class="wpc-cf-select-dropdown" id="' . $optionName_cleaned . '_dropdown">
  <button class="wpc-cf-select-button" type="button">
    <span class="selected-text">' . $cf_preset[$cf_preset_config] . '</span>
    <svg class="wpc-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"></polyline></svg>
  </button>';

        $html .= '<div class="wpc-cf-select-menu">';

        foreach ($cf_preset as $k => $v) {
            $s = '';
            if ($k == $cf_preset_config) {
                $s = 'wpc-cf-active';
            }
            $html .= '<a class="wpc-cf-select-item ' . $s . '" data-preset-title="' . $v . '" data-value="' . $k . '">' . $v . '</a>';
        }

        $html .= '</div></div>';

        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

    public static function cf_checkboxDescription($title = 'Demo', $description = 'Demo', $option = 'default', $configure = false)
    {
        $html = '';
        $active = false;
        $circleActive = '';

        // Get CF settings from WPS_IC_CF option
        $cf = get_option(WPS_IC_CF);
        $cf_settings = isset($cf['settings']) ? $cf['settings'] : ['assets' => '1', 'edge-cache' => 'home'];

        if (!is_array($option)) {
            $optionName_cleaned = 'options_' . $option;
            $optionName = 'options[' . $option . ']';

            // Check CF settings
            if (isset($cf_settings[$option]) && $cf_settings[$option] == '1') {
                $active = true;
                $circleActive = 'active';
            }
        } else {
            $optionName_cleaned = 'options_' . $option[0] . '_' . $option[1];
            $optionName = 'options[' . $option[0] . '][' . $option[1] . ']';

            // Check CF settings using the second array key
            if (isset($cf_settings[$option[1]]) && $cf_settings[$option[1]] == '1') {
                $active = true;
                $circleActive = 'active';
            }
        }

        $cssClass = '';
        if (empty($description)) {
            $cssClass = 'no-description';
        }

        $html = '<div class="wpc-box-for-checkbox ' . $cssClass . '">
               <div class="wpc-box-content">
                   <div class="wpc-checkbox-title-holder">
                       <div class="circle-check ' . $circleActive . '"></div>
                       <h4>' . $title . '</h4>';

        if (!empty($configure) && $configure !== false) {
            $html .= '<a href="#" class="wps-ic-configure-popup" data-popup="' . $configure . '" data-popup-width="750" style="margin-left:10px">';
            $html .= '<svg class="wpc-gear-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M495.9 166.6c3.2 8.7 .5 18.4-6.4 24.6l-43.3 39.4c1.1 8.3 1.7 16.8 1.7 25.4s-.6 17.1-1.7 25.4l43.3 39.4c6.9 6.2 9.6 15.9 6.4 24.6c-4.4 11.9-9.7 23.3-15.8 34.3l-4.7 8.1c-6.6 11-14 21.4-22.1 31.2c-5.9 7.2-15.7 9.6-24.5 6.8l-55.7-17.7c-13.4 10.3-28.2 18.9-44 25.4l-12.5 57.1c-2 9.1-9 16.3-18.2 17.8c-13.8 2.3-28 3.5-42.5 3.5s-28.7-1.2-42.5-3.5c-9.2-1.5-16.2-8.7-18.2-17.8l-12.5-57.1c-15.8-6.5-30.6-15.1-44-25.4L83.1 425.9c-8.8 2.8-18.6 .3-24.5-6.8c-8.1-9.8-15.5-20.2-22.1-31.2l-4.7-8.1c-6.1-11-11.4-22.4-15.8-34.3c-3.2-8.7-.5-18.4 6.4-24.6l43.3-39.4C64.6 273.1 64 264.6 64 256s.6-17.1 1.7-25.4L22.4 191.2c-6.9-6.2-9.6-15.9-6.4-24.6c4.4-11.9 9.7-23.3 15.8-34.3l4.7-8.1c6.6-11 14-21.4 22.1-31.2c5.9-7.2 15.7-9.6 24.5-6.8l55.7 17.7c13.4-10.3 28.2-18.9 44-25.4l12.5-57.1c2-9.1 9-16.3 18.2-17.8C227.3 1.2 241.5 0 256 0s28.7 1.2 42.5 3.5c9.2 1.5 16.2 8.7 18.2 17.8l12.5 57.1c15.8 6.5 30.6 15.1 44 25.4l55.7-17.7c8.8-2.8 18.6-.3 24.5 6.8c8.1 9.8 15.5 20.2 22.1 31.2l4.7 8.1c6.1 11 11.4 22.4 15.8 34.3zM256 336a80 80 0 1 0 0-160 80 80 0 1 0 0 160z"/></svg>';
            $html .= '</a>';
        }

        $html .=  '</div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }

        $html .= '</div>';
        $html .= '<div class="wpc-box-check">';

        if ($active) {
            $html .= '<label class="wpc-switch">
           <input type="checkbox" class="wpc-ic-settings-v4-checkbox" checked="checked" value="1" id="' . $optionName_cleaned . '" name="' . $optionName . '"/>
           <span class="wpc-switch-slider wpc-switch-round"></span>
       </label>';
        } else {
            $html .= '<label class="wpc-switch">
           <input type="checkbox" class="wpc-ic-settings-v4-checkbox" value="1" id="' . $optionName_cleaned . '" name="' . $optionName . '"/>
           <span class="wpc-switch-slider wpc-switch-round"></span>
       </label>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    public static function font_dropdown($title = 'Demo', $description = 'Demo')
    {
        // Dropdown options
        $font_display_options = [
            'off' => 'Off',
            'auto' => 'Auto (Browser Default)',
            'block' => 'Block (FOIT)',
            'swap' => 'Swap (Recommended)',
            'fallback' => 'Fallback',
            'optional' => 'Optional'
        ];

        $optionName_cleaned = 'options_font-display';
        $optionName = 'options[font-display]';

        $settings = get_option(WPS_IC_SETTINGS);

        // Get current value
        $current_value = $settings['font-display'] ?? 'swap';

        if (empty($current_value)) {
            $current_value = 'off';
        }

        $cssClass = '';
        if (empty($description)) {
            $cssClass = 'no-description';
        }

        $html = '<div class="wpc-box-for-checkbox ' . $cssClass . '">
                <div class="wpc-box-content">
                <div class="wpc-checkbox-title-holder">
                <div class="circle-check active"></div>
                    <h4>' . $title . '</h4>
                </div>';

        if (!empty($description)) {
            $html .= '<p>' . $description . '</p>';
        }

        $html .= '</div>';

        $html .= '<div class="wpc-box-check">';

        // Generate dropdown HTML
        $html .= '<input type="hidden" name="' . $optionName . '" id="' . $optionName_cleaned . '_hidden" value="' . $current_value . '" />
<div class="wpc-font-select-dropdown" id="' . $optionName_cleaned . '_dropdown">
  <button class="wpc-font-select-button" type="button">
    ' . $font_display_options[$current_value] . '
  </button>';

        $html .= '<div class="wpc-font-select-menu">';

        foreach ($font_display_options as $value => $label) {
            $activeClass = '';
            if ($value == $current_value) {
                $activeClass = 'wpc-font-active';
            }
            $html .= '<a class="wpc-font-select-item ' . $activeClass . '" data-preset-title="' . $label . '" data-value="' . $value . '">' . $label . '</a>';
        }

        $html .= '</div></div>';

        $html .= '</div>';

        $html .= '</div>';

        return $html;
    }

}