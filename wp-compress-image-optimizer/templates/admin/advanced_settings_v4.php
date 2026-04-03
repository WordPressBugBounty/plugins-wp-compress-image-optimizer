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


if (is_multisite()) {
    $current_blog_id = get_current_blog_id();
    switch_to_blog($current_blog_id);
}

include WPS_IC_DIR . 'classes/gui-v4.class.php';
$cache = new wps_ic_cache_integrations();

if (!empty($_GET['stopBulk'])) {
    $local = new wps_ic_local();
    $send = $local->sendToAPI(['stop']);
    if ($send) {
        delete_option('wps_ic_parsed_images');
        delete_option('wps_ic_BulkStatus');
        delete_option('wps_ic_bulk_process');
        set_transient('wps_ic_bulk_done', true, 60);

        // Delete all transients
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('wps_ic_compress_') . '%'));
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

// Generate Critical CSS
if (!empty($_GET['generate_crit'])) {
    $page = sanitize_text_field($_GET['generate_crit']);

    if ($page == 'home') {
        $page = site_url();
    }

    $response = wp_remote_post('https://mc-6463k17ku1.bunny.run/critical', array('headers' => array('Content-Type' => 'application/json',), 'body' => json_encode(array('url' => $page . '?criticalCombine=true&wpc-hash=' . time(),)), 'method' => 'POST', 'timeout' => 15, 'blocking' => true,));

    if (is_wp_error($response)) {
        $error_message = $response->get_error_message();
        echo sprintf(esc_html__('Something went wrong: %s', WPS_IC_TEXTDOMAIN), $error_message);
    } else {
        $body = wp_remote_retrieve_body($response);
        if (!is_wp_error($body) && !empty($body)) {
            $bodyDecoded = json_decode($body, true);

            if (!empty($bodyDecoded)) {

                $urlKey = new wps_ic_url_key();
                $urlKey = $urlKey->setup($page);
                $criticalCSS = new wps_criticalCss();

                $response = $criticalCSS->saveCriticalCssText($urlKey, $bodyDecoded['desktop'], 'desktop');
                $response = $criticalCSS->saveCriticalCssText($urlKey, $bodyDecoded['mobile'], 'mobile');

            }
        }

    }


    die();
}


if (!empty($_GET['show_hidden_menus'])) {
    update_option('wpc_show_hidden_menus', sanitize_text_field($_GET['show_hidden_menus']));
}

// Save Settings
if (!empty($_POST['options']['font-display']) && isset($_POST['fonts'])) {
    // Debug tool font-display save (standalone form with name="fonts" submit button)
    $options = get_option(WPS_IC_SETTINGS);
    $options['font-display'] = sanitize_text_field($_POST['options']['font-display']);
    update_option(WPS_IC_SETTINGS, $options);
    $cache::purgeAll(false, false, false, false);
} else if (!empty($_POST['options'])) {

    if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['wpc_settings_save_nonce'], 'wpc_settings_save')) {
        die(esc_html__('No privileges to save options!', WPS_IC_TEXTDOMAIN));
    }

    update_option(WPS_IC_PRESET, sanitize_text_field($_POST['wpc_preset_mode']));

    $submittedOptions = $_POST['options'];
    $optimizatonQuality = 'lossless';

    // EU Routing — call API when changed
    $options = get_option(WPS_IC_SETTINGS);
    if (isset($submittedOptions['eu-routing']) && ($options['eu-routing'] ?? '0') !== $submittedOptions['eu-routing']) {
        $region = 'all';
        if ($submittedOptions['eu-routing'] == '1') {
            $region = 'eu';
        }
        $apiOptions = get_option(WPS_IC_OPTIONS);
        $apikey = $apiOptions['api_key'];
        $requests = new wps_ic_requests();
        $body = $requests->GET(WPS_IC_KEYSURL, ['action' => 'setZoneRouting', 'region' => $region, 'apikey' => $apikey], ['timeout' => 30]);
        if (empty($body)) {
            $submittedOptions['eu-routing'] = $options['eu-routing'] ?? '0';
        }
    }
    // Ensure eu-routing defaults to '0' if checkbox unchecked (not submitted)
    if (!isset($submittedOptions['eu-routing'])) {
        $submittedOptions['eu-routing'] = '0';
    }

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
    $cache::purgeAll(false, false, false, false, true);

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
        //$cache::purgePreloads();
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


    if (!empty($options['live-cdn']) && $options['live-cdn'] == 1) {
        $htacces->removeWebpReplace();
    } else if (!empty($options['htaccess-webp-replace']) && $options['htaccess-webp-replace'] == '1') {
        $htacces->addWebpReplace(); // Should be add webp
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

if (!$allowLive) {
    $settings['live-cdn'] = '0';

    foreach ($settings['serve'] as $key => $value) {
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

///CF integration
$cf = get_option(WPS_IC_CF);
if (!empty($_GET['debugCF'])) {
    #var_dump($cf);
}

if (!empty($cf)) {
    $cfsdk = new WPC_CloudflareAPI($cf['token']);

    // Initialize settings with defaults if not set
    if (!isset($cf['settings'])) {
        $cf['settings'] = ['assets' => '1', 'edge-cache' => 'all', 'cdn' => '1'];
        update_option(WPS_IC_CF, $cf);
    }

    if ($cf['settings']['assets'] == '1' && $cf['settings']['cdn'] == '0') {
        $allowLive = false;

        // Save CDN state before disabling (only if not already saved)
        if (!get_transient('wpc_cdn_backup')) {
            $cdnBackup = [
                'serve' => $settings['serve'],
                'css' => $settings['css'] ?? '0',
                'js' => $settings['js'] ?? '0',
                'fonts' => $settings['fonts'] ?? '0',
                'live-cdn' => $settings['live-cdn'] ?? '0',
            ];
            set_transient('wpc_cdn_backup', $cdnBackup, 0);
        }

        $settings['live-cdn'] = '0';

        foreach ($settings['serve'] as $key => $value) {
            $settings['serve'][$key] = '0';
        }
        $settings['css'] = '0';
        $settings['js'] = '0';
        $settings['fonts'] = '0';

        update_option(WPS_IC_SETTINGS, $settings);
    } else {
        // CF Static Assets off or CF CDN on — restore saved CDN state if available
        $cdnBackup = get_transient('wpc_cdn_backup');
        if (!empty($cdnBackup)) {
            if (isset($cdnBackup['serve'])) $settings['serve'] = $cdnBackup['serve'];
            if (isset($cdnBackup['css'])) $settings['css'] = $cdnBackup['css'];
            if (isset($cdnBackup['js'])) $settings['js'] = $cdnBackup['js'];
            if (isset($cdnBackup['fonts'])) $settings['fonts'] = $cdnBackup['fonts'];
            if (isset($cdnBackup['live-cdn'])) $settings['live-cdn'] = $cdnBackup['live-cdn'];
            update_option(WPS_IC_SETTINGS, $settings);
            delete_transient('wpc_cdn_backup');
        }
    }


    // Check if this is a form submission and CF settings changed
    if (!empty($_POST['options'])) {
        $submittedOptions = $_POST['options'];

        // Get new CF settings from submitted options
        $new_assets = isset($submittedOptions['cf']['assets']) && $submittedOptions['cf']['assets'] == '1' ? '1' : '0';
        $new_edge_cache = isset($submittedOptions['cf']['edge-cache']) ? $submittedOptions['cf']['edge-cache'] : 'home';
        $new_cdn = isset($submittedOptions['cf']['cdn']) && $submittedOptions['cf']['cdn'] == '1' ? '1' : '0';

        // Check if settings changed
        $cf_settings_changed = ($cf['settings']['assets'] != $new_assets || $cf['settings']['edge-cache'] != $new_edge_cache || $cf['settings']['cdn'] != $new_cdn);

        if ($cf_settings_changed) {
            // Initialize error collection
            $error_messages = [];
            $new_cf_settings = $cf['settings'];

            // Handle CDN DNS record first
            if ($new_cdn == '1' && $cf['settings']['cdn'] != '1') {
                //DNS nameserver check
                $url = add_query_arg(['cfDNSCheck' => 'true', 'host' => $cf['zoneName'],], 'https://frankfurt.zapwp.net/');

                $response = wp_remote_get($url, ['timeout' => 15]);
                $hasCloudflare = false;

                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
                    $data = json_decode(wp_remote_retrieve_body($response), true);

                    if (isset($data['data']['records']['result'])) {
                        foreach ($data['data']['records']['result'] as $ns) {
                            if (stripos($ns, 'cloudflare') !== false) {
                                $hasCloudflare = true;
                                break;
                            }
                        }
                    }
                }

                if ($hasCloudflare) {
                    $new_cf_settings['cdn'] = $new_cdn;
                } else {
                    $error_messages[] = __('CDN DNS: Domain DNS is not managed by Cloudflare', WPS_IC_TEXTDOMAIN);
                }
            } elseif ($new_cdn == '0' && $cf['settings']['cdn'] == '1') {
                $new_cf_settings['cdn'] = $new_cdn;
            }

            // Test the cache config update
            $staticAssetsEnabled = $new_assets == '1';
            $htmlCacheMode = $new_edge_cache;

            $result = $cfsdk->configureCF($htmlCacheMode, $staticAssetsEnabled);

            // Ensure CDN bypass rule exists on settings save
            $cfBypassSettings = get_option(WPS_IC_CF);
            if (!empty($cfBypassSettings['zone'])) {
                $cfsdk->addCdnBypassRule($cfBypassSettings['zone']);
            }

            // Check for errors in the result
            if (isset($result['static']) && is_wp_error($result['static'])) {
                $formatted_error = $cfsdk->formatError($result['static'], 'Static Assets', 'Zone - Cache Rules - Edit');
                if ($formatted_error) {
                    $error_messages[] = $formatted_error;
                }
            } else {
                $new_cf_settings['assets'] = $new_assets;
            }

            if (isset($result['homepage']) && is_wp_error($result['homepage'])) {
                $formatted_error = $cfsdk->formatError($result['homepage'], 'Homepage Cache', 'Zone - Cache Rules - Edit');
                if ($formatted_error) {
                    $error_messages[] = $formatted_error;
                }
            } else {
                $new_cf_settings['edge-cache'] = $new_edge_cache;
            }

            if (isset($result['fullhtml']) && is_wp_error($result['fullhtml'])) {
                $formatted_error = $cfsdk->formatError($result['fullhtml'], 'Full HTML Cache', 'Zone - Cache Rules - Edit');
                if ($formatted_error) {
                    $error_messages[] = $formatted_error;
                }
            } else {
                $new_cf_settings['edge-cache'] = $new_edge_cache;
            }

            if (isset($result['tiered_cache']) && is_wp_error($result['tiered_cache'])) {
                $formatted_error = $cfsdk->formatError($result['tiered_cache'], 'Tiered Cache', 'Zone - Zone Settings - Edit');
                if ($formatted_error) {
                    $error_messages[] = $formatted_error;
                }
            }

            // Combine all errors with line breaks
            if (!empty($error_messages)) {
                $cf_error_message = implode('<br><br>', $error_messages);
                $cf_has_error = true;
            }

            // Update settings with successful changes
            $cf = get_option(WPS_IC_CF);
            $cf['settings'] = $new_cf_settings;
            update_option(WPS_IC_CF, $cf);

            $cache::purgeAll(false, false, false, false);

            if (!empty($_GET['dbgCF'])) {
                print_r($result);
            }
        }
    }
}

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
                            $preset = ['recommended' => __('Recommended Mode', WPS_IC_TEXTDOMAIN), 'safe' => __('Safe Mode', WPS_IC_TEXTDOMAIN), 'aggressive' => __('Aggressive Mode', WPS_IC_TEXTDOMAIN), 'custom' => __('Custom', WPS_IC_TEXTDOMAIN)];

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
                            echo '<div class="wpc-header-pro-site"><span>' . esc_html__('Unlimited', WPS_IC_TEXTDOMAIN) . '</span></div>';
                        }
                        ?>
                    </div>
                    <div class="wpc-header-right">
                        <div class="d-flex align-items-center gap-3 gap-md-4 wpc-header-right-inner"
                             style="position: relative;">
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
                                    <button type="button" class="wpc-save-pill-btn wpc-save-button">
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
                            <div class="addon-buttons">
                                <button type="button" class="wpc-icon-style-toggle" title="<?php echo esc_attr__('Switch icon style', WPS_IC_TEXTDOMAIN); ?>">
                                    <svg width="16" height="16" viewBox="0 0 512 512" fill="currentColor"><path d="M464 256l0 16-108.1 0c-64 0-115.9 51.9-115.9 115.9 0 20.2 5.3 39.9 15.1 57.2L237 463.1C131 453.5 48 364.5 48 256 48 141.1 141.1 48 256 48s208 93.1 208 208zM320 448l-12.1-12.1c-12.7-12.7-19.9-30-19.9-48 0-37.5 30.4-67.9 67.9-67.9l156.1 0 0-64C512 114.6 397.4 0 256 0S0 114.6 0 256 114.6 512 256 512c19.4-19.4 40.7-40.7 64-64zM256 160a32 32 0 1 0 0-64 32 32 0 1 0 0 64zm-64 0a32 32 0 1 0 -64 0 32 32 0 1 0 64 0zm-32 96a32 32 0 1 0 -64 0 32 32 0 1 0 64 0zm224-96a32 32 0 1 0 -64 0 32 32 0 1 0 64 0z"/></svg>
                                    <span class="wpc-icon-style-label"><?php echo esc_html__('Style', WPS_IC_TEXTDOMAIN); ?></span>
                                </button>
                                <?php
                                if (!$showAdvanced) {
                                    ?>
                                    <a href="#"
                                       class="wpc-plain-btn wpc-change-ui-to-simple"><img src="<?php
                                        echo WPS_IC_ASSETS; ?>/v4/images/popups/selectMode/advanced-settings.svg"
                                                                                          title="<?php echo esc_attr__('Advanced Settings', WPS_IC_TEXTDOMAIN); ?>"/>
                                        <?php echo esc_html__('Advanced Settings', WPS_IC_TEXTDOMAIN); ?></a>
                                    <?php
                                } else { ?>
                                    <a href="#"
                                       class="wpc-plain-btn wpc-change-ui-to-simple"><img
                                                src="<?php echo WPS_IC_ASSETS; ?>/v4/images/popups/selectMode/advanced-settings.svg"
                                                title="<?php echo esc_attr__('Advanced Settings', WPS_IC_TEXTDOMAIN); ?>"/> <?php echo esc_html__('Simple Settings', WPS_IC_TEXTDOMAIN); ?></a>
                                    <?php
                                } ?>

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
                                                            class="icon-pause"></i> <?php echo esc_html__('Pause Local Optimization', WPS_IC_TEXTDOMAIN); ?></a>
                                                <?php
                                            } ?>
                                        </li>
                                        <li>
                                            <?php
                                            if ($localOptimizationStatus == 'restoring') { ?>
                                                <a href="<?php
                                                echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk&hash=' . time()); ?>"
                                                   class="wps-ic-stop-bulk-restore" style="display:block;"><i
                                                            class="icon-pause"></i> <?php echo esc_html__('Pause Local Restore', WPS_IC_TEXTDOMAIN); ?></a>
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
                                    <a href="#" data-tab="dashboard">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/gauge-high.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title"><?php echo esc_html__('Dashboard', WPS_IC_TEXTDOMAIN); ?></span>
                                    </a>
                                </li>
                                <?php
                                if ($allowLive) { ?>
                                    <li>
                                        <a href="#" class="" data-tab="cdn-delivery-options">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/globe.svg"/>
                                </span>
                                </span>
                                            <span class="wpc-title"><?php echo esc_html__('CDN Delivery', WPS_IC_TEXTDOMAIN); ?></span>
                                        </a>
                                    </li>
                                    <?php
                                } ?>
                                <li>
                                    <a href="#" data-tab="image-optimization-options">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/image.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title"><?php echo esc_html__('Image Optimization', WPS_IC_TEXTDOMAIN); ?></span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="" data-tab="performance-tweaks-options">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/rocket-sr.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title"><?php echo esc_html__('Performance Tweaks', WPS_IC_TEXTDOMAIN); ?></span>
                                    </a>
                                </li>
                                <li style="display: block;">
                                    <a href="#" class="" data-tab="scan-fonts">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/font.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title"><?php echo esc_html__('Font Optimization', WPS_IC_TEXTDOMAIN); ?></span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#"
                                       class=""
                                       data-tab="other-optimization-options">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/sliders.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title"><?php echo esc_html__('Other Optimization', WPS_IC_TEXTDOMAIN); ?></span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="" data-tab="ux-settings-options">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/hand-pointer.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title"><?php echo esc_html__('UX Settings', WPS_IC_TEXTDOMAIN); ?></span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="" data-tab="smart-optimization">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/wand-magic.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title"><?php echo esc_html__('Smart Optimization', WPS_IC_TEXTDOMAIN); ?></span>
                                    </a>
                                </li>
                                <?php
                                $cdn_critical_mc = get_option('wps_ic_critical_mc');
                                if (!empty($cdn_critical_mc) && 1 == 0) {
                                    ?>
                                    <li>
                                        <a href="#" class="" data-tab="critical-css-optimization">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/wand-magic.svg"/>
                                </span>
                                </span>
                                            <span class="wpc-title"><?php echo esc_html__('Critical CSS', WPS_IC_TEXTDOMAIN); ?></span>
                                        </a>
                                    </li>
                                <?php } ?>
                                <li>
                                    <a href="#" class="" data-tab="integrations">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/plug.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title"><?php echo esc_html__('Integrations', WPS_IC_TEXTDOMAIN); ?></span>
                                    </a>
                                </li>
                                <li>
                                    <a href="#" class="" data-tab="export_settings">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon">
                                    <img src="<?php
                                    echo WPS_IC_ASSETS; ?>/v4/images/menu-icons/file-export.svg"/>
                                </span>
                                </span>
                                        <span class="wpc-title"><?php echo esc_html__('Export / Import Settings', WPS_IC_TEXTDOMAIN); ?></span>
                                    </a>
                                </li>

                                <?php
                                if (get_option('wpc_show_hidden_menus') == 'true') {
                                    ?>
                                    <li class="wpc-dev-tools-nav">
                                        <a href="#" class="" data-tab="system-information">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon wpc-icon-svg">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512" fill="currentColor"><path d="M176.1 105.7l-89.9-59.9-8.4 8.4 59.9 89.9 38.3 0 0-38.3zm48-25.7l0 78.1 97.9 97.9 94.1 0c99.4 99.4 152.7 152.7 160 160l-128 128c-7.3-7.3-60.6-60.6-160-160l0-94.1-97.9-97.9-78.1 0-96-144 64-64 144 96zm112 284.1l112 112 60.1-60.1-112-112-60.1 0 0 60.1zM180.7 250.3l33.9 33.9-125.1 125.1-22.6 22.6 45.3 45.3c1.3-1.3 44-44 128-128l0 67.9c-121.5 121.5-116.4 116.4-128 128-.9-.9-33-33-96.2-96.2L-1 432c3.7-3.7 98.1-98.2 181.7-181.7zm155.5-48.2l0-92.1 7-7 53.3-53.3c-6.6-1.1-13.4-1.7-20.3-1.7-44.5 0-83.3 24.2-104 60.1l0-72C300.7 13.5 336.9 0 376.1 0l.8 0c26.8 .1 52.2 6.5 74.7 17.9l1.2 .6c9.3 4.8 18 10.3 26.2 16.7-5.4 5.4-37 37-94.8 94.8l0 30.1 30.1 0c57.9-57.9 89.5-89.5 94.8-94.8 6.3 8.2 11.9 16.9 16.7 26.2l.6 1.2c10.4 20.6 16.6 43.6 17.7 67.9 .1 2.5 .2 5 .2 7.6 0 41.3-14.9 79.2-39.7 108.4l-34.1-34.1c16.1-20.4 25.8-46.3 25.8-74.3 0-6.9-.6-13.7-1.7-20.3l-53.3 53.3-7 7-92.1 0-5.8-5.8z"/></svg>
                                </span>
                                </span>
                                            <span class="wpc-title"><?php echo esc_html__('System Information', WPS_IC_TEXTDOMAIN); ?></span>
                                        </a>
                                    </li>
                                    <li class="wpc-dev-tools-nav">
                                        <a href="#" class="" data-tab="debug">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon wpc-icon-svg">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512" fill="currentColor"><path d="M176.1 105.7l-89.9-59.9-8.4 8.4 59.9 89.9 38.3 0 0-38.3zm48-25.7l0 78.1 97.9 97.9 94.1 0c99.4 99.4 152.7 152.7 160 160l-128 128c-7.3-7.3-60.6-60.6-160-160l0-94.1-97.9-97.9-78.1 0-96-144 64-64 144 96zm112 284.1l112 112 60.1-60.1-112-112-60.1 0 0 60.1zM180.7 250.3l33.9 33.9-125.1 125.1-22.6 22.6 45.3 45.3c1.3-1.3 44-44 128-128l0 67.9c-121.5 121.5-116.4 116.4-128 128-.9-.9-33-33-96.2-96.2L-1 432c3.7-3.7 98.1-98.2 181.7-181.7zm155.5-48.2l0-92.1 7-7 53.3-53.3c-6.6-1.1-13.4-1.7-20.3-1.7-44.5 0-83.3 24.2-104 60.1l0-72C300.7 13.5 336.9 0 376.1 0l.8 0c26.8 .1 52.2 6.5 74.7 17.9l1.2 .6c9.3 4.8 18 10.3 26.2 16.7-5.4 5.4-37 37-94.8 94.8l0 30.1 30.1 0c57.9-57.9 89.5-89.5 94.8-94.8 6.3 8.2 11.9 16.9 16.7 26.2l.6 1.2c10.4 20.6 16.6 43.6 17.7 67.9 .1 2.5 .2 5 .2 7.6 0 41.3-14.9 79.2-39.7 108.4l-34.1-34.1c16.1-20.4 25.8-46.3 25.8-74.3 0-6.9-.6-13.7-1.7-20.3l-53.3 53.3-7 7-92.1 0-5.8-5.8z"/></svg>
                                </span>
                                </span>
                                            <span class="wpc-title"><?php echo esc_html__('Debug', WPS_IC_TEXTDOMAIN); ?></span>
                                        </a>
                                    </li>
                                    <li class="wpc-dev-tools-nav">
                                        <a href="#" class="" data-tab="logger">
                                <span class="wpc-icon-container">
                                <span class="wpc-icon wpc-icon-svg">
                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 576 512" fill="currentColor"><path d="M176.1 105.7l-89.9-59.9-8.4 8.4 59.9 89.9 38.3 0 0-38.3zm48-25.7l0 78.1 97.9 97.9 94.1 0c99.4 99.4 152.7 152.7 160 160l-128 128c-7.3-7.3-60.6-60.6-160-160l0-94.1-97.9-97.9-78.1 0-96-144 64-64 144 96zm112 284.1l112 112 60.1-60.1-112-112-60.1 0 0 60.1zM180.7 250.3l33.9 33.9-125.1 125.1-22.6 22.6 45.3 45.3c1.3-1.3 44-44 128-128l0 67.9c-121.5 121.5-116.4 116.4-128 128-.9-.9-33-33-96.2-96.2L-1 432c3.7-3.7 98.1-98.2 181.7-181.7zm155.5-48.2l0-92.1 7-7 53.3-53.3c-6.6-1.1-13.4-1.7-20.3-1.7-44.5 0-83.3 24.2-104 60.1l0-72C300.7 13.5 336.9 0 376.1 0l.8 0c26.8 .1 52.2 6.5 74.7 17.9l1.2 .6c9.3 4.8 18 10.3 26.2 16.7-5.4 5.4-37 37-94.8 94.8l0 30.1 30.1 0c57.9-57.9 89.5-89.5 94.8-94.8 6.3 8.2 11.9 16.9 16.7 26.2l.6 1.2c10.4 20.6 16.6 43.6 17.7 67.9 .1 2.5 .2 5 .2 7.6 0 41.3-14.9 79.2-39.7 108.4l-34.1-34.1c16.1-20.4 25.8-46.3 25.8-74.3 0-6.9-.6-13.7-1.7-20.3l-53.3 53.3-7 7-92.1 0-5.8-5.8z"/></svg>
                                </span>
                                </span>
                                            <span class="wpc-title"><?php echo esc_html__('Logger', WPS_IC_TEXTDOMAIN); ?></span>
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
                                <div class="wpc-tab-content" id="dashboard" style="display:none;">

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
                                                    if ($cf) {
                                                        echo $gui::CFGraph();
                                                    } else {
                                                        echo $gui::usageGraph();
                                                    }
                                                    ?>
                                                </div>
                                            </div>

                                            <?php

                                            echo $gui::usageStats();


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

                                        echo $gui::checkboxTabTitleCheckbox(__('Real-Time Optimization + CDN', WPS_IC_TEXTDOMAIN), __('Optimize your images and scripts in real-time via our top-rated global CDN.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="currentColor" d="M297 57c-23.8 23.8-40.2 40.2-49 49L214.1 72c8.8-8.8 25.1-25.1 49-49l17-17 33.9 33.9-17 17zM489 249c-23.8 23.8-40.2 40.2-49 49L406.1 264c8.8-8.8 25.1-25.1 49-49l17-17 33.9 33.9-17 17zm17-209l-17 17-128 128-17 17-33.9-33.9 17-17 128-128 17-17 33.9 33.9zM220.2 198.2l33 66.9c51.6 7.5 96.1 14 133.7 19.4-27.2 26.5-59.4 57.9-96.7 94.3 8.8 51.4 16.4 95.7 22.8 133.1-33.6-17.7-73.5-38.6-119.6-62.9-46.1 24.2-86 45.2-119.6 62.9 6.4-37.4 14-81.8 22.8-133.1-37.3-36.4-69.6-67.8-96.7-94.3 37.6-5.5 82.1-11.9 133.7-19.4 23.1-46.7 43-87.1 59.8-121.1l26.8 54.2zm26.1 114.4l-25-3.6c-6.5-13.2-15.8-32.1-27.9-56.6-12.1 24.5-21.4 43.3-27.9 56.6-14.6 2.1-35.4 5.1-62.4 9.1 19.6 19.1 34.6 33.7 45.2 44-2.5 14.5-6 35.2-10.7 62.2 24.2-12.7 42.8-22.5 55.8-29.3 13 6.9 31.6 16.6 55.8 29.3-4.6-26.9-8.2-47.6-10.7-62.2 10.5-10.3 25.6-25 45.2-44l-37.4-5.4z"/></svg>', '', 'cdn-delivery-options', $cdnLocked, '', 'exclude-cdn-popup'); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-items-list-row real-time-optimization">

                                            <?php
                                            echo $gui::iconCheckBox(__('JPG/JPEG', WPS_IC_TEXTDOMAIN), 'cdn-delivery/jpg.svg', ['serve', 'jpg'], $cdnLocked); ?>
                                            <?php
                                            echo $gui::iconCheckBox(__('PNG', WPS_IC_TEXTDOMAIN), 'cdn-delivery/png.svg', ['serve', 'png'], $cdnLocked); ?>
                                            <?php
                                            echo $gui::iconCheckBox(__('GIF', WPS_IC_TEXTDOMAIN), 'cdn-delivery/gif.svg', ['serve', 'gif'], $cdnLocked); ?>
                                            <?php
                                            echo $gui::iconCheckBox(__('SVG', WPS_IC_TEXTDOMAIN), 'cdn-delivery/svg.svg', ['serve', 'svg'], $cdnLocked); ?>

                                            <?php
                                            echo $gui::iconCheckBox(__('CSS', WPS_IC_TEXTDOMAIN), 'cdn-delivery/css.svg', 'css', $cdnLocked); ?>
                                            <?php
                                            echo $gui::iconCheckBox(__('JavaScript', WPS_IC_TEXTDOMAIN), 'cdn-delivery/js.svg', 'js', $cdnLocked); ?>
                                            <?php
                                            echo $gui::iconCheckBox(__('Fonts', WPS_IC_TEXTDOMAIN), 'cdn-delivery/font.svg', 'fonts', $cdnLocked); ?>

                                            <a href="#" class="wpc-purge-icon wpc-purge-cdn-cache" title="<?php echo esc_attr__('Purge CDN Cache', WPS_IC_TEXTDOMAIN); ?>">
                                                <i class="icon-arrows-ccw"></i>
                                            </a>

                                        </div>

                                        <?php
                                        #echo $gui::iconCheckBox('JPG', 'cdn-delivery/jpg.svg', 'jpg'); ?>

                                    </div>

                                    <div class="wpc-cdn-bottom-grid">
                                        <?php
                                        echo $gui::cname(); ?>

                                        <div class="wpc-tab-content-box wpc-tab-content-eu-routing">
                                            <div class="wpc-eu-routing-inner">
                                                <div class="wpc-eu-routing-icon">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="currentColor" d="M464 256a208 208 0 1 0 -416 0 208 208 0 1 0 416 0zM0 256a256 256 0 1 1 512 0 256 256 0 1 1 -512 0zm233.4 22.6L86.3 244.7 369.1 142.9 267.3 425.7 233.4 278.6z"/></svg>
                                                </div>
                                                <div class="wpc-eu-routing-text">
                                                    <h4><?php echo esc_html__('Data Privacy & Routing', WPS_IC_TEXTDOMAIN); ?></h4>
                                                    <p><?php echo esc_html__('Strict EU Routing ensures that your data is processed and served exclusively through European data centers.', WPS_IC_TEXTDOMAIN); ?></p>
                                                </div>
                                            </div>
                                            <div class="wpc-eu-routing-toggle">
                                                <div class="wpc-eu-routing-toggle-row">
                                                    <span><?php echo esc_html__('Enforce EU Routing', WPS_IC_TEXTDOMAIN); ?></span>
                                                    <label class="wpc-switch">
                                                        <input type="checkbox" class="wpc-eu-routing-checkbox" name="options[eu-routing]" value="1" <?php echo (!empty($wps_ic::$settings['eu-routing']) && $wps_ic::$settings['eu-routing'] == '1') ? 'checked' : ''; ?>/>
                                                        <span class="wpc-switch-slider"></span>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                </div>
                                <div class="wpc-tab-content" id="image-optimization-options" style="display:none;">

                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="adaptive-images">
                                        <?php
                                        $adaptiveEnabled = $gui::isFeatureEnabled('adaptive');
                                        $adaptiveLocked = false;
                                        if (!$adaptiveEnabled) {
                                            $adaptiveLocked = true;
                                        }

                                        echo $gui::checkboxTabTitleCheckbox(__('Adaptive Images', WPS_IC_TEXTDOMAIN), __('Intelligently adapt images based on the incoming visitor\'s device, browser, and location on page.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="currentColor" d="M400 80l0 352-352 0 0-352 352 0zM48 32l-48 0 0 448 448 0 0-448-400 0zM160 160a32 32 0 1 0 -64 0 32 32 0 1 0 64 0zm96 48l-63.6 99.9-32.4-51.9-80 128 288 0-112-176z"/></svg>', '', 'adaptive-images', $adaptiveLocked); ?>

                                        <div class="wpc-perf-grid">

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Resize by Incoming Device', WPS_IC_TEXTDOMAIN), __('Serve the ideal image based on the visitor\'s device to reduce file sizes, improve load times, and offer a better experience.', WPS_IC_TEXTDOMAIN), false, '0', 'generate_adaptive', $adaptiveLocked, 'right', 'exclude-adaptive-popup'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Serve WebP Images', WPS_IC_TEXTDOMAIN), __('Generate and serve next-generation WebP images to supported browsers and devices.', WPS_IC_TEXTDOMAIN), false, '0', 'generate_webp', $adaptiveLocked, 'right', 'exclude-webp-popup'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Serve Retina Images', WPS_IC_TEXTDOMAIN), __('Deliver higher-resolution retina images so that your images look great on larger screens.', WPS_IC_TEXTDOMAIN), false, '0', 'retina', $adaptiveLocked, 'right'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Background Images', WPS_IC_TEXTDOMAIN), __('Serve background images over CDN with all the adaptive and quality options.', WPS_IC_TEXTDOMAIN), false, '0', 'background-sizing', $adaptiveLocked, 'right'); ?>

                                        </div>

                                    </div>

                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="lazy-images">
                                        <?php

                                        $lazyEnabled = $gui::isFeatureEnabled('lazy');
                                        $lazyLocked = false;
                                        if (!$lazyEnabled) {
                                            $lazyLocked = true;
                                        }

                                        echo $gui::checkboxTabTitle(__('Lazy Loading', WPS_IC_TEXTDOMAIN), __('Intelligently lazy load images based on the viewport position.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="currentColor" d="M256 0C397.4 0 512 114.6 512 256S397.4 512 256 512 0 397.4 0 256c0-37.9 8.2-73.8 23-106.1 6-13.2 13.1-25.8 21.2-37.6l.1-.2C53.4 98.7 63.6 86.3 75 75l33.9 33.9c-9.2 9.2-17.6 19.3-25 30.1l-.1 .2c-21.2 31.2-34.2 68.5-35.7 108.7-.1 2.7-.2 5.4-.2 8.1 0 114.9 93.1 208 208 208s208-93.1 208-208c0-106.8-80.4-194.7-184-206.6l0 78.6-48 0 0-128 24 0zM176 142.1c.8 .8 33.1 33.1 97 97l17 17-33.9 33.9-17-17c-63.8-63.8-96.2-96.2-97-97L176 142.1z"/></svg>', '', ''); ?>

                                        <div class="wpc-perf-grid">
                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Native Lazy', WPS_IC_TEXTDOMAIN), __('Lazy load images using browser methods to save bandwidth and reduce overall page size.', WPS_IC_TEXTDOMAIN), false, '0', 'nativeLazy', $lazyLocked, 'right', 'exclude-lazy-popup'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Lazy Loading by Viewport', WPS_IC_TEXTDOMAIN), __('Load additional images as the user scrolls to save bandwidth and reduce overall page size.', WPS_IC_TEXTDOMAIN), false, '0', 'lazy', $lazyLocked, 'right', 'exclude-lazy-popup'); ?>

                                            <?php
                                            echo $gui::inputDescription_v4(__('Skip Lazy Loading', WPS_IC_TEXTDOMAIN), __('Enter how many images to skip lazy loading for on each page.', WPS_IC_TEXTDOMAIN), __('Skip', WPS_IC_TEXTDOMAIN), __('images', WPS_IC_TEXTDOMAIN), false, 'lazySkipCount', $lazyLocked, '4'); ?>
                                        </div>

                                    </div>

                                    <?php

                                    if (!empty($allowLocal)) { ?>
                                        <?php
                                        $qualityLevel = 2;
                                        if (isset($gui::$options['optimization'])) {
                                            switch ($gui::$options['optimization']) {
                                                case '1': case 'lossless': $qualityLevel = 1; break;
                                                case '2': case 'intelligent': $qualityLevel = 2; break;
                                                case '3': case 'ultra': $qualityLevel = 3; break;
                                            }
                                        }
                                        ?>
                                        <div class="wpc-tab-content-box wpc-optimization-level-card">
                                            <div class="wpc-opt-level-header">
                                                <div class="wpc-opt-level-icon">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="currentColor" d="M404.7 401.5L348.4 304c-10 17.3-40.8 70.7-92.4 160 58.2 0 110.9-23.9 148.7-62.5zM436.2 360c17.7-30.6 27.8-66.1 27.8-104 0-19.4-2.7-38.2-7.6-56l-112.6 0c10 17.3 40.8 70.7 92.4 160zm0-208c-27.6-47.8-73.7-83.5-128.5-97.5l-56.3 97.5 184.8 0zM256 48c-58.2 0-110.9 23.9-148.7 62.5L163.6 208c10-17.3 40.8-70.7 92.4-160zM75.8 152c-17.7 30.6-27.8 66.1-27.8 104 0 19.4 2.7 38.2 7.6 56l112.6 0c-10-17.3-40.8-70.7-92.4-160zm0 208c27.6 47.8 73.7 83.5 128.5 97.5l56.3-97.5-184.8 0zM0 256a256 256 0 1 1 512 0 256 256 0 1 1 -512 0zm223.7-56l-32.3 56 32.3 56 64.7 0 32.3-56-32.3-56-64.7 0z"/></svg>
                                                </div>
                                                <div class="wpc-opt-level-text">
                                                    <h4><?php echo esc_html__('Optimization Level', WPS_IC_TEXTDOMAIN); ?></h4>
                                                    <p><?php echo esc_html__('Select your preferred image compression strength.', WPS_IC_TEXTDOMAIN); ?></p>
                                                </div>
                                            </div>
                                            <div class="wpc-opt-level-slider">
                                                <div class="wpc-range-slider">
                                                    <input id="optimizationLevel" name="options[qualityLevel]" type="range" step="1" value="<?php echo $qualityLevel; ?>" min="1" max="3">
                                                </div>
                                                <div class="wpc-slider-text d-flex align-items-center justify-content-between">
                                                    <div class="text-min" data-value="1"><?php echo esc_html__('Lossless', WPS_IC_TEXTDOMAIN); ?></div>
                                                    <div class="text-middle" data-value="2"><?php echo esc_html__('Intelligent', WPS_IC_TEXTDOMAIN); ?></div>
                                                    <div class="text-max" data-value="3"><?php echo esc_html__('Ultra', WPS_IC_TEXTDOMAIN); ?></div>
                                                </div>
                                            </div>
                                        </div>

                                        <?php
                                        // Auto-Optimize on Upload — EU Routing style
                                        $onUploadActive = (isset($gui::$options['on-upload']) && $gui::$options['on-upload'] == '1');
                                        $onUploadChecked = $onUploadActive ? 'checked' : '';
                                        ?>
                                        <div class="wpc-tab-content-box wpc-auto-optimize-card">
                                            <div class="wpc-auto-optimize-inner">
                                                <div class="wpc-auto-optimize-icon">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="currentColor" d="M248 328l0 24-48 0 0-270.1c-49.7 49.7-76.4 76.4-80 80l-33.9-33.9 17-17 104-104 17-17 17 17 104 104 17 17-33.9 33.9c-3.6-3.6-30.3-30.3-80-80L248 328zm-96-8l-104 0 0 112 352 0 0-112-104 0 0-48 152 0 0 208-448 0 0-208 152 0 0 48zm168 56a24 24 0 1 1 48 0 24 24 0 1 1 -48 0z"/></svg>
                                                </div>
                                                <div class="wpc-auto-optimize-text">
                                                    <h4><?php echo esc_html__('Auto-Optimize on Upload', WPS_IC_TEXTDOMAIN); ?></h4>
                                                    <p><?php echo esc_html__('Automatically compress new media library images as they\'re uploaded.', WPS_IC_TEXTDOMAIN); ?></p>
                                                </div>
                                            </div>
                                            <div class="wpc-auto-optimize-toggle">
                                                <label class="wpc-switch">
                                                    <input type="checkbox" class="wps-ic-settings-v2-checkbox" name="options[on-upload]" value="1" data-option-name="options[on-upload]" data-recommended="0" data-safe="0" <?php echo $onUploadChecked; ?>>
                                                    <div class="wpc-switch-slider"></div>
                                                </label>
                                            </div>
                                        </div>

                                        <div class="wpc-tab-content-box wpc-optimize-library-card">
                                            <div class="wpc-optimize-library-inner">
                                                <div class="wpc-optimize-library-icon">
                                                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                                </div>
                                                <div class="wpc-optimize-library-text">
                                                    <h4><?php echo esc_html__('Optimize Media Library', WPS_IC_TEXTDOMAIN); ?></h4>
                                                    <p><?php echo esc_html__('Optimize locally stored images directly in bulk.', WPS_IC_TEXTDOMAIN); ?></p>
                                                </div>
                                            </div>
                                            <a class="wpc-optimize-library-btn" href="<?php echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=bulk'); ?>">
                                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                                <?php echo esc_html__('Optimize Media', WPS_IC_TEXTDOMAIN); ?>
                                            </a>
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
                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="ux-display-settings">
                                        <?php
                                        echo $gui::checkboxTabTitle(__('Display Settings', WPS_IC_TEXTDOMAIN), __('Control which plugin elements are visible in your WordPress admin.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="currentColor" d="M24 72l-24 0 0 48 112 0 0 56 160 0 0 56-272 0 0 48 272 0 0 56 160 0 0-56 80 0 0-48-80 0 0-56-160 0 0-56 240 0 0-48-240 0 0-56-160 0 0 56-88 0zM320 280l0-56 64 0 0 64-64 0 0-8zM160 120l0-56 64 0 0 64-64 0 0-8zM24 392l-24 0 0 48 80 0 0 56 160 0 0-56 272 0 0-48-272 0 0-56-160 0 0 56-56 0zm104 48l0-56 64 0 0 64-64 0 0-8z"/></svg>', '', ''); ?>

                                        <div class="wpc-perf-grid">
                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Hide in Admin Bar', WPS_IC_TEXTDOMAIN), __('Remove the WP Compress icon and per-page tools from the WordPress admin bar.', WPS_IC_TEXTDOMAIN), false, '0', ['status', 'hide_in_admin_bar'], false, 'right'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Show Title in Admin Bar', WPS_IC_TEXTDOMAIN), __('Display the plugin name next to the icon in the WordPress admin toolbar.', WPS_IC_TEXTDOMAIN), false, '0', ['status', 'show_admin_bar_title'], false, 'right'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Hide Cache Status', WPS_IC_TEXTDOMAIN), __('Hide the cache status indicator from the admin bar on each page.', WPS_IC_TEXTDOMAIN), false, '0', ['status', 'hide_cache_status'], false, 'right'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Hide Critical CSS Status', WPS_IC_TEXTDOMAIN), __('Hide the critical CSS generation status from the admin bar.', WPS_IC_TEXTDOMAIN), false, '0', ['status', 'hide_critical_css_status'], false, 'right'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Hide Preloading Status', WPS_IC_TEXTDOMAIN), __('Hide the preloading status indicator from the admin bar.', WPS_IC_TEXTDOMAIN), false, '0', ['status', 'hide_preload_status'], false, 'right'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Hide from WordPress', WPS_IC_TEXTDOMAIN), __('Completely hide the plugin from the WordPress admin area.', WPS_IC_TEXTDOMAIN), false, 'hide_compress', 'hide_compress', false, 'right'); ?>

                                            <?php
                                            if (!empty($allowLocal)) {
                                                echo $gui::checkboxDescription_v4(__('Show in Media Library', WPS_IC_TEXTDOMAIN), __('Add compress, exclude, and restore options to Media Library list view.', WPS_IC_TEXTDOMAIN), false, '0', ['local', 'media-library'], false, 'right');
                                            } ?>
                                        </div>

                                    </div>
                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="ux-permissions">
                                        <?php
                                        echo $gui::checkboxTabTitle(__('User Permissions', WPS_IC_TEXTDOMAIN), __('Control which user roles can access plugin features.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="currentColor" d="M267.6 4.5l207.5 80.5 19.2 7.4 1.2 20.5c2.9 50-4.9 126.3-37.3 200.9-32.7 75.2-91.1 150-189.4 192.5l-12.7 5.5-12.7-5.5C144.9 463.9 86.6 389.2 53.9 313.9 21.5 239.3 13.7 162.9 16.6 113L17.8 92.5 37 85 244.5 4.5 256 0 267.6 4.5zM64.1 126C63.1 169.5 71 232.9 97.9 294.8 126.1 359.7 175 422.4 256 459.6 337.1 422.4 385.9 359.7 414.2 294.8 441 232.9 449 169.5 448 126L256 51.5 64.1 126zm302.3 44.7L352.3 190.1 249.8 330.9 233 354c-8.8-9.1-30.9-32-66.2-68.6l-16.7-17.3 34.5-33.3c9.5 9.8 23.9 24.7 43.2 44.7l85.6-117.7 14.1-19.4 38.8 28.2z"/></svg>', '', ''); ?>

                                        <?php
                                        $users = new wps_ic_users();
                                        $roles = $users->getRoles(['skip_admin' => true]);

                                        // Permission definitions
                                        $permissions = [
                                            'purge' => [
                                                'label' => __('Purge', WPS_IC_TEXTDOMAIN),
                                                'desc'  => __('Clear CDN, HTML cache, Critical CSS & Cloudflare', WPS_IC_TEXTDOMAIN),
                                                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M142.9 142.9c-17.5 17.5-30.1 38-37.8 59.8c-5.9 16.7-24.2 25.4-40.8 19.5s-25.4-24.2-19.5-40.8c10.8-30.6 28.4-59.3 52.9-83.8c62.5-62.5 163.8-62.5 226.3 0l5.1 5.1L340.1 16H480c8.8 0 16 7.2 16 16V171.9l-87.2-11L397.8 172c-62.5 62.5-163.8 62.5-226.3 0l-5.1-5.1-5.1-5.1c-25-25-25-65.5 0-90.5s65.5-25 90.5 0zM369.1 369.1c17.5-17.5 30.1-38 37.8-59.8c5.9-16.7 24.2-25.4 40.8-19.5s25.4 24.2 19.5 40.8c-10.8 30.6-28.4 59.3-52.9 83.8c-62.5 62.5-163.8 62.5-226.3 0l-5.1-5.1L171.9 496H32c-8.8 0-16-7.2-16-16V340.1l87.2 11L114.2 340c62.5-62.5 163.8-62.5 226.3 0l5.1 5.1 5.1 5.1c25 25 25 65.5 0 90.5s-65.5 25-90.5 0z"/></svg>',
                                                'suffix' => '_purge'
                                            ],
                                            'manage' => [
                                                'label' => __('Settings', WPS_IC_TEXTDOMAIN),
                                                'desc'  => __('View and modify all plugin settings', WPS_IC_TEXTDOMAIN),
                                                'icon'  => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" fill="currentColor"><path d="M495.9 166.6c3.2 8.7 .5 18.4-6.4 24.6l-43.3 39.4c1.1 8.3 1.7 16.8 1.7 25.4s-.6 17.1-1.7 25.4l43.3 39.4c6.9 6.2 9.6 15.9 6.4 24.6c-4.4 11.9-9.7 23.3-15.8 34.3l-4.7 8.1c-6.6 11-14 21.4-22.1 31.2c-5.9 7.2-15.7 9.6-24.5 6.8l-55.7-17.7c-13.4 10.3-28.2 18.9-44 25.4l-12.5 57.1c-2 9.1-9 16.3-18.2 17.8c-13.8 2.3-28 3.5-42.5 3.5s-28.7-1.2-42.5-3.5c-9.2-1.5-16.2-8.7-18.2-17.8l-12.5-57.1c-15.8-6.5-30.6-15.1-44-25.4L83.1 425.9c-8.8 2.8-18.6 .3-24.5-6.8c-8.1-9.8-15.5-20.2-22.1-31.2l-4.7-8.1c-6.1-11-11.4-22.4-15.8-34.3c-3.2-8.7-.5-18.4 6.4-24.6l43.3-39.4C64.6 273.1 64 264.6 64 256s.6-17.1 1.7-25.4L22.4 191.2c-6.9-6.2-9.6-15.9-6.4-24.6c4.4-11.9 9.7-23.3 15.8-34.3l4.7-8.1c6.6-11 14-21.4 22.1-31.2c5.9-7.2 15.7-9.6 24.5-6.8l55.7 17.7c13.4-10.3 28.2-18.9 44-25.4l12.5-57.1c2-9.1 9-16.3 18.2-17.8C227.3 1.2 241.5 0 256 0s28.7 1.2 42.5 3.5c9.2 1.5 16.2 8.7 18.2 17.8l12.5 57.1c15.8 6.5 30.6 15.1 44 25.4l55.7-17.7c8.8-2.8 18.6-.3 24.5 6.8c8.1 9.8 15.5 20.2 22.1 31.2l4.7 8.1c6.1 11 11.4 22.4 15.8 34.3zM256 336a80 80 0 1 0 0-160 80 80 0 1 0 0 160z"/></svg>',
                                                'suffix' => '_manage_wpc'
                                            ],
                                        ];

                                        // Role avatar colors
                                        $roleColors = ['#6366f1', '#3b82f6', '#10b981', '#f59e0b'];

                                        if (!empty($roles)) {
                                        ?>
                                        <div class="wpc-permissions-matrix">
                                            <div class="wpc-perm-header">
                                                <div class="wpc-perm-role-col"><?php echo esc_html__('Role', WPS_IC_TEXTDOMAIN); ?></div>
                                                <?php foreach ($permissions as $perm) { ?>
                                                    <div class="wpc-perm-col">
                                                        <span class="wpc-perm-col-icon"><?php echo $perm['icon']; ?></span>
                                                        <span class="wpc-perm-col-label"><?php echo $perm['label']; ?></span>
                                                        <span class="wpc-perm-col-desc"><?php echo $perm['desc']; ?></span>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                            <?php
                                            $colorIndex = 0;
                                            foreach ($roles as $key => $role) {
                                                $color = $roleColors[$colorIndex % count($roleColors)];
                                                $initial = strtoupper(substr($role, 0, 1));
                                                $colorIndex++;
                                            ?>
                                            <div class="wpc-perm-row">
                                                <div class="wpc-perm-role-col">
                                                    <span class="wpc-role-avatar" style="background: <?php echo $color; ?>;"><?php echo $initial; ?></span>
                                                    <span class="wpc-role-name"><?php echo esc_html($role); ?></span>
                                                </div>
                                                <?php foreach ($permissions as $permKey => $perm) {
                                                    $optKey = $key . $perm['suffix'];
                                                    $optName = 'options[permissions][' . $optKey . ']';
                                                    $optId = 'options_permissions_' . $optKey;
                                                    $isActive = isset(wpc_gui_v4::$options['permissions'][$optKey]) && wpc_gui_v4::$options['permissions'][$optKey] == '1';
                                                ?>
                                                <div class="wpc-perm-col">
                                                    <label class="wpc-switch">
                                                        <input type="checkbox" class="wpc-ic-settings-v4-checkbox" value="1" id="<?php echo $optId; ?>" name="<?php echo $optName; ?>" data-recommended="0" data-safe="0" data-connected-slave-option="<?php echo $optId; ?>" <?php echo $isActive ? 'checked="checked"' : ''; ?> />
                                                        <span class="wpc-switch-slider wpc-switch-round"></span>
                                                    </label>
                                                </div>
                                                <?php } ?>
                                            </div>
                                            <?php } ?>
                                        </div>
                                        <?php } ?>
                                    </div>
                                </div>
                                <div class="wpc-tab-content" id="performance-tweaks-options" style="display:none;">

                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="caching-options">

                                        <?php

                                        $cacheEnabled = $gui::isFeatureEnabled('caching');
                                        $cacheLocked = false;
                                        if (!$cacheEnabled) {
                                            $cacheLocked = true;
                                        }

                                        echo $gui::checkboxTabTitle(__('Advanced Caching', WPS_IC_TEXTDOMAIN), __('Serve static versions of your pages for faster load times and lower server load.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><path fill="currentColor" d="M511.9 19.3L508.8 3.6 493.1 .5c-45-9-114-14.7-180.7 19.7-40 20.7-77.3 54.8-107.7 107.8L96.4 128C45.2 213.3 13.2 266.7 .4 288l158.1 0 1 1 64 64 1 1 0 158.1c21.3-12.8 74.7-44.8 160-96l0-108.3c53.1-30.4 87.2-67.7 107.8-107.7 34.4-66.7 28.7-135.7 19.7-180.7zM272.4 352.7c23.3-6.4 44.6-13.6 64-21.6l0 57.8-64 38.4 0-74.5zM181.3 176c-8 19.4-15.2 40.7-21.6 64l-74.5 0 38.4-64 57.8 0zM334.4 62.8c45.9-23.7 94.6-23.8 133.2-18.1 5.8 38.6 5.6 87.3-18.1 133.2-25.7 49.9-81.9 101.3-201.7 131.5l-45-45c30.2-119.9 81.7-176 131.5-201.7zm74 81.2a40 40 0 1 0 -80 0 40 40 0 1 0 80 0zM152.8 473.6c31.5-31.5 31.5-82.5 0-114s-82.5-31.5-114 0c-45.3 45.3-38 152-38 152s106.7 7.3 152-38zm-40.6-32C95.7 458.1 57 455.4 57 455.4s-2.7-38.7 13.8-55.2c11.4-11.4 30-11.4 41.4 0s11.4 30 0 41.4z"/></svg>', '', '', false, '1', false, false, 'left', false, false, false, 'wpc-purge-html-cache'); ?>

                                        <div class="wpc-perf-grid">

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Enable Caching', WPS_IC_TEXTDOMAIN), __('Dramatically speed up your site by serving pre-built pages to every visitor.', WPS_IC_TEXTDOMAIN), '', '', ['cache', 'advanced'], $cacheLocked, '', ''); ?>

                                            <?php
                                            echo $gui::buttonDescription_v4(__('Exclude URLs', WPS_IC_TEXTDOMAIN), __('Prevent specific pages or URLs from being cached.', WPS_IC_TEXTDOMAIN), '', '', ['wpc-excludes', 'cache'], $cacheLocked, '', 'exclude-advanced-caching-popup'); ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Ignore Server Cache Control', WPS_IC_TEXTDOMAIN), __('Cache pages even when the server sends no-cache headers. Useful when hosting or plugins set overly strict rules.', WPS_IC_TEXTDOMAIN), '', '', ['cache', 'ignore-server-control'], $cacheLocked, '', '');
                                            ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Cache Logged-In Users', WPS_IC_TEXTDOMAIN), __('Serve cached pages to logged-in users. Best for membership sites where frontend content rarely changes per user.', WPS_IC_TEXTDOMAIN), '', '', ['cache', 'cache-logged-in'], $cacheLocked, '', '');
                                            ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Cache Response Headers', WPS_IC_TEXTDOMAIN), __('Store custom HTTP response headers alongside cached pages to preserve server behavior.', WPS_IC_TEXTDOMAIN), '', '', ['cache', 'headers'], $cacheLocked, '', '');
                                            ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Auto-Purge on Update', WPS_IC_TEXTDOMAIN), __('Automatically clear cache when you update plugins, themes, or edit content so visitors always see the latest version.', WPS_IC_TEXTDOMAIN), '', '', ['cache', 'purge-hooks'], $cacheLocked, '', 'purge-settings');
                                            ?>

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Cache by Cookie', WPS_IC_TEXTDOMAIN), __('Serve different cached versions based on cookie values. Required for multi-currency, geo-targeting, or consent-based content.', WPS_IC_TEXTDOMAIN), '', '', ['cache', 'cookies'], $cacheLocked, '', 'cache-cookies');
                                            ?>

                                        </div>

                                    </div>

                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="css-optimization-options">
                                        <?php

                                        $cssEnabled = $gui::isFeatureEnabled('css');
                                        $cssLocked = false;
                                        if (!$cssEnabled) {
                                            $cssLocked = true;
                                        }


                                        echo $gui::checkboxTabTitle(__('Smart CSS', WPS_IC_TEXTDOMAIN), __('Remove render-blocking CSS so your pages appear instantly.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="currentColor" d="M376.3 32L0 32 0 408.3c0 19 7.6 37.2 21 50.7s31.7 21 50.7 21l304.6 0c19 0 37.2-7.6 50.7-21s21-31.7 21-50.7l0-304.6c0-19-7.6-37.2-21-50.7s-31.7-21-50.7-21zM332.4 431.4c-7.7-8.5-11.7-20.7-12-36.6l31.3 0c.2 14.1 5.1 21.1 14.8 21.1c4.9 0 8.4-1.6 10.5-4.7c2-3.1 3-8 3-14.8c0-5.4-1.3-9.9-4-13.4c-3.5-4.2-8.1-7.5-13.2-9.5L351.2 368c-10.3-4.9-17.8-10.8-22.5-17.6c-4.5-6.8-6.7-16.3-6.7-28.4c0-13.6 4-24.6 11.8-33.1c8.1-8.5 19.1-12.7 33.2-12.7c13.6 0 24.1 4.2 31.5 12.5c7.5 8.4 11.5 20.3 11.8 35.9l-30.1 0c.2-5.1-.9-10.2-3-14.8c-1.7-3.4-5-5.1-10-5.1c-8.8 0-13.2 5.2-13.2 15.7c0 5.3 1.1 9.4 3.2 12.6c3.1 3.5 7 6.2 11.4 7.8l11.1 4.9c11.5 5.3 19.7 11.7 24.8 19.4c5.1 7.7 7.6 18 7.6 31c0 15.5-4 27.4-12.3 35.7c-8.2 8.3-19.5 12.5-34.1 12.5s-25.6-4.2-33.4-12.7zm-101 0c-7.7-8.5-11.7-20.7-12-36.6l31.3 0c.2 14.1 5.1 21.1 14.8 21.1c4.9 0 8.4-1.6 10.4-4.7c2-3.1 3-8 3-14.8c0-5.4-1.3-9.9-3.9-13.4c-3.5-4.2-8.1-7.5-13.2-9.5L250.2 368c-10.3-4.9-17.8-10.8-22.5-17.6c-4.5-6.8-6.7-16.3-6.7-28.4c0-13.6 4-24.6 11.8-33.1c8.1-8.5 19.1-12.7 33.2-12.7c13.6 0 24.1 4.2 31.4 12.5c7.6 8.4 11.5 20.3 11.9 35.9l-30.1 0c.2-5.1-.9-10.2-3-14.8c-1.7-3.4-5-5.1-10-5.1c-8.8 0-13.2 5.2-13.2 15.7c0 5.3 1.1 9.4 3.2 12.6c3.1 3.5 7 6.2 11.4 7.8l11.1 4.9c11.5 5.3 19.7 11.7 24.8 19.4c5.1 7.7 7.6 18 7.6 31c0 15.5-4.1 27.4-12.3 35.7s-19.5 12.5-34.1 12.5s-25.6-4.2-33.4-12.7zm-105.6 1.1c-8.4-7.7-12.5-19.2-12.5-34.5l0-75.4c0-15.2 4.4-26.7 13.2-34.6c8.9-7.8 20.7-11.8 35.2-11.8c14.1 0 25.2 4 33.4 12c8.3 8 12.5 20 12.5 35.9l0 6-33.1 0 0-5.8c0-6.1-1.3-10.7-4-13.6c-1.1-1.5-2.6-2.7-4.3-3.5s-3.5-1.2-5.4-1.1c-5.4 0-9.2 1.8-11.4 5.6c-2.3 5.2-3.3 10.8-3 16.4l0 65.5c0 13.7 4.8 20.6 14.4 20.8c4.5 0 7.9-1.6 10.2-4.8c2.5-4.1 3.7-8.8 3.5-13.6l0-4.9 33.1 0 0 5.1c0 10.6-2.1 19.5-6.2 26.6c-4 6.9-9.9 12.5-17.1 16c-7.7 3.7-16.1 5.5-24.6 5.3c-14.2 0-25.5-3.9-33.8-11.6z"/></svg>', ''); ?>

                                        <div class="wpc-perf-grid wpc-perf-grid-single">

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Optimize CSS', WPS_IC_TEXTDOMAIN), __('Automatically extracts critical styles and removes render-blocking CSS for instant page rendering.', WPS_IC_TEXTDOMAIN), '', '', ['critical', 'css'], $cssLocked, '1', 'exclude-critical-css', false, '', $cssEnabled); ?>

                                        </div>

                                    </div>

                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="javascript-optimization-options">
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

                                        echo $gui::checkboxTabTitle(__('Smart JavaScript', WPS_IC_TEXTDOMAIN), __('Reduce script blocking so your pages load and respond faster.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="currentColor" d="M448 96c0-35.3-28.7-64-64-64L64 32C28.7 32 0 60.7 0 96L0 416c0 35.3 28.7 64 64 64l320 0c35.3 0 64-28.7 64-64l0-320zM180.9 444.9c-33.7 0-53.2-17.4-63.2-38.5L152 385.7c6.6 11.7 12.6 21.6 27.1 21.6 13.8 0 22.6-5.4 22.6-26.5l0-143.1 42.1 0 0 143.7c0 43.6-25.6 63.5-62.9 63.5zm85.8-43L301 382.1c9 14.7 20.8 25.6 41.5 25.6 17.4 0 28.6-8.7 28.6-20.8 0-14.4-11.4-19.5-30.7-28l-10.5-4.5c-30.4-12.9-50.5-29.2-50.5-63.5 0-31.6 24.1-55.6 61.6-55.6 26.8 0 46 9.3 59.8 33.7L368 290c-7.2-12.9-15-18-27.1-18-12.3 0-20.1 7.8-20.1 18 0 12.6 7.8 17.7 25.9 25.6l10.5 4.5c35.8 15.3 55.9 31 55.9 66.2 0 37.8-29.8 58.6-69.7 58.6-39.1 0-64.4-18.6-76.7-43z"/></svg>', '', '', false, '1', false, false, 'left');
                                        ?>

                                        <div class="wpc-perf-grid wpc-perf-grid-single">

                                            <?php
                                            echo $gui::checkboxDescription_v4(__('Optimize JavaScript', WPS_IC_TEXTDOMAIN), __('Automatically delays non-essential scripts so your pages load and respond faster.', WPS_IC_TEXTDOMAIN), false, 'delay-js', 'delay-js-v2', $delayLocked, 'right', 'exclude-js-delay-v2', false, '', $delayEnabled); ?>

                                        </div>

                                    </div>
                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="developer-mode-options">
                                        <?php
                                        echo $gui::checkboxTabTitle(__('Developer Mode', WPS_IC_TEXTDOMAIN), __('Pause cache purging and Critical CSS generation while you make changes.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512"><path fill="currentColor" d="M369.5 16.9l7.1-22.9 45.8 14.2-7.1 22.9-144 464-7.1 22.9-45.8-14.2 7.1-22.9 144-464zM194.4 152l-17 17-87 87 87 87 17 17-33.9 33.9-17-17-104-104-17-17 17-17 104-104 17-17 33.9 33.9zm252.1 0l33.9-33.9 17 17 104 104 17 17-17 17-104 104-17 17-33.9-33.9 17-17 87-87-87-87-17-17z"/></svg>', '', '', false, '1', false, false, 'left');
                                        ?>

                                        <div class="wpc-perf-grid wpc-perf-grid-single">

                                        <?php
                                        echo $gui::checkboxDescription_v4(__('Developer Mode', WPS_IC_TEXTDOMAIN), __('Stops automatic cache purging and Critical CSS regeneration to save resources while you\'re actively editing. Turn off when done.', WPS_IC_TEXTDOMAIN), false, '', 'developer_mode', false, 'right', false, false, false);
                                        ?>

                                        </div>
                                    </div>


                                </div>
                                <div class="wpc-tab-content" id="other-optimization-options" style="display:none;">

                                    <div class="wpc-tab-content-box wpc-card-rows wpc-other-opt-single-card">
                                        <?php
                                        echo $gui::checkboxTabTitle(__('Other Optimizations', WPS_IC_TEXTDOMAIN), __('Fine-tune additional performance settings for media, scripts, and WordPress core.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512"><path fill="currentColor" d="M188.2-55.3c5.4 1.4 32.3 8.6 80.6 21.6l18.8 5c-.5 10-1.6 28.9-3.1 56.7 2.7 2.5 5.3 5.1 7.8 7.8 27.8-1.5 46.7-2.5 56.7-3.1 1.4 5.4 8.6 32.3 21.6 80.6l5 18.8c-8.9 4.5-25.8 13.1-50.6 25.7-.8 3.6-1.8 7.2-2.9 10.7 15.2 23.3 25.5 39.2 31 47.6-3.9 3.9-23.6 23.6-59 59l-13.8 13.8c-8.4-5.5-24.2-15.8-47.5-31-3.5 1.1-7.1 2.1-10.7 2.9-12.6 24.8-21.1 41.7-25.7 50.6-5.4-1.4-32.3-8.6-80.6-21.6l-18.8-5c.5-10 1.6-28.9 3.1-56.7-2.7-2.5-5.3-5.1-7.8-7.8-27.8 1.5-46.7 2.5-56.7 3.1-1.4-5.4-8.6-32.3-21.6-80.6L9 123.8c8.9-4.5 25.8-13.1 50.6-25.7 .8-3.6 1.8-7.2 2.9-10.7-15.2-23.3-25.5-39.2-31-47.6 3.9-3.9 23.6-23.6 59-59l13.8-13.8c8.4 5.5 24.2 15.8 47.5 31 3.5-1.1 7.1-2.1 10.7-2.9 12.6-24.8 21.1-41.7 25.7-50.6zM213.4 1.1C203.1 21.4 196.7 34 194.1 39.1l-12.7 1.6c-8.1 1-15.9 3.1-23.3 6.3l-11.8 5c-4.8-3.1-16.6-10.9-35.7-23.3L93 46.3c12.4 19 20.2 30.9 23.2 35.6l-4.9 11.7c-1.5 3.7-2.9 7.5-3.9 11.5s-1.8 8-2.3 11.9l-1.6 12.6c-5 2.6-17.7 9-37.9 19.2l6.5 24.2c22.7-1.2 36.9-2 42.5-2.3l7.7 10.2c4.9 6.4 10.6 12.2 17.1 17.1l10.2 7.7c-.3 5.7-1.1 19.8-2.3 42.5l24.2 6.5c10.3-20.2 16.7-32.9 19.2-37.9l12.7-1.6c8.1-1 15.9-3.1 23.3-6.3l11.8-5c4.8 3.1 16.6 10.9 35.7 23.3l17.7-17.7c-12.4-19-20.2-30.9-23.2-35.6l4.9-11.7c1.5-3.7 2.9-7.5 3.9-11.5s1.8-8 2.3-11.9l1.6-12.6c5-2.6 17.7-9 37.9-19.2l-6.5-24.2c-22.7 1.2-36.9 2-42.5 2.3l-7.7-10.2c-4.9-6.4-10.6-12.2-17.1-17.1l-10.2-7.7c.3-5.7 1.1-19.8 2.3-42.5L213.4 1.1zM161.4 119.7a32 32 0 1 1 61.8 16.6 32 32 0 1 1 -61.8-16.6zM353 227.3c5.4-1.4 32.3-8.6 80.6-21.6l18.8-5c4.5 8.9 13.1 25.8 25.7 50.6 3.6 .8 7.2 1.8 10.7 2.9 23.3-15.2 39.2-25.5 47.5-31 3.9 3.9 23.6 23.6 59 59l13.8 13.8c-5.5 8.4-15.8 24.2-31 47.6 1.1 3.5 2.1 7.1 2.9 10.7 24.8 12.6 41.7 21.2 50.6 25.7-1.4 5.4-8.6 32.3-21.6 80.6l-5 18.8c-10-.5-28.9-1.6-56.7-3.1-2.5 2.7-5.1 5.3-7.8 7.8 1.5 27.8 2.5 46.7 3.1 56.7-5.4 1.4-32.3 8.6-80.6 21.6l-18.8 5c-4.5-8.9-13.1-25.8-25.7-50.6-3.6-.8-7.2-1.8-10.7-2.9-23.3 15.2-39.2 25.5-47.5 31-3.9-3.9-23.6-23.6-59-59l-13.8-13.8c5.5-8.4 15.8-24.2 31-47.6-1.1-3.5-2.1-7.1-2.9-10.7-24.8-12.6-41.7-21.2-50.6-25.7 1.4-5.4 8.6-32.3 21.6-80.6l5-18.8c10 .5 28.9 1.6 56.7 3.1 2.5-2.7 5.1-5.3 7.8-7.8-1.5-27.8-2.5-46.7-3.1-56.7zm50 36.3c1.2 22.7 2 36.8 2.3 42.5l-10.2 7.7c-6.5 4.9-12.2 10.7-17.1 17.1l-7.7 10.2c-5.7-.3-19.8-1.1-42.5-2.3L321.4 363c20.3 10.3 32.9 16.7 37.9 19.2l1.6 12.6c.5 4 1.3 7.9 2.3 11.9s2.4 7.8 3.9 11.5l4.9 11.7c-3.1 4.7-10.8 16.6-23.2 35.6l17.7 17.7c19-12.4 30.9-20.2 35.7-23.3l11.8 5c7.4 3.1 15.3 5.3 23.3 6.3l12.7 1.6c2.6 5.1 9 17.7 19.2 37.9l24.2-6.5c-1.2-22.7-2-36.8-2.3-42.5l10.2-7.7c6.5-4.9 12.2-10.7 17.1-17.1l7.7-10.2c5.7 .3 19.8 1.1 42.5 2.3l6.5-24.2c-20.3-10.3-32.9-16.7-37.9-19.2l-1.6-12.6c-.5-4-1.3-7.9-2.3-11.9s-2.4-7.8-3.9-11.5L524.4 338c3.1-4.7 10.8-16.6 23.2-35.6L530 284.6c-19 12.4-30.9 20.2-35.7 23.3l-11.8-5c-7.4-3.1-15.3-5.3-23.3-6.3l-12.7-1.6c-2.6-5.1-9-17.7-19.2-37.9l-24.2 6.5zm14.3 128.7a32 32 0 1 1 61.8-16.6 32 32 0 1 1 -61.8 16.6z"/></svg>', '', ''); ?>

                                        <!-- Category: Media & Assets -->
                                        <div class="wpc-other-opt-category">
                                            <div class="wpc-other-opt-category-label"><?php echo esc_html__('Media & Assets', WPS_IC_TEXTDOMAIN); ?></div>
                                            <div class="wpc-other-opt-grid">
                                                <?php
                                                echo $gui::checkboxDescription_v4(__('Lazy Load iFrames', WPS_IC_TEXTDOMAIN), '', false, '0', 'iframe-lazy', false, 'right', '');
                                                echo $gui::checkboxDescription_v4(__('Remove srcset', WPS_IC_TEXTDOMAIN), '', false, '0', 'remove-srcset', false, 'right');
                                                echo $gui::checkboxDescription_v4(__('Add Image Sizes', WPS_IC_TEXTDOMAIN), '', false, false, 'add-image-sizes', false, 'right', false, false, '', true);
                                                echo $gui::checkboxDescription_v4(__('Retina in srcset', WPS_IC_TEXTDOMAIN), '', false, false, 'retina-in-srcset', false, 'right', false, false, '');
                                                echo $gui::checkboxDescription_v4(__('Optimize Metadata Images', WPS_IC_TEXTDOMAIN), '', false, '0', 'optimize_meta_images', false, 'right', '');
                                                echo $gui::checkboxDescription_v4(__('.htaccess WebP Rewrite', WPS_IC_TEXTDOMAIN), '', false, false, 'htaccess-webp-replace', false, 'right', false, false, '');
                                                echo $gui::checkboxDescription_v4(__('Defer Video Preload', WPS_IC_TEXTDOMAIN), '', false, '0', 'video-preload-none', false, 'right', '');
                                                ?>
                                            </div>
                                        </div>

                                        <!-- Category: WordPress Core & Plugins -->
                                        <div class="wpc-other-opt-category">
                                            <div class="wpc-other-opt-category-label"><?php echo esc_html__('WordPress Core & Plugins', WPS_IC_TEXTDOMAIN); ?></div>
                                            <div class="wpc-other-opt-grid">
                                                <?php
                                                echo $gui::checkboxDescription_v4(__('Disable Emoji', WPS_IC_TEXTDOMAIN), '', false, '0', 'emoji-remove', false, 'right', '');
                                                echo $gui::checkboxDescription_v4(__('Disable Dashicons', WPS_IC_TEXTDOMAIN), '', false, '0', 'disable-dashicons', false, 'right', '');
                                                echo $gui::checkboxDescription_v4(__('Disable Block Editor', WPS_IC_TEXTDOMAIN), '', false, '0', 'disable-gutenberg', false, 'right', '');
                                                echo $gui::checkboxDescription_v4(__('Disable oEmbeds', WPS_IC_TEXTDOMAIN), '', false, '0', 'disable-oembeds', false, 'right', '');
                                                echo $gui::checkboxDescription_v4(__('WooCommerce Tweaks', WPS_IC_TEXTDOMAIN), '', false, '0', 'disable-cart-fragments', false, 'right', '');
                                                echo $gui::checkboxDescription_v4(__('Bypass Logged-In Users', WPS_IC_TEXTDOMAIN), '', false, '0', 'disable-logged-in-opt', false, 'right');
                                                ?>
                                            </div>
                                        </div>

                                        <!-- Category: Scripts & Tracking -->
                                        <div class="wpc-other-opt-category">
                                            <div class="wpc-other-opt-category-label"><?php echo esc_html__('Scripts & Tracking', WPS_IC_TEXTDOMAIN); ?></div>
                                            <div class="wpc-other-opt-grid">
                                                <?php
                                                echo $gui::checkboxDescription_v4(__('Lazy Load Google Tag Manager', WPS_IC_TEXTDOMAIN), '', false, '0', 'gtag-lazy', false, 'right', '');
                                                echo $gui::checkboxDescription_v4(__('Disable onLoad Event', WPS_IC_TEXTDOMAIN), '', false, false, 'disable-trigger-dom-event', false, 'right', false, false, '', true);
                                                echo $gui::checkboxDescription_v4(__('Optimize External URLs', WPS_IC_TEXTDOMAIN), '', false, '0', 'external-url', false, 'right', '');
                                                echo $gui::checkboxDescription_v4(__('Filter Bot Traffic', WPS_IC_TEXTDOMAIN), '', false, '0', 'ga-bot-shield', false, 'right', '');
                                                echo $gui::checkboxDescription_v4(__('Set Fetch Priority', WPS_IC_TEXTDOMAIN), '', false, false, 'fetchpriority-high', false, 'right', false, false, '');
                                                ?>
                                            </div>
                                        </div>

                                    </div>

                                </div>
                                <div class="wpc-tab-content" id="smart-optimization" style="display:none;">

                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="smart-opt-header">
                                        <div class="wpc-smart-opt-header">
                                            <div class="wpc-smart-opt-hero">
                                                <div class="wpc-smart-monitor-img-animated">
                                                    <div class="pulse-container" style="display:none"></div>
                                                    <div class="background-image wpc-smart-monitor-img" style="background-image:url(<?php echo WPS_IC_URI . '/assets/v4/images/24monitor.svg'; ?>)"></div>
                                                    <div class="shimmer-container" style="display:none"></div>
                                                </div>
                                                <div class="wpc-smart-opt-title-area">
                                                    <div class="wpc-smart-opt-title-row">
                                                        <h4><?php echo esc_html__('Smart Optimization + Performance', WPS_IC_TEXTDOMAIN); ?></h4>
                                                        <span class="wpc-monitoring-badge">
                                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="12" height="12" fill="currentColor"><path d="M256 48a208 208 0 1 1 0 416 208 208 0 1 1 0-416zm0 464A256 256 0 1 0 256 0a256 256 0 1 0 0 512zM369 209c9.4-9.4 9.4-24.6 0-33.9s-24.6-9.4-33.9 0l-111 111-47-47c-9.4-9.4-24.6-9.4-33.9 0s-9.4 24.6 0 33.9l64 64c9.4 9.4 24.6 9.4 33.9 0L369 209z"/></svg>
                                                            <?php echo esc_html__('24/7 Monitoring', WPS_IC_TEXTDOMAIN); ?>
                                                        </span>
                                                    </div>
                                                    <p><?php echo esc_html__('No need to lift a finger -- your website is intelligently optimized around the clock based on demand.', WPS_IC_TEXTDOMAIN); ?></p>
                                                    <div class="optimizations-progress-bar-container">
                                                        <div id="optimizations-progress-bar" class="optimizations-progress-bar" style="width:100%;"></div>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="wpc-smart-opt-counter">
                                                <div class="optimization-image">
                                                    <img src="<?php echo WPS_IC_URI . '/assets/v4/images/pages_optimized.svg'; ?>" alt="">
                                                </div>
                                                <div class="optimization-text">
                                                    <div class="optimized-pages-text">0</div>
                                                    <div class="optimized-pages-bottom-text"><?php echo esc_html__('Preparing', WPS_IC_TEXTDOMAIN); ?></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="smart-opt-table">
                                        <div class="tab-title-checkbox">
                                            <div class="wpc-checkbox-icon">
                                                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512"><path fill="currentColor" d="M288 112l-24-56-56-24 56-24 24-56 24 56 56 24-56 24-24 56zM492-21.9c2.1 2.1 31.8 31.8 89 89l17 17-17 17-416 416-17 17c-2.1-2.1-31.8-31.8-89-89l-17-17 17-17 416-416 17-17zM109.9 428L148 466.1 386.1 228 348 189.9 109.9 428zM492 45.9L381.9 156 420 194.1 530.1 84 492 45.9zM96 96l32-80 32 80 80 32-80 32-32 80-32-80-80-32 80-32zM384 400l80-32 32-80 32 80 80 32-80 32-32 80-32-80-80-32z"/></svg>
                                            </div>
                                            <div class="wpc-checkbox-description">
                                                <h4><?php echo esc_html__('Pages & Posts', WPS_IC_TEXTDOMAIN); ?></h4>
                                                <p><?php echo esc_html__('View optimization status for every page and post on your site.', WPS_IC_TEXTDOMAIN); ?></p>
                                            </div>
                                        </div>

                                        <?php
                                        $publicPostTypes = get_post_types(['public' => true], 'objects');
                                        unset($publicPostTypes['attachment']);
                                        ?>
                                        <div class="wpc-smart-opt-toolbar">
                                            <input type="text" id="live-search" placeholder="<?php echo esc_attr__('Search pages, posts, URLs...', WPS_IC_TEXTDOMAIN); ?>">
                                            <div class="dropdown-container selector-dropdown">
                                                <div class="dropdown" data-dropdown="filter">
                                                    <div class="dropdown-header"><?php echo esc_html__('Filter by Type', WPS_IC_TEXTDOMAIN); ?> <span class="wpc-filter-count"></span>
                                                        <div class="wpc-dropdown-row-arrow"><i class="icon-down-open"></i></div>
                                                    </div>
                                                    <div class="dropdown-menu">
                                                        <?php foreach ($publicPostTypes as $cpt) { ?>
                                                            <div class="dropdown-item icon-<?php echo esc_attr($cpt->name); ?>s" data-value="<?php echo esc_attr($cpt->name); ?>" data-filter="type"><?php echo esc_html($cpt->labels->name); ?></div>
                                                        <?php } ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="wpc-items-list-row mb-20" id="optimizationTable"></div>
                                        <div id="pagination"></div>
                                    </div>

                                </div>
                                <div class="wpc-tab-content" id="critical-css-optimization" style="display:none;">

                                    <div class="wpc-tab-content-box">

                                        <div class="wpc-critical-css-status"
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
                                                        <h4 class="fs-500 text-dark-300 fw-500 p-inline wpc-critical-css-title">
                                                            <?php echo esc_html__('Critical CSS', WPS_IC_TEXTDOMAIN); ?></h4>
                                                        <img src="<?php
                                                        echo WPS_IC_URI . '/assets/v4/images/24bubble.svg' ?>"
                                                             style="padding-left: 15px;height: 30px;padding-top: 2px;">
                                                    </div>
                                                    <p class="wpc-smart-optimization-text" style="margin: 7px 0px 4px">
                                                        <?php echo esc_html__('No need to lift a finger -- your website is intelligently optimized around the clock based on demand.', WPS_IC_TEXTDOMAIN); ?></p>
                                                </div>

                                            </div>

                                            <div class="wpc-optimization-status"
                                                 style="display:flex;align-items:center;margin-left:10px;padding-left:20px">
                                                <div class="optimization-image">
                                                    <img src="<?php
                                                    echo WPS_IC_URI . '/assets/v4/images/pages_optimized.svg' ?>" alt=""
                                                         style="margin-top:-5px">
                                                </div>

                                                <div class="optimization-text">
                                                    <div class="optimized-pages-text">0</div>
                                                    <div class="optimized-pages-bottom-text"><?php echo esc_html__('Preparing', WPS_IC_TEXTDOMAIN); ?></div>
                                                </div>


                                            </div>
                                        </div>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-critical-css-actions">
                                            <a href="<?php echo admin_url('admin.php?page=wpcompress&generate_crit=home#critical-css-optimization'); ?>">
                                                <?php echo esc_html__('Generate Critical CSS for Home Page', WPS_IC_TEXTDOMAIN); ?>
                                            </a>
                                            <a href="#" class="wpc-purge-icon wpc-purge-critical-css" title="<?php echo esc_attr__('Purge Critical CSS', WPS_IC_TEXTDOMAIN); ?>">
                                                <i class="icon-arrows-ccw"></i>
                                            </a>
                                        </div>

                                    </div>
                                </div>
                                <div class="wpc-tab-content" id="integrations" style="display:none;">
                                    <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section" id="cf-connect-options" style="display: block;">
                                        <?php
                                        echo $gui::checkboxTabTitle(__('Cloudflare Integration', WPS_IC_TEXTDOMAIN), __('Connect with Cloudflare for automated cache purging, edge caching, and uninterrupted access.', WPS_IC_TEXTDOMAIN), 'cf-logo.png', '', '', '', '', '', '', '', '', 'https://help.wpcompress.com/en-us/article/cloudflare-integration-setup-guide-for-automated-cache-purging-17ger3i/?bust=1739284717272 ', __('How to?', WPS_IC_TEXTDOMAIN)); ?>

                                        <div class="wpc-cf-connect-wrapper">
                                            <div class="wpc-cf-connect-form">
                                                <?php
                                                if (empty($cf)) {
                                                    ?>
                                                    <div class="wpc-cf-loader" style="display: none;">
                                                        <span><div class="circle-check active wpc-pulse-dot"></div> <?php echo esc_html__('Connecting...', WPS_IC_TEXTDOMAIN); ?></span>
                                                    </div>
                                                    <div class="wpc-cf-loader-zone" style="display: none;">
                                                        <span><div class="circle-check active wpc-pulse-dot"></div> <?php echo esc_html__('Connecting, this can take up to 1-2 minutes...', WPS_IC_TEXTDOMAIN); ?></span>
                                                    </div>
                                                    <div class="wpc-input-holder-no-change wpc-cf-token-hide-on-load wpc-cf-insert-token-step">
                                                        <label for="wpc-cf-token">
                                                            <div class="circle-check"></div>
                                                            <?php echo esc_html__('Login to Cloudflare:', WPS_IC_TEXTDOMAIN); ?></label>
                                                        <input type="text" name="wpc-cf-token" id="wpc-cf-token"/>
                                                        <input type="button" class="wpc-cf-token-check wpc-cf-button" value="<?php echo esc_attr__('Connect', WPS_IC_TEXTDOMAIN); ?>"/>
                                                    </div>

                                                    <div class="wpc-select-holder-no-change" id="wpc-cf-zone-list-holder" style="display: none;">
                                                        <input type="hidden" name="wpc-cf-zone" value=""/>
                                                        <label for="wpc-cf-zone-list">
                                                            <div class="circle-check"></div>
                                                            <?php echo esc_html__('Select the website you wish to connect:', WPS_IC_TEXTDOMAIN); ?></label>
                                                        <div class="wpc-cf-zone-list">
                                                            <div class="wpc-cf-zone-list-selected" id="wpc-cf-zone-list-selected">
                                                                <span class="wpc-cf-zone-text"><?php echo esc_html__('Select a zone', WPS_IC_TEXTDOMAIN); ?></span>
                                                                <svg class="wpc-cf-zone-arrow" width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1L5 5L9 1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                            </div>
                                                            <div class="wpc-cf-zone-list-dropdown" style="display: none;">
                                                                <div class="wpc-cf-zone-search-wrap">
                                                                    <input type="text" class="wpc-cf-zone-search" placeholder="<?php echo esc_attr__('Search zones...', WPS_IC_TEXTDOMAIN); ?>" autocomplete="off"/>
                                                                </div>
                                                                <div class="wpc-cf-zone-list-items"></div>
                                                                <div class="wpc-cf-zone-no-results" style="display: none;"><?php echo esc_html__('No zones found', WPS_IC_TEXTDOMAIN); ?></div>
                                                            </div>
                                                        </div>
                                                        <input type="button" class="wpc-cf-token-connect wpc-cf-button" value="<?php echo esc_attr__('Connect', WPS_IC_TEXTDOMAIN); ?>"/>
                                                    </div>
                                                    <div class="wpc-cf-loader-error" style="display: none;">
                                                        <span></span>
                                                    </div>
                                                <?php } else {
                                                    ?>
                                                    <div class="wpc-cf-loader-disconnecting" style="display: none;">
                                                        <span><div class="circle-check active wpc-pulse-dot"></div> <?php echo esc_html__('Disconnecting, this can take up to 1-2 minutes...', WPS_IC_TEXTDOMAIN); ?></span>
                                                    </div>
                                                    <div class="wpc-cf-loader-refreshing" style="display: none;">
                                                        <span><div class="circle-check active wpc-pulse-dot"></div> <?php echo esc_html__('Refreshing, this can take up to 1-2 minutes...', WPS_IC_TEXTDOMAIN); ?></span>
                                                    </div>
                                                    <div class="wpc-input-holder-no-change wpc-cf-token-connected">
                                                        <div style="display:flex;align-items: center">
                                                            <label for="wpc-cf-token" style="flex:1;max-width:100px;padding:0;">
                                                                <div class="circle-check active"></div>
                                                                <?php echo esc_html__('Connected', WPS_IC_TEXTDOMAIN); ?> </label>
                                                            <div class="wpc-cf-token-connected-info" style="flex: 2;">
                                                                <div class="wpc-cf-token-connected-info-left">
                                                                    <?php
                                                                    echo '<strong>' . $cf['zoneName'] . '</strong>';
                                                                    ?>
                                                                </div>


                                                                <?php
                                                                if (!empty($_GET['dbgRocket'])) {
                                                                    require_once WPS_IC_DIR . '/addons/cf-sdk/cf-sdk.php';
                                                                    $cfsdk = new WPC_CloudflareAPI($cf['token']);
                                                                    var_dump('Rocket Loader: ');
                                                                    $rocketSettings = $cfsdk->checkRocketLoader($cf['zone']);
                                                                    $rocketSettings = $rocketSettings[$cf['zone']];
                                                                    var_dump($rocketSettings['value']);
                                                                }
                                                                ?>

                                                                <div class="wpc-cf-token-connected-info-right">
                                                                    <input type="button" class="wpc-cf-token-verify wpc-cf-button" value="<?php echo esc_attr__('Refresh Connection', WPS_IC_TEXTDOMAIN); ?>"/>
                                                                    <input type="button" class="wpc-cf-token-disconnect wpc-cf-button" value="<?php echo esc_attr__('Disconnect', WPS_IC_TEXTDOMAIN); ?>"/>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php } ?>
                                            </div>
                                        </div>
                                        <?php if (!empty($cf)) {
                                            if (!empty($cf_error_message)) {
                                                ?>
                                                <div class="wpc-cf-loader-error" style="display: block;">
                                                    <span><?php echo $cf_error_message; ?></span>
                                                </div>
                                                <?php
                                            }
                                            ?>
                                            <div class="wpc-perf-grid">
                                                <?php
                                                echo $gui::cf_dropdown(__('HTML Edge Caching', WPS_IC_TEXTDOMAIN), __('Serve HTML globally from the nearest edge server.', WPS_IC_TEXTDOMAIN));
                                                echo $gui::cf_checkboxDescription(__('Static Assets Cache', WPS_IC_TEXTDOMAIN), __('Caches CSS, JS, images, fonts, and more for fast global delivery.', WPS_IC_TEXTDOMAIN), ['cf', 'assets']);
                                                echo $gui::cf_checkboxDescription(__('Real-Time Adaptive Optimization', WPS_IC_TEXTDOMAIN), __('Resize images on the fly, auto-convert to WebP, and optimize scripts (based on your plugin settings).', WPS_IC_TEXTDOMAIN), ['cf', 'cdn'], 'cf-cdn');
                                                ?>
                                            </div>
                                            <details class="setup-accordion" id="cloudflare-permissions-accordion" open>
                                                <summary><?php echo esc_html__('Please make sure your permissions are updated to use the latest integration:', WPS_IC_TEXTDOMAIN); ?></summary>
                                                <div class="accordion-body">
                                                    <img src="<?php echo WPS_IC_URI; ?>assets/v4/images/configure-api-token.png" alt="<?php echo esc_attr__('Configure API Token', WPS_IC_TEXTDOMAIN); ?>" style="max-width:100%;"/>
                                                </div>
                                            </details>
                                            <details class="setup-accordion" id="cloudflare-setup-accordion">
                                                <summary><?php echo esc_html__('How To Get Your Cloudflare API Key', WPS_IC_TEXTDOMAIN); ?></summary>
                                                <div class="accordion-body">
                                                    <div class="iframe-wrap">
                                                        <iframe
                                                                src="https://help.wpcompress.com/en-us/article/cloudflare-integration-setup-wl-sgtph4/reader/compact/"
                                                                title="<?php echo esc_attr__('Cloudflare Integration Setup Guide', WPS_IC_TEXTDOMAIN); ?>"
                                                                loading="lazy"
                                                                referrerpolicy="no-referrer-when-downgrade"
                                                        ></iframe>
                                                    </div>
                                                </div>
                                            </details>
                                        <?php } else {
                                            ?>
                                            <details class="setup-accordion" id="cloudflare-setup-accordion">
                                                <summary><?php echo esc_html__('How To Get Your Cloudflare API Key', WPS_IC_TEXTDOMAIN); ?></summary>
                                                <div class="accordion-body">
                                                    <div class="iframe-wrap">
                                                        <iframe
                                                                src="https://help.wpcompress.com/en-us/article/cloudflare-integration-setup-wl-sgtph4/reader/compact/"
                                                                title="<?php echo esc_attr__('Cloudflare Integration Setup Guide', WPS_IC_TEXTDOMAIN); ?>"
                                                                loading="lazy"
                                                                referrerpolicy="no-referrer-when-downgrade"
                                                        ></iframe>
                                                    </div>
                                                </div>
                                            </details>
                                            <?php
                                        } ?>

                                    </div>


                                    <?php if (get_option('wpc_show_hidden_menus') == 'true') { ?>
                                        <div class="wpc-tab-content-box wpc-card-rows wpc-perf-section">
                                            <?php
                                            echo $gui::checkboxTabTitle(__('Other Integrations', WPS_IC_TEXTDOMAIN), __('Third-party plugin compatibility settings.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="currentColor" d="M144-32l0 128 160 0 0-128 48 0 0 128 96 0 0 48-32 0 0 80c0 97.9-73.3 178.7-168 190.5l0 97.5-48 0 0-97.5C105.3 402.7 32 321.9 32 224l0-80-32 0 0-48 96 0 0-128 48 0zM80 144l0 80c0 79.5 64.5 144 144 144s144-64.5 144-144l0-80-288 0z"/></svg>', '', ''); ?>
                                            <div class="wpc-perf-grid wpc-perf-grid-single">
                                                <?php
                                                echo $gui::checkboxDescription_v4(__('Disable Elementor Triggers', WPS_IC_TEXTDOMAIN), __('Can fix double animations, but may break menus and other Elementor elements.', WPS_IC_TEXTDOMAIN), false, false, 'disable-elementor-triggers', false, 'right', false, false, '', true); ?>
                                            </div>
                                        </div>
                                    <?php } ?>
                                </div>


                                <div class="wpc-tab-content" id="permissions" style="display: none;">
                                    <div class="wpc-tab-content-box">
                                    </div>
                                </div>

                                <div class="wpc-tab-content" id="export_settings" style="display:none;">
                                    <div class="wpc-tab-content-box">

                                        <?php
                                        echo $gui::checkboxTabTitle(__('Export / Import Settings', WPS_IC_TEXTDOMAIN), __('Export your settings to a file to easily import to other sites.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 512"><path fill="currentColor" d="M240.4 48l-128 0 0 416 288 0 0-64 48 0 0 112-384 0 0-512 224 0 160 160 0 96-48 0 0-48-160 0 0-160zm160 304l-112 0 0-48 238.1 0c-23-23-39-39-48-48l33.9-33.9c2.1 2.1 31.8 31.8 89 89l17 17c-2.1 2.1-31.8 31.8-89 89l-17 17-33.9-33.9c9-9 25-25 48-48l-126.1 0zM380.6 160l-92.1-92.1 0 92.1 92.1 0z"/></svg>', ''); ?>

                                        <div class="wpc-spacer"></div>

                                        <div class="wpc-settings-export-form">
                                            <div class="cdn-popup-inner">
                                                <div class="wps-default-excludes-enabled-checkbox-container">
                                                    <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-export-settings" checked style="min-width:24px">
                                                    <p><?php echo esc_html__('Settings', WPS_IC_TEXTDOMAIN); ?></p>
                                                </div>
                                            </div>
                                            <div class="cdn-popup-inner">
                                                <div class="wps-default-excludes-enabled-checkbox-container">
                                                    <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-export-excludes" style="min-width:24px">
                                                    <p><?php echo esc_html__('Excludes', WPS_IC_TEXTDOMAIN); ?></p>
                                                </div>
                                            </div>
                                            <div class="cdn-popup-inner">
                                                <div class="wps-default-excludes-enabled-checkbox-container">
                                                    <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-export-cache" style="min-width:24px">
                                                    <p><?php echo esc_html__('Cache Purge Settings', WPS_IC_TEXTDOMAIN); ?></p>
                                                </div>
                                            </div>
                                            <div class="cdn-popup-inner">
                                                <div class="wps-default-excludes-enabled-checkbox-container">
                                                    <input type="checkbox" class="wps-default-excludes-enabled-checkbox wps-export-cache-cookies" style="min-width:24px">
                                                    <p><?php echo esc_html__('Cache Cookies Settings', WPS_IC_TEXTDOMAIN); ?></p>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="wpc-export-import-buttons">
                                            <button id="wpc-export-button" class="wps-ic-help-btn" style="border:none"><?php echo esc_html__('Export', WPS_IC_TEXTDOMAIN); ?></button>
                                            <button id="wpc-import-button" class="wps-ic-help-btn" style="border:none"><?php echo esc_html__('Import', WPS_IC_TEXTDOMAIN); ?></button>
                                            <button id="wpc-set-default-button" class="wps-ic-help-btn" style="border:none;float:right"><?php echo esc_html__('Reset to default', WPS_IC_TEXTDOMAIN); ?></button>
                                            <input type="file" id="wpc-import-file" style="display: none;" accept=".json">
                                        </div>
                                    </div>

                                </div>


                                <div class="wpc-tab-content" id="system-information" style="display:none;">
                                    <div class="wpc-tab-content-box">

                                        <?php
                                        echo $gui::checkboxTabTitle(__('System Information', WPS_IC_TEXTDOMAIN), __('Server environment, PHP version, and plugin diagnostics.', WPS_IC_TEXTDOMAIN), '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512"><path fill="currentColor" d="M48 206l0 71.4 93.8 58.6 164.5 0 93.8-58.6 0-71.4-80 50-192 0-80-50zM0 176l0-96 128-80 192 0 128 80 0 352-128 80-192 0-128-80 0-256zm400-48l0-21.4-93.8-58.6-164.5 0-93.8 58.6 0 42.8 93.8 58.6 164.5 0 93.8-58.6 0-21.4zM48 405.4l93.8 58.6 164.5 0 93.8-58.6 0-71.4-80 50-192 0-80-50 0 71.4z"/></svg>', ''); ?>

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
                                                <li><?php echo esc_html__('WP Version:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        global $wp_version;
                                                        echo $wp_version; ?></strong>
                                                </li>
                                                <li><?php echo esc_html__('PHP Version:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        echo phpversion() ?></strong>
                                                </li>
                                                <li><?php echo esc_html__('Site URL:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        echo site_url() ?></strong>
                                                </li>
                                                <li><?php echo esc_html__('Home URL:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        echo home_url() ?></strong>
                                                </li>
                                                <li><?php echo esc_html__('API Location:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        echo print_r($location, true); ?></strong>
                                                </li>
                                                <li><?php echo esc_html__('Bulk Status:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        echo print_r(get_option('wps_ic_BulkStatus'), true); ?></strong>
                                                </li>
                                                <li><?php echo esc_html__('Parsed Images:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        echo print_r(get_option('wps_ic_parsed_images'), true); ?></strong>
                                                </li>
                                                <li><?php echo esc_html__('Multisite:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        if (is_multisite()) {
                                                            echo esc_html__('True', WPS_IC_TEXTDOMAIN);
                                                        } else {
                                                            echo esc_html__('False', WPS_IC_TEXTDOMAIN);
                                                        } ?></strong>
                                                </li>
                                                <li><?php echo esc_html__('Maximum upload size:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        echo size_format(wp_max_upload_size()) ?></strong>
                                                </li>
                                                <li><?php echo esc_html__('Memory limit:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        echo ini_get('memory_limit') ?></strong>
                                                </li>

                                                <li><?php echo esc_html__('Thumbnails:', WPS_IC_TEXTDOMAIN); ?>
                                                    <strong><?php
                                                        echo count(get_intermediate_image_sizes()); ?></strong>
                                                </li>

                                                <li>
                                                    <?php
                                                    if (function_exists('file_get_contents')) {
                                                        echo esc_html__('file_get_contents function is available.', WPS_IC_TEXTDOMAIN);
                                                    } else {
                                                        echo esc_html__('file_get_contents function is not available.', WPS_IC_TEXTDOMAIN);
                                                    }
                                                    ?>
                                                </li>

                                                <li><?php echo esc_html__('URL changes:', WPS_IC_TEXTDOMAIN); ?>
                                                    <?php
                                                    echo print_r(get_option('wps_ic_url_changed_log'), true); ?>
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

                              <div class="wpc-tab-content" id="scan-fonts" style="display:none;">
                                    <?php
                                    include_once 'scan-fonts.php'; ?>
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
include WPS_IC_DIR . 'templates/admin/partials/tooltips/all.php';

//
include WPS_IC_DIR . 'templates/admin/partials/popups/compatibility-popups.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/cname.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/exclude-cdn.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/exclude-lazy.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/exclude-webp.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/exclude-adaptive.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/exclude-critical-css.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/exclude-inline-css.php';

// HTML Optimizations
include WPS_IC_DIR . 'templates/admin/partials/popups/exclude-minify-html.php';

// JS Optimizations
include WPS_IC_DIR . 'templates/admin/partials/popups/js/delay-js-configuration.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/js/exclude-js-minify.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/js/exclude-js-combine.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/js/exclude-scripts-to-footer.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/js/exclude-js-defer.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/js/exclude-js-delay.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/js/exclude-js-delay-v2.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/js/inline-js.php';

// CSS Optimizations
include WPS_IC_DIR . 'templates/admin/partials/popups/css/exclude-css-combine.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/css/exclude-css-minify.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/css/exclude-css-render-blocking.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/css/inline-css.php';

//Cache
include WPS_IC_DIR . 'templates/admin/partials/popups/exclude-simple-caching.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/exclude-advanced-caching.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/purge-settings.php';
include WPS_IC_DIR . 'templates/admin/partials/popups/cache-cookies.php';

include WPS_IC_DIR . 'templates/admin/partials/popups/import-export.php';

include WPS_IC_DIR . 'templates/admin/partials/popups/cf-cdn.php';