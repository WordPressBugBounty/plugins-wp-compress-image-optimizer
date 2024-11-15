<?php

/**
 * GeoLocation Stuff
 */

switch_to_blog(1);
$options = new wps_ic_options();

include WPS_IC_DIR . 'classes/gui-v4.class.php';

$multisiteDefaultSettings = get_option('multisite_default_settings');

if (empty($multisiteDefaultSettings['qualityLevel'])) {
    $multisiteDefaultSettings = $options->getDefault();
}

$multisiteDefaultSettings['optimizationLevel'] = $multisiteDefaultSettings['qualityLevel'];
$multisiteDefaultSettings['optimization'] = $multisiteDefaultSettings['qualityLevel'];

$gui = new wpc_gui_v4($multisiteDefaultSettings);

$settings = $multisiteDefaultSettings;

$cacheLocked = false;
$cssLocked = false;
$delayLocked = false;

$cssEnabled = $gui::isFeatureEnabled('css');
$delayEnabled = $gui::isFeatureEnabled('delay-js');

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
                                <?php echo $gui::checkboxTabTitleCheckbox('Performance Features', 'Optimize your images & scripts in real-time via our top-rated global CDN.', 'tab-icons/real-time.svg', '', '', '', '', false); ?>

                                <div class="wpc-spacer"></div>

                                <div class="wpc-items-list-row real-time-optimization">

                                    <?php echo $gui::checkboxDescription_v4('Enable Caching', 'Speed up your site by statically caching entire pages.', '', '', ['cache', 'advanced'], $cacheLocked, '', ''); ?>

                                    <?php
                                    echo $gui::checkboxDescription_v4('Critical CSS', 'Optimize initial page load by removing unused CSS.', '', '', ['critical', 'css'], $cssLocked, '1', '', false, '', $cssEnabled); ?>

                                    <?php
                                    echo $gui::checkboxDescription_v4('Delay JavaScript', 'Speed up initial response times by delaying unnecessary JS.', false, '', 'delay-js', $delayLocked, 'right', '', false, '', $delayEnabled); ?>




                                </div>
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

                                  <?php echo $gui::checkboxDescription_v4('Font SubSetting', 'Font subsetting is the practice of embedding only the necessary characters from a font, reducing file size and improving load times.', false, false, 'font-subsetting', false, 'right', false, false, ''); ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>



    </div>

</form>
