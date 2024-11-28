<?php


class wps_ic_connect extends wps_ic
{


    public static $Requests;
    public static $options;

    public function __construct()
    {
        self::$Requests = new wps_ic_requests();
        self::$options = new wps_ic_options();
    }


    public function connectLite($return = false)
    {
        if (!current_user_can('manage_options')) {
            if ($return) {
                return false;
            } else {
                wp_send_json_error('Forbidden.');
            }
        }

        // API Key
        $siteurl = urlencode(site_url());
        delete_option('wpsShowAdvanced');

        // Required for DEBUG?
        $uri = WPS_IC_KEYSURL . '?action=connectLite&domain=' . $siteurl . '&plugin_version=' . self::$version . '&hash=' . md5(time()) . '&time_hash=' . time();

        // Verify API Key is our database and user has is confirmed getresponse
        $call = self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'connectLite', 'domain' => $siteurl, 'plugin_version' => self::$version, 'hash' => md5(time()), 'time_hash' => time()], ['timeout' => 60, 'sslverify' => true]);

        if (!empty($call)) {
            if ($call->success && $call->data->apikey != '') {
                $options = new wps_ic_options();
                $options->set_option('api_key', $call->data->apikey);
                $options->set_option('version', 'lite');

                update_option('ic_cdn_zone_name', '');

                $settings = get_option(WPS_IC_SETTINGS);
                $sizes = get_intermediate_image_sizes();

                if (!empty($sizes)) {
                    foreach ($sizes as $key => $value) {
                        $settings['thumbnails'][$value] = 1;
                    }
                }

                $default_Settings = self::$options->get_preset('lite');

                update_option(WPS_IC_SETTINGS, $default_Settings);
                update_option(WPS_IC_GUI, 'lite');
                update_option('wpsShowAdvanced', 'true');
                delete_option('wps_ic_allow_live');

                if ($return) {
                    return ['connected' => true, 'liveMode' => $call->data->liveMode, 'localMode' => $call->data->localMode];
                } else {
                    wp_send_json_success(['liveMode' => $call->data->liveMode, 'localMode' => $call->data->localMode]);
                }

            } else {
                // Call Failed
                if ($return) {
                    return 'call-failed';
                } else {
                    wp_send_json_error(['msg' => 'api-issue', 'url' => $uri]);
                }
            }

        } else {
            if ($return) {
                return 'call-failed';
            } else {
                wp_send_json_error(['msg' => 'api-issue', 'url' => $uri]);
            }
        }

    }


    public function connect()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'wpc_live_connect')) {
            wp_send_json_error('Forbidden.');
        }

        // API Key
        $apikey = sanitize_text_field($_POST['apikey']);
        $siteurl = urlencode(site_url());

        // Remove showAdvanced
        delete_option('wpsShowAdvanced');

        // Required for DEBUG?
        $uri = WPS_IC_KEYSURL . '?action=connectV6&apikey=' . $apikey . '&domain=' . $siteurl . '&plugin_version=' . self::$version . '&hash=' . md5(time()) . '&time_hash=' . time();

        // Verify API Key is our database and user has is confirmed getresponse
        $call = self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'connectV6', 'apikey' => $apikey, 'domain' => $siteurl, 'plugin_version' => self::$version, 'hash' => md5(time()), 'time_hash' => time()], ['timeout' => 60]);

        if (!empty($call)) {
            if (!empty($call->data->code)) {
                if ($call->data->code == 'site-user-different') {
                    // Popup Site Already Connected
                    wp_send_json_error(['msg' => 'site-already-connected', 'url' => $uri]);
                } elseif ($call->data->code == 'site-already-connected') {
                    // Popup Site Already Connected
                    wp_send_json_error(['msg' => 'site-already-connected', 'url' => $uri]);
                }
            }


            if ($call->success && $call->data->apikey != '' && $call->data->response_key != '') {
                $options = new wps_ic_options();
                $options->set_option('api_key', $call->data->apikey);
                $options->set_option('response_key', $call->data->response_key);
                $options->set_option('version', 'pro');

                update_option(WPS_IC_GUI, 'lite');
                update_option('wpsShowAdvanced', 'true');

                // CDN Does exist or we just created it
                $zone_name = $call->data->zone_name;

                if (!empty($zone_name)) {
                    update_option('ic_cdn_zone_name', $zone_name);
                }

                $settings = get_option(WPS_IC_SETTINGS);
                $sizes = get_intermediate_image_sizes();
                if ($sizes) {
                    foreach ($sizes as $key => $value) {
                        $settings['thumbnails'][$value] = 1;
                    }
                }

                $default_Settings = ['js' => '1', 'css' => '1', 'css_image_urls' => '0', 'external-url' => '0', 'replace-all-link' => '0', 'emoji-remove' => '0', 'disable-oembeds' => '0', 'disable-gutenber' => '0', 'disable-dashicons' => '0', 'on-upload' => '0', 'defer-js' => '0', 'serve' => ['jpg' => '1', 'png' => '1', 'gif' => '1', 'svg' => '1'], 'search-through' => 'html', 'preserve-exif' => '0', 'minify-css' => '0', 'minify-js' => '0'];

                $settings = array_merge($settings, $default_Settings);

                $settings['live-cdn'] = '1';
                update_option(WPS_IC_SETTINGS, $settings);

                // TODO: Setup the Cache Options, if cache is active

                $cache = new wps_ic_cache_integrations();
                $cache::purgeAll();

                wp_send_json_success(['liveMode' => $call->data->liveMode, 'localMode' => $call->data->localMode]);
            }

            wp_send_json_error(['msg' => 'api-issue', 'url' => $uri]);
        }

        wp_send_json_error(['msg' => 'api-issue', 'url' => $uri]);
    }


}