<?php
if (!defined('ABSPATH')) {
    exit;
}

// v7.21.68 — origin-reach self-healing + smart-delivery fallback (plugin side of the
// service origin_reach witness, orch v3.24.42 / cdn v2.89.115 / keys-api Links 1-5).
// Priority order: HEAL (ask the Cloudflare rules owner to converge the WAF skip rule, CDN
// delivery continues) -> SWITCH (local smart delivery, only when healing cannot work or failed)
// -> TELL (one dismissible notice, only when we switched; never for a state we healed silently).

if (!function_exists('wpc_origin_reach_state')) {
    // Mirror reader with the contract TTLs: ok fresh 24h, bad states fresh 1h; stale or
    // absent mirror = no opinion (null) — inert on absence.
    function wpc_origin_reach_state()
    {
        if (!function_exists('wpc_v2_get_zone_id')) {
            return null;
        }
        $zid = (string) wpc_v2_get_zone_id();
        if ($zid === '') {
            return null;
        }
        $m = get_option('wpc_v2_origin_reach_' . sanitize_key($zid));
        if (!is_array($m) || empty($m['v']) || empty($m['t'])) {
            return null;
        }
        $ttl = ($m['v'] === 'ok') ? DAY_IN_SECONDS : HOUR_IN_SECONDS;
        if ((time() - (int) $m['t']) > (int) apply_filters('wpc_origin_reach_ttl', $ttl, (string) $m['v'])) {
            return null;
        }
        return (string) $m['v'];
    }
}

if (!function_exists('wpc_origin_reach_act_on_state')) {
    function wpc_origin_reach_act_on_state()
    {
        if (!apply_filters('wpc_origin_reach_act_on', true)) {
            return 'off';
        }
        $state = wpc_origin_reach_state();
        if ($state === null || $state === 'ok') {
            return 'no-op';
        }
        if ($state === 'blocked') {
            // Firewall drops pod egress at connect level — no rule we install can fix it.
            return wpc_switch_delivery_lane_to_local('blocked');
        }

        // cf_challenged / waf_403 — heal first: the witness saw the service's origin fetch
        // challenged or refused, so the rules owner reads the zone's WAF rules back and restores
        // the skip rule if it is missing, disabled, demoted or of an old shape (one readback, no
        // write on a healthy zone). Rule: the heal asks the owner, it never writes a rule itself.
        // Observed failure: until 7.24.33 this fired keys cdn_setcname_v6, which receives no
        // Cloudflare token and in the keys source is an empty action answering success, so the
        // "heal" changed nothing and the verify pass then switched the site to local delivery.
        if (get_option('wpc_origin_heal_off') === '1' || !apply_filters('wpc_origin_heal', true)) {
            return 'heal-off';
        }
        $zid = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
        $hs_key = 'wpc_origin_heal_state_' . sanitize_key($zid);
        $hs = get_option($hs_key);
        $hs = is_array($hs) ? $hs : [];
        if (!empty($hs['at']) && (time() - (int) $hs['at']) < DAY_IN_SECONDS) {
            // One heal attempt per 24h per zone; a failed verify inside that window
            // already routed to the lane switch. Never loop.
            return 'heal-cooldown';
        }

        update_option($hs_key, ['at' => time(), 'verified' => null, 'state' => $state], false);
        $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : null;
        if (!is_array($cf) || empty($cf['token']) || empty($cf['zone'])) {
            // No Cloudflare zone connected to the plugin, so no rule of ours to restore. The
            // verify pass still runs: a real pod fetch is the only proof either way, and its
            // failure routes to the switch.
            wpc_log_origin_reach_event('heal-skip', $state, ['why' => 'no-cf-zone']);
        } else {
            // Plain include, never silenced: the gate's malware heuristics flag a silenced include
            // (2026-09-25 gate on 7.24.33, DodgyPhp $silenced_include), and a missing SDK file is a
            // packaging error that must surface, not be hidden.
            if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR') && is_file(WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php')) {
                include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
            }
            $converged = class_exists('wps_ic_cf_rules') ? wps_ic_cf_rules::converge($cf['zone'], 'origin-reach') : ['rules' => [], 'why' => 'no-owner'];
            wpc_log_origin_reach_event('heal-fired', $state, ['skip' => (string) ($converged['rules'][wps_ic_cf_rules::SKIP_KEY] ?? $converged['why'])]);
        }
        // Token propagation + pod cache TTL is <=60s (cdn v2.89.115) — verify after 90s.
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_origin_reach_verify')) {
            wp_schedule_single_event(time() + 90, 'wpc_origin_reach_verify');
        }
        return 'heal-fired';
    }
}

if (!function_exists('wpc_origin_reach_verify')) {
    // The pod's 200 is the only proof (the .11 law): mint a nonce file under uploads
    // (uncacheable by construction) and have keys-api test_origin_fetch pull it via a
    // real pod. Fail-open on every edge: unknown never switches lanes.
    function wpc_origin_reach_verify()
    {
        $state = wpc_origin_reach_state();
        if ($state === null || $state === 'ok' || $state === 'blocked') {
            return 'no-op';
        }
        $zid = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
        $hs_key = 'wpc_origin_heal_state_' . sanitize_key($zid);
        $hs = get_option($hs_key);
        $hs = is_array($hs) ? $hs : [];
        $options = get_option(WPS_IC_OPTIONS);
        $apikey = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        if ($apikey === '' || !function_exists('wp_upload_dir')) {
            return 'unknown';
        }
        $up = wp_upload_dir();
        if (empty($up['basedir']) || !is_writable($up['basedir'])) {
            return 'unknown';
        }
        $nonce = function_exists('wp_generate_password')
            ? strtolower(preg_replace('/[^a-zA-Z0-9]/', '', wp_generate_password(32, false)))
            : md5(microtime(true) . mt_rand());
        $file = $up['basedir'] . '/wpc-verify-' . $nonce . '.txt';
        if (wpc_fs_put($file, $nonce) === false) {
            return 'unknown';
        }
        $r = wp_remote_get(WPS_IC_KEYSURL . '?action=test_origin_fetch&apikey=' . urlencode($apikey)
            . '&nonce=' . urlencode($nonce) . '&time=' . time(),
            ['timeout' => (int) apply_filters('wpc_origin_fetch_timeout', 25), 'sslverify' => false,
             'user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'wpc']);
        @unlink($file);
        if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
            return 'unknown';
        }
        $b = json_decode((string) wp_remote_retrieve_body($r), true);
        $d = (is_array($b) && isset($b['data']) && is_array($b['data'])) ? $b['data'] : (is_array($b) ? $b : []);
        $st = isset($d['status']) ? (int) $d['status'] : 0;
        if ($st === 200 && !empty($d['body_match'])) {
            $hs['verified'] = 'ok';
            update_option($hs_key, $hs, false);
            wpc_log_origin_reach_event('healed', $state, ['pod' => 200]);
            return 'healed';
        }
        if ($st === 403 || $st === 503 || !empty($d['cf_mitigated'])) {
            $hs['verified'] = 'fail';
            update_option($hs_key, $hs, false);
            return wpc_switch_delivery_lane_to_local($state . ':heal-failed');
        }
        return 'unknown';
    }
}

if (!function_exists('wpc_switch_delivery_lane_to_local')) {
    // WP-2 — last resort: local smart delivery. The plugin optimizes via upload (original
    // bytes POSTed once) and serves from disk; our pods never fetch this origin again, so
    // every blocked class is moot. Never break a page to keep a lane.
    function wpc_switch_delivery_lane_to_local($reason)
    {
        if (get_option('wpc_delivery_lane') === 'local') {
            // Latch — but only while the settings still reflect the local lane. A later
            // preset apply can resurrect CDN serving with the latch still set; a live bad
            // state must be allowed to switch again (the .41 class: a lane decision is
            // only as durable as the settings that carry it).
            $current_settings = get_option(WPS_IC_SETTINGS);
            if (!is_array($current_settings) || empty($current_settings['live-cdn']) || $current_settings['live-cdn'] === '0') {
                return 'already-local';
            }
        }
        if (get_option('wpc_lane_autoswitch_off') === '1' || !apply_filters('wpc_lane_autoswitch', true)) {
            return 'switch-off';
        }
        $s = get_option(WPS_IC_SETTINGS);
        if (!is_array($s)) {
            return 'no-settings';
        }
        $s['serve'] = ['jpg' => '0', 'png' => '0', 'gif' => '0', 'svg' => '0', 'fonts' => '0'];
        $s['css'] = 0;
        $s['js'] = 0;
        $s['fonts'] = 0;
        $s['live-cdn'] = '0';
        if (!isset($s['local']) || !is_array($s['local'])) {
            $s['local'] = [];
        }
        $s['local']['media-library'] = '1';
        update_option(WPS_IC_SETTINGS, $s);
        update_option('wpc_delivery_lane', 'local', false);
        update_option('wpc_delivery_lane_reason', (string) $reason . '@' . time(), false);
        update_option('wpc_lane_notice', '1', false);
        if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeAll')) {
            wps_ic_cache_integrations::purgeAll(false, true, false, true, true);
        }
        wpc_log_origin_reach_event('lane-switch', (string) $reason, []);
        return 'switched';
    }
}

if (!function_exists('wpc_on_origin_reach_recovered')) {
    // Recovery: NEVER auto-flip back (thrash risk; re-optimizing is costly). One dismissible
    // note that the origin is reachable again; the switch back is the customer's click.
    function wpc_on_origin_reach_recovered()
    {
        if (get_option('wpc_delivery_lane') === 'local' && get_option('wpc_lane_recover_seen') !== '1') {
            update_option('wpc_lane_recover_notice', '1', false);
        }
        // A recovered origin re-arms the heal budget for the next incident.
        $zid = function_exists('wpc_v2_get_zone_id') ? (string) wpc_v2_get_zone_id() : '';
        if ($zid !== '') {
            delete_option('wpc_origin_heal_state_' . sanitize_key($zid));
        }
    }
}

if (!function_exists('wpc_log_origin_reach_event')) {
    function wpc_log_origin_reach_event($ev, $state, $data = [])
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('origin-reach', $ev, (string) $state, is_array($data) ? $data : []);
        }
        if (function_exists('error_log')) {
            error_log('[WPC origin-reach] ' . $ev . ' state=' . $state);
        }
    }
}

add_action('wpc_origin_reach_act', 'wpc_origin_reach_act_on_state');
add_action('wpc_origin_reach_verify', 'wpc_origin_reach_verify');

// Belt: a bad state seen at admin_init with no act scheduled and a stale/absent heal stamp
// re-enters the machine (covers the missed-cron class). Throttled to one check per 6h.
add_action('admin_init', function () {
    if (wp_doing_ajax() || get_transient('wpc_origin_reach_tick68')) {
        return;
    }
    set_transient('wpc_origin_reach_tick68', 1, 6 * HOUR_IN_SECONDS);
    $state = function_exists('wpc_origin_reach_state') ? wpc_origin_reach_state() : null;
    if ($state === null || $state === 'ok') {
        return;
    }
    if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
        && !wp_next_scheduled('wpc_origin_reach_act')) {
        wp_schedule_single_event(time() + 10, 'wpc_origin_reach_act');
    }
}, 30);

// TELL — one dismissible notice at switch time; one at recovery. Cap-gated, nonce-dismissed.
add_action('admin_init', function () {
    if (empty($_GET['wpc_lane_notice_dismiss']) || !current_user_can('manage_options')
        || !wp_verify_nonce((string) ($_GET['_wpcnonce'] ?? ''), 'wpc_lane_notice')) {
        return;
    }
    if ($_GET['wpc_lane_notice_dismiss'] === 'switch') {
        delete_option('wpc_lane_notice');
    } elseif ($_GET['wpc_lane_notice_dismiss'] === 'recover') {
        delete_option('wpc_lane_recover_notice');
        update_option('wpc_lane_recover_seen', '1', false);
    }
}, 5);

add_action('admin_init', function () {
    if (!function_exists('wpc_set_state_notice')) {
        return;
    }
    if (get_option('wpc_lane_notice') === '1') {
        wpc_set_state_notice('lane_switch', 'info', __('This server blocks our optimization servers, so the site was switched to Smart Delivery: optimized files are served from this server. Nothing to do. To switch back, allow-list our fetchers and choose CDN delivery in Settings.', WPS_IC_TEXTDOMAIN));
    } else {
        wpc_clear_state_notice('lane_switch');
    }
    if (get_option('wpc_lane_recover_notice') === '1') {
        wpc_set_state_notice('lane_recover', 'success', __('Our optimization servers can reach this site again. You can switch back to CDN delivery in Settings.', WPS_IC_TEXTDOMAIN));
    } else {
        wpc_clear_state_notice('lane_recover');
    }
}, 30);
