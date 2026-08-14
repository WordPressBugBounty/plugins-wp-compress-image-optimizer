<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_page_load_drain_tick')) {
    function wpc_v2_page_load_drain_tick()
    {


        if (function_exists('wp_doing_ajax') && wp_doing_ajax())  return;
        if (function_exists('wp_doing_cron') && wp_doing_cron())  return;
        if (defined('WP_CLI') && WP_CLI)                          return;
        if (defined('REST_REQUEST') && REST_REQUEST)              return;


        if (!function_exists('wpc_v2_pull_drain_fire')) return;

        
        
        if (function_exists('wpc_v2_get_apikey')) {
            $apikey = wpc_v2_get_apikey();
            if ($apikey === '') return;
        }

        
        


        $now_ms = (int) round(microtime(true) * 1000);


        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        if ((int) get_option('wpc_v2_drain_alive_until_ms', 0) <= $now_ms) return;
        $last   = (int) get_option('wpc_v2_last_pull_check_ms', 0);
        $window = (int) apply_filters('wpc_v2_page_load_active_throttle_ms', 20000);
        if (($now_ms - $last) < $window) return;


        update_option('wpc_v2_last_pull_check_ms', $now_ms, false);


        if (function_exists('wpc_v2_register_deferred_pull_drain')) {
            wpc_v2_register_deferred_pull_drain();
        } else {
            wpc_v2_pull_drain_fire();
        }
    }
}


add_action('init', 'wpc_v2_page_load_drain_tick', 99);
