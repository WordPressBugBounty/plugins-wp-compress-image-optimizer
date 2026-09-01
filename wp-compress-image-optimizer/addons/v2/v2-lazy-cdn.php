<?php
/**
 * WP Compress — Instant Performance & Speed Optimization.
 * File: addons/v2/v2-lazy-cdn.php
 *
 * @package wp-compress-image-optimizer
 * @version 7.22.01
 */

if (!function_exists('wpc_v2_att')) {
    function wpc_v2_att($u)
    {
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_att_id')) {
            return (int) wps_rewriteLogic::wpc_att_id($u);
        }
        return function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($u) : 0;
    }
}


if (!defined('ABSPATH')) {
    exit;
}







if (!function_exists('wpc_v2_sha256_dedup_seen')) {
    function wpc_v2_sha256_dedup_seen($sha256)
    {
        if (!is_string($sha256) || strlen($sha256) < 16) return false;
        return (bool) get_transient('wpc_v2_dedup_' . substr($sha256, 0, 16));
    }
}
if (!function_exists('wpc_v2_sha256_dedup_mark')) {
    function wpc_v2_sha256_dedup_mark($sha256, $ttl_s = 600)
    {
        if (!is_string($sha256) || strlen($sha256) < 16) return;
        $ttl = (int) apply_filters('wpc_v2_sha256_dedup_ttl_s', $ttl_s);
        set_transient('wpc_v2_dedup_' . substr($sha256, 0, 16), 1, max(60, $ttl));
    }
}


if (!function_exists('wpc_v2_lazy_cdn_clear_dedup_transients')) {
    function wpc_v2_lazy_cdn_clear_dedup_transients()
    {
        global $wpdb;
        if (!isset($wpdb) || !is_object($wpdb)) return 0;
        $like_value = $wpdb->esc_like('_transient_wpc_v2_dedup_') . '%';
        $like_timeout = $wpdb->esc_like('_transient_timeout_wpc_v2_dedup_') . '%';

        $deleted_value = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like_value
        ));

        $deleted_timeout = (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $like_timeout
        ));
        return $deleted_value;
    }
}

if (!function_exists('wpc_v2_adaptive_variant_suffix')) {

    function wpc_v2_adaptive_variant_suffix($width, $meta)
    {
        $width = (int) $width;
        if ($width <= 0) return '';
        $mw = (is_array($meta) && !empty($meta['width']))  ? (int) $meta['width']  : 0;
        $mh = (is_array($meta) && !empty($meta['height'])) ? (int) $meta['height'] : 0;
        if ($mw > 0 && $mh > 0) {
            $h = (int) round($width * $mh / $mw);
            if ($h > 0) return '-' . $width . 'x' . $h;
        }
        return '-' . $width . 'w';
    }
}

if (!function_exists('wpc_v2_lazy_purge_enqueue')) {
    
    


    
    function wpc_v2_lazy_purge_enqueue($url = null, $flush = false)
    {
        static $queue  = [];
        static $hooked = false;
        if ($flush) {
            if (empty($queue) || !function_exists('wpc_customer_purge') || !function_exists('wpc_v2_get_apikey')) {
                $queue = [];
                return;
            }
            $urls = array_values(array_unique($queue));
            $queue = [];
            $key = (string) wpc_v2_get_apikey();
            if ($key === '') {
                return;
            }


            if (function_exists('set_time_limit')) { @set_time_limit(120); }
            $chunks = array_chunk($urls, 200);
            if (count($chunks) > 8) {
                error_log('[WPC LazyCDN] purge flush capped: ' . count($chunks) . ' chunks -> 8 (' . count($urls) . ' urls)');
                $chunks = array_slice($chunks, 0, 8);
            }
            foreach ($chunks as $chunk) {
                wpc_customer_purge($key, 'urls', $chunk, 'variant_landed', true);
            }
            return;
        }
        if (!is_string($url) || $url === '') {
            return;
        }
        $queue[] = $url;
        if (!$hooked && function_exists('add_action')) {
            $hooked = true;
            
            
            add_action('shutdown', function () { wpc_v2_lazy_purge_enqueue(null, true); }, 9);
        }
    }
}

if (!function_exists('wpc_v2_enqueue_landed_purge')) {

    function wpc_v2_enqueue_landed_purge($abs_path)
    {
        if (!function_exists('wpc_v2_lazy_purge_enqueue') || !function_exists('wp_get_upload_dir')) return;
        $up = wp_get_upload_dir();
        if (empty($up['basedir']) || empty($up['baseurl']) || strpos((string) $abs_path, (string) $up['basedir']) !== 0) return;
        $base_rel = function_exists('wp_make_link_relative')
            ? wp_make_link_relative($up['baseurl'])
            : (string) wp_parse_url($up['baseurl'], PHP_URL_PATH);
        $rel = $base_rel . substr($abs_path, strlen($up['basedir']));
        if ($rel === '' || !preg_match('/\.(avif|webp|jpe?g|png)$/i', $rel)) return;
        
        
        $targets = [$rel];
        foreach (['avif', 'webp'] as $ext) {
            $sib = preg_replace('/\.(avif|webp|jpe?g|png)$/i', '.' . $ext, $rel);
            if ($sib && $sib !== $rel) $targets[] = $sib;
        }
        foreach (array_unique($targets) as $t) {
            wpc_v2_lazy_purge_enqueue($t);
        }
    }
}

if (!function_exists('wpc_v2_lazy_resolve_attachment')) {


    function wpc_v2_lazy_resolve_attachment($origin_url, $relative = '')
    {
        if (!function_exists('attachment_url_to_postid')) return 0;
        $clean = preg_replace('/\?.*$/', '', (string) $origin_url);
        $wpc_memo896 = class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_att_id');
        $id = $wpc_memo896 ? (int) wps_rewriteLogic::wpc_att_id($clean) : wpc_v2_att($clean);
        if ($id > 0) return $id;
        
        $scaled = preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $clean);
        if ($scaled !== $clean) {
            $id = $wpc_memo896 ? (int) wps_rewriteLogic::wpc_att_id($scaled) : wpc_v2_att($scaled);
            if ($id > 0) return $id;
        }
        
        
        global $wpdb;
        if (isset($wpdb) && is_object($wpdb) && $relative !== '') {
            $rel_clean = preg_replace('/\?.*$/', '', $relative);
            foreach ([$rel_clean, preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $rel_clean)] as $cand) {
                $pid = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s LIMIT 1",
                    $cand
                ));
                if ($pid > 0) return $pid;
            }
        }
        return 0;
    }
}

if (!function_exists('wpc_v2_lazy_ensure_dims')) {


    function wpc_v2_lazy_ensure_dims($meta, $src_abs)
    {
        if (is_array($meta) && !empty($meta['width']) && !empty($meta['height'])) return $meta;
        if ($src_abs && @is_file($src_abs)) {
            $d = @getimagesize($src_abs);
            if (is_array($d) && !empty($d[0]) && !empty($d[1])) {
                $m = is_array($meta) ? $meta : [];
                $m['width']  = (int) $d[0];
                $m['height'] = (int) $d[1];
                return $m;
            }
        }
        return $meta;
    }
}

if (!function_exists('wpc_v2_lazy_outcome')) {


    function wpc_v2_lazy_outcome($reason)
    {
        $h = get_option('wpc_v2_ingest_outcomes', []);
        if (!is_array($h)) $h = [];
        $h[$reason] = (isset($h[$reason]) ? (int) $h[$reason] : 0) + 1;
        $h['_last'] = $reason; $h['_t'] = time();


        if (function_exists('wpc_v2_ingest_diag_on') && wpc_v2_ingest_diag_on()
            && (!function_exists('wpc_v2_telemetry_throttle') || wpc_v2_telemetry_throttle('ingest_outcomes', 15))) {
            update_option('wpc_v2_ingest_outcomes', $h, false);
        }
        return $reason;
    }
}
if (!function_exists('wpc_v2_lazy_cdn_derive_abs_path')) {

    function wpc_v2_lazy_cdn_derive_abs_path($origin_url, $size_label, $format, $entry = [])
    {
        if (!is_string($origin_url) || $origin_url === '') {
            return ['ok' => false, 'reason' => 'missing_origin_url'];
        }
        if (!is_string($format) || !in_array($format, ['avif', 'webp', 'jpeg', 'jpg', 'png'], true)) {
            return ['ok' => false, 'reason' => 'invalid_format'];
        }

        $upload = wp_get_upload_dir();
        if (empty($upload['basedir'])) {
            return ['ok' => false, 'reason' => 'no_upload_basedir'];
        }

        
        $parsed = wp_parse_url($origin_url);
        if (empty($parsed['path'])) {
            return ['ok' => false, 'reason' => 'unparsable_origin_url'];
        }

        
        


        $site_host   = wp_parse_url(site_url(), PHP_URL_HOST);
        $origin_host = isset($parsed['host']) ? (string) $parsed['host'] : '';
        if ($origin_host !== '' && $site_host !== '' && strcasecmp($origin_host, $site_host) !== 0) {
            


            $zone_ok = false;
            if (function_exists('get_option')) {


                $wpc_zone_cands = [(string) get_option('ic_custom_cname'), (string) get_option('ic_cdn_zone_name')];
                if (defined('WPS_IC_CF_CNAME')) {
                    $wpc_zone_cands[] = (string) get_option(WPS_IC_CF_CNAME);
                }
                foreach ($wpc_zone_cands as $zh) {
                    if ($zh === '') {
                        continue;
                    }
                    $zh_host = (string) wp_parse_url(strpos($zh, '//') === false ? 'https://' . $zh : $zh, PHP_URL_HOST);
                    if ($zh_host !== '' && strcasecmp($origin_host, $zh_host) === 0) {
                        $zone_ok = true;
                        break;
                    }
                }
            }


            if (!$zone_ok && preg_match('/\.zapwp\.com$/i', $origin_host)) {
                $zone_ok = true;
            }
            if (!$zone_ok) {
                return ['ok' => false, 'reason' => 'host_mismatch', 'origin_host' => $origin_host];
            }


            $origin_url = preg_replace('#^https?://[^/]+#', rtrim(site_url(), '/'), $origin_url);
            
            $parsed = wp_parse_url($origin_url);
            if (empty($parsed['path'])) {
                return ['ok' => false, 'reason' => 'unparsable_origin_url_post_normalize'];
            }
        }

        
        $baseurl_path = (string) wp_parse_url($upload['baseurl'], PHP_URL_PATH);
        $baseurl_path = trim($baseurl_path, '/');  
        $url_path     = trim((string) $parsed['path'], '/');  


        if ($baseurl_path !== '' && strpos($url_path, $baseurl_path) !== 0) {
            return ['ok' => false, 'reason' => 'origin_outside_uploads'];
        }
        
        $relative = $baseurl_path !== ''
            ? ltrim(substr($url_path, strlen($baseurl_path)), '/')
            : $url_path;

        if ($relative === '') {
            return ['ok' => false, 'reason' => 'empty_relative_path'];
        }

        
        if (strpos($relative, '..') !== false || strpos($relative, "\0") !== false) {
            return ['ok' => false, 'reason' => 'path_traversal_attempt'];
        }

        
        $dir      = ltrim(dirname($relative), '/.');  
        $basename = basename($relative);
        $base_no_ext = preg_replace('/\.[^.]+$/', '', $basename);
        if ($base_no_ext === '' || $base_no_ext === null) {
            return ['ok' => false, 'reason' => 'empty_basename'];
        }


        $explicit_filename = '';
        $fn_candidate = '';
        foreach (['filename', 'rendition_filename'] as $fk) {
            if (is_array($entry) && !empty($entry[$fk])) { $fn_candidate = (string) $entry[$fk]; break; }
            if (is_array($entry) && isset($entry['tags'][$fk]) && $entry['tags'][$fk] !== '') { $fn_candidate = (string) $entry['tags'][$fk]; break; }
        }
        if ($fn_candidate !== '') {
            $candidate = basename((string) $fn_candidate);
            $expected_ext = ($format === 'jpeg') ? 'jpg' : $format;


            $dot  = strrpos($candidate, '.');
            $stem = $dot !== false ? substr($candidate, 0, $dot) : '';
            $rem  = null;
            if ($stem === $base_no_ext) {
                $rem = '';
            } elseif ($base_no_ext !== '' && strpos($stem, $base_no_ext . '-') === 0) {
                $rem = substr($stem, strlen($base_no_ext)); 
            }
            if ($candidate !== ''
                && strpos($candidate, '..') === false
                && strpos($candidate, "\0") === false
                && strpos($candidate, '/') === false
                && strpos($candidate, '\\') === false
                && $dot !== false && $dot > 0
                && strcasecmp(substr($candidate, $dot + 1), $expected_ext) === 0
                && $rem !== null
                && strpos($rem, '.') === false
                && !preg_match('/^(con|prn|aux|nul|com[1-9]|lpt[1-9])$/i', $stem)) {
                $explicit_filename = $candidate;
            }
        }

        $out_ext = ($format === 'jpeg') ? 'jpg' : $format;


        
        if ($explicit_filename === ''
            && preg_match('/-(\d{2,4})x(\d{2,4})$/', $base_no_ext, $szm)
            && is_string($size_label)
            && preg_match('/^(?:w)?(\d{2,4})w?$/i', trim($size_label), $szl)
            && (int) $szl[1] === (int) $szm[1]) {
            $explicit_filename = $base_no_ext . '.' . $out_ext;
        }

        if ($explicit_filename !== '') {
            $filename = $explicit_filename;
        } else {
            
            $suffix = '';
            $label_lc = is_string($size_label) ? strtolower(trim($size_label)) : '';
            if ($label_lc === '' || $label_lc === 'original') {
                $suffix = '';
            } elseif ($label_lc === 'scaled') {
                $suffix = '-scaled';
            } elseif (preg_match('/^w(\d+)h(\d+)$/i', $size_label, $m) || preg_match('/^(\d+)x(\d+)$/', $size_label, $m)) {


                $w = (int) $m[1];
                $h = (int) $m[2];
                $attachment_id = wpc_v2_lazy_resolve_attachment($origin_url, $relative);
                $meta = $attachment_id > 0 ? wp_get_attachment_metadata($attachment_id) : false;


                if (!is_array($meta)) {
                    $dd = wpc_v2_lazy_ensure_dims([], $upload['basedir'] . '/' . $relative);
                    $meta = !empty($dd['width']) ? $dd : false;
                }
                $resolved = false;
                if (is_array($meta)) {
                    
                    if (isset($meta['width'], $meta['height']) && (int) $meta['width'] === $w && (int) $meta['height'] === $h) {
                        $main_file = isset($meta['file']) ? basename((string) $meta['file']) : '';
                        if ($main_file && stripos($main_file, '-scaled.') !== false) {
                            $suffix = '-scaled';
                        }

                        $resolved = true;
                    }
                    
                    if (!$resolved && isset($meta['sizes']) && is_array($meta['sizes'])) {
                        foreach ($meta['sizes'] as $sz) {
                            if (!is_array($sz) || empty($sz['file']) || empty($sz['width']) || empty($sz['height'])) continue;
                            if ((int) $sz['width'] === $w && (int) $sz['height'] === $h) {
                                $sub_base = basename((string) $sz['file']);
                                $sub_no_ext = preg_replace('/\.[^.]+$/', '', $sub_base);
                                if ($sub_no_ext !== '' && $sub_no_ext !== null) {
                                    $base_no_ext = $sub_no_ext;
                                    $suffix = '';
                                    $resolved = true;
                                    break;
                                }
                            }
                        }
                    }


                    
                    if (!$resolved && isset($meta['width'], $meta['height'])
                        && (int) $meta['width'] > 0 && (int) $meta['height'] > 0
                        && ($w >= (int) $meta['width'] || $h >= (int) $meta['height'])) {
                        $main_file = isset($meta['file']) ? basename((string) $meta['file']) : '';
                        $suffix = ($main_file !== '' && stripos($main_file, '-scaled.') !== false) ? '-scaled' : '';
                        $resolved = true;
                    }
                }
                if (!$resolved) {
                    
                    


                    $meta = wpc_v2_lazy_ensure_dims($meta, $upload['basedir'] . '/' . $relative);
                    $suffix = wpc_v2_adaptive_variant_suffix($w, $meta);
                }
            } elseif (preg_match('/^w(\d+)$/i', $size_label, $m) || preg_match('/^(\d+)w$/i', $size_label, $m)) {
                


                $width = (int) $m[1];
                $attachment_id = wpc_v2_lazy_resolve_attachment($origin_url, $relative);
                $meta = $attachment_id > 0 ? wp_get_attachment_metadata($attachment_id) : false;
                
                
                if (!is_array($meta)) {
                    $dd = wpc_v2_lazy_ensure_dims([], $upload['basedir'] . '/' . $relative);
                    $meta = !empty($dd['width']) ? $dd : false;
                }
                $resolved = false;
                if (is_array($meta)) {
                    
                    if (isset($meta['width']) && (int) $meta['width'] === $width) {
                        $main_file = isset($meta['file']) ? basename((string) $meta['file']) : '';
                        if ($main_file && stripos($main_file, '-scaled.') !== false) {
                            $suffix = '-scaled';
                        }
                        $resolved = true;
                    }
                    
                    if (!$resolved && isset($meta['sizes']) && is_array($meta['sizes'])) {
                        foreach ($meta['sizes'] as $sz) {
                            if (!is_array($sz) || empty($sz['file']) || empty($sz['width'])) continue;
                            if ((int) $sz['width'] === $width) {
                                $sub_base = basename((string) $sz['file']);
                                $sub_no_ext = preg_replace('/\.[^.]+$/', '', $sub_base);
                                if ($sub_no_ext !== '' && $sub_no_ext !== null) {
                                    $base_no_ext = $sub_no_ext;
                                    $suffix = '';
                                    $resolved = true;
                                    break;
                                }
                            }
                        }
                    }


                    if (!$resolved && isset($meta['width']) && (int) $meta['width'] > 0 && $width >= (int) $meta['width']) {
                        $main_file = isset($meta['file']) ? basename((string) $meta['file']) : '';
                        $suffix = ($main_file !== '' && stripos($main_file, '-scaled.') !== false) ? '-scaled' : '';
                        $resolved = true;
                    }
                }


                if (!$resolved) {


                    $meta = wpc_v2_lazy_ensure_dims($meta, $upload['basedir'] . '/' . $relative);
                    $suffix = wpc_v2_adaptive_variant_suffix($width, $meta);
                }
            } elseif (preg_match('/^[a-z][a-z0-9_]*$/i', $size_label)) {
                
                $attachment_id = 0;
                if (function_exists('attachment_url_to_postid')) {
                    $attachment_id = wpc_v2_att(preg_replace('/\?.*$/', '', $origin_url));
                }
                $meta = $attachment_id > 0 ? wp_get_attachment_metadata($attachment_id) : false;
                if (is_array($meta) && isset($meta['sizes'][$size_label]['file'])) {
                    $sub_base = basename((string) $meta['sizes'][$size_label]['file']);
                    $sub_no_ext = preg_replace('/\.[^.]+$/', '', $sub_base);
                    if ($sub_no_ext !== '' && $sub_no_ext !== null) {
                        $base_no_ext = $sub_no_ext;
                        $suffix = '';
                    } else {
                        return ['ok' => false, 'reason' => 'sub_size_empty_file'];
                    }
                } else {
                    return ['ok' => false, 'reason' => 'unknown_sub_size_name'];
                }
            } else {
                
                return ['ok' => false, 'reason' => 'unparsable_size_label', 'sizeLabel' => (string) $size_label];
            }


            if ($suffix === '-scaled' && strlen($base_no_ext) >= 7 && substr($base_no_ext, -7) === '-scaled') {
                $suffix = '';
            }
            $filename = $base_no_ext . $suffix . '.' . $out_ext;
        }

        


        if (preg_match('/-(\d+)x(\d+)\.[a-z0-9]+$/i', $filename, $degm)
            && ((int) $degm[1] <= 2 || (int) $degm[2] <= 2)) {
            return ['ok' => false, 'reason' => 'degenerate_variant_dimensions', 'filename' => $filename];
        }

        
        $abs_path = rtrim($upload['basedir'], '/\\') . '/' . ($dir !== '' ? $dir . '/' : '') . $filename;

        
        
        $basedir_real = realpath($upload['basedir']);
        if ($basedir_real !== false) {
            $abs_real_prefix = rtrim($basedir_real, '/\\');
            $dest_dir = dirname($abs_path);
            $dest_dir_real = realpath($dest_dir);
            if ($dest_dir_real !== false && strpos($dest_dir_real, $abs_real_prefix) !== 0) {
                return ['ok' => false, 'reason' => 'composed_path_outside_basedir'];
            }
        }

        return ['ok' => true, 'abs_path' => $abs_path];
    }
}






if (!function_exists('wpc_v2_lazy_cdn_should_skip_multisite')) {
    function wpc_v2_lazy_cdn_should_skip_multisite()
    {
        return function_exists('is_multisite') && is_multisite();
    }
}


if (!function_exists('wpc_v2_lazy_cdn_write_postmeta')) {
    function wpc_v2_lazy_cdn_write_postmeta($origin_url, $abs_path, $size_bytes, $format, $attachment_id = 0)
    {
        if (!function_exists('wpc_v2_merge_variant') || !function_exists('wpc_v2_variant_key')) {
            return false;
        }
        $attachment_id = (int) $attachment_id;
        if ($attachment_id <= 0 && function_exists('attachment_url_to_postid')) {
            $clean_origin = preg_replace('/\?.*$/', '', (string) $origin_url);
            $attachment_id = wpc_v2_att($clean_origin);
            if ($attachment_id <= 0) {
                $scaled_origin = preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $clean_origin);
                if ($scaled_origin !== $clean_origin) {
                    $attachment_id = wpc_v2_att($scaled_origin);
                }
            }
        }
        if ($attachment_id <= 0) return false;

        $meta = function_exists('wp_get_attachment_metadata')
            ? wp_get_attachment_metadata($attachment_id)
            : false;

        $saved_filename = basename((string) $abs_path);
        $saved_no_ext   = (string) preg_replace('/\.[^.]+$/', '', $saved_filename);

        $resolved_label  = '';
        $source_jpg_path = '';

        if (is_array($meta)) {
            
            if (!empty($meta['file'])) {
                $main_no_ext = preg_replace('/\.[^.]+$/', '', basename((string) $meta['file']));
                if ($main_no_ext === $saved_no_ext) {
                    $main_file = basename((string) $meta['file']);
                    $resolved_label = (stripos($main_file, '-scaled.') !== false) ? 'scaled' : 'unscaled';
                    $source_jpg_path = function_exists('get_attached_file')
                        ? (string) get_attached_file($attachment_id)
                        : '';
                }
            }
            
            if ($resolved_label === '' && !empty($meta['sizes']) && is_array($meta['sizes'])) {
                $upload_dir_meta = wp_get_upload_dir();
                $main_dir = !empty($meta['file']) ? dirname((string) $meta['file']) : '';
                foreach ($meta['sizes'] as $sz_name => $sz_data) {
                    if (empty($sz_data['file'])) continue;
                    $sz_no_ext = preg_replace('/\.[^.]+$/', '', basename((string) $sz_data['file']));
                    if ($sz_no_ext === $saved_no_ext) {
                        $resolved_label = (string) $sz_name;
                        $sub_rel = ($main_dir !== '' && $main_dir !== '.')
                            ? ($main_dir . '/' . basename((string) $sz_data['file']))
                            : basename((string) $sz_data['file']);
                        $source_jpg_path = rtrim((string) $upload_dir_meta['basedir'], '/') . '/' . ltrim($sub_rel, '/');
                        break;
                    }
                }
            }
        }
        
        if ($resolved_label === '' && $saved_no_ext !== '') {
            if (preg_match('/-(\d+)w$/', $saved_no_ext, $sm)) {
                $resolved_label = $sm[1] . 'w';
            } elseif (preg_match('/-(\d+)x(\d+)$/', $saved_no_ext, $sm)) {
                $resolved_label = $sm[1] . 'x' . $sm[2];
            } else {
                $resolved_label = $saved_no_ext;
            }
        }
        if ($resolved_label === '') return false;


        $is_adaptive_label = (bool) preg_match('/^\d+w$/', $resolved_label)
                            || (bool) preg_match('/^\d+x\d+$/', $resolved_label);
        if ($is_adaptive_label && $source_jpg_path === '' && is_array($meta)) {
            $target_w = 0;
            if (preg_match('/^(\d+)w$/', $resolved_label, $wm)) {
                $target_w = (int) $wm[1];
            } elseif (preg_match('/^(\d+)x\d+$/', $resolved_label, $wm)) {
                $target_w = (int) $wm[1];
            }
            if ($target_w > 0) {
                $upload_dir_meta = wp_get_upload_dir();
                $main_dir = !empty($meta['file']) ? dirname((string) $meta['file']) : '';
                $best_w = PHP_INT_MAX;
                $best_file = '';
                $largest_w = 0;
                $largest_file = '';
                
                if (!empty($meta['sizes']) && is_array($meta['sizes'])) {
                    foreach ($meta['sizes'] as $sz_data) {
                        if (empty($sz_data['file']) || empty($sz_data['width'])) continue;
                        $sw = (int) $sz_data['width'];
                        if ($sw >= $target_w && $sw < $best_w) {
                            $best_w = $sw;
                            $best_file = basename((string) $sz_data['file']);
                        }
                        if ($sw > $largest_w) {
                            $largest_w = $sw;
                            $largest_file = basename((string) $sz_data['file']);
                        }
                    }
                }
                
                if (!empty($meta['file']) && !empty($meta['width'])) {
                    $sw = (int) $meta['width'];
                    $f  = basename((string) $meta['file']);
                    if ($sw >= $target_w && $sw < $best_w) {
                        $best_w = $sw;
                        $best_file = $f;
                    }
                    if ($sw > $largest_w) {
                        $largest_w = $sw;
                        $largest_file = $f;
                    }
                }
                
                $pick_file = $best_file !== '' ? $best_file : $largest_file;
                if ($pick_file !== '') {
                    $sub_rel = ($main_dir !== '' && $main_dir !== '.')
                        ? $main_dir . '/' . $pick_file
                        : $pick_file;
                    $source_jpg_path = rtrim((string) $upload_dir_meta['basedir'], '/') . '/' . ltrim($sub_rel, '/');
                }
            }
        }

        $upload_dir_for_url = wp_get_upload_dir();
        $variant_url = '';
        if (!empty($upload_dir_for_url['baseurl']) && !empty($upload_dir_for_url['basedir'])) {
            $rel = ltrim(str_replace(
                rtrim($upload_dir_for_url['basedir'], '/'),
                '',
                $abs_path
            ), '/');
            $variant_url = rtrim($upload_dir_for_url['baseurl'], '/') . '/' . $rel;
        }

        $variant_key = wpc_v2_variant_key($resolved_label, $format);
        $now_ms      = (int) round(microtime(true) * 1000);
        $size_bytes  = (int) $size_bytes;

        $variant_entry = [
            'size'           => $size_bytes,
            'url'            => $variant_url,
            'local'          => true,
            'skipped'        => false,
            'bg_upgraded'    => time(),
            'bg_upgraded_ms' => $now_ms,
            'phase_b_v2'     => true,
            'lazy_cdn'       => true,
        ];
        if ($source_jpg_path !== '' && @is_file($source_jpg_path)) {
            $orig_size = (int) @filesize($source_jpg_path);
            if ($orig_size > 0) {
                $variant_entry['originalSize'] = $orig_size;
                $variant_entry['savings'] = ($size_bytes > 0 && $size_bytes < $orig_size)
                    ? max(0, (int) round((1 - $size_bytes / $orig_size) * 100))
                    : 0;
            }
        }

        wpc_v2_merge_variant($attachment_id, $variant_key, $variant_entry);

        if (function_exists('wpc_v2_recompute_savings')) {
            wpc_v2_recompute_savings($attachment_id);
        }


        $current_status = get_post_meta($attachment_id, 'ic_status', true);
        if ($current_status !== 'compressed') {
            $current_compressing = get_post_meta($attachment_id, 'ic_compressing', true);
            $compressing_status  = (is_array($current_compressing) && !empty($current_compressing['status']))
                ? (string) $current_compressing['status']
                : '';
            if ($compressing_status !== 'optimizing' && $compressing_status !== 'queueing') {
                update_post_meta($attachment_id, 'ic_status', 'compressed');
                
                if (function_exists('wpc_invalidate_local_cache')) wpc_invalidate_local_cache();
                if ($compressing_status !== 'compressed') {
                    update_post_meta($attachment_id, 'ic_compressing', ['status' => 'compressed']);
                }
            }
        }

        return true;
    }
}




if (!function_exists('wpc_v2_lazy_fail_note')) {
    function wpc_v2_lazy_fail_note($reason, $detail = '')
    {


        $GLOBALS['wpc_v2_lif_mem'] = ['t' => time(), 'reason' => (string) $reason, 'detail' => substr((string) $detail, 0, 120)];
        
        
        if (function_exists('wpc_v2_ingest_diag_on') && wpc_v2_ingest_diag_on()
            && (!function_exists('wpc_v2_telemetry_throttle') || wpc_v2_telemetry_throttle('ingest_fail', 15))) {
            update_option('wpc_v2_last_ingest_fail', $GLOBALS['wpc_v2_lif_mem'], false);
        }
    }
}
if (!function_exists('wpc_v2_lazy_cdn_ingest')) {
    function wpc_v2_lazy_cdn_ingest($entry)
    {
        if (!is_array($entry)) return false;


        if (function_exists('wpc_v2_get_lazy_enabled') && !wpc_v2_get_lazy_enabled()) {
            
            


            
            $wpc_nd_consumes = class_exists('WPC_Negotiated_Delivery')
                && method_exists('WPC_Negotiated_Delivery', 'is_active')
                && WPC_Negotiated_Delivery::is_active();


            $wpc_pic_consumes = false;
            if (!$wpc_nd_consumes && class_exists('WPC_Delivery_Resolver')
                && method_exists('WPC_Delivery_Resolver', 'effective_ceiling')) {
                $wpc_ceiling = (string) WPC_Delivery_Resolver::effective_ceiling(get_option('wps_ic_settings'));
                $wpc_pic_consumes = in_array($wpc_ceiling, ['webp', 'avif'], true);
            }
            if (!$wpc_nd_consumes && !$wpc_pic_consumes) {
                error_log('[WPC LazyCDN] ingest declined: lazy_cdn off, no nd consumer (mode='
                    . (function_exists('wpc_get_optimization_mode') ? wpc_get_optimization_mode() : '?') . ')');
                wpc_v2_lazy_outcome('ack_consumer_gate'); return true;
            }
        }

        if (wpc_v2_lazy_cdn_should_skip_multisite()) {
            error_log('[WPC LazyCDN] skipped: multisite not supported in v7.05.0');
            wpc_v2_lazy_outcome('ack_multisite'); return true;
        }

        $sha256 = isset($entry['sha256']) ? (string) $entry['sha256'] : '';
        if ($sha256 === '' || strlen($sha256) < 16) {
            wpc_v2_lazy_fail_note('invalid_sha_field'); error_log('[WPC LazyCDN] reject: missing or invalid sha256');
            return false;
        }


        $origin_url = '';
        if (isset($entry['origin_url'])) {
            $origin_url = (string) $entry['origin_url'];
        } elseif (isset($entry['tags']['origin_url'])) {
            $origin_url = (string) $entry['tags']['origin_url'];
        }


        if ($origin_url !== '' && function_exists('get_option')) {
            $origin_host_for_norm = wp_parse_url($origin_url, PHP_URL_HOST);
            $site_host_for_norm   = wp_parse_url(site_url(), PHP_URL_HOST);
            if ($origin_host_for_norm && $site_host_for_norm
                && strcasecmp((string) $origin_host_for_norm, (string) $site_host_for_norm) !== 0) {


                $wpc_norm_cands = [(string) get_option('ic_custom_cname'), (string) get_option('ic_cdn_zone_name')];
                if (defined('WPS_IC_CF_CNAME')) {
                    $wpc_norm_cands[] = (string) get_option(WPS_IC_CF_CNAME);
                }
                $wpc_norm_match = false;
                foreach ($wpc_norm_cands as $zh) {
                    if ($zh === '') {
                        continue;
                    }
                    $zh_host = (string) wp_parse_url(strpos($zh, '//') === false ? 'https://' . $zh : $zh, PHP_URL_HOST);
                    if ($zh_host !== '' && strcasecmp((string) $origin_host_for_norm, $zh_host) === 0) {
                        $wpc_norm_match = true;
                        break;
                    }
                }
                if ($wpc_norm_match) {


                    $origin_url = preg_replace('#^https?://[^/]+#', rtrim(site_url(), '/'), $origin_url);
                }
            }
        }

        $size_label = isset($entry['sizeLabel']) ? (string) $entry['sizeLabel'] : '';
        $format     = isset($entry['format'])    ? strtolower((string) $entry['format']) : '';
        $fetch_url  = isset($entry['fetchUrl'])  ? (string) $entry['fetchUrl']  : '';

        if ($origin_url === '' || $format === '' || $fetch_url === '') {
            error_log(sprintf(
                '[WPC LazyCDN] reject: missing required fields. origin_url=%s format=%s fetchUrl=%s imageID=%s entry_keys=%s',
                $origin_url === '' ? '(missing)' : 'set',
                $format === '' ? '(missing)' : $format,
                $fetch_url === '' ? '(missing)' : ('set,len=' . strlen($fetch_url)),
                isset($entry['imageID']) ? (string) $entry['imageID'] : '(missing)',
                is_array($entry) ? implode(',', array_keys($entry)) : 'not-array'
            ));
            
            wpc_v2_lazy_fail_note('missing_required_fields', sprintf('origin=%s fmt=%s fetch=%s', $origin_url === '' ? 'missing' : 'set', $format === '' ? 'missing' : $format, $fetch_url === '' ? 'missing' : 'set'));
            return false;
        }

        
        $derived = wpc_v2_lazy_cdn_derive_abs_path($origin_url, $size_label, $format, $entry);
        if (empty($derived['ok'])) {
            error_log(sprintf(
                '[WPC LazyCDN] reject reason=%s origin_url=%s sizeLabel=%s format=%s',
                isset($derived['reason']) ? $derived['reason'] : 'unknown',
                $origin_url, $size_label, $format
            ));
            
            
            wpc_v2_lazy_fail_note('derive_' . (isset($derived['reason']) ? $derived['reason'] : 'failed'), $origin_url);
            return false;
        }
        $abs_path = $derived['abs_path'];


        if (function_exists('attachment_url_to_postid')) {
            $rg_clean = preg_replace('/\?.*$/', '', (string) $origin_url);
            $rg_base  = preg_replace('/-\d{2,4}x\d{2,4}(\.\w+)$/', '$1', $rg_clean);
            $rg_id    = wpc_v2_att($rg_clean);
            if ($rg_id <= 0 && $rg_base !== $rg_clean) $rg_id = wpc_v2_att($rg_base);
            if ($rg_id <= 0) $rg_id = wpc_v2_att(preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $rg_base));
            if ($rg_id > 0 && get_transient('wpc_v2_callbacks_blocked_' . $rg_id) !== false) {
                $rg_target = basename($abs_path);
                $rg_protected = [];
                $rg_att = get_attached_file($rg_id);
                if ($rg_att) $rg_protected[] = basename($rg_att);
                $rg_orig = function_exists('wp_get_original_image_path') ? wp_get_original_image_path($rg_id) : '';
                if ($rg_orig) $rg_protected[] = basename($rg_orig);
                if (in_array($rg_target, $rg_protected, true)) {
                    error_log('[WPC LazyCDN] restored_reject (parent overwrite) imageID=' . $rg_id . ' target=' . $rg_target);
                    wpc_v2_lazy_outcome('ack_restored_reject'); return true;
                }
            }
        }


        if (wpc_v2_sha256_dedup_seen($sha256) && @file_exists($abs_path)) {
            wpc_v2_lazy_outcome('ack_dedup_ondisk'); return true;
        }


        if (file_exists($abs_path)) {
            $existing_size = filesize($abs_path);
            $expected_size = isset($entry['bytes']) ? (int) $entry['bytes'] : 0;
            if ($expected_size > 0 && $existing_size === $expected_size) {
                $on_disk_sha = @hash_file('sha256', $abs_path);
                if ($on_disk_sha !== false && hash_equals($on_disk_sha, $sha256)) {
                    wpc_v2_sha256_dedup_mark($sha256);
                    if (function_exists('wpc_v2_lazy_cdn_write_postmeta')) {
                        wpc_v2_lazy_cdn_write_postmeta($origin_url, $abs_path, (int) $existing_size, $format);
                    }
                    wpc_v2_lazy_outcome('ack_idempotent_ondisk'); return true;
                }
            }
        }

        
        
        $dest_dir = dirname($abs_path);
        if (!is_dir($dest_dir)) {
            if (!wp_mkdir_p($dest_dir)) {
                wpc_v2_lazy_fail_note('mkdir_failed', $dest_dir); error_log('[WPC LazyCDN] reject: mkdir_failed dir=' . $dest_dir);
                return false;
            }
        }


        $wpc_out_ext = strtolower((string) pathinfo($abs_path, PATHINFO_EXTENSION));
        if (!in_array($wpc_out_ext, ['avif', 'webp'], true)) {
            wpc_v2_lazy_outcome('ack_non_nextgen');
            error_log('[WPC LazyCDN] ack-skip: non-next-gen variant ext=' . $wpc_out_ext . ' (lazy writes avif/webp only) ' . substr($abs_path, -50));
            return true;
        }

        
        $resp = wp_remote_get($fetch_url, [
            'timeout'   => 30,
            'sslverify' => true,
            'headers'   => ['User-Agent' => 'WPCompress/' . (defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '7.05.0')],
        ]);
        if (is_wp_error($resp)) {
            wpc_v2_lazy_fail_note('bytes_fetch_error', $resp->get_error_message()); error_log('[WPC LazyCDN] fetch_error: ' . $resp->get_error_message());
            return false;
        }
        $http_code = (int) wp_remote_retrieve_response_code($resp);
        if ($http_code !== 200) {
            wpc_v2_lazy_fail_note('bytes_fetch_non_200', 'code=' . $http_code); error_log('[WPC LazyCDN] fetch_non_200 code=' . $http_code . ' url_tail=' . substr($fetch_url, -50));
            return false;
        }
        $bytes = wp_remote_retrieve_body($resp);
        if (!is_string($bytes) || $bytes === '') {
            wpc_v2_lazy_fail_note('bytes_fetch_empty'); error_log('[WPC LazyCDN] fetch_empty_body url_tail=' . substr($fetch_url, -50));
            return false;
        }


        $bytes_sha = hash('sha256', $bytes);
        if (!hash_equals($bytes_sha, $sha256)) {
            wpc_v2_lazy_fail_note('sha_mismatch', substr($sha256, 0, 12));
            error_log(sprintf(
                '[WPC LazyCDN] sha256_mismatch expected=%s got=%s url_tail=%s',
                substr($sha256, 0, 12), substr($bytes_sha, 0, 12), substr($fetch_url, -50)
            ));
            return false;
        }

        
        

        $tmp = $abs_path . '.wpc_lazycdn_tmp_' . wp_generate_password(8, false);
        if (@file_put_contents($tmp, $bytes) === false) {
            $err = error_get_last();
            wpc_v2_lazy_fail_note('disk_write_failed', substr($abs_path, -60));
            error_log(sprintf(
                '[WPC LazyCDN] write_failed bytes=%d dest_tail=%s msg=%s',
                strlen($bytes), substr($abs_path, -60), $err['message'] ?? '-'
            ));
            return false;
        }
        if (!@rename($tmp, $abs_path)) {
            $err = error_get_last();
            wpc_v2_lazy_fail_note('disk_rename_failed', substr($abs_path, -60));
            error_log(sprintf(
                '[WPC LazyCDN] rename_failed dest_tail=%s msg=%s',
                substr($abs_path, -60), $err['message'] ?? '-'
            ));
            @unlink($tmp);
            return false;
        }
        if (!@chmod($abs_path, 0644)) {
            $err = error_get_last();
            error_log(sprintf(
                '[WPC LazyCDN] chmod_failed dest_tail=%s msg=%s (may still be readable)',
                substr($abs_path, -60), $err['message'] ?? '-'
            ));
        }

        wpc_v2_sha256_dedup_mark($sha256);
        wpc_v2_lazy_outcome('wrote');


        if (function_exists('wpc_v2_ingest_diag_on') && wpc_v2_ingest_diag_on()
            && (!function_exists('wpc_v2_telemetry_throttle') || wpc_v2_telemetry_throttle('ingest_trace', 15)))
        update_option('wpc_v2_last_ingest_trace', [
            't'            => time(),
            'outcome'      => 'wrote',
            'origin_url'   => substr((string) $origin_url, 0, 160),
            'abs_path'     => substr((string) $abs_path, -90),
            'exists_after' => (int) @file_exists($abs_path),
            'size_after'   => (int) (@file_exists($abs_path) ? @filesize($abs_path) : 0),
            'expect_bytes' => (int) (isset($entry['bytes']) ? (int) $entry['bytes'] : strlen($bytes)),
        ], false);


        $attachment_id = 0;
        $clean_origin  = preg_replace('/\?.*$/', '', $origin_url);


        if (function_exists('attachment_url_to_postid')) {
            $resolve_candidates = [$clean_origin];
            $scaled_origin = preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $clean_origin);
            if ($scaled_origin !== $clean_origin) $resolve_candidates[] = $scaled_origin;
            $base_origin = preg_replace('/-\d{2,4}x\d{2,4}(\.\w+)$/', '$1', $clean_origin);
            if ($base_origin !== $clean_origin) {
                $resolve_candidates[] = $base_origin;
                $base_scaled = preg_replace('/\.(jpe?g|png)$/i', '-scaled.$1', $base_origin);
                if ($base_scaled !== $base_origin) $resolve_candidates[] = $base_scaled;
            }
            foreach ($resolve_candidates as $rc) {
                $attachment_id = wpc_v2_att($rc);
                if ($attachment_id > 0) break;
            }
        }


        if (function_exists('wpc_v2_lazy_cdn_write_postmeta')) {
            wpc_v2_lazy_cdn_write_postmeta($origin_url, $abs_path, strlen($bytes), $format, $attachment_id);
        }


        if (function_exists('wpc_v2_enqueue_landed_purge') && function_exists('wpc_v2_get_apikey') && (string) wpc_v2_get_apikey() !== '') {
            
            
            wpc_v2_enqueue_landed_purge($abs_path);
        }


        if ($attachment_id > 0 && function_exists('wpc_v2_purge_html_for_attachment')) {
            wpc_v2_purge_html_for_attachment($attachment_id, 'lazy-cdn-ingest');
        } elseif ($attachment_id <= 0) {
            
            
            error_log('[WPC LazyCDN] purge_skip no_attachment_for_origin=' . substr($clean_origin, -80));
        }

        return true;
    }
}
