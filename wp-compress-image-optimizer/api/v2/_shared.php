<?php
include_once __DIR__ . '/../../addons/cache/wpc-fs.php';
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

// At this point: $wpdb is global. get_option works. wp_upload_dir() works (WP_CONTENT_URL ensured above).
// maybe_unserialize / sanitize_* helpers all available.


// The signature, size, fetch-host and image checks are the REST callbacks' own (v2-inbound.php).
require_once __DIR__ . '/../../addons/v2/v2-inbound.php';
// The one writer of variant bytes (containment, extension allowlist, the larger-than-disk refusal).
require_once __DIR__ . '/../../addons/v2/v2-store.php';


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


//


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






function wpc_v2_direct_respond($status, array $payload) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload);


    if (function_exists('wpc_finish_request')) { wpc_finish_request(); } elseif (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
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

/**
 * Ensure the journal dir exists, is writable, and has its own .htaccess.
 * Called from the inbound write path; cheap after first call (transient cache).
 */
function wpc_v2_journal_ensure_dir() {
    $dir = wpc_v2_journal_dir();
    if ($dir === '') return false;
    // Lightweight check: if dir exists + we wrote .htaccess in a prior request,
    // we're done. Re-check every 5 min via transient to recover from manual deletes.
    if (get_transient('wpc_v2_journal_dir_ok')) {
        return true;
    }
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }
    }
    // .htaccess deny — defense in depth (uploads dir already restrictive, but
    // explicit is better than implicit).
    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        wpc_fs_put($htaccess, "Order Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }

    $index = $dir . '/index.html';
    if (!is_file($index)) {
        wpc_fs_put($index, '');
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
    // Add a random suffix to absolutely guarantee uniqueness even if two
    // callbacks for the same image+job arrive in the same millisecond.
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

    if (wpc_fs_put($tmp, $line, LOCK_EX) === false) {
        return false;
    }
    if (!@rename($tmp, $final)) {
        @unlink($tmp);
        return false;
    }
    @chmod($final, 0644);
    return $final;
}

/**
 * Count current journal files (excludes .tmp in-flight writes). Used by
 * inbound handlers to decide whether to fire a drain loopback.
 */
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
    // Fallback raw curl (works in SHORTINIT context)
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

// ─── Restored-image guard (matches REST endpoint behavior) ───────────────

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


/**
 * Lands one variant through the store. `refused` is set when the store refused the bytes as no
 * smaller than the file WordPress serves at that size: the caller journals a no-improvement entry
 * (the store cannot record it under SHORTINIT) and writes nothing.
 */
function wpc_v2_direct_persist_bytes($imageID, $filename, $raw, $size_label = '', $format = '', $src = 'direct_entry') {
    if (!function_exists('get_attached_file')) {


        require_once ABSPATH . WPINC . '/post.php';
    }
    $abs_parent = get_attached_file((int) $imageID);
    if (!$abs_parent) {
        return ['ok' => false, 'error' => 'parent_file_missing', 'path' => null, 'bytes_size' => 0, 'idempotent' => false];
    }
    $dest_dir = dirname($abs_parent);
    $dest     = $dest_dir . '/' . $filename;

    // Idempotency fast-path: same bytes already on disk → no-op.
    if (file_exists($dest) && filesize($dest) === strlen($raw) && hash_file('sha256', $dest) === hash('sha256', $raw)) {
        return ['ok' => true, 'idempotent' => true, 'path' => $dest, 'bytes_size' => strlen($raw), 'error' => null];
    }

    $put = wpc_v2_store_bytes($raw, $dest, ['variant' => ['id' => (int) $imageID, 'size' => (string) $size_label, 'fmt' => (string) $format, 'src' => (string) $src]]);
    if (($put['error'] ?? '') === 'larger_than_disk') {
        return ['ok' => false, 'refused' => 'larger_than_disk', 'error' => 'larger_than_disk', 'path' => null, 'bytes_size' => 0, 'idempotent' => false];
    }
    if (empty($put['ok'])) {
        return ['ok' => false, 'error' => (string) $put['error'], 'path' => null, 'bytes_size' => 0, 'idempotent' => false];
    }
    return ['ok' => true, 'idempotent' => false, 'path' => $dest, 'bytes_size' => strlen($raw), 'error' => null];
}

/**
 * Derive variant filename when encoder omits it (mirrors v2-callback.php's
 * wpc_v2_derive_variant_filename). Loaded on demand because it needs post.php
 * for wp_get_attachment_metadata.
 */
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
