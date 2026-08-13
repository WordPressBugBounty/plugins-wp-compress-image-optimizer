<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WPC Job Rail — single queue, single consumer, off-pool where the host allows.
 * All background work enters through wpc_rail_enqueue(); exactly one consumer per
 * site executes time-boxed slices. Flag-gated: dormant unless wpc_rail_enabled=1.
 */

if (!function_exists('wpc_rail_on')) {
    function wpc_rail_on()
    {
        return get_option('wpc_rail_enabled') === '1' && apply_filters('wpc_rail_enabled', true);
    }

    function wpc_rail_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'wpc_jobs';
    }

    function wpc_rail_install()
    {
        global $wpdb;
        if (get_option('wpc_rail_schema_v') === '1') {
            return;
        }
        $t = wpc_rail_table();
        $charset = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta("CREATE TABLE {$t} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            hook VARCHAR(64) NOT NULL,
            args LONGTEXT NULL,
            dedupe VARCHAR(40) NULL,
            priority TINYINT NOT NULL DEFAULT 50,
            available_at INT UNSIGNED NOT NULL DEFAULT 0,
            claimed_at INT UNSIGNED NOT NULL DEFAULT 0,
            attempts TINYINT NOT NULL DEFAULT 0,
            max_attempts TINYINT NOT NULL DEFAULT 3,
            created_at INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY dedupe (dedupe),
            KEY claimable (claimed_at, available_at, priority)
        ) {$charset};");
        update_option('wpc_rail_schema_v', '1', false);
    }

    // Priorities: lower = sooner. Crit beats warm beats fonts beats housekeeping.
    function wpc_rail_priority($hook)
    {
        $map = ['wpc_lcp_repull' => 10, 'wpc_land_finalize' => 15, 'wpc_url_warm' => 30,
                'wpc_land_second_wave' => 35, 'wpc_combine_fonts_fetch' => 40, 'wpc_autopurge_check' => 45];
        return isset($map[$hook]) ? $map[$hook] : 50;
    }

    function wpc_rail_enqueue($hook, $args = [], $opts = [])
    {
        global $wpdb;
        if (!wpc_rail_on()) {
            return false;
        }
        $t = wpc_rail_table();
        $dedupe = !empty($opts['no_dedupe']) ? null : substr(md5($hook . '|' . wp_json_encode($args)), 0, 40);
        $row = [
            'hook'         => substr((string) $hook, 0, 64),
            'args'         => wp_json_encode(array_values((array) $args)),
            'dedupe'       => $dedupe,
            'priority'     => isset($opts['priority']) ? (int) $opts['priority'] : wpc_rail_priority($hook),
            'available_at' => isset($opts['at']) ? (int) $opts['at'] : time(),
            'created_at'   => time(),
            'max_attempts' => isset($opts['max_attempts']) ? (int) $opts['max_attempts'] : 3,
        ];
        // INSERT IGNORE: the dedupe unique key coalesces duplicate pending work for free
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$t} (hook, args, dedupe, priority, available_at, created_at, max_attempts)
             VALUES (%s, %s, %s, %d, %d, %d, %d)",
            $row['hook'], $row['args'], $row['dedupe'], $row['priority'], $row['available_at'], $row['created_at'], $row['max_attempts']
        ));
        wpc_rail_nudge();
        return true;
    }

    function wpc_rail_depth()
    {
        global $wpdb;
        $t = wpc_rail_table();
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$t}");
    }

    /**
     * Claim exactly one due job. UPDATE-with-ORDER-BY is atomic per row in MySQL, so
     * concurrent consumers cannot double-claim; claims older than 120s are dead and
     * reclaimable (a killed worker never wedges the queue).
     */
    function wpc_rail_claim($claim_id)
    {
        global $wpdb;
        $t = wpc_rail_table();
        $now = time();
        $wpdb->query($wpdb->prepare(
            "UPDATE {$t} SET claimed_at = %d, dedupe = CONCAT('c:', %s)
             WHERE (claimed_at = 0 OR claimed_at < %d) AND available_at <= %d
             ORDER BY priority ASC, id ASC LIMIT 1",
            $now, $claim_id, $now - 120, $now
        ));
        if ($wpdb->rows_affected < 1) {
            return null;
        }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE dedupe = %s", 'c:' . $claim_id), ARRAY_A);
    }

    function wpc_rail_complete($id)
    {
        global $wpdb;
        $wpdb->delete(wpc_rail_table(), ['id' => (int) $id]);
    }

    function wpc_rail_fail($job)
    {
        global $wpdb;
        $t = wpc_rail_table();
        $attempts = (int) $job['attempts'] + 1;
        if ($attempts >= (int) $job['max_attempts']) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('rail-dead', '', '', ['hook' => $job['hook'], 'attempts' => $attempts]);
            }
            $wpdb->delete($t, ['id' => (int) $job['id']]);
            return;
        }
        // Release + widening backoff; restore a dedupe key so future duplicates coalesce
        $wpdb->update($t, [
            'attempts'     => $attempts,
            'claimed_at'   => 0,
            'available_at' => time() + min(3600, 60 * $attempts * $attempts),
            'dedupe'       => substr(md5($job['hook'] . '|' . $job['args'] . '|r'), 0, 40),
        ], ['id' => (int) $job['id']]);
    }

    /**
     * One consumer slice: pressure-gated, time-boxed, core-aware. This is the ONLY
     * place rail work executes — concurrency-of-one by construction.
     */
    function wpc_rail_consume()
    {
        if (!wpc_rail_on()) {
            return 0;
        }
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            return 0;
        }
        // v7.10.473 — Safe Mode stops DRAINING as well as scheduling. Gating admission alone
        // leaves whatever is already queued to run, which is precisely the half-measure the warm
        // pause documents ("Disabling the scheduler alone leaves the queue to drain"). Jobs stay
        // in the table and resume on &safe=off — stood down, not discarded.
        if (function_exists('wpc_safe_mode') && wpc_safe_mode()) {
            return 0;
        }
        // Single consumer: same-second option-write mutex (parallel nudges collapse)
        $lk = (int) get_option('wpc_rail_consuming');
        if (time() - $lk < 30) {
            return 0;
        }
        if (!update_option('wpc_rail_consuming', time(), false)) {
            return 0;
        }
        $budget = 5.0;
        $max = (function_exists('wpc_box_cores') && wpc_box_cores() <= 2) ? 1 : 3;
        $t0 = microtime(true);
        $done = 0;
        $claim = substr(md5(uniqid('', true)), 0, 20);
        while ($done < $max && (microtime(true) - $t0) < $budget) {
            $job = wpc_rail_claim($claim . $done);
            if (!$job) {
                break;
            }
            try {
                $args = json_decode((string) $job['args'], true);
                if (!is_array($args)) {
                    $args = [];
                }
                do_action_ref_array($job['hook'], $args);
                wpc_rail_complete($job['id']);
            } catch (\Throwable $e) {
                wpc_rail_fail($job);
            }
            $done++;
        }
        delete_option('wpc_rail_consuming');
        if ($done > 0 && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('rail-slice', '', '', ['jobs' => $done, 'ms' => (int) round((microtime(true) - $t0) * 1000), 'engine' => (string) get_option('wpc_rail_engine', 'loopback')]);
        }
        // More work pending → keep the chain alive via the engine
        if ($done >= $max && wpc_rail_depth() > 0) {
            wpc_rail_nudge(true);
        }
        return $done;
    }

    /**
     * Engine detection (deferred, once per version): CLI beats loopback. System cron is
     * OBSERVED, not configured — if runner pings arrive, the engine upgrades itself.
     */
    function wpc_rail_detect_engine()
    {
        $engine = 'loopback';
        if (function_exists('exec')) {
            $disabled = array_map('trim', explode(',', (string) ini_get('disable_functions')));
            if (!in_array('exec', $disabled, true)) {
                $out = [];
                @exec('command -v wp 2>/dev/null', $out);
                $wp_bin = !empty($out[0]) ? trim($out[0]) : '';
                if ($wp_bin !== '') {
                    $probe = [];
                    @exec(escapeshellarg($wp_bin) . ' --version 2>/dev/null', $probe);
                    if (!empty($probe[0]) && stripos($probe[0], 'WP-CLI') !== false) {
                        $engine = 'cli';
                        update_option('wpc_rail_wp_bin', $wp_bin, false);
                    }
                }
            }
        }
        update_option('wpc_rail_engine', $engine, false);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('rail-engine', '', '', ['engine' => $engine]);
        }
        return $engine;
    }

    /** Fire a consumer via the best engine — detached, never blocking the caller. */
    function wpc_rail_nudge($chain = false)
    {
        static $nudged = false;
        if ($nudged && !$chain) {
            return;
        }
        $nudged = true;
        $engine = (string) get_option('wpc_rail_engine', 'loopback');
        if ($engine === 'cli') {
            $wp_bin = (string) get_option('wpc_rail_wp_bin');
            if ($wp_bin !== '' && function_exists('exec')) {
                // A real OS process OUTSIDE the FPM pool — visitor workers untouched
                @exec(escapeshellarg($wp_bin) . ' --path=' . escapeshellarg(ABSPATH)
                    . ' eval ' . escapeshellarg('function_exists("wpc_rail_consume") && wpc_rail_consume();')
                    . ' > /dev/null 2>&1 &');
                return;
            }
        }
        // Loopback fallback: fire-and-forget socket (house pattern), consumer runs in
        // a fresh worker with the 30s mutex preventing pileups
        if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) {
            $p = wp_parse_url(admin_url('admin-ajax.php'));
            if (!empty($p['host'])) {
                $https = (!empty($p['scheme']) && $p['scheme'] === 'https');
                $port = !empty($p['port']) ? (int) $p['port'] : ($https ? 443 : 80);
                $path = (!empty($p['path']) ? $p['path'] : '/') . '?action=wpc_rail_run&k=' . substr(md5('wpc-rail:' . (string) get_option('wpc_rail_key')), 0, 20);
                $req = "GET {$path} HTTP/1.1\r\nHost: {$p['host']}\r\nConnection: close\r\nUser-Agent: WPCRail/1.0\r\n\r\n";
                $fp = wps_ic_ajax::wpc_loopback_open_socket($p['host'], $port, $https, 0.2);
                if ($fp) {
                    @stream_set_timeout($fp, 0, 100000);
                    @fwrite($fp, $req);
                    @fclose($fp);
                    return;
                }
            }
        }
        // Last resort: single-event backstop (ONE cron entry, ever)
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_rail_tick')) {
            wp_schedule_single_event(time() + 30, 'wpc_rail_tick');
        }
    }

    // Consumer entry points: keyed nopriv ajax (loopback/system-cron runner) + cron backstop
    add_action('wp_ajax_wpc_rail_run', 'wpc_rail_run_endpoint');
    add_action('wp_ajax_nopriv_wpc_rail_run', 'wpc_rail_run_endpoint');
    function wpc_rail_run_endpoint()
    {
        $k = isset($_GET['k']) ? (string) $_GET['k'] : '';
        if (!hash_equals(substr(md5('wpc-rail:' . (string) get_option('wpc_rail_key')), 0, 20), $k)) {
            wp_die('', '', ['response' => 403]);
        }
        // System-cron observation: external runner pings upgrade the engine label
        if (!empty($_SERVER['HTTP_X_WPC_SYSCRON'])) {
            update_option('wpc_rail_engine', 'syscron', false);
        }
        ignore_user_abort(true);
        if (!headers_sent()) {
            http_response_code(200);
        }
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        wpc_rail_consume();
        wp_die('', '', ['response' => 200]);
    }
    add_action('wpc_rail_tick', 'wpc_rail_consume');

    // Backstop while queue non-empty: piggyback tick (same trick wp-cron uses), bounded
    add_action('shutdown', function () {
        if (!wpc_rail_on() || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) {
            return;
        }
        $la = (int) get_option('wpc_rail_lastseen');
        if (time() - $la < 60) {
            return;
        }
        update_option('wpc_rail_lastseen', time(), false);
        if (wpc_rail_depth() > 0) {
            wpc_rail_nudge(true);
        }
    }, 99);

    // Install + engine detection ride the deferred post-update lane, never a request
    add_action('wpc_v2_postupdate_purge', function () {
        try {
            if (get_option('wpc_rail_key') === false) {
                update_option('wpc_rail_key', substr(md5(uniqid('', true)), 0, 16), false);
            }
            wpc_rail_install();
            wpc_rail_detect_engine();
        } catch (\Throwable $e) {
        }
    }, 5);
}
