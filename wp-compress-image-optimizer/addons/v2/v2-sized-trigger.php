<?php
if (!defined('ABSPATH')) exit;


if (!function_exists('wpc_v2_ideal_targets_from_sizes')) {

    function wpc_v2_ideal_targets_from_sizes($sizes, $fallback_cap = 0)
    {
        $targets = [];
        $sizes = is_string($sizes) ? trim($sizes) : '';


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
        foreach ($targets as $t) {
            $near = false;
            foreach ($out as $o) { if (abs($o - $t) / max($o, $t) < 0.08) { $near = true; break; } }
            if (!$near) $out[] = $t;
        }
        return $out;
    }
}

if (!function_exists('wpc_v2_sized_trigger_queue')) {

    function wpc_v2_sized_trigger_queue($att, $width, $slot_w = 0)
    {
        static $batch = [];
        static $hooked = false;

        $att   = (int) $att;
        $width = (int) $width;
        if ($att <= 0 || $width <= 0) return false;
        if (!apply_filters('wpc_sized_trigger_enabled', true)) return false;
        // Smart Delivery required: the DELIVERY leg (pull manifest → lazy ingest) only runs in

        if (!function_exists('wpc_v2_get_lazy_enabled') || !wpc_v2_get_lazy_enabled()) return false;


        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($att) : false;
        if (!is_array($meta) || empty($meta['file']) || empty($meta['width']) || empty($meta['height'])) return false;

        // CDN flag 3 — cap at the source's natural width (never upscale; collapses small-source DPR rungs).
        $natural = (int) $meta['width'];
        if ($width >= $natural) return false;


        if (!function_exists('wpc_v2_adaptive_variant_suffix')) return false;
        $suffix = wpc_v2_adaptive_variant_suffix($width, $meta); // "-{W}x{H}" (or "-{W}w" if no aspect)
        if ($suffix === '' || strpos($suffix, 'x') === false) return false;


        $iw_stash = get_post_meta($att, 'wpc_ideal_widths', true);
        $iw_stash = is_array($iw_stash) ? $iw_stash : [];
        if (!in_array($width, $iw_stash, true) && count($iw_stash) < 12) {
            $iw_stash[] = $width;
            update_post_meta($att, 'wpc_ideal_widths', $iw_stash);
        }


        $settings = get_option(WPS_IC_SETTINGS);
        $cdn_drives_images = is_array($settings) && !empty($settings['live-cdn']) && (string) $settings['live-cdn'] === '1'
            && (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled($settings));
        $origin_serving = function_exists('wpc_policy_serve') && wpc_policy_serve() === 'origin';
        if ($cdn_drives_images && !$origin_serving) {
            return false;
        }
        if (!$origin_serving && (string) get_option('wpc_envelope_ideal_widths', '1') === '1'
            && get_post_meta($att, 'ic_status', true) !== 'compressed') {
            return false;
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
        // The dispatch door's gates for a rung fill (parked, callbacks waiting in the journal, a
        // restore), asked after the cheap guards: this runs per image on a render.
        if (wps_ic_image_optimize::refusal($att, 'sized-trigger') !== null) return false;
        set_transient($guard, 1, 15 * MINUTE_IN_SECONDS);


        $orig_ext = strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION));
        if ($orig_ext === '') $orig_ext = 'jpg';
        $origin_url = rtrim($up['baseurl'], '/') . '/' . $subdir . $stem . $suffix . '.' . $orig_ext;


        if (count($batch) >= 18) return false;
        $batch[] = [
            'origin_url' => $origin_url,
            'sizeLabel'  => $width . 'w',
            'slot_w'     => ($slot_w > 0 ? (int) $slot_w : $width),
            'display_w'  => min((int) $width, ($slot_w > 0 ? (int) $slot_w : $width)),
        ];

        if (!$hooked) {
            $hooked = true;
            if (function_exists('did_action') && did_action('shutdown')) {
                wpc_sized_trigger_spool_add($batch);
                $batch = [];
                wpc_sized_trigger_schedule_drain();
                return true;
            }
            add_action('shutdown', function () use (&$batch) {
                if (empty($batch)) {
                    return;
                }
                wpc_sized_trigger_spool_add($batch);
                $batch = [];
                $can_detach = apply_filters('wpc_szt_can_detach79', function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'));
                if ($can_detach && function_exists('wpc_render_guard_active') && wpc_render_guard_active() && function_exists('wpc_net_defer')) {
                    wpc_net_defer('szt79', 'wpc_sized_trigger_drain');
                    return;
                }
                register_shutdown_function('wpc_sized_trigger_after_response');
            }, 1);
        }
        return true;
    }
}

if (!function_exists('wpc_sized_trigger_spool_add')) {
    function wpc_sized_trigger_spool_add($items)
    {
        $spool = get_option('wpc_szt_spool79', []);
        if (!is_array($spool)) {
            $spool = [];
        }
        $now = time();
        foreach ($spool as $k => $e) {
            if (!is_array($e) || ($now - (int) (isset($e['t']) ? $e['t'] : 0)) > HOUR_IN_SECONDS) {
                unset($spool[$k]);
            }
        }
        foreach ((array) $items as $it) {
            if (!is_array($it) || empty($it['origin_url'])) {
                continue;
            }
            $k = md5((string) $it['origin_url']);
            if (isset($spool[$k])) {
                continue;
            }
            if (count($spool) >= 200) {
                break;
            }
            $spool[$k] = [
                'origin_url' => (string) $it['origin_url'],
                'sizeLabel'  => isset($it['sizeLabel']) ? (string) $it['sizeLabel'] : '',
                'slot_w'     => isset($it['slot_w']) ? (int) $it['slot_w'] : 0,
                'display_w'  => isset($it['display_w']) ? (int) $it['display_w'] : 0,
                't'          => $now,
            ];
        }
        update_option('wpc_szt_spool79', $spool, false);
        return count($spool);
    }
}

if (!function_exists('wpc_sized_trigger_schedule_drain')) {
    function wpc_sized_trigger_schedule_drain($delay = 60)
    {
        $delay = max(60, min(3600, (int) $delay));
        if (!function_exists('wp_next_scheduled') || wp_next_scheduled(WPC_SIZED_TRIGGER_DRAIN_HOOK)) {
            return false;
        }
        if (function_exists('wpc_pl_sched') && wpc_pl_sched(time() + $delay, WPC_SIZED_TRIGGER_DRAIN_HOOK)) {
            return true;
        }
        if (function_exists('wp_schedule_single_event')) {
            wp_schedule_single_event(time() + $delay, WPC_SIZED_TRIGGER_DRAIN_HOOK);
            return true;
        }
        return false;
    }
}

if (!function_exists('wpc_sized_trigger_after_response')) {
    function wpc_sized_trigger_after_response()
    {
        $released = function_exists('wpc_finish_request') ? wpc_finish_request() : false;
        if ($released) {
            if (function_exists('ignore_user_abort')) {
                @ignore_user_abort(true);
            }
            wpc_sized_trigger_drain();
            return;
        }
        wpc_sized_trigger_schedule_drain();
    }
}

if (!function_exists('wpc_sized_trigger_log')) {
    function wpc_sized_trigger_log($what, $layers = [])
    {
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('sized-trigger', $what, '', $layers);
        }
    }
}

if (!function_exists('wpc_sized_trigger_drain')) {
    function wpc_sized_trigger_drain()
    {
        if (function_exists('wpc_render_guard_active') && wpc_render_guard_active()) {
            wpc_sized_trigger_schedule_drain();
            return 0;
        }
        if (function_exists('wpc_should_shed_under_pressure') ? wpc_should_shed_under_pressure('trigger') : (function_exists('wpc_under_pressure') && wpc_under_pressure())) {
            wpc_sized_trigger_log('pressure', ['spool' => count((array) get_option('wpc_szt_spool79', []))]);
            wpc_sized_trigger_schedule_drain();
            return 0;
        }
        if (get_transient('wpc_szt_draining79')) {
            return 0;
        }
        set_transient('wpc_szt_draining79', 1, 60);
        $spool = get_option('wpc_szt_spool79', []);
        if (!is_array($spool) || empty($spool)) {
            delete_transient('wpc_szt_draining79');
            return 0;
        }
        $apikey = function_exists('wpc_v2_get_apikey') ? (string) wpc_v2_get_apikey() : '';
        $orch   = function_exists('wpc_v2_orchestrator_url') ? (string) wpc_v2_orchestrator_url() : '';
        if ($apikey === '' || $orch === '') {
            delete_transient('wpc_szt_draining79');
            return 0;
        }
        $sent = 0;
        $deadline = microtime(true) + 20;
        $keys = array_keys($spool);
        foreach (array_chunk($keys, 6) as $i => $chunk_keys) {
            if (microtime(true) > $deadline) {
                break;
            }
            if ($i > 0) {
                usleep(1000000);
            }
            $chunk = [];
            foreach ($chunk_keys as $k) {
                $chunk[] = ['origin_url' => $spool[$k]['origin_url'], 'sizeLabel' => $spool[$k]['sizeLabel'], 'slot_w' => $spool[$k]['slot_w']]
                    + (!empty($spool[$k]['display_w']) ? ['display_w' => (int) $spool[$k]['display_w']] : []);
            }
            $resp = wps_ic_image_optimize::dispatch_sized($chunk);
            if (is_wp_error($resp) && $resp->get_error_code() === 'wpc_encode') {
                foreach ($chunk_keys as $k) {
                    unset($spool[$k]);
                }
                continue;
            }
            $labels = implode(',', array_map(function ($it) { return $it['sizeLabel']; }, $chunk));
            $code = is_wp_error($resp) ? 0 : (int) wp_remote_retrieve_response_code($resp);
            if ($code >= 200 && $code < 300) {
                foreach ($chunk_keys as $k) {
                    unset($spool[$k]);
                }
                $sent += count($chunk_keys);
                wpc_sized_trigger_log('dispatched', ['n' => count($chunk), 'labels' => $labels, 'http' => $code, 'spool' => count($spool)]);
                error_log('[WPC SizedTrigger] dispatched items=' . count($chunk) . ' [' . $labels . '] http=' . $code
                    . ' resp=' . substr(preg_replace('/\s+/', ' ', (string) wp_remote_retrieve_body($resp)), 0, 400));
                continue;
            }
            error_log('[WPC SizedTrigger] deferred items=' . count($chunk) . ' [' . $labels . '] '
                . (is_wp_error($resp) ? 'ERR=' . $resp->get_error_message() : 'http=' . $code) . ' spool=' . count($spool));
            wpc_sized_trigger_log('deferred', ['n' => count($chunk), 'labels' => $labels, 'http' => $code, 'err' => is_wp_error($resp) ? substr((string) $resp->get_error_message(), 0, 120) : '', 'spool' => count($spool), 'retry_after' => (int) wp_remote_retrieve_header($resp, 'retry-after')]);
            if ($code === 429) {
                $retry_after = (int) wp_remote_retrieve_header($resp, 'retry-after');
                $now = time();
                foreach ($spool as $spool_key => $spool_entry) {
                    if (is_array($spool_entry)) { $spool[$spool_key]['t'] = $now; }
                }
                update_option('wpc_szt_spool79', $spool, false);
                delete_transient('wpc_szt_draining79');
                wpc_sized_trigger_schedule_drain($retry_after > 0 ? $retry_after : 300);
                return $sent;
            }
            break;
        }
        update_option('wpc_szt_spool79', $spool, false);
        delete_transient('wpc_szt_draining79');
        if (!empty($spool)) {
            wpc_sized_trigger_schedule_drain();
        }
        return $sent;
    }
}
if (function_exists('add_action')) {
    add_action(WPC_SIZED_TRIGGER_DRAIN_HOOK, 'wpc_sized_trigger_drain');
    add_action('wpc_v2_pull_cron', 'wpc_sized_trigger_drain', 20);
}

