<?php
/**
 * WP Compress — CF Piece 2 scaffold (v7.08.60): signed `x-wpc-config` header injection wiring.
 *
 * INERT BY DESIGN. Nothing here touches the network or a Cloudflare zone on a default install. It
 * activates only when BOTH are true:
 *   (a) orch ships `POST {orchestrator_url}/v2/signed-header` (returns { value, ttl }), and
 *   (b) the `wpc_v2_cf_header_injection` option is set (or the same-named filter returns true).
 * Until then every entry point below short-circuits to a no-op and leaves any CF rules untouched.
 *
 * Flow once live (Option A — orch SIGNS, the plugin INJECTS via a CF Transform Rule):
 *   1. wpc_v2_fetch_signed_header() POSTs the SAME HMAC envelope used by /v2/config to /v2/signed-header;
 *      orch returns { "value": "<signed x-wpc-config>", "ttl": <seconds> }.
 *   2. wpc_v2_apply_signed_header() caches the value + expiry and calls
 *      WPC_CloudflareAPI::ensureWpcConfigInjection($zoneId, $value) — ONE http_request_late_transform
 *      rewrite rule that strips inbound apikey and sets the trusted signed x-wpc-config on CDN requests.
 *   3. A daily WP-Cron event re-fetches before the signature expires (orch rotates ~24h).
 *
 * @since 7.08.60
 */
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_cf_header_injection_enabled')) {
    /**
     * Master flag for the whole feature. Default OFF (scaffold). Option is the primary control;
     * the filter lets ops/QA force it without a DB write.
     *
     * @return bool
     */
    function wpc_v2_cf_header_injection_enabled()
    {
        $opt = get_option('wpc_v2_cf_header_injection', false);
        return (bool) apply_filters('wpc_v2_cf_header_injection', !empty($opt));
    }
}

if (!function_exists('wpc_v2_fetch_signed_header')) {
    /**
     * Fetch the orch-signed x-wpc-config value. Returns ['value' => string, 'ttl' => int] or false.
     *
     * Fails SAFE: if the endpoint is absent (pre-ship orch → 404), times out, or returns anything
     * unexpected, this returns false and the caller leaves any existing CF rule exactly as-is. The
     * plugin NEVER constructs the signed value itself — only orch holds the signing material.
     *
     * @return array|false
     */
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

        // Canonical body. Sign the EXACT bytes we send (same scheme as wpc_v2_config_sync_zones()).
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
            // 404 (orch not shipped yet) / 5xx → silent no-op; existing CF rule (if any) stays valid.
            return false;
        }

        $json = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($json) || empty($json['value']) || !is_string($json['value'])) {
            return false;
        }

        $ttl = isset($json['ttl']) ? (int) $json['ttl'] : DAY_IN_SECONDS;
        if ($ttl < 300) {
            $ttl = DAY_IN_SECONDS; // guard against a bogus/short ttl forcing a fetch storm
        }

        return ['value' => $json['value'], 'ttl' => $ttl];
    }
}

if (!function_exists('wpc_v2_apply_signed_header')) {
    /**
     * Push a fresh signed value into the connected Cloudflare zone's Transform Rules.
     *
     * No-op unless: the flag is on, a CF zone + token are connected, and a value was fetched. Uses an
     * expiry gate so admin loads don't re-hit orch on every page — only re-fetches when forced, when
     * nothing is cached, or within 1h of expiry.
     *
     * @param bool $force Bypass the freshness gate (used by the daily cron).
     * @return bool True if a valid rule is in place, false otherwise.
     */
    function wpc_v2_apply_signed_header($force = false)
    {
        if (!wpc_v2_cf_header_injection_enabled()) {
            return false;
        }

        // Need a connected CF zone + token (same option the cache integration uses) and the SDK class.
        $cf = get_option(WPS_IC_CF);
        if (empty($cf['zone']) || empty($cf['token']) || !class_exists('WPC_CloudflareAPI')) {
            return false;
        }

        $cached_val = (string) get_option('wpc_v2_signed_header_value', '');
        $expires_at = (int) get_option('wpc_v2_signed_header_expires', 0);
        if (!$force && $cached_val !== '' && $expires_at > (time() + HOUR_IN_SECONDS)) {
            return true; // still fresh; the CF rule is already in place
        }

        $fetched = wpc_v2_fetch_signed_header();
        if (!$fetched) {
            return false; // orch not ready / transient error → leave the existing rule untouched
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
    /**
     * Daily refresh — force a re-fetch ahead of signature rotation. Self-disarms when the flag is off.
     */
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
    /**
     * Arm/disarm the feature on admin load. Flag OFF → ensure the cron is unscheduled and stop (no
     * network, no CF calls). Flag ON → ensure the daily cron exists and opportunistically top up
     * (rate-limited by the expiry gate inside wpc_v2_apply_signed_header()).
     */
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
        // v7.08.62 — the inline apply is a blocking up-to-15s POST to /v2/signed-header. Never run it on
        // an admin-ajax request (would hang save/restore). The daily cron + a regular admin page load keep
        // the cached header fresh; steady-state is cached, so this rarely fetches anyway.
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
        wpc_v2_apply_signed_header(false);
    }
}
add_action('admin_init', 'wpc_v2_signed_header_boot', 30);
