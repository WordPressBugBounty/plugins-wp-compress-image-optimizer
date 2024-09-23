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
        <div style="display: inline-block;vertical-align: middle;margin-left:20px;">
          <div class="checkbox-container-v3-inverse wps-ic-ajax-checkbox" style="display: inline-block;">
            <div class="checkbox-label-left">Local</div>
            <input type="checkbox" id="live-cdn-toggle" value="live" name="wp-ic-setting[live-cdn]" data-setting_name="live-cdn" data-setting_value="live" <?php echo checked($settings['live-cdn'], 'live'); ?>/>
            <div>
              <label for="live-cdn-toggle" class="live-cdn-toggle label-live-cdn-toggle"></label>
            </div>
            <div class="checkbox-label-right">Live</div>
          </div>
        </div>
        <div class="wpc-ic-mu-site-action">
        </div>
      </div>
      <div class="wpc-ic-mu-site-right-side">
        <a href="#" class="wpc-mu-individual-disconnect" data-site-id="<?php echo $wpc_siteID; ?>">Disconnect</a>
        <input type="submit" name="Save" value="Save" class="wpc-mu-save-settings"/>
      </div>
    </div>
    <div class="wpc-ic-mu-separator"></div>

    <div class="wp-compress-settings-row wps-ic-live-compress wpc-ic-mu-settings" style="margin-top:0px;">
      <div class="text-center" style="width: 100%;display: flex;">
        <div class="inner optimization-level">
          <h3>Optimization Level</h3>
          <span class="ic-tooltip" title="Select your preferred balance of speed and quality. Over-Compression Prevention™ is included in all modes."><i class="fa fa-question-circle"></i></span>

          <div style="" class="wp-ic-select-box">
            <input type="hidden" name="wp-ic-setting[optimization]" id="wp-ic-setting-optimization" value="<?php echo $settings['optimization']; ?>"/>
            <ul>
              <li <?php echo $wps_ic_active_settings['optimization']['lossless']; ?>><a href="#" class="wps-ic-change-optimization" data-optimization_level="lossless">Lossless</a></li>
              <li <?php echo $wps_ic_active_settings['optimization']['intelligent']; ?>><a href="#" class="wps-ic-change-optimization" data-optimization_level="intelligent">Intelligent</a></li>
              <li <?php echo $wps_ic_active_settings['optimization']['ultra']; ?>><a href="#" class="wps-ic-change-optimization" data-optimization_level="ultra">Ultra</a></li>
            </ul>
          </div>
        </div>
        <div class="inner">
          <h3>Retina</h3>

          <div class="checkbox-container-v2 informative-input whole-checkbox ic-tooltip" title="Intelligently show retina enabled images based on device, which can improve the image quality on retina enabled devices.">
            <input type="checkbox" id="retina-toggle" value="1" name="wp-ic-setting[retina]" data-setting_name="retina" data-setting_value="1" <?php echo checked($settings['retina'], '1'); ?>/>
            <div>
              <label for="retina-toggle" class="retina-toggle"></label>
							<?php if ($settings['retina'] == '1') { ?>
                <span>ON</span>
							<?php } else { ?>
                <span>OFF</span>
							<?php } ?>
            </div>
          </div>

        </div>

        <div class="inner">
          <h3>Adaptive</h3>

          <div class="checkbox-container-v2 informative-input ic-tooltip" title="Intelligently show images based on device size, which can DRASTICALLY reduce load times on mobile devices.">
            <input type="checkbox" id="adaptive-images-toggle" value="1" name="wp-ic-setting[generate_adaptive]" data-setting_name="generate_adaptive" data-setting_value="1" <?php echo checked($settings['generate_adaptive'], '1'); ?>/>
            <div>
              <label for="adaptive-images-toggle" class="adaptive-toggle"></label>
							<?php if ($settings['generate_adaptive'] == '1') { ?>
                <span>ON</span>
							<?php } else { ?>
                <span>OFF</span>
							<?php } ?>
            </div>
          </div>

        </div>

        <div class="inner">
          <h3>WebP</h3>

          <div class="checkbox-container-v2 informative-input ic-tooltip" title="Serve next-gen image format WebP to supported browsers for additional file-size savings.">
            <input type="checkbox" id="generate-webp-toggle" value="1"
                   name="wp-ic-setting[generate_webp]" data-setting_name="generate_webp" data-setting_value="1" <?php echo checked($settings['generate_webp'], '1'); ?>/>
            <div>
              <label for="generate-webp-toggle" class="webp-toggle"></label>
							<?php if ($settings['generate_webp'] == '1') { ?>
                <span>ON</span>
							<?php } else { ?>
                <span>OFF</span>
							<?php } ?>
            </div>
          </div>
        </div>

        <div class="inner">
          <h3>Lazy Load</h3>

          <div class="checkbox-container-v2 informative-input ic-tooltip" title="Load your images only when they are in viewport." style="display: block;">
            <input type="checkbox" id="lazy-toggle" value="1" name="wp-ic-setting[lazy]" data-setting_name="lazy" data-setting_value="1" <?php echo checked($settings['lazy'], '1'); ?>/>
            <div>
              <label for="lazy-toggle" class="lazy-toggle"></label>
							<?php if ($settings['lazy'] == '1') { ?>
                <span>ON</span>
							<?php } else { ?>
                <span>OFF</span>
							<?php } ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="wpc-ic-mu-settings">
      <div class="settings-container-flex-outer" style="margin-bottom: 25px">

        <div class="settings-container-half settings-container-left first">
          <div class="inner">
            <div class="setting-header">
              <div class="settings-header-title" style="flex:1;">
                CDN Delivery Options
                <span class="ic-tooltip" title="Allows your website to minify and serve JavaScript files via the CDN - be sure not to duplicate JavaScript settings with other solutions, however all toggles are temporary and can be toggled off at anytime to revert changes."><i class="fa fa-question-circle"></i></span>
              </div>
            </div>
            <div style="margin-left:15px;display:flex;width:100%;">
              <div class="setting-body-half settings-area-serve-cdn-images">
                <div class="setting-inner-header">
                  <h3>Serve Images</h3>
                </div>
								<?php
								$settings = get_option(WPS_IC_SETTINGS);
								if (empty($settings['live-cdn']) || $settings['live-cdn'] == '0') {
									?>
                  <div class="setting-option wpc-checkbox">
                    <input type="checkbox" id="serve_jpg" value="1" name="wp-ic-setting[serve][jpg]" data-setting_name="serve_jpg" data-setting_value="1" class="disabled-checkbox ic-tooltip" title="Switch to live optimization to serve images." />
                    <label for="serve_jpg">JPG/JPEG</label>
                  </div>
                  <div class="setting-option wpc-checkbox">
                    <input type="checkbox" id="serve_png" value="1" name="wp-ic-setting[serve][png]" data-setting_name="serve_png" data-setting_value="1" class="disabled-checkbox ic-tooltip" title="Switch to live optimization to serve images." />
                    <label for="serve_png">PNG</label>
                  </div>
                  <div class="setting-option wpc-checkbox">
                    <input type="checkbox" id="serve_gif" value="1" name="wp-ic-setting[serve][gif]" data-setting_name="serve_gif" data-setting_value="1" class="disabled-checkbox ic-tooltip" title="Switch to live optimization to serve images." />
                    <label for="serve_gif">GIF</label>
                  </div>
                  <div class="setting-option wpc-checkbox">
                    <input type="checkbox" id="serve_svg" value="1" name="wp-ic-setting[serve][svg]" data-setting_name="serve_svg" data-setting_value="1" class="disabled-checkbox ic-tooltip" title="Switch to live optimization to serve images." />
                    <label for="serve_svg">SVG</label>
                  </div>
								<?php } else { ?>
                  <div class="setting-option wpc-checkbox">
                    <input type="checkbox" id="serve_jpg" value="1" name="wp-ic-setting[serve][jpg]" data-setting_name="serve_jpg" data-setting_value="1" <?php echo checked($settings['serve']['jpg'], '1'); ?>/>
                    <label for="serve_jpg">JPG/JPEG</label>
                  </div>
                  <div class="setting-option wpc-checkbox">
                    <input type="checkbox" id="serve_png" value="1" name="wp-ic-setting[serve][png]" data-setting_name="serve_png" data-setting_value="1" <?php echo checked($settings['serve']['png'], '1'); ?>/>
                    <label for="serve_png">PNG</label>
                  </div>
                  <div class="setting-option wpc-checkbox">
                    <input type="checkbox" id="serve_gif" value="1" name="wp-ic-setting[serve][gif]" data-setting_name="serve_gif" data-setting_value="1" <?php echo checked($settings['serve']['gif'], '1'); ?>/>
                    <label for="serve_gif">GIF</label>
                  </div>
                  <div class="setting-option wpc-checkbox">
                    <input type="checkbox" id="serve_svg" value="1" name="wp-ic-setting[serve][svg]" data-setting_name="serve_svg" data-setting_value="1" <?php echo checked($settings['serve']['svg'], '1'); ?>/>
                    <label for="serve_svg">SVG</label>
                  </div>
								<?php } ?>
              </div>
              <div class="setting-body-half settings-area-serve-cdn">
                <div class="setting-inner-header">
                  <h3>Serve Assets</h3>
                </div>
                <div class="setting-option wpc-checkbox">
                  <input type="checkbox" id="js-toggle" value="1" name="wp-ic-setting[js]" data-setting_name="js" data-setting_value="1" <?php echo checked($settings['js'], '1'); ?>/>
                  <label for="js-toggle">JavaScript via CDN</label>
                </div>
                <div class="setting-option wpc-checkbox">
                  <input type="checkbox" id="css-toggle" value="1" name="wp-ic-setting[css]" data-setting_name="css" data-setting_value="1" <?php echo checked($settings['css'], '1'); ?>/>
                  <label for="css-toggle">CSS via CDN</label>
                </div>
                <div class="setting-option wpc-checkbox">
									<?php
									$zone_name = get_option('ic_custom_cname');
									if ( ! empty($zone_name)) {
										?>
                    <input type="checkbox" id="fonts-enabled" value="1" name="wp-ic-setting[fonts]" data-setting_name="fonts" data-setting_value="1" <?php echo checked($settings['fonts'], '1'); ?> class="cname-enabled"/>
                    <label for="fonts-enabled" class="label-enabled">Fonts</label>

                    <input type="checkbox" id="fonts" value="1" name="wp-ic-setting[fonts]" data-setting_name="fonts" data-setting_value="1" <?php echo checked($settings['fonts'], '1'); ?> class="disabled-checkbox ic-tooltip cname-disabled" title="To enable fonts option you need to setup your custom CDN." style="display: none;"/>
                    <label for="fonts" class="label-disabled ic-tooltip" title="To be able to serve fonts, you’ll first need to set up a custom CDN domain matching your root domain." style="display:none;">Fonts</label>
									<?php } else { ?>
                    <input type="checkbox" id="fonts-enabled" value="1" name="wp-ic-setting[fonts]" data-setting_name="fonts" data-setting_value="1" <?php echo checked($settings['fonts'], '1'); ?> style="display: none;" class="cname-enabled"/>
                    <label for="fonts-enabled" class="label-enabled" style="display:none;">Fonts</label>

                    <input type="checkbox" id="fonts" value="1" name="wp-ic-setting[fonts]" data-setting_name="fonts" data-setting_value="1" <?php echo checked($settings['fonts'], '1'); ?> class="disabled-checkbox ic-tooltip cname-disabled"
                           title="To be able to serve fonts, you’ll first need to set up a custom CDN domain matching your root domain."/>
                    <label for="fonts" class="label-disabled">Fonts</label>
									<?php } ?>
                </div>
              </div>
            </div>
            <div style="margin-left:15px;width:100%;">
              <div class="setting-body settings-area-search-through">
                <div class="setting-inner-header">
                  <h3>Search Through</h3>
                </div>
                <div class="setting-option wpc-checkbox" style="margin-top:20px;">
                  <div class="wp-ic-select-box full-width-box">
                    <input type="hidden" name="wp-ic-setting[search-through]" id="wp-ic-search-through" value="<?php echo $settings['search-through']; ?>">
                    <ul>
											<?php
											$options = array('html' => 'HTML Only', 'html+css' => 'HTML + CSS', 'all' => 'All URLs');
											foreach ($options as $key => $value) {
												if ($key == $settings['search-through']) {
													echo '<li class="current"><a href="#" class="wps-ic-search-through" data-value="' . $key . '">' . $value . '</a></li>';
												}
												else {
													echo '<li><a href="#" class="wps-ic-search-through" data-value="' . $key . '">' . $value . '</a></li>';
												}
											}
											?>
                    </ul>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="settings-container-half settings-container-left">
          <div class="inner">
            <div class="setting-header">
              <div class="settings-header-title">
                Additional Settings
              </div>
              <div class="settings-header-actions">
              </div>
            </div>
            <div class="setting-body settings-area-additional-settings">

              <div class="setting-option">
                <div class="setting-label">Preserve EXIF Data</div>
                <div class="setting-value ic-custom-tooltip" title="Keep the image metadata, typically for photographers or SEO.">
                  <div class="checkbox-container-v3 wps-ic-ajax-checkbox" style="display: inline-block;">
                    <input type="checkbox" id="preserve_exif-toggle" value="1" name="wp-ic-setting[preserve-exif]" data-setting_name="preserve-exif" data-setting_value="1" <?php echo checked($settings['preserve-exif'], '1'); ?>/>
                    <div>
                      <label for="preserve_exif-toggle" class="preserve_exif-toggle"></label>
                    </div>
                  </div>
                </div>
              </div>

              <div class="setting-option">
                <div class="setting-label">Optimize on Upload</div>
                <div class="setting-value ic-custom-tooltip" title="Compress future image uploads with local mode settings.">
                  <div class="checkbox-container-v3 wps-ic-ajax-checkbox" style="display: inline-block;">
                    <input type="checkbox" id="on-upload-toggle" value="1" name="wp-ic-setting[on-upload]" data-setting_name="on-upload" data-setting_value="1" <?php echo checked($settings['on-upload'], '1'); ?>/>
                    <div>
                      <label for="on-upload-toggle" class="on-upload-toggle"></label>
                    </div>
                  </div>
                </div>
              </div>

              <div class="setting-option">
                <div class="setting-label">Disable WP Emoji Script</div>
                <div class="setting-value ic-custom-tooltip" title="You may remove the script if no emojis are used on your site.">
                  <div class="checkbox-container-v3 wps-ic-ajax-checkbox" style="display: inline-block;">
                    <input type="checkbox" id="emoji-remove-toggle" value="1" name="wp-ic-setting[emoji-remove]" data-setting_name="emoji-remove" data-setting_value="1" <?php echo checked($settings['emoji-remove'], '1'); ?>/>
                    <div>
                      <label for="emoji-remove-toggle" class="emoji-remove-toggle"></label>
                    </div>
                  </div>
                </div>
              </div>

              <div class="setting-option">
                <div class="setting-label">Minify CSS</div>
                <div class="setting-value ic-custom-tooltip" title="Compress CSS files and remove whitespace to reduce file-size.">
                  <div class="checkbox-container-v3 wps-ic-ajax-checkbox" style="display: inline-block;">
                    <input type="checkbox" id="minify-css-toggle" value="1" name="wp-ic-setting[minify-css]" data-setting_name="minify-css" data-setting_value="1" <?php echo checked($settings['minify-css'], '1'); ?>/>
                    <div>
                      <label for="minify-css-toggle" class="minify-css-toggle"></label>
                    </div>
                  </div>
                </div>
              </div>

              <div class="setting-option">
                <div class="setting-label">Minify JavaScript</div>
                <div class="setting-value ic-custom-tooltip" title="Compress JavaScript files and remove whitespace to reduce file-size.">
                  <div class="checkbox-container-v3 wps-ic-ajax-checkbox" style="display: inline-block;">
                    <input type="checkbox" id="minify-js-toggle" value="1" name="wp-ic-setting[minify-js]" data-setting_name="minify-js" data-setting_value="1" <?php echo checked($settings['minify-js'], '1'); ?>/>
                    <div>
                      <label for="minify-js-toggle" class="minify-js-toggle"></label>
                    </div>
                  </div>
                </div>
              </div>

              <div class="setting-option">
                <div class="setting-label">Defer JavaScript</div>
                <div class="setting-value ic-custom-tooltip" title="Delay the load priority of specified JavaScript files.">
                  <div class="checkbox-container-v3 wps-ic-ajax-checkbox" style="display: inline-block;">
                    <input type="checkbox" id="defer-js-toggle" value="1" name="wp-ic-setting[defer-js]" data-setting_name="defer-js" data-setting_value="1" <?php echo checked($settings['defer-js'], '1'); ?>/>
                    <div>
                      <label for="defer-js-toggle" class="defer-js-toggle"></label>
                    </div>
                  </div>
                </div>
              </div>

              <div class="setting-option">
                <div class="setting-label">External URLs</div>
                <div class="setting-value ic-custom-tooltip" title="Allows third party URLs to be live optimized for s3 offloading etc.">
                  <div class="checkbox-container-v3 wps-ic-ajax-checkbox" style="display: inline-block;">
                    <input type="checkbox" id="external-url-toggle" value="1" name="wp-ic-setting[external-url]" data-setting_name="external-urls" data-setting_value="1" <?php echo checked($settings['external-url'], '1'); ?>/>
                    <div>
                      <label for="external-url-toggle" class="external-url-toggle"></label>
                    </div>
                  </div>
                </div>
              </div>

              <div class="setting-option">
                <div class="setting-label">Remove Render Blocking</div>
                <div class="setting-value ic-custom-tooltip" title="Remove render blocking on crucial assets.">
                  <div class="checkbox-container-v3 wps-ic-ajax-checkbox" style="display: inline-block;">
                    <input type="checkbox" id="render-blocking-toggle" value="1" name="wp-ic-setting[remove-render-blocking]" data-setting_name="remove-render-blocking" data-setting_value="1" <?php echo checked($settings['remove-render-blocking'], '1'); ?>/>
                    <div>
                      <label for="render-blocking-toggle" class="render-blocking-toggle"></label>
                    </div>
                  </div>
                </div>
              </div>


            </div>
          </div>
        </div>

      </div>
    </div>

    <div class="wpc-ic-mu-settings">
      <div class="settings-container-flex-outer">
        <div class="settings-container-third" style="margin-bottom:25px;margin-right:10px;">
          <div class="inner">
            <div class="setting-group setting-group-center">
              <div class="setting-icon ic-tooltip" title="If you know your site location please select it from the option list below.">
                <img src="<?php echo WPS_IC_URI; ?>assets/images/icon-geolocation.svg"/>
              </div>
              <div class="setting-header" style="line-height: 30px;">
                <strong>Site GEO Location</strong>
              </div>

              <div class="setting-value">
                <p>We've detected your server is in <strong><?php echo $geolocation_text; ?></strong>.</p>
              </div>

              <a href="#" class="wps-ic-configure-popup" data-popup-width="600" data-popup="geo-location"><i class="svg-icon icon-configure"></i> Configure</a>

            </div>
          </div>
        </div>

        <div class="settings-container-third" style="margin-bottom:25px;margin-left:10px;margin-right:10px;">
          <div class="inner">
            <div class="setting-group setting-group-center">
              <div class="setting-icon ic-tooltip" title="If you know your site location please select it from the option list below.">
                <img src="<?php echo WPS_IC_URI; ?>assets/images/icon-cdn-custom.svg"/>
              </div>
              <div class="setting-header" style="line-height: 30px;">
                <strong>Exclude List</strong>
              </div>

              <div class="setting-value">
                <p>Specify excluded images, files or paths as desired.</p>
              </div>

              <a href="#" class="wps-ic-configure-popup" data-popup-width="900" data-popup="exclude-list"><i class="svg-icon icon-configure"></i> Configure</a>
            </div>
          </div>
        </div>

        <div class="settings-container-third cname-container" style="margin-bottom:25px;margin-left:10px;">
          <div class="inner">
            <div class="setting-group setting-group-center">
              <div class="setting-icon ic-tooltip" title="If you know your site location please select it from the option list below.">
                <img src="<?php echo WPS_IC_URI; ?>assets/images/icon-exclude-list.svg"/>
              </div>
              <div class="setting-header" style="line-height: 30px;">
                <strong>Custom CDN Domain</strong>
              </div>

            </div>

            <div class="setting-group setting-group-center">
							<?php
							$zone_name = get_option('ic_custom_cname');
							if ( ! empty($zone_name)) {
								?>
                <div class="setting-value setting-configured cname-configured">
                  <strong>Connected Domain: </strong><br/><?php echo $zone_name; ?><br/>
                </div>
                <div class="setting-value setting-configure" style="display: none;">
                  <p>Use <strong>any domain</strong> you own to serve images and assets.</p>
                </div>
								<?php
							}
							else {
								?>
                <div class="setting-value setting-configured cname-configured" style="display: none;">
                  <strong>Connected Domain: </strong><br/><?php echo $zone_name; ?><br/>
                </div>
                <div class="setting-value setting-configure">
                  <p>Use <strong>any domain</strong> you own to serve images and assets.</p>
                </div>
								<?php
							}
							?>

							<?php
							$zone_name = get_option('ic_custom_cname');
							if ( ! empty($zone_name)) {
								?>
                <a href="#" class="wps-ic-configure-popup setting-configured" data-popup="remove-custom-cdn"><i class="icon-trash"></i> Remove</a>
                <a href="#" class="wps-ic-configure-popup setting-configure" data-popup-width="600" data-popup="custom-cdn" style="display:none;"><i class="svg-icon icon-configure"></i> Configure</a>
								<?php
							}
							else {
								?>
                <a href="#" class="wps-ic-configure-popup setting-configured" data-popup="remove-custom-cdn" style="display: none;"><i class="icon-trash"></i> Remove</a>
                <a href="#" class="wps-ic-configure-popup setting-configure" data-popup-width="600" data-popup="custom-cdn"><i class="svg-icon icon-configure"></i> Configure</a>
								<?php
							}
							?>
            </div>

          </div>
        </div>

      </div>
    </div>

  </div>

  <div id="geo-location" style="visibility: hidden;">
    <div id="cdn-popup-inner-geo" class="ajax-settings-popup bottom-border geo-location-popup">

      <div class="cdn-popup-top">
        <h3>Site Geo Location</h3>
        <img class="popup-icon" src="<?php echo WPS_IC_URI; ?>assets/images/icon-geolocation-popup.svg"/>
      </div>

      <div class="cdn-popup-loading" style="display: none;">
        <div class="wps-ic-bulk-preparing-logo-container">
          <div class="wps-ic-bulk-preparing-logo">
            <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
            <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="bulk-preparing"/>
          </div>
        </div>
      </div>

      <div class="cdn-popup-content">
        <p class="wpc-dynamic-text">We have detected that your server is located in <?php echo $geolocation_text; ?>, if that's not correct, please select the nearest region below.</p>
        <form method="post" action="#">
          <select name="location-select">
						<?php
						$location_select = array('Automatic' => 'Automatic', 'EU' => 'Europe', 'US' => 'United States', 'AS' => 'Asia', 'OC' => 'Oceania');

						foreach ($location_select as $k => $v) {
							if ($k == $geolocation->continent) {
								?>
                <option value="<?php echo $k; ?>" selected="selected"><?php echo $v; ?></option>
								<?php
							}
							else { ?>
                <option value="<?php echo $k; ?>"><?php echo $v; ?></option>
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
  <div id="exclude-list" style="visibility: hidden;">
    <div id="cdn-popup-inner-exclude" class="ajax-settings-popup bottom-border exclude-list-popup">

      <div class="cdn-popup-loading" style="display: none;">
        <div class="wps-ic-bulk-preparing-logo-container">
          <div class="wps-ic-bulk-preparing-logo">
            <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
            <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="bulk-preparing"/>
          </div>
        </div>
      </div>

      <div class="cdn-popup-top">
        <h3>Exclude List</h3>
        <p>Add excluded files or paths as desired as we use wildcard searching.</p>
      </div>

      <form method="post" action="#">
        <div class="cdn-popup-content-flex">
          <div class="cdn-popup-content">
            <div class="inline-heading-icon">
              <img src="<?php echo WPS_IC_URI; ?>assets/images/icon-exclude-from-cdn.svg"/>
              <h3>Exclude from CDN</h3>
            </div>
						<?php
						$external_url_exclude = get_option('wpc-ic-external-url-exclude');
						if ( ! empty($external_url_exclude)) {
							$external_url_exclude = implode("\n", $external_url_exclude);
						}
						?>
            <textarea name="exclude-list-textarea" class="exclude-list-textarea-value" placeholder="e.g. plugin-name/js/script.js, scripts.js, anyimage.jpg"><?php echo $external_url_exclude; ?></textarea>
            <div class="wps-empty-row">&nbsp;</div>

          </div>
          <div class="cdn-popup-content">
            <div class="inline-heading-icon">
              <img src="<?php echo WPS_IC_URI; ?>assets/images/exclude-lazy.svg"/>
              <h3>Exclude from Lazy</h3>
            </div>
						<?php
						$lazy_exclude = get_option('wpc-ic-lazy-exclude');
						if ( ! empty($lazy_exclude)) {
							$lazy_exclude = implode("\n", $lazy_exclude);
						}
						?>
            <textarea name="exclude-lazy-textarea" class="exclude-lazy-textarea-value" placeholder="e.g. wp-content/themes/folder/images, imagefilename.jpg"><?php echo $lazy_exclude; ?></textarea>
            <div class="wps-empty-row">&nbsp;</div>
          </div>
            <div class="cdn-popup-content">
                <div class="inline-heading-icon">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/icon-exclude-from-cdn.svg"/>
                    <h3>Exclude from Delay JS</h3>
                </div>
                <?php
                $delay_js_exclude = get_option('wpc-ic-delay-js-exclude');
                if ( ! empty($delay_js_exclude)) {
                    $delay_js_exclude = implode("\n", $delay_js_exclude);
                }
                ?>
                <textarea name="delay-js-exclude-list-textarea" class="delay-js-exclude-list-textarea-value" placeholder="e.g. plugin-name/js/script.js, scripts.js, plugin-name/"><?php echo $delay_js_exclude; ?></textarea>
                <div class="wps-empty-row">&nbsp;</div>

            </div>
        </div>
        <div class="wps-example-list">
          <div>
            <h3>Examples:</h3>
            <div>
              <p>.svg would exclude all assets with that extension</p>
              <p>imagename would exclude any file with that name</p>
              <p>/myplugin/image.jpg would exclude that specific file</p>
              <p>/wp-content/myplugin/ would exclude everything using that path</p>
            </div>
          </div>
        </div>
        <a href="#" class="btn btn-primary btn-active btn-save btn-exclude-save">Save</a>
      </form>

      <div class="cdn-popup-bottom-border">&nbsp;</div>

    </div>
  </div>
  <div id="custom-cdn" style="visibility: hidden;">
    <div id="cdn-popup-inner-cname" class="ajax-settings-popup bottom-border custom-cname-popup">

      <div class="cdn-popup-loading" style="display: none;">
        <div class="wps-ic-bulk-preparing-logo-container">
          <div class="wps-ic-bulk-preparing-logo">
            <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
            <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="bulk-preparing"/>
          </div>
        </div>
      </div>

      <div class="cdn-popup-content">
        <div class="custom-cdn-steps">
          <div class="custom-cdn-step-1">
            <div class="cdn-popup-top">
              <h3>Custom CDN Domain</h3>
              <img class="popup-icon" src="<?php echo WPS_IC_URI; ?>assets/images/icon-custom-cdn.svg"/>
            </div>
						<?php
						$zone_name = get_option('ic_cdn_zone_name');
						?>
            <ul>
              <li>1. Create a subdomain or domain that you wish to use.</li>
              <li>2. Edit the DNS records for the domain to create a new CNAME pointed at <strong><?php echo $zone_name; ?></strong></li>
              <li>3. Enter the url you've pointed below:</li>
            </ul>
            <form method="post" action="#" class="wpc-form-inline">
							<?php
							$custom_cname = get_option('ic_custom_cname');
							?>
              <input type="text" name="custom-cdn" placeholder="Example: cdn.mysite.com" value="<?php echo $custom_cname; ?>"/>
              <input type="submit" value="Save" name="save"/>
            </form>
            <div class="custom-cdn-error-message wpc-error-text" style="display: none;">
              &nbsp;
            </div>
          </div>
          <div class="custom-cdn-step-2" style="display: none;">
            <img src="" class="custom-cdn-step-2-img" onerror="this.src='<?php echo WPS_IC_URI; ?>assets/images/broken-placeholder.png';"/>
            <h3>Custom Domain Configuration</h3>
            <p>If you can see the celebration image above your custom domain is working!</p>
            <div class="wps-empty-row"></div>
            <a href="#" class="btn btn-primary btn-i-cant-see btn-cdn-config">I can't see the above Image</a>
            <a href="#" class="btn btn-primary btn-active btn-close btn-cdn-config">All Good to Go!</a>
          </div>
          <div class="custom-cdn-step-1-retry" style="display: none;">
            <div class="cdn-popup-top">
              <h3>Custom CDN Domain</h3>
              <img class="popup-icon" src="<?php echo WPS_IC_URI; ?>assets/images/icon-custom-cdn.svg"/>
            </div>
						<?php
						$zone_name = get_option('ic_cdn_zone_name');
						?>
            <ul>
              <li>1. Create a subdomain or domain that you wish to use.</li>
              <li>2. Edit the DNS records for the domain to create a new CNAME pointed at <strong><?php echo $zone_name; ?></strong></li>
              <li>3. Enter the url you've pointed below:</li>
            </ul>
            <form method="post" action="#" class="wpc-form-inline">
							<?php
							$custom_cname = get_option('ic_custom_cname');
							?>
              <input type="text" name="custom-cdn" placeholder="Example: cdn.mysite.com" value="<?php echo $custom_cname; ?>"/>
              <input type="submit" value="Save" name="save"/>
            </form>
            <p class="wpc-error-text"><span class="icon-container close-toggle"><i class="icon-cancel"></i></span> Seems like you have issues with your custom CDN configuration.</p>
            <div class="custom-cdn-error-message wpc-error-text" style="display: none;">
              &nbsp;
            </div>
          </div>
        </div>
      </div>

      <div class="cdn-popup-bottom-border">&nbsp;</div>

    </div>
  </div>
  <div id="remove-custom-cdn" style="visibility: hidden;">
    <div id="cdn-popup-inner-cname-remove" class="ajax-settings-popup bottom-border remove-cname-popup">

      <div class="cdn-popup-loading" style="display: none;">
        <div class="wps-ic-bulk-preparing-logo-container">
          <div class="wps-ic-bulk-preparing-logo">
            <img src="<?php echo WPS_IC_URI; ?>assets/images/logo/blue-icon.svg" class="bulk-logo-prepare"/>
            <img src="<?php echo WPS_IC_URI; ?>assets/preparing.svg" class="bulk-preparing"/>
          </div>
        </div>
      </div>

      <div class="cdn-popup-bottom-border">&nbsp;</div>

    </div>
  </div>

</form>
<div class="wps-ic-mu-popup-empty-cname" style="visibility: hidden;">
  <div id="cdn-popup-empty-sites-inner" class="swal-popup-inner bottom-border">

    <div style="padding-bottom:30px;">
      <div class="wps-ic-mu-popup-select-sites">
        <img src="<?php echo WPS_IC_URI; ?>assets/images/projected-alert.svg" style="width:160px;" />
      </div>
      <h3>You need to insert your CNAME!</h3>
    </div>

    <div class="cdn-popup-bottom-border">&nbsp;</div>

  </div>
</div>
<div id="wps-ic-mu-disconnect-popup" style="visibility: hidden;">
  <div id="disconnect-popup-inner" class="swal-popup-inner bottom-border">

    <div class="cdn-popup-top">
      <img src="<?php echo WPS_IC_URI; ?>assets/mu/images/disconnect.svg" class="disconnect-logo"/>
      <h3>Are you sure you wish to disconnect?</h3>
      <p style="font-size:14px;">You may reconnect at any time. If you are in live mode, image optimization and delivery will stop immediately. If you've locally optimized images, images will remain in their current state after deactivation.</p>
      <p style="color:#57A3E5;"><?php echo $current_blog_details->siteurl; ?></p>
    </div>

    <div class="cdn-popup-bottom-border">&nbsp;</div>

  </div>
</div>