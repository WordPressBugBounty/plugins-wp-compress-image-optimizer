<?php
/**
 * Local Compress
 * @since 5.00.59
 */


// ─────────────────────────────────────────────────────────────────────────
// 7.01.0 Modern Image Delivery — trigger infrastructure (global helpers)
// ─────────────────────────────────────────────────────────────────────────

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

/**
 * Content-based image bytes validator. Defends against corrupt placeholder/sentinel
 * bytes being written to disk (observed: 678-byte fixed-size error responses with .webp/.avif
 * filenames passing the previous filename-only check).
 *
 * Returns true if $bytes look like a valid image of $format. Returns false (and logs a
 * [WPC CorruptByte] tracking entry with $source attribution) for invalid inputs.
 *
 * Validation per format:
 *   - All formats:  minimum 500 bytes (placeholders/sentinels rejected)
 *   - WebP:         RIFF magic at 0-3 + WEBP marker at 8-11
 *   - AVIF:         ftyp box at 4-7 + avif/avis/mif1 brand at 8-11
 *   - JPEG:         FF D8 FF magic at 0-2
 *   - PNG:          standard 8-byte PNG signature
 *
 * Usage at write call sites:
 *   if (!wpc_is_valid_image_bytes($bytes, 'webp', $imageID, 'phase_b_download')) {
 *       continue;  // skip this variant — visitor will get on-demand WebP via CDN proxy
 *   }
 */
if (!function_exists('wpc_is_valid_image_bytes')) {
    function wpc_is_valid_image_bytes($bytes, $format, $imageID = 0, $source = 'unknown', $context = [])
    {
        if (empty($bytes) || !is_string($bytes)) {
            return false;
        }
        $len = strlen($bytes);

        // Build forensic context string. Service-team requested: imageID, sizeLabel, format,
        // URL, response body first 50 bytes, age-since-compress, source attribution.
        $build_log = function ($reason) use ($imageID, $format, $len, $source, $bytes, $context) {
            $size_label = isset($context['size_label']) ? (string) $context['size_label'] : '';
            $url        = isset($context['url']) ? (string) $context['url'] : '';
            $hex50      = bin2hex(substr($bytes, 0, 50));
            // Compute age since this image's last successful compress (helps service team
            // distinguish post-upload race vs cache poisoning).
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

        // Minimum size — any real image at meaningful dimensions is at least ~500 bytes.
        // Observed corrupt placeholders at exactly 678 bytes; this rejects that and similar.
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

        return true;
    }
}

/**
 * Persist inline-delivered variant bytes into ic_local_variants
 * directly (no dependency on bg-swap callback).
 *
 * Mirrors the GET_LOCK + merge pattern from wpc_handle_bg_swap_callback:
 * acquire MySQL GET_LOCK on `wpc_bg_meta_{imageID}` (transient fallback if
 * unavailable), re-read fresh state inside the critical section, merge in
 * each entry under its `<sizeLabel>-<format>` (or unsuffixed for jpeg) key,
 * write back, release. Existing bg_upgraded entries are preserved (bg-swap
 * refinements always win over inline).
 *
 * `$entries` is an array of associative arrays:
 *   ['size_label' => 'wpc_1170', 'format' => 'avif', 'size' => 12345, 'url' => '...']
 *
 * `$source` is a free-form attribution string for the bg_source field
 * (e.g. 'lazy_fill_inline', 'avif_backfill_batch_inline').
 */
if (!function_exists('wpc_persist_inline_variants')) {
    function wpc_persist_inline_variants($imageID, $entries, $source = 'inline')
    {
        $imageID = (int) $imageID;
        if (!$imageID || empty($entries) || !is_array($entries)) return 0;

        global $wpdb;
        $mysql_lock_name = 'wpc_bg_meta_' . $imageID;
        $mysql_state = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $mysql_lock_name));
        $got_mysql   = ($mysql_state === '1' || $mysql_state === 1);

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

                // Preserve bg-swap refinements (bg_upgraded) — they're authoritative
                if (!empty($variants[$key]['bg_upgraded'])) continue;

                if (!isset($variants[$key])) {
                    // New entry. originalSize=0 means downstream readers must derive
                    // from a same-base sibling — wpc_compute_best_savings handles this.
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

                // Trigger the live-update savings recompute helper so the headline
                // ic_savings reflects newly-landed AVIF entries.
                if (function_exists('wpc_compute_best_savings')) {
                    $best = wpc_compute_best_savings($variants, $imageID);
                    if ($best['pct'] > 0 && $best['orig'] > 0) {
                        update_post_meta($imageID, 'ic_savings',          round($best['pct'], 1));
                        update_post_meta($imageID, 'ic_savings_format',   $best['format']);
                        update_post_meta($imageID, 'ic_savings_bytes',    $best['orig'] - $best['opt']);
                        update_post_meta($imageID, 'ic_savings_baseline', $best['orig']);
                    }
                }

                // Gate the chip-event heartbeat on the image's actual user-state.
                // wpc_persist_inline_variants is called by BOTH user-driven flows (Phase A
                // inline ladder, post-compress refinement) AND visitor-driven lazy-fill
                // backfill (page render → wpc_log_variant_emitted → async ladder gen).
                // For visitor backfill on an uncompressed image, firing a chip event would
                // reproduce the screenshot bug where the chip rendered on an "uncompressed"
                // card because compress_details() returned the uncompressed HTML. Gate at
                // the source — only fire the heartbeat when the user has actually compressed.
                // Variant bytes are still written to disk + ic_local_variants either way;
                // the heartbeat is purely about UI animation.
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
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $mysql_lock_name));
            }
            if ($got_transient) {
                if ($has_obj_cache && function_exists('wp_cache_delete')) wp_cache_delete($lock_key, 'wpc');
                delete_transient($lock_key);
            }
        }

        return $written;
    }
}

/**
 * Surgical purge of variant tracking post_meta for one attachment,
 * WITHOUT restoring on-disk bytes from backup. Used by ops/QA to reset variant
 * registry state and force a re-compress to repopulate, while preserving the
 * already-optimized disk files (faster than restoreV4 + recompress + bg drain).
 *
 * Cleared:
 *   - ic_local_variants (the variant registry)
 *   - ic_local_variants_chosen (the byte-optimal stability cache)
 *   - ic_savings, ic_savings_format, ic_savings_bytes, ic_savings_baseline (headline)
 *   - ic_stats (aggregated stats)
 *   - _wpc_compress_started_at (race-guard timestamp; next compress generates fresh)
 *   - wps_ic_heartbeat_<imageID> transient (forces card UI re-render fresh)
 *
 * Preserved:
 *   - On-disk variant files (.jpg, .webp, .avif) — bytes stay
 *   - wp-content/wpc-backups/ — backup files preserved
 *   - _wp_attachment_metadata['sizes'] — WP-core entries left alone
 *   - ic_status — left at current value (the disk bytes are still optimized)
 *
 * Returns ['imageID' => N, 'cleared' => [keys], 'preserved' => [...], 'message' => ...]
 */
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

/**
 * Single source of truth for the headline ic_savings %.
 *
 * Iterates all variants, derives originalSize via the canonical 4-tier lookup
 * (WPC_Modern_Delivery::canonical_original_size), and computes pct live.
 * Replaces three divergent recomputes that previously read the stored 'savings'
 * field directly, and now uses the same canonical originalSize lookup as the
 * modal builder so card and modal can never disagree on savings %.
 *
 * The stored `originalSize` field in ic_local_variants drifts across compress
 * cycles (see WPC_Modern_Delivery::canonical_original_size doc). The canonical
 * helper preferences pristine WPC backup file size first, then WP metadata,
 * then stored value, then sibling-derive — same chain the modal uses since
 * v7.01.11.
 *
 * `$imageID` is required for the canonical lookup. Pass 0 to fall back to the
 * legacy stored-originalSize-only path (kept for any caller without imageID
 * in scope; all v7.01.x callers DO have it).
 *
 * Returns ['pct' => float, 'format' => string, 'orig' => int, 'opt' => int]
 * with pct=0 when no qualifying variant exists.
 */
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
 * falls back to transient check. Worker-lock (wpc_compress_lock) bounds worst case (G4).
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
        $queue = get_option('wpc_compress_queue', []);
        if (!is_array($queue)) $queue = [];
        if (!in_array($attachmentId, $queue)) {
            $queue[] = $attachmentId;
            update_option('wpc_compress_queue', $queue, false);
        }

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

/**
 * Backfill ic_local_variants for images compressed before 7.01.0.
 *
 * Pre-7.01.0, the plugin compressed images but never wrote ic_local_variants.
 * Modern delivery requires that key to build <picture> srcsets. Rather than
 * re-queuing through local-mc (slow, wasteful — variants already on disk), we
 * scan disk directly and write the metadata from what we find.
 *
 * Handles two AVIF/WebP naming conventions:
 *   local-mc (7.01.0+)     : strips -scaled → hero.avif
 *   legacy pipeline (pre-7.01.0): keeps -scaled → hero-scaled.avif
 *
 * Called synchronously from build_picture() on first render — only fires when
 * ic_local_variants is absent. Subsequent renders use the cached metadata (fast path).
 *
 * @param int $attachmentId
 * @return bool  true if any AVIF or WebP variants were found and metadata written
 */
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

        if (!$found_nextgen) return false; // no AVIF or WebP found — nothing to backfill

        update_post_meta($attachmentId, 'ic_local_variants', $variants);
        return true;
    }
}

/**
 * Phase 1 — Ladder backfill: trigger per-attachment generation of missing ladder widths.
 *
 * Called from modern-delivery.php::build_picture() when it detects missing widths.
 * Per-attachment batched (all missing widths for one attachment go in ONE POST to local-mc —
 * service decodes original once, encodes all requested widths from shared buffer).
 *
 * Dedup + retry gates mirror wpc_maybe_trigger_optimize() but keyed per-attachment for ladder gen.
 *
 * Queue cap: max 1000 attachments (prevents options table bloat on huge libraries).
 */
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
        if (!isset($queue[$attachmentId])) return; // entry already processed
        $queue[$attachmentId] = array_values(array_unique(array_merge(
            (array) $queue[$attachmentId],
            array_map('intval', $widths)
        )));
        update_option('wpc_ladder_gen_queue', $queue, false);
    }
}

/**
 * Detect if the site is behind HTTP Basic Auth (staging, dev sites).
 * Loopback POST without auth credentials will fail with 401 — skip Layer 2 on these sites.
 * Layers 3 (shutdown) + 4 (admin) still work and drain the queue naturally.
 *
 * Cached per-request (no repeated header detection).
 */
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

        // Non-blocking loopback — page render doesn't wait for this.
        // Timeout was bumped from 0.1 to 0.5 to reduce dropped-loopback
        // frequency on shared hosts where TCP handshake can spike past 100ms
        // under load. Still effectively fire-and-forget (blocking=false).
        // Loopback goes to the local vhost rather than wp_remote_post(blocking=false) to
        // admin_url()'s PUBLIC host: on a datacenter-IP site that host is the CDN/WAF edge, so
        // the request is truthy but the self-POST never lands on local
        // PHP-FPM. Connect-only via the proven shared helper; cookieless (wpc_async_ladder_gen is
        // nopriv-registered with no user/nonce check → behaviour-preserving). Layer-3 admin-pageview
        // drain stays the backstop.
        $lg_parts = wp_parse_url(admin_url('admin-ajax.php'));
        if (!empty($lg_parts['host'])) {
            $lg_https = (!empty($lg_parts['scheme']) && $lg_parts['scheme'] === 'https');
            $lg_port  = !empty($lg_parts['port']) ? (int) $lg_parts['port'] : ($lg_https ? 443 : 80);
            $lg_host  = (string) $lg_parts['host'];
            $lg_path  = (!empty($lg_parts['path']) ? $lg_parts['path'] : '/') . '?action=wpc_async_ladder_gen';
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

/**
 * Async worker — processes ONE queue entry per invocation (all widths for one attachment).
 * Fires one POST to local-mc with batched widths; local-mc decodes original once, encodes all.
 *
 * Registered as wp_ajax + wp_ajax_nopriv handler. Also invoked from shutdown + admin hooks.
 *
 * @param int    $max_items       Max attachments to process per call.
 * @param string $trigger_source  One of: loopback|shutdown|admin|cron|manual|prewarm — for telemetry.
 */
if (!function_exists('wpc_handle_async_ladder_gen')) {
    function wpc_handle_async_ladder_gen($max_items = 1, $trigger_source = 'loopback')
    {
        // Acquire worker lock — one concurrent worker at a time (prevents Sharp pool saturation).
        // 180s gives headroom for full-ladder restore (9 widths × 2 formats ≈ 60–90s service
        // round-trip + 30s download + safety margin).
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

        // Self-chain: if queue still has items, fire another loopback to
        // keep draining. Without this, a page with N lazy images only encodes 1
        // per page-view (worker lock prevents concurrent drains, max_items=1).
        // Self-chain naturally serializes the queue across separate admin-ajax
        // workers — each worker takes one item, then re-fires for the next. Safe
        // because the per-item failure increments _wpc_ladder_attempts which the
        // 3-attempt cap in wpc_maybe_trigger_ladder_gen turns into a 24h cooldown.
        if (!empty($queue) && function_exists('wpc_fire_ladder_gen_worker')) {
            wpc_fire_ladder_gen_worker();
        }

        return $processed;
    }
}

/**
 * Generate missing ladder widths for one attachment via a single POST to local-mc.
 * Returns true on success, false on failure.
 *
 * @param int    $attachmentId
 * @param array  $widths          Widths to request.
 * @param string $trigger_source  loopback|shutdown|admin|cron|manual|prewarm — for telemetry.
 * @param float  $t_start         microtime(true) when worker began, for total-duration telemetry.
 */
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

        // Lazy variant mode dispatch. Auto-enabled when Modern Image Delivery is on
        // (the only path where custom widths make ladder fires meaningful in the first place).
        // Customer gets per-variant POSTs (~1-1.5s each) instead of batch (~7s). LCP image ready
        // faster; below-fold variants generate as the browser requests them via srcset.
        // Emergency override: define('WPC_DISABLE_LAZY_VARIANT', true) in wp-config.php to force
        // legacy batch flow if a customer hits an issue with the lazy path.
        $settings = get_option(WPS_IC_SETTINGS);
        $modern_delivery_on = !empty($settings['modern_image_delivery']);
        $lazy_disabled      = defined('WPC_DISABLE_LAZY_VARIANT') && WPC_DISABLE_LAZY_VARIANT === true;
        if ($modern_delivery_on && !$lazy_disabled && function_exists('wpc_run_lazy_variant_ladder')) {
            return wpc_run_lazy_variant_ladder($attachmentId, $widths, $trigger_source, $t_start, $meta);
        }

        // Build crops + filenames using the SAME resolver as srcset emission + existence checks.
        // If these disagree on filename convention, downloaded files land at paths the next
        // render can't find → silent cache misses. Single source of truth: resolve_variant_filename().
        // We request JPEG filenames from service; service swaps extensions for avif/webp output
        // (per service-team-filename-mapping.md: hero-1024x683.jpg → hero-1024x683.avif).
        $crops = [];
        $filenames = [];
        $source_width = WPC_Modern_Delivery::get_source_width($attachmentId, $meta);

        foreach ($widths as $w) {
            $w = (int) $w;
            if ($w <= 0) continue;

            // Resolve using JPEG format — service will produce .avif/.webp siblings automatically.
            // Source-width override lets retina widths above meta['width'] resolve to wpc_{W}
            // filenames when the unscaled origin supports them.
            $resolved = WPC_Modern_Delivery::resolve_variant_filename($meta, $w, 'jpg', $source_width);
            if ($resolved === null) continue; // impossible (> source, missing meta)

            $key = $resolved['size_label'];
            $filenames[$key] = $resolved['filename'];
            $crops[$key] = [
                'width'  => $w,
                'height' => 0, // proportional
                'crop'   => false,
            ];
        }

        if (empty($crops)) return false;

        // Get base params from existing buildOptimizeParams
        $inst = new wps_local_compress();
        if (!method_exists($inst, 'buildOptimizeParams')) return false;

        $params = $inst->buildOptimizeParams($attachmentId);
        $params['crops'] = wp_json_encode($crops);
        $params['filenames'] = wp_json_encode($filenames);
        $params['avif'] = '1';
        $params['webp'] = '1';
        // Race-guard timestamp; mirror lazy paths so all 3 ladder code paths
        // send the same body fields. Service uses for stale-callback filtering.
        $params['compressStartedAt'] = (int) round(microtime(true) * 1000);

        // v1.22.8 lazy-variant plumbing for ladder. Telegraphs intent to the service:
        //   parentImageID = the parent attachment (same as imageID in batch mode)
        //   skipBackup    = '1' when parent already has Bunny OR local backup, else '0'
        //   triggerContext = analytics attribution
        // Service treats these as optional + ignores when absent (graceful on pre-v1.22.8 pods).
        $params['parentImageID']  = (string) $attachmentId;
        $params['skipBackup']     = (function_exists('wpc_parent_has_backup') && wpc_parent_has_backup($attachmentId)) ? '1' : '0';
        $params['triggerContext'] = 'ladder_' . $trigger_source;

        // Time the service round-trip (encode on local-mc). 120s covers full-ladder restore
        // re-generation (up to 9 widths × 2 formats = 18 variants). Typical lazy-fill touches
        // 2–3 widths and finishes in 20–40s.
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

        // Download generated variants to disk — track format counts for telemetry
        $downloaded = 0;
        $widths_delivered = [];
        $formats_delivered = ['avif' => 0, 'webp' => 0, 'jpg' => 0];
        $upload_dir = wp_upload_dir();
        $base_dir = rtrim($upload_dir['basedir'], '/');
        $rel_dir = dirname($meta['file']);
        $t_dl_start = microtime(true);
        // Collect inline-delivered entries for direct ic_local_variants persistence
        $ladder_persist_entries = [];

        foreach ($data['optimizedResults'] as $variant) {
            // Per the service contract, v1.22.11.9-fast may omit `url` in inline-bytes mode.
            // Plugin must use `bytes` when present, fall back to `url` only when bytes is null.
            // NEVER URL-fetch when bytes is truthy (avoids redundant network round-trip).
            $has_bytes = !empty($variant['bytes']);
            $has_url   = !empty($variant['url']);
            if (!$has_bytes && !$has_url) continue;            // service sent malformed entry
            if (empty($variant['fileName'])) continue;          // need filename to write to disk
            $dest = $base_dir . '/' . $rel_dir . '/' . $variant['fileName'];
            $fmt = strtolower(pathinfo($variant['fileName'], PATHINFO_EXTENSION));
            $w   = (int) ($variant['width'] ?? 0);
            // Service response omits 'width' — derive from fileName ("hero-480.avif") or sizeLabel ("wpc_480").
            // Require 3+ digits to avoid matching post-slug dedup suffixes (e.g. "hero-4.jpg").
            if ($w === 0) {
                if (preg_match('/-(\d{3,})\.(?:avif|webp|jpg|jpeg|png)$/i', $variant['fileName'], $m)) {
                    $w = (int) $m[1];
                } elseif (!empty($variant['sizeLabel']) && preg_match('/(\d{3,})/', $variant['sizeLabel'], $m)) {
                    $w = (int) $m[1];
                }
            }

            // Skip if already exists (idempotent on worker resume) — but still queue
            // a persist entry so ic_local_variants records this slot. An earlier backfill
            // wrote AVIFs to disk that never got registered; this catches them on retry.
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

            // Inline-bytes path: prefer bytes when service shipped them (no URL fetch needed)
            if ($has_bytes) {
                $bytes = base64_decode($variant['bytes'], true);
                $source_attr = 'ladder_gen_inline';
                $url_for_log = '';
            } else {
                $bytes = wp_remote_retrieve_body(wp_remote_get($variant['url'], ['timeout' => 30]));
                $source_attr = 'ladder_gen_download';
                $url_for_log = $variant['url'];
            }
            // Content validator. Without this, HTML error pages from CDN (404,
            // upstream errors, rate limits) get written to disk as .webp/.avif sentinels.
            // This was the unvalidated path that produced the 678-byte HTML files we saw.
            if (!empty($bytes) && wpc_is_valid_image_bytes($bytes, $fmt, $attachmentId, $source_attr, ['size_label' => $variant['sizeLabel'] ?? '', 'url' => $url_for_log]) && @file_put_contents($dest, $bytes)) {
                @chmod($dest, 0644);
                $downloaded++;
                if ($w > 0) $widths_delivered[$w] = true;
                if (isset($formats_delivered[$fmt])) $formats_delivered[$fmt]++;
                // Queue this entry for direct ic_local_variants persistence
                // so we don't depend on bg-swap callback for primary persistence.
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
        // Persist inline-delivered entries directly into ic_local_variants
        // under GET_LOCK + merge. Bg-swap callbacks become refinement-only.
        if (!empty($ladder_persist_entries) && function_exists('wpc_persist_inline_variants')) {
            wpc_persist_inline_variants($attachmentId, $ladder_persist_entries, 'ladder_gen_inline');
        }

        $download_ms = (int) round((microtime(true) - $t_dl_start) * 1000);
        $total_ms    = (int) round((microtime(true) - $t_start) * 1000);

        // Rich log entry for per-event analysis (ring buffer, 500 max)
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

        // Cumulative stats (fleet + timing + triggers + formats)
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

/**
 * Layer 3 — Frontend shutdown hook (cron replacement).
 * Runs AFTER the response is sent to the browser — zero impact on page speed.
 * Processes 1 queue item per page load. Natural rate limiting via traffic.
 * Works on 100% of hosts (no external dependency).
 */
if (!function_exists('wpc_ladder_shutdown_hook')) {
    function wpc_ladder_shutdown_hook()
    {
        if (!get_option('wpc_ladder_gen_queue_has_items')) return;
        if (is_admin()) return; // admin hook handles admin-side
        // Quick acquire-and-exit pattern; worker has its own lock
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
        // Process up to 3 items per admin page load (bounded time)
        wpc_handle_async_ladder_gen(3, 'admin');
    }
    add_action('admin_init', 'wpc_ladder_admin_hook', 99);
}

// Layer 2 — AJAX handler for loopback POST (primary trigger)
if (!function_exists('wpc_register_async_ladder_gen_ajax')) {
    function wpc_register_async_ladder_gen_ajax()
    {
        // max_items=8 (was 1). With v2 lazy mode each ladder iteration
        // is fast (~50ms — just sets state + fires a drain loopback to admin-ajax;
        // the heavy Phase A POST happens in a SEPARATE worker). Draining 8 items
        // in one loopback worker is ~400ms total and survives the case where the
        // self-chain loopback gets dropped under shared-host network jitter.
        // Self-chain at the end of wpc_handle_async_ladder_gen still fires if
        // queue depth exceeds 8 (or if any one item is unusually slow).
        wpc_handle_async_ladder_gen(8, 'loopback');
        wp_die('', '', ['response' => 200]);
    }
    add_action('wp_ajax_wpc_async_ladder_gen', 'wpc_register_async_ladder_gen_ajax');
    add_action('wp_ajax_nopriv_wpc_async_ladder_gen', 'wpc_register_async_ladder_gen_ajax');
}

// Activation pre-warm AJAX handler — fired when Modern Image Delivery flips ON
if (!function_exists('wpc_register_prewarm_ajax')) {
    function wpc_register_prewarm_ajax()
    {
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
        $processed = wpc_handle_async_ladder_gen(10, 'manual'); // process up to 10 items
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

// Phase B async download worker — 3-layer dispatch for shared-hosting reliability.
// Layer 1: loopback POST (fires immediately, works on most hosts).
// Layer 2: WP Cron 30s backup (fallback when loopback fails — e.g. Basic-Auth staging).
// Layer 3: admin_init passive drain (fallback when both above fail — scans pending meta).
if (!function_exists('wpc_download_variants_hook')) {
    function wpc_download_variants_hook($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID || get_post_type($imageID) !== 'attachment') return;

        // Concurrency lock — prevents two workers (e.g. loopback + cron) racing same image
        $lock_key = 'wpc_download_lock_' . $imageID;
        if (get_transient($lock_key)) return;
        set_transient($lock_key, 1, 120);

        try {
            // Race-guard: if user restored between Phase A and now, abort without writing files
            if (get_post_meta($imageID, 'ic_status', true) !== 'compressed') {
                delete_post_meta($imageID, '_wpc_pending_downloads');
                delete_post_meta($imageID, '_wpc_download_fail_count');
                return;
            }

            // Persistent failure cap: after 5 total attempts, stop re-scheduling.
            // Frontend still works via CDN URLs in ic_local_variants; only WP admin previews affected.
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

            // Success criteria: at least one file written OR no errors occurred (e.g. all variants
            // were size-regression skipped because a re-compress landed no smaller bytes — still "done").
            // Only increment fail counter on actual errors.
            $real_error = empty($result['done']) && !empty($result['errors']);
            if (!$real_error) {
                delete_post_meta($imageID, '_wpc_pending_downloads');
                delete_post_meta($imageID, '_wpc_download_fail_count');
            } else {
                // Actual download failure — increment counter; dispatch layers will retry up to cap.
                update_post_meta($imageID, '_wpc_download_fail_count', $fail_count + 1);
            }
        } finally {
            delete_transient($lock_key);
        }
    }
    add_action('wpc_download_variants', 'wpc_download_variants_hook', 10, 1);

    // Layer 1 AJAX endpoint — loopback POST target
    function wpc_download_variants_ajax()
    {
        wpc_download_variants_hook((int) ($_REQUEST['imageID'] ?? 0));
        wp_die();
    }
    add_action('wp_ajax_wpc_download_variants',        'wpc_download_variants_ajax');
    add_action('wp_ajax_nopriv_wpc_download_variants', 'wpc_download_variants_ajax');
}

// Layer 1 dispatcher — non-blocking loopback POST (0.1s timeout). Skipped on Basic-Auth sites.
if (!function_exists('wpc_fire_download_worker')) {
    function wpc_fire_download_worker($imageID)
    {
        if (function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth()) return;
        // Loopback goes to the local vhost rather than wp_remote_post(blocking=false) to admin_url()'s
        // PUBLIC host: on a datacenter-IP site that host is the CDN/WAF edge, so the request is truthy
        // but never lands on local PHP-FPM. Connect-only via the proven shared helper; cookieless
        // (wpc_download_variants is nopriv-registered with no user/nonce check, so this is
        // behaviour-preserving; the prior $_COOKIE relay was never load-bearing). Layer-3
        // admin-pageview drain (wpc_admin_drain_pending_downloads) stays the backstop.
        $dl_parts = wp_parse_url(admin_url('admin-ajax.php'));
        if (!empty($dl_parts['host'])) {
            $dl_https = (!empty($dl_parts['scheme']) && $dl_parts['scheme'] === 'https');
            $dl_port  = !empty($dl_parts['port']) ? (int) $dl_parts['port'] : ($dl_https ? 443 : 80);
            $dl_host  = (string) $dl_parts['host'];
            $dl_path  = (!empty($dl_parts['path']) ? $dl_parts['path'] : '/') . '?action=wpc_download_variants';
            $dl_body  = http_build_query(['imageID' => (int) $imageID]);
            $dl_req   = "POST {$dl_path} HTTP/1.1\r\nHost: {$dl_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($dl_body) . "\r\nConnection: close\r\nUser-Agent: WPCDownloadVariants/1.0\r\n\r\n" . $dl_body;
            $dl_fp = wps_ic_ajax::wpc_loopback_open_socket($dl_host, $dl_port, $dl_https, 0.2);
            if ($dl_fp) { @stream_set_timeout($dl_fp, 0, 100000); @fwrite($dl_fp, $dl_req); @fclose($dl_fp); }
        }
    }
}

// Layer 3 passive fallback — every admin page view, drain up to 3 pending downloads
// AND up to 3 pending thumbnail regens. Catches shared hosts where WP Cron never fires
// AND loopback is blocked (Basic-Auth or misconfigured).
if (!function_exists('wpc_admin_drain_pending_downloads')) {
    function wpc_admin_drain_pending_downloads()
    {
        if (!is_admin() || wp_doing_ajax() || (defined('DOING_CRON') && DOING_CRON)) return;
        global $wpdb;

        // Pending variant downloads (from compress Phase B)
        $dl_rows = $wpdb->get_results("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_wpc_pending_downloads'
            LIMIT 3
        ");
        foreach ((array) $dl_rows as $row) {
            wpc_download_variants_hook((int) $row->post_id);
        }

        // Pending thumbnail regens (from restore Phase B)
        $regen_rows = $wpdb->get_results("
            SELECT post_id FROM {$wpdb->postmeta}
            WHERE meta_key = '_wpc_pending_thumb_regen'
            LIMIT 3
        ");
        foreach ((array) $regen_rows as $row) {
            if (function_exists('wpc_regen_thumbs_hook')) wpc_regen_thumbs_hook((int) $row->post_id);
        }

        // Pending compress retries (from singleCompressV4 transient errors).
        // Time-gated: only fire if last attempt was > 30s ago (preserves backoff). Caps at
        // 3 per page view to avoid hammering service when many retries are stuck.
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
            if ($last_at > 0 && (time() - $last_at) < 30) continue; // honor backoff
            if (function_exists('wpc_retry_compress_hook')) wpc_retry_compress_hook((int) $row->post_id);
        }
    }
    add_action('admin_init', 'wpc_admin_drain_pending_downloads', 99);
}

// Inbound bg-swap callback endpoint. The rc10.5+ service POSTs here when the
// background worker produces better bytes for a variant that was already delivered via
// fast-path. Plugin decodes base64 body, validates apikey, atomically overwrites the
// existing local file, and records bg_upgraded metadata. Fully backward-compatible —
// this endpoint only fires when customer's plugin opted in via X-Plugin-Accepts-Bg-Swap: 1.
//
// Route: GET|POST /?wpc_bg_swap=1&imageID=N&apikey=KEY
// Body (JSON): {sizeLabel, format, bytes (base64), bgQ, bgS2, bgKb, fastPathKb, fastPathS2}
// Auth: apikey must match WPS_IC_OPTIONS['api_key'] via timing-safe hash_equals().
if (!function_exists('wpc_handle_bg_swap_callback')) {
    function wpc_handle_bg_swap_callback()
    {
        if (empty($_GET['wpc_bg_swap']) || $_GET['wpc_bg_swap'] !== '1') return;

        // ENTRY log: fires BEFORE auth/validation so dropped/rejected
        // callbacks are still visible to ops. Costs ~1ms per call (file_get_contents
        // on php://input is buffered; safe to read again downstream). Service team
        // can correlate these timestamps with their bg-swap dispatch logs to confirm
        // whether callbacks are arriving promptly or being delayed/queued.
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

        // --- Auth: timing-safe apikey comparison ---
        $provided = isset($_GET['apikey']) ? (string) $_GET['apikey'] : '';
        $options  = get_option(WPS_IC_OPTIONS);
        $expected = is_array($options) && !empty($options['api_key']) ? (string) $options['api_key'] : '';
        if ($expected === '' || !hash_equals($expected, $provided)) {
            $respond(401, ['error' => 'auth']);
        }

        // --- Validate required query params + post_max_size ---
        $imageID = (int) ($_GET['imageID'] ?? 0);
        if (!$imageID || get_post_type($imageID) !== 'attachment') {
            $respond(404, ['error' => 'unknown_image']);
        }
        // Defensive max body size — base64 of a single variant should be under ~5MB
        $content_length = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($content_length > 10485760) { // 10 MiB cap
            $respond(413, ['error' => 'body_too_large', 'max' => 10485760]);
        }

        // --- Parse body ---
        // Body parsed BEFORE the race-guard (it used to come after) so the new
        // generation-stamped guard can read $body['compressStartedAt'] when service supplies
        // it. Cost: rejected callbacks now decode JSON before being rejected — but rejected
        // callbacks are exceptional (only fire on stale-generation), so the extra ~1ms per
        // reject is negligible. The content-length cap above still bounds worst-case decode.
        $raw = file_get_contents('php://input');
        if (empty($raw)) $respond(400, ['error' => 'empty_body']);
        $body = json_decode($raw, true);
        if (!is_array($body)) $respond(400, ['error' => 'invalid_json']);

        // Generation-stamped race guard. A bg-swap callback is stale if and only if
        // the most recent restore happened AFTER the compress that produced this callback.
        // Prefer the service-supplied `compressStartedAt` field (authoritative — identifies
        // the SPECIFIC compress this callback belongs to, even if a newer compress has since
        // fired). Falls back to plugin per-image post_meta when service is pre-v1.22.11.13.
        // The post_meta path catches the common race (compress→restore→stale callback); the
        // service-supplied path additionally catches the corner case
        // (compress→restore→recompress→stale callback from the FIRST compress arrives after
        // the recompress flips the post_meta forward). Forward-compatible: ships today,
        // auto-upgrades behavior the moment service adds the field — no plugin redeploy.
        //
        // Replaces the earlier simple `ic_status === 'restored'` check, which
        // false-positively rejected legitimate callbacks for long-running variants
        // (sizeLabel='original' encodes 60-120s and frequently arrives during unrelated
        // restore windows from later test cycles).
        // Service sends compressStartedAt in MILLISECONDS unix (Date.now() /
        // multer entry timestamp at v1.22.11.13+). Plugin post_meta stamps are in seconds
        // (time()). Convert ms→sec so both sides of the comparison share units. Defensive
        // floor() handles fractional values; (float) cast preserves precision through the
        // /1000 (a raw int cast would truncate before division on 32-bit PHP builds).
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

        // Service v1.22.11.10+ may signal "all widen alts lost; stop expecting this
        // variant" via {noImprovement: true, reason, baselineKb, widenAltKbs} instead of bytes.
        // Plugin opted in via X-Plugin-Accepts-NoImprovement: 1 on /optimize POST. Acknowledge,
        // tag the variant entry so UI can definitively render "no AVIF for this variant",
        // skip the bytes-write flow.
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

        // noImprovement branch: tag variant as "service gave up" and respond 200.
        // Skips the bytes-decode + atomic-write + meta-update flow entirely. UI consumers can
        // check $variant['bg_no_improvement'] === true to definitively render "no AVIF for
        // this variant" instead of treating the absence as still-pending.
        if ($is_no_improvement) {
            $norm_fmt    = ($format === 'jpg') ? 'jpeg' : $format;
            $lookup_key  = ($norm_fmt === 'jpeg') ? $sizeLabel : ($sizeLabel . '-' . $norm_fmt);
            $reason      = isset($body['reason']) ? sanitize_text_field((string) $body['reason']) : '';
            $baseline_kb = isset($body['baselineKb']) ? (float) $body['baselineKb'] : null;
            $widen_alts  = (isset($body['widenAltKbs']) && is_array($body['widenAltKbs']))
                ? array_values(array_map('floatval', $body['widenAltKbs']))
                : [];

            // Race-safe meta tag — same lock pattern as the bytes path. Concurrent bytes
            // callbacks for sibling variants may be writing simultaneously.
            global $wpdb;
            $ni_lock_name  = 'wpc_bg_meta_' . (int) $imageID;
            $ni_lock_state = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $ni_lock_name));
            $got_ni_lock   = ($ni_lock_state === '1' || $ni_lock_state === 1);
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
                    // No prior entry (fast-path SKIP'd this variant AND bg gave up). Create a
                    // minimal placeholder so future calls can see the no-improvement state.
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
                    $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $ni_lock_name));
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

        // --- Decode base64 ---
        $bytes = base64_decode($b64, true);
        if ($bytes === false || strlen($bytes) === 0) {
            $respond(400, ['error' => 'decode_fail']);
        }

        // --- Map (sizeLabel, format) to our storage key in ic_local_variants ---
        // Our schema: one entry per (size, format) pair. jpeg uses bare sizeLabel;
        // avif/webp suffix with "-avif" or "-webp".
        $variants = get_post_meta($imageID, 'ic_local_variants', true);
        if (!is_array($variants)) $variants = [];
        $norm_fmt = ($format === 'jpg') ? 'jpeg' : $format;
        $lookup_key = ($norm_fmt === 'jpeg') ? $sizeLabel : ($sizeLabel . '-' . $norm_fmt);
        $entry_exists = isset($variants[$lookup_key]);

        // --- Resolve filename ---
        // rc10.8.1+ path: service sends authoritative `fileName` in body. Used when fast-path
        // SKIPPED this variant — no ic_local_variants entry was created in Phase A, plugin
        // couldn't derive filename otherwise. Always preferred when present.
        // rc10.7 and earlier path: derive filename from existing variant's URL. Backward compat.
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
            // No body fileName AND no existing entry → can't know where to write.
            // (Pre-rc10.8.1 bg-swap for a SKIP'd variant — shouldn't happen in practice.)
            $respond(404, ['error' => 'unknown_variant', 'lookup' => $lookup_key]);
        }
        $attached  = get_attached_file($imageID);
        $dir       = $attached ? (dirname($attached) . '/') : '';
        if (!$dir) $respond(500, ['error' => 'no_attached_dir']);
        $wp_path = $dir . $file_name;

        // Defensive backup-ensure before destructive write. Bg-swap callbacks fire
        // OUTSIDE user-initiated compress flows (5-15× per compress, 30-120s after Phase A
        // returns). If cleanup_backups deleted the wpc-backups/ entry on a recent restore AND
        // a late callback arrives before the next user-initiated compress runs backup_all_sizes,
        // this write would destroy pristine bytes with no recovery path. backup_all_sizes() is
        // idempotent (`if (!file_exists($dest))` guards inside) so calling here is safe even
        // when backup already exists — it's a no-op fast path.
        if (class_exists('wps_local_compress')) {
            $compress_local = new wps_local_compress();
            if (method_exists($compress_local, 'backup_all_sizes')) {
                $compress_local->backup_all_sizes($imageID);
            }
        }

        // Content-based validation via shared helper. Adds minimum-size guard
        // (rejects 678-byte sentinel placeholders that the previous magic-only check let
        // through under certain encoder failure modes) + tracking log with source attribution.
        if (!wpc_is_valid_image_bytes($bytes, $norm_fmt, $imageID, 'bg_swap_callback')) {
            $respond(400, ['error' => 'invalid_image_bytes']);
        }

        // --- Atomic write: tmp + rename (same pattern as Phase A inline writes) ---
        $tmp = $wp_path . '.wpc_bgswap_' . wp_generate_password(8, false);
        if (@file_put_contents($tmp, $bytes, LOCK_EX) === false) {
            @unlink($tmp);
            $respond(500, ['error' => 'write_fail']);
        }
        if (!@rename($tmp, $wp_path)) {
            @unlink($tmp);
            $respond(500, ['error' => 'rename_fail']);
        }
        @chmod($wp_path, 0644);

        // Targeted single-URL CDN purge for the file we just rewrote.
        // Pattern A bg-swap is the FIRST writer for thumbs (fast pod ships only
        // parent + scaled inline); without this, the MC pod and Bunny edge cache
        // continue serving the pre-swap state until natural TTL expiry. Calling
        // wpc_purge_cdn_urls (full-attachment) here would fan out 16+ requests
        // per callback × ~9 callbacks per image = 144+ purges for a single drain.
        // Single-URL purge: 1 request per callback, 9 per image. Non-blocking.
        if (function_exists('wpc_purge_cdn_urls_single')) {
            wpc_purge_cdn_urls_single($imageID, $wp_path);
        }

        // --- Update ic_local_variants with bg-upgrade telemetry ---
        // When the variant entry doesn't exist yet (rc10.8.1 fast-path SKIP scenario),
        // create a fresh entry now that bg has delivered the bytes. fastPathKb=null from
        // service signals SKIP path explicitly — we capture that as bg_source='skip_path'
        // for observability (distinguishes "bg upgraded fast-path bytes" from "bg created
        // what fast-path skipped").
        $bg_kb         = $body['bgKb'] ?? null;
        $bg_s2         = $body['bgS2'] ?? null;
        $bg_q          = $body['bgQ'] ?? null;
        $fast_kb       = $body['fastPathKb'] ?? null;
        $fast_s2       = $body['fastPathS2'] ?? null;
        $was_skipped_on_fast_path = ($fast_kb === null);

        // Race-safe meta update. Multiple bg-swap callbacks arrive concurrently for
        // the same image (one per size×format). Read-modify-write on ic_local_variants would
        // lose entries (last-writer-wins). Acquire per-image lock, re-read fresh state inside
        // the critical section, mutate ONLY our key, write, release.
        //
        // MySQL GET_LOCK is the primary mechanism (truly atomic at server level,
        // no TOCTOU race possible). Transient-based lock kept as fallback for hosts where
        // GET_LOCK is unavailable. Post-write verification + retry catches any edge case.
        global $wpdb;
        $mysql_lock_name = 'wpc_bg_meta_' . (int) $imageID;
        // Lock-acquire timing for diagnostics. Captures real wait time
        // when 9 simultaneous callbacks for the same imageID serialize behind
        // GET_LOCK. Service team uses this to correlate per-image lock pile-up
        // with end-to-end callback latency.
        $bgswap_lock_t0 = microtime(true);
        $mysql_lock_state = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $mysql_lock_name));
        $got_mysql_lock = ($mysql_lock_state === '1' || $mysql_lock_state === 1);

        // Layer 2 fallback: transient-based lock when GET_LOCK is unavailable
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
                if (!$got_transient_lock) usleep(50000); // 50ms
            }
        }
        $bgswap_lock_acq_ms = (int) round((microtime(true) - $bgswap_lock_t0) * 1000);
        // Log lock-acquire wait if non-trivial. Typical is <5ms; >50ms
        // indicates contention from concurrent callbacks for the same imageID.
        if ($bgswap_lock_acq_ms >= 50) {
            error_log(sprintf(
                '[WPC BgSwap LOCK] imageID=%d size=%s fmt=%s acq_ms=%d via=%s',
                $imageID, $sizeLabel, $norm_fmt, $bgswap_lock_acq_ms,
                $got_mysql_lock ? 'mysql' : ($got_transient_lock ? 'transient' : 'failed')
            ));
        }
        $bgswap_crit_t0 = microtime(true);
        try {
            // Re-read INSIDE the lock — concurrent callbacks may have committed since our read at line ~1054
            $variants_fresh = get_post_meta($imageID, 'ic_local_variants', true);
            if (!is_array($variants_fresh)) $variants_fresh = [];

            if (!isset($variants_fresh[$lookup_key])) {
                $variants_fresh[$lookup_key] = [
                    'url'          => '',  // no CDN URL — bytes came inline via bg-swap
                    'originalSize' => 0,   // not known from bg-swap body alone
                    'size'         => strlen($bytes),
                    'savings'      => 0,   // not computable without originalSize
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

            // Defense-in-depth: re-read after write, verify our entry landed.
            // GET_LOCK should make this impossible to fail, but if fallback path was used
            // and TOCTOU race occurred, this catches it and retries once.
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
            $variants = $variants_fresh; // for downstream code that uses $variants
        } finally {
            // Critical-section duration log. Measures total time the
            // per-image lock was HELD (not just acquire wait). The mirror block's
            // wp_update_attachment_metadata() call inside the lock can fire 3rd-
            // party hooks (CDN sync, sitemap, regen-thumbnails plugins) that
            // extend this — visibility from before the mirror was moved out of the lock.
            $bgswap_crit_ms = (int) round((microtime(true) - $bgswap_crit_t0) * 1000);
            if ($bgswap_crit_ms >= 100) {
                error_log(sprintf(
                    '[WPC BgSwap CRIT] imageID=%d size=%s fmt=%s crit_ms=%d',
                    $imageID, $sizeLabel, $norm_fmt, $bgswap_crit_ms
                ));
            }
            if ($got_mysql_lock) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $mysql_lock_name));
            }
            if ($got_transient_lock) {
                if ($has_obj_cache && function_exists('wp_cache_delete')) wp_cache_delete($lock_key, 'wpc');
                delete_transient($lock_key);
            }
        }

        // Mirror block MOVED OUTSIDE the per-image GET_LOCK so concurrent
        // bg-swap callbacks for the same imageID don't serialize behind the slow
        // wp_update_attachment_metadata() hook fan-out. Per service team's smoke #2
        // analysis (2026-05-08): 9 callbacks for one image were taking >5 min to
        // drain because wp_attachment_metadata_updated hooks (3rd-party plugins:
        // CDN sync, sitemap rebuild, regen-thumbnails) extended each critical
        // section to ~30s, multiplied by 9 = 4.5 min serialization.
        //
        // Trade-off: small TOCTOU race window where concurrent mirrors for
        // different sizeLabels of the same imageID can read $wp_meta before
        // each other's write lands → one's update may be lost. Acceptable
        // because (1) low frequency in practice (mirrors arrive ms-staggered
        // due to lock-release ordering), (2) lost mirror entry gets re-written
        // on next compress for that variant, (3) the $meta['sizes'] mirror is
        // a non-critical accelerator — Modern Delivery's <picture> builder
        // doesn't depend on it (reads ic_local_variants which IS lock-protected).
        //
        // Only mirror JPEG. WP's $meta['sizes'] schema is single-mime-per-entry —
        // adding image/webp or image/avif rows would confuse wp_get_attachment_image_src
        // and other WP-core consumers.
        //
        // Skip 'original' and 'scaled' — top-level fields in _wp_attachment_metadata,
        // not $meta['sizes'] entries. Their bg-swap refinement is handled by the
        // disk write above.
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
                    // Instrumentation: split mirror_ms into setup_ms (our prep work)
                    // and wp_meta_update_ms (the wp_update_attachment_metadata call
                    // itself, which fires `wp_update_attachment_metadata` filter +
                    // `attachment_updated` action — where 3rd-party plugins hook).
                    // If wp_meta_update_ms dominates, a conditional skip is the
                    // optimization. If setup_ms dominates, we have a different problem.
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

        // Single source of truth via wpc_compute_best_savings().
        // Replaces the inline recompute that used to live here. Now uses the
        // canonical 4-tier originalSize lookup (modern-delivery.php) — matches the
        // modal's logic so card and modal can never disagree.
        $best = wpc_compute_best_savings($variants, $imageID);
        if ($best['pct'] > 0 && $best['orig'] > 0) {
            update_post_meta($imageID, 'ic_savings',          round($best['pct'], 1));
            update_post_meta($imageID, 'ic_savings_format',   $best['format']);
            update_post_meta($imageID, 'ic_savings_bytes',    $best['orig'] - $best['opt']);
            update_post_meta($imageID, 'ic_savings_baseline', $best['orig']);
        }

        // Promote the card to Compressed the moment the FIRST bg-swap variant
        // lands. Without this, the badge sits at "Optimizing" through the entire Phase A
        // wall (2-13s typical; up to 60s worst case) even though variant bytes are already
        // arriving from service-side bg pods. User experience: "when the first bg variant
        // or anything at all comes it should show compressed then sequentially bump".
        //
        // Two writes flip compress_details() from the Optimizing branch (line 833 in
        // media_library_live.class.php — gated by wps_ic_compress_ transient) to the
        // Compressed branch (line 848 — gated by ic_compressing.status === 'compressed').
        // Both writes are idempotent: Phase A end at compress.php:6266 + :6295 will
        // overwrite/no-op them once Phase A completes. The heartbeat-handler skip
        // gate continues to work — the render is now genuinely compressed once these
        // fire, so the handler returns the swap rather than skipping.
        update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
        delete_transient('wps_ic_compress_' . $imageID);

        // Ping the media-library heartbeat so the card re-renders with the new savings %.
        // Unified heartbeat: status='compressed' is the SETTLED state. bg-swap
        // callbacks send status='compressed' + event='bg_variant_arrived' delta so JS
        // can fire the chip + counter animations under the settled Compressed badge.
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

        // Ack-detail response + structured ACK log. Service team's swapDecisionReason
        // pipeline records (per v1.22.11.19.2) shows whether the plugin's persistence side
        // succeeded — so any "service delivered, plugin lost it" cases are clearly attributable.
        // Fields:
        //   variant_persisted: did the in-memory $variants_fresh array get written to post_meta
        //   verify_post_meta:  did re-reading post_meta confirm the entry landed
        //   verify_disk:       does the optimized file exist on disk
        //   skip_reason:       null on happy path; populated on any short-circuit
        //   compress_started_at + callback_arrival_t: timing for service-side correlation
        $verify_post_meta = !empty($entry_landed);
        $verify_disk      = file_exists($wp_path) && @filesize($wp_path) === strlen($bytes);
        $variant_persisted = $verify_post_meta;
        $callback_arrival_t = time();
        // compress_started_at: prefer the service-supplied value (already converted to seconds at
        // the race-guard step), falling back to plugin post_meta. $callback_cs is in scope from
        // the race guard if service supplied; else read post_meta directly.
        $cs_for_ack = isset($callback_cs) && $callback_cs > 0
            ? (int) $callback_cs
            : (int) get_post_meta($imageID, '_wpc_compress_started_at', true);

        // Release any backfill lock for this variant tuple now that the
        // bytes have landed in ic_local_variants. The render-path emission
        // may have queued an encode under wpc_backfill_lock_{N}_{sizeLabel}_{fmt};
        // releasing here lets the next render's emission re-queue if a future
        // refinement is needed. No-op if no lock was held (harmless).
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
            // ack-detail fields for service-side correlation
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

// Async thumbnail-regeneration worker. Called after restoreV4 Phase A completes
// to rebuild WP size variants (thumbnail, medium, medium_large, large, 1536, 2048, scaled)
// from the restored unscaled original. Deferred because wp_generate_attachment_metadata is
// CPU-bound (5-15s on large images). Moving it out of the restore AJAX drops user-visible
// restore time from 15s → ~1-2s. Frontend is unaffected during the ~10-15s regen window
// (doesn't use local thumbnails). Admin media library previews show stale thumbnails briefly.
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

        // Global concurrency cap (default 1 = strict serial). Bulk-restore of 10+
        // images previously fired 10 parallel workers, each calling wp_generate_attachment_metadata
        // (CPU+memory bound). On 64M shared hosts this OOM'd, saturated PHP-FPM, and made
        // unrelated AJAX (compress) silently fail. Cap=1 means only ONE regen running at any
        // moment — predictable memory peak, admin stays responsive. Excess workers exit
        // immediately and leave the post_meta marker for self-chain or admin_init drain.
        // Override via define('WPC_MAX_CONCURRENT_REGEN', N) in wp-config.php for VPS+ hosts.
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
                $ic = new wps_local_compress();
                remove_filter('wp_generate_attachment_metadata', [$ic, 'on_upload'], PHP_INT_MAX);
            }

            $t_start = microtime(true);
            $newMeta = wp_generate_attachment_metadata($imageID, $regenSource);
            if ($newMeta && !is_wp_error($newMeta)) {
                wp_update_attachment_metadata($imageID, $newMeta);
            }
            $regen_duration = round(microtime(true) - $t_start, 2);

            // Tier 3 verification. wp_generate_attachment_metadata can return populated
            // $newMeta even when one specific size's file failed to write (memory pressure on a
            // single resize). Without a post-write check, the image gets marked "regen done" but
            // admin previews silently miss that one size. Verify each registered size physically
            // exists; if any missing, schedule one retry. After 2 failed attempts, accept the
            // loss and log loud diagnostic for support.
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

                // Post-restore grace window: blocks on_upload-triggered
                // re-compress for 30s after regen completes. Without this, late
                // wp_generate_attachment_metadata calls (from a 3rd-party plugin
                // hooking the same metadata, from a heartbeat-triggered re-render
                // path, or from a user click moments after restore) cascade into
                // on_upload, queue a fresh compress, whose bg-swap callbacks then
                // overwrite the just-restored pristine bytes 20-30s later.
                // Observed on staging 2026-05-09 across images 104/113/122.
                // The grace window is short (30s default) so user-initiated manual
                // compress immediately after restore still works after 30s. Override
                // via define('WPC_POST_RESTORE_GRACE_SECONDS', N) in wp-config.php.
                $grace_s = defined('WPC_POST_RESTORE_GRACE_SECONDS')
                    ? max(1, (int) WPC_POST_RESTORE_GRACE_SECONDS)
                    : 30;
                set_transient('wpc_post_restore_grace_' . $imageID, time(), $grace_s);
                error_log('[WPC RegenThumbs] image=' . $imageID . ' duration=' . $regen_duration . 's mode=' . ($plan['backup_mode'] ?? 'unknown') . ' cap=' . $cap . ' verified=' . count($newMeta['sizes'] ?? []) . ' grace=' . $grace_s . 's');

                // Trigger heartbeat re-render so the media-library card flips out of
                // its "Restoring..." UI lock (set by compress_details when _wpc_pending_thumb_regen
                // is present). Without this, the card would stay locked until something else
                // happens to fire a heartbeat for the image. status='restored' is the existing
                // state the heartbeat handler routes to a fresh compress_details() call.
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored', 'time' => time()], 60);
            }
        } finally {
            // Decrement counter on EVERY exit (success, race-guard abort, exception).
            $current = (int) get_transient('wpc_regen_active_count');
            set_transient('wpc_regen_active_count', max(0, $current - 1), 300);
            delete_transient($lock_key);
            // Self-chain to next pending regen so the queue drains without cron.
            // Each finishing worker fires the loopback for ONE more pending image. If none
            // pending, chain stops naturally. Combined with admin_init Layer 3 drain.
            if (function_exists('wpc_chain_next_pending_regen')) {
                wpc_chain_next_pending_regen($imageID);
            }
        }
    }
    add_action('wpc_regen_thumbs', 'wpc_regen_thumbs_hook', 10, 1);

    // Layer 1 AJAX endpoint — loopback POST target
    function wpc_regen_thumbs_ajax()
    {
        wpc_regen_thumbs_hook((int) ($_REQUEST['imageID'] ?? 0));
        wp_die();
    }
    add_action('wp_ajax_wpc_regen_thumbs',        'wpc_regen_thumbs_ajax');
    add_action('wp_ajax_nopriv_wpc_regen_thumbs', 'wpc_regen_thumbs_ajax');
}

// Self-chaining drain helper. Called from the finally{} of wpc_regen_thumbs_hook
// after a regen finishes (success or otherwise). Picks the OLDEST pending regen (oldest
// meta_id wins) and fires its loopback. If no other regens are pending, chain stops.
// Combined with admin_init Layer 3 drain, this gives cron-free queue progress on every
// host class.
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

// Helper: does the parent attachment have a backup we can fall back to?
// Returns true if EITHER:
//   (a) Bunny CDN backup path is recorded in `wpc_backup_path` post_meta (set after service backs up source)
//   (b) Local /wpc-backups/<file> exists on disk (the per-attachment local backup folder)
//   (c) Sibling _bkp file exists next to the attachment (legacy local backup pattern)
// Used to drive the v1.22.8 `skipBackup='1'` body param — we only tell the service to skip
// backup when we're SURE the parent is already safe. If no backup is detected, skipBackup='0'
// (default service behavior: back up the file).
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

// Lazy variant, per spec resources/dev-latest/local/.../LAZY-VARIANT-INTEGRATION.md.
// Phase A: parent POST. One call per imageID, BEFORE any per-variant fills. Sends parent
// original bytes; service encodes 3 formats; backs up to Bunny async; returns inline bytes.
// Plugin saves the 3 format files to disk. Establishes the parent's `images` table row that
// per-variant POSTs reference via parentImageID (= same imageID).
if (!function_exists('wpc_lazy_optimize_parent')) {
    function wpc_lazy_optimize_parent($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID || !class_exists('wps_local_compress')) return false;
        $parent_path = function_exists('get_attached_file') ? get_attached_file($imageID) : '';
        if (!$parent_path || !file_exists($parent_path)) return false;

        // Defensive backup-ensure before destructive write. Lazy-parent runs OUTSIDE
        // user-initiated compress flows (Modern Delivery on-demand trigger). Same protection
        // pattern as wpc_lazy_fill_variant + bg-swap callback: idempotent backup_all_sizes()
        // call ensures wpc-backups/ has pristine bytes before optimized bytes hit disk.
        $backup_compress = new wps_local_compress();
        if (method_exists($backup_compress, 'backup_all_sizes')) {
            $backup_compress->backup_all_sizes($imageID);
        }

        $params = wps_local_compress::buildOptimizeParams($imageID);
        // Parent POST: empty crops (no thumbs in lazy mode), single filenames entry for original
        $params['crops']          = '{}';
        $params['filenames']      = wp_json_encode(['original' => basename($parent_path)]);
        $params['triggerContext'] = 'lazy_fill_parent';
        // sizeLabel / skipBackup / parentImageID intentionally omitted on parent POST.
        // Defense-in-depth: hard-code AVIF/WebP/level so the request remains
        // correct even when picture_avif setting briefly toggles off, an upgrade reset
        // wipes settings, or a custom theme filter mutates buildOptimizeParams output.
        // Mirrors wpc_generate_ladder_widths (line 660) for consistent behavior across
        // all 3 ladder paths.
        $params['avif']  = '1';
        $params['webp']  = '1';
        $params['level'] = 'intelligent';
        // Race-guard timestamp (ms since epoch). Service uses this to filter
        // stale bg-swap callbacks against more recent compresses for the same image.
        // Without it, late-arriving callbacks from a prior cycle can land after a fresh
        // compress and clobber its variants. Set per service team's required-field list.
        $params['compressStartedAt'] = (int) round(microtime(true) * 1000);

        $response = wps_local_compress::postOptimize($imageID, $params, true, 60);
        if (is_wp_error($response)) {
            error_log('[WPC LazyParent] image=' . $imageID . ' failed: ' . $response->get_error_message());
            return false;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (empty($data['success']) || empty($data['optimizedResults'])) return false;

        // Save inline bytes to disk (atomic tmp + rename)
        $upload_dir = wp_upload_dir();
        $base_dir = rtrim($upload_dir['basedir'], '/') . '/' . dirname(get_post_meta($imageID, '_wp_attached_file', true));
        $written = 0;
        foreach ($data['optimizedResults'] as $variant) {
            if (empty($variant['fileName']) || empty($variant['bytes'])) continue;
            $bytes = base64_decode($variant['bytes'], true);
            if ($bytes === false) continue;
            // Validate before writing. Determine format from filename extension.
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

// Lazy variant Phase B: per-variant POST. Sends pre-resized variant bytes (NOT parent).
// Service encodes 3 formats from received bytes, skips backup (parent already safe).
// Plugin writes 3 format files to disk. Called per missing srcset width.
if (!function_exists('wpc_lazy_fill_variant')) {
    function wpc_lazy_fill_variant($imageID, $sizeLabel, $variantBytes, $variantFilename)
    {
        $imageID = (int) $imageID;
        if (!$imageID || !class_exists('wps_local_compress')) return false;
        if (empty($sizeLabel) || empty($variantBytes) || empty($variantFilename)) return false;

        // Defensive backup-ensure before destructive write. Lazy-fill writes optimized
        // variant bytes to disk (line ~1794) but runs OUTSIDE user-initiated compress flows.
        // If cleanup_backups deleted the wpc-backups/ entry on a recent restore AND lazy-fill
        // fires before the next user-initiated compress, the destructive write would have no
        // recovery path. backup_all_sizes() is idempotent (file_exists guards inside); calling
        // here is safe even when backup already exists — fast no-op.
        $backup_compress = new wps_local_compress();
        if (method_exists($backup_compress, 'backup_all_sizes')) {
            $backup_compress->backup_all_sizes($imageID);
        }

        // Write variant bytes to a tmp file for CURLFile upload
        $tmp_dir = function_exists('get_temp_dir') ? rtrim(get_temp_dir(), '/') : sys_get_temp_dir();
        $tmp_path = $tmp_dir . '/wpc_lazy_' . $imageID . '_' . wp_generate_password(8, false) . '_' . $variantFilename;
        if (file_put_contents($tmp_path, $variantBytes) === false) return false;

        $params = wps_local_compress::buildOptimizeParams($imageID);
        $params['crops']             = '{}';
        $params['filenames']         = wp_json_encode([$sizeLabel => $variantFilename]);
        $params['triggerContext']    = 'lazy_fill_variant';
        // v1.22.8 lazy-variant body params:
        $params['sizeLabel']         = $sizeLabel;
        $params['skipBackup']        = '1'; // REQUIRED — service must not back up the variant as if it were the parent
        // REQUIRED per v1.22.8 spec. Without parentImageID the service may treat each
        // variant POST as an independent unbacked parent, breaking quality-training attribution
        // and bg-swap rails. Even when imageID happens to match attachmentId, the contract requires it.
        $params['parentImageID']     = (string) $imageID;
        $params['file_path_override'] = $tmp_path;
        // Per plugin-team handoff (cold logs 2026-05-04 confirmed AVIF was missing
        // from /optimize POSTs for wpc_* breakpoints). Hard-code AVIF/WebP/level so the
        // request is correct regardless of whether buildOptimizeParams happens to read
        // picture_avif=1 from settings. Mirrors wpc_generate_ladder_widths (line 660).
        // Without these, every wpc_* size shipped as fast-only WebP+JPEG at SAFE_Q=78
        // (no AVIF, no bg-refinement) — 30-40% bigger bytes than necessary on 7 sizes.
        $params['avif']  = '1';
        $params['webp']  = '1';
        $params['level'] = 'intelligent';
        // Race-guard timestamp (ms since epoch). Service uses this to filter
        // stale bg-swap callbacks against newer compresses for the same image. Required
        // per service team's v1.22.11.10+ contract — without it, late callbacks can land
        // after a fresh restore→compress cycle and clobber the new variants.
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

        // Save inline bytes to disk + persist into ic_local_variants directly.
        // Don't depend on the bg-swap callback for primary persistence —
        // bg-swap callbacks land asynchronously and can drop, leaving inline
        // bytes orphaned on disk with no ic_local_variants entry.
        $upload_dir = wp_upload_dir();
        $base_dir = rtrim($upload_dir['basedir'], '/') . '/' . dirname(get_post_meta($imageID, '_wp_attached_file', true));
        $written = 0;
        $persist_entries = [];
        $upload_url = $upload_dir['baseurl'] . '/' . dirname(get_post_meta($imageID, '_wp_attached_file', true));
        foreach ($data['optimizedResults'] as $variant) {
            if (empty($variant['fileName']) || empty($variant['bytes'])) continue;
            $bytes = base64_decode($variant['bytes'], true);
            if ($bytes === false) continue;
            // Validate before writing.
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

// Lazy variant ladder orchestrator. Called by wpc_generate_ladder_widths when the
// `wpc_lazy_variant_mode` setting is on. Per the v1.22.8 spec:
//   1. Acquire transient lock to prevent two threads racing
//   2. Verify parent is optimized (look for parent's avif/webp on disk via ic_local_variants);
//      if not, call wpc_lazy_optimize_parent first
//   3. For each missing width: resize parent locally to target → call wpc_lazy_fill_variant
//   4. Release lock
// Sequential per-variant POSTs (not parallel) — keeps shared-host load predictable.
if (!function_exists('wpc_run_lazy_variant_ladder')) {
    function wpc_run_lazy_variant_ladder($attachmentId, $widths, $trigger_source, $t_start, $meta)
    {
        // Mode-aware dispatch:
        //   - manual mode: skip entirely. Manual means NO auto-encoding —
        //     the customer must click Compress per-image or use bulk-compress.
        //   - lazy_* modes (lazy_full, lazy_smart, lazy_cdn): route to v2
        //     path (run_v2_optimize via async admin-ajax loopback) instead
        //     of the legacy v1 /optimize POSTs.
        //   - legacy mode (or unknown): fall through to the v1 ladder below.
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
            // Prefer the unscaled-original as the resize source when it exists
            // AND is larger (in width) than the attached file. Without this, ladder widths
            // ABOVE the attached file's width fall through wpc_lazy_resize_to_bytes' "don't
            // upscale" guard at line ~1988, which returns the source bytes unchanged. The
            // service then receives a `wpc_1920` POST whose bytes are actually 1887w (the
            // -scaled.jpg), detects the dimension mismatch, and silently drops it — visible
            // in service-side cold logs as wpc_1920/wpc_2831 "never reached optimizeImage".
            //
            // Cold-log evidence (image 122, 2026-05-04): 5 of 7 ladder widths reached
            // service (480/721/1170/1290/1350); 1920 + 2831 dropped because their target
            // exceeded the scaled-file width of 1887.
            //
            // Fix: probe wp_get_original_image_path() and use it as $parent_path when it
            // exists, is readable, and is bigger than the attached file. All ladder widths
            // then resize cleanly down from the actual largest pixel source on disk.
            $parent_path = get_attached_file($attachmentId);
            if (!$parent_path || !file_exists($parent_path)) return false;

            if (function_exists('wp_get_original_image_path')) {
                $unscaled = wp_get_original_image_path($attachmentId);
                if ($unscaled
                    && $unscaled !== $parent_path
                    && file_exists($unscaled)
                    && is_readable($unscaled)) {
                    // Probe widths via getimagesize — only swap when unscaled is genuinely larger.
                    $att_info  = @getimagesize($parent_path);
                    $orig_info = @getimagesize($unscaled);
                    $att_w  = is_array($att_info)  ? (int) ($att_info[0]  ?? 0) : 0;
                    $orig_w = is_array($orig_info) ? (int) ($orig_info[0] ?? 0) : 0;
                    if ($orig_w > $att_w && $orig_w > 0) {
                        $parent_path = $unscaled;
                    }
                }
            }

            // Step 2: ensure parent is optimized. Check ic_local_variants for the parent's
            // base format files. If absent, run lazy parent POST first.
            $variants = get_post_meta($attachmentId, 'ic_local_variants', true);
            $parent_has_avif = is_array($variants) && (isset($variants['original-avif']) || isset($variants['scaled-avif']));
            if (!$parent_has_avif) {
                $ok = wpc_lazy_optimize_parent($attachmentId);
                if (!$ok) {
                    error_log('[WPC LazyLadder] image=' . $attachmentId . ' parent optimize failed — abort');
                    return false;
                }
            }

            // Step 3: resolve each width's sizeLabel + filename, then per-variant POST.
            $delivered = 0;
            $source_width = WPC_Modern_Delivery::get_source_width($attachmentId, $meta);
            foreach ($widths as $w) {
                $w = (int) $w;
                if ($w <= 0) continue;
                $resolved = WPC_Modern_Delivery::resolve_variant_filename($meta, $w, 'jpg', $source_width);
                if ($resolved === null) continue; // > source or missing meta
                $size_label = $resolved['size_label'];
                $filename   = $resolved['filename'];

                // Local resize (fast: ~100-500ms; ~30-60 MB peak)
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

// Helper: locally resize parent attachment to target width. Returns raw JPEG bytes
// for upload to the lazy-variant endpoint. Uses WP's wp_get_image_editor (Imagick or GD).
// Fast (typically 100-500ms per resize); single-size memory peak is ~30-60 MB which fits
// in 64 M shared. Only the resize happens locally; the 3-format encode stays on service.
if (!function_exists('wpc_lazy_resize_to_bytes')) {
    function wpc_lazy_resize_to_bytes($parentPath, $targetWidth, $targetHeight = 0)
    {
        if (!$parentPath || !file_exists($parentPath)) return false;
        if (!function_exists('wp_get_image_editor')) return false;
        $editor = wp_get_image_editor($parentPath);
        if (is_wp_error($editor)) return false;
        $size = $editor->get_size();
        if (!is_array($size) || empty($size['width'])) return false;
        // Don't upscale — if target ≥ source, return source bytes directly.
        if ($targetWidth >= (int) $size['width']) {
            return file_get_contents($parentPath);
        }
        $resize = $editor->resize($targetWidth, $targetHeight ?: null, false);
        if (is_wp_error($resize)) return false;
        // Stream to memory via tmp file
        $tmp_dir = function_exists('get_temp_dir') ? rtrim(get_temp_dir(), '/') : sys_get_temp_dir();
        $tmp = $tmp_dir . '/wpc_lazy_resize_' . wp_generate_password(8, false) . '.jpg';
        $saved = $editor->save($tmp, 'image/jpeg');
        if (is_wp_error($saved) || empty($saved['path']) || !file_exists($saved['path'])) return false;
        $bytes = file_get_contents($saved['path']);
        @unlink($saved['path']);
        return $bytes;
    }
}

// Resolve a size_label string back to its target pixel width.
//   - 'scaled'                       → meta['width']
//   - 'original'                     → unscaled-original width (probe disk) || meta['width']
//   - 'wpc_<N>' / '<N>w' / '<N>x<N>' → N
//   - <wp-registered-size>           → meta['sizes'][$label]['width']
// Returns 0 when the label can't be resolved.
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

        // Legacy `thumb` label (sibling of WP's `thumbnail` size) used by
        // older plugin versions. Map to the WP-registered thumbnail width when present;
        // fall back to 150 (WP's default thumbnail width) otherwise.
        if ($size_label === 'thumb') {
            return (int) ($meta['sizes']['thumbnail']['width'] ?? 150);
        }

        return 0;
    }
}

// DEPRECATED in favor of batch-mode backfill (see wpc_backfill_missing_avif).
// Kept as a thin wrapper for any external caller that referenced it during the
// brief window when it existed. The per-variant path the old cli used did not
// reliably produce AVIF for wpc_* sizeLabels — service returned only JPEG+WebP
// inline. The batch path used by wpc_generate_ladder_widths produces all 3
// formats including AVIF and is what wpc_backfill_missing_avif now delegates to.
if (!function_exists('wpc_lazy_fill_variant_avif_only')) {
    function wpc_lazy_fill_variant_avif_only($imageID, $sizeLabel, $variantBytes, $variantFilename)
    {
        if (!function_exists('wpc_lazy_fill_variant')) return false;
        return wpc_lazy_fill_variant($imageID, $sizeLabel, $variantBytes, $variantFilename);
    }
}

// One-shot AVIF backfill orchestrator. Detects width slots in
// ic_local_variants that have WebP and/or JPEG entries but no matching AVIF
// and re-runs the proven BATCH ladder path (`wpc_generate_ladder_widths` with
// `WPC_DISABLE_LAZY_VARIANT` defined) so the service receives a single POST
// with all missing widths in one crops map. Service returns inline AVIF +
// WebP + JPEG for each width; ladder code writes bytes to disk and persists
// each entry directly into ic_local_variants via wpc_persist_inline_variants.
//
// Why batch instead of per-variant lazy-fill: cold-log evidence on staging
// 2026-05-05 showed the per-variant lazy-fill path returns only JPEG + WebP
// inline for wpc_* sizeLabels — AVIF was missing entirely (`written=2/2`).
// Batch path produces all 3 formats consistently across all sizeLabels.
//
// Returns ['queued' => N, 'reason' => string, 'targets' => [widths]].
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

        // Build coverage map: size_label → {avif, webp, jpeg booleans}.
        // - '<base>-avif' / '<base>-webp' / '<base>-jpeg|jpg' → base + that format
        // - unsuffixed key → JPEG by convention (legacy storage shape)
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

        // Source-width upper bound from the same three-tier probe the ladder uses
        // (attached → wp_get_original_image_path → wp-content/wpc-backups/...).
        // wpc_generate_ladder_widths' resolve_variant_filename internally uses
        // WPC_Modern_Delivery::get_source_width which probes get_attached_file +
        // wp_get_original_image_path; we extend with a backup-file probe here so we
        // can still drop unsupported widths up front (avoiding wasted POST overhead).
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

        // Map size_labels to widths. wpc_generate_ladder_widths takes a flat list of
        // widths and resolves each via resolve_variant_filename → so duplicates from
        // labels that map to the same width are deduped naturally.
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

        // Force the BATCH path (not lazy per-variant). The lazy per-variant path returned
        // only JPEG+WebP inline for wpc_* sizeLabels (cold-log 2026-05-05); the batch path
        // reliably produces all 3 formats for every sizeLabel. PHP constants are immutable
        // once defined, so this affects only the current request — which is fine for cli.
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
        // Loopback goes to the local vhost rather than wp_remote_post(blocking=false) to admin_url()'s
        // PUBLIC host: on a datacenter-IP site that host is the CDN/WAF edge, so the request is truthy
        // but never lands on local PHP-FPM. Connect-only via the proven shared helper; cookieless
        // (wpc_regen_thumbs is nopriv-registered with no user/nonce check, so this is
        // behaviour-preserving). Layer-3 admin-pageview drain stays the backstop.
        $rt_parts = wp_parse_url(admin_url('admin-ajax.php'));
        if (!empty($rt_parts['host'])) {
            $rt_https = (!empty($rt_parts['scheme']) && $rt_parts['scheme'] === 'https');
            $rt_port  = !empty($rt_parts['port']) ? (int) $rt_parts['port'] : ($rt_https ? 443 : 80);
            $rt_host  = (string) $rt_parts['host'];
            $rt_path  = (!empty($rt_parts['path']) ? $rt_parts['path'] : '/') . '?action=wpc_regen_thumbs';
            $rt_body  = http_build_query(['imageID' => (int) $imageID]);
            $rt_req   = "POST {$rt_path} HTTP/1.1\r\nHost: {$rt_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($rt_body) . "\r\nConnection: close\r\nUser-Agent: WPCRegenThumbs/1.0\r\n\r\n" . $rt_body;
            $rt_fp = wps_ic_ajax::wpc_loopback_open_socket($rt_host, $rt_port, $rt_https, 0.2);
            if ($rt_fp) { @stream_set_timeout($rt_fp, 0, 100000); @fwrite($rt_fp, $rt_req); @fclose($rt_fp); }
        }
    }
}

// Single-image retry after transient POST failure. Scheduled by wpc_handle_single_compress
// with exponential backoff (30s → 120s → 300s, max 3 attempts).
if (!function_exists('wpc_retry_compress_hook')) {
    function wpc_retry_compress_hook($imageID)
    {
        $imageID = (int) $imageID;
        if (!$imageID || get_post_type($imageID) !== 'attachment') return;
        if (!class_exists('wps_local_compress')) return;
        // Per-image lock to prevent loopback + cron + admin_init firing the
        // same retry concurrently (each layer can fire within seconds of the others).
        // 60s TTL covers worst-case singleCompressV4 + thumb regen run.
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

// Layer 1 (loopback) dispatcher for retry compress. Mirrors wpc_fire_download_worker
// pattern. Sub-second roundtrip on healthy hosts; bypasses WP-cron-traffic-dependency entirely.
// Skips on Basic-Auth sites (loopback can't authenticate) — falls back to layers 2+3.
if (!function_exists('wpc_fire_retry_compress_worker')) {
    function wpc_fire_retry_compress_worker($imageID)
    {
        if (function_exists('wpc_site_has_basic_auth') && wpc_site_has_basic_auth()) return;
        // Loopback goes to the local vhost rather than wp_remote_post(blocking=false) to admin_url()'s
        // PUBLIC host: on a datacenter-IP site that host is the CDN/WAF edge, so the request is truthy
        // but never lands on local PHP-FPM. Connect-only via the proven shared helper; cookieless
        // (wpc_retry_compress_loopback is nopriv-registered with no user/nonce check, so this is
        // behaviour-preserving). Backoff re-schedule stays the backstop.
        $rc_parts = wp_parse_url(admin_url('admin-ajax.php'));
        if (!empty($rc_parts['host'])) {
            $rc_https = (!empty($rc_parts['scheme']) && $rc_parts['scheme'] === 'https');
            $rc_port  = !empty($rc_parts['port']) ? (int) $rc_parts['port'] : ($rc_https ? 443 : 80);
            $rc_host  = (string) $rc_parts['host'];
            $rc_path  = (!empty($rc_parts['path']) ? $rc_parts['path'] : '/') . '?action=wpc_retry_compress_loopback';
            $rc_body  = http_build_query(['imageID' => (int) $imageID]);
            $rc_req   = "POST {$rc_path} HTTP/1.1\r\nHost: {$rc_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                      . "Content-Length: " . strlen($rc_body) . "\r\nConnection: close\r\nUser-Agent: WPCRetryCompress/1.0\r\n\r\n" . $rc_body;
            $rc_fp = wps_ic_ajax::wpc_loopback_open_socket($rc_host, $rc_port, $rc_https, 0.2);
            if ($rc_fp) { @stream_set_timeout($rc_fp, 0, 100000); @fwrite($rc_fp, $rc_req); @fclose($rc_fp); }
        }
    }
}

// AJAX endpoint that the loopback POST hits. Just delegates to the existing hook.
if (!function_exists('wpc_retry_compress_loopback_ajax')) {
    function wpc_retry_compress_loopback_ajax()
    {
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

/**
 * Phase 1 instrumentation — rich log entry per backfill event.
 * Ring buffer, 500 entries max. Consumed by Debug Tool panel + Phase 2.5 decisions.
 *
 * Callers can pass a single width/format or arrays; extras carries richer fields.
 */
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

        // Merge any additional telemetry fields (duration_ms, trigger_source, etc.)
        if (is_array($extras)) {
            foreach ($extras as $k => $v) {
                if (!isset($entry[$k])) $entry[$k] = $v;
            }
        } elseif (is_string($extras)) {
            // Legacy callers that passed context string as 4th arg
            $entry['ctx'] = $extras;
        }

        $log[] = $entry;
        $log = array_slice($log, -500); // ring buffer
        update_option('wpc_variant_gen_log', $log, false);
    }
}

/**
 * Phase 1 instrumentation — cumulative aggregate stats for ops dashboards.
 * Tracks totals, per-trigger breakdown, rolling avg duration, and a simple p95 approximation.
 *
 * Stored as single JSON option. Reads are per-render cached; writes happen only on backfill events.
 */
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

/**
 * Compress telemetry — cumulative stats for end-to-end singleCompressV4 operations.
 * Source attribution: upload / single / bulk / retry / unknown.
 */
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
            if (time() - $start_time > 90) break; // hard global timeout
            if ($failed_pages >= 3) break; // if 3+ pages fail to fetch, stop (site misconfigured)

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
                    $aid = (int) attachment_url_to_postid($src);
                }
                if ($aid <= 0 || isset($seen_attachments[$aid])) continue;
                $seen_attachments[$aid] = true;

                $meta = wp_get_attachment_metadata($aid);
                if (empty($meta) || empty($meta['file'])) continue;
                if ((int) ($meta['width'] ?? 0) < 400) continue; // skip tiny source

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
        $this->logFile = fopen($this->logFilePath, 'a');

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

        /**
         * If backup directories don't exist, create them
         */
        if (!file_exists(self::$backup_directory)) {
            $made_dir = mkdir(self::$backup_directory, 0755);
            if (!$made_dir) {
                update_option('wpc_errors', ['unable-to-create-backup-dir' => self::$backup_directory]);
            } else {
                delete_option('wpc_errors');
            }
        }


        add_action('delete_attachment', [$this, 'on_delete']);

        // Optimization-mode gate. Any mode other than 'legacy' (Manual,
        // Lazy Full, Smart Lazy, CDN Lazy) means the customer opted out of
        // upload-time encoding — skip the on-upload filter. wpc_auto_encoding_disabled
        // returns true for all non-legacy modes; legacy keeps current behavior.
        //
        // On-upload is now driven SOLELY by the Optimization Strategy
        // dropdown: mode === 'legacy' ("On Upload") enables it. The separate
        // "Auto-Optimize on Upload" toggle was removed from the UI (it was a
        // redundant second control), so the dropdown is the single source of truth.
        // wpc_get_optimization_mode() defaults to 'legacy' when unset, so existing
        // installs that never picked a mode keep auto-compressing exactly as before.
        // (The legacy `on-upload` option is still emitted by the template, mirrored
        // to the mode, for any legacy consumer — but it no longer gates the hook.)
        //   - restoreImage URL param blocks mid-restore self-uploads.
        //   - wpc_auto_encoding_disabled() covers any non-legacy / kill-switch case.
        $on_upload_mode = function_exists('wpc_get_optimization_mode')
            ? wpc_get_optimization_mode()
            : 'legacy';
        $on_upload_enabled = ($on_upload_mode === 'legacy')
            && empty($_GET['restoreImage'])
            && !(function_exists('wpc_auto_encoding_disabled') && wpc_auto_encoding_disabled());

        if ($on_upload_enabled) {
            /*
             * This works but uploads a full sized image to storage for every size variation
             */

            add_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX, 2);
            // TODO: Causing problems with showing 0% saved, while actually compressed
        }

        // Re-optimize on WP image edit. The media editor (Crop/Rotate/Scale)
        // writes -e{timestamp} files via wp_save_image() + wp_update_attachment_metadata,
        // NOT wp_generate_attachment_metadata — so the on_upload filter above never fires
        // for an edit and the edited image keeps stale/no optimized variants. This filter
        // catches the editor save and re-runs the v2 optimize for the edited attachment.
        // The callback self-gates (editor-context + auto-encoding-on + not restore), so it
        // is safe to register unconditionally regardless of $on_upload_enabled / mode.
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

        // Setup paraams for POST to API
        self::$apiParams = [];
        self::$apiParams['apikey'] = self::$options['api_key'];
        self::$apiParams['quality'] = self::$settings['optimization'];
        self::$apiParams['retina'] = 'false';
        self::$apiParams['webp'] = 'false';
        self::$apiParams['width'] = 'false';
        self::$apiParams['url'] = '';
    }

    /**
     * Build optimization params for /optimize and /bulk-start service calls.
     * Reads all Local Image Optimization settings and resolves Quality Override vs CDN level.
     */
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

        // Quality Override: None (0) = use CDN Optimization Level, otherwise use local override
        $local_quality = $settings['local_qualityLevel'] ?? '0';
        $cdn_quality_map = ['1' => 'lossless', '2' => 'intelligent', '3' => 'ultra'];
        $cdn_level = $cdn_quality_map[$settings['qualityLevel'] ?? '2'] ?? 'intelligent';
        $resolved_level = ($local_quality === '0' || empty($local_quality))
            ? $cdn_level
            : ($cdn_quality_map[$local_quality] ?? $cdn_level);

        // Hosting detection — shared if low memory or short execution time
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
            'pluginVersion' => defined('WPS_IC_VERSION') ? WPS_IC_VERSION : (defined('WPS_IC_LOCAL_V') ? WPS_IC_LOCAL_V : '7.01.0'), // L10 — v1.18 auto-captures
        ];

        if ($imageID) {
            $params['imageID'] = $imageID;
        }

        return $params;
    }

    /**
     * Build crops JSON from registered WordPress image sizes.
     * Sent to service so it can generate all thumbnails server-side.
     *
     * Also emits an 'original' crop entry pinned to the user's
     * `maxWidth` setting (default 2560). Pre-fix, the service had no
     * instruction for the Original sizeLabel and produced its variants at
     * native source dims (e.g. 2831×3840 for an unscaled-2560-cap upload).
     * That wasted ~30-40% encode CPU and disk on a variant nobody serves —
     * customers with `big_image_size_threshold` set never expose the unscaled
     * URL to visitors. With this entry, the service crops the Original to
     * maxWidth before encoding, so JPEG/WebP/AVIF Original variants come
     * back at the same dims as Scaled (1887×2560 for a 2831×3840 source +
     * 2560 cap). Modal still tracks them under the 'original' sizeLabel.
     */
    public static function buildCropsJson() {
        $crops = [];

        // Pin Original to maxWidth so service honors the user's setting.
        // Box-fit semantics (width = height = $max_width, crop = false): service
        // scales the source to fit within a $max_width × $max_width box, preserving
        // aspect ratio. Matches WP's `big_image_size_threshold` which caps the
        // LONGEST dimension. For a 2831×3840 portrait + maxWidth=2560 → 1887×2560
        // (matches Scaled). For a 4000×3000 landscape → 2560×1920.
        // Width=N, height=0 was wrong for portraits (gave 2560×3472, taller than
        // original because width-only cap left height untouched).
        $settings  = get_option(WPS_IC_SETTINGS);
        $max_width = (int) ($settings['maxWidth'] ?? $settings['local_maxWidth'] ?? 2560);
        if ($max_width > 0) {
            $crops['original'] = [
                'width'  => $max_width,
                'height' => $max_width,  // box-fit: caps LONGEST dim, not just width
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

    /**
     * Build filenames JSON mapping size labels → local WordPress filenames.
     * Sent to service so optimized files use the correct WP filenames (not hashes).
     */
    public static function buildFilenamesJson($imageID) {
        $filenames = [];

        // Unscaled original
        $unscaledPath = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : null;
        if ($unscaledPath) {
            $filenames['original'] = basename($unscaledPath);
        }

        // Scaled version (if exists)
        $meta = wp_get_attachment_metadata($imageID);
        if (!empty($meta['file']) && strpos($meta['file'], '-scaled') !== false) {
            $filenames['scaled'] = basename($meta['file']);
        }

        // All registered thumbnail sizes
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sizeName => $info) {
                if (!empty($info['file'])) {
                    $filenames[$sizeName] = $info['file'];
                }
            }
        }

        return json_encode($filenames);
    }

    /**
     * Build sizes JSON mapping size labels → file size in bytes on disk.
     * Sent to service v1.17.3+ so it uses WP's actual on-disk bytes as the regression baseline
     * (instead of comparing against its own q95 intermediate). Required for accurate savingsPercent
     * and to prevent variants being silently skipped when output > intermediate but < WP file.
     * Backward compatible: pre-v1.17.3 services ignore this field.
     */
    public static function buildSizesJson($imageID) {
        $sizes = [];

        // Unscaled original
        $unscaled = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : null;
        if ($unscaled && file_exists($unscaled)) {
            $sizes['original'] = filesize($unscaled);
        }

        $main = get_attached_file($imageID);
        if ($main && file_exists($main)) {
            $meta = wp_get_attachment_metadata($imageID);

            // -scaled.jpg: WP 5.3+ big-image-auto-scale
            if (!empty($meta['file']) && strpos($meta['file'], '-scaled') !== false) {
                $sizes['scaled'] = filesize($main);
            }

            // Sized crops (thumbnail, medium, medium_large, large, etc.)
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

    /**
     * Send image to service via POST (file upload) with GET fallback.
     * POST is 3-6x faster than GET because the service doesn't need to download from the site.
     *
     * @param int   $imageID    WordPress attachment ID
     * @param array $params     Params from buildOptimizeParams()
     * @param bool  $blocking   true = wait for response, false = fire and forget
     * @param int   $timeout    Timeout in seconds (only for blocking)
     * @return array|WP_Error|true
     */
    public static function postOptimize($imageID, $params, $blocking = true, $timeout = 120) {
        $file_path = get_attached_file($imageID);

        // GET fallback removed. The GET path causes local-mc to regenerate
        // thumbnails server-side using parent dimensions for regression comparison,
        // which incorrectly skips AVIF/WebP on most sizes (service bug: compares
        // full-size output against tiny WP thumbnail file). POST sends the actual
        // file with correct dimensions — always use POST. If cURL/CURLFile is
        // unavailable or file unreadable, fail gracefully (image stays on legacy
        // CDN path, still works, just not optimized via modern delivery).
        if (!function_exists('curl_init') || !class_exists('CURLFile') || !$file_path || !is_readable($file_path)) {
            if (function_exists('wpc_log_trigger')) {
                wpc_log_trigger('post_optimize_skip', $imageID, [
                    'reason' => !function_exists('curl_init') ? 'no_curl' : (!$file_path ? 'no_file_path' : 'not_readable'),
                    'file_path' => $file_path ?: '(empty)',
                ]);
            }
            return new WP_Error('wpc_no_post', 'POST optimization unavailable: cURL/CURLFile missing or file not readable. GET fallback deprecated in 7.01.0.');
        }

        // Prefer the unscaled original when valid — it has more pixel data so downstream
        // resize/encode produces smaller bytes at same visual quality (measured 2026-04-17:
        // ~32% byte reduction at 1920w from unscaled source vs scaled-2560 source).
        // Fall back to scaled only if unscaled is missing, unreadable, or suspiciously small
        // (<10 KB = likely corrupted from a failed compress→restore cycle).
        $unscaled = wp_get_original_image_path($imageID);
        $upload_path = $file_path; // default = scaled

        if ($unscaled && $unscaled !== $file_path && file_exists($unscaled) && is_readable($unscaled)) {
            $unscaled_size = filesize($unscaled);
            if ($unscaled_size >= 10240) { // 10 KB minimum — above the corruption threshold
                $upload_path = $unscaled;
            }
        }

        // Honor caller-provided file_path_override (lazy-variant per-thumb POSTs send
        // pre-resized variant bytes, not the parent file). When set, override the upload_path
        // selection above + skip the unscaled-source preference (variant has no unscaled).
        if (!empty($params['file_path_override']) && is_readable($params['file_path_override'])) {
            $upload_path = $params['file_path_override'];
        }

        // Build POST body with file
        $body = [
            'apikey'        => $params['apikey'] ?? '',
            'imageSite'     => $params['imageSite'] ?? '',
            'imageID'       => $imageID,
            'level'         => $params['level'] ?? 'intelligent',
            'webp'          => $params['webp'] ?? '1',
            'avif'          => $params['avif'] ?? '1',
            'maxWidth'      => $params['maxWidth'] ?? '2560',
            'hosting'       => $params['hosting'] ?? 'shared',
            'pluginVersion' => $params['pluginVersion'] ?? (defined('WPS_IC_VERSION') ? WPS_IC_VERSION : '7.01.0'), // L10
            // Honor caller-provided crops/filenames (ladder backfill sends custom widths like 480, 1280).
            // Fall back to WP-registered sizes when caller doesn't override.
            'crops'         => isset($params['crops']) ? $params['crops'] : self::buildCropsJson(),
            'filenames'     => isset($params['filenames']) ? $params['filenames'] : self::buildFilenamesJson($imageID),
            'sizes'         => self::buildSizesJson($imageID), // v1.17.3+: WP-real baseline for accurate savings
            'image'         => new CURLFile($upload_path, (function_exists('mime_content_type') ? mime_content_type($upload_path) : false) ?: 'image/jpeg', basename($upload_path)),
        ];

        // v1.22.8 lazy-variant body params. Optional fields; service ignores when absent.
        // parentImageID: identifies the parent attachment (same as imageID for parent POST or
        //   batch ladder; required when sending per-variant POST under a different unique ID).
        // skipBackup='1': tells service this isn't the parent file — don't back it up to Bunny.
        //   Required on every per-variant POST. Set to '1' on batch when parent already has
        //   backup (Bunny or local) so service skips the redundant re-upload.
        // sizeLabel: identifies which variant slot this POST is for (lazy fill only).
        // triggerContext: analytics attribution ('lazy_fill_parent' / 'lazy_fill_variant' / etc.).
        if (isset($params['parentImageID']))   $body['parentImageID']   = (string) $params['parentImageID'];
        if (isset($params['skipBackup']))      $body['skipBackup']      = (string) $params['skipBackup'];
        if (isset($params['sizeLabel']))       $body['sizeLabel']       = (string) $params['sizeLabel'];
        if (isset($params['triggerContext']))  $body['triggerContext']  = (string) $params['triggerContext'];
        // Race-guard timestamp forwarded into the request body. Service team
        // requires this on every /optimize POST for v1.22.11.10+ contract. ms-since-epoch.
        // Falls back to _wpc_compress_started_at post_meta if caller didn't supply, then
        // to current time as a last resort (better to send something than nothing — older
        // service versions ignore the field harmlessly, newer ones use it for race-guard).
        $cs_ms = 0;
        if (isset($params['compressStartedAt']) && (int) $params['compressStartedAt'] > 0) {
            $cs_ms = (int) $params['compressStartedAt'];
        } else {
            $cs_post_meta = (int) get_post_meta($imageID, '_wpc_compress_started_at', true);
            if ($cs_post_meta > 0) {
                // post_meta stores seconds — convert to ms
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
        // Staging-only: skip cert hostname check when WPC_IC_LOCAL_OPTIMIZE
        // points at an IP (dev pod, e.g. 109.224.230.16). Cert SAN is *.zapwp.net,
        // which doesn't match the IP. Production sites use the DNS hostname so cert
        // verification works normally — `defined('WPC_STAGING')` is intentionally
        // never set in prod wp-config.php.
        if (defined('WPC_STAGING') && WPC_STAGING) {
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);

        // Opt-in to inline-bytes response from rc10.3h+ service (saves 4-5s per compress by
        // skipping the Bunny CDN download round-trip). Service falls back to URL-only response on
        // pre-rc10.3h pods OR when this header is absent — fully backward compatible either way.
        // Memory gate: only opt in when host has ≥128M memory_limit (base64 response can inflate to
        // 2-6MB with peak json_decode overhead ~3× string size). Hosts under 128M stay on URL path.
        //
        // Opt-in to bg-swap callback from rc10.5+ service. After fast-path returns, bg
        // worker refines bytes asynchronously and POSTs them to our ?wpc_bg_swap endpoint when a
        // quality/size upgrade is worth shipping. Customer sees fast-path output immediately; disk
        // bytes quietly upgrade within 30-120s. Callback endpoint handler: wpc_handle_bg_swap_callback().
        $mem_bytes = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes(ini_get('memory_limit')) : 0;
        $headers = [];
        // `memory_limit=-1` means unlimited; wp_convert_hr_to_bytes returns -1 for that.
        // Original gate ($mem_bytes >= 134217728) excluded -1, silently degrading unlimited-memory
        // hosts to URL-only path. Accept -1 explicitly.
        if ($mem_bytes === 0 || $mem_bytes === -1 || $mem_bytes >= 134217728) { // 128 MiB or unlimited
            $headers[] = 'X-Plugin-Accepts-Bytes-Inline: 1';
            $headers[] = 'X-Plugin-Accepts-Bg-Swap: 1';
            // Opt-in to noImprovement callback shape from v1.22.11.10+ service. Lets
            // service tell us definitively "all widen alts lost; stop expecting this variant"
            // via {noImprovement: true, reason, baselineKb, widenAltKbs} POSTed to /?wpc_bg_swap
            // instead of a silent timeout. Gated together with bg-swap (no callbacks fire without
            // it). Backward compatible — older pods omit the callback entirely.
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
        // Capture service TTFB (time-to-first-byte) separately from total round-trip
        // so we can distinguish service-encoding time from response-body-download time.
        $ttfb_s      = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
        $total_s     = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        $size_bytes  = (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
        // Capture service body-side timing for service-team correlation.
        // v1.22.5+ pods include {timing: {multerMs, phase1Ms, dbWritesMs}} in the
        // response body. Older pods omit it; absent fields stay 0 (graceful fallback).
        $svc_multer_ms = 0; $svc_phase1_ms = 0; $svc_db_writes_ms = 0;
        if ($http_code >= 200 && $http_code < 300 && is_string($response)) {
            $decoded = json_decode($response);
            if (is_object($decoded) && isset($decoded->timing) && is_object($decoded->timing)) {
                $svc_multer_ms    = (int) round((float) ($decoded->timing->multerMs    ?? 0));
                $svc_phase1_ms    = (int) round((float) ($decoded->timing->phase1Ms    ?? 0));
                $svc_db_writes_ms = (int) round((float) ($decoded->timing->dbWritesMs  ?? 0));
            }
        }
        // Store for singleCompressV4 to consume in the DETAILED_TIMING log
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

        // 403 = auth failure — don't retry, key is invalid
        if ($http_code === 403) {
            return new WP_Error('wpc_not_authorized', 'Local optimization not available on your plan');
        }

        // 404/410 = endpoint deprecated or gone — don't retry, don't queue. Service's GET /optimize
        // returned 410 pre-rc10.3d, returns 404 after. Either way: plugin/service version mismatch.
        if ($http_code === 404 || $http_code === 410) {
            if (function_exists('wpc_log_trigger')) {
                wpc_log_trigger('endpoint_gone', $imageID, ['http_code' => $http_code]);
            }
            return new WP_Error('wpc_endpoint_gone', 'Service /optimize returned HTTP ' . $http_code . ' — plugin/service version mismatch', ['http_code' => $http_code]);
        }

        // Any other non-2xx (or curl error) → typed WP_Error. GET fallback removed: the service's
        // GET /optimize is deprecated and returns HTTP 410 on purpose, so falling back would mask
        // transient POST failures with a permanent-looking 410. Preserve http_code + body excerpt
        // so callers can decide retry policy (transient vs permanent).
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

        // Clear any stale failure marker on success
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

        // Per-image worker — bypasses global queue/lock, runs one image in parallel with others
        register_rest_route('wpc/v1', '/compress-single', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'wpc_handle_single_compress'],
            'permission_callback' => [$this, 'wpc_permission_api_key'],
        ]);
    }

    /**
     * Per-image worker for manual single compress clicks.
     * - Concurrency cap (default 2): protects PHP worker pool + service from overload
     * - Fallback to sequential queue: if cap exceeded OR compression fails, image routes to queue for retry
     * - Heartbeat refresh on failure: UI exits spinner state if queue retry also fails
     *
     * Override cap with: define('WPC_MAX_CONCURRENT_COMPRESS', 5) in wp-config.php
     */
    public function wpc_handle_single_compress(\WP_REST_Request $request) {
        // Instrumentation: end-to-end wall profiling. Captures bracket times at
        // REST handler entry, pre-compress, post-compress, and shutdown so we
        // can locate the 13s gap between phase_a_total (5.7s observed) and
        // customer-side wall (~19s observed). Shutdown fn fires after the HTTP
        // response is sent (per PHP-FPM semantics), so post_single_ms captures
        // both response-build time AND any post-handler hook firing.
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

        // Suppress auto-compress hook to prevent recursion
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);

        $imageID = intval($request->get_param('imageID'));
        if (!$imageID) {
            return rest_ensure_response(['success' => false, 'reason' => 'no-image-id']);
        }

        // Per-image lock — prevents double-compressing same image
        $perImageLock = 'wpc_compress_lock_' . $imageID;
        if (get_transient($perImageLock)) {
            return rest_ensure_response(['success' => false, 'reason' => 'already-processing']);
        }

        // Concurrency cap — prevents PHP worker pool exhaustion + service overload
        // Default 2 (safe across all hosting). Override via WPC_MAX_CONCURRENT_COMPRESS constant.
        $maxConcurrent = defined('WPC_MAX_CONCURRENT_COMPRESS') ? max(1, (int) WPC_MAX_CONCURRENT_COMPRESS) : 2;
        $currentCount = (int) get_transient('wpc_single_concurrent');

        if ($currentCount >= $maxConcurrent) {
            // At cap — route to sequential queue instead of running another parallel worker
            $this->routeToQueue($imageID);
            error_log('[WPC Single] image=' . $imageID . ' routed-to-queue (concurrent=' . $currentCount . '/' . $maxConcurrent . ')');
            return rest_ensure_response(['success' => true, 'fallback' => 'queued-cap-reached']);
        }

        // Acquire slot — increment counter (transient TTL is safety valve if worker dies)
        set_transient('wpc_single_concurrent', $currentCount + 1, 300);
        set_transient($perImageLock, time(), 300);

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '2048M');
            @set_time_limit(180);
        }

        $start = microtime(true);
        // try/finally guarantees the concurrency counter + per-image lock are always
        // released, even if backup_all_sizes or singleCompressV4 throws. Without this, a single
        // exception leaks the slot permanently, eventually pinning $currentCount at the cap and
        // routing all subsequent single-clicks straight to the sequential queue.
        try {
            $backupOk = $this->backup_all_sizes($imageID);
            if ($backupOk) {
                $instr_t_pre_single = microtime(true);  // instrumentation
                $this->singleCompressV4($imageID, 'silent', true, 'single');
                $instr_t_post_single = microtime(true); // instrumentation
            }
        } finally {
            // Decrement concurrency counter (with safety floor at 0)
            $now = (int) get_transient('wpc_single_concurrent');
            set_transient('wpc_single_concurrent', max(0, $now - 1), 300);
            // Release per-image lock
            delete_transient($perImageLock);
        }
        $elapsed = round(microtime(true) - $start, 2);

        // Check if compression actually succeeded
        $newStatus = get_post_meta($imageID, 'ic_status', true);

        if ($newStatus === 'compressed') {
            error_log('[WPC Single] image=' . $imageID . ' time=' . $elapsed . 's status=success');
            delete_transient('wps_ic_compress_' . $imageID);
            return rest_ensure_response(['success' => true, 'time' => $elapsed]);
        }

        // Compression failed. If singleCompressV4 already scheduled a transient-retry cron event,
        // return retry-scheduled (no queue). Otherwise → 404/410 endpoint-gone or exhausted retries
        // or permanent 4xx → route to sequential queue as terminal fallback.
        if (wp_next_scheduled('wpc_retry_compress', [$imageID])) {
            error_log('[WPC Single] image=' . $imageID . ' time=' . $elapsed . 's → retry scheduled by singleCompressV4');
            return rest_ensure_response(['success' => true, 'fallback' => 'retry-scheduled']);
        }

        // 404/410 endpoint-gone is terminal — don't queue-retry (same handler, same failure)
        $last_err  = get_post_meta($imageID, '_wpc_last_post_fail', true);
        $http_code = is_array($last_err) ? (int) ($last_err['http_code'] ?? 0) : 0;
        if ($http_code === 404 || $http_code === 410) {
            error_log('[WPC Single] image=' . $imageID . ' endpoint-gone HTTP' . $http_code . ' — no retry, no queue');
            return rest_ensure_response(['success' => false, 'reason' => 'endpoint-gone', 'http_code' => $http_code]);
        }

        error_log('[WPC Single] image=' . $imageID . ' time=' . $elapsed . 's status=failed -> routing to queue');
        $this->routeToQueue($imageID);

        // Keep wps_ic_compress_ transient so UI stays on "queued" state during queue retry.
        // If queue worker also fails, ITS heartbeat fix sets status=restored to clear UI.

        return rest_ensure_response(['success' => true, 'fallback' => 'queued-after-failure', 'time' => $elapsed]);
    }

    /**
     * Add an image to the sequential queue and fire the queue worker.
     * Used by both the cap-exceeded path and the failure-retry path.
     */
    private function routeToQueue($imageID) {
        $queue = get_option('wpc_compress_queue', []);
        if (!in_array($imageID, $queue)) {
            $queue[] = $imageID;
            update_option('wpc_compress_queue', $queue, false);
        }
        $this->fireQueueWorker();
    }

    /**
     * Sequential queue worker — processes one image at a time, then chains to the next.
     * Only one worker runs at a time (enforced by wpc_compress_lock transient).
     */
    public function wpc_handle_async_compress(\WP_REST_Request $request) {
        // Suppress auto-compress hook to prevent recursion
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);

        // Acquire lock (5 min TTL — failsafe if worker crashes)
        if (get_transient('wpc_compress_lock')) {
            error_log('[WPC Queue] Worker blocked — lock already held');
            return rest_ensure_response(['success' => false, 'reason' => 'worker-already-running']);
        }
        set_transient('wpc_compress_lock', time(), 300);

        $workerStart = microtime(true);
        $processed = 0;
        error_log('[WPC Queue] Worker started. Queue: ' . json_encode(get_option('wpc_compress_queue', [])));

        // try/finally guarantees lock release even on uncaught exceptions outside the
        // per-image try/catch block (e.g., DB errors during update_option, fatal errors in get_post_meta).
        // Without this, a crash mid-loop would leave wpc_compress_lock held for its full 5min TTL,
        // blocking the next worker indefinitely.
        try {
            // Process queue sequentially until empty
            while (true) {
                wp_cache_delete('wpc_compress_queue', 'options');
                $queue = get_option('wpc_compress_queue', []);
                if (empty($queue)) break;

                // Take next image from front of queue
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

                // Refresh lock TTL for each image (worker is alive)
                set_transient('wpc_compress_lock', time(), 300);

                $imgStart = microtime(true);
                try {
                    $backupOk = $this->backup_all_sizes($imageID);
                    if (!$backupOk) {
                        error_log('[WPC Queue] SKIPPED image=' . $imageID . ' — backup failed, will not compress');
                    } else {
                        // Queue worker handles upload-originated images + single-click concurrency-cap overflow.
                        // 'upload' is the most common source; rare cap-overflow gets the same attribution (minor).
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

                // Always clean up this image's transients
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);

                // If compression failed, set heartbeat so UI refreshes to uncompressed state
                // (successful compression already sets this inside singleCompressV4)
                if ($status !== 'compressed') {
                    set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 300);
                }

                $processed++;
            }
        } finally {
            // Release lock — always, even if loop body threw past the inner catch blocks
            delete_transient('wpc_compress_lock');
        }

        $totalElapsed = round(microtime(true) - $workerStart, 2);
        error_log('[WPC Queue] Worker done. Processed=' . $processed . ' total_time=' . $totalElapsed . 's');

        return rest_ensure_response(['success' => true, 'processed' => $processed]);
    }

    /**
     * Fire the queue worker via non-blocking loopback (if not already running).
     */
    public function fireQueueWorker() {
        // Don't fire if worker is already running
        if (get_transient('wpc_compress_lock')) return;

        $loopback_status = get_option('wpc_loopback_status', '');
        if ($loopback_status === 'fail') return;

        $api_key = $this->getApiKey();
        // Loopback goes to the local vhost rather than wp_remote_post(blocking=false) to rest_url()'s
        // PUBLIC host: on a datacenter-IP site that host is the CDN/WAF edge, so the queue-worker self-POST
        // never landed on local PHP-FPM — the exact fake-success this fixes; the in-file comment at ~702 notes
        // ladder-gen "uses the same pattern as fireQueueWorker()". Connect 127.0.0.1→localhost→public host
        // with Host:/SNI = public host; honors a non-standard admin/REST port + the ?rest_route= form on
        // non-pretty-permalink sites. Prefer the proven shared helper, else inline (free insurance). The
        // x-api-key header + empty body are UNCHANGED; wpc_loopback_status='fail' skip + the queue option stay backstops.
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

    // ─── Backup image files to /wpc-backups/ before compression ────────
    /**
     * Backup image files before compression. Respects the 'backup' setting:
     *   'full'      — all files (unscaled + scaled + thumbnails) — safest, instant restore
     *   'originals' — only unscaled original — smaller footprint, restore regenerates thumbnails
     *   'cloud'     — skip local backup — restore downloads from service
     *   'off'       — no backup, compression is permanent
     *
     * Returns true if backup succeeded (or was skipped by setting), false if backup failed.
     * Compression MUST NOT proceed if this returns false (except for 'off' mode).
     */
    /**
     * Synchronously wait for an in-flight thumbnail regen to complete (or for
     * its post_meta marker to age out as stale) before proceeding with an action that
     * depends on all sub-sizes existing on disk (most notably `wps_ic_compress_live`,
     * which builds the `filenames` JSON sent to the service from current disk state).
     *
     * Replaces the earlier visual "Restoring..." gate in compress_details() — that gate
     * locked the UI but caused stuck cards when the heartbeat-recovery missed its window.
     * Action-side wait is the cleaner contract: visual state is always honest; clicks are
     * blocked just long enough for the underlying state to be safe.
     *
     * Returns true if either:
     *   (a) `_wpc_pending_thumb_regen` cleared on its own within the budget (regen done)
     *   (b) the marker is older than its scheduled_at + 60s (stale; abandon and proceed)
     * Returns false ONLY if the marker is still set AND fresh after the budget elapsed
     * (caller should still proceed, but log it; rare edge case).
     *
     * @param int   $imageID
     * @param int   $max_wait_sec  Upper bound on busy-wait. Default 15s.
     * @return bool true if regen done OR stale; false if hit budget with fresh marker.
     */
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

            // Stale-marker bypass: if the regen was scheduled long ago and hasn't cleared,
            // the worker likely never fired (cron disabled, host quirk, etc.). Don't block
            // forever — proceed with whatever sub-sizes exist on disk.
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

    public function backup_all_sizes($imageID) {
        $backupMode = self::$settings['backup'] ?? 'full';

        // 'off' = no backup, compression is permanent — proceed without backup
        if ($backupMode === 'off') {
            error_log('[WPC Backup] image=' . $imageID . ' mode=off — skipped');
            return true;
        }

        // 'cloud' = skip local backup — rely on service cloud backup only
        if ($backupMode === 'cloud') {
            update_post_meta($imageID, 'wpc_backup_mode', 'cloud');
            error_log('[WPC Backup] image=' . $imageID . ' mode=cloud — local skipped');
            return true;
        }

        // 'originals' or 'full' or 'local' or 'local-cloud' (legacy values) = local backup
        $backupBase = WP_CONTENT_DIR . '/wpc-backups/';
        $uploadDir = wp_upload_dir()['basedir'];
        $filesCopied = 0;
        $mainBackedUp = false;
        $backupFull = ($backupMode === 'full' || $backupMode === 'local-cloud');

        // Verify backup directory is writable
        $testDir = $backupBase . 'test_' . $imageID;
        if (!wp_mkdir_p($testDir)) {
            error_log('[WPC Backup] FAILED — backup directory not writable: ' . $backupBase);
            return false;
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
                    return false;
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
                        return false;
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
        return $mainBackedUp;
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

        // if API Key is Valid Setup the PHP Limits
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

        // Final payload in the exact shape requested
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
     * Function to verify if API Key is set and valid
     * @return void
     */
    public function checkAPIKey()
    {
        $options = get_option(WPS_IC_OPTIONS);
        $apikey = sanitize_text_field($_GET['apikey']) ?? '';
        $expected_token = !empty($options['api_key']) ? $options['api_key'] : '';

        // Fallback: if object cache returned empty, read directly from database
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

        // if API Key is Valid Setup the PHP Limits
        $this->raiseLimits();
        return $expected_token;
    }


    /**
     * Raise PHP / Server Limits
     * @return void
     */
    public function raiseLimits() {
        wp_raise_memory_limit('image');
        ini_set('memory_limit', '1024M');
    }


    public function restoreImage()
    {
        if (isset($_GET['restoreImage'])) {

            // Check if API Key is valid
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

            // Skip excluded images — still advance bulk counter
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

            // Use same restore logic as single image (restoreV4 approach)
            $restored = false;
            $scaledPath = get_attached_file($imageID);
            $unscaledPath = $scaledPath ? str_replace('-scaled.', '.', $scaledPath) : '';

            // Priority 1: Local _bkp backup
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

            // Priority 2: Download from service (prefer unscaled, fallback original)
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

            // Always clean metadata (even if download failed — prevents stuck state)
            if ($restored) {
                // Clean leftover .webp and .avif files
                $attachedFile = get_attached_file($imageID);
                if ($attachedFile) {
                    $dir = dirname($attachedFile);
                    $baseName = pathinfo(wp_get_original_image_path($imageID) ?: $attachedFile, PATHINFO_FILENAME);
                    foreach (glob($dir . '/' . $baseName . '*.webp') as $webp) { @unlink($webp); }
                    foreach (glob($dir . '/' . $baseName . '*.avif') as $avif) { @unlink($avif); }
                }
            }

            // Mark image as parsed for heartbeat to pick up
            $parsedImages[$imageID] = ['status' => $restored ? 'restored' : 'failed'];

            // Clean all optimization metadata
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
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); } // async CDN purge (see v2-customer-purge.php)

            set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

            // Invalidate local cache for this image.
            if (function_exists('wpc_invalidate_local_cache')) { wpc_invalidate_local_cache(); }
            // CDN purge removed from this restore path too (see restoreV4 /
            // cleanRestoreMeta). The ~1,500-request inline fan-out cost ~50s; the `?v=`
            // lazy cache-buster makes it redundant. Re-add async/wildcard only if needed.

            $bulkStatus = get_option('wps_ic_BulkStatus');

            if (empty($bulkStatus['restoredImageCount'])) {
                $bulkStatus['restoredImageCount'] = 0;
            }

            $bulkStatus['restoredImageCount'] += 1;
            update_option('wps_ic_BulkStatus', $bulkStatus);

            update_option('wps_ic_parsed_images', $parsedImages);

            wp_send_json_success();
        }
    }


    /**
     * Get a List of All Images to Compress
     * @return void
     */
    public function fetchImages()
    {
        if (isset($_GET['fetchImageByID'])) {

            // Check if API Key is valid
            $this->checkAPIKey();

            $image_id = absint($_GET['fetchImageByID']);
            if (!$image_id) {
                wp_send_json_error('Invalid image ID', 400);
            }

            if (!get_post($image_id) || get_post_type($image_id) !== 'attachment') {
                wp_send_json_error('Invalid image ID', 400);
            }

            // Save OLD post meta for restore usage
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
                    $sizes[$size] = $image_data[0]; // include full size too
                }
            }

            // Add real original (unscaled) image if available
            $meta = wp_get_attachment_metadata($image_id);
            $upload_dir = wp_upload_dir();

            if (!empty($meta['file'])) {
                $original_path = path_join($upload_dir['basedir'], $meta['file']);
                $original_url = trailingslashit($upload_dir['baseurl']) . $meta['file'];

                // Add real original (unscaled) image if available
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


    /**
     * Download Compressed Image from API
     * @return void
     */
    public function downloadImages()
    {
        if (isset($_GET['downloadImage'])) {

            // Check if API Key is valid
            $expected_token = $this->checkAPIKey();

            require_once ABSPATH . 'wp-admin/includes/image.php';

            $apiStatus = sanitize_text_field($_GET['status']);
            $isBulk = sanitize_text_field($_GET['bulk']) ?? false;
            $imageID = absint($_GET['downloadImage']);
            if (!$imageID) {
                wp_send_json_error('Invalid image ID', 400);
            }

            // Skip excluded images — but still advance bulk counter so progress completes
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

            // Get original image URL to extract filename
            $original_url = wp_get_attachment_url($imageID);

            if (empty($original_url) || is_wp_error($original_url)) {
                wp_send_json_error('Invalid image ID', 400);
            }

            $basename = basename($original_url);

            // Skip the image, some error on API Side Occured
            if (!empty($apiStatus) && $apiStatus == 'skip') {
                // Stats
                $stats = [];
                $stats['original']['original']['size'] = 0;
                $stats['original']['compressed']['size'] = 0;
                $stats['original']['compressed']['thumbs'] = 0;

                // Parsed Images Array
                $parsedImages = get_option('wps_ic_parsed_images');

                if (!$parsedImages) {
                    $parsedImages = [];
                    $parsedImages['total']['original'] = 0;
                    $parsedImages['total']['compressed'] = 0;
                }

                // Flag for Bulk Memory
                if ($isBulk) {
                    $thumbCount = $this->getAllThumbSizes();
                    $bulkStatus = get_option('wps_ic_BulkStatus');

                    $parsedImages['total']['original'] += $stats['original']['original']['size'];
                    $parsedImages['total']['compressed'] += $stats['original']['compressed']['size'];

                    $parsedImages[$imageID]['total']['original'] = $parsedImages['total']['original'];
                    $parsedImages[$imageID]['total']['compressed'] = $parsedImages['total']['compressed'];

                    // Write down last compressed before-after
                    update_option('wps_ic_parsed_images', $parsedImages);

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

                    // Write counter for bulk UI
                    $counter = [];
                    $counter['images'] = $bulkStatus['compressedImageCount'];
                    $counter['imagesAndThumbs'] = $bulkStatus['compressedThumbs'];
                    update_option('wps_ic_bulk_counter', $counter);
                }

                // Compressing status
                delete_transient('wps_ic_compress_' . $imageID);
                delete_transient('wps_ic_queue_' . $imageID);

                $imageStats = get_post_meta($imageID, 'ic_stats', true);
                $compressing = get_post_meta($imageID, 'ic_compressing', true);

                // if Image is skipped, on restore do nothing just delete meta
                update_post_meta($imageID, 'ic_skipped', 'true');

                update_post_meta($imageID, 'wpc_images_compressed', 'true');
                update_post_meta($imageID, 'ic_status', 'compressed');
                update_post_meta($imageID, 'ic_compressing', array('status' => 'compressed'));
                update_post_meta($imageID, 'ic_stats', $stats);
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'compressed'], 60);
                die('skipped');
            }


            $api_url = WPC_IC_LOCAL_DOWNLOAD . '?imageID=' . $imageID . '&apikey=' . $expected_token;

            // Retry up to 3 times for bulk reliability
            $response = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $response = wp_remote_get($api_url, ['timeout' => 20]);
                if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) >= 200 && wp_remote_retrieve_response_code($response) < 300) {
                    break;
                }
                if ($attempt < 3) usleep(500000); // 0.5s delay between retries
            }
            if (is_wp_error($response)) {
                wp_die('API call failed: ' . $response->get_error_message());
            }


            $image_data = wp_remote_retrieve_body($response);

            if (empty($image_data) || $image_data == 'No optimized images found.') {
                // No Image Optimized
            } else {
                $body = json_decode($image_data);

                // Save optimized image in WordPress uploads directory
                $relative_path = get_post_meta($imageID, '_wp_attached_file', true);
                $upload_dir = wp_upload_dir();
                $absolute_path = $upload_dir['basedir'] . '/' . $relative_path;
                $finalImagePath = str_replace($basename, '', $absolute_path);

                // Flags
                $errors = false;
                $done = false;

                // Stats
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

                // Skip legacy CDN-download path when modern singleCompressV4 has
                // already processed this image OR when service is in inline-only mode.
                //
                // Background: service v1.22.85+ stopped uploading variants to Bunny CDN, but
                // the /download endpoint (this function's source of $body->files) still returns
                // Bunny URLs for variants. Those URLs resolve to 678-byte HTML 404 pages. The
                // foreach below would wp_remote_get every URL, validator correctly REJECTs them
                // as CorruptByte, but the post-loop merge at line ~5081 then REBUILDS
                // ic_local_variants from this smaller/stale $body->files set and merges with
                // existing variants preserving only bg_upgraded entries. Phase A's freshly-
                // written non-bg_upgraded entries (especially WebP) get CLOBBERED. Symptom:
                // disk WebP files get deleted by downstream cleanup chains after their meta
                // entries vanish.
                //
                // Two-tier detection:
                //   Tier 1: Modern flow already did the work — ic_status=compressed AND
                //           ic_local_variants has entries. ?downloadImage= is legacy dead code
                //           after singleCompressV4 ran; no reason to re-process.
                //   Tier 2: $body->files all have empty $value->url — pure inline-only response.
                //           Service /download endpoint may eventually return this shape too.
                //
                // A later release may replace both tiers with an explicit X-Service-Inline-Only header
                // once service v1.22.86 propagates that signal to /download too.
                $_existing_variants = get_post_meta($imageID, 'ic_local_variants', true);
                $_ic_status = get_post_meta($imageID, 'ic_status', true);
                // Broader skip detection. The earlier `ic_status === 'compressed'`
                // requirement was too strict — admin JS fires ?downloadImage=N mid-compress
                // (before Phase A finalizes ic_status), bypassing the skip and clobbering
                // variants. Confirmed via log analysis: 23 CorruptByte vs 5 SKIPPED, ~78% miss
                // rate. New check: skip if ic_local_variants has ANY entries — means modern
                // flow has done at least one round of writes, legacy clobber would only
                // destroy work. Safe edge case: empty variants → image never compressed →
                // legacy flow runs (it's the only path for un-compressed images).
                $_modern_flow_done = is_array($_existing_variants) && !empty($_existing_variants);
                // Tier 3: also skip when a compress is actively in flight. Catches
                // the post-restore race: restore wipes ic_local_variants, customer clicks
                // Compress, admin JS fires ?downloadImage= in parallel BEFORE Phase A writes
                // its first inline entry. Tier 1 (variants populated) doesn't fire yet, Tier 2
                // (urls empty) doesn't fire under hybrid mode — but the compress-in-flight
                // signal does. Uses the same `wpc_compress_lock_$id` transient that the
                // /compress-single REST endpoint sets at line 3952 and clears in its finally{}.
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
                    // Phase A already wrote ic_local_variants + disk bytes. Don't touch them.
                    // Bg-swap callbacks own AVIF + post-response refinement under their lock.
                    // For UI consistency, set the same status flags downloadImages's success
                    // path would set (lets heartbeat poll see "compressed" without firing the
                    // clobber-prone merge below).
                    update_post_meta($imageID, 'wpc_images_compressed', 'true');
                    update_post_meta($imageID, 'ic_status', 'compressed');
                    update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
                    delete_transient('wps_ic_compress_' . $imageID);
                    delete_transient('wps_ic_queue_' . $imageID);
                    if ($isBulk) {
                        // Advance bulk counter so a bulk pass doesn't stall on inline-only responses.
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

                        // Optimized basename
                        $imageSize = $value->label;
                        $originalSize = $value->originalSize;
                        $compressedSize = $value->optimizedSize;
                        $savings = $value->savingsPercent;
                        // Service v1.22.11.9-fast may omit url in inline-bytes mode.
                        // Legacy downloadImages doesn't process inline bytes, so skip the variant
                        // cleanly when no url. (Modern flow uses singleCompressV4 + downloadVariants
                        // which DO handle inline bytes via base64 decode at line 4585.)
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

                            // Download optimized
                            $response = wp_remote_get($optimizedUrl);

                            if (!is_wp_error($response)) {
                                $image_data = wp_remote_retrieve_body($response);

                                // Content-based validation. Replaces filename-only check
                                // that allowed 678-byte HTML sentinels with .webp/.avif filenames.
                                $legacy_fmt = 'jpeg';
                                if (strpos($optimizedBasename, '.avif') !== false) $legacy_fmt = 'avif';
                                elseif (strpos($optimizedBasename, '.webp') !== false) $legacy_fmt = 'webp';
                                elseif (strpos($optimizedBasename, '.png') !== false) $legacy_fmt = 'png';
                                $is_valid = wpc_is_valid_image_bytes($image_data, $legacy_fmt, isset($imageID) ? $imageID : 0, 'legacy_url_download', ['size_label' => $optimizedBasename, 'url' => $optimizedUrl]);
                                if ($is_valid) {

                                    // Local backup: copy original to _bkp before overwrite when backup includes local
                                    $backupSetting = isset(self::$settings['backup']) ? self::$settings['backup'] : 'cloud';
                                    if (($backupSetting === 'local' || $backupSetting === 'local-cloud') && file_exists($optimizedFilePath)) {
                                        $pathInfo = pathinfo($optimizedFilePath);
                                        $bkpPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '_bkp.' . $pathInfo['extension'];
                                        if (!file_exists($bkpPath)) {
                                            @copy($optimizedFilePath, $bkpPath);
                                        }
                                    }

                                    // Remove original file
                                    if (file_exists($optimizedFilePath)) {
                                        unlink($optimizedFilePath);
                                    }

                                    // Save the file
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

                    // Proceed if at least one file downloaded — thumbnail errors are non-critical
                    if ($done) {
                        // Flag for Bulk Memory
                        if ($isBulk) {
                            $thumbCount = $this->getAllThumbSizes();
                            $bulkStatus = get_option('wps_ic_BulkStatus');

                            $parsedImages['total']['original'] += $stats['original']['original']['size'];
                            $parsedImages['total']['compressed'] += $stats['original']['compressed']['size'];

                            $parsedImages[$imageID]['total']['original'] = $parsedImages['total']['original'];
                            $parsedImages[$imageID]['total']['compressed'] = $parsedImages['total']['compressed'];

                            // Write down last compressed before-after
                            update_option('wps_ic_parsed_images', $parsedImages);

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

                            // Write counter for bulk UI
                            $counter = [];
                            $counter['images'] = $bulkStatus['compressedImageCount'];
                            $counter['imagesAndThumbs'] = $bulkStatus['compressedThumbs'];
                            update_option('wps_ic_bulk_counter', $counter);
                        }

                        // Compressing status
                        delete_transient('wps_ic_compress_' . $imageID);
                        delete_transient('wps_ic_queue_' . $imageID);

                        $imageStats = get_post_meta($imageID, 'ic_stats', true);
                        $compressing = get_post_meta($imageID, 'ic_compressing', true);

                        update_post_meta($imageID, 'wpc_images_compressed', 'true');
                        update_post_meta($imageID, 'ic_status', 'compressed');
                        update_post_meta($imageID, 'ic_compressing', array('status' => 'compressed'));
                        update_post_meta($imageID, 'ic_stats', $stats);
                        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'compressed'], 60);

                        // Reset retry counter + clear dedup transients on success (Phase 3 completion hook)
                        delete_post_meta($imageID, '_wpc_optimize_attempts');
                        delete_transient('wpc_queued_' . $imageID);
                        delete_transient('wpc_failed_' . $imageID);
                        if (function_exists('wp_cache_delete')) {
                            wp_cache_delete('wpc_queued_' . $imageID, 'wpc');
                        }

                        // Process skippedFormats from service response (L3)
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

                        // Store variant data for <picture> delivery and savings display
                        // Schema (L2): 'url','originalSize','size','savings','skipped' (bool regression), 'skipped_formats' (array)
                        $variants = [];
                        foreach ($body->files as $variant) {
                            $orig = intval($variant->originalSize ?? 0);
                            $opt = intval($variant->optimizedSize ?? 0);
                            $is_regression = ($orig > 0 && $opt > 0 && $opt >= $orig);
                            // Null-safe url storage. v1.22.11.9-fast omits url in
                            // inline-bytes mode; store empty string instead of null so downstream
                            // readers (Modern Delivery srcset, modal display) don't see typed null.
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

                        // Backport the lock+merge fix from singleCompressV4 Phase A
                        // (line 5260) and downloadVariants (line ~5505). downloadImages() previously
                        // full-replaced ic_local_variants without merging bg-swap-delivered entries,
                        // clobbering AVIF variants when ?downloadImage=N fired after a successful
                        // compress + bg-swap drain. See service team report 2026-04-30.
                        global $wpdb;
                        $dl_lock_name = 'wpc_bg_meta_' . (int) $imageID;
                        $dl_got_lock  = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $dl_lock_name));
                        $dl_locked    = ($dl_got_lock === '1' || $dl_got_lock === 1);
                        try {
                            // Re-read inside the lock to capture any bg-swap entries that landed
                            // between this download starting and now.
                            $existing_variants = get_post_meta($imageID, 'ic_local_variants', true);
                            if (is_array($existing_variants) && !empty($existing_variants)) {
                                foreach ($existing_variants as $existing_key => $existing_entry) {
                                    // Bg-swap-delivered entries have `bg_upgraded` (unix ts) set.
                                    // Preserve them unconditionally — they reflect post-compress
                                    // bg refinement (e.g. AVIF that downloadImages does not produce).
                                    if (!empty($existing_entry['bg_upgraded'])) {
                                        $variants[$existing_key] = $existing_entry;
                                        continue;
                                    }
                                    // Pre-existing entry that this download didn't touch — keep it.
                                    if (!isset($variants[$existing_key])) {
                                        $variants[$existing_key] = $existing_entry;
                                    }
                                    // Otherwise: this download's fresh entry takes precedence.
                                }
                            }
                            update_post_meta($imageID, 'ic_local_variants', $variants);
                        } finally {
                            if ($dl_locked) {
                                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $dl_lock_name));
                            }
                        }

                        // Use shared live-derive helper with canonical
                        // 4-tier originalSize lookup. Card and modal share this logic so
                        // they can never disagree on the headline savings %.
                        $best = wpc_compute_best_savings($variants, $imageID);
                        if ($best['pct'] > 0 && $best['orig'] > 0) {
                            update_post_meta($imageID, 'ic_savings',          round($best['pct'], 1));
                            update_post_meta($imageID, 'ic_savings_format',   $best['format']);
                            update_post_meta($imageID, 'ic_savings_bytes',    $best['orig'] - $best['opt']);
                            update_post_meta($imageID, 'ic_savings_baseline', $best['orig']);
                        }

                        // Invalidate CDN coexistence cache
                        if (function_exists('wpc_invalidate_local_cache')) {
                            wpc_invalidate_local_cache();
                        }

                        // Ovo je radilo probleme jer generira thumbove iz -scaled verzije slike, i onda generira nove thumbove koji su scaled
                        // Get full image path
                        $relative_path = get_post_meta($imageID, '_wp_attached_file', true);
                        $upload_dir = wp_upload_dir();
                        $file_path = $upload_dir['basedir'] . '/' . $relative_path;

                        $unscaledPath = str_replace('-scaled.', '.', $file_path);
                        if (file_exists($unscaledPath)) {
                            $file_path = $unscaledPath;
                        }

                        // Regenerate metadata and thumbnails - ISSUE: because it rebuilds images and loses optimization
//                    $metadata = wp_generate_attachment_metadata($imageID, $file_path);
//                    if ($metadata && !is_wp_error($metadata)) {
//                        wp_update_attachment_metadata($imageID, $metadata);
//                        echo 'Metadata updated and thumbnails regenerated.';
//                    } else {
//                        echo 'Failed to generate metadata.';
//                    }
                    }

                }
            }

            die();
        }
    }


    /**
     * Start the Bulk Process (Restore or Compress)
     * @return void
     */
    public function initBulk()
    {
        if (!empty($_GET['getImageList'])) {

            // Check if API Key is valid
            $this->checkAPIKey();

            if (empty($_GET['action']) || $_GET['action'] == 'compress') {
                // Compress
                $imagesToProcess = $this->getAllImageIDs();
            } else {
                // Restore
                $imagesToProcess = $this->getImagesToRestore();
            }

            // Count number of found images
            $countImagesToOptimize = count($imagesToProcess);

            // Multiply by number of thumbnails
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


    /**
     * Get All ImageIDs to Restore
     * @param $per_page
     * @return array|int[]|WP_Post[]
     */
    public function getImagesToRestore($per_page = 100)
    {
        $all_ids = [];

        // List of allowed image MIME types
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];

        $meta_query = [
            'relation' => 'AND',
            ['key' => 'ic_stats', 'compare' => 'EXISTS'],
            ['key' => 'wps_ic_exclude_live', 'compare' => 'NOT EXISTS'],
        ];

        // First query just to get total count
        $initial_query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => $meta_query]);

        $total_images = $initial_query->found_posts;
        $total_pages = ceil($total_images / $per_page);

        // Now loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => $per_page, 'paged' => $page, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => $meta_query]);

            $all_ids = array_merge($all_ids, $query->posts);
        }

        return $all_ids;
    }


    /**
     * Get All Image IDs to Compress
     * @param $per_page
     * @return array|int[]|WP_Post[]
     */
    public function getAllImageIDs($per_page = 100)
    {
        $all_ids = [];

        // List of allowed image MIME types
        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif'];

        // Add v2 protocol exclusion. The original filter only checked
        // legacy `ic_stats NOT EXISTS`, but v2 doesn't write ic_stats — it writes
        // ic_status='compressed' (and ic_local_variants). Without the v2 branch,
        // every v2-compressed image was reported as uncompressed → the bulk queue
        // re-ran them every time the user started a new bulk. Mirrors the same
        // fix already applied to prepareImages() + countLibraryImages().
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

        // First query just to get total count
        $initial_query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => 1, 'fields' => 'ids', 'meta_query' => $meta_query]);

        $total_images = $initial_query->found_posts;
        $total_pages = ceil($total_images / $per_page);

        // Now loop through all pages
        for ($page = 1; $page <= $total_pages; $page++) {
            $query = new WP_Query(['post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $allowed_mimes, 'posts_per_page' => $per_page, 'paged' => $page, 'fields' => 'ids', 'no_found_rows' => true, 'meta_query' => $meta_query]);

            $all_ids = array_merge($all_ids, $query->posts);
        }

        return $all_ids;
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

        // Is file extension allowed
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

        // Image Backup Exists
        if ($this->backup_exists($imageID)) {
            return true;
        }

        // Setup Image Stats
        $stats = [];
        $backup_list = [];

        // Create backup directory
        $this->create_backup_directory();

        // Get filename
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

        // Define original / backup file paths
        $original_file_location = ABSPATH . $parsed_url['path'];

        // Where is backup saved?
        $backup_file_location = $backup_dir . '/' . $filename;

        // Stats
        $stats['original']['original']['size'] = filesize($original_file_location);

        copy($original_file_location, $backup_file_location);

        $backup_list['original'] = $backup_file_location;

        if (!file_exists($backup_file_location)) {
            // TODO: What then
            //wp_send_json_error('failed-to-create-backup');
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
                    // If backup file exists
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

        // Skip if the post-restore thumb-regen worker is currently rebuilding
        // WP size variants. The remove_filter dance in wpc_regen_thumbs_hook fails because
        // it instantiates a new wps_local_compress object — WP keys hooks by spl_object_hash,
        // so the new instance's hash doesn't match the original registration. Without this
        // guard, regen → wp_generate_attachment_metadata → on_upload → sets wps_ic_compress_
        // transient + adds to queue → media-library card flips back to "Optimizing" minutes
        // after the user has already seen "Compressed" or "Restored".
        if (get_post_meta($imageID, '_wpc_pending_thumb_regen', true)) {
            return $data;
        }

        // Post-restore grace window: extends the pending_thumb_regen guard
        // for ~30s AFTER the regen worker completes. Catches the gap where regen has
        // finished (pending_thumb_regen deleted) but a late wp_generate_attachment_metadata
        // call still cascades into on_upload → queues fresh compress → bg-swap overwrites
        // the just-restored pristine bytes. Set in wpc_regen_thumbs_hook on success.
        // No-op for normal upload flow (transient never set outside post-restore).
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
        $queue = get_option('wpc_compress_queue', []);
        if (!in_array($imageID, $queue)) {
            $queue[] = $imageID;
            update_option('wpc_compress_queue', $queue, false);
        }

        $queueSize = count(get_option('wpc_compress_queue', []));
        $workerRunning = get_transient('wpc_compress_lock') ? 'YES' : 'NO';
        error_log('[WPC Queue] on_upload image=' . $imageID . ' queue_size=' . $queueSize . ' worker_running=' . $workerRunning . ' elapsed=' . round(microtime(true) - $t0, 3) . 's');

        // Start worker if not already running
        $this->fireQueueWorker();

        return $data;
    }

    /**
     * Re-optimize an image after a WP media-editor edit (Crop/Rotate/Scale).
     *
     * WP edits create -e{timestamp} files and call wp_update_attachment_metadata (NOT
     * wp_generate_attachment_metadata), so on_upload never fires and the edited image is
     * left with stale/no optimized variants. This filter detects the editor save and
     * schedules ONE re-optimize for the attachment; run_v2_optimize regenerates every
     * variant from the edited files and overwrites stale ic_local_variants, so no explicit
     * state-invalidation is needed. Deferred via a single cron event so the editor AJAX
     * response is not blocked by the Phase A POST. Returns $data unchanged (side-effect only).
     *
     * @param array $data          The (filtered) attachment metadata.
     * @param int   $attachment_id The attachment being updated.
     * @return array $data unchanged.
     */
    public function wpc_reoptimize_edited_image($data, $attachment_id)
    {
        static $fired = [];

        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 || isset($fired[$attachment_id])) {
            return $data;
        }

        // Only on an actual WP image-editor save — NOT every metadata update. Uploads,
        // other plugins, and on_upload's own wp_update_attachment_metadata call all skip here.
        // Covers BOTH editor surfaces: the classic media editor (admin-ajax action=image-editor,
        // do=save/scale) AND the block-editor / REST crop (POST /wp/v2/media/{id}/edit).
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

        // Defer so the editor AJAX response returns before the Phase A POST runs. WP dedups
        // an identical hook+args within ~10 min; run_v2_optimize's per-image in-flight lock
        // is the final guard against a double dispatch.
        if (!wp_next_scheduled('wpc_reoptimize_edited_image_event', [$attachment_id])) {
            wp_schedule_single_event(time(), 'wpc_reoptimize_edited_image_event', [$attachment_id]);
        }

        return $data;
    }

    /**
     * Deferred handler for wpc_reoptimize_edited_image(): re-run the v2 optimize
     * for an edited attachment. Idempotent — run_v2_optimize holds a per-image in-flight lock.
     *
     * @param int $attachment_id
     * @return void
     */
    public function wpc_run_edited_reoptimize($attachment_id)
    {
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0) {
            return;
        }
        if (class_exists('wps_ic_ajax') && method_exists('wps_ic_ajax', 'run_v2_optimize')) {
            // Back up BEFORE the re-optimize. This fires from the
            // wpc_reoptimize_edited_image_event cron scheduled on every WP image-editor save
            // (crop/rotate via wp_update_attachment_metadata), and run_v2_optimize →
            // process_response does an IN-PLACE destructive overwrite of the parent. Without a
            // backup the freshly-edited file is destroyed with no pristine copy → restore impossible.
            // backup_all_sizes is idempotent (file_exists-guarded), so existing backups are a no-op.
            // NOTE: because of that !file_exists guard, a pre-edit backup is NOT refreshed here — a
            // later Restore recovers the PRE-EDIT image, not the edit. That is a separate, pre-existing
            // stale-restore behavior flagged to the team; this guard's job is solely to ensure the
            // re-optimize never overwrites with ZERO backup present (the data-loss hole).
            if (class_exists('wps_local_compress')) {
                $bk = new wps_local_compress();
                if (method_exists($bk, 'backup_all_sizes')) {
                    $bk->backup_all_sizes($attachment_id);
                }
            }
            wps_ic_ajax::run_v2_optimize($attachment_id);
        }
    }

    // ─── Loopback health check ─────────────────────────────────

    private function canLoopback() {
        return get_option('wpc_loopback_status', '') !== 'fail';
    }

    public function testLoopback() {
        $api_key = $this->getApiKey();
        if (empty($api_key)) {
            update_option('wpc_loopback_status', 'fail', false);
            return false;
        }

        // CACHE the result. This is a BLOCKING self-POST to the public host (= the CF/WAF
        // edge on a datacenter-IP site, where it 401s after ~1.5s — live: anacletababy) and it was
        // re-running on EVERY settings-page load. Re-test at most once/hour; otherwise serve the cached
        // verdict so the admin isn't taxed a blocking probe per pageview. (Delete the transient to force
        // a fresh test.)
        if (get_transient('wpc_loopback_test_at') !== false) {
            return get_option('wpc_loopback_status', 'fail') === 'ok';
        }
        set_transient('wpc_loopback_test_at', time(), HOUR_IN_SECONDS);

        // CF/edge-fronted shortcut. When THIS request is proxied through Cloudflare, the
        // self-POST to the PUBLIC host hits the edge WAF (401, ~1.5s blocking) and never reaches the REST
        // endpoint, so the probe can't certify it AND it taxes an FPM/LiteSpeed worker on the hourly
        // re-test. The service's REAL fetch arrives via the residential-proxy path that bypasses the WAF,
        // so the self-loopback verdict is unrelated to true endpoint reachability — assume reachable (no
        // false 'fail', no blocking probe; the transient above still throttles).
        // Gate ONLY on the live per-request CF-Ray header: it is present iff the current request actually
        // came through Cloudflare. We deliberately do NOT use the sticky wpc_v2_cf_assets_seen option (an
        // ex-CF site would keep it and falsely shortcut) nor HTTP_CF_VISITOR (absent on some CF configs).
        if (!empty($_SERVER['HTTP_CF_RAY'])) {
            update_option('wpc_loopback_status', 'ok', false);
            return true;
        }

        $response = wp_remote_post(rest_url('wpc/v1/fetch'), [
            'blocking'  => true,
            'timeout'   => 3, // was 5; bound the blocking probe
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
        if ($this->enabledLog == 'true') {
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

    /**
     * Cross-pipeline dedup. Removes the given imageID from the ladder
     * backfill queue. Called from on_upload() and from the wps_ic_compress_live
     * AJAX handler so a full compress pre-empts a redundant ladder fire (full
     * compress will deliver every variant the ladder would have backfilled).
     *
     * Edge case: if the ladder worker is mid-fire on the wire for this image,
     * we can't recall it — the service POST is already in flight. The ladder
     * delivery completes and writes whatever variant came back; Phase A's
     * compress overwrites it shortly after with the full-set variant. No data
     * loss, just one wasted ladder hit on that one image.
     */
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

    /**
     * @param int    $imageID      WordPress attachment ID
     * @param string $output       'json' sends wp_send_json_* responses; 'silent' stays quiet (queue/bulk contexts)
     * @param bool   $allowRetry   true = schedule exponential-backoff cron retry on transient POST failures (default);
     *                             false = fail fast (used by bulk to avoid thundering-herd retries)
     * @param string $source       Telemetry attribution: upload / single / bulk / retry (default 'unknown')
     */
    public function singleCompressV4($imageID, $output = 'json', $allowRetry = true, $source = 'unknown')
    {
        @set_time_limit(120); // defensive: shared hosts sometimes throttle max_execution_time to 30s; Phase A can legitimately take 5-10s
        wp_raise_memory_limit('image');
        $t_compress_start = microtime(true);

        // Stamp the compress-generation timestamp BEFORE any service interaction.
        // The bg-swap callback handler at compress.php:~1090 reads this via post_meta (or
        // prefers the service-supplied compressStartedAt if present) to decide whether each
        // late callback belongs to a still-current compress generation or a pre-restore one.
        // Writing here (vs later) ensures every bg-swap callback fired by THIS run carries a
        // valid post-restore stamp, even if an immediate restore happens before Phase A
        // completes.
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

        // v2 protocol routing for the QUEUE WORKER path (on-upload +
        // retry + cron drains all call singleCompressV4). Previously only the
        // click-driven wps_ic_compress_live AJAX dispatched to run_v2_optimize;
        // anything that came through the queue went straight to v1's
        // /optimize endpoint — which the service team retired (returns 404).
        // Result on fresh installs: image uploads queued forever, never
        // compressed, ENDPOINT_GONE on every queue tick. Same pattern as
        // ajax.class.php:4348 — try v2 first, fall through to v1 on failure
        // (defence in depth even though v1 is dead; the fallthrough at least
        // logs a meaningful ENDPOINT_GONE for diagnostics).
        if (function_exists('wpc_use_v2_protocol') && wpc_use_v2_protocol()
            && class_exists('WPS_LocalV2')
            && class_exists('wps_ic_ajax')
            && method_exists('wps_ic_ajax', 'run_v2_optimize')) {
            $v2_result = wps_ic_ajax::run_v2_optimize($imageID);
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

        // Set the image status (always include time for staleness detection)
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing', 'time' => time()], 120);
        set_transient('wps_ic_queue_' . $imageID, ['imageID' => $imageID, 'status' => 'waiting'], 30);

        // Save OLD post meta for restore usage
        if (!get_post_meta($imageID, 'wpc_old_meta')) {
            $oldMeta = wp_get_attachment_metadata($imageID);
            update_post_meta($imageID, 'wpc_old_meta', $oldMeta);
        }

        // Prepare the request params WPC_IC_LOCAL_OPTIMIZE
        // Site URL — force HTTPS if any SSL indicator is present (fixes HTTP→HTTPS redirect callback issue)
        $site_url = get_site_url();
        if (is_ssl()
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || strpos(home_url(), 'https://') === 0
            || (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos($_SERVER['HTTP_CF_VISITOR'], 'https') !== false)
        ) {
            $site_url = str_replace('http://', 'https://', $site_url);
        }

        // Build params and send image via POST with GET fallback
        $settings = get_option(WPS_IC_SETTINGS);
        $request_params = self::buildOptimizeParams($imageID, $site_url, $settings);
        $t_post = microtime(true);
        $response = self::postOptimize($imageID, $request_params, true, 120);
        $postTime = round(microtime(true) - $t_post, 2);
        error_log('[WPC Timing] image=' . $imageID . ' postOptimize=' . $postTime . 's');

        // Validate response — fail fast instead of leaving image in "compressing" state
        if (is_wp_error($response)) {
            delete_transient('wps_ic_compress_' . $imageID);
            delete_transient('wps_ic_queue_' . $imageID);

            // 403 = plan/auth issue — show specific message
            if ($response->get_error_code() === 'wpc_not_authorized') {
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'local-not-authorized']);
                }
                return;
            }

            // 404/410 = endpoint deprecated — terminal failure, don't retry or queue
            if ($response->get_error_code() === 'wpc_endpoint_gone') {
                error_log('[WPC] ENDPOINT_GONE for image ' . $imageID . ' — ' . $response->get_error_message());
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'endpoint-gone']);
                }
                return;
            }

            // Transient retry for service 5xx/429/408/timeout. postOptimize() always
            // wraps non-2xx in WP_Error('wpc_post_optimize_failed', ..., ['http_code' => N])
            // (see line ~2726). Extract http_code, classify, schedule exponential-backoff cron.
            // Previously the retry block at line ~4499 was unreachable because is_wp_error
            // catches the response at line 4456 first and returns early.
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
                    // 3-layer dispatch (mirrors Phase B downloads). WP cron alone is
                    // unreliable: cron only fires on site traffic, so on low-traffic sites or
                    // admin-only testing, retries can sit pending for minutes. Loopback gives
                    // sub-second retry on healthy hosts; cron + admin_init drain are the
                    // safety nets for Basic-Auth / loopback-blocked / cron-disabled hosts.
                    // First attempt delay shortened from 30s → 5s since loopback fires NOW.
                    $delay = [5, 30, 120][$attempts];
                    update_post_meta($imageID, '_wpc_service_retry_attempts', $attempts + 1);

                    // Layer 1: instant loopback (sub-second on healthy hosts). The retry
                    // hook self-locks to prevent racing with layers 2+3.
                    if (function_exists('wpc_fire_retry_compress_worker')) {
                        wpc_fire_retry_compress_worker($imageID);
                    }
                    // Layer 2: WP Cron at $delay (safety net for hosts where loopback is blocked)
                    if (!wp_next_scheduled('wpc_retry_compress', [$imageID])) {
                        wp_schedule_single_event(time() + $delay, 'wpc_retry_compress', [$imageID]);
                    }
                    // Layer 3: admin_init drain (passive recovery on next admin page view) — registered globally

                    error_log('[WPC] image=' . $imageID . ' transient=HTTP' . $err_http . ' retry layers=loopback+cron+admin_init delay=' . $delay . 's attempt=' . ($attempts + 1));
                    if ($output == 'json') {
                        wp_send_json_success(['msg' => 'retry-scheduled', 'retry_in_seconds' => $delay, 'attempt' => $attempts + 1]);
                    }
                    return;
                }
                // Retries exhausted — clean up counter, fall through to terminal failure
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

            // Centralized transient-retry: schedule exponential-backoff cron event when caller opts in.
            // Bulk passes $allowRetry=false to avoid 100+ simultaneous cron fires during service blips.
            // Upload, single (sync + loopback + cap), lazy-backfill retry paths all benefit automatically.
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
                // Retries exhausted — clean up counter and fall through to terminal failure
                delete_post_meta($imageID, '_wpc_service_retry_attempts');
            }

            // Compress failure telemetry — only counted once terminal retry path exhausted
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

        // Extract AI metadata from POST response for stats modal
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

        // Use URLs from POST response (v1.12.6+) — skip separate /download call
        $dl_files = $post_body->optimizedResults ?? [];
        if (empty($dl_files)) {
            // Fallback: call /download endpoint for older service versions
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

        // Backup is handled by backup_all_sizes() in the queue worker BEFORE singleCompressV4 is called
        error_log('[WPC Timing] image=' . $imageID . ' files_to_download=' . count($dl_files));

        // Capture baseline from disk BEFORE download loop overwrites files
        $_orig_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : false;
        $_scaled_path = get_attached_file($imageID);
        $_orig_size = ($_orig_path && file_exists($_orig_path)) ? filesize($_orig_path) : 0;
        $_scaled_size = ($_scaled_path && file_exists($_scaled_path)) ? filesize($_scaled_path) : 0;
        $disk_baseline = max($_orig_size, $_scaled_size);

        // Resolve local paths
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

        // Build download list AND perform inline-byte writes from service response.
        // Handles BOTH response shapes transparently via extension detection on $value->url:
        //   OLD (pre-rc10.3h): one entry per (size, format), url extension is the format's
        //   NEW (rc10.3h+):    one entry per size, url is JPEG; webpInfo/avifInfo give siblings;
        //                      inlineBytes.{fmt} carries base64 payload; skip.{fmt} marks intentional drops
        $downloads = [];
        $inline_count = 0;
        // Per-variant timing collection for service-team correlation
        $variant_timings = []; // [sizeLabel, fmt, path, bytes, decode_ms, write_ms]

        // First-hour diagnostic logging to confirm handler is firing in real traffic
        $log_until = (int) get_option('wpc_inline_log_until', 0);
        if ($log_until === 0) {
            $log_until = time() + 3600;
            update_option('wpc_inline_log_until', $log_until, false);
        }
        $should_log = (time() < $log_until);

        // Atomic inline write with forensic verification. Captures pre-write existing
        // size, expected (data being written), and post-write actual disk size. Emits a
        // [WPC WriteVerify] log line ONLY when expected != actual (low-noise; only fires on
        // anomalies). Detects: filesystem rename anomalies, concurrent overwrites by other
        // processes, atomic-write reordering, or post-write tampering. Returns
        // ['ok' => bool, 'pre' => int, 'post' => int, 'expected' => int] for caller to log.
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
            // Forensic mismatch log — only fires when disk content differs from what we wrote.
            // Captures imageID, size_label, fmt, exact path, expected vs actual byte counts,
            // and pre-write existing size for cross-correlation with bg-swap or concurrent writers.
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
            // Service v1.22.11.9-fast omits `url` in inline-bytes mode (no Bunny upload
            // happened, so no public URL to ship). Plugin falls back to `fileName` for naming +
            // format derivation. Skip the variant only if BOTH are missing — that means service
            // sent a malformed entry with no actionable data.
            if (empty($file_url) && empty($file_name)) continue;

            $stats['original']['original']['size'] += $value->originalSize ?? 0;
            if (!empty($value->optimizedSize) && $value->optimizedSize > 0) {
                $stats['original']['compressed']['size'] += $value->optimizedSize;
            }
            $stats['original']['compressed']['thumbs'] += 1;

            // Use fileName when available (authoritative since v1.22.11.9-fast); fall back to
            // basename(url) for legacy mode where service still emits CDN URLs.
            $primary_base    = !empty($file_name) ? basename($file_name) : basename($file_url);
            $primary_ext     = strtolower(pathinfo($primary_base, PATHINFO_EXTENSION));
            $primary_fmt     = ($primary_ext === 'avif') ? 'avif' : (($primary_ext === 'webp') ? 'webp' : 'jpeg');
            $size_label      = $value->sizeLabel ?? $value->label ?? pathinfo($primary_base, PATHINFO_FILENAME);
            // CDN dir only meaningful when url is present (inline-bytes mode → empty)
            $cdn_dir         = !empty($file_url) ? dirname($file_url) : '';

            // Build per-format map. Old shape populates only the primary format from $value->url.
            // New shape also adds webp/avif from $value->webpInfo->fileName / $value->avifInfo->fileName.
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

                // Honor intentional service-side drops (e.g. format_winner dropped WebP because AVIF won)
                if (!empty($value->skip->{$fmt})) {
                    if ($should_log) error_log("[wpc] variant {$size_label} fmt={$fmt} path=skip reason=" . (string) $value->skip->{$fmt});
                    continue;
                }

                // Inline path — rc10.3h.1 shipped with a flat `bytes` field per entry (not the nested
                // `inlineBytes.{fmt}` map the spec described). Since each service response entry is
                // already one (size, format) pair, `bytes` applies to the primary format only.
                // Falls back gracefully if service uses the nested shape in a future version.
                $inline_b64 = null;
                if ($fmt === $primary_fmt && !empty($value->bytes)) {
                    $inline_b64 = $value->bytes;
                } elseif (!empty($value->inlineBytes->{$fmt})) {
                    $inline_b64 = $value->inlineBytes->{$fmt};
                }
                if (!empty($inline_b64)) {
                    // Capture decode + write time per variant for detailed diagnostics
                    $t_decode_start = microtime(true);
                    $bytes = base64_decode($inline_b64, true);
                    $t_decode_end = microtime(true);
                    if ($bytes !== false && strlen($bytes) > 0) {
                        // Content validator: reject corrupt/sentinel bytes BEFORE writing
                        // to disk. Service-side bug occasionally produces 678-byte placeholders;
                        // logging captures source attribution for service-team escalation.
                        if (!wpc_is_valid_image_bytes($bytes, $fmt, $imageID, 'phase_a_inline')) {
                            $variant_timings[] = ['size' => $size_label, 'fmt' => $fmt, 'path' => 'inline-corrupt-rejected', 'bytes' => strlen($bytes), 'decode_ms' => (int) round(($t_decode_end - $t_decode_start) * 1000), 'write_ms' => 0];
                            continue;
                        }
                        // Size regression guard — don't overwrite smaller existing file with larger optimized
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
                        // fall through to URL path below
                    } else {
                        $variant_timings[] = ['size' => $size_label, 'fmt' => $fmt, 'path' => 'inline-decode-fail', 'bytes' => 0, 'decode_ms' => 0, 'write_ms' => 0];
                        if ($should_log) error_log("[wpc] variant {$size_label} fmt={$fmt} path=inline-decode-fail — falling through to URL");
                    }
                }

                // URL path — queue for Phase B async download. Existing-file guard preserved from the earlier inline-write version.
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

        // Consolidated DETAILED_TIMING log line for service-team correlation.
        // One line per compress, parseable, includes everything needed to diagnose slow compresses.
        // Gated behind WP_DEBUG so production fleets with WP_DEBUG=false get zero spam.
        //          Customers can flip WP_DEBUG=true in wp-config.php when actively debugging.
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $post_timing = get_post_meta($imageID, '_wpc_last_post_timing', true);
            $api_options = get_option(WPS_IC_OPTIONS);
            $apikey      = !empty($api_options['api_key']) ? $api_options['api_key'] : '';
            $apikey_prefix = $apikey ? substr($apikey, 0, 8) : '-';
            $phase_a_total_ms = (int) round((microtime(true) - $t_compress_start) * 1000);
            $service_ttfb_ms       = is_array($post_timing) ? (int) ($post_timing['ttfb_ms'] ?? 0) : 0;
            $service_total_ms      = is_array($post_timing) ? (int) ($post_timing['total_ms'] ?? 0) : 0;
            $service_resp_bytes    = is_array($post_timing) ? (int) ($post_timing['size_bytes'] ?? 0) : 0;
            // Body-side service timing (v1.22.5+ pods only; legacy pods report 0)
            $service_multer_ms     = is_array($post_timing) ? (int) ($post_timing['multer_ms'] ?? 0) : 0;
            $service_phase1_ms     = is_array($post_timing) ? (int) ($post_timing['phase1_ms'] ?? 0) : 0;
            $service_db_writes_ms  = is_array($post_timing) ? (int) ($post_timing['db_writes_ms'] ?? 0) : 0;
            $plugin_overhead_ms    = max(0, $phase_a_total_ms - $service_total_ms);
            // Source file size (input to service)
            $input_path = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($imageID) : get_attached_file($imageID);
            $input_size = ($input_path && file_exists($input_path)) ? filesize($input_path) : 0;
            // Variant summary
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

            // Per-variant detail with full forensic fields. Parseable single-line format.
            // file=  service-provided fileName (write target basename)
            // target= absolute disk path plugin computed and wrote to
            // pre=    existing file size before write (0 if didn't exist)
            // post=   actual file size on disk AFTER atomic rename + clearstatcache
            // bytes=  expected bytes (strlen of decoded inline payload)
            // post != bytes triggers a separate [WPC WriteVerify] mismatch log line.
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

        // Clear the one-shot timing meta now that we've logged it (runs regardless of log gating)
        delete_post_meta($imageID, '_wpc_last_post_timing');

        // ── PHASE A: synchronous metadata write + async dispatch ──
        // Service-skipped formats (L3) — parsed here so ic_local_variants reflects them immediately
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

        // Build ic_local_variants with CDN URLs — Modern Delivery renders from these directly,
        // so the user's frontend is optimal before Phase B even fires
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
                'local'        => false, // Phase B sets true after successful disk write
            ];
            if (!empty($service_skipped[$label])) $entry['skipped_formats'] = $service_skipped[$label];
            $variants[$label] = $entry;
        }

        // Acquire the SAME MySQL named lock that bg-swap callbacks use, AND merge
        // (not replace) inside the critical section. An earlier version had the lock but still did a
        // full-array replace, which lost bg-swap entries that had landed during Phase A's compute
        // window — small AVIF variants (thumbnail, medium) finish encoding fastest service-side
        // and their callbacks would race ahead of Phase A's write, then get overwritten when
        // Phase A finally took the lock and wrote its snapshot. Service-side telemetry showed
        // `swap_delivered_via_callback` records but plugin's ic_local_variants was missing the
        // entries — matched by `(new entry)` markers in the bg-swap log re-firing for the same
        // sizeLabel/format pair across cycles. The merge below preserves bg-swap entries
        // (anything with `bg_upgraded` set) and fills in Phase A's fresh inline entries elsewhere.
        // Lock acquisition timeout: 5s. If GET_LOCK fails, write anyway (degraded mode).
        global $wpdb;
        $phaseA_lock_name = 'wpc_bg_meta_' . (int) $imageID;
        // Instrumentation: Phase A merge timing splits. Captures lock-acquire wait,
        // critical-section duration, and post-merge meta writes separately so we
        // can pinpoint which sub-step dominates the wall on customer fleets with
        // heavy 3rd-party hooks.
        $instr_t_lock_start = microtime(true);
        $phaseA_got_lock = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $phaseA_lock_name));
        $phaseA_locked = ($phaseA_got_lock === '1' || $phaseA_got_lock === 1);
        $instr_lock_acq_ms = (int) round((microtime(true) - $instr_t_lock_start) * 1000);
        $instr_t_crit_start = microtime(true);
        $instr_merge_write_ms = 0;
        try {
            // Re-read inside the lock to capture any bg-swap entries that landed mid-flight.
            $existing_variants = get_post_meta($imageID, 'ic_local_variants', true);
            if (is_array($existing_variants) && !empty($existing_variants)) {
                foreach ($existing_variants as $existing_key => $existing_entry) {
                    // Bg-swap-delivered entries have `bg_upgraded` (unix ts) set. Preserve them
                    // unconditionally — they reflect post-Phase-A optimization (bg refinement
                    // or a new format Phase A doesn't produce inline like AVIF).
                    if (!empty($existing_entry['bg_upgraded'])) {
                        $variants[$existing_key] = $existing_entry;
                        continue;
                    }
                    // Pre-existing entry that Phase A didn't produce in this run AND wasn't
                    // bg-swap-touched — keep it. Rare; usually means an old variant key.
                    if (!isset($variants[$existing_key])) {
                        $variants[$existing_key] = $existing_entry;
                    }
                    // Otherwise: Phase A's freshly-encoded entry takes precedence.
                }
            }
            $instr_t_write_start = microtime(true);
            update_post_meta($imageID, 'ic_local_variants', $variants);
            $instr_merge_write_ms = (int) round((microtime(true) - $instr_t_write_start) * 1000);
        } finally {
            if ($phaseA_locked) {
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $phaseA_lock_name));
            }
        }
        $instr_crit_ms = (int) round((microtime(true) - $instr_t_crit_start) * 1000);

        // Instrumentation: post-merge status meta writes. Each fires `update_post_meta`
        // → option-write hooks. Cumulative time captured to compare against merge
        // overhead. (Splits show whether status writes are individually slow or
        // batched-DB-write-fast.)
        $instr_t_status_start = microtime(true);
        update_post_meta($imageID, 'wpc_images_compressed', 'true');
        update_post_meta($imageID, 'ic_status', 'compressed');
        update_post_meta($imageID, 'ic_compressing', ['status' => 'compressed']);
        update_post_meta($imageID, 'ic_stats', $stats);
        if (!empty($ai_meta)) update_post_meta($imageID, 'ic_ai_meta', $ai_meta);
        $instr_status_writes_ms = (int) round((microtime(true) - $instr_t_status_start) * 1000);

        // Emit consolidated instrumentation line. Service team + plugin team grep this to
        // find the actual hotspot to scope optimization work.
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

        // Use shared live-derive helper with canonical 4-tier
        // originalSize lookup. Card and modal share this logic so they always agree.
        $best = wpc_compute_best_savings($variants, $imageID);
        if ($best['pct'] > 0 && $best['orig'] > 0) {
            update_post_meta($imageID, 'ic_savings',          round($best['pct'], 1));
            update_post_meta($imageID, 'ic_savings_format',   $best['format']);
            update_post_meta($imageID, 'ic_savings_bytes',    $best['orig'] - $best['opt']);
            update_post_meta($imageID, 'ic_savings_baseline', $best['orig']);
        }

        // Clear in-progress transients + retry/dedup state (Phase 3 completion hook)
        delete_transient('wps_ic_compress_' . $imageID);
        delete_transient('wps_ic_queue_' . $imageID);
        delete_post_meta($imageID, '_wpc_optimize_attempts');
        delete_transient('wpc_queued_' . $imageID);
        delete_transient('wpc_failed_' . $imageID);
        if (function_exists('wp_cache_delete')) wp_cache_delete('wpc_queued_' . $imageID, 'wpc');
        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'compressed'], 60);

        // Compress success telemetry — Phase A duration (service round-trip only, not Phase B)
        if (function_exists('wpc_update_compress_stats')) {
            wpc_update_compress_stats([
                'event'       => 'success',
                'duration_ms' => (int) round((microtime(true) - $t_compress_start) * 1000),
                'source'      => $source,
            ]);
        }

        if (function_exists('wpc_invalidate_local_cache')) wpc_invalidate_local_cache();

        // ── PHASE B dispatch: 3-layer redundancy for shared hosting safety ──
        if (!empty($downloads)) {
            update_post_meta($imageID, '_wpc_pending_downloads', [
                'downloads'       => $downloads,
                'service_skipped' => $service_skipped,
                'scheduled_at'    => time(),
            ]);
            // Layer 1: non-blocking loopback POST (immediate, sub-second on most hosts)
            if (function_exists('wpc_fire_download_worker')) wpc_fire_download_worker($imageID);
            // Layer 2: WP Cron in 30s (fallback for Basic-Auth or loopback-blocked hosts)
            if (!wp_next_scheduled('wpc_download_variants', [$imageID])) {
                wp_schedule_single_event(time() + 30, 'wpc_download_variants', [$imageID]);
            }
            // Layer 3: admin_init scans pending meta every admin page view (registered globally)
        }

        if ($output == 'json') {
            wp_send_json_success();
        }
    }

    /**
     * Phase B: Download variant files from CDN to local disk.
     * Called asynchronously by wpc_download_variants_hook (cron + loopback + admin_init).
     *
     * Atomic writes via tmp + rename: POSIX-atomic, prevents half-written files on timeout/crash.
     * Size regression guard: skips files that would be ≥ the original on disk.
     * Updates ic_local_variants[*]['local'] = true for each file that lands successfully.
     *
     * @param int   $imageID
     * @param array $downloads        [['url' => ..., 'path' => ..., 'basename' => ...], ...]
     * @param array $service_skipped  [sizeLabel => [format, ...]] from post_body->skippedFormats (already stored in ic_local_variants, passed through for completeness)
     * @return array ['done' => bool, 'errors' => bool, 'skipped_variants' => [basenames...]]
     */
    public function downloadVariants($imageID, $downloads, $service_skipped = [])
    {
        $done = false; $errors = false; $skipped_variants = [];
        if (empty($downloads)) return compact('done', 'errors', 'skipped_variants');

        // Phase B runs on a separate PHP worker (loopback/cron/admin_init) that inherits defaults.
        // Bump limits defensively so shared hosts with 30s max_execution_time or 64M memory don't OOM
        // mid-download on 20+ variant POSTs.
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
                // Staging-only IP-direct dev pod (see comment at line ~3635).
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
                // Content-based validation. Determine format from filename, then
                // verify magic bytes + minimum size via wpc_is_valid_image_bytes. Replaces
                // the old filename-only check that allowed 678-byte sentinels through.
                $dl_fmt = 'jpeg';
                if (strpos($dl['basename'], '.avif') !== false) $dl_fmt = 'avif';
                elseif (strpos($dl['basename'], '.webp') !== false) $dl_fmt = 'webp';
                elseif (strpos($dl['basename'], '.png') !== false) $dl_fmt = 'png';
                if (!wpc_is_valid_image_bytes($file_data, $dl_fmt, $imageID, 'phase_b_download', ['size_label' => $dl['basename'] ?? '', 'url' => $dl['url'] ?? ''])) {
                    $errors = true; continue;
                }

                // Size regression guard
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
            // Fallback: sequential via wp_remote_get (same atomic-write pattern)
            foreach ($downloads as $dl) {
                $resp = wp_remote_get($dl['url'], ['timeout' => 20, 'sslverify' => false]);
                if (is_wp_error($resp)) { $errors = true; continue; }
                $file_data = wp_remote_retrieve_body($resp);
                if (empty($file_data)) { $errors = true; continue; }
                // Content-based validation (see parallel branch above)
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

        // Auto-fallback: parallel → sequential on total failure.
        // Counts actual files on disk (not $done flag which only reflects last success) to catch
        // the case where parallel returned errors on every URL. Broken curl_multi builds, WAF
        // rate-limiting, proxy servers single-connection-only all hit this branch and recover.
        $success_count = 0;
        foreach ($downloads as $dl) {
            if (file_exists($dl['path']) && filesize($dl['path']) > 0) $success_count++;
        }
        $used_parallel = function_exists('curl_multi_init');
        if ($used_parallel && $success_count === 0 && !empty($downloads)) {
            error_log('[WPC Download] image=' . $imageID . ' parallel totally failed — falling back to sequential');
            $done = false; $errors = false; $skipped_variants = []; // reset for sequential pass
            foreach ($downloads as $dl) {
                $resp = wp_remote_get($dl['url'], ['timeout' => 20, 'sslverify' => false]);
                if (is_wp_error($resp)) { $errors = true; continue; }
                $file_data = wp_remote_retrieve_body($resp);
                if (empty($file_data)) { $errors = true; continue; }
                // Content-based validation (see parallel branch above)
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

        // Update ic_local_variants under the bg-swap GET_LOCK. This
        // read-modify-write previously happened without the lock, racing against any bg-swap callback that
        // fired during the URL-download window. Same pattern as Phase A's lock+merge fix.
        global $wpdb;
        $dl_lock_name = 'wpc_bg_meta_' . (int) $imageID;
        $dl_got_lock = $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", $dl_lock_name));
        $dl_locked = ($dl_got_lock === '1' || $dl_got_lock === 1);
        try {
            // Re-read inside the lock — bg-swap may have updated entries since the URL-download
            // started. The local/skipped fields we set below are safe to overlay onto the latest.
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
                $wpdb->query($wpdb->prepare("SELECT RELEASE_LOCK(%s)", $dl_lock_name));
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

            /**
             * Figure out the actual file path
             */
            $file_path = get_attached_file($imageID);
            $file_basename = basename($image[0]);

            // Setup POST Params
            $headers = ['timeout' => 300, 'httpversion' => '1.0', 'blocking' => true,];

            // Figure out image type
            $exif = exif_imagetype($file_path);
            $mime = image_type_to_mime_type($exif);

            $file_location = WPS_IC_UPLOADS_DIR . '/' . $image['path'];

            // Fetch the image content
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

            // WebP File Path
            $webp_file_location = str_replace('.' . $extension, '.webp', $file_location);
            $call = wp_remote_post(self::$apiURL, ['timeout' => 300, 'method' => 'POST', 'headers' => $headers, 'sslverify' => false, 'body' => $post_fields, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($call) == 200) {
                $body = wp_remote_retrieve_body($call);
                // Content validation before write (was previously unchecked)
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

        // RE-ENTRANCY GUARD (ship-blocker for the resurrected sync-fallback path). The async
        // dispatcher's one-shot token guards DISPATCH, not EXECUTION OVERLAP: a worker can consume the token
        // (so the click handler's claim_async_token_for_sync() sees it gone and stands down) while it is still
        // mid-restore, and any OTHER concurrent entrant (a second restore click, a page-render re-trigger)
        // would otherwise enter here in parallel. The legacy-backup branch below is DESTRUCTIVE and NON-
        // idempotent — @copy($legacyPath,$targetPath); @unlink($legacyPath); delete_post_meta('ic_backup_images')
        // — so two overlapping restores corrupt: run B finds the backup unlinked by A, falls through to a
        // degraded reconstruction, and wipes the backup meta A may still rely on. wpc_restoring_<id> is
        // already SET below (and released in cleanRestoreMeta on every exit path); here we READ it on ENTRY
        // and bail if a restore is already in flight. This is the ONLY guard against the double-restore (this
        // function had no entry check — wpc_restoring_ was previously read only as a re-OPTIMIZE gate).
        // (Residual: the read→set window is a sub-ms TOCTOU, not a true mutex — but the Edit-2 token claim
        // already serializes the two token-bearing paths; this backstops non-token entrants. NOT a DB-lock.)
        if (get_transient('wpc_restoring_' . $imageID)) {
            error_log('[WPC Restore] RE-ENTRANCY skip image=' . $imageID . ' — a restore is already in flight (wpc_restoring_ set); standing down');
            return false;
        }

        // Reject any in-flight v2 Phase B callbacks for this image. If
        // a compress is mid-drain when the user hits Restore, late encoder
        // POSTs to /wpc/v2/bg_swap would otherwise re-write disk files +
        // re-merge ic_local_variants, causing "few images compressed" flicker
        // after restore. The marker transient is checked by v2-callback.php
        // and cleared on the next compress click (ajax.class.php). 10 min TTL
        // matches the asyncPending GC window — anything still in flight after
        // that is too stale to matter.
        set_transient('wpc_v2_callbacks_blocked_' . $imageID, time(), 600);
        // RESTORE-IN-FLIGHT micro-lock (user-caught race): a page render landing
        // MID-restore would re-trigger optimization against files being deleted this instant
        // — and a backfill compress starting mid-restore clears the restore marker while the
        // restore is still running. Seconds-scoped (60s TTL is the crash backstop); released
        // in cleanRestoreMeta on every exit path, so the post-restore re-trigger flow (~13s
        // to rebuilt avif) is untouched.
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
            return false;
        }

        // Skipped images — just clear metadata
        $skipped = get_post_meta($imageID, 'ic_skipped', true);
        if (!empty($skipped) && $skipped == 'true') {
            $this->cleanRestoreMeta($imageID);
            error_log('[WPC Restore] DONE image=' . $imageID . ' method=skipped time=' . round(microtime(true) - $t_total, 2) . 's');
            return true;
        }

        // Suppress on_upload hook during any regeneration in this function
        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);

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

        // ── PRIORITY 4: Cloud download from service ──────────────────
        if (!$restored) {
            $restored = $this->restore_from_cloud($imageID);
            if ($restored) {
                $restore_source = 'service';
                error_log('[WPC Restore] Restored from cloud image=' . $imageID);
            }
        }

        // ── PRIORITY 5: Safety net — regenerate from unscaled ────────
        if (!$restored) {
            $restored = $this->regenerate_from_unscaled($imageID);
            if ($restored) {
                $restore_source = 'service'; // treat unscaled-regen as service-less safety net
                error_log('[WPC Restore] Restored via regeneration image=' . $imageID);
            }
        }

        // Gate cleanup_backups on actual restore success. If we couldn't
        // verify a single byte-identical copy, the backup directory is the only
        // copy of the user's pristine pre-compress bytes — DO NOT delete it.
        if ($restored) {
            $this->cleanup_backups($imageID, $backupBase, $uploadDir);
        } else {
            error_log('[WPC Restore] BACKUP_RETAINED image=' . $imageID . ' — restore failed verification; backups NOT deleted (so user can retry or manual-recover)');
        }

        // ALWAYS clean metadata — never leave image stuck in "restoring" UI state.
        // cleanRestoreMeta unconditionally writes ic_status='restored' at the bottom;
        // we override below if verification actually failed.
        $this->cleanRestoreMeta($imageID);

        // Override the optimistic ic_status='restored' write inside
        // cleanRestoreMeta when verify_restore_atomic failed across all paths.
        // Pre-fix: ic_status='restored' even when on-disk bytes were still
        // compressed (observed on staging images 113, 122 on 2026-05-09).
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

        // Phase B dispatch: if thumbnail regen was deferred, fire the async worker.
        // Same 3-layer dispatch as Phase B download: loopback + WP Cron + admin_init fallback.
        // Bulk-aware deferral: during bulk-restore, skip the per-image loopback
        // (would be 500+ no-op HTTP round-trips with cap=1). Bulk-finish hook fires ONE
        // chain kickoff that self-chains through all pending regens at cap=1.
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

        // Return actual restore outcome so callers can react. This previously
        // returned `true` unconditionally regardless of restore status. No current
        // caller uses the return value (verified via grep), but a true value here
        // misrepresents partial-failure state to any future consumer or test.

        // HTML cache invalidation on restore. Variants were just
        // deleted from disk + ic_local_variants cleared. Any page HTML
        // referencing this attachment is now stale — it points at natural
        // URLs for variants that no longer exist (would 404). Fire the
        // standard purge cascade so the next render emits CDN transform
        // URLs (which never 404) until variants are re-encoded.
        if (function_exists('wpc_v2_purge_html_for_attachment')) {
            wpc_v2_purge_html_for_attachment((int) $imageID, 'restoreV4');
        }

        // Clear lazy_cdn sha256 dedup transients tied to variants
        // that just got deleted. Without this, the 10-min dedup TTL would
        // cause subsequent orch encodes (same bytes → same sha256) to be
        // silently acked WITHOUT writing the file back to disk. Customer
        // sees: "restored, retried, nothing landed."
        //
        // The dedup transient key format is wpc_v2_dedup_<sha256_pfx_16>.
        // We don't know which sha256 prefixes belonged to THIS image (we
        // never stored that mapping), so we use the broad "clear all
        // wpc_v2_dedup_*" wp_options sweep. Cheap (transients table small)
        // and surgical-enough: collateral damage is forcing one re-ingest
        // for any UNRELATED variant whose dedup transient happens to be
        // active — harmless, just costs one re-download which is exactly
        // what the dedup was preventing.
        if (function_exists('wpc_v2_lazy_cdn_clear_dedup_transients')) {
            wpc_v2_lazy_cdn_clear_dedup_transients();
        }

        // Per-image CDN edge purge on restore.
        //
        // DEFAULT FLIPPED TO FALSE (disabled). The comment below claimed
        // "sub-100ms total wall" — that was wrong. wpc_purge_cdn_urls() fans out
        // ~1,000-1,500 per-URL requests (the transform-ladder enumeration added in
        // v7.08.8/14: ~25 widths × 3 formats × 2 hosts × ~10 URLs); even non-blocking,
        // each does DNS/TCP/TLS setup, so the loop is ~50s wall. Worse, restoreV4 ran
        // it TWICE (here + cleanRestoreMeta) = ~100s/image — the entire reason restore
        // took 2+ min (debug.log 2026-05-29: restoreV4 wall ~103s/image, slice
        // processed=1 wall=104.59s). The per-load `?v=` cache-buster on lazy CDN URLs
        // now forces the edge to re-validate against origin, so the hard purge is
        // redundant for lazy_cdn sites. Disabled by default; re-enable via the
        // 'wpc_restore_purge_cdn' filter (and if you do, route it through a deferred/
        // async loopback or a single wildcard purge — never this inline fan-out).
        if (apply_filters('wpc_restore_purge_cdn', false, (int) $imageID)
            && function_exists('wpc_purge_cdn_urls')) {
            wpc_purge_cdn_urls((int) $imageID);
            if (function_exists('wpc_diagnostic_log')) {
                wpc_diagnostic_log('RESTORE_CDN_PURGE', 'fired for image_id=' . (int) $imageID);
            }
        }

        return $restored;
    }

    // ─── Restore sub-functions ──────────────────────────────────────

    /**
     * Atomic verify-after-copy for restore.
     * Before this helper, restore used `@copy($src, $dest); $filesCopied++` —
     * incrementing on @copy's return value (which only checks "did the OS accept
     * my call", not whether the bytes actually arrived intact). Two staging images
     * (113, 122) ended up in a "ghost-restored" state: ic_status='restored' but
     * live disk bytes were still compressed (~470KB instead of the 1MB backup).
     * The exact mode (silent IO truncation, race with another restore, race with
     * a deferred-thumb-regen worker writing back the parent) is unconfirmed —
     * this helper closes ALL of them by verifying byte-identity via SHA-256
     * before declaring the copy successful.
     *
     * Pattern: write to `$dest.wpc_restore_tmp_RANDOM`, size-check, SHA-check,
     * atomic rename to final dest. Retries up to 3 attempts on transient failure.
     *
     * @return bool true ONLY when the file at $dest is byte-identical to $src;
     *              false when verification failed across all retries.
     */
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

        // Recover unscaled path when WP metadata lost the `original_image`
        // key. Once the key is missing, wp_get_original_image_path() falls back to
        // get_attached_file() — same path as $scaled. The downstream
        // `if ($scaled && $scaled !== $unscaled)` guard then skips the scaled block,
        // so EITHER the parent OR the scaled gets restored, never both. This is the
        // most likely on-staging cause of the ghost-restored state on images 113/122
        // observed 2026-05-09: ic_status='restored' but parent live bytes still
        // compressed because only the -scaled was actually copied.
        //
        // Fix: if $unscaled === $scaled and $scaled has a -scaled.<ext> suffix,
        // derive the parent path by stripping the suffix. Verify the derived file
        // (or its backup) actually exists before substituting — otherwise leave the
        // existing fallback path alone.
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

        // verify_restore_atomic gates each copy on byte-identical SHA match.
        // Was: `if (file_exists($src)) { @copy($src, $dest); $filesCopied++; }` —
        // incremented unconditionally on @copy's return, which only confirmed the
        // OS call started, not that the bytes arrived intact (cause of staging
        // ghost-restore where ic_status='restored' but live bytes were compressed).
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
                // Defer thumbnail regen to async worker. wp_generate_attachment_metadata is
                // CPU-bound (5-15s on large images with 6-8 size variants). Moving it out of the
                // restore AJAX drops user-visible restore time from ~15s → ~1-2s. Tradeoff: WP admin
                // media library previews show stale (old optimized) thumbnails for ~10-15s until the
                // async worker fires. Frontend pages are unaffected (don't use local thumbnails).
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

        // verify_restore_atomic gates each copy on byte-identical SHA match.
        // _bkp paths are local sibling files (atomic-rename to original name); same
        // verification rationale as restore_from_new_backup.
        // Find and restore all _bkp files for this image
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

        // Collect URLs by label
        $byLabel = [];
        foreach ($data['backupUrls'] as $b) {
            $byLabel[$b['sizeLabel']] = $b['fileUrl'];
        }

        // Try in priority order
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

            // Route cloud bytes through verify_restore_atomic (size + SHA
            // match + atomic rename). Catches corruption between download_url's tmp
            // and the live destination. Same helper used in PRIORITY 1/3, so failure
            // modes are consistent in debug.log.
            if ($label === 'unscaled' && $hasScaled) {
                // Restore unscaled original, regenerate scaled + thumbnails
                $ok = $this->verify_restore_atomic($tmp, $unscaledPath, $imageID, 'cloud_unscaled');
                @unlink($tmp);
                if (!$ok) {
                    error_log('[WPC Restore] Cloud verify failed image=' . $imageID . ' label=unscaled — trying next');
                    continue;
                }
                // Defensive on_upload guard. restoreV4 removes this filter at entry,
                // but making the subfn self-contained protects against future refactors that
                // might call this from a path that didn't pre-remove the hook → double-compress race.
                remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                add_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX, 2);
                if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
            } else {
                // Restore directly to the attached file path
                $ok = $this->verify_restore_atomic($tmp, $scaledPath, $imageID, 'cloud_' . $label);
                @unlink($tmp);
                if (!$ok) {
                    error_log('[WPC Restore] Cloud verify failed image=' . $imageID . ' label=' . $label . ' — trying next');
                    continue;
                }
            }

            return true;
        }

        // Last resort: try first available URL regardless of label
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
            // DEFER the regen (user-caught: bulk restore froze 3+ min on one
            // PNG). Every other restore path defers thumb regen to the async worker; this
            // PRIORITY-5 safety net was the last INLINE wp_generate_attachment_metadata —
            // minutes of CPU for a large PNG with 6-8 sub-sizes, executed inside the bulk
            // drain slice, freezing the whole queue. Same deferral contract as the other
            // paths: restoreV4's tail dispatch fires the worker, the card polls
            // is-regen-pending until done. One slow image can no longer stall the chain.
            update_post_meta($imageID, '_wpc_pending_thumb_regen', [
                'regen_source' => $unscaled,
                'backup_mode'  => 'unscaled-safety-net',
                'scheduled_at' => time(),
            ]);
            error_log('[WPC Restore] Priority-5 regen deferred to async worker image=' . $imageID);
            return true;
        }

        // If unscaled == scaled and file exists, it's just a small image — nothing to regenerate
        if ($scaled && file_exists($scaled)) return true;

        return false;
    }

    private function cleanup_backups($imageID, $backupBase, $uploadDir) {
        // Preserve master backups across restore cycles. This function previously
        // deleted ALL wpc-backups/ entries on every restore — masters AND sub-size thumbnails.
        // That broke recovery for images whose late bg-swap callbacks or lazy-fill writes
        // arrived AFTER the restore (paths that destructively overwrite without first calling
        // backup_all_sizes). The pristine bytes were lost and the next restore had nothing to
        // pull from, returning the corrupted disk state instead.
        //
        // Fix: only delete SUB-SIZE backups (those regenerate from masters via
        // wp_create_image_subsizes during regen-thumbs). Masters (no-suffix unscaled +
        // -scaled.jpg) stay forever — the canonical recovery source for the lifetime of the
        // attachment. Storage cost is ~1MB per image, acceptable for the safety guarantee.
        // wpc_backup_path post_meta also stays so restore Priority 1 keeps working.
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

    // ─── Dead legacy code below was removed in rebuild ──────────────
    // olderBackup(), old restoreV4 tail, old restore() — all replaced by
    // restoreV4() with sub-functions above.
    // Legacy ic_backup_images meta is handled in restoreV4 Priority 2.

    /* START DEAD CODE — kept for reference only
        if (is_wp_error($response)) {
            error_log('[WPC Restore] Service error image=' . $imageID . ' err=' . $response->get_error_message() . ' time=' . $svcTime . 's');
            $this->cleanRestoreMeta($imageID);
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        $backupCount = !empty($data['backupUrls']) ? count($data['backupUrls']) : 0;
        error_log('[WPC Restore] Service response image=' . $imageID . ' backups=' . $backupCount . ' time=' . $svcTime . 's');

        if (empty($data['backupUrls'])) {
            $this->cleanRestoreMeta($imageID);
            error_log('[WPC Restore] Done image=' . $imageID . ' method=no_backups time=' . round(microtime(true) - $t_total, 2) . 's');
            return true;
        }

        // Try local _bkp backup FIRST (most reliable — was copied before overwrite)
        $restored = false;
        $scaledPath = get_attached_file($imageID);
        $unscaledPath = $scaledPath ? str_replace('-scaled.', '.', $scaledPath) : '';
        $hasScaled = ($unscaledPath !== $scaledPath);

        $localBkpPaths = [];
        if ($hasScaled && $unscaledPath) $localBkpPaths[] = $unscaledPath . '_bkp';
        if ($scaledPath) $localBkpPaths[] = $scaledPath . '_bkp';

        foreach ($localBkpPaths as $bkpPath) {
            if ($bkpPath && file_exists($bkpPath) && filesize($bkpPath) > 0) {
                $targetPath = str_replace('_bkp', '', $bkpPath);
                if (@copy($bkpPath, $targetPath)) {
                    @unlink($bkpPath);

                    $isUnscaled = ($hasScaled && strpos($targetPath, '-scaled.') === false);
                    if ($isUnscaled) {
                        $t_regen = microtime(true);
                        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                        $newMeta = wp_generate_attachment_metadata($imageID, $targetPath);
                        if ($newMeta) {
                            wp_update_attachment_metadata($imageID, $newMeta);
                        }
                        error_log('[WPC Restore] Regenerated thumbnails image=' . $imageID . ' time=' . round(microtime(true) - $t_regen, 2) . 's');
                    }

                    error_log('[WPC Restore] Local _bkp restored image=' . $imageID . ' path=' . basename($bkpPath));
                    $restored = true;
                    break;
                }
            }
        }

        // Fallback: download from service if no local backup
        if (!$restored) {
        // Collect all available backup URLs by label
        $backupsByLabel = [];
        foreach ($data['backupUrls'] as $backupFile) {
            $backupsByLabel[$backupFile['sizeLabel']] = $backupFile['fileUrl'];
        }

        // Try in priority order: unscaled → original → scaled → any first URL
        $tryOrder = ['unscaled', 'original', 'scaled'];
        $restoreUrl = null;
        $restoreLabel = null;
        foreach ($tryOrder as $label) {
            if (!empty($backupsByLabel[$label])) {
                $restoreUrl = $backupsByLabel[$label];
                $restoreLabel = $label;
                break;
            }
        }
        if (!$restoreUrl && !empty($data['backupUrls'][0]['fileUrl'])) {
            $restoreUrl = $data['backupUrls'][0]['fileUrl'];
            $restoreLabel = $data['backupUrls'][0]['sizeLabel'] ?? 'unknown';
        }

        if ($restoreUrl) {
            error_log('[WPC Restore] Downloading from cloud image=' . $imageID . ' label=' . $restoreLabel);
            $t_dl = microtime(true);
            $tmp = download_url($restoreUrl, 60);
            $dlTime = round(microtime(true) - $t_dl, 2);

            if (!is_wp_error($tmp)) {
                error_log('[WPC Restore] Cloud download image=' . $imageID . ' time=' . $dlTime . 's size=' . filesize($tmp));
                if ($restoreLabel === 'unscaled') {
                    $unscaledPath = $hasScaled ? str_replace('-scaled.', '.', $scaledPath) : $scaledPath;
                    copy($tmp, $unscaledPath);
                    @unlink($tmp);

                    $t_regen = microtime(true);
                    remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                    $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                    if ($newMeta) {
                        wp_update_attachment_metadata($imageID, $newMeta);
                    }
                    error_log('[WPC Restore] Regenerated from unscaled image=' . $imageID . ' time=' . round(microtime(true) - $t_regen, 2) . 's');
                } else {
                    if ($scaledPath) {
                        copy($tmp, $scaledPath);
                    }
                    @unlink($tmp);
                    error_log('[WPC Restore] Replaced scaled file image=' . $imageID);
                }

                if ($scaledPath && file_exists($scaledPath . '_bkp')) {
                    @unlink($scaledPath . '_bkp');
                }

                $restored = true;
            } else {
                error_log('[WPC Restore] Cloud download FAILED image=' . $imageID . ' label=' . $restoreLabel . ' err=' . $tmp->get_error_message() . ' time=' . $dlTime . 's');

                // Retry with next available label
                foreach ($tryOrder as $retryLabel) {
                    if ($retryLabel === $restoreLabel) continue;
                    if (empty($backupsByLabel[$retryLabel])) continue;

                    error_log('[WPC Restore] Retrying cloud download image=' . $imageID . ' label=' . $retryLabel);
                    $t_dl2 = microtime(true);
                    $tmp2 = download_url($backupsByLabel[$retryLabel], 60);

                    if (!is_wp_error($tmp2)) {
                        error_log('[WPC Restore] Retry succeeded image=' . $imageID . ' label=' . $retryLabel . ' time=' . round(microtime(true) - $t_dl2, 2) . 's size=' . filesize($tmp2));
                        if ($retryLabel === 'unscaled') {
                            $unscaledPath = $hasScaled ? str_replace('-scaled.', '.', $scaledPath) : $scaledPath;
                            copy($tmp2, $unscaledPath);
                            @unlink($tmp2);
                            remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                            $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                            if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                        } else {
                            copy($tmp2, $scaledPath);
                            @unlink($tmp2);
                        }
                        $restored = true;
                        break;
                    } else {
                        error_log('[WPC Restore] Retry also failed image=' . $imageID . ' label=' . $retryLabel . ' err=' . $tmp2->get_error_message());
                    }
                }
            }
        }
        } // end if (!$restored) — service fallback

        // Final safety net: if the main attached file STILL doesn't exist on disk
        $attachedFile = get_attached_file($imageID);
        if ($attachedFile && !file_exists($attachedFile)) {
            $unscaledPath = str_replace('-scaled.', '.', $attachedFile);
            if ($unscaledPath !== $attachedFile && file_exists($unscaledPath)) {
                error_log('[WPC Restore] Safety net: regenerating from unscaled image=' . $imageID);
                $t_regen = microtime(true);
                remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                if ($newMeta) {
                    wp_update_attachment_metadata($imageID, $newMeta);
                }
                error_log('[WPC Restore] Safety net regen image=' . $imageID . ' time=' . round(microtime(true) - $t_regen, 2) . 's');
                $restored = true;
            } else {
                error_log('[WPC Restore] Safety net: no unscaled found image=' . $imageID . ' file_missing=' . basename($attachedFile));
            }
        }

        // Restore any thumbnail _bkp files that belong to this image
        $attachedFile = get_attached_file($imageID);
        if ($attachedFile) {
            $dir = dirname($attachedFile);
            $imgBase = pathinfo(wp_get_original_image_path($imageID) ?: $attachedFile, PATHINFO_FILENAME);
            $bkpCount = 0;
            foreach (glob($dir . '/' . $imgBase . '*_bkp.*') as $bkpFile) {
                $original = str_replace('_bkp.', '.', $bkpFile);
                $bkpCount++;
                @copy($bkpFile, $original);
                @unlink($bkpFile);
            }
            if ($bkpCount > 0) {
                error_log('[WPC Restore] Restored ' . $bkpCount . ' thumbnail _bkp files image=' . $imageID);
            }
        }

        // Verify file exists after restore
        clearstatcache(true);
        $finalFile = get_attached_file($imageID);
        $finalExists = ($finalFile && file_exists($finalFile)) ? filesize($finalFile) : 'MISSING';
        $method = $restored ? 'local_bkp_or_cloud' : 'cleanup_only';

        // Clean metadata, variants, caches
        $this->cleanRestoreMeta($imageID);

        error_log('[WPC Restore] DONE image=' . $imageID . ' method=' . $method . ' file_size=' . $finalExists . ' total_time=' . round(microtime(true) - $t_total, 2) . 's');

        return true;


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

                    if (!empty($response['data']['imageURL'])) {
                        $imageUrl = $response['data']['imageURL'];
                        $imagePath = wp_get_original_image_path($imageID);

                        $downloadImage = download_url($imageUrl);

                        if (is_wp_error($downloadImage)) {
                            $this->writeLog('Unable to download Image');
                            $this->writeLog($imageUrl);
                            $this->writeLog($downloadImage);

                            $this->writeLog('Ended Image ID - failed to get backup ' . $imageID);

                            if ($output == 'json') {
                                wp_send_json_error(['msg' => 'failed-to-get-backup', 'apiUrl' => self::$apiURL, 'apikey' => self::$apiParams['apikey'], 'imageID' => $imageID, 'url' => $downloadImage]);
                            }

                            return false;
                        }

//                        $file_info = finfo_open(FILEINFO_MIME_TYPE);
//                        $mime_type = finfo_file($file_info, $downloadImage);
//                        finfo_close($file_info);

                        //$mime_type = mime_content_type($downloadImage);

                        // Verify if the downloaded file is an image
                        if (function_exists('mime_content_type')) {
                            $mime_type = mime_content_type($downloadImage);
                        } else if (function_exists('finfo_open')) {
                            $file_info = finfo_open(FILEINFO_MIME_TYPE);
                            $mime_type = finfo_file($file_info, $downloadImage);
                            finfo_close($file_info);
                        } else {
                            $mime_type = wp_get_image_mime($downloadImage);
                        }

                        if (in_array($mime_type, ['image/jpeg', 'image/png', 'image/gif'])) {
                            $imageSize = getimagesize($downloadImage);
                            if ($imageSize !== false) {

                                if (file_exists($imagePath)) {
                                    unlink($imagePath);
                                }

                                copy($downloadImage, $imagePath);
                                unset($downloadImage);

                                // Delete webP if exists
                                $imagesCompressed = get_post_meta($imageID, 'wpc_images_compressed', true);
                                foreach ($imagesCompressed as $image => $data) {
                                    if (file_exists($data['webp_path'])) {
                                        unlink($data['webp_path']);
                                    }
                                }


                                // Remove meta tags
                                delete_post_meta($imageID, 'wpc_images_compressed');
                                delete_post_meta($imageID, 'ic_stats');
                                delete_post_meta($imageID, 'ic_compressed_images');
                                delete_post_meta($imageID, 'ic_compressed_thumbs');
                                delete_post_meta($imageID, 'ic_backup_images');
                                update_post_meta($imageID, 'ic_status', 'restored');
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); } // async CDN purge (see v2-customer-purge.php)
                                delete_post_meta($imageID, 'ic_bulk_running');
                                delete_transient('wps_ic_compress_' . $imageID);

                                $originalFilePath = wp_get_original_image_path($imageID);
                                remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);

                                $oldMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);
                                wp_update_attachment_metadata($imageID, $oldMeta);
                                // Add for heartbeat to pickup
                                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                                $this->writeLog('Ended Image ID - restored ' . $imageID);

                                if ($output == 'json') {
                                    wp_send_json_success(['msg' => 'backup-restored']);
                                }
                            }
                            wp_send_json_error(['msg' => 'invalid-backup']);
                        }
                        wp_send_json_error(['msg' => 'invalid-backup']);
                    }
                }
            }
        } else {
            $this->writeLog('API Response not 200');
            $this->writeLog(print_r(wp_remote_retrieve_body($call), true));
            $this->writeLog('Ended Image ID ' . $imageID);

    END DEAD CODE */

    /**
     * Clean all optimization metadata and variants. Used by every restore exit path.
     * Guarantees the image is never stuck in a compressed/optimizing state.
     */
    private function cleanRestoreMeta($imageID) {
        // Restore finished: release the in-flight micro-lock (every exit path
        // runs through here). The next render may re-trigger immediately.
        delete_transient('wpc_restoring_' . $imageID);

        // RESTORE UNLOCKS DEMAND (user design call): inventory reset means the
        // demand throttles reset too — the per-width 15-min trigger guards and format-fill
        // sigs exist to dedupe repeat work on EXISTING inventory; after a restore there is
        // no inventory, so the very next page view must be able to re-trigger immediately
        // (demos, and honest UX). The parent-overwrite guard (pull lane) and the bg/journal
        // restored_reject keep protecting the base files independently of this.
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
            foreach (glob($dir . '/' . $baseName . '*.webp') as $webp) { @unlink($webp); }
            foreach (glob($dir . '/' . $baseName . '*.avif') as $avif) { @unlink($avif); }
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

        // Modern Image Delivery trigger-state cleanup
        delete_post_meta($imageID, '_wpc_optimize_attempts');
        delete_transient('wpc_queued_' . $imageID);
        delete_transient('wpc_failed_' . $imageID);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('wpc_queued_' . $imageID, 'wpc');
        }
        // Remove from in-flight queue option
        $queue = get_option('wpc_compress_queue', []);
        if (is_array($queue) && in_array($imageID, $queue)) {
            $queue = array_values(array_diff($queue, [$imageID]));
            update_option('wpc_compress_queue', $queue, false);
        }

        // Ladder backfill state cleanup (parallel to compress-queue cleanup above).
        // Without this, after restore: the 30-min dedup transient blocks re-queue, the retry counter
        // keeps last attempt history, and the pending queue entry (if any) would re-trigger a backfill
        // for files the customer just deleted.
        delete_post_meta($imageID, '_wpc_ladder_attempts');
        delete_transient('wpc_failed_ladder_' . $imageID);
        delete_transient('wpc_ladder_queued_' . $imageID);
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('wpc_ladder_queued_' . $imageID, 'wpc');
        }

        // Lazy first-view trigger lock (10-min TTL set in wpc_lazy_trigger_v2).
        // Without this, after restore the lock keeps silent-bailing any new lazy
        // first-view emission for the same image until TTL expires — the customer
        // restores, reloads the page, and the image just sits at "uncompressed".
        delete_transient('wpc_lazy_v2_trigger_' . $imageID);

        // Per-image in-flight lock set by run_v2_optimize. Normally
        // released in run_v2_optimize's finally{}; clear here too so a
        // crashed worker that left the lock held doesn't prevent the next
        // compress click after restore.
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

        // DO NOT clear _wpc_pending_thumb_regen here. cleanRestoreMeta runs at the end
        // of restoreV4, which has just set this meta. The regen worker reads it after restoreV4
        // returns. Clearing here would prevent the worker from running.
        //
        // Instead, the race between restore-then-compress is handled by the worker's race-guard:
        // if ic_status !== 'restored' when the worker fires, it aborts and cleans up. See
        // wpc_regen_thumbs_hook for the guard logic.

        // Clear any in-flight lazy-backfill locks for this image. Without
        // this they hold for the full BACKFILL_LOCK_TTL_DEFAULT (60-600s) after
        // restore, blocking the next compress click (or first front-end emission)
        // from re-encoding the same widths. The locks themselves are no-ops once
        // restore has run (callbacks are blocked by wpc_v2_callbacks_blocked_*),
        // but they would silently suppress subsequent re-kicks until TTL.
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
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); } // async CDN purge (see v2-customer-purge.php)
        // Stamp the restore-completion timestamp alongside ic_status. The bg-swap
        // callback handler compares this against `_wpc_compress_started_at` (or the
        // service-supplied compressStartedAt) to distinguish stale callbacks from a pre-restore
        // compress generation (reject 410) from fresh callbacks belonging to a still-running
        // post-restore compress (accept). Replaces the simple ic_status === 'restored' check.
        update_post_meta($imageID, '_wpc_restore_completed_at', time());

        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

        if (function_exists('wpc_invalidate_local_cache')) { wpc_invalidate_local_cache(); }

        // Customer Purge v1 — the unified CDN purge is fired by the restore
        // CALLERS on SUCCESS only (wps_ic_restore_live sync fallthrough +
        // wpc_async_restore_regen worker for single; wpc_bulk_v2_restore_drain
        // for bulk), all fire-and-forget. Deliberately NOT here: cleanRestoreMeta
        // also runs on FAILED restores (ic_status overridden to restore_failed
        // by restoreV4 after this returns) and is shared by every exit path, so
        // purging here would clear caches for content that didn't actually revert.

        // CDN purge REMOVED from the restore path. wpc_purge_cdn_urls()
        // fanned out ~1,000-1,500 sequential cache-purge requests (transform ladder:
        // ~25 widths × 3 formats × 2 hosts × ~10 URLs); even non-blocking, the per-call
        // DNS/TCP/TLS setup made it cost ~50s wall PER IMAGE, run synchronously here —
        // the entire reason restore took 2+ min for a few images (confirmed debug.log
        // 2026-05-29: restoreV4 wall 50-53s/image, all in this purge). The per-load `?v=`
        // cache-buster on lazy CDN URLs now forces the edge to re-validate against origin,
        // so a hard purge on restore is redundant for lazy_cdn sites. Removed for now.
        //   NOTE: the `?v=` buster only applies when CDN lazy is ON. If a NON-lazy CDN
        //   path is ever reintroduced, restore on those sites would need cache
        //   invalidation again (ideally a single wildcard purge, not this 1,500-request
        //   fan-out). Re-add via a deferred/async loopback if so — never inline.
    }

    public function olderBackup($imageID)
    {
        $backup_images = get_post_meta($imageID, 'ic_backup_images', true);

        if (!empty($backup_images) && is_array($backup_images)) {
            $compressed_images = get_post_meta($imageID, 'ic_compressed_images', true);

            // Remove Generated Images
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

            // Restore only full (backups stored as 'original' key, legacy used 'full')
            $restore_image_path = isset($backup_images['original']) ? $backup_images['original'] : (isset($backup_images['full']) ? $backup_images['full'] : '');

            // Also check for _bkp file as alternative source
            $scaledBkp = $scaledPath . '_bkp';
            $inlineBkp = preg_replace('/\.(jpe?g|png|gif)$/i', '_bkp.$1', $scaledPath);

            if (!empty($restore_image_path) && file_exists($restore_image_path)) {
                // Backup directory restore
                if ($hasScaledVersion) {
                    // Has separate unscaled — restore to unscaled, regen scaled
                    @copy($restore_image_path, $unscaledPath);
                    if (file_exists($scaledPath)) @unlink($scaledPath);
                    remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                    $newMeta = wp_generate_attachment_metadata($imageID, $unscaledPath);
                    if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                } else {
                    // No separate unscaled — restore directly to the attached file path
                    @copy($restore_image_path, $scaledPath);
                    remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                    $newMeta = wp_generate_attachment_metadata($imageID, $scaledPath);
                    if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);
                }
                @unlink($restore_image_path);
            } elseif (file_exists($inlineBkp)) {
                // _bkp inline file restore (e.g. photo-scaled_bkp.jpg)
                @copy($inlineBkp, $scaledPath);
                @unlink($inlineBkp);
                remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                $newMeta = wp_generate_attachment_metadata($imageID, $hasScaledVersion ? $unscaledPath : $scaledPath);
                if ($newMeta) wp_update_attachment_metadata($imageID, $newMeta);

                clearstatcache();

                // Remove all compression meta
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
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); } // async CDN purge (see v2-customer-purge.php)
                set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                error_log('[WPC Restore] olderBackup succeeded image=' . $imageID);
                return true;
            }

            // Backup file missing — clean up the stale meta and fall through to newer restore logic
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

        // Is the image in process
        $inProcess = get_post_meta($imageID, 'ic_bulk_running', true);
        if ($inProcess && $inProcess == 'true') {
        }

        // Remote backup?

        //check api for original
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
                                wp_send_json_error(['msg' => 'failed-to-get-backup', 'apiUrl' => self::$apiURL, 'apikey' => self::$apiParams['apikey'], 'imageID' => $imageID, 'url' => $downloadImage]);
                            }

                            return false;
                        }

                        if (file_exists($imagePath)) {
                            unlink($imagePath);
                        }

                        copy($downloadImage, $imagePath);
                        unset($downloadImage);


                        // Remove meta tags
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
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); } // async CDN purge (see v2-customer-purge.php)
                        delete_post_meta($imageID, 'ic_bulk_running');
                        delete_transient('wps_ic_compress_' . $imageID);

                        $originalFilePath = wp_get_original_image_path($imageID);
                        remove_filter('wp_generate_attachment_metadata', [$this, 'on_upload'], PHP_INT_MAX);
                        $oldMeta = wp_generate_attachment_metadata($imageID, $originalFilePath);
                        wp_update_attachment_metadata($imageID, $oldMeta);

                        // Add for heartbeat to pickup
                        set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                        $this->writeLog('Ended Image ID - restored ' . $imageID);

                        if ($output == 'json') {
                            wp_send_json_success(['msg' => 'backup-restored']);
                        }

                        return true;
                    }

                    foreach ($response['data']['url'] as $imageSize => $imageUrl) {

                        $imageUrl = $imageUrl['s3'];

                        // Image URL was already restored
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
                            //
                            $imagePath = wp_get_attachment_image_src($imageID, $imageSize);
                            $imagePath = wp_basename($imagePath[0]);
                            $imagePath = $this->pathToDir . $imagePath;
                        }

                        // Local Filename
                        $localFilename = wp_basename($imagePath);

                        // Filename from API
                        $sentFilename = wp_basename($imageUrl);
                        $sentFilename = explode('?', $sentFilename);
                        $sentFilename = $sentFilename[0];

                        if ($sentFilename !== $localFilename) {
                            // Filename not matching?! Error!
                            $sentFilename = explode('-', $sentFilename);
                            $removed = array_shift($sentFilename);
                            $sentFilename = implode('-', $sentFilename);
                        }

                        if ($sentFilename !== $localFilename) {
                            // Still not a match
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

                            // Delete webP if exists
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

                    // Remove meta tags
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
            if (function_exists('wpc_restore_cdn_purge')) { wpc_restore_cdn_purge($imageID); } // async CDN purge (see v2-customer-purge.php)
                    delete_post_meta($imageID, 'ic_bulk_running');
                    delete_transient('wps_ic_compress_' . $imageID);

                    // Add for heartbeat to pickup
                    set_transient('wps_ic_heartbeat_' . $imageID, ['imageID' => $imageID, 'status' => 'restored'], 60);

                    $this->writeLog('Ended Image ID - restored ' . $imageID);

                    if ($output == 'json') {
                        wp_send_json_success(['msg' => 'backup-restored']);
                    }
                }
            } else {
                $this->writeLog('Ended Image ID - failed to get backup ' . $imageID);
                if ($output == 'json') {
                    wp_send_json_error(['msg' => 'failed-to-get-backup', 'apiUrl' => self::$apiURL, 'apikey' => self::$apiParams['apikey'], 'imageID' => $imageID]);
                }
            }

        } else {
            $this->writeLog('API Response not 200');
            $this->writeLog(print_r(wp_remote_retrieve_body($call), true));
            $this->writeLog('Ended Image ID ' . $imageID);

            // Failure to contact API
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

        // Is the image type supported
        if (!$this->is_supported($imageID)) {
            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-not-supported']);
            } else {
                return 'file-not-supported';
            }
        }

        // Is the image already Compressed
        if ($this->is_already_compressed($imageID)) {
            $media_library = new wps_ic_media_library_live();
            $html = $media_library->compress_details($imageID);

            if ($output == 'json') {
                wp_send_json_error(['msg' => 'file-already-compressed', 'imageID' => $imageID, 'html' => $html]);
            } else {
                return 'file-already-compressed';
            }
        }

        // Set the image status
        set_transient('wps_ic_compress_' . $imageID, ['imageID' => $imageID, 'status' => 'compressing'], 60);

        // Save OLD post meta for restore usage
        if (!get_post_meta($imageID, 'wpc_old_meta')) {
            $oldMeta = wp_get_attachment_metadata($imageID);
            update_post_meta($imageID, 'wpc_old_meta', $oldMeta);
        }

        // Prepare the request params
        $post_fields = ['action' => 'queueSingleImage', 'imageID' => $imageID, 'siteUrl' => self::$siteUrl, 'apikey' => self::$apiParams['apikey'], 'parameters' => ['maxWidth' => WPS_IC_MAXWIDTH, 'quality' => self::$apiParams['quality'], 'retina' => self::$apiParams['retina'], 'webp' => self::$apiParams['webp']],];

        // Notify API to queue to queue the request
        $notify = wp_remote_post(self::$apiURL . 'queueManager.php', ['timeout' => 60, 'method' => 'POST', 'sslverify' => false, 'body' => $post_fields, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($notify) == 200) {
            // All good, let's wait for queue
            wp_send_json_success('waiting-queue');
        } else {
            delete_transient('wps_ic_compress_' . $imageID);
            // We were unable to contact API
            wp_send_json_error(['msg' => 'unable-to-contact-api']);
        }
    }

    public function compress_image($imageID, $bulk = true, $retina = true, $webp = true, $just_thumbs = false, $regenerate = true, $output = 'json')
    {
        global $wpc_filesystem;
        wp_raise_memory_limit('image');

        $bulkStats = get_transient('wps_ic_bulk_stats');

        // Is the image type supported
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

        // Is the image already Compressed
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

        // Is the image in process
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
                // All good
                if ($output == 'json') {
                    wp_send_json_success([self::$apiURL, $post_fields, $body]);
                } else {
                    return 'done';
                }
            } else {
                delete_transient('wps_ic_compress_' . $imageID);

                // Error?
                if ($output == 'json') {
                    wp_send_json_error(['msg' => $body->data->msg, 'server' => $body->data->server]);
                } else {
                    return 'done';
                }
            }

        } else {
            delete_transient('wps_ic_compress_' . $imageID);

            // We were unable to contact API
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

        /**
         * Figure out the actual file path
         */
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

            // Retina File Path
            $retina_file_location = str_replace('.' . $extension, '-x2.' . $extension, $file_location);

            // Enable Retina
            $retinaAPIUrl = str_replace('r:0', 'r:1', $retinaAPIUrl);
            $retinaAPIUrl = str_replace('w:1', 'w:' . $image['width'], $retinaAPIUrl);

            $call = wp_remote_get($retinaAPIUrl, ['timeout' => 60, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (wp_remote_retrieve_response_code($call) == 200) {
                $body = wp_remote_retrieve_body($call);
                // Content validation. Determine format from filename extension.
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

        // Get all thumb sizes
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
        // Prepare the request params
        $post_fields = ['action' => 'restartCompressWorker', 'apikey' => self::$apiParams['apikey'], 'siteurl' => self::$siteUrl,];

        // Notify API to queue to queue the request
        $notify = wp_remote_post(self::$apiURL, ['timeout' => 90, 'blocking' => true, 'body' => $post_fields, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
    }

    public function restartRestoreWorker()
    {
        // Prepare the request params
        $post_fields = ['action' => 'restartRestoreWorker', 'apikey' => self::$apiParams['apikey'], 'siteurl' => self::$siteUrl,];

        // Notify API to queue to queue the request
        $notify = wp_remote_post(self::$apiURL, ['timeout' => 90, 'blocking' => true, 'body' => $post_fields, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
    }

}