<?php


if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WPS_LocalV2')) {

class WPS_LocalV2
{
    const TRANSPORT_TIMEOUT_S    = 30;
    const STATUS_POLL_TIMEOUT_S  = 5;
    const PENDING_TRANSIENT_TTL  = 600;

    // The most source bytes ever sent inline, whatever the service declares. Rule: the decoded
    // source must stay within the service's max_inline_bytes and its base64 within the 32 MB
    // body the /optimize-v2 route parses (20 MB raw is ~26.7 MB encoded). See inline_cap().
    const INLINE_BYTES_CEILING   = 20971520;


    const SOURCE_URL_FETCH_MAX   = 9961472;

    /** @var string */
    private $apikey;

    /** @var string */
    private $orchestrator_url;

    public function __construct($apikey, $orchestrator_url)
    {
        $this->apikey           = (string) $apikey;
        $this->orchestrator_url = rtrim((string) $orchestrator_url, '/');
    }


    public function optimize($imageID, array $variants, array $options = [])
    {
        $env = $this->build_envelope($imageID, $variants, $options);
        if (empty($env['ok'])) {
            return $env;
        }

        // v7.21.350 — DIAGNOSTIC (removable): the wire receipt — transport mode + body
        // size of THIS dispatch, in the fetchable log. The .317 flip discriminator: a
        // retry that should be ~1KB source.url but logs inline/six-figure bytes names
        // the flip as the bug; url+small with silence service-side names the edge.
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('media-wire-out', (string) $imageID, '', [
                'transport' => (string) ($env['headers']['X-WPC-Source-Transport'] ?? '?'),
                'bytes'     => strlen((string) $env['body_json']),
            ]);
        }
        $response = wp_remote_post($env['url'], [
            'method'    => 'POST',
            'timeout'   => self::TRANSPORT_TIMEOUT_S,
            'blocking'  => true,
            'sslverify' => true,
            'headers'   => $env['headers'],
            'body'      => $env['body_json'],
        ]);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('media-wire-in', (string) $imageID, '', is_wp_error($response)
                ? ['err' => (string) $response->get_error_code(), 'msg' => substr((string) $response->get_error_message(), 0, 120)]
                : ['code' => (int) wp_remote_retrieve_response_code($response), 'len' => strlen((string) wp_remote_retrieve_body($response))]);
        }

        if (is_wp_error($response)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[WPC V2Client transport_fail] imageID=%s url=%s err_code=%s err_msg=%s',
                    (string) $imageID,
                    $env['url'],
                    $response->get_error_code(),
                    $response->get_error_message()
                ));
            }
            return ['ok' => false, 'error' => 'transport', 'detail' => $response->get_error_message()];
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);
        $body_raw  = wp_remote_retrieve_body($response);
        return $this->process_response($imageID, $http_code, $body_raw, (string) wp_remote_retrieve_header($response, 'retry-after'));
    }


    public function build_envelope($imageID, array $variants, array $options = [])
    {
        $body = $this->build_request_body($imageID, $variants, $options);
        if (empty($body)) {
            // v7.21.351 — DIAGNOSTIC (removable): name the empty-body exit precisely.
            // eleven-ecu 17357: dispatch receipts with no wire-out = the bail is HERE.
            if (function_exists('wpc_cache_first_log')) {
                $attached_path = function_exists('get_attached_file') ? (string) get_attached_file($imageID) : '';
                $original_path = function_exists('wp_get_original_image_path') ? (string) wp_get_original_image_path($imageID) : '';
                wpc_cache_first_log('media-env-fail', (string) $imageID, '', [
                    'why'      => $body === null ? 'animated_webp' : 'file_missing',
                    'attached' => basename($attached_path) . ':' . (($attached_path && @file_exists($attached_path)) ? 1 : 0),
                    'original' => basename($original_path) . ':' . (($original_path && @file_exists($original_path)) ? 1 : 0),
                ]);
            }
            return ['ok' => false, 'error' => 'request_build_failed'];
        }
        $body_json = wp_json_encode($body);
        if ($body_json === false) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('media-env-fail', (string) $imageID, '', ['why' => 'json_encode_failed']);
            }
            return ['ok' => false, 'error' => 'json_encode_failed'];
        }


        $log_body = $body;
        if (isset($log_body['source']['bytesB64'])) {
            $log_body['source']['bytesB64'] = '<' . strlen($log_body['source']['bytesB64']) . 'b64chars>';
        }
        if (isset($log_body['apikey']))             $log_body['apikey']             = '[REDACTED]';
        if (isset($log_body['callback']['apikey'])) $log_body['callback']['apikey'] = '[REDACTED]';
        error_log(sprintf(
            '[WPC V2Client] request imageID=%s body_bytes=%d source_url=%s envelope=%s',
            (string) $imageID,
            strlen($body_json),
            isset($body['source']['url']) ? (string) $body['source']['url'] : 'inline',
            wp_json_encode($log_body)
        ));


        $src_obj  = isset($body['source']) && is_array($body['source']) ? $body['source'] : [];
        $has_b64  = !empty($src_obj['bytesB64']);
        $has_url  = !empty($src_obj['url']);
        $transport = $has_b64 ? ($has_url ? 'both' : 'inline') : 'url';

        // v7.21.319 — envelope telemetry so the debug endpoint can show whether a dispatch
        // went out inline (large body → blackhole risk) or url (tiny). Answers "is source.url
        // actually being used?" without reading debug.log.
        if (function_exists('set_transient')) {
            set_transient('wpc_v2_last_envelope_' . (int) $imageID, [
                'transport' => $transport,
                'bytes'     => strlen($body_json),
                't'         => time(),
            ], 3600);
        }

        return [
            'ok'         => true,
            'url'        => $this->orchestrator_url . '/optimize-v2',
            'body_json'  => $body_json,
            'body_assoc' => $body,
            'headers'    => [
                'Content-Type'             => 'application/json',
                'X-WPC-Plugin-Version'     => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.03.0',
                'X-Plugin-Source-Mode'     => $has_url ? 'url' : 'inline',
                'X-WPC-Source-Transport'   => $transport,
                'Authorization'            => 'Bearer ' . $this->apikey,
            ],
        ];
    }

    /**
     * Public response processor. The bulk curl_multi dispatcher calls this
     * after curl_multi_getcontent() to walk the same response routing that
     * optimize() does (429 / 401 / 413 / 200 + apply_phase_a_response).
     */
    public function process_response($imageID, $http_code, $body_raw, $retry_after_header = '')
    {
        $http_code = (int) $http_code;
        $parsed    = json_decode((string) $body_raw, true);

        // A 429 names its wait in the Retry-After header and in the body's `retry_after`
        // (server.js, the Phase A overload answer); the bulk lane's curl_multi reads no headers,
        // so the body is read too. The dispatch door turns it into the site-wide hold.
        if ($http_code === 429) {
            $retry_after = (int) $retry_after_header;
            if ($retry_after <= 0 && is_array($parsed)) {
                $retry_after = (int) ($parsed['retry_after'] ?? ($parsed['retryAfter'] ?? 0));
            }
            return ['ok' => false, 'error' => 'pool_full', 'http_code' => 429, 'retry_after' => max(0, $retry_after), 'parsed' => $parsed, 'body' => $body_raw];
        }
        if ($http_code === 401) {
            return ['ok' => false, 'error' => 'invalid_apikey', 'http_code' => 401, 'body' => $body_raw];
        }
        // `use_scaled` asks for WordPress's -scaled copy as the source (the door resends once).
        if ($http_code === 413) {
            return [
                'ok'            => false,
                'error'         => 'source_too_large',
                'service_error' => is_array($parsed) ? (string) ($parsed['error'] ?? '') : '',
                'use_scaled'    => is_array($parsed) && !empty($parsed['use_scaled']),
                'http_code'     => 413,
                'parsed'        => $parsed,
                'body'          => $body_raw,
            ];
        }
        if ($http_code !== 200 || !is_array($parsed)) {
            error_log(sprintf(
                '[WPC V2Client] orchestrator_error imageID=%d http_code=%d body_snippet=%s',
                (int) $imageID,
                $http_code,
                substr((string) $body_raw, 0, 500)
            ));
            return ['ok' => false, 'error' => 'orchestrator_error', 'http_code' => $http_code, 'body' => $body_raw];
        }

        if (empty($parsed['ok'])) {
            return ['ok' => false, 'error' => $parsed['error'] ?? 'phase_a_failed', 'http_code' => $http_code, 'parsed' => $parsed, 'body' => $body_raw];
        }

        $jobId = isset($parsed['jobId']) ? (string) $parsed['jobId'] : '';

        // Rule: the service's duplicate answer is a sent image, not a failed write. With
        // V2_MEDIA_DEDUP on, a request whose every (sizeLabel, format) pair the service encoded
        // in the last 24 h is answered {ok, dedupe:'media_recent_encode', imageID, jobId,
        // phase:'A', pairs[, partial]} with no Phase A body: the service republishes those
        // variants to this site's manifest and wakes the pull, and keeps no job state for that
        // jobId (the status route answers 410). Observed failure: the rig's bulk run read that
        // answer as `write_failed`, left two re-sent images uncompressed and showed them as
        // "Refused: write_failed 2" (diff-1bdd6454-vs-96b29648, bug 1).
        if (isset($parsed['dedupe']) && is_string($parsed['dedupe']) && $parsed['dedupe'] !== '') {
            if (function_exists('wpc_v2_reset_attempts')) {
                wpc_v2_reset_attempts((int) $imageID);
            }
            return [
                'ok'      => true,
                'outcome' => 'deduplicated',
                'dedupe'  => (string) $parsed['dedupe'],
                'pairs'   => isset($parsed['pairs']) ? (int) $parsed['pairs'] : 0,
                'jobId'   => $jobId,
                'parsed'  => $parsed,
            ];
        }

        $write = $this->apply_phase_a_response($imageID, $parsed, $jobId);
        if (empty($write['ok'])) {
            return ['ok' => false, 'error' => 'write_failed', 'detail' => $write['detail'] ?? '', 'http_code' => $http_code, 'parsed' => $parsed];
        }

        // v7.21.352 — RECOVERY ON PROOF (eleven-ecu: 37 images burned their 4 attempts
        // against a host firewall that blackholed the orchestrator; once the pipe is
        // fixed, parked images would stay dead up to 7 days). A dispatch that just
        // SUCCEEDED is the proof the underlying issue is resolved — unpark the fleet so
        // bulk re-admits everything. Bounded (park list caps at 200), 10-min throttled.
        if (function_exists('wpc_v2_parked_list') && function_exists('wpc_v2_reset_attempts')
            && !get_transient('wpc_v2_unpark_sweep352')) {
            $parked_ids = wpc_v2_parked_list();
            if (!empty($parked_ids)) {
                set_transient('wpc_v2_unpark_sweep352', 1, 600);
                foreach ($parked_ids as $parked_id) {
                    wpc_v2_reset_attempts((int) $parked_id);
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('media-unpark-sweep', '', '', ['n' => count($parked_ids)]);
                }
            }
        }
        if (function_exists('wpc_v2_reset_attempts')) {
            wpc_v2_reset_attempts((int) $imageID);
        }
        return ['ok' => true, 'parsed' => $parsed, 'write' => $write, 'jobId' => $jobId];
    }


    /**
     * The inline source cap: the service's declared max_inline_bytes (cached capabilities),
     * never above INLINE_BYTES_CEILING; the ceiling when nothing is cached. A larger source goes
     * by URL. Observed failure: a 26 MB hardcode let a source through whose base64 exceeded the
     * route's body limit, while a 700 KB constant elsewhere still named the old cap.
     */
    private static function inline_cap()
    {
        $caps = (function_exists('get_site_transient') && defined('WPC_V2_CAPS_CACHE_KEY')) ? get_site_transient(WPC_V2_CAPS_CACHE_KEY) : false;
        $declared = is_array($caps) && !empty($caps['max_inline_bytes']) ? (int) $caps['max_inline_bytes'] : self::INLINE_BYTES_CEILING;
        return min($declared, self::INLINE_BYTES_CEILING);
    }

    private function build_request_body($imageID, array $variants, array $options)
    {
        // Animated webp: permanent decline — recompression mangles animation frames
        if (function_exists('wpc_is_animated_webp') && function_exists('get_post_mime_type')
            && (string) get_post_mime_type($imageID) === 'image/webp') {
            $wpc_awb_f = function_exists('get_attached_file') ? (string) get_attached_file($imageID) : '';
            if ($wpc_awb_f !== '' && wpc_is_animated_webp($wpc_awb_f)) {
                if (function_exists('update_post_meta')) {
                    update_post_meta($imageID, 'ic_skipped', 'animated_webp');
                }
                return null;
            }
        }
        $imageID  = (int) $imageID;
        $abs_path = get_attached_file($imageID);
        if (!$abs_path || !file_exists($abs_path)) {
            return [];
        }


        $source_path = function_exists('wp_get_original_image_path')
            ? wp_get_original_image_path($imageID)
            : $abs_path;
        // The service's 413 with `use_scaled` asks for the copy WordPress scaled
        // (wps_ic_image_optimize::resend_scaled()).
        if (!$source_path || !file_exists($source_path) || !empty($options['use_scaled_source'])) {
            $source_path = $abs_path;
        }

        $bytes_on_disk = @filesize($source_path);


        $mp_probe       = @getimagesize($source_path);
        $src_megapixels = (isset($mp_probe[0], $mp_probe[1])) ? ((int) $mp_probe[0] * (int) $mp_probe[1]) : 0;
        // Just under the service's declared max_source_mp (99.5 %: 20 MP gives the 19.9 MP this
        // ceiling always had), so a source the service would refuse with a 413 is scaled first.
        $caps_mp        = (function_exists('get_site_transient') && defined('WPC_V2_CAPS_CACHE_KEY')) ? get_site_transient(WPC_V2_CAPS_CACHE_KEY) : false;
        $declared_mp    = is_array($caps_mp) && !empty($caps_mp['max_source_mp']) ? (float) $caps_mp['max_source_mp'] : 0.0;
        $mp_ceiling     = (int) apply_filters('wpc_v2_source_max_megapixels', $declared_mp > 0 ? (int) floor($declared_mp * 995000) : 19900000);
        $over_mp        = ($src_megapixels > 0 && $src_megapixels > $mp_ceiling);


        $url_fetch_max = (int) apply_filters('wpc_v2_source_url_max_bytes', self::SOURCE_URL_FETCH_MAX);
        $used_resized  = false;

        if ($bytes_on_disk > 0
            && ($bytes_on_disk > $url_fetch_max || $over_mp)
            && function_exists('wp_get_image_editor')) {


            $wpsic_opts = get_option('wps_ic');
            $cfg_maxw   = is_array($wpsic_opts) && !empty($wpsic_opts['maxWidth'])
                ? (int) $wpsic_opts['maxWidth']
                : 2560;
            if ($cfg_maxw < 800) {
                $cfg_maxw = 2560;
            }
            $resize_max = (int) apply_filters('wpc_v2_source_resize_max_dim', $cfg_maxw, $imageID);

            $upload_dir_for_tmp = wp_get_upload_dir();
            $tmp_dir = trailingslashit($upload_dir_for_tmp['basedir']) . 'wpc-cache';
            if (!is_dir($tmp_dir)) {
                wp_mkdir_p($tmp_dir);
            } else {
                // Opportunistic cleanup of stale temp sources (>1 hour old).


                $stale_cutoff = time() - 3600;
                $stale_files  = (array) glob($tmp_dir . '/wpc-v2-src-*');
                foreach ($stale_files as $stale) {
                    if (@filemtime($stale) < $stale_cutoff) {
                        @unlink($stale);
                    }
                }
            }

            $editor = wp_get_image_editor($source_path);
            if (!is_wp_error($editor)) {
                $editor->resize($resize_max, $resize_max, false);
                // This intermediate is only built for the rare original that STILL exceeds the (9.5 MB)

                // For those giants the encoder derives every variant from THIS 2560 source, and since no


                $wpc_src_q = (int) apply_filters('wpc_v2_source_quality', 100, $imageID);
                $editor->set_quality($wpc_src_q);

                $tmp_path = $tmp_dir . '/wpc-v2-src-' . (int) $imageID . '-' . wp_generate_password(8, false) . '.jpg';
                $saved    = $editor->save($tmp_path, 'image/jpeg');

                if (!is_wp_error($saved) && !empty($saved['path']) && file_exists($saved['path'])) {
                    $resized_bytes = @filesize($saved['path']);
                    if ($resized_bytes > 0 && $resized_bytes <= $url_fetch_max) {
                        error_log(sprintf(
                            '[WPC V2Client] plugin-resize imageID=%s — unscaled=%d → resized=%d bytes (q%d, max=%dpx)',
                            (string) $imageID, $bytes_on_disk, $resized_bytes, $wpc_src_q, $resize_max
                        ));
                        $source_path   = $saved['path'];
                        $bytes_on_disk = $resized_bytes;
                        $used_resized  = true;
                    } else {


                        @unlink($saved['path']);
                    }
                }
            }
        }


        if (!$used_resized
            && $bytes_on_disk > 0
            && ($bytes_on_disk > $url_fetch_max || $over_mp)
            && $abs_path
            && $abs_path !== $source_path
            && file_exists($abs_path)) {
            $scaled_bytes = @filesize($abs_path);
            if ($scaled_bytes > 0 && $scaled_bytes <= $url_fetch_max) {
                error_log(sprintf(
                    '[WPC V2Client] source fallback imageID=%s — unscaled=%d bytes > url_fetch_max=%d, using scaled=%d bytes',
                    (string) $imageID, $bytes_on_disk, $url_fetch_max, $scaled_bytes
                ));
                $source_path   = $abs_path;
                $bytes_on_disk = $scaled_bytes;
            }
        }


        $size = @getimagesize($source_path);
        $w    = isset($size[0]) ? (int) $size[0] : 0;
        $h    = isset($size[1]) ? (int) $size[1] : 0;

        if ($w <= 0 || $h <= 0) {
            // Tier 2: WP attachment metadata. Cached at upload time; reliable
            // across formats since WP normalises during _wp_attachment_metadata.
            $meta = wp_get_attachment_metadata($imageID);
            if (is_array($meta)) {
                if ($w <= 0 && !empty($meta['width']))  $w = (int) $meta['width'];
                if ($h <= 0 && !empty($meta['height'])) $h = (int) $meta['height'];
            }
        }

        if (($w <= 0 || $h <= 0) && extension_loaded('imagick')) {
            // Tier 3: Imagick identifyImage — slow (~50-100 ms) but bulletproof
            // for any format ImageMagick can read.
            try {
                $im_probe = new Imagick();
                $im_probe->pingImage($source_path);
                if ($w <= 0) $w = (int) $im_probe->getImageWidth();
                if ($h <= 0) $h = (int) $im_probe->getImageHeight();
                $im_probe->clear();
                $im_probe->destroy();
            } catch (\Throwable $e) {
                error_log(sprintf(
                    '[WPC V2Client] imagick_probe_failed imageID=%s err=%s',
                    (string) $imageID, $e->getMessage()
                ));
            }
        }

        if ($w <= 0 || $h <= 0) {
            // All three tiers failed. Bail with a clear error — better than

            error_log(sprintf(
                '[WPC V2Client] source_dims_unknown imageID=%s path=%s — refusing to POST',
                (string) $imageID, $source_path
            ));
            return ['ok' => false, 'error' => 'source_dims_unknown', 'imageID' => $imageID];
        }

        // Source transport: up to 5 MB inline only; up to the inline cap (inline_cap()) inline
        // plus the URL; above the cap the URL only.
        $tier1_max = (int) apply_filters('wpc_v2_source_inline_max_bytes',  5 * 1024 * 1024);
        $tier2_max = (int) apply_filters('wpc_v2_source_both_max_bytes', self::inline_cap());

        $source = ['width' => $w, 'height' => $h, 'bytesB64Available' => true];

        if ($bytes_on_disk > 0 && empty($options['force_url_source']) && $bytes_on_disk <= $tier2_max) {
            // Tier 1 or Tier 2 — attempt inline read.
            $raw = @file_get_contents($source_path);
            if ($raw !== false) {
                $source['bytesB64'] = base64_encode($raw);
                $source['sha256']   = hash('sha256', $raw);
                unset($raw);


                if ($bytes_on_disk > $tier1_max) {
                    $upload_dir = wp_get_upload_dir();
                    $rel        = ltrim(str_replace($upload_dir['basedir'], '', $source_path), '/');
                    $source['url'] = $upload_dir['baseurl'] . '/' . $rel;
                }
                // Tier 1: bytesB64 only — URL omitted to save POST body bytes.
            }
            // If file_get_contents failed (rare — disk read error mid-flight),
            // fall through to URL-only path below as last resort.
        }


        if (!isset($source['bytesB64'])) {
            if (!empty($options['source_url'])) {
                $source['url']    = (string) $options['source_url'];
                $source['sha256'] = '';
            } elseif (!isset($source['url'])) {
                $upload_dir = wp_get_upload_dir();
                $rel        = ltrim(str_replace($upload_dir['basedir'], '', $source_path), '/');
                $source['url'] = $upload_dir['baseurl'] . '/' . $rel;
            }
        }

        $global_formats = isset($options['formats']) && is_array($options['formats'])
                                    ? $options['formats']
                                    : ['jpeg', 'webp', 'avif'];

        // webp-as-source contract: recompress to webp/avif only — NEVER jpeg from a
        // webp original (alpha loss + format downgrade)
        $wpc_src_mime_wb = function_exists('get_post_mime_type') ? (string) get_post_mime_type($imageID) : '';
        if ($wpc_src_mime_wb === 'image/webp') {
            $global_formats = array_values(array_diff($global_formats, ['jpeg', 'jpg']));
            if (!in_array('webp', $global_formats, true)) { $global_formats[] = 'webp'; }
            if (!in_array('avif', $global_formats, true)) { $global_formats[] = 'avif'; }
        }


        if (function_exists('wpc_v2_formats_consumer_enabled')
            && wpc_v2_formats_consumer_enabled()) {
            foreach ($variants as $vi => $v) {
                $per_variant = apply_filters(
                    'wpc_v2_variant_formats',
                    $global_formats,
                    $v,
                    $imageID,
                    $options
                );
                if (is_array($per_variant)
                    && !empty($per_variant)
                    && $per_variant !== $global_formats) {
                    $variants[$vi]['formats'] = array_values(array_unique(array_map('strval', $per_variant)));
                }
            }
        }

        $body = [
            'apikey'         => $this->apikey,
            'imageID'        => (string) $imageID,
            'source'         => $source,
            'variants'       => array_values($variants),
            'formats'        => $global_formats,
            'level'          => isset($options['level']) ? (string) $options['level'] : (function_exists('wpc_v2_level') ? wpc_v2_level() : 'intelligent'),
            'callback'       => [


                'url'    => isset($options['callback_url'])
                                ? (string) $options['callback_url']
                                : (function_exists('wpc_v2_callback_url')
                                    ? wpc_v2_callback_url('bg_swap')
                                    : rest_url('wpc/v2/bg_swap')),
                'apikey' => $this->apikey,


                'batchSupported' => apply_filters('wpc_v2_batch_supported', false),
                // Per-callback-type concurrency caps (AIMD). Plugin self-measures
                // its FPM capacity via AIMD (TCP-style congestion control), advertises


                // Includes WP-CLI/cron 2× multiplier (single unmultiplied) for jobs
                // that don't compete with FE traffic for FPM workers.


                'maxConcurrent'  => (function_exists('wpc_v2_get_max_concurrent')
                                    && function_exists('wpc_v2_adaptive_concurrency_enabled')
                                    && wpc_v2_adaptive_concurrency_enabled())
                                    ? wpc_v2_get_max_concurrent()
                                    : null,


                // See addons/v2/v2-pull.php.

                // POLL to drain the manifest — when that scheduler isn't firing on a host, fresh AND ancient


                'deliveryMode'   => (function_exists('wpc_v2_pull_delivery_enabled')
                                    && wpc_v2_pull_delivery_enabled()
                                    && function_exists('wpc_v2_pull_enabled')
                                    && wpc_v2_pull_enabled())
                                    ? (string) apply_filters('wpc_v2_pull_delivery_mode', 'ping_pull')
                                    : null,
            ],

            // §11 F4: header would be unsigned + spoofable; body is in the HMAC


            'origin'         => function_exists('wpc_v2_get_request_origin')
                                ? wpc_v2_get_request_origin()
                                : 'web',
            'resubmit_reason' => isset($options['resubmit_reason']) && $options['resubmit_reason'] !== ''
                                ? (string) $options['resubmit_reason'] : 'new',
            'attempt'         => isset($options['attempt']) ? max(1, (int) $options['attempt']) : 1,
        ];
        if (!empty($options['run_id']) && is_string($options['run_id'])) {
            $body['run_id'] = preg_replace('/[^A-Za-z0-9_-]/', '', $options['run_id']);
        }


        if ($body['callback']['maxConcurrent'] === null) {
            unset($body['callback']['maxConcurrent']);
        }
        if ($body['callback']['deliveryMode'] === null) {
            unset($body['callback']['deliveryMode']);
        }

        return $body;
    }

    /**
     * Parse Phase A response, write parent variant bytes to disk, update meta,
     * record asyncPending in transient for the polling fallback.
     */
    private function apply_phase_a_response($imageID, array $parsed, $jobId = '')
    {
        $imageID = (int) $imageID;
        $phaseA  = isset($parsed['phaseA']) && is_array($parsed['phaseA']) ? $parsed['phaseA'] : [];
        $parent_size_label = isset($phaseA['sizeLabel']) ? (string) $phaseA['sizeLabel'] : '';
        $parent = isset($phaseA['parent']) && is_array($phaseA['parent']) ? $phaseA['parent'] : [];


        error_log(sprintf(
            '[WPC V2Client] imageID=%d phaseA_keys=%s parent_keys=%s sizeLabel=%s jobId=%s asyncPending_count=%d',
            $imageID,
            is_array($phaseA) ? implode(',', array_keys($phaseA)) : '-',
            is_array($parent) ? implode(',', array_keys($parent)) : '-',
            $parent_size_label,
            $jobId !== '' ? substr($jobId, 0, 8) : '-',
            is_array($parsed['asyncPending'] ?? null) ? count($parsed['asyncPending']) : 0
        ));

        if ($parent_size_label === '' || empty($parent)) {
            error_log('[WPC V2Client] WRITE_FAIL_REASON shape — full top-level keys: ' . implode(',', array_keys($parsed)));
            return ['ok' => false, 'detail' => 'phaseA shape missing sizeLabel or parent'];
        }


        if (function_exists('wpc_v2_predictor_consumer_enabled')
            && wpc_v2_predictor_consumer_enabled()
            && isset($phaseA['avifPrediction'])
            && is_array($phaseA['avifPrediction'])) {
            $pred = $phaseA['avifPrediction'];
            $clean = [
                'mode'    => isset($pred['mode']) ? (string) $pred['mode'] : '',
                'maxProb' => isset($pred['maxProb']) ? (float) $pred['maxProb'] : 0.0,
                'topK'    => isset($pred['topK']) && is_array($pred['topK'])
                                ? array_values(array_filter(array_map('strval', $pred['topK'])))
                                : [],
                'storedAt' => time(),
            ];
            set_transient('wpc_v2_avif_prediction_' . $imageID, $clean, 600);
            error_log(sprintf(
                '[WPC V2Client] avif_predictor imageID=%d mode=%s maxProb=%.2f topK_count=%d',
                $imageID, $clean['mode'], $clean['maxProb'], count($clean['topK'])
            ));
        }

        $upload_dir = wp_get_upload_dir();
        $abs_path   = get_attached_file($imageID);
        $dest_dir   = dirname($abs_path);
        $written    = [];


        $orig_path = function_exists('wp_get_original_image_path')
            ? wp_get_original_image_path($imageID)
            : $abs_path;
        if (!$orig_path) $orig_path = $abs_path;


        $disk_target_path = ($parent_size_label === 'original') ? $orig_path : $abs_path;
        $src_bytes_on_disk  = ($disk_target_path && is_file($disk_target_path)) ? (int) filesize($disk_target_path) : 0;
        $src_bytes_baseline = ($orig_path && is_file($orig_path)) ? (int) filesize($orig_path) : $src_bytes_on_disk;


        $intentional_skip_count = 0;

        foreach (['jpeg', 'webp'] as $fmt) {
            $entry = isset($parent[$fmt]) && is_array($parent[$fmt]) ? $parent[$fmt] : null;
            if (!$entry) continue;

            // Per-format ok/reason from contract C4 — bg_no_improvement maps here too.
            if (isset($entry['ok']) && $entry['ok'] === false) {
                $reason = isset($entry['reason']) ? (string) $entry['reason'] : 'no_improvement';
                $this->record_no_improvement_variant($imageID, $parent_size_label, $fmt, $reason, $entry);
                continue;
            }


            if (isset($entry['bumped']) && (string) $entry['bumped'] === 'source_already_optimal') {
                error_log(sprintf(
                    '[WPC V2Client] phase_a_source_already_optimal size_label=%s fmt=%s',
                    $parent_size_label, $fmt
                ));
                $this->record_no_improvement_variant($imageID, $parent_size_label, $fmt, 'source_already_optimal', $entry);
                $intentional_skip_count++;
                continue;
            }

            $b64 = isset($entry['bytesB64']) ? (string) $entry['bytesB64'] : '';


            $filename = isset($entry['filename']) ? basename((string) $entry['filename']) : '';
            if ($filename === '') {
                $filename = $this->derive_variant_filename($abs_path, $parent_size_label, $fmt, $imageID);
            }
            if ($b64 === '' || $filename === '') continue;

            $raw = base64_decode($b64, true);
            if ($raw === false) continue;


            if (function_exists('wpc_is_valid_image_bytes')
                && !wpc_is_valid_image_bytes($raw, $fmt === 'jpeg' ? 'jpeg' : $fmt, $imageID, 'phase_a_v2', ['size_label' => $parent_size_label])) {
                continue;
            }

            // The store refuses a parent no smaller than the file WordPress serves at its size
            // (the attached file for `scaled`, the original for `original`) and records it as no
            // improvement; that is an intentional skip, not a failure.
            $dest = $dest_dir . '/' . $filename;
            $put = wpc_v2_store_bytes($raw, $dest, ['variant' => ['id' => $imageID, 'size' => $parent_size_label, 'fmt' => $fmt, 'src' => 'phase_a']]);
            if (($put['error'] ?? '') === 'larger_than_disk') {
                if (empty($put['recorded'])) {
                    $this->record_no_improvement_variant($imageID, $parent_size_label, $fmt, 'larger_than_disk', $entry);
                }
                $intentional_skip_count++;
                continue;
            }
            if (empty($put['ok'])) {
                error_log(sprintf(
                    '[WPC V2Client] phase_a_%s imageID=%d size_label=%s fmt=%s bytes=%d dest_tail=%s msg=%s',
                    (string) $put['error'], (int) $imageID, (string) $parent_size_label, (string) $fmt, strlen($raw),
                    substr($dest, -60), (string) $put['msg']
                ));
                continue;
            }

            // Savings baseline = un-scaled original (consistent across variants


            $entry_orig = isset($entry['originalSize']) ? (int) $entry['originalSize'] : 0;
            if ($entry_orig <= 0) $entry_orig = $src_bytes_baseline;
            $savings = ($entry_orig > 0)
                ? max(0, (int) round((1 - (strlen($raw) / $entry_orig)) * 100))
                : 0;

            $variant_key = $this->variant_key($parent_size_label, $fmt);


            $t0_ms      = (int) get_transient('wpc_v2_t0_ms_' . $imageID);
            $now_ms     = (int) round(microtime(true) * 1000);
            $from_click = ($t0_ms > 0) ? max(0, $now_ms - $t0_ms) : 0;

            $written[$variant_key] = [
                'size'         => strlen($raw),
                'originalSize' => $entry_orig,
                'url'          => $upload_dir['baseurl'] . '/' . ltrim(str_replace($upload_dir['basedir'], '', $dest), '/'),
                'local'        => true,
                'skipped'      => false,
                'savings'      => $savings,
                'phaseA_v2'    => true,


                'bg_upgraded'    => time(),
                'bg_upgraded_ms' => (int) round(microtime(true) * 1000),
                'bg_t_from_click_ms' => $from_click,
                'kb_reported'  => isset($entry['kb']) ? (float) $entry['kb'] : 0.0,
                'butter'       => isset($entry['butter']) ? (float) $entry['butter'] : 0.0,
                'q'            => isset($entry['q']) ? (int) $entry['q'] : 0,
            ];
        }

        $async_pending = isset($parsed['asyncPending']) && is_array($parsed['asyncPending']) ? $parsed['asyncPending'] : [];

        if (empty($written)) {


            if ($intentional_skip_count > 0 && !empty($async_pending)) {
                error_log(sprintf(
                    '[WPC V2Client] phase_a_parents_all_skipped intentional_skips=%d asyncPending=%d — proceeding with Phase B drain only',
                    $intentional_skip_count, count($async_pending)
                ));
                $this->record_pending_variants($imageID, $async_pending, $jobId);
                // No parent bytes to record (the service's parent was no smaller than the file on
                // disk), so the owner's rule cannot see it: the promotion is made here.
                wps_ic_image_variants::mark_compressed($imageID, 'phase-a');
                $this->promote_to_compressed($imageID);
                return ['ok' => true, 'variants_written' => [], 'jobId' => $jobId, 'parents_skipped' => $intentional_skip_count];
            }

            $diag = [];
            foreach (['jpeg', 'webp'] as $fmt) {
                $e = isset($parent[$fmt]) ? $parent[$fmt] : null;
                if (!$e) { $diag[$fmt] = 'absent'; continue; }
                $diag[$fmt] = [
                    'keys'        => is_array($e) ? implode(',', array_keys($e)) : 'not-array',
                    'ok'          => $e['ok'] ?? 'unset',
                    'has_bytesB64' => !empty($e['bytesB64']),
                    'has_filename' => !empty($e['filename']),
                    'bytesB64_len' => isset($e['bytesB64']) ? strlen((string) $e['bytesB64']) : 0,
                ];
            }
            error_log('[WPC V2Client] WRITE_FAIL_REASON no_bytes — ' . wp_json_encode($diag));


            if (function_exists('wpc_v2_ic_compressing_set_status')) {
                wpc_v2_ic_compressing_set_status($imageID, 'failed');
            }
            return ['ok' => false, 'detail' => 'no parent bytes written', 'diag' => $diag];
        }

        wps_ic_image_variants::record($imageID, $written, 'phase-a', ['first' => true]);
        $this->record_pending_variants($imageID, $async_pending, $jobId);
        $this->promote_to_compressed($imageID);


        if (function_exists('wpc_v2_purge_html_for_attachment_deferred')) {
            wpc_v2_purge_html_for_attachment_deferred($imageID, 'v2-client-phaseA');
        }


        if (!empty($async_pending) && function_exists('wpc_v2_pull_drain_fire')) {
            $now_ms = (int) (microtime(true) * 1000);


            $target_deadline = $now_ms + 60000;
            wp_cache_delete('wpc_v2_drain_alive_until_ms', 'options');
            $current_deadline = (int) get_option('wpc_v2_drain_alive_until_ms', 0);
            if ($target_deadline > $current_deadline) {
                update_option('wpc_v2_drain_alive_until_ms', $target_deadline, false);
            }
            wpc_v2_pull_drain_fire();
        }

        return ['ok' => true, 'variants_written' => array_keys($written), 'jobId' => $jobId];
    }


    public function get_status($imageID, $jobId = '')
    {
        $imageID = (int) $imageID;
        if ($jobId === '') {
            $jobId = WPS_LocalV2::get_stored_job_id($imageID);
        }
        $url = $this->orchestrator_url . '/optimize-v2/status/' . $imageID;
        if ($jobId !== '') {
            $url .= '?jobId=' . rawurlencode($jobId);
        }
        $response = wp_remote_get($url, [
            'timeout' => self::STATUS_POLL_TIMEOUT_S,
            'headers' => ['Authorization' => 'Bearer ' . $this->apikey],
        ]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => 'transport'];
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 410) {
            return ['ok' => false, 'error' => 'gc_expired', 'http_code' => 410];
        }
        // The job was dispatched under another key (the site's key changed since): this key can
        // never read it (server.js, status route, v3.24.143 (E)).
        if ($code === 403) {
            return ['ok' => false, 'error' => 'apikey_mismatch', 'http_code' => 403];
        }
        $parsed = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($parsed)) {
            return ['ok' => false, 'error' => 'orchestrator_error', 'http_code' => $code];
        }
        return ['ok' => true, 'parsed' => $parsed];
    }

    /**
     * Read the stored jobId for an image from the pending transient. Returns
     * empty string if no pending state exists. Used by get_status() callers
     * (the cron's status poll) so they do not track the jobId separately.
     */
    public static function get_stored_job_id($imageID)
    {
        $imageID = (int) $imageID;
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (!is_array($pending)) return '';
        if (isset($pending['jobId'])) return (string) $pending['jobId'];
        return '';
    }


    private function derive_variant_filename($abs_path, $size_label, $format, $imageID = 0)
    {
        $base = basename($abs_path);                          // e.g. photo-scaled.jpg
        $dot  = strrpos($base, '.');
        if ($dot === false) return '';
        $name = substr($base, 0, $dot);

        $ext = ($format === 'jpeg' || $format === 'jpg') ? 'jpg' : strtolower($format);

        // "scaled" parent — the WP-attached file IS the scaled file. Variant
        // filename is just basename with new extension (e.g. photo-scaled.webp).
        if ($size_label === 'scaled' || $size_label === '') {
            return $name . '.' . $ext;
        }


        if ($size_label === 'original') {
            $orig_path = ($imageID > 0 && function_exists('wp_get_original_image_path'))
                ? wp_get_original_image_path((int) $imageID)
                : '';
            if (!$orig_path) $orig_path = $abs_path;
            $orig_base = basename($orig_path);
            $orig_dot  = strrpos($orig_base, '.');
            if ($orig_dot === false) return '';
            return substr($orig_base, 0, $orig_dot) . '.' . $ext;
        }


        $name_clean = preg_replace('/-scaled$/', '', $name);
        return $name_clean . '-' . $size_label . '.' . $ext;
    }

    /**
     * Variant key matching v1 convention: jpeg uses bare size label,
     * webp/avif use {label}-{format}. Compatible with existing
     * wpc_compute_best_savings, canonical_original_size, and modal renderers.
     */
    private function variant_key($size_label, $format)
    {
        $size_label = (string) $size_label;
        $format     = strtolower((string) $format);
        if ($format === 'jpg') $format = 'jpeg';
        if ($format === 'jpeg') return $size_label;
        return $size_label . '-' . $format;
    }

    private function record_pending_variants($imageID, array $async_pending, $jobId = '')
    {
        // Read ic_local_variants FIRST so we can skip entries that


        wp_cache_delete($imageID, 'post_meta');
        $existing = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($existing)) $existing = [];

        $pending = [];
        foreach ($async_pending as $entry) {
            $size = isset($entry['sizeLabel']) ? (string) $entry['sizeLabel'] : '';
            $fmts = isset($entry['formats']) && is_array($entry['formats']) ? $entry['formats'] : [];
            $is_parent = !empty($entry['parent']);
            if ($size === '' || empty($fmts)) continue;
            foreach ($fmts as $f) {
                $key = $this->variant_key($size, $f);


                if (isset($existing[$key])) {
                    $ev = $existing[$key];
                    $already_landed = is_array($ev) && (
                        !empty($ev['size']) ||
                        !empty($ev['bg_no_improvement'])
                    );
                    if ($already_landed) continue;
                }
                $pending[$key] = ['parent' => $is_parent];
            }
        }
        if (empty($pending) && $jobId === '') {
            delete_transient('wpc_v2_pending_' . $imageID);
            return;
        }
        // If everything already landed (pending empty but jobId present),
        // still discard the transient — there's nothing left to wait for.
        if (empty($pending)) {
            delete_transient('wpc_v2_pending_' . $imageID);
            return;
        }
        $payload = [
            'jobId'   => (string) $jobId,
            'pending' => $pending,
            'recorded_at' => time(),
        ];
        set_transient('wpc_v2_pending_' . $imageID, $payload, self::PENDING_TRANSIENT_TTL);
        // The cron's status poll asks the service about it if the callbacks do not land it.
        if (function_exists('wpc_v2_status_watch_add')) {
            wpc_v2_status_watch_add($imageID);
        }
    }

    /**
     * Record per-format no-improvement signal so UI can render "no AVIF for this
     * variant" definitively. Reuses the v1 bg_no_improvement flag.
     */
    private function record_no_improvement_variant($imageID, $size_label, $format, $reason, array $entry)
    {
        $key = $this->variant_key($size_label, $format);
        wps_ic_image_variants::record($imageID, [$key => [
            'bg_no_improvement' => true,
            'no_improvement_reason' => (string) $reason,
            'baseline_kb' => isset($entry['baselineKb']) ? (float) $entry['baselineKb'] : 0.0,
            'widen_alt_kbs' => isset($entry['widenAltKbs']) && is_array($entry['widenAltKbs']) ? $entry['widenAltKbs'] : [],
        ]], 'phase-a-no-improvement');
    }


    /** The rest of a compressed Phase A: ic_status itself is the variant-set owner's promotion. */
    private function promote_to_compressed($imageID)
    {
        // Merge instead of overwrite so expected_variants survives.
        if (function_exists('wpc_v2_ic_compressing_set_status')) {
            wpc_v2_ic_compressing_set_status($imageID, 'compressed');
        } else {
            update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
        }
        delete_transient('wps_ic_compress_' . $imageID);


        set_transient('wpc_v2_phase_a_done_' . $imageID, time(), 3600);
        set_transient('wps_ic_heartbeat_' . $imageID, [
            'imageID' => $imageID,
            'status'  => 'compressed',
            'time'    => time(),
        ], 60);


        delete_transient('wpc_lazy_v2_trigger_' . $imageID);


        if ((string) get_option('wpc_envelope_ideal_widths', '1') !== '1'
            && function_exists('wpc_v2_sized_trigger_queue')) {
            $iw_replay = get_post_meta($imageID, 'wpc_ideal_widths', true);
            foreach (is_array($iw_replay) ? $iw_replay : [] as $iw_w) {
                wpc_v2_sized_trigger_queue((int) $imageID, (int) $iw_w, (int) $iw_w);
            }
        }
    }
}

}
