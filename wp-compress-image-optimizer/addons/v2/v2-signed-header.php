<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_cf_header_injection_enabled')) {
    





    function wpc_v2_cf_header_injection_enabled()
    {
        $opt = get_option('wpc_v2_cf_header_injection', false);
        return (bool) apply_filters('wpc_v2_cf_header_injection', !empty($opt));
    }
}

if (!function_exists('wpc_v2_fetch_signed_header')) {

    function wpc_v2_fetch_signed_header()
    {
        if (!wpc_v2_cf_header_injection_enabled()) {
            return false;
        }

        $options = get_option(WPS_IC_OPTIONS);
        $apikey  = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        $orch    = function_exists('wpc_v2_orchestrator_url') ? (string) wpc_v2_orchestrator_url() : '';
        $zone_id = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
        if ($apikey === '' || $orch === '' || $zone_id === '') {
            return false;
        }


        $body = wp_json_encode([
            'apikey'   => $apikey,
            'zone_id'  => $zone_id,
            'site_url' => site_url(),
        ]);
        if (!is_string($body)) {
            return false;
        }

        $ts  = (string) time();
        $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body), $apikey);

        $resp = wp_remote_post(rtrim($orch, '/') . '/v2/signed-header', [
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
            ],
            'body'    => $body,
        ]);
        if (is_wp_error($resp)) {
            return false;
        }
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) {

            return false;
        }

        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($json) || empty($json['value']) || !is_string($json['value'])) {
            return false;
        }

        $ttl = isset($json['ttl']) ? (int) $json['ttl'] : DAY_IN_SECONDS;
        if ($ttl < 300) {
            $ttl = DAY_IN_SECONDS;
        }

        return ['value' => $json['value'], 'ttl' => $ttl];
    }
}

if (!function_exists('wpc_v2_apply_signed_header')) {

    function wpc_v2_apply_signed_header($force = false)
    {
        if (!wpc_v2_cf_header_injection_enabled()) {
            return false;
        }

        
        $cf = get_option(WPS_IC_CF);
        if (empty($cf['zone']) || empty($cf['token']) || !class_exists('WPC_CloudflareAPI')) {
            return false;
        }

        $cached_val = (string) get_option('wpc_v2_signed_header_value', '');
        $expires_at = (int) get_option('wpc_v2_signed_header_expires', 0);
        if (!$force && $cached_val !== '' && $expires_at > (time() + HOUR_IN_SECONDS)) {
            return true;
        }

        $fetched = wpc_v2_fetch_signed_header();
        if (!$fetched) {
            return false;
        }

        $api = new WPC_CloudflareAPI($cf['token']);
        $res = $api->ensureWpcConfigInjection($cf['zone'], $fetched['value']);
        if (is_wp_error($res)) {
            error_log('[WPC CFInject] ensureWpcConfigInjection failed: ' . $res->get_error_message());
            return false;
        }

        update_option('wpc_v2_signed_header_value', $fetched['value'], false);
        update_option('wpc_v2_signed_header_expires', time() + (int) $fetched['ttl'], false);
        return true;
    }
}

if (!function_exists('wpc_v2_signed_header_cron')) {
    


    function wpc_v2_signed_header_cron()
    {
        if (!wpc_v2_cf_header_injection_enabled()) {
            return;
        }
        wpc_v2_apply_signed_header(true);
    }
}
add_action('wpc_v2_signed_header_refresh', 'wpc_v2_signed_header_cron');

if (!function_exists('wpc_v2_signed_header_boot')) {
    




    function wpc_v2_signed_header_boot()
    {
        $scheduled = wp_next_scheduled('wpc_v2_signed_header_refresh');

        if (!wpc_v2_cf_header_injection_enabled()) {
            if ($scheduled) {
                wp_unschedule_event($scheduled, 'wpc_v2_signed_header_refresh');
            }
            return;
        }

        if (!$scheduled) {
            wp_schedule_event(time() + 300, 'daily', 'wpc_v2_signed_header_refresh');
        }


        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
        wpc_v2_apply_signed_header(false);
    }
}
add_action('admin_init', 'wpc_v2_signed_header_boot', 30);
