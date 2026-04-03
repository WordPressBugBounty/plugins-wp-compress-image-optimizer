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

// ─── V2 Data Setup ───
$v2_gps = get_option(WPS_IC_LITE_GPS);
$v2_hasGPS = false;
if (!empty($v2_gps) && !empty($v2_gps['result'])) {
    $v2_hasGPS = true;
    $v2_result = $v2_gps['result'];
    $v2_beforeGPS = $v2_result['desktop']['before']['performanceScore'] / 100;
    $v2_afterGPS = $v2_result['desktop']['after']['performanceScore'] / 100;
    $v2_mobileBeforeGPS = $v2_result['mobile']['before']['performanceScore'] / 100;
    $v2_mobileAfterGPS = $v2_result['mobile']['after']['performanceScore'] / 100;
    $v2_desktopDiff = $v2_result['desktop']['after']['performanceScore'] - $v2_result['desktop']['before']['performanceScore'];
    $v2_mobileDiff = $v2_result['mobile']['after']['performanceScore'] - $v2_result['mobile']['before']['performanceScore'];
    if ($v2_desktopDiff <= 0) { $v2_desktopDiff = 'Perfect Score'; } else { $v2_desktopDiff = '+' . $v2_desktopDiff; }
    if ($v2_mobileDiff <= 0) { $v2_mobileDiff = 'Perfect Score'; } else { $v2_mobileDiff = '+' . $v2_mobileDiff; }
    $v2_date = new DateTime();
    $v2_tz = get_option('timezone_string');
    if (!$v2_tz) {
        $v2_offset = get_option('gmt_offset');
        $v2_tz = $v2_offset == 0 ? 'UTC' : timezone_name_from_abbr('', $v2_offset * 3600, 0);
        if (!$v2_tz) $v2_tz = 'UTC';
    }
    try { $v2_date->setTimezone(new DateTimeZone($v2_tz)); } catch (Exception $e) { $v2_date->setTimezone(new DateTimeZone('UTC')); }
    $v2_date->setTimestamp($v2_gps['lastRun']);
    $v2_lastRun = $v2_date->format('F jS, Y \a\t g:i A');
}
?>

<div class="wpc-settings-content-inner wpc-v2-section">
    <!-- ─── Performance Overview ─── -->
    <div class="wpc-v2-card">
        <div class="wpc-v2-card-header">
            <div class="wpc-v2-title">
                <span class="wpc-v2-dot"></span>
                <h3><?php esc_html_e('Optimization Stats', WPS_IC_TEXTDOMAIN); ?></h3>
            </div>
            <?php echo $optimizationStatus; ?>
        </div>
        <?php
        // Show skeleton placeholders when no meaningful data exists
        $v2_testRunning = !empty(get_transient('wpc_initial_test')) || !empty(get_transient('wpc_test_running'));
        $v2_noTestData = empty(get_option(WPS_IC_TESTS));
        // Also show skeleton when stats exist but are all zeros (e.g. "0 B", "0.0 kB")
        $v2_pageSizeNum = floatval(preg_replace('/[^0-9.]/', '', $optimizedStats['totalPageSizeAfter']));
        $v2_requestsNum = floatval(preg_replace('/[^0-9.]/', '', $optimizedStats['totalRequestsAfter']));
        $v2_hasZeroData = ($v2_pageSizeNum == 0 || $v2_requestsNum == 0);
        $v2_showSkeleton = ($v2_testRunning || $v2_noTestData || $v2_hasZeroData);

        if ($v2_showSkeleton) { ?>
        <div class="wpc-v2-stats-row wpc-v2-skeleton-row">
            <?php
            $v2_labels = array(esc_html__('Page Size', WPS_IC_TEXTDOMAIN), esc_html__('Requests', WPS_IC_TEXTDOMAIN), esc_html__('Server Speed', WPS_IC_TEXTDOMAIN));
            foreach ($v2_labels as $v2_label) { ?>
                <div class="wpc-v2-stat-box wpc-v2-skeleton-box">
                    <span class="wpc-v2-stat-label"><?php echo $v2_label; ?></span>
                    <div class="wpc-v2-skeleton-value">
                        <div class="loading-icon"><div class="inner"></div></div>
                    </div>
                    <div class="wpc-v2-skeleton-badge">
                        <div class="wpc-ic-small-thick-placeholder" style="width:90px;"></div>
                    </div>
                    <div class="wpc-v2-stat-sep"></div>
                    <div class="wpc-v2-skeleton-before">
                        <div class="wpc-ic-small-thick-placeholder" style="width:70px;"></div>
                    </div>
                </div>
            <?php } ?>
        </div>
        <?php } else { ?>
        <div class="wpc-v2-stats-row">
            <?php
            $noChange = esc_html__('No Change', WPS_IC_TEXTDOMAIN);
            $v2_stats = array(
                array('label' => esc_html__('Page Size', WPS_IC_TEXTDOMAIN), 'value' => $optimizedStats['totalPageSizeAfter'], 'badge' => (empty($optimizedStats['pageSizeSavingsPercentage']) || $optimizedStats['pageSizeSavingsPercentage'] === '0%' || $optimizedStats['pageSizeSavingsPercentage'] === '0') ? $noChange : $optimizedStats['pageSizeSavingsPercentage'] . ' ' . esc_html__('Smaller', WPS_IC_TEXTDOMAIN), 'arrow' => 'down', 'before' => $optimizedStats['totalPageSizeBefore']),
                array('label' => esc_html__('Requests', WPS_IC_TEXTDOMAIN), 'value' => $optimizedStats['totalRequestsAfter'], 'badge' => (empty($optimizedStats['totalRequestsSavings']) || $optimizedStats['totalRequestsSavings'] === '0' || $optimizedStats['totalRequestsSavings'] === 0) ? $noChange : $optimizedStats['totalRequestsSavings'] . ' ' . esc_html__('Less', WPS_IC_TEXTDOMAIN), 'arrow' => 'down', 'before' => $optimizedStats['totalRequestsBefore']),
                array('label' => esc_html__('Server Speed', WPS_IC_TEXTDOMAIN), 'value' => $optimizedStats['totalTtfbAfter'], 'badge' => (empty($optimizedStats['ttfbLess']) || $optimizedStats['ttfbLess'] === '0' || $optimizedStats['ttfbLess'] === 0) ? $noChange : $optimizedStats['ttfbLess'] . ' ' . esc_html__('Faster', WPS_IC_TEXTDOMAIN), 'arrow' => 'up', 'before' => $optimizedStats['totalTtfbBefore']),
            );
            $v2_i = 0;
            foreach ($v2_stats as $v2_stat) {
                preg_match('/([0-9.]+)\s*([a-zA-Z%]*)/', $v2_stat['value'], $v2_m);
                $v2_arrow = $v2_stat['arrow'] === 'down'
                    ? '<svg class="wpc-v2-badge-arrow" viewBox="0 0 448 512" fill="currentColor"><path d="M207.5 505l17 17 17-17 168-168 17-17-33.9-33.9-17 17-127 127 0-430.1-48 0 0 430.1-127-127-17-17-33.9 33.9 17 17 168 168z"/></svg>'
                    : '<svg class="wpc-v2-badge-arrow" viewBox="0 0 448 512" fill="currentColor"><path d="M241.4 7l-17-17-17 17-185 185 33.9 33.9 17-17 127-127 0 430.1 48 0 0-430.1 127 127 17 17 33.9-33.9-17-17-168-168z"/></svg>';
                ?>
                <div class="wpc-v2-stat-box">
                    <span class="wpc-v2-stat-label"><?php echo $v2_stat['label']; ?></span>
                    <div class="wpc-v2-stat-value">
                        <img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/stats-speed.svg" class="wpc-v2-stat-icon" />
                        <span class="wpc-v2-stat-num"><?php echo isset($v2_m[1]) ? $v2_m[1] : $v2_stat['value']; ?></span>
                        <?php if (!empty($v2_m[2])) { ?><span class="wpc-v2-stat-unit"><?php echo $v2_m[2]; ?></span><?php } ?>
                    </div>
                    <div class="wpc-v2-badge">
                        <?php echo $v2_arrow; ?>
                        <span><?php echo $v2_stat['badge']; ?></span>
                    </div>
                    <div class="wpc-v2-stat-sep"></div>
                    <div class="wpc-v2-stat-before">
                        <img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/gps-before.svg" />
                        <span><?php echo sprintf(esc_html__('was %s', WPS_IC_TEXTDOMAIN), $v2_stat['before']); ?></span>
                    </div>
                </div>
                <?php
                $v2_i++;
            }
            ?>
        </div>
        <?php } ?>
    </div>

    <!-- ─── Performance Score ─── -->
    <div class="wpc-v2-card">
        <div class="wpc-v2-card-header">
            <div class="wpc-v2-title">
                <span class="wpc-v2-dot wpc-v2-dot-green"></span>
                <h3><?php esc_html_e('PageSpeed Score', WPS_IC_TEXTDOMAIN); ?></h3>
            </div>
            <?php if ($v2_hasGPS) { ?>
            <div class="wpc-v2-header-meta">
                <span class="wpc-v2-meta-date"><?php echo $v2_lastRun; ?></span>
                <a href="#" class="wps-ic-initial-retest wpc-v2-retest-btn">
                    <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/refresh.svg"/>
                    <?php esc_html_e('Retest', WPS_IC_TEXTDOMAIN); ?>
                </a>
            </div>
            <?php } ?>
        </div>
        <?php if ($v2_hasGPS) { ?>
        <div class="wpc-v2-scores">
            <?php
            $v2_devices = array(
                array('name' => esc_html__('Mobile', WPS_IC_TEXTDOMAIN), 'icon' => WPS_IC_ASSETS . '/lite/images/mobile-icon.svg', 'before' => $v2_mobileBeforeGPS, 'after' => $v2_mobileAfterGPS, 'diff' => $v2_mobileDiff),
                array('name' => esc_html__('Desktop', WPS_IC_TEXTDOMAIN), 'icon' => WPS_IC_ASSETS . '/lite/images/desktop-icon.svg', 'before' => $v2_beforeGPS, 'after' => $v2_afterGPS, 'diff' => $v2_desktopDiff),
            );
            $v2_di = 0;
            foreach ($v2_devices as $v2_dev) {
                if ($v2_di > 0) echo '<div class="wpc-v2-score-sep"></div>';
                $isPerfect = ($v2_dev['diff'] === 'Perfect Score');
                ?>
                <div class="wpc-v2-score-col">
                    <div class="wpc-v2-device-header">
                        <div class="wpc-v2-device-ico">
                            <img src="<?php echo $v2_dev['icon']; ?>" alt="<?php echo $v2_dev['name']; ?>" />
                        </div>
                        <span class="wpc-v2-device-name"><?php echo $v2_dev['name']; ?></span>
                        <div class="wpc-v2-score-pill<?php echo $isPerfect ? ' wpc-v2-pill-perfect' : ''; ?>">
                            <?php if ($isPerfect) { ?>
                                <svg class="wpc-v2-pill-icon" viewBox="0 0 576 512" fill="currentColor"><path d="M318.3 82.1c6.1-7 9.7-16.1 9.7-26.1 0-22.1-17.9-40-40-40s-40 17.9-40 40c0 10 3.7 19.1 9.7 26.1L184.1 192.6 95 145.1c.7-2.9 1-5.9 1-9.1 0-22.1-17.9-40-40-40s-40 17.9-40 40c0 20.2 15 36.9 34.4 39.6L86.8 375.7c7.6 41.8 44.1 72.3 86.6 72.3l229.2 0c42.5 0 79-30.4 86.6-72.3l36.4-200.1c19.5-2.7 34.4-19.4 34.4-39.6 0-22.1-17.9-40-40-40s-40 17.9-40 40c0 3.1 .4 6.1 1 9.1l-89.1 47.5-73.6-110.4zM212 237.3l76-114 76 114c6.8 10.3 20.4 13.7 31.3 7.9l76.2-40.6-29.6 162.6c-3.5 19-20 32.8-39.4 32.8l-229.2 0c-19.3 0-35.9-13.8-39.4-32.8l-29.6-162.6 76.2 40.6c10.9 5.8 24.4 2.4 31.3-7.9z"/></svg>
                            <?php } else { ?>
                                <svg class="wpc-v2-pill-icon" viewBox="0 0 576 512" fill="currentColor"><path d="M352 120c0-13.3 10.7-24 24-24l176 0c13.3 0 24 10.7 24 24l0 176c0 13.3-10.7 24-24 24s-24-10.7-24-24l0-118.1-191 191c-9.4 9.4-24.6 9.4-33.9 0L192 257.9 41 409c-9.4 9.4-24.6 9.4-33.9 0S-2.3 384.4 7 375L175 207c9.4-9.4 24.6-9.4 33.9 0L320 318.1 494.1 144 376 144c-13.3 0-24-10.7-24-24z"/></svg>
                            <?php } ?>
                            <span><?php echo $isPerfect ? esc_html__('Perfect Score', WPS_IC_TEXTDOMAIN) : $v2_dev['diff'] . ' ' . esc_html__('Points', WPS_IC_TEXTDOMAIN); ?></span>
                        </div>
                    </div>
                    <?php
                    $beforeScore = round($v2_dev['before'] * 100);
                    $afterScore = round($v2_dev['after'] * 100);
                    // SVG circle math
                    $smSize = 50; $smR = 21; $smStroke = 5; $smCirc = 2 * M_PI * $smR;
                    $lgSize = 50; $lgR = 21; $lgStroke = 5; $lgCirc = 2 * M_PI * $lgR;
                    $smOffset = $smCirc - ($smCirc * $v2_dev['before']);
                    $lgOffset = $lgCirc - ($lgCirc * $v2_dev['after']);
                    // Color by score
                    $smColor = $beforeScore <= 55 ? '#ef4444' : ($beforeScore <= 89 ? '#f59e0b' : '#22c55e');
                    $smBg = $beforeScore <= 55 ? '#fee2e2' : ($beforeScore <= 89 ? '#fef3c7' : '#dcfce7');
                    $lgColor = $afterScore <= 55 ? '#ef4444' : ($afterScore <= 89 ? '#f59e0b' : '#22c55e');
                    $lgBg = $afterScore <= 55 ? '#fee2e2' : ($afterScore <= 89 ? '#fef3c7' : '#dcfce7');
                    ?>
                    <div class="wpc-v2-circles">
                        <div class="wpc-v2-circle wpc-v2-circle-sm">
                            <div class="wpc-v2-label-before">
                                <img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/gps-before.svg" />
                                <span><?php esc_html_e('Before', WPS_IC_TEXTDOMAIN); ?></span>
                            </div>
                            <div class="wpc-v2-ring">
                                <svg width="<?php echo $smSize; ?>" height="<?php echo $smSize; ?>" viewBox="0 0 <?php echo $smSize; ?> <?php echo $smSize; ?>">
                                    <circle cx="<?php echo $smSize/2; ?>" cy="<?php echo $smSize/2; ?>" r="<?php echo $smR; ?>" fill="none" stroke="<?php echo $smBg; ?>" stroke-width="<?php echo $smStroke; ?>"/>
                                    <circle cx="<?php echo $smSize/2; ?>" cy="<?php echo $smSize/2; ?>" r="<?php echo $smR; ?>" fill="none" stroke="<?php echo $smColor; ?>" stroke-width="<?php echo $smStroke; ?>" stroke-linecap="round" stroke-dasharray="<?php echo $smCirc; ?>" stroke-dashoffset="<?php echo $smOffset; ?>" transform="rotate(-90 <?php echo $smSize/2; ?> <?php echo $smSize/2; ?>)"/>
                                </svg>
                                <div class="wpc-v2-ring-text"><span><?php echo $beforeScore; ?></span></div>
                            </div>
                        </div>
                        <div class="wpc-v2-circle-arrow">
                            <img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/small-arrow.svg" />
                        </div>
                        <div class="wpc-v2-circle wpc-v2-circle-lg">
                            <div class="wpc-v2-label-after">
                                <img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/gps-after.svg" />
                                <span><?php esc_html_e('After', WPS_IC_TEXTDOMAIN); ?></span>
                            </div>
                            <div class="wpc-v2-ring wpc-v2-ring-hero">
                                <svg width="<?php echo $lgSize; ?>" height="<?php echo $lgSize; ?>" viewBox="0 0 <?php echo $lgSize; ?> <?php echo $lgSize; ?>">
                                    <circle cx="<?php echo $lgSize/2; ?>" cy="<?php echo $lgSize/2; ?>" r="<?php echo $lgR; ?>" fill="none" stroke="<?php echo $lgBg; ?>" stroke-width="<?php echo $lgStroke; ?>"/>
                                    <circle cx="<?php echo $lgSize/2; ?>" cy="<?php echo $lgSize/2; ?>" r="<?php echo $lgR; ?>" fill="none" stroke="<?php echo $lgColor; ?>" stroke-width="<?php echo $lgStroke; ?>" stroke-linecap="round" stroke-dasharray="<?php echo $lgCirc; ?>" stroke-dashoffset="<?php echo $lgOffset; ?>" transform="rotate(-90 <?php echo $lgSize/2; ?> <?php echo $lgSize/2; ?>)"/>
                                </svg>
                                <div class="wpc-v2-ring-text"><span><?php echo $afterScore; ?></span></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php
                $v2_di++;
            }
            ?>
        </div>
        <?php if (!empty($option['version']) && $option['version'] != 'lite') { ?>
        <div class="wpc-v2-score-footer">
            <div class="wpc-v2-footer-msg">
                <img src="<?php echo WPS_IC_ASSETS . '/lite/images/wohoo.png'; ?>" alt="" />
                <strong><?php esc_html_e('Woohoo!', WPS_IC_TEXTDOMAIN); ?></strong> <?php esc_html_e('Your Website is Now Loading Faster!', WPS_IC_TEXTDOMAIN); ?>
            </div>
            <div class="wpc-v2-footer-pill">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 512 512" style="vertical-align:-1px;fill:currentColor"><path d="M256 512a256 256 0 1 1 0-512 256 256 0 1 1 0 512zM374 145.7c-10.7-7.8-25.7-5.4-33.5 5.3L221.1 315.2 169 263.1c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l72 72c5 5 11.8 7.5 18.8 7s13.4-4.1 17.5-9.8L379.3 179.2c7.8-10.7 5.4-25.7-5.3-33.5z"/></svg>
                <span><?php esc_html_e('Optimized', WPS_IC_TEXTDOMAIN); ?></span>
            </div>
        </div>
        <?php } ?>
        <?php } else { ?>
        <div class="wpc-v2-scores-loading">
            <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>
            <span><?php esc_html_e('Analyzing performance...', WPS_IC_TEXTDOMAIN); ?></span>
        </div>
        <?php } ?>
    </div>
</div>
