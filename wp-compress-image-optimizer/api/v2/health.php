<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: api/v2/health.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */



define('WPC_V2_DIRECT_ENTRY', true);
define('WPC_V2_SKIP_METHOD_GUARD', true);


if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'method_not_allowed';
    exit;
}

require __DIR__ . '/_shared.php';




$submitted = '';
if (isset($_POST['probe_token'])) {
    $submitted = (string) $_POST['probe_token'];
} else {
    
    
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
