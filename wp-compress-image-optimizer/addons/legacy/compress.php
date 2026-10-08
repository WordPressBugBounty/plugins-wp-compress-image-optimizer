<?php
/**
 * Local Compress
 * @since 5.00.59
 */


// 7.01.0 Modern Image Delivery — trigger infrastructure (global helpers)


/**
 * Rate-limited diagnostic log — ring buffer, 500 entries max, 1 entry/hour/attachment/event (G14)
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





if (!function_exists('wpc_purge_variants_for_image')) {
    function wpc_purge_variants_for_image($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID || get_post_type($imageID) !== 'attachment') {
            return ['imageID' => $imageID, 'cleared' => [], 'error' => 'invalid_image'];
        }

        // The set and its savings are cleared by their owner; the rest here.
        $cleared = [];
        foreach (array_merge(['ic_local_variants'], wps_ic_image_variants::SAVINGS_KEYS) as $key) {
            $val = get_post_meta($imageID, $key, true);
            if ($val !== '' && $val !== null && $val !== false) {
                $cleared[] = $key;
            }
        }
        wps_ic_image_variants::clear($imageID, 'purge');

        $candidates = [
            'ic_local_variants_chosen',
            'ic_stats',
            '_wpc_compress_started_at',
        ];

        foreach ($candidates as $key) {
            $val = get_post_meta($imageID, $key, true);
            if ($val !== '' && $val !== null && $val !== false) {
                delete_post_meta($imageID, $key);
                $cleared[] = $key;
            }
        }

        // Heartbeat transient — clear so card re-renders without stale state
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
                // Canonical 4-tier lookup (matches modal's logic)
                $orig = WPC_Modern_Delivery::canonical_original_size($imageID, $base, $meta, $variants);
            } else {
                // Legacy fallback path — read stored, then sibling-derive
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

/**
 * Atomic queue-dedup gate (L7). Uses object-cache ADD semantics when persistent cache is available,
 * falls back to transient check. The queue worker lock (wps_local_compress::queue_lock_take) bounds worst case (G4).
 */
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

/**
 * Lazy-trigger local-mc optimization on HTML render (Modern Image Delivery).
 * Multi-gate dedup: already-compressed, permanent-fail cooldown, retry ceiling,
 * atomic concurrent-visitor dedup, queue-array dedup.
 */
if (!function_exists('wpc_maybe_trigger_optimize')) {
    function wpc_maybe_trigger_optimize($attachmentId)
    {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId <= 0) return;

        // Gate 1: already successfully compressed
        if (class_exists('wps_local_compress') && method_exists('wps_local_compress', 'is_already_compressed')) {
            $inst = new wps_local_compress();
            if ($inst->is_already_compressed($attachmentId)) return;
        }

        // Gate 2: permanent-fail cooldown (24h after retry ceiling hit)
        if (get_transient('wpc_failed_' . $attachmentId)) return;

        // Gate 3: retry ceiling
        $attempts = (int) get_post_meta($attachmentId, '_wpc_optimize_attempts', true);
        if ($attempts >= 3) {
            set_transient('wpc_failed_' . $attachmentId, 1, DAY_IN_SECONDS);
            wpc_log_trigger('retry_ceiling_hit', $attachmentId, ['attempts' => $attempts]);
            return;
        }

        // Gate 4: atomic 30-min concurrent-visitor dedup
        if (!wpc_atomic_queue_gate($attachmentId)) return;

        // Gate 5: queue-array dedup (belt-and-suspenders)
        wps_local_compress::queue_add($attachmentId);

        wpc_log_trigger('queued_lazy_gen', $attachmentId);

        // Fire non-blocking worker (existing infrastructure, worker-lock already guarded)
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

        // Locate a variant file on disk, trying both naming conventions.
        // Returns the relative path (from uploads root) if found, null otherwise.
        $resolve = function ($base_name, $format) use ($base_dir, $rel_dir) {
            // Convention 1: local-mc strips -scaled (e.g. hero.avif)
            $p = $base_dir . '/' . $rel_dir . '/' . $base_name . '.' . $format;
            if (file_exists($p) && filesize($p) > 0) {
                return $rel_dir . '/' . $base_name . '.' . $format;
            }
            // Convention 2: legacy kept -scaled (e.g. hero-scaled.avif)
            $stripped = preg_replace('/-scaled$/', '', $base_name);
            if ($stripped !== $base_name) {
                $p2 = $base_dir . '/' . $rel_dir . '/' . $stripped . '.' . $format;
                if (file_exists($p2) && filesize($p2) > 0) {
                    return $rel_dir . '/' . $stripped . '.' . $format;
                }
            }
            return null;
        };

        // WP-registered sizes
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

        // Scaled / full-size master
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

        wps_ic_image_variants::record($attachmentId, $variants, 'disk-backfill');
        return true;
    }
}


if (!function_exists('wpc_maybe_trigger_ladder_gen')) {
    function wpc_maybe_trigger_ladder_gen($attachmentId, $missing_widths)
    {
        $attachmentId = (int) $attachmentId;
        if ($attachmentId <= 0 || empty($missing_widths)) return;

        // Gate 1: permanent-fail cooldown (24h after retry ceiling)
        if (get_transient('wpc_failed_ladder_' . $attachmentId)) return;

        // Gate 2: retry ceiling (3 failures per 24h)
        $attempts = (int) get_post_meta($attachmentId, '_wpc_ladder_attempts', true);
        if ($attempts >= 3) {
            set_transient('wpc_failed_ladder_' . $attachmentId, 1, DAY_IN_SECONDS);
            return;
        }

        // Gate 3: atomic 30-min concurrent-visitor dedup
        $gate_key = 'wpc_ladder_queued_' . $attachmentId;
        if (function_exists('wp_cache_add') && function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache()) {
            if (!wp_cache_add($gate_key, time(), 'wpc', 30 * MINUTE_IN_SECONDS)) {
                // Merge widths into existing queue entry (more widths might have been detected)
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

        // Gate 4: queue-array dedup + size cap
        $queue = get_option('wpc_ladder_gen_queue', []);
        if (!is_array($queue)) $queue = [];

        // Soft cap at 1000 attachments — prevents options table bloat on large libraries
        if (count($queue) >= 1000 && !isset($queue[$attachmentId])) {
            if (function_exists('wpc_log_trigger')) {
                wpc_log_trigger('ladder_queue_full', $attachmentId);
            }
            return;
        }

        // Merge new widths with any already-queued for this attachment
        $existing_widths = isset($queue[$attachmentId]) ? (array) $queue[$attachmentId] : [];
        $queue[$attachmentId] = array_values(array_unique(array_merge($existing_widths, array_map('intval', $missing_widths))));
        update_option('wpc_ladder_gen_queue', $queue, false);
        update_option('wpc_ladder_gen_queue_has_items', true, false);

        if (function_exists('wpc_log_trigger')) {
            wpc_log_trigger('ladder_queued', $attachmentId, ['widths' => $missing_widths]);
        }

        // Fire non-blocking async worker (primary trigger — Layer 2)
        wpc_fire_ladder_gen_worker();
    }
}

/**
 * Merge additional widths into an existing queue entry without re-firing the worker.
 * Called when the atomic gate blocks (another visitor already queued this attachment).
 */
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

        // Server-level markers
        if (!empty($_SERVER['HTTP_AUTHORIZATION']) && stripos($_SERVER['HTTP_AUTHORIZATION'], 'basic') === 0) {
            return $cached = true;
        }
        if (!empty($_SERVER['PHP_AUTH_USER'])) {
            return $cached = true;
        }
        // Common .htaccess auth markers
        if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) || !empty($_SERVER['HTTP_X_ORIGINAL_AUTHORIZATION'])) {
            return $cached = true;
        }

        // Admin-configured auth (Jetpack staging, WP Engine, Flywheel staging flags)
        if (defined('WPE_ATLAS_STAGING') || defined('IS_STAGING')) {
            return $cached = true;
        }

        return $cached = false;
    }
}

/**
 * Fire the ladder-gen worker via non-blocking loopback POST (Layer 2 primary trigger).
 * Uses the same pattern as fireQueueWorker() — proven on shared hosts.
 *
 * Skipped on Basic-Auth sites (loopback would 401). Layers 3/4 drain the queue instead.
 */
if (!function_exists('wpc_fire_ladder_gen_worker')) {
    function wpc_fire_ladder_gen_worker()
    {
        // Skip loopback on Basic-Auth sites — will hang/fail. Shutdown + admin hooks handle drain.
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

/**
 * Detect coexisting image optimization plugins/CDNs that may conflict.
 * Returns array of detected conflicts with names. Non-blocking — used for admin warnings.
 */
if (!function_exists('wpc_detect_image_coexistence')) {
    function wpc_detect_image_coexistence()
    {
        $detected = [];

        // Jetpack Photon (image CDN) — rewrites <img src> to i0.wp.com at render time
        if (class_exists('Jetpack_Photon') || (function_exists('jetpack_is_photon_module_active') && jetpack_is_photon_module_active())) {
            $detected[] = ['key' => 'jetpack_photon', 'name' => 'Jetpack Photon (Image CDN)'];
        }

        // Cloudflare Polish — server-level, detected via response headers (can't check from PHP reliably)
        // Skip — warn in docs instead.

        // Kinsta CDN (auto-rewrites uploads URLs)
        if (defined('KINSTAMU_VERSION') || !empty($_SERVER['KINSTA_CACHE_ZONE'])) {
            $detected[] = ['key' => 'kinsta_cdn', 'name' => 'Kinsta Cache/CDN'];
        }

        // WP Engine CDN / Image Optimizer
        if (class_exists('WpeCommon') && class_exists('WpeImageProcessor')) {
            $detected[] = ['key' => 'wpe_image_optimizer', 'name' => 'WP Engine Image Optimizer'];
        }

        // ShortPixel Image Optimizer
        if (class_exists('ShortPixelPlugin') || class_exists('WPShortPixel')) {
            $detected[] = ['key' => 'shortpixel', 'name' => 'ShortPixel Image Optimizer'];
        }

        // Imagify
        if (class_exists('Imagify') || class_exists('Imagify_Assets')) {
            $detected[] = ['key' => 'imagify', 'name' => 'Imagify'];
        }

        // Smush (by WPMU DEV) — active as plugin, not checking for S3 specifically
        if (class_exists('WP_Smush') && !class_exists('WDEV_Plugin_Dashboard')) {
            $detected[] = ['key' => 'smush', 'name' => 'Smush Image Compression'];
        }

        // EWWW Image Optimizer
        if (defined('EWWW_IMAGE_OPTIMIZER_VERSION') || class_exists('EWWW_Image_Optimizer')) {
            $detected[] = ['key' => 'ewww', 'name' => 'EWWW Image Optimizer'];
        }

        // Optimole
        if (class_exists('Optml_Main') || defined('OPTML_VERSION')) {
            $detected[] = ['key' => 'optimole', 'name' => 'Optimole'];
        }

        return $detected;
    }
}

/**
 * Admin notice when coexistence conflicts detected + Modern Delivery active.
 * Warns but does NOT block — customer decides whether to disable the other plugin.
 */
if (!function_exists('wpc_modern_delivery_coexistence_notice')) {
    function wpc_modern_delivery_coexistence_notice()
    {
        if (!function_exists('wpc_set_state_notice')) return;
        $settings = get_option(WPS_IC_SETTINGS, []);
        if (empty($settings['modern_image_delivery']) || $settings['modern_image_delivery'] != '1') { wpc_clear_state_notice('coexist'); return; }
        $conflicts = wpc_detect_image_coexistence();
        if (empty($conflicts)) { wpc_clear_state_notice('coexist'); return; }
        $names = array_map(function ($c) { return (string) $c['name']; }, $conflicts);
        wpc_set_state_notice('coexist', 'info', sprintf(__('Another image optimizer is active (%s). Disable one of them to avoid double-processing and URL conflicts.', 'wp-compress-image-optimizer'), implode(', ', $names)));
    }
    add_action('admin_init', 'wpc_modern_delivery_coexistence_notice', 30);
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

            // Track max queue depth ever seen (for ops visibility)
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


        // The ladder's widths go through the dispatch door, which refuses them (ladder-retired)
        // until decision D5 on the ladder is taken; this leg sends nothing. Before, it POSTed the
        // full image to the v1 /optimize route, which has answered 404 since the orchestrator
        // replaced the v1 service.
        $result = wps_ic_image_optimize::dispatch($attachmentId, 'ladder', [
            'needed_widths'  => array_values(array_map('intval', (array) $widths)),
            'triggerContext' => 'ladder_' . $trigger_source,
        ]);
        $ok = !empty($result['ok']);
        $total_ms = (int) round((microtime(true) - $t_start) * 1000);
        wpc_update_ladder_stats([
            'event'          => $ok ? 'success' : 'failed',
            'duration_ms'    => $total_ms,
            'trigger_source' => $trigger_source,
        ]);
        if (function_exists('wpc_log_trigger')) {
            wpc_log_trigger($ok ? 'ladder_gen_success' : 'ladder_gen_failed', $attachmentId, [
                'widths'         => $widths,
                'duration_ms'    => $total_ms,
                'trigger_source' => $trigger_source,
                'error'          => $ok ? '' : (string) ($result['error'] ?? ''),
            ]);
        }
        return $ok;
    }
}

/**
 * Layer 3 — Frontend shutdown hook (cron replacement).
 * Processes 1 queue item per page load. Natural rate limiting via traffic.
 * WP "shutdown" still holds the visitor's connection AND the FPM worker through the
 * 120s optimize POST unless the request is detached first — so detach or skip.
 */
if (!function_exists('wpc_ladder_shutdown_hook')) {
    function wpc_ladder_shutdown_hook()
    {
        if (!get_option('wpc_ladder_gen_queue_has_items')) return;
        if (is_admin()) return;
        if (!(function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) return; // no detach → loopback/admin lanes drain instead
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) return;
        wpc_finish_request();
        @set_time_limit(150);

        wpc_handle_async_ladder_gen(1, 'shutdown');
    }
    add_action('shutdown', 'wpc_ladder_shutdown_hook', 1);
}

/**
 * Layer 4 — Admin page hook (parallel drain path).
 * Every admin page view processes 1-3 queue items.
 * Customer browsing settings → queue drains naturally.
 */
if (!function_exists('wpc_ladder_admin_hook')) {
    function wpc_ladder_admin_hook()
    {
        if (!get_option('wpc_ladder_gen_queue_has_items')) return;
        // Skip when WE are the ajax action being processed — let the loopback handler own
        // this execution + attribute it correctly. admin_init fires on admin-ajax.php too.
        if (defined('DOING_AJAX') && DOING_AJAX) {
            $ajax_action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
            if ($ajax_action === 'wpc_async_ladder_gen' || $ajax_action === 'wpc_ladder_process_manual') return;
        }
        // NEVER inline in an admin render: fire the detached loopback worker instead
        if (function_exists('wpc_fire_ladder_gen_worker')) {
            wpc_fire_ladder_gen_worker();
            return;
        }
        wpc_handle_async_ladder_gen(1, 'admin');
    }
    add_action('admin_init', 'wpc_ladder_admin_hook', 99);
}

// These endpoints are nopriv because loopbacks carry no cookies/Origin — a short-lived
// single-use token minted at fire time is the auth. Anonymous calls without it: 403.
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

// Layer 2 — AJAX handler for loopback POST (primary trigger)
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
        // Single-flight: N concurrent fires must never mean N pinned ~90s workers.
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

// Layer 5 — Manual "Process Queue" admin button (last resort for stuck queues)
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

// Layer 6 — WP Cron fallback (best-effort — many hosts disable)
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


// SECURITY (v7.10.821) — the two legacy loopback workers (wpc_download_variants / wpc_regen_thumbs)
// registered wp_ajax_nopriv_ handlers that did real work (remote variant fetches, full
// wp_generate_attachment_metadata thumbnail regen with a raised memory ceiling) on an int-cast
// imageID. No SQLi, but an UNAUTHENTICATED resource-abuse / DoS trigger: anyone could POST imageIDs
// and drive image work on the box. These endpoints are ONLY ever hit by our own server-side
// loopback firer, so they can carry a self-minted token — but a plain wp_create_nonce is wrong
// here: the firer may run as an admin (bulk compress) or cron, while the cookieless loopback always
// arrives as uid 0, so a uid-bound nonce would fail to verify and silently break admin-initiated
// downloads. This token is uid-INDEPENDENT (HMAC over action+id+time-bucket with the site's nonce
// salt) and binds the imageID, so a captured token cannot be replayed for a different image.
// NOT the existing wpc_loopback_token_ok() single-use transient: that keys ONE token per action
// name, so two images firing concurrently (these endpoints run per-image in parallel, unlike the
// single-flight ladder/prewarm/retry loopbacks) would clobber each other's transient and silently
// drop a download. This variant is stateless, so concurrent per-image fires never collide.
if (!function_exists('wpc_loopback_token')) {
    function wpc_loopback_token($action, $imageID, $bucket = null)
    {
        if (!function_exists('wp_salt') || !function_exists('hash_hmac')) { return ''; }
        $bucket = ($bucket === null) ? (int) floor(time() / 300) : (int) $bucket;
        return hash_hmac('sha256', $action . '|' . (int) $imageID . '|' . $bucket, wp_salt('nonce'));
    }
    function wpc_loopback_token_is_valid($action, $imageID, $token)
    {
        $token = (string) $token;
        if ($token === '' || !function_exists('hash_equals')) { return false; }
        // Accept the current and previous 5-min bucket so a token minted just before a boundary
        // still verifies across the loopback's sub-second flight; ~5–10 min total validity.
        $now = (int) floor(time() / 300);
        foreach ([$now, $now - 1] as $b) {
            $expect = wpc_loopback_token($action, $imageID, $b);
            if ($expect !== '' && hash_equals($expect, $token)) { return true; }
        }
        return false;
    }
}


if (!function_exists('wpc_run_admin_drain')) {
    function wpc_run_admin_drain()
    {
        // The whole drain (thumb regens, compression) yields on a hot box
        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) {
            if (function_exists('wpc_sweep_pending_thumb_regens')) {
                wpc_sweep_pending_thumb_regens();
            }
            return;
        }

        global $wpdb;

        // Pending thumbnail regens (from restore Phase B)
        $regen_rows = $wpdb->get_results("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_wpc_pending_thumb_regen'
            LIMIT 3
        ");
        foreach ((array) $regen_rows as $row) {
            if (function_exists('wpc_regen_thumbs_hook')) wpc_regen_thumbs_hook((int) $row->post_id);
        }


        $compress_queue_busy = false;
        if (class_exists('wps_local_compress')) {
            if (wps_local_compress::queue_lock_take()) {
                try {
                    $cq_id = wps_local_compress::queue_next();
                    if ($cq_id > 0) {
                        $compress_queue_busy = true;
                        $cq_obj = new wps_local_compress();
                        if (method_exists($cq_obj, 'backup_all_sizes')) {
                            $cq_obj->backup_all_sizes($cq_id);
                        }
                        if (wps_local_compress::queue_lock_touch()) {
                            $cq_obj->singleCompressV4($cq_id, 'silent', true, 'page-load-drain');
                            wps_local_compress::queue_done($cq_id);
                        }
                    }
                } finally {
                    wps_local_compress::queue_lock_release();
                }
            } else {
                $compress_queue_busy = true;
            }
        }

        // Nothing pending anywhere → arm the 60s idle throttle
        if (empty($regen_rows) && !$compress_queue_busy) {
            update_option('wpc_admin_drain_idle_at', time(), false);
        }
    }
}

if (!function_exists('wpc_dispatch_admin_drain_loopback')) {
    function wpc_dispatch_admin_drain_loopback()
    {
        if (function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth()) return false;
        if (get_option('wpc_loopback_status', '') === 'fail') return false;
        if (!class_exists('wps_ic_ajax') || !method_exists('wps_ic_ajax', 'wpc_loopback_open_socket') || !function_exists('wpc_loopback_token_mint')) return false;
        $dp = wp_parse_url(admin_url('admin-ajax.php'));
        if (empty($dp['host'])) return false;
        $dhttps = (!empty($dp['scheme']) && $dp['scheme'] === 'https');
        $dport  = !empty($dp['port']) ? (int) $dp['port'] : ($dhttps ? 443 : 80);
        $dhost  = (string) $dp['host'];
        $dpath  = (!empty($dp['path']) ? $dp['path'] : '/') . '?action=wpc_admin_drain_loopback&t=' . rawurlencode(wpc_loopback_token_mint('drain'));
        $dreq   = "POST {$dpath} HTTP/1.1\r\nHost: {$dhost}\r\nContent-Length: 0\r\nConnection: close\r\nUser-Agent: WPCAdminDrain/1.0\r\n\r\n";
        $dfp = wps_ic_ajax::wpc_loopback_open_socket($dhost, $dport, $dhttps, 0.2);
        if (!$dfp) return false;
        @stream_set_timeout($dfp, 0, 100000); @fwrite($dfp, $dreq); @fclose($dfp);
        return true;
    }
}

if (!function_exists('wpc_admin_drain_pending_downloads')) {
    function wpc_admin_drain_pending_downloads()
    {
        if (!is_admin() || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) return;

        // Idle throttle (durable): when the last pass found nothing, re-scan at most once/60s
        $drainIdleAt = (int) get_option('wpc_admin_drain_idle_at');
        if ($drainIdleAt && (time() - $drainIdleAt) < 60) return;

        if (function_exists('wpc_under_pressure') && wpc_under_pressure()) return;

        // A worker, not a page hook: one dispatch per 60s whatever the outcome, and NEVER
        // inside the admin response (spessart-militaria: 2.84s of a 3.03s wp-admin load was
        // this drain's blocking optimize-v2 POST, on every backend page view).
        if (get_transient('wpc_admin_drain_fired48')) return;
        set_transient('wpc_admin_drain_fired48', time(), 60);
        if (wpc_dispatch_admin_drain_loopback()) return;

        // No loopback on this host: run after the response is released, never before
        add_action('shutdown', 'wpc_run_admin_drain_after_response', 9999);
    }
    add_action('admin_init', 'wpc_admin_drain_pending_downloads', 99);
}

if (!function_exists('wpc_run_admin_drain_after_response')) {
    function wpc_run_admin_drain_after_response()
    {
        if (function_exists('wpc_finish_request')) { wpc_finish_request(); }
        @set_time_limit(120);
        wpc_run_admin_drain();
    }
}

if (!function_exists('wpc_admin_drain_loopback_ajax')) {
    function wpc_admin_drain_loopback_ajax()
    {
        if (!function_exists('wpc_loopback_token_ok') || !wpc_loopback_token_ok('drain')) {
            wp_die('', '', ['response' => 403]);
        }
        @set_time_limit(120);
        wpc_run_admin_drain();
        wp_die('', '', ['response' => 200]);
    }
    add_action('wp_ajax_wpc_admin_drain_loopback', 'wpc_admin_drain_loopback_ajax');
    add_action('wp_ajax_nopriv_wpc_admin_drain_loopback', 'wpc_admin_drain_loopback_ajax');
}



if (!function_exists('wpc_should_rewrite_jpeg_inplace')) {
    function wpc_should_rewrite_jpeg_inplace()
    {
        $mode = function_exists('wpc_get_optimization_mode') ? (string) wpc_get_optimization_mode() : 'legacy';
        return (bool) apply_filters('wpc_rewrites_jpeg_inplace', $mode !== 'lazy_cdn');
    }
}

if (!function_exists('wpc_sweep_pending_thumb_regens')) {
    function wpc_sweep_pending_thumb_regens()
    {
        global $wpdb;
        if (!isset($wpdb)) {
            return 0;
        }
        $rows = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wpc_pending_thumb_regen' ORDER BY meta_id ASC LIMIT 20");
        $fired = 0;
        $pending = 0;
        foreach ((array) $rows as $row) {
            $pending++;
            if ($fired > 0) {
                continue;
            }
            $plan = maybe_unserialize($row->meta_value);
            $at = is_array($plan) && !empty($plan['scheduled_at']) ? (int) $plan['scheduled_at'] : 0;
            if ($at > 0 && (time() - $at) < 120) {
                continue;
            }
            if (function_exists('wpc_fire_regen_thumbs_worker')) {
                wpc_fire_regen_thumbs_worker((int) $row->post_id);
                $fired++;
            }
        }
        if ($pending > 0 && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled(WPC_THUMB_REGEN_SWEEP_HOOK)) {
            wp_schedule_single_event(time() + 60, WPC_THUMB_REGEN_SWEEP_HOOK);
        }
        if ($fired > 0 && function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('regen-sweep', '', '', ['pending' => $pending, 'fired' => $fired]);
        }
        return $fired;
    }
    add_action(WPC_THUMB_REGEN_SWEEP_HOOK, 'wpc_sweep_pending_thumb_regens');
    function wpc_schedule_thumb_regen($imageID)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0 || !function_exists('add_action') || !function_exists('wpc_regen_thumbs_hook')) {
            return false;
        }
        static $armedImages = [];
        if (isset($armedImages[$imageID])) {
            return true;
        }
        $plan = get_post_meta($imageID, '_wpc_pending_thumb_regen', true);
        $at = is_array($plan) && !empty($plan['scheduled_at']) ? (int) $plan['scheduled_at'] : 0;
        if (!is_array($plan) || $at <= 0 || (time() - $at) < 120 || get_transient('wpc_regen_thumbs_lock_' . $imageID)) {
            return false;
        }
        $armedImages[$imageID] = 1;
        add_action('shutdown', function () use ($imageID) {
            if (function_exists('wpc_finish_request') && empty($GLOBALS['wpc_response_released'])) {
                wpc_finish_request();
            }
            @ignore_user_abort(true);
            @set_time_limit(120);
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('regen-nudge', (string) $imageID, '', ['age' => time() - (int) get_post_meta($imageID, '_wpc_pending_thumb_regen', true)['scheduled_at']]);
            }
            wpc_regen_thumbs_hook($imageID);
        }, 99);
        return true;
    }
    function wpc_arm_pending_thumb_regen_sweep()
    {
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled(WPC_THUMB_REGEN_SWEEP_HOOK)) {
            wp_schedule_single_event(time() + 60, WPC_THUMB_REGEN_SWEEP_HOOK);
        }
    }
    function wpc_regen_retry_ajax()
    {
        if (!current_user_can('upload_files') || !check_ajax_referer('wps_ic_nonce_action', 'nonce', false)) {
            wp_send_json_error(['msg' => 'forbidden'], 403);
        }
        $id = (int) ($_POST['imageID'] ?? 0);
        if ($id <= 0 || !get_post_meta($id, '_wpc_pending_thumb_regen', true)) {
            wp_send_json_success(['pending' => false]);
        }
        delete_transient('wpc_regen_thumbs_lock_' . $id);
        delete_transient('wpc_regen_active_count');
        wpc_regen_thumbs_hook($id);
        wp_send_json_success(['pending' => (bool) get_post_meta($id, '_wpc_pending_thumb_regen', true)]);
    }
    add_action('wp_ajax_wpc_regen_retry', 'wpc_regen_retry_ajax');
}

if (!function_exists('wpc_regen_thumbs_hook')) {
    function wpc_regen_thumbs_hook($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID || get_post_type($imageID) !== 'attachment') return;

        // Per-image concurrency lock — prevents two workers (e.g. loopback + admin_init drain)
        // from regenerating the same image's thumbnails simultaneously.
        $lock_key = 'wpc_regen_thumbs_lock_' . $imageID;
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 180);


        $cap = defined('WPC_MAX_CONCURRENT_REGEN') ? max(1, (int) WPC_MAX_CONCURRENT_REGEN) : 1;
        $active = (int) get_transient('wpc_regen_active_count');
        if ($active >= $cap) {
            // At cap. Release per-image lock so a future drain can reacquire.
            // post_meta `_wpc_pending_thumb_regen` stays set — self-chain or admin_init picks it up.
            delete_transient($lock_key);
            return;
        }
        set_transient('wpc_regen_active_count', $active + 1, 300);

        try {
            // Race-guard: user may have compressed again between restore and this worker firing.
            // If so, ic_status is no longer 'restored' — abort cleanly without regenerating.
            if (get_post_meta($imageID, 'ic_status', true) !== 'restored') {
                delete_post_meta($imageID, '_wpc_pending_thumb_regen');
                return;
            }

            $plan = get_post_meta($imageID, '_wpc_pending_thumb_regen', true);
            if (!is_array($plan)) return;

            $regenSource = $plan['regen_source'] ?? get_attached_file($imageID);
            if (!$regenSource || !file_exists($regenSource)) {
                delete_post_meta($imageID, '_wpc_pending_thumb_regen');
                // Unlock the "Restoring..." card UI even if regen aborts due to
                // missing source. Without this trigger, the card stays locked forever.
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored', 'time' => time()], 60);
                return;
            }

            @set_time_limit(180);
            wp_raise_memory_limit('image');

            // Suppress the on_upload auto-compress hook during regen — we don't want the
            // just-restored image to immediately re-compress.
            if (class_exists('wps_local_compress')) {
                wps_local_compress::unhook_upload();
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
                    // First miss — leave _wpc_pending_thumb_regen set so the chain re-fires this image
                    update_post_meta($imageID, '_wpc_regen_retry_attempts', $attempts + 1);
                    error_log('[WPC RegenThumbs] image=' . $imageID . ' duration=' . $regen_duration . 's cap=' . $cap . ' missing=' . implode(',', $missing) . ' retry_queued');
                } else {
                    // Already retried once and still missing — give up gracefully + loud log
                    delete_post_meta($imageID, '_wpc_pending_thumb_regen');
                    delete_post_meta($imageID, '_wpc_regen_retry_attempts');
                    error_log('[WPC RegenThumbs] image=' . $imageID . ' duration=' . $regen_duration . 's cap=' . $cap . ' FAILED after retry, missing=' . implode(',', $missing));
                    // Unlock the "Restoring..." card UI on give-up so the card
                    // doesn't stay locked forever after a failed regen attempt.
                    set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored', 'time' => time()], 60);
                }
            } else {
                // All sizes verified on disk — clean up retry counter + happy log
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
            // Decrement counter on EVERY exit (success, race-guard abort, exception).
            $current = (int) get_transient('wpc_regen_active_count');
            set_transient('wpc_regen_active_count', max(0, $current - 1), 300);
            delete_transient($lock_key);


            if (function_exists('wpc_chain_next_pending_regen')) {
                wpc_chain_next_pending_regen($imageID);
            }
        }
    }
    add_action('wpc_regen_thumbs', 'wpc_regen_thumbs_hook', 10, 1);

    // Layer 1 AJAX endpoint — loopback POST target
    function wpc_regen_thumbs_ajax()
    {
        $imageId = (int) ($_REQUEST['imageID'] ?? 0);
        if (!function_exists('wpc_loopback_token_is_valid')
            || !wpc_loopback_token_is_valid('wpc_regen_thumbs', $imageId, $_REQUEST['t'] ?? '')) {
            if (function_exists('status_header')) { status_header(403); }
            wp_die('', '', ['response' => 403]);
        }
        wpc_regen_thumbs_hook($imageId);
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
        // Pick oldest pending regen excluding the one that just finished (safety vs. stuck post_meta).
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

        // (a) Bunny CDN / pointer post_meta — set when service backs up source after first compress
        $backup_path = get_post_meta($imageID, 'wpc_backup_path', true);
        if (!empty($backup_path)) return true;

        // (b) /wpc-backups/<file> local backup folder
        $main = function_exists('get_attached_file') ? get_attached_file($imageID) : '';
        if ($main && defined('WP_CONTENT_DIR')) {
            $upload_dir = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
            if (is_array($upload_dir) && !empty($upload_dir['basedir'])) {
                $rel = ltrim(str_replace(rtrim($upload_dir['basedir'], '/'), '', $main), '/');
                $local_backup = WP_CONTENT_DIR . '/wpc-backups/' . $rel;
                if (file_exists($local_backup)) return true;
            }
        }

        // (c) Sibling _bkp file (legacy local backup pattern)
        if ($main && file_exists($main)) {
            $info = pathinfo($main);
            $bkp = $info['dirname'] . '/' . $info['filename'] . '_bkp.' . ($info['extension'] ?? 'jpg');
            if (file_exists($bkp)) return true;
        }

        return false;
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

            // The rungs go through the dispatch door, which refuses them (ladder-retired) until
            // decision D5 on the ladder is taken; this leg sends nothing. Before, it resized each
            // width here and POSTed the bytes to the v1 /optimize route, which answers 404.
            $result = wps_ic_image_optimize::dispatch($attachmentId, 'ladder', [
                'needed_widths'  => array_values(array_map('intval', (array) $widths)),
                'triggerContext' => 'ladder_' . $trigger_source,
            ]);
            $ok = !empty($result['ok']);

            $duration_ms = (int) round((microtime(true) - $t_start) * 1000);
            error_log('[WPC LazyLadder] image=' . $attachmentId . ' widths=' . count($widths) . ' dispatched=' . ($ok ? 1 : 0) . ' duration=' . $duration_ms . 'ms trigger=' . $trigger_source);

            if (function_exists('wpc_update_ladder_stats')) {
                wpc_update_ladder_stats([
                    'event'          => $ok ? 'success' : 'failed',
                    'duration_ms'    => $duration_ms,
                    'trigger_source' => $trigger_source,
                    'mode'           => 'lazy',
                ]);
            }

            return $ok;
        } finally {
            delete_transient($lock_key);
        }
    }
}



if (!function_exists('wpc_resolve_size_label_width')) {
    function wpc_resolve_size_label_width($size_label, $meta, $imageID = 0)
    {
        if (empty($size_label) || !is_array($meta)) return 0;

        // 'scaled' — the WP-attached file (post big_image_size_threshold)
        if ($size_label === 'scaled') {
            return (int) ($meta['width'] ?? 0);
        }

        // 'original' — the true unscaled original on disk (may be larger than meta['width'])
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

        // 'wpc_<N>' (legacy ladder convention)
        if (preg_match('/^wpc_(\d+)$/', $size_label, $m)) {
            return (int) $m[1];
        }

        // '<N>w' (newer ladder convention)
        if (preg_match('/^(\d+)w$/', $size_label, $m)) {
            return (int) $m[1];
        }

        // '<N>x<N>' (WP-registered sizes like '2048x2048', '1536x1536')
        if (preg_match('/^(\d+)x(\d+)$/', $size_label, $m)) {
            // Use registered size first if present (it has the actual aspect-fitted width)
            if (!empty($meta['sizes'][$size_label]['width'])) {
                return (int) $meta['sizes'][$size_label]['width'];
            }
            return (int) $m[1];
        }

        // WP-registered size by exact label match
        if (!empty($meta['sizes'][$size_label]['width'])) {
            return (int) $meta['sizes'][$size_label]['width'];
        }


        if ($size_label === 'thumb') {
            return (int) ($meta['sizes']['thumbnail']['width'] ?? 150);
        }

        return 0;
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

        // Pick base size_labels with WebP or JPEG but no AVIF, then resolve each to a width
        // that wpc_generate_ladder_widths accepts. Skip widths that exceed our source-on-disk.
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

// Layer 1 dispatcher — non-blocking loopback POST (0.1s timeout). Skipped on Basic-Auth sites.
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
            $rt_body  = http_build_query(['imageID' => (int) $imageID, 't' => wpc_loopback_token('wpc_regen_thumbs', $imageID)]);
            $rt_req   = "POST {$rt_path} HTTP/1.1\r\nHost: {$rt_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($rt_body) . "\r\nConnection: close\r\nUser-Agent: WPCRegenThumbs/1.0\r\n\r\n" . $rt_body;
            $rt_fp = wps_ic_ajax::wpc_loopback_open_socket($rt_host, $rt_port, $rt_https, 0.2);
            if ($rt_fp) { @stream_set_timeout($rt_fp, 0, 100000); @fwrite($rt_fp, $rt_req); @fclose($rt_fp); }
        }
    }
}


if (!function_exists('wpc_update_ladder_stats')) {
    function wpc_update_ladder_stats($event_data)
    {
        $stats = get_option('wpc_ladder_stats', []);
        if (!is_array($stats)) $stats = [];

        // Initialise missing fields so the option stays stable
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
                // 20-sample sliding window for p95 approximation
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
        // Deep-merge defaults (PHP 7.2+ compatible)
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

        // Timing — only record non-zero durations
        if ($duration_ms > 0) {
            $stats['timing']['samples']++;
            $stats['timing']['sum_ms'] += $duration_ms;
            if ($duration_ms > $stats['timing']['max_ms']) $stats['timing']['max_ms'] = $duration_ms;
            $stats['timing']['recent_ms'][] = $duration_ms;
            if (count($stats['timing']['recent_ms']) > 20) {
                $stats['timing']['recent_ms'] = array_slice($stats['timing']['recent_ms'], -20);
            }
        }

        // Trigger attribution
        $trigger_key = isset($stats['triggers'][$trigger]) ? $trigger : 'unknown';
        $stats['triggers'][$trigger_key]++;

        update_option('wpc_ladder_stats', $stats, false);
    }
}

/**
 * Phase 1 instrumentation — record peak queue depth when worker picks up work.
 */
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

/**
 * Compute p95 from the rolling 20-sample window (simple sort-and-pick).
 * Returns int ms or 0 if no samples.
 */
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

/**
 * Restore telemetry — cumulative stats mirroring wpc_ladder_stats structure.
 * Sources: local_bkp (_bkp files), cloud_bkp (/wpc-backups/), service (local-mc /restore).
 */
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

/**
 * Phase 2.5 instrumentation — log variant emission counts per render.
 * Counts how often each (attachment, width) pair appears in rendered srcset.
 */
if (!function_exists('wpc_log_variant_emitted')) {
    function wpc_log_variant_emitted($attachment_id, $widths)
    {
        // Rate-limit to avoid hammering options table on high-traffic sites
        $rate_key = 'wpc_emit_ratelimit_' . (int) $attachment_id;
        if (get_transient($rate_key)) return;
        set_transient($rate_key, 1, 300); // 5 min per attachment

        $counts = get_option('wpc_variant_emit_counts', []);
        if (!is_array($counts)) $counts = [];
        foreach ((array) $widths as $w) {
            $key = (int) $attachment_id . ':' . (int) $w;
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        // Cap to 10k keys to prevent options bloat
        if (count($counts) > 10000) {
            $counts = array_slice($counts, -10000, null, true);
        }
        update_option('wpc_variant_emit_counts', $counts, false);
    }
}

/**
 * Phase 1 activation pre-warm — homepage + top 5 pages scanned synchronously on toggle-on.
 * Generates ladder widths for up to 20 "large" images immediately so first visitor sees
 * optimized output. Bounded: max 20 images, 10s timeout per image.
 */
if (!function_exists('wpc_modern_delivery_prewarm')) {
    function wpc_modern_delivery_prewarm()
    {
        @set_time_limit(120);
        update_option('wpc_prewarm_status', ['state' => 'running', 'started_at' => time(), 'prewarmed' => 0], false);

        // Skip on Basic-Auth sites — page fetch will 401 and hang.
        // Shutdown + admin hook drain the queue as visitors browse instead.
        if (function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth()) {
            update_option('wpc_prewarm_status', ['state' => 'skipped_basic_auth', 'started_at' => time(), 'prewarmed' => 0], false);
            return 0;
        }

        // Pages to scan: homepage + sitemap top 5 (if available)
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

            // Fetch page server-side
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

            // Parse <img> tags
            if (!preg_match_all('#<img([^>]+)/?>#i', $html, $matches)) continue;

            foreach ($matches[1] as $attrs_str) {
                if ($prewarmed >= 20) break;

                // Extract src + class
                $src = '';
                $class = '';
                $width = 0;
                if (preg_match('#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $attrs_str, $m)) $src = $m[1];
                if (preg_match('#\bclass\s*=\s*["\']([^"\']+)["\']#i', $attrs_str, $m)) $class = $m[1];
                if (preg_match('#\bwidth\s*=\s*["\']?(\d+)#i', $attrs_str, $m)) $width = (int) $m[1];

                // Skip small images (never LCP candidates)
                if ($width > 0 && $width < 400) continue;
                if (empty($src)) continue;

                // Resolve to attachment ID
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

                // Find missing ladder widths for this attachment
                $missing_avif = class_exists('WPC_Modern_Delivery')
                    ? WPC_Modern_Delivery::find_missing_ladder_widths($aid, $meta, 'avif')
                    : [];
                $missing_webp = class_exists('WPC_Modern_Delivery')
                    ? WPC_Modern_Delivery::find_missing_ladder_widths($aid, $meta, 'webp')
                    : [];
                $missing = array_unique(array_merge($missing_avif, $missing_webp));
                if (empty($missing)) continue;

                // Synchronously generate (blocks activation — user expects progress)
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

/**
 * Get top page URLs for pre-warm — sitemap, front-page children, recent posts.
 */
if (!function_exists('wpc_get_prewarm_candidate_urls')) {
    function wpc_get_prewarm_candidate_urls($limit = 5)
    {
        $urls = [];

        // Recent posts (most likely to have hero images and get traffic)
        $posts = get_posts([
            'numberposts' => $limit,
            'post_status' => 'publish',
            'orderby'     => 'date',
            'order'       => 'DESC',
        ]);
        foreach ($posts as $p) {
            $urls[] = get_permalink($p->ID);
        }

        // WooCommerce shop page if active
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
    private static $settings;
    private static $hook_owner;
    private static $queue_lock_token = '';
    private static $queue_mutex_value = '';

    const QUEUE_CRON = 'wpc_compress_queue_cron';
    const QUEUE_LOCK = 'wpc_compress_worker_lock';
    const QUEUE_LOCK_TTL = 300;
    const QUEUE_MAX_TRIES = 2;
    const QUEUE_MUTEX = 'wpc_compress_queue_mutex';
    const QUEUE_MUTEX_TTL = 5;
    const QUEUE_ADD_ROW = 'wpc_compress_queue_add_';
    const QUEUE_ADD_TRIES = 3;
    const QUEUE_REMOVE_TRIES = 600;


    /**
     * Reads the settings the methods below consult. Nothing else: no file, no directory, no
     * HTTP call and no hook, because a request constructs this class many times (core init,
     * the front-end router, rest_api_init, every ajax handler and drain that restores or
     * backs up). Hooks are register_hooks()'s job.
     */
    public function __construct()
    {
        self::$allowed_types = ['jpg' => 'jpg', 'jpeg' => 'jpeg', 'gif' => 'gif', 'png' => 'png'];
        self::$settings = get_option(WPS_IC_SETTINGS);

        if (empty(self::$settings)) {
            $options = new wps_ic_options();
            $settings = $options->get_preset('lite');
            self::$settings = $settings;
        }

        if (!isset(self::$settings['optimization'])) {
            self::$settings['optimization'] = '';
        }
    }

    /**
     * Hooks the upload, image-edit and delete handlers once per request, on one instance.
     * Rule: registration is idempotent. Observed failure: the constructor registered them, and
     * WordPress keys an object callback by instance, so every construction in a request added
     * another on_upload; one upload in an admin request dispatched once per instance.
     */
    public static function register_hooks()
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        $owner = new self();
        self::$hook_owner = $owner;

        add_action('delete_attachment', [$owner, 'on_delete']);

        $on_upload_mode = function_exists('wpc_get_optimization_mode')
            ? wpc_get_optimization_mode()
            : 'legacy';
        $on_upload_enabled = ($on_upload_mode === 'legacy')
            && empty($_GET['restoreImage'])
            && !(function_exists('wpc_auto_encoding_disabled') && wpc_auto_encoding_disabled());

        if ($on_upload_enabled) {
            add_filter('wp_generate_attachment_metadata', [$owner, 'on_upload'], PHP_INT_MAX, 2);
        }

        add_filter('wp_update_attachment_metadata', [$owner, 'wpc_reoptimize_edited_image'], 99, 2);
        add_action('wpc_reoptimize_edited_image_event', [$owner, 'wpc_run_edited_reoptimize'], 10, 1);
    }

    /**
     * Unhooks on_upload for the rest of this request. Rule: a pass that regenerates an
     * attachment's metadata itself (restore, the thumbnail regen, the compress worker) must
     * not re-enter the upload compress. The hook lives on register_hooks()'s instance, so
     * removing [$this, 'on_upload'] from any other instance matches nothing and the restored
     * image would be compressed again by its own regeneration.
     */
    public static function unhook_upload()
    {
        if (self::$hook_owner) {
            remove_filter('wp_generate_attachment_metadata', [self::$hook_owner, 'on_upload'], PHP_INT_MAX);
        }
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
    }


    /**
     * The loopback's end of the compress queue: answers `queued` at once (the caller waits for
     * it, fireQueueWorker) and releases the client, then drains the queue.
     */
    public function wpc_handle_async_compress(\WP_REST_Request $request) {
        @ignore_user_abort(true);
        http_response_code(200);
        echo 'queued';
        if (!(function_exists('wpc_finish_request') && wpc_finish_request())) {
            while (ob_get_level() > 0 && @ob_end_flush()) {}
            @flush();
        }
        $this->runQueue('upload');
        exit;
    }

    /**
     * Sequential queue worker: takes the worker lock, then processes one image at a time until
     * the queue is empty. The loopback, the cron event and the admin drain all start it here, so
     * one worker runs at a time. The lock is refreshed before an image, before its dispatch and
     * after it; a worker that finds the lock taken over stops, and when that is before the
     * dispatch the image is left, in flight, to the worker that holds the lock. A worker takes
     * an image at most once: one still at the head after it was processed (its removal did not
     * reach the database) stops the worker. An image leaves the queue only when its processing
     * is done; an image a dead worker held is tried once more, then dropped. False when another
     * worker holds the lock.
     */
    public function runQueue($source = 'upload') {
        self::unhook_upload();

        if (!self::queue_lock_take()) {
            error_log('[WPC Queue] Worker blocked — lock already held');
            return false;
        }

        $workerStart = microtime(true);
        $processed = 0;
        error_log('[WPC Queue] Worker started via ' . $source . '. Queue: ' . json_encode(self::queue_ids()));

        $owned = true;
        $taken = [];
        try {
            while (($owned = self::queue_lock_touch()) && ($imageID = self::queue_next()) > 0) {
                if (isset($taken[$imageID])) {
                    error_log('[WPC Queue] image=' . $imageID . ' is still queued after this worker processed it; stopping, the next worker takes it');
                    break;
                }
                $taken[$imageID] = true;
                $remaining = max(0, count(self::queue_ids()) - 1);
                $queuedAt = 0;
                $trans = get_transient('wps_ic_compress_' . $imageID);
                if ($trans && is_array($trans) && !empty($trans['time'])) {
                    $queuedAt = time() - intval($trans['time']);
                }

                error_log('[WPC Queue] Processing image=' . $imageID . ' position=' . ($processed + 1) . ' remaining=' . $remaining . ' waited=' . $queuedAt . 's');

                $imgStart = microtime(true);
                try {
                    $backupOk = $this->backup_all_sizes($imageID);
                    if (!$backupOk) {
                        error_log('[WPC Queue] SKIPPED image=' . $imageID . ' — backup failed, will not compress');
                    } elseif (!($owned = self::queue_lock_touch())) {
                        error_log('[WPC Queue] image=' . $imageID . ' left to the worker that took the lock over, before its dispatch');
                        break;
                    } else {
                        $this->singleCompressV4($imageID, 'silent', true, 'upload');
                    }
                } catch (\Exception $e) {
                    error_log('[WPC Queue] Exception image=' . $imageID . ': ' . $e->getMessage());
                } catch (\Error $e) {
                    error_log('[WPC Queue] Fatal error image=' . $imageID . ': ' . $e->getMessage());
                }
                $owned = self::queue_lock_touch();
                $imgElapsed = round(microtime(true) - $imgStart, 2);

                $status = get_post_meta($imageID, 'ic_status', true) ?: 'failed';
                $savings = get_post_meta($imageID, 'ic_savings', true) ?: '0';
                error_log('[WPC Queue] Done image=' . $imageID . ' status=' . $status . ' savings=' . $savings . '% time=' . $imgElapsed . 's');

                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);

                if ($status !== 'compressed') {
                    set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 300);
                }

                $done = self::queue_done($imageID);
                $processed++;
                if (!$done || !$owned) {
                    break;
                }
            }
        } finally {
            self::queue_lock_release();
        }

        $totalElapsed = round(microtime(true) - $workerStart, 2);
        if (!$owned) {
            error_log('[WPC Queue] Worker stopped: another worker holds the lock');
            return $processed;
        }
        error_log('[WPC Queue] Worker done. Processed=' . $processed . ' total_time=' . $totalElapsed . 's');

        if (self::queue_ids()) {
            self::queue_cron_arm();
        }

        return $processed;
    }

    /** The cron event's end of the queue: a worker runs, or a live worker is asked about again in a minute. */
    public static function queue_cron_run() {
        @ignore_user_abort(true);
        if (!self::queue_ids()) {
            return;
        }
        $local = new self();
        if ($local->runQueue('cron') === false) {
            self::queue_cron_arm(60);
        }
    }

    /** Queues the one cron event that runs the worker in-process when no loopback reaches this site. */
    public static function queue_cron_arm($delay = 0) {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_single_event')) {
            return false;
        }
        if (!wp_next_scheduled(self::QUEUE_CRON)) {
            wp_schedule_single_event(time() + max(0, (int) $delay), self::QUEUE_CRON);
        }
        return true;
    }

    /**
     * The queue, read past the object cache (the worker runs in another process than the page that
     * adds to it): the stored list, then every add recorded beside it that a write has not folded
     * in yet.
     */
    public static function queue_ids() {
        $queue = self::queue_stored();
        foreach (array_keys(self::queue_added_rows()) as $imageID) {
            if (!in_array($imageID, $queue)) {
                $queue[] = $imageID;
            }
        }
        return $queue;
    }

    /** The stored id list alone, read past the object cache. */
    private static function queue_stored() {
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('wpc_compress_queue', 'options');
        }
        $queue = get_option('wpc_compress_queue', []);
        return is_array($queue) ? array_values($queue) : [];
    }

    /** The adds recorded beside the list (one option row per image, QUEUE_ADD_ROW . id): [id => time queued], oldest first. */
    private static function queue_added_rows() {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            return [];
        }
        $rows = (array) $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC", $wpdb->esc_like(self::QUEUE_ADD_ROW) . '%'), ARRAY_A);
        $added = [];
        foreach ($rows as $row) {
            $imageID = (int) substr((string) (isset($row['option_name']) ? $row['option_name'] : ''), strlen(self::QUEUE_ADD_ROW));
            if ($imageID > 0) {
                $added[$imageID] = (int) (isset($row['option_value']) ? $row['option_value'] : 0);
            }
        }
        return $added;
    }

    /**
     * The one writer of the stored queue: under the queue mutex it folds in the adds recorded beside
     * the list, applies $change(list, times) => [list, times], writes both, then deletes the rows it
     * folded in. False when the mutex could not be had in $tries tries; nothing is written then.
     */
    private static function queue_write(callable $change, $tries) {
        global $wpdb;
        if (!self::queue_mutex_take($tries)) {
            return false;
        }
        try {
            $queue = self::queue_stored();
            $times = get_option('wpc_compress_queue_times', []);
            $times = is_array($times) ? $times : [];
            $added = self::queue_added_rows();
            foreach ($added as $imageID => $at) {
                if (!in_array($imageID, $queue)) {
                    $queue[] = $imageID;
                }
                if (empty($times[$imageID])) {
                    $times[$imageID] = $at > 0 ? $at : time();
                }
            }
            list($queue, $times) = $change($queue, $times);
            $queue = array_values($queue);
            update_option('wpc_compress_queue', $queue, false);
            update_option('wpc_compress_queue_times', array_intersect_key($times, array_flip(array_map('intval', $queue))), false);
            foreach (array_keys($added) as $imageID) {
                $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", self::QUEUE_ADD_ROW . $imageID));
            }
        } finally {
            self::queue_mutex_release();
        }
        return true;
    }

    /**
     * Takes the queue mutex: an INSERT IGNORE of its row, at most $tries times 10 ms apart; a mutex
     * older than QUEUE_MUTEX_TTL is a dead writer's and is taken over by a compare-and-swap. A
     * statement the database refused ends the tries at once.
     */
    private static function queue_mutex_take($tries) {
        global $wpdb;
        $token = md5(uniqid((string) mt_rand(), true));
        for ($attempt = 0; $attempt < max(1, (int) $tries); $attempt++) {
            if ($attempt > 0) {
                usleep(10000);
            }
            $now = time();
            $mine = $token . ':' . $now;
            $inserted = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::QUEUE_MUTEX, $mine));
            if ($inserted === false) {
                return false;
            }
            if ((int) $inserted === 1) {
                self::$queue_mutex_value = $mine;
                return true;
            }
            $held = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::QUEUE_MUTEX));
            if ($held !== '' && (int) substr((string) strrchr($held, ':'), 1) <= $now - self::QUEUE_MUTEX_TTL
                && (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $mine, self::QUEUE_MUTEX, $held)) === 1) {
                self::$queue_mutex_value = $mine;
                return true;
            }
        }
        return false;
    }

    private static function queue_mutex_release() {
        global $wpdb;
        if (self::$queue_mutex_value === '') {
            return;
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", self::QUEUE_MUTEX, self::$queue_mutex_value));
        self::$queue_mutex_value = '';
    }

    /**
     * The one writer that adds an image to the queue; it records when the image was queued. It
     * never waits: when the queue mutex is busy after QUEUE_ADD_TRIES tries (about 20 ms) the add
     * is recorded beside the list as its own option row, which every reader counts and the next
     * write folds in.
     */
    public static function queue_add($imageID) {
        global $wpdb;
        $imageID = (int) $imageID;
        if ($imageID <= 0) {
            return;
        }
        $now = time();
        $written = self::queue_write(function ($queue, $times) use ($imageID, $now) {
            if (!in_array($imageID, $queue)) {
                $queue[] = $imageID;
            }
            if (empty($times[$imageID])) {
                $times[$imageID] = $now;
            }
            return [$queue, $times];
        }, self::QUEUE_ADD_TRIES);
        if (!$written) {
            $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::QUEUE_ADD_ROW . $imageID, (string) $now));
        }
    }

    /** Empties the queue: the list, the times and every add recorded beside it. */
    public static function queue_clear() {
        global $wpdb;
        delete_option('wpc_compress_queue');
        delete_option('wpc_compress_queue_times');
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(self::QUEUE_ADD_ROW) . '%'));
    }

    /**
     * The image at the head of the queue, marked in flight, or 0 when the queue is empty. The
     * image stays in the queue until queue_done(); an image that was in flight when its worker
     * died comes back once, and the second time it is dropped with a receipt.
     */
    public static function queue_next() {
        for ($guard = 0; $guard < 1000; $guard++) {
            $queue = self::queue_ids();
            if (!$queue) {
                delete_option('wpc_compress_inflight');
                return 0;
            }
            $head = $queue[0];
            $imageID = (int) $head;
            if ($imageID <= 0 || get_post_type($imageID) !== 'attachment') {
                error_log('[WPC Queue] Skipping invalid ID=' . $imageID);
                delete_transient('wps_ic_compress_' . $imageID);
                self::queue_remove($head);
                continue;
            }
            $held = get_option('wpc_compress_inflight', []);
            $tries = (is_array($held) && (int) (isset($held['id']) ? $held['id'] : 0) === $imageID)
                ? (int) (isset($held['tries']) ? $held['tries'] : 0) + 1 : 1;
            if ($tries > self::QUEUE_MAX_TRIES) {
                self::queue_remove($head);
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 300);
                self::queue_journal('worker-died', ['id' => $imageID, 'tries' => $tries - 1]);
                continue;
            }
            update_option('wpc_compress_inflight', ['id' => $imageID, 'tries' => $tries, 'at' => time()], false);
            return $imageID;
        }
        return 0;
    }

    /** An image's processing is over: it leaves the queue. False when the queue could not be written. */
    public static function queue_done($imageID) {
        return self::queue_remove((int) $imageID);
    }

    /**
     * The one remover: takes an image out of the queue (processed, deleted, restored, invalid) and
     * clears its in-flight mark. It waits for the queue mutex up to QUEUE_REMOVE_TRIES tries (6 s,
     * past QUEUE_MUTEX_TTL, so a dead writer's mutex is always taken over). False when the queue
     * could not be written.
     */
    public static function queue_remove($entry) {
        $written = self::queue_write(function ($queue, $times) use ($entry) {
            $left = [];
            foreach ($queue as $queued) {
                if ((string) $queued !== (string) $entry) {
                    $left[] = $queued;
                }
            }
            return [$left, $times];
        }, self::QUEUE_REMOVE_TRIES);
        if (!$written) {
            return false;
        }
        $held = get_option('wpc_compress_inflight', []);
        if (is_array($held) && isset($held['id']) && (string) $held['id'] === (string) $entry) {
            delete_option('wpc_compress_inflight');
        }
        return true;
    }

    /** Takes the worker lock: true for exactly one caller at a time, a dead worker's lock (older than QUEUE_LOCK_TTL) included. */
    public static function queue_lock_take() {
        global $wpdb;
        if (self::$queue_lock_token === '') {
            self::$queue_lock_token = md5(uniqid((string) mt_rand(), true));
        }
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $now  = time();
            $mine = self::$queue_lock_token . ':' . $now;
            if ((int) $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::QUEUE_LOCK, $mine)) === 1) {
                return true;
            }
            $held = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::QUEUE_LOCK));
            if ($held === '') {
                continue;
            }
            if ((int) substr((string) strrchr($held, ':'), 1) > $now - self::QUEUE_LOCK_TTL) {
                return false;
            }
            return (int) $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $mine, self::QUEUE_LOCK, $held)) === 1;
        }
        return false;
    }

    /** The holder of the lock says it is alive; false when the lock is no longer this worker's. */
    public static function queue_lock_touch() {
        global $wpdb;
        if (self::$queue_lock_token === '') {
            return false;
        }
        $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", self::$queue_lock_token . ':' . time(), self::QUEUE_LOCK, $wpdb->esc_like(self::$queue_lock_token) . ':%'));
        $held = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::QUEUE_LOCK));
        return strpos($held, self::$queue_lock_token . ':') === 0;
    }

    public static function queue_lock_release() {
        global $wpdb;
        if (self::$queue_lock_token === '') {
            return;
        }
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::QUEUE_LOCK, $wpdb->esc_like(self::$queue_lock_token) . ':%'));
    }

    /** Whether a worker holds the lock (a lock older than QUEUE_LOCK_TTL is a dead worker's). */
    public static function queue_worker_running() {
        global $wpdb;
        $held = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::QUEUE_LOCK));
        return $held !== '' && (int) substr((string) strrchr($held, ':'), 1) > time() - self::QUEUE_LOCK_TTL;
    }

    private static function queue_oldest_age(array $queue) {
        $times = get_option('wpc_compress_queue_times', []);
        $times = (is_array($times) ? $times : []) + self::queue_added_rows();
        $oldest = 0;
        foreach ($queue as $queued) {
            $at = (is_array($times) && isset($times[(int) $queued])) ? (int) $times[(int) $queued] : 0;
            if ($at > 0 && ($oldest === 0 || $at < $oldest)) {
                $oldest = $at;
            }
        }
        return $oldest > 0 ? max(0, time() - $oldest) : 0;
    }

    /** Receipt `compress-queue-stalled {reason, queued, oldest_age, ...}`, one line per reason per 10 minutes. */
    private static function queue_journal($reason, array $fields = []) {
        $reason = (string) $reason;
        $seen = get_transient('wpc_compress_stalled_seen');
        $seen = is_array($seen) ? $seen : [];
        if (isset($seen[$reason]) && (int) $seen[$reason] > time() - 10 * MINUTE_IN_SECONDS) {
            return;
        }
        $seen[$reason] = time();
        set_transient('wpc_compress_stalled_seen', $seen, 10 * MINUTE_IN_SECONDS);
        $queue = self::queue_ids();
        $fields = ['reason' => $reason, 'queued' => count($queue), 'oldest_age' => self::queue_oldest_age($queue)] + $fields;
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('compress-queue-stalled', '', '', $fields);
        }
        error_log('[WPC Queue] stalled reason=' . $reason);
    }

    /** The loopback did not start a worker: say why, and let the cron run it in-process. */
    private static function queue_fallback($reason, array $fields = []) {
        self::queue_journal($reason, $fields);
        self::queue_cron_arm();
        if (!(defined('DOING_CRON') && DOING_CRON) && !(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) && function_exists('spawn_cron')) {
            spawn_cron();
        }
        return false;
    }

    /**
     * Start the queue worker through a loopback POST and wait (at most `wpc_loopback_confirm_timeout`,
     * 2.5 s) for it to answer `queued`. Any other outcome (no socket, no answer, a non-2xx answer,
     * a page that is not the worker's) is not a started worker: it journals
     * `compress-queue-stalled {reason}` and queues the cron event that runs the worker in-process.
     * A loopback that went unanswered is not asked again for 10 minutes. On a visitor render the
     * fire waits for the response to be released, or goes to the cron where it cannot be. True
     * when the worker answered.
     */
    public function fireQueueWorker() {
        if (!self::queue_ids() || self::queue_worker_running()) return false;

        $api_key = $this->getApiKey();
        if ($api_key === '') return false;

        if (function_exists('wpc_render_guard_active') && wpc_render_guard_active()) {
            $released = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
            if (!($released && function_exists('wpc_net_defer') && wpc_net_defer('compress-queue-worker', [$this, 'fireQueueWorker']))) {
                self::queue_cron_arm();
            }
            return false;
        }

        if (get_option('wpc_loopback_status', '') === 'fail') {
            return self::queue_fallback('loopback-failed');
        }
        if ((int) get_transient('wpc_compress_loopback_unconfirmed') > 0) {
            return self::queue_fallback('loopback-unconfirmed');
        }

        $qw_parts = wp_parse_url(rest_url('wpc/v1/compress-async'));
        if (empty($qw_parts['host']) || !class_exists('wps_ic_ajax')
            || !method_exists('wps_ic_ajax', 'wpc_loopback_open_socket') || !method_exists('wps_ic_ajax', 'wpc_loopback_read_answer')) {
            return self::queue_fallback('no-loopback');
        }
        $qw_https = (!empty($qw_parts['scheme']) && $qw_parts['scheme'] === 'https');
        $qw_port  = !empty($qw_parts['port']) ? (int) $qw_parts['port'] : ($qw_https ? 443 : 80);
        $qw_host  = (string) $qw_parts['host'];
        $qw_path  = (!empty($qw_parts['path']) ? $qw_parts['path'] : '/') . (!empty($qw_parts['query']) ? '?' . $qw_parts['query'] : '');
        $qw_req   = "POST {$qw_path} HTTP/1.1\r\nHost: {$qw_host}\r\nx-api-key: {$api_key}\r\nContent-Length: 0\r\nConnection: close\r\nUser-Agent: WPCQueueWorker/1.0\r\n\r\n";
        $qw_fp = wps_ic_ajax::wpc_loopback_open_socket($qw_host, $qw_port, $qw_https, 0.2);
        if (!$qw_fp) {
            return self::queue_fallback('loopback-refused', ['host' => $qw_host]);
        }
        @fwrite($qw_fp, $qw_req);
        $qw_answer = wps_ic_ajax::wpc_loopback_read_answer($qw_fp, (float) apply_filters('wpc_loopback_confirm_timeout', 2.5, 'wpc_compress_async', 0));
        @fclose($qw_fp);
        if (!wps_ic_ajax::wpc_loopback_answer_queued($qw_answer)) {
            set_transient('wpc_compress_loopback_unconfirmed', time(), 10 * MINUTE_IN_SECONDS);
            $qw_status = preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $qw_answer, $qw_m) ? (int) $qw_m[1] : 0;
            return self::queue_fallback('loopback-unconfirmed', ['host' => $qw_host, 'status' => $qw_status]);
        }
        delete_transient('wpc_compress_loopback_unconfirmed');
        return true;
    }

    /** fireQueueWorker after the response is released, where it can be; the upload never waits for the answer. */
    public function fireQueueWorkerAfterResponse() {
        $releasable = function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request');
        if (!$releasable || !function_exists('wpc_finish_request') || !function_exists('add_action')) {
            return $this->fireQueueWorker();
        }
        if (empty($GLOBALS['wpc_compress_fire_armed'])) {
            $GLOBALS['wpc_compress_fire_armed'] = true;
            $self = $this;
            add_action('shutdown', function () use ($self) {
                wpc_finish_request();
                @ignore_user_abort(true);
                $self->fireQueueWorker();
            }, PHP_INT_MAX - 1);
        }
        return false;
    }

    // ─── Backup image files to /wpc-backups/ before compression ────────


    public function wait_for_regen_or_clear_stale($imageID, $max_wait_sec = 15)
    {
        $imageID = (int) $imageID;
        if ($imageID <= 0) return true;
        $max_wait_sec = max(1, (int) $max_wait_sec);

        $start = microtime(true);
        $poll_interval_us = 250000; // 250ms polls
        $checked = 0;

        while ((microtime(true) - $start) < $max_wait_sec) {
            $marker = get_post_meta($imageID, '_wpc_pending_thumb_regen', true);
            if (empty($marker)) {
                // Regen finished (or never had one). Done.
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

        // Budget exhausted, marker still fresh. Proceed anyway but log loudly so support
        // can correlate any incomplete-filenames symptom.
        error_log('[WPC RegenWait] image=' . $imageID . ' BUDGET EXHAUSTED after ' .
                  $max_wait_sec . 's, proceeding with current disk state');
        return false;
    }

    /**
     * Back up an image's files before compression rewrites them in place. True when the image
     * may be compressed (backed up, or no local backup is wanted). The bool answer of
     * backup_result(), for the callers that only need go / no-go.
     */
    public function backup_all_sizes($imageID) {
        return $this->backup_result($imageID) === 'ok';
    }

    /**
     * The backup's outcome: 'ok' (backed up, or no local backup is wanted), 'source-missing'
     * (neither the scaled nor the unscaled file is on disk, so there is nothing to back up and
     * nothing to compress; wps_ic_image_library::source_state()), or 'failed' (the backup folder
     * is not writable or a copy did not land). Rule: a missing source is not a failed attempt,
     * a retry cannot bring the file back. Observed (ticket 12006): 39 images whose 2024 files
     * were gone logged `files=0 main=FAIL` on every bulk run, and each run counted an attempt
     * until they were parked for seven days.
     */
    public function backup_result($imageID) {
        $backupMode = self::$settings['backup'] ?? 'full';
        if (function_exists('wpc_should_rewrite_jpeg_inplace') && !wpc_should_rewrite_jpeg_inplace()) {
            return 'ok';
        }

        // 'off' = no backup, compression is permanent — proceed without backup
        if ($backupMode === 'off') {
            error_log('[WPC Backup] image=' . $imageID . ' mode=off — skipped');
            return 'ok';
        }

        // 'cloud' = skip local backup — rely on service cloud backup only
        if ($backupMode === 'cloud') {
            update_post_meta($imageID, 'wpc_backup_mode', 'cloud');
            error_log('[WPC Backup] image=' . $imageID . ' mode=cloud — local skipped');
            return 'ok';
        }

        // 'originals' or 'full' or 'local' or 'local-cloud' (legacy values) = local backup
        $backupBase = WP_CONTENT_DIR . '/wpc-backups/';
        $uploadDir = wp_upload_dir()['basedir'];
        $filesCopied = 0;
        $mainBackedUp = false;
        $backupFull = ($backupMode === 'full' || $backupMode === 'local-cloud');

        if (class_exists('wps_ic_image_library') && wps_ic_image_library::source_state($imageID) === 'missing') {
            wps_ic_image_library::seen_source($imageID, 'missing');
            error_log('[WPC Backup] image=' . $imageID . ' source missing on disk - nothing to back up');
            return 'source-missing';
        }

        // Verify backup directory is writable
        $testDir = $backupBase . 'test_' . $imageID;
        if (!wp_mkdir_p($testDir)) {
            error_log('[WPC Backup] FAILED — backup directory not writable: ' . $backupBase);
            return 'failed';
        }
        @rmdir($testDir);

        // Unscaled original (the real camera file) — ALWAYS backed up for local modes
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
                    return 'failed';
                }
            } else {
                $mainBackedUp = true;
            }
        }

        // Scaled version — backed up for 'full' and 'local'/'local-cloud' modes
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
                        return 'failed';
                    }
                } else {
                    $mainBackedUp = true;
                }
            }
        }

        // Thumbnails — only for 'full' mode (non-critical, don't block on failure)
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

        // Store backup metadata for restore
        $mainFile = $scaled ?: $unscaled;
        if ($mainFile) {
            update_post_meta($imageID, 'wpc_backup_path', str_replace($uploadDir . '/', '', $mainFile));
        }
        update_post_meta($imageID, 'wpc_backup_mode', $backupMode);

        error_log('[WPC Backup] image=' . $imageID . ' mode=' . $backupMode . ' files=' . $filesCopied . ' main=' . ($mainBackedUp ? 'OK' : 'FAIL'));
        return $mainBackedUp ? 'ok' : 'failed';
    }

    public function wpc_permission_api_key(\WP_REST_Request $request) {
        // Read header-based key (preferred)
        $provided = $request->get_header('x-api-key');

        // Fallback: Authorization: Bearer <key>
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

    /**
     * Prefer defining the key in wp-config.php:
     *   define('WPC_API_KEY', 'your-long-random-secret');
     * Or set an option 'wpc_api_key'.
     */
    public function wpc_get_expected_api_key($apikey) {
        $options = get_option(WPS_IC_OPTIONS);
         $expected_token = $options['api_key'];

        if (empty($apikey) || $apikey !== $expected_token) {
            wp_send_json_error('Unauthorized: apikey ' . $apikey, 403);
        }


        $this->raiseLimits();
        return $expected_token;
    }

    /**
     * Main handler: returns original, thumb, filesizes (and unscaled if present).
     */
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

        // Save OLD post meta for restore usage (once)
        if (!get_post_meta($image_id, 'wpc_old_meta', true)) {
            $oldMeta = wp_get_attachment_metadata($image_id);
            if (!empty($oldMeta)) {
                update_post_meta($image_id, 'wpc_old_meta', $oldMeta);
            }
        }

        // Top-level fields
        $original = wp_get_attachment_url($image_id);
        $thumbArr = wp_get_attachment_image_src($image_id, 'thumbnail');
        $thumb    = is_array($thumbArr) && !empty($thumbArr[0]) ? $thumbArr[0] : '';

        // Build filesizes from attachment metadata (includes all custom sizes)
        $filesizes  = [];
        $meta       = wp_get_attachment_metadata($image_id);
        $uploads    = wp_upload_dir();

        if (!empty($meta) && !empty($meta['file'])) {
            // Base directory like "2025/08"
            $subdir   = ltrim(dirname($meta['file']), './\\');
            $baseUrl  = trailingslashit($uploads['baseurl']) . ($subdir ? trailingslashit($subdir) : '');

            // Every generated intermediate size that exists on disk
            if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $sizeName => $info) {
                    if (!empty($info['file'])) {
                        // Preserve the size key EXACTLY as stored in metadata (even if it has spaces)
                        $filesizes[$sizeName] = $baseUrl . $info['file'];
                    }
                }
            }

            // Add "unscaled" if a non -scaled original exists
            if (!empty($original)) {
                $origRelPath = $meta['file']; // e.g. 2025/08/file-scaled.jpeg
                if (strpos($origRelPath, '-scaled.') !== false) {
                    $unscaledRel = str_replace('-scaled.', '.', $origRelPath);
                    $unscaledAbs = path_join($uploads['basedir'], $unscaledRel);
                    if (file_exists($unscaledAbs)) {
                        $filesizes['unscaled'] = trailingslashit($uploads['baseurl']) . $unscaledRel;
                    }
                }
            }
        }

        // Ensure "thumbnail" key is present in filesizes (nice to have)
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


    /**
     * Raise PHP / Server Limits
     * @return void
     */
    public function raiseLimits() {
        wp_raise_memory_limit('image');
        ini_set('memory_limit', '1024M');
    }



    /**
     * Delete WebP once Image Gets Deleted
     * @param $post_id
     * @return void
     */
    public function on_delete($post_id)
    {
        // Delete webP if exists
        $imagesCompressed = get_post_meta($post_id, 'wpc_images_compressed', true);
        if (!empty($imagesCompressed) && is_array($imagesCompressed)) {
            foreach ($imagesCompressed as $image => $data) {
                if (!empty($data['webp_path']) && file_exists($data['webp_path'])) {
                    unlink($data['webp_path']);
                }
            }
        }

        // AVIF sibling cleanup + trigger-state transient/queue cleanup (G10)
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
        if (in_array($post_id, self::queue_ids())) {
            self::queue_remove($post_id);
        }
    }


    public function is_supported($imageID)
    {
        $file_data = get_attached_file($imageID);
        $type = wp_check_filetype($file_data);

        // Is file extension allowed
        if (!in_array(strtolower($type['ext']), self::$allowed_types)) {
            return false;
        } else {
            return true;
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

        // Pre-empt any pending ladder backfill. The full compress will deliver
        // every variant the ladder would have backfilled, so the ladder fire is redundant.
        self::preempt_ladder_for($imageID);

        // Save metadata to DB now so the async process can read filenames.
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
        wp_update_attachment_metadata($imageID, $data);
        add_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX, 2);

        update_post_meta($imageID, 'wpc_old_meta', $data);

        // Mark as queued so media library shows spinner
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'queued', 'time' => time()], 300);

        // Add to sequential queue
        self::queue_add($imageID);

        $queueSize = count(self::queue_ids());
        $workerRunning = self::queue_worker_running() ? 'YES' : 'NO';
        error_log('[WPC Queue] on_upload image=' . $imageID . ' queue_size=' . $queueSize . ' worker_running=' . $workerRunning . ' elapsed=' . round(microtime(true) - $t0, 3) . 's');

        // Start worker if not already running
        $this->fireQueueWorkerAfterResponse();

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

        // Auto-optimization must be on for this mode (skips Manual + any kill-switch),
        // mirroring on_upload's policy so an edit follows the same rule as a fresh upload.
        if (function_exists('wpc_auto_encoding_disabled') && wpc_auto_encoding_disabled()) {
            return $data;
        }

        // Skip mid-restore / post-restore-regen cycles (mirror on_upload's guards) so an
        // edit triggered by restore thumb-regen does not clobber just-restored pristine bytes.
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
        if (class_exists('wps_local_compress')) {
            $bk = new wps_local_compress();
            if (method_exists($bk, 'backup_all_sizes')) {
                $bk->backup_all_sizes($attachment_id);
            }
        }
        wps_ic_image_optimize::dispatch($attachment_id, 'edit', ['resubmit_reason' => 'user_recompress']);
    }

    // ─── Loopback health check ─────────────────────────────────

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

        // Is the image type supported
        if (!$this->is_supported($imageID)) {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-not-supported']);
            } else {
                return 'file-not-supported';
            }
        }

        // Is the image already Compressed
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
            && class_exists('wps_ic_ajax')) {
            // The source names who asked: the upload hook and its queue are automatic, the
            // bulk worker is the bulk run, the Compress button's fallback is the person.
            $dispatch_reasons = ['upload' => 'upload', 'page-load-drain' => 'upload', 'bulk' => 'bulk', 'single' => 'ml-button'];
            $v2_result = wps_ic_image_optimize::dispatch($imageID, $dispatch_reasons[$source] ?? 'upload');
            if (!empty($v2_result['ok'])) {
                // v2 success. Clear queue transients (we're done with this image).
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
            // Clean up so the queue worker doesn't loop forever on this image.
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);
            if ($output === 'json') {
                wp_send_json_error(['msg' => 'v2-failed', 'detail' => $v2_result['error'] ?? 'unknown']);
            }
            return 'v2-failed';
        }

        // The v2 orchestrator is the only compression service. Reached only when its client is
        // not loaded; the v1 upload to /optimize that stood here answered 404 on every call.
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wps_ic_queue_' . $imageID);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-compress-refused', '', '', ['id' => (int) $imageID, 'reason' => 'v2-client-unavailable', 'source' => $source]);
        }
        if ($output === 'json') {
            wp_send_json_error(['msg' => 'v2-failed', 'detail' => 'v2-client-unavailable']);
        }
        return 'v2-failed';
    }


    public function restoreV4($imageID)
    {
        $t_total = microtime(true);
        error_log('[WPC Restore] START image=' . $imageID);


        if (get_transient('wpc_restoring_' . $imageID)) {
            error_log('[WPC Restore] RE-ENTRANCY skip image=' . $imageID . ' — a restore is already in flight (wpc_restoring_ set); standing down');
            return ['ok' => false, 'reason' => 'in-flight'];
        }

        // Rule: a restore with nothing on this site to restore from refuses before it touches any
        // state. Observed: "Cloud Only" keeps no local copy, the cloud restore asked the retired v1
        // /restore route (404), and the scaled-file branch of the safety net answered true anyway,
        // so the image's meta was wiped to "restored" over still-compressed files and the UI
        // reported success (review 2026-09-26, A-D1).
        $refusal = $this->restore_refusal($imageID);
        if ($refusal !== null) {
            return $refusal;
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

        // Check if backup mode was 'off' — compression was permanent
        $backupMode = get_post_meta($imageID, 'wpc_backup_mode', true);
        if ($backupMode === 'off') {
            error_log('[WPC Restore] BLOCKED image=' . $imageID . ' — backup mode was off, compression is permanent');
            return ['ok' => false, 'reason' => 'backup-off'];
        }

        // Skipped images — just clear metadata
        $skipped = get_post_meta($imageID, 'ic_skipped', true);
        if (!empty($skipped) && $skipped == 'true') {
            $this->cleanRestoreMeta($imageID);
            error_log('[WPC Restore] DONE image=' . $imageID . ' method=skipped time=' . round(microtime(true) - $t_total, 2) . 's');
            return ['ok' => true, 'source' => 'skipped', 'deferred' => false];
        }

        // Originals never rewritten in place and no copy on this site: the files on disk are the
        // originals, so restoring is dropping the variants and the meta (cleanRestoreMeta below).
        if (!$this->has_restore_copy($imageID) && !$this->originals_rewritten($imageID)) {
            $restored = true;
            $restore_source = 'variants-only';
        }

        // Suppress on_upload hook during any regeneration in this function
        self::unhook_upload();

        // ── PRIORITY 1: New /wpc-backups/ directory ──────────────────
        $backupRel = get_post_meta($imageID, 'wpc_backup_path', true);
        if ($backupRel && file_exists($backupBase . $backupRel)) {
            $restored = $this->restore_from_new_backup($imageID, $backupBase, $uploadDir);
            if ($restored) {
                $restore_source = 'cloud_bkp';
                error_log('[WPC Restore] Restored from /wpc-backups/ image=' . $imageID);
            }
        }

        // ── PRIORITY 2: Legacy backup directory (ic_backup_images meta) ──
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

                    // Defer thumbnail regen to async worker (same as /wpc-backups/ path)
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

        // ── PRIORITY 3: Inline _bkp files ────────────────────────────
        if (!$restored) {
            $restored = $this->restore_from_bkp_files($imageID);
            if ($restored) {
                $restore_source = 'local_bkp';
                error_log('[WPC Restore] Restored from _bkp files image=' . $imageID);
            }
        }

        // ── PRIORITY 4: Safety net — regenerate from unscaled ────────
        if (!$restored) {
            $restored = $this->regenerate_from_unscaled($imageID);
            if ($restored) {
                $restore_source = 'service';
                error_log('[WPC Restore] Restored via regeneration image=' . $imageID);
            }
        }

        // Gate cleanup_backups on actual restore success. If we couldn't
        // verify a single byte-identical copy, the backup directory is the only

        if ($restored) {
            $this->cleanup_backups($imageID, $backupBase, $uploadDir);
        } else {
            error_log('[WPC Restore] BACKUP_RETAINED image=' . $imageID . ' — restore failed verification; backups NOT deleted (so user can retry or manual-recover)');
        }


        $journal_dropped = $this->cleanRestoreMeta($imageID);


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
            if (function_exists('wpc_arm_pending_thumb_regen_sweep')) {
                wpc_arm_pending_thumb_regen_sweep();
            }
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

        // Clear lazy_cdn sha256 dedup transients tied to variants
        // that just got deleted. Without this, the 10-min dedup TTL would


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

        if (!$restored) {
            return ['ok' => false, 'reason' => 'restore-failed'];
        }
        $deferred = (bool) get_post_meta($imageID, '_wpc_pending_thumb_regen', true);
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-restored', 'site', '', ['id' => (int) $imageID, 'mode' => $restore_source === 'variants-only' ? 'variants-only' : 'from-copy', 'source' => $restore_source, 'deferred' => $deferred ? 1 : 0, 'journal_dropped' => (int) $journal_dropped]);
        }
        return ['ok' => true, 'source' => $restore_source, 'deferred' => $deferred];
    }

    /**
     * The refusal a restore answers when this attachment's originals were rewritten in place and
     * nothing on this site can put them back, or null otherwise. A source is one of the copies
     * restoreV4() restores from (see has_restore_copy()). No service keeps an original: the v1
     * /restore route answers 404 and the v2 orchestrator stores none, so "Cloud Only" leaves
     * nothing. An image whose originals were never rewritten (see originals_rewritten()) needs no
     * copy: its restore drops the variants and the meta. Backup mode "off" and skipped images are
     * answered by restoreV4() itself. Logs image-restore-refused {id, reason, mode} when it refuses.
     *
     * @return array|null ['ok' => false, 'reason' => 'no-backup', 'mode' => string] or null
     */
    public function restore_refusal($imageID)
    {
        $imageID = (int) $imageID;
        $mode = (string) get_post_meta($imageID, 'wpc_backup_mode', true);
        if ($mode === 'off' || get_post_meta($imageID, 'ic_skipped', true) == 'true') {
            return null;
        }
        if ($this->has_restore_copy($imageID) || !$this->originals_rewritten($imageID)) {
            return null;
        }

        $refusal = ['ok' => false, 'reason' => 'no-backup', 'mode' => $mode !== '' ? $mode : 'unknown'];
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('image-restore-refused', 'site', '', ['id' => $imageID, 'reason' => 'no-backup', 'mode' => $refusal['mode']]);
        }
        return $refusal;
    }

    /**
     * Whether this site holds a copy restoreV4() can restore the original from: the /wpc-backups/
     * copy, the legacy ic_backup_images copy, an inline *_bkp file, or WordPress's own unscaled
     * original beside a -scaled file.
     */
    private function has_restore_copy($imageID)
    {
        $backupRel = get_post_meta($imageID, 'wpc_backup_path', true);
        if ($backupRel && file_exists(WP_CONTENT_DIR . '/wpc-backups/' . $backupRel)) {
            return true;
        }
        $legacyBackup = get_post_meta($imageID, 'ic_backup_images', true);
        if (is_array($legacyBackup)) {
            $legacyPath = $legacyBackup['original'] ?? $legacyBackup['full'] ?? '';
            if ($legacyPath && file_exists($legacyPath) && filesize($legacyPath) > 0) {
                return true;
            }
        }
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;
        if ($unscaled && $unscaled !== $scaled && file_exists($unscaled)) {
            return true;
        }
        if ($scaled || $unscaled) {
            $baseName = pathinfo($unscaled ?: $scaled, PATHINFO_FILENAME);
            if (glob(dirname($scaled ?: $unscaled) . '/' . $baseName . '*_bkp.*')) {
                return true;
            }
            $scaledBkp = $scaled ? preg_replace('/\.(jpe?g|png|gif)$/i', '_bkp.$1', $scaled) : '';
            if ($scaledBkp && $scaledBkp !== $scaled && file_exists($scaledBkp)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a compression rewrote this attachment's original files in place, so that a restore
     * needs a copy to put them back. Rule: refuse a restore only for an image whose originals
     * were rewritten. Observed: with in-place rewrite off (lazy_cdn, the fresh-install default)
     * the variants are separate .webp/.avif files, no copy is taken, and every restore on such a
     * site was refused as no-backup while each .jpg on disk was the original (review 2026-09-26,
     * finding 1; hosted rig row d). The evidence, any one of which answers yes:
     *   - a wpc_backup_mode, which backup_all_sizes() writes only once the in-place gate passed;
     *   - first-generation compression meta (ic_compressed_images), which always rewrote in place;
     *   - a variant entry in the image's own format (key without a -webp/-avif suffix, or the
     *     source's own format), which lands on the original's file name;
     *   - a file on disk whose size differs from the originalSize the set recorded for it.
     */
    private function originals_rewritten($imageID)
    {
        if ((string) get_post_meta($imageID, 'wpc_backup_mode', true) !== '') {
            return true;
        }
        if (!empty(get_post_meta($imageID, 'ic_compressed_images', true))) {
            return true;
        }
        $set = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($set) || empty($set)) {
            return false;
        }
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;
        $sourceFormat = strtolower((string) pathinfo((string) ($scaled ?: $unscaled), PATHINFO_EXTENSION));
        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($imageID) : [];
        $dir = dirname((string) ($scaled ?: $unscaled));
        foreach ($set as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (!preg_match('/^(.*)-(webp|avif)$/', (string) $key, $m) || $m[2] === $sourceFormat) {
                return true;
            }
            $label = $m[1];
            $originalSize = (int) ($entry['originalSize'] ?? 0);
            if ($label === 'original') {
                $file = $unscaled;
            } elseif ($label === 'scaled') {
                $file = $scaled;
            } else {
                $file = !empty($meta['sizes'][$label]['file']) ? $dir . '/' . $meta['sizes'][$label]['file'] : '';
            }
            if ($originalSize > 0 && $file && file_exists($file) && (int) filesize($file) !== $originalSize) {
                return true;
            }
        }
        return false;
    }


    // ─── Restore sub-functions ──────────────────────────────────────


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

        // Restore unscaled
        if ($unscaled) {
            $rel = str_replace($uploadDir . '/', '', $unscaled);
            $src = $backupBase . $rel;
            if (file_exists($src)) {
                $filesAttempted++;
                if ($this->verify_restore_atomic($src, $unscaled, $imageID, 'original')) $filesCopied++;
            }
        }

        // Restore scaled
        if ($scaled && $scaled !== $unscaled) {
            $rel = str_replace($uploadDir . '/', '', $scaled);
            $src = $backupBase . $rel;
            if (file_exists($src)) {
                $filesAttempted++;
                if ($this->verify_restore_atomic($src, $scaled, $imageID, 'scaled')) $filesCopied++;
            }
        }

        // Restore thumbnails
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

        // If any attempted file failed verification, log it explicitly so the gap
        // between attempted/copied is visible in debug.log.
        if ($filesAttempted > $filesCopied) {
            error_log('[WPC Restore] new_backup PARTIAL_FAIL image=' . $imageID . ' attempted=' . $filesAttempted . ' verified=' . $filesCopied);
        }

        // If backup mode was 'originals', or main file is missing, regenerate from unscaled
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

        // Also check exact _bkp suffix (e.g. photo-scaled_bkp.jpg)
        $scaledBkp = preg_replace('/\.(jpe?g|png|gif)$/i', '_bkp.$1', $scaled);
        if ($scaledBkp && file_exists($scaledBkp)) {
            if ($this->verify_restore_atomic($scaledBkp, $scaled, $imageID, 'scaled')) {
                @unlink($scaledBkp);
                $restored = true;
            }
        }

        // Defer thumbnail regen to async worker
        if ($restored && $scaled && !file_exists($scaled) && $unscaled && file_exists($unscaled) && $unscaled !== $scaled) {
            update_post_meta($imageID, '_wpc_pending_thumb_regen', [
                'regen_source' => $unscaled,
                'backup_mode'  => 'bkp_files',
                'scheduled_at' => time(),
            ]);
        }

        return $restored;
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

        // No unscaled original: nothing to regenerate from. Answering true here reported a
        // restore that restored nothing (review 2026-09-26, A-D1).
        return false;
    }

    private function cleanup_backups($imageID, $backupBase, $uploadDir) {


        $meta = wp_get_attachment_metadata($imageID);
        $scaled = get_attached_file($imageID);
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : $scaled;

        // Sub-size backups only — masters are preserved.
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

        // Keep wpc_backup_path meta so restore Priority 1 continues to work for
        // future restores. The masters are still on disk in wpc-backups/ pointed to by this.
    }

    // ─── End restore sub-functions ──────────────────────────────────


    /**
     * Clean all optimization metadata and variants. Used by every restore exit path.
     * Guarantees the image is never stuck in a compressed/optimizing state.
     * Answers how many journal files the variant owner dropped (the image-restored receipt).
     */
    private function cleanRestoreMeta($imageID) {
        // Restore finished: release the in-flight micro-lock (every exit path
        // runs through here). The next render may re-trigger immediately.
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
            // Mime-guard: for a webp SOURCE, {base}*.webp matches the original itself and
            // WP's own thumbnails — restore must never glob-delete the source format
            $wpc_rg_mime = (string) get_post_mime_type($imageID);
            if ($wpc_rg_mime !== 'image/webp') {
                foreach (glob($dir . '/' . $baseName . '*.webp') as $webp) { if (function_exists('wpc_add_twin_bytes')) { wpc_add_twin_bytes(-(int) @filesize($webp)); } @unlink($webp); }
            }
            if ($wpc_rg_mime !== 'image/avif') {
                foreach (glob($dir . '/' . $baseName . '*.avif') as $avif) { if (function_exists('wpc_add_twin_bytes')) { wpc_add_twin_bytes(-(int) @filesize($avif)); } @unlink($avif); }
            }
        }

        delete_post_meta($imageID, 'ic_bulk_running');
        delete_post_meta($imageID, 'ic_compressing');
        delete_post_meta($imageID, 'wpc_images_compressed');
        delete_post_meta($imageID, 'ic_stats');
        $journal_dropped = (int) wps_ic_image_variants::clear($imageID, 'restore');
        delete_post_meta($imageID, 'ic_skipped');
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wps_ic_queue_' . $imageID);

        // Modern Image Delivery trigger-state cleanup
        delete_post_meta($imageID, '_wpc_optimize_attempts');
        delete_transient('wpc_queued_' . $imageID);
        delete_transient('wpc_failed_' . $imageID);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('wpc_queued_' . $imageID, 'wpc');
        }
        // Remove from in-flight queue option
        if (in_array($imageID, self::queue_ids())) {
            self::queue_remove($imageID);
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

        // Phase B async-download cleanup: cancel any in-flight variant download
        // so we don't race-write files to a just-restored attachment
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

        return $journal_dropped;
    }

}
