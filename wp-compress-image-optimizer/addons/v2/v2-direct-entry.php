<?php
include_once __DIR__ . '/../cache/wpc-fs.php';
if (!defined('ABSPATH')) {
    exit;
}
require_once __DIR__ . '/v2-service.php';

if (!function_exists('wpc_v2_callback_url')) {

/**
 * Returns the active callback URL for the /optimize-v2 envelope. Caches the
 * result per-request so multiple v2-client calls in the same request don't
 * thrash get_option.
 */
function wpc_v2_callback_url($endpoint = 'bg_swap') {
    static $cache = [];
    if (isset($cache[$endpoint])) return $cache[$endpoint];

    $healthy = (bool) get_option('wpc_v2_direct_entry_healthy', false);
    if ($healthy) {
        $cache[$endpoint] = plugins_url('api/v2/' . $endpoint . '.php', WPC_CC_PLUGIN_FILE);
    } else {
        $cache[$endpoint] = rest_url('wpc/v2/' . $endpoint);
    }
    return $cache[$endpoint];
}


function wpc_v2_probe_direct_entry($force = false) {
    // Rate-limit re-probes to once per hour unless forced.
    if (!$force) {
        $last_at = (int) get_option('wpc_v2_direct_entry_probe_at', 0);
        if ($last_at > 0 && (time() - $last_at) < HOUR_IN_SECONDS) {
            return [
                'ok'     => (bool) get_option('wpc_v2_direct_entry_healthy', false),
                'reason' => 'cached',
                'detail' => 'last probe ' . human_time_diff($last_at) . ' ago',
            ];
        }
    }

    $token = wp_generate_password(32, false, false);
    set_transient('wpc_v2_probe_token', $token, MINUTE_IN_SECONDS);

    $url = plugins_url('api/v2/health.php', WPC_CC_PLUGIN_FILE);
    $r   = wp_remote_post($url, [
        'timeout'   => 5,
        'sslverify' => false,
        'blocking'  => true,
        'headers'   => ['X-WPC-Probe' => '1'],
        'body'      => ['probe_token' => $token],
    ]);

    // Always update probe-at so we don't re-probe on every page load.
    update_option('wpc_v2_direct_entry_probe_at', time(), false);

    if (is_wp_error($r)) {
        update_option('wpc_v2_direct_entry_healthy', 0, false);
        update_option('wpc_v2_direct_entry_last_error', 'wp_error: ' . $r->get_error_message(), false);
        return ['ok' => false, 'reason' => 'wp_error', 'detail' => $r->get_error_message()];
    }

    $code = (int) wp_remote_retrieve_response_code($r);
    $body = trim((string) wp_remote_retrieve_body($r));

    if ($code !== 200) {
        update_option('wpc_v2_direct_entry_healthy', 0, false);
        update_option('wpc_v2_direct_entry_last_error', 'http_' . $code, false);
        // Persistent 403 = host-level PHP-execution deny in the plugin dir
        // (nginx hardening — Cloudways template class; .htaccess is Apache-only
        // there). No amount of hourly re-probing changes server config: after 3
        // consecutive 403s, stretch re-probes to daily and surface an admin
        // notice carrying the exact nginx exception the host needs (Automa/680-
        // site fleet report: ~half their installs sat in this state silently).
        if ($code === 403) {
            $forbidden_streak = (int) get_option('wpc_v2_direct_entry_403s', 0) + 1;
            update_option('wpc_v2_direct_entry_403s', $forbidden_streak, false);
            if ($forbidden_streak >= 3) {
                update_option('wpc_v2_direct_entry_probe_at', time() + DAY_IN_SECONDS - HOUR_IN_SECONDS, false);
            }
        } else {
            delete_option('wpc_v2_direct_entry_403s');
        }
        return ['ok' => false, 'reason' => 'http_status', 'detail' => 'got ' . $code];
    }
    delete_option('wpc_v2_direct_entry_403s');

    if ($body !== $token) {
        // Host didn't run the PHP (returned source/text) or the round-trip
        // broke (transient lost, SHORTINIT crash). Either way: no direct entry.
        update_option('wpc_v2_direct_entry_healthy', 0, false);
        update_option('wpc_v2_direct_entry_last_error', 'token_mismatch', false);
        return ['ok' => false, 'reason' => 'token_mismatch', 'detail' => 'body=' . substr($body, 0, 100)];
    }

    // Optional journal-writable flag from probe response header
    $headers = wp_remote_retrieve_headers($r);
    $journal_ok = '1';
    if (is_object($headers) || is_array($headers)) {
        $h = is_object($headers) ? $headers->getAll() : $headers;
        if (isset($h['x-wpc-journal-writable'])) $journal_ok = (string) $h['x-wpc-journal-writable'];
    }
    update_option('wpc_v2_direct_entry_journal_ok', $journal_ok === '1' ? 1 : 0, false);

    // If direct entry works but uploads/journal isn't writable, we still fall
    // back to REST — batch handler needs to write bytes + journal entries.
    if ($journal_ok !== '1') {
        update_option('wpc_v2_direct_entry_healthy', 0, false);
        update_option('wpc_v2_direct_entry_last_error', 'journal_not_writable', false);
        return ['ok' => false, 'reason' => 'journal_not_writable', 'detail' => 'PHP works but uploads dir is locked'];
    }

    update_option('wpc_v2_direct_entry_healthy', 1, false);
    update_option('wpc_v2_direct_entry_last_error', '', false);
    return ['ok' => true, 'reason' => null, 'detail' => 'direct-entry healthy'];
}


function wpc_v2_door_probe_cron()
{
    if (!function_exists('wpc_encode_on_view_on') || !wpc_encode_on_view_on()) return false;
    $probed_at = get_option('wpc_v2_direct_entry_probe_at', null);
    $healthy = (bool) get_option('wpc_v2_direct_entry_healthy', false);
    $backoff = get_option('wpc_v2_direct_entry_backoff', null);
    if ($healthy && $backoff !== null) {
        delete_option('wpc_v2_direct_entry_backoff');
        $backoff = null;
    }
    $due = $probed_at === null
        || ($healthy && (time() - (int) $probed_at) > WEEK_IN_SECONDS)
        || (!$healthy && (!is_array($backoff) || time() >= (int) (isset($backoff['next']) ? $backoff['next'] : 0)));
    if ($due) {
        $res = wpc_v2_probe_direct_entry($probed_at === null);
        if (get_option('wpc_v2_direct_entry_healthy', false)) {
            delete_option('wpc_v2_direct_entry_backoff');
        } elseif (!is_array($res) || !isset($res['reason']) || $res['reason'] !== 'cached') {
            $misses = (is_array($backoff) && isset($backoff['n']) ? (int) $backoff['n'] : 0) + 1;
            $wait = min(DAY_IN_SECONDS, HOUR_IN_SECONDS * (1 << min($misses - 1, 5)));
            update_option('wpc_v2_direct_entry_backoff', ['n' => $misses, 'next' => time() + $wait], false);
        }
    }
    if (function_exists('wpc_sized_report_arm_settle')) {
        wpc_sized_report_arm_settle();
    }
    return false;
}

add_action('wpc_v2_pull_cron', 'wpc_v2_door_probe_cron', 22);

function wpc_v2_probe_after_update()
{
    wpc_v2_probe_direct_entry();
    if (function_exists('wpc_sized_report_arm_settle')) {
        wpc_sized_report_arm_settle();
    }
    return false;
}

// Plugin activation hook (fires once on activate)
register_activation_hook(WPC_CC_PLUGIN_FILE, function () {
    wpc_v2_probe_direct_entry(true);
});

// Re-probe when apikey is saved/rotated (option update hook)
add_action('update_option_wps_ic_options', function ($old_value, $new_value) {
    $old_key = is_array($old_value) ? ($old_value['api_key'] ?? '') : '';
    $new_key = is_array($new_value) ? ($new_value['api_key'] ?? '') : '';
    if ($old_key !== $new_key) {
        wpc_v2_probe_direct_entry(true);
    }
}, 10, 2);


add_action('admin_init', function () {
    // Probe is a blocking loopback — skip it on admin-ajax; it'll run on the
    // next regular page load instead.
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    if (get_option('wpc_v2_direct_entry_probe_at', null) === null) {
        wpc_v2_probe_direct_entry(true);
    }
}, 999);

// Manual re-detect AJAX (for admin "Re-detect" button)
add_action('wp_ajax_wpc_v2_redetect_direct_entry', function () {
    if (!current_user_can('manage_wpc_settings')) {
        wp_send_json_error('forbidden');
    }
    $res = wpc_v2_probe_direct_entry(true);
    wp_send_json_success($res);
});


add_action('wp_ajax_wpc_v2_journal_drain',        'wpc_v2_journal_drain_handler');
add_action('wp_ajax_nopriv_wpc_v2_journal_drain', 'wpc_v2_journal_drain_handler');


function wpc_v2_journal_drain_handler() {
    // Auth via HMAC. Canonical helper also reads 'wps_ic_settings' (where the
    // settings UI writes the api_key on fresh installs).
    $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
    if ($apikey === '') {
        wp_die('', '', ['response' => 200]);
    }
    $ts  = isset($_POST['t']) ? (int) $_POST['t'] : 0;
    $sig = isset($_POST['sig']) ? (string) $_POST['sig'] : '';
    if ($ts <= 0 || $sig === '' || abs(time() - $ts) > 120) {
        wp_die('', '', ['response' => 200]);
    }
    $expected = hash_hmac('sha256', 'wpc_v2_drain.' . $ts, $apikey);
    if (!hash_equals($expected, $sig)) {
        error_log('[wpc_v2_journal_drain] auth_rejected sig_mismatch');
        wp_die('', '', ['response' => 200]);
    }

    wpc_v2_journal_drain_run();
    wp_die('', '', ['response' => 200]);
}


function wpc_v2_journal_state_file() {
    $up = wp_get_upload_dir();
    if (empty($up['basedir'])) return '';
    $dir = rtrim($up['basedir'], '/\\') . '/wpci-journal';
    return is_dir($dir) ? $dir . '/.drain_state' : '';
}

function wpc_v2_journal_hold_file() {
    $f = wpc_v2_journal_state_file();
    return $f === '' ? '' : dirname($f) . '/.reap_hold';
}

function wpc_v2_journal_hold_on() {
    return (bool) apply_filters('wpc_v2_journal_hold', !(defined('WPC_V2_JOURNAL_HOLD_OFF') && WPC_V2_JOURNAL_HOLD_OFF));
}

function wpc_v2_journal_ttl_seconds() {
    return defined('WPC_V2_JOURNAL_FILE_TTL_S') ? max(60, (int) WPC_V2_JOURNAL_FILE_TTL_S) : 1800;
}

function wpc_v2_journal_hold_max_seconds() {
    $max = defined('WPC_V2_JOURNAL_HOLD_MAX_S') ? (int) WPC_V2_JOURNAL_HOLD_MAX_S : 86400;
    return max(wpc_v2_journal_ttl_seconds(), $max);
}

function wpc_v2_journal_hold_until() {
    $f = wpc_v2_journal_hold_file();
    return ($f !== '' && @is_file($f)) ? (int) trim((string) @file_get_contents($f)) : 0;
}

function wpc_v2_journal_hold_note($until) {
    $f = wpc_v2_journal_hold_file();
    if ($f === '') return;
    wpc_fs_put($f, (string) (max((int) $until, time()) + wpc_v2_journal_ttl_seconds()));
}

function wpc_v2_journal_backoff_until() {
    $f = wpc_v2_journal_state_file();
    if ($f === '' || !@is_file($f)) return 0;
    $s = json_decode((string) @file_get_contents($f), true);
    return is_array($s) ? (int) (isset($s['until']) ? $s['until'] : 0) : 0;
}

function wpc_v2_journal_note_progress($drained, $remaining) {
    $f = wpc_v2_journal_state_file();
    if ($f === '') return;
    if ($drained > 0 || $remaining <= 0) {
        @unlink($f);
        return;
    }
    $s = json_decode((string) @file_get_contents($f), true);
    $n = (is_array($s) ? (int) (isset($s['noprog']) ? $s['noprog'] : 0) : 0) + 1;
    $until = 0;
    if ($n >= 3) {
        $until = time() + (int) min(900 * pow(2, $n - 3), 7200); // 15m, 30m, 1h, 2h cap
        error_log(sprintf('[wpc_v2_journal_drain] no-progress x%d — backing off until %s (files=%d)', $n, gmdate('H:i:s', $until), $remaining));
    }
    wpc_fs_put($f, json_encode(['noprog' => $n, 'until' => $until]));
    wpc_v2_journal_hold_note($until);
}

function wpc_v2_journal_abandon_payload($file) {
    $payload = json_decode((string) @file_get_contents($file), true);
    if (!is_array($payload) || empty($payload['imageID']) || !isset($payload['entries']) || !is_array($payload['entries'])) return [];
    $inner = isset($payload['entries']['entries']) ? $payload['entries']['entries'] : $payload['entries'];
    if (!is_array($inner)) return [];
    $out = [];
    foreach ($inner as $e) {
        if (!is_array($e) || (isset($e['type']) ? (string) $e['type'] : '') !== 'persisted_pending_bytes') continue;
        if (empty($e['sizeLabel']) || empty($e['format']) || empty($e['filename']) || empty($e['dest_dir'])) continue;
        $dest = rtrim((string) $e['dest_dir'], '/\\') . '/' . basename((string) $e['filename']);
        if (@is_file($dest)) continue;
        $out[] = [
            'imageID' => (int) $payload['imageID'],
            'size'    => (string) $e['sizeLabel'],
            'format'  => (string) $e['format'],
            'dest'    => $dest,
            'lane'    => isset($e['source']) ? (string) $e['source'] : 'journal',
            'job'     => isset($payload['jobId']) ? (string) $payload['jobId'] : '',
            'sha256'  => isset($e['bytes_sha256']) ? (string) $e['bytes_sha256'] : '',
        ];
    }
    return $out;
}

function wpc_v2_journal_attachment_exists($imageID) {
    if (!function_exists('get_post')) {
        return true;
    }
    $post = get_post((int) $imageID);
    return is_object($post) && isset($post->post_type) && $post->post_type === 'attachment';
}

function wpc_v2_journal_abandon_receipts(array $items) {
    $failed = [];
    foreach ($items as $it) {
        if (function_exists('wpc_v2_landed_failure') && wpc_v2_journal_hold_on()) {
            wpc_v2_landed_failure($it['imageID'], $it['size'], $it['format'], $it['dest'], $it['lane'], $it['job'], 'pull_abandoned');
        }
        if ($it['sha256'] !== '' && function_exists('wpc_v2_ack_landed_only') && wpc_v2_ack_landed_only() && wpc_v2_journal_attachment_exists($it['imageID'])) {
            $failed[$it['sha256']] = [
                'imageID'   => (string) $it['imageID'],
                'sizeLabel' => $it['size'],
                'format'    => $it['format'],
                'reason'    => 'pull_abandoned',
                'sha256'    => $it['sha256'],
            ];
        }
    }
    return $failed;
}

function wpc_v2_journal_reap_stale(&$deferred_receipts = false) {
    $abandoned   = 0;
    $ttl_seconds = wpc_v2_journal_ttl_seconds();
    $hold_on     = wpc_v2_journal_hold_on();
    $held        = $hold_on && wpc_v2_journal_hold_until() > time();
    $hard_ttl    = wpc_v2_journal_hold_max_seconds();
    $cap         = wpc_v2_journal_max_files();
    $files       = wpc_v2_journal_list_files($cap + 1000);
    $over        = max(0, count($files) - $cap);
    if ($over > 0) {
        $mt = [];
        foreach ($files as $f) {
            $mt[$f] = (int) @filemtime($f);
        }
        usort($files, function ($x, $y) use ($mt) {
            return $mt[$x] === $mt[$y] ? strcmp($x, $y) : ($mt[$x] < $mt[$y] ? -1 : 1);
        });
    }
    $failed_receipts = [];
    foreach ($files as $i => $stale_check) {
        $force = $i < $over;
        $mtime = is_file($stale_check) ? filemtime($stale_check) : false;
        if ($mtime === false) {
            continue;
        }
        $age = time() - $mtime;
        if (!$force) {
            if ($age <= $ttl_seconds) {
                continue;
            }
            if ($held && $age <= $hard_ttl) {
                continue;
            }
        }
        clearstatcache(true, $stale_check);
        $items = wpc_v2_journal_abandon_payload($stale_check);
        if (!is_file($stale_check) || !unlink($stale_check)) {
            continue;
        }
        $abandoned++;
        foreach (wpc_v2_journal_abandon_receipts($items) as $sha => $row) {
            $failed_receipts[$sha] = $row;
        }
        error_log(sprintf(
            '[wpc_v2_journal_drain] abandoned stale file=%s age_s=%d (TTL=%d)%s',
            basename($stale_check), $age, $ttl_seconds, $force ? ' over_cap' : ''
        ));
    }
    if (func_num_args() > 0) {
        $deferred_receipts = array_values($failed_receipts);
    } else {
        wpc_v2_journal_send_abandon_acks(array_values($failed_receipts));
    }
    return $abandoned;
}

function wpc_v2_journal_send_abandon_acks(array $receipts) {
    if (empty($receipts) || !function_exists('wpc_v2_pull_manifest_ack')) {
        return;
    }
    foreach (array_chunk($receipts, 50) as $chunk) {
        wpc_v2_pull_manifest_ack([], ['seen' => count($chunk), 'ingested' => 0, 'failed' => $chunk]);
    }
}

function wpc_v2_journal_file_pulled(array $entries, array $pulled_bytes_by_url) {
    foreach ($entries as $e) {
        if (!is_array($e) || empty($e['sizeLabel']) || empty($e['format'])) continue;
        if (isset($e['source']) && $e['source'] === 'lazycdn') continue;
        if ((isset($e['type']) ? (string) $e['type'] : 'persisted') !== 'persisted_pending_bytes') continue;
        $url = isset($e['fetch_url']) ? (string) $e['fetch_url'] : '';
        if ($url === '' || !isset($pulled_bytes_by_url[$url]) || !is_string($pulled_bytes_by_url[$url]) || $pulled_bytes_by_url[$url] === '') {
            return false;
        }
    }
    return true;
}

/**
 * The actual drain loop. Extracted so the WP cron safety net can call it
 * directly (no HMAC needed when triggered by cron).
 */
function wpc_v2_journal_drain_run() {
    if (function_exists('wpc_landed_pages_drain')) {
        wpc_landed_pages_drain();
    }
    @ini_set('memory_limit', '256M');
    @ini_set('max_execution_time', '30');
    @ignore_user_abort(true);

    // v7.10.472 — the recurring-hook audit found this the ONLY high-frequency recurrence
    // (5 min) with no load/memory shed: every other one already has it. It is otherwise well
    // belted (restore yield, backoff, GET_LOCK(0), 8s wall budget, 200-file cap), so this is
    // the last belt missing.
    // BUT a shed that never lifts is a LEAK: the stale-file abandonment below runs INSIDE this
    // drain, so on a permanently loaded box (busy: load1 38-52 on 2 cores all day) skipping
    // forever means nothing ever reclaims the journal. Shed at most N consecutive times, then
    // force one drain through regardless — shedding must bound the work, never abandon it.
    if (function_exists('wpc_under_pressure') && wpc_under_pressure()
        && apply_filters('wpc_v2_journal_shed_on_pressure', true)) {
        $shed_count  = (int) get_option('wpc_v2_journal_shed_n', 0);
        $shed_max = max(1, (int) apply_filters('wpc_v2_journal_shed_max', 6)); // ~30 min at 5-min cadence
        if ($shed_count < $shed_max) {
            update_option('wpc_v2_journal_shed_n', $shed_count + 1, false);
            error_log('[wpc_v2_journal_drain] shed_pressure n=' . ($shed_count + 1) . '/' . $shed_max);
            return;
        }
        error_log('[wpc_v2_journal_drain] shed_ceiling_reached n=' . $shed_count . ' — forcing one drain so the journal cannot leak');
    }
    if ((int) get_option('wpc_v2_journal_shed_n', 0) !== 0) {
        update_option('wpc_v2_journal_shed_n', 0, false);
    }

    // Yield to a foreground bulk restore. The journal drain is the heaviest


    if (function_exists('wpc_v2_active_restore_count') && wpc_v2_active_restore_count() > 0) {
        error_log('[wpc_v2_journal_drain] yield_to_restore — deferring journal drain (bulk restore active)');
        return;
    }


    global $wpdb;
    $got = wpc_worker_lock('wpc_v2_journal_drain', 0) ? 1 : 0;
    if (!$got) {
        // Another drain chain is already running. Bail silently.
        return;
    }

    $abandon_receipts = [];
    try {
        $total_files_abandoned = wpc_v2_journal_reap_stale($abandon_receipts);
        if (wpc_v2_journal_backoff_until() > time()) {
            return;
        }
        $started = microtime(true);
        $wall_budget_s = 8.0;
        $total_files_drained = 0;
        $total_images = 0;
        $total_files_retained = 0;


        $attempted_this_run = [];
        $retry_names = array_flip(array_map('strval', (array) get_option('wpc_v2_journal_retry', [])));
        $retained_names = [];

        while ((microtime(true) - $started) < $wall_budget_s) {
            $files = wpc_v2_journal_list_files(50);
            if (!empty($attempted_this_run)) {
                $files = array_values(array_diff($files, $attempted_this_run));
            }
            if (empty($files)) break;
            if (!empty($retry_names)) {
                $fresh_files = [];
                $again_files = [];
                foreach ($files as $listed) {
                    if (isset($retry_names[basename($listed)])) {
                        $again_files[] = $listed;
                    } else {
                        $fresh_files[] = $listed;
                    }
                }
                $files = array_merge($fresh_files, $again_files);
            }

            // Group entries by imageID
            $by_image = [];
            foreach ($files as $file) {
                $raw = @file_get_contents($file);
                if ($raw === false) {
                    @unlink($file);
                    continue;
                }
                $payload = json_decode($raw, true);
                if (!is_array($payload) || empty($payload['imageID'])) {
                    @unlink($file);
                    continue;
                }
                $imageID = (int) $payload['imageID'];
                if (!isset($by_image[$imageID])) {
                    $by_image[$imageID] = [
                        'jobId'   => isset($payload['jobId']) ? (string) $payload['jobId'] : '',
                        'entries' => [],
                        'files'   => [],
                        'file_entries' => [],
                    ];
                }
                $by_image[$imageID]['file_entries'][$file] = [];
                // Each file's payload['entries'] is a wrapped structure:
                //   ['flush_reason' => ..., 'received_ms' => ..., 'entries' => [...actual entries...]]
                if (isset($payload['entries']) && is_array($payload['entries'])) {
                    $inner = isset($payload['entries']['entries']) ? $payload['entries']['entries'] : $payload['entries'];
                    if (is_array($inner)) {
                        foreach ($inner as $entry) {
                            $by_image[$imageID]['entries'][] = $entry;
                            $by_image[$imageID]['file_entries'][$file][] = $entry;
                        }
                    }
                }
                $by_image[$imageID]['files'][] = $file;
                $attempted_this_run[] = $file;
            }


            $pulled_bytes_by_url = [];
            $iter_pull_ms = 0;
            $iter_pulls_attempted = 0;
            $iter_pulls_succeeded = 0;
            if (function_exists('wpc_v2_parallel_pull')) {
                $pulls_by_url = [];
                $pull_meta_by_url = [];
                foreach ($by_image as $imageID => $group) {
                    foreach ($group['entries'] as $entry) {
                        if (!is_array($entry)) continue;
                        if (($entry['type'] ?? '') !== 'persisted_pending_bytes') continue;
                        $url = isset($entry['fetch_url']) ? (string) $entry['fetch_url'] : '';
                        if ($url === '' || isset($pulls_by_url[$url]) || !wpc_v2_pull_fetch_allowed($url)) continue;
                        $pulls_by_url[$url] = $url;
                        $pull_meta_by_url[$url] = [
                            'size'   => isset($entry['bytes_size'])   ? (int) $entry['bytes_size']   : null,
                            'sha256' => isset($entry['bytes_sha256']) ? (string) $entry['bytes_sha256'] : null,
                        ];
                    }
                }
                if (!empty($pulls_by_url)) {
                    $t_pull_start  = microtime(true);
                    $urls_indexed  = array_values($pulls_by_url);
                    $meta_indexed  = array_values($pull_meta_by_url);
                    $pulled        = wpc_v2_parallel_pull($urls_indexed, $meta_indexed, [
                        'overall_timeout_ms' => max(1000, (int) floor(($wall_budget_s - (microtime(true) - $started)) * 1000)),
                    ]);
                    foreach ($urls_indexed as $i => $u) {
                        if (isset($pulled[$i])) {
                            $pulled_bytes_by_url[$u] = $pulled[$i];
                        }
                    }
                    $iter_pull_ms         = (int) round((microtime(true) - $t_pull_start) * 1000);
                    $iter_pulls_attempted = count($urls_indexed);
                    $iter_pulls_succeeded = count($pulled_bytes_by_url);
                    error_log(sprintf(
                        '[wpc_v2_journal_drain_pull] pulled=%d/%d wall_ms=%d',
                        $iter_pulls_succeeded,
                        $iter_pulls_attempted,
                        $iter_pull_ms
                    ));
                }
            }

            foreach ($by_image as $imageID => $group) {


                $result = wpc_v2_journal_merge_for_image($imageID, $group['jobId'], $group['entries'], $pulled_bytes_by_url);


                if ($result['ok']) {
                    $kept = 0;
                    foreach ($group['files'] as $file) {
                        if (empty($result['any_pull_failed']) || wpc_v2_journal_file_pulled($group['file_entries'][$file], $pulled_bytes_by_url)) {
                            @unlink($file);
                            $total_files_drained++;
                        } else {
                            $kept++;
                            $retained_names[basename($file)] = 1;
                        }
                    }
                    if ($kept < count($group['files'])) {
                        $total_images++;
                    }
                    if ($kept > 0) {
                        $total_files_retained += $kept;
                        error_log(sprintf(
                            '[wpc_v2_journal_drain] retaining_for_retry imageID=%d files=%d',
                            $imageID, $kept
                        ));
                    }
                } else {
                    $total_files_retained += count($group['files']);
                    foreach ($group['files'] as $retained_file) {
                        @touch($retained_file);
                        $retained_names[basename($retained_file)] = 1;
                    }
                    error_log(sprintf(
                        '[wpc_v2_journal_drain] retaining_after_failure imageID=%d files=%d reason=%s',
                        $imageID, count($group['files']),
                        isset($result['reason']) ? (string) $result['reason'] : 'unknown'
                    ));
                }
            }
        }

        if (empty($retained_names)) {
            if (!empty($retry_names)) {
                delete_option('wpc_v2_journal_retry');
            }
        } else {
            update_option('wpc_v2_journal_retry', array_map('strval', array_keys($retained_names)), false);
        }

        error_log(sprintf(
            '[wpc_v2_journal_drain] iter_files_drained=%d images=%d retained=%d abandoned_ttl=%d wall_ms=%d',
            $total_files_drained,
            $total_images,
            $total_files_retained,
            $total_files_abandoned,
            (int) round((microtime(true) - $started) * 1000)
        ));
    } finally {
        wpc_worker_unlock('wpc_v2_journal_drain');
        wpc_v2_journal_send_abandon_acks($abandon_receipts);
    }

    if (function_exists('wpc_v2_landed_flush') && function_exists('wpc_v2_landed_detached') && wpc_v2_landed_detached()) {
        wpc_v2_landed_flush();
    }

    // Self-chain if files remain OR a pending-fire marker is set. The marker is
    // set when a batch handler hits the fire throttle and skips firing; without


    $pending_fire = (int) get_transient('wpc_v2_journal_pending_fire');
    $file_count   = wpc_v2_journal_count_files();


    wpc_v2_journal_note_progress((int) $total_files_drained, (int) $file_count);
    if (($file_count > 0 || $pending_fire > 0) && wpc_v2_journal_backoff_until() <= time()) {
        if ($pending_fire > 0) {
            delete_transient('wpc_v2_journal_pending_fire');
        }
        wpc_v2_journal_fire_loopback_from_wp();
    }
}

/**
 * Merge N journal entries for one image into ic_local_variants under a single
 * GET_LOCK + single update_post_meta. Then recompute_savings if appropriate.
 *
 * @return array ['ok' => bool, 'reason' => string|null, 'merged' => int]
 */
function wpc_v2_journal_merge_for_image($imageID, $jobId, array $entries, array $pulled_bytes_by_url = []) {
    if (empty($entries)) {
        return ['ok' => true, 'merged' => 0, 'reason' => 'no_entries'];
    }
    if (class_exists('wps_ic_image_variants') && wps_ic_image_variants::landing_refused((int) $imageID)) {
        wps_ic_image_variants::landing_refused_receipt((int) $imageID, 'journal');
        return ['ok' => true, 'merged' => 0, 'reason' => 'restored'];
    }
    $merged = 0;
    $merged_keys = [];
    $any_drain_complete_signal = false;
    $any_pull_failed = false;
    // The entries this journal lands; the variant-set owner merges them under its lock.
    $landed = [];
    $now = time();
    $now_ms = (int) round(microtime(true) * 1000);
    $t0_ms = (int) get_transient('wpc_v2_t0_ms_' . $imageID);

    foreach ($entries as $e) {
        if (!is_array($e) || empty($e['sizeLabel']) || empty($e['format'])) continue;


        if (isset($e['source']) && $e['source'] === 'lazycdn') {
            if (function_exists('wpc_v2_lazy_cdn_ingest')) {
                wpc_v2_lazy_cdn_ingest($e);
            }
            continue;
        }

        $sz  = (string) $e['sizeLabel'];
        $fmt = (string) $e['format'];


        $key = function_exists('wpc_v2_variant_key')
            ? wpc_v2_variant_key($sz, $fmt)
            : ($fmt === 'jpeg' || $fmt === 'jpg' ? $sz : $sz . '-' . $fmt);
        $type = isset($e['type']) ? (string) $e['type'] : 'persisted';

        if ($type === 'no_improvement') {
            $entry = [
                'bg_no_improvement'     => true,
                'no_improvement_reason' => isset($e['reason']) ? (string) $e['reason'] : 'no_improvement',
                'baseline_kb'           => isset($e['baselineKb']) ? (float) $e['baselineKb'] : 0.0,
                'phase_b_v2'            => true,
                'phase_b_direct_entry'  => true,
                'bg_upgraded'           => $now,
                'bg_upgraded_ms'        => $now_ms,
            ];
            $landed[$key] = array_merge($landed[$key] ?? [], $entry);
            $merged_keys[] = $key;
            $any_drain_complete_signal = true;
            continue;
        }
        if ($type === 'idempotent_noop') {
            // Already on disk; no meta change needed beyond touching bg_upgraded_ms
            // so heartbeat picks up the re-arrival.
            $landed[$key] = array_merge($landed[$key] ?? [], [
                'bg_upgraded'    => $now,
                'bg_upgraded_ms' => $now_ms,
            ]);
            $merged_keys[] = $key;
            continue;
        }


        if ($type === 'persisted_pending_bytes') {
            $url = isset($e['fetch_url']) ? (string) $e['fetch_url'] : '';
            $raw = ($url !== '' && isset($pulled_bytes_by_url[$url])) ? $pulled_bytes_by_url[$url] : null;
            if ($raw === null || !is_string($raw) || $raw === '') {
                error_log(sprintf(
                    '[wpc_v2_journal_merge] pull_missing imageID=%d sizeLabel=%s format=%s url_tail=%s (will retry)',
                    $imageID, $sz, $fmt, $url !== '' ? substr($url, -50) : '-'
                ));
                $any_pull_failed = true;
                continue;
            }
            $dest_dir = isset($e['dest_dir']) ? (string) $e['dest_dir'] : '';
            $filename = isset($e['filename']) ? (string) $e['filename'] : '';
            if ($dest_dir === '' || $filename === '') {
                error_log(sprintf('[wpc_v2_journal_merge] missing_dest_or_filename imageID=%d sizeLabel=%s format=%s', $imageID, $sz, $fmt));
                continue;
            }


            $filename = basename($filename);
            $j_segs   = explode('.', strtolower($filename));
            $j_last   = end($j_segs);
            $j_danger = ['php','php3','php4','php5','php6','php7','php8','phps','pht','phtml','phar','shtml','xhtml','html','htm','svg','svgz','js','mjs','jsp','asp','aspx','cgi','pl','py','sh','exe','dll','htaccess','ini','sql','phpt'];
            $j_unsafe = ($filename === '' || $filename[0] === '.' || strpos($filename, "\0") !== false
                || count($j_segs) < 2
                || !in_array($j_last, ['jpg','jpeg','png','gif','webp','avif'], true));
            if (!$j_unsafe) {
                foreach (array_slice($j_segs, 0, -1) as $j_seg) {
                    if (in_array($j_seg, $j_danger, true)) { $j_unsafe = true; break; }
                }
            }
            if ($j_unsafe) {
                error_log(sprintf('[wpc_v2_journal_merge] reject_unsafe_filename imageID=%d fn=%s', (int) $imageID, substr($filename, 0, 60)));
                continue;
            }
            $dest = $dest_dir . '/' . $filename;

            // Idempotent fast path: if same bytes already on disk, no write.
            $skip_write = false;
            if (file_exists($dest) && filesize($dest) === strlen($raw)
                && hash_file('sha256', $dest) === hash('sha256', $raw)) {
                $skip_write = true;
                if (function_exists('wpc_v2_landed_receipt')) {
                    wpc_v2_landed_receipt($imageID, $sz, $fmt, $dest, (string) ($e['source'] ?? 'journal'), (string) $jobId);
                }
            }
            if (!$skip_write) {
                $put = wpc_v2_store_bytes($raw, $dest, ['variant' => ['id' => $imageID, 'size' => $sz, 'fmt' => $fmt, 'src' => (string) ($e['source'] ?? 'journal'), 'job' => (string) $jobId, 'claim' => wpc_v2_variant_claim($e)]]);
                if (!empty($put['settled'])) {
                    // Settled by the store (no improvement, out of pending): nothing lands.
                    $any_drain_complete_signal = true;
                    continue;
                }
                if (empty($put['ok'])) {
                    // errno + bytes on failure so disk-full vs perms-denied vs missing-dir are distinguishable.
                    error_log(sprintf(
                        '[wpc_v2_journal_merge] %s imageID=%d sz=%s fmt=%s bytes=%d dest_tail=%s msg=%s',
                        (string) $put['error'], (int) $imageID, (string) $sz, (string) $fmt, strlen($raw),
                        substr($dest, -60), (string) $put['msg']
                    ));
                    continue;
                }
            }
            // Patch into an inline-bytes shape so the 'persisted' block below
            // handles it. bytes_size from actual bytes, not service-supplied.
            $e['bytes_path'] = $dest;
            $e['bytes_size'] = strlen($raw);

        }
        // Default: 'persisted'
        $orig_size = isset($e['originalSize']) ? (int) $e['originalSize'] : 0;
        $bytes_size = isset($e['bytes_size']) ? (int) $e['bytes_size'] : 0;
        $savings = ($orig_size > 0 && $bytes_size > 0)
            ? max(0, (int) round((1 - ($bytes_size / $orig_size)) * 100))
            : 0;
        $url = '';
        if (!empty($e['bytes_path'])) {
            $up = wp_get_upload_dir();
            $rel = ltrim(str_replace($up['basedir'], '', $e['bytes_path']), '/');
            $url = $up['baseurl'] . '/' . $rel;
        }


        $entry = [
            'size'                => $bytes_size,
            'originalSize'        => $orig_size,
            'url'                 => $url,
            'local'               => true,
            'skipped'             => false,
            'savings'             => $savings,
            'bg_upgraded'         => $now,
            'bg_upgraded_ms'      => $now_ms,
            'encoded_at_ms'       => isset($e['ms']) && $e['ms'] > 0 ? (int) $e['ms'] : 0,
            'bg_t_from_click_ms'  => ($t0_ms > 0 && isset($e['ms']) && $e['ms'] > $t0_ms) ? ((int) $e['ms'] - $t0_ms) : 0,
            'kb_reported'         => isset($e['kb']) ? (float) $e['kb'] : 0.0,
            'butter'              => isset($e['butter']) ? (float) $e['butter'] : 0.0,
            'phase_b_v2'          => true,
            'phase_b_direct_entry' => true,
        ];
        // Persist bytes_sha256 so pull-manifest can dedupe pre-flight (skip
        // variants already on disk via push).
        if (!empty($e['bytes_sha256'])) {
            $entry['bytes_sha256'] = (string) $e['bytes_sha256'];
        }

        if (!empty($e['delivery_method'])) {
            $entry['delivery_method'] = (string) $e['delivery_method'];
        }
        if (!empty($e['source'])) {
            $entry['journal_source'] = (string) $e['source'];
        }
        if (isset($e['q']))      $entry['q']      = (int) $e['q'];
        if (isset($e['bumped'])) $entry['bumped'] = (string) $e['bumped'];
        $landed[$key] = array_merge($landed[$key] ?? [], $entry);
        $merged_keys[] = $key;
        $merged++;
        $any_drain_complete_signal = true;


        // Heartbeat on each merged variant so the chip animation ticks up
        // in real time during pull-driven landings.
        $chip_fmt  = strtoupper((string) $fmt);
        $chip_size = ucfirst(str_replace(['_', '-'], ' ', (string) $sz));
        $compressing = get_post_meta($imageID, 'ic_compressing', true);
        $current_status = (is_array($compressing) && !empty($compressing['status']))
            ? (string) $compressing['status'] : 'optimizing';


        $eager = function_exists('wpc_v2_use_eager_compressed_flip')
            && wpc_v2_use_eager_compressed_flip();
        if ($eager && $current_status !== 'compressed') {
            wpc_v2_ic_compressing_set_status($imageID, 'compressed');
            delete_transient('wps_ic_compress_' . $imageID);
            $current_status = 'compressed';
        }

        set_transient('wps_ic_heartbeat_' . $imageID, [
            'imageID'         => $imageID,
            'status'          => $current_status,
            'event'           => 'bg_variant_arrived',
            'time'            => time(),
            'bg_variant_fmt'  => $chip_fmt,
            'bg_variant_size' => $chip_size,
        ], 300);

        // Remove from wpc_v2_pending_$id (mirrors REST handler's behavior)
        if (function_exists('wpc_v2_remove_pending')) {
            $drain_complete = wpc_v2_remove_pending($imageID, $sz, $fmt);
            if ($drain_complete) {
                $any_drain_complete_signal = true;


                if ($current_status !== 'compressed') {
                    wpc_v2_ic_compressing_set_status($imageID, 'compressed');
                    delete_transient('wps_ic_compress_' . $imageID);
                }


                wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
                $now_ms = (int) (microtime(true) * 1000);
                $extend_to = $now_ms + 10000;
                $current_deadline = (int) get_option('wpc_v2_drain_alive_until_ms', 0);
                if ($extend_to > $current_deadline) {
                    update_option('wpc_v2_drain_alive_until_ms', $extend_to, false);
                }

                // Log which specific variants are missing, by diffing on-disk
                // keys against the full expected size×format matrix.
                $img_variants = get_post_meta($imageID, 'ic_local_variants', true);
                if (is_array($img_variants)) {
                    $cnt_j = 0; $cnt_w = 0; $cnt_a = 0;
                    foreach ($img_variants as $vk => $ve) {
                        if (!is_array($ve)) continue;
                        if (!empty($ve['bg_no_improvement'])) continue;
                        if (empty($ve['size'])) continue;
                        if (strpos((string) $vk, '-avif') !== false)      $cnt_a++;
                        elseif (strpos((string) $vk, '-webp') !== false)  $cnt_w++;
                        else                                              $cnt_j++;
                    }
                    $total = $cnt_j + $cnt_w + $cnt_a;
                    $expected_sizes = ['thumbnail','medium','medium_large','large','1536x1536','2048x2048','scaled','original'];
                    $missing_keys = [];
                    foreach ($expected_sizes as $sz_label) {
                        foreach (['jpeg', 'webp', 'avif'] as $fmt_label) {
                            $expected_key = function_exists('wpc_v2_variant_key')
                                ? wpc_v2_variant_key($sz_label, $fmt_label)
                                : ($fmt_label === 'jpeg' ? $sz_label : $sz_label . '-' . $fmt_label);
                            if (!isset($img_variants[$expected_key])
                                || !is_array($img_variants[$expected_key])
                                || empty($img_variants[$expected_key]['size'])) {
                                $missing_keys[] = $expected_key;
                            }
                        }
                    }
                    if ($total < 22) {
                        error_log(sprintf(
                            '[WPC DrainComplete] imageID=%d INCOMPLETE total=%d J=%d W=%d A=%d missing=[%s]',
                            $imageID, $total, $cnt_j, $cnt_w, $cnt_a, implode(', ', $missing_keys)
                        ));
                    } elseif (!empty($missing_keys)) {
                        error_log(sprintf(
                            '[WPC DrainComplete] imageID=%d near_complete total=%d J=%d W=%d A=%d missing=[%s]',
                            $imageID, $total, $cnt_j, $cnt_w, $cnt_a, implode(', ', $missing_keys)
                        ));
                    } else {
                        error_log(sprintf(
                            '[WPC DrainComplete] imageID=%d ok total=%d J=%d W=%d A=%d',
                            $imageID, $total, $cnt_j, $cnt_w, $cnt_a
                        ));
                    }


                    if (!empty($missing_keys) && function_exists('wpc_v2_fire_image_bg_retry')) {
                        $dc_retry_guard = 'wpc_v2_bg_retry_fired_' . $imageID;
                        if (!get_transient($dc_retry_guard)) {
                            set_transient($dc_retry_guard, 1, 60);
                            error_log(sprintf(
                                '[WPC DrainComplete] imageID=%d firing server-side BGRetry — %d missing',
                                $imageID, count($missing_keys)
                            ));
                            wpc_v2_fire_image_bg_retry($imageID);
                        }
                    }
                }
            }
        }
    }
    // The owner promotes the image (any landed variant counts on this lane).
    wps_ic_image_variants::record($imageID, $landed, 'direct-entry', ['any_variant' => true]);

    if (!empty($merged_keys)) {
        wp_cache_delete($imageID, 'post_meta');
        $stored_variants = get_post_meta($imageID, 'ic_local_variants', true);
        $markers_verified = is_array($stored_variants);
        if ($markers_verified) {
            foreach ($merged_keys as $merged_key) {
                if (!isset($stored_variants[$merged_key])) { $markers_verified = false; break; }
            }
        }
        if (!$markers_verified) {
            if (function_exists('wpc_v2_note_store_broken')) {
                wpc_v2_note_store_broken(true);
            }
            error_log(sprintf(
                '[wpc_v2_journal_merge] marker_verify_failed imageID=%d keys=%d — journal retained for retry',
                (int) $imageID, count($merged_keys)
            ));
            return ['ok' => false, 'merged' => 0, 'reason' => 'marker_verify_failed', 'any_pull_failed' => $any_pull_failed];
        }
        if (function_exists('wpc_v2_note_store_broken')) {
            wpc_v2_note_store_broken(false);
        }
        if (function_exists('wpc_v2_reset_attempts')) {
            wpc_v2_reset_attempts($imageID);
        }
    }


    if ($any_drain_complete_signal) {
        $imageID_for_shutdown = (int) $imageID;
        add_action('shutdown', function () use ($imageID_for_shutdown) {
            if (function_exists('wpc_finish_request')) { wpc_finish_request(); } elseif (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
            // Invalidate HTML cache for pages referencing this attachment so the
            // next render emits natural URLs, not stale CDN transform URLs.
            if (function_exists('wpc_v2_purge_html_for_attachment')) {
                wpc_v2_purge_html_for_attachment($imageID_for_shutdown, 'direct-entry');
            }
        }, 0);
    }

    return ['ok' => true, 'merged' => $merged, 'reason' => null, 'any_pull_failed' => $any_pull_failed];
}

/**
 * List up to $limit .jsonl files in the journal dir, oldest first.
 */
function wpc_v2_journal_list_files($limit = 50) {
    $up = wp_get_upload_dir();
    if (empty($up['basedir'])) return [];
    $dir = rtrim($up['basedir'], '/\\') . '/wpci-journal';
    if (!is_dir($dir)) return [];
    $files = [];
    $dh = @opendir($dir);
    if (!$dh) return [];
    while (($f = readdir($dh)) !== false) {
        if (substr($f, -6) !== '.jsonl') continue;
        $files[] = $dir . '/' . $f;
        if (count($files) >= $limit) break;
    }
    closedir($dh);
    sort($files);
    return $files;
}

/**
 * Fire loopback drain from within WP context (used by self-chain + cron).
 * Direct-entry handlers use their own wpc_v2_journal_fire_loopback() since
 * they may not have wp_remote_post available in SHORTINIT.
 */
function wpc_v2_journal_fire_loopback_from_wp() {
    // Canonical helper (reads 'wps_ic_settings', falls back to 'wps_ic_options').
    $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
    if ($apikey === '') return;
    $ts = time();
    $sig = hash_hmac('sha256', 'wpc_v2_drain.' . $ts, $apikey);


    $jw_parts = wp_parse_url(admin_url('admin-ajax.php'));
    if (!empty($jw_parts['host'])) {
        $jw_https = (!empty($jw_parts['scheme']) && $jw_parts['scheme'] === 'https');
        $jw_port  = !empty($jw_parts['port']) ? (int) $jw_parts['port'] : ($jw_https ? 443 : 80);
        $jw_host  = (string) $jw_parts['host'];
        $jw_path  = (!empty($jw_parts['path']) ? $jw_parts['path'] : '/') . '?action=wpc_v2_journal_drain';
        $jw_body  = http_build_query(['t' => $ts, 'sig' => $sig]);
        $jw_req   = "POST {$jw_path} HTTP/1.1\r\nHost: {$jw_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                  . "Content-Length: " . strlen($jw_body) . "\r\nConnection: close\r\nUser-Agent: WPCJournalDrain/1.0\r\n\r\n" . $jw_body;
        $jw_fp = false;
        if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) {
            $jw_fp = wps_ic_ajax::wpc_loopback_open_socket($jw_host, $jw_port, $jw_https, 0.2);
        } else {
            $jw_ctx = $jw_https ? stream_context_create(['ssl' => ['peer_name' => $jw_host, 'SNI_enabled' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]) : null;
            foreach (['127.0.0.1', 'localhost', $jw_host] as $jw_chost) {
                $jw_errno = 0; $jw_errstr = '';
                $jw_remote = ($jw_https ? 'tls://' : 'tcp://') . $jw_chost . ':' . $jw_port;
                $jw_sock   = $jw_ctx
                    ? @stream_socket_client($jw_remote, $jw_errno, $jw_errstr, 0.2, STREAM_CLIENT_CONNECT, $jw_ctx)
                    : @stream_socket_client($jw_remote, $jw_errno, $jw_errstr, 0.2);
                if ($jw_sock) { $jw_fp = $jw_sock; break; }
            }
        }
        if ($jw_fp) { @stream_set_timeout($jw_fp, 0, 100000); @fwrite($jw_fp, $jw_req); @fclose($jw_fp); }
    }
}

// ─── WP cron safety net (every 5 min) ────────────────────────────────────

add_action('wpc_v2_journal_drain_cron', 'wpc_v2_journal_drain_run');

add_filter('cron_schedules', function ($schedules) {
    if (!isset($schedules['wpc_v2_5min'])) {
        $schedules['wpc_v2_5min'] = [
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => 'Every 5 minutes (WPC v2 journal drain safety net)',
        ];
    }
    return $schedules;
});

add_action('init', function () {


    $direct_entry_ok = (bool) get_option('wpc_v2_direct_entry_healthy', false);
    $scheduled = wp_next_scheduled('wpc_v2_journal_drain_cron');
    if ($direct_entry_ok && !$scheduled) {
        wp_schedule_event(time() + MINUTE_IN_SECONDS, 'wpc_v2_5min', 'wpc_v2_journal_drain_cron');
    } elseif (!$direct_entry_ok && $scheduled) {
        wp_unschedule_event($scheduled, 'wpc_v2_journal_drain_cron');
    }
}, 100);

// Cleanup on plugin deactivation
register_deactivation_hook(WPC_CC_PLUGIN_FILE, function () {
    $ts = wp_next_scheduled('wpc_v2_journal_drain_cron');
    if ($ts) wp_unschedule_event($ts, 'wpc_v2_journal_drain_cron');
});

}
