<form method="POST" action="#" class="wpc-ic-mu-default-settting-form">


  <div class="wpc-ic-mu-site-container ic-advanced-settings-v2">
    <div class="wpc-ic-mu-site-header">
      <div class="wpc-ic-mu-site-left-side">
        <div class="wpc-ic-mu-site-name">
          <span class="wpc-ic-mu-site-status-circle"></span>
          <h3><?php echo 'Bulk Connecting'; ?></h3>
        </div>
      </div>
      <div class="wpc-ic-mu-site-right-side">
      </div>
    </div>
    <div class="wpc-ic-mu-separator"></div>

    <div class="wp-compress-settings-row wps-ic-live-compress wpc-ic-mu-settings" style="margin-top:0px;">
      <div class="wps-ic-mu-bulk-connect-status" style="width: 100%;display: flex;">
        <div class="wps-ic-mu-bulk-saving" style="display: block;">
          <div class="wps-ic-mu-site-saving-logo">
            <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-mu-logo-prepare"/>
            <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-mu-preparing"/>
          </div>
        </div>
        <div class="wps-ic-mu-bulk-done" style="display: none;">
          <img src="<?php echo WPS_IC_URI; ?>assets/images/fireworks.svg" />
        </div>
        <div class="wps-ic-mu-bulk-connect-progress-bar">
          <div class="wps-ic-mu-bulk-connect-progress-bar-inner">
            <div class="wps-ic-mu-bulk-connect-progress-percent" style="width:0%;"></div>
          </div>
        </div>
        <h1>We are working on bulk connect.</h1>
        <h3>0 out of 0 sites done.</h3>
      </div>
    </div>


  </div>
</form>