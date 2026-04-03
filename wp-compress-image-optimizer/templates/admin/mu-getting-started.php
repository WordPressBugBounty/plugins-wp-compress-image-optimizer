<?php
global $wps_ic, $wpdb;
?>
<div class="wrap">
    <div class="wps_ic_wrap wps_ic_settings_page wps_ic_live wpc-mu-connect-mu-container">

        <div class="wp-compress-pre-wrapper">

            <div class="wpc-mu-connect-container">

                <div class="wp-compress-mu-content-overlay" style="display: none;">
                    <div class="wp-compress-mu-content-overlay-inner">
                        <div class="wps-ic-mu-site-saving-logo">
                            <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-mu-logo-prepare"/>
                            <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-mu-preparing"/>
                        </div>
                    </div>
                </div>

                <div class="wpc-mu-connect-left-side">
                    <div class="wpc-mu-connect-logo">
                        <img src="<?php echo WPS_IC_URI; ?>assets/images/main-logo.svg"/>
                    </div>
                    <h1><?php esc_html_e('Multisite Connect', WPS_IC_TEXTDOMAIN); ?></h1>
                    <h3><?php esc_html_e('Paste your multisite access key from the management portal', WPS_IC_TEXTDOMAIN); ?></h3>
                    <form method="post" action="#" class="wpc-mu-api-connect-form">
                        <input type="text" name="api_token" placeholder="<?php echo esc_attr__('Access Key', WPS_IC_TEXTDOMAIN); ?>"/>
                        <div class="wps-ic-mu-connecting-logo" style="display: none;">
                            <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-mu-logo-prepare"/>
                            <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-mu-preparing"/>
                        </div>
                        <input type="button" class="wps-ic-mu-button-connecting" value="<?php echo esc_attr__('Connecting...', WPS_IC_TEXTDOMAIN); ?>" style="display:none;"/>
                        <input type="submit" value="<?php echo esc_attr__('Submit', WPS_IC_TEXTDOMAIN); ?>"/>
                    </form>

                    <div class="wpc-mu-connect-footer-text">
                        <h3><?php esc_html_e('This form collects information we use to send your API Key, news, updates, promotions and special offers. We do not share or sell your information. You may unsubscribe at any time.', WPS_IC_TEXTDOMAIN); ?></h3>
                    </div>
                </div>
                <div class="wpc-mu-connect-right-side">
                    <div class="wpc-mu-connect-bg">
                        <div class="wpc-top-center-logo">
                            <img src="<?php echo WPS_IC_URI; ?>assets/mu/connect/middle.svg"/>
                        </div>
                        <div class="wpc-bottom-hill">
                            <img src="<?php echo WPS_IC_URI; ?>assets/mu/connect/hill.svg"/>
                            <div class="wpc-bottom-tree">
                                <img src="<?php echo WPS_IC_URI; ?>assets/mu/connect/tree.svg"/>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="wpc-mu-connect-optimization-mode-popup" style="display:none;">
                <div class="wpc-mu-connect-optimization-mode-popup-inner">
                    <div class="wps-ic-success-message-choice-container-text" style="display: block">
                        <h1 class="ic-title"><?php esc_html_e('Select Your Optimization Mode', WPS_IC_TEXTDOMAIN); ?></h1>
                        <h3 class="ic-text"><?php esc_html_e('Ultra-Powerful performance at your fingertips as simple toggles', WPS_IC_TEXTDOMAIN); ?></h3>
                        <div class="flex-link-container">
                            <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>" class="wps-big-button-with-icon wpc-live-btn">
                                <img src="<?php echo WPS_IC_URI; ?>assets/images/live-optimization-btn.svg"/>
                                <span><?php esc_html_e('Real-Time Optimization', WPS_IC_TEXTDOMAIN); ?></span>
                                <p><?php esc_html_e('Optimize images and scripts in real-time based on the visitor\'s attributes.', WPS_IC_TEXTDOMAIN); ?></p>
                                <div class="btn btn-primary hvr-grow"><?php esc_html_e('Select', WPS_IC_TEXTDOMAIN); ?></div>
                            </a>
                            <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>" class="wps-big-button-with-icon wpc-local-btn">
                                <img src="<?php echo WPS_IC_URI; ?>assets/images/local-optimization-btn.svg"/>
                                <span><?php esc_html_e('Traditional Compression', WPS_IC_TEXTDOMAIN); ?></span>
                                <p><?php esc_html_e('Compress images in your local media library without CDN delivery.', WPS_IC_TEXTDOMAIN); ?></p>
                                <div class="btn btn-primary hvr-grow"><?php esc_html_e('Select', WPS_IC_TEXTDOMAIN); ?></div>
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <div class="wpc-mu-connect-failure" style="display:none;">
                <div id="connect-failure-popup-inner" class="swal-popup-inner bottom-border">

                    <div class="cdn-popup-top">
                        <div class="wps-ic-mu-popup-select-sites">
                            <img src="<?php echo WPS_IC_URI; ?>assets/images/projected-alert.svg" style="width:160px;" />
                        </div>
                        <h1 class="ic-title"><?php esc_html_e('Access Key Error!', WPS_IC_TEXTDOMAIN); ?></h1>
                        <h3 class="ic-text"><?php esc_html_e('Access Key which you have entered is not found in our database.', WPS_IC_TEXTDOMAIN); ?></h3>
                    </div>

                    <div class="cdn-popup-bottom-border">&nbsp;</div>

                </div>
            </div>

        </div>

    </div>