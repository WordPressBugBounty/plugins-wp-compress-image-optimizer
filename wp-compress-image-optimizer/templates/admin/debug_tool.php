<?php
if (function_exists('update_option')) {
    update_option('wpc_diag_until', time() + 7 * 86400, true);
}
global $wps_ic, $wpdb;






if (!function_exists('wpc_dbg_base651')) {
    function wpc_dbg_base651($slug)
    {
        if (function_exists('menu_page_url')) {
            $u = menu_page_url($slug, false);
            if (!empty($u)) {
                return $u;
            }
        }
        return admin_url('options-general.php?page=' . $slug);
    }
}














$wpc_get651 = ['delete_option', 'wps_ic_critical_mc', 'wps_ic_cdn_mc', 'wps_ic_delay_v2_debug',
    'optimizejs_remove', 'optimizejs_debug', 'wps_ic_debug_log', 'php_development',
    'php_debug', 'js_debug', 'ps_debug', 'ccss_debug'];
$wpc_post651 = ['wps_settings', 'cache_refresh_time', 'elementor_skip_sections',
    'elementor_skip_desktop', 'elementor_skip_mobile', 'local_server', 'savePreloads',
    'preloads', 'preloadsMobile', 'preloads_lcp', 'preloadsMobile_lcp', 'remove_fonts'];
$wpc_hit651 = false;
foreach ($wpc_get651 as $wpc_k651) {
    if (isset($_GET[$wpc_k651])) { $wpc_hit651 = true; break; }
}
if (!$wpc_hit651) {
    foreach ($wpc_post651 as $wpc_k651) {
        if (isset($_POST[$wpc_k651])) { $wpc_hit651 = true; break; }
    }
}
if ($wpc_hit651) {
    if (!current_user_can('manage_wpc_settings') && !current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to perform this action.', WPS_IC_TEXTDOMAIN), 403);
    }
    
    
    $wpc_toks651 = [];
    foreach (['_wpnonce', 'wpc_settings_save_nonce'] as $wpc_f651) {
        if (!empty($_REQUEST[$wpc_f651])) {
            $wpc_toks651[] = (string) $_REQUEST[$wpc_f651];
        }
    }
    $wpc_ok651 = false;
    foreach (['wpc_debug_action', 'wpc_clear_diagnostic_log', 'wpc_excl_diag', 'wpc_prov_diag', 'wpc_settings_save'] as $wpc_a651) {
        foreach ($wpc_toks651 as $wpc_t651) {
            if (wp_verify_nonce($wpc_t651, $wpc_a651)) {
                $wpc_ok651 = true;
                break 2;
            }
        }
    }
    if (!$wpc_ok651) {
        wp_nonce_ays('wpc_debug_action');
    }
}

$settings = get_option(WPS_IC_SETTINGS);
if (!empty($_POST['cache_refresh_time'])) {
    $settings['cache_refresh_time'] = sanitize_text_field($_POST['cache_refresh_time']);
    update_option(WPS_IC_SETTINGS, $settings);
}

if (!isset($settings['cache_refresh_time'])) {
    $settings['cache_refresh_time'] = 60;
}

if (!empty($_GET['delete_option'])) {
    
    
    $wpc_opt651 = sanitize_text_field((string) $_GET['delete_option']);
    if (preg_match('/^(wps_ic|wpc_|ic_|wps_optimizejs|wps_critical|wps_no_content)/', $wpc_opt651)) {
        delete_option($wpc_opt651);
    } else {
        echo '<div style="background:#fef2f2;border:1px solid #fecaca;color:#991b1b;padding:8px 12px;border-radius:6px;margin:10px 0;font-size:12px;">Refused: only WP Compress options may be deleted here.</div>';
    }
}

if (!empty($_GET['debug_img'])) {
    $imageID = $_GET['debug_img'];
    $debug = get_post_meta($imageID, 'ic_debug', true);
    if (!empty($debug)) {
        foreach ($debug as $i => $msg) {
            echo $msg . '<br/>';
        }
    }
    die();
}

if (!empty($_POST['elementor_skip_sections'])) {
	$skipSections = array(
		'desktop' => intval($_POST['elementor_skip_desktop']),
		'mobile' => intval($_POST['elementor_skip_mobile'])
	);
	update_option('wps_ic_elementor_skip_sections', $skipSections);
}


$servers = ['auto' => 'Auto', 'vancouver.zapwp.net' => 'Canada', 'nyc.zapwp.net' => 'New York', 'la2.zapwp.net' => 'LA2', 'singapore.zapwp.net' => 'Singapore', 'dallas.zapwp.net' => 'Dallas', 'sydney.zapwp.net' => 'Sydney', 'india.zapwp.net' => 'India', 'frankfurt.zapwp.net' => 'Germany'];

if (!empty($_POST['local_server'])) {
    $local_server = $_POST['local_server'];
    update_option('wps_ic_force_local_server', $local_server);
} else {
    $local_server = get_option('wps_ic_force_local_server');
    if ($local_server === false || empty($local_server)) {
        $local_server = 'auto';
    }
}


if (isset($_POST['savePreloads'])) {
    if (empty($_POST['preloads'])) {
        $preloadsLcp = get_option('wps_ic_preloads', []);
        unset($preloadsLcp['custom']);
        update_option('wps_ic_preloads', $preloadsLcp);
    }

    if (empty($_POST['preloadsMobile'])) {
        $preloadsLcp = get_option('wps_ic_preloadsMobile', []);
        unset($preloadsLcp['custom']);
        update_option('wps_ic_preloadsMobile', $preloadsLcp);
    }

    if (empty($_POST['preloads_lcp'])) {
        $preloadsLcp = get_option('wps_ic_preloads', []);
        $preloadsLcp['lcp'] = '';
        update_option('wps_ic_preloads', $preloadsLcp);
    }

    if (empty($_POST['preloadsMobile_lcp'])) {
        $preloadsLcp = get_option('wps_ic_preloadsMobile', []);
        $preloadsLcp['lcp'] = '';
        update_option('wps_ic_preloadsMobile', $preloadsLcp);
    }

}

if (!empty($_POST['preloads_lcp'])) {
	$preloadsLcp = get_option('wps_ic_preloads', []);
	$preloadsLcp['lcp'] = [$_POST['preloads_lcp']]; 
	update_option('wps_ic_preloads', $preloadsLcp);
}

if (!empty($_POST['preloadsMobile_lcp'])) {
	$preloadsLcp = get_option('wps_ic_preloadsMobile', []);
	$preloadsLcp['lcp'] = [$_POST['preloadsMobile_lcp']]; 
	update_option('wps_ic_preloadsMobile', $preloadsLcp);
}

if (!empty($_POST['preloads'])) {
	$preloadsLcp = get_option('wps_ic_preloads', []);
	$preloadsArray = explode("\n", $_POST['preloads']);
	$preloadsArray = array_map('trim', $preloadsArray);
	$preloadsLcp['custom'] = $preloadsArray;
	update_option('wps_ic_preloads', $preloadsLcp);
}

$preloads = get_option('wps_ic_preloads');
if (!empty($_POST['preloadsMobile'])) {
	$preloadsLcp = get_option('wps_ic_preloadsMobile', []);
	$preloadsArray = explode("\n", $_POST['preloadsMobile']);
	$preloadsArray = array_map('trim', $preloadsArray);
	$preloadsLcp['custom'] = $preloadsArray;
	update_option('wps_ic_preloadsMobile', $preloadsLcp);
}

if (!empty($_POST['remove_fonts'])) {
    $removeFonts = array_values(array_filter(array_map('trim',
        preg_split('/[\r\n,]+/', (string) wp_unslash($_POST['remove_fonts'])))));
    update_option('wps_ic_remove_fonts', $removeFonts);
}

$preloadsMobile = get_option('wps_ic_preloadsMobile');
?>

<div style="display: none;" id="compress-test-results" class="ic-test-results">
    <textarea id="compress-test-results-textarea" style="visibility: hidden;opacity: none;"></textarea>
    <div class="results-inner">
        <span class="ic-terminal-dot blink"><span></span></span>
    </div>
    <a href="#" class="copy-debug"><?php esc_html_e('Copy Debug Results', WPS_IC_TEXTDOMAIN); ?></a>
</div>

<table id="information-table" class="wp-list-table widefat fixed striped posts">
    <thead>
    <tr>
        <th><?php esc_html_e('Check Name', WPS_IC_TEXTDOMAIN); ?></th>
        <th><?php esc_html_e('Value', WPS_IC_TEXTDOMAIN); ?></th>
        <th><?php esc_html_e('Status', WPS_IC_TEXTDOMAIN); ?></th>
        <th><?php esc_html_e('Action', WPS_IC_TEXTDOMAIN); ?></th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td><?php esc_html_e('Use OLD Critical API', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['wps_ic_critical_mc'])) {
                    if ($_GET['wps_ic_critical_mc'] === 'true') {
                        $settings = get_option(WPS_IC_SETTINGS);
                        $settings['mcCriticalCSS'] = 'mc';
                        update_option(WPS_IC_SETTINGS, $settings);
                        
                    } else {
                        $settings = get_option(WPS_IC_SETTINGS);
                        $settings['mcCriticalCSS'] = 'api';
                        update_option(WPS_IC_SETTINGS, $settings);
                        
                    }
                }

                $cdn_critical_mc = get_option(WPS_IC_SETTINGS);


                if (empty($settings['mcCriticalCSS']) || $settings['mcCriticalCSS'] == 'mc') {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&wps_ic_critical_mc=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable Old API', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&wps_ic_critical_mc=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable New API', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('Enable Bunny Critical CSS API.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('New CDN API Test', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['wps_ic_cdn_mc'])) {
                    if ($_GET['wps_ic_cdn_mc'] === 'true') {
                        update_option('wps_ic_cdn_mc', sanitize_text_field($_GET['wps_ic_cdn_mc']));

                        $oldZone = get_option('ic_cdn_zone_name');
                        update_option('ic_cdn_zone_name_old', $oldZone);
                        update_option('ic_cdn_zone_name', 'mc-enutpvy18x.bunny.run');

                    } else {
                        $oldZone = get_option('ic_cdn_zone_name_old');
                        delete_option('ic_cdn_zone_name_old');
                        update_option('ic_cdn_zone_name', $oldZone);

                        delete_option('wps_ic_cdn_mc');
                    }
                }

                $cdn_mc = get_option('wps_ic_cdn_mc');

                if (empty($cdn_mc) || $cdn_mc == 'false') {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&wps_ic_cdn_mc=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&wps_ic_cdn_mc=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('Enable Bunny MC API.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('New DelayJS DEBUG', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
					    <?php
					    if (!empty($_GET['wps_ic_delay_v2_debug'])) {
						    if ($_GET['wps_ic_delay_v2_debug'] === 'true') {
							    update_option('wps_ic_delay_v2_debug', sanitize_text_field($_GET['wps_ic_delay_v2_debug']));
						    } else {
							    delete_option('wps_ic_delay_v2_debug');
						    }
					    }

					    $v2_debug = get_option('wps_ic_delay_v2_debug');

					    if (empty($v2_debug) || $v2_debug == 'false') {
						    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&wps_ic_delay_v2_debug=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
					    } else {
						    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&wps_ic_delay_v2_debug=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
					    }
					    ?>
                <?php esc_html_e('Enable console log debug.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Remove OptimizeJS', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['optimizejs_remove'])) {
                    if ($_GET['optimizejs_remove'] === 'true') {
                        update_option('wps_optimizejs_remove', sanitize_text_field($_GET['optimizejs_remove']));
                    } else {
                        delete_option('wps_optimizejs_remove');
                    }
                }

                $optimizejs_remove = get_option('wps_optimizejs_remove');

                if (empty($optimizejs_remove) || $optimizejs_remove == 'false') {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&optimizejs_remove=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&optimizejs_remove=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with optimize.js this will give you the debug version.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Enable OptimizeJS Debug', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['optimizejs_debug'])) {
                    update_option('wps_optimizejs_debug', sanitize_text_field($_GET['optimizejs_debug']));
                }

                $optimizejs_debug = get_option('wps_optimizejs_debug');

                if (empty($optimizejs_debug) || $optimizejs_debug == 'false') {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&optimizejs_debug=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&optimizejs_debug=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with optimize.js this will give you the debug version.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Debug Log', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['wps_ic_debug_log'])) {
                    update_option('wps_ic_debug_log', sanitize_text_field($_GET['wps_ic_debug_log']));
                }

                $development = get_option('wps_ic_debug_log');

                if (empty($development) || $development == 'false') {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&wps_ic_debug_log=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&wps_ic_debug_log=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Plugin Development Mode', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['php_development'])) {
                    update_option('wps_ic_development', sanitize_text_field($_GET['php_development']));
                    
                    if (sanitize_text_field($_GET['php_development']) === 'true') {
                        update_option('wpc_dev_flag_seen', time(), false);
                    } else {
                        delete_option('wpc_dev_flag_seen');
                    }
                }

                $development = get_option('wps_ic_development');

                if (empty($development) || $development == 'false') {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&php_development=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&php_development=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Enable Critical CSS Debug', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['ccss_debug'])) {
                    update_option('wps_ccss_debug', sanitize_text_field($_GET['ccss_debug']));
                }

                $ccss_debug = get_option('ccss_debug');

                if (empty($ccss_debug) || $ccss_debug == 'false') {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&ccss_debug=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&ccss_debug=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with critical CSS.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Enable PageSpeed & Critical Debug', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['ps_debug'])) {
                    update_option('wps_ps_debug', sanitize_text_field($_GET['ps_debug']));
                }

                $debugPhp = get_option('wps_ps_debug');

                if (empty($debugPhp) || $debugPhp == 'false') {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&ps_debug=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&ps_debug=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with our plugin, enabling this option will give you some basic debug output in Console log of your browser.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Enable PHP Debug', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['php_debug'])) {
                    update_option('wps_ic_debug', sanitize_text_field($_GET['php_debug']));
                }

                $debugPhp = get_option('wps_ic_debug');

                if (empty($debugPhp) || $debugPhp == 'false') {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&php_debug=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&php_debug=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with our plugin, enabling this option will give you some basic debug output in Console log of your browser.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Enable JavaScript Debug', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                if (!empty($_GET['js_debug'])) {
                    update_option('wps_ic_js_debug', sanitize_text_field($_GET['js_debug']));
                }

                if (get_option('wps_ic_js_debug') == 'false') {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&js_debug=true', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&js_debug=false', 'wpc_debug_action') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
                }
                ?>
                <?php esc_html_e('If you are having any sort of issues with our plugin, enabling this option will give you some basic debug output in Console log of your browser.', WPS_IC_TEXTDOMAIN); ?>
            </p>
        </td>
    </tr>


    <tr>
        <td><?php esc_html_e('Site Url', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                echo esc_html__('Site URL:', WPS_IC_TEXTDOMAIN) . ' ' . site_url();
                ?>
            </p>
            <p>
                <?php
                echo esc_html__('Get site url:', WPS_IC_TEXTDOMAIN) . ' ' . get_site_url();
                ?>
            </p>
        </td>
    </tr>

    <tr>
        <td><?php esc_html_e('Plugin Configuration', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                $allowLive = get_option('wps_ic_allow_live');
                $allowLocal = get_option('wps_ic_allow_local');
                echo '<h3>' . esc_html__('Allow live:', WPS_IC_TEXTDOMAIN) . '</h3>' .$allowLive;
                echo '<h3>' . esc_html__('Allow local:', WPS_IC_TEXTDOMAIN) . '</h3>' .$allowLocal;
                echo '<h3>' . esc_html__('Account Status:', WPS_IC_TEXTDOMAIN) . '</h3>' . var_dump(get_transient('wps_ic_account_status'));
                ?>
            </p>
        </td>
    </tr>

    <tr>
        <td><?php esc_html_e('Get JobID For Crit', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                $jobID = get_transient(WPS_IC_JOB_TRANSIENT);
                var_dump($jobID);
                ?>
            </p>
        </td>
    </tr>

    <tr>
        <td><?php esc_html_e('Generate Ajax Params', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                $locate = get_option('wps_ic_geo_locate_v2');
                echo print_r($locate,true);
                ?>
            </p>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Generate Ajax Params', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <p>
                <?php
                $parameters = get_option(WPS_IC_SETTINGS);
                $translatedParameters = [];
                if (isset($parameters['generate_webp'])) {
                    $translatedParameters['webp'] = $parameters['generate_webp'];
                }

                if (isset($parameters['retina'])) {
                    $translatedParameters['retina'] = $parameters['retina'];
                }

                if (isset($parameters['qualityLevel'])) {
                    $translatedParameters['quality'] = $parameters['qualityLevel'];
                }

                if (isset($parameters['preserve_exif'])) {
                    $translatedParameters['exif'] = $parameters['preserve_exif'];
                }

                if (isset($parameters['max_width'])) {
                    $translatedParameters['max_width'] = $parameters['max_width'];
                } else {
                    $translatedParameters['max_width'] = WPS_IC_MAXWIDTH;
                }

                echo json_encode($translatedParameters);
                ?>
            </p>
        </td>
    </tr>

    <tr>
        <td><?php esc_html_e('Thumbnails', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <?php
            $sizes = get_intermediate_image_sizes();
            echo sprintf(esc_html__('Total Thumbs: %d', WPS_IC_TEXTDOMAIN), count($sizes));
            echo print_r($sizes, true);
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Paths', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <?php
            echo esc_html__('Debug Log:', WPS_IC_TEXTDOMAIN) . ' ' . WPS_IC_LOG . 'debug-log-' . date('d-m-Y') . '.txt';
            echo '<br/>' . esc_html__('Debug Log URI:', WPS_IC_TEXTDOMAIN) . ' <a href="' . WPS_IC_URI . 'debug-log-' . date('d-m-Y') . '.txt">' . WPS_IC_URI . 'debug-log-' . date('d-m-Y') . '.txt' . '</a>';
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Excluded List', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <?php
            $excluded = get_option('wps_ic_exclude_list');
            echo print_r($excluded, true);
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('API Key', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <?php
            $options = get_option(WPS_IC_OPTIONS);
            echo esc_html($options['api_key']);
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('CDN Zone Name', WPS_IC_TEXTDOMAIN); ?></td>
        <td>
            <?php
            echo esc_html(get_option('ic_cdn_zone_name'));
            ?>
        </td>
        <td>
            <a href="<?php
            echo wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&delete_option=ic_cdn_zone_name', 'wpc_debug_action'); ?>"><?php esc_html_e('Delete', WPS_IC_TEXTDOMAIN); ?></a>
        </td>
        <td></td>
    </tr>
    <tr>
        <td><?php esc_html_e('Custom CDN Zone Name', WPS_IC_TEXTDOMAIN); ?></td>
        <td>
            <?php
            echo esc_html(get_option('ic_custom_cname'));
            ?>
        </td>
        <td>
            <a href="<?php
            echo wp_nonce_url(wpc_dbg_base651($wps_ic::$slug) . '&view=debug_tool&delete_option=ic_custom_cname', 'wpc_debug_action'); ?>"><?php esc_html_e('Delete', WPS_IC_TEXTDOMAIN); ?></a>
        </td>
        <td></td>
    </tr>

    <tr>
        <td><?php esc_html_e('Plugin Activated', WPS_IC_TEXTDOMAIN); ?></td>
        <td><?php
            if (is_plugin_active('wp-compress-image-optimizer/wp-compress.php')) {
                echo 'Yes';
                $status = 'OK';
            } else {
                echo 'No';
                $status = 'BAD';
            }
            ?></td>
        <td><?php
            echo $status; ?></td>
        <td><?php esc_html_e('None', WPS_IC_TEXTDOMAIN); ?></td>
    </tr>
    <tr>
        <td><?php esc_html_e('PHP Version', WPS_IC_TEXTDOMAIN); ?></td>
        <td>
            <?php
            $version = phpversion();
            echo $version;
            if (version_compare($version, '7.0', '>=')) {
                $status = 'OK';
            } else {
                $status = 'BAD';
            }
            ?>
        </td>
        <td><?php
            echo $status; ?></td>
        <td><?php esc_html_e('None', WPS_IC_TEXTDOMAIN); ?></td>
    </tr>
    <tr>
        <td><?php esc_html_e('WP Version', WPS_IC_TEXTDOMAIN); ?></td>
        <td>
            <?php
            $wp_version = get_bloginfo('version');
            echo $wp_version;
            if (version_compare($wp_version, '5.0', '>=')) {
                $status = 'OK';
            } else {
                $status = 'BAD';
            }
            ?>
        </td>
        <td>
            <?php
            echo $status;
            ?>
        </td>
        <td>
            <?php esc_html_e('None', WPS_IC_TEXTDOMAIN); ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Options', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <button class="wps_copy_button button-primary" data-field="options" style="float:right"><?php esc_html_e('Copy text', WPS_IC_TEXTDOMAIN); ?></button>
            <textarea id="wps_options_field" style="width:100%"><?php
                echo json_encode(get_option(WPS_IC_OPTIONS));
                ?>
          </textarea>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Settings', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <button class="wps_copy_button button-primary" data-field="settings" style="float:right"><?php esc_html_e('Copy text', WPS_IC_TEXTDOMAIN); ?></button>
            <?php
            echo json_encode(get_option(WPS_IC_SETTINGS));
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Delivery Diagnostic', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <button class="wps_copy_button button-primary" data-field="delivery" style="float:right"><?php esc_html_e('Copy text', WPS_IC_TEXTDOMAIN); ?></button>
            <?php


            if (!class_exists('WPC_Delivery_Resolver') && defined('WPS_IC_DIR') && @file_exists(WPS_IC_DIR . 'addons/cdn/delivery-resolver.php')) {
                @require_once WPS_IC_DIR . 'addons/cdn/delivery-resolver.php';
            }
            if (!class_exists('WPC_Negotiated_Delivery') && defined('WPS_IC_DIR') && @file_exists(WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php')) {
                @require_once WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php';
            }
            $wpc_dd   = ['resolver_loaded' => class_exists('WPC_Delivery_Resolver')];
            $wpc_dd['wpc_delivery_state']    = get_option('wpc_delivery_state', null);
            $wpc_dd['wpc_delivery_override'] = get_option('wpc_delivery_override', null);
            $wpc_dd_s = get_option(WPS_IC_SETTINGS);
            $wpc_dd_g = function ($k) use ($wpc_dd_s) { return (is_array($wpc_dd_s) && isset($wpc_dd_s[$k])) ? $wpc_dd_s[$k] : '(unset)'; };
            $wpc_dd['gates'] = [
                'live-cdn' => $wpc_dd_g('live-cdn'), 'cdnAll' => $wpc_dd_g('cdnAll'),
                'picture_webp' => $wpc_dd_g('picture_webp'), 'picture_avif' => $wpc_dd_g('picture_avif'),
                'generate_webp' => $wpc_dd_g('generate_webp'), 'generate_adaptive' => $wpc_dd_g('generate_adaptive'),
                'wpc_optimization_mode' => $wpc_dd_g('wpc_optimization_mode'),
                'single-url-image-format' => $wpc_dd_g('single-url-image-format'),
                'zone_id' => get_option('wpc_v2_zone_id', '(unset)'),
            ];
            if (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'pick_test_image')) {
                $wpc_dd['pick_test_image'] = WPC_Delivery_Resolver::pick_test_image();
            }
            if (!empty($_GET['wpc_dprobe']) && class_exists('WPC_Delivery_Resolver')) {
                if (method_exists('WPC_Delivery_Resolver', 'resolve_verbose')) {
                    $wpc_dd['forced_reverify'] = WPC_Delivery_Resolver::resolve_verbose(true);
                }
                $wpc_dd_t   = isset($wpc_dd['pick_test_image']) ? $wpc_dd['pick_test_image'] : null;
                $wpc_dd_url = (is_array($wpc_dd_t) && !empty($wpc_dd_t['cdn_webp_url'])) ? (string) $wpc_dd_t['cdn_webp_url'] : '';
                if ($wpc_dd_url !== '' && method_exists('WPC_Delivery_Resolver', 'probe')) {
                    $wpc_dd_pr = [];
                    foreach (['avif' => 'image/avif,image/webp,*/*', 'webp' => 'image/webp,*/*', 'legacy' => 'image/*,*/*'] as $wpc_dd_c => $wpc_dd_a) {
                        $wpc_dd_t0 = microtime(true);
                        $wpc_dd_p  = WPC_Delivery_Resolver::probe($wpc_dd_url, $wpc_dd_a);
                        if (is_array($wpc_dd_p)) { $wpc_dd_p['_ms'] = (int) round((microtime(true) - $wpc_dd_t0) * 1000); unset($wpc_dd_p['body']); }
                        $wpc_dd_pr[$wpc_dd_c] = $wpc_dd_p;
                    }
                    $wpc_dd['live_loopback_probes'] = $wpc_dd_pr;
                    if (method_exists('WPC_Delivery_Resolver', 'evaluate_cdn_probes')) {
                        $wpc_dd['live_evaluate'] = WPC_Delivery_Resolver::evaluate_cdn_probes($wpc_dd_pr);
                    }
                }
            } else {
                $wpc_dd['_hint'] = 'Append &wpc_dprobe=1 to this page URL, reload, and re-open this tab to ALSO run the live origin->edge loopback probe + a forced re-verify.';
            }
            ?>
            <textarea id="wps_delivery_field" style="width:100%;min-height:340px;font-family:monospace"><?php echo esc_textarea(wp_json_encode($wpc_dd, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></textarea>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Local server API', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
                <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
                <label for="server"><?php esc_html_e('Server:', WPS_IC_TEXTDOMAIN); ?></label>
                <select id="server" name="local_server">
                    <?php
                    foreach ($servers as $value => $label) {
                        $selected = ($local_server == $value) ? 'selected' : '';
                        echo '<option value="' . $value . '" ' . $selected . '>' . $label . '</option>';
                    }
                    ?>
                </select>
                <input type="submit" value="<?php esc_attr_e('Save Server', WPS_IC_TEXTDOMAIN); ?>" class="button-primary" style="float:right">
            </form>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Preloads Debug - Last Warmup', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <?php
            $lastLog = get_option('wps_ic_last_warmpup');
            echo print_r($lastLog,true);
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Preloads Desktop', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
                <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
                <h3><?php esc_html_e('Automatic Preloads found by API (can edit)', WPS_IC_TEXTDOMAIN); ?></h3>
                <textarea name="preloads_lcp" style="width:100%;height:150px;"><?php
                    if (!empty($preloads['lcp'])) {
                        echo implode("\n", $preloads['lcp']);
                    }
                    ?></textarea>
                <h3><?php esc_html_e('Manual Desktop Preloads (can edit)', WPS_IC_TEXTDOMAIN); ?></h3>
                <textarea name="preloads" style="width:100%;height:150px;"><?php
                    if (!empty($preloads['custom']) && is_array($preloads['custom'])) {
                        echo implode("\n", $preloads['custom']);
                    }
                    ?></textarea>

                <h3><?php esc_html_e('Automatic Mobile Preloads found by API (can edit)', WPS_IC_TEXTDOMAIN); ?></h3>
                <textarea name="preloadsMobile_lcp" style="width:100%;height:150px;"><?php
                if (!empty($preloadsMobile['lcp'])) {
                    echo implode("\n", $preloadsMobile['lcp']);
                }
                    ?></textarea>
                <h3><?php esc_html_e('Manual Mobile Preloads (can edit)', WPS_IC_TEXTDOMAIN); ?></h3>
                <textarea name="preloadsMobile" style="width:100%;height:150px;"><?php
                    if (!empty($preloadsMobile['custom']) && is_array($preloadsMobile['custom'])) {
                        echo implode("\n", $preloadsMobile['custom']);
                    }
                    ?></textarea>
                <input type="submit" value="<?php esc_attr_e('Save Preloads', WPS_IC_TEXTDOMAIN); ?>" name="savePreloads" class="button-primary"
                       style="float:right">
            </form>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Cache refresh time (minutes)', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
                <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
                <input type="text" name="cache_refresh_time" value="<?php echo
                $settings['cache_refresh_time']; ?>">
                <input type="submit" value="<?php esc_attr_e('Save cache refresh', WPS_IC_TEXTDOMAIN); ?>" name="save" class="button-primary"
                       style="float:right">
            </form>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Elementor Skip Sections', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
			    <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
                <p><?php esc_html_e('Configure how many Elementor sections to skip before applying lazy loading/optimization.', WPS_IC_TEXTDOMAIN); ?></p>

			    <?php $skipSections = get_option('wps_ic_elementor_skip_sections', []); ?>

                <label for="elementor_skip_desktop"><?php esc_html_e('Desktop Skip Count:', WPS_IC_TEXTDOMAIN); ?></label>
                <input type="number" id="elementor_skip_desktop" name="elementor_skip_desktop"
                       value="<?php echo isset($skipSections['desktop']) ? $skipSections['desktop'] : 5; ?>"
                       min="0" max="20" style="width: 80px;">


                <label for="elementor_skip_mobile"><?php esc_html_e('Mobile Skip Count:', WPS_IC_TEXTDOMAIN); ?></label>
                <input type="number" id="elementor_skip_mobile" name="elementor_skip_mobile"
                       value="<?php echo isset($skipSections['mobile']) ? $skipSections['mobile'] : 5; ?>"
                       min="0" max="20" style="width: 80px;">

                <input type="submit" name="elementor_skip_sections" value="<?php esc_attr_e('Save Skip Settings', WPS_IC_TEXTDOMAIN); ?>" class="button-primary" style="float:right;">
            </form>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Fonts', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
			    <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce');
                $gui = new wpc_gui_v4();
                echo $gui->font_dropdown('Fonts', 'Description');
                ?>
                <input type="submit" name="fonts" value="<?php esc_attr_e('Save Fonts', WPS_IC_TEXTDOMAIN); ?>" class="button-primary" style="float:right;">
            </form>
        </td>
    </tr>
    <tr>
    <tr>
        <td><?php esc_html_e('Remove fonts from critical', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <form method="post" action="">
                <?php wp_nonce_field('wpc_settings_save', 'wpc_settings_save_nonce'); ?>
                <textarea name="remove_fonts" style="width:100%;height:150px;"><?php
                    $removeFonts = get_option('wps_ic_remove_fonts', []);
                    echo implode("\n", $removeFonts);
                    ?></textarea>
                <input type="submit" value="<?php esc_attr_e('Save', WPS_IC_TEXTDOMAIN); ?>" class="button-primary"
                       style="float:right">
            </form>
        </td>
    </tr>
    </tbody>
</table>


<?php


$wpcOverviewStats = [
    'compress' => get_option('wpc_compress_stats', []),
    'restore'  => get_option('wpc_restore_stats',  []),
    'ladder'   => get_option('wpc_ladder_stats',   []),
];
$wpcOverviewMeta = [
    'compress' => ['label' => 'Compress',         'fired' => 'total_compresses_fired',  'success' => 'total_compresses_succeeded',  'last' => 'last_compress_at',  'p95' => 'wpc_compress_stats_p95'],
    'restore'  => ['label' => 'Restore',          'fired' => 'total_restores_fired',    'success' => 'total_restores_succeeded',    'last' => 'last_restore_at',   'p95' => 'wpc_restore_stats_p95'],
    'ladder'   => ['label' => 'Ladder backfill',  'fired' => 'total_backfills_fired',   'success' => 'total_backfills_succeeded',   'last' => 'last_backfill_at',  'p95' => 'wpc_ladder_stats_p95'],
];
?>
<h2>Pipeline Overview</h2>
<table class="wp-list-table widefat fixed striped" style="max-width:880px;">
    <thead>
        <tr>
            <th style="width:25%;">Pipeline</th>
            <th style="width:15%;">Fired</th>
            <th style="width:18%;">Success rate</th>
            <th style="width:18%;">p95 (ms)</th>
            <th style="width:24%;">Last activity</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($wpcOverviewMeta as $key => $meta): ?>
            <?php
                $stats   = $wpcOverviewStats[$key];
                $fleet   = isset($stats['fleet']) && is_array($stats['fleet']) ? $stats['fleet'] : [];
                $fired   = (int) ($fleet[$meta['fired']]   ?? 0);
                $success = (int) ($fleet[$meta['success']] ?? 0);
                $rate    = $fired > 0 ? round(($success / $fired) * 100, 1) : 0;
                $p95     = function_exists($meta['p95']) ? (int) call_user_func($meta['p95'], $stats) : 0;
                $last_ts = (int) ($fleet[$meta['last']] ?? 0);
                $last_ago = $last_ts > 0 ? human_time_diff($last_ts) . ' ago' : '—';
                $rate_color = $fired === 0 ? '#888' : ($rate >= 95 ? '#22b73a' : ($rate >= 80 ? '#fbae40' : '#ef5a5a'));
            ?>
            <tr>
                <td><strong><?php echo esc_html($meta['label']); ?></strong></td>
                <td><?php echo number_format_i18n($fired); ?></td>
                <td>
                    <?php if ($fired > 0): ?>
                        <span style="color:<?php echo esc_attr($rate_color); ?>;font-weight:600;"><?php echo esc_html($rate); ?>%</span>
                        <span style="color:#888;font-size:11px;">(<?php echo number_format_i18n($success); ?>/<?php echo number_format_i18n($fired); ?>)</span>
                    <?php else: ?>
                        <span style="color:#888;">—</span>
                    <?php endif; ?>
                </td>
                <td><?php echo $p95 > 0 ? number_format_i18n($p95) . ' ms' : '<span style="color:#888;">—</span>'; ?></td>
                <td><?php echo esc_html($last_ago); ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php


$wpcLadderStats = get_option('wpc_ladder_stats', []);
$wpcLadderQueue = get_option('wpc_ladder_gen_queue', []);
if (!is_array($wpcLadderQueue)) $wpcLadderQueue = [];
$wpcLadderGenLog = get_option('wpc_variant_gen_log', []);
if (!is_array($wpcLadderGenLog)) $wpcLadderGenLog = [];
$wpcLadderPrewarm = get_option('wpc_prewarm_status', []);
if (!is_array($wpcLadderPrewarm)) $wpcLadderPrewarm = [];

$wpcLadderFleet    = isset($wpcLadderStats['fleet']) && is_array($wpcLadderStats['fleet']) ? $wpcLadderStats['fleet'] : [];
$wpcLadderTiming   = isset($wpcLadderStats['timing']) && is_array($wpcLadderStats['timing']) ? $wpcLadderStats['timing'] : [];
$wpcLadderQueueStat = isset($wpcLadderStats['queue']) && is_array($wpcLadderStats['queue']) ? $wpcLadderStats['queue'] : [];
$wpcLadderTriggers = isset($wpcLadderStats['triggers']) && is_array($wpcLadderStats['triggers']) ? $wpcLadderStats['triggers'] : [];

$wpcLadderSamples = (int) ($wpcLadderTiming['samples'] ?? 0);
$wpcLadderAvgMs   = $wpcLadderSamples > 0 ? (int) round(($wpcLadderTiming['sum_ms'] ?? 0) / $wpcLadderSamples) : 0;
$wpcLadderP95Ms   = function_exists('wpc_ladder_stats_p95') ? wpc_ladder_stats_p95($wpcLadderStats) : 0;
$wpcLadderMaxMs   = (int) ($wpcLadderTiming['max_ms'] ?? 0);

$wpcLadderTotalFired     = (int) ($wpcLadderFleet['total_backfills_fired'] ?? 0);
$wpcLadderTotalSucceeded = (int) ($wpcLadderFleet['total_backfills_succeeded'] ?? 0);
$wpcLadderTotalFailed    = (int) ($wpcLadderFleet['total_backfills_failed'] ?? 0);
?>
<h2 style="margin-top:40px;">Modern Delivery Ladder Status</h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
    <tr>
        <th style="width:260px;">Metric</th>
        <th>Value</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td><strong>Current queue depth</strong></td>
        <td><?php echo count($wpcLadderQueue); ?> attachment(s) — <?php echo get_option('wpc_ladder_gen_queue_has_items') ? 'worker has work' : 'idle'; ?></td>
    </tr>
    <tr>
        <td><strong>Max queue depth ever</strong></td>
        <td>
            <?php echo (int) ($wpcLadderQueueStat['max_depth_ever'] ?? 0); ?>
            <?php if (!empty($wpcLadderQueueStat['max_depth_at'])): ?>
                (last reached <?php echo esc_html(date('Y-m-d H:i:s', (int) $wpcLadderQueueStat['max_depth_at'])); ?> UTC)
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Backfills fired</strong></td>
        <td>
            <?php echo $wpcLadderTotalFired; ?> total
            — <span style="color:#2a7a2a;"><?php echo $wpcLadderTotalSucceeded; ?> succeeded</span>
            / <span style="color:#a00;"><?php echo $wpcLadderTotalFailed; ?> failed</span>
        </td>
    </tr>
    <tr>
        <td><strong>Variants generated</strong></td>
        <td>
            <?php echo (int) ($wpcLadderFleet['total_variants_avif'] ?? 0); ?> AVIF
            + <?php echo (int) ($wpcLadderFleet['total_variants_webp'] ?? 0); ?> WebP
            <?php if (!empty($wpcLadderFleet['total_variants_jpg'])): ?>
                + <?php echo (int) $wpcLadderFleet['total_variants_jpg']; ?> JPG
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Backfill duration</strong></td>
        <td>
            avg <?php echo $wpcLadderAvgMs; ?>ms
            · p95 <?php echo $wpcLadderP95Ms; ?>ms
            · max <?php echo $wpcLadderMaxMs; ?>ms
            <?php if ($wpcLadderSamples > 0): ?>
                (<?php echo $wpcLadderSamples; ?> samples)
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Trigger attribution</strong></td>
        <td>
            <?php
            $triggerOrder = ['loopback', 'shutdown', 'admin', 'cron', 'manual', 'prewarm', 'cli-force', 'unknown'];
            $parts = [];
            foreach ($triggerOrder as $trig) {
                $count = (int) ($wpcLadderTriggers[$trig] ?? 0);
                if ($count > 0) $parts[] = $trig . ' <strong>' . $count . '</strong>';
            }
            echo $parts ? implode(' · ', $parts) : '<em>no fires yet</em>';
            ?>
        </td>
    </tr>
    <tr>
        <td><strong>Last backfill</strong></td>
        <td>
            <?php if (!empty($wpcLadderFleet['last_backfill_at'])): ?>
                <?php echo esc_html(date('Y-m-d H:i:s', (int) $wpcLadderFleet['last_backfill_at'])); ?> UTC
                (<?php echo human_time_diff((int) $wpcLadderFleet['last_backfill_at'], time()); ?> ago)
            <?php else: ?>
                <em>never fired</em>
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Activation pre-warm</strong></td>
        <td>
            <?php if (!empty($wpcLadderPrewarm['state'])): ?>
                state=<?php echo esc_html($wpcLadderPrewarm['state']); ?>
                · prewarmed=<?php echo (int) ($wpcLadderPrewarm['prewarmed'] ?? 0); ?>
                <?php if (!empty($wpcLadderPrewarm['completed_at'])): ?>
                    · done <?php echo esc_html(date('Y-m-d H:i:s', (int) $wpcLadderPrewarm['completed_at'])); ?> UTC
                <?php endif; ?>
                <?php if (!empty($wpcLadderPrewarm['failed_pages'])): ?>
                    · failed_pages=<?php echo (int) $wpcLadderPrewarm['failed_pages']; ?>
                <?php endif; ?>
            <?php else: ?>
                <em>not yet fired</em>
            <?php endif; ?>
        </td>
    </tr>
    </tbody>
</table>

<?php if (!empty($wpcLadderQueue)): ?>
    <h3 style="margin-top:30px;">Current queue (<?php echo count($wpcLadderQueue); ?>)</h3>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr><th style="width:120px;">Attachment ID</th><th>Widths pending</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($wpcLadderQueue, 0, 25, true) as $aid => $widths): ?>
            <tr>
                <td><?php echo (int) $aid; ?></td>
                <td><?php echo esc_html(implode(', ', array_map('intval', (array) $widths))); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if (count($wpcLadderQueue) > 25): ?>
        <p><em>… and <?php echo count($wpcLadderQueue) - 25; ?> more. Showing first 25.</em></p>
    <?php endif; ?>
<?php endif; ?>

<?php if (!empty($wpcLadderGenLog)): ?>
    <h3 style="margin-top:30px;">Recent backfill events (last 10)</h3>
    <table class="wp-list-table widefat fixed striped">
        <thead>
        <tr>
            <th style="width:150px;">Time (UTC)</th>
            <th style="width:90px;">Attachment</th>
            <th>Widths</th>
            <th style="width:110px;">Formats</th>
            <th style="width:90px;">Duration</th>
            <th style="width:90px;">Trigger</th>
            <th style="width:70px;">OK?</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach (array_slice($wpcLadderGenLog, -10) as $entry): ?>
            <?php
            $widthsDisplay = '';
            if (!empty($entry['widths_delivered']) && is_array($entry['widths_delivered'])) {
                $widthsDisplay = implode(', ', array_map('intval', $entry['widths_delivered']));
            } elseif (is_array($entry['w'] ?? null)) {
                $widthsDisplay = implode(', ', array_map('intval', $entry['w']));
            } elseif (!empty($entry['w'])) {
                $widthsDisplay = (string) (int) $entry['w'];
            }

            $formatsDisplay = '';
            if (!empty($entry['formats_delivered']) && is_array($entry['formats_delivered'])) {
                $fmtParts = [];
                foreach ($entry['formats_delivered'] as $fmt => $cnt) {
                    if ((int) $cnt > 0) $fmtParts[] = $fmt . '×' . (int) $cnt;
                }
                $formatsDisplay = implode(' ', $fmtParts);
            } elseif (!empty($entry['f'])) {
                $formatsDisplay = is_array($entry['f']) ? implode(',', $entry['f']) : (string) $entry['f'];
            }

            $dur = isset($entry['duration_ms']) ? ((int) $entry['duration_ms']) . 'ms' : '—';
            $trg = isset($entry['trigger_source']) ? (string) $entry['trigger_source'] : (isset($entry['ctx']) ? (string) $entry['ctx'] : '—');
            $ok  = !isset($entry['success']) ? '—' : ($entry['success'] ? '✓' : '✗');
            ?>
            <tr>
                <td><?php echo esc_html(date('Y-m-d H:i:s', (int) ($entry['t'] ?? 0))); ?></td>
                <td>#<?php echo (int) ($entry['aid'] ?? 0); ?></td>
                <td><?php echo esc_html($widthsDisplay); ?></td>
                <td><?php echo esc_html($formatsDisplay); ?></td>
                <td><?php echo esc_html($dur); ?></td>
                <td><?php echo esc_html($trg); ?></td>
                <td><?php echo $ok; ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>

<?php


$wpcCompressStats = get_option('wpc_compress_stats', []);
if (!is_array($wpcCompressStats)) $wpcCompressStats = [];
$wpcCompressFleet   = isset($wpcCompressStats['fleet']) && is_array($wpcCompressStats['fleet']) ? $wpcCompressStats['fleet'] : [];
$wpcCompressTiming  = isset($wpcCompressStats['timing']) && is_array($wpcCompressStats['timing']) ? $wpcCompressStats['timing'] : [];
$wpcCompressSources = isset($wpcCompressStats['sources']) && is_array($wpcCompressStats['sources']) ? $wpcCompressStats['sources'] : [];

$wpcCompressSamples = (int) ($wpcCompressTiming['samples'] ?? 0);
$wpcCompressAvgMs   = $wpcCompressSamples > 0 ? (int) round(($wpcCompressTiming['sum_ms'] ?? 0) / $wpcCompressSamples) : 0;
$wpcCompressP95Ms   = function_exists('wpc_compress_stats_p95') ? wpc_compress_stats_p95($wpcCompressStats) : 0;
$wpcCompressMaxMs   = (int) ($wpcCompressTiming['max_ms'] ?? 0);

$wpcCompressFired     = (int) ($wpcCompressFleet['total_compresses_fired'] ?? 0);
$wpcCompressSucceeded = (int) ($wpcCompressFleet['total_compresses_succeeded'] ?? 0);
$wpcCompressFailed    = (int) ($wpcCompressFleet['total_compresses_failed'] ?? 0);
?>
<h2 style="margin-top:40px;">Compress Status</h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
    <tr>
        <th style="width:260px;">Metric</th>
        <th>Value</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td><strong>Compresses fired</strong></td>
        <td>
            <?php echo $wpcCompressFired; ?> total
            — <span style="color:#2a7a2a;"><?php echo $wpcCompressSucceeded; ?> succeeded</span>
            / <span style="color:#a00;"><?php echo $wpcCompressFailed; ?> failed</span>
        </td>
    </tr>
    <tr>
        <td><strong>Source attribution</strong></td>
        <td>
            <?php
            $compressSourceOrder = ['upload', 'single', 'bulk', 'retry', 'unknown'];
            $cParts = [];
            foreach ($compressSourceOrder as $src) {
                $count = (int) ($wpcCompressSources[$src] ?? 0);
                if ($count > 0) $cParts[] = esc_html($src) . ' <strong>' . $count . '</strong>';
            }
            echo $cParts ? implode(' · ', $cParts) : '<em>no compresses yet</em>';
            ?>
        </td>
    </tr>
    <tr>
        <td><strong>Compress duration</strong></td>
        <td>
            avg <?php echo $wpcCompressAvgMs; ?>ms
            · p95 <?php echo $wpcCompressP95Ms; ?>ms
            · max <?php echo $wpcCompressMaxMs; ?>ms
            <?php if ($wpcCompressSamples > 0): ?>
                (<?php echo $wpcCompressSamples; ?> samples)
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Last compress</strong></td>
        <td>
            <?php if (!empty($wpcCompressFleet['last_compress_at'])): ?>
                <?php echo esc_html(date('Y-m-d H:i:s', (int) $wpcCompressFleet['last_compress_at'])); ?> UTC
                (<?php echo human_time_diff((int) $wpcCompressFleet['last_compress_at'], time()); ?> ago)
            <?php else: ?>
                <em>never fired</em>
            <?php endif; ?>
        </td>
    </tr>
    </tbody>
</table>

<?php


$wpcRestoreStats = get_option('wpc_restore_stats', []);
if (!is_array($wpcRestoreStats)) $wpcRestoreStats = [];
$wpcRestoreFleet   = isset($wpcRestoreStats['fleet']) && is_array($wpcRestoreStats['fleet']) ? $wpcRestoreStats['fleet'] : [];
$wpcRestoreTiming  = isset($wpcRestoreStats['timing']) && is_array($wpcRestoreStats['timing']) ? $wpcRestoreStats['timing'] : [];
$wpcRestoreSources = isset($wpcRestoreStats['sources']) && is_array($wpcRestoreStats['sources']) ? $wpcRestoreStats['sources'] : [];

$wpcRestoreSamples = (int) ($wpcRestoreTiming['samples'] ?? 0);
$wpcRestoreAvgMs   = $wpcRestoreSamples > 0 ? (int) round(($wpcRestoreTiming['sum_ms'] ?? 0) / $wpcRestoreSamples) : 0;
$wpcRestoreP95Ms   = function_exists('wpc_restore_stats_p95') ? wpc_restore_stats_p95($wpcRestoreStats) : 0;
$wpcRestoreMaxMs   = (int) ($wpcRestoreTiming['max_ms'] ?? 0);

$wpcRestoreFired     = (int) ($wpcRestoreFleet['total_restores_fired'] ?? 0);
$wpcRestoreSucceeded = (int) ($wpcRestoreFleet['total_restores_succeeded'] ?? 0);
$wpcRestoreFailed    = (int) ($wpcRestoreFleet['total_restores_failed'] ?? 0);
?>
<h2 style="margin-top:40px;">Restore Status</h2>
<table class="wp-list-table widefat fixed striped">
    <thead>
    <tr>
        <th style="width:260px;">Metric</th>
        <th>Value</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td><strong>Restores fired</strong></td>
        <td>
            <?php echo $wpcRestoreFired; ?> total
            — <span style="color:#2a7a2a;"><?php echo $wpcRestoreSucceeded; ?> succeeded</span>
            / <span style="color:#a00;"><?php echo $wpcRestoreFailed; ?> failed</span>
        </td>
    </tr>
    <tr>
        <td><strong>Source attribution</strong></td>
        <td>
            <?php
            $sourceOrder = ['local_bkp', 'cloud_bkp', 'service', 'unknown'];
            $sourceLabels = ['local_bkp' => 'local _bkp', 'cloud_bkp' => '/wpc-backups/', 'service' => 'service download', 'unknown' => 'unknown'];
            $sParts = [];
            foreach ($sourceOrder as $src) {
                $count = (int) ($wpcRestoreSources[$src] ?? 0);
                if ($count > 0) $sParts[] = esc_html($sourceLabels[$src] ?? $src) . ' <strong>' . $count . '</strong>';
            }
            echo $sParts ? implode(' · ', $sParts) : '<em>no restores yet</em>';
            ?>
        </td>
    </tr>
    <tr>
        <td><strong>Restore duration</strong></td>
        <td>
            avg <?php echo $wpcRestoreAvgMs; ?>ms
            · p95 <?php echo $wpcRestoreP95Ms; ?>ms
            · max <?php echo $wpcRestoreMaxMs; ?>ms
            <?php if ($wpcRestoreSamples > 0): ?>
                (<?php echo $wpcRestoreSamples; ?> samples)
            <?php endif; ?>
        </td>
    </tr>
    <tr>
        <td><strong>Last restore</strong></td>
        <td>
            <?php if (!empty($wpcRestoreFleet['last_restore_at'])): ?>
                <?php echo esc_html(date('Y-m-d H:i:s', (int) $wpcRestoreFleet['last_restore_at'])); ?> UTC
                (<?php echo human_time_diff((int) $wpcRestoreFleet['last_restore_at'], time()); ?> ago)
            <?php else: ?>
                <em>never fired</em>
            <?php endif; ?>
        </td>
    </tr>
    </tbody>
</table>

<script type="text/javascript">
    jQuery(document).ready(function ($) {

        $('.wps_copy_button').on('click', function () {
            var field = $(this).attr("data-field")
            console.log(field);
            var text = document.getElementById('wps_' + field + '_field');


            navigator.clipboard.writeText(text.value);


            alert('<?php echo esc_js(__('Copied to Clipboard', WPS_IC_TEXTDOMAIN)); ?>');
        })

    });
</script>