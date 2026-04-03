<?php
if ($option = 'exclude-url-from-all'){
	$current_option = 'exclude-url-from-all';
} else {
	$current_option = $option[1];
}
?>
<div id="<?php echo $configure; ?>" style="display: none;">
    <div id="" class="cdn-popup-inner ajax-settings-popup bottom-border exclude-list-popup">

        <div class="cdn-popup-loading" style="display: none;">
            <div class="wpc-popup-saving-logo-container">
                <div class="wpc-popup-saving-preparing-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>
                    <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-popup-logo-saving-loader"/>
                </div>
            </div>
        </div>

        <div class="cdn-popup-content">
            <div class="cdn-popup-top">
                <div class="inline-heading">
                    <div class="inline-heading-icon">
                        <img src="<?php
                        echo WPS_IC_URI; ?>assets/images/icon-exclude-from-cdn.svg"/>
                    </div>
                    <div class="inline-heading-text">
                        <h3><?php echo $title; ?></h3>
                        <p><?php echo esc_html__('Add excluded URLs', WPS_IC_TEXTDOMAIN); ?></p>
                    </div>
                </div>
            </div>

            <?php
            if ($configure == 'exclude-url-from-all'){
                //If I don't do this, then there is no form below... I don't know...
                echo '<form></form>';
            }
            ?>

            <form method="post" class="wpc-save-popup-data" action="#">
                <div class="cdn-popup-content-full">
                    <div class="cdn-popup-content-inner">
                        <textarea name="wpc-url-excludes" data-setting-name="wpc-url-excludes"
                                  data-setting-subset="<?php echo $current_option; ?>"  class="exclude-list-textarea-value"
                                  placeholder="<?php echo esc_attr__('e.g. www.example.com example.com example.com/page1', WPS_IC_TEXTDOMAIN); ?>"></textarea>

                        <div class="wps-empty-row">&nbsp;</div>

                    </div>
                </div>
                <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save"><?php echo esc_html__('Save', WPS_IC_TEXTDOMAIN); ?></a>
                <div class="wps-example-section">
                <button type="button" class="wps-example-toggle-btn"><?php echo esc_html__('See Examples', WPS_IC_TEXTDOMAIN); ?> <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>
                <div class="wps-example-list" style="display: none;">
                    <div>
                        <div>
                            <p><?php echo esc_html__('www.siteurl.com/page to exclude just the page', WPS_IC_TEXTDOMAIN); ?></p>
                            <p><?php echo esc_html__('www.siteurl.com/page/subpage to exclude just the subpage', WPS_IC_TEXTDOMAIN); ?></p>
                        </div>
                    </div>
                </div>
                </div>
            </form>
        </div>

    </div>
</div>