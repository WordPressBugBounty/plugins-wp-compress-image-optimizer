<?php
global $wps_ic;
?>
<div class="wps-ic-lite-connect-form" style="display: none;">

    <div class="wps-ic-connect-inner">
        <form method="post" action="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&do=activate'); ?>" id="wps-ic-connect-form">
            <?php wp_nonce_field('wpc_live_connect', 'nonce'); ?>


            <div class="wps-lite-connect-outter">
                <div class="wps-lite-connect-inner">

                    <div class="wps-lite-connect-left">
                        <img src="<?php echo WPS_IC_URI; ?>/assets/lite/images/wl-popup-img.svg" alt="Lite Connect"/>
                    </div>

                    <div class="wps-lite-connect-right">

                        <div class="wps-ic-msg-container">
                            <div class="wps-ic-loading-container wps-ic-popup-message-container" style="display:none;">
                                <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>

                                <h1>Confirming Your Access Key</h1>
                                <h2>You're so close to faster load times for life...</h2>
                            </div>
                            <div class="wps-ic-loading-container wpc-loading-lite wps-ic-popup-message-container" style="display:none;">
                                <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>

                                <h1>Linking Your Account</h1>
                                <h2>You're so close to faster load times for life...</h2>
                            </div>
                            <div class="wps-ic-site-already-connected" style="display: none;">
                                <div class="wps-ic-image"><img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/error.svg" /></div>
                                <h1>We have encountered an error</h1>
                                <h2>Your site is already connected!</h2>

                                <a href="#" class="wps-ic-connect-retry">Retry</a>
                            </div>
                            <div class="wps-ic-invalid-apikey" style="display: none;">
                                <div class="wps-ic-image"><img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/error.svg" /></div>
                                <h1>We have encountered an error</h1>
                                <h2>Your Access Key seems to be invalid</h2>

                                <a href="#" class="wps-ic-connect-retry">Retry</a>
                            </div>

                            <div class="wps-ic-unable-to-communicate" style="padding:200px;display: none;">
                                <div class="wps-ic-image"><img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/error.svg" /></div>
                                <h1>Communication Issue</h1>
                                <h2>We’re unable to connect to the API.</h2>
                                <p>It seems something (like a firewall, security plugin, Cloudflare, or server setting) is blocking communication.<br/><br/>Don’t worry, this is easy to fix!</p>
                                <p>Follow our <a href="https://help.wpcompress.com/en-us/article/whitelisting-wp-compress-for-uninterrupted-service-4dwkra/" target="_blank">Whitelisting Guide</a> for step-by-step instructions to restore access.</p>

                                <a href="#" class="wps-ic-connect-retry">Retry</a>
                            </div>
                        </div>

                        <div class="wps-lite-form-container">
                            <h2>Plugin Activation</h2>
                            <h4>Enter Your Access Key</h4>
                            <ul class="wpc-inline-feature-list">
                                <li class="wpc-inline-fl-icon"><img src="<?php echo WPS_IC_URI; ?>/assets/v4/images/lite-check.svg" alt="Lite Check"/></li>
                                <li class="wpc-inline-fl-text">Unlock all premium features, including advanced configuration, image optimization, and global CDN access.</li>
                            </ul>

                            <span class="wps-ic-lite-input-field-error" style="display: none;">Please enter your API Key.</span>

                            <div class="wps-ic-lite-input-container">
                                <div class="wps-ic-lite-input-icon">
                                    <img src="<?php echo WPS_IC_URI; ?>/assets/v4/images/aperture.svg" alt="Access Key"/>
                                </div>

                                <div class="wps-ic-lite-input-field">
                                    <input type="text" name="apikey" placeholder="Enter Access Key"/>
                                </div>
                            </div>
                            <div class="wps-spacer"></div>
                            <input type="submit" class="wps-ic-button wps-ic-submit-btn" name="submit" value="Activate"/>
                            <div class="wps-spacer" style="height: 30px;"></div>
                            <div class="wps-lite-option-container">
                                <a href="#" class="wps-use-lite"><img src="<?php echo WPS_IC_URI; ?>/assets/lite/images/tool.svg" alt="Access Key"/> Or Use the Lite Version</a>
                            </div>


                            <div class="wps-ic-pro-form-field" style="display: none;">

                                <div class="wps-ic-form-other-options">
                                    <a href="https://app.wpcompress.com/register" class="fadeIn noline" target="_blank">Create an
                                        Account</a>
                                    </br>
                                    <a href="https://app.wpcompress.com/" class="fadeIn noline" target="_blank"
                                       style="text-decoration: none;margin-top: 5px;display: inline-block;">Go to Portal</a>
                                </div>
                            </div>

                            <div class="wps-spacer" style="height: 30px;"></div>

                            <div class="wps-ic-lite-connect-footer" <?php if (get_option('hide_wpcompress_plugin')) {echo 'style="display:none;"';} ?>>
                                <p>You may <a href="https://app.wpcompress.com/" target="_blank">create a free account</a> to unlock bonus performance features and get access to the management portal.</p>
                            </div>

                        </div>
                    </div>
                </div>
            </div>

        </form>
    </div>

</div>
<script type="text/javascript" src="<?php echo WPS_IC_URI . 'assets/js/connect.js'; ?>"></script>