<?php

/**
 * MainWP dashboard -> child site bridge.
 *
 * v7.22.71 — NO PUBLIC URL. Until v7.21.29 this class answered two unauthenticated
 * front-end GET query flags (a "is the plugin connected" probe and a "force connect
 * with this key" command) on a public header-time hook. The second one reached
 * connectWithKey() with no auth at all, so anyone could bind a fresh site to their
 * own WP Compress account
 * (Wordfence PRISM, CVSS 5.3). v7.21.30 gated it on current_user_can('manage_wpc_settings'),
 * which closed the hole but also broke the only legitimate caller: the MainWP
 * dashboard extension talks to the child server-to-server (wp_remote_post, no
 * cookie), so on the child that request is user 0 and was answered 'forbidden'
 * every time.
 *
 * The dashboard already owns an authenticated channel to every child it manages:
 * every dashboard->child call is signed with the dashboard's private key and
 * MainWP Child verifies it against the public key installed when the site owner
 * paired the site. Extensions ride that channel through the 'extra_execution'
 * function, which MainWP Child dispatches to the mainwp_child_extra_execution
 * filter ONLY after the signature check passed. So the plugin registers no URL
 * of its own any more; it answers the dashboard inside that filter, and returns
 * data (MainWP Child JSON-encodes the array) instead of halting the request.
 */
class wps_ic_mainwp extends wps_ic
{


    public function __construct()
    {
        add_filter('mainwp_child_extra_execution', [__CLASS__, 'mainwp_extra_execution'], 10, 2);
    }


    /**
     * @param array $information Accumulated reply from other extensions; must be returned.
     * @param array $post        The signed, verified payload the dashboard sent.
     * @return array
     */
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
                // Never silently re-key a site already linked to a different account.
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
