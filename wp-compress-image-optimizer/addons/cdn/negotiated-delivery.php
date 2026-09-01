<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/cdn/negotiated-delivery.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */


if (!defined('ABSPATH')) exit;

class WPC_Negotiated_Delivery
{

    const EMISSION_READY = true;

    
    const MARK = 'data-wpc-nd';

    



    private static $img_index = 0;


    private static $jpeg_mode = false;

    





    private static $modeb_test = false;


    public static function emission_ready()
    {

        if (defined('WPC_NEGOTIATED_GA')) return (bool) WPC_NEGOTIATED_GA;
        if (function_exists('get_option')) {

            $o = get_option('wpc_negotiated_ga', null);
            if ($o !== null && $o !== false && $o !== '') {
                return ($o === '1' || $o === 1 || $o === true);
            }


            if (defined('WPS_IC_SETTINGS')) {
                $s = get_option(WPS_IC_SETTINGS);
                if (is_array($s) && isset($s['wpc_nextgen']) && $s['wpc_nextgen'] !== '') {
                    $ng = strtolower((string) $s['wpc_nextgen']);
                    if ($ng === 'off') return false;
                    if ($ng === 'webp' || $ng === 'avif' || $ng === 'auto') return true;
                }
            }
        }
        
        return self::EMISSION_READY;
    }


    public static function test_force_mode()
    {
        static $resolved = false, $mode = null;
        if ($resolved) return $mode;
        $resolved = true;
        if (!function_exists('get_option') || empty($_GET['wpc_delivery'])) return $mode;
        $enabled = (defined('WPC_DELIVERY_TEST_MODE') && WPC_DELIVERY_TEST_MODE);
        if (!$enabled) {
            $o = get_option('wpc_delivery_test_mode', '');
            $enabled = ($o === '1' || $o === 1 || $o === true);
        }
        if (!$enabled) return $mode;
        $m = strtolower(preg_replace('/[^a-z]/', '', (string) $_GET['wpc_delivery']));
        if (in_array($m, ['edge', 'legacy', 'natural', 'modeb'], true)) $mode = $m;
        return $mode;
    }

    





    public static function cdn_images_enabled($s = null)
    {
        if ($s === null) {
            if (!function_exists('get_option') || !defined('WPS_IC_SETTINGS')) return false;
            $s = get_option(WPS_IC_SETTINGS);
        }
        if (!is_array($s) || empty($s['serve']) || !is_array($s['serve'])) return false;
        foreach (['jpg', 'png', 'gif', 'svg'] as $k) {
            if (!empty($s['serve'][$k]) && (string) $s['serve'][$k] === '1') return true;
        }
        return false;
    }

    public static function is_active()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;

        
        if (is_admin()) return false;
        if (defined('DOING_AJAX') && DOING_AJAX) return false;
        if (defined('DOING_CRON') && DOING_CRON) return false;
        if (function_exists('is_feed') && is_feed()) return false;
        if (function_exists('amp_is_request') && amp_is_request()) return false;
        if (function_exists('is_amp_endpoint') && is_amp_endpoint()) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;

        
        
        $forced = self::test_force_mode();
        if ($forced === 'edge' || $forced === 'modeb') return self::cdn_host() !== '';
        if ($forced !== null)   return false;

        
        if (!self::cdn_images_enabled()) return false;

        if (!self::emission_ready()) return false;

        
        if (!apply_filters('wpc_negotiated_delivery_enabled', true)) return false;


        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) return false;

        
        if (!class_exists('WPC_Delivery_Resolver')) return false;
        return WPC_Delivery_Resolver::resolve() === WPC_Delivery_Resolver::TIER_CDN_EDGE;
    }


    public static function is_active_jpeg()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;

        
        if (is_admin()) return false;
        if (defined('DOING_AJAX') && DOING_AJAX) return false;
        if (defined('DOING_CRON') && DOING_CRON) return false;
        if (function_exists('is_feed') && is_feed()) return false;
        if (function_exists('amp_is_request') && amp_is_request()) return false;
        if (function_exists('is_amp_endpoint') && is_amp_endpoint()) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;

        
        $forced = self::test_force_mode();
        if ($forced === 'natural') return self::cdn_host() !== '';
        if ($forced !== null)      return false;

        
        if (!apply_filters('wpc_negotiated_delivery_enabled', true)) return false;
        if (!apply_filters('wpc_jpeg_natural_enabled', true)) return false;

        if (!class_exists('WPC_Delivery_Resolver')) return false;
        if (!function_exists('get_option') || !defined('WPS_IC_SETTINGS')) return false;
        $s = get_option(WPS_IC_SETTINGS);
        if (!is_array($s)) return false;

        
        if (!self::cdn_images_enabled($s)) return false;

        
        if (WPC_Delivery_Resolver::effective_ceiling($s) !== 'off') return false;

        
        if (empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') return false;

        
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) return false;

        
        
        $v = WPC_Delivery_Resolver::resolve_verbose();
        return is_array($v) && isset($v['verify']['cdn']['ok']) && $v['verify']['cdn']['ok'] === true;
    }


    public static function cdn_host()
    {
        $cf_cname  = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $cf_set    = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
        $cf_cdn_on = is_array($cf_set) && !empty($cf_set['settings']['cdn']);
        if ($cf_cname !== '' && $cf_cdn_on) return $cf_cname;

        $cname = trim((string) get_option('ic_custom_cname'));
        if ($cname !== '') return $cname;
        return trim((string) get_option('ic_cdn_zone_name'));
    }

    




    public static function edge_origin_active()
    {
        if (!class_exists('WPC_Delivery_Resolver')) return false;
        $rv = WPC_Delivery_Resolver::resolve_verbose();
        return is_array($rv) && isset($rv['tier'], $rv['redirect_target'])
            && (int) $rv['tier'] === WPC_Delivery_Resolver::TIER_CDN_EDGE
            && $rv['redirect_target'] === 'origin';
    }

    




    public static function rewrite_buffer($html)
    {
        try {
            
            if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'dontRunif')
                && !wps_cdn_rewrite::dontRunif()) {
                return $html;
            }


            if (!empty($_POST['action'])) {
                return $html;
            }

            
            self::$img_index = 0;


            self::$jpeg_mode = (!self::is_active() && self::is_active_jpeg());


            self::$modeb_test = (self::test_force_mode() === 'modeb') || self::edge_origin_active();

            
            $picture_stash = [];
            $html = preg_replace_callback('/<noscript\b[^>]*>.*?<\/noscript>/is', function ($m) use (&$picture_stash) {
                $i = '___WPCND_NOSCRIPT_' . count($picture_stash) . '___';
                $picture_stash[$i] = $m[0];
                return $i;
            }, $html);
            $html = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$picture_stash) {
                $i = '___WPCND_PICTURE_' . count($picture_stash) . '___';
                $picture_stash[$i] = $m[0];
                return $i;
            }, $html);

            
            $html = preg_replace_callback('/(?<![\\"\'])<img\b[^>]*>/i', function ($m) {
                try {
                    return self::rewrite_one_img($m[0]);
                } catch (\Throwable $e) {
                    return $m[0];
                }
            }, $html);

            if (!empty($picture_stash)) {
                $html = strtr($html, $picture_stash);
            }


            return $html;
        } catch (\Throwable $e) {
            error_log('[WPC NegotiatedDelivery] rewrite_buffer error — passthrough: ' . $e->getMessage());
            return $html;
        }
    }

    



    private static function rewrite_one_img($tag)
    {
        
        if (strpos($tag, self::MARK) !== false) return $tag;


        $attrs = self::parse_attrs($tag);

        $src = isset($attrs['src']) ? trim($attrs['src']) : '';
        if ($src === '') return $tag;

        
        if (stripos($src, 'data:') === 0) return $tag;
        if (preg_match('/\.(svg|svgz|gif|ico)(\?|$)/i', $src)) return $tag;
        $host = self::cdn_host();
        if ($host !== '' && strpos($src, $host) !== false) return $tag;
        if (preg_match('#//#', $src) && !self::is_local($src)) return $tag;
        if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'isExcluded')
            && wps_cdn_rewrite::isExcluded('cdn', $src)) return $tag;

        foreach (['data-src', 'data-srcset', 'data-cp-src', 'data-oi', 'data-interchange', 'data-mk-image-src-set'] as $k) {
            if (isset($attrs[$k])) return $tag;
        }
        $cls = isset($attrs['class']) ? $attrs['class'] : '';
        if (preg_match('/\b(skip-lazy|notlazy|nolazy|breakdance|jet-image|data-lazy)\b/i', $cls)) return $tag;

        
        $att = (class_exists('WPC_Modern_Delivery') && method_exists('WPC_Modern_Delivery', 'resolve_attachment_id'))
            ? (int) WPC_Modern_Delivery::resolve_attachment_id($src, $cls)
            : ((class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_att_id')) ? (int) wps_rewriteLogic::wpc_att_id($src) : 0);
        if ($att <= 0) return $tag;
        if (class_exists('WPC_Modern_Delivery') && method_exists('WPC_Modern_Delivery', 'is_processable')
            && !WPC_Modern_Delivery::is_processable($att)) return $tag;

        $meta = function_exists('wp_get_attachment_metadata') ? wp_get_attachment_metadata($att) : false;
        if (!is_array($meta) || empty($meta['file'])) return $tag;

        return self::build_negotiated_img($tag, $attrs, $att, $meta);
    }


    private static $afold_hints_cache = null;
    private static function afoldHints()
    {
        if (self::$afold_hints_cache !== null) {
            return self::$afold_hints_cache;
        }
        self::$afold_hints_cache = [];
        if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
            return self::$afold_hints_cache;
        }
        $url = (is_ssl() ? 'https://' : 'http://')
            . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
            . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?');
        $key = (new wps_ic_url_key())->setup($url);
        if ($key === '') {
            return self::$afold_hints_cache;
        }
        $f = rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/lcp.json';
        if (!@is_readable($f)) {
            return self::$afold_hints_cache;
        }
        $j   = json_decode((string) @file_get_contents($f), true);
        $atf = (is_array($j) && isset($j['atf_images']) && is_array($j['atf_images'])) ? $j['atf_images'] : null;
        if ($atf === null) {
            return self::$afold_hints_cache;
        }
        $mob = (isset($atf['mobile'])  && is_array($atf['mobile']))  ? $atf['mobile']  : [];
        $des = (isset($atf['desktop']) && is_array($atf['desktop'])) ? $atf['desktop'] : [];
        if (empty($mob) && empty($des)) { $mob = $atf; $des = $atf; }
        $map = [];
        foreach (['m' => $mob, 'd' => $des] as $slot => $list) {
            foreach ((array) $list as $im) {
                if (!is_array($im) || empty($im['stem']) || empty($im['css_w'])) {
                    continue;
                }
                $st = strtolower((string) $im['stem']);
                if ($st === '') {
                    continue;
                }
                if (!isset($map[$st])) {
                    $map[$st] = ['m' => 0, 'd' => 0];
                }
                if ($map[$st][$slot] === 0) {
                    $map[$st][$slot] = (int) round((float) $im['css_w']);
                }
            }
        }
        self::$afold_hints_cache = $map;
        return self::$afold_hints_cache;
    }

    



    private static function build_negotiated_img($tag, $attrs, $att, $meta)
    {
        
        $entries = [];   
        $by_width = [];
        if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
            foreach ($meta['sizes'] as $sz) {
                if (empty($sz['file']) || empty($sz['width'])) continue;


                if (!empty($sz['height']) && !empty($meta['width']) && !empty($meta['height'])
                    && function_exists('wp_image_matches_ratio')
                    && !wp_image_matches_ratio((int) $sz['width'], (int) $sz['height'], (int) $meta['width'], (int) $meta['height'])) {
                    continue;
                }
                $w = (int) $sz['width'];
                $url = self::build_natural_url((string) $sz['file'], $meta);
                if ($url === '') continue;
                $by_width[$w] = $url;
            }
        }
        
        if (!empty($meta['width']) && !empty($meta['file'])) {
            $full = self::build_natural_url(basename((string) $meta['file']), $meta);
            if ($full !== '') $by_width[(int) $meta['width']] = $full;
        }


        if (!empty($att) && !empty($meta['file'])) {
            $lv = get_post_meta((int) $att, 'ic_local_variants', true);
            if (is_array($lv) && !empty($lv)) {
                $up_pr   = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : [];
                $base_pr = !empty($up_pr['basedir']) ? rtrim($up_pr['basedir'], '/') : '';
                $dir_pr  = (strpos($meta['file'], '/') !== false) ? substr($meta['file'], 0, strrpos($meta['file'], '/') + 1) : '';
                $stem_pr = preg_replace('/(-scaled)?\.[^.]+$/', '', basename((string) $meta['file']));
                $oext_pr = strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION));
                $seen_wh = [];
                foreach ($lv as $lk => $le) {
                    if (!preg_match('/^(\d+)x(\d+)(?:-[a-z0-9]+)?$/', (string) $lk, $am)) continue;
                    $aw = (int) $am[1];
                    $wh = $am[1] . 'x' . $am[2];
                    if ($aw <= 0 || isset($by_width[$aw]) || isset($seen_wh[$wh]) || $base_pr === '') continue;
                    
                    
                    if (!empty($meta['width']) && !empty($meta['height'])
                        && function_exists('wp_image_matches_ratio')
                        && !wp_image_matches_ratio($aw, (int) $am[2], (int) $meta['width'], (int) $meta['height'])) {
                        continue;
                    }
                    $seen_wh[$wh] = true;
                    $close_pr = false;
                    foreach (array_keys($by_width) as $ew) {
                        if ($ew > 0 && abs($ew - $aw) / $ew < 0.08) { $close_pr = true; break; }
                    }
                    if ($close_pr) continue;
                    $fbase_pr = $stem_pr . '-' . $wh;
                    $exts_pr  = self::$jpeg_mode ? [$oext_pr] : ['webp', 'avif'];
                    foreach ($exts_pr as $xt) {
                        if ($xt !== '' && @file_exists($base_pr . '/' . $dir_pr . $fbase_pr . '.' . $xt)) {
                            $u_pr = self::build_natural_url($fbase_pr . '.' . $xt, $meta);
                            if ($u_pr !== '') $by_width[$aw] = $u_pr;
                            break;
                        }
                    }
                }
            }
        }
        if (empty($by_width)) return $tag;

        


        $loading = isset($attrs['loading']) ? $attrs['loading'] : '';


        $nd_rw352 = false;
        if (isset($attrs['sizes']) && $attrs['sizes'] !== ''
            && preg_match('/^(?:auto, *)?\(max-width: *600px\) *50vw, *\(max-width: *1024px\) *40vw, *(\d+)px$/i', trim((string) $attrs['sizes']), $m_baked)
            && preg_match('/\b(size-full|alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', isset($attrs['class']) ? (string) $attrs['class'] : '')) {
            $w_baked = isset($attrs['width']) ? (int) preg_replace('/\D/', '', (string) $attrs['width']) : 0;
            if ($w_baked > (int) $m_baked[1]) {
                $attrs['sizes'] = '(max-width: ' . $w_baked . 'px) 100vw, ' . $w_baked . 'px';
                $nd_rw352 = true; 
            } else {
                unset($attrs['sizes']);
            }
        }
        $wpc_lcp_sizes = '';
        $wpc_crit_sized = false;


        $wpc_afold_hints = apply_filters('wpc_afold_image_hints', self::afoldHints());
        if (is_array($wpc_afold_hints) && !empty($wpc_afold_hints) && !empty($attrs['src'])) {
            $wpc_afold_base = basename(strtok((string) $attrs['src'], '?#'));
            $wpc_afold_stem = strtolower(preg_replace('/(-\d+x\d+|-scaled)?\.[^.]+$/', '', $wpc_afold_base));
            if ($wpc_afold_stem !== '' && isset($wpc_afold_hints[$wpc_afold_stem])) {
                $wpc_afold_mW = (int) $wpc_afold_hints[$wpc_afold_stem]['m'];
                $wpc_afold_dW = (int) $wpc_afold_hints[$wpc_afold_stem]['d'];
                if ($wpc_afold_mW > 0 && $wpc_afold_dW > 0) {
                    $wpc_lcp_sizes  = '(max-width: 768px) ' . $wpc_afold_mW . 'px, ' . $wpc_afold_dW . 'px';
                    $wpc_crit_sized = true;
                } elseif ($wpc_afold_dW > 0) {
                    $wpc_lcp_sizes  = (string) $wpc_afold_dW . 'px';
                    $wpc_crit_sized = true;
                } elseif ($wpc_afold_mW > 0) {
                    $wpc_lcp_sizes  = (string) $wpc_afold_mW . 'px';
                    $wpc_crit_sized = true;
                }
            }
        }
        
        if ($wpc_lcp_sizes === '' && self::$img_index === 0) {
            $set_l = get_option(WPS_IC_SETTINGS);
            if (is_array($set_l) && !empty($set_l['optimize-lcp'])) {
                $w_lcp  = isset($attrs['width']) ? (int) preg_replace('/\D/', '', (string) $attrs['width']) : 0;
                
                
                $pfx    = '';


                $cls_lcp = isset($attrs['class']) ? (string) $attrs['class'] : '';
                $lcp_full_bleed = (bool) preg_match('/\b(size-full|alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $cls_lcp);
                if ($lcp_full_bleed) {

                } elseif ($w_lcp > 0 && $w_lcp < 1200) {
                    $wpc_lcp_sizes = $pfx . '(max-width: ' . $w_lcp . 'px) 100vw, ' . $w_lcp . 'px';
                } else {
                    $maxW_l = !empty($set_l['maxWidth']) ? (int) $set_l['maxWidth'] : 2560;
                    $cw_l   = function_exists('wpc_get_theme_content_width') ? (int) wpc_get_theme_content_width() : 0;
                    $cap_l  = $cw_l > 0 ? $cw_l : min(1200, max(400, $maxW_l));
                    $wpc_lcp_sizes = $pfx . '(max-width: 600px) 50vw, (max-width: 1024px) 40vw, ' . $cap_l . 'px';
                }
                $wpc_lcp_sizes = (string) apply_filters('wpc_picture_lcp_sizes', $wpc_lcp_sizes, $attrs, $set_l);
            }
        }

        $ng_cls   = isset($attrs['class']) ? (string) $attrs['class'] : '';


        
        $ng_has_auto = isset($attrs['sizes']) && stripos((string) $attrs['sizes'], 'auto') !== false;
        $ng_confident = !preg_match('/\b(alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $ng_cls)
            && (
                (function_exists('wpc_get_theme_content_width') && (int) wpc_get_theme_content_width() > 0)
                || $ng_has_auto
                || $wpc_crit_sized
            )
            && apply_filters('wpc_ideal_width_generator', true);
        if ($ng_confident && !empty($meta['width']) && function_exists('wpc_v2_sized_trigger_queue')) {
            $ng_natural = (int) $meta['width'];
            $ng_cap     = (int) wpc_get_theme_content_width();


            $ng_sizes_eff = ($wpc_lcp_sizes !== '') ? $wpc_lcp_sizes
                : (isset($attrs['sizes']) ? (string) $attrs['sizes'] : '');
            $ng_targets = function_exists('wpc_v2_ideal_targets_from_sizes')
                ? wpc_v2_ideal_targets_from_sizes($ng_sizes_eff, $ng_cap)
                : [];
            $ng_targets = array_unique(array_map('intval', apply_filters('wpc_ideal_width_targets', $ng_targets, $attrs, $meta)));
            sort($ng_targets);
            $ng_boot = (string) get_option('wpc_nd_bootstrap_rungs', '1') === '1';
            $ng_zone = self::cdn_host();
            $ng_seen = [];
            foreach ($ng_targets as $tw) {
                if ($tw < 200 || $tw >= $ng_natural) continue;


                $ng_skip = false;
                foreach (array_merge(array_keys($by_width), $ng_seen) as $ew) {
                    if ($ew >= $tw && ($ew - $tw) / $tw < 0.08) { $ng_skip = true; break; }
                }
                if ($ng_skip) continue;
                $ng_seen[] = $tw;
                wpc_v2_sized_trigger_queue($att, $tw, $tw);


                if ($ng_boot && $ng_zone !== '' && !self::$jpeg_mode
                    && function_exists('wpc_v2_adaptive_variant_suffix')) {
                    $sfx_b = wpc_v2_adaptive_variant_suffix($tw, $meta);
                    if ($sfx_b !== '' && strpos($sfx_b, 'x') !== false) {
                        $stem_b = preg_replace('/(-scaled)?\.[^.]+$/', '', basename((string) $meta['file']));
                        $u_b = self::build_natural_url($stem_b . $sfx_b . '.webp', $meta);
                        if ($u_b !== '') $by_width[$tw] = $u_b;
                    }
                }
            }
            ksort($by_width);
        }

        foreach ($by_width as $w => $url) $entries[] = $url . ' ' . $w . 'w';


        $max_w = max(array_keys($by_width));
        $orig_src = isset($attrs['src']) ? trim($attrs['src']) : '';
        $src_rel = self::uploads_relative($orig_src);
        $src_url = ($src_rel !== '') ? self::build_natural_url($src_rel, $meta) : '';
        if ($src_url === '') $src_url = $by_width[$max_w];


        $fb_url = '';
        $upl     = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : array();
        $basedir = !empty($upl['basedir']) ? rtrim($upl['basedir'], '/') : '';
        $baseurl = !empty($upl['baseurl']) ? rtrim($upl['baseurl'], '/') : '';
        if ($src_rel !== '' && $basedir !== '' && @file_exists($basedir . '/' . ltrim($src_rel, '/'))) {
            $fb_url = $orig_src;
        } elseif ($baseurl !== '' && !empty($meta['file'])) {


            $dir   = (strpos($meta['file'], '/') !== false) ? substr($meta['file'], 0, strrpos($meta['file'], '/') + 1) : '';
            $best_w = 0; $best_file = '';
            if ($basedir !== '' && !empty($meta['sizes']) && is_array($meta['sizes'])) {
                foreach ($meta['sizes'] as $sz) {
                    if (empty($sz['file']) || empty($sz['width']) || (int) $sz['width'] <= $best_w) continue;
                    if (@file_exists($basedir . '/' . $dir . $sz['file'])) {
                        $best_w = (int) $sz['width'];
                        $best_file = (string) $sz['file'];
                    }
                }
            }
            $fb_url = ($best_file !== '')
                ? $baseurl . '/' . $dir . $best_file
                : $baseurl . '/' . ltrim((string) $meta['file'], '/');
        }


        $skip = ['src' => 1, 'srcset' => 1, 'sizes' => 1, 'class' => 1, 'alt' => 1,
                 'data-src' => 1, 'data-srcset' => 1, 'data-mk-image-src-set' => 1, 'data-prehidden' => 1,
                 'onerror' => 1, 'data-wpc-fb' => 1];


        $nd_w_attr    = isset($attrs['width']) ? (int) preg_replace('/\D/', '', (string) $attrs['width']) : 0;
        $nd_has_basis = ($wpc_lcp_sizes !== '' || (isset($attrs['sizes']) && $attrs['sizes'] !== '') || $nd_w_attr > 0);


        $nd_set_rs     = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        $nd_nobasis_rs = (!$nd_has_basis
            && self::$img_index >= 1
            
            && is_array($meta) && !empty($meta['width']) && !empty($meta['height'])
            && is_array($entries) && count($entries) > 1
            && is_array($nd_set_rs) && !empty($nd_set_rs['lazy-auto-sizes'])
            && !preg_match('/\b(rs|slide|lgx_app|dynamic-image|breakdance)\b/i', (isset($attrs['class']) ? (string) $attrs['class'] : ''))
            && apply_filters('wpc_nd_auto_sizes', true, $attrs));
        
        
        
        
        reset($by_width);
        $nd_single353 = (count($by_width) === 1)
            && ((int) key($by_width) === $nd_w_attr || (int) key($by_width) === (int) (isset($meta['width']) ? $meta['width'] : 0));
        $out = '<img ' . self::MARK . ' src="' . esc_attr($src_url) . '"';
        if (!$nd_single353 && ($nd_has_basis || $nd_nobasis_rs)) {
            $out .= ' srcset="' . esc_attr(implode(', ', $entries)) . '"';
        }


        if ($nd_nobasis_rs && $nd_w_attr === 0 && empty($attrs['height'])
            && !empty($meta['width']) && !empty($meta['height'])) {
            $out .= ' width="' . (int) $meta['width'] . '" height="' . (int) $meta['height'] . '"';
        }


        self::$img_index++;
        if (empty($attrs['loading'])) {
            $nd_s         = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];


            $nd_lazy_off  = is_array($nd_s)
                && isset($nd_s['nativeLazy']) && (string) $nd_s['nativeLazy'] === '0'
                && (!isset($nd_s['lazy']) || (string) $nd_s['lazy'] !== '1');
            $nd_skip_first = (is_array($nd_s) && !empty($nd_s['lazySkipCount'])) ? (int) $nd_s['lazySkipCount'] : 4;
            $nd_cls       = isset($attrs['class']) ? strtolower($attrs['class']) : '';
            $nd_is_slider = (bool) preg_match('/\b(rs|slide|lgx_app|dynamic-image|breakdance)\b/', $nd_cls);
            if (!$nd_lazy_off && !$nd_is_slider && self::$img_index > $nd_skip_first) {
                $attrs['loading'] = 'lazy';
            } else {
                $attrs['loading'] = 'eager';
            }
        }
        if ($nd_nobasis_rs) {
            $attrs['loading'] = 'lazy';
        }
        if (self::$img_index === 1 && !isset($attrs['fetchpriority'])) {
            $attrs['fetchpriority'] = 'high';
        }


        $nd_sizes = '';
        
        
        
        $nd_from_attr349 = false;
        if ($wpc_lcp_sizes !== '') {
            $nd_sizes = (string) $wpc_lcp_sizes;
        } elseif (isset($attrs['sizes']) && $attrs['sizes'] !== '') {
            $nd_sizes = (string) $attrs['sizes'];
            $nd_from_attr349 = !$nd_rw352;
        } elseif ($nd_w_attr > 0) {
            

            $nd_sizes = '(max-width: ' . $nd_w_attr . 'px) 100vw, ' . $nd_w_attr . 'px';
        } elseif ($nd_nobasis_rs && isset($max_w) && (int) $max_w > 0) {


            $nd_sizes = (int) $max_w . 'px';
        }
        
        
        $nd_loading = isset($attrs['loading']) ? (string) $attrs['loading'] : '';
        $nd_dw = isset($attrs['width'])  ? (int) preg_replace('/\D/', '', (string) $attrs['width'])  : 0;
        $nd_dh = isset($attrs['height']) ? (int) preg_replace('/\D/', '', (string) $attrs['height']) : 0;
        $nd_rw = (is_array($meta) && !empty($meta['width']))  ? (int) $meta['width']  : 0;
        $nd_rh = (is_array($meta) && !empty($meta['height'])) ? (int) $meta['height'] : 0;
        if ($nd_sizes !== '' && !$nd_single353) {


            $nd_set = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
            $nd_auto_on = is_array($nd_set) && !empty($nd_set['lazy-auto-sizes']);
            if ($nd_loading === 'lazy'
                
                
                && ($nd_from_attr349 || $nd_nobasis_rs)
                && apply_filters('wpc_nd_auto_sizes', $nd_auto_on, $attrs)


                
                && ($nd_nobasis_rs
                    || (wps_rewriteLogic::lazy_auto_aspect_safe($nd_dw, $nd_dh, $nd_rw, $nd_rh) && $nd_dw > 0 && $nd_dh > 0))
                && stripos($nd_sizes, 'auto') === false) {
                $nd_sizes = 'auto, ' . $nd_sizes;
            }
            $out .= ' sizes="' . esc_attr($nd_sizes) . '"';
        }


        $class = trim((isset($attrs['class']) ? $attrs['class'] : '') . ' wpc-nd');
        $out .= ' class="' . esc_attr($class) . '"';

        
        
        
        if (empty($attrs['width']) && empty($attrs['height']) && $nd_rw > 0 && $nd_rh > 0) {
            $out .= ' width="' . (int) $nd_rw . '" height="' . (int) $nd_rh . '"';
        }


        foreach ($attrs as $k => $v) {
            if (isset($skip[$k]) || $k === 'class') continue;
            $out .= ' ' . $k . '="' . esc_attr($v) . '"';
        }


        $alt = isset($attrs['alt']) ? $attrs['alt'] : '';
        $out .= ' alt="' . esc_attr($alt) . '"';


        if ($fb_url !== '') {
            $out .= ' data-wpc-fb="' . esc_attr($fb_url) . '"';
            $out .= ' onerror="this.onerror=null;this.removeAttribute(\'srcset\');this.src=this.getAttribute(\'data-wpc-fb\');"';
        }

        $out .= '>';
        return $out;
    }


    public static function build_natural_url($sub_file, $meta)
    {
        $host = self::cdn_host();
        if ($host === '' || $sub_file === '') return '';

        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : (function_exists('wp_upload_dir') ? wp_upload_dir() : []);
        if (empty($up['baseurl'])) return '';

        
        $subdir = '';
        if (!empty($meta['file'])) {
            $d = dirname((string) $meta['file']);
            if ($d !== '' && $d !== '.') $subdir = trim($d, '/') . '/';
        }
        $rel = (strpos($sub_file, '/') !== false) ? ltrim($sub_file, '/') : ($subdir . basename($sub_file));

        if (self::$jpeg_mode) {


            $orig_ext = ($meta && !empty($meta['file'])) ? strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION)) : '';
            if ($orig_ext !== '') {
                $rel = preg_replace('/\.(jpe?g|png|gif|webp|avif)$/i', '.' . $orig_ext, $rel);
            }

        } else {


            $orig_ext = ($meta && !empty($meta['file'])) ? strtolower((string) pathinfo((string) $meta['file'], PATHINFO_EXTENSION)) : '';
            $cur_ext  = strtolower((string) pathinfo($rel, PATHINFO_EXTENSION));
            $pick_ext = ($orig_ext !== '') ? $orig_ext : ($cur_ext !== '' ? $cur_ext : 'jpg');


            $cf_cname_nd = defined('WPS_IC_CF_CNAME') && function_exists('get_option') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
            $zone_is_cf_direct = ($cf_cname_nd !== '' && stripos((string) $host, $cf_cname_nd) !== false);
            $fmt = class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_single_url_format')
                ? wps_rewriteLogic::wpc_single_url_format($pick_ext, $zone_is_cf_direct, true)
                : 'webp';
            
            
            $swap_ext = (is_string($fmt) && $fmt !== '') ? $fmt : 'webp';
            $rel = preg_replace('/\.(jpe?g|png|gif|webp|avif)$/i', '.' . $swap_ext, $rel);
        }

        
        $path = parse_url($up['baseurl'], PHP_URL_PATH); 
        $path = $path ? rtrim($path, '/') : '/wp-content/uploads';

        $url = 'https://' . $host . $path . '/' . $rel;
        if (self::$modeb_test) {


            $url .= (strpos($url, '?') === false ? '?' : '&') . '_wpc_m=r&_redirect_target=origin';
        }


        if (!self::$jpeg_mode
            && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'src_hint_enabled')
            && wps_rewriteLogic::src_hint_enabled()) {
            $sh_oe  = isset($orig_ext) ? strtolower((string) $orig_ext) : '';
            $sh_src = in_array($sh_oe, ['png', 'gif', 'webp', 'jpg', 'jpeg'], true) ? $sh_oe : ''; 
            if ($sh_src !== '' && stripos($url, 'src=') === false) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . 'src=' . $sh_src;
            }
        }
        return $url;
    }

    




    private static function parse_attrs($tag)
    {
        $attrs = [];


        if (!preg_match_all('/([a-zA-Z0-9_:-]+(?:--[a-zA-Z0-9_:-]+)*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^>\s]+)))?/', $tag, $m, PREG_SET_ORDER)) {
            return $attrs;
        }
        $first = true;
        foreach ($m as $p) {
            if ($first) { $first = false; continue; }
            $name = strtolower($p[1]);
            if ($name === '') continue;
            $val = '';
            foreach ([2, 3, 4] as $g) {
                if (isset($p[$g]) && $p[$g] !== '') { $val = $p[$g]; break; }
            }
            $attrs[$name] = function_exists('html_entity_decode') ? html_entity_decode($val, ENT_QUOTES) : $val;
        }
        return $attrs;
    }

    



    private static function uploads_relative($url)
    {
        if ($url === '') return '';
        $up = function_exists('wp_get_upload_dir') ? wp_get_upload_dir() : (function_exists('wp_upload_dir') ? wp_upload_dir() : []);
        if (empty($up['baseurl'])) return '';
        $base_path = parse_url($up['baseurl'], PHP_URL_PATH);
        $url_path  = parse_url(preg_replace('/\?.*$/', '', $url), PHP_URL_PATH);
        if (!$base_path || !$url_path) return '';
        $base_path = rtrim($base_path, '/');
        if (strpos($url_path, $base_path . '/') !== 0) return '';
        return ltrim(substr($url_path, strlen($base_path)), '/');
    }

    
    private static function is_local($url)
    {
        $site = function_exists('site_url') ? site_url() : '';
        if ($site === '') return true;
        $sh = parse_url($site, PHP_URL_HOST);
        $uh = parse_url($url, PHP_URL_HOST);
        return ($uh === null || $uh === '' || $uh === $sh);
    }
}
