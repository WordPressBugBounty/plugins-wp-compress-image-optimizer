<?php
if (!defined('ABSPATH')) exit;

/**
 * Plugin-signed sized-trigger client (Pixel-Optimal spec, Phase 2).
 *
 * Orch contract (v3.22.21, agreed 2026-06-10 with both teams live):
 *   POST {orch}/v2/sized-trigger
 *   body (canonical, byte-exact for the sig):
 *     {"apikey":"<40hex>","items":[{"origin_url":"https://…/img-1024x768.jpg","sizeLabel":"1024w","slot_w":1024}]}
 *   - items[] ≤ 6; sizeLabel /^\d+w$/; slot_w optional int; origin_url SIZED (it determines
 *     orch's output filename — same rule as edge triggers). AVIF implied; webp stays no_op.
 *   - Auth: X-WPC-Sig t=…,v1=HMAC-SHA256(t . "." . sha256(raw_body), apikey). All-or-nothing.
 *   - Budget: orch-side per-apikey token bucket (plugin_trigger_budget, default 60/hour).
 *   - trigger_source: "plugin_ideal_width" threaded into orch traces (census separation).
 *   - Delivery: pull manifest + wake — the exact path the PNG contract fixture verified
 *     (sizeLabel "1024w" + filename:null → canonical -1024x768.avif on disk, all serves green).
 *
 * SOFT-FAIL BY DESIGN: until orch's v3.22.21 deploys, the endpoint 404s. Non-blocking,
 * fire-and-forget, nothing downstream depends on the response (the avif_landed pattern).
 *
 * CDN flag 3 (v2.89.17.2 session): rung widths are CAPPED AT THE SOURCE'S NATURAL WIDTH
 * before any trigger — transform/trigger paths carry no -WxH for orch's cap chain to read,
 * so a 600px slot at DPR 3 must not request w:1800 from a 1200w source. Capping also
 * collapses the high-DPR rungs of small sources into one width (volume reduction).
 */

if (!function_exists('wpc_v2_ideal_targets_from_sizes')) {
    /**
     * PER-IMAGE ideal widths, derived from the image's OWN sizes attribute
     * (which we write, so the slot model is authoritative). Parses each tier:
     *   - "NNpx" terms → that tier's slot in px (the final bare px = the desktop slot);
     *   - "NNvw (max-width: Mpx)" terms → slot = NN% of min(M, 412) (the phone viewport);
     * then needs = slot × DPR {1.75, 2, 3} for vw tiers and × {1, 1.75, 2} for the px slot
     * (1.75 = the PSI Moto profile). Falls back to the theme-content-width model when no
     * sizes is available. Dedupes within 8% of itself; caller dedupes vs existing rungs and
     * the queue helper enforces the natural-width cap.
     *
     * Examples: "(max-width:600px) 50vw, (max-width:1024px) 40vw, 620px"
     *             → 206-tier {361,412,618} + 620-tier {620,1085,1240}
     *           "auto, (max-width:221px) 100vw, 221px" → {221,387,442,663}-class (B's real slot)
     *
     * @return int[] ascending, self-deduped
     */
    function wpc_v2_ideal_targets_from_sizes($sizes, $fallback_cap = 0)
    {
        $targets = [];
        $sizes = is_string($sizes) ? trim($sizes) : '';
        // (v7.03.73) sizes=auto means the browser self-measures the REAL rendered slot at runtime — so any
        // explicit px tier is only an UPPER bound, not the slot. Enables the down-ladder in the px branch.
        $auto_sub = ($sizes !== '' && stripos($sizes, 'auto') !== false
            && (!function_exists('apply_filters') || apply_filters('wpc_nd_auto_subtier_rungs', true)));
        if ($sizes !== '') {
            foreach (array_map('trim', explode(',', $sizes)) as $tier) {
                if ($tier === '' || strtolower($tier) === 'auto') continue;
                if (preg_match('/(\d+(?:\.\d+)?)vw/i', $tier, $vm)) {
                    $bp = preg_match('/max-width:\s*(\d+)px/i', $tier, $bm) ? (int) $bm[1] : 412;
                    $slot = (int) round(min($bp, 412) * ((float) $vm[1] / 100));
                    foreach ([1.75, 2, 3] as $d) $targets[] = (int) round($slot * $d);
                } elseif (preg_match('/(\d+)px\s*$/', $tier, $pm)) {
                    $slot = (int) $pm[1];
                    foreach ([1, 1.75, 2] as $d) $targets[] = (int) round($slot * $d);
                    // (v7.03.73) A trailing px tier under sizes=auto is only an UPPER bound (a portrait ad at
                    // width:100% in a ~300px sidebar still reports its 887px natural here). Without a sub-tier
                    // rung the browser rounds UP to the smallest big rung and over-fetches. Ladder DOWN so auto
                    // has rungs near the true width (DPR1 right-size + DPR2 sharpness). The generator's natural
                    // cap + 8% dedupe + 200px floor bound what actually emits.
                    if ($auto_sub) {
                        foreach ([0.66, 0.5, 0.33] as $f) $targets[] = (int) round($slot * $f);
                    }
                }
            }
        }
        if (empty($targets) && $fallback_cap > 0) {
            foreach ([(int) round(206 * 1.75), 412, (int) round(206 * 3), $fallback_cap, (int) round($fallback_cap * 1.75), $fallback_cap * 2] as $t) $targets[] = $t;
        }
        $targets = array_values(array_unique(array_filter(array_map('intval', $targets), function ($t) { return $t >= 200; })));
        sort($targets);
        $out = [];
        foreach ($targets as $t) { // self-dedupe within 8%
            $near = false;
            foreach ($out as $o) { if (abs($o - $t) / max($o, $t) < 0.08) { $near = true; break; } }
            if (!$near) $out[] = $t;
        }
        return $out;
    }
}

if (!function_exists('wpc_v2_sized_trigger_queue')) {
    /**
     * Queue one width for one attachment. Batched per-request (≤6, orch contract), flushed
     * on shutdown as ONE non-blocking POST. Guards: numeric inputs, natural-width cap,
     * skip-if-on-disk (idempotent before it even leaves the box), and a 15-min per-(att,width)
     * transient so retries/bursts can't drain the orch token bucket.
     *
     * @return bool queued (false = guarded out — never an error condition)
     */
    function wpc_v2_sized_trigger_queue($att, $width, $slot_w = 0)
    {
        static $batch = [];
        static $hooked = false;

        $att   = (int) $att;
        $width = (int) $width;
        if ($att <= 0 || $width <= 0) return false;
        if (!apply_filters('wpc_sized_trigger_enabled', true)) return false;
        // Smart Delivery required: the DELIVERY leg (pull manifest → lazy ingest) only runs in
        // lazy_cdn mode — triggering without it would burn orch encodes that never land locally.
        if (!function_exists('wpc_v2_get_lazy_enabled') || !wpc_v2_get_lazy_enabled()) return false;

        // restore-in-flight micro-lock: a render racing a mid-flight restore must
        // not queue against files being deleted this instant. Released the moment the restore
        // completes; the NEXT render re-triggers freely (the 13s demo flow).
        if (get_transient('wpc_restoring_' . $att)) return false;

        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($att) : false;
        if (!is_array($meta) || empty($meta['file']) || empty($meta['width']) || empty($meta['height'])) return false;

        // CDN flag 3 — cap at the source's natural width (never upscale; collapses small-source DPR rungs).
        $natural = (int) $meta['width'];
        if ($width >= $natural) return false; // the natural/scaled file already serves this need

        // Aspect-derived sized filename — the SAME symmetric helper the write/read paths use,
        // so our origin_url matches the filename orch will derive (and the rung the emitters read).
        if (!function_exists('wpc_v2_adaptive_variant_suffix')) return false;
        $suffix = wpc_v2_adaptive_variant_suffix($width, $meta); // "-{W}x{H}" (or "-{W}w" if no aspect)
        if ($suffix === '' || strpos($suffix, 'x') === false) return false;

        // DEMAND MEMORY (a user design call): persist this image's real slot widths
        // (union, capped) so a completed compress can REPLAY them (promote_to_compressed).
        // restore→backfill previously needed a SECOND page render before the per-slot rungs
        // could even queue. Survives restores deliberately: it's page-derived demand data,
        // not optimization state. Recorded BEFORE the skip-if-on-disk guard so on-disk widths
        // are remembered for the post-restore refire too.
        $iw_stash = get_post_meta($att, 'wpc_ideal_widths', true);
        $iw_stash = is_array($iw_stash) ? $iw_stash : [];
        if (!in_array($width, $iw_stash, true) && count($iw_stash) < 12) {
            $iw_stash[] = $width;
            update_post_meta($att, 'wpc_ideal_widths', $iw_stash);
        }

        // With the unified envelope ON, an image that isn't
        // compressed yet gets its demand widths VIA THE IMMINENT COMPRESS (the scanner
        // backfills it; the envelope carries the stash we just wrote above), so dispatching
        // a per-width trigger here would duplicate the work in the fetch-heavy lane.
        // Stash-only for those; ALREADY-compressed images keep dispatching (the
        // incremental new-context case, where no compress is coming).
        // DISPATCH POLICY, after a user caught over-generation (one CDN-ON reload landed
        // the FULL 7-target set where the old model landed only the picked variant):
        //   CDN-ON  → STASH-ONLY, always. The EDGE is the per-fetch demand engine: a real
        //             visitor picking a bootstrap rung fires that exact width's trigger —
        //             one actual pick, one encode, one variant. Render-side dispatch here
        //             was speculative duplication of every width for visitors who may
        //             never come. The stash still feeds future compress envelopes.
        //   CDN-OFF → uncompressed: stash-only (the imminent backfill envelope carries it);
        //             compressed: DISPATCH (incremental new-context width — no edge, no
        //             compress coming; the trigger lane's one real job).
        $s_c22 = get_option(WPS_IC_SETTINGS);
        $cdn_drives_c22 = is_array($s_c22) && !empty($s_c22['live-cdn']) && (string) $s_c22['live-cdn'] === '1'
            && (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled($s_c22));
        if ($cdn_drives_c22) {
            return false; // edge demand serves + triggers per actual pick
        }
        if ((string) get_option('wpc_envelope_ideal_widths', '1') === '1'
            && get_post_meta($att, 'ic_status', true) !== 'compressed') {
            return false; // scanner will backfill; the envelope carries the demand
        }

        // Skip if the avif is already on disk (idempotent, zero network).
        $up = wp_get_upload_dir();
        if (empty($up['basedir']) || empty($up['baseurl'])) return false;
        $subdir = (strpos($meta['file'], '/') !== false) ? substr($meta['file'], 0, strrpos($meta['file'], '/') + 1) : '';
        $stem   = preg_replace('/(-scaled)?\.[^.]+$/', '', basename((string) $meta['file']));
        if (@file_exists(rtrim($up['basedir'], '/') . '/' . $subdir . $stem . $suffix . '.avif')) return false;

        // Burst guard: one trigger per (attachment, width) per 15 min.
        $guard = 'wpc_szt_' . $att . '_' . $width;
        if (get_transient($guard)) return false;
        set_transient($guard, 1, 15 * MINUTE_IN_SECONDS);

        // Sized origin_url — orch's rule: the sized name determines the output filename.
        $orig_ext = strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION));
        if ($orig_ext === '') $orig_ext = 'jpg';
        $origin_url = rtrim($up['baseurl'], '/') . '/' . $subdir . $stem . $suffix . '.' . $orig_ext;

        // BATCH STARVATION FIX (the all-night B mystery, caught by the first
        // response-visible dispatch): the hero's 6 sizes-parsed targets filled the single
        // ≤6 batch before the page's OTHER images queued anything, so image B's widths were
        // rejected here on every multi-image render. The orch contract caps items per POST
        // at 6, not per REQUEST: the queue now holds 3 batches' worth and the shutdown
        // flush sends array_chunk(…, 6) as sequential signed posts (bucket + caps still
        // bound totals orch-side).
        if (count($batch) >= 18) return false;
        $batch[] = [
            'origin_url' => $origin_url,
            'sizeLabel'  => $width . 'w',
            'slot_w'     => ($slot_w > 0 ? (int) $slot_w : $width),
        ];

        if (!$hooked) {
            $hooked = true;
            register_shutdown_function(function () use (&$batch) {
                if (empty($batch)) return;
                if (!function_exists('wpc_v2_get_apikey') || !function_exists('wpc_v2_orchestrator_url')) return;
                $apikey = (string) wpc_v2_get_apikey();
                $orch   = (string) wpc_v2_orchestrator_url();
                if ($apikey === '' || $orch === '') return;
                foreach (array_chunk(array_values($batch), 6) as $chunk) {
                $body_raw = wp_json_encode(['apikey' => $apikey, 'items' => $chunk]);
                if ($body_raw === false) continue;
                $ts  = time();
                $sig = hash_hmac('sha256', $ts . '.' . hash('sha256', $body_raw), $apikey);
                // RESPONSE VISIBILITY (the all-night lesson: fire-and-forget meant
                // orch's verdict on REAL batches was never observed, only hand probes). The
                // dispatch runs in register_shutdown_function, AFTER the response is sent, so
                // blocking here costs the visitor nothing. Per-item accepted/rejected verdicts
                // land in debug.log; wp_remote errors logged too. Cap'd timeout keeps shutdown
                // bounded on a dead orch.
                $resp = wp_remote_post(rtrim($orch, '/') . '/v2/sized-trigger', [
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
                if (function_exists('error_log')) {
                    $labels = implode(',', array_map(function ($i) { return $i['sizeLabel']; }, $chunk));
                    if (is_wp_error($resp)) {
                        error_log('[WPC SizedTrigger] dispatched items=' . count($chunk) . ' [' . $labels . '] ERR=' . $resp->get_error_message());
                    } else {
                        error_log('[WPC SizedTrigger] dispatched items=' . count($chunk) . ' [' . $labels . '] http=' . wp_remote_retrieve_response_code($resp)
                            . ' resp=' . substr(preg_replace('/\s+/', ' ', (string) wp_remote_retrieve_body($resp)), 0, 400));
                    }
                }
                } // end chunk loop
            });
        }
        return true;
    }
}
