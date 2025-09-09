<?php
global $wps_ic;

?>
<div class="wrap">
  <div class="wp-compress-notice-outer">
    <div class="wp-compress-notice wps-ic-notice-connect">

      <div class="wp-compress-header">
        <div class="wp-compress-logo wp-ic-half">
          <img src="<?php echo WPS_IC_URI; ?>assets/images/live/wp-compress-logo-white.svg"/>
          <div class="wp-compress-logo-subtitle">
            <h4>Faster load times are just a click away...</h4>
          </div>
        </div>
        <div class="wp-compress-logo wp-ic-half">
          <div class="wp-ic-logo-inner text-right">
            <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>"
               class="button-get-started" style="display:flex;justify-content:flex-end;width:120px;float:inline-end;"><i class='wpc-sparkle-icon'></i>Get Started</a>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>