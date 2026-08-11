<?php





if (!function_exists('wpc_force_natural')) {

    function wpc_force_natural()
    {


        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) {
            return false;
        }
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $on = defined('WPC_FORCE_NATURAL') && WPC_FORCE_NATURAL;
        
        
        if (!$on && function_exists('get_option') && defined('WPS_IC_SETTINGS')) {
            $s = get_option(WPS_IC_SETTINGS);
            if (is_array($s) && !empty($s['force-natural']) && (string) $s['force-natural'] === '1') {
                $on = true;
            }
        }
        $cached = (bool) apply_filters('wpc_force_natural', $on);
        return $cached;
    }
}

if (!function_exists('wpc_cf_cname_verified_ok')) {

    function wpc_cf_cname_verified_ok()
    {
        $v = function_exists('get_option') ? get_option('wpc_cf_cname_verified', 'legacy') : 'legacy';
        return !($v === '0' || $v === 0);
    }
}

if (!function_exists('wpc_face_gate710')) {

    
    
    
    
    
    function wpc_stack_splice732($html)
    {
        if (!is_string($html) || $html === '' || !function_exists('wpc_css_insert_fallbacks')
            || stripos($html, 'font-family') === false
            || !apply_filters('wpc_stack_splice', true)) {
            return $html;
        }
        $out = preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function ($m) {
            if (stripos($m[2], 'font-family') === false) {
                return $m[0];
            }
            $s = wpc_css_insert_fallbacks($m[2]);
            return (is_string($s) && $s !== '') ? $m[1] . $s . $m[3] : $m[0];
        }, $html);
        return is_string($out) ? $out : $html;
    }

    
    
    
    
    
    
    
    
    
    
    function wpc_face_gate710($html, $delayOn = false, &$moved = 0)
    {
        $moved = 0;
        if (!$delayOn || !is_string($html) || $html === ''
            || !apply_filters('wpc_face_gate', true)) {
            return $html;
        }
        $late = '';
        
        
        
        $wpc_pin731 = [];
        if (preg_match_all('/<style\b([^>]*)>(.*?)<\/style>/is', $html, $wpc_eb731, PREG_SET_ORDER)) {
            foreach ($wpc_eb731 as $wpc_sb731) {
                if (stripos($wpc_sb731[1], 'wpc-late-faces') !== false
                    || (stripos($wpc_sb731[1], 'media=') !== false && !preg_match('/media=["\']?\s*(?:all|screen)\b/i', $wpc_sb731[1]))) {
                    continue;
                }
                if (stripos($wpc_sb731[2], '@font-face') === false
                    || !preg_match_all('/@font-face\s*\{[^{}]*\}/is', $wpc_sb731[2], $wpc_fb731)) {
                    continue;
                }
                foreach ($wpc_fb731[0] as $wpc_fr731) {
                    if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $wpc_fr731, $wpc_fn731)) {
                        continue;
                    }
                    $wpc_nm731 = strtolower(trim($wpc_fn731[1], " \t\"'"));
                    if ($wpc_nm731 === '') {
                        continue;
                    }
                    if (substr($wpc_nm731, -9) === ' fallback') {
                        $wpc_pin731[rtrim(substr($wpc_nm731, 0, -9))] = 1;
                    } elseif (preg_match('/src\s*:[^;}]*url\(\s*["\']?data:/i', $wpc_fr731)) {
                        $wpc_pin731[$wpc_nm731] = 1;
                    }
                }
            }
        }
        $wpc_scan712 = preg_replace_callback('/<style\b([^>]*)>(.*?)<\/style>/is', function ($sm) use ($wpc_pin731, &$late) {
            $attrs = $sm[1];
            $block = $sm[2];
            if (stripos($attrs, 'wpc-late-faces') !== false
                || (stripos($attrs, 'media=') !== false && !preg_match('/media=["\']?\s*(?:all|screen)\b/i', $attrs))) {
                return $sm[0];
            }
            if (stripos($block, '@font-face') === false
                || !preg_match('/url\(\s*["\']?(?:https?:)?\/\//i', $block)) {
                return $sm[0];
            }
            $kept = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($fm) use ($wpc_pin731, &$late) {
                $rule = $fm[0];
                if (!preg_match('/url\(\s*["\']?(?:https?:)?\/\//i', $rule)) {
                    return $rule;
                }
                if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $rule, $fam)) {
                    return $rule;
                }
                if (empty($wpc_pin731[strtolower(trim($fam[1], " \t\"'"))])) {
                    return $rule;
                }
                $late .= $rule;
                return '';
            }, $block);
            if ($kept === null || $kept === $block) {
                return $sm[0];
            }
            return '<style' . $attrs . '>' . $kept . '</style>';
        }, $html);
        if (is_string($wpc_scan712)) {
            $html = $wpc_scan712;
        } else {
            $late = '';
        }
        if ($late === '') {
            return $html;
        }
        $moved = substr_count($late, '@font-face');
        if (preg_match('/<style\b[^>]*\bid=(["\'])wpc-late-faces\1[^>]*>/i', $html, $lm, PREG_OFFSET_CAPTURE)) {
            $html = substr_replace($html, $late, $lm[0][1] + strlen($lm[0][0]), 0);
        } else {
            $lane = '<style id="wpc-late-faces" media="not all">' . $late . '</style>';
            $bp = strripos($html, '</body>');
            $html = ($bp !== false) ? substr_replace($html, $lane, $bp, 0) : $html . $lane;
        }
        return $html;
    }
}

if (!function_exists('wpc_yield_checkpoints707')) {

    
    
    
    
    
    
    
    
    
    
    function wpc_yield_checkpoints707($html, $delayOn = false)
    {
        if (!$delayOn || !is_string($html) || strlen($html) < 150000
            || !apply_filters('wpc_parse_checkpoints', false)
            || strpos($html, 'wpc-yield-a.js') !== false) {
            return $html;
        }
        $h1 = stripos($html, '<h1');
        if ($h1 === false) {
            return $html;
        }
        $heroOpen  = strripos(substr($html, 0, $h1), '<section');
        $heroClose = stripos($html, '</section>', $h1);
        if ($heroOpen === false || $heroClose === false) {
            return $html;
        }
        $bodyPos = stripos($html, '<body');
        if ($bodyPos === false || $heroOpen <= $bodyPos) {
            return $html;
        }
        $heroClose += 10;
        $base = (defined('WPS_IC_URI') ? WPS_IC_URI : '/wp-content/plugins/wp-compress-image-optimizer/') . 'assets/js/';
        $ver  = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '1';
        $ta = '<script src="' . $base . 'wpc-yield-a.js?v=' . $ver . '" data-nodefer="1"></script>';
        $tb = '<script src="' . $base . 'wpc-yield-b.js?v=' . $ver . '" data-nodefer="1"></script>';
        return substr($html, 0, $heroOpen) . $ta
            . substr($html, $heroOpen, $heroClose - $heroOpen) . $tb
            . substr($html, $heroClose);
    }
}

include WPS_IC_DIR . 'addons/cdn/rewriteLogic.php';
include WPS_IC_DIR . 'addons/minify/html.php';
include_once WPS_IC_DIR . 'addons/cache/cacheHtml.php';

class wps_cdn_rewrite
{


    public static $settings;
    public static $options;
    public static $lazy_excluded_list;
    public static $excluded_list;
    public static $default_excluded_list;
    public static $cdnEnabled;
    public static $fd_raw484 = '';
    public static $preloaderAPI;
    public static $excludes_class;
    public static $assets_to_preload;
    public static $assets_to_defer;
    public static $emoji_remove;
    public static $isAjax;
    public static $brizyCache;
    public static $brizyActive;
    public static $regExURL;

    
    public static $regExDir;
    public static $findImages;
    public static $apiUrl;

    
    public static $apiAssetUrl;
    public static $updir;

    
    public static $home_url;
    public static $site_url;
    public static $site_url_scheme;
    public static $svg_placeholder;

    
    public static $excludes;


    
    public static $fonts;
    public static $css;
    public static $css_img_url;
    public static $css_minify;
    public static $js;
    public static $js_minify;
    public static $replaceAllLinks;

    
    public static $external_url_excluded;
    public static $externalUrlEnabled;
    public static $zone_test;
    public static $zone_name;
    public static $is_retina;
    public static $exif;
    public static $webp;
    public static $retina_enabled;
    public static $adaptive_enabled;
    public static $webp_enabled;
    public static $lazy_enabled;
    public static $native_lazy_enabled;
    public static $sizes;
    public static $randomHash;
    public static $is_multisite;
    public static $keys;
    public static $delay_js_override;

    
    public static $defer_js_override;
    public static $lazy_override;
    public static $rewriteLogic;
    public static $minifyHtml;
    public static $cacheHtml;
    public static $criticalCss;
    public static $combineCss;
    public static $page_excludes;
    public static $post_id;
    public static $page_excludes_files;
    public static $isActive;
    public static $wpcPreloadLinks;
    private static $isAmp;
    private static $themeIntegrations;
    private static $lazyLoadedImages;
    private static $deviceHiddenSet717 = [];
    private static $lazyLoadedImagesLimit;
    private static $lazyLoadSkipFirstImages;
    private static $removeSrcset;
    public $cdn;
    public $compatibility;
    public $criticalCombine;
    public $inline_js;
    public $inline_css;
    public $delay_js_exclude;

    public function __construct()
    {

        
        require_once WPS_IC_DIR . 'integrations/themes/theme.integrations.php';
        self::$themeIntegrations = new ThemeIntegrations();

        
        self::$lazyLoadedImages = 0;
        self::$lazyLoadedImagesLimit = 1;

        self::$settings = get_option(WPS_IC_SETTINGS);
        self::$excludes = get_option('wpc-excludes');


        
        if (empty(self::$settings['mcCriticalCSS']) || self::$settings['mcCriticalCSS'] == 'mc') {
            include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
        } else {
            
            
            
            
            
            
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-v1-branch', '', '', ['mcCriticalCSS' => (string) self::$settings['mcCriticalCSS']]);
            }
            include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss.php';
        }

        if (empty(self::$settings)) {
            $options = new wps_ic_options();
            $settings = $options->get_preset('lite');
            self::$settings = $settings;
        }

        if (empty(self::$excludes)) {
            self::$excludes = [];
        }

        if (!isset(self::$excludes['cdn'])) {
            self::$excludes['cdn'] = [];
        }

        self::$excludes['cdn'][] = '.php';
        self::$excludes['cdn'][] = '/wp-fastest-cache/';
        self::$excludes['cdn'][] = '/wp-content/plugins/ameliabooking/v3/public/assets/';
        self::$excludes['cdn'][] = '/vue3';
        self::$excludes['cdn'][] = 'sharethis.js';
        if (defined('ELEMENTOR_VERSION')) {


            self::$excludes['cdn'][] = 'webpack.runtime.min.js';
            self::$excludes['cdn'][] = 'webpack-pro.runtime.min.js';
        }

        $wpc_cdnex804 = apply_filters('wpc_cdn_excludes', self::$excludes['cdn']);
        if (is_array($wpc_cdnex804)) {
            self::$excludes['cdn'] = array_values(array_filter($wpc_cdnex804, 'is_string'));
        }

        self::$removeSrcset = self::$settings['remove-srcset'];

        if (empty(self::$settings['lazySkipCount'])) {
            self::$lazyLoadSkipFirstImages = 4;
        } else {
            self::$lazyLoadSkipFirstImages = self::$settings['lazySkipCount'];
        }

        self::$excludes_class = new wps_ic_excludes();
        global $post;

        if ($this->is_home_url()) {
            $per_page_settings = isset(self::$excludes['per_page_settings']['home']) ? self::$excludes['per_page_settings']['home'] : [];
        } elseif (!empty($post->ID)) {
            $per_page_settings = isset(self::$excludes['per_page_settings'][$post->ID]) ? self::$excludes['per_page_settings'][$post->ID] : [];
        }

        if (!empty($per_page_settings) && isset($per_page_settings['skip_lazy']) && $per_page_settings['skip_lazy'] !== '') {
            self::$lazyLoadSkipFirstImages = $per_page_settings['skip_lazy'];
        }

        self::$wpcPreloadLinks = [];
        self::$isActive = true;
        $options = get_option(WPS_IC_OPTIONS);
        if (empty($options['api_key'])) {
            self::$isActive = false;
        }
    }

    public function is_home_url()
    {
        $home_url = rtrim(home_url(), '/');
        $current_url = wpc_request_scheme() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $current_url = rtrim($current_url, '/');
        $current_url = explode('?', $current_url);
        $current_url = $current_url[0];
        $home_url = rtrim($home_url, '/');
        $current_url = rtrim($current_url, '/');

        return $home_url === $current_url;
    }

    public static function init()
    {
        global $ic_running;

        if (strpos($_SERVER['REQUEST_URI'], '.xml') !== false) {
            return true;
        }

        if (is_admin() || strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {
            return true;
        }

        if ($ic_running) {
            return true;
        }

        $ic_running = true;

        if (!empty($_GET['ignore_cdn']) || !empty($_GET['ignore_ic'])) {
            return true;
        }

        $options = get_option(WPS_IC_OPTIONS);
        $apikey = $options['api_key'];
        if (empty($apikey)) {
            return true;
        }

        if (self::$settings['css'] == 0 && self::$settings['js'] == 0 && self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) {
            return true;
        }

        self::$isAjax = (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX);

        
        if (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") {
            return true;
        }

        
        if (!self::$isAjax) {
            if (wp_is_json_request() || is_admin() || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['trp-edit-translation']) || !empty($_GET['elementor-preview']) || !empty($_GET['preview']) || !empty($_GET['PageSpeed']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || isset($_GET['is-editor-iframe']) || !empty($_GET['et_fb']) || !empty($_GET['tatsu']) || !empty($_GET['tve']) || !empty($_GET['fb-edit']) || !empty($_GET['ct_builder']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor')) {
                return true;
            }
        }

        add_filter('get_site_icon_url', ['wps_cdn_rewrite', 'favicon_replace'], 10, 1);
        return true;
    }

    public static function dontRunif()
    {


        if (!empty($_GET['disableWPC']) || isset($_SERVER['HTTP_DISABLEWPC'])) {
            return false;
        }

        
        $url_excludes = get_option('wpc-url-excludes');
        if (!empty($url_excludes['exclude-url-from-all']) && function_exists('wpc_url_is_excluded')) {
            $url = $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            $url = explode('?', $url)[0];
            $matched = wpc_url_is_excluded($url, $url_excludes['exclude-url-from-all']);
            if ($matched !== false) {
                error_log('[WPC Bypass] url=' . $url . ' matched_pattern=' . $matched);
                return false;
            }
        }


        if (!empty($_GET['pagelayer-live'])) {
            return false;
        }

        
        if (self::hiddenAdminArea()) {
            return false;
        }

        
        if (class_exists('WP_User_Frontend')) {
            $content = get_post_field('post_content', get_the_ID());

            
            if (preg_match('/\[wpuf/', $content)) {
                return false;
            }
        }

        if (self::MediaActions()) {
            return false;
        }

        if (strpos($_SERVER['REQUEST_URI'], 'jm-ajax') !== false) {
            return false;
        }

        if (isset($_GET['woo_ajax']) || isset($_POST['woo_ajax']) || (isset($_SERVER['REQUEST_URI']) && (strpos($_SERVER['REQUEST_URI'], 'woo_ajax') !== false))) {
            return false;
        }

        if (defined('DOING_AUTOSAVE')) {
            return false;
        }

        if (isset($_SERVER['REQUEST_URI']) && (strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false || strpos($_SERVER['REQUEST_URI'], 'sitemap') !== false)) {
            return false;
        }

        if (!empty($_POST['_cs_nonce'])) {
            return false;
        }

        if (is_admin() || strpos($_SERVER['REQUEST_URI'], 'wp-login.php') !== false) {
            return false;
        }

        if (!empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], 'wp-json') || strpos($_SERVER['REQUEST_URI'], 'rest_route')) {
                return false;
            }
        }

        if (isset($_GET['brizy-edit-iframe']) || isset($_GET['brizy-edit']) || isset($_GET['preview'])) {
            return false;
        }

        if (!empty($_GET['page']) && $_GET['page'] == 'bwc') {
            return false;
        }


        if (!empty($_GET['trp-edit-translation']) || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['bwc']) || !empty($_GET['fb-edit']) || !empty($_GET['bricks']) || !empty($_GET['elementor-preview']) || !empty($_GET['PageSpeed']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['et_fb']) || !empty($_GET['tatsu']) || !empty($_GET['tatsu-header']) || !empty($_GET['tatsu-footer']) || !empty($_GET['tve']) || !empty($_GET['is-editor-iframe']) || !empty
            ($_GET['ct_builder']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor')) {
            return false;
        }

        if ((!empty($_GET['action']) && $_GET['action'] == 'edit#op-builder') || !empty($_GET['op3editor'])) {

            return false;
        }

        if (!empty($_POST['pp_action'])) {

            return false;
        }

        if (!empty($_POST['add-to-cart'])) {

            return false;
        }

        if (!empty($_GET['wc-ajax']) && $_GET['wc-ajax'] == 'get_refreshed_fragments') {
            return false;
        }

        if (!empty($_GET['action']) && $_GET['action'] == 'get_wdtable') {
            return false;
        }

        if (!empty($_GET['lc_action_launch_editing'])) {
            return false;
        }

        
        if (isset($_GET['givewp-route'])) {
            return false;
        }

        
        if (!empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], '/gh/calendar')) {
                return false;
            }
        }

        return true;
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

    public static function MediaActions()
    {
        if (!empty($_GET['preloadCache'])) {
            return true;
        }

        if (!empty($_GET['getAllImages'])) {
            return true;
        }

        if (!empty($_POST['getImageByID']) || !empty($_GET['getImageByID'])) {
            return true;
        }

        if (!empty($_POST['deliverSingleImage']) || !empty($_GET['deliverSingleImage'])) {
            return true;
        }

        if (!empty($_POST['deliverImages']) || !empty($_GET['deliverImages'])) {
            return true;
        }

        if (!empty($_POST['restoreImages']) || !empty($_GET['restoreImages'])) {
            return true;
        }
    }

    public static function favicon_replace($url)
    {
        if (empty($url)) {
            return $url;
        }

        if (strpos($url, self::$zone_name) !== false) {
            return $url;
        }

        $url = 'https://' . self::$zone_name . '/m:0/a:' . self::reformat_url($url);

        return $url;
    }

    public static function reformat_url($url, $remove_site_url = false)
    {
        $url = trim($url);

        if (strpos($url, 'login') !== false) {
            return $url;
        }

        
        if (strpos($url, 'http') === false) {
            
            if (strpos($url, '//') === 0) {
                
                $url = 'https:' . $url;
            } else {

                if (strpos($url, '/') !== 0) {
                    $url = str_replace('../wp-content', 'wp-content', $url);

                    $url_replace = preg_replace('/\/wp-content/', 'wp-content', $url, 1);
                    $url = self::$site_url;
                    $url = rtrim($url, '/');
                    $url .= '/' . $url_replace;
                } else {
                    $urlEnd = $url;
                    $urlEnd = ltrim($urlEnd, '/');
                    $urlEnd = rtrim($urlEnd, '/');
                    $url = self::$site_url;
                    $url = ltrim($url, '/');
                    $url = rtrim($url, '/');
                    $url .= '/' . $urlEnd;
                }
            }
        }

        $formatted_url = $url;


        if (strpos($formatted_url, '?brizy_media') === false && strpos($formatted_url, '.php') === false) {
            $wpc_uv824 = '';
            if (strpos($formatted_url, '/used-css/') !== false && preg_match('/[?&](uv=\d+)/', $formatted_url, $wpc_uvm824)) {
                $wpc_uv824 = $wpc_uvm824[1];
            }
            $formatted_url = explode('?', $formatted_url);
            $formatted_url = $formatted_url[0];
            if ($wpc_uv824 !== '') {
                $formatted_url .= '?' . $wpc_uv824;
            }
        }


        if ($remove_site_url) {
            $formatted_url = str_replace(self::$site_url, '', $formatted_url);
            $formatted_url = str_replace(str_replace(['https://', 'http://'], '', self::$site_url), '', $formatted_url);
            $formatted_url = str_replace(addcslashes(self::$site_url, '/'), '', $formatted_url);
            $formatted_url = ltrim($formatted_url, '\/');
            $formatted_url = ltrim($formatted_url, '/');
        }


        if (self::$randomHash == 0 && strpos($formatted_url, '.css') !== false) {
            $formatted_url .= (strpos($formatted_url, '?') === false ? '?' : '&') . 'icv=' . WPS_IC_HASH;
        }

        if (self::$randomHash == 0 && preg_match('/\.js(?:[?#]|$)/i', $formatted_url)) {
            $formatted_url .= (strpos($formatted_url, '?') === false ? '?' : '&') . 'js_icv=' . WPS_IC_JS_HASH;
        }

        if (self::$randomHash != 0) {
            return $formatted_url . '?icv_random=' . self::$randomHash;
        }
        

        return $formatted_url;
    }

    public static function is_image($image)
    {
        if (strpos($image, '.webp') === false && strpos($image, '.jpg') === false && strpos($image, '.jpeg') === false && strpos($image, '.png') === false && strpos($image, '.ico') === false && strpos($image, '.svg') === false && strpos($image, '.gif') === false) {
            return false;
        } else {
            
            if (strpos($image, '.jpg') !== false || strpos($image, '.jpeg') !== false) {

                if (self::$settings['serve']['jpg'] == '0') {
                    return false;
                }
            }

            
            
            if (strpos($image, '.gif') !== false) {
                if (self::$settings['serve']['gif'] == '0'
                    || !class_exists('wps_rewriteLogic') || !wps_rewriteLogic::cf_is_delivery()) {
                    return false;
                }
            }

            
            if (strpos($image, '.png') !== false) {

                if (self::$settings['serve']['png'] == '0') {
                    return false;
                }
            }

            
            if (strpos($image, '.svg') !== false) {

                if (self::$settings['serve']['svg'] == '0') {
                    return false;
                }
            }


            if ((strpos($image, '.webp') !== false || strpos($image, '.ico') !== false)
                && (!class_exists('WPC_Negotiated_Delivery') || !WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings))) {
                return false;
            }

            return true;
        }
    }

    public function buffer_local_go()
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return true;
        }

        if (self::$isAjax) {
            $wps_ic_cdn = new wps_cdn_rewrite();
        }

        ob_start([$this, 'buffer_local_callback_wrapped']);
    }

    public function isActive()
    {
        return self::$isActive;
    }

    public function add_scripts_inline($tag, $handle, $src)
    {
        if (strpos(strtolower($src), 'webpack') !== false) {
            return $tag;
        }


        if (strpos(strtolower($src), 'tweenmax') !== false) {
            $urlGet = false;
            
            $check = wp_http_validate_url($src);
            if ($check || strpos($src, '//') === 0) {
                if (strpos($src, 'http') === false) {
                    $src = 'https:' . $src;
                }
                $urlGet = true;
                $url = $src;
            } else {
                $url = get_home_url() . $src;
            }

            if ($urlGet) {
                $tag = '<script type="text/javascript" class="wps-inline" id="tweenmax-js">' . $this->get_script_content_url($url) . '</script>';
            } else {
                $tag = '<script type="text/javascript" class="wps-inline" id="tweenmax-js">' . $this->get_script_content($url) . '</script>';
            }

            return $tag;
        }

        if (empty($this->inline_js) || !is_array($this->inline_js)) {
            $this->inline_js = [];
        }

        $found = false;
        foreach ($this->inline_js as $k => $inlineJs) {
            if (strpos(strtolower($src), $inlineJs) !== false) {
                $found = true;
                break;
            }
        }

        if ($found) {
            global $wp_scripts;

            $check = wp_http_validate_url($src);
            if ($check || strpos($src, '//') === 0) {
                $url = $src;
            } else {
                $url = get_home_url() . $src;
            }

            $tag = '';
            if (!empty($wp_scripts->registered[$handle]->extra['before'][1])) {
                $tag .= '<script type="text/javascript" id="' . $handle . '-js-before">' . $wp_scripts->registered[$handle]->extra['before'][1] . '</script>';
            }

            
            if (strpos($handle, 'awesome') !== false) {
                $tag .= '<script type="text/javascript" defer class="wps-inline" id="' . $handle . '-js">' . $this->get_script_content($url) . '</script>';
            } else {
                if (strpos($handle, 'aio') !== false || strpos($handle, 'theme') !== false) {
                    $tag .= '<script type="text/javascript" class="wps-inline" id="' . $handle . '-js" defer>' . $this->get_script_content($url) . '</script>';
                } else {
                    
                    
                    
                    
                    
                    
                    $wpc_delay_executor = !(function_exists('is_user_logged_in') && is_user_logged_in())
                        && ((isset(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1')
                            || (isset(self::$settings['delay-js']) && self::$settings['delay-js'] == '1'));
                    $tag .= '<script type="' . ($wpc_delay_executor ? 'wpc-delay-script' : 'text/javascript') . '" class="wps-inline" id="' . $handle . '-js">' . $this->get_script_content($url) . '</script>';
                }
            }

            if (!empty($wp_scripts->registered[$handle]->extra['after'][1])) {
                $tag .= '<script type="text/javascript" id="' . $handle . '-js-after">' . $wp_scripts->registered[$handle]->extra['after'][1] . '</script>';
            }
        }

        return $tag;
    }

    public function get_script_content_url($url)
    {
        
        
        
        
        
        
        
        
        $key = 'wpc_surl_' . md5((string) $url);
        if (function_exists('get_transient') && get_transient($key)) {
            return ''; 
        }
        if (!isset($GLOBALS['wpc_surlms532'])) {
            $GLOBALS['wpc_surlms532'] = 0.0;
        }
        $budget = (float) apply_filters('wpc_script_fetch_budget_ms', 3000);
        if ($GLOBALS['wpc_surlms532'] >= $budget) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('script-fetch-budget-spent', '', (string) $url, []);
            }
            return '';
        }

        $t0 = microtime(true);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) apply_filters('wpc_script_fetch_timeout', 2));
        $data = curl_exec($ch);
        curl_close($ch);
        $spent = (microtime(true) - $t0) * 1000;
        $GLOBALS['wpc_surlms532'] += $spent;
        if (function_exists('wpc_prof_mark')) {
            wpc_prof_mark('scriptfetch', $t0);
        }

        if ($data === false || $data === '') {
            if (function_exists('set_transient')) {
                set_transient($key, 1, (int) apply_filters('wpc_script_fetch_fail_ttl', 300));
            }
            return '';
        }
        return $data;
    }

    public function get_script_content($url)
    {


        $relativePath = wp_make_link_relative($url);
        $path = ltrim($relativePath, '/');


        $last_abspath = basename(ABSPATH);
        $first_path = explode('/', $path)[0];
        if ($last_abspath == $first_path) {
            $path = substr($path, strlen($first_path));
            $path = ltrim($path, '/');
        }

        $path = explode('?', $path);
        $path = $path[0];

        
        if (!file_exists(ABSPATH . $path)) {
            
        }

        $content = file_get_contents(ABSPATH . $path);

        
        $jsCode = preg_replace('#/\*.*?\*/#s', '', $content);

        return $jsCode;
    }

    public function dnsPrefetch()
    {
        
        if (!self::dontRunif()) {
            return;
        }
        
        
        
        
        
        
        echo '<style id="wpc-img-ratio">img:where([wpc-size][width][height]),img:where(.wpc-nd[width][height]),img:where([data-wpc-md][width][height]){height:auto}</style>';
        if (strlen(trim(self::$zone_name)) > 0) {
            if (!empty($_GET['dbg']) && $_GET['dbg'] == 'direct') {
                if (!empty($_GET['custom_server'])
                    && function_exists('wpc_cdn_debug_allowed649') && wpc_cdn_debug_allowed649()) {
                    $custom_server = sanitize_text_field($_GET['custom_server']);

                    if (preg_match('/^[a-z0-9\-]+\.zapwp\.net$/i', $custom_server)) {
                        self::$zone_name = $custom_server . '/key:' . self::$options['api_key'];
                        echo '<link rel="dns-prefetch" href="//' . $custom_server . '" />';
                    }
                }
            } else {


                if (!((!isset(self::$settings['delay-js-v3']) || self::$settings['delay-js-v3'] != '0')
                    && (isset(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1'
                        || class_exists('wps_ic_js_delay_v3') && wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings)))) {
                    echo '<link rel="dns-prefetch" href="https://optimizerwpc.b-cdn.net" />';
                    echo '<link rel="preconnect" href="https://optimizerwpc.b-cdn.net">';
                    echo '<link rel="preconnect" href="https://optimize-v2.b-cdn.net/">';
                }


                echo '<link rel="dns-prefetch" href="//' . self::$zone_name . '" />';
                echo '<link rel="preconnect" href="https://' . self::$zone_name . '">';
            }
        }
    }

    public function deferJSAssets($tag, $handle, $src)
    {
        return $tag;
    }

    public function rewrite_script_tag($tag, $handle, $src)
    {
        $src = trim($src);

        if (self::isExcludedFrom('cdn', $src)) {
            return $tag;
        }

        if (self::isExcludedFrom('cdn', $tag)) {
            return $tag;
        }

        if ($this->defaultExcluded($src)) {
            return $tag;
        }

        if (self::is_excluded_link($src)) {
            return $tag;
        }


        if (strpos($src, '.js')) {
            $verPosition = strpos($src, '?ver=');
            if ($verPosition !== false) {

                $src = substr($src, 0, $verPosition);
            }
        }

        





        if (!self::image_url_matching_site_url($src)) {
            return $tag;
        }


        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on') && !wps_rewriteLogic::natural_assets_on()) {
            return $tag;
        }
        if (self::$cdnEnabled == '1' && self::$js == '1') {
            
            
            
            
            
            
            if (strpos($src, self::$zone_name) === false && !apply_filters('wpc_scripts_same_origin', true)) {
                $fileMinify = self::$js_minify;
                if (self::isExcluded('js_minify', $src)) {
                    $fileMinify = '0';
                }


                $abs = self::reformat_url($src, false);
                if (empty($fileMinify)) {
                    $pp = function_exists('wp_parse_url') ? wp_parse_url($abs) : parse_url($abs);
                    if (is_array($pp) && !empty($pp['path'])) {
                        $src = 'https://' . self::$zone_name . $pp['path']
                             . (isset($pp['query']) ? '?' . $pp['query'] : '')
                             . (isset($pp['fragment']) ? '#' . $pp['fragment'] : '');
                    } else {
                        $src = 'https://' . self::$zone_name . '/m:0/a:' . $abs;
                    }
                } else {
                    $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . $abs;
                }
            }

            if (!empty(self::$settings['js_defer'])) {
                if (self::$settings['js_defer'] == '1' && !self::$defer_js_override) {
                    foreach (self::$assets_to_defer as $i => $defer_key) {
                        if (strpos($tag, $defer_key) !== false) {
                            if (!self::isExcluded('defer_js', $src) && !strpos($src, 'slide')) {
                                $tag = '<script type="text/javascript" src="' . $src . '" defer></script>';
                            }
                        }
                    }
                } else {
                    
                    $tag = preg_replace('/^(\s*<script[^>]*)\ssrc=["\']([^"\']*)["\']([^>]*>)/i', '$1 src="' . $src . '"$3', $tag);
                }
            } else {

                if (strpos($src, 'gtag') !== false) {
                    $tag = '<script type="text/javascript" src="' . $src . '" defer></script>';
                }

                if (strpos($src, 'fontawesome') !== false) {
                    $tag = '<script type="text/javascript" src="' . $src . '" defer></script>';

                    return $tag;
                }

                
                $tag = preg_replace('/^(\s*<script[^>]*)\ssrc=["\']([^"\']*)["\']([^>]*>)/i', '$1 src="' . $src . '"$3', $tag);
            }

            return $tag;
        }

        return $tag;
    }

    public static function isExcludedFrom($setting, $link)
    {
        if (isset(self::$excludes[$setting])) {
            $excludeList = self::$excludes[$setting];
            if (!empty($excludeList)) {
                foreach ($excludeList as $key => $value) {
                    if (strpos($link, $value) !== false && $value != '') {
                        return true;
                    }
                }
            }
        }

        if (isset(self::$page_excludes_files[$setting])) {
            $excludeList = self::$page_excludes_files[$setting];
            if (!empty($excludeList)) {
                foreach ($excludeList as $key => $value) {
                    if (strpos($link, $value) !== false && $value != '') {
                        return true;
                    }
                }
            }
        }


        return false;
    }

    public function defaultExcluded($string)
    {
        if (!empty(self::$default_excluded_list)) {
            foreach (self::$default_excluded_list as $i => $excluded_string) {
                if (strpos($string, $excluded_string) !== false) {
                    return true;
                }
            }
        }

        return false;
    }


    public static function is_dynamic_query_asset($link)
    {
        if (empty($link) || !is_string($link)) {
            return false;
        }
        $hasCss = stripos($link, '.css') !== false;
        $hasJs  = stripos($link, '.js') !== false;
        if (!$hasCss && !$hasJs) {
            return false;
        }
        $path = (string) (function_exists('wp_parse_url')
            ? wp_parse_url($link, PHP_URL_PATH)
            : parse_url($link, PHP_URL_PATH));
        
        if (($hasCss && stripos($path, '.css') !== false) || ($hasJs && stripos($path, '.js') !== false)) {
            return false;
        }
        
        return true;
    }

    public static function is_excluded_link($link)
    {
        


        if (empty($link)) {
            return false;
        }


        if (self::is_dynamic_query_asset($link)) {
            return true;
        }

        if (strpos($link, '.css') !== false || strpos($link, '.js') !== false) {
            foreach (self::$default_excluded_list as $i => $excluded_string) {
                if (strpos($link, $excluded_string) !== false) {
                    return true;
                }
            }
        }

        if (!empty(self::$excluded_list)) {
            foreach (self::$excluded_list as $i => $value) {
                if (strpos($link, $value) !== false) {
                    
                    return true;
                }
            }
        }

        return false;
    }


    public static function image_url_matching_site_url($image)
    {
        
        
        if (strpos($image, '//') !== 0 && (strpos($image, '/') === 0 || strpos($image, 'wp-content') === 0)) {
            return true;
        }
        $site_url = self::$site_url;
        $stripped = str_replace(['https://', 'http://'], '', $image);
        $site_url = str_replace(['https://', 'http://'], '', $site_url);

        if (strpos($stripped, '.css') !== false || strpos($stripped, '.js') !== false) {
            foreach (self::$default_excluded_list as $i => $excluded_string) {
                if (strpos($stripped, $excluded_string) !== false) {
                    return false;
                }
            }
        }


        $site_host = preg_replace('/^www\./i', '', (string) strtok($site_url, '/'));
        if ($site_host !== '' && preg_match_all('#https?://([^/"\'\s>)]+)#i', $image, $host_matches) && !empty($host_matches[1])) {
            foreach ($host_matches[1] as $h) {
                $h = preg_replace('/^www\./i', '', (string) strtok($h, ':'));
                if (strcasecmp($h, $site_host) === 0) {
                    return true;
                }
            }
            return false;
        }

        if (strpos($stripped, $site_url) === false) {
            
            return false;
        } else {
            
            return true;
        }
    }

    public static function isExcluded($setting, $link)
    {
        if (isset(self::$excludes[$setting])) {
            $excludeList = self::$excludes[$setting];
            if (!empty($excludeList)) {
                foreach ($excludeList as $key => $value) {
                    if (strpos($link, $value) !== false && $value != '') {
                        return true;
                    }
                }
            }
        }


        return false;
    }

    public function crittr_style_tag($html, $handle, $href, $media)
    {

        if (strpos($href, self::$site_url) === false) {

        } else {
            $cdnHref = WPS_IC_URI . 'fixCss.php?zoneName=' . self::$zone_name . '&css=' . urlencode($href) . '&rand=' . time();
            $html = str_replace($href, $cdnHref, $html);
        }

        return $html;
    }

    public function inlineCSS($html, $handle, $href, $media)
    {
        if (strpos($html, 'src=')) {

            if (strpos($href, self::$site_url) !== false) {
                
                
                
                
                
                $wpc_lp543 = ABSPATH . ltrim((string) parse_url($href, PHP_URL_PATH), '/');
                if (@is_readable($wpc_lp543)) {
                    $content = (string) @file_get_contents($wpc_lp543);
                } else {
                    $wpc_r543 = function_exists('wp_remote_get')
                        ? wp_remote_get($href, ['timeout' => 3, 'redirection' => 1]) : null;
                    $content  = (!$wpc_r543 || is_wp_error($wpc_r543))
                        ? '' : (string) wp_remote_retrieve_body($wpc_r543);
                }
                if ($content === '') {
                    return $html; 
                }
                $content = self::$combineCss->minifyCSS($content);
                $return = '<style id="inline-css-' . mt_rand(999, 9999) . '">';
                $return .= $content;
                $return .= '</style>';

                return $return;
            }
        }

        return $html;
    }

    

    public function adjust_style_tag($html, $handle, $href, $media)
    {

        if (!empty(self::$settings['remove-render-blocking']) && self::$settings['remove-render-blocking'] == '1') {
            foreach (self::$assets_to_preload as $i => $preload_key) {
                if (self::$excludes_class->strInArray($html, self::$excludes_class->renderBlockingCSSExcludes())) {
                    return $html;
                }
                if (strpos($href, $preload_key) !== false) {
                    if (!strpos($html, 'preload')) {
                        if (strpos($html, 'rel=') !== false) {

                            $html = preg_replace('/rel\=["|\'](.*?)["|\']/', 'rel="preload" as="style" onload="this.rel=\'stylesheet\'" ', $html);
                        } else {

                            $html = str_replace('/>', 'rel="preload" as="style" onload="this.rel=\'stylesheet\'"/>', $html);
                        }
                    }

                    return $html;
                }

            }
        }

        if (strpos($href, 'wp-includes/css/dist/block-library') !== false) {
            if (!empty($this::$settings['disable-gutenberg']) && $this::$settings['disable-gutenberg'] == '1') {
                return '';
            }
        }

        return $html;
    }

    public function strInArray($haystack, $needles = [])
    {

        if (empty($needles)) {
            return false;
        }

        $haystack = strtolower($haystack);

        foreach ($needles as $needle) {
            $needle = strtolower(trim($needle));

            $res = strpos($haystack, $needle);
            if ($res !== false) {
                return true;
            }
        }

        return false;
    }


    public function adjust_src_url($src)
    {
        $out = $this->adjust_src_url_raw($src);
        if (is_string($out) && $out !== '' && class_exists('wps_rewriteLogic') && wps_rewriteLogic::natural_assets_on()) {
            $natural = wps_rewriteLogic::naturalize_asset_urls($out);
            if (is_string($natural) && $natural !== '') {
                $out = $natural;
            }
        }
        return $out;
    }

    public function adjust_src_url_raw($src)
    {

        $src = trim($src);

        if (strpos($src, '.css') !== false && empty(self::$css) || self::$css == '0') {
            return $src;
        } elseif (strpos($src, '.js') !== false && empty(self::$js) || self::$js == '0') {
            return $src;
        } else if (strpos($src, '.php') !== false) {
            return $src;
        }

        if (self::isExcludedFrom('cdn', $src)) {
            return $src;
        }

        if ($this->defaultExcluded($src)) {
            return $src;
        }

        if (self::is_excluded_link($src)) {
            return $src;
        }

        





        if (!self::image_url_matching_site_url($src)) {
            return $src;
        }


        if (strpos($src, '.css')) {
            if (strpos($src, '?ver=')) {
                $src = remove_query_arg('ver', $src);
            }
        }

        
        
        if ((strpos($src, '.css') !== false || strpos($src, '.js') !== false)
            && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on')
            && !wps_rewriteLogic::natural_assets_on()) {
            return $src;
        }

        if (strpos($src, self::$zone_name) === false) {
            if (strpos($src, '.css') !== false) {
                $fileMinify = self::$css_minify;
                if (self::isExcluded('css_minify', $src)) {
                    $fileMinify = '0';
                }


                if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1'
                    && apply_filters('wpc_font_subset_forces_css_minify', false, $src)) {
                    $fileMinify = '1';
                }

                if (!self::is_excluded_link($src)) {
                    if (self::$css_img_url == '1') {
                        $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($src);
                    } else {
                        if (strpos($src, 'wp-content') !== false || strpos($src, 'wp-includes') !== false) {
                            $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($src, false);
                        } else {
                            $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($src, false);
                        }
                    }
                }
            } elseif (strpos($src, '.js') !== false) {
                
                
                
                
                if (apply_filters('wpc_scripts_same_origin', true)) {
                    return $src;
                }
                $fileMinify = self::$js_minify;
                if (self::isExcluded('js_minify', $src)) {
                    $fileMinify = '0';
                }

                if (strpos($src, 'wp-content') !== false || strpos($src, 'wp-includes') !== false) {
                    $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($src, false);
                } else {
                    $src = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($src, false);
                }
            }
        }

        return $src;
    }


    public static function wpc_svg_naturalize($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name)) {
            return $html;
        }
        $zone = preg_quote((string) self::$zone_name, '#');
        return preg_replace_callback(
            '#https?://(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)/[^"\'()\s<>]*?/u:(https?://[^"\'()\s<>]+?/wp-content/uploads/[^"\'()\s<>]+?\.svg(?:\?[^"\'()\s<>]*)?)#i',
            static function ($m) {
                $pos = stripos($m[1], '/wp-content/uploads/');
                if ($pos === false) {
                    return $m[0];
                }


                if (preg_match_all('#https?://([^/"\'()\s<>]+)#i', substr($m[1], 0, $pos), $host_matches) && !empty($host_matches[1])) {
                    $asset_host = preg_replace('/^www\./i', '', (string) end($host_matches[1]));
                    $site_host  = preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
                    if ($site_host !== '' && strcasecmp($asset_host, $site_host) !== 0) {
                        return $m[0];
                    }
                }

                return 'https://' . self::$zone_name . substr($m[1], $pos);
            },
            $html
        );
    }

    




    public static function wpc_css_bg_imageset_active()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        if (!self::wpc_svg_zoneify_active()) return false;
        $on = function_exists('get_option') ? get_option('wpc_css_bg_imageset', 1) : 1;
        return (bool) apply_filters('wpc_css_bg_imageset', !empty($on));
    }


    public static function wpc_css_bg_disk_siblings($origin_url)
    {
        $out = ['avif' => false, 'webp' => false];
        $url = preg_replace('/[?#].*$/', '', (string) $origin_url);
        if ($url === '') return $out;

        $site = trailingslashit(site_url());
        $host = wp_parse_url($url, PHP_URL_HOST);
        $shst = wp_parse_url($site, PHP_URL_HOST);
        if (!$host || !$shst || strcasecmp((string) $host, (string) $shst) !== 0) return $out;
        if (strpos($url, '/wp-content/uploads/') === false) return $out;

        $base = str_replace($site, trailingslashit(ABSPATH), $url);
        $base = str_replace('/', DIRECTORY_SEPARATOR, $base);
        if (strpos($base, trailingslashit(ABSPATH)) !== 0) return $out;

        $avif = preg_replace('/\.(jpe?g|png)$/i', '.avif', $base);
        $webp = preg_replace('/\.(jpe?g|png)$/i', '.webp', $base);
        if (is_string($avif) && $avif !== $base && @file_exists($avif)
            && wps_rewriteLogic::picture_variant_dims_ok($avif, 0, 0)) {
            $out['avif'] = true;
        }
        if (is_string($webp) && $webp !== $base && @file_exists($webp)
            && wps_rewriteLogic::picture_variant_dims_ok($webp, 0, 0)) {
            $out['webp'] = true;
        }
        return $out;
    }


    public static function wpc_css_bg_imageset_build($origin_url, $sameext_zone, $quote = '')
    {
        if (!self::wpc_css_bg_imageset_active()) return '';
        $origin_url   = (string) $origin_url;
        $sameext_zone = (string) $sameext_zone;
        if ($origin_url === '' || $sameext_zone === '') return '';

        $clean = preg_replace('/[?#].*$/', '', $origin_url);
        $ext   = strtolower(pathinfo($clean, PATHINFO_EXTENSION));


        $css_nw   = wps_rewriteLogic::wpc_natural_nw();
        $css_exts = $css_nw ? ['jpg', 'jpeg', 'png', 'webp'] : ['jpg', 'jpeg', 'png'];
        if (!in_array($ext, $css_exts, true)) return '';
        $base_mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');

        $q = ($quote === '"' || $quote === "'") ? $quote : '';


        if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) {
            $webp_zone = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $sameext_zone);
            if (is_string($webp_zone) && $webp_zone !== '' && $webp_zone !== $sameext_zone) {
                return 'background-image:url(' . $q . $webp_zone . $q . ')';
            }
            return '';
        }


        if ($css_nw) {
            $avif_zone_nw = preg_replace('/\.(jpe?g|png|webp)(\?.*)?$/i', '.avif$2', $sameext_zone);
            $webp_zone_nw = preg_replace('/\.(jpe?g|png|webp)(\?.*)?$/i', '.webp$2', $sameext_zone);
            $nw_entries = [];
            if (is_string($avif_zone_nw) && $avif_zone_nw !== '' && $avif_zone_nw !== $sameext_zone) {
                $nw_entries[] = 'url(' . $q . $avif_zone_nw . $q . ') type("image/avif")';
            }
            if (is_string($webp_zone_nw) && $webp_zone_nw !== '' && $webp_zone_nw !== $sameext_zone) {
                $nw_entries[] = 'url(' . $q . $webp_zone_nw . $q . ') type("image/webp")';
            }
            $nw_entries[] = 'url(' . $q . $sameext_zone . $q . ') type("' . $base_mime . '")';
            if (count($nw_entries) < 2) return '';
            $nw_set = implode(',', $nw_entries);
            return 'background-image:url(' . $q . $sameext_zone . $q . ');'
                 . 'background-image:-webkit-image-set(' . $nw_set . ');'
                 . 'background-image:image-set(' . $nw_set . ')';
        }


        $sib = self::wpc_css_bg_disk_siblings($origin_url);
        if (empty($sib['avif']) && empty($sib['webp'])) return '';

        
        $avif_zone = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.avif$2', $sameext_zone);
        $webp_zone = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $sameext_zone);

        $entries = [];
        if (!empty($sib['avif']) && is_string($avif_zone) && $avif_zone !== '' && $avif_zone !== $sameext_zone) {
            $entries[] = 'url(' . $q . $avif_zone . $q . ') type("image/avif")';
        }
        if (!empty($sib['webp']) && is_string($webp_zone) && $webp_zone !== '' && $webp_zone !== $sameext_zone) {
            $entries[] = 'url(' . $q . $webp_zone . $q . ') type("image/webp")';
        }
        
        $entries[] = 'url(' . $q . $sameext_zone . $q . ') type("' . $base_mime . '")';
        if (count($entries) < 2) return '';

        $set = implode(',', $entries);
        return 'background-image:url(' . $q . $sameext_zone . $q . ');'
             . 'background-image:-webkit-image-set(' . $set . ');'
             . 'background-image:image-set(' . $set . ')';
    }


    







    public static function wpc_css_bg_prior_layers($prefix)
    {
        $prefix = (string) $prefix;
        if (!preg_match('/^(background(?:-image)?\s*:\s*)(.*)(url\(\s*)$/is', $prefix, $m)) {
            return ['skip' => false, 'layers' => ''];
        }
        $extra = trim($m[2]);
        if ($extra === '') {
            return ['skip' => false, 'layers' => ''];
        }
        if (!preg_match('/^background-image\s*:/i', $prefix) || substr($extra, -1) !== ',') {
            return ['skip' => true, 'layers' => ''];
        }
        return ['skip' => false, 'layers' => $m[2]];
    }


    public static function wpc_css_bg_imageset_sweep($css)
    {
        if (!is_string($css) || $css === '' || empty(self::$zone_name)) {
            return $css;
        }
        if (stripos($css, 'background') === false || !self::wpc_css_bg_imageset_active()) {
            return $css;
        }
        $zone = preg_quote(self::$zone_name, '#');
        $origin_host = function_exists('wp_parse_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
        $bases = function_exists('wpc_v2_upload_base_paths') ? wpc_v2_upload_base_paths() : ['/wp-content/uploads'];
        $alts = [];
        foreach ($bases as $b) {
            $b = trim((string) $b, '/');
            if ($b !== '') { $alts[] = preg_quote($b, '#'); }
        }
        if (empty($alts)) { $alts[] = preg_quote('wp-content/uploads', '#'); }
        $base_alt = '(?:' . implode('|', array_unique($alts)) . ')';
        
        
        
        
        
        
        $zone_host785 = strtok((string) self::$zone_name, '/');
        $zone_plain785 = ($zone_host785 === (string) self::$zone_name);
        $hosts785 = $zone;
        if ($zone_plain785 && $origin_host !== '' && strcasecmp($origin_host, $zone_host785) !== 0) {
            $hosts785 = '(?:' . $zone . '|' . preg_quote($origin_host, '#') . ')';
        }
        
        
        
        $rx = '#(background(?:-image)?\s*:\s*[^;{}]*?url\(\s*)([\'"]?)(https?://' . $hosts785 . '/' . $base_alt . '/[^"\'()\s<>]+?)\.(png|jpe?g|webp)((?:\?[^"\'()\s<>]*)?)\2(\s*\))(?=\s*(?:[;}<&\\\\]|[\'"]|$))(?!\s*;\s*background-image\s*:\s*[^;{}]*?(?:-webkit-)?image-set)#i';
        $out = preg_replace_callback($rx, static function ($m) use ($origin_host, $zone_host785, $zone_plain785) {
            if (stripos($m[0], 'image-set(') !== false) {
                return $m[0];
            }
            $wpc_pl822 = self::wpc_css_bg_prior_layers($m[1]);
            if (!empty($wpc_pl822['skip'])) {
                return $m[0];
            }
            $wpc_url785 = $m[3] . '.' . $m[4] . $m[5];
            $rel = function_exists('wp_parse_url') ? (string) wp_parse_url($wpc_url785, PHP_URL_PATH) : '';
            $wpc_isorigin785 = ($origin_host !== '' && stripos($m[3], '://' . $origin_host . '/') !== false);
            if ($wpc_isorigin785 && (!$zone_plain785 || $rel === '')) {
                return $m[0]; 
            }
            $sameext_zone = $wpc_isorigin785
                ? ('https://' . $zone_host785 . $rel . $m[5])
                : $wpc_url785;
            $origin_url = ($origin_host !== '' && $rel !== '') ? ('https://' . $origin_host . $rel) : $wpc_url785;
            $iset = self::wpc_css_bg_imageset_build($origin_url, $sameext_zone, $m[2]);
            if ($iset !== '' && $wpc_pl822['layers'] !== '') {
                $iset = str_replace('background-image:', 'background-image:' . $wpc_pl822['layers'], $iset);
            }
            return ($iset !== '') ? $iset : $m[0];
        }, $css);
        return is_string($out) ? $out : $css; 
    }


    
    








    public static function wpc_font_subset_families()
    {
        static $wpc_fam478 = null;
        if ($wpc_fam478 !== null) { return $wpc_fam478; }
        $wpc_fam478 = [];
        try {
            $wpc_p478 = '';
            if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_lcp_json_file')) {
                $wpc_lj478 = (string) wps_rewriteLogic::wpc_lcp_json_file();
                if ($wpc_lj478 !== '') { $wpc_p478 = dirname($wpc_lj478) . '/font-subsets.css'; }
            }
            if ($wpc_p478 === '' && defined('WPS_IC_CRITICAL') && class_exists('wps_ic_url_key')
                && function_exists('home_url')) {
                $wpc_k478 = (string) (new wps_ic_url_key())->setup(home_url('/'));
                if ($wpc_k478 !== '') {
                    $wpc_p478 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_k478 . '/font-subsets.css';
                }
            }
            if ($wpc_p478 !== '' && @is_readable($wpc_p478)) {
                $wpc_b478 = (string) @file_get_contents($wpc_p478);
                if ($wpc_b478 !== '' && preg_match_all('/font-family\s*:\s*["\']?([^"\';}]+)/i', $wpc_b478, $wpc_m478)) {
                    foreach ($wpc_m478[1] as $wpc_fn478) {
                        $wpc_key478 = strtolower(trim((string) $wpc_fn478, " \t\"'"));
                        if ($wpc_key478 !== '') { $wpc_fam478[$wpc_key478] = 1; }
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return $wpc_fam478;
    }

    public static function wpc_font_remote_ranges()
    {
        static $wpc_rr = null;
        if ($wpc_rr !== null) { return $wpc_rr; }
        $wpc_rr = [];
        if (!function_exists('get_option') || !apply_filters('wpc_font_remote_range', true)) { return $wpc_rr; }
        $raw = get_option('wpc_font_remote_ranges', []);
        if (!is_array($raw)) { return $wpc_rr; }
        foreach ($raw as $k => $v) {
            $v = preg_replace('/[^0-9A-Fa-fUu+,\- ]/', '', (string) $v);
            if ($v !== '' && is_string($k) && strpos($k, '|') !== false) { $wpc_rr[strtolower($k)] = $v; }
        }
        return $wpc_rr;
    }

    public static function wpc_svg_zoneify_active()
    {
        if (empty(self::$zone_name)) {
            return false;
        }
        $s = self::$settings;
        if (!is_array($s) || empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') {
            return false;
        }


        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            return false;
        }
        if (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled($s)) {
            return false;
        }
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$origin || strcasecmp((string) self::$zone_name, $origin) === 0) { 
            return false;
        }
        if (!self::wpc_zone_natural_witnessed750()) {
            return false;
        }
        return true;
    }

    
    
    
    
    
    public static function wpc_zone_natural_witnessed750()
    {
        if (function_exists('wpc_force_natural') && wpc_force_natural()) {
            return true;
        }
        return class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on')
            && wps_rewriteLogic::natural_assets_on();
    }

    
    
    
    
    
    
    
    public static function wpc_eager_nextgen752($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name) || stripos($html, '<img') === false) {
            return $html;
        }
        if (!apply_filters('wpc_eager_nextgen', true)) {
            return $html;
        }
        
        
        
        $wpc_gate754 = class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'picture_avif_emit_natural')
            && (wps_rewriteLogic::picture_avif_emit_natural()
                || (function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled()
                    && self::wpc_zone_natural_witnessed750()));
        if (!$wpc_gate754) {
            return $html;
        }
        
        
        
        $wpc_hosts752 = [preg_quote((string) self::$zone_name, '#')];
        
        
        
        
        
        
        $wpc_rx752 = '#(?<!:)https?://(?:' . implode('|', $wpc_hosts752)
            . ')/[^\s"\'<>,:]*?(?:-\d+x\d+)?\.(jpe?g|png)(?=[\s"\',])#i';
        
        
        $wpc_pstems752 = [];
        if (preg_match_all('#<link\b[^>]*rel="preload"[^>]*as="image"[^>]*href="([^"]+)"#i', $html, $wpc_pm752)) {
            foreach ($wpc_pm752[1] as $wpc_pu752) {
                $wpc_pb752 = basename((string) preg_replace('/\?.*$/', '', $wpc_pu752));
                $wpc_pb752 = (string) preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $wpc_pb752);
                $wpc_pb752 = (string) preg_replace('/\.[a-z0-9]+$/i', '', $wpc_pb752);
                if ($wpc_pb752 !== '') { $wpc_pstems752[strtolower($wpc_pb752)] = 1; }
            }
        }
        $wpc_pic752 = [];
        if (stripos($html, '<picture') !== false) {
            $html = preg_replace_callback('#<picture\b[^>]*>.*?</picture>#is', static function ($m) use (&$wpc_pic752) {
                $k = "\x01WPCEN" . count($wpc_pic752) . "\x01";
                $wpc_pic752[$k] = $m[0];
                return $k;
            }, $html);
            if (!is_string($html)) { return strtr(implode('', array_keys($wpc_pic752)), $wpc_pic752); }
        }
        $out = preg_replace_callback('#<img\b[^>]*>#i', static function ($m) use ($wpc_rx752, $wpc_pstems752) {
            $tag = $m[0];
            if (!preg_match('/\bfetchpriority\s*=\s*["\']high["\']|\bloading\s*=\s*["\']eager["\']/i', $tag)) {
                return $tag;
            }
            if (preg_match('/\bloading\s*=\s*["\']lazy["\']/i', $tag)
                || stripos($tag, 'data-wpc-nd') !== false || stripos($tag, 'data-wpc-md') !== false
                || stripos($tag, 'data-wpc-skip') !== false || stripos($tag, 'avif?src=') !== false) {
                return $tag;
            }
            if (!empty($wpc_pstems752) && preg_match('/\bsrc\s*=\s*["\']([^"\']+)/i', $tag, $wpc_sm752)) {
                $wpc_sb752 = basename((string) preg_replace('/\?.*$/', '', $wpc_sm752[1]));
                $wpc_sb752 = (string) preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $wpc_sb752);
                $wpc_sb752 = strtolower((string) preg_replace('/\.[a-z0-9]+$/i', '', $wpc_sb752));
                if ($wpc_sb752 !== '' && isset($wpc_pstems752[$wpc_sb752])) {
                    return $tag;
                }
            }
            $swapped = preg_replace_callback($wpc_rx752, static function ($u) {
                $ext = strtolower($u[1]);
                return substr($u[0], 0, -strlen($u[1])) . 'avif?src=' . $ext;
            }, $tag);
            return is_string($swapped) ? $swapped : $tag;
        }, $html);
        $html = is_string($out) ? $out : $html;
        if (!empty($wpc_pic752)) {
            $html = strtr($html, $wpc_pic752);
        }
        return $html;
    }

    public static function wpc_svg_zoneify($html)
    {
        $wpc_ps531 = class_exists('Wpc_Prof_Span530') ? new Wpc_Prof_Span530('pass:wpc_svg_zoneify') : null;
        if (!is_string($html) || $html === '' || !self::wpc_svg_zoneify_active()) {
            return $html;
        }
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        $o = preg_quote($origin, '#');
        
        $html = self::wpc_preg_safe(
            '#https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?\.svg(?:\?[^"\'()\s<>]*)?)#i',
            'https://' . self::$zone_name . '$1',
            $html
        );
        
        $html = self::wpc_preg_safe(
            '#(["\'(])(/wp-content/uploads/[^"\'()\s<>]+?\.svg(?:\?[^"\'()\s<>]*)?)#i',
            '$1https://' . self::$zone_name . '$2',
            $html
        );
        return $html;
    }

    public static function wpc_raster_zoneify_active()
    {
        if (empty(self::$zone_name)) {
            return false;
        }
        $s = self::$settings;
        if (!is_array($s) || empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') {
            return false;
        }
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            return false;
        }
        if (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled($s)) {
            return false;
        }
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$origin || strcasecmp((string) self::$zone_name, $origin) === 0) { 
            return false;
        }
        if (!self::wpc_zone_natural_witnessed750()) {
            return false;
        }
        return true;
    }


    public static function wpc_raster_zoneify($html)
    {
        $wpc_ps531 = class_exists('Wpc_Prof_Span530') ? new Wpc_Prof_Span530('pass:wpc_raster_zoneify') : null;
        if (!is_string($html) || $html === '' || !self::wpc_raster_zoneify_active()) {
            return $html;
        }
        $wpc_pic_blocks = [];
        if (stripos($html, '<picture') !== false) {


            $masked = '';
            $offset = 0;
            $hlen   = strlen($html);
            while (($start = stripos($html, '<picture', $offset)) !== false) {
                $after = ($start + 8 < $hlen) ? $html[$start + 8] : '';
                if ($after !== '' && (ctype_alnum($after) || $after === '_')) {

                    $masked .= substr($html, $offset, ($start + 8) - $offset);
                    $offset  = $start + 8;
                    continue;
                }
                $end = stripos($html, '</picture>', $start);
                if ($end === false) {
                    break;
                }
                $end += 10;
                $k = "\x01WPCPIC" . count($wpc_pic_blocks) . "\x01";
                $wpc_pic_blocks[$k] = substr($html, $start, $end - $start);
                $masked .= substr($html, $offset, $start - $offset) . $k;
                $offset  = $end;
            }
            $masked .= substr($html, $offset);
            $html = $masked;
        }
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        $o = preg_quote($origin, '#');


        $nat_gif = (class_exists('wps_rewriteLogic') && wps_rewriteLogic::cf_is_delivery()) ? '|gif' : '';
        $wpc_ng738 = apply_filters('wpc_raster_zoneify_nextgen', true) ? '|webp|avif' : '';


        $zn = self::$zone_name;
        $cf_cname_z  = (defined('WPS_IC_CF_CNAME') && function_exists('get_option')) ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $z_cf_direct = ($cf_cname_z !== '' && stripos((string) $zn, $cf_cname_z) !== false);
        $z_edge_webp = (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()
            && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_single_url_format'));
        $z_swap = static function ($path) use ($z_edge_webp, $z_cf_direct) {
            if (!preg_match('#^(.*\.)(png|jpe?g|gif)(\?.*)?$#i', $path, $mm)) return $path;
            $ext = strtolower($mm[2]);
            $fmt = $z_edge_webp ? wps_rewriteLogic::wpc_single_url_format($ext, $z_cf_direct, true) : $ext;
            $out = (is_string($fmt) && $fmt !== '') ? $fmt : $ext;
            return $mm[1] . $out . (isset($mm[3]) ? $mm[3] : '');
        };
        
        $z_abs = preg_replace_callback(
            '#https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?\.(?:png|jpe?g' . $nat_gif . $wpc_ng738 . ')(?:\?[^"\'()\s<>]*)?)#i',
            static function ($m) use ($zn, $z_swap) { return 'https://' . $zn . $z_swap($m[1]); },
            $html
        );
        if (is_string($z_abs)) $html = $z_abs;
        
        $z_rel = preg_replace_callback(
            '#(["\'(])(/wp-content/uploads/[^"\'()\s<>]+?\.(?:png|jpe?g' . $nat_gif . $wpc_ng738 . ')(?:\?[^"\'()\s<>]*)?)#i',
            static function ($m) use ($zn, $z_swap) { return $m[1] . 'https://' . $zn . $z_swap($m[2]); },
            $html
        );
        if (is_string($z_rel)) $html = $z_rel;
        if (!empty($wpc_pic_blocks)) {
            $html = strtr($html, $wpc_pic_blocks);
        }
        return $html;
    }


    public static function wpc_webp_immediate_ok()
    {


        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) {
            return false;
        }


        $cf = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']) || get_option('wpc_v2_cf_assets_seen', 0);
        if (!$cf && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'zone_is_cf')) {
            $cf = wps_rewriteLogic::zone_is_cf();
        }
        if (!$cf) {
            return true;
        }


        if (function_exists('wpc_force_natural') && wpc_force_natural()) {
            return true;
        }


        if (class_exists('WPC_Delivery_Resolver')) {
            $nav = WPC_Delivery_Resolver::orch_nav_signal();
            if ($nav === true)  return true;
            if ($nav === false) return false;
        }


        $pv = (string) get_transient('wpc_v2_cf_pod_version');


        if ($pv === '' && (is_admin() || (defined('DOING_CRON') && DOING_CRON))) {
            $zone = (string) get_option('ic_cdn_zone_name', '');
            if ($zone !== '') {
                $r  = wp_remote_get('https://' . $zone . '/wp-includes/css/dist/block-library/style.min.css', ['timeout' => 3, 'sslverify' => false, 'redirection' => 2, 'limit_response_size' => 2048]);
                $pv = is_wp_error($r) ? '' : (string) wp_remote_retrieve_header($r, 'x-cdn-version');

                set_transient('wpc_v2_cf_pod_version', $pv !== '' ? $pv : '0', $pv !== '' ? 12 * HOUR_IN_SECONDS : 2 * HOUR_IN_SECONDS);
            }
        }


        if ($pv !== '' && $pv !== '0' && version_compare(ltrim($pv, 'v'), '2.89.18.2', '<')) {
            return false;
        }
        return true;
    }

    





    private static function wpc_preg_safe($pattern, $replacement, $subject)
    {
        $out = preg_replace($pattern, $replacement, $subject);
        return is_string($out) ? $out : $subject;
    }


    public static function wpc_asset_naturalize($html)
    {
        $wpc_ps531 = class_exists('Wpc_Prof_Span530') ? new Wpc_Prof_Span530('pass:wpc_asset_naturalize') : null;
        if (!is_string($html) || $html === '' || empty(self::$zone_name) || stripos($html, '/m:0') === false) {
            return $html;
        }
        if (!apply_filters('wpc_asset_naturalize_enabled', true)) {
            return $html;
        }
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on')
            && !wps_rewriteLogic::natural_assets_on()) {
            return $html;
        }
        $zone = preg_quote(self::$zone_name, '#');
        $bs = '\\\\?/';
        $zone_name = self::$zone_name;
        
        
        $wpc_ext746 = 'css|js|mjs|svg|png|jpe?g|gif|webp|avif|ico|bmp|woff2?|ttf|otf|eot|mp4|webm|json';
        $rx = '#https?:' . $bs . $bs . '(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)' . $bs . 'm:0' . $bs . 'a:(https?:' . $bs . $bs . '[^"\'()\s<>]+?\.(?:' . $wpc_ext746 . ')(?:\?[^"\'()\s<>]*)?)#i';
        $out = preg_replace_callback($rx, static function ($m) use ($zone_name) {
            $u_esc = (strpos($m[1], '\\/') !== false);
            $u_plain = $u_esc ? str_replace('\\/', '/', $m[1]) : $m[1];
            $wpc_hops746 = 0;
            while ($wpc_hops746 < 4 && preg_match('#^https?://[^/]+/m:0/a:(https?://.+)$#i', $u_plain, $wpc_n746)) {
                $u_plain = $wpc_n746[1];
                $wpc_hops746++;
            }
            $p = function_exists('wp_parse_url') ? wp_parse_url($u_plain) : parse_url($u_plain);
            if (empty($p['path'])) {
                return $m[0];
            }


            if (!empty($p['host'])) {
                $asset_host = preg_replace('/^www\./i', '', (string) $p['host']);
                $site_host  = preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
                $zone_host  = preg_replace('/^www\./i', '', (string) $zone_name);
                $wpc_self746 = ($zone_host !== '' && strcasecmp($asset_host, $zone_host) === 0);
                if (!$wpc_self746 && $site_host !== '' && strcasecmp($asset_host, $site_host) !== 0) {
                    return $m[0];
                }
            }
            $natural = 'https://' . $zone_name . $p['path'] . (isset($p['query']) ? '?' . $p['query'] : '');
            if ($u_esc) {
                $natural = str_replace('/', '\\/', $natural);
            }
            return $natural;
        }, $html);
        return is_string($out) ? $out : $html;
    }

    public static function wpc_raster_naturalize($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name)) {
            return $html;
        }
        $wpc_pic_blocks = [];
        if (stripos($html, '<picture') !== false) {


            $masked = '';
            $offset = 0;
            $hlen   = strlen($html);
            while (($start = stripos($html, '<picture', $offset)) !== false) {
                $after = ($start + 8 < $hlen) ? $html[$start + 8] : '';
                if ($after !== '' && (ctype_alnum($after) || $after === '_')) {

                    $masked .= substr($html, $offset, ($start + 8) - $offset);
                    $offset  = $start + 8;
                    continue;
                }
                $end = stripos($html, '</picture>', $start);
                if ($end === false) {
                    break;
                }
                $end += 10;
                $k = "\x01WPCPIC" . count($wpc_pic_blocks) . "\x01";
                $wpc_pic_blocks[$k] = substr($html, $start, $end - $start);
                $masked .= substr($html, $offset, $start - $offset) . $k;
                $offset  = $end;
            }
            $masked .= substr($html, $offset);
            $html = $masked;
        }
        $html = self::wpc_raster_naturalize_passes($html);


        $html = self::wpc_css_bg_imageset_sweep($html);
        $html = self::wpc_css_bg_external_sweep($html);
        if (!empty($wpc_pic_blocks)) {
            $html = strtr($html, $wpc_pic_blocks);
        }
        return $html;
    }

    
    public static function wpc_css_path_collapse795($path)
    {
        $seg = explode('/', (string) $path);
        $out = [];
        foreach ($seg as $s) {
            if ($s === '.' || ($s === '' && !empty($out))) { continue; }
            if ($s === '..') { if (count($out) > 1) { array_pop($out); } continue; }
            $out[] = $s;
        }
        return implode('/', $out);
    }

    






    public static function wpc_css_rebase_urls795($css, $sheet_url)
    {
        $wpc_sp795 = (string) wp_parse_url((string) $sheet_url, PHP_URL_PATH);
        $wpc_sh795 = (string) wp_parse_url((string) $sheet_url, PHP_URL_HOST);
        if ($wpc_sp795 === '' || $wpc_sh795 === '') { return $css; }
        $wpc_dir795 = rtrim(str_replace('\\', '/', dirname($wpc_sp795)), '/');
        $wpc_out795 = preg_replace_callback('/\burl\(\s*(["\']?)([^"\')\s]+)\1\s*\)/i',
            static function ($um) use ($wpc_dir795, $wpc_sh795) {
                $u = (string) $um[2];
                if ($u === '' || $u[0] === '/' || $u[0] === '#'
                    || preg_match('#^(?:https?:)?//|^data:#i', $u)) {
                    return $um[0];
                }
                $abs = self::wpc_css_path_collapse795($wpc_dir795 . '/' . $u);
                return 'url(' . $um[1] . 'https://' . $wpc_sh795 . $abs . $um[1] . ')';
            }, (string) $css);
        return is_string($wpc_out795) ? $wpc_out795 : $css;
    }

    








    public static function wpc_css_bg_external_sweep($html)
    {
        try {
            if (!is_string($html) || $html === '' || stripos($html, '.css') === false
                || !self::wpc_css_bg_imageset_active()
                || !apply_filters('wpc_css_bg_sweep_external', true)) {
                return $html;
            }
            $wpc_oh795 = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            $wpc_site795 = (string) site_url('/');
            $wpc_sitep795 = (string) wp_parse_url($wpc_site795, PHP_URL_PATH);
            if ($wpc_oh795 === '' || !defined('WP_CONTENT_DIR')) { return $html; }
            $wpc_idx795 = get_option('wpc_bgset_idx');
            if (!is_array($wpc_idx795)) { $wpc_idx795 = []; }
            $wpc_dirty795 = false;
            $wpc_n795 = 0;
            $out = preg_replace_callback('/<link\b[^>]*\bhref=(["\'])([^"\']+\.css(?:\?[^"\']*)?)\1[^>]*>/i',
                static function ($lm) use ($wpc_oh795, $wpc_sitep795, &$wpc_idx795, &$wpc_dirty795, &$wpc_n795) {
                    if ($wpc_n795 >= (int) apply_filters('wpc_css_bg_sweep_external_max', 40)) { return $lm[0]; }
                    $href = (string) $lm[2];
                    $bn = strtolower(basename((string) wp_parse_url($href, PHP_URL_PATH)));
                    
                    if (strpos($href, '/cache/wpc-bgset/') !== false
                        || preg_match('/^(?:wps_|critical_|font-subsets|used)/', $bn)) {
                        return $lm[0];
                    }
                    $h = (string) wp_parse_url($href, PHP_URL_HOST);
                    if ($h !== '' && strcasecmp($h, $wpc_oh795) !== 0) { return $lm[0]; } 
                    $path = (string) wp_parse_url($href, PHP_URL_PATH);
                    if ($path === '' || strpos($path, '/wp-') === false) { return $lm[0]; }
                    
                    $rel = $path;
                    if ($wpc_sitep795 !== '' && $wpc_sitep795 !== '/' && strpos($rel, rtrim($wpc_sitep795, '/') . '/') === 0) {
                        $rel = substr($rel, strlen(rtrim($wpc_sitep795, '/')));
                    }
                    $disk = rtrim(ABSPATH, '/') . $rel;
                    $wpc_n795++;
                    $sz = @filesize($disk);
                    $mt = @filemtime($disk);
                    if (!$sz || !$mt || $sz < 64 || $sz > (int) apply_filters('wpc_css_bg_sweep_external_cap', 1048576)) {
                        return $lm[0];
                    }
                    $key = md5($path);
                    $sig = $mt . ':' . $sz;
                    if (isset($wpc_idx795[$key]) && $wpc_idx795[$key]['sig'] === $sig) {
                        $o = (string) $wpc_idx795[$key]['out'];
                        if ($o === '' || !@is_readable(WP_CONTENT_DIR . '/cache/wpc-bgset/' . $o)) {
                            return $lm[0];
                        }
                        return str_replace($lm[1] . $lm[2] . $lm[1],
                            $lm[1] . content_url('cache/wpc-bgset/' . $o) . $lm[1], $lm[0]);
                    }
                    $css = (string) @file_get_contents($disk);
                    $verdict = '';
                    if ($css !== '' && stripos($css, 'background') !== false) {
                        $based = self::wpc_css_rebase_urls795($css, 'https://' . $wpc_oh795 . $path);
                        $swept = self::wpc_css_bg_imageset_sweep($based);
                        if (is_string($swept) && substr_count($swept, 'image-set(') > substr_count($css, 'image-set(')) {
                            $wpc_dd795 = WP_CONTENT_DIR . '/cache/wpc-bgset';
                            if (!is_dir($wpc_dd795)) { @mkdir($wpc_dd795, 0755, true); }
                            $stem = preg_replace('/\.css$/', '', basename($path));
                            $name = $stem . '-' . substr(md5($swept), 0, 10) . '.css';
                            if (@file_put_contents($wpc_dd795 . '/' . $name, $swept) !== false) {
                                
                                
                                $wpc_sib795 = (array) @glob($wpc_dd795 . '/' . $stem . '-*.css');
                                if (count($wpc_sib795) > 3) {
                                    usort($wpc_sib795, static function ($a, $b) {
                                        return (int) @filemtime($a) - (int) @filemtime($b);
                                    });
                                    foreach (array_slice($wpc_sib795, 0, count($wpc_sib795) - 3) as $old) {
                                        if (basename($old) !== $name) { @unlink($old); }
                                    }
                                }
                                $verdict = $name;
                            }
                        }
                    }
                    $wpc_idx795[$key] = ['sig' => $sig, 'out' => $verdict];
                    $wpc_dirty795 = true;
                    if ($verdict === '') { return $lm[0]; }
                    return str_replace($lm[1] . $lm[2] . $lm[1],
                        $lm[1] . content_url('cache/wpc-bgset/' . $verdict) . $lm[1], $lm[0]);
                }, $html);
            if ($wpc_dirty795) {
                if (count($wpc_idx795) > 80) { $wpc_idx795 = array_slice($wpc_idx795, -60, null, true); }
                update_option('wpc_bgset_idx', $wpc_idx795, false);
            }
            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    private static function wpc_raster_naturalize_passes($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name)) {
            return $html;
        }
        $s = self::$settings;
        if (!is_array($s) || empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') {
            return $html;
        }
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            return $html;
        }
        if (!class_exists('WPC_Negotiated_Delivery') || !WPC_Negotiated_Delivery::cdn_images_enabled($s)) {
            return $html;
        }
        if (!self::wpc_zone_natural_witnessed750()) {
            return $html;
        }
        $zone = preg_quote((string) self::$zone_name, '#');


        $html = self::wpc_preg_safe(
            '#https?://(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)/(?:(?:q|r|wp|w|m):[a-z0-9]+/|font:true/){1,40}(?:a|u):(https?://' . $zone . '/[^"\'()\s<>]+)#i',
            '$1',
            $html
        );
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$origin || strcasecmp((string) self::$zone_name, $origin) === 0) { 
            return $html;
        }
        

        
        
        $bs = '\\\\?/';


        $wpc_bases = function_exists('wpc_v2_upload_base_paths') ? wpc_v2_upload_base_paths() : ['/wp-content/uploads'];
        $base_parts = [];
        foreach ($wpc_bases as $wpc_b) {
            $wpc_b = trim((string) $wpc_b, '/');
            if ($wpc_b === '') { continue; }
            $base_parts[] = implode($bs, array_map(function ($s) { return preg_quote($s, '#'); }, explode('/', $wpc_b)));
        }
        $base_alt = !empty($base_parts) ? '(?:' . implode('|', array_unique($base_parts)) . ')' : 'wp-content' . $bs . 'uploads';
        $rx = '#https?://(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)/(?:q:[a-z0-9]+/)?r:\d+/wp:(\d)/w:\d+/u:(https?:' . $bs . $bs . '[^"\'()\s<>]+?' . $bs . $base_alt . $bs . '[^"\'()\s<>]+?\.(?:png|jpe?g|webp|gif)(?:\?[^"\'()\s<>]*)?)#i';
        $naturalize = static function ($m, $allow_webp) use ($wpc_bases) {


            if ($m[1] === '2') {
                return $m[0];
            }
            
            
            $u_esc = (strpos($m[2], '\\/') !== false);
            $u_plain = $u_esc ? str_replace('\\/', '/', $m[2]) : $m[2];
            $pos = false;
            foreach ($wpc_bases as $wpc_b) {
                $needle = '/' . trim((string) $wpc_b, '/') . '/';
                if ($needle === '//') { continue; }
                $p = stripos($u_plain, $needle);
                if ($p !== false) { $pos = $p; break; }
            }
            if ($pos === false) {
                return $m[0];
            }


            if (preg_match_all('#https?://([^/"\'()\s<>]+)#i', substr($u_plain, 0, $pos), $host_matches) && !empty($host_matches[1])) {
                $asset_host = preg_replace('/^www\./i', '', (string) end($host_matches[1]));
                $site_host  = preg_replace('/^www\./i', '', (string) wp_parse_url(home_url(), PHP_URL_HOST));
                if ($site_host !== '' && strcasecmp($asset_host, $site_host) !== 0) {
                    return $m[0];
                }
            }


            
            
            $wpc_w96 = preg_match('#/w:(\d+)/(?:a|u):#i', $m[0], $wpc_wm96) ? (int) $wpc_wm96[1] : 1;
            if ($wpc_w96 > 1) {
                $wpc_path96 = preg_replace('/\?.*$/', '', $u_plain);
                if (!preg_match('/-(\d+)x\d+\.(?:png|jpe?g|webp|gif)$/i', $wpc_path96, $wpc_sm96)
                    || (int) $wpc_sm96[1] > $wpc_w96) {
                    return $m[0];
                }
            }
            $rel = substr($u_plain, $pos);
            if ($allow_webp && $m[1] === '1') {
                $rel = preg_replace('/\.(?:png|jpe?g)(\?|$)/i', '.webp$1', $rel);
            }
            $natural = 'https://' . self::$zone_name . $rel;
            if ($u_esc) {
                $natural = str_replace('/', '\\/', $natural);
            }
            return $natural;
        };
        
        
        $html = preg_replace_callback('#<(?:link|meta)\b[^>]*>#i', static function ($tag) use ($rx, $naturalize) {
            return preg_replace_callback($rx, static function ($m) use ($naturalize) {
                return $naturalize($m, false);
            }, $tag[0]);
        }, $html);


        $rx_w1 = '#https?://(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)/(?:q:[a-z0-9]+/)?r:\d+/wp:(\d)/w:1/u:(https?:' . $bs . $bs . '[^"\'()\s<>]+?' . $bs . $base_alt . $bs . '[^"\'()\s<>]+?\.(?:png|jpe?g|webp|gif)(?:\?[^"\'()\s<>]*)?)#i';


        $html = preg_replace_callback($rx_w1, static function ($m) use ($naturalize) {
            $u   = ($m[1] === '1') ? (strpos($m[2], '\\/') !== false ? str_replace('\\/', '/', $m[2]) : $m[2]) : '';
            $ext = $u !== '' ? strtolower(pathinfo(preg_replace('/\?.*$/', '', $u), PATHINFO_EXTENSION)) : '';
            $allow = ($ext !== '' && class_exists('wps_rewriteLogic')
                && wps_rewriteLogic::wpc_single_url_format($ext, null, null) === 'webp');
            return $naturalize($m, $allow);
        }, $html);
        $nd_webp = WPC_Negotiated_Delivery::is_active();
        $nd_jpeg = !$nd_webp && WPC_Negotiated_Delivery::is_active_jpeg();


        $otf_live = (function_exists('wpc_force_natural') && wpc_force_natural())
            || (class_exists('wps_rewriteLogic') && wps_rewriteLogic::avif_natural_source_ok())
            || self::wpc_webp_immediate_ok();
        if (!$nd_webp && !$nd_jpeg && !$otf_live) {
            return $html;
        }


        $html = preg_replace_callback($rx, static function ($m) use ($naturalize, $nd_webp) {
            if ($nd_webp) {
                return $naturalize($m, true);
            }
            $u   = (strpos($m[2], '\\/') !== false) ? str_replace('\\/', '/', $m[2]) : $m[2];
            $ext = strtolower(pathinfo(preg_replace('/\?.*$/', '', $u), PATHINFO_EXTENSION));
            $allow = ($ext !== '' && class_exists('wps_rewriteLogic')
                && wps_rewriteLogic::wpc_single_url_format($ext, null, null) === 'webp');
            return $naturalize($m, $allow);
        }, $html);


        $html = preg_replace_callback('#<img\b[^>]*\bdata-src="[^"]+"[^>]*>#i', static function ($m) {
            $tag = $m[0];
            if (strpos($tag, 'src="data:image/svg+xml;base64,') === false) {
                return $tag;
            }
            if (!preg_match('/\bdata-src="([^"]+)"/', $tag, $ds)) {
                return $tag;
            }
            $tag = preg_replace('/\bsrc="data:image\/svg\+xml;base64,[^"]*"/', 'src="' . $ds[1] . '"', $tag, 1);
            return preg_replace('/\s+data-src="[^"]+"/', '', $tag, 1);
        }, $html);
        return $html;
    }

    public function buffer_local_callback_wrapped($html)
    {
        $wpc_span530 = class_exists('Wpc_Prof_Span530')
            ? new Wpc_Prof_Span530('OBCHAIN:buffer_local_callback_wrapped') : null;
        
        
        
        
        
        
        
        
        
        
        
        if (function_exists('wpc_is_low_value_page') && function_exists('did_action')
            && did_action('template_redirect') && wpc_is_low_value_page()) {
            $GLOBALS['wpc_shed520'] = 1;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('rewrite-skip-lowvalue', '', '', ['fn' => __FUNCTION__]);
            }
            return $html;
        }
        if ((function_exists('wpc_render_slot_acquire') && !wpc_render_slot_acquire())
            || (function_exists('wpc_memory_pressure') && wpc_memory_pressure())
            || (function_exists('wpc_under_pressure') && wpc_under_pressure())
            || (function_exists('wpc_safe_mode') && wpc_safe_mode())) {
            $GLOBALS['wpc_shed520'] = 1;
            if (function_exists('wpc_prof_mark')) { wpc_prof_mark('shed:' . __FUNCTION__, microtime(true)); }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('rewrite-shed', '', '', ['fn' => __FUNCTION__, 'bytes' => strlen((string) $html)]);
            }
            return $html;
        }
        
        
        if (function_exists('wpc_inv2_stash')) {
            wpc_inv2_stash($html);
        }
        $wpc_in71 = $html;
        try {
            $html = self::wpc_collapse_double_ext(self::wpc_asset_naturalize(self::wpc_eager_nextgen752(self::wpc_raster_zoneify(self::wpc_svg_zoneify(self::wpc_raster_naturalize(self::wpc_svg_naturalize($this->buffer_local_callback($html))))))));
            $wpc_fb735a = self::add_asset_failover($html);
            if (is_string($wpc_fb735a) && $wpc_fb735a !== '') { $html = $wpc_fb735a; }
            if (class_exists('wps_cacheHtml')) {
                $html = wps_cacheHtml::critlessUndefer($html);
                $html = wps_cacheHtml::varsGuard($html);
                $html = wps_cacheHtml::bricksAtfUnveil($html);
                $html = wps_cacheHtml::critBgPreload($html);
            }
            $html = self::wpc_lazy_srcset_buffer_pass($html);
            $html = self::wpc_srcset_space_encode_pass($html);
            $html = self::wpc_lcp_hint_pass($html);
            $html = self::wpc_afold_sizes_pass($html);
            $html = self::wpc_quiet_wire_pass($html);
            $html = self::wpc_below_fold_cv_tag($html);
            $html = self::wpc_picture_sizes_parity_pass($html);
            $html = self::wpc_picture_fidelity_pass($html);
            $html = self::wpc_lcp_img_preload_pass($html);
            $html = self::wpc_embed_facade_pass($html);
            $html = self::wpc_rum_beacon_pass($html);
            $html = self::wpc_font_preconnect_pass($html);
            $html = self::wpc_zone_font_preconnect_pass($html);
            $html = self::wpc_fonts_drop_remote_dup($html);
            $html = self::wpc_zone_preconnect_prune_pass($html);
            $html = self::wpc_svg_dims_pass($html);
            $html = self::wpc_img_aspect_pass($html);
            $html = self::wpc_lcp_eager_invariant_pass($html);
            $html = self::wpc_hoist_viewport_pass($html);
            $html = self::wpc_prune_idle_preconnects_pass($html);
            $html = self::wpc_video_delay_pass($html);
            $html = self::wpc_trim_preset_vars_page($html);
            $html = self::wpc_slider_settle_pass($html);
            $html = self::wpc_preload_img_coherence796($html);
            $html = self::wpc_freshness_marker($html);
        } catch (\Throwable $wpc_t71) {
            return self::wpc_never_blank($wpc_in71, '', $wpc_t71);
        }
        return self::wpc_never_blank($wpc_in71, $html);
    }


    
    
    public static function wpc_video_delay_pass($html)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_video_delay', true)
            || stripos($html, '<video') === false) {
            return $html;
        }
        $out = preg_replace_callback('/<video\b[^>]*>/i', function ($m) {
            $t = $m[0];
            if (stripos($t, 'autoplay') === false || stripos($t, 'data-wpc-src') !== false
                || !preg_match('/\bposter\s*=\s*["\'][^"\']{8,}["\']/i', $t)
                || !preg_match('/(?<![-\w])src\s*=\s*["\']([^"\']+\.(?:mp4|webm)(?:\?[^"\']*)?)["\']/i', $t, $sm)) {
                return $t;
            }
            $t = (string) preg_replace('/(?<![-\w])src\s*=\s*["\'][^"\']*["\']/i', 'data-wpc-src="' . esc_attr($sm[1]) . '"', $t, 1);
            if (preg_match('/\bclass\s*=\s*["\']([^"\']*)["\']/i', $t)) {
                $t = (string) preg_replace('/\bclass\s*=\s*(["\'])([^"\']*)\1/i', 'class=$1$2 wpc-video-delay$1', $t, 1);
            } else {
                $t = (string) preg_replace('/<video\b/i', '<video class="wpc-video-delay"', $t, 1);
            }
            return $t;
        }, $html);
        return is_string($out) ? $out : $html;
    }


    
    
    
    
    
    public static function wpc_elementor_bands797($html)
    {
        if (!is_string($html) || $html === '') {
            return [];
        }
        $i = strpos($html, '"responsive":{"breakpoints":');
        if ($i === false) {
            return [];
        }
        $chunk = substr($html, $i, 2400);
        $order = ['mobile', 'mobile_extra', 'tablet', 'tablet_extra', 'laptop'];
        $maxes = [];
        foreach ($order as $dev) {
            if (preg_match('/"' . $dev . '":\{[^{}]*"value":(\d+)[^{}]*"direction":"max"[^{}]*"is_enabled":true/', $chunk, $m)) {
                $maxes[] = ['dev' => $dev, 'v' => (int) $m[1]];
            }
        }
        if (!$maxes) {
            return [];
        }
        $wide = 0;
        if (preg_match('/"widescreen":\{[^{}]*"value":(\d+)[^{}]*"direction":"min"[^{}]*"is_enabled":true/', $chunk, $wm)) {
            $wide = (int) $wm[1];
        }
        $bands = [];
        
        $bands['mobile'] = [0, $maxes[0]['v'] - 1];
        for ($k = 1; $k < count($maxes); $k++) {
            $bands[$maxes[$k]['dev']] = [$maxes[$k - 1]['v'], $maxes[$k]['v'] - 1];
        }
        $last = $maxes[count($maxes) - 1];
        $bands['desktop'] = [$last['v'], $wide ? $wide - 1 : 0];
        if ($wide) {
            $bands['widescreen'] = [$wide, 0];
        }
        return $bands;
    }

    
    
    
    
    
    
    
    
    
    
    
    
    public static function wpc_slider_settle_pass($html)
    {
        if (!is_string($html) || $html === ''
            || strpos($html, 'slides_per_view') === false
            || stripos($html, 'swiper') === false
            || strpos($html, 'wpc-slider-settle797') !== false
            || stripos($html, '</head>') === false) {
            return $html;
        }
        if (empty(self::$settings['delay-js']) && empty(self::$settings['delay-js-v2'])) {
            return $html;
        }
        if (!apply_filters('wpc_slider_settle', true)) {
            return $html;
        }
        try {
            $bands = self::wpc_elementor_bands797($html);
            if (!$bands) {
                return $html;
            }
            if (!preg_match_all('/<div\b[^>]*class="([^"]*\belementor-element-([a-z0-9]+)\b[^"]*)"[^>]*data-settings="([^"]*slides_per_view[^"]*)"/i', $html, $wm, PREG_SET_ORDER)) {
                return $html;
            }
            $css = '';
            $n_widgets = 0;
            foreach ($wm as $w) {
                if (++$n_widgets > 6) {
                    break;
                }
                $cfg = json_decode(html_entity_decode($w[3], ENT_QUOTES), true);
                if (!is_array($cfg)) {
                    continue;
                }
                $sel = '.elementor-element-' . $w[2];
                $rules = '';
                foreach ($bands as $dev => $mm) {
                    $nk = ($dev === 'desktop') ? 'slides_per_view' : 'slides_per_view_' . $dev;
                    $gk = ($dev === 'desktop') ? 'space_between' : 'space_between_' . $dev;
                    $n = isset($cfg[$nk]) ? $cfg[$nk] : null;
                    if ($n === null && $dev === 'widescreen'
                        && (isset($cfg['space_between_widescreen']) || isset($cfg['slides_to_scroll_widescreen']))) {
                        $n = isset($cfg['slides_per_view']) ? $cfg['slides_per_view'] : null;
                    }
                    if ($n === null || !preg_match('/^\d+$/', (string) $n) || (int) $n < 2) {
                        continue;
                    }
                    $n = (int) $n;
                    $gap = (isset($cfg[$gk]['size']) && is_numeric($cfg[$gk]['size'])) ? (float) $cfg[$gk]['size'] : $gap_desk;
                    $gap = rtrim(rtrim(sprintf('%.2F', $gap), '0'), '.');
                    $q = '@media(min-width:' . (int) $mm[0] . 'px)' . ($mm[1] ? ' and (max-width:' . (int) $mm[1] . 'px)' : '');
                    $rules .= $q . '{' . $sel . ' .swiper-slide{flex-shrink:0;width:calc((100% - '
                        . ($n - 1) . '*' . $gap . 'px)/' . $n . ');margin-right:' . $gap . 'px}}';
                }
                if ($rules === '') {
                    continue;
                }
                $css .= $sel . ' .swiper,' . $sel . ' .swiper-container,' . $sel . ' .elementor-main-swiper{overflow:hidden}'
                    . $sel . ' .swiper-wrapper{display:flex}' . $rules;
            }
            if ($css === '' || strlen($css) > 8192) {
                return $html;
            }
            $tag = '<style id="wpc-slider-settle797">' . $css . '</style>';
            $out = preg_replace('#</head>#i', $tag . '</head>', $html, 1);
            return is_string($out) ? $out : $html;
        } catch (\Throwable $wpc_e797) {
            return $html;
        }
    }

    
    
    
    public static function wpc_preload_stem796($url)
    {
        $u = html_entity_decode((string) $url, ENT_QUOTES);
        $u = (string) preg_replace('/[?#].*$/', '', $u);
        
        
        $cp = strrpos($u, '/wp-content/');
        $b = ($cp !== false) ? substr($u, $cp) : basename($u);
        $b = (string) preg_replace('/\.(avif|webp)$/i', '', $b);
        $b = (string) preg_replace('/\.[a-z0-9]+$/i', '', $b);
        $b = (string) preg_replace('/-\d+x\d+$/', '', $b);
        return strtolower($b);
    }

    
    
    
    
    
    
    
    
    
    public static function wpc_preload_img_coherence796($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<img') === false
            || stripos($html, 'rel="preload"') === false
            || !apply_filters('wpc_preload_img_coherence', true)) {
            return $html;
        }
        try {
            $wpc_imgs796 = [];
            if (preg_match_all('#<img\b[^>]*>#i', $html, $wpc_im796)) {
                foreach ($wpc_im796[0] as $wpc_t796) {
                    if (!preg_match('/(?<![-\w])src\s*=\s*["\']([^"\']+)["\']/i', $wpc_t796, $wpc_sm796)) {
                        continue;
                    }
                    $wpc_src796 = trim($wpc_sm796[1]);
                    $wpc_lazy796 = (bool) preg_match('/\bloading\s*=\s*["\']lazy["\']/i', $wpc_t796);
                    $wpc_real796 = $wpc_src796;
                    if (stripos($wpc_src796, 'data:') === 0) {
                        $wpc_lazy796 = true;
                        if (preg_match('/\bdata-wpc-qw-src\s*=\s*["\']([^"\']+)["\']/i', $wpc_t796, $wpc_qm796)
                            || preg_match('/\bdata-(?:wpc-)?lazy-?src\s*=\s*["\']([^"\']+)["\']/i', $wpc_t796, $wpc_qm796)) {
                            $wpc_real796 = trim($wpc_qm796[1]);
                        } else {
                            continue;
                        }
                    }
                    $wpc_st796 = self::wpc_preload_stem796($wpc_real796);
                    if ($wpc_st796 === '') {
                        continue;
                    }
                    
                    if (isset($wpc_imgs796[$wpc_st796]) && !$wpc_imgs796[$wpc_st796]['lazy']) {
                        continue;
                    }
                    $wpc_imgs796[$wpc_st796] = [
                        'src'    => $wpc_src796,
                        'lazy'   => $wpc_lazy796,
                        'srcset' => preg_match('/(?<![-\w])srcset\s*=\s*["\']([^"\']+)["\']/i', $wpc_t796, $wpc_ss796) ? trim($wpc_ss796[1]) : '',
                        'sizes'  => preg_match('/(?<![-\w])sizes\s*=\s*["\']([^"\']+)["\']/i', $wpc_t796, $wpc_sz796) ? trim($wpc_sz796[1]) : '',
                    ];
                }
            }
            if (empty($wpc_imgs796)) {
                return $html;
            }
            $wpc_mob796 = function_exists('wpc_ua_is_mobile') ? (bool) wpc_ua_is_mobile() : false;
            $wpc_fixed796 = 0;
            $wpc_dropped796 = 0;
            $out = preg_replace_callback(
                '#<link\b[^>]*\brel\s*=\s*["\']preload["\'][^>]*>#i',
                function ($m) use ($wpc_imgs796, $wpc_mob796, &$wpc_fixed796, &$wpc_dropped796) {
                    $t = $m[0];
                    if (!preg_match('/\bas\s*=\s*["\']image["\']/i', $t)
                        || !preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $t, $hm)) {
                        return $t;
                    }
                    
                    if (preg_match('/\bid\s*=\s*["\'][^"\']*bg-preload/i', $t)) {
                        return $t;
                    }
                    
                    if (preg_match('/\bmedia\s*=\s*["\']([^"\']+)["\']/i', $t, $mm)) {
                        $wpc_maxw796 = (stripos($mm[1], 'max-width') !== false);
                        $wpc_minw796 = (stripos($mm[1], 'min-width') !== false);
                        if (($wpc_maxw796 && !$wpc_minw796 && !$wpc_mob796)
                            || ($wpc_minw796 && !$wpc_maxw796 && $wpc_mob796)) {
                            return $t;
                        }
                    }
                    $st = self::wpc_preload_stem796($hm[1]);
                    if ($st === '' || !isset($wpc_imgs796[$st])) {
                        return $t;
                    }
                    $img = $wpc_imgs796[$st];
                    if (!empty($img['lazy'])) {
                        $wpc_dropped796++;
                        return apply_filters('wpc_preload_img_drop_lazy', true) ? '' : $t;
                    }
                    
                    
                    
                    $new = preg_replace_callback('/\bhref\s*=\s*["\'][^"\']*["\']/i', function () use ($img) {
                        return 'href="' . $img['src'] . '"';
                    }, $t, 1);
                    if (!is_string($new) || $new === '') {
                        return $t;
                    }
                    $new = (string) preg_replace('/\s*\bimagesrcset\s*=\s*["\'][^"\']*["\']/i', '', $new);
                    $new = (string) preg_replace('/\s*\bimagesizes\s*=\s*["\'][^"\']*["\']/i', '', $new);
                    
                    $new = (string) preg_replace('/\s*\btype\s*=\s*["\'][^"\']*["\']/i', '', $new);
                    $add = '';
                    if ($img['srcset'] !== '') {
                        $add = ' imagesrcset="' . $img['srcset'] . '"';
                        if ($img['sizes'] !== '') {
                            $add .= ' imagesizes="' . $img['sizes'] . '"';
                        }
                    }
                    if ($add !== '') {
                        $new = (string) preg_replace_callback('#\s*/?>$#', function () use ($add) {
                            return $add . '>';
                        }, $new, 1);
                    }
                    if ($new !== $t) {
                        $wpc_fixed796++;
                    }
                    return $new;
                },
                $html
            );
            if (!is_string($out)) {
                return $html;
            }
            if (($wpc_fixed796 || $wpc_dropped796) && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('preload-coherence', '', '', [
                    'mirrored' => $wpc_fixed796,
                    'dropped'  => $wpc_dropped796,
                    'dev'      => $wpc_mob796 ? 'm' : 'd',
                ]);
            }
            return $out;
        } catch (\Throwable $wpc_e796) {
            return $html;
        }
    }

    
    
    
    public static function wpc_img_aspect_pass($html)
    {
        
        
        
        
        
        
        
        
        $wpc_own482 = '/\b(?:data-count-lazy|ic-fade-in|wps-ic-cdn|wpc-nd|wpc-lcp-optimized|wpc-lazy-skipped)|data-wpc-/i';
        if (!is_string($html) || $html === '' || !apply_filters('wpc_img_aspect', true)
            || !preg_match($wpc_own482, $html)) {
            return $html;
        }
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($wpc_own482) {
            $t = $m[0];
            if (!preg_match($wpc_own482, $t)) {
                return $t;
            }
            if (stripos($t, 'aspect-ratio') !== false) {
                return $t;
            }
            
            
            
            
            
            
            
            if (!apply_filters('wpc_img_aspect_inline_all', false)
                && !preg_match('/\b(?:data-count-lazy|ic-fade-in)\b/i', $t)
                && !preg_match('/\bsrc\s*=\s*["\'](?:data:|[^"\']*\bblank\b)/i', $t)) {
                return $t;
            }
            if (!preg_match('/\bwidth="(\d{2,5})"/i', $t, $w) || !preg_match('/\bheight="(\d{2,5})"/i', $t, $h)) {
                return $t;
            }
            $ar = ' style="aspect-ratio:' . (int) $w[1] . '/' . (int) $h[1] . '"';
            if (preg_match('/\bstyle\s*=\s*"([^"]*)"/i', $t)) {
                return (string) preg_replace('/\bstyle\s*=\s*"([^"]*)"/i', 'style="aspect-ratio:' . (int) $w[1] . '/' . (int) $h[1] . ';$1"', $t, 1);
            }
            return (string) preg_replace('/<img\b/i', '<img' . str_replace('$', '\$', $ar), $t, 1);
        }, $html);
        return is_string($out) ? $out : $html;
    }

    public static function wpc_svg_dims_pass($html)
    {
        try {
            if (!is_string($html) || $html === '' || stripos($html, '.svg') === false) {
                return $html;
            }
            if (!apply_filters('wpc_svg_dims', true)) {
                return $html;
            }
            $wpc_n132 = 0;
            
            
            
            
            
            $wpc_carr489 = function ($t) {
                foreach (['src', 'data-wpc-qw-src', 'data-wpc-src', 'data-src'] as $wpc_a489) {
                    if (preg_match('/\s' . preg_quote($wpc_a489, '/') . '=["\']([^"\']+\.svg)(?:\?[^"\']*)?["\']/i', $t, $wpc_m489)) {
                        return $wpc_m489[1];
                    }
                }
                return '';
            };
            $out = preg_replace_callback(
                '/<img\b[^>]*>/i',
                function ($m) use (&$wpc_n132, $wpc_carr489) {
                    if ($wpc_n132 >= 5) { return $m[0]; }
                    if (stripos($m[0], '.svg') === false) { return $m[0]; }
                    
                    $wpc_su489 = $wpc_carr489($m[0]);
                    if ($wpc_su489 === '') { return $m[0]; }
                    
                    
                    
                    if (preg_match('/\bheight\s*=\s*["\']?(\d{1,4})/i', $m[0], $hm0)
                        && preg_match('/\bwidth\s*=\s*["\']?(\d{1,4})/i', $m[0], $wm0)) {
                        if (stripos($m[0], 'aspect-ratio') !== false) { return $m[0]; }
                        $wpc_n132++;
                        $ar0 = ' style="aspect-ratio:' . (int) $wm0[1] . '/' . (int) $hm0[1] . '"';
                        if (preg_match('/\bstyle\s*=\s*"([^"]*)"/i', $m[0])) {
                            return (string) preg_replace('/\bstyle\s*=\s*"([^"]*)"/i',
                                'style="$1;aspect-ratio:' . (int) $wm0[1] . '/' . (int) $hm0[1] . '"', $m[0], 1);
                        }
                        return (string) preg_replace('/<img\b/i', '<img' . $ar0, $m[0], 1);
                    }
                    if (preg_match('/\bheight\s*=/i', $m[0])) { return $m[0]; }
                    
                    $w = 0;
                    if (preg_match('/\bwidth\s*=\s*["\']?(\d{1,4})/i', $m[0], $wm)) {
                        $w = (int) $wm[1];
                        if ($w < 8 || $w > 4000) { return $m[0]; }
                    }
                    $src = html_entity_decode($wpc_su489, ENT_QUOTES);
                    $cp  = strrpos($src, 'wp-content/'); 
                    if ($cp === false) { return $m[0]; }
                    $rel = (string) preg_replace('/[?#].*$/', '', substr($src, $cp));
                    if ($rel === '' || strpos($rel, '..') !== false) { return $m[0]; }
                    $tk = 'wpc_svgar2_' . md5($rel);
                    $dim = get_transient($tk);
                    if (!is_array($dim)) {
                        $dim = ['ar' => 0, 'w' => 0];
                        $head = @file_get_contents(trailingslashit(ABSPATH) . $rel, false, null, 0, 4096);
                        if (is_string($head) && $head !== '') {
                            if (preg_match('/<svg\b[^>]*\bviewBox\s*=\s*["\']\s*[\d.+-]+[\s,]+[\d.+-]+[\s,]+([\d.]+)[\s,]+([\d.]+)/i', $head, $vb) && (float) $vb[1] > 0) {
                                $dim = ['ar' => (float) $vb[2] / (float) $vb[1], 'w' => (float) $vb[1]];
                            } elseif (preg_match('/<svg\b[^>]*\bwidth\s*=\s*["\']?([\d.]+)(?:px)?["\']?[^>]*\bheight\s*=\s*["\']?([\d.]+)/i', $head, $wh) && (float) $wh[1] > 0) {
                                $dim = ['ar' => (float) $wh[2] / (float) $wh[1], 'w' => (float) $wh[1]];
                            }
                        }
                        set_transient($tk, $dim, WEEK_IN_SECONDS);
                    }
                    $ar = (float) $dim['ar'];
                    if ($ar <= 0.01 || $ar > 20) { return $m[0]; }
                    if ($w < 1) {
                        $w = (int) round((float) $dim['w']);
                        if ($w < 8 || $w > 4000) { return $m[0]; }
                    }
                    $wpc_n132++;
                    $h = max(1, (int) round($w * $ar));
                    
                    
                    $wpc_ins0 = ' height="' . $h . '"';
                    if (stripos($m[0], 'aspect-ratio') === false && !preg_match('/\bstyle\s*=/i', $m[0])) {
                        $wpc_ins0 .= ' style="aspect-ratio:' . $w . '/' . $h . '"';
                    }
                    if (preg_match('/\bwidth\s*=\s*["\']?\d{1,4}/i', $m[0])) {
                        return (string) preg_replace('/(\bwidth\s*=\s*["\']?\d{1,4}["\']?)/i', '$1' . $wpc_ins0, $m[0], 1);
                    }
                    return (string) preg_replace('/<img\b/i', '<img width="' . $w . '"' . $wpc_ins0, $m[0], 1);
                },
                $html, 20);
            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public static function wpc_trim_preset_vars_page($html)
    {
        try {
            if (!is_string($html) || $html === '' || strpos($html, '--wp--preset--') === false
                || !class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'wpc_trim_preset_vars')) {
                return $html;
            }
            $out = preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function ($m) use ($html) {
                if (strpos($m[2], '--wp--preset--') === false) {
                    return $m[0];
                }
                $trimmed = wps_rewriteLogic::wpc_trim_preset_vars($m[2], $html);
                return (is_string($trimmed) && $trimmed !== '') ? $m[1] . $trimmed . $m[3] : $m[0];
            }, $html);
            return is_string($out) ? $out : $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public function cdnRewriter_wrapped($html)
    {
        $wpc_span530 = class_exists('Wpc_Prof_Span530')
            ? new Wpc_Prof_Span530('OBCHAIN:cdnRewriter_wrapped') : null;
        
        
        
        
        
        
        
        
        
        
        
        if (function_exists('wpc_is_low_value_page') && function_exists('did_action')
            && did_action('template_redirect') && wpc_is_low_value_page()) {
            $GLOBALS['wpc_shed520'] = 1;
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('rewrite-skip-lowvalue', '', '', ['fn' => __FUNCTION__]);
            }
            return $html;
        }
        if ((function_exists('wpc_render_slot_acquire') && !wpc_render_slot_acquire())
            || (function_exists('wpc_memory_pressure') && wpc_memory_pressure())
            || (function_exists('wpc_under_pressure') && wpc_under_pressure())
            || (function_exists('wpc_safe_mode') && wpc_safe_mode())) {
            $GLOBALS['wpc_shed520'] = 1;
            if (function_exists('wpc_prof_mark')) { wpc_prof_mark('shed:' . __FUNCTION__, microtime(true)); }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('rewrite-shed', '', '', ['fn' => __FUNCTION__, 'bytes' => strlen((string) $html)]);
            }
            return $html;
        }
        
        if (function_exists('wpc_inv2_stash')) {
            wpc_inv2_stash($html);
        }
        $wpc_in71 = $html;
        try {
            $html = self::wpc_collapse_double_ext(self::wpc_asset_naturalize(self::wpc_eager_nextgen752(self::wpc_raster_zoneify(self::wpc_svg_zoneify(self::wpc_raster_naturalize(self::wpc_svg_naturalize($this->cdnRewriter($html))))))));
            $wpc_fb735b = self::add_asset_failover($html);
            if (is_string($wpc_fb735b) && $wpc_fb735b !== '') { $html = $wpc_fb735b; }
            $html = self::wpc_lazy_srcset_buffer_pass($html);
            $html = self::wpc_srcset_space_encode_pass($html);
            $html = self::wpc_lcp_hint_pass($html);
            $html = self::wpc_afold_sizes_pass($html);
            $html = self::wpc_quiet_wire_pass($html);
            $html = self::wpc_below_fold_cv_tag($html);
            $html = self::wpc_picture_sizes_parity_pass($html);
            $html = self::wpc_picture_fidelity_pass($html);
            $html = self::wpc_lcp_img_preload_pass($html);
            $html = self::wpc_embed_facade_pass($html);
            $html = self::wpc_rum_beacon_pass($html);
            $html = self::wpc_font_preconnect_pass($html);
            $html = self::wpc_zone_font_preconnect_pass($html);
            $html = self::wpc_fonts_drop_remote_dup($html);
            $html = self::wpc_zone_preconnect_prune_pass($html);
            $html = self::wpc_svg_dims_pass($html);
            $html = self::wpc_img_aspect_pass($html);
            $html = self::wpc_lcp_eager_invariant_pass($html);
            $html = self::wpc_hoist_viewport_pass($html);
            $html = self::wpc_prune_idle_preconnects_pass($html);
            $html = self::wpc_video_delay_pass($html);
            $html = self::wpc_trim_preset_vars_page($html);
            if (class_exists('wps_cacheHtml')) {
                $html = wps_cacheHtml::critlessUndefer($html);
                $html = wps_cacheHtml::varsGuard($html);
                $html = wps_cacheHtml::bricksAtfUnveil($html);
                $html = wps_cacheHtml::critBgPreload($html);
            }
            $html = self::wpc_slider_settle_pass($html);
            $html = self::wpc_preload_img_coherence796($html);
            $html = self::wpc_freshness_marker($html);
        } catch (\Throwable $wpc_t71) {
            return self::wpc_never_blank($wpc_in71, '', $wpc_t71);
        }
        return self::wpc_never_blank($wpc_in71, $html);
    }


    private static function wpc_never_blank($in, $out, $e = null)
    {
        $inFull = is_string($in) && strlen($in) >= 255 && stripos($in, '</body>') !== false;
        $outThin = !is_string($out) || strlen($out) < 255 || stripos($out, '</body>') === false;
        if ($e !== null) {
            
            
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('never-blank-restore', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', [
                    'in' => is_string($in) ? strlen($in) : -1,
                    'out' => 'exception',
                    'err' => substr($e->getMessage(), 0, 120),
                ]);
            }
            return $in;
        }
        if ($inFull && $outThin) {
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('never-blank-restore', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', [
                    'in' => strlen($in),
                    'out' => is_string($out) ? strlen($out) : -1,
                    'err' => $e ? substr($e->getMessage(), 0, 120) : '',
                ]);
            }
            return $in;
        }
        return $out;
    }


    
    
    
    public static function wpc_srcset_space_encode_pass($html)
    {
        if (!is_string($html) || stripos($html, 'srcset') === false) {
            return $html;
        }
        $out = preg_replace_callback('/\b(srcset|data-srcset)\s*=\s*(["\'])(.*?)\2/is', function ($m) {
            if (strpos($m[3], ' ') === false) {
                return $m[0];
            }
            $v = preg_replace('/,\s+/', ', ', $m[3]);
            $v = preg_replace('/(?<!,) (?!\d+(?:\.\d+)?[wx]\s*(?:,|$))/', '%20', $v);
            return is_string($v) ? $m[1] . '=' . $m[2] . $v . $m[2] : $m[0];
        }, $html);
        return is_string($out) ? $out : $html;
    }

    public static function wpc_lazy_srcset_buffer_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'data-srcset') === false) return $html;
        if (!class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'activate_lazy_srcset_auto')) return $html;
        $set = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        if (!is_array($set) || empty($set['lazy-auto-sizes'])) return $html;

        
        $wpc_out50 = preg_replace_callback('/<img\b[^>]*?>/is', function ($m) {
            $t = wps_rewriteLogic::activate_lazy_srcset_auto($m[0]);
            if (method_exists('wps_rewriteLogic', 'auto_sizes_for_lazy_img')) {
                $t = wps_rewriteLogic::auto_sizes_for_lazy_img($t);
            }
            return $t;
        }, $html);
        return is_string($wpc_out50) ? $wpc_out50 : $html;
    }


    public static function wpc_freshness_marker($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '</body>') === false) return $html;


        
        
        if (function_exists('apply_filters') && !apply_filters('wpc_freshness_marker', true)) return $html;


        if (isset($_GET['wpc_census_dbg']) && class_exists('wps_rewriteLogic')
            && !empty(wps_rewriteLogic::$wpc_census_dbg)) {
            $html = wpc_body_inject809($html, '<!--WPC-CDBG ' . wp_json_encode(wps_rewriteLogic::$wpc_census_dbg) . '-->');
        }
        $ver   = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?';
        $now   = time();
        $fresh = !empty($_GET['wpc_fresh']) ? ' FRESH' : '';


        $set   = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        $la    = (is_array($set) && !empty($set['lazy-auto-sizes'])) ? '1' : '0';
        $marker = "\n<!-- wpc " . $ver . ' r:' . $now . ' (' . gmdate('Y-m-d H:i:s', $now) . ' UTC) la:' . $la . $fresh . " -->\n";
        return wpc_body_inject809($html, $marker);
    }


    





    public static function wpc_quiet_wire_pass($html)
    {
        if (!is_string($html) || $html === '') return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_quiet_wire', true)) return $html;
        $wpc_qwd387 = function_exists('apply_filters') ? (bool) apply_filters('wpc_quiet_wire_defer_imgs', true) : true;
        $wpc_qwn387 = 0;
        $wpc_qweager387 = false; 
                                 
                                 
        
        
        
        $wpc_qwseen392 = [];
        $wpc_qwstem392 = function ($t) {
            if (!preg_match('/\ssrc=(["\'])(.*?)\1/i', $t, $sm)) return '';
            $u = strtolower((string) strtok($sm[2], '?'));
            return preg_replace('/-\d+x\d+(\.[a-z0-9]{2,5})$/', '$1', $u);
        };
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($wpc_qwd387, &$wpc_qwn387, &$wpc_qweager387, &$wpc_qwseen392, $wpc_qwstem392) {
            $t = $m[0];
            if (stripos($t, 'loading="lazy"') === false && stripos($t, "loading='lazy'") === false) {
                $wpc_qweager387 = true;
                $wpc_qws392 = $wpc_qwstem392($t);
                if ($wpc_qws392 !== '') $wpc_qwseen392[$wpc_qws392] = 1;
                return $t;
            }
            if (stripos($t, 'fetchpriority') !== false) return $t;
            $wpc_qws392 = $wpc_qwstem392($t);
            if ($wpc_qws392 !== '' && isset($wpc_qwseen392[$wpc_qws392])) return $t;
            $t = preg_replace('/<img\b/i', '<img fetchpriority="low"', $t, 1);
            if ($wpc_qwd387 && $wpc_qweager387 && stripos($t, 'data-wpc-qw') === false && preg_match('/\ssrc=/i', $t)) {
                $wpc_qworig389 = $m[0];
                $t = preg_replace('/\ssrc=(["\'])/i', ' data-wpc-qw-src=$1', $t, 1);
                $t = preg_replace('/\ssrcset=(["\'])/i', ' data-wpc-qw-srcset=$1', $t, 1);
                $t = preg_replace('/\ssizes=(["\'])/i', ' data-wpc-qw-sizes=$1', $t, 1);
                
                
                
                
                
                
                
                
                
                $wpc_qwph387 = '';
                if (preg_match('/\swidth=(["\'])(\d{1,5})\1/i', $t, $wpc_qww387)
                    && preg_match('/\sheight=(["\'])(\d{1,5})\1/i', $t, $wpc_qwh387)
                    && (int) $wpc_qww387[2] > 0 && (int) $wpc_qwh387[2] > 0) {
                    $wpc_qwph387 = 'data:image/svg+xml;base64,' . base64_encode(
                        '<svg xmlns="http://www.w3.org/2000/svg" width="' . (int) $wpc_qww387[2]
                        . '" height="' . (int) $wpc_qwh387[2] . '"/>'
                    );
                }
                if ($wpc_qwph387 === '') {
                    $wpc_qwph387 = (!empty(self::$svg_placeholder) && is_string(self::$svg_placeholder))
                        ? self::$svg_placeholder
                        : 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciLz4=';
                }
                if (!preg_match('/\ssrc=/i', $t)) {
                    $t = preg_replace('/<img\b/i', '<img src="' . $wpc_qwph387 . '"', $t, 1);
                }
                
                
                
                
                
                
                
                
                
                if (apply_filters('wpc_qw_pin_aspect_ratio', true)
                    && stripos($t, 'aspect-ratio') === false
                    && preg_match('/\swidth=(["\'])(\d{1,5})\1/i', $t, $wpc_arw481)
                    && preg_match('/\sheight=(["\'])(\d{1,5})\1/i', $t, $wpc_arh481)
                    && (int) $wpc_arw481[2] > 0 && (int) $wpc_arh481[2] > 0) {
                    $wpc_ar481 = 'aspect-ratio:' . (int) $wpc_arw481[2] . '/' . (int) $wpc_arh481[2];
                    if (preg_match('/\sstyle\s*=\s*"/i', $t)) {
                        
                        $t = (string) preg_replace('/\sstyle\s*=\s*"/i', ' style="' . $wpc_ar481 . ';', $t, 1);
                    } else {
                        $t = (string) preg_replace('/<img\b/i', '<img style="' . $wpc_ar481 . '"', $t, 1);
                    }
                }
                $wpc_qwn387++;
                
                $t .= '<noscript>' . preg_replace('/\sonerror=(["\']).*?\1/i', '', $wpc_qworig389) . '</noscript>';
            }
            return $t;
        }, $html);
        if (is_string($out)) $html = $out;
        
        
        $wpc_qwseen389 = [];
        $wpc_qwlive393 = null;
        $out = preg_replace_callback('/<link\b[^>]*rel=(["\'])preconnect\1[^>]*>/i', function ($m) use (&$wpc_qwseen389, &$wpc_qwlive393, $html) {
            $h = preg_match('/href=(["\'])(.*?)\1/i', $m[0], $hm) ? strtolower($hm[2]) : '';
            if ($h === '') return $m[0];
            $k = $h . '|' . (stripos($m[0], 'crossorigin') !== false ? 'c' : '');
            if (isset($wpc_qwseen389[$k])) return '';
            $wpc_qwseen389[$k] = 1;
            
            
            
            
            $wpc_qwhost393 = strtolower((string) parse_url($h, PHP_URL_HOST));
            if ($wpc_qwhost393 !== ''
                && (!function_exists('apply_filters') || apply_filters('wpc_preconnect_prune', true))
                && strpos($wpc_qwhost393, 'gstatic') === false && strpos($wpc_qwhost393, 'googleapis') === false
                && strpos($wpc_qwhost393, 'typekit') === false && strpos($wpc_qwhost393, 'bunny.net') === false) {
                if ($wpc_qwlive393 === null) {
                    $wpc_qwl393 = preg_replace('/<link\b[^>]*rel=(["\'])preconnect\1[^>]*>/i', '', $html);
                    if (preg_match_all('/\s(?:src|href|srcset|imagesrcset)=(["\'])(.*?)\1/i', (string) $wpc_qwl393, $wpc_qwa393)) {
                        $wpc_qwlive393 = strtolower(implode(' ', $wpc_qwa393[2]));
                    } else {
                        $wpc_qwlive393 = '';
                    }
                }
                if (strpos($wpc_qwlive393, $wpc_qwhost393) === false) return '';
            }
            return $m[0];
        }, $html);
        if (is_string($out)) $html = $out;
        if ($wpc_qwn387 > 0 && stripos($html, '</body>') !== false) {
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            
            $wpc_qwjs387 = '<script id="wpc-qw-restore">(function(){var d=false;'
                . 'function r(){d=true;var i=document.querySelectorAll("img[data-wpc-qw-src]"),n;for(n=0;n<i.length;n++){var e=i[n];'
                . 'if(!e.getAttribute("data-wpc-qw-src"))continue;'
                . 'if(e.getAttribute("data-wpc-qw-sizes"))e.setAttribute("sizes",e.getAttribute("data-wpc-qw-sizes"));'
                . 'if(e.getAttribute("data-wpc-qw-srcset"))e.setAttribute("srcset",e.getAttribute("data-wpc-qw-srcset"));'
                . 'e.setAttribute("src",e.getAttribute("data-wpc-qw-src"));e.removeAttribute("data-wpc-qw-src");}}'
                . 'window.wpcQwRestore=r;'
                . 'var v=["scroll","wheel","touchstart","keydown","pointerdown"],x=function(){'
                . 'for(var j=0;j<v.length;j++)window.removeEventListener(v[j],x,{passive:true});r();};'
                . 'function boot(){'
                . 'if((typeof window.pageYOffset==="number"?window.pageYOffset:(document.documentElement||{}).scrollTop||0)>0){r();return;}'
                . 'for(var j=0;j<v.length;j++)window.addEventListener(v[j],x,{passive:true});'
                . 'try{if(window.IntersectionObserver){var o=new IntersectionObserver(function(en){'
                . 'for(var k=0;k<en.length;k++){if(en[k].isIntersecting){o.disconnect();r();return;}}},{rootMargin:"50px"});'
                . 'var m=document.querySelectorAll("img[data-wpc-qw-src]");for(var q=0;q<m.length;q++)o.observe(m[q]);}}catch(e){}}'
                . 'if(window.requestAnimationFrame){requestAnimationFrame(boot);}else{boot();}'
                . 'document.addEventListener("DOMContentLoaded",function(){if(d)r();});'
                . 'window.addEventListener("pageshow",function(e){if(e&&e.persisted)r();},{once:true});'
                . '})();</script>';
            $html = wpc_body_inject809($html, $wpc_qwjs387);
        }
        
        
        
        
        
        
        
        $wpc_nd788 = apply_filters('wpc_quiet_wire_never_demote',
            ['/jquery.min.js', '/jquery.js', 'jquery-migrate', 'jquery-core', 'jquery-ui']);
        $out = preg_replace_callback('/<script\b[^>]*\bsrc=[^>]*>/i', function ($m) use ($wpc_nd788) {
            $t = $m[0];
            if (stripos($t, 'fetchpriority') !== false || !preg_match('/\bdefer\b/i', $t)
                || stripos($t, 'delay-v3-loader') !== false || stripos($t, 'wpc-yield') !== false
                || stripos($t, 'type=') !== false && !preg_match('/type=["\']text\/javascript["\']/i', $t)) return $t;
            foreach ((array) $wpc_nd788 as $wpc_n788) {
                if ($wpc_n788 !== '' && stripos($t, (string) $wpc_n788) !== false) { return $t; }
            }
            return preg_replace('/<script\b/i', '<script fetchpriority="low"', $t, 1);
        }, $html);
        return is_string($out) ? $out : $html;
    }

    
    
    
    
    
    
    
    public static function wpc_below_fold_cv_tag($html)
    {
        if (!is_string($html) || $html === '') return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_below_fold_cv', true)) return $html;
        if (stripos($html, 'elementor-top-section') === false) return $html;
        $atf = 0;
        if (preg_match_all('/<img\b[^>]*>/i', $html, $im, PREG_OFFSET_CAPTURE)) {
            foreach ($im[0] as $t) {
                if (stripos($t[0], 'loading="lazy"') === false && stripos($t[0], "loading='lazy'") === false) { $atf = $t[1]; break; }
            }
        }
        if (!preg_match_all('/<(?:section|main|footer)\b[^>]*class=(["\'])[^"\']*\belementor-top-section\b[^"\']*\1[^>]*>/i', $html, $mm, PREG_OFFSET_CAPTURE)) return $html;
        $keep = function_exists('apply_filters') ? (int) apply_filters('wpc_below_fold_cv_keep', 3) : 3;
        $idx = 0; $add = array();
        foreach ($mm[0] as $t) {
            $idx++;
            if ($idx <= $keep || $t[1] <= $atf) continue;
            if (stripos($t[0], 'data-wpc-cv') !== false) continue;
            $add[] = array($t[1], strlen($t[0]), preg_replace('/^<(\w+)/', '<$1 data-wpc-cv="1"', $t[0], 1));
        }
        for ($i = count($add) - 1; $i >= 0; $i--) {
            $html = substr($html, 0, $add[$i][0]) . $add[$i][2] . substr($html, $add[$i][0] + $add[$i][1]);
        }
        return $html;
    }

    
    
    
    
    
    
    private static $wpc_afold_szmap = null;
    private static function wpc_afold_sizes_map()
    {
        if (self::$wpc_afold_szmap !== null) return self::$wpc_afold_szmap;
        self::$wpc_afold_szmap = array();
        if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) return self::$wpc_afold_szmap;
        $url = (function_exists('is_ssl') && is_ssl() ? 'https://' : 'http://')
            . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
            . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?');
        $key = (new wps_ic_url_key())->setup($url);
        if ($key === '') return self::$wpc_afold_szmap;
        $f = rtrim(WPS_IC_CRITICAL, '/') . '/' . $key . '/lcp.json';
        if (!@is_readable($f)) return self::$wpc_afold_szmap;
        $j = json_decode((string) @file_get_contents($f), true);
        $atf = (is_array($j) && isset($j['atf_images']) && is_array($j['atf_images'])) ? $j['atf_images'] : null;
        if ($atf === null) return self::$wpc_afold_szmap;
        $mob = (isset($atf['mobile']) && is_array($atf['mobile'])) ? $atf['mobile'] : array();
        $des = (isset($atf['desktop']) && is_array($atf['desktop'])) ? $atf['desktop'] : array();
        if (empty($mob) && empty($des)) { $mob = $atf; $des = $atf; }
        $map = array();
        foreach (array('m' => $mob, 'd' => $des) as $slot => $list) {
            foreach ((array) $list as $im) {
                if (!is_array($im) || empty($im['stem']) || empty($im['css_w'])) continue;
                $st = strtolower((string) $im['stem']);
                if ($st === '') continue;
                if (!isset($map[$st])) $map[$st] = array('m' => 0, 'd' => 0);
                if ($map[$st][$slot] === 0) $map[$st][$slot] = (int) round((float) $im['css_w']);
            }
        }
        self::$wpc_afold_szmap = $map;
        return self::$wpc_afold_szmap;
    }

    public static function wpc_afold_sizes_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<img') === false) return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_afold_sizes', true)) return $html;
        $map = self::wpc_afold_sizes_map();
        if (empty($map)) return $html;
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($map) {
            $tag = $m[0];
            $srcAttr = preg_match('/\sdata-wpc-qw-src=(["\'])(.*?)\1/i', $tag, $qm) ? $qm[2]
                     : (preg_match('/\ssrc=(["\'])(.*?)\1/i', $tag, $sm) ? $sm[2] : '');
            if ($srcAttr === '') return $tag;
            $stem = strtolower(preg_replace('/(-\d+x\d+|-scaled)?\.[^.]+$/', '', basename(strtok($srcAttr, '?#'))));
            if ($stem === '' || !isset($map[$stem])) return $tag;
            $mW = (int) $map[$stem]['m']; $dW = (int) $map[$stem]['d'];
            if ($mW > 0 && $dW > 0) { $nv = '(max-width: 768px) ' . $mW . 'px, ' . $dW . 'px'; }
            elseif ($dW > 0) { $nv = (string) $dW . 'px'; }
            elseif ($mW > 0) { $nv = (string) $mW . 'px'; }
            else { return $tag; }
            
            
            
            if (preg_match('/\ssizes=(["\'])([^"\']*)\1/i', $tag, $cur)) {
                if ($dW > 0 && preg_match_all('/(\d+)px/', $cur[2], $cw) && !empty($cw[1])) {
                    $curDesktop = (int) $cw[1][count($cw[1]) - 1];
                    if ($curDesktop > 0 && $curDesktop <= $dW) return $tag; 
                }
                $tag = preg_replace('/\ssizes=(["\'])[^"\']*\1/i', ' sizes="' . $nv . '"', $tag, 1);
            } else {
                $tag = preg_replace('/<img\b/i', '<img sizes="' . $nv . '"', $tag, 1);
            }
            if (preg_match('/\sdata-wpc-qw-sizes=(["\'])/i', $tag)) {
                $tag = preg_replace('/\sdata-wpc-qw-sizes=(["\'])[^"\']*\1/i', ' data-wpc-qw-sizes="' . $nv . '"', $tag, 1);
            }
            return $tag;
        }, $html);
        return is_string($out) ? $out : $html;
    }

    public static function wpc_lcp_hint_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<img') === false) return $html;
        $hint = function_exists('apply_filters')
            ? apply_filters('wpc_lcp_hint', (function_exists('get_option') ? get_option('wpc_lcp_hint') : null))
            : (function_exists('get_option') ? get_option('wpc_lcp_hint') : null);
        if (empty($hint) || !is_array($hint)) return $html;


        
        
        $is_m = !empty($_GET['simulate_mobile']) || (function_exists('wp_is_mobile') && wp_is_mobile());
        if (isset($hint['stem'])) {
            $entry = $hint;
            
            
            if ($is_m && isset($entry['width']) && (int) $entry['width'] > 0) {
                $entry['width'] = min((int) $entry['width'], (int) apply_filters('wpc_lcp_hint_mobile_cap', 412));
            }
        } else {
            $vp = $is_m ? 'mobile' : 'desktop';
            $entry = (isset($hint[$vp]) && is_array($hint[$vp])) ? $hint[$vp]
                   : ((isset($hint['desktop']) && is_array($hint['desktop'])) ? $hint['desktop']
                   : ((isset($hint['mobile']) && is_array($hint['mobile'])) ? $hint['mobile'] : null));
            
            
            if ($is_m && is_array($entry) && isset($entry['width']) && (int) $entry['width'] > 0) {
                $entry['width'] = min((int) $entry['width'], (int) apply_filters('wpc_lcp_hint_mobile_cap', 412));
            }
        }
        if (!is_array($entry) || empty($entry['stem'])) return $html;
        $stem  = (string) $entry['stem'];
        $width = isset($entry['width']) ? (int) $entry['width'] : 0;
        if (strlen($stem) < 4) return $html;
        
        
        
        
        $applied = 0;
        $wpc_hmax392 = function_exists('apply_filters') ? (int) apply_filters('wpc_lcp_hint_max_copies', 4) : 4;
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($stem, $width, &$applied, $wpc_hmax392) {
            $tag = $m[0];
            if ($applied >= $wpc_hmax392 || stripos($tag, $stem) === false) return $tag;
            $applied++;
            if (stripos($tag, 'fetchpriority') === false) {
                $tag = preg_replace('/<img\b/i', '<img fetchpriority="high"', $tag, 1);
            }
            $tag = preg_replace('/\sloading=(["\'])lazy\1/i', ' loading="eager"', $tag, 1);
            if (stripos($tag, 'loading=') === false) {
                $tag = preg_replace('/<img\b/i', '<img loading="eager"', $tag, 1);
            }
            if ($width > 0) {
                if (preg_match('/\ssizes=/i', $tag)) {
                    $tag = preg_replace('/\ssizes=(["\'])[^"\']*\1/i', ' sizes="' . $width . 'px"', $tag, 1);
                } else {
                    $tag = preg_replace('/<img\b/i', '<img sizes="' . $width . 'px"', $tag, 1);
                }
            }
            return $tag;
        }, $html);
        return ($out === null) ? $html : $out;
    }


    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    public static function wpc_picture_fidelity_pass($html)
    {
        try {
            if (!is_string($html) || stripos($html, 'wpc-picture') === false
                || !function_exists('wpc_pic_scan_page631')
                || (function_exists('apply_filters') && !apply_filters('wpc_picture_fidelity', true))) {
                return $html;
            }
            $wpc_scan631 = wpc_pic_scan_page631($html);
            
            
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient')
                && !get_transient('wpc_picfid_log633')) {
                if (function_exists('set_transient')) {
                    set_transient('wpc_picfid_log633', 1, 600);
                }
                wpc_cache_first_log('pic-fidelity-scan', '', isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '', [
                    'cls' => count($wpc_scan631['cls']),
                    'tags' => count($wpc_scan631['tags']),
                    'capped' => isset($wpc_scan631['capped']) ? (int) $wpc_scan631['capped'] : 0,
                    'ir' => in_array('img-repeat', $wpc_scan631['cls'], true) ? 1 : 0,
                    'len' => strlen((string) $html),
                    'styles' => substr_count((string) $html, '<style'),
                ]);
            }

            if (!empty($wpc_scan631['cls'])) {
                
                
                
                $wpc_set631 = array_flip($wpc_scan631['cls']);
                $wpc_pos631 = 0;
                $wpc_iter631 = 0;
                while (($wpc_ps631 = strpos($html, '<picture class="wpc-picture', $wpc_pos631)) !== false && $wpc_iter631++ < 400) {
                    $wpc_pos631 = $wpc_ps631 + 16;
                    $wpc_pe631 = strpos($html, '</picture>', $wpc_ps631);
                    if ($wpc_pe631 === false || $wpc_pe631 - $wpc_ps631 > 12000) {
                        continue;
                    }
                    $wpc_blk631 = substr($html, $wpc_ps631, $wpc_pe631 - $wpc_ps631);
                    if (strpos(substr($wpc_blk631, 0, 200), 'data-wpc-mir') !== false) {
                        continue;
                    }
                    if (!preg_match('/^<picture class="(wpc-picture[^"]*)">/', $wpc_blk631, $wm)
                        || !preg_match('/<img\b[^>]*?class=["\']([^"\']+)["\']/i', $wpc_blk631, $im)) {
                        continue;
                    }
                    $have = preg_split('/\s+/', $wm[1], -1, PREG_SPLIT_NO_EMPTY);
                    $add = [];
                    foreach (preg_split('/\s+/', $im[1], -1, PREG_SPLIT_NO_EMPTY) as $c) {
                        if (isset($wpc_set631[$c]) && !in_array($c, $have, true) && !in_array($c, $add, true)) {
                            $add[] = $c;
                        }
                    }
                    if (!$add) {
                        continue;
                    }
                    
                    
                    
                    $wpc_new631 = '<picture class="' . $wm[1] . ' ' . implode(' ', $add) . '" data-wpc-mir="1">';
                    $html = substr_replace($html, $wpc_new631, $wpc_ps631, strlen($wm[0]));
                    $wpc_pos631 = $wpc_ps631 + strlen($wpc_new631);
                }
            }

            $wpc_inj631 = '';
            if (strpos($html, 'wpc-picture-contract') === false && strpos($html, 'data-wpc-mir') !== false) {
                
                
                
                
                
                $wpc_inj631 .= '<style id="wpc-picture-contract">:where(picture.wpc-picture[data-wpc-mir]){display:inline-block}picture.wpc-picture[data-wpc-mir]{border:0;padding:0;background:none;box-shadow:none}picture.wpc-picture[data-wpc-mir]>img{opacity:1;transform:none;filter:none}</style>';
            }
            if (!empty($wpc_scan631['tags']) && strpos($html, 'wpc-pic-guard') === false) {
                $wpc_list631 = [];
                foreach ($wpc_scan631['tags'] as $t) {
                    $wpc_list631[] = [(string) $t['s'], (string) $t['m']];
                }
                $wpc_json631 = json_encode($wpc_list631, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                if (is_string($wpc_json631) && strlen($wpc_json631) <= 6144) {
                    $wpc_inj631 .= '<script id="wpc-pic-guard">/*wpc-arm-sentinel*/(function(){var L=' . $wpc_json631 . ';'
                        . 'function T(p){try{if(p.__wpcPG)return;for(var i=0;i<L.length;i++){var m=L[i][1];'
                        . 'if(m){try{if(window.matchMedia&&!matchMedia(m).matches)continue}catch(e){}}'
                        . 'var h=false;try{h=p.matches(L[i][0])}catch(e){}'
                        . 'if(h){p.__wpcPG=1;var im=p.querySelector("img");if(!im||!p.parentNode)return;'
                        . 'try{if(im.complete&&im.currentSrc&&im.currentSrc.indexOf("data:")!==0){im.src=im.currentSrc}}catch(e){}'
                        . 'try{p.parentNode.insertBefore(im,p);p.parentNode.removeChild(p)}catch(e){}return}}}catch(e){}}'
                        . 'try{var d=document,q=d.getElementsByTagName("picture"),i;for(i=q.length-1;i>=0;i--)T(q[i]);'
                        . 'var mo=new MutationObserver(function(ms){for(var a=0;a<ms.length;a++){var ns=ms[a].addedNodes;'
                        . 'for(var b=0;b<ns.length;b++){var n=ns[b];if(!n.tagName)continue;'
                        . 'if(n.tagName==="PICTURE")T(n);else if(n.querySelectorAll){var ps=n.querySelectorAll("picture");'
                        . 'for(var c=0;c<ps.length;c++)T(ps[c])}}}});'
                        . 'mo.observe(d.documentElement,{childList:true,subtree:true});'
                        . 'addEventListener("load",function(){setTimeout(function(){'
                        . 'try{var z=d.getElementsByTagName("picture");for(var i=z.length-1;i>=0;i--)T(z[i])}catch(e){}'
                        . 'try{mo.disconnect()}catch(e){}},2500)})}catch(e){}})();</script>';
                }
            }
            if ($wpc_inj631 !== '' && ($wpc_hp631 = stripos($html, '</head>')) !== false) {
                $html = substr_replace($html, $wpc_inj631, $wpc_hp631, 0);
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public static function wpc_picture_sizes_parity_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<picture') === false) {
            return $html;
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_picture_sizes_parity', true)) {
            return $html;
        }
        $out = preg_replace_callback('#<picture\b[^>]*>.*?</picture>#is', function ($m) {
            $pic = $m[0];
            if (!preg_match('/<img\b[^>]*\bsizes\s*=\s*(["\'])(.*?)\1/is', $pic, $im)) {
                return $pic;
            }
            $sz = trim($im[2]);
            if ($sz === '' || strpos($sz, '"') !== false) {
                return $pic;
            }
            $fixed = preg_replace_callback('/<source\b[^>]*>/i', function ($sm) use ($sz) {
                if (stripos($sm[0], 'srcset') === false) {
                    return $sm[0];
                }
                if (preg_match('/\bsizes\s*=\s*(["\'])(.*?)\1/is', $sm[0], $cur) && trim($cur[2]) === $sz) {
                    return $sm[0];
                }
                if (preg_match('/\bsizes\s*=\s*(["\']).*?\1/is', $sm[0])) {
                    return preg_replace('/\bsizes\s*=\s*(["\']).*?\1/is', 'sizes="' . $sz . '"', $sm[0], 1);
                }
                return preg_replace('/<source\b/i', '<source sizes="' . $sz . '"', $sm[0], 1);
            }, $pic);
            return is_string($fixed) ? $fixed : $pic;
        }, $html);
        return is_string($out) ? $out : $html;
    }


    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    
    public static function wpc_prune_idle_preconnects_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'preconnect') === false
            && stripos($html, 'dns-prefetch') === false) {
            return $html;
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_prune_idle_preconnects', true)) {
            return $html;
        }
        
        if (stripos($html, 'text/placeholder') === false && stripos($html, 'wpc-delay-script') === false) {
            return $html;
        }
        if (!preg_match_all('/<link\b[^>]*\brel\s*=\s*["\'](?:preconnect|dns-prefetch)["\'][^>]*>/i', $html, $wpc_pcm698)) {
            return $html;
        }
        
        
        $wpc_probe698 = preg_replace([
            '/<script\b[^>]*(?:text\/placeholder|wpc-delay-script)[^>]*>.*?<\/script>/is',
            '/<noscript\b[^>]*>.*?<\/noscript>/is',
            '/<link\b[^>]*\brel\s*=\s*["\'](?:preconnect|dns-prefetch)["\'][^>]*>/i',
        ], '', $html);
        if (!is_string($wpc_probe698) || $wpc_probe698 === '') {
            return $html;
        }
        $wpc_own698 = function_exists('home_url') ? strtolower((string) parse_url(home_url('/'), PHP_URL_HOST)) : '';
        $out = $html;
        foreach (array_unique($wpc_pcm698[0]) as $wpc_tag698) {
            if (!preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $wpc_tag698, $wpc_hm698)) {
                continue;
            }
            $wpc_h698 = strtolower((string) parse_url(trim($wpc_hm698[1]), PHP_URL_HOST));
            if ($wpc_h698 === '' || $wpc_h698 === $wpc_own698) {
                continue;
            }
            if (stripos($wpc_probe698, $wpc_h698) !== false) {
                continue;
            }
            $out = str_replace($wpc_tag698, '', $out);
        }
        return $out;
    }

    public static function wpc_hoist_viewport_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'viewport') === false) {
            return $html;
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_hoist_viewport', true)) {
            return $html;
        }
        if (!preg_match('/<meta\b[^>]*\bname\s*=\s*["\']?viewport["\']?[^>]*>/i', $html, $wpc_vm444, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $wpc_vt444 = (string) $wpc_vm444[0][0];
        $wpc_va444 = (int) $wpc_vm444[0][1];
        
        if (!preg_match('/<meta\b[^>]*\bcharset\b[^>]*>/i', $html, $wpc_am444, PREG_OFFSET_CAPTURE)
            && !preg_match('/<head\b[^>]*>/i', $html, $wpc_am444, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $wpc_ip444 = (int) $wpc_am444[0][1] + strlen((string) $wpc_am444[0][0]);
        
        if ($wpc_va444 <= $wpc_ip444 + 200) {
            return $html;
        }
        $wpc_cut444 = substr($html, 0, $wpc_va444) . substr($html, $wpc_va444 + strlen($wpc_vt444));
        return substr($wpc_cut444, 0, $wpc_ip444) . "\n" . $wpc_vt444 . substr($wpc_cut444, $wpc_ip444);
    }

    public static function wpc_lcp_eager_invariant_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'fetchpriority') === false) {
            return $html;
        }
        
        
        
        
        if (function_exists('apply_filters') && !apply_filters('wpc_lcp_eager_invariant', false)) {
            return $html;
        }
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($wpc_m438) {
            $wpc_t438 = $wpc_m438[0];
            
            
            
            if (!preg_match('/(?<![-\w])fetchpriority\s*=\s*(["\'])\s*high\s*\1/i', $wpc_t438)) { return $wpc_t438; }
            if (!preg_match('/(?<![-\w])loading\s*=\s*(["\'])\s*lazy\s*\1/i', $wpc_t438)) { return $wpc_t438; }
            if (preg_match('/(?<![-\w])sizes\s*=\s*(["\'])[^"\']*\bauto\b[^"\']*\1/i', $wpc_t438)) { return $wpc_t438; }
            $wpc_r438 = preg_replace('/(?<![-\w])\s*loading\s*=\s*(["\'])\s*lazy\s*\1/i', ' loading="eager"', $wpc_t438, 1);
            return is_string($wpc_r438) ? $wpc_r438 : $wpc_t438;
        }, $html);
        return is_string($out) ? $out : $html;
    }

    public static function wpc_lcp_img_preload_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'fetchpriority') === false) return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_lcp_img_preload', true)) return $html;
        
        


        $wpc_own_preload107 = '';
        if (preg_match('/<link\b[^>]*\bid=["\']wpc-lcp-img-preload(?:-\d+)?["\'][^>]*>\s*/i', $html, $wpc_opm107)) {
            $wpc_own_preload107 = $wpc_opm107[0];
        } else {


            $wpc_rest125 = preg_replace('/<link\b[^>]*\bid=["\']wpc-atf-bg-preload["\'][^>]*>\s*/i', '', $html);
            if (!is_string($wpc_rest125)) { $wpc_rest125 = $html; }
            if (stripos($wpc_rest125, 'wpc-lcp-bg-preload') !== false
                || preg_match('/<link\b[^>]*\brel\s*=\s*["\']?preload["\']?[^>]*\bas\s*=\s*["\']?image\b/i', $wpc_rest125)
                || preg_match('/<link\b[^>]*\bas\s*=\s*["\']?image["\']?[^>]*\brel\s*=\s*["\']?preload\b/i', $wpc_rest125)) {
                return $html;
            }
        }


        $wpc_lcp_stem96 = '';
        $wpc_lcp_url96  = '';
        $wpc_lcp_type96 = '';
        try {
            if (class_exists('wps_criticalCss') && class_exists('wps_rewriteLogic')) {


                {
                    $wpc_f96 = method_exists('wps_rewriteLogic', 'wpc_lcp_json_file')
                        ? wps_rewriteLogic::wpc_lcp_json_file() : '';
                    if ($wpc_f96 !== '' && @is_readable($wpc_f96)) {
                        $wpc_j96 = json_decode((string) @file_get_contents($wpc_f96), true);


                        
                        
                        
                        
                        
                        
                        
                        $wpc_cands814 = function ($d) use ($wpc_j96) {
                            $c = [];
                            if (isset($wpc_j96['lcp'][$d]) && is_array($wpc_j96['lcp'][$d])) { $c[] = $wpc_j96['lcp'][$d]; }
                            if (isset($wpc_j96['lcp_element'][$d]) && is_array($wpc_j96['lcp_element'][$d])) { $c[] = $wpc_j96['lcp_element'][$d]; }
                            if (isset($wpc_j96['lcp_element']) && is_array($wpc_j96['lcp_element'])
                                && (isset($wpc_j96['lcp_element']['stem']) || isset($wpc_j96['lcp_element']['url']))) {
                                $c[] = (array) $wpc_j96['lcp_element'];
                            }
                            return $c;
                        };
                        $wpc_pick96 = function ($d) use ($wpc_cands814) {
                            $e = [];
                            foreach ($wpc_cands814($d) as $wpc_c814) {
                                foreach (['stem', 'url'] as $wpc_k814) {
                                    if (!isset($e[$wpc_k814]) && isset($wpc_c814[$wpc_k814]) && is_string($wpc_c814[$wpc_k814])
                                        && trim($wpc_c814[$wpc_k814]) !== '') {
                                        $e[$wpc_k814] = $wpc_c814[$wpc_k814];
                                    }
                                }
                            }
                            $s = (isset($e['stem']) && is_string($e['stem'])) ? trim($e['stem']) : '';
                            if ($s === '' && isset($e['url']) && is_string($e['url'])) {
                                $s = strtolower(basename((string) preg_replace('/[?#].*$/', '', $e['url'])));
                                $s = (string) preg_replace('/\.(?:jpe?g|png|webp|avif|gif|svg)$/i', '', $s);
                                $s = (string) preg_replace('/(?:-\d+x\d+)?$/', '', (string) preg_replace('/-scaled$/', '', $s), 1);
                            }
                            return (strlen($s) >= 3 && preg_match('/^[A-Za-z0-9._@-]+$/', $s)) ? strtolower($s) : '';
                        };


                        $wpc_picke96 = function ($d) use ($wpc_cands814) {
                            $t = ''; $u = '';
                            foreach ($wpc_cands814($d) as $wpc_c814) {
                                if ($t === '' && isset($wpc_c814['type']) && is_string($wpc_c814['type'])) {
                                    $t = strtolower(trim($wpc_c814['type']));
                                }
                                if ($u === '' && isset($wpc_c814['url']) && is_string($wpc_c814['url'])) {
                                    $u = trim($wpc_c814['url']);
                                }
                            }
                            return ['t' => $t, 'u' => $u];
                        };
                        $wpc_lm96 = $wpc_pick96('mobile');
                        $wpc_ld96 = $wpc_pick96('desktop');
                        if (wps_rewriteLogic::wpc_combined_crit_on()) {


                            if ($wpc_lm96 !== '' && $wpc_ld96 !== '') {
                                $wpc_lcp_stem96 = $wpc_lm96;
                            } else {
                                $wpc_lcp_stem96 = ($wpc_lm96 !== '') ? $wpc_lm96 : $wpc_ld96;
                            }
                        } else {
                            $wpc_lcp_stem96 = !empty(wps_rewriteLogic::$isMobile) ? $wpc_lm96 : $wpc_ld96;
                        }
                        $wpc_win96 = ($wpc_lcp_stem96 !== '' && $wpc_lcp_stem96 === $wpc_ld96 && $wpc_lcp_stem96 !== $wpc_lm96) ? 'desktop' : 'mobile';
                        $wpc_ent96 = $wpc_picke96($wpc_win96);
                        if ($wpc_ent96['u'] === '') { $wpc_ent96 = $wpc_picke96($wpc_win96 === 'mobile' ? 'desktop' : 'mobile'); }
                        $wpc_lcp_type96 = $wpc_ent96['t'];
                        $wpc_lcp_url96  = $wpc_ent96['u'];
                    }
                }
            }
        } catch (\Throwable $e) {
            $wpc_lcp_stem96 = '';
        }


        if ($wpc_own_preload107 !== '') {
            if ($wpc_lcp_stem96 === '' || stripos($wpc_own_preload107, $wpc_lcp_stem96) !== false) {


                $wpc_lane_dead122 = false;
                if (preg_match('/\bimagesrcset\s*=\s*(["\'])(.*?)\1/is', $wpc_own_preload107, $wpc_ops122)) {
                    $wpc_rest122 = str_replace($wpc_own_preload107, '', $html);
                    $wpc_lane_dead122 = true;
                    foreach (preg_split('/\s*,\s*/', trim(html_entity_decode($wpc_ops122[2], ENT_QUOTES))) as $wpc_cand122) {
                        $wpc_cu122 = trim((string) preg_replace('/\s+\d+(?:w|x)\s*$/', '', trim($wpc_cand122)));
                        if ($wpc_cu122 !== '' && stripos($wpc_rest122, $wpc_cu122) !== false) {
                            $wpc_lane_dead122 = false;
                            break;
                        }
                    }
                }
                if (!$wpc_lane_dead122) {
                    return $html;
                }
            }
            $html = str_replace($wpc_own_preload107, '', $html);

            $wpc_fam133 = preg_replace('/<link\b[^>]*\bid=["\']wpc-lcp-img-preload(?:-\d+)?["\'][^>]*>\s*/i', '', $html);
            if (is_string($wpc_fam133)) { $html = $wpc_fam133; }
        }
        
        
        
        
        
        
        
        $wpc_idsrc813 = ($wpc_lcp_stem96 !== '') ? 'json' : '';
        if ($wpc_lcp_stem96 === '' && apply_filters('wpc_lcp_census_keeper', true)) {
            $wpc_cs783 = self::wpc_lcp_census_stem783();
            if ($wpc_cs783 !== '') { $wpc_lcp_stem96 = $wpc_cs783; $wpc_idsrc813 = 'census'; }
        }
        $tag = ''; $imgPos = -1;
        if ($wpc_lcp_stem96 !== ''
            && preg_match('#<img\b[^>]*(?:src|srcset)="[^"]*/' . preg_quote($wpc_lcp_stem96, '#') . '(?:-scaled)?(?:-\d+x\d+)?\.(?:png|jpe?g|webp|avif|gif)[^"]*"[^>]*>#i', $html, $im96, PREG_OFFSET_CAPTURE)) {
            $tag    = $im96[0][0];
            $imgPos = (int) $im96[0][1];
        }

        
        
        
        
        
        
        
        
        
        
        
        
        if ($tag !== '' && $wpc_lcp_stem96 !== ''
            && stripos($html, 'fetchpriority="high"') !== false
            && apply_filters('wpc_lcp_demote_competitors', true)) {
            $wpc_dem474 = 0;
            $wpc_stemq474 = preg_quote($wpc_lcp_stem96, '#');
            $wpc_html474 = preg_replace_callback('#<img\b[^>]*>#i', function ($m) use ($wpc_stemq474, &$wpc_dem474) {
                $t = $m[0];
                if (stripos($t, 'fetchpriority="high"') === false) {
                    return $t;
                }
                
                
                if (preg_match('#(?:src|srcset|data-wpc-fb)="[^"]*/' . $wpc_stemq474 . '#i', $t)) {
                    return $t;
                }
                $wpc_dem474++;
                return preg_replace('#\s*fetchpriority="high"#i', '', $t);
            }, $html);
            if (is_string($wpc_html474) && $wpc_html474 !== '') {
                $html = $wpc_html474;
                if ($wpc_dem474 > 0) {
                    
                    
                    
                    
                    
                    
                    $wpc_np474 = ($tag !== '') ? stripos($html, $tag) : false;
                    if ($wpc_np474 !== false) { $imgPos = (int) $wpc_np474; }
                    if (function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('lcp-demote-competitors', '', '', [
                            'n'    => $wpc_dem474,
                            'stem' => substr($wpc_lcp_stem96, -28),
                        ]);
                    }
                }
            }
        }


        if ($tag === '' && $wpc_lcp_type96 === 'bg' && $wpc_lcp_url96 !== ''
            && stripos($wpc_lcp_url96, 'data:') !== 0
            && (!class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'wpc_lcp_bg_url_allowed')
                || wps_rewriteLogic::wpc_lcp_bg_url_allowed($wpc_lcp_url96))) {
            $wpc_bglink115 = '<link rel="preload" as="image" fetchpriority="high" id="wpc-lcp-img-preload" href="' . esc_url($wpc_lcp_url96) . '">';
            return self::wpc_inject_after_viewport($html, $wpc_bglink115);
        }
        
        
        
        
        
        
        
        
        
        if ($tag === '') {
            
            
            
            
            
            
            
            
            
            if ($wpc_lcp_type96 === 'text' && apply_filters('wpc_lcp_preload_honour_text', true)) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('lcp-preload-text-lcp', '', '', [
                        'why' => 'artifact says the LCP is text — no image to preload',
                    ]);
                }
                return $html;
            }
            if ($wpc_lcp_stem96 !== '') {
                
                
                
                
                
                if (function_exists('wpc_cache_first_log')) {
                    $wpc_sq441 = preg_quote($wpc_lcp_stem96, '#');
                    
                    
                    
                    
                    
                    
                    
                    
                    
                    wpc_cache_first_log('lcp-preload-no-stem-match', '', '', [
                        'inhtml' => (stripos($html, $wpc_lcp_stem96) !== false) ? 1 : 0,
                        'qw'     => preg_match('#data-wpc-qw-(?:src|srcset)="[^"]*' . $wpc_sq441 . '#i', $html) ? 1 : 0,
                        'idsrc'  => $wpc_idsrc813 !== '' ? $wpc_idsrc813 : '-',
                        't'      => $wpc_lcp_type96 !== '' ? $wpc_lcp_type96 : '-',
                        'u'      => $wpc_lcp_url96 !== '' ? 1 : 0,
                        'allow'  => ($wpc_lcp_url96 !== '' && class_exists('wps_rewriteLogic')
                                     && method_exists('wps_rewriteLogic', 'wpc_lcp_bg_url_allowed'))
                                    ? (wps_rewriteLogic::wpc_lcp_bg_url_allowed($wpc_lcp_url96) ? 1 : 0) : '-',
                        'imgs'   => preg_match_all('#<img\b#i', $html),
                        'uri'    => isset($_SERVER['REQUEST_URI']) ? substr((string) $_SERVER['REQUEST_URI'], 0, 40) : '',
                        'stem'   => substr($wpc_lcp_stem96, -28),
                    ]);
                }
                return $html;
            }
            if (!apply_filters('wpc_lcp_preload_guess', true)) { return $html; }
            $wpc_cand438 = [];
            if (preg_match_all('/<img\b[^>]*\bfetchpriority\s*=\s*["\']?high["\']?[^>]*>/i', $html, $wpc_cm438, PREG_OFFSET_CAPTURE)) {
                $wpc_cand438 = $wpc_cm438[0];
            }
            if (count($wpc_cand438) !== 1) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('lcp-preload-ambiguous', '', '', ['candidates' => count($wpc_cand438)]);
                }
                return $html;
            }
            $tag    = $wpc_cand438[0][0];
            $imgPos = (int) $wpc_cand438[0][1];


            if (preg_match('/\s(?:src|srcset)\s*=\s*["\'][^"\']*\.svg(?:[?#"\']|\s)/i', $tag)) {
                return $html;
            }
        }
        
        if (preg_match('/\bloading\s*=\s*["\']?lazy["\']?/i', $tag)) return $html;


        $before = substr($html, 0, $imgPos);
        $pOpen  = strripos($before, '<picture');
        $pClose = strripos($before, '</picture');
        if ($pOpen !== false && ($pClose === false || $pOpen > $pClose)) {
            if ($wpc_lcp_stem96 === '') return $html;
            $wpc_pic96 = substr($html, $pOpen, $imgPos - $pOpen);


            if (preg_match_all('/<source\b[^>]*\btype=["\']image\/(avif|webp)["\'][^>]*>/i', $wpc_pic96, $wpc_srcall133, PREG_SET_ORDER)) {
                $wpc_links133 = [];
                foreach ($wpc_srcall133 as $wpc_sr133) {
                    $wpc_stag96 = $wpc_sr133[0];
                    $wpc_media133 = (preg_match('/\smedia\s*=\s*(["\'])(.*?)\1/is', $wpc_stag96, $wpc_md133)) ? trim($wpc_md133[2]) : '';


                    $wpc_arm133 = ($wpc_media133 !== '') ? 'm:' . md5($wpc_media133) : 'd';
                    if (isset($wpc_links133[$wpc_arm133]) || count($wpc_links133) >= 3) { continue; }
                    $wpc_ssrcset96 = (preg_match('/\ssrcset\s*=\s*(["\'])(.*?)\1/is', $wpc_stag96, $wpc_ss96)) ? trim($wpc_ss96[2]) : '';
                    $wpc_ssizes96  = (preg_match('/\ssizes\s*=\s*(["\'])(.*?)\1/is', $wpc_stag96, $wpc_sz96m)) ? trim($wpc_sz96m[2]) : '';
                    $wpc_sprobe96  = ($wpc_ssrcset96 !== '') ? (string) preg_split('/[\s,]+/', ltrim($wpc_ssrcset96))[0] : '';
                    if ($wpc_ssrcset96 === '' || $wpc_sprobe96 === '' || stripos($wpc_sprobe96, 'data:') === 0
                        || (method_exists('wps_rewriteLogic', 'wpc_lcp_bg_url_allowed') && !wps_rewriteLogic::wpc_lcp_bg_url_allowed($wpc_sprobe96))) {
                        continue;
                    }
                    $wpc_seen133[$wpc_arm133] = true;


                    $wpc_lmedia133 = $wpc_media133;
                    $wpc_links133[$wpc_arm133] = [
                        'srcset' => $wpc_ssrcset96, 'sizes' => $wpc_ssizes96,
                        'type' => strtolower($wpc_sr133[1]), 'media' => $wpc_lmedia133,
                    ];
                }
                if (!empty($wpc_links133)) {


                    if ($wpc_lcp_type96 !== 'bg') {
                        $wpc_nobg133 = preg_replace('/<link\b[^>]*\bid=["\']wpc-atf-bg-preload["\'][^>]*>\s*/i', '', $html);
                        if (is_string($wpc_nobg133)) { $html = $wpc_nobg133; }
                    }
                    
                    
                    if (isset($wpc_links133['d']) && $wpc_links133['d']['media'] === '' && count($wpc_links133) > 1) {
                        $wpc_links133['d']['media'] = '(min-width: 768px)';
                    }
                    $wpc_out133 = '';
                    $wpc_n133 = 0;
                    foreach ($wpc_links133 as $wpc_l133) {
                        $wpc_n133++;
                        $wpc_out133 .= '<link rel="preload" as="image" fetchpriority="high" id="wpc-lcp-img-preload'
                            . ($wpc_n133 > 1 ? '-' . $wpc_n133 : '') . '"'
                            . ' imagesrcset="' . esc_attr($wpc_l133['srcset']) . '"'
                            . (($wpc_l133['sizes'] !== '') ? ' imagesizes="' . esc_attr($wpc_l133['sizes']) . '"' : '')
                            . (($wpc_l133['media'] !== '') ? ' media="' . esc_attr($wpc_l133['media']) . '"' : '')
                            . ' type="image/' . $wpc_l133['type'] . '">';
                    }

                    return self::wpc_inject_after_viewport($html, $wpc_out133);
                }
            }
            return $html;
        }
        
        $srcset = (preg_match('/\ssrcset\s*=\s*(["\'])(.*?)\1/is', $tag, $sm)) ? trim($sm[2]) : '';
        $sizes  = (preg_match('/\ssizes\s*=\s*(["\'])(.*?)\1/is',  $tag, $zm)) ? trim($zm[2]) : '';
        $src    = (preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/is',    $tag, $cm)) ? trim($cm[2]) : '';
        
        $probe  = ($srcset !== '') ? (string) preg_split('/[\s,]+/', ltrim($srcset))[0] : $src;
        if ($probe === '' || stripos($probe, 'data:') === 0) return $html;
        
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_lcp_bg_url_allowed')
            && !wps_rewriteLogic::wpc_lcp_bg_url_allowed($probe)) {
            return $html;
        }
        if ($srcset !== '') {
            $link = '<link rel="preload" as="image" fetchpriority="high" id="wpc-lcp-img-preload"'
                  . ' imagesrcset="' . esc_attr($srcset) . '"'
                  . (($sizes !== '') ? ' imagesizes="' . esc_attr($sizes) . '"' : '')
                  . '>';
        } else {
            if ($src === '' || stripos($src, 'data:') === 0) return $html;
            $link = '<link rel="preload" as="image" fetchpriority="high" id="wpc-lcp-img-preload"'
                  . ' href="' . esc_url($src) . '">';
        }
        return self::wpc_inject_after_viewport($html, $link);
    }


    private static function wpc_inject_after_viewport($html, $link)
    {
        if (preg_match('/<meta\b[^>]*\bname\s*=\s*["\']?viewport["\']?[^>]*>/i', $html, $hm, PREG_OFFSET_CAPTURE)
            || preg_match('/<meta\b[^>]*\bcharset\b[^>]*>/i', $html, $hm, PREG_OFFSET_CAPTURE)
            || preg_match('/<head\b[^>]*>/i', $html, $hm, PREG_OFFSET_CAPTURE)) {
            $pos = (int) $hm[0][1] + strlen($hm[0][0]);
            return substr($html, 0, $pos) . "\n" . $link . substr($html, $pos);
        }
        return $link . "\n" . $html;
    }


    public static function wpc_lcp_census_stem783()
    {
        static $wpc_c783 = null;
        if ($wpc_c783 !== null) { return $wpc_c783; }
        $wpc_c783 = '';
        try {
            if (!class_exists('wps_ic_url_key') || !defined('WPS_IC_CRITICAL')) { return $wpc_c783; }
            $wpc_u783 = (function_exists('is_ssl') && is_ssl() ? 'https://' : 'http://')
                . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
                . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?');
            $wpc_k783 = (new wps_ic_url_key())->setup($wpc_u783);
            if ($wpc_k783 === '') { return $wpc_c783; }
            $wpc_f783 = rtrim(WPS_IC_CRITICAL, '/') . '/' . $wpc_k783 . '/lcp.json';
            if (!@is_readable($wpc_f783)) { return $wpc_c783; }
            $wpc_j783 = json_decode((string) @file_get_contents($wpc_f783), true);
            if (!is_array($wpc_j783) || empty($wpc_j783['atf_images']) || !is_array($wpc_j783['atf_images'])) {
                return $wpc_c783;
            }
            $wpc_m783 = (!empty($_GET['simulate_mobile']) || (function_exists('wp_is_mobile') && wp_is_mobile()));
            $wpc_g783 = $wpc_m783 ? 'mobile' : 'desktop';
            $wpc_l783 = (isset($wpc_j783['atf_images'][$wpc_g783]) && is_array($wpc_j783['atf_images'][$wpc_g783]))
                ? $wpc_j783['atf_images'][$wpc_g783] : [];
            if (empty($wpc_l783)) {
                foreach (['mobile', 'desktop'] as $wpc_o783) {
                    if (!empty($wpc_j783['atf_images'][$wpc_o783]) && is_array($wpc_j783['atf_images'][$wpc_o783])) {
                        $wpc_l783 = $wpc_j783['atf_images'][$wpc_o783];
                        break;
                    }
                }
            }
            if (empty($wpc_l783) && isset($wpc_j783['atf_images'][0])) { $wpc_l783 = $wpc_j783['atf_images']; }
            $wpc_best783 = 0;
            $wpc_fold783 = (int) apply_filters('wpc_lcp_census_fold', 1200);
            foreach ((array) $wpc_l783 as $wpc_e783) {
                if (!is_array($wpc_e783) || empty($wpc_e783['stem']) || !is_string($wpc_e783['stem'])) { continue; }
                if (!preg_match('/^[A-Za-z0-9._@-]{3,}$/', $wpc_e783['stem'])) { continue; }
                $wpc_w783 = (int) (isset($wpc_e783['css_w']) ? $wpc_e783['css_w'] : 0);
                $wpc_h783 = (int) (isset($wpc_e783['css_h']) ? $wpc_e783['css_h'] : 0);
                
                
                if ($wpc_w783 < 64 || $wpc_h783 < 64
                    || $wpc_w783 * $wpc_h783 < (int) apply_filters('wpc_lcp_census_min_area', 20000)) { continue; }
                
                if (isset($wpc_e783['top']) && (int) $wpc_e783['top'] > $wpc_fold783) { continue; }
                $wpc_a783 = $wpc_w783 * $wpc_h783;
                if ($wpc_a783 > $wpc_best783) {
                    $wpc_best783 = $wpc_a783;
                    $wpc_c783 = strtolower((string) $wpc_e783['stem']);
                }
            }
        } catch (\Throwable $e) {
            $wpc_c783 = '';
        }
        return $wpc_c783;
    }

    public static function wpc_zone_preconnect_prune_pass($html)
    {
        try {
            if (!is_string($html) || $html === '' || empty(self::$zone_name)) {
                return $html;
            }
            if (!apply_filters('wpc_zone_preconnect_prune', true)) {
                return $html;
            }
            
            
            
            
            
            $wpc_zh782 = strtok((string) self::$zone_name, '/');
            if (!is_string($wpc_zh782) || $wpc_zh782 === '') {
                return $html;
            }
            $wpc_zp782 = '/<link\b[^>]*rel=["\'](?:preconnect|dns-prefetch)["\'][^>]*'
                . preg_quote($wpc_zh782, '/') . '[^>]*>/i';
            $wpc_zs782 = preg_replace($wpc_zp782, '', $html);
            if (!is_string($wpc_zs782) || $wpc_zs782 === $html) {
                return $html;
            }
            return (stripos($wpc_zs782, $wpc_zh782) !== false) ? $html : $wpc_zs782;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public static function wpc_zone_font_preconnect_pass($html)
    {
        try {
            if (!is_string($html) || $html === '' || empty(self::$zone_name)) {
                return $html;
            }
            if (!apply_filters('wpc_zone_font_preconnect', true)) {
                return $html;
            }
            $wpc_zq132 = preg_quote((string) self::$zone_name, '/');
            if (preg_match('/<link\b[^>]*rel=["\']preconnect["\'][^>]*' . $wpc_zq132 . '[^>]*\bcrossorigin\b/i', $html)) {
                return $html;
            }
            
            $wpc_early132 =
                preg_match('/<link\b[^>]*as=["\']font["\'][^>]*href=["\']https:\/\/' . $wpc_zq132 . '\//i', $html)
                || preg_match('/<link\b[^>]*href=["\']https:\/\/' . $wpc_zq132 . '\/[^"\']*\.woff2?[^"\']*["\'][^>]*as=["\']font["\']/i', $html)
                || preg_match('/@font-face[^}]{0,600}?url\(\s*["\']?https:\/\/' . $wpc_zq132 . '\/[^"\')]*\.woff2?/i', $html);
            if (!$wpc_early132) {
                return $html;
            }
            $wpc_twin132 = '<link rel="preconnect" href="https://' . self::$zone_name . '" crossorigin>';
            $wpc_plain132 = '<link rel="preconnect" href="https://' . self::$zone_name . '">';
            if (strpos($html, $wpc_plain132) !== false) {
                return str_replace($wpc_plain132, $wpc_plain132 . $wpc_twin132, $html);
            }
            return self::wpc_inject_after_viewport($html, $wpc_twin132);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    
    
    
    
    
    
    
    
    
    public static function wpc_fonts_drop_remote_dup($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<link') === false) return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_fonts_drop_remote_dup', true)) return $html;
        $set = function_exists('get_option') ? get_option('wps_ic_fonts_remote_dup') : null;
        if (!is_array($set) || empty($set)) return $html;
        $hrefs = []; $hosts = []; $fams = [];
        foreach ($set as $tok) {
            if (!is_string($tok)) continue;
            if (strpos($tok, '@href:') === 0) { $h = substr($tok, 6); if ($h !== '') $hrefs[] = $h; }
            elseif (strpos($tok, '@host:') === 0) { $h = substr($tok, 6); if ($h !== '') $hosts[] = $h; }
            elseif (strpos($tok, 'fam:') === 0) { $f = substr($tok, 4); if ($f !== '') $fams[] = $f; }
        }
        if (empty($hrefs) && empty($hosts)) return $html;
        $out = preg_replace_callback('/<link\b[^>]*>/i', function ($m) use ($hrefs, $hosts, $fams) {
            $tag = $m[0];
            if (!preg_match('/\bhref=(["\'])(.*?)\1/i', $tag, $hm)) return $tag;
            $href = strtolower(html_entity_decode($hm[2]));
            
            foreach ($hrefs as $h) { if (strpos($href, $h) !== false) return ''; }
            
            $hostHit = false;
            foreach ($hosts as $h) { if (strpos($href, $h) !== false) { $hostHit = true; break; } }
            if (!$hostHit) return $tag;
            if (!preg_match('/[?&]family=([^&"\']*)/i', $href, $fm)) return $tag; 
            foreach (explode('|', urldecode($fm[1])) as $fpart) {
                $fname = trim(preg_replace('/:.*$/', '', str_replace('+', ' ', $fpart)));
                if ($fname !== '' && !in_array($fname, $fams, true)) return $tag; 
            }
            return '';
        }, $html);
        return is_string($out) ? $out : $html;
    }

    public static function wpc_font_preconnect_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<head') === false) {
            return $html;
        }
        if (!apply_filters('wpc_font_preconnect', true)) {
            return $html;
        }


        if (isset(self::$settings['replace-fonts']) && self::$settings['replace-fonts'] === 'local') {
            $wpc_stripped107 = preg_replace(
                [
                    '#<link\b[^>]*rel=["\']?(?:preconnect|dns-prefetch)["\']?[^>]*(?:fonts\.gstatic\.com|fonts\.googleapis\.com|fonts\.bunny\.net)[^>]*>\s*#i',
                    '#<link\b[^>]*(?:fonts\.gstatic\.com|fonts\.googleapis\.com|fonts\.bunny\.net)[^>]*rel=["\']?(?:preconnect|dns-prefetch)["\']?[^>]*>\s*#i',
                ],
                '',
                $html
            );
            if (is_string($wpc_stripped107) && $wpc_stripped107 !== '') {
                $html = $wpc_stripped107;
            }
            return $html;
        }
        $links = '';
        if (stripos($html, 'fonts.gstatic.com') !== false
            && !preg_match('/<link\b[^>]*rel=["\']?preconnect["\']?[^>]*fonts\.gstatic\.com/i', $html)
            && !preg_match('/<link\b[^>]*fonts\.gstatic\.com[^>]*rel=["\']?preconnect/i', $html)) {
            $links .= '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>';
        }
        if (stripos($html, 'fonts.googleapis.com/css') !== false
            && !preg_match('/<link\b[^>]*rel=["\']?preconnect["\']?[^>]*fonts\.googleapis\.com/i', $html)
            && !preg_match('/<link\b[^>]*fonts\.googleapis\.com[^>]*rel=["\']?preconnect/i', $html)) {
            $links .= '<link rel="preconnect" href="https://fonts.googleapis.com">';
        }
        if (stripos($html, 'fonts.bunny.net') !== false
            && !preg_match('/<link\b[^>]*rel=["\']?preconnect["\']?[^>]*fonts\.bunny\.net/i', $html)) {
            $links .= '<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>';
        }
        if ($links === '') {
            return $html;
        }
        if (preg_match('/<head\b[^>]*>/i', $html, $hm, PREG_OFFSET_CAPTURE)) {
            $pos = (int) $hm[0][1] + strlen($hm[0][0]);
            return substr($html, 0, $pos) . "\n" . $links . substr($html, $pos);
        }
        return $html;
    }


    public static function wpc_rum_beacon_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '</body>') === false) { return $html; }
        if (strpos($html, 'wpc-rum-beacon') !== false) { return $html; }
        if (function_exists('is_user_logged_in') && is_user_logged_in()) { return $html; }
        if (function_exists('is_customize_preview') && is_customize_preview()) { return $html; }
        if (function_exists('apply_filters') && !apply_filters('wpc_rum_beacon', true)) { return $html; }
        $wpc_rate775 = (int) (function_exists('apply_filters') ? apply_filters('wpc_rum_sample_rate', 50) : 50);
        if ($wpc_rate775 < 1) { return $html; }
        $wpc_ax775 = function_exists('admin_url') ? (string) admin_url('admin-ajax.php') : '';
        if ($wpc_ax775 === '' || strpos($wpc_ax775, 'http') !== 0) { return $html; }
        
        
        $wpc_js775 = <<<'WPCRUMJS'
(function(){try{
if(navigator.webdriver)return;
if(Math.random()*__RATE__>=1)return;
var LS;try{LS=localStorage}catch(e){}
if(LS&&+(LS.getItem('wpcRumOff')||0)>Date.now())return;
var atf=[],lcp=null,sent=0;
requestAnimationFrame(function(){var vh=innerHeight,vw=innerWidth,xs=document.images,i,im,r;
for(i=0;i<xs.length&&atf.length<20;i++){im=xs[i];r=im.getBoundingClientRect();
if(r.width<24||r.height<24||r.bottom<=0||r.top>=vh||r.left>=vw)continue;
atf.push({classes:String(im.className||'').split(/\s+/).slice(0,2),slot_w:Math.round(r.width),slot_h:Math.round(r.height),intrinsic_w:im.naturalWidth||0,current_src:String(im.currentSrc||im.src||'').slice(0,300),loading:im.getAttribute('loading')||''})}});
try{new PerformanceObserver(function(l){var e=l.getEntries();if(!e.length)return;var x=e[e.length-1],el=x.element;
var sel=el?el.tagName.toLowerCase()+(el.className?'.'+String(el.className).split(/\s+/).slice(0,2).join('.'):''):'';
var rr=el&&el.getBoundingClientRect?el.getBoundingClientRect():{width:0,height:0,x:0,y:0};
lcp={selector:sel.slice(0,120),url:x.url?String(x.url).slice(0,300):null,rect:{w:Math.round(rr.width),h:Math.round(rr.height),x:Math.round(rr.x),y:Math.round(rr.y)},t_ms:Math.round(x.startTime)}}).observe({type:'largest-contentful-paint',buffered:true})}catch(e){}
var send=function(){if(sent||(!atf.length&&!lcp))return;sent=1;var b;
try{b=JSON.stringify({v:1,url:location.href.split('#')[0].slice(0,500),viewport:{w:innerWidth,h:innerHeight,dpr:devicePixelRatio||1},lcp:lcp,atf_images:atf})}catch(e){return}
if(b.length>30000)return;
var ok=navigator.sendBeacon&&navigator.sendBeacon('__AX__?action=wpc_rum_census',new Blob([b],{type:'text/plain'}));
if(!ok&&LS){try{LS.setItem('wpcRumOff',String(Date.now()+6048e5))}catch(e){}}};
addEventListener('load',function(){('requestIdleCallback'in window?requestIdleCallback:function(f){setTimeout(f,2e3)})(function(){setTimeout(send,1200)})});
addEventListener('pagehide',send);
}catch(e){}})();
WPCRUMJS;
        $wpc_js775 = str_replace(['__RATE__', '__AX__'], [(string) $wpc_rate775, esc_url_raw($wpc_ax775)], $wpc_js775);
        return wpc_body_inject809($html, '<script id="wpc-rum-beacon">' . $wpc_js775 . '</script>');
    }

    public static function wpc_embed_facade_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<iframe') === false) {
            return $html;
        }
        $set = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : [];
        $on  = is_array($set) && !empty($set['embed-facade']) && $set['embed-facade'] == '1';
        
        
        $wpc_off769 = is_array($set) && isset($set['embed-facade']) && $set['embed-facade'] == '0';
        $wpc_vid769 = (bool) apply_filters('wpc_embed_facade_video_default', !$wpc_off769);
        if (!apply_filters('wpc_embed_facade', $on) && !$wpc_vid769) {
            return $html;
        }
        if (function_exists('is_user_logged_in') && is_user_logged_in()) {
            return $html;
        }
        $wpc_full769 = (bool) apply_filters('wpc_embed_facade', $on);
        $hosts = (array) apply_filters('wpc_embed_facade_hosts', [
            'google.com/maps/embed', 'maps.google.com', 'youtube.com/embed', 'youtube-nocookie.com/embed',
            'play.gumlet.io/embed',
        ]);
        $count = 0;
        $out = preg_replace_callback('/<iframe\b[^>]*>(?:\s*<\/iframe>)?/is', function ($m) use ($hosts, $wpc_full769, &$count) {
            $tag = $m[0];
            $hit = '';
            foreach ($hosts as $h) {
                if (stripos($tag, $h) !== false) { $hit = $h; break; }
            }
            if ($hit === '') {
                return $tag;
            }
            $isYt = (stripos($hit, 'youtube') !== false);
            $isVid = $isYt || stripos($hit, 'gumlet') !== false;
            if (!$wpc_full769) {
                
                
                
                
                
                if (stripos($hit, 'gumlet') === false
                    || preg_match('/\b(?:autoplay|background)=(?:true|1)\b/i', $tag)
                    || preg_match('/data-(?:cookieblock|cmplz|borlabs|cookieconsent|consent)|cookiebot/i', $tag)
                    || !preg_match('/\ssrc=(["\'])[^"\']*' . preg_quote($hit, '/') . '/i', $tag)) {
                    return $tag;
                }
            }
            
            $w = preg_match('/\bwidth=["\']?(\d{2,4})/i', $tag, $wm) ? (int) $wm[1] : 0;
            $h = preg_match('/\bheight=["\']?(\d{2,4})/i', $tag, $hm) ? (int) $hm[1] : 0;
            $ratio = ($w > 0 && $h > 0) ? ($w . ' / ' . $h) : '16 / 9';
            $style = 'position:relative;display:block;width:100%;aspect-ratio:' . $ratio . ';'
                   . 'background:#e8eaed;border-radius:8px;overflow:hidden;cursor:pointer;border:0;padding:0;';
            $poster = '';
            if ($isYt && preg_match('#/embed/([A-Za-z0-9_-]{6,})#', $tag, $vm)) {
                $poster = '<img src="https://i.ytimg.com/vi/' . esc_attr($vm[1]) . '/hqdefault.jpg" alt="" loading="lazy" decoding="async"'
                        . ' style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">';
            }
            $label = $isVid ? 'Play video' : 'Load map';
            $badge = $isVid
                ? '<span style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:68px;height:48px;background:rgba(0,0,0,.72);border-radius:12px;display:flex;align-items:center;justify-content:center;">'
                  . '<svg width="24" height="24" viewBox="0 0 24 24" fill="#fff" aria-hidden="true"><path d="M8 5v14l11-7z"/></svg></span>'
                : '<span style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);display:flex;flex-direction:column;align-items:center;gap:8px;color:#5f6368;font:600 14px/1 -apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">'
                  . '<svg width="34" height="34" viewBox="0 0 24 24" fill="#ea4335" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5z"/></svg>'
                  . '<span style="background:#fff;border-radius:20px;padding:8px 16px;box-shadow:0 1px 3px rgba(0,0,0,.25);">Load map</span></span>';
            $count++;
            return '<button type="button" class="wpc-embed-facade" data-wpc-embed="' . base64_encode($tag) . '"'
                 . ' aria-label="' . esc_attr($label) . '" style="' . $style . '">' . $poster . $badge . '</button>';
        }, $html, -1, $n);
        if ($out === null || $count === 0) {
            return $html;
        }


        $js = '<script id="wpc-embed-facade-js">(function(){document.addEventListener("click",function(e){'
            . 'var b=e.target&&e.target.closest?e.target.closest(".wpc-embed-facade"):null;if(!b)return;'
            . 'e.preventDefault();var d=document.createElement("div");'
            . 'try{d.innerHTML=atob(b.getAttribute("data-wpc-embed"));}catch(x){return;}'
            . 'var f=d.querySelector("iframe");if(!f)return;'
            . 'var s=f.getAttribute("src")||f.getAttribute("data-src")||f.getAttribute("data-wpc-src")||"";'
            . 'if(s&&/youtube(-nocookie)?\\.com\\/embed\\//i.test(s)&&s.indexOf("autoplay=")===-1){s+=(s.indexOf("?")===-1?"?":"&")+"autoplay=1";}'
            . 'if(s&&/play\\.gumlet\\.io\\/embed\\//i.test(s)){if(/autoplay=false/i.test(s)){s=s.replace(/autoplay=false/ig,"autoplay=true");}else if(s.indexOf("autoplay=")===-1){s+=(s.indexOf("?")===-1?"?":"&")+"autoplay=true";}}'
            . 'if(s){f.setAttribute("src",s);}f.removeAttribute("loading");'
            . 'f.style.width="100%";f.style.height="100%";f.style.position="absolute";f.style.inset="0";f.style.border="0";'
            . 'b.style.cursor="default";b.innerHTML="";b.appendChild(f);},true);})();</script>';
        return wpc_body_inject809($out, $js . "\n");
    }


    public static function wpc_collapse_double_ext($html)
    {
        $wpc_ps531 = class_exists('Wpc_Prof_Span530') ? new Wpc_Prof_Span530('pass:wpc_collapse_double_ext') : null;
        if (!is_string($html) || $html === '') return $html;
        if (strpos($html, '.webp.webp') === false
            && strpos($html, '.avif.avif') === false
            && strpos($html, '.png.png')  === false
            && strpos($html, '.gif.gif')  === false
            && !preg_match('/\.jpe?g\.jpe?g/i', $html)) {
            return $html;
        }
        $out = preg_replace('/(\.(?:webp|avif|jpe?g|png|gif))\1/i', '$1', $html);
        return ($out === null) ? $html : $out;
    }

    public function buffer_local_callback($html)
    {
        $wpc_ps531 = class_exists('Wpc_Prof_Span530') ? new Wpc_Prof_Span530('pass:buffer_local_callback') : null;


        
        
        $html = wpc_heal_mixed_content($html);

        $isUserLoggedIn = is_user_logged_in();

        if (!self::dontRunif()) {
            return $html;
        }

        if ((!empty($_GET['criticalCombine']) && $_GET['criticalCombine'] == 'true') || !empty(wpcGetHeader('criticalCombine'))) {
            $this->criticalCombine = true;
        }
        
        if (isset($_GET['brizy-edit-iframe']) || isset($_GET['brizy-edit']) || isset($_GET['preview'])) {
            return $html;
        }

        if (self::$isAjax) {
            return $html;
        }

        if (is_admin() || is_feed() || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['trp-edit-translation']) || !empty($_GET['elementor-preview']) || !empty($_GET['preview']) || !empty($_GET['is-editor-iframe']) || !empty($_GET['PageSpeed']) || !empty($_GET['tve']) || !empty($_GET['et_fb']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['ct_builder']) || !empty
            ($_GET['tatsu']) || !empty($_GET['fb-edit']) || !empty($_GET['bricks']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor')) {
            return $html;
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'replace_iframe_tags') {
            return $html;
        }


        
        
        $wpcLocalScriptMask = [];
        $html = wps_rewriteLogic::maskMediaScripts($html, $wpcLocalScriptMask);

        $wpcnd_local_stash = [];
        if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) {
            $html = WPC_Negotiated_Delivery::rewrite_buffer($html);
            if (class_exists('wps_rewriteLogic')) {
                wps_rewriteLogic::$pictureWebpEnabled = false;
            }


            $html = preg_replace_callback('/<img\b[^>]*\bdata-wpc-nd\b[^>]*>/i', function ($m) use (&$wpcnd_local_stash) {
                $k = '___WPCND_IMG_' . count($wpcnd_local_stash) . '___';
                $wpcnd_local_stash[$k] = $m[0];
                return $k;
            }, $html);
        }


        
        
        
        
        
        
        if ((!empty(self::$settings['iframe-lazy']) && self::$settings['iframe-lazy'] == '1'
                || self::wpc_facade_aggr_ok())
            && !$isUserLoggedIn) {
            $html = preg_replace_callback('/<iframe[^>]*>(.*?)<\/iframe>/si', [$this, 'replace_iframe_tags'], $html);
            $html = preg_replace_callback('/<source([^>]*)\ssrc=["\']([^"\']+)["\']/i', [$this, 'replace_source_tags'], $html);
        }

        
        if (!empty(self::$settings['video-preload-none']) && self::$settings['video-preload-none'] == '1' && !$isUserLoggedIn) {
            $html = preg_replace_callback('/<video\b([^>]*)>/i', function ($matches) {
                $attrs = $matches[1];
                if (preg_match('/\bpreload\s*=/i', $attrs)) {
                    return $matches[0];
                }
                return '<video' . $attrs . ' preload="none">';
            }, $html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'encode_iframe') {
            return $html;
        }

        if (!$isUserLoggedIn) {
            $html = self::$rewriteLogic->encodeIframe($html);
        }

        $html = wps_rewriteLogic::unmaskMediaScripts($html, $wpcLocalScriptMask);
        $wpcLocalScriptMask = [];

        if (self::$cdnEnabled == 0) {
            $htmlBefore = $html;
            $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$this, 'local_script_encode'], $html);

            if (empty($html)) {
                $html = $htmlBefore;
            }

            
            $wpcLocalPictureBlocks = [];
            if (self::$rewriteLogic::$pictureWebpEnabled) {
                $html = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$wpcLocalPictureBlocks) {
                    $i = count($wpcLocalPictureBlocks);
                    $wpcLocalPictureBlocks[$i] = $m[0];
                    return '<!--WPC_LOCAL_PICTURE_' . $i . '-->';
                }, $html);
            }

            if (function_exists('wpc_device_hidden_image_set')) {
                self::$deviceHiddenSet717 = wpc_device_hidden_image_set($html, function_exists('wpc_ua_is_mobile') ? (bool) wpc_ua_is_mobile() : false);
            }
            $html = preg_replace_callback('/(?<![\"|\'])<img[^>]*>/i', [$this, 'local_image_tags'], $html);

            
            foreach ($wpcLocalPictureBlocks as $i => $block) {
                $html = str_replace('<!--WPC_LOCAL_PICTURE_' . $i . '-->', $block, $html);
            }

            if (self::$fonts == 1) {
                $html = self::$rewriteLogic->fonts($html);
            }

            $html = preg_replace_callback('/\[script\-wpc\](.*?)\[\/script\-wpc\]/i', [$this, 'local_script_decode'], $html);

            $html = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>?/is', [self::$rewriteLogic, 'replaceBackgroundImagesInCSSLocal'], $html);

            
            if ($this->doCacheCombine() && (isset(self::$settings['js_combine']) && self::$settings['js_combine'] == '1')) {
                $combine_js = new wps_ic_combine_js();
                $html = $combine_js->maybe_do_combine($html);
            }
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'setImageSize') {
            return $html;
        }

        
        $wpcLocalSizeMask = [];
        $html = wps_rewriteLogic::maskMediaScripts($html, $wpcLocalSizeMask);
        $html = preg_replace_callback('/<img[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/si', [$this, 'set_image_sizes'], $html);
        $html = preg_replace_callback('/<picture>.*?<\/picture>/is', [$this, 'set_image_sizes'], $html);
        $html = wps_rewriteLogic::unmaskMediaScripts($html, $wpcLocalSizeMask);

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'combine_css') {
            return $html;
        }

        if (!empty($_GET['debug_preload_inject'])) {
            $dbg = 'Before:';
            $dbg .= $html;
        }


        $html = preg_replace_callback('/<head\b[^>]*>(?:\s*<meta[^>]*\bcharset\b[^>]*>)?/si', [$this, 'injectPreloadImages'], $html, 1);

        if (!empty($_GET['debug_preload_inject'])) {
            $dbg .= 'After:';
            $dbg .= $html;

            return $dbg;
        }

        $combine_css = new wps_ic_combine_css();
        if (!empty(wpcGetHeader('criticalCombine')) || !empty($_GET['criticalCombine']) || ($this->doCacheCombine() && (isset(self::$settings['css_combine']) && self::$settings['css_combine'] == '1'))) {
            if (empty($_GET['stopCombineCSS'])) {
                $html = $combine_css->maybe_do_combine($html);
            }
        }

        if (isset(self::$settings['fontawesome-lazy']) && self::$settings['fontawesome-lazy'] == '1') {
            
            $html = $combine_css->lazyFontawesome($html);
        }

        
        $criticalActive = !(isset(self::$page_excludes['critical_css']) && self::$page_excludes['critical_css'] == '0') && ((isset(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') || (isset(self::$page_excludes['critical_css']) && self::$page_excludes['critical_css'] == '1')) && (empty($settings['developer_mode']) || $settings['developer_mode'] == '0');

        $criticalCSS = new wps_criticalCss();
        $criticalCSSExists = $criticalCSS->criticalExists();

        if (!self::$isAmp->isAmp() && empty(wpcGetHeader('criticalCombine')) && (empty($_GET['disableCritical']) && empty($_GET['generateCriticalAPI'])) && empty($_GET['criticalCombine'])) {
            if (!is_user_logged_in() && !is_admin_bar_showing()) {

                if ($criticalActive && !self::$preloaderAPI) {
                    global $post;

                    if (!empty($_GET['forceCriticalAjax'])) {
                        $html = self::$rewriteLogic->runCriticalAjax($html);
                    } else {
                        if (empty($criticalCSSExists)) {
                            $criticalRunning = $criticalCSS->criticalRunning();
                            if (!$criticalRunning) {
                                set_transient('wpc_critical_ajax_' . md5(wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), date('d.m.Y H:i:s'), 60 * 5);
                                $html = self::$rewriteLogic->runCriticalAjax($html);
                            }
                        }

                    }
                }

            }
        }

        if (empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {


            
            if (($this->doCacheCombine() && (isset(self::$settings['css_combine']) && self::$settings['css_combine'] == '1')) || $this->criticalCombine) {
                if (empty($_GET['stopCombineCSS'])) {
                    $html = $combine_css->maybe_do_combine($html);
                }
            }

            
        }

        if ((empty($_GET['disableCritical']) && empty($_GET['generateCriticalAPI'])) && empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {
            if (!is_user_logged_in() && !is_admin_bar_showing()) {
                if (!empty($_GET['debugCriticalRunning'])) {
                    $html .= print_r([self::$settings['critical']['css'], $criticalCSSExists, $criticalRunning], true);
                }


                if (!empty($_GET['debugCritical_replace'])) {
                    
                    $criticalCSS = new wps_criticalCss();
                    $criticalCSSExists = $criticalCSS->criticalExists();
                    $criticalCSSContent = file_get_contents($criticalCSSExists['file']);

                    
                    $createPreloadLinks = function ($cssContent) {
                        $preloadLinks = '';
                        $loadedFonts = []; 
                        $commentPos = strpos($cssContent, '/* Preload Fonts */');

                        
                        if ($commentPos !== false) {
                            $relevantContent = substr($cssContent, 0, $commentPos);
                            $fontPattern = '/url\((\'|")?(.+?\.(woff2?|ttf|otf|eot))\1?\)/i';
                            if (preg_match_all($fontPattern, $relevantContent, $matches, PREG_SET_ORDER)) {
                                foreach ($matches as $match) {
                                    $fontUrl = $match[2];
                                    if (strpos($fontUrl, 'icon') !== false || strpos($fontUrl, 'fa-') !== false || strpos($fontUrl, 'la-') !== false) {
                                        continue;
                                    }
                                    
                                    if ((!empty(self::$settings['preload-crit-fonts'])) && self::$settings['preload-crit-fonts'] == '1') {
                                        if (!in_array($fontUrl, $loadedFonts)) {
                                            $preloadLinks .= "<link rel=\"preload\" href=\"$fontUrl\" as=\"font\" type=\"font/woff2\" crossorigin=\"anonymous\">\n";
                                            $loadedFonts[] = $fontUrl; 
                                        }
                                    }
                                }
                            }
                        }
                        return $preloadLinks;
                    };

                    
                    $getCSSAfterPreloadComment = function ($cssContent) {
                        $commentPos = strpos($cssContent, '/* Preload Fonts */');
                        return $commentPos !== false ? substr($cssContent, $commentPos + strlen('/* Preload Fonts */')) : $cssContent;
                    };


                    $preloadLinks_Desktop = $createPreloadLinks($criticalCSSContent);

                    return print_r(['critActive:' => $criticalActive, 'preloadApi' => self::$preloaderAPI, 'excluded' => self::isURLExcluded('critical_css'), $preloadLinks_Desktop, $criticalCSSExists, $criticalCSSContent], true);
                }

                if (!empty($_GET['testCritical'])) {
                    self::$settings['critical']['css'] = '1';
                    $html = self::$rewriteLogic->addCritical($html);
                    $html = self::$rewriteLogic->lazyCSS($html);
                }

                if ($criticalActive && !self::$preloaderAPI) {

                    if (!self::isURLExcluded('critical_css')) {

                        
                        $criticalCSS = new wps_criticalCss();
                        $criticalCSSExists = $criticalCSS->criticalExists();

                        if (!empty($criticalCSSExists)) {
                            $html = self::$rewriteLogic->addCritical($html);
                            
                            
                            
                            
                            
                            
                            if (preg_match('/<style[^>]*id=["\']wpc-critical-css["\']/i', $html)) {
                                $html = self::$rewriteLogic->lazyCSS($html);
                            }
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            if (class_exists('wps_rewriteLogic')
                                && method_exists('wps_rewriteLogic', 'wpc_defer_wire_dropfaces680')) {
                                $html = wps_rewriteLogic::wpc_defer_wire_dropfaces680($html);
                            }
                        } else {

                            $html = self::$rewriteLogic->wpc_arm_sentinel_tag($html);
                        }
                    }
                }
            }
        }

        if (!$isUserLoggedIn) {
            $html = self::$rewriteLogic->decodeIframe($html);
        }

        
        $html = self::$themeIntegrations->getIntegration($html);

        
        if (empty($_GET['disableDelay']) && empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {
            $js_delay = new wps_ic_js_delay();


            $delayActive = true;

            if (isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '0') {
                
                $delayActive = false;
            }

            $delayV2Active = true;
            if (isset(self::$page_excludes['delay_js_v2']) && self::$page_excludes['delay_js_v2'] == '0') {
                
                $delayV2Active = false;
            }


            if ((isset(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1')
                || (class_exists('wps_ic_js_delay_v3') && wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings))) {
                if (!self::$isAmp->isAmp() && empty($_GET['disableDelay']) && empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {


                    $wpc_delay_v3 = (!isset(self::$settings['delay-js-v3']) || self::$settings['delay-js-v3'] != '0') && class_exists('wps_ic_js_delay_v3');
                    $js_delay = $wpc_delay_v3 ? new wps_ic_js_delay_v3() : new wps_ic_js_delay_v2();

                    if (empty($_GET['disableCritical']) && $delayV2Active && !current_user_can('manage_wpc_settings') && !self::$delay_js_override && !self::$preloaderAPI) {
                        $html = $js_delay->process_html($html);
                    } else {
                        $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$js_delay, 'removeNoDelay'], $html);
                    }
                }
            } elseif ((isset(self::$settings['delay-js']) && self::$settings['delay-js'] == '1')) {
                if (!self::$isAmp->isAmp() && empty($_GET['disableDelay']) && empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {
                    $js_delay = new wps_ic_js_delay();

                    if (empty($_GET['disableCritical']) && $delayActive && !current_user_can('manage_wpc_settings') && !self::$delay_js_override && !self::$preloaderAPI) {
                        if (!empty(self::$settings['preload-scripts']) && self::$settings['preload-scripts'] == '1') {
                            $html = $js_delay->preload_scripts($html);
                        }
                        $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$js_delay, 'delay_script_replace'], $html);
                    } else {
                        $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$js_delay, 'removeNoDelay'], $html);
                    }
                }

                if (!empty($_GET['testGtag'])) {
                    

                    return print_r([$html], true);
                }

            }
        }


        
        $cacheActive = !(isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '0') && ((isset(self::$settings['cache']['advanced']) && self::$settings['cache']['advanced'] == '1') || (isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '1'));


        $html = preg_replace('/<!--WPC[\s\S]*?-->/', '', $html);


        
        
        if (function_exists('wpc_yield_checkpoints707')) {
            $wpc_dm707 = class_exists('wps_ic_js_delay_v3')
                && wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings)
                && !self::$isAmp->isAmp() && empty($_GET['disableDelay']) && empty($_GET['disableCritical'])
                && !current_user_can('manage_wpc_settings') && !self::$delay_js_override && !self::$preloaderAPI;
            $wpc_pre707 = strlen($html);
            $html = wpc_yield_checkpoints707($html, $wpc_dm707);
            if (strlen($html) !== $wpc_pre707 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('yield-inject', '', '', ['lane' => 'local']);
            }
            $wpc_fmv710l = 0;
            $html = wpc_face_gate710($html, $wpc_dm707, $wpc_fmv710l);
            if ($wpc_fmv710l > 0 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('face-gate', '', '', ['moved' => $wpc_fmv710l, 'lane' => 'local']);
            }
        }


        $html = self::wpc_gfonts_display_pass($html);


        if (!empty(self::$settings['replace-fonts'])) {
            if (self::$settings['replace-fonts'] == 'local') {
                $fonts = new wps_ic_fonts();
                $html  = $fonts->replaceFrontend($html);
            } else if (self::$settings['replace-fonts'] == 'bunny') {
                
                $html = str_replace('fonts.googleapis.com', 'fonts.bunny.net', $html);
                $html = preg_replace('/<link\b[^>]*\bhref=["\']https?:\/\/fonts\.gstatic\.com\/[^"\']+["\'][^>]*>\s*/i', '', $html);
                $html = str_replace('fonts.gstatic.com', 'fonts.bunny.net', $html);
            }
        }


        if (class_exists('WPC_Modern_Delivery') && WPC_Modern_Delivery::is_active()
            && !(class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active())) {
            $wpcLocalMdMask = [];
            $html = wps_rewriteLogic::maskMediaScripts($html, $wpcLocalMdMask);
            $html = WPC_Modern_Delivery::rewrite_buffer($html);
            $html = wps_rewriteLogic::unmaskMediaScripts($html, $wpcLocalMdMask);
        }

        
        if (!empty($wpcnd_local_stash)) {
            $html = strtr($html, $wpcnd_local_stash);
        }

        if (function_exists('wpc_stack_splice732')) {
            $html = wpc_stack_splice732($html);
        }
        
        
        
        
        if (function_exists('wpc_face_gate710') && isset($wpc_dm707) && $wpc_dm707) {
            $wpc_fmv711l = 0;
            $html = wpc_face_gate710($html, true, $wpc_fmv711l);
            if ($wpc_fmv711l > 0 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('face-gate', '', '', ['moved' => $wpc_fmv711l, 'lane' => 'local-final']);
            }
        }

        return $html;
    }

    public function doCacheCombine()
    {


        if (is_404()) {
            return false;
        }

        if (!empty($_GET['forceRecombine']) && $_GET['forceRecombine'] == 'true') {
            return true;
        }

        if (current_user_can('manage_wpc_settings')) {
            return false;
        }

        $keys = new wps_ic_url_key();
        $allowed_params = $keys->get_allowed_params();
        $get_keys = array_keys($_GET);

        sort($allowed_params);
        sort($get_keys);

        if ($allowed_params === $get_keys) {
            return true;
        }

        if (!empty($_GET)) {
            return false;
        }

        if (self::dontRunif()) {
            return true;
        }

        if ($this->isPageBuilder()) {
            return false;
        }

        if ($this->isPageBuilderFE()) {
            return false;
        }

        if ($this->isFEBuilder()) {
            return false;
        }

        if ($this->isAPICall()) {
            return false;
        }

        if (wp_doing_cron()) {
            return false;
        }


        return true;
    }

    



    public static function isPageBuilder()
    {
        $page_builders = ['run_compress',
            'run_restore',
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
            'is-editor-iframe',
            'tve',
            'pagelayer-live'];

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return true;
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

    public static function isFEBuilder()
    {
        if (!empty($_GET['trp-edit-translation']) || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || !empty($_GET['elementor-preview']) || !empty($_GET['tatsu']) || !empty($_GET['preview']) || !empty($_GET['PageSpeed']) || !empty($_GET['tve']) || !empty($_GET['et_fb']) || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['ct_builder']) || !empty($_GET['fb-edit']) || !empty($_GET['bricks']) || !empty($_GET['is-editor-iframe']) || !empty($_GET['brizy-edit-iframe']) || !empty($_GET['brizy-edit']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor')) {
            return true;
        } else {
            return false;
        }
    }

    public function isAPICall()
    {
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            if (strpos($_SERVER['HTTP_USER_AGENT'], 'Compress-API') !== false) {
                return true;
            }
        }

        return false;
    }

    public static function isURLExcluded($setting)
    {
        if (!isset(self::$excludes[$setting]) || empty(self::$excludes[$setting])) {
            return false;
        }

        $url = self::$keys->url;
        $excludeList = self::$excludes[$setting];
        if (!empty($excludeList)) {
            foreach ($excludeList as $key => $value) {
                if ($value) {
                    if (strpos($url, $value) !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    public function checkCache()
    {
        if (!empty($_GET['disableCache']) || !empty($_GET['forceRecombine'])) {
            return true;
        }

        if (self::dontRunif()) {
            



            if (!empty($_GET['dontRunCache'])) {
                die('Check cache 23');
            }

            $isUserLoggedIn = is_user_logged_in();
            if ($isUserLoggedIn) {
                return true;
            }

            $cache = new wps_cacheHtml();
            if ($cache->cacheEnabled()) {

                if (!empty($_GET['cacheDbg2'])) {
                    die('x');
                }

                $mobile = self::is_mobile();
                $prefix = '';
                if ($mobile) {
                    $prefix = 'mobile';
                }
                if ($cache->cacheExists($prefix)) {
                    $isCacheExpired = false;

                    
                    $isCacheValid = true;

                    if (!$isCacheExpired && $isCacheValid) {
                        $cache->getCache($prefix);
                    }

                }
            }

        }
    }

    public function is_mobile()
    {
        
        
        if (function_exists('wpc_ua_is_mobile')) {
            return wpc_ua_is_mobile();
        }

        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }

        if (isset($_SERVER['HTTP_USER_AGENT']) && (preg_match('#(ipad|tablet|windows\ phone|mobile)#i', (string) $_SERVER['HTTP_USER_AGENT']) || preg_match('#^.*(2.0\ MMP|240x320|400X240|AvantGo|BlackBerry|Blazer|Cellphone|Danger|DoCoMo|Elaine/3.0|EudoraWeb|Googlebot-Mobile|hiptop|IEMobile|KYOCERA/WX310K|LG/U990|MIDP-2.|MMEF20|MOT-V|NetFront|Newt|Nintendo\ Wii|Nitro|Nokia|Opera\ Mini|Palm|PlayStation\ Portable|portalmmm|Proxinet|ProxiNet|SHARP-TQ-GX10|SHG-i900|Small|SonyEricsson|Symbian\ OS|SymbianOS|TS21i-10|UP.Browser|UP.Link|webOS|Windows\ CE|WinWAP|YahooSeeker/M1A1-R2D2|iPhone|iPod|Android|BlackBerry9530|LG-TU915\ Obigo|LGE\ VX|webOS|Nokia5800).*#i', $_SERVER['HTTP_USER_AGENT']) || preg_match('#^(w3c\ |w3c-|acs-|alav|alca|amoi|audi|avan|benq|bird|blac|blaz|brew|cell|cldc|cmd-|dang|doco|eric|hipt|htc_|inno|ipaq|ipod|jigs|kddi|keji|leno|lg-c|lg-d|lg-g|lge-|lg/u|maui|maxo|midp|mits|mmef|mobi|mot-|moto|mwbp|nec-|newt|noki|palm|pana|pant|phil|play|port|prox|qwap|sage|sams|sany|sch-|sec-|send|seri|sgh-|shar|sie-|siem|smal|smar|sony|sph-|symb|t-mo|teli|tim-|tosh|tsm-|upg1|upsi|vk-v|voda|wap-|wapa|wapi|wapp|wapr|webc|winw|winw|xda\ |xda-).*#i', substr($_SERVER['HTTP_USER_AGENT'], 0, 4)))) {
            return true;
        }

        return false;
    }

    public function checkCache_plugins_loaded()
    {


        if (defined('WEGLOT_VERSION')) {
            wps_ic_url_key::captureRequestUrl();
        }

        if (!empty($_GET['disableCache']) || !empty($_GET['forceRecombine'])) {
            return true;
        }

        if (self::dontRunif()) {
            



            if (!empty($_GET['dontRunCache'])) {
                die('Check cache 23');
            }

            $cache = new wps_cacheHtml();
            $isUserLoggedIn = is_user_logged_in();

            if ($isUserLoggedIn) {
                if (!$cache->cacheLoggedIn()) {
                    return true;
                }
            }

            if ($cache->cacheEnabled()) {

                if (!empty($_GET['cacheDbg2'])) {
                    die('x');
                }

                $mobile = self::is_mobile();
                $prefix = '';
                if ($mobile) {
                    $prefix = 'mobile';
                }

                if ($cache->cacheExists($prefix)) {
                    $isCacheExpired = false;

                    
                    $isCacheValid = true;

                    if (!$isCacheExpired && $isCacheValid) {
                        $cache->getCache($prefix);
                    }

                } else {
                    if (!defined('WPS_IC_CACHE_BUFFER_STARTED')) {
                        if (function_exists('wpc_response_cache_guard')) {
                            wpc_response_cache_guard();
                        }
                        ob_start([$this, 'saveCache']);
                    }
                }
            }

        }
    }

    public function buffer_callback_v3()
    {
        if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
            return true;
        }


        if (!self::dontRunif()) {
            return true;
        }

        if (is_feed() || is_admin()) {
            return true;
        }

        if (!empty($_GET['buffer_callback'])) {
            echo 'Buffer CallBack is Working';
            die();
        }

        if (!empty(self::$settings['disable-logged-in-opt']) && self::$settings['disable-logged-in-opt'] == '1' && is_user_logged_in()) {
            return true;
        }

        
        self::$isAjax = (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX);

        
        if (!self::$isAjax) {
            if (is_admin() || !empty($_GET['trp-edit-translation']) || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['elementor-preview']) || !empty($_GET['preview']) || !empty($_GET['PageSpeed']) || !empty($_GET['et_fb']) || !empty($_GET['is-editor-iframe']) || !empty($_GET['tve']) || !empty($_GET['tatsu']) || !empty($_GET['ct_builder']) || !empty($_GET['fb-edit']) || (!empty($_GET['builder']) && !empty($_GET['builder_id'])) || !empty($_GET['bricks']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor') || !empty($_GET['pagelayer-live'])) {
                return true;
            }

            if (!empty($_GET['tatsu']) || !empty($_GET['tatsu-header']) || !empty($_GET['tatsu-footer'])) {
                return true;
            }
        }

        $init = $this->mainInit();


        
        
        if (isset($_GET['cdn']) && (string) $_GET['cdn'] === '0') {
            self::$cdnEnabled = false;
        }

        if (!self::$cdnEnabled && !in_array($_SERVER['PHP_SELF'], ['/wp-login.php', '/wp-register.php'])) {


            if (!empty(self::$settings['live-cdn']) && self::$settings['live-cdn'] == 1
                && apply_filters('wpc_nocache_degraded_cdn_render', true)
                && (
                    !empty($_GET['criticalCombine']) || !empty(wpcGetHeader('criticalCombine'))
                    || (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed())
                )) {
                if (!headers_sent()) {
                    if (function_exists('nocache_headers')) { nocache_headers(); }
                    header('X-LiteSpeed-Cache-Control: no-cache', true);
                }
                if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
                if (function_exists('do_action')) { do_action('litespeed_control_set_nocache', 'wpc: transient CDN-off render'); }
            }
            $this->cdn = new wps_cdn_rewrite();
            add_action('template_redirect', [$this->cdn, 'buffer_local_go']);

            return true;
        }

        if (isset($post->post_type) && strpos($post->post_type, 'wfocu') !== false) {
            
        } else {


            
            if (!empty(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') {
                
                
            }


            if (empty($_GET['wpc_no_buffer'])) {
                ob_start([$this, 'cdnRewriter_wrapped']);
            }
        }
    }

    public function mainInit()
    {

        if (is_admin()) {
            return true;
        }

        
        include_once WPS_IC_DIR . 'integrations/addon/integrations.php';

        $wpcAddonIntegrations = new wpc_addon_integrations();
        if ($wpcAddonIntegrations->wpMaintenance()) {
            return true;
        }

        
        if (defined('WP_CLI') && WP_CLI) {
            
            return true;
        }

        
        if (defined('REST_REQUEST') && REST_REQUEST) {
            
            return true;
        }

        
        if (ini_get('memory_limit') !== '-1' && wpc_convert_to_bytes(ini_get('memory_limit')) < 1024 * 1024 * 1024) {
            ini_set('memory_limit', '1024M');
        }

        
        ini_set('pcre.backtrack_limit', '10000000');

        global $post;
        self::$options = get_option(WPS_IC_OPTIONS);

        if (!isset(self::$options['api_key']) || empty(self::$options['api_key'])) {
            return true;
        }

        
        if ($this->is_home_url()) {
            if (!self::is_mobile()) {
                
            } else {
                
            }
        }

        self::$excludes_class = new wps_ic_excludes();
        self::$isAmp = new wps_ic_amp();
        self::$preloaderAPI = 0;

        self::$settings = get_option(WPS_IC_SETTINGS);

        if ($this->is_home_url()) {
            self::$post_id = 'home';
            self::$page_excludes = isset(self::$excludes['page_excludes']['home']) ? self::$excludes['page_excludes']['home'] : [];
            self::$page_excludes_files = isset(self::$excludes['page_excludes_files']['home']) ? self::$excludes['page_excludes_files']['home'] : [];
        } elseif (!empty(get_queried_object_id())) {
            self::$post_id = get_queried_object_id();
            self::$page_excludes = isset(self::$excludes['page_excludes'][self::$post_id]) ? self::$excludes['page_excludes'][self::$post_id] : [];
            self::$page_excludes_files = isset(self::$excludes['page_excludes_files'][self::$post_id]) ? self::$excludes['page_excludes_files'][self::$post_id] : [];
        } else if (!empty($post->ID)) {
            self::$post_id = $post->ID;
            self::$page_excludes = isset(self::$excludes['page_excludes'][self::$post_id]) ? self::$excludes['page_excludes'][self::$post_id] : [];
            self::$page_excludes_files = isset(self::$excludes['page_excludes_files'][self::$post_id]) ? self::$excludes['page_excludes_files'][self::$post_id] : [];
        } else {
            self::$post_id = false;
            self::$page_excludes = [];
            self::$page_excludes_files = [];
        }

        if (self::$isAmp->isAmp()) {
            self::$lazy_enabled = '0';
            self::$adaptive_enabled = '0';
            self::$retina_enabled = '0';
            self::$settings['delay-js'] = '0';
            self::$settings['inline-js'] = '0';
        }

        $this->criticalCombine = false;
        if (!empty(wpcGetHeader('criticalCombine')) || (!empty($_GET['criticalCombine']) && $_GET['criticalCombine'] == 'true')) {
            $this->criticalCombine = true;
            self::$settings['critical']['css'] = 0;
        }

        if (!empty($_GET['forceRecombine']) && $_GET['forceRecombine'] == 'true') {
            $post_id = get_the_ID();
            $cache = new wps_ic_cache();
            $cache->updateCSSHash($post_id);
            $cache->removeHtmlCacheFiles($post_id);
        }

        self::$findImages = '';
        if (!empty(self::$settings['serve']['jpg']) && self::$settings['serve']['jpg'] == '1') {
            self::$findImages .= 'jpg|jpeg|';
        }

        if (!empty(self::$settings['serve']['png']) && self::$settings['serve']['png'] == '1') {
            self::$findImages .= 'png|';
        }

        if (!empty(self::$settings['serve']['gif']) && self::$settings['serve']['gif'] == '1') {
            self::$findImages .= 'gif|';
        }

        if (!empty(self::$settings['serve']['svg']) && self::$settings['serve']['svg'] == '1') {
            self::$findImages .= 'svg|';
        }

        self::$keys = new wps_ic_url_key();

        self::$findImages .= 'webp|';

        self::$findImages = rtrim(self::$findImages, '|');

        if ((!empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'PreloaderAPI') !== false) || !empty($_GET['dbg_preload'])) {
            self::$preloaderAPI = 1;
        }

        self::$zone_test = 0;
        self::$is_multisite = is_multisite();

        self::$randomHash = 0;

        self::$rewriteLogic = new wps_rewriteLogic();
        self::$minifyHtml = new wps_minifyHtml();
        self::$cacheHtml = new wps_cacheHtml();
        self::$criticalCss = new wps_criticalCss();
        self::$combineCss = new wps_ic_combine_css();

        
        if (self::dontRunif()) {
            $inline_scripts = get_option('wpc-inline');
            if (!empty($inline_scripts['inline_js'])) {
                $this->inline_js = $inline_scripts['inline_js'];
            }
            if (!empty($inline_scripts['inline_css'])) {
                $this->inline_css = $inline_scripts['inline_css'];
            }

            if (!empty(self::$settings['inline-js']) && self::$settings['inline-js'] == 1) {
                if (!empty($this->inline_js)) {
                    foreach ($this->inline_js as $key => $script) {
                        if (substr($script, -3) == '-js') {
                            $this->inline_js[$key] = substr($script, 0, -3);
                        }
                    }
                }
                add_filter('script_loader_tag', [$this, 'add_scripts_inline'], PHP_INT_MAX, 3);
            }
        }

        
        

        
        


        self::$default_excluded_list = ['wp-admin', 'redditstatic', 'ai-uncode', 'gtm', 'instagram.com', 'fbcdn.net', 'twitter', 'google', 'coinbase', 'cookie', 'schema', 'recaptcha', 'data:image', 'stats.jpg'];


        if (apply_filters('wpc_elementor_css_same_origin', true)) {
            self::$default_excluded_list[] = 'elementor/css/';
        }

        
        self::$assets_to_preload = ['themes', 'elementor', 'wp-includes', 'google'];
        self::$assets_to_defer = ['themes', 'tracking', 'fontawesome'];

        if (!empty($_GET['ignore_ic'])) {
            return true;
        }

        if (!empty($_GET['randomHash'])) {
            self::$randomHash = time();
        }

        if (strpos($_SERVER['REQUEST_URI'], '.xml') !== false) {
            return true;
        }

        if (empty(self::$options['css_hash'])) {
            self::$options['css_hash'] = 5021;
        }

        if (empty(self::$options['js_hash'])) {
            self::$options['js_hash'] = 5021;
        }

        if (!defined('WPS_IC_HASH')) {
            define('WPS_IC_HASH', self::$options['css_hash']);
        }

        if (!defined('WPS_IC_JS_HASH')) {
            define('WPS_IC_JS_HASH', self::$options['js_hash']);
        }

        if (!empty(self::$excludes['delay_js'])) {
            $this->delay_js_exclude = self::$excludes['delay_js'];
        } else {
            $this->delay_js_exclude = '';
        }

        $cf = get_option(WPS_IC_CF);
        $cfLive = false;
        if ($cf && isset($cf['settings'])) {
            $cfLive = ($cf['settings']['assets'] == '1' && $cf['settings']['cdn'] == '0');
        }
        $allowLive = get_option('wps_ic_allow_live') && !$cfLive;

        self::$cdnEnabled = self::$settings['live-cdn'];
        if ((isset(self::$page_excludes['cdn']) && self::$page_excludes['cdn'] == '0') || !$allowLive) {
            self::$cdnEnabled = 0;
            self::$settings['css'] = 0;
            self::$settings['js'] = 0;
            self::$settings['serve']['jpg'] = 0;
            self::$settings['serve']['png'] = 0;
            self::$settings['serve']['gif'] = 0;
            self::$settings['serve']['svg'] = 0;
        } else if (isset(self::$page_excludes['cdn']) && self::$page_excludes['cdn'] == '1' && isset(self::$settings['live-cdn']) && self::$settings['live-cdn'] == '1') {


            self::$cdnEnabled = 1;
            self::$settings['css'] = 1;
            self::$settings['js'] = 1;
            self::$settings['serve']['jpg'] = 1;
            self::$settings['serve']['png'] = 1;
            self::$settings['serve']['gif'] = 1;
            self::$settings['serve']['svg'] = 1;
        }


        if (self::$settings['css'] == 0 && self::$settings['js'] == 0 && empty(self::$settings['fonts']) && self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) {
            self::$cdnEnabled = 0;
        }

        if (!empty($_GET['criticalCombine']) || !empty(wpcGetHeader('criticalCombine'))) {
            self::$cdnEnabled = 0;
            self::$settings['css'] = 0;
            self::$settings['js'] = 0;
            self::$settings['serve']['jpg'] = 0;
            self::$settings['serve']['png'] = 0;
            self::$settings['serve']['gif'] = 0;
            self::$settings['serve']['svg'] = 0;
        }

        
        self::$isAjax = (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX);

        
        if (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") {
            return;
        }

        self::$svg_placeholder = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxMDAwIiBoZWlnaHQ9IjEwMCI+PHBhdGggZD0iTTIgMmgxMDAwdjEwMEgyeiIgZmlsbD0iI2ZmZiIgb3BhY2l0eT0iMCIvPjwvc3ZnPg==';

        self::$updir = wp_upload_dir();

        if (!is_multisite()) {
            self::$site_url = site_url();
            self::$home_url = home_url();
        } else {
            $current_blog_id = get_current_blog_id();
            switch_to_blog($current_blog_id);

            self::$site_url = network_site_url();
            self::$home_url = home_url();
        }

        self::$site_url_scheme = parse_url(self::$site_url, PHP_URL_SCHEME);
        self::$lazy_excluded_list = get_option('wpc-ic-lazy-exclude');
        self::$excluded_list = get_option('wpc-ic-external-url-exclude');

        if (!is_array(self::$excluded_list)) {
            self::$external_url_excluded = explode("\n", self::$excluded_list);
        } else {
            self::$external_url_excluded = self::$excluded_list;
        }

        if (defined('BRIZY_VERSION')) {
            self::$brizyCache = get_option('wps_ic_brizy_cache');
            self::$brizyActive = true;
        } else {
            self::$brizyActive = false;
        }

        $cfCname = get_option(WPS_IC_CF_CNAME);
        $cf = get_option(WPS_IC_CF);


        $cfVerified = wpc_cf_cname_verified_ok();
        $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && $cfVerified) ? $cfCname : get_option('ic_custom_cname');

        if (empty($custom_cname) || !$custom_cname) {
            self::$zone_name = get_option('ic_cdn_zone_name');
        } else {
            self::$zone_name = $custom_cname;
        }


        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            self::$zone_name = '';
            self::$cdnEnabled = 0;
        }

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'direct') {
            if (!empty($_GET['custom_server'])
                && function_exists('wpc_cdn_debug_allowed649') && wpc_cdn_debug_allowed649()) {
                $custom_server = sanitize_text_field($_GET['custom_server']);
                if (preg_match('/^[a-z0-9\-]+\.zapwp\.net$/i', $custom_server)) {
                    self::$zone_name = $custom_server . '/key:' . self::$options['api_key'];
                }
            }
        }


        if (!empty(self::$zone_name) && function_exists('home_url')) {
            $wpc_origin_h = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            if ($wpc_origin_h !== '' && strcasecmp((string) self::$zone_name, $wpc_origin_h) === 0) {
                self::$zone_name = '';
            }
        }

        if (empty(self::$zone_name)) {
            return;
        }

        self::$is_retina = '0';
        self::$webp = '0';
        self::$externalUrlEnabled = 'false';

        self::$lazy_enabled = self::$settings['lazy'];
        self::$native_lazy_enabled = self::$settings['nativeLazy'];
        self::$adaptive_enabled = self::$settings['generate_adaptive'];
        self::$webp_enabled = self::$settings['generate_webp'];
        self::$retina_enabled = self::$settings['retina'];


        $wpc_nextgen_ceiling = class_exists('WPC_Delivery_Resolver')
            ? WPC_Delivery_Resolver::effective_ceiling(self::$settings)
            : 'avif';


        $wpc_cdn_images_on = !class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings);


        self::$rewriteLogic::$pictureWebpEnabled = $wpc_nextgen_ceiling !== 'off'
            && !empty(self::$webp_enabled) && self::$webp_enabled == '1'
            && $wpc_cdn_images_on;
        self::$rewriteLogic::$pictureAvifEnabled = $wpc_nextgen_ceiling === 'avif' && $wpc_cdn_images_on;

        
        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            self::$rewriteLogic::$pictureWebpEnabled = false;
        }

        if (isset(self::$page_excludes['adaptive'])) {


            self::$adaptive_enabled = self::$page_excludes['adaptive'];


        }

        if (!empty(self::$settings['replace-all-link'])) {
            self::$replaceAllLinks = self::$settings['replace-all-link'];
        } else {
            self::$replaceAllLinks = '0';
        }

        if (!empty($_GET['disableLazy'])) {
            self::$lazy_enabled = '0';
            self::$native_lazy_enabled = '0';
        }

        if (!empty(self::$settings['external-url'])) {
            self::$externalUrlEnabled = self::$settings['external-url'];
        }

        if (empty(self::$settings['emoji-remove'])) {
            self::$settings['emoji-remove'] = 0;
        }

        if (empty(self::$settings['remove-duplicated-fontawesome'])) {
            self::$settings['remove-duplicated-fontawesome'] = 0;
        }

        if (empty(self::$settings['external-url'])) {
            self::$settings['external-url'] = 0;
        }

        if (empty(self::$settings['css'])) {
            self::$settings['css'] = 0;
        }

        if (empty(self::$settings['fonts'])) {
            self::$settings['fonts'] = 0;
        }

        if (empty(self::$settings['js'])) {
            self::$settings['js'] = 0;
        }

        if (empty(self::$settings['preserve_exif'])) {
            self::$settings['preserve_exif'] = 0;
        }

        if (!empty($_GET['ic_override_setting']) && $_GET['ic_override_setting'] == 'lazy') {
            self::$lazy_enabled = (bool)$_GET['value'];
        }

        if (!empty($_GET['ic_lazy'])) {
            self::$lazy_enabled = (bool)$_GET['ic_lazy'];
            self::$settings['css'] = 1;
            self::$settings['js'] = 1;
        }

        if (!empty($_GET['css'])) {
            self::$settings['css'] = (bool)$_GET['css'];
        }

        if (!empty($_GET['js'])) {
            self::$settings['js'] = (bool)$_GET['js'];
        }

        if (empty(self::$settings['css_image_urls']) || !isset(self::$settings['css_image_urls'])) {
            self::$settings['css_image_urls'] = '0';
        }

        if (!empty(self::$settings['minify-css']) && self::$settings['minify-css']) {
            self::$settings['minify-css'] = '1';
        } else {
            self::$settings['minify-css'] = '0';
        }

        if (!empty(self::$settings['minify-js']) && self::$settings['minify-js']) {
            self::$settings['minify-js'] = '1';
        } else {
            self::$settings['minify-js'] = '0';
        }

        self::$externalUrlEnabled = self::$settings['external-url'];
        self::$css = self::$settings['css'];
        self::$css_img_url = self::$settings['css_image_urls'];
        self::$css_minify = self::$settings['css_minify'];
        self::$js = self::$settings['js'];
        self::$js_minify = self::$settings['js_minify'];
        self::$emoji_remove = self::$settings['emoji-remove'];
        self::$exif = self::$settings['preserve_exif'];
        self::$fonts = self::$settings['fonts'];

        
        if (empty(self::$settings['optimization']) || self::$settings['optimization'] == '' || self::$settings['optimization'] == '0') {
            self::$settings['optimization'] = 'i';
        }

        
        switch (self::$settings['optimization']) {
            case 'intelligent':
                self::$settings['optimization'] = 'i';
                break;
            case 'ultra':
                self::$settings['optimization'] = 'u';
                break;
            case 'lossless':
                self::$settings['optimization'] = 'l';
                break;
        }

        if (!empty(self::$retina_enabled) && self::$retina_enabled == '1') {
            if (isset($_COOKIE["ic_pixel_ratio"])) {
                if ($_COOKIE["ic_pixel_ratio"] >= 2) {
                    self::$is_retina = '1';
                }
            }
        }

        if (!empty(self::$webp_enabled) && self::$webp_enabled == '1') {
            self::$webp = '1';


            if (!self::wpc_universal_picture_on()
                && !empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'Safari') && !strpos($_SERVER['HTTP_USER_AGENT'], 'Chrome')) {
                self::$webp_enabled = false;
                self::$webp = '0';
            }
        }


        if (!empty($_GET['test_zone'])
            && function_exists('wpc_cdn_debug_allowed649') && wpc_cdn_debug_allowed649()
            && preg_match('/^[a-z0-9\-]+$/iD', (string) $_GET['test_zone'])) { 
            if ($_GET['test_zone'] === 'cdn-rage4') {
                $wpc_test_server = isset($_GET['server']) ? (string) $_GET['server'] : '';
                if (preg_match('/^[a-z0-9\-]+$/iD', $wpc_test_server)) {
                    self::$zone_test = 1;
                    self::$zone_name = $wpc_test_server . '.zapwp.net/key:' . self::$options['api_key'];
                }
            } else {
                self::$zone_name = (string) $_GET['test_zone'] . '.wpmediacompress.com/key:' . self::$options['api_key'];
            }
        }

        if (strpos(self::$zone_name, 'bunny') !== false) {
            self::$settings['optimization'] = 'lossless';
        }

        if (!empty(self::$exif) && self::$exif == '1') {
            self::$apiUrl = 'https://' . self::$zone_name . '/q:' . self::$settings['optimization'] . '/e:1';
        } else {
            self::$apiUrl = 'https://' . self::$zone_name . '/q:' . self::$settings['optimization'];
        }

        self::$apiAssetUrl = 'https://' . self::$zone_name . '/a:';

        if (self::$preloaderAPI) {
            global $post;
            self::$lazy_enabled = '0';
            self::$native_lazy_enabled = '0';
            self::$adaptive_enabled = '0';
            self::$retina_enabled = '0';
            self::$settings['remove-render-blocking'] = 0;
            $preloaded_pages = get_option('wpc-ic-preloaded-pages');

            if (is_array($preloaded_pages) && !in_array($post->ID, $preloaded_pages)) {
                array_push($preloaded_pages, $post->ID);
                update_option('wpc-ic-preloaded-pages', $preloaded_pages);
            } else if ($preloaded_pages === false) {
                update_option('wpc-ic-preloaded-pages', [$post->ID]);
            }
        }

        if (!empty($_GET['overwrite_retina'])) {
            self::$retina_enabled = '1';
            self::$is_retina = '1';
        }

        if (!empty($_GET['debugCritical']) || !empty($_GET['generateCriticalAPI'])) {
            add_filter('style_loader_tag', [$this, 'crittr_style_tag'], 10, 4);
        }


        if ((isset(self::$page_excludes['cdn']) && self::$page_excludes['cdn'] == '0') || !$allowLive) {
            self::$cdnEnabled = 0;
            self::$settings['css'] = 0;
            self::$settings['js'] = 0;
            self::$settings['serve']['jpg'] = 0;
            self::$settings['serve']['png'] = 0;
            self::$settings['serve']['gif'] = 0;
            self::$settings['serve']['svg'] = 0;
        } else if (isset(self::$page_excludes['cdn']) && self::$page_excludes['cdn'] == '1' && isset(self::$settings['live-cdn']) && self::$settings['live-cdn'] == '1') {


            self::$cdnEnabled = 1;
            self::$settings['css'] = 1;
            self::$settings['js'] = 1;
            self::$settings['serve']['jpg'] = 1;
            self::$settings['serve']['png'] = 1;
            self::$settings['serve']['gif'] = 1;
            self::$settings['serve']['svg'] = 1;
        }


        if (self::$settings['css'] == 0 && self::$settings['js'] == 0 && empty(self::$settings['fonts']) && self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) {
            self::$cdnEnabled = 0;
        }


        
        if (empty(self::$settings['font-display'])) {
            self::$settings['font-display'] = 'smart';
        }
        
        
        
        self::$fd_raw484 = (string) self::$settings['font-display'];
        if (function_exists('wpc_font_display_effective')) {
            self::$settings['font-display'] = wpc_font_display_effective(self::$settings['font-display']);
        }
        if (self::$settings['font-display'] != 'off') {
            add_filter('style_loader_src', [$this, 'add_font_display_swap_to_url'], 1, 2);
            add_filter('style_loader_src', [$this, 'process_css_for_fonts'], 1, 4);
        }

        if (self::$cdnEnabled == 1) {
            if (self::dontRunif()) {


                if (self::$css == "1") {
                    add_filter('style_loader_src', [$this, 'adjust_src_url'], 10, 2);
                    add_filter('style_loader_tag', [$this, 'adjust_style_tag'], 10, 4);
                    add_action('wp_head', [$this, 'cssOriginFallbackScript'], 0);
                }
                

                if (self::$js == "1") {
                    add_filter('script_loader_tag', [$this, 'rewrite_script_tag'], 10, 3);
                }

                
            }

            add_action("wp_head", [$this, 'dnsPrefetch'], 0);

            
            add_filter('woocommerce_available_variation', [$this, 'rewrite_woo_variation_image_urls'], 10, 3);
        } else {

            
            if (self::dontRunif()) {


                if (self::$css == "1") {
                    add_filter('style_loader_src', [$this, 'adjust_src_url'], 10, 2);
                    add_filter('style_loader_tag', [$this, 'adjust_style_tag'], 10, 4);
                    add_action('wp_head', [$this, 'cssOriginFallbackScript'], 0);
                }

                if (self::$js == "1") {
                    add_filter('script_loader_src', [$this, 'adjust_src_url'], 10, 3);


                    add_filter('script_module_loader_src', [$this, 'adjust_src_url'], 10, 2);
                }
            }

            if (self::$js == "1" || self::$css == "1") {
                add_action("wp_head", [$this, 'dnsPrefetch'], 0);
            }
        }
    }


    public function cssOriginFallbackScript()
    {
        if (!apply_filters('wpc_css_origin_fallback', true)) {
            return;
        }
        $zone = self::$zone_name;
        if (empty($zone)) {
            return;
        }

        $siteOrigin = preg_replace('#^(https?://[^/]+).*$#', '$1', rtrim((string) self::$site_url, '/'));
        if (empty($siteOrigin) || strpos($siteOrigin, 'http') !== 0) {
            return;
        }
        $Z = json_encode((string) $zone, JSON_UNESCAPED_SLASHES);
        $O = json_encode($siteOrigin, JSON_UNESCAPED_SLASHES);
        echo '<script id="wpc-css-orb-fallback">(function(){var Z=' . $Z . ',O=' . $O . ';'
            . 'function toOrigin(h){var i=h.indexOf("/a:");if(i!==-1){var r=h.slice(i+3);return /^https?:\/\//i.test(r)?r:O+(r.charAt(0)==="/"?"":"/")+r;}try{var u=new URL(h);return O+u.pathname+u.search;}catch(e){return null;}}'
            . 'window.addEventListener("error",function(e){var el=e.target;if(!el||el.tagName!=="LINK"||el.rel!=="stylesheet")return;var h=el.href||"";if(h.indexOf(Z)===-1||el.getAttribute("data-wpco"))return;var o=toOrigin(h);if(!o||o===h)return;el.setAttribute("data-wpco","1");var l=document.createElement("link");l.rel="stylesheet";if(el.media)l.media=el.media;l.href=o;el.parentNode.insertBefore(l,el.nextSibling);},true);})();</script>' . "\n";
    }


    public function add_font_display_swap_to_url($src, $handle)
    {
        if (strpos($src, 'fonts.googleapis.com') === false || empty(self::$settings['font-display'])) {
            return $src;
        }
        if (stripos($src, 'display=') !== false) {
            return $src;
        }
        $sep = (strpos($src, '?') === false) ? '?' : '&';
        return $src . $sep . 'display=' . rawurlencode((string) self::$settings['font-display']);
    }


    public static function wpc_gfonts_display_pass($html)
    {
        if (!is_string($html) || $html === '') {
            return $html;
        }
        $wpc_fd = !empty(self::$settings['font-display']) ? (string) self::$settings['font-display'] : 'swap';
        if ($wpc_fd === 'off') {
            return $html;
        }
        if (stripos($html, 'fonts.googleapis.com/css') === false && stripos($html, 'fonts.bunny.net/css') === false) {
            return $html;
        }
        return preg_replace_callback(
            '/(<link\b[^>]*\bhref=)(["\'])(https?:\/\/fonts\.(?:googleapis\.com|bunny\.net)\/css[^"\']*)\2/i',
            function ($m) use ($wpc_fd) {
                $href = $m[3];
                if (stripos($href, 'display=') !== false) {
                    return $m[0];
                }
                if (strpos($href, '?') === false) {
                    $sep = '?';
                } elseif (strpos($href, '&#038;') !== false) {
                    $sep = '&#038;';
                } elseif (strpos($href, '&amp;') !== false) {
                    $sep = '&amp;';
                } else {
                    $sep = '&';
                }
                return $m[1] . $m[2] . $href . $sep . 'display=' . rawurlencode($wpc_fd) . $m[2];
            },
            $html
        );
    }

    





    private static function wpc_font_display_excluded($src, $handle)
    {
        static $excludes = null;
        if ($excludes === null) {
            $opt = get_option('wpc-excludes');
            $excludes = (!empty($opt['font_display']) && is_array($opt['font_display'])) ? $opt['font_display'] : [];
        }
        if (empty($excludes)) {
            return false;
        }
        $haystack = strtolower($src . ' id="' . $handle . '-css"');
        foreach ($excludes as $needle) {
            $needle = strtolower(trim((string) $needle));
            if ($needle !== '' && strpos($haystack, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    public function process_css_for_fonts($src, $handle)
    {
        
        if (strpos($src, '.css') === false) {
            return $src;
        }

        
        $clean_src = strtok($src, '?');
        if (strpos($clean_src, home_url()) === false) {
            return $src;
        }

        if (!defined('WPS_IC_CSS')) {
            return $src;
        }


        if (self::wpc_font_display_excluded($src, $handle)) {
            return $src;
        }


        $wpc_fonts_cdn = self::wpc_fonts_cdn_serve_on796();


        $wpc_font_nat = $wpc_fonts_cdn
            && ((class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on') && wps_rewriteLogic::natural_assets_on())
                || apply_filters('wpc_asset_naturalize_enabled', true));


        $wpc_cache_basis = strtok($src, '?');
        if ($wpc_fonts_cdn) {
            $wpc_subset_key = (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') ? '1' : '0';
            $wpc_cache_basis .= '|wpccf|' . self::$zone_name . '|' . $wpc_subset_key;


            if ($wpc_font_nat) {
                $wpc_cache_basis .= '|wpcfontnat';
            }
        }


        $wpc_svg_cdn = self::wpc_svg_zoneify_active();
        if ($wpc_svg_cdn) {
            
            
            $wpc_cache_basis .= '|wpccss3|' . self::$zone_name;


            if (self::wpc_css_bg_imageset_active()) {
                $wpc_cache_basis .= '|wpcbgis1';
            }
        }
        
        
        
        
        
        $wpc_rrk387 = self::wpc_font_remote_ranges();
        if (!empty($wpc_rrk387)) {
            ksort($wpc_rrk387);
            $wpc_cache_basis .= '|wpcrr2|' . md5(serialize($wpc_rrk387));
            
            
            
            
            
            
            $wpc_sf479 = self::wpc_font_subset_families();
            ksort($wpc_sf479);
            $wpc_cache_basis .= '|wpcsf1|' . md5(implode(',', array_keys($wpc_sf479)));
        }
        $hash = substr(md5($wpc_cache_basis), 0, 10);
        $new_filename = sanitize_file_name($handle . '-' . $hash . '.css');
        $new_filepath = WPS_IC_CSS . '/' . $new_filename;


        clearstatcache(true, $new_filepath);
        if (file_exists($new_filepath) && @filesize($new_filepath) > 0) {
            $new_url = WPS_IC_CSS_URL . '/' . $new_filename;
            return $new_url;
        }
        if (file_exists($new_filepath)) {
            
            
            @unlink($new_filepath);
        }

        
        $css_path = str_replace(home_url(), ABSPATH, $clean_src);
        $css_path = str_replace('/', DIRECTORY_SEPARATOR, $css_path);

        if (!file_exists($css_path) || !is_readable($css_path)) {
            return $src;
        }

        $css_content = @file_get_contents($css_path);

        if (empty($css_content)) {
            return $src;
        }


        $wpc_has_fontface = (stripos($css_content, '@font-face') !== false);
        if (!$wpc_has_fontface && !($wpc_svg_cdn && stripos($css_content, '/wp-content/uploads/') !== false)) {
            return $src;
        }

        
        $css_base_url = dirname($clean_src);

        
        $css_content = preg_replace_callback('/url\s*\(\s*(["\']?)([^"\')]+)\1\s*\)/i', function ($matches) use ($css_base_url) {
            $quote = $matches[1];
            $url = $matches[2];

            
            if (preg_match('/^(https?:|data:|#)/i', $url)) {
                return $matches[0];
            }

            
            if (strpos($url, '//') === 0) {
                $protocol = wpc_request_is_https() ? 'https:' : 'http:';
                return 'url(' . $quote . $protocol . $url . $quote . ')';
            }

            
            if (strpos($url, '/') === 0) {
                return 'url(' . $quote . home_url($url) . $quote . ')';
            }

            
            
            if (strpos($url, './') === 0) {
                $url = substr($url, 2);
            }

            
            $absolute_url = $css_base_url . '/' . $url;

            
            while (strpos($absolute_url, '/../') !== false) {
                $absolute_url = preg_replace('/\/[^\/]+\/\.\.\//', '/', $absolute_url);
            }

            return 'url(' . $quote . $absolute_url . $quote . ')';
        }, $css_content);


        if ($wpc_fonts_cdn) {
            $wpc_subsetting = (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1');
            $wpc_site_host = wp_parse_url(home_url(), PHP_URL_HOST);
            $wpc_zone = (string) self::$zone_name;


            $css_content = preg_replace('#/\*.*?\*/#s', '', $css_content);
            $css_content = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($block) use ($wpc_subsetting, $wpc_site_host, $wpc_zone, $wpc_font_nat) {
                $rule = $block[0];


                $family_is_icon = false;
                if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $rule, $fam)) {
                    if (preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple.?line/i', strtolower(trim($fam[1])))) {
                        $family_is_icon = true;
                    }
                }
                return preg_replace_callback('/url\s*\(\s*(["\']?)(https?:[^"\')]+\.(?:woff2|woff|eot|ttf)(?:[?#][^"\')]*)?)\1\s*\)/i', function ($m) use ($wpc_subsetting, $wpc_site_host, $wpc_zone, $family_is_icon, $wpc_font_nat) {
                    $url = $m[2];
                    
                    if ($wpc_zone !== '' && strpos($url, $wpc_zone) !== false) {
                        return $m[0];
                    }


                    if ($wpc_zone === '' || ($wpc_site_host !== '' && strcasecmp((string) $wpc_zone, (string) $wpc_site_host) === 0)) {
                        return $m[0];
                    }


                    $host = wp_parse_url($url, PHP_URL_HOST);
                    if (empty($host) || empty($wpc_site_host) || strcasecmp($host, $wpc_site_host) !== 0) {
                        return $m[0];
                    }


                    $u_path = (string) wp_parse_url($url, PHP_URL_PATH);
                    if (stripos($u_path, '/wp-content/') === false) {
                        return $m[0];
                    }
                    
                    
                    $lower = strtolower($url);
                    $url_is_icon = (strpos($lower, 'icon') !== false || strpos($lower, 'awesome') !== false || strpos($lower, 'lightgallery') !== false || strpos($lower, 'gallery') !== false || strpos($lower, 'side-cart-woocommerce') !== false);
                    if ($wpc_subsetting && !$family_is_icon && !$url_is_icon) {

                        $cdn_url = 'https://' . $wpc_zone . '/font:true/a:' . wps_cdn_rewrite::reformat_url($url);
                    } elseif ($wpc_font_nat) {
                        
                        
                        $wpc_fnt_abs = wps_cdn_rewrite::reformat_url($url);
                        $wpc_fnt_pp = wp_parse_url($wpc_fnt_abs);
                        if (is_array($wpc_fnt_pp) && !empty($wpc_fnt_pp['path'])) {
                            $cdn_url = 'https://' . $wpc_zone . $wpc_fnt_pp['path'] . (isset($wpc_fnt_pp['query']) ? '?' . $wpc_fnt_pp['query'] : '');
                        } else {
                            $cdn_url = 'https://' . $wpc_zone . '/m:0/a:' . $wpc_fnt_abs;
                        }
                    } else {
                        $cdn_url = 'https://' . $wpc_zone . '/m:0/a:' . wps_cdn_rewrite::reformat_url($url);
                    }
                    return 'url(' . $m[1] . $cdn_url . $m[1] . ')';
                }, $rule);
            }, $css_content);
        }

        
        $iconFontDisplay = !empty(self::$settings['icon-font-display']) ? self::$settings['icon-font-display'] : 'block';
        $css_content = preg_replace_callback('/(@font-face\s*\{)([^}]*)(})/is', function ($matches) use ($iconFontDisplay) {
            $content = $matches[2];

            
            $content = preg_replace('/font-display\s*:\s*[^;]+;?/i', '', $content);

            
            $fontDisplayValue = self::$settings['font-display'] ?? 'swap';
            if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $content, $familyMatch)) {
                $family = strtolower(trim($familyMatch[1]));
                
                
                if (function_exists('wpc_css_is_icon_font')
                    ? wpc_css_is_icon_font($family)
                    : preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple.?line/i', $family)) {
                    $fontDisplayValue = $iconFontDisplay;
                } elseif (self::$fd_raw484 !== '' && function_exists('wpc_font_display_effective')) {
                    
                    
                    
                    
                    $wpc_fdf484 = wpc_font_display_effective(self::$fd_raw484, $family);
                    if (in_array($wpc_fdf484, ['swap', 'block', 'auto', 'optional', 'fallback'], true)) {
                        $fontDisplayValue = $wpc_fdf484;
                    }
                }
            }


            
            
            
            
            
            if (!preg_match('/unicode-range\s*:/i', $content)) {
                $wpc_rrm = self::wpc_font_remote_ranges();
                if (!empty($wpc_rrm) && !empty($familyMatch[1])) {
                    $wpc_fw = 400; $wpc_fs = 'normal';
                    if (preg_match('/font-weight\s*:\s*(\d{2,4})/i', $content, $wm)) { $wpc_fw = (int) $wm[1]; }
                    if (preg_match('/font-style\s*:\s*italic/i', $content)) { $wpc_fs = 'italic'; }
                    $wpc_famk478 = strtolower(trim($familyMatch[1], " \t\"'"));
                    $wpc_rk = $wpc_famk478 . '|' . $wpc_fw . '|' . $wpc_fs;
                    if (!empty($wpc_rrm[$wpc_rk])) {
                        
                        
                        
                        
                        
                        
                        
                        
                        
                        
                        
                        
                        
                        if (function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($wpc_famk478)) {
                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('font-gate-iconfont', '', '', [
                                    'family' => substr($wpc_famk478, 0, 28), 'src' => 'css-file',
                                ]);
                            }
                            return $matches[1] . $content . ';font-display:' . $fontDisplayValue . ';' . $matches[3];
                        }
                        $wpc_subf478 = self::wpc_font_subset_families();
                        if (!empty($wpc_subf478[$wpc_famk478])) {
                            $content .= ';unicode-range:' . $wpc_rrm[$wpc_rk];
                        } elseif (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('font-gate-unpaired', '', '', [
                                'family' => substr($wpc_famk478, 0, 28),
                                'why'    => 'remote_range present but NO inline subset face — gate withheld',
                            ]);
                        }
                    }
                }
            }
            return $matches[1] . $content . ';font-display:' . $fontDisplayValue . ';' . $matches[3];
        }, $css_content);

        
        if (!file_exists(WPS_IC_CSS)) {
            wp_mkdir_p(WPS_IC_CSS);
        }

        
        if ($wpc_svg_cdn) {
            $css_content = self::wpc_svg_zoneify($css_content);


            $css_content = self::wpc_raster_naturalize($css_content);
            $wpc_css_origin = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($wpc_css_origin && strcasecmp((string) self::$zone_name, $wpc_css_origin) !== 0) {


                $o = preg_quote($wpc_css_origin, '#');


                $css_content = preg_replace_callback(
                    '#(background(?:-image)?\s*:\s*[^;{}]*?url\(\s*)([\'"]?)https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?)\.(png|jpe?g)((?:\?[^"\'()\s<>]*)?)\2(\s*\))(?=\s*(?:[;}\\\\]|[\'"]|$))(?!\s*;\s*background-image\s*:\s*[^;{}]*?(?:-webkit-)?image-set)#i',
                    function ($m) use ($wpc_css_origin) {
                        
                        if (stripos($m[0], 'image-set(') !== false) return $m[0];
                        $sameext_zone = 'https://' . self::$zone_name . $m[3] . '.' . $m[4] . $m[5];
                        
                        
                        $wpc_pl822 = self::wpc_css_bg_prior_layers($m[1]);
                        if (!empty($wpc_pl822['skip'])) return $m[0];
                        $origin_url   = 'https://' . $wpc_css_origin . $m[3] . '.' . $m[4] . $m[5];
                        $iset = self::wpc_css_bg_imageset_build($origin_url, $sameext_zone, $m[2]);
                        if ($iset !== '') {
                            if ($wpc_pl822['layers'] !== '') {
                                $iset = str_replace('background-image:', 'background-image:' . $wpc_pl822['layers'], $iset);
                            }
                            return $iset;
                        }
                        
                        return $m[1] . $m[2] . $sameext_zone . $m[2] . $m[6];
                    },
                    $css_content
                );


                $css_bg_edge_webp = (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active());
                $css_content = preg_replace_callback(
                    '#https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?)\.(png|jpe?g|gif)((?:\?[^"\'()\s<>]*)?)#i',
                    function ($m) use ($css_bg_edge_webp) {


                        $ext = strtolower($m[2]);
                        
                        
                        if ($ext === 'gif' && !(class_exists('wps_rewriteLogic') && wps_rewriteLogic::cf_is_delivery())) {
                            return $m[0];
                        }
                        if ($css_bg_edge_webp && $ext !== 'gif') {
                            return 'https://' . self::$zone_name . $m[1] . '.webp' . $m[3];
                        }

                        return 'https://' . self::$zone_name . $m[1] . '.' . $m[2] . $m[3];
                    },
                    $css_content
                );
                
                $css_content = preg_replace(
                    '#https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?\.(?:webp|avif)(?:\?[^"\'()\s<>]*)?)#i',
                    'https://' . self::$zone_name . '$1',
                    $css_content
                );
            }
        }


        if ($wpc_fonts_cdn
            && class_exists('wps_rewriteLogic')
            && method_exists('wps_rewriteLogic', 'natural_assets_on')
            && wps_rewriteLogic::natural_assets_on()) {
            $css_content = wps_rewriteLogic::naturalize_asset_urls($css_content);
        }


        $wpc_pid = function_exists('getmypid') ? getmypid() : 0;
        $wpc_tmp_path = $new_filepath . '.' . $wpc_pid . '.' . substr(md5(uniqid('', true)), 0, 8) . '.tmp';
        $wpc_bytes = @file_put_contents($wpc_tmp_path, $css_content);
        if ($wpc_bytes === false || $wpc_bytes <= 0) {
            if (file_exists($wpc_tmp_path)) {
                @unlink($wpc_tmp_path);
            }
            
            if (file_exists($new_filepath) && @filesize($new_filepath) > 0) {
                return WPS_IC_CSS_URL . '/' . $new_filename;
            }
            return $src;
        }
        if (!@rename($wpc_tmp_path, $new_filepath)) {
            @unlink($wpc_tmp_path);
            if (file_exists($new_filepath) && @filesize($new_filepath) > 0) {
                return WPS_IC_CSS_URL . '/' . $new_filename;
            }
            return $src;
        }

        
        
        clearstatcache(true, $new_filepath);
        if (!file_exists($new_filepath) || @filesize($new_filepath) <= 0) {
            return $src;
        }

        $new_url = WPS_IC_CSS_URL . '/' . $new_filename;
        return $new_url;
    }


    
    
    
    public static function wpc_fonts_cdn_serve_on796()
    {
        return apply_filters('wpc_fonts_cdn_serve', (bool) get_site_option('wpc_fonts_cdn_serve', true))
            && !empty(self::$settings['live-cdn']) && self::$settings['live-cdn'] == '1'
            && !empty(self::$settings['fonts']) && self::$settings['fonts'] == '1'
            && !empty(self::$zone_name)
            && (empty(self::$settings['css_combine']) || self::$settings['css_combine'] != '1');
    }

    
    
    
    public static function wpc_font_preload_url_form796($url)
    {
        if (!is_string($url) || $url === '' || strpos($url, '/a:') === false) {
            return $url;
        }
        if (!apply_filters('wpc_font_preload_naturalize', true)
            || !self::wpc_fonts_cdn_serve_on796()
            || !class_exists('wps_rewriteLogic')
            || !method_exists('wps_rewriteLogic', 'natural_assets_on')
            || !method_exists('wps_rewriteLogic', 'naturalize_asset_urls')
            || !wps_rewriteLogic::natural_assets_on()) {
            return $url;
        }
        $wpc_n796 = wps_rewriteLogic::naturalize_asset_urls($url);
        return (is_string($wpc_n796) && $wpc_n796 !== '') ? $wpc_n796 : $url;
    }

    public static function rewrite_fontface_css($css, $zone, $subsetting, $site_host)
    {
        $zone = (string) $zone;
        if ($zone === '' || strpos($css, '@font-face') === false) return $css;
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        return preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($block) use ($subsetting, $site_host, $zone) {
            $rule = $block[0];
            $family_is_icon = false;
            if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $rule, $fam)) {
                if (preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple.?line/i', strtolower(trim($fam[1])))) {
                    $family_is_icon = true;
                }
            }
            return preg_replace_callback('/url\s*\(\s*(["\']?)(https?:[^"\')]+\.(?:woff2|woff|eot|ttf)(?:[?#][^"\')]*)?)\1\s*\)/i', function ($m) use ($subsetting, $site_host, $zone, $family_is_icon) {
                $url = $m[2];
                if (strpos($url, $zone) !== false) return $m[0];
                $host = wp_parse_url($url, PHP_URL_HOST);
                if (empty($host) || empty($site_host) || strcasecmp($host, $site_host) !== 0) return $m[0];


                if (stripos((string) wp_parse_url($url, PHP_URL_PATH), '/wp-content/') === false) return $m[0];


                if (stripos($url, '/cache/wp-cio-fonts/') !== false) return $m[0];
                $lower = strtolower($url);
                $url_is_icon = (strpos($lower, 'icon') !== false || strpos($lower, 'awesome') !== false || strpos($lower, 'lightgallery') !== false || strpos($lower, 'gallery') !== false || strpos($lower, 'side-cart-woocommerce') !== false);
                if ($subsetting && !$family_is_icon && !$url_is_icon) {
                    $cdn = 'https://' . $zone . '/font:true/a:' . self::reformat_url($url);
                } else {
                    $cdn = 'https://' . $zone . '/m:0/a:' . self::reformat_url($url);
                }
                return 'url(' . $m[1] . $cdn . $m[1] . ')';
            }, $rule);
        }, $css);
    }

    public function preload_custom_assetsMobile($output = 'array', $html = '')
    {
        $alreadyPreloaded = [];
        $preloads = get_option('wps_ic_preloadsMobile');
        $preloadOutput = '';
        $preloadOutputArray = [];

        if (!empty($preloads) && is_array($preloads)) {
            $allPreloadUrls = [];

            
            if (!empty($preloads['lcp']) && is_array($preloads['lcp'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['lcp']);
            }

            if (!empty($preloads['custom']) && is_array($preloads['custom'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['custom']);
            }

            
            foreach ($allPreloadUrls as $preloadItem) {
                if (empty($preloadItem)) continue; 

                
                $fullUrl = $this->extractUrlFromHtml($preloadItem, $html);
                if (empty($fullUrl)) {
                    continue;
                }

                $extra = '';
                $type = '';

                
                $parsedUrl = parse_url($fullUrl);
                $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : $fullUrl;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                switch ($ext) {
                    case 'css':
                        $as = 'style';
                        $type = 'text/css';
                        break;
                    case 'js':
                        $as = 'script';
                        $type = 'text/javascript';
                        break;
                    case 'woff':
                    case 'woff2':
                    case 'ttf':
                    case 'otf':
                        $extra = 'crossorigin';
                        $as = 'font';
                        if ($ext == 'woff' || $ext == 'woff2') {
                            $type = 'font/woff2';
                        } else {
                            $type = 'font/' . $ext;
                        }
                        break;
                    case 'jpg':
                    case 'jpeg':
                    case 'png':
                    case 'gif':
                    case 'webp':
                    case 'svg':
                    case 'avif':
                        $as = 'image';
                        if ($ext == 'jpg' || $ext == 'jpeg') {
                            $type = 'image/jpeg';
                        } else if ($ext == 'gif') {
                            $type = 'image/gif';
                        } else if ($ext == 'png') {
                            $type = 'image/png';
                        } else if ($ext == 'webp') {
                            $type = 'image/webp';
                        } else if ($ext == 'svg') {
                            $type = 'image/svg+xml';
                        } else if ($ext == 'avif') {
                            $type = 'image/avif';
                        }
                        break;
                    default:
                        $as = '';
                        break;
                }

                if (!empty($as)) {
                    if (!in_array(esc_url($fullUrl), $alreadyPreloaded)) {
                        $alreadyPreloaded[] = esc_url($fullUrl);
                        $preloadOutput = '<link rel="preload" href="' . esc_url($fullUrl) . '" as="' . esc_attr($as) . '" type="' . $type . '"';

                        if (!empty(self::$settings['fetchpriority-high']) && self::$settings['fetchpriority-high'] == '1') {
                            $preloadOutput .= ' fetchpriority="high"';
                        }

                        if (!empty($extra)) {
                            $preloadOutput .= ' ' . $extra;
                        }

                        $preloadOutput .= '/>' . "\n";
                        $preloadOutputArray[] = $preloadOutput;
                    }
                }
            }
        }

        if ($output == 'array') {
            return $preloadOutputArray;
        } else {
            $finalOutput = '';
            if (!empty($preloadOutputArray)) {
                foreach ($preloadOutputArray as $link) {
                    $finalOutput .= $link;
                }
            }
            return $finalOutput;
        }
    }

    


    private function extractUrlFromHtml($resource, $html)
    {
        if (empty($resource) || empty($html)) {
            return $resource;
        }

        
        $escapedResource = preg_quote($resource, '/');

        
        
        $patterns = ['/(?:href|src|content)=["\']([^"\']*' . $escapedResource . '[^"\']*)["\']/i', '/url\(["\']?([^"\')]*' . $escapedResource . '[^"\')]*)["\']?\)/i'];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return trim($matches[1]);
            }
        }

        return false;
    }

    public function preload_custom_assets($output = 'array', $html = '')
    {
        $alreadyPreloaded = [];
        $preloads = get_option('wps_ic_preloads');
        $preloadOutput = '';
        $preloadOutputArray = [];

        if (!empty($preloads) && is_array($preloads)) {
            $allPreloadUrls = [];

            
            if (!empty($preloads['lcp']) && is_array($preloads['lcp'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['lcp']);
            }

            if (!empty($preloads['custom']) && is_array($preloads['custom'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['custom']);
            }

            
            foreach ($allPreloadUrls as $preloadItem) {
                if (empty($preloadItem)) continue; 

                
                $fullUrl = $this->extractUrlFromHtml($preloadItem, $html);
                if (empty($fullUrl)) {
                    continue;
                }

                $extra = '';
                $type = '';

                
                $parsedUrl = parse_url($fullUrl);
                $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : $fullUrl;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

                switch ($ext) {
                    case 'css':
                        $as = 'style';
                        $type = 'text/css';
                        break;
                    case 'js':
                        $as = 'script';
                        $type = 'text/javascript';
                        break;
                    case 'woff':
                    case 'woff2':
                    case 'ttf':
                    case 'otf':
                        $extra = 'crossorigin';
                        $as = 'font';
                        if ($ext == 'woff' || $ext == 'woff2') {
                            $type = 'font/woff2';
                        } else {
                            $type = 'font/' . $ext;
                        }
                        break;
                    case 'jpg':
                    case 'jpeg':
                    case 'png':
                    case 'gif':
                    case 'webp':
                    case 'svg':
                    case 'avif':
                        $as = 'image';
                        if ($ext == 'jpg' || $ext == 'jpeg') {
                            $type = 'image/jpeg';
                        } else if ($ext == 'gif') {
                            $type = 'image/gif';
                        } else if ($ext == 'png') {
                            $type = 'image/png';
                        } else if ($ext == 'webp') {
                            $type = 'image/webp';
                        } else if ($ext == 'svg') {
                            $type = 'image/svg+xml';
                        } else if ($ext == 'avif') {
                            $type = 'image/avif';
                        }
                        break;
                    default:
                        $as = '';
                        break;
                }

                if (!empty($as)) {
                    if (!in_array(esc_url($fullUrl), $alreadyPreloaded)) {
                        $alreadyPreloaded[] = esc_url($fullUrl);
                        $preloadOutput = '<link rel="preload" href="' . esc_url($fullUrl) . '" as="' . esc_attr($as) . '" type="' . $type . '"';

                        if (!empty(self::$settings['fetchpriority-high']) && self::$settings['fetchpriority-high'] == '1') {
                            $preloadOutput .= ' fetchpriority="high"';
                        }

                        if (!empty($extra)) {
                            $preloadOutput .= ' ' . $extra;
                        }

                        $preloadOutput .= '/>';
                        $preloadOutputArray[] = $preloadOutput;
                    }
                }
            }
        }

        if ($output === 'array') {
            return $preloadOutputArray;
        } else {
            $finalOutput = '';
            if (!empty($preloadOutputArray)) {
                foreach ($preloadOutputArray as $link) {
                    $finalOutput .= $link;
                }
            }
            return $finalOutput;
        }
    }

    public function perfMattersOverride()
    {
        if (function_exists('perfmatters_version_check')) {
            $perfmatters_options = get_option('perfmatters_options');

            if (!empty($perfmatters_options['assets']['delay_js']) && $perfmatters_options['assets']['delay_js']) {
                self::$delay_js_override = 1;
            }

            if (!empty($perfmatters_options['assets']['defer_js']) && $perfmatters_options['assets']['defer_js']) {
                self::$defer_js_override = 1;
            }

            if (!empty($perfmatters_options['lazyload']['lazy_loading']) && $perfmatters_options['lazyload']['lazy_loading']) {
                self::$lazy_override = 1;
            }
        }
    }

    public function rocketOverride()
    {
        if (function_exists('get_rocket_option')) {
            $rocket_settings = get_option('wp_rocket_settings');

            if ($rocket_settings['delay_js']) {
                self::$delay_js_override = 1;
            }

            if ($rocket_settings['defer_all_js']) {
                self::$defer_js_override = 1;
            }

            if ($rocket_settings['lazyload']) {
                self::$lazy_override = 1;
            }
        }
    }

    public function script_encode($html)
    {
        $html = base64_encode($html[0]);

        return '[script-wpc]' . $html . '[/script-wpc]';
    }

    public function script_decode($html)
    {
        $html = base64_decode($html[1]);

        return $html;
    }

    public function noscript_encode($html)
    {
        $html = base64_encode($html[0]);
        return '[noscript-wpc]' . $html . '[/noscript-wpc]';
    }

    public function noscript_decode($html)
    {
        $html = base64_decode($html[1]);

        
        if ($html === false) {
            return ''; 
        }

        return $html; 
    }

    public function jetsmart_ajax_rewrite($args)
    {
        $html = $args['content'];

        
        $escapedSiteURL = quotemeta(self::$home_url);
        $regExURL = '(https?:|)' . substr($escapedSiteURL, strpos($escapedSiteURL, '//'));

        
        $directories = 'wp\-content|wp\-includes';
        if (!empty($cdn['cdn_directories'])) {
            $directoriesArray = array_map('trim', explode(',', $cdn['cdn_directories']));

            if (count($directoriesArray) > 0) {
                $directories = implode('|', array_map('quotemeta', array_filter($directoriesArray)));
            }
        }

        $old_values['lazy'] = self::$lazy_enabled;
        $old_values['adaptive'] = self::$adaptive_enabled;

        self::$lazy_enabled = 0;
        self::$adaptive_enabled = 0;

        $regEx = '#(?<=url\(|[\"\'])(?:' . $regExURL . ')?/(?:((?:' . $directories . ')[^\"\')]+)|([^/\"\']+\.[^/\"\')]+))(?=[\"\')])#';
        $html = preg_replace_callback($regEx, [$this, 'cdn_rewrite_url'], $html, true);

        self::$lazy_enabled = $old_values['lazy'];
        self::$adaptive_enabled = $old_values['adaptive'];

        $args['content'] = $html;

        return $args;
    }

    public function saveCache($html)
    {

        if (empty(self::$cacheHtml)) {

            return $html;
        }

        $cacheActive = !(isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '0') && ((isset(self::$settings['cache']['advanced']) && self::$settings['cache']['advanced'] == '1') || (isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '1'));

        if ($cacheActive) {
            if ((!self::isExcludedFromCache($html) && $this->doCacheCombine())) {
                $prefix = '';
                if (self::is_mobile()) $prefix .= 'mobile';
                if (self::is_webp_request() && apply_filters('wpc_webp_cache_variant', false)) { $prefix .= ($prefix ? '-' : '') . 'webp'; }

                return self::$cacheHtml->saveCache($html, $prefix);
            }
        }
        return $html;
    }

    public static function isExcludedFromCache($html)
    {
        $output = [];

        if ((strpos($html, 'id="wp-admin-bar') !== false || strpos($html, "id='wp-admin-bar") !== false) || (strpos($html, 'id="wpadminbar"') !== false || strpos($html, "id='wpadminbar'") !== false)) {
            return true;
        }

        if (isset(self::$excludes['cache'])) {
            if (!is_array(self::$excludes['cache'])) {
                $excludedUrls = explode("\n", self::$excludes['cache']);
            } else {
                $excludedUrls = self::$excludes['cache'];
            }


            if (!empty($excludedUrls)) {
                foreach ($excludedUrls as $k => $path) {
                    if (!empty($path)) {
                        $path = trim($path);
                        if (strpos($_SERVER['REQUEST_URI'], $path) !== false) {
                            return true;
                        }
                    }
                }
            }
        }

        
        if (class_exists('WooCommerce')) {
            if (is_cart() || is_checkout()) {
                return true;
            }
        }

        return false;
    }

    public static function is_webp_request()
    {
        return isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'image/webp') !== false;
    }


    public static function wpc_universal_picture_on()
    {
        static $on = null;
        if ($on !== null) return $on;
        if (defined('WPC_UNIVERSAL_PICTURE_OFF') && WPC_UNIVERSAL_PICTURE_OFF) return $on = false;
        $opt = function_exists('get_option') ? get_option('wpc_universal_picture', '1') : '1';
        return $on = (bool) apply_filters('wpc_universal_picture', ($opt === '1' || $opt === 1 || $opt === true));
    }

    public function cdnRewriter($html)
    {
        $wpc_ps531 = class_exists('Wpc_Prof_Span530') ? new Wpc_Prof_Span530('pass:cdnRewriter') : null;


        if (!empty($_GET['forceCritical']) && function_exists('current_user_can') && current_user_can('manage_options')) {
            $urlKey = new wps_ic_url_key();
            $requests = new wps_ic_requests();
            $postID = get_queried_object_id();
            $url = get_permalink($postID);
            $url_key = $urlKey->setup($url);
            $args = ['url' => (function_exists('wpc_canon_url609') ? wpc_canon_url609($url) : $url) . '?criticalCombine=true&testCompliant=true', 'source' => 'rewrite', 'version' => '6.60.60', 'async' => 'false', 'dbg' => 'true', 'hash' => time() . mt_rand(100, 9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];
            if (function_exists('wpc_sanity_escalate622')) {
                $args = wpc_sanity_escalate622($args, $url);
            }


            
            $call = $requests->POST(self::$apiUrl, $args, ['timeout' => 2, 'blocking' => false, 'headers' => array('Content-Type' => 'application/json')]);

            return print_r(['key' => $url_key, 'url' => $url, 'call' => $call], true);
        }


        $html = wpc_heal_mixed_content($html);


        if (class_exists('WPC_Negotiated_Delivery')
            && (WPC_Negotiated_Delivery::is_active() || WPC_Negotiated_Delivery::is_active_jpeg())) {
            $wpcNdMask = [];
            $html = wps_rewriteLogic::maskMediaScripts($html, $wpcNdMask);
            $html = WPC_Negotiated_Delivery::rewrite_buffer($html);
            $html = wps_rewriteLogic::unmaskMediaScripts($html, $wpcNdMask);


            if (class_exists('wps_rewriteLogic')) {
                wps_rewriteLogic::$pictureWebpEnabled = false;
            }
        }

        self::$wpcPreloadLinks = [];

        $isUserLoggedIn = is_user_logged_in();
        $isVisitorMode = false;
        if (!empty($_GET['wpc_visitor_mode']) && $_GET['wpc_visitor_mode']) {
            $isVisitorMode = $_GET['wpc_visitor_mode'];
        }

        $criticalCombine = false;
        if (!empty($_GET['criticalCombine']) || !empty(wpcGetHeader('criticalCombine'))) {
            $criticalCombine = true;
        }

        if (!empty($_GET['no_rewriter'])) {
            return 'no-cdn-rewriter';
        }

        if (!empty($_GET['ignore_ic'])) {
            return $html;
        }

        


        if (isset($_GET['wc-ajax']) || isset($_GET['product_sku']) || !empty($_POST['product_sku'])) {
            return $html;
        }

        


        if (!empty($_GET['action']) && $_GET['action'] == 'get_wdtable') {
            return $html;
        }

        if (is_feed()) {
            return $html;
        }

        if (self::$isAjax) {
            return $html;
        }

        if (strpos($_SERVER['REQUEST_URI'], 'xmlrpc') !== false || strpos($_SERVER['REQUEST_URI'], 'wp-json') !== false) {
            return $html;
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'wps_ic_amp') {
            return $html;
        }


        self::$isAmp = new wps_ic_amp();
        $combine_css = new wps_ic_combine_css();

        if (self::$isAmp->isAmp($html)) {
            self::$lazy_enabled = '0';
            self::$adaptive_enabled = '0';
            self::$retina_enabled = '0';
            self::$settings['delay-js'] = '0';
            self::$settings['inline-js'] = '0';
            self::$rewriteLogic::$pictureWebpEnabled = false; 
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'action') {
            return $html;
        }


        
        
        if (!empty($_POST['action'])) {


            if (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
                $wpcAjaxMask = [];
                $html = wps_rewriteLogic::maskMediaScripts($html, $wpcAjaxMask);
                $html = preg_replace_callback('/(?<![\"|\'])<img[^>]*>/i', [self::$rewriteLogic, 'replaceImageTagsDoSlash'], $html);
                $html = wps_rewriteLogic::unmaskMediaScripts($html, $wpcAjaxMask);
            }

            return $html;
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'wpc_disableCommentClear') {
            return $html;
        }

        if (empty($_GET['wpc_disableCommentClear'])) {

            $html = preg_replace("/<!--->/ms", '', $html);
            $html = preg_replace_callback("/<!--(.*?)-->/ms", function ($matches) {
                if (strpos($matches[1], 'sc_project') !== false || strpos($matches[1], 'et-ajax') !== false) {

                    return $matches[0];
                } else {
                    return '';
                }
            }, $html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'scriptContent') {
            return $html;
        }


        
        $this->getRegexp();

        if (empty($_GET['wpc_disableStrip'])) {
            $html = self::$rewriteLogic->scriptContent($html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'replace_iframe_tags') {
            return $html;
        }

        
        $wpcScriptMask = [];
        $html = wps_rewriteLogic::maskMediaScripts($html, $wpcScriptMask);

        
        
        
        
        
        
        if ((!empty(self::$settings['iframe-lazy']) && self::$settings['iframe-lazy'] == '1'
                || self::wpc_facade_aggr_ok())
            && !$isUserLoggedIn) {
            $html = preg_replace_callback('/<iframe[^>]*>(.*?)<\/iframe>/si', [$this, 'replace_iframe_tags'], $html);
            $html = preg_replace_callback('/<source([^>]*)\ssrc=["\']([^"\']+)["\']/i', [$this, 'replace_source_tags'], $html);
        }

        
        if (!empty(self::$settings['video-preload-none']) && self::$settings['video-preload-none'] == '1' && !$isUserLoggedIn) {
            $html = preg_replace_callback('/<video\b([^>]*)>/i', function ($matches) {
                $attrs = $matches[1];
                if (preg_match('/\bpreload\s*=/i', $attrs)) {
                    return $matches[0];
                }
                return '<video' . $attrs . ' preload="none">';
            }, $html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'encode_iframe') {
            return $html;
        }

        if (!$isUserLoggedIn) {
            $html = self::$rewriteLogic->encodeIframe($html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'crittr_replace_css') {
            return $html;
        }

        if ((!empty($_GET['debugCritical']) || !empty($_GET['generateCriticalAPI']))) {
            $isUserLoggedIn = is_user_logged_in();
            $html = preg_replace_callback('/<link\b[^>]*>/si', [$this, 'crittr_replace_css'], $html);
        }

        $html = preg_replace_callback('/<noscript><iframe.*?<\/noscript>/is', [$this, 'noscript_encode'], $html);

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'backgroundSizing') {
            return $html;
        }

        
        if (!empty(self::$settings['background-sizing']) && self::$settings['background-sizing'] == '1') {
            $html = self::$rewriteLogic->backgroundSizing($html);
        } else {


            $html = self::$rewriteLogic->backgroundSlideshowOnly($html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'replaceImageTags') {
            return $html;
        }


        if (!empty($_GET['debug_preload_inject'])) {
            $dbg = 'Before:';
            $dbg .= $html;
        }


        $html = preg_replace_callback('/<head\b[^>]*>(?:\s*<meta[^>]*\bcharset\b[^>]*>)?/is', [$this, 'injectPreloadImages'], $html, 1);

        if (!empty($_GET['debug_preload_inject'])) {
            $dbg .= 'After:';
            $dbg .= $html;

            return $dbg;
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'wpFontsLocal') {
            return $html;
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'replaceImageTags0') {
            return $html;
        }

        $html = self::$rewriteLogic->defferFontAwesome($html);

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'setImageSize') {
            return $html;
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'removeTemplates') {
            return $html;
        }


        if (!empty(self::$settings['remove-duplicated-fontawesome'])) {
            $html = $this->removeDuplicatedFontawesome($html);
        }


        $removedTemplates = $this->removeTemplates($html);
        $html = $removedTemplates['html'];

        $html = preg_replace_callback('/<img[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/si', [$this, 'set_image_sizes'], $html);
        $html = preg_replace_callback('/<picture>.*?<\/picture>/is', [$this, 'set_image_sizes'], $html);

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'replaceImageTags1') {
            return $html;
        }

        
        $wpcPictureBlocks = [];
        if (self::$rewriteLogic::$pictureWebpEnabled) {
            $html = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$wpcPictureBlocks) {
                $i = count($wpcPictureBlocks);
                $wpcPictureBlocks[$i] = $m[0];
                return '<!--WPC_PICTURE_' . $i . '-->';
            }, $html);
        }


        if (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
            $html = self::$rewriteLogic->replaceImageTags($html);
        }

        
        foreach ($wpcPictureBlocks as $i => $block) {
            $html = str_replace('<!--WPC_PICTURE_' . $i . '-->', $block, $html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'replaceImageTags2') {
            return $html;
        }


        $html = $combine_css->rewriteInlineFontFaces($html);


        $html = self::wpc_gfonts_display_pass($html);


        $preloadLCP = '';


        $preloadFonts = '';

        $html = str_replace('<!--WPC_INSERT_PRELOAD_MAIN-->', $preloadLCP . $preloadFonts, $html);


        if (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
            $html = self::$rewriteLogic->replacePictureTags($html);
        }

        
        
        $html = wps_rewriteLogic::unmaskMediaScripts($html, $wpcScriptMask);
        $wpcScriptMask = [];


        if (function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled()) {
            
            
            
            
            $lazy_v = !empty(self::$options['lazy_hash'])
                ? (string) self::$options['lazy_hash']
                : (defined('WPS_IC_HASH') ? (string) WPS_IC_HASH : '5021');
            $html = preg_replace_callback(
                '#https?://[^\s"\',]*?/q:i/[^\s"\',]*#i',
                function ($m) use ($lazy_v) {
                    $u = $m[0];
                    return $u . ((strpos($u, '?') !== false) ? '&' : '?') . 'v=' . $lazy_v;
                },
                $html
            );
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'replaceImageTags3') {
            return $html;
        }

        
        $html = self::$rewriteLogic->revSliderReplace($html);

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'cdn_rewrite_url') {
            return $html;
        }

        
        $criticalActive = !(isset(self::$page_excludes['critical_css']) && self::$page_excludes['critical_css'] == '0') && ((isset(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') || (isset(self::$page_excludes['critical_css']) && self::$page_excludes['critical_css'] == '1')) && (empty($settings['developer_mode']) || $settings['developer_mode'] == '0');

        $criticalCSS = new wps_criticalCss();
        $criticalCSSExists = $criticalCSS->criticalExists();


        
        if ($criticalCombine || (!empty(self::$settings['css_combine']) && self::$settings['css_combine'] == '1')) {
            if (empty($_GET['stopCombineCSS'])) {
                $html = $combine_css->maybe_do_combine($html);
            }
        }

        if (!$criticalCombine) {


        }

        $addslashes = false;
        if (!empty($_POST['action'])) {
            $addslashes = true;
        }


        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'combine_css') {
            return $html;
        }

        if (isset(self::$settings['fontawesome-lazy']) && self::$settings['fontawesome-lazy'] == '1') {
            
            $html = $combine_css->lazyFontawesome($html);
        }

        if (isset(self::$settings['gtag-lazy']) && self::$settings['gtag-lazy'] == '1') {
            
            
        }

        if (!self::$isAmp->isAmp() && (empty($_GET['disableCritical']) && empty($_GET['generateCriticalAPI'])) && !$this->criticalCombine) {
            if (!is_user_logged_in() && !is_admin_bar_showing()) {

                if ($criticalActive && !self::$preloaderAPI) {
                    global $post;
                    if (!empty($_GET['forceCriticalAjax'])) {
                        $html = self::$rewriteLogic->runCriticalAjax($html);
                    } else {
                        if (empty($criticalCSSExists)) {
                            $criticalRunning = $criticalCSS->criticalRunning();
                            if (!$criticalRunning) {
                                set_transient('wpc_critical_ajax_' . md5(wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), date('d.m.Y H:i:s'), 60 * 5);
                                $html = self::$rewriteLogic->runCriticalAjax($html);
                            }
                        }

                    }
                }

            }
        }


        if ((empty($_GET['disableCritical']) && empty($_GET['generateCriticalAPI'])) && !$this->criticalCombine) {
            if (!is_user_logged_in() && !is_admin_bar_showing()) {
                if (!empty($_GET['debugCriticalRunning'])) {
                    $html .= print_r([self::$settings['critical']['css'], $criticalCSSExists, $criticalRunning], true);
                }


                if (!empty($_GET['debugCritical_replace'])) {
                    
                    $criticalCSS = new wps_criticalCss();
                    $criticalCSSExists = $criticalCSS->criticalExists();
                    $criticalCSSContent = file_get_contents($criticalCSSExists['file']);

                    
                    $createPreloadLinks = function ($cssContent) {
                        $preloadLinks = '';
                        $loadedFonts = []; 
                        $commentPos = strpos($cssContent, '/* Preload Fonts */');

                        
                        if ($commentPos !== false) {
                            $relevantContent = substr($cssContent, 0, $commentPos);
                            $fontPattern = '/url\((\'|")?(.+?\.(woff2?|ttf|otf|eot))\1?\)/i';
                            if (preg_match_all($fontPattern, $relevantContent, $matches, PREG_SET_ORDER)) {
                                foreach ($matches as $match) {
                                    $fontUrl = $match[2];
                                    if (strpos($fontUrl, 'icon') !== false || strpos($fontUrl, 'fa-') !== false || strpos($fontUrl, 'la-') !== false) {
                                        continue;
                                    }
                                    
                                    if ((!empty(self::$settings['preload-crit-fonts'])) && self::$settings['preload-crit-fonts'] == '1') {
                                        if (!in_array($fontUrl, $loadedFonts)) {
                                            $preloadLinks .= "<link rel=\"preload\" href=\"$fontUrl\" as=\"font\" type=\"font/woff2\" crossorigin=\"anonymous\">\n";
                                            $loadedFonts[] = $fontUrl; 
                                        }
                                    }
                                }
                            }
                        }
                        return $preloadLinks;
                    };


                    $preloadLinks_Desktop = $createPreloadLinks($criticalCSSContent);

                    return print_r(['critActive:' => $criticalActive, 'preloadApi' => self::$preloaderAPI, 'excluded' => self::isURLExcluded('critical_css'), $preloadLinks_Desktop, $criticalCSSExists, $criticalCSSContent], true);
                }

                if (!empty($_GET['testCritical'])) {
                    self::$settings['critical']['css'] = '1';
                    $html = self::$rewriteLogic->addCritical($html);
                    $html = self::$rewriteLogic->lazyCSS($html);
                }

                if ($criticalActive && !self::$preloaderAPI) {
                    if (!self::isURLExcluded('critical_css')) {

                        
                        $criticalCSS = new wps_criticalCss();
                        $criticalCSSExists = $criticalCSS->criticalExists();

                        if (!empty($criticalCSSExists)) {
                            $html = self::$rewriteLogic->addCritical($html);
                            
                            
                            
                            
                            
                            
                            if (preg_match('/<style[^>]*id=["\']wpc-critical-css["\']/i', $html)) {
                                $html = self::$rewriteLogic->lazyCSS($html);
                            }
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            
                            if (class_exists('wps_rewriteLogic')
                                && method_exists('wps_rewriteLogic', 'wpc_defer_wire_dropfaces680')) {
                                $html = wps_rewriteLogic::wpc_defer_wire_dropfaces680($html);
                            }
                        } else {

                            $html = self::$rewriteLogic->wpc_arm_sentinel_tag($html);
                        }
                    }
                }
            }
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'cdn_rewrite_url_2') {
            return $html;
        }


        if (empty(self::$settings['optimize_meta_images']) || self::$settings['optimize_meta_images'] == '0') {
            $metaData = $this->encodeMeta($html);
            $html = $metaData['html'];
        }


        $wpcnd_stash = [];
        if (class_exists('WPC_Negotiated_Delivery')
            && (WPC_Negotiated_Delivery::is_active() || WPC_Negotiated_Delivery::is_active_jpeg())) {
            $html = preg_replace_callback('/<img\b[^>]*\bdata-wpc-nd\b[^>]*>/i', function ($m) use (&$wpcnd_stash) {
                $k = '___WPCND_IMG_' . count($wpcnd_stash) . '___';
                $wpcnd_stash[$k] = $m[0];
                return $k;
            }, $html);
        }


        
        $regEx = '#(?<=url\(|[\"\']|&quot;)(?:' . self::$regExURL . ')?/(?:((?:' . self::$regExDir . ')[^\"\')]+)|([^/\"\']+\.[^/\"\')]+))(?=[\"\')]|&quot;)#';
        
        
        
        
        $html = preg_replace_callback($regEx, [$this, 'cdn_rewrite_url'], $html);

        
        if (!empty(self::$settings['background-sizing']) && self::$settings['background-sizing'] == 1) {
            $regEx = '/background-image:\s*url\((\'|"|&quot;)(.*?)(\'|"|&quot;)\)/i';
            $html = preg_replace_callback($regEx, function ($matches) {
                $url = str_replace('&#039;', '', $matches[2]);

                return 'background-image: url(' . $this->cdn_rewrite_url([$url]) . ')';
            }, $html);
        }


        $html = preg_replace_callback('/data-code="([^"]+)"/', function ($m) use ($regEx) {
            $decoded = base64_decode($m[1]);
            if ($decoded === false) {
                return $m[0];
            }
            $decoded = preg_replace_callback($regEx, [$this, 'cdn_rewrite_url'], $decoded);
            $decoded = preg_replace_callback('/data-code="([^"]+)"/', function ($m2) use ($regEx) {
                $decoded2 = base64_decode($m2[1]);
                if ($decoded2 === false) {
                    return $m2[0];
                }
                $decoded2 = preg_replace_callback($regEx, [$this, 'cdn_rewrite_url'], $decoded2);
                return 'data-code="' . base64_encode($decoded2) . '"';
            }, $decoded);
            return 'data-code="' . base64_encode($decoded) . '"';
        }, $html);

        
        if (!empty($wpcnd_stash)) {
            $html = strtr($html, $wpcnd_stash);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'externalUrls') {
            return $html;
        }

        if (self::$externalUrlEnabled == '1') {
            $html = self::$rewriteLogic->externalUrls($html);
        } else {
            if (!empty(self::$replaceAllLinks) && self::$replaceAllLinks == '1') {
                $html = self::$rewriteLogic->allLinks($html);
            }
        }

        if (empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {
            
            self::$wpcPreloadLinks = $combine_css->preparePreloads($html);

            if (!empty(self::$wpcPreloadLinks)) {
                
                preg_match_all('/href=["\']([^"\']+)["\']/', self::$wpcPreloadLinks, $matches);

                $html = str_replace('<!--WPC_INSERT_PRELOAD-->', self::$wpcPreloadLinks, $html);
            }
        }


        if (!empty($metaData)) {
            $html = $this->decodeMeta($html, $metaData['store']);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'fonts') {
            return $html;
        }

        if (self::$fonts == 1) {
            $html = self::$rewriteLogic->fonts($html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'decodeIframe') {
            return $html;
        }

        if (!$isUserLoggedIn) {
            $html = self::$rewriteLogic->decodeIframe($html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'noscript_decode') {
            return $html;
        }

        
        
        

        $html = preg_replace_callback('/\[noscript-wpc\](.*?)\[\/noscript-wpc\]/is', [$this, 'noscript_decode'], $html);

        

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'Inline') {
            return $html;
        }


        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'combine_js') {
            return $html;
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'delay_js') {
            return $html;
        }

        

        $delayActive = true;

        if (isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '0') {
            
            $delayActive = false;
        }

        $delayV2Active = true;
        if (isset(self::$page_excludes['delay_js_v2']) && self::$page_excludes['delay_js_v2'] == '0') {
            
            $delayV2Active = false;
        }

        $html = self::$themeIntegrations->getIntegration($html);

        if ((isset(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1')
                || (class_exists('wps_ic_js_delay_v3') && wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings))) {
            if (!self::$isAmp->isAmp() && empty($_GET['disableDelay']) && empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {


                $wpc_delay_v3 = (!isset(self::$settings['delay-js-v3']) || self::$settings['delay-js-v3'] != '0') && class_exists('wps_ic_js_delay_v3');
                $js_delay = $wpc_delay_v3 ? new wps_ic_js_delay_v3() : new wps_ic_js_delay_v2();

                if (!empty($_GET['stop_before']) && $_GET['stop_before'] == '3463') {
                    return $html;
                }

                if (empty($_GET['disableCritical']) && $delayV2Active && !current_user_can('manage_wpc_settings') && !self::$delay_js_override && !self::$preloaderAPI) {
                    $html = $js_delay->process_html($html);
                } else {
                    $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$js_delay, 'removeNoDelay'], $html);
                }
            }
        } elseif ((isset(self::$settings['delay-js']) && self::$settings['delay-js'] == '1')) {
            if (!self::$isAmp->isAmp() && empty($_GET['disableDelay']) && empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {
                $js_delay = new wps_ic_js_delay();

                if (!empty($_GET['stop_before']) && $_GET['stop_before'] == '3473') {
                    return $html;
                }

                if (empty($_GET['disableCritical']) && $delayActive && !current_user_can('manage_wpc_settings') && !self::$delay_js_override && !self::$preloaderAPI) {
                    if (!empty(self::$settings['preload-scripts']) && self::$settings['preload-scripts'] == '1') {
                        $html = $js_delay->preload_scripts($html);
                    }
                    $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$js_delay, 'delay_script_replace'], $html);
                } else {
                    $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$js_delay, 'removeNoDelay'], $html);
                }
            }

            if (!empty($_GET['testGtag'])) {
                

                return print_r([$html], true);
            }

        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == '3491') {
            return $html;
        }

        if (empty($_GET['disableCritical']) && !empty(self::$settings['scripts-to-footer']) && self::$settings['scripts-to-footer'] == '1') {
            $js_delay = new wps_ic_js_delay();
            $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$js_delay, 'scriptsToFooter'], $html);
            $html = preg_replace_callback('/<\/body>/si', [$js_delay, 'printFooterScripts'], $html);
        }

        
        
        
        
        if (function_exists('wpc_yield_checkpoints707')) {
            $wpc_dm708 = class_exists('wps_ic_js_delay_v3')
                && wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings)
                && !self::$isAmp->isAmp() && empty($_GET['disableDelay']) && empty($_GET['disableCritical'])
                && !current_user_can('manage_wpc_settings') && !self::$delay_js_override && !self::$preloaderAPI;
            $wpc_pre708 = strlen($html);
            $html = wpc_yield_checkpoints707($html, $wpc_dm708);
            if (strlen($html) !== $wpc_pre708 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('yield-inject', '', '', ['lane' => 'cdn']);
            }
            $wpc_fmv710 = 0;
            $html = wpc_face_gate710($html, $wpc_dm708, $wpc_fmv710);
            if ($wpc_fmv710 > 0 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('face-gate', '', '', ['moved' => $wpc_fmv710, 'lane' => 'cdn']);
            }
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'cache_minify') {
            return $html;
        }

        if (!empty(self::$settings['cache']['minify']) && self::$settings['cache']['minify'] == '1') {
            if (!self::isURLExcluded('minify_html')) {
                $html = self::$minifyHtml->minify($html);
            }
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'returnTemplates') {
            return $html;
        }

        $html = $this->restoreTemplates($html, $removedTemplates['templates']);

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'cache_settings') {
            return $html;
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'cache_advanced') {
            return $html;
        }


        
        $cacheActive = !(isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '0') && ((isset(self::$settings['cache']['advanced']) && self::$settings['cache']['advanced'] == '1') || (isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '1'));


        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'cache_mobile') {
            return $html;
        }


        $html = preg_replace('/<!--WPC[\s\S]*?-->/', '', $html);


        
        
        if (!empty(self::$settings['replace-fonts'])) {
            if (self::$settings['replace-fonts'] == 'local') {
                $fonts = new wps_ic_fonts();
                $html = $fonts->replaceFrontend($html);
            } else if (self::$settings['replace-fonts'] == 'bunny') {


                $html = preg_replace('/<link\b[^>]*\bhref=["\']https?:\/\/fonts\.gstatic\.com\/[^"\']+["\'][^>]*>\s*/i', '', $html);
                $html = str_replace('fonts.gstatic.com', 'fonts.bunny.net', $html);
            }
        }
        


        if (class_exists('WPC_Modern_Delivery') && WPC_Modern_Delivery::is_active()
            && !(class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active())) {
            $wpcMdMask = [];
            $html = wps_rewriteLogic::maskMediaScripts($html, $wpcMdMask);
            $html = WPC_Modern_Delivery::rewrite_buffer($html);
            $html = wps_rewriteLogic::unmaskMediaScripts($html, $wpcMdMask);
        }


        if (class_exists('wps_rewriteLogic') && wps_rewriteLogic::natural_assets_on()) {
            $html = wps_rewriteLogic::naturalize_asset_urls($html);


            $fb = self::add_asset_failover($html);
            if (is_string($fb) && $fb !== '') $html = $fb;
        }

        
        
        
        
        
        
        
        if (function_exists('wpc_unzone_url')
            && (!function_exists('apply_filters') || apply_filters('wpc_scripts_same_origin', true))) {
            $html = preg_replace_callback('#(<script\b[^>]*\ssrc=")(https?://[^"]+)(")#i', function ($m) {
                return $m[1] . wpc_unzone_url($m[2]) . $m[3];
            }, $html);
        }
        if (class_exists('wps_rewriteLogic')) {
            $html = wps_rewriteLogic::wpc_logo_rightsize($html);
        }

        
        
        
        
        
        if (function_exists('wpc_svg_inline_data718')) {
            $html = preg_replace_callback('#(<img\b(?![^>]*loading="lazy")[^>]*\ssrc=")(https?://[^"]+\.svg[^"]*)(")#i', function ($m) {
                $wpc_d719 = wpc_svg_inline_data718($m[2]);
                return $wpc_d719 !== '' ? $m[1] . $wpc_d719 . $m[3] : $m[0];
            }, $html);
        }

        if (function_exists('wpc_stack_splice732')) {
            $html = wpc_stack_splice732($html);
        }
        
        
        
        if (function_exists('wpc_face_gate710') && isset($wpc_dm708) && $wpc_dm708) {
            $wpc_fmv711 = 0;
            $html = wpc_face_gate710($html, true, $wpc_fmv711);
            if ($wpc_fmv711 > 0 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('face-gate', '', '', ['moved' => $wpc_fmv711, 'lane' => 'cdn-final']);
            }
        }

        return $html;
    }


    public static function add_asset_failover($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name)) return $html;
        if (!class_exists('wps_rewriteLogic') || !wps_rewriteLogic::natural_assets_on()) return $html;
        $zoneHost = preg_replace('#/.*$#', '', (string) self::$zone_name);
        $origin   = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
        if ($origin === '' || strcasecmp((string) $zoneHost, (string) $origin) === 0) return $html;
        $zq = preg_quote((string) $zoneHost, '#');
        $wpc_fbjs735 = function ($origin_url) {
            return ' data-wpc-fb="0" onerror="if(!this.dataset.wpcFb||this.dataset.wpcFb===\'0\'){this.dataset.wpcFb=1;this.href=\'' . esc_attr($origin_url) . '\';}"';
        };


        $css = preg_replace_callback('#<link\b(?=[^>]*\srel=["\']?stylesheet)(?![^>]*\sdata-wpc-fb)[^>]*\shref=["\']https://' . $zq . '(/[^"\']+?\.css[^"\']*)["\'][^>]*>#i', function ($m) use ($origin, $wpc_fbjs735) {
            if (strpos($m[0], '/a:') !== false) return $m[0];
            return str_replace('<link', '<link' . $wpc_fbjs735('https://' . $origin . $m[1]), $m[0]);
        }, $html);
        if (is_string($css) && $css !== '') $html = $css;
        $wpc_dfr735 = preg_replace_callback('#<link\b(?=[^>]*\s(?:rel|type)=["\']?wpc-(?:mobile-)?stylesheet)(?![^>]*\sdata-wpc-fb)[^>]*\shref=["\']https://' . $zq . '(/[^"\']+?\.css[^"\']*)["\'][^>]*>#i', function ($m) use ($origin, $wpc_fbjs735) {
            if (strpos($m[0], '/a:') !== false) return $m[0];
            return str_replace('<link', '<link' . $wpc_fbjs735('https://' . $origin . $m[1]), $m[0]);
        }, $html);
        if (is_string($wpc_dfr735) && $wpc_dfr735 !== '') $html = $wpc_dfr735;
        $wpc_rst735 = preg_replace_callback('#<link\b(?![^>]*\sdata-wpc-fb)[^>]*\sdata-wpc-(?:rest|lf-href)=["\']https://' . $zq . '(/[^"\']+?\.css[^"\']*)["\'][^>]*>#i', function ($m) use ($origin, $wpc_fbjs735) {
            if (strpos($m[0], '/a:') !== false) return $m[0];
            return str_replace('<link', '<link' . $wpc_fbjs735('https://' . $origin . $m[1]), $m[0]);
        }, $html);
        if (is_string($wpc_rst735) && $wpc_rst735 !== '') $html = $wpc_rst735;
        $wpc_img735 = preg_replace_callback('#<img\b(?![^>]*\sdata-wpc-fb)[^>]*\ssrc=["\']https://' . $zq . '(/[^"\']+?)["\'][^>]*>#i', function ($m) use ($origin) {
            if (strpos($m[0], '/a:') !== false || strpos($m[0], '/u:') !== false) return $m[0];
            $wpc_o735 = 'https://' . $origin . preg_replace('/\?.*$/', '', $m[1]);
            $wpc_h735 = "this.onerror=null;var p=this.parentNode;if(p&&p.tagName==='PICTURE'){var s;while(s=p.getElementsByTagName('source')[0])s.parentNode.removeChild(s);}this.removeAttribute('srcset');this.src=this.getAttribute('data-wpc-fb');";
            return str_replace('<img', '<img data-wpc-fb="' . esc_attr($wpc_o735) . '" onerror="' . $wpc_h735 . '"', $m[0]);
        }, $html);
        if (is_string($wpc_img735) && $wpc_img735 !== '') $html = $wpc_img735;
        $wpc_lzy735 = preg_replace_callback('#<img\b(?![^>]*\sdata-wpc-fb)[^>]*\sdata-wpc-qw-src=["\']https://' . $zq . '(/[^"\']+?)["\'][^>]*>#i', function ($m) use ($origin) {
            if (strpos($m[1], '/a:') !== false || strpos($m[1], '/u:') !== false) return $m[0];
            $wpc_o735 = 'https://' . $origin . preg_replace('/\?.*$/', '', $m[1]);
            $wpc_h735 = "this.onerror=null;var p=this.parentNode;if(p&&p.tagName==='PICTURE'){var s;while(s=p.getElementsByTagName('source')[0])s.parentNode.removeChild(s);}this.removeAttribute('srcset');this.src=this.getAttribute('data-wpc-fb');";
            return str_replace('<img', '<img data-wpc-fb="' . esc_attr($wpc_o735) . '" onerror="' . $wpc_h735 . '"', $m[0]);
        }, $html);
        if (is_string($wpc_lzy735) && $wpc_lzy735 !== '') $html = $wpc_lzy735;
        $js = preg_replace_callback('#<script\b(?![^>]*\sdata-wpc-fb)[^>]*\ssrc=["\']https://' . $zq . '(/[^"\']+?\.js[^"\']*)["\'][^>]*></script>#i', function ($m) use ($origin) {
            if (strpos($m[0], '/a:') !== false) return $m[0];
            $o = 'https://' . $origin . $m[1];
            return str_replace('<script', '<script data-wpc-fb="0" onerror="if(!this.dataset.wpcFb||this.dataset.wpcFb===\'0\'){this.dataset.wpcFb=1;var s=document.createElement(\'script\');s.src=\'' . esc_attr($o) . '\';this.parentNode.insertBefore(s,this.nextSibling);}"', $m[0]);
        }, $html);
        if (is_string($js) && $js !== '') $html = $js;
        return $html;
    }

    public function getRegexp()
    {
        if (!isset(self::$options['regExUrl']) || !isset(self::$options['regexpDirectories']) || empty(self::$options['regExUrl']) || empty(self::$options['regexpDirectories'])) {
            $escapedSiteURL = quotemeta(self::$home_url);
            self::$options['regExUrl'] = $regExURL = '(https?:|)' . substr($escapedSiteURL, strpos($escapedSiteURL, '//'));

            
            $directories = 'wp\-content|wp\-includes';
            if (!empty($cdn['cdn_directories'])) {
                $directoriesArray = array_map('trim', explode(',', $cdn['cdn_directories']));

                if (count($directoriesArray) > 0) {
                    $directories = implode('|', array_map('quotemeta', array_filter($directoriesArray)));
                }
            }

            self::$options['regexpDirectories'] = $directories;

            self::$regExURL = $regExURL;
            self::$regExDir = $directories;

            update_option(WPS_IC_OPTIONS, self::$options);
        } else {
            self::$regExURL = self::$options['regExUrl'];
            self::$regExDir = self::$options['regexpDirectories'];
        }
    }

    public function removeDuplicatedFontawesome($html)
    {
        if (preg_match('#<link[^>]+href=["\'][^"\']*font-awesome/css/all\.min\.css[^"\']*["\'][^>]*>#i', $html)) {
            
            $html = preg_replace('#<link[^>]+href=["\'][^"\']*fontawesome\.css[^"\']*["\'][^>]*>\s*#i', '', $html, 1);
        }

        return $html;
    }

    





    function removeTemplates($html)
    {
        $templates = [];
        $templateIdPrefix = 'template_';
        $templateCounter = 0;

        
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER);

        
        foreach ($matches as $match) {
            $fullTag = $match[0];
            $content = $match[1];

            
            if (preg_match('/type\s*=\s*["\']text\/template["\']/i', $fullTag)) {
                
                $templateId = $templateIdPrefix . $templateCounter++;

                
                $templates[$templateId] = $content;

                
                if (preg_match('/\swpc_id\s*=\s*["\'][^"\']*["\']/i', $fullTag)) {
                    
                    $newTag = preg_replace('/(\swpc_id\s*=\s*["\'])[^"\']*(["\'])/i', '$1' . $templateId . '$2', $fullTag);
                } else {
                    
                    $newTag = preg_replace('/(<script\b[^>]*)>/i', '$1 wpc_id="' . $templateId . '">', $fullTag);
                }

                
                $newTag = preg_replace('/(<script\b[^>]*>).*(<\/script>)/is', '$1$2', $newTag);

                
                $html = str_replace($fullTag, $newTag, $html);
            }
        }

        return ['html' => $html, 'templates' => $templates];
    }

    




    public function encodeMeta($html)
    {
        $metaTagsStore = [];
        $metaCounter = 0;

        
        $html = preg_replace_callback('#<meta\s+(?:property=["\'](?:og:image|twitter:image)["\']|name=["\']twitter:image["\'])[^>]*>#i', function ($matches) use (&$metaTagsStore, &$metaCounter) {
            $placeholder = '<!--META_PLACEHOLDER_' . $metaCounter . '-->';
            $metaTagsStore[$metaCounter] = $matches[0];
            $metaCounter++;
            return $placeholder;
        }, $html);

        
        $html = preg_replace_callback('#<script\s+type=["\']application/ld\+json["\'][^>]*>.*?</script>#si', function ($matches) use (&$metaTagsStore, &$metaCounter) {
            $placeholder = '<!--JSONLD_PLACEHOLDER_' . $metaCounter . '-->';
            $metaTagsStore[$metaCounter] = $matches[0];
            $metaCounter++;
            return $placeholder;
        }, $html);

        return ['html' => $html, 'store' => $metaTagsStore];
    }


    public static function wpc_zone_is_cf_direct()
    {
        if (!function_exists('get_option') || !defined('WPS_IC_CF_CNAME')) {
            return false;
        }
        $cname = trim((string) get_option(WPS_IC_CF_CNAME, ''));
        return ($cname !== '' && stripos((string) self::$zone_name, $cname) !== false);
    }


    public static function wpc_webp_origin_natural()
    {
        $opt = function_exists('get_option') ? get_option('wpc_webp_origin_natural', 0) : 0;
        return (bool) apply_filters('wpc_webp_origin_natural', !empty($opt));
    }

    public function cdn_rewrite_url($url, $addslashes = false)
    {
        $width = 1;

        if (self::$isAmp->isAmp()) {
            $width = 600;
        }

        $url = $url[0];

        if (strpos($url, 'cookie') !== false) {
            return $this->maybe_slash($url, $addslashes);
        }

        $matchCount = preg_match_all('/((https?\:\/\/|\/\/)[^\s]+\S+\.(' . self::$findImages . '))\s(\d{1,5}+[wx])/', $url, $srcset_links);

        if ((strpos($url, ' ') !== false || strpos($url, '%20') !== false) && $matchCount === 0) {
            return $url;
        }

        if (self::isExcluded('cdn', $url)) {
            return $this->maybe_slash($url, $addslashes);
        }

        if (strpos($url, 'spinner.svg') !== false || strpos($url, 'gform_ajax_spinner') !== false) {
            return $this->maybe_slash($url, $addslashes);
        }


        if (preg_match('/\.gif(\?|#|\s|$)/i', $url)
            && !(class_exists('wps_rewriteLogic') && wps_rewriteLogic::cf_is_delivery())) {
            return $this->maybe_slash($url, $addslashes);
        }

        $siteUrl = self::$home_url;
        $newUrl = str_replace($siteUrl, '', $url);

        
        preg_match('/(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]\/([a-zA-Z0-9]+)/', $siteUrl, $isStaging);

        

        $originalUrl = $url;
        $newSrcSet = '';


        if (!empty($srcset_links[0])) {
            if (!empty(self::$settings['remove-srcset'])) {
                return '';
            }
        }

        if (!empty($srcset_links[0])) {
            $hadTrailingEscapedQuoteSlash = false;

            if (substr($url, -1) === '\\') {
                $hadTrailingEscapedQuoteSlash = true;
                $url = substr($url, 0, -1);

                $matchCount = preg_match_all('/((https?\:\/\/|\/\/)[^\s]+\S+\.(' . self::$findImages . '))\s(\d{1,5}+[wx])/', $url, $srcset_links);
            }

            foreach ($srcset_links[0] as $i => $srcset) {
                $src = explode(' ', $srcset);
                $srcset_url = $src[0];
                $srcset_width = $src[1];

                if (self::is_excluded_link($srcset_url) || self::is_excluded($srcset_url, $srcset_url)) {
                    $newSrcSet .= $srcset_url . ' ' . $srcset_width . ',';
                } elseif (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
                    
                    
                    $newSrcSet .= $srcset_url . ' ' . $srcset_width . ',';
                } else {
                    if (strpos($srcset_width, 'x') !== false) {
                        $width_url = 1;
                        $srcset_width = str_replace('x', '', $srcset_width);
                        $extension = 'x';
                    } else {
                        $width_url = $srcset_width = str_replace('w', '', $srcset_width);
                        $extension = 'w';
                    }

                    if (strpos($srcset_url, self::$zone_name) !== false) {
                        $newSrcSet .= $srcset_url . ' ' . $srcset_width . $extension . ',';
                        continue;
                    }


                    if ((empty(self::$externalUrlEnabled) || self::$externalUrlEnabled == '0')
                        && !self::image_url_matching_site_url($srcset_url)) {
                        $newSrcSet .= $srcset_url . ' ' . $srcset_width . $extension . ',';
                        continue;
                    }

                    if ($srcset_width == '1') {
                        $srcsetWidthExtension = '';
                    } else {
                        $srcsetWidthExtension = $srcset_width . $extension;
                    }


                    if (strpos($srcset_url, '.webp') !== false && self::wpc_webp_origin_natural()) {
                        $webp_nat_ss = preg_replace('/-\d+x\d+(\.webp)$/i', '$1', $srcset_url);
                        $webp_nat_ss = preg_replace('#^https?://[^/]+#', 'https://' . self::$zone_name, $webp_nat_ss);
                        $newSrcSet .= $webp_nat_ss . ' ' . $srcsetWidthExtension . ',';
                    } else {
                        $newSrcSet .= self::$apiUrl . '/r:' . self::$is_retina . '/wp:' . self::$webp . '/w:1/u:' . self::reformat_url($srcset_url) . ' ' . $srcsetWidthExtension . ',';
                    }
                }
            }

            $newSrcSet = rtrim($newSrcSet, ',');

            if ($hadTrailingEscapedQuoteSlash) {
                $newSrcSet .= '\\';
            }

            return $newSrcSet;
        } else {
            if (strpos($url, 'data:image') !== false) {
                return $url;
            }

            if (self::is_excluded_link($url)) {
                return $this->maybe_slash($url, $addslashes);
            }

            if (strpos($url, self::$zone_name) !== false) {
                return $this->maybe_slash($url, $addslashes);
            }

            
            if (empty(self::$externalUrlEnabled) || self::$externalUrlEnabled == '0') {
                if (!self::image_url_matching_site_url($url)) {
                    return $this->maybe_slash($url, $addslashes);
                }
            } else {
                
                if (strpos($url, '.jpg') !== false || strpos($url, '.png') !== false || strpos($url, '.gif') !== false || strpos($url, '.svg') !== false || strpos($url, '.jpeg') !== false) {
                    foreach (self::$default_excluded_list as $i => $excluded_string) {
                        if (strpos($url, $excluded_string) !== false) {
                            return $this->maybe_slash($url, $addslashes);
                        }
                    }
                }
            }

            if (!empty($url)) {
                
                if (strpos($url, 'login') !== false) {
                    return $this->maybe_slash($url, $addslashes);
                }

                if (strpos($url, '.css') !== false && self::$css == '1') {


                    if (stripos($url, '/cache/wp-cio-fonts/') !== false) {
                        return $this->maybe_slash($url, $addslashes);
                    }
                    $fileMinify = self::$css_minify;

                    if (self::isExcluded('css_minify', $url)) {
                        $fileMinify = '0';
                    }


                    if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1'
                        && apply_filters('wpc_font_subset_forces_css_minify', false, $url)) {
                        $fileMinify = '1';
                    }
                    


                    $newUrl = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($url);

                    return $newUrl;
                } elseif (preg_match('/\.js(?:[?#]|$)/i', $url) && self::$js == '1') {
                    
                    
                    
                    
                    
                    
                    if (apply_filters('wpc_scripts_same_origin', true)) {
                        return $this->maybe_slash($url, $addslashes);
                    }
                    $fileMinify = self::$js_minify;
                    if (self::isExcluded('js_minify', $url)) {
                        $fileMinify = '0';
                    }

                    


                    if (strpos($url, 'wp-content') !== false || strpos($url, 'wp-includes') !== false) {
                        if (empty(self::$js_minify) || self::$js_minify == 'false') {
                            $newUrl = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($url, false);
                        } else {
                            $newUrl = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($url, false);
                        }
                    } else {
                        $newUrl = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($url, false);
                    }

                    return $newUrl;
                } elseif (strpos($url, '.svg') !== false) {
                    if (!empty(self::$settings['serve']['svg'])) {
                        


                        if (!self::is_excluded($url, $url)) {
                            if (self::$zone_test == 0 && (strpos($url, 'wp-content') !== false || strpos($url, 'wp-includes') !== false)) {
                                $newUrl = 'https://' . self::$zone_name . '/m:0/a:' . self::reformat_url($url);
                            } else {
                                $newUrl = 'https://' . self::$zone_name . '/m:0/a:' . self::reformat_url($url, false);
                            }
                        }
                    } else {
                        $newUrl = self::reformat_url($url, false);
                    }

                    return $newUrl;
                } elseif (self::$fonts == 1 && (strpos($url, '.woff') !== false || strpos($url, '.woff2') !== false || strpos($url, '.eot') !== false || strpos($url, '.ttf') !== false)) {
                    




                    if (stripos($url, '/cache/wp-cio-fonts/') !== false) {
                        return $this->maybe_slash($url, $addslashes);
                    }


                    if (stripos((string) wp_parse_url($url, PHP_URL_PATH), '/wp-content/') === false) {
                        return $this->maybe_slash($url, $addslashes);
                    }


                    $wpc_zn = (string) self::$zone_name;
                    $wpc_oh = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
                    if ($wpc_zn === '' || ($wpc_oh !== '' && strcasecmp($wpc_zn, $wpc_oh) === 0)) {
                        return $this->maybe_slash($url, $addslashes);
                    }
                    if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') {
                        if (strpos($url, 'icon') !== false || strpos($url, 'awesome') !== false || strpos($url, 'lightgallery') !== false || strpos($url, 'gallery') !== false || strpos($url, 'side-cart-woocommerce') !== false) {
                            $newUrl = 'https://' . $wpc_zn . '/m:0/a:' . self::reformat_url($url);
                        } else {
                            $newUrl = 'https://' . $wpc_zn . '/font:true/a:' . self::reformat_url($url);
                        }
                    } else {
                        $newUrl = 'https://' . $wpc_zn . '/m:0/a:' . self::reformat_url($url);
                    }
                    return $newUrl;
                }

                if (self::is_excluded($url, $url)) {
                    return $this->maybe_slash($originalUrl, $addslashes);
                }

                
                if (function_exists('wpc_url_to_attachment_id') && function_exists('wpc_get_local_optimized_ids')) {
                    $local_att_id = wpc_url_to_attachment_id($url);
                    if ($local_att_id) {
                        $optimized_ids = wpc_get_local_optimized_ids();
                        if (isset($optimized_ids[$local_att_id])) {
                            return $this->maybe_slash($originalUrl, $addslashes);
                        }
                    }
                }

                if (strpos($url, '.jpg') !== false || strpos($url, '.gif') !== false || strpos($url, '.png') !== false) {
                    $ext = '';
                    if (strpos($url, '.jpg') !== false) {
                        $ext = 'jpg';
                    } elseif (strpos($url, '.gif') !== false) {
                        $ext = 'gif';
                    } elseif (strpos($url, '.png') !== false) {
                        $ext = 'png';
                    }

                    if (!empty(self::$settings['serve'][$ext])) {
                        $webp = '/wp:' . self::$webp;
                        if (self::isExcludedFrom('webp', $url)) {
                            $webp = '/wp:0';
                        }

                        if (!self::is_excluded($url, $url)) {
                            $newUrl = 'https://' . self::$zone_name . '/q:i/r:' . self::$is_retina . $webp . '/w:' . self::$rewriteLogic->getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $url)) . '/u:' . self::reformat_url($url);
                        }
                    } else {
                        $newUrl = self::reformat_url($url, false);
                    }

                    return $newUrl;
                }

                if (strpos($url, '.webp') !== false) {
                    
                    
                    if (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
                        return self::reformat_url($url, false);
                    }
                    if (!self::is_excluded($url, $url)) {


                        if (self::wpc_webp_origin_natural()) {
                            $webp_nat = preg_replace('/-\d+x\d+(\.webp)$/i', '$1', $url);
                            $webp_nat = preg_replace('#^https?://[^/]+#', 'https://' . self::$zone_name, $webp_nat);
                            return $this->maybe_slash($webp_nat, $addslashes);
                        }
                        $webp = '/wp:' . self::$webp;
                        if (self::isExcludedFrom('webp', $url)) {
                            $webp = '/wp:0';
                        }
                        $newUrl = 'https://' . self::$zone_name . '/q:i/r:' . self::$is_retina . $webp . '/w:' . self::$rewriteLogic->getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $url)) . '/u:' . self::reformat_url($url);
                        return $newUrl;
                    }
                }

                return $url;


                
                if (self::$is_multisite) {
                    return $this->maybe_slash($newUrl, $addslashes);
                } elseif (empty($isStaging) || empty($isStaging[0])) {
                    
                    return $this->maybe_slash($newUrl, $addslashes);
                } else {
                    
                    return $this->maybe_slash($originalUrl, $addslashes);
                }
            }

            return $this->maybe_slash($url, $addslashes);
        }
    }

    public function maybe_slash($url, $addslashes = false)
    {
        if ($addslashes) {
            return addslashes($url);
        }

        return $url;
    }

    public static function is_excluded($image_element, $image_link = '')
    {
        $image_path = '';

        if (empty($image_link)) {
            preg_match('@src="([^"]+)"@', $image_element, $match_url);
            if (!empty($match_url)) {
                $image_path = $match_url[1];
                $basename_original = basename($match_url[1]);
            } else {
                $basename_original = basename($image_element);
            }
        } else {
            $image_path = $image_link;
            $basename_original = basename($image_link);
        }

        preg_match("/([0-9]+)x([0-9]+)\.[a-zA-Z0-9]+/", $basename_original, $matches);
        if (empty($matches)) {
            
            $basename = $basename_original;
        } else {
            
            $basename = str_replace('-' . $matches[1] . 'x' . $matches[2], '', $basename_original);
        }

        


        if (!empty(self::$lazy_excluded_list) && !empty(self::$lazy_enabled) && self::$lazy_enabled == '1') {

            foreach (self::$lazy_excluded_list as $i => $lazy_excluded) {
                if (strpos($basename, $lazy_excluded) !== false) {
                    return true;
                }
            }
        } elseif (!empty(self::$excluded_list)) {
            foreach (self::$excluded_list as $i => $excluded) {
                if (strpos($basename, $excluded) !== false) {
                    return true;
                }
            }
        }

        if (!empty(self::$lazy_excluded_list) && in_array($basename, self::$lazy_excluded_list)) {
            return true;
        }

        if (!empty(self::$excluded_list) && in_array($basename, self::$excluded_list)) {
            return true;
        }

        return false;
    }

    





    public function decodeMeta($html, $metaTagsStore)
    {
        if (empty($metaTagsStore)) {
            return $html;
        }

        foreach ($metaTagsStore as $index => $originalTag) {
            $metaPlaceholder = '<!--META_PLACEHOLDER_' . $index . '-->';
            $jsonldPlaceholder = '<!--JSONLD_PLACEHOLDER_' . $index . '-->';

            
            if (strpos($html, $metaPlaceholder) !== false) {
                $html = str_replace($metaPlaceholder, $originalTag, $html);
            } elseif (strpos($html, $jsonldPlaceholder) !== false) {
                $html = str_replace($jsonldPlaceholder, $originalTag, $html);
            }
        }

        return $html;
    }


    function restoreTemplates($html, $templates)
    {
        
        preg_match_all('/<script\b[^>]*><\/script>/is', $html, $matches, PREG_SET_ORDER);

        
        foreach ($matches as $match) {
            $fullTag = $match[0];

            
            if (preg_match('/type\s*=\s*["\']text\/template["\']/i', $fullTag) && preg_match('/wpc_id\s*=\s*["\']([^"\']+)["\']/i', $fullTag, $idMatch)) {

                $templateId = $idMatch[1];

                
                if (isset($templates[$templateId])) {
                    
                    $newTag = str_replace('></script>', '>' . $templates[$templateId] . '</script>', $fullTag);

                    
                    $html = str_replace($fullTag, $newTag, $html);
                }
            }
        }

        return $html;
    }

    public function set_image_sizes($matches)
    {

        
        if (preg_match('/wpc-size=(["\'])preserve\1/', $matches[0])) {
            return $matches[0];
        }

        
        if (preg_match('/\s(width|height)\s*=\s*["\']?\d+/i', $matches[0])) {
            return $matches[0];
        }

        if (empty(self::$settings['add-image-sizes']) || self::$settings['add-image-sizes'] == '0') {
            return $matches[0];
        }

        
        if (strpos($matches[0], '<picture>') !== false) {
            
            preg_match('/<img[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/si', $matches[0], $imgMatches);
            if (!$imgMatches) {
                return $matches[0]; 
            }
            $imageUrl = $imgMatches[1];
        } else {
            
            $imageUrl = $matches[1];
        }

        
        $localPath = $this->url_to_path($imageUrl);

        if (!$localPath) {
            
            return $matches[0];
        }

        
        $dimensions = $this->get_image_dimensions($localPath);
        if ($dimensions === false) {
            
            return $matches[0];
        }

        
        $widthHeightStr = 'width="' . round($dimensions[0], 0) . '" height="' . round($dimensions[1], 0) . '"';

        if ($dimensions[0] <= 5 || $dimensions[1] <= 5) {
            $widthHeightStr = '';
        }

        
        if (isset($imgMatches)) {
            
            $newImgTag = preg_replace('/<img([^>]+)>/', '<img$1 ' . $widthHeightStr . '>', $imgMatches[0]);

            
            return str_replace($imgMatches[0], $newImgTag, $matches[0]);
        } else {
            
            return preg_replace('/<img/', '<img ' . $widthHeightStr, $matches[0]);
        }
    }

    public function url_to_path($url)
    {
        $parsedUrl = parse_url($url);
        $siteUrl = parse_url(get_site_url());

        
        if (!isset($parsedUrl['host']) || !isset($siteUrl['host']) || $parsedUrl['host'] !== $siteUrl['host']) {
            return false; 
        }

        
        $relPath = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';

        
        $wpBasePath = ABSPATH;

        
        if (!empty($siteUrl['path']) && $siteUrl['path'] !== '/') {
            $wpBasePath = str_replace(trim($siteUrl['path'], '/'), '', $wpBasePath);
        }

        
        $localPath = realpath($wpBasePath . $relPath);

        
        return file_exists($localPath) ? $localPath : false;
    }

    public function get_image_dimensions($filename)
    {
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
            
            $svgfile = @simplexml_load_file(rawurlencode($filename), 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($svgfile) {
                $attributes = $svgfile->attributes();
                $width = isset($attributes->width) ? (string)$attributes->width : null;
                $height = isset($attributes->height) ? (string)$attributes->height : null;

                
                $width = $this->format_svg_value($width);
                $height = $this->format_svg_value($height);

                if ($width && $height) {
                    
                    return [$width, $height];
                } elseif (isset($attributes->viewBox)) {
                    
                    $viewBox = explode(' ', $attributes->viewBox);
                    if (count($viewBox) === 4) {
                        $width = $viewBox[2];
                        $height = $viewBox[3];
                        return [$width, $height];
                    }
                }
            }
            
            return false;
        } else {
            
            $sizes = @getimagesize($filename);
            return $sizes ? [$sizes[0], $sizes[1]] : false;
        }
    }

    public function format_svg_value($value)
    {
        
        if (empty($value) || is_numeric($value)) {
            return $value;
        }

        
        $px_pattern = '/([0-9]+)\s*px/i';

        
        if (preg_match($px_pattern, $value, $matches)) {
            return $matches[1];
        }

        
        return '';
    }

    public function injectPreloadImages($matches)
    {
        $originalHead = $matches[0];

        $inject = $originalHead;
        $inject .= '<!--WPC_INSERT_CRITICAL-->';
        $inject .= '<!--WPC_INSERT_PRELOAD_MAIN-->';
        $inject .= '<!--WPC_INSERT_PRELOAD-->';

        
        if (self::$rewriteLogic::$pictureWebpEnabled) {
            $inject .= '<style id="wpc-picture-css">picture.wpc-picture:not([data-wpc-mir]){display:contents}picture.wpc-picture source{display:none}</style>';
        }

        $inject .= $this->get_ga_script();

        return $inject;
    }

    public function get_ga_script()
    {
        if (!empty(self::$settings['ga-bot-shield']) && self::$settings['ga-bot-shield'] === '1') {
            return <<<JS
<script id="wpc-ga-bot-shield">
(function () {
  try {
    var ua = (navigator.userAgent || "").toLowerCase();

    /* ===============================
       Test helper (force bot mode)
       =============================== */
    function hasCookie(name) {
      try {
        return (document.cookie || "")
          .split(";")
          .some(c => c.trim().startsWith(name + "="));
      } catch(e) { return false; }
    }

    var forceBot =
      /(?:\\?|&)wpc_force_bot=1(?:&|$)/.test(location.search) ||
      hasCookie("wpc_force_bot");

    /* ===============================
       Bot detection
       =============================== */

    var isKnownBot =
      ua.includes("petalbot") ||
      ua.includes("sogou") ||
      ua.includes("baiduspider") ||
      ua.includes("yandexbot");

    if (!(forceBot || isKnownBot)) return;

    // Debug flag for support / QA
    window.__WPC_GA_BLOCKED__ = true;

    // Prevent inline GA errors
    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function(){ window.dataLayer.push(arguments); };
    window.ga = window.ga || function(){ (window.ga.q = window.ga.q || []).push(arguments); };

    function isGA(url) {
      url = String(url || "").toLowerCase();
      return (
        url.includes("google-analytics.com") ||
        url.includes("stats.g.doubleclick.net") ||
        url.includes("/collect") ||
        url.includes("/g/collect") ||
        url.includes("/mp/collect")
      );
    }

    /* ===============================
       sendBeacon
       =============================== */
    if (navigator.sendBeacon) {
      var _sb = navigator.sendBeacon.bind(navigator);
      navigator.sendBeacon = function (url, data) {
        if (isGA(url)) return true;
        return _sb(url, data);
      };
    }

    /* ===============================
       fetch
       =============================== */
    if (window.fetch) {
      var _fetch = window.fetch.bind(window);
      window.fetch = function (input, init) {
        var url = "";
        try {
          url = (typeof input === "string")
            ? input
            : (input && input.url) || "";
        } catch(e) {}
        if (isGA(url)) {
          return Promise.resolve(new Response("", { status: 204 }));
        }
        return _fetch(input, init);
      };
    }

    /* ===============================
       XMLHttpRequest
       =============================== */
    if (window.XMLHttpRequest) {
      var _open = XMLHttpRequest.prototype.open;
      var _send = XMLHttpRequest.prototype.send;

      XMLHttpRequest.prototype.open = function (method, url) {
        this.__wpc_block_ga = isGA(url);
        return _open.apply(this, arguments);
      };

      XMLHttpRequest.prototype.send = function () {
        if (this.__wpc_block_ga) {
          try { this.abort(); } catch(e) {}
          return;
        }
        return _send.apply(this, arguments);
      };
    }

    /* ===============================
       Image pixel fallback
       =============================== */
    try {
      var desc = Object.getOwnPropertyDescriptor(Image.prototype, "src");
      if (desc && desc.set) {
        Object.defineProperty(Image.prototype, "src", {
          configurable: true,
          get: desc.get,
          set: function (v) {
            if (!isGA(v)) desc.set.call(this, v);
          }
        });
      }
    } catch(e) {}

  } catch (e) {
    // Fail open: never break analytics for humans
  }
})();
</script>
JS;
        }
        return '';
    }

    public function elementorAnimations($matches)
    {
        $animationData = $matches[1];
        if (strpos($animationData, '_animation')) {
            
            
            return $matches[0];
        }
        return $matches[0];
    }

    public function removeBgOverlay($html)
    {
        return '';
    }

    public function gtagDelay($src)
    {

        $tag = trim($src[0]);
        $srcToLower = strtolower($tag);

        
        return $tag;

        if (self::$isAmp->isAmp()) {
            return $tag;
        }

        if (strpos($tag, 'wps-inline') !== false) {
            return $tag;
        }

        
        if (strpos($srcToLower, 'optimizer.pixel') !== false || strpos($srcToLower, 'optimizer.adaptive') !== false || strpos($srcToLower, 'optimizer.local') !== false) {
            return $tag;
        }


        if (strpos($srcToLower, 'googletag') !== false || strpos($srcToLower, 'gtag') !== false || strpos($srcToLower, 'facebook') !== false || strpos($srcToLower, 'tween') !== false || strpos($srcToLower, 'fontawesome') !== false) {

            if (strpos($srcToLower, 'src=') === false) {
                if (strpos($srcToLower, 'type=') === false) {
                    $tag = str_replace('<script', '<script type="wpc-delay-last-script" data-from-wpc="3078"', $srcToLower);
                } else {
                    $tag = str_replace('text/javascript', 'wpc-delay-last-script', $srcToLower);
                }
            } else {
                if (strpos($srcToLower, 'type=') === false) {
                    $tag = str_replace('<script', '<script type="wpc-delay-last-script" data-from-wpc="3078"', $srcToLower);
                } else {
                    $tag = str_replace('text/javascript', 'wpc-delay-last-script', $srcToLower);
                }
            }

        }

        return $tag;
    }

    public function local_script_encode($html)
    {
        $slashed = addslashes($html[0]);
        $encoded = base64_encode($slashed);

        return '[script-wpc]' . $encoded . '[/script-wpc]';
    }

    public function local_script_decode($html)
    {
        $decode = str_replace('[script-wpc]', '', $html[0]);
        $decode = str_replace('[/script-wpc]', '', $decode);

        $decode = base64_decode($decode);
        $decode = stripslashes($decode);

        return $decode;
    }

    public function crittr_replace_css($links)
    {
        preg_match_all('/([a-zA-Z\-\_]*)\s*\=["|\'](.*?)["|\']/is', $links[0], $linkAtts);

        if (!empty($linkAtts[1])) {
            $linkHtml = '<link';
            $linkRel = '';

            $attNames = $linkAtts[1];
            $attValues = $linkAtts[2];

            foreach ($attNames as $i => $attName) {
                if ($attName == 'rel' && $attValues[$i] == 'dns-prefetch') {
                    $linkRel = $attValues[$i];
                } elseif ($attName == 'href') {
                    if (strpos($attValues[$i], self::$site_url) === false) {

                    } else {

                        if (strpos($attValues[$i], self::$zone_name) === false) {
                            $attValues[$i] = WPS_IC_URI . 'fixCss.php?zoneName=' . self::$zone_name . '&css=' . urlencode($attValues[$i]) . '&rand=' . time();
                        }

                    }
                }

                $linkHtml .= ' ' . $attName . '="' . $attValues[$i] . '"';
            }

            $linkHtml .= '/>';

            if ($linkRel == 'stylesheet') {
                return $linkHtml;
            } else {
                return $links[0];
            }


        } else {
            return $links[0];
        }
    }

    public function replace_source_tags($source)
    {

        preg_match_all('/([a-zA-Z0-9\-\_]*)\s*\=["\']([^"]*)["\']?/is', $source[0], $sourceAtts);
        if (!empty($sourceAtts[1])) {
            $iFrame = '<source';
            $hasClass = false;

            $attNames = $sourceAtts[1];
            $attValues = $sourceAtts[2];

            if (!in_array('loading', $attNames)) {
                $attNames[] = 'loading';
            }

            foreach ($attNames as $i => $attName) {
                if ($attName == 'src') {
                    $attName = 'data-wpc-src';
                } elseif ($attName == 'class') {
                    $hasClass = true;
                    $attValues[$i] .= ' wpc-iframe-delay';
                } elseif ($attName == 'loading') {
                    $attValues[$i] = 'lazy';
                }

                $iFrame .= ' ' . $attName . '="' . $attValues[$i] . '" ';
            }

            if (!$hasClass) {
                $iFrame .= 'class="wpc-iframe-delay"';
            }

            $iFrame .= '';

            return $iFrame;
        } else {
            return $source;
        }
    }

    
    
    
    
    
    
    public static function wpc_facade_aggr_ok()
    {
        static $wpc_ok366 = null;
        if ($wpc_ok366 !== null) {
            return $wpc_ok366;
        }
        $wpc_ok366 = false;
        try {
            if (defined('WPS_IC_AGENCY') && WPS_IC_AGENCY) {
                return $wpc_ok366;
            }
            if (!empty($_GET['disableDelay']) || !empty($_GET['criticalCombine']) || !empty($_GET['disableCritical'])) {
                return $wpc_ok366;
            }
            if (function_exists('wpcGetHeader') && !empty(wpcGetHeader('criticalCombine'))) {
                return $wpc_ok366;
            }
            if (is_object(self::$isAmp) && method_exists(self::$isAmp, 'isAmp') && self::$isAmp->isAmp()) {
                return $wpc_ok366;
            }
            if (isset(self::$page_excludes['delay_js_v2']) && self::$page_excludes['delay_js_v2'] == '0') {
                return $wpc_ok366;
            }
            if (!empty(self::$delay_js_override) || !empty(self::$preloaderAPI)) {
                return $wpc_ok366;
            }
            if (isset(self::$settings['delay-js-v3']) && self::$settings['delay-js-v3'] == '0') {
                return $wpc_ok366; 
            }
            if (!class_exists('wps_ic_js_delay_v3')
                || !wps_ic_js_delay_v3::wpc_aggr_live()
                || !wps_ic_js_delay_v3::wpc_delay_master_on(self::$settings)) {
                return $wpc_ok366;
            }
            $wpc_ok366 = true;
        } catch (\Throwable $e) {
            $wpc_ok366 = false;
        }
        return $wpc_ok366;
    }

    public function replace_iframe_tags($iframe)
    {
        if (strpos($iframe[0], 'gform') !== false || strpos($iframe[0], 'data-src-cmplz') !== false) {
            return $iframe[0];
        }


        $wpc_if    = $iframe[0];
        $wpc_if_ns = str_replace(' ', '', strtolower($wpc_if));
        if (stripos($wpc_if, 'data-initial-iframe-hidden') !== false
            || strpos($wpc_if_ns, 'visibility:hidden') !== false
            || strpos($wpc_if_ns, 'opacity:0') !== false
            || strpos($wpc_if_ns, 'display:none') !== false
            || strpos($wpc_if_ns, 'left:-9999') !== false
            || strpos($wpc_if_ns, 'left:-99999') !== false) {
            return $iframe[0];
        }
        
        
        
        
        if ((stripos($wpc_if, 'leadconnectorhq.com') !== false || stripos($wpc_if, 'msgsndr') !== false)
            && !self::wpc_facade_aggr_ok()) {
            return $iframe[0];
        }

        preg_match_all('/([a-zA-Z0-9\-\_]*)\s*\=(["\'])([^"\']*)\2/is', $iframe[0], $iframeAtts);

        if (!empty($iframeAtts[1])) {
            $attNames = $iframeAtts[1];
            $srcIndex = array_search('src', $attNames, true);
            $hasSrc = $srcIndex !== false && !empty($iframeAtts[3][$srcIndex]);
            $hasDataSrc = in_array('data-src', $attNames, true) || in_array('data-wpc-src', $attNames, true);

            if (!$hasSrc) {
                return $iframe[0];
            }

            if ($hasDataSrc && $hasSrc) {
                $srcIndex = array_search('src', $attNames, true);
                $srcValue = $iframeAtts[3][$srcIndex];

                if (strpos($srcValue, 'data:') === 0) {
                    
                    return $iframe[0];
                }
            }

            $iFrame = '<iframe';
            $hasClass = false;

            $attNames = $iframeAtts[1];
            $attValues = $iframeAtts[3];

            foreach ($attNames as $i => $attName) {
                if ($attName == 'src') {
                    $attName = 'data-wpc-src';
                    $escapedValue = $this->conditionallyEscapeUrl($attValues[$i]);
                } elseif ($attName == 'class') {
                    $hasClass = true;
                    $attValues[$i] .= ' wpc-iframe-delay';
                    $escapedValue = htmlspecialchars($attValues[$i], ENT_QUOTES, 'UTF-8');
                } elseif ($attName == 'loading') {
                    $attValues[$i] = 'lazy';
                    $escapedValue = $attValues[$i];
                } else if ($attName == 'data-src') {
                    $escapedValue = $this->conditionallyEscapeUrl($attValues[$i]);
                } else {
                    $escapedValue = htmlspecialchars($attValues[$i], ENT_QUOTES, 'UTF-8');
                }

                $iFrame .= ' ' . $attName . '="' . $escapedValue . '"';
            }

            if (!$hasClass) {
                $iFrame .= ' class="wpc-iframe-delay"';
            }

            $iFrame .= '></iframe>';

            return $iFrame;
        } else {
            return $iframe[0]; 
        }
    }

    private function conditionallyEscapeUrl($url)
    {
        
        $encodedPatterns = ['&amp;',     
            '&#038;',    
            '%20',       
            '%2C',       
            '&quot;',    
            '&lt;',      
            '&gt;'       
        ];

        foreach ($encodedPatterns as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return $url; 
            }
        }

        
        if (preg_match('/&[a-zA-Z0-9#]+;/', $url)) {
            return $url; 
        }

        
        if (strpos($url, '&') !== false || strpos($url, '"') !== false || strpos($url, '<') !== false || strpos($url, '>') !== false) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }

        return $url;
    }

    public function maybe_addslashes($image, $addslashes = false)
    {
        if ($addslashes) {
            $image = addslashes($image);
        }

        return $image;
    }

    public function specialChars($url)
    {
        if (!self::$brizyActive) {
            $url = htmlspecialchars($url);
        }

        return $url;
    }

    public function local_image_tags($image)
    {
        $class_Addon = '';
        $image_tag = $image[0];
        $image_source = '';
        $webP = false;
        $isLazy = false;

        
        if ($this->defaultExcluded($image[0])) {
            return $image[0];
        }

        
        if (strpos($image[0], '.webp') === false && strpos($image[0], '.jpg') === false && strpos($image[0], '.jpeg') === false && strpos($image[0], '.png') === false && strpos($image[0], '.ico') === false && strpos($image[0], '.svg') === false && strpos($image[0], '.gif') === false) {
            return $image[0];
        }

        
        if (self::is_excluded($image[0])) {
            $image_source = $image[0];
            $image_source = preg_replace('/class=["|\'](.*?)["|\']/is', 'class="$1 wps-ic-loaded"', $image_source);

            return $image_source;
        }

        if ((self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0') && !self::image_url_matching_site_url($image[0])) {
            return $image[0];
        }

        
        
        $wpcHidden717 = false;
        if (!empty(self::$deviceHiddenSet717) && function_exists('wpc_device_hidden_has')) {
            foreach (['src', 'data-src', 'data-cp-src'] as $wpc_att717) {
                if (preg_match('/\b' . $wpc_att717 . '="([^"]+)"/i', $image[0], $wpc_m717)
                    && wpc_device_hidden_has(self::$deviceHiddenSet717, $wpc_m717[1])) {
                    $wpcHidden717 = true;
                    break;
                }
            }
        }
        if (!$wpcHidden717 && class_exists('wps_rewriteLogic')
            && method_exists('wps_rewriteLogic', 'wpc_census_below_fold793')
            && wps_rewriteLogic::wpc_census_below_fold793($image[0])) {
            $wpcHidden717 = true;
        }

        
        if (!$wpcHidden717) {
            self::$lazyLoadedImages++;
        }


        $original_img_tag = [];
        $original_img_tag['original_tags'] = $this->getAllTags($image[0], []);

        if (!empty($original_img_tag['original_tags']['src']) && empty($original_img_tag['original_tags']['data-src'])) {
            $image_source = $original_img_tag['original_tags']['src'];
        } else {
            $image_source = $original_img_tag['original_tags']['data-src'];
        }

        $original_img_tag['original_src'] = $image_source;

        

        
        preg_match('/srcset=["|\']([^"]+)["|\']/', $image_tag, $image_srcset);
        if (!empty($image_srcset[1])) {
            $original_img_tag['srcset'] = $image_srcset[1];
        }

        $size = self::get_image_size($image_source);

        $svgAPI = $source_svg = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="' . $size[0] . '" height="' . $size[1] . '"><path d="M2 2h' . $size[0] . 'v' . $size[1] . 'H2z" fill="#fff" opacity="0"/></svg>');

        
        $original_img_src = $image_source;

        
        $site_url = str_replace(['https://', 'http://'], '', self::$site_url);
        $image_path = str_replace(['https://' . $site_url . '/', 'http://' . $site_url . '/'], '', $image_source);
        $image_path = explode('?', $image_path);
        $image_path = ABSPATH . $image_path[0];

        


        if (!file_exists($image_path)) {
            return $image[0];
        } else {


            $wpc_ng_ceiling = class_exists('WPC_Delivery_Resolver')
                ? WPC_Delivery_Resolver::effective_ceiling(self::$settings) : 'avif';
            if ($wpc_ng_ceiling !== 'off' && (self::$webp == 'true' || self::$webp == '1')) {
                
                $webP = wps_rewriteLogic::swap_ext_to($image_path, 'webp');

                if (!file_exists($webP)) {
                    $webP = false;
                    $image_source = $original_img_src;
                } else {
                    $original_img_src = wps_rewriteLogic::swap_ext_to($original_img_src, 'webp');
                    $image_source = $original_img_src;
                }
            } else {
                $image_source = $original_img_src;
            }
        }


        
        if (!empty(self::$lazy_enabled) && self::$lazy_enabled == '1' && !self::$lazy_override) {

            if ($wpcHidden717 || self::$lazyLoadedImages >= self::$lazyLoadSkipFirstImages) {
                $isLazy = true;

                
                if (strpos($image_source, 'logo') !== false) {
                    $image_tag = 'src="' . $image_source . '"';
                } else {
                    $image_tag = 'src="' . $svgAPI . '"';
                }

                $image_tag .= ' data-src="' . $image_source . '"';


                $lazyClass = 'wps-ic-local-lazy';
                if (self::$settings['js'] == 1) {
                    $lazyClass = 'wps-ic-lazy-image';
                }

                
                if (strpos($image_source, 'logo') !== false) {
                    
                    $class_Addon .= $lazyClass . ' wps-ic-logo';
                } else {
                    
                    $class_Addon .= $lazyClass . ' ';
                }

            } else {
                $image_tag = 'src="' . $image_source . '"';
            }

        } else if ((!empty(self::$native_lazy_enabled) && self::$native_lazy_enabled == '1' && !self::$lazy_override)) {
            $image_tag = 'src="' . $image_source . '"';

            if (!$wpcHidden717 && self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages) {
                
            } else {
                
                if (!strpos($image_source, 'logo')) {
                    $image_tag .= ' loading="lazy"';
                }
            }

        } else {
            if (!empty(self::$adaptive_enabled) && self::$adaptive_enabled == '1') {
                $image_tag = 'src="' . $image_source . '"';
                $image_tag .= ' data-adaptive="true"';
                $image_tag .= ' data-remove-src="true"';
            } else {
                $image_tag = 'src="' . $image_source . '"';
                $image_tag .= ' data-adaptive="false"';
            }

            $image_tag .= ' data-src="' . $image_source . '"';
        }

        $image_tag .= ' data-count-lazy="' . self::$lazyLoadedImages . '"';

        if (!empty(self::$settings['fetchpriority-high']) && self::$settings['fetchpriority-high'] == '1') {
            if (!$wpcHidden717 && self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages) {
                $image_tag .= ' fetchpriority="high" decoding="async"';
            }
        }


        


        $srcset_att = '';

        if (self::$webp == 'true' || self::$webp == '1') {
            if (!empty($original_img_tag['srcset'])) {
                $exploded_scrcset = explode(',', $original_img_tag['srcset']);
                if (!empty($exploded_scrcset)) {
                    foreach ($exploded_scrcset as $i => $src) {
                        $src = trim($src);
                        $src_w = explode(' ', $src);

                        if (!empty($src_w)) {
                            $real_src = $src_w[0];
                            
                            
                            $real_src_width = $src_w[1] ?? '';
                            if ($real_src_width === '') continue;

                            $image_path = str_replace(self::$site_url . '/', '', $real_src);
                            $image_path_webP = ABSPATH . $image_path;

                            $webP = wps_rewriteLogic::swap_ext_to($real_src, 'webp');
                            $image_path_webP = wps_rewriteLogic::swap_ext_to($image_path_webP, 'webp');

                            if (!file_exists($image_path_webP)) {
                                $srcset_att .= $real_src . ' ' . $real_src_width . ',';
                            } else {
                                $srcset_att .= $webP . ' ' . $real_src_width . ',';
                            }
                        }
                    }
                }
                $srcset_att = rtrim($srcset_att, ',');
            }
        }


        if (empty($srcset_att)) {
            $srcset_att = $original_img_tag['srcset'] ?? '';
        }

        if (!empty(self::$removeSrcset) && self::$removeSrcset == '1') {
            unset($original_img_tag['original_tags']['srcset']);
        } else {
            if (!empty($srcset_att)) {
                $srcsetAttr = $isLazy ? 'data-srcset' : 'srcset';
                $image_tag .= ' ' . $srcsetAttr . '="' . $srcset_att . '" ';
                unset($original_img_tag['original_tags']['srcset']);
            }
        }

        if (!empty($original_img_tag['original_tags'])) {
            foreach ($original_img_tag['original_tags'] as $tag => $value) {
                if ($tag == 'class') {
                    $value = $class_Addon . ' ' . $value;
                }

                if ($tag == 'src' || $tag == 'data-src') {
                    continue;
                }

                if (!is_null($value)) {
                    $image_tag .= $tag . '="' . $value . '" ';
                } else {
                    $image_tag .= $tag . ' ';
                }
            }
        }

        $finalTag = '<img ' . $image_tag . ' />';


        $wpc_pic_ceiling = class_exists('WPC_Delivery_Resolver')
            ? WPC_Delivery_Resolver::effective_ceiling(self::$settings) : 'avif';
        if (self::$rewriteLogic::$pictureWebpEnabled && $webP !== false && $wpc_pic_ceiling !== 'off') {
            $lowerSrc = strtolower($original_img_tag['original_src']);
            $skipFormats = (strpos($lowerSrc, '.svg') !== false || strpos($lowerSrc, '.gif') !== false || strpos($lowerSrc, '.ico') !== false || strpos($lowerSrc, '.webp') !== false);

            if (!$skipFormats) {
                
                
                $fallbackTag = $finalTag;
                if (!empty($srcset_att) && !empty($original_img_tag['srcset'])) {
                    
                    $srcsetAttrInTag = $isLazy ? 'data-srcset' : 'srcset';
                    $fallbackTag = str_replace($srcsetAttrInTag . '="' . $srcset_att . '"', $srcsetAttrInTag . '="' . $original_img_tag['srcset'] . '"', $fallbackTag);
                }
                $fallbackTag = str_replace($image_source, $original_img_tag['original_src'], $fallbackTag);

                
                $srcsetKey = $isLazy ? 'data-srcset' : 'srcset';
                $sourceSrcset = !empty($srcset_att) ? ' ' . $srcsetKey . '="' . $srcset_att . '"' : ' ' . $srcsetKey . '="' . $image_source . '"';
                $sourceSizes = '';
                if (preg_match('/sizes="([^"]*)"/', $finalTag, $szMatch)) {
                    $sourceSizes = ' sizes="' . $szMatch[1] . '"';
                }


                $avifSource = '';
                if (self::$rewriteLogic::$pictureAvifEnabled) {


                    $avifBaseUrl  = preg_replace('/\?.*$/', '', (string) $original_img_tag['original_src']);
                    $avifBaseRel  = preg_replace('#^(?:https?:)?//[^/]+#', '', $avifBaseUrl);
                    $avifMainPath = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', ABSPATH . ltrim($avifBaseRel, '/'));
                    if (@file_exists($avifMainPath)) {


                        $avifEntries = [];
                        if (!empty($original_img_tag['srcset'])) {
                            foreach (explode(',', (string) $original_img_tag['srcset']) as $ent) {
                                $ent = trim($ent);
                                if ($ent === '' || !preg_match('/^(\S+)(\s+\S+)?$/', $ent, $em)) continue;
                                $eAvifUrl = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', preg_replace('/\?.*$/', '', $em[1]));
                                $eRel     = preg_replace('#^(?:https?:)?//[^/]+#', '', $eAvifUrl);
                                if (@file_exists(ABSPATH . ltrim($eRel, '/'))) {
                                    $avifEntries[] = $eAvifUrl . (isset($em[2]) ? $em[2] : '');
                                }
                            }
                        }
                        if (empty($avifEntries)) {
                            
                            $avifEntries[] = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', $avifBaseUrl);
                        }
                        $avifSource = '<source ' . $srcsetKey . '="' . implode(', ', $avifEntries) . '"' . $sourceSizes . ' type="image/avif">';
                    }
                }

                $finalTag = '<picture class="wpc-picture">' . $avifSource . '<source' . $sourceSrcset . $sourceSizes . ' type="image/webp">' . $fallbackTag . '</picture>';
            }
        }


        if ($webP !== false && strncmp($finalTag, '<picture', 8) !== 0 && self::wpc_universal_picture_on()) {
            if (!empty($srcset_att) && !empty($original_img_tag['srcset'])) {
                $wpc_ss_attr72 = $isLazy ? 'data-srcset' : 'srcset';
                $finalTag = str_replace($wpc_ss_attr72 . '="' . $srcset_att . '"', $wpc_ss_attr72 . '="' . $original_img_tag['srcset'] . '"', $finalTag);
            }
            $finalTag = str_replace($image_source, $original_img_tag['original_src'], $finalTag);
        }

        return $finalTag;
    }

    public function getAllTags($image, $ignore_tags = ['src', 'srcset', 'data-src', 'data-srcset'])
    {
        $found_tags = [];

        
        preg_match_all('/([a-zA-Z_-]+(?:--[a-zA-Z_-]+)*)(?:\s*=\s*(?:"((?:[^"\\\\]|\\\\.|&[a-zA-Z0-9#]+;)*)"|\'((?:[^\'\\\\]|\\\\.|&[a-zA-Z0-9#]+;)*)\'|([^>\s]+)))?/', $image, $matches, PREG_SET_ORDER);

        $attributes = [];
        unset($matches[0]);

        foreach ($matches as $match) {
            $attrName = $match[1];
            $attrValue = null;


            foreach ([2, 3, 4] as $index) {
                if (!empty($match[$index])) {
                    $attrValue = $match[$index];
                    break;
                }
            }

            
            
            if ($attrValue !== null && (strpos($attrName, 'data-') === 0) && (strpos($attrValue, '[{') !== false || strpos($attrValue, '{') !== false) && strpos($attrValue, '&quot;') !== false) {
                
                
                $attributes[$attrName] = $attrValue;
            } else {
                
                $attributes[$attrName] = $attrValue ? html_entity_decode($attrValue) : $attrValue;
            }
        }

        
        foreach ($attributes as $tag => $value) {
            if (!empty($ignore_tags) && in_array($tag, $ignore_tags)) {
                continue;
            }

            if ($tag == 'data-mk-image-src-set') {
                $value = htmlspecialchars_decode($value);
                $decoded = json_decode($value, true);
                if ($decoded && isset($decoded['default'])) {
                    $value = $decoded['default'];
                }
            }

            $found_tags[$tag] = $value;
        }

        return $found_tags;
    }

    public static function get_image_size($url)
    {
        preg_match("/([0-9]+)x([0-9]+)\.[a-zA-Z0-9]+/", $url, $matches);
        if (isset($matches[1]) && isset($matches[2])) {
            return [$matches[1], $matches[2]];
            $sizes = [$matches[1], $matches[2]];
        } else {
            return [1024, 1024];
        }

        return $sizes;
    }

    public function rewrite_woo_variation_image_urls($variation, $product, $variation_obj)
    {
        if (empty($variation['image']) || empty(self::$rewriteLogic) || empty(self::$zone_name)) {
            return $variation;
        }

        $url_keys = ['url', 'src', 'full_src', 'gallery_thumbnail_src', 'thumb_src'];
        foreach ($url_keys as $key) {
            if (!empty($variation['image'][$key])) {
                $variation['image'][$key] = self::$rewriteLogic->replaceSourceSrcset([$variation['image'][$key]]);
            }
        }

        if (!empty($variation['image']['srcset'])) {
            $variation['image']['srcset'] = preg_replace_callback('/(?:https?:\/\/|\/)[^\s]+\.(jpg|jpeg|png|gif|svg|webp)/i', [self::$rewriteLogic, 'replaceSourceSrcset'], $variation['image']['srcset']);
        }

        return $variation;
    }


}