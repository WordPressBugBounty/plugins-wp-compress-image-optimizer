<?php


/**
 * Class - Multisite
 */
class wps_ic_mu extends wps_ic
{

    public static $slug;
    public $templates;
    private $default_settings;


    public function __construct()
    {
        $options = new wps_ic_options();
        $this->default_settings = $options->getDefault();

        add_action('wp_initialize_site', [$this, 'new_mu_site'], 900);

        $this->add_ajax('mu_connect');
        $this->add_ajax('mu_connect_sites');
        $this->add_ajax('mu_connect_single_site');
        $this->add_ajax('mu_disconnect_single_site');
        $this->add_ajax('mu_get_site_settings');
        $this->add_ajax('mu_save_site_settings');
        $this->add_ajax('mu_reconfigure_sites');
        $this->add_ajax('mu_autoconnect_setting');
        $this->add_ajax('mu_save_default_settings');
        $this->add_ajax('mu_connect_bulk_prepare');

        // Popup Saves
        $this->add_ajax('wps_ic_exclude_list');
        $this->add_ajax('wps_ic_geolocation');
        $this->add_ajax('wps_ic_geolocation_force');
        $this->add_ajax('wps_ic_cname_add');
        $this->add_ajax('wps_ic_remove_cname');
        $this->add_ajax('wps_ic_cname_retry');
    }

    public function add_ajax($hook)
    {
        add_action('wp_ajax_' . $hook, [$this, $hook]);
    }

    public function wps_ic_cname_retry()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        switch_to_blog($_POST['siteID']);
        $cname = get_option('ic_custom_cname');
        $retry_count = get_option('ic_cname_retry_count');

        if (!$retry_count) {
            update_option('ic_cname_retry_count', 1);
        } else {
            update_option('ic_cname_retry_count', $retry_count + 1);
        }

        if ($retry_count >= 3) {
            wp_send_json_error();
        }

        // Wait for SSL?
        sleep(10);

        wp_send_json_success(['image' => 'https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg', 'configured' => 'Connected Domain: <strong>' . $cname . '</strong>']);
    }

    public function mu_autoconnect_setting()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        switch_to_blog(1);
        $mu_settings = get_option(WPS_IC_MU_SETTINGS);

        if (sanitize_text_field($_POST['checked']) == 'true') {
            $autoconnect = '1';
        } else {
            $autoconnect = '0';
        }

        $mu_settings['autoconnect'] = $autoconnect;
        update_option(WPS_IC_MU_SETTINGS, $mu_settings);
        wp_send_json_success();
    }

    public function mu_connect_bulk_prepare()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        switch_to_blog(1);
        parse_str($_POST['settings'], $form_settings);
        $tmp_settings = $form_settings['wp-ic-setting'];
        update_option('wps_ic_mu_tmp_settings', $tmp_settings);

        $sites = html_entity_decode(stripslashes($_POST['sites']));
        $sites = json_decode($sites);

        if (empty($sites)) {
            wp_send_json_error('site-list-empty');
        }

        update_option('wps_ic_mu_site_list', $sites);
        wp_send_json_success($sites[0]);
    }

    public function mu_connect_sites()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $sites = html_entity_decode(stripslashes($_POST['sites']));
        $sites = json_decode($sites);

        if (empty($sites)) {
            wp_send_json_error('site-list-empty');
        }

        // API Token
        switch_to_blog(1);
        $multisiteDefaultSettings = get_option('multisite_default_settings');
        $settings = get_option(WPS_IC_MU_SETTINGS);
        if (empty($settings['token'])) {
            // Error, token does not exist
        }

        $results = [];

        foreach ($sites as $index => $siteID) {
            // Change Active Blog
            switch_to_blog($siteID);

            $siteurl = urlencode(site_url());
            $token = sanitize_text_field($settings['token']);

            // Setup URI
            $uri = WPS_IC_KEYSURL . '?action=connect_mu_single&token=' . $token . '&domain=' . $siteurl . '&hash=' . md5(time()) . '&time_hash=' . time();

            // Verify API Key is our database and user has is confirmed getresponse
            $get = wp_remote_get($uri, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($get) == 200) {
                $body = wp_remote_retrieve_body($get);
                $body = json_decode($body);

                if (!empty($body->data->code) && $body->data->code == 'site-user-different') {
                    // Popup Site Already Connected
                    $reconnect_msg = 'invalid-api-key';
                }

                if ($body->success && $body->data->apikey != '' && $body->data->response_key != '') {
                    $options = get_option(WPS_IC_OPTIONS);

                    $options['api_key'] = $body->data->apikey;
                    $options['response_key'] = $body->data->response_key;
                    update_option(WPS_IC_OPTIONS, $options);

                    // CDN Does exist or we just created it
                    $zone_name = $body->data->zone_name;

                    if (!empty($zone_name)) {
                        update_option('ic_cdn_zone_name', $zone_name);
                    }

                    $site_settings = get_option(WPS_IC_SETTINGS);

                    $settings = array_merge($this->default_settings, $multisiteDefaultSettings);

                    update_option(WPS_IC_SETTINGS, $settings);

                    /**
                     * GeoLocation Fix
                     */
                    if (!is_multisite()) {
                        $siteurl = site_url();
                    } else {
                        $siteurl = network_site_url();
                    }

                    $call = wp_remote_get('https://cdn.zapwp.net/?action=geo_locate&domain=' . urlencode($siteurl), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

                    if (wp_remote_retrieve_response_code($call) == 200) {
                        $body = wp_remote_retrieve_body($call);
                        $body = json_decode($body);

                        if ($body->success) {
                            update_option('wps_ic_geo_locate_v2', $body->data);
                        } else {
                            update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
                        }
                    } else {
                        update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
                    }

                    $results['connected'][] = $siteID;
                } else {
                    $reconnect_msg = 'api-error';
                    $results['failed'][] = $siteID;
                }
            } else {
                $reconnect_msg = 'api-error';
                $results['failed'][] = $siteID;
            }

            // No hash returned - token is not valid
            $results['api_failed'][] = $siteID;
        }

        wp_send_json_success($results);
    }

    public function wps_ic_remove_cname()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        switch_to_blog($_POST['siteID']);
        $cname = get_option('ic_custom_cname');
        $zone_name = get_option('ic_cdn_zone_name');
        $options = get_option(WPS_IC_OPTIONS);
        $apikey = $options['api_key'];

        $url = WPS_IC_KEYSURL . '?action=cdn_removecname&apikey=' . $apikey . '&cname=' . $cname . '&zone_name=' . $zone_name . '&time=' . time() . '&no_cache=' . md5(mt_rand(999, 9999));

        $call = wp_remote_get($url, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
        $call = wp_remote_get(WPS_IC_KEYSURL . '?action=cdn_purge&domain=' . site_url() . '&apikey=' . $options['api_key'], ['timeout' => '10', 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        delete_option('ic_custom_cname');

        $settings = get_option(WPS_IC_SETTINGS);
        $settings['cname'] = '';
        $settings['fonts'] = '';
        update_option(WPS_IC_SETTINGS, $settings);

        // Clear cache.
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
        }

        // Lite Speed
        if (defined('LSCWP_V')) {
            do_action('litespeed_purge_all');
        }

        // HummingBird
        if (defined('WPHB_VERSION')) {
            do_action('wphb_clear_page_cache');
        }

        if (defined('BREEZE_VERSION')) {
            global $wp_filesystem;
            require_once(ABSPATH . 'wp-admin/includes/file.php');

            WP_Filesystem();

            $cache_path = breeze_get_cache_base_path(is_network_admin(), true);
            $wp_filesystem->rmdir(untrailingslashit($cache_path), true);

            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
        }

        wp_send_json_success();
    }

    public function wps_ic_cname_add()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        switch_to_blog($_POST['siteID']);
        $zone_name = get_option('ic_cdn_zone_name');
        $options = get_option(WPS_IC_OPTIONS);
        $apikey = $options['api_key'];

        delete_option('ic_cname_retry_count');

        if (!empty($_POST['cname'])) {
            $error = '';
            $options = get_option(WPS_IC_OPTIONS);
            $apikey = $options['api_key'];

            // TODO is cname valid?
            $cname = sanitize_text_field($_POST['cname']);
            $cname = str_replace(['http://', 'https://'], '', $cname);
            $cname = rtrim($cname, '/');

            if ($zone_name == $cname) {
                $error = 'This domain is invalid, please link a new domain...';
                wp_send_json_error('invalid-domain');
            }

            if (strpos($cname, 'zapwp.com') !== false || strpos($cname, 'zapwp.net') !== false) {
                $error = 'This domain is invalid, please link a new domain...';
                wp_send_json_error('invalid-domain');
            }

            if (empty($error)) {
                if (!preg_match('/^([a-zA-z0-9\_\-]+)\.([a-zA-z0-9\_\-]+)\.([a-zA-z0-9\_\-]+)$/', $cname, $matches) && !preg_match('/^([a-zA-z0-9\_\-]+)\.([a-zA-z0-9\_\-]+)\.([a-zA-z0-9\_\-]+)\.([a-zA-z0-9\_\-]+)$/', $cname, $matches)) {
                    // Subdomain is not valid
                    $error = 'This domain is invalid, please link a new domain...';
                    delete_option('ic_custom_cname');
                    $settings = get_option(WPS_IC_SETTINGS);
                    unset($settings['cname']);
                    update_option(WPS_IC_SETTINGS, $settings);
                    wp_send_json_error('invalid-domain');
                } else {
                    // Verify CNAME DNS
                    $verify_cname_dns = 'https://frankfurt.zapwp.net/?dnsCheck=true&host=' . $cname . '&zoneName=' . $zone_name . '&random=' . microtime(true);

                    $call = wp_remote_get($verify_cname_dns, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
                    if (wp_remote_retrieve_response_code($call) == 200) {
                        $body = wp_remote_retrieve_body($call);
                        $body = json_decode($body, true);
                        $data = $body['data'];
                        $host = $data['host'];
                        $recordsType = $data['records']['type'];
                        $recordsTarget = $data['records']['target'];

                        if ($recordsType == 'CNAME') {
                            if ($recordsTarget == $zone_name) {
                                update_option('ic_custom_cname', sanitize_text_field($cname));

                                $url = WPS_IC_KEYSURL . '?action=cdn_setcname&apikey=' . $apikey . '&cname=' . $cname . '&zone_name=' . $zone_name . '&time=' . time() . '&no_cache=' . md5(mt_rand(999, 9999));

                                $call = wp_remote_get($url, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

                                //v6 call:
                                $url = WPS_IC_KEYSURL . '?action=cdn_setcname_v6&apikey=' . $apikey . '&cname=' . $cname . '&zone_name=' . $zone_name . '&time=' . time() . '&no_cache=' . md5(mt_rand(999, 9999));

                                $call = wp_remote_get($url, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
                                sleep(5);

                                $call = wp_remote_get(WPS_IC_KEYSURL . '?action=cdn_purge&domain=' . site_url() . '&apikey=' . $options['api_key'], ['timeout' => '10', 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);


                                // Wait for SSL?
                                sleep(8);

                                wp_send_json_success(['image' => 'https://' . $cname . '/' . WPS_IC_IMAGES . '/fireworks.svg', 'configured' => 'Connected Domain: <strong>' . $cname . '</strong>']);
                            }
                        }

                        wp_send_json_error('invalid-dns-prop');
                    } else {
                        wp_send_json_error('dns-api-not-working');
                    }
                }
            }

            $custom_cname = get_option('ic_custom_cname');
            if (!$custom_cname) {
                $custom_cname = '';
            }

            wp_send_json_success($custom_cname);
        } else {
            $custom_cname = delete_option('ic_custom_cname');

            wp_send_json_success();
        }
    }

    public function wps_ic_geolocation_force()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        global $wps_ic;

        switch_to_blog($_POST['siteID']);
        $post = $_POST['location'];

        if ($post == 'Automatic') {
            $geolocation = $this->geoLocateAjax();
            wp_send_json_success($geolocation);
        }

        $location_data = ['server' => 'frankfurt.zapwp.net', 'continent' => 'EU', 'continent_name' => 'Europe', 'country' => 'DE', 'country_name' => 'Germany'];

        switch ($post) {
            case 'EU':
                break;
            case 'US':
                $location_data = ['server' => 'nyc.zapwp.net', 'continent' => 'US', 'continent_name' => 'United States', 'country' => 'US', 'country_name' => 'United States'];
                break;
            case 'OC':
                $location_data = ['server' => 'sydney.zapwp.net', 'continent' => 'OC', 'continent_name' => 'Oceania', 'country' => 'AU', 'country_name' => 'Australia'];
                break;
            case 'AS':
                $location_data = ['server' => 'singapore.zapwp.net', 'continent' => 'AS', 'continent_name' => 'Asia', 'country' => 'Singapore', 'country_name' => 'Singapore'];
                break;
        }

        update_option('wpc-ic-force-location', $location_data);
        update_option('wps_ic_geo_locate_v2', $location_data);

        wp_send_json_success($location_data);
    }

    public function wps_ic_geolocation()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        global $wps_ic;
        switch_to_blog($_POST['siteID']);
        $geolocation = $this->geoLocateAjax();
        wp_send_json_success($geolocation);
    }

    public function wps_ic_exclude_list()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        switch_to_blog($_POST['siteID']);
        $excludeList = $_POST['excludeList'];
        $lazyExcludeList = $_POST['lazyExcludeList'];

        if (!empty($excludeList)) {
            $excludeList = rtrim($excludeList, "\n");
            $excludeList = explode("\n", $excludeList);
            update_option('wpc-ic-external-url-exclude', $excludeList);
        } else {
            delete_option('wpc-ic-external-url-exclude');
        }

        if (!empty($lazyExcludeList)) {
            $lazyExcludeList = rtrim($lazyExcludeList, "\n");
            $lazyExcludeList = explode("\n", $lazyExcludeList);
            update_option('wpc-ic-lazy-exclude', $lazyExcludeList);
        } else {
            delete_option('wpc-ic-lazy-exclude');
        }

        wp_send_json_success();
    }

    public function mu_save_default_settings()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        switch_to_blog(1);
        parse_str($_POST['form'], $form_settings);

        $form_settings = $form_settings['options'];

        foreach ($this->default_settings as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (!isset($form_settings[$key][$k])) {
                        $form_settings[$key][$k] = '0';
                    }
                }
            } else {
                if (!isset($form_settings[$key])) {
                    $form_settings[$key] = '0';
                }
            }
        }

        update_option('multisite_default_settings', $form_settings);

        wp_send_json_success();
    }


    public function mu_reconfigure_sites()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        $sites = sanitize_text_field($_POST['sites']);
        $sites = explode(',', $sites);

        if (empty($sites)) {
            wp_send_json_error('empty-site-list');
        }

        parse_str($_POST['settings'], $form_settings);
        $form_settings = $form_settings['options'];

        foreach ($sites as $i => $siteID) {
            // Change Active Blog
            switch_to_blog($siteID);
            $saved_settings = get_option(WPS_IC_SETTINGS);

            foreach ($this->default_settings as $key => $value) {
                if (is_array($value)) {
                    foreach ($value as $k => $v) {
                        if (!isset($form_settings[$key][$k])) {
                            $form_settings[$key][$k] = '0';
                        }
                    }
                } else {
                    if (!isset($form_settings[$key])) {
                        $form_settings[$key] = '0';
                    }
                }
            }

            // Check if Serve is ON and set Live-CDN = ON
            $form_settings['live-cdn'] = 0;
            if (!empty($form_settings['serve'])) {
                foreach ($form_settings['serve'] as $key => $value) {
                    if ($value == '1') {
                        $form_settings['live-cdn'] = 1;
                        break;
                    }
                }
            }

            update_option(WPS_IC_SETTINGS, $form_settings);
        }

        wp_send_json_success();
    }


    public function mu_save_site_settings()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        global $wpc_siteID;
        $output = '';

        $wpc_siteID = $siteID = sanitize_title($_POST['siteID']);

        parse_str($_POST['form'], $form_settings);
        $form_settings = $form_settings['wp-ic-setting'];

        // Change Active Blog
        switch_to_blog($siteID);
        $saved_settings = get_option(WPS_IC_SETTINGS);

        foreach ($saved_settings as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (!isset($form_settings[$key][$k])) {
                        $form_settings[$key][$k] = '0';
                    }
                }
            } else {
                if (!isset($form_settings[$key])) {
                    $form_settings[$key] = '0';
                }
            }
        }

        foreach ($this->default_settings as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $k => $v) {
                    if (!isset($form_settings[$key][$k])) {
                        $form_settings[$key][$k] = '0';
                    }
                }
            } else {
                if (!isset($form_settings[$key])) {
                    $form_settings[$key] = '0';
                }
            }
        }

        update_option(WPS_IC_SETTINGS, $form_settings);

        wp_send_json_success();
    }


    public function mu_get_site_settings()
    {
        global $wpc_siteID;
        $output = '';

        $wpc_siteID = $siteID = sanitize_title($_POST['siteID']);
        ob_start(); // begin collecting output

        if ($this->mu_is_connected($siteID)) {
            include WPS_IC_DIR . 'templates/mu/connected.php';
            #include WPS_IC_DIR . 'templates/mu/site-settings.php';
        } else {
            include WPS_IC_DIR . 'templates/mu/not-connected.php';
        }

        $output .= ob_get_clean(); // retrieve output from myfile.php, stop buffering

        wp_send_json_success($output);
    }

    public function mu_is_connected($siteID)
    {
        switch_to_blog($siteID);
        $options = get_option(WPS_IC_OPTIONS);

        if (!empty($options['api_key']) && !empty($options['response_key'])) {
            return true;
        }

        return false;
    }

    public function new_mu_site(WP_Site $new_site)
    {
        // Setup Database
        $this->mu_autoconnect_site($new_site->blog_id);
        restore_current_blog();
    }

    public function mu_autoconnect_site($siteID)
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        // API Token
        switch_to_blog(1);
        $multisiteDefaultSettings = get_option('multisite_default_settings');
        $mu_settings = get_option(WPS_IC_MU_SETTINGS);

        if (!empty($mu_settings['autoconnect']) && $mu_settings['autoconnect'] == '1') {
            if (empty($mu_settings['token'])) {
                // Error, token does not exist
            }

            $single = true;

            // Change Active Blog
            switch_to_blog($siteID);

            $siteurl = urlencode(site_url());
            $token = sanitize_text_field($mu_settings['token']);

            // Setup URI
            $uri = WPS_IC_KEYSURL . '?action=connect_mu_single&token=' . $token . '&domain=' . $siteurl . '&hash=' . md5(time()) . '&time_hash=' . time();

            // Verify API Key is our database and user has is confirmed getresponse
            $get = wp_remote_get($uri, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($get) == 200) {
                $body = wp_remote_retrieve_body($get);
                $body = json_decode($body);

                if (!empty($body->data->code) && $body->data->code == 'site-user-different') {
                    // Popup Site Already Connected
                    $reconnect_msg = 'invalid-api-key';
                }

                if ($body->success && $body->data->apikey != '' && $body->data->response_key != '') {
                    $options = get_option(WPS_IC_OPTIONS);

                    $options['api_key'] = $body->data->apikey;
                    $options['response_key'] = $body->data->response_key;
                    update_option(WPS_IC_OPTIONS, $options);

                    // CDN Does exist or we just created it
                    $zone_name = $body->data->zone_name;

                    if (!empty($zone_name)) {
                        update_option('ic_cdn_zone_name', $zone_name);
                    }

                    if (empty($multisiteDefaultSettings)) {
                        $settings = $this->default_settings;
                    } else {
                        $settings = $multisiteDefaultSettings;
                    }

                    update_option(WPS_IC_SETTINGS, $settings);

                    global $wpc_siteID;
                    $output = '';

                    $wpc_siteID = $siteID;

                    /**
                     * Autoconnect
                     */
                    if (!is_multisite()) {
                        $siteurl = site_url();
                    } else {
                        $siteurl = network_site_url();
                    }

                    $call = wp_remote_get('https://cdn.zapwp.net/?action=geo_locate&domain=' . urlencode($siteurl), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

                    if (wp_remote_retrieve_response_code($call) == 200) {
                        $body = wp_remote_retrieve_body($call);
                        $body = json_decode($body);

                        if ($body->success) {
                            update_option('wps_ic_geo_locate_v2', $body->data);
                        } else {
                            update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
                        }
                    } else {
                        update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
                    }
                } else {
                    $reconnect_msg = 'api-error';
                }
            } else {
                $reconnect_msg = 'api-error';
            }
        } else {
            // Do nothing
        }
    }

    public function get_agency_stats()
    {
        // API Token
        $settings = get_option(WPS_IC_MU_SETTINGS);
        if (empty($settings['token'])) {
            // Error, token does not exist
        }

        $siteID = sanitize_text_field($_POST['siteID']);

        // Change Active Blog
        switch_to_blog($siteID);

        $siteurl = urlencode(site_url());
        $token = sanitize_text_field($settings['token']);

        $get = wp_remote_get('https://app.wpcompress.com/?token=' . $token . '&action=details', ['timeout' => 10]);
    }

    public function mu_disconnect_single_site()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        // API Token
        $settings = get_option(WPS_IC_MU_SETTINGS);
        if (empty($settings['token'])) {
            // Error, token does not exist
        }

        $siteID = sanitize_text_field($_POST['siteID']);

        // Change Active Blog
        switch_to_blog($siteID);

        $options = get_option(WPS_IC_OPTIONS);
        $siteurl = urlencode(site_url());

        // Setup URI
        $uri = WPS_IC_KEYSURL . '?action=disconnect&apikey=' . $options['api_key'] . '&domain=' . $siteurl . '&hash=' . md5(time()) . '&time_hash=' . time();

        // Remove Settings
        $options = get_option(WPS_IC_OPTIONS);

        $options['api_key'] = '';
        $options['response_key'] = '';
        update_option(WPS_IC_OPTIONS, $options);

        // Verify API Key is our database and user has is confirmed getresponse
        $get = wp_remote_get($uri, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        wp_send_json_success(['html_status' => '<a href="#" class="wps-ic-mu-connect wpc-mu-individual-connect-bulk hvr-grow" data-site-id="' . $siteID . '"><i class="icon icon-link"></i> Connect</a>']);

        // TODO: Remove?
        if (wp_remote_retrieve_response_code($get) == 200) {
            wp_send_json_success();
        } else {
            wp_send_json_success();
        }

        // No hash returned - token is not valid
        wp_send_json_success();
    }

    public function mu_connect_single_site()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        // API Token
        switch_to_blog(1);
        $multisiteDefaultSettings = get_option('multisite_default_settings');
        $settings = get_option(WPS_IC_MU_SETTINGS);
        $siteList = get_option('wps_ic_mu_site_list');

        if (empty($settings['token'])) {
            // Error, token does not exist
            wp_send_json_error('token-invalid');
        }

        #$initialSettings = $this->setupSettings();
        $initialSettings = $multisiteDefaultSettings;

        $bulk = false;
        if (isset($_POST['bulk']) && $_POST['bulk'] == 'true') {
            $bulk = true;
            unset($siteList[0]);
            $siteList = array_values($siteList);
            update_option('wps_ic_mu_site_list', $siteList);
            $tmp_settings = get_option('wps_ic_mu_tmp_settings');
        }

        $single = false;
        if (isset($_POST['single']) && $_POST['single'] == 'true') {
            $single = true;
        }

        $siteID = sanitize_text_field($_POST['siteID']);

        // Change Active Blog
        switch_to_blog($siteID);

        $siteurl = urlencode(site_url());
        $token = sanitize_text_field($settings['token']);

        // Setup URI
        $uri = WPS_IC_KEYSURL . '?action=connect_mu_single&token=' . $token . '&domain=' . $siteurl . '&hash=' . md5(time()) . '&time_hash=' . time();


        // Verify API Key is our database and user has is confirmed getresponse
        $get = wp_remote_get($uri, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);


        $body_msg = '';

        if (wp_remote_retrieve_response_code($get) == 200) {
            $body_msg = wp_remote_retrieve_body($get);
            $body = json_decode($body_msg);

            if (!empty($body->data->code) && $body->data->code == 'site-user-different') {
                // Popup Site Already Connected
                $reconnect_msg = 'invalid-api-key';
            }

            if ($body->success && $body->data->apikey != '' && $body->data->response_key != '') {
                $options = get_option(WPS_IC_OPTIONS);

                $options['api_key'] = $body->data->apikey;
                $options['response_key'] = $body->data->response_key;
                update_option(WPS_IC_OPTIONS, $options);

                // CDN Does exist or we just created it
                $zone_name = $body->data->zone_name;

                if (!empty($zone_name)) {
                    update_option('ic_cdn_zone_name', $zone_name);
                }

                if ($bulk) {
                    $settings = $tmp_settings;
                } else {
                    $settings = $initialSettings;
                }

                update_option(WPS_IC_SETTINGS, $settings);


                global $wpc_siteID;
                $output = '';

                $wpc_siteID = $siteID = sanitize_title($_POST['siteID']);
                ob_start(); // begin collecting output

                if ($this->mu_is_connected($siteID)) {
                    if (!is_multisite()) {
                        $siteurl = site_url();
                    } else {
                        $siteurl = network_site_url();
                    }

                    $call = wp_remote_get('https://cdn.zapwp.net/?action=geo_locate&domain=' . urlencode($siteurl), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

                    if (wp_remote_retrieve_response_code($call) == 200) {
                        $body = wp_remote_retrieve_body($call);
                        $body = json_decode($body);

                        if ($body->success) {
                            update_option('wps_ic_geo_locate_v2', $body->data);
                        } else {
                            update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
                        }
                    } else {
                        update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
                    }

                    include WPS_IC_DIR . 'templates/mu/connected.php';
                    include WPS_IC_DIR . 'templates/mu/site-settings.php';
                } else {
                    include WPS_IC_DIR . 'templates/mu/not-connected.php';
                }

                $output .= ob_get_clean(); // retrieve output from myfile.php, stop buffering

                if ($bulk) {
                    if (empty($siteList)) {
                        wp_send_json_success('done');
                    }
                    wp_send_json_success($siteList[0]);
                }

                if ($single) {
                    #wp_send_json_success(array('html_status' => '<a href="#" class="wps-ic-mu-configure" data-site-id="' . $siteID . '">Configure</a><a href="#" class="wps-ic-mu-disconnect wpc-mu-individual-disconnect-bulk" data-site-id="' . $siteID . '">Disconnect</a>'));
                    wp_send_json_success(['html_status' => '<a href="#" class="wps-ic-mu-configure ic-tooltip" title="Configure" data-site-id="' . $siteID . '"><i class="icon icon-cog"></i></a><a href="#" class="wps-ic-mu-disconnect wpc-mu-individual-disconnect-bulk ic-tooltip" title="Disconnect" data-site-id="' . $siteID . '"><i class="icon icon-cancel"></i></a>']
                    );
                }

                wp_send_json_success($output);
            } else {
                $reconnect_msg = 'api-error';
                wp_send_json_error(['msg' => $reconnect_msg, 'body' => $body_msg]);
            }
        } else {
            $reconnect_msg = 'api-error';
            wp_send_json_error(['msg' => $reconnect_msg, 'body' => $body_msg]);
        }

        // No hash returned - token is not valid
        wp_send_json_error('unkown');
    }

    public function setupSettings()
    {
        $options = new wps_ic_options();
        $defaultSettings = $options->getDefault();

        if (empty($settings) || !is_array($settings)) {
            $settings = [];
        }

        foreach ($defaultSettings as $option_key => $option_value) {
            if (is_array($option_value)) {
                foreach ($option_value as $option_value_k => $option_value_v) {
                    if (empty($settings[$option_key][$option_value_k])) {
                        if (!isset($settings[$option_key])) {
                            $settings[$option_key] = [];
                        }
                        $settings[$option_key][$option_value_k] = '0';
                    }
                }
            } else {
                if (empty($settings[$option_key])) {
                    $settings[$option_key] = '0';
                }
            }
        }

        return $settings;
    }

    public function mu_connect()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['wps_ic_nonce'], 'wps_ic_nonce_action')) {
            wp_send_json_error('Forbidden.');
        }

        // https://app.wpcompress.com/?token=apitokentest
        $token = sanitize_text_field($_POST['token']);

        $site_url = urlencode(network_site_url());

        $get = wp_remote_get('https://app.wpcompress.com/?token=' . $token . '&site_url=' . $site_url, ['timeout' => 10]);

        if (wp_remote_retrieve_response_code($get) == 200) {
            $body = wp_remote_retrieve_body($get);
            $body = json_decode($body);

            if (!empty($body->data)) {
                $response = $body->data;
                switch ($response) {
                    case 'bad-token':
                    case 'verification-failed-01':
                    case 'verification-failed-02':
                        wp_send_json_error('verification-failed');
                        break;
                    case 'different-account':
                        wp_send_json_error('different-account');
                        break;
                    default:
                        // Hash is returned, token is valid!
                        $settings = get_option(WPS_IC_MU_SETTINGS);
                        $settings['token'] = $token;
                        update_option(WPS_IC_MU_SETTINGS, $settings);

                        wp_send_json_success();
                        break;
                }
            }
        }

        // No hash returned - token is not valid
        wp_send_json_error();
    }


}