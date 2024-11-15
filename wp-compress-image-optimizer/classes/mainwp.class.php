<?php

class wps_ic_mainwp extends wps_ic
{
    public static $version = '6.21.16';


    public function __construct()
    {
        add_action('send_headers', [__CLASS__, 'admin_init_mainwp']);
        add_action('send_headers', [__CLASS__, 'check_mainwp']);
    }


    public static function check_mainwp()
    {
        if (!empty($_GET['check_mainwp'])) {
            $options = get_option(WPS_IC_OPTIONS);
            if (!empty($options['api_key']) && $options['api_key'] != '') {
                wp_send_json_success();
            } else {
                wp_send_json_error('#21');
            }
        }
    }


    public static function admin_init_mainwp()
    {
        if (!empty($_GET['force_ic_connect']) && !empty($_GET['apikey'])) {
            // API Key
            $apikey = sanitize_text_field($_GET['apikey']);
            $siteurl = urlencode(site_url());

            // if empty apikey
            if (empty($apikey)) die('What are you doing?');

            // Setup URI
            $uri = WPS_IC_KEYSURL . '?action=connectV6&apikey=' . $apikey . '&domain=' . $siteurl . '&plugin_version=' . self::$version . '&hash=' . md5(time()) . '&time_hash=' . time();

            // Verify API Key is our database and user has is confirmed getresponse
            $get = wp_remote_get($uri, ['timeout' => 45, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($get) == 200) {
                $body = wp_remote_retrieve_body($get);
                $body = json_decode($body);

                if (!empty($body->data->code) && $body->data->code == 'site-user-different') {
                    // Popup Site Already Connected
                    wp_send_json_error('site-already-connected');
                }

                if ($body->success && $body->data->apikey != '' && $body->data->response_key != '') {
                    $options = new wps_ic_options();
                    $options->set_option('api_key', $body->data->apikey);
                    $options->set_option('response_key', $body->data->response_key);

                    // CDN Does exist or we just created it
                    $zone_name = $body->data->zone_name;

                    if (!empty($zone_name)) {
                        update_option('ic_cdn_zone_name', $zone_name);
                    }

                    $configuration = $options->get_preset('recommended');
                    #var_dump(print_r($configuration,true));

                    update_option(WPS_IC_SETTINGS, $configuration);
                    update_option(WPS_IC_PRESET, 'recommended');
                    delete_transient('wps_ic_account_status');

//
//                    $settings = get_option(WPS_IC_SETTINGS);
//                    $sizes = get_intermediate_image_sizes();
//                    foreach ($sizes as $key => $value) {
//                        $settings['thumbnails'][$value] = 1;
//                    }
//
//                    $default_Settings = ['js' => '1', 'css' => '0', 'css_image_urls' => '0', 'external-url' => '0', 'replace-all-link' => '0', 'emoji-remove' => '0', 'disable-oembeds' => '0', 'disable-gutenber' => '0', 'disable-dashicons' => '0', 'on-upload' => '0', 'defer-js' => '0', 'serve' => ['jpg' => '1', 'png' => '1', 'gif' => '1', 'svg' => '1'], 'search-through' => 'html', 'preserve-exif' => '0', 'minify-css' => '0', 'minify-js' => '0'];
//
//                    $settings = array_merge($settings, $default_Settings);
//
//                    $settings['live-cdn'] = '1';
//                    update_option(WPS_IC_SETTINGS, $settings);
                    wp_send_json_success();
                }

                wp_send_json_error(['uri' => $uri, 'body' => wp_remote_retrieve_body($get), 'code' => wp_remote_retrieve_response_code($get), 'get' => $get]);
            } else {
                wp_send_json_error(['Cannot Call API', $uri, wp_remote_retrieve_body($get), wp_remote_retrieve_response_code($get), wp_remote_retrieve_response_message($get)]);
            }

            wp_send_json_error('0');
        }
    }


}