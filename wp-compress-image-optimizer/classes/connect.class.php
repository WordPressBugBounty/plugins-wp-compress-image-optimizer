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
        if (!current_user_can('manage_wpc_settings')) {
            if ($return) {
                return false;
            } else {
                wp_send_json_error('Forbidden.');
            }
        }

        
        $siteurl = urlencode(site_url());
        delete_option('wpsShowAdvanced');

        
        $uri = WPS_IC_KEYSURL . '?action=connectLite&domain=' . $siteurl . '&plugin_version=' . self::$version . '&hash=' . md5(time()) . '&time_hash=' . time();

        
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
                $settings = array_merge($default_Settings, $settings);

                update_option(WPS_IC_SETTINGS, $settings);
                update_option(WPS_IC_GUI, 'lite');
                update_option('wpsShowAdvanced', 'true');
                delete_option('wps_ic_allow_live');


                
                if (function_exists('wpc_apply_link_preset')) {
                    wpc_apply_link_preset('connect-lite');
                }

                if ($return) {
                    return ['connected' => true, 'liveMode' => $call->data->liveMode, 'localMode' => $call->data->localMode];
                } else {
                    wp_send_json_success(['liveMode' => $call->data->liveMode, 'localMode' => $call->data->localMode]);
                }

            } else {
                
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


    






    public function connectWithKey($apikey)
    {
        $siteurl = urlencode(site_url());

        
        delete_option('wpsShowAdvanced');

        
        $uri = WPS_IC_KEYSURL . '?action=connectV6&apikey=' . $apikey . '&domain=' . $siteurl . '&plugin_version=' . self::$version . '&hash=' . md5(time()) . '&time_hash=' . time();

        
        $call = self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'connectV6', 'apikey' => $apikey, 'domain' => $siteurl, 'plugin_version' => self::$version, 'hash' => md5(time()), 'time_hash' => time()], ['timeout' => 60]);

        if (empty($call)) {
            return ['success' => false, 'code' => 'call-empty', 'url' => $uri, 'data' => null];
        }

        if (!empty($call->data->code)) {
            if ($call->data->code == 'site-user-different' || $call->data->code == 'site-already-connected') {
                
                return ['success' => false, 'code' => 'site-already-connected', 'url' => $uri, 'data' => $call->data];
            } elseif ($call->data->code == 'apikey-in-use') {
                return ['success' => false, 'code' => 'apikey-in-use', 'url' => $uri, 'data' => $call->data];
            }
        }

        if ($call->success && $call->data->apikey !== '' && $call->data->response_key !== '') {
            $options = new wps_ic_options();
            $options->set_option('api_key', $call->data->apikey);
            $options->set_option('response_key', $call->data->response_key);
            $options->set_option('version', 'pro');

            update_option(WPS_IC_GUI, 'lite');
            update_option('wpsShowAdvanced', 'true');


            $zone_name = $call->data->zone_name;

            if (!empty($zone_name)) {
                update_option('ic_cdn_zone_name', $zone_name);
            }


            if (isset($call->data->zone_id) && ctype_digit((string) $call->data->zone_id)) {
                update_option('wpc_v2_zone_id', (string) $call->data->zone_id, false);
            }

            $settings = get_option(WPS_IC_SETTINGS);

            
            
            
            
            
            if (empty($settings)) {
                $sizes = get_intermediate_image_sizes();
                if ($sizes) {
                    foreach ($sizes as $key => $value) {
                        $settings['thumbnails'][$value] = 1;
                    }
                }


                $default_Settings = self::$options->get_preset('aggressive');
                $settings = array_merge($default_Settings, $settings);

                $settings['live-cdn'] = '1';
                update_option(WPS_IC_SETTINGS, $settings);
            }


            
            if (function_exists('wpc_apply_link_preset')) {
                wpc_apply_link_preset('connect');
            }

            

            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll();
            delete_option('wps_ic_url_changed');


            delete_option('wpc_v2_provisioned_fingerprint');
            update_option('wpc_v2_force_provision', 1, false);

            delete_transient('wps_ic_account_status');

            return ['success' => true, 'code' => 'connected', 'url' => $uri, 'data' => $call->data];
        }

        return ['success' => false, 'code' => 'not-successful', 'url' => $uri, 'data' => isset($call->data) ? $call->data : null];
    }


    public function connect()
    {
        ini_set('max_execution_time', '120');

        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_POST['nonce'], 'wpc_live_connect')) {
            wp_send_json_error('Forbidden.');
        }

        
        $apikey = sanitize_text_field($_POST['apikey']);
        $siteurl = urlencode(site_url());

        
        $uri = WPS_IC_KEYSURL . '?action=connectV6&apikey=' . $apikey . '&domain=' . $siteurl . '&plugin_version=' . self::$version . '&hash=' . md5(time()) . '&time_hash=' . time();

        
        if (!empty($apikey)) {
            if ($apikey == '1') {
                wp_send_json_error(['msg' => 'api-issue', 'code' => 'call-empty', 'url' => $uri]);
            } else if ($apikey == '2') {
                wp_send_json_error(['msg' => 'api-issue', 'code' => 'not-successful', 'url' => $uri]);
            } else if ($apikey == '3') {
                wp_send_json_error(['msg' => 'apikey-in-use', 'url' => $uri]);
            } else if ($apikey == '4') {
                wp_send_json_error(['msg' => 'site-already-connected', 'url' => $uri]);
            }
        }

        $result = $this->connectWithKey($apikey);

        if ($result['success']) {
            wp_send_json_success(['liveMode' => $result['data']->liveMode, 'localMode' => $result['data']->localMode]);
        }

        if ($result['code'] == 'site-already-connected' || $result['code'] == 'apikey-in-use') {
            wp_send_json_error(['msg' => $result['code'], 'url' => $result['url']]);
        }

        wp_send_json_error(['msg' => 'api-issue', 'code' => $result['code'], 'url' => $result['url']]);
    }


}