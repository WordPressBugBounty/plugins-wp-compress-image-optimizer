<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WPC_V2_TELEMETRY_MAX_ENTRIES')) {
    define('WPC_V2_TELEMETRY_MAX_ENTRIES', 200);
}
if (!defined('WPC_V2_TELEMETRY_TTL')) {
    define('WPC_V2_TELEMETRY_TTL', 3600);
}


function wpc_v2_telemetry_record($type, $ms, array $meta = [])
{
    if (!get_option('wpc_v2_telemetry_enabled')) return;
    $type = (string) $type;
    $ms   = (int) $ms;
    if ($type === '' || $ms < 0) return;

    $buf = get_transient('wpc_v2_fpm_telemetry');
    if (!is_array($buf)) $buf = [];
    $buf[] = [
        't'    => (int) round(microtime(true) * 1000),
        'type' => $type,
        'ms'   => $ms,
        'meta' => $meta,
    ];
    
    if (count($buf) > WPC_V2_TELEMETRY_MAX_ENTRIES) {
        $buf = array_slice($buf, -WPC_V2_TELEMETRY_MAX_ENTRIES);
    }
    set_transient('wpc_v2_fpm_telemetry', $buf, WPC_V2_TELEMETRY_TTL);
}


function wpc_v2_telemetry_stats()
{
    $out = [
        'enabled'       => (bool) get_option('wpc_v2_telemetry_enabled'),
        'total_entries' => 0,
        'oldest_age_s'  => 0,
        'newest_age_s'  => 0,
        'types'         => [],
    ];

    $buf = get_transient('wpc_v2_fpm_telemetry');
    if (!is_array($buf) || empty($buf)) {
        return $out;
    }

    $out['total_entries'] = count($buf);
    $now_ms = (int) round(microtime(true) * 1000);
    $oldest_t = PHP_INT_MAX;
    $newest_t = 0;

    
    $buckets = [];
    foreach ($buf as $e) {
        if (!is_array($e) || empty($e['type']) || !isset($e['ms'])) continue;
        $type = (string) $e['type'];
        $ms   = (int) $e['ms'];
        $t    = isset($e['t']) ? (int) $e['t'] : 0;
        if (!isset($buckets[$type])) $buckets[$type] = [];
        $buckets[$type][] = $ms;
        if ($t > 0 && $t < $oldest_t) $oldest_t = $t;
        if ($t > 0 && $t > $newest_t) $newest_t = $t;
    }

    if ($oldest_t < PHP_INT_MAX) {
        $out['oldest_age_s'] = max(0, (int) round(($now_ms - $oldest_t) / 1000));
    }
    if ($newest_t > 0) {
        $out['newest_age_s'] = max(0, (int) round(($now_ms - $newest_t) / 1000));
    }

    foreach ($buckets as $type => $samples) {
        sort($samples, SORT_NUMERIC);
        $n = count($samples);
        if ($n === 0) continue;
        $sum = array_sum($samples);
        $p = function ($pct) use ($samples, $n) {
            $idx = max(0, min($n - 1, (int) floor(($pct / 100) * ($n - 1))));
            return (int) $samples[$idx];
        };
        $slow_2s = 0;
        $slow_5s = 0;
        foreach ($samples as $s) {
            if ($s >= 5000) { $slow_5s++; $slow_2s++; }
            elseif ($s >= 2000) { $slow_2s++; }
        }
        $out['types'][$type] = [
            'count'         => $n,
            'mean_ms'       => (int) round($sum / $n),
            'p50_ms'        => $p(50),
            'p95_ms'        => $p(95),
            'p99_ms'        => $p(99),
            'max_ms'        => (int) max($samples),
            'slow_2s_count' => $slow_2s,
            'slow_5s_count' => $slow_5s,
        ];
    }

    return $out;
}




function wpc_v2_telemetry_format_stats(array $stats)
{
    $lines = [];
    $lines[] = sprintf(
        'WP Compress FPM telemetry — enabled=%s, entries=%d, age=%ds…%ds',
        $stats['enabled'] ? 'YES' : 'no',
        $stats['total_entries'],
        $stats['newest_age_s'],
        $stats['oldest_age_s']
    );
    if (empty($stats['types'])) {
        $lines[] = '  (no samples — run a compress, then check again)';
        return implode("\n", $lines);
    }
    foreach ($stats['types'] as $type => $t) {
        $lines[] = sprintf(
            '  %-10s  n=%-4d  mean=%-5dms  p50=%-5dms  p95=%-5dms  p99=%-5dms  max=%-5dms  slow(>=2s)=%-3d  slow(>=5s)=%-3d',
            $type, $t['count'], $t['mean_ms'], $t['p50_ms'], $t['p95_ms'],
            $t['p99_ms'], $t['max_ms'], $t['slow_2s_count'], $t['slow_5s_count']
        );
    }
    return implode("\n", $lines);
}




function wpc_v2_telemetry_clear()
{
    delete_transient('wpc_v2_fpm_telemetry');
}
