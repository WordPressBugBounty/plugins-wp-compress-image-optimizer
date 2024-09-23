<?php

/**
 * GeoLocation Stuff
 */

switch_to_blog(1);
$geolocation = get_option('wps_ic_geo_locate');

include WPS_IC_DIR . 'classes/gui-v4.class.php';
$multisiteDefaultSettings = get_option('multisite_default_settings');

if (empty($multisiteDefaultSettings['qualityLevel'])) {
  $multisiteDefaultSettings['qualityLevel'] = '2';
}

$multisiteDefaultSettings['optimizationLevel'] = $multisiteDefaultSettings['qualityLevel'];
$multisiteDefaultSettings['optimization'] = $multisiteDefaultSettings['qualityLevel'];
$gui = new wpc_gui_v4($multisiteDefaultSettings);

if (empty($geolocation)) {
  $geolocation = $this->geoLocate();
} else {
  $geolocation = (object)$geolocation;
}

$geolocation_text = $geolocation->country_name . ' (' . $geolocation->continent_name . ')';

$multisiteDefaultSettings = get_option('multisite_default_settings');
if (empty($multisiteDefaultSettings)) {
  $multisiteDefaultSettings = array('live-cdn' => 'live', 'retina' => '1', 'generate_webp' => '1', 'generate_adaptive' => '1', 'lazy' => '1', 'js' => '0', 'css' => '0', 'css_image_urls' => '0', 'external-url' => '0', 'remove-render-blocking' => '0', 'background-sizing' => '0', 'replace-all-link' => '0', 'emoji-remove' => '1', 'on-upload' => '0', 'defer-js' => '0', 'serve' => ['jpg' => '1', 'png' => '1', 'gif' => '1', 'svg' => '1'], 'search-through' => 'html');
  update_option('multisite_default_settings', $multisiteDefaultSettings);
}

$settings = $multisiteDefaultSettings;

?>
<form method="POST" action="#" class="wpc-ic-mu-default-settting-form">

    <input type="hidden" name="siteID" value="0"/>

    <div class="wpc-ic-mu-site-container ic-advanced-settings-v2">
        <div class="wpc-ic-mu-site-header">
            <div class="wpc-ic-mu-site-left-side">
                <div class="wpc-ic-mu-site-name">
                    <span class="wpc-ic-mu-site-status-circle"></span>
                    <h3><?php echo 'Default Settings'; ?></h3>
                    <h5><?php echo 'setup your default configuration'; ?></h5>
                </div>
                <div style="display: inline-block;vertical-align: middle;margin-left:20px;">
                    <div class="checkbox-container-v3-inverse wps-ic-ajax-checkbox wps-ic-ajax-live-cdn" style="display: inline-block;">
                        <div class="checkbox-label-left">Local</div>
                        <input type="checkbox" id="live-cdn-toggle" value="live" name="options[live-cdn]" data-setting_name="live-cdn" data-setting_value="live" <?php echo checked($settings['live-cdn'], 'live'); ?>/>
                        <div>
                            <label for="live-cdn-toggle" class="live-cdn-toggle label-live-cdn-toggle"></label>
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

        <div class="wpc-advanced-settings-container-v4" style="margin-top: 0">
            <div class="wpc-settings-body">
                <div class="wpc-settings-tabs">
                    <div class="wpc-settings-tab-content">
                        <div class="wpc-settings-tab-content-inner">
                            <div class="wpc-tab-content-box">
                              <?php echo $gui::optimizationLevel('Optimization Level', 'optimizationLevel', 'Select your preferred image compression strength.', 'tab-icons/optimization-level.svg', '', 'optimizationLevel'); ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="wpc-settings-tabs">
                    <div class="wpc-settings-tab-content">
                        <div class="wpc-settings-tab-content-inner">
                            <div class="wpc-tab-content-box">
                              <?php echo $gui::checkboxTabTitleCheckbox('Real-Time Optimization + CDN', 'Optimize your images & scripts in real-time via our top-rated global CDN.', 'tab-icons/real-time.svg', '', '', '', '', 'exclude-cdn-popup'); ?>

                                <div class="wpc-spacer"></div>

                                <div class="wpc-items-list-row real-time-optimization">

                                  <?php echo $gui::iconCheckBox('JPG/JPEG', 'cdn-delivery/jpg.svg', ['serve', 'jpg']); ?>
                                  <?php echo $gui::iconCheckBox('PNG', 'cdn-delivery/png.svg', ['serve', 'png']); ?>
                                  <?php echo $gui::iconCheckBox('GIF', 'cdn-delivery/gif.svg', ['serve', 'gif']); ?>
                                  <?php echo $gui::iconCheckBox('SVG', 'cdn-delivery/svg.svg', ['serve', 'svg']); ?>

                                  <?php echo $gui::iconCheckBox('CSS', 'cdn-delivery/css.svg', 'css'); ?>
                                  <?php echo $gui::iconCheckBox('JavaScript', 'cdn-delivery/js.svg', 'js'); ?>
                                  <?php echo $gui::iconCheckBox('Fonts', 'cdn-delivery/font.svg', 'fonts'); ?>


                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="wpc-settings-tabs">
                    <div class="wpc-settings-tab-content">
                        <div class="wpc-settings-tab-content-inner">
                            <div class="wpc-tab-content-box">
                              <?php echo $gui::checkboxTabTitleCheckbox('Adaptive Images', 'Intelligently adapt images based on the incoming visitors device, browser and location on page.', 'image-optimization/image-optimization.svg', '', ''); ?>

                                <div class="wpc-spacer"></div>

                                <div class="wpc-items-list-row mb-20">

                                  <?php echo $gui::checkboxDescription_v4('Resize by Incoming Device', 'Serve the ideal image based on the visitors device to slash file-sizes, improve load times and offer a better experience.', false, '0', 'generate_adaptive', false, 'right', 'exclude-adaptive-popup'); ?>

                                  <?php echo $gui::checkboxDescription_v4('Serve WebP Images', 'Generate and serve next generation WebP images to supported browsers and devices.', false, '0', 'generate_webp', false, 'right', 'exclude-webp-popup'); ?>

                                </div>
                                <div class="wpc-items-list-row mb-20">

                                  <?php echo $gui::checkboxDescription_v4('Serve Retina Images', 'Deliver higher resolution retina images so that your images look great on larger screens.', false, '0', 'retina', false, 'right'); ?>

                                  <?php echo $gui::checkboxDescription_v4('Lazy Loading by Viewport', 'Load additional images as the user scrolls to save tons of bandwidth and slash overall page size.', false, '0', 'lazy', false, 'right', 'exclude-lazy-popup'); ?>

                                </div>

                                <div class="wpc-items-list-row mb-20">
                                  <?php echo $gui::checkboxDescription_v4('Remove Srcset', 'Disable theme srcset to avoid unintended conflicts with adaptive images or lazy loading.', false, '0', 'remove-srcset', false, 'right'); ?>

                                  <?php echo $gui::inputDescription_v4('Max Image Width', 'Insert maximum dimensions of images, we will scale your original images to that width.', false, '0', 'max-original-width', false, 'right'); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>



    </div>

</form>
