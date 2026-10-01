<div id="geo-location" style="display: none;">
  <div id="cdn-popup-inner" class="ajax-settings-popup bottom-border geo-location-popup">

    <div class="cdn-popup-top">
      <h3><?php echo esc_html__('Site Geo Location', WPS_IC_TEXTDOMAIN); ?></h3>
      <img class="popup-icon" src="<?php
      echo WPS_IC_URI; ?>assets/images/icon-geolocation-popup.svg"/>
    </div>

    <div class="cdn-popup-loading" style="display: none;">
      <div class="wps-ic-bulk-preparing-logo-container">
        <div class="wps-ic-bulk-preparing-logo">
          <img src="<?php
          echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
          <img src="<?php
          echo WPS_IC_URI; ?>assets/preparing.svg" class="bulk-preparing"/>
        </div>
      </div>
    </div>

    <div class="cdn-popup-content">
      <p class="wpc-dynamic-text"><?php printf(__('We have detected that your server is located in %s, if that\'s not correct, please select the nearest region below.', WPS_IC_TEXTDOMAIN), esc_html($geolocation_text)); ?></p>
      <form method="post" action="#">
        <select name="location-select">
          <?php
          $location_select = [
            'Automatic' => __('Automatic', WPS_IC_TEXTDOMAIN),
            'EU' => __('Europe', WPS_IC_TEXTDOMAIN),
            'US' => __('United States', WPS_IC_TEXTDOMAIN),
            'AS' => __('Asia', WPS_IC_TEXTDOMAIN),
            'OC' => __('Oceania', WPS_IC_TEXTDOMAIN),
          ];

          foreach ($location_select as $k => $v) {
            if ($k == $geolocation->continent) {
              ?>
              <option value="<?php
              echo $k; ?>" selected="selected"><?php
                echo esc_html($v); ?></option>
              <?php
            } else { ?>
              <option value="<?php
              echo $k; ?>"><?php
                echo esc_html($v); ?></option>
              <?php
            }
          }
          ?>
        </select>
        <div class="wps-empty-row">&nbsp;</div>
        <a href="#" class="btn btn-primary btn-active btn-save-location"><?php echo esc_html__('Save Location', WPS_IC_TEXTDOMAIN); ?></a>
      </form>
    </div>

    <div class="cdn-popup-bottom-border">&nbsp;</div>

  </div>
</div>