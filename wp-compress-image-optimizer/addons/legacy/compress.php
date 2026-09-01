<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/legacy/compress.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */













if (!function_exists('wpc_log_trigger')) {
    function wpc_log_trigger($event, $attachmentId = 0, $context = [])
    {
        $rateKey = 'wpc_logged_' . $event . '_' . (int) $attachmentId;
        if (get_transient($rateKey)) return;
        set_transient($rateKey, 1, HOUR_IN_SECONDS);

        $log = get_option('wpc_diagnostic_log', []);
        if (!is_array($log)) $log = [];
        $ctxStr = '';
        if (!empty($context)) {
            $ctxStr = ' | ' . (is_string($context) ? $context : wp_json_encode($context));
        }
        $log[] = date('Y-m-d H:i:s') . ' | ' . strtoupper($event) . ' | id=' . (int) $attachmentId . $ctxStr;
        $log = array_slice($log, -500);
        update_option('wpc_diagnostic_log', $log, false);
    }
}


if (!function_exists('wpc_is_valid_image_bytes')) {
    function wpc_is_valid_image_bytes($bytes, $format, $imageID = 0, $source = 'unknown', $context = [])
    {
        if (empty($bytes) || !is_string($bytes)) {
            return false;
        }
        $len = strlen($bytes);


        
        $build_log = function ($reason) use ($imageID, $format, $len, $source, $bytes, $context) {
            $size_label = isset($context['size_label']) ? (string) $context['size_label'] : '';
            $url        = isset($context['url']) ? (string) $context['url'] : '';
            $hex50      = bin2hex(substr($bytes, 0, 50));


            $age_sec = '?';
            if ($imageID > 0) {
                $stats = get_post_meta((int) $imageID, '_wpc_last_post_timing', true);
                if (is_array($stats) && !empty($stats['at'])) {
                    $age_sec = (string) max(0, time() - (int) $stats['at']);
                }
            }
            return '[WPC CorruptByte] image=' . (int) $imageID
                . ' size=' . $size_label
                . ' fmt=' . $format
                . ' bytes=' . $len
                . ' source=' . $source
                . ' reason=' . $reason
                . ' age_sec=' . $age_sec
                . ' url=' . $url
                . ' hex50=' . $hex50
                . ' — REJECTED';
        };

        
        
        if ($len < 500) {
            error_log($build_log('too-small'));
            return false;
        }

        $fmt = strtolower((string) $format);
        $ok = true;
        $reason = '';

        if ($fmt === 'webp') {
            $ok = (substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP');
            $reason = 'invalid-webp-magic';
        } elseif ($fmt === 'avif') {
            $ftyp = substr($bytes, 4, 4);
            $brand = substr($bytes, 8, 4);
            $ok = ($ftyp === 'ftyp' && in_array($brand, ['avif', 'avis', 'mif1', 'heic', 'heix'], true));
            $reason = 'invalid-avif-magic';
        } elseif ($fmt === 'jpeg' || $fmt === 'jpg') {
            $ok = (substr($bytes, 0, 3) === "\xFF\xD8\xFF");
            $reason = 'invalid-jpeg-magic';
        } elseif ($fmt === 'png') {
            $ok = (substr($bytes, 0, 8) === "\x89PNG\r\n\x1A\n");
            $reason = 'invalid-png-magic';
        }

        if (!$ok) {
            error_log($build_log($reason));
            return false;
        }

        
        
        
        
        if (stripos($bytes, '<?php') !== false || stripos($bytes, '<?=') !== false
            || stripos($bytes, '<script') !== false || stripos($bytes, '<%') !== false) {
            error_log($build_log('embedded-executable-marker'));
            return false;
        }
        if (($fmt === 'jpeg' || $fmt === 'jpg' || $fmt === 'png' || $fmt === 'gif')
            && function_exists('getimagesizefromstring')) {
            $info = @getimagesizefromstring($bytes);
            $want = ($fmt === 'png') ? IMAGETYPE_PNG : (($fmt === 'gif') ? IMAGETYPE_GIF : IMAGETYPE_JPEG);
            if (!is_array($info) || empty($info[2]) || (int) $info[2] !== $want) {
                error_log($build_log('decode-mismatch'));
                return false;
            }
        }

        return true;
    }
}


if (!function_exists('wpc_persist_inline_variants')) {
    function wpc_persist_inline_variants($imageID, $entries, $source = 'inline')
    {
        $imageID = (int) $imageID;
        if (!$imageID || empty($entries) || !is_array($entries)) return 0;

        global $wpdb;
        $mysql_lock_name = 'wpc_bg_meta_' . $imageID;
        $got_mysql   = wpc_worker_lock($mysql_lock_name);

        $lock_key = 'wpc_bg_meta_lock_' . $imageID;
        $got_transient = false;
        $has_obj_cache = function_exists('wp_cache_add')
            && function_exists('wp_using_ext_object_cache')
            && wp_using_ext_object_cache();
        if (!$got_mysql) {
            for ($i = 0; $i < 50 && !$got_transient; $i++) {
                if ($has_obj_cache) {
                    $got_transient = wp_cache_add($lock_key, 1, 'wpc', 30);
                } else {
                    if (!get_transient($lock_key)) {
                        set_transient($lock_key, 1, 30);
                        $got_transient = true;
                    }
                }
                if (!$got_transient) usleep(50000);
            }
        }

        $written = 0;
        try {
            $variants = get_post_meta($imageID, 'ic_local_variants', true);
            if (!is_array($variants)) $variants = [];

            foreach ($entries as $e) {
                $size_label = (string) ($e['size_label'] ?? '');
                $format     = strtolower((string) ($e['format'] ?? ''));
                $size_bytes = (int) ($e['size'] ?? 0);
                $url        = (string) ($e['url'] ?? '');
                if ($size_label === '' || $format === '' || $size_bytes <= 0) continue;
                if ($format === 'jpg') $format = 'jpeg';

                $key = ($format === 'jpeg') ? $size_label : ($size_label . '-' . $format);

                
                if (!empty($variants[$key]['bg_upgraded'])) continue;

                if (!isset($variants[$key])) {
                    
                    
                    $variants[$key] = [
                        'url'          => $url,
                        'originalSize' => 0,
                        'size'         => $size_bytes,
                        'savings'      => 0,
                        'skipped'      => false,
                        'local'        => true,
                        'bg_source'    => $source,
                    ];
                } else {
                    $variants[$key]['size']  = $size_bytes;
                    $variants[$key]['local'] = true;
                    if ($url !== '') $variants[$key]['url'] = $url;
                }
                $written++;
            }

            if ($written > 0) {
                update_post_meta($imageID, 'ic_local_variants', $variants);

                
                
                if (function_exists('wpc_compute_best_savings')) {
                    $best = wpc_compute_best_savings($variants, $imageID);
                    if ($best['pct'] > 0 && $best['orig'] > 0) {
                        update_post_meta($imageID, 'ic_savings',          round($best['pct'], 1));
                        update_post_meta($imageID, 'ic_savings_format',   $best['format']);
                        update_post_meta($imageID, 'ic_savings_bytes',    $best['orig'] - $best['opt']);
                        update_post_meta($imageID, 'ic_savings_baseline', $best['orig']);
                    }
                }


                $is_user_compressed = (get_post_meta($imageID, 'ic_status', true) === 'compressed');
                if ($is_user_compressed) {
                    set_transient('wps_ic_heartbeat_' . $imageID, [
                        'imageID' => $imageID,
                        'status'  => 'compressed',
                        'event'   => 'bg_variant_arrived',
                        'time'    => time(),
                    ], 300);
                }
            }
        } finally {
            if ($got_mysql) {
                wpc_worker_unlock($mysql_lock_name);
            }
            if ($got_transient) {
                if ($has_obj_cache && function_exists('wp_cache_delete')) wp_cache_delete($lock_key, 'wpc');
                delete_transient($lock_key);
            }
        }

        return $written;
    }
}


if (!function_exists('wpc_purge_variants_for_image')) {
    function wpc_purge_variants_for_image($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID || get_post_type($imageID) !== 'attachment') {
            return ['imageID' => $imageID, 'cleared' => [], 'error' => 'invalid_image'];
        }

        $candidates = [
            'ic_local_variants',
            'ic_local_variants_chosen',
            'ic_savings',
            'ic_savings_format',
            'ic_savings_bytes',
            'ic_savings_baseline',
            'ic_stats',
            '_wpc_compress_started_at',
        ];

        $cleared = [];
        foreach ($candidates as $key) {
            $val = get_post_meta($imageID, $key, true);
            if ($val !== '' && $val !== null && $val !== false) {
                delete_post_meta($imageID, $key);
                $cleared[] = $key;
            }
        }

        
        delete_transient('wps_ic_heartbeat_' . $imageID);

        error_log(sprintf(
            '[WPC PurgeVariants] image=%d cleared=%s',
            $imageID, empty($cleared) ? '-' : implode(',', $cleared)
        ));

        return [
            'imageID'   => $imageID,
            'cleared'   => $cleared,
            'preserved' => ['disk_files', 'backup_files', '_wp_attachment_metadata', 'ic_status'],
            'message'   => 'Variant post_meta cleared. On-disk files preserved. Re-compress to repopulate.',
        ];
    }
}


if (!function_exists('wpc_compute_best_savings')) {
    function wpc_compute_best_savings($variants, $imageID = 0)
    {
        $best = ['pct' => 0.0, 'format' => 'jpeg', 'orig' => 0, 'opt' => 0];
        if (!is_array($variants) || empty($variants)) return $best;

        $imageID = (int) $imageID;
        $can_canonical = $imageID > 0 && class_exists('WPC_Modern_Delivery')
            && method_exists('WPC_Modern_Delivery', 'canonical_original_size');
        $meta = $can_canonical ? wp_get_attachment_metadata($imageID) : null;

        foreach ($variants as $key => $vdata) {
            if (!empty($vdata['skipped'])) continue;
            $opt  = (int) ($vdata['size'] ?? 0);
            if ($opt <= 0) continue;

            $base = preg_replace('/-(avif|webp|jpe?g|png)$/i', '', $key);

            if ($can_canonical) {
                
                $orig = WPC_Modern_Delivery::canonical_original_size($imageID, $base, $meta, $variants);
            } else {
                
                $orig = (int) ($vdata['originalSize'] ?? 0);
                if ($orig === 0) {
                    foreach ($variants as $skey => $sdata) {
                        $sbase = preg_replace('/-(avif|webp|jpe?g|png)$/i', '', $skey);
                        if ($sbase === $base && (int) ($sdata['originalSize'] ?? 0) > 0) {
                            $orig = (int) $sdata['originalSize'];
                            break;
                        }
                    }
                }
            }

            if ($orig <= 0 || $opt >= $orig) continue;

            $pct = (1 - $opt / $orig) * 100;
            if ($pct > $best['pct']) {
                $best['pct']  = $pct;
                $best['orig'] = $orig;
                $best['opt']  = $opt;
                if (strpos($key, 'avif') !== false)      $best['format'] = 'avif';
                elseif (strpos($key, 'webp') !== false)  $best['format'] = 'webp';
                else                                     $best['format'] = 'jpeg';
            }
        }
        return $best;
    }
}





if (!function_exists('wpc_atomic_queue_gate')) {
    function wpc_atomic_queue_gate($attachmentId)
    {
        $key = 'wpc_queued_' . (int) $attachmentId;

        if (function_exists('wp_cache_add') && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            if (wp_cache_add($key, time(), 'wpc', 30 * MINUTE_IN_SECONDS)) {
                set_transient($key, time(), 30 * MINUTE_IN_SECONDS);
                return true;
            }
            return false;
        }

        if (get_transient($key)) return false;
        set_transient($key, time(), 30 * MINUTE_IN_SECONDS);
        return true;
    }
}






if (!function_exists('wpc_maybe_trigger_optimize')) {
    function wpc_maybe_trigger_optimize($attachmentId)
    {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId <= 0) return;

        
        if (class_exists('wps_local_compress') && method_exists('wps_local_compress', 'is_already_compressed')) {
            $inst = new wps_local_compress();
            if ($inst->is_already_compressed($attachmentId)) return;
        }

        
        if (get_transient('wpc_failed_' . $attachmentId)) return;

        
        $attempts = (int) get_post_meta($attachmentId, '_wpc_optimize_attempts', true);
        if ($attempts >= 3) {
            set_transient('wpc_failed_' . $attachmentId, 1, DAY_IN_SECONDS);
            wpc_log_trigger('retry_ceiling_hit', $attachmentId, ['attempts' => $attempts]);
            return;
        }

        
        if (!wpc_atomic_queue_gate($attachmentId)) return;

        
        $queue = get_option('wpc_compress_queue', []);
        if (!is_array($queue)) $queue = [];
        if (!in_array($attachmentId, $queue)) {
            $queue[] = $attachmentId;
            update_option('wpc_compress_queue', $queue, false);
        }

        wpc_log_trigger('queued_lazy_gen', $attachmentId);

        
        if (class_exists('wps_local_compress')) {
            $inst = isset($inst) ? $inst : new wps_local_compress();
            if (method_exists($inst, 'fireQueueWorker')) {
                $inst->fireQueueWorker();
            }
        }
    }
}


if (!function_exists('wpc_backfill_local_variants')) {
    function wpc_backfill_local_variants($attachmentId)
    {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId <= 0) return false;

        $meta = wp_get_attachment_metadata($attachmentId);
        if (empty($meta) || empty($meta['file'])) return false;

        $upload_dir    = wp_upload_dir();
        $base_dir      = rtrim($upload_dir['basedir'], '/');
        $base_url      = rtrim($upload_dir['baseurl'], '/');
        $rel_dir       = dirname($meta['file']);
        $variants      = [];
        $found_nextgen = false;

        
        
        $resolve = function ($base_name, $format) use ($base_dir, $rel_dir) {
            
            $p = $base_dir . '/' . $rel_dir . '/' . $base_name . '.' . $format;
            if (file_exists($p) && filesize($p) > 0) {
                return $rel_dir . '/' . $base_name . '.' . $format;
            }
            
            $stripped = preg_replace('/-scaled$/', '', $base_name);
            if ($stripped !== $base_name) {
                $p2 = $base_dir . '/' . $rel_dir . '/' . $stripped . '.' . $format;
                if (file_exists($p2) && filesize($p2) > 0) {
                    return $rel_dir . '/' . $stripped . '.' . $format;
                }
            }
            return null;
        };

        
        foreach ($meta['sizes'] ?? [] as $size_name => $size_info) {
            if (empty($size_info['file'])) continue;
            $size_base = pathinfo($size_info['file'], PATHINFO_FILENAME);
            $jpg_rel   = $rel_dir . '/' . $size_info['file'];
            $entry = [
                'width'    => (int) ($size_info['width'] ?? 0),
                'height'   => (int) ($size_info['height'] ?? 0),
                'jpg_path' => $base_dir . '/' . $jpg_rel,
                'jpg_url'  => $base_url . '/' . $jpg_rel,
            ];
            foreach (['avif', 'webp'] as $fmt) {
                $rel = $resolve($size_base, $fmt);
                if ($rel) {
                    $entry[$fmt . '_path'] = $base_dir . '/' . $rel;
                    $entry[$fmt . '_url']  = $base_url . '/' . $rel;
                    $found_nextgen = true;
                }
            }
            $variants[$size_name] = $entry;
        }

        
        if (!empty($meta['file'])) {
            $file_base = pathinfo($meta['file'], PATHINFO_FILENAME);
            $key       = strpos($file_base, '-scaled') !== false ? 'scaled' : 'full';
            $entry = [
                'width'    => (int) ($meta['width'] ?? 0),
                'height'   => (int) ($meta['height'] ?? 0),
                'jpg_path' => $base_dir . '/' . $meta['file'],
                'jpg_url'  => $base_url . '/' . $meta['file'],
            ];
            foreach (['avif', 'webp'] as $fmt) {
                $rel = $resolve($file_base, $fmt);
                if ($rel) {
                    $entry[$fmt . '_path'] = $base_dir . '/' . $rel;
                    $entry[$fmt . '_url']  = $base_url . '/' . $rel;
                    $found_nextgen = true;
                }
            }
            $variants[$key] = $entry;
        }

        if (!$found_nextgen) return false;

        update_post_meta($attachmentId, 'ic_local_variants', $variants);
        return true;
    }
}


if (!function_exists('wpc_maybe_trigger_ladder_gen')) {
    function wpc_maybe_trigger_ladder_gen($attachmentId, $missing_widths)
    {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId <= 0 || empty($missing_widths)) return;

        
        if (get_transient('wpc_failed_ladder_' . $attachmentId)) return;

        
        $attempts = (int) get_post_meta($attachmentId, '_wpc_ladder_attempts', true);
        if ($attempts >= 3) {
            set_transient('wpc_failed_ladder_' . $attachmentId, 1, DAY_IN_SECONDS);
            return;
        }

        
        $gate_key = 'wpc_ladder_queued_' . $attachmentId;
        if (function_exists('wp_cache_add') && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            if (!wp_cache_add($gate_key, time(), 'wpc', 30 * MINUTE_IN_SECONDS)) {
                
                wpc_merge_ladder_queue_widths($attachmentId, $missing_widths);
                return;
            }
            set_transient($gate_key, time(), 30 * MINUTE_IN_SECONDS);
        } else {
            if (get_transient($gate_key)) {
                wpc_merge_ladder_queue_widths($attachmentId, $missing_widths);
                return;
            }
            set_transient($gate_key, time(), 30 * MINUTE_IN_SECONDS);
        }

        
        $queue = get_option('wpc_ladder_gen_queue', []);
        if (!is_array($queue)) $queue = [];

        
        if (count($queue) >= 1000 && !isset($queue[$attachmentId])) {
            if (function_exists('wpc_log_trigger')) {
                wpc_log_trigger('ladder_queue_full', $attachmentId);
            }
            return;
        }

        
        $existing_widths = isset($queue[$attachmentId]) ? (array) $queue[$attachmentId] : [];
        $queue[$attachmentId] = array_values(array_unique(array_merge($existing_widths, array_map('intval', $missing_widths))));
        update_option('wpc_ladder_gen_queue', $queue, false);
        update_option('wpc_ladder_gen_queue_has_items', true, false);

        if (function_exists('wpc_log_trigger')) {
            wpc_log_trigger('ladder_queued', $attachmentId, ['widths' => $missing_widths]);
        }

        
        wpc_fire_ladder_gen_worker();
    }
}





if (!function_exists('wpc_merge_ladder_queue_widths')) {
    function wpc_merge_ladder_queue_widths($attachmentId, $widths)
    {
        $queue = get_option('wpc_ladder_gen_queue', []);
        if (!is_array($queue)) return;
        if (!isset($queue[$attachmentId])) return;
        $queue[$attachmentId] = array_values(array_unique(array_merge(
            (array) $queue[$attachmentId],
            array_map('intval', $widths)
        )));
        update_option('wpc_ladder_gen_queue', $queue, false);
    }
}


if (!function_exists('wpc_site_has_basic_auth')) {
    function wpc_site_has_basic_auth()
    {
        static $cached = null;
        if ($cached !== null) return $cached;

        
        if (!empty($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'basic') === 0) {
            return $cached = true;
        }
        if (!empty($_SERVER['PHP_AUTH_USER'])) {
            return $cached = true;
        }
        
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_X_ORIGINAL_AUTHORIZATION'])) {
            return $cached = true;
        }

        
        if (defined('WPE_ATLAS_STAGING') || defined('IS_STAGING')) {
            return $cached = true;
        }

        return $cached = false;
    }
}







if (!function_exists('wpc_fire_ladder_gen_worker')) {
    function wpc_fire_ladder_gen_worker()
    {
        
        if (wpc_site_has_basic_auth()) return;


        $lg_parts = wp_parse_url(admin_url('admin-ajax.php'));
        if (!empty($lg_parts['host'])) {
            $lg_https = (!empty($lg_parts['scheme']) && $lg_parts['scheme'] === 'https');
            $lg_port  = !empty($lg_parts['port']) ? (int) $lg_parts['port'] : ($lg_https ? 443 : 80);
            $lg_host  = (string) $lg_parts['host'];
            $lg_path  = (!empty($lg_parts['path']) ? $lg_parts['path'] : '/') . '?action=wpc_async_ladder_gen&t=' . rawurlencode(wpc_loopback_token_mint('ladder'));
            $lg_req   = "POST {$lg_path} HTTP/1.1\r\nHost: {$lg_host}\r\nContent-Length: 0\r\nConnection: close\r\nUser-Agent: WPCLadderGen/1.0\r\n\r\n";
            $lg_fp = wps_ic_ajax::wpc_loopback_open_socket($lg_host, $lg_port, $lg_https, 0.2);
            if ($lg_fp) { @stream_set_timeout($lg_fp, 0, 100000); @fwrite($lg_fp, $lg_req); @fclose($lg_fp); }
        }
    }
}





if (!function_exists('wpc_detect_image_coexistence')) {
    function wpc_detect_image_coexistence()
    {
        $detected = [];

        
        if (class_exists('Jetpack_Photon') || (function_exists('jetpack_is_photon_module_active') && jetpack_is_photon_module_active())) {
            $detected[] = ['key' => 'jetpack_photon', 'name' => 'Jetpack Photon (Image CDN)'];
        }

        
        

        
        if (defined('KINSTAMU_VERSION') || !empty($_SERVER['KINSTA_CACHE_ZONE'])) {
            $detected[] = ['key' => 'kinsta_cdn', 'name' => 'Kinsta Cache/CDN'];
        }

        
        if (class_exists('WpeCommon') && class_exists('WpeImageProcessor')) {
            $detected[] = ['key' => 'wpe_image_optimizer', 'name' => 'WP Engine Image Optimizer'];
        }

        
        if (class_exists('ShortPixelPlugin') || class_exists('WPShortPixel')) {
            $detected[] = ['key' => 'shortpixel', 'name' => 'ShortPixel Image Optimizer'];
        }

        
        if (class_exists('Imagify') || class_exists('Imagify_Assets')) {
            $detected[] = ['key' => 'imagify', 'name' => 'Imagify'];
        }

        
        if (class_exists('WP_Smush') && !class_exists('WDEV_Plugin_Dashboard')) {
            $detected[] = ['key' => 'smush', 'name' => 'Smush Image Compression'];
        }

        
        if (defined('EWWW_IMAGE_OPTIMIZER_VERSION') || class_exists('EWWW_Image_Optimizer')) {
            $detected[] = ['key' => 'ewww', 'name' => 'EWWW Image Optimizer'];
        }

        
        if (class_exists('Optml_Main') || defined('OPTML_VERSION')) {
            $detected[] = ['key' => 'optimole', 'name' => 'Optimole'];
        }

        return $detected;
    }
}





if (!function_exists('wpc_modern_delivery_coexistence_notice')) {
    function wpc_modern_delivery_coexistence_notice()
    {
        if (!current_user_can('manage_options')) return;
        if (get_user_meta(get_current_user_id(), '_wpc_dismissed_coexistence_notice', true)) return;

        $settings = get_option(WPS_IC_SETTINGS, []);
        if (empty($settings['modern_image_delivery']) || $settings['modern_image_delivery'] != '1') return;

        $conflicts = wpc_detect_image_coexistence();
        if (empty($conflicts)) return;

        $names = array_map(function ($c) { return esc_html($c['name']); }, $conflicts);

        echo '<div class="notice notice-warning is-dismissible" data-wpc-notice="coexistence">';
        echo '<p><strong>WP Compress Modern Image Delivery:</strong> detected other image optimization active — ';
        echo implode(', ', $names);
        echo '. For best results, disable conflicting optimizers to avoid double-processing and URL rewrite collisions.</p>';
        echo '</div>';
    }
    add_action('admin_notices', 'wpc_modern_delivery_coexistence_notice');
}


if (!function_exists('wpc_handle_async_ladder_gen')) {
    function wpc_handle_async_ladder_gen($max_items = 1, $trigger_source = 'loopback')
    {


        $lock_key = 'wpc_ladder_worker_lock';
        if (get_transient($lock_key)) return 0;
        set_transient($lock_key, 1, 180);

        $processed = 0;
        try {
            $queue = get_option('wpc_ladder_gen_queue', []);
            if (!is_array($queue) || empty($queue)) {
                update_option('wpc_ladder_gen_queue_has_items', false, false);
                return 0;
            }

            
            wpc_record_queue_depth(count($queue));

            $iterations = 0;
            foreach ($queue as $attachmentId => $widths) {
                if ($iterations >= $max_items) break;
                $iterations++;

                $t_start = microtime(true);
                $result = wpc_generate_ladder_widths((int) $attachmentId, (array) $widths, $trigger_source, $t_start);
                unset($queue[$attachmentId]);

                if ($result) {
                    $processed++;
                    delete_post_meta($attachmentId, '_wpc_ladder_attempts');
                } else {
                    $attempts = (int) get_post_meta($attachmentId, '_wpc_ladder_attempts', true);
                    update_post_meta($attachmentId, '_wpc_ladder_attempts', $attempts + 1);
                }
            }

            update_option('wpc_ladder_gen_queue', $queue, false);
            update_option('wpc_ladder_gen_queue_has_items', !empty($queue), false);
        } finally {
            delete_transient($lock_key);
        }


        if (!empty($queue) && function_exists('wpc_fire_ladder_gen_worker')) {
            wpc_fire_ladder_gen_worker();
        }

        return $processed;
    }
}


if (!function_exists('wpc_generate_ladder_widths')) {
    function wpc_generate_ladder_widths($attachmentId, $widths, $trigger_source = 'unknown', $t_start = null)
    {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId <= 0 || empty($widths)) return false;
        if (!class_exists('wps_local_compress')) return false;
        if (!class_exists('WPC_Modern_Delivery')) return false;
        if ($t_start === null) $t_start = microtime(true);

        $meta = wp_get_attachment_metadata($attachmentId);
        if (empty($meta) || empty($meta['file'])) return false;


        $settings = get_option(WPS_IC_SETTINGS);
        $modern_delivery_on = !empty($settings['modern_image_delivery']);
        $lazy_disabled      = defined('WPC_DISABLE_LAZY_VARIANT') && WPC_DISABLE_LAZY_VARIANT === true;
        if ($modern_delivery_on && !$lazy_disabled && function_exists('wpc_run_lazy_variant_ladder')) {
            return wpc_run_lazy_variant_ladder($attachmentId, $widths, $trigger_source, $t_start, $meta);
        }


        $crops = [];
        $filenames = [];
        $source_width = WPC_Modern_Delivery::get_source_width($attachmentId, $meta);

        foreach ($widths as $w) {
            $w = (int) $w;
            if ($w <= 0) continue;


            $resolved = WPC_Modern_Delivery::resolve_variant_filename($meta, $w, 'jpg', $source_width);
            if ($resolved === null) continue;

            $key = $resolved['size_label'];
            $filenames[$key] = $resolved['filename'];
            $crops[$key] = [
                'width'  => $w,
                'height' => 0,
                'crop'   => false,
            ];
        }

        if (empty($crops)) return false;

        
        $inst = new wps_local_compress();
        if (!method_exists($inst, 'buildOptimizeParams')) return false;

        $params = $inst->buildOptimizeParams($attachmentId);
        $params['crops'] = wp_json_encode($crops);
        $params['filenames'] = wp_json_encode($filenames);
        $params['avif'] = '1';
        $params['webp'] = '1';
        
        
        $params['compressStartedAt'] = (int) round(microtime(true) * 1000);


        $params['parentImageID']  = (string) $attachmentId;
        $params['skipBackup']     = (function_exists('wpc_parent_has_backup') && wpc_parent_has_backup($attachmentId)) ? '1' : '0';
        $params['triggerContext'] = 'ladder_' . $trigger_source;


        $t_post_start = microtime(true);
        $response = wps_local_compress::postOptimize($attachmentId, $params, true, 120);
        $post_ms = (int) round((microtime(true) - $t_post_start) * 1000);

        if (is_wp_error($response)) {
            wpc_update_ladder_stats([
                'event'          => 'failed',
                'duration_ms'    => (int) round((microtime(true) - $t_start) * 1000),
                'trigger_source' => $trigger_source,
            ]);
            if (function_exists('wpc_log_trigger')) {
                wpc_log_trigger('ladder_gen_failed', $attachmentId, ['error' => $response->get_error_message(), 'post_ms' => $post_ms]);
            }
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (empty($data) || !is_array($data) || empty($data['optimizedResults'])) {
            wpc_update_ladder_stats([
                'event'          => 'failed',
                'duration_ms'    => (int) round((microtime(true) - $t_start) * 1000),
                'trigger_source' => $trigger_source,
            ]);
            if (function_exists('wpc_log_trigger')) {
                wpc_log_trigger('ladder_gen_empty_response', $attachmentId, ['post_ms' => $post_ms]);
            }
            return false;
        }

        
        $downloaded = 0;
        $widths_delivered = [];
        $formats_delivered = ['avif' => 0, 'webp' => 0, 'jpg' => 0];
        $upload_dir = wp_upload_dir();
        $base_dir = rtrim($upload_dir['basedir'], '/');
        $rel_dir = dirname($meta['file']);
        $t_dl_start = microtime(true);
        
        $ladder_persist_entries = [];

        foreach ($data['optimizedResults'] as $variant) {

            
            
            $has_bytes = !empty($variant['bytes']);
            $has_url   = !empty($variant['url']);
            if (!$has_bytes && !$has_url) continue;
            if (empty($variant['fileName'])) continue;
            $dest = $base_dir . '/' . $rel_dir . '/' . $variant['fileName'];
            $fmt = strtolower(pathinfo($variant['fileName'], PATHINFO_EXTENSION));
            $w   = (int) ($variant['width'] ?? 0);
            
            
            if ($w === 0) {
                if (preg_match('/-(\d{3,})\.(?:avif|webp|jpg|jpeg|png)$/i', $variant['fileName'], $m)) {
                    $w = (int) $m[1];
                } elseif (!empty($variant['sizeLabel']) && preg_match('/(\d{3,})/', $variant['sizeLabel'], $m)) {
                    $w = (int) $m[1];
                }
            }


            if (file_exists($dest) && filesize($dest) > 0) {
                $downloaded++;
                if ($w > 0) $widths_delivered[$w] = true;
                if (isset($formats_delivered[$fmt])) $formats_delivered[$fmt]++;
                if (!empty($variant['sizeLabel'])) {
                    $ladder_persist_entries[] = [
                        'size_label' => (string) $variant['sizeLabel'],
                        'format'     => $fmt === 'jpg' ? 'jpeg' : $fmt,
                        'size'       => (int) filesize($dest),
                        'url'        => trailingslashit($upload_dir['baseurl']) . $rel_dir . '/' . $variant['fileName'],
                    ];
                }
                continue;
            }

            
            if ($has_bytes) {
                $bytes = base64_decode($variant['bytes'], true);
                $source_attr = 'ladder_gen_inline';
                $url_for_log = '';
            } else {
                $bytes = wp_remote_retrieve_body(wp_remote_get($variant['url'], ['timeout' => 30]));
                $source_attr = 'ladder_gen_download';
                $url_for_log = $variant['url'];
            }


            if (!empty($bytes) && wpc_is_valid_image_bytes($bytes, $fmt, $attachmentId, $source_attr, ['size_label' => $variant['sizeLabel'] ?? '', 'url' => $url_for_log]) && @file_put_contents($dest, $bytes)) {
                @chmod($dest, 0644);
                $downloaded++;
                if ($w > 0) $widths_delivered[$w] = true;
                if (isset($formats_delivered[$fmt])) $formats_delivered[$fmt]++;
                
                
                if (!empty($variant['sizeLabel'])) {
                    $ladder_persist_entries[] = [
                        'size_label' => (string) $variant['sizeLabel'],
                        'format'     => $fmt === 'jpg' ? 'jpeg' : $fmt,
                        'size'       => strlen($bytes),
                        'url'        => trailingslashit($upload_dir['baseurl']) . $rel_dir . '/' . $variant['fileName'],
                    ];
                }
            }
        }
        
        
        if (!empty($ladder_persist_entries) && function_exists('wpc_persist_inline_variants')) {
            wpc_persist_inline_variants($attachmentId, $ladder_persist_entries, 'ladder_gen_inline');
        }

        $download_ms = (int) round((microtime(true) - $t_dl_start) * 1000);
        $total_ms    = (int) round((microtime(true) - $t_start) * 1000);

        
        if (function_exists('wpc_log_variant_gen')) {
            wpc_log_variant_gen($attachmentId, array_map('intval', $widths), array_keys($formats_delivered), [
                'widths_delivered'  => array_values(array_map('intval', array_keys($widths_delivered))),
                'formats_delivered' => $formats_delivered,
                'duration_ms'       => $total_ms,
                'post_ms'           => $post_ms,
                'download_ms'       => $download_ms,
                'trigger_source'    => $trigger_source,
                'downloaded'        => $downloaded,
                'success'           => $downloaded > 0,
            ]);
        }


        wpc_update_ladder_stats([
            'event'             => $downloaded > 0 ? 'success' : 'failed',
            'duration_ms'       => $total_ms,
            'trigger_source'    => $trigger_source,
            'formats_delivered' => $formats_delivered,
        ]);

        if (function_exists('wpc_log_trigger')) {
            wpc_log_trigger('ladder_gen_success', $attachmentId, [
                'widths'         => $widths,
                'downloaded'     => $downloaded,
                'duration_ms'    => $total_ms,
                'post_ms'        => $post_ms,
                'trigger_source' => $trigger_source,
            ]);
        }

        return $downloaded > 0;
    }
}







if (!function_exists('wpc_ladder_shutdown_hook')) {
    function wpc_ladder_shutdown_hook()
    {
        if (!get_option('wpc_ladder_gen_queue_has_items')) return;
        if (is_admin()) return;
        if (!function_exists('fastcgi_finish_request')) return; 
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) return;
        @fastcgi_finish_request();
        @set_time_limit(150);

        wpc_handle_async_ladder_gen(1, 'shutdown');
    }
    add_action('shutdown', 'wpc_ladder_shutdown_hook', 1);
}






if (!function_exists('wpc_ladder_admin_hook')) {
    function wpc_ladder_admin_hook()
    {
        if (!get_option('wpc_ladder_gen_queue_has_items')) return;
        
        
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $ajax_action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
            if ($ajax_action === 'wpc_async_ladder_gen' || $ajax_action === 'wpc_ladder_process_manual') return;
        }
        
        if (function_exists('wpc_fire_ladder_gen_worker')) {
            wpc_fire_ladder_gen_worker();
            return;
        }
        wpc_handle_async_ladder_gen(1, 'admin');
    }
    add_action('admin_init', 'wpc_ladder_admin_hook', 99);
}



if (!function_exists('wpc_loopback_token_mint')) {
    function wpc_loopback_token_mint($name)
    {
        $t = function_exists('wp_generate_password') ? wp_generate_password(20, false) : md5(uniqid('', true));
        set_transient('wpc_lbtok_' . $name, $t, 120);
        return $t;
    }
    function wpc_loopback_token_ok($name)
    {
        if (function_exists('current_user_can') && current_user_can('manage_options')) {
            return true;
        }
        $t = isset($_GET['t']) ? (string) $_GET['t'] : '';
        $s = (string) get_transient('wpc_lbtok_' . $name);
        if ($t === '' || $s === '' || !hash_equals($s, $t)) {
            return false;
        }
        delete_transient('wpc_lbtok_' . $name);
        return true;
    }
}


if (!function_exists('wpc_register_async_ladder_gen_ajax')) {
    function wpc_register_async_ladder_gen_ajax()
    {
        if (!wpc_loopback_token_ok('ladder')) {
            wp_die('', '', ['response' => 403]);
        }

        wpc_handle_async_ladder_gen(8, 'loopback');
        wp_die('', '', ['response' => 200]);
    }
    add_action('wp_ajax_wpc_async_ladder_gen', 'wpc_register_async_ladder_gen_ajax');
    add_action('wp_ajax_nopriv_wpc_async_ladder_gen', 'wpc_register_async_ladder_gen_ajax');
}


if (!function_exists('wpc_register_prewarm_ajax')) {
    function wpc_register_prewarm_ajax()
    {
        if (!wpc_loopback_token_ok('prewarm')) {
            wp_die('', '', ['response' => 403]);
        }
        
        if (get_transient('wpc_prewarm_lock')) {
            wp_die('', '', ['response' => 200]);
        }
        set_transient('wpc_prewarm_lock', 1, 180);
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            wp_die('', '', ['response' => 200]);
        }
        @set_time_limit(120);
        ignore_user_abort(true);
        if (function_exists('wpc_modern_delivery_prewarm')) {
            wpc_modern_delivery_prewarm();
        }
        wp_die('', '', ['response' => 200]);
    }
    add_action('wp_ajax_wpc_modern_delivery_prewarm', 'wpc_register_prewarm_ajax');
    add_action('wp_ajax_nopriv_wpc_modern_delivery_prewarm', 'wpc_register_prewarm_ajax');
}


if (!function_exists('wpc_register_manual_process_ajax')) {
    function wpc_register_manual_process_ajax()
    {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden', '', ['response' => 403]);
        }
        $processed = wpc_handle_async_ladder_gen(10, 'manual');
        $queue = get_option('wpc_ladder_gen_queue', []);
        wp_send_json_success([
            'processed' => $processed,
            'remaining' => is_array($queue) ? count($queue) : 0,
        ]);
    }
    add_action('wp_ajax_wpc_ladder_process_manual', 'wpc_register_manual_process_ajax');
}


if (!function_exists('wpc_ladder_cron_hook')) {
    function wpc_ladder_cron_hook()
    {
        if (!get_option('wpc_ladder_gen_queue_has_items')) return;
        wpc_handle_async_ladder_gen(5, 'cron');
    }
    add_action('wpc_ladder_gen_cron', 'wpc_ladder_cron_hook');
    if (!wp_next_scheduled('wpc_ladder_gen_cron')) {
        wp_schedule_event(time() + 300, 'hourly', 'wpc_ladder_gen_cron');
    }
}
















if (!function_exists('wpc_loopback_token821')) {
    function wpc_loopback_token821($action, $imageID, $bucket = null)
    {
        if (!function_exists('wp_salt') || !function_exists('hash_hmac')) { return ''; }
        $bucket = ($bucket === null) ? (int) floor(time() / 300) : (int) $bucket;
        return hash_hmac('sha256', $action . '|' . (int) $imageID . '|' . $bucket, wp_salt('nonce'));
    }
    function wpc_loopback_token_ok821($action, $imageID, $token)
    {
        $token = (string) $token;
        if ($token === '' || !function_exists('hash_equals')) { return false; }
        
        
        $now = (int) floor(time() / 300);
        foreach ([$now, $now - 1] as $b) {
            $expect = wpc_loopback_token821($action, $imageID, $b);
            if ($expect !== '' && hash_equals($expect, $token)) { return true; }
        }
        return false;
    }
}

if (!function_exists('wpc_download_variants_hook')) {
    function wpc_download_variants_hook($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID || get_post_type($imageID) !== 'attachment') return;

        
        $lock_key = 'wpc_download_lock_' . $imageID;
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 120);

        try {
            
            if (get_post_meta($imageID, 'ic_status', true) !== 'compressed') {
                delete_post_meta($imageID, '_wpc_pending_downloads');
                delete_post_meta($imageID, '_wpc_download_fail_count');
                return;
            }

            
            
            $fail_count = (int) get_post_meta($imageID, '_wpc_download_fail_count', true);
            if ($fail_count >= 5) {
                delete_post_meta($imageID, '_wpc_pending_downloads');
                if (function_exists('wpc_log_trigger')) {
                    wpc_log_trigger('download_abandoned', $imageID, ['fail_count' => $fail_count]);
                }
                return;
            }

            $plan = get_post_meta($imageID, '_wpc_pending_downloads', true);
            if (!is_array($plan) || empty($plan['downloads'])) return;

            if (!class_exists('wps_local_compress')) return;
            $compress = new wps_local_compress();
            $result = $compress->downloadVariants($imageID, $plan['downloads'], $plan['service_skipped'] ?? []);


            $real_error = empty($result['done']) && !empty($result['errors']);
            if (!$real_error) {
                delete_post_meta($imageID, '_wpc_pending_downloads');
                delete_post_meta($imageID, '_wpc_download_fail_count');
            } else {
                
                update_post_meta($imageID, '_wpc_download_fail_count', $fail_count + 1);
            }
        } finally {
            delete_transient($lock_key);
        }
    }
    add_action('wpc_download_variants', 'wpc_download_variants_hook', 10, 1);

    
    
    
    function wpc_download_variants_ajax()
    {
        $wpc_id821 = (int) ($_REQUEST['imageID'] ?? 0);
        if (!wpc_loopback_token_ok821('wpc_download_variants', $wpc_id821, $_REQUEST['t'] ?? '')) {
            if (function_exists('status_header')) { status_header(403); }
            wp_die('', '', ['response' => 403]);
        }
        wpc_download_variants_hook($wpc_id821);
        wp_die();
    }
    add_action('wp_ajax_wpc_download_variants',        'wpc_download_variants_ajax');
    add_action('wp_ajax_nopriv_wpc_download_variants', 'wpc_download_variants_ajax');
}


if (!function_exists('wpc_fire_download_worker')) {
    function wpc_fire_download_worker($imageID)
    {
        if (function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth()) return;


        $dl_parts = wp_parse_url(admin_url('admin-ajax.php'));
        if (!empty($dl_parts['host'])) {
            $dl_https = (!empty($dl_parts['scheme']) && $dl_parts['scheme'] === 'https');
            $dl_port  = !empty($dl_parts['port']) ? (int) $dl_parts['port'] : ($dl_https ? 443 : 80);
            $dl_host  = (string) $dl_parts['host'];
            $dl_path  = (!empty($dl_parts['path']) ? $dl_parts['path'] : '/') . '?action=wpc_download_variants';
            $dl_body  = http_build_query(['imageID' => (int) $imageID, 't' => wpc_loopback_token821('wpc_download_variants', $imageID)]);
            $dl_req   = "POST {$dl_path} HTTP/1.1\r\nHost: {$dl_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($dl_body) . "\r\nConnection: close\r\nUser-Agent: WPCDownloadVariants/1.0\r\n\r\n" . $dl_body;
            $dl_fp = wps_ic_ajax::wpc_loopback_open_socket($dl_host, $dl_port, $dl_https, 0.2);
            if ($dl_fp) { @stream_set_timeout($dl_fp, 0, 100000); @fwrite($dl_fp, $dl_req); @fclose($dl_fp); }
        }
    }
}


if (!function_exists('wpc_admin_drain_pending_downloads')) {
    function wpc_admin_drain_pending_downloads()
    {
        if (!is_admin() || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) return;

        
        $wpc_didle99 = (int) get_option('wpc_admin_drain_idle_at');
        if ($wpc_didle99 && (time() - $wpc_didle99) < 60) return;

        
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) return;

        global $wpdb;

        
        $dl_rows = $wpdb->get_results("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_wpc_pending_downloads'
            LIMIT 3
        ");
        foreach ((array) $dl_rows as $row) {
            wpc_download_variants_hook((int) $row->post_id);
        }

        
        $regen_rows = $wpdb->get_results("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_wpc_pending_thumb_regen'
            LIMIT 3
        ");
        foreach ((array) $regen_rows as $row) {
            if (function_exists('wpc_regen_thumbs_hook')) wpc_regen_thumbs_hook((int) $row->post_id);
        }


        $retry_rows = $wpdb->get_results("
            SELECT post_id, meta_value FROM {$wpdb->postmeta}
            WHERE meta_key = '_wpc_service_retry_attempts'
            AND CAST(meta_value AS UNSIGNED) > 0
            AND CAST(meta_value AS UNSIGNED) < 3
            LIMIT 3
        ");
        foreach ((array) $retry_rows as $row) {
            $last_fail = get_post_meta((int) $row->post_id, '_wpc_last_post_fail', true);
            $last_at = is_array($last_fail) ? (int) ($last_fail['at'] ?? 0) : 0;
            if ($last_at > 0 && (time() - $last_at) < 30) continue;
            if (function_exists('wpc_retry_compress_hook')) wpc_retry_compress_hook((int) $row->post_id);
        }


        $wpc_qbusy99 = false;
        if (!get_transient('wpc_compress_lock')) {
            wp_cache_delete('wpc_compress_queue', 'options');
            $cq = get_option('wpc_compress_queue', []);
            if (is_array($cq) && !empty($cq) && class_exists('wps_local_compress')) {
                $wpc_qbusy99 = true;
                $cq_id = (int) array_shift($cq);
                update_option('wpc_compress_queue', $cq, false);
                if ($cq_id > 0 && get_post_type($cq_id) === 'attachment') {
                    set_transient('wpc_compress_lock', time(), 300);
                    try {
                        $cq_obj = new wps_local_compress();
                        if (method_exists($cq_obj, 'backup_all_sizes')) {
                            $cq_obj->backup_all_sizes($cq_id);
                        }
                        $cq_obj->singleCompressV4($cq_id, 'silent', true, 'page-load-drain');
                    } finally {
                        delete_transient('wpc_compress_lock');
                    }
                } else {
                    delete_transient('wps_ic_compress_' . $cq_id);
                }
            }
        } else {
            $wpc_qbusy99 = true;
        }

        
        if (empty($dl_rows) && empty($regen_rows) && empty($retry_rows) && !$wpc_qbusy99) {
            update_option('wpc_admin_drain_idle_at', time(), false);
        }
    }
    add_action('admin_init', 'wpc_admin_drain_pending_downloads', 99);
}


if (!function_exists('wpc_handle_bg_swap_callback')) {
    function wpc_handle_bg_swap_callback()
    {
        if (empty($_GET['wpc_bg_swap']) || $_GET['wpc_bg_swap'] !== '1') return;

        
        


        $bgswap_entry_t = microtime(true);
        $bgswap_raw_peek = file_get_contents('php://input');
        $bgswap_body_peek = is_string($bgswap_raw_peek) ? json_decode($bgswap_raw_peek, true) : null;
        error_log(sprintf(
            '[WPC BgSwap ENTRY] imageID=%s size=%s fmt=%s body_bytes=%d cs=%s ip=%s ts=%.3f',
            $_GET['imageID'] ?? '-',
            (is_array($bgswap_body_peek) && isset($bgswap_body_peek['sizeLabel'])) ? sanitize_key((string) $bgswap_body_peek['sizeLabel']) : '-',
            (is_array($bgswap_body_peek) && isset($bgswap_body_peek['format'])) ? strtolower(sanitize_key((string) $bgswap_body_peek['format'])) : '-',
            isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0,
            (is_array($bgswap_body_peek) && isset($bgswap_body_peek['compressStartedAt'])) ? (string) $bgswap_body_peek['compressStartedAt'] : '-',
            $_SERVER['REMOTE_ADDR'] ?? '-',
            $bgswap_entry_t
        ));

        $respond = function ($code, $body) {
            http_response_code($code);
            header('Content-Type: application/json');
            echo wp_json_encode($body);
            exit;
        };

        
        $provided = isset($_GET['apikey']) ? (string) $_GET['apikey'] : '';
        $options  = get_option(WPS_IC_OPTIONS);
        $expected = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        if ($expected === '' || !hash_equals($expected, $provided)) {
            $respond(401, ['error' => 'auth']);
        }

        
        $imageID = (int) ($_GET['imageID'] ?? 0);
        if (!$imageID || get_post_type($imageID) !== 'attachment') {
            $respond(404, ['error' => 'unknown_image']);
        }
        
        $content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($content_length > 10485760) { 
            $respond(413, ['error' => 'body_too_large', 'max' => 10485760]);
        }


        $raw = file_get_contents('php://input');
        if (empty($raw)) $respond(400, ['error' => 'empty_body']);
        $body = json_decode($raw, true);
        if (!is_array($body)) $respond(400, ['error' => 'invalid_json']);


        $callback_cs_ms = (float) ($body['compressStartedAt'] ?? 0);
        $callback_cs    = $callback_cs_ms > 0 ? (int) floor($callback_cs_ms / 1000) : 0;
        $last_compress  = $callback_cs > 0
            ? $callback_cs
            : (int) get_post_meta($imageID, '_wpc_compress_started_at', true);
        $last_restore   = (int) get_post_meta($imageID, '_wpc_restore_completed_at', true);

        if ($last_restore > 0 && $last_compress < $last_restore) {
            $cs_source = $callback_cs > 0 ? 'callback' : 'post_meta';
            error_log('[WPC BgSwap] imageID=' . $imageID . ' refused — stale generation (compress=' . $last_compress . ' src=' . $cs_source . ' < restore=' . $last_restore . ')');
            $respond(410, ['error' => 'stale_generation', 'imageID' => $imageID, 'last_compress' => $last_compress, 'last_restore' => $last_restore, 'cs_source' => $cs_source]);
        }

        $sizeLabel = isset($body['sizeLabel']) ? sanitize_key($body['sizeLabel']) : '';
        $format    = isset($body['format']) ? strtolower(sanitize_key($body['format'])) : '';
        $b64       = isset($body['bytes']) ? (string) $body['bytes'] : '';


        $is_no_improvement = !empty($body['noImprovement']);

        if (!$sizeLabel || !$format) {
            $respond(400, ['error' => 'missing_fields']);
        }
        if (!in_array($format, ['jpeg', 'jpg', 'webp', 'avif'], true)) {
            $respond(400, ['error' => 'invalid_format']);
        }
        if (!$is_no_improvement && !$b64) {
            $respond(400, ['error' => 'missing_fields']);
        }


        if ($is_no_improvement) {
            $norm_fmt    = ($format === 'jpg') ? 'jpeg' : $format;
            $lookup_key  = ($norm_fmt === 'jpeg') ? $sizeLabel : ($sizeLabel . '-' . $norm_fmt);
            $reason      = isset($body['reason']) ? sanitize_text_field((string) $body['reason']) : '';
            $baseline_kb = isset($body['baselineKb']) ? (float) $body['baselineKb'] : null;
            $widen_alts  = (isset($body['widenAltKbs']) && is_array($body['widenAltKbs']))
                ? array_values(array_map('floatval', $body['widenAltKbs']))
                : [];

            
            
            global $wpdb;
            $ni_lock_name  = 'wpc_bg_meta_' . (int) $imageID;
            $got_ni_lock   = wpc_worker_lock($ni_lock_name);
            $ni_t_key      = 'wpc_bg_meta_lock_' . $imageID;
            $got_ni_t_lock = false;
            $ni_obj_cache  = function_exists('wp_cache_add') && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
            if (!$got_ni_lock) {
                for ($i = 0; $i < 50 && !$got_ni_t_lock; $i++) {
                    if ($ni_obj_cache) {
                        $got_ni_t_lock = wp_cache_add($ni_t_key, 1, 'wpc', 30);
                    } else {
                        if (!get_transient($ni_t_key)) {
                            set_transient($ni_t_key, 1, 30);
                            $got_ni_t_lock = true;
                        }
                    }
                    if (!$got_ni_t_lock) usleep(50000);
                }
            }
            try {
                $variants_ni = get_post_meta($imageID, 'ic_local_variants', true);
                if (!is_array($variants_ni)) $variants_ni = [];
                if (!isset($variants_ni[$lookup_key])) {
                    
                    
                    $variants_ni[$lookup_key] = [
                        'url'          => '',
                        'originalSize' => 0,
                        'size'         => 0,
                        'savings'      => 0,
                        'skipped'      => true,
                        'local'        => false,
                    ];
                }
                $variants_ni[$lookup_key]['bg_no_improvement']   = true;
                $variants_ni[$lookup_key]['bg_ni_reason']        = $reason;
                $variants_ni[$lookup_key]['bg_ni_baseline_kb']   = $baseline_kb;
                $variants_ni[$lookup_key]['bg_ni_widen_alt_kbs'] = $widen_alts;
                $variants_ni[$lookup_key]['bg_ni_at']            = time();
                update_post_meta($imageID, 'ic_local_variants', $variants_ni);
            } finally {
                if ($got_ni_lock) {
                    wpc_worker_unlock($ni_lock_name);
                }
                if ($got_ni_t_lock) {
                    if ($ni_obj_cache && function_exists('wp_cache_delete')) wp_cache_delete($ni_t_key, 'wpc');
                    delete_transient($ni_t_key);
                }
            }

            error_log(sprintf(
                '[WPC BgSwap] imageID=%d size=%s fmt=%s NO_IMPROVEMENT reason=%s baseline_kb=%s widen_alts=%s',
                $imageID, $sizeLabel, $norm_fmt,
                $reason !== '' ? $reason : '-',
                $baseline_kb !== null ? (string) $baseline_kb : '-',
                empty($widen_alts) ? '-' : implode(',', $widen_alts)
            ));

            $respond(200, [
                'success'      => true,
                'acknowledged' => 'no_improvement',
                'sizeLabel'    => $sizeLabel,
                'format'       => $norm_fmt,
            ]);
        }

        
        $bytes = base64_decode($b64, true);
        if ($bytes === false || strlen($bytes) === 0) {
            $respond(400, ['error' => 'decode_fail']);
        }


        $variants = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($variants)) $variants = [];
        $norm_fmt = ($format === 'jpg') ? 'jpeg' : $format;
        $lookup_key = ($norm_fmt === 'jpeg') ? $sizeLabel : ($sizeLabel . '-' . $norm_fmt);
        $entry_exists = isset($variants[$lookup_key]);


        $body_filename = isset($body['fileName']) ? sanitize_file_name((string) $body['fileName']) : '';
        if ($body_filename !== '') {
            $file_name = $body_filename;
        } elseif ($entry_exists) {
            $variant_url = $variants[$lookup_key]['url'] ?? '';
            if (empty($variant_url)) {
                $respond(404, ['error' => 'no_variant_url']);
            }
            $file_name = basename($variant_url);
        } else {
            
            
            $respond(404, ['error' => 'unknown_variant', 'lookup' => $lookup_key]);
        }
        $attached  = get_attached_file($imageID);
        $dir       = $attached ? (dirname($attached) . '/') : '';
        if (!$dir) $respond(500, ['error' => 'no_attached_dir']);
        $wp_path = $dir . $file_name;


        if (class_exists('wps_local_compress')) {
            $compress_local = new wps_local_compress();
            if (method_exists($compress_local, 'backup_all_sizes')) {
                $compress_local->backup_all_sizes($imageID);
            }
        }


        if (!wpc_is_valid_image_bytes($bytes, $norm_fmt, $imageID, 'bg_swap_callback')) {
            $respond(400, ['error' => 'invalid_image_bytes']);
        }

        
        
        
        
        
        
        
        if (!function_exists('wpc_v2_store_bytes655')) {
            @include_once __DIR__ . '/../v2/v2-store.php';
        }
        if (!function_exists('wpc_v2_store_bytes655')) {
            $respond(500, ['error' => 'write_fail']);
        }
        $wpc_put656 = wpc_v2_store_bytes655($bytes, $wp_path);
        if (empty($wpc_put656['ok'])) {
            $respond(500, ['error' => (string) $wpc_put656['error']]);
        }


        if (function_exists('wpc_purge_cdn_urls_single')) {
            wpc_purge_cdn_urls_single($imageID, $wp_path);
        }


        $bg_kb         = $body['bgKb'] ?? null;
        $bg_s2         = $body['bgS2'] ?? null;
        $bg_q          = $body['bgQ'] ?? null;
        $fast_kb       = $body['fastPathKb'] ?? null;
        $fast_s2       = $body['fastPathS2'] ?? null;
        $was_skipped_on_fast_path = ($fast_kb === null);


        global $wpdb;
        $mysql_lock_name = 'wpc_bg_meta_' . (int) $imageID;
        
        


        $bgswap_lock_t0 = microtime(true);
        $got_mysql_lock = wpc_worker_lock($mysql_lock_name);

        
        $lock_key = 'wpc_bg_meta_lock_' . $imageID;
        $got_transient_lock = false;
        $has_obj_cache = function_exists('wp_cache_add') && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        if (!$got_mysql_lock) {
            error_log('[WPC BgSwap] image=' . $imageID . ' GET_LOCK failed (' . var_export($mysql_lock_state, true) . ') — using transient fallback');
            for ($i = 0; $i < 50 && !$got_transient_lock; $i++) {
                if ($has_obj_cache) {
                    $got_transient_lock = wp_cache_add($lock_key, 1, 'wpc', 30);
                } else {
                    if (!get_transient($lock_key)) {
                        set_transient($lock_key, 1, 30);
                        $got_transient_lock = true;
                    }
                }
                if (!$got_transient_lock) usleep(50000); 
            }
        }
        $bgswap_lock_acq_ms = (int) round((microtime(true) - $bgswap_lock_t0) * 1000);
        
        
        if ($bgswap_lock_acq_ms >= 50) {
            error_log(sprintf(
                '[WPC BgSwap LOCK] imageID=%d size=%s fmt=%s acq_ms=%d via=%s',
                $imageID, $sizeLabel, $norm_fmt, $bgswap_lock_acq_ms,
                $got_mysql_lock ? 'mysql' : ($got_transient_lock ? 'transient' : 'failed')
            ));
        }
        $bgswap_crit_t0 = microtime(true);
        try {
            
            $variants_fresh = get_post_meta($imageID, 'ic_local_variants', true);
            if (!is_array($variants_fresh)) $variants_fresh = [];

            if (!isset($variants_fresh[$lookup_key])) {
                $variants_fresh[$lookup_key] = [
                    'url'          => '',
                    'originalSize' => 0,
                    'size'         => strlen($bytes),
                    'savings'      => 0,
                    'skipped'      => false,
                    'local'        => true,
                    'bg_source'    => $was_skipped_on_fast_path ? 'skip_path' : 'bg_upgrade',
                ];
            } else {
                $variants_fresh[$lookup_key]['size']  = strlen($bytes);
                $variants_fresh[$lookup_key]['local'] = true;
            }
            $variants_fresh[$lookup_key]['bg_upgraded']  = time();
            $variants_fresh[$lookup_key]['bg_kb']        = $bg_kb;
            $variants_fresh[$lookup_key]['bg_s2']        = $bg_s2;
            $variants_fresh[$lookup_key]['bg_q']         = $bg_q;
            $variants_fresh[$lookup_key]['fast_path_kb'] = $fast_kb;
            $variants_fresh[$lookup_key]['fast_path_s2'] = $fast_s2;
            update_post_meta($imageID, 'ic_local_variants', $variants_fresh);


            $verify = get_post_meta($imageID, 'ic_local_variants', true);
            $expected_size = strlen($bytes);
            $entry_landed = is_array($verify)
                && isset($verify[$lookup_key])
                && (int) ($verify[$lookup_key]['size'] ?? -1) === $expected_size;
            if (!$entry_landed) {
                error_log('[WPC BgSwap] image=' . $imageID . ' verification FAILED for ' . $lookup_key . ' (expected_size=' . $expected_size . ') — retrying once');
                $variants_retry = get_post_meta($imageID, 'ic_local_variants', true);
                if (!is_array($variants_retry)) $variants_retry = [];
                $variants_retry[$lookup_key] = $variants_fresh[$lookup_key];
                update_post_meta($imageID, 'ic_local_variants', $variants_retry);
                $variants_fresh = $variants_retry;
            }
            $variants = $variants_fresh;
        } finally {


            $bgswap_crit_ms = (int) round((microtime(true) - $bgswap_crit_t0) * 1000);
            if ($bgswap_crit_ms >= 100) {
                error_log(sprintf(
                    '[WPC BgSwap CRIT] imageID=%d size=%s fmt=%s crit_ms=%d',
                    $imageID, $sizeLabel, $norm_fmt, $bgswap_crit_ms
                ));
            }
            if ($got_mysql_lock) {
                wpc_worker_unlock($mysql_lock_name);
            }
            if ($got_transient_lock) {
                if ($has_obj_cache && function_exists('wp_cache_delete')) wp_cache_delete($lock_key, 'wpc');
                delete_transient($lock_key);
            }
        }

        
        


        if ($norm_fmt === 'jpeg' && !in_array($sizeLabel, ['original', 'scaled'], true)) {
            $bgswap_mirror_t0 = microtime(true);
            $wp_meta = wp_get_attachment_metadata($imageID);
            if (is_array($wp_meta)) {
                $dims = @getimagesize($wp_path);
                $mirror_w = is_array($dims) ? (int) ($dims[0] ?? 0) : 0;
                $mirror_h = is_array($dims) ? (int) ($dims[1] ?? 0) : 0;
                if ($mirror_w > 0 && $mirror_h > 0) {
                    if (!isset($wp_meta['sizes']) || !is_array($wp_meta['sizes'])) {
                        $wp_meta['sizes'] = [];
                    }
                    $wp_meta['sizes'][$sizeLabel] = [
                        'file'      => $file_name,
                        'width'     => $mirror_w,
                        'height'    => $mirror_h,
                        'mime-type' => 'image/jpeg',
                        'filesize'  => strlen($bytes),
                    ];


                    $instr_meta_t0 = microtime(true);
                    wp_update_attachment_metadata($imageID, $wp_meta);
                    $bgswap_meta_update_ms = (int) round((microtime(true) - $instr_meta_t0) * 1000);
                    $bgswap_mirror_ms = (int) round((microtime(true) - $bgswap_mirror_t0) * 1000);
                    error_log(sprintf(
                        '[WPC BgSwap Mirror] image=%d size=%s mirrored to _wp_attachment_metadata (%dx%d %d bytes) mirror_ms=%d wp_meta_update_ms=%d setup_ms=%d',
                        $imageID, $sizeLabel, $mirror_w, $mirror_h, strlen($bytes), $bgswap_mirror_ms, $bgswap_meta_update_ms, max(0, $bgswap_mirror_ms - $bgswap_meta_update_ms)
                    ));
                } else {
                    error_log(sprintf(
                        '[WPC BgSwap Mirror] image=%d size=%s skipped — getimagesize failed on %s',
                        $imageID, $sizeLabel, $wp_path
                    ));
                }
            }
        }


        $best = wpc_compute_best_savings($variants, $imageID);
        if ($best['pct'] > 0 && $best['orig'] > 0) {
            update_post_meta($imageID, 'ic_savings',          round($best['pct'], 1));
            update_post_meta($imageID, 'ic_savings_format',   $best['format']);
            update_post_meta($imageID, 'ic_savings_bytes',    $best['orig'] - $best['opt']);
            update_post_meta($imageID, 'ic_savings_baseline', $best['orig']);
        }


        update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
        delete_transient('wps_ic_compress_' . $imageID);


        $bg_chip_fmt  = strtoupper($norm_fmt);
        $bg_chip_size = ucfirst(str_replace(['_', '-'], ' ', $sizeLabel));
        set_transient('wps_ic_heartbeat_' . $imageID, [
            'imageID'         => $imageID,
            'status'          => 'compressed',
            'event'           => 'bg_variant_arrived',
            'time'            => time(),
            'bg_variant_fmt'  => $bg_chip_fmt,
            'bg_variant_size' => $bg_chip_size,
        ], 300);

        error_log(sprintf(
            '[WPC BgSwap] imageID=%d size=%s fmt=%s bytes=%d bg_kb=%s bg_s2=%s fast_kb=%s fast_s2=%s path=%s%s',
            $imageID, $sizeLabel, $norm_fmt, strlen($bytes),
            $bg_kb ?? '-', $bg_s2 ?? '-',
            $fast_kb ?? '-', $fast_s2 ?? '-',
            $was_skipped_on_fast_path ? 'skip_path' : 'bg_upgrade',
            $entry_exists ? '' : ' (new entry)'
        ));


        $verify_post_meta = !empty($entry_landed);
        $verify_disk      = file_exists($wp_path) && @filesize($wp_path) === strlen($bytes);
        $variant_persisted = $verify_post_meta;
        $callback_arrival_t = time();


        $cs_for_ack = isset($callback_cs) && $callback_cs > 0
            ? (int) $callback_cs
            : (int) get_post_meta($imageID, '_wpc_compress_started_at', true);


        if (class_exists('WPC_Modern_Delivery')
            && method_exists('WPC_Modern_Delivery', 'release_backfill_lock')) {
            WPC_Modern_Delivery::release_backfill_lock($imageID, $sizeLabel, $norm_fmt);
        }

        error_log(sprintf(
            '[WPC BgSwap ACK] imageID=%d size=%s fmt=%s persisted=%s disk=%s cs=%d arrival=%d reason=%s',
            $imageID, $sizeLabel, $norm_fmt,
            $variant_persisted ? 'true' : 'false',
            $verify_disk ? 'true' : 'false',
            $cs_for_ack, $callback_arrival_t,
            '-'
        ));

        $respond(200, [
            'success'              => true,
            'written'              => strlen($bytes),
            'sizeLabel'            => $sizeLabel,
            'format'               => $norm_fmt,
            'new_entry'            => !$entry_exists,
            'bg_source'            => $was_skipped_on_fast_path ? 'skip_path' : 'bg_upgrade',

            'variant_persisted'    => $variant_persisted,
            'verify_post_meta'     => $verify_post_meta,
            'verify_disk'          => $verify_disk,
            'skip_reason'          => null,
            'compress_started_at'  => $cs_for_ack,
            'callback_arrival_t'   => $callback_arrival_t,
        ]);
    }
    add_action('init', 'wpc_handle_bg_swap_callback', 5);
}


if (!function_exists('wpc_regen_thumbs_hook')) {
    function wpc_regen_thumbs_hook($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID || get_post_type($imageID) !== 'attachment') return;

        
        
        $lock_key = 'wpc_regen_thumbs_lock_' . $imageID;
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 180);


        $cap = defined('WPC_MAX_CONCURRENT_REGEN') ? max(1, (int) WPC_MAX_CONCURRENT_REGEN) : 1;
        $active = (int) get_transient('wpc_regen_active_count');
        if ($active >= $cap) {
            
            
            delete_transient($lock_key);
            return;
        }
        set_transient('wpc_regen_active_count', $active + 1, 300);

        try {
            
            
            if (get_post_meta($imageID, 'ic_status', true) !== 'restored') {
                delete_post_meta($imageID, '_wpc_pending_thumb_regen');
                return;
            }

            $plan = get_post_meta($imageID, '_wpc_pending_thumb_regen', true);
            if (!is_array($plan)) return;

            $regenSource = $plan['regen_source'] ?? get_attached_file($imageID);
            if (!$regenSource || !file_exists($regenSource)) {
                delete_post_meta($imageID, '_wpc_pending_thumb_regen');
                
                
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored', 'time' => time()], 60);
                return;
            }

            @set_time_limit(180);
            wp_raise_memory_limit('image');

            
            
            if (class_exists('wps_local_compress')) {
                $ic = new wps_local_compress();
                remove_filter('wp_generate_attachment_metadata', [$ic, 'on_upload'], PHP_INT_MAX);
            }

            $t_start = microtime(true);
            $newMeta = wp_generate_attachment_metadata($imageID, $regenSource);
            if ($newMeta && !is_wp_error($newMeta)) {
                wp_update_attachment_metadata($imageID, $newMeta);
            }
            $regen_duration = round(microtime(true) - $t_start, 2);


            $missing = [];
            if (is_array($newMeta) && !empty($newMeta['sizes']) && is_array($newMeta['sizes'])) {
                $upload_dir = wp_upload_dir();
                $base_dir = rtrim($upload_dir['basedir'], '/');
                $rel_dir = !empty($newMeta['file']) ? dirname($newMeta['file']) : '';
                foreach ($newMeta['sizes'] as $size_name => $size_info) {
                    if (empty($size_info['file'])) continue;
                    $disk_path = $base_dir . '/' . $rel_dir . '/' . $size_info['file'];
                    if (!file_exists($disk_path)) {
                        $missing[] = $size_name;
                    }
                }
            }

            if (!empty($missing)) {
                $attempts = (int) get_post_meta($imageID, '_wpc_regen_retry_attempts', true);
                if ($attempts < 1) {
                    
                    update_post_meta($imageID, '_wpc_regen_retry_attempts', $attempts + 1);
                    error_log('[WPC RegenThumbs] image=' . $imageID . ' duration=' . $regen_duration . 's cap=' . $cap . ' missing=' . implode(',', $missing) . ' retry_queued');
                } else {
                    
                    delete_post_meta($imageID, '_wpc_pending_thumb_regen');
                    delete_post_meta($imageID, '_wpc_regen_retry_attempts');
                    error_log('[WPC RegenThumbs] image=' . $imageID . ' duration=' . $regen_duration . 's cap=' . $cap . ' FAILED after retry, missing=' . implode(',', $missing));
                    
                    
                    set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored', 'time' => time()], 60);
                }
            } else {
                
                delete_post_meta($imageID, '_wpc_pending_thumb_regen');
                delete_post_meta($imageID, '_wpc_regen_retry_attempts');


                $grace_s = defined('WPC_POST_RESTORE_GRACE_SECONDS')
                    ? max(1, (int) WPC_POST_RESTORE_GRACE_SECONDS)
                    : 30;
                set_transient('wpc_post_restore_grace_' . $imageID, time(), $grace_s);
                error_log('[WPC RegenThumbs] image=' . $imageID . ' duration=' . $regen_duration . 's mode=' . ($plan['backup_mode'] ?? 'unknown') . ' cap=' . $cap . ' verified=' . count($newMeta['sizes'] ?? []) . ' grace=' . $grace_s . 's');


                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored', 'time' => time()], 60);
            }
        } finally {
            
            $current = (int) get_transient('wpc_regen_active_count');
            set_transient('wpc_regen_active_count', max(0, $current - 1), 300);
            delete_transient($lock_key);


            if (function_exists('wpc_chain_next_pending_regen')) {
                wpc_chain_next_pending_regen($imageID);
            }
        }
    }
    add_action('wpc_regen_thumbs', 'wpc_regen_thumbs_hook', 10, 1);

    
    function wpc_regen_thumbs_ajax()
    {
        $wpc_rid821 = (int) ($_REQUEST['imageID'] ?? 0);
        if (!function_exists('wpc_loopback_token_ok821')
            || !wpc_loopback_token_ok821('wpc_regen_thumbs', $wpc_rid821, $_REQUEST['t'] ?? '')) {
            if (function_exists('status_header')) { status_header(403); }
            wp_die('', '', ['response' => 403]);
        }
        wpc_regen_thumbs_hook($wpc_rid821);
        wp_die();
    }
    add_action('wp_ajax_wpc_regen_thumbs',        'wpc_regen_thumbs_ajax');
    add_action('wp_ajax_nopriv_wpc_regen_thumbs', 'wpc_regen_thumbs_ajax');
}


if (!function_exists('wpc_chain_next_pending_regen')) {
    function wpc_chain_next_pending_regen($just_finished_id = 0)
    {
        global $wpdb;
        $just_finished_id = (int) $just_finished_id;
        
        $row = $wpdb->get_row($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_wpc_pending_thumb_regen' AND post_id != %d
            ORDER BY meta_id ASC
            LIMIT 1
        ", $just_finished_id));
        if ($row && function_exists('wpc_fire_regen_thumbs_worker')) {
            wpc_fire_regen_thumbs_worker((int) $row->post_id);
        }
    }
}


if (!function_exists('wpc_parent_has_backup')) {
    function wpc_parent_has_backup($imageID)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) return false;

        
        $backup_path = get_post_meta($imageID, 'wpc_backup_path', true);
        if (!empty($backup_path)) return true;

        
        $main = function_exists('get_attached_file') ? get_attached_file($imageID) : '';
        if ($main && defined('WP_CONTENT_DIR')) {
            $upload_dir = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
            if (is_array($upload_dir) && !empty($upload_dir['basedir'])) {
                $rel = ltrim(str_replace(rtrim($upload_dir['basedir'], '/'), '', $main), '/');
                $local_backup = WP_CONTENT_DIR . '/wpc-backups/' . $rel;
                if (file_exists($local_backup)) return true;
            }
        }

        
        if ($main && file_exists($main)) {
            $info = pathinfo($main);
            $bkp = $info['dirname'] . '/' . $info['filename'] . '_bkp.' . ($info['extension'] ?? 'jpg');
            if (file_exists($bkp)) return true;
        }

        return false;
    }
}


if (!function_exists('wpc_lazy_optimize_parent')) {
    function wpc_lazy_optimize_parent($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID || !class_exists('wps_local_compress')) return false;
        $parent_path = function_exists('get_attached_file') ? get_attached_file($imageID) : '';
        if (!$parent_path || !file_exists($parent_path)) return false;


        $backup_compress = new wps_local_compress();
        if (method_exists($backup_compress, 'backup_all_sizes')) {
            $backup_compress->backup_all_sizes($imageID);
        }

        $params = wps_local_compress::buildOptimizeParams($imageID);
        
        $params['crops']          = '{}';
        $params['filenames']      = wp_json_encode(['original' => basename($parent_path)]);
        $params['triggerContext'] = 'lazy_fill_parent';


        $params['avif']  = '1';
        $params['webp']  = '1';
        $params['level'] = 'intelligent';


        $params['compressStartedAt'] = (int) round(microtime(true) * 1000);

        $response = wps_local_compress::postOptimize($imageID, $params, true, 60);
        if (is_wp_error($response)) {
            error_log('[WPC LazyParent] image=' . $imageID . ' failed: ' . $response->get_error_message());
            return false;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (empty($data['success']) || empty($data['optimizedResults'])) return false;

        
        $upload_dir = wp_upload_dir();
        $base_dir = rtrim($upload_dir['basedir'], '/') . '/' . dirname(get_post_meta($imageID, '_wp_attached_file', true));
        $written = 0;
        foreach ($data['optimizedResults'] as $variant) {
            if (empty($variant['fileName']) || empty($variant['bytes'])) continue;
            $bytes = base64_decode($variant['bytes'], true);
            if ($bytes === false) continue;
            
            $fname = basename($variant['fileName']);
            $vfmt = 'jpeg';
            if (strpos($fname, '.avif') !== false) $vfmt = 'avif';
            elseif (strpos($fname, '.webp') !== false) $vfmt = 'webp';
            elseif (strpos($fname, '.png') !== false) $vfmt = 'png';
            if (!wpc_is_valid_image_bytes($bytes, $vfmt, $imageID, 'lazy_parent')) continue;
            $dest = $base_dir . '/' . $fname;
            $tmp = $dest . '.wpc_tmp_' . wp_generate_password(8, false);
            if (file_put_contents($tmp, $bytes) !== false && @rename($tmp, $dest)) {
                @chmod($dest, 0644);
                $written++;
            } else {
                @unlink($tmp);
            }
        }
        error_log('[WPC LazyParent] image=' . $imageID . ' written=' . $written . '/' . count($data['optimizedResults']));
        return $written > 0;
    }
}


if (!function_exists('wpc_lazy_fill_variant')) {
    function wpc_lazy_fill_variant($imageID, $sizeLabel, $variantBytes, $variantFilename)
    {
        $imageID = (int) $imageID;
        if (!$imageID || !class_exists('wps_local_compress')) return false;
        if (empty($sizeLabel) || empty($variantBytes) || empty($variantFilename)) return false;


        $backup_compress = new wps_local_compress();
        if (method_exists($backup_compress, 'backup_all_sizes')) {
            $backup_compress->backup_all_sizes($imageID);
        }

        
        $tmp_dir = function_exists('get_temp_dir') ? rtrim(get_temp_dir(), '/') : sys_get_temp_dir();
        $tmp_path = $tmp_dir . '/wpc_lazy_' . $imageID . '_' . wp_generate_password(8, false) . '_' . $variantFilename;
        if (file_put_contents($tmp_path, $variantBytes) === false) return false;

        $params = wps_local_compress::buildOptimizeParams($imageID);
        $params['crops']             = '{}';
        $params['filenames']         = wp_json_encode([$sizeLabel => $variantFilename]);
        $params['triggerContext']    = 'lazy_fill_variant';

        $params['sizeLabel']         = $sizeLabel;
        $params['skipBackup']        = '1'; 


        $params['parentImageID']     = (string) $imageID;
        $params['file_path_override'] = $tmp_path;


        $params['avif']  = '1';
        $params['webp']  = '1';
        $params['level'] = 'intelligent';
        
        


        $params['compressStartedAt'] = (int) round(microtime(true) * 1000);

        $response = wps_local_compress::postOptimize($imageID, $params, true, 30);
        @unlink($tmp_path);

        if (is_wp_error($response)) {
            error_log('[WPC LazyVariant] image=' . $imageID . ' size=' . $sizeLabel . ' failed: ' . $response->get_error_message());
            return false;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (empty($data['success']) || empty($data['optimizedResults'])) return false;


        $upload_dir = wp_upload_dir();
        $base_dir = rtrim($upload_dir['basedir'], '/') . '/' . dirname(get_post_meta($imageID, '_wp_attached_file', true));
        $written = 0;
        $persist_entries = [];
        $upload_url = $upload_dir['baseurl'] . '/' . dirname(get_post_meta($imageID, '_wp_attached_file', true));
        foreach ($data['optimizedResults'] as $variant) {
            if (empty($variant['fileName']) || empty($variant['bytes'])) continue;
            $bytes = base64_decode($variant['bytes'], true);
            if ($bytes === false) continue;
            
            $fname = basename($variant['fileName']);
            $vfmt = 'jpeg';
            if (strpos($fname, '.avif') !== false) $vfmt = 'avif';
            elseif (strpos($fname, '.webp') !== false) $vfmt = 'webp';
            elseif (strpos($fname, '.png') !== false) $vfmt = 'png';
            if (!wpc_is_valid_image_bytes($bytes, $vfmt, $imageID, 'lazy_variant')) continue;
            $dest = $base_dir . '/' . $fname;
            $tmp = $dest . '.wpc_tmp_' . wp_generate_password(8, false);
            if (file_put_contents($tmp, $bytes) !== false && @rename($tmp, $dest)) {
                @chmod($dest, 0644);
                $written++;
                $persist_entries[] = [
                    'size_label' => $sizeLabel,
                    'format'     => $vfmt,
                    'size'       => strlen($bytes),
                    'url'        => $upload_url . '/' . $fname,
                ];
            } else {
                @unlink($tmp);
            }
        }
        if (!empty($persist_entries) && function_exists('wpc_persist_inline_variants')) {
            $persisted = wpc_persist_inline_variants($imageID, $persist_entries, 'lazy_fill_inline');
        } else {
            $persisted = 0;
        }
        error_log('[WPC LazyVariant] image=' . $imageID . ' size=' . $sizeLabel
            . ' written=' . $written . '/' . count($data['optimizedResults'])
            . ' persisted=' . $persisted);
        return $written > 0;
    }
}


if (!function_exists('wpc_run_lazy_variant_ladder')) {
    function wpc_run_lazy_variant_ladder($attachmentId, $widths, $trigger_source, $t_start, $meta)
    {


        if (function_exists('wpc_get_optimization_mode')) {
            $opt_mode = wpc_get_optimization_mode();
            if ($opt_mode === 'manual') {
                error_log('[WPC LazyLadder] image=' . $attachmentId . ' skipped (manual mode — no auto-encoding)');
                return false;
            }
            if (strpos($opt_mode, 'lazy_') === 0
                && function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol()
                && function_exists('wpc_lazy_trigger_v2')) {
                error_log('[WPC LazyLadder] image=' . $attachmentId . ' routed to v2 path (mode=' . $opt_mode . ')');
                return wpc_lazy_trigger_v2($attachmentId);
            }
        }

        $lock_key = 'wpc_lazy_lock_' . $attachmentId;
        if (get_transient($lock_key)) return false;
        set_transient($lock_key, 1, 300);

        try {


            $parent_path = get_attached_file($attachmentId);
            if (!$parent_path || !file_exists($parent_path)) return false;

            if (function_exists('wp_get_original_image_path')) {
                $unscaled = wp_get_original_image_path($attachmentId);
                if ($unscaled
                    && $unscaled !== $parent_path
                    && file_exists($unscaled)
                    && is_readable($unscaled)) {
                    
                    $att_info  = @getimagesize($parent_path);
                    $orig_info = @getimagesize($unscaled);
                    $att_w  = is_array($att_info)  ? (int) ($att_info[0]  ?? 0) : 0;
                    $orig_w = is_array($orig_info) ? (int) ($orig_info[0] ?? 0) : 0;
                    if ($orig_w > $att_w && $orig_w > 0) {
                        $parent_path = $unscaled;
                    }
                }
            }

            
            
            $variants = get_post_meta($attachmentId, 'ic_local_variants', true);
            $parent_has_avif = is_array($variants) && (isset($variants['original-avif']) || isset($variants['scaled-avif']));
            if (!$parent_has_avif) {
                $ok = wpc_lazy_optimize_parent($attachmentId);
                if (!$ok) {
                    error_log('[WPC LazyLadder] image=' . $attachmentId . ' parent optimize failed — abort');
                    return false;
                }
            }

            
            $delivered = 0;
            $source_width = WPC_Modern_Delivery::get_source_width($attachmentId, $meta);
            foreach ($widths as $w) {
                $w = (int) $w;
                if ($w <= 0) continue;
                $resolved = WPC_Modern_Delivery::resolve_variant_filename($meta, $w, 'jpg', $source_width);
                if ($resolved === null) continue; 
                $size_label = $resolved['size_label'];
                $filename   = $resolved['filename'];

                
                $bytes = wpc_lazy_resize_to_bytes($parent_path, $w);
                if (!$bytes) {
                    error_log('[WPC LazyLadder] image=' . $attachmentId . ' resize failed for width=' . $w);
                    continue;
                }

                $ok = wpc_lazy_fill_variant($attachmentId, $size_label, $bytes, $filename);
                if ($ok) $delivered++;
            }

            $duration_ms = (int) round((microtime(true) - $t_start) * 1000);
            error_log('[WPC LazyLadder] image=' . $attachmentId . ' widths=' . count($widths) . ' delivered=' . $delivered . ' duration=' . $duration_ms . 'ms trigger=' . $trigger_source);

            if (function_exists('wpc_update_ladder_stats')) {
                wpc_update_ladder_stats([
                    'event'          => $delivered > 0 ? 'success' : 'failed',
                    'duration_ms'    => $duration_ms,
                    'trigger_source' => $trigger_source,
                    'mode'           => 'lazy',
                ]);
            }

            return $delivered > 0;
        } finally {
            delete_transient($lock_key);
        }
    }
}


if (!function_exists('wpc_lazy_resize_to_bytes')) {
    function wpc_lazy_resize_to_bytes($parentPath, $targetWidth, $targetHeight = 0)
    {
        if (!$parentPath || !file_exists($parentPath)) return false;
        if (!function_exists('wp_get_image_editor')) return false;
        $editor = wp_get_image_editor($parentPath);
        if (is_wp_error($editor)) return false;
        $size = $editor->get_size();
        if (!is_array($size) || empty($size['width'])) return false;
        
        if ($targetWidth >= (int) $size['width']) {
            return file_get_contents($parentPath);
        }
        $resize = $editor->resize($targetWidth, $targetHeight ?: null, false);
        if (is_wp_error($resize)) return false;
        
        $tmp_dir = function_exists('get_temp_dir') ? rtrim(get_temp_dir(), '/') : sys_get_temp_dir();
        $tmp = $tmp_dir . '/wpc_lazy_resize_' . wp_generate_password(8, false) . '.jpg';
        $saved = $editor->save($tmp, 'image/jpeg');
        if (is_wp_error($saved) || empty($saved['path']) || !file_exists($saved['path'])) return false;
        $bytes = file_get_contents($saved['path']);
        @unlink($saved['path']);
        return $bytes;
    }
}


if (!function_exists('wpc_resolve_size_label_width')) {
    function wpc_resolve_size_label_width($size_label, $meta, $imageID = 0)
    {
        if (empty($size_label) || !is_array($meta)) return 0;

        
        if ($size_label === 'scaled') {
            return (int) ($meta['width'] ?? 0);
        }

        
        if ($size_label === 'original') {
            if ($imageID && function_exists('wp_get_original_image_path')) {
                $unscaled = wp_get_original_image_path($imageID);
                if ($unscaled && file_exists($unscaled)) {
                    $info = @getimagesize($unscaled);
                    if (is_array($info) && !empty($info[0])) return (int) $info[0];
                }
            }
            return (int) ($meta['width'] ?? 0);
        }

        
        if (preg_match('/^wpc_(\d+)$/', $size_label, $m)) {
            return (int) $m[1];
        }

        
        if (preg_match('/^(\d+)w$/', $size_label, $m)) {
            return (int) $m[1];
        }

        
        if (preg_match('/^(\d+)x(\d+)$/', $size_label, $m)) {
            
            if (!empty($meta['sizes'][$size_label]['width'])) {
                return (int) $meta['sizes'][$size_label]['width'];
            }
            return (int) $m[1];
        }

        
        if (!empty($meta['sizes'][$size_label]['width'])) {
            return (int) $meta['sizes'][$size_label]['width'];
        }


        if ($size_label === 'thumb') {
            return (int) ($meta['sizes']['thumbnail']['width'] ?? 150);
        }

        return 0;
    }
}


if (!function_exists('wpc_lazy_fill_variant_avif_only')) {
    function wpc_lazy_fill_variant_avif_only($imageID, $sizeLabel, $variantBytes, $variantFilename)
    {
        if (!function_exists('wpc_lazy_fill_variant')) return false;
        return wpc_lazy_fill_variant($imageID, $sizeLabel, $variantBytes, $variantFilename);
    }
}


if (!function_exists('wpc_backfill_missing_avif')) {
    function wpc_backfill_missing_avif($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID) return ['queued' => 0, 'reason' => 'invalid-id'];
        if (!function_exists('wpc_generate_ladder_widths')) return ['queued' => 0, 'reason' => 'no-generator'];

        $variants = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($variants) || empty($variants)) return ['queued' => 0, 'reason' => 'no-variants'];

        $meta = wp_get_attachment_metadata($imageID);
        if (!is_array($meta)) return ['queued' => 0, 'reason' => 'no-meta'];


        $coverage = [];
        foreach ($variants as $key => $_v) {
            if (preg_match('/^(.+)-(avif|webp|jpe?g)$/i', $key, $m)) {
                $base = $m[1];
                $fmt  = strtolower($m[2]);
                if ($fmt === 'jpg') $fmt = 'jpeg';
            } else {
                $base = $key;
                $fmt  = 'jpeg';
            }
            if (!isset($coverage[$base])) {
                $coverage[$base] = ['avif' => false, 'webp' => false, 'jpeg' => false];
            }
            $coverage[$base][$fmt] = true;
        }

        
        
        $needs_avif = [];
        foreach ($coverage as $base => $c) {
            if (!$c['avif'] && ($c['webp'] || $c['jpeg'])) {
                $needs_avif[] = $base;
            }
        }
        if (empty($needs_avif)) return ['queued' => 0, 'reason' => 'all-covered'];


        $parent_w = (int) (@getimagesize(get_attached_file($imageID))[0] ?? 0);
        if (function_exists('wp_get_original_image_path')) {
            $unscaled = wp_get_original_image_path($imageID);
            if ($unscaled && file_exists($unscaled) && is_readable($unscaled)) {
                $w = (int) (@getimagesize($unscaled)[0] ?? 0);
                if ($w > $parent_w) $parent_w = $w;
            }
        }
        $relative = (string) get_post_meta($imageID, '_wp_attached_file', true);
        if ($relative !== '' && defined('WP_CONTENT_DIR')) {
            $rel_dir   = dirname($relative);
            $rel_base  = pathinfo($relative, PATHINFO_FILENAME);
            $rel_ext   = pathinfo($relative, PATHINFO_EXTENSION) ?: 'jpg';
            $rel_strip = preg_replace('/-scaled$/', '', $rel_base);
            $candidate = WP_CONTENT_DIR . '/wpc-backups/' . trim($rel_dir, '/') . '/' . $rel_strip . '.' . $rel_ext;
            if (file_exists($candidate) && is_readable($candidate)) {
                $bw = (int) (@getimagesize($candidate)[0] ?? 0);
                if ($bw > $parent_w) $parent_w = $bw;
            }
        }
        $source_width = $parent_w > 0 ? $parent_w : (int) ($meta['width'] ?? 0);


        $widths = [];
        $skipped = [];
        foreach ($needs_avif as $base) {
            $w = wpc_resolve_size_label_width($base, $meta, $imageID);
            if (!$w) {
                $skipped[] = $base . ':no-width';
                continue;
            }
            if ($source_width > 0 && $w > $source_width) {
                $skipped[] = $base . ':exceeds-source(' . $w . '>' . $source_width . ')';
                continue;
            }
            $widths[] = $w;
        }
        $widths = array_values(array_unique(array_map('intval', $widths)));
        if (empty($widths)) {
            return [
                'queued'  => 0,
                'reason'  => 'all-skipped',
                'targets' => $needs_avif,
                'skipped' => $skipped,
            ];
        }


        if (!defined('WPC_DISABLE_LAZY_VARIANT')) {
            define('WPC_DISABLE_LAZY_VARIANT', true);
        }

        $ok = wpc_generate_ladder_widths($imageID, $widths, 'avif_backfill_batch', microtime(true));

        error_log(sprintf(
            '[WPC AvifBackfill] image=%d batch widths=[%s] source_w=%d skipped=%s ok=%s',
            $imageID, implode(',', $widths), $source_width,
            empty($skipped) ? '-' : implode(',', $skipped),
            $ok ? 'true' : 'false'
        ));

        return [
            'queued'   => $ok ? count($widths) : 0,
            'reason'   => $ok ? 'submitted' : 'batch-fail',
            'source_w' => $source_width,
            'widths'   => $widths,
            'targets'  => $needs_avif,
            'skipped'  => $skipped,
        ];
    }
}


if (!function_exists('wpc_fire_regen_thumbs_worker')) {
    function wpc_fire_regen_thumbs_worker($imageID)
    {
        if (function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth()) return;


        $rt_parts = wp_parse_url(admin_url('admin-ajax.php'));
        if (!empty($rt_parts['host'])) {
            $rt_https = (!empty($rt_parts['scheme']) && $rt_parts['scheme'] === 'https');
            $rt_port  = !empty($rt_parts['port']) ? (int) $rt_parts['port'] : ($rt_https ? 443 : 80);
            $rt_host  = (string) $rt_parts['host'];
            $rt_path  = (!empty($rt_parts['path']) ? $rt_parts['path'] : '/') . '?action=wpc_regen_thumbs';
            $rt_body  = http_build_query(['imageID' => (int) $imageID, 't' => wpc_loopback_token821('wpc_regen_thumbs', $imageID)]);
            $rt_req   = "POST {$rt_path} HTTP/1.1\r\nHost: {$rt_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($rt_body) . "\r\nConnection: close\r\nUser-Agent: WPCRegenThumbs/1.0\r\n\r\n" . $rt_body;
            $rt_fp = wps_ic_ajax::wpc_loopback_open_socket($rt_host, $rt_port, $rt_https, 0.2);
            if ($rt_fp) { @stream_set_timeout($rt_fp, 0, 100000); @fwrite($rt_fp, $rt_req); @fclose($rt_fp); }
        }
    }
}



if (!function_exists('wpc_retry_compress_hook')) {
    function wpc_retry_compress_hook($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID || get_post_type($imageID) !== 'attachment') return;
        if (!class_exists('wps_local_compress')) return;


        $lock_key = 'wpc_retry_lock_' . $imageID;
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 60);
        try {
            $compress = new wps_local_compress();
            $compress->backup_all_sizes($imageID);
            $compress->singleCompressV4($imageID, 'silent', true, 'retry');
            if (get_post_meta($imageID, 'ic_status', true) === 'compressed') {
                delete_post_meta($imageID, '_wpc_service_retry_attempts');
                delete_post_meta($imageID, '_wpc_last_post_fail');
                delete_transient('wps_ic_compress_' . $imageID);
                error_log('[WPC Retry] image=' . $imageID . ' succeeded on retry');
            }
        } finally {
            delete_transient($lock_key);
        }
    }
    add_action('wpc_retry_compress', 'wpc_retry_compress_hook', 10, 1);
}


if (!function_exists('wpc_fire_retry_compress_worker')) {
    function wpc_fire_retry_compress_worker($imageID)
    {
        if (function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth()) return;


        $rc_parts = wp_parse_url(admin_url('admin-ajax.php'));
        if (!empty($rc_parts['host'])) {
            $rc_https = (!empty($rc_parts['scheme']) && $rc_parts['scheme'] === 'https');
            $rc_port  = !empty($rc_parts['port']) ? (int) $rc_parts['port'] : ($rc_https ? 443 : 80);
            $rc_host  = (string) $rc_parts['host'];
            $rc_path  = (!empty($rc_parts['path']) ? $rc_parts['path'] : '/') . '?action=wpc_retry_compress_loopback&t=' . rawurlencode(wpc_loopback_token_mint('retry'));
            $rc_body  = http_build_query(['imageID' => (int) $imageID]);
            $rc_req   = "POST {$rc_path} HTTP/1.1\r\nHost: {$rc_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($rc_body) . "\r\nConnection: close\r\nUser-Agent: WPCRetryCompress/1.0\r\n\r\n" . $rc_body;
            $rc_fp = wps_ic_ajax::wpc_loopback_open_socket($rc_host, $rc_port, $rc_https, 0.2);
            if ($rc_fp) { @stream_set_timeout($rc_fp, 0, 100000); @fwrite($rc_fp, $rc_req); @fclose($rc_fp); }
        }
    }
}


if (!function_exists('wpc_retry_compress_loopback_ajax')) {
    function wpc_retry_compress_loopback_ajax()
    {
        
        
        if (!function_exists('wpc_loopback_token_ok') || !wpc_loopback_token_ok('retry')) {
            wp_die('', '', ['response' => 403]);
        }
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            wp_die('', '', ['response' => 200]);
        }
        @set_time_limit(120);
        $imageID = (int) ($_REQUEST['imageID'] ?? 0);
        if ($imageID && function_exists('wpc_retry_compress_hook')) {
            wpc_retry_compress_hook($imageID);
        }
        wp_die('', '', ['response' => 200]);
    }
    add_action('wp_ajax_wpc_retry_compress_loopback', 'wpc_retry_compress_loopback_ajax');
    add_action('wp_ajax_nopriv_wpc_retry_compress_loopback', 'wpc_retry_compress_loopback_ajax');
}







if (!function_exists('wpc_log_variant_gen')) {
    function wpc_log_variant_gen($attachment_id, $widths_or_width, $formats_or_format, $extras = [])
    {
        $log = get_option('wpc_variant_gen_log', []);
        if (!is_array($log)) $log = [];

        $entry = [
            't'   => time(),
            'aid' => (int) $attachment_id,
            'w'   => is_array($widths_or_width) ? array_map('intval', $widths_or_width) : (int) $widths_or_width,
            'f'   => is_array($formats_or_format) ? $formats_or_format : (string) $formats_or_format,
        ];

        
        if (is_array($extras)) {
            foreach ($extras as $k => $v) {
                if (!isset($entry[$k])) $entry[$k] = $v;
            }
        } elseif (is_string($extras)) {
            
            $entry['ctx'] = $extras;
        }

        $log[] = $entry;
        $log = array_slice($log, -500);
        update_option('wpc_variant_gen_log', $log, false);
    }
}


if (!function_exists('wpc_update_ladder_stats')) {
    function wpc_update_ladder_stats($event_data)
    {
        $stats = get_option('wpc_ladder_stats', []);
        if (!is_array($stats)) $stats = [];

        
        $defaults = [
            'fleet' => [
                'total_backfills_fired'      => 0,
                'total_backfills_succeeded'  => 0,
                'total_backfills_failed'     => 0,
                'total_variants_avif'        => 0,
                'total_variants_webp'        => 0,
                'total_variants_jpg'         => 0,
                'last_backfill_at'           => 0,
            ],
            'timing' => [
                'samples'          => 0,
                'sum_ms'           => 0,
                'max_ms'           => 0,
                
                'recent_ms'        => [],
            ],
            'queue' => [
                'max_depth_ever' => 0,
                'max_depth_at'   => 0,
            ],
            'triggers' => [
                'loopback'  => 0,
                'shutdown'  => 0,
                'admin'     => 0,
                'cron'      => 0,
                'manual'    => 0,
                'prewarm'   => 0,
                'cli-force' => 0,
                'unknown'   => 0,
            ],
        ];
        
        foreach ($defaults as $section => $fields) {
            if (!isset($stats[$section]) || !is_array($stats[$section])) {
                $stats[$section] = $fields;
            } else {
                foreach ($fields as $k => $v) {
                    if (!isset($stats[$section][$k])) $stats[$section][$k] = $v;
                }
            }
        }

        $event = isset($event_data['event']) ? $event_data['event'] : 'unknown';
        $duration_ms = isset($event_data['duration_ms']) ? (int) $event_data['duration_ms'] : 0;
        $trigger     = isset($event_data['trigger_source']) ? (string) $event_data['trigger_source'] : 'unknown';
        $formats     = isset($event_data['formats_delivered']) && is_array($event_data['formats_delivered']) ? $event_data['formats_delivered'] : [];

        $stats['fleet']['total_backfills_fired']++;
        if ($event === 'success') {
            $stats['fleet']['total_backfills_succeeded']++;
        } else {
            $stats['fleet']['total_backfills_failed']++;
        }
        $stats['fleet']['last_backfill_at'] = time();

        if (isset($formats['avif'])) $stats['fleet']['total_variants_avif'] += (int) $formats['avif'];
        if (isset($formats['webp'])) $stats['fleet']['total_variants_webp'] += (int) $formats['webp'];
        if (isset($formats['jpg']))  $stats['fleet']['total_variants_jpg']  += (int) $formats['jpg'];

        
        if ($duration_ms > 0) {
            $stats['timing']['samples']++;
            $stats['timing']['sum_ms'] += $duration_ms;
            if ($duration_ms > $stats['timing']['max_ms']) $stats['timing']['max_ms'] = $duration_ms;
            $stats['timing']['recent_ms'][] = $duration_ms;
            if (count($stats['timing']['recent_ms']) > 20) {
                $stats['timing']['recent_ms'] = array_slice($stats['timing']['recent_ms'], -20);
            }
        }

        
        $trigger_key = isset($stats['triggers'][$trigger]) ? $trigger : 'unknown';
        $stats['triggers'][$trigger_key]++;

        update_option('wpc_ladder_stats', $stats, false);
    }
}




if (!function_exists('wpc_record_queue_depth')) {
    function wpc_record_queue_depth($depth)
    {
        $depth = (int) $depth;
        if ($depth <= 0) return;
        $stats = get_option('wpc_ladder_stats', []);
        if (!is_array($stats)) $stats = [];
        if (!isset($stats['queue']) || !is_array($stats['queue'])) {
            $stats['queue'] = ['max_depth_ever' => 0, 'max_depth_at' => 0];
        }
        if ($depth > (int) $stats['queue']['max_depth_ever']) {
            $stats['queue']['max_depth_ever'] = $depth;
            $stats['queue']['max_depth_at'] = time();
            update_option('wpc_ladder_stats', $stats, false);
        }
    }
}





if (!function_exists('wpc_ladder_stats_p95')) {
    function wpc_ladder_stats_p95($stats = null)
    {
        if ($stats === null) $stats = get_option('wpc_ladder_stats', []);
        if (empty($stats['timing']['recent_ms']) || !is_array($stats['timing']['recent_ms'])) return 0;
        $samples = $stats['timing']['recent_ms'];
        sort($samples);
        $idx = (int) floor(count($samples) * 0.95) - 1;
        if ($idx < 0) $idx = count($samples) - 1;
        return (int) $samples[$idx];
    }
}





if (!function_exists('wpc_update_restore_stats')) {
    function wpc_update_restore_stats($event_data)
    {
        $stats = get_option('wpc_restore_stats', []);
        if (!is_array($stats)) $stats = [];

        $defaults = [
            'fleet' => [
                'total_restores_fired'     => 0,
                'total_restores_succeeded' => 0,
                'total_restores_failed'    => 0,
                'last_restore_at'          => 0,
            ],
            'timing' => [
                'samples'   => 0,
                'sum_ms'    => 0,
                'max_ms'    => 0,
                'recent_ms' => [],
            ],
            'sources' => [
                'local_bkp' => 0,
                'cloud_bkp' => 0,
                'service'   => 0,
                'unknown'   => 0,
            ],
        ];
        foreach ($defaults as $section => $fields) {
            if (!isset($stats[$section]) || !is_array($stats[$section])) {
                $stats[$section] = $fields;
            } else {
                foreach ($fields as $k => $v) {
                    if (!isset($stats[$section][$k])) $stats[$section][$k] = $v;
                }
            }
        }

        $event       = isset($event_data['event']) ? (string) $event_data['event'] : 'unknown';
        $duration_ms = isset($event_data['duration_ms']) ? (int) $event_data['duration_ms'] : 0;
        $source      = isset($event_data['source']) ? (string) $event_data['source'] : 'unknown';

        $stats['fleet']['total_restores_fired']++;
        if ($event === 'success') {
            $stats['fleet']['total_restores_succeeded']++;
        } else {
            $stats['fleet']['total_restores_failed']++;
        }
        $stats['fleet']['last_restore_at'] = time();

        if ($duration_ms > 0) {
            $stats['timing']['samples']++;
            $stats['timing']['sum_ms'] += $duration_ms;
            if ($duration_ms > $stats['timing']['max_ms']) $stats['timing']['max_ms'] = $duration_ms;
            $stats['timing']['recent_ms'][] = $duration_ms;
            if (count($stats['timing']['recent_ms']) > 20) {
                $stats['timing']['recent_ms'] = array_slice($stats['timing']['recent_ms'], -20);
            }
        }

        $source_key = isset($stats['sources'][$source]) ? $source : 'unknown';
        $stats['sources'][$source_key]++;

        update_option('wpc_restore_stats', $stats, false);
    }
}

if (!function_exists('wpc_restore_stats_p95')) {
    function wpc_restore_stats_p95($stats = null)
    {
        if ($stats === null) $stats = get_option('wpc_restore_stats', []);
        if (empty($stats['timing']['recent_ms']) || !is_array($stats['timing']['recent_ms'])) return 0;
        $samples = $stats['timing']['recent_ms'];
        sort($samples);
        $idx = (int) floor(count($samples) * 0.95) - 1;
        if ($idx < 0) $idx = count($samples) - 1;
        return (int) $samples[$idx];
    }
}





if (!function_exists('wpc_update_compress_stats')) {
    function wpc_update_compress_stats($event_data)
    {
        $stats = get_option('wpc_compress_stats', []);
        if (!is_array($stats)) $stats = [];

        $defaults = [
            'fleet' => [
                'total_compresses_fired'     => 0,
                'total_compresses_succeeded' => 0,
                'total_compresses_failed'    => 0,
                'last_compress_at'           => 0,
            ],
            'timing' => [
                'samples'   => 0,
                'sum_ms'    => 0,
                'max_ms'    => 0,
                'recent_ms' => [],
            ],
            'sources' => [
                'upload'  => 0,
                'single'  => 0,
                'bulk'    => 0,
                'retry'   => 0,
                'unknown' => 0,
            ],
        ];
        foreach ($defaults as $section => $fields) {
            if (!isset($stats[$section]) || !is_array($stats[$section])) {
                $stats[$section] = $fields;
            } else {
                foreach ($fields as $k => $v) {
                    if (!isset($stats[$section][$k])) $stats[$section][$k] = $v;
                }
            }
        }

        $event       = isset($event_data['event']) ? (string) $event_data['event'] : 'unknown';
        $duration_ms = isset($event_data['duration_ms']) ? (int) $event_data['duration_ms'] : 0;
        $source      = isset($event_data['source']) ? (string) $event_data['source'] : 'unknown';

        $stats['fleet']['total_compresses_fired']++;
        if ($event === 'success') {
            $stats['fleet']['total_compresses_succeeded']++;
        } else {
            $stats['fleet']['total_compresses_failed']++;
        }
        $stats['fleet']['last_compress_at'] = time();

        if ($duration_ms > 0) {
            $stats['timing']['samples']++;
            $stats['timing']['sum_ms'] += $duration_ms;
            if ($duration_ms > $stats['timing']['max_ms']) $stats['timing']['max_ms'] = $duration_ms;
            $stats['timing']['recent_ms'][] = $duration_ms;
            if (count($stats['timing']['recent_ms']) > 20) {
                $stats['timing']['recent_ms'] = array_slice($stats['timing']['recent_ms'], -20);
            }
        }

        $source_key = isset($stats['sources'][$source]) ? $source : 'unknown';
        $stats['sources'][$source_key]++;

        update_option('wpc_compress_stats', $stats, false);
    }
}

if (!function_exists('wpc_compress_stats_p95')) {
    function wpc_compress_stats_p95($stats = null)
    {
        if ($stats === null) $stats = get_option('wpc_compress_stats', []);
        if (empty($stats['timing']['recent_ms']) || !is_array($stats['timing']['recent_ms'])) return 0;
        $samples = $stats['timing']['recent_ms'];
        sort($samples);
        $idx = (int) floor(count($samples) * 0.95) - 1;
        if ($idx < 0) $idx = count($samples) - 1;
        return (int) $samples[$idx];
    }
}





if (!function_exists('wpc_log_variant_emitted')) {
    function wpc_log_variant_emitted($attachment_id, $widths)
    {
        
        $rate_key = 'wpc_emit_ratelimit_' . (int) $attachment_id;
        if (get_transient($rate_key)) return;
        set_transient($rate_key, 1, 300); 

        $counts = get_option('wpc_variant_emit_counts', []);
        if (!is_array($counts)) $counts = [];
        foreach ((array) $widths as $w) {
            $key = (int) $attachment_id . ':' . (int) $w;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        
        if (count($counts) > 10000) {
            $counts = array_slice($counts, -10000, null, true);
        }
        update_option('wpc_variant_emit_counts', $counts, false);
    }
}






if (!function_exists('wpc_modern_delivery_prewarm')) {
    function wpc_modern_delivery_prewarm()
    {
        @set_time_limit(120);
        update_option('wpc_prewarm_status', ['state' => 'running', 'started_at' => time(), 'prewarmed' => 0], false);

        
        
        if (function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth()) {
            update_option('wpc_prewarm_status', ['state' => 'skipped_basic_auth', 'started_at' => time(), 'prewarmed' => 0], false);
            return 0;
        }

        
        $urls = [home_url('/')];
        $urls = array_merge($urls, wpc_get_prewarm_candidate_urls(5));
        $urls = array_unique($urls);

        $seen_attachments = [];
        $prewarmed = 0;
        $failed_pages = 0;
        $start_time = time();

        foreach ($urls as $url) {
            if ($prewarmed >= 20) break;
            if (time() - $start_time > 90) break;
            if ($failed_pages >= 3) break;

            
            $response = wp_remote_get($url, [
                'timeout'   => 10,
                'sslverify' => false,
                'headers'   => ['User-Agent' => 'WP Compress Pre-Warm'],
            ]);
            if (is_wp_error($response)) {
                $failed_pages++;
                continue;
            }
            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                $failed_pages++;
                continue;
            }
            $html = wp_remote_retrieve_body($response);
            if (empty($html)) {
                $failed_pages++;
                continue;
            }

            
            if (!preg_match_all('#<img([^>]+)/?>#i', $html, $matches)) continue;

            foreach ($matches[1] as $attrs_str) {
                if ($prewarmed >= 20) break;

                
                $src = '';
                $class = '';
                $width = 0;
                if (preg_match('#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $attrs_str, $m)) $src = $m[1];
                if (preg_match('#\bclass\s*=\s*["\']([^"\']+)["\']#i', $attrs_str, $m)) $class = $m[1];
                if (preg_match('#\bwidth\s*=\s*["\']?(\d+)#i', $attrs_str, $m)) $width = (int) $m[1];

                
                if ($width > 0 && $width < 400) continue;
                if (empty($src)) continue;

                
                $aid = 0;
                if (preg_match('/\bwp-image-(\d+)\b/', $class, $m)) {
                    $aid = (int) $m[1];
                } else {
                    $aid = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_att_id'))
                        ? (int) wps_rewriteLogic::wpc_att_id($src)
                        : (int) attachment_url_to_postid($src);
                }
                if ($aid <= 0 || isset($seen_attachments[$aid])) continue;
                $seen_attachments[$aid] = true;

                $meta = wp_get_attachment_metadata($aid);
                if (empty($meta) || empty($meta['file'])) continue;
                if ((int) ($meta['width'] ?? 0) < 400) continue;

                
                $missing_avif = class_exists('WPC_Modern_Delivery')
                    ? WPC_Modern_Delivery::find_missing_ladder_widths($aid, $meta, 'avif')
                    : [];
                $missing_webp = class_exists('WPC_Modern_Delivery')
                    ? WPC_Modern_Delivery::find_missing_ladder_widths($aid, $meta, 'webp')
                    : [];
                $missing = array_unique(array_merge($missing_avif, $missing_webp));
                if (empty($missing)) continue;

                
                if (wpc_generate_ladder_widths($aid, $missing, 'prewarm')) {
                    $prewarmed++;
                }
            }
        }

        update_option('wpc_prewarm_completed_at', time(), false);
        update_option('wpc_prewarm_count', $prewarmed, false);
        update_option('wpc_prewarm_status', [
            'state'        => 'done',
            'started_at'   => $start_time,
            'completed_at' => time(),
            'prewarmed'    => $prewarmed,
            'failed_pages' => $failed_pages,
        ], false);

        return $prewarmed;
    }
}




if (!function_exists('wpc_get_prewarm_candidate_urls')) {
    function wpc_get_prewarm_candidate_urls($limit = 5)
    {
        $urls = [];

        
        $posts = get_posts([
            'numberposts' => $limit,
            'post_status' => 'publish',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
        foreach ($posts as $p) {
            $urls[] = get_permalink($p->ID);
        }

        
        if (function_exists('wc_get_page_id')) {
            $shop_id = wc_get_page_id('shop');
            if ($shop_id > 0) $urls[] = get_permalink($shop_id);
        }

        return array_slice(array_unique(array_filter($urls)), 0, $limit);
    }
}


class wps_local_compress
{

    private static $allowed_types;
    private static $apiURL;
    private static $siteUrl;
    private static $apiParams;
    private static $settings;
    private static $options;
    private static $zone_name;
    private static $backup_directory;
    public $webp_sizes;
    public $sizes;
    public $total_sizes;
    public $compressed_list;

    public $enabledLog;
    public $logFile;
    public $logFilePath;
    public $pathToDir;


    public function __construct()
    {
        global $wps_ic;
        global $wpc_filesystem;

        $this->enabledLog = 'true';

        $this->logFilePath = WPS_IC_LOG . 'compress-log.txt';
        $this->logFile = @fopen($this->logFilePath, 'a');

        $this->get_filesystem();

        $this->total_sizes = count(get_intermediate_image_sizes());
        $this->sizes = $this->getAllThumbSizes();
        $this->webp_sizes = get_intermediate_image_sizes();
        $uploads_dir = wp_upload_dir();

        self::$allowed_types = ['jpg' => 'jpg', 'jpeg' => 'jpeg', 'gif' => 'gif', 'png' => 'png'];
        self::$backup_directory = $uploads_dir['basedir'] . '/wp-compress-backups';
        self::$settings = get_option(WPS_IC_SETTINGS);
        self::$options = get_option(WPS_IC_OPTIONS);
        self::$siteUrl = site_url();

        


        if (!file_exists(self::$backup_directory)) {
            $made_dir = mkdir(self::$backup_directory, 0755);
            if (!$made_dir) {
                update_option('wpc_errors', ['unable-to-create-backup-dir' => self::$backup_directory]);
            } else {
                delete_option('wpc_errors');
            }
        }


        add_action('delete_attachment', [$this, 'on_delete']);


        $on_upload_mode = function_exists('wpc_get_optimization_mode')
            ? wpc_get_optimization_mode()
            : 'legacy';
        $on_upload_enabled = ($on_upload_mode === 'legacy')
            && empty($_GET['restoreImage'])
            && !(function_exists('wpc_auto_encoding_disabled') && wpc_auto_encoding_disabled());

        if ($on_upload_enabled) {


            add_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX, 2);
            
        }


        add_filter('wp_update_attachment_metadata', [$this, 'wpc_reoptimize_edited_image'], 99, 2);
        add_action('wpc_reoptimize_edited_image_event', [$this, 'wpc_run_edited_reoptimize'], 10, 1);

        if (empty(self::$settings['cname']) || !self::$settings['cname']) {
            self::$zone_name = get_option('ic_cdn_zone_name');
        } else {
            self::$zone_name = get_option('ic_custom_cname');
        }

        $location = get_option('wps_ic_geo_locate_v2');
        if (empty($location)) {
            $location = $this->geoLocate();
        }

        if (is_object($location)) {
            $location = (array)$location;
        }

        $apiVersion = 'v4';

        if (isset($location) && !empty($location)) {
            if (is_array($location) && !empty($location['server'])) {

                if (empty($location['continent'])) {
                    $location['continent'] = '';
                }

                if ($location['continent'] == 'CUSTOM') {
                    self::$apiURL = 'https://' . $location['custom_server'] . '.zapwp.net/local/' . $apiVersion . '/';
                } elseif ($location['continent'] == 'AS' || $location['continent'] == 'IN') {
                    self::$apiURL = 'https://singapore.zapwp.net/local/' . $apiVersion . '/';
                } elseif ($location['continent'] == 'EU') {
                    self::$apiURL = 'https://germany.zapwp.net/local/' . $apiVersion . '/';
                } elseif ($location['continent'] == 'OC') {
                    self::$apiURL = 'https://sydney.zapwp.net/local/' . $apiVersion . '/';
                } elseif ($location['continent'] == 'US' || $location['continent'] == 'NA' || $location['continent'] == 'SA') {
                    self::$apiURL = 'https://nyc.zapwp.net/local/' . $apiVersion . '/';
                } else {
                    self::$apiURL = 'https://germany.zapwp.net/local/' . $apiVersion . '/';
                }
            } else {
                self::$apiURL = 'https://' . $location->server . '/local/' . $apiVersion . '/';
            }
        } else {
            self::$apiURL = 'https://germany.zapwp.net/local/' . $apiVersion . '/';
        }

        $local_server = get_option('wps_ic_force_local_server');
        if ($local_server !== false && $local_server !== 'auto') {
            self::$apiURL = 'https://' . $local_server . '/local/' . $apiVersion . '/';
        }

        if (!isset(self::$options['api_key'])) {
            self::$options['api_key'] = '';
        }

        if (empty(self::$settings)) {
            $options = new wps_ic_options();
            $settings = $options->get_preset('lite');
            self::$settings = $settings;
        }

        if (!isset(self::$settings['optimization'])) {
            self::$settings['optimization'] = '';
        }

        
        self::$apiParams = [];
        self::$apiParams['apikey'] = self::$options['api_key'];
        self::$apiParams['quality'] = self::$settings['optimization'];
        self::$apiParams['retina'] = 'false';
        self::$apiParams['webp'] = 'false';
        self::$apiParams['width'] = 'false';
        self::$apiParams['url'] = '';
    }

    



    public static function buildOptimizeParams($imageID = null, $site_url = null, $settings = null)
    {
        if (!$settings) {
            $settings = get_option(WPS_IC_SETTINGS);
        }
        if (!$site_url) {
            $site_url = get_site_url();
            if (is_ssl()
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                || strpos(home_url(), 'https://') === 0
                || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
            ) {
                $site_url = str_replace('http://', 'https://', $site_url);
            }
        }

        $options = get_option(WPS_IC_OPTIONS);

        
        $local_quality = $settings['local_qualityLevel'] ?? '0';
        $cdn_quality_map = ['1' => 'lossless', '2' => 'intelligent', '3' => 'ultra'];
        $cdn_level = $cdn_quality_map[$settings['qualityLevel'] ?? '2'] ?? 'intelligent';
        $resolved_level = ($local_quality === '0' || empty($local_quality))
            ? $cdn_level
            : ($cdn_quality_map[$local_quality] ?? $cdn_level);

        
        $memory = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $max_exec = (int) ini_get('max_execution_time');
        $hosting = ($memory < 268435456 || $max_exec < 60) ? 'shared' : 'vps';

        $params = [
            'imageSite'     => $site_url,
            'apikey'        => $options['api_key'] ?? '',
            'level'         => $resolved_level,
            'webp'          => !empty($settings['generate_webp']) && $settings['generate_webp'] == '1' ? '1' : '0',
            'avif'          => !empty($settings['picture_avif']) && $settings['picture_avif'] == '1' ? '1' : '0',
            'maxWidth'      => $settings['maxWidth'] ?? $settings['local_maxWidth'] ?? '2560',
            'hosting'       => $hosting,
            'pluginVersion' => defined('WPS_IC_VERSION') ? WPS_IC_VERSION : (defined('WPS_IC_LOCAL_V') ? WPS_IC_LOCAL_V : '7.01.0'),
        ];

        if ($imageID) {
            $params['imageID'] = $imageID;
        }

        return $params;
    }


    public static function buildCropsJson() {
        $crops = [];


        $settings  = get_option(WPS_IC_SETTINGS);
        $max_width = (int) ($settings['maxWidth'] ?? $settings['local_maxWidth'] ?? 2560);
        if ($max_width > 0) {
            $crops['original'] = [
                'width'  => $max_width,
                'height' => $max_width,
                'crop'   => false,
            ];
        }

        if (!function_exists('wp_get_registered_image_subsizes')) {
            return json_encode($crops);
        }
        $subsizes = wp_get_registered_image_subsizes();
        foreach ($subsizes as $name => $size) {
            $crops[$name] = [
                'width'  => $size['width'],
                'height' => $size['height'],
                'crop'   => $size['crop'],
            ];
        }
        return json_encode($crops);
    }

    



    public static function buildFilenamesJson($imageID) {
        $filenames = [];

        
        $unscaledPath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : null;
        if ($unscaledPath) {
            $filenames['original'] = basename($unscaledPath);
        }

        
        $meta = wp_get_attachment_metadata($imageID);
        if (!empty($meta['file']) && strpos($meta['file'], '-scaled') !== false) {
            $filenames['scaled'] = basename($meta['file']);
        }

        
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sizeName => $info) {
                if (!empty($info['file'])) {
                    $filenames[$sizeName] = $info['file'];
                }
            }
        }

        return json_encode($filenames);
    }


    public static function buildSizesJson($imageID) {
        $sizes = [];

        
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : null;
        if ($unscaled && file_exists($unscaled)) {
            $sizes['original'] = filesize($unscaled);
        }

        $main = get_attached_file($imageID);
        if ($main && file_exists($main)) {
            $meta = wp_get_attachment_metadata($imageID);

            
            if (!empty($meta['file']) && strpos($meta['file'], '-scaled') !== false) {
                $sizes['scaled'] = filesize($main);
            }

            
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                $baseDir = dirname($main);
                foreach ($meta['sizes'] as $sizeName => $info) {
                    if (!empty($info['file'])) {
                        $path = $baseDir . '/' . $info['file'];
                        if (file_exists($path)) {
                            $sizes[$sizeName] = filesize($path);
                        }
                    }
                }
            }
        }

        return json_encode($sizes);
    }


    public static function postOptimize($imageID, $params, $blocking = true, $timeout = 120) {
        $file_path = get_attached_file($imageID);


        if (!function_exists('curl_init') || !class_exists('CURLFile') || !$file_path || !is_readable($file_path)) {
            if (function_exists('wpc_log_trigger')) {
                wpc_log_trigger('post_optimize_skip', $imageID, [
                    'reason' => !function_exists('curl_init') ? 'no_curl' : (!$file_path ? 'no_file_path' : 'not_readable'),
                    'file_path' => $file_path ?: '(empty)',
                ]);
            }
            return new WP_Error('wpc_no_post', 'POST optimization unavailable: cURL/CURLFile missing or file not readable. GET fallback deprecated in 7.01.0.');
        }


        $unscaled = wp_get_original_image_path($imageID);
        $upload_path = $file_path;

        if ($unscaled && $unscaled !== $file_path && file_exists($unscaled) && is_readable($unscaled)) {
            $unscaled_size = filesize($unscaled);
            if ($unscaled_size >= 10240) { 
                $upload_path = $unscaled;
            }
        }


        if (!empty($params['file_path_override']) && is_readable($params['file_path_override'])) {
            $upload_path = $params['file_path_override'];
        }

        
        $body = [
            'apikey'        => $params['apikey'] ?? '',
            'imageSite'     => $params['imageSite'] ?? '',
            'imageID'       => $imageID,
            'level'         => $params['level'] ?? 'intelligent',
            'webp'          => $params['webp'] ?? '1',
            'avif'          => $params['avif'] ?? '1',
            'maxWidth'      => $params['maxWidth'] ?? '2560',
            'hosting'       => $params['hosting'] ?? 'shared',
            'pluginVersion' => $params['pluginVersion'] ?? (defined('WPS_IC_VERSION') ? WPS_IC_VERSION : '7.01.0'),


            'crops'         => isset($params['crops']) ? $params['crops'] : self::buildCropsJson(),
            'filenames'     => isset($params['filenames']) ? $params['filenames'] : self::buildFilenamesJson($imageID),
            'sizes'         => self::buildSizesJson($imageID),
            'image'         => new CURLFile($upload_path, (function_exists('mime_content_type') ? mime_content_type($upload_path) : false) ?: 'image/jpeg', basename($upload_path)),
        ];


        if (isset($params['parentImageID']))   $body['parentImageID']   = (string) $params['parentImageID'];
        if (isset($params['skipBackup']))      $body['skipBackup']      = (string) $params['skipBackup'];
        if (isset($params['sizeLabel']))       $body['sizeLabel']       = (string) $params['sizeLabel'];
        if (isset($params['triggerContext']))  $body['triggerContext']  = (string) $params['triggerContext'];


        $cs_ms = 0;
        if (isset($params['compressStartedAt']) && (int) $params['compressStartedAt'] > 0) {
            $cs_ms = (int) $params['compressStartedAt'];
        } else {
            $cs_post_meta = (int) get_post_meta($imageID, '_wpc_compress_started_at', true);
            if ($cs_post_meta > 0) {

                $cs_ms = $cs_post_meta * 1000;
            } else {
                $cs_ms = (int) round(microtime(true) * 1000);
            }
        }
        $body['compressStartedAt'] = (string) $cs_ms;

        $ch = curl_init(WPC_IC_LOCAL_OPTIMIZE);
        $opts = [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'WP-Compress/' . WPS_IC_LOCAL_V,
        ];
        


        if (defined('WPC_STAGING') && WPC_STAGING) {
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);


        $mem_bytes = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes(ini_get('memory_limit')) : 0;
        $headers = [];


        if ($mem_bytes === 0 || $mem_bytes === -1 || $mem_bytes >= 134217728) { 
            $headers[] = 'X-Plugin-Accepts-Bytes-Inline: 1';
            $headers[] = 'X-Plugin-Accepts-Bg-Swap: 1';


            $headers[] = 'X-Plugin-Accepts-NoImprovement: 1';
        }
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        if (!$blocking) {
            curl_setopt($ch, CURLOPT_TIMEOUT_MS, 1000);
            curl_setopt($ch, CURLOPT_NOSIGNAL, true);
            curl_exec($ch);
            curl_close($ch);
            return true;
        }

        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        
        $ttfb_s      = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
        $total_s     = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $size_bytes  = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);


        $svc_multer_ms = 0; $svc_phase1_ms = 0; $svc_db_writes_ms = 0;
        if ($http_code >= 200 && $http_code < 300 && is_string($response)) {
            $decoded = json_decode($response);
            if (is_object($decoded) && isset($decoded->timing) && is_object($decoded->timing)) {
                $svc_multer_ms    = (int) round((float) ($decoded->timing->multerMs    ?? 0));
                $svc_phase1_ms    = (int) round((float) ($decoded->timing->phase1Ms    ?? 0));
                $svc_db_writes_ms = (int) round((float) ($decoded->timing->dbWritesMs  ?? 0));
            }
        }
        
        update_post_meta($imageID, '_wpc_last_post_timing', [
            'ttfb_ms'         => (int) round($ttfb_s * 1000),
            'total_ms'        => (int) round($total_s * 1000),
            'size_bytes'      => $size_bytes,
            'http_code'       => (int) $http_code,
            'multer_ms'       => $svc_multer_ms,
            'phase1_ms'       => $svc_phase1_ms,
            'db_writes_ms'    => $svc_db_writes_ms,
            'at'              => time(),
        ]);
        curl_close($ch);

        
        if ($http_code === 403) {
            return new WP_Error('wpc_not_authorized', 'Local optimization not available on your plan');
        }

        
        
        if ($http_code === 404 || $http_code === 410) {
            if (function_exists('wpc_log_trigger')) {
                wpc_log_trigger('endpoint_gone', $imageID, ['http_code' => $http_code]);
            }
            return new WP_Error('wpc_endpoint_gone', 'Service /optimize returned HTTP ' . $http_code . ' — plugin/service version mismatch', ['http_code' => $http_code]);
        }


        if ($error || $http_code < 200 || $http_code >= 300) {
            $body_excerpt = substr((string) $response, 0, 200);
            if (function_exists('wpc_log_trigger')) {
                wpc_log_trigger('post_optimize_fail', $imageID, [
                    'http_code'  => $http_code,
                    'curl_error' => $error ?: null,
                    'body'       => $body_excerpt,
                ]);
            }
            update_post_meta($imageID, '_wpc_last_post_fail', [
                'http_code' => $http_code,
                'at'        => time(),
                'body'      => $body_excerpt,
            ]);
            return new WP_Error('wpc_post_optimize_failed', 'POST /optimize failed (HTTP ' . $http_code . ')', [
                'http_code'  => $http_code,
                'curl_error' => $error,
                'body'       => $body_excerpt,
            ]);
        }

        
        delete_post_meta($imageID, '_wpc_last_post_fail');

        return ['body' => $response, 'response' => ['code' => $http_code]];
    }

    public function get_filesystem()
    {
        require_once(ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php');
        require_once(ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php');
        global $wpc_filesystem;

        if (!defined('FS_CHMOD_DIR')) {
            define('FS_CHMOD_DIR', (fileperms(ABSPATH) & 0777 | 0755));
        }

        if (!defined('FS_CHMOD_FILE')) {
            define('FS_CHMOD_FILE', (fileperms(ABSPATH . 'index.php') & 0777 | 0644));
        }

        if (!isset($wpc_filesystem) || !is_object($wpc_filesystem)) {
            $wpc_filesystem = new WP_Filesystem_Direct('');
        }
    }

    public function getAllThumbSizes()
    {
        $cache_key = 'wps_ic_image_sizes';

        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }

        global $_wp_additional_image_sizes;

        $default_image_sizes = get_intermediate_image_sizes();
        $image_sizes = [];

        foreach ($default_image_sizes as $size) {
            $crop = get_option("{$size}_crop");

            $image_sizes[$size]['width']  = intval(get_option("{$size}_size_w"));
            $image_sizes[$size]['height'] = intval(get_option("{$size}_size_h"));
            $image_sizes[$size]['crop']   = $crop ? $crop : false;
        }

        if (isset($_wp_additional_image_sizes) && count($_wp_additional_image_sizes)) {
            $image_sizes = array_merge($image_sizes, $_wp_additional_image_sizes);
        }

        $AdditionalSizes = ['full'];
        foreach ($AdditionalSizes as $size) {
            $image_sizes[$size]['width'] = 'full';
        }

        $image_sizes['original']['width'] = 'original';

        set_transient($cache_key, $image_sizes, 1 * HOUR_IN_SECONDS);

        return $image_sizes;
    }


    public function geoLocate()
    {
        $force_location = get_option('wpc-ic-force-location');
        if (!empty($force_location)) {
            return $force_location;
        }

        $call = wp_remote_get('https://cdn.zapwp.net/?action=geo_locate&domain=' . urlencode(site_url()), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
        if (wp_remote_retrieve_response_code($call) == 200) {
            $body = wp_remote_retrieve_body($call);
            $body = json_decode($body);

            if ($body->success) {
                update_option('wps_ic_geo_locate_v2', $body->data);

                return $body->data;
            } else {
                update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);

                return ['country' => 'EU', 'server' => 'frankfurt.zapwp.net'];
            }
        } else {
            update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);

            return ['country' => 'EU', 'server' => 'frankfurt.zapwp.net'];
        }
    }

    public function routes()
    {

        $this->fetchImages();
        $this->restoreImage();
        $this->downloadImages();
        $this->initBulk();
    }


    public function registerEndpoints() {
        register_rest_route('wpc/v1', '/fetch', [
            'methods'             => [\WP_REST_Server::READABLE, \WP_REST_Server::CREATABLE],
            'callback'            => [$this, 'wpc_handle_fetch_image'],
            'permission_callback' => [$this, 'wpc_permission_api_key'],
        ]);

        register_rest_route('wpc/v1', '/compress-async', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'wpc_handle_async_compress'],
            'permission_callback' => [$this, 'wpc_permission_api_key'],
        ]);

        
        register_rest_route('wpc/v1', '/compress-single', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'wpc_handle_single_compress'],
            'permission_callback' => [$this, 'wpc_permission_api_key'],
        ]);
    }


    public function wpc_handle_single_compress(\WP_REST_Request $request) {


        $instr_rest_t0 = microtime(true);
        $instr_image_id_for_log = (int) $request->get_param('imageID');
        $instr_t_pre_single = 0.0;
        $instr_t_post_single = 0.0;
        register_shutdown_function(function() use ($instr_rest_t0, $instr_image_id_for_log, &$instr_t_pre_single, &$instr_t_post_single) {
            $t_shutdown = microtime(true);
            $rest_total_ms      = (int) round(($t_shutdown - $instr_rest_t0) * 1000);
            $pre_single_ms      = ($instr_t_pre_single  > 0) ? (int) round(($instr_t_pre_single  - $instr_rest_t0)       * 1000) : -1;
            $single_total_ms    = ($instr_t_pre_single  > 0 && $instr_t_post_single > 0) ? (int) round(($instr_t_post_single - $instr_t_pre_single) * 1000) : -1;
            $post_single_ms     = ($instr_t_post_single > 0) ? (int) round(($t_shutdown            - $instr_t_post_single) * 1000) : -1;
            error_log(sprintf(
                '[WPC SingleCompress PROFILE] imageID=%d rest_total_ms=%d pre_single_ms=%d single_total_ms=%d post_single_ms=%d',
                $instr_image_id_for_log, $rest_total_ms, $pre_single_ms, $single_total_ms, $post_single_ms
            ));
        });

        
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);

        $imageID = intval($request->get_param('imageID'));
        if (!$imageID) {
            return rest_ensure_response(['success' => false, 'reason' => 'no-image-id']);
        }

        
        $perImageLock = 'wpc_compress_lock_' . $imageID;
        if (get_transient($perImageLock)) {
            return rest_ensure_response(['success' => false, 'reason' => 'already-processing']);
        }

        
        
        $maxConcurrent = defined('WPC_MAX_CONCURRENT_COMPRESS') ? max(1, (int) WPC_MAX_CONCURRENT_COMPRESS) : 2;
        $currentCount = (int) get_transient('wpc_single_concurrent');

        if ($currentCount >= $maxConcurrent) {
            
            $this->routeToQueue($imageID);
            error_log('[WPC Single] image=' . $imageID . ' routed-to-queue (concurrent=' . $currentCount . '/' . $maxConcurrent . ')');
            return rest_ensure_response(['success' => true, 'fallback' => 'queued-cap-reached']);
        }

        
        set_transient('wpc_single_concurrent', $currentCount + 1, 300);
        set_transient($perImageLock, time(), 300);

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '2048M');
            @set_time_limit(180);
        }

        $start = microtime(true);


        try {
            $backupOk = $this->backup_all_sizes($imageID);
            if ($backupOk) {
                $instr_t_pre_single = microtime(true);
                $this->singleCompressV4($imageID, 'silent', true, 'single');
                $instr_t_post_single = microtime(true);
            }
        } finally {
            
            $now = (int) get_transient('wpc_single_concurrent');
            set_transient('wpc_single_concurrent', max(0, $now - 1), 300);
            
            delete_transient($perImageLock);
        }
        $elapsed = round(microtime(true) - $start, 2);

        
        $newStatus = get_post_meta($imageID, 'ic_status', true);

        if ($newStatus === 'compressed') {
            error_log('[WPC Single] image=' . $imageID . ' time=' . $elapsed . 's status=success');
            delete_transient('wps_ic_compress_' . $imageID);
            return rest_ensure_response(['success' => true, 'time' => $elapsed]);
        }


        if (wp_next_scheduled('wpc_retry_compress', [$imageID])) {
            error_log('[WPC Single] image=' . $imageID . ' time=' . $elapsed . 's → retry scheduled by singleCompressV4');
            return rest_ensure_response(['success' => true, 'fallback' => 'retry-scheduled']);
        }

        
        $last_err  = get_post_meta($imageID, '_wpc_last_post_fail', true);
        $http_code = is_array($last_err) ? (int) ($last_err['http_code'] ?? 0) : 0;
        if ($http_code === 404 || $http_code === 410) {
            error_log('[WPC Single] image=' . $imageID . ' endpoint-gone HTTP' . $http_code . ' — no retry, no queue');
            return rest_ensure_response(['success' => false, 'reason' => 'endpoint-gone', 'http_code' => $http_code]);
        }

        error_log('[WPC Single] image=' . $imageID . ' time=' . $elapsed . 's status=failed -> routing to queue');
        $this->routeToQueue($imageID);

        
        

        return rest_ensure_response(['success' => true, 'fallback' => 'queued-after-failure', 'time' => $elapsed]);
    }

    



    private function routeToQueue($imageID) {
        $queue = get_option('wpc_compress_queue', []);
        if (!in_array($imageID, $queue)) {
            $queue[] = $imageID;
            update_option('wpc_compress_queue', $queue, false);
        }
        $this->fireQueueWorker();
    }

    



    public function wpc_handle_async_compress(\WP_REST_Request $request) {
        
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);

        
        if (get_transient('wpc_compress_lock')) {
            error_log('[WPC Queue] Worker blocked — lock already held');
            return rest_ensure_response(['success' => false, 'reason' => 'worker-already-running']);
        }
        set_transient('wpc_compress_lock', time(), 300);

        $workerStart = microtime(true);
        $processed = 0;
        error_log('[WPC Queue] Worker started. Queue: ' . json_encode(get_option('wpc_compress_queue', [])));


        try {
            
            while (true) {
                wp_cache_delete('wpc_compress_queue', 'options');
                $queue = get_option('wpc_compress_queue', []);
                if (empty($queue)) break;

                
                $imageID = intval(array_shift($queue));
                update_option('wpc_compress_queue', $queue, false);

                if (!$imageID || get_post_type($imageID) !== 'attachment') {
                    error_log('[WPC Queue] Skipping invalid ID=' . $imageID);
                    delete_transient('wps_ic_compress_' . $imageID);
                    continue;
                }

                $remaining = count($queue);
                $queuedAt = 0;
                $trans = get_transient('wps_ic_compress_' . $imageID);
                if ($trans && is_array($trans) && !empty($trans['time'])) {
                    $queuedAt = time() - intval($trans['time']);
                }

                error_log('[WPC Queue] Processing image=' . $imageID . ' position=' . ($processed + 1) . ' remaining=' . $remaining . ' waited=' . $queuedAt . 's');

                
                set_transient('wpc_compress_lock', time(), 300);

                $imgStart = microtime(true);
                try {
                    $backupOk = $this->backup_all_sizes($imageID);
                    if (!$backupOk) {
                        error_log('[WPC Queue] SKIPPED image=' . $imageID . ' — backup failed, will not compress');
                    } else {
                        
                        
                        $this->singleCompressV4($imageID, 'silent', true, 'upload');
                    }
                } catch (\Exception $e) {
                    error_log('[WPC Queue] Exception image=' . $imageID . ': ' . $e->getMessage());
                } catch (\Error $e) {
                    error_log('[WPC Queue] Fatal error image=' . $imageID . ': ' . $e->getMessage());
                }
                $imgElapsed = round(microtime(true) - $imgStart, 2);

                $status = get_post_meta($imageID, 'ic_status', true) ?: 'failed';
                $savings = get_post_meta($imageID, 'ic_savings', true) ?: '0';
                error_log('[WPC Queue] Done image=' . $imageID . ' status=' . $status . ' savings=' . $savings . '% time=' . $imgElapsed . 's');

                
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);

                
                
                if ($status !== 'compressed') {
                    set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 300);
                }

                $processed++;
            }
        } finally {
            
            delete_transient('wpc_compress_lock');
        }

        $totalElapsed = round(microtime(true) - $workerStart, 2);
        error_log('[WPC Queue] Worker done. Processed=' . $processed . ' total_time=' . $totalElapsed . 's');

        return rest_ensure_response(['success' => true, 'processed' => $processed]);
    }

    


    public function fireQueueWorker() {
        
        if (get_transient('wpc_compress_lock')) return;

        $loopback_status = get_option('wpc_loopback_status', '');
        if ($loopback_status === 'fail') return;

        $api_key = $this->getApiKey();


        $qw_parts = wp_parse_url(rest_url('wpc/v1/compress-async'));
        if (!empty($qw_parts['host'])) {
            $qw_https = (!empty($qw_parts['scheme']) && $qw_parts['scheme'] === 'https');
            $qw_port  = !empty($qw_parts['port']) ? (int) $qw_parts['port'] : ($qw_https ? 443 : 80);
            $qw_host  = (string) $qw_parts['host'];
            $qw_path  = (!empty($qw_parts['path']) ? $qw_parts['path'] : '/') . (!empty($qw_parts['query']) ? '?' . $qw_parts['query'] : '');
            $qw_req   = "POST {$qw_path} HTTP/1.1\r\nHost: {$qw_host}\r\nx-api-key: {$api_key}\r\nContent-Length: 0\r\nConnection: close\r\nUser-Agent: WPCQueueWorker/1.0\r\n\r\n";
            $qw_fp = false;
            if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) {
                $qw_fp = wps_ic_ajax::wpc_loopback_open_socket($qw_host, $qw_port, $qw_https, 0.2);
            } else {
                $qw_ctx = $qw_https ? stream_context_create(['ssl' => ['peer_name' => $qw_host, 'SNI_enabled' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]]) : null;
                foreach (['127.0.0.1', 'localhost', $qw_host] as $qw_chost) {
                    $qw_errno = 0; $qw_errstr = '';
                    $qw_remote = ($qw_https ? 'tls://' : 'tcp://') . $qw_chost . ':' . $qw_port;
                    $qw_sock   = $qw_ctx
                        ? @stream_socket_client($qw_remote, $qw_errno, $qw_errstr, 0.2, STREAM_CLIENT_CONNECT, $qw_ctx)
                        : @stream_socket_client($qw_remote, $qw_errno, $qw_errstr, 0.2);
                    if ($qw_sock) { $qw_fp = $qw_sock; break; }
                }
            }
            if ($qw_fp) { @stream_set_timeout($qw_fp, 0, 100000); @fwrite($qw_fp, $qw_req); @fclose($qw_fp); }
        }
    }

    


    public function wait_for_regen_or_clear_stale($imageID, $max_wait_sec = 15)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) return true;
        $max_wait_sec = max(1, (int) $max_wait_sec);

        $start = microtime(true);
        $poll_interval_us = 250000; 
        $checked = 0;

        while ((microtime(true) - $start) < $max_wait_sec) {
            $marker = get_post_meta($imageID, '_wpc_pending_thumb_regen', true);
            if (empty($marker)) {
                
                if ($checked > 0) {
                    error_log('[WPC RegenWait] image=' . $imageID . ' cleared after ' .
                              round(microtime(true) - $start, 2) . 's');
                }
                return true;
            }


            if (is_array($marker) && !empty($marker['scheduled_at'])) {
                $age_sec = time() - (int) $marker['scheduled_at'];
                if ($age_sec > 60) {
                    error_log('[WPC RegenWait] image=' . $imageID . ' marker stale (age=' .
                              $age_sec . 's), proceeding');
                    return true;
                }
            }

            $checked++;
            usleep($poll_interval_us);
        }

        
        
        error_log('[WPC RegenWait] image=' . $imageID . ' BUDGET EXHAUSTED after ' .
                  $max_wait_sec . 's, proceeding with current disk state');
        return false;
    }

    public function backup_all_sizes($imageID) {
        $backupMode = self::$settings['backup'] ?? 'full';

        
        if ($backupMode === 'off') {
            error_log('[WPC Backup] image=' . $imageID . ' mode=off — skipped');
            return true;
        }

        
        if ($backupMode === 'cloud') {
            update_post_meta($imageID, 'wpc_backup_mode', 'cloud');
            error_log('[WPC Backup] image=' . $imageID . ' mode=cloud — local skipped');
            return true;
        }

        
        $backupBase = WP_CONTENT_DIR . '/wpc-backups/';
        $uploadDir = wp_upload_dir()['basedir'];
        $filesCopied = 0;
        $mainBackedUp = false;
        $backupFull = ($backupMode === 'full' || $backupMode === 'local-cloud');

        
        $testDir = $backupBase . 'test_' . $imageID;
        if (!wp_mkdir_p($testDir)) {
            error_log('[WPC Backup] FAILED — backup directory not writable: ' . $backupBase);
            return false;
        }
        @rmdir($testDir);

        
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : null;
        if ($unscaled && file_exists($unscaled)) {
            $rel = str_replace($uploadDir . '/', '', $unscaled);
            $dest = $backupBase . $rel;
            wp_mkdir_p(dirname($dest));
            if (!file_exists($dest)) {
                copy($unscaled, $dest);
                if (file_exists($dest) && filesize($dest) > 0) {
                    $filesCopied++;
                    $mainBackedUp = true;
                } else {
                    error_log('[WPC Backup] FAILED to copy main file image=' . $imageID . ' src=' . basename($unscaled));
                    return false;
                }
            } else {
                $mainBackedUp = true;
            }
        }

        
        $scaled = get_attached_file($imageID);
        if ($backupFull || $backupMode === 'local') {
            if ($scaled && file_exists($scaled) && $scaled !== $unscaled) {
                $rel = str_replace($uploadDir . '/', '', $scaled);
                $dest = $backupBase . $rel;
                wp_mkdir_p(dirname($dest));
                if (!file_exists($dest)) {
                    copy($scaled, $dest);
                    if (file_exists($dest) && filesize($dest) > 0) {
                        $filesCopied++;
                        $mainBackedUp = true;
                    } else {
                        error_log('[WPC Backup] FAILED to copy scaled file image=' . $imageID);
                        return false;
                    }
                } else {
                    $mainBackedUp = true;
                }
            }
        }

        
        if ($backupFull) {
            $meta = wp_get_attachment_metadata($imageID);
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                $dir = dirname($scaled ?: $unscaled);
                foreach ($meta['sizes'] as $size => $info) {
                    if (empty($info['file'])) continue;
                    $thumbPath = $dir . '/' . $info['file'];
                    if (file_exists($thumbPath)) {
                        $rel = str_replace($uploadDir . '/', '', $thumbPath);
                        $dest = $backupBase . $rel;
                        if (!file_exists($dest)) {
                            @copy($thumbPath, $dest);
                            if (file_exists($dest)) $filesCopied++;
                        }
                    }
                }
            }
        }

        
        $mainFile = $scaled ?: $unscaled;
        if ($mainFile) {
            update_post_meta($imageID, 'wpc_backup_path', str_replace($uploadDir . '/', '', $mainFile));
        }
        update_post_meta($imageID, 'wpc_backup_mode', $backupMode);

        error_log('[WPC Backup] image=' . $imageID . ' mode=' . $backupMode . ' files=' . $filesCopied . ' main=' . ($mainBackedUp ? 'OK' : 'FAIL'));
        return $mainBackedUp;
    }

    public function wpc_permission_api_key(\WP_REST_Request $request) {
        
        $provided = $request->get_header('x-api-key');

        
        if (!$provided) {
            $auth = $request->get_header('authorization');
            if ($auth && preg_match('/Bearer\s+(.+)/i', $auth, $m)) {
                $provided = trim($m[1]);
            }
        }

        $expected = $this->wpc_get_expected_api_key($provided);
        if (!$expected) {
            return new \WP_Error('wpc_no_api_key', 'API key not configured on server', ['status' => 500]);
        }

        if (!$provided || !hash_equals((string) $expected, (string) $provided)) {
            return new \WP_Error('wpc_forbidden', 'Invalid API key', ['status' => 403]);
        }

        return true;
    }

    




    public function wpc_get_expected_api_key($apikey) {
        $options = get_option(WPS_IC_OPTIONS);
         $expected_token = $options['api_key'];

        if (empty($apikey) || $apikey !== $expected_token) {
            wp_send_json_error('Unauthorized: apikey ' . $apikey, 403);
        }


        $this->raiseLimits();
        return $expected_token;
    }

    


    public function wpc_handle_fetch_image(\WP_REST_Request $request) {
        $image_id = (int) $request->get_param('image_id');

        if ( ! $image_id ) {
            $image_id = $request->get_header('x-image-id');
        }

        if (!$image_id) {
            return new \WP_Error('wpc_bad_request', 'Invalid image ID', ['status' => 401]);
        }

        $post = get_post($image_id);
        if (!$post || get_post_type($image_id) !== 'attachment') {
            return new \WP_Error('wpc_bad_request', 'Invalid image ID', ['status' => 402]);
        }

        
        if (!get_post_meta($image_id, 'wpc_old_meta', true)) {
            $oldMeta = wp_get_attachment_metadata($image_id);
            if (!empty($oldMeta)) {
                update_post_meta($image_id, 'wpc_old_meta', $oldMeta);
            }
        }

        
        $original = wp_get_attachment_url($image_id);
        $thumbArr = wp_get_attachment_image_src($image_id, 'thumbnail');
        $thumb    = is_array($thumbArr) && !empty($thumbArr[0]) ? $thumbArr[0] : '';

        
        $filesizes  = [];
        $meta       = wp_get_attachment_metadata($image_id);
        $uploads    = wp_upload_dir();

        if (!empty($meta) && !empty($meta['file'])) {
            
            $subdir   = ltrim(dirname($meta['file']), './\\');
            $baseUrl  = trailingslashit($uploads['baseurl']) . ($subdir ? trailingslashit($subdir) : '');

            
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $sizeName => $info) {
                    if (!empty($info['file'])) {
                        
                        $filesizes[$sizeName] = $baseUrl . $info['file'];
                    }
                }
            }

            
            if (!empty($original)) {
                $origRelPath = $meta['file']; 
                if (strpos($origRelPath, '-scaled.') !== false) {
                    $unscaledRel = str_replace('-scaled.', '.', $origRelPath);
                    $unscaledAbs = path_join($uploads['basedir'], $unscaledRel);
                    if (file_exists($unscaledAbs)) {
                        $filesizes['unscaled'] = trailingslashit($uploads['baseurl']) . $unscaledRel;
                    }
                }
            }
        }

        
        if ($thumb && !isset($filesizes['thumbnail'])) {
            $filesizes['thumbnail'] = $thumb;
        }


        $payload = [
            'original'  => $original ?: '',
            'thumb'     => $thumb ?: '',
            'filesizes' => $filesizes,
        ];

        $response = new \WP_REST_Response($payload, 200);
        $response->set_headers([
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma'        => 'no-cache',
            'Content-Type'  => 'application/json; charset=' . get_option('blog_charset'),
        ]);
        return $response;
    }

    



    public function checkAPIKey()
    {
        $options = get_option(WPS_IC_OPTIONS);
        $apikey = sanitize_text_field($_GET['apikey']) ?? '';
        $expected_token = !empty($options['api_key']) ? $options['api_key'] : '';

        
        if (empty($expected_token)) {
            global $wpdb;
            $row = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = '" . WPS_IC_OPTIONS . "' LIMIT 1");
            if ($row) {
                $db_options = maybe_unserialize($row);
                $expected_token = !empty($db_options['api_key']) ? $db_options['api_key'] : '';
            }
        }

        if (empty($apikey) || $apikey !== $expected_token) {
            error_log('[WPC] Callback auth failed: received=' . substr($apikey, 0, 8) . '... expected=' . substr($expected_token, 0, 8) . '... URI=' . $_SERVER['REQUEST_URI']);
            wp_send_json_error('Unauthorized', 403);
        }


        $this->raiseLimits();
        return $expected_token;
    }


    



    public function raiseLimits() {
        wp_raise_memory_limit('image');
        ini_set('memory_limit', '1024M');
    }


    public function restoreImage()
    {
        if (isset($_GET['restoreImage'])) {

            
            $this->checkAPIKey();

            if (!function_exists('download_url')) {
                require_once(ABSPATH . "wp-admin" . '/includes/image.php');
                require_once(ABSPATH . "wp-admin" . '/includes/file.php');
                require_once(ABSPATH . "wp-admin" . '/includes/media.php');
            }

            if (!function_exists('update_option')) {
                require_once(ABSPATH . "wp-includes" . '/option.php');
            }

            $imageID = absint($_GET['restoreImage']);
            if (!$imageID) {
                wp_send_json_error('Invalid image ID', 400);
            }

            
            if (get_post_meta($imageID, 'wps_ic_exclude_live', true) === 'true') {
                $bulkStatus = get_option('wps_ic_BulkStatus');
                if (empty($bulkStatus['restoredImageCount'])) {
                    $bulkStatus['restoredImageCount'] = 0;
                }
                $bulkStatus['restoredImageCount'] += 1;
                update_option('wps_ic_BulkStatus', $bulkStatus);
                wp_send_json_success();
            }

            $parsedImages = get_option('wps_ic_parsed_images');

            if (!$parsedImages) {
                $parsedImages = [];
                $parsedImages['total']['original'] = 0;
                $parsedImages['total']['compressed'] = 0;
            }

            if (!function_exists('download_url')) {
                require_once(ABSPATH . "wp-admin" . '/includes/image.php');
                require_once(ABSPATH . "wp-admin" . '/includes/file.php');
                require_once(ABSPATH . "wp-admin" . '/includes/media.php');
            }

            
            $restored = false;
            $scaledPath = get_attached_file($imageID);
            $unscaledPath = $scaledPath ? str_replace('-scaled.', '.', $scaledPath) : '';

            
            $localBkpPaths = array_filter([$unscaledPath . '_bkp', $scaledPath . '_bkp']);
            foreach ($localBkpPaths as $bkpPath) {
                if ($bkpPath && file_exists($bkpPath) && filesize($bkpPath) > 0) {
                    $targetPath = str_replace('_bkp', '', $bkpPath);
                    if (@copy($bkpPath, $targetPath)) {
                        @unlink($bkpPath);
                        $isUnscaled = (strpos($targetPath, '-scaled.') === false && $unscaledPath === $targetPath);
                        if ($isUnscaled) {
                            remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                            $newMeta = wp_generate_attachment_metadata($imageID, $targetPath);
                            if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                        }
                        $restored = true;
                        break;
                    }
                }
            }

            
            if (!$restored) {
                $site_url = get_site_url();
                $request_url = add_query_arg(array('imageID' => $imageID, 'imageSite' => $site_url, 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']), WPC_IC_LOCAL_RESTORE);
                $response = wp_remote_get($request_url, array('timeout' => 30, 'sslverify' => false));

                if (!is_wp_error($response)) {
                    $body = wp_remote_retrieve_body($response);
                    $data = json_decode($body, true);

                    if (!empty($data['backupUrls'])) {
                        $restoreUrl = null;
                        $restoreLabel = null;

                        foreach ($data['backupUrls'] as $backupFile) {
                            if ($backupFile['sizeLabel'] === 'unscaled') {
                                $restoreUrl = $backupFile['fileUrl'];
                                $restoreLabel = 'unscaled';
                                break;
                            }
                            if ($backupFile['sizeLabel'] === 'original' && !$restoreUrl) {
                                $restoreUrl = $backupFile['fileUrl'];
                                $restoreLabel = 'original';
                            }
                        }

                        if ($restoreUrl) {
                            $tmp = download_url($restoreUrl, 60);
                            if (!is_wp_error($tmp)) {
                                if ($restoreLabel === 'unscaled') {
                                    copy($tmp, $unscaledPath);
                                    @unlink($tmp);
                                    remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                                    $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                                    if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                                } else {
                                    if ($scaledPath) copy($tmp, $scaledPath);
                                    @unlink($tmp);
                                }
                                $restored = true;
                            }
                        }
                    }
                }
            }

            
            if ($restored) {
                
                $attachedFile = get_attached_file($imageID);
                if ($attachedFile) {
                    $dir = dirname($attachedFile);
                    $baseName = pathinfo(wp_get_original_image_path($imageID) ?: $attachedFile, PATHINFO_FILENAME);
                    
                    $wpc_rg2_mime = (string) get_post_mime_type($imageID);
                    if ($wpc_rg2_mime !== 'image/webp') {
                        foreach (glob($dir . '/' . $baseName . '*.webp') as $webp) { @unlink($webp); }
                    }
                    if ($wpc_rg2_mime !== 'image/avif') {
                        foreach (glob($dir . '/' . $baseName . '*.avif') as $avif) { @unlink($avif); }
                    }
                }
            }

            
            $parsedImages[$imageID] = ['status' => $restored ? 'restored' : 'failed'];

            
            delete_post_meta($imageID, 'ic_bulk_running');
            delete_post_meta($imageID, 'ic_compressing');
            delete_post_meta($imageID, 'wpc_images_compressed');
            delete_post_meta($imageID, 'ic_stats');
            delete_post_meta($imageID, 'ic_local_variants');
            delete_post_meta($imageID, 'ic_savings');
            delete_post_meta($imageID, 'ic_savings_format');
            delete_post_meta($imageID, 'ic_savings_bytes');
            delete_post_meta($imageID, 'ic_savings_baseline');
            delete_post_meta($imageID, 'ic_skipped');
            update_post_meta($imageID, 'ic_status', 'restored');
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); }

            set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

            
            if (function_exists('wpc_invalidate_local_cache')) { wpc_invalidate_local_cache(); }


            $bulkStatus = get_option('wps_ic_BulkStatus');

            if (empty($bulkStatus['restoredImageCount'])) {
                $bulkStatus['restoredImageCount'] = 0;
            }

            $bulkStatus['restoredImageCount'] += 1;
            update_option('wps_ic_BulkStatus', $bulkStatus);

            update_option('wps_ic_parsed_images', $parsedImages, false);

            wp_send_json_success();
        }
    }


    



    public function fetchImages()
    {
        if (isset($_GET['fetchImageByID'])) {

            
            $this->checkAPIKey();

            $image_id = absint($_GET['fetchImageByID']);
            if (!$image_id) {
                wp_send_json_error('Invalid image ID', 400);
            }

            if (!get_post($image_id) || get_post_type($image_id) !== 'attachment') {
                wp_send_json_error('Invalid image ID', 400);
            }

            
            if (!get_post_meta($image_id, 'wpc_old_meta')) {
                $oldMeta = wp_get_attachment_metadata($image_id);
                update_post_meta($image_id, 'wpc_old_meta', $oldMeta);
            }

            $original = wp_get_attachment_url($image_id);
            $thumb = wp_get_attachment_image_src($image_id, 'thumbnail')[0];

            $sizes = [];
            $available_sizes = get_intermediate_image_sizes();

            foreach ($available_sizes as $size) {
                $image_data = wp_get_attachment_image_src($image_id, $size);
                if (!empty($image_data[0])) {
                    $sizes[$size] = $image_data[0];
                }
            }

            
            $meta = wp_get_attachment_metadata($image_id);
            $upload_dir = wp_upload_dir();

            if (!empty($meta['file'])) {
                $original_path = path_join($upload_dir['basedir'], $meta['file']);
                $original_url = trailingslashit($upload_dir['baseurl']) . $meta['file'];

                
                if (!empty($original)) {
                    $unscaledFilePath = str_replace('-scaled.', '.', $original_path);
                    $unscaledFileUrl = str_replace('-scaled.', '.', $original_url);
                    if (file_exists($unscaledFilePath)) {
                        $sizes['unscaled'] = $unscaledFileUrl;
                    }
                }
            }

            wp_send_json(['original' => $original, 'thumb' => $thumb, 'filesizes' => $sizes]);
        }
    }


    



    public function downloadImages()
    {
        if (isset($_GET['downloadImage'])) {

            
            $expected_token = $this->checkAPIKey();

            require_once ABSPATH . 'wp-admin/includes/image.php';

            $apiStatus = sanitize_text_field($_GET['status']);
            $isBulk = sanitize_text_field($_GET['bulk']) ?? false;
            $imageID = absint($_GET['downloadImage']);
            if (!$imageID) {
                wp_send_json_error('Invalid image ID', 400);
            }

            
            if (get_post_meta($imageID, 'wps_ic_exclude_live', true) === 'true') {
                if ($isBulk) {
                    $bulkStatus = get_option('wps_ic_BulkStatus');
                    if ($bulkStatus) {
                        $bulkStatus['compressedImageCount'] = ($bulkStatus['compressedImageCount'] ?? 0) + 1;
                        update_option('wps_ic_BulkStatus', $bulkStatus);
                    }
                }
                die('skipped');
            }

            
            $original_url = wp_get_attachment_url($imageID);

            if (empty($original_url) || is_wp_error($original_url)) {
                wp_send_json_error('Invalid image ID', 400);
            }

            $basename = basename($original_url);

            
            if (!empty($apiStatus) && $apiStatus == 'skip') {
                
                $stats = [];
                $stats['original']['original']['size'] = 0;
                $stats['original']['compressed']['size'] = 0;
                $stats['original']['compressed']['thumbs'] = 0;

                
                $parsedImages = get_option('wps_ic_parsed_images');

                if (!$parsedImages) {
                    $parsedImages = [];
                    $parsedImages['total']['original'] = 0;
                    $parsedImages['total']['compressed'] = 0;
                }

                
                if ($isBulk) {
                    $thumbCount = $this->getAllThumbSizes();
                    $bulkStatus = get_option('wps_ic_BulkStatus');

                    $parsedImages['total']['original'] += $stats['original']['original']['size'];
                    $parsedImages['total']['compressed'] += $stats['original']['compressed']['size'];

                    $parsedImages[$imageID]['total']['original'] = $parsedImages['total']['original'];
                    $parsedImages[$imageID]['total']['compressed'] = $parsedImages['total']['compressed'];

                    
                    update_option('wps_ic_parsed_images', $parsedImages, false);

                    if (!$bulkStatus) {
                        $bulkStatus = [];
                        $bulkStatus['compressedImageCount'] = 0;
                        $bulkStatus['compressedThumbs'] = 0;
                        $bulkStatus['total']['original']['size'] = 0;
                        $bulkStatus['total']['compressed']['size'] = 0;
                    }

                    $bulkStatus['compressedImageCount'] += 1;
                    $bulkStatus['compressedThumbs'] += count($thumbCount);
                    $bulkStatus['total']['original']['size'] += $stats['original']['original']['size'];
                    $bulkStatus['total']['compressed']['size'] += $stats['original']['compressed']['size'];

                    update_option('wps_ic_BulkStatus', $bulkStatus);

                    
                    $counter = [];
                    $counter['images'] = $bulkStatus['compressedImageCount'];
                    $counter['imagesAndThumbs'] = $bulkStatus['compressedThumbs'];
                    update_option('wps_ic_bulk_counter', $counter);
                }

                
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);

                $imageStats = get_post_meta($imageID, 'ic_stats', true);
                $compressing = get_post_meta($imageID, 'ic_compressing', true);


                update_post_meta($imageID, 'ic_skipped', 'true');

                update_post_meta($imageID, 'wpc_images_compressed', 'true');
                update_post_meta($imageID, 'ic_status', 'compressed');
                update_post_meta($imageID, 'ic_compressing', array('status' => 'compressed'));
                update_post_meta($imageID, 'ic_stats', $stats);
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'compressed'], 60);
                die('skipped');
            }


            $api_url = WPC_IC_LOCAL_DOWNLOAD . '?imageID=' . $imageID . '&apikey=' . $expected_token;

            
            $response = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $response = wp_remote_get($api_url, ['timeout' => 20]);
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 200 && wp_remote_retrieve_response_code($response) < 300) {
                    break;
                }
                if ($attempt < 3) usleep(500000); 
            }
            if (is_wp_error($response)) {
                wp_die('API call failed: ' . $response->get_error_message());
            }


            $image_data = wp_remote_retrieve_body($response);

            if (empty($image_data) || $image_data == 'No optimized images found.') {
                
            } else {
                $body = json_decode($image_data);

                
                $relative_path = get_post_meta($imageID, '_wp_attached_file', true);
                $upload_dir = wp_upload_dir();
                $absolute_path = $upload_dir['basedir'] . '/' . $relative_path;
                $finalImagePath = str_replace($basename, '', $absolute_path);

                
                $errors = false;
                $done = false;

                
                $stats = [];
                $stats['original']['original']['size'] = 0;
                $stats['original']['compressed']['size'] = 0;
                $stats['original']['compressed']['thumbs'] = 0;

                $parsedImages = get_option('wps_ic_parsed_images');

                if (!$parsedImages) {
                    $parsedImages = [];
                    $parsedImages['total']['original'] = 0;
                    $parsedImages['total']['compressed'] = 0;
                }


                $_existing_variants = get_post_meta($imageID, 'ic_local_variants', true);
                $_ic_status = get_post_meta($imageID, 'ic_status', true);


                $_modern_flow_done = is_array($_existing_variants) && !empty($_existing_variants);


                $_compress_in_flight = (bool) get_transient('wpc_compress_lock_' . $imageID);
                $_inline_only = !empty($body->files);
                if ($_inline_only) {
                    foreach ($body->files as $_v) {
                        if (!empty($_v->url)) { $_inline_only = false; break; }
                    }
                }
                if ($_modern_flow_done || $_inline_only || $_compress_in_flight) {
                    $_skip_reason = $_compress_in_flight ? 'compress-in-flight'
                                  : ($_modern_flow_done ? 'modern-flow-done' : 'inline-only-response');
                    error_log('[WPC LegacyDownload] image=' . (int) $imageID . ' SKIPPED — ' . $_skip_reason . ' (existing_variants_count=' . (is_array($_existing_variants) ? count($_existing_variants) : 0) . ', ic_status=' . ($_ic_status ?: '-') . ', body_files=' . (is_array($body->files ?? null) ? count($body->files) : 0) . ', compress_in_flight=' . ($_compress_in_flight ? 'Y' : 'N') . ')');


                    update_post_meta($imageID, 'wpc_images_compressed', 'true');
                    update_post_meta($imageID, 'ic_status', 'compressed');
                    update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
                    delete_transient('wps_ic_compress_' . $imageID);
                    delete_transient('wps_ic_queue_' . $imageID);
                    if ($isBulk) {
                        
                        $bulkStatus = get_option('wps_ic_BulkStatus');
                        if ($bulkStatus) {
                            $bulkStatus['compressedImageCount'] = ($bulkStatus['compressedImageCount'] ?? 0) + 1;
                            update_option('wps_ic_BulkStatus', $bulkStatus);
                        }
                    }
                    wp_send_json_success(['msg' => 'inline-only-skipped', 'imageID' => $imageID]);
                }

                if (!empty($body->files)) {
                    foreach ($body->files as $key => $value) {

                        
                        $imageSize = $value->label;
                        $originalSize = $value->originalSize;
                        $compressedSize = $value->optimizedSize;
                        $savings = $value->savingsPercent;


                        $optimizedUrl = $value->url ?? '';
                        if (empty($optimizedUrl)) continue;

                        $parsedImages[$imageID][$imageSize]['original'] = $stats['original']['original']['size'];
                        $parsedImages[$imageID][$imageSize]['compressed'] = $stats['original']['compressed']['size'];

                        $stats['original']['original']['size'] += $originalSize;
                        $stats['original']['compressed']['size'] += $compressedSize;
                        $stats['original']['compressed']['thumbs'] += 1;

                        $optimizedBasename = basename($optimizedUrl);
                        $optimizedFilePath = $finalImagePath . $optimizedBasename;


                        if (file_exists($optimizedFilePath) || strpos($optimizedBasename, '.webp') !== false || strpos($optimizedBasename, '.avif') !== false) {

                            
                            $response = wp_remote_get($optimizedUrl);

                            if (!is_wp_error($response)) {
                                $image_data = wp_remote_retrieve_body($response);

                                
                                
                                $legacy_fmt = 'jpeg';
                                if (strpos($optimizedBasename, '.avif') !== false) $legacy_fmt = 'avif';
                                elseif (strpos($optimizedBasename, '.webp') !== false) $legacy_fmt = 'webp';
                                elseif (strpos($optimizedBasename, '.png') !== false) $legacy_fmt = 'png';
                                $is_valid = wpc_is_valid_image_bytes($image_data, $legacy_fmt, isset($imageID) ? $imageID : 0, 'legacy_url_download', ['size_label' => $optimizedBasename, 'url' => $optimizedUrl]);
                                if ($is_valid) {

                                    
                                    $backupSetting = isset(self::$settings['backup']) ? self::$settings['backup'] : 'cloud';
                                    if (($backupSetting === 'local' || $backupSetting === 'local-cloud') && file_exists($optimizedFilePath)) {
                                        $pathInfo = pathinfo($optimizedFilePath);
                                        $bkpPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_bkp.' . $pathInfo['extension'];
                                        if (!file_exists($bkpPath)) {
                                            @copy($optimizedFilePath, $bkpPath);
                                        }
                                    }

                                    
                                    if (file_exists($optimizedFilePath)) {
                                        unlink($optimizedFilePath);
                                    }

                                    
                                    file_put_contents($optimizedFilePath, $image_data);

                                    echo "Downloaded and replaced: " . $optimizedBasename;
                                    $done = true;
                                } else {
                                    $errors = true;
                                    echo "Error: Downloaded data is not a valid image for " . $optimizedUrl;
                                }

                            } else {
                                echo "Failed to download optimized image: " . $optimizedUrl;
                            }

                        }

                    }

                    
                    if ($done) {
                        
                        if ($isBulk) {
                            $thumbCount = $this->getAllThumbSizes();
                            $bulkStatus = get_option('wps_ic_BulkStatus');

                            $parsedImages['total']['original'] += $stats['original']['original']['size'];
                            $parsedImages['total']['compressed'] += $stats['original']['compressed']['size'];

                            $parsedImages[$imageID]['total']['original'] = $parsedImages['total']['original'];
                            $parsedImages[$imageID]['total']['compressed'] = $parsedImages['total']['compressed'];

                            
                            update_option('wps_ic_parsed_images', $parsedImages, false);

                            if (!$bulkStatus) {
                                $bulkStatus = [];
                                $bulkStatus['compressedImageCount'] = 0;
                                $bulkStatus['compressedThumbs'] = 0;
                                $bulkStatus['total']['original']['size'] = 0;
                                $bulkStatus['total']['compressed']['size'] = 0;
                            }

                            $bulkStatus['compressedImageCount'] += 1;
                            $bulkStatus['compressedThumbs'] += count($thumbCount);
                            $bulkStatus['total']['original']['size'] += $stats['original']['original']['size'];
                            $bulkStatus['total']['compressed']['size'] += $stats['original']['compressed']['size'];

                            update_option('wps_ic_BulkStatus', $bulkStatus);

                            
                            $counter = [];
                            $counter['images'] = $bulkStatus['compressedImageCount'];
                            $counter['imagesAndThumbs'] = $bulkStatus['compressedThumbs'];
                            update_option('wps_ic_bulk_counter', $counter);
                        }

                        
                        delete_transient('wps_ic_compress_' . $imageID);
                        delete_transient('wps_ic_queue_' . $imageID);

                        $imageStats = get_post_meta($imageID, 'ic_stats', true);
                        $compressing = get_post_meta($imageID, 'ic_compressing', true);

                        update_post_meta($imageID, 'wpc_images_compressed', 'true');
                        update_post_meta($imageID, 'ic_status', 'compressed');
                        update_post_meta($imageID, 'ic_compressing', array('status' => 'compressed'));
                        update_post_meta($imageID, 'ic_stats', $stats);
                        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'compressed'], 60);

                        
                        delete_post_meta($imageID, '_wpc_optimize_attempts');
                        delete_transient('wpc_queued_' . $imageID);
                        delete_transient('wpc_failed_' . $imageID);
                        if (function_exists('wp_cache_delete')) {
                            wp_cache_delete('wpc_queued_' . $imageID, 'wpc');
                        }

                        
                        $service_skipped = [];
                        if (!empty($body->skippedFormats) && is_array($body->skippedFormats)) {
                            foreach ($body->skippedFormats as $skip) {
                                $sl = $skip->sizeLabel ?? '';
                                $fmt = $skip->format ?? '';
                                if ($sl && $fmt) {
                                    if (!isset($service_skipped[$sl])) $service_skipped[$sl] = [];
                                    $service_skipped[$sl][] = $fmt;
                                }
                            }
                        }

                        
                        
                        $variants = [];
                        foreach ($body->files as $variant) {
                            $orig = intval($variant->originalSize ?? 0);
                            $opt = intval($variant->optimizedSize ?? 0);
                            $is_regression = ($orig > 0 && $opt > 0 && $opt >= $orig);


                            $entry = [
                                'url'          => $variant->url ?? '',
                                'originalSize' => $variant->originalSize ?? 0,
                                'size'         => $variant->optimizedSize ?? 0,
                                'savings'      => $variant->savingsPercent ?? 0,
                                'skipped'      => $is_regression,
                            ];
                            if (!empty($service_skipped[$variant->label])) {
                                $entry['skipped_formats'] = $service_skipped[$variant->label];
                            }
                            $variants[$variant->label] = $entry;
                        }


                        global $wpdb;
                        $dl_lock_name = 'wpc_bg_meta_' . (int) $imageID;
                        $dl_locked    = wpc_worker_lock($dl_lock_name);
                        try {
                            
                            
                            $existing_variants = get_post_meta($imageID, 'ic_local_variants', true);
                            if (is_array($existing_variants) && !empty($existing_variants)) {
                                foreach ($existing_variants as $existing_key => $existing_entry) {


                                    if (!empty($existing_entry['bg_upgraded'])) {
                                        $variants[$existing_key] = $existing_entry;
                                        continue;
                                    }
                                    
                                    if (!isset($variants[$existing_key])) {
                                        $variants[$existing_key] = $existing_entry;
                                    }
                                    
                                }
                            }
                            update_post_meta($imageID, 'ic_local_variants', $variants);
                        } finally {
                            if ($dl_locked) {
                                wpc_worker_unlock($dl_lock_name);
                            }
                        }


                        $best = wpc_compute_best_savings($variants, $imageID);
                        if ($best['pct'] > 0 && $best['orig'] > 0) {
                            update_post_meta($imageID, 'ic_savings',          round($best['pct'], 1));
                            update_post_meta($imageID, 'ic_savings_format',   $best['format']);
                            update_post_meta($imageID, 'ic_savings_bytes',    $best['orig'] - $best['opt']);
                            update_post_meta($imageID, 'ic_savings_baseline', $best['orig']);
                        }

                        
                        if (function_exists('wpc_invalidate_local_cache')) {
                            wpc_invalidate_local_cache();
                        }

                        
                        
                        $relative_path = get_post_meta($imageID, '_wp_attached_file', true);
                        $upload_dir = wp_upload_dir();
                        $file_path = $upload_dir['basedir'] . '/' . $relative_path;

                        $unscaledPath = str_replace('-scaled.', '.', $file_path);
                        if (file_exists($unscaledPath)) {
                            $file_path = $unscaledPath;
                        }


                    }

                }
            }

            die();
        }
    }


    



    public function initBulk()
    {
        if (!empty($_GET['getImageList'])) {

            
            $this->checkAPIKey();

            if (empty($_GET['action']) || $_GET['action'] == 'compress') {
                
                $imagesToProcess = $this->getAllImageIDs();
            } else {
                
                $imagesToProcess = $this->getImagesToRestore();
            }

            
            $countImagesToOptimize = count($imagesToProcess);

            
            $imageSizes = count($this->getAllThumbSizes());
            $thumbnailCount = $countImagesToOptimize * $imageSizes;

            $counter = [];
            $counter['images'] = 0;
            $counter['imagesAndThumbs'] = 0;
            update_option('wps_ic_bulk_counter', $counter);

            $bulkStats = get_option('wps_ic_BulkStatus');
            $bulkStats['foundImageCount'] = $countImagesToOptimize;
            $bulkStats['foundThumbCount'] = $thumbnailCount;
            update_option('wps_ic_BulkStatus', $bulkStats);

            wp_send_json_success($imagesToProcess);
        }
    }


    




    public function getImagesToRestore($per_page = 100)
    {
        $all_ids = [];

        
        $allowed_mimes = function_exists('wpc_optimizable_mimes') ? wpc_optimizable_mimes() : ['image/jpeg', 'image/png', 'image/gif'];

        $meta_query = [
            'relation' => 'AND',
            ['key' => 'ic_stats', 'compare' => 'EXISTS'],
            ['key' => 'wps_ic_exclude_live', 'compare' => 'NOT EXISTS'],
        ];

        
        $initial_query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => $meta_query]);

        $total_images = $initial_query->found_posts;
        $total_pages = ceil($total_images / $per_page);

        
        for ($page = 1; $page <= $total_pages; $page++) {
            $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => $per_page, 'paged' => $page, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => $meta_query]);

            $all_ids = array_merge($all_ids, $query->posts);
        }

        return $all_ids;
    }


    




    public function getAllImageIDs($per_page = 100)
    {
        $all_ids = [];

        
        $allowed_mimes = function_exists('wpc_optimizable_mimes') ? wpc_optimizable_mimes() : ['image/jpeg', 'image/png', 'image/gif'];


        $meta_query = [
            'relation' => 'AND',
            ['key' => 'ic_stats', 'compare' => 'NOT EXISTS'],
            [
                'relation' => 'OR',
                ['key' => 'ic_status', 'compare' => 'NOT EXISTS'],
                ['key' => 'ic_status', 'value' => 'compressed', 'compare' => '!='],
            ],
            ['key' => 'wps_ic_exclude_live', 'compare' => 'NOT EXISTS'],
        ];

        
        $initial_query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => $meta_query]);

        $total_images = $initial_query->found_posts;
        $total_pages = ceil($total_images / $per_page);

        
        for ($page = 1; $page <= $total_pages; $page++) {
            $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => $per_page, 'paged' => $page, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => $meta_query]);

            $all_ids = array_merge($all_ids, $query->posts);
        }

        return $all_ids;
    }


    




    public function on_delete($post_id)
    {
        
        $imagesCompressed = get_post_meta($post_id, 'wpc_images_compressed', true);
        if (!empty($imagesCompressed) && is_array($imagesCompressed)) {
            foreach ($imagesCompressed as $image => $data) {
                if (!empty($data['webp_path']) && file_exists($data['webp_path'])) {
                    unlink($data['webp_path']);
                }
            }
        }

        
        $variants = get_post_meta($post_id, 'ic_local_variants', true);
        if (!empty($variants) && is_array($variants)) {
            foreach ($variants as $variant) {
                if (!is_array($variant)) continue;
                foreach (['avif_path', 'webp_path', 'jpg_path'] as $pathKey) {
                    if (!empty($variant[$pathKey]) && file_exists($variant[$pathKey])) {
                        @unlink($variant[$pathKey]);
                    }
                }
            }
        }
        delete_transient('wpc_queued_' . $post_id);
        delete_transient('wpc_failed_' . $post_id);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('wpc_queued_' . $post_id, 'wpc');
        }
        $queue = get_option('wpc_compress_queue', []);
        if (is_array($queue) && in_array($post_id, $queue)) {
            $queue = array_values(array_diff($queue, [$post_id]));
            update_option('wpc_compress_queue', $queue, false);
        }
    }


    public function is_supported($imageID)
    {
        $file_data = get_attached_file($imageID);
        $type = wp_check_filetype($file_data);

        
        if (!in_array(strtolower($type['ext']), self::$allowed_types)) {
            return false;
        } else {
            return true;
        }
    }

    public function backup_image($imageID)
    {
        wp_raise_memory_limit('image');

        $backup_mode = self::$settings['backup'] ?? 'cloud';
        if ($backup_mode !== 'local' && $backup_mode !== 'local-cloud') {
            return true;
        }

        
        if ($this->backup_exists($imageID)) {
            return true;
        }

        
        $stats = [];
        $backup_list = [];

        
        $this->create_backup_directory();

        
        $image = wp_get_original_image_url($imageID);
        $image_url = $image;
        $parsed_url = parse_url($image_url);
        $parsed_url['path'] = ltrim($parsed_url['path'], '/');
        $filename = basename($parsed_url['path']);
        $backup_folders = str_replace($filename, '', $parsed_url['path']);
        $backup_folders = rtrim($backup_folders, '/');
        $backup_folders = explode('/', $backup_folders);

        $backup_dir = self::$backup_directory;
        if (is_array($backup_folders)) {
            foreach ($backup_folders as $i => $folder) {
                $backup_dir .= '/' . $folder;
                if (!file_exists($backup_dir)) {
                    $made_dir = mkdir($backup_dir, 0755);
                }
            }
        }

        if (empty($image) || empty($image_url)) {
            return false;
        }

        
        $original_file_location = ABSPATH . $parsed_url['path'];

        
        $backup_file_location = $backup_dir . '/' . $filename;

        
        $stats['original']['original']['size'] = filesize($original_file_location);

        copy($original_file_location, $backup_file_location);

        $backup_list['original'] = $backup_file_location;

        if (!file_exists($backup_file_location)) {
            
            
        }

        update_post_meta($imageID, 'ic_stats', $stats);
        update_post_meta($imageID, 'ic_backup_images', $backup_list);
        update_post_meta($imageID, 'ic_original_stats', $stats);

        return true;
    }


    public function backup_exists($imageID)
    {
        $backup_exists = get_post_meta($imageID, 'ic_backup_images', true);
        if (!empty($backup_exists) && is_array($backup_exists)) {
            foreach ($backup_exists as $filename => $backup_location) {
                if (!empty($backup_location)) {
                    
                    if (file_exists($backup_location)) {
                        return $backup_location;
                    } else {
                        return false;
                    }
                }
            }

            return true;
        } else {
            return false;
        }
    }


    public function create_backup_directory()
    {
        if (!file_exists(self::$backup_directory)) {
            mkdir(self::$backup_directory, 0755);
        }
    }

    public function on_upload($data, $attachment_id)
    {
        $t0 = microtime(true);
        $imageID = $attachment_id;


        if (get_post_meta($imageID, '_wpc_pending_thumb_regen', true)) {
            return $data;
        }


        if (get_transient('wpc_post_restore_grace_' . $imageID)) {
            error_log('[WPC Queue] on_upload image=' . $imageID . ' BLOCKED by post-restore grace window');
            return $data;
        }

        if (!$this->is_supported($imageID)) {
            return $data;
        }

        if ($this->is_already_compressed($imageID)) {
            return $data;
        }

        
        
        self::preempt_ladder_for($imageID);

        
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
        wp_update_attachment_metadata($imageID, $data);
        add_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX, 2);

        update_post_meta($imageID, 'wpc_old_meta', $data);

        
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'queued', 'time' => time()], 300);

        
        $queue = get_option('wpc_compress_queue', []);
        if (!in_array($imageID, $queue)) {
            $queue[] = $imageID;
            update_option('wpc_compress_queue', $queue, false);
        }

        $queueSize = count(get_option('wpc_compress_queue', []));
        $workerRunning = get_transient('wpc_compress_lock') ? 'YES' : 'NO';
        error_log('[WPC Queue] on_upload image=' . $imageID . ' queue_size=' . $queueSize . ' worker_running=' . $workerRunning . ' elapsed=' . round(microtime(true) - $t0, 3) . 's');

        
        $this->fireQueueWorker();

        return $data;
    }


    public function wpc_reoptimize_edited_image($data, $attachment_id)
    {
        static $fired = [];

        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || isset($fired[$attachment_id])) {
            return $data;
        }


        $is_editor_save = (isset($_POST['action']) && $_POST['action'] === 'image-editor')
            || (function_exists('doing_action') && doing_action('wp_ajax_image-editor'))
            || (defined('REST_REQUEST') && REST_REQUEST
                && isset($_SERVER['REQUEST_URI'])
                && preg_match('#/wp/v2/media/\d+/edit/?$#', (string) $_SERVER['REQUEST_URI']));
        if (!$is_editor_save) {
            return $data;
        }

        if (!function_exists('wp_attachment_is_image') || !wp_attachment_is_image($attachment_id)) {
            return $data;
        }

        
        
        if (function_exists('wpc_auto_encoding_disabled') && wpc_auto_encoding_disabled()) {
            return $data;
        }

        
        
        if (!empty($_GET['restoreImage'])
            || get_post_meta($attachment_id, '_wpc_pending_thumb_regen', true)
            || get_transient('wpc_post_restore_grace_' . $attachment_id)) {
            return $data;
        }

        $fired[$attachment_id] = true;


        if (!wp_next_scheduled('wpc_reoptimize_edited_image_event', [$attachment_id])) {
            wp_schedule_single_event(time(), 'wpc_reoptimize_edited_image_event', [$attachment_id]);
        }

        return $data;
    }


    public function wpc_run_edited_reoptimize($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return;
        }
        if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'run_v2_optimize')) {


            if (class_exists('wps_local_compress')) {
                $bk = new wps_local_compress();
                if (method_exists($bk, 'backup_all_sizes')) {
                    $bk->backup_all_sizes($attachment_id);
                }
            }
            wps_ic_ajax::run_v2_optimize($attachment_id, ['resubmit_reason' => 'user_recompress']);
        }
    }

    

    private function canLoopback() {
        return get_option('wpc_loopback_status', '') !== 'fail';
    }

    public function testLoopback() {
        $api_key = $this->getApiKey();
        if (empty($api_key)) {
            update_option('wpc_loopback_status', 'fail', false);
            return false;
        }


        if (get_transient('wpc_loopback_test_at') !== false) {
            return get_option('wpc_loopback_status', 'fail') === 'ok';
        }
        set_transient('wpc_loopback_test_at', time(), HOUR_IN_SECONDS);


        if (!empty($_SERVER['HTTP_CF_RAY'])) {
            update_option('wpc_loopback_status', 'ok', false);
            return true;
        }

        $response = wp_remote_post(rest_url('wpc/v1/fetch'), [
            'blocking'  => true,
            'timeout'   => 3,
            'headers'   => ['x-api-key' => $api_key],
            'body'      => ['image_id' => 0],
            'sslverify' => false,
        ]);

        $code = wp_remote_retrieve_response_code($response);
        $works = !is_wp_error($response) && $code > 0;

        update_option('wpc_loopback_status', $works ? 'ok' : 'fail', false);
        return $works;
    }

    private function getApiKey() {
        if (defined('WPC_API_KEY')) return WPC_API_KEY;
        $options = get_option('wps_ic');
        return !empty($options['api_key']) ? $options['api_key'] : '';
    }

    public function writeLog($message)
    {
        if ($this->enabledLog == 'true' && $this->logFile) {
            fwrite($this->logFile, "[" . date('d.m.Y H:i:s') . "] " . $message . "\r\n");
        }
    }

    public function is_already_compressed($imageID)
    {
        $backup_exists = get_post_meta($imageID, 'ic_status', true);
        if (!empty($backup_exists) && $backup_exists == 'compressed') {
            return true;
        } else {
            return false;
        }
    }


    public static function preempt_ladder_for($imageID)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) return;
        $queue = get_option('wpc_ladder_gen_queue', []);
        if (!is_array($queue) || !isset($queue[$imageID])) return;
        unset($queue[$imageID]);
        update_option('wpc_ladder_gen_queue', $queue, false);
        if (empty($queue)) {
            update_option('wpc_ladder_gen_queue_has_items', false, false);
        }
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WPC Dedup] image=' . $imageID . ' pre-empted ladder fire (full compress incoming)');
        }
    }


    public function singleCompressV4($imageID, $output = 'json', $allowRetry = true, $source = 'unknown')
    {
        @set_time_limit(120);
        wp_raise_memory_limit('image');
        $t_compress_start = microtime(true);


        update_post_meta($imageID, '_wpc_compress_started_at', time());

        
        if (!$this->is_supported($imageID)) {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-not-supported']);
            } else {
                return 'file-not-supported';
            }
        }

        
        if ($this->is_already_compressed($imageID)) {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            $media_library = new wps_ic_media_library_live();
            $html = $media_library->compress_details($imageID);

            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-already-compressed', 'imageID' => $imageID, 'html' => $html]);
            } else {
                return 'file-already-compressed';
            }
        }


        if (function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol()
            && class_exists('WPS_LocalV2')
            && class_exists('wps_ic_ajax')
            && method_exists('wps_ic_ajax', 'run_v2_optimize')) {
            $v2_result = wps_ic_ajax::run_v2_optimize($imageID);
            if (!empty($v2_result['ok'])) {
                
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);
                error_log('[WPC] singleCompressV4 image=' . $imageID . ' source=' . $source . ' routed to v2 — SUCCESS');
                if ($output === 'json') {
                    $media_library = new wps_ic_media_library_live();
                    wp_send_json_success([
                        'immediate' => true,
                        'html'      => $media_library->compress_details($imageID),
                    ]);
                }
                return 'success-v2';
            }
            error_log('[WPC] singleCompressV4 image=' . $imageID . ' source=' . $source . ' v2 failed — error=' . ($v2_result['error'] ?? 'unknown') . ' (NOT falling through to v1 — endpoint retired)');
            
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            if ($output === 'json') {
                wp_send_json_error(['msg' => 'v2-failed', 'detail' => $v2_result['error'] ?? 'unknown']);
            }
            return 'v2-failed';
        }

        
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing', 'time' => time()], 120);
        set_transient('wps_ic_queue_' . $imageID, ['imageID' => $imageID, 'status' => 'waiting'], 30);

        
        if (!get_post_meta($imageID, 'wpc_old_meta')) {
            $oldMeta = wp_get_attachment_metadata($imageID);
            update_post_meta($imageID, 'wpc_old_meta', $oldMeta);
        }

        
        
        $site_url = get_site_url();
        if (is_ssl()
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || strpos(home_url(), 'https://') === 0
            || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
        ) {
            $site_url = str_replace('http://', 'https://', $site_url);
        }

        
        $settings = get_option(WPS_IC_SETTINGS);
        $request_params = self::buildOptimizeParams($imageID, $site_url, $settings);
        $t_post = microtime(true);
        $response = self::postOptimize($imageID, $request_params, true, 120);
        $postTime = round(microtime(true) - $t_post, 2);
        error_log('[WPC Timing] image=' . $imageID . ' postOptimize=' . $postTime . 's');

        
        if (is_wp_error($response)) {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);

            
            if ($response->get_error_code() === 'wpc_not_authorized') {
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'local-not-authorized']);
                }
                return;
            }

            
            if ($response->get_error_code() === 'wpc_endpoint_gone') {
                error_log('[WPC] ENDPOINT_GONE for image ' . $imageID . ' — ' . $response->get_error_message());
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'endpoint-gone']);
                }
                return;
            }


            $err_data = $response->get_error_data();
            $err_http = is_array($err_data) ? (int) ($err_data['http_code'] ?? 0) : 0;
            $is_transient = ($err_http === 429 || $err_http === 408 || ($err_http >= 500 && $err_http < 600) || $err_http === 0);

            update_post_meta($imageID, '_wpc_last_post_fail', [
                'http_code' => $err_http,
                'at'        => time(),
                'body'      => substr((string) $response->get_error_message(), 0, 200),
            ]);

            if ($allowRetry && $is_transient) {
                $attempts = (int) get_post_meta($imageID, '_wpc_service_retry_attempts', true);
                if ($attempts < 3) {


                    $delay = [5, 30, 120][$attempts];
                    update_post_meta($imageID, '_wpc_service_retry_attempts', $attempts + 1);

                    
                    
                    if (function_exists('wpc_fire_retry_compress_worker')) {
                        wpc_fire_retry_compress_worker($imageID);
                    }
                    
                    if (!wp_next_scheduled('wpc_retry_compress', [$imageID])) {
                        wp_schedule_single_event(time() + $delay, 'wpc_retry_compress', [$imageID]);
                    }
                    

                    error_log('[WPC] image=' . $imageID . ' transient=HTTP' . $err_http . ' retry layers=loopback+cron+admin_init delay=' . $delay . 's attempt=' . ($attempts + 1));
                    if ($output == 'json') {
                        wp_send_json_success(['msg' => 'retry-scheduled', 'retry_in_seconds' => $delay, 'attempt' => $attempts + 1]);
                    }
                    return;
                }
                
                delete_post_meta($imageID, '_wpc_service_retry_attempts');
            }

            error_log('[WPC] Local optimize failed for image ' . $imageID . ': ' . $response->get_error_message());
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'unable-to-contact-api']);
            }
            return;
        }

        $http_code = wp_remote_retrieve_response_code($response);
        if ($http_code < 200 || $http_code >= 300) {
            $body_excerpt = substr((string) wp_remote_retrieve_body($response), 0, 200);
            error_log('[WPC] Local optimize HTTP ' . $http_code . ' for image ' . $imageID . ' body=' . $body_excerpt);
            update_post_meta($imageID, '_wpc_last_post_fail', [
                'http_code' => (int) $http_code,
                'at'        => time(),
                'body'      => $body_excerpt,
            ]);
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);


            $is_transient = ($http_code === 429 || $http_code === 408 || ($http_code >= 500 && $http_code < 600) || $http_code === 0);
            if ($allowRetry && $is_transient) {
                $attempts = (int) get_post_meta($imageID, '_wpc_service_retry_attempts', true);
                if ($attempts < 3) {
                    $delay = [30, 120, 300][$attempts];
                    update_post_meta($imageID, '_wpc_service_retry_attempts', $attempts + 1);
                    if (!wp_next_scheduled('wpc_retry_compress', [$imageID])) {
                        wp_schedule_single_event(time() + $delay, 'wpc_retry_compress', [$imageID]);
                    }
                    error_log('[WPC] image=' . $imageID . ' transient=HTTP' . $http_code . ' retry_in=' . $delay . 's attempt=' . ($attempts + 1));
                    if ($output == 'json') {
                        wp_send_json_success(['msg' => 'retry-scheduled', 'retry_in_seconds' => $delay, 'attempt' => $attempts + 1]);
                    }
                    return;
                }
                
                delete_post_meta($imageID, '_wpc_service_retry_attempts');
            }

            
            if (function_exists('wpc_update_compress_stats')) {
                wpc_update_compress_stats([
                    'event'       => 'failed',
                    'duration_ms' => (int) round((microtime(true) - $t_compress_start) * 1000),
                    'source'      => $source,
                ]);
            }

            if ($output == 'json') {
                wp_send_json_error(['msg' => 'unable-to-contact-api']);
            }
            return;
        }

        
        $post_body = json_decode(wp_remote_retrieve_body($response));
        $ai_meta = null;
        if (!empty($post_body->optimizedResults[0]->ai)) {
            $ai = $post_body->optimizedResults[0]->ai;
            $ai_meta = [
                'ssim'            => $ai->ssim ?? null,
                'quality'         => $ai->quality ?? null,
                'category'        => $ai->category ?? null,
                'perceptualScore' => $ai->perceptualScore ?? null,
                'attempts'        => $ai->attempts ?? null,
            ];
        }


        $dl_files = $post_body->optimizedResults ?? [];
        if (empty($dl_files)) {
            
            $options = get_option(WPS_IC_OPTIONS);
            $download_url = WPC_IC_LOCAL_DOWNLOAD . '?imageID=' . $imageID . '&apikey=' . ($options['api_key'] ?? '');
            $dl_response = wp_remote_get($download_url, ['timeout' => 30, 'sslverify' => false]);
            if (is_wp_error($dl_response) || wp_remote_retrieve_response_code($dl_response) !== 200) {
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'unable-to-contact-api']);
                }
                return;
            }
            $dl_body = json_decode(wp_remote_retrieve_body($dl_response));
            $dl_files = $dl_body->files ?? [];
        }

        if (empty($dl_files)) {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'unable-to-contact-api']);
            }
            return;
        }

        
        error_log('[WPC Timing] image=' . $imageID . ' files_to_download=' . count($dl_files));

        
        $_orig_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : false;
        $_scaled_path = get_attached_file($imageID);
        $_orig_size = ($_orig_path && file_exists($_orig_path)) ? filesize($_orig_path) : 0;
        $_scaled_size = ($_scaled_path && file_exists($_scaled_path)) ? filesize($_scaled_path) : 0;
        $disk_baseline = max($_orig_size, $_scaled_size);

        
        $original_url = wp_get_attachment_url($imageID);
        $basename = basename($original_url);
        $relative_path = get_post_meta($imageID, '_wp_attached_file', true);
        $upload_dir = wp_upload_dir();
        $absolute_path = $upload_dir['basedir'] . '/' . $relative_path;
        $finalImagePath = str_replace($basename, '', $absolute_path);

        $stats = [];
        $stats['original']['original']['size'] = 0;
        $stats['original']['compressed']['size'] = 0;
        $stats['original']['compressed']['thumbs'] = 0;
        $done = false;
        $errors = false;
        $skipped_variants = [];


        $downloads = [];
        $inline_count = 0;

        $variant_timings = []; 

        
        $log_until = (int) get_option('wpc_inline_log_until', 0);
        if ($log_until === 0) {
            $log_until = time() + 3600;
            update_option('wpc_inline_log_until', $log_until, false);
        }
        $should_log = (time() < $log_until);


        $write_atomic_inline = function ($path, $data, $imageID, $size_label, $fmt) {
            $expected = strlen((string) $data);
            $pre = file_exists($path) ? @filesize($path) : 0;
            if (empty($data)) return ['ok' => false, 'pre' => $pre, 'post' => $pre, 'expected' => 0];
            $tmp = $path . '.wpc_tmp_' . wp_generate_password(8, false);
            if (@file_put_contents($tmp, $data) === false) {
                @unlink($tmp);
                return ['ok' => false, 'pre' => $pre, 'post' => $pre, 'expected' => $expected];
            }
            if (!@rename($tmp, $path)) {
                @unlink($tmp);
                return ['ok' => false, 'pre' => $pre, 'post' => $pre, 'expected' => $expected];
            }
            @chmod($path, 0644);
            clearstatcache(true, $path);
            $post = @filesize($path);


            if ($post !== $expected) {
                error_log(sprintf(
                    '[WPC WriteVerify] image=%d size=%s fmt=%s path=%s expected=%d actual=%d pre_existing=%d — MISMATCH',
                    (int) $imageID, $size_label, $fmt, basename($path), $expected, $post, $pre
                ));
            }
            return ['ok' => true, 'pre' => $pre, 'post' => $post, 'expected' => $expected];
        };

        foreach ($dl_files as $value) {
            $file_url   = $value->url ?? '';
            $file_name  = $value->fileName ?? '';


            if (empty($file_url) && empty($file_name)) continue;

            $stats['original']['original']['size'] += $value->originalSize ?? 0;
            if (!empty($value->optimizedSize) && $value->optimizedSize > 0) {
                $stats['original']['compressed']['size'] += $value->optimizedSize;
            }
            $stats['original']['compressed']['thumbs'] += 1;


            $primary_base    = !empty($file_name) ? basename($file_name) : basename($file_url);
            $primary_ext     = strtolower(pathinfo($primary_base, PATHINFO_EXTENSION));
            $primary_fmt     = ($primary_ext === 'avif') ? 'avif' : (($primary_ext === 'webp') ? 'webp' : 'jpeg');
            $size_label      = $value->sizeLabel ?? $value->label ?? pathinfo($primary_base, PATHINFO_FILENAME);
            
            $cdn_dir         = !empty($file_url) ? dirname($file_url) : '';

            
            
            $formats = [$primary_fmt => ['filename' => $primary_base, 'url' => $file_url]];
            if (!empty($value->webpInfo->fileName) && !isset($formats['webp'])) {
                $webp_url = !empty($cdn_dir) ? ($cdn_dir . '/' . $value->webpInfo->fileName) : '';
                $formats['webp'] = ['filename' => $value->webpInfo->fileName, 'url' => $webp_url];
            }
            if (!empty($value->avifInfo->fileName) && !isset($formats['avif'])) {
                $avif_url = !empty($cdn_dir) ? ($cdn_dir . '/' . $value->avifInfo->fileName) : '';
                $formats['avif'] = ['filename' => $value->avifInfo->fileName, 'url' => $avif_url];
            }

            foreach ($formats as $fmt => $info) {
                $local_path = $finalImagePath . $info['filename'];


                if (!empty($value->skip->{$fmt})) {
                    if ($should_log) error_log("[wpc] variant {$size_label} fmt={$fmt} path=skip reason=" . (string) $value->skip->{$fmt});
                    continue;
                }

                


                $inline_b64 = null;
                if ($fmt === $primary_fmt && !empty($value->bytes)) {
                    $inline_b64 = $value->bytes;
                } elseif (!empty($value->inlineBytes->{$fmt})) {
                    $inline_b64 = $value->inlineBytes->{$fmt};
                }
                if (!empty($inline_b64)) {
                    
                    $t_decode_start = microtime(true);
                    $bytes = base64_decode($inline_b64, true);
                    $t_decode_end = microtime(true);
                    if ($bytes !== false && strlen($bytes) > 0) {
                        


                        if (!wpc_is_valid_image_bytes($bytes, $fmt, $imageID, 'phase_a_inline')) {
                            $variant_timings[] = ['size' => $size_label, 'fmt' => $fmt, 'path' => 'inline-corrupt-rejected', 'bytes' => strlen($bytes), 'decode_ms' => (int) round(($t_decode_end - $t_decode_start) * 1000), 'write_ms' => 0];
                            continue;
                        }
                        
                        $existing_size = file_exists($local_path) ? filesize($local_path) : 0;
                        if ($existing_size > 0 && strlen($bytes) >= $existing_size) {
                            $variant_timings[] = ['size' => $size_label, 'fmt' => $fmt, 'path' => 'inline-skip-regression', 'bytes' => strlen($bytes), 'decode_ms' => (int) round(($t_decode_end - $t_decode_start) * 1000), 'write_ms' => 0];
                            if ($should_log) error_log("[wpc] variant {$size_label} fmt={$fmt} path=inline-skip reason=size_regression bytes=" . strlen($bytes) . " vs existing=" . $existing_size);
                            continue;
                        }
                        $t_write_start = microtime(true);
                        $write_result = $write_atomic_inline($local_path, $bytes, $imageID, $size_label, $fmt);
                        $t_write_end = microtime(true);
                        $decode_ms = (int) round(($t_decode_end - $t_decode_start) * 1000);
                        $write_ms  = (int) round(($t_write_end - $t_write_start) * 1000);
                        $write_ok = is_array($write_result) ? $write_result['ok'] : false;
                        $disk_post = is_array($write_result) ? (int) $write_result['post'] : 0;
                        $disk_pre  = is_array($write_result) ? (int) $write_result['pre'] : 0;
                        if ($write_ok) {
                            $inline_count++;
                            $variant_timings[] = ['size' => $size_label, 'fmt' => $fmt, 'path' => 'inline', 'bytes' => strlen($bytes), 'filename' => $info['filename'], 'target' => $local_path, 'disk_pre' => $disk_pre, 'disk_post' => $disk_post, 'decode_ms' => $decode_ms, 'write_ms' => $write_ms];
                            if ($should_log) error_log("[wpc] variant {$size_label} fmt={$fmt} path=inline bytes=" . strlen($bytes) . " disk_pre=" . $disk_pre . " disk_post=" . $disk_post . " file=" . $info['filename'] . " decode=" . $decode_ms . "ms write=" . $write_ms . "ms");
                            continue;
                        }
                        $variant_timings[] = ['size' => $size_label, 'fmt' => $fmt, 'path' => 'inline-write-fail', 'bytes' => strlen($bytes), 'filename' => $info['filename'], 'target' => $local_path, 'disk_pre' => $disk_pre, 'disk_post' => $disk_post, 'decode_ms' => $decode_ms, 'write_ms' => $write_ms];
                        error_log("[WPC InlineWriteFail] image=" . $imageID . " size=" . $size_label . " fmt=" . $fmt . " file=" . $info['filename'] . " target=" . $local_path . " bytes=" . strlen($bytes) . " disk_pre=" . $disk_pre . " — atomic write returned false");

                    } else {
                        $variant_timings[] = ['size' => $size_label, 'fmt' => $fmt, 'path' => 'inline-decode-fail', 'bytes' => 0, 'decode_ms' => 0, 'write_ms' => 0];
                        if ($should_log) error_log("[wpc] variant {$size_label} fmt={$fmt} path=inline-decode-fail — falling through to URL");
                    }
                }

                
                if (file_exists($local_path) || $fmt === 'webp' || $fmt === 'avif') {
                    $downloads[] = ['url' => $info['url'], 'path' => $local_path, 'basename' => $info['filename']];
                    $variant_timings[] = ['size' => $size_label, 'fmt' => $fmt, 'path' => 'url-queued', 'bytes' => 0, 'decode_ms' => 0, 'write_ms' => 0];
                    if ($should_log) error_log("[wpc] variant {$size_label} fmt={$fmt} path=url queued url=" . $info['url']);
                }
            }
        }

        if ($inline_count > 0 || !empty($downloads)) {
            error_log('[WPC Timing] image=' . $imageID . ' inline_writes=' . $inline_count . ' url_downloads_queued=' . count($downloads));
        }


        

        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $post_timing = get_post_meta($imageID, '_wpc_last_post_timing', true);
            $api_options = get_option(WPS_IC_OPTIONS);
            $apikey      = !empty($api_options['api_key']) ? $api_options['api_key'] : '';
            $apikey_prefix = $apikey ? substr($apikey, 0, 8) : '-';
            $phase_a_total_ms = (int) round((microtime(true) - $t_compress_start) * 1000);
            $service_ttfb_ms       = is_array($post_timing) ? (int) ($post_timing['ttfb_ms'] ?? 0) : 0;
            $service_total_ms      = is_array($post_timing) ? (int) ($post_timing['total_ms'] ?? 0) : 0;
            $service_resp_bytes    = is_array($post_timing) ? (int) ($post_timing['size_bytes'] ?? 0) : 0;

            $service_multer_ms     = is_array($post_timing) ? (int) ($post_timing['multer_ms'] ?? 0) : 0;
            $service_phase1_ms     = is_array($post_timing) ? (int) ($post_timing['phase1_ms'] ?? 0) : 0;
            $service_db_writes_ms  = is_array($post_timing) ? (int) ($post_timing['db_writes_ms'] ?? 0) : 0;
            $plugin_overhead_ms    = max(0, $phase_a_total_ms - $service_total_ms);
            
            $input_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : get_attached_file($imageID);
            $input_size = ($input_path && file_exists($input_path)) ? filesize($input_path) : 0;
            
            $inline_paths   = array_filter($variant_timings, function($v) { return $v['path'] === 'inline'; });
            $url_paths      = array_filter($variant_timings, function($v) { return $v['path'] === 'url-queued'; });
            $skip_paths     = array_filter($variant_timings, function($v) { return strpos($v['path'], 'skip') !== false; });
            $total_inline_bytes = array_sum(array_column($inline_paths, 'bytes'));
            $total_decode_ms    = array_sum(array_column($variant_timings, 'decode_ms'));
            $total_write_ms     = array_sum(array_column($variant_timings, 'write_ms'));

            error_log(sprintf(
                '[WPC DETAILED_TIMING] imageID=%d apikey=%s... input_bytes=%d variants=%d (inline=%d url=%d skip=%d) '
                . 'service_ttfb=%dms service_total=%dms service_resp_bytes=%d '
                . 'service_multer=%dms service_phase1=%dms service_db_writes=%dms '
                . 'plugin_overhead=%dms phase_a_total=%dms inline_bytes_written=%d decode_sum=%dms write_sum=%dms source=%s',
                $imageID,
                $apikey_prefix,
                $input_size,
                count($variant_timings),
                count($inline_paths),
                count($url_paths),
                count($skip_paths),
                $service_ttfb_ms,
                $service_total_ms,
                $service_resp_bytes,
                $service_multer_ms,
                $service_phase1_ms,
                $service_db_writes_ms,
                $plugin_overhead_ms,
                $phase_a_total_ms,
                $total_inline_bytes,
                $total_decode_ms,
                $total_write_ms,
                $source
            ));


            foreach ($variant_timings as $vt) {
                error_log(sprintf(
                    '[WPC VARIANT] imageID=%d size=%s fmt=%s path=%s file=%s bytes=%d pre=%d post=%d target=%s decode=%dms write=%dms',
                    $imageID, $vt['size'], $vt['fmt'], $vt['path'],
                    $vt['filename'] ?? '-',
                    $vt['bytes'],
                    $vt['disk_pre'] ?? 0,
                    $vt['disk_post'] ?? 0,
                    $vt['target'] ?? '-',
                    $vt['decode_ms'], $vt['write_ms']
                ));
            }
        }

        
        delete_post_meta($imageID, '_wpc_last_post_timing');

        
        
        $service_skipped = [];
        if (!empty($post_body->skippedFormats) && is_array($post_body->skippedFormats)) {
            foreach ($post_body->skippedFormats as $skip) {
                $sl  = $skip->sizeLabel ?? '';
                $fmt = strtolower($skip->format ?? '');
                if ($sl && $fmt) {
                    if (!isset($service_skipped[$sl])) $service_skipped[$sl] = [];
                    $service_skipped[$sl][] = $fmt;
                }
            }
        }


        $variants = [];
        foreach ($dl_files as $variant) {
            $label = $variant->sizeLabel ?? $variant->label ?? basename($variant->fileName ?? $variant->url ?? '', '.jpg');
            $orig  = (int) ($variant->originalSize ?? 0);
            $opt   = (int) ($variant->optimizedSize ?? 0);
            $entry = [
                'url'          => $variant->url ?? '',
                'originalSize' => $orig,
                'size'         => $opt,
                'savings'      => $variant->savingsPercent ?? 0,
                'skipped'      => ($orig > 0 && $opt > 0 && $opt >= $orig),
                'local'        => false, 
            ];
            if (!empty($service_skipped[$label])) $entry['skipped_formats'] = $service_skipped[$label];
            $variants[$label] = $entry;
        }


        global $wpdb;
        $phaseA_lock_name = 'wpc_bg_meta_' . (int) $imageID;
        
        


        $instr_t_lock_start = microtime(true);
        $phaseA_locked = wpc_worker_lock($phaseA_lock_name);
        $instr_lock_acq_ms = (int) round((microtime(true) - $instr_t_lock_start) * 1000);
        $instr_t_crit_start = microtime(true);
        $instr_merge_write_ms = 0;
        try {
            
            $existing_variants = get_post_meta($imageID, 'ic_local_variants', true);
            if (is_array($existing_variants) && !empty($existing_variants)) {
                foreach ($existing_variants as $existing_key => $existing_entry) {


                    if (!empty($existing_entry['bg_upgraded'])) {
                        $variants[$existing_key] = $existing_entry;
                        continue;
                    }
                    
                    
                    if (!isset($variants[$existing_key])) {
                        $variants[$existing_key] = $existing_entry;
                    }
                    
                }
            }
            $instr_t_write_start = microtime(true);
            update_post_meta($imageID, 'ic_local_variants', $variants);
            $instr_merge_write_ms = (int) round((microtime(true) - $instr_t_write_start) * 1000);
        } finally {
            if ($phaseA_locked) {
                wpc_worker_unlock($phaseA_lock_name);
            }
        }
        $instr_crit_ms = (int) round((microtime(true) - $instr_t_crit_start) * 1000);


        $instr_t_status_start = microtime(true);
        update_post_meta($imageID, 'wpc_images_compressed', 'true');
        update_post_meta($imageID, 'ic_status', 'compressed');
        update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
        update_post_meta($imageID, 'ic_stats', $stats);
        if (!empty($ai_meta)) update_post_meta($imageID, 'ic_ai_meta', $ai_meta);
        $instr_status_writes_ms = (int) round((microtime(true) - $instr_t_status_start) * 1000);


        error_log(sprintf(
            '[WPC PhaseA INSTR] imageID=%d lock_acq_ms=%d crit_ms=%d merge_write_ms=%d status_writes_ms=%d variant_count=%d existing_count=%d',
            $imageID,
            $instr_lock_acq_ms,
            $instr_crit_ms,
            $instr_merge_write_ms,
            $instr_status_writes_ms,
            count($variants),
            is_array($existing_variants) ? count($existing_variants) : 0
        ));

        
        
        $best = wpc_compute_best_savings($variants, $imageID);
        if ($best['pct'] > 0 && $best['orig'] > 0) {
            update_post_meta($imageID, 'ic_savings',          round($best['pct'], 1));
            update_post_meta($imageID, 'ic_savings_format',   $best['format']);
            update_post_meta($imageID, 'ic_savings_bytes',    $best['orig'] - $best['opt']);
            update_post_meta($imageID, 'ic_savings_baseline', $best['orig']);
        }

        
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wps_ic_queue_' . $imageID);
        delete_post_meta($imageID, '_wpc_optimize_attempts');
        delete_transient('wpc_queued_' . $imageID);
        delete_transient('wpc_failed_' . $imageID);
        if (function_exists('wp_cache_delete')) wp_cache_delete('wpc_queued_' . $imageID, 'wpc');
        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'compressed'], 60);

        
        if (function_exists('wpc_update_compress_stats')) {
            wpc_update_compress_stats([
                'event'       => 'success',
                'duration_ms' => (int) round((microtime(true) - $t_compress_start) * 1000),
                'source'      => $source,
            ]);
        }

        if (function_exists('wpc_invalidate_local_cache')) wpc_invalidate_local_cache();

        
        if (!empty($downloads)) {
            update_post_meta($imageID, '_wpc_pending_downloads', [
                'downloads'       => $downloads,
                'service_skipped' => $service_skipped,
                'scheduled_at'    => time(),
            ]);
            
            if (function_exists('wpc_fire_download_worker')) wpc_fire_download_worker($imageID);
            
            if (!wp_next_scheduled('wpc_download_variants', [$imageID])) {
                wp_schedule_single_event(time() + 30, 'wpc_download_variants', [$imageID]);
            }
            
        }

        if ($output == 'json') {
            wp_send_json_success();
        }
    }


    public function downloadVariants($imageID, $downloads, $service_skipped = [])
    {
        $done = false; $errors = false; $skipped_variants = [];
        if (empty($downloads)) return compact('done', 'errors', 'skipped_variants');


        @set_time_limit(60);
        wp_raise_memory_limit('image');

        $t_dl = microtime(true);

        $write_atomic = function ($path, $data) {
            $tmp = $path . '.wpc_tmp_' . wp_generate_password(8, false);
            if (file_put_contents($tmp, $data) === false) { @unlink($tmp); return false; }
            if (!@rename($tmp, $path)) { @unlink($tmp); return false; }
            @chmod($path, 0644);
            return true;
        };

        if (function_exists('curl_multi_init')) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($downloads as $i => $dl) {
                $ch = curl_init($dl['url']);
                $dl_opts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 30,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_FOLLOWLOCATION => true,
                ];
                
                if (defined('WPC_STAGING') && WPC_STAGING) {
                    $dl_opts[CURLOPT_SSL_VERIFYHOST] = 0;
                }
                curl_setopt_array($ch, $dl_opts);
                curl_multi_add_handle($mh, $ch);
                $handles[$i] = $ch;
            }
            do {
                $status = curl_multi_exec($mh, $active);
                if ($active) curl_multi_select($mh, 1);
            } while ($active && $status === CURLM_OK);

            foreach ($handles as $i => $ch) {
                $file_data = curl_multi_getcontent($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                if ($http_code < 200 || $http_code >= 300 || empty($file_data)) { $errors = true; continue; }

                $dl = $downloads[$i];


                $dl_fmt = 'jpeg';
                if (strpos($dl['basename'], '.avif') !== false) $dl_fmt = 'avif';
                elseif (strpos($dl['basename'], '.webp') !== false) $dl_fmt = 'webp';
                elseif (strpos($dl['basename'], '.png') !== false) $dl_fmt = 'png';
                if (!wpc_is_valid_image_bytes($file_data, $dl_fmt, $imageID, 'phase_b_download', ['size_label' => $dl['basename'] ?? '', 'url' => $dl['url'] ?? ''])) {
                    $errors = true; continue;
                }

                
                $original_size  = file_exists($dl['path']) ? filesize($dl['path']) : 0;
                $optimized_size = strlen($file_data);
                if ($original_size > 0 && $optimized_size >= $original_size) {
                    $skipped_variants[] = $dl['basename'];
                    continue;
                }
                if ($write_atomic($dl['path'], $file_data)) $done = true;
                else $errors = true;
            }
            curl_multi_close($mh);
        } else {
            
            foreach ($downloads as $dl) {
                $resp = wp_remote_get($dl['url'], ['timeout' => 20, 'sslverify' => false]);
                if (is_wp_error($resp)) { $errors = true; continue; }
                $file_data = wp_remote_retrieve_body($resp);
                if (empty($file_data)) { $errors = true; continue; }
                
                $dl_fmt = 'jpeg';
                if (strpos($dl['basename'], '.avif') !== false) $dl_fmt = 'avif';
                elseif (strpos($dl['basename'], '.webp') !== false) $dl_fmt = 'webp';
                elseif (strpos($dl['basename'], '.png') !== false) $dl_fmt = 'png';
                if (!wpc_is_valid_image_bytes($file_data, $dl_fmt, $imageID, 'phase_b_seq_download', ['size_label' => $dl['basename'] ?? '', 'url' => $dl['url'] ?? ''])) {
                    $errors = true; continue;
                }
                $original_size = file_exists($dl['path']) ? filesize($dl['path']) : 0;
                if ($original_size > 0 && strlen($file_data) >= $original_size) {
                    $skipped_variants[] = $dl['basename']; continue;
                }
                if ($write_atomic($dl['path'], $file_data)) $done = true;
                else $errors = true;
            }
        }


        $success_count = 0;
        foreach ($downloads as $dl) {
            if (file_exists($dl['path']) && filesize($dl['path']) > 0) $success_count++;
        }
        $used_parallel = function_exists('curl_multi_init');
        if ($used_parallel && $success_count === 0 && !empty($downloads)) {
            error_log('[WPC Download] image=' . $imageID . ' parallel totally failed — falling back to sequential');
            $done = false; $errors = false; $skipped_variants = [];
            foreach ($downloads as $dl) {
                $resp = wp_remote_get($dl['url'], ['timeout' => 20, 'sslverify' => false]);
                if (is_wp_error($resp)) { $errors = true; continue; }
                $file_data = wp_remote_retrieve_body($resp);
                if (empty($file_data)) { $errors = true; continue; }
                
                $dl_fmt = 'jpeg';
                if (strpos($dl['basename'], '.avif') !== false) $dl_fmt = 'avif';
                elseif (strpos($dl['basename'], '.webp') !== false) $dl_fmt = 'webp';
                elseif (strpos($dl['basename'], '.png') !== false) $dl_fmt = 'png';
                if (!wpc_is_valid_image_bytes($file_data, $dl_fmt, $imageID, 'phase_b_seq_download', ['size_label' => $dl['basename'] ?? '', 'url' => $dl['url'] ?? ''])) {
                    $errors = true; continue;
                }
                $original_size = file_exists($dl['path']) ? filesize($dl['path']) : 0;
                if ($original_size > 0 && strlen($file_data) >= $original_size) {
                    $skipped_variants[] = $dl['basename']; continue;
                }
                if ($write_atomic($dl['path'], $file_data)) $done = true;
                else $errors = true;
            }
        }


        global $wpdb;
        $dl_lock_name = 'wpc_bg_meta_' . (int) $imageID;
        $dl_locked = wpc_worker_lock($dl_lock_name);
        try {
            
            
            $variants = get_post_meta($imageID, 'ic_local_variants', true);
            if (is_array($variants)) {
                foreach ($downloads as $dl) {
                    $label = pathinfo($dl['basename'], PATHINFO_FILENAME);
                    if (isset($variants[$label])) $variants[$label]['local'] = file_exists($dl['path']);
                }
                foreach ($skipped_variants as $sv) {
                    $label = pathinfo($sv, PATHINFO_FILENAME);
                    if (isset($variants[$label])) $variants[$label]['skipped'] = true;
                }
                update_post_meta($imageID, 'ic_local_variants', $variants);
            }
        } finally {
            if ($dl_locked) {
                wpc_worker_unlock($dl_lock_name);
            }
        }

        error_log('[WPC Download] image=' . $imageID . ' duration=' . round(microtime(true) - $t_dl, 2) . 's files=' . count($downloads) . ' done=' . ($done ? 'Y' : 'N') . ' errors=' . ($errors ? 'Y' : 'N'));
        return compact('done', 'errors', 'skipped_variants');
    }

    public function generate_webp($arg, $type = 'click')
    {
        global $wpc_filesystem;

        $upload_dir = wp_upload_dir();
        $imageID = $arg;
        $return = [];
        $compressed = [];
        $extension = '';
        $stats = [];

        $image_url_full = wp_get_attachment_image_src($imageID, 'full');
        $image_url_full = $image_url_full[0];
        $image_filename = basename($image_url_full);

        if (strpos($image_filename, '.jpg') !== false) {
            $extension = 'jpg';
        } elseif (strpos($image_filename, '.jpeg') !== false) {
            $extension = 'jpeg';
        } elseif (strpos($image_filename, '.gif') !== false) {
            $extension = 'gif';
        } elseif (strpos($image_filename, '.png') !== false) {
            $extension = 'png';
        }

        foreach ($this->webp_sizes as $i => $size) {
            if ($size == 'full') {
                $image = wp_get_attachment_image_src($imageID, $size);
                if ($image) {
                    $image_url = $image[0];
                }
            } else {
                $image = wp_get_attachment_image_src($imageID, $size);
                if ($image) {
                    $image_url = $image[0];
                }
            }

            if (empty($image_url)) {
                continue;
            }

            if (!isset($image['path']) && !empty($image)) {
                $image['path'] = $image;
            }

            $image['path'] = str_replace($upload_dir['baseurl'] . '/', '', $image[0]);
            $image['path'] = str_replace('./', '', $image['path']);

            


            $file_path = get_attached_file($imageID);
            $file_basename = basename($image[0]);

            
            $headers = ['timeout' => 300, 'httpversion' => '1.0', 'blocking' => true,];

            
            $exif = exif_imagetype($file_path);
            $mime = image_type_to_mime_type($exif);

            $file_location = WPS_IC_UPLOADS_DIR . '/' . $image['path'];

            
            $file_content = $wpc_filesystem->get_contents($file_path);

            $post_fields = ['action' => 'compress', 'imageID' => $imageID, 'filename' => $file_basename, 'apikey' => self::$apiParams['apikey'], 'key' => self::$apiParams['apikey'], 'image' => $image[0], 'url' => $image[0], 'exif' => $exif, 'mime' => $mime, 'content' => base64_encode($file_content), 'quality' => self::$apiParams['quality'], 'width' => '1', 'retina' => 'false', 'webp' => 'true'];

            if (!empty($size)) {
                if ($size == 'full') {
                    $post_fields['width'] = '1';
                } else {
                    if (empty($image['width'])) {
                        $post_fields['width'] = '1';
                    } else {
                        $post_fields['width'] = $image['width'];
                    }
                }
            }


            $webp_file_location = preg_replace('/\.' . preg_quote($extension, '/') . '$/', '.webp', $file_location);
            $call = wp_remote_post(self::$apiURL, ['timeout' => 300, 'method' => 'POST', 'headers' => $headers, 'sslverify' => false, 'body' => $post_fields, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($call) == 200) {
                $body = wp_remote_retrieve_body($call);
                
                if (!empty($body) && wpc_is_valid_image_bytes($body, 'webp', isset($imageID) ? $imageID : 0, 'legacy_webp_convert')) {
                    file_put_contents($webp_file_location, $body);
                    clearstatcache();

                    $stats[$size . '-webp']['compressed']['size'] = filesize($webp_file_location);
                    $compressed[$size . '-webp'] = $webp_file_location;
                }
            }
        }

        $return['stats'] = $stats;
        $return['compressed'] = $compressed;

        $stats = get_post_meta($imageID, 'ic_stats', true);
        $stats = array_merge($stats, $return['stats']);
        update_post_meta($imageID, 'ic_stats', $stats);

        if ($type == 'click') {
            $compressed = get_post_meta($imageID, 'ic_compressed_images', true);
            $compressed = array_merge($compressed, $return['compressed']);
            update_post_meta($imageID, 'ic_compressed_images', $compressed);
        }

        return $return;
    }

    public function restoreV4($imageID)
    {
        $t_total = microtime(true);
        error_log('[WPC Restore] START image=' . $imageID);


        if (get_transient('wpc_restoring_' . $imageID)) {
            error_log('[WPC Restore] RE-ENTRANCY skip image=' . $imageID . ' — a restore is already in flight (wpc_restoring_ set); standing down');
            return false;
        }


        set_transient('wpc_v2_callbacks_blocked_' . $imageID, time(), 600);


        set_transient('wpc_restoring_' . $imageID, 1, 60);
        delete_transient('wpc_v2_pending_' . $imageID);

        if (!function_exists('download_url')) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        wp_raise_memory_limit('image');

        $restored = false;
        $restore_source = 'unknown';
        $backupBase = WP_CONTENT_DIR . '/wpc-backups/';
        $uploadDir = wp_upload_dir()['basedir'];

        
        $backupMode = get_post_meta($imageID, 'wpc_backup_mode', true);
        if ($backupMode === 'off') {
            error_log('[WPC Restore] BLOCKED image=' . $imageID . ' — backup mode was off, compression is permanent');
            return false;
        }

        
        $skipped = get_post_meta($imageID, 'ic_skipped', true);
        if (!empty($skipped) && $skipped == 'true') {
            $this->cleanRestoreMeta($imageID);
            error_log('[WPC Restore] DONE image=' . $imageID . ' method=skipped time=' . round(microtime(true) - $t_total, 2) . 's');
            return true;
        }

        
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);

        
        $backupRel = get_post_meta($imageID, 'wpc_backup_path', true);
        if ($backupRel && file_exists($backupBase . $backupRel)) {
            $restored = $this->restore_from_new_backup($imageID, $backupBase, $uploadDir);
            if ($restored) {
                $restore_source = 'cloud_bkp';
                error_log('[WPC Restore] Restored from /wpc-backups/ image=' . $imageID);
            }
        }

        
        if (!$restored) {
            $legacyBackup = get_post_meta($imageID, 'ic_backup_images', true);
            if (!empty($legacyBackup) && is_array($legacyBackup)) {
                $legacyPath = $legacyBackup['original'] ?? $legacyBackup['full'] ?? '';
                if ($legacyPath && file_exists($legacyPath) && filesize($legacyPath) > 0) {
                    $scaledPath = get_attached_file($imageID);
                    $unscaledPath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaledPath;
                    $targetPath = ($unscaledPath && $unscaledPath !== $scaledPath) ? $unscaledPath : $scaledPath;

                    @copy($legacyPath, $targetPath);
                    @unlink($legacyPath);

                    
                    update_post_meta($imageID, '_wpc_pending_thumb_regen', [
                        'regen_source' => $targetPath,
                        'backup_mode'  => 'legacy',
                        'scheduled_at' => time(),
                    ]);

                    $restored = true;
                    $restore_source = 'local_bkp';
                    error_log('[WPC Restore] Restored from legacy backup image=' . $imageID . ' size=' . filesize($targetPath) . ' (thumb regen deferred)');
                }
                delete_post_meta($imageID, 'ic_backup_images');
                delete_post_meta($imageID, 'ic_compressed_images');
                delete_post_meta($imageID, 'ic_compressed_thumbs');
            }
        }

        
        if (!$restored) {
            $restored = $this->restore_from_bkp_files($imageID);
            if ($restored) {
                $restore_source = 'local_bkp';
                error_log('[WPC Restore] Restored from _bkp files image=' . $imageID);
            }
        }

        
        if (!$restored) {
            $restored = $this->restore_from_cloud($imageID);
            if ($restored) {
                $restore_source = 'service';
                error_log('[WPC Restore] Restored from cloud image=' . $imageID);
            }
        }

        
        if (!$restored) {
            $restored = $this->regenerate_from_unscaled($imageID);
            if ($restored) {
                $restore_source = 'service';
                error_log('[WPC Restore] Restored via regeneration image=' . $imageID);
            }
        }

        
        

        if ($restored) {
            $this->cleanup_backups($imageID, $backupBase, $uploadDir);
        } else {
            error_log('[WPC Restore] BACKUP_RETAINED image=' . $imageID . ' — restore failed verification; backups NOT deleted (so user can retry or manual-recover)');
        }


        $this->cleanRestoreMeta($imageID);


        if (!$restored) {
            update_post_meta($imageID, 'ic_status', 'restore_failed');
            update_post_meta($imageID, '_wpc_restore_failed_at', time());
        }

        clearstatcache(true);
        $finalFile = get_attached_file($imageID);
        $finalSize = ($finalFile && file_exists($finalFile)) ? filesize($finalFile) : 'MISSING';
        $duration_ms = (int) round((microtime(true) - $t_total) * 1000);
        error_log('[WPC Restore] DONE image=' . $imageID . ' restored=' . ($restored ? 'Y' : 'N') . ' source=' . $restore_source . ' file_size=' . $finalSize . ' time=' . round($duration_ms / 1000, 2) . 's');

        if (function_exists('wpc_update_restore_stats')) {
            wpc_update_restore_stats([
                'event'       => $restored ? 'success' : 'failed',
                'duration_ms' => $duration_ms,
                'source'      => $restore_source,
            ]);
        }


        if (get_post_meta($imageID, '_wpc_pending_thumb_regen', true)) {
            $bulk = get_option('wps_ic_bulk_process');
            $is_bulk_restore = is_array($bulk) && (($bulk['status'] ?? '') === 'restoring');
            if (!$is_bulk_restore) {
                if (function_exists('wpc_fire_regen_thumbs_worker')) wpc_fire_regen_thumbs_worker($imageID);
                if (!wp_next_scheduled('wpc_regen_thumbs', [$imageID])) {
                    wp_schedule_single_event(time() + 30, 'wpc_regen_thumbs', [$imageID]);
                }
            }
        }


        if (function_exists('wpc_v2_purge_html_for_attachment')) {
            wpc_v2_purge_html_for_attachment((int) $imageID, 'restoreV4');
        }

        
        


        if (function_exists('wpc_v2_lazy_cdn_clear_dedup_transients')) {
            wpc_v2_lazy_cdn_clear_dedup_transients();
        }


        if (apply_filters('wpc_restore_purge_cdn', false, (int) $imageID)
            && function_exists('wpc_purge_cdn_urls')) {
            wpc_purge_cdn_urls((int) $imageID);
            if (function_exists('wpc_diagnostic_log')) {
                wpc_diagnostic_log('RESTORE_CDN_PURGE', 'fired for image_id=' . (int) $imageID);
            }
        }

        return $restored;
    }

    


    private function verify_restore_atomic($src, $dest, $imageID, $size_label = 'unknown') {
        if (!is_string($src) || !is_string($dest) || $src === '' || $dest === '') return false;
        if (!file_exists($src) || !is_readable($src)) {
            error_log('[WPC Restore Verify] image=' . $imageID . ' size=' . $size_label . ' SRC_INVALID src=' . $src);
            return false;
        }
        $src_size = filesize($src);
        if ($src_size === false || $src_size <= 0) {
            error_log('[WPC Restore Verify] image=' . $imageID . ' size=' . $size_label . ' SRC_EMPTY src=' . $src);
            return false;
        }
        $src_hash = hash_file('sha256', $src);
        if (!$src_hash) {
            error_log('[WPC Restore Verify] image=' . $imageID . ' size=' . $size_label . ' SRC_HASH_FAIL src=' . $src);
            return false;
        }

        $tries = 0;
        while ($tries < 3) {
            $tries++;
            $tmp = $dest . '.wpc_restore_tmp_' . wp_generate_password(8, false);

            if (!@copy($src, $tmp)) {
                $err = error_get_last();
                error_log('[WPC Restore Verify] image=' . $imageID . ' size=' . $size_label . ' COPY_FAIL try=' . $tries . ' err=' . ($err['message'] ?? 'n/a'));
                @unlink($tmp);
                usleep(50000);
                continue;
            }
            clearstatcache(true, $tmp);
            $tmp_size = filesize($tmp);
            if ($tmp_size !== $src_size) {
                error_log('[WPC Restore Verify] image=' . $imageID . ' size=' . $size_label . ' SIZE_MISMATCH try=' . $tries . ' src=' . $src_size . ' tmp=' . var_export($tmp_size, true));
                @unlink($tmp);
                usleep(50000);
                continue;
            }
            $tmp_hash = hash_file('sha256', $tmp);
            if ($tmp_hash !== $src_hash) {
                error_log('[WPC Restore Verify] image=' . $imageID . ' size=' . $size_label . ' SHA_MISMATCH try=' . $tries . ' src=' . substr($src_hash, 0, 16) . ' tmp=' . substr((string) $tmp_hash, 0, 16));
                @unlink($tmp);
                usleep(50000);
                continue;
            }
            if (!@rename($tmp, $dest)) {
                $err = error_get_last();
                error_log('[WPC Restore Verify] image=' . $imageID . ' size=' . $size_label . ' RENAME_FAIL try=' . $tries . ' err=' . ($err['message'] ?? 'n/a'));
                @unlink($tmp);
                usleep(50000);
                continue;
            }
            @chmod($dest, 0644);
            if ($tries > 1) {
                error_log('[WPC Restore Verify] image=' . $imageID . ' size=' . $size_label . ' OK_RETRY try=' . $tries . ' bytes=' . $src_size);
            }
            return true;
        }
        error_log('[WPC Restore Verify] image=' . $imageID . ' size=' . $size_label . ' FINAL_FAIL bytes_expected=' . $src_size);
        return false;
    }

    private function restore_from_new_backup($imageID, $backupBase, $uploadDir) {
        $meta = wp_get_attachment_metadata($imageID);
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;


        if ($unscaled === $scaled && is_string($scaled) && preg_match('/-scaled\.([^.]+)$/i', $scaled)) {
            $derived = preg_replace('/-scaled\.([^.]+)$/i', '.$1', $scaled);
            $derived_rel = str_replace($uploadDir . '/', '', $derived);
            $derived_bkp = $backupBase . $derived_rel;
            if ($derived !== $scaled && (file_exists($derived) || file_exists($derived_bkp))) {
                $unscaled = $derived;
                error_log('[WPC Restore] DERIVED_UNSCALED image=' . $imageID . ' from=' . basename($scaled) . ' to=' . basename($derived));
            }
        }

        $filesCopied = 0;


        $filesAttempted = 0;

        
        if ($unscaled) {
            $rel = str_replace($uploadDir . '/', '', $unscaled);
            $src = $backupBase . $rel;
            if (file_exists($src)) {
                $filesAttempted++;
                if ($this->verify_restore_atomic($src, $unscaled, $imageID, 'original')) $filesCopied++;
            }
        }

        
        if ($scaled && $scaled !== $unscaled) {
            $rel = str_replace($uploadDir . '/', '', $scaled);
            $src = $backupBase . $rel;
            if (file_exists($src)) {
                $filesAttempted++;
                if ($this->verify_restore_atomic($src, $scaled, $imageID, 'scaled')) $filesCopied++;
            }
        }

        
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $dir = dirname($scaled ?: $unscaled);
            foreach ($meta['sizes'] as $size => $info) {
                if (empty($info['file'])) continue;
                $thumbPath = $dir . '/' . $info['file'];
                $rel = str_replace($uploadDir . '/', '', $thumbPath);
                $src = $backupBase . $rel;
                if (file_exists($src)) {
                    $filesAttempted++;
                    if ($this->verify_restore_atomic($src, $thumbPath, $imageID, $size)) $filesCopied++;
                }
            }
        }

        
        
        if ($filesAttempted > $filesCopied) {
            error_log('[WPC Restore] new_backup PARTIAL_FAIL image=' . $imageID . ' attempted=' . $filesAttempted . ' verified=' . $filesCopied);
        }

        
        $backupMode = get_post_meta($imageID, 'wpc_backup_mode', true) ?: 'full';
        $needsRegen = ($backupMode === 'originals' || $backupMode === 'local');
        $mainMissing = ($scaled && !file_exists($scaled) && $unscaled && file_exists($unscaled));

        if ($needsRegen || $mainMissing) {
            $regenSource = ($unscaled && file_exists($unscaled)) ? $unscaled : $scaled;
            if ($regenSource && file_exists($regenSource)) {


                update_post_meta($imageID, '_wpc_pending_thumb_regen', [
                    'regen_source' => $regenSource,
                    'backup_mode'  => $backupMode,
                    'scheduled_at' => time(),
                ]);
                error_log('[WPC Restore] Thumbnail regen deferred to async worker image=' . $imageID . ' mode=' . $backupMode);
            }
        }

        error_log('[WPC Restore] new_backup files_copied=' . $filesCopied . ' image=' . $imageID);
        return $filesCopied > 0;
    }

    private function restore_from_bkp_files($imageID) {
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;
        $dir = dirname($scaled ?: $unscaled);
        $baseName = pathinfo($unscaled ?: $scaled, PATHINFO_FILENAME);
        $restored = false;


        foreach (glob($dir . '/' . $baseName . '*_bkp.*') as $bkpFile) {
            $original = str_replace('_bkp.', '.', $bkpFile);
            $label = basename($original);
            if ($this->verify_restore_atomic($bkpFile, $original, $imageID, $label)) {
                @unlink($bkpFile);
                $restored = true;
            }
        }

        
        $scaledBkp = preg_replace('/\.(jpe?g|png|gif)$/i', '_bkp.$1', $scaled);
        if ($scaledBkp && file_exists($scaledBkp)) {
            if ($this->verify_restore_atomic($scaledBkp, $scaled, $imageID, 'scaled')) {
                @unlink($scaledBkp);
                $restored = true;
            }
        }

        
        if ($restored && $scaled && !file_exists($scaled) && $unscaled && file_exists($unscaled) && $unscaled !== $scaled) {
            update_post_meta($imageID, '_wpc_pending_thumb_regen', [
                'regen_source' => $unscaled,
                'backup_mode'  => 'bkp_files',
                'scheduled_at' => time(),
            ]);
        }

        return $restored;
    }

    private function restore_from_cloud($imageID) {
        $site_url = get_site_url();
        $options = get_option(WPS_IC_OPTIONS);
        $request_url = add_query_arg(['imageID' => $imageID, 'imageSite' => $site_url, 'apikey' => $options['api_key'] ?? ''], WPC_IC_LOCAL_RESTORE);

        $t_svc = microtime(true);
        $response = wp_remote_get($request_url, ['timeout' => 30, 'sslverify' => false]);

        if (is_wp_error($response)) {
            error_log('[WPC Restore] Cloud service error image=' . $imageID . ' err=' . $response->get_error_message());
            return false;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        error_log('[WPC Restore] Cloud service image=' . $imageID . ' backups=' . count($data['backupUrls'] ?? []) . ' time=' . round(microtime(true) - $t_svc, 2) . 's');

        if (empty($data['backupUrls'])) return false;

        
        $byLabel = [];
        foreach ($data['backupUrls'] as $b) {
            $byLabel[$b['sizeLabel']] = $b['fileUrl'];
        }

        
        $scaledPath = get_attached_file($imageID);
        $unscaledPath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaledPath;
        $hasScaled = ($unscaledPath && $unscaledPath !== $scaledPath);

        foreach (['unscaled', 'original', 'scaled'] as $label) {
            if (empty($byLabel[$label])) continue;

            $t_dl = microtime(true);
            $tmp = download_url($byLabel[$label], 60);

            if (is_wp_error($tmp)) {
                error_log('[WPC Restore] Cloud download failed image=' . $imageID . ' label=' . $label . ' err=' . $tmp->get_error_message());
                continue;
            }

            $dlSize = filesize($tmp);
            error_log('[WPC Restore] Cloud download image=' . $imageID . ' label=' . $label . ' size=' . $dlSize . ' time=' . round(microtime(true) - $t_dl, 2) . 's');


            if ($label === 'unscaled' && $hasScaled) {
                
                $ok = $this->verify_restore_atomic($tmp, $unscaledPath, $imageID, 'cloud_unscaled');
                @unlink($tmp);
                if (!$ok) {
                    error_log('[WPC Restore] Cloud verify failed image=' . $imageID . ' label=unscaled — trying next');
                    continue;
                }


                remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                add_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX, 2);
                if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
            } else {
                
                $ok = $this->verify_restore_atomic($tmp, $scaledPath, $imageID, 'cloud_' . $label);
                @unlink($tmp);
                if (!$ok) {
                    error_log('[WPC Restore] Cloud verify failed image=' . $imageID . ' label=' . $label . ' — trying next');
                    continue;
                }
            }

            return true;
        }

        
        if (!empty($data['backupUrls'][0]['fileUrl'])) {
            $tmp = download_url($data['backupUrls'][0]['fileUrl'], 60);
            if (!is_wp_error($tmp)) {
                $ok = $this->verify_restore_atomic($tmp, $scaledPath, $imageID, 'cloud_lastresort');
                @unlink($tmp);
                if ($ok) return true;
                error_log('[WPC Restore] Cloud last-resort verify failed image=' . $imageID);
            }
        }

        return false;
    }

    private function regenerate_from_unscaled($imageID) {
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;

        if ($unscaled && file_exists($unscaled) && $unscaled !== $scaled) {


            update_post_meta($imageID, '_wpc_pending_thumb_regen', [
                'regen_source' => $unscaled,
                'backup_mode'  => 'unscaled-safety-net',
                'scheduled_at' => time(),
            ]);
            error_log('[WPC Restore] Priority-5 regen deferred to async worker image=' . $imageID);
            return true;
        }

        
        if ($scaled && file_exists($scaled)) return true;

        return false;
    }

    private function cleanup_backups($imageID, $backupBase, $uploadDir) {


        $meta = wp_get_attachment_metadata($imageID);
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;

        
        $filesToClean = [];
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            $dir = dirname($scaled ?: $unscaled);
            foreach ($meta['sizes'] as $info) {
                if (!empty($info['file'])) $filesToClean[] = $dir . '/' . $info['file'];
            }
        }
        foreach ($filesToClean as $f) {
            $rel = str_replace($uploadDir . '/', '', $f);
            $backupFile = $backupBase . $rel;
            if (file_exists($backupFile)) @unlink($backupFile);
        }

        
        
    }

    


    



    private function cleanRestoreMeta($imageID) {
        
        
        delete_transient('wpc_restoring_' . $imageID);


        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
            '_transient_wpc_szt_' . $imageID . '_%',
            '_transient_timeout_wpc_szt_' . $imageID . '_%',
            '_transient_wpc_fmtfill_' . $imageID . '_%',
            '_transient_timeout_wpc_fmtfill_' . $imageID . '_%'
        ));

        $attachedFile = get_attached_file($imageID);
        if ($attachedFile) {
            $dir = dirname($attachedFile);
            $baseName = pathinfo(wp_get_original_image_path($imageID) ?: $attachedFile, PATHINFO_FILENAME);
            
            
            $wpc_rg_mime = (string) get_post_mime_type($imageID);
            if ($wpc_rg_mime !== 'image/webp') {
                foreach (glob($dir . '/' . $baseName . '*.webp') as $webp) { @unlink($webp); }
            }
            if ($wpc_rg_mime !== 'image/avif') {
                foreach (glob($dir . '/' . $baseName . '*.avif') as $avif) { @unlink($avif); }
            }
        }

        delete_post_meta($imageID, 'ic_bulk_running');
        delete_post_meta($imageID, 'ic_compressing');
        delete_post_meta($imageID, 'wpc_images_compressed');
        delete_post_meta($imageID, 'ic_stats');
        delete_post_meta($imageID, 'ic_local_variants');
        delete_post_meta($imageID, 'ic_savings');
        delete_post_meta($imageID, 'ic_savings_format');
        delete_post_meta($imageID, 'ic_savings_bytes');
        delete_post_meta($imageID, 'ic_savings_baseline');
        delete_post_meta($imageID, 'ic_skipped');
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wps_ic_queue_' . $imageID);

        
        delete_post_meta($imageID, '_wpc_optimize_attempts');
        delete_transient('wpc_queued_' . $imageID);
        delete_transient('wpc_failed_' . $imageID);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('wpc_queued_' . $imageID, 'wpc');
        }
        
        $queue = get_option('wpc_compress_queue', []);
        if (is_array($queue) && in_array($imageID, $queue)) {
            $queue = array_values(array_diff($queue, [$imageID]));
            update_option('wpc_compress_queue', $queue, false);
        }


        delete_post_meta($imageID, '_wpc_ladder_attempts');
        delete_transient('wpc_failed_ladder_' . $imageID);
        delete_transient('wpc_ladder_queued_' . $imageID);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('wpc_ladder_queued_' . $imageID, 'wpc');
        }


        delete_transient('wpc_lazy_v2_trigger_' . $imageID);


        delete_option('wpc_v2_inflight_' . $imageID);
        $ladder_queue = get_option('wpc_ladder_gen_queue', []);
        if (is_array($ladder_queue) && isset($ladder_queue[$imageID])) {
            unset($ladder_queue[$imageID]);
            update_option('wpc_ladder_gen_queue', $ladder_queue, false);
            update_option('wpc_ladder_gen_queue_has_items', !empty($ladder_queue), false);
        }

        
        
        delete_post_meta($imageID, '_wpc_pending_downloads');
        delete_post_meta($imageID, '_wpc_download_fail_count');
        $next_dl = wp_next_scheduled('wpc_download_variants', [$imageID]);
        if ($next_dl) wp_unschedule_event($next_dl, 'wpc_download_variants', [$imageID]);
        delete_transient('wpc_download_lock_' . $imageID);


        global $wpdb;
        $like = $wpdb->esc_like('_transient_wpc_backfill_lock_' . (int) $imageID . '_') . '%';
        $lock_rows = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like
        ));
        foreach ((array) $lock_rows as $opt_name) {
            $transient_key = preg_replace('/^_transient_/', '', (string) $opt_name);
            if ($transient_key !== '') {
                delete_transient($transient_key);
                if (function_exists('wp_cache_delete')) {
                    wp_cache_delete($transient_key, 'wpc_backfill');
                }
            }
        }

        update_post_meta($imageID, 'ic_status', 'restored');
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); }


        update_post_meta($imageID, '_wpc_restore_completed_at', time());

        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

        if (function_exists('wpc_invalidate_local_cache')) { wpc_invalidate_local_cache(); }


    }

    public function olderBackup($imageID)
    {
        $backup_images = get_post_meta($imageID, 'ic_backup_images', true);

        if (!empty($backup_images) && is_array($backup_images)) {
            $compressed_images = get_post_meta($imageID, 'ic_compressed_images', true);

            
            if (!empty($compressed_images)) {

                foreach ($compressed_images as $index => $path) {
                    if (strpos($index, 'webp') !== false) {
                        if (file_exists($path)) {
                            unlink($path);
                        }
                    }
                }

            }

            $upload_dir = wp_get_upload_dir();
            $sizes = get_intermediate_image_sizes();
            foreach ($sizes as $i => $size) {
                clearstatcache();
                $image = image_get_intermediate_size($imageID, $size);
                if ($image['path']) {
                    $path = $upload_dir['basedir'] . '/' . $image['path'];
                    if (file_exists($path)) {
                        unlink($path);
                    }
                }
            }

            $scaledPath = get_attached_file($imageID);
            $unscaledPath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaledPath;
            if (!$unscaledPath) $unscaledPath = $scaledPath;
            $hasScaledVersion = ($scaledPath !== $unscaledPath);

            
            $restore_image_path = isset($backup_images['original']) ? $backup_images['original'] : (isset($backup_images['full']) ? $backup_images['full'] : '');

            
            $scaledBkp = $scaledPath . '_bkp';
            $inlineBkp = preg_replace('/\.(jpe?g|png|gif)$/i', '_bkp.$1', $scaledPath);

            if (!empty($restore_image_path) && file_exists($restore_image_path)) {
                
                if ($hasScaledVersion) {
                    
                    @copy($restore_image_path, $unscaledPath);
                    if (file_exists($scaledPath)) @unlink($scaledPath);
                    remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                    $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                    if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                } else {
                    
                    @copy($restore_image_path, $scaledPath);
                    remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                    $newMeta = wp_generate_attachment_metadata($imageID, $scaledPath);
                    if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                }
                @unlink($restore_image_path);
            } elseif (file_exists($inlineBkp)) {
                
                @copy($inlineBkp, $scaledPath);
                @unlink($inlineBkp);
                remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                $newMeta = wp_generate_attachment_metadata($imageID, $hasScaledVersion ? $unscaledPath : $scaledPath);
                if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);

                clearstatcache();

                
                delete_post_meta($imageID, 'ic_stats');
                delete_post_meta($imageID, 'ic_compressed_images');
                delete_post_meta($imageID, 'ic_compressed_thumbs');
                delete_post_meta($imageID, 'ic_backup_images');
                delete_post_meta($imageID, 'ic_local_variants');
                delete_post_meta($imageID, 'ic_savings');
                delete_post_meta($imageID, 'ic_savings_format');
                delete_post_meta($imageID, 'ic_savings_bytes');
                delete_post_meta($imageID, 'ic_savings_baseline');
                delete_post_meta($imageID, 'ic_ai_meta');
                delete_post_meta($imageID, 'ic_compressing');
                delete_post_meta($imageID, 'wpc_images_compressed');
                delete_post_meta($imageID, 'ic_bulk_running');
                update_post_meta($imageID, 'ic_status', 'restored');
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); }
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                error_log('[WPC Restore] olderBackup succeeded image=' . $imageID);
                return true;
            }

            
            error_log('[WPC Restore] olderBackup file MISSING image=' . $imageID . ' path=' . $restore_image_path);
            delete_post_meta($imageID, 'ic_backup_images');
            delete_post_meta($imageID, 'ic_compressed_images');
            delete_post_meta($imageID, 'ic_compressed_thumbs');
        }

        return false;
    }

    public function restore($imageID, $output = 'json')
    {
        if (!function_exists('download_url')) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        if (!function_exists('update_option')) {
            require_once(ABSPATH . "wp-includes" . '/option.php');
        }

        $output = [];

        wp_raise_memory_limit('image');
        ini_set('memory_limit', '1024M');

        $olderVersionBackup = $this->olderBackup($imageID);
        if ($olderVersionBackup) {
            return true;
        }

        
        $inProcess = get_post_meta($imageID, 'ic_bulk_running', true);
        if ($inProcess && $inProcess == 'true') {
        }

        


        $params = ['timeout' => 300, 'method' => 'POST', 'sslverify' => false, 'body' => ['getS3Backup' => true, 'apikey' => self::$apiParams['apikey'], 'imageID' => $imageID], 'user-agent' => WPS_IC_API_USERAGENT];

        $call = wp_remote_post(self::$apiURL, $params);

        $this->writeLog('Started Image ID ' . $imageID);

        if (wp_remote_retrieve_response_code($call) == 200) {
            $response = wp_remote_retrieve_body($call);
            $response = json_decode($response, true);


            $this->writeLog('API Response IS 200');
            $this->writeLog(print_r(wp_remote_retrieve_body($call), true));

            if ($response['success'] == 'true') {
                if (!empty($response['data'])) {

                    $alreadyRestored = [];
                    $oldMeta = get_post_meta($imageID, 'wpc_old_meta', true);

                    if (!empty($response['data']['url']['original']['local'])) {
                        $imageUrl = $response['data']['url']['original']['local'];
                        $imagePath = wp_get_original_image_path($imageID);

                        $downloadImage = download_url($imageUrl);

                        if (is_wp_error($downloadImage)) {
                            $this->writeLog('Unable to download Image');
                            $this->writeLog($imageUrl);
                            $this->writeLog($downloadImage);

                            $this->writeLog('Ended Image ID - failed to get backup ' . $imageID);

                            if ($output == 'json') {
                                wp_send_json_error(['msg' => 'failed-to-get-backup', 'apiUrl' => self::$apiURL, 'imageID' => $imageID, 'url' => $downloadImage]);
                            }

                            return false;
                        }

                        if (file_exists($imagePath)) {
                            unlink($imagePath);
                        }

                        copy($downloadImage, $imagePath);
                        unset($downloadImage);


                        
                        delete_post_meta($imageID, 'wpc_images_compressed');
                        delete_post_meta($imageID, 'ic_stats');
                        delete_post_meta($imageID, 'ic_compressed_images');
                        delete_post_meta($imageID, 'ic_compressed_thumbs');
                        delete_post_meta($imageID, 'ic_backup_images');
                        delete_post_meta($imageID, 'ic_local_variants');
                        delete_post_meta($imageID, 'ic_savings');
                        delete_post_meta($imageID, 'ic_savings_format');
                        delete_post_meta($imageID, 'ic_savings_bytes');
                        delete_post_meta($imageID, 'ic_savings_baseline');
                        update_post_meta($imageID, 'ic_status', 'restored');
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); }
                        delete_post_meta($imageID, 'ic_bulk_running');
                        delete_transient('wps_ic_compress_' . $imageID);

                        $originalFilePath = wp_get_original_image_path($imageID);
                        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                        $oldMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);
                        wp_update_attachment_metadata($imageID, $oldMeta);

                        
                        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                        $this->writeLog('Ended Image ID - restored ' . $imageID);

                        if ($output == 'json') {
                            wp_send_json_success(['msg' => 'backup-restored']);
                        }

                        return true;
                    }

                    foreach ($response['data']['url'] as $imageSize => $imageUrl) {

                        $imageUrl = $imageUrl['s3'];

                        
                        if (in_array($imageUrl, $alreadyRestored)) {
                            $this->writeLog('Image was already restored');
                            $this->writeLog($imageUrl);
                            continue;
                        }

                        if ($imageSize == 'original') {
                            $imagePath = wp_get_original_image_path($imageID);
                        } else {
                            $originalFilePath = wp_get_original_image_path($imageID);
                            $originalFilename = wp_basename($originalFilePath);
                            $this->pathToDir = str_replace($originalFilename, '', $originalFilePath);
                            
                            $imagePath = wp_get_attachment_image_src($imageID, $imageSize);
                            $imagePath = wp_basename($imagePath[0]);
                            $imagePath = $this->pathToDir . $imagePath;
                        }

                        
                        $localFilename = wp_basename($imagePath);

                        
                        $sentFilename = wp_basename($imageUrl);
                        $sentFilename = explode('?', $sentFilename);
                        $sentFilename = $sentFilename[0];

                        if ($sentFilename !== $localFilename) {
                            
                            $sentFilename = explode('-', $sentFilename);
                            $removed = array_shift($sentFilename);
                            $sentFilename = implode('-', $sentFilename);
                        }

                        if ($sentFilename !== $localFilename) {
                            
                        } else {
                            $downloadImage = download_url($imageUrl);

                            if (is_wp_error($downloadImage)) {
                                $this->writeLog('Unable to download Image');
                                $this->writeLog($imageUrl);
                                $this->writeLog($downloadImage);

                                $alreadyRestored[] = $imageUrl;
                                continue;
                            }

                            if (file_exists($imagePath)) {
                                unlink($imagePath);
                            }

                            copy($downloadImage, $imagePath);
                            unset($downloadImage);

                            
                            $imagesCompressed = get_post_meta($imageID, 'wpc_images_compressed', true);
                            foreach ($imagesCompressed as $image => $data) {
                                if (file_exists($data['webp_path'])) {
                                    unlink($data['webp_path']);
                                }
                            }

                            $this->writeLog('WebP path ' . $data['webp_path']);
                            $this->writeLog('WebP path exists ' . file_exists($data['webp_path']));

                        }
                    }

                    $originalFilePath = wp_get_original_image_path($imageID);
                    $oldMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);

                    wp_update_attachment_metadata($imageID, $oldMeta);

                    
                    delete_post_meta($imageID, 'wpc_images_compressed');
                    delete_post_meta($imageID, 'ic_stats');
                    delete_post_meta($imageID, 'ic_compressed_images');
                    delete_post_meta($imageID, 'ic_compressed_thumbs');
                    delete_post_meta($imageID, 'ic_backup_images');
                    delete_post_meta($imageID, 'ic_local_variants');
                    delete_post_meta($imageID, 'ic_savings');
                    delete_post_meta($imageID, 'ic_savings_format');
                    delete_post_meta($imageID, 'ic_savings_bytes');
                    delete_post_meta($imageID, 'ic_savings_baseline');
                    update_post_meta($imageID, 'ic_status', 'restored');
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); }
                    delete_post_meta($imageID, 'ic_bulk_running');
                    delete_transient('wps_ic_compress_' . $imageID);

                    
                    set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                    $this->writeLog('Ended Image ID - restored ' . $imageID);

                    if ($output == 'json') {
                        wp_send_json_success(['msg' => 'backup-restored']);
                    }
                }
            } else {
                $this->writeLog('Ended Image ID - failed to get backup ' . $imageID);
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'failed-to-get-backup', 'apiUrl' => self::$apiURL, 'imageID' => $imageID]);
                }
            }

        } else {
            $this->writeLog('API Response not 200');
            $this->writeLog(print_r(wp_remote_retrieve_body($call), true));
            $this->writeLog('Ended Image ID ' . $imageID);

            
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'unable-to-contact-api']);
            }
        }
    }

    public function disable_scaling()
    {
        return false;
    }

    public function singleCompressV3($imageID, $output = 'json')
    {
        wp_raise_memory_limit('image');
        $settings = get_option(WPS_IC_SETTINGS);

        
        if (!$this->is_supported($imageID)) {
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-not-supported']);
            } else {
                return 'file-not-supported';
            }
        }

        
        if ($this->is_already_compressed($imageID)) {
            $media_library = new wps_ic_media_library_live();
            $html = $media_library->compress_details($imageID);

            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-already-compressed', 'imageID' => $imageID, 'html' => $html]);
            } else {
                return 'file-already-compressed';
            }
        }

        
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing'], 60);

        
        if (!get_post_meta($imageID, 'wpc_old_meta')) {
            $oldMeta = wp_get_attachment_metadata($imageID);
            update_post_meta($imageID, 'wpc_old_meta', $oldMeta);
        }

        
        $post_fields = ['action' => 'queueSingleImage', 'imageID' => $imageID, 'siteUrl' => self::$siteUrl, 'apikey' => self::$apiParams['apikey'], 'parameters' => ['maxWidth' => WPS_IC_MAXWIDTH, 'quality' => self::$apiParams['quality'], 'retina' => self::$apiParams['retina'], 'webp' => self::$apiParams['webp']],];

        
        $notify = wp_remote_post(self::$apiURL . 'queueManager.php', ['timeout' => 60, 'method' => 'POST', 'sslverify' => false, 'body' => $post_fields, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($notify) == 200) {
            
            wp_send_json_success('waiting-queue');
        } else {
            delete_transient('wps_ic_compress_' . $imageID);
            
            wp_send_json_error(['msg' => 'unable-to-contact-api']);
        }
    }

    public function compress_image($imageID, $bulk = true, $retina = true, $webp = true, $just_thumbs = false, $regenerate = true, $output = 'json')
    {
        global $wpc_filesystem;
        wp_raise_memory_limit('image');

        $bulkStats = get_transient('wps_ic_bulk_stats');

        
        if (!$this->is_supported($imageID)) {
            if (!$bulk) {
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'file-not-supported']);
                } else {
                    return 'file-not-supported';
                }
            }

            return $bulkStats;
        }

        
        if ($this->is_already_compressed($imageID)) {
            if (!$bulk) {
                $media_library = new wps_ic_media_library_live();
                $html = $media_library->compress_details($imageID);

                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'file-already-compressed', 'imageID' => $imageID, 'html' => $html]);
                } else {
                    return 'file-already-compressed';
                }
            }

            return $bulkStats;
        }

        
        $inProcess = get_post_meta($imageID, 'ic_bulk_running', true);
        if ($inProcess && $inProcess == 'true') {
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-in-bulk', 'imageID' => $imageID]);
            } else {
                return 'file-in-bulk';
            }
        }

        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing'], 30);

        if (!get_post_meta($imageID, 'wpc_old_meta')) {
            $oldMeta = wp_get_attachment_metadata($imageID);
            update_post_meta($imageID, 'wpc_old_meta', $oldMeta);
        }

        $stats = get_post_meta($imageID, 'ic_stats', true);
        if (empty($stats) || !$stats) {
            $stats = [];
        }

        $post_fields = ['action' => 'compressArray', 'imageID' => $imageID, 'siteUrl' => self::$siteUrl, 'maxWidth' => WPS_IC_MAXWIDTH, 'apikey' => self::$apiParams['apikey'], 'quality' => self::$apiParams['quality'], 'retina' => self::$apiParams['retina'], 'webp' => self::$apiParams['webp'],];

        $response = wp_remote_post(self::$apiURL, ['timeout' => 60, 'method' => 'POST', 'sslverify' => false, 'body' => $post_fields, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($response) == 200) {
            set_transient('wps_ic_compress_' . $imageID, 'sent-to-api', 30);

            $body = wp_remote_retrieve_body($response);
            $body = json_decode($body);

            if ($body->success == 'true') {
                
                if ($output == 'json') {
                    wp_send_json_success([self::$apiURL, $post_fields, $body]);
                } else {
                    return 'done';
                }
            } else {
                delete_transient('wps_ic_compress_' . $imageID);

                
                if ($output == 'json') {
                    wp_send_json_error(['msg' => $body->data->msg, 'server' => $body->data->server]);
                } else {
                    return 'done';
                }
            }

        } else {
            delete_transient('wps_ic_compress_' . $imageID);

            
            wp_send_json_error(['msg' => 'unable-to-contact-api']);
        }
    }

    public function debug_msg($attachmentID, $mesage)
    {
        if (defined('WPS_IC_DEBUG') && WPS_IC_DEBUG == 'true') {
            $debug_log = get_post_meta($attachmentID, 'ic_debug', true);
            if (!$debug_log) {
                $debug_log = [];
            }
            $debug_log[] = $mesage;
            update_post_meta($attachmentID, 'ic_debug', $debug_log);
        }
    }

    public function generate_retina($arg)
    {
        $imageID = $arg;
        $return = [];
        $compressed = [];
        $filename = '';

        $image = $image_url = wp_get_attachment_image_src($imageID, 'full');
        $image_url = $image_url[0];

        if ($filename == '') {
            if (strpos($image_url, '.jpg') !== false) {
                $extension = 'jpg';
            } elseif (strpos($image_url, '.jpeg') !== false) {
                $extension = 'jpeg';
            } elseif (strpos($image_url, '.gif') !== false) {
                $extension = 'gif';
            } elseif (strpos($image_url, '.png') !== false) {
                $extension = 'png';
            } else {
                return true;
            }
        }

        


        $file_path = get_attached_file($imageID);
        $file_basename = basename($image[0]);
        $file_path = str_replace($file_basename, '', $file_path);

        foreach ($this->sizes as $i => $size) {
            if (empty($image_url)) {
                continue;
            }

            $retinaAPIUrl = self::$apiURL . $image_url;

            if ($size == 'full') {
                continue;
            } else {
                $image = image_get_intermediate_size($imageID, $size);
                $image_url = $image['url'];
            }

            if (empty($image['width']) || $image['width'] == '') {
                continue;
            }

            $file_location = $file_path . basename($image_url);

            
            $retina_file_location = str_replace('.' . $extension, '-x2.' . $extension, $file_location);

            
            $retinaAPIUrl = str_replace('r:0', 'r:1', $retinaAPIUrl);
            $retinaAPIUrl = str_replace('w:1', 'w:' . $image['width'], $retinaAPIUrl);

            $call = wp_remote_get($retinaAPIUrl, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($call) == 200) {
                $body = wp_remote_retrieve_body($call);
                
                $retina_fmt = 'jpeg';
                if (strpos($retina_file_location, '.avif') !== false) $retina_fmt = 'avif';
                elseif (strpos($retina_file_location, '.webp') !== false) $retina_fmt = 'webp';
                elseif (strpos($retina_file_location, '.png') !== false) $retina_fmt = 'png';
                if (!empty($body) && wpc_is_valid_image_bytes($body, $retina_fmt, isset($imageID) ? $imageID : 0, 'legacy_retina')) {
                    file_put_contents($retina_file_location, $body);
                    clearstatcache();

                    $stats[$size . '-2x']['compressed']['size'] = filesize($retina_file_location);
                    $compressed[$size . '-2x'] = $retina_file_location;
                }
            }
        }

        if (isset ($stats)) {
            $return['stats'] = $stats;
        }
        $return['compressed'] = $compressed;

        $stats = get_post_meta($imageID, 'ic_stats', true);

        if (empty($stats)) {
            $stats = [];
        }
        if (empty($return['stats'])) {
            $return['stats'] = [];
        }

        $stats = array_merge($stats, $return['stats']);
        update_post_meta($imageID, 'ic_stats', $stats);

        $compressed = get_post_meta($imageID, 'ic_compressed_images', true);
        $compressed = array_merge($compressed, $return['compressed']);
        update_post_meta($imageID, 'ic_compressed_images', $compressed);

        return $return;
    }

    public function regenerate_thumbnails($imageID)
    {
        wp_raise_memory_limit('image');
        $thumbs = [];
        $thumbs['total']['old'] = 0;
        $thumbs['total']['new'] = 0;

        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        if (!function_exists('download_url')) {
            require_once(ABSPATH . "wp-admin" . '/includes/image.php');
            require_once(ABSPATH . "wp-admin" . '/includes/file.php');
            require_once(ABSPATH . "wp-admin" . '/includes/media.php');
        }

        
        $upload_dir = wp_get_upload_dir();
        $sizes = get_intermediate_image_sizes();
        foreach ($sizes as $i => $size) {
            clearstatcache();
            $image = image_get_intermediate_size($imageID, $size);
            if (!empty($image) && isset($image['path'])) {
                $image['path'] = str_replace('./', '', $image['path']);
                $path = $upload_dir['basedir'] . '/' . $image['path'];
                $thumbs[$size]['old'] = filesize($path);
                $thumbs['total']['old'] = $thumbs['total']['old'] + filesize($path);
            } else if (!empty($image)) {
                $image = str_replace('./', '', $image);
                $path = $upload_dir['basedir'] . '/' . $image;
                $thumbs[$size]['old'] = filesize($path);
                $thumbs['total']['old'] = $thumbs['total']['old'] + filesize($path);
            }
        }

        add_filter('jpeg_quality', function ($arg) {
            return 70;
        });

        foreach ($sizes as $i => $size) {
            clearstatcache();
            $image = image_get_intermediate_size($imageID, $size);
            if (!empty($image) && isset($image['path'])) {
                $image['path'] = str_replace('./', '', $image['path']);
                $path = $upload_dir['basedir'] . '/' . $image['path'];
                $thumbs[$size]['new'] = filesize($path);
                $thumbs['total']['new'] = $thumbs['total']['new'] + filesize($path);
            } else if (!empty($image)) {
                $image = str_replace('./', '', $image);
                $path = $upload_dir['basedir'] . '/' . $image;
                $thumbs[$size]['new'] = filesize($path);
                $thumbs['total']['new'] = $thumbs['total']['new'] + filesize($path);
            }

        }

        update_post_meta($imageID, 'ic_compressed_thumbs', $thumbs);
    }

    public function restartCompressWorker()
    {
        
        $post_fields = ['action' => 'restartCompressWorker', 'apikey' => self::$apiParams['apikey'], 'siteurl' => self::$siteUrl,];

        
        $notify = wp_remote_post(self::$apiURL, ['timeout' => 90, 'blocking' => true, 'body' => $post_fields, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
    }

    public function restartRestoreWorker()
    {
        
        $post_fields = ['action' => 'restartRestoreWorker', 'apikey' => self::$apiParams['apikey'], 'siteurl' => self::$siteUrl,];

        
        $notify = wp_remote_post(self::$apiURL, ['timeout' => 90, 'blocking' => true, 'body' => $post_fields, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
    }

}