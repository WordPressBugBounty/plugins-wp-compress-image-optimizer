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

            @file_put_contents($dir . '.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
        if (!@file_exists($dir . 'index.html')) {
            @file_put_contents($dir . 'index.html', '');
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
            @file_put_contents($dir . 'config.json', $new);
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
            $wpc_byp833 = !empty($_GET['disableWPC']) ? ',b:1' : '';
            // v7.10.926 — the loopback probe can lie (hdavid-law: server-side POST passes the
            // host's deny rule, every BROWSER POST 403s — verdict stayed 'direct' forever).
            // The browser is the only honest prober: ship the ajax fallback beside the direct
            // endpoint so the collector can observe the failure and switch itself.
            $wpc_ajx926 = '';
            if (function_exists('admin_url') && strpos($endpoint, 'admin-ajax.php') === false) {
                $wpc_ajx926 = ',a:' . wp_json_encode(admin_url('admin-ajax.php') . '?action=wpc_v');
            }
            echo "\n<script>window.wpcVitals={u:" . wp_json_encode($endpoint) . $wpc_ajx926 . ",t:" . wp_json_encode($token) . ",s:" . (int) $cfg['sample'] . ",m:" . (int) time() . $wpc_byp833 . "};</script>"
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
            'headers'     => ['Content-Type' => 'text/plain'],
            'body'        => 'v=1&t=0000000000000000000000000000000000000000&d=m&e=c&h=0&lcp=1000',
        ]);
        $code = (!is_wp_error($r) && function_exists('wp_remote_retrieve_response_code'))
            ? (int) wp_remote_retrieve_response_code($r) : 0;
        $wpc_v918 = ($code === 204 || $code === 410) ? 'direct' : 'ajax';
        set_transient('wpc_vitals_ch916', $wpc_v918, 12 * 3600);
        if (function_exists('get_option') && function_exists('update_option')
            && get_option('wpc_vitals_ch_last') !== $wpc_v918) {
            update_option('wpc_vitals_ch_last', $wpc_v918, false);
            if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
            }
        }
    }
}
if (!function_exists('wpc_vitals_channel')) {
    function wpc_vitals_channel($endpoint)
    {
        if (function_exists('apply_filters')) {
            $wpc_f916 = apply_filters('wpc_vitals_channel', '');
            if ($wpc_f916 === 'direct' || $wpc_f916 === 'ajax') {
                return $wpc_f916;
            }
        }
        $wpc_ch916 = function_exists('get_transient') ? get_transient('wpc_vitals_ch916') : false;
        if ($wpc_ch916 === 'direct' || $wpc_ch916 === 'ajax') {
            return $wpc_ch916;
        }
        if (function_exists('register_shutdown_function') && empty($GLOBALS['wpc_vch_probe916'])) {
            $GLOBALS['wpc_vch_probe916'] = 1;
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
            $wpc_ep916 = defined('WPS_IC_URI')
                ? WPS_IC_URI . 'addons/vitals/v.php'
                : (function_exists('plugins_url')
                    ? plugins_url('addons/vitals/v.php', dirname(__DIR__, 2) . '/wp-compress.php') : '');
            if ($wpc_ep916 !== '') {
                wpc_vitals_channel_probe($wpc_ep916);
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
        $wpc_raw926 = (string) @file_get_contents('php://input');
        if ((strpos($wpc_raw926, 'fb=1') !== false || (isset($_POST['fb']) && $_POST['fb'] === '1'))
            && function_exists('get_transient') && get_transient('wpc_vitals_ch916') !== 'ajax') {
            set_transient('wpc_vitals_ch916', 'ajax', 12 * 3600);
            if (function_exists('get_option') && get_option('wpc_vitals_ch_last') !== 'ajax') {
                update_option('wpc_vitals_ch_last', 'ajax', false);
                if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                    try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
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
        $wpc_ping831 = ['v' => 0, 'hit' => 0, 'mob' => 0, 'r' => []];
        $agg['bm'] = ['lcp' => []];
        $agg['bd'] = ['lcp' => []];
        // v7.10.845 — cache-hit LCP cohort (the "fully optimized visit" number) and
        // coarse-region lanes. Byte 8 = region enum minted at receive time (1 NA · 2 EU
        // · 3 APAC · 4 LATAM · 5 MEA, 0 unknown); 'rv' = views per region, 'r{n}' =
        // device-merged LCP buckets per region.
        $agg['hm'] = ['lcp' => [], 'ttfb' => []];
        $agg['hd'] = ['lcp' => [], 'ttfb' => []];
        $agg['rv'] = [];
        $wpc_rv845 = [];
        for ($i = 0; $i < $n; $i++) {
            $r = array_values(unpack('C8', substr($bytes, $i * 8, 8)));
            if ($r[0] === 0xA6) {
                if ($r[1] & 0x10) { continue; } // bypass ping: baseline cohort, never a counted view
                $wpc_ping831['v']++;
                if ($r[1] & 0x01) { $wpc_ping831['mob']++; }
                if ($r[1] & 0x02) { $wpc_ping831['hit']++; }
                if ((int) $r[7] >= 1 && (int) $r[7] <= 5) {
                    $wpc_ping831['r'][(int) $r[7]] = ($wpc_ping831['r'][(int) $r[7]] ?? 0) + 1;
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
                    $wpc_bl833 = ($r[1] & 0x01) ? 'bm' : 'bd';
                    $agg[$wpc_bl833]['lcp'][(int) $r[2]] = ($agg[$wpc_bl833]['lcp'][(int) $r[2]] ?? 0) + 1;
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
                    $wpc_rk845 = 'r' . (int) $r[7];
                    if (!isset($agg[$wpc_rk845])) { $agg[$wpc_rk845] = ['lcp' => []]; }
                    $agg[$wpc_rk845]['lcp'][(int) $r[2]] = ($agg[$wpc_rk845]['lcp'][(int) $r[2]] ?? 0) + 1;
                }
            }
            if ((int) $r[7] >= 1 && (int) $r[7] <= 5) {
                $wpc_rv845[(int) $r[7]] = ($wpc_rv845[(int) $r[7]] ?? 0) + 1;
            }
        }
        if ($wpc_ping831['v'] > 0) {
            $agg['v']   = $wpc_ping831['v'];
            $agg['hit'] = min($wpc_ping831['v'], $wpc_ping831['hit']);
            $agg['mob'] = $wpc_ping831['mob'];
            $agg['rv']  = $wpc_ping831['r'];
        } else {
            $agg['rv'] = $wpc_rv845;
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
        $wpc_cfg894 = function_exists('wpc_vitals_config') ? wpc_vitals_config() : false;
        if ($wpc_cfg894 && !empty($wpc_cfg894['dir'])) {
            $wpc_n894 = 0;
            foreach ((array) @glob($wpc_cfg894['dir'] . '*.bin') as $wpc_f894) {
                if ($wpc_n894 >= 40) {
                    break;
                }
                @unlink($wpc_f894);
                $wpc_n894++;
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
            if (!get_option('wpc_vitals_baseline') && count($kept) >= 7 && function_exists('wpc_vitals_p75')) {
                $base  = wpc_vitals_p75(array_slice($kept, -7, null, true), 'm', 'lcp');
                $baseD = wpc_vitals_p75(array_slice($kept, -7, null, true), 'd', 'lcp');
                if ($base > 0) {
                    update_option('wpc_vitals_baseline', ['t' => time(), 'lcp_m_p75' => $base, 'lcp_d_p75' => max(0, (int) $baseD)], false);
                }
            }
            // v7.10.832 — desktop parity backfill: sites baselined before .832 hold only the
            // mobile number; fill the desktop leg ONCE from the oldest kept window so the
            // desktop view gets its band and its since-activation delta too.
            $wpc_vb832 = get_option('wpc_vitals_baseline');
            if (is_array($wpc_vb832) && empty($wpc_vb832['lcp_d_p75']) && count($kept) >= 7 && function_exists('wpc_vitals_p75')) {
                $wpc_bd832 = (int) wpc_vitals_p75(array_slice($kept, -7, null, true), 'd', 'lcp');
                if ($wpc_bd832 > 0) {
                    $wpc_vb832['lcp_d_p75'] = $wpc_bd832;
                    update_option('wpc_vitals_baseline', $wpc_vb832, false);
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
            ];
        } catch (\Throwable $e) {
            return $empty;
        }
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
