<div id="exclude-font-display" style="display: none;">
  <div id="" class="cdn-popup-inner ajax-settings-popup bottom-border exclude-list-popup">

    <div class="cdn-popup-loading" style="display: none;">
      <div class="wpc-popup-saving-logo-container">
        <div class="wpc-popup-saving-preparing-logo">
          <img src="<?php







 echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-popup-logo-saving"/>
          <div class="wpc-ic-popup-logo-saving-loader" aria-hidden="true"></div>
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
          <h3><?php echo esc_html__('Exclude from Font Display', WPS_IC_TEXTDOMAIN); ?></h3>
          <p><?php echo __('Excluded stylesheets keep their original font-display. Matched against the stylesheet URL and ID — partial names work too.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>
      </div>
    </div>

    <form method="post" class="wpc-save-popup-data" action="#">
      <div class="cdn-popup-content-full">
        <div class="cdn-popup-content-inner">
          <textarea name="wpc-excludes[font_display]" data-setting-name="wpc-excludes" data-setting-subset="font_display" class="exclude-list-textarea-value" placeholder="<?php echo esc_attr__('e.g. fontawesome, theme-style-css, /wp-content/themes/mytheme/style.css', WPS_IC_TEXTDOMAIN); ?>"></textarea>

          <div class="wps-empty-row">&nbsp;</div>

        </div>
      </div>
      <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save"><?php echo esc_html__('Save', WPS_IC_TEXTDOMAIN); ?></a>
                <div class="wps-example-section">
                <button type="button" class="wps-example-toggle-btn"><?php echo esc_html__('See Examples', WPS_IC_TEXTDOMAIN); ?> <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>
                <div class="wps-example-list" style="display: none;">
        <div>
          <div>
            <p><span class="wpc-example-chip">fontawesome</span> <?php echo esc_html__('would exclude any stylesheet with that in its URL or ID', WPS_IC_TEXTDOMAIN); ?></p>
            <p><span class="wpc-example-chip">theme-style-css</span> <?php echo esc_html__('would exclude the stylesheet with that ID', WPS_IC_TEXTDOMAIN); ?></p>
            <p><span class="wpc-example-chip">/wp-content/themes/mytheme/style.css</span> <?php echo esc_html__('would exclude that specific file', WPS_IC_TEXTDOMAIN); ?></p>
            <p><span class="wpc-example-chip">/wp-content/plugins/myplugin/</span> <?php echo esc_html__('would exclude everything using that path', WPS_IC_TEXTDOMAIN); ?></p>
          </div>
        </div>
      </div>
                </div>
    </form>
    </div>

  </div>
</div>
