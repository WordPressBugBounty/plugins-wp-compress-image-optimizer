<div id="exclude-from-plugin-popup" style="display: none;">
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
          <h3><?php echo esc_html__('Exclude from Plugin', WPS_IC_TEXTDOMAIN); ?></h3>
          <p><?php echo esc_html__('URLs listed here completely bypass ALL optimizations. The plugin will not touch these pages — useful for checkout, account pages, or anything that may cause issues with optimization.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>
      </div>
    </div>

    <form method="post" class="wpc-save-popup-data" action="#">
      <div class="cdn-popup-content-full">
        <div class="cdn-popup-content-inner">
          <textarea name="wpc-url-excludes[exclude-url-from-all]" data-setting-name="wpc-url-excludes" data-setting-subset="exclude-url-from-all" class="exclude-list-textarea-value" placeholder="<?php echo esc_attr__('e.g. /checkout, /my-account, /offer/di-premium', WPS_IC_TEXTDOMAIN); ?>"></textarea>

          <div class="wps-empty-row">&nbsp;</div>

        </div>
      </div>
      <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save"><?php echo esc_html__('Save', WPS_IC_TEXTDOMAIN); ?></a>
                <div class="wps-example-section">
                <button type="button" class="wps-example-toggle-btn"><?php echo esc_html__('See Examples', WPS_IC_TEXTDOMAIN); ?> <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>
                <div class="wps-example-list" style="display: none;">
        <div>
            <div>
                <p><span class="wpc-example-chip">/checkout</span> <?php echo esc_html__('would bypass plugin on checkout and all sub-pages', WPS_IC_TEXTDOMAIN); ?></p>
                <p><span class="wpc-example-chip">/my-account</span> <?php echo esc_html__('would bypass plugin on account pages and all sub-pages', WPS_IC_TEXTDOMAIN); ?></p>
                <p><span class="wpc-example-chip">/offer/di-premium</span> <?php echo esc_html__('would bypass plugin on that offer page and any sub-pages', WPS_IC_TEXTDOMAIN); ?></p>
                <p><span class="wpc-example-chip">cart</span> <?php echo __('would bypass plugin on any URL containing &quot;cart&quot;', WPS_IC_TEXTDOMAIN); ?></p>
            </div>
        </div>
      </div>
                </div>
    </form>
    </div>

  </div>
</div>
