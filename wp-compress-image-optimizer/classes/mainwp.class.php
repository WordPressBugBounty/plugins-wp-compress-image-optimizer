<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/mainwp.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */


class wps_ic_mainwp extends wps_ic
{


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
            
            
            
            
            
            
            
            
            
            
            if (!current_user_can('manage_wpc_settings')) {
                wp_send_json_error('forbidden');
            }
            
            
            
            if (isset($_GET['_wpcnonce']) && !wp_verify_nonce(sanitize_text_field($_GET['_wpcnonce']), 'wpc_live_connect')) {
                wp_send_json_error('forbidden');
            }

            
            $apikey = sanitize_text_field($_GET['apikey']);

            if (empty($apikey)) die('What are you doing?');

            
            
            $options = get_option(WPS_IC_OPTIONS);
            if (!empty($options['api_key']) && $options['api_key'] !== $apikey) {
                wp_send_json_error('site-already-connected');
            }

            $connect = new wps_ic_connect();
            $result = $connect->connectWithKey($apikey);

            if ($result['success']) {
                wp_send_json_success(['apikey' => $result['data']->apikey]);
            }

            if ($result['code'] == 'site-already-connected' || $result['code'] == 'apikey-in-use') {
                wp_send_json_error($result['code']);
            }

            wp_send_json_error(['code' => $result['code'], 'uri' => $result['url']]);
        }
    }


}
