<?php
if (!defined('ABSPATH')) {
    exit;
}


if (!function_exists('wpc_vitals_enabled')) {
    function wpc_vitals_enabled()
    {
        if (defined('WPC_VITALS_DISABLE') && WPC_VITALS_DISABLE) {
            return false;
        }
        $on = get_option('wpc_vitals_enabled', '1') === '1';
        return (bool) apply_filters('wpc_vitals', $on);
    }

    function wpc_vitals_stats_dir()
    {
        $u = wp_upload_dir(null, false);
        if (!empty($u['error']) || empty($u['basedir'])) {
            return '';
        }
        return rtrim($u['basedir'], '/') . '/wpc-vitals/';
    }


    function wpc_vitals_config($refresh = false)
    {
        static $cfg = null;
        if ($cfg !== null && !$refresh) {
            return $cfg;
        }
        $dir = wpc_vitals_stats_dir();
        if ($dir === '') {
            return $cfg = false;
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            return $cfg = false;
        }
        if (!@file_exists($dir . '.htaccess')) {

            wpc_fs_put($dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!@file_exists($dir . 'index.html')) {
            wpc_fs_put($dir . 'index.html', '');
        }
        $salt = get_option('wpc_vitals_salt');
        if (!is_string($salt) || strlen($salt) < 24) {
            $salt = function_exists('wp_generate_password') ? wp_generate_password(32, false, false) : substr(sha1(uniqid('', true) . mt_rand()), 0, 32);
            update_option('wpc_vitals_salt', $salt, false);
        }
        $sample = max(1, (int) get_option('wpc_vitals_sample', 1));
        $cfg = ['dir' => $dir, 'salt' => $salt, 'sample' => $sample, 'enabled' => wpc_vitals_enabled()];
        $disk = ['enabled' => $cfg['enabled'] ? 1 : 0, 'salt' => $salt, 'sample' => $sample];
        $cur  = @file_get_contents($dir . 'config.json');
        $new  = wp_json_encode($disk);
        if ($cur !== $new) {
            wpc_fs_put($dir . 'config.json', $new);
        }
        return $cfg;
    }
}


if (!function_exists('wpc_vitals_emit')) {
    function wpc_vitals_emit()
    {
        try {
            if (!wpc_vitals_enabled() || (function_exists('is_admin') && is_admin())) {
                return;
            }
            // v7.10.906 — logged-in views are the site owner and editors, never "your visitors":
            // the auth cookie bypasses the page cache, several optimizations stand down for
            // logged-in requests, and DevTools sessions disable caching entirely — so every
            // such sample measures the slowest possible path and poisons the real-visitor
            // lanes (the 7s p25=p50=p75 cohort). Bypass probes (?disableWPC) stay collectable:
            // they land in the fenced baseline lanes and the silent baseline runner mints them
            // from the logged-in admin browser on non-credentialless browsers by design.
            if (function_exists('is_user_logged_in') && is_user_logged_in() && empty($_GET['disableWPC'])
                && !(function_exists('apply_filters') && apply_filters('wpc_vitals_collect_logged_in', false))) {
                return;
            }
            // v7.10.701 — warm renders MUST carry the collector: the warmer is a JS-less fetch
            // (it can never beacon), but the HTML it renders is exactly what gets stored and
            // replayed to real visitors. Skipping emit here minted the majority copy class
            // WITHOUT the collector — every visitor served a warm-minted copy was invisible
            // to RUM, which is what pinned "served from cache" at 0%.
            $cfg = wpc_vitals_config();
            if (!$cfg) {
                return;
            }
            static $done = false;
            if ($done) {
                return;
            }
            $done = true;
            $src = defined('WPS_IC_DIR') ? WPS_IC_DIR . 'assets/js/vitals-collector.min.js' : '';
            if ($src === '' || !@is_readable($src)) {
                return;
            }
            $js = (string) @file_get_contents($src);
            if ($js === '' || stripos($js, '</script') !== false) {
                return;
            }
            // dirname(__DIR__) is the ADDONS dir, not the plugin root, so plugins_url() resolved
            // against addons/ and appended addons/vitals/v.php again -> addons/addons/... 404.
            // WPS_IC_URI is the URL partner of the WPS_IC_DIR used for $src above.
            $endpoint = defined('WPS_IC_URI')
                ? WPS_IC_URI . 'addons/vitals/v.php'
                : plugins_url('addons/vitals/v.php', dirname(__DIR__, 2) . '/wp-compress.php');
            if (function_exists('wpc_vitals_channel') && wpc_vitals_channel($endpoint) === 'ajax'
                && function_exists('admin_url')) {
                $endpoint = admin_url('admin-ajax.php') . '?action=wpc_v';
            }
            $token    = sha1($cfg['salt']);
            // m = mint epoch, frozen into the stored copy beside the collector itself. WebKit
            // never shipped PerformanceServerTiming, so every iOS view is blind to the
            // wpc-cache/wpc-mint headers — the copy's own age is the one cache signal every
            // browser can read. A replay >300s after mint is a cache serve by definition.
            $bypass_flag = !empty($_GET['disableWPC']) ? ',b:1' : '';
            // v7.10.926 — the loopback probe can lie (hdavid-law: server-side POST passes the
            // host's deny rule, every BROWSER POST 403s — verdict stayed 'direct' forever).
            // The browser is the only honest prober: ship the ajax fallback beside the direct
            // endpoint so the collector can observe the failure and switch itself.
            $ajax_fallback = '';
            if (function_exists('admin_url') && strpos($endpoint, 'admin-ajax.php') === false) {
                $ajax_fallback = ',a:' . wp_json_encode(admin_url('admin-ajax.php') . '?action=wpc_v');
            }
            echo "\n<script>window.wpcVitals={u:" . wp_json_encode($endpoint) . $ajax_fallback . ",t:" . wp_json_encode($token) . ",s:" . (int) $cfg['sample'] . ",m:" . (int) time() . $bypass_flag . "};</script>"
                . "<script>" . $js . "</script>\n";
        } catch (\Throwable $e) {
        }
    }
    add_action('wp_footer', 'wpc_vitals_emit', 99);
}

// ─── beacon channel: direct v.php POST is the default (zero WP boot per view), but hardened
// hosts (nginx deny on wp-content/*.php — hdavid-law + dalton-roofing, 403 on every beacon)
// never let v.php execute. A 12h-cached self-probe decides the channel. The probe is
// POST-SHAPED with an invalid token — it exercises the exact method+path+body a real beacon
// uses (a WAF can pass GET yet block POST, so a GET probe proves nothing) and v.php's token
// gate turns it into a guaranteed no-write: 204 (or 410 disabled) proves execution -> direct;
// 403/404/5xx/unreachable -> admin-ajax fallback. Probes run EAGERLY on admin_init (verdict
// exists before real traffic embeds an endpoint) and lazily at frontend shutdown as backstop;
// unknown verdict stays direct (fail-open to the cheap channel). ───────────────────────────
if (!function_exists('wpc_vitals_channel_probe')) {
    function wpc_vitals_channel_probe($endpoint)
    {
        if (!function_exists('wp_remote_post') || !function_exists('set_transient')) {
            return;
        }
        $r = wp_remote_post($endpoint, [
            'timeout'     => 5,
            'redirection' => 1,
            'sslverify'   => false,
            'headers'     => ['Content-Type' => 'application/x-www-form-urlencoded'],
            'body'        => 'v=1&t=0000000000000000000000000000000000000000&d=m&e=c&h=0&lcp=1000',
        ]);
        $code = (!is_wp_error($r) && function_exists('wp_remote_retrieve_response_code'))
            ? (int) wp_remote_retrieve_response_code($r) : 0;
        $verdict = ($code === 204 || $code === 410) ? 'direct' : 'ajax';
        if ($verdict === 'direct' && function_exists('get_option')
            && (time() - (int) get_option('wpc_vitals_fb_at03', 0)) < 7 * 86400) {
            $verdict = 'ajax';
        }
        set_transient('wpc_vitals_ch916', $verdict, 12 * 3600);
        if (function_exists('get_option') && function_exists('update_option')
            && get_option('wpc_vitals_ch_last') !== $verdict) {
            update_option('wpc_vitals_ch_last', $verdict, false);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('vitals-channel', '', '', ['to' => $verdict, 'by' => 'probe', 'code' => $code]);
            }
        }
    }
}
if (!function_exists('wpc_vitals_channel')) {
    function wpc_vitals_channel($endpoint)
    {
        if (function_exists('apply_filters')) {
            $forced_channel = apply_filters('wpc_vitals_channel', '');
            if ($forced_channel === 'direct' || $forced_channel === 'ajax') {
                return $forced_channel;
            }
        }
        $cached_channel = function_exists('get_transient') ? get_transient('wpc_vitals_ch916') : false;
        if ($cached_channel === 'direct' || $cached_channel === 'ajax') {
            return $cached_channel;
        }
        if (function_exists('register_shutdown_function') && empty($GLOBALS['wpc_vitals_channel_probe_registered'])) {
            $GLOBALS['wpc_vitals_channel_probe_registered'] = 1;
            register_shutdown_function(function () use ($endpoint) {
                wpc_vitals_channel_probe($endpoint);
            });
        }
        return 'direct';
    }
    if (function_exists('add_action')) {
        add_action('admin_init', function () {
            if (function_exists('get_transient') && get_transient('wpc_vitals_ch916') !== false) {
                return;
            }
            $endpoint = defined('WPS_IC_URI')
                ? WPS_IC_URI . 'addons/vitals/v.php'
                : (function_exists('plugins_url')
                    ? plugins_url('addons/vitals/v.php', dirname(__DIR__, 2) . '/wp-compress.php') : '');
            if ($endpoint !== '') {
                wpc_vitals_channel_probe($endpoint);
            }
        }, 30);
    }
}
if (!function_exists('wpc_vitals_ajax_ingest')) {
    function wpc_vitals_ajax_ingest()
    {
        // v7.10.926 — a beacon carrying fb=1 arrived here because a real browser watched the
        // direct v.php POST fail. That observation outranks the loopback probe's verdict:
        // flip the server channel so freshly-minted HTML embeds admin-ajax directly, and
        // purge the copies still carrying the dead endpoint.
        $raw_body = (string) @file_get_contents('php://input');
        if ((strpos($raw_body, 'fb=1') !== false || (isset($_POST['fb']) && $_POST['fb'] === '1'))
            && function_exists('get_transient') && get_transient('wpc_vitals_ch916') !== 'ajax') {
            set_transient('wpc_vitals_ch916', 'ajax', 12 * 3600);
            if (function_exists('update_option')) {
                update_option('wpc_vitals_fb_at03', time(), false);
            }
            if (function_exists('get_option') && get_option('wpc_vitals_ch_last') !== 'ajax') {
                update_option('wpc_vitals_ch_last', 'ajax', false);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('vitals-channel', '', '', ['to' => 'ajax', 'by' => 'browser']);
                }
            }
        }
        include __DIR__ . '/v.php';
        exit;
    }
    if (function_exists('add_action')) {
        add_action('wp_ajax_nopriv_wpc_v', 'wpc_vitals_ajax_ingest');
        add_action('wp_ajax_wpc_v', 'wpc_vitals_ajax_ingest');
    }
}

// ─── shared aggregator: one day-file's raw 8-byte records → the bucketed day aggregate ─────
if (!function_exists('wpc_vitals_agg_bytes')) {
    function wpc_vitals_agg_bytes($bytes)
    {
        $bytes = (string) $bytes;
        $n = intdiv(strlen($bytes), 8);
        $agg = [
            'v'  => 0, 'hit' => 0, 'mob' => 0,

            'm'  => ['lcp' => [], 'cls' => [], 'inp' => [], 'inp_s' => [], 'ttfb' => [], 'fcp' => []],
            'd'  => ['lcp' => [], 'cls' => [], 'inp' => [], 'inp_s' => [], 'ttfb' => [], 'fcp' => []],
            's'  => max(1, (int) get_option('wpc_vitals_sample', 1)),
        ];
        // v7.10.831 — view pings (0xA6, sent at load) are the view/hit/mob truth when
        // present: the metrics record (0xA7, flushed at tab-hide) undercounts views by
        // every session that never hides. On a ping-day, 0xA7 contributes metrics only;
        // on a legacy day (cached copies still carrying the old collector) 0xA7 counts
        // views exactly as before — the two rules converge as the page cache re-dresses.
        $pings = ['v' => 0, 'hit' => 0, 'mob' => 0, 'r' => []];
        $agg['bm'] = ['lcp' => []];
        $agg['bd'] = ['lcp' => []];
        // v7.10.845 — cache-hit LCP cohort (the "fully optimized visit" number) and
        // coarse-region lanes. Byte 8 = region enum minted at receive time (1 NA · 2 EU
        // · 3 APAC · 4 LATAM · 5 MEA, 0 unknown); 'rv' = views per region, 'r{n}' =
        // device-merged LCP buckets per region.
        $agg['hm'] = ['lcp' => [], 'ttfb' => []];
        $agg['hd'] = ['lcp' => [], 'ttfb' => []];
        $agg['rv'] = [];
        $region_views = [];
        for ($i = 0; $i < $n; $i++) {
            $r = array_values(unpack('C8', substr($bytes, $i * 8, 8)));
            if ($r[0] === 0xA6) {
                if ($r[1] & 0x10) { continue; } // bypass ping: baseline cohort, never a counted view
                $pings['v']++;
                if ($r[1] & 0x01) { $pings['mob']++; }
                if ($r[1] & 0x02) { $pings['hit']++; }
                if ((int) $r[7] >= 1 && (int) $r[7] <= 5) {
                    $pings['r'][(int) $r[7]] = ($pings['r'][(int) $r[7]] ?? 0) + 1;
                }
                continue;
            }
            if ($r[0] !== 0xA7) {
                continue;
            }
            if ($r[1] & 0x10) {
                // v7.10.833 — LIVE BASELINE COHORT: a ?disableWPC=true visit. Its LCP
                // feeds the without-plugin lanes only — never the optimized p75, never
                // the view counts.
                if ($r[2] !== 255) {
                    $baseline_lane = ($r[1] & 0x01) ? 'bm' : 'bd';
                    $agg[$baseline_lane]['lcp'][(int) $r[2]] = ($agg[$baseline_lane]['lcp'][(int) $r[2]] ?? 0) + 1;
                }
                continue;
            }
            $agg['v']++;
            $mob = ($r[1] & 0x01) ? 'm' : 'd';
            if ($r[1] & 0x01) {
                $agg['mob']++;
            }
            if ($r[1] & 0x02) {
                $agg['hit']++;
            }
            $safari = (($r[1] >> 2) & 0x03) === 1;
            $map = [2 => 'lcp', 3 => 'cls', 4 => $safari ? 'inp_s' : 'inp', 5 => 'ttfb', 6 => 'fcp'];
            foreach ($map as $bi => $metric) {
                if ($r[$bi] !== 255) {
                    $b = (int) $r[$bi];
                    $agg[$mob][$metric][$b] = ($agg[$mob][$metric][$b] ?? 0) + 1;
                }
            }
            if ($r[5] !== 255 && ($r[1] & 0x02)) {
                $agg['h' . $mob]['ttfb'][(int) $r[5]] = ($agg['h' . $mob]['ttfb'][(int) $r[5]] ?? 0) + 1;
            }
            if ($r[2] !== 255) {
                if ($r[1] & 0x02) {
                    $agg['h' . $mob]['lcp'][(int) $r[2]] = ($agg['h' . $mob]['lcp'][(int) $r[2]] ?? 0) + 1;
                }
                if ((int) $r[7] >= 1 && (int) $r[7] <= 5) {
                    $region_key = 'r' . (int) $r[7];
                    if (!isset($agg[$region_key])) { $agg[$region_key] = ['lcp' => []]; }
                    $agg[$region_key]['lcp'][(int) $r[2]] = ($agg[$region_key]['lcp'][(int) $r[2]] ?? 0) + 1;
                }
            }
            if ((int) $r[7] >= 1 && (int) $r[7] <= 5) {
                $region_views[(int) $r[7]] = ($region_views[(int) $r[7]] ?? 0) + 1;
            }
        }
        if ($pings['v'] > 0) {
            $agg['v']   = $pings['v'];
            $agg['hit'] = min($pings['v'], $pings['hit']);
            $agg['mob'] = $pings['mob'];
            $agg['rv']  = $pings['r'];
        } else {
            $agg['rv'] = $region_views;
        }
        return $agg;
    }
}

// ─── v7.10.863 — SAME-DAY PARTIAL: today's day-file parsed read-only (the rollup only folds
//     FINISHED days, which left every fresh install staring at an empty panel for 1–2 days).
//     The file stays on disk untouched; the daily rollup remains the only writer.
if (!function_exists('wpc_vitals_today_partial')) {
    function wpc_vitals_today_partial()
    {
        try {


            $wpc_tp_pre = apply_filters('wpc_vitals_today_partial_pre', null);
            if ($wpc_tp_pre !== null) {
                return is_array($wpc_tp_pre) ? $wpc_tp_pre : null;
            }
            $cfg = wpc_vitals_config();
            if (!$cfg) {
                return null;
            }
            $f = $cfg['dir'] . gmdate('Ymd') . '.bin';
            if (!@is_readable($f)) {
                return null;
            }
            $sz = (int) @filesize($f);
            if ($sz < 8) {
                return null;
            }
            $bytes = (string) @file_get_contents($f, false, null, 0, min($sz, 2097152));
            if (strlen($bytes) < 8) {
                return null;
            }
            $agg = wpc_vitals_agg_bytes(substr($bytes, 0, intdiv(strlen($bytes), 8) * 8));
            $has = $agg['v'] > 0;
            foreach (['m', 'd', 'bm', 'bd'] as $wpc_tp_l) {
                foreach ((array) ($agg[$wpc_tp_l] ?? []) as $wpc_tp_b) {
                    if (!empty($wpc_tp_b)) {
                        $has = true;
                    }
                }
            }
            return $has ? $agg : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}

// ─── daily rollup: day-files → bucketed aggregates in ONE autoload=false option ────────────
if (!function_exists('wpc_vitals_rollup')) {
    function wpc_vitals_epoch_guard()
    {
        if (get_option('wpc_vitals_epoch') === '906') {
            return;
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_vitals_epoch_reset', true)) {
            return;
        }
        delete_option('wpc_vitals_daily');
        delete_option('wpc_vitals_baseline');
        $cfg = function_exists('wpc_vitals_config') ? wpc_vitals_config() : false;
        if ($cfg && !empty($cfg['dir'])) {
            $deleted = 0;
            foreach ((array) @glob($cfg['dir'] . '*.bin') as $file) {
                if ($deleted >= 40) {
                    break;
                }
                @unlink($file);
                $deleted++;
            }
        }
        update_option('wpc_vitals_epoch', '906', true);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('vitals-epoch-reset', '', '', ['e' => '906']);
        }
    }

    function wpc_vitals_rollup()
    {
        try {
            wpc_vitals_epoch_guard();
            $cfg = wpc_vitals_config();
            if (!$cfg) {
                return;
            }
            $dir   = $cfg['dir'];
            $today = gmdate('Ymd');
            $daily = get_option('wpc_vitals_daily', []);
            if (!is_array($daily)) {
                $daily = [];
            }
            foreach ((array) @glob($dir . '*.bin') as $f) {
                $day = basename($f, '.bin');
                if (!preg_match('/^\d{8}$/', $day) || $day >= $today) {
                    continue;
                }
                $agg = wpc_vitals_agg_bytes((string) @file_get_contents($f));
                $daily[$day] = $agg;
                if (function_exists('wpc_vitals_add_saved_day')) {
                    wpc_vitals_add_saved_day($day,
                        wpc_vitals_saved_day_ms($agg, $daily),
                        (int) $agg['v'] * max(1, (int) get_option('wpc_vitals_sample', 1)));
                }
                @unlink($f);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('vitals-rollup', '', '', ['day' => $day, 'views' => $agg['v'], 'hit' => $agg['hit']]);
                }
            }

            krsort($daily);
            $kept = [];
            $i = 0;
            foreach ($daily as $k => $v) {
                if (strpos((string) $k, 'w:') === 0 || $i < 90) {
                    $kept[$k] = $v;
                    $i += (strpos((string) $k, 'w:') === 0) ? 0 : 1;
                    continue;
                }
                $wk = 'w:' . gmdate('oW', strtotime(substr($k, 0, 4) . '-' . substr($k, 4, 2) . '-' . substr($k, 6, 2)));
                if (!isset($kept[$wk])) {
                    $kept[$wk] = $v;
                } else {
                    $kept[$wk]['v']   += $v['v'];
                    $kept[$wk]['hit'] += $v['hit'];
                    $kept[$wk]['mob'] += $v['mob'];
                    foreach (['m', 'd', 'bm', 'bd', 'hm', 'hd', 'r1', 'r2', 'r3', 'r4', 'r5'] as $dev) {
                        if (empty($v[$dev]) || !is_array($v[$dev])) { continue; }
                        foreach ($v[$dev] as $metric => $buckets) {
                            foreach ($buckets as $b => $c) {
                                $kept[$wk][$dev][$metric][$b] = ($kept[$wk][$dev][$metric][$b] ?? 0) + $c;
                            }
                        }
                    }
                    if (!empty($v['rv']) && is_array($v['rv'])) {
                        foreach ($v['rv'] as $rg => $c) {
                            $kept[$wk]['rv'][$rg] = ($kept[$wk]['rv'][$rg] ?? 0) + (int) $c;
                        }
                    }
                }
            }
            update_option('wpc_vitals_daily', $kept, false);

            // FIELD BASELINE — first full week's site-wide mobile p75 LCP, captured once: the
            // "improved X% since optimization" banner compares field-to-field, never lab-to-field.
            // v7.21.96 — key-scoped guard + merge write: the live-baseline ratchet (vitalsPanel)
            // stores its own keys in this option, so option-existence would suppress this
            // capture forever and a replace-write would clobber the ratchet's keys.
            $baseline = get_option('wpc_vitals_baseline');
            $baseline = is_array($baseline) ? $baseline : [];
            if (empty($baseline['lcp_m_p75']) && count($kept) >= 7 && function_exists('wpc_vitals_p75')) {
                $base  = wpc_vitals_p75(array_slice($kept, -7, null, true), 'm', 'lcp');
                $baseD = wpc_vitals_p75(array_slice($kept, -7, null, true), 'd', 'lcp');
                if ($base > 0) {
                    $baseline['t'] = time();
                    $baseline['lcp_m_p75'] = $base;
                    $baseline['lcp_d_p75'] = max(0, (int) $baseD);
                    update_option('wpc_vitals_baseline', $baseline, false);
                }
            }
            // v7.10.832 — desktop parity backfill: sites baselined before .832 hold only the
            // mobile number; fill the desktop leg ONCE from the oldest kept window so the
            // desktop view gets its band and its since-activation delta too.
            $stored_baseline = get_option('wpc_vitals_baseline');
            if (is_array($stored_baseline) && empty($stored_baseline['lcp_d_p75']) && count($kept) >= 7 && function_exists('wpc_vitals_p75')) {
                $desktop_lcp_baseline = (int) wpc_vitals_p75(array_slice($kept, -7, null, true), 'd', 'lcp');
                if ($desktop_lcp_baseline > 0) {
                    $stored_baseline['lcp_d_p75'] = $desktop_lcp_baseline;
                    update_option('wpc_vitals_baseline', $stored_baseline, false);
                }
            }

            // AUTO-SAMPLING — yesterday's volume tunes tomorrow's client-side sample denominator.
            $yv = isset($daily[gmdate('Ymd', time() - 86400)]['v']) ? (int) $daily[gmdate('Ymd', time() - 86400)]['v'] * max(1, (int) get_option('wpc_vitals_sample', 1)) : 0;
            $new = $yv > 250000 ? 25 : ($yv > 50000 ? 10 : 1);
            if ($new !== (int) get_option('wpc_vitals_sample', 1)) {
                update_option('wpc_vitals_sample', $new, false);
                wpc_vitals_config(true);
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('vitals-sample-changed', '', '', ['sample' => $new, 'yv' => $yv]);
                }
            }
        } catch (\Throwable $e) {
        }
    }
    add_action('wpc_vitals_rollup', 'wpc_vitals_rollup');

    add_action('admin_init', function () {
        if (wpc_vitals_enabled() && function_exists('wp_next_scheduled') && !wp_next_scheduled('wpc_vitals_rollup')) {
            wp_schedule_event(time() + 300, 'daily', 'wpc_vitals_rollup');
        }
    }, 40);
}


if (!function_exists('wpc_vitals_p75')) {
    /** Upper edge of the bucket where the 75th percentile falls; ms metrics only unless $cls. */
    function wpc_vitals_bucket_edges($cls = false)
    {
        return $cls
            ? [0, 5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 120, 140, 160, 180, 200, 225, 250, 300, 350, 400, 500, 600, 750, 1000, 1500, 2000, 3000, 4000]
            : [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000, 1200, 1400, 1600, 1800, 2000, 2250, 2500, 2750, 3000, 3500, 4000, 4500, 5000, 6000, 7000, 8500, 10000, 15000, 25000, 40000];
    }

    /** generalized percentile over the same bucket walk — q in (0,1); p75 keeps its own fn */
    function wpc_vitals_pct($days, $device, $metric, $q)
    {
        $buckets = [];
        $total = 0;
        foreach ((array) $days as $d) {
            if (empty($d[$device][$metric]) || !is_array($d[$device][$metric])) {
                continue;
            }
            foreach ($d[$device][$metric] as $b => $c) {
                $buckets[(int) $b] = ($buckets[(int) $b] ?? 0) + (int) $c;
                $total += (int) $c;
            }
        }
        if ($total < 1) {
            return 0;
        }
        ksort($buckets);
        $edges = wpc_vitals_bucket_edges($metric === 'cls');
        $need = max(0.01, min(0.99, (float) $q)) * $total;
        $run = 0;
        foreach ($buckets as $b => $c) {
            $run += $c;
            if ($run >= $need) {
                return isset($edges[$b]) ? $edges[$b] : end($edges);
            }
        }
        return end($edges);
    }

    /** share of samples at or below $ms (bucket-edge resolution): [share 0..1, samples] */
    function wpc_vitals_share_below($days, $device, $metric, $ms)
    {
        $edges = wpc_vitals_bucket_edges($metric === 'cls');
        $total = 0;
        $below = 0;
        foreach ((array) $days as $d) {
            if (empty($d[$device][$metric]) || !is_array($d[$device][$metric])) {
                continue;
            }
            foreach ($d[$device][$metric] as $b => $c) {
                $total += (int) $c;
                $edge = isset($edges[(int) $b]) ? $edges[(int) $b] : PHP_INT_MAX;
                if ($edge <= $ms) {
                    $below += (int) $c;
                }
            }
        }
        return [$total > 0 ? $below / $total : 0.0, $total];
    }

    function wpc_vitals_p75($days, $device, $metric)
    {
        $buckets = [];
        $total = 0;
        foreach ((array) $days as $d) {
            if (empty($d[$device][$metric]) || !is_array($d[$device][$metric])) {
                continue;
            }
            foreach ($d[$device][$metric] as $b => $c) {
                $buckets[(int) $b] = ($buckets[(int) $b] ?? 0) + (int) $c;
                $total += (int) $c;
            }
        }
        if ($total < 1) {
            return 0;
        }
        ksort($buckets);
        $edges = wpc_vitals_bucket_edges($metric === 'cls');
        $need = 0.75 * $total;
        $run = 0;
        foreach ($buckets as $b => $c) {
            $run += $c;
            if ($run >= $need) {
                return isset($edges[$b]) ? $edges[$b] : end($edges);
            }
        }
        return end($edges);
    }
}


if (!function_exists('wpc_vitals_export')) {
    function wpc_vitals_export($maxDays = 56)
    {
        $empty = ['daily' => [], 'baseline' => false, 'sample' => 1, 'enabled' => false];
        try {
            $daily = get_option('wpc_vitals_daily', []);
            if (!is_array($daily)) {
                $daily = [];
            }


            if (function_exists('wpc_vitals_today_partial')) {
                $tp = wpc_vitals_today_partial();
                if (is_array($tp)) {
                    $daily[gmdate('Ymd')] = $tp;
                }
            }


            $days = [];
            foreach ($daily as $k => $v) {
                if (preg_match('/^\d{8}$/', (string) $k) && is_array($v)) {
                    $days[$k] = $v;
                }
            }
            ksort($days);
            $maxDays = max(1, (int) $maxDays);
            if (count($days) > $maxDays) {
                $days = array_slice($days, -$maxDays, $maxDays, true);
            }

            return [
                'daily'    => $days,
                'baseline' => get_option('wpc_vitals_baseline'),
                'sample'   => max(1, (int) get_option('wpc_vitals_sample', 1)),
                'enabled'  => function_exists('wpc_vitals_enabled') ? (bool) wpc_vitals_enabled() : false,
                'saved'    => function_exists('wpc_vitals_export_saved_totals') ? wpc_vitals_export_saved_totals() : null,
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
    }


    // v7.21.27 — the Initial Speed Check lived ONLY in the measuring admin's
    // localStorage (wpcVpSC): a second admin on another browser/machine — or any
    // Safari/Firefox admin, where credentialless iframes don't exist so the check
    // can never run — saw an empty panel on the same site. The result is now also
    // persisted server-side and exported to every viewer.
    function wpc_vitals_sc_sane($r)
    {
        if (!is_array($r)) {
            return null;
        }
        $out = ['e' => 1, 't' => 0];
        $t = isset($r['t']) ? (float) $r['t'] : 0;
        if ($t < 1 || $t > 4102444800000.0) {
            return null;
        }
        $out['t'] = $t;
        $legs = 0;
        foreach (['m', 'd'] as $dv) {
            if (empty($r[$dv]) || !is_array($r[$dv])) {
                continue;
            }
            $on  = isset($r[$dv]['on']) ? (int) $r[$dv]['on'] : 0;
            $off = isset($r[$dv]['off']) ? (int) $r[$dv]['off'] : 0;
            if ($on < 1 || $on > 120000 || $off < 1 || $off > 120000) {
                continue;
            }
            $out[$dv] = ['on' => $on, 'off' => $off];
            $legs++;
        }
        return $legs > 0 ? $out : null;
    }

    function wpc_vitals_diagnostics()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')
            || !function_exists('wp_verify_nonce')
            || !wp_verify_nonce(isset($_POST['n']) ? (string) $_POST['n'] : '', 'wpc_vp_sc')) {
            wp_send_json_error('forbidden');
        }
        $raw = isset($_POST['r']) ? (string) wp_unslash($_POST['r']) : '';
        if ($raw === '' || strlen($raw) > 1200) {
            wp_send_json_error('bad');
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            wp_send_json_error('bad');
        }
        $rec = [
            'armed'   => !empty($j['armed']) ? 1 : 0,
            'foreign' => isset($j['foreign']) ? max(0, min(9999, (int) $j['foreign'])) : 0,
            'svgs'    => isset($j['svgs']) ? max(0, min(99, (int) $j['svgs'])) : 0,
            'src'     => isset($j['src']) ? substr(preg_replace('/[^\x20-\x7E]/', '', (string) $j['src']), 0, 240) : '',
            'ua'      => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 120) : '',
            't'       => time(),
        ];
        update_option('wpc_vp_diag37', $rec, false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('vp-diag37', '', '', $rec);
        }
        wp_send_json_success('ok');
    }

    function wpc_vitals_sc_save()
    {
        if (!function_exists('current_user_can') || !current_user_can('manage_options')
            || !function_exists('wp_verify_nonce')
            || !wp_verify_nonce(isset($_POST['n']) ? (string) $_POST['n'] : '', 'wpc_vp_sc')) {
            wp_send_json_error('forbidden');
        }
        $raw = isset($_POST['r']) ? (string) wp_unslash($_POST['r']) : '';
        if ($raw === '' || strlen($raw) > 2000) {
            wp_send_json_error('bad');
        }
        $sane = wpc_vitals_sc_sane(json_decode($raw, true));
        if ($sane === null) {
            wp_send_json_error('bad');
        }
        $prev = get_option('wpc_vitals_sc');
        // Keep the freshest complete record: never let an m-only save clobber a
        // stored m+d pair from the same day unless the new one is newer AND fuller.
        if (is_array($prev) && isset($prev['t']) && (float) $prev['t'] >= $sane['t']
            && isset($prev['d']) && !isset($sane['d'])) {
            wp_send_json_success('kept');
        }
        update_option('wpc_vitals_sc', $sane, false);
        wp_send_json_success('saved');
    }
    if (function_exists('add_action')) {
        add_action('wp_ajax_wpc_vitals_sc_save', 'wpc_vitals_sc_save');
        add_action('wp_ajax_wpc_vitals_diagnostics', 'wpc_vitals_diagnostics');
        add_action('wp_ajax_wpc_vitals_live', 'wpc_vitals_live_data');
    }

    function wpc_vitals_export_has_data($export)
    {
        if (!is_array($export) || empty($export['daily']) || !is_array($export['daily'])) {
            return false;
        }
        foreach ($export['daily'] as $d) {
            if (!is_array($d)) {
                continue;
            }
            if (!empty($d['v'])) {
                return true;
            }
            foreach (['m', 'd', 'bm', 'bd'] as $lane) {
                foreach ((array) ($d[$lane] ?? []) as $buckets) {
                    if (!empty($buckets)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}


if (!function_exists('wpc_vitals_base_p75')) {
    function wpc_vitals_base_p75($daily, $device)
    {
        $bk = 'b' . $device;
        $merged = [];
        $n = 0;
        $i = 0;
        foreach ((array) $daily as $k => $v) {
            if (strpos((string) $k, 'w:') === 0) { continue; }
            if ($i >= 28) { break; }
            $i++;
            foreach ((array) (isset($v[$bk]['lcp']) ? $v[$bk]['lcp'] : []) as $b => $c) {
                $merged[(int) $b] = (isset($merged[(int) $b]) ? $merged[(int) $b] : 0) + (int) $c;
                $n += (int) $c;
            }
        }
        if ($n >= (int) apply_filters('wpc_vitals_base_min_samples', 30)) {
            ksort($merged);
            $edges = wpc_vitals_bucket_edges(false);
            $need = 0.75 * $n;
            $run = 0;
            foreach ($merged as $b => $c) {
                $run += $c;
                if ($run >= $need) {
                    return isset($edges[$b]) ? (int) $edges[$b] : (int) end($edges);
                }
            }
        }
        $fb = get_option('wpc_vitals_baseline');
        $key = 'lcp_' . $device . '_p75';
        return (is_array($fb) && !empty($fb[$key])) ? (int) $fb[$key] : 0;
    }

    function wpc_vitals_saved_day_ms($agg, $daily)
    {
        if (!is_array($agg)) { return 0.0; }
        $sample = max(1, (int) get_option('wpc_vitals_sample', 1));
        $vm = (int) (isset($agg['mob']) ? $agg['mob'] : 0);
        $views = ['m' => $vm, 'd' => max(0, (int) (isset($agg['v']) ? $agg['v'] : 0) - $vm)];
        $ms = 0.0;
        foreach (['m', 'd'] as $dv) {
            if ($views[$dv] <= 0) { continue; }
            $base = wpc_vitals_base_p75($daily, $dv);
            if ($base <= 0) { continue; }
            $day = (int) wpc_vitals_p75(['x' => $agg], $dv, 'lcp');
            if ($day <= 0) { continue; }
            $ms += (float) ($base - $day) * $views[$dv] * $sample;
        }
        return $ms;
    }

    function wpc_vitals_add_saved_day($day, $ms, $views)
    {
        $day = (string) $day;
        $s = get_option('wpc_vitals_saved');
        if (!is_array($s)) {
            $s = ['ms_total' => 0.0, 'views' => 0, 'months' => [], 'days' => [], 'since' => time()];
        }
        if (!isset($s['days']) || !is_array($s['days'])) { $s['days'] = []; }
        if (isset($s['days'][$day])) { return $s; }
        $s['days'][$day] = 1;
        if (count($s['days']) > 90) { krsort($s['days']); $s['days'] = array_slice($s['days'], 0, 90, true); }
        $mkey = substr($day, 0, 6);
        $s['ms_total'] = (float) $s['ms_total'] + (float) $ms;
        $s['views']    = (int) $s['views'] + (int) $views;
        if (!isset($s['months'][$mkey]) || !is_array($s['months'][$mkey])) {
            $s['months'][$mkey] = ['ms' => 0.0, 'views' => 0];
        }
        $s['months'][$mkey]['ms']    = (float) $s['months'][$mkey]['ms'] + (float) $ms;
        $s['months'][$mkey]['views'] = (int) $s['months'][$mkey]['views'] + (int) $views;
        if (count($s['months']) > 24) { krsort($s['months']); $s['months'] = array_slice($s['months'], 0, 24, true); ksort($s['months']); }
        update_option('wpc_vitals_saved', $s, false);
        return $s;
    }

    function wpc_vitals_export_saved_totals()
    {
        $out = ['ms_total' => 0.0, 'ms_month' => 0.0, 'ms_year' => 0.0, 'ms_today' => 0.0,
                'views' => 0, 'since' => 0, 'rate_ms_per_s' => 0.0, 'base_m' => 0, 'base_d' => 0,
                'sample' => max(1, (int) get_option('wpc_vitals_sample', 1))];
        $s = get_option('wpc_vitals_saved');
        if (is_array($s)) {
            $out['ms_total'] = (float) (isset($s['ms_total']) ? $s['ms_total'] : 0);
            $out['views']    = (int) (isset($s['views']) ? $s['views'] : 0);
            $out['since']    = (int) (isset($s['since']) ? $s['since'] : 0);
            $mkey = gmdate('Ym');
            $y    = gmdate('Y');
            foreach ((array) (isset($s['months']) ? $s['months'] : []) as $k => $v) {
                if (!is_array($v)) { continue; }
                if ((string) $k === $mkey) { $out['ms_month'] += (float) $v['ms']; }
                if (strpos((string) $k, $y) === 0) { $out['ms_year'] += (float) $v['ms']; }
            }
        }
        $daily = get_option('wpc_vitals_daily', []);
        $daily = is_array($daily) ? $daily : [];
        $out['base_m'] = (int) wpc_vitals_base_p75($daily, 'm');
        $out['base_d'] = (int) wpc_vitals_base_p75($daily, 'd');
        $tp = function_exists('wpc_vitals_today_partial') ? wpc_vitals_today_partial() : null;
        if (is_array($tp)) {
            $out['ms_today'] = wpc_vitals_saved_day_ms($tp, $daily);
        }
        $win_ms = 0.0; $win_from = 0;
        if (is_array($s) && !empty($s['months']) && is_array($s['months'])) {
            $mk = array_keys($s['months']);
            rsort($mk);
            foreach (array_slice($mk, 0, 2) as $k) {
                $win_ms += (float) $s['months'][$k]['ms'];
                $t = strtotime(substr((string) $k, 0, 4) . '-' . substr((string) $k, 4, 2) . '-01');
                if ($t && ($win_from === 0 || $t < $win_from)) { $win_from = $t; }
            }
            if ($out['since'] > $win_from) { $win_from = $out['since']; }
        }
        $r1 = ($win_from > 0 && $win_ms > 0) ? $win_ms / max(86400, time() - $win_from) : 0.0;
        $sod = strtotime(gmdate('Y-m-d') . ' 00:00:00');
        $r2 = ($out['ms_today'] > 0) ? $out['ms_today'] / max(600, time() - $sod) : 0.0;
        $out['rate_ms_per_s'] = max(0.0, $r1, $r2);
        return $out;
    }

    function wpc_vitals_live_data()
    {
        if (!function_exists('current_user_can')
            || (!current_user_can('manage_wpc_settings') && !current_user_can('manage_options'))
            || !function_exists('wp_verify_nonce')
            || !wp_verify_nonce(isset($_POST['n']) ? (string) $_POST['n'] : '', 'wpc_vp_sc')) {
            wp_send_json_error('forbidden');
        }
        $off = isset($_POST['o']) ? max(-1, (int) $_POST['o']) : -1;
        $cfg = wpc_vitals_config();
        $out = ['o' => 0, 'pts' => [], 'sample' => max(1, (int) get_option('wpc_vitals_sample', 1))];
        if (!$cfg) { wp_send_json_success($out); }
        $f  = $cfg['dir'] . gmdate('Ymd') . '.bin';
        $sz = @is_readable($f) ? (int) @filesize($f) : 0;
        $sz = intdiv($sz, 8) * 8;
        $out['o'] = $sz;
        if ($off < 0 || $off > $sz || $sz <= $off) { wp_send_json_success($out); }
        $read = intdiv(min($sz - $off, 1600), 8) * 8;
        $bytes = (string) @file_get_contents($f, false, null, $sz - $read, $read);
        $edges = wpc_vitals_bucket_edges(false);
        for ($i = 0; $i + 8 <= strlen($bytes); $i += 8) {
            $r = array_values(unpack('C8', substr($bytes, $i, 8)));
            if ($r[0] !== 0xA7 || ($r[1] & 0x10) || $r[2] === 255) { continue; }
            $lcp = isset($edges[(int) $r[2]]) ? (int) $edges[(int) $r[2]] : 0;
            if ($lcp <= 0) { continue; }
            $out['pts'][] = ['d' => ($r[1] & 0x01) ? 'm' : 'd', 'lcp' => $lcp, 'hit' => ($r[1] & 0x02) ? 1 : 0];
            if (count($out['pts']) >= 200) { break; }
        }
        wp_send_json_success($out);
    }
}
