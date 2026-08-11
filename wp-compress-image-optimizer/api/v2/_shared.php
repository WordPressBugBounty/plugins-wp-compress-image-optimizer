<?php


if (!defined('WPC_V2_DIRECT_ENTRY')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"error":"forbidden"}';
    exit;
}


if (!defined('WPC_V2_SKIP_METHOD_GUARD') || !WPC_V2_SKIP_METHOD_GUARD) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo '{"error":"method_not_allowed"}';
        exit;
    }
    if (empty($_SERVER['HTTP_X_WPC_SIG'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"error":"auth","reason":"missing_sig"}';
        exit;
    }
}


if (!defined('SHORTINIT')) {
    define('SHORTINIT', true);
}


$wpc_v2_wp_load = realpath(__DIR__ . '/../../../../../wp-load.php');
if (!$wpc_v2_wp_load || !is_file($wpc_v2_wp_load)) {


    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"wp_load_not_found"}';
    error_log('[wpc_v2_direct_entry] FATAL wp-load.php not found from ' . __DIR__);
    exit;
}
require_once $wpc_v2_wp_load;


if (!defined('WP_CONTENT_URL') && function_exists('get_option')) {
    define('WP_CONTENT_URL', get_option('siteurl') . '/wp-content');
}





function wpc_v2_read_apikey() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    global $wpdb;


    foreach (['wps_ic', 'wps_ic_options', 'wps_ic_settings'] as $opt_name) {
        $row = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                $opt_name
            )
        );
        if (!$row) {
            continue;
        }
        $opts = maybe_unserialize($row);
        if (is_array($opts) && !empty($opts['api_key'])) {
            $cached = (string) $opts['api_key'];
            return $cached;
        }
    }
    $cached = '';
    return '';
}





function wpc_v2_direct_safe_filename($filename) {
    $filename = basename((string) $filename);
    if ($filename === '' || $filename[0] === '.' || strpos($filename, "\0") !== false) {
        return '';
    }
    $segs = explode('.', strtolower($filename));
    if (count($segs) < 2) {
        return '';
    }
    if (!in_array(end($segs), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
        return '';
    }
    $danger = ['php','php3','php4','php5','php6','php7','php8','phps','pht','phtml','phar','shtml','xhtml','html','htm','svg','svgz','js','mjs','jsp','asp','aspx','cgi','pl','py','sh','exe','dll','htaccess','ini','sql','phpt'];
    foreach (array_slice($segs, 0, -1) as $seg) {
        if (in_array($seg, $danger, true)) {
            return '';
        }
    }
    return $filename;
}


function wpc_v2_direct_safe_fetch_url($url) {
    $url = (string) $url;
    $p = @parse_url($url);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return false;
    }
    $scheme = strtolower($p['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }
    $host = trim($p['host'], '[]');
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }
        if (function_exists('dns_get_record')) {
            $v6 = @dns_get_record($host, DNS_AAAA);
            if (is_array($v6)) {
                foreach ($v6 as $rec) {
                    if (!empty($rec['ipv6'])) {
                        $ips[] = $rec['ipv6'];
                    }
                }
            }
        }
    }
    if (empty($ips)) {
        return false;
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}


function wpc_v2_direct_verify_hmac($sig_header, $body_raw) {
    if (!is_string($sig_header) || $sig_header === '') {
        return ['ok' => false, 'reason' => 'missing_sig'];
    }
    if (!function_exists('hash_hmac')) {
        return ['ok' => false, 'reason' => 'hash_hmac_unavailable'];
    }
    $parts = [];
    foreach (explode(',', $sig_header) as $kv) {
        $kv = trim($kv);
        $eq = strpos($kv, '=');
        if ($eq === false) continue;
        $parts[substr($kv, 0, $eq)] = substr($kv, $eq + 1);
    }
    if (empty($parts['t']) || empty($parts['v1'])) {
        return ['ok' => false, 'reason' => 'malformed_sig'];
    }
    $ts  = (int) $parts['t'];
    $now = time();
    if (abs($now - $ts) > 60) {
        return ['ok' => false, 'reason' => 'replay_window_exceeded'];
    }
    $apikey = wpc_v2_read_apikey();
    if ($apikey === '') {
        return ['ok' => false, 'reason' => 'plugin_no_apikey'];
    }
    $expected = hash_hmac('sha256', $ts . '.' . hash('sha256', (string) $body_raw), $apikey);
    if (!hash_equals($expected, (string) $parts['v1'])) {
        return ['ok' => false, 'reason' => 'sig_mismatch'];
    }
    return ['ok' => true];
}


function wpc_v2_direct_respond($status, array $payload) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload);


    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}


function wpc_v2_journal_dir() {
    static $cached = null;
    if ($cached !== null) return $cached;
    $up = wp_upload_dir();
    if (empty($up['basedir'])) {
        $cached = '';
        return '';
    }
    $cached = rtrim($up['basedir'], '/\\') . '/wpci-journal';
    return $cached;
}





function wpc_v2_journal_ensure_dir() {
    $dir = wpc_v2_journal_dir();
    if ($dir === '') return false;
    
    
    if (get_transient('wpc_v2_journal_dir_ok')) {
        return true;
    }
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    
    
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }

    $index = $dir . '/index.html';
    if (!is_file($index)) {
        @file_put_contents($index, '');
    }
    if (!is_writable($dir)) {
        return false;
    }
    set_transient('wpc_v2_journal_dir_ok', 1, 5 * MINUTE_IN_SECONDS);
    return true;
}


function wpc_v2_journal_write($imageID, $jobId, array $entries) {
    if (!wpc_v2_journal_ensure_dir()) return false;
    $dir = wpc_v2_journal_dir();
    $imageID_i = (int) $imageID;
    $jobId_s   = preg_replace('/[^a-zA-Z0-9_\-]/', '', substr((string) $jobId, 0, 16));
    if ($jobId_s === '') $jobId_s = 'nojob';
    $ms = (int) round(microtime(true) * 1000);
    
    
    $rand = function_exists('random_int') ? random_int(1000, 9999) : mt_rand(1000, 9999);
    $name = $imageID_i . '-' . $jobId_s . '-' . $ms . '-' . $rand . '.jsonl';
    $final = $dir . '/' . $name;
    $tmp   = $final . '.tmp';

    $payload = [
        'v'           => 1,
        'imageID'     => $imageID_i,
        'jobId'       => (string) $jobId,
        'received_ms' => $ms,
        'entries'     => array_values($entries),
    ];
    $line = wp_json_encode($payload);
    if ($line === false) return false;

    if (@file_put_contents($tmp, $line, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $final)) {
        @unlink($tmp);
        return false;
    }
    @chmod($final, 0644);
    return $final;
}





function wpc_v2_journal_count() {
    $dir = wpc_v2_journal_dir();
    if (!is_dir($dir)) return 0;
    $n = 0;
    $dh = @opendir($dir);
    if (!$dh) return 0;
    while (($f = readdir($dh)) !== false) {
        if (substr($f, -6) === '.jsonl') $n++;
    }
    closedir($dh);
    return $n;
}


function wpc_v2_journal_fire_loopback() {
    $apikey = wpc_v2_read_apikey();
    if ($apikey === '') return;
    $ts = time();
    $sig = hash_hmac('sha256', 'wpc_v2_drain.' . $ts, $apikey);
    $url = admin_url('admin-ajax.php?action=wpc_v2_journal_drain');


    if (function_exists('wp_remote_post')) {
        wp_remote_post($url, [
            'timeout'   => 0.01,
            'blocking'  => false,
            'sslverify' => false,
            'body'      => ['t' => $ts, 'sig' => $sig],
        ]);
        return;
    }
    
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['t' => $ts, 'sig' => $sig]),
            CURLOPT_TIMEOUT_MS     => 100,
            CURLOPT_CONNECTTIMEOUT_MS => 100,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        @curl_exec($ch);
        @curl_close($ch);
    }
}



function wpc_v2_direct_callbacks_blocked($imageID) {


    if (function_exists('get_transient')) {
        return get_transient('wpc_v2_callbacks_blocked_' . (int) $imageID) !== false;
    }
    global $wpdb;
    $val = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
        '_transient_wpc_v2_callbacks_blocked_' . (int) $imageID
    ));
    return $val !== null;
}


function wpc_v2_direct_persist_bytes($imageID, $filename, $raw) {
    if (!function_exists('get_attached_file')) {


        require_once ABSPATH . WPINC . '/post.php';
    }
    $abs_parent = get_attached_file((int) $imageID);
    if (!$abs_parent) {
        return ['ok' => false, 'error' => 'parent_file_missing', 'path' => null, 'bytes_size' => 0, 'idempotent' => false];
    }
    $dest_dir = dirname($abs_parent);
    $dest     = $dest_dir . '/' . $filename;

    
    if (file_exists($dest) && filesize($dest) === strlen($raw) && hash_file('sha256', $dest) === hash('sha256', $raw)) {
        return ['ok' => true, 'idempotent' => true, 'path' => $dest, 'bytes_size' => strlen($raw), 'error' => null];
    }

    $tmp = $dest . '.wpc_v2_tmp_' . substr(md5(microtime(true) . mt_rand()), 0, 8);
    if (@file_put_contents($tmp, $raw, LOCK_EX) === false) {
        return ['ok' => false, 'error' => 'write_failed', 'path' => null, 'bytes_size' => 0, 'idempotent' => false];
    }
    if (!@rename($tmp, $dest)) {
        @unlink($tmp);
        return ['ok' => false, 'error' => 'rename_failed', 'path' => null, 'bytes_size' => 0, 'idempotent' => false];
    }
    @chmod($dest, 0644);
    return ['ok' => true, 'idempotent' => false, 'path' => $dest, 'bytes_size' => strlen($raw), 'error' => null];
}






function wpc_v2_direct_derive_filename($imageID, $size_label, $format) {
    if (!function_exists('wp_get_attachment_metadata')) {
        require_once ABSPATH . WPINC . '/post.php';
    }
    $abs_parent = get_attached_file((int) $imageID);
    if (!$abs_parent) return '';
    $ext = ($format === 'jpeg' || $format === 'jpg') ? 'jpg' : strtolower($format);

    if ($size_label === 'scaled' || $size_label === '') {
        $base = basename($abs_parent);
        $dot  = strrpos($base, '.');
        return $dot === false ? '' : substr($base, 0, $dot) . '.' . $ext;
    }
    if ($size_label === 'original') {
        $orig = function_exists('wp_get_original_image_path') ? wp_get_original_image_path((int) $imageID) : $abs_parent;
        if (!$orig) $orig = $abs_parent;
        $base = basename($orig);
        $dot  = strrpos($base, '.');
        return $dot === false ? '' : substr($base, 0, $dot) . '.' . $ext;
    }
    $meta = wp_get_attachment_metadata((int) $imageID);
    if (!is_array($meta) || empty($meta['sizes'][$size_label]['file'])) return '';
    $sub = (string) $meta['sizes'][$size_label]['file'];
    $dot = strrpos($sub, '.');
    return $dot === false ? $sub . '.' . $ext : substr($sub, 0, $dot) . '.' . $ext;
}
