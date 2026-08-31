<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-direct-entry.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_callback_url')) {






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
        
        
        
        
        
        
        if ($code === 403) {
            $wpc_de403 = (int) get_option('wpc_v2_direct_entry_403s', 0) + 1;
            update_option('wpc_v2_direct_entry_403s', $wpc_de403, false);
            if ($wpc_de403 >= 3) {
                update_option('wpc_v2_direct_entry_probe_at', time() + DAY_IN_SECONDS - HOUR_IN_SECONDS, false);
            }
        } else {
            delete_option('wpc_v2_direct_entry_403s');
        }
        return ['ok' => false, 'reason' => 'http_status', 'detail' => 'got ' . $code];
    }
    delete_option('wpc_v2_direct_entry_403s');

    if ($body !== $token) {
        
        
        update_option('wpc_v2_direct_entry_healthy', 0, false);
        update_option('wpc_v2_direct_entry_last_error', 'token_mismatch', false);
        return ['ok' => false, 'reason' => 'token_mismatch', 'detail' => 'body=' . substr($body, 0, 100)];
    }

    
    $headers = wp_remote_retrieve_headers($r);
    $journal_ok = '1';
    if (is_object($headers) || is_array($headers)) {
        $h = is_object($headers) ? $headers->getAll() : $headers;
        if (isset($h['x-wpc-journal-writable'])) $journal_ok = (string) $h['x-wpc-journal-writable'];
    }
    update_option('wpc_v2_direct_entry_journal_ok', $journal_ok === '1' ? 1 : 0, false);

    
    
    if ($journal_ok !== '1') {
        update_option('wpc_v2_direct_entry_healthy', 0, false);
        update_option('wpc_v2_direct_entry_last_error', 'journal_not_writable', false);
        return ['ok' => false, 'reason' => 'journal_not_writable', 'detail' => 'PHP works but uploads dir is locked'];
    }

    update_option('wpc_v2_direct_entry_healthy', 1, false);
    update_option('wpc_v2_direct_entry_last_error', '', false);
    return ['ok' => true, 'reason' => null, 'detail' => 'direct-entry healthy'];
}



register_activation_hook(WPC_CC_PLUGIN_FILE, function () {
    wpc_v2_probe_direct_entry(true);
});


add_action('update_option_wps_ic_options', function ($old_value, $new_value) {
    $old_key = is_array($old_value) ? ($old_value['api_key'] ?? '') : '';
    $new_key = is_array($new_value) ? ($new_value['api_key'] ?? '') : '';
    if ($old_key !== $new_key) {
        wpc_v2_probe_direct_entry(true);
    }
}, 10, 2);


add_action('admin_init', function () {
    
    
    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    if (get_option('wpc_v2_direct_entry_probe_at', null) === null) {
        wpc_v2_probe_direct_entry(true);
    }
}, 999);


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
        $until = time() + (int) min(900 * pow(2, $n - 3), 7200); 
        error_log(sprintf('[wpc_v2_journal_drain] no-progress x%d — backing off until %s (files=%d)', $n, gmdate('H:i:s', $until), $remaining));
    }
    @file_put_contents($f, json_encode(['noprog' => $n, 'until' => $until]));
}





function wpc_v2_journal_drain_run() {
    @ini_set('memory_limit', '256M');
    @ini_set('max_execution_time', '30');
    @ignore_user_abort(true);

    
    
    
    
    
    
    
    
    if (function_exists('wpc_under_pressure') && wpc_under_pressure()
        && apply_filters('wpc_v2_journal_shed_on_pressure', true)) {
        $wpc_sn472  = (int) get_option('wpc_v2_journal_shed_n', 0);
        $wpc_smx472 = max(1, (int) apply_filters('wpc_v2_journal_shed_max', 6)); 
        if ($wpc_sn472 < $wpc_smx472) {
            update_option('wpc_v2_journal_shed_n', $wpc_sn472 + 1, false);
            error_log('[wpc_v2_journal_drain] shed_pressure n=' . ($wpc_sn472 + 1) . '/' . $wpc_smx472);
            return;
        }
        error_log('[wpc_v2_journal_drain] shed_ceiling_reached n=' . $wpc_sn472 . ' — forcing one drain so the journal cannot leak');
    }
    if ((int) get_option('wpc_v2_journal_shed_n', 0) !== 0) {
        update_option('wpc_v2_journal_shed_n', 0, false);
    }

    


    if (function_exists('wpc_v2_active_restore_count') && wpc_v2_active_restore_count() > 0) {
        error_log('[wpc_v2_journal_drain] yield_to_restore — deferring journal drain (bulk restore active)');
        return;
    }


    
    if (wpc_v2_journal_backoff_until() > time()) {
        return;
    }

    global $wpdb;
    $got = wpc_worker_lock('wpc_v2_journal_drain', 0) ? 1 : 0;
    if (!$got) {
        
        return;
    }

    try {
        $started = microtime(true);
        $wall_budget_s = 8.0;
        $total_files_drained = 0;
        $total_images = 0;
        $total_files_retained = 0;
        $total_files_abandoned = 0;


        $ttl_seconds = defined('WPC_V2_JOURNAL_FILE_TTL_S') ? max(60, (int) WPC_V2_JOURNAL_FILE_TTL_S) : 1800;
        foreach (wpc_v2_journal_list_files(200) as $stale_check) {
            if (file_exists($stale_check) && (time() - filemtime($stale_check)) > $ttl_seconds) {
                $age = time() - filemtime($stale_check);
                @unlink($stale_check);
                $total_files_abandoned++;
                error_log(sprintf(
                    '[wpc_v2_journal_drain] abandoned stale file=%s age_s=%d (TTL=%d)',
                    basename($stale_check), $age, $ttl_seconds
                ));
            }
        }


        $attempted_this_run = [];

        while ((microtime(true) - $started) < $wall_budget_s) {
            $files = wpc_v2_journal_list_files(50);
            if (!empty($attempted_this_run)) {
                $files = array_values(array_diff($files, $attempted_this_run));
            }
            if (empty($files)) break;

            
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
                    ];
                }
                
                
                if (isset($payload['entries']) && is_array($payload['entries'])) {
                    $inner = isset($payload['entries']['entries']) ? $payload['entries']['entries'] : $payload['entries'];
                    if (is_array($inner)) {
                        foreach ($inner as $entry) {
                            $by_image[$imageID]['entries'][] = $entry;
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
                        if ($url === '' || isset($pulls_by_url[$url])) continue;
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
                    $pulled        = wpc_v2_parallel_pull($urls_indexed, $meta_indexed);
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


                if ($result['ok'] && empty($result['any_pull_failed'])) {
                    foreach ($group['files'] as $file) {
                        @unlink($file);
                        $total_files_drained++;
                    }
                    $total_images++;
                } else if ($result['ok'] && !empty($result['any_pull_failed'])) {
                    $total_files_retained += count($group['files']);
                    error_log(sprintf(
                        '[wpc_v2_journal_drain] retaining_for_retry imageID=%d files=%d',
                        $imageID, count($group['files'])
                    ));
                } else {
                    $total_files_retained += count($group['files']);
                    foreach ($group['files'] as $wpc_rf197) {
                        @touch($wpc_rf197);
                    }
                    error_log(sprintf(
                        '[wpc_v2_journal_drain] retaining_after_failure imageID=%d files=%d reason=%s',
                        $imageID, count($group['files']),
                        isset($result['reason']) ? (string) $result['reason'] : 'unknown'
                    ));
                }
            }
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
    }

    
    


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







function wpc_v2_journal_merge_for_image($imageID, $jobId, array $entries, array $pulled_bytes_by_url = []) {
    if (empty($entries)) {
        return ['ok' => true, 'merged' => 0, 'reason' => 'no_entries'];
    }
    global $wpdb;
    $lock = 'wpc_bg_meta_' . (int) $imageID;
    
    $got_lock = wpc_worker_lock($lock);
    if (!$got_lock) {
        error_log(sprintf('[WPC V2] journal_merge lock_unavailable imageID=%d entries=%d — proceeding unlocked (race possible)', (int) $imageID, count($entries)));
    }

    $merged = 0;
    $merged_keys197 = [];
    $any_drain_complete_signal = false;
    $any_pull_failed = false;
    try {
        wp_cache_delete($imageID, 'post_meta');
        $existing = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($existing)) $existing = [];

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
                $existing[$key] = array_merge($existing[$key] ?? [], $entry);
                $merged_keys197[] = $key;
                $any_drain_complete_signal = true;
                continue;
            }
            if ($type === 'idempotent_noop') {
                
                
                $existing[$key] = array_merge($existing[$key] ?? [], [
                    'bg_upgraded'    => $now,
                    'bg_upgraded_ms' => $now_ms,
                ]);
                $merged_keys197[] = $key;
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

                
                $skip_write = false;
                if (file_exists($dest) && filesize($dest) === strlen($raw)
                    && hash_file('sha256', $dest) === hash('sha256', $raw)) {
                    $skip_write = true;
                }
                if (!$skip_write) {
                    $tmp = $dest . '.wpc_v2_tmp_' . wp_generate_password(8, false);
                    
                    
                    if (@file_put_contents($tmp, $raw) === false) {
                        $err = error_get_last();
                        error_log(sprintf(
                            '[wpc_v2_journal_merge] write_failed imageID=%d sz=%s fmt=%s bytes=%d dest_tail=%s msg=%s',
                            (int) $imageID, (string) $sz, (string) $fmt, strlen($raw),
                            substr($dest, -60), $err['message'] ?? '-'
                        ));
                        continue;
                    }
                    if (!@rename($tmp, $dest)) {
                        $err = error_get_last();
                        error_log(sprintf(
                            '[wpc_v2_journal_merge] rename_failed imageID=%d sz=%s fmt=%s dest_tail=%s msg=%s',
                            (int) $imageID, (string) $sz, (string) $fmt,
                            substr($dest, -60), $err['message'] ?? '-'
                        ));
                        @unlink($tmp);
                        continue;
                    }
                    if (!@chmod($dest, 0644)) {
                        $err = error_get_last();
                        error_log(sprintf(
                            '[wpc_v2_journal_merge] chmod_failed imageID=%d dest_tail=%s msg=%s',
                            (int) $imageID, substr($dest, -60), $err['message'] ?? '-'
                        ));
                    }
                }
                
                
                $e['bytes_path'] = $dest;
                $e['bytes_size'] = strlen($raw);

            }
            
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
            $existing[$key] = array_merge($existing[$key] ?? [], $entry);
            $merged_keys197[] = $key;
            $merged++;
            $any_drain_complete_signal = true;


            if ($orig_size > 0 && $bytes_size > 0 && $savings > 0) {
                $cur_savings = (float) get_post_meta($imageID, 'ic_savings', true);
                if ((float) $savings > $cur_savings) {
                    update_post_meta($imageID, 'ic_savings',          round((float) $savings, 1));
                    update_post_meta($imageID, 'ic_savings_format',   $fmt);
                    update_post_meta($imageID, 'ic_savings_bytes',    max(0, $orig_size - $bytes_size));
                    update_post_meta($imageID, 'ic_savings_baseline', $orig_size);
                }
            }

            
            
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
        update_post_meta($imageID, 'ic_local_variants', $existing);

        if (!empty($merged_keys197)) {
            wp_cache_delete($imageID, 'post_meta');
            $wpc_rb197 = get_post_meta($imageID, 'ic_local_variants', true);
            $wpc_verify197 = is_array($wpc_rb197);
            if ($wpc_verify197) {
                foreach ($merged_keys197 as $wpc_mk197) {
                    if (!isset($wpc_rb197[$wpc_mk197])) { $wpc_verify197 = false; break; }
                }
            }
            if (!$wpc_verify197) {
                if (function_exists('wpc_v2_store_broken_note197')) {
                    wpc_v2_store_broken_note197(true);
                }
                error_log(sprintf(
                    '[wpc_v2_journal_merge] marker_verify_failed imageID=%d keys=%d — journal retained for retry',
                    (int) $imageID, count($merged_keys197)
                ));
                return ['ok' => false, 'merged' => 0, 'reason' => 'marker_verify_failed', 'any_pull_failed' => $any_pull_failed];
            }
            if (function_exists('wpc_v2_store_broken_note197')) {
                wpc_v2_store_broken_note197(false);
            }
            if (function_exists('wpc_v2_attempts_reset197')) {
                wpc_v2_attempts_reset197($imageID);
            }
        }


        if ($merged > 0) {
            $promote_status = get_post_meta($imageID, 'ic_status', true);
            if ($promote_status !== 'compressed') {
                $promote_cmp        = get_post_meta($imageID, 'ic_compressing', true);
                $promote_cmp_status = (is_array($promote_cmp) && !empty($promote_cmp['status']))
                    ? (string) $promote_cmp['status']
                    : '';
                if ($promote_cmp_status !== 'optimizing' && $promote_cmp_status !== 'queueing') {
                    update_post_meta($imageID, 'ic_status', 'compressed');
                    if (function_exists('wpc_invalidate_local_cache')) wpc_invalidate_local_cache();
                    if ($promote_cmp_status !== 'compressed') {
                        update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
                    }
                }
            }
        }
    } finally {
        if ($got_lock) {
            wpc_worker_unlock($lock);
        }
    }

    
    
    if ($any_drain_complete_signal && function_exists('wpc_v2_recompute_savings')) {
        $imageID_for_shutdown = (int) $imageID;
        add_action('shutdown', function () use ($imageID_for_shutdown) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            if (function_exists('wpc_v2_recompute_savings')) {
                wpc_v2_recompute_savings($imageID_for_shutdown);
            }
            
            
            if (function_exists('wpc_v2_purge_html_for_attachment')) {
                wpc_v2_purge_html_for_attachment($imageID_for_shutdown, 'direct-entry');
            }
        }, 0);
    }

    return ['ok' => true, 'merged' => $merged, 'reason' => null, 'any_pull_failed' => $any_pull_failed];
}




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





function wpc_v2_journal_count_files() {
    $up = wp_get_upload_dir();
    if (empty($up['basedir'])) return 0;
    $dir = rtrim($up['basedir'], '/\\') . '/wpci-journal';
    if (!is_dir($dir)) return 0;
    $n = 0;
    $dh = @opendir($dir);
    if (!$dh) return 0;
    while (($f = readdir($dh)) !== false) {
        if (substr($f, -6) === '.jsonl') $n++;
        if ($n > 1000) break;
    }
    closedir($dh);
    return $n;
}






function wpc_v2_journal_fire_loopback_from_wp() {
    
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


register_deactivation_hook(WPC_CC_PLUGIN_FILE, function () {
    $ts = wp_next_scheduled('wpc_v2_journal_drain_cron');
    if ($ts) wp_unschedule_event($ts, 'wpc_v2_journal_drain_cron');
});

}
