<?php
/**
 * WP Compress v7.02 — Direct PHP entry for /wpc/v2/bg_swap (legacy single-variant).
 *
 * Same single-variant shape as the original REST endpoint (one callback per
 * sizeLabel/format tuple). Kept alive for:
 *   - Pre-batching encoder pods that may still be in rotation
 *   - On-demand lazy backfill (single-variant POST per the spec)
 *   - Any orch fallback path that doesn't batch
 *
 * Internally, this writes a 1-entry journal file with the same shape the
 * batch handler uses, so drain consolidates them identically.
 *
 * @see SPEC-direct_entry.md
 */

define('WPC_V2_DIRECT_ENTRY', true);
require __DIR__ . '/_shared.php';

$entry_t  = microtime(true);
$body_raw = file_get_contents('php://input');
if (!is_string($body_raw) || $body_raw === '') {
    wpc_v2_direct_respond(400, ['error' => 'empty_body']);
}

$sig_header = isset($_SERVER['HTTP_X_WPC_SIG']) ? (string) $_SERVER['HTTP_X_WPC_SIG'] : '';
$hmac = wpc_v2_direct_verify_hmac($sig_header, $body_raw);
if (!$hmac['ok']) {
    error_log('[wpc_v2_direct_single] auth_rejected reason=' . $hmac['reason']);
    wpc_v2_direct_respond(401, ['error' => 'auth', 'reason' => $hmac['reason']]);
}

$body = json_decode($body_raw, true);
if (!is_array($body)) {
    wpc_v2_direct_respond(400, ['error' => 'invalid_json']);
}

$imageID    = isset($body['imageID']) ? (int) $body['imageID'] : 0;
$size_label = isset($body['sizeLabel']) ? preg_replace('/[^a-z0-9_\-]/i', '', (string) $body['sizeLabel']) : '';
$format     = isset($body['format']) ? strtolower(preg_replace('/[^a-z]/i', '', (string) $body['format'])) : '';
// v7.02.03 — SECURITY: an orch-supplied filename is written to uploads; validate
// it (basename + image-ext + no executable interior segment). Supplied-but-hostile
// → reject; not supplied → derive below.
$filename   = '';
if (isset($body['filename']) && (string) $body['filename'] !== '') {
    $filename = wpc_v2_direct_safe_filename((string) $body['filename']);
    if ($filename === '') {
        wpc_v2_direct_respond(400, ['error' => 'unsafe_filename']);
    }
}
$b64        = isset($body['bytesB64']) ? (string) $body['bytesB64'] : '';
$fetch_url  = isset($body['fetchUrl']) ? (string) $body['fetchUrl'] : '';
$jobId      = isset($body['jobId']) ? (string) $body['jobId'] : '';

if ($imageID <= 0) wpc_v2_direct_respond(410, ['error' => 'unknown_image', 'imageID' => $imageID]);
if ($size_label === '' || $format === '') wpc_v2_direct_respond(400, ['error' => 'missing_fields']);
if (!in_array($format, ['jpeg', 'jpg', 'webp', 'avif'], true)) wpc_v2_direct_respond(400, ['error' => 'invalid_format']);
if ($format === 'jpg') $format = 'jpeg';

if (wpc_v2_direct_callbacks_blocked($imageID)) {
    wpc_v2_direct_respond(410, ['error' => 'image_restored', 'imageID' => $imageID]);
}

if ($jobId !== '') {
    $pending = function_exists('get_transient') ? get_transient('wpc_v2_pending_' . $imageID) : null;
    if (is_array($pending) && !empty($pending['jobId']) && (string) $pending['jobId'] !== $jobId) {
        wpc_v2_direct_respond(410, ['error' => 'stale_job']);
    }
}

if ($filename === '') {
    $filename = wpc_v2_direct_derive_filename($imageID, $size_label, $format);
    if ($filename === '') {
        wpc_v2_direct_respond(400, ['error' => 'filename_derive_failed']);
    }
}

// No-improvement signal
if (!empty($body['noImprovement']) || (isset($body['bumped']) && (string) $body['bumped'] === 'source_already_optimal')) {
    $reason = !empty($body['noImprovement'])
        ? (isset($body['reason']) ? (string) $body['reason'] : 'no_improvement')
        : 'source_already_optimal';
    $journal_entries = [[
        'type'       => 'no_improvement',
        'sizeLabel'  => $size_label,
        'format'     => $format,
        'reason'     => $reason,
        'baselineKb' => isset($body['baselineKb']) ? (float) $body['baselineKb'] : 0.0,
    ]];
    $journal_file = wpc_v2_journal_write($imageID, $jobId, [
        'flush_reason' => 'single',
        'received_ms'  => (int) round($entry_t * 1000),
        'entries'      => $journal_entries,
    ]);
    if ($journal_file === false) {
        wpc_v2_direct_respond(503, ['error' => 'journal_unavailable', 'retry_via' => 'rest']);
    }
    error_log(sprintf(
        '[wpc_v2_bg_swap_single_timing] direct_entry=yes imageID=%d job=%s size=%s fmt=%s kind=%s total_handler_ms=%.1f',
        $imageID, substr($jobId, 0, 8), $size_label, $format, $reason,
        (microtime(true) - $entry_t) * 1000
    ));
    register_shutdown_function('wpc_v2_journal_fire_loopback');
    wpc_v2_direct_respond(200, ['ok' => true, 'kind' => $reason, 'direct_entry' => true]);
}

// Resolve bytes
$raw = null;
if ($b64 !== '') {
    $raw = base64_decode($b64, true);
    if ($raw === false) wpc_v2_direct_respond(400, ['error' => 'invalid_base64']);
} elseif ($fetch_url !== '') {
    // v7.02.03 — SECURITY (SSRF): validate the orch-supplied URL (http/https +
    // public IP only) and disable redirects so a public host can't 30x into an
    // internal target (cloud metadata, loopback, private ranges).
    if (!wpc_v2_direct_safe_fetch_url($fetch_url)) {
        wpc_v2_direct_respond(400, ['error' => 'unsafe_fetch_url']);
    }
    $ctx = stream_context_create(['http' => ['timeout' => 15, 'follow_location' => 0, 'max_redirects' => 0]]);
    $raw = @file_get_contents($fetch_url, false, $ctx);
    if ($raw === false || $raw === '') wpc_v2_direct_respond(502, ['error' => 'fetch_url_failed']);
} else {
    wpc_v2_direct_respond(400, ['error' => 'missing_bytes_or_fetchUrl']);
}

// Atomic disk write
$persist = wpc_v2_direct_persist_bytes($imageID, $filename, $raw);
if (!$persist['ok']) {
    wpc_v2_direct_respond(500, ['error' => $persist['error']]);
}

$journal_entries = [[
    'type'         => !empty($persist['idempotent']) ? 'idempotent_noop' : 'persisted',
    'sizeLabel'    => $size_label,
    'format'       => $format,
    'bytes_path'   => $persist['path'],
    'bytes_size'   => $persist['bytes_size'],
    'originalSize' => isset($body['originalSize']) ? (int) $body['originalSize'] : 0,
    'kb'           => isset($body['kb']) ? (float) $body['kb'] : 0.0,
    'butter'       => isset($body['butter']) ? (float) $body['butter'] : 0.0,
    'q'            => isset($body['q']) ? (int) $body['q'] : 0,
    'bumped'       => isset($body['bumped']) ? (string) $body['bumped'] : '',
    'ms'           => isset($body['ms']) ? (int) $body['ms'] : 0,
]];
$journal_file = wpc_v2_journal_write($imageID, $jobId, [
    'flush_reason' => 'single',
    'received_ms'  => (int) round($entry_t * 1000),
    'entries'      => $journal_entries,
]);
if ($journal_file === false) {
    wpc_v2_direct_respond(503, ['error' => 'journal_unavailable', 'retry_via' => 'rest']);
}

error_log(sprintf(
    '[wpc_v2_bg_swap_single_timing] direct_entry=yes imageID=%d job=%s size=%s fmt=%s bytes=%d kind=%s total_handler_ms=%.1f',
    $imageID, substr($jobId, 0, 8), $size_label, $format, $persist['bytes_size'],
    !empty($persist['idempotent']) ? 'idempotent_noop' : 'persisted',
    (microtime(true) - $entry_t) * 1000
));

// Trigger drain on threshold (less likely with single-variant, but consistent)
$threshold = defined('WPC_V2_JOURNAL_DRAIN_THRESHOLD') ? (int) WPC_V2_JOURNAL_DRAIN_THRESHOLD : 5;
if (wpc_v2_journal_count() >= $threshold) {
    register_shutdown_function('wpc_v2_journal_fire_loopback');
}

wpc_v2_direct_respond(200, [
    'ok'           => true,
    'direct_entry' => true,
    'kind'         => !empty($persist['idempotent']) ? 'idempotent_noop' : 'persisted',
    'bytes'        => $persist['bytes_size'],
]);
