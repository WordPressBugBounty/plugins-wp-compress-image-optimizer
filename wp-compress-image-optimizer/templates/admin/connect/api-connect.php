<?php
global $wps_ic;
?>
<div class="wps-ic-connect-form" style="display: none;">

    <div id="wps-ic-test-error" style="display: none;">
        <?php
        echo '<div class="ic-popup ic-popup-v2" id="wps-ic-connection-tests-inner">';
        #echo '<div class="ic-image"><img src="' . WPS_IC_URI . 'assets/tests/error_robot.png" /></div>';
        echo '<h3 class="ic-title">' . esc_html__('We have encountered an error', WPS_IC_TEXTDOMAIN) . '</h3>';
        echo '<ul class="wps-ic-check-list" style="margin:0px !important;">';
        echo '<li></li>';
        echo '</ul>';
        echo '<h5 class="ic-error-msg" style="margin:15px 0px;">' . esc_html__('Error message', WPS_IC_TEXTDOMAIN) . '</h5>';

        echo '<div class="ic-input-holder">';
        echo '<a class="button button-primary button-half wps-ic-swal-close" href="#">' . esc_html__('Retry', WPS_IC_TEXTDOMAIN) . '</a>';
        echo '<a class="button button-primary button-half wps-ic-swal-close" target="_blank" href="https://wpcompress.com/support">' . esc_html__('Contact support', WPS_IC_TEXTDOMAIN) . '</a>';
        echo '</div>';

        echo '</div>';
        ?>
    </div>
    <div id="wps-ic-connection-tests" style="display: none;">
        <?php
        echo '<div class="ic-popup ic-popup-v2" id="wps-ic-connection-tests-inner">';
        #echo '<div class="ic-image"><img src="' . WPS_IC_URI . 'assets/tests/robot.png" /></div>';
        echo '<h3 class="ic-title">' . esc_html__('We\'re running a few quick tests', WPS_IC_TEXTDOMAIN) . '</h3>';
        echo '<h5 class="ic-subtitle" style="padding-bottom:10px;">' . esc_html__('It should only be a few moments...', WPS_IC_TEXTDOMAIN) . '</h5>';
        echo '<ul class="wps-ic-check-list" style="margin:0px !important;">';
        echo '<li data-test="verify_api_key"><span class="fas fa-dot-circle running"></span> ' . esc_html__('API Key Validation', WPS_IC_TEXTDOMAIN) . '</li>';
        echo '<li data-test="finalization"><span class="fas fa-dot-circle running"></span> ' . esc_html__('Finalization', WPS_IC_TEXTDOMAIN) . '</li>';
        echo '</ul>';
        echo '<div class="ic-input-holder">';
        echo '<a class="button button-primary wps-ic-swal-close">' . esc_html__('Cancel', WPS_IC_TEXTDOMAIN) . '</a>';
        echo '</div>';
        echo '</div>';
        ?>
    </div>
    <div id="wps-ic-connection-tests-done" style="display: none;">
        <?php
        echo '<div class="ic-popup ic-popup-v2" id="wps-ic-connection-tests-inner">';
        echo '<h3 class="ic-title">' . esc_html__('Faster Loading Images on Autopilot', WPS_IC_TEXTDOMAIN) . '</h3>';
        echo '<h4 class="ic-subtitle">' . esc_html__('We\'ll automatically optimize and serve your images from our lightning-fast global CDN for increased performance.', WPS_IC_TEXTDOMAIN) . '</h4>';
        echo '<div class="ic-input-holder">';
        echo '<a href="' . admin_url('options-general.php?page=' . $wps_ic::$slug) . '" class="button button-primary">' . esc_html__('Start', WPS_IC_TEXTDOMAIN) . '</a>';
        echo '<a href="' . admin_url('options-general.php?page=' . $wps_ic::$slug) . '" class="grey-link" style="display:block;">' . esc_html__('I want to use Legacy Mode', WPS_IC_TEXTDOMAIN) . '</a>';
        echo '</div>';
        echo '</div>';
        ?>
    </div>

    <div class="wps-ic-connect-inner">
        <form method="post"
              action="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&do=activate'); ?>"
              id="wps-ic-connect-form">
            <?php wp_nonce_field('wpc_live_connect', 'nonce'); ?>
            <div class="wps-ic-init-container wps-ic-popup-message-container">
                <img src="<?php echo WPS_IC_URI . 'assets/images/live/bolt-icon_opt.png'; ?>"
                     alt="WP Compress - Lightning Fast Images" class="wps-ic-popup-icon"/>

                <h1><?php esc_html_e('Lightning Fast Load Times', WPS_IC_TEXTDOMAIN); ?></h1>
                <h2><?php esc_html_e('without lifting another finger past setup!', WPS_IC_TEXTDOMAIN); ?></h2>
            </div>

            <div class="wps-ic-error-message-container wps-ic-popup-message-container" style="display: none;">
                <img src="<?php echo WPS_IC_URI . 'assets/images/live/error-v2_opt.png'; ?>"
                     alt="WP Compress - Connection Error" class="wps-ic-popup-icon"/>
            </div>

            <div class="wps-ic-success-message-container wps-ic-popup-message-container" style="display: none;">
                <div class="ic-popup ic-popup-v2">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/fireworks.svg"/>
                </div>
            </div>

            <div class="wps-ic-loading-container wps-ic-popup-message-container" style="display:none;">
                <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>

                <h1><?php esc_html_e('Confirming Your Access Key', WPS_IC_TEXTDOMAIN); ?></h1>
                <h2><?php esc_html_e('You\'re so close to faster load times for life...', WPS_IC_TEXTDOMAIN); ?></h2>

            </div>

            <div class="wps-ic-error-message-container-text" style="display: none;">
                <h1><?php esc_html_e('We have encountered an error', WPS_IC_TEXTDOMAIN); ?></h1>
                <h2><?php esc_html_e('Your Access Key seems to be invalid', WPS_IC_TEXTDOMAIN); ?></h2>

                <a href="#" class="wps-ic-connect-retry"><?php esc_html_e('Retry', WPS_IC_TEXTDOMAIN); ?></a>
            </div>

            <div class="wps-ic-error-already-connected" style="display: none;">
                <h1><?php esc_html_e('We have encountered an error', WPS_IC_TEXTDOMAIN); ?></h1>
                <h2><?php esc_html_e('Your site is already connected to a different API Key', WPS_IC_TEXTDOMAIN); ?></h2>

                <a href="#" class="wps-ic-connect-retry"><?php esc_html_e('Retry', WPS_IC_TEXTDOMAIN); ?></a>
            </div>

            <div class="wps-ic-success-message-container-text" style="display: none;">
                <div class="wps-ic-success-message-container-text" style="display: block">
                    <h1 class="ic-title"><?php esc_html_e('It\'s Really That Simple...', WPS_IC_TEXTDOMAIN); ?></h1>
                    <h3 class="ic-text"><?php esc_html_e('It may take a few moments to start serving all assets, but you\'re all set up with lightning-fast live optimization!', WPS_IC_TEXTDOMAIN); ?></h3>
                    <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>"
                       class="wps-ic-dashboard-btn"><?php esc_html_e('Continue', WPS_IC_TEXTDOMAIN); ?></a>
                </div>
            </div>

            <div class="wps-ic-success-message-choice-container-text" style="display: none;">
                <div class="wps-ic-success-message-choice-container-text" style="display: block">
                    <h1 class="ic-title"><?php esc_html_e('Select Your Optimization Mode', WPS_IC_TEXTDOMAIN); ?></h1>
                    <h3 class="ic-text"><?php esc_html_e('Ultra-Powerful performance at your fingertips as simple toggles', WPS_IC_TEXTDOMAIN); ?></h3>
                    <div class="flex-link-container wpc-select-mode-containers">
                        <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>"
                           class="wps-big-button-with-icon wpc-live-btn">
                            <img src="<?php echo WPS_IC_URI; ?>assets/images/live-optimization-btn.svg"/>
                            <span><?php esc_html_e('Real-Time Optimization', WPS_IC_TEXTDOMAIN); ?></span>
                            <p><?php esc_html_e('Optimize images and scripts in real-time based on the visitor\'s attributes.', WPS_IC_TEXTDOMAIN); ?></p>
                            <div class="btn btn-primary hvr-grow wpc-live-btn-text"><?php esc_html_e('Select', WPS_IC_TEXTDOMAIN); ?></div>
                        </a>
                        <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>"
                           class="wps-big-button-with-icon wpc-local-btn">
                            <img src="<?php echo WPS_IC_URI; ?>assets/images/local-optimization-btn.svg"/>
                            <span><?php esc_html_e('Traditional Compression', WPS_IC_TEXTDOMAIN); ?></span>
                            <p><?php esc_html_e('Compress images in your local media library without CDN delivery.', WPS_IC_TEXTDOMAIN); ?></p>
                            <div class="btn btn-primary hvr-grow wpc-local-btn-text"><?php esc_html_e('Select', WPS_IC_TEXTDOMAIN); ?></div>
                        </a>
                    </div>
                </div>
            </div>

            <div class="wps-ic-finishing-container" style="display: none;">
                <div class="wps-ic-bulk-loading-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="loading-logo"/>
                    <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="loading-circle"/>
                </div>
            </div>


            <div class="wps-ic-form-container">
                <div class="wps-ic-form-field wps-ic-selection-form">
                    <div class="wps-ic-selection-button">
                        <input type="submit" class="hvr-grow" name="submit" value="<?php esc_attr_e('Lite Version', WPS_IC_TEXTDOMAIN); ?>"/>
                    </div>
                    <strong style="margin:15px 0px;display:block;"><?php esc_html_e('OR', WPS_IC_TEXTDOMAIN); ?></strong>
                    <div class="wps-ic-selection-button" style="margin-top:0px !important;">
                        <input type="button" class="hvr-grow wpc-pro-version-btn" name="button" value="<?php esc_attr_e('Pro Version', WPS_IC_TEXTDOMAIN); ?>"/>
                    </div>
                </div>
                <div class="wps-ic-pro-form-field" style="display: none;">
                    <div class="wps-ic-form-field">
                        <label for="apikey"><?php esc_html_e('Enter Your Access Key', WPS_IC_TEXTDOMAIN); ?></label>
                        <input id="apikey" type="text" placeholder="u390jv0v28zquh8293uzfhc" name="apikey" value=""/>
                    </div>
                    <div class="wps-ic-submit-field">
                        <input type="submit" class="hvr-grow" name="submit" value="<?php esc_attr_e('Start', WPS_IC_TEXTDOMAIN); ?>"/>
                    </div>
                    <div class="wps-ic-form-other-options">
                        <a href="https://app.wpcompress.com/register" class="fadeIn noline" target="_blank"><?php esc_html_e('Create an Account', WPS_IC_TEXTDOMAIN); ?></a>
                        </br>
                        <a href="https://app.wpcompress.com/" class="fadeIn noline" target="_blank"
                           style="text-decoration: none;margin-top: 5px;display: inline-block;"><?php esc_html_e('Go to Portal', WPS_IC_TEXTDOMAIN); ?></a>
                    </div>
                </div>
            </div>

        </form>
    </div>

</div>
<script type="text/javascript" src="<?php echo WPS_IC_URI . 'assets/js/connect.js'; ?>"></script>