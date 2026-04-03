<?php
global $wps_ic, $wpdb;

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wpc_settings_save_nonce'], 'wpc_settings_save')) {
        die(esc_html__('Forbidden.', WPS_IC_TEXTDOMAIN));
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
    $sentSettings = $_POST['options'];

    $optionsClass = new wps_ic_options();
    $defaultSettings = $optionsClass->getDefault();


    $newSettings = $settings;


    if (!empty($sentSettings['delay-js-v2']) && $sentSettings['delay-js-v2'] == '1' ){
        $newSettings['delay-js-v2'] = '1';
        $newSettings['delay-js'] = '1';
    } else {
        $newSettings['delay-js-v2'] = '0';
        $newSettings['delay-js'] = '0';
    }

    if (isset($sentSettings['imagesPreset']) && $sentSettings['imagesPreset'] == '1') {
        $newSettings['retina'] = '1';
        $newSettings['generate_adaptive'] = '1';
        $newSettings['generate_webp'] = '1';
        $newSettings['imagesPreset'] = '1';
    } else {
        $newSettings['retina'] = '0';
        $newSettings['generate_adaptive'] = '0';
        $newSettings['generate_webp'] = '0';
        $newSettings['imagesPreset'] = '0';
    }

    if (isset($sentSettings['cdnAll']) && $sentSettings['cdnAll'] == '1') {
        $newSettings['live-cdn'] = '1';
        $newSettings['serve'] = ['jpg' => '1', 'gif' => '1', 'png' => '1', 'svg' => '1'];
        $newSettings['css'] = '1';
        $newSettings['js'] = '1';
        $newSettings['fonts'] = '1';
        $newSettings['qualityLevel'] = 'intelligent';
        $newSettings['cdnAll'] = '1';
    } else {
        $newSettings['live-cdn'] = '0';
        $newSettings['serve'] = ['jpg' => '0', 'gif' => '0', 'png' => '0', 'svg' => '0'];
        $newSettings['css'] = '0';
        $newSettings['js'] = '0';
        $newSettings['fonts'] = '0';
        $newSettings['cdnAll'] = '0';
    }

    if (isset($sentSettings['critical']['css']) && $sentSettings['critical']['css'] == '1') {
        $newSettings['critical']['css'] = '1';
    } else {
        $newSettings['critical']['css'] = '0';
    }

    if (isset($sentSettings['nativeLazy']) && $sentSettings['nativeLazy'] == '1') {
        $newSettings['nativeLazy'] = '1';
    } else {
        $newSettings['nativeLazy'] = '0';
    }

    if (isset($sentSettings['cache']['advanced']) && $sentSettings['cache']['advanced'] == '1') {
        $newSettings['cache']['advanced'] = '1';
    } else {
        $newSettings['cache']['advanced'] = '0';
    }


    update_option(WPS_IC_SETTINGS, $newSettings);

    $cache = new wps_ic_cache_integrations();

    // Get Purge List
    $options_class = new wps_ic_options();
    $purgeList = $options_class->getPurgeList($options);

    $cache::purgeAll(false, false, false, false); //this only clears cache files
    //To edit what setting purges what, go to wps_ic_options->__construct()
    if (in_array('combine', $purgeList)) {
        $cache::purgeCombinedFiles();
    }

    if (in_array('critical', $purgeList)) {
        $cache::purgeCriticalFiles();
    }

    if (in_array('cdn', $purgeList)) {
        $cacheLogic = new wps_ic_cache();
        $cacheLogic->purgeCDN(false);
	    $cache::purgeCriticalFiles();
	    $cache::purgePreloads();
    }

    if (!class_exists('wps_ic_htaccess')) {
        include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }

    $htacces = new wps_ic_htaccess();

    if (!empty($options['cache']['advanced']) && $options['cache']['advanced'] == '1') {

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

$cf = get_option(WPS_IC_CF);

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
                            // Nothing
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
    <?php $isLiteMode = (empty($options['api_key']) || (!empty($options['version']) && $options['version'] == 'lite')); ?>
    <div class="wpc-advanced-settings-container wpc-lite-settings-container wps_ic_settings_page<?php if ($isLiteMode) echo ' wpc-is-lite'; ?>">
        <?php
        $wps_ic->integrations->render_plugin_notices();
        ?>


        <form method="POST" class="wpc-lite-form"
              action="">
            <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
            <?php $liteActive = (empty($options['api_key']) || (!empty($options['version']) && $options['version'] == 'lite')); ?>
            <!-- Header Start -->
            <div class="wpc-header">
                <div class="wpc-header-left">
                    <div class="wpc-header-logo">
                        <img src="<?php echo WPS_IC_URI; ?>assets/v4/images/main-logo.svg"/>
                    </div>
                </div>
                <!-- Right Side -->
                <div class="wpc-header-right">
                    <button type="button" class="wpc-icon-style-toggle" title="<?php echo esc_attr__('Switch icon style', WPS_IC_TEXTDOMAIN); ?>">
                        <svg width="16" height="16" viewBox="0 0 512 512" fill="currentColor"><path d="M464 256l0 16-108.1 0c-64 0-115.9 51.9-115.9 115.9 0 20.2 5.3 39.9 15.1 57.2L237 463.1C131 453.5 48 364.5 48 256 48 141.1 141.1 48 256 48s208 93.1 208 208zM320 448l-12.1-12.1c-12.7-12.7-19.9-30-19.9-48 0-37.5 30.4-67.9 67.9-67.9l156.1 0 0-64C512 114.6 397.4 0 256 0S0 114.6 0 256 114.6 512 256 512c19.4-19.4 40.7-40.7 64-64zM256 160a32 32 0 1 0 0-64 32 32 0 1 0 0 64zm-64 0a32 32 0 1 0 -64 0 32 32 0 1 0 64 0zm-32 96a32 32 0 1 0 -64 0 32 32 0 1 0 64 0zm224-96a32 32 0 1 0 -64 0 32 32 0 1 0 64 0z"/></svg>
                        <span class="wpc-icon-style-label"><?php echo esc_html__('Style', WPS_IC_TEXTDOMAIN); ?></span>
                    </button>
                    <div class="save-button wpc-save-pill" style="display:none;">
                        <div class="wpc-save-pill-left">
                            <span class="wpc-save-pill-icon">
                                <span class="wpc-save-pill-warn-ico"></span>
                                <span class="wpc-save-pill-ping"></span>
                            </span>
                            <div class="wpc-save-pill-text">
                                <span class="wpc-save-pill-title"><?php echo esc_html__('Unsaved changes', WPS_IC_TEXTDOMAIN); ?></span>
                                <span class="wpc-save-pill-sub"><?php echo esc_html__('Please save your progress.', WPS_IC_TEXTDOMAIN); ?></span>
                            </div>
                        </div>
                        <div class="wpc-save-pill-actions">
                            <button type="submit" class="wpc-save-pill-btn save-button-lite">
                                <span class="wpc-save-pill-save-ico"></span> <?php echo esc_html__('Save', WPS_IC_TEXTDOMAIN); ?>
                            </button>
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
                            <i class="icon-pause"></i> <?php echo esc_html__('Pause Optimization', WPS_IC_TEXTDOMAIN); ?></a>
                        <a class="btn btn-gradient text-white fw-500 btn-radius wpc-optimizer-running wpc-page-optimizations-running"
                           style="display:none;font-weight: bold;font-family:'proxima_semibold' !important;">
                            <img src="<?php
                            echo WPS_IC_ASSETS; ?>/v4/images/loading-icon-media.svg"
                                 style="max-height: 25px;margin-right:10px">
                            <?php echo esc_html__('Optimization in progress...', WPS_IC_TEXTDOMAIN); ?>
                        </a>
                        <a class="btn btn-gradient text-white fw-500 btn-radius wpc-start-optimizations"
                           style="display:none;font-weight: bold;font-family:'proxima_semibold' !important;">
                            <img src="<?php
                            echo WPS_IC_ASSETS; ?>/v4/icons/thunder-icon-white.svg"
                                 style="height: 17px;;margin-right:10px"><?php echo esc_html__('Start Optimization', WPS_IC_TEXTDOMAIN); ?>
                        </a>
                        <a class="btn btn-gradient text-white fw-500 btn-radius
                                    wpc-optimization-complete"
                           style="display:none;font-weight: bold;font-family:'proxima_semibold' !important;">
                            <?php echo esc_html__('Optimized', WPS_IC_TEXTDOMAIN); ?>
                        </a>
                        <a class="btn btn-gradient text-white fw-500 btn-radius
                                    wpc-preparing-optimization"
                           style="display:none;font-weight: bold;font-family:'proxima_semibold' !important;">
                            <?php echo esc_html__('Preparing...', WPS_IC_TEXTDOMAIN); ?>
                        </a>
                        <a class="btn btn-gradient text-white fw-500 btn-radius
                                    wpc-optimization-locked" style="display:none;font-weight: bold;
                                    font-family:'proxima_semibold' !important;">
                            <?php echo esc_html__('Smart Optimization Locked', WPS_IC_TEXTDOMAIN); ?>
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
                    <div class="wpc-header-advanced-btn">
                        <?php if ($liteActive) { ?>
                            <a href="#" class="wpc-lite-locked-advanced wpc-header-adv-link"><img src="<?php echo WPS_IC_URI; ?>assets/lite/images/advanced-settings.svg"/> <span class="wpc-adv-btn-text"><?php echo esc_html__('Advanced Settings', WPS_IC_TEXTDOMAIN); ?></span></a>
                        <?php } else { ?>
                            <a href="#" class="wpc-lite-toggle-advanced wpc-header-adv-link"><img src="<?php echo WPS_IC_URI; ?>assets/lite/images/advanced-settings.svg"/> <span class="wpc-adv-btn-text"><?php echo esc_html__('Advanced Settings', WPS_IC_TEXTDOMAIN); ?></span></a>
                        <?php } ?>
                    </div>
                </div>
            </div>
            <!-- Header End -->

            <!-- Body Start -->
            <div class="wpc-settings-flex-body">
                <div class="wpc-settings-sidebar">

                    <div class="wpc-rounded-box wpc-quick-opts">
                        <div class="wpc-box-title circle no-separator">
                            <h3><?php echo esc_html__('Quick Optimizations', WPS_IC_TEXTDOMAIN); ?></h3>
                        </div>
                        <div class="wpc-box-content">
                            <ul class="wpc-toggles">
                                <li>
                                    <?php echo $gui::simpleCheckbox(esc_html__('Cache', WPS_IC_TEXTDOMAIN), '', false, '0', ['cache', 'advanced'], false); ?>
                                </li>
                                <li>
                                    <?php echo $gui::simpleCheckbox(esc_html__('CSS', WPS_IC_TEXTDOMAIN), '', false, '0', ['critical', 'css'], false); ?>
                                </li>
                                <li>
                                    <?php echo $gui::simpleCheckbox(esc_html__('JavaScript', WPS_IC_TEXTDOMAIN), '', false, '0', 'delay-js-v2', false); ?>
                                </li>
                                <li>
                                    <?php echo $gui::simpleCheckbox(esc_html__('Lazy Loading', WPS_IC_TEXTDOMAIN), '', false, '0', 'nativeLazy', false); ?>
                                </li>
                                <li>
                                    <?php
			                            $liteActive = (empty($options['api_key']) || (!empty($options['version']) && $options['version'] == 'lite'));
			                            echo $gui::simpleCheckbox(esc_html__('Images', WPS_IC_TEXTDOMAIN), '', false, '0', 'imagesPreset', $liteActive);
                                    ?>
                                </li>
                                <li>
			                        <?php
                                        $cfLive = false;
                                        if ($cf && isset($cf['settings'])){
                                            $cfLive = ($cf['settings']['assets'] == '1' && $cf['settings']['cdn'] == '0');
                                        }
			                            $allowLive = get_option('wps_ic_allow_live') && !$cfLive;
			                            if ($liteActive) {
				                            echo $gui::simpleCheckbox( esc_html__('CDN', WPS_IC_TEXTDOMAIN), '', false, '0', 'cdnAll', true );
			                            } else if (!$allowLive){
				                            //dont display the toggle, off in portal
			                            } else {
				                            echo $gui::simpleCheckbox( esc_html__('CDN', WPS_IC_TEXTDOMAIN), '', false, '0', 'cdnAll', false );
			                            }
			                        ?>
                                </li>
                            </ul>
                        </div>
                    </div>

                    <?php
                    if ($liteActive) {
                        ?>
                        <div class="wpc-rounded-box wpc-promo-box">
                            <div class="wpc-box-title"><img class="wpc-ic-logo"
                                        src="<?php echo WPS_IC_URI; ?>assets/lite/images/unlock-icon.svg"
                                        alt="<?php echo esc_attr__('Unlock PRO Features', WPS_IC_TEXTDOMAIN); ?>"/> <?php echo esc_html__('Unlock PRO Features', WPS_IC_TEXTDOMAIN); ?>
                            </div>
                            <div class="wpc-box-content">
                                <ul>
                                    <li>
                                        <svg class="wpc-promo-icon" width="16" height="16" viewBox="0 0 512 512" fill="currentColor"><path d="M256 48a208 208 0 1 1 0 416 208 208 0 1 1 0-416zm0 464a256 256 0 1 0 0-512 256 256 0 1 0 0 512zm32-400a32 32 0 1 0 -64 0 32 32 0 1 0 64 0zM256 408c30.9 0 56-25.1 56-56 0-14.4-5.4-27.5-14.4-37.5l60.5-145.3 9.2-22.2-44.3-18.5-9.2 22.2-60.5 145.3c-29.7 1.4-53.3 25.9-53.3 55.9 0 30.9 25.1 56 56 56zM192 160a32 32 0 1 0 -64 0 32 32 0 1 0 64 0zM112 288a32 32 0 1 0 0-64 32 32 0 1 0 0 64zm320-32a32 32 0 1 0 -64 0 32 32 0 1 0 64 0z"/></svg>
                                        <span><?php echo esc_html__('Instant Page Speed Boost', WPS_IC_TEXTDOMAIN); ?></span>
                                    </li>
                                    <li>
                                        <svg class="wpc-promo-icon" width="16" height="16" viewBox="0 0 640 512" fill="currentColor"><path d="M288 112l-24-56-56-24 56-24 24-56 24 56 56 24-56 24-24 56zM492-21.9c2.1 2.1 31.8 31.8 89 89l17 17-17 17-416 416-17 17c-2.1-2.1-31.8-31.8-89-89l-17-17 17-17 416-416 17-17zM109.9 428L148 466.1 386.1 228 348 189.9 109.9 428zM492 45.9L381.9 156 420 194.1 530.1 84 492 45.9zM96 96l32-80 32 80 80 32-80 32-32 80-32-80-80-32 80-32zM384 400l80-32 32-80 32 80 80 32-80 32-32 80-32-80-80-32z"/></svg>
                                        <span><?php echo esc_html__('24/7 Smart Optimization', WPS_IC_TEXTDOMAIN); ?></span>
                                    </li>
                                    <li>
                                        <svg class="wpc-promo-icon" width="16" height="16" viewBox="0 0 576 512" fill="currentColor"><path d="M480 80c8.8 0 16 7.2 16 16l0 256c0 8.8-7.2 16-16 16l-320 0c-8.8 0-16-7.2-16-16l0-256c0-8.8 7.2-16 16-16l320 0zM160 32c-35.3 0-64 28.7-64 64l0 256c0 35.3 28.7 64 64 64l320 0c35.3 0 64-28.7 64-64l0-256c0-35.3-28.7-64-64-64L160 32zm80 112a32 32 0 1 0 -64 0 32 32 0 1 0 64 0zm140.7 3.8c-4.3-7.3-12.2-11.8-20.7-11.8s-16.4 4.5-20.7 11.8l-46.5 79-17.2-24.6c-4.5-6.4-11.8-10.2-19.7-10.2s-15.2 3.8-19.7 10.2l-56 80c-5.1 7.3-5.8 16.9-1.6 24.8S191.1 320 200 320l240 0c8.6 0 16.6-4.6 20.8-12.1s4.2-16.7-.1-24.1l-80-136zM48 152c0-13.3-10.7-24-24-24S0 138.7 0 152L0 448c0 35.3 28.7 64 64 64l360 0c13.3 0 24-10.7 24-24s-10.7-24-24-24L64 464c-8.8 0-16-7.2-16-16l0-296z"/></svg>
                                        <span><?php echo esc_html__('Adaptive Images + WebP', WPS_IC_TEXTDOMAIN); ?></span>
                                    </li>
                                    <li>
                                        <svg class="wpc-promo-icon" width="16" height="16" viewBox="0 0 448 512" fill="currentColor"><path d="M64 80c-8.8 0-16 7.2-16 16l0 320c0 8.8 7.2 16 16 16l320 0c8.8 0 16-7.2 16-16l0-320c0-8.8-7.2-16-16-16L64 80zM0 96C0 60.7 28.7 32 64 32l320 0c35.3 0 64 28.7 64 64l0 320c0 35.3-28.7 64-64 64L64 480c-35.3 0-64-28.7-64-64L0 96zM128.6 272c-9.2 0-16.6-7.4-16.6-16.6 0-4.7 2-9.2 5.5-12.4L258.7 116.7c3.4-3 7.8-4.7 12.4-4.7 12.4 0 21.3 12 17.8 23.9l-31.2 104.1 61.8 0c9.2 0 16.6 7.4 16.6 16.6 0 4.7-2 9.2-5.5 12.4L189.3 395.3c-3.4 3-7.8 4.7-12.4 4.7-12.4 0-21.3-12-17.8-23.9l31.2-104.1-61.8 0z"/></svg>
                                        <span><?php echo esc_html__('Ultra-Fast CDN Delivery', WPS_IC_TEXTDOMAIN); ?></span>
                                    </li>
                                    <li>
                                        <svg class="wpc-promo-icon" width="16" height="16" viewBox="0 0 512 512" fill="currentColor"><path d="M48 56l0-24-48 0 0 448 512 0 0-48-464 0 0-376zm296 72l-24 0 0 48 78.1 0-94.1 94.1c-63-63-95-95-96-96l-17 17-88 88-17 17 33.9 33.9c2.3-2.3 31.6-31.6 88-88l79 79 17 17 17-17 111-111 0 78.1 48 0 0-160-136 0z"/></svg>
                                        <span><?php echo esc_html__('Management & Reporting', WPS_IC_TEXTDOMAIN); ?></span>
                                    </li>
                                </ul>
                            </div>
                            <div class="wpc-box-content-btn">
                                <a href="#" class="wpc-add-access-key-btn-pro"><svg width="9" height="9" viewBox="0 0 448 512" fill="currentColor"><path d="M341.2-12.1c9.1 6 13 17.3 9.6 27.6L292 192 412.9 192c19.4 0 35.1 15.7 35.1 35.1 0 10-4.2 19.5-11.7 26.1L136 521.9c-8.1 7.3-20.1 8.2-29.2 2.2s-13-17.3-9.6-27.6L156 320 35.1 320C15.7 320 0 304.3 0 284.9 0 275 4.2 265.5 11.7 258.8L312-9.9c8.1-7.3 20.1-8.1 29.2-2.2zM68.9 272l120.4 0c7.7 0 15 3.7 19.5 10s5.7 14.3 3.3 21.6L171.3 425.9 379.1 240 258.7 240c-7.7 0-15-3.7-19.5-10s-5.7-14.3-3.3-21.6L276.7 86.1 68.9 272z"/></svg> <?php echo esc_html__('Enter Access Key', WPS_IC_TEXTDOMAIN); ?></a>
                            </div>
                        </div>
                    <?php } else { ?>
                        <div class="wpc-rounded-box wpc-usage-card">
                            <!-- Header -->
                            <div class="wpc-usage-header">
                                <div class="wpc-usage-title-row">
                                    <span class="wpc-usage-icon"></span>
                                    <div>
                                        <h3><?php echo esc_html__("This Month's Usage", WPS_IC_TEXTDOMAIN); ?></h3>
                                        <span class="wpc-usage-subtitle"><?php echo esc_html__('statistics updated hourly', WPS_IC_TEXTDOMAIN); ?></span>
                                    </div>
                                </div>
                            </div>

                            <!-- Stats -->
                            <?php
                            // Parse the bytes value to extract number and unit
                            preg_match('/([0-9.,]+)\s*([a-zA-Z]+)/', $apiStats->display->bytes, $bytesMatch);
                            $bytesNum = isset($bytesMatch[1]) ? $bytesMatch[1] : $apiStats->display->bytes;
                            $bytesUnit = isset($bytesMatch[2]) ? $bytesMatch[2] : '';
                            ?>
                            <div class="wpc-usage-stats">
                                <div class="wpc-usage-stat">
                                    <span class="wpc-usage-label"><?php echo esc_html__('Total Assets', WPS_IC_TEXTDOMAIN); ?></span>
                                    <div class="wpc-usage-value">
                                        <span class="wpc-usage-num"><?php echo number_format((int) $apiStats->display->requests); ?></span>
                                    </div>
                                </div>
                                <div class="wpc-usage-sep"></div>
                                <div class="wpc-usage-stat">
                                    <span class="wpc-usage-label"><?php echo esc_html__('Optimized', WPS_IC_TEXTDOMAIN); ?></span>
                                    <div class="wpc-usage-value">
                                        <span class="wpc-usage-num"><?php echo $bytesNum; ?></span>
                                        <?php if ($bytesUnit) { ?><span class="wpc-usage-unit"><?php echo $bytesUnit; ?></span><?php } ?>
                                    </div>
                                </div>
                            </div>

                            <!-- Projected Usage Footer -->
                            <div class="wpc-usage-footer">
                                <div class="wpc-usage-footer-label">
                                    <img class="wpc-usage-footer-label-ico" src="<?php echo WPS_IC_URI; ?>assets/lite/images/projected-stats.svg" alt="" />
                                    <?php echo esc_html__('Projected Usage', WPS_IC_TEXTDOMAIN); ?>
                                </div>
                                <div class="wpc-usage-footer-boxes">
                                    <div class="wpc-usage-footer-box">
                                        <span class="wpc-usage-footer-title"><?php echo esc_html__('Assets', WPS_IC_TEXTDOMAIN); ?></span>
                                        <span class="wpc-usage-footer-value"><?php echo $apiStats->display->projectedRequests; ?></span>
                                    </div>
                                    <div class="wpc-usage-footer-box">
                                        <span class="wpc-usage-footer-title"><?php echo esc_html__('Data', WPS_IC_TEXTDOMAIN); ?></span>
                                        <?php
                                        preg_match('/([0-9.,]+)\s*([a-zA-Z]+)/', $apiStats->display->projectedBytes, $projMatch);
                                        $projNum = isset($projMatch[1]) ? $projMatch[1] : $apiStats->display->projectedBytes;
                                        $projUnit = isset($projMatch[2]) ? $projMatch[2] : '';
                                        ?>
                                        <span class="wpc-usage-footer-value"><?php echo $projNum; ?><?php if ($projUnit) { ?><span class="wpc-usage-footer-unit"><?php echo $projUnit; ?></span><?php } ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php } ?>

                    <?php $cf_option = get_option(WPS_IC_CF); if (!empty($cf_option) && !empty($cf_option['token'])) { ?>
                    <div class="wpc-rounded-box wpc-cf-sidebar-banner wpc-cf-connected" id="wpc-cf-banner">
                        <img src="<?php echo WPS_IC_ASSETS; ?>/v4/images/cf-logo.png" alt="<?php echo esc_attr__('Cloudflare', WPS_IC_TEXTDOMAIN); ?>">
                        <span class="wpc-cf-sidebar-text"><?php echo esc_html__('Faster TTFB and auto-optimization at the edge.', WPS_IC_TEXTDOMAIN); ?></span>
                        <span class="wpc-cf-connected-badge"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline></svg> <?php echo esc_html__('Connected', WPS_IC_TEXTDOMAIN); ?></span>
                    </div>
                    <?php } else { ?>
                    <div class="wpc-rounded-box wpc-cf-sidebar-banner" id="wpc-cf-banner">
                        <button type="button" class="wpc-cf-dismiss" aria-label="<?php echo esc_attr__('Dismiss', WPS_IC_TEXTDOMAIN); ?>" onclick="this.closest('.wpc-cf-sidebar-banner').style.display='none'">&times;</button>
                        <img src="<?php echo WPS_IC_ASSETS; ?>/v4/images/cf-logo.png" alt="<?php echo esc_attr__('Cloudflare', WPS_IC_TEXTDOMAIN); ?>">
                        <span class="wpc-cf-sidebar-text"><?php echo esc_html__('Connect for faster TTFB and auto-optimize at the edge.', WPS_IC_TEXTDOMAIN); ?></span>
                        <button type="button" class="wpc-cf-enable-btn wpc-cf-link"><?php echo esc_html__('Enable now', WPS_IC_TEXTDOMAIN); ?> &#8594;</button>
                    </div>
                    <?php } ?>

                </div>
                <!-- Lite Dashboard Start -->
                <div class="wpc-settings-content wpc-lite-dashboard">
                    <div class="wpc-settings-content-inner">
                        <div class="wpc-rounded-box wpc-rounded-box-full">
	                        <?php
	                        if ($cf){
		                        echo $gui::CFGraph();
	                        } else {
		                        echo $gui::usageGraph();
	                        }
	                        ?>
                        </div>
                    </div>


                    <div class="wpc-settings-content-inner">
                        <div class="wpc-rounded-box wpc-rounded-box-half">
                            <div class="wpc-box-title circle no-separator">
                                <h3><?php echo esc_html__('Optimization Stats', WPS_IC_TEXTDOMAIN); ?></h3>
                                <?php echo $optimizationStatus; ?>
                            </div>
                            <div class="wpc-box-content">
                                <ul class="wpc-optimization-stats">
                                    <li>
                                        <?php echo $stats->getLiteStatsBox(esc_html__('Page Size', WPS_IC_TEXTDOMAIN), 'down', $optimizedStats['totalPageSizeAfter'], $optimizedStats['pageSizeSavingsPercentage'] . ' ' . esc_html__('Smaller', WPS_IC_TEXTDOMAIN), $optimizedStats['totalPageSizeBefore']); ?>
                                    </li>
                                    <li>
                                        <?php echo $stats->getLiteStatsBox(esc_html__('Requests', WPS_IC_TEXTDOMAIN), 'down', $optimizedStats['totalRequestsAfter'], $optimizedStats['totalRequestsSavings'] . ' ' . esc_html__('Less', WPS_IC_TEXTDOMAIN), $optimizedStats['totalRequestsBefore']); ?>
                                    </li>
                                    <li>
                                        <?php echo $stats->getLiteStatsBox(esc_html__('Server Speed', WPS_IC_TEXTDOMAIN), 'up', $optimizedStats['totalTtfbAfter'], $optimizedStats['ttfbLess'] . ' ' . esc_html__('Faster', WPS_IC_TEXTDOMAIN), $optimizedStats['totalTtfbBefore']); ?>
                                    </li>
                                </ul>
                            </div>
                        </div>
                        <div class="wpc-rounded-box wpc-rounded-box-half">
                            <div class="wpc-box-title circle no-separator">
                                <h3><?php echo esc_html__('PageSpeed Score', WPS_IC_TEXTDOMAIN); ?></h3>
                                <?php
                                if (empty($initialPageSpeedScore) && !empty(get_transient('wpc_test_running')) && !$warmupFailing) {
                                    ?>

                                    <span class="wpc-test-in-progress"><?php echo esc_html__('Running...', WPS_IC_TEXTDOMAIN); ?></span>
                                    <a href="#" class="wps-ic-initial-retest">
                                        <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/refresh.svg"/>
                                        <?php echo esc_html__('Retest', WPS_IC_TEXTDOMAIN); ?>
                                    </a>
                                <?php } elseif(empty($initialPageSpeedScore) && $warmupFailing){
                                  ?>
                                    <div class="wpc-box-title-right">
                                        <span class="wpc-test-not-going"><?php echo esc_html__('Error: connection to API failed.', WPS_IC_TEXTDOMAIN); ?></span>
                                        <a href="#" class="wps-ic-initial-retest">
                                            <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/refresh.svg"/>
                                            <?php echo esc_html__('Retest', WPS_IC_TEXTDOMAIN); ?>
                                        </a>
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
                                        $lastRun = $date->format('F jS, Y \a\t g:i A');
                                        ?>
                                        <div class="wpc-box-title-right">
                                            <span><?php echo $lastRun; ?></span>
                                            <a href="#" class="wps-ic-initial-retest">
                                                <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/refresh.svg"/>
                                                <?php echo esc_html__('Retest', WPS_IC_TEXTDOMAIN); ?>
                                            </a>
                                        </div>
                                            <?php
                                    } else {
                                        $lastRun = esc_html__('Running...', WPS_IC_TEXTDOMAIN);
                                        ?>
                                        <div class="wpc-box-title-right">
                                            <span class="wpc-test-in-progress"><?php echo esc_html__('Running...', WPS_IC_TEXTDOMAIN); ?></span>
                                            <a href="#" class="wps-ic-initial-retest">
                                                <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/refresh.svg"/>
                                                <?php echo esc_html__('Retest', WPS_IC_TEXTDOMAIN); ?>
                                            </a>
                                        </div>
                                            <?php
                                    }
                                    ?>
                                <?php } ?>
                            </div>
                            <div class="wpc-box-content wpc-box-centered">
                                <div class="wpc-pagespeed-running wpc-pagespeed-preparing" style="display:none">
                                    <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>
                                    <span><?php echo esc_html__('Preparing...', WPS_IC_TEXTDOMAIN); ?></span>
                                </div>

                                <?php
                                if (empty($options['api_key']) || (empty($initialPageSpeedScore) && !empty(get_transient('wpc_test_running')))) {
                                    ?>

                                    <div class="wpc-pagespeed-running">
                                        <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>
                                        <span><?php echo esc_html__('Usually takes about 10 minutes...', WPS_IC_TEXTDOMAIN); ?></span>
                                    </div>
                                <?php
                                } elseif (empty($initialPageSpeedScore) && $warmupFailing){
                                    echo '<div style="padding:35px 15px;text-align: center;">';
                                    echo '<strong>' . esc_html__('Error: Connection to our API was blocked by a firewall on your server.', WPS_IC_TEXTDOMAIN) . '</strong>';
                                    echo '<br/><br/><a href="https://help.wpcompress.com/en-us/article/whitelisting-wp-compress-for-uninterrupted-service-4dwkra/" target="_blank">' . esc_html__('Whitelisting Tutorial', WPS_IC_TEXTDOMAIN) . '</a>';
                                    echo '</div>';

                                } elseif (!empty($options['api_key']) && (empty($initialPageSpeedScore) && empty(get_transient('wpc_test_running')))) {

                                $home_page_id = get_option('page_on_front');
                                ?>
                                    <script type="text/javascript">

                                    </script>

                                    <div class="wpc-pagespeed-running">
                                        <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>
                                        <span><?php echo esc_html__('Usually takes about 10 minutes...', WPS_IC_TEXTDOMAIN); ?></span>
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

                                    if ($desktopDiff <= 0) { $desktopDiff = esc_html__('Perfect Score', WPS_IC_TEXTDOMAIN); } else { $desktopDiff = '+' . $desktopDiff; }
                                    if ($mobileDiff <= 0) { $mobileDiff = esc_html__('Perfect Score', WPS_IC_TEXTDOMAIN); } else { $mobileDiff = '+' . $mobileDiff; }
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
                                                                 alt="<?php echo esc_attr__('Mobile GPS', WPS_IC_TEXTDOMAIN); ?>"/>
                                                        </div>
                                                        <div class="wpc-gps-info-text">
                                                            <?php echo esc_html__('Mobile', WPS_IC_TEXTDOMAIN); ?>
                                                        </div>
                                                        <div class="wpc-gps-improvement">
                                                            <div class="wpc-stats-improvement">
                                                                <span class="wpc-stats-improvement-icon">
                                                                    <img src="<?php echo WPS_IC_ASSETS . '/lite/images/arrow-up.svg'; ?>"/>
                                                                </span>
                                                                <span class="wpc-stats-improvement-text"><?php echo $mobileDiff === esc_html__('Perfect Score', WPS_IC_TEXTDOMAIN) ? $mobileDiff : $mobileDiff . ' ' . esc_html__('points', WPS_IC_TEXTDOMAIN); ?></span>
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
                                                                <?php echo esc_html__('Before', WPS_IC_TEXTDOMAIN); ?>
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
                                                                <?php echo esc_html__('After', WPS_IC_TEXTDOMAIN); ?>
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
                                                                 alt="<?php echo esc_attr__('Desktop GPS', WPS_IC_TEXTDOMAIN); ?>"/>
                                                        </div>
                                                        <div class="wpc-gps-info-text">
                                                            <?php echo esc_html__('Desktop', WPS_IC_TEXTDOMAIN); ?>
                                                        </div>
                                                        <div class="wpc-gps-improvement">
                                                            <div class="wpc-stats-improvement">
                                                                <span class="wpc-stats-improvement-icon">
                                                                    <img src="<?php echo WPS_IC_ASSETS . '/lite/images/arrow-up.svg'; ?>"/>
                                                                </span>
                                                                <span class="wpc-stats-improvement-text"><?php echo $desktopDiff === esc_html__('Perfect Score', WPS_IC_TEXTDOMAIN) ? $desktopDiff : $desktopDiff . ' ' . esc_html__('points', WPS_IC_TEXTDOMAIN); ?></span>
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
                                                                <?php echo esc_html__('Before', WPS_IC_TEXTDOMAIN); ?>
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
                                                                <?php echo esc_html__('After', WPS_IC_TEXTDOMAIN); ?>
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
                                            <span><?php echo __('Unlock even more power with <strong>PRO</strong> Access!', WPS_IC_TEXTDOMAIN); ?></span>
                                        </div>
                                        <div class="wpc-ps-f-right">
                                            <a href="https://wpcompress.com/go/plans/" target="_blank"
                                               class="wpc-custom-btn">
                                                <div>
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 512 512" style="vertical-align:-1px;fill:currentColor"><path d="M256 512a256 256 0 1 1 0-512 256 256 0 1 1 0 512zM374 145.7c-10.7-7.8-25.7-5.4-33.5 5.3L221.1 315.2 169 263.1c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l72 72c5 5 11.8 7.5 18.8 7s13.4-4.1 17.5-9.8L379.3 179.2c7.8-10.7 5.4-25.7-5.3-33.5z"/></svg>
                                                </div>
                                                <div><?php echo esc_html__('View Plans', WPS_IC_TEXTDOMAIN); ?></div>
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
                                                        <p><img src="<?php echo WPS_IC_ASSETS . '/lite/images/wohoo.png'; ?>"/> <?php echo esc_html__('Woohoo! Your Website is Now Loading Faster!', WPS_IC_TEXTDOMAIN); ?></p>
                                                        <span class="wpc-badge-success"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 512 512" style="vertical-align:-1px;fill:currentColor"><path d="M256 512a256 256 0 1 1 0-512 256 256 0 1 1 0 512zM374 145.7c-10.7-7.8-25.7-5.4-33.5 5.3L221.1 315.2 169 263.1c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l72 72c5 5 11.8 7.5 18.8 7s13.4-4.1 17.5-9.8L379.3 179.2c7.8-10.7 5.4-25.7-5.3-33.5z"/></svg> <?php echo esc_html__('Optimized', WPS_IC_TEXTDOMAIN); ?></span>
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
                                        <p style="text-align: center;font-weight: bold;font-family: 'proxima_semibold';"><?php echo esc_html__('Oops! We had some issues testing your site. Please retry!', WPS_IC_TEXTDOMAIN); ?></p>
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

                    <!-- ═══════════════════════════════════════════════════════════
                         V2 REDESIGN — World-Class Dashboard Cards
                         (Identical markup to Advanced dashboard stats.php)
                         ═══════════════════════════════════════════════════════════ -->
                    <?php
                    // Recompute GPS data for v2 section
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
                        if ($v2_desktopDiff <= 0) { $v2_desktopDiff = esc_html__('Perfect Score', WPS_IC_TEXTDOMAIN); } else { $v2_desktopDiff = '+' . $v2_desktopDiff; }
                        if ($v2_mobileDiff <= 0) { $v2_mobileDiff = esc_html__('Perfect Score', WPS_IC_TEXTDOMAIN); } else { $v2_mobileDiff = '+' . $v2_mobileDiff; }
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
                        <!-- ─── Optimization Stats ─── -->
                        <div class="wpc-v2-card">
                            <div class="wpc-v2-card-header">
                                <div class="wpc-v2-title">
                                    <span class="wpc-v2-dot"></span>
                                    <h3><?php echo esc_html__('Optimization Stats', WPS_IC_TEXTDOMAIN); ?></h3>
                                </div>
                                <?php echo $optimizationStatus; ?>
                            </div>
                            <?php
                            $v2_testRunning = !empty(get_transient('wpc_initial_test')) || !empty(get_transient('wpc_test_running'));
                            $v2_noTestData = empty(get_option(WPS_IC_TESTS));
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
                                $v2_stats = array(
                                    array('label' => esc_html__('Page Size', WPS_IC_TEXTDOMAIN), 'value' => $optimizedStats['totalPageSizeAfter'], 'badge' => $optimizedStats['pageSizeSavingsPercentage'] . ' ' . esc_html__('Smaller', WPS_IC_TEXTDOMAIN), 'arrow' => 'down', 'before' => $optimizedStats['totalPageSizeBefore']),
                                    array('label' => esc_html__('Requests', WPS_IC_TEXTDOMAIN), 'value' => $optimizedStats['totalRequestsAfter'], 'badge' => $optimizedStats['totalRequestsSavings'] . ' ' . esc_html__('Less', WPS_IC_TEXTDOMAIN), 'arrow' => 'down', 'before' => $optimizedStats['totalRequestsBefore']),
                                    array('label' => esc_html__('Server Speed', WPS_IC_TEXTDOMAIN), 'value' => $optimizedStats['totalTtfbAfter'], 'badge' => $optimizedStats['ttfbLess'] . ' ' . esc_html__('Faster', WPS_IC_TEXTDOMAIN), 'arrow' => 'up', 'before' => $optimizedStats['totalTtfbBefore']),
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
                                            <span><?php echo esc_html__('was', WPS_IC_TEXTDOMAIN); ?> <?php echo $v2_stat['before']; ?></span>
                                        </div>
                                    </div>
                                    <?php
                                    $v2_i++;
                                }
                                ?>
                            </div>
                            <?php } ?>
                        </div>

                        <!-- ─── PageSpeed Score ─── -->
                        <div class="wpc-v2-card">
                            <div class="wpc-v2-card-header">
                                <div class="wpc-v2-title">
                                    <span class="wpc-v2-dot wpc-v2-dot-green"></span>
                                    <h3><?php echo esc_html__('PageSpeed Score', WPS_IC_TEXTDOMAIN); ?></h3>
                                </div>
                                <?php if ($v2_hasGPS) { ?>
                                <div class="wpc-v2-header-meta">
                                    <span class="wpc-v2-meta-date"><?php echo $v2_lastRun; ?></span>
                                    <a href="#" class="wps-ic-initial-retest wpc-v2-retest-btn">
                                        <img src="<?php echo WPS_IC_URI; ?>assets/lite/images/refresh.svg"/>
                                        <?php echo esc_html__('Retest', WPS_IC_TEXTDOMAIN); ?>
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
                                    $isPerfect = ($v2_dev['diff'] === esc_html__('Perfect Score', WPS_IC_TEXTDOMAIN));
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
                                    <div class="wpc-v2-score-col">
                                        <div class="wpc-v2-device-header">
                                            <div class="wpc-v2-device-ico">
                                                <img src="<?php echo $v2_dev['icon']; ?>" alt="<?php echo esc_attr($v2_dev['name']); ?>" />
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
                                        <div class="wpc-v2-circles">
                                            <div class="wpc-v2-circle wpc-v2-circle-sm">
                                                <div class="wpc-v2-label-before">
                                                    <img src="<?php echo WPS_IC_ASSETS; ?>/lite/images/gps-before.svg" />
                                                    <span><?php echo esc_html__('Before', WPS_IC_TEXTDOMAIN); ?></span>
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
                                                    <span><?php echo esc_html__('After', WPS_IC_TEXTDOMAIN); ?></span>
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
                                    <?php echo __('<strong>Woohoo!</strong> Your Website is Now Loading Faster!', WPS_IC_TEXTDOMAIN); ?>
                                </div>
                                <div class="wpc-v2-footer-pill">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 512 512" style="vertical-align:-1px;fill:currentColor"><path d="M256 512a256 256 0 1 1 0-512 256 256 0 1 1 0 512zM374 145.7c-10.7-7.8-25.7-5.4-33.5 5.3L221.1 315.2 169 263.1c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l72 72c5 5 11.8 7.5 18.8 7s13.4-4.1 17.5-9.8L379.3 179.2c7.8-10.7 5.4-25.7-5.3-33.5z"/></svg>
                                    <span><?php echo esc_html__('Optimized', WPS_IC_TEXTDOMAIN); ?></span>
                                </div>
                            </div>
                            <?php } ?>
                            <?php } else { ?>
                            <div class="wpc-v2-scores-loading">
                                <img src="<?php echo WPS_IC_URI; ?>assets/images/live/bars.svg"/>
                                <span><?php echo esc_html__('Analyzing performance...', WPS_IC_TEXTDOMAIN); ?></span>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                    <!-- ═══ End V2 Redesign ═══ -->

                    <?php
                    if (empty($option) || (!empty($option['version']) && $option['version'] == 'lite')) {
                        ?>
                        <div class="wpc-settings-content-inner" style="display: none;">
                            <div class="wpc-rounded-box wpc-rounded-box-full">
                                <div class="wpc-box-content">
                                    <div class="wpc-box-content-inner">
                                        <div class="wpc-box-content-icon">
                                            <img src="<?php echo WPS_IC_ASSETS . '/v4/images/wpc-logo.svg'; ?>" alt="<?php echo esc_attr__('Go PRO for Portal Access', WPS_IC_TEXTDOMAIN); ?>"/>
                                        </div>
                                        <div class="wpc-box-content-text">
                                            <h3><?php echo esc_html__('Go PRO for Portal Access', WPS_IC_TEXTDOMAIN); ?></h3>
                                            <p><?php echo esc_html__('Get image optimization access, CDN Delivery, remote configuration and more by creating an account!', WPS_IC_TEXTDOMAIN); ?></p>
                                        </div>
                                        <div class="wpc-box-content-button">
                                            <a href="#" class="wpc-add-access-key-btn">
                                                <div>
                                                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 512 512" style="vertical-align:-1px;fill:currentColor"><path d="M256 512a256 256 0 1 1 0-512 256 256 0 1 1 0 512zM374 145.7c-10.7-7.8-25.7-5.4-33.5 5.3L221.1 315.2 169 263.1c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l72 72c5 5 11.8 7.5 18.8 7s13.4-4.1 17.5-9.8L379.3 179.2c7.8-10.7 5.4-25.7-5.3-33.5z"/></svg>
                                                </div>
                                                <div><?php echo esc_html__('Add Access Key', WPS_IC_TEXTDOMAIN); ?></div>
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