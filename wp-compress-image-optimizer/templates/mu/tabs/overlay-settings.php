<form method="POST" action="#" class="wpc-ic-mu-overlay-settting-form" style="display:none;">

  <div class="wpc-ic-mu-site-overlay-container ic-advanced-settings-v2">
    <div class="wpc-ic-mu-site-header">
      <div class="wpc-ic-mu-site-left-side">
        <div class="wpc-ic-mu-site-name">
          <span class="wpc-ic-mu-site-status-circle"></span>
          <h3><?php echo 'Default Settings'; ?></h3>
          <h5><?php echo 'setup your default configuration'; ?></h5>
        </div>
        <div style="display: inline-block;vertical-align: middle;margin-left:20px;">
            <div class="checkbox-container-v3-inverse wps-ic-ajax-checkbox" style="display: inline-block;">
              <div class="checkbox-label-left">Local</div>
              <input type="checkbox" value="live" name="wp-ic-setting[live-cdn]" data-setting_name="live-cdn" data-setting_value="live" <?php echo checked($settings['live-cdn'], 'live'); ?>/>
              <div>
                <label for="live-cdn-toggle" class="live-cdn-toggle"></label>
              </div>
              <div class="checkbox-label-right">Live</div>
            </div>
        </div>
      </div>
      <div class="wpc-ic-mu-site-right-side">
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
            <input type="hidden" name="wp-ic-setting[optimization]" value="<?php echo $settings['optimization']; ?>"/>
            <ul>
              <li <?php echo $wps_ic_active_settings['optimization']['lossless']; ?>><a href="#" class="wps-ic-change-optimization" data-value="lossless">Lossless</a></li>
              <li <?php echo $wps_ic_active_settings['optimization']['intelligent']; ?>><a href="#" class="wps-ic-change-optimization" data-value="intelligent">Intelligent</a></li>
              <li <?php echo $wps_ic_active_settings['optimization']['ultra']; ?>><a href="#" class="wps-ic-change-optimization" data-value="ultra">Ultra</a></li>
            </ul>
          </div>
        </div>
        <div class="inner">
          <h3>Retina</h3>

          <div class="checkbox-container-v2 informative-input">
            <input type="checkbox" value="1" name="wp-ic-setting[retina]" data-setting_name="retina" data-setting_value="1" <?php echo checked($settings['retina'], '1'); ?>/>
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

          <div class="checkbox-container-v2 informative-input">
            <input type="checkbox" value="1" name="wp-ic-setting[generate_adaptive]" data-setting_name="generate_adaptive" data-setting_value="1" <?php echo checked($settings['generate_adaptive'], '1'); ?>/>
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

          <div class="checkbox-container-v2 informative-input">
            <input type="checkbox" value="1"
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

          <div class="checkbox-container-v2 informative-input" style="display: block;">
            <input type="checkbox" value="1" name="wp-ic-setting[lazy]" data-setting_name="lazy" data-setting_value="1" <?php echo checked($settings['lazy'], '1'); ?>/>
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
              <div class="settings-header-title">
                CDN Delivery Options
                <span class="ic-tooltip" title="Allows your website to minify and serve JavaScript files via the CDN - be sure not to duplicate JavaScript settings with other solutions, however all toggles are temporary and can be toggled off at anytime to revert changes."><i class="fa fa-question-circle"></i></span>
              </div>
            </div>
            <div style="margin-left:15px;display:flex;width:100%;">
              <div class="setting-body-half settings-area-serve-cdn">
                <div class="setting-inner-header">
                  <h3>Serve Images</h3>
                </div>
                <div class="setting-option wpc-checkbox">
                  <input type="checkbox" value="1" name="wp-ic-setting[serve_jpg]" data-setting_name="serve_jpg" data-setting_value="1" <?php echo checked($settings['serve']['jpg'], '1'); ?>/>
                  <label for="serve_jpg">JPG/JPEG</label>
                </div>
                <div class="setting-option wpc-checkbox">
                  <input type="checkbox" value="1" name="wp-ic-setting[serve_png]" data-setting_name="serve_png" data-setting_value="1" <?php echo checked($settings['serve']['png'], '1'); ?>/>
                  <label for="serve_png">PNG</label>
                </div>
                <div class="setting-option wpc-checkbox">
                  <input type="checkbox" value="1" name="wp-ic-setting[serve_gif]" data-setting_name="serve_gif" data-setting_value="1" <?php echo checked($settings['serve']['gif'], '1'); ?>/>
                  <label for="serve_gif">GIF</label>
                </div>
                <div class="setting-option wpc-checkbox">
                  <input type="checkbox" value="1" name="wp-ic-setting[serve_svg]" data-setting_name="serve_svg" data-setting_value="1" <?php echo checked($settings['serve']['svg'], '1'); ?>/>
                  <label for="serve_svg">SVG</label>
                </div>
              </div>
              <div class="setting-body-half settings-area-serve-cdn">
                <div class="setting-inner-header">
                  <h3>Serve Assets</h3>
                </div>
                <div class="setting-option wpc-checkbox">
                  <input type="checkbox" value="1" name="wp-ic-setting[js]" data-setting_name="js" data-setting_value="1" <?php echo checked($settings['js'], '1'); ?>/>
                  <label for="js-toggle">JavaScript via CDN</label>
                </div>
                <div class="setting-option wpc-checkbox">
                  <input type="checkbox" value="1" name="wp-ic-setting[css]" data-setting_name="css" data-setting_value="1" <?php echo checked($settings['css'], '1'); ?>/>
                  <label for="css-toggle">CSS via CDN</label>
                </div>
                <div class="setting-option wpc-checkbox">
									<?php
									$zone_name = get_option('ic_custom_cname');
									if ( ! empty($zone_name)) {
										?>
                    <input type="checkbox" value="1" name="wp-ic-setting[fonts]" data-setting_name="fonts" data-setting_value="1" <?php echo checked($settings['fonts'], '1'); ?> class="cname-enabled"/>
                    <label for="fonts-enabled" class="label-enabled">Fonts</label>

                    <input type="checkbox" value="1" name="wp-ic-setting[fonts]" data-setting_name="fonts" data-setting_value="1" <?php echo checked($settings['fonts'], '1'); ?> class="disabled-checkbox ic-tooltip cname-disabled" title="To enable fonts option you need to setup your custom CDN." style="display: none;"/>
                    <label for="fonts" class="label-disabled ic-tooltip" title="To be able to serve fonts, you’ll first need to set up a custom CDN domain matching your root domain." style="display:none;">Fonts</label>
									<?php } else { ?>
                    <input type="checkbox" value="1" name="wp-ic-setting[fonts]" data-setting_name="fonts" data-setting_value="1" <?php echo checked($settings['fonts'], '1'); ?> style="display: none;" class="cname-enabled"/>
                    <label for="fonts-enabled" class="label-enabled" style="display:none;">Fonts</label>

                    <input type="checkbox" value="1" name="wp-ic-setting[fonts]" data-setting_name="fonts" data-setting_value="1" <?php echo checked($settings['fonts'], '1'); ?> class="disabled-checkbox ic-tooltip cname-disabled"
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
                    <input type="hidden" name="wp-ic-setting[search-through]" value="<?php echo $settings['search-through']; ?>">
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
            </div>
            <div class="setting-body settings-area-additional-settings">

              <div class="setting-option">
                <div class="setting-label">Preserve EXIF Data</div>
                <div class="setting-value ic-custom-tooltip" title="Keep the image metadata, typically for photographers or SEO.">
                  <div class="checkbox-container-v3 wps-ic-ajax-checkbox" style="display: inline-block;">
                    <input type="checkbox" value="1" name="wp-ic-setting[preserve-exif]" data-setting_name="preserve-exif" data-setting_value="1" <?php echo checked($settings['preserve-exif'], '1'); ?>/>
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
                    <input type="checkbox" value="1" name="wp-ic-setting[on-upload]" data-setting_name="on-upload" data-setting_value="1" <?php echo checked($settings['on-upload'], '1'); ?>/>
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
                    <input type="checkbox" value="1" name="wp-ic-setting[emoji-remove]" data-setting_name="emoji-remove" data-setting_value="1" <?php echo checked($settings['emoji-remove'], '1'); ?>/>
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
                    <input type="checkbox" value="1" name="wp-ic-setting[minify-css]" data-setting_name="minify-css" data-setting_value="1" <?php echo checked($settings['minify-css'], '1'); ?>/>
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
                    <input type="checkbox" value="1" name="wp-ic-setting[minify-js]" data-setting_name="minify-js" data-setting_value="1" <?php echo checked($settings['minify-js'], '1'); ?>/>
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
                    <input type="checkbox" value="1" name="wp-ic-setting[defer-js]" data-setting_name="defer-js" data-setting_value="1" <?php echo checked($settings['defer-js'], '1'); ?>/>
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
                    <input type="checkbox" value="1" name="wp-ic-setting[external-url]" data-setting_name="external-urls" data-setting_value="1" <?php echo checked($settings['external-url'], '1'); ?>/>
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
                    <input type="checkbox" id="remove-render-blocking-toggle" value="1" name="wp-ic-setting[remove-render-blocking]" data-setting_name="remove-render-blocking" data-setting_value="1" <?php echo checked($settings['remove-render-blocking'], '1'); ?>/>
                    <div>
                      <label for="remove-render-blocking-toggle" class="remove-render-blocking-toggle"></label>
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

              <a href="#" class="wps-ic-configure-popup" data-popup="geo-location"><i class="svg-icon icon-configure"></i> Configure</a>

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

              <a href="#" class="wps-ic-configure-popup" data-popup="exclude-list"><i class="svg-icon icon-configure"></i> Configure</a>
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
                <a href="#" class="wps-ic-configure-popup setting-configure" data-popup="custom-cdn" style="display:none;"><i class="svg-icon icon-configure"></i> Configure</a>
								<?php
							}
							else {
								?>
                <a href="#" class="wps-ic-configure-popup setting-configured" data-popup="remove-custom-cdn" style="display: none;"><i class="icon-trash"></i> Remove</a>
                <a href="#" class="wps-ic-configure-popup setting-configure" data-popup="custom-cdn"><i class="svg-icon icon-configure"></i> Configure</a>
								<?php
							}
							?>
            </div>

          </div>
        </div>

      </div>
    </div>
  </div>


</form>
