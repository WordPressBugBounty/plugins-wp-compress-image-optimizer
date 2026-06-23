<?php
/**
 * WP Compress v7.02 — Direct PHP entry for /wpc/v2/bg_swap_announce
 *
 * Announce-only stream: NO bytes, NO disk write, NO journal. Just a transient
 * write per image (wpc_v2_announced_$id). Bulk heartbeat reads it to surface
 * pending pills in the UI before the bytes batch lands.
 *
 * This handler is even simpler than bg_swap_batch:
 *   1. HMAC verify
 *   2. Parse + validate
 *   3. Gap 3 race-guard: discard if variant already in ic_local_variants
 *   4. Append to wpc_v2_announced_$id transient
 *   5. Respond 200
 *
 * Total handler time: 5-30 ms. ~10x faster than the REST equivalent.
 *
 * @see SPEC-direct_entry.md
 * @see SPEC-bg_swap_announce.md
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
    error_log('[wpc_v2_direct_announce] auth_rejected reason=' . $hmac['reason']);
    wpc_v2_direct_respond(401, ['error' => 'auth', 'reason' => $hmac['reason']]);
}

$body = json_decode($body_raw, true);
if (!is_array($body)) {
    wpc_v2_direct_respond(400, ['error' => 'invalid_json']);
}
if (empty($body['items']) || !is_array($body['items'])) {
    wpc_v2_direct_respond(400, ['error' => 'missing_items']);
}
$items  = $body['items'];
$item_n = count($items);
if ($item_n > 25) {
    wpc_v2_direct_respond(413, ['error' => 'batch_too_large', 'max' => 25, 'got' => $item_n]);
}

$imageID = isset($body['imageID']) ? (int) $body['imageID'] : 0;
if ($imageID <= 0) {
    wpc_v2_direct_respond(410, ['error' => 'unknown_image', 'imageID' => $imageID]);
}
$jobId       = isset($body['jobId']) ? (string) $body['jobId'] : '';
$serverTime  = isset($body['serverTime']) ? (int) $body['serverTime'] : 0;
$clockSkewMs = $serverTime > 0 ? (int) round(($entry_t * 1000) - $serverTime) : null;

// Restored-image guard
if (wpc_v2_direct_callbacks_blocked($imageID)) {
    error_log(sprintf(
        '[wpc_v2_direct_announce restored_reject] imageID=%d items=%d job=%s',
        $imageID, $item_n, $jobId !== '' ? substr($jobId, 0, 8) : '-'
    ));
    wpc_v2_direct_respond(410, ['error' => 'image_restored', 'imageID' => $imageID]);
}

// Stale-job (batch-level)
if ($jobId !== '') {
    $pending = function_exists('get_transient')
        ? get_transient('wpc_v2_pending_' . $imageID)
        : null;
    if (is_array($pending) && !empty($pending['jobId']) && (string) $pending['jobId'] !== $jobId) {
        error_log(sprintf(
            '[wpc_v2_direct_announce stale_job] imageID=%d announce_job=%s pending_job=%s items=%d',
            $imageID, substr($jobId, 0, 8), substr((string) $pending['jobId'], 0, 8), $item_n
        ));
        wpc_v2_direct_respond(410, ['error' => 'stale_job']);
    }
}

// Gap 3 guard — read ic_local_variants once. SHORTINIT provides $wpdb but not
// get_post_meta out of the box; load post.php on demand for it.
if (!function_exists('get_post_meta')) {
    require_once ABSPATH . WPINC . '/post.php';
}
$persisted = get_post_meta($imageID, 'ic_local_variants', true);
if (!is_array($persisted)) $persisted = [];

// Variant key helper — keep in sync with wpc_v2_variant_key() in REST handler.
$variant_key = function ($size_label, $format) {
    return $size_label . '-' . $format;
};

// Read current announce transient for append-merge
$announced = function_exists('get_transient')
    ? get_transient('wpc_v2_announced_' . $imageID)
    : null;
if (!is_array($announced)) $announced = [];

$now_ms = (int) round(microtime(true) * 1000);
$now_s  = time();
$results = [];
$announced_count = $discarded_count = $rejected_count = 0;

foreach ($items as $idx => $item) {
    if (!is_array($item)) {
        $results[] = ['ok' => false, 'kind' => 'rejected', 'error' => 'malformed_item', 'index' => $idx];
        $rejected_count++;
        continue;
    }
    $sz  = isset($item['sizeLabel']) ? preg_replace('/[^a-z0-9_\-]/i', '', (string) $item['sizeLabel']) : '';
    $fmt = isset($item['format']) ? strtolower(preg_replace('/[^a-z]/i', '', (string) $item['format'])) : '';
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

    $key = $variant_key($sz, $fmt);

    // Gap 3 — discard if already persisted
    if (isset($persisted[$key])) {
        $results[] = ['ok' => true, 'kind' => 'discarded_already_persisted', 'sizeLabel' => $sz, 'format' => $fmt];
        $discarded_count++;
        continue;
    }

    $orig_size = isset($item['originalSize']) ? (int) $item['originalSize'] : 0;
    $kb        = isset($item['kb']) ? (float) $item['kb'] : 0.0;
    $bytes_est = (int) round($kb * 1024);
    $savings   = ($orig_size > 0 && $bytes_est > 0)
                 ? max(0, (int) round((1 - ($bytes_est / $orig_size)) * 100))
                 : 0;
    $item_ms   = isset($item['ms']) ? (int) $item['ms'] : $now_ms;
    $no_improv = !empty($item['noImprovement']);

    $announced[$key] = [
        'sizeLabel'     => $sz,
        'format'        => $fmt,
        'kb'            => $kb,
        'originalSize'  => $orig_size,
        'bytes_est'     => $bytes_est,
        'savings'       => $savings,
        'noImprovement' => $no_improv,
        'reason'        => isset($item['reason']) ? (string) $item['reason'] : '',
        'announced_ms'  => $item_ms,
        'expires_at'    => $now_s + 30,
    ];
    $results[] = [
        'ok'        => true,
        'kind'      => $no_improv ? 'announced_no_improvement' : 'announced',
        'sizeLabel' => $sz,
        'format'    => $fmt,
    ];
    $announced_count++;
}

// Persist the transient via set_transient (handles 5-min TTL + cleanup)
if (function_exists('set_transient')) {
    set_transient('wpc_v2_announced_' . $imageID, $announced, 5 * MINUTE_IN_SECONDS);
} else {
    // SHORTINIT fallback: raw $wpdb write
    global $wpdb;
    $opt_name = '_transient_wpc_v2_announced_' . $imageID;
    $expires_name = '_transient_timeout_wpc_v2_announced_' . $imageID;
    $value = maybe_serialize($announced);
    $wpdb->replace($wpdb->options, [
        'option_name'  => $opt_name,
        'option_value' => $value,
        'autoload'     => 'no',
    ]);
    $wpdb->replace($wpdb->options, [
        'option_name'  => $expires_name,
        'option_value' => (string) ($now_s + 5 * MINUTE_IN_SECONDS),
        'autoload'     => 'no',
    ]);
}

$total_ms = (microtime(true) - $entry_t) * 1000;

error_log(sprintf(
    '[wpc_v2_bg_swap_announce_timing] direct_entry=yes imageID=%d jobId=%s item_count=%d announced=%d discarded=%d rejected=%d clock_skew_ms=%s total_handler_ms=%.1f',
    $imageID,
    $jobId !== '' ? substr($jobId, 0, 8) : '-',
    $item_n,
    $announced_count,
    $discarded_count,
    $rejected_count,
    $clockSkewMs === null ? 'n/a' : (string) $clockSkewMs,
    max(0.0, $total_ms)
));

wpc_v2_direct_respond(200, [
    'ok'           => true,
    'imageID'      => $imageID,
    'jobId'        => $jobId,
    'direct_entry' => true,
    'summary'      => [
        'announced' => $announced_count,
        'discarded' => $discarded_count,
        'rejected'  => $rejected_count,
    ],
    'results'      => $results,
]);
