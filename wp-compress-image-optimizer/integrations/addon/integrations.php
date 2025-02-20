<?php

class wpc_addon_integrations
{

    public function __construct()
    {
        #$this->wpMaintenance();
    }


    public function wpMaintenance()
    {
        if (class_exists('MTNC') || class_exists('MTNC_PRO')) {
            // WP Maintenance Plugin
            $wpMaintenance = get_option('maintenance_options');
            if (!empty($wpMaintenance)) {
                if (!empty($wpMaintenance['state']) && $wpMaintenance['state'] === 1) {
                    return true;
                }
            }
        }

        return false;
    }

}