<?php
/**
 * WP Compress v7.02 — Direct entry health probe.
 *
 * Verifies the full chain works on this host:
 *   1. PHP execution in plugin dir (i.e. this file ran at all)
 *   2. SHORTINIT WordPress bootstrap (i.e. _shared.php loaded $wpdb)
 *   3. wp_options readable via $wpdb (i.e. probe_token transient round-trips)
 *   4. uploads dir writable (i.e. journal can be created)
 *
 * Called by the plugin on activation + apikey-save + manual re-detect.
 * If all 4 checks pass and the echoed token matches what the plugin set,
 * the plugin advertises this URL pattern as callback.url. If anything fails,
 * the plugin falls back to /wp-json/wpc/v2/* REST endpoints and the user gets
 * the same behavior as before this feature shipped (no regression).
 *
 * Auth: simpler than the data endpoints. The probe POSTs a random token in
 * the body; we read the token, check that the plugin's transient holds the
 * SAME token (proving the request came from the same WP install), and echo
 * the token back. Replay-safe because the token is rotated each probe + only
 * valid for 60s.
 */

define('WPC_V2_DIRECT_ENTRY', true);
define('WPC_V2_SKIP_METHOD_GUARD', true);  // health uses a simpler token, not HMAC

// Method preflight (health-specific — POST only, no HMAC required).
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'method_not_allowed';
    exit;
}

require __DIR__ . '/_shared.php';

// At this point: WordPress is loaded via SHORTINIT, $wpdb is global.

// Read submitted token. Expected as POST body field probe_token.
$submitted = '';
if (isset($_POST['probe_token'])) {
    $submitted = (string) $_POST['probe_token'];
} else {
    // Some environments don't auto-populate $_POST for direct-entry requests.
    // Fall back to parsing the raw body.
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        parse_str($raw, $parsed);
        if (isset($parsed['probe_token'])) $submitted = (string) $parsed['probe_token'];
    }
}

if ($submitted === '' || strlen($submitted) < 8 || strlen($submitted) > 128) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'invalid_token';
    exit;
}

// Read the plugin's expected token from the transient. Plugin set this just
// before firing the probe; 60s TTL.
$expected = '';
if (function_exists('get_transient')) {
    $val = get_transient('wpc_v2_probe_token');
    if (is_string($val)) $expected = $val;
}

if ($expected === '' || !hash_equals($expected, $submitted)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'token_mismatch';
    exit;
}

// Optional deep-check: verify uploads dir is writable. Plugin's URL-selection
// logic only cares about the token echo, but echoing back with a status flag
// lets the plugin store extra diagnostic info.
$journal_ok = wpc_v2_journal_ensure_dir();

// Probe success — echo the token plus a one-line status with the journal
// writability flag. Plugin parses this to decide whether to enable the
// fast path (token match required) AND store the journal_ok flag for the
// admin status line ("✓ active" vs "✓ active but journal blocked → falls back").
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-WPC-Direct-Entry: ok');
header('X-WPC-Journal-Writable: ' . ($journal_ok ? '1' : '0'));
echo $submitted;
exit;
