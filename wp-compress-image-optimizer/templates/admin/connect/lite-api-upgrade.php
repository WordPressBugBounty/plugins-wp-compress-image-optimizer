<?php
global $wps_ic;
?>
<div class="wps-ic-lite-connect-form" style="display: none;">

    <div class="wps-ic-connect-inner">
        <form method="post" action="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&do=activate'); ?>" id="wps-ic-connect-form">
            <?php wp_nonce_field('wpc_live_connect', 'nonce'); ?>

            <div class="wps-lite-connect-outter">
                <div class="wps-lite-connect-inner">

                    <!-- Left Column: SVG Illustration -->
                    <div class="wps-lite-connect-left">
                        <div class="wpc-connect-mesh-1"></div>
                        <div class="wpc-connect-mesh-2"></div>
                        <div class="wpc-connect-illustration">
                            <div class="wpc-connect-lock-box">
                                <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                    <rect x="3" y="11" width="18" height="11" rx="3" ry="3"></rect>
                                    <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                                    <circle cx="12" cy="15" r="1" fill="currentColor"></circle>
                                    <path d="M12 16v2"></path>
                                </svg>
                                <div class="wpc-connect-floating-key">
                                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="7.5" cy="15.5" r="5.5"></circle>
                                        <path d="m21 2-9.6 9.6"></path>
                                        <path d="m15.5 7.5 3 3L22 7l-3-3"></path>
                                    </svg>
                                </div>
                            </div>
                            <div class="wpc-connect-illus-text">
                                <h3><?php echo esc_html__('Supercharge Your Site', WPS_IC_TEXTDOMAIN); ?></h3>
                                <p><?php echo esc_html__('Join thousands of businesses experiencing lightning-fast load times.', WPS_IC_TEXTDOMAIN); ?></p>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Form -->
                    <div class="wps-lite-connect-right">

                        <div class="wps-ic-msg-container">
                            <div class="wps-ic-loading-container wps-ic-popup-message-container" style="display:none;">
                                <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>
                                <h1><?php echo esc_html__('Confirming Your Access Key', WPS_IC_TEXTDOMAIN); ?></h1>
                                <h2><?php echo esc_html__("You're so close to faster load times for life...", WPS_IC_TEXTDOMAIN); ?></h2>
                            </div>
                            <div class="wps-ic-loading-container wpc-loading-lite wps-ic-popup-message-container" style="display:none;">
                                <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>
                                <h1><?php echo esc_html__('Linking Your Account', WPS_IC_TEXTDOMAIN); ?></h1>
                                <h2><?php echo esc_html__("You're so close to faster load times for life...", WPS_IC_TEXTDOMAIN); ?></h2>
                            </div>
                            <div class="wps-ic-site-already-connected" style="display: none;">
                                <div class="wps-ic-image"><img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/error.svg" /></div>
                                <h1><?php echo esc_html__('We have encountered an error', WPS_IC_TEXTDOMAIN); ?></h1>
                                <h2><?php echo esc_html__('Your site is already connected to a different access key.', WPS_IC_TEXTDOMAIN); ?></h2>
                                <a href="#" class="wps-ic-connect-retry"><?php echo esc_html__('Retry', WPS_IC_TEXTDOMAIN); ?></a>
                            </div>
                            <div class="wps-ic-invalid-apikey" style="display: none;">
                                <div class="wps-ic-image"><img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/error.svg" /></div>
                                <h1><?php echo esc_html__('We have encountered an error', WPS_IC_TEXTDOMAIN); ?></h1>
                                <h2><?php echo esc_html__('Your access key seems to be invalid.', WPS_IC_TEXTDOMAIN); ?></h2>
                                <a href="#" class="wps-ic-connect-retry"><?php echo esc_html__('Retry', WPS_IC_TEXTDOMAIN); ?></a>
                            </div>
                        </div>

                        <div class="wps-lite-form-container">
                            <div class="wpc-connect-header">
                                <h2><?php echo esc_html__('Plugin Activation', WPS_IC_TEXTDOMAIN); ?></h2>
                                <p><?php echo esc_html__('Enter your unique access key to get started.', WPS_IC_TEXTDOMAIN); ?></p>
                            </div>

                            <div class="wpc-connect-premium-box">
                                <div class="wpc-connect-premium-icon">
                                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                </div>
                                <p><?php echo esc_html__('Unlock premium features including advanced configuration, image optimization, and global CDN access.', WPS_IC_TEXTDOMAIN); ?></p>
                            </div>

                            <span class="wps-ic-lite-input-field-error" style="display: none;"><?php echo esc_html__('Please enter your API Key.', WPS_IC_TEXTDOMAIN); ?></span>

                            <div class="wps-ic-lite-input-container">
                                <div class="wps-ic-lite-input-icon">
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="12" r="10"></circle>
                                        <path d="m14.31 8 5.74 9.94"></path>
                                        <path d="M9.69 8h11.48"></path>
                                        <path d="m7.38 12 5.74-9.94"></path>
                                        <path d="M9.69 16 3.95 6.06"></path>
                                        <path d="M14.31 16H2.83"></path>
                                        <path d="m16.62 12-5.74 9.94"></path>
                                    </svg>
                                </div>
                                <div class="wps-ic-lite-input-field">
                                    <input type="text" name="apikey" placeholder="<?php echo esc_attr__('e.g. wpc_1234567890abcdef', WPS_IC_TEXTDOMAIN); ?>"/>
                                </div>
                            </div>

                            <div class="wps-spacer"></div>
                            <input type="submit" class="wps-ic-button wps-ic-submit-btn" name="submit" value="<?php echo esc_attr__('Activate Plugin', WPS_IC_TEXTDOMAIN); ?>"/>

                            <div class="wpc-connect-lite-link">
                                <a href="#" class="wps-use-lite">
                                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                    <?php echo esc_html__('Or Continue with Lite Version', WPS_IC_TEXTDOMAIN); ?>
                                </a>
                            </div>

                            <div class="wps-ic-lite-connect-footer" <?php if (get_option('hide_wpcompress_plugin')) {echo 'style="display:none;"';} ?>>
                                <p><?php echo wp_kses_post(__("Don't have an access key? You may <a href=\"https://app.wpcompress.com/register/\" target=\"_blank\">create a free account</a> to unlock bonus performance features and portal access.", WPS_IC_TEXTDOMAIN)); ?></p>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

        </form>
    </div>

</div>
<script type="text/javascript" src="<?php echo WPS_IC_URI . 'assets/js/upgrade.js'; ?>"></script>
