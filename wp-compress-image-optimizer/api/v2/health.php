<?php


define('WPC_V2_DIRECT_ENTRY', true);
define('WPC_V2_SKIP_METHOD_GUARD', true);

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


$journal_ok = wpc_v2_journal_ensure_dir();


header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-WPC-Direct-Entry: ok');
header('X-WPC-Journal-Writable: ' . ($journal_ok ? '1' : '0'));
echo $submitted;
exit;
