<?php
/**
 * WP Compress — REST handler journal write helper.
 *
 * Companion to addons/v2/v2-direct-entry.php which owns the journal DRAIN
 * (already battle-tested for the direct-entry SHORTINIT path). This file
 * adds the WRITE side for the REST handlers in addons/v2/v2-callback.php.
 *
 * Bottleneck addressed: on shared hosts with 4-8 FPM workers (Cloudways
 * class), batch handler per-call time was 4-9 s — almost entirely
 * GET_LOCK('wpc_bg_meta_$id') + ic_local_variants blob read-merge-write.
 * With 4 concurrent images × multiple batches each contending on the SAME
 * postmeta row, 96 callbacks serialised through 3 cap slots took ~55 s
 * wall vs ~13 s for a single image. AIMD revealed but did not eliminate
 * the contention.
 *
 * With wpc_v2_rest_journal_enabled() returning true, the batch REST
 * handler instead:
 *   1. Writes variant bytes to disk (existing — unchanged)
 *   2. Appends entries to wp-content/uploads/wpci-journal/<ms>-<id>-<rand>.jsonl
 *   3. Fires a non-blocking loopback to the drain chain
 *   4. Returns 200 (target: < 50 ms total)
 *
 * The drain chain (wpc_v2_journal_drain_run, already shipping) reads the
 * journal files for each imageID, does ONE merge per image under ONE lock
 * acquire, then truncates. Postmeta touches per image: 1 (vs N today).
 *
 * @see addons/v2/v2-direct-entry.php  — drain side
 * @see addons/v2/v2-callback.php       — batch REST handler caller
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_rest_journal_enabled')) {

/**
 * Flag check. Default 0 until staging verifies wall-time + visual-bump
 * cadence at BATCH_FLUSH_AFTER_MS=500. Filter `wpc_v2_rest_journal_enabled`
 * for per-site runtime control.
 */
function wpc_v2_rest_journal_enabled() {
    $enabled = ((int) get_option('wpc_v2_rest_journal_enabled', 0) === 1);
    return (bool) apply_filters('wpc_v2_rest_journal_enabled', $enabled);
}

/**
 * Resolve + ensure journal dir. Shared path with direct-entry drain so the
 * existing wpc_v2_journal_list_files() picks up REST-written files for free.
 *
 * Returns absolute path on success; empty string on failure. On failure the
 * caller MUST fall back to inline merge — silently dropping variants is
 * unacceptable.
 */
function wpc_v2_journal_dir() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $up = wp_get_upload_dir();
    if (empty($up['basedir'])) {
        $cached = '';
        return $cached;
    }
    $dir = rtrim($up['basedir'], '/\\') . '/wpci-journal';
    if (!is_dir($dir)) {
        if (!wp_mkdir_p($dir)) {
            $cached = '';
            return $cached;
        }
        // Files contain no secrets but no reason to serve them either.
        @file_put_contents($dir . '/.htaccess', "Deny from all\n");
        @file_put_contents($dir . '/index.html', '');
    }
    if (!is_writable($dir)) {
        $cached = '';
        return $cached;
    }
    $cached = $dir;
    return $cached;
}

/**
 * Write one batch payload as a single .jsonl file. Atomic via temp+rename
 * so the drain side never reads a half-written file.
 *
 * Filename: <unix_ms>-<imageID>-<rand>.jsonl. Leading ms gives directory
 * listing chronological order (drain processes oldest first).
 *
 * Schema MUST match what wpc_v2_journal_merge_for_image() in
 * v2-direct-entry.php expects:
 *
 *   {
 *     "imageID": 122,
 *     "jobId":   "j_abc...",
 *     "entries": {
 *       "flush_reason": "batched",
 *       "received_ms":  1737401234567,
 *       "entries": [
 *         {"sizeLabel":"scaled","format":"avif","type":"persisted",
 *          "originalSize":1048576,"bytes_size":96800,
 *          "bytes_path":"/abs/path/scaled.avif","ms":...,"kb":...,"butter":...},
 *         {"sizeLabel":"thumbnail","format":"webp","type":"no_improvement",
 *          "reason":"source_already_optimal","baselineKb":7.62},
 *         ...
 *       ]
 *     }
 *   }
 *
 * @return bool true if written (caller may skip inline merge), false if
 *              failed (caller MUST fall back to inline merge).
 */
function wpc_v2_journal_write_batch($imageID, $jobId, array $entries, $flush_reason = '') {
    if (empty($entries)) {
        return true;
    }
    $dir = wpc_v2_journal_dir();
    if ($dir === '') {
        // Postmortem item p0: this was silent. The caller (pull-manifest
        // tick) acks the entry to orch even on this false return, so
        // failure here is permanent data loss without observability.
        error_log(sprintf(
            '[WPC JournalWrite] FAIL reason=no_journal_dir imageID=%d entries=%d',
            (int) $imageID, count($entries)
        ));
        return false;
    }

    $payload = [
        'imageID' => (int) $imageID,
        'jobId'   => (string) $jobId,
        'entries' => [
            'flush_reason' => (string) $flush_reason,
            'received_ms'  => (int) round(microtime(true) * 1000),
            'entries'      => array_values($entries),
        ],
    ];
    $body = wp_json_encode($payload);
    if ($body === false) {
        error_log(sprintf(
            '[WPC JournalWrite] FAIL reason=json_encode_failed imageID=%d entries=%d',
            (int) $imageID, count($entries)
        ));
        return false;
    }

    $ms   = (int) round(microtime(true) * 1000);
    $rand = wp_generate_password(6, false, false);
    $path = $dir . '/' . $ms . '-' . (int) $imageID . '-' . $rand . '.jsonl';
    $tmp  = $path . '.tmp';

    if (@file_put_contents($tmp, $body, LOCK_EX) === false) {
        $err = error_get_last();
        error_log(sprintf(
            '[WPC JournalWrite] FAIL reason=tmp_write_failed imageID=%d entries=%d bytes=%d dest_tail=%s msg=%s',
            (int) $imageID, count($entries), strlen($body), substr($tmp, -60), ($err['message'] ?? '-')
        ));
        return false;
    }
    if (!@rename($tmp, $path)) {
        $err = error_get_last();
        error_log(sprintf(
            '[WPC JournalWrite] FAIL reason=rename_failed imageID=%d entries=%d dest_tail=%s msg=%s',
            (int) $imageID, count($entries), substr($path, -60), ($err['message'] ?? '-')
        ));
        @unlink($tmp);
        return false;
    }
    return true;
}

/**
 * Fast non-blocking loopback fire. Replacement for
 * wpc_v2_journal_fire_loopback_from_wp() in the REST hot path because the
 * latter goes through wp_remote_post which rounds ceil(timeout=0.01) to a
 * full 1 s CURLOPT_TIMEOUT — every batch handler then paid ~1 s "merge_ms"
 * just to kick the drain (which the drain self-chain made redundant anyway).
 *
 * Two layers of fix:
 *   1. THROTTLE — at most one fire per WPC_V2_JOURNAL_FIRE_THROTTLE_MS
 *      milliseconds (default 250 ms). Dedupes microsecond-burst floods of
 *      fsockopen fires while leaving the throttle window narrow enough that
 *      "the next batch in this wave will wake the drain" remains true.
 *      The 3 s throttle that shipped initially was too wide — when a wave's
 *      LAST batch fell inside it, the file orphaned because no follow-up
 *      batch existed to retrigger after the throttle expired.
 *   2. FAST FIRE — fsockopen + write request + immediate close. No
 *      CURLOPT_CONNECTTIMEOUT rounding, no wait for response, ~10-50 ms
 *      total worker time per fire (vs ~1000 ms with wp_remote_post).
 *
 * Returns true if a fire actually went out, false if throttled or fsockopen
 * failed. The caller (batch handler in v2-callback.php) registers a
 * shutdown-hook deferred fire when false is returned — that catches the
 * end-of-wave case where this batch is the last and no follow-up batch will
 * arrive to wake the drain. Drain handler also checks a pending_fire marker
 * on its exit fence as belt-and-suspenders.
 *
 * Falls back gracefully: if fsockopen fails (host disables it, SSL handshake
 * issue), pending_fire marker is set so drain's exit fence self-chains.
 *
 * @return bool  true if loopback actually fired, false if throttled or failed.
 */
function wpc_v2_journal_fire_loopback_fast() {
    $throttle_ms = defined('WPC_V2_JOURNAL_FIRE_THROTTLE_MS')
        ? max(50, (int) WPC_V2_JOURNAL_FIRE_THROTTLE_MS)
        : 250;
    $now_ms       = (int) (microtime(true) * 1000);
    $last_fire_ms = (int) get_option('wpc_v2_journal_fire_last_ms', 0);

    if (($now_ms - $last_fire_ms) < $throttle_ms) {
        // Inside dedup window. A fire fired recently; the drain it woke will
        // pick up our file via its in-loop list_files() iteration. Mark a
        // pending fire so the drain's exit fence self-chains if it somehow
        // missed our file (race: file written 1 ms before drain exits).
        set_transient('wpc_v2_journal_pending_fire', $now_ms, 60);
        return false;
    }
    update_option('wpc_v2_journal_fire_last_ms', $now_ms, false);

    // Canonical helper (see v2-capabilities.php).
    $apikey = function_exists('wpc_v2_get_apikey') ? wpc_v2_get_apikey() : '';
    if ($apikey === '') {
        return false;
    }

    $ts  = time();
    $sig = hash_hmac('sha256', 'wpc_v2_drain.' . $ts, $apikey);

    $url   = admin_url('admin-ajax.php');
    $parts = wp_parse_url($url);
    if (empty($parts['host'])) {
        return false;
    }
    $is_https = (!empty($parts['scheme']) && $parts['scheme'] === 'https');
    $port     = !empty($parts['port']) ? (int) $parts['port'] : ($is_https ? 443 : 80);
    $host     = (string) $parts['host'];
    $path     = (!empty($parts['path']) ? $parts['path'] : '/') . '?action=wpc_v2_journal_drain';

    $body = http_build_query(['t' => $ts, 'sig' => $sig]);
    $req  = "POST {$path} HTTP/1.1\r\n"
          . "Host: {$host}\r\n"
          . "Content-Type: application/x-www-form-urlencoded\r\n"
          . "Content-Length: " . strlen($body) . "\r\n"
          . "Connection: close\r\n"
          . "User-Agent: WPCV2Journal/1.0\r\n"
          . "\r\n"
          . $body;

    // Local-vhost loopback (was @fsockopen($sock_host) where $sock_host = the PUBLIC host =
    // the CDN/WAF edge on a datacenter-IP site → truthy but the drain self-POST never landed on local
    // PHP-FPM). This is the REST/SHORTINIT hot-path fire, where core's autoloader is NOT registered, so
    // wps_ic_ajax may be absent — prefer the proven shared helper when present, else INLINE the same
    // local-vhost connect (127.0.0.1→localhost→public host, Host:/SNI = public host). NEVER the old
    // public-host fsockopen. 0.2 s budget unchanged; pending_fire marker + shutdown-hook stay backstops.
    $errno  = 0;
    $errstr = '';
    $fp     = false;
    if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) {
        $fp = wps_ic_ajax::wpc_loopback_open_socket($host, $port, $is_https, 0.2);
    } else {
        $jf_ctx = $is_https ? stream_context_create(['ssl' => ['peer_name' => $host, 'SNI_enabled' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]) : null;
        foreach (['127.0.0.1', 'localhost', $host] as $jf_chost) {
            $jf_remote = ($is_https ? 'tls://' : 'tcp://') . $jf_chost . ':' . $port;
            $jf_sock   = $jf_ctx
                ? @stream_socket_client($jf_remote, $errno, $errstr, 0.2, STREAM_CLIENT_CONNECT, $jf_ctx)
                : @stream_socket_client($jf_remote, $errno, $errstr, 0.2);
            if ($jf_sock) { $fp = $jf_sock; break; }
        }
    }
    if (!$fp) {
        // Mark pending so drain exit-fence or shutdown-hook catches up.
        set_transient('wpc_v2_journal_pending_fire', $now_ms, 60);
        return false;
    }
    @stream_set_timeout($fp, 0, 100000);
    @fwrite($fp, $req);
    @fclose($fp);
    return true;
}

}  // end if (!function_exists)
