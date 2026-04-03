<?php

global $wps_ic;

if (!empty($_GET['reset'])) {
  delete_option('wps_ic_bulk_process');
}

$live_cdn = false;
if (!empty($wps_ic::$settings['live-cdn']) && $wps_ic::$settings['live-cdn'] == '1') {
  $live_cdn = true;
}
// Also check CF CDN setting
if (!$live_cdn) {
    $cfSettings = get_option(WPS_IC_CF);
    if (!empty($cfSettings['settings']['cdn']) && $cfSettings['settings']['cdn'] == '1') {
        $live_cdn = true;
    }
}

?>
<div class="wrap">
    <div class="wps_ic_wrap wps_ic_settings_page wps_ic_live">

        <div class="wp-compress-header">
            <div class="wp-ic-logo-container">
                <div class="wp-compress-logo">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/main-logo.svg"/>
                </div>
            </div>
            <div class="wp-ic-header-buttons-container">
                <ul>
                    <li>
                        <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug); ?>" class="wpc-btn-return"><?php esc_html_e('Return to Dashboard', WPS_IC_TEXTDOMAIN); ?></a>
                    </li>
                </ul>
            </div>
            <div class="clearfix"></div>
        </div>


        <div class="wp-compress-pre-wrapper-no-shadow">
            <a href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=preload&start=true&hash=' . time()); ?>" class="wps-ic-start-preload"><?php esc_html_e('Start Preload', WPS_IC_TEXTDOMAIN); ?></a>
        </div>
    </div>
</div>