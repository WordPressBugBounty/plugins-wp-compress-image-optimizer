<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_crit_meta_write')) {
    // Mixed-tree belt: the canonical atomic writer lives in defines.php — a partial
    // deploy (FTP truncation, stale defines.php) degrades to a plain write here,
    // never a fatal (law 10). Complete trees never reach this definition.
    function wpc_crit_meta_write($path, $value)
    {
        try {
            return @file_put_contents($path, (string) $value) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}



if (!function_exists('wpc_mc_up')) {
    // Global MC circuit breaker: an unreachable service must cost ~zero. A
    // timed-out call trips a 5-min skip-all window (staging.wpcompress.com
    // receipt: config-sync blocked ADMIN pages 8s each while the MC was dark).
    function wpc_mc_up()
    {
        return !get_transient('wpc_mc_down');
    }
    function wpc_mc_trip($err = '')
    {
        set_transient('wpc_mc_down', 1, 300);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('mc-breaker-trip', '', '', ['err' => substr((string) $err, 0, 120)]);
        }
    }
}



if (!function_exists('wpc_delay_aggr_rearm')) {
    // A fresh measured gen = a new keep list = a new aggressive-delay attempt:
    // clear the boot-watchdog demote so the site retries interaction-only.
    // DAMPED: each prior trip adds an hour of hold before a land may re-arm
    // (trip-purge oscillation breaker — an unrelated URL's land must not
    // instantly re-arm a site that just demoted). 5 trips = 30d cool-down;
    // any trip older than 30d decays the counter to 0 (no lifetime penalty).
    function wpc_delay_aggr_rearm()
    {
        $wpc_t360 = (int) get_option('wpc_delay_aggr_off', 0);
        if (!$wpc_t360) {
            return;
        }
        $wpc_n360 = (int) get_option('wpc_delay_aggr_fails', 0);
        if (time() - $wpc_t360 > 30 * DAY_IN_SECONDS) {
            delete_option('wpc_delay_aggr_off');
            delete_option('wpc_delay_v3_bootfails');
            update_option('wpc_delay_aggr_fails', 0, false);
            return;
        }
        if ($wpc_n360 >= 5) {
            return;
        }
        if (time() - $wpc_t360 < min($wpc_n360, 5) * HOUR_IN_SECONDS) {
            return;
        }
        delete_option('wpc_delay_aggr_off');
        delete_option('wpc_delay_v3_bootfails');
    }
}



if (!function_exists('wpc_settings_drift_guard')) {
    function wpc_settings_drift_guard($new, $old)
    {
        try {
            if (!is_array($new) || !is_array($old) || !apply_filters('wpc_settings_drift_guard', true)) {
                return $new;
            }
            $protect = apply_filters('wpc_drift_protected_keys', [
                'replace-fonts', 'used-css', 'delay-js-v3', 'preload-crit-fonts',
                'font-display', 'icon-font-display', 'delay-js-v2',


                'static-serve', 'cache', 'critical',
            ]);
            foreach ($protect as $k) {
                if (!array_key_exists($k, $new) && array_key_exists($k, $old) && $old[$k] !== '' && $old[$k] !== null) {
                    $new[$k] = $old[$k];
                }
            }
            return $new;
        } catch (\Throwable $e) {
            return $new;
        }
    }
    if (defined('WPS_IC_SETTINGS')) {
        add_filter('pre_update_option_' . WPS_IC_SETTINGS, 'wpc_settings_drift_guard', 10, 2);
    }
}


if (!function_exists('wpc_cache_first_enabled')) {
    /**
     * Cache-first = pages cache from the very first hit (no pre-crit no-store); crit/LCP landing
     * converges via per-URL HTML purge + warm instead of holding the page uncached. Default ON.
     * Kill-switches: add_filter('wpc_cache_first','__return_false'), define('WPC_CACHE_FIRST', false)
     * or define('WPC_CACHE_FIRST_DISABLE', true), or option wpc_cache_first = '0'.
     */
    function wpc_cache_first_enabled()
    {
        if (defined('WPC_CACHE_FIRST_DISABLE') && WPC_CACHE_FIRST_DISABLE) {
            $on = false;
        } elseif (defined('WPC_CACHE_FIRST')) {
            $on = (bool) WPC_CACHE_FIRST;
        } else {
            $opt = get_option('wpc_cache_first', '1');
            $on  = ($opt === '1' || $opt === 1 || $opt === true);
        }
        return (bool) apply_filters('wpc_cache_first', $on);
    }
}

// ─── Bounded observability log (last 40 events, autoload off) ────────────────────────────
if (!function_exists('wpc_cache_first_log')) {
    function wpc_cache_first_log($event, $key = '', $url = '', $layers = [])
    {
        // v7.10.518 — breadcrumb for the 61.5s renders. The phase timeline proves the whole
        // stall sits between shutdown pri 0 and pri 999, but 16 of our callbacks live in that
        // band, so knowing the LAST thing we logged (and when) names the one that then hung.
        // Set before any work so a hang inside this function still leaves the crumb.
        $GLOBALS['wpc_lastlog518'] = [(string) $event, microtime(true)];
        try {


            if (!apply_filters('wpc_cflog_verbose', true)
                && preg_match('/^(warm-rx|warm-wrote|warm-fired|warm-coalesced|warm-cron|kick-rx|second-wave)$/', (string) $event)) {
                return;
            }


            $entry = ['t' => time(), 'event' => (string) $event, 'key' => (string) $key, 'url' => (string) $url, 'layers' => $layers];
            $wpc_lf = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl' : '';
            $wpc_ok = false;
            if ($wpc_lf !== '') {
                // Purge windows can briefly take the cache dir out from under the logger —
                // repair + one retry, else B7/SLO receipts fall into the 3-cap DB fallback
                // and the latency distribution grows silent holes (drill forensics 2026-07-20).
                if (!is_dir(dirname($wpc_lf))) {
                    @mkdir(dirname($wpc_lf), 0777, true);
                }
                if (is_dir(dirname($wpc_lf))) {
                    if (@filesize($wpc_lf) > 262144) {
                        $wpc_tail = (string) @file_get_contents($wpc_lf, false, null, max(0, filesize($wpc_lf) - 65536));
                        $wpc_nl   = strpos($wpc_tail, "\n");
                        @file_put_contents($wpc_lf, $wpc_nl === false ? '' : substr($wpc_tail, $wpc_nl + 1), LOCK_EX);
                    }
                    $wpc_ok = (bool) @file_put_contents($wpc_lf, wp_json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
                    if (!$wpc_ok) {
                        $wpc_ok = (bool) @file_put_contents($wpc_lf, wp_json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
                    }
                }
            }
            if (!$wpc_ok) {


                static $wpc_dbfb75 = 0;
                if ($wpc_dbfb75 < 3) {
                    $wpc_dbfb75++;
                    $log = get_option('wpc_cache_first_log', []);
                    if (!is_array($log)) {
                        $log = [];
                    }
                    $log[] = $entry;
                    update_option('wpc_cache_first_log', array_slice($log, -40), false);
                }
            }


            // (auto toggle → 3-min floor → 25s throttle → single-flight), so this choke point
            // costs one boolean per event when Auto Mode is off.
            if (function_exists('wpc_auto_chain_maybe')
                && preg_match('/^(land-saved|fonts-landed|kick-rx|warm-rx)$/', (string) $event)) {
                wpc_auto_chain_maybe((string) $event);
            }


            if (function_exists('wpc_cohort_beacon_on') && wpc_cohort_beacon_on()) {
                if ($event === 'land-saved') {
                    wpc_cohort_beacon('crit_landed', ['key' => (string) $key]);
                } elseif ($event === 'fonts-landed') {
                    wpc_cohort_beacon('fonts_landed');
                } elseif ($event === 'warm-wrote' && defined('WPS_IC_CACHE') && defined('WPS_IC_CRITICAL')) {
                    $wpc_bl = rtrim(WPS_IC_CACHE, '/') . '/.beacon_armed';
                    if (!@file_exists($wpc_bl) && (string) $key !== '') {
                        $wpc_bc = WPS_IC_CRITICAL . $key . '/critical_desktop.css';
                        if (@is_readable($wpc_bc) && @filesize($wpc_bc) > 5) {
                            @touch($wpc_bl);
                            wpc_cohort_beacon('armed', ['key' => (string) $key]);
                        }
                    }
                }
            }
            // land-report beacon into the joint delivery ledger — land-saved is inline/reliable,
            // land-finalize is the settled point; throttled once per key|uuid, idempotent server-side.
            if (function_exists('wpc_land_report') && ($event === 'land-finalize' || $event === 'land-saved')) {
                wpc_land_report((string) $key);
            }
        } catch (\Throwable $e) {

        }
    }
}

if (!function_exists('wpc_land_report')) {
    /**
     * Land-report beacon → the joint delivery ledger (service §8, wire-proven both directions).
     * POST crit-push.zapwp.net/land-report, X-WPC-Sig/X-WPC-Ts headers (ms ts, ±600s), HMAC-SHA256
     * over uuid|url_key|ts keyed with the site apikey — the url_key signed IS the url_key sent.
     * Digest = cflog serialization (<=4KB, stored verbatim). Load-gated, blocking=false, throttled
     * once per key|uuid. A pinned box (load-gated out) leaves the row serve-probe-only, not blind.
     */
    function wpc_land_report($key)
    {
        try {
            if (!apply_filters('wpc_land_report_on', true)) { return; }
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) { return; }
            if (!function_exists('wp_remote_post') || !defined('WPS_IC_CRITICAL')) { return; }
            $key = (string) $key;
            if ($key === '' || (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($key))) { return; }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/';
            $rd  = function ($f) { return @is_readable($f) ? trim((string) @file_get_contents($f)) : ''; };
            $uuid = $rd($dir . 'land_uuid.txt');
            if ($uuid === '') { $uuid = $rd($dir . 'uuid.txt'); }
            if (strlen($uuid) < 8) { return; }
            $opt = (function_exists('get_option') && defined('WPS_IC_OPTIONS')) ? get_option(WPS_IC_OPTIONS) : [];
            $apikey = is_array($opt) && !empty($opt['api_key']) ? (string) $opt['api_key'] : '';
            if ($apikey === '') { return; }
            $seen = rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/lr_' . md5($key . '|' . $uuid) . '.txt';
            if (@is_readable($seen) && (time() - (int) @filemtime($seen)) < (int) apply_filters('wpc_land_report_ttl', 300)) { return; }
            if (!is_dir(dirname($seen))) { @mkdir(dirname($seen), 0777, true); }
            @touch($seen);
            $tl = []; $lf = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl' : '';
            if ($lf !== '' && @is_readable($lf)) {
                $tail = (string) @file_get_contents($lf, false, null, max(0, (int) @filesize($lf) - 65536));
                foreach (explode("\n", $tail) as $ln) {
                    if ($ln === '') { continue; }
                    $e = json_decode($ln, true);
                    if (!is_array($e) || !isset($e['event']) || (string) ($e['key'] ?? '') !== $key) { continue; }
                    $tl[] = ['t' => (int) ($e['t'] ?? 0), 'e' => (string) $e['event']];
                }
                $tl = array_slice($tl, -40);
            }
            $load1 = null;
            if (function_exists('sys_getloadavg')) { $la = @sys_getloadavg(); if (is_array($la) && isset($la[0])) { $load1 = round((float) $la[0], 2); } }
            $have = [
                'crit'  => @is_readable($dir . 'critical_desktop.css'),
                'delay' => @is_readable($dir . 'delay.json'),
                'lcp'   => @is_readable($dir . 'lcp.json'),
                'fonts' => (trim($rd($dir . 'fonts_url.txt')) !== '' || @is_readable($dir . 'fonts_none.txt')),
                'used'  => trim($rd($dir . 'used_css_url.txt')) !== '',
            ];
            $rn = count(array_filter($have));
            $digest = ['ve' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '', 'load1' => $load1,
                'ready_n' => $rn, 'ready_of' => count($have), 'have' => $have, 'timeline' => $tl];
            $dj = wp_json_encode($digest);
            if (is_string($dj) && strlen($dj) > 4096) {
                $digest['timeline'] = array_slice($tl, -10); $digest['clamp'] = 1; $dj = wp_json_encode($digest);
                if (is_string($dj) && strlen($dj) > 4096) { $digest['timeline'] = []; }
            }
            $body = wp_json_encode(['uuid' => $uuid, 'apikey' => $apikey, 'url_key' => $key, 'digest' => $digest]);
            if (!is_string($body)) { return; }
            $ts  = (string) (int) round(microtime(true) * 1000);
            $sig = hash_hmac('sha256', $uuid . '|' . $key . '|' . $ts, $apikey);
            wp_remote_post(apply_filters('wpc_land_report_url', 'https://crit-push.zapwp.net/land-report'), [
                'blocking' => false, 'timeout' => 2, 'redirection' => 0,
                'headers' => ['Content-Type' => 'application/json', 'X-WPC-Sig' => $sig, 'X-WPC-Ts' => $ts],
                'body' => $body,
            ]);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('land-report-sent', $key, '', ['uuid' => substr($uuid, 0, 8), 'ready' => $rn . '/' . count($have)]);
            }
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('wpc_warm_url_variants')) {
    /**
     * The 4 cache-variant request shapes. MUST mirror the writer (cacheHtml.php saveCache:
     * webp = Accept contains image/webp, mobile = is_mobile() UA regex) and the drop-in reader.
     * Order matters: webp forms fire LAST per device so the static mirror bucket (which collapses
     * webp) ends holding the webp render — what real browsers send.
     */
    function wpc_warm_url_variants()
    {
        $desktop = 'Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0';
        $mobile  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
        // v7.10.522 — 4 -> 2. The webp variants rendered BYTE-IDENTICAL HTML into a second
        // filename, so half of every fan-out was pure waste: on a 4-worker host, warming two
        // needless variants is half the pool. Kept behind the same single filter as the cache
        // key so the two can never drift apart — a key/fan-out mismatch would warm a file the
        // serve path never looks for.
        $wpc_vars522 = [
            ''            => ['ua' => $desktop, 'accept' => 'text/html'],
            'mobile'      => ['ua' => $mobile,  'accept' => 'text/html'],
        ];
        if (apply_filters('wpc_webp_cache_variant', false)) {
            $wpc_vars522['webp']        = ['ua' => $desktop, 'accept' => 'text/html,image/webp'];
            $wpc_vars522['mobile-webp'] = ['ua' => $mobile,  'accept' => 'text/html,image/webp'];
        }
        return $wpc_vars522;
    }
}

if (!function_exists('wpc_warm_url_queue')) {
    /**
     * Queue a non-blocking warm of ONE URL (all 4 variants) after its HTML was purged, so even the
     * first visitor after a crit/LCP land gets an optimized cache hit. Fail-safe: any bail-out just
     * leaves the page to warm on first visit (today's behavior), never worse.
     */

    // wpc_service_warm removed (joint spec v2 S2): the /warm endpoint never shipped
    // service-side — every POST was a silent 404 no-op. Purge-path regen is owned by
    // wpc_crit_purge_redispatch.
    function wpc_warm_url_queue($url, $context = '')
    {
        static $queued = [];
        try {
            if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
                return false;
            }
            if (defined('WPC_URL_WARM_DISABLE') && WPC_URL_WARM_DISABLE) {
                return false;
            }
            if (!apply_filters('wpc_url_warm_on_purge', (bool) get_option('wpc_url_warm_on_purge', true))) {
                return false;
            }
            if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'sanitizeSameHostUrl')) {
                $url = wps_ic_url_key::sanitizeSameHostUrl($url);
            }
            if (empty($url)) {
                return false;
            }
            if (isset($queued[$url])) {
                return true;
            }


            // (joint spec v2 S2): /warm never shipped service-side — the POST was a 404
            // no-op; purge-path regen rides wpc_crit_purge_redispatch instead.


            if (defined('WPS_IC_SETTINGS')) {
                $wpc_settings = get_option(WPS_IC_SETTINGS);
                if (empty($wpc_settings['cache']['advanced']) || $wpc_settings['cache']['advanced'] == '0') {
                    return false;
                }
            }
            // The loopback can't land on a Basic-Auth site.
            if (function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth()) {
                return false;
            }


            $wpc_lock = 'wpc_warm_lock_' . md5($url);
            $wpc_debounced = (bool) get_transient($wpc_lock);
            set_transient($wpc_lock, 1, (int) apply_filters('wpc_url_warm_debounce_seconds', 25));
            if ($wpc_debounced) {
                wpc_cache_first_log('warm-coalesced', '', $url, ['context' => $context]);
                return true; // the debounce must actually gate (was log-only — latent bug)
            }


            $wpc_bucket = (int) get_transient('wpc_warm_bucket');
            $wpc_max    = max(1, (int) apply_filters('wpc_warm_max_batches_per_minute', 12));
            if ($wpc_bucket >= $wpc_max) {
                if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_url_warm', [$url, 1])) {
                    wpc_pl_sched(time() + 90, 'wpc_url_warm', [$url, 1]);
                }
                wpc_cache_first_log('warm-deferred', '', $url, ['context' => $context]);
                return true;
            }
            set_transient('wpc_warm_bucket', $wpc_bucket + 1, 60);

            $queued[$url] = true;
            register_shutdown_function('wpc_warm_url_fire', $url);
            // Cron backstop: verifies all 4 variants actually landed, re-fires the missing ones.
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_url_warm', [$url, 1])) {
                wpc_pl_sched(time() + 60, 'wpc_url_warm', [$url, 1]);
            }
            wpc_cache_first_log('warm-fired', '', $url, ['context' => $context]);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_crit_resync_wave_h')) {
    /** Follow-up waves after a resync click: re-open the one-shot used-css gates and
     *  re-pull, so observe-leg artifacts (geometry/fonts/used amendments) land without
     *  another human action. Bounded: one probe + one kick per wave, pressure-yielding. */
    function wpc_crit_resync_wave_h($urlKey, $wave = 0)
    {
        try {
            if ((string) $urlKey === '' || !defined('WPS_IC_CRITICAL')) {
                return;
            }
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                if ((int) $wave < 1000 && function_exists('wpc_pl_sched')) {
                    wpc_pl_sched(time() + 120, 'wpc_crit_resync_wave', [(string) $urlKey, (int) $wave + 1000]);
                }
                return;
            }
            $wpc_n334 = 0;
            foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/used-css/tpl-*.css') as $wpc_gf334) {
                if (++$wpc_n334 > 20) {
                    break;
                }
                delete_transient('wpc_ucsr266_' . md5($wpc_gf334));
                delete_transient('wpc_ucsp_' . md5($wpc_gf334));
            }
            // Amendments land IN PLACE on the same uuid (geometry bake, observe leg) —
            // the collector dedupes on unchanged uuid, so force the re-pull like
            // refetch=crit does: drop the land stamp (atomic overwrite, never critless).
            @unlink(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/land_uuid.txt');
            delete_transient('wpc_repull_kick_' . md5((string) $urlKey));
            delete_transient('wpc_prescfetch_' . md5((string) $urlKey));
            delete_transient('wpc_prescpoll_' . md5((string) $urlKey));
            if (function_exists('wpc_repull_kick_now')) {
                wpc_repull_kick_now((string) $urlKey);
            }
            if (function_exists('wpc_crit_collect_now')) {
                wpc_crit_collect_now((string) $urlKey, 1);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('resync-wave', (string) $urlKey, '', ['w' => (int) $wave]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_crit_resync_wave', 'wpc_crit_resync_wave_h', 10, 2);
}

if (!function_exists('wpc_purge_hot_set')) {
    /** Pages worth re-warming after a full wipe = dirs with crit identity. url.txt
     *  survives every purge by design, so the hot set survives the wipe it heals. */
    function wpc_purge_hot_set($cap = 12)
    {
        $wpc_scored111 = [];
        try {
            if (!defined('WPS_IC_CRITICAL')) {
                return [];
            }
            foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*', GLOB_ONLYDIR) as $wpc_d111) {
                $wpc_u111 = trim((string) @file_get_contents($wpc_d111 . '/url.txt'));
                if ($wpc_u111 === '' || !preg_match('#^https?://#i', $wpc_u111)) {
                    continue;
                }
                $wpc_scored111[$wpc_u111] = max((int) @filemtime($wpc_d111 . '/land_ts.txt'),
                    (int) @filemtime($wpc_d111 . '/dispatch_ts.txt'), (int) @filemtime($wpc_d111 . '/tpl.txt'));
            }
            arsort($wpc_scored111);
        } catch (\Throwable $e) {
        }
        return array_slice(array_keys($wpc_scored111), 0, max(1, (int) $cap));
    }

    /** Staggered single events, one page per slot — the rebuild must never become the
     *  storm it exists to prevent. Home rides the immediate warm queue, not this wave. */
    function wpc_purge_rewarm_hot_set($context = '')
    {
        try {
            if (!function_exists('wpc_pl_sched') || !function_exists('wpc_warm_url_queue')) {
                return 0;
            }
            if (get_transient('wpc_rewarm_hot_lock')) {
                return 0;
            }
            // DEFAULT OFF: the hot set is SPECULATION. It pays a full uncached render (3-4s on a
            // small box) for pages nobody has asked for, and the homepage — the one page whose
            // traffic is actually predictable — is excluded from this set anyway because it rides
            // the immediate warm queue. So home-only is the honest default; a stale sub-page costs
            // exactly one on-demand render when a visitor arrives, versus twelve speculative ones
            // that a hot box will re-purge before anyone reads them. Raise per site if the box has
            // headroom and the traffic spread justifies it.
            if (!apply_filters('wpc_purge_rewarm_enabled', false)) {
                if (function_exists('wpc_cache_first_log') && !get_transient('wpc_rewarm_off_log')) {
                    set_transient('wpc_rewarm_off_log', 1, 900);
                    wpc_cache_first_log('rewarm-hot-set-off', '', '', [
                        'context' => (string) $context,
                        'note'    => 'home-only default; enable via wpc_purge_rewarm_enabled',
                    ]);
                }
                return 0;
            }
            // The 20s stagger bounds the RATE, not the AMOUNT. Where an uncached render costs
            // 3-4s on 2 cores, 12 staggered warms are still 12 renders the box cannot absorb and
            // the rebuild becomes the storm it exists to prevent (busy: 13 queued at load 42).
            // Under real pressure shed the wave ENTIRELY — the homepage rides the immediate warm
            // queue and is excluded from this set by design, so shedding leaves exactly
            // home-only. A hot box will re-purge whatever we warm anyway, and a visitor-triggered
            // render is one render on demand instead of twelve speculative ones.
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                if (function_exists('wpc_cache_first_log') && !get_transient('wpc_rewarm_shed_log')) {
                    set_transient('wpc_rewarm_shed_log', 1, 300);
                    wpc_cache_first_log('rewarm-hot-set-shed', '', '', [
                        'context' => (string) $context,
                        'cores'   => function_exists('wpc_box_cores') ? (int) wpc_box_cores() : 0,
                        'note'    => 'home-only; hot set deferred until pressure clears',
                    ]);
                }
                return 0;
            }
            set_transient('wpc_rewarm_hot_lock', 1, 300);
            // Scale the fan-out to the hardware: 2 cores -> 4 pages, 4 -> 8, 8+ -> the old 12.
            // Widen the stagger on small boxes, where each render occupies a larger share of it.
            $wpc_cores111 = function_exists('wpc_box_cores') ? max(1, (int) wpc_box_cores()) : 2;
            $wpc_cap111  = max(1, (int) apply_filters('wpc_purge_rewarm_cap', min(12, $wpc_cores111 * 2)));
            $wpc_gap111 = max(10, (int) apply_filters('wpc_purge_rewarm_gap_seconds', $wpc_cores111 <= 2 ? 45 : 20));
            $wpc_home111 = function_exists('home_url') ? untrailingslashit(home_url('/')) : '';
            $wpc_i111 = 0;
            foreach (wpc_purge_hot_set($wpc_cap111) as $wpc_hu111) {
                if ($wpc_home111 !== '' && untrailingslashit($wpc_hu111) === $wpc_home111) {
                    continue;
                }
                wpc_pl_sched(time() + 8 + $wpc_gap111 * $wpc_i111++, 'wpc_url_warm', [$wpc_hu111, 1]);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('rewarm-hot-set', '', '', ['n' => $wpc_i111, 'context' => (string) $context]);
            }
            return $wpc_i111;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('wpc_warm_url_fire')) {
    /**
     * Shutdown transport: flush the triggering response, then fire the 4 variant renders as
     * non-blocking local-vhost loopbacks (the proven fireHomepageWarm transport, generalized).
     * The receiver survives the client-close via the X-WPC-Cache-Warm guard in wp-compress.php.
     */
    function wpc_warm_url_fire($url, $only_variants = null)
    {
        try {
            // v7.10.609 — canonical form BEFORE the fetch. The field log showed two cron
            // loopbacks to /about-us with no trailing slash, 2049ms + 2005ms: WordPress answers
            // a canonical 301, so each warm booted WordPress twice.
            if (function_exists('wpc_canon_url609')) {
                $url = wpc_canon_url609($url);
            }
            // v7.10.520 BELT 1 — PARITY. wpc_warm_url_queue() has refused to run inside a
            // warm request since it was written; fire() never did. A warm loopback could
            // therefore fan out four more, and only a racy 15s global cooldown stood in the
            // way. Measured: 3 hard refreshes produced ~19 concurrent 724KB renders.
            if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('warm-refire-blocked', '', (string) $url, []);
                }
                return false;
            }
            // BELT 2 — a request that CANNOT cache must not trigger a warm. A hard refresh
            // sends Cache-Control: no-cache, saveCache bails at the server-control gate, so
            // nothing is stored — which makes the "variants missing" signal permanently true
            // and re-fires the whole 4-variant fan-out on every single refresh.
            $wpc_cc520 = strtolower((string) ($_SERVER['HTTP_CACHE_CONTROL'] ?? ''));
            if ($wpc_cc520 !== '' && (strpos($wpc_cc520, 'no-cache') !== false
                || strpos($wpc_cc520, 'no-store') !== false)) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('warm-skip-uncacheable', '', (string) $url, ['cc' => substr($wpc_cc520, 0, 24)]);
                }
                return false;
            }
            // BELT 3 — the 15s cooldown was get_transient()-then-set_transient(): a
            // read-then-write race that ~19 simultaneous requests walk straight through.
            // GET_LOCK(name,0) is atomic, so exactly one caller can win.
            if (function_exists('wpc_worker_lock') && !wpc_worker_lock('warm_fire_gate')) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('warm-gate-contended', '', (string) $url, []);
                }
                return false;
            }
            // v7.10.531 — the release was a NESTED shutdown function, so it ran last and the gate
            // was held across fastcgi_finish_request() and the whole fan-out: captured holding
            // GET_LOCK for 33s with its MySQL connection in Sleep (blocked on loopback HTTP, not
            // on a query). FPM reports that phase as "Finishing" and request_slowlog_timeout
            // cannot see it, so the most expensive lock in the plugin was structurally invisible.
            // The shutdown release stays as a crash belt only; the real release is explicit, the
            // moment the cooldown transient makes the decision durable.
            if (function_exists('wpc_worker_unlock')) {
                register_shutdown_function(function () { @wpc_worker_unlock('warm_fire_gate'); });
            }
            $wpc_gate531 = true;
            if (function_exists('wpc_bg_slot_take') && !wpc_bg_slot_take('warm')) {
                if (!empty($wpc_gate531) && function_exists('wpc_worker_unlock')) {
                    @wpc_worker_unlock('warm_fire_gate');
                }
                return;
            }


            $wpc_cool117 = (int) apply_filters('wpc_warm_global_cooldown', 15);
            if ($wpc_cool117 > 0 && function_exists('get_transient')) {
                if (get_transient('wpc_warm_cooldown')) {
                    if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('warm-cooldown-skip', '', $url, []); }
                    if (!empty($wpc_gate531) && function_exists('wpc_worker_unlock')) {
                        @wpc_worker_unlock('warm_fire_gate');
                    }
                    return false;
                }
                set_transient('wpc_warm_cooldown', 1, $wpc_cool117);
            }
            // Decision is now durable in the cooldown — the gate has done its whole job. Everything
            // below (jitter + loopback renders) must NOT hold it.
            if (!empty($wpc_gate531) && function_exists('wpc_worker_unlock')) {
                @wpc_worker_unlock('warm_fire_gate');
                $wpc_gate531 = false;
            }
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }

            $wpc_jitter = (int) apply_filters('wpc_url_warm_jitter_ms', 800);
            if ($wpc_jitter > 0) {
                @usleep(mt_rand(0, $wpc_jitter) * 1000);
            }
            $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
            if (empty($parts['host'])) {
                return;
            }
            $https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
            $port  = !empty($parts['port']) ? (int) $parts['port'] : ($https ? 443 : 80);
            $host  = (string) $parts['host'];
            $path  = !empty($parts['path']) ? $parts['path'] : '/';


            if (substr($path, -1) !== '/' && !preg_match('/\.[a-z0-9]{2,5}$/i', $path)) {
                $path .= '/';
            }

            $wpc_socket_ok = class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket');
            $wpc_batch117  = 0;
            foreach (wpc_warm_url_variants() as $wpc_variant => $wpc_v) {
                if (is_array($only_variants) && !in_array($wpc_variant, $only_variants, true)) {
                    continue;
                }
                if (++$wpc_batch117 > (int) apply_filters('wpc_warm_batch_cap', 2)) {
                    break;
                }


                // ONE transport per variant: the socket loopback when available, wp_remote_get
                // only as its fallback. Double-firing doubled the self-inflicted render load.
                if (!$wpc_socket_ok) {
                    wp_remote_get($url, [
                        'blocking' => false,
                        'timeout'  => 2,
                        'headers'  => [
                            'user-agent'       => $wpc_v['ua'],
                            'accept'           => $wpc_v['accept'],
                            'X-WPC-Cache-Warm' => '1',
                        ],
                    ]);
                }
                if ($wpc_socket_ok) {
                    $req = "GET {$path} HTTP/1.1\r\n"
                         . "Host: {$host}\r\n"
                         . "User-Agent: {$wpc_v['ua']}\r\n"
                         . "Accept: {$wpc_v['accept']}\r\n"
                         . "X-WPC-Cache-Warm: 1\r\n"
                         . "Connection: close\r\n\r\n";
                    $fp = wps_ic_ajax::wpc_loopback_open_socket($host, $port, $https, 0.2);
                    if ($fp) {
                        @stream_set_timeout($fp, 0, 100000);
                        @fwrite($fp, $req);
                        @fclose($fp);
                    }
                }
                // Small gap so the 4 renders queue politely instead of landing simultaneously.
                @usleep(mt_rand(150, 400) * 1000);
            }
        } catch (\Throwable $e) {

        }
        return true;
    }
}

if (!function_exists('wpc_warm_rx_gate')) {
    /**
     * Receiver-side shed: our own warm loopbacks may never exhaust the FPM pool. At most
     * wpc_warm_rx_cap warm renders run concurrently; excess loopbacks get an instant 204
     * (the cron backstop re-fires anything that didn't land). Real visitors are never shed.
     */
    function wpc_warm_rx_gate()
    {
        try {
            if (empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])
                || !empty($_SERVER['HTTP_X_WPC_DIAG'])
                || (defined('DOING_CRON') && DOING_CRON)
                || (function_exists('wp_doing_ajax') && wp_doing_ajax())) {
                return;
            }
            $now = time();
            $sl  = get_option('wpc_warm_rx_slots');
            $sl  = is_array($sl) ? array_values(array_filter($sl, function ($t) use ($now) {
                return ($now - (int) $t) < 120;
            })) : [];
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('warm-rx-shed', '', '', ['why' => 'pressure']);
                }
                if (function_exists('status_header')) { status_header(204); }
                exit;
            }
            $cap = max(1, (int) apply_filters('wpc_warm_rx_cap', 1));
            if (count($sl) >= $cap) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('warm-rx-shed', '', '', ['inflight' => count($sl)]);
                }
                if (function_exists('status_header')) { status_header(204); }
                exit;
            }
            $sl[] = $now;
            update_option('wpc_warm_rx_slots', $sl, false);
            register_shutdown_function(function () use ($now) {
                try {
                    $sl = get_option('wpc_warm_rx_slots');
                    if (is_array($sl)) {
                        $k = array_search($now, $sl, true);
                        if ($k !== false) {
                            unset($sl[$k]);
                            update_option('wpc_warm_rx_slots', array_values($sl), false);
                        }
                    }
                } catch (\Throwable $e) {
                }
            });
            // v7.10.691 — warm traffic is the wire's heartbeat. The kick-lane catchup only runs
            // on repull branches, which a HEALTHY site may not enter for hours — the exact state
            // that left c3720e5a's crit serving with 0a0080ce's manifest. Warms are internal and
            // detached (nobody waits on them), fire on every update-window/land wave, and each
            // one heals its own URL. Transient-gated inside (300s): ~3 file reads per warm.
            if (function_exists('wpc_wire_catchup') && class_exists('wps_ic_url_key')) {
                $wpc_wk691 = ltrim((string) (new wps_ic_url_key())->setup(''), '/');
                if ($wpc_wk691 !== ''
                    && !(function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($wpc_wk691))) {
                    wpc_wire_catchup($wpc_wk691);
                }
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('init', 'wpc_warm_rx_gate', 0);
}

if (!function_exists('wpc_url_warm_cron_handler')) {
    /**
     * Cron backstop: check which of the 4 hashed-cache variant files actually landed; re-fire only
     * the missing ones. Max 2 attempts, then leave the rest to live traffic.
     */
    function wpc_url_warm_cron_handler($url, $attempt = 1)
    {
        // .473: Safe Mode stands the DRAIN down too, not just admission — a cron event already
        // in the row would otherwise run. Returns without rescheduling, so nothing accumulates.
        if (function_exists('wpc_bg_lane_allowed') && !wpc_bg_lane_allowed('wpc_url_warm')) {
            return;
        }
        try {
            if (empty($url) || !defined('WPS_IC_CACHE') || !class_exists('wps_ic_url_key')) {
                return;
            }
            if (method_exists('wps_ic_url_key', 'sanitizeSameHostUrl')) {
                $url = wps_ic_url_key::sanitizeSameHostUrl($url);
                if ($url === '') {
                    return;
                }
            }
            $wpc_key = (new wps_ic_url_key())->setup($url);
            if (empty($wpc_key)) {
                return;
            }
            if (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($wpc_key)) {
                return;
            }
            $wpc_dir     = rtrim(WPS_IC_CACHE, '/') . '/' . $wpc_key . '/';
            $wpc_missing = [];
            foreach (array_keys(wpc_warm_url_variants()) as $wpc_variant) {
                $wpc_prefix = $wpc_variant === '' ? '' : $wpc_variant . '_';
                if (!@file_exists($wpc_dir . $wpc_prefix . 'index.html_gzip')) {
                    $wpc_missing[] = $wpc_variant;
                }
            }
            if (empty($wpc_missing)) {
                return;
            }
            // Sender-side yield: never fire warm renders INTO pressure (the receiver shed
            // just burns a retry), and small boxes rebuild one variant per tick
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                $wpc_rkp49 = 'wpc_warm_resk_' . md5($url);
                $wpc_rcp49 = (int) get_transient($wpc_rkp49);
                if ($wpc_rcp49 < 6 && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_url_warm', [$url, (int) $attempt])) {
                    set_transient($wpc_rkp49, $wpc_rcp49 + 1, HOUR_IN_SECONDS);
                    wpc_pl_sched(time() + 180, 'wpc_url_warm', [$url, (int) $attempt]);
                }
                return;
            }
            if (function_exists('wpc_box_cores') && wpc_box_cores() <= 2 && count($wpc_missing) > 1) {
                $wpc_missing = array_slice($wpc_missing, 0, 1);
                // Bounded by the same resk counter as the pressure path — never an open loop
                $wpc_rks49 = 'wpc_warm_resk_' . md5($url);
                $wpc_rcs49 = (int) get_transient($wpc_rks49);
                if ($wpc_rcs49 < 6 && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_url_warm', [$url, (int) $attempt])) {
                    set_transient($wpc_rks49, $wpc_rcs49 + 1, HOUR_IN_SECONDS);
                    wpc_pl_sched(time() + 60, 'wpc_url_warm', [$url, (int) $attempt]);
                }
            }
            $wpc_fired49 = wpc_warm_url_fire($url, $wpc_missing);
            wpc_cache_first_log('warm-cron', $wpc_key, $url, ['missing' => $wpc_missing, 'attempt' => (int) $attempt, 'fired' => $wpc_fired49 ? 1 : 0]);
            if ($wpc_fired49 === false) {


                $wpc_rk49 = 'wpc_warm_resk_' . md5($url);
                $wpc_rc49 = (int) get_transient($wpc_rk49);
                if ($wpc_rc49 < 6 && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_url_warm', [$url, (int) $attempt])) {
                    set_transient($wpc_rk49, $wpc_rc49 + 1, HOUR_IN_SECONDS);
                    wpc_pl_sched(time() + 45, 'wpc_url_warm', [$url, (int) $attempt]);
                }
                return;
            }
            if ((int) $attempt < 2 && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_url_warm', [$url, (int) $attempt + 1])) {
                wpc_pl_sched(time() + 90, 'wpc_url_warm', [$url, (int) $attempt + 1]);
            }
        } catch (\Throwable $e) {

        }
    }
    add_action('wpc_url_warm', 'wpc_url_warm_cron_handler', 10, 2);
}


if (!function_exists('wpc_crit_cur_uuid')) {
    /** The gen uuid to derive artifact URLs from — land_uuid (what actually
     *  landed) preferred over uuid (overwritten at dispatch). Requires ≥8 HEX
     *  chars so a degenerate value (all-dashes) can't derive a malformed URL. */
    function wpc_crit_cur_uuid($dir)
    {
        $dir = rtrim((string) $dir, '/') . '/';
        foreach (['land_uuid.txt', 'uuid.txt'] as $uf) {
            $uuid = preg_replace('/[^a-f0-9-]/i', '', trim((string) @file_get_contents($dir . $uf)));
            if (preg_match('/[a-f0-9]{8}/i', $uuid)) {
                return $uuid;
            }
        }
        return '';
    }
}
if (!function_exists('wpc_crit_artifact_url')) {
    /** URL of an observation artifact (delay.json / lcp.json), meta-or-DERIVED.
     *  The service returns /status=success (crit done) with NO delay_url/lcp_url
     *  BEFORE the observation artifacts land, and nothing re-polls after success —
     *  so the URL meta is frequently absent while {uuid[:4]}/{uuid}.<artifact> is
     *  already 200 (busyprosai bd175a43, confirmed live: 29KB measured delay.json +
     *  lcp.json with lcp_preload). Prefer the advertised meta; derive from the
     *  on-disk uuid only as the fallback. ?t= busts Bunny's edge so an IN-PLACE
     *  amendment (same uuid, richer body) is seen. Suffixes are the service's
     *  canonical layout (delay/lcp fleet-confirmed 200). */
    function wpc_crit_artifact_url($dir, $which)
    {
        $suf = ['delay' => '.delay.json', 'lcp' => '.lcp.json'];
        if (!isset($suf[$which])) {
            return '';
        }
        $dir  = rtrim((string) $dir, '/') . '/';
        $meta = $dir . ($which === 'delay' ? 'delay_url.txt' : 'lcp_url.txt');
        if (@is_readable($meta)) {
            $u = trim((string) @file_get_contents($meta));
            if ($u !== '' && preg_match('#^https?://#i', $u)) {
                return $u . (strpos($u, '?') !== false ? '&' : '?') . 't=' . time();
            }
        }
        $uuid = wpc_crit_cur_uuid($dir);
        if ($uuid !== '') {
            return 'https://critical-css-mc.b-cdn.net/' . substr($uuid, 0, 4) . '/' . $uuid . $suf[$which] . '?t=' . time();
        }
        return '';
    }
}
if (!function_exists('wpc_lcp_has_facts776')) {
    /** USABLE atf facts = an entry carrying stem+css_w in some leg (legged or flat). */
    function wpc_lcp_has_facts776($j)
    {
        if (!is_array($j) || !isset($j['atf_images']) || !is_array($j['atf_images'])) { return false; }
        $l = [];
        foreach (['mobile', 'desktop'] as $g) {
            if (isset($j['atf_images'][$g]) && is_array($j['atf_images'][$g])) { $l = array_merge($l, $j['atf_images'][$g]); }
        }
        if (empty($l)) { $l = $j['atf_images']; }
        foreach ((array) $l as $e) {
            if (is_array($e) && !empty($e['stem']) && !empty($e['css_w'])) { return true; }
        }
        return false;
    }
}
if (!function_exists('wpc_lcp_write_preserve781')) {
    /** THE ONLY lcp.json WRITER. Census facts live on the gen uuid, so a regen ships a
     *  factless artifact and the pointer follows it — the sizes bake would flap off on
     *  every regen until the next mint (up to a day on a 1/domain budget). Facts already
     *  measured for THIS url survive: grafted onto the incoming body, marked inherited
     *  (never laundered as native), age-bounded, and only ever when the incoming body
     *  carries none of its own. Native facts always win. */
    function wpc_lcp_write_preserve781($dir, $body)
    {
        $dir = rtrim((string) $dir, '/') . '/';
        if (!is_string($body) || $body === '') { return false; }
        $wpc_in781 = json_decode($body, true);
        if (!is_array($wpc_in781)) { return false; }
        $wpc_f781 = $dir . 'lcp.json';
        if (apply_filters('wpc_lcp_carry_facts', true)
            && !wpc_lcp_has_facts776($wpc_in781) && @is_readable($wpc_f781)) {
            $wpc_mt781 = (int) @filemtime($wpc_f781);
            $wpc_cur781 = json_decode((string) @file_get_contents($wpc_f781), true);
            if (is_array($wpc_cur781) && wpc_lcp_has_facts776($wpc_cur781) && $wpc_mt781 > 0
                && (time() - $wpc_mt781) < (int) apply_filters('wpc_lcp_carry_max_age', 7 * DAY_IN_SECONDS)) {
                $wpc_in781['atf_images'] = $wpc_cur781['atf_images'];
                if (empty($wpc_in781['oversized_images']) && !empty($wpc_cur781['oversized_images'])) {
                    $wpc_in781['oversized_images'] = $wpc_cur781['oversized_images'];
                }
                $wpc_in781['wpc_inherited'] = [
                    't' => $wpc_mt781,
                    'from' => isset($wpc_cur781['census']['method']) ? (string) $wpc_cur781['census']['method']
                        : (!empty($wpc_cur781['wpc_inherited']['from']) ? (string) $wpc_cur781['wpc_inherited']['from'] : 'prev'),
                ];
                $wpc_enc781 = wp_json_encode($wpc_in781);
                if (is_string($wpc_enc781) && $wpc_enc781 !== '') {
                    $body = $wpc_enc781;
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('lcp-facts-carried', '', '', [
                            'age' => time() - $wpc_mt781,
                            'from' => (string) $wpc_in781['wpc_inherited']['from'],
                        ]);
                    }
                }
            }
        }
        return wpc_crit_meta_write($wpc_f781, $body);
    }
}
if (!function_exists('wpc_lcp_taint_pending776')) {
    /** On-disk lcp.json is a challenged taint with no census and no usable facts, <7d old
     *  — the shape the in-place amend replaces. Shared by the recovery trigger AND the
     *  traffic carrier's converged-gate: a caller that returns on "measured delay" must
     *  still fall through while this is true. */
    function wpc_lcp_taint_pending776($dir)
    {
        if (!@is_readable($dir . 'lcp.json')) { return false; }
        $mt = (int) @filemtime($dir . 'lcp.json');
        if ($mt <= 0 || (time() - $mt) >= 7 * DAY_IN_SECONDS) { return false; }
        $j = json_decode((string) @file_get_contents($dir . 'lcp.json'), true);
        if (!is_array($j) || empty($j['nav_debug']['challenged'])) { return false; }
        // A census whose facts were INHERITED (service carry-forward, v3.198.24: method
        // "inherited", or per-leg census.legs{mobile|desktop}) is not this gen's own
        // measurement — same standing as our own wpc_inherited marker, so it must not
        // retire the refetch. Any single inherited leg keeps us converging.
        if (isset($j['census'])) {
            $wpc_inh784 = false;
            if (is_array($j['census'])) {
                $wpc_lg784 = (isset($j['census']['legs']) && is_array($j['census']['legs'])) ? $j['census']['legs'] : [];
                foreach (['mobile', 'desktop'] as $wpc_d784) {
                    if (isset($wpc_lg784[$wpc_d784]) && (string) $wpc_lg784[$wpc_d784] === 'inherited') { $wpc_inh784 = true; }
                }
                if (!$wpc_inh784 && isset($j['census']['method'])
                    && (string) $j['census']['method'] === 'inherited') { $wpc_inh784 = true; }
            }
            if (!$wpc_inh784) { return false; }
        }
        // Inherited facts keep the bake armed but are NOT this gen's measurement — the
        // refetch keeps trying for native ones until the mint (or carry-forward) lands.
        return !wpc_lcp_has_facts776($j) || !empty($j['wpc_inherited']);
    }
}
if (!function_exists('wpc_amend_delay_recovery')) {
    /** IN-PLACE AMENDMENT RECOVERY (Artifact Identity Contract 2026-07-22): the
     *  service amends artifacts under a STABLE uuid — ceiling / lcp_preload /
     *  schema_epoch land AFTER the initial crit, so a by-URL cache pins the pre-
     *  amendment copy (busyprosai). While the on-disk delay.json is NOT measured-
     *  shape, re-fetch it (meta-or-DERIVED URL) IGNORING write-once; overwrite ONLY
     *  if the refetch IS measured-shape (never regress). Self-throttled ≤once/5min
     *  per key; retires the instant it's measured. A land also re-pulls the amended
     *  lcp.json (replace-on-200, never blanks a working hero). Extracted so BOTH the
     *  cron/rail repull AND the cron-FREE traffic carrier (wpc_amend_traffic_enqueue,
     *  post-response) run the exact same recovery. Returns true on a measured land. */
    function wpc_amend_delay_recovery($urlKey)
    {
        if ((string) $urlKey === '' || !defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_js_delay_v3')
            || !apply_filters('wpc_delay_amend_refetch', true)
            || (function_exists('wpc_under_pressure') && wpc_under_pressure())) {
            return false; // load gate: the refetch is blocking HTTP — yield to visitors when hot
        }
        $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
        // A tainted lcp.json (challenged legs, facts withheld) is amended IN PLACE under the
        // same uuid after gen completes — the write-once law pins the taint, and the delay
        // measured-shape gate below never runs on a converged site. Re-fetch while the taint
        // shape persists; replace only when the refetch carries facts. Never regress.
        if (apply_filters('wpc_lcp_taint_refetch', true)
            && !get_transient('wpc_amend_lj_' . md5((string) $urlKey))) {
            if (wpc_lcp_taint_pending776($dir)) {
                set_transient('wpc_amend_lj_' . md5((string) $urlKey), 1, HOUR_IN_SECONDS);
                $wpc_lju774 = wpc_crit_artifact_url($dir, 'lcp');
                // Every exit journals: 'ran, got factless' and 'never ran' must never be
                // the same silence again.
                $wpc_miss779 = '';
                if ($wpc_lju774 !== '' && preg_match('#^https?://#i', $wpc_lju774)) {
                    $wpc_ljr774 = wp_remote_get($wpc_lju774, ['headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : ''], 'timeout' => 5]);
                    $wpc_ljcode779 = !is_wp_error($wpc_ljr774) ? (int) wp_remote_retrieve_response_code($wpc_ljr774) : -1;
                    if ($wpc_ljcode779 === 200) {
                        $wpc_ljb774 = wp_remote_retrieve_body($wpc_ljr774);
                        $wpc_ljj774 = json_decode((string) $wpc_ljb774, true);
                        if (is_array($wpc_ljj774)
                            && (wpc_lcp_has_facts776($wpc_ljj774) || isset($wpc_ljj774['census']))) {
                            wpc_lcp_write_preserve781($dir, $wpc_ljb774);
                            if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                                function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($urlKey, '', 'lcp-amend-land') : wps_ic_cache_integrations::purgeUrlHtml($urlKey, '', ['context' => 'lcp-amend-land']);
                            }
                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('lcp-amend-landed', (string) $urlKey, '', ['bytes' => strlen((string) $wpc_ljb774), 'method' => isset($wpc_ljj774['census']['method']) ? (string) $wpc_ljj774['census']['method'] : '']);
                            }
                        } else {
                            $wpc_miss779 = 'shelf-factless';
                        }
                    } else {
                        $wpc_miss779 = 'http-' . $wpc_ljcode779;
                    }
                } else {
                    $wpc_miss779 = 'no-artifact-url';
                }
                if ($wpc_miss779 !== '' && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('lcp-amend-miss', (string) $urlKey, '', ['why' => $wpc_miss779, 'url' => substr((string) $wpc_lju774, -60)]);
                }
            }
        }
        if (!@is_readable($dir . 'delay.json') || get_transient('wpc_amend_dj_' . md5((string) $urlKey))) {
            return false;
        }
        $wpc_djcur = json_decode((string) @file_get_contents($dir . 'delay.json'), true);
        if (wps_ic_js_delay_v3::wpc_delay_measured_shape($wpc_djcur)) {
            return false; // already measured — converged
        }
        set_transient('wpc_amend_dj_' . md5((string) $urlKey), 1, 300);
        $wpc_amu = wpc_crit_artifact_url($dir, 'delay');
        if ($wpc_amu === '' || !preg_match('#^https?://#i', $wpc_amu)) {
            return false;
        }
        $wpc_amr = wp_remote_get($wpc_amu, ['headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : ''], 'timeout' => 5]);
        if (is_wp_error($wpc_amr) || (int) wp_remote_retrieve_response_code($wpc_amr) !== 200) {
            return false;
        }
        $wpc_amb = wp_remote_retrieve_body($wpc_amr);
        $wpc_amj = json_decode((string) $wpc_amb, true);
        if (!wps_ic_js_delay_v3::wpc_delay_measured_shape($wpc_amj)) {
            return false; // never regress
        }
        wpc_crit_meta_write($dir . 'delay.json', $wpc_amb);
        // Re-pull the amended lcp.json in the SAME pass (hero lcp_preload), replace-on-200
        // only — a transient miss never blanks a working hero (never-blank law).
        $wpc_alu = wpc_crit_artifact_url($dir, 'lcp');
        if ($wpc_alu !== '') {
            $wpc_alr = wp_remote_get($wpc_alu, ['headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : ''], 'timeout' => 5]);
            if (!is_wp_error($wpc_alr) && (int) wp_remote_retrieve_response_code($wpc_alr) === 200) {
                $wpc_alb = wp_remote_retrieve_body($wpc_alr);
                if (is_string($wpc_alb) && $wpc_alb !== '' && is_array(json_decode($wpc_alb, true))) {
                    wpc_lcp_write_preserve781($dir, $wpc_alb);
                }
            }
        }
        @unlink($dir . 'fonts_none.txt');
        @unlink($dir . 'lcp_none.txt');
        delete_option('wpc_delay_v3_manifest_off');
        if (function_exists('wpc_delay_aggr_rearm')) { wpc_delay_aggr_rearm(); }
        if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
            function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($urlKey, '', 'delay-amend-land') : wps_ic_cache_integrations::purgeUrlHtml($urlKey, '', ['context' => 'delay-amend-land']);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('delay-amend-landed', (string) $urlKey, '', ['bytes' => strlen((string) $wpc_amb)]);
        }
        return true;
    }
}


if (!function_exists('wpc_lcp_repull_handler')) {

    function wpc_lcp_repull_handler($urlKey, $attempt = 1)
    {
        // .473: Safe Mode stands the DRAIN down too, not just admission — a cron event already
        // in the row would otherwise run. Returns without rescheduling, so nothing accumulates.
        if (function_exists('wpc_bg_lane_allowed') && !wpc_bg_lane_allowed('wpc_lcp_repull')) {
            return;
        }
        if (!defined('WPS_IC_CRITICAL') || empty($urlKey)) {
            return;
        }
        if (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($urlKey)) {
            return; // junk url_key chain dies here, no reschedule
        }
        // Breaker: when the backend is known-down every leg below times out (~70-90s of
        // blocking HTTP per fire) — skip the whole chain, keep ONE bounded backstop.
        if (function_exists('wpc_gen_backoff_active') && wpc_gen_backoff_active()) {
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && (int) $attempt < 8 && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, (int) $attempt + 1])) {
                wpc_pl_sched(time() + 300, 'wpc_lcp_repull', [$urlKey, (int) $attempt + 1]);
            }
            return;
        }
        // Phase-0 load gate: the legs below are all blocking HTTP (status / v2-latest /
        // artifact fetch), each holding a worker. On a hot box they must yield to
        // visitors and retry when pressure clears — never pile onto an overloaded pool.
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && (int) $attempt < 8 && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, (int) $attempt + 1])) {
                wpc_pl_sched(time() + 120, 'wpc_lcp_repull', [$urlKey, (int) $attempt + 1]);
            }
            return;
        }
        // Fleet-wide singleton: post-purge, N url-keys kick in parallel and each
        // repull holds a worker 10-20s (artifact chain) — N of them drown a small
        // FPM pool and every visitor queues (staging.wpcompress.com 60s+ receipt).
        // One repull at a time; the skipped keys re-carry via probes/due-tick.
        if (get_transient('wpc_repull_mutex')) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('repull-mutex-skip', $urlKey, '', []);
            }
            return;
        }
        set_transient('wpc_repull_mutex', 1, 45);
        $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';


        // DB-free landing: if crit is missing but the gen finished, the artifact is on Bunny
        // CDN regardless of any service-DB outage — pull it by uuid, no /status needed.
        if (class_exists('wps_criticalCss')
            && !((int) @filesize($dir . 'critical_desktop.css') > 64 && (int) @filesize($dir . 'critical_mobile.css') > 64)) {
            try {
                $wpc_cc_rp = new wps_criticalCss();
                if (method_exists($wpc_cc_rp, 'pullDerivedArtifacts')) {
                    $wpc_cc_rp->pullDerivedArtifacts($urlKey);
                }
            } catch (\Throwable $e) {
            }
        }


        $wpc_ucss_on = false;
        if (function_exists('get_option') && defined('WPS_IC_SETTINGS')) {
            $wpc_ucss_set = get_option(WPS_IC_SETTINGS);
            $wpc_ucss_on  = is_array($wpc_ucss_set) && !empty($wpc_ucss_set['used-css']) && $wpc_ucss_set['used-css'] == '1';
        }
        if ($wpc_ucss_on && @is_readable($dir . 'used_tpl.txt')) {
            $wpc_cur_tpl = trim((string) @file_get_contents($dir . 'tpl.txt'));
            $wpc_used_tpl_now = trim((string) @file_get_contents($dir . 'used_tpl.txt'));
            if ($wpc_cur_tpl !== '' && $wpc_used_tpl_now !== '' && $wpc_cur_tpl !== $wpc_used_tpl_now) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('used-css-tpl-stale', (string) $urlKey, '', ['used_tpl' => substr($wpc_used_tpl_now, 0, 24), 'cur_tpl' => substr($wpc_cur_tpl, 0, 24)]);
                }
                foreach (['used_tpl.txt', 'used_css_url.txt', 'used_css_mobile_url.txt', 'used_css_desktop_url.txt', 'used_css_sheets_url.txt'] as $wpc_stale_f) {
                    @unlink($dir . $wpc_stale_f);
                }
                $wpc_ucss_on = false;
            }
        }


        $wpc_max_att = (int) apply_filters('wpc_repull_max_attempts', 8);


        $wpc_crit_age = ($wpc_cm = (int) @filemtime($dir . 'critical_desktop.css')) > 0 ? (time() - $wpc_cm) : 999999;
        if (apply_filters('wpc_repull_status_poll', true) && defined('WPS_IC_CRITICAL_API_URL')
            && $wpc_crit_age >= (int) apply_filters('wpc_observation_min_age', 60)


            && (!@is_readable($dir . 'lcp_url.txt') || !@is_readable($dir . 'delay_url.txt') || !@is_readable($dir . 'fonts_url.txt') || !@is_readable($dir . 'used_css_sheets_url.txt') || !@is_readable($dir . 'used_tpl.txt') || !@is_readable($dir . 'used_css_url.txt'))
            && @is_readable($dir . 'uuid.txt')) {
            $wpc_ru = preg_replace('/[^A-Za-z0-9-]/', '', trim((string) @file_get_contents($dir . 'uuid.txt')));
            if ($wpc_ru !== '') {
                $wpc_rs = wp_remote_get(str_replace('/generate', '/status', WPS_IC_CRITICAL_API_URL) . '?uuid=' . urlencode($wpc_ru), ['timeout' => 5]);
                if (!is_wp_error($wpc_rs) && (int) wp_remote_retrieve_response_code($wpc_rs) === 200) {
                    $wpc_rd = json_decode((string) wp_remote_retrieve_body($wpc_rs), true);
                    if (is_array($wpc_rd)) {
                        // observation:ready|pending (service 3.77.1/.2) — status:success means the
                        // CRIT finished, NOT the observation leg (delay/lcp/fonts land later). On
                        // 'pending' keep retrying; NEVER conclude absence from a missing url (that
                        // stranded busyprosai — polled 3s in, saw success + no delay_url, gave up).
                        if (($wpc_rd['observation'] ?? '') === 'pending'
                            && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                            && (int) $attempt < $wpc_max_att && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, (int) $attempt + 1])) {
                            wpc_pl_sched(time() + 30, 'wpc_lcp_repull', [$urlKey, (int) $attempt + 1]);
                        }
                        if (!@is_readable($dir . 'lcp_url.txt')   && !empty($wpc_rd['lcp_url']))   { wpc_crit_meta_write($dir . 'lcp_url.txt', trim((string) $wpc_rd['lcp_url'])); wpc_crit_meta_write($dir . 'lcp_src.txt', 'repull-status'); }
                        if (!@is_readable($dir . 'delay_url.txt') && !empty($wpc_rd['delay_url'])) { wpc_crit_meta_write($dir . 'delay_url.txt', trim((string) $wpc_rd['delay_url'])); }
                        if (!@is_readable($dir . 'fonts_url.txt') && !empty($wpc_rd['fonts_url'])) { wpc_crit_meta_write($dir . 'fonts_url.txt', trim((string) $wpc_rd['fonts_url'])); }
                        if (!@is_readable($dir . 'prescriptions_url.txt') && !empty($wpc_rd['prescriptions_url']) && preg_match('#^https?://#i', trim((string) $wpc_rd['prescriptions_url']))) { wpc_crit_meta_write($dir . 'prescriptions_url.txt', trim((string) $wpc_rd['prescriptions_url'])); }

                        // /status like fonts_url. Capture it + (if present) the content url + tpl_key, so the repull
                        // leg below can land used.css even when the consolidated callback is dropped.
                        // URL metas only ever hold real URLs — the service serializes null as
                        // the literal 'None', which then shadows derivation forever downstream.
                        if (!@is_readable($dir . 'used_css_sheets_url.txt') && !empty($wpc_rd['used_css_sheets_url']) && preg_match('#^https?://#i', trim((string) $wpc_rd['used_css_sheets_url']))) { wpc_crit_meta_write($dir . 'used_css_sheets_url.txt', trim((string) $wpc_rd['used_css_sheets_url'])); }
                        if (!@is_readable($dir . 'used_css_url.txt') && !empty($wpc_rd['used_css_url']) && preg_match('#^https?://#i', trim((string) $wpc_rd['used_css_url']))) { wpc_crit_meta_write($dir . 'used_css_url.txt', trim((string) $wpc_rd['used_css_url'])); }
                        if (!@is_readable($dir . 'used_tpl.txt') && !empty($wpc_rd['tpl_key'])) {
                            wpc_crit_meta_write($dir . 'used_tpl.txt', preg_replace('/[^A-Za-z0-9:._-]/', '', (string) $wpc_rd['tpl_key']));
                            if (function_exists('wpc_r2_on_artifact_land')) { wpc_r2_on_artifact_land(); }
                            if (function_exists('wpc_autopurge_on_land')) { wpc_autopurge_on_land($dir); }
                        }

                        foreach (['mobile', 'desktop'] as $wpc_dv18) {
                            $wpc_k18 = 'used_css_' . $wpc_dv18 . '_url';
                            if (!@is_readable($dir . $wpc_k18 . '.txt') && !empty($wpc_rd[$wpc_k18])) { wpc_crit_meta_write($dir . $wpc_k18 . '.txt', trim((string) $wpc_rd[$wpc_k18])); }
                        }
                    }
                }
            }
        }


        // URL). The /status leg above needs uuid.txt — which a purge wipes with the dir, and whose


        // Prescriptions only hold the /v2/latest gate open when the service has SIGNALLED
        // they exist (presc_want.txt, webhook-written, ≤1h fresh) — otherwise a fleet that
        // never emits prescriptions would poll /v2/latest forever (law 12: absent = prior
        // behavior, so the leg must converge to zero once the standing artifacts land).
        $wpc_pwant356 = !@is_readable($dir . 'prescriptions_url.txt')
            && ($wpc_pwm356 = (int) @filemtime($dir . 'presc_want.txt')) > 0
            && (time() - $wpc_pwm356) < 3600;
        if (apply_filters('wpc_v2_latest', true) && defined('WPS_IC_CRITICAL_API_URL')
            && (!@is_readable($dir . 'lcp_url.txt') || !@is_readable($dir . 'used_tpl.txt')
                || !@is_readable($dir . 'used_css_sheets_url.txt') || !@is_readable($dir . 'fonts_url.txt')
                || $wpc_pwant356)
            && !get_transient('wpc_v2l_' . md5((string) $urlKey))) {
            set_transient('wpc_v2l_' . md5((string) $urlKey), 1, 60);
            $wpc_lurl136 = '';
            if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'getUrlFromKey')) {
                $wpc_lurl136 = (string) (new wps_ic_url_key())->getUrlFromKey((string) $urlKey);
            }
            if ($wpc_lurl136 === '' && function_exists('home_url') && class_exists('wps_ic_url_key')
                && ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/') === ltrim((string) $urlKey, '/')) {
                $wpc_lurl136 = home_url('/');
            }
            $wpc_opt136 = (array) get_option(defined('WPS_IC_OPTIONS') ? WPS_IC_OPTIONS : 'wps_ic');
            if ($wpc_lurl136 !== '' && !empty($wpc_opt136['api_key'])) {
                $wpc_lr136 = wp_remote_get(str_replace('/generate', '/v2/latest', WPS_IC_CRITICAL_API_URL)
                    . '?url=' . urlencode($wpc_lurl136) . '&apikey=' . urlencode((string) $wpc_opt136['api_key']),
                    ['timeout' => 5]);
                $wpc_lc136 = !is_wp_error($wpc_lr136) ? (int) wp_remote_retrieve_response_code($wpc_lr136) : 0;
                if ($wpc_lc136 === 200) {
                    $wpc_ld136 = json_decode((string) wp_remote_retrieve_body($wpc_lr136), true);
                    if (is_array($wpc_ld136) && !empty($wpc_ld136['ready'])
                        && !empty($wpc_ld136['artifacts']) && is_array($wpc_ld136['artifacts'])) {
                        $wpc_a136 = $wpc_ld136['artifacts'];
                        // FRESH-GEN SUPERSEDES STALE WRITE-ONCE delay.json (the flip's gate).
                        // delay.json/lcp.json are write-once, so a fresh gen never overwrites a
                        // stale on-disk copy — busyprosai pinned an 18.9h-old, ceiling-less
                        // delay.json while the pointer advanced, so the flip never fired despite
                        // perfect fetches. When /v2/latest offers a delay/lcp URL that DIFFERS
                        // from on-disk (or on-disk URL was purged away while the content stayed),
                        // drop the stale pair so the loop below re-writes the fresh URL and the
                        // content-fetch re-derives. Only on a real change with a replacement in
                        // hand — never wipes bare. The mtime-vs-land heuristic (delay.json older
                        // than the last crit land) catches the post-purge case where the URL is
                        // gone but the content persists.
                        $wpc_land369 = (int) @file_get_contents($dir . 'land_ts.txt');
                        foreach (['delay' => 'delay_url.txt', 'lcp' => 'lcp_url.txt'] as $wpc_sk369 => $wpc_su369) {
                            if (empty($wpc_a136[$wpc_sk369]) || !is_string($wpc_a136[$wpc_sk369])) { continue; }
                            $wpc_fresh369 = trim((string) $wpc_a136[$wpc_sk369]);
                            if ($wpc_fresh369 === '' || !preg_match('#^https?://#i', $wpc_fresh369)) { continue; }
                            $wpc_cur369  = @is_readable($dir . $wpc_su369) ? trim((string) @file_get_contents($dir . $wpc_su369)) : '';
                            $wpc_cf369   = $wpc_sk369 === 'delay' ? 'delay.json' : 'lcp.json';
                            $wpc_cfmt369 = (int) @filemtime($dir . $wpc_cf369);
                            $wpc_stale369 = ($wpc_cur369 !== '' && $wpc_fresh369 !== $wpc_cur369)              // URL advanced
                                || ($wpc_cur369 === '' && @is_readable($dir . $wpc_cf369)                      // URL purged, content pinned
                                    && $wpc_land369 > 0 && $wpc_cfmt369 > 0 && $wpc_land369 - $wpc_cfmt369 > 60);
                            if ($wpc_stale369) {
                                @unlink($dir . $wpc_su369);
                                @unlink($dir . $wpc_cf369);
                                if ($wpc_sk369 === 'delay') { @unlink($dir . 'fonts_none.txt'); }
                                if ($wpc_sk369 === 'lcp') { @unlink($dir . 'lcp_none.txt'); }
                                if (function_exists('wpc_cache_first_log')) {
                                    wpc_cache_first_log('derived-supersede', (string) $urlKey, '', ['artifact' => $wpc_sk369, 'age' => $wpc_cfmt369 > 0 ? time() - $wpc_cfmt369 : -1]);
                                }
                            }
                        }
                        foreach (['lcp' => 'lcp_url.txt', 'delay' => 'delay_url.txt', 'fonts' => 'fonts_url.txt',
                            'used_css_sheets' => 'used_css_sheets_url.txt',
                            'used_css_mobile' => 'used_css_mobile_url.txt',
                            'used_css_desktop' => 'used_css_desktop_url.txt',
                            'crit_combined' => 'crit_combined_url.txt'] as $wpc_ak136 => $wpc_pf136) {
                            if (!@is_readable($dir . $wpc_pf136) && !empty($wpc_a136[$wpc_ak136]) && is_string($wpc_a136[$wpc_ak136])) {
                                wpc_crit_meta_write($dir . $wpc_pf136, trim((string) $wpc_a136[$wpc_ak136]));
                                if ($wpc_ak136 === 'lcp') { wpc_crit_meta_write($dir . 'lcp_src.txt', 'v2-latest'); }
                            }
                        }


                        // Contract delta 2: prescriptions + used_css (union) ride artifacts{}
                        // directly — THE delivery channel; shelf paths are never derived.
                        foreach (['prescriptions' => 'prescriptions_url.txt', 'used_css' => 'used_css_url.txt'] as $wpc_nk356 => $wpc_np356) {
                            if (!@is_readable($dir . $wpc_np356) && !empty($wpc_a136[$wpc_nk356]) && is_string($wpc_a136[$wpc_nk356])
                                && preg_match('#^https?://#i', trim((string) $wpc_a136[$wpc_nk356]))) {
                                wpc_crit_meta_write($dir . $wpc_np356, trim((string) $wpc_a136[$wpc_nk356]));
                            }
                        }


                        // §6 near_expiry: >13d of 14d retention — regen beats pulling
                        // about-to-expire URLs; fail-open handled inside the helper.
                        if (!empty($wpc_ld136['near_expiry']) && function_exists('wpc_near_expiry_regen')) {
                            wpc_near_expiry_regen((string) $urlKey);
                        }


                        if (!@is_readable($dir . 'used_tpl.txt')) {
                            foreach (['used_css_mobile', 'used_css_desktop', 'used_css_sheets'] as $wpc_uk136) {
                                if (!empty($wpc_a136[$wpc_uk136])
                                    && preg_match('/tpl-([A-Za-z0-9._-]{8,})?\.(?:mobile\.|desktop\.|sheets\.)?(?:css|json)$/i', basename((string) parse_url((string) $wpc_a136[$wpc_uk136], PHP_URL_PATH)), $wpc_tm136)
                                    && !empty($wpc_tm136[1])) {
                                    wpc_crit_meta_write($dir . 'used_tpl.txt', 'tpl:' . preg_replace('/\.(?:mobile|desktop|sheets)$/i', '', (string) $wpc_tm136[1]));
                                    if (function_exists('wpc_r2_on_artifact_land')) { wpc_r2_on_artifact_land(); }
                                    if (function_exists('wpc_autopurge_on_land')) { wpc_autopurge_on_land($dir); }
                                    break;
                                }
                            }
                        }
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('v2-latest-hit', (string) $urlKey, $wpc_lurl136,
                                ['uuid' => isset($wpc_ld136['crit_uuid']) ? substr((string) $wpc_ld136['crit_uuid'], 0, 8) : '']);
                        }
                    } elseif (is_array($wpc_ld136) && isset($wpc_ld136['ready']) && !$wpc_ld136['ready']) {

                        set_transient('wpc_v2l_' . md5((string) $urlKey), 1,
                            min(300, max(15, (int) ($wpc_ld136['retry_after'] ?? 30))));
                    }
                } elseif ($wpc_lc136 === 429) {
                    $wpc_ra136 = (int) wp_remote_retrieve_header($wpc_lr136, 'retry-after');
                    set_transient('wpc_v2l_' . md5((string) $urlKey), 1, min(3600, max(60, $wpc_ra136)));
                }
            }
        }


        if ((!@is_readable($dir . 'lcp_url.txt') || !@is_readable($dir . 'delay_url.txt') || !@is_readable($dir . 'fonts_url.txt'))
            && (int) $attempt < $wpc_max_att && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, (int) $attempt + 1])) {
            wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, (int) $attempt + 1]);
        }


        if (!@is_readable($dir . 'delay.json')) {
            $wpc_du = wpc_crit_artifact_url($dir, 'delay');
            if ($wpc_du !== '') {
                $wpc_dr = wp_remote_get($wpc_du, ['headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : ''], 'timeout' => 5]);
                if (!is_wp_error($wpc_dr) && (int) wp_remote_retrieve_response_code($wpc_dr) === 200) {
                    $wpc_db = wp_remote_retrieve_body($wpc_dr);
                    if (is_string($wpc_db) && $wpc_db !== '' && is_array(json_decode($wpc_db, true))) {
                        wpc_crit_meta_write($dir . 'delay.json', $wpc_db);


                        delete_option('wpc_delay_v3_manifest_off');
                        delete_option('wpc_delay_v3_promoted');
                        wpc_delay_aggr_rearm();
                        if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                            function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($urlKey, '', 'delay-manifest-land') : wps_ic_cache_integrations::purgeUrlHtml($urlKey, '', ['context' => 'delay-manifest-land']);
                        }
                    } elseif ((int) $attempt < $wpc_max_att && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                        && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, (int) $attempt + 1])) {

                        wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, (int) $attempt + 1]);
                    }
                }
            }
        }


        // In-place amendment recovery (Artifact Identity Contract) — extracted so the
        // cron/rail repull AND the cron-free traffic carrier share one implementation.
        wpc_amend_delay_recovery($urlKey);

        // Self-heal the promotion kill-switch (BACKGROUND, not render path): a
        // measured delay.json on disk means the aggressive flip is valid, but
        // manifest_off (set by an old promoted-script ReferenceError) is cleared
        // only on the write-once delay.json WRITE above — so a stale switch froze
        // the flip forever once delay.json existed (busyprosai's 2h stall). Clear
        // it here whenever a measured gen is confirmed present.
        if (get_option('wpc_delay_v3_manifest_off') && @is_readable($dir . 'delay.json') && class_exists('wps_ic_js_delay_v3')) {
            $wpc_djh366 = json_decode((string) @file_get_contents($dir . 'delay.json'), true);
            if (wps_ic_js_delay_v3::wpc_delay_measured_shape($wpc_djh366)) {
                delete_option('wpc_delay_v3_manifest_off');
                if (function_exists('wpc_delay_aggr_rearm')) { wpc_delay_aggr_rearm(); }
                if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                    function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($urlKey, '', 'manifest-off-heal') : wps_ic_cache_integrations::purgeUrlHtml($urlKey, '', ['context' => 'manifest-off-heal']);
                }
            }
        }

        if (!@is_readable($dir . 'prescriptions.json') && @is_readable($dir . 'prescriptions_url.txt')
            && function_exists('wpc_presc_on_land')) {
            wpc_presc_on_land($urlKey);
            if (!@is_readable($dir . 'prescriptions.json')
                && (int) $attempt < $wpc_max_att && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, (int) $attempt + 1])) {
                wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, (int) $attempt + 1]);
            }
        }
        // Poll-#2 due-tick rides this handler — which the loopback KICK lane runs on
        // real traffic, so the post-verdict re-pull lands even where cron never fires.
        if (function_exists('wpc_presc_due_tick')) {
            wpc_presc_due_tick($urlKey);
        }
        // §10 TTFB actuation rides the same background lane: once delay.json (with its
        // ceiling{}) is on disk, a measured recoverable origin-TTFB arms the static serve.
        // Fully gated + hourly-throttled + self-test-rolled-back inside — safe to call here.
        if (@is_readable($dir . 'delay.json') && function_exists('wpc_ttfb_maybe_arm_static_serve')) {
            wpc_ttfb_maybe_arm_static_serve($urlKey);
        }


        // fonts_none.txt = fail-open sentinel: "no fonts to subset" is provable,
        // not inferred (law 12). Without it a 404/empty fonts.json re-fetched on
        // every kick forever (busyprosai: atf_faces:0 cross-origin Google Fonts →
        // 3x/2x/2x per uuid). Service v3.75.4 now emits an empty array; this belt
        // covers pre-.4 gens + any genuine 404, and converges the repull loop.
        // v7.10.485 — fonts_none.txt MUST be revisitable. It is written on "200 + empty", but the
        // service's fast-emit writes fonts.json ASYNCHRONOUSLY, so an early read sees [] before the
        // subsets exist. That is "not yet", not "provably none" — and the sentinel was permanent.
        // zinsenvergleich receipt: fonts_none.txt AND fonts_url.txt both present, four subsets
        // uploaded and fetchable, font-subsets.css ABSENT, every gate withheld for want of a pair.
        // A NEWER fonts_url.txt is proof a later gen supplied fonts after we concluded none, so the
        // conclusion is stale by construction. Clearing it only re-opens the fetch; the fail-open
        // sentinel is rewritten if the artifact really is empty.
        $wpc_fn485 = (int) @filemtime($dir . 'fonts_none.txt');
        $wpc_fu485 = (int) @filemtime($dir . 'fonts_url.txt');
        // v7.10.487 — KEY THE REVISIT ON THE GEN, NOT ON fonts_url.txt. .485 required a newer
        // fonts_url.txt, but a land can DELETE that file while leaving the sentinel: zinsenvergleich
        // ended with fonts_none.txt present and fonts_url.txt absent, and the fetch gate below
        // REQUIRES fonts_url.txt — so both the fetch and the revisit were dead permanently. The
        // sentinel is a statement about ONE gen; any newer gen makes it non-authoritative whether or
        // not the url file survived. land_ts/crit mtime is the gen clock and is always present.
        $wpc_gen487 = max((int) @filemtime($dir . 'land_ts.txt'), (int) @filemtime($dir . 'critical_desktop.css'));
        if ($wpc_fn485 > 0 && apply_filters('wpc_fonts_none_revisit', true)
            && ($wpc_fu485 > $wpc_fn485 || $wpc_gen487 > $wpc_fn485)) {
            @unlink($dir . 'fonts_none.txt');
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('fonts-none-stale', (string) $urlKey, '', [
                    'none_age' => max(0, time() - $wpc_fn485),
                    'url_age'  => $wpc_fu485 > 0 ? max(0, time() - $wpc_fu485) : -1,
                    'why'      => ($wpc_gen487 > $wpc_fn485) ? 'newer-gen' : 'newer-url',
                ]);
            }
        }
        // .487: if a land deleted fonts_url.txt we can still derive it from the gen uuid — the
        // same two-owner derivation .486 uses. Without this the gate is unreachable on exactly the
        // sites whose land dropped the pointer.
        if (!@is_readable($dir . 'fonts_url.txt') && @is_readable($dir . 'uuid.txt')
            && apply_filters('wpc_fonts_url_derive', true)) {
            $wpc_du487 = preg_replace('/[^0-9a-fA-F-]/', '', trim((string) @file_get_contents($dir . 'uuid.txt')));
            $wpc_dl487 = @is_readable($dir . 'delay_url.txt') ? trim((string) @file_get_contents($dir . 'delay_url.txt')) : '';
            if (strlen($wpc_du487) >= 32 && $wpc_dl487 !== ''
                && preg_match('#^https?://#i', $wpc_dl487)) {
                $wpc_dv487 = (string) preg_replace(
                    '#/[0-9a-f]{4}/[0-9a-fA-F-]{32,40}\.delay\.json#i',
                    '/' . substr($wpc_du487, 0, 4) . '/' . $wpc_du487 . '.fonts.json',
                    $wpc_dl487
                );
                if ($wpc_dv487 !== '' && $wpc_dv487 !== $wpc_dl487 && stripos($wpc_dv487, '.fonts.json') !== false) {
                    wpc_crit_meta_write($dir . 'fonts_url.txt', $wpc_dv487);
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('fonts-url-derived', (string) $urlKey, '', ['gen' => substr($wpc_du487, 0, 8)]);
                    }
                }
            }
        }
        if (!@is_readable($dir . 'font-subsets.css') && !@is_readable($dir . 'fonts_none.txt')
            && @is_readable($dir . 'fonts_url.txt')
            && function_exists('wpc_consume_fonts_artifact') && apply_filters('wpc_fonts_subset_repull', true)) {
            $wpc_fu = trim((string) @file_get_contents($dir . 'fonts_url.txt'));
            if ($wpc_fu !== '') {


                $wpc_fcm2    = (int) @filemtime($dir . 'critical_desktop.css');
                $wpc_f_young = $wpc_fcm2 > 0 && (time() - $wpc_fcm2) < (int) apply_filters('wpc_fonts_min_age', 60);
                $wpc_fr      = null;
                if (!$wpc_f_young) {
                    $wpc_fr  = wp_remote_get($wpc_fu, ['headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : ''], 'timeout' => 6]);
                    $wpc_frc = !is_wp_error($wpc_fr) ? (int) wp_remote_retrieve_response_code($wpc_fr) : 0;
                    if ($wpc_frc === 200) {
                        $wpc_fj = json_decode((string) wp_remote_retrieve_body($wpc_fr), true);
                        $wpc_fa = (is_array($wpc_fj) && !empty($wpc_fj['fonts']) && is_array($wpc_fj['fonts'])) ? $wpc_fj['fonts'] : (is_array($wpc_fj) ? $wpc_fj : []);
                        // v7.10.486 — TWO OWNERS, ONE ARTIFACT. fonts.json has two possible homes:
                        // the crit-time fast-emit writes it under the GEN uuid, while /status
                        // advertises fonts_url under the OBS uuid. On a fresh observation they are
                        // the same; on a TEMPLATE-CACHE HIT they diverge and the fast-emit's copy —
                        // the icon-complete one — is never advertised. zinsenvergleich: gen 45891209
                        // carried 4 icon entries / 16,884b while the advertised a3059372 copy was a
                        // genuine computed-empty. We followed a correct instruction to the wrong file.
                        // So: when the advertised url names a DIFFERENT uuid than the gen on disk,
                        // try the gen-uuid sibling and prefer whichever actually has entries. Costs
                        // one bounded GET only when the uuids disagree AND the advertised copy is
                        // empty, and it converges to a no-op once the resolver points at the gen.
                        if (empty($wpc_fa) && apply_filters('wpc_fonts_gen_uuid_fallback', true)) {
                            $wpc_gu486 = @is_readable($dir . 'uuid.txt')
                                ? trim((string) @file_get_contents($dir . 'uuid.txt')) : '';
                            $wpc_gu486 = preg_replace('/[^0-9a-fA-F-]/', '', $wpc_gu486);
                            if (strlen($wpc_gu486) >= 32 && stripos($wpc_fu, $wpc_gu486) === false) {
                                $wpc_alt486 = (string) preg_replace(
                                    '#/[0-9a-f]{4}/[0-9a-fA-F-]{32,40}\.fonts\.json#i',
                                    '/' . substr($wpc_gu486, 0, 4) . '/' . $wpc_gu486 . '.fonts.json',
                                    $wpc_fu
                                );
                                if ($wpc_alt486 !== '' && $wpc_alt486 !== $wpc_fu) {
                                    $wpc_ar486 = wp_remote_get($wpc_alt486, ['headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : ''], 'timeout' => 6]);
                                    if (!is_wp_error($wpc_ar486) && (int) wp_remote_retrieve_response_code($wpc_ar486) === 200) {
                                        $wpc_aj486 = json_decode((string) wp_remote_retrieve_body($wpc_ar486), true);
                                        $wpc_aa486 = (is_array($wpc_aj486) && !empty($wpc_aj486['fonts']) && is_array($wpc_aj486['fonts']))
                                            ? $wpc_aj486['fonts'] : (is_array($wpc_aj486) ? $wpc_aj486 : []);
                                        if (!empty($wpc_aa486)) {
                                            $wpc_fa = $wpc_aa486;
                                            if (function_exists('wpc_cache_first_log')) {
                                                wpc_cache_first_log('fonts-gen-uuid-rescue', (string) $urlKey, '', [
                                                    'n'   => count($wpc_aa486),
                                                    'gen' => substr($wpc_gu486, 0, 8),
                                                ]);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                        if (!empty($wpc_fa)) {
                            wpc_consume_fonts_artifact($wpc_fa, $urlKey);
                            if (@is_readable($dir . 'font-subsets.css') && class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                                function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($urlKey, '', 'fonts-subset-land') : wps_ic_cache_integrations::purgeUrlHtml($urlKey, '', ['context' => 'fonts-subset-land']);
                            }
                        } elseif (!empty($wpc_fj['fonts_computed']) || array_key_exists('fonts_computed', (array) $wpc_fj)) {
                            // .485: only an EXPLICIT computed-and-empty statement proves absence.
                            wpc_crit_meta_write($dir . 'fonts_none.txt', '1');
                        } else {
                            // Empty with no statement = ambiguous (async emit may not have written
                            // yet). Write the sentinel so we do not hammer, but a later fonts_url.txt
                            // re-opens it via the revisit above.
                            wpc_crit_meta_write($dir . 'fonts_none.txt', '1');
                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('fonts-none-ambiguous', (string) $urlKey, '',
                                    ['why' => 'empty fonts.json with no fonts_computed statement']);
                            }
                        }
                    } elseif ($wpc_frc === 404 || $wpc_frc === 410) {
                        wpc_crit_meta_write($dir . 'fonts_none.txt', '1'); // definitive absence = fail-open, stop re-fetching
                    }
                }


                if (!@is_readable($dir . 'font-subsets.css')) {
                    if (!$wpc_f_young && function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('fonts-repull-miss', (string) $urlKey, '', [
                            'code'    => is_wp_error($wpc_fr) ? 0 : (int) wp_remote_retrieve_response_code($wpc_fr),
                            'attempt' => (int) $attempt,
                        ]);
                    }
                    if ((int) $attempt < $wpc_max_att && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                        && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, (int) $attempt + 1])) {
                        wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, (int) $attempt + 1]);
                    }
                }
            }
        }


        // .466: neither crit-dir pointer is required any more. The template-stale cleanup
        // deletes both while leaving the bundle in place, which made this branch — the ONLY
        // path that refreshes a used-css bundle or rebuilds its siblings — unreachable for
        // good. Fall back to the tpl_key on disk and to the URL remembered beside the bundle.
        if ($wpc_ucss_on && function_exists('wpc_used_css_fetch') && function_exists('wpc_used_css_path')) {
            $wpc_uu = @is_readable($dir . 'used_css_url.txt')
                ? trim((string) @file_get_contents($dir . 'used_css_url.txt')) : '';
            if ($wpc_uu !== '' && !preg_match('#^https?://#i', $wpc_uu)) { $wpc_uu = ''; } // 'None'-class meta
            $wpc_ut = @is_readable($dir . 'used_tpl.txt')
                ? trim((string) @file_get_contents($dir . 'used_tpl.txt')) : '';
            if ($wpc_ut === '') {
                $wpc_ut = trim((string) @file_get_contents($dir . 'tpl.txt'));
            }
            if ($wpc_uu === '' && $wpc_ut !== '' && function_exists('wpc_used_css_url_recall')) {
                $wpc_uu = wpc_used_css_url_recall($wpc_ut);
                if ($wpc_uu !== '' && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('ucss-url-recalled', (string) $urlKey, substr($wpc_uu, 0, 60),
                        ['tpl' => substr($wpc_ut, 0, 24)]);
                }
            }
            $wpc_up = wpc_used_css_path($wpc_ut);
            // store-hit is cheap inside fetch — and it's where split siblings arm
            if ($wpc_uu !== '' && $wpc_up !== '') {
                $wpc_uwhy19 = '';
                if (!wpc_used_css_fetch($wpc_uu, $wpc_ut, '', $wpc_uwhy19)) {
                    // A 404 that survived the first backoff step is an EXPIRED artifact, not a
                    // slow one — ask for a regen instead of only sleeping longer (v3.198.68).
                    if (strpos($wpc_uwhy19, 'http:404') === 0
                        && (int) get_transient('wpc_ucss404_' . md5((string) $wpc_uu) . '_n') >= 2
                        && function_exists('wpc_used_css_expired_dispatch19')) {
                        wpc_used_css_expired_dispatch19($urlKey, $wpc_uwhy19);
                    }
                    if ((int) $attempt < $wpc_max_att
                        && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                        && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, (int) $attempt + 1])) {
                        wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, (int) $attempt + 1]);
                    }
                }
            }
        }


        if ($wpc_ucss_on && function_exists('wpc_used_css_fetch') && @is_readable($dir . 'used_tpl.txt')) {
            $wpc_ut18 = trim((string) @file_get_contents($dir . 'used_tpl.txt'));
            foreach (['mobile', 'desktop'] as $wpc_dv18b) {
                $wpc_uf18 = $dir . 'used_css_' . $wpc_dv18b . '_url.txt';
                if (!@is_readable($wpc_uf18)) { continue; }
                $wpc_du18 = trim((string) @file_get_contents($wpc_uf18));
                if ($wpc_du18 !== '' && !preg_match('#^https?://#i', $wpc_du18)) { $wpc_du18 = ''; } // 'None'-class meta
                $wpc_dp18 = $wpc_ut18 !== '' ? wpc_used_css_path($wpc_ut18, $wpc_dv18b) : '';
                if ($wpc_du18 !== '' && $wpc_dp18 !== '') {
                    wpc_used_css_fetch($wpc_du18, $wpc_ut18, $wpc_dv18b);
                }
            }
        }


        if (@is_readable($dir . 'crit_combined_url.txt')) {
            $wpc_cbu154 = trim((string) @file_get_contents($dir . 'crit_combined_url.txt'));
            $wpc_cbs154 = trim((string) @file_get_contents($dir . 'crit_combined_src.txt'));
            if ($wpc_cbu154 !== '' && preg_match('#^https://#i', $wpc_cbu154)
                && ($wpc_cbs154 !== $wpc_cbu154 || !@is_readable($dir . 'critical_combined.css'))) {
                $wpc_cbr154 = wp_remote_get($wpc_cbu154, ['timeout' => 15, 'sslverify' => true]);
                $wpc_cbb154 = (!is_wp_error($wpc_cbr154) && (int) wp_remote_retrieve_response_code($wpc_cbr154) === 200)
                    ? (string) wp_remote_retrieve_body($wpc_cbr154) : '';
                if (strlen($wpc_cbb154) > 1024 && stripos($wpc_cbb154, '@media') !== false
                    && stripos($wpc_cbb154, '<script') === false && stripos($wpc_cbb154, '</style') === false) {
                    @file_put_contents($dir . 'critical_combined.css', $wpc_cbb154, LOCK_EX);
                    wpc_crit_meta_write($dir . 'crit_combined_src.txt', $wpc_cbu154);
                } else {
                    wpc_crit_meta_write($dir . 'crit_combined_src.txt', $wpc_cbu154);
                }
            }
        }


        if ($wpc_ucss_on && function_exists('wpc_used_css_store_sheets') && function_exists('wpc_used_css_sheets_path')
            && @is_readable($dir . 'used_css_sheets_url.txt') && @is_readable($dir . 'used_tpl.txt')) {
            $wpc_su2 = trim((string) @file_get_contents($dir . 'used_css_sheets_url.txt'));
            if ($wpc_su2 !== '' && !preg_match('#^https?://#i', $wpc_su2)) { $wpc_su2 = ''; } // 'None'-class meta
            $wpc_st2 = trim((string) @file_get_contents($dir . 'used_tpl.txt'));
            $wpc_sp2 = wpc_used_css_sheets_path($wpc_st2);
            if ($wpc_su2 !== '' && $wpc_sp2 !== '' && !@is_readable($wpc_sp2)) {
                $wpc_shr = wp_remote_get($wpc_su2, ['headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : ''], 'timeout' => 5]);
                if (!is_wp_error($wpc_shr) && (int) wp_remote_retrieve_response_code($wpc_shr) === 200) {
                    $wpc_shj = json_decode((string) wp_remote_retrieve_body($wpc_shr), true);
                    $wpc_sha = (is_array($wpc_shj) && isset($wpc_shj['sheets']) && is_array($wpc_shj['sheets'])) ? $wpc_shj['sheets'] : (is_array($wpc_shj) ? $wpc_shj : []);
                    if (!empty($wpc_sha)) { wpc_used_css_store_sheets($wpc_st2, $wpc_sha); }
                }
            }
        }
        if (@is_readable($dir . 'lcp.json')) {
            return;
        }
        // lcp_none = fail-open back-off (mirrors fonts_none). Since .371 the lcp URL
        // is DERIVED from uuid when unadvertised — but many sites have no distinct
        // lcp.json (no img-hero preload), so an unbounded derive→404 would re-hit
        // origin on every repull. A definitive 404/410 writes this sentinel; the
        // read below backs off for a TTL (self-heals if the service emits it later)
        // and it's cleared on a fresh measured gen (delay-supersede / amend-land)
        // for prompt re-check. NOTE: NO equivalent for delay.json — a delay 404 is
        // "gen not ready yet" (must keep retrying) and amendments land at the SAME
        // uuid, so a delay sentinel would wrongly suppress recovery.
        if (($wpc_lnm = (int) @filemtime($dir . 'lcp_none.txt')) > 0
            && (time() - $wpc_lnm) < (int) apply_filters('wpc_lcp_none_ttl', 3600)) {
            return;
        }
        $lcpUrl = wpc_crit_artifact_url($dir, 'lcp');
        if ($lcpUrl === '') {
            return;
        }
        $ua    = defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : '';
        $resp  = wp_remote_get($lcpUrl, ['headers' => ['user-agent' => $ua], 'timeout' => 5]);
        $wpc_lcode = !is_wp_error($resp) ? (int) wp_remote_retrieve_response_code($resp) : 0;
        if ($wpc_lcode === 200) {
            $body = wp_remote_retrieve_body($resp);
            if (is_string($body) && $body !== '' && json_decode($body) !== null) {
                if (!is_dir($dir)) {
                    wp_mkdir_p($dir);
                }
                wpc_lcp_write_preserve781($dir, $body);
                @unlink($dir . 'lcp_none.txt');
                if (class_exists('wps_ic_cache_integrations')) {
                    if (wpc_cache_first_enabled() && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                        function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($urlKey, '', 'lcp-land') : wps_ic_cache_integrations::purgeUrlHtml($urlKey, '', ['context' => 'lcp-land']);
                    } else {
                        wps_ic_cache_integrations::purgeAll($urlKey, false, true, false);
                    }
                }
                return;
            }
        }
        if ($wpc_lcode === 404 || $wpc_lcode === 410) {
            wpc_crit_meta_write($dir . 'lcp_none.txt', '1'); // provable absence → back off (TTL); no reschedule
            return;
        }
        // Transient (timeout / 5xx / invalid body) → bounded retry (~3 min), then give up silently.
        if ((int) $attempt < $wpc_max_att && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, (int) $attempt + 1])) {
            wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, (int) $attempt + 1]);
        }
    }
    add_action('wpc_lcp_repull', 'wpc_lcp_repull_handler', 10, 2);
}


if (!function_exists('wpc_amend_traffic_enqueue')) {
    /** CRON-FREE CONVERGENCE. The recovery rides wpc_lcp_repull, only ENQUEUED on
     *  gen/purge/land — so a steady-state stuck page (crit present, delay.json stale)
     *  with DISABLE_WP_CRON / a dead crontab / no active regen never self-heals (the
     *  rail is flag-gated OFF by default, so an enqueue degrades to WP-Cron). This
     *  makes a stuck front-end page run its OWN recovery on TRAFFIC, INLINE and
     *  POST-RESPONSE (fastcgi_finish_request first → visitor waits 0ms) — the only
     *  carrier that needs neither cron, rail, nor loopback. Bounded: only WPC pages
     *  (crit dir present) NOT yet measured; the check is filesystem-throttled
     *  once/5min per key (amend_tick.txt — no transient bloat at 100k scale) and the
     *  recovery self-throttles via its own 5min transient, so the post-response
     *  worker-hold is one ~29KB fetch, rare (contention doctrine: worker-accounted).
     *  A cron/rail enqueue rides along as a backstop for the fuller repull work. */
    function wpc_amend_traffic_enqueue()
    {
        try {
            if (is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax())
                || (defined('DOING_CRON') && DOING_CRON) || (defined('REST_REQUEST') && REST_REQUEST)
                || (defined('WP_CLI') && WP_CLI) || !did_action('template_redirect')
                || !empty($_GET['criticalCombine']) || !defined('WPS_IC_CRITICAL')
                || !function_exists('wpc_pl_sched') || !class_exists('wps_ic_url_key')
                || !class_exists('wps_ic_js_delay_v3') || !apply_filters('wpc_amend_on_traffic', true)
                || (function_exists('wpc_under_pressure') && wpc_under_pressure())
                || empty($_SERVER['HTTP_HOST']) || empty($_SERVER['REQUEST_URI'])) {
                return;
            }
            $cur = ((function_exists('is_ssl') && is_ssl()) ? 'https://' : 'http://')
                . $_SERVER['HTTP_HOST'] . strtok((string) $_SERVER['REQUEST_URI'], '?');
            $key = ltrim((string) (new wps_ic_url_key())->setup($cur), '/');
            if ($key === '' || (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($key))) {
                return;
            }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/';
            if (!@is_readable($dir . 'critical_desktop.css')) {
                return; // not a WPC-managed/crit page — nothing to recover
            }
            $tick = $dir . 'amend_tick.txt';
            if (($m = (int) @filemtime($tick)) > 0 && (time() - $m) < (int) apply_filters('wpc_amend_tick_ttl', 300)) {
                return; // filesystem throttle — checked recently, no DB writes
            }
            @touch($tick);
            $dj = @is_readable($dir . 'delay.json') ? json_decode((string) @file_get_contents($dir . 'delay.json'), true) : null;
            if (is_array($dj) && wps_ic_js_delay_v3::wpc_delay_measured_shape($dj)
                && !(function_exists('wpc_lcp_taint_pending776') && wpc_lcp_taint_pending776($dir))) {
                return; // measured AND no lcp taint pending — converged, nothing to do
            }
            // Stuck → run the recovery INLINE, POST-RESPONSE. This is the only carrier
            // that works with DISABLE_WP_CRON + a dead crontab (the rail is flag-gated
            // OFF by default, so wpc_pl_sched degrades to WP-Cron there). Flush the
            // response FIRST so the visitor waits 0ms; the recovery is one ~29KB fetch
            // (+lcp), self-throttled ≤once/5min per key and mutex-bounded, so the post-
            // response worker-hold is tiny and rare (contention doctrine: worker-
            // accounted). The cron/rail enqueue rides along as a backstop for the
            // FULLER repull work (status / v2-latest / used-css), no-op-cheap when dead.
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
            if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
            wpc_amend_delay_recovery($key);
            if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_lcp_repull', [$key, 1])) {
                wpc_pl_sched(time(), 'wpc_lcp_repull', [$key, 1]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('shutdown', 'wpc_amend_traffic_enqueue', PHP_INT_MAX);
}


if (!function_exists('wpc_used_css_key_valid')) {
    /** Exactly the identity the plugin itself mints in wpc_compute_tpl_key(). */
    function wpc_used_css_key_valid($tplKey)
    {
        return is_string($tplKey) && (bool) preg_match('/^tpl:[0-9a-f]{16}$/', $tplKey);
    }
}
if (!function_exists('wpc_used_css_zoneify')) {
    /** Origin-host image url()s inside a stored used.css bypass every HTML rewriter —
     *  point them at the zone so the edge serves sized/next-gen bytes. */
    function wpc_used_css_zoneify($css)
    {
        try {
            if (!is_string($css) || $css === '' || stripos($css, 'url(') === false
                || !apply_filters('wpc_used_css_zoneify', true)) {
                return $css;
            }
            if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
                return $css;
            }
            $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : [];
            $cn = defined('WPS_IC_CF_CNAME') ? get_option(WPS_IC_CF_CNAME) : '';
            $ok = (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok());
            $zone = (!empty($cf['settings']['cdn']) && !empty($cn) && $ok) ? $cn : get_option('ic_custom_cname');
            if (empty($zone)) {
                $zone = get_option('ic_cdn_zone_name');
            }
            $origin = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
            if (empty($zone) || !is_string($zone) || $origin === '' || strcasecmp((string) $zone, $origin) === 0) {
                return $css;
            }
            $zone = trim((string) $zone, '/');
            $bare = preg_replace('/^www\./i', '', $origin);
            $out = preg_replace(
                '#url\((["\']?)https?://(?:www\.)?' . preg_quote($bare, '#') . '/wp-content/([^)"\']+\.(?:png|jpe?g|gif|webp|avif))((?:\?[^)"\']*)?)\1\)#i',
                'url($1https://' . $zone . '/wp-content/$2$3$1)',
                $css
            );
            return is_string($out) ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }
}
if (!function_exists('wpc_used_css_path')) {

    function wpc_used_css_path($tplKey, $device = '')
    {
        if (!defined('WPS_IC_CRITICAL') || !wpc_used_css_key_valid($tplKey)) {
            return '';
        }
        $sfx = ($device === 'mobile' || $device === 'desktop') ? '.' . $device : '';
        return rtrim(WPS_IC_CRITICAL, '/') . '/used-css/' . str_replace(':', '-', $tplKey) . $sfx . '.css';
    }


    function wpc_used_css_sheets_path($tplKey)
    {
        if (!defined('WPS_IC_CRITICAL') || !wpc_used_css_key_valid($tplKey)) {
            return '';
        }
        return rtrim(WPS_IC_CRITICAL, '/') . '/used-css/' . str_replace(':', '-', $tplKey) . '.sheets.json';
    }
    function wpc_used_css_store_sheets($tplKey, $sheets)
    {
        try {
            $p = wpc_used_css_sheets_path($tplKey);
            if ($p === '' || !is_array($sheets) || empty($sheets)) {
                return false;
            }
            if (!is_dir(dirname($p))) { @wp_mkdir_p(dirname($p)); }
            $clean = [];


            foreach (array_slice($sheets, 0, (int) apply_filters('wpc_used_css_sheets_cap', 200)) as $sh) {
                if (is_array($sh) && !empty($sh['url']) && is_string($sh['url'])) {
                    $clean[] = ['url' => (string) $sh['url'], 'in' => (int) ($sh['in'] ?? 0), 'out' => (int) ($sh['out'] ?? 0), 'skip' => (isset($sh['skip']) && $sh['skip'] !== '' && $sh['skip'] !== false) ? (string) $sh['skip'] : ''];
                }
            }
            if (empty($clean)) { return false; }
            wpc_crit_meta_write($p, wp_json_encode($clean));


            if (function_exists('wpc_r2_on_artifact_land')) { wpc_r2_on_artifact_land(); }
            return true;
        } catch (\Throwable $e) { return false; }
    }
    function wpc_used_css_load_sheets($tplKey)
    {
        $p = function_exists('wpc_used_css_sheets_path') ? wpc_used_css_sheets_path($tplKey) : '';
        if ($p === '' || !@is_readable($p)) { return []; }
        $j = json_decode((string) @file_get_contents($p), true);
        return is_array($j) ? $j : [];
    }
    /**
     * v7.10.464 — identity of the artifact revision a DERIVED sibling was built from.
     * The used-css path is keyed on tpl_key alone, which does not move when the template
     * is unchanged, so a new artifact with the same tpl_key produced no new cache key, no
     * rebuild, and the old .atf.css served indefinitely (busy: service pruned to 0 orphaned
     * keyframes, the wire kept 561). Same class as the .429 remote_range freeze: the map
     * updated, the file it feeds was frozen.
     * The union's CONTENT is the faithful basis — union and split come from the same gen —
     * so the sibling carries a stamp of it and rebuilds when it moves. Stamp-and-compare
     * rather than a new filename: nothing orphans on disk and an unchanged bundle is stable.
     */
    /**
     * v7.10.466 — the artifact URL, persisted BESIDE the bundle it built.
     * The crit-dir pointers (used_css_url.txt / used_tpl.txt) are deleted by the
     * template-stale cleanup, which does NOT delete the derived bundle — so the files
     * outlive the pointer needed to refresh them and the refresh path becomes permanently
     * unreachable (busy: siblings correctly read STALE, nothing could ever act on it, and
     * uuid.txt was gone too so /status recovery could not re-land the pointer either).
     * Keyed by tpl_key in the used-css dir, so a crit-dir wipe cannot orphan it.
     */
    /**
     * v7.10.470 — parse a service tri-state WITHOUT ever testing truthiness.
     * The service pinned the JS trap ('0' is truthy there); PHP has the mirror image —
     * the string "false" is TRUTHY here, so !empty($v) on an echoed boolean reads a
     * decline as an accept. Anything unrecognised is UNKNOWN (-1), never a silent 1:
     * a defaulting field is the bug this whole contract exists to remove.
     * Returns 1 = true, 0 = false, -1 = unknown/absent.
     */
    function wpc_tri_bool($v)
    {
        if ($v === null) { return -1; }
        if (is_bool($v)) { return $v ? 1 : 0; }
        if (is_int($v) || is_float($v)) { return ((int) $v === 0) ? 0 : 1; }
        if (!is_string($v)) { return -1; }
        $s = strtolower(trim($v));
        if ($s === '') { return -1; }
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) { return 1; }
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) { return 0; }
        return -1;
    }
    /**
     * Record the service's used-css echo (v3.109.0: used_css_requested + used_css_known).
     * Without this the two failure modes the service just made distinguishable — "asked and
     * got nothing" vs "never asked" — arrive and vanish. One non-autoload option, latest wins.
     */
    function wpc_used_css_echo_note($src, $arr)
    {
        if (!is_array($arr)) { return false; }
        // The echo arrives as used_css_requested (the ask) on the callback and as used_css (the
        // durable column) on /status — accept either rather than guess which branch we are on.
        $raw = null;
        foreach (['used_css_requested', 'used_css'] as $wpc_ek471) {
            if (array_key_exists($wpc_ek471, $arr)) { $raw = $arr[$wpc_ek471]; break; }
        }
        if ($raw === null) { return false; } // pre-v3.109 payload — nothing echoed at all
        $req = wpc_tri_bool($raw);
        // INVERTED BY DESIGN: used_css_known is a MARKER meaning "we cannot speak for this row".
        // Its ABSENCE means the value IS known — "a known zero carries no such marker" (service
        // v3.109.0). Reading absence as unknown, which .470 did, reports every genuine decline as
        // unknown and throws away the whole point of the field.
        $kn = array_key_exists('used_css_known', $arr)
            ? (wpc_tri_bool($arr['used_css_known']) === 0 ? 0 : 1)
            : 1;
        update_option('wpc_used_css_echo', [
            't'   => time(),
            'req' => $req,
            'kn'  => $kn,
            'src' => substr((string) $src, 0, 16),
            'ptr' => (!empty($arr['used_css_url']) && is_string($arr['used_css_url'])) ? 1 : 0,
        ], false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('ucss-echo', (string) $src, '', ['req' => $req, 'known' => $kn,
                'ptr' => (!empty($arr['used_css_url']) ? 1 : 0)]);
        }
        return true;
    }
    function wpc_used_css_url_path($tplKey, $device = '')
    {
        // v7.10.475 — DEVICE-SCOPED, mirroring wpc_used_css_path(). .466 keyed the pointer on
        // tplKey alone while the bundles are per-device, so union -> mobile -> desktop each
        // remembered in turn and the LAST one won: zinsenvergleich stored the desktop URL as the
        // union's pointer. A refresh would then have fetched the desktop bundle into the union
        // slot, and .469's demand gate trusts exactly this pointer. Union keeps the original
        // filename, so pointers written by .466 stay valid for the union.
        $p = wpc_used_css_path($tplKey, $device);
        return $p === '' ? '' : (string) preg_replace('/\.css$/', '.url.txt', $p);
    }
    /**
     * v7.10.471 — a pointer EARNS trust by working; presence alone is not proof.
     * Service /status advertises used_css_url unconditionally under wantsObservation (their
     * deliberate v3.33.13 fail-open), so a used_css:0 gen ships a locator that 404s and always
     * will. Remembering that as authoritative would let .469's demand gate see "a pointer
     * exists, we can refresh ourselves", suppress the demand, and RE-CREATE the fossil deadlock
     * with a pointer that can never fetch — strictly worse than the bug it replaced.
     * So the file is two lines: the candidate URL, and 'ok' once a fetch through it succeeded.
     * Only a VERIFIED pointer may suppress the demand.
     */
    function wpc_used_css_url_remember($tplKey, $url, $verified = false, $device = '')
    {
        $p = wpc_used_css_url_path($tplKey, $device);
        if ($p === '' || !is_string($url) || !preg_match('#^https?://#i', $url) || strlen($url) > 2000) {
            return false;
        }
        $url = trim($url);
        $cur = @is_readable($p) ? (string) @file_get_contents($p) : '';
        $curU = trim((string) strtok($cur, "\n"));
        $curOk = (strpos($cur, "\nok") !== false);
        // Never DOWNGRADE a verified pointer for the same URL to unverified.
        if ($curU === $url && ($curOk || !$verified)) { return true; }
        if (!is_dir(dirname($p))) { @wp_mkdir_p(dirname($p)); }
        return (wpc_crit_meta_write($p, $url . ($verified ? "\nok" : '')) !== false);
    }
    function wpc_used_css_url_recall($tplKey, $device = '')
    {
        $p = wpc_used_css_url_path($tplKey, $device);
        if ($p === '' || !@is_readable($p)) { return ''; }
        $u = trim((string) strtok((string) @file_get_contents($p), "\n"));
        return preg_match('#^https?://#i', $u) ? $u : '';
    }
    function wpc_used_css_url_verified($tplKey, $device = '')
    {
        $p = wpc_used_css_url_path($tplKey, $device);
        if ($p === '' || !@is_readable($p)) { return false; }
        $c = (string) @file_get_contents($p);
        return (preg_match('#^https?://#i', trim((string) strtok($c, "\n"))) && strpos($c, "\nok") !== false);
    }
    /**
     * v7.10.828 — THE PIN IS A DECLARATION: a used-css bundle serving a page with builder
     * conceal markers must DECLARE the dynamic-state safelist (service v3.198.60 stamps
     * `wpc-safelist:v1` into union/atf/rest heads). An unstamped bundle on such a page is the
     * pre-safelist artifact class that kept .et-waypoint{opacity:0} while pruning every
     * .et-animated reveal — consumption refuses, full sheets serve (correct, slightly
     * slower), and the next gen lands stamped.
     */
    function wpc_used_css_stamped828($path)
    {
        $wpc_h828 = (string) @file_get_contents((string) $path, false, null, 0, 240);
        return $wpc_h828 !== '' && strpos($wpc_h828, 'wpc-safelist:') !== false;
    }
    function wpc_used_css_basis($unionPath)
    {
        if (!is_string($unionPath) || $unionPath === '' || !@is_readable($unionPath)) { return ''; }
        $h = @md5_file($unionPath);
        return is_string($h) ? substr($h, 0, 12) : '';
    }
    function wpc_used_css_stamp($basis)
    {
        return '/*wpc-ucss:' . $basis . '*/';
    }
    function wpc_used_css_stamp_ok($path, $basis)
    {
        if (!is_string($basis) || $basis === '' || !@is_readable($path)) { return false; }
        $head = (string) @file_get_contents($path, false, null, 0, 48);
        return ($head !== '' && strpos($head, wpc_used_css_stamp($basis)) !== false);
    }
}
if (!function_exists('wpc_used_css_expired_dispatch19')) {
    /**
     * v7.20.19 — AN EXPIRED ARTIFACT NEEDS A DISPATCH, NOT A LONGER SLEEP. Service finding
     * (crit-push v3.198.68): object sets expire at 14 days, and the pointer resolver kept
     * advertising ready=1 rows whose objects had aged out — 18,736 rows across 591 sites.
     * The .18 escalating backoff alone would have made those sites heal SLOWER, because
     * nothing else asks for a regen. Past the first escalation (or on an explicit
     * expired:true from the resolver) we schedule one regen for this template and let the
     * normal land path take over. Throttled per url-key at the service's 30-min debounce
     * cap so a fleet of pages cannot herd.
     */
    function wpc_used_css_expired_dispatch19($urlKey, $why = '404')
    {
        $urlKey = ltrim((string) $urlKey, '/');
        if ($urlKey === '' || !apply_filters('wpc_used_css_expired_dispatch', true)) {
            return false;
        }
        $bk = 'wpc_ucss_redisp_' . md5($urlKey);
        if (function_exists('get_transient') && get_transient($bk)) {
            return false;
        }
        if (function_exists('set_transient')) {
            set_transient($bk, 1, (int) apply_filters('wpc_used_css_expired_dispatch_ttl', 1800));
        }
        if (function_exists('wp_next_scheduled') && function_exists('wpc_pl_sched')
            && !wp_next_scheduled('wpc_crit_redispatch', [$urlKey])) {
            wpc_pl_sched(time() + 20, 'wpc_crit_redispatch', [$urlKey]);
            if (function_exists('wpc_spawn_cron')) {
                wpc_spawn_cron();
            }
        }
        if (function_exists('wpc_crit_collector_arm')) {
            wpc_crit_collector_arm($urlKey);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('ucss-expired-dispatch', $urlKey, '', ['why' => (string) $why]);
        }
        return true;
    }
}

if (!function_exists('wpc_used_css_fetch')) {

    function wpc_used_css_fetch($url, $tplKey, $device = '', &$wpc_why341 = null)
    {
        $wpc_why341 = '';
        $path = wpc_used_css_path($tplKey, $device);
        if ($path === '' || !is_string($url) || $url === '') {
            $wpc_why341 = $path === '' ? 'bad-tpl-path' : 'empty-url';
            return false;
        }
        // Remember the URL BEFORE any gate — including the store-hit path — so an already
        // stored bundle gains a durable pointer on the first run after upgrade rather than
        // only on the next fetch.
        if (function_exists('wpc_used_css_url_remember')) {
            wpc_used_css_url_remember($tplKey, $url, false, $device);
        }
        if (@is_readable($path) && @filesize($path) > 5) {
            // One-shot refresh: stores from before the service pageTokens repair can lack
            // hidden-element state rules — re-pull the current bundle once per artifact.
            // NEVER unlink first: the fetch below writes temp+rename (atomic), so a 404
            // leaves the existing good file intact. Just fall through to force the refetch.
            // .464: keyed on md5($path) alone with a 30-DAY ttl, this gate made the union
            // permanent — same path for a new artifact, transient already set, never refetched.
            // Fold the artifact URL in (invalidates outright if the service versions it) and
            // bound the ttl so the union self-heals regardless.
            $wpc_rk464 = 'wpc_ucsr266_' . md5($path . '|' . $url);
            if (!get_transient($wpc_rk464)) {
                set_transient($wpc_rk464, 1, (int) apply_filters('wpc_used_css_refresh_ttl', 3 * DAY_IN_SECONDS));
            } else {
                // Union already stored — arm the split siblings once per window (they appear
                // only on gens ≥ v3.56.0, so absence is normal and must not hammer). The arm
                // condition is the union BASIS, not mere presence: an .atf.css built from an
                // older union must rebuild, which "absent" never detected.
                $wpc_atf464 = (string) preg_replace('/\.css$/', '.atf.css', $path);
                $wpc_bas464 = wpc_used_css_basis($path);
                $wpc_sk464  = 'wpc_ucsp_' . md5($path . '|' . $wpc_bas464);
                if (function_exists('wpc_used_css_fetch_split')
                    && !wpc_used_css_stamp_ok($wpc_atf464, $wpc_bas464)
                    && !get_transient($wpc_sk464)) {
                    set_transient($wpc_sk464, 1, 6 * HOUR_IN_SECONDS);
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('ucss-split-rebuild', basename($wpc_atf464), '', [
                            'basis'   => $wpc_bas464,
                            'present' => @is_readable($wpc_atf464) ? 1 : 0,
                        ]);
                    }
                    wpc_used_css_fetch_split($url, $tplKey, $device);
                }
                $wpc_why341 = 'gate-skip';
                return true;
            }
        }


        $wpc_ucf_log = function ($why, $extra = []) use ($url, $device, &$wpc_why341) {
            $wpc_why341 = $why . (isset($extra['code']) ? ':' . $extra['code'] : '') . (isset($extra['len']) ? ':' . $extra['len'] : '');
            if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('used-css-fetch', '', substr((string) $url, 0, 90), array_merge(['why' => $why, 'dev' => $device !== '' ? $device : 'union'], $extra)); }
        };


        // Artifacts can be amended IN PLACE on the same uuid/URL — an un-busted GET gets
        // Bunny's stale edge copy (hawkeye receipt: corrected bytes on origin, old order
        // served). Every real fetch busts; the one-shot gates already bound frequency.
        $wpc_bust336 = (strpos($url, '?') === false ? '?' : '&') . 't=' . time();
// v7.10.550 — the service advertises used_css_*_url unconditionally on one /status
// branch while the sibling branch HEAD-probes first, so a URL can be published for an
// object that was never uploaded. We treated the advertisement as proof and re-probed
// on EVERY land. Remember a 404 briefly: presence must be proven, not announced.
$wpc_nk550 = 'wpc_ucss404_' . md5((string) $url);
if (function_exists('get_transient') && get_transient($wpc_nk550)) {
    return false;
}
        $resp = wp_remote_get($url . $wpc_bust336, ['headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : ''], 'timeout' => (int) apply_filters('wpc_used_css_fetch_timeout', 5)]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 404
                && function_exists('set_transient')) {
                // A URL the service advertised but never uploaded 404s forever; a flat 15-min
                // memory re-probes it ~100x/day per device and starves the budget (customer
                // receipt: 45 fetch failures + 94 budget-deferred per HOUR). Escalate per URL:
                // 15m -> 1h -> 6h -> 24h cap; any 200 lands the bundle and retires the counter.
                $wpc_na18 = (int) get_transient($wpc_nk550 . '_n') + 1;
                set_transient($wpc_nk550 . '_n', $wpc_na18, 7 * DAY_IN_SECONDS);
                $wpc_lad18 = [900, 3600, 21600, 86400];
                $wpc_ttl18 = $wpc_lad18[min($wpc_na18, 4) - 1];
                set_transient($wpc_nk550, 1, (int) apply_filters('wpc_used_css_404_ttl', $wpc_ttl18));
                // v7.20.19 — site-wide "the stylesheet is not coming" flag. The conceal guard
                // hides real UI until the used-css link releases it; while the fetch is known to
                // be failing the guard must concede at once instead of hiding navigation.
                set_transient('wpc_ucss_failing', 1, (int) $wpc_ttl18);
            }
            $wpc_ucf_log('http', ['code' => is_wp_error($resp) ? ('err:' . $resp->get_error_code()) : (int) wp_remote_retrieve_response_code($resp)]);
            return false;
        }
        $body = wp_remote_retrieve_body($resp);
        if (!is_string($body) || strlen($body) < 64 || strlen($body) > 5242880) {
            $wpc_ucf_log('size', ['len' => is_string($body) ? strlen($body) : -1]);
            return false;
        }
        if (preg_match('/<(?:html|body|head|!doctype)\b/i', substr($body, 0, 4096))) {
            $wpc_ucf_log('html', ['head' => substr(preg_replace('/\s+/', ' ', $body), 0, 50)]);
            return false; // HTML (error/redirect page) — never store as CSS
        }
        $body = wpc_used_css_process_body($body);
        $dir = dirname($path);
        if (!is_dir($dir) && function_exists('wp_mkdir_p')) {
            wp_mkdir_p($dir);
        }
        if (!is_dir($dir)) {
            $wpc_ucf_log('nodir');
            return false;
        }
        $tmp = $path . '.tmp-' . getmypid();
        if (wpc_crit_meta_write($tmp, $body) === false) {
            $wpc_ucf_log('writefail');
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            $wpc_ucf_log('renamefail');
            return false;
        }
        $wpc_ucf_log('ok', ['bytes' => strlen($body)]);
        if (function_exists('delete_transient')) { delete_transient($wpc_nk550 . '_n'); delete_transient('wpc_ucss_failing'); }
        // The fetch went through, so this locator is real — promote it to verified (.471).
        if (function_exists('wpc_used_css_url_remember')) {
            wpc_used_css_url_remember($tplKey, $url, true, $device);
        }
        if (function_exists('wpc_used_css_fetch_split')) {
            wpc_used_css_fetch_split($url, $tplKey, $device);
        }
        return true;
    }
}
if (!function_exists('wpc_used_css_process_body')) {
    /**
     * v7.10.394 embed-document leak belt: collector rules sourced from iframe embeds
     * (vimeo/youtube players) carry html/body{overflow:hidden} — correct inside the
     * embed's OWN document, a page-wide scroll lock when unioned into ours (busy
     * receipt: tpl-781b8d54b6c778d9.desktop.atf.css, "source: player.vimeo.com").
     * Strip overflow:hidden from any rule whose selector list hits BARE html/body;
     * class-scoped locks (.modal-open body) are legitimate and pass through.
     */
    function wpc_used_css_embed_leak_belt($body)
    {
        if (!is_string($body) || $body === '' || stripos($body, 'overflow') === false) {
            return $body;
        }
        $out = preg_replace_callback('/([^{}\/]{1,400})(\{[^{}]*\})/', function ($m) {
            if (!preg_match('/overflow[^:;{}]*:\s*hidden/i', $m[2])) return $m[0];
            $bare = false;
            foreach (explode(',', $m[1]) as $sel) {
                if (preg_match('/^(?:html|body)(?::[a-z()-]+)?$/i', trim($sel))) { $bare = true; break; }
            }
            if (!$bare) return $m[0];
            return $m[1] . preg_replace('/overflow(?:-[xy])?\s*:\s*hidden\s*;?/i', '', $m[2]);
        }, $body);
        return is_string($out) ? $out : $body;
    }
    /** One-shot heal of already-landed artifacts (the belt covers future fetches). */
    function wpc_used_css_disk_resanitize()
    {
        if (get_option('wpc_ucss_resan394') || !defined('WPS_IC_CRITICAL')) { return; }
        update_option('wpc_ucss_resan394', 1, false);
        foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/used-css/tpl-*.css') as $wpc_uf394) {
            $wpc_ub394 = (string) @file_get_contents($wpc_uf394);
            if ($wpc_ub394 === '') { continue; }
            $wpc_un394 = wpc_used_css_embed_leak_belt($wpc_ub394);
            if (is_string($wpc_un394) && $wpc_un394 !== $wpc_ub394) {
                @file_put_contents($wpc_uf394, $wpc_un394);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('ucss-embed-leak-healed', basename($wpc_uf394), '', []);
                }
            }
        }
    }
    /** Shared post-fetch pipeline for every stored used-css body (union, atf, rest). */
    function wpc_used_css_process_body($body)
    {
        $body = wpc_used_css_embed_leak_belt($body);
        if (function_exists('wpc_css_insert_fallbacks')) {
            $body = wpc_css_insert_fallbacks($body);
        }
        // Faces inside the used bundle bypass the font emitters — bake the effective
        // display here (strip-then-set; a later declaration would win a bare prepend).
        if (is_string($body) && stripos($body, '@font-face') !== false) {
            $wpc_fdu = 'smart';
            $wpc_su18 = get_option(WPS_IC_SETTINGS);
            if (is_array($wpc_su18) && !empty($wpc_su18['font-display'])) {
                $wpc_fdu = (string) $wpc_su18['font-display'];
            }
            if (function_exists('wpc_font_display_effective')) {
                $wpc_fdu = wpc_font_display_effective($wpc_fdu);
            }
            if ($wpc_fdu !== '' && $wpc_fdu !== 'off' && preg_match('/^[a-z-]{2,16}$/', $wpc_fdu)) {
                // .485: per-face, for the same reason as the CSS emitters — one blanket value
                // put 'optional' on families with no metric-matched fallback.
                $wpc_fdraw485 = $wpc_fdu;
                $body = (string) preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($wpc_fdraw485) {
                    $b = (string) preg_replace('/font-display\s*:\s*[a-z-]+\s*;?/i', '', $m[0]);
                    $fam = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $b, $fm)
                        ? trim($fm[1], " \t\"'") : '';
                    $fd = ($fam !== '' && function_exists('wpc_font_display_effective'))
                        ? wpc_font_display_effective($wpc_fdraw485, $fam) : $wpc_fdraw485;
                    if (!in_array($fd, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) { $fd = 'swap'; }
                    return (string) preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $fd . ';', $b, 1);
                }, $body);
            }
        }
        if (function_exists('wpc_used_css_zoneify')) {
            $body = wpc_used_css_zoneify($body);
        }
        return $body;
    }
}
if (!function_exists('wpc_used_css_fetch_split')) {
    /** ATF/REST siblings, derived from the legacy URL. Both-or-neither — a lone half
     *  is worse than the union, so any failure drops the pair. */
    function wpc_used_css_fetch_split($url, $tplKey, $device = '')
    {
        try {
            if (!is_string($url) || strpos($url, '.css') === false || !apply_filters('wpc_used_css_split', true)) {
                return false;
            }
            $base = wpc_used_css_path($tplKey, $device);
            if ($base === '') {
                return false;
            }
            $bodies = [];
            foreach (['atf', 'rest'] as $part) {
                $pu = (string) preg_replace('/\.css(\?|$)/', '.' . $part . '.css$1', $url, 1);
                $r = wp_remote_get($pu . (strpos($pu, '?') === false ? '?' : '&') . 't=' . time(), ['headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : ''], 'timeout' => (int) apply_filters('wpc_used_css_fetch_timeout', 5)]);
                if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
                    return false;
                }
                $b = wp_remote_retrieve_body($r);
                if (!is_string($b) || strlen($b) < 32 || strlen($b) > 5242880
                    || preg_match('/<(?:html|body|head|!doctype)\b/i', substr($b, 0, 4096))) {
                    return false;
                }
                $bodies[$part] = wpc_used_css_process_body($b);
            }
            // Stamp the union basis into each sibling so a later render can tell "built from
            // THIS union" from "built from some earlier one" — the distinction mere presence
            // could not make, which is why the pruned bundle never reached the wire.
            $wpc_bas464 = function_exists('wpc_used_css_basis') ? wpc_used_css_basis($base) : '';
            foreach ($bodies as $part => $b) {
                $p = (string) preg_replace('/\.css$/', '.' . $part . '.css', $base);
                if ($wpc_bas464 !== '') { $b = wpc_used_css_stamp($wpc_bas464) . $b; }
                $tmp = $p . '.tmp-' . getmypid();
                if (wpc_crit_meta_write($tmp, $b) === false || !@rename($tmp, $p)) {
                    @unlink($tmp);
                    foreach (['atf', 'rest'] as $q) {
                        @unlink((string) preg_replace('/\.css$/', '.' . $q . '.css', $base));
                    }
                    return false;
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('used-css-split', '', substr((string) $url, 0, 90), ['dev' => $device !== '' ? $device : 'union', 'atf' => strlen($bodies['atf']), 'rest' => strlen($bodies['rest'])]);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
if (!function_exists('wpc_used_css_demand_args')) {
    /**
     * THE demand-control line — the single decision point for advertising the capability.
     * Reads the render-persisted tpl.txt for this URL (async /generate contexts can't compute
     * a template identity — no styles enqueued); returns ['tpl_key','used_css'] ONLY when that
     * template has no artifact on disk. Store-hit or unknown-template → [] → nothing sent.
     */
    function wpc_used_css_demand_args($urlKey)
    {
        if (!defined('WPS_IC_CRITICAL') || empty($urlKey)) {
            return [];
        }


        if (apply_filters('wpc_used_css_demand_gate', true)) {
            $set = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
            if (!is_array($set) || empty($set['used-css']) || $set['used-css'] != '1') {
                return [];
            }
        }
        $tk = trim((string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/tpl.txt'));
        if (!wpc_used_css_key_valid($tk)) {


            // Bounded by the same dispatch cadence + .51 self-arm caps as the keyed path.
            return ['tpl_key' => '', 'used_css' => '1'];
        }
        // A HIT requires the current-era per-device artifact; a legacy union alone must not
        // suppress the demand or old stores block their own upgrade indefinitely.
        $pm = wpc_used_css_path($tk, 'mobile');
        if ($pm !== '' && @is_readable($pm) && @filesize($pm) > 64) {
            // v7.10.469 — PRESENCE IS NOT REFRESHABILITY, and the guard above anticipated this
            // class for a legacy union without covering a stale per-device store doing the same.
            // busy deadlocked exactly here: the fossil on disk suppressed the demand, used_css:0
            // meant the service never sent used_css_url, without that pointer nothing could
            // refresh the union or rebuild the siblings, so the gate kept seeing the same fossil
            // — 602KB of permanently un-updatable CSS with 561 orphaned keyframes on the wire.
            // Only a store we could replace OURSELVES may suppress its own replacement.
            // Self-limiting: once a pointer lands this suppresses again, and re-asserting costs
            // no extra request (it sets a capability on a dispatch already being made, under the
            // same cadence + self-arm caps as the keyed path).
            // .471: VERIFIED, not merely present. An unverified pointer can be the service's
            // fail-open /status locator for a gen that never produced a bundle (404 forever);
            // trusting it would re-create the very deadlock .469 closed.
            if (function_exists('wpc_used_css_url_verified') && wpc_used_css_url_verified($tk)) {
                return [];
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('ucss-demand-reasserted', (string) $urlKey, '', [
                    'tpl'   => substr($tk, 0, 24),
                    'bytes' => (int) @filesize($pm),
                    'why'   => 'orphaned-no-pointer',
                ]);
            }
        }
        return ['tpl_key' => $tk, 'used_css' => '1'];
    }
}

if (!function_exists('wpc_used_css_apply_demand')) {

    function wpc_used_css_apply_demand(&$args, $urlKey)
    {
        if (!function_exists('wpc_used_css_demand_args')) {
            return false;
        }
        $demand = wpc_used_css_demand_args($urlKey);
        if (empty($demand) || empty($demand['used_css'])) {
            return false;
        }

        if (!empty($demand['tpl_key'])) {
            $args['tpl_key'] = (string) $demand['tpl_key'];
        }

        if (!isset($args['capabilities']) || !is_array($args['capabilities'])) {
            $args['capabilities'] = [];
        }
        $args['capabilities']['used_css'] = 1;
        // Flat belt for the form-encoded POST path (either parser sees it; harmless on JSON).
        $args['used_css'] = '1';
        return true;
    }
}


// The demand line rides /generate, but a healthy site with crit present never fires one — so


if (!function_exists('wpc_css_insert_fallbacks')) {
    /** Insert metric-fallback family names into font-family chains (declarations only;
     *  @font-face descriptors masked). Unknown fallback families are skipped by browsers,
     *  so inserting for every metrics-table family is safe. */
    function wpc_css_insert_fallbacks($css)
    {
        try {
            if (!is_string($css) || $css === '' || stripos($css, 'font-family') === false) {
                return $css;
            }
            $table = apply_filters('wpc_font_fallback_metrics', []);
            if (!is_array($table) || !$table) {
                return $css;
            }
            $mask = [];
            $css2 = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use (&$mask) {
                $mask[] = $m[0];
                return "\x02WPCF" . (count($mask) - 1) . "\x02";
            }, $css);
            if (!is_string($css2)) {
                return $css;
            }
            foreach ($table as $fam => $row) {
                if (!is_string($fam) || trim($fam) === '' || !is_array($row)) {
                    continue;
                }
                $fam = trim($fam);
                // Per-weight rows are 'family|weight|style' — the piped key must NEVER
                // splice literally; splice the base family once (faces carry the weights).
                if (strpos($fam, '|') !== false) {
                    $fam = trim((string) strstr($fam, '|', true));
                    if ($fam === '') {
                        continue;
                    }
                }
                // v7.10.731 — splice only what the declarer will declare: a row with no valid
                // percentage metric produces no @font-face, and an undeclared name in the stack
                // is a pin that lies.
                $wpc_dcl731 = false;
                foreach (['size-adjust', 'ascent-override', 'descent-override', 'line-gap-override'] as $wpc_mk731) {
                    if (!empty($row[$wpc_mk731]) && preg_match('/^[0-9.]+%$/', (string) $row[$wpc_mk731])) {
                        $wpc_dcl731 = true;
                        break;
                    }
                }
                if (!$wpc_dcl731) {
                    continue;
                }
                if (stripos($css2, $fam) === false) {
                    continue;
                }
                $fb = $fam . ' Fallback';
                if (stripos($css2, $fb) !== false) {
                    continue;
                }
                $css2 = preg_replace(
                    '/(font-family\s*:\s*)([\'"]?)' . preg_quote($fam, '/') . '\2\s*(,|;|\}|!)/i',
                    '$1$2' . $fam . '$2,"' . $fb . '"$3',
                    $css2
                );
                if (!is_string($css2)) {
                    return $css;
                }
            }
            $out = preg_replace_callback('/\x02WPCF(\d+)\x02/', function ($m) use ($mask) {
                return $mask[(int) $m[1]];
            }, $css2);
            return (is_string($out) && strpos($out, "\x02WPCF") === false) ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }
}

if (!function_exists('wpc_header_css_slice')) {
    /**
     * Extract every rule governing the header region from a stylesheet. The deferred CSS
     * is our own file — inlining its header rules with crit makes first-paint header
     * geometry IDENTICAL to final, so late application can't move anything, regardless
     * of crit coverage. Cached beside the source, keyed by its mtime.
     */
    function wpc_header_css_slice($cssPath, $tokens = [])
    {
        try {
            if (!is_string($cssPath) || !@is_readable($cssPath)) {
                return '';
            }
            $tokens = array_values(array_filter(array_map('strval', (array) $tokens)));
            $mt    = (int) @filemtime($cssPath);
            $cache = $cssPath . '.hslice' . ($tokens ? '-' . substr(md5(implode(',', $tokens)), 0, 8) : '');
            if (@is_readable($cache) && (int) @filemtime($cache) >= $mt) {
                return (string) @file_get_contents($cache);
            }
            $css = (string) @file_get_contents($cssPath);
            if ($css === '') {
                return '';
            }
            $css   = preg_replace('#/\*.*?\*/#s', '', $css);
            // Keyword selectors alone miss opaque builder IDs (.elementor-element-xxxx) that
            // style the header region — the markup-derived tokens make the slice complete.
            $selRx = '/(?:^|[\s,>+~(])(?:header\b|\.elementor-location-header|#masthead|\.site-header|\.main-header|\.[\w-]*navbar[\w-]*|\.elementor-nav-menu[\w-]*|\.[\w-]*site-logo[\w-]*|\.custom-logo\b)/i';
            $pick  = function ($block) use ($selRx, $tokens, &$pick) {
                $out = '';
                $len = strlen($block);
                $i   = 0;
                while ($i < $len) {
                    $open = strpos($block, '{', $i);
                    if ($open === false) {
                        break;
                    }
                    $sel = trim(substr($block, $i, $open - $i));
                    // balanced-brace scan for the block body
                    $depth = 1;
                    $j = $open + 1;
                    while ($j < $len && $depth > 0) {
                        $c = $block[$j];
                        if ($c === '{') { $depth++; }
                        elseif ($c === '}') { $depth--; }
                        $j++;
                    }
                    $body = substr($block, $open + 1, $j - $open - 2);
                    if ($sel !== '' && $sel[0] === '@') {
                        if (stripos($sel, '@media') === 0 || stripos($sel, '@supports') === 0) {
                            $inner = $pick($body);
                            if ($inner !== '') {
                                $out .= $sel . '{' . $inner . '}';
                            }
                        }
                        // other at-rules (@font-face, @keyframes) never move the header — skip
                    } else {
                        $hit = preg_match($selRx, $sel);
                        if (!$hit && $tokens) {
                            foreach ($tokens as $tk) {
                                if ($tk !== '' && strpos($sel, $tk) !== false) { $hit = true; break; }
                            }
                        }
                        if ($hit) {
                            $out .= $sel . '{' . $body . '}';
                        }
                    }
                    $i = $j;
                }
                return $out;
            };
            $slice = $pick($css);
            if (strlen($slice) > 16384) {
                $slice = substr($slice, 0, strrpos(substr($slice, 0, 16384), '}') + 1);
            }
            @file_put_contents($cache, $slice, LOCK_EX);
            return $slice;
        } catch (\Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('wpc_fallback_restitch')) {
    /** Re-apply the fallback stack-splice to stored used.css once metrics exist. Idempotent. */
    function wpc_fallback_restitch($urlKey)
    {
        try {
            if (empty($urlKey) || !defined('WPS_IC_CRITICAL')
                || !function_exists('wpc_css_insert_fallbacks') || !function_exists('wpc_used_css_path')) {
                return;
            }
            // Metrics may have landed within this request — drop the primed request-cache
            // so the splice below reads the freshly-written table.
            unset($GLOBALS['wpc_fm_cache159']);
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
            $tpl = trim((string) @file_get_contents($dir . 'used_tpl.txt'));
            if ($tpl === '') {
                $tpl = trim((string) @file_get_contents($dir . 'tpl.txt'));
            }
            if ($tpl === '') {
                return;
            }
            foreach (['', 'mobile', 'desktop'] as $dev) {
                $p = wpc_used_css_path($tpl, $dev);
                if ($p === '' || !@is_readable($p)) {
                    continue;
                }
                $body = (string) @file_get_contents($p);
                if ($body === '') {
                    continue;
                }
                $new = wpc_css_insert_fallbacks($body);
                if (is_string($new) && function_exists('wpc_used_css_zoneify')) {
                    $new = wpc_used_css_zoneify($new);
                }
                if (is_string($new) && $new !== $body) {
                    $tmp = $p . '.tmp.' . substr(md5($p . strlen($new)), 0, 8);
                    if (@file_put_contents($tmp, $new, LOCK_EX) !== false && @rename($tmp, $p)) {
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('fallback-restitched', $urlKey, '', ['dev' => $dev === '' ? 'single' : $dev, 'bytes' => strlen($new)]);
                        }
                    } else {
                        @unlink($tmp);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('wpc_fallback_restitch_once')) {
    // One-shot per version: converge the already-stored used.css that missed the splice.
    function wpc_fallback_restitch_once()
    {
        try {
            $v = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '0';
            if (get_option('wpc_fbstitch_v') === $v || !class_exists('wps_ic_url_key')) {
                return;
            }
            update_option('wpc_fbstitch_v', $v, false);
            $k = (new wps_ic_url_key())->setup(home_url('/'));
            if ($k !== '' && function_exists('wpc_fallback_restitch')) {
                wpc_fallback_restitch($k);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_fallback_restitch_once', 36);
}

if (!function_exists('wpc_memory_pressure')) {
    /**
     * Load-avg-free pressure signal: true when PHP is near its memory ceiling. Works on
     * every host (shared/containerized boxes that lack a usable sys_getloadavg). Conservative
     * — only fires close to OOM, where deferring background work is exactly the right call.
     */
    function wpc_memory_pressure()
    {
        $limit = function_exists('ini_get') ? (string) @ini_get('memory_limit') : '';
        if ($limit === '' || $limit === '-1') {
            return false; // no ceiling → no memory pressure to detect
        }
        $bytes = (int) $limit;
        $unit  = strtolower(substr(trim($limit), -1));
        if ($unit === 'g') { $bytes *= 1073741824; }
        elseif ($unit === 'm') { $bytes *= 1048576; }
        elseif ($unit === 'k') { $bytes *= 1024; }
        if ($bytes <= 0) {
            return false;
        }
        $used  = function_exists('memory_get_usage') ? memory_get_usage(true) : 0;
        $ratio = (float) apply_filters('wpc_memory_pressure_ratio', 0.90);
        return $used >= $bytes * $ratio;
    }
}

if (!function_exists('wpc_under_pressure')) {
    /**
     * Fleet governor: on small shared pools our background work must yield to visitors
     * entirely, not just stay bounded. Load above ~1.5x cores means the box is queueing —
     * every background lane defers and retries when the pressure clears. Memory pressure
     * (wpc_memory_pressure) is checked first so hosts without sys_getloadavg still shed.
     */
    function wpc_under_pressure()
    {
        try {
            if (!apply_filters('wpc_pressure_governor', true)) {
                return false;
            }
            // Memory pressure works on EVERY host (no sys_getloadavg dependency) — shared and
            // containerized boxes often lack a usable load average, so checking it first means
            // the governor still sheds background work when a tiny box is about to OOM.
            if (function_exists('wpc_memory_pressure') && wpc_memory_pressure()) {
                return true;
            }
            if (!function_exists('sys_getloadavg')) {
                return false;
            }
            $l = @sys_getloadavg();
            if (!is_array($l) || !isset($l[0])) {
                return false;
            }
            // Load is only meaningful against a core count we actually KNOW. Unknown cores =
            // host-load vs container mismatch — never shed on it (memory belt above still guards)
            if (!apply_filters('wpc_pressure_trust_load',
                function_exists('wpc_cores_known767') ? wpc_cores_known767() : true)) {
                return false;
            }
            $cores = function_exists('wpc_box_cores') ? max(1, (int) wpc_box_cores()) : 2;
            $hot = (float) $l[0] > $cores * (float) apply_filters('wpc_pressure_threshold', 1.5);
            if ($hot && function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_pressure_log')) {
                set_transient('wpc_pressure_log', 1, 60);
                wpc_cache_first_log('pressure-deferred', '', '', ['load1' => round((float) $l[0], 2), 'cores' => $cores]);
            }
            return $hot;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_box_cores')) {
    function wpc_box_cores()
    {
        static $cores = null;
        if ($cores === null) {
            $cores = 2;
            $ci = @is_readable('/proc/cpuinfo') ? (string) @file_get_contents('/proc/cpuinfo') : '';
            if ($ci !== '' && preg_match_all('/^processor\s*:/m', $ci, $pm)) {
                $cores = max(1, count($pm[0]));
            } else {
                // Containerized: the cgroup CPU quota is the container's real allowance —
                // host cpuinfo (when readable at all) overstates it
                $cg = wpc_cgroup_cores767();
                if ($cg > 0) {
                    $cores = $cg;
                }
            }
        }
        return $cores;
    }
}

if (!function_exists('wpc_cgroup_cores767')) {
    function wpc_cgroup_cores767()
    {
        // v2: "<quota|max> <period>"; v1: cfs_quota_us (-1 = unlimited) / cfs_period_us
        $v2 = @is_readable('/sys/fs/cgroup/cpu.max') ? trim((string) @file_get_contents('/sys/fs/cgroup/cpu.max')) : '';
        if ($v2 !== '' && preg_match('/^(\d+)\s+(\d+)$/', $v2, $m) && (int) $m[2] > 0) {
            return max(1, (int) ceil((int) $m[1] / (int) $m[2]));
        }
        $q = @is_readable('/sys/fs/cgroup/cpu/cpu.cfs_quota_us') ? (int) trim((string) @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_quota_us')) : 0;
        $p = @is_readable('/sys/fs/cgroup/cpu/cpu.cfs_period_us') ? (int) trim((string) @file_get_contents('/sys/fs/cgroup/cpu/cpu.cfs_period_us')) : 0;
        if ($q > 0 && $p > 0) {
            return max(1, (int) ceil($q / $p));
        }
        return 0;
    }
}

if (!function_exists('wpc_cores_known767')) {
    /** Cores are KNOWN when either /proc/cpuinfo or a cgroup quota is readable. When neither
     *  is, sys_getloadavg() is the HOST's number on a shared box — judging a 2-core default
     *  against host load shed every render forever (searchcommander: host load 13, container
     *  idle, MySQL 1 active query, cron 0 overdue — dark for months). */
    function wpc_cores_known767()
    {
        static $known = null;
        if ($known === null) {
            $ci = @is_readable('/proc/cpuinfo') ? (string) @file_get_contents('/proc/cpuinfo') : '';
            $known = ($ci !== '' && preg_match('/^processor\s*:/m', $ci) === 1)
                || (function_exists('wpc_cgroup_cores767') && wpc_cgroup_cores767() > 0);
        }
        return $known;
    }
}

if (!function_exists('wpc_pipeline_admission_ok')) {
    // URL-space guard: the crit/warm/repull pipeline is for CANONICAL pages only.
    // Query-string URLs (tracking params, bot probes, our own marker params) each
    // minted their own url_key + crit dir + repull/warm/fonts event chains — cron
    // bloated to hundreds of per-URL entries and alloptions paid for it per request.
    function wpc_pipeline_admission_ok()
    {
        $q = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
        if ($q === '') {
            return true;
        }
        parse_str($q, $p);
        $wpc_plain = function_exists('get_option') && (string) get_option('permalink_structure') === '';
        foreach ($p as $pk => $pv) {
            $pk = (string) $pk;
            if (in_array($pk, ['wpc_visitor_mode', 'remote_generate_critical'], true)) {
                continue;
            }
            if ($wpc_plain && in_array($pk, ['p', 'page_id'], true)) {
                continue; // plain-permalink sites: ?p= IS the canonical URL
            }
            return false;
        }
        return true;
    }
    // Junk-key detector for ALREADY-QUEUED chains: our own pipeline markers and common
    // tracking params folded into url_keys must never keep re-arming their own events
    function wpc_pipeline_key_junk($urlKey)
    {
        return (bool) preg_match('/criticaldone|criticalcombine|testcomplete|disablewpc|remote_generate|remote-generate|wpc_smoke|gad_source|gad_campaignid|gclid|fbclid|utm_|wpc_no_buffer/i', (string) $urlKey);
    }
    // Global ceiling on pending per-URL pipeline events — beyond it, chains stop
    // re-arming (live traffic re-arms organically; the cron row must stay small)
    /**
     * v7.10.472 — the ceiling, scaled to the box instead of a flat 40.
     * 40 was sized for a healthy host. busy ran all day on 2 cores at load1 38-52, where 40
     * queued pipeline events is far more than the box can drain — the contention doctrine's
     * fan-out ceiling has to be a function of capacity, not a constant. Floored at 8 so the
     * pipeline still functions on the smallest box.
     */
    /**
     * v7.10.473 — SAFE MODE: the operator lever the 2026-07-08 FPM audit specified and that was
     * never built. Deliberately NOT a third mechanism: it is the existing warm pause
     * (wpc_url_warm_on_purge / wpc_warm_paused_at) generalised from ONE hook to EVERY background
     * lane, enforced at the same choke point. ?disableWPC stays untouched — that is a per-request
     * whole-plugin bypass for a human debugging one URL, a different scope and audience.
     *
     * MANUAL ONLY, by design. The automatic behaviour already exists as .472's per-schedule
     * pressure shed. An auto-arming site-wide stand-down on a chronically loaded box (busy: load1
     * 38-52 all day) would never lift, and a stand-down that never lifts is the journal-leak shape
     * — bounded shedding belongs per-call, a permanent lever belongs to a human.
     */
    function wpc_safe_mode()
    {
        static $wpc_sm473 = null;
        if ($wpc_sm473 === null) {
            $wpc_sm473 = (bool) get_option('wpc_safe_mode', 0);
        }
        return (bool) apply_filters('wpc_safe_mode', $wpc_sm473);
    }
    /**
     * The single question every background lane asks. Returns false when the lane must stand down.
     * Safe Mode stands down ALL lanes; the legacy warm pause still stands down warm alone.
     */
    function wpc_bg_lane_allowed($lane = '')
    {
        if (wpc_safe_mode()) {
            return false;
        }
        if (($lane === 'wpc_url_warm' || $lane === 'warm') && !get_option('wpc_url_warm_on_purge', 1)) {
            return false;
        }
        return true;
    }
    function wpc_pipeline_ceiling()
    {
        $wpc_c472 = function_exists('wpc_box_cores') ? max(1, (int) wpc_box_cores()) : 2;
        return max(8, (int) apply_filters('wpc_pipeline_events_max', min(40, $wpc_c472 * 8)));
    }
    function wpc_pipeline_events_ok()
    {
        // On RAIL sites the pipeline lives in the rail table, not the cron array — so counting
        // cron alone returned ~0 and the ceiling never bound on exactly the sites whose queue
        // grows fastest. Count whichever queue the work is actually going into.
        if (function_exists('wpc_rail_on') && wpc_rail_on() && function_exists('wpc_rail_depth')) {
            static $wpc_rd472 = null;
            if ($wpc_rd472 === null) { $wpc_rd472 = (int) wpc_rail_depth(); }
            if (isset($GLOBALS['wpc_plsched_bump179'])) {
                $wpc_rd472 += (int) $GLOBALS['wpc_plsched_bump179'];
                unset($GLOBALS['wpc_plsched_bump179']);
            }
            return $wpc_rd472 < wpc_pipeline_ceiling();
        }
        if (!function_exists('_get_cron_array')) {
            return true;
        }
        static $n = null;
        if ($n === null) {
            $n = 0;
            foreach ((array) _get_cron_array() as $wpc_ts => $wpc_hooks) {
                foreach ((array) $wpc_hooks as $wpc_h => $wpc_ev) {
                    if (strpos((string) $wpc_h, 'wpc_lcp_repull') === 0 || strpos((string) $wpc_h, 'wpc_url_warm') === 0
                        || strpos((string) $wpc_h, 'wpc_combine_fonts_fetch') === 0 || strpos((string) $wpc_h, 'wpc_autopurge_check') === 0
                        || strpos((string) $wpc_h, 'wpc_crit_collect') === 0 || strpos((string) $wpc_h, 'wpc_crit_redispatch') === 0
                        || strpos((string) $wpc_h, 'wpc_presc') === 0) {
                        $n += count((array) $wpc_ev);
                    }
                }
            }
        }
        // The count is cached per request; wpc_pl_sched bumps it on every successful
        // schedule so a single request cannot blow past the ceiling between re-reads.
        if (isset($GLOBALS['wpc_plsched_bump179'])) {
            $n += (int) $GLOBALS['wpc_plsched_bump179'];
            unset($GLOBALS['wpc_plsched_bump179']);
        }
        return $n < 40;
    }
}

if (!function_exists('wpc_pl_sched')) {
    // Capped scheduler for per-URL pipeline events — the cron row must stay small
    function wpc_pl_sched($ts, $hook, $args = [])
    {
        // Warm pause is enforced HERE because this is the only path every warm scheduling site
        // shares (:368 :380 :479 :682 :694 :705 — retry paths included). .423 gated one site and
        // left the rest open, so the queue refilled to 12 within 7 minutes of pausing. One choke
        // point, not N agreeing lists — same lesson as the icon-font detectors.
        // .473: ONE question for every lane. Safe Mode stands all of them down; the legacy warm
        // pause still stands down warm alone, so existing behaviour is unchanged when it is off.
        if (function_exists('wpc_bg_lane_allowed') && !wpc_bg_lane_allowed((string) $hook)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('pl-sched-standdown', (string) $hook, '', [
                    'safe' => (function_exists('wpc_safe_mode') && wpc_safe_mode()) ? 1 : 0,
                ]);
            }
            return false;
        }
        // v7.10.472 — ORDER IS THE FIX. The ceiling used to sit AFTER the rail branch, so a rail
        // site (busy: rail-engine=loopback) returned early and wpc_pipeline_events_ok() never ran:
        // the fan-out ceiling was dead code on exactly the sites whose queue grows fastest. Both
        // the shed and the ceiling now gate EVERY engine, before any enqueue decision.
        // Shed first: a box already over its load/memory line must stop ADMITTING work, not just
        // stop draining it — the dual-admission law. Fail-open if the probe is unavailable.
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()
            && apply_filters('wpc_pl_sched_shed_on_pressure', true)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('pl-sched-shed', (string) $hook, '', [
                    'load1' => function_exists('sys_getloadavg') ? round((float) @sys_getloadavg()[0], 2) : -1,
                    'cores' => function_exists('wpc_box_cores') ? (int) wpc_box_cores() : -1,
                ]);
            }
            return false;
        }
        if (function_exists('wpc_pipeline_events_ok') && !wpc_pipeline_events_ok()) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('pl-sched-ceiling', (string) $hook, '', [
                    'cap'  => function_exists('wpc_pipeline_ceiling') ? wpc_pipeline_ceiling() : 40,
                    'rail' => (function_exists('wpc_rail_on') && wpc_rail_on()) ? 1 : 0,
                ]);
            }
            return false;
        }
        // RAIL: when enabled, ALL pipeline work rides the single queue — one consumer,
        // priorities, dedupe, off-pool engines. Legacy single-events otherwise.
        if (function_exists('wpc_rail_on') && wpc_rail_on() && function_exists('wpc_rail_enqueue')) {
            $wpc_rq472 = wpc_rail_enqueue($hook, $args, ['at' => (int) $ts]);
            if ($wpc_rq472) {
                // Same in-request accounting the cron path gets, so one request cannot burst
                // past the ceiling between two depth reads.
                $GLOBALS['wpc_plsched_bump179'] = (int) ($GLOBALS['wpc_plsched_bump179'] ?? 0) + 1;
            }
            return $wpc_rq472;
        }
        $wpc_ok179 = wp_schedule_single_event($ts, $hook, $args);
        if ($wpc_ok179 !== false) {
            $GLOBALS['wpc_plsched_bump179'] = (int) ($GLOBALS['wpc_plsched_bump179'] ?? 0) + 1;
        }
        return $wpc_ok179;
    }
}

if (!function_exists('wpc_land_purge_coalesced')) {
    // Land purges COALESCE: crit/lcp/delay/fonts artifacts arrive in waves 30-150s apart,
    // and each purge restarts the cache-rebuild convoy. First land purges immediately
    // (visitors need crit fast); every later wave inside the window marks dirty and ONE
    // finalize event does the last purge+warm.
    function wpc_land_purge_coalesced($urlKey, $url, $context)
    {
        if (!class_exists('wps_ic_cache_integrations') || !method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
            return;
        }
        $k = 'wpc_landcyc_' . substr(md5((string) $urlKey), 0, 16);
        $c = get_option($k);
        $now = time();
        if (!is_array($c) || ($now - (int) ($c['t'] ?? 0)) > 240) {
            update_option($k, ['t' => $now, 'dirty' => 0, 'u' => (string) $url], false);
            wps_ic_cache_integrations::purgeUrlHtml($urlKey, $url, ['context' => $context]);
            return;
        }
        $c['dirty'] = 1;
        if (empty($c['u']) && $url !== '') {
            $c['u'] = (string) $url;
        }
        update_option($k, $c, false);
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_land_finalize', [(string) $urlKey])) {
            wpc_pl_sched((int) $c['t'] + 240, 'wpc_land_finalize', [(string) $urlKey]);
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('land-coalesced', (string) $urlKey, '', ['ctx' => (string) $context]);
        }
    }
    function wpc_land_finalize_handler($urlKey)
    {
        $k = 'wpc_landcyc_' . substr(md5((string) $urlKey), 0, 16);
        $c = get_option($k);
        delete_option($k);
        if (is_array($c) && !empty($c['dirty'])
            && class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
            wps_ic_cache_integrations::purgeUrlHtml((string) $urlKey, (string) ($c['u'] ?? ''), ['context' => 'land-finalize']);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('land-finalize', (string) $urlKey, '', []);
            }
        }
    }
    add_action('wpc_land_finalize', 'wpc_land_finalize_handler');
}

if (!function_exists('wpc_bg_slot_take')) {
    /** Bounded budget for background helper work so visitor requests always keep pool workers. */
    function wpc_bg_slot_take($name = '')
    {
        try {
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                return false;
            }
            $max = (int) apply_filters('wpc_bg_worker_budget', 1);
            if ($max < 1) {
                return true;
            }
            for ($i = 0; $i < $max; $i++) {
                $k = 'wpc_bgslot_' . $i;
                $v = get_option($k);
                if ($v !== false && (time() - (int) $v) > 180) {
                    delete_option($k);
                    $v = false;
                }
                if ($v === false && add_option($k, time(), '', 'no')) {
                    register_shutdown_function(function () use ($k) {
                        delete_option($k);
                    });
                    return true;
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('bg-budget-deferred', (string) $name, '', []);
            }
        } catch (\Throwable $e) {
            return true;
        }
        return false;
    }
}

if (!function_exists('wpc_font_metrics_unwrap593')) {
    /** Measured per-family fallback rows from the fonts artifact, mapped and range-gated. */
    /**
     * v7.10.593 — read a metrics artifact in either shape, and capture its generation identity.
     *
     * Bare map (today):  { "roboto": {...}, "roboto|500|normal": {...} }
     * Stamped envelope:  { "v":3, "uuid":"…", "tpl_key":"…", "generated_at":…,
     *                      "families": { "roboto": {...} } }
     *
     * Payload key is whichever of families/faces/metrics/fonts holds an array. Envelope scalars
     * are captured, never treated as families. A bare map is returned untouched, so this is a
     * no-op on every artifact that exists today.
     *
     * Identity goes to $GLOBALS['wpc_fm_ident593'] for wpc_font_metrics_gen_check() to compare
     * against the crit dir's landed generation. It is OBSERVABILITY ONLY — a mismatch is logged
     * and nothing is dropped, because a silent drop on a stale pairing would be the same class of
     * bug as the one .592 removed. Act on it only once the field says how often it fires.
     */
    function wpc_font_metrics_unwrap593($j, $urlKey = '')
    {
        $wpc_ident593 = [];
        foreach (['v', 'uuid', 'land_uuid', 'tpl_key', 'generated_at', 'fallback_source'] as $wpc_k593) {
            if (isset($j[$wpc_k593]) && (is_string($j[$wpc_k593]) || is_int($j[$wpc_k593]))) {
                $wpc_ident593[$wpc_k593] = $j[$wpc_k593];
            }
        }
        $wpc_pay593 = null;
        foreach (['families', 'faces', 'metrics', 'fonts'] as $wpc_pk593) {
            if (isset($j[$wpc_pk593]) && is_array($j[$wpc_pk593]) && $j[$wpc_pk593]) {
                $wpc_pay593 = $j[$wpc_pk593];
                break;
            }
        }
        if ($wpc_pay593 === null) {
            // Bare map. Strip any envelope scalars that were stamped alongside the families so a
            // half-stamped artifact cannot be read as a family named "uuid".
            if ($wpc_ident593) {
                foreach (array_keys($wpc_ident593) as $wpc_dk593) {
                    unset($j[$wpc_dk593]);
                }
            }
            $wpc_pay593 = $j;
        }
        if ($wpc_ident593) {
            $GLOBALS['wpc_fm_ident593'] = $wpc_ident593;
            wpc_font_metrics_gen_check($wpc_ident593, (string) $urlKey);
        }
        // v7.10.597 — PER-ROW PROVENANCE IS THE ONE THAT CAN SEE A CARRIED-FORWARD ROW.
        // The service declined the top-level stamp for good reasons — the wire format is a bare
        // array every consumer iterates, wrapping it breaks readers below .593 — and shipped
        // gen_uuid per row instead. That is strictly better here: a single top-level uuid cannot
        // describe an artifact whose merge step can hold rows from two generations, so .593's
        // check would have passed a mixed set as consistent. It also answers the question mtime
        // spread cannot: a row carried forward from an older gen has a FRESH mtime and shows zero
        // spread, which is why the staging mtime evidence could rule out torn writes but not this.
        // function_exists so unwrap593 stays independently testable — the suites slice one
        // function and replay it, and a hard call to a sibling makes that impossible. Same
        // guard as the priority-5 call site for the same reason.
        if (function_exists('wpc_font_metrics_row_gens597')) {
            wpc_font_metrics_row_gens597($wpc_pay593, (string) $urlKey);
        }
        return $wpc_pay593;
    }

    /**
     * v7.10.597 — read gen_uuid off the ROWS and report a mixed-generation artifact.
     * Consumer half of the service's genCensus(). Logs only, never drops: a mixed set is still
     * the best data available, and dropping it would hide the producer-side race that made it.
     */
    function wpc_font_metrics_row_gens597($payload, $urlKey)
    {
        if (!is_array($payload) || !$payload || !apply_filters('wpc_font_metrics_row_gen_check', true)) {
            return;
        }
        try {
            $wpc_gens597 = [];
            $wpc_rows597 = 0;
            $wpc_bare597 = 0;
            foreach ($payload as $wpc_row597) {
                if (!is_array($wpc_row597)) { continue; }
                $wpc_rows597++;
                $wpc_g597 = '';
                foreach (['gen_uuid', 'genUuid', 'gen'] as $wpc_gk597) {
                    if (!empty($wpc_row597[$wpc_gk597]) && is_string($wpc_row597[$wpc_gk597])) {
                        $wpc_g597 = trim($wpc_row597[$wpc_gk597]);
                        break;
                    }
                }
                if ($wpc_g597 === '') { $wpc_bare597++; continue; }
                $wpc_gens597[$wpc_g597] = isset($wpc_gens597[$wpc_g597]) ? $wpc_gens597[$wpc_g597] + 1 : 1;
            }
            // No row carries provenance => pre-.139 artifact. Unknown is not a mismatch.
            if (!$wpc_gens597) {
                return;
            }
            static $wpc_said597 = [];
            if (!empty($wpc_said597[$urlKey]) || !function_exists('wpc_cache_first_log')) {
                return;
            }
            $wpc_said597[$urlKey] = 1;
            $wpc_landed597 = '';
            if ($urlKey !== '' && defined('WPS_IC_CRITICAL')) {
                foreach (['land_uuid.txt', 'uuid.txt'] as $wpc_lf597) {
                    $wpc_p597 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/' . $wpc_lf597;
                    if (@is_readable($wpc_p597)) {
                        $wpc_landed597 = trim((string) @file_get_contents($wpc_p597));
                        if ($wpc_landed597 !== '') { break; }
                    }
                }
            }
            $wpc_keys597 = array_keys($wpc_gens597);
            $wpc_mixed597 = count($wpc_keys597) > 1;
            $wpc_stale597 = ($wpc_landed597 !== '' && !isset($wpc_gens597[$wpc_landed597]));
            if (!$wpc_mixed597 && !$wpc_stale597 && $wpc_bare597 === 0) {
                return;
            }
            wpc_cache_first_log(
                $wpc_mixed597 ? 'font-metrics-rows-mixed-generation' : 'font-metrics-rows-stale-generation',
                (string) $urlKey,
                '',
                [
                    'rows' => $wpc_rows597,
                    'gens' => count($wpc_keys597),
                    'no_gen_rows' => $wpc_bare597,
                    'landed' => substr($wpc_landed597, 0, 40),
                    'row_gens' => implode(',', array_map(function ($wpc_k) {
                        return substr($wpc_k, 0, 12);
                    }, array_slice($wpc_keys597, 0, 4))),
                ]
            );
        } catch (\Throwable $e) {
        }
    }

    /**
     * v7.10.593 — does the metrics artifact belong to the generation the crit dir landed?
     * Logs only. Absent identity on either side says nothing at all: unknown is not a mismatch.
     */
    function wpc_font_metrics_gen_check($ident, $urlKey)
    {
        if (!apply_filters('wpc_font_metrics_gen_check', true) || !is_array($ident) || !$ident) {
            return;
        }
        $wpc_claim593 = '';
        foreach (['land_uuid', 'uuid'] as $wpc_ck593) {
            if (!empty($ident[$wpc_ck593]) && is_string($ident[$wpc_ck593])) {
                $wpc_claim593 = trim($ident[$wpc_ck593]);
                break;
            }
        }
        if ($wpc_claim593 === '' || $urlKey === '' || !defined('WPS_IC_CRITICAL')) {
            return;
        }
        $wpc_d593 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
        $wpc_landed593 = '';
        foreach (['land_uuid.txt', 'uuid.txt'] as $wpc_lf593) {
            if (@is_readable($wpc_d593 . $wpc_lf593)) {
                $wpc_landed593 = trim((string) @file_get_contents($wpc_d593 . $wpc_lf593));
                if ($wpc_landed593 !== '') {
                    break;
                }
            }
        }
        if ($wpc_landed593 === '' || $wpc_landed593 === $wpc_claim593) {
            return;
        }
        static $wpc_said593 = [];
        if (!empty($wpc_said593[$urlKey]) || !function_exists('wpc_cache_first_log')) {
            return;
        }
        $wpc_said593[$urlKey] = 1;
        wpc_cache_first_log('font-metrics-generation-mismatch', $urlKey, '', [
            'artifact_gen' => substr($wpc_claim593, 0, 40),
            'landed_gen' => substr($wpc_landed593, 0, 40),
            'tpl_key' => isset($ident['tpl_key']) ? substr((string) $ident['tpl_key'], 0, 40) : '',
        ]);
    }
}

if (!function_exists('wpc_font_metrics_from_artifact')) {

    function wpc_font_metrics_from_artifact($table)
    {
        // Request-cache lives in $GLOBALS so a fonts/metrics LAND in this same request can
        // bust it (wpc_fallback_restitch) — a function-static primed empty before the land
        // would make the post-land restitch splice against a stale empty table.
        $cached = isset($GLOBALS['wpc_fm_cache159']) ? $GLOBALS['wpc_fm_cache159'] : null;
        if ($cached === null) {
            $cached = [];
            try {
                if (defined('WPS_IC_CRITICAL') && class_exists('wps_ic_url_key') && function_exists('home_url')) {
                    $hk = (new wps_ic_url_key())->setup(home_url('/'));
                    $f = $hk ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $hk . '/font-metrics.json' : '';
                    $j = ($f !== '' && @is_readable($f)) ? json_decode((string) @file_get_contents($f), true) : null;
                    // v7.10.593 — ACCEPT A STAMPED ENVELOPE so the artifact can carry its own
                    // identity. This loop reads every top-level key as a family name, which is
                    // exactly why the service could not add uuid/tpl_key/generated_at without
                    // breaking it: a payload key would be read as a family and its contents as a
                    // metrics row. Tolerating both shapes here means generation identity can live
                    // IN the file instead of in a sibling manifest, and set-consistency becomes
                    // checkable on the render path — the check whose absence made .589 invisible.
                    if (is_array($j)) {
                        $j = wpc_font_metrics_unwrap593($j, $hk);
                    }
                    if (is_array($j)) {
                        $map = ['size_adjust' => 'size-adjust', 'size-adjust' => 'size-adjust', 'sizeAdjust' => 'size-adjust',
                            'ascent_override' => 'ascent-override', 'ascent-override' => 'ascent-override', 'ascent' => 'ascent-override',
                            'descent_override' => 'descent-override', 'descent-override' => 'descent-override', 'descent' => 'descent-override',
                            'line_gap_override' => 'line-gap-override', 'line-gap-override' => 'line-gap-override', 'line_gap' => 'line-gap-override'];
                        $win = ['size-adjust' => [80, 125], 'ascent-override' => [60, 130], 'descent-override' => [3, 60], 'line-gap-override' => [0, 60]];
                        foreach ($j as $fam => $fb) {
                            if (!is_array($fb) || !is_string($fam) || trim($fam) === '') {
                                continue;
                            }
                            $src = strtolower((string) ($fb['sample_source'] ?? ''));
                            // v7.10.731 — estimated rows the generator VALIDATED against the
                            // rendered page (validated:true) are usable pins; unvalidated
                            // estimates stay out.
                            if ($src !== '' && $src !== 'real_glyphs'
                                && !(!empty($fb['validated']) && apply_filters('wpc_font_metrics_estimated', true))) {
                                continue;
                            }
                            $row = [];
                            $ok = true;
                            foreach ($map as $sk => $dk) {
                                if (!isset($fb[$sk]) || isset($row[$dk])) {
                                    continue;
                                }
                                $v = trim((string) $fb[$sk]);
                                if ($v === '') {
                                    continue;
                                }
                                if (substr($v, -1) !== '%') {
                                    $v .= '%';
                                }
                                if (!preg_match('/^[0-9.]+%$/', $v)) {
                                    continue;
                                }
                                $n = (float) $v;
                                if ($n < $win[$dk][0] || $n > $win[$dk][1]) {
                                    $ok = false;
                                    break;
                                }
                                $row[$dk] = $v;
                            }
                            if ($ok && $row) {
                                if (isset($fb['local']) && is_string($fb['local']) && preg_match('/^[A-Za-z ]{3,32}$/', $fb['local'])) {
                                    $row['local'] = $fb['local'];
                                } elseif (isset($fb['fallback']) && is_string($fb['fallback']) && preg_match('/^[A-Za-z ]{3,32}$/', $fb['fallback'])) {
                                    // §3 metrics{} names its measured local face 'fallback'
                                    $row['local'] = $fb['fallback'];
                                }
                                $cached[strtolower(trim($fam))] = $row;
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                $cached = [];
            }
            $GLOBALS['wpc_fm_cache159'] = $cached;
        }
        if (!$cached) {
            return $table;
        }
        return is_array($table) ? array_merge($table, $cached) : $cached;
    }
    add_filter('wpc_font_fallback_metrics', 'wpc_font_metrics_from_artifact');
}

if (!function_exists('wpc_font_metrics_area')) {
    // Inline content area a fallback face will produce, in % of font-size:
    // (ascent + descent + line-gap) x size-adjust. Browser-verified both ways —
    // Roboto 117.2 x 1.001 = 117.3 (measured 117), Circular Std 97.17 x 1.0291 = 100.0
    // (measured 100). Zero means "not computable", never "matches".
    function wpc_font_metrics_area($row)
    {
        if (!is_array($row)) {
            return 0.0;
        }
        $a = isset($row['ascent-override']) ? (float) $row['ascent-override'] : 0.0;
        $d = isset($row['descent-override']) ? (float) $row['descent-override'] : 0.0;
        if ($a <= 0.0 || $d <= 0.0) {
            return 0.0;
        }
        $g = isset($row['line-gap-override']) ? (float) $row['line-gap-override'] : 0.0;
        $s = isset($row['size-adjust']) ? (float) $row['size-adjust'] : 100.0;
        if ($s <= 0.0) {
            $s = 100.0;
        }
        return ($a + $d + $g) * ($s / 100.0);
    }
}

if (!function_exists('wpc_font_metrics_consistency_gate')) {
    // v7.10.592 — PREMISE INVERTED. This gate was written when a per-weight row disagreeing with
    // the family row was read as evidence the row was wrong. It is the opposite: a real per-weight
    // measurement MUST differ from the family value, because different weights have different
    // metrics. That is the entire point of the service's per-face work, and at a 10% band this
    // gate would have started dropping those rows at exactly the moment they became correct —
    // silently, leaving the browser on the family value while the service artifact said per_face.
    // A silent fail-open is indistinguishable from "the fix does not work" from the other side.
    //
    // What it now rejects is IMPLAUSIBILITY, not disagreement. Band sized off the WITHIN-family
    // spread, which is what a per-weight row can legitimately move: Circular Std measures
    // 102.0 / 103.9 / 105.5 across w400/500/600, a 3.4% spread. (The ~7% figure this comment
    // carried was a spread ACROSS families — Roboto vs Circular Std — which says nothing about
    // how far one family's weights may diverge.) 35% is clear of 3.4% by an order of magnitude
    // and still catches gross corruption: wrong family, unit error, doubled box.
    //
    // v7.10.595 — AND THIS GATE IS STRUCTURALLY BLIND TO THE FAILURE THAT ACTUALLY HAPPENS.
    // Content area is very nearly INVARIANT across weights, because ascent+descent and
    // size-adjust are inversely coupled: Circular Std's three faces compute to 126.48 / 126.45
    // / 126.49, a spread of 0.04%. So an area comparison can neither reject a wrong row nor
    // notice a COLLAPSED one — three weights carrying one measurement pass at ~0.1% delta,
    // which is precisely the live defect. The quantity that varies is size-adjust. Collapse
    // detection therefore lives in wpc_font_metrics_collapse595(), on size-adjust, and reports
    // rather than drops: a normalisation is a producer bug and dropping the rows would hide it.
    // Rows kept past the OLD band are receipted so the service side can confirm the plugin
    // honoured the value it shipped. Still fails OPEN: no family row, or either area not
    // computable, leaves the table untouched.
    function wpc_font_metrics_consistency_gate($table)
    {
        if (!is_array($table) || !$table) {
            return $table;
        }
        try {
            $fam = [];
            foreach ($table as $k => $v) {
                if (is_string($k) && $k !== '' && strpos($k, '|') === false) {
                    $fam[$k] = wpc_font_metrics_area($v);
                }
            }
            if (!$fam) {
                return $table;
            }
            $tol = (float) apply_filters('wpc_font_metrics_area_tolerance', 35.0);
            if ($tol <= 0.0) {
                return $table;
            }
            // The old default. Rows between this and $tol are the ones .591 and earlier dropped;
            // they are now KEPT and receipted, because that band is where a genuine per-weight
            // measurement lands once the service measures faces rather than families.
            $wpc_prev592 = (float) apply_filters('wpc_font_metrics_area_receipt_from', 10.0);
            static $wpc_seen592 = [];
            foreach ($table as $k => $v) {
                if (!is_string($k) || ($pos = strpos($k, '|')) === false || $pos === 0) {
                    continue;
                }
                $base = substr($k, 0, $pos);
                if (!isset($fam[$base]) || $fam[$base] <= 0.0) {
                    continue;
                }
                $pa = wpc_font_metrics_area($v);
                if ($pa <= 0.0) {
                    continue;
                }
                $delta = abs($pa - $fam[$base]) / $fam[$base] * 100.0;
                if ($delta <= $tol) {
                    // KEPT. Receipt only the band that used to be dropped, once per key per
                    // request — this is the line that tells the service side its per-weight
                    // value actually reached the browser instead of being silently replaced.
                    if ($delta > $wpc_prev592 && empty($wpc_seen592[$k])
                        && function_exists('wpc_cache_first_log')) {
                        $wpc_seen592[$k] = 1;
                        wpc_cache_first_log('font-metrics-per-weight-kept', (string) $k, '', [
                            'row_area' => round($pa, 2),
                            'family_area' => round($fam[$base], 2),
                            'delta_pct' => round($delta, 1),
                            'band' => round($wpc_prev592, 1) . '-' . round($tol, 1),
                        ]);
                    }
                    continue;
                }
                unset($table[$k]);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('font-metrics-implausible', (string) $k, '', [
                        'row_area' => round($pa, 2),
                        'family_area' => round($fam[$base], 2),
                        'delta_pct' => round($delta, 1),
                        'band_pct' => round($tol, 1),
                    ]);
                }
            }
        } catch (\Throwable $e) {
        }
        return $table;
    }
    add_filter('wpc_font_fallback_metrics', 'wpc_font_metrics_consistency_gate', 99);
}

if (!function_exists('wpc_font_metrics_collapse595')) {
    /**
     * v7.10.595 — DID THE PRODUCER MEASURE THREE WEIGHTS, OR NORMALISE ONE VALUE ACROSS THREE?
     *
     * A family whose per-weight rows all carry the SAME size-adjust has not been measured
     * per-weight; it has been given one number three times. Same shape as the .555 bug where
     * three faces landed on exactly 1.000em, and the same law: identical values across inputs
     * that genuinely differ is a normalisation, not a measurement.
     *
     * size-adjust is the field to test. Content area cannot see this — ascent+descent and
     * size-adjust are inversely coupled, so a collapsed family's areas agree to ~0.04% and pass
     * every area band ever chosen. Real within-family spread is ~3.4%.
     *
     * Reports only, never drops: a collapse is the producer's bug and the rows are still the
     * best available. Dropping them would hide the very thing this exists to surface. Also logs
     * healthy spreads once, so a consumer can prove it received per-weight values rather than
     * inferring it from a rendered page.
     */
    function wpc_font_metrics_collapse595($table)
    {
        if (!is_array($table) || !$table || !apply_filters('wpc_font_metrics_collapse_check', true)) {
            return $table;
        }
        try {
            $wpc_by595 = [];
            foreach ($table as $wpc_k595 => $wpc_v595) {
                if (!is_string($wpc_k595) || !is_array($wpc_v595)) { continue; }
                $wpc_pos595 = strpos($wpc_k595, '|');
                if ($wpc_pos595 === false || $wpc_pos595 === 0) { continue; }
                if (!isset($wpc_v595['size-adjust'])) { continue; }
                $wpc_sa595 = (float) $wpc_v595['size-adjust'];
                if ($wpc_sa595 <= 0.0) { continue; }
                $wpc_by595[substr($wpc_k595, 0, $wpc_pos595)][$wpc_k595] = $wpc_sa595;
            }
            static $wpc_said595 = [];
            foreach ($wpc_by595 as $wpc_fam595 => $wpc_rows595) {
                if (count($wpc_rows595) < 2 || !empty($wpc_said595[$wpc_fam595])) { continue; }
                $wpc_said595[$wpc_fam595] = 1;
                $wpc_vals595 = array_values($wpc_rows595);
                $wpc_lo595 = min($wpc_vals595);
                $wpc_hi595 = max($wpc_vals595);
                $wpc_spread595 = ($wpc_lo595 > 0.0) ? (($wpc_hi595 - $wpc_lo595) / $wpc_lo595 * 100.0) : 0.0;
                $wpc_floor595 = (float) apply_filters('wpc_font_metrics_collapse_floor', 0.05);
                if (!function_exists('wpc_cache_first_log')) { continue; }
                wpc_cache_first_log(
                    $wpc_spread595 <= $wpc_floor595 ? 'font-metrics-collapsed' : 'font-metrics-per-weight-spread',
                    (string) $wpc_fam595,
                    '',
                    [
                        'weights' => count($wpc_rows595),
                        'sa_min' => round($wpc_lo595, 2),
                        'sa_max' => round($wpc_hi595, 2),
                        'spread_pct' => round($wpc_spread595, 3),
                        'keys' => implode(',', array_slice(array_keys($wpc_rows595), 0, 6)),
                    ]
                );
            }
        } catch (\Throwable $e) {
        }
        return $table;
    }
    // After the consistency gate (99) so it observes the final table the consumer will emit.
    add_filter('wpc_font_fallback_metrics', 'wpc_font_metrics_collapse595', 100);
}

if (!function_exists('wpc_used_css_arm_reset')) {
    function wpc_used_css_arm_reset()
    {
        try {
            if (!defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
                return;
            }
            $hk = (new wps_ic_url_key())->setup(home_url('/'));
            if (!$hk) {
                return;
            }
            $tk = trim((string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $hk . '/tpl.txt'));
            $bk = $tk !== '' ? $tk : 'home:' . $hk;
            delete_transient('wpc_ucss_arm_' . md5($bk));
            $tries = (array) get_option('wpc_ucss_arm_tries', []);
            if (isset($tries[$bk])) {
                unset($tries[$bk]);
                update_option('wpc_ucss_arm_tries', $tries, false);
            }
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('wpc_used_css_self_arm')) {
    function wpc_used_css_self_arm()
    {
        try {
            $set = get_option(WPS_IC_SETTINGS);
            if (!is_array($set) || empty($set['used-css']) || $set['used-css'] != '1') {
                return;
            }
            if (!defined('WPS_IC_CRITICAL') || !function_exists('wpc_used_css_path')) {
                return;
            }
            if (!class_exists('wps_ic_url_key')) {
                return;
            }
            $hk = (new wps_ic_url_key())->setup(home_url('/'));
            if (!$hk) {
                return;
            }
            $tk = trim((string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $hk . '/tpl.txt'));
            $ap = $tk !== '' ? wpc_used_css_path($tk) : '';
            if ($tk !== '' && $ap !== '' && @is_readable($ap) && @filesize($ap) > 64) {
                return;
            }


            // Bounds key falls back to the home url-key when tpl is unknown.
            $wpc_bk139 = $tk !== '' ? $tk : 'home:' . $hk;


            $wpc_pending52 = get_transient('wpc_critical_uuid_' . $hk);
            if (!$wpc_pending52 && get_transient('wpc_ucss_arm_' . md5($wpc_bk139))) {
                return;
            }
            $tries = (array) get_option('wpc_ucss_arm_tries', []);
            if ((int) ($tries[$wpc_bk139] ?? 0) >= 5) {
                return;
            }
            set_transient('wpc_ucss_arm_' . md5($wpc_bk139), 1, 6 * HOUR_IN_SECONDS);
            $tries[$wpc_bk139] = (int) ($tries[$wpc_bk139] ?? 0) + 1;
            update_option('wpc_ucss_arm_tries', $tries, false);
            if (!class_exists('wps_criticalCss') && defined('WPS_IC_DIR')) {
                @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
            }
            if (class_exists('wps_criticalCss')) {
                $c = new wps_criticalCss();
                $c->initCritical('home', home_url('/'), $hk, 'meta');
            }
        } catch (\Throwable $e) {

        }
    }
    add_action('admin_init', 'wpc_used_css_self_arm', 30);
    add_action('admin_init', 'wpc_used_css_disk_resanitize', 31);
    add_action('wpc_lcp_repull', 'wpc_used_css_self_arm', 5);
}


// Pre-envelope combine-lane gens landed WITHOUT delay.json, so delay-v3 delays render-critical


if (!function_exists('wpc_delay_manifest_self_heal')) {
    function wpc_delay_manifest_self_heal()
    {
        try {
            if (!defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
                return;
            }
            $s = get_option(WPS_IC_SETTINGS);
            if (!is_array($s) || empty($s['delay-js-v2']) || $s['delay-js-v2'] != '1'
                || (isset($s['delay-js-v3']) && $s['delay-js-v3'] == '0')) {
                return;
            }
            if (!apply_filters('wpc_delay_manifest_self_heal', true)) {
                return;
            }
            if (get_transient('wpc_manifest_heal_lock')) {
                return;
            }
            $tries = (array) get_option('wpc_manifest_heal_tries', []);
            $target_key = '';
            $target_url = '';
            foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/critical_desktop.css') as $wpc_cc108) {
                $dir = dirname($wpc_cc108);
                if (@file_exists($dir . '/delay.json')) {
                    continue;
                }
                $key = basename($dir);
                if ((int) ($tries[$key] ?? 0) >= 3) {
                    continue;
                }
                if (get_transient('wpc_critical_uuid_' . $key)) {
                    continue;
                }
                $url = method_exists('wps_ic_url_key', 'getUrlFromKey') ? (string) wps_ic_url_key::getUrlFromKey($key) : '';
                if ($url === '') {
                    continue;
                }
                $target_key = $key;
                $target_url = $url;
                break;
            }
            if ($target_key === '') {
                return;
            }
            set_transient('wpc_manifest_heal_lock', 1, HOUR_IN_SECONDS);
            $tries[$target_key] = (int) ($tries[$target_key] ?? 0) + 1;
            update_option('wpc_manifest_heal_tries', $tries, false);
            if (!class_exists('wps_criticalCss') && defined('WPS_IC_DIR')) {
                @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
            }
            if (class_exists('wps_criticalCss')) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('manifest-heal-dispatch', $target_key, $target_url, ['try' => (int) $tries[$target_key]]);
                }
                (new wps_criticalCss())->initCritical(0, $target_url, $target_key, 'meta');
            }
        } catch (\Throwable $e) {

        }
    }
    add_action('admin_init', 'wpc_delay_manifest_self_heal', 32);
    add_action('wpc_lcp_repull', 'wpc_delay_manifest_self_heal', 6);
}


if (!function_exists('wpc_crit_land_self_heal')) {
    function wpc_crit_land_self_heal()
    {
        try {
            if (!defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
                return;
            }
            // The feature toggle gates the healer like every other crit lane: with
            // critical.css off, a missing artifact is the CONFIGURED state, not a wound.
            $wpc_set845 = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : false;
            if (!is_array($wpc_set845) || empty($wpc_set845['critical']['css']) || (string) $wpc_set845['critical']['css'] !== '1') {
                return;
            }
            if (get_transient('wpc_landheal_lock')) {
                return;
            }
            $tries = (array) get_option('wpc_landheal_tries', []);
            $target_key = '';
            $target_url = '';
            foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/uuid.txt') as $wpc_uf113) {
                $dir = dirname($wpc_uf113);
                if (@file_exists($dir . '/critical_desktop.css')) {
                    continue;
                }
                if (time() - (int) @filemtime($wpc_uf113) < 180) {
                    continue;
                }
                $key = basename($dir);
                if ((int) ($tries[$key] ?? 0) >= 5) {
                    continue;
                }
                $url = method_exists('wps_ic_url_key', 'getUrlFromKey') ? (string) wps_ic_url_key::getUrlFromKey($key) : '';
                if ($url === '') {
                    continue;
                }
                $target_key = $key;
                $target_url = $url;
                break;
            }
            if ($target_key === '') {
                return;
            }
            set_transient('wpc_landheal_lock', 1, 15 * MINUTE_IN_SECONDS);
            $tries[$target_key] = (int) ($tries[$target_key] ?? 0) + 1;
            update_option('wpc_landheal_tries', $tries, false);
            if (!class_exists('wps_criticalCss') && defined('WPS_IC_DIR')) {
                @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
            }
            if (class_exists('wps_criticalCss')) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('crit-land-heal', $target_key, $target_url, ['try' => (int) $tries[$target_key]]);
                }
                (new wps_criticalCss())->initCritical(0, $target_url, $target_key, 'meta');
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_crit_land_self_heal', 34);
    add_action('wpc_lcp_repull', 'wpc_crit_land_self_heal', 7);
}


// f056962…woff2). Run it once per plugin version; the function honors the 'off' opt-out and
// now sweeps unmapped cache files too.
if (!function_exists('wpc_fontdisplay_rebake_once')) {
    function wpc_fontdisplay_rebake_once()
    {
        try {
            $v = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '0';
            if (get_option('wpc_fd_rebake_v') === $v) {
                return;
            }
            if (!class_exists('wps_ic_fonts') && defined('WPS_IC_DIR')) {
                @include_once WPS_IC_DIR . 'addons/fonts/fonts.class.php';
            }
            if (!class_exists('wps_ic_fonts')) {
                return;
            }
            $f = new wps_ic_fonts();
            if (!method_exists($f, 'rebakeFontDisplay')) {
                return;
            }


            $wpc_n115 = (int) $f->rebakeFontDisplay();
            update_option('wpc_fd_rebake_v', $v, false);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('fd-rebake', '', '', ['baked' => $wpc_n115]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_fontdisplay_rebake_once', 33);
}


if (!function_exists('wpc_font_display_effective')) {
    // 'smart' resolves per-site: optional only when the deterministic metrics verdict
    // landed (matched twin proven from the woff2 tables) — zero-CLS is then free; else swap.
    function wpc_font_display_effective($raw, $family = '')
    {
        $raw = strtolower(trim((string) $raw));
        // v7.10.401: 'smart' has always resolved to optional once metrics are validated.
        // Extend that to plain 'swap': a swap with a VALIDATED metrics-matched fallback still
        // swap-reflows on every load for zero benefit (the fallback is metrics-identical), and
        // that reflow is the residual CLS on Divi headings (busy desktop 0.131 -> ~0). Only
        // upgrades when metrics are proven (trustworthy fallback); un-validated swap is left
        // alone (show the real font). Filtered so an explicit swap preference can be restored.
        if ($raw === 'smart' || ($raw === 'swap' && apply_filters('wpc_fd_swap_to_optional_when_validated', true))) {
            // A trustworthy fallback exists when the SERVICE validated it (strict) OR the plugin
            // computed font-metrics for the site and baked size-adjust fallbacks (present). Either
            // makes the fallback metric-matched, so optional is safe and kills the swap-CLS.
            $v = get_option('wpc_font_metrics_validated');
            // v7.10.483 — PER-FAMILY, not site-wide. 'optional' is safe only when THIS family has a
            // metric-matched fallback; the guard read only $v['t'], a site-wide boolean, so one
            // family with metrics promoted EVERY face to optional. zinsenvergleich receipt: two
            // Astra faces, size-adjust=(NONE) and ascent-override=(NONE) on both, one emitted as
            // optional — and Astra is still fetched over the network, so that glyph may simply
            // never paint. That is the .443 trade (invisible icon beats no byte win) arriving by a
            // different route. The per-family list was already being written into $v['fams'] and
            // simply never read.
            // No family supplied => unchanged site-wide behaviour, so no caller regresses.
            $wpc_fam483 = strtolower(trim((string) $family, " \t\"'"));
            if ($wpc_fam483 !== '') {
                $wpc_fams483 = (is_array($v) && !empty($v['fams']) && is_array($v['fams']))
                    ? array_map('strtolower', $v['fams']) : [];
                // Unknown family => swap. The font renders and may reflow; optional risks never
                // rendering at all. Reflow is recoverable, invisible text is not.
                return in_array($wpc_fam483, $wpc_fams483, true) ? 'optional' : 'swap';
            }
            $ok = (is_array($v) && !empty($v['t'])) || (int) get_option('wpc_font_metrics_present', 0) === 1;
            return $ok ? 'optional' : 'swap';
        }
        return $raw;
    }
}

if (!function_exists('wpc_fd_auto_migrate')) {
    function wpc_fd_auto_migrate()
    {
        try {
            if (get_option('wpc_fd_auto_migr') === '1' || !apply_filters('wpc_fd_auto_to_swap', true)) {
                return;
            }
            $set = get_option(WPS_IC_SETTINGS);
            if (!is_array($set) || empty($set['font-display']) || strtolower((string) $set['font-display']) !== 'auto') {
                update_option('wpc_fd_auto_migr', '1', false);
                return;
            }
            $set['font-display'] = 'swap';
            update_option(WPS_IC_SETTINGS, $set);
            update_option('wpc_fd_auto_migr', '1', false);
            delete_option('wpc_fd_rebake_v');
            if (function_exists('wpc_fontdisplay_rebake_once')) {
                wpc_fontdisplay_rebake_once();
            }
            if (function_exists('wpc_r2_purge_html_layers')) {
                wpc_r2_purge_html_layers();
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('fd-auto-migrated', '', '', ['to' => 'swap']);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_fd_auto_migrate', 32);
}


if (!function_exists('wpc_compute_tpl_key')) {

    function wpc_compute_tpl_key()
    {
        try {
            $h = [];
            if (!empty($GLOBALS['wp_styles']) && !empty($GLOBALS['wp_styles']->done)) {
                foreach ((array) $GLOBALS['wp_styles']->done as $handle) {
                    $reg = isset($GLOBALS['wp_styles']->registered[$handle]) ? $GLOBALS['wp_styles']->registered[$handle] : null;
                    $wpc_v626 = ($reg && !empty($reg->ver)) ? (string) $reg->ver : '0';
                    // v7.10.626 — A BUILD STAMP IS NOT A TEMPLATE VERSION. Elementor
                    // enqueues its per-post CSS with ver = the file's mtime
                    // (post-6912.css?ver=1785456828), so every CSS rebuild minted a brand
                    // new tpl_key: the service saw an endless stream of "new" templates,
                    // its 3-template observation budget filled, and 7 of 10 staging
                    // templates could not refresh their used-css since July (their trace,
                    // 2026-07-31: /affiliates/ minted two keys six minutes apart). The key
                    // names WHICH sheets compose this template; a 10-digit unix timestamp
                    // says only WHEN one was written. Real release versions (1.2.3, theme
                    // versions) still participate.
                    if (preg_match('/^\d{9,11}$/', $wpc_v626)
                        && (int) $wpc_v626 > 946684800 && (int) $wpc_v626 < 4102444800) {
                        $wpc_v626 = 'b';
                    }
                    $h[] = $handle . ':' . $wpc_v626;
                }
            }
            sort($h);
            $theme = function_exists('wp_get_theme') ? wp_get_theme() : null;
            $sig = (function_exists('get_template') ? get_template() : '') . '|'
                 . ($theme ? $theme->get('Version') : '') . '|'
                 . (!empty($GLOBALS['template']) ? basename((string) $GLOBALS['template']) : '') . '|'
                 . implode(',', $h);
            return 'tpl:' . substr(sha1($sig), 0, 16);
        } catch (\Throwable $e) {
            return '';
        }
    }


    function wpc_dispatch_tpl_key($urlKey)
    {
        if (!defined('WPS_IC_CRITICAL') || empty($urlKey)) {
            return '';
        }
        $tk = trim((string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/tpl.txt'));
        if ($tk === '') {
            return '';
        }
        if (function_exists('wpc_used_css_key_valid')) {
            return wpc_used_css_key_valid($tk) ? $tk : '';
        }
        return preg_match('/^tpl:[a-f0-9]{6,}$/', $tk) ? $tk : '';
    }


    function wpc_dispatch_post_mtime($postID, $url)
    {
        if (!function_exists('get_post_modified_time')) {
            return '';
        }
        // Homepage / front page → dynamic ATF, no reliable content-version → omit.
        if (function_exists('home_url') && rtrim((string) $url, '/') === rtrim((string) home_url('/'), '/')) {
            return '';
        }
        $pid = (is_numeric($postID) && (int) $postID > 0) ? (int) $postID : 0;
        if ($pid === 0 && function_exists('url_to_postid')) {
            $pid = (int) url_to_postid((string) $url); // 0 for archives / taxonomies / search → omit
        }
        if ($pid <= 0) {
            return '';
        }
        $mt = get_post_modified_time('U', true, $pid); // GMT unix
        return ($mt && function_exists('get_post_status') && get_post_status($pid) === 'publish') ? (string) $mt : '';
    }
}


if (!function_exists('wpc_land_second_wave')) {
    function wpc_land_second_wave($url)
    {
        try {
            if (!class_exists('wps_ic_url_key') || !method_exists('wps_ic_url_key', 'sanitizeSameHostUrl')) {
                return;
            }
            $clean = wps_ic_url_key::sanitizeSameHostUrl((string) $url);
            if ($clean === '') {
                return;
            }

            // 1. External re-purge (same primitives purgeUrlHtml uses; each self-gates/no-ops).
            if (class_exists('wps_ic_cache_integrations')) {
                try {
                    if (method_exists('wps_ic_cache_integrations', 'purgeVarnish')) {
                        wps_ic_cache_integrations::purgeVarnish(0, false, $clean);
                    }
                } catch (\Throwable $e) {
                }
            }
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeEdgeHtmlUrls')) {
                try {
                    $forms   = [$clean];
                    $forms[] = (substr($clean, -1) === '/') ? rtrim($clean, '/') : $clean . '/';
                    wps_ic_cache::purgeEdgeHtmlUrls(array_filter($forms));
                } catch (\Throwable $e) {
                }
            }


            $key = (new wps_ic_url_key())->setup($clean);
            if (empty($key) || !defined('WPS_IC_CACHE')) {
                return;
            }
            $dir = rtrim(WPS_IC_CACHE, '/') . '/' . $key . '/';
            if (apply_filters('wpc_bypass_freeze_fallback', true)) {
                $newest = 0;
                foreach (['index.html_gzip', 'webp_index.html_gzip', 'mobile_index.html_gzip', 'mobile-webp_index.html_gzip'] as $vf) {
                    $mt = @filemtime($dir . $vf);
                    if ($mt && $mt > $newest) {
                        $newest = $mt;
                    }
                }
                if ($newest > 0 && (time() - $newest) > 600) {
                    if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeCacheFiles')) {
                        wps_ic_cache_integrations::purgeCacheFiles($key);
                    }
                    if (class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'removeStaticMirror')) {
                        wps_cacheHtml::removeStaticMirror($clean);
                    }
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('bypass-failed-fallback', $key, $clean, ['age' => time() - $newest]);
                    }
                    return;
                }
            }

            // 2. Edge pre-warm from the ARMED page (our own cache file — no origin request needed).
            if (!apply_filters('wpc_land_asset_prewarm', true) || !function_exists('wp_remote_get')) {
                return;
            }
            $html = '';
            foreach (['webp_index.html_gzip', 'index.html_gzip'] as $f) {
                if (@is_readable($dir . $f) && function_exists('gzdecode')) {
                    $raw = @file_get_contents($dir . $f);
                    if ($raw !== false) {
                        $html = (string) @gzdecode($raw);
                    }
                    break;
                }
            }
            if ($html === '') {
                return;
            }
            $targets = [];

            if (preg_match('/<link[^>]*id="wpc-lcp-bg-preload"[^>]*href="([^"]+)"/i', $html, $m)
                || preg_match('/<link[^>]*href="([^"]+)"[^>]*id="wpc-lcp-bg-preload"/i', $html, $m)) {
                $targets[] = $m[1];
            }

            if (preg_match_all('/<link[^>]*rel="preload"[^>]*as="font"[^>]*href="([^"]+)"/i', $html, $mm)) {
                $targets = array_merge($targets, array_slice($mm[1], 0, 4));
            }
            // v7.10.689 — font preloads are post-paint injected now (data-wpc-fpb script,
            // JSON [[url,mime],...]); warm those URLs exactly like the old static tags.
            if (preg_match('/<script[^>]*data-wpc-fpb="1"[^>]*>\(function\(\)\{var u=(\[\[.*?\]\]);/s', $html, $m689)) {
                $wpc_fpj689 = json_decode(str_replace('<\/', '</', (string) $m689[1]), true);
                if (is_array($wpc_fpj689)) {
                    foreach (array_slice($wpc_fpj689, 0, 4) as $wpc_fpe689) {
                        if (is_array($wpc_fpe689) && !empty($wpc_fpe689[0])) {
                            $targets[] = (string) $wpc_fpe689[0];
                        }
                    }
                }
            }
            if (preg_match('/<link[^>]*id="wpc-used-css"[^>]*href="([^"]+)"/i', $html, $m)) {
                $targets[] = $m[1];
            }

            if (preg_match_all('/<link[^>]*type=["\'](?:wpc-stylesheet|wpc-mobile-stylesheet)["\'][^>]*href="([^"]+)"/i', $html, $mm)) {
                $targets = array_merge($targets, array_slice($mm[1], 0, 3));
            }

            $home_host = (string) parse_url(home_url(), PHP_URL_HOST);
            $fired     = 0;
            foreach (array_unique($targets) as $t) {
                if ($fired >= 8) {
                    break;
                }
                $t = html_entity_decode((string) $t);
                $h = (string) parse_url($t, PHP_URL_HOST);
                if ($h === '' || ($h !== $home_host && stripos($h, 'zapwp.com') === false
                    && !apply_filters('wpc_land_prewarm_host_ok', false, $h))) {
                    continue;
                }
                wp_remote_get($t, ['blocking' => false, 'timeout' => 3, 'redirection' => 2]);
                $fired++;
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('second-wave', $key, $clean, ['prewarmed' => $fired]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_land_second_wave', 'wpc_land_second_wave', 10, 1);
}


if (!function_exists('wpc_crit_mark_stale_instead')) {
    function wpc_crit_mark_stale_instead($url_key = '')
    {
        try {
            if (!apply_filters('wpc_overwrite_only_store', true)) {
                return false;
            }
            $k = is_string($url_key) && $url_key !== '' ? $url_key : 'all';
            set_transient('wpc_crit_stale_' . md5($k), time(), 6 * HOUR_IN_SECONDS);


            if (function_exists('wpc_repull_kick_now')) {
                wpc_repull_kick_now(($k === 'all' || $k === 'upgrade') ? '' : $k);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-stale-mark', $k, '', []);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}


// The manual purge button was the last delete-first path: it wiped the store and rebuilt in


if (!function_exists('wpc_crit_soft_purge_all')) {
    function wpc_crit_soft_purge_all()
    {
        $n = 0;
        try {
            if (!defined('WPS_IC_CRITICAL')) {
                return 0;
            }
            $root = rtrim(WPS_IC_CRITICAL, '/');
            $items = @scandir($root);
            if (!is_array($items)) {
                return 0;
            }
            foreach ($items as $it) {
                if ($it === '.' || $it === '..' || $it === 'used-css') {
                    continue;
                }
                $d = $root . '/' . $it;
                if (!is_dir($d)) {
                    continue;
                }
                if (wpc_crit_meta_write($d . '/stale.txt', (string) time()) !== false) {
                    $n++;
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-soft-purge', 'all', '', ['n' => $n]);
            }
        } catch (\Throwable $e) {
        }
        return $n;
    }
}

if (!function_exists('wpc_delay_manifest_reset')) {
    /**
     * Delete the JS delay manifest and its sticky option state (v7.10.519).
     *
     * Reported by Denis and confirmed: NOTHING deleted delay.json. The only unlink in the
     * tree is delay_url.txt (the POINTER), dropped on every crit land while the body was
     * kept — so a fresh generation got a fresh pointer and the old manifest. And because
     * an overwrite requires the replacement to already be measured-shape ("never regress"),
     * a wrong manifest was immortal: no button could clear it.
     *
     * Worse, two of the option lanes had writers and ZERO deleters — wpc_presc_pins (2
     * writers) and wpc_delay_v3_jqneed (1 writer). A stale pin can hold one script eager
     * while its dependency stays delayed, which tears the chain and breaks JS with no way
     * out. Same class as every other bug this release: state with a writer and no deleter.
     *
     * Deleting the manifest does NOT regress behaviour — it drops to the unmeasured lane
     * until a new one lands, and since .512 the unmeasured defer path is order-safe.
     */
    function wpc_delay_manifest_reset($ctx = '')
    {
        $wpc_f519 = 0;
        $wpc_o519 = 0;
        try {
            if (defined('WPS_IC_CRITICAL')) {
                $wpc_r519 = rtrim(WPS_IC_CRITICAL, '/');
                $wpc_i519 = @scandir($wpc_r519);
                if (is_array($wpc_i519)) {
                    foreach ($wpc_i519 as $wpc_e519) {
                        if ($wpc_e519 === '.' || $wpc_e519 === '..' || $wpc_e519 === 'used-css') {
                            continue;
                        }
                        $wpc_d519 = $wpc_r519 . '/' . $wpc_e519;
                        if (!@is_dir($wpc_d519)) {
                            continue;
                        }
                        // Pointer AND body together — dropping only one is what created the
                        // fresh-pointer/stale-body split in the first place.
                        foreach (['delay.json', 'delay_url.txt'] as $wpc_n519) {
                            if (@is_file($wpc_d519 . '/' . $wpc_n519) && @unlink($wpc_d519 . '/' . $wpc_n519)) {
                                $wpc_f519++;
                            }
                        }
                    }
                }
            }
            // Global (not per-key) lanes that outlived every file purge.
            foreach ([
                'wpc_delay_v3_manifest_off', 'wpc_delay_aggr_off', 'wpc_delay_v3_promoted',
                'wpc_presc_pins', 'wpc_delay_v3_jqneed',
            ] as $wpc_k519) {
                if (get_option($wpc_k519) !== false) {
                    delete_option($wpc_k519);
                    $wpc_o519++;
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('delay-manifest-reset', '', '', [
                    'ctx' => (string) $ctx, 'files' => $wpc_f519, 'options' => $wpc_o519,
                ]);
            }
        } catch (\Throwable $e) {
        }
        return ['files' => $wpc_f519, 'options' => $wpc_o519];
    }
}

if (!function_exists('wpc_crit_bypass_active')) {
    /**
     * v7.10.391 zero-dark purge. Hard purge writes bypass.txt instead of deleting artifacts:
     * while active, STALE artifacts resolve as absent (pages render critless but CACHEABLE —
     * no layer is ever cold + forbidden to cache at once, the exact interaction behind the
     * post-purge 60s outages). An artifact fresher than the window start lifts the bypass
     * for its URL the moment it lands. File-based (stale.txt idiom): zero DB cost per render.
     */
    function wpc_crit_bypass_start()
    {
        if (!defined('WPS_IC_CRITICAL') || !function_exists('wpc_crit_meta_write')) { return false; }
        return wpc_crit_meta_write(rtrim(WPS_IC_CRITICAL, '/') . '/bypass.txt', (string) time()) !== false;
    }
    /**
     * v7.10.825 — per-URL window for Rebuild This Page: the clicked page stops serving its
     * suspect crit IMMEDIATELY (renders with full theme CSS, cacheable) instead of serving it
     * for the whole gen window. Same laws as the global window: land lifts it, orphan lifts it,
     * the 1800s ceiling bounds it.
     */
    function wpc_crit_bypass_page_start($key)
    {
        if (!defined('WPS_IC_CRITICAL') || !function_exists('wpc_crit_meta_write')) { return false; }
        $key = basename((string) $key);
        if ($key === '') { return false; }
        return wpc_crit_meta_write(rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/bypass.txt', (string) time()) !== false;
    }
    function wpc_crit_bypass_active($key)
    {
        if (!defined('WPS_IC_CRITICAL')) { return false; }
        $key = basename((string) $key);
        $wpc_root825 = rtrim(WPS_IC_CRITICAL, '/');
        $bf = $wpc_root825 . '/bypass.txt';
        $t = (int) @file_get_contents($bf);
        // .825: no global window -> consult this key's own window (Rebuild This Page).
        if ($t <= 0 && $key !== '') {
            $bf = $wpc_root825 . '/' . $key . '/bypass.txt';
            $t = (int) @file_get_contents($bf);
        }
        if ($t <= 0) { return false; }
        if ((time() - $t) > (int) apply_filters('wpc_crit_bypass_max_s', 1800)) {
            @unlink($bf);
            return false;
        }
        if ($key === '') { return false; }
        // v7.10.690 — A DEFERRAL IS A PROMISE SOMETHING WILL UNDO IT. The window's undo is a
        // fresh land; its trigger is the purge-side redispatch. If NOTHING has dispatched for
        // this key since the window armed and the ≤60s land contract's budget is long spent,
        // the promise is broken: lift and serve the existing crit (soft-purge semantics)
        // instead of riding the 1800s ceiling critless. Keys whose regen DID dispatch keep the
        // window (dispatch_ts >= arm) and lift per-URL the moment their land arrives.
        if ((time() - $t) > (int) apply_filters('wpc_crit_bypass_orphan_s', 240)) {
            $wpc_dts690 = (int) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/dispatch_ts.txt');
            if ($wpc_dts690 < $t) {
                @unlink($bf);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('bypass-orphan-lift', $key, '', ['open_s' => time() - $t]);
                }
                return false;
            }
        }
        $m = (int) @filemtime(rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/critical_desktop.css');
        // missing artifact = the ordinary critless path owns it; fresher-than-window = landed, lift
        return $m > 0 && $m <= $t;
    }
}

if (!function_exists('wpc_repull_kick_now')) {


    function wpc_kick_lockfile($urlKey, $scope = 'recv')
    {
        if (!defined('WPS_IC_CRITICAL') || (string) $urlKey === '') { return ''; }
        $sc = ($scope === 'fire') ? 'f_' : 'r_';
        return rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/' . $sc . md5((string) $urlKey) . '.lock';
    }
    function wpc_kick_recent($urlKey, $ttl, $scope = 'recv')
    {
        $f = wpc_kick_lockfile($urlKey, $scope);
        return $f !== '' && @is_file($f) && (time() - (int) @filemtime($f)) < (int) $ttl;
    }
    function wpc_kick_stamp($urlKey, $scope = 'recv')
    {
        $f = wpc_kick_lockfile($urlKey, $scope);
        if ($f === '') { return; }
        $d = dirname($f);
        if (!is_dir($d)) { @mkdir($d, 0777, true); }
        @touch($f);
    }


    // (wpc_warm_cooldown / wpc_warm_bucket / wpc_crit_watchdog) are OBJECT-CACHE-backed and vanish
    // on a cache flush — so N distinct crit-less URLs each start an independent kick→receiver→warm


    function wpc_kick_global_bucket_ok()
    {
        try {
            if (!defined('WPS_IC_CRITICAL')) { return true; }
            $cap = (int) apply_filters('wpc_kick_global_cap', 30);
            $win = (int) apply_filters('wpc_kick_global_window', 10);
            if ($cap <= 0 || $win <= 0) { return true; }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/';
            if (!is_dir($dir)) { @mkdir($dir, 0777, true); }
            $fh = @fopen($dir . 'gbucket', 'c+');


            if (!$fh) { return false; }
            $ok = true;


            if (@flock($fh, LOCK_EX | LOCK_NB)) {
                $st    = json_decode((string) stream_get_contents($fh), true);
                $now   = time();
                $start = (is_array($st) && isset($st['s'])) ? (int) $st['s'] : 0;
                $cnt   = (is_array($st) && isset($st['c'])) ? (int) $st['c'] : 0;
                if (($now - $start) >= $win) { $start = $now; $cnt = 0; }
                $ok = ($cnt < $cap);
                if ($ok) {
                    @ftruncate($fh, 0); @rewind($fh);
                    @fwrite($fh, json_encode(['s' => $start, 'c' => $cnt + 1]));
                }
                @flock($fh, LOCK_UN);
            }
            @fclose($fh);
            return $ok;
        } catch (\Throwable $e) {
            return true;
        }
    }


    // A client that re-dispatches crit generation every ~45s to a FAILING backend (502 / no-response
    // / land-fail) amplifies the outage — it holds an overloaded pod flat instead of letting it drain


    function wpc_gen_backoff_file()
    {
        if (!defined('WPS_IC_CRITICAL')) { return ''; }
        $d = rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/';
        if (!is_dir($d)) { @mkdir($d, 0777, true); }
        return $d . 'genfail.json';
    }

    /** TRUE ⇒ the gen dispatch should be DEFERRED (we're inside a failure-backoff window). */
    function wpc_gen_backoff_active()
    {
        try {
            if (!apply_filters('wpc_gen_failure_backoff', true)) { return false; }
            $f = wpc_gen_backoff_file();
            if ($f === '' || !@is_file($f)) { return false; }
            $j = json_decode((string) @file_get_contents($f), true);
            return is_array($j) && !empty($j['until']) && time() < (int) $j['until'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Record a gen/land FAILURE → widen the backoff window (exponential, capped). */
    function wpc_gen_note_failure($why = '')
    {
        try {
            if (!apply_filters('wpc_gen_failure_backoff', true)) { return; }
            $f = wpc_gen_backoff_file();
            if ($f === '') { return; }
            $j     = json_decode((string) @file_get_contents($f), true);
            $fails = (is_array($j) && !empty($j['fails'])) ? (int) $j['fails'] + 1 : 1;
            $base  = (int) apply_filters('wpc_gen_backoff_base', 45);
            $max   = (int) apply_filters('wpc_gen_backoff_max', 900);
            $wait  = min($base * (1 << min($fails - 1, 6)), $max);       // 45,90,180,360,720,900,900…
            wpc_crit_meta_write($f, json_encode(['fails' => $fails, 'until' => time() + $wait, 't' => time(), 'why' => substr((string) $why, 0, 40)]));
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('gen-backoff', '', '', ['fails' => $fails, 'wait' => $wait, 'why' => substr((string) $why, 0, 24)]);
            }
        } catch (\Throwable $e) {
        }
    }

    /** Record a land SUCCESS → clear the backoff (the backend is healthy again). */
    function wpc_gen_note_success()
    {
        try {
            $f = wpc_gen_backoff_file();
            if ($f !== '' && @is_file($f)) { @unlink($f); }
        } catch (\Throwable $e) {
        }
    }


    function wpc_gen_landless_file($urlKey)
    {
        if (!defined('WPS_IC_CRITICAL') || !is_string($urlKey) || $urlKey === '') { return ''; }
        $d = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
        if (!is_dir($d) && function_exists('wp_mkdir_p')) { @wp_mkdir_p($d); }
        return is_dir($d) ? $d . 'gen_fails.json' : '';
    }

    /** TRUE ⇒ this URL is parked (too many landless dispatches) — skip generation for now. */
    function wpc_gen_landless_parked($urlKey)
    {
        try {
            if (!apply_filters('wpc_gen_landless_giveup', true)) { return false; }
            $f = wpc_gen_landless_file($urlKey);
            if ($f === '' || !@is_file($f)) { return false; }
            $j = json_decode((string) @file_get_contents($f), true);
            return is_array($j) && !empty($j['until']) && time() < (int) $j['until'];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Count one dispatch for this URL; arm the park after 5 without a landing. */
    function wpc_gen_landless_note($urlKey)
    {
        try {
            if (!apply_filters('wpc_gen_landless_giveup', true)) { return; }
            $f = wpc_gen_landless_file($urlKey);
            if ($f === '') { return; }
            $j = json_decode((string) @file_get_contents($f), true);
            $n = (is_array($j) && !empty($j['n'])) ? (int) $j['n'] + 1 : 1;
            $until = 0;
            if ($n >= 5) {
                $until = time() + (int) min(3600 * pow(2, $n - 5), 86400); // 1h, 2h, 4h … 24h cap
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('gen-parked', (string) $urlKey, '', ['n' => $n, 'until' => $until]);
                }
            }
            wpc_crit_meta_write($f, json_encode(['n' => $n, 'until' => $until, 't' => time()]));
        } catch (\Throwable $e) {
        }
    }

    /** A landing for this URL clears its landless counter + park. */
    function wpc_gen_landless_clear($urlKey)
    {
        try {
            $f = wpc_gen_landless_file($urlKey);
            if ($f !== '' && @is_file($f)) { @unlink($f); }
        } catch (\Throwable $e) {
        }
    }


    function wpc_land_cooldown_file($urlKey)
    {
        if (!defined('WPS_IC_CRITICAL') || $urlKey === '') { return ''; }
        $d = rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/';
        if (!is_dir($d)) { @mkdir($d, 0777, true); }
        return $d . 'land_' . md5($urlKey) . '.txt';
    }

    /** TRUE ⇒ this URL landed successfully within the cooldown window — defer any new dispatch. */
    function wpc_land_cooldown_active($urlKey)
    {
        try {
            if (!apply_filters('wpc_land_cooldown', true) || $urlKey === '') { return false; }
            $f = wpc_land_cooldown_file($urlKey);
            if ($f === '' || !@is_file($f)) { return false; }
            $t = (int) @file_get_contents($f);
            return $t > 0 && (time() - $t) < (int) apply_filters('wpc_land_cooldown_seconds', 100);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Stamp a successful land — starts this URL's cooldown window. */
    function wpc_land_cooldown_stamp($urlKey)
    {
        try {
            $f = wpc_land_cooldown_file($urlKey);
            if ($f !== '') { wpc_crit_meta_write($f, (string) time()); }
        } catch (\Throwable $e) {
        }
    }


    function wpc_land_cooldown_clear($urlKey)
    {
        try {
            if (!defined('WPS_IC_CRITICAL')) { return; }
            if ($urlKey === 'all') {
                foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/land_*.txt') as $f) { @unlink($f); }
                return;
            }
            $f = wpc_land_cooldown_file((string) $urlKey);
            if ($f !== '' && @is_file($f)) { @unlink($f); }
        } catch (\Throwable $e) {
        }
    }

    function wpc_repull_kick_now($urlKey = '', $inline = false)
    {
        try {
            if ($urlKey === '' && class_exists('wps_ic_url_key')) {
                $urlKey = (new wps_ic_url_key())->setup(home_url('/'));
            }
            if (empty($urlKey)) {
                return false;
            }


            if (get_transient('wpc_kick_fire_' . md5($urlKey)) || wpc_kick_recent($urlKey, 60, 'fire')) {
                return true;
            }


            // B4 (≤60s contract): the landless park gates GENERATION only (inside
            // generateCriticalAjax) — it must never block the kick, because the kick is
            // also the COLLECTION lane and the artifact may be waiting on the shelf.
            set_transient('wpc_kick_fire_' . md5($urlKey), 1, 60);
            wpc_kick_stamp($urlKey, 'fire');
            $wpc_dir62   = defined('WPS_IC_CRITICAL') ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/' : '';
            $wpc_have62  = $wpc_dir62 && @filesize($wpc_dir62 . 'critical_desktop.css') > 5;
            if ($inline && $wpc_have62 && function_exists('wpc_lcp_repull_handler')) {

                wpc_lcp_repull_handler($urlKey, 1);
            } elseif (!wpc_kick_global_bucket_ok()) {


                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('kick-gbucket-capped', $urlKey, '', []);
                }
            } else {


                if (function_exists('wp_remote_get') && function_exists('admin_url')) {
                    wp_remote_get(admin_url('admin-ajax.php') . '?action=wpc_repull_kick&k=' . rawurlencode($urlKey),
                        ['blocking' => false, 'timeout' => 2, 'headers' => ['X-WPC-Cache-Warm' => '1']]);
                }
                if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')
                    && function_exists('admin_url')) {
                    $wpc_u = admin_url('admin-ajax.php');
                    $wpc_p = parse_url($wpc_u);
                    if (!empty($wpc_p['host'])) {
                        $wpc_https = (isset($wpc_p['scheme']) && $wpc_p['scheme'] === 'https');
                        $fp = wps_ic_ajax::wpc_loopback_open_socket($wpc_p['host'], $wpc_https ? 443 : 80, $wpc_https, 0.3);
                        if ($fp) {
                            $wpc_path = (isset($wpc_p['path']) ? $wpc_p['path'] : '/wp-admin/admin-ajax.php')
                                . '?action=wpc_repull_kick&k=' . rawurlencode($urlKey);
                            fwrite($fp, "GET " . $wpc_path . " HTTP/1.1\r\nHost: " . $wpc_p['host']
                                . "\r\nX-WPC-Cache-Warm: 1\r\nConnection: Close\r\n\r\n");
                            fclose($fp);
                        }
                    }
                }
            }

            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, 1])) {
                wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, 1]);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }


    function wpc_repull_kick_receiver()
    {
        try {
            if (function_exists('wpc_bg_slot_take') && !wpc_bg_slot_take('kick')) {
                return;
            }


            if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
            if (function_exists('set_time_limit')) { @set_time_limit(120); }
            $k = isset($_GET['k']) ? sanitize_text_field((string) $_GET['k']) : '';
            if ($k === '' || get_transient('wpc_repull_kick_' . md5($k)) || wpc_kick_recent($k, 120)) {
                wp_die('', '', ['response' => 200]);
            }
            set_transient('wpc_repull_kick_' . md5($k), 1, 120);
            wpc_kick_stamp($k);
            $dir  = defined('WPS_IC_CRITICAL') ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $k . '/' : '';
            $have = $dir && @filesize($dir . 'critical_desktop.css') > 5;


            $wpc_want117 = $have && $dir
                && (@is_file($dir . 'inv2_want_d.json') || @is_file($dir . 'inv2_want_m.json'))
                && function_exists('wpc_inv2_enabled') && wpc_inv2_enabled()
                && apply_filters('wpc_inv2_regen_on_want', true);
            if ($wpc_want117) {
                $have = false;
            }


            $wpc_stale73 = $have && $dir && @is_file($dir . 'stale.txt');
            if ($wpc_stale73 && (time() - (int) @file_get_contents($dir . 'stale.txt')) > 7200) {
                @unlink($dir . 'stale.txt');
                $wpc_stale73 = false;
            }
            if ($wpc_stale73) {
                $have = false;
            }
            // v7.10.685 — PRESENCE IS NOT SERVICE. $have decided COLLECT-vs-GENERATE purely on a
            // crit FILE existing, but a crit the render refuses to paint — the zero-dark bypass
            // window, or a content-keyed sanity reject — serves nobody. While that file sat on
            // disk every kick took the collect branch, so the ONE page that needed a fresh gen was
            // the one page that never dispatched. Receipt (wpcompress, 2026-08-02): pages with no
            // crit file reached the service all afternoon while the homepage sent ZERO requests for
            // hours and rendered critless. stale.txt was already honoured above; these are its two
            // untreated twins.
            $wpc_unserv685 = false;
            if ($have && $dir && apply_filters('wpc_kick_gen_on_unservable', true)) {
                if (function_exists('wpc_crit_bypass_active') && wpc_crit_bypass_active($k)) {
                    $wpc_unserv685 = 'bypass';
                } elseif (function_exists('wpc_crit_sanity_bad617')) {
                    // Only read the blob if the cheap check passed — kicks are rare, but this is
                    // an 80KB+ read on a background lane.
                    $wpc_cb685 = (string) @file_get_contents($dir . 'critical_desktop.css');
                    if ($wpc_cb685 !== '' && wpc_crit_sanity_bad617(rtrim($dir, '/'), $wpc_cb685)) {
                        $wpc_unserv685 = 'sanity';
                    }
                }
                if ($wpc_unserv685 !== false) {
                    $have = false; // regenerate: a crit nobody paints is not a crit we hold
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('kick-rx', $k, '', ['have' => $have ? 1 : 0, 'want' => $wpc_want117 ? 1 : 0,
                    'stale' => $wpc_stale73 ? 1 : 0, 'unservable' => $wpc_unserv685 === false ? '' : $wpc_unserv685]);
            }
            if ($have && function_exists('wpc_lcp_repull_handler')) {


                $wpc_ck   = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/' . $k . '/' : '';
                $wpc_cm   = (int) @filemtime($dir . 'critical_desktop.css');
                $wpc_newv = 0;
                foreach (['index.html_gzip', 'webp_index.html_gzip', 'mobile_index.html_gzip', 'mobile-webp_index.html_gzip'] as $wpc_vf) {
                    $wpc_vm = (int) @filemtime($wpc_ck . $wpc_vf);
                    if ($wpc_vm > $wpc_newv) {
                        $wpc_newv = $wpc_vm;
                    }
                }
                if ($wpc_cm > 0 && $wpc_newv > 0 && $wpc_cm > $wpc_newv
                    && apply_filters('wpc_stale_public_heal', true)) {
                    if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeCacheFiles')) {
                        wps_ic_cache_integrations::purgeCacheFiles($k);
                    }
                    $wpc_su = (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'getUrlFromKey'))
                        ? (string) wps_ic_url_key::getUrlFromKey($k) : '';
                    if ($wpc_su !== '' && class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'removeStaticMirror')) {
                        wps_cacheHtml::removeStaticMirror($wpc_su);
                    }
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('stale-purge', $k, $wpc_su, ['crit_m' => $wpc_cm, 'cache_m' => $wpc_newv]);
                    }
                }
                if (function_exists('wpc_wire_catchup')) {
                    wpc_wire_catchup($k); // v7.10.684 — heal a declared-but-not-held wire on the kick lane
                }
                wpc_lcp_repull_handler($k, 1);
            } elseif (class_exists('wps_criticalCss')
                // v7.10.686 — AN UNSERVABLE CRIT MUST NOT BE VETOED BY COLLECTION. .685 correctly
                // routed a bypassed/sanity-rejected crit to this branch, and then collection
                // answered "already landed" from the same file-presence test the branch exists to
                // escape (pullDerivedArtifacts returns true when both blobs are >64B) — so the
                // generate below never ran. Receipt (wpcompress 2026-08-02): kick-rx
                // {have:0, unservable:"bypass"} immediately followed by kick-collect-landed, and
                // still no dispatch. Re-collecting cannot help either suppression state: the
                // bypass rejects THESE bytes by mtime and the sanity mark rejects them by hash,
                // so the same bytes stay unservable however many times we fetch them. Only a new
                // generation changes the answer — short-circuit straight to it.
                && ($wpc_unserv685 !== false
                    || !(function_exists('wpc_crit_kick_collect') && wpc_crit_kick_collect($k)))) {
                // B3 (≤60s contract): collection ran first and found nothing on the shelf —
                // only now is generation the right move.

                $u = '';
                if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'getUrlFromKey')) {
                    $u = (string) wps_ic_url_key::getUrlFromKey($k);
                }
                // A8 (incident report 2026-07-20): an unresolvable key must be a NO-OP, not a
                // home_url fallback — the fallback redirected five different pages' kicks at
                // the one page already parked and failing (and stamped home's key over their
                // logs, A7). Home itself still resolves: its key matches setup(home_url()).
                if ($u === '' && function_exists('home_url') && class_exists('wps_ic_url_key')
                    && ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/') === ltrim((string) $k, '/')) {
                    $u = home_url('/');
                }
                if ($u === '') {
                    // v7.10.530 — record the proof so the render path stops minting chains for it.
                    if (function_exists('wpc_kick_dead_mark')) {
                        wpc_kick_dead_mark($k);
                    }
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('kick-unresolvable', $k, '', []);
                    }
                }
                if ($u !== '') {


                    global $wpdb;


                    $wpc_lkns75 = 'wpc_gen_' . substr(md5((defined('DB_NAME') ? DB_NAME : '') . $wpdb->prefix), 0, 12) . '_';
                    $wpc_slot75 = 0;
                    foreach ([1, 2] as $wpc_s75) {
                        if ((int) $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, %d)", $wpc_lkns75 . $wpc_s75, 0))) {
                            $wpc_slot75 = $wpc_s75;
                            break;
                        }
                    }
                    if (!$wpc_slot75) {
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('kick-slots-busy', $k, '', []);
                        }
                    } else {
                        try {
                            if (function_exists('set_time_limit')) {
                                @set_time_limit(150);
                            }
                            $c = new wps_criticalCss($u);
                            if (method_exists($c, 'generateCriticalAjax')) {
                                $c->generateCriticalAjax();
                            }
                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('kick-generate', $k, $u, []);
                            }
                        } finally {
                            $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $wpc_lkns75 . $wpc_slot75));
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        wp_die('', '', ['response' => 200]);
    }
    add_action('wp_ajax_wpc_repull_kick', 'wpc_repull_kick_receiver');
    add_action('wp_ajax_nopriv_wpc_repull_kick', 'wpc_repull_kick_receiver');
}


if (!function_exists('wpc_crit_self_arm_watchdog')) {
    function wpc_crit_self_arm_watchdog()
    {
        try {
            if (!apply_filters('wpc_crit_self_arm', true) || !function_exists('get_option')) {
                return;
            }
            if (get_transient('wpc_crit_watchdog')) {
                return;
            }
            if (!is_admin() && !(function_exists('wp_doing_ajax') && wp_doing_ajax())
                && function_exists('wpc_pipeline_admission_ok') && !wpc_pipeline_admission_ok()) {
                return; // query-string URLs never self-arm the pipeline
            }
            set_transient('wpc_crit_watchdog', 1, 120);
            $set = get_option(WPS_IC_SETTINGS);
            if (!is_array($set) || empty($set['critical']['css']) || $set['critical']['css'] != '1') {
                return;
            }
            $opt = get_option(WPS_IC_OPTIONS);
            if (!is_array($opt) || empty($opt['api_key'])) {
                return;
            }
            if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return;
            }
            $k = (new wps_ic_url_key())->setup(home_url('/'));
            if (empty($k)) {
                return;
            }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $k . '/';
            // B6 (≤60s contract): non-home URLs get the same self-heal — any dir with a
            // fresh dispatch (uuid.txt <6h) and no landed crit gets a collection kick.
            // Bounded ≤3/tick; stale uuids age out of the window on their own.
            $wpc_root179s = rtrim(WPS_IC_CRITICAL, '/') . '/';
            $wpc_sw179 = 0;
            foreach (['*/uuid.txt', '*/*/uuid.txt', '*/*/*/uuid.txt'] as $wpc_gp179) {
                foreach ((array) @glob($wpc_root179s . $wpc_gp179, GLOB_NOSORT) as $wpc_uf179) {
                    if ($wpc_sw179 >= 3) {
                        break 2;
                    }
                    $wpc_ud179 = dirname($wpc_uf179) . '/';
                    $wpc_uk179 = trim(substr($wpc_ud179, strlen($wpc_root179s)), '/');
                    if ($wpc_uk179 === '' || $wpc_uk179 === ltrim($k, '/')) {
                        continue; // homepage rides the dedicated lane below
                    }
                    // AUTO-QUARANTINE (out-of-the-box recovery): land_uuid WITHOUT dispatch_ts
                    // = landed-but-never-dispatched = storm-borrowed foreign crit. Wipe the
                    // dir; the sentinel/kick regenerate it legitimately. Pre-.320 dirs have
                    // NEITHER stamp and are untouched.
                    // manifest.held_gen belt (v7.10.697): a manifest flip is a legitimate land
                    // whether or not a dispatch stamp survives — never quarantine that dir.
                    if (@is_readable($wpc_ud179 . 'land_uuid.txt') && !@is_readable($wpc_ud179 . 'dispatch_ts.txt')
                        && !@is_readable($wpc_ud179 . 'manifest.held_gen')) {
                        foreach ((array) @glob($wpc_ud179 . '*') as $wpc_cf179) {
                            @unlink($wpc_cf179);
                        }
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('contamination-swept', $wpc_uk179, '', []);
                        }
                        $wpc_sw179++;
                        continue;
                    }
                    if ((time() - (int) @filemtime($wpc_uf179)) > 6 * 3600) {
                        continue;
                    }
                    if (@filesize($wpc_ud179 . 'critical_desktop.css') > 5 && !@is_file($wpc_ud179 . 'stale.txt')) {
                        continue;
                    }
                    if (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($wpc_uk179)) {
                        continue;
                    }
                    if (get_transient('wpc_kick_fire_' . md5($wpc_uk179)) || get_transient('wpc_collect_' . md5($wpc_uk179))) {
                        continue;
                    }
                    $wpc_sw179++;
                    if (function_exists('wpc_repull_kick_now')) {
                        wpc_repull_kick_now($wpc_uk179);
                    }
                }
            }
            // v7.10.685 — twin of the kick receiver's servability gate: the watchdog stood down on
            // FILE PRESENCE too, so a homepage whose crit is bypassed/sanity-rejected had neither
            // lane able to arm a regen. A crit that does not paint must not satisfy the watchdog.
            if (@filesize($dir . 'critical_desktop.css') > 5
                && !(apply_filters('wpc_kick_gen_on_unservable', true)
                    && function_exists('wpc_crit_bypass_active') && wpc_crit_bypass_active($k))) {
                return;
            }
            if (get_transient('criticalRunning' . $k) || get_transient('wpc_repull_kick_' . md5($k))) {
                return;
            }
            if (function_exists('wpc_repull_kick_now')) {
                wpc_repull_kick_now($k);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('watchdog-arm', $k, '', []);
                }
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('init', 'wpc_crit_self_arm_watchdog', 99);
}


// ─── Crit ≤60s land contract (v7.10.320): dispatch-owned collector + publish webhook ───
if (!function_exists('wpc_crit_collect_now')) {
    /**
     * ZERO-HELD-WORKER collector (v7.10.324, James's contention law): ONE probe —
     * HEAD by uuid / pointer fallback, land through the validated save path — and exit.
     * No sleep, no loop, no held FPM child. The cadence comes from pre-scheduled probe
     * events, fired by whatever PHP runs next (spawn-nudge, webhook, sentinel beacon,
     * admin heartbeat). $budget > 0 ⇒ on a miss, one follow-up probe is scheduled.
     */
    function wpc_crit_collect_now($urlKey, $budget = 0, $interval = 12)
    {
        try {
            if (empty($urlKey) || !defined('WPS_IC_CRITICAL') || !class_exists('wps_criticalCss')) {
                return false;
            }
            if (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($urlKey)) {
                return false;
            }
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                return false; // scheduled probes keep trying; never add load to a hot box
            }
            $lk = 'wpc_collect_' . md5((string) $urlKey);
            if (get_transient($lk)) {
                return false; // collapse simultaneous probes
            }
            set_transient($lk, 1, 8);
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
            // A dispatch newer than the land (uuid.txt ≠ land_uuid.txt) must overwrite —
            // organic regens and serve-stale copies are otherwise stranded by "already landed".
            $du = trim((string) @file_get_contents($dir . 'uuid.txt'));
            $lu = trim((string) @file_get_contents($dir . 'land_uuid.txt'));
            $force = ($du !== '' && $du !== $lu);
            // stale + same-uuid is decided INSIDE pullDerivedArtifacts via the pointer
            // (a fresh publish collects even when the dispatch timeout ate the ack).
            $landed = false;
            try {
                $cc = new wps_criticalCss();
                $landed = $cc->pullDerivedArtifacts($urlKey, '', $force);
            } catch (\Throwable $e) {
            }
            delete_transient($lk);
            if ($landed) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('collector-landed', (string) $urlKey, '', ['forced' => $force ? 1 : 0]);
                }
                return true;
            }
            if ((int) $budget > 0 && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_crit_collect', [$urlKey, 9])) {
                wpc_pl_sched(time() + max(8, (int) $interval), 'wpc_crit_collect', [$urlKey, 9]);
            }
            // NO spawn_cron on a miss — a probe miss re-spawning cron chains cron passes
            // continuously across many armed URLs (staging TTFB storm receipt); the arm's
            // single nudge + organic PHP + the webhook carry the cadence.
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_crit_collector_arm')) {
    /**
     * B2 as probe BURSTS (law 9 + zero-held-workers): dispatch mints its own pickup
     * appointments — staggered single events, each a sub-second probe — plus the repull
     * backstop and a spawn nudge. No shutdown worker, no sleeping FPM child, ever.
     */
    function wpc_crit_collector_arm($urlKey)
    {
        try {
            if (empty($urlKey)) {
                return;
            }
            if (get_transient('wpc_collarm_' . md5((string) $urlKey))) {
                return;
            }
            set_transient('wpc_collarm_' . md5((string) $urlKey), 1, 60);
            foreach ([12 => 1, 30 => 2, 60 => 3, 120 => 4] as $wpc_at324 => $wpc_n324) {
                if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_crit_collect', [$urlKey, $wpc_n324])) {
                    wpc_pl_sched(time() + $wpc_at324, 'wpc_crit_collect', [$urlKey, $wpc_n324]);
                }
            }
            if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, 1])) {
                wpc_pl_sched(time() + 90, 'wpc_lcp_repull', [$urlKey, 1]);
            }
            if (function_exists('spawn_cron')) {
                wpc_spawn_cron();
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_crit_collect', function ($urlKey, $n = 1) {
        wpc_crit_collect_now((string) $urlKey, ((int) $n === 4 || (int) $n === 9) ? 1 : 0);
    }, 10, 2);
    // B5: a failed dispatch re-dispatches itself (retry_after-scheduled by the dispatcher).
    add_action('wpc_crit_redispatch', function ($urlKey, $sync = 0) {
        try {
            if (empty($urlKey) || !class_exists('wps_criticalCss') || !class_exists('wps_ic_url_key')) {
                return;
            }
            $u = method_exists('wps_ic_url_key', 'getUrlFromKey')
                ? (string) wps_ic_url_key::getUrlFromKey((string) $urlKey) : '';
            if ($u === '' && function_exists('home_url')
                && ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/') === ltrim((string) $urlKey, '/')) {
                $u = home_url('/');
            }
            if ($u === '') {
                return;
            }
            $c = new wps_criticalCss($u);
            if (method_exists($c, 'generateCriticalAjax')) {
                $c->generateCriticalAjax((bool) $sync); // sync=1 = operator Pull Latest (bust tpl cache)
            }
        } catch (\Throwable $e) {
        }
    }, 10, 2);
}

if (!function_exists('wpc_near_expiry_regen')) {
    /** §6 near_expiry (>13d of 14d retention): arm a fresh gen instead of relying on
     *  about-to-expire shelf URLs. FAIL-OPEN: with no served crit on disk the caller's
     *  pull proceeds untouched (an expiring artifact beats none). Herd-gated: the expiry
     *  horizon is fleet-synchronized by construction — long per-key transient, global
     *  backoff + pressure yields, jittered dispatch. */
    function wpc_near_expiry_regen($urlKey)
    {
        try {
            if ((string) $urlKey === '' || !defined('WPS_IC_CRITICAL')
                || !apply_filters('wpc_near_expiry_regen', true)) {
                return false;
            }
            if (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($urlKey)) {
                return false;
            }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
            if (!((int) @filesize($dir . 'critical_desktop.css') > 64)) {
                return false;
            }
            if (get_transient('wpc_nexp_' . md5((string) $urlKey))) {
                return false;
            }
            if (function_exists('wpc_gen_backoff_active') && wpc_gen_backoff_active()) {
                return false;
            }
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                return false;
            }
            // Schedule FIRST, suppress only on a committed dispatch — the pipeline
            // ceiling can eat the schedule, and a burned shot with a 2-day suppression
            // would outlive the ≤1-day retention runway. 12h suppression = a second
            // attempt still fits inside the window.
            $wpc_nsch356 = false;
            if (function_exists('wpc_pl_sched') && function_exists('wp_next_scheduled')) {
                $wpc_nsch356 = wp_next_scheduled('wpc_crit_redispatch', [(string) $urlKey]) !== false
                    || wpc_pl_sched(time() + rand(30, 900), 'wpc_crit_redispatch', [(string) $urlKey]) !== false;
            }
            if (!$wpc_nsch356) {
                return false;
            }
            set_transient('wpc_nexp_' . md5((string) $urlKey), 1, 12 * HOUR_IN_SECONDS);
            wpc_crit_meta_write($dir . 'stale.txt', (string) time());
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('near-expiry-regen', (string) $urlKey, '', []);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

// ─── §10 TTFB actuation — the slow-ttfb lever (v7.10.357) ───
// The service MEASURES a recoverable origin-TTFB cap (ceiling.caps[factor:ttfb,scope:origin].
// fixable_ms); the plugin ACTUATES by arming the zero-PHP static serve (Lane A), whose OWN
// self-test + auto-rollback lives in htaccess.class.php — so the rollback safety is local to
// the actuator (contract §10 v1.2.1). Never touches Lane B (cache.advanced / R9) or the manual
// toggle; a host it cannot serve on rolls back to PHP serve, unchanged, and backs off.
if (!function_exists('wpc_ttfb_read_ceiling_cap')) {
    /** Recoverable origin-TTFB think-time (ms) from a delay.json ceiling{}; 0 if none. */
    function wpc_ttfb_read_ceiling_cap($dir)
    {
        try {
            $f = rtrim((string) $dir, '/') . '/delay.json';
            if (!@is_readable($f)) {
                return 0;
            }
            $j = json_decode((string) @file_get_contents($f), true);
            if (!is_array($j) || empty($j['ceiling']) || !is_array($j['ceiling'])) {
                return 0;
            }
            $lists = [];
            foreach (['mobile', 'desktop'] as $wpc_dv357) {
                if (isset($j['ceiling'][$wpc_dv357]['caps']) && is_array($j['ceiling'][$wpc_dv357]['caps'])) {
                    $lists[] = $j['ceiling'][$wpc_dv357]['caps'];
                }
            }
            if (isset($j['ceiling']['caps']) && is_array($j['ceiling']['caps'])) {
                $lists[] = $j['ceiling']['caps'];
            }
            $best = 0;
            foreach ($lists as $wpc_caps357) {
                foreach ($wpc_caps357 as $c) {
                    // caps[].basis (service 3.77.1/.81.0): ONLY actuate on measured origin
                    // think-time. 'coarse_upper_bound' (server_ms absent → ttfb−floor) over-
                    // claims; 'cpu_share_proxy' is the TBT cap, never TTFB. Skip a degenerate
                    // cross-device read (3.81.0) or an unmeasured leg — a slow origin can't tell
                    // which viewport asked (staging: 28s "measured" TTFB, real 260ms → bogus
                    // 45-point cap). Absent basis (pre-3.77 gens) is NOT trusted → no actuation.
                    if (is_array($c) && (string) ($c['factor'] ?? '') === 'ttfb' && (string) ($c['scope'] ?? '') === 'origin'
                        && (string) ($c['basis'] ?? '') === 'measured_server_ms'
                        && empty($c['ttfb_read_degenerate']) && empty($c['ttfb_unmeasured'])
                        && isset($c['fixable_ms']) && is_numeric($c['fixable_ms']) && (int) $c['fixable_ms'] > $best) {
                        $best = (int) $c['fixable_ms'];
                    }
                }
            }
            return $best;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Exponential-capped retry window after a host can't serve statically (mirrors the
     *  gen-backoff): base 1h, cap 7d — never re-probe a non-serving host every land. */
    function wpc_ttfb_ss_backoff_bump()
    {
        $n = max(0, (int) get_option('wpc_ttfb_ss_fails'));
        $base = (int) apply_filters('wpc_ttfb_ss_backoff_base', 3600);
        $cap  = (int) apply_filters('wpc_ttfb_ss_backoff_cap', 7 * DAY_IN_SECONDS);
        update_option('wpc_ttfb_ss_fails', $n + 1, false);
        update_option('wpc_ttfb_ss_next', time() + min($cap, $base * (1 << min($n, 12))), false);
    }
    function wpc_ttfb_ss_backoff_clear()
    {
        delete_option('wpc_ttfb_ss_fails');
        delete_option('wpc_ttfb_ss_next');
    }

    function wpc_ttfb_maybe_arm_static_serve($urlKey)
    {
        try {
            if (!apply_filters('wpc_ttfb_static_serve_auto', true) || get_option('wpc_ttfb_ss_optout')) {
                return false;
            }
            // Only evaluate in an HTTP context (loopback cron / admin-ajax kick): a WP-CLI
            // system-cron run has no SERVER_SOFTWARE, so isApache() would false-negative and
            // POISON the backoff on a genuinely Apache host. Skip cleanly — no state touched
            // — and let the next HTTP-context kick decide.
            if (empty($_SERVER['SERVER_SOFTWARE'])) {
                return false;
            }
            // Already serving statically (auto OR user OR live block) → nothing to do.
            if (get_option('wpc_static_serve_active') == 1 || get_option('wpc_ttfb_ss_auto') === '1') {
                return false;
            }
            $set = get_option(WPS_IC_SETTINGS);
            if (is_array($set) && !empty($set['static-serve']) && $set['static-serve'] == '1') {
                return false; // user already armed it manually
            }
            // Nothing to statically serve unless the plugin's own HTML cache is on.
            if (function_exists('wpc_cache_first_enabled') && !wpc_cache_first_enabled()) {
                return false;
            }
            // Evaluate at most hourly; honor the failure backoff; yield under pressure.
            if (get_transient('wpc_ttfb_ss_eval') || time() < (int) get_option('wpc_ttfb_ss_next')) {
                return false;
            }
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                return false;
            }
            // Never stomp a competing full-page cache (WP Rocket / LiteSpeed / foreign drop-in).
            if (function_exists('wpc_detect_foreign_page_cache') && wpc_detect_foreign_page_cache() !== false) {
                return false;
            }
            set_transient('wpc_ttfb_ss_eval', 1, HOUR_IN_SECONDS);

            $dir = defined('WPS_IC_CRITICAL') ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/' : '';
            $fixable = $dir !== '' ? wpc_ttfb_read_ceiling_cap($dir) : 0;
            $min = (int) apply_filters('wpc_ttfb_static_serve_min_ms', 300);
            if ($fixable < $min) {
                return false; // no meaningful recoverable TTFB — leave the host alone
            }

            if (!class_exists('wps_ic_htaccess') && defined('WPS_IC_DIR')) {
                @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
            }
            if (!class_exists('wps_ic_htaccess')) {
                return false;
            }
            $h = new wps_ic_htaccess();
            // The constructor only detects Apache under is_admin(); this lane is a background
            // kick/cron worker, so force detection now (needs SERVER_SOFTWARE — absent only
            // under WP-CLI, where there is no request to serve and skipping is correct).
            if (method_exists($h, 'isApache')) {
                $h->isApache();
            }
            // Arm the serve-decision flag BEFORE apply so the enable's warm writes real mirror
            // files (the wpc_static_serve filter below honors it); rolled back on any failure.
            update_option('wpc_ttfb_ss_auto', '1', false);
            $res = method_exists($h, 'applyStaticServe') ? $h->applyStaticServe() : ['ok' => false, 'reason' => 'no-method'];
            if (!empty($res['ok'])) {
                wpc_ttfb_ss_backoff_clear();
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('ttfb-static-serve-armed', (string) $urlKey, '', ['fixable_ms' => $fixable]);
                }
                if (function_exists('wpc_auto_journal')) {
                    wpc_auto_journal('ttfb-static-serve', ['armed' => 1, 'fixable_ms' => $fixable]);
                }
                return true;
            }
            // not-apache / not-writeable / self-test-failed (already rolled the htaccess block
            // back to PHP serve, nothing broken) → clear our flag + back off hard.
            delete_option('wpc_ttfb_ss_auto');
            wpc_ttfb_ss_backoff_bump();
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('ttfb-static-serve-unavailable', (string) $urlKey, '',
                    ['reason' => (string) ($res['reason'] ?? '?'), 'fixable_ms' => $fixable]);
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    if (function_exists('add_filter')) {
        // Serve-decision: honor the auto-armed TTFB static serve so cacheHtml writes the
        // mirror files. The constant / user setting still win first (the core filter runs
        // and returns early when either is set); this only ORs in the auto flag.
        add_filter('wpc_static_serve', function ($v) {
            return $v ? $v : (get_option('wpc_ttfb_ss_auto') === '1');
        });
    }
}

if (!function_exists('wpc_crit_kick_collect')) {
    /** B3: the kick lane COLLECTS before it generates — the shelf outranks the brakes. */
    function wpc_crit_kick_collect($urlKey)
    {
        try {
            if (empty($urlKey) || !class_exists('wps_criticalCss') || !defined('WPS_IC_CRITICAL')) {
                return false;
            }
            // stale + same-uuid is decided inside pullDerivedArtifacts via the pointer —
            // it refuses only when nothing newer than the landed artifact is published.
            $cc = new wps_criticalCss();
            if ($cc->pullDerivedArtifacts((string) $urlKey)) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('kick-collect-landed', (string) $urlKey, '', []);
                }
                return true;
            }
        } catch (\Throwable $e) {
        }
        return false;
    }
}

if (!function_exists('wpc_crit_purge_redispatch')) {
    /** B1: the crit purge itself owns the homepage regen — detached, ungated on cache mode. */
    function wpc_crit_purge_redispatch($sync = false)
    {
        try {
            if (!class_exists('wps_criticalCss') || !class_exists('wps_ic_url_key') || !function_exists('home_url')) {
                return;
            }
            $k = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
            if ($k === '') {
                return;
            }
            // Operator Pull Latest passes $sync=true → the cron backstop carries sync=1 so a
            // fresh (tpl-cache-busting) gen happens even if the detach below dies. The NON-sync
            // path keeps the 1-arg [$k] shape so it still dedups with near_expiry / B5 redispatch
            // (which schedule [$urlKey]); a sync resync uses [$k,1] and is INTENDED to fire even
            // if a non-sync gen is pending (only sync busts the cache).
            $wpc_rd_args357 = $sync ? [$k, 1] : [$k];
            if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_crit_redispatch', $wpc_rd_args357)) {
                wpc_pl_sched(time() + 20, 'wpc_crit_redispatch', $wpc_rd_args357); // backstop if the detach dies
            }
            if (function_exists('spawn_cron')) {
                wpc_spawn_cron();
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('purge-redispatch', $k, '', []);
            }
            // Probe bursts own the pickup (zero-held-workers) — armed regardless of SAPI.
            if (function_exists('wpc_crit_collector_arm')) {
                wpc_crit_collector_arm($k);
            }
            if (!function_exists('fastcgi_finish_request') && !function_exists('litespeed_finish_request')) {
                return;
            }
            register_shutdown_function(function () use ($k, $sync) {
                try {
                    if (function_exists('fastcgi_finish_request')) {
                        @fastcgi_finish_request();
                    } elseif (function_exists('litespeed_finish_request')) {
                        @litespeed_finish_request();
                    }
                    // Bounded ≤ ~15s total (8s dispatch + one probe) — ONE ordinary-request-
                    // sized burst per human purge click, never a sleeping worker (James's
                    // contention law; the probe bursts + webhook carry the tail).
                    if (function_exists('set_time_limit')) { @set_time_limit(30); }
                    if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
                    add_filter('wpc_gen_dispatch_timeout', function () use ($sync) { return $sync ? 20 : 8; });
                    $_GET['forceCritical'] = '1'; // human-initiated: automatic caps stand down
                    $c = new wps_criticalCss(home_url('/'));
                    if (method_exists($c, 'generateCriticalAjax')) {
                        $c->generateCriticalAjax((bool) $sync); // Pull Latest → fresh, tpl-cache-busting gen
                    }
                    if (function_exists('wpc_crit_collect_now')) {
                        wpc_crit_collect_now($k, 1);
                    }
                } catch (\Throwable $e) {
                }
            });
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('wpc_delay_inline_fresher')) {
    /**
     * Delay-inline landing gate (v7.10.381, service-flagged): land when the on-disk delay.json is
     * ABSENT, or when the incoming measured manifest is NEWER by generated_at (the service amends
     * in place under a stable uuid, so a stale first-land must not pin us forever). Forward-only —
     * an incoming with no/older/unparseable stamp keeps the on-disk copy, so it can never regress.
     * The measured-shape gate still applies separately, so a non-measured body never lands here.
     */
    function wpc_delay_inline_fresher($file, $incoming)
    {
        if (!@is_readable($file)) { return true; }
        if (!is_array($incoming) || empty($incoming['generated_at'])) { return false; }
        $in = @strtotime((string) $incoming['generated_at']);
        if (!$in) { return false; }
        $od   = json_decode((string) @file_get_contents($file), true);
        $odGA = (is_array($od) && !empty($od['generated_at'])) ? @strtotime((string) $od['generated_at']) : 0;
        return $in > (int) $odGA;
    }
}

if (!function_exists('wpc_lcp_first_auth')) {
    /** First lcp_preload's url_is_authoritative: true (or absent => true) emits a BARE hero
     *  preload; false = responsive skip; null = no preload entry. Decides the bare<->skip flip. */
    function wpc_lcp_first_auth($lcpArr)
    {
        if (!is_array($lcpArr) || empty($lcpArr['hints']['lcp_preload'][0]) || !is_array($lcpArr['hints']['lcp_preload'][0])) {
            return null;
        }
        $e = $lcpArr['hints']['lcp_preload'][0];
        return array_key_exists('url_is_authoritative', $e) ? (bool) $e['url_is_authoritative'] : true;
    }

    /** v7.10.383 — synchronous CF edge purge for the bare->skip lcp flip ONLY. The served page
     *  still carries the old bare preload; the batched CF purge (purgeEdgeHtmlUrls default queue)
     *  pressure-defers on a pinned box (queued becomes never), so evict this one URL inline, past
     *  the queue. Batched path untouched for everything else. Best-effort; never blocks. */
    function wpc_lcp_edge_flip_purge($url)
    {
        try {
            if ((string) $url === '' || !class_exists('wps_ic_cache') || !method_exists('wps_ic_cache', 'purgeEdgeHtmlUrls')) {
                return;
            }
            $clean = (string) strtok((string) $url, '?');
            if ($clean === '') { return; }
            $forms = array_values(array_filter([$clean, (substr($clean, -1) === '/') ? rtrim($clean, '/') : $clean . '/']));
            wps_ic_cache::purgeEdgeHtmlUrls($forms, true); // inline=true => synchronous, past the batch queue
            if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('cf-purge-flip-sync', '', $clean, []); }
        } catch (\Throwable $e) {
        }
    }
}

if (!function_exists('wpc_crit_webhook_rx')) {
    /**
     * v3.69.1 publish webhook receiver (joint spec v2 FINAL):
     * POST /wp-admin/admin-ajax.php?action=wpc_crit_published with
     * {event:'crit_published', uuid, url, url_key, ts, sig},
     * sig = HMAC-SHA256("uuid|url_key|ts", apikey) — url_key verbatim from the body
     * (service canonicalizeForKey form), used for the signature ONLY; our own key
     * derivation stays wps_ic_url_key::setup. The webhook is a SIGNAL, not a data
     * carrier: artifacts{} is advisory — the pull derives URLs from the signed uuid
     * through the validated save path, so a tampered body can never point this site
     * at foreign CSS.
     */
    function wpc_crit_webhook_rx()
    {
        try {
            if (!apply_filters('wpc_crit_webhook', true) || !defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
                status_header(200);
                echo 'off';
                exit;
            }
            // v3.82.0+ consolidated callback carries artifacts INLINE (delay.json ~29KB), so
            // the old 16KB cap truncated the body → json_decode failed → 403 → the plugin fell
            // back to polling. Read the full signed body up to a bounded cap (the sig is verified
            // before any of it is trusted; an unsigned POST just pays one bounded read, then 403).
            $raw  = (string) @file_get_contents('php://input', false, null, 0, (int) apply_filters('wpc_crit_webhook_max_body', 262144));
            $j    = json_decode($raw, true);
            $uuid = is_array($j) ? preg_replace('/[^A-Za-z0-9-]/', '', (string) ($j['uuid'] ?? '')) : '';
            $url  = is_array($j) ? (string) ($j['url'] ?? '') : '';
            $ukey = is_array($j) ? (string) ($j['url_key'] ?? '') : '';
            // Signature transport: X-WPC-Sig / X-WPC-Ts headers (service 3.83.0 consolidated
            // callback), falling back to body sig/ts (legacy crit_published). Same HMAC recipe
            // over uuid|url_key|ts either way — url_key/uuid always ride the body.
            $ts   = (string) (!empty($_SERVER['HTTP_X_WPC_TS']) ? $_SERVER['HTTP_X_WPC_TS'] : (is_array($j) ? ($j['ts'] ?? '') : ''));
            $sig  = strtolower((string) (!empty($_SERVER['HTTP_X_WPC_SIG']) ? $_SERVER['HTTP_X_WPC_SIG'] : (is_array($j) ? ($j['sig'] ?? '') : '')));
            $evt  = is_array($j) ? (string) ($j['event'] ?? '') : '';
            // Q2: 'prescriptions_updated' rides this receiver with the identical field
            // set + signature recipe; everything below the event check stays shared.
            $ok   = is_array($j) && in_array($evt, ['crit_published', 'prescriptions_updated'], true)
                && strlen($uuid) >= 8 && $url !== '' && strlen($url) <= 2048
                && $ukey !== '' && strlen($ukey) <= 1024
                && ctype_digit($ts) && (bool) preg_match('/^[a-f0-9]{64}$/', $sig);
            // ts is milliseconds in the contract — accept s or ms, 10min skew.
            $tsn = $ok ? (float) $ts : 0;
            if ($tsn > 20000000000) {
                $tsn = $tsn / 1000;
            }
            $ok = $ok && abs(time() - $tsn) <= 600;
            $opt = ($ok && function_exists('get_option') && defined('WPS_IC_OPTIONS')) ? get_option(WPS_IC_OPTIONS) : [];
            $key = is_array($opt) && !empty($opt['api_key']) ? (string) $opt['api_key'] : '';
            $ok  = $ok && $key !== '' && hash_equals(hash_hmac('sha256', $uuid . '|' . $ukey . '|' . $ts, $key), $sig);
            if ($ok) {
                $wh = strtolower((string) parse_url($url, PHP_URL_HOST));
                $oh = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
                if (strpos($wh, 'www.') === 0) { $wh = substr($wh, 4); }
                if (strpos($oh, 'www.') === 0) { $oh = substr($oh, 4); }
                $ok = $wh !== '' && $wh === $oh;
            }
            if (!$ok) {
                status_header(403);
                echo 'x';
                exit;
            }
            if ($evt === 'prescriptions_updated') {
                // SIGNAL-ONLY (body URLs untrusted): schedule the pull; it re-derives
                // through the validated /v2/latest + prescriptions_url.txt path. Own
                // dedupe key — the crit event shares this uuid and must not eat it.
                if (get_transient('wpc_whkp_' . md5($uuid . '|' . $ts))) {
                    status_header(200);
                    echo 'dup';
                    exit;
                }
                set_transient('wpc_whkp_' . md5($uuid . '|' . $ts), 1, 600);
                $wpc_pk355 = ltrim((string) (new wps_ic_url_key())->setup((string) strtok($url, '?')), '/');
                if ($wpc_pk355 === '' || (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($wpc_pk355))) {
                    status_header(200);
                    echo 'nokey';
                    exit;
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('whk-presc-rx', $wpc_pk355, (string) strtok($url, '?'), ['uuid' => substr($uuid, 0, 8)]);
                }
                // The webhook is the POSITIVE SIGNAL that prescriptions exist. Write the
                // want-marker (opens the /v2/latest prescriptions gate for ≤1h so the URL
                // meta lands even on sites that already hold every other artifact) AND, if
                // the URL is already on disk, a due-NOW marker so the next traffic kick
                // pulls immediately even with cron dead.
                $wpc_pdir355 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_pk355 . '/';
                if (is_dir($wpc_pdir355)) {
                    wpc_crit_meta_write($wpc_pdir355 . 'presc_want.txt', (string) time());
                    if (@is_readable($wpc_pdir355 . 'prescriptions_url.txt')) {
                        wpc_crit_meta_write($wpc_pdir355 . 'presc_due.txt', (string) time());
                    }
                }
                if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_presc_poll', [$wpc_pk355])) {
                    wpc_pl_sched(time() + 1, 'wpc_presc_poll', [$wpc_pk355]);
                }
                status_header(200);
                echo 'ok';
                if (function_exists('spawn_cron')) {
                    register_shutdown_function('spawn_cron');
                }
                exit;
            }
            // Dedupe per DELIVERY (uuid|ts), not per uuid — v3.86.0 sends a SECOND signed
            // crit_published (observation_late follow-up, fresh ts) for the same uuid carrying
            // the amended url_is_authoritative lcp inline; keying on uuid alone would 'dup' it
            // out before the inline-consume blocks. A retry re-sends the same signed ts → still
            // deduped; the consume is idempotent (delay write-once, lcp overwrite) regardless.
            if (get_transient('wpc_whk_' . md5($uuid . '|' . $ts))) {
                status_header(200);
                echo 'dup';
                exit;
            }
            set_transient('wpc_whk_' . md5($uuid . '|' . $ts), 1, 600);
            $wpc_klk = rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/';
            if (!is_dir($wpc_klk)) { @mkdir($wpc_klk, 0777, true); }
            wpc_crit_meta_write($wpc_klk . 'whk_last.txt', (string) time());
            $k = ltrim((string) (new wps_ic_url_key())->setup((string) strtok($url, '?')), '/');
            if ($k === '' || (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($k))) {
                status_header(200);
                echo 'nokey';
                exit;
            }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $k . '/';
            if (!is_dir($dir) && function_exists('wp_mkdir_p')) { @wp_mkdir_p($dir); }
            wpc_crit_meta_write($dir . 'uuid.txt', $uuid);
            // v3.83.0 delay_inline: consume the INLINE delay.json (zero-HTTP) instead of the
            // blocking locator pull — delay.json was the last artifact still forcing a fetch.
            // The signed body is now a verified data carrier for this artifact, BUT we only
            // trust it after the SAME measured-shape gate a pulled artifact must pass, so a bad
            // inline body can never land a worse gen than the pull would. ADDITIVE: the by-uuid
            // pull below stays the authoritative path (delay_url locator never goes away), so a
            // bug here can't break delivery — it just misses the optimization. Field shape per
            // service §7: $j['delay'] (top-level body) + $j['delay_inline']===true.
            if (!empty($j['delay_inline']) && isset($j['delay']) && is_array($j['delay'])
                && function_exists('wpc_delay_inline_fresher') && wpc_delay_inline_fresher($dir . 'delay.json', $j['delay'])
                && class_exists('wps_ic_js_delay_v3')
                && apply_filters('wpc_delay_inline_consume', true)
                && wps_ic_js_delay_v3::wpc_delay_measured_shape($j['delay'])) {
                $wpc_dib383 = wp_json_encode($j['delay']);
                if (is_string($wpc_dib383) && $wpc_dib383 !== '' && strlen($wpc_dib383) <= 524288) {
                    wpc_crit_meta_write($dir . 'delay.json', $wpc_dib383);
                    delete_option('wpc_delay_v3_manifest_off');
                    delete_option('wpc_delay_v3_promoted');
                    if (function_exists('wpc_delay_aggr_rearm')) { wpc_delay_aggr_rearm(); }
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('delay-inline-landed', $k, '', ['bytes' => strlen($wpc_dib383)]);
                    }
                }
            }
            // v3.85.0 lcp_inline: consume the INLINE lcp.json off the signed callback (zero-HTTP),
            // carrying the fresh url_is_authoritative shape. UNLIKE delay (write-once, never-
            // regress) this OVERWRITES — the callback's lcp is THIS gen's authoritative copy, and
            // the point is to replace a stale on-disk lcp the load-gated repull will never
            // supersede on a chronically-loaded box. Validated as a real lcp.json (lcp_element or
            // hints) before trusting; signature already verified above. Drops lcp_none + purges so
            // the next render skips the bare-href preload (duplicate-kill) with no idle-load wait.
            if (!empty($j['lcp_inline']) && isset($j['lcp']) && is_array($j['lcp'])
                && (isset($j['lcp']['lcp_element']) || isset($j['lcp']['hints']))
                && apply_filters('wpc_lcp_inline_consume', true)) {
                $wpc_lib385 = wp_json_encode($j['lcp']);
                if (is_string($wpc_lib385) && $wpc_lib385 !== '' && strlen($wpc_lib385) <= 524288) {
                    $wpc_oldauth383 = function_exists('wpc_lcp_first_auth')
                        ? wpc_lcp_first_auth(json_decode((string) @file_get_contents($dir . 'lcp.json'), true)) : null;
                    wpc_lcp_write_preserve781($dir, $wpc_lib385);
                    @unlink($dir . 'lcp_none.txt');
                    // bare->skip flip = the duplicate-kill: evict the CF edge copy NOW (sync),
                    // not via the batched queue a pinned box defers indefinitely.
                    if ($wpc_oldauth383 !== false && function_exists('wpc_lcp_first_auth') && wpc_lcp_first_auth($j['lcp']) === false
                        && function_exists('wpc_lcp_edge_flip_purge')) {
                        wpc_lcp_edge_flip_purge((string) strtok($url, '?'));
                    }
                    if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                        function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced($k, '', 'lcp-inline-land') : wps_ic_cache_integrations::purgeUrlHtml($k, '', ['context' => 'lcp-inline-land']);
                    }
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('lcp-inline-landed', $k, '', ['bytes' => strlen($wpc_lib385)]);
                    }
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('whk-rx', $k, (string) strtok($url, '?'), ['uuid' => substr($uuid, 0, 8)]);
            }
            status_header(200);
            echo 'ok';
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            } else {
                // No detach: never pull inline on a 5s-timeout webhook — cron + nudge.
                if (function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_crit_collect', [$k])) {
                    wpc_pl_sched(time() + 1, 'wpc_crit_collect', [$k]);
                }
                if (function_exists('spawn_cron')) {
                    wpc_spawn_cron();
                }
                exit;
            }
            if (function_exists('set_time_limit')) { @set_time_limit(60); }
            if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
            if (class_exists('wps_criticalCss')) {
                $cc = new wps_criticalCss();
                if ($cc->pullDerivedArtifacts($k, $uuid, true) && function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('whk-landed', $k, '', ['uuid' => substr($uuid, 0, 8)]);
                }
            }
            exit;
        } catch (\Throwable $e) {
            // fail OPEN: a webhook fault answers 200 and leaves the B2 backstop to land it
        }
        status_header(200);
        echo '';
        exit;
    }
    add_action('wp_ajax_wpc_crit_published', 'wpc_crit_webhook_rx');
    add_action('wp_ajax_nopriv_wpc_crit_published', 'wpc_crit_webhook_rx');
}


// ─── AUTO-100 prescriptions consumption (contract v1.1.1 §4, Phase A) ───
// Land-time only: validate → store → apply-route → journal. Render reads the stored
// file with fail-open parse guards; no HTTP ever leaves the render path.
if (!function_exists('wpc_presc_known_classes')) {
    /** Complete 12-class enum (contract delta 1) — a short list rejects every real file. */
    function wpc_presc_known_classes()
    {
        return ['font-metric-swap', 'icon-pop', 'crit-removal', 'used-swap', 'unsized-media',
            'band-restyle', 'js-init-reflow', 'paint-fouc', 'site-native', 'unclassified',
            'third-party-lane', 'delay-created-conceal'];
    }

    /** Envelope + per-item structural validation. Law 12: unknown keys pass through,
     *  unknown classes stay in (apply skips + journals them) — only structurally broken
     *  items are dropped, never the whole file. Returns the sanitized envelope or false. */
    function wpc_prescriptions_valid($j)
    {
        if (!is_array($j) || !isset($j['v']) || !isset($j['prescriptions']) || !is_array($j['prescriptions'])) {
            return false;
        }
        $out = $j;
        $items = [];
        foreach ($j['prescriptions'] as $p) {
            if (count($items) >= 64) {
                break;
            }
            if (!is_array($p) || empty($p['id']) || empty($p['class'])) {
                continue;
            }
            $id = strtolower((string) $p['id']);
            $cl = strtolower((string) $p['class']);
            if (!preg_match('/^[a-f0-9]{6,40}$/', $id) || !preg_match('/^[a-z0-9][a-z0-9-]{1,39}$/', $cl)) {
                continue;
            }
            $p['id'] = $id;
            $p['class'] = $cl;
            $items[] = $p;
        }
        $out['prescriptions'] = $items;
        return $out;
    }

    /** Replace-by-id receipts, single non-autoload option, hard-capped, only-on-change:
     *  an identical row is a no-op (original applied_at receipt preserved — the verdict
     *  loop keys on it); a changed row keeps first_at. Cflog mirror per change (law 7). */
    function wpc_presc_journal_merge($rows)
    {
        if (!is_array($rows) || empty($rows)) {
            return;
        }
        $j = get_option('wpc_presc_journal');
        if (!is_array($j)) {
            $j = [];
        }
        $dirty = false;
        foreach ($rows as $id => $row) {
            $id = (string) $id;
            $row = (array) $row;
            $prev = isset($j[$id]) && is_array($j[$id]) ? $j[$id] : null;
            if ($prev !== null
                && (string) ($prev['status'] ?? '') === (string) ($row['status'] ?? '')
                && (string) ($prev['skipped'] ?? '') === (string) ($row['skipped'] ?? '')
                && (string) ($prev['class'] ?? '') === (string) ($row['class'] ?? '')
                && (string) ($prev['fix'] ?? '') === (string) ($row['fix'] ?? '')) {
                continue; // identical receipt — keep the original timestamps
            }
            $row['applied_at'] = time();
            $row['first_at'] = $prev !== null ? (int) ($prev['first_at'] ?? $prev['applied_at'] ?? time()) : time();
            $j[$id] = $row;
            $dirty = true;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('presc-' . (string) ($row['status'] ?? 'journal'), '', '',
                    ['id' => $id, 'class' => (string) ($row['class'] ?? ''), 'fix' => (string) ($row['fix'] ?? ''), 'skipped' => (string) ($row['skipped'] ?? '')]);
            }
        }
        if (!$dirty) {
            return;
        }
        // Evict junk (skips/reports) before LIVE receipts — stable armed/applied rows
        // keep their original applied_at by design, so age alone would evict exactly
        // the receipts the verdict loop needs most (multi-page sites, 48 ids/URL).
        if (count($j) > 160) {
            uasort($j, function ($a, $b) {
                $w = function ($r) {
                    return in_array((string) ($r['status'] ?? ''), ['armed', 'applied', 'pending-verdict', 'service-applied'], true) ? 1 : 0;
                };
                return ($w($a) - $w($b)) ?: ((int) ($a['applied_at'] ?? 0) - (int) ($b['applied_at'] ?? 0));
            });
            $j = array_slice($j, -160, null, true);
        }
        update_option('wpc_presc_journal', $j, false);
    }

    function wpc_presc_journal_put($id, $row)
    {
        wpc_presc_journal_merge([(string) $id => $row]);
    }

    /** A3 gate-2 STICKY store: ids the runtime re-count found non-unique on the served
     *  DOM. Kept OUT of the churny journal so a forced re-apply cannot re-arm them and
     *  eviction cannot drop the gate state. Each mark is STAMPED with the 12-hex artifact
     *  tag (av = md5(prescriptions body)[:12]) it was computed against, so a mark is
     *  honored only while that exact artifact is live — a republish, a purge-and-refetch,
     *  or a stale in-flight beacon all self-invalidate on av mismatch. id => av. Bounded. */
    function wpc_presc_av_tag($body)
    {
        return substr(md5((string) $body), 0, 12);
    }
    function wpc_presc_notuniq_get()
    {
        $s = get_option('wpc_presc_notuniq');
        return is_array($s) ? $s : [];
    }
    /** Is this id currently refuted FOR the live artifact av? */
    function wpc_presc_notuniq_hit($store, $id, $av)
    {
        $id = strtolower((string) $id);
        return $av !== '' && isset($store[$id]) && (string) $store[$id] === (string) $av;
    }
    function wpc_presc_notuniq_mark($map)
    {
        $s = wpc_presc_notuniq_get();
        $orig = $s;
        foreach ((array) $map as $id => $av) {
            $id = strtolower((string) $id);
            $av = (string) $av;
            if (!preg_match('/^[a-f0-9]{6,40}$/', $id) || !preg_match('/^[a-f0-9]{6,32}$/', $av)) {
                continue;
            }
            // Move to the TAIL on every confirm (unconditional per-id, not gated on a
            // batch change-flag) — eviction is LRU-by-mark, so a repeatedly-refuted id is
            // the LAST to evict, not the first (the insertion-order slice dropped exactly
            // those). The consume lane is ≤1/60s, so the write below is not churn.
            unset($s[$id]);
            $s[$id] = $av;
        }
        if ($s !== $orig) { // value change OR reorder
            if (count($s) > 512) {
                $s = array_slice($s, -512, null, true);
            }
            update_option('wpc_presc_notuniq', $s, false);
        }
    }
    function wpc_presc_notuniq_clear($ids)
    {
        $s = wpc_presc_notuniq_get();
        if (empty($s)) {
            return;
        }
        $d = false;
        foreach ((array) $ids as $id) {
            $id = strtolower((string) $id);
            if (isset($s[$id])) {
                unset($s[$id]);
                $d = true;
            }
        }
        if ($d) {
            update_option('wpc_presc_notuniq', $s, false);
        }
    }

    /** Route each entry per the §4 fix.type→action map; journal every id (law 7) in ONE
     *  batched option write. $av = the artifact tag this envelope was applied from
     *  (a runtime not-unique mark is honored only while its av is the live one). */
    function wpc_presc_apply($urlKey, $env, $av = '')
    {
        $known = wpc_presc_known_classes();
        $armed = 0;
        $pins = [];
        $rows = [];
        $storedPins = get_option('wpc_presc_pins');
        if (!is_array($storedPins)) {
            $storedPins = [];
        }
        // A3 gate-2: ids the served-DOM re-count already refuted stay demoted across
        // every re-apply — a forced refetch of an unchanged file must not re-arm them
        // (the oscillation class). A genuine service rewrite clears them (see fetch).
        $notUniq = function_exists('wpc_presc_notuniq_get') ? wpc_presc_notuniq_get() : [];
        // Same selector alphabet the render leg enforces — land and render must agree,
        // or 'armed' receipts describe rules that never emit.
        $selRx = '/^[A-Za-z0-9 _\-#.:\[\]=>+~()]+$/';
        foreach ((array) ($env['prescriptions'] ?? []) as $p) {
            $id = (string) $p['id'];
            $cl = (string) $p['class'];
            $fx = isset($p['fix']['type']) ? strtolower((string) $p['fix']['type']) : '';
            $base = ['class' => $cl, 'fix' => $fx];
            if (!empty($p['applied_by_service'])) {
                $rows[$id] = $base + ['status' => 'service-applied'];
                continue;
            }
            if (!in_array($cl, $known, true)) {
                $rows[$id] = $base + ['status' => 'skipped', 'skipped' => 'unknown-class'];
                continue;
            }
            if ($fx === 'reserve-rect') {
                // A3 gate 1: only the verdict probe's verified_unique:true stamp arms an
                // emission (the runtime re-count is check #2). Unstamped = pre-verdict file
                // — the poll-#2 rewrite carries the stamps; false = service-demoted.
                if (isset($p['verified_unique']) && $p['verified_unique'] === false) {
                    $rows[$id] = $base + ['status' => 'report', 'skipped' => 'not-unique-verdict'];
                    continue;
                }
                $sel = isset($p['fix']['payload']['sel']) ? trim((string) $p['fix']['payload']['sel']) : '';
                // Same rounding the render leg applies — (int) truncation would journal
                // 'armed' for 2000.5 (render drops it) and 'bad-payload' for 23.7 (render
                // emits it): receipts must describe what actually ships.
                $px  = isset($p['fix']['payload']['min_height_px']) && is_numeric($p['fix']['payload']['min_height_px'])
                    ? (int) round((float) $p['fix']['payload']['min_height_px']) : 0;
                if ($sel === '' || strlen($sel) > 400 || !preg_match($selRx, $sel) || $px < 24 || $px > 2000) {
                    $rows[$id] = $base + ['status' => 'skipped', 'skipped' => 'bad-payload'];
                    continue;
                }
                if (!isset($p['verified_unique']) || $p['verified_unique'] !== true) {
                    $rows[$id] = $base + ['status' => 'pending-verdict'];
                    continue;
                }
                if (wpc_presc_notuniq_hit($notUniq, $id, $av)) {
                    // runtime re-count refuted this id FOR THIS artifact version — do not
                    // re-arm (render suppresses it too). A different av (republish) is not
                    // a hit, so a genuinely re-published/fixed id arms again.
                    $rows[$id] = $base + ['status' => 'skipped', 'skipped' => 'not-unique'];
                    continue;
                }
                $armed++;
                $rows[$id] = $base + ['status' => 'armed'];
            } elseif ($fx === 'lane-demote') {
                $host = isset($p['fix']['payload']['host']) ? strtolower(trim((string) $p['fix']['payload']['host'])) : '';
                $mode = isset($p['fix']['payload']['mode']) ? strtolower(trim((string) $p['fix']['payload']['mode'])) : '';
                // Contract §2 lane names → internal lanes (service-confirmed emitted set:
                // delay-interaction-only, keep-eager, facade). keep-eager = consent pin —
                // surfaced via the eager lane, NEVER wired into excludes (dm law: the
                // built-in consent keeps do the actual eager-keeping); facade = Phase C,
                // report lane. Unknown modes journal as skips.
                $modeMap = ['delay-interaction-only' => 'io', 'io' => 'io', 'delay' => 'delay',
                    'keep-eager' => 'eager', 'facade' => 'report', 'report' => 'report', 'review' => 'report'];
                if ($host === '' || $mode === '' || !function_exists('wpc_auto_3p_lane_set')) {
                    $rows[$id] = $base + ['status' => 'skipped', 'skipped' => ($host === '' || $mode === '') ? 'bad-payload' : 'no-lane-engine'];
                    continue;
                }
                if (!isset($modeMap[$mode])) {
                    $rows[$id] = $base + ['status' => 'skipped', 'skipped' => 'unknown-mode'];
                    continue;
                }
                $m = (isset($p['match']) && is_array($p['match'])) ? $p['match'] : [];
                $lr = wpc_auto_3p_lane_set($host, $modeMap[$mode], $m, 'presc:' . $id);
                // A false receipt blinds the verdict loop — journal what actually happened.
                $rows[$id] = $lr !== false
                    ? $base + ['status' => 'applied']
                    : $base + ['status' => 'skipped', 'skipped' => 'lane-rejected'];
            } elseif ($fx === 'lane-pin') {
                // A2: the chain walk needs wp_scripts, which only exists on a front-end
                // render — store the intent; the delay-v3 pass resolves or degrades there.
                $cand = (isset($p['evidence']['candidates']) && is_array($p['evidence']['candidates']))
                    ? array_slice(array_map('strval', $p['evidence']['candidates']), 0, 6) : [];
                if (empty($cand)) {
                    $rows[$id] = $base + ['status' => 'skipped', 'skipped' => 'no-candidates'];
                    continue;
                }
                // A render-decided pin keeps BOTH its state and its journal receipt —
                // re-journaling 'armed' here would erase the 'applied'/'report' truth.
                $wpc_pst356 = (string) ($storedPins[$id]['state'] ?? '');
                if ($wpc_pst356 === 'resolved' || $wpc_pst356 === 'degraded') {
                    $pins[$id] = $storedPins[$id];
                    continue;
                }
                $pins[$id] = ['cand' => $cand, 'sel' => (string) ($p['selector'] ?? ''), 'state' => 'pending',
                    't' => time(), 'k' => (string) $urlKey, 'cl' => $cl];
                $rows[$id] = $base + ['status' => 'armed'];
            } elseif ($fx === 'bake-geometry' || $fx === 'size-adjust') {
                $rows[$id] = $base + ['status' => 'service-applied'];
            } elseif ($fx === 'report-customer' || $fx === 'surface-review') {
                $rows[$id] = $base + ['status' => 'report'];
            } else {
                $rows[$id] = $base + ['status' => 'skipped', 'skipped' => 'unknown-fix'];
            }
        }
        wpc_presc_journal_merge($rows);
        {
            $cur = $storedPins;
            // pending intents refresh; resolved/degraded verdicts stick (render decided)
            foreach ($pins as $wpc_pid => $wpc_pin) {
                if (!isset($cur[$wpc_pid]) || (($cur[$wpc_pid]['state'] ?? '') === 'pending')) {
                    $cur[$wpc_pid] = $wpc_pin;
                }
            }
            // WITHDRAWAL: this key's stored pins absent from the current envelope (or
            // re-typed away from lane-pin) un-pin — a chain must not stay promoted
            // after the service retreats from the prescription.
            foreach (array_keys($cur) as $wpc_wpid356) {
                if ((string) ($cur[$wpc_wpid356]['k'] ?? '') === (string) $urlKey && !isset($pins[$wpc_wpid356])) {
                    unset($cur[$wpc_wpid356]);
                }
            }
            if (count($cur) > 12) {
                uasort($cur, function ($a, $b) {
                    return (int) ($a['t'] ?? 0) - (int) ($b['t'] ?? 0);
                });
                $cur = array_slice($cur, -12, null, true);
            }
            if ($cur !== $storedPins) {
                update_option('wpc_presc_pins', $cur, false);
            }
        }
        return $armed;
    }

    /** Fetch → validate → store → apply. Buster-always (§1 fetch law: the file is amended
     *  in place post-verdict); ETag is a change marker ONLY — never If-None-Match (delta 3). */
    function wpc_prescriptions_fetch($urlKey, $force = false)
    {
        try {
            if (empty($urlKey) || !defined('WPS_IC_CRITICAL') || !apply_filters('wpc_prescriptions_consume', true)) {
                return false;
            }
            if (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($urlKey)) {
                return false;
            }
            // A FORCED fetch (poll #2 / webhook / due-tick) bypasses the 30s land-side
            // single-flight (a heated land fetch must not swallow the post-verdict pull)
            // but takes a SHARED 15s force-mutex — the cron poll and the traffic tick
            // have disjoint lane throttles and would otherwise double-fetch concurrently.
            if (!$force && get_transient('wpc_prescfetch_' . md5((string) $urlKey))) {
                return false;
            }
            if ($force && get_transient('wpc_prescff_' . md5((string) $urlKey))) {
                return false;
            }
            set_transient('wpc_prescfetch_' . md5((string) $urlKey), 1, 30);
            if ($force) {
                set_transient('wpc_prescff_' . md5((string) $urlKey), 1, 15);
            }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
            $u = trim((string) @file_get_contents($dir . 'prescriptions_url.txt'));
            if ($u === '' || !preg_match('#^https://#i', $u)) {
                return false;
            }
            $u .= (strpos($u, '?') === false ? '?' : '&') . 't=' . time();
            $r = wp_remote_get($u, ['timeout' => 5, 'user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : '']);
            if (is_wp_error($r) || (int) wp_remote_retrieve_response_code($r) !== 200) {
                return false;
            }
            $body = (string) wp_remote_retrieve_body($r);
            if ($body === '' || strlen($body) > 262144) {
                return false;
            }
            $etag = wp_remote_retrieve_header($r, 'etag');
            $etag = is_string($etag) ? trim($etag) : '';
            if (!$force && $etag !== '' && @is_readable($dir . 'prescriptions.json')
                && $etag === trim((string) @file_get_contents($dir . 'prescriptions_etag.txt'))) {
                return true; // unchanged shelf copy — already applied
            }
            $env = wpc_prescriptions_valid(json_decode($body, true));
            if ($env === false) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('presc-invalid', (string) $urlKey, '', ['len' => strlen($body)]);
                }
                return false;
            }
            // CHANGE DETECTION keys on a PERSISTED per-URL body md5 (option, survives the
            // prescriptions.json unlink that our own purge/refetch does) — NOT the on-disk
            // json, whose absence would false-positive "changed" on a byte-identical
            // refetch and spuriously clear the sticky store / fire a purge.
            $wpc_bmd356 = md5($body);
            $wpc_av356  = wpc_presc_av_tag($body);
            $wpc_bstore356 = get_option('wpc_presc_bodymd5');
            if (!is_array($wpc_bstore356)) {
                $wpc_bstore356 = [];
            }
            $changed = ((string) ($wpc_bstore356[(string) $urlKey] ?? '')) !== $wpc_bmd356;
            wpc_crit_meta_write($dir . 'prescriptions.json', $body);
            if ($etag !== '') {
                wpc_crit_meta_write($dir . 'prescriptions_etag.txt', $etag);
            }
            if ($changed) {
                // Re-insert at the tail (LRU): the change-detection baseline for an
                // actively-refetched URL must outlive eviction, else a >cap multi-page
                // site false-positives 'changed' on a byte-identical refetch and fires an
                // avoidable purge. 256 URLs is far beyond any real per-install footprint.
                unset($wpc_bstore356[(string) $urlKey]);
                $wpc_bstore356[(string) $urlKey] = $wpc_bmd356;
                if (count($wpc_bstore356) > 256) {
                    $wpc_bstore356 = array_slice($wpc_bstore356, -256, null, true);
                }
                update_option('wpc_presc_bodymd5', $wpc_bstore356, false);
            }
            // The av stamp makes marks self-invalidate across versions, so an explicit
            // clear is no longer load-bearing — but prune this envelope's stale-av marks
            // on a genuine republish to keep the store tight.
            if ($changed && function_exists('wpc_presc_notuniq_clear')) {
                $wpc_cids356 = [];
                $wpc_nu356 = wpc_presc_notuniq_get();
                foreach ((array) ($env['prescriptions'] ?? []) as $wpc_cp356) {
                    $wpc_cid356 = strtolower((string) ($wpc_cp356['id'] ?? ''));
                    if ($wpc_cid356 !== '' && isset($wpc_nu356[$wpc_cid356]) && (string) $wpc_nu356[$wpc_cid356] !== $wpc_av356) {
                        $wpc_cids356[] = $wpc_cid356;
                    }
                }
                if (!empty($wpc_cids356)) {
                    wpc_presc_notuniq_clear($wpc_cids356);
                }
            }
            $armed = wpc_presc_apply($urlKey, $env, $wpc_av356);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('presc-landed', (string) $urlKey, '', ['n' => count((array) $env['prescriptions']), 'armed' => (int) $armed, 'changed' => (int) $changed]);
            }
            // Purge on ANY content change (coalescer bounds the cost): a rewrite that
            // only DEMOTES must also rotate cached HTML, or stale reserve rules persist.
            if ($changed && function_exists('wpc_land_purge_coalesced')) {
                wpc_land_purge_coalesced($urlKey, '', 'presc-land');
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Land ride: try now if the URL meta is here, and arm poll #2 (+300s) for the
     *  post-verdict rewrite (§1: prescriptions.json is amended after verdict).
     *  CRON-INDEPENDENCE: the due-marker file makes the traffic-driven kick lane a
     *  co-equal executor of poll #2 (wpc_presc_due_tick below) — WP-Cron is an
     *  optimization here, never a dependency (DISABLE_WP_CRON + dead crontab sites). */
    function wpc_presc_on_land($urlKey)
    {
        try {
            if (empty($urlKey) || !apply_filters('wpc_prescriptions_consume', true)) {
                return;
            }
            $dir = defined('WPS_IC_CRITICAL') ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/' : '';
            if ($dir !== '' && @is_readable($dir . 'prescriptions_url.txt')) {
                wpc_prescriptions_fetch($urlKey);
            }
            // Arm the +300s post-verdict poll ONLY when prescriptions are known for this
            // site (url meta present). A prescriptions-less site gets no marker — else the
            // due-tick drips a 120s transient write per trafficked kick forever.
            if ($dir !== '' && is_dir($dir) && @is_readable($dir . 'prescriptions_url.txt')) {
                // Keep the EARLIER due time: a webhook's due-NOW marker must never be
                // pushed out by a land re-arm (cron-dead sites: the marker is the only
                // carrier of the post-verdict pull).
                $wpc_pdcur356 = (int) trim((string) @file_get_contents($dir . 'presc_due.txt'));
                $wpc_pdnew356 = time() + 300;
                if ($wpc_pdcur356 <= 0 || $wpc_pdcur356 > $wpc_pdnew356) {
                    wpc_crit_meta_write($dir . 'presc_due.txt', (string) $wpc_pdnew356);
                }
            }
            if ($dir !== '' && @is_readable($dir . 'prescriptions_url.txt')
                && function_exists('wpc_pl_sched') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_presc_poll', [(string) $urlKey])) {
                wpc_pl_sched(time() + 300, 'wpc_presc_poll', [(string) $urlKey]);
            }
        } catch (\Throwable $e) {
        }
    }

    /** Traffic-driven poll executor: any kick/repull after the due time runs poll #2
     *  inline on a BACKGROUND worker (the kick lane is loopback-driven, cron-free).
     *  Also folds the beacon queue opportunistically — same cron-independence. Bounded:
     *  120s throttle + the fetch's own single-flight; marker clears only on success. */
    function wpc_presc_due_tick($urlKey)
    {
        try {
            if ((string) $urlKey === '' || !defined('WPS_IC_CRITICAL')) {
                return false;
            }
            if (function_exists('wpc_presc_seen_consume_h')) {
                $q = get_option('wpc_presc_seen_queue');
                if (is_array($q) && !empty($q)) {
                    wpc_presc_seen_consume_h();
                }
            }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
            $due = (int) trim((string) @file_get_contents($dir . 'presc_due.txt'));
            if ($due <= 0 || time() < $due) {
                return false;
            }
            if (get_transient('wpc_prescdt_' . md5((string) $urlKey))) {
                return false;
            }
            set_transient('wpc_prescdt_' . md5((string) $urlKey), 1, 120);
            if (!@is_readable($dir . 'prescriptions_url.txt')) {
                // No URL to fetch — retire this marker (the /v2/latest + presc_want lane
                // lands the URL and re-arms). Never drip a per-kick transient forever.
                @unlink($dir . 'presc_due.txt');
                return false;
            }
            if (wpc_prescriptions_fetch($urlKey, true)) {
                @unlink($dir . 'presc_due.txt');
                delete_transient('wpc_prescpw_' . md5((string) $urlKey));
                delete_transient('wpc_prescdf_' . md5((string) $urlKey));
                return true;
            }
            // Duration cap: a marker whose fetch NEVER succeeds (withdrawn shelf URL)
            // retires after 12 consecutive failures — the next land re-arms it. Without
            // this the 120s-throttled retry drips forever on trafficked sites.
            $wpc_df356 = (int) get_transient('wpc_prescdf_' . md5((string) $urlKey)) + 1;
            set_transient('wpc_prescdf_' . md5((string) $urlKey), $wpc_df356, DAY_IN_SECONDS);
            if ($wpc_df356 >= 12) {
                @unlink($dir . 'presc_due.txt');
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('presc-due-retired', (string) $urlKey, '', ['fails' => $wpc_df356]);
                }
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Poll #2 handler: force-refetch the post-verdict rewrite. Every blocked path
     *  DEFERS instead of dropping (bounded by a wave counter) — a swallowed poll loses
     *  the verified_unique stamps until the next full generation. */
    function wpc_presc_poll_h($urlKey)
    {
        try {
            if ((string) $urlKey === '' || !defined('WPS_IC_CRITICAL')) {
                return;
            }
            if (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($urlKey)) {
                return;
            }
            $ctr = 'wpc_prescpw_' . md5((string) $urlKey);
            $defer = function ($secs) use ($urlKey, $ctr) {
                $n = (int) get_transient($ctr);
                if ($n >= 8) {
                    return; // wave cap — stop chaining, the next land re-arms
                }
                set_transient($ctr, $n + 1, HOUR_IN_SECONDS);
                if (function_exists('wpc_pl_sched') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_presc_poll', [(string) $urlKey])) {
                    wpc_pl_sched(time() + $secs, 'wpc_presc_poll', [(string) $urlKey]);
                }
            };
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                $defer(120);
                return;
            }
            if (get_transient('wpc_prescpoll_' . md5((string) $urlKey))) {
                $defer(90);
                return;
            }
            set_transient('wpc_prescpoll_' . md5((string) $urlKey), 1, 60);
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
            if (!@is_readable($dir . 'prescriptions_url.txt')) {
                // Positive-signal path only (webhook wrote presc_want): clear the /v2/latest
                // throttle so the repull lands the URL meta now, not up to 60s later.
                delete_transient('wpc_v2l_' . md5((string) $urlKey));
                if (function_exists('wpc_pl_sched') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_lcp_repull', [(string) $urlKey, 1])) {
                    wpc_pl_sched(time() + 1, 'wpc_lcp_repull', [(string) $urlKey, 1]);
                }
                $defer(120); // re-poll after the repull lands the URL meta
                return;
            }
            if (wpc_prescriptions_fetch($urlKey, true)) {
                @unlink($dir . 'presc_due.txt');
                delete_transient($ctr);
            } else {
                $defer(90);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_presc_poll', 'wpc_presc_poll_h', 10, 1);
}


if (!function_exists('wpc_presc_seen_handler')) {
    /** A3 runtime re-count beacon (loader disagrees with the verdict at apply time).
     *  font_seen shape: accept filter, lock, strict input caps, bounded queue, deferred
     *  consume — zero blocking work on the beacon request. */
    function wpc_presc_seen_handler()
    {
        try {
            if (!apply_filters('wpc_presc_seen_accept', true)) {
                wp_die('', '', 204);
            }
            if (get_transient('wpc_presc_seen_lock')) {
                wp_die('', '', 204);
            }
            set_transient('wpc_presc_seen_lock', 1, 30);
            // One beacon per pageload carries up to 4 comma-joined ids (the 30s lock
            // would drop a second same-visitor beacon).
            $idr = isset($_POST['id']) ? strtolower(trim((string) wp_unslash($_POST['id']))) : '';
            $sk  = isset($_POST['skipped']) ? trim((string) wp_unslash($_POST['skipped'])) : '';
            // av = the artifact tag the verdict was computed against; a mark is honored
            // only while that av is live, so a stale in-flight beacon can't suppress a
            // freshly re-published id.
            $av  = isset($_POST['av']) ? strtolower(trim((string) wp_unslash($_POST['av']))) : '';
            if ($idr === '' || strlen($idr) > 180 || !in_array($sk, ['not-unique'], true)
                || !preg_match('/^[a-f0-9]{6,32}$/', $av)) {
                wp_die('', '', 204);
            }
            $ids = array_slice(array_filter(array_map('trim', explode(',', $idr))), 0, 4);
            $q = get_option('wpc_presc_seen_queue');
            if (!is_array($q)) {
                $q = [];
            }
            $qd = false;
            foreach ($ids as $id) {
                if (!preg_match('/^[a-f0-9]{6,40}$/', $id)) {
                    continue;
                }
                if (!isset($q[$id]) && count($q) < 10) {
                    $q[$id] = $av;
                    $qd = true;
                }
            }
            if ($qd) {
                update_option('wpc_presc_seen_queue', $q, false);
                if (function_exists('wp_schedule_single_event') && !wp_next_scheduled('wpc_presc_seen_consume')) {
                    wp_schedule_single_event(time() + 60, 'wpc_presc_seen_consume');
                }
            }
        } catch (\Throwable $e) {
        }
        wp_die('', '', 204);
    }
    add_action('wp_ajax_wpc_presc_seen', 'wpc_presc_seen_handler');
    add_action('wp_ajax_nopriv_wpc_presc_seen', 'wpc_presc_seen_handler');

    function wpc_presc_seen_consume_h()
    {
        try {
            $q = get_option('wpc_presc_seen_queue');
            delete_option('wpc_presc_seen_queue');
            if (!is_array($q) || empty($q) || !function_exists('wpc_presc_journal_put')) {
                return;
            }
            $mark = [];
            foreach ($q as $id => $av) {
                wpc_presc_journal_put((string) $id, ['status' => 'skipped', 'skipped' => 'not-unique', 'via' => 'runtime-recount']);
                if (preg_match('/^[a-f0-9]{6,32}$/', (string) $av)) {
                    $mark[(string) $id] = (string) $av; // id => artifact tag
                }
            }
            // Sticky gate: survives journal eviction AND forced re-applies; the av stamp
            // makes a stale-artifact verdict self-invalidate.
            if (!empty($mark) && function_exists('wpc_presc_notuniq_mark')) {
                wpc_presc_notuniq_mark($mark);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_presc_seen_consume', 'wpc_presc_seen_consume_h');
}


// ─── used-css scoped purge (v7.10.325, hit-rate law): a used.css artifact is TEMPLATE-
// keyed — purging ALL local HTML for it wiped every page's cache on every used-css land.
// Purge only the pages whose crit dir carries the same tpl (bounded); fall back to the
// old full wipe only when the template family is too large to enumerate. ───
if (!function_exists('wpc_used_css_scoped_purge')) {
    function wpc_used_css_scoped_purge($tplKey)
    {
        try {
            $cap = (int) apply_filters('wpc_used_css_purge_cap', 12);
            if (!is_string($tplKey) || $tplKey === '' || !defined('WPS_IC_CRITICAL')
                || !class_exists('wps_ic_cache_integrations') || !method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                return false;
            }
            $root = rtrim(WPS_IC_CRITICAL, '/') . '/';
            $keys = [];
            foreach (['*/tpl.txt', '*/*/tpl.txt', '*/*/*/tpl.txt'] as $gp) {
                foreach ((array) @glob($root . $gp, GLOB_NOSORT) as $tf) {
                    if (trim((string) @file_get_contents($tf)) !== trim($tplKey)) {
                        continue;
                    }
                    $keys[] = trim(substr(dirname($tf) . '/', strlen($root)), '/');
                    if (count($keys) > $cap) {
                        return false; // family too large — caller falls back to the full wipe
                    }
                }
            }
            if (empty($keys)) {
                return false;
            }
            foreach ($keys as $k) {
                wps_ic_cache_integrations::purgeUrlHtml($k, '', ['context' => 'used-css-tpl', 'warm' => false]);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('used-css-scoped-purge', substr($tplKey, 0, 24), '', ['n' => count($keys)]);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}


// ─── LiteSpeed purge-ping (v7.10.324, hawkeye receipt): background lands cannot emit
// response headers, and LiteSpeed purges ONLY via response headers — so the clean URL
// keeps serving a stale LS HIT that never runs PHP. The ping is one non-blocking
// cache-miss loopback (?wpc_lsp) whose own response carries the purge directive. ───
if (!function_exists('wpc_ls_purge_ping')) {
    function wpc_ls_purge_ping($url)
    {
        try {
            if (empty($url) || !function_exists('wp_remote_get')) {
                return false;
            }
            $wpc_ls324 = defined('LSCWP_V') || class_exists('\LiteSpeed\Purge')
                || (!empty($_SERVER['SERVER_SOFTWARE']) && stripos((string) $_SERVER['SERVER_SOFTWARE'], 'litespeed') !== false);
            if (!$wpc_ls324) {
                return false;
            }
            if (get_transient('wpc_lsp_' . md5((string) $url))) {
                return true; // one ping per URL per window
            }
            set_transient('wpc_lsp_' . md5((string) $url), 1, 20);
            wp_remote_get(add_query_arg('wpc_lsp', (string) time(), (string) $url),
                ['blocking' => false, 'timeout' => 2, 'sslverify' => false]);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('ls-ping-fired', '', (string) $url, []);
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    function wpc_ls_purge_ping_rx()
    {
        try {
            if (empty($_GET['wpc_lsp'])) {
                return;
            }
            // Purges only THIS request's own clean path — the same power any visitor already
            // has via a random query param; rate-limited so probe floods mint nothing.
            $path = (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
            if ($path === '') {
                $path = '/';
            }
            if (get_transient('wpc_lsprx_' . md5($path))) {
                status_header(200);
                echo 'dup';
                exit;
            }
            set_transient('wpc_lsprx_' . md5($path), 1, 10);
            if (function_exists('do_action') && (defined('LSCWP_V') || class_exists('\LiteSpeed\Purge'))
                && function_exists('home_url')) {
                do_action('litespeed_purge_url', home_url($path));
            }
            if (!headers_sent()) {
                header('X-LiteSpeed-Purge: ' . $path, false);
                header('X-LiteSpeed-Cache-Control: no-cache', false);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('ls-ping-rx', '', $path, []);
            }
            status_header(200);
            echo 'ok';
            exit;
        } catch (\Throwable $e) {
            // fail OPEN: never break the render this rode in on
        }
    }
    add_action('init', 'wpc_ls_purge_ping_rx', 0);
}


// ─── Crit pipeline SELF-TEST (v7.10.323): the acceptance drill, productized ───
// Preflight = read-only hop checks. Drill (&drill=1) = windowless: stale-mark the
// homepage (old crit keeps serving), regenerate through the real lanes, measure
// dispatch→land from the B7 stamps, verify the marker cleared; on timeout the marker
// is removed so serving state is exactly as before. One budgeted worker, bounded.
if (!function_exists('wpc_crit_selftest_run')) {
    function wpc_crit_selftest_report_file()
    {
        if (!defined('WPS_IC_CRITICAL')) {
            return '';
        }
        $d = rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/';
        if (!is_dir($d)) {
            @mkdir($d, 0777, true);
        }
        return $d . 'selftest.json';
    }

    function wpc_crit_selftest_run($drill = false)
    {
        $t0 = time();
        $r  = ['t' => $t0, 'mode' => $drill ? 'drill' : 'preflight', 'verdict' => 'FAIL', 'hops' => [], 'latency_s' => null, 'notes' => []];
        try {
            $set = get_option(WPS_IC_SETTINGS);
            $opt = get_option(WPS_IC_OPTIONS);
            $r['hops']['crit_enabled'] = is_array($set) && !empty($set['critical']['css']) && $set['critical']['css'] == '1';
            $r['hops']['api_key'] = is_array($opt) && !empty($opt['api_key']);
            $hd = rtrim(WPS_IC_CRITICAL, '/') . '/.selftest-' . getmypid();
            @mkdir($hd, 0777, true);
            $r['hops']['store_writable'] = function_exists('wpc_crit_meta_write')
                && wpc_crit_meta_write($hd . '/x.txt', 'ok') && trim((string) @file_get_contents($hd . '/x.txt')) === 'ok';
            foreach ((array) @glob($hd . '/*') as $hf) {
                @unlink($hf);
            }
            @rmdir($hd);
            $k    = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
            $dir  = rtrim(WPS_IC_CRITICAL, '/') . '/' . $k . '/';
            $host = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
            if (strpos($host, 'www.') === 0) {
                $host = substr($host, 4);
            }
            $apikey = is_array($opt) && !empty($opt['api_key']) ? (string) $opt['api_key'] : '';
            if ($apikey !== '') {
                $ptr = 'https://critical-css-mc.b-cdn.net/latest/' . substr(sha1($apikey), 0, 16) . '/' . substr(sha1($host . '/'), 0, 16) . '.json?t=' . time();
                $pr  = wp_remote_get($ptr, ['timeout' => 4]);
                $r['hops']['pointer_http'] = is_wp_error($pr) ? 0 : (int) wp_remote_retrieve_response_code($pr);
            }
            if (defined('WPS_IC_CRITICAL_API_URL')) {
                $sr = wp_remote_get(str_replace('/generate', '/status', WPS_IC_CRITICAL_API_URL) . '?uuid=selftest', ['timeout' => 4]);
                $r['hops']['service_http'] = is_wp_error($sr) ? 0 : (int) wp_remote_retrieve_response_code($sr);
            }
            $whk = (int) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/whk_last.txt');
            $r['hops']['webhook_seen_s'] = $whk > 0 ? (time() - $whk) : -1;
            $r['hops']['backoff']      = function_exists('wpc_gen_backoff_active') && wpc_gen_backoff_active();
            $r['hops']['parked_home']  = function_exists('wpc_gen_landless_parked') && wpc_gen_landless_parked($k);
            $r['hops']['detach']       = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
            $r['hops']['crit_on_disk'] = @filesize($dir . 'critical_desktop.css') > 64;
            $r['hops']['last_land_age_s'] = ($wpc_ll = (int) @file_get_contents($dir . 'land_ts.txt')) > 0 ? (time() - $wpc_ll) : -1;

            $preOk = $r['hops']['crit_enabled'] && $r['hops']['api_key'] && $r['hops']['store_writable'];
            if (!$drill) {
                $r['verdict'] = $preOk ? 'PASS-PREFLIGHT' : 'FAIL';
                return $r;
            }
            // DRILL START (zero-held-workers): stale-mark + the real purge-regen lane +
            // probe bursts; the verdict materializes in the stamps and the POLL endpoint
            // computes it. No sleeping worker, works on every SAPI.
            if (!$preOk || !class_exists('wps_criticalCss')) {
                $r['notes'][] = 'preflight-failed';
                return $r;
            }
            wpc_crit_meta_write($dir . 'stale.txt', (string) $t0);
            if (function_exists('wpc_crit_purge_redispatch')) {
                wpc_crit_purge_redispatch();
            }
            $r['verdict'] = 'RUNNING';
            $r['deadline'] = $t0 + (int) apply_filters('wpc_selftest_budget', 150);
            $r['key'] = $k;
            return $r;
        } catch (\Throwable $e) {
            $r['notes'][] = 'exception: ' . substr($e->getMessage(), 0, 120);
            return $r;
        }
    }

    /** Poll-side finalize: PASS/FAIL computed from the on-disk stamps; FAIL restores serving state. */
    function wpc_crit_selftest_finalize($rep)
    {
        try {
            if (!is_array($rep) || ($rep['verdict'] ?? '') !== 'RUNNING' || empty($rep['key'])) {
                return $rep;
            }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $rep['key'] . '/';
            $t0  = (int) ($rep['t'] ?? 0);
            $lts = (int) @file_get_contents($dir . 'land_ts.txt');
            if ($lts >= $t0 && !@is_file($dir . 'stale.txt')) {
                $dts = (int) @file_get_contents($dir . 'dispatch_ts.txt');
                $rep['latency_s'] = ($dts >= $t0) ? max(0, $lts - $dts) : null;
                $rep['hops']['landed'] = true;
                $rep['verdict'] = 'PASS';
            } elseif (time() > (int) ($rep['deadline'] ?? 0)) {
                @unlink($dir . 'stale.txt'); // restore serving state exactly as before
                // The landed artifact may BE the latest published (service debounced the
                // regen — nothing newer exists to land). That is freshness, not failure.
                $wpc_pf325 = '';
                try {
                    $opt325 = get_option(WPS_IC_OPTIONS);
                    $key325 = is_array($opt325) && !empty($opt325['api_key']) ? (string) $opt325['api_key'] : '';
                    $host325 = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
                    if (strpos($host325, 'www.') === 0) {
                        $host325 = substr($host325, 4);
                    }
                    if ($key325 !== '' && $host325 !== '') {
                        $pr325 = wp_remote_get('https://critical-css-mc.b-cdn.net/latest/' . substr(sha1($key325), 0, 16)
                            . '/' . substr(sha1($host325 . '/'), 0, 16) . '.json?t=' . time(), ['timeout' => 4]);
                        if (!is_wp_error($pr325) && (int) wp_remote_retrieve_response_code($pr325) === 200) {
                            $pj325 = json_decode((string) wp_remote_retrieve_body($pr325), true);
                            $wpc_pf325 = is_array($pj325) ? preg_replace('/[^a-f0-9-]/i', '', (string) ($pj325['crit_uuid'] ?? '')) : '';
                        }
                    }
                } catch (\Throwable $e) {
                }
                if ($wpc_pf325 !== '' && $wpc_pf325 === trim((string) @file_get_contents($dir . 'land_uuid.txt'))) {
                    $rep['verdict'] = 'PASS-FRESH';
                    $rep['notes'][] = 'landed artifact IS the latest published (service debounced the regen)';
                } else {
                    $rep['verdict'] = 'FAIL';
                    $rep['notes'][] = 'no-land-within-budget; pointer_http=' . (int) ($rep['hops']['pointer_http'] ?? 0)
                        . ' (200 = service published → plugin pickup at fault; else service generate side)';
                }
            }
            if (($rep['verdict'] ?? '') !== 'RUNNING') {
                $f = wpc_crit_selftest_report_file();
                if ($f !== '') {
                    wpc_crit_meta_write($f, (string) wp_json_encode($rep));
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('selftest', (string) $rep['verdict'], '', ['s' => $rep['latency_s'] ?? null, 'drill' => 1]);
                }
            }
            return $rep;
        } catch (\Throwable $e) {
            return $rep;
        }
    }

    function wpc_crit_selftest_rx()
    {
        try {
            $tok = isset($_GET['t']) && defined('WPC_PERF_DEBUG_TOKEN') && hash_equals((string) WPC_PERF_DEBUG_TOKEN, (string) $_GET['t']);
            if (!(function_exists('current_user_can') && current_user_can('manage_options')) && !$tok) {
                status_header(403);
                echo 'x';
                exit;
            }
            // v7.10.379 TRACE (read-only cross-team debug, &trace=1): this key's landing lifecycle
            // — gen uuid (the service's join key), artifact inventory + quality flags, and the cflog
            // hop timeline with per-hop deltas. Pure disk reads, bounded; no side effects. &k= a
            // non-home URL, &all=1 unfiltered stream, &tail=N rows.
            if (isset($_GET['trace'])) {
                $tk = isset($_GET['k']) && (string) $_GET['k'] !== ''
                    ? ltrim((string) (new wps_ic_url_key())->setup((string) strtok((string) $_GET['k'], '?')), '/')
                    : ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
                if ($tk === '' || (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($tk))) {
                    $tk = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
                }
                $tdir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $tk . '/';
                $tnow = time();
                $trd  = function ($f) { return @is_readable($f) ? (string) @file_get_contents($f) : ''; };
                $tart = function ($file) use ($tdir, $tnow) {
                    $p = $tdir . $file; $ex = @is_readable($p);
                    return ['exists' => (bool) $ex, 'bytes' => $ex ? (int) @filesize($p) : 0, 'age_s' => $ex ? max(0, $tnow - (int) @filemtime($p)) : -1];
                };
                $tdelay = $tart('delay.json');
                if ($tdelay['exists'] && class_exists('wps_ic_js_delay_v3')) {
                    $tdj = json_decode($trd($tdir . 'delay.json'), true);
                    $tdelay['measured'] = is_array($tdj) && wps_ic_js_delay_v3::wpc_delay_measured_shape($tdj);
                }
                $tlcp = $tart('lcp.json'); $tlcp['none'] = @is_readable($tdir . 'lcp_none.txt'); $tlcp['preload'] = [];
                if ($tlcp['exists']) {
                    $tlj = json_decode($trd($tdir . 'lcp.json'), true);
                    if (is_array($tlj) && !empty($tlj['hints']['lcp_preload']) && is_array($tlj['hints']['lcp_preload'])) {
                        foreach ($tlj['hints']['lcp_preload'] as $tlp) {
                            if (!is_array($tlp)) { continue; }
                            $tlcp['preload'][] = ['device' => (string) ($tlp['device'] ?? 'both'),
                                'url_is_authoritative' => array_key_exists('url_is_authoritative', $tlp) ? (bool) $tlp['url_is_authoritative'] : true];
                        }
                    }
                }
                $ttl = []; $tlf = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl' : '';
                if ($tlf !== '' && @is_readable($tlf)) {
                    $ttail = (string) @file_get_contents($tlf, false, null, max(0, (int) @filesize($tlf) - 131072));
                    $tall = isset($_GET['all']); $tprev = null;
                    foreach (explode("\n", $ttail) as $tln) {
                        if ($tln === '') { continue; }
                        $te = json_decode($tln, true);
                        if (!is_array($te) || !isset($te['event'])) { continue; }
                        if (!$tall && (string) ($te['key'] ?? '') !== $tk) { continue; }
                        $trow = ['t' => (int) ($te['t'] ?? 0), 'event' => (string) $te['event'], 'layers' => $te['layers'] ?? []];
                        $trow['dt_s'] = $tprev === null ? 0 : ((int) $trow['t'] - $tprev);
                        $tprev = (int) $trow['t']; $ttl[] = $trow;
                    }
                    $ttl = array_slice($ttl, -(int) (isset($_GET['tail']) ? max(1, min(300, (int) $_GET['tail'])) : 80));
                }
                $twhk = (int) $trd(rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/whk_last.txt');
                $tdts = (int) $trd($tdir . 'dispatch_ts.txt'); $tlts = (int) $trd($tdir . 'land_ts.txt');
                // v7.10.688 — the wire block. §8(c)/§8.1 read wire.json at render and no endpoint
                // surfaced it, so "is the manifest carrying the instruction?" was answerable on
                // neither side of the fence (the .687 Roboto/hero standstill). held_url vs
                // declared_url is the §2 dedupe key — a mismatch means declared-but-not-held
                // (wpc_wire_catchup's case). Per-device counts + the drop families + lcp verdict
                // are the two consumers' entire decision surface. Pure disk reads.
                $twp = function_exists('wpc_wire_provenance') ? wpc_wire_provenance($tk)
                    : ['rev' => 0, 'sig' => '', 'url' => '', 'scope' => 'url', 'material_rev' => 0, 'epoch' => 0];
                $twire = [
                    'declared_rev' => (int) $twp['rev'], 'declared_url' => (string) $twp['url'],
                    'held_url' => trim($trd($tdir . 'wire.held_url')),
                    'material_rev' => (int) $twp['material_rev'], 'epoch' => (int) $twp['epoch'],
                    'scope' => (string) $twp['scope'], 'file' => $tart('wire.json'),
                ];
                $twj = $twire['file']['exists'] ? json_decode($trd($tdir . 'wire.json'), true) : null;
                if (is_array($twj) && !empty($twj['wire']) && is_array($twj['wire'])) {
                    foreach (['mobile', 'desktop'] as $twd) {
                        $twn = (isset($twj['wire'][$twd]) && is_array($twj['wire'][$twd])) ? $twj['wire'][$twd] : [];
                        $twdrops = (isset($twn['drop']) && is_array($twn['drop'])) ? $twn['drop'] : [];
                        $twfams = [];
                        foreach ($twdrops as $twdr) {
                            if (is_array($twdr) && ($twdr['class'] ?? '') === 'font-family' && !empty($twdr['family'])) {
                                $twfams[] = strtolower((string) $twdr['family']);
                            }
                        }
                        $twire[$twd] = [
                            'allow' => isset($twn['allow']) && is_array($twn['allow']) ? count($twn['allow']) : 0,
                            'defer' => isset($twn['defer']) && is_array($twn['defer']) ? count($twn['defer']) : 0,
                            'drop'  => count($twdrops), 'drop_families' => $twfams,
                            'lcp_verdict' => (isset($twn['lcp']['verdict']) && is_string($twn['lcp']['verdict']))
                                ? $twn['lcp']['verdict'] : null,
                        ];
                    }
                }
                status_header(200);
                @header('Content-Type: application/json');
                echo wp_json_encode([
                    'site' => (string) parse_url(home_url('/'), PHP_URL_HOST),
                    'ver'  => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
                    'now'  => $tnow, 'key' => $tk,
                    'uuid' => ['on_disk' => trim($trd($tdir . 'uuid.txt')), 'land_uuid' => trim($trd($tdir . 'land_uuid.txt'))],
                    // Atomic Contract canary surface (v7.10.697): which path owns this key and
                    // which generation the manifest hold names — diff against the service's
                    // pointer read; a mismatch names the owning side in one look.
                    'manifest' => [
                        'held_gen' => trim($trd($tdir . 'manifest.held_gen')),
                        'present'  => @is_readable($tdir . 'manifest.json'),
                        'mode'     => function_exists('wpc_manifest_mode') ? (wpc_manifest_mode($tk) !== '') : false,
                    ],
                    // Verified Copy Contract surface (v7.10.700): last refusal + storm state —
                    // one look answers "why is the edge not filling?" / "did a bad copy sneak in?"
                    'admission' => (array) get_option('wpc_admission_state700', []),
                    'wire' => $twire,
                    'artifacts' => [
                        'crit_desktop'  => $tart('critical_desktop.css'),
                        'crit_mobile'   => $tart('critical_mobile.css'),
                        'crit_combined' => $tart('critical_combined.css'),
                        'delay' => $tdelay, 'lcp' => $tlcp, 'prescriptions' => $tart('prescriptions.json'),
                    ],
                    'locators' => [
                        'delay_url'    => trim($trd($tdir . 'delay_url.txt')) !== '',
                        'lcp_url'      => trim($trd($tdir . 'lcp_url.txt')) !== '',
                        'fonts_url'    => trim($trd($tdir . 'fonts_url.txt')) !== '',
                        'fonts_none'   => @is_readable($tdir . 'fonts_none.txt'),
                        'used_css_url' => trim($trd($tdir . 'used_css_url.txt')) !== '',
                    ],
                    'stamps' => [
                        // raw last-write stamps — NOT gen-scoped (dispatch_ts/land_ts can be
                        // different gens, so a derived delta lies). Authoritative per-gen
                        // dispatch->serve latency is the ledger's (uuid-joined + serve-probe);
                        // honest hop spacing is the timeline dt_s below.
                        'dispatch_ts' => $tdts ?: null, 'land_ts' => $tlts ?: null,
                        'note' => 'last-write, not gen-scoped',
                    ],
                    'webhook' => ['last_seen_s' => $twhk > 0 ? ($tnow - $twhk) : -1, 'dedupe_key' => 'uuid|ts'],
                    'timeline' => $ttl,
                ]);
                exit;
            }
            // REFETCH (v7.10.327): re-consume held/amended shelf artifacts without FTP.
            // &refetch=fonts → drop the local subset+metrics so the fonts leg refetches
            //   the (possibly re-amended) fonts_url on the next kick — crit embeds keep
            //   carrying first paint meanwhile.
            // &refetch=crit → drop land_uuid.txt only; the collector force-pulls the held
            //   uuid and OVERWRITES in place (atomic rename — never a critless window).
            // &refetch=used → clear the one-shot fetch gates and re-pull the stored
            //   used-css bundles inline (amended shelf bytes can differ at identical
            //   length), then rotate page copies exactly like a land would.
            // Optional &k= targets a non-home key (junk-gated); default homepage.
            // &refetch=resync → the one-click full close: stale-mark everything (pages
            //   keep serving), clear every gate/backoff, force a fresh dispatch, pull all
            //   lanes now, and self-schedule follow-up waves for the observe-leg artifacts.
            $wpc_rf327 = isset($_GET['refetch']) ? sanitize_key((string) $_GET['refetch']) : '';
            $wpc_resync334 = ($wpc_rf327 === 'resync');
            if ($wpc_resync334) {
                $wpc_rf327 = 'all';
            }
            if ($wpc_rf327 !== '' && in_array($wpc_rf327, ['fonts', 'crit', 'used', 'all'], true)) {
                $wpc_rk327 = isset($_GET['k']) ? sanitize_text_field((string) $_GET['k'])
                    : ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
                if ($wpc_rk327 === '' || (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($wpc_rk327))) {
                    status_header(200);
                    echo '{"error":"bad-key"}';
                    exit;
                }
                $wpc_rd327 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_rk327 . '/';
                $wpc_done327 = [];
                // Captured BEFORE the crit branch unlinks land_uuid.txt (all-mode ordering);
                // identity law: a bare uuid without its dispatch stamp is borrowed state.
                $wpc_uid333 = trim((string) @file_get_contents($wpc_rd327 . 'land_uuid.txt'));
                if ($wpc_uid333 === '' && @is_readable($wpc_rd327 . 'dispatch_ts.txt')) {
                    $wpc_uid333 = trim((string) @file_get_contents($wpc_rd327 . 'uuid.txt'));
                }
                if ($wpc_resync334) {
                    if (function_exists('wpc_crit_soft_purge_all')) {
                        wpc_crit_soft_purge_all(); // blank-slate that never blanks a page
                    }
                    delete_option('wpc_gen_sf_global');
                    delete_option('wpc_gen_sf_' . $wpc_rk327);
                    delete_transient('wpc_collarm_' . md5((string) $wpc_rk327));
                    delete_transient('wpc_kick_fire_' . md5((string) $wpc_rk327));
                    if (function_exists('wpc_kick_lockfile')) {
                        @unlink(wpc_kick_lockfile($wpc_rk327, 'recv'));
                        @unlink(wpc_kick_lockfile($wpc_rk327, 'fire'));
                    }
                    // Pull Latest force-gens with sync=1 (v7.10.357) — threaded through the
                    // redispatch cron arg + detached runner (NOT a shared transient, which
                    // leaked the blocking full-sync onto visitor kicks). sync=1 makes the
                    // service SKIP its template cache and grind a genuinely fresh, current-
                    // schema gen (ceiling / hints / verified-unique prescriptions) — without
                    // it, resync re-pulls the SAME stale tpl-cached artifact (staging receipt:
                    // 48's morning gen had no ceiling until a sync=1 gen busted the cache).
                    if (function_exists('wpc_crit_purge_redispatch')) {
                        wpc_crit_purge_redispatch(true); // operator force-sync, detached runner
                    }
                    foreach ([150, 330] as $wpc_wv334) {
                        wpc_pl_sched(time() + $wpc_wv334, 'wpc_crit_resync_wave', [$wpc_rk327, (int) $wpc_wv334]);
                    }
                    $wpc_done327[] = 'resync-armed';
                }
                if ($wpc_rf327 === 'fonts' || $wpc_rf327 === 'all') {
                    @unlink($wpc_rd327 . 'font-subsets.css');
                    @unlink($wpc_rd327 . 'font-metrics.json');
                    $wpc_done327[] = 'fonts';
                }
                if ($wpc_rf327 === 'used' || $wpc_rf327 === 'all') {
                    // prescriptions re-consume rides the used refetch: drop the stored file
                    // + gates so the next kick pulls the (possibly re-amended) shelf copy.
                    @unlink($wpc_rd327 . 'prescriptions.json');
                    @unlink($wpc_rd327 . 'prescriptions_etag.txt');
                    delete_transient('wpc_prescfetch_' . md5((string) $wpc_rk327));
                    delete_transient('wpc_prescpoll_' . md5((string) $wpc_rk327));
                    if (function_exists('wpc_presc_on_land')) {
                        wpc_presc_on_land($wpc_rk327);
                    }
                    $wpc_done327[] = 'presc';
                }
                $wpc_landed327 = null;
                if ($wpc_rf327 === 'crit' || $wpc_rf327 === 'all') {
                    @unlink($wpc_rd327 . 'land_uuid.txt');
                    // Manifest-path escape hatch (v7.10.697): the crit drill also drops the
                    // manifest hold, reverting the key to the legacy flow until the next
                    // generation_complete webhook re-establishes it.
                    @unlink($wpc_rd327 . 'manifest.held_gen');
                    @unlink($wpc_rd327 . 'manifest.json');
                    if (function_exists('wpc_crit_collector_arm')) {
                        wpc_crit_collector_arm($wpc_rk327);
                    }
                    if (function_exists('wpc_crit_collect_now')) {
                        $wpc_landed327 = (bool) wpc_crit_collect_now($wpc_rk327, 1); // one bounded inline probe
                    }
                    $wpc_done327[] = 'crit';
                }
                if ($wpc_rf327 === 'used' || $wpc_rf327 === 'all') {
                    // URL metas are ARTIFACTS (purges delete them; identity survives) — when
                    // absent, derive the shelf URLs. POINTER FIRST: a refetch means "give me
                    // what the service currently publishes", and staged corrections can live
                    // on a held uuid the local stamps have already moved past (hawkeye
                    // receipt: stamps → morning gen, corrected used.css → pointer's uuid).
                    // Local stamps are the fallback when the pointer is unreachable.
                    // NOTE: stamps were captured early ($wpc_uid333) — the crit branch above
                    // unlinks land_uuid.txt in 'all' mode.
                    $wpc_ub333 = '';
                    $wpc_phu347 = '';
                    $wpc_bsrc347 = '';
                    {
                        $wpc_opts333 = get_option(WPS_IC_OPTIONS);
                        $wpc_ak333 = is_array($wpc_opts333) && !empty($wpc_opts333['api_key']) ? (string) $wpc_opts333['api_key'] : '';
                        $wpc_cu333 = trim((string) @file_get_contents($wpc_rd327 . 'url.txt'));
                        if ($wpc_cu333 === '' && ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/') === ltrim((string) $wpc_rk327, '/')) {
                            $wpc_cu333 = home_url('/'); // A8 law: home fallback only for the home key
                        }
                        if ($wpc_ak333 !== '' && $wpc_cu333 !== '') {
                            if (strpos($wpc_cu333, '://') === false) {
                                $wpc_cu333 = 'https://' . ltrim($wpc_cu333, '/');
                            }
                            $wpc_ph333 = strtolower((string) parse_url($wpc_cu333, PHP_URL_HOST));
                            if (strpos($wpc_ph333, 'www.') === 0) {
                                $wpc_ph333 = substr($wpc_ph333, 4);
                            }
                            $wpc_pp333 = (string) parse_url($wpc_cu333, PHP_URL_PATH);
                            if ($wpc_ph333 !== '') {
                                $wpc_pt333 = 'https://critical-css-mc.b-cdn.net/latest/' . substr(sha1($wpc_ak333), 0, 16) . '/' . substr(sha1($wpc_ph333 . ($wpc_pp333 === '' ? '/' : $wpc_pp333)), 0, 16) . '.json?t=' . time();
                                $wpc_pg333 = wp_remote_get($wpc_pt333, ['timeout' => 4, 'user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : '']);
                                if (!is_wp_error($wpc_pg333) && (int) wp_remote_retrieve_response_code($wpc_pg333) === 200) {
                                    $wpc_pj333 = json_decode((string) wp_remote_retrieve_body($wpc_pg333), true);
                                    $wpc_pv333 = is_array($wpc_pj333) ? preg_replace('/[^a-f0-9-]/i', '', (string) ($wpc_pj333['crit_uuid'] ?? '')) : '';
                                    if (strlen($wpc_pv333) >= 8) {
                                        $wpc_ub333 = 'https://critical-css-mc.b-cdn.net/' . substr($wpc_pv333, 0, 4) . '/' . $wpc_pv333;
                                        $wpc_bsrc347 = 'ptr';
                                        if (is_array($wpc_pj333) && (int) ($wpc_pj333['has_used_css'] ?? 0) === 1) {
                                            $wpc_phu347 = $wpc_ub333;
                                            $wpc_bsrc347 = 'ptr-used';
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($wpc_ub333 === '' && preg_match('/^[0-9a-f-]{8,}$/i', (string) $wpc_uid333)) {
                        $wpc_ub333 = 'https://critical-css-mc.b-cdn.net/' . substr($wpc_uid333, 0, 4) . '/' . $wpc_uid333;
                        $wpc_bsrc347 = 'stamp';
                    }
                    // Crit lands are ASYNC — during the click land_uuid.txt still holds the
                    // PREVIOUS gen, so a pointer that advertises used files (has_used_css:1,
                    // truthful since the kick carries the capability) outranks the live stamp.
                    // The stamp still wins when the pointer lacks used files or timed out.
                    $wpc_ulive342 = trim((string) @file_get_contents($wpc_rd327 . 'land_uuid.txt'));
                    if ($wpc_phu347 === '' && preg_match('/^[0-9a-f-]{8,}$/i', $wpc_ulive342)) {
                        $wpc_ub333 = 'https://critical-css-mc.b-cdn.net/' . substr($wpc_ulive342, 0, 4) . '/' . $wpc_ulive342;
                        $wpc_bsrc347 = 'land';
                    }
                    // Operator override (temporary until the service advertises used_css=1 on
                    // the kick so every gen mints its own bundle): &used_uuid=<uuid> pulls the
                    // known-good used bundle by uuid — a fresh gen with real used files later
                    // supersedes it organically. One-shot, no persistence, no FTP.
                    $wpc_uovr343 = isset($_GET['used_uuid']) ? preg_replace('/[^0-9a-f-]/i', '', (string) $_GET['used_uuid']) : '';
                    if (strlen($wpc_uovr343) >= 8) {
                        $wpc_ub333 = 'https://critical-css-mc.b-cdn.net/' . substr($wpc_uovr343, 0, 4) . '/' . $wpc_uovr343;
                        $wpc_bsrc347 = 'override';
                    }
                    $wpc_ut327 = '';
                    foreach (['tpl.txt', 'used_tpl.txt'] as $wpc_tf327) {
                        $wpc_tv327 = trim((string) @file_get_contents($wpc_rd327 . $wpc_tf327));
                        if (function_exists('wpc_used_css_key_valid') && wpc_used_css_key_valid($wpc_tv327)) {
                            $wpc_ut327 = $wpc_tv327;
                            break;
                        }
                    }
                    if ($wpc_ut327 === '') {
                        // Page-dir identity missing (pre-.327 installs / service rows with
                        // tpl_key=None): recover the key from the newest store file itself.
                        $wpc_newest327 = 0;
                        foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/used-css/tpl-*.css') as $wpc_sf327) {
                            $wpc_bn327 = basename($wpc_sf327);
                            if (!preg_match('/^tpl-([0-9a-f]{16})\.css$/', $wpc_bn327, $wpc_m327)) {
                                continue;
                            }
                            $wpc_mt327 = (int) @filemtime($wpc_sf327);
                            if ($wpc_mt327 > $wpc_newest327) {
                                $wpc_newest327 = $wpc_mt327;
                                $wpc_ut327 = 'tpl:' . $wpc_m327[1];
                            }
                        }
                    }
                    $wpc_upulled327 = [];
                    $wpc_udbg341 = ['tpl' => $wpc_ut327, 'base' => substr((string) $wpc_ub333, 0, 90), 'base_src' => $wpc_bsrc347];
                    if ($wpc_ut327 !== '' && function_exists('wpc_used_css_fetch') && function_exists('wpc_used_css_path')) {
                        $wpc_sfx333 = ['union' => '.used.css', 'mobile' => '.used-mobile.css', 'desktop' => '.used-desktop.css'];
                        foreach (['union' => ['', 'used_css_url.txt'], 'mobile' => ['mobile', 'used_css_mobile_url.txt'], 'desktop' => ['desktop', 'used_css_desktop_url.txt']] as $wpc_lb327 => $wpc_pair327) {
                            $wpc_mraw341 = trim((string) @file_get_contents($wpc_rd327 . $wpc_pair327[1]));
                            $wpc_uu327 = $wpc_mraw341;
                            if ($wpc_uu327 !== '' && !preg_match('#^https?://#i', $wpc_uu327)) {
                                $wpc_uu327 = ''; // 'None'/junk meta (service-side null serialization) — derive instead
                            }
                            $wpc_msrc341 = $wpc_uu327 !== '' ? 'meta' : 'none';
                            if ($wpc_uu327 !== '' && $wpc_ub333 !== '' && strpos($wpc_uu327, basename($wpc_ub333)) === false) {
                                $wpc_uu327 = ''; // meta uuid ≠ the generation that just landed — stale by definition
                                $wpc_msrc341 = 'meta-stale';
                            }
                            if ($wpc_uu327 === '' && $wpc_ub333 !== '') {
                                $wpc_uu327 = $wpc_ub333 . $wpc_sfx333[$wpc_lb327] . '?t=' . time(); // ?t= — Bunny negative-caches premature 404s
                                $wpc_msrc341 = ($wpc_msrc341 === 'meta-stale') ? 'derived-over-stale-meta' : 'derived';
                            }
                            $wpc_pp327 = $wpc_uu327 !== '' ? wpc_used_css_path($wpc_ut327, $wpc_pair327[0]) : '';
                            if ($wpc_pp327 === '') {
                                continue;
                            }
                            delete_transient('wpc_ucsr266_' . md5($wpc_pp327));
                            delete_transient('wpc_ucsp_' . md5($wpc_pp327));
                            $wpc_mtb341 = (int) @filemtime($wpc_pp327);
                            $wpc_why341 = '';
                            $wpc_upulled327[$wpc_lb327] = (bool) wpc_used_css_fetch($wpc_uu327, $wpc_ut327, $wpc_pair327[0], $wpc_why341);
                            $wpc_udbg341[$wpc_lb327] = [
                                'src' => $wpc_msrc341, 'meta_raw' => substr($wpc_mraw341, 0, 70),
                                'url' => substr((string) $wpc_uu327, 0, 110), 'why' => (string) $wpc_why341,
                                'ok' => $wpc_upulled327[$wpc_lb327],
                                'mtime_before' => $wpc_mtb341, 'mtime_after' => (int) @filemtime($wpc_pp327),
                            ];
                            // Unconditional on success — a pointer-first pull that leaves the
                            // OLD uuid in the meta gets reverted by the next meta-driven
                            // re-pull (waves/kick legs fetch the stale address and overwrite).
                            if ($wpc_upulled327[$wpc_lb327] && $wpc_bsrc347 !== 'override' && function_exists('wpc_crit_meta_write')) {
                                // override stays one-shot — persisting it would let repull
                                // waves re-fetch a hand-pasted uuid forever.
                                wpc_crit_meta_write($wpc_rd327 . $wpc_pair327[1], (string) strtok($wpc_uu327, '?'));
                            }
                        }
                    }
                    if (!empty(array_filter($wpc_upulled327))) {
                        if (!function_exists('wpc_used_css_scoped_purge') || !wpc_used_css_scoped_purge($wpc_ut327)) {
                            try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
                        }
                    }
                    $wpc_done327[] = 'used:' . ($wpc_ut327 === '' ? 'no-tpl-key' : (empty($wpc_upulled327) ? 'no-urls' : implode(',', array_keys(array_filter($wpc_upulled327)))));
                }
                delete_transient('wpc_repull_kick_' . md5($wpc_rk327));
                if (function_exists('wpc_repull_kick_now')) {
                    wpc_repull_kick_now($wpc_rk327); // fonts leg refetches on the kicked repull
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('refetch', $wpc_rk327, '', ['what' => implode(',', $wpc_done327), 'landed' => $wpc_landed327]);
                }
                status_header(200);
                header('Content-Type: application/json');
                echo wp_json_encode(['refetch' => $wpc_done327, 'key' => $wpc_rk327, 'crit_repulled' => $wpc_landed327,
                    'used_dbg' => isset($wpc_udbg341) ? $wpc_udbg341 : null,
                    'note' => 'fonts re-land async within ~60s of the kick; poll perf-debug fonts/land lines']);
                exit;
            }

            $f = wpc_crit_selftest_report_file();
            if (empty($_GET['run'])) {
                // POLL: finalizes a RUNNING drill from the stamps (and self-restores on timeout).
                $rep = json_decode(($f !== '' && @is_readable($f)) ? (string) @file_get_contents($f) : '', true);
                if (is_array($rep) && ($rep['verdict'] ?? '') === 'RUNNING' && function_exists('wpc_crit_selftest_finalize')) {
                    $rep = wpc_crit_selftest_finalize($rep);
                }
                status_header(200);
                header('Content-Type: application/json');
                echo is_array($rep) ? wp_json_encode($rep) : '{"verdict":"NEVER-RUN"}';
                exit;
            }
            $prev = json_decode(($f !== '' && @is_readable($f)) ? (string) @file_get_contents($f) : '', true);
            if (is_array($prev) && ($prev['verdict'] ?? '') === 'RUNNING' && time() <= (int) ($prev['deadline'] ?? 0)) {
                status_header(200);
                echo '{"verdict":"ALREADY-RUNNING"}';
                exit;
            }
            // Start (preflight or drill) is a sub-second request — no worker held, any SAPI.
            $rep = wpc_crit_selftest_run(!empty($_GET['drill']));
            if ($f !== '') {
                wpc_crit_meta_write($f, (string) wp_json_encode($rep));
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('selftest', (string) $rep['verdict'], '', ['drill' => empty($_GET['drill']) ? 0 : 1]);
            }
            status_header(200);
            header('Content-Type: application/json');
            echo wp_json_encode($rep);
            exit;
        } catch (\Throwable $e) {
            exit;
        }
    }
    add_action('wp_ajax_wpc_crit_selftest', 'wpc_crit_selftest_rx');
    add_action('wp_ajax_nopriv_wpc_crit_selftest', 'wpc_crit_selftest_rx');
}


if (!function_exists('wpc_cflog_read')) {
    function wpc_cflog_read($n = 40)
    {
        $wpc_lf = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl' : '';
        if ($wpc_lf !== '' && @is_readable($wpc_lf)) {
            $wpc_sz   = (int) @filesize($wpc_lf);
            $wpc_tail = (string) @file_get_contents($wpc_lf, false, null, max(0, $wpc_sz - 32768));
            $out = [];
            foreach (array_filter(explode("\n", $wpc_tail)) as $wpc_ln) {
                $e = json_decode($wpc_ln, true);
                if (is_array($e)) {
                    $out[] = $e;
                }
            }
            if (!empty($out)) {
                return array_slice($out, -$n);
            }
        }
        return array_slice((array) get_option('wpc_cache_first_log', []), -$n);
    }
}


if (!function_exists('wpc_cflog_viewer')) {
    function wpc_cflog_viewer()
    {
        if (!current_user_can('manage_options') && !current_user_can('manage_wpc_settings')) {
            wp_send_json_error('forbidden', 403);
        }
        $log = function_exists('wpc_cflog_read') ? wpc_cflog_read(40) : (array) get_option('wpc_cache_first_log', []);
        foreach ($log as &$e) {
            if (isset($e['t'])) {
                $e['time'] = gmdate('H:i:s', (int) $e['t']) . 'Z';
            }
        }
        wp_send_json_success(array_reverse($log));
    }
    add_action('wp_ajax_wpc_cflog', 'wpc_cflog_viewer');

    // URLs only — no secrets. Filter-gated for the debug window; flip off after the soak.
    if (apply_filters('wpc_cflog_nopriv', true)) {
        add_action('wp_ajax_nopriv_wpc_cflog', function () {
            $log = function_exists('wpc_cflog_read') ? wpc_cflog_read(40) : (array) get_option('wpc_cache_first_log', []);
            foreach ($log as &$e) {
                if (isset($e['t'])) {
                    $e['time'] = gmdate('H:i:s', (int) $e['t']) . 'Z';
                }
            }
            wp_send_json_success(array_reverse($log));
        });
    }
}


if (!function_exists('wpc_font_metrics_from_evidence')) {
    function wpc_font_metrics_from_evidence($table)
    {
        try {
            if (!is_array($table) || !class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
                return $table;
            }
            static $wpc_ev = null;
            if ($wpc_ev === null) {
                $wpc_ev = [];
                $k = (new wps_ic_url_key())->setup('');
                $f = $k ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $k . '/font-metrics.json' : '';
                if ($f && @is_readable($f)) {
                    $j = json_decode((string) @file_get_contents($f), true);
                    // v7.10.593 — SECOND READER OF THE SAME ARTIFACT, and the one with no
                    // validation window: it merges the decoded object straight into the metrics
                    // table. Unwrapped, a stamped envelope would enter as families named v/uuid/
                    // tpl_key/generated_at plus one called "families". It also runs at priority 5,
                    // ahead of the reader at 10, so fixing only that one would have left this the
                    // live path. function_exists guard: the helper is defined in its own block.
                    if (is_array($j) && function_exists('wpc_font_metrics_unwrap593')) {
                        $j = wpc_font_metrics_unwrap593($j, (string) $k);
                    }
                    if (is_array($j)) {
                        $wpc_ev = $j;
                    }
                }
            }
            return array_merge($wpc_ev, $table);
        } catch (\Throwable $e) {
            return $table;
        }
    }
    add_filter('wpc_font_fallback_metrics', 'wpc_font_metrics_from_evidence', 5);
}


// The census metrics above are the precise source, but they only generate when an observation ran


if (!function_exists('wpc_static_font_metrics')) {
    function wpc_static_font_metrics()
    {
        return [

            'Roboto'          => ['size-adjust' => '100.1%', 'ascent-override' => '92.8%',  'descent-override' => '24.4%', 'line-gap-override' => '0%'],
            'Inter'           => ['size-adjust' => '107.0%', 'ascent-override' => '90.4%',  'descent-override' => '22.5%', 'line-gap-override' => '0%'],
            'Open Sans'       => ['size-adjust' => '105.3%', 'ascent-override' => '101.3%', 'descent-override' => '27.8%', 'line-gap-override' => '0%'],
            'Lato'            => ['size-adjust' => '97.4%',  'ascent-override' => '99.1%',  'descent-override' => '21.4%', 'line-gap-override' => '0%'],
            'Montserrat'      => ['size-adjust' => '112.3%', 'ascent-override' => '86.3%',  'descent-override' => '22.4%', 'line-gap-override' => '0%'],
            'Poppins'         => ['size-adjust' => '112.2%', 'ascent-override' => '93.8%',  'descent-override' => '31.3%', 'line-gap-override' => '0%'],
            'Raleway'         => ['size-adjust' => '104.8%', 'ascent-override' => '89.9%',  'descent-override' => '22.4%', 'line-gap-override' => '0%'],
            'Nunito'          => ['size-adjust' => '101.4%', 'ascent-override' => '99.9%',  'descent-override' => '34.9%', 'line-gap-override' => '0%'],
            'Nunito Sans'     => ['size-adjust' => '102.6%', 'ascent-override' => '101.4%', 'descent-override' => '35.4%', 'line-gap-override' => '0%'],
            'Rubik'           => ['size-adjust' => '104.7%', 'ascent-override' => '89.2%',  'descent-override' => '23.9%', 'line-gap-override' => '0%'],
            'Work Sans'       => ['size-adjust' => '106.0%', 'ascent-override' => '87.6%',  'descent-override' => '22.9%', 'line-gap-override' => '0%'],
            'DM Sans'         => ['size-adjust' => '104.0%', 'ascent-override' => '94.4%',  'descent-override' => '29.5%', 'line-gap-override' => '0%'],
            'Manrope'         => ['size-adjust' => '106.4%', 'ascent-override' => '100.5%', 'descent-override' => '28.2%', 'line-gap-override' => '0%'],
            'Mulish'          => ['size-adjust' => '104.0%', 'ascent-override' => '100.4%', 'descent-override' => '25.1%', 'line-gap-override' => '0%'],
            'Karla'           => ['size-adjust' => '100.7%', 'ascent-override' => '97.1%',  'descent-override' => '24.2%', 'line-gap-override' => '0%'],
            'Source Sans Pro' => ['size-adjust' => '94.0%',  'ascent-override' => '104.6%', 'descent-override' => '28.6%', 'line-gap-override' => '0%'],
            'Source Sans 3'   => ['size-adjust' => '94.2%',  'ascent-override' => '104.8%', 'descent-override' => '28.6%', 'line-gap-override' => '0%'],
            'Oswald'          => ['size-adjust' => '78.0%',  'ascent-override' => '119.1%', 'descent-override' => '28.9%', 'line-gap-override' => '0%'],
            'Josefin Sans'    => ['size-adjust' => '92.5%',  'ascent-override' => '108.0%', 'descent-override' => '34.0%', 'line-gap-override' => '0%'],
            'Quicksand'       => ['size-adjust' => '101.5%', 'ascent-override' => '99.0%',  'descent-override' => '24.8%', 'line-gap-override' => '0%'],
            'Barlow'          => ['size-adjust' => '96.5%',  'ascent-override' => '100.5%', 'descent-override' => '21.0%', 'line-gap-override' => '0%'],
            'Figtree'         => ['size-adjust' => '102.5%', 'ascent-override' => '93.6%',  'descent-override' => '24.6%', 'line-gap-override' => '0%'],

            'Playfair Display'  => ['local' => 'Georgia', 'size-adjust' => '104.6%', 'ascent-override' => '96.7%',  'descent-override' => '24.5%', 'line-gap-override' => '0%'],
            'Merriweather'      => ['local' => 'Georgia', 'size-adjust' => '107.4%', 'ascent-override' => '91.3%',  'descent-override' => '25.3%', 'line-gap-override' => '0%'],
            'Lora'              => ['local' => 'Georgia', 'size-adjust' => '100.7%', 'ascent-override' => '99.8%',  'descent-override' => '27.2%', 'line-gap-override' => '0%'],
            'PT Serif'          => ['local' => 'Georgia', 'size-adjust' => '99.6%',  'ascent-override' => '104.0%', 'descent-override' => '28.6%', 'line-gap-override' => '0%'],
            'Libre Baskerville' => ['local' => 'Georgia', 'size-adjust' => '106.9%', 'ascent-override' => '88.7%',  'descent-override' => '24.5%', 'line-gap-override' => '0%'],
            'EB Garamond'       => ['local' => 'Georgia', 'size-adjust' => '94.0%',  'ascent-override' => '103.5%', 'descent-override' => '27.2%', 'line-gap-override' => '0%'],
        ];
    }
    function wpc_font_metrics_static_defaults($table)
    {
        try {
            if (!apply_filters('wpc_font_fallback_metrics_static', true) || !is_array($table)) {
                return $table;
            }

            return array_merge(wpc_static_font_metrics(), $table);
        } catch (\Throwable $e) {
            return $table;
        }
    }
    add_filter('wpc_font_fallback_metrics', 'wpc_font_metrics_static_defaults', 8);


    function wpc_font_catalog_metrics($famLc)
    {
        static $cat = null;
        if (!apply_filters('wpc_font_catalog', true)) {
            return null;
        }
        if ($cat === null) {
            $cat = [];
            $f = defined('WPS_IC_DIR') ? WPS_IC_DIR . 'assets/fonts-fallback-metrics.json' : '';
            if ($f !== '' && @is_readable($f)) {
                $j = json_decode((string) @file_get_contents($f), true);
                if (is_array($j)) { $cat = $j; }
            }
        }
        $famLc = strtolower(trim((string) $famLc));
        if ($famLc === '' || !isset($cat[$famLc]) || !is_array($cat[$famLc])) {
            return null;
        }
        $e = $cat[$famLc];
        $frame = 'Arial';
        if (isset($e[4])) {
            if ($e[4] === 'G') { $frame = 'Georgia'; }
            elseif ($e[4] === 'C') { $frame = 'Courier New'; }
        }
        $m = ['local' => $frame];
        foreach ([0 => 'size-adjust', 1 => 'ascent-override', 2 => 'descent-override', 3 => 'line-gap-override'] as $i => $k) {
            if (isset($e[$i]) && is_numeric($e[$i]) && $e[$i] >= 0) { $m[$k] = $e[$i] . '%'; }
        }
        return $m;
    }
}


if (!function_exists('wpc_font_seen_handler')) {
    function wpc_font_seen_handler()
    {
        try {
            if (!apply_filters('wpc_font_seen_accept', true)) { wp_die('', '', 204); }
            if (get_transient('wpc_font_seen_lock')) { wp_die('', '', 204); }
            set_transient('wpc_font_seen_lock', 1, 30);
            $u = isset($_POST['u']) ? trim((string) wp_unslash($_POST['u'])) : '';
            if ($u === '' || strlen($u) > 700
                || !preg_match('#^https://fonts\.(googleapis\.com|bunny\.net)/css2?\?[A-Za-z0-9&=:;,+%@._\-]+$#', $u)) {
                wp_die('', '', 204);
            }
            $q = get_option('wpc_font_seen_queue');
            if (!is_array($q)) { $q = []; }
            if (!in_array($u, $q, true) && count($q) < 10) {
                $q[] = $u;
                update_option('wpc_font_seen_queue', $q, false);
                if (function_exists('wp_schedule_single_event') && !wp_next_scheduled('wpc_font_rescan')) {
                    wp_schedule_single_event(time() + 60, 'wpc_font_rescan');
                }
            }
        } catch (\Throwable $e) {
        }
        wp_die('', '', 204);
    }
    add_action('wp_ajax_wpc_font_seen', 'wpc_font_seen_handler');
    add_action('wp_ajax_nopriv_wpc_font_seen', 'wpc_font_seen_handler');
}


if (!function_exists('wpc_font_rescan_handler')) {
    function wpc_font_rescan_handler()
    {
        try {
            if (!class_exists('wps_ic_fonts') && defined('WPS_IC_DIR')) {
                @include_once WPS_IC_DIR . 'addons/fonts/fonts.class.php';
            }
            if (!class_exists('wps_ic_fonts') || !function_exists('home_url')) {
                return;
            }
            if (function_exists('wpc_bg_slot_take') && !wpc_bg_slot_take('font-rescan')) {
                if (function_exists('wp_schedule_single_event')) {
                    wp_schedule_single_event(time() + 180, 'wpc_font_rescan');
                }
                return;
            }
            $fonts = new wps_ic_fonts();
            $url   = home_url('/');
            $found = $fonts->scanForFonts($fonts->callAPI($url));
            if ((empty($found['googleFontsStylesheets']) && empty($found['gstaticUrls']))
                && method_exists($fonts, 'localScan') && apply_filters('wpc_font_local_scan', true)) {
                $found = $fonts->localScan($url);
            }


            $wpc_q45 = get_option('wpc_font_seen_queue');
            if (is_array($wpc_q45) && !empty($wpc_q45)) {
                $found['googleFontsStylesheets'] = array_values(array_unique(array_merge(
                    isset($found['googleFontsStylesheets']) && is_array($found['googleFontsStylesheets']) ? $found['googleFontsStylesheets'] : [],
                    array_slice($wpc_q45, 0, 10)
                )));
                delete_option('wpc_font_seen_queue');
            }
            if (!empty($found['googleFontsStylesheets'])) {
                $fonts->readGoogleStylesheet($found);
                // Localized sheets only reach visitors once the cached HTML that still
                // carries the remote links is dropped.
                if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeAll')) {
                    wps_ic_cache_integrations::purgeAll(false, false, true, false);
                }
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_font_rescan', 'wpc_font_rescan_handler');
}


if (!function_exists('wpc_font_inline_localize_cron_handler')) {
    function wpc_font_inline_localize_cron_handler()
    {
        try {
            if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
            if (function_exists('set_time_limit')) { @set_time_limit(120); }
            if (!class_exists('wps_ic_fonts') && defined('WPS_IC_DIR')) {
                @include_once WPS_IC_DIR . 'addons/fonts/fonts.class.php';
            }
            if (!class_exists('wps_ic_fonts')) {
                return;
            }
            $fonts = new wps_ic_fonts();
            $n     = (int) $fonts->localizeInlineFonts('');
            if ($n > 0) {
                if (!get_transient('wpc_font_purgeall_day')
                    && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                    set_transient('wpc_font_purgeall_day', 1, DAY_IN_SECONDS);
                    try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('font-inline-localized', '', '', ['n' => $n]);
                }
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_font_inline_localize_cron', 'wpc_font_inline_localize_cron_handler');
}


// ≤6 files ≤64KB, woff2 only, crit-MC host only. Kill: wpc_fonts_artifact_consume / wpc_atf_subset_inline.
if (!function_exists('wpc_consume_wire_artifact')) {
    /**
     * §2 (v7.10.678) — consume the service's per-URL wire.json manifest.
     *
     * Called from the crit callback (a SEPARATE background request, never a page render). Stores
     * the render-provenance stamp (wire_rev/wire_sig) per-URL alongside the crit, and — when a real
     * URL + rev are present — fetches, validates and caches the manifest, recording its material_rev,
     * schema_epoch and scope. `wire_rev` 0 / `wire_sig` '' means "no manifest for this gen": the stamp
     * is written as 0 and NOTHING is fetched or acted on (§5 never purges on rev 0). The fetch is
     * host-allowlisted, size-capped and JSON-validated — never trusts an arbitrary callback URL — and
     * is idempotent: a repeat callback for a rev already cached does not re-hit the network. No render
     * touches this; §5/render read the stamp back with wpc_wire_provenance() (a cheap file read).
     */
    function wpc_consume_wire_artifact($urlKey, $wireUrl, $wireRev, $wireSig)
    {
        if (empty($urlKey) || !defined('WPS_IC_CRITICAL')
            || !apply_filters('wpc_wire_artifact_consume', true)) {
            return 0;
        }
        try {
            // P2 (v7.10.697, Atomic Contract): a key on the manifest path takes wire bytes from
            // the manifest ONLY — the legacy declaration lane is exactly where the straggler /
            // rev-reset / first-callback-downgrade classes lived. Nothing stamped, nothing fetched.
            if (function_exists('wpc_manifest_mode') && wpc_manifest_mode($urlKey) !== '') {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('legacy-wire-shadowed', (string) $urlKey, '', []);
                }
                return 0;
            }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim((string) $urlKey, '/') . '/';
            if (!is_dir($dir)) { @wp_mkdir_p($dir); }
            $write = function ($p, $v) {
                return function_exists('wpc_crit_meta_write')
                    ? wpc_crit_meta_write($p, $v) : @file_put_contents($p, $v);
            };
            $rev = (int) $wireRev;
            // Always stamp the provenance — even rev 0 records "no manifest this gen" for §5.
            $wireUrl = is_string($wireUrl) ? $wireUrl : '';
            // v7.10.696 — GEN-MONOTONIC CONSUME. A straggler callback from a SUPERSEDED gen
            // (3dcd's late amend, arriving 500s after 77f1's) overwrote the newer gen's wire and
            // flipped lcp_verdict inline→keep — un-inlining the hero the 97 was scored on. .695
            // guards poor-vs-rich, not rich-vs-out-of-order. The gen the plugin LANDED (uuid.txt
            // / land_uuid — written by the crit save that precedes every legit wire declaration)
            // is the monotonic authority: an incoming wire URL naming a DIFFERENT uuid is a
            // stale gen — hold everything, stamp nothing (a chased stamp re-points the catchup).
            $wpc_gen696 = '';
            foreach (['land_uuid.txt', 'uuid.txt'] as $wpc_gf696) {
                if (@is_file($dir . $wpc_gf696)) {
                    $wpc_gen696 = preg_replace('/[^a-f0-9\-]/', '', strtolower(trim((string) @file_get_contents($dir . $wpc_gf696))));
                    if (strlen($wpc_gen696) >= 32) { break; }
                    $wpc_gen696 = '';
                }
            }
            if ($wpc_gen696 !== '' && $wireUrl !== ''
                && preg_match('#/([a-f0-9]{8}-[a-f0-9\-]{27,})\.wire\.json#i', $wireUrl, $wpc_wm696)
                && strtolower($wpc_wm696[1]) !== $wpc_gen696) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('wire-stale-gen', (string) $urlKey, '', ['inc' => substr($wpc_wm696[1], 0, 8), 'held' => substr($wpc_gen696, 0, 8)]);
                }
                return 0;
            }
            $write($dir . 'wire_rev.txt', (string) $rev);
            $write($dir . 'wire_sig.txt', substr((string) $wireSig, 0, 4096));
            if ($wireUrl !== '') { $write($dir . 'wire_url.txt', $wireUrl); }
            // rev 0 / no url ⇒ nothing to fetch (the callback said "not yet / never").
            if ($rev <= 0 || $wireUrl === '') { return 0; }
            // Host allowlist: only the crit MC / bunny CDN, never an arbitrary param URL.
            $host = strtolower((string) parse_url($wireUrl, PHP_URL_HOST));
            if ($host === '' || (substr($host, -10) !== '.b-cdn.net' && stripos($host, 'critical-css-mc') === false)) {
                return 0;
            }
            // v7.10.684 — idempotence keys on the SHELF OBJECT ({uuid}.wire.json is immutable),
            // NEVER on material_rev: rev RESETS PER-UUID (the service's own §5 warning), so the
            // first-ever consumed rev-2 wire made every later uuid's rev-2 wire skip as "already
            // held" — wpcompress served a 4-day-frozen manifest while the shelf carried the
            // drop[]/lcp entries §8(c)/§8.1 were waiting for.
            $heldUrl = @is_file($dir . 'wire.held_url') ? trim((string) @file_get_contents($dir . 'wire.held_url')) : '';
            // v7.10.694 — AMENDS REWRITE THE SAME URL. The .684 "shelf object is immutable"
            // premise is false for the amend flow: the late callback re-declares the SAME
            // {uuid}.wire.json at rev 2 after the obs lands (live: 3dcd3509 held at rev 1 with
            // the rev-2 amend minutes out). Same-URL idempotence therefore also requires the
            // held material to be at least the DECLARED rev; a rev advance re-fetches.
            $wpc_prevrev694 = @is_file($dir . 'wire.rev') ? (int) @file_get_contents($dir . 'wire.rev') : 0;
            if ($heldUrl !== '' && $heldUrl === $wireUrl && $wpc_prevrev694 >= $rev
                && @is_file($dir . 'wire.json') && (int) @filesize($dir . 'wire.json') > 2) {
                return 1;
            }
            // Amended object, same URL: bust any shelf-CDN copy of the stale rev on the FETCH
            // only — held_url stays canonical for the dedupe key.
            $wpc_fetch694 = ($heldUrl === $wireUrl)
                ? $wireUrl . ((strpos($wireUrl, '?') === false ? '?' : '&') . 'wr=' . $rev)
                : $wireUrl;
            $resp = wp_remote_get($wpc_fetch694, ['timeout' => (int) apply_filters('wpc_wire_fetch_timeout', 4)]);
            if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) { return 0; }
            $raw = (string) wp_remote_retrieve_body($resp);
            if ($raw === '' || strlen($raw) > 262144) { return 0; }
            $wire = json_decode($raw, true);
            if (!is_array($wire)) { return 0; }
            // Envelope: unknown fields ignored; scope BRANCHED not inferred (unknown => 'url', the
            // conservative per-page side, per spec §5.1).
            $matRev = isset($wire['material_rev']) ? (int) $wire['material_rev'] : 0;
            $epoch  = isset($wire['schema_epoch']) ? (int) $wire['schema_epoch'] : 0;
            $scope  = (isset($wire['scope']) && strtolower(trim((string) $wire['scope'])) === 'site') ? 'site' : 'url';
            // v7.10.695 — NEVER DOWNGRADE THE WIRE. A new gen's FIRST callback ships an
            // allow-only rev-1 wire; consuming it over the previous gen's rev-2 replaced real
            // instructions (roboto drop, hero verdict — page-level census facts that do not
            // change gen-to-gen) with nothing for the whole ~11min observation window, every
            // gen. Hold the richer held wire when the incoming is a PRE-AMEND (rev<=1) empty:
            // the declared stamps above still update, so the .694 rev-advance re-fetch pulls
            // the amend the moment it exists and replaces honestly. A rev>=2 wire with no
            // instructions is an HONEST empty (census says nothing to do) and always lands.
            $wpc_rich695 = function ($w) {
                $n = 0;
                foreach (['mobile', 'desktop'] as $d) {
                    if (!isset($w['wire'][$d]) || !is_array($w['wire'][$d])) { continue; }
                    $node = $w['wire'][$d];
                    $n += (isset($node['drop']) && is_array($node['drop'])) ? count($node['drop']) : 0;
                    $n += (isset($node['lcp']['verdict']) && (string) $node['lcp']['verdict'] !== ''
                           && (string) $node['lcp']['verdict'] !== 'none') ? 1 : 0;
                }
                return $n;
            };
            if ($matRev <= 1 && $wpc_rich695($wire) === 0 && @is_file($dir . 'wire.json')) {
                $wpc_heldw695 = json_decode((string) @file_get_contents($dir . 'wire.json'), true);
                if (is_array($wpc_heldw695) && $wpc_rich695($wpc_heldw695) > 0) {
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('wire-hold-richer', (string) $urlKey, '', ['incoming_rev' => $matRev, 'to' => substr($wireUrl, -22)]);
                    }
                    return 1; // keep serving the rich wire; the amend re-fetch replaces it honestly
                }
            }
            $tmp = $dir . 'wire.json.tmp.' . getmypid();
            if ($write($tmp, $raw) !== false) { @rename($tmp, $dir . 'wire.json'); }
            $write($dir . 'wire.rev', (string) $matRev);
            $write($dir . 'wire.epoch', (string) $epoch);
            $write($dir . 'wire.scope', $scope);
            $write($dir . 'wire.held_url', $wireUrl); // the dedupe key: which shelf object these bytes ARE
            // v7.10.692 — DERIVED FILES NEED THE ARTIFACT'S CONTENT IN THEIR KEY. The stored
            // HTML is a derived copy of the wire and was keyed on NOTHING wire-shaped: a new
            // shelf object consumed here left every stored page rendering the OLD instructions
            // (receipt: ad78b676's roboto drop on disk, §3b firing on the same renders, zero
            // late-faces served — the replay against the same bytes fired instantly). The rev
            // stamp can't gate this purge — rev resets per-uuid (.684) and adjacent gens both
            // declared rev 2. held_url CHANGING is the one honest content signal. Local layers
            // only: CF eviction already rides the crit-land tag purge.
            if ($heldUrl !== $wireUrl || $matRev > $wpc_prevrev694) {
                if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeCacheFiles')) {
                    wps_ic_cache_integrations::purgeCacheFiles((string) $urlKey);
                }
                $wpc_su692 = (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'getUrlFromKey'))
                    ? (string) wps_ic_url_key::getUrlFromKey((string) $urlKey) : '';
                if ($wpc_su692 !== '' && class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'removeStaticMirror')) {
                    wps_cacheHtml::removeStaticMirror($wpc_su692);
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('wire-purge-derived', (string) $urlKey, '', ['from' => substr((string) $heldUrl, -22), 'to' => substr($wireUrl, -22)]);
                }
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('wire-consumed', (string) $urlKey, '', ['rev' => $matRev, 'epoch' => $epoch, 'scope' => $scope]);
            }
            return 1;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('wpc_wire_catchup')) {
    /**
     * v7.10.684 — heal a MISSED wire consume. The callback stamps wire_url.txt on every gen even
     * when the fetch is skipped/fails, so a declared-but-not-held wire (wire_url.txt newer than
     * wire.held_url — the exact state the rev-reset trap left the whole fleet in) is detectable
     * from disk. Rides the kick lane, transient-gated; consumes via the same allowlisted fetch.
     */
    function wpc_wire_catchup($urlKey)
    {
        try {
            if (empty($urlKey) || !defined('WPS_IC_CRITICAL') || !function_exists('wpc_consume_wire_artifact')) {
                return false;
            }
            // P2 (v7.10.697): on the manifest path the pointer/webhook owns wire freshness —
            // catchup's derive-from-land heuristic is a legacy belt and stands down entirely.
            if (function_exists('wpc_manifest_mode') && wpc_manifest_mode($urlKey) !== '') {
                return false;
            }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim((string) $urlKey, '/') . '/';
            $declared = @is_file($dir . 'wire_url.txt') ? trim((string) @file_get_contents($dir . 'wire_url.txt')) : '';
            // v7.10.691 — THE WIRE FOLLOWS THE LAND, not the lane. Wire declaration rode the
            // webhook callback only, so a gen landing via the poll/collect lane kept the OLD
            // gen's declared URL — held==declared stayed true and .684's heal saw nothing
            // (receipt: c3720e5a's crit served 42min with 0a0080ce's manifest, drop/lcp null).
            // When the landed uuid is not the uuid inside the declared URL, derive the shelf
            // URL from land_uuid ({first4}/{uuid}.wire.json — the same immutable-object naming
            // the shelf uses for used.css). A gen with no wire 404s harmlessly and the 300s
            // transient stops any retry storm.
            $wpc_land691 = @is_file($dir . 'land_uuid.txt')
                ? preg_replace('/[^a-f0-9\-]/', '', strtolower(trim((string) @file_get_contents($dir . 'land_uuid.txt')))) : '';
            // v7.10.696 — land_uuid is transiently unlinked by the resync refetch leg; uuid.txt
            // names the same gen and survives (live: land_uuid empty, on_disk 77f1f837, derive
            // disarmed exactly when it was needed to heal a straggler overwrite).
            if (strlen($wpc_land691) < 32 && @is_file($dir . 'uuid.txt')) {
                $wpc_land691 = preg_replace('/[^a-f0-9\-]/', '', strtolower(trim((string) @file_get_contents($dir . 'uuid.txt'))));
            }
            if (strlen($wpc_land691) >= 32 && stripos($declared, $wpc_land691) === false) {
                $wpc_base691 = 'https://critical-css-mc.b-cdn.net/';
                if ($declared !== '' && preg_match('#^(https?://[^/]+/)#i', $declared, $wpc_bm691)) {
                    $wpc_base691 = $wpc_bm691[1];
                }
                $declared = $wpc_base691 . substr($wpc_land691, 0, 4) . '/' . $wpc_land691 . '.wire.json';
            }
            if ($declared === '') {
                return false;
            }
            $held = @is_file($dir . 'wire.held_url') ? trim((string) @file_get_contents($dir . 'wire.held_url')) : '';
            if ($held === $declared && @is_file($dir . 'wire.json')) {
                return false; // already current
            }
            if (get_transient('wpc_wirecu_' . md5((string) $urlKey))) {
                return false;
            }
            set_transient('wpc_wirecu_' . md5((string) $urlKey), 1, 300);
            $rev = @is_file($dir . 'wire_rev.txt') ? (int) @file_get_contents($dir . 'wire_rev.txt') : 0;
            $sig = @is_file($dir . 'wire_sig.txt') ? (string) @file_get_contents($dir . 'wire_sig.txt') : '';
            $ok  = (int) wpc_consume_wire_artifact((string) $urlKey, $declared, max(1, $rev), $sig);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('wire-catchup', (string) $urlKey, '', ['ok' => $ok]);
            }
            return $ok === 1;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_wire_provenance')) {
    /**
     * §2 — read the stored wire provenance for a urlKey. Cheap file reads; safe at render.
     * Returns ['rev'=>int (callback stamp), 'sig'=>string, 'url'=>string,
     *          'scope'=>'url'|'site', 'material_rev'=>int (cached manifest), 'epoch'=>int].
     * rev 0 ⇒ no manifest was declared for this render (§5 must not purge on it).
     */
    function wpc_wire_provenance($urlKey)
    {
        $out = ['rev' => 0, 'sig' => '', 'url' => '', 'scope' => 'url', 'material_rev' => 0, 'epoch' => 0];
        if (empty($urlKey) || !defined('WPS_IC_CRITICAL')) { return $out; }
        $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim((string) $urlKey, '/') . '/';
        $r = function ($p) use ($dir) { return @is_file($dir . $p) ? (string) @file_get_contents($dir . $p) : ''; };
        $out['rev']          = (int) $r('wire_rev.txt');
        $out['sig']          = $r('wire_sig.txt');
        $out['url']          = $r('wire_url.txt');
        $out['scope']        = trim($r('wire.scope')) === 'site' ? 'site' : 'url';
        $out['material_rev'] = (int) $r('wire.rev');
        $out['epoch']        = (int) $r('wire.epoch');
        return $out;
    }
}

if (!function_exists('wpc_manifest_mode')) {
    /**
     * Atomic Generation Contract (spec v1, frozen 2026-08-02) — is this key on the manifest
     * path? Returns the held gen_id ('' = legacy path). True once wpc_manifest_consume() has
     * flipped at least one generation for the key. Per-key file, so the operator escape hatch
     * is the same as every other artifact reset: &refetch=crit unlinks it and the key reverts
     * to the legacy flow; the filter is the site-wide kill.
     */
    function wpc_manifest_mode($urlKey)
    {
        if (empty($urlKey) || !defined('WPS_IC_CRITICAL') || !apply_filters('wpc_manifest_mode', true)) {
            return '';
        }
        $wpc_mp697 = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim((string) $urlKey, '/') . '/manifest.held_gen';
        $wpc_mg697 = @is_file($wpc_mp697) ? preg_replace('/[^a-f0-9\-]/', '', strtolower(trim((string) @file_get_contents($wpc_mp697)))) : '';
        return strlen($wpc_mg697) >= 32 ? $wpc_mg697 : '';
    }
}

if (!function_exists('wpc_copy_admissible')) {
    /**
     * P-A (v7.10.700) — the Verified Copy Contract, Law A: a degraded render must never become
     * a cached copy. One verdict consulted by every memoizer (HTML store, static mirror, and —
     * via the refusal's no-store header — the CF edge, whose full-HTML rule respects origin).
     * Returns '' to admit, or the refusal reason. Receipted classes it kills (2026-08-02, live):
     * the critless PSI-90 copy, the hero-uncovered PSI-97/96 copies, tracer-armed renders.
     * Failure semantics per spec: a CHECK failing refuses; the CHECKER erroring admits
     * (availability beats purity) with its own receipt. Refusal-storm brake: >20 in 10min
     * stands the gate down for an hour — a config mismatch degrades to today's behavior,
     * never to an uncacheable site. Each refusal fires the throttled warm kick: the refused
     * copy is the symptom, the warm is the cure.
     */
    function wpc_copy_admissible($html, $urlKey, $prefix = '')
    {
        try {
            if (!is_string($html) || $html === '' || empty($urlKey) || !defined('WPS_IC_CRITICAL')
                || !apply_filters('wpc_copy_admission', true)) {
                return '';
            }
            $wpc_chk700 = (array) apply_filters('wpc_copy_admission_checks', ['crit' => 1, 'hero' => 1, 'arm' => 1]);
            $wpc_brk700 = (array) get_option('wpc_admission_state700', []);
            if (!empty($wpc_brk700['until']) && time() < (int) $wpc_brk700['until']) {
                return ''; // storm brake engaged — fail-open until it expires
            }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim((string) $urlKey, '/') . '/';
            $refuse = function ($why) use ($urlKey, $wpc_brk700) {
                $wpc_in700 = (isset($wpc_brk700['n'], $wpc_brk700['t']) && (time() - (int) $wpc_brk700['t']) < 600);
                $n = $wpc_in700 ? (int) $wpc_brk700['n'] + 1 : 1;
                $u = ($n > 20) ? time() + 3600 : 0;
                update_option('wpc_admission_state700', [
                    'n' => $n, 't' => $wpc_in700 ? (int) $wpc_brk700['t'] : time(),
                    'until' => $u, 'why' => $why, 'at' => time(),
                ], false);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log($u ? 'admission-storm' : 'copy-admission-refused', (string) $urlKey, '', ['why' => $why, 'n' => $n]);
                }
                if (!$u && function_exists('wpc_repull_kick_now') && !get_transient('wpc_admkick_' . md5((string) $urlKey))) {
                    set_transient('wpc_admkick_' . md5((string) $urlKey), 1, 120);
                    wpc_repull_kick_now($urlKey);
                }
                return $why;
            };
            // 1 — CRIT CARRIED, config-aware: the copy must hold what the configuration promises.
            if (!empty($wpc_chk700['crit'])) {
                $wpc_set700 = get_option(WPS_IC_SETTINGS);
                if (is_array($wpc_set700) && isset($wpc_set700['critical']['css']) && (string) $wpc_set700['critical']['css'] === '1'
                    && ((int) @filesize($dir . 'critical_desktop.css') > 5 || (int) @filesize($dir . 'critical_mobile.css') > 5)
                    && strpos($html, 'id="wpc-critical-css"') === false) {
                    return $refuse('crit');
                }
            }
            // 2 — HERO COVERED: verdict=inline admits only with the OBSERVED inline (same-request
            // pass receipt) or the fallback pair P-B emits. Intent never vouches for effect.
            if (!empty($wpc_chk700['hero']) && @is_readable($dir . 'wire.json')) {
                $wpc_w700 = json_decode((string) @file_get_contents($dir . 'wire.json'), true);
                $wpc_dev700 = (strpos((string) $prefix, 'mobile') !== false) ? 'mobile'
                    : ((function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop');
                $wpc_v700 = (is_array($wpc_w700) && isset($wpc_w700['wire'][$wpc_dev700]['lcp']['verdict']))
                    ? (string) $wpc_w700['wire'][$wpc_dev700]['lcp']['verdict'] : '';
                if ($wpc_v700 === 'inline-data-uri'
                    && (($GLOBALS['wpc_lcp_cover700'] ?? '') !== 'inline')
                    && strpos($html, 'wpc-lcp-bg-authority') === false
                    && strpos($html, 'wpc-lcp-bg-preload') === false) {
                    return $refuse('hero');
                }
            }
            // 3 — NO MEASUREMENT ARM: tracer renders serve once and evaporate.
            if (!empty($wpc_chk700['arm'])
                && (strpos($html, 'id="wpc-img-trace"') !== false || strpos($html, 'id="wpc-lcp-trace"') !== false)) {
                return $refuse('arm');
            }
            return '';
        } catch (\Throwable $e) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('copy-admission-checker-error', (string) $urlKey, '', []);
            }
            return '';
        }
    }
}

if (!function_exists('wpc_gen_semantic_ok')) {
    /**
     * v7.10.705 — SEMANTIC verify: hashes prove transport, never provenance. A generation whose
     * observation ran against an ALREADY-OPTIMIZED copy is self-referential — its lcp record
     * points at a fragment of our own inlined data-URI instead of a real asset, and its crit
     * can carry serve-time-only markers. Live receipt (wpcompress.com 2026-08-02): the sweep
     * re-observed optimized pages; the resulting gen shipped jQuery eager, unparked the theme
     * sheets and put full woff2s back on the render chain — PSI 100 → 90. Refusing the gen
     * whole keeps the predecessor serving (all-or-nothing already guarantees that).
     * Checker error ⇒ true: this belt must never block a clean landing.
     */
    function wpc_gen_semantic_ok($bodies, &$why = '')
    {
        try {
            // v7.10.706 — 'wpc-css-live' is NOT an ingestion marker anymore: service v3.189.3
            // emits natively-scoped conceal-guards (html:not(.wpc-css-live) + the
            // /*wpc-conceal-scoped:1*/ mark), so its presence is the CONVERGED format, not
            // proof of self-reference. The remaining markers are serve-time-only artifacts
            // (script sentinel text + our injected style/link ids) that no legit CSS
            // extraction can produce.
            foreach (['crit_desktop', 'crit_mobile'] as $k) {
                if (isset($bodies[$k]) && is_string($bodies[$k])) {
                    foreach (['wpc-arm-sentinel', 'bg-authority700', 'wpc-lcp-bg-preload'] as $mk) {
                        if (strpos($bodies[$k], $mk) !== false) {
                            $why = 'self-ref-' . $k;
                            return false;
                        }
                    }
                }
            }
            if (isset($bodies['lcp']) && is_string($bodies['lcp'])) {
                $lj = json_decode($bodies['lcp'], true);
                $scan = function ($n) use (&$scan, &$why) {
                    if (!is_array($n)) {
                        return true;
                    }
                    foreach ($n as $k => $v) {
                        if ($k === 'lcp_element' && is_array($v)) {
                            foreach (['url', 'bg', 'net_url'] as $uk) {
                                if (isset($v[$uk]) && is_string($v[$uk])) {
                                    $u = trim($v[$uk]);
                                    if ($u !== '' && $u !== 'none' && $u !== '-'
                                        && !preg_match('#^(?:https?:)?//|^[a-z0-9.-]+\.[a-z]{2,}/|^/[^/]|^data:image/#i', $u)) {
                                        $why = 'lcp-url-shape';
                                        return false;
                                    }
                                }
                            }
                        } elseif (is_array($v)) {
                            if (!$scan($v)) {
                                return false;
                            }
                        }
                    }
                    return true;
                };
                if (is_array($lj) && !$scan($lj)) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }
}

if (!function_exists('wpc_manifest_consume')) {
    /**
     * P1 (v7.10.697) — the Atomic Generation Contract's whole consume path, per the frozen
     * spec: fetch manifest → verify EVERY artifact (bytes + sha) before anything lands →
     * land all → single flip → purge every memoized render. Newness is decided by AUTHORITY
     * (the predecessor chain — a gen is newer than every gen in its ancestor chain), never
     * by clocks; a replayed webhook is a cheap idempotent no-op; a stale or disjoint gen is
     * held with nothing written. Called only from the generation_complete webhook branch —
     * dormant until the service ships S1 and starts minting manifests.
     *
     * v1 landing coverage: crit (via saveCriticalCss — full bookkeeping: combined refresh,
     * land stamps, land purge incl. CF), wire, delay, lcp, fonts (shared consumer). used_css
     * is VERIFIED but still lands via the legacy callback: the store keys on tpl_key, which
     * manifest v1 does not carry (flagged to the service for spec v1.1).
     */
    function wpc_manifest_consume($urlKey, $manifestUrl, $genId, $pageUrl = '')
    {
        try {
            if (empty($urlKey) || empty($manifestUrl) || !defined('WPS_IC_CRITICAL')
                || !apply_filters('wpc_manifest_consume', true)) {
                return 0;
            }
            $log = function ($e, $x = []) use ($urlKey) {
                if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log($e, (string) $urlKey, '', $x); }
            };
            $genId = preg_replace('/[^a-f0-9\-]/', '', strtolower((string) $genId));
            if (strlen($genId) < 32) { return 0; }
            // Host allowlist — manifest AND every artifact URL inside it (never trust webhook URLs).
            $allow = function ($u) {
                $h = strtolower((string) parse_url((string) $u, PHP_URL_HOST));
                return $h !== '' && (substr($h, -10) === '.b-cdn.net' || stripos($h, 'critical-css-mc') !== false);
            };
            if (!$allow($manifestUrl)) { return 0; }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim((string) $urlKey, '/') . '/';
            if (!is_dir($dir)) { @wp_mkdir_p($dir); }
            $write = function ($p, $v) {
                return function_exists('wpc_crit_meta_write') ? wpc_crit_meta_write($p, $v) : @file_put_contents($p, $v);
            };
            $held = @is_file($dir . 'manifest.held_gen')
                ? preg_replace('/[^a-f0-9\-]/', '', strtolower(trim((string) @file_get_contents($dir . 'manifest.held_gen')))) : '';
            // Law 3: the webhook is idempotent and replayable — a replay of the held gen is free.
            if ($held !== '' && $held === $genId && @is_file($dir . 'manifest.json')) {
                $log('manifest-replay', ['gen' => substr($genId, 0, 8)]);
                return 1;
            }
            $fetch = function ($url, $cap) {
                for ($t = 0; $t < 2; $t++) {
                    $r = wp_remote_get($url, ['timeout' => (int) apply_filters('wpc_manifest_fetch_timeout', 6),
                        'headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'wpc']]);
                    if (!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200) {
                        $b = (string) wp_remote_retrieve_body($r);
                        if ($b !== '' && strlen($b) <= $cap) { return $b; }
                    }
                }
                return null;
            };
            $raw = $fetch($manifestUrl, 262144);
            if ($raw === null) { $log('manifest-fetch-fail', ['u' => substr((string) $manifestUrl, -40)]); return 0; }
            $m = json_decode($raw, true);
            if (!is_array($m) || (int) ($m['v'] ?? 0) !== 1 || empty($m['artifacts']) || !is_array($m['artifacts'])) {
                $log('manifest-invalid', []);
                return 0;
            }
            if (preg_replace('/[^a-f0-9\-]/', '', strtolower((string) ($m['gen_id'] ?? ''))) !== $genId) {
                $log('manifest-gen-mismatch', ['gen' => substr($genId, 0, 8)]);
                return 0;
            }
            // A gen that cannot name its own crit pair never flips render identity.
            if (empty($m['artifacts']['crit_mobile']['url']) || empty($m['artifacts']['crit_desktop']['url'])) {
                $log('manifest-no-crit', ['gen' => substr($genId, 0, 8)]);
                return 0;
            }
            // Newness by pointer authority: the held gen must sit in the incoming gen's ancestor
            // chain (successors reference predecessors; the chain is linear per url_key). Held
            // NOT in the chain = stale straggler or disjoint history — hold everything; the
            // pointer's real current gen re-announces via webhook replay or /reannounce.
            if ($held !== '' && $held !== $genId) {
                $wpc_base697 = 'https://critical-css-mc.b-cdn.net/';
                if (preg_match('#^(https?://[^/]+/)#i', (string) $manifestUrl, $wpc_bm697)) { $wpc_base697 = $wpc_bm697[1]; }
                $cur = $m;
                $newer = false;
                for ($i = 0; $i < 4; $i++) {
                    $pred = preg_replace('/[^a-f0-9\-]/', '', strtolower((string) ($cur['predecessor'] ?? '')));
                    if (strlen($pred) < 32) { break; }
                    if ($pred === $held) { $newer = true; break; }
                    $praw = $fetch($wpc_base697 . substr($pred, 0, 4) . '/' . $pred . '.manifest.json', 262144);
                    $cur = ($praw !== null) ? json_decode($praw, true) : null;
                    if (!is_array($cur)) { break; }
                }
                if (!$newer) {
                    $log('manifest-stale-gen', ['inc' => substr($genId, 0, 8), 'held' => substr($held, 0, 8)]);
                    return 0;
                }
            }
            // Law 2, consumed honestly: verify EVERY listed artifact — bytes and sha256 against
            // the manifest — before a single byte lands. Any failure holds the whole generation.
            $bodies = [];
            $budget = 8 * 1024 * 1024;
            foreach ($m['artifacts'] as $name => $a) {
                if (!is_array($a) || empty($a['url']) || !$allow($a['url'])) {
                    $log('manifest-verify-fail', ['a' => (string) $name, 'why' => 'url']);
                    return 0;
                }
                $b = $fetch((string) $a['url'], min($budget, 2 * 1024 * 1024));
                if ($b === null
                    || (!empty($a['bytes']) && (int) $a['bytes'] !== strlen($b))
                    || (!empty($a['sha256']) && !hash_equals(strtolower((string) $a['sha256']), hash('sha256', $b)))) {
                    $log('manifest-verify-fail', ['a' => (string) $name, 'why' => $b === null ? 'fetch' : 'bytes-sha']);
                    return 0;
                }
                $budget -= strlen($b);
                if ($budget <= 0) { $log('manifest-verify-fail', ['a' => (string) $name, 'why' => 'budget']); return 0; }
                $bodies[(string) $name] = $b;
            }
            $wpc_semwhy705 = '';
            if (apply_filters('wpc_semantic_verify', true)
                && function_exists('wpc_gen_semantic_ok') && !wpc_gen_semantic_ok($bodies, $wpc_semwhy705)) {
                $log('manifest-verify-fail', ['a' => 'semantic', 'why' => (string) $wpc_semwhy705]);
                return 0;
            }
            // LAND — crit rides the proven path (device files, combined refresh, land stamps,
            // land purge incl. the CF tag). uuid = gen_id keeps the .696 gen-monotonic authority
            // and every legacy reader pointing at the manifest's generation.
            $landed = [];
            if (!class_exists('wps_criticalCss') && defined('WPS_IC_DIR')) {
                include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
            }
            if (!class_exists('wps_criticalCss')) { $log('manifest-land-fail', ['a' => 'crit', 'why' => 'no-class']); return 0; }
            $cc = new wps_criticalCss();
            $wpc_scc699 = [
                'url'  => ['desktop' => (string) $m['artifacts']['crit_desktop']['url'], 'mobile' => (string) $m['artifacts']['crit_mobile']['url']],
                'uuid' => $genId,
            ];
            // v7.10.699 — spec v1.1: tpl_key rides the manifest (service v3.187), so used_css
            // lands through the same proven saver path (used_css_url.txt + used_tpl.txt + the
            // Route-2 artifact-land hooks) and NOTHING remains on the legacy callback.
            $wpc_tpl699 = (isset($m['tpl_key']) && is_string($m['tpl_key'])) ? trim($m['tpl_key']) : '';
            if ($wpc_tpl699 !== '' && !empty($m['artifacts']['used_css']['url'])) {
                $wpc_scc699['tpl_key']      = $wpc_tpl699;
                $wpc_scc699['used_css_url'] = (string) $m['artifacts']['used_css']['url'];
            }
            $res = $cc->saveCriticalCss($urlKey, $wpc_scc699, 'meta', (string) $pageUrl);
            if (is_array($res) && isset($res['critical-failed'])) {
                $log('manifest-land-fail', ['a' => 'crit']);
                return 0;
            }
            // Verify effect, not execution: the flip requires the device pair ON DISK — a save
            // that returned without the failure key but landed only one variant must not flip.
            if ((int) @filesize($dir . 'critical_desktop.css') <= 5 || (int) @filesize($dir . 'critical_mobile.css') <= 5) {
                $log('manifest-land-fail', ['a' => 'crit', 'why' => 'files']);
                return 0;
            }
            $landed[] = 'crit';
            if (isset($wpc_scc699['used_css_url'])) { $landed[] = 'used_css'; }
            if (isset($bodies['wire']) && is_array(json_decode($bodies['wire'], true))) {
                $w = json_decode($bodies['wire'], true);
                $tmp = $dir . 'wire.json.tmp.' . getmypid();
                if ($write($tmp, $bodies['wire']) !== false) { @rename($tmp, $dir . 'wire.json'); }
                $write($dir . 'wire.rev', (string) (int) ($w['material_rev'] ?? 0));
                $write($dir . 'wire.epoch', (string) (int) ($w['schema_epoch'] ?? 0));
                $write($dir . 'wire.scope', (isset($w['scope']) && strtolower(trim((string) $w['scope'])) === 'site') ? 'site' : 'url');
                $write($dir . 'wire.held_url', (string) $m['artifacts']['wire']['url']);
                $write($dir . 'wire_url.txt', (string) $m['artifacts']['wire']['url']);
                $write($dir . 'wire_rev.txt', (string) (int) ($w['material_rev'] ?? 0));
                $landed[] = 'wire';
            }
            if (isset($bodies['delay'])) {
                $d = json_decode($bodies['delay'], true);
                if (is_array($d) && (!class_exists('wps_ic_js_delay_v3')
                        || !method_exists('wps_ic_js_delay_v3', 'wpc_delay_measured_shape')
                        || wps_ic_js_delay_v3::wpc_delay_measured_shape($d))) {
                    $write($dir . 'delay.json', $bodies['delay']);
                    delete_option('wpc_delay_v3_manifest_off');
                    $landed[] = 'delay';
                }
            }
            if (isset($bodies['lcp'])) {
                $l = json_decode($bodies['lcp'], true);
                if (is_array($l) && (isset($l['lcp_element']) || isset($l['hints']))) {
                    wpc_lcp_write_preserve781($dir, $bodies['lcp']);
                    @unlink($dir . 'lcp_none.txt');
                    $landed[] = 'lcp';
                }
            }
            if (isset($bodies['fonts']) && function_exists('wpc_consume_fonts_artifact')) {
                $f = json_decode($bodies['fonts'], true);
                if (is_array($f) && isset($f['fonts']) && is_array($f['fonts'])) { $f = $f['fonts']; }
                if (is_array($f) && !empty($f)) {
                    wpc_consume_fonts_artifact($f, $urlKey);
                    $landed[] = 'fonts';
                }
            }
            // FLIP — stamps last, then purge every LOCAL memoized render (an HTML render is a
            // derived file of the manifest — the .692 law). CF already rode the crit-land purge.
            $write($dir . 'manifest.json', $raw);
            $write($dir . 'manifest.held_gen', $genId);
            $write($dir . 'uuid.txt', $genId);
            $write($dir . 'land_uuid.txt', $genId);
            if (!@is_file($dir . 'dispatch_ts.txt')) {
                $write($dir . 'dispatch_ts.txt', (string) time()); // quarantine reads land-without-dispatch as foreign crit
            }
            if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeCacheFiles')) {
                wps_ic_cache_integrations::purgeCacheFiles((string) $urlKey);
            }
            $wpc_su697 = (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'getUrlFromKey'))
                ? (string) wps_ic_url_key::getUrlFromKey((string) $urlKey) : '';
            if ($wpc_su697 !== '' && class_exists('wps_cacheHtml') && method_exists('wps_cacheHtml', 'removeStaticMirror')) {
                wps_cacheHtml::removeStaticMirror($wpc_su697);
            }
            $log('manifest-consumed', [
                'gen'      => substr($genId, 0, 8),
                'verified' => count($bodies),
                'landed'   => implode(',', $landed),
                'lean'     => (string) ($m['quality']['lean'] ?? ''),
            ]);
            return 1;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('wpc_consume_fonts_artifact')) {
    function wpc_consume_fonts_artifact($fonts, $urlKey)
    {
        if (!is_array($fonts) || empty($fonts) || empty($urlKey) || !defined('WPS_IC_FONTS_DIR')
            || !apply_filters('wpc_fonts_artifact_consume', true)) {
            return 0;
        }
        try {
            if (!is_dir(WPS_IC_FONTS_DIR)) { @wp_mkdir_p(WPS_IC_FONTS_DIR); }
            $n = 0; $metrics = [];


            $wpc_fonts_t0 = time();
            $wpc_dlstop356 = false;
            $wpc_pwn356 = 0;
            foreach ($fonts as $fe) {
                if (!is_array($fe)) { continue; }
                // Metrics capture is pure array work — deliberately ABOVE the download
                // slot/budget gates so which fallback rows exist never depends on how
                // many woff2 files this pass happened to fetch.
                if (!empty($fe['fallback']) && is_array($fe['fallback']) && !empty($fe['family'])) {
                    $metrics[(string) $fe['family']] = $fe['fallback'];
                }
                // remote_range must be captured on EVERY transport, not just the callback. The
                // .420 producer lived only in the callback consumer (wp-compress-core), so fonts
                // arriving via the /status -> fonts_url POLL built the subset but left the map
                // empty and the range-gate silently no-op'd. This is the shared consumer the poll
                // path uses, so capturing here covers it. Pure array work, above the download
                // gates — same reasoning as metrics.
                if (!empty($fe['family'])) {
                    if (!isset($wpc_rr_diag356)) { $wpc_rr_diag356 = []; }
                    $wpc_rr_diag356[] = strtolower((string) $fe['family']) . '|' . (string) ($fe['weight'] ?? '400')
                        . ' keys=' . implode(',', array_keys($fe))
                        . ' ur=' . (empty($fe['unicode_range']) ? '(none)' : (string) $fe['unicode_range'])
                        . ' rr=' . (empty($fe['remote_range']) ? '(NONE)' : (string) $fe['remote_range']);
                }
                if (!empty($fe['remote_range']) && !empty($fe['unicode_range']) && !empty($fe['family'])) {
                    $wpc_rr_fam = strtolower(str_replace(["'", '"', "\r", "\n", '<', '>'], '', (string) $fe['family']));
                    $wpc_rr_wt  = trim(preg_replace('/[^0-9 ]/', '', (string) ($fe['weight'] ?? '400')));
                    $wpc_rr_wt  = strtok($wpc_rr_wt === '' ? '400' : $wpc_rr_wt, ' ');
                    $wpc_rr_st  = (strtolower((string) ($fe['style'] ?? 'normal')) === 'italic') ? 'italic' : 'normal';
                    $wpc_rr_v   = preg_replace('/[^0-9A-Fa-fUu+,\- ]/', '', (string) $fe['remote_range']);
                    if ($wpc_rr_fam !== '' && $wpc_rr_wt !== '' && $wpc_rr_v !== '') {
                        if (!isset($wpc_rr_map356)) { $wpc_rr_map356 = []; }
                        $wpc_rr_map356[$wpc_rr_fam . '|' . $wpc_rr_wt . '|' . $wpc_rr_st] = $wpc_rr_v;
                    }
                }
                // v7.10.398 remote_dup {hosts, css_links}: the service's authoritative "our
                // subset supersedes the remote face, drop its <link>" signal (budget_basis
                // provider_replacement). It OWNS the coverage guarantee (observed the page,
                // subset the used weights); the plugin trusts it and collects match tokens.
                // Tokens: fam:<family> (safety — never orphan an un-covered family),
                // @href:<css_link> (authoritative exact drop), @host:<host> (family-gated drop).
                if (!empty($fe['remote_dup']) && !empty($fe['family'])) {
                    if (!isset($wpc_rdup398) || !is_array($wpc_rdup398)) { $wpc_rdup398 = []; }
                    $wpc_rdup398['fam:' . strtolower((string) $fe['family'])] = true;
                    $wpc_rd_v = $fe['remote_dup'];
                    if (is_array($wpc_rd_v)) {
                        foreach ((array) ($wpc_rd_v['css_links'] ?? []) as $wpc_cl398) {
                            if (is_string($wpc_cl398) && $wpc_cl398 !== '') { $wpc_rdup398['@href:' . strtolower($wpc_cl398)] = true; }
                        }
                        foreach ((array) ($wpc_rd_v['hosts'] ?? []) as $wpc_hh398) {
                            if (is_string($wpc_hh398) && $wpc_hh398 !== '') { $wpc_rdup398['@host:' . strtolower($wpc_hh398)] = true; }
                        }
                    } elseif (is_string($wpc_rd_v) && stripos($wpc_rd_v, 'http') === 0) {
                        $wpc_rdup398['@href:' . strtolower($wpc_rd_v)] = true;
                    }
                }
                // §3 per-weight metrics{}: keyed 'family|weight|style' (same sanitizers as
                // the subset lane); a metrics{} without a usable weight rides the family
                // lane. Entries without metrics keep today's behavior untouched.
                if (!empty($fe['metrics']) && is_array($fe['metrics']) && !empty($fe['family'])) {
                    $wpc_mw356 = trim(preg_replace('/\s+/', ' ', preg_replace('/[^0-9 ]/', ' ', (string) ($fe['weight'] ?? ''))));
                    $wpc_ms356 = (strtolower((string) ($fe['style'] ?? 'normal')) === 'italic') ? 'italic' : 'normal';
                    if ($wpc_mw356 !== '' && preg_match('/^\d{1,4}( \d{1,4})?$/', $wpc_mw356) && $wpc_pwn356 < 16) {
                        $metrics[(string) $fe['family'] . '|' . $wpc_mw356 . '|' . $wpc_ms356] = $fe['metrics'];
                        $wpc_pwn356++;
                    } elseif (!isset($metrics[(string) $fe['family']])) {
                        $metrics[(string) $fe['family']] = $fe['metrics'];
                    }
                }
                if (!empty($fe['validated']) && !empty($fe['family'])) {
                    $wpc_fdval219   = isset($wpc_fdval219) ? $wpc_fdval219 : [];
                    $wpc_fdval219[] = (string) $fe['family'];
                }
                if ($n >= 6 || empty($fe['url'])) { continue; }
                if ((time() - $wpc_fonts_t0) > (int) apply_filters('wpc_fonts_download_budget', 10)) { $wpc_dlstop356 = true; }
                if ($wpc_dlstop356) { continue; }
                $fu = (string) $fe['url'];
                $fh = (string) parse_url($fu, PHP_URL_HOST);
                $fb = basename((string) parse_url($fu, PHP_URL_PATH));
                if (stripos($fh, 'critical-css-mc.b-cdn.net') === false || !preg_match('/^[A-Za-z0-9._-]+\.woff2$/', $fb)) { continue; }
                $dst = rtrim(WPS_IC_FONTS_DIR, '/') . '/' . $fb;
                if (!file_exists($dst) || (int) @filesize($dst) !== (int) ($fe['bytes'] ?? -1)) {
                    $fr = wp_remote_get($fu, ['timeout' => 4]);
                    $fbody = (!is_wp_error($fr) && wp_remote_retrieve_response_code($fr) === 200) ? wp_remote_retrieve_body($fr) : '';
                    if ($fbody !== '' && strlen($fbody) <= 65536 && strncmp($fbody, 'wOF2', 4) === 0) {
                        $tmp = $dst . '.tmp.' . getmypid();
                        if (wpc_crit_meta_write($tmp, $fbody) !== false) { @rename($tmp, $dst); $n++; }
                    }
                } else { $n++; }
            }
            if (defined('WPS_IC_CRITICAL')) {
                $cd = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
                if (!is_dir($cd)) { @wp_mkdir_p($cd); }
                if (!empty($metrics)) { wpc_crit_meta_write($cd . 'font-metrics.json', wp_json_encode($metrics)); update_option('wpc_font_metrics_present', 1, false); }
                // Persist the range-gate map collected above (poll transport). Font-scoped, not
                // page-scoped, so one small non-autoloaded option serves every render; written only
                // when the artifact actually carried remote_range, and only on change.
                if (!empty($wpc_rr_diag356)) {
                    update_option('wpc_fonts_consume_diag', ['t' => time(), 'src' => 'shared-consume', 'rows' => array_slice($wpc_rr_diag356, 0, 8)], false);
                }
                if (!empty($wpc_rr_map356) && get_option('wpc_font_remote_ranges') !== $wpc_rr_map356) {
                    update_option('wpc_font_remote_ranges', $wpc_rr_map356, false);
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('font-remote-ranges', (string) $urlKey, '', ['n' => count($wpc_rr_map356), 'src' => 'consume']);
                    }
                }
                // v3.51.0 deterministic verdict (woff2-table metrics): the trustworthy gate
                // for font-display 'smart' -> optional.
                if (!empty($wpc_fdval219)) {
                    update_option('wpc_font_metrics_validated', ['t' => time(), 'fams' => array_values(array_unique($wpc_fdval219))], false);
                }
                if (apply_filters('wpc_atf_subset_inline', true)) {
                    $sub = ''; $sn = 0;
                    foreach ($fonts as $fe) {


                        if ($sn >= 4 || !is_array($fe) || empty($fe['url']) || empty($fe['family'])) { continue; }
                        if ((int) ($fe['bytes'] ?? 999999) > 12288) { continue; }
                        $fb = basename((string) parse_url((string) $fe['url'], PHP_URL_PATH));
                        $fp = rtrim(WPS_IC_FONTS_DIR, '/') . '/' . $fb;
                        if (!preg_match('/^[A-Za-z0-9._-]+\.woff2$/', $fb) || !@is_readable($fp)) { continue; }
                        $w = @file_get_contents($fp);
                        if ($w === false || $w === '' || strncmp($w, 'wOF2', 4) !== 0) { continue; }
                        $fam = str_replace(["'", "\\", "\r", "\n", '<', '>'], '', (string) $fe['family']);


                        $wt  = trim(preg_replace('/\s+/', ' ', preg_replace('/[^0-9 ]/', ' ', (string) ($fe['weight'] ?? '400'))));
                        if ($wt === '' || !preg_match('/^\d{1,4}( \d{1,4})?$/', $wt)) { $wt = '400'; }
                        $st  = (strtolower((string) ($fe['style'] ?? 'normal')) === 'italic') ? 'italic' : 'normal';
                        $ur  = preg_replace('/[^0-9A-Fa-fUu+,\- ]/', '', (string) ($fe['unicode_range'] ?? ''));
                        if ($fam === '' || $wt === '') { continue; }
                        $sub .= "@font-face{font-family:'" . $fam . "';font-weight:" . $wt . ';font-style:' . $st
                            . ';src:url(data:font/woff2;base64,' . base64_encode($w) . ") format('woff2');"
                            . ($ur !== '' ? 'unicode-range:' . $ur . ';' : '') . 'font-display:block}';
                        $sn++;
                    }
                    $sp = $cd . 'font-subsets.css';
                    if ($sub !== '') { wpc_crit_meta_write($sp, $sub); }
                    elseif (@is_readable($sp)) { @unlink($sp); }
                }


                $wpc_home_h = function_exists('home_url') ? strtolower((string) parse_url(home_url(), PHP_URL_HOST)) : '';
                $wpc_strip_w = function ($h) { return strpos($h, 'www.') === 0 ? substr($h, 4) : $h; };


                // Raleway 400/500/600 all in-chain); preloading only #1 left #2/#3 a full CSS→face


                $wpc_dom114 = [];
                foreach ($fonts as $wpc_fe114) {
                    if (count($wpc_dom114) >= 3) { break; }
                    if (!is_array($wpc_fe114) || empty($wpc_fe114['src_url']) || !is_string($wpc_fe114['src_url'])) { continue; }


                    if (isset($wpc_fe114['kind']) && $wpc_fe114['kind'] === 'icon') { continue; }
                    $wpc_su114 = trim((string) $wpc_fe114['src_url']);
                    if (substr((string) parse_url($wpc_su114, PHP_URL_PATH), -6) !== '.woff2') { continue; }
                    $wpc_sh114 = strtolower((string) parse_url($wpc_su114, PHP_URL_HOST));
                    if ($wpc_sh114 !== '' && ($wpc_home_h === '' || $wpc_strip_w($wpc_sh114) !== $wpc_strip_w($wpc_home_h))) {
                        continue;
                    }
                    if (!in_array($wpc_su114, $wpc_dom114, true)) { $wpc_dom114[] = $wpc_su114; }
                }
                if (!empty($wpc_dom114)) { wpc_crit_meta_write($cd . 'font-preload.txt', implode("\n", $wpc_dom114)); }
                elseif (@is_readable($cd . 'font-preload.txt')) { @unlink($cd . 'font-preload.txt'); }
            }
            // v7.10.398: persist remote_dup match tokens as a global option (fonts localization
            // is site-consistent — a family the service subsets is subset on every page). The
            // output pass drops matching <link>s. Only faces that actually inlined a subset
            // (sn>0) count, so a dropped subset never orphans its remote in the same pass.
            if (!empty($wpc_rdup398) && is_array($wpc_rdup398) && isset($sn) && (int) $sn > 0) {
                $wpc_rdcur398 = get_option('wps_ic_fonts_remote_dup');
                $wpc_rdcur398 = is_array($wpc_rdcur398) ? $wpc_rdcur398 : [];
                $wpc_rdcur398 = array_slice(array_merge($wpc_rdcur398, array_keys($wpc_rdup398)), -40);
                update_option('wps_ic_fonts_remote_dup', array_values(array_unique($wpc_rdcur398)), false);
            }
            if (function_exists('wpc_cache_first_log')) {


                wpc_cache_first_log('fonts-landed', $urlKey, '', ['recv' => is_array($fonts) ? count($fonts) : 0, 'dl' => $n, 'subset' => isset($sn) ? (int) $sn : -1, 'metrics' => count($metrics), 'rdup' => isset($wpc_rdup398) ? count($wpc_rdup398) : 0]);
            }
            // Sticky subsets marker: the preload standdowns must not flap during the brief
            // window an artifact land rewrites font-subsets.css — one flapped render mints a
            // preload-heavy copy the edge then serves for its whole TTL.
            if (isset($sn) && (int) $sn > 0) {
                update_option('wpc_subsets_seen', time(), false);
            }
            // The metrics table may land AFTER used.css was fetched-and-spliced (receipted:
            // used.css 19:30:57, metrics 19:30:58) — the stored file then carries no fallback
            // stacks and pre-swap text paints in raw fallback metrics. Re-stitch on landing;
            // the mtime bump re-versions the ?uv= href so pages pick it up.
            if (function_exists('wpc_fallback_restitch')) { wpc_fallback_restitch($urlKey); }
            if (function_exists('wpc_autopurge_on_land')) { wpc_autopurge_on_land($urlKey); }
            return $n;
        } catch (\Throwable $e) {
            return 0;
        }
    }
}


if (!function_exists('wpc_perf_debug_report')) {
    function wpc_perf_debug_report()
    {
        if (defined('WPC_PERF_DEBUG_DISABLE') && WPC_PERF_DEBUG_DISABLE) {
            wp_die('disabled', '', ['response' => 404]);
        }
        $wpc_adm742 = current_user_can('manage_options');
        if (!$wpc_adm742) {
            // Read-only lookup: an unauthenticated request must never be able to MINT the secret
            // by asking for it, only to present one it already holds.
            $wpc_tok742 = function_exists('wpc_perf_debug_token742') ? wpc_perf_debug_token742(false) : '';
            if (!isset($_GET['t']) || $wpc_tok742 === '' || !hash_equals($wpc_tok742, (string) $_GET['t'])) {
                wp_die('denied', '', ['response' => 403]);
            }
        }
        if (function_exists('nocache_headers')) { nocache_headers(); }
        header('Content-Type: text/plain; charset=utf-8');
        $url = !empty($_GET['url']) ? esc_url_raw((string) $_GET['url']) : home_url('/');
        $o = [];
        $o[] = "=== WPC PERF DEBUG  (plugin " . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?') . ") ===";
        // Minted here, on an admin-authenticated view, so a fresh install needs no wp-config edit.
        if ($wpc_adm742 && function_exists('wpc_perf_debug_token742')) {
            $o[] = "token: " . wpc_perf_debug_token742() . "   (append &t=TOKEN to read this without being logged in)";
        }
        // v7.10.743 — same trip arms the benchmark: mint the tier key, set the cache flag, and
        // rewrite the drop-in so the pre-WordPress read gate can see it. Admin branch only.
        if ($wpc_adm742 && class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'tierKey')) {
            $wpc_tk743 = wps_ic_url_key::tierKey();
            if ($wpc_tk743 !== '') {
                if (get_option('wpc_tier_cache', '') !== '1') {
                    update_option('wpc_tier_cache', '1', false);
                }
                if (!class_exists('wps_ic_htaccess') && defined('WPS_IC_DIR')) {
                    @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
                }
                if (class_exists('wps_ic_htaccess')) {
                    $wpc_ht743 = new wps_ic_htaccess();
                    $wpc_ht743->setWPCache(true);
                    $wpc_ht743->setAdvancedCache();
                }
                $o[] = "tier_key: " . $wpc_tk743
                     . "   cacheable_arms: " . ((defined('WPC_TIER_CACHE') && WPC_TIER_CACHE) ? 'ARMED' : 'armed-next-request');
                $o[] = "tier_urls: ?wpc_tier=control|free|local|edge &wpc_key=" . $wpc_tk743;
            }
        }
        // The natural-assets gate needs a MIME proof on CF zones, and its probe only ever fires
        // from an is_admin()/cron context or a cold render's loopback — a site can sit unproven
        // (every asset in its m:0 working form) indefinitely. admin-ajax IS an is_admin() context,
        // so this report is the arming trip: run the probe synchronously when unproven.
        $wpc_mok748 = (string) get_option('wpc_v2_cf_asset_mime_ok', '');
        if ($wpc_mok748 !== '1' && function_exists('wpc_v2_asset_mime_probe_run')) {
            delete_transient('wpc_v2_cf_asset_mime_retry');
            delete_transient('wpc_v2_asset_probe_inflight');
            $wpc_pr748 = wpc_v2_asset_mime_probe_run();
            $wpc_mok748 = (string) get_option('wpc_v2_cf_asset_mime_ok', '');
            $o[] = "natural_assets: mime probe RAN -> " . ($wpc_pr748 ? 'PROVEN' : 'refused (zone not serving text/css yet)');
            // Newly proven: cached HTML still carries the m:0 working forms (they keep serving —
            // pass-through), so drop the page cache once and the next render emits natural.
            if ($wpc_pr748 && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                wps_ic_cache::removeHtmlCacheFiles('all');
                $o[] = "natural_assets: page cache purged — next render collapses m:0";
            }
        }
        $o[] = "natural_assets: mime_ok=" . ($wpc_mok748 !== '' ? $wpc_mok748 : 'unproven')
             . " | gate=" . ((class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on') && wps_rewriteLogic::natural_assets_on()) ? 'OPEN (m:0 collapses)' : 'SHUT (m:0 stands)');
        // Origin security headers replayed by the pull can block cross-origin image delivery
        // (ERR_BLOCKED_BY_RESPONSE) — surface the guard's verdict, and run it now if never run.
        $wpc_cg823 = get_option('wpc_corp_guard_state', []);
        if ((!is_array($wpc_cg823) || empty($wpc_cg823['state'])) && function_exists('wpc_corp_guard_tick')) {
            wpc_corp_guard_tick(true);
            $wpc_cg823 = get_option('wpc_corp_guard_state', []);
        }
        $o[] = "corp_guard: " . ((is_array($wpc_cg823) && !empty($wpc_cg823['state']))
            ? $wpc_cg823['state'] . ' (origin corp=' . (isset($wpc_cg823['corp']) ? ($wpc_cg823['corp'] !== '' ? $wpc_cg823['corp'] : 'none') : '?')
                . (isset($wpc_cg823['after']) ? ' -> ' . ($wpc_cg823['after'] !== '' ? $wpc_cg823['after'] : 'none') : '') . ')'
            : 'never-ran (guard off, cdn off, or probe unreachable)');
        // THE WHOLE CHAIN, in evaluation order, with the probe's ACTUAL last response. A gate
        // that only reports its verdict makes every refusal a guess about invisible option
        // state; this makes "why is this site still on m:0" readable in one line.
        $set = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings'); $set = is_array($set) ? $set : [];
        // The zone statics are populated by the cdn bootstrap, which a front-end render runs and
        // admin-ajax does not: reading them here reported EMPTY on sites whose zone is correct and
        // serving, and printed a probe URL of https:/// . Fall back to the options the bootstrap
        // itself reads, and say which vantage the value came from.
        $wpc_zn812  = (class_exists('wps_rewriteLogic') && is_string(wps_rewriteLogic::$zoneName)) ? trim((string) wps_rewriteLogic::$zoneName) : '';
        $wpc_zsr812 = $wpc_zn812 !== '' ? '' : ' (from options; cdn bootstrap has not run in this admin context)';
        if ($wpc_zn812 === '') {
            $wpc_cfo812 = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
            $wpc_cfn812 = (string) get_option(defined('WPS_IC_CF_CNAME') ? WPS_IC_CF_CNAME : 'wps-ic-cf-cname');
            $wpc_zn812  = (is_array($wpc_cfo812) && !empty($wpc_cfo812['settings']['cdn']) && $wpc_cfn812 !== '')
                ? $wpc_cfn812 : (string) get_option('ic_custom_cname');
            if ($wpc_zn812 === '') { $wpc_zn812 = (string) get_option('ic_cdn_zone_name'); }
            $wpc_zn812 = trim($wpc_zn812);
            if ($wpc_zn812 === '') { $wpc_zsr812 = ''; }
        }
        $wpc_ng810 = [];
        $wpc_ng810[] = 'why=' . ((class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_nat_why808'))
            ? (string) wps_rewriteLogic::wpc_nat_why808() : 'n/a');
        $wpc_ng810[] = 'live_cdn=' . (isset($set['live-cdn']) ? (string) $set['live-cdn'] : 'unset');
        $wpc_ng810[] = 'zone=' . ($wpc_zn812 !== '' ? substr($wpc_zn812, 0, 46) . $wpc_zsr812 : 'EMPTY');
        $wpc_ng810[] = 'origin=' . (function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '?');
        $wpc_ng810[] = 'zone_is_cf=' . ((class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'zone_is_cf') && wps_rewriteLogic::zone_is_cf()) ? 'Y' : 'n');
        $wpc_ng810[] = 'suppressed=' . ((function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) ? 'YES' : 'no');
        $wpc_ng810[] = 'cf_seen=' . ((int) get_option('wpc_v2_cf_assets_seen', 0) > 0 ? 'Y' : 'n');
        $wpc_ng810[] = 'emission_ready=' . ((class_exists('WPC_Negotiated_Delivery') && method_exists('WPC_Negotiated_Delivery', 'emission_ready') && WPC_Negotiated_Delivery::emission_ready()) ? 'Y' : 'n');
        $wpc_ng810[] = 'proof_age=' . (((int) get_option('wpc_v2_cf_asset_mime_ts', 0) > 0) ? (time() - (int) get_option('wpc_v2_cf_asset_mime_ts', 0)) . 's' : 'never');
        $wpc_ng810[] = 'retry_lock=' . ((function_exists('get_transient') && get_transient('wpc_v2_cf_asset_mime_retry') !== false) ? 'HELD' : 'clear');
        $o[] = "natural_gate: " . implode(' | ', $wpc_ng810);
        $wpc_nl810 = get_option('wpc_v2_cf_asset_mime_last', []);
        $o[] = "natural_probe_last: " . (is_array($wpc_nl810) && !empty($wpc_nl810)
            ? ('at=' . (isset($wpc_nl810['at']) ? (time() - (int) $wpc_nl810['at']) . 's ago' : '?')
               . ' code=' . (isset($wpc_nl810['code']) ? (int) $wpc_nl810['code'] : '?')
               . ' ct=' . (!empty($wpc_nl810['ct']) ? (string) $wpc_nl810['ct'] : '-')
               . ' srv=' . (!empty($wpc_nl810['srv']) ? (string) $wpc_nl810['srv'] : '-')
               . (!empty($wpc_nl810['err']) ? ' err=' . (string) $wpc_nl810['err'] : '')
               . ' zone=' . (isset($wpc_nl810['zone']) ? (string) $wpc_nl810['zone'] : '?'))
            : 'never run (no probe has completed on this install)');
        $o[] = "natural_probe_url: " . ($wpc_zn812 !== ''
             ? 'https://' . preg_replace('#/.*$#', '', $wpc_zn812) . '/wp-includes/css/dist/block-library/style.min.css   (must answer 200 + text/css FROM THIS SERVER, not from your browser)'
             : 'no zone configured — nothing to probe');
        // suppressed=YES has five possible trips; a verdict without the tripped branch makes
        // every "toggle on, serves origin" ticket a guessing game. Print each predicate.
        $wpc_sw933 = [];
        $wpc_al933 = get_option('wps_ic_allow_live', 'unset');
        $wpc_sw933[] = 'account_live=' . ($wpc_al933 === 'unset' ? 'unset' : ($wpc_al933 ? '1' : '0 <-TRIP'));
        $wpc_sw933[] = 'env_changed=' . ((function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed())
            ? ((function_exists('wpc_v2_zone_origin_proved') && wpc_v2_zone_origin_proved()) ? 'YES (BRIDGED by measured origin-proof — not suppressing)' : 'YES <-TRIP')
            : 'no');
        $wpc_cfc933 = (defined('WPS_IC_CF_CNAME')) ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $wpc_cfo933 = (defined('WPS_IC_CF')) ? get_option(WPS_IC_CF) : false;
        $wpc_cfv933 = get_option('wpc_cf_cname_verified');
        $wpc_sw933[] = 'cf_cname=' . ($wpc_cfc933 !== ''
            ? (substr($wpc_cfc933, 0, 30) . ' verified=' . (($wpc_cfv933 === '1' || $wpc_cfv933 === 1) ? '1'
                : (is_array($wpc_cfo933) && !empty($wpc_cfo933['settings']['cdn']) ? (string) $wpc_cfv933 . ' <-TRIP' : (string) $wpc_cfv933)))
            : 'none');
        $wpc_sw933[] = 'foreign_zone=' . ((function_exists('wpc_cdn_zone_is_foreign') && wpc_cdn_zone_is_foreign()) ? 'YES <-TRIP' : 'no');
        $wpc_sw933[] = 'zone_disabled=' . ((function_exists('wpc_v2_zone_cdn_disabled') && wpc_v2_zone_cdn_disabled()) ? 'YES <-TRIP' : 'no');
        $wpc_sw933[] = 'auto_disabled=' . ((function_exists('wpc_v2_zone_auto_disabled') && wpc_v2_zone_auto_disabled()) ? 'YES <-TRIP' : 'no');
        $o[] = "cdn_suppress_why: " . implode(' | ', $wpc_sw933);
        // env_changed has NO standing healer unless force/unprov/moved already hold — a
        // never-stamped fingerprint (site provisioned before the mechanism) trips FOREVER.
        // The panel visit arms the bounded self-heal chain: reset (sets force_provision)
        // then ensure_bg -> deferred config sync; a 2xx re-stamps the fingerprint and the
        // suppression clears. Capped at 12 attempts, 2-min pacing — same belts as always.
        if (function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed()
            && function_exists('wpc_v2_provision_reset_for_env') && function_exists('wpc_v2_config_sync_lazy_enabled')
            && apply_filters('wpc_env_heal_armtrip', true)) {
            wpc_v2_provision_reset_for_env();
            // v7.20.10 — run ONE sync inline and print the wire verdict. The ensure_bg path
            // reported ARMED while the sync failed silently (its ok/http_code/reason were
            // returned and dropped); a heal whose failure is invisible is not a heal.
            $wpc_zid933 = '';
            if (function_exists('wpc_v2_get_zone_id')) {
                $wpc_zid933 = (string) wpc_v2_get_zone_id();
            }
            if ($wpc_zid933 === '' || !ctype_digit($wpc_zid933)) {
                $wpc_cn933 = trim((string) get_option('ic_custom_cname'));
                $wpc_zid933 = $wpc_cn933 !== '' ? $wpc_cn933 : trim((string) get_option('ic_cdn_zone_name'));
            }
            if ($wpc_zid933 !== '') {
                $wpc_sr933 = wpc_v2_config_sync_lazy_enabled(
                    $wpc_zid933,
                    function_exists('wpc_v2_get_lazy_enabled') ? wpc_v2_get_lazy_enabled() : false
                );
                $wpc_ec933 = function_exists('wpc_v2_provision_env_changed') && wpc_v2_provision_env_changed();
                $o[] = "env_heal: sync ran inline -> ok=" . (!empty($wpc_sr933['ok']) ? '1' : '0')
                     . " http=" . (isset($wpc_sr933['http_code']) ? (int) $wpc_sr933['http_code'] : '?')
                     . (isset($wpc_sr933['reason']) ? " reason=" . (string) $wpc_sr933['reason'] : '')
                     . " | env_changed now: " . ($wpc_ec933 ? 'STILL TRIPPED' : 'CLEARED (suppression lifts this request)');
                // v7.20.11 — outage bridge: when the declaration cannot re-stamp, MEASURE
                // the invariant instead (uploads asset through the zone vs local bytes).
                if ($wpc_ec933 && function_exists('wpc_v2_zone_origin_probe_run')) {
                    delete_transient('wpc_v2_origin_probe_bk');
                    $wpc_op933 = wpc_v2_zone_origin_probe_run();
                    $o[] = "zone_origin_proof: " . ($wpc_op933
                        ? 'MEASURED OK -> env trip bridged, CDN emission unsuppressed (12h proof; re-proved hourly, re-stamped properly when the orchestrator returns)'
                        : 'FAILED/mismatch -> suppression stands (zone did not serve this origin\'s bytes)');
                }
            } else {
                $o[] = "env_heal: no zone id/cname resolvable — nothing to sync against";
            }
        }
        // The cname trip has a bounded self-healer (config-sync + healthcheck legs already
        // call it); make the panel visit an arming trip too, same pattern as the .748 mime
        // probe: run it synchronously when tripped, report the promote/refuse verdict.
        if ($wpc_cfc933 !== '' && is_array($wpc_cfo933) && !empty($wpc_cfo933['settings']['cdn'])
            && $wpc_cfv933 !== '1' && $wpc_cfv933 !== 1 && function_exists('wpc_v2_cf_cname_reverify')) {
            $wpc_rv933 = wpc_v2_cf_cname_reverify(false);
            $o[] = "cf_cname_reverify: RAN -> " . ($wpc_rv933
                ? 'PROMOTED (verified=1, html cache purged — next render emits the cname)'
                : 'refused (cname not answering yet, or probe throttled <120s)');
        }
        $o[] = "url: $url";
        $o[] = "settings: replace-fonts=" . ($set['replace-fonts'] ?? 'unset') . " | used-css=" . ($set['used-css'] ?? 'unset')
             . " | delay-js-v2=" . ($set['delay-js-v2'] ?? 'unset') . " | delay-js-v3=" . ($set['delay-js-v3'] ?? '(absent=on)');
        // Mode drift: a preset switch REPLACES the settings array with the profile, and any
        // later writer stamping '0' into profile-absent keys hard-disables features the mode
        // intended on (dalton: aggressive profile predated delay-js-v3 -> v3 dead). Diff the
        // stored array against the active mode's CURRENT profile so drift names itself.
        $wpc_pn933 = (string) get_option(defined('WPS_IC_PRESET') ? WPS_IC_PRESET : 'wps_ic_preset_setting', '');
        $wpc_dr933 = [];
        if ($wpc_pn933 !== '' && class_exists('wps_ic_options')) {
            $wpc_pf933 = (new wps_ic_options())->get_preset($wpc_pn933);
            if (is_array($wpc_pf933)) {
                foreach ($wpc_pf933 as $wpc_pk933 => $wpc_pv933) {
                    if (is_array($wpc_pv933) || count($wpc_dr933) >= 12) { continue; }
                    $wpc_sv933 = array_key_exists($wpc_pk933, $set) ? (string) $set[$wpc_pk933] : '(absent)';
                    if ($wpc_sv933 !== (string) $wpc_pv933) {
                        $wpc_dr933[] = $wpc_pk933 . '=' . $wpc_sv933 . ' (profile:' . (string) $wpc_pv933 . ')';
                    }
                }
            }
        }
        $o[] = "mode_drift: preset=" . ($wpc_pn933 !== '' ? $wpc_pn933 : 'none')
             . ($wpc_dr933 ? ' | ' . implode(' | ', $wpc_dr933) : ($wpc_pn933 !== '' ? ' | in sync' : ''));
        // Panel visit doubles as the coherence-heal trip (belt for the upgrade lane): the
        // v2=1+v3=0 pair is unreachable through any current writer, so heal it here too.
        if (isset($set['delay-js-v2']) && $set['delay-js-v2'] == '1'
            && isset($set['delay-js-v3']) && $set['delay-js-v3'] === '0'
            && apply_filters('wpc_settings_coherence_heal', true)) {
            $set['delay-js-v3'] = '1';
            update_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings', $set);
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                wps_ic_cache::removeHtmlCacheFiles('all');
            }
            $o[] = "coherence_heal: RAN -> delay-js-v3=1 (html cache purged; next render drops legacy optimize.js)";
        }
        $key = class_exists('wps_ic_url_key') ? (new wps_ic_url_key())->setup($url) : '';
        $cd  = defined('WPS_IC_CRITICAL') ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/' : '';
        // The full consume-gate chain: every runtime reason a crit-bearing dir still serves
        // nothing, in evaluation order — settings, excludes, caps, then the absent-resolvers
        $wpc_cg766 = [];
        $wpc_cg766[] = 'critical.css=' . (isset($set['critical']['css']) ? (string) $set['critical']['css'] : 'UNSET');
        $wpc_cg766[] = 'inline-css=' . (isset($set['inline-css']) ? (string) $set['inline-css'] : 'unset');
        $wpc_cg766[] = 'dev_mode=' . (!empty($set['developer_mode']) ? (string) $set['developer_mode'] : '0');
        $wpc_ex766 = get_option('wpc-excludes');
        $wpc_exl766 = (is_array($wpc_ex766) && !empty($wpc_ex766['critical_css']) && is_array($wpc_ex766['critical_css'])) ? $wpc_ex766['critical_css'] : [];
        $wpc_hit766 = '';
        foreach ($wpc_exl766 as $wpc_pat766) {
            if ($wpc_pat766 !== '' && strpos((string) $url, (string) $wpc_pat766) !== false) { $wpc_hit766 = (string) $wpc_pat766; break; }
        }
        $wpc_cg766[] = 'url_excludes=' . count($wpc_exl766) . ($wpc_hit766 !== '' ? ' MATCH:' . substr($wpc_hit766, 0, 40) : ' no-match');
        $wpc_pgx766 = 'none';
        if (is_array($wpc_ex766) && isset($wpc_ex766['page_excludes']) && is_array($wpc_ex766['page_excludes'])) {
            $wpc_pid766 = function_exists('url_to_postid') ? url_to_postid($url) : 0;
            foreach ([(string) $wpc_pid766, 'home'] as $wpc_pk766) {
                if (isset($wpc_ex766['page_excludes'][$wpc_pk766]['critical_css'])) {
                    $wpc_pgx766 = $wpc_pk766 . '=' . (string) $wpc_ex766['page_excludes'][$wpc_pk766]['critical_css'];
                    break;
                }
            }
        }
        $wpc_cg766[] = 'page_exclude=' . $wpc_pgx766;
        $wpc_cg766[] = 'caps.css=' . (function_exists('wpc_caps_enabled') ? (wpc_caps_enabled('css') ? 'allowed' : 'DENIED(ui)') : 'n/a');
        $wpc_cg766[] = 'inv2_gate=' . ((function_exists('wpc_inv2_gate_serve') && wpc_inv2_gate_serve($key)) ? 'BLOCKING' : 'clear');
        $wpc_cg766[] = 'purge_bypass=' . ((function_exists('wpc_crit_bypass_active') && wpc_crit_bypass_active($key)) ? 'ACTIVE' : 'clear');
        $wpc_epm766 = (int) get_option('wpc_crit_epoch_min', 0);
        $wpc_eph766 = ($cd !== '' && @is_file($cd . 'crit_epoch.txt')) ? (int) trim((string) @file_get_contents($cd . 'crit_epoch.txt')) : 0;
        $wpc_cg766[] = 'epoch=' . $wpc_eph766 . '/floor=' . $wpc_epm766 . (($wpc_epm766 > 0 && $wpc_eph766 < $wpc_epm766) ? ' STALE-BLOCKING' : ' ok');
        $o[] = "css_consume_gate: " . implode(' | ', $wpc_cg766);
        $wpc_pl767 = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
        $o[] = "pressure: load1=" . (is_array($wpc_pl767) && isset($wpc_pl767[0]) ? round((float) $wpc_pl767[0], 2) : 'n/a')
             . " | cores=" . (function_exists('wpc_box_cores') ? (int) wpc_box_cores() : 0)
             . ((function_exists('wpc_cores_known767') && !wpc_cores_known767()) ? '(UNKNOWN: load-shed disabled)' : '')
             . " | under_pressure=" . ((function_exists('wpc_under_pressure') && wpc_under_pressure()) ? 'YES (rewrites shed)' : 'no')
             . " | safe_mode=" . (get_option('wpc_safe_mode', 0) ? 'ON' : 'off');
        $o[] = "url_key: $key  crit_dir exists: " . (is_dir($cd) ? 'Y' : 'N');
        if (is_dir($cd)) {
            foreach (['critical_desktop.css', 'critical_mobile.css', 'lcp.json', 'font-subsets.css', 'font-metrics.json', 'tpl.txt'] as $f) {
                $o[] = "  $f: " . (@is_readable($cd . $f) ? filesize($cd . $f) . 'B' : 'ABSENT');
            }
            if (@is_readable($cd . 'lcp.json')) {
                $j = json_decode((string) @file_get_contents($cd . 'lcp.json'), true);
                $le = (is_array($j) && isset($j['lcp_element'])) ? $j['lcp_element'] : null;
                foreach (['desktop', 'mobile'] as $dev) {
                    $e = (is_array($le) && isset($le[$dev])) ? $le[$dev] : null;
                    $o[] = "  lcp_element[$dev]: " . (is_array($e) ? (($e['type'] ?? '?') . ' url=' . substr((string) ($e['url'] ?? ''), -54) . ' net_url=' . substr((string) ($e['net_url'] ?? '-'), -30) . ' sel=' . substr((string) ($e['sel'] ?? ''), 0, 36)) : 'null/absent');
                }
                // THE CONSUMER'S EYE. The lines above read lcp_element[dev]; the preload emitter
                // prefers lcp[dev] and falls back to a flat lcp_element. A panel that reads a
                // different container than the code it describes reports a healthy artifact for a
                // lane that is refusing, so print what the EMITTER would resolve, same precedence.
                foreach (['desktop', 'mobile'] as $wpc_cd813) {
                    $wpc_cc814 = [];
                    if (is_array($j) && isset($j['lcp'][$wpc_cd813]) && is_array($j['lcp'][$wpc_cd813])) {
                        $wpc_cc814['lcp[' . $wpc_cd813 . ']'] = $j['lcp'][$wpc_cd813];
                    }
                    if (is_array($le) && isset($le[$wpc_cd813]) && is_array($le[$wpc_cd813])) {
                        $wpc_cc814['lcp_element[' . $wpc_cd813 . ']'] = $le[$wpc_cd813];
                    }
                    if (is_array($le) && (isset($le['stem']) || isset($le['url']))) {
                        $wpc_cc814['lcp_element(flat)'] = $le;
                    }
                    $wpc_ct814 = ''; $wpc_cu813 = ''; $wpc_ts814 = '-'; $wpc_us814 = '-';
                    foreach ($wpc_cc814 as $wpc_cn814 => $wpc_c814) {
                        if ($wpc_ct814 === '' && isset($wpc_c814['type']) && is_string($wpc_c814['type'])) {
                            $wpc_ct814 = strtolower(trim($wpc_c814['type'])); $wpc_ts814 = $wpc_cn814;
                        }
                        if ($wpc_cu813 === '' && isset($wpc_c814['url']) && is_string($wpc_c814['url'])) {
                            $wpc_cu813 = trim($wpc_c814['url']); $wpc_us814 = $wpc_cn814;
                        }
                    }
                    $o[] = "  lcp_consumer[$wpc_cd813]: containers=" . (empty($wpc_cc814) ? 'none' : implode(',', array_keys($wpc_cc814)))
                         . ' type=' . ($wpc_ct814 !== '' ? $wpc_ct814 . '<' . $wpc_ts814 : 'ABSENT')
                         . ' url=' . ($wpc_cu813 !== '' ? substr($wpc_cu813, -40) . '<' . $wpc_us814 : 'ABSENT')
                         . ' bg_allowed=' . (($wpc_cu813 !== '' && class_exists('wps_rewriteLogic')
                             && method_exists('wps_rewriteLogic', 'wpc_lcp_bg_url_allowed'))
                             ? (wps_rewriteLogic::wpc_lcp_bg_url_allowed($wpc_cu813) ? 'Y' : 'NO') : '-')
                         . ' => bg-preload ' . (($wpc_ct814 === 'bg' && $wpc_cu813 !== '' && stripos($wpc_cu813, 'data:') !== 0)
                             ? 'ELIGIBLE' : 'refused');
                }
                $wpc_atfct776 = function ($j, $g) {
                    if (!isset($j['atf_images'][$g]) || !is_array($j['atf_images'][$g])) { return 0; }
                    $n = 0;
                    foreach ($j['atf_images'][$g] as $e) { if (is_array($e) && !empty($e['stem']) && !empty($e['css_w'])) { $n++; } }
                    return $n;
                };
                $o[] = "  atf_images: " . (isset($j['atf_images'])
                    ? 'present (usable m=' . $wpc_atfct776($j, 'mobile') . ' d=' . $wpc_atfct776($j, 'desktop')
                        . (isset($j['census']['method']) ? ' method=' . $j['census']['method'] : '')
                        . (!empty($j['wpc_inherited']) ? ' INHERITED from=' . (string) $j['wpc_inherited']['from']
                            . ' age=' . max(0, time() - (int) $j['wpc_inherited']['t']) . 's' : '') . ')'
                    : 'absent');
                // Why the preload came out the way it did, per hint entry. A bare-href preload on
                // a RESPONSIVE element is the double-fetch hazard, so the three fields that decide
                // it must be visible: rungs_complete gates the ladder, url_is_authoritative gates
                // whether a bare href is allowed at all, and sizes_attr feeds imagesizes.
                $wpc_lph450 = (isset($j['hints']['lcp_preload']) && is_array($j['hints']['lcp_preload']))
                    ? $j['hints']['lcp_preload'] : null;
                if (is_array($wpc_lph450)) {
                    foreach ($wpc_lph450 as $wpc_k450 => $wpc_e450) {
                        if (!is_array($wpc_e450)) { continue; }
                        $wpc_r450 = (isset($wpc_e450['rungs']) && is_array($wpc_e450['rungs'])) ? $wpc_e450['rungs'] : null;
                        $wpc_d450 = '';
                        if ($wpc_r450 && isset($wpc_r450[0]) && is_array($wpc_r450[0])) {
                            $wpc_d450 = isset($wpc_r450[0]['w']) ? 'w' : (isset($wpc_r450[0]['x']) ? 'x' : 'BARE');
                        }
                        $o[] = "  lcp_preload[$wpc_k450]: dev=" . (string) ($wpc_e450['device'] ?? '?')
                            . ' responsive=' . (array_key_exists('responsive', $wpc_e450) ? (!empty($wpc_e450['responsive']) ? 'Y' : 'N') : '-')
                            . ' rungs=' . ($wpc_r450 === null ? 'ABSENT' : (string) count($wpc_r450))
                            . ($wpc_d450 !== '' ? '/' . $wpc_d450 : '')
                            . ' complete=' . (array_key_exists('rungs_complete', $wpc_e450) ? (!empty($wpc_e450['rungs_complete']) ? 'Y' : 'N') : '-')
                            . ' authoritative=' . (array_key_exists('url_is_authoritative', $wpc_e450) ? (!empty($wpc_e450['url_is_authoritative']) ? 'Y' : 'N') : '-')
                            . ' sizes_attr=' . (isset($wpc_e450['sizes_attr']) ? (string) $wpc_e450['sizes_attr'] : '-')
                            . ' css_w=' . (isset($wpc_e450['css_w']) ? (int) $wpc_e450['css_w'] : 0)
                            . ' dpr=' . (isset($wpc_e450['dpr']) ? (string) $wpc_e450['dpr'] : '-');
                        // The one combination that emits a bare-href preload on a responsive img.
                        if (!empty($wpc_e450['responsive']) && !empty($wpc_e450['url_is_authoritative'])
                            && ($wpc_r450 === null || empty($wpc_e450['rungs_complete']))) {
                            $o[] = "     !! responsive + authoritative but NO usable ladder -> bare-href preload"
                                 . " (one fetch only while the img resolves to the SAME rung; a different DPR double-fetches)";
                        }
                        // ORIGIN-SERVE FEASIBILITY (&originprobe=1). Moving the LCP image off the
                        // zone removes a simulated cross-origin handshake, but ONLY if the origin
                        // holds the SAME rung bytes — otherwise the browser gets a different file
                        // and the test measures bytes instead of the handshake. Zone URLs are a
                        // pure host swap of the origin path (the natural-URL contract), so the
                        // local path is exact, not inferred. Both lanes must move together: an
                        // origin <img> beside a zone preload is the .444 double-fetch.
                        if (!empty($_GET['originprobe']) && $wpc_r450) {
                            // URL path -> local path must go through the uploads baseurl/basedir PAIR.
                            // ABSPATH . path is wrong three ways: /storage is a base path that is not
                            // under ABSPATH, a subdirectory install double-counts the subdir so every
                            // file reads MISSING, and a custom UPLOADS dir maps elsewhere. All three
                            // would report "swap impossible" when it may be possible — a false verdict
                            // is worse than no probe.
                            $wpc_ud461 = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
                            $wpc_ubu461 = is_array($wpc_ud461) && !empty($wpc_ud461['baseurl'])
                                ? rtrim((string) parse_url((string) $wpc_ud461['baseurl'], PHP_URL_PATH), '/') : '';
                            $wpc_ubd461 = is_array($wpc_ud461) && !empty($wpc_ud461['basedir'])
                                ? rtrim((string) $wpc_ud461['basedir'], '/') : '';
                            $wpc_have461 = 0; $wpc_want461 = 0; $wpc_lb461 = 0;
                            $wpc_miss461 = []; $wpc_nomap461 = []; $wpc_fmt461 = []; $wpc_res461 = [];
                            foreach ($wpc_r450 as $wpc_rg461) {
                                if (!is_array($wpc_rg461) || empty($wpc_rg461['url']) || !is_string($wpc_rg461['url'])) { continue; }
                                $wpc_want461++;
                                $wpc_pth461 = (string) parse_url(trim($wpc_rg461['url']), PHP_URL_PATH);
                                if ($wpc_pth461 === '' || strpos($wpc_pth461, '..') !== false
                                    || $wpc_ubu461 === '' || $wpc_ubd461 === ''
                                    || strpos($wpc_pth461, $wpc_ubu461 . '/') !== 0) {
                                    // Not under the uploads baseurl (e.g. /storage) — edge-only by
                                    // layout, not a missing file. Different fact, said differently.
                                    $wpc_nomap461[] = basename($wpc_pth461);
                                    continue;
                                }
                                $wpc_loc461 = $wpc_ubd461 . '/' . ltrim(substr($wpc_pth461, strlen($wpc_ubu461)), '/');
                                // The ladder URL carries the DELIVERED extension (.webp, ?src=png),
                                // but the CDN BAKES the rung to disk as {base}-{W}x{H}.avif. Testing
                                // the URL's extension verbatim reported "absent" for a rung sitting
                                // right there under another extension — 1/14 when the real question
                                // is the variant FAMILY. Same WRITE<->READ symmetry the variant
                                // filename derivation depends on: never assume the served extension
                                // is the stored one.
                                $wpc_stem461 = (string) preg_replace('/\.[a-z0-9]+$/i', '', $wpc_loc461);
                                $wpc_hitx461 = ''; $wpc_hitb461 = 0;
                                foreach (['avif', 'webp', 'jpg', 'jpeg', 'png', 'gif'] as $wpc_xt461) {
                                    $wpc_cnd461 = $wpc_stem461 . '.' . $wpc_xt461;
                                    if (!@is_readable($wpc_cnd461) || @is_dir($wpc_cnd461)) { continue; }
                                    $wpc_csz461 = (int) @filesize($wpc_cnd461);
                                    if ($wpc_csz461 <= 0) { continue; }
                                    // Report the SMALLEST present variant — that is what an origin
                                    // serve would actually send, so it is the byte figure that
                                    // decides whether the swap is one-variable.
                                    if ($wpc_hitx461 === '' || $wpc_csz461 < $wpc_hitb461) {
                                        $wpc_hitx461 = $wpc_xt461; $wpc_hitb461 = $wpc_csz461;
                                    }
                                }
                                $wpc_rw461 = preg_match('/-(\d+)x\d+$/', $wpc_stem461, $wpc_rwm461) ? $wpc_rwm461[1] : '?';
                                if ($wpc_hitx461 !== '') {
                                    $wpc_have461++;
                                    $wpc_lb461 += $wpc_hitb461;
                                    $wpc_fmt461[$wpc_hitx461] = (int) ($wpc_fmt461[$wpc_hitx461] ?? 0) + 1;
                                    $wpc_res461[] = $wpc_rw461 . '=' . $wpc_hitx461 . '/' . (int) round($wpc_hitb461 / 1024) . 'K';
                                } else {
                                    // "Absent" and "present at a different height" need completely
                                    // different fixes — generate the bake vs align the derivation
                                    // (1200x950 at w=510 is h=403.75: floor 403, round 404). Say which.
                                    $wpc_nm461 = '';
                                    if (preg_match('/^(.*)-(\d+)x(\d+)$/', $wpc_stem461, $wpc_nmm461)) {
                                        $wpc_nh461 = [];
                                        foreach ((array) @glob($wpc_nmm461[1] . '-' . $wpc_nmm461[2] . 'x*') as $wpc_nf461) {
                                            if (preg_match('/-\d+x(\d+)\.([a-z0-9]+)$/i', $wpc_nf461, $wpc_nfm461)) {
                                                $wpc_nh461[] = 'h' . $wpc_nfm461[1] . '.' . strtolower($wpc_nfm461[2]);
                                            }
                                        }
                                        if ($wpc_nh461) {
                                            $wpc_nm461 = '  <-- SAME WIDTH is on disk as '
                                                . implode(',', array_unique($wpc_nh461))
                                                . ' but the ladder wants h' . $wpc_nmm461[3]
                                                . ': DERIVATION MISMATCH, not a missing bake';
                                        }
                                    }
                                    $wpc_miss461[] = basename($wpc_stem461) . '.*' . $wpc_nm461;
                                    $wpc_res461[] = $wpc_rw461 . '=MISS';
                                }
                            }
                            $o[] = sprintf('     origin-serve: %d/%d rungs readable on local disk%s',
                                $wpc_have461, $wpc_want461,
                                ($wpc_want461 > 0 && $wpc_have461 === $wpc_want461)
                                    ? '  <-- one-variable swap IS possible (same bytes, origin host)'
                                    : '  <-- swap would serve DIFFERENT bytes; NOT a clean handshake test');
                            if ($wpc_miss461) {
                                $o[] = '       absent locally (edge generates on the fly): '
                                     . implode(' ', array_slice($wpc_miss461, 0, 6))
                                     . (count($wpc_miss461) > 6 ? ' …+' . (count($wpc_miss461) - 6) : '');
                            }
                            if ($wpc_nomap461) {
                                $o[] = '       not under the uploads dir, so no local path exists: '
                                     . implode(' ', array_slice($wpc_nomap461, 0, 4))
                                     . (count($wpc_nomap461) > 4 ? ' …+' . (count($wpc_nomap461) - 4) : '');
                            }
                            if ($wpc_have461 > 0) {
                                $wpc_fp461 = [];
                                foreach ($wpc_fmt461 as $wpc_fk461 => $wpc_fv461) { $wpc_fp461[] = $wpc_fv461 . ' ' . $wpc_fk461; }
                                $o[] = sprintf('       present as: %s | smallest-variant bytes total %dKB (avg %dKB/rung)',
                                    implode(', ', $wpc_fp461), (int) round($wpc_lb461 / 1024),
                                    (int) round($wpc_lb461 / max(1, $wpc_have461) / 1024));
                            }
                            if ($wpc_res461) {
                                $o[] = '       per-rung (w=format/bytes): ' . implode(' ', $wpc_res461);
                            }
                            // What is ACTUALLY baked next to the hero, so a ladder/disk mismatch is
                            // visible rather than inferred from misses alone.
                            if (!empty($wpc_r450[0]['url'])) {
                                $wpc_p0461 = (string) parse_url((string) $wpc_r450[0]['url'], PHP_URL_PATH);
                                if ($wpc_ubu461 !== '' && $wpc_ubd461 !== '' && strpos($wpc_p0461, $wpc_ubu461 . '/') === 0) {
                                    $wpc_d0461 = dirname($wpc_ubd461 . '/' . ltrim(substr($wpc_p0461, strlen($wpc_ubu461)), '/'));
                                    $wpc_b0461 = (string) preg_replace('/-\d+x\d+$/', '',
                                        (string) preg_replace('/\.[a-z0-9]+$/i', '', basename($wpc_p0461)));
                                    if ($wpc_b0461 !== '') {
                                        $wpc_g461 = (array) @glob($wpc_d0461 . '/' . $wpc_b0461 . '-*');
                                        $wpc_wid461 = [];
                                        foreach ($wpc_g461 as $wpc_gf461) {
                                            if (preg_match('/-(\d+)x\d+\.([a-z0-9]+)$/i', $wpc_gf461, $wpc_gm461)) {
                                                $wpc_wid461[$wpc_gm461[1] . strtolower($wpc_gm461[2])] =
                                                    $wpc_gm461[1] . strtolower($wpc_gm461[2][0]);
                                            }
                                        }
                                        $wpc_wl461 = array_values($wpc_wid461);
                                        sort($wpc_wl461, SORT_NATURAL);
                                        $o[] = '       baked on disk beside it (' . count($wpc_g461) . ' files): '
                                             . (empty($wpc_wl461) ? '(none)' : implode(' ', array_slice($wpc_wl461, 0, 28)))
                                             . (count($wpc_wl461) > 28 ? ' …' : '')
                                             . '   [w+format initial]';
                                    }
                                }
                            }
                            $o[] = '       map: ' . ($wpc_ubu461 !== '' ? $wpc_ubu461 : '(no baseurl)')
                                 . '  ->  ' . ($wpc_ubd461 !== '' ? $wpc_ubd461 : '(no basedir)')
                                 . ' | zone host=' . (string) parse_url((string) ($wpc_r450[0]['url'] ?? ''), PHP_URL_HOST);
                        }
                    }
                    if (empty($_GET['originprobe'])) {
                        $o[] = "  ORIGIN-SERVE: add &originprobe=1 to test whether the origin holds the same rung bytes";
                    }
                } else {
                    $o[] = "  lcp_preload: ABSENT from lcp.json (pre-3.104 gen, or no hero captured)";
                }


                foreach (['desktop', 'mobile'] as $wpc_bgt_dev) {
                    $wpc_bgt_e = (is_array($le) && isset($le[$wpc_bgt_dev])) ? $le[$wpc_bgt_dev] : null;
                    if (!is_array($wpc_bgt_e)) { $o[] = "  bg-trace[$wpc_bgt_dev]: no lcp_element"; continue; }
                    $wpc_bgt_url = (!empty($wpc_bgt_e['net_url']) && is_string($wpc_bgt_e['net_url'])) ? $wpc_bgt_e['net_url']
                        : ((!empty($wpc_bgt_e['url']) && is_string($wpc_bgt_e['url'])) ? $wpc_bgt_e['url'] : '');
                    $wpc_bgt_type_ok = isset($wpc_bgt_e['type']) && $wpc_bgt_e['type'] === 'bg';
                    $wpc_bgt_allowed = ($wpc_bgt_url !== '' && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_lcp_bg_url_allowed'))
                        ? (wps_rewriteLogic::wpc_lcp_bg_url_allowed($wpc_bgt_url) ? 'Y' : 'N') : '?';
                    $wpc_bgt_filter = apply_filters('wpc_lcp_bg_responder', true) ? 'Y' : 'N';
                    $wpc_bgt_orig = $wpc_bgt_url !== '' ? basename((string) parse_url($wpc_bgt_url, PHP_URL_PATH)) : '';
                    $wpc_bgt_blob = @is_readable($cd . 'critical_' . $wpc_bgt_dev . '.css') ? (string) @file_get_contents($cd . 'critical_' . $wpc_bgt_dev . '.css') : '';
                    $wpc_bgt_strpos = ($wpc_bgt_orig !== '' && $wpc_bgt_blob !== '') ? (strpos($wpc_bgt_blob, $wpc_bgt_orig) !== false ? 'Y' : 'N') : '?';
                    $wpc_bgt_regex = '?'; $wpc_bgt_captured = '';
                    if ($wpc_bgt_orig !== '' && $wpc_bgt_blob !== ''
                        && preg_match('#url\(\s*["\']?([^"\')\s]*/' . preg_quote($wpc_bgt_orig, '#') . ')["\']?\s*\)#i', $wpc_bgt_blob, $wpc_bgt_rm)) {
                        $wpc_bgt_regex = 'Y'; $wpc_bgt_captured = substr((string) $wpc_bgt_rm[1], -60);
                    } elseif ($wpc_bgt_orig !== '' && $wpc_bgt_blob !== '') {
                        $wpc_bgt_regex = 'N';
                    }
                    $o[] = "  bg-trace[$wpc_bgt_dev]: url_ok=" . ($wpc_bgt_url !== '' ? 'Y' : 'N') . " type_ok=" . ($wpc_bgt_type_ok ? 'Y' : 'N')
                        . " host_allowed=$wpc_bgt_allowed filter=$wpc_bgt_filter orig_file=" . ($wpc_bgt_orig !== '' ? $wpc_bgt_orig : '-')
                        . " blob_bytes=" . strlen($wpc_bgt_blob) . " filename_in_blob=$wpc_bgt_strpos url()_regex_match=$wpc_bgt_regex"
                        . ($wpc_bgt_captured !== '' ? " captured=…$wpc_bgt_captured" : '');
                }
            }


            $wpc_dag = null; $wpc_dag_src = '';
            foreach (['delay.json', 'lcp.json'] as $wpc_dagfn) {
                if (!@is_readable($cd . $wpc_dagfn)) { continue; }
                $wpc_dagj = json_decode((string) @file_get_contents($cd . $wpc_dagfn), true);
                if (!is_array($wpc_dagj)) { continue; }
                if (isset($wpc_dagj['atf_glyphs']) && is_array($wpc_dagj['atf_glyphs']) && $wpc_dagj['atf_glyphs']) { $wpc_dag = $wpc_dagj['atf_glyphs']; $wpc_dag_src = $wpc_dagfn; break; }
                foreach ($wpc_dagj as $wpc_dagv) {
                    if (is_array($wpc_dagv) && isset($wpc_dagv['atf_glyphs']) && is_array($wpc_dagv['atf_glyphs']) && $wpc_dagv['atf_glyphs']) { $wpc_dag = $wpc_dagv['atf_glyphs']; $wpc_dag_src = $wpc_dagfn; break 2; }
                }
            }


            $wpc_dj_on = @is_readable($cd . 'delay.json');
            $o[] = "  atf_glyphs: " . ($wpc_dag !== null
                ? (count($wpc_dag) . " keys [src=$wpc_dag_src] " . substr(json_encode(array_keys($wpc_dag)), 0, 120))
                : ($wpc_dj_on
                    ? 'delay.json ON DISK but no atf_glyphs key (real gap)'
                    : 'delay.json NOT landed locally yet (rich-artifact pass lands ~76-150s post-crit; NOT a service gap)'));
            if (@is_readable($cd . 'font-subsets.css')) {
                $o[] = "  subset preview: " . substr(preg_replace('/base64,[^)]+/', 'base64,<..>', (string) @file_get_contents($cd . 'font-subsets.css')), 0, 260);
            }


            $wpc_dbg_utpl = @is_readable($cd . 'used_tpl.txt') ? trim((string) @file_get_contents($cd . 'used_tpl.txt')) : '';
            $wpc_dbg_tpl  = @is_readable($cd . 'tpl.txt') ? trim((string) @file_get_contents($cd . 'tpl.txt')) : '';
            $wpc_dbg_uk   = $wpc_dbg_utpl !== '' ? $wpc_dbg_utpl : $wpc_dbg_tpl;
            $wpc_dbg_up   = ($wpc_dbg_uk !== '' && function_exists('wpc_used_css_path')) ? wpc_used_css_path($wpc_dbg_uk) : '';
            $o[] = "  used.css stored: " . (($wpc_dbg_up !== '' && @is_readable($wpc_dbg_up)) ? (@filesize($wpc_dbg_up) . 'B') : 'NO')
                 . " | used_tpl=" . ($wpc_dbg_utpl !== '' ? substr($wpc_dbg_utpl, 0, 24) : '-')
                 . " | tpl.txt=" . ($wpc_dbg_tpl !== '' ? substr($wpc_dbg_tpl, 0, 24) : '-')
                 . " | match=" . (($wpc_dbg_utpl !== '' && $wpc_dbg_utpl === $wpc_dbg_tpl) ? 'Y' : 'N');
            // .464 derived-sibling freshness. STALE here is the whole reason a pruned service
            // bundle can be correct while the wire still serves 561 orphaned keyframes.
            if ($wpc_dbg_up !== '' && function_exists('wpc_used_css_basis')) {
                $wpc_dbg_bas = wpc_used_css_basis($wpc_dbg_up);
                foreach (['', '.desktop', '.mobile'] as $wpc_dbg_dv) {
                    $wpc_dbg_u2 = $wpc_dbg_dv === '' ? $wpc_dbg_up
                        : (function_exists('wpc_used_css_path') ? wpc_used_css_path($wpc_dbg_uk, ltrim($wpc_dbg_dv, '.')) : '');
                    if ($wpc_dbg_u2 === '' || !@is_readable($wpc_dbg_u2)) { continue; }
                    $wpc_dbg_b2 = wpc_used_css_basis($wpc_dbg_u2);
                    $wpc_dbg_a2 = (string) preg_replace('/\.css$/', '.atf.css', $wpc_dbg_u2);
                    $wpc_dbg_hd = @is_readable($wpc_dbg_a2)
                        ? (string) @file_get_contents($wpc_dbg_a2, false, null, 0, 48) : '';
                    $wpc_dbg_st = '';
                    if ($wpc_dbg_hd !== '' && preg_match('#/\*wpc-ucss:([0-9a-f]{6,32})\*/#', $wpc_dbg_hd, $wpc_dbg_m)) {
                        $wpc_dbg_st = $wpc_dbg_m[1];
                    }
                    $o[] = sprintf('  used-css sibling%s: union basis=%s | atf=%s stamp=%s => %s',
                        $wpc_dbg_dv === '' ? '(union)' : $wpc_dbg_dv,
                        $wpc_dbg_b2 !== '' ? $wpc_dbg_b2 : '-',
                        @is_readable($wpc_dbg_a2) ? (@filesize($wpc_dbg_a2) . 'B') : 'ABSENT',
                        $wpc_dbg_st !== '' ? $wpc_dbg_st : '(none - pre-.464 build)',
                        function_exists('wpc_used_css_stamp_ok') && wpc_used_css_stamp_ok($wpc_dbg_a2, $wpc_dbg_b2)
                            ? 'FRESH' : 'STALE -> will rebuild on next warm');
                }
            }
            $wpc_dbg_uu = @is_readable($cd . 'used_css_url.txt') ? trim((string) @file_get_contents($cd . 'used_css_url.txt')) : '';
            $wpc_dbg_ur = ($wpc_dbg_uk !== '' && function_exists('wpc_used_css_url_recall')) ? wpc_used_css_url_recall($wpc_dbg_uk) : '';
// v7.10.551 — make the WITHHELD per-device droplist visible. crit-push v3.121.1 now
// probes before advertising and fail-closes on a partial pair (page cache is
// variant-split, so a mobile-only droplist is a variant disagreeing with its sibling,
// not half the benefit). Without this line we take the degraded path SILENTLY - which
// is exactly what let a permanent 404 loop hide: clean degradation concealed the fault.
$wpc_cd551 = rtrim((string) (isset($wpc_dbg_dir) ? $wpc_dbg_dir : ''), '/');
if ($wpc_cd551 !== '') {
    $wpc_d551 = @is_file($wpc_cd551 . '/used_css_desktop_url.txt');
    $wpc_m551 = @is_file($wpc_cd551 . '/used_css_mobile_url.txt');
    if ($wpc_d551 !== $wpc_m551) {
        $o[] = '  used-css PER-DEVICE: PARTIAL PAIR (desktop=' . ($wpc_d551 ? 'Y' : 'N')
            . ' mobile=' . ($wpc_m551 ? 'Y' : 'N') . ') — one device only; droplist withheld by design';
    } elseif (!$wpc_d551 && !$wpc_m551) {
        $o[] = '  used-css PER-DEVICE: none advertised (union-only gen) — droplist runs from the union';
    }
}
// v7.10.551 — AUTOLOAD CENSUS. 483KB of wps_ic_* options (31% of this site's total) are
// fetched AND unserialised on every request whether read or not; boot:924ms observed.
// Flipping autoload blind is the wrong move - an option genuinely read each request is
// cheaper autoloaded than as a query. So MEASURE first: list the biggest by size and let
// the decision be per-option. Add &autoload=1 to run it; costs one query, never on a render.
if (!empty($_GET['autoload'])) {
    global $wpdb;
    $wpc_rows551 = $wpdb->get_results("SELECT option_name, LENGTH(option_value) AS b, autoload
        FROM {$wpdb->options} WHERE autoload IN ('yes','on') ORDER BY b DESC LIMIT 25");
    $wpc_tot551 = (int) $wpdb->get_var("SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options}
        WHERE autoload IN ('yes','on')");
    $o[] = '--- AUTOLOAD CENSUS (total ' . round($wpc_tot551 / 1024) . 'KB on every request) ---';
    foreach ((array) $wpc_rows551 as $wpc_r551) {
        $wpc_mine551 = (strpos($wpc_r551->option_name, 'wps_ic') === 0
            || strpos($wpc_r551->option_name, 'wpc') === 0) ? ' [OURS]' : '';
        $o[] = sprintf('  %7sKB  %-52s%s', round($wpc_r551->b / 1024, 1),
            substr($wpc_r551->option_name, 0, 52), $wpc_mine551);
    }
    $o[] = '  NOTE: [OURS] rows are candidates. Flip only ones NOT read on a render;';
    $o[] = '        an option read every request is cheaper autoloaded than as a query.';
}
            $o[] = "  used_css_url: crit-dir=" . ($wpc_dbg_uu !== '' ? substr($wpc_dbg_uu, -52) : 'ABSENT')
                 . " | remembered=" . ($wpc_dbg_ur !== '' ? substr($wpc_dbg_ur, -52) : 'ABSENT');
            if ($wpc_dbg_uu === '' && $wpc_dbg_ur === '') {
                $o[] = "     !! NO pointer either way — the refresh path cannot run, so STALE siblings can never rebuild."
                     . " .469 now re-asserts the demand when a store is orphaned; watch for ucss-demand-reasserted.";
            }
            // Service echo (v3.109.0): separates "asked and got nothing" from "never asked".
            $wpc_dbg_ec = get_option('wpc_used_css_echo', []);
            if (is_array($wpc_dbg_ec) && $wpc_dbg_ec) {
                $wpc_tb = function ($v) { return $v === 1 ? 'YES' : ($v === 0 ? 'no' : 'unknown'); };
                $wpc_rq = (int) ($wpc_dbg_ec['req'] ?? -1);
                $wpc_kn = (int) ($wpc_dbg_ec['kn'] ?? -1);
                $o[] = "  used-css echo: requested=" . $wpc_tb($wpc_rq)
                     . " | service_can_speak_for_it=" . ($wpc_kn === 1 ? 'YES' : 'no')
                     . " | pointer_in_payload=" . (empty($wpc_dbg_ec['ptr']) ? 'no' : 'YES')
                     . " | via=" . (string) ($wpc_dbg_ec['src'] ?? '?')
                     . " | " . human_time_diff((int) ($wpc_dbg_ec['t'] ?? time())) . " ago";
                if ($wpc_kn === 0) {
                    // used_css_known:false is the service's "we cannot speak for this row" MARKER;
                    // it is NOT a decline, and reading it as one is how a pre-v3.109 row lies.
                    $o[] = "     (used_css_known:false marker — a pre-v3.109 row the service cannot speak for; NOT a decline)";
                } elseif ($wpc_rq === 1 && empty($wpc_dbg_ec['ptr'])) {
                    $o[] = "     !! WE ASKED and no pointer came back — that is a SERVICE-side gap, not the plugin's.";
                } elseif ($wpc_rq === 0) {
                    $o[] = "     (KNOWN decline this gen — expected while a store is present AND its pointer is verified)";
                }
                // /status advertises used_css_url unconditionally under wantsObservation (service
                // v3.33.13 fail-open), so a pointer alongside a KNOWN decline is a 404 forever.
                if ($wpc_rq === 0 && $wpc_kn === 1 && !empty($wpc_dbg_ec['ptr'])) {
                    $o[] = "     note: pointer advertised alongside a known decline — expected to 404; .471 will not trust it unverified";
                }
                if ($wpc_dbg_uk !== '' && function_exists('wpc_used_css_url_verified')) {
                    $o[] = "  pointer trust: " . (wpc_used_css_url_verified($wpc_dbg_uk)
                        ? 'VERIFIED (a fetch through it succeeded — may suppress the demand)'
                        : 'unverified (never fetched through — will NOT suppress the demand)');
                }
            } else {
                $o[] = "  used-css echo: none recorded yet (pre-v3.109 service, or no gen landed since upgrade)";
            }

            // GLYPH CENSUS GAP — why a remote icon face is fetched at all. The inline subset only
            // eliminates the remote font if it covers every codepoint the page's CSS asks for; on
            // busy the subset held 15 while the CSS requested 55, so 51 glyphs forced a 91 KiB
            // fetch that "the widening is shipped" would otherwise imply was gone. This computes
            // it per site from artifacts already on disk — no network, bounded reads.
            if (!empty($_GET['glyphs'])) {
                $wpc_cov451 = [];
                $wpc_sub451 = @is_readable($cd . 'font-subsets.css') ? (string) @file_get_contents($cd . 'font-subsets.css') : '';
                foreach (preg_split('/@font-face/i', $wpc_sub451) as $wpc_fb451) {
                    if (stripos($wpc_fb451, 'data:') === false) { continue; }
                    if (!preg_match('/unicode-range:\s*([^;}]+)/i', $wpc_fb451, $wpc_urm451)) { continue; }
                    foreach (explode(',', $wpc_urm451[1]) as $wpc_tok451) {
                        $wpc_tok451 = strtoupper(trim(str_replace('U+', '', $wpc_tok451)));
                        if ($wpc_tok451 === '') { continue; }
                        if (strpos($wpc_tok451, '-') !== false) {
                            list($wpc_a451, $wpc_b451) = array_pad(explode('-', $wpc_tok451, 2), 2, '');
                            $wpc_a451 = hexdec($wpc_a451); $wpc_b451 = hexdec($wpc_b451);
                            if ($wpc_b451 >= $wpc_a451 && ($wpc_b451 - $wpc_a451) < 200000) {
                                for ($wpc_c451 = $wpc_a451; $wpc_c451 <= $wpc_b451; $wpc_c451++) { $wpc_cov451[$wpc_c451] = 1; }
                            }
                        } else {
                            $wpc_cov451[hexdec($wpc_tok451)] = 1;
                        }
                    }
                }
                $wpc_scan451 = '';
                foreach (['critical_mobile.css', 'critical_desktop.css'] as $wpc_cf451) {
                    if (@is_readable($cd . $wpc_cf451)) { $wpc_scan451 .= (string) @file_get_contents($cd . $wpc_cf451); }
                }
                if ($wpc_dbg_up !== '' && @is_readable($wpc_dbg_up) && @filesize($wpc_dbg_up) < 4194304) {
                    $wpc_scan451 .= (string) @file_get_contents($wpc_dbg_up);
                }
                $wpc_used451 = [];
                if (preg_match_all('/content\s*:\s*(["\'])((?:\\\\?[0-9a-fA-F]{2,6}\s?)+)\1/', $wpc_scan451, $wpc_cm451)) {
                    foreach ($wpc_cm451[2] as $wpc_seq451) {
                        if (preg_match_all('/\\\\?([0-9a-fA-F]{2,6})/', $wpc_seq451, $wpc_em451)) {
                            foreach ($wpc_em451[1] as $wpc_e451) {
                                $wpc_cp451 = hexdec($wpc_e451);
                                if ($wpc_cp451 >= 0xE000 && $wpc_cp451 <= 0xF8FF) {
                                    $wpc_used451[$wpc_cp451] = isset($wpc_used451[$wpc_cp451]) ? $wpc_used451[$wpc_cp451] + 1 : 1;
                                }
                            }
                        }
                    }
                }
                $wpc_miss451 = array_diff(array_keys($wpc_used451), array_keys($wpc_cov451));
                sort($wpc_miss451);
                $o[] = "  GLYPH CENSUS: subset covers " . count($wpc_cov451) . " | CSS requests " . count($wpc_used451)
                     . " | MISSING " . count($wpc_miss451)
                     . (count($wpc_miss451) > 0 ? "   <-- these force the remote icon font to load" : "   (subset complete: remote face never needed)");
                if ($wpc_miss451) {
                    $wpc_out451 = [];
                    foreach (array_slice($wpc_miss451, 0, 40) as $wpc_mc451) {
                        $wpc_out451[] = sprintf('U+%04X', $wpc_mc451);
                    }
                    $o[] = "    missing: " . implode(' ', $wpc_out451) . (count($wpc_miss451) > 40 ? ' …+' . (count($wpc_miss451) - 40) : '');
                    $wpc_pua451 = 0; $wpc_fa451 = 0;
                    foreach ($wpc_miss451 as $wpc_mc451) {
                        if ($wpc_mc451 >= 0xE000 && $wpc_mc451 <= 0xE0FF) { $wpc_pua451++; } elseif ($wpc_mc451 >= 0xF000) { $wpc_fa451++; }
                    }
                    $o[] = "    split: " . $wpc_pua451 . " in U+E0xx (theme icon font) | " . $wpc_fa451 . " in U+F0xx+ (FontAwesome range)";
                }
            } else {
                $o[] = "  GLYPH CENSUS: add &glyphs=1 to compute subset coverage vs CSS demand";
            }
            // Beaconed FCP->LCP traces (?wpc_lcp_trace=1&send=1 during a PSI run).
            $wpc_trr452 = get_option('wpc_lcp_trace_reports', []);
            if (is_array($wpc_trr452) && $wpc_trr452) {
                // A full-page capture reports viewport=16383px, where an image 10,784px down is
                // "in viewport" and wins LCP at 35x the real figure. Those rows are correct
                // measurements of a condition no visitor and no PSI run is ever in, so they must
                // never enter an aggregate. Derived from stored vh so existing rows classify too.
                $wpc_vhmax461 = max(1200, (int) apply_filters('wpc_lcp_trace_real_vh_max', 2400));
                $wpc_syn461 = function ($wpc_r461) use ($wpc_vhmax461) {
                    $wpc_v461 = isset($wpc_r461['vh']) ? (int) $wpc_r461['vh'] : -1;
                    return ($wpc_v461 > $wpc_vhmax461);
                };
                $wpc_real461 = [];
                $wpc_nsyn461 = 0;
                foreach ($wpc_trr452 as $wpc_r461) {
                    if (!is_array($wpc_r461)) { continue; }
                    if ($wpc_syn461($wpc_r461)) { $wpc_nsyn461++; } else { $wpc_real461[] = $wpc_r461; }
                }
                $o[] = "  --- BEACONED FCP->LCP TRACES (" . count($wpc_trr452) . " total: "
                     . count($wpc_real461) . " real viewport, " . $wpc_nsyn461 . " synthetic"
                     . ($wpc_nsyn461 > 0 ? " EXCLUDED from the medians below (vh>" . $wpc_vhmax461 . "px = page capture, not a viewport)" : "")
                     . ", newest first) ---";
                if ($wpc_real461) {
                    $wpc_med461 = function ($wpc_k461) use ($wpc_real461) {
                        $wpc_vs461 = [];
                        foreach ($wpc_real461 as $wpc_x461) { $wpc_vs461[] = (int) ($wpc_x461[$wpc_k461] ?? 0); }
                        sort($wpc_vs461);
                        $wpc_c461 = count($wpc_vs461);
                        return ($wpc_c461 % 2)
                            ? $wpc_vs461[intdiv($wpc_c461, 2)]
                            : (int) round(($wpc_vs461[$wpc_c461 / 2 - 1] + $wpc_vs461[$wpc_c461 / 2]) / 2);
                    };
                    // Median, not mean: one synthetic row that slips the filter still cannot
                    // move a median the way it moves an average.
                    $o[] = sprintf('   MEDIAN over %d real-viewport trace%s: FCP=%dms LCP=%dms GAP=%dms',
                        count($wpc_real461), count($wpc_real461) === 1 ? '' : 's',
                        $wpc_med461('fcp'), $wpc_med461('lcp'), $wpc_med461('gap'));
                } else {
                    $o[] = "   MEDIAN: no real-viewport traces yet — every row is a page capture, draw no conclusions";
                }
                foreach (array_reverse($wpc_trr452) as $wpc_tr452) {
                    if (!is_array($wpc_tr452)) { continue; }
                    if ($wpc_syn461($wpc_tr452)) {
                        $o[] = '   [SYNTHETIC VIEWPORT — page capture, excluded from medians; not busy\'s LCP]';
                    }
                    $o[] = sprintf('   %s ago  FCP=%dms LCP=%dms GAP=%dms  verdict=%s  longtasks own %dms (%d%%)',
                        human_time_diff((int) ($wpc_tr452['t'] ?? time())),
                        (int) ($wpc_tr452['fcp'] ?? 0), (int) ($wpc_tr452['lcp'] ?? 0), (int) ($wpc_tr452['gap'] ?? 0),
                        (string) ($wpc_tr452['v'] ?? '?'), (int) ($wpc_tr452['own'] ?? 0), (int) ($wpc_tr452['pct'] ?? 0))
                        . '  via=' . (($wpc_tr452['ch'] ?? '?') === 'b' ? 'sendBeacon' : ((($wpc_tr452['ch'] ?? '?') === 'i') ? 'imageGET' : '?'));
                    $o[] = sprintf('       el=%s  url=...%s', (string) ($wpc_tr452['el'] ?? '-'), (string) ($wpc_tr452['url'] ?? '-'));
                    // humanSignal is the difference between a PSI run and a browser visit: PSI never
                    // interacts, so hum=0 is the comparable path and hum=1 means the replay had already
                    // run. A gap-window long-task count is vacuous when gap=0 — ltms is the real figure.
                    // A below-fold element winning LCP is the difference between a 2s and a 17s
                    // reading, so say whether it was in the viewport when the browser chose it.
                    $wpc_iv452 = isset($wpc_tr452['inv']) ? (int) $wpc_tr452['inv'] : -1;
                    if ($wpc_iv452 !== -1) {
                        $o[] = sprintf('       element box: top=%dpx viewport=%dpx  %s',
                            (int) ($wpc_tr452['top'] ?? 0), (int) ($wpc_tr452['vh'] ?? 0),
                            $wpc_syn461($wpc_tr452)
                                ? 'in view ONLY because the viewport is a page-capture height'
                                : ($wpc_iv452 === 1 ? 'IN viewport (normal)'
                                    : 'OUTSIDE the viewport when chosen   <-- below-fold element won LCP'));
                    }
                    $wpc_hm452 = isset($wpc_tr452['hum']) ? (int) $wpc_tr452['hum'] : -1;
                    $o[] = sprintf('       humanSignal=%s  |  whole load to LCP: %d long tasks, %dms%s',
                        $wpc_hm452 === 1 ? 'YES (replay ran — NOT the PSI path)' : ($wpc_hm452 === 0 ? 'no (comparable to PSI)' : 'unknown'),
                        (int) ($wpc_tr452['ltn'] ?? 0), (int) ($wpc_tr452['ltms'] ?? 0),
                        (int) ($wpc_tr452['gap'] ?? 0) <= 0 ? '   (gap=0: the gap-window count above is vacuous)' : '');
                    if (!empty($wpc_tr452['r']) && is_array($wpc_tr452['r'])) {
                        $wpc_rp452 = [];
                        foreach ($wpc_tr452['r'] as $wpc_rk452 => $wpc_rv452) { $wpc_rp452[] = $wpc_rk452 . '=' . (int) $wpc_rv452; }
                        $o[] = '       lcp resource: ' . implode(' ', $wpc_rp452);
                    }
                    foreach ((array) ($wpc_tr452['net'] ?? []) as $wpc_n452) {
                        if (is_array($wpc_n452) && isset($wpc_n452[0])) {
                            $o[] = sprintf('       in-window: %-24s %d->%dms %dKB', substr((string) $wpc_n452[0], 0, 24),
                                (int) ($wpc_n452[1] ?? 0), (int) ($wpc_n452[2] ?? 0), (int) ($wpc_n452[3] ?? 0));
                        }
                    }
                    foreach ((array) ($wpc_tr452['lt'] ?? []) as $wpc_l452) {
                        if (is_array($wpc_l452)) { $o[] = sprintf('       longtask: at %dms for %dms', (int) ($wpc_l452[0] ?? 0), (int) ($wpc_l452[1] ?? 0)); }
                    }
                }
            } else {
                $o[] = "  BEACONED TRACES: none — run PSI against ?wpc_lcp_trace=1&send=1 to capture one";
            }
        }
        $map = get_option('wps_ic_fonts_inline_map');
        $o[] = "inline_font_map entries: " . (is_array($map) ? count($map) : 0) . " | inline_lock: " . (get_transient('wpc_font_inline_lock') ? 'LOCKED' : 'clear');
        // THREE lanes emit the hero preload under three different ids — the autoderive bg lane
        // (wpc-lcp-bg-preload), its device-pinned twin (wpc-lcp-hero-preload) and the lcp.json
        // lane (wpc-lcp-img-preload). Reporting only the first printed N for documents that
        // plainly carried one of the others.
        $wpc_pre815 = function ($doc) {
            $hit = [];
            foreach (['bg' => 'wpc-lcp-bg-preload', 'hero' => 'wpc-lcp-hero-preload', 'img' => 'wpc-lcp-img-preload'] as $wpc_k815 => $wpc_id815) {
                if (is_string($doc) && strpos($doc, $wpc_id815) !== false) { $hit[] = $wpc_k815; }
            }
            return empty($hit) ? 'NONE' : implode('+', $hit);
        };
        $r = wp_remote_get($url, ['timeout' => 15, 'headers' => ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile']]);
        $h = is_wp_error($r) ? '' : wp_remote_retrieve_body($r);
        $o[] = "SERVED page: " . strlen($h) . "B | crit=" . (preg_match('/<style[^>]*id=["\']wpc-critical-css["\']/i', $h) ? 'Y' : 'N')
             . " | gstatic=" . substr_count($h, 'fonts.gstatic.com') . " | subset_base64=" . substr_count($h, 'data:font/woff2;base64')
             . " | lcp-preload=" . $wpc_pre815($h) . " | used-css=" . (strpos($h, 'wpc-used-css') !== false ? 'Y' : 'N')
             . " | size-adjust=" . (strpos($h, 'size-adjust') !== false ? 'Y' : 'N');


        // The headers below are ours; an edge in front of the origin does not honour them and
        // returns the same cached body, which is why this arm printed bytes IDENTICAL to SERVED on
        // every report and reported absent markers for a document that plainly carried them. A
        // unique query string is the only bypass that survives a foreign edge. .815 used a NOVEL
        // param — which fragmented the url_key, so the render lost its own delay.json/crit
        // artifacts and reported aggr:0 for a site whose visitors get aggr:1: the cachebust had
        // changed the code path it was measuring. disable_cache is the one param with exactly the
        // needed semantics ALREADY: trackingParams() strips it from the key (real artifacts, real
        // code path) while controlParams() refuses cache read/write for it (a genuinely fresh
        // render, never stored). The timestamp value defeats the foreign edge's query-keyed cache.
        $wpc_fu815 = $url . ((strpos($url, '?') !== false) ? '&' : '?') . 'disable_cache=' . time();
        $rf = wp_remote_get($wpc_fu815, ['timeout' => 25, 'headers' => ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile', 'X-WPC-Cache-Warm' => '1', 'X-WPC-Diag' => '1']]);
        $hf = is_wp_error($rf) ? '' : wp_remote_retrieve_body($rf);
        // An empty arm must say WHY: a transport error, a non-200, and an empty 200 are three
        // different diagnoses (blocked loopback / edge challenge / render died), and a bare 0B
        // collapses them into a guess.
        $wpc_fw816 = '';
        if (is_wp_error($rf)) {
            $wpc_fw816 = ' | FETCH FAILED: ' . substr((string) $rf->get_error_message(), 0, 90);
        } elseif ($hf === '') {
            $wpc_fw816 = ' | HTTP ' . (int) wp_remote_retrieve_response_code($rf) . ' with EMPTY body';
        } elseif ((int) wp_remote_retrieve_response_code($rf) !== 200) {
            $wpc_fw816 = ' | HTTP ' . (int) wp_remote_retrieve_response_code($rf) . ' — markers below are from an ERROR page';
        }
        // On any failure, the response HEADERS say who answered — an edge challenge carries
        // cf-cache-status/server, a shed origin answers bare, a WAF names itself. Without them
        // the empty arm bounded the failure to "origin busy" but could not name the exit.
        if ($wpc_fw816 !== '' && !is_wp_error($rf)) {
            $wpc_fh818 = [];
            foreach (['server', 'cf-cache-status', 'content-type', 'content-length', 'x-powered-by', 'retry-after'] as $wpc_hk818) {
                $wpc_hv818 = wp_remote_retrieve_header($rf, $wpc_hk818);
                if (is_array($wpc_hv818)) { $wpc_hv818 = implode(',', $wpc_hv818); }
                if ((string) $wpc_hv818 !== '') { $wpc_fh818[] = $wpc_hk818 . '=' . substr((string) $wpc_hv818, 0, 40); }
            }
            if (!empty($wpc_fh818)) { $wpc_fw816 .= ' [' . implode(' ', $wpc_fh818) . ']'; }
        }
        $o[] = "FRESH render (&disable_cache=<ts>: clean url_key + no cache serve/store, edge MISS): "
             . strlen($hf) . "B" . $wpc_fw816
             . (($hf !== '' && $hf === $h) ? ' | !! IDENTICAL BYTES to SERVED — this arm did NOT bypass; treat both lines as one observation' : '')
             . " | crit=" . (preg_match('/<style[^>]*id=["\']wpc-critical-css["\']/i', $hf) ? 'Y' : 'N')
             . " | lcp-preload=" . $wpc_pre815($hf)
             . " | subset_base64=" . substr_count($hf, 'data:font/woff2;base64')
             . " | gstatic=" . substr_count($hf, 'fonts.gstatic.com')
             . " | @font-face=" . substr_count($hf, '@font-face');
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_lcp_autoderive_bg')) {
            $blob = ($cd && @is_readable($cd . 'critical_mobile.css')) ? (string) @file_get_contents($cd . 'critical_mobile.css') : '';
            $ad = wps_rewriteLogic::wpc_lcp_autoderive_bg($h, $blob);
            $o[] = "autoderive(mobile): " . (is_array($ad) ? 'HERO url=' . substr((string) $ad['url'], -54) . ' sel=' . $ad['sel'] : 'NULL (no classic-bg hero found in served HTML)');
        }


        if (is_dir($cd)) {
            $o[] = "crit_dir files: " . implode(', ', array_map('basename', (array) @glob($cd . '*')));
            // READINESS: one line answering "is this site converged and safe to PSI?"
            $wpc_rd = [];
            $wpc_rd['crit']  = @is_readable($cd . 'critical_desktop.css') && @is_readable($cd . 'critical_mobile.css');
            $wpc_rd['lcp']   = @is_readable($cd . 'lcp.json');
            $wpc_rd['delay'] = @is_readable($cd . 'delay.json');
            $wpc_rd['fonts'] = @is_readable($cd . 'font-subsets.css') || (strpos((string) @file_get_contents($cd . 'delay.json'), '-apple-system') !== false ? null : false);
            $wpc_ucs = is_array($set) ? (string) ($set['used-css'] ?? '0') : '0';
            $wpc_rd['used-css'] = $wpc_ucs === '1' ? true : null; // null = probation pending, not a fault
            $wpc_dressed = null;
            if (defined('WPS_IC_CACHE') && class_exists('wps_ic_url_key')) {
                $wpc_cf157 = rtrim(WPS_IC_CACHE, '/') . '/' . (new wps_ic_url_key())->setup(home_url('/')) . '/index.html_gzip';
                if (@is_readable($wpc_cf157) && function_exists('gzdecode')) {
                    $wpc_ch157 = (string) @gzdecode((string) @file_get_contents($wpc_cf157));
                    $wpc_ver157 = defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : '';
                    $wpc_dressed = strpos($wpc_ch157, 'wpc-critical-css') !== false
                        && !($wpc_ver157 !== '' && strpos($wpc_ch157, 'delay-v3-loader-') !== false
                            && strpos($wpc_ch157, 'delay-v3-loader-' . $wpc_ver157) === false);
                }
            }
            $wpc_rd['cache-dressed'] = $wpc_dressed;
            $wpc_rd['cf-crown'] = (bool) get_option('wpc_cf_purge_verified');
            // v7.10.682 — readiness measured FILE PRESENCE while stale.txt / the zero-dark bypass
            // window withheld crit from every render (the rebuild-press outage read 7/7 READY with
            // crit=N on the served page). Serving state is part of readiness, not a footnote.
            $wpc_rd['fresh'] = !@is_file($cd . 'stale.txt')
                && !(function_exists('wpc_crit_bypass_active') && wpc_crit_bypass_active(basename(rtrim($cd, '/'))));
            $wpc_parts = [];
            $wpc_ok = 0; $wpc_tot = 0;
            foreach ($wpc_rd as $wpc_k => $wpc_v) {
                if ($wpc_v === null) { $wpc_parts[] = $wpc_k . '=pending'; continue; }
                $wpc_tot++;
                if ($wpc_v) { $wpc_ok++; }
                $wpc_parts[] = $wpc_k . '=' . ($wpc_v ? 'Y' : 'N');
            }
            $o[] = "readiness: " . implode(' | ', $wpc_parts) . " => " . $wpc_ok . "/" . $wpc_tot
                . ($wpc_ok === $wpc_tot ? " READY (safe to PSI)" : " CONVERGING (PSI will grade a transition)");
            if (@is_readable($cd . 'gen_fails.json')) {
                $wpc_gf157 = (string) @file_get_contents($cd . 'gen_fails.json');
                $o[] = "gen_fails (tail): " . substr(trim($wpc_gf157), -600);
            }
            // A5: both brakes in one place — operators were guessing which gate blocked.
            $wpc_gbf157 = function_exists('wpc_gen_backoff_file') ? wpc_gen_backoff_file() : '';
            if ($wpc_gbf157 !== '' && @is_file($wpc_gbf157)) {
                $o[] = "global gen-backoff: " . trim((string) @file_get_contents($wpc_gbf157));
            }
            // ≤60s-contract receipts: dispatch→land seconds + publish-webhook liveness.
            $wpc_dts157 = (int) @file_get_contents($cd . 'dispatch_ts.txt');
            $wpc_lts157 = (int) @file_get_contents($cd . 'land_ts.txt');
            $wpc_whk157 = (int) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/whk_last.txt');
            // "took Ns" is only meaningful when both stamps belong to the SAME generation.
            // Subtracting a new gen's dispatch from a previous gen's land invents a latency that
            // never happened — it read "took 5739s" on busy while the real story was a finished
            // gen that was never consumed, and it sent the diagnosis the wrong way.
            $wpc_du157 = trim((string) @file_get_contents($cd . 'uuid.txt'));
            $wpc_lu157 = trim((string) @file_get_contents($cd . 'land_uuid.txt'));
            $wpc_same157 = ($wpc_du157 !== '' && $wpc_du157 === $wpc_lu157);
            $o[] = "land-latency: "
                . ($wpc_dts157 > 0 ? 'dispatched ' . max(0, time() - $wpc_dts157) . 's ago' : 'no dispatch stamp')
                . ' | ' . ($wpc_lts157 > 0
                    ? 'landed ' . max(0, time() - $wpc_lts157) . 's ago'
                        . ($wpc_same157 && $wpc_dts157 > 0 && $wpc_lts157 >= $wpc_dts157
                            ? ' (took ' . ($wpc_lts157 - $wpc_dts157) . 's, same gen)' : '')
                    : 'never landed')
                . ' | webhook: ' . ($wpc_whk157 > 0 ? 'seen ' . max(0, time() - $wpc_whk157) . 's ago' : 'never');
            if (!$wpc_same157) {
                if ($wpc_du157 !== '') {
                    $o[] = '  !! pending gen ' . substr($wpc_du157, 0, 8) . ' NOT consumed — landed uuid is '
                         . ($wpc_lu157 !== '' ? substr($wpc_lu157, 0, 8) : '(none)')
                         . '. Latency above spans TWO gens; ignore it.';
                } elseif ($wpc_dts157 > 0) {
                    $o[] = '  !! dispatch stamp present but NO pending uuid — a pending gen was CLEARED'
                         . ' (landed uuid still ' . ($wpc_lu157 !== '' ? substr($wpc_lu157, 0, 8) : '(none)') . ').'
                         . ' Grep cflog for uuid-cleared-not-found / uuid-no-dispatch-stamp for the reason.';
                }
            }
            $wpc_st157 = json_decode((string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks/selftest.json'), true);
            $o[] = "selftest: " . (is_array($wpc_st157)
                ? (string) ($wpc_st157['verdict'] ?? '?') . ' (' . (string) ($wpc_st157['mode'] ?? '?') . ', '
                    . max(0, time() - (int) ($wpc_st157['t'] ?? 0)) . 's ago'
                    . (isset($wpc_st157['latency_s']) && $wpc_st157['latency_s'] !== null ? ', land ' . (int) $wpc_st157['latency_s'] . 's' : '') . ')'
                : 'never run — POST admin-ajax?action=wpc_crit_selftest&run=1&drill=1');
            // CRIT-BLAME: when crit is missing, name the side. The pointer shelf is the
            // service's PUBLISH receipt — present = plugin pickup stalled; absent = service
            // generate pending (or never dispatched if no fail evidence). Debug-only probe.
            if (empty($wpc_rd['crit'])) {
                $wpc_bl_opts = function_exists('get_option') && defined('WPS_IC_OPTIONS') ? get_option(WPS_IC_OPTIONS) : [];
                $wpc_bl_key  = is_array($wpc_bl_opts) && !empty($wpc_bl_opts['api_key']) ? (string) $wpc_bl_opts['api_key'] : '';
                $wpc_bl_line = 'crit-blame: ';
                if ($wpc_bl_key === '') {
                    $wpc_bl_line .= 'NO API KEY — plugin side (not connected)';
                } else {
                    $wpc_bl_u = trim((string) @file_get_contents($cd . 'url.txt'));
                    if ($wpc_bl_u === '' && function_exists('home_url')) { $wpc_bl_u = home_url('/'); }
                    if ($wpc_bl_u !== '' && strpos($wpc_bl_u, '://') === false) { $wpc_bl_u = 'https://' . ltrim($wpc_bl_u, '/'); }
                    $wpc_bl_h = strtolower((string) parse_url($wpc_bl_u, PHP_URL_HOST));
                    if (strpos($wpc_bl_h, 'www.') === 0) { $wpc_bl_h = substr($wpc_bl_h, 4); }
                    $wpc_bl_p = (string) parse_url($wpc_bl_u, PHP_URL_PATH); if ($wpc_bl_p === '') { $wpc_bl_p = '/'; }
                    $wpc_bl_ptr = 'https://critical-css-mc.b-cdn.net/latest/' . substr(sha1($wpc_bl_key), 0, 16) . '/' . substr(sha1($wpc_bl_h . $wpc_bl_p), 0, 16) . '.json?t=' . time();
                    $wpc_bl_r = wp_remote_get($wpc_bl_ptr, ['timeout' => 4]);
                    $wpc_bl_code = is_wp_error($wpc_bl_r) ? 0 : (int) wp_remote_retrieve_response_code($wpc_bl_r);
                    if ($wpc_bl_code === 200) {
                        $wpc_bl_j = json_decode((string) wp_remote_retrieve_body($wpc_bl_r), true);
                        $wpc_bl_uuid = is_array($wpc_bl_j) && !empty($wpc_bl_j['uuid']) ? substr((string) $wpc_bl_j['uuid'], 0, 8) : '?';
                        $wpc_bl_line .= 'SERVICE PUBLISHED (pointer 200, uuid=' . $wpc_bl_uuid . ') → PLUGIN pickup stalled — check pickup backoff/loopback';
                    } elseif ($wpc_bl_code > 0) {
                        $wpc_bl_gf = @is_readable($cd . 'gen_fails.json') ? 'dispatch-fail evidence present (gen_fails tail above)' : 'no dispatch-fail evidence';
                        $wpc_bl_line .= 'NOT PUBLISHED (pointer ' . $wpc_bl_code . ') → SERVICE generate pending or never dispatched — ' . $wpc_bl_gf . '; service /status by uuid decides';
                    } else {
                        $wpc_bl_line .= 'POINTER PROBE FAILED (network) — cannot attribute from this box; probe the pointer URL externally';
                    }
                    $wpc_bl_line .= ' | ptr=' . $wpc_bl_ptr;
                }
                $o[] = $wpc_bl_line;
            }
        }


        $uuid = @is_readable($cd . 'uuid.txt') ? preg_replace('/[^A-Za-z0-9-]/', '', trim((string) @file_get_contents($cd . 'uuid.txt'))) : '';
        if ($uuid === '') { $uuid = (string) get_transient('wpc_critical_uuid_' . $key); }
        if ($uuid && defined('WPS_IC_CRITICAL_API_URL')) {
            $su = str_replace('/generate', '/status', WPS_IC_CRITICAL_API_URL) . '?uuid=' . urlencode((string) $uuid);
            $sr = wp_remote_get($su, ['timeout' => 6]);
            if (!is_wp_error($sr)) {
                $sd = json_decode((string) wp_remote_retrieve_body($sr), true);
                $o[] = "/status (uuid=" . substr((string) $uuid, 0, 8) . "): code=" . wp_remote_retrieve_response_code($sr)
                     . " status=" . (is_array($sd) ? ($sd['status'] ?? '?') : 'non-json')
                     . " | lcp_url=" . (is_array($sd) && !empty($sd['lcp_url']) ? 'Y' : '-')
                     . " | fonts_url=" . (is_array($sd) && !empty($sd['fonts_url']) ? 'Y' : (is_array($sd) && !empty($sd['fonts']) ? 'inline' : '-'))
                     . " | delay_url=" . (is_array($sd) && !empty($sd['delay_url']) ? 'Y' : '-')
                     . " | used_css_url=" . (is_array($sd) && !empty($sd['used_css_url']) ? 'Y' : '-')
                     . " | used_css_sheets_url=" . (is_array($sd) && !empty($sd['used_css_sheets_url']) ? 'Y' : (is_array($sd) && !empty($sd['used_css_sheets']) ? 'inline' : '-'))
                     . " | tpl_key=" . (is_array($sd) && !empty($sd['tpl_key']) ? 'Y' : '-');
                if (is_array($sd)) { $o[] = "/status keys: " . implode(',', array_keys($sd)); }
            } else {
                $o[] = "/status: WP_Error " . $sr->get_error_message();
            }
        } else {
            $o[] = "/status: no pending uuid transient (crit landed + cleared, or object-cache lost)";
        }
        $clf = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl' : '';
        $ev = [];
        if ($clf && @is_readable($clf)) {
            foreach (array_slice((array) @file($clf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -22) as $ln) {
                $d = json_decode($ln, true);
                if (is_array($d)) { $ev[] = $d; }
            }
        }
        if (empty($ev)) { // DB fallback (wpc_cache_first_log writes here when the file path refuses)
            $log = get_option('wpc_cache_first_log', []);
            if (is_array($log)) { $ev = array_slice($log, -22); }
        }
        // v7.10.614 — LANE SPLIT, surfaced explicitly. It fires at most once a day while the
        // cflog runs hundreds of entries an hour, so the last-22 tail below would essentially
        // never contain it. "no split found" and "the detector has not run" are completely
        // different answers and must never look alike, so both are printed.
        $wpc_ls614 = null;
        if ($clf && @is_readable($clf)) {
            foreach (array_reverse(array_slice((array) @file($clf, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -4000)) as $wpc_ln614) {
                if (strpos($wpc_ln614, 'lane-split') === false) { continue; }
                $wpc_d614 = json_decode($wpc_ln614, true);
                if (is_array($wpc_d614) && ($wpc_d614['event'] ?? '') === 'lane-split') { $wpc_ls614 = $wpc_d614; break; }
            }
        }
        $wpc_ran614 = function_exists('get_transient') ? (bool) get_transient('wpc_lane_split613') : false;
        if ($wpc_ls614 === null) {
            $o[] = "LANE SPLIT: none recorded — "
                 . ($wpc_ran614
                    ? "detector HAS run this period and found no split (good)"
                    : "detector has NOT run yet: it needs a WARM or CRON request, and runs once per day. Trigger a warm, then re-check.");
        } else {
            $wpc_l614 = is_array($wpc_ls614['layers'] ?? null) ? $wpc_ls614['layers'] : [];
            $wpc_age614 = max(0, time() - (int) ($wpc_ls614['t'] ?? 0));
            $o[] = "LANE SPLIT: quiet=" . (int) ($wpc_l614['quiet'] ?? 0)
                 . " hard=" . (int) ($wpc_l614['hard'] ?? 0)
                 . " | " . $wpc_age614 . "s ago"
                 . " | url=" . substr((string) ($wpc_ls614['url'] ?? '-'), 0, 48);
            if (!empty($wpc_l614['q'])) { $o[] = "  QUIET (dependent delayed, its framework sync — handlers never attach): " . $wpc_l614['q']; }
            if (!empty($wpc_l614['h'])) { $o[] = "  HARD  (dependent sync, its dependency delayed — runs before what it needs): " . $wpc_l614['h']; }
        }
        $o[] = "--- cflog (last " . count($ev) . " land events; src=" . ($clf && @is_readable($clf) ? 'file' : 'db') . ") ---";
        foreach ($ev as $e) {
            if (is_array($e)) {
                $o[] = "  " . gmdate('H:i:s', (int) ($e['t'] ?? 0)) . " " . ($e['event'] ?? '?')
                     // 70 chars truncated diagnostic payloads to uselessness — the .441
                     // no-stem-match fields (uri/inhtml/qw/imgs) sat past a 60-char stem and
                     // were never readable. A log you cannot read is not instrumentation.
                     . (empty($e['layers']) ? '' : ' ' . substr(json_encode($e['layers']), 0,
                         (int) apply_filters('wpc_cflog_payload_chars', 560)));
            }
        }
        // AGGRESSIVE-FLIP DIAGNOSIS: the exact reason a page does/doesn't go
        // interaction-only — reads the same inputs the render flip reads, so it
        // names the blocker without guessing (added .368 for the busy stall).
        $o[] = "--- FLIP DIAGNOSIS ---";
        $wpc_fd_moff = (int) get_option('wpc_delay_v3_manifest_off', 0);
        $wpc_fd_aoff = (int) get_option('wpc_delay_aggr_off', 0);
        $wpc_fd_dj   = $cd !== '' ? $cd . 'delay.json' : '';
        $wpc_fd_djok = $wpc_fd_dj !== '' && @is_readable($wpc_fd_dj);
        $wpc_fd_ceil = false; $wpc_fd_rc = 'n/a'; $wpc_fd_mt = 0; $wpc_fd_epoch = 'absent'; $wpc_fd_measured = false; $wpc_fd_j = null;
        if ($wpc_fd_djok) {
            $wpc_fd_mt = (int) @filemtime($wpc_fd_dj);
            $wpc_fd_j  = json_decode((string) @file_get_contents($wpc_fd_dj), true);
            $wpc_fd_ceil = is_array($wpc_fd_j) && isset($wpc_fd_j['ceiling']) && is_array($wpc_fd_j['ceiling']);
            if (is_array($wpc_fd_j) && isset($wpc_fd_j['schema_epoch'])) { $wpc_fd_epoch = (string) (int) $wpc_fd_j['schema_epoch']; }
            $wpc_fd_rc = 'absent';
            foreach ([$wpc_fd_j, $wpc_fd_j['mobile'] ?? null, $wpc_fd_j['desktop'] ?? null] as $wpc_fd_s) {
                if (is_array($wpc_fd_s) && isset($wpc_fd_s['render_critical']) && is_array($wpc_fd_s['render_critical'])) {
                    $wpc_fd_rc = 'present(' . count($wpc_fd_s['render_critical']) . ')'; break;
                }
            }
            $wpc_fd_measured = class_exists('wps_ic_js_delay_v3') && wps_ic_js_delay_v3::wpc_delay_measured_shape($wpc_fd_j);
        }
        // Evaluate for the TARGET $cd/$key directly — NOT the ambient admin-ajax
        // request context (wpc_aggr_live()/wpc_measured use the request url_key,
        // which is admin-ajax here → false negative; the 48 self-test caught it).
        $wpc_fd_uuid = @is_readable($cd . 'uuid.txt') ? trim((string) @file_get_contents($cd . 'uuid.txt')) : '-';
        $wpc_fd_luid = @is_readable($cd . 'land_uuid.txt') ? trim((string) @file_get_contents($cd . 'land_uuid.txt')) : '-';
        $wpc_fd_lpre = 'absent';
        if (@is_readable($cd . 'lcp.json')) {
            $wpc_fd_lj = json_decode((string) @file_get_contents($cd . 'lcp.json'), true);
            if (is_array($wpc_fd_lj) && !empty($wpc_fd_lj['hints']['lcp_preload'])) { $wpc_fd_lpre = 'PRESENT(' . count($wpc_fd_lj['hints']['lcp_preload']) . ')'; }
        }
        $o[] = "on-disk uuid: " . $wpc_fd_uuid . " | land_uuid: " . $wpc_fd_luid . " | lcp.json lcp_preload: " . $wpc_fd_lpre . "  <-- land_uuid is the OBS uuid; it holds steady by design while the CRIT uuid advances (template-cache split). Only STALE if lcp_preload is absent";
        $o[] = "delay.json on disk: " . ($wpc_fd_djok ? filesize($wpc_fd_dj) . 'B, mtime ' . max(0, time() - $wpc_fd_mt) . 's ago' : 'ABSENT');
        $o[] = "  schema_epoch: " . $wpc_fd_epoch . " | ceiling{}: " . ($wpc_fd_ceil ? 'PRESENT' : 'ABSENT') . " | render_critical key: " . $wpc_fd_rc . ($wpc_fd_djok && !$wpc_fd_measured ? '  <-- OLD-SCHEMA gen (no schema_epoch>=1 / no ceiling); amendment-refetch (meta-or-DERIVED uuid URL) supersedes on next repull' : '');
        // Live probe of what the amendment-refetch WILL pull (meta-or-derived from
        // the on-disk uuid) — proves whether the fix converges plugin-side or is
        // genuinely blocked service-side, without guessing.
        if ($wpc_fd_djok && !$wpc_fd_measured && function_exists('wpc_crit_artifact_url')) {
            $wpc_fd_amu = wpc_crit_artifact_url($cd, 'delay');
            if ($wpc_fd_amu !== '') {
                $wpc_fd_pr = wp_remote_get($wpc_fd_amu, ['headers' => ['user-agent' => defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : ''], 'timeout' => 4]);
                $wpc_fd_pc = !is_wp_error($wpc_fd_pr) ? (int) wp_remote_retrieve_response_code($wpc_fd_pr) : 0;
                $wpc_fd_pb = $wpc_fd_pc === 200 ? (string) wp_remote_retrieve_body($wpc_fd_pr) : '';
                $wpc_fd_pm = $wpc_fd_pb !== '' && class_exists('wps_ic_js_delay_v3')
                    && wps_ic_js_delay_v3::wpc_delay_measured_shape(json_decode($wpc_fd_pb, true));
                // v7.10.476 — DO NOT ATTRIBUTE ON AN OLD ARTIFACT. The probe fetches the copy for
                // the uuid ON DISK. If that gen predates the measured-shape feature, "old-schema"
                // says nothing about what the service emits TODAY — and the previous wording
                // ("SERVICE-side") sent the service team hunting a fault that was not theirs.
                // A stale artifact is un-attributable by construction; only a RECENT one accuses.
                $wpc_fd_age = ($wpc_fd_mt > 0) ? max(0, time() - $wpc_fd_mt) : -1;
                $wpc_fd_oldgen = ($wpc_fd_age < 0
                    || $wpc_fd_age > (int) apply_filters('wpc_amend_probe_attribution_max_age', 6 * HOUR_IN_SECONDS));
                $o[] = "  amendment probe: HTTP " . $wpc_fd_pc . " | " . strlen($wpc_fd_pb) . "B | measured-shape: "
                    . ($wpc_fd_pm
                        ? 'YES → amendment-refetch WILL land this on next repull ✓'
                        : ($wpc_fd_pc === 200
                            ? ($wpc_fd_oldgen
                                ? 'NO — but this artifact is ' . ($wpc_fd_age < 0 ? 'of unknown age' : human_time_diff($wpc_fd_mt, time()) . ' old')
                                  . ', so it PREDATES the measured-shape feature. NOT attributable: dispatch a fresh gen to find out.'
                                : 'NO on a RECENT gen (' . human_time_diff($wpc_fd_mt, time()) . ' old) → service-side')
                            : 'unreachable (retry/not-yet-landed)'))
                    . "\n    url: " . preg_replace('/[?&]t=\d+/', '', $wpc_fd_amu);
            }
        }
        $o[] = "manifest_off (promotion kill-switch): " . ($wpc_fd_moff > 0 ? 'SET ' . max(0, time() - $wpc_fd_moff) . 's ago' . ($wpc_fd_mt > $wpc_fd_moff ? ' (gen NEWER → .367 overrides ✓)' : ' (gen OLDER → blocks unless bg-heal ran)') : 'clear');
        $o[] = "aggr_off (watchdog demote): " . ($wpc_fd_aoff > 0 ? 'SET ' . max(0, time() - $wpc_fd_aoff) . 's ago (DEMOTED)' : 'clear');
        $wpc_fd_master = class_exists('wps_ic_js_delay_v3') && method_exists('wps_ic_js_delay_v3', 'wpc_delay_master_on')
            ? (wps_ic_js_delay_v3::wpc_delay_master_on($set) ? 'ON' : 'OFF') : '?';
        $wpc_fd_moffblk  = $wpc_fd_moff > 0 && $wpc_fd_mt <= $wpc_fd_moff;
        $wpc_fd_tele = apply_filters('wpc_delay_v3_telemetry', true) && apply_filters('wpc_delay_v3_io_when_measured', true);
        $o[] = "delay master: " . $wpc_fd_master . " | measured(this gen): " . ($wpc_fd_measured ? 'YES' : 'NO')
            . " | telemetry+io: " . ($wpc_fd_tele ? 'on' : 'OFF');
        $wpc_fd_flip = $wpc_fd_master === 'ON' && $wpc_fd_measured && !$wpc_fd_moffblk && $wpc_fd_aoff <= 0 && $wpc_fd_tele;
        // Ground truth from real HTML: served (what visitors/PSI get) + fresh
        // loopback (what a fresh render produces). If VERDICT=should-fire but
        // served shows 60 while fresh shows 0 → stale page-cache (purge). If
        // fresh ALSO shows 60 → the flip genuinely isn't firing (on-disk gen).
        $wpc_fd_cfg = function ($html) {
            return (is_string($html) && preg_match('/wpcDelayV3Cfg=\{"timeout":(\d+),"aggr":(\d+)/', $html, $m))
                ? 'timeout:' . $m[1] . ',aggr:' . $m[2] : 'n/a';
        };
        $o[] = "served flip config: " . $wpc_fd_cfg(isset($h) ? $h : '')
            . " | fresh-render: " . ($wpc_fd_cfg(isset($hf) ? $hf : '') === 'n/a' && (!isset($hf) || $hf === '') ? 'loopback-unavailable' : $wpc_fd_cfg(isset($hf) ? $hf : ''));
        $o[] = "VERDICT: " . ($wpc_fd_flip
            ? 'flip SHOULD fire (timeout:0,aggr:1) — if FRESH-render above shows 0 but the live page shows 60, it is stale page-cache: purge + reload'
            : 'flip WILL NOT fire — ' . (!$wpc_fd_djok ? 'no delay.json on disk' : (!$wpc_fd_measured ? 'on-disk gen is OLD-SCHEMA (no schema_epoch>=1, no ceiling{}) — amendment-refetch pulls the measured copy from the meta-or-DERIVED uuid URL on the next repull: measured YES above = the flip lands automatically; measured NO is only attributable when that artifact is RECENT (see its age above) — on an old gen it means no new gen has landed, not that the service is behind (uuid on-disk ' . $wpc_fd_uuid . ')' : ($wpc_fd_master === 'OFF' ? 'delay master off' : ($wpc_fd_moffblk ? 'manifest_off newer than gen' : ($wpc_fd_aoff > 0 ? 'watchdog-demoted' : 'telemetry/io off'))))));

        echo implode("\n", $o) . "\n";
        wp_die();
    }
    add_action('wp_ajax_wpc_perf_debug', 'wpc_perf_debug_report');
    add_action('wp_ajax_nopriv_wpc_perf_debug', 'wpc_perf_debug_report');
}

if (!function_exists('wpc_upload_premint15')) {
    /**
     * v7.20.15 — upload-hook pre-mint (service contract: the natural URL IS the pre-mint
     * endpoint). When an upload's sizes land, fire non-blocking GETs at each emitted
     * size's next-gen zone URL with its origin-ext hint: the edge mints and caches, the
     * first visitor lands on warm keys. Bounded: full + WP sizes x2 formats, 12-URL cap,
     * blocking=false + 2s timeout, image mimes only, standalone from compress-on-upload
     * (fires even with auto-optimize off). Kill filter wpc_upload_premint.
     */
    function wpc_upload_premint15($data, $attachment_id)
    {
        try {
            if (!is_array($data) || !apply_filters('wpc_upload_premint', true)
                || !function_exists('wp_remote_get') || !function_exists('wp_get_attachment_url')
                || !class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'src_hint_qs')) {
                return $data;
            }
            $zone = trim((string) get_option('ic_cdn_zone_name', ''));
            if ($zone === ''
                || (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed())) {
                return $data;
            }
            $url   = (string) wp_get_attachment_url($attachment_id);
            $clean = (string) preg_replace('/[?#].*$/', '', $url);
            $ext   = strtolower((string) pathinfo($clean, PATHINFO_EXTENSION));
            if (!in_array($ext, array('jpg', 'jpeg', 'png', 'webp'), true)) {
                return $data;
            }
            $site = rtrim((string) site_url(), '/');
            if (strpos($clean, $site) !== 0) {
                return $data;
            }
            $base = 'https://' . $zone . substr($clean, strlen($site));
            $hint = wps_rewriteLogic::src_hint_qs($ext);
            $dir  = substr($base, 0, strrpos($base, '/') + 1);
            $mk   = function ($fileBase) use ($hint, $ext) {
                $out = array();
                foreach (array('webp', 'avif') as $f) {
                    $u = (string) preg_replace('/\.(?:jpe?g|png|webp)$/i', '.' . $f, $fileBase);
                    if ($u === $fileBase && $f === $ext) { $out[] = $u; continue; }
                    $out[] = $u . ($hint !== '' ? $hint : '');
                }
                return $out;
            };
            $urls = $mk($base);
            if (isset($data['sizes']) && is_array($data['sizes'])) {
                foreach ($data['sizes'] as $sz) {
                    if (count($urls) >= 12) { break; }
                    if (empty($sz['file']) || !is_string($sz['file'])) { continue; }
                    foreach ($mk($dir . $sz['file']) as $u) {
                        if (count($urls) >= 12) { break; }
                        $urls[] = $u;
                    }
                }
            }
            foreach ($urls as $u) {
                wp_remote_get($u, array('blocking' => false, 'timeout' => 2, 'redirection' => 0, 'sslverify' => true));
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('upload-premint', '', '', array('n' => count($urls), 'id' => (int) $attachment_id));
            }
        } catch (\Throwable $e) {
        }
        return $data;
    }
    add_filter('wp_generate_attachment_metadata', 'wpc_upload_premint15', PHP_INT_MAX - 1, 2);
}

if (!function_exists('wpc_rum_census_receiver')) {
    /** RUM census relay: the page beacon posts SAME-ORIGIN (no CSP connect-src walls, no
     *  third-party blocking, and the apikey never appears in page bytes — injected here).
     *  Always 204, allowlist-rebuilt payload (never relays arbitrary bytes), own-host URL
     *  only (no open relay), hourly forward cap mirroring the ingest bucket. */
    function wpc_rum_census_receiver()
    {
        while (ob_get_level()) { @ob_end_clean(); }
        status_header(204);
        if (!apply_filters('wpc_rum_beacon', true)) { exit; }
        $wpc_raw775 = (string) @file_get_contents('php://input', false, null, 0, 33000);
        if ($wpc_raw775 === '' || strlen($wpc_raw775) > 32768) { exit; }
        $wpc_j775 = json_decode($wpc_raw775, true);
        if (!is_array($wpc_j775) || (int) (isset($wpc_j775['v']) ? $wpc_j775['v'] : 0) !== 1
            || empty($wpc_j775['url']) || !is_string($wpc_j775['url'])) { exit; }
        $wpc_host775 = strtolower((string) parse_url((string) $wpc_j775['url'], PHP_URL_HOST));
        $wpc_home775 = function_exists('home_url') ? strtolower((string) parse_url(home_url(), PHP_URL_HOST)) : '';
        $wpc_sw775 = function ($h) { return strpos($h, 'www.') === 0 ? substr($h, 4) : $h; };
        if ($wpc_host775 === '' || $wpc_home775 === '' || $wpc_sw775($wpc_host775) !== $wpc_sw775($wpc_home775)) { exit; }
        $wpc_opt775 = (array) get_option(defined('WPS_IC_OPTIONS') ? WPS_IC_OPTIONS : 'wps_ic');
        if (empty($wpc_opt775['api_key'])) { exit; }
        $wpc_ct775 = (int) get_transient('wpc_rum_fw_ct');
        if ($wpc_ct775 >= (int) apply_filters('wpc_rum_forward_cap', 60)) { exit; }
        set_transient('wpc_rum_fw_ct', $wpc_ct775 + 1, HOUR_IN_SECONDS);
        $wpc_vp775 = (isset($wpc_j775['viewport']) && is_array($wpc_j775['viewport'])) ? $wpc_j775['viewport'] : [];
        $wpc_out775 = [
            'v' => 1,
            'apikey' => (string) $wpc_opt775['api_key'],
            'url' => substr((string) $wpc_j775['url'], 0, 500),
            'viewport' => [
                'w' => (int) (isset($wpc_vp775['w']) ? $wpc_vp775['w'] : 0),
                'h' => (int) (isset($wpc_vp775['h']) ? $wpc_vp775['h'] : 0),
                'dpr' => (float) (isset($wpc_vp775['dpr']) ? $wpc_vp775['dpr'] : 1),
            ],
            'lcp' => null,
            'atf_images' => [],
        ];
        if (isset($wpc_j775['lcp']) && is_array($wpc_j775['lcp'])) {
            $wpc_lr775 = (isset($wpc_j775['lcp']['rect']) && is_array($wpc_j775['lcp']['rect'])) ? $wpc_j775['lcp']['rect'] : [];
            $wpc_out775['lcp'] = [
                'selector' => substr((string) (isset($wpc_j775['lcp']['selector']) ? $wpc_j775['lcp']['selector'] : ''), 0, 120),
                'url' => (isset($wpc_j775['lcp']['url']) && is_string($wpc_j775['lcp']['url'])) ? substr($wpc_j775['lcp']['url'], 0, 300) : null,
                'rect' => [
                    'w' => (int) (isset($wpc_lr775['w']) ? $wpc_lr775['w'] : 0),
                    'h' => (int) (isset($wpc_lr775['h']) ? $wpc_lr775['h'] : 0),
                    'x' => (int) (isset($wpc_lr775['x']) ? $wpc_lr775['x'] : 0),
                    'y' => (int) (isset($wpc_lr775['y']) ? $wpc_lr775['y'] : 0),
                ],
                't_ms' => (int) (isset($wpc_j775['lcp']['t_ms']) ? $wpc_j775['lcp']['t_ms'] : 0),
            ];
        }
        if (isset($wpc_j775['atf_images']) && is_array($wpc_j775['atf_images'])) {
            foreach (array_slice($wpc_j775['atf_images'], 0, 20) as $wpc_im775) {
                if (!is_array($wpc_im775)) { continue; }
                $wpc_cl775 = [];
                if (isset($wpc_im775['classes']) && is_array($wpc_im775['classes'])) {
                    foreach (array_slice($wpc_im775['classes'], 0, 2) as $wpc_c775) {
                        if (is_string($wpc_c775) && $wpc_c775 !== '') { $wpc_cl775[] = substr($wpc_c775, 0, 80); }
                    }
                }
                $wpc_out775['atf_images'][] = [
                    'classes' => $wpc_cl775,
                    'slot_w' => (int) (isset($wpc_im775['slot_w']) ? $wpc_im775['slot_w'] : 0),
                    'slot_h' => (int) (isset($wpc_im775['slot_h']) ? $wpc_im775['slot_h'] : 0),
                    'intrinsic_w' => (int) (isset($wpc_im775['intrinsic_w']) ? $wpc_im775['intrinsic_w'] : 0),
                    'current_src' => (isset($wpc_im775['current_src']) && is_string($wpc_im775['current_src'])) ? substr($wpc_im775['current_src'], 0, 300) : '',
                    'loading' => substr((string) (isset($wpc_im775['loading']) ? $wpc_im775['loading'] : ''), 0, 10),
                ];
            }
        }
        $wpc_ep775 = apply_filters('wpc_rum_census_endpoint',
            defined('WPS_IC_CRITICAL_API_URL') ? str_replace('/generate', '/rum-census', WPS_IC_CRITICAL_API_URL) : '');
        if (!is_string($wpc_ep775) || strpos($wpc_ep775, 'http') !== 0) { exit; }
        wp_remote_post($wpc_ep775, [
            'timeout' => 2,
            'blocking' => false,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode($wpc_out775),
        ]);
        exit;
    }
    add_action('wp_ajax_wpc_rum_census', 'wpc_rum_census_receiver');
    add_action('wp_ajax_nopriv_wpc_rum_census', 'wpc_rum_census_receiver');
}


if (!function_exists('wpc_font_inline_self_arm')) {
    function wpc_font_inline_self_arm()
    {
        try {
            if (!is_admin() || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)
                || !apply_filters('wpc_localize_inline_fonts', true)
                || get_transient('wpc_font_selfarm_lock')) {
                return;
            }
            if (!class_exists('wps_ic_fonts') && defined('WPS_IC_DIR')) {
                @include_once WPS_IC_DIR . 'addons/fonts/fonts.class.php';
            }
            if (!class_exists('wps_ic_fonts')) {
                return;
            }
            $f = new wps_ic_fonts();
            if (method_exists($f, 'isActive') && $f->isActive() !== 'local') {
                return;
            }

            set_transient('wpc_font_selfarm_lock', 1, 30 * MINUTE_IN_SECONDS); // ≤1/30min, own throttle
            register_shutdown_function(function () use ($f) {
                if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
                if (function_exists('set_time_limit')) { @set_time_limit(120); }
                try {
                    $n = method_exists($f, 'localizeInlineFonts') ? (int) $f->localizeInlineFonts('') : 0;
                    if ($n > 0) {
                        // Site-wide purge at most once/day: a recurring n>0 means the localize
                        // loop hasn't converged, and wiping the HTML cache each pass turns that
                        // into a permanent cold-cache storm (the FPM-stampede class).
                        if (!get_transient('wpc_font_purgeall_day')
                            && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                            set_transient('wpc_font_purgeall_day', 1, DAY_IN_SECONDS);
                            try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
                        }

                        delete_transient('wpc_font_inline_lock');
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('font-selfarm', '', '', ['n' => $n]);
                        }
                    }
                } catch (\Throwable $e) {
                }
            });
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_font_inline_self_arm');
}


// (crit-less is the lander's job, not ours). Logged. Kill: wpc_convergence_selfheal.
if (!function_exists('wpc_convergence_selfheal')) {
    function wpc_convergence_selfheal()
    {
        try {
            if (!is_admin() || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)
                || !apply_filters('wpc_convergence_selfheal', true) || get_transient('wpc_selfheal_lock')) {
                return;
            }
            set_transient('wpc_selfheal_lock', 1, HOUR_IN_SECONDS);
            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                if (function_exists('wpc_bg_slot_take') && !wpc_bg_slot_take('selfheal')) { return; }
                if (function_exists('set_time_limit')) { @set_time_limit(60); }
                try {
                    $url = home_url('/');
                    $r = wp_remote_get($url, ['timeout' => 15, 'headers' => ['User-Agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile', 'X-WPC-Selfheal' => '1']]);
                    if (is_wp_error($r)) { return; }
                    $h = (string) wp_remote_retrieve_body($r);
                    if (strpos($h, 'wpc-critical-css') === false) { return; }
                    $miss = '';
                    // (1) a bg-hero is derivable but the served page has no preload → stale
                    if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_lcp_autoderive_bg')
                        && strpos($h, 'wpc-lcp-bg-preload') === false) {
                        $ad = wps_rewriteLogic::wpc_lcp_autoderive_bg($h, '');
                        if (is_array($ad) && !empty($ad['url'])) { $miss = 'lcp-preload'; }
                    }
                    // (2) a subset was built but isn't in the served crit → stale
                    if ($miss === '' && class_exists('wps_ic_url_key') && defined('WPS_IC_CRITICAL')) {
                        $k  = (new wps_ic_url_key())->setup($url);
                        $sf = rtrim(WPS_IC_CRITICAL, '/') . '/' . $k . '/font-subsets.css';
                        if ($k !== '' && @is_readable($sf) && strpos($h, 'data:font/woff2;base64') === false) {
                            $miss = 'subset-inline';
                        }
                    }
                    if ($miss !== '') {
                        $tries = (int) get_transient('wpc_selfheal_tries');
                        if ($tries >= 3) { return; }
                        set_transient('wpc_selfheal_tries', $tries + 1, 6 * HOUR_IN_SECONDS);
                        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                            try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
                        }
                        if (function_exists('wpc_warm_url_queue')) { wpc_warm_url_queue($url, 'selfheal'); }
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('selfheal-purge', '', $url, ['miss' => $miss, 'try' => $tries + 1]);
                        }
                    } else {
                        delete_transient('wpc_selfheal_tries');
                    }
                } catch (\Throwable $e) {
                }
            });
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_convergence_selfheal', 20);
}


if (!function_exists('wpc_brand_name')) {
    function wpc_brand_name()
    {
        $wl = class_exists('whtlbl_whitelabel_plugin');
        return (string) apply_filters('wpc_brand_name', $wl ? __('Your optimization plugin', 'wp-compress-image-optimizer') : 'WP Compress');
    }
}
if (!function_exists('wpc_device_blind_cache_conflict')) {
    function wpc_device_blind_cache_conflict()
    {
        if (!defined('BREEZE_VERSION') || !function_exists('get_option')) { return false; }
        $b = get_option('breeze_basic_settings');
        if (!is_array($b)) { return false; }
        $cache_on  = !empty($b['breeze-desktop-cache']) && $b['breeze-desktop-cache'] == '1';
        $mobile_on = !empty($b['breeze-mobile-cache']) && $b['breeze-mobile-cache'] == '1';
        if (!$cache_on || $mobile_on) { return false; }
        $s = defined('WPS_IC_SETTINGS') ? get_option(WPS_IC_SETTINGS) : [];
        return is_array($s) && ((!empty($s['cache']['advanced']) && $s['cache']['advanced'] == '1') || !empty($s['critical']));
    }
    add_action('admin_notices', function () {
        if (!function_exists('current_user_can') || !current_user_can('manage_wpc_settings')) { return; }
        if (get_option('wpc_devblind_notice_dismissed') || !wpc_device_blind_cache_conflict()) { return; }
        $brand = esc_html(wpc_brand_name());
        $fix1 = wp_nonce_url(admin_url('admin-post.php?action=wpc_devblind_fix&mode=breeze_off'), 'wpc_devblind');
        $fix2 = wp_nonce_url(admin_url('admin-post.php?action=wpc_devblind_fix&mode=mobile_on'), 'wpc_devblind');
        $dis  = wp_nonce_url(admin_url('admin-post.php?action=wpc_devblind_fix&mode=dismiss'), 'wpc_devblind');
        echo '<div class="notice notice-warning"><p><strong>' . $brand . ':</strong> '
            . sprintf(esc_html__('Breeze is caching one page copy for ALL devices, which conflicts with %s\'s per-device optimization (mobile visitors can receive desktop-optimized pages).', 'wp-compress-image-optimizer'), $brand)
            . '</p><p><a class="button button-primary" href="' . esc_url($fix1) . '">' . esc_html__('Disable Breeze page cache (recommended)', 'wp-compress-image-optimizer') . '</a> '
            . '<a class="button" href="' . esc_url($fix2) . '">' . esc_html__('Enable Breeze Mobile Cache', 'wp-compress-image-optimizer') . '</a> '
            . '<a href="' . esc_url($dis) . '" style="margin-left:8px;">' . esc_html__('Dismiss', 'wp-compress-image-optimizer') . '</a></p></div>';
    });
    add_action('admin_post_wpc_devblind_fix', function () {
        if (!current_user_can('manage_wpc_settings') || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'wpc_devblind')) { wp_die('Forbidden'); }
        $mode = sanitize_key($_GET['mode'] ?? '');
        if ($mode === 'dismiss') {
            update_option('wpc_devblind_notice_dismissed', time(), false);
        } elseif (in_array($mode, ['breeze_off', 'mobile_on'], true)) {
            $b = get_option('breeze_basic_settings');
            if (is_array($b)) {
                if ($mode === 'breeze_off') { $b['breeze-desktop-cache'] = '0'; }
                else { $b['breeze-mobile-cache'] = '1'; $b['breeze-mobile-separate'] = '1'; }
                update_option('breeze_basic_settings', $b);
                if (function_exists('do_action')) { do_action('breeze_clear_all_cache'); }
                if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) { wps_ic_cache::removeHtmlCacheFiles('all'); }
            }
        }
        wp_safe_redirect(wp_get_referer() ?: admin_url()); exit;
    });
}


add_action('update_option_' . (defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings'), function ($old, $new) {
    try {
        $o = is_array($old) && isset($old['replace-fonts']) ? $old['replace-fonts'] : '';
        $n = is_array($new) && isset($new['replace-fonts']) ? $new['replace-fonts'] : '';
        if ($o !== $n) {
            // Deferred: a Save must never pay the full wipe inline
            if (!wp_next_scheduled('wpc_sitechange_trailing')) {
                wp_schedule_single_event(time() + 8, 'wpc_sitechange_trailing');
            }
            if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('fonts-flip-purge-deferred', '', '', ['from' => (string) $o, 'to' => (string) $n]); }
        }
    } catch (\Throwable $e) {
    }
}, 10, 2);


// Opt-in per site via the Performance Advisory card toggle (wpc_auto_mode option). The loop is
// SERVER-side (cron single-events; this file is loaded by BOTH core and wp-compress-cron.php, so


if (!function_exists('wpc_auto_mode_on')) {
    function wpc_auto_mode_on()
    {
        // Default ON for connected sites; an explicit off (option '0') is always respected.
        $v = get_option('wpc_auto_mode', null);
        if ($v === null || $v === false || $v === '') {
            $o = get_option(defined('WPS_IC_OPTIONS') ? WPS_IC_OPTIONS : 'wps_ic');
            $v = (is_array($o) && !empty($o['api_key'])) ? '1' : '0';
        }
        return apply_filters('wpc_auto_mode', $v === '1');
    }
    /**
     * v7.10.824 — a freeze is a verdict about ONE generation, not about the site forever.
     * frozen[] had no remover: a lever measured during a stale/settling window (vincire's R2)
     * reverted once and stayed off permanently, surviving upgrades and fresh gens that changed
     * everything the measurement was based on. The freeze now carries the epoch it was minted
     * in (plugin version + landed gen uuid); a different epoch thaws every frozen lever once,
     * journaled, and the measured cycle re-tries it — worst case one measured revert per epoch.
     */
    function wpc_auto_freeze_epoch824()
    {
        static $wpc_e824 = null;
        if ($wpc_e824 !== null) { return $wpc_e824; }
        $wpc_u824 = '';
        try {
            if (defined('WPS_IC_CRITICAL') && class_exists('wps_ic_url_key') && function_exists('home_url')) {
                $wpc_k824 = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
                if ($wpc_k824 !== '') {
                    $wpc_u824 = trim((string) @file_get_contents(rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_k824 . '/land_uuid.txt'));
                }
            }
        } catch (\Throwable $x) {
        }
        $wpc_e824 = (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?') . '|' . $wpc_u824;
        return $wpc_e824;
    }
    function wpc_auto_state()
    {
        $s = get_option('wpc_auto_mode_state');
        $d = [
            'status' => 'idle', 'cycle' => 0, 'run_id' => '', 'last_score' => null,
            'baseline' => null, 'flipped_last' => [], 'frozen' => [], 'msg' => '',

            'next_tick_at' => 0, 'measure_started_at' => 0, 'run_id_d' => '', 'last_score_d' => null,
            'arm_tries' => 0,
        ];
        $s = is_array($s) ? array_merge($d, $s) : $d;
        if (!empty($s['frozen'])) {
            $wpc_ep824 = wpc_auto_freeze_epoch824();
            if ((string) (isset($s['frozen_epoch']) ? $s['frozen_epoch'] : '') !== $wpc_ep824) {
                $wpc_th824 = $s['frozen'];
                $s['frozen'] = [];
                $s['frozen_epoch'] = $wpc_ep824;
                update_option('wpc_auto_mode_state', $s, false);
                if (function_exists('wpc_auto_journal')) {
                    wpc_auto_journal('auto-thaw', ['rules' => $wpc_th824, 'epoch' => $wpc_ep824]);
                }
            }
        }
        return $s;
    }
    function wpc_auto_state_save($s)
    {
        update_option('wpc_auto_mode_state', $s, false);
    }
    // Slow-render profiler: any frontend request over 3s journals WHERE the time went —
    // outbound HTTP (count/ms/worst call) named per-URL.
    if (!function_exists('wpc_slowreq_arm')) {
        function wpc_slowreq_arm()
        {
            $GLOBALS['wpc_sr_http'] = ['n' => 0, 'ms' => 0, 'worst' => '', 'worst_ms' => 0, 'calls' => []];
            add_filter('http_request_args', function ($a, $u) {
                $a['_wpc_t0'] = microtime(true);
                return $a;
            }, 1, 2);
            add_action('http_api_debug', function ($resp, $ctx, $cls, $args, $url) {
                if (empty($args['_wpc_t0'])) {
                    return;
                }
                $ms = (int) round((microtime(true) - (float) $args['_wpc_t0']) * 1000);
                $GLOBALS['wpc_sr_http']['n']++;
                $GLOBALS['wpc_sr_http']['ms'] += $ms;
                if ($ms > $GLOBALS['wpc_sr_http']['worst_ms']) {
                    $GLOBALS['wpc_sr_http']['worst_ms'] = $ms;
                    $GLOBALS['wpc_sr_http']['worst'] = substr((string) $url, 0, 90);
                }
                // The worst call alone accounted for 275 of 955ms on a busy visitor render and left
                // ~680ms across four unnamed calls. A compact per-call list closes that: host + last
                // path segment + ms + b|n for blocking, capped at 8 entries so the payload stays small.
                if (count($GLOBALS['wpc_sr_http']['calls']) < 8) {
                    $wpc_ch157 = (string) parse_url((string) $url, PHP_URL_HOST);
                    $wpc_cp157 = trim((string) parse_url((string) $url, PHP_URL_PATH), '/');
                    if ($wpc_cp157 !== '' && strpos($wpc_cp157, '/') !== false) {
                        $wpc_cp157 = substr($wpc_cp157, strrpos($wpc_cp157, '/') + 1);
                    }
                    $GLOBALS['wpc_sr_http']['calls'][] = substr($wpc_ch157, 0, 22)
                        . ($wpc_cp157 !== '' ? '/' . substr($wpc_cp157, 0, 18) : '')
                        . ':' . $ms . (empty($args['blocking']) ? 'n' : 'b');
                }
            }, 1, 5);
            add_action('shutdown', function () {
                $t0 = isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : 0;
                if (!$t0) {
                    return;
                }
                $ms = (int) round((microtime(true) - $t0) * 1000);
                // Deliberate holds (the dashboard's manifest LONG-POLL parks a connection for up
                // to 25s BY DESIGN) waive their measured wait here — a 26s "slow render" that is
                // 25s of intentional idle sent two teams investigating a non-problem. The waiver
                // is an accumulator, not an action-name match: any future deliberate wait joins by
                // adding its measured hold, and only the REMAINDER is judged.
                $wpc_waived751 = isset($GLOBALS['wpc_sr_waived_ms']) ? (int) $GLOBALS['wpc_sr_waived_ms'] : 0;
                if (($ms - $wpc_waived751) < 3000) {
                    return;
                }
                $h = isset($GLOBALS['wpc_sr_http']) ? $GLOBALS['wpc_sr_http'] : ['n' => 0, 'ms' => 0, 'worst' => '', 'worst_ms' => 0];
                if (function_exists('wpc_auto_journal')) {
                    // Phase deltas answer WHERE the seconds went: big 'boot' = PHP/FPM queue
                    // or plugin load; big init->tpl gap = a hook; big q = DB volume; big
                    // http_ms = outbound calls; high load = machine, not this request.
                    $wpc_m157 = isset($GLOBALS['wpc_ms157']) ? $GLOBALS['wpc_ms157'] : [];
                    $wpc_d157 = function ($k) use ($wpc_m157, $t0) {
                        return isset($wpc_m157[$k]) ? (int) round(($wpc_m157[$k] - $t0) * 1000) : -1;
                    };
                    global $wpdb;
                    $wpc_load157 = function_exists('sys_getloadavg') ? (array) sys_getloadavg() : [0];
                    // Ordered phase timeline — the GAP between two adjacent stamps names the
                    // phase that ate the seconds. Only stamps that fired are listed, so a
                    // missing name is itself evidence (never reached that hook).
                    $wpc_ph157 = [];
                    foreach (['init', 'loaded', 'tpl', 'adm', 'wp', 'head0', 'head9', 'foot0', 'foot9',
                              'shut', 's1', 's5', 's9', 's10', 's20', 's100', 's500'] as $wpc_pk157) {
                        if (isset($wpc_m157[$wpc_pk157])) {
                            $wpc_ph157[] = $wpc_pk157 . ':' . $wpc_d157($wpc_pk157);
                        }
                    }
                    wpc_auto_journal('slow-render', [
                        'ms'      => $ms,
                        'waived'  => $wpc_waived751,
                        'uri'     => substr((string) ($_SERVER['REQUEST_URI'] ?? ''), 0, 80),
                        'boot'    => $wpc_d157('init'),
                        'tpl'     => $wpc_d157(function_exists('is_admin') && is_admin() ? 'adm' : 'tpl'),
                        'ph'      => $wpc_ph157 ? implode(' ', $wpc_ph157) : '-',
                        // last cflog event + its offset: if the stall follows a known step,
                        // this names it; if it precedes everything, the stall is pre-log.
                        'lastlog' => isset($GLOBALS['wpc_lastlog518'])
                            ? $GLOBALS['wpc_lastlog518'][0] . '@'
                              . (int) round(($GLOBALS['wpc_lastlog518'][1] - $t0) * 1000)
                            : '-',
                        // ob buffers still open at shutdown: WP flushes them at 'shutdown'
                        // priority 1, so a non-zero count here means our rewrite/save chain
                        // runs INSIDE the measured band (this corrects an earlier assumption).
                        'ob'      => function_exists('ob_get_level') ? (int) ob_get_level() : -1,
                        // v7.10.530 — the missing half. 'ph'/'ob' localise the stall to a BAND;
                        // this names the FUNCTION. Worst-first label:ms/n, incl. lock waits
                        // (lock:*) and contended locks (LOCKFAIL:*) — the two ways a request
                        // burns 60 s of wall clock without burning CPU or tripping a slowlog.
                        'prof'    => function_exists('wpc_prof_dump') ? wpc_prof_dump(25) : '-',
                        'q'       => isset($wpdb->num_queries) ? (int) $wpdb->num_queries : -1,
                        'http_n'  => $h['n'],
                        'http_ms' => $h['ms'],
                        'worst'   => $h['worst'] !== '' ? $h['worst'] . ':' . $h['worst_ms'] . 'ms' : '-',
                        'calls'   => !empty($h['calls']) ? implode(' ', (array) $h['calls']) : '-',
                        'mem'     => (int) round(memory_get_peak_usage(true) / 1048576) . 'M',
                        'load'    => round((float) $wpc_load157[0], 1),
                        'lane'    => (defined('DOING_CRON') && DOING_CRON) ? 'cron'
                            : ((function_exists('is_admin') && is_admin() && !wp_doing_ajax()) ? 'admin'
                            : ((function_exists('wp_doing_ajax') && wp_doing_ajax()) ? 'ajax'
                            : (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM']) ? 'warm' : 'visitor'))),
                    ]);
                }
            }, 999);
        }
        add_action('init', function () {
            $GLOBALS['wpc_ms157'] = ['init' => microtime(true)];
        }, 0);
        add_action('wp_loaded', function () {
            if (isset($GLOBALS['wpc_ms157'])) { $GLOBALS['wpc_ms157']['loaded'] = microtime(true); }
        }, 0);
        add_action('template_redirect', function () {
            if (isset($GLOBALS['wpc_ms157'])) { $GLOBALS['wpc_ms157']['tpl'] = microtime(true); }
        }, 0);
        add_action('admin_init', function () {
            if (isset($GLOBALS['wpc_ms157'])) { $GLOBALS['wpc_ms157']['adm'] = microtime(true); }
        }, 0);
        // Markers stopped at template_redirect, so every second after it read as one
        // opaque block: a 62s render logged boot:618 tpl:684 and named nothing.
        add_action('wp', function () {
            if (isset($GLOBALS['wpc_ms157'])) { $GLOBALS['wpc_ms157']['wp'] = microtime(true); }
        }, 99999);
        add_action('wp_head', function () {
            if (isset($GLOBALS['wpc_ms157'])) { $GLOBALS['wpc_ms157']['head0'] = microtime(true); }
        }, -99999);
        add_action('wp_head', function () {
            if (isset($GLOBALS['wpc_ms157'])) { $GLOBALS['wpc_ms157']['head9'] = microtime(true); }
        }, 99999);
        add_action('wp_footer', function () {
            if (isset($GLOBALS['wpc_ms157'])) { $GLOBALS['wpc_ms157']['foot0'] = microtime(true); }
        }, -99999);
        add_action('wp_footer', function () {
            if (isset($GLOBALS['wpc_ms157'])) { $GLOBALS['wpc_ms157']['foot9'] = microtime(true); }
        }, 99999);
        add_action('shutdown', function () {
            if (isset($GLOBALS['wpc_ms157'])) { $GLOBALS['wpc_ms157']['shut'] = microtime(true); }
        }, 0);
        // v7.10.518 — the 61.5s renders land ENTIRELY between shut:721 and the priority-999
        // journal write, so the whole request is fast and one shutdown callback eats a minute.
        // 13 of ours are registered in that band; rather than guess, stamp the band edges so
        // the gap between two adjacent marks names the priority, and the priority names the
        // hook. Six extra microtime() calls per request.
        foreach ([1, 5, 9, 10, 20, 100, 500] as $wpc_sp518) {
            add_action('shutdown', function () use ($wpc_sp518) {
                if (isset($GLOBALS['wpc_ms157'])) {
                    $GLOBALS['wpc_ms157']['s' . $wpc_sp518] = microtime(true);
                }
            }, $wpc_sp518);
        }
        add_action('init', 'wpc_slowreq_arm', 1);
    }
    function wpc_auto_journal($event, $data = [])
    {
        $j = get_option('wpc_auto_mode_journal');
        if (!is_array($j)) { $j = []; }
        $j[] = array_merge(['t' => time(), 'event' => $event], $data);
        update_option('wpc_auto_mode_journal', array_slice($j, -50), false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('auto-mode', '', '', array_merge(['ev' => $event], $data));
        }

        if (function_exists('wpc_cohort_beacon')) {
            $map = [
                'enabled' => 'auto_enabled', 'measure-start' => 'auto_cycle',
                'auto-apply' => 'auto_actuated', 'auto-revert' => 'auto_reverted',
                'converged' => 'auto_converged', 'measure-failed' => 'error',
                'measure-timeout' => 'error', 'arm-timeout' => 'error', 'no-api-key' => 'error',
            ];
            if (isset($map[$event])) {
                wpc_cohort_beacon($map[$event], array_merge(
                    $map[$event] === 'error' ? ['msg' => $event] : [], $data
                ));
            }
        }
    }


    function wpc_auto_levers()
    {


        return apply_filters('wpc_auto_mode_levers', [
            'R2' => ['key' => 'used-css', 'on' => '1', 'off' => '0'],
            'R3' => ['key' => 'replace-fonts', 'on' => 'local', 'off' => '', 'gate' => 'font-localizer'],
            'R8' => ['key' => 'delay-js-v3', 'on' => 1, 'off' => 0, 'also' => ['delay-js-v2' => [1, 0]]],
            'R9' => ['key' => 'cache.advanced', 'nested' => ['cache', 'advanced'], 'on' => 1, 'off' => 0, 'gate' => 'foreign-cache'],
            'R10' => ['key' => 'fontawesome-optimize', 'on' => '1', 'off' => '0'],
        ]);
    }


    function wpc_auto_lever_get($set, $lv)
    {
        if (!is_array($set) || !is_array($lv)) { return null; }
        if (!empty($lv['nested']) && is_array($lv['nested'])) {
            $n = $lv['nested'];
            return isset($set[$n[0]][$n[1]]) ? $set[$n[0]][$n[1]] : null;
        }
        return isset($set[$lv['key']]) ? $set[$lv['key']] : null;
    }
    function wpc_auto_lever_set(&$set, $lv, $val, $alsoVals = null)
    {
        if (!is_array($set) || !is_array($lv)) { return; }
        if (!empty($lv['nested']) && is_array($lv['nested'])) {
            $n = $lv['nested'];
            if (!isset($set[$n[0]]) || !is_array($set[$n[0]])) { $set[$n[0]] = []; }
            $set[$n[0]][$n[1]] = $val;
        } else {
            $set[$lv['key']] = $val;
        }
        if (!empty($lv['also']) && is_array($lv['also'])) {
            foreach ($lv['also'] as $k => $onoff) {
                if (is_array($alsoVals) && array_key_exists($k, $alsoVals)) {

                    $set[$k] = ($alsoVals[$k] === null || $alsoVals[$k] === '') ? $onoff[1] : $alsoVals[$k];
                } else {
                    $set[$k] = $onoff[0];
                }
            }
        }
    }
    function wpc_auto_schedule($hook, $delay, $args = [])
    {
        if (function_exists('wp_schedule_single_event') && !wp_next_scheduled($hook, $args)) {
            wp_schedule_single_event(time() + $delay, $hook, $args);
        }
    }


    function wpc_auto_lever_outcome_met($rule)
    {
        if ($rule === 'R3') {
            $map = get_option('wps_ic_fonts_inline_map');
            if (is_array($map) && count($map) > 0) { return true; }


            if (get_option('wpc_auto_r3_actuated') === '1') { return true; }
            return false;
        }
        if ($rule === 'R2') {


            try {
                if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL') || !function_exists('home_url')) {
                    return true;
                }
                $k = (new wps_ic_url_key())->setup(home_url('/'));
                $tf = WPS_IC_CRITICAL . $k . '/used_tpl.txt';
                $tpl = @is_readable($tf) ? trim((string) @file_get_contents($tf)) : '';
                if ($tpl === '') { return false; }
                $art = function_exists('wpc_used_css_path') ? wpc_used_css_path($tpl) : '';
                return ($art !== '' && @is_readable($art) && @filesize($art) > 64);
            } catch (\Throwable $e) {
                return true;
            }
        }
        if ($rule === 'R9') {

            // (serving itself is verified by the next measure + the drop-in's own self-gates).
            $p = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR . '/advanced-cache.php' : '';
            if ($p !== '' && @is_readable($p)) {
                $head = (string) @file_get_contents($p, false, null, 0, 4096);
                if ($head !== '' && (stripos($head, 'wp-compress') !== false
                    || stripos($head, 'wps_ic') !== false || stripos($head, 'advancedCache') !== false)) {
                    return true;
                }
            }
            return false;
        }
        return true; // R8 & unknown rules: the flip is the outcome (delay engine keys off settings)
    }


    function wpc_auto_actuate_lever($rule, $from, $to)
    {
        try {
            if ($rule === 'R3' && function_exists('wpc_font_rescan_handler')) {
                if (function_exists('delete_transient')) { delete_transient('wpc_font_rescan_lock'); }
                wpc_font_rescan_handler();
                update_option('wpc_auto_r3_actuated', '1', false);
            }
            if ($rule === 'R2' && function_exists('wpc_warm_url_fire') && function_exists('home_url')) {

                // = one dispatch. Bounded by the loop's single-flight + 3-cycle cap.
                wpc_warm_url_fire(home_url('/'));
            }
            if ($rule === 'R9') {


                if (!class_exists('wps_ic_htaccess') && defined('WPS_IC_DIR')) {
                    include_once WPS_IC_DIR . 'classes/htaccess.class.php';
                }
                if (class_exists('wps_ic_htaccess')) {
                    $h = new wps_ic_htaccess();
                    $h->setWPCache(true);
                    $h->setAdvancedCache();
                }
            }
        } catch (\Throwable $e) {
        }
        wpc_auto_journal('actuate-fired', [
            'rule' => $rule, 'from' => (string) $from, 'to' => (string) $to,
            'outcome' => wpc_auto_lever_outcome_met($rule) ? 'met' : 'pending',
        ]);
    }

    // TICK — ensure crit is armed, then start a measure run.
    function wpc_auto_mode_tick_handler($attempt = 1)
    {
        try {
            if (!wpc_auto_mode_on()) { return; }
            if (get_transient('wpc_auto_tick_lock')) { return; }
            set_transient('wpc_auto_tick_lock', 1, 540);
            $st = wpc_auto_state();


            if (in_array($st['status'], ['converged', 'done', 'failed'], true)) {
                delete_transient('wpc_auto_tick_lock');
                return;
            }


            if (!empty($st['next_tick_at']) && time() < (int) $st['next_tick_at'] && $st['status'] === 'settling') {
                delete_transient('wpc_auto_tick_lock');
                wpc_auto_schedule('wpc_auto_mode_tick', max(30, (int) $st['next_tick_at'] - time()), [1]);
                return;
            }

            // Crit armed for the homepage? (The render path dispatches gen itself — one warm

            $armed = false;
            if (class_exists('wps_ic_url_key') && defined('WPS_IC_CRITICAL') && function_exists('home_url')) {
                $k = (new wps_ic_url_key())->setup(home_url('/'));
                $cf = WPS_IC_CRITICAL . $k . '/critical_desktop.css';
                $armed = @is_readable($cf) && @filesize($cf) > 5;
            }
            if (!$armed) {


                $st['arm_tries'] = (int) ($st['arm_tries'] ?? 0) + 1;
                if ($attempt >= 5 || $st['arm_tries'] > 6) {
                    $st['status'] = 'failed'; $st['msg'] = 'crit-arm-timeout';
                    wpc_auto_state_save($st);
                    wpc_auto_journal('arm-timeout', ['attempt' => $attempt, 'tries' => $st['arm_tries']]);
                    delete_transient('wpc_auto_tick_lock');
                    return;
                }
                if (function_exists('wpc_warm_url_fire')) { wpc_warm_url_fire(home_url('/')); }
                $st['status'] = 'arming'; wpc_auto_state_save($st);
                delete_transient('wpc_auto_tick_lock');
                wpc_auto_schedule('wpc_auto_mode_tick', 120, [$attempt + 1]);
                return;
            }
            $st['arm_tries'] = 0;


            if (function_exists('wpc_update_window_active') && wpc_update_window_active()) {
                wpc_auto_journal('measure-deferred', ['why' => 'update-window']);
                delete_transient('wpc_auto_tick_lock');
                wpc_auto_schedule('wpc_auto_mode_tick', 120, [$attempt]);
                return;
            }

            // Start the measure (mirrors the advisory proxy; same host, https, verified TLS).
            $opts   = get_option(WPS_IC_OPTIONS);
            $apikey = (is_array($opts) && !empty($opts['api_key'])) ? $opts['api_key'] : '';
            if ($apikey === '') {
                $st['status'] = 'failed'; $st['msg'] = 'no-api-key';
                wpc_auto_state_save($st); wpc_auto_journal('no-api-key');
                delete_transient('wpc_auto_tick_lock');
                return;
            }
            $endpoint = defined('WPS_IC_OPTIMIZE_API_URL') ? WPS_IC_OPTIMIZE_API_URL : 'https://pagespeed.zapwp.net/optimize';
            $resp = wp_remote_post($endpoint, [
                'timeout' => 20, 'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode([
                    'url' => home_url('/'), 'apikey' => $apikey,
                    'version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
                    'desktop' => 0, 'uuid' => 'auto-' . time() . '-' . wp_rand(1000, 9999),
                ]),
            ]);
            $body = is_wp_error($resp) ? [] : json_decode((string) wp_remote_retrieve_body($resp), true);
            if (is_array($body) && !empty($body['run_id']) && (($body['status'] ?? '') === 'accepted')
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,120}$/', (string) $body['run_id'])) {
                $st['run_id'] = (string) $body['run_id']; $st['status'] = 'measuring'; $st['msg'] = '';
                $st['measure_started_at'] = time();


                $st['run_id_d'] = '';
                if (apply_filters('wpc_auto_both_devices', true)) {
                    $respD = wp_remote_post($endpoint, [
                        'timeout' => 20, 'headers' => ['Content-Type' => 'application/json'],
                        'body' => wp_json_encode([
                            'url' => home_url('/'), 'apikey' => $apikey,
                            'version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
                            'desktop' => 1, 'uuid' => 'auto-' . time() . '-' . wp_rand(1000, 9999) . '-d',
                        ]),
                    ]);
                    $bodyD = is_wp_error($respD) ? [] : json_decode((string) wp_remote_retrieve_body($respD), true);
                    if (is_array($bodyD) && !empty($bodyD['run_id']) && (($bodyD['status'] ?? '') === 'accepted')
                        && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,120}$/', (string) $bodyD['run_id'])) {
                        $st['run_id_d'] = (string) $bodyD['run_id'];
                    }
                }
                wpc_auto_state_save($st);
                wpc_auto_journal('measure-start', ['run_id' => $st['run_id'], 'cycle' => $st['cycle'] + 1]);
                wpc_auto_schedule('wpc_auto_mode_poll', 35, [1]);
            } else {
                $st['status'] = 'failed';
                $st['msg'] = is_wp_error($resp) ? substr($resp->get_error_message(), 0, 80) : 'start-rejected';
                wpc_auto_state_save($st); wpc_auto_journal('measure-start-failed', ['msg' => $st['msg']]);
            }
            delete_transient('wpc_auto_tick_lock');
        } catch (\Throwable $e) {
            delete_transient('wpc_auto_tick_lock');
        }
    }
    add_action('wpc_auto_mode_tick', function ($attempt = 1) {
        $set = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        if (is_array($set) && !empty($set['developer_mode']) && $set['developer_mode'] == '1') { return; }
        wpc_auto_mode_tick_handler($attempt);
    });

    // POLL — wait for the run, then process the report.
    function wpc_auto_mode_poll_handler($attempt = 1)
    {
        try {
            if (!wpc_auto_mode_on()) { return; }
            $st = wpc_auto_state();
            if (empty($st['run_id'])) { return; }
            $base = defined('WPS_IC_OPTIMIZE_STATUS_API_URL') ? WPS_IC_OPTIMIZE_STATUS_API_URL : 'https://pagespeed.zapwp.net/optimize-status';
            $resp = wp_remote_get(add_query_arg(['run_id' => $st['run_id']], $base), ['timeout' => 20, 'sslverify' => true]);
            $d = is_wp_error($resp) ? [] : json_decode((string) wp_remote_retrieve_body($resp), true);
            $state = is_array($d) ? (string) ($d['state'] ?? '') : '';
            if ($state === 'done') {


                $reportD = null;
                if (!empty($st['run_id_d'])) {
                    $respD = wp_remote_get(add_query_arg(['run_id' => $st['run_id_d']], $base), ['timeout' => 20, 'sslverify' => true]);
                    $dD = is_wp_error($respD) ? [] : json_decode((string) wp_remote_retrieve_body($respD), true);
                    if (is_array($dD) && (string) ($dD['state'] ?? '') === 'done') {
                        $reportD = is_array($dD['report'] ?? null) ? $dD['report'] : $dD;
                    }
                }
                wpc_auto_mode_process_report(is_array($d['report'] ?? null) ? $d['report'] : $d, $reportD);
                return;
            }
            if ($state === 'failed') {
                $st['status'] = 'failed'; $st['msg'] = substr((string) ($d['step'] ?? 'measure-failed'), 0, 80);
                wpc_auto_state_save($st); wpc_auto_journal('measure-failed', ['step' => $st['msg']]);
                return;
            }


            if ($attempt >= 12
                || (!empty($st['measure_started_at']) && time() - (int) $st['measure_started_at'] > 900)) {
                $st['status'] = 'failed'; $st['msg'] = 'measure-timeout';
                wpc_auto_state_save($st); wpc_auto_journal('measure-timeout');
                return;
            }
            wpc_auto_schedule('wpc_auto_mode_poll', 30, [$attempt + 1]);
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_auto_mode_poll', 'wpc_auto_mode_poll_handler');


    function wpc_auto_mode_process_report($report, $reportD = null)
    {
        $st = wpc_auto_state();
        $st['cycle']++; $st['run_id'] = ''; $st['run_id_d'] = '';
        $score = null;
        if (is_array($report) && isset($report['scores']['mobile'])) {
            $score = (float) $report['scores']['mobile'];
        }


        $scoreD = null;
        if (is_array($reportD)) {
            if (isset($reportD['scores']['desktop'])) { $scoreD = (float) $reportD['scores']['desktop']; }
            elseif (isset($reportD['scores']['mobile'])) { $scoreD = (float) $reportD['scores']['mobile']; }
        }
        if ($st['baseline'] === null && $score !== null) { $st['baseline'] = $score; }


        $dropM = (!empty($st['flipped_last']) && $score !== null && $st['last_score'] !== null
            && $score < (float) $st['last_score'] - 2);
        $dropD = (!empty($st['flipped_last']) && $scoreD !== null && $st['last_score_d'] !== null
            && $scoreD < (float) $st['last_score_d'] - 2);
        if ($dropM || $dropD) {
            $set = get_option(WPS_IC_SETTINGS);
            $levers = wpc_auto_levers();
            foreach ($st['flipped_last'] as $f) {
                if (is_array($set) && isset($f['rule'], $levers[$f['rule']])) {
                    $lv = $levers[$f['rule']];


                    $restore = ($f['from'] === null || $f['from'] === '')
                        ? (isset($lv['off']) ? $lv['off'] : '0') : $f['from'];
                    wpc_auto_lever_set($set, $lv, $restore, isset($f['from_also']) ? $f['from_also'] : []);
                    $st['frozen'][] = $f['rule'];
                    $st['frozen_epoch'] = wpc_auto_freeze_epoch824();
                    wpc_auto_journal('auto-revert', ['rule' => $f['rule'], 'key' => $f['key'],
                        'score' => $score, 'was' => $st['last_score'],
                        'device' => $dropM ? ($dropD ? 'both' : 'mobile') : 'desktop']);
                }
            }
            if (is_array($set)) { update_option(WPS_IC_SETTINGS, $set); }
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
            }
            $st['flipped_last'] = []; $st['last_score'] = $score; $st['last_score_d'] = $scoreD;
            if ($st['cycle'] >= 3) {
                $st['status'] = 'done'; $st['msg'] = 'reverted-then-capped';
            } else {
                $st['status'] = 'settling';
                $st['next_tick_at'] = time() + (int) apply_filters('wpc_auto_cycle_floor', 180); // P2 floor
                wpc_auto_schedule('wpc_auto_mode_tick', 240, [1]);
            }
            wpc_auto_state_save($st);
            return;
        }

        // 2. PLATEAU / CAP — converged?
        $plateau = ($score !== null && $st['last_score'] !== null && abs($score - (float) $st['last_score']) < 2);

        // 3. APPLY — whitelisted, owner:auto, currently-off, not-frozen recommendations.
        $flips = [];
        $set = get_option(WPS_IC_SETTINGS);
        $levers = wpc_auto_levers();
        // Actuation reads auto_plan.actions; recommendations[] is the advisory layer and may
        // contain diagnostics that are never plan actions.
        $recs = [];
        if (is_array($report) && !empty($report['auto_plan']['actions']) && is_array($report['auto_plan']['actions'])) {
            $recs = $report['auto_plan']['actions'];
        } elseif (is_array($report) && !empty($report['recommendations']) && is_array($report['recommendations'])) {
            $recs = $report['recommendations'];
        }
        // Per-vendor third-party actions: apply the recommended default for heavy entities.
        if (function_exists('wpc_auto_apply_third_parties')) {
            wpc_auto_apply_third_parties($report);
        }
        if (is_array($report) && !empty($report['reveal_gap']['classes']) && is_array($report['reveal_gap']['classes'])) {
            $wpc_rg = array_values(array_filter(array_map('strtolower', array_map('strval', $report['reveal_gap']['classes'])), function ($c) {
                return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{2,63}$/', $c)
                    && !in_array($c, ['elementor-invisible', 'hidden', 'd-none', 'sr-only', 'screen-reader-text', 'visually-hidden', 'lazyload', 'lazyloaded', 'no-js'], true);
            }));
            if ($wpc_rg) {
                $wpc_rg_cur = (array) get_option('wpc_auto_conceal_classes', []);
                $wpc_rg_new = array_slice(array_values(array_unique(array_merge($wpc_rg_cur, $wpc_rg))), 0, 8);
                if ($wpc_rg_new !== $wpc_rg_cur) {
                    update_option('wpc_auto_conceal_classes', $wpc_rg_new, false);
                    wpc_auto_journal('reveal-gap', ['classes' => implode(',', $wpc_rg_new)]);
                }
            }
        }
        $unknownRules = [];
        foreach ($recs as $r) {
            if (!is_array($r) || strtolower((string) ($r['owner'] ?? '')) !== 'auto') { continue; }
            $rule = (string) ($r['rule'] ?? '');


            if (!isset($levers[$rule])) {
                if ($rule !== '' && !in_array($rule, $unknownRules, true)) { $unknownRules[] = $rule; }
                continue;
            }
            if (in_array($rule, (array) $st['frozen'], true)) { continue; }
            $lv = $levers[$rule];


            if (!empty($lv['gate']) && $lv['gate'] === 'font-localizer'
                && function_exists('wpc_font_localizer_present')) {
                $wpc_fl789 = wpc_font_localizer_present();
                if ($wpc_fl789 !== false) {
                    wpc_auto_journal('lever-gated', ['rule' => $rule, 'reason' => 'localizer:' . $wpc_fl789]);
                    continue;
                }
            }
            if (!empty($lv['gate']) && $lv['gate'] === 'foreign-cache'
                && function_exists('wpc_detect_foreign_page_cache')) {
                $fg = wpc_detect_foreign_page_cache();
                if ($fg !== false) {
                    wpc_auto_journal('lever-gated', ['rule' => $rule, 'reason' => 'foreign:' . $fg]);
                    continue;
                }
            }
            $key = isset($lv['key']) ? $lv['key'] : implode('.', (array) ($lv['nested'] ?? []));
            $on = $lv['on'];
            $cur = wpc_auto_lever_get($set, $lv);

            $fromAlso = [];
            if (!empty($lv['also']) && is_array($lv['also'])) {
                foreach ($lv['also'] as $ak => $onoff) {
                    $fromAlso[$ak] = (is_array($set) && isset($set[$ak])) ? $set[$ak] : null;
                }
            }
            if ((string) $cur === (string) $on && $cur !== null) {


                if (wpc_auto_lever_outcome_met($rule)) { continue; }
                $flips[] = ['rule' => $rule, 'key' => $key, 'from' => $cur, 'to' => $on, 'from_also' => $fromAlso, 'reactuate' => true];
                continue;
            }
            $flips[] = ['rule' => $rule, 'key' => $key, 'from' => $cur, 'to' => $on, 'from_also' => $fromAlso];
        }


        $flips = ($st['cycle'] >= 3) ? [] : array_slice($flips, 0, 1);
        if (!empty($flips) && is_array($set)) {
            foreach ($flips as $f) {
                wpc_auto_lever_set($set, $levers[$f['rule']], $f['to']);
                wpc_auto_journal('auto-apply', ['rule' => $f['rule'], 'key' => $f['key'],
                    'from' => (string) $f['from'], 'to' => $f['to'], 'cycle' => $st['cycle'], 'score' => $score]);
            }
            update_option(WPS_IC_SETTINGS, $set); // P5d purges HTML on the fonts flip automatically
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
            }


            foreach ($flips as $f) {
                wpc_auto_actuate_lever($f['rule'], $f['from'], $f['to']);
            }
            $st['flipped_last'] = $flips; $st['last_score'] = $score; $st['last_score_d'] = $scoreD;
            if ($st['cycle'] >= 3) {
                $st['status'] = 'done'; $st['msg'] = 'cycle-cap';
                wpc_auto_journal('cycle-cap', ['score' => $score]);
            } else {
                $st['status'] = 'settling';


                $st['next_tick_at'] = time() + (int) apply_filters('wpc_auto_cycle_floor', 180);
                wpc_auto_schedule('wpc_auto_mode_tick', 240, [1]);
            }
        } else {
            // Nothing left to flip (or plateau) — converged. Residuals stay named in the card.
            $st['flipped_last'] = []; $st['last_score'] = $score; $st['last_score_d'] = $scoreD;
            $st['status'] = 'converged';
            $st['msg'] = $plateau ? 'plateau' : 'no-applicable-levers';
            $wpc_cj = ['score' => $score, 'score_desktop' => $scoreD,
                'baseline' => $st['baseline'], 'reason' => $st['msg'], 'cycles' => $st['cycle']];
            if (!empty($unknownRules)) { $wpc_cj['unexecuted_auto_rules'] = $unknownRules; }
            wpc_auto_journal('converged', $wpc_cj);
        }
        if (!empty($unknownRules)) {
            wpc_auto_journal('lever-unknown', ['rules' => $unknownRules]);
        }
        wpc_auto_state_save($st);
    }

    // AJAX — toggle / status / revert (card UI). Same nonce + capability as the advisory proxies.
    function wpc_auto_mode_ajax_guard()
    {
        if (!current_user_can('manage_wpc_settings')
            || !wp_verify_nonce(isset($_POST['nonce']) ? (string) $_POST['nonce'] : '', 'wps_ic_nonce_action')) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
    }
    /**
     * Arms the Auto loop on sites where it is on by default (no toggle click ever happens on a
     * link-and-go install). One-shot latch; respects the explicit-off option, the connected
     * gate, and developer mode. Journals its own arming for the status timeline.
     */
    function wpc_auto_bootstrap()
    {
        try {
            if (!function_exists('wpc_auto_mode_on') || !wpc_auto_mode_on()) { return; }
            if (get_option('wpc_auto_bootstrapped') === '1') { return; }
            $set = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
            if (is_array($set) && !empty($set['developer_mode']) && $set['developer_mode'] == '1') { return; }
            if (function_exists('wp_next_scheduled') && wp_next_scheduled('wpc_auto_mode_tick')) {
                update_option('wpc_auto_bootstrapped', '1', false);
                return;
            }
            $st = wpc_auto_state();
            if (!in_array($st['status'], ['idle', ''], true)) { return; }
            update_option('wpc_auto_bootstrapped', '1', false);
            $st['status'] = 'starting'; $st['cycle'] = 0; $st['run_id'] = '';
            $st['flipped_last'] = []; $st['msg'] = ''; $st['arm_tries'] = 0;
            $st['next_tick_at'] = 0; $st['measure_started_at'] = 0; $st['run_id_d'] = '';
            wpc_auto_state_save($st);
            wpc_auto_journal('enabled-default');
            wpc_auto_schedule('wpc_auto_mode_tick', 60, [1]);
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_auto_bootstrap', 40);
    add_action('admin_init', 'wpc_artifact_refresh_on_update', 35);
    add_action('wpc_lcp_repull', 'wpc_auto_bootstrap', 8);
    add_action('wpc_autopurge_sweep', 'wpc_auto_bootstrap', 8);





    function wpc_auto_mode_apply_set($on)
    {
        $on = (bool) $on;
        update_option('wpc_auto_mode', $on ? '1' : '0', false);
        if ($on) {
            $st = wpc_auto_state();
            $st['status'] = 'starting'; $st['cycle'] = 0; $st['run_id'] = '';
            $st['flipped_last'] = []; $st['msg'] = '';
            $st['arm_tries'] = 0; $st['next_tick_at'] = 0; $st['measure_started_at'] = 0; $st['run_id_d'] = '';
            wpc_auto_state_save($st);
            delete_option('wpc_auto_r3_actuated');
            wpc_auto_journal('enabled');
            wpc_auto_schedule('wpc_auto_mode_tick', 5, [1]);
            wpc_spawn_cron();
        } else {
            wpc_auto_journal('disabled');
            if (function_exists('wp_unschedule_hook')) {
                wp_unschedule_hook('wpc_auto_mode_tick');
                wp_unschedule_hook('wpc_auto_mode_poll');
            }
        }
        return ['on' => $on, 'state' => wpc_auto_state()];
    }


    function wpc_auto_mode_build_status()
    {


        if (function_exists('wpc_auto_chain_maybe')) { wpc_auto_chain_maybe('status-poll'); }


        $wpc_r2s123 = function_exists('wpc_r2_probation_state') ? wpc_r2_probation_state() : [];
        if (!empty($wpc_r2s123['phase']) && function_exists('wpc_r2_kick')) { wpc_r2_kick(); }
        return [
            'on' => wpc_auto_mode_on(),
            'state' => wpc_auto_state(),
            'r2' => [
                'phase' => isset($wpc_r2s123['phase']) ? $wpc_r2s123['phase'] : '',
                'since' => isset($wpc_r2s123['phase_at']) ? (int) $wpc_r2s123['phase_at'] : 0,
                'base_m' => isset($wpc_r2s123['base_m']) ? $wpc_r2s123['base_m'] : null,
                'base_d' => isset($wpc_r2s123['base_d']) ? $wpc_r2s123['base_d'] : null,
                'post_m' => isset($wpc_r2s123['post_m']) ? $wpc_r2s123['post_m'] : null,
                'verified' => get_option('wpc_r2_verified'),
            ],
            'journal' => array_reverse(array_slice((array) get_option('wpc_auto_mode_journal', []), -12)),
        ];
    }


    function wpc_auto_mode_do_revert()
    {
        $j = (array) get_option('wpc_auto_mode_journal', []);
        $set = get_option(WPS_IC_SETTINGS);
        $n = 0;
        // Journal is oldest-first; keep the FIRST 'from' per key (the pre-Auto value) + its rule.
        $orig = [];
        foreach ($j as $e) {
            if (($e['event'] ?? '') === 'auto-apply' && isset($e['key']) && !array_key_exists($e['key'], $orig)) {
                $orig[$e['key']] = ['from' => $e['from'] ?? '', 'rule' => (string) ($e['rule'] ?? '')];
            }
        }
        if (is_array($set)) {
            $levers = wpc_auto_levers();
            foreach ($orig as $k => $o) {


                $restore = ($o['from'] === '' || $o['from'] === null)
                    ? (isset($levers[$o['rule']]['off']) ? $levers[$o['rule']]['off'] : '0')
                    : $o['from'];

                if (isset($levers[$o['rule']]) && function_exists('wpc_auto_lever_set')) {
                    $lv = $levers[$o['rule']];
                    $alsoOff = [];
                    if (!empty($lv['also']) && is_array($lv['also'])) {
                        foreach ($lv['also'] as $ak => $onoff) { $alsoOff[$ak] = $onoff[1]; }
                    }
                    wpc_auto_lever_set($set, $lv, $restore, $alsoOff);
                } else {
                    $set[$k] = $restore;
                }
                $n++;
            }
            update_option(WPS_IC_SETTINGS, $set);
        }
        update_option('wpc_auto_mode', '0', false);
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
        }
        wpc_auto_journal('manual-revert', ['restored' => $n]);
        return ['restored' => $n];
    }


    add_action('wp_ajax_wpc_auto_mode_set', function () {
        wpc_auto_mode_ajax_guard();
        $on = isset($_POST['on']) && $_POST['on'] === '1';
        if (function_exists('wpc_agency_forward_json')) {
            wpc_agency_forward_json('autoModeSet', ['on' => $on ? '1' : '0']);
        }
        wp_send_json_success(wpc_auto_mode_apply_set($on));
    });

    add_action('wp_ajax_wpc_auto_mode_status', function () {
        wpc_auto_mode_ajax_guard();
        if (function_exists('wpc_agency_forward_json')) {
            wpc_agency_forward_json('autoModeStatus');
        }
        wp_send_json_success(wpc_auto_mode_build_status());
    });

    add_action('wp_ajax_wpc_auto_mode_revert', function () {
        wpc_auto_mode_ajax_guard();
        if (function_exists('wpc_agency_forward_json')) {
            wpc_agency_forward_json('autoModeRevert');
        }
        wp_send_json_success(wpc_auto_mode_do_revert());
    });


    function wpc_auto_chain_maybe($src)
    {
        try {
            if (!function_exists('wpc_auto_mode_on') || !wpc_auto_mode_on()) { return; }
            $st = wpc_auto_state();
            $what = '';
            if ($st['status'] === 'measuring' && !empty($st['run_id'])) {

                if (empty($st['measure_started_at']) || time() - (int) $st['measure_started_at'] >= 30) {
                    $what = 'poll';
                }
            } elseif (in_array($st['status'], ['settling', 'starting', 'arming'], true)) {
                if (empty($st['next_tick_at']) || time() >= (int) $st['next_tick_at']) {
                    $what = 'tick';
                }
            }
            if ($what === '') { return; }
            if (get_transient('wpc_auto_chain_throttle')) { return; }
            set_transient('wpc_auto_chain_throttle', 1, 25);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('auto-chain', '', '', ['src' => (string) $src, 'w' => $what]);
            }
            wpc_auto_kick($what);
        } catch (\Throwable $e) {
        }
    }


    function wpc_auto_kick_sig()
    {
        $opts = get_option(WPS_IC_OPTIONS);
        $key = (is_array($opts) && !empty($opts['api_key'])) ? (string) $opts['api_key'] : '';
        return $key === '' ? '' : substr(hash_hmac('sha256', 'wpc_auto_kick', $key), 0, 20);
    }
    function wpc_auto_kick($what)
    {
        $wpc_sig = wpc_auto_kick_sig();
        if ($wpc_sig === '') { return; }
        $q = '?action=wpc_auto_kick&w=' . ($what === 'poll' ? 'poll' : 'tick') . '&k=' . rawurlencode($wpc_sig);
        if (function_exists('wp_remote_get') && function_exists('admin_url')) {
            wp_remote_get(admin_url('admin-ajax.php') . $q,
                ['blocking' => false, 'timeout' => 2, 'headers' => ['X-WPC-Cache-Warm' => '1']]);
        }

        if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')
            && function_exists('admin_url')) {
            $u = admin_url('admin-ajax.php');
            $p = parse_url($u);
            if (!empty($p['host'])) {
                $https = (isset($p['scheme']) && $p['scheme'] === 'https');
                $fp = wps_ic_ajax::wpc_loopback_open_socket($p['host'], $https ? 443 : 80, $https, 0.3);
                if ($fp) {
                    $path = (isset($p['path']) ? $p['path'] : '/wp-admin/admin-ajax.php') . $q;
                    fwrite($fp, "GET " . $path . " HTTP/1.1\r\nHost: " . $p['host']
                        . "\r\nX-WPC-Cache-Warm: 1\r\nConnection: Close\r\n\r\n");
                    fclose($fp);
                }
            }
        }
    }


    function wpc_auto_kick_receiver()
    {

        $wpc_sig = wpc_auto_kick_sig();
        if ($wpc_sig === '' || !hash_equals($wpc_sig, isset($_GET['k']) ? (string) $_GET['k'] : '')) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }


        if (isset($_GET['w']) && $_GET['w'] === 'r2') {
            if (get_transient('wpc_r2_kick_lock')) {
                wp_send_json_success(['skip' => 'throttled']);
            }
            set_transient('wpc_r2_kick_lock', 1, 10);
            if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
            if (function_exists('wpc_r2_probation_tick_handler')) { wpc_r2_probation_tick_handler(); }
            wp_send_json_success(['ran' => 'r2']);
        }
        if (!function_exists('wpc_auto_mode_on') || !wpc_auto_mode_on()) {
            wp_send_json_success(['skip' => 'off']);
        }


        $st = wpc_auto_state();
        if (!in_array($st['status'], ['starting', 'arming', 'settling', 'measuring'], true)) {
            wp_send_json_success(['skip' => 'no-pending-cycle']);
        }
        if (get_transient('wpc_auto_kick_lock')) {
            wp_send_json_success(['skip' => 'throttled']);
        }
        set_transient('wpc_auto_kick_lock', 1, 20);
        if (function_exists('ignore_user_abort')) { @ignore_user_abort(true); }
        $w = (isset($_GET['w']) && $_GET['w'] === 'poll') ? 'poll' : 'tick';
        if ($w === 'poll') { wpc_auto_mode_poll_handler(1); } else { wpc_auto_mode_tick_handler(1); }
        wp_send_json_success(['ran' => $w]);
    }
    add_action('wp_ajax_wpc_auto_kick', 'wpc_auto_kick_receiver');
    add_action('wp_ajax_nopriv_wpc_auto_kick', 'wpc_auto_kick_receiver');


    add_action('admin_init', function () {
        wpc_auto_chain_maybe('admin');
    });
}


if (!function_exists('wpc_sitechange_purge')) {
    function wpc_sitechange_purge($why)
    {
        try {


            static $wpc_scp75 = false;
            if ($wpc_scp75) {
                return;
            }
            $wpc_scp75 = true;
            // ALWAYS deferred: menu/widget/theme saves never pay the wipe inline;
            // event dedup coalesces bursts into one purge
            if (!wp_next_scheduled('wpc_sitechange_trailing')) {
                wp_schedule_single_event(time() + 8, 'wpc_sitechange_trailing');
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('sitechange-purge-deferred', '', '', ['why' => (string) $why]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_sitechange_trailing', function () {
        delete_transient('wpc_sitechange_win');
        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('sitechange-purge', '', '', ['why' => 'trailing-coalesced']);
            }
        }
    });
    // v7.10.567 — crit-mode flip. ALL html layers, not just the local mirror: the edge is
    // holding copies keyed under the previous device model, so a local-only wipe would leave
    // CF serving the old shape until its own TTL expires.
    add_action('wpc_critmode_purge', function () {
        if (function_exists('wpc_r2_purge_html_layers')) {
            wpc_r2_purge_html_layers();
        } elseif (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
            wps_ic_cache::removeHtmlCacheFiles('all');
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('crit-mode-purge', '', '', ['why' => 'mode-flip']);
        }
    });
    add_action('switch_theme',            function () { wpc_sitechange_purge('theme-switch'); });
    add_action('customize_save_after',    function () { wpc_sitechange_purge('customizer'); });
    add_action('wp_update_nav_menu',      function () { wpc_sitechange_purge('menu'); });
    add_action('deactivated_plugin',      function () { wpc_sitechange_purge('plugin-deactivated'); });
    // A plugin/theme UPDATE rewrites the very asset URLs and markup our cached HTML points
    // at (builder CSS files re-hash, image markup changes) — deactivation purged, activation
    // purged, and the update in between did not, so a stale copy kept referencing files that
    // no longer exist. vincire.nl receipt: images vanished after a Kadence update and came
    // back only when the plugin was toggled, which is the purge this hook was missing.
    add_action('upgrader_process_complete', function () { wpc_sitechange_purge('plugin-updated'); });
    add_action('update_option_sidebars_widgets', function () { wpc_sitechange_purge('widgets'); });
    add_action('edited_term',             function () { wpc_sitechange_purge('term-edited'); });
}


add_action('update_option_' . (defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings'), function ($old, $new) {
    try {
        $keys = ['combined-crit', 'minimal-mobile-css'];
        $changed = false;
        foreach ($keys as $k) {
            $o = (is_array($old) && isset($old[$k])) ? $old[$k] : null;
            $n = (is_array($new) && isset($new[$k])) ? $new[$k] : null;
            if ($o !== $n) { $changed = true; break; }
        }
        if (!$changed) { return; }
        // v7.10.567 — A MODE CHANGE MUST INVALIDATE THE COPIES MADE UNDER THE OLD MODE.
        // Either key flips the emitted HTML between device-universal (one combined blob) and
        // device-divergent, and the CF rule re-patch below changes the edge's key model — but
        // nothing invalidated what is already stored under the old one. With max-age=300 +
        // stale-while-revalidate=86400 the old shape can serve for a day, and inside that window
        // the edge can hand a mobile visitor desktop-shaped HTML. Every other mode flip already
        // purges (format toggles core:5125, fonts warm:7555, used-css warm:9414); this was the
        // one that re-patched the rules and left the payload. Deferred + coalesced, so a Save
        // never pays the wipe inline — same contract as wpc_sitechange_purge().
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_critmode_purge')) {
            wp_schedule_single_event(time() + 8, 'wpc_critmode_purge');
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('crit-mode-purge-deferred', '', '', [
                'combined-crit'     => (string) ((is_array($new) && isset($new['combined-crit'])) ? $new['combined-crit'] : ''),
                'minimal-mobile-css' => (string) ((is_array($new) && isset($new['minimal-mobile-css'])) ? $new['minimal-mobile-css'] : ''),
            ]);
        }
        $cf = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
        if (!is_array($cf) || empty($cf['token']) || empty($cf['zone'])) { return; }
        if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
            @include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
        }
        if (class_exists('WPC_CloudflareAPI')) {
            $sdk = new WPC_CloudflareAPI($cf['token']);
            if (method_exists($sdk, 'patchHtmlRulesRespectOrigin')) {
                // Compute combined-mode from the FRESH ($new) settings — mid-save, the statics

                $combined = class_exists('wps_rewriteLogic')
                    && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on')
                    && wps_rewriteLogic::wpc_combined_crit_on(is_array($new) ? $new : null);
                $sdk->patchHtmlRulesRespectOrigin($cf['zone'], $combined);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('cf-rules-repatch', '', '', ['trigger' => 'settings-save', 'combined' => $combined ? 1 : 0]);
                }
            }
        }
    } catch (\Throwable $e) {
    }
}, 10, 2);


// Connect/refresh no longer fail when OUR keys server answers slowly (the "connected on hard


if (!function_exists('wpc_cf_finish_cname')) {
    function wpc_cf_finish_cname($attempt = 1)
    {
        try {
            if (!get_option('wpc_cf_setup_pending')) { return; }
            $cf = get_option(WPS_IC_CF);
            if (empty($cf['token']) || empty($cf['zone'])) { delete_option('wpc_cf_setup_pending'); return; }
            if (!class_exists('wps_ic_requests') && defined('WPS_IC_DIR')) { @include_once WPS_IC_DIR . 'classes/requests.class.php'; }
            if (!class_exists('wps_ic_requests') || !defined('WPS_IC_KEYSURL')) { return; }
            $opts     = get_option(WPS_IC_OPTIONS);
            $apikey   = (is_array($opts) && !empty($opts['api_key'])) ? $opts['api_key'] : '';
            $siteUrl  = site_url();
            $zoneName = str_replace(['http://', 'https://', '/'], '', $siteUrl);
            $requests = new wps_ic_requests();
            $body = $requests->GET(WPS_IC_KEYSURL, ['action' => 'setupCF', 'token' => $cf['token'], 'zone' => $cf['zone'], 'siteUrl' => $siteUrl, 'zoneName' => $cf['zoneName'] ?? $zoneName, 'staticAssets' => '1', 'htmlCache' => 'all', 'cdn' => '1', 'apikey' => $apikey, 'time' => microtime(true)], ['timeout' => 25]);
            $cfCname = (!empty($body) && isset($body->data)) ? (string) (((array) $body->data)['cfName'] ?? '') : '';
            if ($cfCname !== '') {
                $prev = (string) get_option(WPS_IC_CF_CNAME);
                $cf['custom_cname'] = $cfCname;
                update_option(WPS_IC_CF, $cf);
                update_option(WPS_IC_CF_CNAME, $cfCname);
                if ($cfCname !== $prev) { update_option('wpc_cf_cname_verified', '0', false); }
                delete_option('wpc_cf_setup_pending');
                if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('cf-setup-finished-bg', '', '', ['attempt' => (int) $attempt]); }
                return;
            }
            if ((int) $attempt < 6) {
                if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_cf_setup_retry', [(int) $attempt + 1])) {
                    wp_schedule_single_event(time() + min(600, 90 * (int) $attempt), 'wpc_cf_setup_retry', [(int) $attempt + 1]);
                }
            } elseif (function_exists('wpc_cache_first_log')) {

                wpc_cache_first_log('cf-setup-bg-giveup', '', '', ['attempts' => (int) $attempt]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_cf_setup_retry', 'wpc_cf_finish_cname');
}


// This handler can only: read /status for the SAME stashed uuid → consume fonts → purge this
// page. It cannot dispatch, kick, or write uuid.txt — re-generation is structurally impossible.
if (!function_exists('wpc_combine_fonts_fetch_handler')) {
    function wpc_combine_fonts_fetch_handler($url_key = '', $attempt = 1)
    {
        if (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($url_key)) { return; }
        try {
            if (!defined('WPS_IC_CRITICAL') || $url_key === '') { return; }
            $dir = rtrim(WPS_IC_CRITICAL, '/') . '/' . $url_key . '/';
            if (@is_readable($dir . 'font-subsets.css')) { return; }
            $fonts = [];
            $fu = @is_readable($dir . 'fonts_url.txt') ? trim((string) @file_get_contents($dir . 'fonts_url.txt')) : '';
            if ($fu === '' && @is_readable($dir . 'uuid.txt') && defined('WPS_IC_CRITICAL_API_URL')) {
                $uu = preg_replace('/[^A-Za-z0-9-]/', '', trim((string) @file_get_contents($dir . 'uuid.txt')));
                if ($uu !== '') {
                    $rs = wp_remote_get(str_replace('/generate', '/status', WPS_IC_CRITICAL_API_URL) . '?uuid=' . urlencode($uu), ['timeout' => 8]);
                    if (!is_wp_error($rs) && (int) wp_remote_retrieve_response_code($rs) === 200) {
                        $rd = json_decode((string) wp_remote_retrieve_body($rs), true);
                        if (is_array($rd) && !empty($rd['fonts_url'])) {
                            $fu = trim((string) $rd['fonts_url']);
                            wpc_crit_meta_write($dir . 'fonts_url.txt', $fu);
                        } elseif (is_array($rd) && !empty($rd['fonts']) && is_array($rd['fonts'])) {
                            $fonts = $rd['fonts'];
                        }
                    }
                }
            }
            if (empty($fonts) && $fu !== '') {
                $ff = wp_remote_get($fu, ['timeout' => 8]);
                $wpc_fc624 = is_wp_error($ff) ? 0 : (int) wp_remote_retrieve_response_code($ff);
                if ($wpc_fc624 === 200) {
                    $fj = json_decode((string) wp_remote_retrieve_body($ff), true);
                    if (is_array($fj)) { $fonts = (!empty($fj['fonts']) && is_array($fj['fonts'])) ? $fj['fonts'] : $fj; }
                } elseif ($wpc_fc624 === 404 || $wpc_fc624 === 410) {
                    // v7.10.624 — a DEAD pointer must reach a terminal state: the scheduled
                    // path caps at 4 attempts but the admin-visit heal re-enters at attempt 1
                    // every 10min forever (QM receipt: 3 x 404 per admin load). Count 404s
                    // keyed to THIS url; at 4 the pointer file is deleted — the heal glob
                    // stops selecting the dir, and any fresh generation rewrites the pointer
                    // (new url => counter resets by key).
                    $wpc_4f624 = $dir . 'fonts_404.txt';
                    $wpc_4s624 = @is_readable($wpc_4f624) ? explode(':', trim((string) @file_get_contents($wpc_4f624))) : [0, ''];
                    $wpc_4n624 = (substr((string) ($wpc_4s624[1] ?? ''), 0, 8) === substr(md5($fu), 0, 8))
                        ? (int) ($wpc_4s624[0] ?? 0) + 1 : 1;
                    @file_put_contents($wpc_4f624, $wpc_4n624 . ':' . substr(md5($fu), 0, 8));
                    if ($wpc_4n624 >= 4) {
                        @unlink($dir . 'fonts_url.txt');
                        @unlink($wpc_4f624);
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('fonts-pointer-dead', (string) $url_key, '', ['url' => substr($fu, -48)]);
                        }
                        return;
                    }
                }
            }
            if (!empty($fonts) && function_exists('wpc_consume_fonts_artifact')) {
                wpc_consume_fonts_artifact($fonts, $url_key);
                if (@is_readable($dir . 'font-subsets.css')) {

                    if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                        wps_ic_cache_integrations::purgeUrlHtml($url_key, '', ['context' => 'fonts-late-land']);
                    }
                    return;
                }
            }

            if ((int) $attempt < 4 && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_combine_fonts_fetch', [$url_key, (int) $attempt + 1])) {
                wpc_pl_sched(time() + 60, 'wpc_combine_fonts_fetch', [$url_key, (int) $attempt + 1]);
            } elseif ((int) $attempt >= 4 && function_exists('wpc_cache_first_log')) {
                // v7.10.397: a silent terminal state made "fonts never landed" a mystery —
                // the pointer heal below owns recovery, this line owns observability.
                wpc_cache_first_log('fonts-fetch-exhausted', (string) $url_key, '', ['attempts' => (int) $attempt]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_combine_fonts_fetch', 'wpc_combine_fonts_fetch_handler', 10, 2);
}

if (!function_exists('wpc_fonts_pointer_heal')) {
    /**
     * v7.10.397: fonts consumption was cron-scheduled only — on a loaded box that sheds
     * cron (pressure-deferred), a landed fonts_url pointer sat unconsumed forever (busy:
     * pointer present 40+ min, subsets absent, no fonts-landed journal). Admin visits are
     * the one traffic class that always happens: consume inline, throttled, cron-free.
     */
    function wpc_fonts_pointer_heal()
    {
        try {
            if (!defined('WPS_IC_CRITICAL') || !function_exists('wpc_combine_fonts_fetch_handler')) { return; }
            // v7.10.401: backfill the metrics-present signal from any already-landed
            // font-metrics.json, so the swap->optional CLS fix applies to sites whose
            // metrics landed before this release (no fresh consume needed).
            if (!get_option('wpc_font_metrics_present')
                && (array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/font-metrics.json')) {
                foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/font-metrics.json') as $wpc_fm401) {
                    if ((int) @filesize($wpc_fm401) > 2) { update_option('wpc_font_metrics_present', 1, false); break; }
                }
            }
            if (get_transient('wpc_fonts_heal_tick')) { return; }
            set_transient('wpc_fonts_heal_tick', 1, 600);
            $wpc_fh397 = 0;
            foreach ((array) @glob(rtrim(WPS_IC_CRITICAL, '/') . '/*/fonts_url.txt') as $wpc_fp397) {
                if ($wpc_fh397 >= 3) { break; }
                $wpc_fd397 = dirname($wpc_fp397);
                if (@is_readable($wpc_fd397 . '/font-subsets.css')) { continue; }
                wpc_combine_fonts_fetch_handler(basename($wpc_fd397), 1);
                $wpc_fh397++;
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_fonts_pointer_heal', 33);
}


if (!function_exists('wpc_update_window_active')) {
    function wpc_update_window_active()
    {
        $until = (int) get_option('wpc_update_window_until', 0);
        return $until > 0 && time() < $until;
    }

    /**
     * Presence is not currency: pointer files from old generations satisfy existence checks and
     * block their own modernization. On every plugin version change, clear the POINTER chain for
     * the homepage key (artifacts stay serving) and re-arm the resolver so the current-era
     * artifact set lands within one cycle.
     */
    function wpc_artifact_refresh_on_update()
    {
        try {
            $v = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '';
            if ($v === '' || get_option('wpc_artifact_refresh_v') === $v) { return; }
            update_option('wpc_artifact_refresh_v', $v, false);
            // A failed-era selftest gate must not delay the crown after new code lands —
            // each version change earns a fresh attempt.
            delete_transient('wpc_cf_selftest_gate');
            // A background render pays the post-update compile/setup cost within seconds,
            // so the first human arrival never does.
            if (function_exists('wpc_warm_url_queue')) {
                wpc_warm_url_queue(home_url('/'), 'post-update-prime');
            }
            if (!defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key') || !function_exists('home_url')) { return; }
            $hk = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
            if ($hk === '') { return; }
            $d = rtrim(WPS_IC_CRITICAL, '/') . '/' . $hk . '/';
            foreach (['lcp_url.txt', 'delay_url.txt', 'fonts_url.txt', 'used_css_sheets_url.txt',
                'used_css_url.txt', 'used_css_mobile_url.txt', 'used_css_desktop_url.txt', 'uuid.txt'] as $f) {
                // v7.10.682 — uuid.txt is the PENDING-GEN pointer, not an old-era locator: deleting
                // it under a live dispatch orphans the in-flight generation collection lane (the
                // update-day outage shape). An old-era uuid still clears (dispatch stamp aged out).
                if ($f === 'uuid.txt'
                    && (time() - (int) @file_get_contents($d . 'dispatch_ts.txt')) < 900) {
                    continue;
                }
                @unlink($d . $f);
            }
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_lcp_repull', [$hk, 1])) {
                wpc_pl_sched(time() + 90, 'wpc_lcp_repull', [$hk, 1]);
            }
            if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('artifact-refresh', $hk, '', ['v' => $v]); }
        } catch (\Throwable $e) {
        }
    }

    function wpc_update_window_open()
    {
        if (function_exists('wpc_artifact_refresh_on_update')) { wpc_artifact_refresh_on_update(); }
        $secs  = max(60, (int) apply_filters('wpc_update_window_secs', 180));
        $until = time() + $secs;
        update_option('wpc_update_window_until', $until, false);
        if (function_exists('wp_schedule_single_event') && !wp_next_scheduled('wpc_update_window_end')) {
            wp_schedule_single_event($until + 5, 'wpc_update_window_end');
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('update-window-open', '', '', ['secs' => $secs]);
        }
    }

    function wpc_update_window_end_handler()
    {
        $until = (int) get_option('wpc_update_window_until', 0);
        if ($until <= 0) {
            return;
        }
        if (time() < $until) {
            return;
        }
        // Phase-0 load gate: a purge-all (→ cold origin → visitor-render herd) + warm
        // burst on an already-hot box is self-inflicted (busy: 2 cores @ load 35 → 503).
        // Defer the whole thing until pressure clears; the flag stays set so the
        // rescheduled run re-attempts. Lazy-warm on real visits covers any gap.
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            if (function_exists('wpc_pl_sched') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_update_window_end')) {
                wpc_pl_sched(time() + 120, 'wpc_update_window_end');
            }
            return;
        }
        delete_option('wpc_update_window_until');
        try {

            // (crit-tree maps survive either way; homepage always present).
            $wpc_warm_list114 = [];
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'wpcHtmlUrlList')) {
                // World-class warming: eagerly warm the HOMEPAGE only (highest-value,
                // highest-traffic — the one page where a cold first-visit hurts). Every
                // other page re-optimizes lazily on its next visit (free, spread over time,
                // no cold-cache herd). On a slow-origin box each render is seconds, so the
                // old top-6 was 6× the cost for pages that get a handful of visits/day.
                // Filterable up for fast boxes; the durable version is service-side warm.
                $wpc_warm_list114 = array_slice((array) wps_ic_cache::wpcHtmlUrlList(12), 0, max(1, (int) apply_filters('wpc_update_window_warm_max', 1)));
            }
            if (empty($wpc_warm_list114)) {
                $wpc_warm_list114 = [home_url('/')];
            }
            // THE purge-at-END: levers are live now — rebuild every layer from optimized renders.
            if (class_exists('wps_ic_cache_integrations')) {
                wps_ic_cache_integrations::purgeAll(false, true, false, false, true);
            }


            if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
            if (defined('LSCWP_V')) { do_action('litespeed_purge_all'); }
            if (defined('WPHB_VERSION')) { do_action('wphb_clear_page_cache'); }
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'purgeBreeze')) { wps_ic_cache::purgeBreeze(); }
            // Edge purge DELAYED: dropping every CF copy while the origin is still cold sends
            // all live traffic into a concurrent-render herd (receipted: 11x 5-41s renders).
            // Local layers purge now; the warms below rebuild statics; CF drops in ~90s onto
            // a warm origin. Old edge copies stay styled meanwhile — acceptable staleness.
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_cf_allhtml_delayed')) {
                wp_schedule_single_event(time() + 90, 'wpc_cf_allhtml_delayed');
            } elseif (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                wps_ic_cache::cfPurgeAllHtml(true, true);
            }


            if (function_exists('wpc_warm_url_queue')) {
                foreach ($wpc_warm_list114 as $wpc_wu114) {
                    wpc_warm_url_queue((string) $wpc_wu114, 'update-window-end');
                }
            }

            // (once per version; the once-guard inside makes this free on every later window).

            if (function_exists('wpc_fd_auto_migrate')) {
                wpc_fd_auto_migrate();
            }
            if (function_exists('wpc_fontdisplay_rebake_once')) {
                wpc_fontdisplay_rebake_once();
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('update-window-end-purge', '', '', ['warmed' => count($wpc_warm_list114)]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_update_window_end', 'wpc_update_window_end_handler');
    add_action('wpc_cf_allhtml_delayed', function () {
        try {
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                wps_ic_cache::cfPurgeAllHtml(true, true);
            }
        } catch (\Throwable $e) {
        }
    });


    function wpc_render_armed_for_cache($buffer)
    {
        try {
            if (!is_string($buffer) || $buffer === '') { return false; }
            if (!empty($_GET['criticalCombine'])) { return false; }
            if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) { return false; }
            $set = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
            $critOn = is_array($set) && !empty($set['critical']['css']) && $set['critical']['css'] == '1';
            if ($critOn && strpos($buffer, 'id="wpc-critical-css"') === false && strpos($buffer, "id='wpc-critical-css'") === false) {
                // SELF-CONSISTENT crit-less render (deferral stood down / was restored to
                // blocking): the page is complete and safe to cache — crit-less periods
                // must serve HITs, not no-store convoys. Only the INCONSISTENT state
                // (deferred <link> sheets without crit) stays uncacheable. Tag-scoped:
                // the loader's inline JS carries marker strings as selectors.
                if (!preg_match('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:late-|mobile-)?stylesheet["\']/i', $buffer)) {
                    return true;
                }
                return false;
            }
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    function wpc_update_window_headers()
    {
        try {
            $until = (int) get_option('wpc_update_window_until', 0);
            if ($until <= 0) {
                return;
            }
            if (time() < $until) {


                if (!headers_sent() && !(function_exists('is_admin') && is_admin())) {
                    header('X-WPC-Update-Window: 1');
                }
                return;
            }

            register_shutdown_function(function () {
                if (function_exists('fastcgi_finish_request')) {
                    @fastcgi_finish_request();
                }
                wpc_update_window_end_handler();
            });
        } catch (\Throwable $e) {
        }
    }
    add_action('send_headers', 'wpc_update_window_headers', 3);
}


if (!function_exists('wpc_cf_url_tag')) {

    function wpc_cf_url_tag($url)
    {
        $p    = parse_url((string) $url);
        $host = strtolower((string) (isset($p['host']) ? $p['host'] : ''));
        $host = preg_replace('/:\d+$/', '', $host);
        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }
        $path = (isset($p['path']) && $p['path'] !== '') ? (string) $p['path'] : '/';
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path .= '/';
        }
        $wpc_tag = 'wpc-u-' . substr(md5($host . $path), 0, 20);
        // The tag is a truncated md5 and cannot be reversed, so the purge ledger could only
        // ever report the hash. Remember what we hashed — this is the ONLY point that knows
        // both — and the ledger resolves the tag back to a readable URL.
        if (function_exists('wpc_purge_tag_remember')) {
            wpc_purge_tag_remember($wpc_tag, $host . $path);
        }
        return $wpc_tag;
    }
}

if (!function_exists('wpc_cf_emit_html_tags')) {
    function wpc_cf_emit_html_tags()
    {
        try {
            if (headers_sent() || (function_exists('is_admin') && is_admin())) {
                return;
            }
            $m = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
            if ($m !== 'GET' && $m !== 'HEAD') {
                return;
            }
            $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
            $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

            if (preg_match('#^/(wp-admin(/|$)|wp-json/|wp-login\.php|wp-cron\.php|xmlrpc\.php)#', $path)) {
                return;
            }

            if (preg_match('#\.(?:css|js|mjs|json|map|jpe?g|png|gif|webp|avif|svg|ico|ttf|otf|woff2?|eot|mp4|webm|ogg|pdf|zip|gz|txt)$#i', $path)) {
                return;
            }
            $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
            if ($host === '') {
                return;
            }
            header('Cache-Tag: wpc-html,' . wpc_cf_url_tag('https://' . $host . $path), false);
        } catch (\Throwable $e) {
        }
    }
    add_action('send_headers', 'wpc_cf_emit_html_tags', 4);
}


// Doctor covers diagnosis.
if (!function_exists('wpc_cf_wait_evicted')) {
    /**
     * Wait for a purge to actually land, rather than guessing how long it takes (v7.10.575).
     *
     * Every eviction check in this plugin used a fixed 2-3s sleep and one probe. Cloudflare
     * allows a purge up to ~30s to reach a given PoP, so on any zone slower than the guess the
     * check read "still HIT" and concluded the purge had failed. Measured directly on the
     * flagship: the purge DID evict and returned a fresh render, but a probe 3s later at another
     * colo still saw the old object aging. That one wrong assumption is why the verified-purge
     * crown, and with it tiered caching, has been unreachable on most zones.
     *
     * Polls until the edge stops saying HIT, or the window closes. Returns the last status; ''
     * means the probe itself errored and is never treated as proof of eviction by callers.
     */
    function wpc_cf_wait_evicted($probe, $tries = 8, $gap = 3)
    {
        $last = '';
        for ($i = 0; $i < max(1, (int) $tries); $i++) {
            wpc_diag_sleep($gap, 'cf-wait-evicted');
            $last = $probe();
            if ($last !== '' && $last !== 'HIT') {
                return $last;
            }
        }
        return $last;
    }
}

if (!function_exists('wpc_cf_selftest_handler')) {
    function wpc_cf_selftest_handler()
    {
        try {
            if (function_exists('wpc_bg_slot_take') && !wpc_bg_slot_take('cf-selftest')) {
                if (function_exists('wp_schedule_single_event') && !wp_next_scheduled('wpc_cf_selftest')) {
                    wp_schedule_single_event(time() + 300, 'wpc_cf_selftest');
                }
                return;
            }
            $cf = get_option(WPS_IC_CF);
            if (empty($cf['token']) || empty($cf['zone']) || !function_exists('wpc_cf_url_tag')) {
                return;
            }
            if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR')) {
                @include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
            }
            if (!class_exists('WPC_CloudflareAPI')) {
                return;
            }
            $sdk = new WPC_CloudflareAPI($cf['token']);
            if (!method_exists($sdk, 'purgeFiles')) {
                return;
            }
            // Test the PRODUCTION OBJECT: a query-param probe URL renders no-store by design
            // (unknown-param poisoning defense), so it can never HIT and the test always
            // bailed. The homepage is the real cached object — prime it, purge it, verify
            // eviction, then immediately re-warm so visitors never meet the test's MISS.
            $url   = home_url('/');
            $probe = function () use ($url) {
                $r = wp_remote_get($url, [
                    'timeout' => 4, 'redirection' => 0,
                    'headers' => ['Accept' => 'text/html'],
                    'user-agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36',
                ]);
                return is_wp_error($r) ? '' : strtoupper((string) wp_remote_retrieve_header($r, 'cf-cache-status'));
            };
            $hit = '';
            for ($i = 0; $i < 6; $i++) {
                $hit = $probe();
                if ($hit === 'HIT') { break; }
                wpc_diag_sleep(1, 'cf-selftest');
            }
            if ($hit !== 'HIT') {
                update_option('wpc_cf_selftest_fails', (int) get_option('wpc_cf_selftest_fails') + 1, false);
                if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('selftest-prime-failed', '', '', ['last' => $hit]); }
                return;
            }
            // Verify the primitive production actually relies on: purge-by-URL works on every
            // Cloudflare plan. Tag purge is Enterprise-only — testing it meant the crown could
            // never earn on most zones, keeping the long edge TTL permanently locked.
            $sdk->purgeFiles($cf['zone'], [$url]);
            // v7.10.575 — poll, do not guess. A 2s window failed every zone slower than 2s.
            $wpc_pv112 = wpc_cf_wait_evicted($probe);
            // '' = probe error, NOT proof of eviction — same guard leg B already has
            if ($wpc_pv112 !== '' && $wpc_pv112 !== 'HIT') {
                update_option('wpc_cf_purge_verified', ['t' => time(), 'method' => 'url', 'via' => 'selftest'], false);
                delete_option('wpc_cf_selftest_fails');


                update_option('wpc_cf_rules_normalized_at', time(), false);
                if (method_exists($sdk, 'patchHtmlRulesRespectOrigin')) {
                    $wpc_st_norm112 = $sdk->patchHtmlRulesRespectOrigin($cf['zone'], null, true);
                    if (function_exists('wpc_cache_first_log')) {
                        $wpc_st_rules112 = is_array($wpc_st_norm112) ? array_diff_key($wpc_st_norm112, ['tiered_on' => 1]) : [];
                        wpc_cache_first_log('selftest-crown-normalize', '', '', ['patched' => count($wpc_st_rules112)]);
                    }
                }

                // EARNED TIERED: purges propagate through all tiers (CF docs) — the historical
                // breakage was custom cache keys, never the tiers. Tiered warms every POP from
                // the first visit anywhere (the lab/PSI colo is never cold), so enable it — but
                // only if eviction re-verifies WITH tiered active, and revert on any doubt.
                if (apply_filters('wpc_earned_tiered', true)
                    && method_exists($sdk, 'enableTieredCache') && method_exists($sdk, 'disableTieredCache')) {
                    // v7.10.576 — SHUTDOWN GUARD, armed BEFORE the enable. .575 stretched this leg
                    // from a 2s guess to a poll of up to ~56s, which makes it long enough to be
                    // killed by max_execution_time mid-verify — and a death between enable and
                    // verdict leaves tiered caching ON and unproven, the one state this whole
                    // mechanism exists to prevent. The Doctor's tiered-on already had this belt;
                    // the cron path did not, and only became long enough to need it in .575.
                    @set_time_limit(300);
                    $GLOBALS['wpc_tiered_armed576'] = true;
                    register_shutdown_function(function () use ($sdk, $cf) {
                        if (empty($GLOBALS['wpc_tiered_armed576'])) {
                            return;
                        }
                        try {
                            $sdk->disableTieredCache($cf['zone']);
                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('cf-tiered-reverted', '', '', ['via' => 'selftest-died-before-verdict']);
                            }
                        } catch (\Throwable $e) {
                        }
                    });
                    $sdk->enableTieredCache($cf['zone']);
                    wpc_diag_sleep(3, 'cf-selftest');
                    // Re-prime the homepage (leg A just purged it) — the probes themselves seed it.
                    $wpc_h2 = '';
                    for ($i = 0; $i < 6; $i++) {
                        $wpc_h2 = $probe();
                        if ($wpc_h2 === 'HIT') { break; }
                        wpc_diag_sleep(1, 'cf-selftest');
                    }
                    $wpc_earned203 = false;
                    if ($wpc_h2 === 'HIT') {
                        $sdk->purgeFiles($cf['zone'], [$url]);
                        // Same fix as leg A: this 2s window is why tiered never earned anywhere.
                        $h3 = wpc_cf_wait_evicted($probe);
                        $wpc_earned203 = ($h3 !== '' && $h3 !== 'HIT');
                    }
                    // Verdict reached — disarm the shutdown belt either way. Placed before the
                    // branches so no path can return with it still armed.
                    $GLOBALS['wpc_tiered_armed576'] = false;
                    if ($wpc_earned203) {
                        update_option('wpc_cf_purge_verified', ['t' => time(), 'method' => 'url+tiered', 'via' => 'selftest'], false);
                        if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('tiered-earned', '', '', []); }
                    } else {
                        $sdk->disableTieredCache($cf['zone']);
                        if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('tiered-reverted', '', '', []); }
                    }
                }
                // Heal the test's own purge: re-seed the homepage so no visitor meets a MISS.
                if (function_exists('wpc_warm_url_queue')) {
                    wpc_warm_url_queue($url, 'selftest-reheal');
                }
            } else {
                update_option('wpc_cf_selftest_fails', (int) get_option('wpc_cf_selftest_fails') + 1, false);
                // Eviction failed — if a previous crown had earned tiered, revert it too:
                // an unverifiable zone must fall all the way back to the safe floor.
                $wpc_pvf = get_option('wpc_cf_purge_verified');
                if (is_array($wpc_pvf) && strpos((string) ($wpc_pvf['method'] ?? ''), 'tiered') !== false
                    && method_exists($sdk, 'disableTieredCache')) {
                    $sdk->disableTieredCache($cf['zone']);
                }
                if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('selftest-evict-failed', '', '', []); }
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_cf_selftest', 'wpc_cf_selftest_handler');

    // The self-test earns (and periodically re-earns) the verified-purge crown that
    // unlocks the long edge TTL. Fires shortly after each version change, and weekly
    // re-verifies so the crown always reflects the zone's current behavior.
    add_action('admin_init', function () {
        try {
            if (!function_exists('wp_schedule_single_event') || !function_exists('wp_next_scheduled')) {
                return;
            }
            $cf = get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf');
            if (empty($cf['token']) || empty($cf['zone'])) {
                return;
            }
            $pv = get_option('wpc_cf_purge_verified');
            $ts = is_array($pv) ? (int) ($pv['t'] ?? 0) : ($pv === '1' ? time() : 0);
            if ($ts && (time() - $ts) < 7 * DAY_IN_SECONDS) {
                return;
            }
            if (get_transient('wpc_cf_selftest_gate') || wp_next_scheduled('wpc_cf_selftest')) {
                return;
            }
            // Durable belt: an unverifiable zone must not re-run the selftest (6 loopbacks
            // + sleeps in a worker) every few minutes when the object cache is flushed
            $wpc_sta45 = (int) get_option('wpc_cf_selftest_attempt_at');
            $wpc_stf45 = (int) get_option('wpc_cf_selftest_fails');
            $wpc_stb45 = min(12 * HOUR_IN_SECONDS * max(1, $wpc_stf45), 7 * DAY_IN_SECONDS);
            if ($wpc_sta45 && (time() - $wpc_sta45) < $wpc_stb45) {
                return;
            }
            update_option('wpc_cf_selftest_attempt_at', time(), false);
            set_transient('wpc_cf_selftest_gate', 1, 12 * HOUR_IN_SECONDS);
            wp_schedule_single_event(time() + 120, 'wpc_cf_selftest');
        } catch (\Throwable $e) {
        }
    }, 45);
}


// Any used-css OFF→ON flip — Auto Mode, link preset, service plan, or a human — enters


// BEFORE the artifact landed — it approved an unchanged page, converged, and the artifact then


if (!function_exists('wpc_r2_probation_state')) {
    function wpc_r2_probation_state()
    {
        $s = get_option('wpc_r2_probation');
        $d = ['phase' => '', 't' => 0, 'base_m' => null, 'base_d' => null, 'post_m' => null, 'post_d' => null,
              'run_m' => '', 'run_d' => '', 'tries' => 0, 'phase_at' => 0];
        return is_array($s) ? array_merge($d, $s) : $d;
    }
    function wpc_r2_probation_save($s)
    {
        update_option('wpc_r2_probation', $s, false);
    }
    function wpc_r2_journal($event, $data = [])
    {
        if (function_exists('wpc_auto_journal')) { wpc_auto_journal($event, $data); }
        elseif (function_exists('wpc_cache_first_log')) { wpc_cache_first_log($event, '', '', $data); }
    }
    function wpc_r2_purge_html_layers()
    {
        try {
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                wps_ic_cache::removeHtmlCacheFiles('all');
            }
            if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeVarnish')) {
                wps_ic_cache_integrations::purgeVarnish(0, true);
            }
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                wps_ic_cache::cfPurgeAllHtml(true, true);

            }
        } catch (\Throwable $e) {
        }
    }
    /** Roll the lever back + purge + freeze R2 against auto re-flips. $why is journaled. */
    function wpc_r2_rollback($why, $extra = [])
    {
        try {
            $set = get_option(WPS_IC_SETTINGS);
            if (is_array($set)) {
                $set['used-css'] = '0';
                update_option(WPS_IC_SETTINGS, $set);
            }
            wpc_r2_purge_html_layers();
            if (function_exists('wpc_auto_state') && function_exists('wpc_auto_state_save')) {
                $st = wpc_auto_state();
                if (!in_array('R2', (array) $st['frozen'], true)) {
                    $st['frozen'][] = 'R2';
                    $st['frozen_epoch'] = wpc_auto_freeze_epoch824();
                    wpc_auto_state_save($st);
                }
            }
            delete_option('wpc_r2_probation');
            wpc_r2_journal('r2-rollback', array_merge(['why' => $why], $extra));
        } catch (\Throwable $e) {
        }
    }
    /** Start one measure run; '' when it could not start. Mirrors the Auto Mode transport. */
    function wpc_r2_measure_start($desktop)
    {
        try {
            $opts   = get_option(WPS_IC_OPTIONS);
            $apikey = (is_array($opts) && !empty($opts['api_key'])) ? $opts['api_key'] : '';
            if ($apikey === '' || !function_exists('home_url')) { return ''; }
            $endpoint = defined('WPS_IC_OPTIMIZE_API_URL') ? WPS_IC_OPTIMIZE_API_URL : 'https://pagespeed.zapwp.net/optimize';
            $resp = wp_remote_post($endpoint, [
                'timeout' => 20, 'headers' => ['Content-Type' => 'application/json'],
                'body' => wp_json_encode([
                    'url' => home_url('/'), 'apikey' => $apikey,
                    'version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
                    'desktop' => $desktop ? 1 : 0,
                    'uuid' => 'r2p-' . time() . '-' . wp_rand(1000, 9999) . ($desktop ? '-d' : ''),
                ]),
            ]);
            $b = is_wp_error($resp) ? [] : json_decode((string) wp_remote_retrieve_body($resp), true);
            if (is_array($b) && !empty($b['run_id']) && (($b['status'] ?? '') === 'accepted')
                && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{2,120}$/', (string) $b['run_id'])) {
                return (string) $b['run_id'];
            }
        } catch (\Throwable $e) {
        }
        return '';
    }
    /** Poll one run: float score when done, null on failure, '' while pending. */
    function wpc_r2_measure_score($run_id)
    {
        try {
            if ($run_id === '') { return null; }
            $base = defined('WPS_IC_OPTIMIZE_STATUS_API_URL') ? WPS_IC_OPTIMIZE_STATUS_API_URL : 'https://pagespeed.zapwp.net/optimize-status';
            $resp = wp_remote_get(add_query_arg(['run_id' => $run_id], $base), ['timeout' => 20, 'sslverify' => true]);
            $d = is_wp_error($resp) ? [] : json_decode((string) wp_remote_retrieve_body($resp), true);
            $state = is_array($d) ? (string) ($d['state'] ?? '') : '';
            if ($state === 'failed') { return null; }
            if ($state !== 'done') { return ''; }
            $r = is_array($d['report'] ?? null) ? $d['report'] : $d;
            if (isset($r['scores']['desktop'])) { return (float) $r['scores']['desktop']; }
            if (isset($r['scores']['mobile'])) { return (float) $r['scores']['mobile']; }
            return null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    function wpc_r2_probation_begin()
    {
        try {
            if (!apply_filters('wpc_r2_probation', true)) { return; }
            $s = wpc_r2_probation_state();
            if ($s['phase'] !== '' && time() - (int) $s['t'] < 3600) { return; }
            $rm = wpc_r2_measure_start(false);
            if ($rm === '') {

                wpc_r2_rollback('gate-no-baseline-run');
                return;
            }
            $rd = wpc_r2_measure_start(true);
            wpc_r2_probation_save(['phase' => 'baseline', 't' => time(), 'phase_at' => time(),
                'run_m' => $rm, 'run_d' => $rd, 'base_m' => null, 'base_d' => null,
                'post_m' => null, 'post_d' => null, 'tries' => 0]);
            wpc_r2_journal('r2-probation-start', ['run_m' => $rm, 'run_d' => $rd]);
            if (function_exists('wpc_auto_schedule')) { wpc_auto_schedule('wpc_r2_probation_tick', 45); }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_r2_probation_kick', 'wpc_r2_probation_begin');
    function wpc_r2_probation_tick_handler()
    {
        try {
            if (get_transient('wpc_r2_prob_lock')) { return; }
            set_transient('wpc_r2_prob_lock', 1, 40);
            $s = wpc_r2_probation_state();
            $tol = (int) apply_filters('wpc_r2_gate_tolerance', 3);
            $again = function ($delay) {
                if (function_exists('wpc_auto_schedule')) { wpc_auto_schedule('wpc_r2_probation_tick', $delay); }
            };

            $set = get_option(WPS_IC_SETTINGS);
            if (!is_array($set) || empty($set['used-css']) || $set['used-css'] != '1') {
                if ($s['phase'] !== '') { delete_option('wpc_r2_probation'); }
                delete_transient('wpc_r2_prob_lock');
                return;
            }
            if ($s['phase'] === 'baseline') {
                if ($s['base_m'] === null) {
                    $v = wpc_r2_measure_score($s['run_m']);
                    if ($v === null) {
                        $s['tries']++;
                        if ($s['tries'] > 2) { wpc_r2_rollback('gate-baseline-failed'); delete_transient('wpc_r2_prob_lock'); return; }
                        $s['run_m'] = wpc_r2_measure_start(false);
                        if ($s['run_m'] === '') { wpc_r2_rollback('gate-baseline-failed'); delete_transient('wpc_r2_prob_lock'); return; }
                        wpc_r2_probation_save($s); $again(60); delete_transient('wpc_r2_prob_lock'); return;
                    }
                    if ($v === '') {
                        if (time() - (int) $s['phase_at'] > 900) { wpc_r2_rollback('gate-baseline-timeout'); }
                        else { $again(30); }
                        delete_transient('wpc_r2_prob_lock'); return;
                    }
                    $s['base_m'] = (float) $v;
                }
                if ($s['base_d'] === null && $s['run_d'] !== '') {
                    $vd = wpc_r2_measure_score($s['run_d']);
                    if ($vd !== '' && $vd !== null) { $s['base_d'] = (float) $vd; }
                    elseif ($vd === null) { $s['run_d'] = ''; }
                }
                if ($s['base_m'] !== null && ($s['run_d'] === '' || $s['base_d'] !== null
                    || time() - (int) $s['phase_at'] > 600)) {
                    $s['phase'] = 'outcome-wait'; $s['phase_at'] = time(); $s['tries'] = 0;
                    wpc_r2_probation_save($s);
                    wpc_r2_journal('r2-baseline', ['m' => $s['base_m'], 'd' => $s['base_d']]);


                    // The probation owns this dispatch: clear the arm bounds so the demand
                    // actually fires inside the gate window regardless of prior attempts.
                    if (function_exists('wpc_used_css_arm_reset')) { wpc_used_css_arm_reset(); }
                    if (function_exists('wpc_used_css_self_arm')) { wpc_used_css_self_arm(); }
                    $again(90); delete_transient('wpc_r2_prob_lock'); return;
                }
                wpc_r2_probation_save($s); $again(30); delete_transient('wpc_r2_prob_lock'); return;
            }
            if ($s['phase'] === 'outcome-wait') {
                $met = function_exists('wpc_auto_lever_outcome_met') ? wpc_auto_lever_outcome_met('R2') : true;
                if (!$met) {


                    if (function_exists('wpc_used_css_self_arm')) { wpc_used_css_self_arm(); }


                    if (time() - (int) $s['phase_at'] > (int) apply_filters('wpc_r2_outcome_ceiling', 300)) {
                        wpc_r2_rollback('gate-outcome-timeout');
                    } else {

                        if (function_exists('wpc_warm_url_fire') && function_exists('home_url')
                            && time() - (int) $s['phase_at'] > 60 && empty($s['nudged'])) {
                            wpc_warm_url_fire(home_url('/')); $s['nudged'] = 1; wpc_r2_probation_save($s);
                        }
                        $again(30);
                    }
                    delete_transient('wpc_r2_prob_lock'); return;
                }
                wpc_r2_purge_html_layers();


                if (function_exists('wpc_warm_url_fire') && function_exists('home_url')) {
                    wpc_warm_url_fire(home_url('/'));
                }
                $s['phase'] = 'settle'; $s['phase_at'] = time();
                wpc_r2_probation_save($s);
                wpc_r2_journal('r2-outcome-landed', []);
                $again(30); delete_transient('wpc_r2_prob_lock'); return;
            }
            if ($s['phase'] === 'settle') {


                if (time() - (int) $s['phase_at'] < (int) apply_filters('wpc_r2_settle_seconds', 60)) {
                    $again(20); delete_transient('wpc_r2_prob_lock'); return;
                }
                $s['run_m'] = wpc_r2_measure_start(false);
                if ($s['run_m'] === '') { wpc_r2_rollback('gate-post-failed'); delete_transient('wpc_r2_prob_lock'); return; }
                $s['run_d'] = ($s['base_d'] !== null) ? wpc_r2_measure_start(true) : '';
                $s['phase'] = 'post'; $s['phase_at'] = time(); $s['tries'] = 0;
                wpc_r2_probation_save($s);
                $again(30); delete_transient('wpc_r2_prob_lock'); return;
            }
            if ($s['phase'] === 'post') {
                if ($s['post_m'] === null) {
                    $v = wpc_r2_measure_score($s['run_m']);
                    if ($v === null) {
                        $s['tries']++;
                        if ($s['tries'] > 2) { wpc_r2_rollback('gate-post-failed'); delete_transient('wpc_r2_prob_lock'); return; }
                        $s['run_m'] = wpc_r2_measure_start(false);
                        if ($s['run_m'] === '') { wpc_r2_rollback('gate-post-failed'); delete_transient('wpc_r2_prob_lock'); return; }
                        wpc_r2_probation_save($s); $again(60); delete_transient('wpc_r2_prob_lock'); return;
                    }
                    if ($v === '') {
                        if (time() - (int) $s['phase_at'] > 900) { wpc_r2_rollback('gate-post-timeout'); }
                        else { $again(30); }
                        delete_transient('wpc_r2_prob_lock'); return;
                    }
                    $s['post_m'] = (float) $v;
                }
                if ($s['post_d'] === null && $s['run_d'] !== '') {
                    $vd = wpc_r2_measure_score($s['run_d']);
                    if ($vd !== '' && $vd !== null) { $s['post_d'] = (float) $vd; }
                    elseif ($vd === null) { $s['run_d'] = ''; }
                    elseif (time() - (int) $s['phase_at'] < 600) {
                        wpc_r2_probation_save($s); $again(45); delete_transient('wpc_r2_prob_lock'); return;
                    }
                }
                $dropM = ($s['post_m'] !== null && $s['base_m'] !== null && $s['post_m'] < $s['base_m'] - $tol);
                $dropD = ($s['post_d'] !== null && $s['base_d'] !== null && $s['post_d'] < $s['base_d'] - $tol);
                $facts = ['base_m' => $s['base_m'], 'post_m' => $s['post_m'], 'base_d' => $s['base_d'], 'post_d' => $s['post_d']];
                if ($dropM || $dropD) {
                    wpc_r2_rollback('gate-verdict-drop', array_merge($facts, ['device' => $dropM ? ($dropD ? 'both' : 'mobile') : 'desktop']));
                } else {
                    update_option('wpc_r2_verified', array_merge(['t' => time()], $facts), false);
                    delete_option('wpc_r2_probation');
                    wpc_r2_journal('r2-held', $facts);
                }
                delete_transient('wpc_r2_prob_lock');
                return;
            }
            delete_transient('wpc_r2_prob_lock');
        } catch (\Throwable $e) {
            delete_transient('wpc_r2_prob_lock');
        }
    }
    add_action('wpc_r2_probation_tick', 'wpc_r2_probation_tick_handler');


    function wpc_r2_kick()
    {
        try {
            if (!function_exists('wpc_auto_kick_sig')) { return; }
            $wpc_sig = wpc_auto_kick_sig();
            if ($wpc_sig === '' || !function_exists('wp_remote_get') || !function_exists('admin_url')) { return; }
            wp_remote_get(admin_url('admin-ajax.php') . '?action=wpc_auto_kick&w=r2&k=' . rawurlencode($wpc_sig),
                ['blocking' => false, 'timeout' => 2, 'headers' => ['X-WPC-Cache-Warm' => '1']]);
        } catch (\Throwable $e) {
        }
    }

    function wpc_r2_on_artifact_land()
    {
        try {
            $s = get_option('wpc_r2_probation');
            if (is_array($s) && !empty($s['phase']) && $s['phase'] === 'outcome-wait') {
                wpc_r2_kick();
            }
        } catch (\Throwable $e) {
        }
    }

    // The choke point: EVERY used-css transition passes through the settings option.
    add_action('update_option_' . (defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings'), function ($old, $new) {
        try {
            $o = is_array($old) && isset($old['used-css']) ? (string) $old['used-css'] : '';
            $n = is_array($new) && isset($new['used-css']) ? (string) $new['used-css'] : '';
            if ($n === '1' && $o !== '1') {
                if (function_exists('wpc_auto_schedule')) {
                    wpc_auto_schedule('wpc_r2_probation_kick', 8); // 40s of PSI HTTP leaves the Save request
                } else {
                    wpc_r2_probation_begin();
                }
            } elseif ($o === '1' && $n !== '1') {
                delete_option('wpc_r2_probation');


                wpc_r2_purge_html_layers();
            }
        } catch (\Throwable $e) {
        }
    }, 10, 2);
}


if (!function_exists('wpc_autopurge_on_land')) {
    /** Called from every artifact store site with the crit-dir path or url_key. */
    function wpc_autopurge_on_land($dirOrKey)
    {
        try {
            if (!apply_filters('wpc_auto_purge', true)) { return; }
            $key = basename((string) rtrim((string) $dirOrKey, '/'));
            if ($key === '' || strlen($key) > 200) { return; }
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_autopurge_check', [$key])) {
                wpc_pl_sched(time() + 130, 'wpc_autopurge_check', [$key]);
            }

            if (function_exists('wpc_auto_schedule')) { wpc_auto_schedule('wpc_autopurge_sweep', 140); }
        } catch (\Throwable $e) {
        }
    }
    /** Newest artifact mtime in a crit dir — the "what should be serving" clock. */
    function wpc_autopurge_artifact_mtime($critDir)
    {
        $mt = 0;
        foreach (['critical_desktop.css', 'critical_mobile.css', 'used_tpl.txt', 'lcp.json', 'font-subsets.css', 'delay.json'] as $f) {
            $m = @filemtime($critDir . $f);
            if ($m && $m > $mt) { $mt = $m; }
        }

        $tpl = trim((string) @file_get_contents($critDir . 'used_tpl.txt'));
        if ($tpl !== '' && function_exists('wpc_used_css_path')) {
            foreach (['', 'mobile', 'desktop'] as $dev) {
                $p = $dev === '' ? wpc_used_css_path($tpl) : wpc_used_css_path($tpl, $dev);
                $m = ($p !== '') ? @filemtime($p) : 0;
                if ($m && $m > $mt) { $mt = $m; }
            }
        }
        return $mt;
    }
    /** Oldest cached-HTML mtime for a key — the "what is serving" clock (0 = nothing cached). */
    function wpc_autopurge_cached_mtime($key)
    {
        if (!defined('WPS_IC_CACHE')) { return 0; }
        $mt = 0;
        // v7.10.643 — the clock must see VARIANT copies (team atlas: cookie-variant
        // ghost). Oldest-mtime is the whole point of this clock — the stalest copy a
        // visitor can be served — and the operator's own logged-in/cookie variant of
        // the homepage was exactly the copy it could not see: bare copy fresh ⇒ no
        // purge fired, while the variant served old crit for weeks.
        foreach ([
            WPS_IC_CACHE . $key . '/*index.html*',
            WPS_IC_CACHE . $key . '_*/*index.html*',
            WPS_IC_CACHE . '*/' . $key . '/*index.html*',
            WPS_IC_CACHE . '*/' . $key . '_*/*index.html*',
        ] as $wpc_pat643) {
            foreach ((array) @glob($wpc_pat643) as $f) {
                $m = @filemtime($f);
                if ($m && ($mt === 0 || $m < $mt)) { $mt = $m; }
            }
        }
        // v7.10.647 — the STATIC MIRROR is a different tree (URI-keyed under <host>/)
        // and was invisible here: hashed copy gone + mirror stale read as "nothing
        // cached" and no purge ever fired while the mirror kept serving (wpcompress.com,
        // 6.7h-stale receipt). Resolve the key's URL and score the mirror copies too.
        if (class_exists('wps_ic_url_key') && method_exists('wps_ic_url_key', 'getUrlFromKey')) {
            $wpc_u647 = (string) wps_ic_url_key::getUrlFromKey($key);
            if ($wpc_u647 !== '' && strpos($wpc_u647, '..') === false) {
                $wpc_pu647 = @parse_url((strpos($wpc_u647, '//') === false ? 'https://' : '') . $wpc_u647);
                if (is_array($wpc_pu647) && !empty($wpc_pu647['host'])) {
                    $wpc_md647 = WPS_IC_CACHE . $wpc_pu647['host'] . rtrim(isset($wpc_pu647['path']) ? $wpc_pu647['path'] : '/', '/');
                    foreach (['index.html_gzip', 'mobile_index.html_gzip', 'index.html', 'mobile_index.html'] as $wpc_mf647) {
                        $m = @filemtime($wpc_md647 . '/' . $wpc_mf647);
                        if ($m && ($mt === 0 || $m < $mt)) { $mt = $m; }
                    }
                }
            }
        }
        return $mt;
    }
    function wpc_autopurge_check_handler($key)
    {
        if (function_exists('wpc_pipeline_key_junk') && wpc_pipeline_key_junk($key)) { return; }
        try {
            if (!apply_filters('wpc_auto_purge', true) || !defined('WPS_IC_CRITICAL')) { return; }
            $key = basename((string) $key);
            $critDir = WPS_IC_CRITICAL . $key . '/';
            if (!is_dir($critDir)) { return; }
            $art = wpc_autopurge_artifact_mtime($critDir);
            $html = wpc_autopurge_cached_mtime($key);
            if ($art === 0 || $html === 0 || $html >= $art) {
                return;
            }
            // v7.10.604 — STALENESS FLOOR. Any lag at all counted as stale, so a cached page
            // 4 SECONDS behind its artifact triggered a full edge purge and a cold MISS
            // (measured 1.024s vs 0.104s on a HIT). Measured lag distribution on wpcompress.com:
            // median 123s, and only 24% under 60s while ~84% fall under 300s. A small lag also
            // self-heals — the next natural render picks up the current artifact set — so the
            // purge buys a marginally fresher crit at the price of evicting a warm page.
            $wpc_lagmin604 = (int) apply_filters('wpc_autopurge_min_lag_s', 300);
            if ($wpc_lagmin604 > 0 && ($art - $html) < $wpc_lagmin604) {
                if (function_exists('wpc_auto_journal')) {
                    wpc_auto_journal('auto-purge-below-floor', ['key' => $key, 'lag_s' => $art - $html, 'floor' => $wpc_lagmin604]);
                }
                return;
            }
            if (time() - $art < 120) {

                if (function_exists('wp_schedule_single_event') && !wp_next_scheduled('wpc_autopurge_check', [$key])) {
                    wpc_pl_sched(time() + 130, 'wpc_autopurge_check', [$key]);
                }
                return;
            }

            $cnt = (int) get_transient('wpc_ap_page_' . md5($key));
            if ($cnt >= 4) {
                if (function_exists('wpc_auto_journal')) { wpc_auto_journal('auto-purge-capped', ['key' => $key]); }
                return;
            }
            set_transient('wpc_ap_page_' . md5($key), $cnt + 1, HOUR_IN_SECONDS);

            $recent = get_option('wpc_ap_recent', []);
            if (!is_array($recent)) { $recent = []; }
            $now = time();
            $recent = array_filter($recent, function ($t) use ($now) { return $now - (int) $t < 600; });
            $recent[$key] = $now;
            update_option('wpc_ap_recent', $recent, false);
            if (count($recent) >= 3) {
                $day = gmdate('Ymd');
                $sw = get_option('wpc_ap_sitewide', []);
                if (!is_array($sw) || ($sw['d'] ?? '') !== $day) { $sw = ['d' => $day, 'n' => 0]; }
                if ((int) $sw['n'] < 2) {
                    $sw['n']++;
                    update_option('wpc_ap_sitewide', $sw, false);
                    update_option('wpc_ap_recent', [], false);
                    if (function_exists('wpc_r2_purge_html_layers')) { wpc_r2_purge_html_layers(); }
                    if (function_exists('wpc_warm_url_queue') && function_exists('home_url')) {
                        wpc_warm_url_queue(home_url('/'), 'auto-purge-sitewide');
                    }
                    if (function_exists('wpc_auto_journal')) {
                        wpc_auto_journal('auto-purge', ['scope' => 'site', 'trigger' => '3-pages-stale', 'nth_today' => $sw['n']]);
                    }
                    return;
                }
            }

            if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                wps_ic_cache_integrations::purgeUrlHtml($key, '', ['context' => 'auto-purge-stale']);
            }
            if (function_exists('wpc_auto_journal')) {
                wpc_auto_journal('auto-purge', ['scope' => 'page', 'key' => $key, 'trigger' => 'artifact-newer',
                    'art' => $art, 'html' => $html, 'lag_s' => $art - $html]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_autopurge_check', 'wpc_autopurge_check_handler');
    // Sweep belt: re-check the homepage key even if the per-key event was lost (cron dedupe etc.)
    function wpc_autopurge_sweep_handler()
    {
        try {
            if (!class_exists('wps_ic_url_key') || !function_exists('home_url')) { return; }
            $k = (new wps_ic_url_key())->setup(home_url('/'));
            if ($k !== '') { wpc_autopurge_check_handler($k); }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_autopurge_sweep', 'wpc_autopurge_sweep_handler');
}

if (!function_exists('wpc_crit_junk_sweep599')) {
    /**
     * v7.10.599 — remove crit dirs that can never be used, and NOTHING else.
     *
     * Receipted by the crit team on staging: 21 of 32 dirs under cache/critical/ hold no crit CSS
     * at all. Most predate .530/.590, which closed the two paths that minted them (attachment
     * permalinks, and endpoints like admin-ajax.php resolving to no post). A gate stops new ones;
     * it cannot remove the ones already on disk.
     *
     * THE DANGEROUS PART IS NOT THE DELETION, IT IS THE PREDICATE. Two of those 21 were `inv2/`
     * and `used-css/` — infrastructure, not page dirs — so a naive "no crit CSS means junk" sweep
     * would have destroyed them. That is the whole reason this got its own build, and it is why
     * every rule below is a reason to KEEP rather than a reason to delete.
     *
     * A dir is removed only when ALL of these hold:
     *   1. its name is not infrastructure (inv2*, used-css, anything dot-prefixed)
     *   2. it carries no critical_desktop/mobile/combined.css
     *   3. it carries no font-subsets.css — a fonts-only dir still serves faces (.561)
     *   4. every file in it is older than the age floor, so a generation in flight is never
     *      touched: a dispatched-but-unlanded dir has a fresh dispatch_ts.txt and is skipped
     *   5. it contains no subdirectories — an unexpected shape is left alone by definition
     *   6. its realpath is genuinely inside WPS_IC_CRITICAL
     * Bounded per run, every removal logged with the file list that justified it, and
     * `wpc_crit_junk_sweep_dry_run` reports without unlinking so the predicate can be audited on
     * a real site before it is trusted.
     */
    function wpc_crit_junk_sweep599()
    {
        if (!apply_filters('wpc_crit_junk_sweep', true) || !defined('WPS_IC_CRITICAL')) {
            return;
        }
        try {
            $wpc_root599 = @realpath(rtrim(WPS_IC_CRITICAL, '/'));
            if ($wpc_root599 === false || !@is_dir($wpc_root599)) {
                return;
            }
            $wpc_dry599 = (bool) apply_filters('wpc_crit_junk_sweep_dry_run', false);
            $wpc_age599 = (int) apply_filters('wpc_crit_junk_sweep_min_age', 86400);
            $wpc_cap599 = (int) apply_filters('wpc_crit_junk_sweep_cap', 25);
            $wpc_now599 = time();
            $wpc_done599 = 0;
            $wpc_kept599 = 0;
            foreach ((array) @scandir($wpc_root599) as $wpc_name599) {
                if ($wpc_done599 >= $wpc_cap599) {
                    break;
                }
                if ($wpc_name599 === '.' || $wpc_name599 === '..' || $wpc_name599 === '') {
                    continue;
                }
                // Infrastructure and dot-dirs are never candidates, by name, before any file test.
                if ($wpc_name599[0] === '.' || strpos($wpc_name599, 'inv2') === 0
                    || $wpc_name599 === 'used-css' || $wpc_name599 === 'combine') {
                    continue;
                }
                $wpc_dir599 = $wpc_root599 . '/' . $wpc_name599;
                if (!@is_dir($wpc_dir599) || @is_link($wpc_dir599)) {
                    continue;
                }
                if (strpos((string) @realpath($wpc_dir599), $wpc_root599 . '/') !== 0) {
                    continue;
                }
                foreach (['critical_desktop.css', 'critical_mobile.css', 'critical_combined.css',
                    'font-subsets.css', 'icon-subsets.css'] as $wpc_keep599) {
                    if (@is_file($wpc_dir599 . '/' . $wpc_keep599)) {
                        continue 2;
                    }
                }
                $wpc_files599 = [];
                $wpc_newest599 = 0;
                $wpc_subdir599 = false;
                foreach ((array) @scandir($wpc_dir599) as $wpc_f599) {
                    if ($wpc_f599 === '.' || $wpc_f599 === '..' || $wpc_f599 === '') {
                        continue;
                    }
                    $wpc_fp599 = $wpc_dir599 . '/' . $wpc_f599;
                    if (@is_dir($wpc_fp599)) {
                        $wpc_subdir599 = true;
                        break;
                    }
                    $wpc_files599[] = $wpc_f599;
                    $wpc_newest599 = max($wpc_newest599, (int) @filemtime($wpc_fp599));
                    if (count($wpc_files599) > 60) {
                        $wpc_subdir599 = true; // unexpected shape; treat as do-not-touch
                        break;
                    }
                }
                if ($wpc_subdir599) {
                    continue;
                }
                // A dir with recent activity is a generation in flight, not junk.
                if ($wpc_newest599 > 0 && ($wpc_now599 - $wpc_newest599) < $wpc_age599) {
                    $wpc_kept599++;
                    continue;
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log($wpc_dry599 ? 'crit-junk-sweep-dry' : 'crit-junk-sweep',
                        (string) $wpc_name599, '', [
                            'files' => count($wpc_files599),
                            'age_h' => $wpc_newest599 > 0 ? (int) (($wpc_now599 - $wpc_newest599) / 3600) : -1,
                            'held' => implode(',', array_slice($wpc_files599, 0, 8)),
                        ]);
                }
                if ($wpc_dry599) {
                    $wpc_done599++;
                    continue;
                }
                foreach ($wpc_files599 as $wpc_f599) {
                    @unlink($wpc_dir599 . '/' . $wpc_f599);
                }
                if (@rmdir($wpc_dir599)) {
                    $wpc_done599++;
                }
            }
            if (($wpc_done599 > 0 || $wpc_kept599 > 0) && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-junk-sweep-run', '', '', [
                    'removed' => $wpc_done599,
                    'in_flight_kept' => $wpc_kept599,
                    'dry' => $wpc_dry599 ? 1 : 0,
                    'capped' => $wpc_done599 >= $wpc_cap599 ? 1 : 0,
                ]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_autopurge_sweep', 'wpc_crit_junk_sweep599', 20);
}

// v7.10.642 — RETENTION for the three writer-with-no-deleter stores (James: ".kicklocks
// and used-css are never deleted... we can't let those folders just grow"). The used-css
// comment's "deleting orphans HTML alive in CDN/browser caches" argues against
// delete-on-purge, not against GC. Age alone cannot prove deadness for in-place stores
// (a healthy site's live artifact sits byte-stable for months), so the serve paths now
// refresh liveness — used-css by touching the served artifact (its URL never derives
// from mtime), combine via a .wpc-live sidecar (artifact mtime feeds the content hash) —
// and this sweep collects only what nothing has served for 60 days. Kicklocks are
// transient locks and land markers: anything older than 48h is dead by definition.
if (!function_exists('wpc_store_retention_sweep642')) {
    function wpc_store_retention_sweep642()
    {
        if (!apply_filters('wpc_store_retention_sweep', true) || !defined('WPS_IC_CRITICAL')) {
            return;
        }
        try {
            $wpc_dry642 = (bool) apply_filters('wpc_store_retention_dry_run', false);
            $wpc_now642 = time();
            $wpc_log642 = ['kick' => 0, 'ucss' => 0, 'comb' => 0];
            $wpc_del642 = function ($wpc_fp) use ($wpc_dry642) {
                return $wpc_dry642 ? true : @unlink($wpc_fp);
            };

            // 1. kicklocks: transient locks/markers, 48h floor, files only.
            $wpc_kd642 = rtrim(WPS_IC_CRITICAL, '/') . '/.kicklocks';
            $wpc_kage642 = (int) apply_filters('wpc_kicklock_max_age', 172800);
            $wpc_kcap642 = 300;
            foreach ((array) @scandir($wpc_kd642) as $wpc_f642) {
                if ($wpc_kcap642 <= 0) { break; }
                if ($wpc_f642 === '.' || $wpc_f642 === '..') { continue; }
                $wpc_fp642 = $wpc_kd642 . '/' . $wpc_f642;
                if (@is_file($wpc_fp642) && (int) @filemtime($wpc_fp642) < $wpc_now642 - $wpc_kage642) {
                    if ($wpc_del642($wpc_fp642)) { $wpc_log642['kick']++; $wpc_kcap642--; }
                }
            }

            // 2. used-css: files only, never the dir itself; grouped by TEMPLATE STEM
            //    (tpl-X.css / tpl-X.mobile.css / tpl-X.sheets.json are one family) so the
            //    serve-path touch on the bundle spares its siblings. A family whose newest
            //    member is 60d untouched has served nothing for 60 days.
            $wpc_uage642 = (int) apply_filters('wpc_store_retention_max_age', 60 * 86400);
            $wpc_ud642 = rtrim(WPS_IC_CRITICAL, '/') . '/used-css';
            $wpc_ucap642 = 100;
            $wpc_fam642 = [];
            foreach ((array) @scandir($wpc_ud642) as $wpc_f642) {
                if ($wpc_f642 === '.' || $wpc_f642 === '..') { continue; }
                $wpc_fp642 = $wpc_ud642 . '/' . $wpc_f642;
                if (!@is_file($wpc_fp642)) { continue; }
                $wpc_st642 = preg_replace('/\.live$/', '', $wpc_f642);
                $wpc_st642 = preg_replace('/\.(sheets\.json|css)$/', '', $wpc_st642);
                $wpc_st642 = preg_replace('/\.(mobile|desktop|atf|rest)$/', '', $wpc_st642);
                $wpc_st642 = preg_replace('/\.(mobile|desktop|atf|rest)$/', '', $wpc_st642);
                if (!isset($wpc_fam642[$wpc_st642])) { $wpc_fam642[$wpc_st642] = ['n' => 0, 'f' => []]; }
                $wpc_fam642[$wpc_st642]['n'] = max($wpc_fam642[$wpc_st642]['n'], (int) @filemtime($wpc_fp642));
                $wpc_fam642[$wpc_st642]['f'][] = $wpc_fp642;
            }
            foreach ($wpc_fam642 as $wpc_g642) {
                if ($wpc_ucap642 <= 0) { break; }
                if ($wpc_g642['n'] >= $wpc_now642 - $wpc_uage642) { continue; }
                foreach ($wpc_g642['f'] as $wpc_fp642) {
                    if ($wpc_ucap642 <= 0) { break; }
                    if ($wpc_del642($wpc_fp642)) { $wpc_log642['ucss']++; $wpc_ucap642--; }
                }
            }

            // 3. combine: per-key dirs under WPS_IC_COMBINE; a key dir whose .wpc-live
            //    sidecar (or newest artifact) is younger than the floor is spared whole.
            if (defined('WPS_IC_COMBINE')) {
                $wpc_cr642 = rtrim(WPS_IC_COMBINE, '/');
                $wpc_ccap642 = 100;
                foreach ((array) @scandir($wpc_cr642) as $wpc_k642) {
                    if ($wpc_ccap642 <= 0) { break; }
                    if ($wpc_k642 === '.' || $wpc_k642 === '..') { continue; }
                    $wpc_kdir642 = $wpc_cr642 . '/' . $wpc_k642 . '/css';
                    if (!@is_dir($wpc_kdir642)) { continue; }
                    if ((int) @filemtime($wpc_kdir642 . '/.wpc-live') >= $wpc_now642 - $wpc_uage642) { continue; }
                    $wpc_newest642 = 0;
                    $wpc_list642 = [];
                    foreach ((array) @scandir($wpc_kdir642) as $wpc_f642) {
                        if ($wpc_f642 === '.' || $wpc_f642 === '..') { continue; }
                        $wpc_fp642 = $wpc_kdir642 . '/' . $wpc_f642;
                        if (!@is_file($wpc_fp642)) { $wpc_newest642 = PHP_INT_MAX; break; }
                        $wpc_list642[] = $wpc_fp642;
                        $wpc_newest642 = max($wpc_newest642, (int) @filemtime($wpc_fp642));
                    }
                    if ($wpc_newest642 >= $wpc_now642 - $wpc_uage642 || empty($wpc_list642)) { continue; }
                    foreach ($wpc_list642 as $wpc_fp642) {
                        if ($wpc_del642($wpc_fp642)) { $wpc_log642['comb']++; }
                    }
                    if (!$wpc_dry642) {
                        @rmdir($wpc_kdir642);
                        @rmdir($wpc_cr642 . '/' . $wpc_k642);
                    }
                    $wpc_ccap642--;
                }
            }

            if (array_sum($wpc_log642) > 0 && function_exists('wpc_cache_first_log')) {
                $wpc_log642['dry'] = $wpc_dry642 ? 1 : 0;
                wpc_cache_first_log('store-retention', '', '', $wpc_log642);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_autopurge_sweep', 'wpc_store_retention_sweep642', 30);
}


// PERMANENT decline, never retried daily. Kill: option wpc_webp_source='0' / filter.
if (!function_exists('wpc_webp_source_ok')) {
    function wpc_webp_source_ok()
    {
        return (bool) apply_filters('wpc_webp_source', get_option('wpc_webp_source', '1') === '1');
    }
    /** The one list every scope gate reads — bulk queries, ML cards, verification in_arrays. */
    function wpc_optimizable_mimes()
    {
        $m = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        if (wpc_webp_source_ok()) {
            $m[] = 'image/webp';
        }
        return (array) apply_filters('wpc_optimizable_mimes', $m);
    }

    function wpc_is_animated_webp($path)
    {
        try {
            if (!is_string($path) || $path === '' || !@is_readable($path)) { return false; }
            $h = @fopen($path, 'rb');
            if (!$h) { return false; }
            $b = (string) fread($h, 21);
            fclose($h);
            if (strlen($b) < 21 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') {
                return false;
            }
            if (substr($b, 12, 4) !== 'VP8X') {
                return false;
            }
            return (ord($b[20]) & 0x02) === 0x02; // VP8X flags byte, ANIMATION bit
        } catch (\Throwable $e) {
            return false;
        }
    }
    /** Permanent decline: scope filters exclude it; the ML card names it; no daily retry churn. */
    function wpc_webp_animated_decline($attachmentId)
    {
        if ((int) $attachmentId > 0 && function_exists('update_post_meta')) {
            update_post_meta((int) $attachmentId, '_wpc_animated_webp', '1');
        }
    }
    /** Scope-time gate for one attachment: true = dispatchable webp source. */
    function wpc_webp_attachment_ok($attachmentId, $path = '')
    {
        if (!wpc_webp_source_ok()) { return false; }
        if ((int) $attachmentId > 0 && function_exists('get_post_meta')
            && get_post_meta((int) $attachmentId, '_wpc_animated_webp', true) === '1') {
            return false;
        }
        if ($path === '' && (int) $attachmentId > 0 && function_exists('get_attached_file')) {
            $path = (string) get_attached_file((int) $attachmentId);
        }
        if ($path !== '' && wpc_is_animated_webp($path)) {
            wpc_webp_animated_decline($attachmentId);
            return false;
        }
        return true;
    }
}


if (!function_exists('wpc_pipeline_debug_handler')) {

    function wpc_pipeline_debug_handler()
    {
        try {
            $opts = (function_exists('get_option') && defined('WPS_IC_OPTIONS')) ? get_option(WPS_IC_OPTIONS) : [];
            $api  = (is_array($opts) && !empty($opts['api_key'])) ? (string) $opts['api_key'] : '';
            $key  = substr(md5('wpc-dbg:' . $api), 0, 16);
            $k    = isset($_GET['k']) ? (string) $_GET['k'] : '';
            if ($api === '' || $k === '' || !hash_equals($key, $k)) {
                if (function_exists('status_header')) { status_header(403); }
                wp_send_json(['err' => 'bad key']);
                return;
            }
            $url = (isset($_GET['url']) && is_string($_GET['url'])) ? esc_url_raw((string) $_GET['url']) : home_url('/');
            $uk  = class_exists('wps_ic_url_key') ? ltrim((string) (new wps_ic_url_key())->setup($url), '/') : '';
            $cd  = defined('WPS_IC_CRITICAL') ? WPS_IC_CRITICAL . $uk . '/' : '';
            $fi = function ($f) use ($cd) {
                $p = $cd . $f;
                return @is_file($p) ? ['size' => (int) @filesize($p), 'age_s' => time() - (int) @filemtime($p)] : null;
            };
            $files = [];
            foreach (['critical_desktop.css', 'critical_mobile.css', 'critical_combined.css', 'lcp.json', 'used_tpl.txt', 'tpl.txt', 'delay.json', 'font-subsets.css', 'font-metrics.json', 'font-preload.txt', 'lcp_url.txt', 'delay_url.txt', 'fonts_url.txt', 'used_css_url.txt', 'used_css_mobile_url.txt', 'used_css_desktop_url.txt', 'land_uuid.txt', 'uuid.txt', 'gen_fails.json'] as $f) {
                $files[$f] = $fi($f);
            }
            $lcp = null;
            if (!empty($files['lcp.json'])) {
                $j = json_decode((string) @file_get_contents($cd . 'lcp.json'), true);
                if (is_array($j)) {
                    $lcp = [
                        'atf_m' => isset($j['atf_images']['mobile']) ? count((array) $j['atf_images']['mobile']) : 0,
                        'atf_d' => isset($j['atf_images']['desktop']) ? count((array) $j['atf_images']['desktop']) : 0,
                        'oversized' => isset($j['oversized_images']) ? 1 : 0,
                        'lcp_type_m' => isset($j['lcp']['mobile']['type']) ? $j['lcp']['mobile']['type'] : null,
                        'lcp_type_d' => isset($j['lcp']['desktop']['type']) ? $j['lcp']['desktop']['type'] : null,
                    ];
                }
            }
            $set  = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
            $ucOn = is_array($set) && !empty($set['used-css']) && $set['used-css'] == '1';
            $tpl  = trim((string) @file_get_contents($cd . 'used_tpl.txt'));
            $tplSrc = 'used_tpl.txt';
            if ($tpl === '') { $tpl = trim((string) @file_get_contents($cd . 'tpl.txt')); $tplSrc = 'tpl.txt'; }
            $uc = ['setting_on' => $ucOn, 'tpl_key' => $tpl !== '' ? substr($tpl, 0, 24) : '', 'tpl_source' => $tpl !== '' ? $tplSrc : 'NONE'];
            foreach (['' => 'union', 'mobile' => 'mobile', 'desktop' => 'desktop'] as $dv => $lbl) {
                $p = ($tpl !== '' && function_exists('wpc_used_css_path')) ? wpc_used_css_path($tpl, $dv) : '';
                $uc['store_' . $lbl] = ($p !== '' && @is_file($p)) ? (int) @filesize($p) : 0;
            }
            $uc['reason'] = !$ucOn ? 'setting-off'
                : ($tpl === '' ? 'no-tpl-pointer (key dir lost it — the purge-orphan class)'
                : (max($uc['store_union'], $uc['store_mobile'], $uc['store_desktop']) <= 64 ? 'store-artifact-missing' : 'should-serve'));
            wp_send_json([
                'v' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?',
                't' => gmdate('c'),
                'url' => $url,
                'key' => $uk,
                'files' => $files,
                'lcp_json' => $lcp,
                'used_css' => $uc,
                'r2' => function_exists('get_option') ? get_option('wpc_r2_probation') : null,
                'journal_tail' => array_slice((array) get_option('wpc_auto_mode_journal', []), -10),
                'meta_raw' => (function () use ($cd) {
                    $o = [];
                    foreach (['used_css_url.txt', 'used_css_mobile_url.txt', 'used_css_desktop_url.txt', 'fonts_url.txt', 'crit_combined_url.txt'] as $mf) {
                        $o[$mf] = @is_file($cd . $mf) ? substr(trim((string) @file_get_contents($cd . $mf)), 0, 90) : null;
                    }
                    return $o;
                })(),
                'cflog_tail' => (function () {
                    $lf = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl' : '';
                    if ($lf === '' || !@is_readable($lf)) { return null; }
                    $sz = (int) @filesize($lf);
                    $tail = (string) @file_get_contents($lf, false, null, max(0, $sz - 8192));
                    return array_slice(array_values(array_filter(explode("\n", $tail))), -15);
                })(),
                'cflog_db' => array_slice((array) get_option('wpc_cache_first_log', []), -15),
                'flags' => [
                    'combined_crit' => (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_combined_crit_on')) ? (bool) wps_rewriteLogic::wpc_combined_crit_on() : null,
                    'delay_v3' => is_array($set) && !empty($set['delay-js-v2']) && $set['delay-js-v2'] == '1',
                    'replace_fonts' => (is_array($set) && isset($set['replace-fonts'])) ? $set['replace-fonts'] : null,
                    'css_icv' => function_exists('get_option') ? substr((string) get_option('css_hash'), 0, 8) : null,
                ],
            ]);
        } catch (\Throwable $e) {
            wp_send_json(['err' => 'exception', 'msg' => substr($e->getMessage(), 0, 120)]);
        }
    }
    if (function_exists('add_action')) {
        add_action('wp_ajax_wpc_pipeline_debug', 'wpc_pipeline_debug_handler');
        add_action('wp_ajax_nopriv_wpc_pipeline_debug', 'wpc_pipeline_debug_handler');
    }
}

if (!function_exists('wpc_3p_pattern_ok')) {
    /** A1 pattern gate — delegates to the v3 engine's validator (single authority);
     *  conservative inline fallback when the class isn't loaded on this lane. */
    function wpc_3p_pattern_ok($p)
    {
        if (class_exists('wps_ic_js_delay_v3') && method_exists('wps_ic_js_delay_v3', 'wpc_io_pattern_ok')) {
            return wps_ic_js_delay_v3::wpc_io_pattern_ok($p);
        }
        return is_string($p) && strlen(trim($p)) >= 5 && strlen(trim($p)) <= 160
            && !preg_match('/jquery|cookie|consent|gdpr|cmplz|complianz|borlabs|wp-i18n|wp-polyfill/i', $p);
    }
}

if (!function_exists('wpc_auto_3p_lane_set')) {
    /** Per-vendor lane store: {host: {lane, match[], ts, src}} — single non-autoload
     *  option, only-on-change, hard caps. Reversible: emptying restores prior behavior.
     *  Returns 'changed' | 'same' | false (rejected) — callers must branch on it. */
    function wpc_auto_3p_lane_set($host, $lane, $match = [], $src = 'auto')
    {
        try {
            $host = strtolower(trim((string) $host));
            $lane = strtolower(trim((string) $lane));
            if ($host === '' || strlen($host) > 120
                || !in_array($lane, ['delay', 'io', 'report', 'eager'], true)) {
                return false;
            }
            $pats = [];
            foreach (array_slice((array) $match, 0, 6) as $m) {
                if (wpc_3p_pattern_ok($m)) {
                    $pats[] = trim((string) $m);
                }
            }
            // A1: auto-applied lanes require PATH-PROVEN match[] patterns — a bare host
            // is exactly the host-keyed application the amendment forbids (gstatic serves
            // recaptcha AND fonts). No usable patterns → reject; the caller journals it.
            if (empty($pats) && in_array($lane, ['delay', 'io'], true)) {
                return false;
            }
            $lanes = get_option('wpc_auto_3p_lanes');
            if (!is_array($lanes)) {
                $lanes = [];
            }
            $prev = isset($lanes[$host]) ? $lanes[$host] : null;
            $row = ['lane' => $lane, 'match' => array_values(array_unique($pats)), 'ts' => time(), 'src' => substr((string) $src, 0, 40)];
            if (is_array($prev) && ($prev['lane'] ?? '') === $row['lane'] && ($prev['match'] ?? []) === $row['match']) {
                return 'same';
            }
            $lanes[$host] = $row;
            if (count($lanes) > 16) {
                // Evict report/eager (informational) lanes before active delay/io lanes;
                // ts breaks ties — unchanged rows never refresh ts, so lane class must
                // outrank age or the longest-stable ACTIVE lanes evict first.
                uasort($lanes, function ($a, $b) {
                    $w = function ($r) {
                        return in_array((string) ($r['lane'] ?? ''), ['delay', 'io'], true) ? 1 : 0;
                    };
                    return ($w($a) - $w($b)) ?: ((int) ($a['ts'] ?? 0) - (int) ($b['ts'] ?? 0));
                });
                $lanes = array_slice($lanes, -16, null, true);
            }
            update_option('wpc_auto_3p_lanes', $lanes, false);
            if (function_exists('wpc_auto_journal')) {
                wpc_auto_journal('auto-3p-lane', ['host' => $host, 'lane' => $lane, 'n' => count($row['match'])]);
            }
            return 'changed';
        } catch (\Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('wpc_auto_apply_third_parties')) {
    /**
     * Consume the per-vendor third-party block (AUTO-100 §2 artifact shape with match[]
     * verbatim per A1, or the legacy PSI-report {entity, recommended} shape). Lanes route
     * per recommended; the stored options are the single source — reversible.
     */
    function wpc_auto_apply_third_parties($report)
    {
        try {
            if (!apply_filters('wpc_auto_third_parties', true)) { return; }
            if (!is_array($report) || empty($report['third_parties']) || !is_array($report['third_parties'])) { return; }
            $map = apply_filters('wpc_third_party_patterns', [
                'tag manager' => ['googletagmanager.com'],
                'google cdn' => ['recaptcha/api.js', 'gstatic.com/recaptcha'],
                'recaptcha' => ['recaptcha/api.js', 'gstatic.com/recaptcha'],
                'respond' => ['respond.io'],
                'plerdy' => ['plerdy.com'],
                'facebook' => ['connect.facebook.net'],
                'hotjar' => ['hotjar.com'],
                'clarity' => ['clarity.ms'],
                'tawk' => ['tawk.to'],
                'intercom' => ['intercom.io', 'intercomcdn'],
                'crisp' => ['crisp.chat'],
                'hubspot' => ['hs-scripts.com', 'hs-analytics'],
                'doubleclick' => ['doubleclick.net'],
                'analytics' => ['google-analytics.com'],
                'clickmoat' => ['clickmoat.com'],
            ]);
            $stored = (array) get_option('wpc_auto_3p_delay', []);
            $before = $stored;
            $applied = [];
            $unknown = [];
            $lanesChanged = false;
            foreach (array_slice($report['third_parties'], 0, 24) as $tp) {
                if (!is_array($tp)) { continue; }
                $rec = strtolower((string) ($tp['recommended'] ?? ''));
                if ($rec === '' || $rec === 'allow') { continue; }
                $ent  = strtolower((string) ($tp['entity'] ?? ($tp['vendor'] ?? '')));
                $host = strtolower(trim((string) ($tp['host'] ?? '')));
                $vkey = $host !== '' ? $host : $ent;
                if ($vkey === '') { continue; }
                // A1: match[] verbatim when present; the curated entity map as fallback.
                $pats = [];
                foreach (array_slice((array) ($tp['match'] ?? []), 0, 6) as $tm) {
                    if (wpc_3p_pattern_ok($tm)) { $pats[] = trim((string) $tm); }
                }
                $fromMatch356 = !empty($pats);
                if (empty($pats) && $ent !== '') {
                    foreach ($map as $needle => $pp) {
                        if (strpos($ent, $needle) !== false) { $pats = $pp; break; }
                    }
                }
                if ($rec === 'delay-interaction-only') {
                    // A4: interaction-only for EVERY visitor — the io lane.
                    $wpc_lr356 = wpc_auto_3p_lane_set($vkey, 'io', $pats, 'measure');
                    if ($wpc_lr356 === 'changed') { $lanesChanged = true; $applied[$vkey] = $rec; }
                    elseif ($wpc_lr356 === false) { $unknown[] = $vkey; }
                } elseif ($rec === 'delay') {
                    // Service match[] rides the SRC-only delay lane — the flat list feeds
                    // userForceDelay, which content-matches inline scripts (the dm door);
                    // only the curated entity map may still use it (pre-existing shape).
                    if ($fromMatch356) {
                        $wpc_lr356 = wpc_auto_3p_lane_set($vkey, 'delay', $pats, 'measure');
                        if ($wpc_lr356 === 'changed') { $lanesChanged = true; $applied[$vkey] = $rec; }
                        elseif ($wpc_lr356 === false) { $unknown[] = $vkey; }
                    } elseif (empty($pats)) {
                        $unknown[] = $vkey;
                    } else {
                        foreach ($pats as $pat) {
                            if (!in_array($pat, $stored, true)) { $stored[] = $pat; $applied[$vkey] = $rec; }
                        }
                    }
                } elseif ($rec === 'keep-eager') {
                    // dm dependency-graph law: keep-eager JS is per-DEPENDENCY-GRAPH, never
                    // per-name — consent stacks are already built-in keeps at every door;
                    // new vendors journal + surface, never auto-wire into excludes.
                    // A reclassification must also WITHDRAW the vendor's previously-stored
                    // delay patterns, or keep-eager can never undo an earlier delay.
                    $wpc_lr356 = wpc_auto_3p_lane_set($vkey, 'eager', $pats, 'measure');
                    if (!empty($pats)) {
                        $wpc_kept356 = array_values(array_diff($stored, $pats));
                        if ($wpc_kept356 !== array_values($stored)) {
                            $stored = $wpc_kept356;
                        }
                    }
                    if ($wpc_lr356 === 'changed') {
                        // a WITHDRAWAL (io→eager) changes render output — must purge too
                        $lanesChanged = true;
                        if (function_exists('wpc_auto_journal')) {
                            wpc_auto_journal('auto-3p-keep-eager-surfaced', ['host' => $vkey]);
                        }
                    }
                } elseif ($rec === 'review' || $rec === 'facade') {
                    // §2: review is human-lane, never auto-delayed; facade is Phase C.
                    $wpc_lr356 = wpc_auto_3p_lane_set($vkey, 'report', $pats, $rec);
                    if ($wpc_lr356 === 'changed') {
                        $lanesChanged = true; // io→report withdrawal must rotate cached HTML
                        if (function_exists('wpc_auto_journal')) {
                            wpc_auto_journal('auto-3p-' . $rec, ['host' => $vkey, 'kb' => (int) ($tp['kb'] ?? 0), 'est_pts' => (int) ($tp['est_pts'] ?? 0)]);
                        }
                    }
                } else {
                    $unknown[] = $vkey;
                }
            }
            if ($stored !== $before || $lanesChanged) {
                if ($stored !== $before) {
                    update_option('wpc_auto_3p_delay', array_values($stored), false);
                }
                if (function_exists('wpc_auto_journal')) {
                    wpc_auto_journal('auto-3p-apply', ['entities' => array_keys($applied), 'patterns' => count($stored)]);
                }
                // Lane changes only exist on re-rendered HTML (purge scoped to home copies).
                if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')
                    && class_exists('wps_ic_url_key') && function_exists('home_url')) {
                    $hk = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
                    wps_ic_cache_integrations::purgeUrlHtml($hk, home_url('/'), ['context' => 'auto-3p']);
                }
                if (function_exists('wpc_warm_url_queue')) { wpc_warm_url_queue(home_url('/'), 'auto-3p'); }
            }
            if (!empty($unknown) && function_exists('wpc_auto_journal')) {
                wpc_auto_journal('auto-3p-unknown', ['entities' => array_slice(array_unique($unknown), 0, 8)]);
            }
        } catch (\Throwable $e) {
        }
    }
    if (function_exists('add_filter')) {
        // Legacy flat delay list only — lane patterns deliberately do NOT ride this
        // filter (it lands in userForceDelay, which content-matches inline scripts
        // ahead of every consent keep — the dm class). Lanes travel the two SRC-only
        // filters below, consumed exclusively by the v3 engine.
        add_filter('wpc_builtin_force_delay', function ($list) {
            $auto = get_option('wpc_auto_3p_delay', []);
            return (is_array($auto) && !empty($auto))
                ? array_values(array_unique(array_merge((array) $list, $auto)))
                : $list;
        });
        add_filter('wpc_builtin_interaction_only', function ($list) {
            $lanes = get_option('wpc_auto_3p_lanes');
            if (is_array($lanes)) {
                foreach ($lanes as $v) {
                    if (!is_array($v) || (string) ($v['lane'] ?? '') !== 'io') { continue; }
                    foreach ((array) ($v['match'] ?? []) as $m) {
                        if (is_string($m) && $m !== '') { $list[] = $m; }
                    }
                }
            }
            return array_values(array_unique((array) $list));
        });
        add_filter('wpc_builtin_lane_delay', function ($list) {
            $lanes = get_option('wpc_auto_3p_lanes');
            if (is_array($lanes)) {
                foreach ($lanes as $v) {
                    if (!is_array($v) || (string) ($v['lane'] ?? '') !== 'delay') { continue; }
                    foreach ((array) ($v['match'] ?? []) as $m) {
                        if (is_string($m) && $m !== '') { $list[] = $m; }
                    }
                }
            }
            return array_values(array_unique((array) $list));
        });
    }
}

if (!function_exists('wpc_dress_check')) {
    // DRESS-CHECK SENTINEL: a transitional cached homepage (bare crit or stale-version
    // loader from a mid-purge render) must never survive to the next PSI run — detect
    // and re-dress once calm, capped 4/day
    function wpc_dress_check()
    {
        try {
            if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
                return;
            }
            if (!function_exists('wpc_cache_first_enabled') || !wpc_cache_first_enabled()
                || !class_exists('wps_ic_url_key') || !defined('WPS_IC_CACHE')) {
                return;
            }
            $key = (new wps_ic_url_key())->setup(home_url('/'));
            if (empty($key)) {
                return;
            }
            $f = rtrim(WPS_IC_CACHE, '/') . '/' . $key . '/index.html_gzip';
            if (!@is_readable($f)) {
                return; // nothing cached — the warm lane owns first fill
            }
            $html = function_exists('gzdecode') ? @gzdecode((string) @file_get_contents($f)) : '';
            if (!is_string($html) || $html === '') {
                return;
            }
            $ver = defined('WPC_PLUGIN_VERSION') ? (string) WPC_PLUGIN_VERSION : '';
            $bare  = strpos($html, 'wpc-critical-css') === false;
            $stale = $ver !== '' && strpos($html, 'delay-v3-loader-') !== false
                && strpos($html, 'delay-v3-loader-' . $ver) === false;
            if (!$bare && !$stale) {
                return;
            }
            $day = gmdate('Ymd');
            if (get_option('wpc_dress_fix_d') !== $day) {
                update_option('wpc_dress_fix_d', $day, false);
                update_option('wpc_dress_fix_n', 0, false);
            }
            $n = (int) get_option('wpc_dress_fix_n');
            if ($n >= 4) {
                return;
            }
            update_option('wpc_dress_fix_n', $n + 1, false);
            if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                wps_ic_cache_integrations::purgeUrlHtml($key, home_url('/'), ['context' => 'dress-check']);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('dress-fix', $key, '', ['bare' => $bare ? 1 : 0, 'stale' => $stale ? 1 : 0, 'n' => $n + 1]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_v2_cdn_liveness_cron', 'wpc_dress_check', 20);
    add_action('wpc_sitechange_trailing', function () {
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_dress_verify')) {
            wp_schedule_single_event(time() + 180, 'wpc_dress_verify');
        }
    }, 20);
    add_action('wpc_dress_verify', 'wpc_dress_check');
}

if (!function_exists('wpc_box_debug_report')) {
    /**
     * Box-pressure diagnosis for hosts with no shell (busy). Answers "what is consuming this
     * machine" from inside PHP: real runnable-process count, a bounded /proc snapshot, and the
     * WP-cron backlog — an overdue/duplicated cron queue is the classic cause of a pegged box
     * with healthy per-render numbers, and it is invisible from the front end.
     * Read-only, bounded, admin- or token-gated. Never called on a visitor path.
     */
    function wpc_box_debug_report()
    {
        $tok = isset($_GET['t']) ? (string) $_GET['t'] : '';
        $ok  = (function_exists('current_user_can') && current_user_can('manage_options'));
        if (!$ok && function_exists('wpc_v2_get_apikey')) {
            $k = (string) wpc_v2_get_apikey();
            $ok = ($k !== '' && $tok !== '' && hash_equals(md5('wpc-dbg:' . $k), $tok));
        }
        if (!$ok) { status_header(403); exit('forbidden'); }

        $o = [];
        $o[] = '=== WPC BOX DEBUG (plugin ' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?') . ') ===';

        // &purges=1 — the purge ledger. Every purge is a self-inflicted MISS (1.587s TTFB
        // against 0.096s on a HIT), so "how often, from which line, and why" is an
        // operational question. &purges=1&download=1 serves the raw JSONL for offline audit.
        if (!empty($_GET['purges']) && function_exists('wpc_purge_ledger_report')) {
            if (!empty($_GET['download']) && function_exists('wpc_purge_ledger_file')) {
                $wpc_lf = wpc_purge_ledger_file();
                if ($wpc_lf !== '' && @is_readable($wpc_lf)) {
                    nocache_headers();
                    header('Content-Type: application/x-ndjson; charset=utf-8');
                    header('Content-Disposition: attachment; filename="wpc-purge-ledger-'
                        . preg_replace('/[^a-z0-9.-]/i', '-', (string) wp_parse_url(home_url(), PHP_URL_HOST))
                        . '-' . gmdate('Ymd-Hi') . '.jsonl"');
                    @readfile($wpc_lf);
                    if (@is_readable($wpc_lf . '.1')) { @readfile($wpc_lf . '.1'); }
                    exit;
                }
                status_header(404);
                exit('no ledger file (dir not writable — falling back to the option ring buffer)');
            }
            $wpc_pr = wpc_purge_ledger_report();
            $o[] = '';
            $o[] = '--- PURGE LEDGER ---';
            if (empty($wpc_pr['rows'])) {
                $o[] = '  (empty — no purge has run since this build was installed)';
            } else {
                $wpc_span = $wpc_pr['oldest'] ? max(1, time() - $wpc_pr['oldest']) : 1;
                $o[] = sprintf('  rows=%d spanning %s   last_1h=%d   last_24h=%d   failed=%d',
                    $wpc_pr['rows'], human_time_diff(time() - $wpc_span, time()),
                    $wpc_pr['last_1h'], $wpc_pr['last_24h'], $wpc_pr['failed']);
                $o[] = sprintf('  median gap %ds   calls sharing one second: %d%s',
                    $wpc_pr['median_gap_s'], $wpc_pr['same_second'],
                    $wpc_pr['same_second'] > 0 ? '   <-- more than one call per purge event' : '');
                $o[] = '  file: ' . ($wpc_pr['file'] !== '' ? $wpc_pr['file'] : '(option ring buffer — log dir not writable)');
                $o[] = '  download: add &download=1 to this URL';
                $wpc_tbl = function ($label, $rows, &$out, $note = '') {
                    $out[] = '  ' . $label . ':';
                    foreach ((array) $rows as $wpc_k => $wpc_v) {
                        $out[] = sprintf('    %-46s %d%s', $wpc_k, $wpc_v,
                            $note !== '' && (strpos($wpc_k, 'purge_everything') !== false
                                || strpos($wpc_k, ':reset_full') !== false
                                || strpos($wpc_k, ':hosts') !== false
                                || strpos($wpc_k, 'object-cache') !== false) ? '   <-- ' . $note : '');
                    }
                };
                $wpc_tbl('by surface', $wpc_pr['by_surface'], $o);
                $wpc_tbl('by method', $wpc_pr['by_method'], $o, 'widest kind');
                $wpc_tbl('TRIGGERED FROM (file:line)', $wpc_pr['by_line'], $o);
                $wpc_tbl('by calling function', $wpc_pr['by_caller'], $o);
                $wpc_tbl('WHY (WordPress hook stack)', $wpc_pr['by_hook'], $o);
                $o[] = '  trigger: ' . json_encode($wpc_pr['by_trigger']);
                if (!empty($_GET['raw'])) {
                    $o[] = '  --- last 40 raw ---';
                    foreach (array_slice(wpc_purge_ledger_rows(), -40) as $wpc_row) {
                        $o[] = sprintf('    %s %-13s %-16s n=%-3d ok=%d %-5s %-22s %-30s %s',
                            date('m-d H:i:s', (int) $wpc_row['t']),
                            isset($wpc_row['sf']) ? $wpc_row['sf'] : '?',
                            isset($wpc_row['m']) ? $wpc_row['m'] : '?',
                            isset($wpc_row['n']) ? $wpc_row['n'] : 0,
                            isset($wpc_row['ok']) ? $wpc_row['ok'] : 0,
                            isset($wpc_row['src']) ? $wpc_row['src'] : '?',
                            isset($wpc_row['at']) ? $wpc_row['at'] : '',
                            isset($wpc_row['hk']) ? substr((string) $wpc_row['hk'], 0, 30) : '',
                            isset($wpc_row['u']) ? $wpc_row['u'] : '');
                    }
                }
            }
            $o[] = '';
        }

        // &warm=off — the brake for a box the warmer is holding down. Each wpc_url_warm event is an
        // UNCACHED render; a queue of them on a small box self-sustains (warm renders spawn cron on
        // page loads, which runs more warm). Disabling the scheduler alone leaves the queue to drain,
        // so this also clears what is already scheduled. Reversible with &warm=on.
        // &safe=on / &safe=off — the operator lever. LOUD by design: printed on every debug run
        // with how long it has been on, because a stand-down left on by accident is its own outage.
        $wpc_sa473 = isset($_GET['safe']) ? strtolower((string) $_GET['safe']) : '';
        if ($wpc_sa473 === 'on' || $wpc_sa473 === 'off') {
            if ($wpc_sa473 === 'on') {
                update_option('wpc_safe_mode', 1, false);
                update_option('wpc_safe_mode_at', time(), false);
            } else {
                update_option('wpc_safe_mode', 0, false);
                delete_option('wpc_safe_mode_at');
            }
            $o[] = '*** SAFE MODE ' . strtoupper($wpc_sa473) . ($wpc_sa473 === 'on'
                ? ' — every background lane stands down (scheduling AND draining). Front end unchanged.'
                : ' — background lanes resume') . ' ***';
            $o[] = '';
        }
        $wpc_smat473 = (int) get_option('wpc_safe_mode_at', 0);
        $o[] = 'SAFE MODE: ' . (get_option('wpc_safe_mode', 0)
            ? '*** ON *** — all background lanes stood down'
                . ($wpc_smat473 ? ' since ' . human_time_diff($wpc_smat473, time()) . ' ago' : '')
                . ' — front end serves normally'
            : 'off') . '   [&safe=on / &safe=off]';

        $wpc_wa = isset($_GET['warm']) ? strtolower((string) $_GET['warm']) : '';
        if ($wpc_wa === 'off' || $wpc_wa === 'on') {
            $cleared = 0;
            if ($wpc_wa === 'off') {
                update_option('wpc_url_warm_on_purge', 0, false);
                update_option('wpc_warm_paused_at', time(), false);
                $ca = function_exists('_get_cron_array') ? _get_cron_array() : [];
                if (is_array($ca)) {
                    foreach ($ca as $ts => $hooks) {
                        if (!is_array($hooks)) { continue; }
                        foreach ($hooks as $hook => $evs) {
                            if ($hook !== 'wpc_url_warm' || !is_array($evs)) { continue; }
                            foreach ($evs as $ev) {
                                $args = (is_array($ev) && isset($ev['args'])) ? $ev['args'] : [];
                                if (wp_unschedule_event((int) $ts, 'wpc_url_warm', $args) !== false) { $cleared++; }
                            }
                        }
                    }
                }
                delete_transient('wpc_warm_batch_lock');
            } else {
                update_option('wpc_url_warm_on_purge', 1, false);
                delete_option('wpc_warm_paused_at');
            }
            $o[] = '*** WARM ' . strtoupper($wpc_wa) . ($wpc_wa === 'off' ? ' — cleared ' . $cleared . ' queued wpc_url_warm events' : ' — scheduler re-enabled') . ' ***';
            $o[] = '';
        }
        $wpc_wp = (int) get_option('wpc_warm_paused_at', 0);
        $o[] = 'warm scheduler: ' . (get_option('wpc_url_warm_on_purge', 1) ? 'ENABLED' : 'PAUSED'
             . ($wpc_wp ? ' (' . human_time_diff($wpc_wp, time()) . ' ago)' : '')) . '   [&warm=off / &warm=on]';

        $cores = 0;
        if (@is_readable('/proc/cpuinfo')) {
            $cores = substr_count((string) @file_get_contents('/proc/cpuinfo'), 'processor');
        }
        $la = function_exists('sys_getloadavg') ? sys_getloadavg() : [];
        $raw = trim((string) @file_get_contents('/proc/loadavg'));
        $o[] = 'cores: ' . ($cores ?: '?') . ' | loadavg: ' . (is_array($la) ? implode(' ', array_map(function ($x) { return round($x, 2); }, $la)) : 'n/a');
        // 4th field of /proc/loadavg is runnable/total — the number that says HOW MANY things want CPU
        if ($raw !== '' && preg_match('#(\d+)/(\d+)#', $raw, $m)) {
            $o[] = 'runnable/total procs: ' . $m[1] . '/' . $m[2] . '   (runnable >> cores = saturated)';
        }
        $o[] = 'raw /proc/loadavg: ' . ($raw !== '' ? $raw : 'unreadable (containerised)');

        // Bounded process snapshot — who is actually on the box
        $procs = [];
        $dh = @opendir('/proc');
        if ($dh) {
            $n = 0;
            while (($e = readdir($dh)) !== false) {
                if ($n++ > 4000) { break; }
                if (!ctype_digit($e)) { continue; }
                $cl = (string) @file_get_contents('/proc/' . $e . '/cmdline', false, null, 0, 160);
                if ($cl === '') { continue; }
                $cl = trim(str_replace("\0", ' ', $cl));
                if ($cl === '') { continue; }
                $key = substr($cl, 0, 90);
                if (!isset($procs[$key])) { $procs[$key] = 0; }
                $procs[$key]++;
            }
            closedir($dh);
        }
        if ($procs) {
            arsort($procs);
            $o[] = '';
            $o[] = '--- processes by count (top 15) ---';
            $i = 0;
            foreach ($procs as $cmd => $c) {
                if ($i++ >= 15) { break; }
                $o[] = sprintf('  %3dx  %s', $c, $cmd);
            }
        } else {
            $o[] = '';
            $o[] = '--- /proc not readable (open_basedir / container) — use host monitoring ---';
        }

        // WP-cron backlog: overdue events are the classic pegged-box cause and are invisible outside
        $o[] = '';
        $o[] = '--- WP-CRON backlog ---';
        $cron = function_exists('_get_cron_array') ? _get_cron_array() : [];
        $now = time();
        $tot = 0; $due = 0; $byhook = []; $oldest = 0;
        if (is_array($cron)) {
            foreach ($cron as $ts => $hooks) {
                if (!is_array($hooks)) { continue; }
                foreach ($hooks as $hook => $ev) {
                    $c = is_array($ev) ? count($ev) : 1;
                    $tot += $c;
                    if (!isset($byhook[$hook])) { $byhook[$hook] = 0; }
                    $byhook[$hook] += $c;
                    if ((int) $ts <= $now) {
                        $due += $c;
                        if (!$oldest || (int) $ts < $oldest) { $oldest = (int) $ts; }
                    }
                }
            }
        }
        $o[] = 'events: ' . $tot . ' total | ' . $due . ' OVERDUE'
             . ($oldest ? ' | oldest overdue: ' . human_time_diff($oldest, $now) . ' ago' : '');
        $o[] = 'DISABLE_WP_CRON: ' . ((defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) ? 'true (external cron)' : 'false (spawns on page loads)');
        $o[] = 'cron lock: ' . ((string) get_transient('doing_cron') !== '' ? 'HELD' : 'clear');
        arsort($byhook);
        $i = 0;
        foreach ($byhook as $hook => $c) {
            if ($i++ >= 12) { break; }
            $o[] = sprintf('  %4dx  %s%s', $c, $hook, (strpos($hook, 'wpc') === 0 || strpos($hook, 'wps_ic') === 0) ? '   <-- OURS' : '');
        }

        // LCP preload correctness. Populated ONLY when a real browser reported that the element
        // it chose as LCP was not the one we preloaded — a wrong preload spends the LCP's
        // bandwidth at fetchpriority="high" on the wrong resource. Empty here is the healthy
        // state; entries name the stem we promised vs the stem the browser actually used.
        $wpc_mxr440 = get_option('wpc_lcp_preload_mismatch', []);
        $o[] = '';
        if (is_array($wpc_mxr440) && !empty($wpc_mxr440)) {
            $o[] = 'LCP preload MISMATCH: ' . count($wpc_mxr440) . ' distinct   <-- preloading the wrong image';
            $wpc_mi440 = 0;
            foreach (array_reverse($wpc_mxr440) as $wpc_me440) {
                if ($wpc_mi440++ >= 5 || !is_array($wpc_me440)) { break; }
                $o[] = sprintf('  %3dx  %s  want=%s  got=%s',
                    isset($wpc_me440['n']) ? (int) $wpc_me440['n'] : 1,
                    isset($wpc_me440['u']) ? $wpc_me440['u'] : '?',
                    isset($wpc_me440['want']) ? $wpc_me440['want'] : '?',
                    isset($wpc_me440['got']) ? $wpc_me440['got'] : '(none)');
            }
        } else {
            // "none reported" alone is ambiguous — say whether a browser actually confirmed.
            $wpc_okr447 = get_option('wpc_lcp_preload_ok', []);
            if (is_array($wpc_okr447) && !empty($wpc_okr447['t'])) {
                $o[] = 'LCP preload: VERIFIED CORRECT by ' . (int) $wpc_okr447['n']
                     . ' browser report(s), last ' . human_time_diff((int) $wpc_okr447['t']) . ' ago';
            } else {
                $o[] = 'LCP preload: no mismatch reported — but NOT yet confirmed by any browser'
                     . '   <-- silence, not proof (1% sampled confirmation)';
            }
        }

        // Autoloaded options — a bloated autoload tax is paid on EVERY request
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb)) {
            $al = $wpdb->get_row("SELECT COUNT(*) n, ROUND(SUM(LENGTH(option_value))/1024) kb FROM {$wpdb->options} WHERE autoload='yes'");
            if ($al) {
                $o[] = '';
                $o[] = 'autoloaded options: ' . (int) $al->n . ' rows, ' . (int) $al->kb . ' KB'
                     . ((int) $al->kb > 800 ? '   <-- BLOATED (paid on every request)' : '');
            }
        }

        // MySQL saturation — readable from PHP where /proc is not. Threads_running is the real
        // signal (Threads_connected counts idle ones); a processlist deep in 'Sending data' means
        // the load is DB-bound, not PHP-bound, and no amount of cron braking will touch it.
        if (isset($wpdb) && is_object($wpdb)) {
            $o[] = '';
            $o[] = '--- MYSQL ---';
            foreach (['Threads_running' => 'running (vs cores!)', 'Threads_connected' => 'connected',
                      'Max_used_connections' => 'max used', 'Slow_queries' => 'slow (since start)',
                      'Aborted_clients' => 'aborted clients'] as $var => $label) {
                $r = $wpdb->get_row($wpdb->prepare('SHOW GLOBAL STATUS LIKE %s', $var));
                if ($r && isset($r->Value)) { $o[] = sprintf('  %-22s %s', $label, $r->Value); }
            }
            $pl = $wpdb->get_results('SHOW FULL PROCESSLIST');
            if (is_array($pl)) {
                $states = []; $long = []; $active = 0;
                foreach ($pl as $p) {
                    $cmd = isset($p->Command) ? (string) $p->Command : '';
                    if ($cmd === 'Sleep' || $cmd === '') { continue; }
                    $active++;
                    $st = isset($p->State) && $p->State !== '' ? (string) $p->State : $cmd;
                    if (!isset($states[$st])) { $states[$st] = 0; }
                    $states[$st]++;
                    if ((int) ($p->Time ?? 0) >= 2) {
                        $long[] = (int) $p->Time . 's  ' . substr(preg_replace('/\s+/', ' ', (string) ($p->Info ?? $st)), 0, 110);
                    }
                }
                $o[] = '  ACTIVE (non-sleep) queries: ' . $active . ' of ' . count($pl) . ' conns'
                     . ($active > 8 ? '   <-- DB-BOUND: this is the load' : '');
                arsort($states);
                $i = 0;
                foreach ($states as $st => $c) { if ($i++ >= 6) { break; } $o[] = sprintf('    %3dx  %s', $c, $st); }
                if ($long) {
                    $o[] = '  queries >=2s:';
                    foreach (array_slice($long, 0, 5) as $l) { $o[] = '    ' . $l; }
                }
            }
        }

        // opcache: disabled or full means PHP recompiles this whole plugin on every request — the
        // single biggest silent CPU multiplier on a big codebase, and invisible from the front end.
        $o[] = '';
        $o[] = '--- PHP / OPCACHE ---';
        $o[] = '  php ' . PHP_VERSION . ' | sapi ' . PHP_SAPI . ' | memory_limit ' . ini_get('memory_limit')
             . '  (flat 1024M x N workers invites swap on a small box)';
        // opcache_get_status() returns false for TWO reasons — genuinely disabled, OR
        // opcache.restrict_api set (a common hardening) while opcache runs perfectly. Relying on
        // it made me report "disabled" from a silent false. extension_loaded()/ini_get() are not
        // affected by restrict_api, so ask those first and never guess again.
        $oc_ext = extension_loaded('Zend OPcache') || extension_loaded('opcache');
        $oc_on  = (string) ini_get('opcache.enable');
        $oc_res = (string) ini_get('opcache.restrict_api');
        $o[] = '  opcache extension: ' . ($oc_ext ? 'loaded' : '*** NOT LOADED ***')
             . ' | opcache.enable=' . ($oc_on === '' ? '?' : $oc_on)
             . ' | enable_cli=' . ((string) ini_get('opcache.enable_cli'))
             . ' | restrict_api=' . ($oc_res === '' ? 'off' : $oc_res);
        if ($oc_ext && ($oc_on === '1' || $oc_on === 'On' || $oc_on === 'on')) {
            $o[] = '  => opcache IS ON' . ($oc_res !== '' ? ' (status API restricted — that is why it read empty, NOT a fault)' : '');
        } elseif ($oc_ext) {
            $o[] = '  => *** opcache LOADED BUT DISABLED — every request recompiles every PHP file ***';
        } else {
            $o[] = '  => *** opcache ABSENT — every request recompiles every PHP file ***';
        }
        if (function_exists('opcache_get_status')) {
            $oc = @opcache_get_status(false);
            if (!is_array($oc)) {
                $o[] = '  (status API returned false' . ($oc_res !== '' ? ' — expected, restrict_api is set' : ' — disabled or restricted') . ')';
            }
            if (is_array($oc)) {
                $hits = (int) ($oc['opcache_statistics']['hits'] ?? 0);
                $miss = (int) ($oc['opcache_statistics']['misses'] ?? 0);
                $rate = ($hits + $miss) > 0 ? round($hits / ($hits + $miss) * 100, 1) : 0;
                $used = (int) ($oc['memory_usage']['used_memory'] ?? 0);
                $free = (int) ($oc['memory_usage']['free_memory'] ?? 0);
                $o[] = '  opcache: ' . (!empty($oc['opcache_enabled']) ? 'ON' : '*** OFF — every request recompiles ***')
                     . ' | hit rate ' . $rate . '%' . ($rate < 95 && ($hits + $miss) > 1000 ? '  <-- THRASHING' : '')
                     . ' | mem ' . round($used / 1048576) . 'M used / ' . round($free / 1048576) . 'M free'
                     . (!empty($oc['cache_full']) ? '  *** CACHE FULL ***' : '')
                     . ' | restarts oom=' . (int) ($oc['opcache_statistics']['oom_restarts'] ?? 0);
            }
        } else {
            $o[] = '  opcache: unavailable (not loaded) — every request recompiles every PHP file';
        }
        $o[] = '';
        $o[] = '--- FONT remote_range STATE ---';
        $wpc_rrm_dbg = get_option('wpc_font_remote_ranges', []);
        if (is_array($wpc_rrm_dbg) && $wpc_rrm_dbg) {
            foreach ($wpc_rrm_dbg as $wpc_k_dbg => $wpc_v_dbg) { $o[] = '  MAP  ' . $wpc_k_dbg . ' => ' . $wpc_v_dbg; }
        } else {
            $o[] = '  MAP  *** EMPTY *** (consumer emits no unicode-range at all when empty)';
        }
        $wpc_cd_dbg = get_option('wpc_fonts_consume_diag', []);
        if (is_array($wpc_cd_dbg) && !empty($wpc_cd_dbg['rows'])) {
            $o[] = '  last consume: ' . human_time_diff((int) ($wpc_cd_dbg['t'] ?? 0), time()) . ' ago via ' . (string) ($wpc_cd_dbg['src'] ?? '?');
            foreach ((array) $wpc_cd_dbg['rows'] as $wpc_r_dbg) { $o[] = '    ' . substr((string) $wpc_r_dbg, 0, 200); }
        } else {
            $o[] = '  last consume: no diag recorded yet (no consume since .431)';
        }
        $o[] = '  object cache: ' . ((function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache())
             ? 'EXTERNAL (redis/memcached)' : 'NONE — every transient is an options-table write');

        nocache_headers();
        header('Content-Type: text/plain; charset=utf-8');
        echo implode("\n", $o) . "\n";
        exit;
    }
    add_action('wp_ajax_wpc_box_debug', 'wpc_box_debug_report');
    add_action('wp_ajax_nopriv_wpc_box_debug', 'wpc_box_debug_report');
}

if (!function_exists('wpc_img_trace_emit')) {
    /**
     * `?wpc_img_trace=1` — in-page image-lane tracer. Front-end only, query-gated, never for visitors.
     * Exists because the broken-image flash is invisible to HAR/netlog/external probing: a decode
     * failure or an attribute write produces no distinguishable network entry. This runs BEFORE any
     * other script, so it sees every write and every error event including ones an inline onerror
     * would otherwise swallow.
     */
    function wpc_img_trace_emit()
    {
        if (empty($_GET['wpc_img_trace']) || is_admin()) { return; }
        echo '<script id="wpc-img-trace">/*wpc-arm-sentinel*/(function(){' .
        'var L=function(t,c,m){try{console.log("%c[IMGTRACE] "+t,"background:"+c+";color:#fff;padding:1px 4px",m||"")}catch(e){}};' .
        'var N=function(el){return (el&&(el.className||"")+"").slice(0,40)||"(no class)"};' .
        // every error event, capture phase — fires before any inline onerror
        'document.addEventListener("error",function(e){var t=e.target;if(!t||t.tagName!=="IMG")return;' .
        'L("ERROR","#c00",{cls:N(t),src:(t.currentSrc||t.getAttribute("src")||"(none)"),' .
        'complete:t.complete,natW:t.naturalWidth,srcset:!!t.getAttribute("srcset")});},true);' .
        // src / srcset writes, with stack
        'var D=Object.getOwnPropertyDescriptor(HTMLImageElement.prototype,"src");' .
        'try{Object.defineProperty(HTMLImageElement.prototype,"src",{get:function(){return D.get.call(this)},' .
        'set:function(v){if(!v||v==="null"||v==="undefined"){L("WRITE-EMPTY-src","#c00",{cls:N(this),val:v,stack:(new Error()).stack})}' .
        'return D.set.call(this,v)}});}catch(e){}' .
        'var SA=Element.prototype.setAttribute;Element.prototype.setAttribute=function(n,v){' .
        'if(this.tagName==="IMG"&&(n==="src"||n==="srcset")&&!v){L("WRITE-EMPTY-"+n,"#c00",{cls:N(this),stack:(new Error()).stack})}' .
        'return SA.apply(this,arguments)};' .
        'var RA=Element.prototype.removeAttribute;Element.prototype.removeAttribute=function(n){' .
        'if(this.tagName==="IMG"&&(n==="src"||n==="srcset")){L("REMOVE-"+n,"#a50",{cls:N(this),stack:(new Error()).stack})}' .
        'return RA.apply(this,arguments)};' .
        // aggregate snapshots: how many images are actually broken vs merely unloaded
        // An image can be perfectly LOADED and still paint nothing (opacity/visibility/zero-rect,
        // or a reveal animation whose selector was dropped from the extracted CSS). That state is
        // invisible to error events, HAR, and naturalWidth — it needs computed style. Walks 4
        // ancestors because the hiding rule is often on a wrapper, not the <img>.
        'var WHY=function(m){try{var s=getComputedStyle(m),r=m.getBoundingClientRect(),w=[];' .
        'if(+s.opacity===0)w.push("opacity:0");' .
        'if(s.visibility==="hidden")w.push("visibility:hidden");' .
        'if(s.display==="none")w.push("display:none");' .
        'if(r.width<2||r.height<2)w.push("rect:"+Math.round(r.width)+"x"+Math.round(r.height));' .
        'if(s.animationName&&s.animationName!=="none")w.push("anim:"+s.animationName+"/"+s.animationPlayState+"/"+s.animationFillMode);' .
        'var p=m.parentElement,d=0;while(p&&d<4){var q=getComputedStyle(p);' .
        'if(+q.opacity===0)w.push("ANCESTOR"+d+" opacity:0 ."+((p.className||"")+"").slice(0,30));' .
        'if(q.visibility==="hidden")w.push("ANCESTOR"+d+" visibility:hidden");' .
        'if(q.display==="none")w.push("ANCESTOR"+d+" display:none");' .
        'if(q.animationName&&q.animationName!=="none")w.push("ANCESTOR"+d+" anim:"+q.animationName+"/"+q.animationPlayState);' .
        'p=p.parentElement;d++}return w;}catch(e){return["(err)"]}};' .
        'var SNAP=function(w){try{var i=document.images,n=i.length,br=0,ld=0,pend=0,inv=0,ex=[],iex=[];' .
        'for(var k=0;k<n;k++){var m=i[k];if(m.complete&&m.naturalWidth===0){br++;if(ex.length<4)ex.push({cls:N(m),src:(m.currentSrc||m.getAttribute("src")||"(none)").slice(-60)})}' .
        'else if(m.complete){ld++;var y=WHY(m);if(y.length){inv++;if(iex.length<6)iex.push({cls:N(m),why:y.join(" | "),src:(m.currentSrc||"(none)").slice(-46)})}}else{pend++}}' .
        'L("SNAP@"+w,(br||inv)?"#c00":"#070",{imgs:n,BROKEN:br,loaded:ld,pending:pend,"LOADED-BUT-INVISIBLE":inv,examples:ex,invisible:iex});}catch(e){}};' .
        'document.addEventListener("DOMContentLoaded",function(){SNAP("DOMContentLoaded");setTimeout(function(){SNAP("DCL+500ms")},500)});' .
        'window.addEventListener("load",function(){SNAP("load");setTimeout(function(){SNAP("load+1s")},1000);setTimeout(function(){SNAP("load+3s")},3000)});' .
        'var t0=Date.now();var iv=setInterval(function(){SNAP("t+"+(Date.now()-t0)+"ms")},250);setTimeout(function(){clearInterval(iv)},3000);' .
        'L("ARMED","#070","tracing "+document.images.length+" imgs at parse time");' .
        '})();</script>' . "\n";
    }
    add_action('wp_head', 'wpc_img_trace_emit', -PHP_INT_MAX);
}

if (!function_exists('wpc_lcp_trace_script')) {
    /**
     * `?wpc_lcp_trace=1` — what actually occupies the FCP -> LCP gap. Front-end only, query-gated.
     * Exists because the LCP SUBPARTS do not explain the metric: on busy they sum to ~320ms against
     * a 2,100ms score, so the gap is neither the image fetch nor its discovery. This reports the
     * real paint timeline plus everything competing inside the window — network AND main thread —
     * because a 1s gap with a 240ms resource is by elimination style/layout, and guessing which is
     * how four theories died today.
     * v7.10.717 — split into a builder so the armed-copy serve lane injects the SAME script.
     */
    function wpc_lcp_trace_script()
    {
        $wpc_snd452 = !empty($_GET['send']) ? '1' : '';
        $wpc_ep452  = function_exists('admin_url') ? admin_url('admin-ajax.php') : '';
        return '<script id="wpc-lcp-trace">/*wpc-arm-sentinel*/(function(){' .
        'var SEND=' . ($wpc_snd452 !== '' ? '1' : '0') . ',EP=' . json_encode($wpc_ep452) . ';' .
        'var L=function(t,c,m){try{console.log("%c[LCPTRACE] "+t,"background:"+c+";color:#fff;padding:1px 4px",m||"")}catch(e){}};' .
        'var fcp=0,lcp=0,lel=null,lurl="",tasks=[],done=0;' .
        'var hum=function(){try{return (window.__wpcEngaged||window.__wpcHuman)?1:0}catch(e){return-1}};' .
        'var P=function(t,cb){try{var o=new PerformanceObserver(cb);o.observe({type:t,buffered:true});return o}catch(e){return null}};' .
        // paint + LCP + long tasks, all buffered so nothing before arm is lost
        'P("paint",function(l){l.getEntries().forEach(function(e){if(e.name==="first-contentful-paint")fcp=e.startTime})});' .
        'var dbt=null,lrect=null;' .
        'P("largest-contentful-paint",function(l){l.getEntries().forEach(function(e){lcp=e.startTime;lel=e.element||null;lurl=e.url||(e.element&&e.element.currentSrc)||"";' .
        'try{var rr=e.element&&e.element.getBoundingClientRect?e.element.getBoundingClientRect():null;' .
        'lrect=rr?{top:Math.round(rr.top),h:Math.round(rr.height),w:Math.round(rr.width),' .
        'vh:Math.round(window.innerHeight||0),sy:Math.round(window.pageYOffset||0),' .
        'inView:(rr.top < (window.innerHeight||0) && rr.top+rr.height > 0)?1:0}:null}catch(x){lrect=null}});' .
        'try{if(dbt)clearTimeout(dbt);dbt=setTimeout(function(){if(typeof fire==="function")fire()},900)}catch(e){}});' .
        'P("longtask",function(l){l.getEntries().forEach(function(e){tasks.push([Math.round(e.startTime),Math.round(e.duration),(e.attribution&&e.attribution[0]&&e.attribution[0].name)||"-"])})});' .
        'var R=function(){try{return performance.getEntriesByType("resource")||[]}catch(e){return[]}};' .
        'var rep=function(){if(done)return;done=1;' .
        'var gap=Math.round(lcp-fcp);' .
        'L("PAINT","#059",{FCP:Math.round(fcp)+"ms",LCP:Math.round(lcp)+"ms",GAP:gap+"ms",' .
        'lcpEl:lel?(lel.tagName+"."+((lel.className||"")+"").split(" ")[0]):"-",lcpUrl:lurl.slice(-46)});' .
        // the LCP resource, phase by phase — proves whether the fetch is even in the window
        'var lr=null;R().forEach(function(r){if(lurl&&r.name===lurl)lr=r});' .
        'L("LCP ELEMENT BOX",(lrect&&!lrect.inView)?"#c00":"#059",lrect||"(no element - text LCP?)");' .
        'if(lrect&&!lrect.inView){L("ANOMALY","#c00","the LCP element was OUTSIDE the viewport when chosen — a below-fold lazy image winning LCP is why some sessions read 14-17s")}' .
        'if(lr){L("LCP RESOURCE","#059",{start:Math.round(lr.startTime),dns:Math.round(lr.domainLookupEnd-lr.domainLookupStart),' .
        'connect:Math.round(lr.connectEnd-lr.connectStart),ttfb:Math.round(lr.responseStart-lr.requestStart),' .
        'download:Math.round(lr.responseEnd-lr.responseStart),end:Math.round(lr.responseEnd),' .
        'kb:Math.round((lr.transferSize||0)/1024),afterLCP:(lr.responseEnd>lcp)});}' .
        'else{L("LCP RESOURCE","#a50","no resource entry matched "+lurl.slice(-40)+" (text LCP, or cross-origin without Timing-Allow-Origin)");}' .
        // everything whose fetch overlapped the gap = the network side of the answer
        'var ov=[];R().forEach(function(r){if(r.responseEnd>fcp&&r.startTime<lcp)ov.push({res:r.name.split("/").pop().slice(0,38),' .
        'start:Math.round(r.startTime),end:Math.round(r.responseEnd),kb:Math.round((r.transferSize||0)/1024),via:r.initiatorType})});' .
        'ov.sort(function(a,b){return a.start-b.start});' .
        'L("IN THE GAP: network ("+ov.length+")",ov.length?"#a50":"#070",ov.slice(0,14));' .
        // main-thread side: long tasks inside the window, and how much of the gap they own
        'var g=tasks.filter(function(t){return t[0]+t[1]>fcp&&t[0]<lcp});' .
        'var owned=0;g.forEach(function(t){owned+=Math.min(t[0]+t[1],lcp)-Math.max(t[0],fcp)});' .
        'var gAll=tasks.filter(function(t){return t[0]<lcp}),ownAll=0;' .
        'gAll.forEach(function(t){ownAll+=Math.min(t[0]+t[1],lcp)-t[0]});' .
        'L(gap>0?("IN THE GAP: main thread ("+g.length+" long tasks, "+Math.round(owned)+"ms of "+gap+"ms = "+Math.round(owned/gap*100)+"%)")' .
        ':"GAP IS ZERO — a gap-window long-task count would be vacuous; see the whole-load figure below",' .
        '(gap>0&&owned>gap*0.3)?"#c00":"#070",g.slice(0,12).map(function(t){return{at:t[0],ms:t[1],attr:t[2]}}));' .
        'L("WHOLE LOAD -> LCP: main thread ("+gAll.length+" long tasks, "+Math.round(ownAll)+"ms before LCP)",' .
        'ownAll>500?"#c00":"#070",{humanSignal:hum(),note:hum()===1?"REPLAY RAN — not the PSI path (PSI never interacts)":"no interaction — comparable to PSI"});' .
        'var V=(gap>0&&owned>gap*0.3)?"main-thread":(gap<=0?"no-gap":(lr&&lr.responseEnd<fcp?"paint-blocked":"inconclusive"));' .
        'L("VERDICT","#059",V==="main-thread"?"MAIN-THREAD bound — style/layout/script owns the gap, not bytes":' .
        '(V==="paint-blocked"?"LCP resource finished BEFORE FCP — the gap is PAINT-blocked, not fetch-blocked"' .
        ':(V==="no-gap"?"FCP and LCP coincide — there is no gap in THIS environment; check humanSignal before generalising":"see the tables above")));' .
        // Lighthouse runs on Google's infra, so console output is unreachable — the only way to
        // see the gap in the environment where it EXISTS is to beacon it back. Payload is kept
        // compact because the report handler rejects anything over 2048 bytes.
        'if(SEND&&EP){try{' .
        'var pl={lcptrace:1,ln:(window.__wpcTraceLane||0),u:location.pathname.slice(0,60),fcp:Math.round(fcp),lcp:Math.round(lcp),gap:gap,' .
        'el:lel?(lel.tagName+"."+((lel.className||"")+"").split(" ")[0]).slice(0,28):"-",url:lurl.slice(-44),v:V,' .
        'own:Math.round(owned),pct:(gap>0?Math.round(owned/gap*100):0),' .
        'hum:hum(),ltn:gAll.length,ltms:Math.round(ownAll),' .
        'top:lrect?lrect.top:-1,vh:lrect?lrect.vh:-1,inv:lrect?lrect.inView:-1,' .
        'r:lr?{s:Math.round(lr.startTime),ttfb:Math.round(lr.responseStart-lr.requestStart),' .
        'dl:Math.round(lr.responseEnd-lr.responseStart),end:Math.round(lr.responseEnd),' .
        'kb:Math.round((lr.transferSize||0)/1024),aft:(lr.responseEnd>lcp)?1:0}:null,' .
        'net:ov.slice(0,6).map(function(r){return[r.res.slice(0,22),r.start,r.end,r.kb]}),' .
        'lt:g.slice(0,5).map(function(t){return[t[0],t[1]]})};' .
        'var okb=false,oki=false;' .
        // sendBeacon returning true means QUEUED, not delivered — Lighthouse destroys the target
        // before the queue flushes, which is why a PSI run reported success and nothing arrived.
        // So fire BOTH channels every time; the image GET starts on the wire immediately and needs
        // no response to have been useful. Duplicates are harmless (rows are timestamped, cap 8)
        // and the ch field tells us which channel actually survived.
        'try{if(navigator.sendBeacon){var pb={};for(var k in pl)pb[k]=pl[k];pb.ch="b";' .
        'var fd=new FormData;fd.append("action","wpc_delay_v3_report");' .
        'fd.append("payload",JSON.stringify(pb));okb=navigator.sendBeacon(EP,fd)===true}}catch(e){okb=false}' .
        'try{var pi={};for(var k2 in pl)pi[k2]=pl[k2];pi.ch="i";var im=new Image();' .
        'im.src=EP+(EP.indexOf("?")<0?"?":"&")+"action=wpc_delay_v3_report&payload="+encodeURIComponent(JSON.stringify(pi))+"&_t="+(+new Date());oki=true}catch(e){}' .
        'L("BEACON",(okb||oki)?"#070":"#c00",{sendBeacon:okb?"queued":"refused",imageGET:oki?"fired":"failed",note:"queued != delivered"});' .
        '}catch(e){}}' .
        '};' .
        // report once LCP has settled; pagehide catches an early leave
        'var sends=0,lastLcp=0;var fire=function(){if(sends>=2)return;if(sends===1&&lcp<=lastLcp)return;' .
        'lastLcp=lcp;sends++;done=0;rep()};' .
        'if(document.readyState==="complete"){setTimeout(fire,300)}else{window.addEventListener("load",function(){setTimeout(fire,300)},{once:true})}' .
        'setTimeout(fire,4000);window.addEventListener("pagehide",fire);' .
        'document.addEventListener("visibilitychange",function(){if(document.visibilityState==="hidden")fire()});' .
        'L("ARMED","#070","tracing FCP->LCP; reports 900ms after LCP settles"+(SEND?" (beaconing to the site)":""));' .
        '})();</script>' . "\n";
    }
}

if (!function_exists('wpc_lcp_trace_emit')) {
    function wpc_lcp_trace_emit()
    {
        if (empty($_GET['wpc_lcp_trace']) || is_admin()) { return; }
        echo wpc_lcp_trace_script();
    }
    add_action('wp_head', 'wpc_lcp_trace_emit', -PHP_INT_MAX);
}

if (!function_exists('wpc_lcp_trace_serve717')) {
    /**
     * v7.10.717 — INSTRUMENT PARITY. ?wpc_lcp_trace previously fell into the unknown-param
     * class: the read gate refused the cached copy, WP rendered fresh, and the beacon measured
     * a divergent variant no visitor receives (legacy crit, no wire-lcp-inline — the two wrong
     * conclusions of the pin-714 night). The instrument now serves the EXACT armed bytes: the
     * clean-key cached copy with the beacon injected at serve time, no-store so an instrumented
     * body can never become a cacheable representation anywhere. Payload key ln:1 names the
     * lane. Falls through to the old render lane when no admissible copy exists, the visitor
     * is logged in, or the residual query is itself uncacheable.
     */
    function wpc_lcp_trace_serve717()
    {
        try {
            if (empty($_GET['wpc_lcp_trace']) || is_admin()) { return; }
            if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'GET') { return; }
            if (function_exists('is_user_logged_in') && is_user_logged_in()) { return; }
            $wpc_q717 = [];
            parse_str(isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '', $wpc_q717);
            unset($wpc_q717['wpc_lcp_trace'], $wpc_q717['send']);
            if (!empty($wpc_q717)) {
                if (!class_exists('wps_ic_url_key') || !method_exists('wps_ic_url_key', 'queryIsCacheable')
                    || !wps_ic_url_key::queryIsCacheable(http_build_query($wpc_q717))) {
                    return;
                }
            }
            if (!class_exists('wps_advancedCache') && defined('WPS_IC_DIR')
                && @is_readable(WPS_IC_DIR . 'addons/cache/advancedCache.php')) {
                include_once WPS_IC_DIR . 'addons/cache/advancedCache.php';
            }
            if (!class_exists('wps_advancedCache') || !class_exists('wps_ic_url_key')
                || !function_exists('wpc_lcp_trace_script')) {
                return;
            }
            // v7.10.718 - the copy lives under the CLEAN key. The url-key derives from
            // REQUEST_URI, which still carries the instrument params here - constructing
            // against the raw URI looks up a key no writer ever mints and always falls
            // through (receipted live on the .717 first check). Strip the params for the
            // construction, restore on fallthrough (success exits inside the serve).
            $wpc_ru717 = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            $wpc_qo717 = isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
            $wpc_qc717 = http_build_query($wpc_q717);
            $wpc_pp717 = (string) parse_url($wpc_ru717, PHP_URL_PATH);
            $_SERVER['QUERY_STRING'] = $wpc_qc717;
            $_SERVER['REQUEST_URI'] = $wpc_pp717 . ($wpc_qc717 !== '' ? '?' . $wpc_qc717 : '');
            try {
                $wpc_ac717 = new wps_advancedCache();
                if (method_exists($wpc_ac717, 'serveTraceCopy717')) {
                    $wpc_ac717->serveTraceCopy717(
                        '<script>window.__wpcTraceLane=1</script>' . "\n" . wpc_lcp_trace_script()
                    );
                }
            } finally {
                $_SERVER['REQUEST_URI'] = $wpc_ru717;
                $_SERVER['QUERY_STRING'] = $wpc_qo717;
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('init', 'wpc_lcp_trace_serve717', 1);
}

// v7.10.644 — USED-CSS DEFAULT ON (private beta; service: "a default that's wrong 94%
// of the time is a default, not a feature flag" — lanes reach 9.5% of delivering sites
// and succeed 94.5% when asked). One-time flip: any site not already '1' becomes '1',
// prior value recorded, existing probation machinery (lever auto-off on a failed gate
// outcome) supervises every flipped site individually. Kill: wpc_used_css_beta_default
// filter, or the recorded prior value for a manual revert.
if (!function_exists('wpc_used_css_beta_flip644')) {
    function wpc_used_css_beta_flip644()
    {
        try {
            if (!apply_filters('wpc_used_css_beta_default', true) || get_option('wpc_used_css_flip644')) {
                return;
            }
            $wpc_s644 = get_option(WPS_IC_SETTINGS);
            if (!is_array($wpc_s644)) {
                return;
            }
            // v7.10.645 — JANITOR BEFORE WAVE (service: "run wave one after the janitor
            // so the join is unambiguous"): canonicalize the key stores in the same
            // request, then journal the apikey FINGERPRINT — the join key between our
            // wave journal and their consumption sweep.
            if (function_exists('wpc_apikey_canonicalize644')) {
                wpc_apikey_canonicalize644();
            }
            $wpc_prior644 = isset($wpc_s644['used-css']) ? (string) $wpc_s644['used-css'] : 'absent';
            if ($wpc_prior644 !== '1') {
                $wpc_s644['used-css'] = '1';
                update_option(WPS_IC_SETTINGS, $wpc_s644);
            }
            update_option('wpc_used_css_flip644', ['at' => time(), 'prior' => $wpc_prior644], false);
            if (function_exists('wpc_cache_first_log')) {
                $wpc_ak645 = function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '';
                wpc_cache_first_log('used-css-beta-flip', '', '', [
                    'prior' => $wpc_prior644,
                    'ak' => $wpc_ak645 !== '' ? substr(md5($wpc_ak645), 0, 8) : '',
                ]);
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('admin_init', 'wpc_used_css_beta_flip644', 50);
}


// v7.10.647 — Brotli land handler (spec §3). v7.10.656 moved it to its own file: it
// decodes a live request body, and this file is the plugin's most write-heavy one.
require_once __DIR__ . '/html-br-land.php';
