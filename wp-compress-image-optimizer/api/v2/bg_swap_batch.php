<?php


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
    error_log('[wpc_v2_direct_batch] auth_rejected reason=' . $hmac['reason']);
    wpc_v2_direct_respond(401, ['error' => 'auth', 'reason' => $hmac['reason']]);
}


$body = json_decode($body_raw, true);
if (!is_array($body)) {
    wpc_v2_direct_respond(400, ['error' => 'invalid_json']);
}
if (empty($body['variants']) || !is_array($body['variants'])) {
    wpc_v2_direct_respond(400, ['error' => 'missing_variants']);
}
$variants = $body['variants'];
$variant_n = count($variants);
if ($variant_n > 25) {
    wpc_v2_direct_respond(413, ['error' => 'batch_too_large', 'max' => 25, 'got' => $variant_n]);
}

$imageID = isset($body['imageID']) ? (int) $body['imageID'] : 0;
if ($imageID <= 0) {
    wpc_v2_direct_respond(410, ['error' => 'unknown_image', 'imageID' => $imageID]);
}


$jobId      = isset($body['jobId']) ? (string) $body['jobId'] : '';
$serverTime = isset($body['serverTime']) ? (int) $body['serverTime'] : 0;
$clockSkewMs = $serverTime > 0 ? (int) round(($entry_t * 1000) - $serverTime) : null;
$flushReason = isset($body['flush_reason']) ? preg_replace('/[^a-z_]/', '', (string) $body['flush_reason']) : '';

// Wrapper-level fallback fields (JW pod batch shape)
$wrap_size   = isset($body['sizeLabel']) ? preg_replace('/[^a-z0-9_\-]/i', '', (string) $body['sizeLabel']) : '';
$wrap_orig   = isset($body['originalSize']) ? (int) $body['originalSize'] : 0;
$wrap_fname  = isset($body['filename']) ? $body['filename'] : null;


if (wpc_v2_direct_callbacks_blocked($imageID)) {
    error_log(sprintf(
        '[wpc_v2_direct_batch restored_reject] imageID=%d variants=%d job=%s',
        $imageID, $variant_n, $jobId !== '' ? substr($jobId, 0, 8) : '-'
    ));
    wpc_v2_direct_respond(410, ['error' => 'image_restored', 'imageID' => $imageID]);
}


if ($jobId !== '') {
    $pending = function_exists('get_transient')
        ? get_transient('wpc_v2_pending_' . $imageID)
        : null;
    if (is_array($pending) && !empty($pending['jobId']) && (string) $pending['jobId'] !== $jobId) {
        error_log(sprintf(
            '[wpc_v2_direct_batch stale_job] imageID=%d batch_job=%s pending_job=%s variants=%d',
            $imageID, substr($jobId, 0, 8), substr((string) $pending['jobId'], 0, 8), $variant_n
        ));
        wpc_v2_direct_respond(410, ['error' => 'stale_job', 'cb_jobId' => $jobId, 'pending_jobId' => (string) $pending['jobId']]);
    }
}

$t_after_validate = microtime(true);


$journal_entries  = [];
$results          = [];
$persisted_count  = $rejected_count = $duplicate_count = 0;

foreach ($variants as $idx => $v) {
    if (!is_array($v)) {
        $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'malformed_item', 'index' => $idx];
        $rejected_count++;
        continue;
    }

    $sz  = isset($v['sizeLabel']) ? preg_replace('/[^a-z0-9_\-]/i', '', (string) $v['sizeLabel']) : $wrap_size;
    $fmt = isset($v['format']) ? strtolower(preg_replace('/[^a-z]/i', '', (string) $v['format'])) : '';
    if ($sz === '' || $fmt === '') {
        $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'missing_size_or_format', 'index' => $idx];
        $rejected_count++;
        continue;
    }
    if (!in_array($fmt, ['jpeg', 'jpg', 'webp', 'avif'], true)) {
        $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_format', 'sizeLabel' => $sz, 'format' => $fmt];
        $rejected_count++;
        continue;
    }
    if ($fmt === 'jpg') $fmt = 'jpeg';

    // No-improvement: record without bytes write.
    if (!empty($v['noImprovement']) || (isset($v['bumped']) && (string) $v['bumped'] === 'source_already_optimal')) {
        $reason = !empty($v['noImprovement'])
            ? (isset($v['reason']) ? (string) $v['reason'] : 'no_improvement')
            : 'source_already_optimal';
        $journal_entries[] = [
            'type'       => 'no_improvement',
            'sizeLabel'  => $sz,
            'format'     => $fmt,
            'reason'     => $reason,
            'baselineKb' => isset($v['baselineKb']) ? (float) $v['baselineKb'] : 0.0,
        ];
        $results[] = ['ok' => true, 'kind' => $reason === 'source_already_optimal' ? 'source_already_optimal' : 'no_improvement', 'sizeLabel' => $sz, 'format' => $fmt];
        $persisted_count++;
        continue;
    }

    // Filename resolution


    $filename = '';
    $supplied_fn = null;
    if (isset($v['filename']) && is_string($v['filename']) && $v['filename'] !== '') {
        $supplied_fn = (string) $v['filename'];
    } elseif (is_array($wrap_fname) && isset($wrap_fname[$fmt]) && (string) $wrap_fname[$fmt] !== '') {
        $supplied_fn = (string) $wrap_fname[$fmt];
    }
    if ($supplied_fn !== null) {
        $filename = wpc_v2_direct_safe_filename($supplied_fn);
        if ($filename === '') {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'unsafe_filename', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
    }
    if ($filename === '') {
        $filename = wpc_v2_direct_derive_filename($imageID, $sz, $fmt);
        if ($filename === '') {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'filename_derive_failed', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
    }

    // Bytes
    $b64       = isset($v['bytesB64']) ? (string) $v['bytesB64'] : '';
    $fetch_url = isset($v['fetchUrl']) ? (string) $v['fetchUrl'] : '';
    $raw = null;
    if ($b64 !== '') {
        $raw = base64_decode($b64, true);
        if ($raw === false) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'invalid_base64', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
    } elseif ($fetch_url !== '') {


        if (!wpc_v2_direct_safe_fetch_url($fetch_url)) {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'unsafe_fetch_url', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 15, 'follow_location' => 0, 'max_redirects' => 0]]);
        $raw = @file_get_contents($fetch_url, false, $ctx);
        if ($raw === false || $raw === '') {
            $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'fetch_url_failed', 'sizeLabel' => $sz, 'format' => $fmt];
            $rejected_count++;
            continue;
        }
    } else {
        $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'missing_bytes_or_fetchUrl', 'sizeLabel' => $sz, 'format' => $fmt];
        $rejected_count++;
        continue;
    }

    // Atomic disk write
    $persist = wpc_v2_direct_persist_bytes($imageID, $filename, $raw);
    if (!$persist['ok']) {
        $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => $persist['error'], 'sizeLabel' => $sz, 'format' => $fmt];
        $rejected_count++;
        continue;
    }
    if (!empty($persist['idempotent'])) {
        $journal_entries[] = [
            'type'      => 'idempotent_noop',
            'sizeLabel' => $sz,
            'format'    => $fmt,
        ];
        $results[] = ['ok' => true, 'kind' => 'idempotent_noop', 'sizeLabel' => $sz, 'format' => $fmt];
        $duplicate_count++;
        continue;
    }

    // Build the journal entry (drain merges into ic_local_variants)
    $orig_size = isset($v['originalSize']) ? (int) $v['originalSize']
                : (isset($v['orig_size']) ? (int) $v['orig_size'] : $wrap_orig);
    $kb        = isset($v['kb']) ? (float) $v['kb'] : 0.0;
    $butter    = isset($v['butter']) ? (float) $v['butter'] : 0.0;

    $journal_entries[] = [
        'type'         => 'persisted',
        'sizeLabel'    => $sz,
        'format'       => $fmt,
        'bytes_path'   => $persist['path'],
        'bytes_size'   => $persist['bytes_size'],
        'originalSize' => $orig_size,
        'kb'           => $kb,
        'butter'       => $butter,
        'q'            => isset($v['q']) ? (int) $v['q'] : 0,
        'bumped'       => isset($v['bumped']) ? (string) $v['bumped'] : '',
        'ms'           => isset($v['ms']) ? (int) $v['ms'] : 0,
    ];
    $results[] = ['ok' => true, 'kind' => 'persisted', 'sizeLabel' => $sz, 'format' => $fmt, 'bytes' => $persist['bytes_size']];
    $persisted_count++;
}

$t_after_loop = microtime(true);

// ─── Write journal entries (one file per batch) ───────────────────────────
$journal_file = null;
if (!empty($journal_entries)) {
    $journal_file = wpc_v2_journal_write($imageID, $jobId, [
        'flush_reason' => $flushReason,
        'received_ms'  => (int) round($entry_t * 1000),
        'entries'      => $journal_entries,
    ]);
    if ($journal_file === false) {


        error_log(sprintf(
            '[wpc_v2_direct_batch journal_write_failed] imageID=%d job=%s persisted=%d',
            $imageID, $jobId !== '' ? substr($jobId, 0, 8) : '-', $persisted_count
        ));
        wpc_v2_direct_respond(503, ['error' => 'journal_unavailable', 'retry_via' => 'rest']);
    }
}

$t_handler_end = microtime(true);

// ─── Timing log (matches REST handler's format for grep correlation) ──────
//


error_log(sprintf(
    '[wpc_v2_bg_swap_batch_timing] direct_entry=yes imageID=%d jobId=%s variant_count=%d persisted=%d rejected=%d duplicates=%d flush_reason=%s clock_skew_ms=%s validate_ms=%.1f loop_ms=%.1f journal_file=%s total_handler_ms=%.1f',
    $imageID,
    $jobId !== '' ? substr($jobId, 0, 8) : '-',
    $variant_n,
    $persisted_count,
    $rejected_count,
    $duplicate_count,
    $flushReason !== '' ? $flushReason : '-',
    $clockSkewMs === null ? 'n/a' : (string) $clockSkewMs,
    max(0.0, ($t_after_validate - $entry_t) * 1000),
    max(0.0, ($t_after_loop - $t_after_validate) * 1000),
    $journal_file ? basename($journal_file) : '-',
    max(0.0, ($t_handler_end - $entry_t) * 1000)
));


$response = [
    'ok'            => true,
    'imageID'       => $imageID,
    'jobId'         => $jobId,
    'direct_entry'  => true,
    'summary'       => [
        'persisted'  => $persisted_count,
        'rejected'   => $rejected_count,
        'duplicates' => $duplicate_count,
    ],
    'results'       => $results,
];


$threshold = defined('WPC_V2_JOURNAL_DRAIN_THRESHOLD') ? (int) WPC_V2_JOURNAL_DRAIN_THRESHOLD : 5;
if (wpc_v2_journal_count() >= $threshold) {


    register_shutdown_function('wpc_v2_journal_fire_loopback');
}

wpc_v2_direct_respond(200, $response);
