<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_policy_defaults')) {
    function wpc_policy_defaults()
    {
        $s = function_exists('get_option') ? get_option(WPS_IC_SETTINGS, []) : [];
        $s = is_array($s) ? $s : [];
        $live = !empty($s['live-cdn']) && (string) $s['live-cdn'] === '1';
        $ceiling = 'avif';
        if (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'effective_ceiling')) {
            $c = (string) WPC_Delivery_Resolver::effective_ceiling($s);
            if ($c === 'webp' || $c === 'avif' || $c === 'off') {
                $ceiling = $c;
            }
        }
        return [
            'serve'   => $live ? 'edge' : 'origin',
            'land'    => 'on',
            'scope'   => 'site',
            'formats' => $ceiling,
            'budget'  => 0,
            'version' => 0,
            'source'  => 'derived',
        ];
    }
}

if (!function_exists('wpc_policy_normalize')) {
    function wpc_policy_normalize($p)
    {
        if (!is_array($p)) {
            return null;
        }
        $out = [];
        if (isset($p['serve']) && in_array($p['serve'], ['edge', 'origin'], true)) {
            $out['serve'] = $p['serve'];
        }
        if (isset($p['land'])) {
            $l = $p['land'];
            if ($l === 'on' || $l === 'off') {
                $out['land'] = $l;
            } elseif (is_bool($l) || $l === 1 || $l === 0 || $l === '1' || $l === '0') {
                $out['land'] = ((bool) $l) ? 'on' : 'off';
            }
        }
        if (isset($p['scope']) && in_array($p['scope'], ['site', 'homepage'], true)) {
            $out['scope'] = $p['scope'];
        }
        if (isset($p['formats']) && in_array($p['formats'], ['webp', 'avif', 'off'], true)) {
            $out['formats'] = $p['formats'];
        }
        if (isset($p['budget']) && is_numeric($p['budget'])) {
            $out['budget'] = max(0, (int) $p['budget']);
        }
        if (isset($p['version']) && is_numeric($p['version'])) {
            $out['version'] = max(0, (int) $p['version']);
        }
        $cohorts = [];
        foreach ($p as $k => $v) {
            if (!is_string($k) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $k) || in_array($k, wpc_policy_reserved_keys(), true)) {
                continue;
            }
            if ($v === 1 || $v === 0 || $v === true || $v === false || $v === '1' || $v === '0') {
                $cohorts[$k] = ($v === 1 || $v === true || $v === '1') ? 1 : 0;
            }
        }
        if (!empty($cohorts)) {
            $out['cohorts'] = $cohorts;
        }
        if (array_key_exists('wpc_levers', $p)) {
            $levers = wpc_policy_levers_normalize($p['wpc_levers']);
            if ($levers !== null) {
                $out['wpc_levers'] = $levers;
            }
        }
        if (array_key_exists('wpc_lists', $p)) {
            $lists = wpc_policy_lists_normalize($p['wpc_lists']);
            if ($lists !== []) {
                $out['wpc_lists'] = $lists;
            }
        }
        if (isset($p['wpc_trial']) && is_array($p['wpc_trial'])) {
            $of = isset($p['wpc_trial']['of']) && is_numeric($p['wpc_trial']['of']) ? max(0, (int) $p['wpc_trial']['of']) : 0;
            $step = isset($p['wpc_trial']['step']) && is_numeric($p['wpc_trial']['step']) ? max(0, (int) $p['wpc_trial']['step']) : 0;
            if ($of > 0 && $step > 0) {
                $out['wpc_trial'] = [
                    'step'       => min($step, $of),
                    'of'         => $of,
                    'started_at' => isset($p['wpc_trial']['started_at']) && is_numeric($p['wpc_trial']['started_at']) ? max(0, (int) $p['wpc_trial']['started_at']) : 0,
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('wpc_policy_reserved_keys')) {
    function wpc_policy_reserved_keys()
    {
        return ['serve', 'land', 'scope', 'formats', 'budget', 'version', 'source', 'received_at', 'cohorts', 'wpc_levers', 'wpc_lists', 'wpc_trial'];
    }
}

if (!function_exists('wpc_policy_lists_allowed')) {
    /** The list keys the plugin reads: key => ['kind' => 'path' or 'url', 'cohort' => the switch whose consumer reads it]. */
    function wpc_policy_lists_allowed()
    {
        return [
            'cdn_dirs'         => ['kind' => 'path', 'cohort' => 'cdn_directories'],
            'slider_first_url' => ['kind' => 'url', 'cohort' => 'slider_lcp'],
        ];
    }
}

if (!function_exists('wpc_policy_list_hosts')) {
    /** The hosts a URL entry may name: [the home host without www, the zone hosts as lower case]. */
    function wpc_policy_list_hosts()
    {
        $home = function_exists('home_url') ? strtolower((string) wp_parse_url((string) home_url(), PHP_URL_HOST)) : '';
        $zones = [];
        $raws = [function_exists('wpc_cdn_host') ? (string) wpc_cdn_host() : '', function_exists('get_option') ? (string) get_option('ic_cdn_zone_name') : ''];
        foreach ($raws as $raw) {
            $host = strtolower(preg_replace('#/.*$#', '', trim($raw)));
            if ($host !== '' && !in_array($host, $zones, true)) {
                $zones[] = $host;
            }
        }
        return [preg_replace('/^www\./', '', $home), $zones];
    }
}

if (!function_exists('wpc_policy_list_entry_ok')) {
    /** True when $entry is a valid value for list $key: a path of plain folder segments for 'path' (none a WordPress wp- name or a file name with an extension), an absolute URL on the site's host or the zone's for 'url', https when the site is. */
    function wpc_policy_list_entry_ok($key, $entry)
    {
        $allowed = wpc_policy_lists_allowed();
        if (!isset($allowed[$key]) || !is_string($entry) || $entry === '' || strlen($entry) > 300) {
            return false;
        }
        if ($allowed[$key]['kind'] === 'path') {
            if (preg_match('#^/?[A-Za-z0-9._~\-]+(?:/[A-Za-z0-9._~\-]+)*/?\z#', $entry) !== 1) {
                return false;
            }
            foreach (explode('/', trim($entry, '/')) as $segment) {
                if ($segment[0] === '.' || stripos($segment, 'wp-') === 0 || preg_match('/\.(?=[A-Za-z0-9]*[A-Za-z])[A-Za-z0-9]{1,10}\z/', $segment) === 1) {
                    return false;
                }
            }
            return true;
        }
        if (preg_match('/[\s"\'<>\x00-\x1f\x7f]/', $entry) === 1) {
            return false;
        }
        $parts = wp_parse_url($entry);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host']) || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        if (strtolower($parts['scheme']) === 'http' && function_exists('home_url') && strtolower((string) wp_parse_url((string) home_url(), PHP_URL_SCHEME)) === 'https') {
            return false;
        }
        $host = strtolower((string) $parts['host']);
        list($home, $zones) = wpc_policy_list_hosts();
        return ($home !== '' && preg_replace('/^www\./', '', $host) === $home) || in_array($host, $zones, true);
    }
}

if (!function_exists('wpc_policy_list_clean')) {
    /** The entries of list $key as the plugin keeps and reads them: valid ones only, a path without its outer slashes, each once, at most 8; paths sorted, URLs in the service's order. */
    function wpc_policy_list_clean($key, $entries)
    {
        $allowed = wpc_policy_lists_allowed();
        $key = (string) $key;
        $out = [];
        if (!isset($allowed[$key]) || !is_array($entries)) {
            return $out;
        }
        foreach ($entries as $entry) {
            if (count($out) >= 8) {
                break;
            }
            if (!wpc_policy_list_entry_ok($key, $entry)) {
                continue;
            }
            if ($allowed[$key]['kind'] === 'path') {
                $entry = trim($entry, '/');
            }
            if (!in_array($entry, $out, true)) {
                $out[] = $entry;
            }
        }
        if ($allowed[$key]['kind'] === 'path') {
            sort($out, SORT_STRING);
        }
        return $out;
    }
}

if (!function_exists('wpc_policy_lists_normalize')) {
    /** The lists as the policy keeps them: only allowed keys, each list as wpc_policy_list_clean() gives it, a key with none left absent. */
    function wpc_policy_lists_normalize($raw)
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach (array_keys(wpc_policy_lists_allowed()) as $key) {
            if (!isset($raw[$key])) {
                continue;
            }
            $kept = wpc_policy_list_clean($key, $raw[$key]);
            if ($kept !== []) {
                $out[$key] = $kept;
            }
        }
        return $out;
    }
}

if (!function_exists('wpc_policy_list')) {
    /** The validated entries the service sent for list $key, as wpc_policy_list_clean() gives them; [] when there are none. */
    function wpc_policy_list($key)
    {
        $lists = wpc_policy_get('wpc_lists', null);
        $key = (string) $key;
        if (!is_array($lists) || !isset($lists[$key])) {
            return [];
        }
        return wpc_policy_list_clean($key, $lists[$key]);
    }
}

if (!function_exists('wpc_policy_lists_counts')) {
    /** list key => number of entries kept, for the echo and the health body; never the entries. */
    function wpc_policy_lists_counts()
    {
        $counts = [];
        foreach (array_keys(wpc_policy_lists_allowed()) as $key) {
            $n = count(wpc_policy_list($key));
            if ($n > 0) {
                $counts[$key] = $n;
            }
        }
        return $counts;
    }
}

if (!function_exists('wpc_policy_lever_scalar')) {
    /** One lever value as the policy keeps it: a scalar, a boolean as 1 or 0, a string of at most 64 characters; null for anything else. */
    function wpc_policy_lever_scalar($value)
    {
        if (!is_scalar($value)) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_string($value) && strlen($value) > 64) {
            return null;
        }
        return $value;
    }
}

if (!function_exists('wpc_policy_levers_normalize')) {
    function wpc_policy_levers_normalize($raw)
    {
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        $rev = isset($raw['rev']) && is_numeric($raw['rev']) ? max(0, (int) $raw['rev']) : 0;
        $src = isset($raw['levers']) && is_array($raw['levers']) ? $raw['levers'] : array_diff_key($raw, ['rev' => 1, 'scope' => 1, 'levers' => 1]);
        $levers = [];
        foreach ($src as $name => $value) {
            $name = is_string($name) ? strtolower($name) : '';
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
                continue;
            }
            if (is_array($value)) {
                $byDevice = [];
                foreach ($value as $device => $one) {
                    $one = wpc_policy_lever_scalar($one);
                    if (is_string($device) && $one !== null) {
                        $byDevice[strtolower($device)] = $one;
                    }
                }
                $value = [];
                foreach (['mobile', 'desktop'] as $device) {
                    if (isset($byDevice[$device])) {
                        $value[$device] = $byDevice[$device];
                    }
                }
                if ($value === []) {
                    continue;
                }
            } else {
                $value = wpc_policy_lever_scalar($value);
                if ($value === null) {
                    continue;
                }
            }
            $levers[$name] = $value;
        }
        $scope = [];
        if (isset($raw['scope']) && is_array($raw['scope'])) {
            foreach ($raw['scope'] as $path) {
                if (is_string($path) && $path !== '' && strlen($path) <= 300) {
                    $scope[] = '/' . ltrim($path, '/');
                }
            }
        }
        return ['rev' => $rev, 'levers' => $levers, 'scope' => array_values(array_unique($scope))];
    }
}

if (!function_exists('wpc_policy_stored')) {
    function wpc_policy_stored()
    {
        $o = function_exists('get_option') ? get_option(WPC_POLICY_OPTION, null) : null;
        return is_array($o) ? $o : null;
    }
}

if (!function_exists('wpc_policy_builtin_cohort_names')) {
    function wpc_policy_builtin_cohort_names()
    {
        return ['after_hero', 'atf_faces', 'bg_hero_match', 'body_js_ext', 'cache_p1', 'cache_p2',
            'click_serviced', 'crit_after_global', 'crit_retire', 'css_order', 'css_release_idle', 'delay_trusted', 'embed_hold',
            'encode_on_view', 'faces_first_screen', 'fetchpriority_hero', 'first_party_typed', 'font_display_declared',
            'form_submit_hold', 'hero_fold', 'hero_lqip', 'icon_font_preload_drop', 'img_after_hero', 'img_hold_first_screen',
            'inline_data_pct', 'late_faces_below_fold', 'late_faces_handover', 'menu_early', 'menu_fix', 'menu_toggle_live',
            'phone_hero', 'plain_paths', 'release_needs_gesture', 'release_no_transition', 'release_on_lcp_entry',
            'rest_swap_hold', 'safe_delay', 'slice_page_sheets', 'swap_no_transition'];
    }
}

if (!function_exists('wpc_policy_builtin_cohorts')) {
    function wpc_policy_builtin_cohorts()
    {
        if (!function_exists('get_option') || !defined('WPC_POLICY_OPTION')) {
            return [];
        }
        $stored = get_option(WPC_POLICY_OPTION, null);
        if ($stored !== null && $stored !== false && $stored !== '' && $stored !== []) {
            return [];
        }
        if (defined('WPC_BUILTIN_COHORTS_KILL') && WPC_BUILTIN_COHORTS_KILL) {
            return [];
        }
        $at = get_option('wpc_connected_at', null);
        if (is_string($at) && preg_match('/^[0-9]{1,12}$/', $at)) {
            $at = (int) $at;
        }
        if (!is_int($at) || $at < 1792879800) {
            return [];
        }
        if (function_exists('apply_filters') && apply_filters('wpc_builtin_cohorts_enabled', true) === false) {
            return [];
        }
        return array_fill_keys(wpc_policy_builtin_cohort_names(), 1);
    }
}

if (!function_exists('wpc_policy_plugin_default_cohorts')) {
    function wpc_policy_plugin_default_cohorts()
    {
        if (defined('WPC_BUILTIN_COHORTS_KILL') && WPC_BUILTIN_COHORTS_KILL) {
            return [];
        }
        $names = [];
        if (function_exists('apply_filters')) {
            $f = apply_filters('wpc_plugin_default_cohorts', $names);
            $names = is_array($f) ? array_values(array_filter($f, 'is_string')) : [];
        }
        return $names;
    }
}

if (!function_exists('wpc_policy_with_default_cohorts')) {
    function wpc_policy_with_default_cohorts($cohorts)
    {
        if (!is_array($cohorts)) {
            return $cohorts;
        }
        foreach (wpc_policy_plugin_default_cohorts() as $dn) {
            if (!array_key_exists($dn, $cohorts)) {
                $cohorts[$dn] = 1;
            }
        }
        return $cohorts;
    }
}

if (!function_exists('wpc_policy_effective_cohorts')) {
    function wpc_policy_effective_cohorts($stored, $builtin = [])
    {
        if (is_array($stored) && $stored !== []) {
            return isset($stored['cohorts']) && is_array($stored['cohorts']) ? wpc_policy_with_default_cohorts($stored['cohorts']) : [];
        }
        return is_array($builtin) && $builtin !== [] ? wpc_policy_with_default_cohorts($builtin) : [];
    }
}

if (!function_exists('wpc_policy_local')) {
    function wpc_policy_local()
    {
        $o = function_exists('get_option') ? get_option(WPC_POLICY_LOCAL_OPTION, []) : [];
        return is_array($o) ? $o : [];
    }
}

if (!function_exists('wpc_policy')) {
    function wpc_policy($refresh = false)
    {
        static $memo = null;
        if ($refresh) {
            $memo = null;
        }
        if ($memo !== null) {
            return $memo;
        }
        $p = wpc_policy_defaults();
        $stored = wpc_policy_stored();
        if (is_array($stored)) {
            foreach (['serve', 'land', 'scope', 'formats', 'budget', 'version', 'cohorts', 'wpc_levers', 'wpc_lists', 'wpc_trial'] as $k) {
                if (array_key_exists($k, $stored)) {
                    $p[$k] = $stored[$k];
                }
            }
            $p['source'] = 'orchestrator';
        }
        if (!is_array($stored) || $stored === []) {
            $builtin = wpc_policy_builtin_cohorts();
            if ($builtin !== []) {
                $p['cohorts'] = $builtin;
                $p['source'] = 'builtin';
            }
        }
        if (isset($p['source']) && ($p['source'] === 'orchestrator' || $p['source'] === 'builtin') && isset($p['cohorts']) && is_array($p['cohorts'])) {
            $p['cohorts'] = wpc_policy_with_default_cohorts($p['cohorts']);
        }
        $local = wpc_policy_local();
        if (isset($local['serve']) && $local['serve'] === 'origin') {
            $p['serve'] = 'origin';
        }
        if (isset($local['land']) && $local['land'] === 'on') {
            $p['land'] = 'on';
        }
        $f = apply_filters(WPC_POLICY_OPTION, $p);
        if ($f === null || $f === false) {
            $f = wpc_policy_defaults();
        }
        $memo = is_array($f) ? $f : $p;
        return $memo;
    }
}

if (!function_exists('wpc_policy_get')) {
    function wpc_policy_get($key, $default = null)
    {
        $p = wpc_policy();
        return array_key_exists($key, $p) ? $p[$key] : $default;
    }
}

if (!function_exists('wpc_policy_serve')) {
    function wpc_policy_serve()
    {
        return (string) wpc_policy_get('serve', 'edge');
    }
}

if (!function_exists('wpc_policy_land')) {
    function wpc_policy_land()
    {
        if (function_exists('wpc_cdn_drives_images') && !wpc_cdn_drives_images()) {
            return 'on';
        }
        return (string) wpc_policy_get('land', 'on');
    }
}

if (!function_exists('wpc_policy_formats')) {
    function wpc_policy_formats()
    {
        return (string) wpc_policy_get('formats', 'avif');
    }
}

if (!function_exists('wpc_policy_scope')) {
    function wpc_policy_scope()
    {
        return (string) wpc_policy_get('scope', 'site');
    }
}

if (!function_exists('wpc_policy_version')) {
    function wpc_policy_version()
    {
        $s = wpc_policy_stored();
        return is_array($s) && isset($s['version']) ? (int) $s['version'] : 0;
    }
}

if (!function_exists('wpc_policy_ingest')) {
    function wpc_policy_ingest($rbody)
    {
        if (!is_array($rbody)) {
            return false;
        }
        $raw = null;
        if (isset($rbody['policy']) && is_array($rbody['policy'])) {
            $raw = $rbody['policy'];
        } elseif (!empty($rbody['zones']) && is_array($rbody['zones'])) {
            foreach ($rbody['zones'] as $rz) {
                if (is_array($rz) && isset($rz['policy']) && is_array($rz['policy'])) {
                    $raw = $rz['policy'];
                    break;
                }
            }
        }
        if ($raw === null) {
            return false;
        }
        $new = wpc_policy_normalize($raw);
        if (!is_array($new) || empty($new)) {
            return false;
        }
        $old = wpc_policy_stored();
        if (is_array($old) && isset($old['version']) && isset($new['version']) && (int) $new['version'] < (int) $old['version']) {
            return false;
        }
        if (is_array($old) && isset($old['version']) && !isset($new['version'])) {
            $new['version'] = (int) $old['version'];
        }
        if (is_array($old) && isset($old['version']) && isset($new['version']) && (int) $new['version'] === (int) $old['version']) {
            $held = [];
            foreach (['serve', 'land', 'scope', 'formats'] as $k) {
                $was = array_key_exists($k, $old) ? $old[$k] : null;
                if (isset($new[$k]) && $new[$k] !== $was) {
                    $held[$k] = [$was, $new[$k]];
                }
                if ($was === null) {
                    unset($new[$k]);
                } else {
                    $new[$k] = $was;
                }
            }
            if (!empty($held) && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('policy-same-version', '', '', ['held' => $held, 'version' => (int) $old['version']]);
            }
        }
        if (is_array($old) && !empty($new['wpc_levers']['levers']) && isset($new['wpc_levers']['rev'], $old['wpc_levers']['rev'])
            && (int) $new['wpc_levers']['rev'] < (int) $old['wpc_levers']['rev']) {
            $new['wpc_levers'] = $old['wpc_levers'];
        }
        $new['received_at'] = time();
        $builtin_was = wpc_policy_builtin_cohorts();
        update_option(WPC_POLICY_OPTION, $new, false);
        wpc_policy(true);
        if ($builtin_was !== []) {
            $now_fx = isset($new['cohorts']) ? $new['cohorts'] : [];
            wpc_policy_cohort_effects(array_merge($now_fx, $builtin_was), $now_fx);
        }
        wpc_policy_cohort_effects(is_array($old) && isset($old['cohorts']) ? $old['cohorts'] : [], isset($new['cohorts']) ? $new['cohorts'] : []);
        $changed = [];
        foreach (['serve', 'land', 'scope', 'formats'] as $k) {
            $was = is_array($old) && isset($old[$k]) ? $old[$k] : null;
            if (isset($new[$k]) && $new[$k] !== $was) {
                $changed[$k] = [$was, $new[$k]];
            }
        }
        if (!empty($changed)) {
            wpc_policy_transition($changed);
        }
        $was_levers = is_array($old) && isset($old['wpc_levers']) ? $old['wpc_levers'] : null;
        $now_levers = isset($new['wpc_levers']) ? $new['wpc_levers'] : null;
        $was_cohorts = wpc_policy_effective_cohorts($old, $builtin_was);
        $now_cohorts = wpc_policy_effective_cohorts($new);
        $was_lists = is_array($old) && isset($old['wpc_lists']) ? $old['wpc_lists'] : null;
        $now_lists = isset($new['wpc_lists']) ? $new['wpc_lists'] : null;
        if (wpc_policy_render_part($was_cohorts, $was_levers, $was_lists) !== wpc_policy_render_part($now_cohorts, $now_levers, $now_lists)
            && function_exists('wpc_delivery_config_version_flip')) {
            wpc_delivery_config_version_flip(true);
        }
        if (($was_levers !== $now_levers || $was_cohorts !== $now_cohorts || $was_lists !== $now_lists) && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('policy-levers', '', '', [
                'rev_from' => is_array($was_levers) ? (int) $was_levers['rev'] : 0,
                'rev_to'   => is_array($now_levers) ? (int) $now_levers['rev'] : 0,
                'levers'   => is_array($now_levers) ? count($now_levers['levers']) : 0,
                'cohorts'  => count($now_cohorts),
                'lists'    => is_array($now_lists) ? count($now_lists) : 0,
            ]);
        }
        return true;
    }
}

if (!function_exists('wpc_policy_cohort_effects')) {
    function wpc_policy_cohort_effects($was, $now)
    {
        $was = is_array($was) ? $was : [];
        $now = is_array($now) ? $now : [];
        if (function_exists('wpc_cache_p1_sync')) {
            wpc_cache_p1_sync(!empty($now['cache_p1']));
        }
        if (function_exists('wpc_vitals_early_off_sync')) {
            wpc_vitals_early_off_sync(!empty($now['off_vitals_early']));
        }
        if (function_exists('wpc_off_flag_names')) {
            foreach (wpc_off_flag_names() as $flagName) {
                wpc_off_flag_sync($flagName, !empty($now[$flagName]));
            }
        }
        if (!empty($now['off_bc_htaccess']) || !empty($was['off_bc_htaccess'])) {
            wpc_policy_browser_cache_form();
        }
        foreach (['cache_p4' => 'wpc_cache_p4_sync', 'cache_p3' => 'wpc_cache_p3_sync'] as $name => $sync) {
            if (!empty($now[$name]) !== !empty($was[$name]) && function_exists($sync)) {
                $sync(!empty($now[$name]));
            }
        }
        if (!empty($now['font_display_declared']) !== !empty($was['font_display_declared'])
            && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_fontdisplay_rebake_event')) {
            wp_schedule_single_event(time() + 20, 'wpc_fontdisplay_rebake_event');
        }
        if ((int) ($now['devmix_clear'] ?? 0) === 1 && (int) ($was['devmix_clear'] ?? 0) !== 1 && function_exists('wpc_device_mix_clear')) {
            wpc_device_mix_clear('remote');
        }
    }
}

if (!function_exists('wpc_policy_browser_cache_form')) {
    function wpc_policy_browser_cache_form()
    {
        if (!class_exists('wps_ic_htaccess') && defined('WPS_IC_DIR') && is_readable(WPS_IC_DIR . 'classes/htaccess.class.php')) {
            include_once WPS_IC_DIR . 'classes/htaccess.class.php';
        }
        if (!class_exists('wps_ic_htaccess')) {
            return false;
        }
        return (new wps_ic_htaccess())->wpcSyncBrowserCacheForm();
    }
}

if (!function_exists('wpc_policy_forget_service_keys')) {
    function wpc_policy_forget_service_keys()
    {
        $stored = wpc_policy_stored();
        if (!is_array($stored) || !array_intersect(['cohorts', 'wpc_levers', 'wpc_lists', 'wpc_trial', 'version'], array_keys($stored))) {
            return false;
        }
        $was = isset($stored['cohorts']) ? $stored['cohorts'] : [];
        $was_effective = wpc_policy_effective_cohorts($stored);
        $was_levers = isset($stored['wpc_levers']) ? $stored['wpc_levers'] : null;
        $was_lists = isset($stored['wpc_lists']) ? $stored['wpc_lists'] : null;
        unset($stored['cohorts'], $stored['wpc_levers'], $stored['wpc_lists'], $stored['wpc_trial'], $stored['version']);
        update_option(WPC_POLICY_OPTION, $stored, false);
        wpc_policy(true);
        wpc_policy_cohort_effects($was, []);
        if (wpc_policy_render_part($was_effective, $was_levers, $was_lists) !== wpc_policy_render_part(wpc_policy_effective_cohorts($stored, wpc_policy_builtin_cohorts()), null, null)
            && function_exists('wpc_delivery_config_version_flip')) {
            wpc_delivery_config_version_flip(true);
        }
        return true;
    }
}

if (!function_exists('wpc_policy_cohort')) {
    function wpc_policy_cohort($name)
    {
        $cohorts = wpc_policy_get('cohorts', []);
        return (is_array($cohorts) && isset($cohorts[(string) $name]) && (int) $cohorts[(string) $name] === 1) ? 1 : 0;
    }
}

if (!function_exists('wpc_safe_delay_on')) {
    /** True while the safe_delay cohort ('families': safe_delay_families) is on for the site and its kill filter (wpc_safe_delay, wpc_safe_delay_families) has not been turned off. */
    function wpc_safe_delay_on($part = '')
    {
        $name = $part === 'families' ? 'safe_delay_families' : 'safe_delay';
        if (wpc_policy_cohort($name) !== 1) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_' . $name, true) !== false;
    }
}

if (!function_exists('wpc_lever_acting_names')) {
    function wpc_lever_acting_names()
    {
        return ['delay_js', 'scripts_late', 'delay_onload', 'menu_early', 'lazy_skip', 'hero_eager', 'lcp_density_cap', 'fonts_origin', 'cv_below_fold', 'font_preload_drop',
            'preload_cap', 'preload_gap_ms', 'css_barrier', 'late_css_timer_cap', 'style_hold_ms', 'submit_hold_ms', 'menu_resize_hold_ms', 'loader_pace_ms',
            'late_css_timer', 'late_css_lcp', 'late_css_cap', 'late_css_backstop', 'menu_early_lcp', 'delay_timeout',
            'loader_after_lcp_cap_ms', 'after_hero_cap_ms', 'after_hero_paint_ms', 'img_after_hero_cap_ms', 'hero_painted_timeout_ms', 'after_hero_entry_cap_ms', 'hero_lqip_load_cap_ms',
            'atf_inline_faces_cap', 'fonts_css_inline_max', 'localize_inline_fonts_cap', 'font_preload_max',
            'hero_lqip_image_max', 'hero_lqip_inline_max', 'hero_lqip_selector_max', 'hero_lqip_layers_max', 'hero_lqip_images_max',
            'img_lqip_image_max', 'img_lqip_width_max'];
    }
}

if (!function_exists('wpc_lever_cohorts')) {
    /** The on/off levers that read as on from a cohort while the lever says nothing: lever name => the cohort names that turn it on. lazy_skip (a count) and lcp_density_cap (a number) carry a value a cohort cannot. */
    function wpc_lever_cohorts()
    {
        return [
            'delay_js'          => ['lever_delay_js'],
            'scripts_late'      => ['lever_scripts_late'],
            'delay_onload'      => ['lever_delay_onload'],
            'menu_early'        => ['lever_menu_early', 'menu_early'],
            'hero_eager'        => ['lever_hero_eager'],
            'fonts_origin'      => ['lever_fonts_origin'],
            'cv_below_fold'     => ['lever_cv_below_fold'],
            'font_preload_drop' => ['lever_font_preload_drop'],
        ];
    }
}

if (!function_exists('wpc_lever_cohort_on')) {
    /** True while a cohort that turns lever $name on is on and a person's own setting does not block the lever. */
    function wpc_lever_cohort_on($name)
    {
        $map = wpc_lever_cohorts();
        $name = (string) $name;
        if (!isset($map[$name])) {
            return false;
        }
        foreach ($map[$name] as $cohort) {
            if (wpc_policy_cohort($cohort) === 1) {
                return !wpc_lever_blocked($name);
            }
        }
        return false;
    }
}

if (!function_exists('wpc_policy_nonrender_cohorts')) {
    /**
     * The per-site switches whose flip leaves every stored page as it is: they change what is dispatched,
     * accepted, refused, purged or sent as a header, never the bytes of a page. A switch not named here
     * is taken to change the page (wpc_policy_render_part), so a new one purges until it is listed in one of the two lists.
     */
    function wpc_policy_nonrender_cohorts()
    {
        return ['tls_verify', 'eov_formats_off', 'sig_enforce', 'off_sig_403', 'land_sig_required', 'off_bc_htaccess', 'off_post_takedown', 'off_scope_narrow',
            'off_key_check', 'off_rung_bound', 'off_wire_gzip', 'off_gone_drop', 'off_warm_sig', 'off_land_shape', 'corpus_noscript',
            'off_font_seen_only',
            'hero_land_first',
            'off_beacon_proof',
            'off_vitals_early',
            'vitals_push',
            'cache_p1', 'cache_p2', 'cache_p3', 'cache_p4', 'hc_auth_legs', 'devmix_clear', 'off_lcp_preload_guard_per_device', 'off_cache_without_lcp_preload',
            'host_cache_purge', 'crit_leg_reopen'];
    }
}

if (!function_exists('wpc_policy_render_cohorts')) {
    /** The per-site switches whose flip changes the bytes of a stored page. Together with the list above, every cohort the code reads is named once. */
    function wpc_policy_render_cohorts()
    {
        return ['plain_paths', 'encode_on_view', 'off_attach_match', 'off_zone_crit_urls', 'delay_trusted', 'menu_fix', 'css_order', 'body_js_ext',
            'fetchpriority_hero', 'hero_fold', 'faces_range', 'atf_faces', 'asset_icv', 'crit_after_global', 'click_record', 'off_first_screen_swap', 'click_serviced', 'bg_hero_match', 'off_bg_preload_scan', 'off_painted_follow', 'native_assets', 'phone_hero', 'after_hero', 'img_after_hero', 'icon_font_preload_drop', 'late_faces_handover', 'late_faces_below_fold', 'release_no_transition', 'swap_no_transition', 'inline_data_pct', 'doc_trim', 'release_needs_gesture', 'css_release_idle', 'crit_retire', 'rest_swap_hold', 'embed_hold', 'menu_early', 'subset_face_swap', 'font_display_declared', 'faces_first_screen','hidden_until_js_idle', 'tap_first', 'form_submit_hold', 'bg_mobile_src', 'bg_mobile_edge_rung', 'hero_lqip', 'img_lqip', 'hero_one_url', 'slice_keyframes', 'slice_page_sheets', 'refused_face_first', 'icon_guard_release', 'ar_pin_exact', 'hero_decode_sync', 'lazy_margin_native', 'atf_complete_lazy', 'hero_same_origin', 'ar_pin_disk', 'deferred_after_lcp', 'faces_base64_trim', 'menu_toggle_live', 'release_on_lcp_entry', 'img_hold_first_screen', 'faces_after_lcp', 'first_party_typed', 'lever_delay_js', 'lever_scripts_late', 'lever_delay_onload', 'lever_menu_early', 'lever_hero_eager', 'lever_fonts_origin', 'lever_cv_below_fold', 'lever_font_preload_drop', 'off_retire_live_gate', 'phone_bg_derive', 'safe_delay', 'safe_delay_families', 'delay_host_rule', 'divi_live_css_off', 'off_first_n_trim', 'off_icon_preload_unseen', 'off_gf_hold', 'off_data_only_call_guard', 'off_cio_rebase_fix', 'off_lcp_entry_match', 'off_rescan_late_faces', 'off_first_n_measured_fold', 'off_hidden_slide_defer', 'off_loader_after_lcp', 'off_bg_rung_fit', 'off_bg_rung_pair', 'off_cf_bg_named', 'off_img_load_unhold', 'off_preload_before_crit', 'off_rest_at_crit', 'rest_at_crit', 'off_late_chain_gate', 'off_cv_transition_guard', 'off_rest_focus_guard', 'off_offscreen_video', 'off_lcp_kind_gate', 'off_slider_fold_lazy', 'off_hero_lqip_after_paint', 'off_hero_lqip_combined', 'off_lcp_copy_low', 'off_el_stretch_fit', 'off_el_pin_reach', 'off_lcp_size_floor', 'ucss_heal', 'off_el_heal_px', 'theme_bg_cdn', 'etcache_live_inline', 'lscwp_keep_inline', 'lcp_bg_path_exact', 'lcp_link_header', 'cdn_directories', 'slider_lcp', 'lcp_paint_exact', 'ar_pin_width_only', 'force_delay_jquery', 'atf_wire_clear'];
    }
}

if (!function_exists('wpc_policy_cohort_class')) {
    /** 'nonrender', 'render', or 'unclassified' for a name in neither list (taken to change the page). */
    function wpc_policy_cohort_class($name)
    {
        $name = (string) $name;
        if (in_array($name, wpc_policy_nonrender_cohorts(), true)) {
            return 'nonrender';
        }
        return in_array($name, wpc_policy_render_cohorts(), true) ? 'render' : 'unclassified';
    }
}

if (!function_exists('wpc_policy_render_part')) {
    /**
     * A stable digest of the switches and levers that change what a page renders: the cohorts that are on and not in
     * wpc_policy_nonrender_cohorts() (a lever_<name> cohort only while a person's setting does not block that lever), the acting
     * levers a person's setting does not block, and the levers' scope.
     * Empty when none is on, so a site that has none signs its delivery configuration as it always did.
     */
    function wpc_policy_render_part($cohorts, $levers, $lists = null)
    {
        $cohortsOn = [];
        if (is_array($cohorts)) {
            foreach ($cohorts as $name => $value) {
                if ((int) $value === 1 && wpc_policy_cohort_class($name) !== 'nonrender') {
                    $leverOf = strpos((string) $name, 'lever_') === 0 ? substr((string) $name, 6) : '';
                    if ($leverOf !== '' && isset(wpc_lever_cohorts()[$leverOf]) && wpc_lever_blocked($leverOf)) {
                        continue;
                    }
                    $cohortsOn[] = (string) $name;
                }
            }
        }
        sort($cohortsOn);
        $leversOn = [];
        $scope = [];
        if (is_array($levers) && isset($levers['levers']) && is_array($levers['levers'])) {
            foreach (wpc_lever_acting_names() as $name) {
                if (!isset($levers['levers'][$name]) || wpc_lever_blocked($name, $levers['levers'][$name])) {
                    continue;
                }
                $value = $levers['levers'][$name];
                if (is_scalar($value)) {
                    $leversOn[$name] = (string) $value;
                } elseif (is_array($value)) {
                    $both = [];
                    foreach (['mobile', 'desktop'] as $device) {
                        $both[$device] = isset($value[$device]) && is_scalar($value[$device]) ? (string) $value[$device] : null;
                    }
                    if ($both['mobile'] !== null || $both['desktop'] !== null) {
                        $leversOn[$name] = $both;
                    }
                }
            }
            ksort($leversOn);
            if ($leversOn !== [] && isset($levers['scope']) && is_array($levers['scope'])) {
                $scope = array_values(array_unique(array_map('strval', $levers['scope'])));
                sort($scope);
            }
        }
        $listsOn = [];
        if (is_array($lists)) {
            foreach (wpc_policy_lists_allowed() as $key => $def) {
                $clean = isset($lists[$key]) && in_array($def['cohort'], $cohortsOn, true) ? wpc_policy_list_clean($key, $lists[$key]) : [];
                if ($clean !== []) {
                    $listsOn[$key] = $clean;
                }
            }
            ksort($listsOn);
        }
        if ($cohortsOn === [] && $leversOn === []) {
            return '';
        }
        return substr(md5(serialize($listsOn === [] ? [$cohortsOn, $leversOn, $scope] : [$cohortsOn, $leversOn, $scope, $listsOn])), 0, 12);
    }
}

if (!function_exists('wpc_policy_render_signature')) {
    function wpc_policy_render_signature()
    {
        return wpc_policy_render_part(wpc_policy_get('cohorts', []), wpc_policy_get('wpc_levers', null), wpc_policy_get('wpc_lists', null));
    }
}

if (!function_exists('wpc_lever_setting_keys')) {
    function wpc_lever_setting_keys($name)
    {
        $map = [
            'lazy_skip'     => ['lazySkipCount'],
            'delay_timeout' => ['delay-js-v3-timeout'],
            'delay_js'      => ['delay-js-v3', 'delay-js-v2', 'delay-js'],
            'delay_onload'  => ['maximum-mobile'],
            'menu_early'    => ['maximum-mobile'],
            'fonts_origin'  => ['replace-fonts'],
            'fetchpriority' => ['fetchpriority-high'],
            'lcp_density_cap' => ['retina'],
            'page_cache'    => ['cache.advanced'],
        ];
        return isset($map[(string) $name]) ? $map[(string) $name] : [];
    }
}

if (!function_exists('wpc_lever_kill_names')) {
    /** The levers that may turn their feature off over a person's saved choice, because off is the switch for breakage: never on over a person's off, and never a stored value rewritten. */
    function wpc_lever_kill_names()
    {
        return ['delay_js'];
    }
}

if (!function_exists('wpc_lever_value_off')) {
    function wpc_lever_value_off($value)
    {
        foreach ((array) $value as $one) {
            if (in_array($one, [0, '0', 'off', false], true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('wpc_lever_blocked')) {
    function wpc_lever_blocked($name, $value = null)
    {
        if (!function_exists('wpc_setting_chosen')) {
            return false;
        }
        if ($value !== null && in_array((string) $name, wpc_lever_kill_names(), true) && wpc_lever_value_off($value)) {
            return false;
        }
        foreach (wpc_lever_setting_keys($name) as $key) {
            if (wpc_setting_chosen($key)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('wpc_lever_path_norm')) {
    function wpc_lever_path_norm($path)
    {
        $path = rtrim(rawurldecode((string) $path), '/');
        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('wpc_lever_request_uri')) {
    function wpc_lever_request_uri()
    {
        $uri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        return function_exists('wp_unslash') ? (string) wp_unslash($uri) : $uri;
    }
}

if (!function_exists('wpc_lever_request_path')) {
    function wpc_lever_request_path()
    {
        $uri = wpc_lever_request_uri();
        if ($uri === '') {
            return null;
        }
        $q = strpos($uri, '?');
        $path = rawurldecode($q === false ? $uri : substr($uri, 0, $q));
        $home = function_exists('home_url') && function_exists('wp_parse_url') ? rtrim((string) wp_parse_url(home_url(), PHP_URL_PATH), '/') : '';
        if ($home !== '' && ($path === $home || strpos($path, $home . '/') === 0)) {
            $path = substr($path, strlen($home));
        }
        return wpc_lever_path_norm($path);
    }
}

if (!function_exists('wpc_lever_in_scope')) {
    function wpc_lever_in_scope($scope)
    {
        if (empty($scope) || !is_array($scope)) {
            return true;
        }
        $path = wpc_lever_request_path();
        if ($path === null) {
            return false;
        }
        foreach ($scope as $one) {
            if (wpc_lever_path_norm($one) === $path) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('wpc_lever_sign')) {
    function wpc_lever_sign($key, $path, $lever_query, $exp)
    {
        return hash_hmac('sha256', (string) $path . '|' . (string) $lever_query . '|' . (string) $exp, (string) $key);
    }
}

if (!function_exists('wpc_lever_test_request')) {
    function wpc_lever_test_request($now = null)
    {
        $uri = wpc_lever_request_uri();
        $pos = strpos($uri, '?');
        if ($pos === false || strpos($uri, 'wpc_sig=') === false) {
            return null;
        }
        static $memo = [];
        $memo_key = $now === null ? $uri . "\0" . (function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '') : null;
        if ($memo_key !== null && array_key_exists($memo_key, $memo)) {
            return $memo[$memo_key];
        }
        $verdict = wpc_lever_test_verify($uri, $pos, $now);
        if ($memo_key !== null) {
            $memo[$memo_key] = $verdict;
        }
        return $verdict;
    }
}

if (!function_exists('wpc_lever_test_verify')) {
    function wpc_lever_test_verify($uri, $pos, $now)
    {
        $path = substr($uri, 0, $pos);
        parse_str(substr($uri, $pos + 1), $q);
        $sig = isset($q['wpc_sig']) && is_string($q['wpc_sig']) ? strtolower($q['wpc_sig']) : '';
        $exp = isset($q['wpc_exp']) && is_string($q['wpc_exp']) && ctype_digit($q['wpc_exp']) && strlen($q['wpc_exp']) <= 12 ? $q['wpc_exp'] : '';
        if (strlen($sig) !== 64 || !ctype_xdigit($sig) || $exp === '') {
            return null;
        }
        $t = $now === null ? time() : (int) $now;
        if ((int) $exp < $t || (int) $exp > $t + 600) {
            return null;
        }
        $params = [];
        foreach ($q as $k => $v) {
            if (strpos((string) $k, 'wpc_lever_') !== 0) {
                continue;
            }
            if (!is_string($v)) {
                return null;
            }
            $params[(string) $k] = $v;
        }
        if (empty($params)) {
            return null;
        }
        ksort($params, SORT_STRING);
        $pairs = [];
        foreach ($params as $k => $v) {
            $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        $key = function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '';
        if ($key === '' || !hash_equals(wpc_lever_sign($key, $path, implode('&', $pairs), $exp), $sig)) {
            return null;
        }
        $out = ['exp' => (int) $exp, 'test' => 0, 'levers' => []];
        foreach ($params as $k => $v) {
            $n = substr($k, strlen('wpc_lever_'));
            if ($n === 'test') {
                $out['test'] = $v === '1' ? 1 : 0;
            } elseif (preg_match('/^[a-z][a-z0-9_]{0,39}$/', $n)) {
                $out['levers'][$n] = $v;
            }
        }
        return $out;
    }
}

if (!function_exists('wpc_lever_test_active')) {
    function wpc_lever_test_active()
    {
        return wpc_lever_test_request() !== null;
    }
}

if (!function_exists('wpc_lever')) {
    function wpc_lever($name)
    {
        $name = (string) $name;
        $value = null;
        $set = false;
        $signed = wpc_lever_test_request();
        if (is_array($signed) && array_key_exists($name, $signed['levers'])) {
            $value = $signed['levers'][$name];
            $set = true;
        } else {
            $levers = wpc_policy_get('wpc_levers', null);
            if (is_array($levers) && isset($levers['levers'][$name]) && wpc_lever_in_scope(isset($levers['scope']) ? $levers['scope'] : [])) {
                $value = $levers['levers'][$name];
                if (is_array($value)) {
                    $device = function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile() ? 'mobile' : 'desktop';
                    $value = isset($value[$device]) && is_scalar($value[$device]) ? $value[$device] : null;
                }
                $set = $value !== null;
            }
        }
        if (!$set) {
            return wpc_lever_cohort_on($name) ? 1 : null;
        }
        if (wpc_lever_blocked($name, $value)) {
            return null;
        }
        return $value;
    }
}

if (!function_exists('wpc_lever_num')) {
    function wpc_lever_num($name, $default, $min, $max)
    {
        try {
            return wpc_lever_num_pick(wpc_lever($name), $default, $min, $max);
        } catch (\Throwable $e) {
            return $default;
        }
    }

    function wpc_lever_num_pick($value, $default, $min, $max)
    {
        if (is_array($value)) {
            $device = function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile() ? 'mobile' : 'desktop';
            $value = isset($value[$device]) && is_scalar($value[$device]) ? $value[$device] : null;
        }
        if ($value === null || is_bool($value) || !is_numeric($value) || !is_finite((float) $value)) {
            return $default;
        }
        $n = max((float) $min, min((float) $max, (float) $value));
        return is_float($default) ? $n : (int) round($n);
    }
}

if (!function_exists('wpc_policy_echo_fields')) {
    function wpc_policy_echo_fields()
    {
        $f = [
            'policy_version'      => (int) wpc_policy_version(),
            'levers_rev_seen'     => 0,
            'levers_unknown'      => [],
            'levers_overridden'   => [],
            'plain_paths_seen'    => wpc_policy_cohort('plain_paths'),
            'encode_on_view_seen' => wpc_policy_cohort('encode_on_view'),
            'tls_verify_seen'     => wpc_policy_cohort('tls_verify'),
            'eov_formats_off_seen' => wpc_policy_cohort('eov_formats_off'),
        ];
        $f['policy_version_seen'] = $f['policy_version'];
        $seen = [];
        foreach ((array) wpc_policy_get('cohorts', []) as $name => $v) {
            $seen[(string) $name] = (int) $v === 1 ? 1 : 0;
        }
        $f['cohorts_seen'] = (object) $seen;
        if ((string) wpc_policy_get('source', '') === 'builtin') {
            $f['cohorts_builtin_seen'] = 1;
        }
        $f['wpc_lists'] = (object) wpc_policy_lists_counts();
        $f['policy_effective'] = ['serve' => (string) wpc_policy_serve(), 'land' => (string) wpc_policy_land()];
        $levers = wpc_policy_get('wpc_levers', null);
        if (is_array($levers)) {
            $f['levers_rev_seen'] = (int) $levers['rev'];
            foreach (array_keys($levers['levers']) as $name) {
                if (!in_array($name, wpc_lever_acting_names(), true)) {
                    $f['levers_unknown'][] = (string) $name;
                }
                if (wpc_lever_blocked($name, $levers['levers'][$name])) {
                    $f['levers_overridden'][] = (string) $name;
                }
            }
        }
        if (function_exists('wpc_connected_at') && wpc_connected_at() > 0) {
            $f['connected_at'] = gmdate('Y-m-d\TH:i:s\Z', wpc_connected_at());
        }
        if (function_exists('wpc_auto_mode_on')) {
            $f['auto_mode'] = wpc_auto_mode_on() ? 1 : 0;
        }
        $tls = function_exists('get_option') ? get_option('wpc_tls_verify_fail', null) : null;
        if (is_array($tls) && (int) ($tls['at'] ?? 0) > 0) {
            $f['tls_verify_fail_at'] = gmdate('Y-m-d\TH:i:s\Z', (int) $tls['at']);
        }
        $mismatch = function_exists('get_option') ? get_option('wpc_cb_sig_mismatch', null) : null;
        $f['sig_mismatch_seen'] = is_array($mismatch) ? max(0, (int) ($mismatch['n'] ?? 0)) : 0;
        if (is_array($mismatch) && (int) ($mismatch['at'] ?? 0) > 0) {
            $f['sig_mismatch_at'] = gmdate('Y-m-d\TH:i:s\Z', (int) $mismatch['at']);
        }
        if (function_exists('wpc_crit_off_state')) {
            $off = wpc_crit_off_state();
            if (is_array($off)) {
                $f['crit_off'] = $off;
            }
        }
        if (function_exists('wpc_sized_health_echo')) {
            $f = array_merge($f, wpc_sized_health_echo());
        }
        if (function_exists('wpc_site_noprescr_counts')) {
            $f = array_merge($f, wpc_site_noprescr_counts());
        }
        if (function_exists('wpc_host_cache_echo')) {
            $f = array_merge($f, wpc_host_cache_echo());
        }
        if (function_exists('wpc_crit_leg_echo')) {
            $f = array_merge($f, wpc_crit_leg_echo());
        }
        return $f;
    }
}

if (!function_exists('wpc_callback_sig_mismatch_note')) {
    function wpc_callback_sig_mismatch_note($uuid, array $tried, $why, $enforced)
    {
        $seen = function_exists('get_option') ? get_option('wpc_cb_sig_mismatch', null) : null;
        $n = is_array($seen) ? max(0, (int) ($seen['n'] ?? 0)) : 0;
        $fresh = is_array($seen) && abs(time() - (int) ($seen['at'] ?? 0)) < 60;
        if (!$fresh && function_exists('update_option')) {
            $n++;
            update_option('wpc_cb_sig_mismatch', ['n' => $n, 'at' => time()], false);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($enforced ? 'callback-sig-refused' : 'callback-sig-mismatch', '', '',
                ['uuid' => substr((string) $uuid, 0, 8), 'tried' => implode('+', $tried), 'why' => (string) $why, 'n' => $n]);
        }
        return $n;
    }
}

if (!function_exists('wpc_policy_transition')) {
    function wpc_policy_transition($changed)
    {
        if (!is_array($changed) || empty($changed)) {
            return;
        }
        if (isset($changed['land']) && $changed['land'][1] === 'on' && $changed['land'][0] === 'off') {
            wpc_policy_materialize();
        }
        if (isset($changed['serve']) && function_exists('wpc_crit_invalidate')) {
            wpc_crit_invalidate('all', 'policy-serve', 'stale');
        }
        if (isset($changed['formats']) && function_exists('update_option')) {
            $s = get_option(WPS_IC_SETTINGS, []);
            $s = is_array($s) ? $s : [];
            $map = ['avif' => 'auto', 'webp' => 'webp', 'off' => 'off'];
            $rank = ['off' => 0, 'webp' => 1, 'avif' => 2];
            $want = $changed['formats'][1];
            if (isset($map[$want]) && class_exists('WPC_Delivery_Resolver') && defined('WPC_Delivery_Resolver::NEXTGEN_OPTION')) {
                $owner = (string) WPC_Delivery_Resolver::effective_ceiling($s);
                $owner_rank = isset($rank[$owner]) ? $rank[$owner] : 2;
                if ($rank[$want] < $owner_rank) {
                    $s[WPC_Delivery_Resolver::NEXTGEN_OPTION] = $map[$want];
                    if (function_exists('wpc_settings_written_as')) {
                        wpc_settings_written_as('policy', function () use ($s) {
                            update_option(WPS_IC_SETTINGS, $s);
                        });
                    } else {
                        update_option(WPS_IC_SETTINGS, $s);
                    }
                } elseif ($rank[$want] > $owner_rank && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('policy-formats-kept', '', '', ['owner' => $owner, 'policy' => $want]);
                }
            }
        }
        if (isset($changed['serve']) || isset($changed['scope'])) {
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeAllCache')) {
                try {
                    wps_ic_cache::purgeAllCache();
                } catch (\Throwable $e) {
                }
            }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('policy-changed', '', '', ['changed' => $changed, 'version' => wpc_policy_version()]);
        }
        do_action('wpc_policy23_changed', $changed);
    }
}


if (!function_exists('wpc_v2_fetch_variants_list')) {
    function wpc_v2_fetch_variants_list($cursor = '', $limit = 200)
    {
        $orch   = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        if ($orch === '' || $apikey === '') {
            return ['ok' => false, 'reason' => 'not_connected'];
        }
        $query = 'apikey=' . rawurlencode($apikey) . '&cursor=' . rawurlencode((string) $cursor) . '&limit=' . (int) $limit;
        // The service signs the DECODED values (`apikey=<k>&cursor=<c>&limit=<n>`, server.js
        // /optimize-v2/variants/list). Observed: the plugin signed the url-encoded query, so the
        // first page (empty cursor) verified and every later page, whose cursor is a store key
        // with slashes (%2F on the wire), answered 401 hmac_signature_mismatch.
        $signed = 'apikey=' . $apikey . '&cursor=' . (string) $cursor . '&limit=' . (int) $limit;
        $resp = wp_remote_get(rtrim($orch, '/') . '/optimize-v2/variants/list?' . $query, [
            'timeout' => 15,
            'headers' => wpc_v2_service_sign($apikey, $signed),
        ]);
        if (is_wp_error($resp)) {
            return ['ok' => false, 'reason' => 'transport', 'detail' => preg_replace('~apikey=[^&\s]*~i', 'apikey=*', (string) $resp->get_error_message())];
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code < 200 || $code >= 300 || !is_array($body) || empty($body['ok'])) {
            return ['ok' => false, 'reason' => 'http_' . $code, 'detail' => is_array($body) && isset($body['error']) ? (string) $body['error'] : ''];
        }
        return [
            'ok'          => true,
            'entries'     => isset($body['entries']) && is_array($body['entries']) ? $body['entries'] : [],
            'next_cursor' => isset($body['next_cursor']) ? (string) $body['next_cursor'] : '',
            'total'       => isset($body['total']) ? (int) $body['total'] : 0,
        ];
    }
}

if (!function_exists('wpc_policy_store_entry_to_manifest')) {
    function wpc_policy_store_entry_to_manifest($e)
    {
        if (!is_array($e) || empty($e['path']) || empty($e['url'])) {
            return null;
        }
        $path = '/' . ltrim((string) $e['path'], '/');
        $ext  = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== 'avif' && $ext !== 'webp') {
            return null;
        }
        $stem = substr($path, 0, -(strlen($ext) + 1));
        $up   = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        $base_path = !empty($up['baseurl']) ? (string) wp_parse_url($up['baseurl'], PHP_URL_PATH) : '/wp-content/uploads';
        $orig_ext = 'jpg';
        if (!empty($up['basedir']) && strpos($stem, rtrim($base_path, '/') . '/') === 0) {
            $rel = substr($stem, strlen(rtrim($base_path, '/')));
            $src_stem = preg_replace('/-\d{2,4}x\d{2,4}$/', '', $rel);
            // The source beside the stem, from its owner (png before jpg: a .jpg beside a .png is
            // our twin, ticket 12231).
            $source = function_exists('wpc_v2_source_beside') ? wpc_v2_source_beside(rtrim($up['basedir'], '/') . $src_stem . '.' . $ext) : '';
            if ($source !== '') {
                $orig_ext = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
            }
        }
        $home = function_exists('site_url') ? rtrim(site_url(), '/') : '';
        return [
            'origin_url' => $home . $stem . '.' . $orig_ext,
            'filename'   => basename($path),
            'sizeLabel'  => '',
            'format'     => $ext,
            'fetchUrl'   => (string) $e['url'],
            'sha256'     => isset($e['sha256']) ? (string) $e['sha256'] : '',
            'bytes'      => isset($e['bytes']) ? (int) $e['bytes'] : 0,
            'lazy_cdn'   => 1,
        ];
    }
}

if (!function_exists('wpc_policy_materialize')) {
    function wpc_policy_materialize($cursor = '')
    {
        if (!apply_filters('wpc_policy23_materialize_on', true)) {
            return 0;
        }
        if (function_exists('update_site_option')) {
            update_site_option('wpc_v2_pull_enabled', 1);
        }
        $landed = 0;
        $refused = [];
        $list = wpc_v2_fetch_variants_list($cursor, 200);
        if (!empty($list['ok'])) {
            foreach ($list['entries'] as $e) {
                $m = wpc_policy_store_entry_to_manifest($e);
                if ($m === null) {
                    continue;
                }
                if (function_exists('wpc_v2_lazy_cdn_ingest_state')) {
                    $state = wpc_v2_lazy_cdn_ingest_state($m);
                    if ($state === 'landed') {
                        $landed++;
                    } elseif ($state === 'refused' && count($refused) < 50) {
                        $refused[] = [
                            'imageID'   => '',
                            'sizeLabel' => '',
                            'format'    => (string) $m['format'],
                            'reason'    => isset($GLOBALS['wpc_v2_lazy_last_outcome']) ? (string) $GLOBALS['wpc_v2_lazy_last_outcome'] : 'refused',
                        ] + ($m['sha256'] !== '' ? ['sha256' => $m['sha256']] : []);
                    }
                } elseif (function_exists('wpc_v2_lazy_cdn_ingest') && wpc_v2_lazy_cdn_ingest($m)) {
                    $landed++;
                }
            }
            if (!empty($refused) && function_exists('wpc_v2_pull_manifest_ack')) {
                wpc_v2_pull_manifest_ack([], ['seen' => count($list['entries']), 'ingested' => $landed, 'failed' => $refused]);
            }
            if ($list['next_cursor'] !== '') {
                if (!(function_exists('wpc_pl_sched') && wpc_pl_sched(time() + 5, WPC_POLICY_MATERIALIZE_HOOK, [$list['next_cursor']]))
                    && function_exists('wp_schedule_single_event')) {
                    wp_schedule_single_event(time() + 5, WPC_POLICY_MATERIALIZE_HOOK, [$list['next_cursor']]);
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('policy-materialize', '', '', ['source' => 'store', 'landed' => $landed, 'refused' => count($refused), 'entries' => count($list['entries']), 'next' => $list['next_cursor'] !== '' ? 1 : 0]);
            }
            return $landed;
        }
        if (function_exists('wpc_v2_pull_set_cursor')) {
            wpc_v2_pull_set_cursor(0);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('policy-materialize', '', '', ['source' => 'manifest', 'reason' => isset($list['reason']) ? $list['reason'] : '']);
        }
        if (function_exists('wpc_pl_sched')) {
            wpc_pl_sched(time() + 5, 'wpc_v2_pull_cron');
        } elseif (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 5, 'wpc_v2_pull_cron');
        }
        return $landed;
    }
    add_action(WPC_POLICY_MATERIALIZE_HOOK, 'wpc_policy_materialize', 10, 1);
}
if (!function_exists('wpc_policy_tighten')) {
    function wpc_policy_tighten($key, $value)
    {
        $local = wpc_policy_local();
        if ($key === 'serve') {
            if ($value === 'origin') {
                $local['serve'] = 'origin';
            } else {
                unset($local['serve']);
            }
        } elseif ($key === 'land') {
            if ($value === 'on') {
                $local['land'] = 'on';
            } else {
                unset($local['land']);
            }
        } else {
            return false;
        }
        $before = wpc_policy();
        update_option(WPC_POLICY_LOCAL_OPTION, $local, false);
        $after = $before;
        $after[$key] = $value;
        $stored = wpc_policy_stored();
        if ($key === 'serve' && $value !== 'origin') {
            $after['serve'] = is_array($stored) && isset($stored['serve']) ? $stored['serve'] : wpc_policy_defaults()['serve'];
        }
        if ($key === 'land' && $value !== 'on') {
            $after['land'] = is_array($stored) && isset($stored['land']) ? $stored['land'] : 'on';
        }
        if ($after[$key] !== $before[$key]) {
            wpc_policy_transition([$key => [$before[$key], $after[$key]]]);
        }
        return true;
    }
}

if (!function_exists('wpc_policy_resync')) {
    function wpc_policy_resync()
    {
        if (!function_exists('wpc_v2_config_sync_site')) {
            return false;
        }
        $r = wpc_v2_config_sync_site();
        return !(is_array($r) && isset($r['reason']) && $r['reason'] === 'not_connected');
    }
}

if (!function_exists('wpc_policy_schedule_resync')) {
    function wpc_policy_schedule_resync($delay = 5)
    {
        if (function_exists('wp_next_scheduled') && wp_next_scheduled(WPC_POLICY_RESYNC_HOOK)) {
            return false;
        }
        if (function_exists('wpc_pl_sched') && wpc_pl_sched(time() + (int) $delay, WPC_POLICY_RESYNC_HOOK)) {
            return true;
        }
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + (int) $delay, WPC_POLICY_RESYNC_HOOK);
            return true;
        }
        return false;
    }
}

if (!function_exists('wpc_policy_clock')) {
    /** Seconds, as a float: the clock the drain's waits, caps and pull starts read. */
    function wpc_policy_clock()
    {
        return microtime(true);
    }
}

if (!function_exists('wpc_policy_sleep')) {
    /** The one place the drain waits. */
    function wpc_policy_sleep($seconds)
    {
        usleep((int) round(max(0.0, (float) $seconds) * 1000000));
    }
}

if (!function_exists('wpc_policy_now_ms')) {
    /** The clock the owed mark and a pull's start are stamped with, in milliseconds. */
    function wpc_policy_now_ms()
    {
        return (int) round(wpc_policy_clock() * 1000);
    }
}

if (!function_exists('wpc_policy_opt_fresh')) {
    /** One option read that skips a stale object-cache copy, for the marks several processes write. */
    function wpc_policy_opt_fresh($key, $default = 0)
    {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($key, 'options');
        }
        return get_option($key, $default);
    }
}

if (!function_exists('wpc_policy_note_wake')) {
    /** Records that a pull is owed: the time of the latest wake, always later than the start of any pull in flight. */
    function wpc_policy_note_wake()
    {
        $run   = wpc_policy_opt_fresh('wpc_policy23_resync_run', null);
        $floor = max((int) wpc_policy_opt_fresh('wpc_policy23_resync_owed', 0), is_array($run) ? (int) $run['start'] : 0);
        $mark  = max(wpc_policy_now_ms(), $floor + 1);
        update_option('wpc_policy23_resync_owed', $mark, false);
        return $mark;
    }
}

if (!function_exists('wpc_policy_owed')) {
    /** The time of the latest wake no pull has covered yet, 0 when none is owed. */
    function wpc_policy_owed()
    {
        $owed = (int) wpc_policy_opt_fresh('wpc_policy23_resync_owed', 0);
        return $owed > (int) wpc_policy_opt_fresh('wpc_policy23_resync_served', 0) ? $owed : 0;
    }
}

if (!function_exists('wpc_policy_claim')) {
    /** Takes a one-holder mark (add_option is the lock), taking over one older than $stale seconds. */
    function wpc_policy_claim($key, $value, $stale)
    {
        if (add_option($key, $value, '', 'no')) {
            return true;
        }
        $held = wpc_policy_opt_fresh($key, null);
        $at   = is_array($held) ? (int) (isset($held['at']) ? $held['at'] : 0) : (int) $held;
        if ($at > 0 && time() - $at < (int) $stale) {
            return false;
        }
        delete_option($key);
        return (bool) add_option($key, $value, '', 'no');
    }
}

if (!function_exists('wpc_policy_lane_ok')) {
    /** A pull runs only in the request of a signed wake or in WP-cron, never in a visitor's. */
    function wpc_policy_lane_ok($lane)
    {
        if ($lane === 'wake') {
            return !empty($GLOBALS['wpc_signed_wake_request']);
        }
        return function_exists('wp_doing_cron') ? (bool) wp_doing_cron() : (defined('DOING_CRON') && DOING_CRON);
    }
}

if (!function_exists('wpc_policy_pull_run')) {
    /**
     * One policy pull (the /v2/config sync), one at a time per site. A success marks every wake
     * up to the pull's start as served; a wake after the start stays owed.
     */
    function wpc_policy_pull_run($stage)
    {
        if (!function_exists('wpc_v2_config_sync_site')) {
            return ['ran' => false, 'ok' => false, 'why' => 'absent'];
        }
        if (function_exists('wpc_mc_up') && !wpc_mc_up()) {
            return ['ran' => false, 'ok' => false, 'why' => 'mc_breaker_open'];
        }
        $start = max(wpc_policy_now_ms(), (int) wpc_policy_opt_fresh('wpc_policy23_resync_owed', 0));
        if (!wpc_policy_claim('wpc_policy23_resync_run', ['start' => $start, 'at' => time()], 120)) {
            return ['ran' => false, 'ok' => false, 'why' => 'busy'];
        }
        $t0 = wpc_policy_clock();
        try {
            $r = wpc_v2_config_sync_site();
        } finally {
            delete_option('wpc_policy23_resync_run');
        }
        $ok     = is_array($r) && !empty($r['ok']);
        $reason = is_array($r) && isset($r['reason']) ? (string) $r['reason'] : '';
        if ($ok) {
            update_option('wpc_policy23_pulled_at', time(), false);
            update_option('wpc_policy23_resync_served', max($start, (int) wpc_policy_opt_fresh('wpc_policy23_resync_served', 0)), false);
            delete_option('wpc_policy23_pull_fails');
            delete_option('wpc_policy23_pull_retry_at');
        } elseif ($reason === 'not_connected') {
            update_option('wpc_policy23_resync_served', max($start, (int) wpc_policy_opt_fresh('wpc_policy23_resync_served', 0)), false);
        } else {
            $fails = (int) get_option('wpc_policy23_pull_fails', 0) + 1;
            update_option('wpc_policy23_pull_fails', $fails, false);
            update_option('wpc_policy23_pull_retry_at', time() + min(900, 30 * (1 << min($fails - 1, 5))), false);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('policy-pull-' . $stage, '', '', [
                'ok'     => $ok ? 1 : 0,
                'reason' => $reason,
                'http'   => is_array($r) && isset($r['http_code']) ? (int) $r['http_code'] : 0,
                'ms'     => (int) round((wpc_policy_clock() - $t0) * 1000),
                'owed'   => wpc_policy_owed() > 0 ? 1 : 0,
                'policy' => function_exists('wpc_policy_version') ? (int) wpc_policy_version() : -1,
            ]);
        }
        return ['ran' => true, 'ok' => $ok, 'why' => $reason];
    }
}

if (!function_exists('wpc_policy_drain_owed')) {
    /**
     * Pulls what is owed, at most twice per drainer and one drainer at a time per site. The wake lane
     * (response already released) waits until no wake has arrived for a few seconds, never longer than
     * 20 s from the start, pulls, and pulls once more if a wake landed after that pull started; the cron
     * lane pulls at once. What is still owed after that, or after a failure, an open breaker or a backoff,
     * goes to the cron event. A wake on a host that cannot release the response only schedules that event.
     */
    function wpc_policy_drain_owed($lane)
    {
        if (!wpc_policy_lane_ok($lane) || wpc_policy_owed() === 0) {
            return 0;
        }
        if ($lane === 'wake' && empty($GLOBALS['wpc_response_released'])) {
            wpc_policy_schedule_resync(5);
            return 0;
        }
        $waits    = $lane === 'wake';
        $quiet_ms = (int) round(max(0.0, (float) apply_filters('wpc_policy_settle_s', 3)) * 1000);
        $deadline = wpc_policy_clock() + max(0.0, (float) apply_filters('wpc_policy_drain_cap_s', 20));
        $pulls    = 0;
        $halted   = '';
        if (wpc_policy_claim('wpc_policy23_resync_drainer', time(), 120)) {
            try {
                while ($pulls < 2) {
                    if ((int) get_option('wpc_policy23_pull_retry_at', 0) > time()) {
                        $halted = 'backoff';
                        break;
                    }
                    while ($waits && ($owed = wpc_policy_owed()) > 0 && wpc_policy_now_ms() - $owed < $quiet_ms && wpc_policy_clock() < $deadline) {
                        wpc_policy_sleep(0.25);
                    }
                    if (wpc_policy_owed() === 0) {
                        break;
                    }
                    $res = wpc_policy_pull_run($pulls === 0 ? $lane : 'trailing');
                    if (!$res['ran']) {
                        if ($res['why'] === 'busy' && $waits && wpc_policy_clock() < $deadline) {
                            wpc_policy_sleep(0.5);
                            update_option('wpc_policy23_resync_drainer', time(), false);
                            continue;
                        }
                        $halted = (string) $res['why'];
                        break;
                    }
                    $pulls++;
                    if (!$res['ok']) {
                        $halted = 'failed';
                        break;
                    }
                    update_option('wpc_policy23_resync_drainer', time(), false);
                }
            } finally {
                delete_option('wpc_policy23_resync_drainer');
            }
        }
        if (wpc_policy_owed() > 0) {
            $delay = max(30, (int) get_option('wpc_policy23_pull_retry_at', 0) - time());
            wpc_policy_schedule_resync($halted === 'mc_breaker_open' ? max(60, $delay) : $delay);
        }
        return $pulls;
    }
}

if (!function_exists('wpc_policy_resync_run')) {
    /** The cron event of a wake: pulls only if a wake is still owed. */
    function wpc_policy_resync_run()
    {
        return wpc_policy_drain_owed('cron');
    }
    add_action(WPC_POLICY_RESYNC_HOOK, 'wpc_policy_resync_run');
}

if (!function_exists('wpc_policy_poll_interval')) {
    /** Seconds between periodic policy pulls: filter wpc_policy_poll_interval, default 3600, never under 600. */
    function wpc_policy_poll_interval()
    {
        return max(600, (int) apply_filters('wpc_policy_poll_interval', 3600));
    }
}

if (!function_exists('wpc_policy_poll_jitter')) {
    /** The site's fixed offset in [0, interval), from a hash of its key and address. */
    function wpc_policy_poll_jitter($interval = null)
    {
        $interval = $interval === null ? wpc_policy_poll_interval() : max(1, (int) $interval);
        $seed = (function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '') . '|' . (function_exists('site_url') ? (string) site_url() : '');
        return (int) (hexdec(substr(md5('wpc-policy-poll|' . $seed), 0, 8)) % $interval);
    }
}

if (!function_exists('wpc_policy_poll_due')) {
    /** True when the last successful policy pull (or any successful /v2/config sync, which carries the policy) is older than interval plus jitter. */
    function wpc_policy_poll_due()
    {
        $interval = wpc_policy_poll_interval();
        $last     = max((int) get_option('wpc_policy23_pulled_at', 0), (int) get_option('wpc_v2_config_synced_at', 0));
        return $last <= 0 || time() - $last >= $interval + wpc_policy_poll_jitter($interval);
    }
}

if (!function_exists('wpc_policy_poll_tick')) {
    /** The slow periodic pull, run from WP-cron: finishes an owed pull, else pulls when the site is due. */
    function wpc_policy_poll_tick()
    {
        if (!wpc_policy_lane_ok('cron')) {
            return false;
        }
        if (!function_exists('wpc_v2_get_apikey') || (string) wpc_v2_get_apikey() === '') {
            return false;
        }
        if (wpc_policy_owed() > 0) {
            return wpc_policy_drain_owed('cron') > 0;
        }
        if (!wpc_policy_poll_due() || (int) get_option('wpc_policy23_pull_retry_at', 0) > time()) {
            return false;
        }
        $res = wpc_policy_pull_run('periodic');
        return !empty($res['ran']);
    }
    add_action('wpc_v2_pull_cron', 'wpc_policy_poll_tick', 30);
    add_action('wpc_policy23_poll', 'wpc_policy_poll_tick');
}

if (!function_exists('wpc_policy_poll_ensure')) {
    /** A site whose 5-minute pull event is not scheduled (pull off) gets its own 5-minute event for the policy poll; a site with the pull event needs none. */
    function wpc_policy_poll_ensure()
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }
        $connected = function_exists('wpc_v2_get_apikey') && (string) wpc_v2_get_apikey() !== '';
        if (!$connected || wp_next_scheduled('wpc_v2_pull_cron')) {
            if (wp_next_scheduled('wpc_policy23_poll') && function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook('wpc_policy23_poll');
            }
            return;
        }
        if (!wp_next_scheduled('wpc_policy23_poll')) {
            wp_schedule_event(time() + 60 + (wpc_policy_poll_jitter(300)), 'wpc_v2_5min', 'wpc_policy23_poll');
        }
    }
    add_action('init', 'wpc_policy_poll_ensure', 25);
}

if (!function_exists('wpc_v2_attachment_paths')) {
    function wpc_v2_attachment_paths($imageID)
    {
        $imageID = (int) $imageID;
        $paths = [];
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        $base_path = !empty($up['baseurl']) ? rtrim((string) wp_parse_url($up['baseurl'], PHP_URL_PATH), '/') : '/wp-content/uploads';
        $file = function_exists('get_post_meta') ? (string) get_post_meta($imageID, '_wp_attached_file', true) : '';
        if ($file === '') {
            return $paths;
        }
        $dir = (strpos($file, '/') !== false) ? substr($file, 0, strrpos($file, '/') + 1) : '';
        $paths[] = $base_path . '/' . ltrim($file, '/');
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($imageID) : [];
        if (is_array($meta)) {
            if (!empty($meta['original_image'])) {
                $paths[] = $base_path . '/' . $dir . $meta['original_image'];
            }
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $s) {
                    if (is_array($s) && !empty($s['file'])) {
                        $paths[] = $base_path . '/' . $dir . $s['file'];
                    }
                }
            }
        }
        if (class_exists('wps_ic_image_variants')) {
            $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
            foreach (wps_ic_image_variants::landed_stems($imageID) as $name) {
                $paths[] = $base_path . '/' . $dir . $name . '.' . $ext;
            }
        }
        return array_values(array_unique($paths));
    }
}

if (!function_exists('wpc_v2_attachment_stems')) {
    function wpc_v2_attachment_stems($paths)
    {
        $stems = [];
        foreach ((array) $paths as $p) {
            $p = (string) $p;
            if ($p === '' || strpos($p, '.') === false) {
                continue;
            }
            $stem = preg_replace('/(?:-scaled)?(?:-\d+x\d+)?\.[a-z0-9]+$/i', '', $p);
            if (is_string($stem) && $stem !== '' && $stem !== $p) {
                $stems[$stem] = 1;
            }
        }
        foreach (array_keys($stems) as $stem) {
            if (preg_match('/^(.+)-\d+w$/', $stem, $m) && isset($stems[$m[1]])) {
                unset($stems[$stem]);
            }
        }
        return array_keys($stems);
    }
}
if (!function_exists('wpc_v2_forget_variants')) {
    function wpc_v2_forget_variants($imageID, $reason = 'delete', $paths = [], $exact = false)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) {
            return false;
        }
        if (function_exists('get_transient') && get_transient('wpc_forget_done14_' . $imageID) === (string) $reason) {
            return true;
        }
        if ($exact && (!is_array($paths) || empty($paths))) {
            return true;
        }
        $paths = is_array($paths) && !empty($paths) ? $paths : wpc_v2_attachment_paths($imageID);
        $orch   = function_exists('wpc_v2_orchestrator_url') ? wpc_v2_orchestrator_url() : '';
        $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
        $store_ok = null;
        if ($orch !== '' && $apikey !== '' && !empty($paths) && function_exists('wpc_v2_manifest_sign_body')) {
            $body_raw = wp_json_encode(['paths' => array_values($paths), 'stems' => $exact ? [] : wpc_v2_attachment_stems($paths), 'image_ids' => [(int) $imageID], 'reason' => (string) $reason]);
            $resp = wp_remote_post(rtrim($orch, '/') . '/optimize-v2/variants/delete?apikey=' . rawurlencode($apikey), [
                'timeout' => 8,
                'headers' => array_merge(['Content-Type' => 'application/json'], wpc_v2_manifest_sign_body($apikey, $body_raw)),
                'body'    => $body_raw,
            ]);
            $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
            $store_ok = ($code >= 200 && $code < 300);
            if ($store_ok && function_exists('set_transient')) {
                set_transient('wpc_forget_done14_' . $imageID, (string) $reason, 10 * MINUTE_IN_SECONDS);
            }
            if (!$store_ok && !get_transient('wpc_forget_retry23_' . $imageID)) {
                set_transient('wpc_forget_retry23_' . $imageID, 1, 15 * MINUTE_IN_SECONDS);
                $retry_args = $exact ? [$imageID, (string) $reason, $paths, true] : [$imageID, (string) $reason, $paths];
                if (!(function_exists('wpc_pl_sched') && wpc_pl_sched(time() + 300, WPC_VARIANT_FORGET_HOOK, $retry_args))
                    && function_exists('wp_schedule_single_event')) {
                    wp_schedule_single_event(time() + 300, WPC_VARIANT_FORGET_HOOK, $retry_args);
                }
            }
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('variant-forget', (string) $imageID, '', ['reason' => (string) $reason, 'paths' => count($paths), 'store' => $store_ok, 'http' => isset($code) ? (int) $code : null]);
        }
        return (bool) $store_ok;
    }
    add_action(WPC_VARIANT_FORGET_HOOK, 'wpc_v2_forget_variants', 10, 4);
}
if (!function_exists('wpc_v2_forget_variants_queue')) {
    function wpc_v2_forget_variants_queue($imageID, $reason)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) {
            return false;
        }
        if (!apply_filters('wpc_v2_variant_forget23_on', true)) {
            return false;
        }
        if (function_exists('get_transient') && get_transient('wpc_forget_q23_' . $imageID)) {
            return true;
        }
        if (function_exists('set_transient')) {
            set_transient('wpc_forget_q23_' . $imageID, (string) $reason, 60);
        }
        $paths = wpc_v2_attachment_paths($imageID);
        wpc_v2_variant_detach($imageID, (string) $reason, $paths);
        if (function_exists('wpc_pl_sched') && wpc_pl_sched(time() + 5, WPC_VARIANT_FORGET_HOOK, [$imageID, (string) $reason, $paths])) {
            return true;
        }
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + 5, WPC_VARIANT_FORGET_HOOK, [$imageID, (string) $reason, $paths]);
            return true;
        }
        return false;
    }
    function wpc_v2_variant_detach($imageID, $reason, $paths)
    {
        if (!function_exists('add_action') || empty($paths)) {
            return false;
        }
        add_action('shutdown', function () use ($imageID, $reason, $paths) {
            if (function_exists('wpc_finish_request') && empty($GLOBALS['wpc_response_released'])) {
                wpc_finish_request();
            }
            @ignore_user_abort(true);
            wpc_v2_forget_variants($imageID, $reason, $paths);
        }, 99);
        return true;
    }
    add_action('delete_attachment', function ($id) { wpc_v2_forget_variants_queue($id, 'delete'); }, 5);
    add_action('wpc_image_restored', function ($id) { wpc_v2_forget_variants_queue($id, 'restore'); }, 5);
    function wpc_v2_forget_variants_meta($meta_id, $object_id, $meta_key, $meta_value)
    {
        if ($meta_key === 'ic_status' && $meta_value === 'restored') {
            wpc_v2_forget_variants_queue((int) $object_id, 'restore');
        }
    }
    add_action('updated_post_meta', 'wpc_v2_forget_variants_meta', 10, 4);
    add_action('added_post_meta', 'wpc_v2_forget_variants_meta', 10, 4);
}

if (!function_exists('wpc_twin_floor_arm')) {
    function wpc_twin_floor_hook()
    {
        return 'wpc_twin_floor_run';
    }

    function wpc_twin_floor_claim_key()
    {
        return 'wpc_twin_floor_' . preg_replace('/[^0-9a-z]+/i', '_', defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : '0');
    }

    function wpc_twin_floor_state()
    {
        $state = get_option('wpc_twin_floor_state', []);
        return is_array($state) ? $state : [];
    }

    function wpc_twin_floor_save(array $state)
    {
        update_option('wpc_twin_floor_state', $state, true);
    }

    function wpc_twin_floor_url_path($abs)
    {
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        if (empty($up['basedir']) || empty($up['baseurl']) || strpos((string) $abs, (string) $up['basedir']) !== 0) {
            return '';
        }
        return rtrim((string) wp_parse_url($up['baseurl'], PHP_URL_PATH), '/') . substr((string) $abs, strlen((string) $up['basedir']));
    }

    /**
     * Claims the sweep of oversized twins once per plugin version (add_option is the claim) and
     * queues its first run; the claim does no work. A sweep that is not done, has nothing queued
     * and has not run for an hour (a run that died took its event with it) is queued again.
     */
    function wpc_twin_floor_arm()
    {
        if (!function_exists('wp_schedule_single_event') || !function_exists('wp_next_scheduled') || !defined('WPC_PLUGIN_VERSION')) {
            return;
        }
        $state = wpc_twin_floor_state();
        $claim_key = wpc_twin_floor_claim_key();
        $current = (string) ($state['v'] ?? '') === (string) WPC_PLUGIN_VERSION;
        $claimed = !$current && add_option($claim_key, time(), '', 'no');
        if (!$claimed && !$current) {
            $held = get_option($claim_key, 'saved');
            $claimed = is_numeric($held) && time() - (int) $held > 300;
        }
        if ($claimed) {
            wpc_twin_floor_save(['v' => (string) WPC_PLUGIN_VERSION, 'cursor' => 0, 'armed' => time(), 'done' => 0, 'runs' => 0, 'attachments' => 0, 'removed' => 0, 'bytes' => 0]);
            update_option($claim_key, 'saved');
            if (!wp_next_scheduled(wpc_twin_floor_hook())) {
                wp_schedule_single_event(time() + 180, wpc_twin_floor_hook());
            }
            return;
        }
        if (!empty($state) && empty($state['done']) && !wp_next_scheduled(wpc_twin_floor_hook())
            && time() - (int) (isset($state['t']) ? $state['t'] : (isset($state['armed']) ? $state['armed'] : 0)) > 3600) {
            wp_schedule_single_event(time() + 60, wpc_twin_floor_hook());
        }
    }

    /** What one attachment's removal still owes: the zone copies, the service's copy, the pages naming the twins. */
    function wpc_twin_floor_settle($id, array $removed)
    {
        $paths = [];
        foreach ($removed as $file) {
            $url = wpc_twin_floor_url_path($file);
            if ($url !== '') {
                $paths[] = $url;
            }
            if (function_exists('wpc_v2_enqueue_landed_purge') && function_exists('wpc_v2_get_apikey') && (string) wpc_v2_get_apikey() !== '') {
                wpc_v2_enqueue_landed_purge($file);
            }
        }
        if (!empty($paths) && apply_filters('wpc_v2_variant_forget23_on', true)) {
            $args = [(int) $id, 'twin-floor', array_values(array_unique($paths)), true];
            if (!(function_exists('wpc_pl_sched') && wpc_pl_sched(time() + 30, WPC_VARIANT_FORGET_HOOK, $args)) && function_exists('wp_schedule_single_event')) {
                wp_schedule_single_event(time() + 30, WPC_VARIANT_FORGET_HOOK, $args);
            }
        }
        if (function_exists('wpc_v2_purge_html_for_attachment')) {
            wpc_v2_purge_html_for_attachment((int) $id, 'twin-floor', 'hard');
        }
    }

    /**
     * One cron tick of the sweep: up to 50 attachments after the stored cursor, in id order, inside a time
     * budget. The next tick is queued before any work; the cursor is stored before and after each attachment and at the end. A server under pressure (wpc_under_pressure) does nothing and leaves the
     * queued tick to try again (`twin-floor-deferred`, at most once an hour). One `twin-floor` line per
     * tick; the tick that passes the last attachment ends the sweep with `twin-floor-done` and the totals.
     */
    function wpc_twin_floor_run()
    {
        global $wpdb;
        if (!wpc_policy_lane_ok('cron') || !class_exists('wps_ic_image_variants')) {
            return;
        }
        $state = wpc_twin_floor_state();
        if (empty($state) || !empty($state['done'])) {
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(wpc_twin_floor_hook());
            }
            return;
        }
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled') && !wp_next_scheduled(wpc_twin_floor_hook())) {
            wp_schedule_single_event(time() + 60, wpc_twin_floor_hook());
        }
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_twin_floor_shed')) {
                set_transient('wpc_twin_floor_shed', 1, 3600);
                wpc_cache_first_log('twin-floor-deferred', '', '', ['why' => 'pressure', 'cursor' => (int) ($state['cursor'] ?? 0)]);
            }
            return;
        }
        $batch = max(1, (int) apply_filters('wpc_twin_floor_batch', 50));
        $budget = (float) apply_filters('wpc_twin_floor_seconds', 8.0);
        $started = microtime(true);
        $mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif'];
        $ids = [];
        if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'get_col')) {
            $ids = (array) $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN (" . implode(',', array_fill(0, count($mimes), '%s')) . ") AND ID > %d ORDER BY ID ASC LIMIT %d",
                ...array_merge($mimes, [(int) ($state['cursor'] ?? 0), $batch])
            ));
        }
        if (empty($ids) && isset($wpdb) && is_object($wpdb) && !empty($wpdb->last_error)) {
            return;
        }
        if (!empty($state['open'])) {
            $state['skipped'] = (int) ($state['skipped'] ?? 0) + 1;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('twin-floor-skipped', '', '', ['attachment' => (int) $state['open'], 'skipped' => $state['skipped']]);
            }
            $state['open'] = 0;
        }
        $before = $state;
        $state['runs'] = (int) ($state['runs'] ?? 0) + 1;
        $state['t'] = time();
        $tick = ['attachments' => 0, 'removed' => 0, 'bytes' => 0];
        $finished = true;
        foreach ($ids as $id) {
            if ($tick['attachments'] > 0 && microtime(true) - $started >= $budget) {
                $finished = false;
                break;
            }
            $id = (int) $id;
            $state['cursor'] = $id;
            $state['open'] = $id;
            wpc_twin_floor_save($state);
            $res = wps_ic_image_variants::drop_oversized($id, 'twin-floor');
            $lost = count($res['paths']);
            if ($lost > 0) {
                wpc_twin_floor_settle($id, $res['paths']);
                $tick['removed'] += $lost;
                $tick['bytes'] += (int) $res['bytes'];
            }
            $tick['attachments']++;
            foreach (['attachments', 'removed', 'bytes'] as $key) {
                $state[$key] = (int) ($before[$key] ?? 0) + $tick[$key];
            }
            $state['open'] = 0;
            wpc_twin_floor_save($state);
        }
        $ended = $finished && count($ids) < $batch;
        if ($ended) {
            $state['done'] = 1;
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook(wpc_twin_floor_hook());
            }
        }
        wpc_twin_floor_save($state);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('twin-floor', '', '', ['cursor' => (int) $state['cursor'], 'attachments' => $tick['attachments'], 'removed' => $tick['removed'], 'bytes' => $tick['bytes'], 'done' => $ended ? 1 : 0]);
            if ($ended) {
                wpc_cache_first_log('twin-floor-done', '', '', ['attachments' => (int) $state['attachments'], 'removed' => (int) $state['removed'], 'bytes' => (int) $state['bytes'], 'runs' => (int) $state['runs']]);
            }
        }
        return $tick;
    }

    /** The sweep's state for the healthcheck: null when no sweep has been claimed on this site. */
    function wpc_twin_floor_report()
    {
        $state = wpc_twin_floor_state();
        if (empty($state)) {
            return null;
        }
        $out = [];
        foreach (['v', 'done', 'cursor', 'runs', 'attachments', 'removed', 'bytes', 'skipped', 't'] as $key) {
            $out[$key] = isset($state[$key]) ? $state[$key] : 0;
        }
        return $out;
    }

    if (function_exists('add_action') && !defined('SHORTINIT')) {
        add_action(wpc_twin_floor_hook(), 'wpc_twin_floor_run');
        add_action('init', 'wpc_twin_floor_arm', 22);
    }
}

if (!function_exists('wpc_policy_ui')) {
    function wpc_policy_ui()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_wpc_settings')) {
            return;
        }
        $p = wpc_policy();
        $stored = wpc_policy_stored();
        $orch_serve = is_array($stored) && isset($stored['serve']) ? $stored['serve'] : wpc_policy_defaults()['serve'];
        $orch_land  = is_array($stored) && isset($stored['land']) ? $stored['land'] : 'on';
        $cdn_serves = function_exists('wpc_cdn_drives_images') && wpc_cdn_drives_images();
        $smart = function_exists('wpc_get_optimization_mode') && wpc_get_optimization_mode() === 'lazy_cdn';
        $nonce = wp_create_nonce(WPC_POLICY_OPTION);
        $current = $p['serve'] === 'origin' ? 'origin' : (wpc_policy_land() === 'off' ? 'edge_only' : 'edge_land');
        $tile = get_option(WPS_IC_SETTINGS);
        $tile_on = is_array($tile) && isset($tile['live-cdn']) && (string) $tile['live-cdn'] === '1';
        $show_choices = $cdn_serves || ($tile_on && $current === 'origin');
        $choices = [
            'edge_land' => ['label' => __('CDN + this server', WPS_IC_TEXTDOMAIN), 'chip' => __('Recommended', WPS_IC_TEXTDOMAIN), 'chipClass' => 'wpc-chip--success', 'tip' => __('Keeps delivering optimized files if the CDN is off.', WPS_IC_TEXTDOMAIN), 'serve' => 'edge', 'land' => 'on', 'allowed' => ($orch_serve === 'edge')],
            'edge_only' => ['label' => __('CDN only', WPS_IC_TEXTDOMAIN), 'chip' => __('Saves Space', WPS_IC_TEXTDOMAIN), 'chipClass' => 'wpc-chip--info', 'tip' => __('Optimized files stay on the CDN only, with no copy using disk space on this server.', WPS_IC_TEXTDOMAIN), 'serve' => 'edge', 'land' => 'off', 'allowed' => ($orch_serve === 'edge' && $orch_land === 'off')],
        ];
        $plan_option = get_option(class_exists('wps_ic_plan') ? wps_ic_plan::PLAN_OPTION : 'wpc_plan');
        $plan_name = is_array($plan_option) && !empty($plan_option['plan']) ? ucfirst(sanitize_key((string) $plan_option['plan'])) : '';
        $lock_text = $plan_name !== '' ? sprintf(__('Not included in your %s plan', WPS_IC_TEXTDOMAIN), $plan_name) : __('Not included in your plan', WPS_IC_TEXTDOMAIN);
        $locked = [];
        foreach ($choices as $val => $c) {
            if (!$c['allowed'] && $current !== $val) {
                $locked[$val] = $c['label'];
            }
        }
        $served_here = !$cdn_serves || $current === 'origin';
        $meta = sprintf(__('Plan setting: %s, %s. Version %d.', WPS_IC_TEXTDOMAIN), $orch_serve === 'edge' ? 'CDN edge' : 'this server', $orch_land === 'on' ? 'copy on disk' : 'no copy on disk', wpc_policy_version());
        if ($show_choices) {
            $help = ($smart ? __('Where Smart Delivery keeps the files it optimizes. Your plan sets the widest option; you can always choose a stricter one.', WPS_IC_TEXTDOMAIN) : __('Where optimized images are kept. Your plan sets the widest option; you can always choose a stricter one.', WPS_IC_TEXTDOMAIN)) . ' ' . $meta;
            $title = __('Keep optimized files on', WPS_IC_TEXTDOMAIN);
        } else {
            $help = $smart ? __('Smart Delivery serves images from this server and keeps optimizing them as visitors view them.', WPS_IC_TEXTDOMAIN) : __('Images are served from this server.', WPS_IC_TEXTDOMAIN);
            $title = __('Delivery', WPS_IC_TEXTDOMAIN);
        }
        $error_text = __('Could not save this setting. Try again.', WPS_IC_TEXTDOMAIN);
        ?>
        <div class="wpc-box-for-checkbox wpc-policy-box" data-nonce="<?php echo esc_attr($nonce); ?>">
            <div class="wpc-box-content">
                <div class="wpc-checkbox-title-holder">
                    <div class="circle-check active"></div>
                    <h4><?php echo esc_html($title); ?></h4>
                </div>
                <p><?php echo esc_html($help); ?></p>
            </div>
            <div class="wpc-policy-keep">
                <?php if ($show_choices) : ?>
                    <?php $picked = isset($choices[$current]) ? $choices[$current] : null; ?>
                    <div class="wpc-custom-dropdown wpc-policy-keep-dropdown">
                        <button type="button" class="wpc-custom-dropdown-trigger" aria-haspopup="listbox" aria-label="<?php echo esc_attr($title); ?>">
                            <span class="wpc-custom-dropdown-label"><?php echo esc_html($picked ? $picked['label'] : __('Choose', WPS_IC_TEXTDOMAIN)); ?></span>
                            <svg width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        </button>
                        <div class="wpc-custom-dropdown-menu wpc-policy-keep-group" role="listbox" aria-label="<?php echo esc_attr($title); ?>">
                            <?php foreach ($choices as $val => $c) : ?>
                                <?php $is_locked = isset($locked[$val]); $is_on = !$is_locked && $current === $val; ?>
                                <div role="option" aria-selected="<?php echo $is_on ? 'true' : 'false'; ?>"<?php echo $is_locked ? ' aria-disabled="true"' : ''; ?> class="wpc-custom-dropdown-item wpc-policy-keep-choice<?php echo $is_on ? ' wpc-active' : ''; ?><?php echo $is_locked ? ' wpc-policy-keep-locked' : ''; ?>" data-value="<?php echo esc_attr($val); ?>" data-serve="<?php echo esc_attr($c['serve']); ?>" data-land="<?php echo esc_attr($c['land']); ?>" data-label="<?php echo esc_attr($c['label']); ?>" data-tip="<?php echo esc_attr($c['tip']); ?>">
                                    <span class="wpc-dropdown-check"><?php echo $is_locked ? '<i class="wpc-gray-lock"></i>' : ($is_on ? '&#10003;' : ''); ?></span>
                                    <span class="wpc-dropdown-item-content">
                                        <span class="wpc-dropdown-item-label"><?php echo esc_html($c['label']); ?> <span class="wpc-dropdown-badge <?php echo esc_attr($c['chipClass']); ?>"><?php echo esc_html($c['chip']); ?></span></span>
                                        <span class="wpc-dropdown-tooltip"><?php echo esc_html($c['tip']); ?></span>
                                        <?php if ($is_locked) : ?><span class="wpc-policy-keep-lock-note"><i class="wpc-gray-lock"></i><?php echo esc_html($lock_text); ?></span><?php endif; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <span class="wpc-policy-keep-hint"<?php echo $picked ? '' : ' hidden'; ?>><?php echo esc_html($picked ? $picked['tip'] : ''); ?></span>
                <?php endif; ?>
                <?php if ($served_here) : ?>
                    <span class="wpc-policy-served"><?php esc_html_e('Served from this server', WPS_IC_TEXTDOMAIN); ?> &middot; <a href="#" data-wpc-open-tab="cdn-delivery-options"><?php esc_html_e('CDN settings', WPS_IC_TEXTDOMAIN); ?></a></span>
                <?php endif; ?>
                <?php if ($show_choices) : ?>
                    <?php if (class_exists('wps_ic_admin_notice')) : ?>
                        <?php echo wps_ic_admin_notice::render_inline('warning', $error_text, '', '', 'wpc-policy-error'); ?>
                    <?php else : ?>
                        <div class="wpc-inline-notice is-warning wpc-policy-error" role="status"><span><?php echo esc_html($error_text); ?></span></div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <script>
        (function(){
            var box = document.querySelector('.wpc-policy-box'); if (!box) return;
            var link = box.querySelector('[data-wpc-open-tab]');
            if (link) {
                var tab = document.querySelector('a[data-tab="' + link.getAttribute('data-wpc-open-tab') + '"]');
                if (tab) { link.addEventListener('click', function(e){ e.preventDefault(); tab.click(); }); }
                else { link.replaceWith(document.createTextNode(link.textContent)); }
            }
            var group = box.querySelector('.wpc-policy-keep-group'); if (!group) return;
            var dd = box.querySelector('.wpc-policy-keep-dropdown');
            var label = dd.querySelector('.wpc-custom-dropdown-label');
            var hint = box.querySelector('.wpc-policy-keep-hint');
            var error = box.querySelector('.wpc-policy-error');
            if (error) { error.hidden = true; }
            function send(key, value){
                var fd = new FormData(); fd.append('action','wpc_policy_tighten'); fd.append('nonce', box.getAttribute('data-nonce')); fd.append('key', key); fd.append('value', value);
                return fetch(ajaxurl, {method:'POST', credentials:'same-origin', body: fd}).then(function(r){ return r.json(); }).then(function(j){ if (!j || !j.success) { throw new Error('policy'); } });
            }
            function mark(item){
                [].forEach.call(group.querySelectorAll('.wpc-policy-keep-choice'), function(b){ if (b.getAttribute('aria-disabled') === 'true') return; var on = b === item; b.setAttribute('aria-selected', on ? 'true' : 'false'); b.classList.toggle('wpc-active', on); b.querySelector('.wpc-dropdown-check').innerHTML = on ? '&#10003;' : ''; });
                if (label) { label.textContent = item ? item.getAttribute('data-label') : label.textContent; }
                if (hint) { hint.textContent = item ? item.getAttribute('data-tip') : ''; hint.hidden = !item; }
            }
            function close(){
                dd.classList.remove('wpc-open');
                for (var p = dd.parentNode; p && p.classList; p = p.parentNode) { p.classList.remove('wpc-dropdown-open'); }
            }
            group.addEventListener('click', function(e){
                var item = e.target.closest ? e.target.closest('.wpc-policy-keep-choice') : null;
                if (!item) return;
                e.preventDefault(); e.stopPropagation();
                if (item.getAttribute('aria-disabled') === 'true') return;
                close();
                if (item.getAttribute('aria-selected') === 'true') return;
                var prev = group.querySelector('.wpc-policy-keep-choice[aria-selected="true"]');
                mark(item);
                if (error) { error.hidden = true; }
                send('serve', item.getAttribute('data-serve')).then(function(){ return send('land', item.getAttribute('data-land')); }).catch(function(){ mark(prev); if (error) { error.hidden = false; } });
            });
        })();
        </script>
        <?php
    }
    add_action('wpc_policy_ui', 'wpc_policy_ui');
}

if (!function_exists('wpc_policy_tighten_ajax')) {
    function wpc_policy_tighten_ajax()
    {
        if (!current_user_can('manage_wpc_settings') || !isset($_POST['nonce']) || !wp_verify_nonce((string) $_POST['nonce'], WPC_POLICY_OPTION)) {
            wp_send_json_error(['error' => 'forbidden'], 403);
        }
        $key = isset($_POST['key']) ? sanitize_key((string) $_POST['key']) : '';
        $value = isset($_POST['value']) ? sanitize_key((string) $_POST['value']) : '';
        $stored = wpc_policy_stored();
        if ($key === 'serve' && $value === 'edge') {
            $orch_serve = is_array($stored) && isset($stored['serve']) ? $stored['serve'] : wpc_policy_defaults()['serve'];
            if ($orch_serve !== 'edge') {
                wp_send_json_error(['error' => 'plan_does_not_allow'], 403);
            }
        }
        if ($key === 'land' && $value === 'off') {
            $orch_land = is_array($stored) && isset($stored['land']) ? $stored['land'] : 'on';
            if ($orch_land !== 'off') {
                wp_send_json_error(['error' => 'plan_does_not_allow'], 403);
            }
        }
        $ok = wpc_policy_tighten($key, $value);
        if ($ok) {
            $policy = wpc_policy();
            if (is_array($policy) && isset($policy['wpc_lists'])) {
                $policy['wpc_lists'] = (object) wpc_policy_lists_counts();
            }
            wp_send_json_success(['policy' => $policy]);
        }
        wp_send_json_error(['error' => 'bad_key'], 400);
    }
    add_action('wp_ajax_wpc_policy_tighten', 'wpc_policy_tighten_ajax');
}

if (!function_exists('wpc_add_twin_bytes')) {
    function wpc_add_twin_bytes($delta)
    {
        $c = get_option(WPC_TWIN_BYTES_OPTION, ['bytes' => 0, 'since' => time()]);
        $c = is_array($c) ? $c : ['bytes' => 0, 'since' => time()];
        $c['bytes'] = max(0, (int) $c['bytes'] + (int) $delta);
        update_option(WPC_TWIN_BYTES_OPTION, $c, false);
        return (int) $c['bytes'];
    }
}
if (!function_exists('wpc_v2_health_snapshot')) {
    function wpc_v2_health_snapshot()
    {
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
        $base = !empty($up['basedir']) ? (string) $up['basedir'] : (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : '/');
        $free = @disk_free_space($base); $total = @disk_total_space($base);
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
        $twin = get_option(WPC_TWIN_BYTES_OPTION, ['bytes' => 0, 'since' => 0]);
        $snapshot = [
            'plugin_version'  => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
            'mode'            => function_exists('wpc_get_optimization_mode') ? wpc_get_optimization_mode() : '',
            'policy'          => ['version' => function_exists('wpc_policy_version') ? (int) wpc_policy_version() : 0, 'serve' => function_exists('wpc_policy_serve') ? wpc_policy_serve() : '', 'land' => function_exists('wpc_policy_land') ? wpc_policy_land() : ''],
            'last_sync'       => get_option('wpc_v2_sync_last20', null),
            'queue'           => ['depth' => function_exists('wpc_rail_depth') ? (int) wpc_rail_depth() : -1, 'parked' => (int) get_option('wpc_rail_parked28', 0), 'purge_spool' => count((array) get_option('wpc_landed_purge_spool88', []))],
            'pressure'        => ['hot' => function_exists('wpc_under_pressure') ? (int) wpc_under_pressure() : -1, 'load1' => is_array($load) ? round((float) $load[0], 2) : -1, 'cores' => function_exists('wpc_box_cores') ? (int) wpc_box_cores() : -1],
            'disk'            => ['free' => $free === false ? null : (int) $free, 'total' => $total === false ? null : (int) $total, 'twin_bytes' => is_array($twin) ? (int) $twin['bytes'] : 0, 'twin_since' => is_array($twin) ? (int) $twin['since'] : 0],
            'safe_mode'       => function_exists('wpc_safe_mode') ? (int) wpc_safe_mode() : -1,
            'time'            => time(),
        ];
        if (function_exists('wpc_policy_lists_counts')) {
            $snapshot['wpc_lists'] = (object) wpc_policy_lists_counts();
        }
        if (function_exists('wpc_host_cache_echo')) {
            $snapshot = array_merge($snapshot, wpc_host_cache_echo());
        }
        if (function_exists('wpc_crit_leg_echo')) {
            $snapshot = array_merge($snapshot, wpc_crit_leg_echo());
        }
        return $snapshot;
    }
    add_action('rest_api_init', function () {
        register_rest_route('wpc/v2', '/health', [
            'methods'             => 'GET',
            'permission_callback' => '__return_true',
            'callback'            => function ($request) {
                $sig = $request->get_header('X-WPC-Sig');
                $v = function_exists('wpc_v2_verify_hmac') ? wpc_v2_verify_hmac($sig, '', 300) : ['ok' => false, 'reason' => 'no_verifier'];
                if (empty($v['ok'])) {
                    return new WP_REST_Response(['error' => 'hmac_fail'], 401);
                }
                return new WP_REST_Response(wpc_v2_health_snapshot(), 200);
            },
        ]);
    });
}
if (!function_exists('wpc_run_selfcheck_request')) {
    function wpc_run_selfcheck_request()
    {
        if (!apply_filters('wpc_selfcheck', true) || !function_exists('home_url') || !function_exists('wp_remote_get')) {
            return null;
        }
        $t0 = microtime(true);
        $r = wp_remote_get(add_query_arg(['wpcnc' => 1, 'wpc_selfcheck' => 1], home_url('/')), ['timeout' => 20, 'sslverify' => false, 'headers' => ['User-Agent' => 'WPCompress-SelfCheck/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '')]]);
        $code = is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
        $body = is_wp_error($r) ? '' : (string) wp_remote_retrieve_body($r);
        $ok = ($code === 200 && stripos($body, '</html>') !== false && stripos($body, 'Fatal error') === false);
        $fails = $ok ? 0 : (int) get_option('wpc_selfcheck_fails29', 0) + 1;
        update_option('wpc_selfcheck_fails29', $fails, false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($ok ? 'selfcheck-ok' : 'selfcheck-fail', '', '', ['http' => $code, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'fails' => $fails, 'v' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '']);
        }
        // Rule: a stand-down the self-check set is lifted by the next successful self-check; a
        // human's safe mode never is. Until 7.24.55 a success only reset the fail counter, so the
        // trip was permanent: sharvvi.com (2026-09-29) had every render shed after one slow
        // post-update loopback on a healthy box (load 0.9 on 2 cores).
        if ($ok && function_exists('wpc_safe_mode_source') && wpc_safe_mode_source() === 'selfcheck') {
            $since = (int) get_option('wpc_safe_mode_at', 0);
            update_option('wpc_safe_mode', 0);
            delete_option('wpc_safe_mode_src');
            delete_option('wpc_safe_mode_at');
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('selfcheck-standdown-cleared', '', '', ['since' => $since, 'legacy' => $since ? 0 : 1]);
            }
        }
        if ($fails >= 2 && apply_filters('wpc_selfcheck_standdown', true)) {
            // Record the source only when this trip turns safe mode on: a human's safe mode that is
            // already on must not be relabelled as the self-check's and then lifted by it.
            if (!get_option('wpc_safe_mode', 0)) {
                update_option('wpc_safe_mode', 1);
                update_option('wpc_safe_mode_src', 'selfcheck', false);
                update_option('wpc_safe_mode_at', time(), false);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('selfcheck-standdown', '', '', ['fails' => $fails]);
            }
        }
        return $ok;
    }
    function wpc_arm_selfcheck_request()
    {
        static $armed = false;
        if ($armed || !function_exists('add_action')) {
            return false;
        }
        $armed = true;
        add_action('shutdown', function () {
            if (function_exists('wpc_finish_request') && empty($GLOBALS['wpc_response_released'])) {
                wpc_finish_request();
            }
            @ignore_user_abort(true);
            wpc_run_selfcheck_request();
        }, 97);
        return true;
    }
    /**
     * While the self-check holds safe mode on, run it again at most once per 6 hours.
     *
     * Only a plugin update arms the self-check (wp-compress-core.php checkPluginVersion), so a
     * trip taken right after an update would otherwise wait for the next release to be lifted:
     * sharvvi.com (2026-09-29) stayed shed across versions. admin_init is used because it is the
     * hook the update arm already runs on and it fires on every wp-admin and admin-ajax.php request
     * (the heartbeat included), while a cron event would be scheduled through wpc_pl_sched, which
     * safe mode itself refuses (wpc_bg_lane_allowed). The self-check calls no lane gate, so safe
     * mode does not block it. A human's safe mode is never re-checked.
     */
    function wpc_selfcheck_rearm_while_tripped()
    {
        if (!function_exists('wpc_safe_mode_source') || wpc_safe_mode_source() !== 'selfcheck') {
            return false;
        }
        $every = max(300, (int) apply_filters('wpc_selfcheck_rearm_secs', 6 * 3600));
        if (time() - (int) get_option('wpc_selfcheck_rearm_at', 0) < $every) {
            return false;
        }
        // Stamped before the check runs so concurrent admin requests do not each fire a 20 s loopback.
        update_option('wpc_selfcheck_rearm_at', time(), false);
        return wpc_arm_selfcheck_request();
    }
    add_action('admin_init', 'wpc_selfcheck_rearm_while_tripped', 2);
}
