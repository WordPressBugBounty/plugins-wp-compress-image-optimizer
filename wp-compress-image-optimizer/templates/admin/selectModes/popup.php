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
            <h4 style="margin-top: 0px;margin-bottom: 46px;display:none;">We are setting up your DNS, this can take up
                to 30 seconds...</h4>
        </div>

        <div class="cdn-popup-content">
            <div class="cdn-popup-top">
                <div class="inline-heading">
                    <div class="inline-heading-text">
                        <h3>Select Your Optimization Mode</h3>
                        <p>You may change your mode or customize advanced settings at any time!</p>
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
                            <h3>Safe Mode</h3>
                            <p>Start with no settings active, <br/>then customize as you wish
                            </p>
                        </div>
                        <ul>
                            <li>Advanced Website Caching</li>
                            <li>Resize Images by Device</li>
                            <li>Serve WebP Images</li>
                            <li>Lazy Load Images</li>
                            <li>Generate Critical CSS</li>
                            <li>Move JavaScript to Footer</li>
                            <li>Delay JavaScript Files</li>
                        </ul>
                    </div>
                    <div class="wpc-popup-column <?php echo $recommendedModeSelected; ?>" data-slider-bar="2"
                         data-mode="recommended">
                        <div class="wpc-column-heading">
                            <h3>Recommended Mode</h3>
                            <p>Our recommended blend of <br/>performance and compatibility
                            </p>
                        </div>
                        <ul>
                            <li class="wpc-active">Advanced Website Caching</li>
                            <li class="wpc-active">Resize Images by Device</li>
                            <li class="wpc-active">Serve WebP Images</li>
                            <li class="wpc-active">Lazy Load Assets</li>
                            <li class="wpc-active">Inline CSS</li>
                            <li>Generate Critical CSS</li>
                            <li>Move JavaScript to Footer</li>
                            <li>Delay JavaScript Files</li>
                        </ul>
                    </div>
                    <div class="wpc-popup-column <?php echo $agressiveModeSelected; ?> wpc-darker" data-slider-bar="3"
                         data-mode="aggressive">
                        <div class="wpc-column-heading">
                            <h3>Aggressive Mode</h3>
                            <p>Squeeze out performance, may require <br/>excluding specific files from optimization
                            </p>
                        </div>
                        <ul>
                            <li class="wpc-active">Advanced Website Caching</li>
                            <li class="wpc-active">Resize Images by Device</li>
                            <li class="wpc-active">Serve WebP Images</li>
                            <li class="wpc-active">Native Lazy Load Images</li>
                            <li class="wpc-active">Critical CSS</li>
                            <li class="wpc-active">Inline CSS</li>
                            <li class="wpc-active">Delay JavaScript Files</li>
                            <li class="wpc-active">Inline JavaScript Files</li>
                        </ul>
                    </div>
                </div>

                <?php
                $allowLive = get_option('wps_ic_allow_live');
                $hidden = '';
                if (!$allowLive) {
                    $hidden = 'style="display:none"';
                }
                ?>

                <div class="wpc-popup-options <?php echo $lockedClass; ?> " <?php echo $hidden; ?> >
                    <div class="wpc-popup-option">
                        <div class="wpc-popup-option-icon">
                            <img src="<?php echo WPS_IC_URI; ?>assets/v4/images/popups/selectMode/option-1.svg"
                                 alt="Enable Real-Time Optimization + CDN"/>
                        </div>
                        <div class="wpc-popup-option-description">
                            <h4>Enable Real-Time Optimization + CDN</h4>
                            <p>Optimize and serve your website content across the globe</p>
                        </div>
                        <div class="wpc-popup-option-checkbox">
                            <div class="form-check  <?php if (!empty($lockedClass)) {
                                echo 'SwalTooltip';
                            } ?>" data-pop-text="<i class='wpc-sparkle-icon'></i> Optimize Plan Required">
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
                            src="<?php echo WPS_IC_URI; ?>assets/v4/images/popups/selectMode/save.svg" alt="Save"/>Save
                    Settings</a>
            </div>
        </div>

    </div>
</div>