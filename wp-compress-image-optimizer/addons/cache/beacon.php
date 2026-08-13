<?php
if (!defined('ABSPATH')) {
    exit;
}


// Fires the phase-timeline events (linked → preset_applied → gen_dispatched → crit_landed →
// fonts_landed → armed → auto_*) to the manager tracker (contract: p1-handoff §8) so every

//
// HARD-GATED — customer sites must never phone home by default: events fire only when BOTH

//   2. the Auto Mode toggle is on.
// With the constant undefined and the option absent the gate is a single boolean check — zero


if (!function_exists('wpc_cohort_beacon_key')) {
    function wpc_cohort_beacon_key()
    {
        if (defined('WPC_COHORT_BEACON') && is_string(WPC_COHORT_BEACON) && strlen(WPC_COHORT_BEACON) > 8) {
            return WPC_COHORT_BEACON;
        }

        $k = function_exists('get_option') ? get_option('wpc_cohort_beacon') : '';
        return (is_string($k) && strlen($k) > 8) ? $k : '';
    }

    function wpc_cohort_beacon_on()
    {


        static $mem = null;
        if ($mem === false) {
            return false;
        }
        if (!defined('WPC_COHORT_BEACON') && !(function_exists('get_option') && get_option('wpc_cohort_beacon'))) {
            return $mem = false;
        }
        if (wpc_cohort_beacon_key() === '') {
            return $mem = false;
        }
        return get_option('wpc_auto_mode') === '1';
    }

    function wpc_cohort_beacon($event, $payload = [])
    {
        try {
            if (!wpc_cohort_beacon_on() || !function_exists('wp_remote_post')) {
                return;
            }
            $host = function_exists('home_url') ? (string) parse_url(home_url('/'), PHP_URL_HOST) : '';
            wp_remote_post(
                'https://manager.wpcompress.com/?rest_route=/wpc-cohort/v1/ping&key=' . rawurlencode(wpc_cohort_beacon_key()),
                [
                    'blocking' => false,
                    'timeout'  => 2,
                    'headers'  => ['Content-Type' => 'application/json'],
                    'body'     => wp_json_encode([
                        'site'    => $host,
                        'event'   => (string) $event,
                        'ver'     => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
                        'payload' => is_array($payload) ? $payload : [],
                    ]),
                ]
            );
        } catch (\Throwable $e) {
        }
    }
}
