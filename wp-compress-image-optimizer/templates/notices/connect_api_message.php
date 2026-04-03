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
            <h4><?php echo esc_html__('Faster load times are just a click away...', WPS_IC_TEXTDOMAIN); ?></h4>
          </div>
        </div>
        <div class="wp-compress-logo wp-ic-half">
          <div class="wp-ic-logo-inner text-right">
            <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>"
               class="button-get-started"><svg class="wpc-sparkle-icon" width="16" height="16" viewBox="0 0 640 512" fill="currentColor"><path d="M480 32l-72 32 72 32 32 72 32-72 72-32-72-32-32-72-32 72zM288 320c60.9-27.1 108.9-48.4 144-64-35.1-15.6-83.1-36.9-144-64-27.1-60.9-48.4-108.9-64-144-15.6 35.1-36.9 83.1-64 144-60.9 27.1-108.9 48.4-144 64 35.1 15.6 83.1 36.9 144 64 27.1 60.9 48.4 108.9 64 144 15.6-35.1 36.9-83.1 64-144zm-64 25.8c-15.5-34.9-24.7-55.7-27.6-62.2-6.5-2.9-27.2-12.1-62.2-27.6 34.9-15.5 55.7-24.7 62.2-27.6 2.9-6.5 12.1-27.2 27.6-62.2 15.5 34.9 24.7 55.7 27.6 62.2 6.5 2.9 27.2 12.1 62.2 27.6-34.9 15.5-55.7 24.7-62.2 27.6-2.9 6.5-12.1 27.2-27.6 62.2zM480 344l-32 72-72 32 72 32 32 72 32-72 72-32-72-32-32-72z"/></svg> <?php echo esc_html__('Get Started', WPS_IC_TEXTDOMAIN); ?></a>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>