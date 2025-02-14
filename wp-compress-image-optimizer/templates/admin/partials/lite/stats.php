<?php

global $wps_ic;

$user_credits = self::$user_credits;
$stats_live = self::$stats_live;
$stats_local = self::$stats_local;
$stats_local_sum = self::$stats_local_sum;

/**
 * Quick fix for PHP undefined notices
 */
$wps_ic_active_settings['optimization']['lossless'] = '';
$wps_ic_active_settings['optimization']['intelligent'] = '';
$wps_ic_active_settings['optimization']['ultra'] = '';

/**
 * Decides which setting is active
 */
if (!empty($wps_ic::$settings['optimization'])) {
    if ($wps_ic::$settings['optimization'] == 'lossless') {
        $wps_ic_active_settings['optimization']['lossless'] = 'class="current"';
    } elseif ($wps_ic::$settings['optimization'] == 'intelligent') {
        $wps_ic_active_settings['optimization']['intelligent'] = 'class="current"';
    } else {
        $wps_ic_active_settings['optimization']['ultra'] = 'class="current"';
    }
} else {
    $wps_ic_active_settings['optimization']['intelligent'] = 'class="current"';
}

// Lite
$options = get_option(WPS_IC_OPTIONS);
$gui = new wpc_gui_v4();
$stats = new wps_ic_stats();
$apiStats = $stats->getApiStats();
$optimizedStats = $stats->getOptimizedStats();
$optimizationStatus = $stats->getLiteOptimizationStatus($optimizedStats);

$settings = get_option(WPS_IC_SETTINGS);
$initialPageSpeedScore = get_option(WPS_IC_LITE_GPS);
$initialTestRunning = get_transient('wpc_initial_test');
$option = get_option(WPS_IC_OPTIONS);

$warmup_class = new wps_ic_preload_warmup();
$warmupFailing = $warmup_class->isWarmupFailing();
?>
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
            <?php if (empty($initialPageSpeedScore) && !empty($initialTestRunning)) { ?>
                <span class="wpc-test-in-progress">Running...</span>
            <?php } elseif (empty($initialPageSpeedScore) && $warmupFailing){ ?>
                <span class="wpc-test-in-progress"><a href="#" class="wps-ic-initial-retest">
                        <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/refresh.svg"/>
                    </a> Error, warmup not going.</span>
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

                if (empty($initialPageSpeedScore['lastRun'])) {
                    $lastRun = '';
                } else {
                    // Apply the timezone to the DateTime object
                    $date->setTimezone(new DateTimeZone($timezone));
                    $date->setTimestamp($initialPageSpeedScore['lastRun']);
                    $lastRun = "Last Tested " . $date->format('F jS, Y @ g:i A');
                }
                ?>
                <div class="wpc-box-title-right">
                    <a href="#" class="wps-ic-initial-retest">
                        <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/refresh.svg"/>
                    </a>
                    <span><?php echo $lastRun; ?></span>
                </div>
            <?php } ?>
        </div>
        <div class="wpc-box-content">
            <?php
            if (empty($options['api_key'])) {
                ?>

                <div class="wpc-pagespeed-running">
                    <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>
                    <span>Usually takes about 2 minutes...</span>
                </div>
            <?php
            } elseif ($warmupFailing){
                echo '<div style="padding:35px 15px;text-align: center;">';
                echo '<strong>Error! Seems connection to our API was blocked by Firewall on your server.</strong>';
                echo '<br/><br/><a href="https://help.wpcompress.com/en-us/article/whitelisting-wp-compress-for-uninterrupted-service-4dwkra/" target="_blank">Whitelisting Tutorial</a>';
                echo '</div>';
            } elseif (!empty($options['api_key']) && (empty($initialPageSpeedScore) || !empty($initialTestRunning))) {
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
                                console.log('Optimized');
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
            $initialPageSpeedScore = $initialPageSpeedScore['result'];
            $beforeGPS = $initialPageSpeedScore['desktop']['before']['performanceScore'] / 100;
            $afterGPS = $initialPageSpeedScore['desktop']['after']['performanceScore'] / 100;
            $mobileBeforeGPS = $initialPageSpeedScore['mobile']['before']['performanceScore'] / 100;
            $mobileAfterGPS = $initialPageSpeedScore['mobile']['after']['performanceScore'] / 100;
            $desktopDiff = $initialPageSpeedScore['desktop']['after']['performanceScore'] - $initialPageSpeedScore['desktop']['before']['performanceScore'];
            $mobileDiff = $initialPageSpeedScore['mobile']['after']['performanceScore'] - $initialPageSpeedScore['mobile']['before']['performanceScore'];

            $desktopDiff = $desktopDiff < 0 ? 0 : '+' . $desktopDiff;
            $mobileDiff = $mobileDiff < 0 ? 0 : '+' . $mobileDiff;
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
            if (empty($option) || (!empty($option['version']) && $option['version'] == 'lite' && !get_option('hide_wpcompress_plugin'))) {
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
                                <?php

                                //                                            $beforeMobileGPSCalc = $mobileBeforeGPS*100;
                                //                                            $afterMobileGPSCalc = $mobileAfterGPS*100;
                                //                                            $beforeGPSCalc = $beforeGPS*100;
                                //                                            $afterGPSCalc = $afterGPS*100;
                                //
                                //                                            $mobileDiff = $afterMobileGPSCalc-$beforeMobileGPSCalc;
                                //                                            $desktopDiff = $afterGPSCalc-$beforeGPSCalc;
                                //
                                //                                            if ($mobileDiff <= 10) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            } else if ($mobileDiff <= 20) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            } else if ($mobileDiff <= 30) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            } else if ($mobileDiff <= 40) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            } else if ($mobileDiff <= 50) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            } else if ($mobileDiff <= 60) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            }
                                //
                                //                                            if ($desktopDiff <= 10) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            } else if ($desktopDiff <= 20) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            } else if ($desktopDiff <= 30) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            } else if ($desktopDiff <= 40) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            } else if ($desktopDiff <= 50) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            } else if ($desktopDiff <= 60) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            }
                                //
                                //                                            echo '!';

                                ?>
                            </div>
                        </div>
                    <?php }
                } else {
                    if (!empty($initialPageSpeedScore['failed']) && $initialPageSpeedScore['failed'] == 'true') { ?>
                        <div class="wpc-page-speed-footer">
                            <div class="wpc-ps-f-left">
                                <div class="wpc-badge-container">
                                    <p style="text-align: center;font-weight: bold;font-family: 'proxima_semibold';">Ooops! Seems we had some issues with testing your site! Please retry!</p>
                                </div>
                                <?php

                                //                                            $beforeMobileGPSCalc = $mobileBeforeGPS*100;
                                //                                            $afterMobileGPSCalc = $mobileAfterGPS*100;
                                //                                            $beforeGPSCalc = $beforeGPS*100;
                                //                                            $afterGPSCalc = $afterGPS*100;
                                //
                                //                                            $mobileDiff = $afterMobileGPSCalc-$beforeMobileGPSCalc;
                                //                                            $desktopDiff = $afterGPSCalc-$beforeGPSCalc;
                                //
                                //                                            if ($mobileDiff <= 10) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            } else if ($mobileDiff <= 20) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            } else if ($mobileDiff <= 30) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            } else if ($mobileDiff <= 40) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            } else if ($mobileDiff <= 50) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            } else if ($mobileDiff <= 60) {
                                //                                                echo 'Congrats! You\'ve improved your mobile score by '.$mobileDiff;
                                //                                            }
                                //
                                //                                            if ($desktopDiff <= 10) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            } else if ($desktopDiff <= 20) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            } else if ($desktopDiff <= 30) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            } else if ($desktopDiff <= 40) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            } else if ($desktopDiff <= 50) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            } else if ($desktopDiff <= 60) {
                                //                                                echo 'and your desktop score by '.$desktopDiff;
                                //                                            }
                                //
                                //                                            echo '!';

                                ?>
                            </div>
                        </div>
                        <?php
                    }
                }
            } ?>
        </div>
    </div>
</div>