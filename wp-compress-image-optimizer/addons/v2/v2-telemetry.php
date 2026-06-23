<?php
/**
 * WP Compress v7.02.11 — FPM telemetry.
 *
 * Records two stream of timings to a rolling 200-entry transient so we can
 * diagnose FPM saturation without grepping debug.log or relying on host-level
 * php-fpm status (which Cloudways doesn't expose by default):
 *
 *   1. `heartbeat` — wall time of wps_ic_media_library_heartbeat handler
 *      (call site: classes/ajax.class.php). Includes any FPM queue wait
 *      experienced by the worker before it started executing (via
 *      REQUEST_TIME_FLOAT vs entry_t delta).
 *   2. `batch` — wall time of wpc_v2_handle_bg_swap_batch handler
 *      (inbound_to_complete_ms, captured from REQUEST_TIME_FLOAT to handler
 *      return). Same FPM-aware semantic.
 *
 * Stats are surfaced via `wp wpc fpm-stats` wp-cli command and the admin
 * widget at Settings → WP Compress → Debug → FPM Telemetry. Both read from
 * the same transient. No host config required, works universally.
 *
 * Disabled by default; enable via `wp option update wpc_v2_telemetry_enabled 1`.
 * Auto-trims on write so the transient size stays bounded even under heavy
 * traffic. ~50 KB max footprint.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('WPC_V2_TELEMETRY_MAX_ENTRIES')) {
    define('WPC_V2_TELEMETRY_MAX_ENTRIES', 200);
}
if (!defined('WPC_V2_TELEMETRY_TTL')) {
    define('WPC_V2_TELEMETRY_TTL', 3600);
}

/**
 * Append one timing entry to the rolling buffer. Cheap: one transient
 * read + one transient write. Concurrent writes can lose individual
 * entries — acceptable for telemetry. Skips when option disabled to keep
 * the production fast path zero-cost.
 *
 * $type:  'heartbeat' | 'batch' | other short label
 * $ms:    integer milliseconds (caller-computed)
 * $meta:  optional array of extra fields (image_id, variant_count, etc).
 *         Kept small to bound transient size.
 */
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
    // Ring-buffer: keep only the last N entries.
    if (count($buf) > WPC_V2_TELEMETRY_MAX_ENTRIES) {
        $buf = array_slice($buf, -WPC_V2_TELEMETRY_MAX_ENTRIES);
    }
    set_transient('wpc_v2_fpm_telemetry', $buf, WPC_V2_TELEMETRY_TTL);
}

/**
 * Compute aggregate stats from the rolling buffer. Returns per-type breakdown
 * with count, mean, p50, p95, p99, max, slow count (>2s), age range.
 *
 * Return shape:
 *   { 'enabled' => bool, 'total_entries' => int, 'oldest_age_s' => int,
 *     'types' => { 'heartbeat' => { count, mean_ms, p50_ms, p95_ms, p99_ms, max_ms, slow_2s_count, slow_5s_count },
 *                  'batch'     => { ... } } }
 */
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

    // Bucket by type.
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

/**
 * Pretty-print stats as a single string. Used by wp-cli and admin widget.
 */
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

/**
 * Clear the rolling buffer. Exposed for wp-cli (`wp wpc fpm-stats clear`).
 */
function wpc_v2_telemetry_clear()
{
    delete_transient('wpc_v2_fpm_telemetry');
}
