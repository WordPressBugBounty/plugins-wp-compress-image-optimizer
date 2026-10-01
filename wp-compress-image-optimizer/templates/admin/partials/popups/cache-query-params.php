<div id="cache-query-params" style="display: none;">
    <div id="" class="cdn-popup-inner ajax-settings-popup bottom-border exclude-list-popup">

        <div class="cdn-popup-loading">
            <div class="wpc-popup-saving-logo-container">
                <div class="wpc-popup-saving-preparing-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>
                    <div class="wpc-ic-popup-logo-saving-loader" aria-hidden="true"></div>
                </div>
            </div>
        </div>

        <div class="cdn-popup-content" style="display: none;">
            <div class="cdn-popup-top">
                <div class="inline-heading">
                    <div class="inline-heading-icon">
                        <img src="<?php
                        echo WPS_IC_URI; ?>assets/images/icon-exclude-from-cdn.svg"/>
                    </div>
                    <div class="inline-heading-text">
                        <h3><?php echo esc_html__('Cache Query Parameter Settings', WPS_IC_TEXTDOMAIN); ?></h3>
                        <p><?php echo esc_html__('Define the query parameters we should monitor.', WPS_IC_TEXTDOMAIN); ?></p>
                    </div>
                </div>
            </div>

            <form method="post" class="wpc-save-popup-data" action="#">
                <div class="cdn-popup-content-full">
                    <div class="cdn-popup-content-inner">

                        <div class="wpc-section-header-split">
                            <h4 class="wpc-section-header"><?php echo esc_html__('Cache by parameter', WPS_IC_TEXTDOMAIN); ?></h4>
                            <h4 class="wpc-section-header"><?php echo esc_html__('Defaults', WPS_IC_TEXTDOMAIN); ?></h4>
                        </div>

                        <div class="wpc-hooks-container">
                            <div class="wpc-hooks-textarea-wrap">
                                <textarea name="wpc-cache-query-params" class="cache-query-params-textarea-value hooks-list-textarea-value" spellcheck="false"></textarea>
                            </div>
                            <div class="wpc-hooks-defaults-wrap">
                                <div class="wpc-hooks-defaults-box"></div>
                            </div>
                        </div>

                        <hr class="wpc-section-divider">

                        <div class="wpc-section-header-split">
                            <h4 class="wpc-section-header"><?php echo esc_html__('Ignore parameter', WPS_IC_TEXTDOMAIN); ?></h4>
                            <h4 class="wpc-section-header"><?php echo esc_html__('Defaults', WPS_IC_TEXTDOMAIN); ?></h4>
                        </div>

                        <div class="wpc-hooks-container">
                            <div class="wpc-hooks-textarea-wrap">
                                <textarea name="wpc-ignore-query-params" class="ignore-query-params-textarea-value hooks-list-textarea-value" spellcheck="false"></textarea>
                            </div>
                            <div class="wpc-hooks-defaults-wrap">
                                <div class="wpc-hooks-defaults-box"></div>
                            </div>
                        </div>

                    </div>
                </div>

                <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save"><?php echo esc_html__('Save', WPS_IC_TEXTDOMAIN); ?></a>
                <div class="wps-example-section">
                    <button type="button" class="wps-example-toggle-btn"><?php echo esc_html__('See Examples', WPS_IC_TEXTDOMAIN); ?> <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>
                    <div class="wps-example-list" style="display: none;">
                        <div>
                            <div>
                                <p><span class="wpc-example-chip">currency</span> <?php echo esc_html__('under "Cache by parameter" stores one cached page per value, so ?currency=usd and ?currency=eur are served separately.', WPS_IC_TEXTDOMAIN); ?></p>
                                <p><span class="wpc-example-chip">ref</span> <?php echo esc_html__('under "Ignore parameter" is dropped from the cache key, so every value is served the same cached page.', WPS_IC_TEXTDOMAIN); ?></p>
                                <p><?php echo esc_html__('A parameter on neither list keeps today\'s behaviour: the page is served live and never cached.', WPS_IC_TEXTDOMAIN); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

    </div>
</div>
