<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * (v7.03.87) LCP/ATF HEALTH-CHECK endpoint — for the crit-push joint debug.
 *
 * Returns the live on-disk state of the LCP/ATF hint pipeline for a given URL as JSON, so the producer
 * can curl ONE url and pinpoint the first failing "beat" (regen -> stash -> heal -> apply) with no
 * round-trips.
 *
 *   GET /?wpc_lcp_health=<KEY>&url=<page-url>
 *   GET /?wpc_lcp_health=mykey                 (logged-in admin -> returns the KEY)
 *
 * KEY = HMAC-SHA256('wpc-lcp-health-v1', site apikey) — stable + secret, never exposes the raw apikey.
 *
 * NOTE: "applied to the LIVE page" cannot come from a server endpoint (the served HTML is CDN/page-cached).
 * This reports the PLUGIN state + parsed hints; pair it with an admin hard-refresh view-source to split
 * "plugin applied it" vs "CDN/edge cache stale".
 */

if (!function_exists('wpc_lcp_health_json')) {
    function wpc_lcp_health_json($data, $code = 200)
    {
        if (function_exists('status_header')) {
            status_header($code);
        }
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Robots-Tag: noindex');
        }
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

add_action('init', function () {
    if (!isset($_GET['wpc_lcp_health'])) {
        return;
    }
    if (!function_exists('wpc_v2_get_apikey')) {
        return;
    }
    $apikey = (string) wpc_v2_get_apikey();
    if ($apikey === '') {
        wpc_lcp_health_json(['error' => 'no apikey configured on this site'], 403);
    }
    $key   = hash_hmac('sha256', 'wpc-lcp-health-v1', $apikey);
    $given = (string) $_GET['wpc_lcp_health'];

    // Admin key retrieval: ?wpc_lcp_health=mykey
    if ($given === 'mykey') {
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            wpc_lcp_health_json([
                'debug_key' => $key,
                'usage'     => '/?wpc_lcp_health=' . $key . '&url=' . rawurlencode(home_url('/sample-page/')),
            ]);
        }
        wpc_lcp_health_json(['error' => 'log in as admin to retrieve the key'], 403);
    }
    if (!hash_equals($key, $given)) {
        wpc_lcp_health_json(['error' => 'bad key (admin: ?wpc_lcp_health=mykey to retrieve it)'], 403);
    }

    // ---- build state ----
    $url     = (isset($_GET['url']) && $_GET['url'] !== '') ? esc_url_raw(urldecode((string) $_GET['url'])) : home_url('/');
    $url_key = class_exists('wps_ic_url_key') ? (new wps_ic_url_key())->setup($url) : '';
    $dir     = (defined('WPS_IC_CRITICAL') ? WPS_IC_CRITICAL : '') . $url_key . '/';

    $iso = function ($f) {
        $t = @file_exists($f) ? @filemtime($f) : 0;
        return $t ? gmdate('c', (int) $t) : null;
    };
    $read = function ($f) {
        return @is_readable($f) ? trim((string) @file_get_contents($f)) : '';
    };

    // crit (beat 1)
    $crit_d = $dir . 'critical_desktop.css';
    $crit_m = $dir . 'critical_mobile.css';
    $crit_exists = @file_exists($crit_d) && @file_exists($crit_m);
    $crit_t = $crit_exists ? (int) @filemtime($crit_d) : 0;

    // stash (beat 2)
    $stash_url = $read($dir . 'lcp_url.txt');
    $stash_src = $read($dir . 'lcp_src.txt');

    // lcp.json + last heal fetch (beat 3)
    $lcp_file    = $dir . 'lcp.json';
    $lcp_on_disk = @is_readable($lcp_file);
    $heal_rec    = @is_readable($dir . 'lcp_heal.json')
        ? json_decode((string) @file_get_contents($dir . 'lcp_heal.json'), true)
        : null;

    // hints (beat 4) — re-parse lcp.json the way the reader/consumer does
    $lcp_hint = null;
    $afold    = [];
    if ($lcp_on_disk) {
        $j = json_decode((string) @file_get_contents($lcp_file), true);
        if (is_array($j)) {
            $lcp_hint = (isset($j['lcp']) && is_array($j['lcp'])) ? $j['lcp'] : null;
            $atf = (isset($j['atf_images']) && is_array($j['atf_images'])) ? $j['atf_images'] : null;
            if ($atf !== null) {
                $mob = (isset($atf['mobile'])  && is_array($atf['mobile']))  ? $atf['mobile']  : [];
                $des = (isset($atf['desktop']) && is_array($atf['desktop'])) ? $atf['desktop'] : [];
                if (empty($mob) && empty($des)) { $mob = $atf; $des = $atf; }
                $map = [];
                foreach (['mobile_w' => $mob, 'desktop_w' => $des] as $field => $list) {
                    foreach ((array) $list as $im) {
                        if (!is_array($im) || empty($im['stem']) || empty($im['css_w'])) continue;
                        $st = strtolower((string) $im['stem']);
                        if (!isset($map[$st])) $map[$st] = ['stem' => $st, 'mobile_w' => 0, 'desktop_w' => 0];
                        if ($map[$st][$field] === 0) $map[$st][$field] = (int) round((float) $im['css_w']);
                    }
                }
                $afold = array_values($map);
            }
        }
    }
    // would_apply — exactly what the consumer would emit per matched stem
    $would = [];
    foreach ($afold as $h) {
        $mw = (int) $h['mobile_w'];
        $dw = (int) $h['desktop_w'];
        $would[$h['stem']] = ($mw > 0 && $dw > 0)
            ? "(max-width: 768px) {$mw}px, {$dw}px"
            : ($dw > 0 ? "{$dw}px" : "{$mw}px");
    }

    $giveup = ($stash_url !== '' && function_exists('get_transient'))
        ? (int) get_transient('wpc_lcp_healn_' . md5($stash_url))
        : 0;

    wpc_lcp_health_json([
        'plugin_version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
        'url'            => $url,
        'url_key'        => $url_key,
        'crit'           => [
            'exists'       => $crit_exists,
            'mobile_mtime' => $iso($crit_m),
            'age_s'        => $crit_t ? (time() - $crit_t) : null,
        ],
        'stash'          => [
            'lcp_url'    => $stash_url,
            'stashed_at' => $iso($dir . 'lcp_url.txt'),
            'source'     => $stash_url !== '' ? ($stash_src !== '' ? $stash_src : 'unknown') : 'none',
        ],
        'lcp_json'       => [
            'on_disk'    => $lcp_on_disk,
            'path'       => $lcp_file,
            'mtime'      => $iso($lcp_file),
            'last_fetch' => is_array($heal_rec) ? $heal_rec : null,   // {at, http_status, wrote}
        ],
        'healer'         => [
            'give_up_count' => $giveup,   // >=15 = gave up (producer never wrote it for this uuid)
            'throttled'     => ($stash_url !== '' && function_exists('get_transient') && get_transient('wpc_lcp_heal_' . md5($dir))) ? true : false,
        ],
        'hints'          => [
            'wpc_lcp_hint'          => $lcp_hint,
            'wpc_afold_image_hints' => $afold,
        ],
        'would_apply'    => $would,
        'note'           => 'Plugin/on-disk state only. For "applied to the live page": hard-refresh as admin + view-source the <img> sizes. Flipped for admin but not anon = CDN/edge cache (purge scope/TTL), not the plugin.',
    ]);
}, 1);
