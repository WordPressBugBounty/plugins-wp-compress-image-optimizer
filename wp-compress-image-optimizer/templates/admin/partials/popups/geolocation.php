<div id="geo-location" style="display: none;">
  <div id="cdn-popup-inner" class="ajax-settings-popup bottom-border geo-location-popup">
    
    <div class="cdn-popup-top">
      <h3>Site Geo Location</h3>
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
      <p class="wpc-dynamic-text">We have detected that your server is located in <?php
        echo $geolocation_text; ?>, if that's not correct, please select the nearest region below.</p>
      <form method="post" action="#">
        <select name="location-select">
          <?php
          $location_select = array('Automatic' => 'Automatic', 'EU' => 'Europe', 'US' => 'United States', 'AS' => 'Asia', 'OC' => 'Oceania');
          
          foreach ($location_select as $k => $v) {
            if ($k == $geolocation->continent) {
              ?>
              <option value="<?php
              echo $k; ?>" selected="selected"><?php
                echo $v; ?></option>
              <?php
            } else { ?>
              <option value="<?php
              echo $k; ?>"><?php
                echo $v; ?></option>
              <?php
            }
          }
          ?>
        </select>
        <div class="wps-empty-row">&nbsp;</div>
        <a href="#" class="btn btn-primary btn-active btn-save-location">Save Location</a>
      </form>
    </div>
    
    <div class="cdn-popup-bottom-border">&nbsp;</div>
  
  </div>
</div>