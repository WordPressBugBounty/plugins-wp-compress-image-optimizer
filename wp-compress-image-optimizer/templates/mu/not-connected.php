<?php
global $wpc_siteID;
// Change Active Blog
switch_to_blog($wpc_siteID);
$current_blog_details = get_blog_details(array('blog_id' => $wpc_siteID));

/**
 * GeoLocation Stuff
 */
$geolocation = get_option('wps_ic_geo_locate');
if (empty($geolocation)) {
	$geolocation = $this->geoLocate();
}
else {
	$geolocation = (object)$geolocation;
}

$geolocation_text = $geolocation->country_name . ' (' . $geolocation->continent_name . ')';

/**
 * Fetch settings, or if save is triggered save them.
 * - If no settings are saved (bug, deleted options..) regenerate recommended
 */
$settings = get_option(WPS_IC_SETTINGS);

/**
 * Quick fix for PHP undefined notices
 */
$wps_ic_active_settings['optimization']['lossless']    = '';
$wps_ic_active_settings['optimization']['intelligent'] = '';
$wps_ic_active_settings['optimization']['ultra']       = '';

/**
 * Decides which setting is active
 */
if ( ! empty($settings['optimization'])) {
	if ($settings['optimization'] == 'lossless') {
		$wps_ic_active_settings['optimization']['lossless'] = 'class="current"';
	}
	else if ($settings['optimization'] == 'intelligent') {
		$wps_ic_active_settings['optimization']['intelligent'] = 'class="current"';
	}
	else {
		$wps_ic_active_settings['optimization']['ultra'] = 'class="current"';
	}
}
else {
	$wps_ic_active_settings['optimization']['intelligent'] = 'class="current"';
}
?>
<script type="text/javascript">
    var wpc_siteID = "<?php echo $wpc_siteID; ?>";
</script>
<form method="POST" action="#" class="wpc-ic-mu-settting-form">

  <input type="hidden" name="siteID" value="<?php echo $wpc_siteID; ?>"/>

  <div class="wpc-ic-mu-site-container ic-advanced-settings-v2">
    <div class="wpc-ic-mu-site-header">
      <div class="wpc-ic-mu-site-left-side">
        <div class="wpc-ic-mu-site-name">
          <span class="wpc-ic-mu-site-status-circle"></span>
          <h3><?php echo $current_blog_details->blogname; ?></h3>
          <h5><?php echo $current_blog_details->siteurl; ?></h5>
        </div>
        <div class="wpc-ic-mu-site-action">
        </div>
      </div>
      <div class="wpc-ic-mu-site-right-side">
        <a href="#" class="wpc-mu-individual-connect" data-site-id="<?php echo $wpc_siteID; ?>">Connect</a>
      </div>
    </div>
    <div class="wpc-ic-mu-separator"></div>
    <div class="wp-compress-settings-row wps-ic-live-compress wpc-ic-mu-settings wps-ic-mu-single-site-not-connected" style="margin-top:0px;text-align: center;display:block;padding:50px;">
      <h3 style="font-size:30px;color:#6d7072;">This site is not yet connected....</h3>
      <div><img src="<?php echo WPS_IC_URI; ?>assets/mu/images/not-connected-v2.svg" style="padding: 0;margin: 0 auto;width: 100%;"/></div>
    </div>
    <div class="wp-compress-settings-row wps-ic-live-compress wpc-ic-mu-settings wps-ic-mu-single-site-connecting-loading" style="margin-top:0px;text-align: center;display:none;padding:50px;">
      <div class="wps-ic-mu-site-connecting-saving" style="position:relative;">
        <div class="wps-ic-mu-site-saving-logo">
          <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="wpc-ic-mu-logo-prepare"/>
          <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="wpc-ic-mu-preparing"/>
        </div>
      </div>
    </div>
  </div>


</form>