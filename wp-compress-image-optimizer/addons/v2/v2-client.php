<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-client.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WPS_LocalV2')) {

class WPS_LocalV2
{
    const TRANSPORT_TIMEOUT_S    = 30;
    const STATUS_POLL_TIMEOUT_S  = 5;
    const PENDING_TRANSIENT_TTL  = 600;

    
    const INLINE_BYTES_RAW_MAX   = 716800;   


    const SOURCE_URL_FETCH_MAX   = 9961472;

    
    private $apikey;

    
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


    private function build_request_body($imageID, array $variants, array $options)
    {
        
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
        if (!$source_path || !file_exists($source_path)) {
            $source_path = $abs_path;
        }

        $bytes_on_disk = @filesize($source_path);


        $mp_probe       = @getimagesize($source_path);
        $src_megapixels = (isset($mp_probe[0], $mp_probe[1])) ? ((int) $mp_probe[0] * (int) $mp_probe[1]) : 0;
        $mp_ceiling     = (int) apply_filters('wpc_v2_source_max_megapixels', 19900000);
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


        $size = @getimagesize($source_path);
        $w    = isset($size[0]) ? (int) $size[0] : 0;
        $h    = isset($size[1]) ? (int) $size[1] : 0;

        if ($w <= 0 || $h <= 0) {
            
            
            $meta = wp_get_attachment_metadata($imageID);
            if (is_array($meta)) {
                if ($w <= 0 && !empty($meta['width']))  $w = (int) $meta['width'];
                if ($h <= 0 && !empty($meta['height'])) $h = (int) $meta['height'];
            }
        }

        if (($w <= 0 || $h <= 0) && extension_loaded('imagick')) {
            
            
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
            

            error_log(sprintf(
                '[WPC V2Client] source_dims_unknown imageID=%s path=%s — refusing to POST',
                (string) $imageID, $source_path
            ));
            return ['ok' => false, 'error' => 'source_dims_unknown', 'imageID' => $imageID];
        }

        


        


        $tier1_max = (int) apply_filters('wpc_v2_source_inline_max_bytes',  5 * 1024 * 1024);
        $tier2_max = (int) apply_filters('wpc_v2_source_both_max_bytes',   26 * 1024 * 1024);

        $source = ['width' => $w, 'height' => $h, 'bytesB64Available' => true];

        if ($bytes_on_disk > 0 && empty($options['force_url_source']) && $bytes_on_disk <= $tier2_max) {
            
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
                
            }
            
            
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
            'imageSite'      => parse_url(home_url(), PHP_URL_HOST),
            'source'         => $source,
            'variants'       => array_values($variants),
            'formats'        => $global_formats,
            'level'          => isset($options['level']) ? (string) $options['level'] : 'intelligent',


            
            'skipBackup'     => (function_exists('wpc_parent_has_backup') && wpc_parent_has_backup($imageID)) ? '1' : '0',
            'callback'       => [


                'url'    => isset($options['callback_url'])
                                ? (string) $options['callback_url']
                                : (function_exists('wpc_v2_callback_url')
                                    ? wpc_v2_callback_url('bg_swap')
                                    : rest_url('wpc/v2/bg_swap')),
                'apikey' => $this->apikey,


                'batchSupported' => apply_filters('wpc_v2_batch_supported', false),


                'directEntry'    => function_exists('wpc_v2_callback_url')
                                    ? (bool) get_option('wpc_v2_direct_entry_healthy', false)
                                    : false,
                
                


                
                


                'maxConcurrent'  => (function_exists('wpc_v2_get_max_concurrent')
                                    && function_exists('wpc_v2_adaptive_concurrency_enabled')
                                    && wpc_v2_adaptive_concurrency_enabled())
                                    ? wpc_v2_get_max_concurrent()
                                    : null,


                

                


                'deliveryMode'   => (function_exists('wpc_v2_pull_delivery_enabled')
                                    && wpc_v2_pull_delivery_enabled()
                                    && function_exists('wpc_v2_pull_enabled')
                                    && wpc_v2_pull_enabled())
                                    ? (string) apply_filters('wpc_v2_pull_delivery_mode', 'ping_pull')
                                    : null,
            ],

            


            'origin'         => function_exists('wpc_v2_get_request_origin')
                                ? wpc_v2_get_request_origin()
                                : 'web',
            'triggerContext' => isset($options['triggerContext']) ? (string) $options['triggerContext'] : 'unknown',
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

        $this->merge_variants($imageID, $written);
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
        $parsed = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($parsed)) {
            return ['ok' => false, 'error' => 'orchestrator_error', 'http_code' => $code];
        }
        return ['ok' => true, 'parsed' => $parsed];
    }

    




    public static function get_stored_job_id($imageID)
    {
        $imageID = (int) $imageID;
        $pending = get_transient('wpc_v2_pending_' . $imageID);
        if (!is_array($pending)) return '';
        if (isset($pending['jobId'])) return (string) $pending['jobId'];
        return '';
    }


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


    private function derive_variant_filename($abs_path, $size_label, $format, $imageID = 0)
    {
        $base = basename($abs_path);                          
        $dot  = strrpos($base, '.');
        if ($dot === false) return '';
        $name = substr($base, 0, $dot);

        $ext = ($format === 'jpeg' || $format === 'jpg') ? 'jpg' : strtolower($format);

        
        
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

    




    private function variant_key($size_label, $format)
    {
        $size_label = (string) $size_label;
        $format     = strtolower((string) $format);
        if ($format === 'jpg') $format = 'jpeg';
        if ($format === 'jpeg') return $size_label;
        return $size_label . '-' . $format;
    }

    




    private function merge_variants($imageID, array $new_entries)
    {
        global $wpdb;
        $lock_name = 'wpc_bg_meta_' . $imageID;


        $got_lock = wpc_worker_lock($lock_name);
        if (!$got_lock) {
            error_log(sprintf('[WPC V2] merge_variants lock_unavailable imageID=%d entries=%d — proceeding unlocked with defensive merge', (int) $imageID, count($new_entries)));
        }

        try {


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
                wpc_worker_unlock($lock_name);
            }
        }
    }


    private function record_pending_variants($imageID, array $async_pending, $jobId = '')
    {
        


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

    



    private function record_no_improvement_variant($imageID, $size_label, $format, $reason, array $entry)
    {
        $key = $this->variant_key($size_label, $format);
        global $wpdb;
        $lock_name = 'wpc_bg_meta_' . $imageID;
        
        $got_lock = wpc_worker_lock($lock_name);
        if (!$got_lock) {
            error_log(sprintf('[WPC V2] record_no_improvement_variant lock_unavailable imageID=%d variant=%s — proceeding unlocked', (int) $imageID, $key));
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
                wpc_worker_unlock($lock_name);
            }
        }
    }


    private function promote_to_compressed($imageID)
    {
        update_post_meta($imageID, 'ic_status', 'compressed');
        
        
        if (function_exists('wpc_invalidate_local_cache')) wpc_invalidate_local_cache();
        
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
