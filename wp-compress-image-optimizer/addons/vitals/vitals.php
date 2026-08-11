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
            
            
            
            $endpoint = defined('WPS_IC_URI')
                ? WPS_IC_URI . 'addons/vitals/v.php'
                : plugins_url('addons/vitals/v.php', dirname(__DIR__, 2) . '/wp-compress.php');
            $token    = sha1($cfg['salt']);
            
            
            
            
            $wpc_byp833 = !empty($_GET['disableWPC']) ? ',b:1' : '';
            echo "\n<script>window.wpcVitals={u:" . wp_json_encode($endpoint) . ",t:" . wp_json_encode($token) . ",s:" . (int) $cfg['sample'] . ",m:" . (int) time() . $wpc_byp833 . "};</script>"
                . "<script>" . $js . "</script>\n";
        } catch (\Throwable $e) {
        }
    }
    add_action('wp_footer', 'wpc_vitals_emit', 99);
}


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
        
        
        
        
        
        $wpc_ping831 = ['v' => 0, 'hit' => 0, 'mob' => 0, 'r' => []];
        $agg['bm'] = ['lcp' => []];
        $agg['bd'] = ['lcp' => []];
        
        
        
        
        $agg['hm'] = ['lcp' => [], 'ttfb' => []];
        $agg['hd'] = ['lcp' => [], 'ttfb' => []];
        $agg['rv'] = [];
        $wpc_rv845 = [];
        for ($i = 0; $i < $n; $i++) {
            $r = array_values(unpack('C8', substr($bytes, $i * 8, 8)));
            if ($r[0] === 0xA6) {
                if ($r[1] & 0x10) { continue; } 
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




if (!function_exists('wpc_vitals_today_partial')) {
    function wpc_vitals_today_partial()
    {
        try {
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


if (!function_exists('wpc_vitals_rollup')) {
    function wpc_vitals_rollup()
    {
        try {
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

            
            
            if (!get_option('wpc_vitals_baseline') && count($kept) >= 7 && function_exists('wpc_vitals_p75')) {
                $base  = wpc_vitals_p75(array_slice($kept, -7, null, true), 'm', 'lcp');
                $baseD = wpc_vitals_p75(array_slice($kept, -7, null, true), 'd', 'lcp');
                if ($base > 0) {
                    update_option('wpc_vitals_baseline', ['t' => time(), 'lcp_m_p75' => $base, 'lcp_d_p75' => max(0, (int) $baseD)], false);
                }
            }
            
            
            
            $wpc_vb832 = get_option('wpc_vitals_baseline');
            if (is_array($wpc_vb832) && empty($wpc_vb832['lcp_d_p75']) && count($kept) >= 7 && function_exists('wpc_vitals_p75')) {
                $wpc_bd832 = (int) wpc_vitals_p75(array_slice($kept, -7, null, true), 'd', 'lcp');
                if ($wpc_bd832 > 0) {
                    $wpc_vb832['lcp_d_p75'] = $wpc_bd832;
                    update_option('wpc_vitals_baseline', $wpc_vb832, false);
                }
            }

            
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
    
    function wpc_vitals_bucket_edges($cls = false)
    {
        return $cls
            ? [0, 5, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 120, 140, 160, 180, 200, 225, 250, 300, 350, 400, 500, 600, 750, 1000, 1500, 2000, 3000, 4000]
            : [100, 200, 300, 400, 500, 600, 700, 800, 900, 1000, 1200, 1400, 1600, 1800, 2000, 2250, 2500, 2750, 3000, 3500, 4000, 4500, 5000, 6000, 7000, 8500, 10000, 15000, 25000, 40000];
    }

    
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
