<?php
global $ic_running;
global $wps_ic_cdn_instance;


if (!defined('WPC_ERROR_CAPTURE_DISABLED')) {
    set_error_handler(function ($errno, $errstr, $errfile, $errline) {
        try {
            
            
            if (error_reporting() === 0) {
                return false;
            }
            
            if (strpos($errfile, 'wp-compress') === false) {
                return false;
            }
            $types = [E_WARNING => 'WARNING', E_NOTICE => 'NOTICE', E_DEPRECATED => 'DEPRECATED'];
            if (!isset($types[$errno])) {
                return false;
            }

            
            static $seen = [];
            $key = $errfile . ':' . $errline . ':' . $errstr;
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            
            if (count($seen) > 50) {
                return false;
            }

            $log = get_option('wpc_error_debug_log', []);
            $log[] = date('Y-m-d H:i:s') . ' | ' . $types[$errno] . ' | ' . basename($errfile) . ':' . $errline . ' | ' . $errstr;
            update_option('wpc_error_debug_log', array_slice($log, -50), false);
        } catch (\Throwable $e) {
            
        }
        return false; 
    }, E_WARNING | E_NOTICE | E_DEPRECATED);
}


if (!function_exists('wpc_url_matches_pattern')) {
    function wpc_url_matches_pattern($url, $pattern) {
        $pattern = trim($pattern);
        if ($pattern === '' || $pattern[0] === '#') return false;

        
        $pattern = ltrim($pattern, '/');

        
        if (strpos($pattern, '*') !== false || strpos($pattern, '?') !== false) {
            
            $regex = preg_quote($pattern, '#');
            $regex = str_replace(['\\*\\*', '\\*', '\\?'], ['.*', '[^/]*', '.'], $regex);
            return (bool) @preg_match('#' . $regex . '#i', $url);
        }

        
        return stripos($url, $pattern) !== false;
    }
}

if (!function_exists('wpc_url_is_excluded')) {
    function wpc_url_is_excluded($currentUrl, $patterns) {
        if (empty($patterns) || !is_array($patterns)) return false;
        foreach ($patterns as $pattern) {
            if (wpc_url_matches_pattern($currentUrl, $pattern)) {
                return $pattern; 
            }
        }
        return false;
    }
}







if (!function_exists('wpc_diagnostic_log')) {
    function wpc_diagnostic_log($tag, $detail = '') {
        try {
            static $seen = [];
            static $tagCounts = [];
            static $buf = [];
            static $hooked = false;

            static $armed = null;
            if ($armed === null) {
                $armed = function_exists('get_option') && (int) get_option('wpc_diag_until', 0) >= time();
            }
            if (function_exists('apply_filters') ? !apply_filters('wpc_diagnostic_log_enabled', $armed) : !$armed) return;

            
            $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
            if ($tagCounts[$tag] > 5) return;

            
            $key = $tag . '|' . $detail;
            if (isset($seen[$key])) return;
            $seen[$key] = true;

            
            if (count($seen) > 100) return;

            $buf[] = date('Y-m-d H:i:s') . ' | ' . $tag . ' | ' . $detail;

            if (!$hooked && function_exists('register_shutdown_function')) {
                $hooked = true;
                register_shutdown_function(function () use (&$buf) {
                    try {
                        if (empty($buf) || !function_exists('get_option')) return;
                        if (function_exists('is_admin') && !is_admin()
                            && function_exists('get_transient') && get_transient('wpc_diag_flush_lock')) {
                            return;
                        }
                        if (function_exists('set_transient')) set_transient('wpc_diag_flush_lock', 1, 60);
                        $log = get_option('wpc_diagnostic_log', []);
                        if (!is_array($log)) $log = [];
                        foreach ($buf as $line) { $log[] = $line; }
                        update_option('wpc_diagnostic_log', array_slice($log, -100), false);
                    } catch (\Throwable $e) {
                    }
                });
            }
        } catch (\Throwable $e) {
            
        }
    }
}

include_once __DIR__ . '/debug.php';
include_once __DIR__ . '/defines.php';

if (!function_exists('wpc_crit_meta_write')) {
    
    
    function wpc_crit_meta_write($path, $value)
    {
        try {
            return @file_put_contents($path, (string) $value) !== false;
        } catch (\Throwable $e) {
            return false;
        }
    }
}

include_once WPS_IC_DIR . 'addons/cdn/cdn-rewrite.php';
include_once WPS_IC_DIR . 'addons/cdn/modern-delivery.php';
include_once WPS_IC_DIR . 'addons/cdn/delivery-resolver.php';
include_once WPS_IC_DIR . 'addons/cdn/corp-guard.php';
include_once WPS_IC_DIR . 'addons/cdn/fast404-guard.php';
include_once WPS_IC_DIR . 'addons/cdn/negotiated-delivery.php';
include_once WPS_IC_DIR . 'addons/legacy/compress.php';
include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';


include_once WPS_IC_DIR . 'addons/v2/v2-bootstrap.php';


include_once WPS_IC_DIR . 'addons/v2/v2-natural-url-buffer.php';


include WPS_IC_DIR . 'traits/agency.php';






function wpc_get_local_optimized_ids() {
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = get_transient('wpc_local_optimized_ids');
    if ($cache !== false) return $cache;

    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'ic_status' AND meta_value = 'compressed'"
    );
    $cache = array_flip($ids);
    set_transient('wpc_local_optimized_ids', $cache, 300);
    return $cache;
}






function wpc_url_to_attachment_id($url) {
    static $id_cache = [];

    
    $clean_url = strtok($url, '?#');

    
    $base_url = preg_replace('/-\d+x\d+(?=\.\w{3,4}$)/', '', $clean_url);

    if (isset($id_cache[$base_url])) return $id_cache[$base_url];

    $id = (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_att_id'))
        ? (int) wps_rewriteLogic::wpc_att_id($base_url)
        : attachment_url_to_postid($base_url);
    $id_cache[$base_url] = $id ?: false;
    return $id_cache[$base_url];
}





function wpc_invalidate_local_cache() {
    delete_transient('wpc_local_optimized_ids');
}


function wpc_bulk_heartbeat_touch() {
    
    
    set_transient('wpc_bulk_heartbeat', time(), 300);
}


function wpc_bulk_process_active() {
    $bp = get_option('wps_ic_bulk_process');
    if (empty($bp)) {
        return false;
    }
    if (get_transient('wpc_bulk_heartbeat')) {
        return $bp;
    }
    
    delete_option('wps_ic_bulk_process');
    delete_transient('wps_ic_bulk_running');
    return false;
}






function wpc_purge_cdn_urls($attachment_id) {
    $options = get_option(WPS_IC_OPTIONS);
    if (empty($options['api_key'])) return;

    $path = get_post_meta($attachment_id, '_wp_attached_file', true);
    if (!$path) return;

    
    $urls_to_purge = ["/wp-content/uploads/{$path}"];

    
    $unscaled_path = str_replace('-scaled.', '.', $path);
    if ($unscaled_path !== $path) {
        $urls_to_purge[] = "/wp-content/uploads/{$unscaled_path}";
    }

    
    $metadata = wp_get_attachment_metadata($attachment_id);
    if (!empty($metadata['sizes'])) {
        $base_dir = dirname($path);
        foreach ($metadata['sizes'] as $data) {
            $urls_to_purge[] = "/wp-content/uploads/{$base_dir}/{$data['file']}";
        }
    }

    
    foreach ($urls_to_purge as $url) {
        $pathinfo = pathinfo($url);
        $webp = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '.webp';
        $avif = $pathinfo['dirname'] . '/' . $pathinfo['filename'] . '.avif';
        if (!in_array($webp, $urls_to_purge)) $urls_to_purge[] = $webp;
        if (!in_array($avif, $urls_to_purge)) $urls_to_purge[] = $avif;
    }

    
    foreach ($urls_to_purge as $url) {
        wp_remote_get(
            "https://cdn-mc.zapwp.net/health/cache-purge?apikey=" . urlencode($options['api_key']) . "&url=" . urlencode($url),
            ['timeout' => 5, 'blocking' => false, 'sslverify' => false]
        );
    }


    $site_url = site_url();
    $ladder_widths = [150, 221, 300, 400, 442, 480, 640, 720, 755, 768, 800,
                      960, 1100, 1132, 1200, 1280, 1366, 1440, 1510, 1536,
                      1600, 1800, 1887, 2048, 2560];
    $cdn_zone = '';
    $custom_cname = get_option('ic_custom_cname');
    $cdn_zone = !empty($custom_cname) ? $custom_cname : (string) get_option('ic_cdn_zone_name');
    if ($cdn_zone !== '') {


        $u_hosts = ['https://' . $cdn_zone];
        if (rtrim($site_url, '/') !== rtrim($u_hosts[0], '/')) {
            $u_hosts[] = $site_url;
        }
        foreach ($urls_to_purge as $rel_url) {
            
            
            if (preg_match('/\.(webp|avif)$/i', $rel_url)) continue;
            foreach ($u_hosts as $u_host_for_purge) {
                $full_u = $u_host_for_purge . $rel_url;
                foreach ([0, 1, 2] as $wp_fmt) {
                    foreach ($ladder_widths as $w) {
                        $transform_path = '/q:i/r:0/wp:' . $wp_fmt . '/w:' . $w . '/u:' . $full_u;
                        wp_remote_get(
                            "https://cdn-mc.zapwp.net/health/cache-purge?apikey=" . urlencode($options['api_key']) . "&url=" . urlencode($transform_path),
                            ['timeout' => 5, 'blocking' => false, 'sslverify' => false]
                        );
                    }
                }
            }
        }
    }

    
    $cf = get_option(WPS_IC_CF);
    if (!empty($cf['token']) && !empty($cf['zone'])) {
        $site_url = site_url();
        $full_urls = array_map(function($u) use ($site_url) {
            return $site_url . $u;
        }, $urls_to_purge);
        if (class_exists('WPC_CloudflareAPI')) {
            $cfsdk = new WPC_CloudflareAPI($cf['token']);
            $cfsdk->purgeFiles($cf['zone'], $full_urls);
        }
    }
}


function wpc_purge_cdn_urls_single($attachment_id, $abs_path) {
    $options = get_option(WPS_IC_OPTIONS);
    if (empty($options['api_key'])) return;
    if (!is_string($abs_path) || $abs_path === '') return;

    
    $uploads = wp_upload_dir();
    $basedir = isset($uploads['basedir']) ? $uploads['basedir'] : (WP_CONTENT_DIR . '/uploads');
    $basedir = rtrim($basedir, '/');
    if (strpos($abs_path, $basedir) !== 0) return;
    $rel  = ltrim(substr($abs_path, strlen($basedir)), '/');
    if ($rel === '') return;
    $url  = '/wp-content/uploads/' . $rel;

    
    wp_remote_get(
        "https://cdn-mc.zapwp.net/health/cache-purge?apikey=" . urlencode($options['api_key']) . "&url=" . urlencode($url),
        ['timeout' => 5, 'blocking' => false, 'sslverify' => false]
    );

    
    $cf = get_option(WPS_IC_CF);
    if (!empty($cf['token']) && !empty($cf['zone']) && class_exists('WPC_CloudflareAPI')) {
        $cfsdk = new WPC_CloudflareAPI($cf['token']);
        $cfsdk->purgeFiles($cf['zone'], [site_url() . $url]);
    }

    error_log(sprintf(
        '[WPC PurgeSingle] imageID=%d url=%s',
        (int) $attachment_id,
        $url
    ));
}




function wpc_get_whitelabel_url($fallback = 'https://www.wpcompress.com/') {
    static $cached = null;
    if ($cached !== null) return $cached;

    if (class_exists('wps_ic') && !empty(wps_ic::$slug)) {
        $wl_file = WP_PLUGIN_DIR . '/' . wps_ic::$slug . '/whitelabel.php';
        if (file_exists($wl_file)) {
            if (!function_exists('get_plugin_data')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $wl_data = get_plugin_data($wl_file, false, false);
            if (!empty($wl_data['AuthorURI'])) {
                $cached = $wl_data['AuthorURI'];
                return $cached;
            }
        }
    }

    global $whtlbl;
    if (isset($whtlbl) && property_exists($whtlbl, 'author_url') && !empty($whtlbl->author_url)) {
        $cached = $whtlbl->author_url;
        return $cached;
    }

    $cached = $fallback;
    return $cached;
}







function wpc_wl_mirror_sync() {
    if (!class_exists('whtlbl_whitelabel_plugin') || !defined('WHITE_LABEL_DIR')) {
        return;
    }
    $ver = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '0';
    if (get_option('wpc_wl_mirror_ver') === $ver) {
        return;
    }
    $mirror = rtrim((string) WHITE_LABEL_DIR, '/') . '/files/';
    if (!@is_dir($mirror)) {
        update_option('wpc_wl_mirror_ver', $ver);
        return;
    }
    
    $wpc_map346 = [];
    foreach (['assets/v4/js/', 'assets/v4/css/', 'assets/js/admin/', 'assets/css/', 'assets/js/'] as $wpc_d346) {
        foreach (['*.js', '*.css'] as $wpc_g346) {
            foreach ((array) @glob(WPS_IC_DIR . $wpc_d346 . $wpc_g346) as $wpc_f346) {
                $wpc_bn346 = basename((string) $wpc_f346);
                if ($wpc_bn346 !== '' && !isset($wpc_map346[$wpc_bn346])) {
                    $wpc_map346[$wpc_bn346] = $wpc_f346;
                }
            }
        }
    }
    $wpc_n346 = 0;
    foreach (['*.js', '*.css'] as $wpc_g346) {
        foreach ((array) @glob($mirror . $wpc_g346) as $wpc_dest346) {
            $wpc_bn346 = basename((string) $wpc_dest346);
            if (!isset($wpc_map346[$wpc_bn346])) {
                continue;
            }
            $wpc_src346 = $wpc_map346[$wpc_bn346];
            if (@filesize($wpc_src346) === @filesize($wpc_dest346) && (int) @filemtime($wpc_dest346) >= (int) @filemtime($wpc_src346)) {
                continue;
            }
            if (@copy($wpc_src346, $wpc_dest346)) {
                $wpc_n346++;
            }
        }
    }
    update_option('wpc_wl_mirror_ver', $ver);
    if ($wpc_n346 && function_exists('wpc_cache_first_log')) {
        wpc_cache_first_log('wl-mirror-refresh', '', '', ['n' => $wpc_n346, 'ver' => $ver]);
    }
}
add_action('admin_init', 'wpc_wl_mirror_sync');





function wpc_get_plugin_name() {
    static $cached = null;
    if ($cached !== null) return $cached;

    if (class_exists('whtlbl_whitelabel_plugin')) {
        try {
            $wpc_wlrc462 = new ReflectionClass('whtlbl_whitelabel_plugin');
            $wpc_wldp462 = $wpc_wlrc462->getDefaultProperties();
            if (!empty($wpc_wldp462['whitelabel_menu_name']) && is_string($wpc_wldp462['whitelabel_menu_name'])) {
                $cached = wp_strip_all_tags($wpc_wldp462['whitelabel_menu_name']);
                return $cached;
            }
        } catch (Throwable $wpc_wle462) {
        }
    }

    global $submenu;
    if (isset($submenu['options-general.php']) && class_exists('wps_ic')) {
        foreach ($submenu['options-general.php'] as $item) {
            if (isset($item[2]) && $item[2] === wps_ic::$slug) {
                $cached = wp_strip_all_tags($item[0]);
                return $cached;
            }
        }
    }

    $wpc_wl462 = function_exists('get_option') ? get_option('wpc_wl_menu_name') : '';
    if (is_string($wpc_wl462) && $wpc_wl462 !== '') {
        $cached = $wpc_wl462;
        return $cached;
    }

    $cached = __('WP Compress', 'wp-compress-image-optimizer');
    return $cached;
}


if (!function_exists('wpc_settings_page_url')) {
    function wpc_settings_page_url($wpc_extra = '') {
        $wpc_slug = (class_exists('wps_ic') && !empty(wps_ic::$slug)) ? wps_ic::$slug : 'wpcompress';
        $wpc_base = 'options-general.php';
        $wpc_cap  = 'manage_wpc_settings';
        global $menu, $submenu;
        $wpc_hit = false;
        if (!empty($submenu['options-general.php']) && is_array($submenu['options-general.php'])) {
            foreach ($submenu['options-general.php'] as $wpc_it) {
                if (isset($wpc_it[1], $wpc_it[2]) && $wpc_it[1] === $wpc_cap && strpos((string) $wpc_it[2], '-mu') === false) {
                    $wpc_slug = $wpc_it[2]; $wpc_base = 'options-general.php'; $wpc_hit = true; break;
                }
            }
        }
        if (!$wpc_hit && !empty($menu) && is_array($menu)) {
            foreach ($menu as $wpc_it) {
                if (isset($wpc_it[1], $wpc_it[2]) && $wpc_it[1] === $wpc_cap && strpos((string) $wpc_it[2], '-mu') === false) {
                    $wpc_slug = $wpc_it[2]; $wpc_base = 'admin.php'; $wpc_hit = true; break;
                }
            }
        }
        return admin_url($wpc_base . '?page=' . $wpc_slug . $wpc_extra);
    }
}


function wpc_v2_rewrite_img_to_natural_urls($img_tag, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url) {
    if (empty($cdn_zone) || empty($img_tag)) return $img_tag;
    if (strpos($img_tag, $cdn_zone) === false) return $img_tag;

    
    $attrs = [
        ['name' => 'src',         'is_srcset' => false],
        ['name' => 'srcset',      'is_srcset' => true],
        ['name' => 'data-src',    'is_srcset' => false],
        ['name' => 'data-srcset', 'is_srcset' => true],
    ];

    foreach ($attrs as $a) {
        $name = $a['name'];
        
        if (!preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $img_tag, $m)) continue;
        $original_value = $m[1];
        if ($original_value === '') continue;

        $new_value = $a['is_srcset']
            ? wpc_v2_rewrite_srcset_value($original_value, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url)
            : wpc_v2_rewrite_single_url_to_natural($original_value, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url);

        if ($new_value !== $original_value) {
            
            $img_tag = preg_replace(
                '/(\b' . preg_quote($name, '/') . '\s*=\s*")' . preg_quote($original_value, '/') . '(")/i',
                '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $new_value) . '$2',
                $img_tag,
                1
            );
        }
    }

    return $img_tag;
}

function wpc_v2_rewrite_srcset_value($srcset, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url) {
    if (empty($srcset)) return $srcset;
    $entries = explode(',', $srcset);
    foreach ($entries as &$entry) {
        $entry = trim($entry);
        if ($entry === '') continue;
        
        
        if (preg_match('/^(\S+)(\s+.+)?$/', $entry, $em)) {
            $url = $em[1];
            $descriptor = isset($em[2]) ? $em[2] : '';
            $new_url = wpc_v2_rewrite_single_url_to_natural($url, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url);
            $entry = $new_url . $descriptor;
        }
    }
    unset($entry);
    return implode(', ', $entries);
}

function wpc_v2_rewrite_single_url_to_natural($url, $cdn_zone, $upload_basedir, $upload_baseurl, $site_url) {
    if (empty($url) || empty($cdn_zone)) return $url;

    
    if (strpos($url, $cdn_zone) === false) return $url;


    if (!preg_match('#/(?:u:|m:0/a:)(https?://[^\s]+)$#', $url, $m)) return $url;

    $origin_url = $m[1];
    $origin_clean = preg_replace('/\?.*$/', '', $origin_url);
    $query = '';
    if (strpos($origin_url, '?') !== false) {
        $query = substr($origin_url, strpos($origin_url, '?'));
    }

    
    
    $disk = null;
    if ($upload_baseurl && strpos($origin_clean, $upload_baseurl) === 0) {
        $relative = substr($origin_clean, strlen($upload_baseurl));
        $disk = rtrim($upload_basedir, '/\\') . '/' . ltrim($relative, '/');
    } elseif ($site_url && strpos($origin_clean, $site_url) === 0) {
        $relative = substr($origin_clean, strlen(rtrim($site_url, '/')));
        $disk = rtrim(ABSPATH, '/\\') . '/' . ltrim($relative, '/');
    }

    if ($disk === null || !@file_exists($disk)) {
        
        return $url;
    }

    
    $path_after_site = str_replace($site_url, '', $origin_clean);
    return 'https://' . $cdn_zone . $path_after_site . $query;
}


if (!function_exists('wpc_picture_should_inject_lazy')) {

function wpc_picture_should_inject_lazy($img_tag, $settings)
{


    if (isset($settings['nativeLazy']) && (string) $settings['nativeLazy'] !== '1') {
        return (bool) apply_filters('wpc_picture_inject_lazy', false, $img_tag, $settings);
    }
    
    if (preg_match('/\sloading\s*=\s*["\'][^"\']*["\']/i', $img_tag)) {
        return (bool) apply_filters('wpc_picture_inject_lazy', false, $img_tag, $settings);
    }


    $is_eager_lcp = wpc_picture_is_eager_lcp_marker($img_tag);
    return (bool) apply_filters('wpc_picture_inject_lazy', !$is_eager_lcp, $img_tag, $settings);
}
}

if (!function_exists('wpc_picture_is_eager_lcp_marker')) {

function wpc_picture_is_eager_lcp_marker($img_tag)
{
    
    if (preg_match('/\sloading\s*=\s*["\']lazy["\']/i', $img_tag)) return false;

    
    if (preg_match('/fetchpriority\s*=\s*["\']high["\']/i', $img_tag)) return true;

    
    if (!preg_match('/\swidth\s*=\s*["\'](\d+)["\']/i', $img_tag, $wm)) return false;
    if ((int) $wm[1] < 1200) return false;
    if (!preg_match('/\ssizes\s*=\s*["\']([^"\']*)["\']/i', $img_tag, $sm)) return false;
    return (stripos($sm[1], '100vw') !== false);
}
}

if (!function_exists('wpc_picture_compute_lcp_sizes')) {

if (!function_exists('wpc_get_theme_content_width')) {

function wpc_get_theme_content_width()
{
    $w = 0;
    if (function_exists('wp_get_global_settings')) {
        $layout = wp_get_global_settings(['layout']);
        foreach (['wideSize', 'contentSize'] as $layout_key) {
            if (!empty($layout[$layout_key])) {
                $px = (int) preg_replace('/[^0-9]/', '', (string) $layout[$layout_key]);
                if ($px >= 320 && $px <= 2000) { $w = $px; break; }
            }
        }
    }
    if ($w === 0 && !empty($GLOBALS['content_width']) && (int) $GLOBALS['content_width'] >= 320) {
        $w = (int) $GLOBALS['content_width'];
    }
    return (int) apply_filters('wpc_lcp_content_width', $w);
}
}

function wpc_picture_compute_lcp_sizes($img_tag, $settings)
{
    if (!wpc_picture_is_eager_lcp_marker($img_tag)) {
        return (string) apply_filters('wpc_picture_lcp_sizes', '', $img_tag, $settings);
    }
    $intrinsic_w = 0;
    if (preg_match('/\swidth\s*=\s*["\'](\d+)["\']/i', $img_tag, $wm)) {
        $intrinsic_w = (int) $wm[1];
    }
    
    if ($intrinsic_w < 1200) {
        return (string) apply_filters('wpc_picture_lcp_sizes', '', $img_tag, $settings);
    }
    $max_w       = !empty($settings['maxWidth']) ? (int) $settings['maxWidth'] : 2560;
    
    
    $content_w   = function_exists('wpc_get_theme_content_width') ? wpc_get_theme_content_width() : 0;
    $desktop_cap = $content_w > 0 ? $content_w : min(1200, max(400, $max_w));


    $smart       = '(max-width: 600px) 50vw, (max-width: 1024px) 40vw, ' . $desktop_cap . 'px';
    return (string) apply_filters('wpc_picture_lcp_sizes', $smart, $img_tag, $settings);
}
}

if (!function_exists('wpc_picture_apply_sizes_to_img')) {





function wpc_picture_apply_sizes_to_img($img_tag, $sizes_value)
{
    if (preg_match('/\ssizes\s*=\s*["\'][^"\']*["\']/i', $img_tag)) {
        return preg_replace(
            '/\ssizes\s*=\s*["\'][^"\']*["\']/i',
            ' sizes="' . $sizes_value . '"',
            $img_tag, 1
        );
    }
    return preg_replace('/<img\b/i', '<img sizes="' . $sizes_value . '"', $img_tag, 1);
}
}





function wpc_inject_picture_tags($content) {
    if (is_admin() || empty($content)) return $content;


    if (function_exists('is_feed') && is_feed()) return $content;
    if (function_exists('is_amp_endpoint') && is_amp_endpoint()) return $content;
    if (function_exists('amp_is_request') && amp_is_request()) return $content;
    if (defined('REST_REQUEST') && REST_REQUEST) {
        $wpc_route = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        if (strpos($wpc_route, '/wp/v2/block-renderer/') === false) return $content;
    }


    if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) {
        return $content;
    }

    
    $settings = get_option(WPS_IC_SETTINGS);
    if (empty($settings['picture_webp']) || $settings['picture_webp'] != '1') return $content;


    $wpc_avif_ok = !class_exists('WPC_Delivery_Resolver')
                   || WPC_Delivery_Resolver::effective_ceiling($settings) === 'avif';


    $wpc_webp_ok = !class_exists('WPC_Delivery_Resolver')
                   || WPC_Delivery_Resolver::effective_ceiling($settings) !== 'off';


    $wpc_cdn_imgs_on = !class_exists('WPC_Negotiated_Delivery')
                       || WPC_Negotiated_Delivery::cdn_images_enabled($settings);
    if (!empty($settings['live-cdn']) && (string) $settings['live-cdn'] === '1' && $wpc_cdn_imgs_on) {
        return $content;
    }

    
    if (strpos($content, 'wpc-picture') !== false) return $content;

    $optimized = wpc_get_local_optimized_ids();
    if (empty($optimized)) return $content;

    
    
    $picture_placeholders = [];
    $content = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$picture_placeholders) {
        $key = '<!--WPC_PICTURE_' . count($picture_placeholders) . '-->';
        $picture_placeholders[$key] = $m[0];
        return $key;
    }, $content);

    
    $upload_dir_for_rewrite = wp_get_upload_dir();
    $upload_basedir_for_rewrite = isset($upload_dir_for_rewrite['basedir']) ? $upload_dir_for_rewrite['basedir'] : '';
    $upload_baseurl_for_rewrite = isset($upload_dir_for_rewrite['baseurl']) ? $upload_dir_for_rewrite['baseurl'] : '';
    $site_url_for_rewrite = site_url();

    
    $content = preg_replace_callback(
        '/<img\b[^>]*class="[^"]*wp-image-(\d+)[^"]*"[^>]*>/i',
        function ($matches) use ($optimized, $cdn_zone, $upload_basedir_for_rewrite, $upload_baseurl_for_rewrite, $site_url_for_rewrite, $settings, $wpc_avif_ok, $wpc_webp_ok) {
            $img_tag = $matches[0];
            $attachment_id = (int) $matches[1];

            if (!isset($optimized[$attachment_id])) return $img_tag;

            
            if (preg_match('/\.(svg|gif|ico)[\s"\'?]/i', $img_tag)) return $img_tag;


            if ($cdn_zone) {
                $img_tag = wpc_v2_rewrite_img_to_natural_urls(
                    $img_tag,
                    $cdn_zone,
                    $upload_basedir_for_rewrite,
                    $upload_baseurl_for_rewrite,
                    $site_url_for_rewrite
                );
            }

            $variants = get_post_meta($attachment_id, 'ic_local_variants', true);
            if (empty($variants) || !is_array($variants)) return $img_tag;

            
            $srcsetAttr = (strpos($img_tag, 'data-srcset=') !== false) ? 'data-srcset' : 'srcset';

            
            $webp_srcset = [];
            $avif_srcset = [];

            
            $upload_dir = wp_get_upload_dir();
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true);
            $upload_subdir = $attached_file ? dirname($attached_file) : '';
            $upload_basedir = isset($upload_dir['basedir']) ? $upload_dir['basedir'] : '';

            foreach ($variants as $label => $data) {
                if (empty($data['url'])) continue;

                
                $filename = basename($data['url']);
                $width = 0;
                if (preg_match('/-(\d+)x\d+\.\w+$/', $filename, $wm)) {
                    $width = (int) $wm[1];
                } elseif (preg_match('/-scaled\.\w+$/', $filename)) {
                    $width = 2560;
                } elseif ($label === 'unscaled-webp' || $label === 'unscaled-avif' || $label === 'unscaled') {
                    $meta = wp_get_attachment_metadata($attachment_id);
                    $width = !empty($meta['width']) ? (int) $meta['width'] : 4000;
                }
                if ($width <= 0) continue;


                $disk_path = $upload_basedir . '/' . $upload_subdir . '/' . $filename;
                if (!@file_exists($disk_path)) {
                    continue;
                }

                
                


                if (!defined('WPC_SKIP_PICTURE_VARIANT_VALIDATION') || !WPC_SKIP_PICTURE_VARIANT_VALIDATION) {
                    
                    if (preg_match('/-(\d+)x(\d+)\.\w+$/', $filename, $dm)
                        && ((int) $dm[1] <= 2 || (int) $dm[2] <= 2)) {
                        continue;
                    }
                    
                    $vdims = @getimagesize($disk_path);
                    if (is_array($vdims) && !empty($vdims[0]) && !empty($vdims[1])) {
                        $real_w = (int) $vdims[0];
                        $real_h = (int) $vdims[1];
                        if ($real_w <= 2 || $real_h <= 2) {
                            continue;
                        }


                        if ($width > 0 && abs($real_w - $width) > max(8, (int) ($width * 0.10))) {
                            continue;
                        }
                    }
                }

                
                $local_url = $upload_dir['baseurl'] . '/' . $upload_subdir . '/' . $filename;

                
                if ($cdn_zone) {
                    $local_url = 'https://' . $cdn_zone . str_replace(site_url(), '', $local_url);
                }
                $entry = esc_url($local_url) . ' ' . $width . 'w';

                if (strpos($label, '-avif') !== false) {
                    $avif_srcset[$width] = $entry;
                } elseif (strpos($label, '-webp') !== false) {
                    $webp_srcset[$width] = $entry;
                }
            }


            if (function_exists('wpc_v2_sized_trigger_queue')
                && function_exists('wpc_get_theme_content_width')
                && !preg_match('/\b(alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $img_tag)) {
                $pa_cap = (int) wpc_get_theme_content_width();
                if ($pa_cap > 0) {
                    $pa_existing = array_keys($webp_srcset + $avif_srcset);
                    
                    
                    $pa_sizes  = preg_match('/sizes="([^"]*)"/i', $img_tag, $pa_sm) ? $pa_sm[1] : '';
                    $pa_targets = function_exists('wpc_v2_ideal_targets_from_sizes')
                        ? wpc_v2_ideal_targets_from_sizes($pa_sizes, $pa_cap)
                        : array_unique([(int) round(206 * 1.75), 412, (int) round(206 * 3), $pa_cap, (int) round($pa_cap * 1.75), $pa_cap * 2]);
                    foreach ($pa_targets as $pa_t) {
                        if ($pa_t < 200) continue;


                        $pa_near = false;
                        foreach ($pa_existing as $pa_e) {
                            if ($pa_e >= $pa_t && ($pa_e - $pa_t) / $pa_t < 0.08) { $pa_near = true; break; }
                        }
                        if (!$pa_near) {
                            wpc_v2_sized_trigger_queue($attachment_id, $pa_t, $pa_t);
                            $pa_existing[] = $pa_t;
                        }
                    }
                }
            }


            $lazy_enabled_for_optimistic = function_exists('wpc_v2_get_lazy_enabled')
                                            && wpc_v2_get_lazy_enabled();


            $cdn_live_for_optimistic = !empty($settings['live-cdn']) && (string) $settings['live-cdn'] === '1';
            if ($lazy_enabled_for_optimistic && $cdn_live_for_optimistic && $cdn_zone) {
                $meta_for_lazy = wp_get_attachment_metadata($attachment_id);
                if (is_array($meta_for_lazy) && !empty($meta_for_lazy['sizes'])) {
                    
                    foreach ($meta_for_lazy['sizes'] as $size_name => $size_data) {
                        if (empty($size_data['file']) || empty($size_data['width'])) continue;
                        $w = (int) $size_data['width'];
                        if ($w <= 0) continue;

                        
                        
                        $needs_webp = !isset($webp_srcset[$w]);
                        $needs_avif = !isset($avif_srcset[$w]);
                        if (!$needs_webp && !$needs_avif) continue;

                        $base_filename = (string) $size_data['file'];
                        $base_no_ext   = preg_replace('/\.[^.]+$/', '', $base_filename);
                        if ($base_no_ext === '' || $base_no_ext === null) continue;

                        
                        $jpg_origin_url = $upload_dir['baseurl'] . '/' . $upload_subdir . '/' . $base_filename;

                        
                        $webp_disk = $upload_basedir . '/' . $upload_subdir . '/' . $base_no_ext . '.webp';
                        $avif_disk = $upload_basedir . '/' . $upload_subdir . '/' . $base_no_ext . '.avif';

                        if ($needs_webp) {
                            if (file_exists($webp_disk)) {
                                
                                $webp_url = 'https://' . $cdn_zone . str_replace(site_url(), '', $upload_dir['baseurl']) . '/' . $upload_subdir . '/' . $base_no_ext . '.webp';
                            } else {
                                
                                $webp_url = 'https://' . $cdn_zone . '/q:i/r:0/wp:1/w:' . $w . '/u:' . $jpg_origin_url;
                            }
                            $webp_srcset[$w] = esc_url($webp_url) . ' ' . $w . 'w';
                        }
                        if ($needs_avif) {
                            if (file_exists($avif_disk)) {
                                
                                $avif_url = 'https://' . $cdn_zone . str_replace(site_url(), '', $upload_dir['baseurl']) . '/' . $upload_subdir . '/' . $base_no_ext . '.avif';
                            } else {
                                
                                
                                $avif_url = 'https://' . $cdn_zone . '/q:i/r:0/wp:2/w:' . $w . '/u:' . $jpg_origin_url;
                            }
                            $avif_srcset[$w] = esc_url($avif_url) . ' ' . $w . 'w';
                        }
                    }
                    
                    if (!empty($meta_for_lazy['file']) && !empty($meta_for_lazy['width'])) {
                        $w = (int) $meta_for_lazy['width'];
                        if ($w > 0 && (!isset($avif_srcset[$w]) || !isset($webp_srcset[$w]))) {
                            $parent_file = basename((string) $meta_for_lazy['file']);
                            $parent_no_ext = preg_replace('/\.[^.]+$/', '', $parent_file);
                            if ($parent_no_ext !== '' && $parent_no_ext !== null) {
                                $jpg_full_url = $upload_dir['baseurl'] . '/' . $upload_subdir . '/' . $parent_file;
                                $webp_full_disk = $upload_basedir . '/' . $upload_subdir . '/' . $parent_no_ext . '.webp';
                                $avif_full_disk = $upload_basedir . '/' . $upload_subdir . '/' . $parent_no_ext . '.avif';

                                if (!isset($webp_srcset[$w])) {
                                    if (file_exists($webp_full_disk)) {
                                        $webp_full = 'https://' . $cdn_zone . str_replace(site_url(), '', $upload_dir['baseurl']) . '/' . $upload_subdir . '/' . $parent_no_ext . '.webp';
                                    } else {
                                        $webp_full = 'https://' . $cdn_zone . '/q:i/r:0/wp:1/w:' . $w . '/u:' . $jpg_full_url;
                                    }
                                    $webp_srcset[$w] = esc_url($webp_full) . ' ' . $w . 'w';
                                }
                                if (!isset($avif_srcset[$w])) {
                                    if (file_exists($avif_full_disk)) {
                                        $avif_full = 'https://' . $cdn_zone . str_replace(site_url(), '', $upload_dir['baseurl']) . '/' . $upload_subdir . '/' . $parent_no_ext . '.avif';
                                    } else {
                                        $avif_full = 'https://' . $cdn_zone . '/q:i/r:0/wp:2/w:' . $w . '/u:' . $jpg_full_url;
                                    }
                                    $avif_srcset[$w] = esc_url($avif_full) . ' ' . $w . 'w';
                                }
                            }
                        }
                    }
                }
            }

            if (empty($webp_srcset) && empty($avif_srcset)) return $img_tag;


            $lazy_enabled_for_uni_ladder = function_exists('wpc_v2_get_lazy_enabled')
                                            && wpc_v2_get_lazy_enabled();
            if ($lazy_enabled_for_uni_ladder && $cdn_zone && is_array($meta_for_lazy)) {
                $maxW_uni = !empty($settings['maxWidth']) ? (int) $settings['maxWidth'] : 2560;
                if ($maxW_uni < 100) $maxW_uni = 2560;
                $effective_max_uni = $maxW_uni;
                if (!empty($meta_for_lazy['width']) && !empty($meta_for_lazy['height'])) {
                    $sw_uni = (int) $meta_for_lazy['width'];
                    $sh_uni = (int) $meta_for_lazy['height'];
                    if ($sh_uni > $sw_uni && $sh_uni > 0) {
                        $effective_max_uni = (int) floor($maxW_uni * ($sw_uni / $sh_uni));
                    }
                }
                
                $ladder_uni = [400, 480, 640, 720, 800, 960, 1100, 1200, 1280, 1366, 1440, 1600, 1800, 2048, 2560];
                foreach (array_merge(array_keys($webp_srcset), array_keys($avif_srcset)) as $existing_w) {
                    $ladder_uni[] = (int) $existing_w * 2;
                }
                
                
                $ladder_uni = array_values(array_unique(array_map(function ($w) use ($effective_max_uni) {
                    return min($w, $effective_max_uni);
                }, $ladder_uni)));
                sort($ladder_uni);

                
                $orig_u_url_uni = '';
                if (function_exists('wp_get_original_image_url') && function_exists('wp_get_original_image_path')) {
                    $orig_url_try = wp_get_original_image_url($attachment_id);
                    $orig_path_try = wp_get_original_image_path($attachment_id);
                    if ($orig_url_try && $orig_path_try && @file_exists($orig_path_try)) {
                        $orig_u_url_uni = $orig_url_try;
                    }
                }
                $u_base_uni = $orig_u_url_uni !== ''
                    ? $orig_u_url_uni
                    : ($upload_dir['baseurl'] . '/' . $upload_subdir . '/' . basename((string) $meta_for_lazy['file']));
                $base_no_ext_uni = preg_replace('/\.(jpe?g|png|webp)$/i', '', $u_base_uni);

                foreach ($ladder_uni as $w_uni) {
                    if ($w_uni <= 0) continue;
                    
                    if (isset($webp_srcset[$w_uni]) && isset($avif_srcset[$w_uni])) continue;

                    
                    if (!isset($avif_srcset[$w_uni])) {
                        $natural_avif = $base_no_ext_uni . '-' . $w_uni . 'w.avif';
                        $natural_avif_disk = str_replace(trailingslashit($upload_dir['baseurl']), trailingslashit($upload_basedir) . '', $natural_avif);
                        if (@file_exists($natural_avif_disk)) {
                            $avif_url = 'https://' . $cdn_zone . str_replace(site_url(), '', $natural_avif);
                        } else {
                            $u_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . $cdn_zone, $u_base_uni);
                            $avif_url = 'https://' . $cdn_zone . '/q:i/r:0/wp:2/w:' . $w_uni . '/u:' . $u_via_cdn;
                        }
                        $avif_srcset[$w_uni] = esc_url($avif_url) . ' ' . $w_uni . 'w';
                    }
                    
                    if (!isset($webp_srcset[$w_uni])) {
                        $natural_webp = $base_no_ext_uni . '-' . $w_uni . 'w.webp';
                        $natural_webp_disk = str_replace(trailingslashit($upload_dir['baseurl']), trailingslashit($upload_basedir) . '', $natural_webp);
                        if (@file_exists($natural_webp_disk)) {
                            $webp_url = 'https://' . $cdn_zone . str_replace(site_url(), '', $natural_webp);
                        } else {
                            $u_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . $cdn_zone, $u_base_uni);
                            $webp_url = 'https://' . $cdn_zone . '/q:i/r:0/wp:1/w:' . $w_uni . '/u:' . $u_via_cdn;
                        }
                        $webp_srcset[$w_uni] = esc_url($webp_url) . ' ' . $w_uni . 'w';
                    }
                }
            }

            
            
            if (wpc_picture_should_inject_lazy($img_tag, $settings)) {
                $img_tag = preg_replace('/<img\b/i', '<img loading="lazy"', $img_tag, 1);
                if (function_exists('wpc_diagnostic_log')) {
                    wpc_diagnostic_log('PICTURE_LAZY',
                        'injected loading=lazy on non-LCP IMG id=' . (int) $matches[1]);
                }
            }
            $smart_lcp_sizes = wpc_picture_compute_lcp_sizes($img_tag, $settings);
            if ($smart_lcp_sizes !== '') {
                $img_tag = wpc_picture_apply_sizes_to_img($img_tag, $smart_lcp_sizes);
                if (function_exists('wpc_diagnostic_log')) {
                    wpc_diagnostic_log('PICTURE_LCP_SIZES',
                        'override id=' . (int) $matches[1] . ' sizes="' . $smart_lcp_sizes . '"');
                }
            }

            
            $sizes = '100vw';
            if (preg_match('/sizes="([^"]*)"/', $img_tag, $sz)) {
                $sizes = $sz[1];
            }


            $img_is_lazy_for_auto = (stripos($img_tag, 'loading="lazy"') !== false);
            if ($img_is_lazy_for_auto && stripos($sizes, 'auto') === false) {
                $sizes = 'auto, ' . $sizes;
            }


            $is_mobile_for_cap = class_exists('wps_ic_rewriteLogic')
                ? (bool) wps_ic_rewriteLogic::$isMobile
                : (function_exists('wp_is_mobile') && wp_is_mobile());
            $is_adaptive_for_cap = !empty($settings['generate_adaptive'])
                && (string) $settings['generate_adaptive'] === '1';
            if ($is_mobile_for_cap && $is_adaptive_for_cap) {
                $mob_cap_final = (int) apply_filters('wpc_mobile_srcset_cap',
                    (int) get_option('wpc-min-mobile-width', 400),
                    isset($img_tag) ? (string) $img_tag : '');
                if ($mob_cap_final > 0) {
                    $avif_srcset = array_filter($avif_srcset, function ($_, $w) use ($mob_cap_final) {
                        return (int) $w <= $mob_cap_final;
                    }, ARRAY_FILTER_USE_BOTH);
                    $webp_srcset = array_filter($webp_srcset, function ($_, $w) use ($mob_cap_final) {
                        return (int) $w <= $mob_cap_final;
                    }, ARRAY_FILTER_USE_BOTH);
                }
            }

            $sources = '';
            if ($wpc_avif_ok && !empty($avif_srcset)) {
                ksort($avif_srcset);
                $sources .= '<source type="image/avif" ' . $srcsetAttr . '="' . implode(', ', $avif_srcset) . '" sizes="' . esc_attr($sizes) . '">';
            }
            if ($wpc_webp_ok && !empty($webp_srcset)) {
                ksort($webp_srcset);
                $sources .= '<source type="image/webp" ' . $srcsetAttr . '="' . implode(', ', $webp_srcset) . '" sizes="' . esc_attr($sizes) . '">';
            }

            
            
            if ($sources === '') {
                return $img_tag;
            }
            return '<picture class="wpc-picture">' . $sources . $img_tag . '</picture>';
        },
        $content
    );

    
    if (!empty($picture_placeholders)) {
        $content = str_replace(array_keys($picture_placeholders), array_values($picture_placeholders), $content);
    }

    return $content;
}
add_filter('the_content', 'wpc_inject_picture_tags', 999);


function wpc_picture_tag_css() {
    $settings = get_option(WPS_IC_SETTINGS);
    if (empty($settings['picture_webp']) || $settings['picture_webp'] != '1') return;


    
    
    
    
    
    
    echo '<style>.wpc-picture:not([data-wpc-mir]){display:contents;}</style>' . "\n";
}
add_action('wp_head', 'wpc_picture_tag_css', 1);


function wpc_no_404_guess_for_upload_images($do_guess)
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri !== '' && preg_match('#/wp-content/uploads/[^?]+\.(avif|webp|jpe?g|png|gif|svg|ico)(\?|$)#i', $uri)) {
        return false;
    }
    return $do_guess;
}
add_filter('do_redirect_guess_404_permalink', 'wpc_no_404_guess_for_upload_images');


function wpc_hard_404_for_upload_images()
{
    if (!function_exists('is_404') || !is_404()) {
        return;
    }


    $uri  = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $path = $uri !== '' ? (string) parse_url($uri, PHP_URL_PATH) : '';
    if ($path === '' || !preg_match('#\.(avif|webp|jpe?g|png|gif|svg|ico|bmp|tiff?)$#i', $path)) {
        return;
    }
    status_header(404);
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    header('X-WPC-Fast-404: tr');
    echo 'Not Found';
    exit;
}
add_action('template_redirect', 'wpc_hard_404_for_upload_images', 1);


function wpc_early_404_for_missing_upload_images()
{
    $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    if ($uri === '' || !preg_match('#^/wp-content/uploads/([^?\#]+\.(?:avif|webp|jpe?g|png|gif|svg|ico))(?:[?\#]|$)#i', $uri, $m)) {
        return;
    }
    $rel = rawurldecode($m[1]);
    if (strpos($rel, '..') !== false || strpos($rel, "\0") !== false) {
        return;
    }
    
    
    
    if (preg_match('/-\d+x\d+\.(?:avif|webp|jpe?g|png)$/i', $rel)
        && !(defined('WPC_RUNG_INTERCEPT_OFF') && WPC_RUNG_INTERCEPT_OFF)) {
        return;
    }
    $file = (defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content') . '/uploads/' . $rel;
    if (@file_exists($file)) {
        return;
    }
    status_header(404);
    nocache_headers();
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Not Found';
    exit;
}
add_action('init', 'wpc_early_404_for_missing_upload_images', 0);
add_action('wpc_upgrade_remote_lane', ['wps_ic', 'wpc_upgrade_remote_lane']);


spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'wps_ic_') !== false) {
        $class_nameBase = str_replace('wps_ic_', '', $class_name);
        $class_name = $class_nameBase . '.class.php';
        $class_name_underscore = str_replace('_', '-', $class_name);
        if (file_exists(WPS_IC_DIR . 'classes/' . $class_name)) {
            include_once __DIR__ . '/classes/' . $class_name;
        } elseif (file_exists(WPS_IC_DIR . 'classes/' . $class_name_underscore)) {
            include_once __DIR__ . '/classes/' . $class_name_underscore;
        } elseif (file_exists(WPS_IC_DIR . 'addons/' . $class_nameBase . '/' . $class_name)) {
            include_once __DIR__ . '/addons/' . $class_nameBase . '/' . $class_name;
        }
    }
});

if (!function_exists('wpc_caps_store')) {
    










    function wpc_caps_store($caps, $unrestricted = false)
    {
        $rec = [
            'v'   => 1,
            't'   => time(),
            'un'  => $unrestricted ? 1 : 0,
            'caps' => [],
        ];
        foreach ((array) $caps as $k => $v) {
            $rec['caps'][(string) $k] = ((string) $v === '0') ? '0' : '1';
        }
        update_option('wpc_caps', $rec, false);
        
        foreach ($rec['caps'] as $k => $v) {
            set_transient($k . 'Enabled', $v, 5 * 60);
        }
        return $rec;
    }
}

if (!function_exists('wpc_caps_enabled')) {
    







    function wpc_caps_enabled($featureName)
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return true;
        }
        $name = (string) $featureName;

        
        $t = get_transient($name . 'Enabled');
        if ($t !== false && $t !== null) {
            return !((string) $t === '0');
        }

        $rec = get_option('wpc_caps');
        if (!is_array($rec) || empty($rec['t'])) {
            return false; 
        }
        if (isset($rec['caps'][$name])) {
            return ((string) $rec['caps'][$name] !== '0');
        }
        
        return !empty($rec['un']) || empty($rec['caps']);
    }
}

if (!function_exists('wpc_spawn_cron')) {
    












    function wpc_spawn_cron($ctx = '')
    {
        if (!function_exists('spawn_cron') || !apply_filters('wpc_spawn_cron_on', true)) {
            return false;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return false; 
        }
        $min  = (int) apply_filters('wpc_spawn_cron_min_interval', 60);
        $last = (int) get_option('wpc_cron_spawn_at', 0);
        $now  = time();
        if ($min > 0 && ($now - $last) < $min) {
            return false;
        }
        update_option('wpc_cron_spawn_at', $now, false);
        spawn_cron();
        return true;
    }
}

class wps_ic
{
    use wps_ic_agency_trait;

    public static $slug;
    public static $version;

    public static $api_key;
    public static $response_key;

    public static $settings;
    public static $zone_name;
    public static $quality;
    public static $options;
    public static $js_debug;
    public static $debug;
    public static $local;
    public static $media_lib_ajax;
    private static $accountStatus;
    public $integrations;
    public $upgrader;
    public $cache;
    public $cacheLogic;
    public $remote_restore;
    public $comms;
    public $notices;
    public $enqueues;
    public $templates;
    public $menu;
    public $ajax;
    public $media_library;
    public $compress;
    public $controller;
    public $log;
    public $bulk;
    public $queue;
    public $stats;
    public $cdn;
    public $mu;
    public $mainwp;
    public $offloading;
    public static $accStatusChecked;
    protected $excludes_class;

    


    public function __construct()
    {
        global $wps_ic;
        self::debug_log('Constructor');

        
        self::$slug = 'wpcompress';
        self::$version = '7.21.28';

        $development = get_option('wps_ic_development');
        if (!empty($development) && $development == 'true') {
            
            
            
            $wpc_dev_seen = (int) get_option('wpc_dev_flag_seen');
            if (!$wpc_dev_seen) {
                $wpc_dev_seen = time();
                update_option('wpc_dev_flag_seen', $wpc_dev_seen, false);
            }
            if (time() - $wpc_dev_seen > 86400) {
                delete_option('wps_ic_development');
                delete_option('wpc_dev_flag_seen');
            } else {
                self::$version = (string) (600 * (int) floor(time() / 600));
            }
        }

        $wps_ic = $this;
        self::$accStatusChecked = false;


        
        load_plugin_textdomain('wp-compress-image-optimizer', false, dirname(plugin_basename(WPC_CC_PLUGIN_FILE)) . '/langs');

        if ((!empty($_GET['wpc_visitor_mode']) && sanitize_text_field($_GET['wpc_visitor_mode']))) {
            
            new wps_ic_visitor_mode();
        }


        if (!empty($_GET['preload_mode'])) {
            die('Preloaded');
        }

        $isPostConnectivityTest = isset($_POST['action']) && sanitize_text_field($_POST['action']) === 'connectivityTest';
        $isGetConnectivityTest = isset($_GET['action']) && sanitize_text_field($_GET['action']) === 'connectivityTest';

        $isHeaderConnectivityTest = false;
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $isHeaderConnectivityTest = isset($headers['Action']) && $headers['Action'] === 'connectivityTest';
        }

        if ($isPostConnectivityTest || $isGetConnectivityTest || $isHeaderConnectivityTest) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            ob_start();
            echo json_encode(['message' => 'Connectivity Test passed.']);
            die();
        }


        if (!class_exists('wps_ic_cache')) {
            include_once WPS_IC_DIR . 'classes/cache.class.php';
        }

        $cache = new wps_ic_cache();
        $cache->purgeHooks();

        $this->integrations = new wps_ic_integrations();


        if (!defined('WPC_IS_LIGHT_AJAX') || !WPC_IS_LIGHT_AJAX) {
            $this->integrations->add_admin_hooks();
            $this->integrations->apply_admin_filters();
        }

        if (class_exists('WpeCommon')) {
            add_action('wpe_cache_flush', function() {
                $log = get_option('wpc_purge_debug_log', []);
                $log[] = date('Y-m-d H:i:s') . ' | WPE "Clear all caches" fired (wpe_cache_flush)';
                update_option('wpc_purge_debug_log', array_slice($log, -20), false);
            });
        }

        
        
        if (!defined('WPC_IS_LIGHT_AJAX') || !WPC_IS_LIGHT_AJAX) {
            $preload = new wps_ic_preload_warmup();
            $preload->setupCronPreload();
        }

        
        $cfCname = get_option(WPS_IC_CF_CNAME);
        $cf = get_option(WPS_IC_CF);
        if (!empty($cf) && !empty($cf['custom_cname']) && $cfCname === false) {
            update_option(WPS_IC_CF_CNAME, $cf['custom_cname']);
        }


        
        
    }


    public static function debug_log($message)
    {
        if (get_option('ic_debug') == 'log') {
            $log_file = WPS_IC_LOG . 'debug-log-' . date('d-m-Y') . '.txt';
            $time = current_time('mysql');

            if (!file_exists($log_file)) {
                fopen($log_file, 'a');
            }

            $log = file_get_contents($log_file);
            $log .= '[' . $time . '] - ' . $message . "\r\n";
            file_put_contents($log_file, $log);
        }
    }

    public static function generate_critical_cron()
    {
        $criticalCSS = new wps_criticalCss();
        $criticalCSS->generate_critical_cron();
    }

    



    public static function checkPluginVersion()
    {


        if (!empty($_SERVER['HTTP_X_WPC_CACHE_WARM'])) {
            return;
        }


        
        
        if (!preg_match('/^\d+\.\d+/', (string) self::$version)) {
            return;
        }
        if (is_admin()) {
            $installed_version = get_option('wpc_core_version');


            if (!is_string($installed_version) || !preg_match('/^\d+(\.\d+)+$/', $installed_version)) {
                if (function_exists('wpc_cache_first_log') && !empty($installed_version)) {
                    wpc_cache_first_log('upgrade-version-garbage', '', '', ['v' => substr((string) $installed_version, 0, 24)]);
                }
                $installed_version = '0';
            }

            
            
            
            
            $wpc_realv189 = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : self::$version;
            if (version_compare($installed_version, $wpc_realv189, '<') || !empty($_GET['simulateVersionChange'])) {


                
                
                if (get_transient('wpc_upgrade_lock')) {
                    return;
                }
                set_transient('wpc_upgrade_lock', 1, 300);


                if (function_exists('wpc_update_window_open')) {
                    wpc_update_window_open();
                }


                $wpc_up_tries = (int) get_option("wpc_upgrade_attempts_" . md5($wpc_realv189), 0);
                if ($wpc_up_tries >= 3) {
                    update_option("wpc_core_version", $wpc_realv189, false);
                    delete_option("wpc_upgrade_attempts_" . md5($wpc_realv189));
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('upgrade-degraded', '', '', ['tries' => $wpc_up_tries]);
                    }
                    return;
                }
                update_option("wpc_upgrade_attempts_" . md5($wpc_realv189), $wpc_up_tries + 1, false);


                $wpc_upgrade_done = false;
                if (function_exists('register_shutdown_function')) {
                    register_shutdown_function(function () use (&$wpc_upgrade_done) {
                        if ($wpc_upgrade_done) {
                            return;
                        }
                        $wpc_le = function_exists('error_get_last') ? error_get_last() : null;
                        if (is_array($wpc_le) && isset($wpc_le['type'])
                            && in_array($wpc_le['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                            error_log('[WPC Upgrade] HARD-FATAL during upgrade pass — wpc_core_version left UNBUMPED, next admin load will retry: '
                                . $wpc_le['message'] . ' @ ' . $wpc_le['file'] . ':' . $wpc_le['line']);
                        }
                    });
                }

                try {

                
                $cache = new wps_ic_cache_integrations();
                $cache::purgeAll(false, false, false, true, false, true);


                if (!function_exists('wpc_crit_mark_stale_instead') || !wpc_crit_mark_stale_instead('upgrade')) {
                    $cache::purgeCriticalFiles();
                }
                $cache::purgeCacheFiles(false, true);

                
                $cacheObject = new wps_ic_cache();
                $cacheObject->purgeObjectCache();

                
                
                
                
                
                
                
                if (function_exists('wpc_v2_asset_mime_probe_run')) {
                    try {
                        delete_transient('wpc_v2_cf_asset_mime_retry');
                        delete_transient('wpc_v2_asset_probe_inflight');
                        wpc_v2_asset_mime_probe_run();
                    } catch (\Throwable $e) {
                    }
                }


                if (function_exists('update_option'))    update_option('wpc_v2_force_provision', 1, false);
                if (function_exists('delete_option'))    delete_option('wpc_v2_selfheal_attempts');
                if (function_exists('delete_transient')) delete_transient('wpc_v2_selfheal_backoff');
                if (function_exists('wpc_v2_provision_ensure_bg')) {
                    wpc_v2_provision_ensure_bg('upgrade');
                }


                $wpc_cache_settings = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
                if (!empty($wpc_cache_settings['cache']['advanced']) && $wpc_cache_settings['cache']['advanced'] == '1') {
                    if (!class_exists('wps_ic_htaccess')) {
                        @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
                    }
                    if (class_exists('wps_ic_htaccess')) {
                        $htaccess = new wps_ic_htaccess();
                        $htaccess->setWPCache(true);
                        $htaccess->setAdvancedCache();
                    }
                }


                if (get_option('wpc_cf_cname_verified', '__unset__') === '__unset__') {
                    $wpc_cf_bf  = (defined('WPS_IC_CF')) ? get_option(WPS_IC_CF) : false;
                    $wpc_cfc_bf = (defined('WPS_IC_CF_CNAME')) ? trim((string) get_option(WPS_IC_CF_CNAME)) : '';
                    if ($wpc_cfc_bf !== '' && is_array($wpc_cf_bf) && !empty($wpc_cf_bf['settings']['cdn'])) {
                        update_option('wpc_cf_cname_verified', 1, false);
                    }
                }


                update_option('wpc_v2_force_provision', 1, false);
                if (function_exists('wpc_v2_schedule_config_sync')) {
                    wpc_v2_schedule_config_sync();
                } elseif (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                    && !wp_next_scheduled('wpc_v2_deferred_config_sync')) {
                    wp_schedule_single_event(time(), 'wpc_v2_deferred_config_sync');
                }

                


                $migrateSettings = get_option(WPS_IC_SETTINGS);
                $migrateDirty = false;
                if (is_array($migrateSettings)
                    && !empty($migrateSettings['generate_webp']) && $migrateSettings['generate_webp'] == '1' && !isset($migrateSettings['picture_webp'])) {
                    $migrateSettings['picture_webp'] = '1';
                    $migrateDirty = true;
                }


                $ng = is_array($migrateSettings) && isset($migrateSettings['wpc_nextgen']) ? strtolower((string) $migrateSettings['wpc_nextgen']) : '';
                $ngUnchosen = ($ng === '' || $ng === 'auto');
                $gwOn = is_array($migrateSettings) && !empty($migrateSettings['generate_webp']) && (string) $migrateSettings['generate_webp'] === '1';
                $paOn = is_array($migrateSettings) && !empty($migrateSettings['picture_avif']) && (string) $migrateSettings['picture_avif'] === '1';
                if (is_array($migrateSettings) && $gwOn && $ngUnchosen && !$paOn) {
                    $migrateSettings['picture_avif'] = '1';
                    if (empty($migrateSettings['picture_webp'])) {
                        $migrateSettings['picture_webp'] = '1';
                    }
                    $migrateSettings['wpc_nextgen'] = 'auto';
                    $migrateDirty = true;
                }

                if ($migrateDirty) {
                    update_option(WPS_IC_SETTINGS, $migrateSettings);
                }

                
                
                $wpc_opts214 = get_option(WPS_IC_OPTIONS);
                if (is_array($wpc_opts214) && !empty($wpc_opts214['api_key'])
                    && function_exists('wpc_apply_link_preset')
                    && apply_filters('wpc_upgrade_apply_preset', true)) {
                    wpc_apply_link_preset('upgrade');
                }


                
                update_option('wpc_upgrade_prev_version', (string) $installed_version, false);
                if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
                    if (!wp_next_scheduled('wpc_upgrade_remote_lane')) {
                        wp_schedule_single_event(time(), 'wpc_upgrade_remote_lane');
                    }
                    if (function_exists('spawn_cron')) {
                        wpc_spawn_cron();
                    }
                } else {
                    self::wpc_upgrade_remote_lane();
                }


                try {
                    $wpc_ss61 = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
                    if (is_array($wpc_ss61) && !empty($wpc_ss61['static-serve']) && $wpc_ss61['static-serve'] == '1') {
                        if (!class_exists('wps_ic_htaccess')) {
                            @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
                        }
                        if (class_exists('wps_ic_htaccess')) {
                            (new wps_ic_htaccess())->applyStaticServe();
                        }
                    }
                } catch (\Throwable $e) {
                }


                update_option("wpc_core_version", $wpc_realv189, false);
                delete_option("wpc_upgrade_attempts_" . md5($wpc_realv189));


                if (apply_filters('wpc_purge_html_on_update', true)
                    && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                    try { wps_ic_cache::removeHtmlCacheFiles('all'); } catch (\Throwable $e) {}
                    
                    if (function_exists('wpc_purge_rewarm_hot_set')) {
                        wpc_purge_rewarm_hot_set('core-upgrade');
                    }
                }

                
                $wpc_upgrade_done = true;

                } catch (\Throwable $wpc_upgrade_err) {


                    error_log('[WPC Upgrade] CAUGHT upgrade-pass error — wpc_core_version left UNBUMPED, next admin load will retry: '
                        . $wpc_upgrade_err->getMessage() . ' @ ' . $wpc_upgrade_err->getFile() . ':' . $wpc_upgrade_err->getLine());
                }
            }

            
            if (empty(get_option('wpc_cf_bypass_v7'))) {
                if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')) {
                    if (!wp_next_scheduled('wpc_upgrade_remote_lane')) {
                        wp_schedule_single_event(time(), 'wpc_upgrade_remote_lane');
                    }
                } else {
                    self::wpc_upgrade_remote_lane();
                }
            }


            if (get_option('wpc_avif_natural_default_v70') !== '1') {
                $avifNatSettings = get_option(WPS_IC_SETTINGS);
                if (is_array($avifNatSettings)) {
                    $avifNatSettings['avif-natural-source'] = '1';
                    update_option(WPS_IC_SETTINGS, $avifNatSettings);
                }
                update_option('wpc_avif_natural_default_v70', '1');
            }
        }
    }

    
    public static function wpc_upgrade_remote_lane()
    {
        
        
        
        
        try {
            if (function_exists('wpc_v2_asset_mime_probe_run')
                && (string) get_option('wpc_v2_cf_asset_mime_ok', '') !== '1') {
                $wpc_us819 = get_option(WPS_IC_SETTINGS);
                if (is_array($wpc_us819) && !empty($wpc_us819['live-cdn']) && (string) $wpc_us819['live-cdn'] === '1'
                    && !(function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed())
                    && wpc_v2_asset_mime_probe_run()
                    && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                    wps_ic_cache::removeHtmlCacheFiles('all');
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('natural-converged', '', '', ['src' => 'upgrade']);
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        try {
            $cfReassert = get_option(WPS_IC_CF);
            if (!empty($cfReassert['token']) && !empty($cfReassert['zone'])) {
                if (!class_exists('WPC_CloudflareAPI')) {
                    require_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
                }
                if (class_exists('WPC_CloudflareAPI')) {
                    $cfReassertSdk = new WPC_CloudflareAPI($cfReassert['token']);
                    
                    $wpc_cfs217 = isset($cfReassert['settings']) && is_array($cfReassert['settings']) ? $cfReassert['settings'] : [];
                    if (method_exists($cfReassertSdk, 'configureCF')) {
                        $cfReassertSdk->configureCF(
                            isset($wpc_cfs217['edge-cache']) ? (string) $wpc_cfs217['edge-cache'] : 'home',
                            !empty($wpc_cfs217['assets']) && (string) $wpc_cfs217['assets'] === '1'
                        );
                    }
                    $cfReassertSdk->patchStaticAssetsRespectOrigin($cfReassert['zone']);
                    if (method_exists($cfReassertSdk, 'patchHtmlRulesRespectOrigin')) {
                        $cfReassertSdk->patchHtmlRulesRespectOrigin($cfReassert['zone'], null, true);
                    }
                    
                    
                    $wpc_prev217 = (string) get_option('wpc_upgrade_prev_version', '');
                    if ($wpc_prev217 !== '' && version_compare($wpc_prev217, apply_filters('wpc_edge_reset_below', '7.10.210'), '<')
                        && method_exists($cfReassertSdk, 'purgeCacheAsync')) {
                        $cfReassertSdk->purgeCacheAsync($cfReassert['zone']);
                        if (function_exists('wpc_auto_journal')) {
                            wpc_auto_journal('edge-reset-on-upgrade', ['from' => substr($wpc_prev217, 0, 16)]);
                        }
                    } elseif (apply_filters('wpc_purge_cf_on_update', true)
                        && method_exists($cfReassertSdk, 'purgeByTags')) {
                        
                        
                        
                        
                        
                        $cfReassertSdk->purgeByTags($cfReassert['zone'], ['wpc-html']);
                    }
                    if (method_exists($cfReassertSdk, 'addRendererAllowRule')) {
                        try { $cfReassertSdk->addRendererAllowRule($cfReassert['zone']); } catch (\Throwable $e) {}
                    }
                    if (method_exists($cfReassertSdk, 'addBrowserTtlRules')) {
                        try { $cfReassertSdk->addBrowserTtlRules($cfReassert['zone']); } catch (\Throwable $e) {}
                    }
                    
                    
                    
                    
                    
                    if (empty(get_option('wpc_cf_bypass_v7'))) {
                        $cfReassertSdk->addCdnBypassRule($cfReassert['zone']);
                        $cfReassertSdk->whitelistIPs($cfReassert['zone']);
                        
                        update_option('wpc_cf_bypass_v7', '1');
                    }
                }
            } elseif (empty(get_option('wpc_cf_bypass_v7'))) {
                update_option('wpc_cf_bypass_v7', '1');
            }
        } catch (\Throwable $e) {
            error_log('[WPC Upgrade] remote lane CF step failed: ' . $e->getMessage());
        }
        try {
            if (function_exists('wpc_repull_kick_now')) {
                
                
                
                wpc_repull_kick_now('');
            }
        } catch (\Throwable $e) {
        }
        try {
            
            
            
            
            
            
            $wpc_set830 = get_option(WPS_IC_SETTINGS);
            $wpc_ver830 = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : 'x';
            if (is_array($wpc_set830) && !empty($wpc_set830['critical']['css']) && (string) $wpc_set830['critical']['css'] === '1'
                && apply_filters('wpc_upgrade_resync', true)
                && (string) get_option('wpc_upgrade_resync_v', '') !== $wpc_ver830) {
                update_option('wpc_upgrade_resync_v', $wpc_ver830, false);
                if (function_exists('wpc_crit_purge_redispatch')) {
                    wpc_crit_purge_redispatch(true);
                }
                if (function_exists('wpc_pl_sched') && class_exists('wps_ic_url_key') && function_exists('home_url')) {
                    $wpc_k830 = ltrim((string) (new wps_ic_url_key())->setup(home_url('/')), '/');
                    if ($wpc_k830 !== '') {
                        foreach ([150, 330] as $wpc_w830) {
                            wpc_pl_sched(time() + $wpc_w830, 'wpc_crit_resync_wave', [$wpc_k830, (int) $wpc_w830]);
                        }
                    }
                }
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('upgrade-resync', '', '', ['v' => $wpc_ver830]);
                }
            }
        } catch (\Throwable $e) {
        }
        
        
        error_log(sprintf('[WPC Upgrade] remote lane done [t=%dms peak=%.0fM]',
            (int) round((microtime(true) - (isset($_SERVER['REQUEST_TIME_FLOAT']) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime(true))) * 1000),
            memory_get_peak_usage(true) / 1048576));
    }

    public static function deleteTests()
    {
        
        delete_transient('wpc_test_running');
        delete_transient('wpc_initial_test');
        delete_option(WPC_WARMUP_LOG_SETTING);
        delete_option('wps_ic_gen_hp_url');
    }


    public static function get_wp_filesize($imageID)
    {
        $filepath = get_attached_file($imageID);
        $filesize = filesize($filepath);
        $filesize = wps_ic_format_bytes($filesize, null, null, false);

        return $filesize;
    }

    public static function getAccountQuota($data, $quotaType)
    {
        $proSite = get_option('wps_ic_prosite');
        $options = get_option(WPS_IC_OPTIONS);

        if (empty($data) || empty($options['response_key'])) {
            return ['local' => 0, 'live' => 0, 'liveQuota' => 0, 'localQuota' => 0, 'liveShared' => 0, 'localShared' => 0];
        }

        $liveShared = 0;
        $localShared = 0;

        if (!empty($data->account->liveShared)) {
            $liveShared = $data->account->liveShared;
        }

        if (!empty($data->account->localShared)) {
            $localShared = $data->account->localShared;
        }

        $liveQuota = 0;

        if ($data->account->quotaType == 'requests' || $data->account->quotaType == 'requests-combined') {
            
            $liveCredits = $data->account->leftover . ' Requests Left';

            if (empty($data->liveCredits)) {
                $data->liveCredits = (object)['formatted' => '', 'value' => 0];
            }

            if (!empty($data->liveCredits->value)) {
                $liveQuota = $data->liveCredits->value;
            }

            if (!empty($proSite) && $proSite) {
                $localCredits = 'Unlimited';
                $localQuota = 'Unlimited';
            } else {
                $localCredits = $data->liveCredits->formatted . ' Images Left';
                $localQuota = $data->liveCredits->value;
            }
        } else {
            
            $liveCredits = $data->account->leftover . ' Left';

            if (!empty($data->liveCredits->value)) {
                $liveQuota = $data->liveCredits->value;
            }

            if (!empty($proSite) && $proSite) {
                $localCredits = 'Unlimited';
                $localQuota = 'Unlimited';
            } else {
                
                
                $localCredits = 0;
                $localQuota = 0;
            }
        }

        if (empty($proSite)) {
            if ($localShared) {
                $localCredits = 'Shared Credits';
                $localCredits = 'Shared';
            }

            if ($liveShared) {
                $liveShared = 'Shared Credits';
                $liveCredits = 'Shared';
            }
        } else {
            $localCredits = 'Unlimited &infin;';
            $localCredits = 'Unlimited &infin;';
            $liveShared = 'Unlimited &infin;';
            $liveCredits = 'Unlimited &infin;';
        }

        return ['local' => $localCredits, 'live' => $liveCredits, 'liveQuota' => $liveQuota, 'localQuota' => $localQuota, 'liveShared' => $liveShared, 'localShared' => $localShared];
    }


    public static function getAccountStatusMemory($force = false)
    {
        if (!empty($_GET['refresh']) || $force) {
            delete_transient('wps_ic_account_status');
        }

        $transient_data = get_transient('wps_ic_account_status');

        if (!$transient_data || empty($transient_data)) {
            self::debug_log('Not In Memory');
            self::$accountStatus = self::check_account_status();

            return self::$accountStatus;
        } else {
            self::debug_log('In Memory');
            self::debug_log(print_r($transient_data, true));

            return $transient_data;
        }
    }

    













    public static function wpc_account_gate_live_cdn($account_status, &$settings)
    {
        if (!is_array($settings)) {
            return false;
        }
        $st = is_string($account_status) ? strtolower(trim($account_status)) : '';
        $cur = isset($settings['live-cdn']) ? (string) $settings['live-cdn'] : '';
        $kill = (array) apply_filters('wpc_account_status_disables_cdn',
            ['suspended', 'cancelled', 'canceled', 'expired', 'inactive', 'deleted', 'terminated']);

        
        if ($st === '') {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('account-gate-no-status', '', '', ['cur' => $cur, 'action' => 'none']);
            }
            return false;
        }

        if (in_array($st, $kill, true)) {
            if ($cur === '0') {
                return false; 
            }
            
            update_option('wpc_live_cdn_pre_gate', $cur, false);
            $settings['live-cdn'] = '0';
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('account-gate-cdn-off', '', '', ['status' => $st, 'was' => $cur]);
            }
            return true;
        }

        if ($st === 'active') {
            $prev = get_option('wpc_live_cdn_pre_gate', null);
            $have = ($prev !== null && $prev !== false && $prev !== '');
            if ($have && $cur === '0') {
                $settings['live-cdn'] = (string) $prev;
                delete_option('wpc_live_cdn_pre_gate');
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('account-gate-cdn-restored', '', '', ['to' => (string) $prev]);
                }
                return true;
            }
            if ($have) {
                
                
                delete_option('wpc_live_cdn_pre_gate');
            }
            return false;
        }

        
        
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('account-gate-status-unhandled', '', '', ['status' => $st, 'action' => 'none']);
        }
        return false;
    }

    public static function check_account_status($ignore_transient = false)
    {
        
        self::debug_log('Check Account Status');

        if (!empty($_GET['refresh']) || $ignore_transient) {
            delete_transient('wps_ic_account_status');
        }

        $transient_data = get_transient('wps_ic_account_status');
        if (!empty($transient_data) && $transient_data !== 'no-site-found' && self::$accStatusChecked) {
            self::debug_log('Check Account Status - In Transient');

            return $transient_data;
        }

        
        if (!empty($transient_data) && $transient_data !== 'no-site-found' && !$ignore_transient && empty($_GET['refresh'])) {
            $wpc_cca61 = (int) get_option('wpc_credits_checked_at');
            if (time() - $wpc_cca61 < 60) {
                self::$accStatusChecked = true;

                return $transient_data;
            }
        }

        
        
        
        
        
        if (!empty($transient_data) && $transient_data !== 'no-site-found'
            && !$ignore_transient && empty($_GET['refresh'])
            && !(defined('DOING_CRON') && DOING_CRON)
            && apply_filters('wpc_account_status_async', true)) {
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_account_status_refresh')) {
                wp_schedule_single_event(time() + 2, 'wpc_account_status_refresh');
                wpc_spawn_cron();
            }
            self::$accStatusChecked = true;
            return $transient_data;
        }

        self::debug_log('Check Account Status - Call API');

        $options = get_option(WPS_IC_OPTIONS);
        $settings = get_option(WPS_IC_SETTINGS);

        


        if (!$options || empty($options['api_key'])) {
            $data = [];
            $data['account']['allow_local'] = false;
            $data['account']['allow_live'] = false;
            $data['account']['allow_cname'] = false;
            $data['account']['type'] = 'shared';
            $data['account']['projected_flag'] = 1;

            $data['account'] = (object)$data['account'];

            $data['bytes']['leftover'] = '0';
            $data['bytes']['cdn_bandwidth'] = '0';
            $data['bytes']['cdn_requests'] = '0';
            $data['bytes']['bandwidth_savings'] = '0';
            $data['bytes']['bandwidth_savings_bytes'] = '0';
            $data['bytes']['original_bandwidth'] = '0';
            $data['bytes']['projected'] = '0';
            
            $data['bytes']['local_requests'] = '0';
            $data['bytes']['local_savings'] = '0';
            $data['bytes']['local_original'] = '0';
            $data['bytes']['local_optimized'] = '0';

            $data['bytes'] = (object)$data['bytes'];

            $data['formatted']['leftover'] = '0 MB';
            $data['formatted']['cdn_bandwidth'] = '0 MB';
            $data['formatted']['cdn_requests'] = '0';
            $data['formatted']['bandwidth_savings'] = '0 MB';
            $data['formatted']['bandwidth_savings_bytes'] = '0 MB';
            $data['formatted']['package_without_extra'] = '0';
            $data['formatted']['original_bandwidth'] = '0 MB';
            $data['formatted']['projected'] = '0 MB';

            
            $data['formatted']['local_requests'] = '0';
            $data['formatted']['local_savings'] = '0 MB';
            $data['formatted']['local_original'] = '0 MB';
            $data['formatted']['local_optimized'] = '0 MB';

            $data['formatted'] = (object)$data['formatted'];

            $data = (object)$data;

            $body = ['success' => true, 'data' => $data];
            $body = (object)$body;

            return $data;
        }

        
        $saved_credits_call = get_option('wps_ic_credits_call');

        
        $api_timeout = !empty($saved_credits_call) ? 2 : 5;

        
        update_option('wpc_credits_checked_at', time(), false);

        
        $url = 'https://apiv3.wpcompress.com/api/site/credits';
        $call = wp_remote_get($url, ['timeout' => $api_timeout, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $options['api_key'], 'plugin-version' => self::$version]]);

        if (wp_remote_retrieve_response_code($call) == 200) {

            $json = $body = wp_remote_retrieve_body($call);

            $body = json_decode($body);

            
            if (!empty($body) && $body !== 'no-site-found') {
                update_option('wps_ic_credits_call', $body);
            }

            set_transient('wps_ic_account_status_call', $body, WPS_IC_ACCOUNT_STATUS_MEMORY);

            if (!empty($body) && $body !== 'no-site-found') {
                
                $body = self::createObjectFromJson($json);

                
                $site_url = trim(site_url());
                $api_url  = trim(($body->site->site_url ?? ''));

                if (!empty($site_url) && !empty($api_url) && $api_url !== $site_url) {
                    
                    $logs = get_option('wps_ic_url_changed_log', []);
                    if (!is_array($logs)) {
                        $logs = [];
                    }
                    if (count($logs) > 20) {
                        $logs = array_slice($logs, -20);
                    }

                    $logs[] = [
                            'ts'          => current_time('mysql'),
                            'site_url'    => $site_url,
                            'api_url'     => $api_url,
                            'request_uri' => isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '',
                            'host'        => isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '',
                    ];

                    update_option('wps_ic_url_changed_log', $logs, false);

                    
                    $options = get_option(WPS_IC_OPTIONS);
                    if (!is_array($options)) {
                        $options = [];
                    }

                    $options['api_key'] = '';
                    $options['response_key'] = '';
                    $options['orp'] = '';
                    $options['regExUrl'] = '';
                    $options['regexpDirectories'] = '';

                    update_option(WPS_IC_OPTIONS, $options);
                    update_option('wps_ic_url_changed', true);

                    if ( ! isset($_GET['_wpc_refreshed']) ) {
                        $url = add_query_arg('_wpc_refreshed', '1', wp_get_referer() ?: admin_url());
                        wp_safe_redirect($url);
                        exit;
                    }

                    return false;
                }


                $account_status = $body->account->status;

                $allow_local = $body->account->allowLocal;
                $allow_live = $body->account->allowLive;
                $quota_type = $body->account->quotaType;
                $proSite = $body->account->proSite;

                if ($quota_type == 'pageviews') {
                    $wpc_pkg760 = isset($body->packageConfiguration) ? (array) $body->packageConfiguration : [];

                    $data = [];
                    $data['account']['quotaType'] = 'pageviews';

                    $data['account'] = (object)$data['account'];

                    $data['bytes']['bandwidth_savings'] = $body->bytes->bandwidth_savings;
                    $data['formatted']['bandwidth_savings'] = $body->formatted->bandwidth_savings;
                    
                    $data['bytes']['original_bandwidth'] = $body->bytes->original_bandwidth;
                    $data['formatted']['original_bandwidth'] = $body->formatted->original_bandwidth;

                    $data['bytes']['pageviews'] = $body->pageviews;
                    $data['bytes']['usedPageviews'] = $body->usedPageviews;
                    $data['bytes']['monthly']['requests'] = $body->monthly->requests;
                    $data['bytes']['monthly']['bytes'] = $body->monthly->bytes;
                    $data['bytes']['leftover'] = $data['bytes']['pageviews'] - $data['bytes']['usedPageviews'];

                    $data['bytes'] = (object)$data['bytes'];


                    $data['formatted']['pageviews'] = $body->pageviews;
                    $data['formatted']['usedPageviews'] = $body->usedPageviews;
                    $data['formatted']['monthly']['requests'] = $body->monthly->formatted->requests;
                    $data['formatted']['monthly']['bytes'] = $body->monthly->formatted->bytes;
                    $data['formatted']['leftover'] = $data['formatted']['pageviews'] - $data['formatted']['usedPageviews'];

                    $data['formatted'] = (object)$data['formatted'];
                    $data = (object)$data;

                    $body = ['success' => true, 'data' => $data];
                    $body = (object)$body;

                    
                    set_transient('wps_ic_account_status', $body->data, WPS_IC_ACCOUNT_STATUS_MEMORY);
                    self::$accStatusChecked = true;
                    
                    
                    
                    
                    
                    if (function_exists('wpc_caps_store')) {
                        if (empty($wpc_pkg760)) {
                            wpc_caps_store([], true);
                        } else {
                            wpc_caps_store($wpc_pkg760, false);
                        }
                    }
                    return $body->data;
                }
                else {

                    
                    if (!empty($proSite) && $proSite == '1') {
                        update_option('wps_ic_prosite', true);
                    } else {
                        update_option('wps_ic_prosite', false);
                    }

                    
                    set_transient('wps_ic_account_status', $body, WPS_IC_ACCOUNT_STATUS_MEMORY);
                    self::$accStatusChecked = true;

                    if (!empty($body->account->suspended)) {
                        if ($body->account->suspended == 1) {
                            $allow_local = false;
                            $allow_live = false;
                        }
                    }

                    
                    
                    $updated_local = ((bool) $allow_local !== (bool) get_option('wps_ic_allow_local')) ? update_option('wps_ic_allow_local', $allow_local) : false;
                    $updated_live = ((bool) $allow_live !== (bool) get_option('wps_ic_allow_live')) ? update_option('wps_ic_allow_live', $allow_live) : false;

                    
                    if ($updated_local || $updated_live) {
                        $cache = new wps_ic_cache_integrations();
                        $cache::purgeAll();
                    }

                    
                    if (self::wpc_account_gate_live_cdn($account_status, $settings)) {
                        update_option(WPS_IC_SETTINGS, $settings);
                    }
                }

                
                if (empty($body->packageConfiguration)) {
                    
                    
                    if (function_exists('wpc_caps_store')) { wpc_caps_store([], true); }
                }
                else {
                    
                    $packageConfig = (array)$body->packageConfiguration;
                    
                    
                    if (function_exists('wpc_caps_store')) { wpc_caps_store($packageConfig, false); }
                    if (!empty($packageConfig)) {
                        foreach ($packageConfig as $key => $value) {
                            set_transient($key . 'Enabled', $value, 5 * 60); 

                            if ($value == '0') {
                                switch ($key) {
                                    case 'cdn':
                                        $settings['live-cdn'] = 0;
                                        $settings['serve'] = ['jpg' => 0, 'png' => 0, 'gif' => 0, 'svg' => 0, 'css' => 0, 'js' => 0, 'fonts' => 0];
                                        $settings['css'] = 0;
                                        $settings['js'] = 0;
                                        $settings['fonts'] = 0;
                                        break;
                                    case 'adaptive':
                                        $settings['generate_adaptive'] = 0;
                                        $settings['generate_webp'] = 0;
                                        $settings['retina'] = 0;
                                        $settings['background-sizing'] = 0;
                                        break;
                                    case 'lazy':
                                        $settings['lazy'] = 0;
                                        $settings['nativeLazy'] = 0;
                                        $settings['lazySkipCount'] = 4;
                                        break;
                                    case 'local':
                                        $settings['local'] = ['media-library' => 0];
                                        $settings['on-upload'] = 0;
                                        break;
                                    case 'caching':
                                        $settings['cache'] = ['advanced' => 0, 'mobile' => 0, 'minify' => 0];
                                        break;
                                    case 'css':
                                        $settings['critical']['css'] = 0;
                                        $settings['inline-css'] = 0;
                                        break;
                                    case 'js':
                                        $settings['inline-js'] = 0;
                                        break;
                                    case 'delay-js':
                                        $settings['delay-js'] = 0;
                                        break;

                                }
                            }
                        }
                    }
                }

                return $body;
            } else {
                
                
                
                
                if (!empty($saved_credits_call)) {
                    set_transient('wps_ic_account_status_call', $saved_credits_call, WPS_IC_ACCOUNT_STATUS_MEMORY);
                    return $saved_credits_call;
                }
                return false;
            }
        } else if (wp_remote_retrieve_response_code($call) == 401) {
            
            
            
            
            if (!empty($saved_credits_call)) {
                return $saved_credits_call;
            }
            return false;
        } else {
            
            if (!empty($saved_credits_call)) {
                self::debug_log('Check Account Status - Using Saved Results');

                $body = $saved_credits_call;
                $json = json_encode($body);

                set_transient('wps_ic_account_status_call', $body, WPS_IC_ACCOUNT_STATUS_MEMORY);

                if (!empty($body) && $body !== 'no-site-found') {
                    
                    $body = self::createObjectFromJson($json);
                    $account_status = $body->account->status;

                    $allow_local = $body->account->allowLocal;
                    $allow_live = $body->account->allowLive;
                    $quota_type = $body->account->quotaType;
                    $proSite = $body->account->proSite;

                    if ($quota_type == 'pageviews') {

                        $data = [];
                        $data['account']['quotaType'] = 'pageviews';

                        $data['account'] = (object)$data['account'];

                        $data['bytes']['bandwidth_savings'] = $body->bytes->bandwidth_savings;
                        $data['formatted']['bandwidth_savings'] = $body->formatted->bandwidth_savings;
                        
                        $data['bytes']['original_bandwidth'] = $body->bytes->original_bandwidth;
                        $data['formatted']['original_bandwidth'] = $body->formatted->original_bandwidth;

                        $data['bytes']['pageviews'] = $body->pageviews;
                        $data['bytes']['usedPageviews'] = $body->usedPageviews;
                        $data['bytes']['monthly']['requests'] = $body->monthly->requests;
                        $data['bytes']['monthly']['bytes'] = $body->monthly->bytes;
                        $data['bytes']['leftover'] = $data['bytes']['pageviews'] - $data['bytes']['usedPageviews'];

                        $data['bytes'] = (object)$data['bytes'];


                        $data['formatted']['pageviews'] = $body->pageviews;
                        $data['formatted']['usedPageviews'] = $body->usedPageviews;
                        $data['formatted']['monthly']['requests'] = $body->monthly->formatted->requests;
                        $data['formatted']['monthly']['bytes'] = $body->monthly->formatted->bytes;
                        $data['formatted']['leftover'] = $data['formatted']['pageviews'] - $data['formatted']['usedPageviews'];

                        $data['formatted'] = (object)$data['formatted'];
                        $data = (object)$data;

                        $body = ['success' => true, 'data' => $data];
                        $body = (object)$body;

                        
                        set_transient('wps_ic_account_status', $body->data, WPS_IC_ACCOUNT_STATUS_MEMORY);
                        self::$accStatusChecked = true;

                        return $body->data;
                    } else {

                        
                        if (!empty($proSite) && $proSite == '1') {
                            update_option('wps_ic_prosite', true);
                        } else {
                            update_option('wps_ic_prosite', false);
                        }

                        
                        set_transient('wps_ic_account_status', $body, WPS_IC_ACCOUNT_STATUS_MEMORY);
                        self::$accStatusChecked = true;

                        if (!empty($body->account->suspended)) {
                            if ($body->account->suspended == 1) {
                                $allow_local = false;
                                $allow_live = false;
                            }
                        }

                        
                        $updated_local = ((bool) $allow_local !== (bool) get_option('wps_ic_allow_local')) ? update_option('wps_ic_allow_local', $allow_local) : false;
                        $updated_live = ((bool) $allow_live !== (bool) get_option('wps_ic_allow_live')) ? update_option('wps_ic_allow_live', $allow_live) : false;

                        
                        if ($updated_local || $updated_live) {
                            $cache = new wps_ic_cache_integrations();
                            $cache::purgeAll();
                        }

                        
                        if (self::wpc_account_gate_live_cdn($account_status, $settings)) {
                            update_option(WPS_IC_SETTINGS, $settings);
                        }
                    }
                    
                    if (empty($body->packageConfiguration)) {
                        
                        if (function_exists('wpc_caps_store')) { wpc_caps_store([], true); }
                    } else {
                        
                        $packageConfig = (array)$body->packageConfiguration;
                        if (function_exists('wpc_caps_store')) { wpc_caps_store($packageConfig, false); }
                        if (!empty($packageConfig)) {
                            foreach ($packageConfig as $key => $value) {
                                set_transient($key . 'Enabled', $value, 5 * 60); 

                                if ($value == '0') {
                                    switch ($key) {
                                        case 'cdn':
                                            $settings['live-cdn'] = 0;
                                            $settings['serve'] = ['jpg' => 0, 'png' => 0, 'gif' => 0, 'svg' => 0, 'css' => 0, 'js' => 0, 'fonts' => 0];
                                            $settings['css'] = 0;
                                            $settings['js'] = 0;
                                            $settings['fonts'] = 0;
                                            break;
                                        case 'adaptive':
                                            $settings['generate_adaptive'] = 0;
                                            $settings['generate_webp'] = 0;
                                            $settings['retina'] = 0;
                                            $settings['background-sizing'] = 0;
                                            break;
                                        case 'lazy':
                                            $settings['lazy'] = 0;
                                            $settings['nativeLazy'] = 0;
                                            $settings['lazySkipCount'] = 4;
                                            break;
                                        case 'local':
                                            $settings['local'] = ['media-library' => 0];
                                            $settings['on-upload'] = 0;
                                            break;
                                        case 'caching':
                                            $settings['cache'] = ['advanced' => 0, 'mobile' => 0, 'minify' => 0];
                                            break;
                                        case 'css':
                                            $settings['critical']['css'] = 0;
                                            $settings['inline-css'] = 0;
                                            break;
                                        case 'js':
                                            $settings['inline-js'] = 0;
                                            break;
                                        case 'delay-js':
                                            $settings['delay-js'] = 0;
                                            break;

                                    }
                                }
                            }
                        }
                    }

                    return $body;
                }
            }

            
            $data = [];
            $data['account']['allow_local'] = false;
            $data['account']['allow_live'] = false;
            $data['account']['allow_cname'] = false;
            $data['account']['type'] = 'shared';
            $data['account']['projected_flag'] = 1;

            $data['account'] = (object)$data['account'];

            $data['bytes']['leftover'] = '0';
            $data['bytes']['cdn_bandwidth'] = '0';
            $data['bytes']['cdn_requests'] = '0';
            $data['bytes']['bandwidth_savings'] = '0';
            $data['bytes']['bandwidth_savings_bytes'] = '0';
            $data['bytes']['original_bandwidth'] = '0';
            $data['bytes']['projected'] = '0';

            
            $data['bytes']['local_requests'] = '0';
            $data['bytes']['local_savings'] = '0';
            $data['bytes']['local_original'] = '0';
            $data['bytes']['local_optimized'] = '0';

            $data['bytes'] = (object)$data['bytes'];

            $data['formatted']['leftover'] = '0';
            $data['formatted']['cdn_bandwidth'] = '0';
            $data['formatted']['cdn_requests'] = '0';
            $data['formatted']['bandwidth_savings'] = '0';
            $data['formatted']['bandwidth_savings_bytes'] = '0';
            $data['formatted']['package_without_extra'] = '0';
            $data['formatted']['original_bandwidth'] = '0';
            $data['formatted']['projected'] = '0';

            
            $data['formatted']['local_requests'] = '0';
            $data['formatted']['local_savings'] = '0 MB';
            $data['formatted']['local_original'] = '0 MB';
            $data['formatted']['local_optimized'] = '0 MB';

            $data['formatted'] = (object)$data['formatted'];
            $data = (object)$data;

            $body = ['success' => true, 'data' => $data];
            $body = (object)$body;

            
            set_transient('wps_ic_account_status', $body->data, WPS_IC_ACCOUNT_STATUS_MEMORY);
            self::$accStatusChecked = true;

            update_option('wps_ic_allow_local', false);

            return $body->data;
        }
    }

    public static function createObjectFromJson($json)
    {
        $data = json_decode($json);

        
        $object = new stdClass();

        
        $object->site = new stdClass();
        $object->site->site_url = $data->site_url;

        
        $object->account = new stdClass();
        $object->account->status = "active";
        $object->account->quotaType = $data->quotaType ?? 'bandwidth';
        $object->account->proSite = $data->proSite;
        $object->account->allowLocal = $data->local_enabled;
        $object->account->allowLive = $data->cdn_enabled;
        $object->account->liveShared = $data->live_shared;
        $object->account->quota = $data->credits;
        $object->account->leftover = $data->display->leftover;
        $object->account->displayQuota = $data->display->credits;
        $object->account->suspended = $data->suspended;
        

        
        $object->bytes = new stdClass();
        $object->bytes->cdn_requests = $data->requests;
        $object->bytes->cdn_bandwidth = $data->bytes;
        
        $object->bytes->bandwidth_savings_bytes = $data->savedBytes;
        $object->bytes->bandwidth_savings = $data->savings * 100;
        $object->bytes->original_bandwidth = $data->originalBytes;

        
        $object->formatted = new stdClass();
        $object->formatted->cdn_requests = (string)$data->requests;
        $object->formatted->cdn_bandwidth = $data->display->bytes;
        $object->formatted->bandwidth_savings_bytes = $data->display->savedBytes;
        $object->formatted->bandwidth_savings = $data->savings * 100;
        $object->formatted->original_bandwidth = $data->display->originalBytes;

        
        $object->monthly = new stdClass();
        $object->monthly->requests = $data->requests;
        $object->monthly->bytes = $data->bytes;
        $object->monthly->formatted = new stdClass();
        $object->monthly->formatted->requests = $data->requests;
        $object->monthly->formatted->bytes = $data->display->bytes;

        
        $object->packageConfiguration = new stdClass();
        foreach ($data->configuration as $key => $value) {
            $object->packageConfiguration->$key = $value;
        }

        return $object;
    }

    


    



    public static function getActiveFeatures() {
        $settings = get_option(WPS_IC_SETTINGS);
        $options  = get_option(WPS_IC_OPTIONS);
        $cf       = get_option(WPS_IC_CF);

        return [
            'critical_css'    => !empty($settings['critical']) && !empty($settings['critical']['css']),
            'delay_js'        => !empty($settings['delay-js-v2']) || !empty($settings['delay-js']),
            'cdn'             => !empty($settings['live-cdn']) || !empty($settings['cdn']),
            'lazy_load'       => !empty($settings['lazy']),
            'native_lazy'     => !empty($settings['nativeLazy']),
            'webp'            => !empty($settings['generate_webp']) || !empty($settings['picture_webp']),
            'avif'            => !empty($settings['picture_avif']),
            'minify_css'      => !empty($settings['css_minify']),
            'combine_css'     => !empty($settings['css_combine']),
            'minify_js'       => !empty($settings['js_minify']),
            'combine_js'      => !empty($settings['js_combine']),
            'defer_js'        => !empty($settings['js_defer']),
            'cloudflare'      => !empty($cf['zone']),
            'cache'           => !empty($settings['cache']) && !empty($settings['cache']['advanced']),
            'local_compress'  => !empty($settings['local']) && !empty($settings['local']['media-library']),
            'plugin_version'  => self::$version,
        ];
    }

    public static function activation()
    {


        if (get_option('wpc_install_fresh') === false) {
            $wpc_af_settings = get_option(WPS_IC_SETTINGS);
            $wpc_af_fresh = get_option('wpc_settings_initialized') !== '1'
                && (!is_array($wpc_af_settings) || count($wpc_af_settings) <= 3)
                && get_option('wpc_link_preset_applied') === false;
            update_option('wpc_install_fresh', $wpc_af_fresh ? '1' : '0', false);
        }

        
        delete_option('wpc_loopback_status');


        if (function_exists('delete_transient')) {
            delete_transient('wpc_font_rescan_lock');
        }
        $wpc_act_set126 = get_option(WPS_IC_SETTINGS);
        if (is_array($wpc_act_set126) && (isset($wpc_act_set126['replace-fonts']) ? $wpc_act_set126['replace-fonts'] : '') === 'local'
            && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_font_rescan')) {
            wp_schedule_single_event(time() + 30, 'wpc_font_rescan');
        }

        
        
        if (class_exists('WPC_Modern_Delivery') && method_exists('WPC_Modern_Delivery', 'maybe_create_emissions_table')) {
            WPC_Modern_Delivery::maybe_create_emissions_table();
        }


        update_option('wpc_v2_force_provision', 1, false);
        if (function_exists('wpc_v2_schedule_config_sync')) {
            wpc_v2_schedule_config_sync();
        } elseif (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_v2_deferred_config_sync')) {
            wp_schedule_single_event(time(), 'wpc_v2_deferred_config_sync');
        }

        
        $cache = new wps_ic_cache();
        $cache->purgeObjectCache();

        
        $users = new wps_ic_users();

        if (!class_exists('wps_ic_htaccess')) {
            include_once WPS_IC_DIR . 'classes/htaccess.class.php';
        }

        
        $htaccess = new wps_ic_htaccess();

        
        $config = new wps_ic_config();
        $config->generateCacheConfig();


        $wpc_cache_settings = get_option(WPS_IC_SETTINGS);
        if (!empty($wpc_cache_settings['cache']['advanced']) && $wpc_cache_settings['cache']['advanced'] == '1') {
            $htaccess->setWPCache(true);
            $htaccess->setAdvancedCache();
        }

        
        $wpc_excludes = get_option('wpc-inline');
        $wpc_excludes['inline_js'] = explode(',', "jquery.min,adaptive,jquery-migrate,wp-includes");
        update_option('wpc-inline', $wpc_excludes);

        
        delete_option('wps_ic_gen_hp_url');
        update_option('wpsShowAdvanced', 'true');

        
        $cache = new wps_ic_cache_integrations();
        $cache::purgeAll();

        if (is_multisite()) {
            
        } else {
            $options = get_option(WPS_IC_OPTIONS);

            if (!$options || empty($options['api_key'])) {
                return;
            } else {

                self::check_account_status(true);

                
                $options = new wps_ic_options();
                $settings = get_option(WPS_IC_SETTINGS);


                $wpc_settings_init = get_option('wpc_settings_initialized') === '1';
                if (!$wpc_settings_init) {
                    if (!$settings || count($settings) <= 3) {
                        $options->set_defaults();
                    }
                    update_option('wpc_settings_initialized', '1', false);
                }

                $purge_rules = get_option('wps_ic_purge_rules');

                if ($purge_rules === false) {
                    $purge_rules = $options->get_preset('purge_rules');
                    update_option('wps_ic_purge_rules', $purge_rules, false);
                }

                $cache_cookies = get_option('wps_ic_cache_cookies');

                if ($cache_cookies === false) {
                    $cache_cookies = $options->get_preset('cache_cookies');
                    update_option('wps_ic_cache_cookies', $cache_cookies);
                }

                if (!file_exists(WPS_IC_DIR . 'cache')) {
                    
                    mkdir(WPS_IC_DIR . 'cache', 0755);
                } else {
                    
                    if (!is_writable(WPS_IC_DIR . 'cache')) {
                        chmod(WPS_IC_DIR . 'cache', 0755);
                    }
                }
            }
        }
    }

    



    public static function deactivation($plugin)
    {
        if ($plugin === 'wp-compress-image-optimizer/wp-compress.php') {
            
            $timestamp = wp_next_scheduled('runCronPreload');
            if ($timestamp) {
                wp_unschedule_event($timestamp, 'runCronPreload');
            }

            if (!class_exists('wps_ic_htaccess')) {
                include_once WPS_IC_DIR . 'classes/htaccess.class.php';
            }

            
            $htaccess = new wps_ic_htaccess();
            $htaccess->removeHtaccessRules();
            
            
            
            
            $htaccess->removeStaticServe();

            
            
            
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook('wpc_v2_journal_drain_cron');
                wp_clear_scheduled_hook('wpc_v2_pull_cron');
                wp_clear_scheduled_hook('wpc_v2_provheal_cron');
            }

            
            $htaccess->setWPCache(false);
            $htaccess->removeAdvancedCache();


            static $wpc_deact_cf_purged = false;
            if (!$wpc_deact_cf_purged && !empty(get_option(WPS_IC_CF))) {
                $wpc_deact_cf_purged = true;
                if (!class_exists('WPC_CloudflareAPI') && defined('WPS_IC_DIR') && file_exists(WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php')) {
                    @include_once WPS_IC_DIR . 'addons/cf-sdk/cf-sdk.php';
                }
                $wpc_cf = get_option(WPS_IC_CF);
                if (class_exists('WPC_CloudflareAPI') && !empty($wpc_cf['token']) && !empty($wpc_cf['zone'])) {
                    try {
                        $wpc_cfapi = new WPC_CloudflareAPI($wpc_cf['token']);
                        if ($wpc_cfapi) {
                            
                            
                            if (method_exists($wpc_cfapi, 'purgeCacheAsync')) {
                                $wpc_cfapi->purgeCacheAsync($wpc_cf['zone']);
                            } else {
                                $wpc_cfapi->purgeCache($wpc_cf['zone']);
                            }
                        }
                    } catch (\Throwable $e) {
                        
                    }
                }
            }

            
            $cacheLogic = new wps_ic_cache();
            if (file_exists(WPS_IC_CACHE)) {
                $cacheLogic::deleteFolder(WPS_IC_CACHE);
            }


            if (file_exists(WPS_IC_COMBINE)) {
                $cacheLogic::deleteFolder(WPS_IC_COMBINE);
            }

            
            delete_transient('wps_ic_live_stats');
            delete_transient('wps_ic_local_stats');

            
            delete_option('wps_ic_gen_hp_url');
            delete_option(WPS_IC_GUI);
            delete_option('wps_log_critCombine');

            
            $settings = get_option(WPS_IC_MU_SETTINGS);
            $settings['hide_compress'] = 0;
            update_option(WPS_IC_MU_SETTINGS, $settings);

            
            $options = get_option(WPS_IC_OPTIONS);
            $site = site_url();
            $apikey = $options['api_key'];

            $newOptions = $options;
            $newOptions['regExUrl'] = '';
            $newOptions['regexpDirectories'] = '';
            update_option(WPS_IC_OPTIONS, $newOptions);

            
            $uri = WPS_IC_KEYSURL . '?action=disconnect&apikey=' . $apikey . '&site=' . urlencode($site);

            
            $get = wp_remote_get($uri, ['timeout' => 5, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
        }
    }

    public static function checkQuotaStatus()
    {
        if (get_transient('wps_icQuotaStatus')) {
            return;
        }
        
        if (time() - (int) get_option('wpc_quota_checked_at') < 300) {
            return;
        }
        $settings = get_option(WPS_IC_OPTIONS);
        if (empty($settings['api_key'])) {
            return;
        }
        update_option('wpc_quota_checked_at', time(), false);
        
        
        
        if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
            && !wp_next_scheduled('wpc_quota_status_refresh')
            && apply_filters('wpc_quota_status_async', true)) {
            wp_schedule_single_event(time() + 2, 'wpc_quota_status_refresh');
            if (function_exists('spawn_cron')) {
                wpc_spawn_cron();
            }
        }
    }

    public static function checkQuotaStatusRefresh()
    {
        $settings = get_option(WPS_IC_OPTIONS);
        if (empty($settings['api_key'])) {
            return;
        }
        $call = wp_remote_get(WPS_IC_KEYSURL . '?action=get_account_status_v6&apikey=' . $settings['api_key'] . '&range=month&hash=' . md5(mt_rand(999, 9999)), ['timeout' => 10, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);
        if (wp_remote_retrieve_response_code($call) == 200) {
            set_transient('wps_icQuotaStatus', 'true', 60 * 30);
        }
    }

    



    public static function deactivate_script()
    {
        wp_enqueue_style('wp-pointer');
        wp_enqueue_script('wp-pointer');
        wp_enqueue_script('utils');
        $nonceVar = wp_create_nonce('wps_ic_nonce_action');
        ?>
        <script type="text/javascript">
            function deactivateButton() {
                var row = jQuery('tr:has(span.wps-ic-reconnect)');  // Targets rows containing the 'wps-ic-reconnect' span
                var span_deactivate = jQuery('span.deactivate', row);
                var link = jQuery('a', span_deactivate);
                var pointer = '';

                // Get the original deactivate URL
                var deactivateHref = jQuery(link).attr('href');

                var url = new URL(deactivateHref, window.location.origin);
                url.searchParams.set("action", "deactivate_and_disconnect");
                // Remove protocol + domain
                var updatedDeactivateHref = (url.pathname + url.search).replace(/^\//, "");

                jQuery(link).on('click', function (e) {
                    e.preventDefault();
                    jQuery('.wp-pointer').hide();

                    pointer = jQuery(this).pointer({
                        content: '<h3>Are you sure you want to deactivate?</h3>' +
                            '<div class="wpc-boxed-outter">' +
                            '<p>Deactivating may cause the following:</p>' +
                            '<ul style="padding:0px 15px;margin:0px 10px;' +
                            'list-style:disc;">'
                            + '<li>Significantly higher bounce rates</li>'
                            + '<li>Slow loading images for incoming visitors</li>'
                            + '<li>Backups removed from our cloud</li>'
                            + '<li>Our team crying that you’ve left... <?php echo '<img src="' . WPS_IC_URI . '/assets/crying.png" style="width:19px;" />';?></li>'
                            + '</ul>'
                            + '<div class="wpc-boxed">If you have any questions or issues, please contact us. We\'ll be happy to make sure everything is running fast and smooth for you!</div>'
                            + '</div>'
                            + '<div class="wpc-boxed-footer">'
                            + '<a id="wps-ic-leave-active" class="button ' + 'button-primary" href="#">Keep Active</a>'
                            + '<div class="tooltip-container">'
                            + '<a id="everything" class="button ' + 'button-secondary" ' + 'href="' + jQuery(link).attr('href') + '">Temporarily Deactivate</a>'
                            + '<span class="tooltip-text">This will just turn off the plugin. All your settings and cloud-connected images will be saved for when you reactivate.</span>'
                            + '</div>'
                            + '<div class="tooltip-container align-right">'
                            + '<a id="wps-ic-delete" class="" ' + 'href="' + updatedDeactivateHref + '" style="font-size: 10px;">Disconnect & Deactivate</a>'
                            + '<span class="tooltip-text">This will turn off the plugin, disconnect your site from our service, and may remove your backups from the cloud.</span>'
                            + '</div>'
                            + '</div>',
                        position: {
                            my: 'left top',
                            at: 'left top',
                            offset: '0 0',
                        },
                        close: function () {
                            //
                        }
                    }).pointer('open');

                    var $p = jQuery(pointer).pointer('widget');
                    $p.addClass('wps-ic-pointer');

                    $p[0].style.setProperty('display', 'block', 'important');
                    $p[0].style.setProperty('visibility', 'visible', 'important');
                    $p[0].style.setProperty('opacity', '1', 'important');
                    $p[0].style.setProperty('z-index', '999999', 'important');


                    // Apply width after opening
                    jQuery('.wp-pointer').css({
                        width: '440px',
                        maxWidth: '440px'
                    });

                    jQuery('.wp-pointer').addClass('wpc-custom-pointer');

                    jQuery('#wps-ic-leave-active', '.wp-pointer-content').on('click', function (e) {
                        e.preventDefault();
                        jQuery(pointer).pointer('close');
                        return false;
                    });

                    jQuery('#wps-ic-leave-active', '.wp-pointer-content').on('click', function (e) {
                        e.preventDefault();
                        jQuery(pointer).pointer('close');
                        return false;
                    });

                    jQuery('.wp-pointer-buttons').hide();

                    return false;
                });
            }

            function reconnectButton() {
                var row = jQuery('tr:has(span.wps-ic-reconnect)');  // Targets rows containing the 'wps-ic-reconnect' span
                var span_reconnect = jQuery('span.wps-ic-reconnect', row);
                var link = jQuery('a', span_reconnect);
                var pointer = '';

                jQuery(link).on('click', function (e) {
                    e.preventDefault();
                    jQuery('.wp-pointer').hide();

                    pointer = jQuery(this).pointer({
                        content: '<h3>Are You Sure...</h3>' +
                            '<div class="wpc-boxed-outter">' +
                            '<p>If you continue, you will need your API Key in order to Reconnect the plugin.</p>' +
                            '<p class="wps-ic-helpdesk-link">If you have any questions or issues, please visit our <a href="https://help.wpcompress.com/en-us/" target="_blank">helpdesk</a>.</p>' +
                            '</div>' +
                            '<div class="wpc-boxed-footer">' +
                            '<a id="wps-ic-leave-active" class="button button-primary" href="#">Leave Connected</a>' +
                            '<a id="wps-ic-reconnect-confirm" class="button button-secondary wps-ic-reconnect-confirm" href="' + jQuery(link).attr('href') + '">Reconnect Anyway</a>' +
                            '</div>',
                        position: {
                            my: 'left top',
                            at: 'left top',
                            offset: '0 0'
                        },
                        close: function () {
                            //
                        }
                    }).pointer('open');

                    var $p = jQuery(pointer).pointer('widget');
                    $p.addClass('wps-ic-pointer');

                    $p[0].style.setProperty('display', 'block', 'important');
                    $p[0].style.setProperty('visibility', 'visible', 'important');
                    $p[0].style.setProperty('opacity', '1', 'important');
                    $p[0].style.setProperty('z-index', '999999', 'important');

                    // Apply width + custom styling after opening (match deactivate pointer)
                    jQuery('.wp-pointer').css({
                        width: '440px',
                        maxWidth: '440px'
                    });
                    jQuery('.wp-pointer').addClass('wpc-custom-pointer');

                    jQuery('#wps-ic-reconnect-confirm', '.wp-pointer-content').on('click', function (e) {
                        e.preventDefault();
                        jQuery.post(ajaxurl, {action: 'wps_ic_remove_key', wps_ic_nonce: '<?php echo $nonceVar; ?>'}, function (response) {
                            if (response.success) {
                                window.location.reload();
                            }
                        });
                        return false;
                    });

                    jQuery('#wps-ic-leave-active', '.wp-pointer-content').on('click', function (e) {
                        e.preventDefault();
                        jQuery(pointer).pointer('close');
                        return false;
                    });

                    jQuery('.wp-pointer-buttons').hide();

                    return false;
                });
            }

            jQuery(document).ready(function ($) {
                deactivateButton();
                reconnectButton();
            });
        </script><?php
    }

    public function offloaderHooks()
    {
        $offloader = new wps_ic_offloading();
    }

    


    public function init()
    {
        if (!is_admin()) {
            
            if (ini_get('memory_limit') !== '-1' && wpc_convert_to_bytes(ini_get('memory_limit')) < 1024 * 1024 * 1024) {
                ini_set('memory_limit', '1024M');
            }
        }

        
        if (get_option('wps_ic_url_changed')){
            add_action('admin_notices', function () {
                $class   = 'notice notice-error';
                $reconnect_url = wpc_settings_page_url();

                $message = sprintf(
                        '<strong>Error!</strong> Seems like your URL changed, please reconnect with a new apikey. <a href="%s">Reconnect</a>',
                        esc_url($reconnect_url)
                );

                printf(
                        '<div class="%1$s"><p>%2$s</p></div>',
                        esc_attr($class),
                        $message
                );
            });
        }

        
        $this->fetchCritical();
        $this->fetchPageSpeed();

        


        if (!empty($_GET['show_optimizer'])) {
            $settings = get_option(WPS_IC_SETTINGS);
            $settings['hide_compress'] = '0';
            update_option(WPS_IC_SETTINGS, $settings);
        }

        if (!empty($_GET['getPagesJSON'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->getPagesJSON();
            die();
        }

        if (!empty($_GET['updateStatus'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->updateStatus();
            die();
        }


        if (!empty($_GET['deliverError'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->deliverError();
            die();
        }

        if (!empty($_GET['desktopCritUrl'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->downloadDesktopCrit();
            die();
        }

        if (!empty($_GET['mobileCritUrl'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->downloadMobileCrit();
            die();
        }

        if (!empty($_GET['getWarmupLog'])) {
            $preload = new wps_ic_preload_warmup();
            $preload->getWarmupLog();
            die();
        }

        if (!empty($_GET['override_version'])) {
            self::$version = mt_rand(100, 999);
        }

        if (is_admin() || !empty($_GET['_locale'])) {

            self::$local = new wps_local_compress();
        }

        
        $this::$js_debug = get_option('wps_ic_js_debug');
        $this::$settings = get_option(WPS_IC_SETTINGS);
        $this::$options = get_option(WPS_IC_OPTIONS);

        
        $user = new wps_ic_users();

        if (empty($this::$settings)) {
            $this::$settings = [];
        }


        if (empty($this::$options)) {
            $this::$options = [];
        }


        

        if (!empty($_GET['ignore_ic'])) {
            return;
        }


        if (!empty($_GET['wpc_optimization_done']) && sanitize_text_field($_GET['apikey']) == self::$options['api_key']) {

            delete_transient('wpc-page-optimizations-status');
            die('Ended');
        }

        if (!empty($_GET['wpc_start_test']) && sanitize_text_field($_GET['apikey']) == self::$options['api_key']) {
            $id = sanitize_text_field($_GET['id']);
            if (get_transient('wpc-page-optimizations-status') !== false) {
                set_transient('wpc-page-optimizations-status', ['id' => $id, 'status' => 'test'], 60 * 2);
            }
            $warmup = new wps_ic_preload_warmup();
            $warmup->doTest($id, true);
            die('Test done?');
        }

        if (!empty($_GET['fetchTest']) && sanitize_text_field($_GET['apikey']) == self::$options['api_key']) {
            $warmup = new wps_ic_preload_warmup();
            $testUrl = $warmup::$apiUrl . 'tests/' . $_GET['fetchTest'];
            $download = wp_remote_get($testUrl, ['timeout' => 10, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

            if (!is_wp_error($download)) {
                $body = wp_remote_retrieve_body($download);
                $body = json_decode($body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $tests = get_option(WPS_IC_TESTS);
                    $tests['home'] = $body;

                    delete_transient('wpc_initial_test');

                    update_option(WPS_IC_TESTS, $tests);
                    update_option(WPS_IC_LITE_GPS, ['result' => $body, 'failed' => false, 'lastRun' => time()]);


                    if (!empty($body['insights'])
                        && (int) ($body['insights']['schema_version'] ?? 0) === 1
                        && empty($body['insights']['error'])) {
                        update_option('wpc_psi_insights', [
                            'data'           => $body['insights'],
                            'schema_version' => 1,
                            'lastRun'        => time(),
                        ], false);
                    }

                    if (!empty($body['testID'])) {
                        $warmupLog = get_option(WPC_WARMUP_LOG_SETTING, []);
                        $warmupLog[$body['testID']] = ['ended' => date('Y-m-d H:i:s')];
                        update_option(WPC_WARMUP_LOG_SETTING, $warmupLog);
                    }
                    wp_send_json_success($tests);
                } else {
                    wp_send_json_error('json-error');
                }
            }
            wp_send_json_error('download-error');
        }


        if (!empty($_GET['show_wpcompress_plugin'])) {
            delete_option('hide_wpcompress_plugin');
            delete_option('pause_wpcompress_plugin');
        }


        if (get_option('hide_wpcompress_plugin')) {
            function whitelabel_hide_specific_plugin($plugins)
            {
                
                if (isset($plugins['wp-compress-image-optimizer/wp-compress.php'])) {
                    
                    unset($plugins['wp-compress-image-optimizer/wp-compress.php']);
                }

                return $plugins;
            }

            add_filter('all_plugins', 'whitelabel_hide_specific_plugin');
        }


        if (self::dontRunif()) {
            return;
        }

        if ((!empty($_GET['wps_ic_action']) || !empty($_GET['run_restore']) || !empty($_GET['run_compress'])) && !empty($_GET['apikey'])) {
            $options = get_option(WPS_IC_OPTIONS);
            $apikey = sanitize_text_field($_GET['apikey']);
            if ($apikey !== $options['api_key']) {
                die('Hacking?');
            }
        }

        $this::$settings = $this->fillMissingSettings($this::$settings);


        if (empty($this::$settings['live-cdn']) || $this::$settings['live-cdn'] != '1') {
            $cfSettings = get_option(WPS_IC_CF);
            if (!empty($cfSettings['settings']['cdn']) && $cfSettings['settings']['cdn'] == '1') {
                $this::$settings['live-cdn'] = '1';
            } else {
                $cdnOn = false;
                if (!empty($this::$settings['serve'])) {
                    foreach ($this::$settings['serve'] as $v) {
                        if ($v == '1') { $cdnOn = true; break; }
                    }
                }
                if (!$cdnOn && !empty($this::$settings['css']) && $this::$settings['css'] == '1') $cdnOn = true;
                if (!$cdnOn && !empty($this::$settings['js']) && $this::$settings['js'] == '1') $cdnOn = true;
                if (!$cdnOn && !empty($this::$settings['fonts']) && $this::$settings['fonts'] == '1') $cdnOn = true;
                if ($cdnOn) $this::$settings['live-cdn'] = '1';
            }
        }

        


        if (empty($this::$settings['cname']) || !$this::$settings['cname']) {
            $this::$zone_name = get_option('ic_cdn_zone_name');
        } else {
            $custom_cname = get_option('ic_custom_cname');
            $this::$zone_name = $custom_cname;
        }

        


        if (empty($this::$settings['optimization']) || $this::$settings['optimization'] == '' || $this::$settings['optimization'] == '0') {
            $this::$quality = 'intelligent';
        } else {
            $this::$quality = $this::$settings['optimization'];
        }

        if (empty($this::$options['css_hash'])) {
            $this::$options['css_hash'] = 5021;
        }

        if (!empty($_GET['random_css_hash'])) {
            define('WPS_IC_HASH', substr(md5(microtime(true)), 0, 6));
        } elseif (!defined('WPS_IC_HASH')) {
            define('WPS_IC_HASH', $this::$options['css_hash']);
        }

        if (empty($this::$options['js_hash'])) {
            $this::$options['js_hash'] = 5021;
        }

        if (!empty($_GET['random_js_hash'])) {
            define('WPS_IC_JS_HASH', substr(md5(microtime(true)), 0, 6));
        } elseif (!defined('WPS_IC_JS_HASH')) {
            define('WPS_IC_JS_HASH', $this::$options['js_hash']);
        }

        
        if (empty($this::$options['api_key'])) {
            self::$api_key = '';
        } else {
            self::$api_key = $this::$options['api_key'];
        }

        
        $this->isAgencyPortal();

        if (empty($this::$options['response_key'])) {
            self::$response_key = '';
        } else {
            self::$response_key = $this::$options['response_key'];
        }

        
        $this->upgrader = new wps_ic_upgrader();
        $this->mainwp = new wps_ic_mainwp();

        if ($this->isAgencyPortal()) {

            
            $this->enqueues = new wps_ic_enqueues();
            $this->ajax = new wps_ic_ajax();

            
            $modes = new wps_ic_modes();
            add_action('wp_footer', [$modes, 'showPopup']);

        } else {

            if (is_admin()) {
                $this->inAdmin();
            } else {
                
                $bgLazy = new wps_ic_bgLazy();
                $this->inFrontEnd();
            }

        }

        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return;
        }

        
        $wps_ic = $this;
        do_action('wps_ic_init');
    }


    public function inAgency() {
        $this->enqueues = new wps_ic_enqueues();
    }


    public function fetchCritical()
    {
        if (!empty($_GET['criticalDone'])) {
            $jobStatus = [];


            $wpc_cb_body = json_decode((string) @file_get_contents('php://input'), true);
            if (!is_array($wpc_cb_body)) { $wpc_cb_body = []; }
            foreach (['uuid', 'apikey', 'pageUrl', 'lcp_url', 'delay_url', 'used_css_url', 'tpl_key', 'ready'] as $wpc_ck) {
                if ((!isset($_GET[$wpc_ck]) || $_GET[$wpc_ck] === '') && isset($wpc_cb_body[$wpc_ck])
                    && is_scalar($wpc_cb_body[$wpc_ck]) && $wpc_cb_body[$wpc_ck] !== '') {
                    $_GET[$wpc_ck] = (string) $wpc_cb_body[$wpc_ck];
                }
            }

            
            
            
            
            if ((string) ($wpc_cb_body['event'] ?? '') === 'generation_complete'
                && !empty($wpc_cb_body['manifest_url']) && !empty($wpc_cb_body['gen_id'])
                && apply_filters('wpc_manifest_webhook', true)) {
                $wpc_mo697 = get_option(WPS_IC_OPTIONS);
                $wpc_mk697key = is_array($wpc_mo697) && !empty($wpc_mo697['api_key']) ? (string) $wpc_mo697['api_key'] : '';
                
                
                $wpc_msig697 = (string) ($_SERVER['HTTP_X_WPC_SIG'] ?? ($wpc_cb_body['sig'] ?? ''));
                $wpc_mexp697 = $wpc_mk697key === '' ? '' : hash_hmac('sha256',
                    (string) $wpc_cb_body['gen_id'] . '|' . (string) ($wpc_cb_body['url_key'] ?? '') . '|' . (string) ($wpc_cb_body['ts'] ?? ''),
                    $wpc_mk697key);
                $wpc_mok697 = ($wpc_mexp697 !== '' && $wpc_msig697 !== '' && hash_equals($wpc_mexp697, $wpc_msig697))
                    || ($wpc_mk697key !== '' && !empty($_GET['apikey']) && $wpc_mk697key === sanitize_text_field((string) $_GET['apikey']));
                if (!$wpc_mok697) {
                    wp_send_json_error('sig-failure');
                }
                @ignore_user_abort(true);
                if (function_exists('set_time_limit')) { @set_time_limit(180); }
                if (!headers_sent()) { http_response_code(200); }
                if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
                if (!class_exists('wps_ic_url_key')) {
                    include_once WPS_IC_DIR . 'traits/url_key.php';
                }
                
                
                $wpc_mpu697 = (string) strtok((string) ($wpc_cb_body['url_key'] ?? ''), '?');
                if ($wpc_mpu697 === '' && !empty($_SERVER['HTTP_HOST'])) {
                    $wpc_mpu697 = (string) $_SERVER['HTTP_HOST'] . (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
                }
                $wpc_mlk697 = (new wps_ic_url_key())->setup($wpc_mpu697);
                $wpc_mres697 = 0;
                if (!empty($wpc_mlk697) && function_exists('wpc_manifest_consume')) {
                    $wpc_mres697 = (int) wpc_manifest_consume((string) $wpc_mlk697, (string) $wpc_cb_body['manifest_url'], (string) $wpc_cb_body['gen_id'], $wpc_mpu697);
                }
                wp_send_json_success(['manifest' => $wpc_mres697]);
            }

            $uuid = sanitize_text_field($_GET['uuid'] ?? '');
            $apikey = sanitize_text_field($_GET['apikey'] ?? '');

            if (!empty($uuid) && !empty($apikey)) {
                $options = get_option(WPS_IC_OPTIONS);
                $dbApiKey = $options['api_key'];

                if ($dbApiKey == $apikey) {

                    
                    
                    @ignore_user_abort(true);
                    if (function_exists('set_time_limit')) { @set_time_limit(180); }
                    if (!headers_sent()) { http_response_code(200); }
                    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                    elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }

                    if (!empty($_GET['debug'])) {
                        ini_set('display_errors', 1);
                        error_reporting(E_ALL);
                    }

                    if (!class_exists('wps_ic_url_key')) {
                        include_once WPS_IC_DIR . 'traits/url_key.php';
                    }

                    $urlKey = new wps_ic_url_key();
                    $pageUrl = sanitize_url(urldecode($_GET['pageUrl'] ?? ''));
                    
                    
                    
                    
                    
                    if ($pageUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
                        $pageUrl = (string) $_SERVER['HTTP_HOST'] . (string) strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
                    }
                    $urlKey = $urlKey->setup((string) strtok($pageUrl, '?'));

                    
                    
                    
                    
                    
                    
                    
                    if (defined('WPS_IC_CRITICAL') && function_exists('wpc_crit_meta_write')) {
                        $wpc_cbdir = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim((string) $urlKey, '/') . '/';
                        if (!is_dir($wpc_cbdir) && function_exists('wp_mkdir_p')) { @wp_mkdir_p($wpc_cbdir); }
                        if (!empty($wpc_cb_body['delay_inline']) && !empty($wpc_cb_body['delay']) && is_array($wpc_cb_body['delay'])
                            && function_exists('wpc_delay_inline_fresher') && wpc_delay_inline_fresher($wpc_cbdir . 'delay.json', $wpc_cb_body['delay'])
                            && class_exists('wps_ic_js_delay_v3') && wps_ic_js_delay_v3::wpc_delay_measured_shape($wpc_cb_body['delay'])
                            && apply_filters('wpc_delay_inline_consume', true)) {
                            $wpc_dib382 = wp_json_encode($wpc_cb_body['delay']);
                            if (is_string($wpc_dib382) && $wpc_dib382 !== '' && strlen($wpc_dib382) <= 524288) {
                                wpc_crit_meta_write($wpc_cbdir . 'delay.json', $wpc_dib382);
                                delete_option('wpc_delay_v3_manifest_off');
                                delete_option('wpc_delay_v3_promoted');
                                if (function_exists('wpc_delay_aggr_rearm')) { wpc_delay_aggr_rearm(); }
                                if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('delay-inline-landed', (string) $urlKey, '', ['via' => 'criticalDone', 'bytes' => strlen($wpc_dib382)]); }
                            }
                        }
                        $wpc_cblcp382 = (isset($wpc_cb_body['lcp_json']) && is_array($wpc_cb_body['lcp_json'])) ? $wpc_cb_body['lcp_json']
                            : ((isset($wpc_cb_body['lcp']) && is_array($wpc_cb_body['lcp'])) ? $wpc_cb_body['lcp'] : null);
                        if (!empty($wpc_cb_body['lcp_inline']) && is_array($wpc_cblcp382)
                            && (isset($wpc_cblcp382['lcp_element']) || isset($wpc_cblcp382['hints']))
                            && apply_filters('wpc_lcp_inline_consume', true)) {
                            $wpc_lib382 = wp_json_encode($wpc_cblcp382);
                            if (is_string($wpc_lib382) && $wpc_lib382 !== '' && strlen($wpc_lib382) <= 524288) {
                                $wpc_oldauth383c = function_exists('wpc_lcp_first_auth')
                                    ? wpc_lcp_first_auth(json_decode((string) @file_get_contents($wpc_cbdir . 'lcp.json'), true)) : null;
                                wpc_crit_meta_write($wpc_cbdir . 'lcp.json', $wpc_lib382);
                                @unlink($wpc_cbdir . 'lcp_none.txt');
                                
                                if ($wpc_oldauth383c !== false && function_exists('wpc_lcp_first_auth') && wpc_lcp_first_auth($wpc_cblcp382) === false
                                    && function_exists('wpc_lcp_edge_flip_purge')) {
                                    wpc_lcp_edge_flip_purge((string) strtok((string) $pageUrl, '?'));
                                }
                                if (class_exists('wps_ic_cache_integrations') && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                                    function_exists('wpc_land_purge_coalesced') ? wpc_land_purge_coalesced((string) $urlKey, '', 'lcp-inline-criticalDone') : wps_ic_cache_integrations::purgeUrlHtml((string) $urlKey, '', ['context' => 'lcp-inline-criticalDone']);
                                }
                                if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('lcp-inline-landed', (string) $urlKey, '', ['via' => 'criticalDone', 'bytes' => strlen($wpc_lib382)]); }
                            }
                        }
                    }

                    
                    $uuidPart = substr($uuid, 0, 4);

                    
                    $mobileCriticalCSS = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/' . $uuid . '-mobile.css';

                    
                    $desktopCriticalCSS = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/' . $uuid . '-desktop.css';

                    if (!class_exists('wps_criticalCss')) {
                        include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
                    }

                    $criticalCSS = new wps_criticalCss();

                    
                    

                    $wpc_cb_lcp_url = !empty($_GET['lcp_url']) ? sanitize_url(urldecode($_GET['lcp_url'])) : '';

                    $wpc_cb_delay_url = !empty($_GET['delay_url']) ? sanitize_url(urldecode($_GET['delay_url'])) : '';


                    $wpc_cb_used_css = !empty($_GET['used_css_url']) ? sanitize_url(urldecode($_GET['used_css_url'])) : '';
                    
                    if (function_exists('wpc_used_css_echo_note')) {
                        wpc_used_css_echo_note('legacy-get', $_GET);
                    }
                    $wpc_cb_tpl_key  = !empty($_GET['tpl_key']) ? sanitize_text_field(urldecode($_GET['tpl_key'])) : '';


                    if ($wpc_cb_tpl_key !== '' && function_exists('wpc_used_css_store_sheets')) {


                        if (!empty($wpc_cb_body['used_css_sheets']) && is_array($wpc_cb_body['used_css_sheets'])) {
                            wpc_used_css_store_sheets($wpc_cb_tpl_key, $wpc_cb_body['used_css_sheets']);
                        }
                    }


                    try {

                        $wpc_fonts67 = (!empty($wpc_cb_body['fonts']) && is_array($wpc_cb_body['fonts']))
                            ? $wpc_cb_body['fonts']
                            : ((!empty($_GET['fonts']) && is_array(json_decode(urldecode((string) $_GET['fonts']), true))) ? json_decode(urldecode((string) $_GET['fonts']), true) : []);
                        if (!empty($wpc_fonts67) && defined('WPS_IC_FONTS_DIR')
                            && apply_filters('wpc_fonts_artifact_consume', true)) {
                            if (!is_dir(WPS_IC_FONTS_DIR)) {
                                @wp_mkdir_p(WPS_IC_FONTS_DIR);
                            }
                            $wpc_f_n = 0;
                            $wpc_metrics67 = [];
                            foreach ($wpc_fonts67 as $wpc_fe) {
                                if ($wpc_f_n >= 6 || !is_array($wpc_fe) || empty($wpc_fe['url'])) {
                                    continue;
                                }
                                $wpc_fu = (string) $wpc_fe['url'];
                                $wpc_fh = (string) parse_url($wpc_fu, PHP_URL_HOST);
                                $wpc_fb = basename((string) parse_url($wpc_fu, PHP_URL_PATH));
                                if (stripos($wpc_fh, 'critical-css-mc.b-cdn.net') === false
                                    || !preg_match('/^[A-Za-z0-9._-]+\.woff2$/', $wpc_fb)) {
                                    continue;
                                }
                                $wpc_dst = rtrim(WPS_IC_FONTS_DIR, '/') . '/' . $wpc_fb;
                                if (!file_exists($wpc_dst) || (int) @filesize($wpc_dst) !== (int) ($wpc_fe['bytes'] ?? -1)) {
                                    $wpc_fr = wp_remote_get($wpc_fu, ['timeout' => 8]);
                                    $wpc_fbody = (!is_wp_error($wpc_fr) && wp_remote_retrieve_response_code($wpc_fr) === 200)
                                        ? wp_remote_retrieve_body($wpc_fr) : '';
                                    if ($wpc_fbody !== '' && strlen($wpc_fbody) <= 65536 && strncmp($wpc_fbody, 'wOF2', 4) === 0) {
                                        $wpc_ftmp = $wpc_dst . '.tmp.' . getmypid();
                                        if (wpc_crit_meta_write($wpc_ftmp, $wpc_fbody) !== false) {
                                            @rename($wpc_ftmp, $wpc_dst);
                                            $wpc_f_n++;
                                        }
                                    }
                                } else {
                                    $wpc_f_n++;
                                }
                                if (!empty($wpc_fe['fallback']) && is_array($wpc_fe['fallback']) && !empty($wpc_fe['family'])) {
                                    $wpc_metrics67[(string) $wpc_fe['family']] = $wpc_fe['fallback'];
                                }
                            }
                            if (!empty($wpc_metrics67) && defined('WPS_IC_CRITICAL') && !empty($urlKey)) {
                                $wpc_md = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/';
                                if (!is_dir($wpc_md)) {
                                    @wp_mkdir_p($wpc_md);
                                }
                                wpc_crit_meta_write($wpc_md . 'font-metrics.json', wp_json_encode($wpc_metrics67));
                            }
                            if ($wpc_f_n > 0 && function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('fonts-landed', $urlKey, '', ['n' => $wpc_f_n, 'metrics' => count($wpc_metrics67)]);
                            }


                            if (apply_filters('wpc_atf_subset_inline', true) && defined('WPS_IC_CRITICAL') && !empty($urlKey)) {
                                $wpc_sub_css = '';
                                $wpc_sub_n   = 0;


                                
                                $wpc_all_v2  = true;
                                foreach ($wpc_fonts67 as $wpc_sfe) {
                                    $wpc_e_v2 = is_array($wpc_sfe) && (int) ($wpc_sfe['subset_v'] ?? 1) >= 2;
                                    $wpc_cap69 = $wpc_e_v2 ? 6 : 2;
                                    if ($wpc_sub_n >= $wpc_cap69 || !is_array($wpc_sfe) || empty($wpc_sfe['url']) || empty($wpc_sfe['family'])) {
                                        continue;
                                    }
                                    if ((int) ($wpc_sfe['bytes'] ?? 999999) > 12288) {
                                        continue;
                                    }
                                    $wpc_sfb = basename((string) parse_url((string) $wpc_sfe['url'], PHP_URL_PATH));
                                    $wpc_sfp = rtrim(WPS_IC_FONTS_DIR, '/') . '/' . $wpc_sfb;
                                    if (!preg_match('/^[A-Za-z0-9._-]+\.woff2$/', $wpc_sfb) || !@is_readable($wpc_sfp)) {
                                        continue;
                                    }
                                    $wpc_sfw = @file_get_contents($wpc_sfp);
                                    if ($wpc_sfw === false || $wpc_sfw === '' || strncmp($wpc_sfw, 'wOF2', 4) !== 0) {
                                        continue;
                                    }
                                    $wpc_sfam = str_replace(["'", "\\", "\r", "\n", '<', '>'], '', (string) $wpc_sfe['family']);

                                    
                                    
                                    $wpc_swt = trim(preg_replace('/[^0-9 ]/', '', (string) ($wpc_sfe['weight'] ?? '400')));
                                    if (!($wpc_e_v2 && !empty($wpc_sfe['variable']) && preg_match('/^\d{2,4} \d{2,4}$/', $wpc_swt))) {
                                        $wpc_swt = strtok($wpc_swt, ' ');
                                    }
                                    $wpc_sst  = (strtolower((string) ($wpc_sfe['style'] ?? 'normal')) === 'italic') ? 'italic' : 'normal';
                                    $wpc_sur  = preg_replace('/[^0-9A-Fa-fUu+,\- ]/', '', (string) ($wpc_sfe['unicode_range'] ?? ''));
                                    
                                    
                                    
                                    
                                    $wpc_srr  = preg_replace('/[^0-9A-Fa-fUu+,\- ]/', '', (string) ($wpc_sfe['remote_range'] ?? ''));
                                    if ($wpc_sfam === '' || $wpc_swt === '') {
                                        continue;
                                    }
                                    if (!isset($wpc_rr_map)) { $wpc_rr_map = []; }
                                    
                                    
                                    
                                    if (!isset($wpc_rr_diag)) { $wpc_rr_diag = []; }
                                    $wpc_rr_diag[] = strtolower($wpc_sfam) . '|' . $wpc_swt . '|' . $wpc_sst
                                        . ' keys=' . implode(',', array_keys($wpc_sfe))
                                        . ' ur=' . ($wpc_sur !== '' ? $wpc_sur : '(none)')
                                        . ' rr=' . ($wpc_srr !== '' ? $wpc_srr : '(NONE)');
                                    if ($wpc_srr !== '' && $wpc_sur !== '') {
                                        $wpc_rr_map[strtolower($wpc_sfam) . '|' . $wpc_swt . '|' . $wpc_sst] = $wpc_srr;
                                    }


                                    if (!$wpc_e_v2) { $wpc_all_v2 = false; }
                                    $wpc_sub_css .= "@font-face{font-family:'" . $wpc_sfam . "';font-weight:" . $wpc_swt
                                        . ';font-style:' . $wpc_sst . ';src:url(data:font/woff2;base64,' . base64_encode($wpc_sfw)
                                        . ") format('woff2');" . ($wpc_sur !== '' ? 'unicode-range:' . $wpc_sur . ';' : '')
                                        . 'font-display:block}';
                                    $wpc_sub_n++;
                                }
                                $wpc_sub_path = rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/font-subsets.css';
                                if ($wpc_sub_css !== '' && $wpc_all_v2 && $wpc_sub_n > 0) {
                                    $wpc_sub_css = '/*wpc-subsets-v2*/' . $wpc_sub_css;
                                }
                                if ($wpc_sub_css !== '') {
                                    if (!is_dir(dirname($wpc_sub_path))) { @wp_mkdir_p(dirname($wpc_sub_path)); }
                                    wpc_crit_meta_write($wpc_sub_path, $wpc_sub_css);
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('font-subset-built', $urlKey, '', ['n' => $wpc_sub_n, 'bytes' => strlen($wpc_sub_css)]);
                                    }
                                } elseif (@is_readable($wpc_sub_path)) {
                                    @unlink($wpc_sub_path);
                                }
                                
                                
                                
                                if (!empty($wpc_rr_diag)) {
                                    update_option('wpc_fonts_consume_diag', ['t' => time(), 'src' => 'core-callback', 'rows' => array_slice($wpc_rr_diag, 0, 8)], false);
                                }
                                if (!empty($wpc_rr_map) && $wpc_sub_css !== '') {
                                    if (get_option('wpc_font_remote_ranges') !== $wpc_rr_map) {
                                        update_option('wpc_font_remote_ranges', $wpc_rr_map, false);
                                        if (function_exists('wpc_cache_first_log')) { wpc_cache_first_log('font-remote-ranges', (string) $urlKey, '', ['n' => count($wpc_rr_map), 'src' => 'core']); }
                                    }
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                    $wpc_cb_ready = !empty($_GET['ready']) ? sanitize_text_field(urldecode($_GET['ready'])) : '';
                    if ($wpc_cb_ready !== '' && function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('land-bundle-' . $wpc_cb_ready, $urlKey, (string) $pageUrl, []);
                    }


                    if ($wpc_cb_ready !== '' && function_exists('set_transient')) {
                        set_transient('wpc_land_ready_' . md5((string) $urlKey), $wpc_cb_ready, 600);
                    }
                    $jobStatus[] = $criticalCSS->saveCriticalCss($urlKey, ['url' => ['desktop' => $desktopCriticalCSS, 'mobile' => $mobileCriticalCSS], 'lcp_url' => $wpc_cb_lcp_url, 'lcp_src' => 'callback', 'delay_url' => $wpc_cb_delay_url, 'used_css_url' => $wpc_cb_used_css, 'tpl_key' => $wpc_cb_tpl_key], 'meta', $pageUrl);

                    
                    $mobileLCP = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/lcp-' . $uuid . '-mobile';
                    $desktopLCP = 'https://critical-css-mc.b-cdn.net/' . $uuidPart . '/lcp-' . $uuid . '-desktop';

                    $jobStatus[] = $criticalCSS->saveLCP($urlKey, ['url' => ['desktop' => $desktopLCP, 'mobile' => $mobileLCP]]);

                    
                    
                    
                    
                    
                    try {
                        $wpc_wire_url67 = !empty($wpc_cb_body['wire_url']) ? sanitize_url((string) $wpc_cb_body['wire_url'])
                            : (!empty($_GET['wire_url']) ? sanitize_url(urldecode((string) $_GET['wire_url'])) : '');
                        $wpc_wire_rev67 = isset($wpc_cb_body['wire_rev']) ? (int) $wpc_cb_body['wire_rev']
                            : (isset($_GET['wire_rev']) ? (int) $_GET['wire_rev'] : 0);
                        $wpc_wire_sig67 = !empty($wpc_cb_body['wire_sig']) ? (string) $wpc_cb_body['wire_sig']
                            : (!empty($_GET['wire_sig']) ? sanitize_text_field(urldecode((string) $_GET['wire_sig'])) : '');
                        if (($wpc_wire_url67 !== '' || $wpc_wire_rev67 > 0) && function_exists('wpc_consume_wire_artifact')) {
                            wpc_consume_wire_artifact($urlKey, $wpc_wire_url67, $wpc_wire_rev67, $wpc_wire_sig67);
                        }
                    } catch (\Throwable $e) {
                    }

                    if (apply_filters('wpc_lcp_async', true) && defined('WPS_IC_CRITICAL')
                        && !@is_readable(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/lcp.json')
                        && @is_readable(rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/lcp_url.txt')
                        && function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                        && !wp_next_scheduled('wpc_lcp_repull', [$urlKey, 1])) {
                        if (function_exists('wpc_pl_sched')) {
                            wpc_pl_sched(time() + 45, 'wpc_lcp_repull', [$urlKey, 1]);
                        } else {
                            wp_schedule_single_event(time() + 45, 'wpc_lcp_repull', [$urlKey, 1]);
                        }
                    }

                    wp_send_json_success($jobStatus);
                }

                wp_send_json_error('uuid-apikey-failure');
            }

            wp_send_json_error('failed');
        }
    }

    public function fetchPageSpeed()
    {
        if (!empty($_GET['pagespeedDone'])) {

            $jobStatus = [];
            $uuid = sanitize_text_field($_GET['uuid']);
            $apikey = sanitize_text_field($_GET['apikey']);

            if (!empty($uuid) && !empty($apikey)) {

                $this->debugPageSpeed('PageSpeed Started');

                $options = get_option(WPS_IC_OPTIONS);
                $dbApiKey = $options['api_key'];

                if ($dbApiKey == $apikey) {

                    
                    
                    @ignore_user_abort(true);
                    if (function_exists('set_time_limit')) { @set_time_limit(180); }
                    if (!headers_sent()) { http_response_code(200); }
                    if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
                    elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }

                    if (!empty($_GET['debug'])) {
                        ini_set('display_errors', 1);
                        error_reporting(E_ALL);
                    }

                    if (!class_exists('wps_ic_url_key')) {
                        include_once WPS_IC_DIR . 'traits/url_key.php';
                    }

                    $urlKey = new wps_ic_url_key();
                    $pageUrl = sanitize_url(urldecode($_GET['pageUrl']));
                    $urlKey = $urlKey->setup($pageUrl);

                    
                    $uuidPart = substr($uuid, 0, 4);

                    
                    $mobileCriticalCSS = 'https://critical-css.b-cdn.net/' . $uuidPart . '/' . $uuid . '-mobile.css';

                    
                    $desktopCriticalCSS = 'https://critical-css.b-cdn.net/' . $uuidPart . '/' . $uuid . '-desktop.css';

                    if (!class_exists('wps_criticalCss')) {
                        include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
                    }

                    $criticalCSS = new wps_criticalCss();

                    $jobStatus[] = $criticalCSS->saveBenchmark($urlKey, $uuid);

                    $this->debugPageSpeed('Pagespeed Done with uuid ' . $uuid . '!');
                    wp_send_json_success($jobStatus);
                }

                $this->debugPageSpeed('Apikey not matching!');
                wp_send_json_error('uuid-apikey-failure');
            }

            wp_send_json_error('failed');
        }
    }

    public function debugPageSpeed($message)
    {
        if (get_option('wps_ps_debug') == 'true') {
            $log_file = WPS_IC_LOG . 'pagespeed-log-' . date('d-m-Y') . '.txt';
            $time = current_time('mysql');

            if (!touch($log_file)) {
                error_log("Failed to create log file: $log_file");
            }

            $log = file_get_contents($log_file);
            $log .= '[' . $time . '] - ' . $message . "\r\n";
            file_put_contents($log_file, $log);
        }
    }


    



    public static function dontRunif()
    {

        if (self::hiddenAdminArea()) {
            return true;
        }

        if (get_option('pause_wpcompress_plugin')) {
            return true;
        }

        if (self::isPageBuilder()) {
            return true;
        }

        if (self::isPageBuilderFE()) {
            return true;
        }

        
        if (!empty($_POST['action']) && ($_POST['action'] == 'feedzy' || $_POST['action'] == 'action' || $_POST['action'] == 'elementor')) {
            return true;
        }

        if (!empty($_GET['wps_ic_action'])) {
            return true;
        }

        if (strpos($_SERVER['REQUEST_URI'], 'xmlrpc') !== false || strpos($_SERVER['REQUEST_URI'], 'wp-json') !== false) {
            return true;
        }

        if (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") {
            return true;
        }

        if (!empty($_GET['tatsu']) || !empty($_GET['tatsu-header']) || !empty($_GET['tatsu-footer'])) {
            return true;
        }

        if ((!empty($_GET['page']) && sanitize_text_field($_GET['page']) == 'livecomposer_editor')) {
            return true;
        }

        if (!empty($_GET['PageSpeed'])) {
            return true;
        }

        if (!empty($_GET['pagelayer-live'])) {
            return true;
        }

        
        if (isset($_GET['givewp-route'])) {
            return true;
        }

        return false;
    }

    public static function hiddenAdminArea()
    {

        
        if (class_exists('AIO_WP_Security')) {
            
            $configs = get_option('aio_wp_security_configs');
            if (!empty($configs['aiowps_login_page_slug'])) {
                if (strpos($_SERVER['REQUEST_URI'], $configs['aiowps_login_page_slug']) !== false) {
                    return true;
                }
            }
        }

        
        if (class_exists('WPS\WPS_Hide_Login\Plugin')) {
            
            $loginPage = get_option('whl_page');
            if (!empty($loginPage)) {
                if (strpos($_SERVER['REQUEST_URI'], '/' . $loginPage) !== false) {
                    return true;
                }
            }
        }

        
        if (class_exists('HMWP_Classes_ObjController')) {
            $option = get_option('hmwp_options');

            if (!empty($option)) {
                $option = json_decode($option, true);
                $loginPage = $option['hmwp_login_url'];
                if (!empty($loginPage)) {
                    if (strpos($_SERVER['REQUEST_URI'], $loginPage) !== false) {
                        return true;
                    }
                }
            }
        }

    }


    



    public static function isPageBuilder()
    {
        $page_builders = ['run_compress',
                'run_restore',
                'bwc',
                'elementor-preview',
                'fl_builder',
                'et_fb',
                'preview', 
                'builder',
                'brizy',
                'fb-edit',
                'bricks',
                'ct_template',
                'ct_builder',
                'cs-render',
                'tatsu',
                'trp-edit-translation',
                'brizy-edit-iframe',
                'ct_builder',
                'livecomposer_editor',
                'tatsu',
                'tatsu-header',
                'tatsu-footer',
                'tve',
                'is-editor-iframe',
                'pagelayer-live'];

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return true;
        }

        if (!empty($_POST['_cs_nonce'])) {
            return false;
        }

        if (!empty($_GET['page']) && sanitize_text_field($_GET['page']) == 'bwc') {
            return false;
        }

        if ((!empty($_GET['action']) && $_GET['action'] == 'in-front-editor')) {

            return true;
        }

        if ((!empty($_GET['action']) && sanitize_text_field($_GET['action']) == 'edit#op-builder') || !empty($_GET['op3editor'])) {

            return true;
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], 'wp-json') || strpos($_SERVER['REQUEST_URI'], 'rest_route')) {
                return false;
            }
        }

        if (!empty($page_builders)) {
            foreach ($page_builders as $page_builder) {
                if (isset($_GET[$page_builder])) {
                    return true;
                }
            }
        }

        return false;
    }


    



    public static function isPageBuilderFE()
    {
        if (class_exists('BT_BB_Root')) {
            if (is_user_logged_in() && !is_admin()) {
                return true;
            }
        }

        return false;
    }


    public function fillMissingSettings($settings)
    {
        if (!class_exists('wps_ic_options')) {
            require_once 'classes/options.class.php';
        }

        $foundMissing = false;
        $options = new wps_ic_options();
        $defaultSettings = $options->getDefault();

        if (empty($settings) || count($settings) <= 3) {
            $settings = [];
        }

        foreach ($defaultSettings as $option_key => $option_value) {
            if (is_array($option_value)) {
                foreach ($option_value as $option_value_k => $option_value_v) {
                    if (!isset($settings[$option_key][$option_value_k])) {
                        if (!isset($settings[$option_key])) {
                            $settings[$option_key] = [];
                        }
                        $settings[$option_key][$option_value_k] = $option_value_v;
                        $foundMissing = true;
                    }
                }
            } else {
                if (!isset($settings[$option_key])) {
                    $settings[$option_key] = $option_value;
                    $foundMissing = true;
                }
            }
        }

        if ($foundMissing) {
            update_option(WPS_IC_SETTINGS, $settings);
        }

        return $settings;
    }


    public function inAdmin()
    {
        add_action('current_screen', function () {
            if ( wp_doing_ajax() || ( defined('WP_CLI') && WP_CLI ) ) {
                return;
            }
            self::check_account_status();
        });

        if (!empty($_GET['resetHistory'])) {
            delete_option(WPS_IC_LITE_GPS_HISTORY);
        }

        if (!empty($_GET['testHistory'])) {
            $history = get_option(WPS_IC_LITE_GPS_HISTORY);
            var_dump($history);
        }

        $this->enqueues = new wps_ic_enqueues();
        $this->runInitialTest();

        
        
        
        
        
        $elementCache = get_option('elementor_element_cache_ttl');
        if ($elementCache !== false && !get_option('wpc_elementor_ec_set')) {
            if ((string) $elementCache !== 'disable') {
                update_option('elementor_element_cache_ttl', 'disable');
            }
            update_option('wpc_elementor_ec_set', 1, false);
        }


        if (current_user_can('manage_wpc_settings') && !empty($this::$options['api_key'])) {
            if (!class_exists('wps_ic_htaccess')) {
                include_once WPS_IC_DIR . 'classes/htaccess.class.php';
            }

            
            $htaccess = new wps_ic_htaccess();
            
            $this->integrations->init();
        }


        if (!empty($this::$options['api_key']) && empty($this::$zone_name) && get_option('wps_ic_allow_live') !== false
            && !(function_exists('wp_doing_ajax') && wp_doing_ajax())
            && (time() - (int) get_option('wpc_zone_backfill_at')) > HOUR_IN_SECONDS) {
            
            update_option('wpc_zone_backfill_at', time(), false);
            $url = 'https://apiv3.wpcompress.com/api/site/credits';
            $call = wp_remote_get($url, ['timeout' => 5, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $this::$options['api_key'], 'plugin-version' => self::$version]]);

            if (wp_remote_retrieve_response_code($call) == 200) {
                $body = wp_remote_retrieve_body($call);
                $body = json_decode($body, true);

                if (!empty($body['zone_name'])) {
                    self::$zone_name = $body['zone_name'];
                    update_option('ic_cdn_zone_name', $body['zone_name']);
                }
            }
        }

        
        if (is_multisite()) {
            $this->mu = new wps_ic_mu();
        }

        
        if (!$this::$settings) {
            $options = new wps_ic_options();
            $options->set_recommended_options();
        }

        
        $settings = get_option(WPS_IC_SETTINGS);
        if (empty($this::$settings['preload-scripts'])) {
            $settings['preload-scripts'] = '1';
            update_option(WPS_IC_SETTINGS, $settings);
        }

        
        if (!empty(self::$settings['cache']['advanced']) && self::$settings['cache']['advanced'] == '1') {
            if (!class_exists('wps_ic_htaccess')) {
                include_once WPS_IC_DIR . 'classes/htaccess.class.php';
            }

            
            $htacces = new wps_ic_htaccess();

            if (!empty($options['cache']['compatibility']) && $options['cache']['compatibility'] == '1' && $htacces->isApache) {
                
                
            } else {
                $htacces->removeHtaccessRules();
            }


            $wpc_livecdn = !empty(self::$settings['live-cdn']) && self::$settings['live-cdn'] == '1';
            if (!$wpc_livecdn && !empty(self::$settings['generate_webp']) && self::$settings['generate_webp'] == '1') {
                $htacces->addWebpReplace(); 
            } else {
                $htacces->removeWebpReplace();
            }

            
            $htacces->setWPCache(true);
            $htacces->setAdvancedCache();

            
            if ($htacces->isApache()) {
                $htacces->addGzip();
            }
        }


        
        add_action('admin_footer', ['wps_ic', 'deactivate_script']);
        add_action('admin_footer', ['wps_ic', 'checkQuotaStatus']);
        add_action('wpc_quota_status_refresh', ['wps_ic', 'checkQuotaStatusRefresh']);

        $this->cache = new wps_ic_cache_integrations();
        $this->cacheLogic = new wps_ic_cache();
        $this->ajax = new wps_ic_ajax();
        $this->menu = new wps_ic_menu();

        if (!class_exists('wps_ic_log')) {
            include_once WPS_IC_DIR . 'classes/log.class.php';
        }

        if (class_exists('wps_ic_log')) {
            $this->log = new wps_ic_log();
        }

        $this->templates = new wps_ic_templates();
        $this->notices = new wps_ic_notices();

        
        add_action('elementor/document/after_save', [$this->cacheLogic, 'purgeElementorCache'], 10, 2);

        
        $modes = new wps_ic_modes();
        add_action('admin_footer', [$modes, 'showPopup']);

        
        $this->cacheLogic->purgeHooks();

        add_filter('big_image_size_threshold', [$this, 'maxImageWidth'], 999, 1);

        
        $this->notices->connect_api_notice();

        
        if (empty(self::$settings['css']) && empty(self::$settings['js']) && empty(self::$settings['serve']['jpg']) && empty(self::$settings['serve']['png']) && empty(self::$settings['serve']['gif']) && empty(self::$settings['serve']['svg'])) {
            $this->localMode();
        } else {
            if (!empty(self::$api_key)) {
                $this->media_library = new wps_ic_media_library_live();
                $this->stats = new wps_ic_stats();
                $this->comms = new wps_ic_comms();
            }
        }

        if (!empty($_GET['reset_compress'])) {
            $this->reset_local_compress();
            die('Reset Done');
        }

        if (!empty($_GET['ic_stats'])) {
            $this->stats->fetch_live_stats();
            die();
        }

        $this::$settings = $this->fillMissingSettings($this::$settings);

        if (empty($this::$settings['live-cdn']) || $this::$settings['live-cdn'] == '0') {
            
            if (!empty($_GET['apikey'])) {
                if (self::$api_key !== sanitize_text_field($_GET['apikey'])) {
                    die('Bad Call');
                }
            }

            if (is_admin()) {
                if (!empty($_GET['deauth'])) {
                    $this->ajax->wps_ic_deauthorize_api();
                    wp_safe_redirect(wpc_settings_page_url());
                    die();
                }
            }
        }
    }

    public function runInitialTest()
    {

        if (!empty($_GET['forceInitial'])) {
            
            set_transient('wpc_run_initial_test', 'true', 5 * 60);
        }

        if (!empty($_GET['resetTest'])) {
            delete_transient('wpc_initial_test');
        }

        
        $initial = get_transient('wpc_run_initial_test');

        
        $initialTestRunning = get_transient('wpc_initial_test');

        
        $initialPageSpeedScore = get_option(WPS_IC_LITE_GPS);

        
        $options = get_option(WPS_IC_OPTIONS);

        
        if (empty($options['api_key'])) {
            return false;
        }

        if ((!empty($initial) && $initial === 'true') || (empty($initialPageSpeedScore) && empty($initialTestRunning))) {

            $apikey = $options['api_key'];

            
            set_transient('wpc_initial_test', 'true', 24 * 60 * 60);

            
            delete_transient('wpc_run_initial_test');

            
            $history = get_option(WPS_IC_LITE_GPS_HISTORY);
            if (empty($history)) {
                $history = [];
            }
            $history[time()] = get_option(WPS_IC_LITE_GPS);
            update_option(WPS_IC_LITE_GPS_HISTORY, $history);

            
            delete_option(WPS_IC_TESTS);
            delete_option(WPS_IC_LITE_GPS);
            delete_option(WPC_WARMUP_LOG_SETTING);
            delete_option('wpc_psi_insights');

            $requests = new wps_ic_requests();


            $psiUuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(8));
            set_transient('wpc_psi_uuid', $psiUuid, 30 * 60);

            
            $args = ['url' => home_url(), 'version' => self::$version, 'plugin_version' => self::$version, 'uuid' => $psiUuid, 'hash' => $psiUuid, 'apikey' => $apikey];
            $args['features'] = self::getActiveFeatures();


            if (apply_filters('wpc_psi_clean_after', true)) {
                $args['clean_after'] = 1;
            }
            
            $requests->POST(WPS_IC_PAGESPEED_API_URL_HOME, $args, ['timeout' => 2, 'blocking' => false, 'headers' => array('Content-Type' => 'application/json')]);
        }
    }

    public function localMode()
    {
        $this->queue = new wps_ic_queue();
        $this->compress = new wps_ic_compress();
        $this->controller = new wps_ic_controller();
        $this->remote_restore = new wps_ic_remote_restore();
        $this->comms = new wps_ic_comms();
        $this::$media_lib_ajax = $this->media_library = new wps_ic_media_library_live();
        $this->mu = new wps_ic_mu();
    }

    


    public function reset_local_compress()
    {
        $queue = $this->media_library->find_compressed_images();

        $compressed_images_queue = get_transient('wps_ic_restore_queue');

        if ($compressed_images_queue['queue']) {
            foreach ($compressed_images_queue['queue'] as $i => $image) {
                $attID = $image;
                delete_post_meta($attID, 'ic_status');
                delete_post_meta($attID, 'ic_stats');
                delete_post_meta($attID, 'ic_compressed_images');
            }
        }
    }

    


    public function inFrontEnd()
    {
        add_action('wp', [$this, 'do_enqueues']);

        $local = new wps_local_compress();
        $local->routes();

        


        $this->integrations->apply_frontend_filters();

        


        if (!empty($this::$settings['disable-oembeds']) && $this::$settings['disable-oembeds'] == '1') {
            $oEmbed = new wps_ic_oEmbed();
            $oEmbed->run();
        }

        


        if (!empty($this::$settings['disable-dashicons']) && $this::$settings['disable-dashicons'] == '1') {
            add_action('wp_enqueue_scripts', [$this, 'disableDashicons'], 999);
        }

        


        if (!empty($this::$settings['disable-gutenberg']) && $this::$settings['disable-gutenberg'] == '1') {
            add_action('wp_enqueue_scripts', [$this, 'disableGutenberg'], 1);
        }


        



        if (!empty($_GET['apiGenerateCritical'])) {
            $wpc_agc_opts = get_option(WPS_IC_OPTIONS);
            $wpc_agc_key = isset($_GET['apikey']) ? (string) $_GET['apikey'] : '';
            if (empty($wpc_agc_opts['api_key']) || $wpc_agc_key === '' || !hash_equals((string) $wpc_agc_opts['api_key'], $wpc_agc_key)
                || ((time() - (int) get_option('wpc_apigen_at')) < 60 && !update_option('wpc_apigen_at', time(), false))) {
                wp_send_json_error('unauthorized');
            }
            update_option('wpc_apigen_at', time(), false);
            
            
            $GLOBALS['wpc_gen_force496'] = 1;
            $criticalCSS = new wps_criticalCss();
            $criticalCSS->sendCriticalUrl('', 0);
            wp_send_json_success();
        }

        



        if (!empty($_GET['apiPreload'])) {
            $wpc_apl_opts = get_option(WPS_IC_OPTIONS);
            $wpc_apl_key = isset($_GET['apikey']) ? (string) $_GET['apikey'] : '';
            if (empty($wpc_apl_opts['api_key']) || $wpc_apl_key === '' || !hash_equals((string) $wpc_apl_opts['api_key'], $wpc_apl_key)) {
                wp_send_json_error('unauthorized');
            }
            $criticalCSS = new wps_criticalCss();
            $criticalCSS->sendCriticalUrl('', 0);
            wp_send_json_success();
        }

        $this->ajax = new wps_ic_ajax();

        



        if (!in_array($_SERVER['PHP_SELF'], ['/wp-login.php', '/wp-register.php'])) {
            $this->menu = new wps_ic_menu();

            


            if (self::$settings['css'] == 0 && self::$settings['js'] == 0 && self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) {
                
                $this->comms = new wps_ic_comms();
            } else {
                if (!empty(self::$api_key)) {
                    $this->comms = new wps_ic_comms();
                }
            }
        }
    }


    public function do_enqueues()
    {
        global $post;
        $wpc_excludes = get_option('wpc-excludes', []);
        if ($this->is_home_url()) {
            $page_excludes = isset($wpc_excludes['page_excludes']['home']) ? $wpc_excludes['page_excludes']['home'] : [];
        } else if (!empty(get_queried_object_id())) {
            $page_excludes = isset($wpc_excludes['page_excludes'][get_queried_object_id()]) ? $wpc_excludes['page_excludes'][get_queried_object_id()] : [];
        } elseif (!empty($post->ID)) {
            $page_excludes = isset($wpc_excludes['page_excludes'][$post->ID]) ? $wpc_excludes['page_excludes'][$post->ID] : [];
        } else {
            $page_excludes = [];
        }

        if (!empty($page_excludes)) {
            if (isset($page_excludes['cdn'])) {
                self::$settings['css'] = $page_excludes['cdn'];
                self::$settings['js'] = $page_excludes['cdn'];
                self::$settings['fonts'] = $page_excludes['cdn'];
                self::$settings['serve']['jpg'] = $page_excludes['cdn'];
                self::$settings['serve']['png'] = $page_excludes['cdn'];
                self::$settings['serve']['gif'] = $page_excludes['cdn'];
                self::$settings['serve']['svg'] = $page_excludes['cdn'];
            }


            
            if (isset($page_excludes['delay_js'])) {
                self::$settings['delay-js-v2'] = $page_excludes['delay_js'];
            } elseif (isset($page_excludes['delay_js_v2'])) {
                self::$settings['delay-js-v2'] = $page_excludes['delay_js_v2'];
            }

            if (isset($page_excludes['adaptive'])) {
                self::$settings['generate_adaptive'] = $page_excludes['adaptive'];
            }
        }


        $this->enqueues = new wps_ic_enqueues();
    }

    public function is_home_url()
    {
        $home_url = rtrim(home_url(), '/');
        $current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $current_url = rtrim($current_url, '/');

        return $home_url === $current_url;
    }

    



    public function disableDashicons()
    {
        if (!is_admin_bar_showing() && !is_customize_preview()) {
            wp_dequeue_style('dashicons');
            wp_deregister_style('dashicons');
        }
    }

    



    public function disableGutenberg()
    {

        wp_deregister_style('wp-block-library');
        wp_dequeue_style('wp-block-library');
        wp_deregister_style('wp-block-library-theme');
        wp_dequeue_style('wp-block-library-theme');


        wp_deregister_style('global-styles');
        wp_dequeue_style('global-styles');


        remove_action('wp_enqueue_scripts', 'wp_enqueue_global_styles');
        remove_action('wp_body_open', 'wp_global_styles_render_svg_filters');
    }

    public function maxImageWidth()
    {
        if (empty(self::$settings['max-original-width'])) {
            return 2560;
        }

        return self::$settings['max-original-width'];
    }


    public function geoLocateAjax()
    {
        if (!is_multisite()) {
            $siteurl = site_url();
        } else {
            $siteurl = network_site_url();
        }

        $call = wp_remote_get('https://cdn.zapwp.net/?action=geo_locate&domain=' . urlencode($siteurl), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($call) == 200) {
            $body = wp_remote_retrieve_body($call);
            $body = json_decode($body);

            if ($body->success) {
                update_option('wps_ic_geo_locate_v2', $body->data);
            } else {
                update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
            }

            wp_send_json_success($body->data);
        } else {
            update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
        }

        return false;
    }


    



    public function geoLocate()
    {
        $call = wp_remote_get('https://cdn.zapwp.net/?action=geo_locate&domain=' . urlencode(site_url()), ['timeout' => 30, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

        if (wp_remote_retrieve_response_code($call) == 200) {
            $body = wp_remote_retrieve_body($call);
            $body = json_decode($body);

            if ($body->success) {
                update_option('wps_ic_geo_locate_v2', $body->data);
            } else {
                update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
            }
        } else {
            update_option('wps_ic_geo_locate_v2', ['country' => 'EU', 'server' => 'frankfurt.zapwp.net']);
        }
    }

}

include WPS_IC_DIR . 'traits/excludes.php';

function wpc_convert_to_bytes($value) {
    $value = trim($value);
    $last = strtolower($value[strlen($value) - 1]);
    $num = (int)$value;

    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }

    return $num;
}


function wps_ic_format_bytes($bytes, $force_unit = null, $format = null, $si = false)
{
    
    $format = ($format === null) ? '%01.2f %s' : (string)$format;

    
    if (!$si or strpos($force_unit, 'i') !== false) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $mod = 1000;
    } 
    else {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $mod = 1000;
    }
    
    if (($power = array_search((string)$force_unit, $units)) === false) {
        $power = ($bytes > 0) ? floor(log($bytes, $mod)) : 0;
    }

    return sprintf($format, $bytes / pow($mod, $power), $units[$power]);
}


function wps_ic_size_format($bytes, $decimals)
{
    $quant = ['TB' => 1000 * 1000 * 1000 * 1000, 'GB' => 1000 * 1000 * 1000, 'MB' => 1000 * 1000, 'KB' => 1000, 'B' => 1,];

    if ($bytes == 0) {
        return '0 MB';
    }

    if ($bytes === 0) {
        return number_format_i18n(0, $decimals) . ' B';
    }

    foreach ($quant as $unit => $mag) {
        if ((float)$bytes >= $mag) {
            return number_format_i18n($bytes / $mag, $decimals) . ' ' . $unit;
        }
    }

    return false;
}


if (defined('WPC_IS_BG_SWAP') && WPC_IS_BG_SWAP) {
    return;
}



$wpsIc = new wps_ic();
add_action('init', [$wpsIc, 'init'], 100);


if (!class_exists('wps_cdn_rewrite', false)) {
    $cdn_file = __DIR__ . '/addons/cdn/cdn-rewrite.php';
    if (is_readable($cdn_file)) {
        include_once $cdn_file;
    }
}

if (!$wpsIc->isAgencyPortal() && class_exists('wps_cdn_rewrite', false)) {
    $cdn = new wps_cdn_rewrite();
    $wps_ic_cdn_instance = $cdn;
} else {
    
    $wps_ic_cdn_instance = null;
}


if (isset($cdn) && $cdn->isActive()) {
    add_action('plugins_loaded', [$cdn, 'checkCache_plugins_loaded'], 1);
    add_action('init', [$cdn, 'checkCache'], 1);
    add_action('wp', [$cdn, 'buffer_callback_v3'], 1);

    $elementor = new wps_ic_elementor();
    add_action('template_redirect', [$elementor, 'intercept_css_404'], 1);
}


add_filter('upgrader_post_install', ['wps_ic_cache', 'updateCSSHash'], 1);
add_filter('upgrader_post_install', [$wpsIc, 'deleteTests'], 1);


add_action('upgrader_process_complete', ['wps_ic_cache', 'updateCSSHash'], 1);
add_action('upgrader_process_complete', ['wps_ic_cache', 'purgeCDNUpdate'], 1);
add_action('wpc_update_hash_retry922', ['wps_ic_cache', 'updateCSSHash'], 1);
add_action('wpc_update_hash_retry922', ['wps_ic_cache', 'purgeCDNUpdate'], 2);


add_action('wpc_migrate_cf_bypass', function() {
    $cf = get_option(WPS_IC_CF);
    if (!empty($cf['token']) && !empty($cf['zone'])) {
        $cfsdk = new WPC_CloudflareAPI($cf['token']);
        $cfsdk->addCdnBypassRule($cf['zone']);
    }
});


add_action('activate_plugin', ['wps_ic_cache', 'updateCSSHash'], 1);
add_action('activate_plugin', [$wpsIc, 'deleteTests'], 1);
add_action('activated_plugin', ['wps_ic_cache', 'purgeCDNUpdate'], 1);


add_action('deactivate_plugin', [$wpsIc, 'deactivation'], 1, 1);




add_action('admin_init', [$wpsIc, 'checkPluginVersion'], 1);
add_action('plugins_loaded', 'wpcCheckCredits', PHP_INT_MAX);


register_activation_hook(WPC_CC_PLUGIN_FILE, [$wpsIc, 'activation']);
register_deactivation_hook(WPC_CC_PLUGIN_FILE, [$wpsIc, 'deactivation']);
register_uninstall_hook(WPC_CC_PLUGIN_FILE, 'wpcUninstall');


add_action('rest_api_init', function () {
    
    $local = new wps_local_compress();
    $local->registerEndpoints();
});


add_action('update_option_' . WPS_IC_SETTINGS, function () {
    delete_option('wpc_loopback_status');


    delete_transient('wpc_loopback_test_at');
});


add_action('admin_init', function () {


    if (function_exists('wp_doing_ajax') && wp_doing_ajax()) return;
    if (get_option('wpc_loopback_status', '') !== '') return;
    
    
    add_action('shutdown', function () {
        
        
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
        if (function_exists('ignore_user_abort')) { ignore_user_abort(true); }
        $local = new wps_local_compress();
        $local->testLoopback();
    }, PHP_INT_MAX);
}, 99);


add_action('admin_init', function () {
    if (get_option('wpc_serve_keys_reconciled_v70121')) return;
    $s = get_option(WPS_IC_SETTINGS);
    if (is_array($s) && !empty($s['serve']) && is_array($s['serve'])) {
        $any = false;
        foreach (['jpg', 'png', 'gif', 'svg'] as $k) {
            if (!empty($s['serve'][$k]) && (string) $s['serve'][$k] === '1') { $any = true; break; }
        }
        $v = $any ? '1' : '0';
        if ((string) ($s['serve']['jpg'] ?? '') !== $v || (string) ($s['serve']['png'] ?? '') !== $v
            || (string) ($s['serve']['gif'] ?? '') !== $v || (string) ($s['serve']['svg'] ?? '') !== $v) {
            $s['serve']['jpg'] = $s['serve']['png'] = $s['serve']['gif'] = $s['serve']['svg'] = $v;
            update_option(WPS_IC_SETTINGS, $s);
        }
    }
    update_option('wpc_serve_keys_reconciled_v70121', 1);
}, 98);






add_filter('wpc_src_hint_enabled', function ($on) {
    $s = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : null;
    if (is_array($s) && isset($s['emit-src-hints'])) {
        $v = (string) $s['emit-src-hints'];
        if ($v === '1') return true;
        if ($v === '0') return false;
    }
    return $on;
}, 20);






function wpc_do_cleanup_backups() {
    $backupDir = WP_CONTENT_DIR . '/wpc-backups/';
    if (!is_dir($backupDir)) return;

    $maxAge = 30 * DAY_IN_SECONDS;
    $now = time();
    $deleted = 0;

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($backupDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $file) {
        if ($file->isFile() && ($now - $file->getMTime()) > $maxAge) {
            @unlink($file->getPathname());
            $deleted++;
        }
    }

    
    $dirs = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($backupDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($dirs as $dir) {
        if ($dir->isDir()) {
            @rmdir($dir->getPathname()); 
        }
    }

    if ($deleted > 0) {
        error_log('[WPC Cleanup] Deleted ' . $deleted . ' backup files older than 30 days');
    }
}



add_action('admin_action_deactivate_and_disconnect', 'wpc_deactivate_delete_date');

add_action( 'init', 'wps_ic_load_textdomain' );

function wps_ic_load_textdomain() {
    load_plugin_textdomain(
            WPS_IC_TEXTDOMAIN,
            false,
            dirname( plugin_basename( __FILE__ ) ) . '/languages'
    );
}


add_action('update_option_wf301_redirect_rules', 'wpc_purge_redirect_cache', 10, 2);
add_action('update_option_301_redirects', 'wpc_purge_redirect_cache', 10, 2);
add_action('update_option_ts_301_redirection', 'wpc_purge_redirect_cache', 10, 2);
add_action('redirection_redirect_updated', 'wpc_purge_all_html_cache');
add_action('srm_redirect_saved', 'wpc_purge_all_html_cache');

function wpc_purge_redirect_cache($old_value, $new_value) {
    if (!is_array($new_value)) return;
    $url_key_class = new wps_ic_url_key();
    foreach ($new_value as $rule) {
        $source = isset($rule['url']) ? $rule['url'] : (isset($rule['request']) ? $rule['request'] : '');
        if (empty($source)) continue;
        $url_key = $url_key_class->setup(site_url($source));
        if (is_dir(WPS_IC_CACHE . $url_key)) {
            wps_ic_cache_integrations::purgeCacheFiles($url_key);
        }
    }
}

function wpc_purge_all_html_cache() {
    wps_ic_cache_integrations::purgeCacheFiles();
}

function wpcUninstall()
{
    try {
        $settings = get_option(WPS_IC_SETTINGS);
        $options = get_option(WPS_IC_OPTIONS);
        $connectivity = get_option('wpc-connectivity-status');
        $url = get_home_url();

        $data = ['settings' => $settings, 'options' => $options, 'connectivity' => $connectivity, 'url' => $url];

        $json_data = json_encode($data);

        $url = 'https://frankfurt.zapwp.net/uninstall/uninstall.php'; 

        $args = ['body' => $json_data, 'timeout' => '5', 'redirection' => '5', 'httpversion' => '1.0', 'blocking' => true, 'headers' => ['Content-Type' => 'application/json',],];

        $response = wp_remote_post($url, $args);
    } catch (Exception $e) {
        error_log($e->getMessage());
    }
}

function wpcGetHeader($headerName)
{
    $headerKey = 'HTTP_' . str_replace('-', '_', strtoupper($headerName));
    return $_SERVER[$headerKey] ?? null;
}

function wpcCheckCredits()
{

    
    if (!is_admin()) {
        return;
    }

    $transient_key = 'wps_ic_credits_check';
    if (get_transient($transient_key)) {
        return;
    }

    
    
    $wpc_ccf12 = (int) get_option('wpc_credits_check_at');
    if (time() - $wpc_ccf12 < 12 * HOUR_IN_SECONDS) {
        return;
    }

    $options = get_option(WPS_IC_OPTIONS);

    if (empty($options) || empty($options['api_key'])) {
        return;
    }

    update_option('wpc_credits_check_at', time(), false);

    $url = 'https://apiv3.wpcompress.com/api/site/credits';


    $call = wp_remote_get($url, ['timeout' => (int) apply_filters('wpc_credits_check_timeout', 2), 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT, 'headers' => ['apikey' => $options['api_key'], 'plugin-version' => wps_ic::$version]]);

    if (is_wp_error($call)) {
        
        set_transient($transient_key, true, MINUTE_IN_SECONDS);
        return;
    }

    $body = wp_remote_retrieve_body($call);
    $response_code = wp_remote_retrieve_response_code($call);

    if ($response_code !== 200) {
        
        set_transient($transient_key, true, MINUTE_IN_SECONDS);
        return;
    }

    $data = json_decode($body);

    if (json_last_error() !== JSON_ERROR_NONE) {
        set_transient($transient_key, true, 15 * MINUTE_IN_SECONDS);
        return;
    }

    $allow_local = true;
    $allow_live = true;

    if (!empty($data->suspended) && $data->suspended == 1) {
        $allow_local = false;
        $allow_live = false;
    }

    $updated_local = ((bool) $allow_local !== (bool) get_option('wps_ic_allow_local')) ? update_option('wps_ic_allow_local', $allow_local) : false;
    $updated_live = ((bool) $allow_live !== (bool) get_option('wps_ic_allow_live')) ? update_option('wps_ic_allow_live', $allow_live) : false;

    
    if ($updated_local || $updated_live) {
        if (class_exists('wps_ic_cache_integrations')) {
            $cache = new wps_ic_cache_integrations();
            $cache::purgeAll();
        }
    }

    set_transient($transient_key, true, 43200);
}


add_action('admin_init', function () {
    if (!apply_filters('wpc_autoload_debloat', true)) {
        return;
    }
    $ver = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '0';
    if (get_option('wpc_autoload_debloat_v') === $ver) {
        return;
    }
    global $wpdb;
    $names = ['wps_ic_purge_rules', 'wps_ic_parsed_images', 'wps_ic_excluded_list', 'wps-ic-background-compress-queue', 'wps_ic_mu_site_list'];
    $in = implode(',', array_map(function ($n) { return "'" . esc_sql($n) . "'"; }, $names));
    $wpdb->query("UPDATE {$wpdb->options} SET autoload = 'no' WHERE option_name IN ($in) AND autoload IN ('yes','on','auto','auto-on','auto-yes')");
    if (function_exists('wp_cache_delete')) {
        wp_cache_delete('alloptions', 'options');
    }
    update_option('wpc_autoload_debloat_v', $ver, false);
}, 1);


function wpc_deactivate_delete_date()
{
    $plugin = isset($_GET['plugin']) ? sanitize_text_field(wp_unslash($_GET['plugin'])) : '';
    $c = check_admin_referer('deactivate-plugin_' . $plugin);

    if ($plugin === 'wp-compress-image-optimizer/wp-compress.php') {
        wpc_delete_and_remove_data();
    }
}

function wpc_delete_and_remove_data()
{
    
    $timestamp = wp_next_scheduled('runCronPreload');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'runCronPreload');
    }

    if (!class_exists('wps_ic_htaccess')) {
        include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }

    
    $htaccess = new wps_ic_htaccess();
    $htaccess->removeHtaccessRules();

    
    $htaccess->setWPCache(false);
    $htaccess->removeAdvancedCache();

    
    $cacheLogic = new wps_ic_cache();
    if (file_exists(WPS_IC_CACHE)) {
        $cacheLogic::deleteFolder(WPS_IC_CACHE);
    }

    if (file_exists(WPS_IC_CRITICAL)) {
        $cacheLogic::deleteFolder(WPS_IC_CRITICAL);
    }

    if (file_exists(WPS_IC_COMBINE)) {
        $cacheLogic::deleteFolder(WPS_IC_COMBINE);
    }

    
    delete_transient('wps_ic_live_stats');
    delete_transient('wps_ic_local_stats');

    
    delete_option('wps_ic_gen_hp_url');
    delete_option(WPS_IC_GUI);
    delete_option('wps_log_critCombine');

    
    delete_option(WPS_IC_TESTS);
    delete_transient('wpc_test_running');
    delete_transient('wpc_initial_test');
    delete_option(WPS_IC_LITE_GPS);
    delete_option(WPC_WARMUP_LOG_SETTING);
    delete_option('wpc_psi_insights');

    
    $settings = get_option(WPS_IC_MU_SETTINGS);
    $settings['hide_compress'] = 0;
    update_option(WPS_IC_MU_SETTINGS, $settings);

    
    $options = get_option(WPS_IC_OPTIONS);
    $site = site_url();
    $apikey = $options['api_key'];

    unset($options['api_key']);
    $newOptions = $options;
    $newOptions['regExUrl'] = '';
    $newOptions['regexpDirectories'] = '';
    update_option(WPS_IC_OPTIONS, $newOptions);

    $cfSettings = get_option(WPS_IC_CF);

    if (!empty($cfSettings)) {
        $zone = $cfSettings['zone'];
        $cfapi = new WPC_CloudflareAPI($cfSettings['token']);
        $cfapi->removeCacheRules($zone);
    }

    
    $uri = WPS_IC_KEYSURL . '?action=disconnect&apikey=' . $apikey . '&site=' . urlencode($site);

    
    $get = wp_remote_get($uri, ['timeout' => 5, 'sslverify' => false, 'user-agent' => WPS_IC_API_USERAGENT]);

    deactivate_plugins('wp-compress-image-optimizer/wp-compress.php');

    if (get_option('pause_wpcompress_plugin_full_delete')){

        delete_option('pause_wpcompress_plugin_full_delete');
        delete_plugins(['wp-compress-image-optimizer/wp-compress.php']);

        $active_plugins = get_option('active_plugins');
        $plugin_slug = 'wp-compress-image-optimizer/wp-compress.php';
        $key = array_search($plugin_slug, $active_plugins);
        if ($key !== false) {
            unset($active_plugins[$key]);
            update_option('active_plugins', $active_plugins);
        }
    }
    wp_safe_redirect(admin_url('plugins.php?deactivate=true'));
}

add_action('do_faviconico', function () {
    if (!apply_filters('wpc_favicon_optimize', true) || headers_sent()) {
        return;
    }
    $icon = function_exists('get_site_icon_url') ? (string) get_site_icon_url(32) : '';
    if ($icon !== '') {
        header('Cache-Control: public, max-age=86400');
        wp_redirect($icon, 301);
        exit;
    }
    $f = ABSPATH . 'wp-includes/images/w-logo-blue-white-bg.png';
    if (@is_file($f)) {
        status_header(200);
        header('Content-Type: image/png');
        header('Content-Length: ' . (string) filesize($f));
        header('Cache-Control: public, max-age=31536000, immutable');
        readfile($f);
        exit;
    }
    status_header(204);
    header('Cache-Control: public, max-age=86400');
    exit;
}, 1);


add_filter('wp_calculate_image_sizes', function ($sizes, $size, $image_src, $image_meta, $attachment_id) {
    static $done = false;
    if ($done || is_admin()) return $sizes;
    $s = get_option(WPS_IC_SETTINGS);
    if (!is_array($s) || empty($s['optimize-lcp'])) return $sizes;
    $w = 0;
    if (is_array($size) && !empty($size[0])) $w = (int) $size[0];
    if ($w <= 0 && is_array($image_meta) && !empty($image_meta['width'])) $w = (int) $image_meta['width'];
    if ($w < 1200) return $sizes;
    $done = true;
    $maxW = !empty($s['maxWidth']) ? (int) $s['maxWidth'] : 2560;
    $cw   = function_exists('wpc_get_theme_content_width') ? (int) wpc_get_theme_content_width() : 0;
    $cap  = $cw > 0 ? $cw : min(1200, max(400, $maxW));
    $ladder = '(max-width: 600px) 50vw, (max-width: 1024px) 40vw, ' . $cap . 'px';
    return (string) apply_filters('wpc_picture_lcp_sizes', $ladder, ['width' => $w], $s);
}, 20, 5);







add_action('update_option_' . WPS_IC_SETTINGS, function ($old, $new) {
    if (!is_array($old)) $old = [];
    if (!is_array($new)) $new = [];
    foreach (['generate_webp', 'picture_avif', 'wpc_nextgen'] as $k) {
        if ((string) ($old[$k] ?? '') !== (string) ($new[$k] ?? '')) {
            if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
                && !wp_next_scheduled('wpc_sitechange_trailing')) {
                wp_schedule_single_event(time() + 8, 'wpc_sitechange_trailing');
            }
            do_action('breeze_clear_all_cache');
            if (function_exists('error_log')) {
                error_log('[WPC FormatToggle] ' . $k . ' changed — page cache purged (format-fill can scan fresh renders)');
            }
            break;
        }
    }
}, 10, 2);


$wpc_rebake_dropin_excludes = function () {
    $s = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
    if (empty($s['cache']['advanced']) || $s['cache']['advanced'] != '1') {
        return;
    }


    $wpc_dropin = ABSPATH . 'wp-content/advanced-cache.php';
    if (!file_exists($wpc_dropin)) {
        return;
    }
    $wpc_dropin_head = @file_get_contents($wpc_dropin, false, null, 0, 256);
    if ($wpc_dropin_head === false || strpos($wpc_dropin_head, 'WP_COMPRESS_ADVANCED_CACHE') === false) {
        return;
    }
    if (!class_exists('wps_ic_htaccess')) {
        @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }
    if (class_exists('wps_ic_htaccess')) {
        try {
            $htaccess = new wps_ic_htaccess();
            $htaccess->setAdvancedCache();
        } catch (\Throwable $e) {}
    }
};
add_action('update_option_wpc-excludes', $wpc_rebake_dropin_excludes);
add_action('add_option_wpc-excludes', $wpc_rebake_dropin_excludes);
add_action('update_option_wpc-url-excludes', $wpc_rebake_dropin_excludes);
add_action('add_option_wpc-url-excludes', $wpc_rebake_dropin_excludes);


add_filter('wpc_static_serve', function ($v) {
    if ($v) {
        return $v; 
    }
    $s = function_exists('get_option') ? get_option(WPS_IC_SETTINGS) : [];
    return is_array($s) && !empty($s['static-serve']) && $s['static-serve'] == '1';
});
add_action('update_option_' . WPS_IC_SETTINGS, function ($old, $new) {
    static $reentry = false;
    if ($reentry) {
        return;
    }
    $wasOn = is_array($old) && !empty($old['static-serve']) && $old['static-serve'] == '1';
    $isOn  = is_array($new) && !empty($new['static-serve']) && $new['static-serve'] == '1';
    if ($wasOn === $isOn) {
        return;
    }
    if (!class_exists('wps_ic_htaccess')) {
        @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }
    if (!class_exists('wps_ic_htaccess')) {
        return;
    }
    try {
        $h = new wps_ic_htaccess();
        if ($isOn) {
            $res = $h->applyStaticServe();
            if (empty($res['ok'])) {
                
                update_option('wpc_static_serve_failed', isset($res['reason']) ? $res['reason'] : 'failed', false);
                if (strpos((string) ($res['reason'] ?? ''), 'litespeed-family') !== false) {
                    update_option('wpc_ss_retry921', time(), false);
                }
                if (is_array($new)) {
                    $new['static-serve'] = '0';
                    $reentry = true;
                    update_option(WPS_IC_SETTINGS, $new);
                    $reentry = false;
                }
            }
        } else {
            
            
            
            
            $wpc_was_ss357 = ($wasOn || get_option('wpc_ttfb_ss_auto') === '1' || get_option('wpc_static_serve_active') == 1);
            if ($wasOn) {
                update_option('wpc_ttfb_ss_optout', 1, false);
            }
            $h->removeStaticServe();
            
            
            
            
            if ($wpc_was_ss357 && class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'cfPurgeAllHtml')) {
                try { wps_ic_cache::cfPurgeAllHtml(false, true); } catch (\Throwable $e) {}
            }
        }
    } catch (\Throwable $e) {}
}, 10, 2);







add_action('admin_init', function () {
    if (!(int) get_option('wpc_ss_retry921')) {
        return;
    }
    if (get_option('wpc_ttfb_ss_optout') || get_option('wpc_static_serve_active') == 1) {
        delete_option('wpc_ss_retry921');
        return;
    }
    if (!apply_filters('wpc_ss_ols_retry', true) || get_transient('wpc_ss_retry_t921')) {
        return;
    }
    set_transient('wpc_ss_retry_t921', 1, DAY_IN_SECONDS);
    if (!class_exists('wps_ic_htaccess')) {
        @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }
    if (!class_exists('wps_ic_htaccess')) {
        return;
    }
    try {
        $wpc_h921 = new wps_ic_htaccess();
        if (method_exists($wpc_h921, 'isApache')) {
            $wpc_h921->isApache();
        }
        $wpc_r921 = $wpc_h921->applyStaticServe();
        if (!empty($wpc_r921['ok'])) {
            delete_option('wpc_ss_retry921');
            delete_option('wpc_static_serve_failed');
            $wpc_set921 = get_option(WPS_IC_SETTINGS);
            if (is_array($wpc_set921)) {
                $wpc_set921['static-serve'] = '1';
                update_option(WPS_IC_SETTINGS, $wpc_set921);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('ss-ols-selfheal-armed', '', '', []);
            }
        }
    } catch (\Throwable $e) {}
}, 35);


add_action('update_option_' . WPS_IC_SETTINGS, function ($old, $new) {
    static $bcReentry = false;
    if ($bcReentry) {
        return;
    }
    $wasOn = is_array($old) && !empty($old['browser-cache-headers']) && $old['browser-cache-headers'] == '1';
    $isOn  = is_array($new) && !empty($new['browser-cache-headers']) && $new['browser-cache-headers'] == '1';
    if ($wasOn === $isOn) {
        return;
    }
    if (!class_exists('wps_ic_htaccess')) {
        @include_once WPS_IC_DIR . 'classes/htaccess.class.php';
    }
    if (!class_exists('wps_ic_htaccess')) {
        return;
    }
    try {
        $h = new wps_ic_htaccess();
        if ($isOn) {
            $bcReentry = true;
            $h->wpcApplyBrowserCache();
            $bcReentry = false;
        } else {
            $h->wpcRemoveBrowserCache();
        }
    } catch (\Throwable $e) {
        $bcReentry = false;
    }
}, 10, 2);


if (!function_exists('wpc_delay_v3_report_handler')) {
    function wpc_delay_v3_report_handler()
    {
        $rate = (int) get_transient('wpc_delay_v3_report_rate');
        if ($rate > 200) {
            wp_send_json_error('rate', 429);
        }
        set_transient('wpc_delay_v3_report_rate', $rate + 1, HOUR_IN_SECONDS);


        $wpc_src = !empty($_SERVER['HTTP_ORIGIN']) ? $_SERVER['HTTP_ORIGIN'] : (!empty($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '');
        $wpc_sh  = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        $wpc_oh  = strtolower((string) parse_url($wpc_src, PHP_URL_HOST));
        $wpc_strip = function ($h) { return strpos($h, 'www.') === 0 ? substr($h, 4) : $h; };
        if ($wpc_oh === '' || $wpc_strip($wpc_oh) !== $wpc_strip($wpc_sh)) {
            wp_send_json_error('bad-origin');
        }
        
        
        
        $raw = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload'])
             : (isset($_GET['payload']) ? (string) wp_unslash($_GET['payload']) : '');
        if ($raw === '' || strlen($raw) > 2048) {
            wp_send_json_error('bad-payload');
        }
        $data = json_decode($raw, true);
        $wpc_bootfail360 = is_array($data) && isset($data['b']) && (int) $data['b'] === 0;
        $wpc_bootretr360 = is_array($data) && isset($data['b']) && (int) $data['b'] === 1;
        $wpc_lcpmx440 = is_array($data) && !empty($data['lcpmx']);
        $wpc_lcpok447 = is_array($data) && !empty($data['lcpok']);
        $wpc_lcptr452 = is_array($data) && !empty($data['lcptrace']);
        if (!is_array($data) || ((empty($data['e']) || !is_array($data['e']))
            && !$wpc_bootfail360 && !$wpc_bootretr360 && !$wpc_lcpmx440 && !$wpc_lcpok447 && !$wpc_lcptr452)) {
            wp_send_json_error('bad-payload');
        }
        if (!isset($data['e']) || !is_array($data['e'])) {
            $data['e'] = [];
        }
        
        
        
        
        
        
        
        
        if ($wpc_bootfail360 || $wpc_bootretr360) {
            $wpc_bf360 = get_option('wpc_delay_v3_bootfails', []);
            if (!is_array($wpc_bf360) || (isset($wpc_bf360['t']) && time() - (int) $wpc_bf360['t'] > DAY_IN_SECONDS)) {
                $wpc_bf360 = [];
            }
            if (empty($wpc_bf360)) {
                $wpc_bf360 = ['t' => time(), 'u' => [], 'p' => []];
            }
            if (!isset($wpc_bf360['p']) || !is_array($wpc_bf360['p'])) {
                $wpc_bf360['p'] = [];
            }
            $wpc_bp360 = isset($data['u']) ? sanitize_text_field(substr((string) $data['u'], 0, 120)) : '';
            $wpc_bu360 = substr(md5($wpc_bp360), 0, 8);
            if ($wpc_bootretr360) {
                $wpc_bi360 = array_search($wpc_bu360, (array) $wpc_bf360['u'], true);
                if ($wpc_bi360 !== false) {
                    array_splice($wpc_bf360['u'], (int) $wpc_bi360, 1);
                    unset($wpc_bf360['p'][$wpc_bu360]);
                    update_option('wpc_delay_v3_bootfails', $wpc_bf360, false);
                }
            } elseif (count((array) $wpc_bf360['u']) < 10 && !in_array($wpc_bu360, (array) $wpc_bf360['u'], true)) {
                $wpc_bf360['u'][] = $wpc_bu360;
                if (count($wpc_bf360['p']) < 3 && $wpc_bp360 !== '' && strpos($wpc_bp360, '/') === 0) {
                    $wpc_bf360['p'][$wpc_bu360] = $wpc_bp360;
                }
                update_option('wpc_delay_v3_bootfails', $wpc_bf360, false);
            }
            if ($wpc_bootfail360 && count((array) $wpc_bf360['u']) >= 3 && !get_option('wpc_delay_aggr_off')) {
                $wpc_fails360 = (int) get_option('wpc_delay_aggr_fails', 0);
                update_option('wpc_delay_aggr_off', time(), false);
                update_option('wpc_delay_aggr_fails', $wpc_fails360 + 1, false);
                foreach ((array) $wpc_bf360['p'] as $wpc_pp360) {
                    try {
                        if (class_exists('wps_ic_url_key') && class_exists('wps_ic_cache_integrations')
                            && method_exists('wps_ic_cache_integrations', 'purgeUrlHtml')) {
                            $wpc_pk360 = (new wps_ic_url_key())->setup(home_url($wpc_pp360));
                            if ($wpc_pk360) {
                                wps_ic_cache_integrations::purgeUrlHtml($wpc_pk360, '', ['context' => 'aggr-demote']);
                            }
                        }
                    } catch (\Throwable $e) {
                    }
                }
                delete_option('wpc_delay_v3_bootfails');
                if (function_exists('wpc_diagnostic_log')) {
                    wpc_diagnostic_log('DELAY_AGGR_DEMOTED', 'boot watchdog: 3 distinct aggr paths failed to boot — demoted to timer (targeted purge)');
                }
            }
        }
        if ($wpc_bootretr360 && empty($data['e'])) {
            wp_send_json_success('retracted');
        }
        
        
        
        
        
        
        
        
        
        
        
        
        if ($wpc_lcptr452) {
            $wpc_trs452 = get_option('wpc_lcp_trace_reports', []);
            if (!is_array($wpc_trs452)) { $wpc_trs452 = []; }
            $wpc_num452 = function ($v) { return is_numeric($v) ? (int) $v : 0; };
            $wpc_trs452[] = [
                't'   => time(),
                'u'   => sanitize_text_field(substr((string) ($data['u'] ?? ''), 0, 60)),
                'fcp' => $wpc_num452($data['fcp'] ?? 0),
                'lcp' => $wpc_num452($data['lcp'] ?? 0),
                'gap' => $wpc_num452($data['gap'] ?? 0),
                'own' => $wpc_num452($data['own'] ?? 0),
                'pct' => $wpc_num452($data['pct'] ?? 0),
                'v'   => sanitize_text_field(substr((string) ($data['v'] ?? ''), 0, 20)),
                'ch'  => in_array(($data['ch'] ?? ''), ['b', 'i'], true) ? (string) $data['ch'] : '?',
                'hum' => isset($data['hum']) ? (int) $data['hum'] : -1,
                'ltn' => $wpc_num452($data['ltn'] ?? 0),
                'ltms'=> $wpc_num452($data['ltms'] ?? 0),
                'top' => isset($data['top']) ? (int) $data['top'] : -1,
                'vh'  => isset($data['vh'])  ? (int) $data['vh']  : -1,
                'inv' => isset($data['inv']) ? (int) $data['inv'] : -1,
                'el'  => sanitize_text_field(substr((string) ($data['el'] ?? ''), 0, 32)),
                'url' => sanitize_text_field(substr((string) ($data['url'] ?? ''), 0, 48)),
                'r'   => is_array($data['r'] ?? null) ? array_map($wpc_num452, array_slice($data['r'], 0, 8)) : [],
                'net' => is_array($data['net'] ?? null) ? array_slice($data['net'], 0, 6) : [],
                'lt'  => is_array($data['lt'] ?? null) ? array_slice($data['lt'], 0, 5) : [],
            ];
            
            
            
            
            update_option('wpc_lcp_trace_reports',
                array_slice($wpc_trs452, -(int) apply_filters('wpc_lcp_trace_keep', 8)), false);
            if (empty($data['e'])) {
                wp_send_json_success('lcptrace');
            }
        }
        if ($wpc_lcpok447) {
            $wpc_ok447 = get_option('wpc_lcp_preload_ok', []);
            if (!is_array($wpc_ok447)) { $wpc_ok447 = []; }
            $wpc_ok447 = [
                't' => time(),
                'n' => isset($wpc_ok447['n']) ? min((int) $wpc_ok447['n'] + 1, 1000000) : 1,
            ];
            update_option('wpc_lcp_preload_ok', $wpc_ok447, false);
            if (empty($data['e'])) {
                wp_send_json_success('lcpok');
            }
        }
        if ($wpc_lcpmx440) {
            $wpc_mx440 = get_option('wpc_lcp_preload_mismatch', []);
            if (!is_array($wpc_mx440)) { $wpc_mx440 = []; }
            $wpc_mxu440 = isset($data['u'])    ? sanitize_text_field(substr((string) $data['u'], 0, 120)) : '';
            $wpc_mxg440 = isset($data['got'])  ? sanitize_text_field(substr((string) $data['got'], 0, 80)) : '';
            $wpc_mxw440 = isset($data['want']) ? sanitize_text_field(substr((string) $data['want'], 0, 80)) : '';
            if ($wpc_mxw440 !== '') {
                $wpc_mxk440 = substr(md5($wpc_mxu440 . '|' . $wpc_mxg440 . '|' . $wpc_mxw440), 0, 10);
                $wpc_mx440[$wpc_mxk440] = [
                    't'    => time(),
                    'u'    => $wpc_mxu440,
                    'got'  => $wpc_mxg440,
                    'want' => $wpc_mxw440,
                    'n'    => isset($wpc_mx440[$wpc_mxk440]['n']) ? (int) $wpc_mx440[$wpc_mxk440]['n'] + 1 : 1,
                ];
                update_option('wpc_lcp_preload_mismatch', array_slice($wpc_mx440, -20, null, true), false);
            }
            if (empty($data['e'])) {
                wp_send_json_success('lcpmx');
            }
        }
        $log = get_option('wpc_delay_v3_errors', []);
        if (!is_array($log)) {
            $log = [];
        }
        $url = isset($data['u']) ? sanitize_text_field(substr((string) $data['u'], 0, 120)) : '';
        foreach (array_slice($data['e'], 0, 10) as $e) {
            if (!is_array($e)) {
                continue;
            }
            $msg  = isset($e['m']) ? sanitize_text_field(substr((string) $e['m'], 0, 180)) : '';
            $file = isset($e['f']) ? sanitize_text_field(substr((string) $e['f'], 0, 160)) : '';
            if ($msg === '') {
                continue;
            }
            $key = md5($msg . '|' . $file);
            $log[$key] = ['t' => time(), 'm' => $msg, 'f' => $file, 'u' => $url, 'n' => isset($log[$key]['n']) ? (int) $log[$key]['n'] + 1 : 1];
        }
        update_option('wpc_delay_v3_errors', array_slice($log, -30, null, true), false);


        $wpc_dur = isset($data['d']) ? (int) $data['d'] : 0;
        if ($wpc_dur > 0 && $wpc_dur < 60000) {
            $stats = get_option('wpc_delay_v3_stats', []);
            if (!is_array($stats)) {
                $stats = [];
            }
            $stats[] = $wpc_dur;
            update_option('wpc_delay_v3_stats', array_slice($stats, -50), false);
        }


        $wpc_promoted = get_option('wpc_delay_v3_promoted', []);
        if (!is_array($wpc_promoted)) {
            $wpc_promoted = [];
        }
        if (!empty($wpc_promoted) && !get_option('wpc_delay_v3_manifest_off')
            && apply_filters('wpc_delay_v3_autotune', true)) {
            foreach ($log as $entry) {
                if (empty($entry['f']) || (int) $entry['n'] < 2) {
                    continue;
                }
                $wpc_m = (string) $entry['m'];
                if (stripos($wpc_m, 'is not defined') === false
                    && stripos($wpc_m, "can't find variable") === false) {
                    continue; 
                }
                $wpc_fh = strtolower((string) parse_url((string) $entry['f'], PHP_URL_HOST));
                if ($wpc_fh !== '' && $wpc_strip($wpc_fh) !== $wpc_strip($wpc_sh)
                    && strpos($wpc_fh, 'zapwp') === false && strpos($wpc_fh, 'b-cdn') === false) {
                    continue;
                }
                $wpc_pb = basename((string) parse_url((string) $entry['f'], PHP_URL_PATH));
                if ($wpc_pb === '' || !in_array($wpc_pb, $wpc_promoted, true)) {
                    continue;
                }
                update_option('wpc_delay_v3_manifest_off', time(), false);
                set_transient('wpc_delay_v3_manifest_notice', $wpc_pb, WEEK_IN_SECONDS);
                if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                    try {
                        wps_ic_cache::removeHtmlCacheFiles('all');
                    } catch (\Throwable $t) {
                    }
                }
                break;
            }
        }


        if (apply_filters('wpc_delay_v3_autotune', true)) {
            $tuned = get_option('wpc_delay_v3_autotuned', []);
            if (!is_array($tuned)) {
                $tuned = [];
            }
            if (count($tuned) < 5) {
                foreach ($log as $entry) {
                    if ((int) $entry['n'] < 3 || empty($entry['f'])) {
                        continue;
                    }
                    
                    
                    
                    
                    
                    
                    if (preg_match('/\.\s*(?:on|each|extend|hasclass|ready|ajax|fn)\b[^a-z]{0,4}is not a function|pseudos|jquery is not|\$ is not/i', (string) $entry['m'])
                        && !apply_filters('wpc_autotune_jqenv_ok', false, (string) $entry['f'])) {
                        continue;
                    }


                    $wpc_fh = strtolower((string) parse_url((string) $entry['f'], PHP_URL_HOST));
                    if ($wpc_fh !== '' && $wpc_strip($wpc_fh) !== $wpc_strip($wpc_sh)
                        && strpos($wpc_fh, 'zapwp') === false && strpos($wpc_fh, 'b-cdn') === false) {
                        continue;
                    }
                    $base = basename((string) parse_url($entry['f'], PHP_URL_PATH));

                    if ($base === '' || strlen($base) < 6 || strpos($base, 'delay-v3-loader') !== false || strpos($base, 'optimize') === 0) {
                        continue;
                    }


                    if ((strpos($base, 'jquery') !== false || preg_match('/-js-(after|before)$/', $base))
                        && !apply_filters('wpc_autotune_jquery_ok', false, $base)) {
                        continue;
                    }

                    
                    if (in_array($base, $wpc_promoted, true)) {
                        continue;
                    }
                    if (isset($tuned[$base])) {
                        continue;
                    }
                    $ex = get_option('wpc-excludes', []);
                    if (!is_array($ex)) {
                        $ex = [];
                    }
                    if (empty($ex['delay_js_v2']) || !is_array($ex['delay_js_v2'])) {
                        $ex['delay_js_v2'] = [];
                    }
                    if (!in_array($base, $ex['delay_js_v2'], true)) {
                        $ex['delay_js_v2'][] = $base;
                        update_option('wpc-excludes', $ex);
                        set_transient('wpc_delay_v3_autotune_notice', $base, WEEK_IN_SECONDS);
                        if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                            try {
                                wps_ic_cache::removeHtmlCacheFiles('all');
                            } catch (\Throwable $t) {
                            }
                        }
                    }
                    $tuned[$base] = time();
                    update_option('wpc_delay_v3_autotuned', $tuned, false);
                    break;
                }
            }
        }
        wp_send_json_success();
    }
    add_action('wp_ajax_wpc_delay_v3_report', 'wpc_delay_v3_report_handler');
    add_action('wp_ajax_nopriv_wpc_delay_v3_report', 'wpc_delay_v3_report_handler');
    add_action('admin_notices', function () {
        $base = get_transient('wpc_delay_v3_autotune_notice');
        if (empty($base)) {
            return;
        }
        echo '<div class="notice notice-info is-dismissible"><p><strong>WP Compress — JavaScript delay self-tuned:</strong> visitors repeatedly hit errors from <code>'
            . esc_html($base) . '</code> while it was delayed, so it was automatically added to your "Scripts to Exclude" list (Optimize JavaScript → Excludes) and the page cache was refreshed. You can remove it there any time.</p></div>';
        delete_transient('wpc_delay_v3_autotune_notice');
    });
    add_action('admin_notices', function () {
        $wpc_pb = get_transient('wpc_delay_v3_manifest_notice');
        if (empty($wpc_pb)) {
            return;
        }
        echo '<div class="notice notice-info is-dismissible"><p><strong>WP Compress — JavaScript delay self-healed:</strong> visitors hit errors from <code>'
            . esc_html($wpc_pb) . '</code> after the render analysis moved it earlier in the load, so this site was automatically reverted to the standard (safe) delay behavior and the page cache was refreshed. It re-evaluates automatically after the next page analysis; nothing needs your attention.</p></div>';
        delete_transient('wpc_delay_v3_manifest_notice');
    });
    add_action('admin_notices', function () {
        if (!get_option('wpc_delay_aggr_off') || !current_user_can('manage_options')) {
            return;
        }
        echo '<div class="notice notice-info"><p><strong>WP Compress — instant-boot mode paused:</strong> visitor reports showed delayed scripts failing to finish booting on a few pages, so this site was automatically switched back to the standard (timed) delay behavior. It re-arms on the next optimization refresh; nothing needs your attention.</p></div>';
    });
    
    
    
    
}


if (!function_exists('wpc_first_run_home_crit_exists')) {
    function wpc_first_run_home_crit_exists()
    {
        if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) {
            return true;
        }
        $homePage = function_exists('get_option') ? get_option('page_on_front') : 0;
        $url = (!empty($homePage) && function_exists('get_permalink')) ? get_permalink($homePage) : home_url('/');
        $key = (new wps_ic_url_key())->setup($url);
        if ($key === '') {
            return true;
        }
        $f = rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/critical_desktop.css';
        return @file_exists($f) && @filesize($f) > 0;
    }
}
if (!function_exists('wpc_first_run_dispatch_now')) {
    function wpc_first_run_dispatch_now()
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        if (!class_exists('wps_criticalCss')) {
            @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
        }
        if (class_exists('wps_criticalCss')) {
            try {
                $c = new wps_criticalCss();
                $c->generateCriticalCSS('home', true);
            } catch (\Throwable $e) {}
        }
    }
}



add_action('wpc_account_status_refresh', function () {
    if (class_exists('wps_ic') && method_exists('wps_ic', 'check_account_status')) {
        wps_ic::check_account_status(true);
    }
});





if (!function_exists('wpc_apiv3_recover_surviving_key')) {
    function wpc_apiv3_recover_surviving_key() {
        $key = '';
        foreach (['wps_ic_options', 'wps_ic_settings'] as $src) {
            $o = get_option($src);
            if (is_array($o) && !empty($o['api_key'])) { $key = (string) $o['api_key']; break; }
        }
        return $key;
    }
    function wpc_apiv3_recover_needed() {
        $canon = get_option('wps_ic');
        return !(is_array($canon) && !empty($canon['api_key']) && !empty($canon['response_key']));
    }
}
add_action('admin_init', function () {
    if (get_option('wpc_apiv3_reconnect_done')) { return; }
    if (!wpc_apiv3_recover_needed()) { update_option('wpc_apiv3_reconnect_done', 1, false); return; }
    if (wpc_apiv3_recover_surviving_key() === '') { return; }   
    if (get_transient('wpc_apiv3_reconnect_backoff')) { return; }
    if (function_exists('wp_schedule_single_event') && function_exists('wp_next_scheduled')
        && !wp_next_scheduled('wpc_apiv3_reconnect')) {
        set_transient('wpc_apiv3_reconnect_backoff', 1, 15 * MINUTE_IN_SECONDS);
        wp_schedule_single_event(time() + 5, 'wpc_apiv3_reconnect');
        wpc_spawn_cron();
    }
}, 5);
add_action('wpc_apiv3_reconnect', function () {
    if (get_option('wpc_apiv3_reconnect_done')) { return; }
    if (!wpc_apiv3_recover_needed()) { update_option('wpc_apiv3_reconnect_done', 1, false); return; }
    $key = wpc_apiv3_recover_surviving_key();
    if ($key === '') { update_option('wpc_apiv3_reconnect_done', 1, false); return; }
    if (class_exists('wps_ic_connect')) {
        $res = (new wps_ic_connect())->connectWithKey($key);
        if (is_array($res) && !empty($res['success'])) {
            update_option('wpc_apiv3_reconnect_done', 1, false);   
        }
        
    }
});
add_action('admin_init', function () {
    $opts = function_exists('get_option') ? get_option(WPS_IC_OPTIONS) : [];
    if (empty($opts['api_key'])) {
        return;
    }
    if (wpc_first_run_home_crit_exists()) {
        if (get_option('wpc_first_run_attempts') !== false) {
            delete_option('wpc_first_run_dispatched_at');
            delete_option('wpc_first_run_attempts');
            delete_option('wpc_first_run_failed');
        }
        return;
    }
    $dispatchedAt = (int) get_option('wpc_first_run_dispatched_at');
    $attempts     = (int) get_option('wpc_first_run_attempts');
    $timeout      = (int) apply_filters('wpc_first_run_timeout_seconds', 180);
    $maxAttempts  = (int) apply_filters('wpc_first_run_max_attempts', 5);
    
    $interval = ($attempts < $maxAttempts) ? $timeout : 3600;
    if ($dispatchedAt > 0 && (time() - $dispatchedAt) < $interval) {
        return;
    }
    if ($attempts >= $maxAttempts && !get_option('wpc_first_run_failed')) {
        update_option('wpc_first_run_failed', 1, false); 
    }
    update_option('wpc_first_run_dispatched_at', time(), false);
    update_option('wpc_first_run_attempts', $attempts + 1, false);
    register_shutdown_function('wpc_first_run_dispatch_now');
}, 20);


if (!function_exists('wpc_first_run_psi_now')) {
    function wpc_first_run_psi_now()
    {
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        $opts = get_option(WPS_IC_OPTIONS);
        if (empty($opts['api_key'])) {
            return;
        }
        $uuid = function_exists('get_transient') ? get_transient('wpc_psi_uuid') : '';
        if (!empty($uuid)) {
            
            if (!class_exists('wps_criticalCss')) {
                @include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
            }
            if (class_exists('wps_criticalCss') && class_exists('wps_ic_url_key')) {
                try {
                    $homePage = get_option('page_on_front');
                    $url = (!empty($homePage) && function_exists('get_permalink')) ? get_permalink($homePage) : home_url('/');
                    $key = (new wps_ic_url_key())->setup($url);
                    (new wps_criticalCss())->saveBenchmark($key, $uuid);
                } catch (\Throwable $e) {}
            }
            return;
        }
        
        $uuid = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : bin2hex(random_bytes(8));
        set_transient('wpc_psi_uuid', $uuid, 30 * 60);
        try {
            $requests = new wps_ic_requests();
            $args = [
                'url'            => home_url(),
                'uuid'           => $uuid,
                'hash'           => $uuid,
                'apikey'         => $opts['api_key'],
                'version'        => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
                'plugin_version' => defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '',
            ];
            $requests->POST(WPS_IC_PAGESPEED_API_URL_HOME, $args, ['timeout' => 2, 'blocking' => false, 'headers' => ['Content-Type' => 'application/json']]);
        } catch (\Throwable $e) {}
    }
}
add_action('admin_init', function () {
    $opts = function_exists('get_option') ? get_option(WPS_IC_OPTIONS) : [];
    if (empty($opts['api_key'])) {
        return;
    }
    $gps = get_option(WPS_IC_LITE_GPS);
    if (!empty($gps['result'])) {
        if (get_option('wpc_first_run_psi_attempts') !== false) {
            delete_option('wpc_first_run_psi_at');
            delete_option('wpc_first_run_psi_attempts');
        }
        return; 
    }
    $at          = (int) get_option('wpc_first_run_psi_at');
    $attempts    = (int) get_option('wpc_first_run_psi_attempts');
    $timeout     = (int) apply_filters('wpc_first_run_timeout_seconds', 180);
    $maxAttempts = (int) apply_filters('wpc_first_run_max_attempts', 5);
    $interval    = ($attempts < $maxAttempts) ? $timeout : 3600;
    if ($at > 0 && (time() - $at) < $interval) {
        return;
    }
    if ($attempts >= $maxAttempts && !get_option('wpc_first_run_failed')) {
        update_option('wpc_first_run_failed', 1, false);
    }
    update_option('wpc_first_run_psi_at', time(), false);
    update_option('wpc_first_run_psi_attempts', $attempts + 1, false);
    register_shutdown_function('wpc_first_run_psi_now');
}, 21);


include_once WPS_IC_DIR . 'addons/cache/warm.php';
include_once WPS_IC_DIR . 'addons/rail/rail.php';
include_once WPS_IC_DIR . 'addons/vitals/vitals.php';


include_once WPS_IC_DIR . 'addons/cache/beacon.php';


include_once WPS_IC_DIR . 'addons/cache/link-preset.php';

include_once WPS_IC_DIR . 'addons/debug/db-health.php';


include_once WPS_IC_DIR . 'addons/cache/invalidation.php';
