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
        $attachments = $wpdb->get_results("SELECT ID FROM " . $wpdb->prefix . "posts WHERE post_type='attachment' AND post_status='inherit' AND post_mime_type='image/jpeg' ORDER BY post_date DESC LIMIT 1");

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

                                $query = $wpdb->prepare("UPDATE " . $wpdb->prefix . "ic_compressed SET restored='1' WHERE attachment_ID='" . $attachments[0]->ID . "'");
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

            $stats = $wpdb->get_results("SELECT COUNT(ID) as count, created, original, compressed, saved FROM " . $wpdb->prefix . "ic_stats WHERE created>='" . date('Y-m-d', $month_start) . "' AND created<='" . date('Y-m-d', $month_end) . "' GROUP BY attachment_ID ORDER BY created DESC");
        } else if ($range == 'last_month') {

            $month_start = strtotime('first day of last month', time());
            $month_end = strtotime('last day of last month', time());

            $stats = $wpdb->get_results("SELECT COUNT(ID) as count, created, original, compressed, saved FROM " . $wpdb->prefix . "ic_stats WHERE created>='" . date('Y-m-d', $month_start) . "' AND created<='" . date('Y-m-d', $month_end) . "' GROUP BY attachment_ID ORDER BY created DESC");

        } else if ($range == 'get_month') {

            $month = sanitize_text_field($_GET['month']);
            $month_start = strtotime('first day of this month', strtotime($month));
            $month_end = strtotime('last day of this month', strtotime($month));

            $stats = $wpdb->get_results("SELECT COUNT(ID) as count, created, original, compressed, saved FROM " . $wpdb->prefix . "ic_stats WHERE created>='" . date('Y-m-d', $month_start) . "' AND created<='" . date('Y-m-d', $month_end) . "' GROUP BY attachment_ID ORDER BY created DESC");

        } else {
            $stats = $wpdb->get_results("SELECT COUNT(ID) as count, created, original, compressed, saved FROM " . $wpdb->prefix . "ic_stats GROUP BY attachment_ID ORDER BY created DESC");
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


    public function restore_all()
    {
        global $wps_ic;
        update_option('ic_remote_action', 'restoring');

        if (!defined('ABSPATH')) {
            /** Set up WordPress environment */
            require_once(dirname(__FILE__) . '/wp-load.php');
        }

        if (!function_exists('update_option')) {
            require_once(ABSPATH . "wp-includes" . '/option.php');
        }

        $wps_ic->ajax->wps_ic_restore_bulk_prep_background_hidden('none');
        $wps_ic->ajax->wps_ic_restore_hidden_bulk();

        wp_remote_get(site_url('?ic_restore_queue_ping=true'), ['timeout' => 10, 'sslverify' => false]);
        wp_send_json_success('scheduled-restore-all');
    }


    public function generateMetaData()
    {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $imageID = sanitize_text_field($_GET['imageID']);

        $originalFilePath = wp_get_original_image_path($imageID);
        $oldMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);
        wp_update_attachment_metadata($imageID, $oldMeta);

        wp_send_json_success();
    }


    public function compressOnUpload()
    {
        global $wpdb;

        $queuedData = $wpdb->get_results("SELECT * FROM " . $wpdb->options . " WHERE option_name LIKE '_transient_wps_ic_queue_%'");
        if (!$queuedData) wp_send_json_error();

        $settings = get_option(WPS_IC_SETTINGS);
        $compress = new wps_local_compress();

        foreach ($queuedData as $transient) {
            $data = maybe_unserialize($transient->option_value);

            if (!empty($data['imageID'])) {
                $imageID = $data['imageID'];
                $status = $data['status'];

                $data = $compress->compress_image($imageID, false, $settings['retina'], $settings['webp'], false, false, 'return');
            }
        }

        wp_send_json_success();
    }


    public function compress_all()
    {
        global $wps_ic;

        if (!defined('ABSPATH')) {
            /** Set up WordPress environment */
            require_once(dirname(__FILE__) . '/wp-load.php');
        }

        if (!function_exists('update_option')) {
            require_once(ABSPATH . "wp-includes" . '/option.php');
        }

        update_option('ic_remote_action', 'compressing');
        $wps_ic->ajax->wps_ic_compress_bulk_prep_hidden_background('none');
        $queue = $wps_ic->queue->get_hidden_compress_bulk_queue();

        $queued_array = [];

        if ($queue) {
            foreach ($queue as $attachment_ID => $attachment) {

                $queued_array[] = $attachment_ID;

                // Mark attachment as compressing
                update_post_meta($attachment_ID, 'wps_ic_in_bulk', 'true');

                // Add the attachment to queue
                $wps_ic->queue->remove_queue($attachment_ID);
                $queue = $wps_ic->queue->add_queue($attachment_ID, 'hidden_compress_bulk');

                if (count($queued_array) >= 3) {
                    $queued_array = [];
                }

            }

            wp_remote_get(site_url('?ic_queue_ping=true'), ['timeout' => 10, 'sslverify' => false]);
        }

        wp_send_json_success('scheduled-compress-all');
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
        $excludedList = explode(' ', $excludedList);

        $form['default_enabled'] = !empty($form['default_enabled']) ? sanitize_text_field($form['default_enabled']) : '0';
        $form['exclude_themes'] = !empty($form['exclude_themes']) ? sanitize_text_field($form['exclude_themes']) : '0';
        $form['exclude_plugins'] = !empty($form['exclude_plugins']) ? sanitize_text_field($form['exclude_plugins']) : '0';
        $form['exclude_wp'] = !empty($form['exclude_wp']) ? sanitize_text_field($form['exclude_wp']) : '0';


        $option[$form['settingName']] = $excludedList;
        $option[$form['settingName'] . '_default_excludes_disabled'] = $form['default_enabled'];
        $option[$form['settingName'] . '_exclude_themes'] = $form['exclude_themes'];
        $option[$form['settingName'] . '_exclude_plugins'] = $form['exclude_plugins'];
        $option[$form['settingName'] . '_exclude_wp'] = $form['exclude_wp'];
        update_option($form['groupName'], $option);

        wp_send_json_success();
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
            wp_send_json_error(['msg' => 'bad-apikey', 'form' => print_r($form, true), 'post' => print_r($_POST, true), 'get' => print_r($_GET, true)]);
        }

        $cdnEnabled = 0;

        foreach ($form['options']['serve'] as $key => $value) {
            if ($value == '1' && ($key != 'css' && $key != 'js')) {
                $cdnEnabled = '1';
                break;
            }
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

        $settings['live-cdn'] = $cdnEnabled;

        update_option(WPS_IC_SETTINGS, $settings);
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
            $settings['serve'] = [
                'jpg' => '1',
                'png' => '1',
                'gif' => '1',
                'svg' => '1',
                'css' => '1',
                'js' => '1',
                'fonts' => '0'
            ];
            $settings['css'] = 1;
            $settings['js'] = 1;
            $settings['fonts'] = 0;
            $settings['generate_adaptive'] = 1;
            $settings['generate_webp'] = 1;
            $settings['retina'] = 1;
        } else {
            $settings['live-cdn'] = '0';
            $settings['serve'] = [
                'jpg' => '0',
                'png' => '0',
                'gif' => '0',
                'svg' => '0',
                'css' => '0',
                'js' => '0',
                'fonts' => '0'
            ];
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

        if ($preset == 'safe') {
            // TODO: MAYBE WP CACHE?!
            // Setup Advanced Caching
            $htaccess = new wps_ic_htaccess();
            $htaccess->removeHtaccessRules();
            $htaccess->removeAdvancedCache();
            $htaccess->setWPCache(false);
        } else {
            // Setup Advanced Caching
            $htaccess = new wps_ic_htaccess();
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
        wp_send_json_success(['settings' => $options, 'excludes' => $excludes, 'inline' => $inlines, 'wpc-url-excludes' => $url_excludes, 'mode' => $mode]);
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
                wp_send_json_error('Function does not existt');
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
        $ajax_class = new wps_ic_ajax();
        $ajax_class->wps_ic_cname_add();
    }

    public function cnameRetry()
    {
        $ajax_class = new wps_ic_ajax();
        $ajax_class->wps_ic_cname_retry();
    }

    public function cnameRemove()
    {
        $ajax_class = new wps_ic_ajax();
        $ajax_class->wps_ic_remove_cname();
    }


}