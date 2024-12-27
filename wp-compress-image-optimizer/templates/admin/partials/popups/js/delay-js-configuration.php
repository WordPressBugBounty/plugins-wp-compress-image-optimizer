<div id="delay-js-configuration" style="display: none;">
    <div id="" class="cdn-popup-inner ajax-settings-popup bottom-border exclude-list-popup">

        <div class="cdn-popup-loading">
            <div class="wpc-popup-saving-logo-container">
                <div class="wpc-popup-saving-preparing-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>
                    <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-popup-logo-saving-loader"/>
                </div>
            </div>
        </div>

        <div class="cdn-popup-content"  style="display: none;">
            <div class="cdn-popup-top">
                <div class="inline-heading">
                    <div class="inline-heading-icon">
                        <img src="<?php
                        echo WPS_IC_URI; ?>assets/images/icon-exclude-from-cdn.svg"/>
                    </div>
                    <div class="inline-heading-text">
                        <h3>Configure JS Delay</h3>
                        <p>Add files or paths as desired as we use wildcard searching.</p>
                    </div>
                </div>
            </div>

            <form method="post" class="wpc-save-popup-data" action="#">
                <div class="cdn-popup-content-full">
                    <div class="cdn-popup-content-inner">
                        <h4>Delay last</h4>
                        <textarea name="wpc-excludes[lastLoadScript]" data-setting-name="wpc-excludes" data-setting-subset="lastLoadScript" class="exclude-list-textarea-value" placeholder="e.g. plugin-name/js/script.js, scripts.js"></textarea>

                        <div class="wps-empty-row">&nbsp;</div>

                        <h4>Defer</h4>
                        <textarea name="wpc-excludes[deferScript]" data-setting-name="wpc-excludes" data-setting-subset="deferScript" class="exclude-list-textarea-value-defer" placeholder="e.g. plugin-name/js/script.js, scripts.js"></textarea>

                    </div>
                </div>
                <div class="wps-example-list">
                    <div>
                        <h3>Examples:</h3>
                        <div>
                            <p>/myplugin/image.js would exclude that specific file</p>
                            <p>/wp-content/myplugin/ would exclude everything using that path</p>
                        </div>
                    </div>
                </div>
                <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save">Save</a>
            </form>
        </div>

    </div>
</div>
