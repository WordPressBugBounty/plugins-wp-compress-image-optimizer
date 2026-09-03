<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-shutdown-drain.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */



if (!defined('ABSPATH')) {
    exit;
}


if (!function_exists('wpc_v2_shutdown_drain_should_trigger')) {
    function wpc_v2_shutdown_drain_should_trigger()
    {
        if (defined('WP_CLI') && WP_CLI) return false;
        if (defined('DOING_CRON') && DOING_CRON) return false;
        if (defined('DOING_AJAX') && DOING_AJAX) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) return false;
        
        
        if (!apply_filters('wpc_v2_shutdown_drain_enabled', true)) return false;
        return true;
    }
}





if (!function_exists('wpc_v2_shutdown_drain_register')) {
    function wpc_v2_shutdown_drain_register()
    {
        if (!wpc_v2_shutdown_drain_should_trigger()) return;


        if (function_exists('wpc_v2_get_lazy_enabled') && !wpc_v2_get_lazy_enabled()) return;

        register_shutdown_function('wpc_v2_shutdown_drain_fire');
    }
}
add_action('init', 'wpc_v2_shutdown_drain_register', 99);


if (!function_exists('wpc_v2_shutdown_drain_fire')) {
    function wpc_v2_shutdown_drain_fire()
    {


        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }

        
        
        if (!function_exists('wpc_v2_pull_drain_fire')) return;


        $lock_ttl = (int) apply_filters('wpc_v2_shutdown_drain_lock_ttl_s', 30);
        if ($lock_ttl < 5)   $lock_ttl = 5;
        if ($lock_ttl > 300) $lock_ttl = 300;


        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        if ((int) get_option('wpc_v2_drain_alive_until_ms', 0) <= (int) (microtime(true) * 1000)) {
            return;
        }

        if (get_transient('wpc_v2_shutdown_drain_lock')) return;
        set_transient('wpc_v2_shutdown_drain_lock', 1, $lock_ttl);

        wpc_v2_pull_drain_fire();
    }
}


if (!function_exists('wpc_v2_shutdown_drain_schedule_event')) {
    function wpc_v2_shutdown_drain_schedule_event()
    {
        $enabled = function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled();
        $hook    = 'wpc_v2_shutdown_drain_tick';


        if (function_exists('wp_get_scheduled_event')) {
            $existing = wp_get_scheduled_event($hook);
            if (is_object($existing) && !empty($existing->schedule)) {
                wp_clear_scheduled_hook($hook);
            }
        }

        if ($enabled && !wp_next_scheduled($hook)) {
            wp_schedule_single_event(time() + 120, $hook);
        }
        if (!$enabled && wp_next_scheduled($hook)) {
            wp_clear_scheduled_hook($hook);
        }
    }
}
add_action('init', 'wpc_v2_shutdown_drain_schedule_event', 100);

if (!function_exists('wpc_v2_shutdown_drain_tick_handler')) {
    function wpc_v2_shutdown_drain_tick_handler()
    {


        wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
        if (function_exists('wpc_v2_pull_drain_fire')
            && (int) get_option('wpc_v2_drain_alive_until_ms', 0) > (int) (microtime(true) * 1000)) {
            wpc_v2_pull_drain_fire();
        }


        $enabled = function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled();
        if ($enabled && !wp_next_scheduled('wpc_v2_shutdown_drain_tick')) {
            wp_schedule_single_event(time() + 120, 'wpc_v2_shutdown_drain_tick');
        }
    }
}
add_action('wpc_v2_shutdown_drain_tick', 'wpc_v2_shutdown_drain_tick_handler');
