<?php
/**
 * WP Compress v7.02 — Shared utilities for direct PHP entry callback handlers.
 *
 * Loaded by bg_swap_batch.php, bg_swap_announce.php, bg_swap.php, health.php.
 * NOT directly invocable — .htaccess blocks browser access. The internal guard
 * below also rejects any request that didn't go through one of the entry files.
 *
 * Bootstrap pattern: WordPress SHORTINIT mode. Loads ONLY wp-config.php +
 * $wpdb + minimal helpers (load.php, functions.php, formatting.php). Skips
 * plugins, themes, hooks, REST API, admin layer — the heavy stuff. Total
 * bootstrap time: ~30-80 ms vs ~300-500 ms for full WP REST handler.
 *
 * SHORTINIT is a documented WordPress feature (used by WP-CLI for bulk ops,
 * various security plugins, ActionScheduler). Repo-compliant pattern.
 *
 * @see SPEC-direct_entry.md §4 for the security model
 * @see SPEC-direct_entry.md §5 for host detection + fallback
 */

// Guard: only entry files may include this. Each entry sets this constant
// BEFORE the require. Without it, attempts to hit _shared.php directly
// (despite the .htaccess deny) terminate with a generic 403.
if (!defined('WPC_V2_DIRECT_ENTRY')) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo '{"error":"forbidden"}';
    exit;
}

// ─── Method + sig presence preflight ─────────────────────────────────────
//
// Bail early on non-POST or missing HMAC header. Costs ~0 ms; rejects 99 %
// of curious scanners + accidental browser visits before any heavy work.
// Health endpoint sets WPC_V2_SKIP_METHOD_GUARD=true since it accepts a
// POST-with-token shape that isn't HMAC-signed.

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

// ─── SHORTINIT WordPress bootstrap ────────────────────────────────────────
//
// Loads enough WP to use $wpdb, get_option, wp_upload_dir, maybe_unserialize.
// Does NOT load plugins, themes, REST router, admin layer, hooks beyond core.
// Constant set BEFORE wp-load.php so wp-settings.php short-circuits early.

if (!defined('SHORTINIT')) {
    define('SHORTINIT', true);
}

// Compute wp-load.php path relative to this file. This file lives at:
//   /wp-content/plugins/wp-compress-image-optimizer/api/v2/_shared.php
// wp-load.php lives at:
//   /wp-load.php
// → 5 levels up.
$wpc_v2_wp_load = realpath(__DIR__ . '/../../../../../wp-load.php');
if (!$wpc_v2_wp_load || !is_file($wpc_v2_wp_load)) {
    // Customer has a non-standard layout (custom wp-content path, symlinks).
    // Bail with diagnostic — the probe at activation should have caught this
    // and flipped wpc_v2_direct_entry_healthy=0, so this branch should be
    // unreachable in practice. Log just in case so we know if it ever fires.
    http_response_code(500);
    header('Content-Type: application/json');
    echo '{"error":"wp_load_not_found"}';
    error_log('[wpc_v2_direct_entry] FATAL wp-load.php not found from ' . __DIR__);
    exit;
}
require_once $wpc_v2_wp_load;

// SHORTINIT short-circuits wp-settings.php BEFORE wp_plugin_directory_constants() runs, so
// WP_CONTENT_URL is never defined in this bootstrap. wp_upload_dir() -> _wp_upload_dir() references it
// for the uploads baseurl, and on PHP 8 an undefined constant is a HARD FATAL — which crashed any
// direct-entry endpoint that touches uploads (e.g. the journal dir via health.php -> 500, which made
// the plugin treat direct-entry as unhealthy and fall back to slower REST callbacks). Define it exactly
// as WP core's fallback (wp_plugin_directory_constants) does. WP_CONTENT_DIR IS defined under SHORTINIT
// (wp_initial_constants runs before the early return), so only the URL constant is missing.
if (!defined('WP_CONTENT_URL') && function_exists('get_option')) {
    define('WP_CONTENT_URL', get_option('siteurl') . '/wp-content');
}

// At this point: $wpdb is global. get_option works. wp_upload_dir() works (WP_CONTENT_URL ensured above).
// maybe_unserialize / sanitize_* helpers all available.

// ─── Apikey reader (cached per-request) ───────────────────────────────────
//
// Reads the CANONICAL apikey (option 'wps_ic', with fallbacks) from wp_options
// via $wpdb. One SELECT per option until found, ~3-5 ms. Result static-cached so
// multiple HMAC verifies in the same request (e.g. the batch handler reading the
// apikey for the wrapper sig) don't re-query.

function wpc_v2_read_apikey() {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    global $wpdb;
    // v7.02.03 — SECURITY: read the CANONICAL apikey option. This previously read
    // ONLY 'wps_ic_options' (WPS_IC_OPTIONS_V2 — a migration-staging option that is
    // empty on most sites), so every HMAC verify failed with plugin_no_apikey and
    // the entire /api/v2 direct-entry was dead on canonical sites (and silently live
    // only on the rare site where wps_ic_options happened to be populated). Mirror
    // the canonical wpc_v2_get_apikey() resolution order: 'wps_ic' first, then the
    // migration option, then the settings array.
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

// ─── Input validation helpers (v7.02.03 SECURITY) ────────────────────────
//
// The orch-supplied `filename` is written to the uploads dir, and `fetchUrl` is
// fetched server-side. Both are HMAC-gated but must be validated before use, to
// the SAME contract the WP-integrated direct-entry already enforces (the v7.01.62
// "fleet audit B1" filename guard) plus SSRF protection on the fetch URL. Shared
// by bg_swap.php + bg_swap_batch.php.

/**
 * Return a safe basename for an orch-supplied filename, or '' if hostile.
 * Mirrors the v7.01.62 journal-merge guard: basename (kills traversal), the FINAL
 * segment must be an image ext, and NO interior segment may be an executable/active
 * type (blocks shell.php, x.php.webp, x.svg.avif, wp-config.php, etc.). Benign
 * timestamp dots (…-12.43.25-PM-…) pass.
 */
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

/**
 * SSRF guard for an orch-supplied fetchUrl. Requires http/https + a host that
 * resolves ONLY to public IPs — rejects private/reserved/loopback/link-local
 * (incl. 127.0.0.0/8, 169.254.169.254 cloud-metadata, 10/172.16/192.168, ::1,
 * fc00::/7). Caller must ALSO disable redirects (follow_location=0) so a public
 * host can't 30x into an internal target. Unresolvable host → reject.
 */
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
    $host = trim($p['host'], '[]'); // strip IPv6 literal brackets
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
        return false; // can't prove it's public → reject
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
    }
    return true;
}

// ─── HMAC verification ────────────────────────────────────────────────────
//
// Identical scheme to the REST endpoint's wpc_v2_verify_hmac in v2-callback.php.
// Format: X-WPC-Sig: t=<unix_ts>,v1=<hex_hmac_sha256_of_t_dot_body_sha256>
// Key = site apikey, 60s replay window. timing_safe via hash_equals.

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

// ─── JSON response helper ─────────────────────────────────────────────────

function wpc_v2_direct_respond($status, array $payload) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($payload);
    // Release the FPM worker as soon as the response is flushed. Direct-entry
    // is already fast (~30-80 ms total), but on saturated hosts every ms of
    // worker turnover counts. Falls back gracefully on non-FPM (Apache mod_php).
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit;
}

// ─── Journal directory + helpers ──────────────────────────────────────────
//
// Journal lives at wp_upload_dir()['basedir'] . '/wpci-journal/'. WordPress's
// uploads dir is per-site (multisite-aware via wp_upload_dir). One file per
// callback. Drain reads + deletes. Atomic via temp+rename.

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
        @file_put_contents($htaccess, "Order Deny,Allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }
    // index.html as well — prevents directory listing on hosts with autoindex.
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

/**
 * Atomic write of a journal entry. Filename: <imageID>-<jobId>-<unix_ms>.jsonl
 * (jobId truncated to 16 chars, sanitized). One entry per file (no append
 * contention). Drain reads all files for an imageID and consolidates.
 *
 * @param int    $imageID
 * @param string $jobId
 * @param array  $entries  per-variant entries (typically 1-25 in a batch)
 * @return string|false    full path on success, false on failure
 */
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

/**
 * Fire a non-blocking loopback to /admin-ajax.php?action=wpc_v2_journal_drain.
 * Mirrors the wpc_bulk_v2_fire_loopback pattern in classes/ajax.class.php so
 * we have a single well-understood mechanism for triggering chain handlers.
 *
 * Auth: HMAC over a fixed string "wpc_v2_drain.<ts>" with site apikey.
 * Drain handler verifies before doing any work. Concurrent drains are
 * serialized by GET_LOCK in the handler itself.
 */
function wpc_v2_journal_fire_loopback() {
    $apikey = wpc_v2_read_apikey();
    if ($apikey === '') return;
    $ts = time();
    $sig = hash_hmac('sha256', 'wpc_v2_drain.' . $ts, $apikey);
    $url = admin_url('admin-ajax.php?action=wpc_v2_journal_drain');
    // wp_remote_post may not be loaded in SHORTINIT. Use raw curl with
    // fire-and-forget semantics — we don't care about the response.
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
    // get_transient may not be fully wired in pure SHORTINIT; fall back to
    // raw $wpdb if needed.
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
 * Atomic disk write of variant bytes (mirrors REST handler's pattern).
 *
 * @param int    $imageID
 * @param string $filename  basename of the file to write inside uploads dir
 * @param string $raw       binary bytes
 * @return array            ['ok'=>bool, 'path'=>string|null, 'error'=>string|null,
 *                           'bytes_size'=>int, 'idempotent'=>bool]
 */
function wpc_v2_direct_persist_bytes($imageID, $filename, $raw) {
    if (!function_exists('get_attached_file')) {
        // get_attached_file not loaded in SHORTINIT — load post.php on demand.
        // This adds ~5ms but only on inbound write path, not health/probe.
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
