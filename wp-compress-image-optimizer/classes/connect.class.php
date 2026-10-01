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
                if (function_exists('wpc_drop_preset_advanced_cache_if_foreign')) {
                    $default_Settings = wpc_drop_preset_advanced_cache_if_foreign($default_Settings);
                }
                $settings = array_merge($default_Settings, $settings);

                update_option(WPS_IC_SETTINGS, $settings);
                update_option(WPS_IC_GUI, 'lite');
                update_option('wpsShowAdvanced', 'true');
                delete_option('wps_ic_allow_live');


                // Non-destructive (existing keys win); lever 4 gated on no foreign page cache.
                if (function_exists('wpc_apply_link_preset')) {
                    wpc_apply_link_preset('connect-lite');
                }

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


    /**
     * Shared connect routine used by the standard AJAX connect and the
     * MainWP force-connect endpoint. Runs the connectV6 handshake and the
     * full post-connect site setup. Callers handle auth and the JSON reply.
     *
     * @return array ['success' => bool, 'code' => string, 'url' => string, 'data' => object|null]
     */
    public function connectWithKey($apikey)
    {
        $siteurl = urlencode(site_url());
        // The site's Lite key rides along so a connect token can keep it (and its history).
        $wpc_prev = get_option(WPS_IC_OPTIONS);
        $wpc_lite_apikey = (is_array($wpc_prev) && !empty($wpc_prev['api_key']) && isset($wpc_prev['version']) && $wpc_prev['version'] === 'lite') ? (string) $wpc_prev['api_key'] : '';
        $wpc_prev_features = class_exists('wps_ic_plan') ? wps_ic_plan::features() : null;

        // Remove showAdvanced
        delete_option('wpsShowAdvanced');

        // Required for DEBUG?
        $uri = WPS_IC_KEYSURL . '?action=connectV6&apikey=' . $apikey . '&domain=' . $siteurl . '&plugin_version=' . self::$version . '&hash=' . md5(time()) . '&time_hash=' . time();

        // Verify API Key is our database and user has is confirmed getresponse
        $call = self::$Requests->GET(WPS_IC_KEYSURL, ['action' => 'connectV6', 'apikey' => $apikey, 'lite_apikey' => $wpc_lite_apikey, 'domain' => $siteurl, 'plugin_version' => self::$version, 'hash' => md5(time()), 'time_hash' => time()], ['timeout' => 60]);

        if (empty($call)) {
            return ['success' => false, 'code' => 'call-empty', 'url' => $uri, 'data' => null];
        }

        if (!empty($call->data->code)) {
            if ($call->data->code == 'site-user-different' || $call->data->code == 'site-already-connected') {
                // Popup Site Already Connected
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

            // Onboarding defaults (aggressive preset + live-cdn on) apply ONLY to a genuinely
            // fresh connect. A RECONNECT (the site is already configured) must preserve the user's
            // choices — never re-apply the preset or force-enable the CDN. The old
            // `count($settings) >= 3` ran on every configured site, so a reconnect reset settings
            // + re-activated the CDN (the mass-reconnect after the apiv3 event exposed it).
            //
            // "Configured" is tested on the settings this code writes, not on the row being empty:
            // the row is already non-empty before the user ever connects, because the fresh-install
            // default writes wpc_optimization_mode into it on the first admin load. An empty-row
            // test therefore skipped the whole block on every fresh install, leaving js,
            // background-sizing, css, fonts, serve.*, retina, adaptive, webp and live-cdn all unset
            // — a connected site running on nothing, while the dropdown claimed Aggressive Mode.
            $settings = is_array($settings) ? $settings : [];
            $alreadyConfigured = isset($settings['live-cdn']) || isset($settings['serve']);

            if (!$alreadyConfigured) {
                $sizes = get_intermediate_image_sizes();
                if ($sizes) {
                    foreach ($sizes as $key => $value) {
                        $settings['thumbnails'][$value] = 1;
                    }
                }


                $default_Settings = self::$options->get_preset('aggressive');
                if (function_exists('wpc_drop_preset_advanced_cache_if_foreign')) {
                    $default_Settings = wpc_drop_preset_advanced_cache_if_foreign($default_Settings);
                }
                // Existing keys win, so the fresh-install strategy (and anything else already in
                // the row) survives the preset it is merged with.
                $settings = array_merge($default_Settings, $settings);

                $settings['live-cdn'] = '1';
                update_option(WPS_IC_SETTINGS, $settings);

                // The site now IS on this preset, so say so. Without this the dropdown had no
                // stored value to read and invented one, which is how a site could be labelled
                // with a preset nothing had applied.
                update_option(WPS_IC_PRESET, 'aggressive');
            }


            // Non-destructive (existing keys win); lever 4 gated on no foreign page cache.
            if (function_exists('wpc_apply_link_preset')) {
                wpc_apply_link_preset('connect');
            }

            // TODO: Setup the Cache Options, if cache is active

            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll();
            delete_option('wps_ic_url_changed');


            delete_option('wpc_v2_provisioned_fingerprint');
            update_option('wpc_v2_force_provision', 1, false);

            delete_transient('wps_ic_account_status');

            if (class_exists('wps_ic_plan') && isset($call->data->version)) {
                wps_ic_plan::apply_payload($call->data, 'key', $wpc_prev_features);
            }
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

        // API Key
        $apikey = sanitize_text_field($_POST['apikey']);
        $siteurl = urlencode(site_url());

        // Required for DEBUG?
        $uri = WPS_IC_KEYSURL . '?action=connectV6&apikey=' . $apikey . '&domain=' . $siteurl . '&plugin_version=' . self::$version . '&hash=' . md5(time()) . '&time_hash=' . time();

        // Simulations of failure?
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