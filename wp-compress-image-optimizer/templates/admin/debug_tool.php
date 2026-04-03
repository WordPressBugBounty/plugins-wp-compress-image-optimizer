<?php
global $wps_ic, $wpdb;

if (!empty($_POST['wps_settings'])) {
    $settings = stripslashes($_POST['wps_settings']);
    $settings = json_decode($settings, true, JSON_UNESCAPED_SLASHES);
    if (is_array($settings)) {
        update_option(WPS_IC_SETTINGS, $settings);
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
    delete_option($_GET['delete_option']);
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

//list of api endpoints
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
	$preloadsLcp['lcp'] = [$_POST['preloads_lcp']]; // Wrap in array
	update_option('wps_ic_preloads', $preloadsLcp);
}

if (!empty($_POST['preloadsMobile_lcp'])) {
	$preloadsLcp = get_option('wps_ic_preloadsMobile', []);
	$preloadsLcp['lcp'] = [$_POST['preloadsMobile_lcp']]; // Wrap in array
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
    $removeFonts = [$_POST['remove_fonts']]; // Wrap in array
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
                        #update_option('wps_ic_critical_mc', sanitize_text_field($_GET['wps_ic_critical_mc']));
                    } else {
                        $settings = get_option(WPS_IC_SETTINGS);
                        $settings['mcCriticalCSS'] = 'api';
                        update_option(WPS_IC_SETTINGS, $settings);
                        #delete_option('wps_ic_critical_mc');
                    }
                }

                $cdn_critical_mc = get_option(WPS_IC_SETTINGS);


                if (empty($settings['mcCriticalCSS']) || $settings['mcCriticalCSS'] == 'mc') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_critical_mc=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable Old API', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_critical_mc=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable New API', WPS_IC_TEXTDOMAIN) . '</a>';
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
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_cdn_mc=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_cdn_mc=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
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
						    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_delay_v2_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
					    } else {
						    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_delay_v2_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
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
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&optimizejs_remove=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&optimizejs_remove=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
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
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&optimizejs_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&optimizejs_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
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
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_debug_log=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&wps_ic_debug_log=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
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
                }

                $development = get_option('wps_ic_development');

                if (empty($development) || $development == 'false') {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&php_development=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&php_development=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
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
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&ccss_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&ccss_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
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
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&ps_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&ps_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
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
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&php_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&php_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
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
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&js_debug=true') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Enable', WPS_IC_TEXTDOMAIN) . '</a>';
                } else {
                    echo '<a href="' . admin_url('admin.php?page=' . $wps_ic::$slug . '&view=debug_tool&js_debug=false') . '" class="button-primary" style="margin-right:20px;">' . esc_html__('Disable', WPS_IC_TEXTDOMAIN) . '</a>';
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
            echo $options['api_key'];
            ?>
        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('CDN Zone Name', WPS_IC_TEXTDOMAIN); ?></td>
        <td>
            <?php
            echo get_option('ic_cdn_zone_name');
            ?>
        </td>
        <td>
            <a href="<?php
            echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=debug_tool&delete_option=ic_cdn_zone_name'); ?>"><?php esc_html_e('Delete', WPS_IC_TEXTDOMAIN); ?></a>
        </td>
        <td></td>
    </tr>
    <tr>
        <td><?php esc_html_e('Custom CDN Zone Name', WPS_IC_TEXTDOMAIN); ?></td>
        <td>
            <?php
            echo get_option('ic_custom_cname');
            ?>
        </td>
        <td>
            <a href="<?php
            echo admin_url('options-general.php?page=' . $wps_ic::$slug . '&view=debug_tool&delete_option=ic_custom_cname'); ?>"><?php esc_html_e('Delete', WPS_IC_TEXTDOMAIN); ?></a>
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

        </td>
    </tr>
    <tr>
        <td><?php esc_html_e('Test API Connectivity', WPS_IC_TEXTDOMAIN); ?></td>
        <td colspan="3">
            <button class="test-api-button"><?php esc_html_e('Start Test', WPS_IC_TEXTDOMAIN); ?></button>
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


<script type="text/javascript">
    jQuery(document).ready(function ($) {

        $('.wps_copy_button').on('click', function () {
            var field = $(this).attr("data-field")
            console.log(field);
            var text = document.getElementById('wps_' + field + '_field');

            // Copy the text inside the text field
            navigator.clipboard.writeText(text.value);

            // Alert the copied text
            alert('<?php echo esc_js(__('Copied to Clipboard', WPS_IC_TEXTDOMAIN)); ?>');
        })

    });
</script>