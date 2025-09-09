<?php

global $wps_ic, $wpdb;

if (empty($_GET['view'])) { ?>
  <div class="wrap">
  <div class="wps_ic_wrap wps_ic_settings_page wps_ic_live">

    <div class="wp-compress-header">
      <div class="wp-ic-logo-container">
        <div class="wp-compress-logo">
          <img src="<?php echo WPS_IC_URI; ?>assets/images/main-logo.svg"/>
          <div class="wp-ic-logo-inner">
            <span class="small"><?php echo $wps_ic::$version; ?></span>
          </div>
        </div>
      </div>
      <div class="wp-ic-header-buttons-container">

      </div>
      <div class="clearfix"></div>
    </div>

    <div class="wp-compress-pre-wrapper">
      <div class="wp-compress-pre-subheader">
        <div class="col-12">
          <h3>Bulk Compress</h3>
        </div>
      </div>

      <div class="wp-compress-bulk-state" style="display: none;">
        <p>We are compressing <span class="current">0</span> out of <span class="total">0</span> images.</p>
      </div>

      <div class="wp-compress-bulk-list" style="background: #fff;padding: 20px;text-align: center">
        <a href="#" class="button button-primary wps-ic-start-bulk-compress-v5">Start Bulk Compress</a>
      </div>

    </div>

  </div>

<?php }
