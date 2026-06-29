<?php
/**
 * WP Compress /optimize-v2 client. Introduced in v7.02.0 as the Day 1-2 of the
 * v0.2 contract implementation.
 *
 * Stateless class. Builds the JSON POST body per the v0.2 contract, sends to
 * the orchestrator, parses Phase A response into per-format variant writes +
 * an asyncPending transient that the polling fallback (Day 7) + bg-swap
 * callback (Day 3) both consult. Outbound auth is `Authorization: Bearer
 * <apikey>`; inbound callbacks use HMAC body signing — handled in v2-callback.php.
 *
 * NOT loaded for v7.01.49 customers — gated by v2-bootstrap.php which only
 * fires when `wpc_protocol_version` site option is non-'v1'.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WPS_LocalV2')) {

class WPS_LocalV2
{
    const TRANSPORT_TIMEOUT_S    = 30;
    const STATUS_POLL_TIMEOUT_S  = 5;
    const PENDING_TRANSIENT_TTL  = 600;   // matches service-side GC at T+600s
    // Orch returns 413 above ~1 MB body; base64 inflates 4/3, so cap raw at
    // ~700 KB. Larger sources fall through to the source.url fetch path.
    const INLINE_BYTES_RAW_MAX   = 716800;   // 700 KB raw → ~933 KB base64
    // Plugin self-limit on the byte size of the master we hand the orch — NOT an orch limit.
    // (v7.03.98) The orch actually accepts up to 25 MB (MAX_INLINE_BYTES + URL-fetch) and 20 MP
    // (MAX_SOURCE_MP); the ONLY hard 413 is megapixels, gated separately in build_request_body
    // via $over_mp. This 9.5 MB cap is purely a memory/double-compression balance: ABOVE it we
    // resize the master to Max Image Size at the source-quality below (keeps the inline-transport
    // base64 memory peak bounded — ~2.3× raw — and q100 ≈ lossless for the orch's AVIF re-encode);
    // BELOW it we send the original untouched (single pass). Safe to deploy independently — the
    // orch takes far more, so this never causes a byte 413. Override via `wpc_v2_source_url_max_bytes`.
    const SOURCE_URL_FETCH_MAX   = 9961472; // 9.5 MB — plugin memory/quality self-limit (orch takes 25 MB / 20 MP)

    /** @var string */
    private $apikey;

    /** @var string */
    private $orchestrator_url;

    public function __construct($apikey, $orchestrator_url)
    {
        $this->apikey           = (string) $apikey;
        $this->orchestrator_url = rtrim((string) $orchestrator_url, '/');
    }

    /**
     * Phase A blocking POST to /optimize-v2. Returns the parsed response. On
     * transport / HTTP failure returns ['ok' => false, 'error' => ..., 'http_code' => ...].
     *
     * @param int   $imageID    WP attachment ID
     * @param array $variants   List of variant specs per v0.2 spec. One MUST have parent: true.
     * @param array $options    { formats: [...], level: 'intelligent', triggerContext: 'upload',
     *                            callback_url: 'https://site/wp-json/wpc/v2/bg_swap',
     *                            source_url: 'https://...' (or null to force inline) }
     */
    public function optimize($imageID, array $variants, array $options = [])
    {
        $env = $this->build_envelope($imageID, $variants, $options);
        if (empty($env['ok'])) {
            return $env;
        }

        $response = wp_remote_post($env['url'], [
            'method'    => 'POST',
            'timeout'   => self::TRANSPORT_TIMEOUT_S,
            'blocking'  => true,
            'sslverify' => true,
            'headers'   => $env['headers'],
            'body'      => $env['body_json'],
        ]);

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
        return $this->process_response($imageID, $http_code, $body_raw);
    }

    /**
     * Public envelope builder. Returns the full POST envelope (url, headers,
     * body_json) so the bulk-mode curl_multi dispatcher can fire K=3 concurrent
     * Phase A POSTs without going through optimize() one at a time.
     *
     * Returns:
     *   ['ok' => true, 'url' => ..., 'body_json' => ..., 'body_assoc' => ..., 'headers' => [...]]
     *   ['ok' => false, 'error' => 'request_build_failed' | 'json_encode_failed']
     */
    public function build_envelope($imageID, array $variants, array $options = [])
    {
        $body = $this->build_request_body($imageID, $variants, $options);
        if (empty($body)) {
            return ['ok' => false, 'error' => 'request_build_failed'];
        }
        $body_json = wp_json_encode($body);
        if ($body_json === false) {
            return ['ok' => false, 'error' => 'json_encode_failed'];
        }

        // Dump the request envelope to catch shape mismatches with the orch
        // parser; bytesB64 placeholder keeps the log readable. Redact apikey
        // (top-level + callback) — debug.log is often publicly served on
        // cPanel/Cloudways, so plaintext secrets are a compliance leak.
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

        // X-WPC-Source-Transport tags the tier per request so trace queries can
        // find URL-only (maybe WAF-blocked) sites without parsing body JSON:
        //   inline → bytesB64 only · both → bytesB64+url · url → url only
        $src_obj  = isset($body['source']) && is_array($body['source']) ? $body['source'] : [];
        $has_b64  = !empty($src_obj['bytesB64']);
        $has_url  = !empty($src_obj['url']);
        $transport = $has_b64 ? ($has_url ? 'both' : 'inline') : 'url';

        return [
            'ok'         => true,
            'url'        => $this->orchestrator_url . '/optimize-v2',
            'body_json'  => $body_json,
            'body_assoc' => $body,
            'headers'    => [
                'Content-Type'             => 'application/json',
                'X-WPC-Plugin-Version'     => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.03.0',
                'X-Plugin-Source-Mode'     => $has_url ? 'url' : 'inline',  // legacy field, kept for compat
                'X-WPC-Source-Transport'   => $transport,                    // tier identifier
                'Authorization'            => 'Bearer ' . $this->apikey,
            ],
        ];
    }

    /**
     * Public response processor. The bulk curl_multi dispatcher calls this
     * after curl_multi_getcontent() to walk the same response routing that
     * optimize() does (429 / 401 / 413 / 200 + apply_phase_a_response).
     */
    public function process_response($imageID, $http_code, $body_raw)
    {
        $http_code = (int) $http_code;
        $parsed    = json_decode((string) $body_raw, true);

        if ($http_code === 429) {
            return ['ok' => false, 'error' => 'pool_full', 'http_code' => 429, 'parsed' => $parsed];
        }
        if ($http_code === 401) {
            return ['ok' => false, 'error' => 'invalid_apikey', 'http_code' => 401];
        }
        if ($http_code === 413) {
            return ['ok' => false, 'error' => 'source_too_large', 'http_code' => 413, 'parsed' => $parsed];
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
            return ['ok' => false, 'error' => $parsed['error'] ?? 'phase_a_failed', 'parsed' => $parsed];
        }

        $jobId = isset($parsed['jobId']) ? (string) $parsed['jobId'] : '';

        $write = $this->apply_phase_a_response($imageID, $parsed, $jobId);
        if (empty($write['ok'])) {
            return ['ok' => false, 'error' => 'write_failed', 'detail' => $write['detail'] ?? '', 'parsed' => $parsed];
        }

        return ['ok' => true, 'parsed' => $parsed, 'write' => $write, 'jobId' => $jobId];
    }

    /**
     * Build the JSON request body per v0.2 spec.
     */
    private function build_request_body($imageID, array $variants, array $options)
    {
        $imageID  = (int) $imageID;
        $abs_path = get_attached_file($imageID);
        if (!$abs_path || !file_exists($abs_path)) {
            return [];
        }

        // Send the UN-SCALED original bytes when available. For WP-scaled images
        // the attached file is a q≈85 copy; feeding only that makes the encoder
        // return `source_already_optimal` (nothing left to gain). The q95-100
        // original gives the "original" parent real savings.
        $source_path = function_exists('wp_get_original_image_path')
            ? wp_get_original_image_path($imageID)
            : $abs_path;
        if (!$source_path || !file_exists($source_path)) {
            $source_path = $abs_path;
        }

        $bytes_on_disk = @filesize($source_path);

        // (v7.03.98) Megapixel gate. The orch's only HARD 413 is MAX_SOURCE_MP (~20 MP,
        // server.js:167) — it rejects an over-dimension source REGARDLESS of byte size, and
        // there's no reactive retry on our side. A lightly-compressed high-dimension original
        // (e.g. 6000×4000 ≈ 24 MP at ~4 MB) slips UNDER the byte guard below, then 413s with
        // nothing to catch it. So gate the resize on megapixels too — this is what actually
        // makes "every site can send it" true. Header-only read; cheap. Ceiling a hair under
        // 20 MP to cover a >= gate; resizing to Max Image Size brings any source to ≤6.5 MP.
        $mp_probe       = @getimagesize($source_path);
        $src_megapixels = (isset($mp_probe[0], $mp_probe[1])) ? ((int) $mp_probe[0] * (int) $mp_probe[1]) : 0;
        $mp_ceiling     = (int) apply_filters('wpc_v2_source_max_megapixels', 19900000); // ~19.9 MP (< orch 20 MP)
        $over_mp        = ($src_megapixels > 0 && $src_megapixels > $mp_ceiling);

        // Source-too-large fallback ladder. Resize the master to Max Image Size when the
        // original exceeds EITHER our byte self-limit OR the orch's megapixel ceiling. Recover
        // via (1) wp_get_image_editor resize at the source-quality below, or (2) on no GD/Imagick,
        // WP's -scaled.jpg (the fallback further down now also honors $over_mp). Either yields a
        // source the orch will accept.
        $url_fetch_max = (int) apply_filters('wpc_v2_source_url_max_bytes', self::SOURCE_URL_FETCH_MAX);
        $used_resized  = false;

        if ($bytes_on_disk > 0
            && ($bytes_on_disk > $url_fetch_max || $over_mp)
            && function_exists('wp_get_image_editor')) {

            // Read user's "Max Image Size" setting (Settings → Image
            // Optimization). Default 2560 matches WP's
            // big_image_size_threshold. Hard floor at 800px so a misconfigured
            // value doesn't produce a useless source.
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
                // Orchestrator only needs the file for the ~5-10s of Phase A;
                // anything older is leftover from an aborted compress.
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
                // URL-fetch cap — everything under it is sent pristine/untouched for the orch to resize from.
                // For those giants the encoder derives every variant from THIS 2560 source, and since no
                // delivery variant exceeds Max Image Size, a 2560 source is already complete. (v7.03.97)
                // q95 → q100: this is the ONLY place the plugin re-encodes before the orch's own encode — i.e.
                // the sole double-compression in the whole pipeline (everything under the cap is sent
                // pristine/untouched) — so minimize OUR pass. q100 JPEG @2560 ≈ 3–5 MB, safely under the
                // 9.5 MB cap; the too-big fall-through below still catches any extreme outlier. Filterable via
                // wpc_v2_source_quality (drop to true-lossless WebP/PNG only if the orch confirms it accepts
                // those as a source format).
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
                        // Save succeeded but file still too big (very rare —
                        // would mean q88 + maxW couldn't fit under 3 MB).
                        // Delete the temp and fall through to scaled fallback.
                        @unlink($saved['path']);
                    }
                }
            }
        }

        // Fallback: WP's auto-scaled (q≈82, always exists for images that
        // exceed big_image_size_threshold). Only triggers when resize didn't
        // succeed (e.g. no GD/Imagick on host, or unscaled is < url_fetch_max
        // but > inline cap).
        if (!$used_resized
            && $bytes_on_disk > 0
            && (($bytes_on_disk > self::INLINE_BYTES_RAW_MAX && $bytes_on_disk > $url_fetch_max) || $over_mp)
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

        // Robust width/height resolution. Orch's MAX_SOURCE_MP gate at
        // server.js:7551 fires a width*height check, so accurate dimensions let
        // it 413 in <50 ms on oversized inputs instead of doing the full decode.
        // Three-tier resolution:
        //   1. getimagesize()              → fast for JPEG/PNG/WebP/GIF
        //   2. wp_get_attachment_metadata  → WP's own cached dimensions; works
        //                                    for HEIC/AVIF/TIFF where libgd fails
        //   3. Imagick::identifyImage      → last-resort if extension loaded
        // If all three return 0/empty, refuse to send the POST. Orch can't
        // gate-check zero-dim and would waste work decoding garbage.
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
                $im_probe->pingImage($source_path);  // ping = header-only read
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
            // sending {width:0, height:0} which bypasses orch's gate.
            error_log(sprintf(
                '[WPC V2Client] source_dims_unknown imageID=%s path=%s — refusing to POST',
                (string) $imageID, $source_path
            ));
            return ['ok' => false, 'error' => 'source_dims_unknown', 'imageID' => $imageID];
        }

        // Tiered source transport. The old logic was 700 KB inline /
        // everything-else URL-only, which broke any customer whose orch couldn't
        // reach origin (WAF, IP whitelist, Cloudways bot protection, etc.).
        // Image 17 stuck Optimizing today was a real instance: orch tried URL
        // fetch, got 302 from Cloudways WAF, returned 502 source_fetch_302.
        //
        // New tiers (LS team-validated; orch fetchSource at server.js:1415-1438
        // handles all three cases natively, no orch change needed):
        //
        //   Tier 1 (≤5 MB raw):  bytesB64-only. Safe PHP memory peak (~12 MB
        //                        with base64 string). URL is decorative; not
        //                        included to save POST body bytes.
        //   Tier 2 (5-26 MB):    BOTH bytesB64 + url. Orch prefers bytes when
        //                        present, falls back to URL on bytes parse fail.
        //                        Saves customers behind WAF.
        //   Tier 3 (>26 MB):     URL-only. Exceeds orch MAX_INLINE_BYTES. Rare
        //                        on WP sites; still relies on URL fetch +
        //                        v3.18.67 magic-byte diagnostic for clear errors.
        //
        // Filter overrides:
        //   wpc_v2_source_inline_max_bytes        → Tier 1 ceiling
        //   wpc_v2_source_both_max_bytes          → Tier 2 ceiling
        //   $options['force_url_source']          → bypass inline (debug / opt-out)
        //
        // X-WPC-Source-Transport header set in build_envelope's return
        // identifies which transport was chosen per request for trace queries
        // ("which sites are URL-only and might be WAF-blocked").
        $tier1_max = (int) apply_filters('wpc_v2_source_inline_max_bytes',  5 * 1024 * 1024);
        $tier2_max = (int) apply_filters('wpc_v2_source_both_max_bytes',   26 * 1024 * 1024);

        $source = ['width' => $w, 'height' => $h, 'bytesB64Available' => true];

        if ($bytes_on_disk > 0 && empty($options['force_url_source']) && $bytes_on_disk <= $tier2_max) {
            // Tier 1 or Tier 2 — attempt inline read.
            $raw = @file_get_contents($source_path);
            if ($raw !== false) {
                $source['bytesB64'] = base64_encode($raw);
                $source['sha256']   = hash('sha256', $raw);
                unset($raw); // explicit GC hint after base64 lifts memory peak

                // Tier 2 ALSO carries url for orch's URL-fallback path.
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

        // Fallthrough: URL-only path. Hit when:
        //   - Tier 3 (>26 MB), or
        //   - force_url_source flag set, or
        //   - Tier 1/2 file_get_contents failed.
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

        // Per-variant formats[] consumer (orch v3.18.65 capability). Lets us
        // suppress format encodes for specific variants without changing
        // the global default. Driven by a filter so the predictor parser (or any
        // future heuristic) can decide which variants drop which formats; the
        // flag only gates whether we walk the variants at all. Default-off so
        // dispatch shape is byte-identical to v7.02.x until the flag flips.
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
            'imageSite'      => parse_url(home_url(), PHP_URL_HOST),
            'source'         => $source,
            'variants'       => array_values($variants),
            'formats'        => $global_formats,
            'level'          => isset($options['level']) ? (string) $options['level'] : 'intelligent',
            // (v7.03.95) Honor the user's backup setting on the MODERN path too. Phase A backs the
            // parent source up to Bunny (CLOUD) async unless told otherwise; the legacy path emits
            // skipBackup='1' when a local backup already exists (compress.php:969) but this V2 body
            // never sent the flag → a local-backup site was STILL getting a redundant cloud copy.
            // Mirror the legacy gate: wpc_parent_has_backup() (local /wpc-backups/ file, sibling _bkp,
            // or an existing Bunny pointer) → '1' so the service skips the cloud backup; else '0'
            // (default service behavior — correct for mode=cloud). backup_all_sizes runs before this
            // send on the worker path, so on a local-backup mode the disk copy already exists here →
            // '1' → disk-only. (Cross-team: confirm the orch's /optimize-v2 honors skipBackup as
            // /optimize does — established contract, but unverified on the V2 endpoint.)
            'skipBackup'     => (function_exists('wpc_parent_has_backup') && wpc_parent_has_backup($imageID)) ? '1' : '0',
            'callback'       => [
                // Direct-entry URL selection. wpc_v2_callback_url()
                // returns the direct-PHP-entry URL if the probe at activation
                // confirmed this host supports it (PHP execution in plugin
                // dirs + uploads writable), otherwise the REST fallback URL.
                // Orch derives batch + announce URLs from this same base URL
                // by substring substitution (/bg_swap → /bg_swap_batch etc).
                // The substring detector in wp-compress.php's WPC_IS_BG_SWAP
                // catches all three suffixes for free — same naming property
                // as the spec'd batch+announce endpoints.
                // See SPEC-direct_entry.md §5 for host detection + fallback.
                'url'    => isset($options['callback_url'])
                                ? (string) $options['callback_url']
                                : (function_exists('wpc_v2_callback_url')
                                    ? wpc_v2_callback_url('bg_swap')
                                    : rest_url('wpc/v2/bg_swap')),
                'apikey' => $this->apikey,
                // Capability advertisement for orchestrator's per-request
                // batch opt-in (multi-tenant-safe rollout). When true, the orch's
                // BatchedCallbackOutbox routes callbacks to /wpc/v2/bg_swap_batch
                // (derived from this same `url` by substring substitution). When
                // false/absent (older plugin installs without the batch route),
                // the orch falls through to per-variant POSTs to `url`.
                // Lives behind orch's BATCHED_CALLBACK_ENABLED env flag too — both
                // sides must be ON for batching to happen.
                'batchSupported' => apply_filters('wpc_v2_batch_supported', false), // (v7.03.69) DEFAULT OFF → orch uses per-variant bg_swap. Batching bundled ~28 avif into ONE POST where we fetch each variant's bytes; a few still-propagating 502s stalled the whole request past the orch's 60s timeout → whole-batch retry ×30s ≈ the ~6-min avif tail. Per-variant isolates a racing fetch to itself (the path already confirmed working). Re-enable via the filter once the orch shrinks max batch size + tightens the retry backoff.
                // Advertise direct-entry capability. Orch can use this
                // to relax BATCH_FLUSH_AFTER_MS coalescing (lower idle = more
                // granular streaming) since the FPM cost per callback drops
                // from ~400 ms to ~30-80 ms when this URL is direct entry.
                'directEntry'    => function_exists('wpc_v2_callback_url')
                                    ? (bool) get_option('wpc_v2_direct_entry_healthy', false)
                                    : false,
                // Per-callback-type concurrency caps (AIMD). Plugin self-measures
                // its FPM capacity via AIMD (TCP-style congestion control), advertises
                // the per-type cap here, orch's BatchedCallbackOutbox respects it.
                // Object form: orch routes batch/announce/single each to its own cap.
                // Includes WP-CLI/cron 2× multiplier (single unmultiplied) for jobs
                // that don't compete with FE traffic for FPM workers.
                // Backwards compat: orch falls back to default cap=3 if field missing
                // OR if orch's ADAPTIVE_CONCURRENCY_ENABLED flag is OFF.
                // See docs/SPEC-adaptive_concurrency.md §3 + §11 closed decisions.
                'maxConcurrent'  => (function_exists('wpc_v2_get_max_concurrent')
                                    && function_exists('wpc_v2_adaptive_concurrency_enabled')
                                    && wpc_v2_adaptive_concurrency_enabled())
                                    ? wpc_v2_get_max_concurrent()
                                    : null,
                // Pull delivery, added in v7.02.2 (service team v3.18.27+). When
                // wpc_v2_pull_delivery_enabled() AND the host has curl_multi
                // (parallel pull dependency), advertise the pull family so orch routes
                // bytes through BunnyCDN storage + URL-only callbacks. Plugin
                // batch handler parallel-pulls bytes via wpc_v2_parallel_pull
                // (curl_multi + HTTP/2 keep-alive). Falls back to inline bytesB64
                // when this field is absent or on per-variant orch upload
                // failure (orch retries that variant with bytesB64 instead).
                // See addons/v2/v2-pull.php.
                // (v7.03.79) Default 'ping_pull', not plain 'pull'. Plain 'pull' relies on the plugin's RECURRING
                // POLL to drain the manifest — when that scheduler isn't firing on a host, fresh AND ancient
                // entries sit unpulled (orch traced 22 fresh + 27 stale stuck on a test site). 'ping_pull' makes
                // the orch WAKE /wake per variant (gated orch-side by LAZY_CDN_PING_PULL_ENABLED) → the drain
                // fires immediately, poll-independent. The drain handles both lazycdn and numeric-imageID LIBRARY
                // entries (v2-pull-manifest.php:413). Filter to revert to 'pull'; orch's PULL_PER_VARIANT is the
                // service-side kill-switch.
                'deliveryMode'   => (function_exists('wpc_v2_pull_delivery_enabled')
                                    && wpc_v2_pull_delivery_enabled()
                                    && function_exists('curl_multi_init'))
                                    ? (string) apply_filters('wpc_v2_pull_delivery_mode', 'ping_pull')
                                    : null,
            ],
            // Origin field for AIMD (HMAC-signed via body inclusion per spec
            // §11 F4: header would be unsigned + spoofable; body is in the HMAC
            // scope of the /optimize-v2 request). Orch reads body.origin to pick
            // per-(apikey, origin) inFlight counter. 'cli' = CLI/cron job (gets 2×
            // cap); 'web' = admin/frontend trigger (standard cap).
            'origin'         => function_exists('wpc_v2_get_request_origin')
                                ? wpc_v2_get_request_origin()
                                : 'web',
            'triggerContext' => isset($options['triggerContext']) ? (string) $options['triggerContext'] : 'unknown',
        ];

        // Strip null fields so old orch versions see clean envelope (defensive)
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

        // Temp diagnostic for v0.2 smoke. Strip once shape is verified.
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

        // AVIF family predictor parser (orch v3.18.65). The orch
        // attaches `phaseA.avifPrediction` = { topK[], mode, maxProb } so the
        // plugin can decide which child variants to skip AVIF on. Parsing is
        // behavior-neutral — we store the prediction as a 600s transient and
        // do nothing else. The formats consumer (separate flag) is what may
        // later read this transient via the wpc_v2_variant_formats filter.
        // Default-off so the transient is never written until we're confident
        // in the orch field shape across the fleet.
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

        // Two source references — they diverge for WP-scaled images.
        //  - on_disk: the file the encoder output will overwrite (must NOT inflate).
        //  - baseline: the un-optimized original for savings/originalSize column
        //    in the modal. Customer expects ALL rows to show the un-scaled
        //    original as "Original" (consistent baseline across variants).
        $orig_path = function_exists('wp_get_original_image_path')
            ? wp_get_original_image_path($imageID)
            : $abs_path;
        if (!$orig_path) $orig_path = $abs_path;

        // The parent is now `original` (was `scaled`) since orch v3.0.11. The
        // encoder's output overwrites $orig_path (the un-scaled file), so the inflation guard must
        // compare against THAT file's bytes — not $abs_path (scaled). Fall back
        // to $abs_path for any non-original parent label.
        $disk_target_path = ($parent_size_label === 'original') ? $orig_path : $abs_path;
        $src_bytes_on_disk  = ($disk_target_path && is_file($disk_target_path)) ? (int) filesize($disk_target_path) : 0;
        $src_bytes_baseline = ($orig_path && is_file($orig_path)) ? (int) filesize($orig_path) : $src_bytes_on_disk;

        // Counter for parents intentionally skipped via inflation guard. If
        // BOTH jpeg AND webp inflate, $written ends up empty — but that's NOT
        // a Phase A failure if asyncPending is non-empty (Phase B will deliver
        // bytes). Without this counter we'd fall through to v1 and discard
        // the entire async drain.
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

            // Since v3.0.11 the encoder ships `bumped: source_already_optimal`
            // when re-encoding would produce >= source bytes. Treat as no-improvement;
            // don't write disk (existing file is already optimal). Increment skip
            // counter so the "all parents skipped + asyncPending non-empty"
            // branch (around line 318) keeps Phase B alive instead of falling
            // through to v1.
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
            // Contract gap in v2.2.0: the orchestrator currently omits `filename`
            // from phaseA.parent.{jpeg,webp}. Derive from source + sizeLabel + format
            // using WP's standard `<basename>-<sizeLabel>.<ext>` convention so the
            // file lands at the same path WP_Image_Editor would have created. If
            // service later adds an explicit filename, that wins.
            $filename = isset($entry['filename']) ? basename((string) $entry['filename']) : '';
            if ($filename === '') {
                $filename = $this->derive_variant_filename($abs_path, $parent_size_label, $fmt, $imageID);
            }
            if ($b64 === '' || $filename === '') continue;

            $raw = base64_decode($b64, true);
            if ($raw === false) continue;

            // Refuse to persist a parent variant whose bytes are >=
            // the on-disk source. Applies to both jpeg AND webp:
            //  - JPEG: writes overwrite the customer's scaled file. A larger
            //    output is a direct regression.
            //  - WebP: browsers pick WebP via <picture> source order regardless
            //    of bytes. If WebP > JPEG, WebP-capable browsers download MORE
            //    bytes than they would have via the JPEG fallback. Same
            //    customer-facing regression, just on a different file.
            // Compare to $src_bytes_on_disk (the WP-scaled JPEG on disk) — it's
            // the right "what would have served otherwise" reference for both.
            if (in_array($fmt, ['jpeg', 'webp'], true) && $src_bytes_on_disk > 0 && strlen($raw) >= $src_bytes_on_disk) {
                error_log(sprintf(
                    '[WPC V2Client] phase_a_parent_skip reason=parent_larger_than_disk size_label=%s fmt=%s parent_bytes=%d disk_bytes=%d',
                    $parent_size_label, $fmt, strlen($raw), $src_bytes_on_disk
                ));
                $this->record_no_improvement_variant($imageID, $parent_size_label, $fmt, 'parent_larger_than_source', $entry);
                $intentional_skip_count++;
                continue;
            }

            if (function_exists('wpc_is_valid_image_bytes')
                && !wpc_is_valid_image_bytes($raw, $fmt === 'jpeg' ? 'jpeg' : $fmt, $imageID, 'phase_a_v2', ['size_label' => $parent_size_label])) {
                continue;
            }

            $dest = $dest_dir . '/' . $filename;
            $tmp  = $dest . '.wpc_tmp_' . wp_generate_password(8, false);
            // Audit hardening (P0-2): the Phase A write path is the
            // WORST silent-failure surface because Phase A runs in the
            // user's click-handler request. Pre-fix: `if (@file_put_contents
            // === false) continue` SILENTLY skipped the variant. On a full
            // disk or perms-mismatch host (Pantheon, WP Engine), Phase A
            // appeared to "succeed" (no error returned to caller) but zero
            // bytes hit disk → bulk shows the image as compressed but
            // browsers request the variant and get 404. Now log + report
            // back to caller so heartbeat can render error state.
            // Revert: restore `if (@file_put_contents($tmp, $raw) === false)
            // continue;` and same one-line for rename + chmod.
            if (@file_put_contents($tmp, $raw) === false) {
                $err = error_get_last();
                error_log(sprintf(
                    '[WPC V2Client] phase_a_write_failed imageID=%d size_label=%s fmt=%s bytes=%d dest_tail=%s msg=%s',
                    (int) $imageID, (string) $parent_size_label, (string) $fmt, strlen($raw),
                    substr($dest, -60), $err['message'] ?? '-'
                ));
                continue;
            }
            if (!@rename($tmp, $dest)) {
                $err = error_get_last();
                error_log(sprintf(
                    '[WPC V2Client] phase_a_rename_failed imageID=%d size_label=%s fmt=%s dest_tail=%s msg=%s',
                    (int) $imageID, (string) $parent_size_label, (string) $fmt,
                    substr($dest, -60), $err['message'] ?? '-'
                ));
                @unlink($tmp);
                continue;
            }
            if (!@chmod($dest, 0644)) {
                $err = error_get_last();
                error_log(sprintf(
                    '[WPC V2Client] phase_a_chmod_failed imageID=%d dest_tail=%s msg=%s',
                    (int) $imageID, substr($dest, -60), $err['message'] ?? '-'
                ));
            }

            // Savings baseline = un-scaled original (consistent across variants
            // in modal). Use service-echoed originalSize if present (v3.0.6+);
            // fall back to local filesize of the WP original.
            $entry_orig = isset($entry['originalSize']) ? (int) $entry['originalSize'] : 0;
            if ($entry_orig <= 0) $entry_orig = $src_bytes_baseline;
            $savings = ($entry_orig > 0)
                ? max(0, (int) round((1 - (strlen($raw) / $entry_orig)) * 100))
                : 0;

            $variant_key = $this->variant_key($parent_size_label, $fmt);

            // Elapsed since T0 (click). run_v2_optimize stamps the
            // wpc_v2_t0_ms_$imageID transient; we read it here so the modal
            // can render a per-variant T+ column. Phase B writes the same
            // field in v2-callback.php; both paths produce identical units.
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
                // Stamp arrival time so the count-poller's recent[]
                // cursor includes Phase A parents (jpeg + webp). Both fields
                // are written: bg_upgraded (seconds, legacy compat) and
                // bg_upgraded_ms (milliseconds, authoritative cursor) — the
                // ms field avoids same-second cursor collisions that
                // previously dropped variants from recent[].
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
            // Empty $written is ONLY a Phase A failure when no intentional skip
            // happened AND no asyncPending callbacks are expected. If parents
            // were skipped by the inflation guard (intentional) and there are
            // callbacks coming, Phase A is succeeding — the bytes will arrive
            // via /wpc/v2/bg_swap. Falling through to v1 here would race the
            // Phase B drain and clobber variants.
            if ($intentional_skip_count > 0 && !empty($async_pending)) {
                error_log(sprintf(
                    '[WPC V2Client] phase_a_parents_all_skipped intentional_skips=%d asyncPending=%d — proceeding with Phase B drain only',
                    $intentional_skip_count, count($async_pending)
                ));
                $this->record_pending_variants($imageID, $async_pending, $jobId);
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
            // Settle the image into a 'failed' status so bulk doesn't see it as
            // eternally-optimizing. Before this fix, this branch
            // returned ok=false but left ic_compressing.status='optimizing'
            // from prepare_v2_optimize → bulk handler's is_completed gate
            // failed forever → image stranded in active[] until the 10-min
            // wpc_v2_cleanup_stale_compressing hook caught it. UX bad.
            if (function_exists('wpc_v2_ic_compressing_set_status')) {
                wpc_v2_ic_compressing_set_status($imageID, 'failed');
            }
            return ['ok' => false, 'detail' => 'no parent bytes written', 'diag' => $diag];
        }

        $this->merge_variants($imageID, $written);
        $this->record_pending_variants($imageID, $async_pending, $jobId);
        $this->promote_to_compressed($imageID);

        // HTML cache invalidation. Phase A landed the parent
        // variants on disk; page HTML referencing this attachment is now
        // stale (picture sources point at CDN transform URLs because
        // variant didn't exist when HTML was last rendered). Deferred to
        // shutdown via fastcgi_finish_request so we don't block the
        // response to the orchestrator.
        if (function_exists('wpc_v2_purge_html_for_attachment_deferred')) {
            wpc_v2_purge_html_for_attachment_deferred($imageID, 'v2-client-phaseA');
        }

        // Phase A announced pending sub-size variants. Two
        // things must be true for those to actually land:
        //
        //   1. Pull-drain loop must POLL the orch manifest. It does this
        //      while now < drain_alive_until_ms. Without this extension,
        //      the loop exits with `deadline_reached iter=0` (no poll).
        //
        //   2. Pull-drain dispatcher (wpc_v2_pull_drain_fire) must
        //      actually fire — bypassing the 5-min page-load-poll
        //      throttle so sub-sizes land within ~30s of Phase A
        //      completion, not 5 minutes later.
        //
        // Both safe to call: drain_alive_until_ms is monotonic-max'd
        // (we never shrink an existing deadline); wpc_v2_pull_drain_fire
        // has its own 15s lock so concurrent calls from page-load-poll
        // are no-ops.
        if (!empty($async_pending) && function_exists('wpc_v2_pull_drain_fire')) {
            $now_ms = (int) (microtime(true) * 1000);
            // 60-second window to poll for the announced pending variants.
            // Each successful poll extends by 30s, so steady stream of
            // landings keeps the loop alive until all pending are pulled.
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

    /**
     * GET /optimize-v2/status/{imageID}?jobId={jobId} — polling fallback.
     * Used by Day 7 worker. v2.2.0 requires jobId as the query param (not "since").
     * Pass empty string if jobId is unknown — orchestrator still returns whatever
     * state it has for the imageID + falls back to apikey scope.
     */
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
        $parsed = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($parsed)) {
            return ['ok' => false, 'error' => 'orchestrator_error', 'http_code' => $code];
        }
        return ['ok' => true, 'parsed' => $parsed];
    }

    /**
     * Read the stored jobId for an image from the pending transient. Returns
     * empty string if no pending state exists. Used by get_status() callers
     * (Day 7 polling worker) so they don't have to track jobId separately.
     */
    public static function get_stored_job_id($imageID)
    {
        $imageID = (int) $imageID;
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (!is_array($pending)) return '';
        if (isset($pending['jobId'])) return (string) $pending['jobId'];
        return '';
    }

    /**
     * Request orchestrator re-send missed callbacks. v0.4-deferred per service
     * team (capability flag redeliver_supported: false in v2.2.0). Caller MUST
     * check wpc_probe_orchestrator_capabilities()['redeliver_supported'] before
     * calling — this method short-circuits if the cap is missing/false to
     * surface a clean error rather than hammer an unsupported endpoint.
     */
    public function redeliver($imageID)
    {
        if (function_exists('wpc_probe_orchestrator_capabilities')) {
            $caps = wpc_probe_orchestrator_capabilities();
            if (empty($caps['redeliver_supported'])) {
                return ['ok' => false, 'error' => 'redeliver_unsupported_v04_deferred'];
            }
        }
        $imageID = (int) $imageID;
        $jobId = self::get_stored_job_id($imageID);
        $url = $this->orchestrator_url . '/optimize-v2/status/' . $imageID . '?redeliver=true';
        if ($jobId !== '') $url .= '&jobId=' . rawurlencode($jobId);
        $response = wp_remote_get($url, [
            'timeout' => self::STATUS_POLL_TIMEOUT_S,
            'headers' => ['Authorization' => 'Bearer ' . $this->apikey],
        ]);
        if (is_wp_error($response)) return ['ok' => false, 'error' => 'transport'];
        $code = (int) wp_remote_retrieve_response_code($response);
        return ['ok' => $code === 200, 'http_code' => $code];
    }

    /**
     * Derive a variant filename when the orchestrator omits one (v2.2.0 gap;
     * service to add later). Matches WP_Image_Editor's `<basename>-<suffix>.<ext>`
     * convention so the file lands where downstream code (modal, modern-delivery)
     * already looks for it.
     *
     * `scaled` (the WP-attached file's scaled form): special-case — uses the
     * existing `<name>-scaled.<ext>` naming from `_wp_attached_file`. For other
     * size labels (1920w, medium_large, etc.), uses `<name>-<sizeLabel>.<ext>`.
     */
    private function derive_variant_filename($abs_path, $size_label, $format, $imageID = 0)
    {
        $base = basename($abs_path);                          // e.g. photo-scaled.jpg
        $dot  = strrpos($base, '.');
        if ($dot === false) return '';
        $name = substr($base, 0, $dot);                       // photo-scaled

        $ext = ($format === 'jpeg' || $format === 'jpg') ? 'jpg' : strtolower($format);

        // "scaled" parent — the WP-attached file IS the scaled file. Variant
        // filename is just basename with new extension (e.g. photo-scaled.webp).
        if ($size_label === 'scaled' || $size_label === '') {
            return $name . '.' . $ext;
        }

        // "original" parent — uses wp_get_original_image_path() basename, not the
        // attached file. For WP-scaled images get_attached_file()=-scaled.jpg but
        // the original is at -1.jpg (un-scaled). Without this branch we'd write to
        // -1-original.jpg which WP doesn't serve. Mirrors v2-callback.php's
        // wpc_v2_derive_variant_filename() implementation.
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

        // Other sizes — strip any existing `-scaled` suffix from the basename
        // so we don't end up with `photo-scaled-1920w.jpg`. The size label is
        // appended directly.
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

    /**
     * Merge new variant entries into ic_local_variants under the existing
     * GET_LOCK pattern from v1. Preserves bg_upgraded entries from previous
     * runs — Phase A v2 writes never clobber refined bg-swap bytes.
     */
    private function merge_variants($imageID, array $new_entries)
    {
        global $wpdb;
        $lock_name = 'wpc_bg_meta_' . $imageID;
        // Bumped from 5s to 15s. Under 4-image bulk + bg_swap_batch
        // bursts, the lock queue can hold 50+ callbacks (24 variants × 4
        // images = ~96). At ~50 ms in-lock per writer, 5s was at the edge of
        // saturation — when timeout expired the try block ran WITHOUT the
        // lock (silent fallthrough), causing read-modify-write races that
        // clobbered Phase B writes. The user-visible symptom was chip count
        // and popup variant list both regressing mid-drain (eventually
        // recovered via journal-drain retry, hence "refresh shows 24").
        $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 15)", $lock_name));
        $got_lock = ($got === '1' || $got === 1);
        // One retry on timeout (30s total wait). If we STILL can't
        // acquire, proceed unlocked but with explicit cache flush + atomic
        // merge semantics (we preserve any entry with bg_upgraded that's
        // in our fresh read but not in our $new_entries). Before this fix, silent
        // fallthrough on timeout caused read-modify-write race clobbers.
        if (!$got_lock) {
            error_log(sprintf('[WPC V2] merge_variants lock_acquire_failed_first imageID=%d entries=%d — retrying once', (int) $imageID, count($new_entries)));
            $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 15)", $lock_name));
            $got_lock = ($got === '1' || $got === 1);
            if (!$got_lock) {
                error_log(sprintf('[WPC V2] merge_variants lock_unavailable_after_retry imageID=%d entries=%d — proceeding unlocked with defensive merge', (int) $imageID, count($new_entries)));
            }
        }

        try {
            // Phase B callbacks land in OTHER PHP processes during the
            // 8-20s Phase A POST. Their writes hit MySQL but don't invalidate
            // this process's WP post_meta object cache, which was populated
            // earlier in the request (backup_all_sizes etc.). Without this
            // cache flush, get_post_meta returns the stale empty/2-entry value
            // and our update_post_meta clobbers 14 Phase B writes. Force a
            // fresh read from MySQL while holding the GET_LOCK so writes are
            // serialized with the read.
            wp_cache_delete($imageID, 'post_meta');
            $existing = get_post_meta($imageID, 'ic_local_variants', true);
            if (!is_array($existing)) $existing = [];
            foreach ($new_entries as $key => $entry) {
                if (!empty($existing[$key]['bg_upgraded'])) continue;
                $existing[$key] = $entry;
            }
            update_post_meta($imageID, 'ic_local_variants', $existing);

            if (function_exists('wpc_compute_best_savings')) {
                $best = wpc_compute_best_savings($existing, $imageID);
                if (!empty($best['orig']) && !empty($best['pct'])) {
                    update_post_meta($imageID, 'ic_savings',          round((float) $best['pct'], 1));
                    update_post_meta($imageID, 'ic_savings_format',   (string) $best['format']);
                    update_post_meta($imageID, 'ic_savings_bytes',    (int) $best['orig'] - (int) $best['opt']);
                    update_post_meta($imageID, 'ic_savings_baseline', (int) $best['orig']);
                }
            }
        } finally {
            if ($got_lock) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            }
        }
    }

    /**
     * Record asyncPending tuples + jobId in a transient so Day 7 polling worker
     * knows what to expect (and which jobId to pass to /status), and so the
     * bg-swap callback handler knows what to clear as variants land.
     *
     * Schema:
     *   { "jobId": "abc123...",
     *     "pending": { "thumbnail-jpeg": ["parent" => false], ... } }
     *
     * Top-level jobId from v2.2.0 contract. `pending` is keyed by variant_key()
     * with the per-variant metadata from asyncPending[] (parent flag etc).
     * Callback handler removes entries from `pending` as they land; if `pending`
     * becomes empty AND no further callbacks are expected, the transient is
     * deleted (drain complete).
     */
    private function record_pending_variants($imageID, array $async_pending, $jobId = '')
    {
        // Read ic_local_variants FIRST so we can skip entries that
        // already landed. With v7.04 pull-manifest direct-entry, Phase B
        // variants are merged into ic_local_variants faster than the Phase A
        // loopback response returns (pull tick 5s; Phase A POST 8-15s on
        // 3MB+ bodies). Without this dedupe, Phase A re-creates the pending
        // transient with the same entries that already landed → pending
        // stays non-empty forever → phase_b_done never trips → bulk loop
        // hits the 15s ceiling on every image. Symptom: ic_local_variants
        // has 24 variants but wpc_v2_pending_$id has 12 (jpeg+webp) stuck.
        // Production audit fix #1: bust the post_meta cache before
        // reading. record_pending_variants runs in the async Phase A worker;
        // by the time it executes, Phase B pull-manifest drain may have
        // already merged some variants into ic_local_variants in a parallel
        // process. Without flush, the dedupe walk below sees a stale
        // empty/partial snapshot → adds entries to pending that already
        // landed → pending transient stays non-empty forever → bulk's
        // is_completed gate blocks on drain_complete that already happened.
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
                // Skip if this variant already has bytes in ic_local_variants.
                // bg_no_improvement entries also count as "landed" (no bytes
                // expected) — only consider a variant unlanded if there's no
                // entry at all OR the entry has no size/bg_no_improvement flag.
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
    }

    /**
     * Record per-format no-improvement signal so UI can render "no AVIF for this
     * variant" definitively. Reuses the v1 bg_no_improvement flag.
     */
    private function record_no_improvement_variant($imageID, $size_label, $format, $reason, array $entry)
    {
        $key = $this->variant_key($size_label, $format);
        global $wpdb;
        $lock_name = 'wpc_bg_meta_' . $imageID;
        // 5s→15s, same race rationale as merge_variants.
        $got = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 15)", $lock_name));
        $got_lock = ($got === '1' || $got === 1);
        if (!$got_lock) {
            error_log(sprintf('[WPC V2] record_no_improvement_variant lock_acquire_failed imageID=%d variant=%s — proceeding unlocked', (int) $imageID, $key));
        }
        try {
            $existing = get_post_meta($imageID, 'ic_local_variants', true);
            if (!is_array($existing)) $existing = [];
            $existing[$key] = array_merge($existing[$key] ?? [], [
                'bg_no_improvement' => true,
                'no_improvement_reason' => (string) $reason,
                'baseline_kb' => isset($entry['baselineKb']) ? (float) $entry['baselineKb'] : 0.0,
                'widen_alt_kbs' => isset($entry['widenAltKbs']) && is_array($entry['widenAltKbs']) ? $entry['widenAltKbs'] : [],
            ]);
            update_post_meta($imageID, 'ic_local_variants', $existing);
        } finally {
            if ($got_lock) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $lock_name));
            }
        }
    }

    /**
     * Flip the card to Compressed at Phase A completion. Mirrors the v7.01.49
     * bg-swap promotion writes so compress_details() renders the compressed
     * state immediately.
     *
     * Also sets wpc_v2_phase_a_done_<id> here. This is the single
     * canonical "Phase A succeeded" function — it runs from BOTH sync and
     * async dispatch paths (via run_v2_optimize). Previously the transient
     * was only set in the SYNC fallback path at ajax.class.php:5296, which
     * is unreachable when async dispatch is enabled (the default). Result:
     * async-dispatched compresses (bulk + most ML clicks) never set the
     * transient, so bulkCompressHeartbeat_v2's is_completed gate failed
     * forever — images sat at status='compressed' + variants on disk but
     * never advanced to the completed bucket. Root cause for "0/5 stuck".
     */
    private function promote_to_compressed($imageID)
    {
        update_post_meta($imageID, 'ic_status', 'compressed');
        // F1: refresh the path-A optimized-ids cache so the origin <picture>
        // upgrade appears on the NEXT render instead of after the 300s transient TTL.
        if (function_exists('wpc_invalidate_local_cache')) wpc_invalidate_local_cache();
        // Merge instead of overwrite so expected_variants survives.
        if (function_exists('wpc_v2_ic_compressing_set_status')) {
            wpc_v2_ic_compressing_set_status($imageID, 'compressed');
        } else {
            update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
        }
        delete_transient('wps_ic_compress_' . $imageID);
        // Kept as JS chip-timing signal (gates win-pip badges during
        // first-burst window). NOT used by bulk's is_completed gate anymore
        // — that uses expected_variants + accounted variant count instead.
        set_transient('wpc_v2_phase_a_done_' . $imageID, time(), 3600);
        set_transient('wps_ic_heartbeat_' . $imageID, [
            'imageID' => $imageID,
            'status'  => 'compressed',
            'time'    => time(),
        ], 60);

        // Replay the image's DEMAND MEMORY (user design call): the registered
        // ladder just rebuilt, so re-queue every known real-slot width via the sized-trigger
        // in the SAME request — one render yields registered ladder + ideal rungs without
        // waiting for a second page view. AVIF-only by contract (the registered ladder
        // already carries all three formats for legacy browsers — per-format ideal widths
        // would be 3× encode volume for <5% of traffic: overprocessing). Every guard (lazy
        // gate, natural cap, skip-if-on-disk, 15-min burst, ≤6 batch) lives in the helper.
        // Release the Gate-2 trigger lock NOW (user-caught: sequential format
        // toggles within 10 min looked broken — the AVIF fill's compress FINISHED but its
        // lock lingered for the leftover TTL, bouncing the WebP fill's admit with
        // "lock held, ic_compressing=compressed"). The lock's job is in-flight dedup;
        // promote IS the end of in-flight. Storm protection during real flight unchanged.
        delete_transient('wpc_lazy_v2_trigger_' . $imageID);

        // When the unified envelope is ON, the compress that just
        // completed ALREADY carried the demand widths — replaying them through the
        // sized-trigger lane would duplicate jobs in the fetch-per-width path (the exact
        // 415-exposure the unification exists to kill). Replay only on envelope-off sites.
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
