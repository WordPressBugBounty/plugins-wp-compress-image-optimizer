<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: classes/mainwp.class.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.24.00
 */

























class wps_ic_mainwp extends wps_ic
{


    public function __construct()
    {
        add_filter('mainwp_child_extra_execution', [__CLASS__, 'mainwp_extra_execution'], 10, 2);
    }


    




    public static function mainwp_extra_execution($information, $post)
    {
        if (!is_array($information)) {
            $information = [];
        }
        if (!is_array($post) || empty($post['wpc_action'])) {
            return $information;
        }

        $options = get_option(WPS_IC_OPTIONS);
        $stored = (is_array($options) && !empty($options['api_key'])) ? (string) $options['api_key'] : '';

        switch ($post['wpc_action']) {
            case 'check':
                $information['wpc'] = ['connected' => $stored !== ''];
                break;

            case 'connect':
                $apikey = isset($post['apikey']) ? sanitize_text_field($post['apikey']) : '';
                if ($apikey === '') {
                    $information['wpc'] = ['success' => false, 'code' => 'empty-key'];
                    break;
                }
                
                if ($stored !== '' && $stored !== $apikey) {
                    $information['wpc'] = ['success' => false, 'code' => 'site-already-connected'];
                    break;
                }

                $connect = new wps_ic_connect();
                $result = $connect->connectWithKey($apikey);

                if (!empty($result['success'])) {
                    $information['wpc'] = [
                        'success' => true,
                        'apikey' => (!empty($result['data']) && !empty($result['data']->apikey)) ? $result['data']->apikey : $apikey,
                    ];
                } else {
                    $information['wpc'] = [
                        'success' => false,
                        'code' => isset($result['code']) ? $result['code'] : 'not-successful',
                        'uri' => isset($result['url']) ? $result['url'] : '',
                    ];
                }
                break;

            default:
                $information['wpc'] = ['success' => false, 'code' => 'unknown-action'];
                break;
        }

        return $information;
    }


}
