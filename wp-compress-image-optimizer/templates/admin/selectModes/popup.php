<?php if (!defined('WPS_IC_TEXTDOMAIN')) return; ?>
<div id="select-mode" style="display: none;">
    <div id="select-mode-popup-inner" class="ajax-settings-popup bottom-border">

        <?php
        function isFeatureEnabledPopup($featureName)
        {
            $feature = get_transient($featureName . 'Enabled');
            if (!$feature || $feature == '0') {
                return false;
            }

            return true;
        }

        wp_nonce_field('wpc_save_mode', 'wpc_save_mode_nonce');
        $mode = get_option(WPS_IC_PRESET);

        $cdnEnabled = isFeatureEnabledPopup('cdn');
        $cdnLocked = false;
        $lockedClass = '';

        if (!$cdnEnabled) {
            $cdnLocked = true;
            $lockedClass = ' wpc-locked-setting';
        }

        $safeModeSelected = '';
        $recommendedModeSelected = '';
        $agressiveModeSelected = '';
        $sliderWidth = 'wpc-select-bar-width-1';

        if (empty($mode)) {
            $agressiveModeSelected = 'wpc-active';
            $sliderWidth = 'wpc-select-bar-width-3';
        } else {
            if ($mode == 'aggressive') {
                $agressiveModeSelected = 'wpc-active';
                $sliderWidth = 'wpc-select-bar-width-3';
            } else if ($mode == 'safe') {
                $safeModeSelected = 'wpc-active';
                $sliderWidth = 'wpc-select-bar-width-1';
            } else {
                $recommendedModeSelected = 'wpc-active';
                $sliderWidth = 'wpc-select-bar-width-2';
            }
        }

        ?>

        <div class="cdn-popup-loading" style="display: none;">
            <div class="wpc-popup-saving-logo-container">
                <div class="wpc-popup-saving-preparing-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg"
                         class="wpc-ic-popup-logo-saving"/>
                    <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-popup-logo-saving-loader"/>
                </div>
            </div>
            <h4 style="margin-top: 0px;margin-bottom: 46px;display:none;"><?php esc_html_e('We are setting up your DNS, this can take up to 30 seconds...', WPS_IC_TEXTDOMAIN); ?></h4>
        </div>

        <div class="cdn-popup-content">
            <div class="cdn-popup-top">
                <div class="inline-heading">
                    <div class="inline-heading-text">
                        <h3><?php esc_html_e('Select Your Optimization Mode', WPS_IC_TEXTDOMAIN); ?></h3>
                        <p><?php esc_html_e('You may change your mode or customize advanced settings at any time!', WPS_IC_TEXTDOMAIN); ?></p>
                    </div>
                </div>
            </div>
            <div class="cdn-popup-content-full">
                <div class="wpc-popup-select-bar-container">
                    <div class="wpc-select-bar">
                        <div class="wpc-select-bar-outter">
                            <div class="wpc-select-bar-inner <?php echo $sliderWidth; ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="wpc-popup-columns wpc-three-columns">
                    <div class="wpc-popup-column <?php echo $safeModeSelected; ?>" data-slider-bar="1" data-mode="safe">
                        <div class="wpc-column-heading">
                            <h3><?php esc_html_e('Safe Mode', WPS_IC_TEXTDOMAIN); ?></h3>
                            <p><?php esc_html_e('Start with no settings active,', WPS_IC_TEXTDOMAIN); ?><br/><?php esc_html_e('then customize as you wish', WPS_IC_TEXTDOMAIN); ?>
                            </p>
                        </div>
                        <ul>
                            <li><?php esc_html_e('Advanced Website Caching', WPS_IC_TEXTDOMAIN); ?></li>
                            <li><?php esc_html_e('Resize Images by Device', WPS_IC_TEXTDOMAIN); ?></li>
                            <li><?php esc_html_e('Serve WebP Images', WPS_IC_TEXTDOMAIN); ?></li>
                            <li><?php esc_html_e('Lazy Load Images', WPS_IC_TEXTDOMAIN); ?></li>
                            <li><?php esc_html_e('Generate Critical CSS', WPS_IC_TEXTDOMAIN); ?></li>
                            <li><?php esc_html_e('Move JavaScript to Footer', WPS_IC_TEXTDOMAIN); ?></li>
                            <li><?php esc_html_e('Delay JavaScript Files', WPS_IC_TEXTDOMAIN); ?></li>
                        </ul>
                    </div>
                    <div class="wpc-popup-column <?php echo $recommendedModeSelected; ?>" data-slider-bar="2"
                         data-mode="recommended">
                        <div class="wpc-column-heading">
                            <h3><?php esc_html_e('Recommended Mode', WPS_IC_TEXTDOMAIN); ?></h3>
                            <p><?php esc_html_e('Our recommended blend of', WPS_IC_TEXTDOMAIN); ?><br/><?php esc_html_e('performance and compatibility', WPS_IC_TEXTDOMAIN); ?>
                            </p>
                        </div>
                        <ul>
                            <li class="wpc-active"><?php esc_html_e('Advanced Website Caching', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Resize Images by Device', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Serve WebP Images', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Lazy Load Assets', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Inline CSS', WPS_IC_TEXTDOMAIN); ?></li>
                            <li><?php esc_html_e('Generate Critical CSS', WPS_IC_TEXTDOMAIN); ?></li>
                            <li><?php esc_html_e('Move JavaScript to Footer', WPS_IC_TEXTDOMAIN); ?></li>
                            <li><?php esc_html_e('Delay JavaScript Files', WPS_IC_TEXTDOMAIN); ?></li>
                        </ul>
                    </div>
                    <div class="wpc-popup-column <?php echo $agressiveModeSelected; ?> wpc-darker" data-slider-bar="3"
                         data-mode="aggressive">
                        <div class="wpc-column-heading">
                            <h3><?php esc_html_e('Aggressive Mode', WPS_IC_TEXTDOMAIN); ?></h3>
                            <p><?php esc_html_e('Squeeze out performance, may require', WPS_IC_TEXTDOMAIN); ?><br/><?php esc_html_e('excluding specific files from optimization', WPS_IC_TEXTDOMAIN); ?>
                            </p>
                        </div>
                        <ul>
                            <li class="wpc-active"><?php esc_html_e('Advanced Website Caching', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Resize Images by Device', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Serve WebP Images', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Native Lazy Load Images', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Critical CSS', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Inline CSS', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Delay JavaScript Files', WPS_IC_TEXTDOMAIN); ?></li>
                            <li class="wpc-active"><?php esc_html_e('Inline JavaScript Files', WPS_IC_TEXTDOMAIN); ?></li>
                        </ul>
                    </div>
                </div>

                <?php
                $cf = get_option(WPS_IC_CF);
                $cfLive = false;
                if ($cf && isset($cf['settings'])) {
                    $cfLive = ($cf['settings']['assets'] == '1' && $cf['settings']['cdn'] == '0');
                }
                $allowLive = get_option('wps_ic_allow_live') && !$cfLive;
                $hidden = '';
                if (!$allowLive) {
                    $hidden = 'style="display:none"';
                }
                ?>

                <div class="wpc-popup-options <?php echo $lockedClass; ?> " <?php echo $hidden; ?> >
                    <div class="wpc-popup-option">
                        <div class="wpc-popup-option-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 576 512" style="fill:currentColor"><path d="M70.8-6.7c5.4-5.4 13.8-6.2 20.2-2L209.9 70.5c8.9 5.9 14.2 15.9 14.2 26.6l0 49.6 90.8 90.8c33.3-15 73.9-8.9 101.2 18.5L542.2 382.1c18.7 18.7 18.7 49.1 0 67.9l-60.1 60.1c-18.7 18.7-49.1 18.7-67.9 0L288.1 384c-27.4-27.4-33.5-67.9-18.5-101.2l-90.8-90.8-49.6 0c-10.7 0-20.7-5.3-26.6-14.2L23.4 58.9c-4.2-6.3-3.4-14.8 2-20.2L70.8-6.7zm145 303.5c-6.3 36.9 2.3 75.9 26.2 107.2l-94.9 95c-28.1 28.1-73.7 28.1-101.8 0s-28.1-73.7 0-101.8l135.4-135.5 35.2 35.1zM384.1 0c20.1 0 39.4 3.7 57.1 10.5 10 3.8 11.8 16.5 4.3 24.1L388.8 91.3c-3 3-4.7 7.1-4.7 11.3l0 41.4c0 8.8 7.2 16 16 16l41.4 0c4.2 0 8.3-1.7 11.3-4.7l56.7-56.7c7.6-7.5 20.3-5.7 24.1 4.3 6.8 17.7 10.5 37 10.5 57.1 0 43.2-17.2 82.3-45 111.1l-49.1-49.1c-33.1-33-78.5-45.7-121.1-38.4l-56.8-56.8 0-29.7-.2-5c-.8-12.4-4.4-24.3-10.5-34.9 29.4-35 73.4-57.2 122.7-57.3z"/></svg>
                        </div>
                        <div class="wpc-popup-option-description">
                            <h4><?php esc_html_e('Enable Real-Time Optimization + CDN', WPS_IC_TEXTDOMAIN); ?></h4>
                            <p><?php esc_html_e('Optimize and serve your website content across the globe', WPS_IC_TEXTDOMAIN); ?></p>
                        </div>
                        <div class="wpc-popup-option-checkbox">
                            <div class="form-check <?php if (!empty($lockedClass)) { echo 'SwalTooltip'; } ?>"
                                 data-pop-text="<?php echo esc_attr__("<i class='wpc-sparkle-icon'></i> Optimize Plan Required", WPS_IC_TEXTDOMAIN); ?>">
                                <?php
                                /*if ($cdnLocked){ ?>
                                    <input class="form-check-input checkbox mt-0" type="checkbox">
                                    <label class="with-label" for="mode-options" style="pointer-events:none"><span></span></label>
                                <?php #}else{*/ ?>
                                <div class="wpc-cdn-mode-enabled">
                                    <input class="form-check-input checkbox mt-0" data-for-div-id="mode-options"
                                           type="checkbox" value="1" id="mode-options" name="mode-options"
                                           checked="checked">
                                    <label class="with-label" for="mode-options"><span></span></label>
                                </div>
                                <?php #} ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="cdn-popup-save">
                <a href="#" class="cdn-popup-save-btn"><img
                            src="<?php echo WPS_IC_URI; ?>assets/v4/images/popups/selectMode/save.svg" alt="<?php echo esc_attr__('Save', WPS_IC_TEXTDOMAIN); ?>"/><?php esc_html_e('Save Settings', WPS_IC_TEXTDOMAIN); ?></a>
            </div>
        </div>

    </div>
</div>