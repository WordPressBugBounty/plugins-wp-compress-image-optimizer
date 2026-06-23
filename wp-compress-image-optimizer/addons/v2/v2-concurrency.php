<?php
/**
 * WP Compress — Adaptive Concurrency Control (AIMD).
 *
 * Plugin-side companion to orch's BatchedCallbackOutbox concurrency gate
 * (v3.18.19-adaptive-concurrency). Implements the math from
 * docs/SPEC-adaptive_concurrency.md §3:
 *
 *   • Per-callback-type AIMD (batch / announce / single)
 *   • Records inbound_to_complete_ms (REQUEST_TIME_FLOAT → handler complete)
 *   • Computes p20 baseline + p95 saturation signal over rolling 100 timings
 *   • Multiplicative decrease (×0.5) on saturation; additive increase (+1) on healthy growth
 *   • Adjusts every 15 callbacks + 30s cooldown
 *   • Initial cap = 3, floor = 2, ceiling = 50
 *   • Cap exposed via wpc_v2_get_max_concurrent() for /optimize-v2 envelope
 *   • CLI/cron multiplier (2×) for batch + announce (matches orch's per-origin tracking)
 *   • Manual override via admin UI option (pins cap, disables adaptive)
 *
 * Storage: non-autoloaded WP options. Per-type state (cap, timings, baseline,
 * last_adjust_at, adjust_log). One option write per ~15 callbacks (cheap).
 *
 * Failure modes documented in spec §4. Three kill switches in spec §9.
 *
 * @see docs/SPEC-adaptive_concurrency.md
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_record_handler_timing')) {

// ─── Constants (spec §3 "Why these specific numbers") ─────────────────────

// Legacy ceiling stays at 50 (orch ignores plugin's advertised cap
// when ADAPTIVE_CONCURRENCY_ENABLED=0). When the wpc_v2_aimd_tuned_enabled
// flag is ON, the effective values become 8/2/12 via the helpers below.
if (!defined('WPC_V2_AC_INITIAL_CAP'))     define('WPC_V2_AC_INITIAL_CAP', 3);
if (!defined('WPC_V2_AC_FLOOR'))           define('WPC_V2_AC_FLOOR', 2);
if (!defined('WPC_V2_AC_CEILING'))         define('WPC_V2_AC_CEILING', 50);

// Tuned-mode constants for once orch flips ADAPTIVE_CONCURRENCY_ENABLED=1.
// Defaults match the orch team's recommendation: INITIAL 3→8 (start where
// the AIMD usually settles), FLOOR stays 2, CEILING 50→12 (orch's actual
// per-customer fleet ceiling once enforcement turns on).
if (!defined('WPC_V2_AC_INITIAL_CAP_TUNED')) define('WPC_V2_AC_INITIAL_CAP_TUNED', 8);
if (!defined('WPC_V2_AC_FLOOR_TUNED'))       define('WPC_V2_AC_FLOOR_TUNED', 2);
if (!defined('WPC_V2_AC_CEILING_TUNED'))     define('WPC_V2_AC_CEILING_TUNED', 12);

/**
 * Effective AIMD constants. Returns the tuned set when the
 * wpc_v2_aimd_tuned_enabled flag is on, legacy otherwise. Centralized so
 * every read site (get_cap, set_cap, adjustment math) sees the same view.
 */
function wpc_v2_ac_effective_caps() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $tuned = function_exists('wpc_v2_aimd_tuned_enabled') && wpc_v2_aimd_tuned_enabled();
    $cached = $tuned
        ? ['initial' => WPC_V2_AC_INITIAL_CAP_TUNED, 'floor' => WPC_V2_AC_FLOOR_TUNED, 'ceiling' => WPC_V2_AC_CEILING_TUNED]
        : ['initial' => WPC_V2_AC_INITIAL_CAP, 'floor' => WPC_V2_AC_FLOOR, 'ceiling' => WPC_V2_AC_CEILING];
    return $cached;
}
if (!defined('WPC_V2_AC_WINDOW_SIZE'))     define('WPC_V2_AC_WINDOW_SIZE', 100);
if (!defined('WPC_V2_AC_ADJUST_EVERY_N'))  define('WPC_V2_AC_ADJUST_EVERY_N', 15);
if (!defined('WPC_V2_AC_COOLDOWN_S'))      define('WPC_V2_AC_COOLDOWN_S', 30);
if (!defined('WPC_V2_AC_SATURATED_MULT'))  define('WPC_V2_AC_SATURATED_MULT', 3.0);
if (!defined('WPC_V2_AC_RELAXED_MULT'))    define('WPC_V2_AC_RELAXED_MULT', 1.2);
if (!defined('WPC_V2_AC_UTILIZATION'))     define('WPC_V2_AC_UTILIZATION', 0.7);
if (!defined('WPC_V2_AC_ADJUST_LOG_SIZE')) define('WPC_V2_AC_ADJUST_LOG_SIZE', 20);

/**
 * Map type string → option suffix. Centralized so a typo can't drift across files.
 * Valid types: 'batch', 'announce', 'single'.
 */
function wpc_v2_ac_valid_type($type) {
    return in_array($type, ['batch', 'announce', 'single'], true);
}

function wpc_v2_ac_opt_name($base, $type) {
    return 'wpc_v2_' . $base . '_' . $type;
}

/**
 * Read current cap for a callback type. Honors manual override + the
 * `wpc_v2_max_concurrent_override` filter (rollback hatch per spec §9).
 *
 * @param string $type 'batch' | 'announce' | 'single'
 * @return int 2-50
 */
function wpc_v2_ac_get_cap($type) {
    $caps = wpc_v2_ac_effective_caps();

    if (!wpc_v2_ac_valid_type($type)) {
        return $caps['initial'];
    }

    // Rollback kill switch (spec §9 — instant override)
    $override = apply_filters('wpc_v2_max_concurrent_override', null, $type);
    if ($override !== null) {
        return max($caps['floor'], min($caps['ceiling'], (int) $override));
    }

    // Manual admin pin (per-type or all)
    $manual = get_option('wpc_v2_concurrency_manual_' . $type, null);
    if ($manual === null) {
        $manual = get_option('wpc_v2_concurrency_manual_all', null);
    }
    if ($manual !== null && (int) $manual > 0) {
        return max($caps['floor'], min($caps['ceiling'], (int) $manual));
    }

    $cap = (int) get_option(wpc_v2_ac_opt_name('concurrency_cap', $type), $caps['initial']);
    return max($caps['floor'], min($caps['ceiling'], $cap));
}

/**
 * Compute percentile of an integer array. Simple sort + index; window is
 * small (≤100) so this is microseconds.
 */
function wpc_v2_ac_percentile(array $values, $p) {
    if (empty($values)) return 0.0;
    sort($values);
    $idx = (int) floor(($p / 100.0) * (count($values) - 1));
    return (float) $values[$idx];
}

/**
 * Append a timing to the rolling window for a callback type. Triggers the
 * AIMD adjustment every WPC_V2_AC_ADJUST_EVERY_N records (default 15).
 *
 * CRITICAL: the value passed should be `inbound_to_complete_ms` measured from
 * `$_SERVER['REQUEST_TIME_FLOAT']` — NOT handler-only `total_handler_ms`. See
 * spec §3 "CRITICAL — what we measure". Otherwise AIMD never sees FPM saturation.
 *
 * @param string $type   'batch' | 'announce' | 'single'
 * @param float  $ms     inbound_to_complete_ms (REQUEST_TIME_FLOAT → now)
 */
function wpc_v2_record_handler_timing($type, $ms) {
    if (!wpc_v2_ac_valid_type($type)) return;
    $ms = (float) $ms;
    if ($ms < 0 || $ms > 600000) return; // sanity: drop anything > 10min as garbage

    $opt_timings = wpc_v2_ac_opt_name('handler_timings', $type);
    $timings = get_option($opt_timings, []);
    if (!is_array($timings)) $timings = [];

    // Append + trim to window size (deque-style, oldest first)
    $timings[] = (int) round($ms);
    if (count($timings) > WPC_V2_AC_WINDOW_SIZE) {
        $timings = array_slice($timings, -WPC_V2_AC_WINDOW_SIZE);
    }
    update_option($opt_timings, $timings, false); // non-autoloaded

    // The adjustment trigger must NOT use count($timings) because the
    // window slices to WPC_V2_AC_WINDOW_SIZE (100), so count freezes at 100
    // once full. count(100) % 15 = 10 (never 0) → AIMD permanently stops
    // adjusting after the window fills. Use a monotonically-increasing
    // recording counter instead so the every-Nth trigger keeps firing for
    // the lifetime of the plugin.
    $opt_rec_n  = wpc_v2_ac_opt_name('rec_count', $type);
    $rec_count  = (int) get_option($opt_rec_n, 0) + 1;
    update_option($opt_rec_n, $rec_count, false);
    if ($rec_count % WPC_V2_AC_ADJUST_EVERY_N === 0) {
        wpc_v2_ac_maybe_adjust($type, $timings);
    }
}

/**
 * AIMD step. Compares p95 against baseline×saturation_mult and either
 * (a) backs off cap multiplicatively, (b) grows additively, or (c) holds.
 * Respects 30s cooldown to prevent thrashing on transient spikes.
 */
function wpc_v2_ac_maybe_adjust($type, array $timings) {
    if (count($timings) < 10) return; // need enough samples for stable percentiles

    $opt_last_adjust = wpc_v2_ac_opt_name('last_adjust_at', $type);
    $last_adjust_at = (int) get_option($opt_last_adjust, 0);
    if ($last_adjust_at > 0 && (time() - $last_adjust_at) < WPC_V2_AC_COOLDOWN_S) {
        return; // cooldown active
    }

    $baseline_p20 = wpc_v2_ac_percentile($timings, 20);
    $p95          = wpc_v2_ac_percentile($timings, 95);

    // Persist baseline so admin diagnostic can show it cheaply
    update_option(wpc_v2_ac_opt_name('baseline_ms', $type), $baseline_p20, false);

    $cap = wpc_v2_ac_get_cap($type);
    $old_cap = $cap;
    $reason = 'hold';
    $direction = 'hold';
    $caps = wpc_v2_ac_effective_caps();

    // Saturation: p95 > baseline × saturation_mult → multiplicative decrease
    if ($baseline_p20 > 0 && $p95 > $baseline_p20 * WPC_V2_AC_SATURATED_MULT) {
        $new_cap = max($caps['floor'], (int) floor($cap * 0.5));
        if ($new_cap !== $cap) {
            $cap = $new_cap;
            $reason = 'saturated_3x';
            $direction = 'decrease';
        }
    } elseif ($baseline_p20 > 0 && $p95 < $baseline_p20 * WPC_V2_AC_RELAXED_MULT) {
        // Healthy: only grow if we're actually USING the cap (utilization check)
        // We can't easily observe "in-flight" from plugin side, so we use a proxy:
        // recent callback throughput >= cap × 0.7 timings in last cooldown window.
        $recent_throughput = min(count($timings), WPC_V2_AC_ADJUST_EVERY_N);
        if ($recent_throughput >= $cap * WPC_V2_AC_UTILIZATION) {
            $new_cap = min($caps['ceiling'], $cap + 1);
            if ($new_cap !== $cap) {
                $cap = $new_cap;
                $reason = 'healthy_growth';
                $direction = 'increase';
            }
        }
    }

    if ($direction !== 'hold') {
        update_option(wpc_v2_ac_opt_name('concurrency_cap', $type), $cap, false);
        update_option($opt_last_adjust, time(), false);

        // Append to adjust log ring buffer (cap at 20 entries)
        $opt_log = wpc_v2_ac_opt_name('adjust_log', $type);
        $log = get_option($opt_log, []);
        if (!is_array($log)) $log = [];
        $log[] = [
            'ts'         => time(),
            'direction'  => $direction,
            'old_cap'    => $old_cap,
            'new_cap'    => $cap,
            'baseline'   => (int) round($baseline_p20),
            'p95'        => (int) round($p95),
            'reason'     => $reason,
        ];
        if (count($log) > WPC_V2_AC_ADJUST_LOG_SIZE) {
            $log = array_slice($log, -WPC_V2_AC_ADJUST_LOG_SIZE);
        }
        update_option($opt_log, $log, false);

        error_log(sprintf(
            '[wpc_v2_concurrency_adjust] type=%s direction=%s old=%d new=%d baseline=%dms p95=%dms reason=%s',
            $type, $direction, $old_cap, $cap,
            (int) round($baseline_p20), (int) round($p95), $reason
        ));
    }
}

/**
 * Get current cap object for /optimize-v2 envelope. Applies CLI/cron 2× multiplier
 * per spec §3 "WP-CLI / cron context".
 *
 * @return array{batch:int, announce:int, single:int}
 */
function wpc_v2_get_max_concurrent() {
    $is_cli_or_cron = (defined('WP_CLI') && WP_CLI)
                   || (defined('DOING_CRON') && DOING_CRON);
    $mult = $is_cli_or_cron ? 2 : 1;
    $caps = wpc_v2_ac_effective_caps();

    return [
        'batch'    => min($caps['ceiling'], wpc_v2_ac_get_cap('batch') * $mult),
        'announce' => min($caps['ceiling'], wpc_v2_ac_get_cap('announce') * $mult),
        'single'   => wpc_v2_ac_get_cap('single'), // unmultiplied per spec
    ];
}

/**
 * Returns 'cli' if in CLI/cron context, 'web' otherwise. Used in /optimize-v2
 * envelope `origin` field (spec §11 F4: body-signed, not header).
 */
function wpc_v2_get_request_origin() {
    if ((defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON)) {
        return 'cli';
    }
    return 'web';
}

/**
 * Admin diagnostic API. Returns the full per-type state — useful for
 * admin UI status panel + WP-CLI inspection.
 *
 * @return array
 */
function wpc_v2_get_concurrency_state() {
    $out = ['types' => []];
    foreach (['batch', 'announce', 'single'] as $type) {
        $timings  = get_option(wpc_v2_ac_opt_name('handler_timings', $type), []);
        if (!is_array($timings)) $timings = [];
        $baseline = (float) get_option(wpc_v2_ac_opt_name('baseline_ms', $type), 0);
        $log      = get_option(wpc_v2_ac_opt_name('adjust_log', $type), []);
        if (!is_array($log)) $log = [];
        $out['types'][$type] = [
            'cap'              => wpc_v2_ac_get_cap($type),
            'baseline_ms'      => (int) round($baseline),
            'p95_ms'           => count($timings) >= 10 ? (int) round(wpc_v2_ac_percentile($timings, 95)) : null,
            'sample_count'     => count($timings),
            'recent_adjusts'   => array_slice($log, -5),
            'manual_pinned'    => get_option('wpc_v2_concurrency_manual_' . $type, null) !== null
                                  || get_option('wpc_v2_concurrency_manual_all', null) !== null,
        ];
    }
    $out['cli_multiplier']    = (defined('WP_CLI') && WP_CLI) ? 2 : 1;
    $out['envelope_advert']   = wpc_v2_get_max_concurrent();
    $out['origin']            = wpc_v2_get_request_origin();
    return $out;
}

/**
 * Manual override admin AJAX. Customer can pin a value for diagnostic purposes
 * or to work around adaptive misfiring on very-low-traffic sites.
 */
add_action('wp_ajax_wpc_v2_set_concurrency_manual', function () {
    if (!current_user_can('manage_wpc_settings')) {
        wp_send_json_error('forbidden');
    }
    $type  = isset($_POST['type'])  ? sanitize_key((string) $_POST['type'])  : '';
    $value = isset($_POST['value']) ? (string) $_POST['value'] : '';

    // 'auto' resets to adaptive; a numeric value pins.
    if ($value === 'auto' || $value === '') {
        if ($type === 'all') {
            delete_option('wpc_v2_concurrency_manual_all');
            foreach (['batch', 'announce', 'single'] as $t) {
                delete_option('wpc_v2_concurrency_manual_' . $t);
            }
        } elseif (wpc_v2_ac_valid_type($type)) {
            delete_option('wpc_v2_concurrency_manual_' . $type);
        }
        wp_send_json_success(['mode' => 'auto', 'state' => wpc_v2_get_concurrency_state()]);
    }

    $n = (int) $value;
    $caps = wpc_v2_ac_effective_caps();
    if ($n < $caps['floor'] || $n > $caps['ceiling']) {
        wp_send_json_error(['error' => 'value_out_of_range', 'floor' => $caps['floor'], 'ceiling' => $caps['ceiling']]);
    }
    $opt = ($type === 'all') ? 'wpc_v2_concurrency_manual_all' : 'wpc_v2_concurrency_manual_' . $type;
    update_option($opt, $n, false);
    wp_send_json_success(['mode' => 'manual', 'pinned' => $n, 'state' => wpc_v2_get_concurrency_state()]);
});

/**
 * Helper: returns true if adaptive concurrency is enabled for this site.
 * Used by v2-client.php to decide whether to emit maxConcurrent in the envelope
 * (kill switch #2 per spec §9 — option flip omits field → orch sees no cap).
 */
function wpc_v2_adaptive_concurrency_enabled() {
    // Default ENABLED — this is the new normal. Customer can opt out via option.
    $enabled = get_option('wpc_v2_adaptive_concurrency_enabled', 1);
    return (bool) $enabled;
}

} // end if (!function_exists('wpc_v2_record_handler_timing'))
