<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-trigger-scanner.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.38
 */



if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('wpc_v2_defer_lazy_trigger')) {

    function wpc_v2_defer_lazy_trigger($id, $widths = [], $upgrade_partial = false, $release_sig = '', array $trigger_opts = [])
    {
        global $wpc_v2_deferred_triggers;
        if (!is_array($wpc_v2_deferred_triggers)) {
            $wpc_v2_deferred_triggers = [];
            add_action('shutdown', 'wpc_v2_run_deferred_lazy_triggers', PHP_INT_MAX);
        }
        
        $wpc_v2_deferred_triggers[(int) $id] = [(int) $id, (array) $widths, (bool) $upgrade_partial, (string) $release_sig, (array) $trigger_opts];
    }

    function wpc_v2_run_deferred_lazy_triggers()
    {
        global $wpc_v2_deferred_triggers;
        if (empty($wpc_v2_deferred_triggers) || !function_exists('wpc_lazy_trigger_v2')) {
            return;
        }
        $batch = $wpc_v2_deferred_triggers;
        $wpc_v2_deferred_triggers = [];
        
        
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            @litespeed_finish_request();
        }
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(60);
        }
        $fired = 0;
        foreach ($batch as $t) {
            try {
                if (wpc_lazy_trigger_v2($t[0], $t[1], $t[2], isset($t[4]) && is_array($t[4]) ? $t[4] : [])) {
                    $fired++;
                } elseif ($t[3] !== '') {


                    delete_transient($t[3]);
                }
            } catch (\Throwable $e) {
                error_log('[WPC TriggerScan] deferred trigger image=' . $t[0] . ' threw: ' . $e->getMessage());
            }
        }
        error_log('[WPC TriggerScan] deferred drain post-response queued=' . count($batch) . ' fired=' . $fired);
    }
}

if (!function_exists('wpc_v2_extract_srcset_widths')) {

    function wpc_v2_extract_srcset_widths($html)
    {
        if (!is_string($html) || $html === '') return [];

        $map = []; 

        
        if (preg_match_all(
            '/<img\b[^>]*?\bclass="[^"]*\bwp-image-(\d+)\b[^"]*"[^>]*?\bsrcset="([^"]+)"/i',
            $html, $matches, PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $id     = (int) $m[1];
                $srcset = (string) $m[2];
                if ($id <= 0) continue;
                if (preg_match_all('/\s(\d+)w(?:[\s,]|$)/', $srcset, $w)) {
                    $widths = array_map('intval', $w[1]);
                    if (!isset($map[$id])) $map[$id] = [];
                    $map[$id] = array_values(array_unique(array_merge($map[$id], $widths)));
                }
            }
        }

        
        if (preg_match_all(
            '/<img\b[^>]*?\bsrcset="([^"]+)"[^>]*?\bclass="[^"]*\bwp-image-(\d+)\b[^"]*"/i',
            $html, $matches, PREG_SET_ORDER
        )) {
            foreach ($matches as $m) {
                $id     = (int) $m[2];
                $srcset = (string) $m[1];
                if ($id <= 0) continue;
                if (preg_match_all('/\s(\d+)w(?:[\s,]|$)/', $srcset, $w)) {
                    $widths = array_map('intval', $w[1]);
                    if (!isset($map[$id])) $map[$id] = [];
                    $map[$id] = array_values(array_unique(array_merge($map[$id], $widths)));
                }
            }
        }

        return $map;
    }
}

if (!function_exists('wpc_v2_scan_html_for_lazy_triggers')) {

    function wpc_v2_scan_html_for_lazy_triggers($html, $source = 'unknown', array $opts = [])
    {
        if (!is_string($html) || $html === '') return 0;
        if (!function_exists('wpc_lazy_trigger_v2')) return 0;
        if (!function_exists('wpc_lazy_mode_active') || !wpc_lazy_mode_active()) return 0;

        
        


        $default_enabled = !(function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled());


        $wpc_llb_full_ladder = false;
        if (!$default_enabled) {
            $wpc_s = get_option('wps_ic_settings');
            $wpc_cdn_drives = is_array($wpc_s) && !empty($wpc_s['live-cdn']) && (string) $wpc_s['live-cdn'] === '1'
                && (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled($wpc_s));
            if (!$wpc_cdn_drives) {
                $default_enabled = true;
                $wpc_llb_full_ladder = true;
            }
        }
        if (!apply_filters('wpc_v2_lazy_trigger_scanner_enabled', $default_enabled)) return 0;

        $skip_if_compressed = !isset($opts['skip_if_compressed']) || (bool) $opts['skip_if_compressed'];


        static $request_cache = [];
        $cache_key = sha1($html);
        if (isset($request_cache[$cache_key])) {
            return $request_cache[$cache_key];
        }


        if (!preg_match_all('/\bwp-image-(\d+)\b/', $html, $m)) {
            $request_cache[$cache_key] = 0;
            return 0;
        }

        $ids = array_unique(array_map('intval', $m[1]));
        $ids = array_filter($ids, function ($id) { return $id > 0; });
        if (empty($ids)) return 0;

        
        
        $widths_map = wpc_v2_extract_srcset_widths($html);

        $triggered = 0;
        $skipped   = 0;
        foreach ($ids as $id) {
            $wpc_upgrade_partial = false;
            $wpc_reason197s      = 'new';
            $wpc_fill_fmts197    = [];
            if ($skip_if_compressed) {
                $variants = get_post_meta($id, 'ic_local_variants', true);
                if (is_array($variants) && !empty($variants)) {


                    if ($wpc_llb_full_ladder && function_exists('wpc_v2_variants_all_lazy') && wpc_v2_variants_all_lazy($variants)) {
                        $wpc_upgrade_partial = true;
                        $wpc_reason197s      = 'missing_variant';
                    } else {


                        $fmt_gap = false;
                        if ((string) get_option('wpc_envelope_formats_v2', '1') === '1') {
                            $s_fg = get_option(WPS_IC_SETTINGS);
                            $s_fg = is_array($s_fg) ? $s_fg : [];
                            $ceil_fg = class_exists('WPC_Delivery_Resolver') ? WPC_Delivery_Resolver::effective_ceiling($s_fg) : 'avif';
                            $want_w_fg = ($ceil_fg === 'webp' || $ceil_fg === 'avif' || !empty($s_fg['generate_webp']));
                            $want_a_fg = ($ceil_fg === 'avif' || !empty($s_fg['picture_avif']));
                            $have_w_fg = $have_a_fg = false;
                            foreach (array_keys($variants) as $vk_fg) {
                                if (substr($vk_fg, -5) === '-webp') $have_w_fg = true;
                                elseif (substr($vk_fg, -5) === '-avif') $have_a_fg = true;
                            }
                            if (($want_w_fg && !$have_w_fg) || ($want_a_fg && !$have_a_fg)) {
                                $sig_fg = 'wpc_fmtfill_' . $id . '_' . ($want_w_fg ? 'w' : '') . ($want_a_fg ? 'a' : '');
                                if (!get_transient($sig_fg)) {
                                    set_transient($sig_fg, 1, DAY_IN_SECONDS);
                                    $fmt_gap = true;
                                    error_log('[WPC TriggerScan] format_fill_admit image=' . $id . ' want=' . ($want_w_fg ? 'W' : '') . ($want_a_fg ? 'A' : ''));
                                }
                            }
                        }
                        if ($fmt_gap) {
                            $wpc_upgrade_partial = true;
                            $wpc_reason197s      = 'format_fill';
                            if ($want_w_fg && !$have_w_fg) $wpc_fill_fmts197[] = 'webp';
                            if ($want_a_fg && !$have_a_fg) $wpc_fill_fmts197[] = 'avif';
                        } else {
                            $skipped++;
                            continue;
                        }
                    }
                }
            }
            
            
            $needed_widths = $wpc_llb_full_ladder ? [] : (isset($widths_map[$id]) ? (array) $widths_map[$id] : []);


            


            $wpc_topts197 = ['reason' => $wpc_reason197s];
            if (!empty($wpc_fill_fmts197)) {
                $wpc_topts197['formats'] = array_values(array_unique($wpc_fill_fmts197));
            }
            wpc_v2_defer_lazy_trigger($id, $needed_widths, $wpc_upgrade_partial,
                (!empty($sig_fg) && $fmt_gap) ? $sig_fg : '', $wpc_topts197);
            $triggered++;
        }

        if ($triggered > 0 || $skipped > 0) {
            error_log(sprintf(
                '[WPC TriggerScan] source=%s scanned=%d triggered=%d skipped_compressed=%d',
                $source,
                count($ids),
                $triggered,
                $skipped
            ));
        }

        $request_cache[$cache_key] = $triggered;
        return $triggered;
    }
}





add_action('wpc_cache_buffer_ready', function ($buffer, $url, $prefix) {
    wpc_v2_scan_html_for_lazy_triggers((string) $buffer, 'cache-write:' . (string) $url);
}, 10, 3);





add_action('save_post', function ($post_id, $post, $update) {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
    if (!is_object($post) || $post->post_status !== 'publish') return;


    wpc_v2_scan_html_for_lazy_triggers(
        (string) $post->post_content,
        'save_post:' . (int) $post_id
    );
}, 20, 3);





add_action('template_redirect', function () {
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX) || (defined('DOING_CRON') && DOING_CRON)) return;
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    if (function_exists('is_feed') && is_feed()) return;
    if (!function_exists('wpc_lazy_mode_active') || !wpc_lazy_mode_active()) return;

    ob_start(function ($html) {
        
        
        try {
            wpc_v2_scan_html_for_lazy_triggers((string) $html, 'output-buffer');
        } catch (\Throwable $e) {
            
        }
        return $html;
    });
}, 1);
