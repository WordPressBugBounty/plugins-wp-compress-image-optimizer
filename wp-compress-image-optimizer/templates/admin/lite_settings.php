<?php
global $wps_ic, $wpdb;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wpc_settings_save_nonce'], 'wpc_settings_save')) {
        die('Forbidden.');
    }
}

// For Lite Settings
$settings = get_option(WPS_IC_SETTINGS);
if (empty($settings['imagesPreset']) || empty($settings['cdnAll'])) {
    if (!empty($settings['generate_adaptive']) || !empty($settings['retina']) || !empty($settings['generate_webp'])) {
        $settings['imagesPreset'] = '1';
    }

    if (!empty($settings['css']) || !empty($settings['js']) || !empty($settings['fonts']) || !empty($settings['serve']['jpg']) && !empty($settings['serve']['gif']) || !empty($settings['serve']['png']) || !empty($settings['serve']['svg'])) {
        $settings['cdnAll'] = '1';
    }

    update_option(WPS_IC_SETTINGS, $settings);
}
// End

// reset GPS Test
if (!empty($_GET['resetTest'])) {
    delete_transient('wpc_test_running');
    delete_transient('wpc_initial_test');
    delete_option(WPS_IC_LITE_GPS);
    delete_option(WPC_WARMUP_LOG_SETTING);
}

$options = get_option(WPS_IC_OPTIONS);

if (!empty($_POST)) {
    $settings = $_POST['options'];

    $optionsClass = new wps_ic_options();
    $defaultSettings = $optionsClass->getDefault();

    if (empty($settings) || !is_array($settings)) {
        $settings = [];
    }

    foreach ($defaultSettings as $option_key => $option_value) {
        if (is_array($option_value)) {
            foreach ($option_value as $option_value_k => $option_value_v) {
                if (!isset($settings[$option_key][$option_value_k])) {
                    if (!isset($settings[$option_key])) {
                        $settings[$option_key] = [];
                    }
                    $settings[$option_key][$option_value_k] = '0';
                }
            }
        } else {
            if (!isset($settings[$option_key])) {
                $settings[$option_key] = '0';
            }
        }
    }

    // Patch for CDNAll
    if (isset($settings['imagesPreset']) && $settings['imagesPreset'] == '1') {
        $settings['retina'] = '1';
        $settings['generate_adaptive'] = '1';
        $settings['generate_webp'] = '1';
    }

    if (isset($settings['cdnAll']) && $settings['cdnAll'] == '1') {
        $settings['live-cdn'] = '1';
        $settings['serve'] = ['jpg' => '1', 'gif' => '1', 'png' => '1', 'svg' => '1'];
        $settings['css'] = '1';
        $settings['js'] = '1';
        $settings['fonts'] = '1';
        $settings['qualityLevel'] = 'intelligent';
    } else {
        $settings['live-cdn'] = '0';
        $settings['serve'] = ['jpg' => '0', 'gif' => '0', 'png' => '0', 'svg' => '0'];
        $settings['css'] = '0';
        $settings['js'] = '0';
        $settings['fonts'] = '0';
    }

    update_option(WPS_IC_SETTINGS, $settings);

    $cache = new wps_ic_cache_integrations();

    // Get Purge List
    $options_class = new wps_ic_options();
    $purgeList = $options_class->getPurgeList($options);

    $cache::purgeAll(); //this only clears cache files
    //To edit what setting purges what, go to wps_ic_options->__construct()
    if (in_array('combine', $purgeList)) {
        $cache::purgeCombinedFiles();
    }

    if (in_array('critical', $purgeList)) {
        $cache::purgeCriticalFiles();
    }

    if (in_array('cdn', $purgeList)) {
        $cacheLogic = new wps_ic_cache();
        $cacheLogic->purgeCDN();
    }

    if (!empty($options['cache']['advanced']) && $options['cache']['advanced'] == '1') {
        $htacces = new wps_ic_htaccess();

        if (!empty($options['cache']['compatibility']) && $options['cache']['compatibility'] == '1' && $htacces->isApache) {
            // Modify HTAccess
            #$htacces->checkHtaccess();
        } else {
            $htacces->removeHtaccessRules();
        }

        // Add WP_CACHE to wp-config.php
        $htacces->setWPCache(true);
        $htacces->setAdvancedCache();

        $this->cacheLogic = new wps_ic_cache();
        $this->cacheLogic::removeHtmlCacheFiles(0); // Purge & Preload
        $this->cacheLogic::preloadPage(0); // Purge & Preload
    } else {
        // Modify HTAccess
        $htacces = new wps_ic_htaccess();
        $htacces->removeHtaccessRules();

        // Add WP_CACHE to wp-config.php
        $htacces->setWPCache(false);
        $htacces->removeAdvancedCache();
    }
}

if (is_multisite()) {
    $current_blog_id = get_current_blog_id();
    switch_to_blog($current_blog_id);
}

$optimize = get_option('wpc-warmup-selector');
if ($optimize === false) {
    $optimize = ['page', 'post'];
    update_option('wpc-warmup-selector', $optimize);
}

if (!empty($_GET['resetTest'])) {
    delete_transient('wpc_initial_test');
    update_option(WPS_IC_LITE_GPS, ['result' => array(), 'failed' => false, 'lastRun' => time()]);
    $tests = get_option(WPS_IC_TESTS);
    unset($tests['home']);
    update_option(WPS_IC_TESTS, $tests);
}

include WPS_IC_DIR . 'classes/gui-v4.class.php';
$gui = new wpc_gui_v4();
$stats = new wps_ic_stats();
$apiStats = $stats->getApiStats();
$optimizedStats = $stats->getOptimizedStats();
$optimizationStatus = $stats->getLiteOptimizationStatus($optimizedStats);

$settings = get_option(WPS_IC_SETTINGS);
$initialPageSpeedScore = get_option(WPS_IC_LITE_GPS);
$initialTestRunning = get_transient('wpc_initial_test');
$warmupLog = get_option(WPC_WARMUP_LOG_SETTING);
$option = get_option(WPS_IC_OPTIONS);

$warmup_class = new wps_ic_preload_warmup();
$warmupFailing = $warmup_class->isWarmupFailing();

if (!empty($option['api_key']) && !$warmupFailing && (empty($initialPageSpeedScore))) {
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function ($) {
            var checkFetch = setInterval(function () {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wps_fetchInitialTest',
                    },
                    success: function (response) {
                        if (response.success) {
                            clearInterval(checkFetch);
                            setTimeout(function () {
                                window.location.reload();
                            }, 2000);
                        } else if (response.success == false) {
                            if (response.data.reset == 'true') {
                                clearInterval(checkFetch);
                                setTimeout(function () {
                                    window.location.reload();
                                }, 2000);
                            } else if (response.data.warmupFailing == true){
                                clearInterval(checkFetch);
                                setTimeout(function () {
                                    window.location.reload();
                                }, 2000);
                            }
                        }
                    }
                });
            }, 10000);
        });
    </script>
<?php } ?>

    <script type="text/javascript">
        var selectedTypes = <?php echo json_encode([]); ?>;
        var selectedStatuses = <?php echo json_encode([]); ?>;
        var selectedOptimizes = <?php echo json_encode($optimize); ?>;
    </script>
    <div class="wpc-advanced-settings-container wpc-lite-settings-container wps_ic_settings_page">
        <?php
        $wps_ic->integrations->render_plugin_notices();
        ?>


        <form method="POST" class="wpc-lite-form"
              action="<?php echo admin_url('options-general.php?page=' . $gui::$slug); ?>">
            <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
            <!-- Header Start -->
            <div class="wpc-header">
                <div class="wpc-header-left" style="max-width:250px;">
                    <div class="wpc-header-logo">
                        <img src="<?php echo WPS_IC_URI; ?>assets/v4/images/main-logo.svg"/>
                    </div>
                </div>
                <!-- Right Side -->
                <div class="wpc-header-right">
                    <div class="save-button" style="display:none;">
                        <div class="save-notification">
                            <div class="save-notification-inside">
                                <p class="cdn-active d-flex align-items-center gap-2 fs-400">
                                    <i class="wpc-warning-icon"></i> Please save your changes!
                                </p>
                            </div>
                        </div>
                        <div class="save-button-inside">
                            <div>
                                <button type="submit"
                                        class="btn btn-gradient text-white fw-400 btn-radius save-button-lite">
                                    <i class="wpc-save-button-icon"></i> Save
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="wpc-loading-spinner" style="display:none;">
                        <div class="snippet" data-title=".dot-pulse">
                            <div class="stage">
                                <div class="dot-pulse"></div>
                            </div>
                        </div>
                    </div>
                    <div class="wpc-optimization-page-button">
                        <a class="wpc-optimizer-running wpc-page-optimizations-running wpc-stop-page-optimizations"
                           style="display:none">
                            <i class="icon-pause"></i> Pause Optimization</a>
                        <a class="btn btn-gradient text-white fw-500 btn-radius wpc-optimizer-running wpc-page-optimizations-running"
                           style="display:none;font-weight: bold;font-family:'proxima_semibold' !important;">
                            <img src="<?php
                            echo WPS_IC_ASSETS; ?>/v4/images/loading-icon-media.svg"
                                 style="max-height: 25px;margin-right:10px">
                            Optimization in progress...
                        </a>
                        <a class="btn btn-gradient text-white fw-500 btn-radius wpc-start-optimizations"
                           style="display:none;font-weight: bold;font-family:'proxima_semibold' !important;">
                            <img src="<?php
                            echo WPS_IC_ASSETS; ?>/v4/icons/thunder-icon-white.svg"
                                 style="height: 17px;;margin-right:10px">Start Optimization
                        </a>
                        <a class="btn btn-gradient text-white fw-500 btn-radius
                                    wpc-optimization-complete"
                           style="display:none;font-weight: bold;font-family:'proxima_semibold' !important;">
                            Site Optimized
                        </a>
                        <a class="btn btn-gradient text-white fw-500 btn-radius
                                    wpc-preparing-optimization"
                           style="display:none;font-weight: bold;font-family:'proxima_semibold' !important;">
                            Preparing...
                        </a>
                        <a class="btn btn-gradient text-white fw-500 btn-radius
                                    wpc-optimization-locked" style="display:none;font-weight: bold;
                                    font-family:'proxima_semibold' !important;">
                            Smart Optimization Locked
                        </a>

                        <?php
                        /*
                        $preload_class = new wps_ic_preload_warmup();
                        $pagesToPreload = $preload_class->getPagesToOptimize();
                        if (!empty($preload_class->get_optimization_status())) { ?>
                            <script>
                                jQuery('.wpc-page-optimizations-running').show();
                            </script>
                        <?php
                        } else if ($pagesToPreload['unoptimized'] > 0) { ?>
                            <script>
                                jQuery('.wpc-start-optimizations').show();
                            </script>
                        <?php
                        } else { ?>
                            <script>
                                jQuery('.wpc-optimization-complete').show();
                            </script>
                          <?php
                        } */ ?>
                    </div>
                </div>
            </div>
            <!-- Header End -->

            <!-- Body Start -->
            <div class="wpc-settings-flex-body">
                <div class="wpc-settings-sidebar">

                    <div class="wpc-rounded-box">
                        <div class="wpc-box-title circle">
                            <h3>Quick Optimizations</h3>
                        </div>
                        <div class="wpc-box-content">
                            <ul class="wpc-toggles">
                                <li>
                                    <?php echo $gui::simpleCheckbox('Cache', '', false, '0', ['cache', 'advanced'], false); ?>
                                </li>
                                <li>
                                    <?php echo $gui::simpleCheckbox('CSS', '', false, '0', ['critical', 'css'], false); ?>
                                </li>
                                <li>
                                    <?php echo $gui::simpleCheckbox('JavaScript', '', false, '0', 'delay-js', false); ?>
                                </li>
                                <li>
                                    <?php echo $gui::simpleCheckbox('Lazy Loading', '', false, '0', 'nativeLazy', false); ?>
                                </li>
                                <li>
                                    <?php
			                            $liteActive = empty($option['api_key']);
			                            echo $gui::simpleCheckbox('Images', '', false, '0', 'imagesPreset', $liteActive);
                                    ?>
                                </li>
                                <li>
			                        <?php
			                            $cdnLocked = !get_option( 'wps_ic_allow_live' );
			                            if ($liteActive) {
				                            echo $gui::simpleCheckbox( 'CDN', '', false, '0', 'cdnAll', true );
			                            } else if ($cdnLocked){
				                            //dont display the toggle, off in portal
			                            } else {
				                            echo $gui::simpleCheckbox( 'CDN', '', false, '0', 'cdnAll', false );
			                            }
			                        ?>
                                </li>
                                <li class="wpc-menu-divider">
                                    <?php
                                    if ($liteActive) {
                                        ?>
                                        <a href="#" class="wpc-lite-locked-advanced"><img src="<?php echo WPS_IC_URI; ?>assets/lite/images/advanced-settings.svg"/>Advanced Settings</a>
                                    <?php } else { ?>
                                        <a href="#" class="wpc-lite-toggle-advanced"><img src="<?php echo WPS_IC_URI; ?>assets/lite/images/advanced-settings.svg"/>Advanced Settings</a>
                                    <?php } ?>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <?php
                    if (empty($options['api_key']) || (!empty($options['version']) && $options['version'] == 'lite')) {
                        ?>
                        <div class="wpc-rounded-box wpc-promo-box">
                            <div class="wpc-box-title"><img
                                        src="<?php echo WPS_IC_URI; ?>assets/lite/images/unlock-icon.svg"
                                        alt="Unlock PRO Features"/> Unlock PRO Features
                            </div>
                            <div class="wpc-box-content">
                                <ul>
                                    <li>
                                        <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/up-pro.svg"
                                             alt="Instant Page Speed Boost"/>
                                        <span>Instant Page Speed Boost</span>
                                    </li>
                                    <li>
                                        <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/magic-wand.svg"
                                             alt="One-Click Smart Optimization"/>
                                        <span>One-Click Smart Optimization</span>
                                    </li>
                                    <li>
                                        <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/falling-star.svg"
                                             alt="Adaptive Image Optimization"/>
                                        <span>Adaptive Image Optimization</span>
                                    </li>
                                    <li>
                                        <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/bolt.svg"
                                             alt="Ultra-Fast Global CDN Delivery"/>
                                        <span>Ultra-Fast Global CDN Delivery</span>
                                    </li>
                                </ul>
                            </div>
                            <div class="wpc-box-content-btn">
                                <a href="#" class="wpc-add-access-key-btn-pro">Enter Access Key <img
                                            src="<?php echo WPS_IC_URI; ?>assets/lite/images/btn-arrow.svg"/></a>
                            </div>
                        </div>
                    <?php } else { ?>
                        <div class="wpc-rounded-box wpc-promo-box">
                            <div class="wpc-box-title"><img
                                        src="<?php echo WPS_IC_URI; ?>assets/lite/images/unlock-icon.svg"
                                        alt="Unlock PRO Features"/>
                                <div>
                                    <span>This Month’s Usage</span>
                                    <span class="wpc-small-txt">statistics updated hourly</span>
                                </div>
                            </div>
                            <div class="wpc-box-content">
                                <ul>
                                    <li>
                                        <div class="wpc-month-usage">
                                            <span class="label">Total Assets</span>
                                            <span class="value"><?php echo $apiStats->display->requests; ?></span>
                                        </div>
                                    </li>
                                    <li>
                                        <div class="wpc-month-usage">
                                            <span class="label">Optimized</span>
                                            <span class="value"><?php echo $apiStats->display->bytes; ?></span>
                                        </div>
                                    </li>
                                </ul>
                            </div>
                            <div class="wpc-box-footer-content">
                                <div class="wpc-box-footer-title">
                                    Projected Usage
                                </div>
                                <ul class="wpc-footer-li-inline">
                                    <li>
                                        <span class="wpc-stats-footer-title">Assets</span>
                                        <span class="wpc-stats-footer-box">
                                            <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/projected-stats.svg"/>
                                            <?php echo $apiStats->display->projectedRequests; ?>
                                        </span>
                                    </li>
                                    <li>
                                        <span class="wpc-stats-footer-title">Data</span>
                                        <span class="wpc-stats-footer-box">
                                            <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/projected-stats.svg"/>
                                            <?php echo $apiStats->display->projectedBytes; ?>
                                        </span>
                                    </li>
                                </ul>
                            </div>
                        </div>
                    <?php } ?>

                </div>
                <!-- Lite Dashboard Start -->
                <div class="wpc-settings-content wpc-lite-dashboard">
                    <div class="wpc-settings-content-inner">
                        <div class="wpc-rounded-box wpc-rounded-box-full">
                            <?php
                            echo $gui::usageLiteGraph(); ?>
                        </div>
                    </div>
                    <div class="wpc-settings-content-inner">
                        <div class="wpc-rounded-box wpc-rounded-box-half">
                            <div class="wpc-box-title circle no-separator">
                                <h3>Optimization Stats</h3>
                                <?php echo $optimizationStatus; ?>
                            </div>
                            <div class="wpc-box-content">
                                <ul class="wpc-optimization-stats">
                                    <li>
                                        <?php echo $stats->getLiteStatsBox('Page Size', 'down', $optimizedStats['totalPageSizeAfter'], $optimizedStats['pageSizeSavingsPercentage'] . ' Smaller', $optimizedStats['totalPageSizeBefore']); ?>
                                    </li>
                                    <li>
                                        <?php echo $stats->getLiteStatsBox('Requests', 'down', $optimizedStats['totalRequestsAfter'], $optimizedStats['totalRequestsSavings'] . ' Less', $optimizedStats['totalRequestsBefore']); ?>
                                    </li>
                                    <li>
                                        <?php echo $stats->getLiteStatsBox('Server Response (TTFB)', 'up', $optimizedStats['totalTtfbAfter'], $optimizedStats['ttfbLess'] . ' Faster', $optimizedStats['totalTtfbBefore']); ?>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="wpc-rounded-box wpc-rounded-box-half">
                            <div class="wpc-box-title circle no-separator">
                                <h3>PageSpeed Score</h3>
                                <?php
                                if (empty($initialPageSpeedScore) && !empty(get_transient('wpc_test_running')) && !$warmupFailing) {
                                    ?>
                                    <span class="wpc-test-in-progress">Running...</span>
                                <?php } elseif(empty($initialPageSpeedScore) && $warmupFailing){
                                  ?>
                                    <div class="wpc-box-title-right">
                                        <a href="#" class="wps-ic-initial-retest">
                                            <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/refresh.svg"/>
                                        </a>
                                        <span class="wpc-test-not-going">Error, connection to API Failed.</span>
                                    </div>
                                <?php } else {
                                    $date = new DateTime();

                                    // Get the WordPress timezone
                                    $timezone = get_option('timezone_string');

                                    // Fallback if timezone_string is not set
                                    if (!$timezone) {
                                        $gmt_offset = get_option('gmt_offset');
                                        if ($gmt_offset == 0) {
                                            $timezone = 'UTC';
                                        } else {
                                            $timezone = timezone_name_from_abbr('', $gmt_offset * 3600, 0);

                                            // If timezone_name_from_abbr() fails, set default timezone
                                            if (!$timezone) {
                                                $timezone = 'UTC'; // Default to UTC to prevent errors
                                            }
                                        }
                                    }

                                    // Patch: IF-ovi su losi
                                    if (!empty($initialPageSpeedScore)) {
                                        // Apply the timezone to the DateTime object

                                        try {
                                            $date->setTimezone(new DateTimeZone($timezone));
                                        } catch (Exception $e) {
                                            #error_log("Invalid timezone: $timezone - Falling back to UTC");
                                            $date->setTimezone(new DateTimeZone('UTC')); // Default to UTC
                                        }

                                        $date->setTimestamp($initialPageSpeedScore['lastRun']);
                                        $lastRun = "Last Tested " . $date->format('F jS, Y @ g:i A');
                                        ?>
                                        <div class="wpc-box-title-right">
                                            <a href="#" class="wps-ic-initial-retest">
                                                <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/refresh.svg"/>
                                            </a>
                                            <span><?php echo $lastRun; ?></span>
                                        </div>
                                            <?php
                                    } else {
                                        $lastRun = "Running...";
                                        ?>
                                        <div class="wpc-box-title-right">
                                            <span class="wpc-test-in-progress">Running...</span>
                                        </div>
                                            <?php
                                    }
                                    ?>
                                <?php } ?>
                            </div>
                            <div class="wpc-box-content wpc-box-centered">
                                <?php
                                if (empty($options['api_key']) || (empty($initialPageSpeedScore) && !empty(get_transient('wpc_test_running')))) {
                                    ?>

                                    <div class="wpc-pagespeed-running">
                                        <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>
                                        <span>Usually takes about 2 minutes...</span>
                                    </div>
                                <?php
                                } elseif (empty($initialPageSpeedScore) && $warmupFailing){
                                    echo '<div style="padding:35px 15px;text-align: center;">';
                                    echo '<strong>Error! Seems connection to our API was blocked by Firewall on your server.</strong>';
                                    echo '<br/><br/><a href="https://help.wpcompress.com/en-us/article/whitelisting-wp-compress-for-uninterrupted-service-4dwkra/" target="_blank">Whitelisting Tutorial</a>';
                                    echo '</div>';

                                } elseif (!empty($options['api_key']) && (empty($initialPageSpeedScore) && empty(get_transient('wpc_test_running')))) {

                                $home_page_id = get_option('page_on_front');
                                ?>
                                    <script type="text/javascript">
                                        jQuery(document).ready(function ($) {
                                            $.ajax({
                                                url: ajaxurl,
                                                type: 'POST',
                                                data: {
                                                    action: 'wpsRunQuickTest',
                                                    id: 'home',
                                                    retest: 'true',
                                                    dash: 'true',
                                                    nonce: ajaxVar.nonce,
                                                }, success: function (response) {
                                                }, error: function (response) {
                                                    console.log(response);
                                                }
                                            });
                                        });
                                    </script>

                                    <div class="wpc-pagespeed-running">
                                        <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>
                                        <span>Usually takes about 2 minutes...</span>
                                    </div>
                                <?php } else {
                                /**
                                 * Possible values
                                 * $initialPageSpeedScore['desktop']['before']['performanceScore']
                                 * $initialPageSpeedScore['desktop']['before']['ttfb']
                                 * $initialPageSpeedScore['desktop']['before']['requests']
                                 * $initialPageSpeedScore['desktop']['before']['pageSize']
                                 */

                                if (!empty($initialPageSpeedScore['result'])) {
                                    $initialPageSpeedScore = $initialPageSpeedScore['result'];
                                    $beforeGPS = $initialPageSpeedScore['desktop']['before']['performanceScore'] / 100;
                                    $afterGPS = $initialPageSpeedScore['desktop']['after']['performanceScore'] / 100;
                                    $mobileBeforeGPS = $initialPageSpeedScore['mobile']['before']['performanceScore'] / 100;
                                    $mobileAfterGPS = $initialPageSpeedScore['mobile']['after']['performanceScore'] / 100;
                                    $desktopDiff = $initialPageSpeedScore['desktop']['after']['performanceScore'] - $initialPageSpeedScore['desktop']['before']['performanceScore'];
                                    $mobileDiff = $initialPageSpeedScore['mobile']['after']['performanceScore'] - $initialPageSpeedScore['mobile']['before']['performanceScore'];

                                    $desktopDiff = $desktopDiff < 0 ? 0 : '+' . $desktopDiff;
                                    $mobileDiff = $mobileDiff < 0 ? 0 : '+' . $mobileDiff;
                                } else {
                                    $afterGPS = 0;
                                    $beforeGPS = 0;
                                    $mobileAfterGPS = 0;
                                    $mobileBeforeGPS = 0;
                                    $desktopDiff = 0;
                                    $mobileDiff = 0;
                                }
                                ?>
                                    <ul class="wpc-pagespeed-score" style="">
                                        <li>
                                            <ul>
                                                <li>
                                                    <div class="wpc-gps-info-box">
                                                        <div class="wpc-gps-info-icon">
                                                            <img src="<?php echo WPS_IC_ASSETS . '/lite/images/mobile-icon.svg'; ?>"
                                                                 alt="Mobile GPS"/>
                                                        </div>
                                                        <div class="wpc-gps-info-text">
                                                            Mobile
                                                        </div>
                                                        <div class="wpc-gps-improvement">
                                                            <div class="wpc-stats-improvement">
                                                                <span class="wpc-stats-improvement-icon">
                                                                    <img src="<?php echo WPS_IC_ASSETS . '/lite/images/arrow-up.svg'; ?>"/>
                                                                </span>
                                                                <span class="wpc-stats-improvement-text"><?php echo $mobileDiff; ?> points</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </li>
                                                <li>
                                                    <div class="page-stats-circle-container">
                                                        <div class="wpc-stats-before">
                                                            <span class="wpc-stats-improvement-icon">
                                                                <img src="<?php echo WPS_IC_ASSETS . '/lite/images/gps-before.svg'; ?>"/>
                                                            </span>
                                                            <span class="wpc-stats-improvement-text">
                                                                Before
                                                            </span>
                                                        </div>
                                                        <div class="page-stats-circle">
                                                            <div class="circle-progress-bar-lite"
                                                                 data-value="<?php echo $mobileBeforeGPS; ?>"></div>
                                                            <div class="stats-circle-text">
                                                                <h5><?php echo $mobileBeforeGPS; ?></h5>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="page-stats-circle-container-small">
                                                        <img src="<?php echo WPS_IC_ASSETS . '/lite/images/small-arrow.svg'; ?>"/>
                                                    </div>
                                                    <div class="page-stats-circle-container">
                                                        <div class="wpc-stats-before">
                                                            <span class="wpc-stats-improvement-icon">
                                                                <img src="<?php echo WPS_IC_ASSETS . '/lite/images/gps-after.svg'; ?>"/>
                                                            </span>
                                                            <span class="wpc-stats-improvement-text">
                                                                After
                                                            </span>
                                                        </div>
                                                        <div class="page-stats-circle">
                                                            <div class="circle-progress-bar-lite"
                                                                 data-value="<?php echo $mobileAfterGPS; ?>"></div>
                                                            <div class="stats-circle-text">
                                                                <h5><?php echo $mobileAfterGPS; ?></h5>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </li>
                                            </ul>
                                        </li>
                                        <li>
                                            <ul>
                                                <li>
                                                    <div class="wpc-gps-info-box">
                                                        <div class="wpc-gps-info-icon">
                                                            <img src="<?php echo WPS_IC_ASSETS . '/lite/images/desktop-icon.svg'; ?>"
                                                                 alt="Desktop GPS"/>
                                                        </div>
                                                        <div class="wpc-gps-info-text">
                                                            Desktop
                                                        </div>
                                                        <div class="wpc-gps-improvement">
                                                            <div class="wpc-stats-improvement">
                                                                <span class="wpc-stats-improvement-icon">
                                                                    <img src="<?php echo WPS_IC_ASSETS . '/lite/images/arrow-up.svg'; ?>"/>
                                                                </span>
                                                                <span class="wpc-stats-improvement-text"><?php echo $desktopDiff; ?> points</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </li>
                                                <li>
                                                    <div class="page-stats-circle-container">
                                                        <div class="wpc-stats-before">
                                                            <span class="wpc-stats-improvement-icon">
                                                                <img src="<?php echo WPS_IC_ASSETS . '/lite/images/gps-before.svg'; ?>"/>
                                                            </span>
                                                            <span class="wpc-stats-improvement-text">
                                                                Before
                                                            </span>
                                                        </div>
                                                        <div class="page-stats-circle">
                                                            <div class="circle-progress-bar-lite"
                                                                 data-value="<?php echo $beforeGPS; ?>"></div>
                                                            <div class="stats-circle-text">
                                                                <h5><?php echo $beforeGPS; ?></h5>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="page-stats-circle-container-small">
                                                        <img src="<?php echo WPS_IC_ASSETS . '/lite/images/small-arrow.svg'; ?>"/>
                                                    </div>
                                                    <div class="page-stats-circle-container">
                                                        <div class="wpc-stats-before">
                                                            <span class="wpc-stats-improvement-icon">
                                                                <img src="<?php echo WPS_IC_ASSETS . '/lite/images/gps-after.svg'; ?>"/>
                                                            </span>
                                                            <span class="wpc-stats-improvement-text">
                                                                After
                                                            </span>
                                                        </div>
                                                        <div class="page-stats-circle">
                                                            <div class="circle-progress-bar-lite"
                                                                 data-value="<?php echo $afterGPS; ?>"></div>
                                                            <div class="stats-circle-text">
                                                                <h5><?php echo $afterGPS; ?></h5>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </li>
                                            </ul>
                                        </li>
                                    </ul>
                                <?php } ?>
                                <?php
                                if (empty($option) || (!empty($option['version']) && $option['version'] == 'lite'  && !get_option('hide_wpcompress_plugin'))) {
                                    ?>
                                    <div class="wpc-page-speed-footer">
                                        <div class="wpc-ps-f-left">
                                            <span>Unlock even more power with <strong>PRO</strong> Access!</span>
                                        </div>
                                        <div class="wpc-ps-f-right">
                                            <a href="https://wpcompress.com/go/plans/" target="_blank"
                                               class="wpc-custom-btn">
                                                <div>
                                                    <img src="<?php echo WPS_IC_ASSETS . '/lite/images/checkbox-link.svg'; ?>"/>
                                                </div>
                                                <div>View Plans</div>
                                            </a>
                                        </div>
                                    </div>
                                <?php } else {
                                    if (!empty($afterGPS) && !empty($mobileAfterGPS)) {
                                        if ($beforeGPS <= $afterGPS || $mobileAfterGPS <= $mobileBeforeGPS) {
                                            ?>
                                            <div class="wpc-page-speed-footer">
                                                <div class="wpc-ps-f-left">
                                                    <div class="wpc-badge-container">
                                                        <p><img src="<?php echo WPS_IC_ASSETS . '/lite/images/wohoo.png'; ?>"/> Woohoo! Your Website is Now Loading Faster!</p>
                                                        <span class="wpc-badge-success"><img src="<?php echo WPS_IC_ASSETS . '/lite/images/checkbox-link.svg'; ?>"/> Site Optimized</span>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php }
                                    } else {
                                        if (!empty($initialPageSpeedScore['failed']) && $initialPageSpeedScore['failed'] == 'true') {
                                            ?>
                                <div class="wpc-page-speed-footer">
                                <div class="wpc-ps-f-left">
                                    <div class="wpc-badge-container">
                                        <p style="text-align: center;font-weight: bold;font-family: 'proxima_semibold';">Ooops! Seems we had some issues with testing your site! Please retry!</p>
                                    </div>
                                </div>
                            </div>
                                <?php
                                        }
                                    }
                                } ?>
                            </div>
                        </div>
                    </div>
                    <?php
                    if (empty($option) || (!empty($option['version']) && $option['version'] == 'lite')) {
                        ?>
                        <div class="wpc-settings-content-inner" style="display: none;">
                            <div class="wpc-rounded-box wpc-rounded-box-full">
                                <div class="wpc-box-content">
                                    <div class="wpc-box-content-inner">
                                        <div class="wpc-box-content-icon">
                                            <img src="<?php echo WPS_IC_ASSETS . '/v4/images/wpc-logo.svg'; ?>"
                                                 alt="Go Pro for Portal Access"/>
                                        </div>
                                        <div class="wpc-box-content-text">
                                            <h3>Go PRO for Portal Access</h3>
                                            <p>Get image optimization access, CDN Delivery, remote configuration and
                                                more by creating an account!</p>
                                        </div>
                                        <div class="wpc-box-content-button">
                                            <a href="#" class="wpc-add-access-key-btn">
                                                <div>
                                                    <img src="<?php echo WPS_IC_ASSETS . '/lite/images/checkbox-link.svg'; ?>"/>
                                                </div>
                                                <div>Add Access Key</div>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>
                </div>
            </div>
            <!-- Body End -->

        </form>
    </div>
<?php include WPS_IC_DIR . 'templates/admin/partials/v4/footer-scripts.php'; ?>
<?php include WPS_IC_DIR . 'templates/admin/connect/lite-api-locked.php'; ?>
<?php include WPS_IC_DIR . 'templates/admin/connect/lite-api-upgrade.php'; ?>