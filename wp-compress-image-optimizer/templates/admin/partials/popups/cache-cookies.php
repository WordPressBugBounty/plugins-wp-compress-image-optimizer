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
                        <h3>Cache Cookies Settings</h3>
                        <p>Define the cookies we should monitor.</p>
                    </div>
                </div>
            </div>

            <form method="post" class="wpc-save-popup-data" action="#">
                <div class="cdn-popup-content-full">
                    <div class="cdn-popup-content-inner">

                        <div style="display:flex;padding-left:40px;padding-right:80px;justify-content: space-between;">

                            <h4 style="text-align: start;margin-bottom: 0;width:300px;">
                                Cache cookies
                            </h4>

                            <h4 style="text-align: end;margin-bottom: 0;width:90px;">
                                Defaults
                            </h4>

                        </div>

                        <div style="display:flex;padding-left:40px;padding-right:40px;justify-content: space-between;">

                            <textarea name="wpc-cache-cookies" class="cache-cookies-textarea-value" style="font-size:13px;line-height:1.5;padding-top:0px;"></textarea>

                            <div class="wps-example-list" style="display:flex;min-width:220px;">
                                <div>
                                    <div>
                                        <p> cookie_notice_accepted<br>
                                            allowed_cookies<br>
                                            consent_types<br>
                                            catAccCookies<br>
                                            aelia_cs_recalculate_cart_totals<br>
                                            aelia_cs_selected_currency<br>
                                            aelia_customer_country<br>
                                            aelia_customer_state<br>
                                            aelia_tax_exempt<br>
                                            wcml_client_currency<br>
                                            wcml_client_currency_language<br>
                                            wcml_client_country<br>
                                            geot_rocket_<br>
                                            pll_language<br></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="wps-empty-row">&nbsp;</div>

                        <div style="display:flex;padding-left:40px;padding-right:80px;justify-content: space-between;">

                            <h4 style="text-align: start;margin-bottom: 0;width:300px;">
                                Exclude cookies
                            </h4>

                            <h4 style="text-align: end;margin-bottom: 0;width:90px;">
                                Defaults
                            </h4>

                        </div>


                        <div style="display:flex;padding-left:40px;padding-right:40px;justify-content: space-between;">

                            <textarea name="wpc-exclude-cookies" class="exclude-cookies-textarea-value" style="font-size:13px;line-height:1.5;padding-top:0px;"></textarea>

                            <div class="wps-example-list" style="display:flex;min-width:220px;">
                                <div>
                                    <div>
                                        <p></p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="wps-empty-row">&nbsp;</div>

                        <div class="wps-example-list">
                            <div>
                                <h3>Examples:</h3>
                                <div>
                                    <p>wp_compress would cache/exclude this particular cookie.</p>
                                    <p>wp_compress_ is treated as a prefix and would cache/exclude all cookies with this prefix.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save">Save</a>
            </form>
        </div>

    </div>
</div>