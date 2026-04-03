<div id="exclude-js-delay-v2" style="display: none;">
    <div id="" class="cdn-popup-inner ajax-settings-popup bottom-border exclude-list-popup">

        <div class="cdn-popup-loading">
            <div class="wpc-popup-saving-logo-container">
                <div class="wpc-popup-saving-preparing-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>
                    <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-popup-logo-saving-loader"/>
                </div>
            </div>
        </div>

        <div class="cdn-popup-content" style="display: none;">
            <div class="cdn-popup-top">
                <div class="inline-heading">
                    <div class="inline-heading-icon">
                        <img src="<?php echo WPS_IC_URI; ?>assets/images/icon-exclude-from-cdn.svg"/>
                    </div>
                    <div class="inline-heading-text">
                        <h3><?php echo esc_html__('Delay JavaScript', WPS_IC_TEXTDOMAIN); ?></h3>
                        <p><?php echo esc_html__('Control which scripts are delayed, deferred, or excluded from optimization.', WPS_IC_TEXTDOMAIN); ?></p>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="wpc-popup-tabs">
                <a href="#" class="wpc-popup-tab active" data-tab-target="js-delay-excludes">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512" fill="currentColor"><path d="M368 256l192-192-544 0 192 192 0 120 160 160 0-280zM320 420.1l-64-64 0-120-14.1-14.1-110.1-110.1 312.2 0-110.1 110.1-14.1 14.1 0 184z"/></svg>
                    <?php echo esc_html__('Excludes', WPS_IC_TEXTDOMAIN); ?>
                </a>
                <a href="#" class="wpc-popup-tab" data-tab-target="js-delay-configure">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M24 72l-24 0 0 48 112 0 0 56 160 0 0 56-272 0 0 48 272 0 0 56 160 0 0-56 80 0 0-48-80 0 0-56-160 0 0-56 240 0 0-48-240 0 0-56-160 0 0 56-88 0zM320 280l0-56 64 0 0 64-64 0 0-8zM160 120l0-56 64 0 0 64-64 0 0-8zM24 392l-24 0 0 48 80 0 0 56 160 0 0-56 272 0 0-48-272 0 0-56-160 0 0 56-56 0zm104 48l0-56 64 0 0 64-64 0 0-8z"/></svg>
                    <?php echo esc_html__('Configure', WPS_IC_TEXTDOMAIN); ?>
                </a>
            </div>

            <!-- Tab: Excludes -->
            <div class="wpc-popup-tab-content active" data-tab-id="js-delay-excludes">
                <form method="post" class="wpc-save-popup-data" action="#">
                    <div class="cdn-popup-content-full">
                        <div class="cdn-popup-content-inner">
                            <textarea name="wpc-excludes[delay_js_v2]" data-setting-name="wpc-excludes" data-setting-subset="delay_js_v2" class="exclude-list-textarea-value" placeholder="<?php echo esc_attr__('e.g. analytics.js, /my-plugin/tracking.js, google-tag', WPS_IC_TEXTDOMAIN); ?>"></textarea>

                            <div class="wps-empty-row">&nbsp;</div>

                        </div>
                    </div>
                    <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save"><?php echo esc_html__('Save', WPS_IC_TEXTDOMAIN); ?></a>
                <div class="wps-example-section">
                <button type="button" class="wps-example-toggle-btn"><?php echo esc_html__('See Examples', WPS_IC_TEXTDOMAIN); ?> <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>
                    <div class="wps-example-list" style="display: none;">
                        <div>
                            <div>
                                <p><span class="wpc-example-chip">jquery</span> <?php echo esc_html__('would exclude any script containing "jquery" in the URL', WPS_IC_TEXTDOMAIN); ?></p>
                                <p><span class="wpc-example-chip">/my-plugin/app.js</span> <?php echo esc_html__('would exclude that specific script', WPS_IC_TEXTDOMAIN); ?></p>
                                <p><span class="wpc-example-chip">/wp-content/plugins/my-plugin/</span> <?php echo esc_html__('would exclude all scripts from that plugin', WPS_IC_TEXTDOMAIN); ?></p>
                            </div>
                        </div>
                    </div>
                    </div>
                </form>
            </div>

            <!-- Tab: Configure -->
            <div class="wpc-popup-tab-content" data-tab-id="js-delay-configure" style="display:none;">
                <form method="post" class="wpc-save-popup-data" action="#">
                    <div class="cdn-popup-content-full">
                        <div class="cdn-popup-content-inner">
                            <h4><?php echo esc_html__('Scripts to Delay', WPS_IC_TEXTDOMAIN); ?></h4>
                            <textarea name="wpc-excludes[lastLoadScript]" data-setting-name="wpc-excludes" data-setting-subset="lastLoadScript" class="exclude-list-textarea-value" placeholder="<?php echo esc_attr__('e.g. chat-widget.js, fb-pixel.js', WPS_IC_TEXTDOMAIN); ?>"></textarea>

                            <div class="wps-empty-row">&nbsp;</div>

                            <h4><?php echo esc_html__('Scripts to Defer', WPS_IC_TEXTDOMAIN); ?></h4>
                            <textarea name="wpc-excludes[deferScript]" data-setting-name="wpc-excludes" data-setting-subset="deferScript" class="exclude-list-textarea-value-defer" placeholder="<?php echo esc_attr__('e.g. analytics.js, /plugins/slider/main.js', WPS_IC_TEXTDOMAIN); ?>"></textarea>

                        </div>
                    </div>
                    <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save"><?php echo esc_html__('Save', WPS_IC_TEXTDOMAIN); ?></a>
                    <div class="wps-example-section">
                <button type="button" class="wps-example-toggle-btn"><?php echo esc_html__('See Examples', WPS_IC_TEXTDOMAIN); ?> <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>
                    <div class="wps-example-list" style="display: none;">
                        <div>
                            <div>
                                <p><span class="wpc-example-chip">chat-widget</span> <?php echo esc_html__('would match any script containing that name', WPS_IC_TEXTDOMAIN); ?></p>
                                <p><span class="wpc-example-chip">/my-plugin/tracking.js</span> <?php echo esc_html__('would target that specific file', WPS_IC_TEXTDOMAIN); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                </form>
            </div>

        </div>

    </div>
</div>
