<div id="exclude-scripts-to-footer" style="display: none;">
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
          <h3><?php echo esc_html__('Exclude JavaScript from Moving to Footer', WPS_IC_TEXTDOMAIN); ?></h3>
          <p><?php echo __('List files or paths to exclude. Partial names work too — we match automatically.', WPS_IC_TEXTDOMAIN); ?></p>
        </div>
      </div>
    </div>

    <form method="post" class="wpc-save-popup-data" action="#">
      <div class="cdn-popup-content-full">
        <div class="cdn-popup-content-inner">
          <textarea name="wpc-excludes[defer_js]" data-setting-name="wpc-excludes" data-setting-subset="exclude-scripts-to-footer" class="exclude-list-textarea-value" placeholder="<?php echo esc_attr__('e.g. plugin-name/js/script.js, scripts.js, anyimage.jpg', WPS_IC_TEXTDOMAIN); ?>"></textarea>

            <div class="wps-default-excludes-container">
          <div class="wps-default-excludes-enabled-checkbox-container">
            <input type="checkbox" class="wps-default-excludes-enabled-checkbox">
            <p><?php echo esc_html__('Disable Default Excludes', WPS_IC_TEXTDOMAIN); ?></p>
          </div>
          </div>

          <div class="wps-empty-row">&nbsp;</div>

        </div>
      </div>
      <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save"><?php echo esc_html__('Save', WPS_IC_TEXTDOMAIN); ?></a>
                <div class="wps-example-section">
                <button type="button" class="wps-example-toggle-btn"><?php echo esc_html__('See Examples', WPS_IC_TEXTDOMAIN); ?> <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg></button>
                <div class="wps-example-list" style="display: none;">
        <div>
          <div>
            <p><span class="wpc-example-chip">.svg</span> <?php echo esc_html__('would exclude all assets with that extension', WPS_IC_TEXTDOMAIN); ?></p>
            <p><span class="wpc-example-chip">imagename</span> <?php echo esc_html__('would exclude any file with that name', WPS_IC_TEXTDOMAIN); ?></p>
            <p><span class="wpc-example-chip">/myplugin/image.jpg</span> <?php echo esc_html__('would exclude that specific file', WPS_IC_TEXTDOMAIN); ?></p>
            <p><span class="wpc-example-chip">/wp-content/myplugin/</span> <?php echo esc_html__('would exclude everything using that path', WPS_IC_TEXTDOMAIN); ?></p>
          </div>
        </div>
      </div>
                </div>
    </form>
    </div>

  </div>
</div>
