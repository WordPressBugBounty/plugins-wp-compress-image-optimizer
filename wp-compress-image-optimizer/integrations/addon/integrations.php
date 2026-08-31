<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: integrations/addon/integrations.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

if (!defined('ABSPATH')) {
    exit; 
}

class wpc_addon_integrations
{

    public function __construct()
    {
        
    }


    public function wpMaintenance()
    {
        if (class_exists('MTNC') || class_exists('MTNC_PRO')) {
            
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