<?php

/**
 * The one door every image compression goes through on its way to the image service.
 *
 * Twelve entry points used to reach the service: ten called wps_ic_ajax::run_v2_optimize()
 * directly, the sized-rung trigger POSTed /v2/sized-trigger on its own and `wp wpcompress
 * v2-test` built a WPS_LocalV2 with a hand-made envelope. Each checked a different subset of
 * the gates (the attempt admission, the in-flight lock, the parked list, the landing journal),
 * so the same image could be refused on one path and dispatched on the next: the bulk drain
 * asked the attempt admission, the upload queue and the Compress button did not; the lazy
 * trigger asked the journal, the bulk drain did not (review 2026-09-26, B-F3).
 *
 *   dispatch($id, $reason, $opts)        one image to /optimize-v2
 *   dispatch_batch($opts_by_id, $reason) several images to /optimize-v2 in parallel (the bulk drain)
 *   dispatch_sized($items)               rung fills to /v2/sized-trigger
 *   refusal($id, $reason)                the gate an image would be refused by, or null; no receipt
 *   admit($id, $reason)                  the same question for a caller that acts on it: a refusal
 *                                        leaves its receipt
 *
 * THE GATES, per reason (self::GATES). An automatic dispatch (an upload, the lazy trigger, the
 * ladder, the sized-rung trigger) is refused while an earlier outcome for the image is still
 * unaccounted: the attempt back-off or cap, a lock held by a dispatch in this site, a job the
 * service has not answered yet, the parked list, callbacks waiting in the journal, a restore in
 * progress. A person asking (the Compress button, an edit in the image editor, the CLI) is the
 * retry the back-off waits for, so those reasons pass every gate but the lock (and the two
 * source gates every reason asks first: no attachment, or no file on disk). The bulk run is
 * started by a person too: it passes the journal gate (its receipt counts the waiting files as
 * journal_pending) and keeps the others, because it runs over the whole library unattended.
 * Observed: callbacks left in the journal by a service 429 window, whose fetch_url no longer
 * answered, refused 51 of 51 images of a bulk run while the Compress click on the same images
 * was sent (rig, 2026-09-26).
 *
 * Receipts: `image-dispatch {id, src, level}` when a request goes out, `image-dispatch-refused
 * {id, reason, src}` when a gate refuses it, and `image-dispatch {id, src, outcome:deduplicated,
 * dedupe, job, pairs}` when the service answers that it already encoded every variant asked for
 * (see answered()). `image-service-hold {reason, until, retry_after, http, id, src}` when the
 * service refuses the site rather than the image (see hold_on_refusal()), and
 * `image-service-hold-cleared {reason, id, src}` when a later request is accepted.
 */
class wps_ic_image_optimize
{
    const LOCK_STALE_SECONDS = 300;

    /** The send outcome of an image the service answered with its duplicate answer. */
    const DEDUPLICATED = 'deduplicated';

    /**
     * The site-wide hold the service's own refusals set: ['reason', 'until', 'since', 'http',
     * 'retry_after']. One option, one writer (hold_on_refusal()), one reader (service_hold()).
     */
    const SERVICE_HOLD_OPTION = 'wpc_v2_service_hold';

    /** How long a 401 invalid_apikey holds automatic dispatches before the next try. */
    const INVALID_KEY_HOLD_SECONDS = 3600;

    /**
     * reason => the gates it asks, in the order they are asked. Every reason asks first whether
     * there is an image to compress: `not-attachment` (the id is no attachment any more) and
     * `source-missing` (neither its scaled nor its unscaled file is on disk,
     * wps_ic_image_library::source_state()). Rule: a missing file is not a failed attempt; it
     * is refused without counting one, and a vanished id also leaves the parked list and its
     * attempt record. Observed (ticket 12006): old uploads whose files were gone were retried
     * as "backup failed" on every bulk run until parked for seven days, and two deleted ids
     * were still processed by the bulk run.
     */
    const GATES = [
        'upload'        => ['not-attachment', 'source-missing', 'service-hold', 'attempts', 'in-flight', 'parked', 'journal', 'job-pending', 'restoring'],
        'bulk'          => ['not-attachment', 'source-missing', 'service-hold', 'attempts', 'in-flight', 'parked', 'job-pending', 'restoring'],
        'lazy'          => ['not-attachment', 'source-missing', 'service-hold', 'attempts', 'in-flight', 'parked', 'journal', 'job-pending', 'restoring'],
        // Parked pending decision D5 (delete the Modern Delivery ladder, modern-delivery.php in
        // scope): its remote leg is refused before any request. Observed: routing it through this
        // door turned a POST to the dead v1 /optimize route into real /optimize-v2 traffic nobody
        // asked for or measured; the service builds WordPress sub-sizes, not the ladder's widths,
        // so the rung stays missing and the ladder asks again (review 2026-09-26, finding 5).
        'ladder'        => ['not-attachment', 'source-missing', 'ladder-retired'],
        'sized-trigger' => ['not-attachment', 'source-missing', 'parked', 'journal', 'restoring'],
        'ml-button'     => ['not-attachment', 'source-missing', 'in-flight'],
        'edit'          => ['not-attachment', 'source-missing', 'in-flight'],
        'cli'           => ['not-attachment', 'source-missing', 'in-flight'],
    ];

    /**
     * Dispatch one image. Answers the dispatch result: ['ok' => true, 'jobId', 'wall_ms',
     * 'variants_written'] or ['ok' => false, 'error', ...]. A gate refusal also carries
     * 'refused' => true and 'reason' (the gate); the lock's refusal keeps the error name
     * 'already_in_flight' its callers branch on.
     */
    public static function dispatch($id, $reason, array $opts = [])
    {
        $id = (int) $id;
        $reason = (string) $reason;
        $gate = self::refusal($id, $reason);
        if ($gate !== null) {
            return self::refuse($id, $reason, $gate);
        }
        if (!self::take_lock($id)) {
            return self::refuse($id, $reason, 'in-flight');
        }
        try {
            $prep = self::prepare($id, $reason, $opts);
            if (empty($prep['ok'])) {
                return $prep;
            }
            $t0 = $prep['t0'];
            $result = $prep['client']->optimize($id, $prep['variants'], $prep['options']);
            $result = self::resend_scaled($id, $reason, $prep, $result);
            $wall_ms = (int) round((microtime(true) - $t0) * 1000);
            self::after_answer($id, $reason, $result);
            if (empty($result['ok'])) {
                self::failed($id, $reason, $result);
                return [
                    'ok'        => false,
                    'error'     => $result['error'] ?? 'optimize_failed',
                    'detail'    => $result['detail'] ?? '',
                    'http_code' => $result['http_code'] ?? 0,
                    'wall_ms'   => $wall_ms,
                ];
            }
            if (self::answered($id, $reason, $result) === self::DEDUPLICATED) {
                // The variants come back through the manifest pull the service woke; a bulk
                // batch fires the drain after the batch, a single dispatch fires it here.
                if (function_exists('wpc_v2_pull_enabled') && wpc_v2_pull_enabled() && function_exists('wpc_v2_pull_drain_fire')) {
                    wpc_v2_pull_drain_fire();
                }
                return [
                    'ok'               => true,
                    'outcome'          => self::DEDUPLICATED,
                    'jobId'            => $result['jobId'] ?? '',
                    'wall_ms'          => $wall_ms,
                    'variants_written' => [],
                ];
            }
            return [
                'ok'               => true,
                'jobId'            => $result['jobId'] ?? '',
                'wall_ms'          => $wall_ms,
                'variants_written' => $result['write']['variants_written'] ?? [],
            ];
        } finally {
            delete_option(self::lock_key($id));
        }
    }

    /**
     * Dispatch several images in one parallel round (the bulk drain). $opts_by_id maps an
     * attachment id to its dispatch options. Answers ['sent' => [ids], 'refused' => [id => gate],
     * 'failed' => [id => error], 'deduplicated' => [ids]]; a deduplicated image is also in
     * 'sent' (the service answered it; its variants come back through the manifest pull).
     */
    public static function dispatch_batch(array $opts_by_id, $reason)
    {
        $reason = (string) $reason;
        $out = ['sent' => [], 'refused' => [], 'failed' => [], 'deduplicated' => []];
        $preps = [];
        try {
            foreach ($opts_by_id as $id => $opts) {
                $id = (int) $id;
                $gate = self::refusal($id, $reason);
                if ($gate === null && !self::take_lock($id)) {
                    $gate = 'in-flight';
                }
                if ($gate !== null) {
                    self::refuse($id, $reason, $gate);
                    $out['refused'][$id] = $gate;
                    continue;
                }
                $prep = self::prepare($id, $reason, is_array($opts) ? $opts : []);
                if (empty($prep['ok'])) {
                    delete_option(self::lock_key($id));
                    $out['failed'][$id] = (string) ($prep['error'] ?? 'prepare_failed');
                    continue;
                }
                $preps[$id] = $prep;
            }
            if (!empty($preps)) {
                foreach (self::send_batch($preps, $reason) as $id => $error) {
                    if ($error === '' || $error === self::DEDUPLICATED) {
                        $out['sent'][] = (int) $id;
                        if ($error === self::DEDUPLICATED) {
                            $out['deduplicated'][] = (int) $id;
                        }
                    } else {
                        $out['failed'][(int) $id] = $error;
                    }
                }
            }
        } finally {
            foreach (array_keys($preps) as $id) {
                delete_option(self::lock_key((int) $id));
            }
        }
        return $out;
    }

    /**
     * Send rung fills to /v2/sized-trigger. Each item names an origin URL and a rung; the
     * per-attachment gates were asked when the item was queued (refusal($id, 'sized-trigger')).
     * Answers the wp_remote_post() response.
     */
    public static function dispatch_sized(array $items)
    {
        $apikey = function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '';
        $orch   = function_exists('wpc_v2_orchestrator_url') ? (string) wpc_v2_orchestrator_url() : '';
        if ($apikey === '' || $orch === '') {
            return new WP_Error('wpc_no_service', 'no apikey or orchestrator url');
        }
        $body_raw = wp_json_encode(['apikey' => $apikey, 'items' => array_values($items)]);
        if ($body_raw === false) {
            return new WP_Error('wpc_encode', 'items do not encode');
        }
        $ts  = time();
        $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-dispatch', '', '', ['src' => 'sized-trigger', 'n' => count($items)]);
        }
        return wp_remote_post(rtrim($orch, '/') . '/v2/sized-trigger', [
            'timeout'   => 8,
            'blocking'  => true,
            'sslverify' => true,
            'headers'   => [
                'Content-Type' => 'application/json',
                'X-WPC-Sig'    => 't=' . $ts . ',v1=' . $sig,
                'User-Agent'   => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?'),
            ],
            'body' => $body_raw,
        ]);
    }

    /**
     * The first gate that refuses this image for this reason, or null when every gate passes.
     * Logs no receipt: a caller that only asks (the lazy trigger before it fires its loopback,
     * the bulk drain before it takes a backup) decides what to log; dispatch() logs.
     */
    public static function refusal($id, $reason)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return 'invalid-id';
        }
        if (!isset(self::GATES[$reason])) {
            return 'unknown-reason';
        }
        foreach (self::GATES[$reason] as $gate) {
            if (self::gate_refuses($id, $gate)) {
                return $gate === 'attempts' ? self::$attempt_verdict : $gate;
            }
        }
        return null;
    }

    /**
     * Ask the gates before work that precedes the dispatch (the bulk drain backs an image up
     * first): null when admitted, else the refusing gate, with its image-dispatch-refused
     * receipt. Observed: the bulk drain asked refusal() and only wrote an error_log line, so
     * 51 + 49 refusals on the rig left no receipt and the run read as done (2026-09-26).
     */
    public static function admit($id, $reason)
    {
        $gate = self::refusal($id, $reason);
        if ($gate !== null) {
            self::refuse($id, (string) $reason, $gate);
        }
        return $gate;
    }

    /** The attempt admission's own verdict ('backoff_wait', 'parked_attempt_cap'), kept for the receipt. */
    private static $attempt_verdict = 'attempts';

    private static function gate_refuses($id, $gate)
    {
        switch ($gate) {
            case 'ladder-retired':
                return true;
            case 'service-hold':
                return self::service_hold() !== null;
            case 'not-attachment':
                return get_post_type($id) !== 'attachment';
            case 'source-missing':
                $state = wps_ic_image_library::source_state($id);
                wps_ic_image_library::seen_source($id, $state);
                return $state === 'missing';
            case 'attempts':
                $verdict = function_exists('wpc_v2_admit_optimize_attempt') ? wpc_v2_admit_optimize_attempt($id) : true;
                self::$attempt_verdict = is_string($verdict) ? $verdict : 'attempts';
                return $verdict !== true;
            case 'in-flight':
                $held = (int) get_option(self::lock_key($id), 0);
                return $held > 0 && (time() - $held) <= self::LOCK_STALE_SECONDS;
            case 'parked':
                return function_exists('wpc_v2_parked_list') && in_array($id, array_map('intval', (array) wpc_v2_parked_list()), true);
            case 'journal':
                return function_exists('wpc_v2_journal_has_image') && wpc_v2_journal_has_image($id);
            case 'job-pending':
                // A job the service accepted and has not answered: the dispatch that owns it
                // lands the result through the pull or the callback. Observed: the bulk run
                // re-dispatched images a click or the lazy trigger had in flight, burning an
                // attempt and doubling the service's work (the bulk drain's in-flight skip).
                wp_cache_delete($id, 'post_meta');
                $pending = get_transient('wpc_v2_pending_' . $id);
                if (is_array($pending) && !empty($pending['pending'])) {
                    return true;
                }
                $state = get_post_meta($id, 'ic_compressing', true);
                $status = is_array($state) && !empty($state['status']) ? (string) $state['status'] : '';
                $since = is_array($state) ? (int) ($state['time'] ?? 0) : 0;
                return ($status === 'optimizing' || $status === 'queueing')
                    && $since > 0 && (time() - $since) < (int) apply_filters('wpc_bulk_inflight_grace', 900);
            case 'restoring':
                return (bool) get_transient('wpc_restoring_' . $id);
        }
        return false;
    }

    /**
     * The service's site-wide hold, or null when none is running. An automatic dispatch (upload,
     * lazy) and the bulk run wait it out; a person's click, an edit and the CLI pass it, and an
     * accepted answer to one of them ends it (after_answer()).
     */
    public static function service_hold()
    {
        $hold = get_option(self::SERVICE_HOLD_OPTION, null);
        if (!is_array($hold) || (int) ($hold['until'] ?? 0) <= time()) {
            return null;
        }
        return $hold;
    }

    /**
     * What the site does with the service's answer beyond this image: a refusal of the site sets
     * the hold (hold_on_refusal()); a request the service accepted (a 2xx, whatever this site
     * then made of the answer) ends a hold that is running.
     */
    private static function after_answer($id, $reason, array $result)
    {
        $code = (int) ($result['http_code'] ?? (empty($result['ok']) ? 0 : 200));
        if (empty($result['ok']) && ($code < 200 || $code > 299)) {
            self::hold_on_refusal($id, $reason, $result);
            return;
        }
        $hold = get_option(self::SERVICE_HOLD_OPTION, null);
        if (is_array($hold)) {
            delete_option(self::SERVICE_HOLD_OPTION);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('image-service-hold-cleared', (string) $id, '', ['reason' => (string) ($hold['reason'] ?? ''), 'id' => (int) $id, 'src' => (string) $reason]);
            }
        }
    }

    /**
     * The service refused the site, not the image: a 429 (`pool_full`, its encoders are out of
     * capacity) or a 401 (`invalid_apikey`, the key has no site row at the service). Automatic
     * dispatches and the bulk run then wait: a 429 for its Retry-After (10 to 600 s, 30 s when it
     * names none), a 401 for an hour. The attempt this request counted is given back, because the
     * image did nothing wrong. The site's key is never touched.
     *
     * Rule: a service refusal of the site is one site-wide wait, not a failure of each image.
     * Observed: a 429 answered each image of a bulk batch, the drain dequeued and sent the next
     * batch at once and counted an attempt on every image, so four 429 windows parked the whole
     * library for seven days; the service asks for its Retry-After to be honoured (orchestrator
     * v3.24.143 (H), server.js "honest 429"). The 401 comes when the service switches
     * OPTIMIZE_V2_AUTH to enforce, and then only for a key unknown to it for over an hour; the
     * service asks that a 401 never wipe a working key on its own (hub ask 047, item 6).
     */
    private static function hold_on_refusal($id, $reason, array $result)
    {
        $error = (string) ($result['error'] ?? '');
        if ($error === 'pool_full') {
            $retry_after = (int) ($result['retry_after'] ?? 0);
            $seconds = $retry_after > 0 ? max(10, min(600, $retry_after)) : 30;
        } elseif ($error === 'invalid_apikey') {
            $retry_after = 0;
            $seconds = self::INVALID_KEY_HOLD_SECONDS;
        } else {
            return;
        }
        if (in_array('attempts', self::GATES[$reason] ?? [], true)) {
            self::refund_attempt($id);
        }
        $prev = get_option(self::SERVICE_HOLD_OPTION, null);
        $until = time() + $seconds;
        $running = is_array($prev) && (int) ($prev['until'] ?? 0) > time() && ($prev['reason'] ?? '') === $error;
        if ($running && (int) $prev['until'] >= $until) {
            return;   // the hold already covers this answer (the other images of the same batch)
        }
        update_option(self::SERVICE_HOLD_OPTION, [
            'reason'      => $error,
            'until'       => $until,
            'since'       => $running ? (int) ($prev['since'] ?? time()) : time(),
            'http'        => (int) ($result['http_code'] ?? 0),
            'retry_after' => $retry_after,
        ], false);
        if (!$running && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-service-hold', (string) $id, '', [
                'reason' => $error, 'until' => $until, 'retry_after' => $retry_after,
                'http' => (int) ($result['http_code'] ?? 0), 'id' => (int) $id, 'src' => (string) $reason,
            ]);
        }
    }

    /** Give back the attempt prepare() counted for this request (wpc_v2_bump_attempts()). */
    private static function refund_attempt($id)
    {
        $a = get_post_meta($id, 'ic_v2_attempts', true);
        $n = is_array($a) ? (int) ($a['n'] ?? 0) : 0;
        if ($n <= 1) {
            delete_post_meta($id, 'ic_v2_attempts');
            return;
        }
        $a['n'] = $n - 1;
        update_post_meta($id, 'ic_v2_attempts', $a);
    }

    /**
     * A 413 whose answer carries `use_scaled: true` is sent once more with WordPress's `-scaled`
     * copy as the source. Answers the second result, or the first when there is no scaled copy
     * or the request already used it.
     *
     * Rule: the service encodes no original above its `max_source_mp` and never changes a
     * customer original's dimensions; it asks for the copy WordPress made (orchestrator
     * v3.24.146, 413 `source_too_large` / `source_too_large_mp` with `use_scaled`; hub ask 047,
     * item 5). Observed: the 413 failed the image and counted an attempt, and every retry sent
     * the same unscaled source until the image was parked.
     */
    private static function resend_scaled($id, $reason, array $prep, array $result)
    {
        if (!empty($result['ok']) || (int) ($result['http_code'] ?? 0) !== 413 || empty($result['use_scaled'])
            || !empty($prep['options']['use_scaled_source'])) {
            return $result;
        }
        $scaled = (string) get_attached_file($id);
        $original = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($id) : '';
        if ($scaled === '' || $scaled === $original || !is_file($scaled)) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('image-dispatch-refused', (string) $id, '', ['id' => (int) $id, 'reason' => 'no-scaled-copy', 'src' => (string) $reason, 'http' => 413]);
            }
            return $result;
        }
        $options = $prep['options'];
        $options['use_scaled_source'] = true;
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-dispatch', (string) $id, '', ['id' => (int) $id, 'src' => (string) $reason, 'source' => 'scaled', 'after' => (string) ($result['service_error'] ?? 'source_too_large')]);
        }
        return $prep['client']->optimize($id, $prep['variants'], $options);
    }

    private static function lock_key($id)
    {
        return 'wpc_v2_inflight_' . (int) $id;
    }

    /** add_option is the atomic test-and-set; a lock older than LOCK_STALE_SECONDS belonged to a dead worker. */
    private static function take_lock($id)
    {
        $key = self::lock_key($id);
        if (add_option($key, time(), '', 'no')) {
            return true;
        }
        $held = (int) get_option($key, 0);
        if ($held > 0 && (time() - $held) <= self::LOCK_STALE_SECONDS) {
            return false;
        }
        delete_option($key);
        return (bool) add_option($key, time(), '', 'no');
    }

    /**
     * The receipt of a request the service did not accept: `image-dispatch-failed {id, src, error,
     * http_code, body_snippet}`, the snippet at most 160 characters of the answer (or of the
     * transport error), with the site's key and anything key-shaped masked. Rule: every failed
     * /optimize-v2 request leaves its answer in the journal, from this one place. Observed
     * (ticket 12006, finde-online.de): 16 `orchestrator_error` refusals whose HTTP code and body
     * were only in PHP's error_log, which the site owner could not read.
     */
    private static function failed($id, $reason, array $result)
    {
        if (!function_exists('wpc_cache_first_log')) {
            return;
        }
        $body = $result['body'] ?? null;
        if (!is_string($body) || $body === '') {
            $body = isset($result['parsed']) && is_array($result['parsed']) ? (string) wp_json_encode($result['parsed']) : (string) ($result['detail'] ?? '');
        }
        wpc_cache_first_log('image-dispatch-failed', (string) $id, '', [
            'id'           => (int) $id,
            'src'          => (string) $reason,
            'error'        => (string) ($result['error'] ?? 'unknown'),
            'http_code'    => (int) ($result['http_code'] ?? 0),
            'body_snippet' => self::snippet($body),
        ]);
    }

    /** At most 160 characters of an answer, on one line, with every key-shaped value masked. */
    private static function snippet($text)
    {
        $text = (string) $text;
        $key = function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '';
        if ($key !== '') {
            $text = str_replace($key, '[key]', $text);
        }
        $text = (string) preg_replace('/("?(?:api_?key|apikey|token|secret|sig|signature|password)"?\s*[:=]\s*"?)[^"&,;\s}]+/i', '$1[redacted]', $text);
        $text = (string) preg_replace('/\b[0-9a-f]{32,}\b/i', '[key]', $text);
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        return function_exists('mb_substr') ? mb_substr($text, 0, 160) : substr($text, 0, 160);
    }

    private static function refuse($id, $reason, $gate)
    {
        if ($gate === 'not-attachment' && function_exists('wpc_v2_reset_attempts')) {
            wpc_v2_reset_attempts($id);   // off the parked list, no attempt record: nothing will ever retry it
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-dispatch-refused', (string) $id, '', ['id' => (int) $id, 'reason' => (string) $gate, 'src' => (string) $reason]);
        }
        return [
            'ok'      => false,
            'refused' => true,
            'reason'  => (string) $gate,
            'error'   => $gate === 'in-flight' ? 'already_in_flight' : (string) $gate,
            'imageID' => (int) $id,
        ];
    }

    /**
     * Count the attempt (automatic reasons), build the envelope and log the dispatch. The
     * envelope and its meta writes are wps_ic_ajax::prepare_v2_optimize()'s.
     */
    private static function prepare($id, $reason, array $opts)
    {
        if (!class_exists('wps_ic_ajax') || !method_exists('wps_ic_ajax', 'prepare_v2_optimize')) {
            return ['ok' => false, 'error' => 'handler-unavailable'];
        }
        if (in_array('attempts', self::GATES[$reason], true) && function_exists('wpc_v2_bump_attempts')) {
            $why = isset($opts['resubmit_reason']) && $opts['resubmit_reason'] !== '' ? (string) $opts['resubmit_reason'] : 'new';
            $n = (int) wpc_v2_bump_attempts($id, $why);
            $opts['attempt'] = $n;
            if (!isset($opts['resubmit_reason']) || $opts['resubmit_reason'] === '') {
                $opts['resubmit_reason'] = $n > 1 ? 'retry_' . $n : 'new';
            }
        }
        $prep = wps_ic_ajax::prepare_v2_optimize($id, $opts);
        if (empty($prep['ok'])) {
            return $prep;
        }
        $variants_count = is_array($prep['variants']) ? count($prep['variants']) : 0;
        $formats = isset($prep['options']['formats']) && is_array($prep['options']['formats'])
            ? $prep['options']['formats']
            : ['jpeg', 'webp', 'avif'];
        if ($variants_count > 0 && function_exists('wpc_v2_ic_compressing_set_expected')) {
            wpc_v2_ic_compressing_set_expected($id, $variants_count * max(1, count($formats)));
        }
        if (function_exists('wpc_cache_first_log')) {
            $receipt = ['id' => (int) $id, 'src' => (string) $reason, 'level' => (string) ($prep['options']['level'] ?? '')];
            // A reason that passes the journal gate says what it passed: the callbacks still
            // waiting for this image when it was sent anyway.
            if (!in_array('journal', self::GATES[$reason], true) && function_exists('wpc_v2_journal_image_files')) {
                $pending = count(wpc_v2_journal_image_files($id));
                if ($pending > 0) {
                    $receipt['journal_pending'] = $pending;
                }
            }
            wpc_cache_first_log('image-dispatch', (string) $id, '', $receipt);
        }
        return $prep;
    }

    /**
     * The send outcome of an accepted answer: '' for a job, self::DEDUPLICATED for the service's
     * duplicate answer, which leaves its receipt. Rule: an image the service answered is sent,
     * whatever it answered; the duplicate answer names no refusal. Observed failure: two re-sent
     * images answered that way were counted as `write_failed` refusals (rig, 2026-09-26).
     */
    private static function answered($id, $reason, array $result)
    {
        if (($result['outcome'] ?? '') !== self::DEDUPLICATED) {
            return '';
        }
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-dispatch', (string) $id, '', [
                'id'      => (int) $id,
                'src'     => (string) $reason,
                'outcome' => self::DEDUPLICATED,
                'dedupe'  => (string) ($result['dedupe'] ?? ''),
                'job'     => (string) ($result['jobId'] ?? ''),
                'pairs'   => (int) ($result['pairs'] ?? 0),
            ]);
        }
        return self::DEDUPLICATED;
    }

    /**
     * Send prepared envelopes in parallel (curl_multi), or one after another where curl_multi is
     * missing or there is one image. Answers [id => ''] for an accepted job, [id =>
     * self::DEDUPLICATED] for the service's duplicate answer, [id => error] otherwise.
     */
    private static function send_batch(array $preps, $reason)
    {
        $answers = [];
        if (!function_exists('curl_multi_init') || !function_exists('curl_multi_exec') || count($preps) < 2) {
            foreach ($preps as $id => $p) {
                $result = $p['client']->optimize($id, $p['variants'], $p['options']);
                $result = self::resend_scaled($id, $reason, $p, $result);
                self::after_answer($id, $reason, $result);
                $answers[$id] = empty($result['ok']) ? (string) ($result['error'] ?? 'unknown') : self::answered($id, $reason, $result);
                if (empty($result['ok'])) {
                    self::failed($id, $reason, $result);
                    error_log('[WPC Bulk] FAILED image=' . $id . ' — ' . $answers[$id]);
                }
            }
            return $answers;
        }
        $mh = curl_multi_init();
        $handles = [];
        foreach ($preps as $id => $p) {
            $env = $p['client']->build_envelope($id, $p['variants'], $p['options']);
            if (empty($env['ok'])) {
                error_log('[WPC Bulk] envelope_build_failed image=' . $id . ' — ' . ($env['error'] ?? 'unknown'));
                $answers[$id] = 'envelope_build_failed';
                continue;
            }
            $hdrs = [];
            foreach ($env['headers'] as $k => $v) {
                $hdrs[] = $k . ': ' . $v;
            }
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $env['url'],
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $env['body_json'],
                CURLOPT_HTTPHEADER     => $hdrs,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'WPCompress/7.02 bulk-multi',
            ]);
            curl_multi_add_handle($mh, $ch);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('media-wire-out', (string) $id, '', [
                    'transport' => (string) ($env['headers']['X-WPC-Source-Transport'] ?? '?'),
                    'bytes'     => strlen((string) $env['body_json']),
                    'lane'      => 'bulk',
                ]);
            }
            $handles[$id] = ['ch' => $ch, 'client' => $p['client'], 't0' => $p['t0']];
        }
        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running > 0);
        foreach ($handles as $id => $h) {
            $body_raw  = curl_multi_getcontent($h['ch']);
            $http_code = (int) curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
            $wall_ms   = (int) round((microtime(true) - $h['t0']) * 1000);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('media-wire-in', (string) $id, '', [
                    'code' => $http_code, 'len' => strlen((string) $body_raw),
                    'ms' => $wall_ms, 'curl' => (string) curl_error($h['ch']), 'lane' => 'bulk',
                ]);
            }
            $result = $h['client']->process_response($id, $http_code, $body_raw);
            if (empty($result['ok']) && $http_code !== 0) {
                $result = self::resend_scaled($id, $reason, $preps[$id], $result);
            }
            if ($http_code !== 0) {
                self::after_answer($id, $reason, $result);
            }
            if (empty($result['ok'])) {
                if ($http_code === 0) {
                    // No answer at all: the transport failed, as wp_remote_post's WP_Error does.
                    $result = ['ok' => false, 'error' => 'transport', 'http_code' => 0, 'detail' => (string) curl_error($h['ch'])];
                }
                self::failed($id, $reason, $result);
                $answers[$id] = (string) ($result['error'] ?? 'unknown');
                error_log(sprintf('[WPC Bulk] FAILED image=%d wall=%dms err=%s', $id, $wall_ms, $answers[$id]));
            } else {
                $answers[$id] = self::answered($id, $reason, $result);
            }
            curl_multi_remove_handle($mh, $h['ch']);
            curl_close($h['ch']);
        }
        curl_multi_close($mh);
        return $answers;
    }
}
