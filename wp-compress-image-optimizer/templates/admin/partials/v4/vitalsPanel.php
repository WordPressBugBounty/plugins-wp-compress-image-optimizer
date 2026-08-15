<?php
if (!defined('ABSPATH')) {
    exit;
}


if (function_exists('wpc_vitals_epoch_guard')) {
    wpc_vitals_epoch_guard();
}
$wpc_vp_daily = get_option('wpc_vitals_daily', []);
if (!is_array($wpc_vp_daily)) {
    $wpc_vp_daily = [];
}


if (function_exists('wpc_vitals_today_partial')) {
    $wpc_vp_tp = wpc_vitals_today_partial();
    if (is_array($wpc_vp_tp)) {
        $wpc_vp_daily[gmdate('Ymd')] = $wpc_vp_tp;
    }
}


$wpc_vp_days = [];
foreach ($wpc_vp_daily as $wpc_vp_k => $wpc_vp_v) {
    if (preg_match('/^\d{8}$/', (string) $wpc_vp_k) && is_array($wpc_vp_v)) {
        $wpc_vp_days[$wpc_vp_k] = $wpc_vp_v;
    }
}
ksort($wpc_vp_days);
$wpc_vp_n     = count($wpc_vp_days);
$wpc_vp_cur   = array_slice($wpc_vp_days, max(0, $wpc_vp_n - 28), 28, true);
$wpc_vp_prior = $wpc_vp_n > 28 ? array_slice($wpc_vp_days, max(0, $wpc_vp_n - 56), min(28, $wpc_vp_n - 28), true) : [];



$wpc_vp_merge_inp = function ($days) {
    foreach ($days as $k => $d) {
        foreach (['m', 'd'] as $dev) {
            if (!empty($d[$dev]['inp_s']) && is_array($d[$dev]['inp_s'])) {
                foreach ($d[$dev]['inp_s'] as $b => $c) {
                    $days[$k][$dev]['inp'][$b] = ($days[$k][$dev]['inp'][$b] ?? 0) + (int) $c;
                }
            }
        }
    }
    return $days;
};
$wpc_vp_curM   = $wpc_vp_merge_inp($wpc_vp_cur);
$wpc_vp_priorM = $wpc_vp_merge_inp($wpc_vp_prior);
if (!empty($wpc_vp_curM)) {
    $wpc_vp_axis = [];
    for ($wpc_vp_ai = 27; $wpc_vp_ai >= 0; $wpc_vp_ai--) {
        $wpc_vp_ak = gmdate('Ymd', time() - $wpc_vp_ai * 86400);
        $wpc_vp_axis[$wpc_vp_ak] = $wpc_vp_curM[$wpc_vp_ak]
            ?? ['v' => 0, 'hit' => 0, 'mob' => 0, 's' => 1, 'm' => ['lcp' => []], 'd' => ['lcp' => []]];
    }
    $wpc_vp_curM = $wpc_vp_axis;
    $wpc_vp_cur  = $wpc_vp_axis;
}


$wpc_vp_fmt = function ($val, $metric) {
    if ($val <= 0) {
        return ['—', ''];
    }
    if ($metric === 'cls') {
        return [number_format($val / 1000, 2), ''];
    }
    return $val < 1000 ? [(string) $val, 'ms'] : [rtrim(rtrim(number_format($val / 1000, 1), '0'), '.'), 's'];
};

$wpc_vp_TH = ['lcp' => [2500, 4000], 'inp' => [200, 500], 'cls' => [100, 250], 'ttfb' => [800, 1800]];

$wpc_vp_baseline = get_option('wpc_vitals_baseline');
$wpc_vp_base_lcp = (is_array($wpc_vp_baseline) && !empty($wpc_vp_baseline['lcp_m_p75'])) ? (int) $wpc_vp_baseline['lcp_m_p75'] : 0;




$wpc_vp_bsamp = ['m' => 0, 'd' => 0];
$wpc_vp_blive = ['m' => 0, 'd' => 0];
foreach (['m' => 'bm', 'd' => 'bd'] as $wpc_vp_bl_dev => $wpc_vp_bl_lane) {
    foreach ($wpc_vp_cur as $wpc_vp_bl_d) {
        if (!empty($wpc_vp_bl_d[$wpc_vp_bl_lane]['lcp']) && is_array($wpc_vp_bl_d[$wpc_vp_bl_lane]['lcp'])) {
            $wpc_vp_bsamp[$wpc_vp_bl_dev] += array_sum($wpc_vp_bl_d[$wpc_vp_bl_lane]['lcp']);
        }
    }
    if ($wpc_vp_bsamp[$wpc_vp_bl_dev] >= 8) {
        $wpc_vp_blive[$wpc_vp_bl_dev] = (int) wpc_vitals_p75($wpc_vp_cur, $wpc_vp_bl_lane, 'lcp');
    }
}
$wpc_vp_base_src = 'firstweek';
if ($wpc_vp_blive['m'] > 0) {
    $wpc_vp_base_lcp = $wpc_vp_blive['m'];
    $wpc_vp_base_src = 'live';
}





$wpc_vp_curp = [
    'm' => (int) wpc_vitals_p75($wpc_vp_curM, 'm', 'lcp'),
    'd' => (int) wpc_vitals_p75($wpc_vp_curM, 'd', 'lcp'),
];
if ($wpc_vp_base_src === 'firstweek' && $wpc_vp_base_lcp > 0 && $wpc_vp_curp['m'] > 0 && $wpc_vp_base_lcp <= $wpc_vp_curp['m']) {
    $wpc_vp_base_lcp = 0;
}
if ($wpc_vp_blive['d'] === 0 && is_array($wpc_vp_baseline) && !empty($wpc_vp_baseline['lcp_d_p75'])
    && $wpc_vp_curp['d'] > 0 && (int) $wpc_vp_baseline['lcp_d_p75'] <= $wpc_vp_curp['d']) {
    $wpc_vp_baseline['lcp_d_p75'] = 0;
}

$wpc_vp_chips = [];
foreach (['m', 'd'] as $wpc_vp_dev) {
    foreach (['lcp', 'inp', 'cls', 'ttfb'] as $wpc_vp_metric) {
        $cur = (int) wpc_vitals_p75($wpc_vp_curM, $wpc_vp_dev, $wpc_vp_metric);
        $pri = (int) wpc_vitals_p75($wpc_vp_priorM, $wpc_vp_dev, $wpc_vp_metric);
        list($num, $unit) = $wpc_vp_fmt($cur, $wpc_vp_metric);
        $dot = 'na';
        if ($cur > 0) {
            $dot = $cur <= $wpc_vp_TH[$wpc_vp_metric][0] ? 'good' : ($cur <= $wpc_vp_TH[$wpc_vp_metric][1] ? 'ni' : 'poor');
        } elseif ($wpc_vp_metric === 'cls') {
            
            
            
            $wpc_cls906 = 0;
            foreach ($wpc_vp_curM as $wpc_vp_cd906) {
                if (!empty($wpc_vp_cd906[$wpc_vp_dev]['cls']) && is_array($wpc_vp_cd906[$wpc_vp_dev]['cls'])) {
                    $wpc_cls906 += (int) array_sum($wpc_vp_cd906[$wpc_vp_dev]['cls']);
                }
            }
            if ($wpc_cls906 >= 5) {
                $dot = 'good';
                $num = '0.00';
                $unit = '';
            }
        }
        $delta = '';
        $dcls  = '';
        $wpc_vp_basedev = $wpc_vp_dev === 'm' ? $wpc_vp_base_lcp
            : ($wpc_vp_blive['d'] > 0 ? $wpc_vp_blive['d']
                : ((is_array($wpc_vp_baseline) && !empty($wpc_vp_baseline['lcp_d_p75'])) ? (int) $wpc_vp_baseline['lcp_d_p75'] : 0));
        if ($wpc_vp_metric === 'lcp' && $wpc_vp_basedev > 0 && $cur > 0 && $wpc_vp_basedev !== $cur) {
            $pct   = (int) round(abs($wpc_vp_basedev - $cur) / $wpc_vp_basedev * 100);
            $delta = ($cur < $wpc_vp_basedev ? "\xE2\x96\xBC " : "\xE2\x96\xB2 ") . $pct . '% ' . __('since activation', WPS_IC_TEXTDOMAIN);
            $dcls  = $cur < $wpc_vp_basedev ? 'improve' : 'regress';
        } elseif ($pri > 0 && $cur > 0 && $pri !== $cur) {
            $pct   = (int) round(abs($pri - $cur) / $pri * 100);
            $delta = ($cur < $pri ? "\xE2\x96\xBC " : "\xE2\x96\xB2 ") . $pct . '% ' . __('vs prior 4 weeks', WPS_IC_TEXTDOMAIN);
            $dcls  = $cur < $pri ? 'improve' : 'regress';
        }
        $wpc_vp_q25 = (int) wpc_vitals_pct($wpc_vp_curM, $wpc_vp_dev, $wpc_vp_metric, 0.25);
        $wpc_vp_q50 = (int) wpc_vitals_pct($wpc_vp_curM, $wpc_vp_dev, $wpc_vp_metric, 0.5);
        list($wpc_vp_qn25, $wpc_vp_qu25) = $wpc_vp_fmt($wpc_vp_q25, $wpc_vp_metric);
        list($wpc_vp_qn50, $wpc_vp_qu50) = $wpc_vp_fmt($wpc_vp_q50, $wpc_vp_metric);
        $wpc_vp_chips[$wpc_vp_dev][$wpc_vp_metric] = ['num' => $num, 'unit' => $unit, 'dot' => $dot, 'delta' => $delta, 'dcls' => $dcls,
            'q25' => $wpc_vp_q25 > 0 ? [$wpc_vp_qn25, $wpc_vp_qu25] : null,
            'q50' => $wpc_vp_q50 > 0 ? [$wpc_vp_qn50, $wpc_vp_qu50] : null];
        if ($wpc_vp_metric === 'ttfb') {
            $wpc_vp_ttot = []; $wpc_vp_thit = [];
            foreach ($wpc_vp_curM as $wpc_vp_ttd) {
                foreach ((array) (($wpc_vp_ttd[$wpc_vp_dev] ?? [])['ttfb'] ?? []) as $wpc_vp_tb => $wpc_vp_tc) {
                    $wpc_vp_ttot[$wpc_vp_tb] = ($wpc_vp_ttot[$wpc_vp_tb] ?? 0) + (int) $wpc_vp_tc;
                }
                foreach ((array) (($wpc_vp_ttd['h' . $wpc_vp_dev] ?? [])['ttfb'] ?? []) as $wpc_vp_tb => $wpc_vp_tc) {
                    $wpc_vp_thit[$wpc_vp_tb] = ($wpc_vp_thit[$wpc_vp_tb] ?? 0) + (int) $wpc_vp_tc;
                }
            }
            $wpc_vp_tren = $wpc_vp_ttot;
            foreach ($wpc_vp_thit as $wpc_vp_tb => $wpc_vp_tc) {
                $wpc_vp_tren[$wpc_vp_tb] = max(0, ($wpc_vp_tren[$wpc_vp_tb] ?? 0) - $wpc_vp_tc);
            }
            if (array_sum($wpc_vp_thit) >= 10 && array_sum($wpc_vp_tren) >= 10) {
                $wpc_vp_th50 = (int) wpc_vitals_pct([['x' => ['ttfb' => $wpc_vp_thit]]], 'x', 'ttfb', 0.5);
                $wpc_vp_tr50 = (int) wpc_vitals_pct([['x' => ['ttfb' => $wpc_vp_tren]]], 'x', 'ttfb', 0.5);
                if ($wpc_vp_th50 > 0 && $wpc_vp_tr50 > 0 && $wpc_vp_th50 < $wpc_vp_tr50) {
                    $wpc_vp_chips[$wpc_vp_dev]['ttfb']['hit'] = $wpc_vp_fmt($wpc_vp_th50, 'ttfb');
                    $wpc_vp_chips[$wpc_vp_dev]['ttfb']['ren'] = $wpc_vp_fmt($wpc_vp_tr50, 'ttfb');
                }
            }
        }
    }
}


$wpc_vp_labels = $wpc_vp_cached = $wpc_vp_rendered = [];
$wpc_vp_lcp = ['m' => [], 'd' => []];





$wpc_vp_fcp = ['m' => [], 'd' => []];
$wpc_vp_lcpq = ['m' => ['p25' => [], 'p50' => []], 'd' => ['p25' => [], 'p50' => []]];
$wpc_vp_views28 = $wpc_vp_hits28 = $wpc_vp_raw28 = 0;
foreach ($wpc_vp_curM as $wpc_vp_k => $wpc_vp_d) {
    $s = max(1, (int) ($wpc_vp_d['s'] ?? 1));
    $v = (int) ($wpc_vp_d['v'] ?? 0);
    $h = min($v, (int) ($wpc_vp_d['hit'] ?? 0));
    $wpc_vp_labels[]   = gmdate('M j', strtotime(substr($wpc_vp_k, 0, 4) . '-' . substr($wpc_vp_k, 4, 2) . '-' . substr($wpc_vp_k, 6, 2)));
    $wpc_vp_cached[]   = $h * $s;
    $wpc_vp_rendered[] = ($v - $h) * $s;
    $wpc_vp_views28   += $v * $s;
    $wpc_vp_hits28    += $h;
    $wpc_vp_raw28     += $v;
    foreach (['m', 'd'] as $wpc_vp_dev) {
        $p = (int) wpc_vitals_p75([$wpc_vp_d], $wpc_vp_dev, 'lcp');
        $wpc_vp_lcp[$wpc_vp_dev][] = $p > 0 ? $p : null;
        $wpc_vp_pf27 = (int) wpc_vitals_p75([$wpc_vp_d], $wpc_vp_dev, 'fcp');
        $wpc_vp_fcp[$wpc_vp_dev][] = $wpc_vp_pf27 > 0 ? $wpc_vp_pf27 : null;
        foreach (['p25' => 0.25, 'p50' => 0.5] as $wpc_vp_qk => $wpc_vp_qv) {
            $q = (int) wpc_vitals_pct([$wpc_vp_d], $wpc_vp_dev, 'lcp', $wpc_vp_qv);
            $wpc_vp_lcpq[$wpc_vp_dev][$wpc_vp_qk][] = $q > 0 ? $q : null;
        }
    }
}
$wpc_vp_share = $wpc_vp_raw28 > 0 ? (int) round($wpc_vp_hits28 / $wpc_vp_raw28 * 100) : 0;





$wpc_vp_fmeta = ['m' => ['fn' => 0, 'met' => 0], 'd' => ['fn' => 0, 'met' => 0]];
foreach ($wpc_vp_curM as $wpc_vp_fd28) {
    foreach (['m', 'd'] as $wpc_vp_fv28) {
        foreach (['lcp', 'fcp', 'ttfb'] as $wpc_vp_fm28) {
            $wpc_vp_fc28 = (!empty($wpc_vp_fd28[$wpc_vp_fv28][$wpc_vp_fm28]) && is_array($wpc_vp_fd28[$wpc_vp_fv28][$wpc_vp_fm28]))
                ? (int) array_sum($wpc_vp_fd28[$wpc_vp_fv28][$wpc_vp_fm28]) : 0;
            $wpc_vp_fmeta[$wpc_vp_fv28]['met'] += $wpc_vp_fc28;
            if ($wpc_vp_fm28 === 'fcp') { $wpc_vp_fmeta[$wpc_vp_fv28]['fn'] += $wpc_vp_fc28; }
        }
    }
}


$wpc_vp_cur_lcp_m  = (int) wpc_vitals_p75($wpc_vp_curM, 'm', 'lcp');
$wpc_vp_banner     = '';
$wpc_vp_banner_sub = '';
if ($wpc_vp_base_lcp > 0 && $wpc_vp_cur_lcp_m > 0 && $wpc_vp_cur_lcp_m < $wpc_vp_base_lcp && round((1 - $wpc_vp_cur_lcp_m / $wpc_vp_base_lcp) * 100) >= 5) {
    $wpc_vp_imp = (int) round((1 - $wpc_vp_cur_lcp_m / $wpc_vp_base_lcp) * 100);
    list($bn, $bu) = $wpc_vp_fmt($wpc_vp_base_lcp, 'lcp');
    list($cn, $cu) = $wpc_vp_fmt($wpc_vp_cur_lcp_m, 'lcp');
    $wpc_vp_banner     = sprintf(__("Your visitors' experience improved %d%% since optimization began.", WPS_IC_TEXTDOMAIN), $wpc_vp_imp);
    $wpc_vp_banner_sub = sprintf(__('p75 LCP went from %1$s to %2$s', WPS_IC_TEXTDOMAIN), $bn . $bu, $cn . $cu)
        . ' · ' . sprintf(__('%d%% of pageviews served from cache', WPS_IC_TEXTDOMAIN), $wpc_vp_share)
        . ' · ' . sprintf(__('%s optimized views in the past 28 days', WPS_IC_TEXTDOMAIN), number_format($wpc_vp_views28));
} elseif ($wpc_vp_views28 > 0) {
    $wpc_vp_banner     = __('Optimized delivery is live — measuring your visitors\' real experience.', WPS_IC_TEXTDOMAIN);
    $wpc_vp_banner_sub = sprintf(__('%s views in the past 28 days', WPS_IC_TEXTDOMAIN), number_format($wpc_vp_views28))
        . ' · ' . sprintf(__('%d%% served from cache', WPS_IC_TEXTDOMAIN), $wpc_vp_share);
}


$wpc_vp_matrix = ['m' => [], 'd' => []];
foreach (['lcp', 'inp', 'cls', 'ttfb'] as $wpc_vp_mm) {
    foreach (['m', 'd'] as $wpc_vp_dv) {
        $wpc_vp_matrix[$wpc_vp_dv][$wpc_vp_mm] = [];
    }
}
foreach ($wpc_vp_curM as $wpc_vp_k => $wpc_vp_d) {
    foreach (['m', 'd'] as $wpc_vp_dv) {
        foreach (['lcp', 'inp', 'cls', 'ttfb'] as $wpc_vp_mm) {
            $wpc_vp_pv = (int) wpc_vitals_p75([$wpc_vp_d], $wpc_vp_dv, $wpc_vp_mm);
            $wpc_vp_matrix[$wpc_vp_dv][$wpc_vp_mm][] = $wpc_vp_pv > 0 ? $wpc_vp_pv : null;
        }
    }
}



$wpc_vp_tlidx = [];
$wpc_vp_ti863 = 0;
foreach ($wpc_vp_curM as $wpc_vp_d863) {
    if ((int) ($wpc_vp_d863['v'] ?? 0) > 0 || !empty($wpc_vp_d863['m']['lcp']) || !empty($wpc_vp_d863['d']['lcp'])) {
        $wpc_vp_tlidx[] = $wpc_vp_ti863;
    }
    $wpc_vp_ti863++;
}




$wpc_vp_events = [];
if (function_exists('wpc_vitals_panel_events')) {
    $wpc_vp_events = wpc_vitals_panel_events($wpc_vp_curM);
} else {
    $wpc_vp_evmap = [
        'link-preset'       => [1, __('Optimization connected', WPS_IC_TEXTDOMAIN), __('One-click preset armed critical CSS, script delay and the image CDN', WPS_IC_TEXTDOMAIN)],
        'upgrade-resync'    => [2, __('Plugin updated', WPS_IC_TEXTDOMAIN), __('Every optimization artifact was refreshed automatically', WPS_IC_TEXTDOMAIN)],
        'natural-converged' => [3, __('CDN delivery upgraded', WPS_IC_TEXTDOMAIN), __('Assets switched to clean, cache-friendly CDN URLs', WPS_IC_TEXTDOMAIN)],
        'corp-guard-armed'  => [4, __('Image delivery protected', WPS_IC_TEXTDOMAIN), __('A blocking security header was neutralized automatically', WPS_IC_TEXTDOMAIN)],
        'auto-flip'         => [5, __('Auto Mode improvement kept', WPS_IC_TEXTDOMAIN), __('A measured optimization passed its check and stayed on', WPS_IC_TEXTDOMAIN)],
        'r2-rollback'       => [6, __('Auto Mode reverted a change', WPS_IC_TEXTDOMAIN), __('A change that did not help was rolled back automatically', WPS_IC_TEXTDOMAIN)],
        'rebuild-page'      => [7, __('Page rebuilt', WPS_IC_TEXTDOMAIN), __('A page was regenerated on request', WPS_IC_TEXTDOMAIN)],
    ];
    $wpc_vp_daykeys = array_keys($wpc_vp_curM);
    $wpc_vp_seen = [];
    $wpc_vp_lf = defined('WPS_IC_CACHE') ? rtrim(WPS_IC_CACHE, '/') . '/wpc-cflog.jsonl' : '';
    if ($wpc_vp_lf !== '' && @is_readable($wpc_vp_lf)) {
        foreach (array_reverse(explode("\n", trim((string) @file_get_contents($wpc_vp_lf)))) as $wpc_vp_ln) {
            $wpc_vp_e = json_decode($wpc_vp_ln, true);
            if (!is_array($wpc_vp_e) || empty($wpc_vp_e['event']) || empty($wpc_vp_e['t'])) { continue; }
            $wpc_vp_ek = (string) $wpc_vp_e['event'];
            if (!isset($wpc_vp_evmap[$wpc_vp_ek])) { continue; }
            $wpc_vp_di = array_search(gmdate('Ymd', (int) $wpc_vp_e['t']), $wpc_vp_daykeys, true);
            if ($wpc_vp_di === false || isset($wpc_vp_seen[$wpc_vp_ek . '|' . $wpc_vp_di])) { continue; }
            $wpc_vp_seen[$wpc_vp_ek . '|' . $wpc_vp_di] = 1;
            $wpc_vp_events[] = ['i' => (int) $wpc_vp_di, 'p' => $wpc_vp_evmap[$wpc_vp_ek][0],
                't' => $wpc_vp_evmap[$wpc_vp_ek][1], 's' => $wpc_vp_evmap[$wpc_vp_ek][2]];
        }
    }
    $wpc_vp_lpj = get_option('wpc_link_preset_journal', []);
    if (is_array($wpc_vp_lpj)) {
        foreach ($wpc_vp_lpj as $wpc_vp_j) {
            if (is_array($wpc_vp_j) && (($wpc_vp_j['event'] ?? '') === 'applied') && !empty($wpc_vp_j['t'])) {
                $wpc_vp_di = array_search(gmdate('Ymd', (int) $wpc_vp_j['t']), $wpc_vp_daykeys, true);
                if ($wpc_vp_di !== false && !isset($wpc_vp_seen['link-preset|' . $wpc_vp_di])) {
                    $wpc_vp_seen['link-preset|' . $wpc_vp_di] = 1;
                    $wpc_vp_events[] = ['i' => (int) $wpc_vp_di, 'p' => 1,
                        't' => $wpc_vp_evmap['link-preset'][1], 's' => $wpc_vp_evmap['link-preset'][2]];
                }
            }
        }
    }
    usort($wpc_vp_events, function ($a, $b) { return ($a['p'] <=> $b['p']) ?: ($a['i'] <=> $b['i']); });
    $wpc_vp_events = array_slice($wpc_vp_events, 0, 6);
    usort($wpc_vp_events, function ($a, $b) { return $a['i'] <=> $b['i']; });
}




$wpc_vp_lanecount = function ($days, $lane, $metric) {
    $n = 0;
    foreach ($days as $d) {
        if (!empty($d[$lane][$metric]) && is_array($d[$lane][$metric])) { $n += array_sum($d[$lane][$metric]); }
    }
    return $n;
};


$wpc_vp_brand = function_exists('wpc_get_plugin_name') ? wpc_get_plugin_name() : __('WP Compress', WPS_IC_TEXTDOMAIN);
$wpc_vp_spec = [];
foreach (['m', 'd'] as $wpc_vp_sd) {
    $wpc_vp_sn  = $wpc_vp_lanecount($wpc_vp_curM, $wpc_vp_sd, 'lcp');
    $wpc_vp_shn = $wpc_vp_lanecount($wpc_vp_curM, 'h' . $wpc_vp_sd, 'lcp');
    list($wpc_vp_sh1, $wpc_vp_sh1n) = function_exists('wpc_vitals_share_below')
        ? wpc_vitals_share_below($wpc_vp_curM, $wpc_vp_sd, 'lcp', 1000) : [0, 0];
    $wpc_vp_sbase = $wpc_vp_sd === 'm' ? $wpc_vp_base_lcp
        : ($wpc_vp_blive['d'] > 0 ? $wpc_vp_blive['d']
            : ((is_array($wpc_vp_baseline) && !empty($wpc_vp_baseline['lcp_d_p75'])) ? (int) $wpc_vp_baseline['lcp_d_p75'] : 0));
    $wpc_vp_sp75 = (int) wpc_vitals_p75($wpc_vp_curM, $wpc_vp_sd, 'lcp');
    
    $wpc_vp_ghost = 0;
    if ($wpc_vp_base_src === 'live' && $wpc_vp_bsamp[$wpc_vp_sd] >= 8 && $wpc_vp_sbase > 0 && $wpc_vp_sp75 > 0
        && ($wpc_vp_sbase >= $wpc_vp_sp75 * 1.25 || $wpc_vp_sbase - $wpc_vp_sp75 >= 600)) {
        $wpc_vp_ghost = $wpc_vp_sbase;
    }
    $wpc_vp_spec[$wpc_vp_sd] = [
        'opt'  => $wpc_vp_shn >= 10 ? (int) wpc_vitals_pct($wpc_vp_curM, 'h' . $wpc_vp_sd, 'lcp', 0.5) : 0,
        'p50'  => $wpc_vp_sn >= 10 ? (int) wpc_vitals_pct($wpc_vp_curM, $wpc_vp_sd, 'lcp', 0.5) : 0,
        'p75'  => $wpc_vp_sp75,
        'n'    => $wpc_vp_sn,
        'hn'   => $wpc_vp_shn,
        'sh1'  => ($wpc_vp_sh1n >= 50) ? (int) round($wpc_vp_sh1 * 100) : -1,
        'ghost' => $wpc_vp_ghost,
    ];
}

$wpc_vp_regnames = [1 => 'NA', 2 => 'EU', 3 => 'APAC', 4 => 'LATAM', 5 => 'MEA'];
$wpc_vp_regv = [];
foreach ($wpc_vp_curM as $wpc_vp_rd) {
    if (!empty($wpc_vp_rd['rv']) && is_array($wpc_vp_rd['rv'])) {
        foreach ($wpc_vp_rd['rv'] as $wpc_vp_rg => $wpc_vp_rc) {
            $wpc_vp_regv[(int) $wpc_vp_rg] = ($wpc_vp_regv[(int) $wpc_vp_rg] ?? 0) + (int) $wpc_vp_rc;
        }
    }
}
arsort($wpc_vp_regv);
$wpc_vp_reg = [];
$wpc_vp_regtot = array_sum($wpc_vp_regv);
foreach ($wpc_vp_regv as $wpc_vp_rg => $wpc_vp_rc) {
    if ($wpc_vp_rc < 20 || !isset($wpc_vp_regnames[$wpc_vp_rg])) { continue; }
    $wpc_vp_rp75 = (int) wpc_vitals_p75($wpc_vp_curM, 'r' . $wpc_vp_rg, 'lcp');
    $wpc_vp_rser = [];
    foreach ($wpc_vp_curM as $wpc_vp_rdd) {
        $wpc_vp_rpv = (int) wpc_vitals_p75([$wpc_vp_rdd], 'r' . $wpc_vp_rg, 'lcp');
        $wpc_vp_rser[] = $wpc_vp_rpv > 0 ? $wpc_vp_rpv : null;
    }
    $wpc_vp_reg[] = ['c' => (int) $wpc_vp_rg, 'label' => $wpc_vp_regnames[$wpc_vp_rg], 'v' => $wpc_vp_rc,
        'share' => $wpc_vp_regtot > 0 ? (int) round($wpc_vp_rc / $wpc_vp_regtot * 100) : 0,
        'p75' => $wpc_vp_rp75, 'ser' => $wpc_vp_rser];
}
if (count($wpc_vp_reg) < 2) { $wpc_vp_reg = []; }


$wpc_vp_specm = $wpc_vp_spec['m'];
$wpc_vp_bpair = null;
if ($wpc_vp_specm['ghost'] > 0 && $wpc_vp_bsamp['m'] >= 30 && $wpc_vp_cur_lcp_m > 0) {
    $wpc_vp_g50 = (int) wpc_vitals_pct($wpc_vp_curM, 'bm', 'lcp', 0.5);
    $wpc_vp_c50 = (int) $wpc_vp_specm['p50'];
    if ($wpc_vp_g50 > 0 && $wpc_vp_c50 > 0
        && ($wpc_vp_g50 >= $wpc_vp_c50 * 1.25 || $wpc_vp_g50 - $wpc_vp_c50 >= 600)) {
        list($wpc_vp_gn, $wpc_vp_gu) = $wpc_vp_fmt($wpc_vp_g50, 'lcp');
        list($wpc_vp_cn2, $wpc_vp_cu2) = $wpc_vp_fmt($wpc_vp_c50, 'lcp');
        $wpc_vp_bpair = ['off' => (int) $wpc_vp_g50, 'on' => (int) $wpc_vp_c50, 'avg' => 1];
        $wpc_vp_banner = sprintf(__('Your average visitor loads in %1$s — without %2$s they would wait %3$s.', WPS_IC_TEXTDOMAIN), $wpc_vp_cn2 . $wpc_vp_cu2, $wpc_vp_brand, $wpc_vp_gn . $wpc_vp_gu);
    } else {
        list($wpc_vp_gn, $wpc_vp_gu) = $wpc_vp_fmt($wpc_vp_specm['ghost'], 'lcp');
        list($wpc_vp_cn2, $wpc_vp_cu2) = $wpc_vp_fmt($wpc_vp_cur_lcp_m, 'lcp');
        $wpc_vp_bpair = ['off' => (int) $wpc_vp_specm['ghost'], 'on' => (int) $wpc_vp_cur_lcp_m, 'avg' => 0];
        $wpc_vp_banner = sprintf(__('Your visitors load in %1$s — without %2$s they would wait %3$s.', WPS_IC_TEXTDOMAIN), $wpc_vp_cn2 . $wpc_vp_cu2, $wpc_vp_brand, $wpc_vp_gn . $wpc_vp_gu);
    }
    $wpc_vp_mins = (int) floor(max(0, $wpc_vp_specm['ghost'] - $wpc_vp_cur_lcp_m) * $wpc_vp_views28 / 60000);
    list($wpc_vp_on, $wpc_vp_ou) = $wpc_vp_fmt((int) $wpc_vp_specm['opt'], 'lcp');
    $wpc_vp_banner_sub = sprintf(__('Measured on your own traffic (%d comparison visits)', WPS_IC_TEXTDOMAIN), (int) $wpc_vp_bsamp['m'])
        . ($wpc_vp_specm['opt'] > 0 ? ' · ' . sprintf(__('fully optimized visits paint in %s', WPS_IC_TEXTDOMAIN), $wpc_vp_on . $wpc_vp_ou) : '')
        . ($wpc_vp_mins > 0 ? ' · ' . sprintf(__('roughly %s minutes of waiting saved this month', WPS_IC_TEXTDOMAIN), number_format($wpc_vp_mins)) : '')
        . ' · ' . sprintf(__('%d%%%% of pageviews served from cache', WPS_IC_TEXTDOMAIN), $wpc_vp_share);
    $wpc_vp_banner_sub = str_replace('%%', '%', $wpc_vp_banner_sub);
} elseif ($wpc_vp_banner === '' && $wpc_vp_specm['sh1'] >= 40) {
    $wpc_vp_banner = sprintf(__('%d%%%% of your visits painted in under 1 second.', WPS_IC_TEXTDOMAIN), $wpc_vp_specm['sh1']);
    $wpc_vp_banner = str_replace('%%', '%', $wpc_vp_banner);
    $wpc_vp_banner_sub = sprintf(__('%s optimized views in the past 28 days', WPS_IC_TEXTDOMAIN), number_format($wpc_vp_views28))
        . ' · ' . sprintf(__('%d%% served from cache', WPS_IC_TEXTDOMAIN), $wpc_vp_share);
}





$wpc_vp_demo = ($wpc_vp_views28 < 1 && $wpc_vp_cur_lcp_m < 1 && apply_filters('wpc_vitals_sample_preview', true));
if ($wpc_vp_demo) {
    $wpc_vp_labels = $wpc_vp_cached = $wpc_vp_rendered = [];
    $wpc_vp_lcp = ['m' => [], 'd' => []];
    $wpc_vp_lcpq = ['m' => ['p25' => [], 'p50' => []], 'd' => ['p25' => [], 'p50' => []]];
    $wpc_vp_matrix = ['m' => [], 'd' => []];
    foreach (['lcp', 'inp', 'cls', 'ttfb'] as $wpc_vp_dm) { $wpc_vp_matrix['m'][$wpc_vp_dm] = []; $wpc_vp_matrix['d'][$wpc_vp_dm] = []; }
    for ($wpc_vp_di = 27; $wpc_vp_di >= 0; $wpc_vp_di--) {
        $wpc_vp_labels[] = gmdate('M j', time() - $wpc_vp_di * 86400);
        $wpc_vp_t = (27 - $wpc_vp_di) / 27;
        $wpc_vp_ease = 1 - pow(1 - $wpc_vp_t, 2);
        $wpc_vp_m75 = (int) (round((3400 - 2000 * $wpc_vp_ease) / 50) * 50);
        $wpc_vp_d75 = (int) (round((2300 - 1400 * $wpc_vp_ease) / 50) * 50);
        $wpc_vp_lcp['m'][] = $wpc_vp_m75; $wpc_vp_lcp['d'][] = $wpc_vp_d75;
        $wpc_vp_lcpq['m']['p50'][] = (int) round($wpc_vp_m75 * 0.7); $wpc_vp_lcpq['m']['p25'][] = (int) round($wpc_vp_m75 * 0.45);
        $wpc_vp_lcpq['d']['p50'][] = (int) round($wpc_vp_d75 * 0.7); $wpc_vp_lcpq['d']['p25'][] = (int) round($wpc_vp_d75 * 0.45);
        $wpc_vp_v = (int) (40 + 150 * $wpc_vp_t); $wpc_vp_hshare = 0.12 + 0.6 * $wpc_vp_ease;
        $wpc_vp_cached[] = (int) round($wpc_vp_v * $wpc_vp_hshare); $wpc_vp_rendered[] = $wpc_vp_v - (int) round($wpc_vp_v * $wpc_vp_hshare);
        $wpc_vp_matrix['m']['lcp'][] = $wpc_vp_m75; $wpc_vp_matrix['d']['lcp'][] = $wpc_vp_d75;
        $wpc_vp_matrix['m']['inp'][] = (int) (220 - 120 * $wpc_vp_ease); $wpc_vp_matrix['d']['inp'][] = (int) (140 - 80 * $wpc_vp_ease);
        $wpc_vp_matrix['m']['cls'][] = (int) (140 - 120 * $wpc_vp_ease); $wpc_vp_matrix['d']['cls'][] = (int) (90 - 80 * $wpc_vp_ease);
        $wpc_vp_matrix['m']['ttfb'][] = (int) (900 - 500 * $wpc_vp_ease); $wpc_vp_matrix['d']['ttfb'][] = (int) (700 - 420 * $wpc_vp_ease);
    }
    $wpc_vp_demo_chip = function ($m75, $metric) use ($wpc_vp_fmt, $wpc_vp_TH) {
        list($n, $u) = $wpc_vp_fmt($m75, $metric);
        list($n25, $u25) = $wpc_vp_fmt((int) round($m75 * 0.45), $metric);
        list($n50, $u50) = $wpc_vp_fmt((int) round($m75 * 0.7), $metric);
        $dot = $m75 <= $wpc_vp_TH[$metric][0] ? 'good' : ($m75 <= $wpc_vp_TH[$metric][1] ? 'ni' : 'poor');
        return ['num' => $n, 'unit' => $u, 'dot' => $dot, 'delta' => '', 'dcls' => '', 'q25' => [$n25, $u25], 'q50' => [$n50, $u50]];
    };
    foreach ([['m', 1400, 100, 20, 400], ['d', 900, 60, 10, 280]] as $wpc_vp_dc) {
        $wpc_vp_chips[$wpc_vp_dc[0]] = [
            'lcp'  => $wpc_vp_demo_chip($wpc_vp_dc[1], 'lcp'),
            'inp'  => $wpc_vp_demo_chip($wpc_vp_dc[2], 'inp'),
            'cls'  => $wpc_vp_demo_chip($wpc_vp_dc[3], 'cls'),
            'ttfb' => $wpc_vp_demo_chip($wpc_vp_dc[4], 'ttfb'),
        ];
        $wpc_vp_chips[$wpc_vp_dc[0]]['lcp']['delta'] = "\xE2\x96\xBC 58% " . __('example trend', WPS_IC_TEXTDOMAIN);
        $wpc_vp_chips[$wpc_vp_dc[0]]['lcp']['dcls'] = 'improve';
    }
    $wpc_vp_spec = [
        'm' => ['opt' => 300, 'p50' => 980, 'p75' => 1400, 'n' => 2400, 'hn' => 1700, 'sh1' => 52, 'ghost' => 3400],
        'd' => ['opt' => 220, 'p50' => 640, 'p75' => 900, 'n' => 1600, 'hn' => 1200, 'sh1' => 71, 'ghost' => 2300],
    ];
    $wpc_vp_reg = [
        ['c' => 2, 'label' => 'EU', 'v' => 1980, 'share' => 52, 'p75' => 1250, 'ser' => $wpc_vp_lcp['m']],
        ['c' => 1, 'label' => 'NA', 'v' => 1830, 'share' => 48, 'p75' => 1550, 'ser' => $wpc_vp_lcp['m']],
    ];
    $wpc_vp_base_lcp = 3400;
    $wpc_vp_blive['d'] = 2300;
    $wpc_vp_base_src = 'live';
    $wpc_vp_tlidx = range(0, 27);
    $wpc_vp_banner = __('This is a sample preview — measuring your real visitors has started.', WPS_IC_TEXTDOMAIN);
    $wpc_vp_banner_sub = __('Every number shown is example data so you can explore the views. Your own measurements replace it automatically — starting with today\'s very first visits.', WPS_IC_TEXTDOMAIN);
}








$wpc_vp_mat = ['m' => ['n' => 0, 'days' => 0, 'ok' => 1], 'd' => ['n' => 0, 'days' => 0, 'ok' => 1]];
if (!$wpc_vp_demo && (!function_exists('apply_filters') || apply_filters('wpc_vitals_maturity_gate', true))) {
    $wpc_vp_matmin = function_exists('apply_filters') ? (int) apply_filters('wpc_vitals_mature_n', 50) : 50;
    foreach (['m', 'd'] as $wpc_vp_gdv) {
        $wpc_vp_gn905 = 0;
        $wpc_vp_gd905 = 0;
        foreach ($wpc_vp_curM as $wpc_vp_gday) {
            $wpc_vp_gc905 = (!empty($wpc_vp_gday[$wpc_vp_gdv]['lcp']) && is_array($wpc_vp_gday[$wpc_vp_gdv]['lcp']))
                ? (int) array_sum($wpc_vp_gday[$wpc_vp_gdv]['lcp']) : 0;
            if ($wpc_vp_gc905 > 0) { $wpc_vp_gn905 += $wpc_vp_gc905; $wpc_vp_gd905++; }
        }
        $wpc_vp_gok = ($wpc_vp_gn905 >= $wpc_vp_matmin || $wpc_vp_gd905 >= 5) ? 1 : 0;
        $wpc_vp_mat[$wpc_vp_gdv] = ['n' => $wpc_vp_gn905, 'days' => $wpc_vp_gd905, 'ok' => $wpc_vp_gok, 'min' => $wpc_vp_matmin];
        if (!$wpc_vp_gok) {
            $wpc_vp_gcnt = count($wpc_vp_lcp[$wpc_vp_gdv]);
            $wpc_vp_lcp[$wpc_vp_gdv] = $wpc_vp_gcnt > 0 ? array_fill(0, $wpc_vp_gcnt, null) : [];
            $wpc_vp_lcpq[$wpc_vp_gdv] = [
                'p25' => $wpc_vp_gcnt > 0 ? array_fill(0, $wpc_vp_gcnt, null) : [],
                'p50' => $wpc_vp_gcnt > 0 ? array_fill(0, $wpc_vp_gcnt, null) : [],
            ];
            foreach (['lcp', 'inp', 'cls', 'ttfb'] as $wpc_vp_gmm) {
                $wpc_vp_gmc = count($wpc_vp_matrix[$wpc_vp_gdv][$wpc_vp_gmm]);
                $wpc_vp_matrix[$wpc_vp_gdv][$wpc_vp_gmm] = $wpc_vp_gmc > 0 ? array_fill(0, $wpc_vp_gmc, null) : [];
                list($wpc_vp_gz1, $wpc_vp_gz2) = $wpc_vp_fmt(0, $wpc_vp_gmm);
                $wpc_vp_chips[$wpc_vp_gdv][$wpc_vp_gmm] = ['num' => $wpc_vp_gz1, 'unit' => $wpc_vp_gz2,
                    'dot' => 'na', 'delta' => '', 'dcls' => '', 'q25' => null, 'q50' => null];
            }
            $wpc_vp_spec[$wpc_vp_gdv] = ['opt' => 0, 'p50' => 0, 'p75' => 0, 'n' => 0, 'hn' => 0, 'sh1' => -1, 'ghost' => 0];
        }
        
        
        
        
        
        
        $wpc_vp_gfn27 = 0;
        $wpc_vp_gfd27 = 0;
        foreach ($wpc_vp_curM as $wpc_vp_gday27) {
            $wpc_vp_gfc27 = (!empty($wpc_vp_gday27[$wpc_vp_gdv]['fcp']) && is_array($wpc_vp_gday27[$wpc_vp_gdv]['fcp']))
                ? (int) array_sum($wpc_vp_gday27[$wpc_vp_gdv]['fcp']) : 0;
            if ($wpc_vp_gfc27 > 0) { $wpc_vp_gfn27 += $wpc_vp_gfc27; $wpc_vp_gfd27++; }
        }
        $wpc_vp_gfok28 = ($wpc_vp_gfn27 >= $wpc_vp_matmin || $wpc_vp_gfd27 >= 5)
            || ($wpc_vp_mat[$wpc_vp_gdv]['n'] < 1
                && ($wpc_vp_gfn27 >= (int) apply_filters('wpc_vitals_fcp_floor', 12) || $wpc_vp_gfd27 >= 3));
        if (!$wpc_vp_gfok28) {
            $wpc_vp_gfcnt27 = count($wpc_vp_fcp[$wpc_vp_gdv]);
            $wpc_vp_fcp[$wpc_vp_gdv] = $wpc_vp_gfcnt27 > 0 ? array_fill(0, $wpc_vp_gfcnt27, null) : [];
        }
    }
    if (!$wpc_vp_mat['m']['ok']) {
        $wpc_vp_bpair = null;
        if ($wpc_vp_views28 > 0) {
            $wpc_vp_banner = __('Optimized delivery is live — measuring your visitors\' real experience.', WPS_IC_TEXTDOMAIN);
            $wpc_vp_banner_sub = sprintf(__('%s views in the past 28 days', WPS_IC_TEXTDOMAIN), number_format($wpc_vp_views28))
                . ' · ' . sprintf(__('%d%% served from cache', WPS_IC_TEXTDOMAIN), $wpc_vp_share);
        }
    }
    if (!$wpc_vp_mat['m']['ok'] && !$wpc_vp_mat['d']['ok']) { $wpc_vp_reg = []; }
}
$wpc_vp_have4 = ['m' => true, 'd' => true];
foreach (['m', 'd'] as $wpc_vp_dv860) {
    foreach (['lcp', 'inp', 'cls', 'ttfb'] as $wpc_vp_m850) {
        if (($wpc_vp_chips[$wpc_vp_dv860][$wpc_vp_m850]['dot'] ?? 'na') === 'na') { $wpc_vp_have4[$wpc_vp_dv860] = false; }
    }
}
$wpc_vp_defdev = (!$wpc_vp_have4['m'] && $wpc_vp_have4['d']) ? 'd' : 'm';
$wpc_vp_payload = [
    'have4'  => ['m' => $wpc_vp_have4['m'] ? 1 : 0, 'd' => $wpc_vp_have4['d'] ? 1 : 0],
    'defdev' => $wpc_vp_defdev,
    'bl'     => (!$wpc_vp_demo && function_exists('apply_filters') && apply_filters('wpc_vitals_auto_baseline', true)) ? [
        'm' => max(0, 8 - (int) $wpc_vp_bsamp['m']),
        'd' => max(0, 8 - (int) $wpc_vp_bsamp['d']),
        'u' => (function_exists('home_url') ? home_url('/') : '/') . '?disableWPC=true',
    ] : null,
    'sc'     => (function_exists('apply_filters') && apply_filters('wpc_vitals_speed_check', true)) ? [
        'u'  => (function_exists('home_url') ? home_url('/') : '/'),
        'ub' => (function_exists('home_url') ? home_url('/') : '/') . '?disableWPC=true',
    ] : null,
    'spec'   => $wpc_vp_spec,
    'bpair'  => $wpc_vp_bpair,
    'reg'    => $wpc_vp_reg,
    'v28'    => (int) $wpc_vp_views28,
    'tlx'    => $wpc_vp_tlidx,
    'labels'   => $wpc_vp_labels,
    'cached'   => $wpc_vp_cached,
    'rendered' => $wpc_vp_rendered,
    'lcp'      => $wpc_vp_lcp,
    'fcp'      => $wpc_vp_fcp,
    'fmeta'    => $wpc_vp_fmeta,
    'scr'      => (!$wpc_vp_demo && function_exists('wpc_vitals_sc_sane'))
        ? wpc_vitals_sc_sane(get_option('wpc_vitals_sc')) : null,
    'scn'      => function_exists('wp_create_nonce') ? wp_create_nonce('wpc_vp_sc') : '',
    'lcpq'     => $wpc_vp_lcpq,
    'chips'    => $wpc_vp_chips,
    'matrix'   => $wpc_vp_matrix,
    'events'   => $wpc_vp_events,
    'base'     => [
        'm' => $wpc_vp_base_lcp,
        'd' => $wpc_vp_blive['d'] > 0 ? $wpc_vp_blive['d']
            : ((is_array($wpc_vp_baseline) && !empty($wpc_vp_baseline['lcp_d_p75'])) ? (int) $wpc_vp_baseline['lcp_d_p75'] : 0),
        'label' => $wpc_vp_base_src === 'live'
            ? sprintf(__('Without %s', WPS_IC_TEXTDOMAIN), $wpc_vp_brand)
            : __('Before optimization', WPS_IC_TEXTDOMAIN),
    ],
    'th'       => $wpc_vp_TH,
    'mat'      => ['m' => (int) $wpc_vp_mat['m']['ok'], 'd' => (int) $wpc_vp_mat['d']['ok']],
    'demo'     => $wpc_vp_demo ? 1 : 0,
    'bnrg'     => ($wpc_vp_banner === __('Optimized delivery is live — measuring your visitors\' real experience.', WPS_IC_TEXTDOMAIN)) ? 1 : 0,
];
$wpc_vp_metric_names = ['lcp' => 'LCP', 'inp' => 'INP', 'cls' => 'CLS', 'ttfb' => 'TTFB'];
?>
<div class="wpc-vitals-panel" id="wpc-vitals-panel">
    <div class="wpc-vitals-head">
        <div class="wpc-vitals-titles">
            <h3><?php echo esc_html__('Real-User Performance', WPS_IC_TEXTDOMAIN); ?></h3>
            <p><?php echo esc_html__('Measured from your actual visitors — anonymous, no cookies, no consent needed · updates through the day', WPS_IC_TEXTDOMAIN); ?></p>
        </div>
        <div class="wpc-vitals-controls">
            <div class="wpc-vitals-toggle" role="tablist">
                <button type="button" class="<?php echo $wpc_vp_defdev === 'm' ? 'active' : ''; ?>" aria-pressed="<?php echo $wpc_vp_defdev === 'm' ? 'true' : 'false'; ?>" data-wpc-dev="m"><?php echo esc_html__('Mobile', WPS_IC_TEXTDOMAIN); ?></button>
                <button type="button" class="<?php echo $wpc_vp_defdev === 'd' ? 'active' : ''; ?>" aria-pressed="<?php echo $wpc_vp_defdev === 'd' ? 'true' : 'false'; ?>" data-wpc-dev="d"><?php echo esc_html__('Desktop', WPS_IC_TEXTDOMAIN); ?></button>
            </div>
            <span class="wpc-vitals-range"><?php echo esc_html__('28 days', WPS_IC_TEXTDOMAIN); ?></span>
        </div>
    </div>


    <?php if ($wpc_vp_banner !== '') : ?>
        <div class="wpc-vitals-banner">
            <span class="wpc-vitals-banner-ic">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M13 2 4.5 13.5H11L10 22l8.5-11.5H12L13 2Z" fill="#ffffff" stroke="#ffffff" stroke-width="1" stroke-linejoin="round"/></svg>
            </span>
            <div class="wpc-vitals-banner-txt">
                <strong><?php echo esc_html($wpc_vp_banner); ?><?php if ($wpc_vp_demo) : ?> <span class="wpc-vitals-demo-badge"><?php echo esc_html__('Sample data', WPS_IC_TEXTDOMAIN); ?></span><?php endif; ?></strong>
                <span><?php echo esc_html($wpc_vp_banner_sub); ?></span>
            </div>
        </div>
    <?php endif; ?>


    <div class="wpc-vitals-sc" id="wpc-vp-sc" style="display:none" aria-live="polite">
        <span class="wpc-vitals-sc-dot" id="wpc-vp-sc-dot"></span>
        <div class="wpc-vitals-sc-txt" id="wpc-vp-sc-txt"></div>
    </div>

    <div class="wpc-vitals-viewbar">
        <div class="wpc-vitals-viewseg" role="tablist">
            <button type="button" role="tab" aria-selected="true" class="active" data-wpc-view="timeline"><?php echo esc_html__('Timeline', WPS_IC_TEXTDOMAIN); ?></button>
            <button type="button" role="tab" aria-selected="false" data-wpc-view="experience" id="wpc-vp-expbtn" style="display:none"><?php echo esc_html__('Experience', WPS_IC_TEXTDOMAIN); ?></button>
            <button type="button" role="tab" aria-selected="false" data-wpc-view="details"><?php echo esc_html__('Details', WPS_IC_TEXTDOMAIN); ?></button>
        </div>
        <div class="wpc-vitals-viewbar-right">
        <button type="button" class="wpc-vp-scbadge" id="wpc-vp-scbadge" style="display:none" aria-expanded="false"></button>
        <div class="wpc-vitals-chippct" id="wpc-vp-chippct" style="display:none"<?php echo $wpc_vp_have4['m'] || $wpc_vp_have4['d'] || $wpc_vp_demo ? '' : ' data-wpc-empty="1"'; ?>>
            <button type="button" data-wpc-cpct="p25">p25</button>
            <button type="button" data-wpc-cpct="p50">p50</button>
            <button type="button" class="active" data-wpc-cpct="p75">p75</button>
        </div>
        </div>
    </div>

    <div class="wpc-vitals-view" id="wpc-vp-timeline">
        <div class="wpc-vitals-svgwrap" id="wpc-vp-tl"></div>
        <div class="wpc-vp-tlkey">
            <span id="wpc-vp-tlkey-line"><i class="wpc-vp-sw-line"></i><span id="wpc-vp-tlkey-linelabel"><?php echo esc_html__('Typical visitor load time', WPS_IC_TEXTDOMAIN); ?></span></span>
            <span><i class="wpc-vp-sw-cache"></i><?php echo esc_html__('Views served from cache', WPS_IC_TEXTDOMAIN); ?></span>
            <span><i class="wpc-vp-sw-render"></i><?php echo esc_html__('Views freshly rendered', WPS_IC_TEXTDOMAIN); ?></span>
            <span id="wpc-vp-tlkey-base" style="display:none"><i class="wpc-vp-sw-base"></i><span id="wpc-vp-tlkey-baselabel"></span></span>
        </div>
    </div>

    <div class="wpc-vitals-view" id="wpc-vp-experience" style="display:none">
        <div class="wpc-vitals-stage">
            <div class="wpc-vitals-regions" id="wpc-vp-regions" style="display:none"></div>
            <div class="wpc-vitals-spectrum" id="wpc-vp-spectrum" style="display:none"></div>
        </div>
    </div>

    <div class="wpc-vitals-view" id="wpc-vp-details" style="display:none">
        <div class="wpc-vitals-chips"<?php echo $wpc_vp_have4['m'] || $wpc_vp_demo ? '' : ' style="display:none"'; ?>>
            <?php foreach (['lcp', 'inp', 'cls', 'ttfb'] as $wpc_vp_metric) :
                $c = $wpc_vp_chips['m'][$wpc_vp_metric]; ?>
                <div class="wpc-vitals-chip" data-wpc-metric="<?php echo esc_attr($wpc_vp_metric); ?>">
                    <div class="wpc-vitals-chip-top">
                        <span><?php echo esc_html($wpc_vp_metric_names[$wpc_vp_metric]); ?> &middot; P75</span>
                        <i class="wpc-vitals-dot <?php echo esc_attr($c['dot']); ?>"></i>
                    </div>
                    <div class="wpc-vitals-chip-value"><?php echo esc_html($c['num']); ?><span><?php echo esc_html($c['unit']); ?></span></div>
                    <div class="wpc-vitals-chip-delta <?php echo esc_attr($c['dcls']); ?>"><?php echo esc_html($c['delta']); ?></div>
                    <div class="wpc-vitals-chip-split"<?php echo empty($c['hit']) ? ' style="display:none"' : ''; ?>><?php if (!empty($c['hit'])) { echo esc_html(sprintf(__('From cache %s · Rendered %s', WPS_IC_TEXTDOMAIN), $c['hit'][0] . $c['hit'][1], $c['ren'][0] . $c['ren'][1])); } ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="wpc-vp-matrixwrap">
            <div class="wpc-vp-matrix" id="wpc-vp-matrix"></div>
        </div>
        <div class="wpc-vp-mkey">
            <span><i style="background:#10B981"></i><?php echo esc_html__('Good', WPS_IC_TEXTDOMAIN); ?></span>
            <span><i style="background:#F59E0B"></i><?php echo esc_html__('Needs improvement', WPS_IC_TEXTDOMAIN); ?></span>
            <span><i style="background:#EF4444"></i><?php echo esc_html__('Poor', WPS_IC_TEXTDOMAIN); ?></span>
            <span><i class="wpc-vp-nodata"></i><?php echo esc_html__('No data', WPS_IC_TEXTDOMAIN); ?></span>
        </div>
    </div>
</div>
<style>
#wpc-vitals-panel .wpc-vitals-sc{display:flex;align-items:center;gap:10px;font-size:12.5px;color:#475569;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:10px 14px;margin:0 0 14px}
#wpc-vitals-panel .wpc-vitals-sc{margin:0 0 14px}
#wpc-vitals-panel .wpc-vitals-viewbar-right{display:flex;align-items:center;gap:10px}
#wpc-vitals-panel .wpc-vp-scbadge{display:inline-flex;align-items:baseline;gap:6px;border:1px solid #e2e8f0;background:#fff;border-radius:999px;padding:6px 14px;cursor:pointer;box-shadow:0 2px 6px rgba(15,23,42,.06);transition:transform .15s ease,box-shadow .15s ease,background .2s ease,border-color .2s ease}
#wpc-vitals-panel .wpc-vp-scbadge:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(15,23,42,.1)}
#wpc-vitals-panel .wpc-vp-scbadge:focus-visible{outline:2px solid var(--wpc-brand-primary,#3B82F6);outline-offset:2px}
#wpc-vitals-panel .wpc-vp-scbadge{animation:wpcVpScPop .4s cubic-bezier(.16,1,.3,1) both}
#wpc-vitals-panel .wpc-vp-scbadge i{font-style:normal;display:inline-flex;align-self:center;line-height:0}
#wpc-vitals-panel .wpc-vp-scbadge[aria-expanded="true"]{background:#f0fdf4;border-color:#a7f3d0}
#wpc-vitals-panel .wpc-vp-scbadge b{font-size:14px;font-weight:800;color:#059669;line-height:1;font-variant-numeric:tabular-nums}
#wpc-vitals-panel .wpc-vp-scbadge span{font-size:9px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8}
#wpc-vitals-panel .wpc-vitals-sc b{color:#0f172a}
#wpc-vitals-panel .wpc-vitals-sc .wpc-vp-scfast{color:#10B981;font-weight:800}
#wpc-vitals-panel .wpc-vitals-sc-dot{width:8px;height:8px;border-radius:50%;background:var(--wpc-brand-primary,#3B82F6);flex:none;animation:wpcVpScPulse 1.2s ease-in-out infinite}
#wpc-vitals-panel .wpc-vitals-sc.done .wpc-vitals-sc-dot{display:none}
#wpc-vitals-panel .wpc-vitals-sc.done{padding:14px 18px}
#wpc-vitals-panel .wpc-vitals-sc-txt{flex:1;min-width:0}
#wpc-vitals-panel .wpc-vp-scres{display:flex;align-items:center;gap:26px;animation:wpcVpScIn .55s cubic-bezier(.16,1,.3,1)}
@keyframes wpcVpScIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
#wpc-vitals-panel .wpc-vp-scres-main{flex:1;min-width:0}
#wpc-vitals-panel .wpc-vp-scres-head{display:flex;align-items:center;font-size:13.5px;font-weight:800;letter-spacing:.05em;color:#0f172a;text-transform:uppercase;margin:0 0 10px}
#wpc-vitals-panel .wpc-vp-scres-head span{font-weight:500;letter-spacing:0;text-transform:none;color:#94a3b8;margin-left:8px}
#wpc-vitals-panel .wpc-vp-scrow{display:flex;align-items:center;gap:10px;margin:5px 0}
#wpc-vitals-panel .wpc-vp-scrow .lab{flex:0 0 158px;font-size:10px;font-weight:800;letter-spacing:.03em;text-transform:uppercase;color:#94a3b8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
#wpc-vitals-panel .wpc-vp-scrow .trk{flex:1;height:14px;border-radius:7px;background:#f1f5f9;overflow:hidden}
#wpc-vitals-panel .wpc-vp-scrow .fill{height:100%;border-radius:7px;width:0;transition:width .9s cubic-bezier(.16,1,.3,1)}
#wpc-vitals-panel .wpc-vp-scrow .fill.off{background:repeating-linear-gradient(45deg,#cbd5e1 0 6px,#e2e8f0 6px 12px)}
#wpc-vitals-panel .wpc-vp-scrow .fill.on{background:linear-gradient(90deg,#10B981,#34d399)}
#wpc-vitals-panel .wpc-vp-scrow b{flex:0 0 52px;font-size:13px;font-weight:800;color:#0f172a;text-align:right;font-variant-numeric:tabular-nums}
#wpc-vitals-panel .wpc-vp-scrow b.chip{flex:0 0 auto;min-width:52px;background:#fff;border:1px solid #e2e8f0;border-radius:999px;padding:2px 10px;font-size:12px;box-shadow:0 1px 3px rgba(15,23,42,.06);text-align:center}
#wpc-vitals-panel .wpc-vp-scrow b.fast{color:#10B981}
#wpc-vitals-panel .wpc-vp-scinfo{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;border-radius:50%;border:1px solid #cbd5e1;color:#94a3b8;font-size:9.5px;font-weight:700;font-style:normal;text-transform:none;letter-spacing:0;margin-left:8px;vertical-align:1px;cursor:help;position:relative}
#wpc-vitals-panel .wpc-vp-scinfo-tip{display:none;position:absolute;left:50%;top:24px;transform:translateX(-50%);background:#0f172a;color:#e2e8f0;font-size:11px;font-weight:500;letter-spacing:0;text-transform:none;line-height:1.55;padding:10px 13px;border-radius:9px;width:250px;text-align:left;box-shadow:0 10px 28px rgba(15,23,42,.28);z-index:30}
#wpc-vitals-panel .wpc-vp-scinfo-tip:before{content:'';position:absolute;top:-5px;left:50%;transform:translateX(-50%) rotate(45deg);width:9px;height:9px;background:#0f172a;border-radius:2px}
#wpc-vitals-panel .wpc-vp-scinfo:hover .wpc-vp-scinfo-tip,#wpc-vitals-panel .wpc-vp-scinfo:focus-visible .wpc-vp-scinfo-tip{display:block}
#wpc-vitals-panel .wpc-vp-scstat{flex:0 0 auto;display:inline-flex;align-items:baseline;gap:6px;background:linear-gradient(145deg,#0f172a 0%,#1e293b 100%);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:10px 18px;box-shadow:0 5px 16px rgba(15,23,42,.2);animation:wpcVpScPop .45s cubic-bezier(.16,1,.3,1) .4s both}
#wpc-vitals-panel .wpc-vp-scstat i{font-style:normal;display:inline-flex;align-self:center;line-height:0}
#wpc-vitals-panel .wpc-vp-scstat b{font-size:20px;font-weight:800;color:#34d399;line-height:1;font-variant-numeric:tabular-nums}
#wpc-vitals-panel .wpc-vp-scstat span{font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#8fa2c0}
@keyframes wpcVpScPop{from{opacity:0;transform:scale(.88)}to{opacity:1;transform:scale(1)}}
#wpc-vitals-panel .wpc-vp-scres-foot{font-size:11.5px;color:#94a3b8;margin-top:8px}
@media (max-width:900px){#wpc-vitals-panel .wpc-vp-scrow .lab{flex-basis:110px}}
@media (max-width:640px){#wpc-vitals-panel .wpc-vp-scres{flex-direction:column;align-items:flex-start;gap:12px}#wpc-vitals-panel .wpc-vp-scres-main{width:100%}#wpc-vitals-panel .wpc-vp-scstat{align-self:flex-end}}
@keyframes wpcVpScPulse{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.4;transform:scale(.75)}}
#wpc-vitals-panel .wpc-vp-scres-head span.wpc-vp-scghost{margin-left:auto;font-size:10.5px;font-weight:600;letter-spacing:.01em;text-transform:none;color:#64748b;background:#f1f5f9;border:1px solid #e2e8f0;padding:3.5px 11px;border-radius:999px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:64%}
#wpc-vitals-panel .wpc-vp-scrow b.chip.empty{color:#cbd5e1}
#wpc-vitals-panel .wpc-vp-shim{position:relative}
#wpc-vitals-panel .wpc-vp-shim:after{content:'';position:absolute;top:0;right:0;bottom:0;left:0;background:linear-gradient(90deg,transparent,rgba(148,163,184,.25),transparent);animation:wpcVpShim 1.4s linear infinite}
@keyframes wpcVpShim{from{transform:translateX(-100%)}to{transform:translateX(100%)}}
#wpc-vitals-panel .wpc-vp-sctoast{display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;border:1px solid #a7f3d0;color:#059669;font-size:10.5px;font-weight:700;letter-spacing:.02em;text-transform:none;border-radius:999px;padding:2px 10px;margin-left:10px;vertical-align:1px;transition:opacity .5s}
#wpc-vitals-panel .wpc-vp-sctoast.out{opacity:0}
#wpc-vitals-panel .wpc-vitals-toggle button{position:relative}
#wpc-vitals-panel .wpc-vp-devdot{position:absolute;top:4px;right:6px;width:6px;height:6px;border-radius:50%;background:var(--wpc-brand-primary,#3B82F6);animation:wpcVpScPulse 1.2s ease-in-out infinite;pointer-events:none}
#wpc-vitals-panel .wpc-vitals-banner{transition:opacity .5s}
#wpc-vitals-panel .wpc-vitals-you b{color:#0f172a}
#wpc-vitals-panel .wpc-vitals-viewbar{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin:18px 0 14px;padding-top:18px;border-top:1px solid #f1f5f9}
#wpc-vitals-panel .wpc-vitals-viewseg{display:inline-flex;background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;padding:4px;gap:2px;box-shadow:inset 0 2px 4px rgba(15,23,42,.02)}
#wpc-vitals-panel .wpc-vitals-viewseg button{border:0;background:transparent;color:#64748b;font-size:13px;font-weight:600;padding:6px 18px;border-radius:999px;cursor:pointer;transition:all .2s cubic-bezier(.16,1,.3,1)}
#wpc-vitals-panel .wpc-vitals-viewseg button.active{background:#fff;color:#0f172a;box-shadow:0 2px 6px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.04),0 0 0 1px rgba(15,23,42,.02)}
#wpc-vitals-panel .wpc-vitals-viewseg button.active:hover,#wpc-vitals-panel .wpc-vitals-viewseg button:focus-visible{box-shadow:0 0 0 3px rgba(59,130,246,.12),0 2px 8px rgba(15,23,42,.08)}
#wpc-vitals-panel .wpc-vitals-viewseg button:focus-visible{outline:2px solid var(--wpc-brand-primary,#3B82F6);outline-offset:1px}
#wpc-vitals-panel .wpc-vp-tlkey .wpc-vp-sw-p25{background:var(--wpc-brand-primary,#3B82F6);opacity:.3;height:3px!important;border-radius:2px;vertical-align:2px!important}
#wpc-vitals-panel .wpc-vp-tlkey .wpc-vp-sw-p75{background:#334155;height:3px!important;border-radius:2px;vertical-align:2px!important}
@keyframes wpcVpPulse{0%{transform:scale(1);opacity:.55}70%{transform:scale(2.4);opacity:0}100%{transform:scale(2.4);opacity:0}}
#wpc-vitals-panel .wpc-vp-pulse{animation:wpcVpPulse 2.6s cubic-bezier(.16,1,.3,1) 1.2s infinite}
#wpc-vitals-panel .wpc-vitals-demo-badge{display:inline-block;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.3);color:#e2e8f0;font-size:10.5px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;border-radius:999px;padding:2px 10px;margin-left:8px;vertical-align:2px}
@keyframes wpcVpCellIn{0%{opacity:0;transform:scale(.55)}100%{opacity:1;transform:scale(1)}}
#wpc-vitals-panel .wpc-vp-cellin{animation:wpcVpCellIn .34s cubic-bezier(.16,1,.3,1) both}
@keyframes wpcVpSweep{0%{transform:scaleX(0)}100%{transform:scaleX(1)}}
#wpc-vitals-panel .wpc-vp-sweep{transform-box:fill-box;transform-origin:left center;animation:wpcVpSweep .7s cubic-bezier(.16,1,.3,1) both}
@keyframes wpcVpMkIn{0%{opacity:0;transform:translateY(-7px)}100%{opacity:1;transform:translateY(0)}}
#wpc-vitals-panel .wpc-vp-mkin{transform-box:fill-box;animation:wpcVpMkIn .5s cubic-bezier(.16,1,.3,1) both}
@keyframes wpcVpValSwap{0%{opacity:0;transform:translateY(7px)}100%{opacity:1;transform:translateY(0)}}
#wpc-vitals-panel .wpc-vp-valswap{animation:wpcVpValSwap .4s cubic-bezier(.16,1,.3,1) both}
#wpc-vitals-panel .wpc-vitals-chip-top span{transition:color .3s}
#wpc-vitals-panel .wpc-vitals-chip-delta{transition:opacity .3s}
#wpc-vitals-panel .wpc-vitals-chippct{display:inline-flex;gap:2px;margin-left:auto}
#wpc-vitals-panel .wpc-vitals-chippct button{border:0;background:transparent;color:#94a3b8;font-size:11.5px;font-weight:600;padding:3px 10px;border-radius:999px;cursor:pointer;transition:all .2s;font-variant-numeric:tabular-nums}
#wpc-vitals-panel .wpc-vitals-chippct button:hover{color:#64748b;background:#f8fafc}
#wpc-vitals-panel .wpc-vitals-chippct button.active{color:#0f172a;background:#f1f5f9}
#wpc-vitals-panel .wpc-vitals-chippct button:focus-visible{outline:2px solid var(--wpc-brand-primary,#3B82F6);outline-offset:1px}
#wpc-vitals-panel .wpc-vitals-spectrum{position:relative;margin:8px 0 22px}
#wpc-vitals-panel .wpc-vitals-spectrum svg{display:block;width:100%;height:auto;font-family:inherit;font-variant-numeric:tabular-nums}
#wpc-vitals-panel .wpc-vitals-regions{display:flex;gap:10px;flex-wrap:wrap;margin:0 0 16px}
#wpc-vitals-panel .wpc-vp-regchip{display:inline-flex;align-items:center;gap:8px;border:1px solid #e2e8f0;background:#f8fafc;border-radius:999px;padding:6px 14px;font-size:12.5px;font-weight:500;color:#64748b;cursor:pointer;transition:box-shadow .2s,background .2s,border-color .2s;box-shadow:0 1px 2px rgba(0,0,0,.02)}
#wpc-vitals-panel .wpc-vp-regchip b{color:#0f172a;font-weight:700}
#wpc-vitals-panel .wpc-vp-regchip .wpc-vp-regdot{width:8px;height:8px;border-radius:50%;background:#cbd5e1}
#wpc-vitals-panel .wpc-vp-regchip.is-slow .wpc-vp-regdot{background:#F59E0B;box-shadow:0 0 0 2px rgba(229,165,10,.15)}
#wpc-vitals-panel .wpc-vp-regchip.active{background:#fff;border-color:#bfdbfe;color:#0f172a;box-shadow:0 2px 6px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.04),0 0 0 1px rgba(15,23,42,.02)}
#wpc-vitals-panel .wpc-vp-regchip:hover,#wpc-vitals-panel .wpc-vp-regchip:focus-visible{box-shadow:0 0 0 3px rgba(59,130,246,.12),0 2px 8px rgba(15,23,42,.08)}
#wpc-vitals-panel .wpc-vp-regchip:focus-visible{outline:2px solid var(--wpc-brand-primary,#3B82F6);outline-offset:1px}
#wpc-vitals-panel .wpc-vitals-svgwrap{position:relative;width:100%}
#wpc-vitals-panel .wpc-vitals-svgwrap svg{display:block;width:100%;height:auto;font-variant-numeric:tabular-nums;font-family:inherit}
#wpc-vitals-panel .wpc-vp-tip{position:absolute;pointer-events:none;background:rgba(15,23,42,.95);backdrop-filter:blur(8px);-webkit-backdrop-filter:blur(8px);color:#f8fafc;font-size:12.5px;line-height:1.5;padding:10px 14px;border-radius:10px;box-shadow:0 10px 25px -5px rgba(0,0,0,.3),0 0 0 1px rgba(255,255,255,.1);opacity:0;transform:translateY(6px);transition:opacity .15s cubic-bezier(.16,1,.3,1),transform .15s cubic-bezier(.16,1,.3,1);max-width:260px;z-index:20;font-weight:400}
#wpc-vitals-panel .wpc-vp-tip.show{opacity:1;transform:translateY(0)}
#wpc-vitals-panel .wpc-vp-tip .wpc-vp-tiphead{display:block;font-size:11px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#94a3b8;margin:0 0 7px}
#wpc-vitals-panel .wpc-vp-tip .wpc-vp-tiprow{display:flex;align-items:center;gap:8px;min-width:216px;padding:2.5px 0;font-variant-numeric:tabular-nums}
#wpc-vitals-panel .wpc-vp-tip .wpc-vp-tiprow i{width:8px;height:8px;border-radius:50%;flex:0 0 8px}
#wpc-vitals-panel .wpc-vp-tip .wpc-vp-tiprow span{color:#cbd5e1;font-weight:400}
#wpc-vitals-panel .wpc-vp-tip .wpc-vp-tiprow b{margin-left:auto;font-weight:700;color:#fff}
#wpc-vitals-panel .wpc-vp-tip .wpc-vp-tipfoot{display:block;border-top:1px solid rgba(255,255,255,.12);margin-top:7px;padding-top:7px;color:#94a3b8;font-size:11.5px;font-variant-numeric:tabular-nums}
#wpc-vitals-panel .wpc-vp-tip .wpc-vp-tipfoot b{color:#e2e8f0}
#wpc-vitals-panel .wpc-vp-matrixwrap{overflow-x:auto;padding:0 10px 4px 2px;position:relative}
#wpc-vitals-panel .wpc-vp-matrix{display:grid;gap:3px;align-items:center;justify-content:center;min-width:640px}
#wpc-vitals-panel .wpc-vp-mnote{text-align:center;font-size:12.5px;font-weight:500;color:#94a3b8;margin:2px 0 14px}
#wpc-vitals-panel .wpc-vp-mlab{font-size:11.5px;font-weight:700;color:#64748b;letter-spacing:.04em;padding-right:8px}
#wpc-vitals-panel .wpc-vp-cell{height:26px;border-radius:6px;min-width:14px;position:relative;transition:box-shadow .15s cubic-bezier(.16,1,.3,1),filter .15s,transform .15s cubic-bezier(.16,1,.3,1)}
#wpc-vitals-panel .wpc-vp-cell.on:hover{box-shadow:0 0 0 2px #fff,0 0 0 3px var(--wpc-brand-primary,#3B82F6),0 4px 12px rgba(15,23,42,.15);filter:brightness(1.05);z-index:2;transform:scale(1.1)}
#wpc-vitals-panel .wpc-vp-mdate{font-size:10.5px;font-weight:500;color:#94a3b8;white-space:nowrap;justify-self:center;padding-top:6px;font-variant-numeric:tabular-nums}
#wpc-vitals-panel .wpc-vp-tlkey,#wpc-vitals-panel .wpc-vp-mkey{display:flex;gap:16px;flex-wrap:wrap;justify-content:center;margin-top:12px;font-size:11.5px;font-weight:500;color:#94a3b8}
#wpc-vitals-panel .wpc-vp-baselab{text-transform:uppercase}
#wpc-vitals-panel .wpc-vp-tlkey i,#wpc-vitals-panel .wpc-vp-mkey i{width:8px;height:8px;border-radius:2.5px;display:inline-block;margin-right:6px;vertical-align:-1px;opacity:.85}
#wpc-vitals-panel .wpc-vp-sw-line{background:var(--wpc-brand-primary,#3B82F6);height:3px!important;border-radius:2px;vertical-align:2px!important}
#wpc-vitals-panel .wpc-vp-tlkey .wpc-vp-sw-cache{background:var(--wpc-brand-primary,#3B82F6);opacity:.6}
#wpc-vitals-panel .wpc-vp-tlkey .wpc-vp-sw-render{background:var(--wpc-brand-primary,#3B82F6);opacity:.16}
#wpc-vitals-panel .wpc-vp-sw-base{background:transparent;border:0;border-top:2px dashed #94a3b8;height:0!important;border-radius:0;width:14px;vertical-align:2px!important}
#wpc-vitals-panel .wpc-vp-nodata{background:#f1f5f9;border:1px solid #e2e8f0}
#wpc-vitals-panel,#wpc-vitals-panel *{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif}
#wpc-vitals-panel .wpc-vitals-head{margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #f1f5f9}
#wpc-vitals-panel .wpc-vitals-head h3{font-weight:700;font-size:18px;color:#0f172a;letter-spacing:-0.01em}
#wpc-vitals-panel .wpc-vitals-stage{padding:4px 0 0}
#wpc-vitals-panel .wpc-vitals-stage .wpc-vitals-spectrum{margin:4px 0 18px}
#wpc-vitals-panel .wpc-vitals-stage .wpc-vitals-regions{margin:0 0 8px}
#wpc-vitals-panel .wpc-vitals-head p{font-weight:400;font-size:13px;color:#64748b}
#wpc-vitals-panel .wpc-vitals-controls{background:#f1f5f9;border:1px solid #e2e8f0;box-shadow:inset 0 2px 4px rgba(0,0,0,.02)}
#wpc-vitals-panel .wpc-vitals-toggle button{font-weight:600;color:#64748b}
#wpc-vitals-panel .wpc-vitals-toggle button.active{color:#0f172a}
#wpc-vitals-panel .wpc-vitals-range{font-weight:500;color:#94a3b8}
#wpc-vitals-panel .wpc-vitals-chip{border-color:#e2e8f0;border-radius:16px;padding:20px}
#wpc-vitals-panel .wpc-vitals-chip-top span{font-weight:600;font-size:11.5px;letter-spacing:.04em;color:#64748b}
#wpc-vitals-panel .wpc-vitals-chip-value{font-weight:700;font-size:28px;color:#0f172a;letter-spacing:-0.02em}
#wpc-vitals-panel .wpc-vitals-chip[data-wpc-metric="lcp"] .wpc-vitals-chip-value{font-size:34px;font-weight:800;letter-spacing:-0.025em}
#wpc-vitals-panel .wpc-vitals-chip-value span{font-weight:600;font-size:14px;color:#64748b}
#wpc-vitals-panel .wpc-vitals-chip-delta{font-weight:600;font-size:12px;color:#94a3b8}
#wpc-vitals-panel .wpc-vitals-chip-split{font-weight:600;font-size:11px;color:#64748b;margin-top:4px;padding-top:4px;border-top:1px solid #f1f5f9;font-variant-numeric:tabular-nums}
#wpc-vitals-panel .wpc-vitals-chip-delta.improve{color:#10B981}
#wpc-vitals-panel .wpc-vitals-chip-delta.regress{color:#F59E0B}
#wpc-vitals-panel .wpc-vitals-dot.good{background:#10B981;box-shadow:0 0 0 2px rgba(16,185,129,.15)}
#wpc-vitals-panel .wpc-vitals-dot.ni{background:#F59E0B;box-shadow:0 0 0 2px rgba(245,158,11,.15)}
#wpc-vitals-panel .wpc-vitals-dot.poor{background:#EF4444;box-shadow:0 0 0 2px rgba(239,68,68,.15)}
#wpc-vitals-panel .wpc-vitals-banner{background:linear-gradient(145deg,#0f172a 0%,#1e293b 100%);border:1px solid rgba(255,255,255,.1);border-radius:16px;padding:24px;gap:20px}
#wpc-vitals-panel .wpc-vitals-banner-ic{width:48px;height:48px;flex:0 0 48px;border-radius:14px;background:linear-gradient(135deg,var(--wpc-brand-primary,#3B82F6),var(--wpc-brand-primary-dark,#1D4ED8));box-shadow:inset 0 2px 4px rgba(255,255,255,.3),0 8px 16px -4px rgba(37,99,235,.4)}
#wpc-vitals-panel .wpc-vitals-banner-txt strong{font-weight:600;font-size:16px;color:#fff;letter-spacing:-0.01em}
#wpc-vitals-panel .wpc-vitals-banner-txt span{font-weight:400;font-size:13.5px;color:#94a3b8;line-height:1.5}
#wpc-vitals-panel .wpc-vitals-legend span{font-weight:500;font-size:12px;color:#64748b}
@keyframes wpcVpDrop{0%{opacity:0;transform:translateY(-14px)}100%{opacity:1;transform:translateY(0)}}
@keyframes wpcVpDraw{to{stroke-dashoffset:0}}
@keyframes wpcVpFade{from{opacity:0;transform:translateY(4px)}to{opacity:1;transform:translateY(0)}}
@keyframes wpcVpPulse{0%{transform:scale(.8);opacity:.8}100%{transform:scale(2.4);opacity:0}}
#wpc-vitals-panel .wpc-vp-marker{animation:wpcVpDrop .5s cubic-bezier(.16,1,.3,1) both}
@keyframes wpcVpBar{from{transform:scaleY(0)}to{transform:scaleY(1)}}
#wpc-vitals-panel .wpc-vp-bar{transform-box:fill-box;transform-origin:bottom;animation:wpcVpBar .45s cubic-bezier(.16,1,.3,1) both}
#wpc-vitals-panel.wpc-vp-entered .wpc-vitals-chip,#wpc-vitals-panel.wpc-vp-entered .wpc-vitals-banner{animation:none}
#wpc-vitals-panel .wpc-vitals-chip{animation:wpcVpFade .5s cubic-bezier(.16,1,.3,1) both}
#wpc-vitals-panel .wpc-vitals-chip:nth-child(2){animation-delay:.06s}
#wpc-vitals-panel .wpc-vitals-chip:nth-child(3){animation-delay:.12s}
#wpc-vitals-panel .wpc-vitals-chip:nth-child(4){animation-delay:.18s}
#wpc-vitals-panel .wpc-vitals-banner{animation:wpcVpFade .55s cubic-bezier(.16,1,.3,1) .15s both}
#wpc-vitals-panel .wpc-vp-path-anim{stroke-dasharray:4000;stroke-dashoffset:4000;animation:wpcVpDraw 1.1s cubic-bezier(.16,1,.3,1) .15s forwards}
#wpc-vitals-panel .wpc-vp-area-anim{animation:wpcVpFade .7s ease .55s both}
#wpc-vitals-panel #wpc-vp-endb{transition:opacity .12s ease}
#wpc-vitals-panel #wpc-vp-endb.wpc-vp-hid{opacity:0 !important}
#wpc-vitals-panel .wpc-vp-ring-anim{animation:wpcVpPulse 2.4s cubic-bezier(.16,1,.3,1) infinite}
#wpc-vitals-panel .wpc-vp-noanim .wpc-vp-marker,#wpc-vitals-panel .wpc-vp-noanim .wpc-vp-path-anim,#wpc-vitals-panel .wpc-vp-noanim .wpc-vp-area-anim,#wpc-vitals-panel .wpc-vp-noanim .wpc-vp-bar{animation:none;opacity:1;transform:none;stroke-dasharray:none;stroke-dashoffset:0}
@media (prefers-reduced-motion:reduce){#wpc-vitals-panel .wpc-vp-tip{transition:none}#wpc-vitals-panel .wpc-vp-scrow .fill{transition:none}#wpc-vitals-panel .wpc-vp-cell{transition:none}#wpc-vitals-panel .wpc-vp-marker,#wpc-vitals-panel .wpc-vp-path-anim,#wpc-vitals-panel .wpc-vp-area-anim,#wpc-vitals-panel .wpc-vp-ring-anim,#wpc-vitals-panel .wpc-vp-pulse,#wpc-vitals-panel .wpc-vp-cellin,#wpc-vitals-panel .wpc-vp-sweep,#wpc-vitals-panel .wpc-vp-mkin,#wpc-vitals-panel .wpc-vp-valswap,#wpc-vitals-panel .wpc-vp-bar,#wpc-vitals-panel .wpc-vitals-chip,#wpc-vitals-panel .wpc-vitals-banner,#wpc-vitals-panel .wpc-vitals-sc-dot,#wpc-vitals-panel .wpc-vp-devdot,#wpc-vitals-panel .wpc-vp-scres,#wpc-vitals-panel .wpc-vp-scstat,#wpc-vitals-panel .wpc-vp-scbadge{animation:none !important;opacity:1 !important;transform:none !important;stroke-dasharray:none !important;stroke-dashoffset:0 !important}#wpc-vitals-panel .wpc-vp-shim:after{display:none !important}}
</style>
<script type="text/javascript">
    (function () {
        var VP = <?php echo wp_json_encode($wpc_vp_payload); ?>;
        try { if (localStorage.getItem('wpcVpDbg') === '1') { window.wpcVpDebug = VP; } } catch (e) {}
        var dev = VP.defdev === 'd' ? 'd' : 'm', view = 'timeline', chipPhase = 'p75';
        var wpcBrandRaw = (window.getComputedStyle ? getComputedStyle(document.documentElement).getPropertyValue('--wpc-brand-primary') : '').trim();
        var ACC = /^#[0-9a-fA-F]{6}$/.test(wpcBrandRaw) ? wpcBrandRaw : '#3B82F6';
        function accA(a) {
            var r = parseInt(ACC.substr(1, 2), 16), g = parseInt(ACC.substr(3, 2), 16), b = parseInt(ACC.substr(5, 2), 16);
            return 'rgba(' + r + ',' + g + ',' + b + ',' + a + ')';
        }
        function accShade(f) {
            var r = Math.round(parseInt(ACC.substr(1, 2), 16) * (1 - f));
            var g = Math.round(parseInt(ACC.substr(3, 2), 16) * (1 - f));
            var b = Math.round(parseInt(ACC.substr(5, 2), 16) * (1 - f));
            return 'rgb(' + r + ',' + g + ',' + b + ')';
        }
        var ACCD = accShade(.48);
        function accGrey() {
            var gr = 148, gg = 163, gb = 184;
            var r = Math.round(parseInt(ACC.substr(1, 2), 16) * .3 + gr * .7);
            var g = Math.round(parseInt(ACC.substr(3, 2), 16) * .3 + gg * .7);
            var b = Math.round(parseInt(ACC.substr(5, 2), 16) * .3 + gb * .7);
            return 'rgb(' + r + ',' + g + ',' + b + ')';
        }
        var ACCG = accGrey();
        var NS = 'http://www.w3.org/2000/svg';
        var VPBRAND = '<?php echo esc_js(sprintf(__('Without %s', WPS_IC_TEXTDOMAIN), $wpc_vp_brand)); ?>';
        var VPBRANDU = VPBRAND.toUpperCase();

        function fmtMs(v) { return v == null ? '—' : (v < 1000 ? Math.round(v) + 'ms' : (Math.round(v / 100) / 10) + 's'); }
        function esc(t) { var d = document.createElement('span'); d.textContent = t; return d.innerHTML; }
        function el(n, a) { var e = document.createElementNS(NS, n); for (var k in a) { e.setAttribute(k, a[k]); } return e; }
        function smooth(pts) {
            if (pts.length < 2) { return ''; }
            var d = 'M' + pts[0][0] + ',' + pts[0][1];
            for (var i = 1; i < pts.length; i++) {
                var mx = (pts[i - 1][0] + pts[i][0]) / 2;
                d += 'C' + mx + ',' + pts[i - 1][1] + ' ' + mx + ',' + pts[i][1] + ' ' + pts[i][0] + ',' + pts[i][1];
            }
            return d;
        }
        function tipFor(wrap) {
            var t = wrap.querySelector('.wpc-vp-tip');
            if (!t) { t = document.createElement('div'); t.className = 'wpc-vp-tip'; wrap.appendChild(t); }
            return t;
        }
        function place(tip, wrap, x, y, html) {
            tip.innerHTML = html; tip.classList.add('show');
            var r = wrap.getBoundingClientRect(), tw = tip.offsetWidth;
            tip.style.left = Math.max(4, Math.min(x - tw / 2, r.width - tw - 4)) + 'px';
            tip.style.top = Math.max(0, y - tip.offsetHeight - 12) + 'px';
        }

        var region = 0;
        var animOn = true;



        function drawSpectrum() {
            var wrap = document.getElementById('wpc-vp-spectrum');
            if (!wrap) { return; }
            var s = (VP.spec || {})[dev] || {};
            if (!s.p75 || s.p75 <= 0) {
                if (!(VP.v28 > 0)) { wrap.style.display = 'none'; return; }
                wrap.style.display = '';
                wrap.innerHTML = '';
                var Wm = 960, Hm = 96, Lm = 24, Rm = 24, bwm = Wm - Lm - Rm, bym = 40, bhm = 8;
                var svgm = el('svg', { viewBox: '0 0 ' + Wm + ' ' + Hm, role: 'img' });
                var defsm = el('defs', {});
                var gm = el('linearGradient', { id: 'wpcvpsgm', x1: 0, y1: 0, x2: 1, y2: 0 });
                gm.appendChild(el('stop', { offset: '0%', 'stop-color': '#10B981', 'stop-opacity': .35 }));
                gm.appendChild(el('stop', { offset: '62%', 'stop-color': '#F59E0B', 'stop-opacity': .3 }));
                gm.appendChild(el('stop', { offset: '100%', 'stop-color': '#EF4444', 'stop-opacity': .28 }));
                defsm.appendChild(gm);
                svgm.appendChild(defsm);
                svgm.appendChild(el('rect', { x: Lm, y: bym, width: bwm, height: bhm, rx: bhm / 2, fill: '#f1f5f9', stroke: '#e2e8f0', 'stroke-width': 1 }));
                svgm.appendChild(el('rect', { x: Lm, y: bym, width: bwm, height: bhm, rx: bhm / 2, fill: 'url(#wpcvpsgm)' }));
                var gxm = Lm + bwm * 0.62;
                svgm.appendChild(el('line', { x1: gxm, x2: gxm, y1: bym - 8, y2: bym + bhm + 8, stroke: '#94a3b8', 'stroke-width': 1.5, 'stroke-dasharray': '4 3', opacity: .5 }));
                var glm = el('text', { x: gxm, y: bym + bhm + 22, 'text-anchor': 'middle', 'font-size': '10', 'font-weight': '700', fill: '#94a3b8', 'letter-spacing': '.04em' });
                glm.textContent = ('2.5s · <?php echo esc_js(__('Core Web Vitals goal', WPS_IC_TEXTDOMAIN)); ?>').toUpperCase();
                svgm.appendChild(glm);
                var mlm = el('text', { x: Lm, y: bym - 14, 'font-size': '10.5', 'font-weight': '600', fill: '#94a3b8', 'letter-spacing': '.04em', 'class': 'wpc-vp-baselab' });
                mlm.textContent = '<?php echo esc_js(__('Measuring — your experience markers appear after about 10 visits', WPS_IC_TEXTDOMAIN)); ?>';
                svgm.appendChild(mlm);
                wrap.appendChild(svgm);
                return;
            }
            var activeReg = null;
            if (region) {
                (VP.reg || []).forEach(function (r) { if (r.c === region) { activeReg = r; } });
            }
            var plotP75 = (activeReg && activeReg.p75 > 0) ? activeReg.p75 : s.p75;
            var plotGhost = activeReg ? 0 : (s.ghost || 0);
            if (plotGhost > 0 && s.p75 > 0 && !(plotGhost > s.p75)) { plotGhost = 0; s.ghost = 0; }
            wrap.style.display = '';
            wrap.innerHTML = '';
            var W = 960, H = 176, L = 24, R = 24, bw = W - L - R, by = 76, bh = 8;
            var MIN = 150, MAX = Math.max(6000, plotGhost * 1.15, plotP75 * 1.4);
            function X(v) { return L + bw * (Math.log(Math.max(MIN, Math.min(MAX, v)) / MIN) / Math.log(MAX / MIN)); }
            var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' });
            if (!animOn) { svg.setAttribute('class', 'wpc-vp-noanim'); }
            var defs = el('defs', {});
            var g = el('linearGradient', { id: 'wpcvpsg', x1: 0, y1: 0, x2: 1, y2: 0 });
            g.appendChild(el('stop', { offset: '0%', 'stop-color': '#10B981', 'stop-opacity': .95 }));
            g.appendChild(el('stop', { offset: ((X(2500) - L) / bw * 100) + '%', 'stop-color': '#10B981', 'stop-opacity': .85 }));
            g.appendChild(el('stop', { offset: ((X(4000) - L) / bw * 100) + '%', 'stop-color': '#F59E0B', 'stop-opacity': .8 }));
            g.appendChild(el('stop', { offset: '100%', 'stop-color': '#EF4444', 'stop-opacity': .75 }));
            defs.appendChild(g);
            svg.appendChild(defs);
            svg.appendChild(el('rect', { x: L, y: by, width: bw, height: bh, rx: bh / 2, fill: '#f1f5f9', stroke: '#e2e8f0', 'stroke-width': 1 }));
            var trk = el('rect', { x: L, y: by, width: bw, height: bh, rx: bh / 2, fill: 'url(#wpcvpsg)' });
            if (animOn && !vpReduce) { trk.setAttribute('class', 'wpc-vp-sweep'); }
            svg.appendChild(trk);
            var gx = X(2500);
            svg.appendChild(el('line', { x1: gx, x2: gx, y1: by - 12, y2: by + bh + 12, stroke: '#94a3b8', 'stroke-width': 1.5, 'stroke-dasharray': '4 3', opacity: .6 }));
            var gl = el('text', { x: gx, y: by + bh + 26, 'text-anchor': 'middle', 'font-size': '10.5', 'font-weight': '700', fill: '#64748b', 'letter-spacing': '.04em' });
            gl.textContent = ('2.5s · <?php echo esc_js(__('Core Web Vitals goal', WPS_IC_TEXTDOMAIN)); ?>').toUpperCase();
            svg.appendChild(gl);
            var tip = tipFor(wrap);
            var markerCount = 0;
            function marker(v, label, above, cls, hollow, tipHtml) {
                if (!v || v <= 0) { return; }
                markerCount++;
                var x = X(v), cy = by + bh / 2;
                var mg = el('g', { cursor: 'default', 'class': 'wpc-vp-marker' });
                mg.appendChild(el('line', { x1: x, x2: x, y1: above ? by - 18 : by + bh + 18, y2: above ? by - 2 : by + bh + 2, stroke: hollow ? '#94a3b8' : '#cbd5e1', 'stroke-width': 1.5, 'stroke-dasharray': hollow ? '3 3' : 'none' }));
                mg.appendChild(el('circle', { cx: x, cy: cy, r: hollow ? 6 : 6.5, fill: hollow ? '#f8fafc' : '#0f172a', stroke: hollow ? '#64748b' : '#fff', 'stroke-width': 2, 'stroke-dasharray': hollow ? '2 2' : 'none' }));
                var ty = above ? by - 34 : by + bh + 46;
                var tv = el('text', { x: x, y: ty, 'text-anchor': 'middle', 'font-size': '14', 'font-weight': '800', fill: hollow ? '#64748b' : '#0f172a', 'letter-spacing': '-0.02em' });
                tv.textContent = fmtMs(v);
                mg.appendChild(tv);
                var tl = el('text', { x: x, y: ty + 14, 'text-anchor': 'middle', 'font-size': '9.5', 'font-weight': '600', fill: '#94a3b8', 'letter-spacing': '.05em' });
                tl.textContent = label;
                mg.appendChild(tl);
                mg.addEventListener('pointermove', function () {
                    var r = wrap.getBoundingClientRect();
                    place(tip, wrap, (x / W) * r.width, (by / H) * r.height, tipHtml);
                });
                mg.addEventListener('pointerleave', function () { tip.classList.remove('show'); });
                mg.style.animationDelay = (hollow ? 0.5 : (markerCount * 0.1)) + 's';
                svg.appendChild(mg);
            }
            if (plotGhost > 0) {
                var lx = X(plotP75), rx2 = X(plotGhost);
                svg.appendChild(el('line', { x1: lx + 11, x2: rx2 - 11, y1: by + bh / 2, y2: by + bh / 2, stroke: '#94a3b8', 'stroke-width': 1.5, 'stroke-dasharray': '4 4', opacity: .55 }));
            }
            if (activeReg) {
                marker(plotP75, activeReg.label.toUpperCase() + ' · <?php echo esc_js(strtoupper(__('Slowest quarter', WPS_IC_TEXTDOMAIN))); ?>', true, '', false,
                    '<b>' + esc(activeReg.label) + ' — <?php echo esc_js(__('Slowest quarter starts here', WPS_IC_TEXTDOMAIN)); ?></b><br><?php echo esc_js(__('p75 — the number Google scores', WPS_IC_TEXTDOMAIN)); ?> · ' + (activeReg.v || 0) + ' <?php echo esc_js(__('visits', WPS_IC_TEXTDOMAIN)); ?>');
            } else {
            marker(s.opt, '<?php echo esc_js(strtoupper(__('Fully optimized visit', WPS_IC_TEXTDOMAIN))); ?>', true, '', false,
                '<b><?php echo esc_js(__('Fully optimized visit', WPS_IC_TEXTDOMAIN)); ?></b><br><?php echo esc_js(__('Median cached repeat visit', WPS_IC_TEXTDOMAIN)); ?> · ' + (s.hn || 0) + ' <?php echo esc_js(__('visits', WPS_IC_TEXTDOMAIN)); ?>');
            marker(s.p50, '<?php echo esc_js(strtoupper(__('Typical visit', WPS_IC_TEXTDOMAIN))); ?>', false, '', false,
                '<b><?php echo esc_js(__('Typical visit', WPS_IC_TEXTDOMAIN)); ?></b><br><?php echo esc_js(__('Median of all visits (p50)', WPS_IC_TEXTDOMAIN)); ?> · ' + (s.n || 0) + ' <?php echo esc_js(__('visits', WPS_IC_TEXTDOMAIN)); ?>');
            marker(s.p75, '<?php echo esc_js(strtoupper(__('Slowest quarter', WPS_IC_TEXTDOMAIN))); ?>', true, '', false,
                '<b><?php echo esc_js(__('Slowest quarter starts here', WPS_IC_TEXTDOMAIN)); ?></b><br><?php echo esc_js(__('p75 — the number Google scores', WPS_IC_TEXTDOMAIN)); ?>');
            marker(s.ghost, VPBRANDU, false, '', true,
                '<b>' + VPBRAND + '</b><br><?php echo esc_js(__('p75 of real unoptimized comparison visits on this site', WPS_IC_TEXTDOMAIN)); ?>');
            }
            wrap.appendChild(svg);
        }


        function drawRegions() {
            var row = document.getElementById('wpc-vp-regions');
            if (!row) { return; }
            var regs = VP.reg || [];
            if (!regs.length) { row.style.display = 'none'; return; }
            row.style.display = '';
            row.innerHTML = '';
            var site75 = ((VP.spec || {})[dev] || {}).p75 || 0;
            function chip(label, sub, code, slow) {
                var b = document.createElement('button');
                b.type = 'button';
                b.className = 'wpc-vp-regchip' + (slow ? ' is-slow' : '') + (region === code ? ' active' : '');
                b.innerHTML = '<i class="wpc-vp-regdot"></i><b>' + esc(label) + '</b>' + (sub ? '<span>' + esc(sub) + '</span>' : '');
                b.addEventListener('click', function () {
                    region = (region === code) ? 0 : code;
                    render();
                });
                row.appendChild(b);
            }
            chip('<?php echo esc_js(__('All regions', WPS_IC_TEXTDOMAIN)); ?>', '', 0, false);
            regs.forEach(function (r) {
                chip(r.label, fmtMs(r.p75) + ' · ' + r.share + '%', r.c, site75 > 0 && r.p75 > site75 * 1.3);
            });
        }

        var vpReduce = !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);
        function countUp(node, num) {
            var target = parseFloat(num);
            if (isNaN(target) || target <= 0) { node.nodeValue = num; return; }
            var dec = (String(num).split('.')[1] || '').length;
            var t0 = null;
            function step(ts) {
                if (!t0) { t0 = ts; }
                var k = Math.min(1, (ts - t0) / 700);
                k = 1 - Math.pow(1 - k, 3);
                node.nodeValue = (target * k).toFixed(dec);
                if (k < 1) { requestAnimationFrame(step); } else { node.nodeValue = num; }
            }
            requestAnimationFrame(step);
        }
        function devHasChips() {
            return !!(VP.demo || (VP.have4 && VP.have4[dev]));
        }
        function chipVis() {
            var cw = document.querySelector('#wpc-vitals-panel .wpc-vitals-chips');
            if (cw) { cw.style.display = devHasChips() ? '' : 'none'; }
            var cp = document.getElementById('wpc-vp-chippct');
            if (cp) { cp.style.display = (view === 'details' && devHasChips()) ? '' : 'none'; }
        }
        function renderChips() {
            chipVis();
            var chips = VP.chips[dev] || {};
            document.querySelectorAll('#wpc-vitals-panel .wpc-vitals-chip').forEach(function (elc) {
                var c = chips[elc.getAttribute('data-wpc-metric')];
                if (!c) { return; }
                var v = elc.querySelector('.wpc-vitals-chip-value');
                v.innerHTML = '';
                var vtn = document.createTextNode(c.num);
                v.appendChild(vtn);
                if (animOn && !vpReduce && window.requestAnimationFrame) { countUp(vtn, c.num); }
                var u = document.createElement('span'); u.appendChild(document.createTextNode(c.unit)); v.appendChild(u);
                elc.querySelector('.wpc-vitals-dot').className = 'wpc-vitals-dot ' + c.dot;
                var d = elc.querySelector('.wpc-vitals-chip-delta');
                d.className = 'wpc-vitals-chip-delta ' + (c.dcls || '');
                d.textContent = c.delta || '';
                d.style.opacity = '1';
                var sp = elc.querySelector('.wpc-vitals-chip-split');
                if (sp) {
                    if (c.hit && c.ren) {
                        sp.textContent = '<?php echo esc_js(__('From cache', WPS_IC_TEXTDOMAIN)); ?> ' + c.hit[0] + c.hit[1] + ' · <?php echo esc_js(__('Rendered', WPS_IC_TEXTDOMAIN)); ?> ' + c.ren[0] + c.ren[1];
                        sp.style.display = '';
                    } else {
                        sp.style.display = 'none';
                    }
                }
                var top0 = elc.querySelector('.wpc-vitals-chip-top span');
                if (top0) { top0.textContent = top0.textContent.replace(/P\d\d/, 'P75'); }
            });
            chipPhase = 'p75';
            document.querySelectorAll('#wpc-vp-chippct button').forEach(function (x) {
                x.classList.toggle('active', x.getAttribute('data-wpc-cpct') === 'p75');
            });
        }
        function applyChipPhase(next) {
            var chips = VP.chips[dev] || {};
            var any = false;
            document.querySelectorAll('#wpc-vitals-panel .wpc-vitals-chip').forEach(function (elc) {
                var c = chips[elc.getAttribute('data-wpc-metric')];
                if (!c) { return; }
                var q = next === 'p75' ? [c.num, c.unit] : (next === 'p25' ? c.q25 : c.q50);
                if (!q) { return; }
                any = true;
                var v = elc.querySelector('.wpc-vitals-chip-value');
                v.classList.remove('wpc-vp-valswap');
                if (!vpReduce) { void v.offsetWidth; }
                v.innerHTML = '';
                v.appendChild(document.createTextNode(q[0]));
                var u = document.createElement('span'); u.appendChild(document.createTextNode(q[1])); v.appendChild(u);
                if (!vpReduce) { v.classList.add('wpc-vp-valswap'); }
                var top = elc.querySelector('.wpc-vitals-chip-top span');
                if (top) {
                    top.textContent = top.textContent.replace(/P\d\d/, next.toUpperCase());
                }
                var d = elc.querySelector('.wpc-vitals-chip-delta');
                if (d) { d.style.opacity = next === 'p75' ? '1' : '0'; }
            });
            if (any) { chipPhase = next; }
            document.querySelectorAll('#wpc-vp-chippct button').forEach(function (x) {
                x.classList.toggle('active', x.getAttribute('data-wpc-cpct') === chipPhase);
            });
        }
        document.querySelectorAll('#wpc-vp-chippct button').forEach(function (b) {
            b.addEventListener('click', function () { applyChipPhase(b.getAttribute('data-wpc-cpct')); });
        });


        function wpcTlPick2127(l, f) {
            var i, hl = false, hf = false;
            for (i = 0; i < l.length; i++) { if (l[i] != null) { hl = true; break; } }
            if (!hl) { for (i = 0; i < f.length; i++) { if (f[i] != null) { hf = true; break; } } }
            return { fcp: (!hl && hf), ser: (!hl && hf) ? f : l };
        }
        function drawTimeline() {
            var wrap = document.getElementById('wpc-vp-tl');
            if (!wrap) { return; }
            wrap.innerHTML = '';
            var tlx = (VP.tlx && VP.tlx.length) ? VP.tlx : null;
            function pick(a) { if (!a) { return []; } if (!tlx) { return a; } var o = []; tlx.forEach(function (ii) { o.push(a[ii]); }); return o; }
            var s75 = pick(VP.lcp[dev] || []);
            var s50 = pick((((VP.lcpq || {})[dev] || {}).p50) || []);
            var s25 = pick((((VP.lcpq || {})[dev] || {}).p25) || []);
            var tlFcp = false;
            if (region) {
                (VP.reg || []).forEach(function (rr) { if (rr.c === region && rr.ser) { s75 = pick(rr.ser); s50 = []; s25 = []; } });
            } else {
                var wpcTlp = wpcTlPick2127(s75, (VP.fcp && VP.fcp[dev]) ? pick(VP.fcp[dev]) : []);
                if (wpcTlp.fcp) { s75 = wpcTlp.ser; s50 = []; s25 = []; tlFcp = true; }
            }
            var tlLabels = pick(VP.labels), tlCached = pick(VP.cached), tlRendered = pick(VP.rendered);
            var s50n = s50.filter(function (v) { return v != null; }).length;
            var s75n = s75.filter(function (v) { return v != null; }).length;
            var wpcHeroP50 = s50n > 1 || (s50n === 1 && s75n === 1);
            var series = wpcHeroP50 ? s50 : s75;
            var n = s75.length;
            if (!n) { return; }
            var W = 960, H = 290, L = 52, R = 20, T = 20, B = 34, iw = W - L - R, ih = H - T - B;
            var base = (region || tlFcp) ? 0 : ((VP.base && VP.base[dev]) ? VP.base[dev] : 0);
            var vals = s75.filter(function (v) { return v != null; });
            var allVals = vals.concat(s50, s25).filter(function (v) { return v != null; });
            var maxY = Math.max.apply(null, allVals.concat([base || 0, tlFcp ? 2000 : 2600])) * 1.14;
            var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img' });
            if (!animOn) { svg.setAttribute('class', 'wpc-vp-noanim'); }
            function X(i) { return n < 2 ? (W - R - 18) : L + iw * (i / (n - 1)); }
            function Y(v) { return T + ih * (1 - v / maxY); }
            var defs = el('defs', {});
            var pat = el('pattern', { id: 'wpc-vp-stripes', width: '12', height: '12', patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' });
            pat.appendChild(el('rect', { width: '6', height: '12', fill: '#94a3b8', opacity: '0.08' }));
            defs.appendChild(pat);
            svg.appendChild(defs);
            var hasLine = vals.length > 1;
            var showAxes = hasLine || base > 0 || vals.length === 1;
            if (base > 0) {
                svg.appendChild(el('rect', { x: L, y: Y(base), width: iw, height: T + ih - Y(base), fill: 'url(#wpc-vp-stripes)' }));
                var bg = el('g', { 'class': 'wpc-vp-area-anim' });
                bg.appendChild(el('line', { x1: L, x2: W - R, y1: Y(base), y2: Y(base), stroke: '#94a3b8', 'stroke-width': 1.5, 'stroke-dasharray': '2 5', 'stroke-linecap': 'round', opacity: .75 }));
                bg.appendChild(el('circle', { cx: L + 5, cy: Y(base), r: 4, fill: '#f8fafc', stroke: '#94a3b8', 'stroke-width': 1.5, 'stroke-dasharray': '2 2' }));
                var bl = el('text', { x: L + 15, y: Y(base) - 7, 'font-size': '10', 'font-weight': '600', fill: '#94a3b8', 'letter-spacing': '.04em' });
                bl.textContent = (VP.base.label || 'Before optimization').toUpperCase() + ' · ' + (dev === 'm' ? '<?php echo esc_js(__('MOBILE', WPS_IC_TEXTDOMAIN)); ?>' : '<?php echo esc_js(__('DESKTOP', WPS_IC_TEXTDOMAIN)); ?>') + ' · ' + fmtMs(base);
                bg.appendChild(bl);
                svg.appendChild(bg);
            }
            if (showAxes) {
            [0.25, 0.5, 0.75, 1].forEach(function (f) {
                var v = maxY * f;
                svg.appendChild(el('line', { x1: L, x2: W - R, y1: Y(v), y2: Y(v), stroke: '#f1f5f9', 'stroke-width': 1.5, 'stroke-dasharray': '4 6' }));
                var t = el('text', { x: L - 10, y: Y(v) + 4, 'text-anchor': 'end', 'font-size': '10.5', 'font-weight': '500', fill: '#94a3b8' });
                t.textContent = fmtMs(Math.round(v / 100) * 100); svg.appendChild(t);
            });
            }
            var wpcGood = tlFcp ? 1800 : 2500;
            if (showAxes && wpcGood < maxY) {
                svg.appendChild(el('line', { x1: L, x2: W - R, y1: Y(wpcGood), y2: Y(wpcGood), stroke: '#10B981', 'stroke-width': 1.5, 'stroke-dasharray': '6 6', opacity: .55 }));
                var gcx = W - R - 76;
                svg.appendChild(el('rect', { x: gcx - 10, y: Y(wpcGood) - 21, width: 86, height: 20, rx: 10, fill: '#ffffff', 'fill-opacity': '.92' }));
                svg.appendChild(el('circle', { cx: gcx, cy: Y(wpcGood) - 11, r: 6, fill: '#10B981' }));
                svg.appendChild(el('path', { d: 'M' + (gcx - 2.6) + ',' + (Y(wpcGood) - 11) + ' l1.9,2 l3.4,-3.9', fill: 'none', stroke: '#fff', 'stroke-width': 1.6, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' }));
                var gt = el('text', { x: gcx + 11, y: Y(wpcGood) - 7, 'font-size': '9.5', 'font-weight': '700', fill: '#10B981', 'letter-spacing': '.04em' });
                gt.textContent = tlFcp ? 'GOOD < 1.8s' : 'GOOD < 2.5s'; svg.appendChild(gt);
            }
            var maxV = 0, bi, barH = [];
            for (bi = 0; bi < n; bi++) { maxV = Math.max(maxV, (tlCached[bi] || 0) + (tlRendered[bi] || 0)); }
            if (maxV > 0) {
                var bw = Math.max(4, Math.min(22, (iw / Math.max(1, n)) * 0.55)), bmax = ih * 0.3;
                for (bi = 0; bi < n; bi++) {
                    var bc = tlCached[bi] || 0, br = tlRendered[bi] || 0, bt = bc + br;
                    if (!bt) { continue; }
                    var hTot = Math.max(2, (bt / maxV) * bmax);
                    var hC = bt > 0 ? hTot * (bc / bt) : 0;
                    var bx = (L + 14) + (iw - 28 - bw) * (n < 2 ? 1 : bi / (n - 1)), by0 = T + ih;
                    barH[bi] = hTot;
                    var wpcBarDelay = (bi * 12) + 'ms';
                    if (hTot - hC > 0.5) {
                        var rTop = el('rect', { x: bx, y: by0 - hTot, width: bw, height: Math.max(1, hTot - hC), rx: 1, fill: accA(.16), 'class': 'wpc-vp-bar' });
                        rTop.style.animationDelay = wpcBarDelay;
                        svg.appendChild(rTop);
                    }
                    if (hC > 0.5) {
                        var rBot = el('rect', { x: bx, y: by0 - hC, width: bw, height: hC, rx: 1, fill: accA(.6), 'class': 'wpc-vp-bar' });
                        rBot.style.animationDelay = wpcBarDelay;
                        svg.appendChild(rBot);
                    }
                }
            }
            if (!showAxes) {
                var em = el('text', { x: L + iw / 2, y: T + ih * 0.42, 'text-anchor': 'middle', 'font-size': '12.5', 'font-weight': '500', fill: '#94a3b8' });
                if (typeof scBusy !== 'undefined' && scBusy[dev]) {
                    em.textContent = dev === 'd'
                        ? '<?php echo esc_js(__('Measuring desktop now — first numbers land in about a minute.', WPS_IC_TEXTDOMAIN)); ?>'
                        : '<?php echo esc_js(__('Running your first speed check — numbers land in about a minute.', WPS_IC_TEXTDOMAIN)); ?>';
                } else if (maxV > 0) {
                    var wpcFm28 = (VP.fmeta && VP.fmeta[dev]) || null;
                    if (wpcFm28 && wpcFm28.met < 1) {
                        em.textContent = '<?php echo esc_js(__('Visitors are landing, but their speed beacons never arrive — a firewall or security rule may be blocking them.', WPS_IC_TEXTDOMAIN)); ?>';
                    } else if (wpcFm28 && wpcFm28.fn > 0) {
                        em.textContent = '<?php echo esc_js(__('Collecting speed samples —', WPS_IC_TEXTDOMAIN)); ?> ' + wpcFm28.fn + ' <?php echo esc_js(__('so far. The trend line appears shortly.', WPS_IC_TEXTDOMAIN)); ?>';
                    } else {
                        em.textContent = '<?php echo esc_js(__('Visitors are landing — speed numbers appear here as they report in.', WPS_IC_TEXTDOMAIN)); ?>';
                    }
                } else {
                    em.textContent = '<?php echo esc_js(__('Measuring real visitor speed — the trend line appears after a few days of traffic.', WPS_IC_TEXTDOMAIN)); ?>';
                }
                svg.appendChild(em);
            }
            var lineKey = document.getElementById('wpc-vp-tlkey-line');
            if (lineKey) { lineKey.style.display = hasLine ? '' : 'none'; }
            var swp75 = document.querySelector('#wpc-vitals-panel .wpc-vp-sw-p75');
            if (swp75) { swp75.style.background = ACCG; }
            var lineKeyLab = document.getElementById('wpc-vp-tlkey-linelabel');
            if (lineKeyLab) { lineKeyLab.textContent = tlFcp ? '<?php echo esc_js(__('First paint · typical visitor', WPS_IC_TEXTDOMAIN)); ?>' : (region ? '<?php echo esc_js(__('Region · typical visitor', WPS_IC_TEXTDOMAIN)); ?>' : '<?php echo esc_js(__('Typical visitor load time', WPS_IC_TEXTDOMAIN)); ?>'); }
            var baseKey = document.getElementById('wpc-vp-tlkey-base');
            if (baseKey) {
                baseKey.style.display = (base > 0) ? '' : 'none';
                var baseKeyLab = document.getElementById('wpc-vp-tlkey-baselabel');
                if (baseKeyLab) { baseKeyLab.textContent = (VP.base && VP.base.label) || ''; }
            }
            function linePts(ser) {
                var pp = [];
                (ser || []).forEach(function (v, i) { if (v != null) { pp.push([X(i), Y(v)]); } });
                return pp;
            }
            function drawLine(ser, stroke, width, delayMs, dash, op, fade) {
                var pp = linePts(ser);
                if (pp.length < 2) { return null; }
                var attrs = { d: smooth(pp), fill: 'none', stroke: stroke, 'stroke-width': width, 'stroke-linecap': 'round', 'stroke-linejoin': 'round' };
                if (op) { attrs.opacity = op; }
                var path = el('path', attrs);
                path.setAttribute('class', (dash || fade) ? 'wpc-vp-area-anim' : 'wpc-vp-path-anim');
                if (dash) { path.setAttribute('stroke-dasharray', dash); }
                path.style.animationDelay = delayMs + 'ms';
                svg.appendChild(path);
                return pp;
            }
            var hero = region ? s75 : (wpcHeroP50 ? s50 : s75);
            var pts = linePts(hero);
            if (!pts.length && !region) { hero = s75; pts = linePts(s75); }
            if (pts.length > 1) {
                var grad = el('linearGradient', { id: 'wpcvpg', x1: 0, y1: 0, x2: 0, y2: 1 });
                grad.appendChild(el('stop', { offset: '0%', 'stop-color': ACC, 'stop-opacity': .18 }));
                grad.appendChild(el('stop', { offset: '100%', 'stop-color': ACC, 'stop-opacity': 0 }));
                defs.appendChild(grad);
                svg.appendChild(el('path', { d: smooth(pts) + 'L' + pts[pts.length - 1][0] + ',' + (T + ih) + 'L' + pts[0][0] + ',' + (T + ih) + 'Z', fill: 'url(#wpcvpg)', 'class': 'wpc-vp-area-anim' }));
                var heroPath = el('path', { d: smooth(pts), fill: 'none', stroke: ACC, 'stroke-width': 3, 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'class': 'wpc-vp-path-anim' });
                heroPath.style.animationDelay = '280ms';
                svg.appendChild(heroPath);
            }
            if (pts.length >= 1) {
                var last = pts[pts.length - 1];
                var lastHi = -1;
                for (var hi = hero.length - 1; hi >= 0; hi--) { if (hero[hi] != null) { lastHi = hi; break; } }
                if (pts.length > 1 && lastHi > -1 && lastHi < n - 1) {
                    var extX = X(n - 1);
                    svg.appendChild(el('line', { x1: last[0], y1: last[1], x2: extX, y2: last[1], stroke: ACC, 'stroke-width': 2.5, 'stroke-dasharray': '2 7', 'stroke-linecap': 'round', opacity: .55, 'class': 'wpc-vp-area-anim' }));
                    last = [extX, last[1]];
                }
                if (base > 0 && !region && pts.length === 1) {
                    var dx0 = L, dw = last[0] - dx0, dy0 = Y(base);
                    var dcrv = 'M' + dx0 + ',' + dy0
                        + 'C' + (dx0 + dw * 0.10) + ',' + (last[1] + (dy0 - last[1]) * 0.06)
                        + ' ' + (dx0 + dw * 0.30) + ',' + last[1]
                        + ' ' + last[0] + ',' + last[1];
                    var dgrad = el('linearGradient', { id: 'wpcvpdg', x1: 0, y1: 0, x2: 0, y2: 1 });
                    dgrad.appendChild(el('stop', { offset: '0%', 'stop-color': ACC, 'stop-opacity': .16 }));
                    dgrad.appendChild(el('stop', { offset: '100%', 'stop-color': ACC, 'stop-opacity': 0 }));
                    defs.appendChild(dgrad);
                    svg.appendChild(el('path', { d: dcrv + 'L' + last[0] + ',' + (T + ih) + 'L' + dx0 + ',' + (T + ih) + 'Z', fill: 'url(#wpcvpdg)', 'class': 'wpc-vp-area-anim' }));
                    var dPath = el('path', { d: dcrv, fill: 'none', stroke: ACC, 'stroke-width': 3, 'stroke-linecap': 'round', 'class': 'wpc-vp-path-anim' });
                    dPath.style.animationDelay = '240ms';
                    svg.appendChild(dPath);
                    svg.appendChild(el('circle', { cx: dx0, cy: dy0, r: 3.5, fill: '#f8fafc', stroke: '#94a3b8', 'stroke-width': 1.5, 'stroke-dasharray': '2 2', 'class': 'wpc-vp-area-anim' }));
                }
                var pulse = el('circle', { cx: last[0], cy: last[1], r: 5, fill: 'none', stroke: ACC, 'stroke-width': 2, 'class': 'wpc-vp-pulse' });
                pulse.style.transformOrigin = last[0] + 'px ' + last[1] + 'px';
                svg.appendChild(pulse);
                svg.appendChild(el('circle', { cx: last[0], cy: last[1], r: 5, fill: '#fff', stroke: ACC, 'stroke-width': 2.5, 'class': 'wpc-vp-area-anim' }));
                var lastVal = null;
                for (var li = hero.length - 1; li >= 0; li--) { if (hero[li] != null) { lastVal = hero[li]; break; } }
                var lvTxt = fmtMs(lastVal);
                var lvW = lvTxt.length * 7.8 + 18, lvH = 24;
                var lvG = el('g', { 'class': 'wpc-vp-area-anim', id: 'wpc-vp-endb' });
                lvG.appendChild(el('rect', { x: last[0] - 12 - lvW, y: last[1] - 12 - lvH / 2, width: lvW, height: lvH, rx: 8, fill: '#ffffff', 'fill-opacity': '.95', stroke: '#e2e8f0', 'stroke-width': 1 }));
                var lv = el('text', { x: last[0] - 12 - lvW / 2, y: last[1] - 7.5, 'text-anchor': 'middle', 'font-size': '13', 'font-weight': '800', fill: '#0f172a' });
                lv.textContent = lvTxt; lvG.appendChild(lv);
                svg.appendChild(lvG);
            }
            [0, Math.floor((n - 1) / 4), Math.floor((n - 1) / 2), Math.floor(3 * (n - 1) / 4), n - 1].forEach(function (i) {
                if (i < 0 || !tlLabels[i]) { return; }
                var ta = n === 1 ? 'end' : (i === 0 ? 'start' : (i === n - 1 ? 'end' : 'middle'));
                var tx = n === 1 ? W - R : (i === 0 ? L : (i === n - 1 ? W - R : X(i)));
                var t = el('text', { x: tx, y: H - 11, 'text-anchor': ta, 'font-size': '10.5', 'font-weight': '500', fill: '#94a3b8' });
                t.textContent = tlLabels[i]; svg.appendChild(t);
            });
            wrap.appendChild(svg);
            wpcTlW = wrap.clientWidth;
            var tip = tipFor(wrap);
            function endB(show) {
                var eb = svg.querySelector('#wpc-vp-endb');
                if (eb) { eb.classList[show ? 'remove' : 'add']('wpc-vp-hid'); }
            }
            var hover = el('g', {});
            svg.appendChild(hover);
            series.forEach(function (v, i) {
                var hz = el('rect', { x: X(i) - iw / Math.max(1, n - 1) / 2, y: T, width: iw / Math.max(1, n - 1), height: ih, fill: 'transparent' });
                hz.addEventListener('pointermove', function () {
                    var r = wrap.getBoundingClientRect();
                    var cached = tlCached[i] || 0, rendered = tlRendered[i] || 0, tot = cached + rendered;
                    var hy = (v != null) ? ((Y(v) / H) * r.height) : (((T + ih - (barH[i] || 2)) / H) * r.height);
                    function tipRow(sw, label, val) {
                        return '<span class="wpc-vp-tiprow"><i style="background:' + sw + '"></i><span>' + label + '</span><b>' + val + '</b></span>';
                    }
                    var fi = tlx ? tlx[i] : i;
                    var seeded = (!tlFcp && VP.sci != null && fi === VP.sci && s50[i] == null && s25[i] == null);
                    var rows = '';
                    if (region) {
                        if (s75[i] != null) { rows = tipRow(ACC, '<?php echo esc_js(__('Region · typical', WPS_IC_TEXTDOMAIN)); ?>', fmtMs(s75[i])); }
                    } else if (seeded && s75[i] != null) {
                        rows = tipRow(ACC, '<?php echo esc_js(__('Initial speed check · optimized', WPS_IC_TEXTDOMAIN)); ?>', fmtMs(s75[i]));
                    } else {
                        if (s25[i] != null) { rows += tipRow(accA(.35), '<?php echo esc_js(__('Fastest visits', WPS_IC_TEXTDOMAIN)); ?>', fmtMs(s25[i])); }
                        if (s50[i] != null) { rows += tipRow(ACC, '<?php echo esc_js(__('Typical visitor', WPS_IC_TEXTDOMAIN)); ?>', fmtMs(s50[i])); }
                        if (s75[i] != null) { rows += tipRow(ACCG, tlFcp ? '<?php echo esc_js(__('First paint · slowest quarter', WPS_IC_TEXTDOMAIN)); ?>' : '<?php echo esc_js(__('Slowest quarter', WPS_IC_TEXTDOMAIN)); ?>', fmtMs(s75[i])); }
                    }
                    endB(false);
                    place(tip, wrap, (X(i) / W) * r.width, hy,
                        '<span class="wpc-vp-tiphead">' + esc(tlLabels[i]) + '</span>' + rows +
                        (seeded
                            ? '<span class="wpc-vp-tipfoot"><?php echo esc_js(__('Measured from this browser — real visitors take over as they arrive', WPS_IC_TEXTDOMAIN)); ?>' + (tot ? ' \u00b7 <b>' + tot.toLocaleString() + '</b> <?php echo esc_js(__('views', WPS_IC_TEXTDOMAIN)); ?>' : '') + '</span>'
                            : '<span class="wpc-vp-tipfoot"><b>' + tot.toLocaleString() + '</b> <?php echo esc_js(__('views', WPS_IC_TEXTDOMAIN)); ?>' + (tot ? ' \u00b7 ' + Math.round(cached / tot * 100) + '% <?php echo esc_js(__('from cache', WPS_IC_TEXTDOMAIN)); ?>' : '') + '</span>'));
                });
                hz.addEventListener('pointerleave', function () { tip.classList.remove('show'); endB(true); });
                hover.appendChild(hz);
            });
            (VP.events || []).forEach(function (ev) {
                if (ev.i == null) { return; }
                var ei = tlx ? tlx.indexOf(ev.i) : ev.i;
                if (ei < 0 || ei >= n) { return; }
                var vv = series[ei], y = vv != null ? Y(vv) : T + 20;
                var x = X(ei);
                var g = el('g', { cursor: 'pointer' });
                g.appendChild(el('line', { x1: x, x2: x, y1: y, y2: T + ih, stroke: ACC, 'stroke-width': 1.5, opacity: .35, 'stroke-dasharray': '3 4' }));
                var ring = el('circle', { cx: x, cy: y, r: 8, fill: 'none', stroke: ACC, 'stroke-width': 2, 'class': 'wpc-vp-ring-anim' });
                ring.style.transformOrigin = x + 'px ' + y + 'px';
                g.appendChild(ring);
                g.appendChild(el('circle', { cx: x, cy: y, r: 6, fill: '#fff', stroke: ACC, 'stroke-width': 2.5 }));
                g.appendChild(el('circle', { cx: x, cy: y, r: 2, fill: ACC }));
                g.addEventListener('pointermove', function () {
                    var r = wrap.getBoundingClientRect();
                    place(tip, wrap, (x / W) * r.width, (y / H) * r.height,
                        '<b style="color:#93c5fd">' + esc(ev.t) + '</b> · <span style="color:#94a3b8">' + esc(tlLabels[ei] || '') + '</span><br>' + esc(ev.s));
                });
                g.addEventListener('pointerleave', function () { tip.classList.remove('show'); });
                svg.appendChild(g);
            });
        }


        function drawMatrix() {
            var mx = document.getElementById('wpc-vp-matrix');
            if (!mx) { return; }
            mx.innerHTML = '';
            var n = VP.labels.length;
            mx.style.gridTemplateColumns = '52px repeat(' + n + ', minmax(12px, 44px))';
            var mAny = false;
            ['lcp', 'inp', 'cls', 'ttfb'].forEach(function (mk) {
                ((VP.matrix[dev] || {})[mk] || []).forEach(function (v) { if (v != null) { mAny = true; } });
            });
            var oldNote = document.getElementById('wpc-vp-mnote');
            if (oldNote) { oldNote.remove(); }
            if (!mAny) {
                var mn = document.createElement('div');
                mn.id = 'wpc-vp-mnote'; mn.className = 'wpc-vp-mnote';
                mn.textContent = '<?php echo esc_js(__('Collecting — your visitors\' Core Web Vitals appear here as today\'s first visits are measured.', WPS_IC_TEXTDOMAIN)); ?>';
                mx.parentElement.insertBefore(mn, mx);
            }
            var rows = [['LCP', 'lcp'], ['INP', 'inp'], ['CLS', 'cls'], ['TTFB', 'ttfb']];
            var wrapEl = mx.parentElement;
            var tip = tipFor(wrapEl);
            var RATING = ['<?php echo esc_js(__('Good', WPS_IC_TEXTDOMAIN)); ?>', '<?php echo esc_js(__('Needs improvement', WPS_IC_TEXTDOMAIN)); ?>', '<?php echo esc_js(__('Poor', WPS_IC_TEXTDOMAIN)); ?>'];
            var RCOLOR = ['#10B981', '#F59E0B', '#EF4444'];
            rows.forEach(function (row, rowIdx) {
                var lab = document.createElement('div');
                lab.className = 'wpc-vp-mlab'; lab.textContent = row[0]; mx.appendChild(lab);
                var series = (VP.matrix[dev] || {})[row[1]] || [];
                var th = VP.th[row[1]] || [0, 0];
                for (var i = 0; i < n; i++) {
                    var v = series[i];
                    var c = document.createElement('div');
                    c.className = 'wpc-vp-cell' + (v == null ? '' : ' on');
                    if (animOn && !vpReduce) {
                        c.className += ' wpc-vp-cellin';
                        c.style.animationDelay = (i * 9 + rowIdx * 46) + 'ms';
                    }
                    var ri = v == null ? -1 : (v <= th[0] ? 0 : (v <= th[1] ? 1 : 2));
                    if (ri < 0) {
                        c.style.background = '#f1f5f9'; c.style.border = '1px solid #e2e8f0';
                    } else {
                        c.style.background = RCOLOR[ri];
                        c.style.boxShadow = 'inset 0 1px 2px rgba(255,255,255,0.15)';
                    }
                    (function (v2, i2, r2, ri2, c2) {
                        c2.addEventListener('mouseenter', function () {
                            var wr = wrapEl.getBoundingClientRect(), cr = c2.getBoundingClientRect();
                            var disp = v2 == null ? '—' : (r2 === 'cls' ? (v2 / 1000).toFixed(3) : fmtMs(v2));
                            var rate = ri2 < 0 ? '' : ' · <span style="color:' + RCOLOR[ri2] + '">' + RATING[ri2] + '</span>';
                            place(tip, wrapEl,
                                cr.left - wr.left + wrapEl.scrollLeft + cr.width / 2,
                                cr.top - wr.top + wrapEl.scrollTop,
                                '<b>' + r2.toUpperCase() + ' · ' + esc(VP.labels[i2] || '') + '</b><br>p75: <b>' + disp + '</b>' + rate);
                        });
                        c2.addEventListener('pointerleave', function () { tip.classList.remove('show'); });
                    })(v, i, row[1], ri, c);
                    mx.appendChild(c);
                }
            });
            var dl = document.createElement('div');
            dl.className = 'wpc-vp-mlab'; mx.appendChild(dl);
            var marks = n < 14 ? [0, Math.floor((n - 1) / 2), n - 1]
                : [0, Math.floor((n - 1) / 4), Math.floor((n - 1) / 2), Math.floor(3 * (n - 1) / 4), n - 1];
            for (var di = 0; di < n; di++) {
                var dc = document.createElement('div');
                dc.className = 'wpc-vp-mdate';
                dc.textContent = marks.indexOf(di) !== -1 && VP.labels[di] ? VP.labels[di] : '';
                mx.appendChild(dc);
            }
        }

        function setView(v) {
            view = v;
            animOn = !vpReduce;
            chipPhase = 'p75';
            document.querySelectorAll('#wpc-vitals-panel .wpc-vitals-viewseg button').forEach(function (x) {
                x.classList.toggle('active', x.getAttribute('data-wpc-view') === v);
                x.setAttribute('aria-selected', x.getAttribute('data-wpc-view') === v ? 'true' : 'false');
            });
            if (typeof scCard === 'function') {
                scCard(scRes, scLive);
            }
            ['timeline', 'experience', 'details'].forEach(function (k) {
                var e = document.getElementById('wpc-vp-' + k);
                if (e) { e.style.display = k === v ? '' : 'none'; }
            });
            chipVis();
        }
        function stageVis() {
            var vis = false;
            ['wpc-vp-spectrum', 'wpc-vp-regions'].forEach(function (id) {
                var e = document.getElementById(id);
                if (e && e.style.display !== 'none') { vis = true; }
            });
            var btn = document.getElementById('wpc-vp-expbtn');
            if (btn) { btn.style.display = vis ? '' : 'none'; }
            if (!vis && view === 'experience') { setView('timeline'); }
        }
        function render() { drawSpectrum(); drawRegions(); stageVis(); renderChips(); if (view === 'timeline') { drawTimeline(); } else if (view === 'details') { drawMatrix(); } animOn = false; var vpPanel = document.getElementById('wpc-vitals-panel'); if (vpPanel) { vpPanel.className += vpPanel.className.indexOf('wpc-vp-entered') === -1 ? ' wpc-vp-entered' : ''; } }

        document.querySelectorAll('#wpc-vitals-panel .wpc-vitals-toggle button').forEach(function (b) {
            b.addEventListener('click', function () {
                if (b.getAttribute('data-wpc-dev') === dev) { return; }
                dev = b.getAttribute('data-wpc-dev');
                document.querySelectorAll('#wpc-vitals-panel .wpc-vitals-toggle button').forEach(function (x) { x.className = ''; x.setAttribute('aria-pressed', 'false'); });
                b.className = 'active';
                b.setAttribute('aria-pressed', 'true');
                animOn = !vpReduce;
                chipPhase = 'p75';
                render();
                if (typeof scCard === 'function') { scCard(scRes, scLive); }
            });
        });
        document.querySelectorAll('#wpc-vitals-panel .wpc-vitals-viewseg button').forEach(function (b) {
            b.addEventListener('click', function () {
                setView(b.getAttribute('data-wpc-view'));
                render();
            });
        });








        function scFrame(url, w, h, dwell, measure) {
            return new Promise(function (resolve) {
                var f = document.createElement('iframe');
                f.credentialless = true;
                f.src = url;
                f.setAttribute('aria-hidden', 'true');
                f.setAttribute('tabindex', '-1');
                f.style.cssText = 'position:fixed;right:0;bottom:0;width:' + w + 'px;height:' + h + 'px;border:0;opacity:.01;transform:scale(.02);transform-origin:bottom right;pointer-events:none';
                var lcp = 0;
                f.addEventListener('load', function () {
                    if (!measure) { return; }
                    try {
                        new f.contentWindow.PerformanceObserver(function (l) {
                            var en = l.getEntries();
                            if (en.length) { lcp = en[en.length - 1].startTime; }
                        }).observe({ type: 'largest-contentful-paint', buffered: true });
                    } catch (e) {}
                });
                document.body.appendChild(f);
                setTimeout(function () { f.remove(); resolve(Math.round(lcp)); }, dwell);
            });
        }
        function scWarm(u) {
            return new Promise(function (res) {
                var done = function () { setTimeout(res, 1200); };
                try {
                    if (typeof fetch !== 'function') { res(); return; }
                    fetch(u, { cache: 'no-store', credentials: 'omit' }).then(function (r) {
                        if (r && r.text) { r.text().then(done, done); } else { done(); }
                    }, done);
                } catch (e) { res(); }
            });
        }
        function scText(html, done) {
            var box = document.getElementById('wpc-vp-sc'), t = document.getElementById('wpc-vp-sc-txt');
            if (!box || !t || view !== 'timeline') { return; }
            t.innerHTML = html;
            box.style.display = '';
            box.className = 'wpc-vitals-sc' + (done ? ' done' : '');
            var bnr = document.querySelector('#wpc-vitals-panel .wpc-vitals-banner');
            if (bnr && VP.bnrg) { bnr.style.display = 'none'; }
        }
        function scHide() {
            var box = document.getElementById('wpc-vp-sc');
            if (box) { box.style.display = 'none'; }
            var bnr = document.querySelector('#wpc-vitals-panel .wpc-vitals-banner');
            if (bnr) { bnr.style.display = ''; }
        }
        function scTry(mk) {
            return mk().then(function (v) { return v > 0 ? v : mk(); });
        }
        var scBrand = '<?php echo esc_js($wpc_vp_brand); ?>';
        var scWithout = '<?php echo esc_js(sprintf(__('Without %s', WPS_IC_TEXTDOMAIN), $wpc_vp_brand)); ?>';
        function scSkel(step, off) {
            var stage = step === 1
                ? '<?php echo esc_js(sprintf(__('Loading your homepage without %s — a first-time visit, no optimization…', WPS_IC_TEXTDOMAIN), $wpc_vp_brand)); ?>'
                : '<?php echo esc_js(__('Now measuring the fully optimized version — cached, compressed, delivered…', WPS_IC_TEXTDOMAIN)); ?>';
            scText('<div class="wpc-vp-scres"><div class="wpc-vp-scres-main">'
                + '<div class="wpc-vp-scres-head"><?php echo esc_js(__('Initial Speed Check', WPS_IC_TEXTDOMAIN)); ?><span class="wpc-vp-scghost">' + stage + '</span></div>'
                + '<div class="wpc-vp-scrow"><span class="lab">' + scWithout + '</span><div class="trk' + (step === 1 ? ' wpc-vp-shim' : '') + '"><div class="fill off" data-wpc-w="' + (step > 1 && off > 0 ? 100 : 0) + '"></div></div><b class="chip' + (step > 1 && off > 0 ? '' : ' empty') + '">' + (step > 1 && off > 0 ? fmtMs(off) : '—') + '</b></div>'
                + '<div class="wpc-vp-scrow"><span class="lab"><?php echo esc_js(__('Fully optimized', WPS_IC_TEXTDOMAIN)); ?></span><div class="trk' + (step > 1 ? ' wpc-vp-shim' : '') + '"><div class="fill on" data-wpc-w="0"></div></div><b class="chip empty">—</b></div>'
                + '</div></div>', true);
            requestAnimationFrame(function () {
                document.querySelectorAll('#wpc-vp-sc .fill').forEach(function (fl) {
                    fl.style.width = fl.getAttribute('data-wpc-w') + '%';
                });
            });
        }
        function scSkelD() {
            scText('<div class="wpc-vp-scres"><div class="wpc-vp-scres-main">'
                + '<div class="wpc-vp-scres-head"><?php echo esc_js(__('Initial Speed Check', WPS_IC_TEXTDOMAIN)); ?><span class="wpc-vp-scghost"><?php echo esc_js(__('Measuring the desktop version now — first numbers land in about a minute…', WPS_IC_TEXTDOMAIN)); ?></span></div>'
                + '<div class="wpc-vp-scrow"><span class="lab">' + scWithout + '</span><div class="trk wpc-vp-shim"><div class="fill off" data-wpc-w="0"></div></div><b class="chip empty">—</b></div>'
                + '<div class="wpc-vp-scrow"><span class="lab"><?php echo esc_js(__('Fully optimized', WPS_IC_TEXTDOMAIN)); ?></span><div class="trk wpc-vp-shim"><div class="fill on" data-wpc-w="0"></div></div><b class="chip empty">—</b></div>'
                + '</div></div>', true);
        }
        function scDeskDot(on) {
            var b = document.querySelector('#wpc-vitals-panel .wpc-vitals-toggle button[data-wpc-dev="d"]');
            if (!b) { return; }
            var d = b.querySelector('.wpc-vp-devdot');
            if (on && !d) {
                d = document.createElement('i');
                d.className = 'wpc-vp-devdot';
                b.appendChild(d);
            } else if (!on && d) {
                d.parentNode.removeChild(d);
            }
        }
        function scDemoLand(r) {
            if (!VP.demo) { return; }
            var v = scLeg(r, 'm');
            if (!v || !(v.on > 0)) { return; }
            VP.demo = 0;
            VP.have4 = { m: 0, d: 0 };
            VP.v28 = 0;
            VP.reg = [];
            VP.events = [];
            VP.chips = { m: {}, d: {} };
            VP.matrix = { m: {}, d: {} };
            VP.base = { m: 0, d: 0, label: '' };
            VP.tlx = [];
            var zn = (VP.labels && VP.labels.length) ? VP.labels.length : 28;
            var zi, zNull = [], zZero = [];
            for (zi = 0; zi < zn; zi++) { zNull.push(null); zZero.push(0); }
            VP.cached = zZero.slice();
            VP.rendered = zZero.slice();
            ['m', 'd'].forEach(function (dv) {
                if (VP.lcp) { VP.lcp[dv] = zNull.slice(); }
                if (VP.lcpq) { VP.lcpq[dv] = { p25: zNull.slice(), p50: zNull.slice() }; }
                if (VP.spec) { VP.spec[dv] = { opt: 0, p50: 0, p75: 0, n: 0, hn: 0, sh1: -1, ghost: 0 }; }
            });
            VP.bnrg = 1;
            var bnr = document.querySelector('#wpc-vitals-panel .wpc-vitals-banner');
            if (bnr) {
                var bt = bnr.querySelector('.wpc-vitals-banner-txt');
                bnr.style.opacity = '0';
                setTimeout(function () {
                    if (bt) {
                        bt.innerHTML = '<strong><?php echo esc_js(__('Optimized delivery is live — measuring your visitors\' real experience.', WPS_IC_TEXTDOMAIN)); ?></strong>'
                            + '<span><?php echo esc_js(__('Your first real measurements are landing now — numbers build through the day.', WPS_IC_TEXTDOMAIN)); ?></span>';
                    }
                    bnr.style.opacity = '1';
                }, vpReduce ? 0 : 500);
            }
            animOn = !vpReduce;
            render();
        }
        function scLeg(r, dv) {
            if (!r) { return null; }
            if (r[dv] && r[dv].on > 0) { return r[dv]; }
            if (dv === 'm' && r.on > 0) { return r; }
            return null;
        }
        function scValid(rr) { return !!(rr && rr.on > 0 && rr.off > rr.on * 1.15); }
        var scRes = null, scLive = false;
        var scBusy = { m: false, d: false };
        function scQuietNow() { return !!(VP.have4 && VP.have4.m && VP.have4.d && !VP.demo); }
        function scBusySet(dv, on) {
            scBusy[dv] = !!on;
            if (view === 'timeline') { drawTimeline(); }
            if (dev === 'd' && typeof scCard === 'function') { scCard(scRes, scLive); }
        }
        function scInfoTxt(when, legName) {
            return when + ' \u00b7 <?php echo esc_js(__('your homepage, loaded as a first-time visitor', WPS_IC_TEXTDOMAIN)); ?> \u00b7 ' + legName + '. <?php echo esc_js(__('Real visitor measurements take over as they arrive.', WPS_IC_TEXTDOMAIN)); ?>';
        }
        var scOpen = false;
        try { scOpen = localStorage.getItem('wpcVpScOpen') === '1'; } catch (e) {}
        function scBadgeEl() { return document.getElementById('wpc-vp-scbadge'); }
        function scCard(r, live) {
            scRes = r; scLive = live;
            var rl = scLeg(r, dev);
            var rm = scValid(rl) ? rl : (scValid(scLeg(r, 'm')) ? scLeg(r, 'm') : (rl || scLeg(r, 'm')));
            var badge = scBadgeEl();
            if (view !== 'timeline') { scHide(); if (badge) { badge.style.display = 'none'; } return; }
            if (dev === 'd' && scBusy.d && !scQuietNow() && !scValid(scLeg(r, 'd'))) {
                if (badge) { badge.style.display = 'none'; }
                scSkelD();
                return;
            }
            var mature = !!(VP.have4 && VP.have4[dev] && !VP.demo);
            if (!rm && mature) {
                if (dev === 'm' && VP.bpair && VP.bpair.off > 0 && VP.bpair.on > 0 && VP.bpair.off > VP.bpair.on * 1.15) {
                    rm = { off: VP.bpair.off, on: VP.bpair.on, rv: 1, avg: VP.bpair.avg };
                } else {
                    var spd = (VP.spec && VP.spec[dev]) ? VP.spec[dev] : null;
                    if (spd && spd.ghost > 0 && spd.p75 > 0 && spd.ghost > spd.p75 * 1.15) {
                        rm = { off: spd.ghost, on: spd.p75, rv: 1 };
                    }
                }
            }
            if (!rm) {
                if ((scBusy.m || scBusy.d) && !scQuietNow()) { return; }
                scHide(); if (badge) { badge.style.display = 'none'; } return;
            }
            if (mature) {
                if (badge && rm.off > 0 && rm.off > rm.on * 1.15) {
                    badge.innerHTML = '<i><svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M10 5l2.1 4.9L17 12l-4.9 2.1L10 19l-2.1-4.9L3 12l4.9-2.1L10 5z" fill="#10B981"/><path d="M18 2l1.2 2.8L22 6l-2.8 1.2L18 10l-1.2-2.8L14 6l2.8-1.2L18 2z" fill="#34d399" opacity=".75"/><path d="M19 15l.9 2.1 2.1.9-2.1.9-.9 2.1-.9-2.1-2.1-.9 2.1-.9.9-2.1z" fill="#34d399" opacity=".5"/></svg></i><b>' + Math.round((1 - rm.on / rm.off) * 100) + '%</b><span><?php echo esc_js(__('faster', WPS_IC_TEXTDOMAIN)); ?></span>';
                    badge.style.display = '';
                    badge.setAttribute('aria-expanded', scOpen ? 'true' : 'false');
                } else if (badge) {
                    badge.style.display = 'none';
                }
                if (!scOpen) { scHide(); return; }
            } else if (badge) {
                badge.style.display = 'none';
            }
            var legName = rm.rv
                ? (dev === 'd' ? '<?php echo esc_js(__('desktop', WPS_IC_TEXTDOMAIN)); ?>' : '<?php echo esc_js(__('mobile', WPS_IC_TEXTDOMAIN)); ?>')
                : ((rm === scLeg(r, 'd') && scLeg(r, 'd') !== scLeg(r, 'm')) ? '<?php echo esc_js(__('desktop', WPS_IC_TEXTDOMAIN)); ?>' : '<?php echo esc_js(__('mobile', WPS_IC_TEXTDOMAIN)); ?>');
            var when = live ? '<?php echo esc_js(__('measured just now from this browser', WPS_IC_TEXTDOMAIN)); ?>' : '<?php echo esc_js(__('measured from this browser', WPS_IC_TEXTDOMAIN)); ?>';
            var scHeadTxt = rm.rv ? '<?php echo esc_js(__('Before & After', WPS_IC_TEXTDOMAIN)); ?>' : '<?php echo esc_js(__('Initial Speed Check', WPS_IC_TEXTDOMAIN)); ?>';
            var scOnLab = rm.rv ? (rm.avg ? '<?php echo esc_js(__('Average visit', WPS_IC_TEXTDOMAIN)); ?>' : '<?php echo esc_js(__('Typical visit', WPS_IC_TEXTDOMAIN)); ?>') : '<?php echo esc_js(__('Fully optimized', WPS_IC_TEXTDOMAIN)); ?>';
            var scInfo = rm.rv
                ? '<?php echo esc_js(__('Measured from your real visitors — typical load time (p75) vs real unoptimized comparison visits', WPS_IC_TEXTDOMAIN)); ?> · ' + legName + '.'
                : scInfoTxt(when, legName);
            if (rm.off > 0 && rm.off > rm.on * 1.15) {
                var pct = Math.round((1 - rm.on / rm.off) * 100);
                var onW = Math.max(4, Math.round(rm.on / rm.off * 100));
                var scStatHtml = mature ? '' : '<div class="wpc-vp-scstat"><i><svg width="11" height="7" viewBox="0 0 11 7" aria-hidden="true"><path d="M1.5 1.5 5.5 5.5 9.5 1.5" fill="none" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg></i><b>' + pct + '%</b><span><?php echo esc_js(__('faster', WPS_IC_TEXTDOMAIN)); ?></span></div>';
                scText('<div class="wpc-vp-scres">'
                    + '<div class="wpc-vp-scres-main">'
                    + '<div class="wpc-vp-scres-head">' + scHeadTxt
                    + '<span class="wpc-vp-scinfo" tabindex="0" role="note" aria-label="' + scInfo.replace(/"/g, '&quot;') + '">i<span class="wpc-vp-scinfo-tip">' + scInfo + '</span></span>'
                    + (live ? '<span class="wpc-vp-sctoast" id="wpc-vp-sctoast">✓ <?php echo esc_js(__('Measured just now', WPS_IC_TEXTDOMAIN)); ?></span>' : '')
                    + '</div>'
                    + '<div class="wpc-vp-scrow"><span class="lab">' + scWithout + '</span><div class="trk"><div class="fill off" data-wpc-w="100"></div></div><b class="chip">' + fmtMs(rm.off) + '</b></div>'
                    + '<div class="wpc-vp-scrow"><span class="lab">' + scOnLab + '</span><div class="trk"><div class="fill on" data-wpc-w="' + onW + '"></div></div><b class="chip fast">' + fmtMs(rm.on) + '</b></div>'
                    + '</div>'
                    + scStatHtml
                    + '</div>', true);
                requestAnimationFrame(function () {
                    document.querySelectorAll('#wpc-vp-sc .fill').forEach(function (fl) {
                        fl.style.width = fl.getAttribute('data-wpc-w') + '%';
                    });
                });
                var tst = document.getElementById('wpc-vp-sctoast');
                if (tst) {
                    scLive = false;
                    setTimeout(function () {
                        tst.classList.add('out');
                        setTimeout(function () { if (tst.parentNode) { tst.parentNode.removeChild(tst); } }, 600);
                    }, 4000);
                }
                var sEl = document.querySelector('#wpc-vp-sc .wpc-vp-scstat b');
                if (sEl && !vpReduce) {
                    var scT0 = null;
                    var scStep = function (ts) {
                        if (!scT0) { scT0 = ts; }
                        var k = Math.min(1, (ts - scT0) / 900);
                        sEl.textContent = Math.round(pct * (1 - Math.pow(1 - k, 3))) + '%';
                        if (k < 1) { requestAnimationFrame(scStep); }
                    };
                    requestAnimationFrame(scStep);
                }
            } else {
                scText('<b><?php echo esc_js(__('Initial Speed Check', WPS_IC_TEXTDOMAIN)); ?></b> · <?php echo esc_js(__('your homepage painted in', WPS_IC_TEXTDOMAIN)); ?> <b class="wpc-vp-scfast">' + fmtMs(rm.on) + '</b> <?php echo esc_js(__('for a first-time visitor', WPS_IC_TEXTDOMAIN)); ?> · ' + when, true);
            }
        }



        function scInject(r) {
            if (VP.demo || !r || r.e !== 1) { return; }
            var chg = false;
            ['m', 'd'].forEach(function (dv) {
                var rr = scLeg(r, dv);
                if (!rr) { return; }
                var sp = (VP.spec && VP.spec[dv]) ? VP.spec[dv] : null;
                if (sp && sp.p75 > 0 && sp.n >= 10 && !(rr.off > sp.p75 * 1.15)) { return; }
                if (scValid(rr) && VP.base && !(VP.base[dv] > 0)) {
                    VP.base[dv] = Math.round(rr.off);
                    VP.base.label = scWithout;
                    chg = true;
                }
                if (scValid(rr) && VP.spec && VP.spec[dv] && !(VP.spec[dv].ghost > 0)) {
                    VP.spec[dv].ghost = Math.round(rr.off);
                    chg = true;
                }
                if (rr.on > 0 && VP.lcp && VP.lcp[dv] && VP.lcp[dv].length) {
                    var li = VP.lcp[dv].length - 1;
                    if (VP.lcp[dv][li] == null) {
                        VP.lcp[dv][li] = Math.round(rr.on);
                        if (VP.tlx && VP.tlx.indexOf(li) === -1) { VP.tlx.push(li); }
                        VP.sci = li;
                        chg = true;
                    }
                }
            });
            if (chg && view === 'timeline') { animOn = !vpReduce; render(); }
            return chg;
        }
        (function () {
            var badge = scBadgeEl();
            if (badge) {
                badge.addEventListener('click', function () {
                    scOpen = !scOpen;
                    try { localStorage.setItem('wpcVpScOpen', scOpen ? '1' : '0'); } catch (e) {}
                    badge.setAttribute('aria-expanded', scOpen ? 'true' : 'false');
                    scCard(scRes, scLive);
                });
            }
        })();
        var scCred = 'credentialless' in HTMLIFrameElement.prototype;





        function scSave(r) {
            try {
                if (!VP.scn || typeof ajaxurl === 'undefined' || !window.fetch) { return; }
                var fd = new FormData();
                fd.append('action', 'wpc_vitals_sc_save');
                fd.append('n', VP.scn);
                fd.append('r', JSON.stringify(r));
                fetch(ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' });
            } catch (e) {}
        }
        try {
            var scStored = JSON.parse(localStorage.getItem('wpcVpSC') || 'null');





            if (scStored && scStored.e === 1 && (!VP.scr || (VP.scr.t || 0) < (scStored.t || 0))) { scSave(scStored); }
            if (!(scStored && scStored.e === 1) && VP.scr && VP.scr.e === 1) { scStored = VP.scr; }
            if (VP.sc && scStored && scStored.e === 1 && scLeg(scStored, 'm')) { scCard(scStored, false); scInject(scStored); }
            var scFresh = scStored && scStored.e === 1 && (Date.now() - (scStored.t || 0)) <= 24 * 3600 * 1000;
            var scDok = !!(scStored && scStored.d && scStored.d.on > 0 && scStored.d.off > 0);
            if (VP.sc && scCred && document.visibilityState === 'visible'
                && scFresh && scLeg(scStored, 'm') && !scDok) {
                var scLastD = parseInt(localStorage.getItem('wpcVpScRun') || '0', 10);
                if (!(scLastD > 0) || (Date.now() - scLastD) > 20 * 60 * 1000) {
                    localStorage.setItem('wpcVpScRun', String(Date.now()));
                    setTimeout(function () {
                        scDeskDot(true);
                        scBusySet('d', true);
                        scTry(function () { return scFrame(VP.sc.ub + '&wpcb=' + Date.now(), 1200, 800, 12000, true); }).then(function (doff) {
                            return scWarm(VP.sc.u).then(function () {
                                return scTry(function () { return scFrame(VP.sc.u, 1200, 800, 10000, true); });
                            }).then(function (don) {
                                var mig = { m: scLeg(scStored, 'm'), t: scStored.t || Date.now(), e: 1 };
                                if (don > 0 && doff > 0) { mig.d = { off: doff, on: don }; }
                                try { localStorage.setItem('wpcVpSC', JSON.stringify(mig)); } catch (e) {}
                                scSave(mig);
                                scDeskDot(false);
                                scBusy.d = false;
                                scCard(mig, false);
                                if (!scInject(mig) && view === 'timeline') { drawTimeline(); }
                            });
                        });
                    }, 1500);
                }
            }
            if (VP.sc && scCred && document.visibilityState === 'visible' && !scFresh) {
                var scLast = parseInt(localStorage.getItem('wpcVpScRun') || '0', 10);
                if (!(scLast > 0) || (Date.now() - scLast) > 20 * 60 * 1000) {
                    localStorage.setItem('wpcVpScRun', String(Date.now()));
                    var scQuiet = !!(VP.have4 && VP.have4.m && VP.have4.d && !VP.demo);
                    setTimeout(function () {
                        if (!scQuiet) { scSkel(1, 0); }
                        scBusy.m = true;
                        scBusySet('d', true);
                        scTry(function () { return scFrame(VP.sc.ub + '&wpcb=' + Date.now(), 390, 700, 12000, true); }).then(function (off) {
                            if (!scQuiet) { scSkel(2, off); }
                            return scWarm(VP.sc.u).then(function () {
                                return scTry(function () { return scFrame(VP.sc.u, 390, 700, 10000, true); });
                            }).then(function (on) {
                                if (!(on > 0 && off > 0)) {
                                    scBusy.m = false;
                                    scBusy.d = false;
                                    scDeskDot(false);
                                    if (!scQuiet && view === 'timeline') { scHide(); }
                                    if (view === 'timeline') { drawTimeline(); }
                                    return;
                                }
                                var res = { m: { off: off, on: on }, t: Date.now(), e: 1 };
                                try { localStorage.setItem('wpcVpSC', JSON.stringify(res)); } catch (e) {}
                                scSave(res);
                                scDemoLand(res);
                                scCard(res, true);
                                scBusy.m = false;
                                if (!scInject(res) && view === 'timeline') { drawTimeline(); }
                                scDeskDot(true);
                                return scTry(function () { return scFrame(VP.sc.ub + '&wpcb=' + Date.now(), 1200, 800, 12000, true); }).then(function (doff) {
                                    return scWarm(VP.sc.u).then(function () {
                                        return scTry(function () { return scFrame(VP.sc.u, 1200, 800, 10000, true); });
                                    }).then(function (don) {
                                        if (don > 0 && doff > 0) {
                                            res.d = { off: doff, on: don };
                                            try { localStorage.setItem('wpcVpSC', JSON.stringify(res)); } catch (e) {}
                                scSave(res);
                                        }
                                        scDeskDot(false);
                                        scBusy.d = false;
                                        if (!scInject(res) && view === 'timeline') { drawTimeline(); }
                                        if (dev === 'd') { scCard(res, true); }
                                    });
                                });
                            });
                        });
                    }, 1500);
                }
            }
        } catch (e) {}
        if (!scRes && typeof scCard === 'function') { scCard(null, false); }



        try {
            if ((!VP.sc || !scCred) && VP.bl && (VP.bl.m > 0 || VP.bl.d > 0) && VP.bl.u && document.visibilityState === 'visible') {
                var blLast = parseInt(localStorage.getItem('wpcVpBlRun') || '0', 10);
                if (!(blLast > 0) || (Date.now() - blLast) > 20 * 60 * 1000) {
                    localStorage.setItem('wpcVpBlRun', String(Date.now()));
                    setTimeout(function () {
                        var legs = [];
                        if (VP.bl.m > 0) { legs.push([390, 700]); }
                        if (VP.bl.d > 0) { legs.push([1200, 800]); }
                        legs.forEach(function (sz, i) {
                            setTimeout(function () {
                                var f = document.createElement('iframe');
                                f.src = VP.bl.u + '&wpcb=' + Date.now();
                                f.setAttribute('aria-hidden', 'true');
                                f.setAttribute('tabindex', '-1');
                                f.style.cssText = 'position:fixed;right:0;bottom:0;width:' + sz[0] + 'px;height:' + sz[1] + 'px;border:0;opacity:.01;transform:scale(.02);transform-origin:bottom right;pointer-events:none';
                                document.body.appendChild(f);
                                setTimeout(function () { f.remove(); }, 15000);
                            }, i * 4000);
                        });
                    }, 2500);
                }
            }
        } catch (e) {}
        var wpcTlW = 0;
        var vpIO = (!vpReduce && 'IntersectionObserver' in window) ? new IntersectionObserver(function (en) {
            en.forEach(function (e) {
                if (e.isIntersecting && vpIO) { vpIO.disconnect(); vpIO = null; animOn = true; render(); }
            });
        }, { threshold: 0.3 }) : null;
        if (vpIO) { animOn = false; }
        render();
        if (vpIO) { vpIO.observe(document.getElementById('wpc-vitals-panel')); }
        var ro = window.ResizeObserver ? new ResizeObserver(function () {
            var tl = document.getElementById('wpc-vp-tl');
            var w = tl ? tl.clientWidth : 0;
            if (view === 'timeline' && w > 0 && Math.abs(w - wpcTlW) > 2) { wpcTlW = w; drawTimeline(); }
        }) : null;
        if (ro) { ro.observe(document.getElementById('wpc-vp-tl')); }
    })();
</script>
