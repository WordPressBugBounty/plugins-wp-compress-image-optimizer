<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_record_handler_timing')) {


if (!defined('WPC_V2_AC_INITIAL_CAP'))     define('WPC_V2_AC_INITIAL_CAP', 3);
if (!defined('WPC_V2_AC_FLOOR'))           define('WPC_V2_AC_FLOOR', 2);
if (!defined('WPC_V2_AC_CEILING'))         define('WPC_V2_AC_CEILING', 50);


if (!defined('WPC_V2_AC_INITIAL_CAP_TUNED')) define('WPC_V2_AC_INITIAL_CAP_TUNED', 8);
if (!defined('WPC_V2_AC_FLOOR_TUNED'))       define('WPC_V2_AC_FLOOR_TUNED', 2);
if (!defined('WPC_V2_AC_CEILING_TUNED'))     define('WPC_V2_AC_CEILING_TUNED', 12);






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





function wpc_v2_ac_valid_type($type) {
    return in_array($type, ['batch', 'announce', 'single'], true);
}

function wpc_v2_ac_opt_name($base, $type) {
    return 'wpc_v2_' . $base . '_' . $type;
}


function wpc_v2_ac_get_cap($type) {
    $caps = wpc_v2_ac_effective_caps();

    if (!wpc_v2_ac_valid_type($type)) {
        return $caps['initial'];
    }


    $override = apply_filters('wpc_v2_max_concurrent_override', null, $type);
    if ($override !== null) {
        return max($caps['floor'], min($caps['ceiling'], (int) $override));
    }

    
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





function wpc_v2_ac_percentile(array $values, $p) {
    if (empty($values)) return 0.0;
    sort($values);
    $idx = (int) floor(($p / 100.0) * (count($values) - 1));
    return (float) $values[$idx];
}


function wpc_v2_record_handler_timing($type, $ms) {
    if (!wpc_v2_ac_valid_type($type)) return;
    $ms = (float) $ms;
    if ($ms < 0 || $ms > 600000) return;

    $opt_timings = wpc_v2_ac_opt_name('handler_timings', $type);
    $timings = get_option($opt_timings, []);
    if (!is_array($timings)) $timings = [];

    
    $timings[] = (int) round($ms);
    if (count($timings) > WPC_V2_AC_WINDOW_SIZE) {
        $timings = array_slice($timings, -WPC_V2_AC_WINDOW_SIZE);
    }
    update_option($opt_timings, $timings, false);


    $opt_rec_n  = wpc_v2_ac_opt_name('rec_count', $type);
    $rec_count  = (int) get_option($opt_rec_n, 0) + 1;
    update_option($opt_rec_n, $rec_count, false);
    if ($rec_count % WPC_V2_AC_ADJUST_EVERY_N === 0) {
        wpc_v2_ac_maybe_adjust($type, $timings);
    }
}






function wpc_v2_ac_maybe_adjust($type, array $timings) {
    if (count($timings) < 10) return;

    $opt_last_adjust = wpc_v2_ac_opt_name('last_adjust_at', $type);
    $last_adjust_at = (int) get_option($opt_last_adjust, 0);
    if ($last_adjust_at > 0 && (time() - $last_adjust_at) < WPC_V2_AC_COOLDOWN_S) {
        return;
    }

    $baseline_p20 = wpc_v2_ac_percentile($timings, 20);
    $p95          = wpc_v2_ac_percentile($timings, 95);

    
    update_option(wpc_v2_ac_opt_name('baseline_ms', $type), $baseline_p20, false);

    $cap = wpc_v2_ac_get_cap($type);
    $old_cap = $cap;
    $reason = 'hold';
    $direction = 'hold';
    $caps = wpc_v2_ac_effective_caps();

    
    if ($baseline_p20 > 0 && $p95 > $baseline_p20 * WPC_V2_AC_SATURATED_MULT) {
        $new_cap = max($caps['floor'], (int) floor($cap * 0.5));
        if ($new_cap !== $cap) {
            $cap = $new_cap;
            $reason = 'saturated_3x';
            $direction = 'decrease';
        }
    } elseif ($baseline_p20 > 0 && $p95 < $baseline_p20 * WPC_V2_AC_RELAXED_MULT) {


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


function wpc_v2_get_max_concurrent() {
    $is_cli_or_cron = (defined('WP_CLI') && WP_CLI)
                   || (defined('DOING_CRON') && DOING_CRON);
    $mult = $is_cli_or_cron ? 2 : 1;
    $caps = wpc_v2_ac_effective_caps();

    return [
        'batch'    => min($caps['ceiling'], wpc_v2_ac_get_cap('batch') * $mult),
        'announce' => min($caps['ceiling'], wpc_v2_ac_get_cap('announce') * $mult),
        'single'   => wpc_v2_ac_get_cap('single'),
    ];
}


function wpc_v2_get_request_origin() {
    if ((defined('WP_CLI') && WP_CLI) || (defined('DOING_CRON') && DOING_CRON)) {
        return 'cli';
    }
    return 'web';
}







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





add_action('wp_ajax_wpc_v2_set_concurrency_manual', function () {
    if (!current_user_can('manage_wpc_settings')) {
        wp_send_json_error('forbidden');
    }
    $type  = isset($_POST['type'])  ? sanitize_key((string) $_POST['type'])  : '';
    $value = isset($_POST['value']) ? (string) $_POST['value'] : '';

    
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


function wpc_v2_adaptive_concurrency_enabled() {
    
    $enabled = get_option('wpc_v2_adaptive_concurrency_enabled', 1);
    return (bool) $enabled;
}

}
