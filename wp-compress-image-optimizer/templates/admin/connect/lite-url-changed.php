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
                                <h3><?php echo esc_html__('Reconnect Your Site', WPS_IC_TEXTDOMAIN); ?></h3>
                                <p><?php echo esc_html__('Your site URL has changed. Please re-enter your access key.', WPS_IC_TEXTDOMAIN); ?></p>
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
                            <div class="wps-ic-apikey-in-use" style="display: none;">
                                <div class="wps-ic-image"><img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/error.svg" /></div>
                                <h1><?php echo esc_html__('We have encountered an error', WPS_IC_TEXTDOMAIN); ?></h1>
                                <h2><?php echo esc_html__('This access key is already in use.', WPS_IC_TEXTDOMAIN); ?></h2>
                                <a href="#" class="wps-ic-connect-retry"><?php echo esc_html__('Retry', WPS_IC_TEXTDOMAIN); ?></a>
                            </div>
                            <div class="wps-ic-unable-to-communicate" style="display: none;">
                                <div class="wpc-connect-error-icon">
                                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                                        <line x1="12" y1="9" x2="12" y2="13"/>
                                        <line x1="12" y1="17" x2="12.01" y2="17"/>
                                    </svg>
                                </div>
                                <h1><?php echo esc_html__('Communication Issue', WPS_IC_TEXTDOMAIN); ?></h1>
                                <h2><?php echo esc_html__("We're unable to connect to the API.", WPS_IC_TEXTDOMAIN); ?></h2>
                                <div class="wpc-connect-error-detail">
                                    <p><?php echo esc_html__("Something (like a firewall, security plugin, Cloudflare, or server setting) is blocking communication. Don't worry — this is easy to fix.", WPS_IC_TEXTDOMAIN); ?></p>
                                    <a href="https://help.wpcompress.com/en-us/article/whitelisting-wp-compress-for-uninterrupted-service-4dwkra/" target="_blank" class="wpc-connect-guide-link">
                                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
                                        <?php echo esc_html__('View Whitelisting Guide', WPS_IC_TEXTDOMAIN); ?>
                                    </a>
                                </div>
                                <a href="#" class="wps-ic-connect-retry"><?php echo esc_html__('Retry Connection', WPS_IC_TEXTDOMAIN); ?></a>
                            </div>
                        </div>

                        <div class="wps-lite-form-container">
                            <div class="wpc-connect-header">
                                <h2><?php echo esc_html__('Plugin Activation', WPS_IC_TEXTDOMAIN); ?></h2>
                                <p><?php echo esc_html__('Your site URL has changed — please reconnect with your access key.', WPS_IC_TEXTDOMAIN); ?></p>
                            </div>

                            <div class="wpc-connect-premium-box wpc-connect-warning-box">
                                <div class="wpc-connect-premium-icon wpc-connect-warning-icon">
                                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                                </div>
                                <p><?php echo esc_html__('Looks like your site URL has changed, please reconnect with a new API key.', WPS_IC_TEXTDOMAIN); ?></p>
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

                        </div>
                    </div>
                </div>
            </div>

        </form>
    </div>

</div>
<script type="text/javascript" src="<?php echo WPS_IC_URI . 'assets/js/connect.js'; ?>"></script>
