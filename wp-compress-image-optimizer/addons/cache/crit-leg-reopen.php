<?php
/**
 * An announce reopens the /v2/latest leg (cohort crit_leg_reopen, default off).
 *
 * (a) Every announce clears the leg's memory for its url_key (latest_404.txt, the wpc_v2l_ throttle,
 *     the retry ladder); one that did not land the crit pair also marks repull_due.txt and schedules one
 *     wpc_lcp_repull 5 s out, which lands the crit_uuid /v2/latest names through pullDerivedArtifacts.
 * (b) A remembered /v2/latest 404 expires after 30 min, doubling per consecutive 404 of the same page
 *     state (30, 60, 120, 240, 480 min), each expiry with its repull; after the fifth it stays remembered
 *     and is reported final.
 * (c) crit_leg_closed / crit_fetch_fail ride the /v2/config echo and the health body.
 * Off (cohort absent, WPC_CRIT_LEG_REOPEN_KILL, or filter wpc_crit_leg_reopen false): every entry point
 * returns before it reads or writes anything.
 */

if (!defined('WPC_CRIT_LEG_OPTION')) {
    define('WPC_CRIT_LEG_OPTION', 'wpc_crit_leg');
}

if (!function_exists('wpc_crit_leg_on')) {
    function wpc_crit_leg_on()
    {
        if (defined('WPC_CRIT_LEG_REOPEN_KILL') && WPC_CRIT_LEG_REOPEN_KILL) {
            return false;
        }
        if (function_exists('wpc_policy_cohort')) {
            $on = (int) wpc_policy_cohort('crit_leg_reopen') === 1;
        } else {
            // The cron lane (where the repull runs) does not load v2-policy.php.
            $on = function_exists('wpc_policy_cohort_stored') && wpc_policy_cohort_stored('crit_leg_reopen');
        }
        if (!$on) {
            return false;
        }
        return !function_exists('apply_filters') || apply_filters('wpc_crit_leg_reopen', true) !== false;
    }
}

if (!function_exists('wpc_crit_leg_dir')) {
    function wpc_crit_leg_dir($urlKey)
    {
        $urlKey = ltrim((string) $urlKey, '/');
        if ($urlKey === '' || !defined('WPS_IC_CRITICAL') || strpos($urlKey, '..') !== false) {
            return '';
        }
        return rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
    }

    function wpc_crit_leg_pair_missing($dir)
    {
        return (int) @filesize($dir . 'critical_desktop.css') <= 64 || (int) @filesize($dir . 'critical_mobile.css') <= 64;
    }

    function wpc_crit_leg_write($path, $value)
    {
        return function_exists('wpc_crit_meta_write') ? wpc_crit_meta_write($path, $value) : (@file_put_contents($path, $value) !== false);
    }

    function wpc_crit_leg_sched($ts, $urlKey, $attempt)
    {
        $args = [(string) $urlKey, (int) $attempt];
        if (function_exists('wp_next_scheduled') && wp_next_scheduled('wpc_lcp_repull', $args)) {
            return 'already';
        }
        if (function_exists('wpc_pl_sched')) {
            $ok = wpc_pl_sched((int) $ts, 'wpc_lcp_repull', $args);
        } elseif (function_exists('wp_schedule_single_event')) {
            $ok = wp_schedule_single_event((int) $ts, 'wpc_lcp_repull', $args);
        } else {
            $ok = false;
        }
        return ($ok === false || (function_exists('is_wp_error') && is_wp_error($ok))) ? 'refused' : 'scheduled';
    }
}

if (!function_exists('wpc_crit_leg_ladder')) {
    /** The retry ladder: [base seconds, max retries]. */
    function wpc_crit_leg_ladder()
    {
        $base = max(60, (int) apply_filters('wpc_crit_leg_404_ttl_s', 1800));
        $max = max(0, min(10, (int) apply_filters('wpc_crit_leg_retry_max', 5)));
        return [$base, $max];
    }

    /** The ladder for the page as it is now: n consecutive 404s of this page state, the last at t. */
    function wpc_crit_leg_retry_read($dir)
    {
        $state = function_exists('wpc_latest_miss_state') ? wpc_latest_miss_state($dir) : '';
        $parts = explode(' ', trim((string) @file_get_contents($dir . 'leg_retry.txt')), 3);
        if (count($parts) !== 3 || $parts[2] !== $state) {
            return ['n' => 0, 't' => 0, 'state' => $state];
        }
        return ['n' => (int) $parts[0], 't' => (int) $parts[1], 'state' => $state];
    }

    /** The seconds the n-th consecutive 404 is remembered: base * 2^(n-1). */
    function wpc_crit_leg_ttl($n)
    {
        list($base) = wpc_crit_leg_ladder();
        return (int) ($base * (2 ** max(0, (int) $n - 1)));
    }
}

if (!function_exists('wpc_crit_leg_miss_expired')) {
    /** (b) Whether the remembered 404 on $dir has outlived its rung (false when off, or once the ladder is spent). */
    function wpc_crit_leg_miss_expired($dir)
    {
        if (!wpc_crit_leg_on()) {
            return false;
        }
        $r = wpc_crit_leg_retry_read($dir);
        list(, $max) = wpc_crit_leg_ladder();
        if ($r['n'] <= 0) {
            // A 404 remembered before the cohort was on (or by a build without the ladder): its
            // age is the file's.
            $at = (int) @filemtime($dir . 'latest_404.txt');
            return $at > 0 && (time() - $at) >= wpc_crit_leg_ttl(1);
        }
        if ($r['n'] > $max) {
            return false;
        }
        return (time() - $r['t']) >= wpc_crit_leg_ttl($r['n']);
    }
}

if (!function_exists('wpc_crit_leg_note')) {
    /** (c) One url_key's leg record; $field 'closed' or 'fail', $reason '' clears that field. */
    function wpc_crit_leg_note($urlKey, $field, $reason, array $extra = [])
    {
        $urlKey = ltrim((string) $urlKey, '/');
        if ($urlKey === '' || !function_exists('get_option')) {
            return;
        }
        $all = get_option(WPC_CRIT_LEG_OPTION, []);
        $all = is_array($all) ? $all : [];
        $now = time();
        $row = isset($all[$urlKey]) && is_array($all[$urlKey]) ? $all[$urlKey] : [];
        $old = $row;
        if ($reason === '') {
            unset($row[$field], $row[$field . '_at']);
        } else {
            if (($row[$field] ?? '') === (string) $reason && ($now - (int) ($row[$field . '_at'] ?? 0)) < 600 && $extra == array_intersect_key($row, $extra)) {
                return;
            }
            $row[$field] = substr((string) $reason, 0, 80);
            $row[$field . '_at'] = $now;
        }
        foreach ($extra as $k => $v) {
            $row[$k] = $v;
        }
        if (!isset($row['closed']) && !isset($row['fail'])) {
            unset($all[$urlKey]);
        } else {
            $all[$urlKey] = $row;
        }
        if ($row === $old && isset($all[$urlKey])) {
            return;
        }
        // Bounded: a week of history, thirty pages.
        foreach ($all as $k => $r) {
            $last = max((int) ($r['closed_at'] ?? 0), (int) ($r['fail_at'] ?? 0));
            if ($now - $last > 7 * 86400) {
                unset($all[$k]);
            }
        }
        if (count($all) > 30) {
            uasort($all, function ($a, $b) {
                return max((int) ($b['closed_at'] ?? 0), (int) ($b['fail_at'] ?? 0)) <=> max((int) ($a['closed_at'] ?? 0), (int) ($a['fail_at'] ?? 0));
            });
            $all = array_slice($all, 0, 30, true);
        }
        update_option(WPC_CRIT_LEG_OPTION, $all, false);
    }

    function wpc_crit_leg_forget($urlKey)
    {
        $all = function_exists('get_option') ? get_option(WPC_CRIT_LEG_OPTION, []) : [];
        $urlKey = ltrim((string) $urlKey, '/');
        if (is_array($all) && isset($all[$urlKey])) {
            unset($all[$urlKey]);
            update_option(WPC_CRIT_LEG_OPTION, $all, false);
        }
    }
}

if (!function_exists('wpc_crit_leg_on_announce')) {
    /**
     * (a) An announce for $urlKey arrived ($via 'manifest' | 'callback'); $landed whether the
     * device pair it names is now on disk; $why the consume's failure. Returns what it did.
     */
    function wpc_crit_leg_on_announce($urlKey, $via, $landed, $why = '')
    {
        try {
            if (!wpc_crit_leg_on()) {
                return [];
            }
            $dir = wpc_crit_leg_dir($urlKey);
            if ($dir === '' || !@is_dir($dir)) {
                return [];
            }
            $did = ['cleared' => []];
            foreach (['latest_404.txt', 'leg_retry.txt'] as $f) {
                if (@is_file($dir . $f) && @unlink($dir . $f)) {
                    $did['cleared'][] = $f;
                }
            }
            if (function_exists('delete_transient')) {
                delete_transient('wpc_v2l_' . md5((string) $urlKey));
            }
            $missing = wpc_crit_leg_pair_missing($dir);
            // An announce of a generation older than the one on disk is spent, not failed.
            if (!$landed && !$missing && ((string) $why === 'superseded' || strpos((string) $why, 'manifest-stale-gen') === 0)) {
                $landed = true;
            }
            if (!$landed || $missing || apply_filters('wpc_crit_leg_fetch_on_landed', false)) {
                wpc_crit_leg_write($dir . 'repull_due.txt', (string) time());
                $did['repull'] = wpc_crit_leg_sched(time() + 5, $urlKey, 1);
            }
            if (!$landed || $missing) {
                $reason = (string) $via . ':' . ($why !== '' ? (string) $why : ($landed ? 'pair-missing' : 'not-landed'));
                wpc_crit_leg_note($urlKey, 'fail', $reason);
                wpc_crit_leg_note($urlKey, 'closed', '');
                $did['fail'] = $reason;
            } else {
                wpc_crit_leg_forget($urlKey);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-leg-announce', (string) $urlKey, '', $did + ['via' => (string) $via, 'landed' => $landed ? 1 : 0]);
            }
            return $did;
        } catch (\Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('wpc_crit_leg_owed')) {
    /** The leg's extra opening condition: the pair is missing on a page this site dispatched. */
    function wpc_crit_leg_owed($dir)
    {
        return wpc_crit_leg_on() && @is_readable($dir . 'dispatch_ts.txt') && wpc_crit_leg_pair_missing($dir);
    }
}

if (!function_exists('wpc_crit_leg_gate_note')) {
    /** Called where the repull decides on the /v2/latest leg: records why a page owed crit is not asking. */
    function wpc_crit_leg_gate_note($urlKey, $dir)
    {
        if (!wpc_crit_leg_on()) {
            return;
        }
        if (!wpc_crit_leg_pair_missing($dir)) {
            wpc_crit_leg_forget($urlKey);
            return;
        }
        if (function_exists('wpc_latest_miss_remembered') && wpc_latest_miss_remembered($dir)) {
            $r = wpc_crit_leg_retry_read($dir);
            list(, $max) = wpc_crit_leg_ladder();
            $final = $r['n'] > $max;
            $next = $final ? 0 : ($r['n'] > 0 ? $r['t'] + wpc_crit_leg_ttl($r['n']) : (int) @filemtime($dir . 'latest_404.txt') + wpc_crit_leg_ttl(1));
            wpc_crit_leg_note($urlKey, 'closed', $final ? 'latest-404-final' : 'latest-404', ['n' => $r['n'], 'next' => $next]);
            // The rung's own repull: WP cron unschedules the running event first, so this is the
            // one pending [key, 1] unless the chain already holds an earlier one.
            if (!$final) {
                wpc_crit_leg_sched(max(time() + 5, $next + 5), $urlKey, 1);
            }
        }
    }
}

if (!function_exists('wpc_crit_leg_latest_answer')) {
    /**
     * After the repull's /v2/latest GET. 200: while the pair is missing, land the crit_uuid it
     * names. Anything else: one more rung of the ladder (bounded), its repull scheduled.
     */
    function wpc_crit_leg_latest_answer($urlKey, $dir, $code, $json = null)
    {
        try {
            if (!wpc_crit_leg_on()) {
                return '';
            }
            $code = (int) $code;
            if ($code === 200) {
                if (!wpc_crit_leg_pair_missing($dir)) {
                    @unlink($dir . 'leg_retry.txt');
                    wpc_crit_leg_forget($urlKey);
                    return 'present';
                }
                $uuid = is_array($json) && !empty($json['ready']) && isset($json['crit_uuid']) && is_string($json['crit_uuid'])
                    ? preg_replace('/[^a-f0-9-]/i', '', $json['crit_uuid']) : '';
                if (strlen((string) $uuid) < 8) {
                    wpc_crit_leg_note($urlKey, 'fail', 'latest-200-not-ready');
                    return 'not-ready';
                }
                if (!class_exists('wps_criticalCss') && defined('WPS_IC_DIR') && @is_file(WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php')) {
                    include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
                }
                if (!class_exists('wps_criticalCss') || !method_exists('wps_criticalCss', 'pullDerivedArtifacts')) {
                    wpc_crit_leg_note($urlKey, 'fail', 'latest-no-class');
                    return 'no-class';
                }
                (new wps_criticalCss())->pullDerivedArtifacts((string) $urlKey, $uuid, false, 0, ['via' => 'latest']);
                if (!wpc_crit_leg_pair_missing($dir)) {
                    @unlink($dir . 'leg_retry.txt');
                    wpc_crit_leg_forget($urlKey);
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('crit-leg-landed', (string) $urlKey, '', ['uuid' => substr($uuid, 0, 8)]);
                    }
                    return 'landed';
                }
                wpc_crit_leg_note($urlKey, 'fail', 'latest-pull-failed', ['uuid' => substr($uuid, 0, 8)]);
                // fall through: the pull failing is a rung like any other miss
            }
            $r = wpc_crit_leg_retry_read($dir);
            list(, $max) = wpc_crit_leg_ladder();
            $n = $r['n'] + 1;
            wpc_crit_leg_write($dir . 'leg_retry.txt', $n . ' ' . time() . ' ' . $r['state']);
            if ($code !== 200) {
                wpc_crit_leg_note($urlKey, 'fail', 'latest-http-' . ($code === -1 ? 'foreign-key' : $code));
            }
            if ($n > $max) {
                wpc_crit_leg_note($urlKey, 'closed', 'latest-404-final', ['n' => $n, 'next' => 0]);
                return 'final';
            }
            $next = time() + wpc_crit_leg_ttl($n) + 5;
            if ($code === 404) {
                wpc_crit_leg_note($urlKey, 'closed', 'latest-404', ['n' => $n, 'next' => $next]);
            }
            wpc_crit_leg_sched($next, $urlKey, 1);
            return 'rung-' . $n;
        } catch (\Throwable $e) {
            return '';
        }
    }
}

if (!function_exists('wpc_crit_leg_mutex_skipped')) {
    /** A repull the mutex ate while an announce's re-check is open (repull_due ≤ 1 h) is put back once, 60 s on. */
    function wpc_crit_leg_mutex_skipped($urlKey, $attempt)
    {
        if (!wpc_crit_leg_on()) {
            return '';
        }
        $dir = wpc_crit_leg_dir($urlKey);
        $due = $dir !== '' ? (int) @filemtime($dir . 'repull_due.txt') : 0;
        if ($due <= 0 || (time() - $due) >= 3600 || !wpc_crit_leg_pair_missing($dir)) {
            return '';
        }
        return wpc_crit_leg_sched(time() + 60, $urlKey, max(1, (int) $attempt));
    }
}

if (!function_exists('wpc_crit_leg_echo')) {
    /** (c) crit_leg_closed / crit_fetch_fail for the echo and health body; [] when off. */
    function wpc_crit_leg_echo()
    {
        if (!wpc_crit_leg_on()) {
            return [];
        }
        $all = get_option(WPC_CRIT_LEG_OPTION, []);
        $all = is_array($all) ? $all : [];
        $home = '';
        if (class_exists('wps_ic_url_key') && function_exists('home_url')) {
            try {
                $home = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
            } catch (\Throwable $e) {
                $home = '';
            }
        }
        $now = time();
        $closed = ['n' => 0, 'home' => '', 'keys' => []];
        $fail = ['n' => 0, 'home' => '', 'last' => '', 'last_at' => 0];
        foreach ($all as $k => $r) {
            if (!empty($r['closed'])) {
                $closed['n']++;
                if ((string) $k === $home) {
                    $closed['home'] = (string) $r['closed'];
                }
                if (count($closed['keys']) < 5) {
                    $closed['keys'][] = [substr((string) $k, 0, 80), (string) $r['closed'], $now - (int) ($r['closed_at'] ?? $now), (int) ($r['n'] ?? 0)];
                }
            }
            if (!empty($r['fail'])) {
                $fail['n']++;
                if ((string) $k === $home) {
                    $fail['home'] = (string) $r['fail'];
                }
                if ((int) ($r['fail_at'] ?? 0) >= $fail['last_at']) {
                    $fail['last'] = (string) $r['fail'];
                    $fail['last_at'] = (int) ($r['fail_at'] ?? 0);
                }
            }
        }
        return ['crit_leg_closed' => $closed, 'crit_fetch_fail' => $fail];
    }
}
