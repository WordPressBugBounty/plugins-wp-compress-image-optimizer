<?php
global $wps_ic, $wpdb;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wpc_settings_save_nonce'], 'wpc_settings_save')) {
        die('Forbidden.');
    }
}


if (is_multisite()) {
    $current_blog_id = get_current_blog_id();
    switch_to_blog($current_blog_id);
}

include WPS_IC_DIR . 'classes/gui-v4.class.php';
$cache = new wps_ic_cache_integrations();

if (!empty($_GET['stopBulk'])) {
    $local = new wps_ic_local();
    $send = $local->sendToAPI(['stop'], '', 'stopBulk');
    if ($send) {
        delete_option('wps_ic_parsed_images');
        delete_option('wps_ic_BulkStatus');
        delete_option('wps_ic_bulk_process');
        set_transient('wps_ic_bulk_done', true, 60);

        // Delete all transients
        $query = $wpdb->query("DELETE FROM " . $wpdb->options . " WHERE option_name LIKE '%wps_ic_compress_%'");
        wp_send_json_success();
    }
}


$usageStatsWidth = '';
$hideSidebar = '';
if (!empty($_GET['showAdvanced'])) {
    if ($_GET['showAdvanced'] == 'true') {
        update_option('wpsShowAdvanced', 'true');
    } else {
        delete_option('wpsShowAdvanced');
    }
}

$advancedSettings = get_option('wpsShowAdvanced');
if (!empty($advancedSettings) && $advancedSettings == 'true') {
    $showAdvanced = true;
    $usageStatsWidth = '';
    $hideSidebar = '';
} else {
    $showAdvanced = false;
    $usageStatsWidth = 'wider';
    $hideSidebar = 'style="display:none;"';
}

if (!empty($_GET['selectModes'])) {
    $usageStatsWidth = 'wider';
    $hideSidebar = 'style="display:none;"';
    $modes = new wps_ic_modes();
    $modes->showPopup();
    #$modes->triggerPopup();
    #echo '<a href="#" class="wpc-select-modes">Select modes</a>';
}


if (!empty($_GET['show_hidden_menus'])) {
    update_option('wpc_show_hidden_menus', $_GET['show_hidden_menus']);
}

// Save Settings
if (!empty($_POST['options'])) {

    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wpc_settings_save_nonce'], 'wpc_settings_save')) {
        die('No privileges to save options!');
    }

    update_option(WPS_IC_PRESET, $_POST['wpc_preset_mode']);

    $submittedOptions = $_POST['options'];
    $optimizatonQuality = 'lossless';

    if (isset($submittedOptions['qualityLevel'])) {
        switch ($submittedOptions['qualityLevel']):
            case '1':
                $optimizatonQuality = 'lossless';
                break;
            case '2':
                $optimizatonQuality = 'intelligent';
                break;
            case '3':
                $optimizatonQuality = 'ultra';
                break;
        endswitch;
    }

    $submittedOptions['optimization'] = $optimizatonQuality;
    $options_class = new wps_ic_options();
    $options = $options_class->setMissingSettings($submittedOptions);


    if (isset($options['serve'])) {
        $cdnEnabled = '0';
        foreach ($options['serve'] as $key => $value) {
            if ($options['serve'][$key] == '1') {
                $cdnEnabled = '1';
                break;
            }
        }

        $options['live-cdn'] = $cdnEnabled;
    }

    // Get Purge List
    $purgeList = $options_class->getPurgeList($options);

    // For Lite Settings
    if (!empty($options['generate_adaptive']) && !empty($options['retina']) && !empty($options['generate_webp'])) {
        $options['imagesPreset'] = '1';
    }

    // For Lite Settings
    if (!empty($options['css']) || !empty($options['js']) || !empty($options['fonts']) || !empty($options['serve']['jpg']) && !empty($options['serve']['gif']) || !empty($options['serve']['png']) || !empty($options['serve']['svg'])) {
        $options['cdnAll'] = '1';
    }

    update_option(WPS_IC_SETTINGS, $options);
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


	if (!empty($options['live-cdn']) && $options['live-cdn'] == 1) {
		$htacces->removeWebpReplace();
	} else if (!empty($options['htaccess-webp-replace']) && $options['htaccess-webp-replace'] == '1') {
		$htacces->addWebpReplace();
	} else {
		$htacces->removeWebpReplace();
	}

}

if (!empty($_GET['resetTest'])) {
    delete_transient('wpc_initial_test');
    update_option(WPS_IC_LITE_GPS, ['result' => array(), 'failed' => false, 'lastRun' => time()]);
    $tests = get_option(WPS_IC_TESTS);
    unset($tests['home']);
    update_option(WPS_IC_TESTS, $tests);
}


$gui = new wpc_gui_v4();

$proSite = get_option('wps_ic_prosite');
$options = get_option(WPS_IC_OPTIONS);
$settings = get_option(WPS_IC_SETTINGS);
$bulkProcess = get_option('wps_ic_bulk_process');


$allowLocal = get_option('wps_ic_allow_local');
$allowLive = get_option('wps_ic_allow_live', false);

if (!$allowLive){
	$settings['live-cdn'] = '0';

    foreach ($settings['serve'] as $key => $value){
	    $settings['serve'][$key] = '0';
    }
	$settings['css'] = '0';
	$settings['js'] = '0';
	$settings['fonts'] = '0';

    update_option(WPS_IC_SETTINGS, $settings);
}

$productsDefined = false;
if (post_type_exists('product')) {
    $productsDefined = true;
}

$optimize = get_option('wpc-warmup-selector');
if ($optimize === false) {
    $optimize = ['page', 'post'];
    update_option('wpc-warmup-selector', $optimize);
}

$cdnEnabled = $gui::isFeatureEnabled('cdn');
$cdnLocked = false;
if (!$cdnEnabled) {
    $cdnLocked = true;
}

$localEnabled = $gui::isFeatureEnabled('local');
$localLocked = false;
if (!$localEnabled) {
    $localLocked = true;
}


$settings = get_option(WPS_IC_SETTINGS);
$initialPageSpeedScore = get_option(WPS_IC_LITE_GPS);
$initialTestRunning = get_transient('wpc_initial_test');
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
                            } else if (response.data.warmupFailing == true) {
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

    <div class="wpc-advanced-settings-container wpc-advanced-settings-container-v4 wps_ic_settings_page">
        <form method="POST" action="">

            <?php
            wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce');
            if (!empty($settings['live-cdn']) && $settings['live-cdn'] == '1') { ?>
                <input name="options[live-cdn]" type="hidden" value="1"/>
                <?php
            } else { ?>
                <input name="options[live-cdn]" type="hidden" value="0"/>
                <?php
            } ?>

            <!-- Header Start -->
            <div class="wpc-header">
                <?php
                if (!empty($hideSidebar)) { ?>
                <div class="wpc-header-left" style="max-width:500px;">
                    <?php
                    } else { ?>
                    <div class="wpc-header-left">
                        <?php
                        } ?>
                        <div class="wpc-header-logo">
                            <img src="<?php echo WPS_IC_URI; ?>assets/v4/images/main-logo.svg"/>
                        </div>
                        <?php
                        if (!$showAdvanced) {
                            // Preset Modes
                            $preset_config = get_option(WPS_IC_PRESET);
                            $preset = ['recommended' => 'Recommended Mode', 'safe' => 'Safe Mode', 'aggressive' => 'Aggressive Mode', 'custom' => 'Custom'];

                            if (empty($preset_config)) {
                                update_option('wps_ic_preset_setting', 'aggressive');
                                $preset_config = 'aggressive';
                            }

                            if (empty($preset_config) || empty($preset[$preset_config])) {
                                $preset_config = 'custom';
                            }

                            $html = '<input type="hidden" name="wpc_preset_mode" value="' . $preset_config . '" />
<div class="wpc-dropdown wpc-dropdown-left wpc-dropdown-trigger-popup">
  <button class="btn btn-secondary dropdown-toggle" type="button" id="dropdownMenuButton" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
    ' . $preset[$preset_config] . '
  </button></div>';
                            echo $html;
                        }

                        if ($proSite) {
                            echo '<div class="wpc-header-pro-site"><span>Unlimited</span></div>';
                        }
                        ?>
                    </div>
                    <div class="wpc-header-right">
                        <div class="d-flex align-items-center gap-3 gap-md-4 wpc-header-right-inner"
                             style="position: relative;">
                            <div class="save-button"
                                 style="display: none;">
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
                                                class="btn btn-gradient text-white fw-400 btn-radius wpc-save-button">
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
                            <div class="addon-buttons">
                                <?php
                                if (!$showAdvanced) {
                                    ?>
                                    <a href="#"
                                       class="wpc-plain-btn wpc-change-ui-to-simple"><img src="<?php
                                        echo WPS_IC_ASSETS; ?>/v4/images/popups/selectMode/advanced-settings.svg"
                                                                                          title="Advanced Settings"/>
                                        Advanced Settings</a>
                                    <?php
                                } else { ?>
                                    <a href="#"
                                       class="wpc-plain-btn wpc-change-ui-to-simple"><img
                                                src="<?php echo WPS_IC_ASSETS; ?>/v4/images/popups/selectMode/advanced-settings.svg"
                                                title="Advanced Settings"/> Simple Settings</a>
                                    <?php
                                } ?>

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
                                <?php
                                $localOptimizationStatus = '';
                                $bulkRunning = get_option('wps_ic_bulk_process');
                                if ($bulkRunning) {
                                    if (!empty($bulkRunning['status'])) {
                                        if ($bulkRunning['status'] == 'compressing') {
                                            $localOptimizationStatus = 'compressing';
                                        } else {
                                            $localOptimizationStatus = 'restoring';
                                        }
                                    }
                                    ?>
                                    <ul>
                                        <li>
                                            <?php
                                            if ($localOptimizationStatus == 'compressing') { ?>
                                                <a href="<?php
                                                echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&hash=' . time()); ?>"
                                                   class="wps-ic-stop-bulk-compress" style="display:block;"><i
                                                            class="icon-pause"></i> Pause Local Optimization</a>
                                                <?php
                                            } ?>
                                        </li>
                                        <li>
                                            <?php
                                            if ($localOptimizationStatus == 'restoring') { ?>
                                                <a href="<?php
                                                echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&hash=' . time()); ?>"
                                                   class="wps-ic-stop-bulk-restore" style="display:block;"><i
                                                            class="icon-pause"></i> Pause Local Restore</a>
                                                <?php
                                            } ?>
                                        </li>
                                    </ul>
                                    <?php
                                } ?>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- Header End -->
                <!-- Body Start -->
                <div class="wpc-settings-body">
                    <div class="wpc-settings-tabs">
                        <!-- Tab List Start -->
                        <div class="wpc-settings-tab-list" <?php
                        echo $hideSidebar; ?>>
                            <ul>
                                <li>
                                    <a href="#" class="active" data-tab="dashboard">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/dashboard.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title">Optimization Dashboard</span>
                                    </a>
                                </li>
                                <?php
                                if ($allowLive) { ?>
                                    <li>
                                        <a href="#" class="" data-tab="cdn-delivery-options">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/cdn-delivery-options.svg"/>
                                </span>
                                </span>
                                            <span class="wpc-title">CDN Delivery</span>
                                        </a>
                                    </li>
                                    <?php
                                } ?>
                                <li>
                                    <a href="#" class="wpc-menu-tooltip" title="Image Optimization"
                                       data-tab="image-optimization-options">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/image-optimization.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title">Image Optimization</span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="" data-tab="performance-tweaks-options">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/rocket.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title">Performance Tweaks</span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#"
                                       class=""
                                       data-tab="other-optimization-options">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/other.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title">Other Optimization</span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="" data-tab="ux-settings-options">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/ux.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title">UX Settings</span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="" data-tab="smart-optimization">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/star-shooting-duotone.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title">Smart Optimization</span>
                                    </a>
                                </li>
                                    <li>
                                        <a href="#" class="" data-tab="integrations">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/css-optimization/menu-icon.svg"/>
                                </span>
                                </span>
                                            <span class="wpc-title">Integrations</span>
                                        </a>
                                    </li>
                                <li>
                                    <a href="#" class="" data-tab="export_settings">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/css-optimization/menu-icon.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title">Export/Import settings</span>
                                    </a>
                                </li>

                                <?php
                                if (get_option('wpc_show_hidden_menus') == 'true') {
                                    ?>
                                    <li>
                                        <a href="#" class="" data-tab="system-information">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/css-optimization/menu-icon.svg"/>
                                </span>
                                </span>
                                            <span class="wpc-title">System Information</span>
                                        </a>
                                    </li>
                                    <li style="display: block;">
                                        <a href="#" class="" data-tab="debug">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/css-optimization/menu-icon.svg"/>
                                </span>
                                </span>
                                            <span class="wpc-title">Debug</span>
                                        </a>
                                    </li>
                                    <li style="display: block;">
                                        <a href="#" class="" data-tab="logger">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/css-optimization/menu-icon.svg"/>
                                </span>
                                </span>
                                            <span class="wpc-title">Logger</span>
                                        </a>
                                    </li>
                                    <?php
                                } ?>
                            </ul>
                        </div>
                        <!-- Tab List End -->
                        <!-- Tab Content Start -->
                        <div class="wpc-settings-tab-content">
                            <div class="wpc-settings-tab-content-inner">
                                <div class="wpc-tab-content active-tab" id="dashboard">

                                    <div class="wpc-settings-flex-body" style="padding-top:0px;">
                                        <div class="wpc-settings-content">
                                            <div class="wpc-settings-content-inner"
                                                 style="padding: 20px 20px !important;display:none;">
                                                <?php
                                                include WPS_IC_DIR . 'templates/admin/partials/v4/pull-stats.php'; ?>
                                            </div>

                                            <div class="wpc-settings-content-inner">
                                                <div class="wpc-rounded-box wpc-rounded-box-full">
                                                    <?php
                                                    echo $gui::usageGraph(); ?>
                                                </div>
                                            </div>

                                            <?php
                                            if (($localEnabled || $cdnEnabled) && ($allowLive || $allowLocal)) {
                                                echo $gui::usageStats();
                                            }

                                            include WPS_IC_DIR . 'templates/admin/partials/v4/footer-scripts.php';
                                            ?>

                                            <?php
                                            if (empty($hideSidebar)) { ?>
                                                <div class="wpc-tab-content-box">
                                                    <?php
                                                    echo $gui::presetModes(); ?>
                                                </div>
                                                <?php
                                            } ?>

                                        </div>
                                    </div>

                                </div>
                                <div class="wpc-tab-content" id="cdn-delivery-options" style="display:none;">

                                    <div class="wpc-tab-content-box">
                                        <?php

                                        echo $gui::checkboxTabTitleCheckbox('Real-Time Optimization + CDN', 'Optimize your images & scripts in real-time via our top-rated global CDN.', 'tab-icons/real-time.svg', '', 'cdn-delivery-options', $cdnLocked, '', 'exclude-cdn-popup'); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row real-time-optimization">

                                            <?php
                                            echo $gui::iconCheckBox('JPG/JPEG', 'cdn-delivery/jpg.svg', ['serve', 'jpg'], $cdnLocked); ?>
                                            <?php
                                            echo $gui::iconCheckBox('PNG', 'cdn-delivery/png.svg', ['serve', 'png'], $cdnLocked); ?>
                                            <?php
                                            echo $gui::iconCheckBox('GIF', 'cdn-delivery/gif.svg', ['serve', 'gif'], $cdnLocked); ?>
                                            <?php
                                            echo $gui::iconCheckBox('SVG', 'cdn-delivery/svg.svg', ['serve', 'svg'], $cdnLocked); ?>

                                            <?php
                                            echo $gui::iconCheckBox('CSS', 'cdn-delivery/css.svg', 'css', $cdnLocked); ?>
                                            <?php
                                            echo $gui::iconCheckBox('JavaScript', 'cdn-delivery/js.svg', 'js', $cdnLocked); ?>
                                            <?php
                                            echo $gui::iconCheckBox('Fonts', 'cdn-delivery/font.svg', 'fonts', $cdnLocked); ?>


                                        </div>

                                        <?php
                                        #echo $gui::iconCheckBox('JPG', 'cdn-delivery/jpg.svg', 'jpg'); ?>

                                    </div>

                                    <?php
                                    echo $gui::cname(); ?>

                                </div>
                                <div class="wpc-tab-content" id="image-optimization-options" style="display:none;">

                                    <div class="wpc-tab-content-box" id="adaptive-images">
                                        <?php
                                        $adaptiveEnabled = $gui::isFeatureEnabled('adaptive');
                                        $adaptiveLocked = false;
                                        if (!$adaptiveEnabled) {
                                            $adaptiveLocked = true;
                                        }

                                        echo $gui::checkboxTabTitleCheckbox('Adaptive Images', 'Intelligently adapt images based on the incoming visitors device, browser and location on page.', 'image-optimization/image-optimization.svg', '', 'adaptive-images', $adaptiveLocked); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Resize by Incoming Device', 'Serve the ideal image based on the visitors device to slash file-sizes, improve load times and offer a better experience.', false, '0', 'generate_adaptive', $adaptiveLocked, 'right', 'exclude-adaptive-popup'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Serve WebP Images', 'Generate and serve next generation WebP images to supported browsers and devices.', false, '0', 'generate_webp', $adaptiveLocked, 'right', 'exclude-webp-popup'); ?>

                                        </div>
                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Serve Retina Images', 'Deliver higher resolution retina images so that your images look great on larger screens.', false, '0', 'retina', $adaptiveLocked, 'right'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Background Images', 'Serve background images over CDN with all the adaptive and quality options.', false, '0', 'background-sizing', $adaptiveLocked, 'right'); ?>

                                        </div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            #echo $gui::checkboxDescription_v4('Remove Srcset', 'Disable theme srcset to avoid unintended conflicts with adaptive images or lazy loading.', false, '0', 'remove-srcset', false, 'right'); ?>

                                            <?php
                                            //echo $gui::inputDescription_v4('Max Image Width', 'Insert maximum
                                            // dimensions of images, we will scale your original images to that width.', false, '0', 'max-original-width', false, 'right'); ?>
                                        </div>

                                    </div>

                                    <div class="wpc-tab-content-box" id="lazy-images">
                                        <?php
                                        #echo $gui::TabTitle_InputField('Lazy Loading', 'Intelligently lazy load images based on the viewport position.', 'image-optimization/image-optimization.svg', '', 'lazy-images'); ?>
                                        <?php

                                        $lazyEnabled = $gui::isFeatureEnabled('lazy');
                                        $lazyLocked = false;
                                        if (!$lazyEnabled) {
                                            $lazyLocked = true;
                                        }

                                        echo $gui::checkboxTabTitle('Lazy Loading', 'Intelligently lazy load images based on the viewport position.', 'image-optimization/image-optimization.svg', '', ''); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row mb-20">
                                            <?php
                                            echo $gui::checkboxDescription_v4('Native Lazy', 'Lazy load images using browser methods, to save bandwidth and reduce overall page size.', false, '0', 'nativeLazy', $lazyLocked, 'right', 'exclude-lazy-popup'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Lazy Loading by Viewport', 'Load additional images as the user scrolls to save tons of bandwidth and slash overall page size.', false, '0', 'lazy', $lazyLocked, 'right', 'exclude-lazy-popup'); ?>

                                        </div>

                                        <div class="wpc-items-list-row mb-20">
                                            <?php
                                            echo $gui::inputDescription_v4('Skip Lazy Loading', 'Simply enter how many images you’d like to skip lazy loading for on each page.', 'Skip', 'images', false, 'lazySkipCount', $lazyLocked, '4'); ?>
                                        </div>

                                    </div>

                                    <?php

                                    if (!empty($allowLocal)) { ?>
                                        <div class="wpc-tab-content-box">
                                            <?php
                                            echo $gui::optimizationLevel('Optimization Level', 'optimizationLevel', 'Select your preferred image compression strength.', 'tab-icons/optimization-level.svg', '', 'optimizationLevel', $localLocked); ?>
                                        </div>

                                        <div class="wpc-tab-content-box">
                                            <?php
                                            echo $gui::checkboxDescription('Auto-Optimize on Upload', 'Automatically compress new media library images as they’re uploaded.', 'tab-icons/on-upload.svg', '', 'on-upload', $localLocked); ?>
                                        </div>

                                        <div class="wpc-tab-content-box">
                                            <?php
                                            echo $gui::optimizeMediaLibrary('Optimize Media Library', 'optimizationMediaLib', 'Optimize locally stored images.', 'tab-icons/optimization-level.svg', '', 'optimizationMediaLib', $localLocked); ?>
                                        </div>


                                        <?php
                                    } ?>


                                    <?php
                                    /*
                                                                   <div class="wpc-tab-content-box">
                                                                     <?php echo $gui::checkboxDescription('Local Backups', 'Backup original images on your local server.', 'tab-icons/backup-local.svg', '', ['backup', 'local']); ?>
                                                                   </div> */ ?>

                                </div>
                                <div class="wpc-tab-content" id="ux-settings-options" style="display:none;">
                                    <div class="wpc-tab-content-box" id="ux-settings">
                                        <?php
                                        echo $gui::checkboxTabTitle('User Experience Settings', 'Tailor the plugin to your preferences or needs with customizable design options.', 'tab-icons/ux-settings.svg', '', ''); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row mb-20">
                                            <?php
                                            echo $gui::checkboxDescription_v4('Hide in Admin Bar', 'Admin bar will hide plugin icon with tools per page.', false, '0', ['status', 'hide_in_admin_bar'], false, 'right'); ?>

                                            <?php
                                            if (!empty($allowLocal)) { ?>
                                                <?php
                                                echo $gui::checkboxDescription_v4('Show in Media Library List', 'Compress, exclude and restore images in List Mode.', false, '0', ['local', 'media-library'], false, 'right'); ?>
                                                <?php
                                            } ?>
                                        </div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Hide Cache Status', 'Display Cache status in admin bar for the page.', false, '0', ['status', 'hide_cache_status'], false, 'right'); ?>
                                            <?php
                                            echo $gui::checkboxDescription_v4('Hide Critical CSS Status', 'Display Critical CSS status in admin bar for the page.', false, '0', ['status', 'hide_critical_css_status'], false, 'right'); ?>

                                        </div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Hide Preloading Status', 'Display Preloading status in admin bar for the page.', false, '0', ['status', 'hide_preload_status'], false, 'right'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Hide from WordPress', 'Totally hide
                                      // the plugin from the Admin Area.', false, 'hide_compress', 'hide_compress', false, 'right'); ?>

                                        </div>

                                    </div>
                                </div>
                                <div class="wpc-tab-content" id="performance-tweaks-options" style="display:none;">

                                    <div class="wpc-tab-content-box" id="caching-options">

                                        <?php

                                        $cacheEnabled = $gui::isFeatureEnabled('caching');
                                        $cacheLocked = false;
                                        if (!$cacheEnabled) {
                                            $cacheLocked = true;
                                        }

                                        echo $gui::checkboxTabTitle('Advanced Caching', 'Improve server response times by caching entire pages.', 'tab-icons/caching.svg', ''); ?>
                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Enable Caching', 'Speed up your site by statically caching entire pages.', '', '', ['cache', 'advanced'], $cacheLocked, '', ''); ?>

                                            <?php
                                            echo $gui::buttonDescription_v4('Exclude From Caching', 'Choose specific URLs to exclude from caching.', '', '', ['wpc-excludes', 'cache'], $cacheLocked, '', 'exclude-advanced-caching-popup'); ?>

                                        </div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            #echo $gui::checkboxDescription_v4('Cache Compatibility', 'Prevent cached webpages from opening as a download on LiteSpeed or OpenLiteSpeed servers.', '', '', ['cache', 'compatibility'], $cacheLocked, '', ''); ?>

                                            <?php
                                            #echo $gui::inputDescription_v4('Expire Cache After', 'Recreate cache if it\'s stale or expired after a set duration.', 'Expire after', 'hours', false, ['cache', 'expire'], $cacheLocked, '6'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Ignore Server Cache Control', 'Always cache pages, even when no-cache is set by the server.', '', '', ['cache', 'ignore-server-control'], $cacheLocked, '', '');
                                            ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Cache Logged-In Users', 'Enable caching of frontend pages for logged-in users.', '', '', ['cache', 'cache-logged-in'], $cacheLocked, '', '');
                                            ?>
                                        </div>

                                        <div class="wpc-items-list-row mb-0">

                                                <?php
                                                echo $gui::checkboxDescription_v4('Cache Headers', 'Enable caching of custom headers.', '', '', ['cache', 'headers'], $cacheLocked, '', '');
                                                ?>

			                                    <?php
			                                    echo $gui::checkboxDescription_v4('Purge Cache on Update', 'Purge all cache on plugin, theme, core or menu updates. Purge individual posts and pages on edit.', '', '', ['cache', 'purge-hooks'], $cacheLocked, '', 'purge-settings');
			                                    ?>


                                        </div>


                                    </div>

                                    <div class="wpc-tab-content-box" id="css-optimization-options">
                                        <?php

                                        $cssEnabled = $gui::isFeatureEnabled('css');
                                        $cssLocked = false;
                                        if (!$cssEnabled) {
                                            $cssLocked = true;
                                        }


                                        echo $gui::checkboxTabTitle('CSS Optimizations', "Boost your site's performance by enabling global CSS optimization.", 'css-optimization/css-icon.svg', ''); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row mb-0">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Critical CSS', 'Optimize initial page load by removing unused CSS.', '', '', ['critical', 'css'], $cssLocked, '1', 'exclude-critical-css', false, '', $cssEnabled); ?>

                                            <?php
                                            #echo $gui::checkboxDescription_v4('Inline CSS', 'Insert CSS files directly into your page.', false, '0', 'inline-css', $cssLocked, 'right', 'exclude-inline-css'); ?>


                                        </div>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row mb-0">

                                            <?php
                                            #echo $gui::checkboxDescription_v4('Combine CSS', 'Merge CSS files to minimize HTTP requests.', false, 'combine-css', 'css_combine', false, 'right', 'exclude-css-combine'); ?>

                                            <?php
                                            #echo $gui::checkboxDescription_v4('Optimize Google Fonts', 'Optimize google fonts.', false, 'google_fonts', 'google_fonts', false, 'right', false); ?>

                                            <?php
                                            #echo $gui::checkboxDescription_v4('Minify CSS', 'Reduce CSS file sizes for faster loading.', false, '0', 'css_minify', false, 'right', 'exclude-css-minify'); ?>

                                            <?php
                                            #echo $gui::checkboxDescription_v4( 'Remove Render Blocking', 'Insert CSS files directly into your page.', false, '0', 'remove-render-blocking', false, 'right' ); ?>

                                        </div>

                                    </div>

                                    <div class="wpc-tab-content-box" id="javascript-optimization-options">
                                        <?php

                                        $delayEnabled = $gui::isFeatureEnabled('delay-js');
                                        $delayLocked = false;
                                        if (!$delayEnabled) {
                                            $delayLocked = true;
                                        }

                                        $jsEnabled = $gui::isFeatureEnabled('js');
                                        $jsLocked = false;
                                        if (!$jsEnabled) {
                                            $jsLocked = true;
                                        }

                                        echo $gui::checkboxTabTitle('JavaScript Optimizations', "Enhance your site performance by enabling global JavaScript optimization.", 'javascript-optimization/js-icon.svg', '', '', false, '1', false, false, 'left', 'delay-js-configuration');
                                        //echo $gui::checkboxTabTitle('JavaScript Optimizations', "Enhance your site performance by enabling global JavaScript optimization.", 'javascript-optimization/js-icon.svg', '', '', false); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row mb-20" style="display: none;">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Minify JavaScript', 'Reduce JavaScript file sizes for faster loading.', false, '0', 'js_minify', false, 'right', 'exclude-js-minify'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Combine JavaScript', ' Merge JavaScript files to minimize HTTP requests.', false, 'combine-js', 'js_combine', false, 'right', 'exclude-js-combine'); ?>

                                        </div>
                                        <div class="wpc-items-list-row mb-20" style="display: none;">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Move JavaScript to Footer', 'Improve page load time by moving JS to footer.', false, '0', 'scripts-to-footer', false, 'right', 'exclude-scripts-to-footer', false, false, true); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Defer JavaScript', 'Improve interactivity by loading non-essential scripts later.', false, '0', 'js_defer', false, 'right', 'exclude-js-defer'); ?>

                                        </div>
                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Delay JavaScript', 'Speed up initial response times by delaying unnecessary JS.', false, 'delay-js', 'delay-js', $delayLocked, 'right', 'exclude-js-delay', false, '', $delayEnabled); ?>


                                            <?php
                                            //echo $gui::checkboxDescription_v4('Inline JavaScript', 'Optimize page
                                            // load by inserting JS directly into HTML.', false, '0', 'inline-js', $jsLocked, 'right', 'inline-js'); ?>

                                        </div>

                                    </div>

                                </div>
                                <div class="wpc-tab-content" id="other-optimization-options" style="display:none;">

                                    <div class="wpc-tab-content-box" id="other-optimization">
                                        <?php
                                        echo $gui::checkboxTabTitle('Other Optimizations', 'Advanced tweaks to help for specific use cases, use only as needed.', 'other-optimization/tab-icon.svg', ''); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Disable Emoji', '', false, '0', 'emoji-remove', false, 'right', ''); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Disable oEmbeds', '', false, '0', 'disable-oembeds', false, 'right', ''); ?>

                                        </div>
                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Disable Dashicons', '', false, '0', 'disable-dashicons', false, 'right', ''); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Disable Gutenberg Block', '', false, '0', 'disable-gutenberg', false, 'right', ''); ?>

                                        </div>
                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Optimize External URLs', '', false, '0', 'external-url', false, 'right', ''); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('WooCommerce Tweaks', '', false, '0', 'disable-cart-fragments', false, 'right', ''); ?>

                                        </div>
                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Lazy Load iFrames', '', false, '0', 'iframe-lazy', false, 'right', ''); ?>

                                            <?php
                                            /*
                                            echo $gui::checkboxDescription_v4('Minify HTML', '', false, '0', ['cache',
                                             'minify'], false, 'right');
                                            */ ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Lazy Load FontAwesome', '', false, '0', 'fontawesome-lazy', false, 'right', ''); ?>

                                        </div>

                                        <div class="wpc-items-list-row mb-20">
                                            <?php
                                            echo $gui::checkboxDescription_v4('Lazy Load Google Tag Manager', '', false, '0', 'gtag-lazy', false, 'right', ''); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Lazy Load Fonts', '', false, '0', 'fonts-lazy', false, 'right'); ?>

                                        </div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Disable onLoad Event Trigger', 'Disables event triggers in the rare case of duplicated content or images.', false, false, 'disable-trigger-dom-event', false, 'right', false, false, '', true); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Remove srcset', 'Required on some themes to get better results on Google Page Speed.', false, '0', 'remove-srcset', false, 'right'); ?>

                                        </div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Add Image Sizes', 'Add \'width\' and 
                                          \'height\' to image tags.', false, false, 'add-image-sizes', false, 'right',
                                                false, false, '', true); ?>


                                            <?php
                                            echo $gui::checkboxDescription_v4('Preload Scripts', 'Preload delayed JS scripts for a faster load time.',
                                                false, false, 'preload-scripts', false, 'right',
                                                false, false, '', false); ?>


                                        </div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Set \'fetchpriority\'', 'Set \'fetchpriority\' to high for important images', false,
                                                false, 'fetchpriority-high', false, 'right',
                                                false, false, ''); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4('Retina in srcset', 'Generate retina links in srcset attribute', false,
                                                false, 'retina-in-srcset', false, 'right',
                                                false, false, ''); ?>


                                        </div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Preload critical fonts', 'Preload fonts from generated critical CSS', false, false, 'preload-crit-fonts', false, 'right', false, false, '');

                                            echo $gui::checkboxDescription_v4('Font SubSetting', 'Font subsetting is the practice of embedding only the necessary characters from a font, reducing file size and improving load times.', false, false, 'font-subsetting', false, 'right', false, false, ''); ?>


                                        </div>

                                        <div class="wpc-items-list-row mb-20">

			                                    <?php
			                                    echo $gui::checkboxDescription_v4('Htaccess Webp replace', 'Replace images with webp via .htaccess file when in local mode.', false, false, 'htaccess-webp-replace', false, 'right', false, false, '');

			                                     ?>

                                                <?php

                                                echo $gui::checkboxDescription_v4('Disable Optimizations for logged in users', 'Disable optimizations for logged in users.', false, '0', 'disable-logged-in-opt', false, 'right');

                                                ?>

                                        </div>

                                    </div>

                                </div>
                                <div class="wpc-tab-content" id="smart-optimization" style="display:none;">

                                    <div class="wpc-tab-content-box">

                                        <div class="wpc-optimization-status"
                                             style="display:flex;align-items:center;border:none;">

                                            <div class="d-flex align-items-top gap-3 tab-title-checkbox"
                                                 style="width:100%; padding-right:20px">
                                                <div class="wpc-checkbox-icon">
                                                    <div class="wpc-smart-monitor-img-animated">
                                                        <div class="pulse-container" style="display:none"></div>
                                                        <div style="background-image:url(<?php
                                                        echo WPS_IC_URI . '/assets/v4/images/24monitor.svg' ?>);min-height:100px;min-width:100px;background-repeat:no-repeat;"
                                                             class="background-image wpc-smart-monitor-img">
                                                        </div>
                                                        <div class="shimmer-container" style="display:none"></div>
                                                    </div>
                                                </div>
                                                <div class="wpc-checkbox-description" style="z-index:2">
                                                    <div style="display:flex">
                                                        <h4 class="fs-500 text-dark-300 fw-500 p-inline wpc-smart-optimization-title">
                                                            Smart Optimization + Performance</h4>
                                                        <img src="<?php
                                                        echo WPS_IC_URI . '/assets/v4/images/24bubble.svg' ?>"
                                                             style="padding-left: 15px;height: 30px;padding-top: 2px;">
                                                    </div>
                                                    <p class="wpc-smart-optimization-text" style="margin: 7px 0px 4px">
                                                        No need to lift a finger, your website is intelligently
                                                        optimized around the clock based on demand.</p>
                                                    <div class="optimizations-progress-bar-container">
                                                        <div id="optimizations-progress-bar"
                                                             class="optimizations-progress-bar"
                                                             style="width:100%;"></div>
                                                    </div>
                                                </div>

                                            </div>
                                            <!--
                                                                                          <div class="optimization-progress-outter">
                                                                                              <div class="row" style="display:flex">
                                                                                                  <div style="width:90%;display: flex;">
                                                                                                      <span class="wpc-optimization-complete wpc-page-title" style="line-height: 25px;margin-right:5px;display:none">Smart Optimization Complete!</span>
                                                                                                      <span class="wpc-start-optimizations wpc-page-title" style="line-height: 25px;margin-right:5px;display:none"></span>
                                                                                                      <div class="optimizations-progress-bar-text" style="display: none;">
                                                                                                          <div class="lds-ring">
                                                                                                              <div></div>
                                                                                                              <div></div>
                                                                                                              <div></div>
                                                                                                              <div></div>
                                                                                                          </div>
                                                                                                          <span class="wpc-page-title" style="line-height:25px;margin-right:5px"></span>
                                                                                                          <p class="wpc-status-message"></p>
                                                                                                          <p></p>
                                                                                                      </div>
                                                                                                  </div>
                                                                                              </div>
                                                                                          </div>
                                            -->
                                            <div class="wpc-optimization-status"
                                                 style="display:flex;align-items:center;margin-left:10px;padding-left:20px">
                                                <div class="optimization-image">
                                                    <img src="<?php
                                                    echo WPS_IC_URI . '/assets/v4/images/pages_optimized.svg' ?>" alt=""
                                                         style="margin-top:-5px">
                                                </div>

                                                <div class="optimization-text">
                                                    <div class="optimized-pages-text">0</div>
                                                    <div class="optimized-pages-bottom-text">Preparing</div>
                                                </div>


                                            </div>
                                        </div>

                                        <div class="wpc-spacer"></div>

                                        <div class="dropdown-container selector-dropdown">
                                            <input type="text" id="live-search" placeholder="Search...">
                                            <div class="dropdown" data-dropdown="type">
                                                <div class="dropdown-header">Show By Type
                                                    <div class="wpc-dropdown-row-arrow">
                                                        <i class="icon-down-open"></i>
                                                    </div>
                                                </div>
                                                <div class="dropdown-menu">
                                                    <div class="dropdown-item icon-pages" data-value="page">Pages</div>
                                                    <div class="dropdown-item icon-posts" data-value="post">Posts</div>
                                                    <?php
                                                    if ($productsDefined) { ?>
                                                        <div class="dropdown-item icon-products"
                                                             data-value="product">Products
                                                        </div>
                                                        <?php
                                                    } ?>
                                                </div>
                                            </div>

                                            <div class="dropdown" data-dropdown="status">
                                                <div class="dropdown-header">Status
                                                    <div class="wpc-dropdown-row-arrow">
                                                        <i class="icon-down-open"></i>
                                                    </div>
                                                </div>
                                                <div class="dropdown-menu">
                                                    <div class="dropdown-item" data-value="optimized">Optimized</div>
                                                    <div class="dropdown-item" data-value="skipped">Skipped</div>
                                                    <div class="dropdown-item"
                                                         data-value="unoptimized">Unoptimized
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="dropdown" data-dropdown="optimize">
                                                <div class="dropdown-header">Optimize
                                                    <div class="wpc-dropdown-row-arrow">
                                                        <i class="icon-down-open"></i>
                                                    </div>
                                                </div>
                                                <div class="dropdown-menu">
                                                    <div class="dropdown-item icon-pages <?php
                                                    if (is_array($optimize) && in_array('page', $optimize)) {
                                                        echo 'selected';
                                                    } ?>"
                                                         data-value="page">Pages
                                                    </div>
                                                    <div class="dropdown-item icon-posts <?php
                                                    if (is_array($optimize) && in_array('post', $optimize)) {
                                                        echo 'selected';
                                                    } ?>"
                                                         data-value="post">Posts
                                                    </div>
                                                    <?php
                                                    if ($productsDefined) { ?>
                                                        <div class="dropdown-item icon-products <?php
                                                        if (is_array($optimize) && in_array('product', $optimize)) {
                                                            echo 'selected';
                                                        } ?>"
                                                             data-value="product">Products
                                                        </div>
                                                        <?php
                                                    } ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="wpc-items-list-row mb-20" id="optimizationTable">
                                        </div>
                                        <div id="pagination"></div>

                                    </div>
                                </div>
                                <div class="wpc-tab-content" id="integrations" style="display:none;">
                                    <div class="wpc-tab-content-box" id="cf-connect-options" style="display: block;">
                                        <?php
                                        echo $gui::checkboxTabTitle('CloudFlare Integration', "Seamlessly connect with Cloudflare for automated cache purging and uninterrupted access.", 'cf-logo.png', '', '', '', '', '', '', '', '', 'https://help.wpcompress.com/en-us/article/cloudflare-integration-setup-guide-for-automated-cache-purging-17ger3i/?bust=1739284717272 ', 'How to?'); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row mb-0">

                                            <div class="wpc-cf-connect-form">
                                                <?php
                                                $cf = get_option(WPS_IC_CF);
                                                if (empty($cf)) {
                                                    ?>
                                                    <div class="wpc-cf-loader" style="display: none;">
                                                        <span><div class="wpcLoader"></div> Connecting....</span>
                                                    </div>
                                                    <div class="wpc-cf-loader-zone" style="display: none;">
                                                        <span><div class="wpcLoader"></div> Connecting, this might take up to 60 seconds....</span>
                                                    </div>
                                                    <div class="wpc-input-holder-no-change wpc-cf-token-hide-on-load wpc-cf-insert-token-step">
                                                        <label for="wpc-cf-token">
                                                            <div class="circle-check"></div>
                                                            Login to Cloudflare:</label>
                                                        <input type="text" name="wpc-cf-token" id="wpc-cf-token"/>
                                                        <input type="button" class="wpc-cf-token-check wpc-cf-button" value="Connect"/>
                                                    </div>
                                                    <div class="wpc-select-holder-no-change" id="wpc-cf-zone-list-holder" style="display: none;">
                                                        <input type="hidden" name="wpc-cf-zone" value=""/>
                                                        <label for="wpc-cf-zone-list">
                                                            <div class="circle-check"></div>
                                                            Select account/site you wish to connect:</label>
                                                        <!--<select name="wpc-cf-zone-list" id="wpc-cf-zone-list">
                                                        </select>-->
                                                        <div class="wpc-cf-zone-list">
                                                            <div class="wpc-cf-zone-list-selected" id="wpc-cf-zone-list-selected">Select a zone</div>
                                                            <div class="wpc-cf-zone-list-items" style="display: none;">
                                                            </div>
                                                        </div>
                                                        <input type="button" class="wpc-cf-token-connect wpc-cf-button" value="Connect"/>
                                                    </div>
                                                    <div class="wpc-cf-loader-error" style="display: none;">
                                                        <span></span>
                                                    </div>
                                                <?php } else {
                                                    ?>
                                                    <div class="wpc-cf-loader-disconnecting" style="display: none;">
                                                        <span><div class="wpcLoader"></div> Disconnecting, this might take up to 60 seconds....</span>
                                                    </div>
                                                    <div class="wpc-input-holder-no-change wpc-cf-token-connected">
                                                        <div style="display:flex;align-items: center">
                                                            <label for="wpc-cf-token" style="flex:1;max-width:100px;padding:0;">
                                                                <div class="circle-check active"></div>
                                                                Connected </label>
                                                            <div class="wpc-cf-token-connected-info" style="flex: 2;">
                                                                <div class="wpc-cf-token-connected-info-left">
                                                                    <?php
                                                                    echo '<strong>' . $cf['zoneName'] . '</strong> on ID: <strong>' . $cf['zone'] . '</strong>';
                                                                    ?>
                                                                </div>
                                                                <div class="wpc-cf-token-connected-info-right">
                                                                    <input type="button" class="wpc-cf-token-disconnect wpc-cf-button" value="Disconnect"/>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php } ?>
                                            </div>

                                        </div>
                                    </div>



                                    <?php if (get_option('wpc_show_hidden_menus') == 'true') { ?>
                                    <div class="wpc-tab-content-box">

                                        <?php
                                        echo $gui::checkboxTabTitle('Integrations', '', 'other-optimization/tab-icon.svg', ''); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row mb-20">

                                            <?php
                                            echo $gui::checkboxDescription_v4('Disable Elementor Triggers', 'Can fix double animations, but may break menus and other elementor elements.', false, false, 'disable-elementor-triggers', false, 'right',
                                                false, false, '', true); ?>


                                        </div>


                                    </div>
                                    <?php } ?>
                                </div>

                                <div class="wpc-tab-content" id="export_settings" style="display:none;">
                                    <div class="wpc-tab-content-box">

                                      <?php
                                      echo $gui::checkboxTabTitle('Export/Import settings', 'Export your settings to a file to easily import to other sites.', 'other-optimization/tab-icon.svg', ''); ?>

                                    <div class="wpc-spacer"></div>

                                        <div class="wpc-settings-export-form">
                                            <div class="cdn-popup-inner"">
                                                <div class="wps-default-excludes-enabled-checkbox-container">
                                                    <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-export-settings" checked>
                                                    <p>Export Settings</p>
                                                </div>
                                            </div>
                                            <div class="cdn-popup-inner"">
                                                <div class="wps-default-excludes-enabled-checkbox-container">
                                                    <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-export-excludes">
                                                    <p>Export Excludes</p>
                                                </div>
                                            </div>
                                            <div class="cdn-popup-inner"">
                                                <div class="wps-default-excludes-enabled-checkbox-container">
                                                    <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-export-cache">
                                                    <p>Export Cache Purge Settings</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="wpc-export-import-buttons">
                                            <button id="wpc-export-button" class="wps-ic-help-btn" style="border:none">Export</button>
                                            <button id="wpc-import-button" class="wps-ic-help-btn" style="border:none">Import</button>
                                            <button id="wpc-set-default-button" class="wps-ic-help-btn" style="border:none;float:right">Reset to default</button>
                                            <input type="file" id="wpc-import-file" style="display: none;" accept=".json">
                                        </div>
                                    </div>

                                </div>


                                <div class="wpc-tab-content" id="system-information" style="display:none;">
                                    <div class="wpc-tab-content-box">

                                        <?php
                                        echo $gui::checkboxTabTitle('System Information', '', 'other-optimization/tab-icon.svg', ''); ?>

                                        <div class="wpc-spacer"></div>

                                        <?php
                                        $location = get_option('wps_ic_geo_locate_v2');
                                        if (empty($location)) {
                                            $location = $this->geoLocate();
                                        }

                                        if (is_object($location)) {
                                            $location = (array)$location;
                                        }
                                        ?>

                                        <div class="wpc-items-list-row mb-20" style="flex-direction:column;">
                                            <ul class="wpc-list-item-ul">
                                                <li>WP Version:
                                                    <strong><?php
                                                        global $wp_version;
                                                        echo $wp_version; ?></strong>
                                                </li>
                                                <li>PHP Version:
                                                    <strong><?php
                                                        echo phpversion() ?></strong>
                                                </li>
                                                <li>Site URL:
                                                    <strong><?php
                                                        echo site_url() ?></strong>
                                                </li>
                                                <li>Home URL:
                                                    <strong><?php
                                                        echo home_url() ?></strong>
                                                </li>
                                                <li>API Location:
                                                    <strong><?php
                                                        echo print_r($location, true); ?></strong>
                                                </li>
                                                <li>Bulk Status:
                                                    <strong><?php
                                                        echo print_r(get_option('wps_ic_BulkStatus'), true); ?></strong>
                                                </li>
                                                <li>Parsed Images:
                                                    <strong><?php
                                                        echo print_r(get_option('wps_ic_parsed_images'), true); ?></strong>
                                                </li>
                                                <li>Multisite:
                                                    <strong><?php
                                                        if (is_multisite()) {
                                                            echo 'True';
                                                        } else {
                                                            echo 'False';
                                                        } ?></strong>
                                                </li>
                                                <li>Maximum upload size:
                                                    <strong><?php
                                                        echo size_format(wp_max_upload_size()) ?></strong>
                                                </li>
                                                <li>Memory limit:
                                                    <strong><?php
                                                        echo ini_get('memory_limit') ?></strong>
                                                </li>

                                                <li>Thumbnails:
                                                    <strong><?php
                                                        echo count(get_intermediate_image_sizes()); ?></strong>
                                                </li>

                                                <li>
                                                    <?php
                                                    if (function_exists('file_get_contents')) {
                                                        echo "file_get_contents function is available.";
                                                    } else {
                                                        echo "file_get_contents function is not available.";
                                                    }
                                                    ?>
                                                </li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>


                                <div class="wpc-tab-content" id="debug" style="display:none;">
                                    <?php
                                    include_once 'debug_tool.php'; ?>
                                </div>

                                <div class="wpc-tab-content" id="logger" style="display:none;">
			                            <?php
			                            include_once 'logger_menu.php'; ?>
                                </div>
                            </div>
                        </div>
                        <!-- Tab Content End -->
                    </div>
                </div>
                <!-- Body End -->
        </form>
    </div>

<?php
// Tooltips
include 'partials/tooltips/all.php';

//
include 'partials/popups/compatibility-popups.php';
include 'partials/popups/geolocation.php';
include 'partials/popups/cname.php';
include 'partials/popups/exclude-cdn.php';
include 'partials/popups/exclude-lazy.php';
include 'partials/popups/exclude-webp.php';
include 'partials/popups/exclude-adaptive.php';
include 'partials/popups/exclude-critical-css.php';
include 'partials/popups/exclude-inline-css.php';

// HTML Optimizations
include 'partials/popups/exclude-minify-html.php';

// JS Optimizations
include 'partials/popups/js/delay-js-configuration.php';
include 'partials/popups/js/exclude-js-minify.php';
include 'partials/popups/js/exclude-js-combine.php';
include 'partials/popups/js/exclude-scripts-to-footer.php';
include 'partials/popups/js/exclude-js-defer.php';
include 'partials/popups/js/exclude-js-delay.php';
include 'partials/popups/js/inline-js.php';

// CSS Optimizations
include 'partials/popups/css/exclude-css-combine.php';
include 'partials/popups/css/exclude-css-minify.php';
include 'partials/popups/css/exclude-css-render-blocking.php';
include 'partials/popups/css/inline-css.php';

//Cache
include 'partials/popups/exclude-simple-caching.php';
include 'partials/popups/exclude-advanced-caching.php';
include 'partials/popups/purge-settings.php';

include 'partials/popups/import-export.php';