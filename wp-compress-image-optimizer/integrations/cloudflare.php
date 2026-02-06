<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class wps_ic_cloudflare extends wps_ic_integrations {

    public function is_active() {
        $cfSettings = get_option(WPS_IC_CF);
        return !empty($cfSettings) && !empty($cfSettings['token']);
    }

    public function do_checks() {
        // No specific checks needed
    }

    public function fix_setting($setting) {
        // No specific fixes needed
    }

    public function add_admin_hooks() {
        return [
            'wps_ic_purge_all_cache' => [
                'callback' => 'purge_cache',
                'priority' => 10,
                'args' => 1
            ]
        ];
    }

    public function purge_cache($url_key = false) {
        $cfSettings = get_option(WPS_IC_CF);

        if (!empty($cfSettings)) {
            $zone = $cfSettings['zone'];
            $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
            if ($cfapi) {
                $cfapi->purgeCache($zone);
                sleep(3);
            }
        }
    }

}
