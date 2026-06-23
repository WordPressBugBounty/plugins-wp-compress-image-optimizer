<?php
/**
 * WP Compress — Server-Sent Events (SSE) push for variant-arrived updates.
 *
 * Browser opens an EventSource to /wp-json/wpc/v2/events/{imageID} immediately
 * after compress click. Server long-polls ic_local_variants count every 500 ms
 * and pushes an event on every change so the chip count climbs 1-by-1 (or
 * close to it, bounded by 500 ms poll cadence) without waiting for the
 * heartbeat AJAX's 3 s burst interval.
 *
 * Gated by `wpc_v2_use_sse_events()` (site option + filter). When disabled, the
 * REST route stays registered but client-side never opens the EventSource —
 * zero runtime cost.
 *
 * Trade-offs:
 *   - Holds a PHP-FPM worker for up to 60 s per active compress. Acceptable for
 *     single-image clicks; bulk operations open one EventSource per image
 *     which can exhaust the FPM pool. JS-side guard: only open EventSource on
 *     the foreground click flow, not on bulk.
 *   - nginx default-buffers SSE responses. Sending `X-Accel-Buffering: no`
 *     header + manual flush() after each event neutralises this.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_use_sse_events')) {

/**
 * Feature flag. Default OFF — opt-in via site option:
 *   wp option update wpc_v2_use_sse_events 1
 */
function wpc_v2_use_sse_events()
{
    $opt = get_site_option('wpc_v2_use_sse_events', false);
    return (bool) apply_filters('wpc_v2_use_sse_events', !empty($opt));
}

function wpc_v2_register_sse_route()
{
    register_rest_route('wpc/v2', '/events/(?P<imageID>\d+)', [
        'methods'             => 'GET',
        'callback'            => 'wpc_v2_sse_stream',
        // Reuse the same logged-in admin auth WP REST uses for media library
        // ops. Single-user staging + WP admins on prod both pass; anonymous
        // clients can't open the stream.
        'permission_callback' => function () {
            return current_user_can('upload_files');
        },
        'args' => [
            'imageID' => [
                'validate_callback' => function ($v) { return is_numeric($v) && (int) $v > 0; },
            ],
        ],
    ]);
}
add_action('rest_api_init', 'wpc_v2_register_sse_route');

/**
 * SSE long-poll handler. Holds the connection for up to 60 s, emitting a
 * `data: {count, jpeg, webp, avif, savings}` event whenever the variant count
 * for the given imageID changes. Auto-closes when count stabilises (no change
 * for 8 s) or after the 60 s ceiling.
 *
 * Does NOT call WP_REST_Response — sends raw output. Returns void via exit().
 */
function wpc_v2_sse_stream($request)
{
    $imageID = (int) $request->get_param('imageID');
    if ($imageID <= 0) {
        status_header(400);
        exit;
    }

    // Gate check: if SSE is disabled at the site option level, close
    // immediately. Client-side fallback (heartbeat polling) still drives chip
    // updates. JS will receive `error` event and clean up.
    if (!wpc_v2_use_sse_events()) {
        status_header(503);
        exit;
    }

    // Disable PHP buffering layers.
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', false);
    while (ob_get_level()) { @ob_end_flush(); }

    // SSE headers.
    header('Content-Type: text/event-stream; charset=UTF-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    // Prevents nginx (and most reverse proxies) from buffering the stream
    // end-to-end. Cloudways' default nginx config sometimes ignores this
    // unless paired with the 2KB-padding flush below.
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    // Disable gzip — gzipped SSE responses don't flush until the gzip window
    // fills (typically ~8KB), which makes events arrive in batches.
    header('Content-Encoding: identity');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }

    // Loosen PHP's wall-clock budget for the long-poll.
    @set_time_limit(75);
    @ignore_user_abort(true);

    $start         = time();
    $max_seconds   = 60;
    $idle_close_s  = 8;     // close after no-change for 8 s
    $tick_us       = 150000; // 150 ms — tight enough that chip catches count=1,2,3… individually

    $last_count    = -1;
    $last_change_t = $start;

    // 16 KB padding comment — Cloudways' nginx buffer threshold appears to be
    // higher than the default 4 KB. Empirically the first ~14 events (~1.4 KB)
    // got buffered with a 2 KB pad. Bumping to 16 KB ensures we exceed any
    // realistic FastCGI / nginx / Varnish buffer immediately at connection
    // open, so all subsequent events flush 1-by-1.
    echo ": " . str_repeat(' ', 16384) . "\n\n";
    @flush();
    // Hard-flush trick: write an empty SSE comment + flush twice. Some PHP-FPM
    // configurations require this double-flush to actually push to nginx.
    echo ":\n\n";
    @flush();

    // Initial "open" event so the client knows the stream is live.
    echo "event: ready\ndata: {\"imageID\":" . $imageID . "}\n\n";
    @flush();

    while ((time() - $start) < $max_seconds) {
        if (connection_aborted()) break;

        // Force fresh meta read; another PHP-FPM worker (Phase B callback)
        // wrote to ic_local_variants since we last polled, but the local
        // post_meta cache won't see it without invalidation.
        wp_cache_delete($imageID, 'post_meta');
        $variants = get_post_meta($imageID, 'ic_local_variants', true);

        $count = 0; $jpeg = 0; $webp = 0; $avif = 0;
        if (is_array($variants)) {
            foreach ($variants as $vkey => $ventry) {
                // Skip noImprovement records (no bytes on disk).
                if (!empty($ventry['bg_no_improvement'])) continue;
                if (empty($ventry['size'])) continue;
                $count++;
                if (strpos((string) $vkey, '-avif') !== false)      $avif++;
                elseif (strpos((string) $vkey, '-webp') !== false)  $webp++;
                else                                                 $jpeg++;
            }
        }

        // Read ic_compressing status too — when it flips to 'compressed' the
        // client can swap card state immediately, not wait for the main AJAX.
        $ic_compressing = get_post_meta($imageID, 'ic_compressing', true);
        $status = (is_array($ic_compressing) && !empty($ic_compressing['status']))
            ? (string) $ic_compressing['status']
            : 'optimizing';

        $changed = ($count !== $last_count);
        // Surface the warming sub-state every tick (cheap transient
        // read). When the announce handler eager-flipped to compressed but no
        // bytes have landed yet, warming=true → JS renders a quiet
        // "Finalizing…" pill. Cleared by /bg_swap or /bg_swap_batch on first
        // persist; pill crossfades out on the next SSE tick.
        $warming = (bool) get_transient('wpc_v2_warming_' . $imageID);
        // Fire an event if the warming flag flipped, even when the variant
        // count itself didn't change (e.g. announce flip with no bytes yet,
        // OR bytes landing with no new variant addition because warming was
        // already cleared elsewhere). Keeps the pill visually accurate.
        static $last_warming = -1;
        if ($warming !== ($last_warming === 1)) {
            $changed = true;
            $last_warming = $warming ? 1 : 0;
        }
        if ($changed) {
            $payload = [
                'imageID' => $imageID,
                'count'   => $count,
                'jpeg'    => $jpeg,
                'webp'    => $webp,
                'avif'    => $avif,
                'status'  => $status,
                'warming' => $warming,
                't'       => time(),
            ];
            // When status JUST flipped to compressed, include the
            // rendered compressed-card HTML directly in the SSE payload. The
            // client can swap the card IMMEDIATELY without firing a separate
            // heartbeat AJAX (which adds 500-1500ms of admin-ajax bootstrap
            // latency). This cuts visual flip time from T+~3-4s down to T+~3s
            // (limited only by the SSE 150ms tick).
            //
            // Single-fire — only include html on the FIRST event that sees
            // compressed status (avoids repeated render cost on subsequent
            // count changes).
            static $sent_compressed_html = false;
            if (!$sent_compressed_html && $status === 'compressed') {
                global $wps_ic;
                if (isset($wps_ic) && isset($wps_ic->media_library)
                    && method_exists($wps_ic->media_library, 'compress_details')) {
                    $payload['html'] = $wps_ic->media_library->compress_details($imageID);
                    $sent_compressed_html = true;
                }
            }
            echo "event: variant_count\ndata: " . wp_json_encode($payload) . "\n\n";
            @flush();
            $last_count    = $count;
            $last_change_t = time();
        } else {
            // Heartbeat comment every 5 s of stillness so reverse proxies
            // don't close the connection. SSE clients ignore `:` comments.
            if ((time() - $last_change_t) % 5 === 0) {
                echo ": ping " . time() . "\n\n";
                @flush();
            }
        }

        // Auto-close when count has been stable for $idle_close_s AND status
        // is compressed (drain done from the plugin's view).
        if ($status === 'compressed' && (time() - $last_change_t) >= $idle_close_s) {
            echo "event: done\ndata: {\"count\":" . $count . "}\n\n";
            @flush();
            break;
        }

        usleep($tick_us);
    }

    exit;
}

} // function_exists guard
