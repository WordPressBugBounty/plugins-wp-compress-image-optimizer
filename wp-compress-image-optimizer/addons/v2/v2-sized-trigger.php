<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-sized-trigger.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.21.337
 */

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
        

        if (!function_exists('wpc_v2_get_lazy_enabled') || !wpc_v2_get_lazy_enabled()) return false;


        if (get_transient('wpc_restoring_' . $att)) return false;

        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($att) : false;
        if (!is_array($meta) || empty($meta['file']) || empty($meta['width']) || empty($meta['height'])) return false;

        
        $natural = (int) $meta['width'];
        if ($width >= $natural) return false;


        if (!function_exists('wpc_v2_adaptive_variant_suffix')) return false;
        $suffix = wpc_v2_adaptive_variant_suffix($width, $meta); 
        if ($suffix === '' || strpos($suffix, 'x') === false) return false;


        $iw_stash = get_post_meta($att, 'wpc_ideal_widths', true);
        $iw_stash = is_array($iw_stash) ? $iw_stash : [];
        if (!in_array($width, $iw_stash, true) && count($iw_stash) < 12) {
            $iw_stash[] = $width;
            update_post_meta($att, 'wpc_ideal_widths', $iw_stash);
        }


        $s_c22 = get_option(WPS_IC_SETTINGS);
        $cdn_drives_c22 = is_array($s_c22) && !empty($s_c22['live-cdn']) && (string) $s_c22['live-cdn'] === '1'
            && (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled($s_c22));
        if ($cdn_drives_c22) {
            return false;
        }
        if ((string) get_option('wpc_envelope_ideal_widths', '1') === '1'
            && get_post_meta($att, 'ic_status', true) !== 'compressed') {
            return false;
        }

        
        $up = wp_get_upload_dir();
        if (empty($up['basedir']) || empty($up['baseurl'])) return false;
        $subdir = (strpos($meta['file'], '/') !== false) ? substr($meta['file'], 0, strrpos($meta['file'], '/') + 1) : '';
        $stem   = preg_replace('/(-scaled)?\.[^.]+$/', '', basename((string) $meta['file']));
        if (@file_exists(rtrim($up['basedir'], '/') . '/' . $subdir . $stem . $suffix . '.avif')) return false;

        
        $guard = 'wpc_szt_' . $att . '_' . $width;
        if (get_transient($guard)) return false;
        set_transient($guard, 1, 15 * MINUTE_IN_SECONDS);


        $orig_ext = strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION));
        if ($orig_ext === '') $orig_ext = 'jpg';
        $origin_url = rtrim($up['baseurl'], '/') . '/' . $subdir . $stem . $suffix . '.' . $orig_ext;


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
                }
            });
        }
        return true;
    }
}
