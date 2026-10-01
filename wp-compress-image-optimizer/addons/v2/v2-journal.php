<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_rest_journal_enabled')) {


function wpc_v2_rest_journal_enabled() {
    $enabled = ((int) get_option('wpc_v2_rest_journal_enabled', 0) === 1);
    return (bool) apply_filters('wpc_v2_rest_journal_enabled', $enabled);
}


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
        wpc_fs_put($dir . '/.htaccess', "Deny from all\n");
        wpc_fs_put($dir . '/index.html', '');
    }
    if (!is_writable($dir)) {
        $cached = '';
        return $cached;
    }
    $cached = $dir;
    return $cached;
}


function wpc_v2_journal_write_batch($imageID, $jobId, array $entries, $flush_reason = '') {
    if (empty($entries)) {
        return true;
    }
    $dir = wpc_v2_journal_dir();
    if ($dir === '') {
        // Postmortem item p0: this was silent. The caller (pull-manifest


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

    if (wpc_fs_put($tmp, $body, LOCK_EX) === false) {
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


function wpc_v2_journal_has_image($imageID) {
    $imageID = (int) $imageID;
    if ($imageID <= 0) return false;
    $dir = wpc_v2_journal_dir();
    if ($dir === '') return false;
    $g = glob($dir . '/*-' . $imageID . '-*.jsonl');
    return is_array($g) && !empty($g);
}

/**
 * Every journal file that carries callbacks for one image, in both name layouts: this file's
 * writer (`<ms>-<id>-<rand>.jsonl`) and the REST fast path's (`<id>-<job>-<ms>-<rand>.jsonl`,
 * api/v2/_shared.php). `.tmp` files are a write in progress and are not listed.
 */
function wpc_v2_journal_image_files($imageID) {
    $imageID = (int) $imageID;
    if ($imageID <= 0) return [];
    $dir = wpc_v2_journal_dir();
    if ($dir === '') return [];
    $files = array_merge(
        (array) glob($dir . '/*-' . $imageID . '-*.jsonl'),
        (array) glob($dir . '/' . $imageID . '-*.jsonl')
    );
    return array_values(array_unique(array_filter($files, 'is_string')));
}

/**
 * Drop the journal files of one image (a restore is a clean slate). Answers how many were
 * removed. Observed: entries left from a service 429 window, whose fetch_url no longer answers,
 * stayed after the image was restored and refused every later bulk run of it (the journal gate;
 * rig, 2026-09-26: 51 of 51 refused, 0 dispatched).
 */
function wpc_v2_journal_drop_image($imageID) {
    $n = 0;
    foreach (wpc_v2_journal_image_files($imageID) as $f) {
        if (@unlink($f)) $n++;
    }
    return $n;
}


function wpc_v2_journal_fire_loopback_fast() {
    $throttle_ms = defined('WPC_V2_JOURNAL_FIRE_THROTTLE_MS')
        ? max(50, (int) WPC_V2_JOURNAL_FIRE_THROTTLE_MS)
        : 250;
    $now_ms       = (int) (microtime(true) * 1000);
    $last_fire_ms = (int) get_option('wpc_v2_journal_fire_last_ms', 0);

    if (($now_ms - $last_fire_ms) < $throttle_ms) {


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

}
