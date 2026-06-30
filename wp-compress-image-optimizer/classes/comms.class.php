<?php


class wps_ic_comms extends wps_ic
{


    public function __construct()
    {
        if (!is_admin()) {
            if ((!empty($_POST['apikey']) && !empty($_POST['comms_action'])) || (!empty($_GET['apikey']) && !empty($_GET['comms_action']))) {
                add_action('send_headers', [$this, 'start_comms']);
            }
        }
    }


    public static function change_setting()
    {
        $settings = get_option(WPS_IC_SETTINGS);

        $setting_group = sanitize_text_field($_GET['group']);
        $setting_key = sanitize_text_field($_GET['setting']);
        $setting_value = sanitize_text_field($_GET['value']);

        if ($setting_key == 'cdn') {
            // First check if CDN Zone already exists
            $options = get_option(WPS_IC_OPTIONS);

            $request_params = [];
            $request_params['apiv3'] = 'true';
            $request_params['apikey'] = $options['api_key'];
            $request_params['action'] = 'cdn_check';
            $request_params['url'] = site_url();

            $params = ['method' => 'POST', 'timeout' => 30, 'redirection' => 3, 'sslverify' => false, 'httpversion' => '1.0', 'blocking' => true, // TODO: Mozda true?
                'headers' => [], 'body' => $request_params, 'cookies' => []];

            // Send call to API
            $call = wp_remote_post(WPS_IC_APIURL, $params);
        }

        if (isset($setting_group) && $setting_group != '') {
            $settings[$setting_group][$setting_key] = $setting_value;
        } else {
            $settings[$setting_key] = $setting_value;
        }

        update_option(WPS_IC_SETTINGS, $settings);

        wp_send_json_success();
    }


    public static function deactivate()
    {
        $settings = get_option(WPS_IC_SETTINGS);
        $settings['hide_compress'] = 0;
        update_option(WPS_IC_SETTINGS, $settings);

        $options = get_option(WPS_IC_OPTIONS);
        $options['api_key'] = '';
        $options['response_key'] = '';
        update_option(WPS_IC_OPTIONS, $options);

        delete_option('wps_ic_gen_hp_url');

        wp_send_json_success();
    }


    public static function test_connection()
    {
        global $wpdb;

        if (!function_exists('download_url')) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        // Get attachment
        $attachments = $wpdb->get_results(
            $wpdb->prepare(
                "
        SELECT ID
        FROM {$wpdb->prefix}posts
        WHERE post_type = %s
          AND post_status = %s
          AND post_mime_type = %s
        ORDER BY post_date DESC
        LIMIT 1
        ",
                'attachment',   // post_type
                'inherit',      // post_status
                'image/jpeg'    // post_mime_type
            )
        );

        if ($attachments) {

            $attachment_Path = get_attached_file($attachments[0]->ID);
            $attachment_URL = wp_get_attachment_image_src($attachments[0]->ID, 'full');

            if (!empty($attachment_Path) && !empty($attachment_URL)) {
                global $wps_ic;

                $original_filesize = filesize($attachment_Path);

                $compressed = get_post_meta($attachments[0]->ID, 'wps_ic_compressed', true);
                if ($compressed == 'true') {
                    // Restore first
                    $file_name = basename($attachment_Path);
                    $file_path = str_replace($file_name, '', $attachment_Path);

                    // Find image source on site
                    $image = wp_get_attachment_image_src($attachments[0]->ID, 'full');
                    $file_name = basename($image[0]);

                    $call = wp_remote_get(WPS_IC_APIURL . '?get_restore=true&site=' . site_url('/') . '&attachment_id=' . $attachments[0]->ID . '&file_name=' . $file_name, ['timeout' => 25, 'sslverify' => false]);

                    $original_image = wp_remote_retrieve_body($call);
                    $original_image = json_decode($original_image, true);
                    $original_image = $original_image['data'];

                    if (wp_remote_retrieve_response_code($call) == 200) {
                        $body = wp_remote_retrieve_body($call);
                        $body = json_decode($body);

                        if ($body->success == 'true') {

                            $temp = download_url($body->data);

                            if (!is_wp_error($temp) && filesize($temp) > 0) {
                                clearstatcache();

                                // Remove file
                                unlink($file_path . $file_name);

                                // New file
                                $fp = fopen($file_path . $file_name, 'w+');
                                fclose($fp);

                                copy($temp, $file_path . $file_name);

                                $query = $wpdb->prepare("UPDATE " . $wpdb->prefix . "ic_compressed SET restored='1' WHERE attachment_ID=%d",$attachments[0]->ID);
                                $wpdb->query($query);

                                $attach_data = wp_generate_attachment_metadata($attachments[0]->ID, $attachment_Path);
                                wp_update_attachment_metadata($attachments[0]->ID, $attach_data);

                                // Delete compress data
                                delete_post_meta($attachments[0]->ID, 'wps_ic_started');
                                delete_post_meta($attachments[0]->ID, 'wps_ic_reset');
                                delete_post_meta($attachments[0]->ID, 'wps_ic_times');
                                delete_post_meta($attachments[0]->ID, 'wps_ic_compressed');
                                delete_post_meta($attachments[0]->ID, 'wps_ic_data');
                                delete_post_meta($attachments[0]->ID, 'wps_ic_cdn');
                                delete_post_meta($attachments[0]->ID, 'wps_ic_in_bulk');
                                delete_post_meta($attachments[0]->ID, 'wps_ic_compressing');
                                delete_post_meta($attachments[0]->ID, 'wps_ic_restoring');

                            }
                        }

                    }

                    // Set compressing
                    delete_post_meta($attachments[0]->ID, 'wps_ic_reset');
                    delete_post_meta($attachments[0]->ID, 'wps_ic_started');
                    delete_post_meta($attachments[0]->ID, 'wps_ic_restoring');
                    delete_post_meta($attachments[0]->ID, 'wps_ic_in_bulk');

                    $original_filesize = filesize($attachment_Path);

                }

                $wps_ic->compress->single_bulk_v2(['attachment_id' => $attachments[0]->ID]);

                $compressed_filesize = filesize($attachment_Path);

                wp_send_json_success(['original_size' => $original_filesize, 'compressed_size' => $compressed_filesize]);
            }
        } else {
            wp_send_json_error('no-images');
        }
    }


    public static function get_stats()
    {
        global $wpdb;

        if (empty($_GET['range'])) {
            $_GET['range'] = 'current_month';
        }

        $range = $_GET['range'];

        if ($range == 'current_month') {

            $month_start = strtotime('first day of this month', time());
            $month_end = strtotime('last day of this month', time());

            $start_date = date('Y-m-d', $month_start);
            $end_date = date('Y-m-d', $month_end);

            $stats = $wpdb->get_results($wpdb->prepare("SELECT COUNT(ID) AS count,
               created,
               original,
               compressed,
               saved
        FROM {$wpdb->prefix}ic_stats
        WHERE created >= %s
          AND created <= %s
        GROUP BY attachment_ID
        ORDER BY created DESC
        ", $start_date, $end_date));

        } elseif ($range == 'last_month') {

            $month_start = strtotime('first day of last month', time());
            $month_end = strtotime('last day of last month', time());

            $start_date = date('Y-m-d', $month_start);
            $end_date = date('Y-m-d', $month_end);

            $stats = $wpdb->get_results($wpdb->prepare("SELECT COUNT(ID) AS count,
               created,
               original,
               compressed,
               saved
        FROM {$wpdb->prefix}ic_stats
        WHERE created >= %s
          AND created <= %s
        GROUP BY attachment_ID
        ORDER BY created DESC", $start_date, $end_date));

        } elseif ($range == 'get_month') {

            $month = sanitize_text_field($_GET['month']);

            $month_start = strtotime('first day of this month', strtotime($month));
            $month_end = strtotime('last day of this month', strtotime($month));

            $start_date = date('Y-m-d', $month_start);
            $end_date = date('Y-m-d', $month_end);

            $stats = $wpdb->get_results($wpdb->prepare("SELECT COUNT(ID) AS count,
               created,
               original,
               compressed,
               saved
        FROM {$wpdb->prefix}ic_stats
        WHERE created >= %s
          AND created <= %s
        GROUP BY attachment_ID
        ORDER BY created DESC", $start_date, $end_date));

        } else {
            $stats = $wpdb->get_results("SELECT COUNT(ID) AS count,
           created,
           original,
           compressed,
           saved
    FROM {$wpdb->prefix}ic_stats
    GROUP BY attachment_ID
    ORDER BY created DESC");
        }

        $output = [];

        if ($stats) {
            foreach ($stats as $stat) {
                $output[$stat->created]['count'] += $stat->count;
                $output[$stat->created]['original'] += $stat->original;
                $output[$stat->created]['compressed'] += $stat->compressed;
                $output[$stat->created]['saved'] += $stat->saved;
            }
        } else {
            $output[date('Y-m-d', $month_start)]['count'] = 0;
            $output[date('Y-m-d', $month_start)]['original'] = 0;
            $output[date('Y-m-d', $month_start)]['compressed'] = 0;
            $output[date('Y-m-d', $month_start)]['saved'] = 0;
        }

        wp_send_json_success($output);
    }


    public static function save_excludes()
    {

        $form = $_POST['form'];
        $savedForm = [];
        parse_str($form, $savedForm);

        if (!empty($savedForm)) {
            foreach ($savedForm as $settingName => $settingData) {

                if (!in_array($settingName, ['wpc-excludes', 'wpc-inline', 'wpc-url-excludes'])) {
                    wp_send_json_error('Forbidden.');
                }

                $option = get_option($settingName);
                if (!empty($settingData)) {
                    foreach ($settingData as $settingSubset => $settingValue) {
                        $settingValue = rtrim($settingValue, "\n");
                        $settingValue = explode("\n", $settingValue);
                        $option[$settingSubset] = $settingValue;
                    }
                }

                update_option($settingName, $option);
            }

            wp_send_json_success();
        }

        wp_send_json_error();
    }


    public static function get_excludes()
    {
        $option = get_option($_GET['name']);
        $value = $option[$_GET['subset']];

        if (empty($value)) {
            $value = '';
        } else {
            $value = implode("\n", $value);
        }

        wp_send_json_success($value);
    }


    public static function fetch_plugin_settings()
    {
        global $wpdb, $wps_ic;

        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        $wps_ic = new wps_ic();
        $output = [];

        if (is_multisite()) {
            $current_blog_id = get_current_blog_id();
            switch_to_blog($current_blog_id);
            $settings = get_option(WPS_IC_SETTINGS);
        } else {
            $settings = get_option(WPS_IC_SETTINGS);
        }

        if (!empty($settings)) {
            foreach ($settings as $key => $value) {
                $output['settings'][$key] = $value;
            }
        }

        $output['settings']['version'] = $wps_ic::$version;

        wp_send_json_success($output);
    }


    public static function media_compressed()
    {
        $imageID = sanitize_text_field($_GET['imageID']);
        $imageHash = sanitize_text_field($_GET['imageHash']);

    }


    public function remote_status()
    {
        $remote_action = get_option('ic_remote_action');
        if (!$remote_action || empty($remote_action)) {
            wp_send_json_success('empty');
        } else {
            wp_send_json_success($remote_action);
        }
    }


    public function isActive()
    {
        wp_send_json_success(parent::$version);
    }


    public function saveExcludes()
    {
        $options = get_option(WPS_IC_OPTIONS);
        $form = json_decode(stripslashes($_GET['form']), true);

        if (empty($form['apikey']) || $form['apikey'] !== $options['api_key']) {
            wp_send_json_error('bad-apikey', $form);
        }

        $form['groupName'] = sanitize_text_field($form['groupName']);

        if (!in_array($form['groupName'], ['wpc-excludes', 'wpc-inline', 'wpc-url-excludes'])) {
            wp_send_json_error('Forbidden.');
        }

        $option = get_option($form['groupName']);

        $excludedList = rtrim($form['value'], "\n");
        $excludedList = explode("\n", $excludedList);
        $excludedList = array_values(array_filter(array_map('trim', $excludedList)));

        $form['default_enabled'] = !empty($form['default_enabled']) ? sanitize_text_field($form['default_enabled']) : '0';
        $form['exclude_themes'] = !empty($form['exclude_themes']) ? sanitize_text_field($form['exclude_themes']) : '0';
        $form['exclude_plugins'] = !empty($form['exclude_plugins']) ? sanitize_text_field($form['exclude_plugins']) : '0';
        $form['exclude_wp'] = !empty($form['exclude_wp']) ? sanitize_text_field($form['exclude_wp']) : '0';
        $form['exclude_third'] = !empty($form['exclude_third']) ? sanitize_text_field($form['exclude_third']) : '0';


        $option[$form['settingName']] = $excludedList;
        $option[$form['settingName'] . '_default_excludes_disabled'] = $form['default_enabled'];
        $option[$form['settingName'] . '_exclude_themes'] = $form['exclude_themes'];
        $option[$form['settingName'] . '_exclude_plugins'] = $form['exclude_plugins'];
        $option[$form['settingName'] . '_exclude_wp'] = $form['exclude_wp'];
        $option[$form['settingName'] . '_exclude_third'] = $form['exclude_third'];
        update_option($form['groupName'], $option);

        if (!empty($form['min_mobile_width']) && $form['min_mobile_width'] !== 'false') {
            update_option('wpc-min-mobile-width', sanitize_text_field($form['min_mobile_width']));
        }

        wp_send_json_success();
    }


    public function getExcludes()
    {
        $options = get_option(WPS_IC_OPTIONS);
        $form = json_decode(stripslashes($_GET['form']), true);

        if (empty($form['apikey']) || $form['apikey'] !== $options['api_key']) {
            wp_send_json_error('bad-apikey');
        }

        $group_name = sanitize_text_field($form['groupName']);
        $setting_name = sanitize_text_field($form['settingName']);

        if (!in_array($group_name, ['wpc-excludes', 'wpc-inline', 'wpc-url-excludes'])) {
            wp_send_json_error('Forbidden.');
        }

        $option = get_option($group_name);
        $value = !empty($option[$setting_name]) ? $option[$setting_name] : [];
        $default_excludes = isset($option[$setting_name . '_default_excludes_disabled']) ? $option[$setting_name . '_default_excludes_disabled'] : '';
        $exclude_themes   = isset($option[$setting_name . '_exclude_themes'])   ? $option[$setting_name . '_exclude_themes']   : '';
        $exclude_plugins  = isset($option[$setting_name . '_exclude_plugins'])  ? $option[$setting_name . '_exclude_plugins']  : '';
        $exclude_wp       = isset($option[$setting_name . '_exclude_wp'])       ? $option[$setting_name . '_exclude_wp']       : '';
        $exclude_third    = isset($option[$setting_name . '_exclude_third'])    ? $option[$setting_name . '_exclude_third']    : '';
        $min_mobile_width = get_option('wpc-min-mobile-width');

        if (empty($value)) {
            $value = '';
        } else {
            $value = implode("\n", $value);
        }

        wp_send_json_success([
            'value'           => $value,
            'default_excludes' => $default_excludes,
            'exclude_themes'  => $exclude_themes,
            'exclude_plugins' => $exclude_plugins,
            'exclude_wp'      => $exclude_wp,
            'exclude_third'   => $exclude_third,
            'min_mobile_width' => $min_mobile_width,
        ]);
    }


    public function getGpsResult()
    {
        $gps = get_option(WPS_IC_LITE_GPS);
        if (!empty($gps) && !empty($gps['result'])) {
            wp_send_json_success($gps);
        }
        wp_send_json_error('not-done');
    }


    public function resetTest()
    {
        $options = get_option(WPS_IC_OPTIONS);

        // If a test is already in progress, don't start another one
        if (get_transient('wpc_initial_test')) {
            wp_send_json_success('already-running');
        }

        // Purge homepage HTML cache (same as reset button on user site)
        $url = home_url();
        $url_key_class = new wps_ic_url_key();
        $url_key = $url_key_class->setup($url);
        $cache = new wps_ic_cache_integrations();
        $cache::purgeCacheFiles($url_key);

        // Clear home test entry from WPS_IC_TESTS
        $tests = get_option(WPS_IC_TESTS);
        unset($tests['home']);
        update_option(WPS_IC_TESTS, $tests);

        // Save previous GPS result to history
        $history = get_option(WPS_IC_LITE_GPS_HISTORY);
        if (empty($history)) {
            $history = [];
        }
        $history[time()] = get_option(WPS_IC_LITE_GPS);
        update_option(WPS_IC_LITE_GPS_HISTORY, $history);

        // Clear current results and flags
        delete_transient('wpc_test_running');
        delete_option(WPS_IC_LITE_GPS);
        delete_option(WPC_WARMUP_LOG_SETTING);

        // Mark test as running
        set_transient('wpc_initial_test', 'running', 5 * 60);

        // Kick off pagespeed test
        $requests = new wps_ic_requests();
        $args = ['url' => home_url(), 'version' => self::$version, 'plugin_version' => self::$version, 'hash' => time() . mt_rand(100, 9999), 'apikey' => $options['api_key']];
        $response = $requests->POST(WPS_IC_PAGESPEED_API_URL_HOME, $args, ['timeout' => 5, 'blocking' => true, 'headers' => ['Content-Type' => 'application/json']]);

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (isset($data['jobId'])) {
            set_transient(WPS_IC_JOB_TRANSIENT, $data['jobId'], 60 * 10);
            wp_send_json_success('started');
        } else {
            set_transient(WPS_IC_JOB_TRANSIENT, 'failed', 60 * 10);
        }

        wp_send_json_error();
    }


    public function getCFOption()
    {
        $cf = get_option(WPS_IC_CF);
        wp_send_json_success($cf ?: []);
    }

    public function getCFCname()
    {
        $cname = get_option(WPS_IC_CF_CNAME);
        wp_send_json_success($cname ?: '');
    }

    public function saveCFOption()
    {
        $form = json_decode(stripslashes($_GET['form'] ?? '{}'), true);
        if (!empty($form['cf'])) {
            update_option(WPS_IC_CF, $form['cf']);
        }
        if (!empty($form['cname'])) {
            update_option(WPS_IC_CF_CNAME, $form['cname']);
        }
        if (isset($form['settings_cf'])) {
            $settings = get_option(WPS_IC_SETTINGS);
            $settings['cf'] = $form['settings_cf'];
            update_option(WPS_IC_SETTINGS, $settings);
        }
        wp_send_json_success();
    }

    public function deleteCFOption()
    {
        delete_option(WPS_IC_CF);
        delete_transient('wpc_cdn_backup');
        wp_send_json_success();
    }

    public function getPurgeRules()
    {
        $purge_rules = get_option('wps_ic_purge_rules');
        if (empty($purge_rules)) {
            $options = new wps_ic_options();
            $purge_rules = $options->get_preset('purge_rules');
        }
        wp_send_json_success($purge_rules ?: []);
    }

    public function savePurgeRules()
    {
        $form = json_decode(stripslashes($_GET['form'] ?? '{}'), true);
        $purge_rules = get_option('wps_ic_purge_rules', []);
        if (!empty($form['post-publish'])) {
            $purge_rules['post-publish'] = $form['post-publish'];
        }
        if (isset($form['hooks'])) {
            $purge_rules['hooks'] = $form['hooks'];
        }
        if (isset($form['scheduled'])) {
            $purge_rules['scheduled'] = $form['scheduled'];
        }
        update_option('wps_ic_purge_rules', $purge_rules);
        wp_send_json_success();
    }

    public function purgeAfterSave()
    {
        $form        = json_decode(stripslashes($_GET['form'] ?? '{}'), true);
        $changedKeys = isset($form['changed_keys']) ? (array) $form['changed_keys'] : [];

        $htmlPurgeKeys = [
            'replace-fonts', 'font-display', 'icon-font-display',
            'preload-crit-fonts', 'fontawesome-lazy',
            'css', 'js', 'fonts', 'lazy',
            'serve,jpg', 'serve,png', 'serve,gif', 'serve,svg',
            'generate_adaptive', 'generate_webp', 'retina', 'background-sizing',
            'qualityLevel',
            'avif-natural-source', 'fetchpriority-high', 'single-url-image-format', // v7.01.93/.94 — HTML-output keys (parity with ajax)
            'critical,css', 'delay,js',
            'minify,html', 'minify,css', 'minify,js',
            'cf,cdn', 'cf,assets',
        ];
        $critPurgeKeys = ['replace-fonts', 'font-display', 'icon-font-display', 'preload-crit-fonts', 'css', 'fonts', 'minify,css', 'critical,css'];

        $needsHtmlPurge = !empty($changedKeys) && !empty(array_intersect($changedKeys, $htmlPurgeKeys));
        $needsCritPurge = !empty($changedKeys) && !empty(array_intersect($changedKeys, $critPurgeKeys));

        if ($needsHtmlPurge) {
            delete_transient('wps_ic_css_cache');
            delete_option('wps_ic_modified_css_cache');
            delete_option('wps_ic_css_combined_cache');
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll(false, true, false, false, true);
            $cache::purgeCombinedFiles();
            wps_ic_ajax::purgeBreeze();
            wps_ic_ajax::purge_cache_files();
            if (function_exists('rocket_clean_domain')) rocket_clean_domain();
            if (defined('LSCWP_V')) do_action('litespeed_purge_all');
            if (defined('WPHB_VERSION')) do_action('wphb_clear_page_cache');
        }

        if ($needsCritPurge) {
            global $wpdb;
            $options_table = $wpdb->options;
            $wpdb->query($wpdb->prepare("DELETE FROM $options_table WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like('_transient_wpc_critical_key_') . '%', $wpdb->esc_like('_transient_timeout_wpc_critical_key_') . '%'));
            if (!isset($cache)) $cache = new wps_ic_cache_integrations();
            $cache::purgeCriticalFiles();
        }

        wp_send_json_success();
    }

    public function getCacheCookies()
    {
        $cookies_setting = get_option('wps_ic_cache_cookies');
        if ($cookies_setting === false) {
            $options = new wps_ic_options();
            $cookies_setting = $options->get_preset('cache_cookies');
        }
        wp_send_json_success($cookies_setting ?: []);
    }

    public function importSettings()
    {
        $form = json_decode(stripslashes($_GET['form'] ?? '{}'), true);

        $options_class = new wps_ic_options();

        if (isset($form['settings'])) {
            $settings = $options_class->setMissingSettings($form['settings']);
            update_option(WPS_IC_SETTINGS, $settings);
        }

        if (isset($form['excludes'])) {
            update_option('wpc-excludes', $form['excludes']);
        }

        if (isset($form['cache'])) {
            update_option('wps_ic_purge_rules', $form['cache']);
        }

        if (isset($form['cache_cookies'])) {
            update_option('wps_ic_cache_cookies', $form['cache_cookies']);
        }

        $cache = new wps_ic_cache_integrations();
        $cache::purgeCriticalFiles();
        // (v7.03.92) full-site Varnish: a settings import is a maximal site-wide markup change.
        $cache::purgeAll(false, true, false, false, true);

        wp_send_json_success(['msg' => 'Settings imported successfully']);
    }

    public function saveCacheCookies()
    {
        $form            = json_decode(stripslashes($_GET['form'] ?? '{}'), true);
        $cookies_setting = get_option('wps_ic_cache_cookies', []);
        if (isset($form['cookies'])) {
            $cookies_setting['cookies'] = $form['cookies'];
        }
        if (isset($form['exclude_cookies'])) {
            $cookies_setting['exclude_cookies'] = $form['exclude_cookies'];
        }
        update_option('wps_ic_cache_cookies', $cookies_setting);
        wp_send_json_success();
    }

    public function getOptimizationStatus()
    {
        $warmup_class = new wps_ic_preload_warmup();
        $pages  = $warmup_class->getPagesToOptimize();
        $status = $warmup_class->get_optimization_status();
        $response = [
            'optimizationStatus' => $status,
            'optimized'          => ($pages['total'] ?? 0) - ($pages['unoptimized'] ?? 0),
            'total'              => $pages['total'] ?? 0,
            'connectivity'       => true,
        ];
        wp_send_json_success($response);
    }


    public function saveSettings()
    {
        $options = get_option(WPS_IC_OPTIONS);
        $settings = get_option(WPS_IC_SETTINGS);

        if (empty($_POST['form'])) {
            $form = json_decode(stripslashes($_GET['form']), true);
        } else {
            $form = json_decode(stripslashes($_POST['form']), true);
        }

        if (empty($form['apikey']) || $form['apikey'] !== $options['api_key']) {
            wp_send_json_error(['msg' => 'bad-apikey']); // (v7.10.04) SECURITY: was dumping $_POST/$_GET back to the caller
        }

        if (!empty($settings)) {
            foreach ($settings as $key => $value) {
                if (isset($form['options'][$key])) {
                    if (!is_array($value)) {
                        $settings[$key] = $form['options'][$key];
                        unset($form['options'][$key]);
                    } else {
                        foreach ($value as $k => $v) {
                            if (isset($form['options'][$key][$k])) {
                                $settings[$key][$k] = $form['options'][$key][$k];
                                unset($form['options'][$key][$k]);
                            }
                        }
                        unset($form['options'][$key]);
                    }
                }
            }
        }


        if (!empty($form['options'])) {
            foreach ($form['options'] as $key => $value) {
                if (!is_array($value)) {
                    $settings[$key] = $value;
                } else {
                    foreach ($value as $k => $v) {
                        $settings[$key][$k] = $form['options'][$key][$k];
                    }
                }

            }
        }

        // Recalculate live-cdn from the fully-merged settings so a partial agency
        // save (only the changed key in the payload) doesn't incorrectly set it
        // based on incomplete form data.
        $cdnEnabled = '0';
        foreach (['jpg', 'png', 'gif', 'svg'] as $_k) {
            if (!empty($settings['serve'][$_k]) && $settings['serve'][$_k] == '1') {
                $cdnEnabled = '1';
                break;
            }
        }
        if (!$cdnEnabled && !empty($settings['css'])   && $settings['css']   == '1') $cdnEnabled = '1';
        if (!$cdnEnabled && !empty($settings['js'])    && $settings['js']    == '1') $cdnEnabled = '1';
        if (!$cdnEnabled && !empty($settings['fonts']) && $settings['fonts'] == '1') $cdnEnabled = '1';
        $settings['live-cdn'] = $cdnEnabled;

        // Capture old modern_image_delivery value BEFORE option write, for toggle-transition handling (L8/L13)
        $oldModernDelivery = get_option(WPS_IC_SETTINGS)['modern_image_delivery'] ?? '0';
        $newModernDelivery = $settings['modern_image_delivery'] ?? '0';

        update_option(WPS_IC_SETTINGS, $settings);

        // Toggle-transition cleanup (fires AFTER option write — L8 + L13 + G13)
        if ($oldModernDelivery !== $newModernDelivery) {
            // Toggle ON: clear all retry-state so previously-failed attachments get a fresh try (L8)
            if ($oldModernDelivery === '0' && $newModernDelivery === '1') {
                global $wpdb;
                // One-shot cleanup — can take 5-10s on sites with 100K+ options, acceptable for admin action
                $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpc_failed_%' OR option_name LIKE '_transient_timeout_wpc_failed_%'");
                $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = '_wpc_optimize_attempts'");
            }
            // Both directions: purge all caches so HTML regenerates with new path (G13). (v7.03.92) a bare
            // do_action reaches only 3rd-party purgers, NOT host Varnish — go through purgeAll so Varnish
            // (full-site) + the fan-out both fire.
            if (class_exists('wps_ic_cache_integrations')) {
                wps_ic_cache_integrations::purgeAll(false, true, false, false, true);
            }
        }

        wp_send_json_success($form);
    }


    public function save_mode()
    {
        $options = get_option(WPS_IC_OPTIONS);
        $form = json_decode(stripslashes($_GET['form']), true);
        if (empty($form['apikey']) || $form['apikey'] !== $options['api_key']) {
            wp_send_json_error('bad-apikey', $form);
        }

        $preset = $form['mode'];
        $cdn = $form['cdn'];
        $options_class = new wps_ic_options();
        $settings = $options_class->get_preset($preset);

        if ($cdn == 'true') {
            $settings['live-cdn'] = '1';
            $settings['serve'] = ['jpg' => '1', 'png' => '1', 'gif' => '1', 'svg' => '1', 'css' => '1', 'js' => '1', 'fonts' => '0'];
            $settings['css'] = 1;
            $settings['js'] = 1;
            $settings['fonts'] = 0;
            $settings['generate_adaptive'] = 1;
            $settings['generate_webp'] = 1;
            // v7.01.87 — ITEM 2 coherence hardening: the preset already ships picture_avif=1, but make the
            // next-gen ceiling coherent independent of preset contents so this path can never de-sync
            // (single Next-Gen control reads ON=avif). Twin of ajax.class.php save_mode_v2.
            $settings['picture_webp'] = 1;
            $settings['picture_avif'] = 1;
            $settings['wpc_nextgen'] = 'auto';
            $settings['retina'] = 1;
        } else {
            $settings['live-cdn'] = '0';
            $settings['serve'] = ['jpg' => '0', 'png' => '0', 'gif' => '0', 'svg' => '0', 'css' => '0', 'js' => '0', 'fonts' => '0'];
            $settings['css'] = 0;
            $settings['js'] = 0;
            $settings['fonts'] = 0;
            $settings['generate_adaptive'] = 0;
            $settings['generate_webp'] = 0;
            $settings['retina'] = 0;
        }


        $wpc_excludes = get_option('wpc-inline');
        $wpc_excludes['inline_js'] = explode(',', "jquery.min,adaptive,jquery-migrate,wp-includes");
        update_option('wpc-inline', $wpc_excludes);

        $wpc_excludes = get_option('wpc-excludes');
        $wpc_excludes['delay_js'] = [];
        update_option('wpc-excludes', $wpc_excludes);


        update_option(WPS_IC_SETTINGS, $settings);
        update_option(WPS_IC_PRESET, $preset);

        // Preload Page
        $cacheLogic = new wps_ic_cache();

        // Remove generateCriticalCSS Options
        delete_option('wps_ic_gen_hp_url');

        if (!class_exists('wps_ic_htaccess')) {
            include_once WPS_IC_DIR . 'classes/htaccess.class.php';
        }

        $htaccess = new wps_ic_htaccess();

        if ($preset == 'safe') {
            // Setup Advanced Caching
            $htaccess->removeHtaccessRules();
            $htaccess->removeAdvancedCache();
            $htaccess->setWPCache(false);
        } else {
            // Setup Advanced Caching
            // Add WP_CACHE to wp-config.php
            $htaccess->setWPCache(true);
            $htaccess->setAdvancedCache();
        }

        // Remove & Purge Cache Files for home directory (that's all pages)
        $cacheLogic::removeHtmlCacheFiles(0);

        // Preload the home page only
        $cacheLogic::preloadPage(0);

        wp_send_json_success();
    }


    public function getSettings()
    {
        $options = get_option(WPS_IC_SETTINGS);
        $options['live-cdn'] = '0';

        if (isset($options['serve'])) {
            foreach ($options['serve'] as $file => $status) {
                if (!empty($options['serve'][$file]) && $options['serve'][$file] == '1') {
                    $options['live-cdn'] = '1';
                    break;
                }
            }
        }

        $excludes = get_option('wpc-excludes');
        $inlines = get_option('wpc-inline');
        $url_excludes = get_option('wpc-url-excludes');
        $mode = get_option(WPS_IC_PRESET);
        $allow_live = get_option('wps_ic_allow_live', false);
        $gps = get_option(WPS_IC_LITE_GPS);
        $tests = get_option(WPS_IC_TESTS);
        $plugin_options = get_option(WPS_IC_OPTIONS);
        $plan_version = $plugin_options['version'] ?? '';
        $fonts_map = get_option(WPS_IC_FONTS_MAP);

        $cf = get_option(WPS_IC_CF) ?: [];
        $cf_cname = get_option(WPS_IC_CF_CNAME) ?: '';
        wp_send_json_success([
            'settings'       => $options,
            'excludes'       => $excludes,
            'inline'         => $inlines,
            'wpc-url-excludes' => $url_excludes,
            'mode'           => $mode,
            'allow_live'     => $allow_live,
            'gps'            => $gps,
            'tests'          => $tests,
            'plan_version'   => $plan_version,
            'fonts_map'      => $fonts_map,
            'cf'             => $cf,
            'cf_cname'       => $cf_cname,
            'site_url'       => site_url(),
            'home_url'       => home_url(),
            'active_plugins' => (function () {
                $all    = get_plugins();
                $active = get_option('active_plugins', []);
                $result = [];
                foreach ($active as $path) {
                    $slug     = explode('/', $path)[0];
                    $result[] = ['slug' => $slug, 'name' => $all[$path]['Name'] ?? $slug, 'path' => $path];
                }
                return $result;
            })(),
            'active_theme'   => [
                'slug' => wp_get_theme()->get_stylesheet(),
                'name' => wp_get_theme()->get('Name'),
                'type' => 'theme',
            ],
            'server_info'    => [
                'php_version'  => phpversion(),
                'wp_version'   => $GLOBALS['wp_version'],
                'max_upload'   => size_format(wp_max_upload_size()),
                'memory_limit' => ini_get('memory_limit'),
            ],
        ]);
    }


    public function scanFonts()
    {
        $form = json_decode(stripslashes($_GET['form'] ?? '{}'), true);

        $url = sanitize_url($form['scanUrl'] ?? '');
        if (empty($url)) {
            wp_send_json_error('no-url');
        }

        $fonts = new wps_ic_fonts();
        $response = $fonts->callAPI($url);
        $found = $fonts->scanForFonts($response);

        $hasGoogleFonts = !empty($found['googleFontsStylesheets']) || !empty($found['gstaticUrls']);
        if ($hasGoogleFonts) {
            $fonts->readGoogleStylesheet($found);
        }

        wp_send_json_success(['found' => $hasGoogleFonts]);
    }


    public function removeFont()
    {
        $form = json_decode(stripslashes($_GET['form'] ?? '{}'), true);

        $fontId = sanitize_text_field($form['fontId'] ?? '');
        if (empty($fontId)) {
            wp_send_json_error('no-font-id');
        }

        $font = new wps_ic_fonts();
        $font->removeFont($fontId);

        wp_send_json_success();
    }


    public function purgeFontCache()
    {
        delete_option(WPS_IC_FONTS_MAP);

        $form = json_decode(stripslashes($_GET['form'] ?? '{}'), true);
        $scanUrl = !empty($form['scanUrl']) ? sanitize_url($form['scanUrl']) : site_url();

        $fonts = new wps_ic_fonts();
        $response = $fonts->callAPI($scanUrl);
        $found = $fonts->scanForFonts($response);

        $hasGoogleFonts = !empty($found['googleFontsStylesheets']) || !empty($found['gstaticUrls']);
        if ($hasGoogleFonts) {
            $fonts->readGoogleStylesheet($found);
        }

        wp_send_json_success(['found' => $hasGoogleFonts]);
    }


    public function deactivatePlugin()
    {
        $options = get_option(WPS_IC_OPTIONS);
        $options['api_key'] = '';
        $options['response_key'] = '';
        $options['orp'] = '';
        $options['regExUrl'] = '';
        $options['regexpDirectories'] = '';
        update_option(WPS_IC_OPTIONS, $options);
        wp_send_json_success($options);
    }

    public function start_comms()
    {
        global $wps_ic;

        ini_set('display_errors', 0);
        error_reporting(0);

        $apikey = '';
        $action = '';

        if (!empty($_POST['apikey'])) {
            $apikey = sanitize_text_field($_POST['apikey']);
        }

        if (!empty($_POST['comms_action'])) {
            $action = sanitize_text_field($_POST['comms_action']);
        }

        if (empty($apikey) || empty($action)) {
            $apikey = sanitize_text_field($_GET['apikey']);
            $action = sanitize_text_field($_GET['comms_action']);
        }

        if (!empty($apikey) && !empty($action)) {
            $options = get_option(WPS_IC_OPTIONS);
            if (empty($options)) {
                wp_send_json_error('Hacking?');
            }

            if ($apikey != $options['api_key']) {
                wp_send_json_error('Hacking?');
            }

            if (!method_exists($this, $action)) {
                wp_send_json_error('Function does not exist');
            }

            self::$action();

        }
        wp_send_json_error('#155');
    }


    public function reEnableCDN()
    {
        $settings = get_option(WPS_IC_SETTINGS . '_tmp');
        if (!$settings || empty($settings)) {
            wp_send_json_success();
        }

        delete_option(WPS_IC_SETTINGS . '_tmp');
        update_option(WPS_IC_SETTINGS, $settings);
        wp_send_json_success($settings);
    }


    public function disableCDN()
    {
        $settings = get_option(WPS_IC_SETTINGS);
        $tmpSettings = get_option(WPS_IC_SETTINGS . '_tmp');
        if (empty($tmpSettings)) {
            update_option(WPS_IC_SETTINGS . '_tmp', $settings);
        }

        foreach ($settings['serve'] as $key => $value) {
            $settings['serve'][$key] = 0;
        }

        $settings['css'] = 0;
        $settings['js'] = 0;
        update_option(WPS_IC_SETTINGS, $settings);
        wp_send_json_success('done disablecdn');
    }


    public function cnameAdd()
    {
        $cname_input = !empty($_GET['cname']) ? $_GET['cname'] : null;

        $cname_class = new wps_ic_cname();
        $cname_class->add($cname_input);
    }

    public function cnameRetry()
    {
        $cname_class = new wps_ic_cname();
        $cname_class->retry();
    }

    public function cnameRemove()
    {
        $cname_class = new wps_ic_cname();
        $cname_class->remove();
    }

    public function settingsCheck()
    {
        $options = get_option(WPS_IC_OPTIONS);

        if (empty($options) || empty($options['api_key'])) {
            return;
        }

        $url = 'https://apiv3.wpcompress.com/api/site/credits';
        $call = wp_remote_get($url, ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $options['api_key'],]]);

        if (is_wp_error($call)) {
            return;
        }

        $body = wp_remote_retrieve_body($call);
        $response_code = wp_remote_retrieve_response_code($call);

        if ($response_code !== 200) {
            return;
        }

        $data = json_decode($body);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return;
        }

        $allow_local = true;
        $allow_live = true;

        if (!empty($data->suspended) && $data->suspended == 1) {
            $allow_local = false;
            $allow_live = false;
        }

        $updated_local = update_option('wps_ic_allow_local', $allow_local);
        $updated_live = update_option('wps_ic_allow_live', $allow_live);

        if ($updated_local || $updated_live) {
            if (class_exists('wps_ic_cache_integrations')) {
                $cache = new wps_ic_cache_integrations();
                // (v7.03.92) full-site Varnish: suspend/unsuspend toggles optimization site-wide.
                $cache::purgeAll(false, true, false, false, true);
            }
        }
        wp_send_json_success([$updated_local, $updated_live]);
    }


}