<?php
/**
 * Host page-cache purge (cohort host_cache_purge, default off).
 *
 * Purges the host platform caches the landing purge does not reach (WP Engine, SiteGround, Pantheon, VIP,
 * Batcache) per URL from wps_ic_purge_url_html, and the homepage from the delivery-config flip and the
 * plugin-version latch. Layers listed by wpc_host_cache_owned_layers are not called again. No adapter
 * purges a whole site; a cache without a per-URL call answers 'no-url-api'.
 *
 * Ten minutes after a homepage purge one plain GET reads the served copy's mint (Server-Timing wpc-mint,
 * else Last-Modified); a copy minted before the purge is recorded as host_cache_stale (echo + health)
 * and the purge is retried once.
 *
 * Bounds: 120 s per URL, 12 host purges per site per hour, one outstanding check.
 * Off (cohort absent, WPC_HOST_CACHE_PURGE_KILL, or filter wpc_host_cache_purge false): every entry
 * point returns before it reads or writes anything.
 */

if (!defined('WPC_HOST_CACHE_STATE_OPTION')) {
    define('WPC_HOST_CACHE_STATE_OPTION', 'wpc_host_cache_state');
}

if (!function_exists('wpc_host_cache_purge_on')) {
    function wpc_host_cache_purge_on()
    {
        if (defined('WPC_HOST_CACHE_PURGE_KILL') && WPC_HOST_CACHE_PURGE_KILL) {
            return false;
        }
        if (function_exists('wpc_policy_cohort')) {
            $on = (int) wpc_policy_cohort('host_cache_purge') === 1;
        } else {
            // The cron lane does not load v2-policy.php; read the stored switches it would read.
            $on = function_exists('wpc_policy_cohort_stored') && wpc_policy_cohort_stored('host_cache_purge');
        }
        if (!$on) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_host_cache_purge', true) !== false;
    }
}

if (!function_exists('wpc_host_cache_state')) {
    function wpc_host_cache_state()
    {
        $s = get_option(WPC_HOST_CACHE_STATE_OPTION, []);
        return is_array($s) ? $s : [];
    }

    function wpc_host_cache_state_save(array $s)
    {
        update_option(WPC_HOST_CACHE_STATE_OPTION, $s, false);
    }
}

if (!function_exists('wpc_host_cache_owned_layers')) {
    /** The layers purgeExternalLayers / purgeAll already reach; the landing and flip paths do not call them again. */
    function wpc_host_cache_owned_layers()
    {
        $owned = ['kinsta', 'litespeed', 'rocket', 'w3tc', 'wpsc', 'breeze', 'cloudflare'];
        $f = function_exists('apply_filters') ? apply_filters('wpc_host_cache_owned_layers', $owned) : $owned;
        return is_array($f) ? array_values(array_map('strval', $f)) : $owned;
    }
}

if (!function_exists('wpc_host_cache_other_form')) {
    function wpc_host_cache_other_form($url)
    {
        return (substr($url, -1) === '/') ? rtrim($url, '/') : $url . '/';
    }
}

if (!function_exists('wpc_host_cache_post_id')) {
    /** The post a URL shows; the homepage resolves to page_on_front. 0 when none. */
    function wpc_host_cache_post_id($url)
    {
        $pid = function_exists('url_to_postid') ? (int) url_to_postid($url) : 0;
        if ($pid === 0 && wpc_host_cache_is_home($url) && function_exists('get_option')) {
            $pid = (int) get_option('page_on_front');
        }
        return $pid;
    }
}

if (!function_exists('wpc_host_cache_adapters')) {
    /**
     * name => [detect, purge(url) -> result string]. Every call is existence-guarded: a host that
     * renames its API turns into a silent 'no-url-api', never a fatal.
     */
    function wpc_host_cache_adapters()
    {
        $a = [];
        $a['wpengine'] = [
            'detect' => function () { return class_exists('WpeCommon') && method_exists('WpeCommon', 'purge_varnish_cache'); },
            'purge'  => function ($url) {
                // WpeCommon has no URL purge; its post purge is per page. A listing homepage has no post.
                $pid = wpc_host_cache_post_id($url);
                if ($pid <= 0) {
                    return 'no-url-api';
                }
                WpeCommon::purge_varnish_cache($pid);
                return 'post:' . $pid;
            },
        ];
        $a['kinsta'] = [
            'detect' => function () { return defined('KINSTAMU_VERSION') || isset($GLOBALS['kinsta_cache']); },
            'purge'  => function ($url) {
                $k = isset($GLOBALS['kinsta_cache']) ? $GLOBALS['kinsta_cache'] : null;
                if (is_object($k) && !empty($k->kinsta_cache_purge) && method_exists($k->kinsta_cache_purge, 'purge_url')) {
                    $k->kinsta_cache_purge->purge_url($url);
                    return 'url';
                }
                return 'no-url-api';
            },
        ];
        $a['siteground'] = [
            'detect' => function () { return function_exists('sg_cachepress_purge_cache'); },
            'purge'  => function ($url) {
                $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');
                $homePath = rtrim((string) parse_url((string) (function_exists('get_option') ? get_option('home') : ''), PHP_URL_PATH), '/');
                $root = $path === '' || $path === $homePath;
                if ($root && apply_filters('wpc_host_cache_purge_sg_root', false, $url) !== true) {
                    return 'root-refused';
                }
                sg_cachepress_purge_cache($url);
                return $root ? 'subtree' : 'url';
            },
        ];
        $a['pantheon'] = [
            'detect' => function () { return function_exists('pantheon_wp_clear_edge_paths'); },
            'purge'  => function ($url) {
                $path = (string) parse_url($url, PHP_URL_PATH);
                $r = pantheon_wp_clear_edge_paths([$path === '' ? '/' : $path]);
                return (function_exists('is_wp_error') && is_wp_error($r)) ? 'error' : 'path';
            },
        ];
        $a['vip'] = [
            'detect' => function () { return function_exists('wpvip_purge_edge_cache_for_url') || function_exists('wpcom_vip_purge_edge_cache_for_url'); },
            'purge'  => function ($url) {
                if (function_exists('wpvip_purge_edge_cache_for_url')) {
                    wpvip_purge_edge_cache_for_url($url);
                } else {
                    wpcom_vip_purge_edge_cache_for_url($url);
                }
                return 'url';
            },
        ];
        $a['batcache'] = [
            // WordPress.com Atomic, Pressable, older VIP: the copy is versioned per URL in the object cache.
            'detect' => function () { return function_exists('batcache_clear_url') && isset($GLOBALS['batcache']) && is_object($GLOBALS['batcache']); },
            'purge'  => function ($url) {
                batcache_clear_url($url);
                batcache_clear_url(wpc_host_cache_other_form($url));
                return 'url';
            },
        ];
        $a['litespeed'] = [
            'detect' => function () { return defined('LSCWP_V') || class_exists('\LiteSpeed\Purge'); },
            'purge'  => function ($url) { function_exists('wpc_litespeed_purge_url') ? wpc_litespeed_purge_url($url) : do_action('litespeed_purge_url', $url); return 'url'; },
        ];
        $a['rocket'] = [
            'detect' => function () { return function_exists('rocket_clean_files'); },
            'purge'  => function ($url) { rocket_clean_files($url); return 'url'; },
        ];
        $a['w3tc'] = [
            'detect' => function () { return function_exists('w3tc_flush_url'); },
            'purge'  => function ($url) { w3tc_flush_url($url); return 'url'; },
        ];
        $a['wpsc'] = [
            'detect' => function () { return function_exists('wpsc_delete_url_cache'); },
            'purge'  => function ($url) { wpsc_delete_url_cache($url); return 'url'; },
        ];
        $a['breeze'] = [
            // Breeze has a whole-cache action only; maybeFullPurgeFullOnlyLayers owns it.
            'detect' => function () { return defined('BREEZE_VERSION') || class_exists('Breeze_PurgeCache'); },
            'purge'  => function ($url) { return 'no-url-api'; },
        ];
        $a['cloudflare'] = [
            'detect' => function () {
                if (!class_exists('wps_ic_cache') || !method_exists('wps_ic_cache', 'purgeEdgeHtmlUrls') || !function_exists('get_option')) {
                    return false;
                }
                $cf = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
                return is_array($cf) && !empty($cf['token']) && !empty($cf['zone']);
            },
            'purge'  => function ($url) {
                wps_ic_cache::purgeEdgeHtmlUrls([$url, wpc_host_cache_other_form($url)], true);
                return 'url';
            },
        ];
        return $a;
    }
}

if (!function_exists('wpc_host_cache_detected')) {
    function wpc_host_cache_detected()
    {
        $names = [];
        foreach (wpc_host_cache_adapters() as $name => $a) {
            try {
                if (call_user_func($a['detect'])) {
                    $names[] = $name;
                }
            } catch (\Throwable $e) {
            }
        }
        return $names;
    }
}

if (!function_exists('wpc_host_cache_platform')) {
    /** A label for the echo, never a purge decision. */
    function wpc_host_cache_platform()
    {
        if (defined('ATOMIC_SITE_ID') || (defined('IS_ATOMIC') && IS_ATOMIC) || isset($_SERVER['ATOMIC_SITE_ID'])) return 'atomic';
        if (defined('IS_WPCOM') && IS_WPCOM) return 'wpcom';
        if (defined('IS_PRESSABLE') && IS_PRESSABLE) return 'pressable';
        if ((defined('WPCOM_IS_VIP_ENV') && WPCOM_IS_VIP_ENV) || defined('VIP_GO_APP_ENVIRONMENT')) return 'vip';
        if (class_exists('WpeCommon') || defined('WPE_PLUGIN_VERSION')) return 'wpengine';
        if (defined('KINSTAMU_VERSION') || isset($GLOBALS['kinsta_cache'])) return 'kinsta';
        if (defined('PANTHEON_ENVIRONMENT') || function_exists('pantheon_wp_clear_edge_paths')) return 'pantheon';
        if (function_exists('sg_cachepress_purge_cache')) return 'siteground';
        return function_exists('wpc_v2_detect_host') ? (string) wpc_v2_detect_host() : '';
    }
}

if (!function_exists('wpc_host_cache_clean_url')) {
    /** Same-host absolute URL without query, or ''. */
    function wpc_host_cache_clean_url($url)
    {
        $url = (string) $url;
        if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'sanitizeSameHostUrl')) {
            return (string) wps_ic_url_key::sanitizeSameHostUrl($url);
        }
        if (!function_exists('home_url')) {
            return '';
        }
        $p = parse_url($url);
        $h = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
        if (!is_array($p) || empty($p['host']) || $h === '') {
            return '';
        }
        $strip = function ($x) { return strpos($x, 'www.') === 0 ? substr($x, 4) : $x; };
        if ($strip(strtolower($p['host'])) !== $strip($h)) {
            return '';
        }
        $scheme = ((string) parse_url(home_url('/'), PHP_URL_SCHEME) === 'http') ? 'http' : 'https';
        return $scheme . '://' . strtolower($p['host']) . (isset($p['path']) && $p['path'] !== '' ? $p['path'] : '/');
    }
}

if (!function_exists('wpc_host_cache_is_home')) {
    function wpc_host_cache_is_home($url)
    {
        if (!function_exists('home_url')) {
            return false;
        }
        $a = rtrim((string) parse_url((string) $url, PHP_URL_PATH), '/');
        $b = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
        return $a === $b;
    }
}

if (!function_exists('wpc_host_cache_budget_take')) {
    /**
     * At most wpc_host_cache_purge_hourly_max (12) host purges per site per clock window of an
     * hour. Landings stop short of the last wpc_host_cache_flip_reserve (2): those slots belong to
     * the homepage purges this class exists for — the delivery/version flips and the verify retry —
     * so a landing burst can never be what throttles them.
     */
    function wpc_host_cache_budget_take($reserved = false)
    {
        $cap = max(1, (int) apply_filters('wpc_host_cache_purge_hourly_max', 12));
        $reserve = min($cap - 1, max(0, (int) apply_filters('wpc_host_cache_flip_reserve', 2)));
        $b = get_option('wpc_host_cache_budget', []);
        $now = time();
        if (!is_array($b) || ($now - (int) ($b['t'] ?? 0)) >= 3600) {
            $b = ['t' => $now, 'n' => 0];
        }
        if ((int) $b['n'] >= ($reserved ? $cap : $cap - $reserve)) {
            return false;
        }
        $b['n'] = (int) $b['n'] + 1;
        update_option('wpc_host_cache_budget', $b, false);
        return true;
    }
}

if (!function_exists('wpc_host_cache_log')) {
    function wpc_host_cache_log($event, $url, array $fields)
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log($event, '', (string) $url, $fields);
        }
    }
}

if (!function_exists('wpc_host_cache_purge_url')) {
    /**
     * Purges $url in every detected host cache not in $skip (null = the owned layers).
     * $opts['retry']: the verify's one retry (no URL window, schedules no check of its own).
     * Returns layer => result, or ['throttled' => 'url'|'hour'], or [] when off or not same-host.
     */
    function wpc_host_cache_purge_url($url, $context = '', $skip = null, $opts = [])
    {
        try {
            if (!wpc_host_cache_purge_on()) {
                return [];
            }
            $opts = is_array($opts) ? $opts : [];
            $retry = !empty($opts['retry']);
            $clean = wpc_host_cache_clean_url($url);
            if ($clean === '') {
                return [];
            }
            $skip = array_map('strval', (array) ($skip === null ? wpc_host_cache_owned_layers() : $skip));
            $win = max(0, (int) apply_filters('wpc_host_cache_url_window_s', 120));
            $tk = 'wpc_hcp_' . md5($clean);
            // The two flips (defines.php wpc_delivery_config_version_flip / wpc_stale_hard_purge_done).
            $flip = in_array((string) $context, ['dcv-flip', 'plugin-version'], true);
            // A flip is held back by the URL window only when the purge inside it was a flip too
            // (both flips in one request = one purge); a landing just before never throttles it.
            $held = ($win > 0) ? get_transient($tk) : false;
            if (!$retry && $held && (!$flip || $held === 'flip')) {
                wpc_host_cache_log('host-cache-throttled', $clean, ['why' => 'url', 'ctx' => (string) $context]);
                return ['throttled' => 'url'];
            }
            if (!wpc_host_cache_budget_take($flip || $retry)) {
                wpc_host_cache_log('host-cache-throttled', $clean, ['why' => 'hour', 'ctx' => (string) $context]);
                return ['throttled' => 'hour'];
            }
            if (!$retry && $win > 0) {
                set_transient($tk, $flip ? 'flip' : 'land', $win);
            }
            $layers = [];
            $detected = 0;
            foreach (wpc_host_cache_adapters() as $name => $adapter) {
                try {
                    if (!call_user_func($adapter['detect'])) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
                $detected++;
                if (in_array($name, $skip, true)) {
                    $layers[$name] = 'owned';
                    continue;
                }
                try {
                    $res = (string) call_user_func($adapter['purge'], $clean);
                } catch (\Throwable $e) {
                    $res = 'error';
                }
                $layers[$name] = $res;
                if ($res === 'root-refused') {
                    wpc_host_cache_log('host-cache-refused', $clean, ['layer' => $name, 'why' => 'whole-cache', 'ctx' => (string) $context]);
                }
                if (function_exists('wpc_purge_record')) {
                    wpc_purge_record('host', $name, 'url:' . md5($clean), 1, !in_array($res, ['error', 'no-url-api', 'root-refused'], true), (string) $context);
                }
            }
            // No host integration at all: a PURGE to the site's own host, only when the site asks.
            if ($detected === 0 && apply_filters('wpc_host_cache_generic_purge', false, $clean)) {
                $r = wp_remote_request($clean, [
                    'method' => 'PURGE', 'blocking' => false, 'timeout' => 2, 'redirection' => 0, 'sslverify' => false,
                    'headers' => ['X-Purge-Method' => 'default'],
                ]);
                $layers['generic'] = (function_exists('is_wp_error') && is_wp_error($r)) ? 'error' : 'sent';
            }
            $st = wpc_host_cache_state();
            $st['last'] = ['at' => time(), 'ctx' => substr((string) $context, 0, 40), 'url' => $clean, 'layers' => $layers];
            wpc_host_cache_state_save($st);
            wpc_host_cache_log('host-cache-purge', $clean, ['ctx' => (string) $context, 'layers' => $layers, 'retry' => $retry ? 1 : 0]);
            // Nothing purgeable and no platform to name: the self-GET could only measure the origin.
            // Atomic and any named platform keep it (their edge is what the check is for).
            $verifiable = $layers !== [] || wpc_host_cache_platform() !== '';
            if (!$retry && wpc_host_cache_is_home($clean) && $verifiable) {
                wpc_host_cache_verify_schedule(time(), 1);
            }
            return $layers;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('wpc_host_cache_verify_schedule')) {
    /** One outstanding check: attempt 1 is not rescheduled while one is pending (a pending record older than 2 h is dead). */
    function wpc_host_cache_verify_schedule($ref, $attempt = 1)
    {
        $attempt = (int) $attempt;
        $st = wpc_host_cache_state();
        $p = isset($st['pending']) && is_array($st['pending']) ? $st['pending'] : null;
        if ($attempt === 1 && $p !== null && (time() - (int) ($p['at'] ?? 0)) < 7200) {
            return false;
        }
        if ((function_exists('wpc_safe_mode') && wpc_safe_mode()) || !function_exists('wpc_pl_sched')) {
            return false;
        }
        $due = time() + max(60, (int) apply_filters('wpc_host_cache_verify_delay_s', 600));
        if (!(function_exists('wp_next_scheduled') && wp_next_scheduled('wpc_host_cache_verify', [$attempt]))) {
            $ok = wpc_pl_sched($due, 'wpc_host_cache_verify', [$attempt]);
            if ($ok === false || (function_exists('is_wp_error') && is_wp_error($ok))) {
                return false;
            }
        }
        $st['pending'] = ['ref' => (int) $ref, 'attempt' => $attempt, 'at' => time(), 'due' => $due];
        wpc_host_cache_state_save($st);
        return true;
    }
}

if (!function_exists('wpc_host_cache_observe')) {
    /**
     * Pure: what a GET of the page says about the copy that answered it.
     * $headers: lower-case name => string|string[]. served_mint 0 when the response names none.
     * holder 'host' when a cache in front marks a hit, 'origin-copy' when only this plugin's own
     * stored copy answered (Server-Timing wpc-cache;desc=hit), else 'render'. own_stale 1 when that
     * copy is the plugin's stale-and-rewarm serve (X-WPC-Cache: stale-rewarm).
     */
    function wpc_host_cache_observe($code, array $headers, $body)
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower((string) $k)] = implode(', ', array_map('strval', (array) $v));
        }
        $st = $h['server-timing'] ?? '';
        $mint = 0;
        if (preg_match_all('/wpc-mint\s*;\s*desc="?(\d{9,11})/i', $st, $m)) {
            $mint = max(array_map('intval', $m[1]));
        }
        $coh = '';
        if (preg_match_all('/wpc-coh\s*;\s*desc="?([0-9a-f]{12})\b/i', $st, $mc)) {
            $coh = strtolower((string) end($mc[1]));
        }
        $from = 'wpc-mint';
        if ($mint === 0 && !empty($h['last-modified'])) {
            $lm = strtotime($h['last-modified']);
            $mint = $lm !== false ? (int) $lm : 0;
            $from = 'last-modified';
        }
        $markers = [
            'x-ac'              => '/\b(HIT|STALE|UPDATING|EXPIRED)\b/i',
            'x-nananana'        => '/batcache-hit/i',
            'x-cache'           => '/\bHIT\b/i',
            'cf-cache-status'   => '/^(HIT|STALE|UPDATING|REVALIDATED)$/i',
            'x-litespeed-cache' => '/^hit/i',
            'x-kinsta-cache'    => '/HIT/i',
            'x-proxy-cache'     => '/HIT/i',
            'x-cache-status'    => '/HIT|STALE/i',
            'x-varnish'         => '/^\d+\s+\d+$/',
        ];
        $sig = [];
        $hostHit = false;
        foreach ($markers as $name => $re) {
            if (!isset($h[$name]) || $h[$name] === '') {
                continue;
            }
            $sig[$name] = substr($h[$name], 0, 60);
            if (preg_match($re, trim($h[$name]))) {
                $hostHit = true;
            }
        }
        if (isset($h['age']) && (int) $h['age'] > 0) {
            $sig['age'] = (int) $h['age'];
            $hostHit = true;
        }
        $holder = $hostHit ? 'host' : (preg_match('/wpc-cache\s*;\s*desc="?hit/i', $st) ? 'origin-copy' : 'render');
        return [
            'code'        => (int) $code,
            'served_mint' => $mint,
            'served_coh'  => $coh,
            'mint_from'   => $mint > 0 ? $from : '',
            'holder'      => $holder,
            'own_stale'   => ($holder === 'origin-copy' && isset($h['x-wpc-cache']) && stripos($h['x-wpc-cache'], 'stale-rewarm') !== false) ? 1 : 0,
            'crit_tag'    => strpos((string) $body, 'id="wpc-critical-css"') !== false ? 1 : 0,
            'sig'         => $sig,
        ];
    }
}

if (!function_exists('wpc_host_cache_stale_decide')) {
    /** Pure: the copy was minted before the purge that should have replaced it (less the grace a held landing takes to store its rebuild). */
    function wpc_host_cache_stale_decide(array $obs, $ref, $grace)
    {
        $mint = (int) ($obs['served_mint'] ?? 0);
        return (int) ($obs['code'] ?? 0) === 200 && $mint > 0 && (int) $ref > 0 && $mint < (int) $ref - max(0, (int) $grace);
    }
}

if (!function_exists('wpc_host_cache_verify_run')) {
    /** The cron check. Only the attempt that was scheduled runs; attempt 1 may retry once, attempt 2 never does. */
    function wpc_host_cache_verify_run($attempt = 1)
    {
        try {
            if (!wpc_host_cache_purge_on() || !function_exists('home_url') || !function_exists('wp_remote_get')) {
                return null;
            }
            $attempt = max(1, (int) $attempt);
            $st = wpc_host_cache_state();
            $p = isset($st['pending']) && is_array($st['pending']) ? $st['pending'] : null;
            if ($p === null || (int) ($p['attempt'] ?? 0) !== $attempt) {
                return null;
            }
            unset($st['pending']);
            wpc_host_cache_state_save($st);

            $home = home_url('/');
            // A plain GET: no query, no Cache-Control / Pragma, so the host cache answers as it does for PSI.
            $r = wp_remote_get($home, [
                'timeout' => 10, 'redirection' => 3, 'sslverify' => false, 'limit_response_size' => 1048576,
                'headers' => [
                    'User-Agent' => (string) apply_filters('wpc_host_cache_verify_ua', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'),
                    'Accept'     => 'text/html',
                ],
            ]);
            $err = function_exists('is_wp_error') && is_wp_error($r);
            $headers = [];
            if (!$err && isset($r['headers'])) {
                foreach ((is_object($r['headers']) && method_exists($r['headers'], 'getAll')) ? $r['headers']->getAll() : (array) $r['headers'] as $k => $v) {
                    $headers[strtolower((string) $k)] = $v;
                }
            }
            $obs = wpc_host_cache_observe($err ? 0 : (int) wp_remote_retrieve_response_code($r), $headers, $err ? '' : (string) wp_remote_retrieve_body($r));
            $ref = (int) ($p['ref'] ?? 0);
            $grace = max(0, (int) apply_filters('wpc_host_cache_stale_grace_s', 300));
            $stale = wpc_host_cache_stale_decide($obs, $ref, $grace) && empty($obs['own_stale']);
            $retry = $stale && $attempt === 1;
            $v = array_merge($obs, [
                'at'         => time(),
                'attempt'    => $attempt,
                'ref'        => $ref,
                'mint_age_s' => $obs['served_mint'] > 0 ? time() - (int) $obs['served_mint'] : -1,
                'stale'      => $stale ? 1 : 0,
                'retry'      => $retry ? 'sent' : ($stale ? 'final' : 'none'),
                'hosts'      => wpc_host_cache_detected(),
                'platform'   => wpc_host_cache_platform(),
            ]);
            $st = wpc_host_cache_state();
            $st['verify'] = $v;
            if ($stale) {
                $st['stale'] = $v;
            } else {
                unset($st['stale']);
            }
            wpc_host_cache_state_save($st);
            wpc_host_cache_log($stale ? 'host-cache-stale' : 'host-cache-fresh', $home, $v);
            if ($stale && function_exists('wpc_belt_receipt')) {
                wpc_belt_receipt('host-cache-stale', ['mint_age_s' => $v['mint_age_s'], 'attempt' => $attempt, 'holder' => $obs['holder'], 'platform' => $v['platform']], false, '');
            }
            if ($retry) {
                wpc_host_cache_purge_url($home, 'host-verify-retry', [], ['retry' => true]);
                wpc_host_cache_verify_schedule(time(), 2);
            }
            return $v;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('wpc_host_cache_on_url_purge')) {
    /** Listener on wps_ic_purge_url_html (the end of purgeExternalLayers): the plugin's own landings only. */
    function wpc_host_cache_on_url_purge($clean, $urlKey = '', $context = '')
    {
        if (!wpc_host_cache_purge_on()) {
            return;
        }
        $context = (string) $context;
        $landing = function_exists('wpc_p2_landing_context')
            ? wpc_p2_landing_context($context)
            : preg_match('/^(crit-|lcp-|delay-|fonts-|manifest-off-|land-|image-land|sized-landed)/', $context) === 1;
        if (!apply_filters('wpc_host_cache_context', $landing, $context)) {
            return;
        }
        wpc_host_cache_purge_url((string) $clean, $context, null);
    }
}

if (!function_exists('wpc_host_cache_echo')) {
    /** The fields the /v2/config echo and the health body carry; [] when off. */
    function wpc_host_cache_echo()
    {
        if (!wpc_host_cache_purge_on()) {
            return [];
        }
        $st = wpc_host_cache_state();
        $v = isset($st['verify']) && is_array($st['verify']) ? $st['verify'] : [];
        $last = isset($st['last']) && is_array($st['last']) ? $st['last'] : [];
        $hc = [
            'hosts'        => wpc_host_cache_detected(),
            'platform'     => wpc_host_cache_platform(),
            'last_purge_at' => (int) ($last['at'] ?? 0),
            'last_purge_ctx' => (string) ($last['ctx'] ?? ''),
            'pending'      => isset($st['pending']) ? 1 : 0,
        ];
        if ($v !== []) {
            $hc['verify_at'] = (int) $v['at'];
            $hc['served_mint'] = (int) $v['served_mint'];
            $hc['mint_age_s'] = (int) $v['mint_age_s'];
            $hc['holder'] = (string) $v['holder'];
            $hc['crit_tag'] = (int) $v['crit_tag'];
            $hc['attempt'] = (int) $v['attempt'];
            $hc['retry'] = (string) $v['retry'];
            $hc['sig'] = (object) (array) $v['sig'];
        }
        return [
            'host_cache'       => $hc,
            'host_cache_stale' => isset($st['stale']) ? 1 : 0,
            'wpc_mint_age_s'   => $v !== [] ? (int) $v['mint_age_s'] : -1,
            'wpc_cohort_sig'   => $v !== [] ? (string) ($v['served_coh'] ?? '') : '',
        ];
    }
}

if (function_exists('add_action')) {
    add_action('wps_ic_purge_url_html', 'wpc_host_cache_on_url_purge', 20, 3);
    add_action('wpc_host_cache_verify', 'wpc_host_cache_verify_run', 10, 1);
}
