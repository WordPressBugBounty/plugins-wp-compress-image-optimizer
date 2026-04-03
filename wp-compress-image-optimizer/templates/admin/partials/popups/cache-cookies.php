<div id="cache-cookies" style="display: none;">
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
                        <img src="<?php
                        echo WPS_IC_URI; ?>assets/images/icon-exclude-from-cdn.svg"/>
                    </div>
                    <div class="inline-heading-text">
                        <h3><?php echo esc_html__('Cache Cookies Settings', WPS_IC_TEXTDOMAIN); ?></h3>
                        <p><?php echo esc_html__('Define the cookies we should monitor.', WPS_IC_TEXTDOMAIN); ?></p>
                    </div>
                </div>
            </div>

            <form method="post" class="wpc-save-popup-data" action="#">
                <div class="cdn-popup-content-full">
                    <div class="cdn-popup-content-inner">

                        <div class="wpc-section-header-split">
                            <h4 class="wpc-section-header"><?php echo esc_html__('Cache cookies', WPS_IC_TEXTDOMAIN); ?></h4>
                            <h4 class="wpc-section-header"><?php echo esc_html__('Defaults', WPS_IC_TEXTDOMAIN); ?></h4>
                        </div>

                        <div class="wpc-hooks-container">
                            <div class="wpc-hooks-textarea-wrap">
                                <textarea name="wpc-cache-cookies" class="cache-cookies-textarea-value hooks-list-textarea-value" spellcheck="false"></textarea>
                            </div>
                            <div class="wpc-hooks-defaults-wrap">
                                <button type="button" class="wpc-copy-defaults-btn" title="<?php echo esc_attr__('Copy all defaults', WPS_IC_TEXTDOMAIN); ?>">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
                                    <span><?php echo esc_html__('Copy all', WPS_IC_TEXTDOMAIN); ?></span>
                                </button>
                                <div class="wpc-hooks-defaults-box">cookie_notice_accepted
allowed_cookies
consent_types
catAccCookies
aelia_cs_recalculate_cart_totals
aelia_cs_selected_currency
aelia_customer_country
aelia_customer_state
aelia_tax_exempt
wcml_client_currency
wcml_client_currency_language
wcml_client_country
geot_rocket_
pll_language</div>
                            </div>
                        </div>

                        <hr class="wpc-section-divider">

                        <div class="wpc-section-header-split">
                            <h4 class="wpc-section-header"><?php echo esc_html__('Exclude cookies', WPS_IC_TEXTDOMAIN); ?></h4>
                            <h4 class="wpc-section-header"><?php echo esc_html__('Defaults', WPS_IC_TEXTDOMAIN); ?></h4>
                        </div>

                        <div class="wpc-hooks-container">
                            <div class="wpc-hooks-textarea-wrap">
                                <textarea name="wpc-exclude-cookies" class="exclude-cookies-textarea-value hooks-list-textarea-value" spellcheck="false"></textarea>
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
                                <p><span class="wpc-example-chip">wp_compress</span> <?php echo esc_html__('would cache/exclude this particular cookie.', WPS_IC_TEXTDOMAIN); ?></p>
                                <p><span class="wpc-example-chip">wp_compress_</span> <?php echo esc_html__('is treated as a prefix and would cache/exclude all cookies with this prefix.', WPS_IC_TEXTDOMAIN); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

    </div>
</div>
