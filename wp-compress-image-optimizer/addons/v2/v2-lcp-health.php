<?php
if (!defined('ABSPATH')) {
    exit;
}

// The health endpoint reports the observation, so it needs the reader even when the v2
// bootstrap runs on its own (the drop-in path does not include the CDN addons).
if (!class_exists('wps_ic_atf_observation') && defined('WPS_IC_DIR')) {
    require_once WPS_IC_DIR . 'classes/atf_observation.class.php';
}


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


    $crit_d = $dir . 'critical_desktop.css';
    $crit_m = $dir . 'critical_mobile.css';
    $crit_exists = @file_exists($crit_d) && @file_exists($crit_m);
    $crit_t = $crit_exists ? (int) @filemtime($crit_d) : 0;


    $stash_url = $read($dir . 'lcp_url.txt');
    $stash_src = $read($dir . 'lcp_src.txt');


    $lcp_file    = $dir . 'lcp.json';
    $lcp_on_disk = @is_readable($lcp_file);
    $heal_rec    = @is_readable($dir . 'lcp_heal.json')
        ? json_decode((string) @file_get_contents($dir . 'lcp_heal.json'), true)
        : null;


    $lcp_hint = null;
    $afold    = [];
    if ($lcp_on_disk) {
        $j = json_decode((string) @file_get_contents($lcp_file), true);
        if (is_array($j)) {
            $lcp_hint = (isset($j['lcp']) && is_array($j['lcp'])) ? $j['lcp'] : null;
            // Report the widths the render will actually use, resolved, not the raw entries.
            $map = [];
            foreach (wps_ic_atf_observation::widths(wps_ic_atf_observation::SCOPE_ATF) as $st => $record) {
                $map[] = ['stem' => $st, 'mobile_w' => (int) $record['m'], 'desktop_w' => (int) $record['d']];
            }
            if (!empty($map)) {
                $afold = $map;
            }
        }
    }

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
