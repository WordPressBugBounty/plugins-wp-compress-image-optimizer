<?php
/**
 * Plugin: WP Compress – Instant Performance & Speed Optimization
 * Description: Legitimate script handling for WP Compress Optimizer
 */

if (!function_exists('wpc_force_natural')) {
    /**
     * Operator override (default OFF) to emit clean natural URLs everywhere instead of /q:i/wp:N/w:N/
     * transforms. NOT a safe default: it bypasses the orch's native_accept_vary witness, the gate that
     * blocks vary-blind CF cache-poisoning — only enable on a zone you've confirmed is vary-correct AND
     * OTF-live. The safe road to all-natural is to provision the zone (orch then echoes the witness).
     *
     * Enable via WPC_FORCE_NATURAL in wp-config.php or the wpc_force_natural filter. Cached per-request.
     */
    function wpc_force_natural()
    {
        // KILL is absolute and wins over force. This is the first OR-term of $otf_live in the
        // bare-<img>/CSS-bg naturalize lane, which isn't gated by natural_assets_on(); without the
        // guard a site with both KILL and force defined would still naturalize under KILL. Check it
        // before the static cache too, so a force value cached earlier this request can't survive a kill.
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) {
            return false;
        }
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        $on = defined('WPC_FORCE_NATURAL') && WPC_FORCE_NATURAL;
        $cached = (bool) apply_filters('wpc_force_natural', $on);
        return $cached;
    }
}

if (!function_exists('wpc_cf_cname_verified_ok')) {
    /**
     * Shared FAIL-OPEN verified-gate for emitting the CF custom CNAME. Returns true unless the cname was
     * EXPLICITLY cleared by a cname change (flag === '0') — the brief window between a save and
     * verifyCfCnameLive promoting it. A never-set flag (default 'legacy') counts as verified: an
     * already-serving zone and a fresh connect both legitimately have no flag yet, and blocking them
     * would degrade the live fleet. Used by every CF-cname emit surface so they stay consistent.
     */
    function wpc_cf_cname_verified_ok()
    {
        $v = function_exists('get_option') ? get_option('wpc_cf_cname_verified', 'legacy') : 'legacy';
        return !($v === '0' || $v === 0);
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
    public static $preloaderAPI;
    public static $excludes_class;
    public static $assets_to_preload;
    public static $assets_to_defer;
    public static $emoji_remove;
    public static $isAjax;
    public static $brizyCache;
    public static $brizyActive;
    public static $regExURL;

    // Regexp Url & Dirs
    public static $regExDir;
    public static $findImages;
    public static $apiUrl;

    // Predefined API URLs
    public static $apiAssetUrl;
    public static $updir;

    // Site URL, Upload Dir
    public static $home_url;
    public static $site_url;
    public static $site_url_scheme;
    public static $svg_placeholder;

    // SVG Placeholder (empty svg)
    public static $excludes;


    // CSS / JS Variables
    public static $fonts;
    public static $css;
    public static $css_img_url;
    public static $css_minify;
    public static $js;
    public static $js_minify;
    public static $replaceAllLinks;

    // Image Compress Variables
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

    //Overrides
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

        // Theme Integrations
        require_once WPS_IC_DIR . 'integrations/themes/theme.integrations.php';
        self::$themeIntegrations = new ThemeIntegrations();

        // Lazy Limits
        self::$lazyLoadedImages = 0;
        self::$lazyLoadedImagesLimit = 1;

        self::$settings = get_option(WPS_IC_SETTINGS);
        self::$excludes = get_option('wpc-excludes');

//        self::$settings['mcCriticalCSS'] = '';
//        update_option(WPS_IC_SETTINGS, self::$settings);
//        self::$settings = get_option(WPS_IC_SETTINGS);

        // Decide to Load new API or Old Api for Critical CSS
        if (empty(self::$settings['mcCriticalCSS']) || self::$settings['mcCriticalCSS'] == 'mc') {
            include_once WPS_IC_DIR . 'addons/criticalCss/criticalCss-v2.php';
        } else {
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

        self::$excludes['cdn'][] = '.php'; //pagelayer .php requests fix
        self::$excludes['cdn'][] = '/wp-fastest-cache/'; //icon in admin bugfix
        self::$excludes['cdn'][] = '/wp-content/plugins/ameliabooking/v3/public/assets/'; //amelia fix
        self::$excludes['cdn'][] = '/vue3'; //issues with vue libraries on cdn
        self::$excludes['cdn'][] = 'sharethis.js';
        if (defined('ELEMENTOR_VERSION')) {
            // Elementor's webpack runtime resolves its own lazy-loaded chunk URLs; CDN-rewriting the
            // base path breaks chunk loading, so both runtimes stay on origin.
            self::$excludes['cdn'][] = 'webpack.runtime.min.js';
            self::$excludes['cdn'][] = 'webpack-pro.runtime.min.js';
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
        $current_url = wpc_request_scheme() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]"; // proxy-aware scheme
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

        // Don't run in admin side!
        if (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") {
            return true;
        }

        // TODO: Check this for wpadmin and frontend ajax
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
        // Hard bypass via ?disableWPC=true OR HTTP_DISABLEWPC header
        // Mirrors the plugin-entry-point check in wp-compress.php so any code path
        // that reaches here also respects the runtime kill switch.
        if (!empty($_GET['disableWPC']) || isset($_SERVER['HTTP_DISABLEWPC'])) {
            return false;
        }

        // URL exclusions (wildcard support) — auto-enabled when patterns exist
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

        // Any hide login plugins active?
        if (self::hiddenAdminArea()) {
            return false;
        }

        //WP User Frontend check
        if (class_exists('WP_User_Frontend')) {
            $content = get_post_field('post_content', get_the_ID());

            // Check if the content contains wpuf shorcode
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

        if (!empty($_POST['_cs_nonce'])) { //cornerstone
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
            //optimizePress builder fix
            return false;
        }

        if (!empty($_POST['pp_action'])) {
            //power pack for beaver builder ajax get posts fix
            return false;
        }

        if (!empty($_POST['add-to-cart'])) {
            //woo on some themes slow add to cart fix
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

        //GiveWP routes
        if (isset($_GET['givewp-route'])) {
            return false;
        }

        //Groundhogg calendar
        if (!empty($_SERVER['REQUEST_URI'])) {
            if (strpos($_SERVER['REQUEST_URI'], '/gh/calendar')) {
                return false;
            }
        }

        return true;
    }

    public static function hiddenAdminArea()
    {

        // AIOS
        if (class_exists('AIO_WP_Security')) {
            // Hide Login Exists
            $configs = get_option('aio_wp_security_configs');
            if (!empty($configs['aiowps_login_page_slug'])) {
                if (strpos($_SERVER['REQUEST_URI'], $configs['aiowps_login_page_slug']) !== false) {
                    return true;
                }
            }
        }

        // WPS Hide Login
        if (class_exists('WPS\WPS_Hide_Login\Plugin')) {
            // Hide Login Exists
            $loginPage = get_option('whl_page');
            if (!empty($loginPage)) {
                if (strpos($_SERVER['REQUEST_URI'], '/' . $loginPage) !== false) {
                    return true;
                }
            }
        }

        // Hide My WP - Ghost
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

        if (!empty($_GET['dbg_reformaturl_first'])) {
            return print_r([$url, $remove_site_url], true);
        }

        if (strpos($url, 'login') !== false) {
            return $url;
        }

        // Check if url is maybe a relative URL (no http or https)
        if (strpos($url, 'http') === false) {
            // Check if url is maybe absolute but without http/s
            if (strpos($url, '//') === 0) {
                // Just needs http/s
                $url = 'https:' . $url;
            } else {

                if (strpos($url, '/') !== 0) {
                    $url = str_replace('../wp-content', 'wp-content', $url);
                    //if we replace all we break things like '.../wp-content/cache/min/1/wp-content/...'
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
            $formatted_url = explode('?', $formatted_url);
            $formatted_url = $formatted_url[0];
        }

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'log_url_format') {
            $fp = fopen(WPS_IC_LOG . 'url_Format.txt', 'a+');
            fwrite($fp, 'URL: ' . $formatted_url . "\r\n");
            fwrite($fp, 'Site URL: ' . self::$site_url . "\r\n");
            fwrite($fp, 'Slashes: ' . addcslashes(self::$site_url, '/') . "\r\n");
            fwrite($fp, '---' . "\r\n");
            fclose($fp);
        }

        if ($remove_site_url) {
            $formatted_url = str_replace(self::$site_url, '', $formatted_url);
            $formatted_url = str_replace(str_replace(['https://', 'http://'], '', self::$site_url), '', $formatted_url);
            $formatted_url = str_replace(addcslashes(self::$site_url, '/'), '', $formatted_url);
            $formatted_url = ltrim($formatted_url, '\/');
            $formatted_url = ltrim($formatted_url, '/');
        }

        if (!empty($_GET['dbg_reformaturl'])) {
            return print_r([$url, $formatted_url], true);
        }

        //if (!empty(self::$cdnEnabled) && self::$cdnEnabled == '1') {
        if (self::$randomHash == 0 && strpos($formatted_url, '.css') !== false) {
            $formatted_url .= '?icv=' . WPS_IC_HASH;
        }

        if (self::$randomHash == 0 && preg_match('/\.js(?:[?#]|$)/i', $formatted_url)) {
            $formatted_url .= '?js_icv=' . WPS_IC_JS_HASH;
        }

        if (self::$randomHash != 0) {
            return $formatted_url . '?icv_random=' . self::$randomHash;
        }
        //}

        return $formatted_url;
    }

    public static function is_image($image)
    {
        if (strpos($image, '.webp') === false && strpos($image, '.jpg') === false && strpos($image, '.jpeg') === false && strpos($image, '.png') === false && strpos($image, '.ico') === false && strpos($image, '.svg') === false && strpos($image, '.gif') === false) {
            return false;
        } else {
            // Serve JPG Enabled?
            if (strpos($image, '.jpg') !== false || strpos($image, '.jpeg') !== false) {
                // is JPEG enabled
                if (self::$settings['serve']['jpg'] == '0') {
                    return false;
                }
            }

            // Serve GIF? Never via the Bunny CDN: GIFs get no next-gen conversion, so on Bunny it's
            // pure WPC egress. CF-direct zones only.
            if (strpos($image, '.gif') !== false) {
                if (self::$settings['serve']['gif'] == '0'
                    || !class_exists('wps_rewriteLogic') || !wps_rewriteLogic::cf_is_delivery()) {
                    return false;
                }
            }

            // Serve PNG Enabled?
            if (strpos($image, '.png') !== false) {
                // is PNG enabled
                if (self::$settings['serve']['png'] == '0') {
                    return false;
                }
            }

            // Serve SVG Enabled?
            if (strpos($image, '.svg') !== false) {
                // is SVG enabled
                if (self::$settings['serve']['svg'] == '0') {
                    return false;
                }
            }

            // Images-master gate for .webp/.ico — the only formats with no per-serve-key check above.
            // When the "Images" tile is OFF, image CDN delivery stands down (jpg/gif/png/svg are
            // already gated by their serve keys).
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

        // TODO: Hrvoje
        if (strpos(strtolower($src), 'tweenmax') !== false) {
            $urlGet = false;
            // TODO: Move to default defers
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

            // TODO: Make more elegant
            if (strpos($handle, 'awesome') !== false) {
                $tag .= '<script type="text/javascript" defer class="wps-inline" id="' . $handle . '-js">' . $this->get_script_content($url) . '</script>';
            } else {
                if (strpos($handle, 'aio') !== false || strpos($handle, 'theme') !== false) {
                    $tag .= '<script type="text/javascript" class="wps-inline" id="' . $handle . '-js" defer>' . $this->get_script_content($url) . '</script>';
                } else {
                    $tag .= '<script type="wpc-delay-script" class="wps-inline" id="' . $handle . '-js">' . $this->get_script_content($url) . '</script>';
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
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        // Bound the fetch — this runs during front-end output buffering, and with no timeout a
        // slow/dead remote URL stalls real visitors (curl falls back to max_execution_time). Degrade
        // to '' on failure so the caller keeps the original markup.
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $data = curl_exec($ch);
        curl_close($ch);

        return ($data === false) ? '' : $data;
    }

    public function get_script_content($url)
    {
        //
        //    $ch = curl_init();
        //    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //    curl_setopt($ch, CURLOPT_URL, $url);
        //    $data = curl_exec($ch);
        //    curl_close($ch);

        $relativePath = wp_make_link_relative($url);
        $path = ltrim($relativePath, '/');

        //check if is folder install and if folder is in url remove it (it is already in ABSPATH)
        $last_abspath = basename(ABSPATH);
        $first_path = explode('/', $path)[0];
        if ($last_abspath == $first_path) {
            $path = substr($path, strlen($first_path));
            $path = ltrim($path, '/');
        }

        $path = explode('?', $path);
        $path = $path[0];

        // TODO: What if file does not exist?
        if (!file_exists(ABSPATH . $path)) {
            // Can't just return empty , because it's in script tags, fix!!
        }

        $content = file_get_contents(ABSPATH . $path);

        // Remove comments
        $jsCode = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $content);

        return $jsCode;
    }

    public function dnsPrefetch()
    {
        // Honor "Exclude from Plugin" — skip DNS prefetch / preconnect injection on excluded URLs
        if (!self::dontRunif()) {
            return;
        }
        if (strlen(trim(self::$zone_name)) > 0) {
            if (!empty($_GET['dbg']) && $_GET['dbg'] == 'direct') {
                if (!empty($_GET['custom_server'])) {
                    $custom_server = sanitize_text_field($_GET['custom_server']);

                    if (preg_match('/^[a-z0-9\-]+\.zapwp\.net$/i', $custom_server)) {
                        self::$zone_name = $custom_server . '/key:' . self::$options['api_key'];
                        echo '<link rel="dns-prefetch" href="//' . $custom_server . '" />';
                    }
                }
            } else {
//				echo '<link rel="dns-prefetch" href="https://cdn.zapwp.net" />';
//				echo '<link rel="preconnect" href="https://cdn.zapwp.net">';
                echo '<link rel="dns-prefetch" href="https://optimizerwpc.b-cdn.net" />';
                echo '<link rel="preconnect" href="https://optimizerwpc.b-cdn.net">';
                echo '<link rel="preconnect" href="https://optimize-v2.b-cdn.net/">';
                echo '<link rel="dns-prefetch" href="//' . self::$zone_name . '" />';
                echo '<link rel="preconnect" href="https://' . self::$zone_name . '">';
                // Crossorigin preconnect for the cdn zone. The non-crossorigin tag above only warms
                // the connection for non-CORS resources (images); fonts are fetched with crossorigin
                // per spec and need their own preconnect, or the browser pays a full TLS handshake on
                // the first font. Both tags let it pick the right connection per resource type.
                echo '<link rel="preconnect" href="https://' . self::$zone_name . '" crossorigin>';
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

        if (!empty($_GET['dbg_src_excludes'])) {
            return print_r([$tag, $src, self::isExcludedFrom('cdn', $src), self::$excludes]);
        }

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

        //remove version, needed for cdn
        if (strpos($src, '.js')) {
            $verPosition = strpos($src, '?ver=');
            if ($verPosition !== false) {
                // Truncate the src to remove '?ver=' and everything after it
                $src = substr($src, 0, $verPosition);
            }
        }

        /**
         * TODO:
         * check if external is enabled
         */
        // (v7.03.49) EXTERNAL assets are never routed through the CDN — leave them on their origin (direct).
        // A third-party-host asset carries CORS + availability risk and gains little (it's already on its own
        // CDN); routing it produced the broken {origin}/m:0/a:… 404s. Unconditional now (was only when the
        // external-URL feature was off). Same-site assets (below) are unaffected — that's the core CDN.
        if (!self::image_url_matching_site_url($src)) {
            return $tag;
        }

        // ORIGIN FLOOR for JS: until the per-zone JS-MIME proof is satisfied (natural_assets_on() —
        // Bunny by construction, CF once proven), leave the origin <script> untouched (real
        // application/javascript from the filesystem, can never wrong-MIME). Proven → falls through.
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on') && !wps_rewriteLogic::natural_assets_on()) {
            return $tag;
        }
        if (self::$cdnEnabled == '1' && self::$js == '1') {
            if (strpos($src, self::$zone_name) === false) {
                $fileMinify = self::$js_minify;
                if (self::isExcluded('js_minify', $src)) {
                    $fileMinify = '0';
                }

                /**
                 * Same-site JS. When NOT minified (m:0) emit a clean NATURAL zone URL instead of the
                 * m:0/a: transform: m:0 is pure pass-through, so the natural URL is byte-identical (the
                 * edge serves the same origin asset — verified m:0 and natural both 302 to the file).
                 * This also makes a webpack bundle's auto-publicPath natural, so its runtime-loaded
                 * chunks become natural too (they derive their base from this script's src). When the
                 * edge IS minifying (m:1+) keep the transform — a natural URL wouldn't be minified.
                 * Only reached when natural_assets_on() (the JS-MIME proof) is satisfied (gated above).
                 */
                $abs = self::reformat_url($src, false);
                if (empty($fileMinify)) {
                    $pp = function_exists('wp_parse_url') ? wp_parse_url($abs) : parse_url($abs);
                    if (is_array($pp) && !empty($pp['path'])) {
                        $src = 'https://' . self::$zone_name . $pp['path']
                             . (isset($pp['query']) ? '?' . $pp['query'] : '')
                             . (isset($pp['fragment']) ? '#' . $pp['fragment'] : '');
                    } else {
                        $src = 'https://' . self::$zone_name . '/m:0/a:' . $abs; // unparsable → keep transform (never break)
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
                    // FIXED: Only replace src in the opening script tag, not in any content after
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

                // FIXED: Only replace src in the opening script tag, not in any content after
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

    public static function is_excluded_link($link)
    {
        /**
         * Is the link in excluded list?
         */
        if (empty($link)) {
            return false;
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
                    // Link is excluded
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Is link matching the site url?
     *
     * @param $image
     *
     * @return bool
     */
    public static function image_url_matching_site_url($image)
    {
        // Single leading slash = root-relative local path.
        // Double leading slash = protocol-relative external URL (e.g. //cdnjs.cloudflare.com/...) — treat as external.
        if (strpos($image, '//') !== 0 && (strpos($image, '/') === 0 || strpos($image, 'wp-content') === 0)) {
            return true;
        }
        $site_url = self::$site_url;
        $image = str_replace(['https://', 'http://'], '', $image);
        $site_url = str_replace(['https://', 'http://'], '', $site_url);

        if (strpos($image, '.css') !== false || strpos($image, '.js') !== false) {
            foreach (self::$default_excluded_list as $i => $excluded_string) {
                if (strpos($image, $excluded_string) !== false) {
                    return false;
                }
            }
        }

        if (strpos($image, $site_url) === false) {
            // Image not on site
            return false;
        } else {
            // Image on site
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
            // It has a src attribute, inline it
            if (strpos($href, self::$site_url) !== false) {
                // Href is local
                $content = file_get_contents($href);
                $content = self::$combineCss->minifyCSS($content);
                $return = '<style id="inline-css-' . mt_rand(999, 9999) . '">';
                $return .= $content;
                $return .= '</style>';

                return $return;
            }
        }

        return $html;
    }

    // TODO: IMPORANT! If you don't want to run it needs to return false!

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
                            // Rel exists, change it
                            $html = preg_replace('/rel\=["|\'](.*?)["|\']/', 'rel="preload" as="style" onload="this.rel=\'stylesheet\'" ', $html);
                        } else {
                            // Rel does not exist, create it
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

    /**
     * Natural-URL source emission. When natural assets are on, emit the clean cname URL from the
     * enqueue filter rather than the /m:N/a: transform form. The buffer pass naturalizes these for
     * normal page output anyway, but content printed AFTER the buffer flushes (late import-maps,
     * flush()'d footers, etc.) never reaches it and would otherwise ship the transform form.
     * Naturalizing at the source makes the URL form independent of buffer/flush timing. Idempotent.
     */
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

        /**
         * TODO:
         * check if external is enabled
         */
        // (v7.03.49) EXTERNAL css/js/assets are never routed through the CDN — leave them on their origin
        // (direct). A third-party-host asset carries CORS + availability risk and gains little (already on
        // its own CDN); routing it produced the broken {origin}/m:0/a:… 404s. Unconditional now (was only
        // when the external-URL feature was off). Same-site assets (below) are unaffected — that's the core CDN.
        if (!self::image_url_matching_site_url($src)) {
            return $src;
        }

        //remove version, needed for cdn
        if (strpos($src, '.css')) {
            if (strpos($src, '?ver=')) {
                $src = remove_query_arg('ver', $src);
            }
        }

        // ORIGIN FLOOR for same-origin css/js: unproven zone → leave the origin href (proven → the
        // m:N/a: build below runs and adjust_src_url naturalizes it).
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

                if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') {
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

    /**
     * SVGs are vector — no raster transform applies — yet the legacy emitters route them through
     * /q:/r:/wp:/w:/u: transform URLs. The edge serves uploads-path natural URLs verbatim by
     * extension, so collapse zone-transform SVG URLs back to the clean host-swapped form. Uploads
     * only: theme/plugin SVG paths 404 on some zone configs, so those keep their transform URLs.
     * Runs as the outermost buffer pass (catches every emitter, srcset + CSS url() included).
     */
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
                // substr keeps the origin URL's exact path+query bytes — no re-encoding.
                return 'https://' . self::$zone_name . substr($m[1], $pos);
            },
            $html
        );
    }

    /**
     * CSS-background image-set() master gate. Default ON. Piggybacks wpc_svg_zoneify_active() (cdn on
     * + live-cdn + images tile on + not suppressed + zone != origin) so it can only be active where
     * the same-ext host-swap already runs. KILL is the absolute off-ramp.
     */
    public static function wpc_css_bg_imageset_active()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false; // absolute off-ramp
        if (!self::wpc_svg_zoneify_active()) return false;                        // inherit the lane's gate
        $on = function_exists('get_option') ? get_option('wpc_css_bg_imageset', 1) : 1; // DEFAULT ON
        return (bool) apply_filters('wpc_css_bg_imageset', !empty($on));
    }

    /**
     * CSS-bg on-disk sibling resolver: origin uploads url -> local path -> file_exists on .avif/.webp.
     * A concrete css url maps directly to {base}.avif/.webp — no width inference.
     *
     * Never-404: an image-set() entry is type()-selected and commits (no onerror fall-through), so a
     * format is listed only when its file is on disk now. The arms also pass picture_variant_dims_ok()
     * so a dimensionally-corrupt sibling is dropped rather than committed.
     *
     * @param string $origin_url same-site origin uploads url
     * @return array ['avif'=>bool,'webp'=>bool]
     */
    public static function wpc_css_bg_disk_siblings($origin_url)
    {
        $out = ['avif' => false, 'webp' => false];
        $url = preg_replace('/[?#].*$/', '', (string) $origin_url);
        if ($url === '') return $out;

        $site = trailingslashit(site_url());
        $host = wp_parse_url($url, PHP_URL_HOST);
        $shst = wp_parse_url($site, PHP_URL_HOST);
        if (!$host || !$shst || strcasecmp((string) $host, (string) $shst) !== 0) return $out; // same-site
        if (strpos($url, '/wp-content/uploads/') === false) return $out;                       // uploads only

        $base = str_replace($site, trailingslashit(ABSPATH), $url);
        $base = str_replace('/', DIRECTORY_SEPARATOR, $base);
        if (strpos($base, trailingslashit(ABSPATH)) !== 0) return $out;                         // traversal guard

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

    /**
     * Build the CSS-bg image-set() format-upgrade for ONE matched background url(). Returns the full
     * two-declaration replacement, or '' to fall through to the caller's same-ext host-swap.
     *
     *   background-image:url(<zone same-ext>);                  <- pre-image-set floor (always 200)
     *   background-image:-webkit-image-set(<entries>);          <- prefix-only WebKit
     *   background-image:image-set(url(<avif>) type("image/avif"), url(<webp>) type("image/webp"),
     *                              url(<same-ext>) type("<mime>"));   <- self-select by type()
     *
     * Verified CDN-edge (Bunny+Vary): returns a single clean .webp natural URL; the edge negotiates
     * format by Accept, so no image-set and no .avif. Non-edge (CF / vary-blind / picture / off):
     * on-disk image-set only — type() self-selection is browser-side (vary-blind-safe, like a typed
     * <picture> <source>) and never-404 by on-disk existence; same-ext entry is always last.
     *
     * @param string $origin_url   ORIGIN clean url (for on-disk sibling resolution)
     * @param string $sameext_zone the zone-hosted same-ext url the caller already built
     * @param string $quote        original quote char inside url() ('' | "'" | '"')
     */
    public static function wpc_css_bg_imageset_build($origin_url, $sameext_zone, $quote = '')
    {
        if (!self::wpc_css_bg_imageset_active()) return '';                 // gate/KILL → caller keeps same-ext
        $origin_url   = (string) $origin_url;
        $sameext_zone = (string) $sameext_zone;
        if ($origin_url === '' || $sameext_zone === '') return '';

        $clean = preg_replace('/[?#].*$/', '', $origin_url);
        $ext   = strtolower(pathinfo($clean, PATHINFO_EXTENSION));
        // wpc_natural_nw lets the edge OTF avif/webp from a webp source too (E1), so webp origins join the
        // upgrade; otherwise raster only. gif/svg/data: stay excluded.
        $css_nw   = wps_rewriteLogic::wpc_natural_nw();
        $css_exts = $css_nw ? ['jpg', 'jpeg', 'png', 'webp'] : ['jpg', 'jpeg', 'png'];
        if (!in_array($ext, $css_exts, true)) return '';                   // gif/svg/data:/next-gen excluded
        $base_mime = ($ext === 'png') ? 'image/png' : (($ext === 'webp') ? 'image/webp' : 'image/jpeg');

        $q = ($quote === '"' || $quote === "'") ? $quote : '';

        // Verified CDN-edge (Bunny + Vary): emit ONE clean .webp natural URL. The edge negotiates
        // format by Accept (AVIF up / WebP across / JPEG down), so .webp is the single negotiable base
        // for a CSS background. No image-set and no explicit .avif here: a .avif URL is a fixed format
        // the edge can't downgrade for a non-avif UA — only .webp varies both up and down.
        if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) {
            $webp_zone = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $sameext_zone);
            if (is_string($webp_zone) && $webp_zone !== '' && $webp_zone !== $sameext_zone) {
                return 'background-image:url(' . $q . $webp_zone . $q . ')';
            }
            return '';                                     // ext-swap failed → caller keeps same-ext
        }

        // CONVERGED (wpc_natural_nw): non-edge zones emit the OTF image-set — typed avif + webp entries the
        // edge resizes/transcodes from ANY source (E1), floor never-404s, no on-disk dependency. This is what
        // gives CSS backgrounds avif on CF-direct (+ webp origins). type() self-selection is browser-side.
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
            $nw_entries[] = 'url(' . $q . $sameext_zone . $q . ') type("' . $base_mime . '")'; // same-ext floor (always 200)
            if (count($nw_entries) < 2) return '';
            $nw_set = implode(',', $nw_entries);
            return 'background-image:url(' . $q . $sameext_zone . $q . ');'
                 . 'background-image:-webkit-image-set(' . $nw_set . ');'
                 . 'background-image:image-set(' . $nw_set . ')';
        }

        // Non-edge (CF / vary-blind / picture-mode / off): on-disk image-set only. type()
        // self-selection is browser-side and never-404 by on-disk existence — the vary-blind-safe
        // path. No fabricated natural URLs here.
        $sib = self::wpc_css_bg_disk_siblings($origin_url);
        if (empty($sib['avif']) && empty($sib['webp'])) return '';         // no disk siblings → caller keeps same-ext

        // Ext-swap on the ZONE url (host-swap already done by the caller); swap only the extension.
        $avif_zone = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.avif$2', $sameext_zone);
        $webp_zone = preg_replace('/\.(jpe?g|png)(\?.*)?$/i', '.webp$2', $sameext_zone);

        $entries = [];
        if (!empty($sib['avif']) && is_string($avif_zone) && $avif_zone !== '' && $avif_zone !== $sameext_zone) {
            $entries[] = 'url(' . $q . $avif_zone . $q . ') type("image/avif")';
        }
        if (!empty($sib['webp']) && is_string($webp_zone) && $webp_zone !== '' && $webp_zone !== $sameext_zone) {
            $entries[] = 'url(' . $q . $webp_zone . $q . ') type("image/webp")';
        }
        // Same-ext floor entry — guarantees a 200 inside image-set even for an exotic UA.
        $entries[] = 'url(' . $q . $sameext_zone . $q . ') type("' . $base_mime . '")';
        if (count($entries) < 2) return '';                                // only the floor survived → no upgrade

        $set = implode(',', $entries);
        return 'background-image:url(' . $q . $sameext_zone . $q . ');'
             . 'background-image:-webkit-image-set(' . $set . ');'
             . 'background-image:image-set(' . $set . ')';
    }

    /**
     * CSS/inline-background image-set sweep. Runs AFTER wpc_raster_naturalize_passes (so background URLs
     * are already the clean natural ZONE-host form, e.g. cdn/wp-content/uploads/X.png) and upgrades each
     * raster background to the never-404 image-set() via wpc_css_bg_imageset_build (AVIF+WebP+same-ext
     * floor, type()-self-selected → Vary-blind-safe, OTF so no on-disk dependency on a nw/edge zone).
     *
     * One call site (inside wpc_raster_naturalize) covers EVERY caller: the CSS combiner (combine_css),
     * process_css_for_fonts, the page output buffer (inline style backgrounds), and css_content. Scoped
     * to the media-base catalog (uploads + storage + filtered). IDEMPOTENT: the negative-lookahead skips
     * the image-set's own same-ext floor (a floor url is immediately followed by ;background-image:
     * [-webkit-]image-set), and the belt skips any declaration that already contains image-set(. NULL-safe.
     */
    public static function wpc_css_bg_imageset_sweep($css)
    {
        if (!is_string($css) || $css === '' || empty(self::$zone_name)) {
            return $css;
        }
        if (stripos($css, 'background') === false || !self::wpc_css_bg_imageset_active()) {
            return $css; // no backgrounds present, or gate/KILL off
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
        $rx = '#(background(?:-image)?\s*:\s*[^;{}]*?url\(\s*)([\'"]?)(https?://' . $zone . '/' . $base_alt . '/[^"\'()\s<>]+?)\.(png|jpe?g|webp)((?:\?[^"\'()\s<>]*)?)\2(\s*\))(?!\s*;\s*background-image\s*:\s*(?:-webkit-)?image-set)#i';
        $out = preg_replace_callback($rx, static function ($m) use ($origin_host) {
            if (stripos($m[0], 'image-set(') !== false) {
                return $m[0]; // already upgraded
            }
            $sameext_zone = $m[3] . '.' . $m[4] . $m[5];
            $rel = function_exists('wp_parse_url') ? (string) wp_parse_url($sameext_zone, PHP_URL_PATH) : '';
            $origin_url = ($origin_host !== '' && $rel !== '') ? ('https://' . $origin_host . $rel) : $sameext_zone;
            $iset = self::wpc_css_bg_imageset_build($origin_url, $sameext_zone, $m[2]);
            return ($iset !== '') ? $iset : $m[0]; // upgrade, or leave the natural same-ext (already correct)
        }, $css);
        return is_string($out) ? $out : $css; // NULL-safe: a backtrack returns the original, never blanks
    }

    /**
     * Gate for the SVG positive sweep. The naturalize pass only cleans SVGs the legacy rewriter
     * already lifted to the zone; SVGs referenced from CSS background-image, inline style url(),
     * root-relative hrefs etc. were never zone-served at all. The sweep host-swaps any origin
     * uploads-SVG to the clean zone URL. Hard-gated: live CDN, Images master on, zone not suppressed,
     * zone != origin.
     */
    public static function wpc_svg_zoneify_active()
    {
        if (empty(self::$zone_name)) {
            return false;
        }
        $s = self::$settings;
        if (!is_array($s) || empty($s['live-cdn']) || (string) $s['live-cdn'] !== '1') {
            return false;
        }
        // No serve['svg'] check: the UI is one Images tile now, but legacy presets wrote svg='0',
        // which would permanently block this gate on pre-consolidation sites. The cdn_images_enabled()
        // check below is the tile's real gate and covers svg.
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            return false;
        }
        if (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled($s)) {
            return false;
        }
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$origin || strcasecmp((string) self::$zone_name, $origin) === 0) { // EQUALITY not substring: cdn.{origin} contains origin, so a substring guard false-positives every custom-CNAME zone
            return false; // zone derives from origin host → swapping would self-loop
        }
        return true;
    }

    public static function wpc_svg_zoneify($html)
    {
        if (!is_string($html) || $html === '' || !self::wpc_svg_zoneify_active()) {
            return $html;
        }
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        $o = preg_quote($origin, '#');
        // Absolute origin URLs (src/href/srcset/CSS url()).
        $html = self::wpc_preg_safe(
            '#https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?\.svg(?:\?[^"\'()\s<>]*)?)#i',
            'https://' . self::$zone_name . '$1',
            $html
        );
        // Root-relative references (quoted attributes + CSS url(...)).
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
        if (!$origin || strcasecmp((string) self::$zone_name, $origin) === 0) { // EQUALITY not substring: cdn.{origin} contains origin, so a substring guard false-positives every custom-CNAME zone
            return false; // zone derives from origin host → swapping would self-loop
        }
        return true;
    }

    /**
     * Raster-zoneify backstop. nd-skipped origin rasters (metadata-broken attachments the
     * negotiated/img lane never touched, plus theme/CSS-background uploads outside the srcset
     * rewriter) leak to the origin host — SVGs got a backstop in wpc_svg_zoneify(), rasters didn't.
     * Host-swaps absolute + root-relative origin /wp-content/uploads/ png/jpg/gif refs onto the zone,
     * SAME extension — same bytes 200 from the CDN, no format change. No blanket webp swap: <link
     * rel=icon>/og:image would break favicon parsers + webp-blind scrapers, and a GIF would flatten to
     * a static webp. <picture> blocks are masked first so the builder-owned typed <source>s and their
     * u: targets are never touched.
     */
    public static function wpc_raster_zoneify($html)
    {
        if (!is_string($html) || $html === '' || !self::wpc_raster_zoneify_active()) {
            return $html;
        }
        $wpc_pic_blocks = [];
        if (stripos($html, '<picture') !== false) {
            // String-based <picture> masking (NOT regex). The old '#<picture\b.*?</picture>#is' returned
            // NULL on pcre.backtrack_limit on large Elementor pages, and the bail-to-original below then
            // silently skipped ALL naturalization — so gallery / background / storage transforms stayed
            // /q: on exactly the heavy pages that need it (acrystalglass). A linear strpos scan can't
            // backtrack, so the naturalize ALWAYS runs. Mirrors the old \b (skips <pictureX) and masks
            // each first <picture>..</picture> block.
            $masked = '';
            $offset = 0;
            $hlen   = strlen($html);
            while (($start = stripos($html, '<picture', $offset)) !== false) {
                $after = ($start + 8 < $hlen) ? $html[$start + 8] : '';
                if ($after !== '' && (ctype_alnum($after) || $after === '_')) {
                    // not a <picture> tag (e.g. <picturex) — emit through it and continue
                    $masked .= substr($html, $offset, ($start + 8) - $offset);
                    $offset  = $start + 8;
                    continue;
                }
                $end = stripos($html, '</picture>', $start);
                if ($end === false) {
                    break; // unclosed — leave the remainder unmasked (old regex wouldn't match it either)
                }
                $end += 10; // strlen('</picture>')
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
        // GIF host-swaps to the zone ONLY on a CF-direct zone: on Bunny a GIF gets no next-gen
        // conversion, so naturalizing it is pure WPC egress for zero benefit — leave it on origin.
        // (Runs AFTER the per-image gate, so it must exclude gif too or it re-host-swaps a gif the
        // gate left on origin.)
        $nat_gif = (class_exists('wps_rewriteLogic') && wps_rewriteLogic::cf_is_delivery()) ? '|gif' : '';
        // Emit .webp for transcodable rasters on a proven negotiating edge. This outermost pass
        // host-swaps any leftover origin uploads URL (gallery <a href>, data-large_image, the nd
        // data-wpc-fb fallback) and was same-ext, so on a CF-fronted Bunny zone those double-fetched
        // alongside the nd .webp. Derive CF-direct from emit-host-vs-cname (NOT zone_is_cf()'s CF-RAY
        // false-positive); only swap when nd is active. gif stays gif; un-witnessed CF / nd-inactive →
        // same-ext. The edge down-negotiates .webp→jpeg for non-webp Accept, so scraper/legacy paths
        // stay valid by Content-Type.
        $zn = self::$zone_name;
        $cf_cname_z  = (defined('WPS_IC_CF_CNAME') && function_exists('get_option')) ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        $z_cf_direct = ($cf_cname_z !== '' && stripos((string) $zn, $cf_cname_z) !== false);
        $z_edge_webp = (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()
            && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_single_url_format'));
        $z_swap = static function ($path) use ($z_edge_webp, $z_cf_direct) {
            if (!preg_match('#^(.*\.)(png|jpe?g|gif)(\?.*)?$#i', $path, $mm)) return $path; // no ext → verbatim
            $ext = strtolower($mm[2]);
            $fmt = $z_edge_webp ? wps_rewriteLogic::wpc_single_url_format($ext, $z_cf_direct, true) : $ext;
            $out = (is_string($fmt) && $fmt !== '') ? $fmt : $ext; // gif→gif, un-witnessed/KILL → same-ext
            return $mm[1] . $out . (isset($mm[3]) ? $mm[3] : '');
        };
        // Absolute origin uploads rasters.
        $z_abs = preg_replace_callback(
            '#https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?\.(?:png|jpe?g' . $nat_gif . ')(?:\?[^"\'()\s<>]*)?)#i',
            static function ($m) use ($zn, $z_swap) { return 'https://' . $zn . $z_swap($m[1]); },
            $html
        );
        if (is_string($z_abs)) $html = $z_abs;
        // Root-relative refs.
        $z_rel = preg_replace_callback(
            '#(["\'(])(/wp-content/uploads/[^"\'()\s<>]+?\.(?:png|jpe?g' . $nat_gif . ')(?:\?[^"\'()\s<>]*)?)#i',
            static function ($m) use ($zn, $z_swap) { return $m[1] . 'https://' . $zn . $z_swap($m[2]); },
            $html
        );
        if (is_string($z_rel)) $html = $z_rel;
        if (!empty($wpc_pic_blocks)) {
            $html = strtr($html, $wpc_pic_blocks);
        }
        return $html;
    }

    /**
     * webp-immediate gate. Bunny chains: always safe (edge negotiates AND downgrades natively). CF
     * chains: only when the zone's pod is >= 2.89.18.2 — below that a legacy browser's jpg-downgrade
     * could cache-pin itself under a .webp URL at CF's full TTL (the vary-blind poisoning lane). Pod
     * version comes from the asset probe's x-cdn-version capture.
     */
    public static function wpc_webp_immediate_ok()
    {
        // KILL is the single emergency off-ramp. This witness feeds the bare-<img>/CSS-bg naturalize
        // lane, which isn't gated by natural_assets_on() (the lane that honors KILL), so without this a
        // KILL'd CF site would still naturalize wp:1→.webp there. Symmetric with avif_natural_source_ok.
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) {
            return false;
        }
        // Config-authoritative CF detection. Request-header detection is blind to a CF-direct CNAME
        // zone over a non-orange origin (no CF header on any origin render); without zone_is_cf() the
        // optimistic `return true` below would promote a vary-blind .webp on the CF edge (CSS-bg /
        // slideshow have no fallback). zone_is_cf() routes such a zone to the witness path instead.
        $cf = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']) || get_option('wpc_v2_cf_assets_seen', 0);
        if (!$cf && class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'zone_is_cf')) {
            $cf = wps_rewriteLogic::zone_is_cf();
        }
        if (!$cf) {
            return true;
        }
        // Force-natural operator override (default OFF): promote natural .webp on a CF zone the
        // operator confirmed vary-correct + OTF-live, before the orch echoes the witness. Short-circuits
        // the vary-blind-poisoning gate below, so only ever true on a confirmed zone.
        if (function_exists('wpc_force_natural') && wpc_force_natural()) {
            return true;
        }
        // Honor the orch's per-zone native_accept_vary witness, symmetric with avif:
        //   true  → promote (provisioned, vary-correct — webp lands in lockstep with avif);
        //   false → orch asserts this CF zone is NOT vary-correct → stay on the wp:1 transform. A
        //           capable pod binary doesn't guarantee EnableWebPVary, and a natural .webp would let a
        //           legacy jpg-downgrade pin under the .webp URL on the vary-blind edge — the orch's
        //           zone-config-level witness is the stronger authority, so it wins;
        //   null  → orch hasn't reported → fall through to the pod-version witness below.
        if (class_exists('WPC_Delivery_Resolver')) {
            $nav = WPC_Delivery_Resolver::orch_nav_signal();
            if ($nav === true)  return true;
            if ($nav === false) return false;
        }
        // Un-provisioned CF fallback is optimistic, symmetric with the AVIF witness. The pod-version
        // probe below stays a capability signal but must not lag the AVIF leg, or a fresh CF zone ships
        // natural-avif + transform-webp (half-natural <picture>). WebP is emitted only as a typed
        // <source>, so a no-webp UA never fetches the .webp URL → no vary-blind pin. Still overridable:
        // nav=false above hard-disables, KILL/force handled above.
        $pv = (string) get_transient('wpc_v2_cf_pod_version');
        // Promote-on-proof self-capture. The pod-version transient is otherwise only written by the
        // healthcheck asset-probe, so on a normal CF frontend render it's frequently unset, wrongly
        // dropping WebP to wp:1 even though the pod is capable. If unset, capture it off-render
        // (admin/cron); frontend renders only read the cached verdict.
        if ($pv === '' && (is_admin() || (defined('DOING_CRON') && DOING_CRON))) {
            $zone = (string) get_option('ic_cdn_zone_name', '');
            if ($zone !== '') {
                $r  = wp_remote_get('https://' . $zone . '/wp-includes/css/dist/block-library/style.min.css', ['timeout' => 3, 'sslverify' => false, 'redirection' => 2, 'limit_response_size' => 2048]);
                $pv = is_wp_error($r) ? '' : (string) wp_remote_retrieve_header($r, 'x-cdn-version');
                // good → cache 12h; empty/unreachable → 2h '0' sentinel so we don't re-probe every admin load
                set_transient('wpc_v2_cf_pod_version', $pv !== '' ? $pv : '0', $pv !== '' ? 12 * HOUR_IN_SECONDS : 2 * HOUR_IN_SECONDS);
            }
        }
        // Optimistic return. The probe verdict is kept for diagnostics but no longer gates: a capable,
        // OTF-live edge is the fleet baseline and WebP-in-<picture> is type-safe, so we keep WebP
        // natural in lockstep with the optimistic AVIF leg. The ONE hard-no is a definite-old probed
        // version ($pv present, not the '0' unreachable sentinel, < 2.89.18.2) — the '0' sentinel and an
        // empty probe are optimistic. nav=false above is the hard-off; KILL at the top reverts all.
        if ($pv !== '' && $pv !== '0' && version_compare(ltrim($pv, 'v'), '2.89.18.2', '<')) {
            return false; // probe positively says this pod is too old for natural .webp
        }
        return true;
    }

    /**
     * NULL-safe preg_replace. A preg_replace that hits the PCRE backtrack/JIT-stack limit returns
     * NULL; assigning that straight to the output-buffer $html serves a BLANK PAGE. Every buffer-pass
     * rewrite routes through this: on NULL (or non-string) it returns the original subject unchanged,
     * so the rewrite is skipped, never the page lost.
     */
    private static function wpc_preg_safe($pattern, $replacement, $subject)
    {
        $out = preg_replace($pattern, $replacement, $subject);
        return is_string($out) ? $out : $subject;
    }

    /**
     * Asset (CSS/JS) transform -> natural. Collapses the m:0 no-minify pass-through transform
     * (cdn/m:0/a:ORIGIN_URL) to a clean natural zone URL (cdn host + the origin path). m:0 = no
     * minify, so the natural URL is byte-identical — the edge fetches the same origin asset either
     * way (confirmed: CSS "passes through fully"). ONLY m:0 (m:1+ are minified; collapsing would lose
     * the minification). NULL-safe (a backtrack returns the original, never blanks the page).
     * Kill switch: add_filter('wpc_asset_naturalize_enabled', '__return_false').
     */
    public static function wpc_asset_naturalize($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name) || stripos($html, '/m:0') === false) {
            return $html;
        }
        if (!apply_filters('wpc_asset_naturalize_enabled', true)) {
            return $html;
        }
        $zone = preg_quote(self::$zone_name, '#');
        $bs = '\\\\?/';
        $zone_name = self::$zone_name;
        // $bs-tolerant throughout (not just the a: target) so a FULLY JSON-escaped m:0 transform inside
        // a JS/loader config (https:\/\/zone\/m:0\/a:...) also collapses; the closure re-escapes on output.
        $rx = '#https?:' . $bs . $bs . '(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)' . $bs . 'm:0' . $bs . 'a:(https?:' . $bs . $bs . '[^"\'()\s<>]+?\.(?:css|js)(?:\?[^"\'()\s<>]*)?)#i';
        $out = preg_replace_callback($rx, static function ($m) use ($zone_name) {
            $u_esc = (strpos($m[1], '\\/') !== false);
            $u_plain = $u_esc ? str_replace('\\/', '/', $m[1]) : $m[1];
            $p = function_exists('wp_parse_url') ? wp_parse_url($u_plain) : parse_url($u_plain);
            if (empty($p['path'])) {
                return $m[0];
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
            // String-based <picture> masking (NOT regex). The old '#<picture\b.*?</picture>#is' returned
            // NULL on pcre.backtrack_limit on large Elementor pages, and the bail-to-original below then
            // silently skipped ALL naturalization — so gallery / background / storage transforms stayed
            // /q: on exactly the heavy pages that need it (acrystalglass). A linear strpos scan can't
            // backtrack, so the naturalize ALWAYS runs. Mirrors the old \b (skips <pictureX) and masks
            // each first <picture>..</picture> block.
            $masked = '';
            $offset = 0;
            $hlen   = strlen($html);
            while (($start = stripos($html, '<picture', $offset)) !== false) {
                $after = ($start + 8 < $hlen) ? $html[$start + 8] : '';
                if ($after !== '' && (ctype_alnum($after) || $after === '_')) {
                    // not a <picture> tag (e.g. <picturex) — emit through it and continue
                    $masked .= substr($html, $offset, ($start + 8) - $offset);
                    $offset  = $start + 8;
                    continue;
                }
                $end = stripos($html, '</picture>', $start);
                if ($end === false) {
                    break; // unclosed — leave the remainder unmasked (old regex wouldn't match it either)
                }
                $end += 10; // strlen('</picture>')
                $k = "\x01WPCPIC" . count($wpc_pic_blocks) . "\x01";
                $wpc_pic_blocks[$k] = substr($html, $start, $end - $start);
                $masked .= substr($html, $offset, $start - $offset) . $k;
                $offset  = $end;
            }
            $masked .= substr($html, $offset);
            $html = $masked;
        }
        $html = self::wpc_raster_naturalize_passes($html);
        // Upgrade the now-natural raster backgrounds (CSS files + inline styles) to never-404 image-set
        // (AVIF+WebP, type()-selected, Vary-free). Runs on the picture-masked html, so <picture> source
        // URLs are untouched. Self-gated + idempotent + NULL-safe.
        $html = self::wpc_css_bg_imageset_sweep($html);
        if (!empty($wpc_pic_blocks)) {
            $html = strtr($html, $wpc_pic_blocks);
        }
        return $html;
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
        $zone = preg_quote((string) self::$zone_name, '#');
        // Pass 0 — double-zone collapse, any mode. A transform wrapping an already-zoned URL
        // (/q:i/…/u:https://{zone}/…) 302s as text/plain and wastes a request before the natural 200:
        // the edge can't treat itself as the origin at any width/format. The fonts lane double-wraps
        // too (a rewriter without a zone-skip guard re-wrapping an already-zoned URL — works via a
        // double hop but fragments the cache). Collapse any zone-prefixed wrap (q:/r:/wp:/w:/m:/
        // font:true chains ending in a:/u:) whose target is already the zone, down to the inner URL.
        $html = self::wpc_preg_safe(
            '#https?://(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)/(?:(?:q|r|wp|w|m):[a-z0-9]+/|font:true/){1,40}(?:a|u):(https?://' . $zone . '/[^"\'()\s<>]+)#i',
            '$1',
            $html
        );
        $origin = wp_parse_url(home_url(), PHP_URL_HOST);
        if (!$origin || strcasecmp((string) self::$zone_name, $origin) === 0) { // EQUALITY not substring: cdn.{origin} contains origin, so a substring guard false-positives every custom-CNAME zone
            return $html; // zone derives from origin host → swapping would self-loop
        }
        // Any width (not just w:1): the srcset ladder owns responsive widths, so uploads rasters go
        // to natural zone URLs across the board — per-request edge resizing isn't part of the contract.
        // Uploads-scoped (theme/plugin-path transforms keep their working form). The "/" or "\/" lets
        // JSON-escaped u: targets naturalize too.
        $bs = '\\\\?/';
        // Media-base scope for the transform->natural collapse. Was hardcoded to /wp-content/uploads;
        // now derived from wpc_v2_upload_base_paths() so offloaded / page-builder media (e.g. /storage)
        // collapse to natural too — riding the SAME $otf_live witness gate /uploads already passes, so
        // no new risk (uploads already proves natural delivery works on the zone). Each base's path
        // segments are joined by $bs so JSON-escaped u: targets still match.
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
            // Never collapse a wp:2 (avif-intent) transform: its u: target is a jpg/png, so collapsing
            // changes the served format class — the jpg-under-an-avif-slot corruption. No natural
            // equivalent to synthesize (the builder emits the natural .avif when the file exists);
            // leave the transform to do its interim+trigger work.
            if ($m[1] === '2') {
                return $m[0];
            }
            // Escape-tolerant: a u: target inside JSON arrives slash-escaped — unescape to find the
            // path, re-escape on output.
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
            $rel = substr($u_plain, $pos); // exact origin path+query bytes — no re-encoding
            if ($allow_webp && $m[1] === '1') {
                $rel = preg_replace('/\.(?:png|jpe?g)(\?|$)/i', '.webp$1', $rel);
            }
            $natural = 'https://' . self::$zone_name . $rel;
            if ($u_esc) {
                $natural = str_replace('/', '\\/', $natural); // keep JSON valid
            }
            return $natural;
        };
        // Pass 1 — <link>/<meta> tags: same-ext natural, any mode. These tags are never
        // JS-width-managed, so the nd/jpeg gate below doesn't apply, and w:1 does no resize work anyway.
        $html = preg_replace_callback('#<(?:link|meta)\b[^>]*>#i', static function ($tag) use ($rx, $naturalize) {
            return preg_replace_callback($rx, static function ($m) use ($naturalize) {
                return $naturalize($m, false);
            }, $tag[0]);
        }, $html);
        // Pass 1.5 — no-work transforms (w:1), any mode, same-ext (picture-mode sites, where the
        // nd-gated pass below never ran): q:u/wp:0/w:1 transforms of origin uploads, plus the
        // nested-monster class — a transform wrapping the site's OLD zone host which wraps origin
        // (old-era content re-wrapped by a rewriter with no foreign-zone guard). The capture runs
        // through the nested junk to the innermost uploads target, so one pass digests the whole chain.
        $rx_w1 = '#https?://(?:' . $zone . '|[a-z0-9-]+\.zapwp\.com)/(?:q:[a-z0-9]+/)?r:\d+/wp:(\d)/w:1/u:(https?:' . $bs . $bs . '[^"\'()\s<>]+?' . $bs . $base_alt . $bs . '[^"\'()\s<>]+?\.(?:png|jpe?g|webp|gif)(?:\?[^"\'()\s<>]*)?)#i';
        // Route the webp-vs-same-ext decision through the resolver per-match: an un-witnessed CF zone
        // collapses to same-ext (no vary-blind .webp on a no-fallback URL), Bunny/witnessed-CF get
        // negotiated .webp.
        $html = preg_replace_callback($rx_w1, static function ($m) use ($naturalize) {
            $u   = ($m[1] === '1') ? (strpos($m[2], '\\/') !== false ? str_replace('\\/', '/', $m[2]) : $m[2]) : '';
            $ext = $u !== '' ? strtolower(pathinfo(preg_replace('/\?.*$/', '', $u), PATHINFO_EXTENSION)) : '';
            $allow = ($ext !== '' && class_exists('wps_rewriteLogic')
                && wps_rewriteLogic::wpc_single_url_format($ext, null, null) === 'webp');
            return $naturalize($m, $allow);
        }, $html);
        $nd_webp = WPC_Negotiated_Delivery::is_active();
        $nd_jpeg = !$nd_webp && WPC_Negotiated_Delivery::is_active_jpeg();
        // OTF-live Pass-2 ungate. When the zone is OTF-live (the CDN resizes a natural -WxH URL by its
        // filename suffix and negotiates format off Accept), the q:i/wp:N/w:N transform does no work a
        // natural URL can't reproduce — collapsing to natural is byte-equivalent at the slot width and
        // better-cached. Then Pass 2 must run regardless of nd/jpeg mode — picture/adaptive-mode sites
        // (both $nd_webp/$nd_jpeg false) were still shipping w:N transforms in CSS/srcset/data-attrs.
        // Witness is the same trichotomy the picture builder gates on — never a blind promotion:
        //   • wpc_force_natural()        — operator-confirmed vary-correct + OTF-live zone;
        //   • avif_natural_source_ok()   — provisioned (orch_nav_signal()===true) OR Bunny/CF-flag;
        //   • wpc_webp_immediate_ok()    — symmetric webp witness (optimistic on un-witnessed CF; the
        //                                  pod-version is only a negative hard-no, nav=false is the
        //                                  authoritative hard-off, KILL reverts everything).
        $otf_live = (function_exists('wpc_force_natural') && wpc_force_natural())
            || (class_exists('wps_rewriteLogic') && wps_rewriteLogic::avif_natural_source_ok())
            || self::wpc_webp_immediate_ok();
        if (!$nd_webp && !$nd_jpeg && !$otf_live) {
            return $html; // not OTF-live and not nd/jpeg → keep transforms (current conservative form)
        }
        // Pass 2 — everything else (CSS url(), style attrs, srcset, data attrs): webp ext-swap in nd
        // mode or when OTF-live, origin ext in jpeg mode. The wp:2-avif-skip, uploads-scope, self-loop
        // equality and <picture> masking all still hold — this only widens WHEN Pass 2 runs. nd-webp
        // mode forces its own typed-webp URL; otherwise the per-match resolver collapses an un-witnessed
        // CF zone to same-ext (no vary-blind .webp on a no-fallback URL) and gives Bunny/witnessed-CF
        // negotiated .webp.
        $html = preg_replace_callback($rx, static function ($m) use ($naturalize, $nd_webp) {
            if ($nd_webp) {
                return $naturalize($m, true); // nd-mode owns its typed-webp delivery URL
            }
            $u   = (strpos($m[2], '\\/') !== false) ? str_replace('\\/', '/', $m[2]) : $m[2];
            $ext = strtolower(pathinfo(preg_replace('/\?.*$/', '', $u), PATHINFO_EXTENSION));
            $allow = ($ext !== '' && class_exists('wps_rewriteLogic')
                && wps_rewriteLogic::wpc_single_url_format($ext, null, null) === 'webp');
            return $naturalize($m, $allow);
        }, $html);
        // Pass 3 — splash un-stick. Legacy-lane images ship the base64 SVG placeholder in src with the
        // real URL in data-src, but the un-splash JS is intentionally dequeued on nd/jpeg paths →
        // placeholder forever, invisible images. Native loading="lazy" already lazy-loads these, so
        // restore the real URL into src and drop data-src. Only fires on the exact splash signature.
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
        $html = self::wpc_collapse_double_ext(self::wpc_asset_naturalize(self::wpc_raster_zoneify(self::wpc_svg_zoneify(self::wpc_raster_naturalize(self::wpc_svg_naturalize($this->buffer_local_callback($html)))))));
        $html = self::wpc_lazy_srcset_buffer_pass($html);
        $html = self::wpc_lcp_hint_pass($html);
        return self::wpc_freshness_marker($html);
    }

    public function cdnRewriter_wrapped($html)
    {
        $html = self::wpc_collapse_double_ext(self::wpc_asset_naturalize(self::wpc_raster_zoneify(self::wpc_svg_zoneify(self::wpc_raster_naturalize(self::wpc_svg_naturalize($this->cdnRewriter($html)))))));
        $html = self::wpc_lazy_srcset_buffer_pass($html);
        $html = self::wpc_lcp_hint_pass($html);
        return self::wpc_freshness_marker($html);
    }

    /**
     * (v7.03.59) Buffer-level SAFETY NET for the lazy over-fetch fix. The per-image promotion at
     * rewriteLogic.php:5024 only runs inside the $build_image_tag rebuild; a theme-lazy thumbnail that takes a
     * different emit path (or any <img> the rebuild missed) keeps its INERT data-srcset and over-fetches the
     * full `src`. This final pass catches EVERY native-lazy <img> in the rendered HTML — regardless of which
     * builder produced it — and promotes data-srcset -> active srcset + sizes="auto" so the browser self-sizes.
     * Reuses the EXACT per-image guards from wps_rewriteLogic (loading="lazy" only, no data-src / no active
     * srcset, not a carousel, aspect-safe), so it's idempotent (an already-promoted img is skipped) and never
     * touches eager/LCP/slider images. Gated by the SAME "Right-size Lazy Images" toggle, read here via
     * get_option so it's correct independent of rewriteLogic's static cache. Default OFF => exact no-op.
     */
    public static function wpc_lazy_srcset_buffer_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'data-srcset') === false) return $html;
        if (!class_exists('wps_rewriteLogic') || !method_exists('wps_rewriteLogic', 'activate_lazy_srcset_auto')) return $html;
        $set = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        if (!is_array($set) || empty($set['lazy-auto-sizes'])) return $html; // toggle OFF => exact no-op
        return preg_replace_callback('/<img\b[^>]*?>/is', function ($m) {
            $t = wps_rewriteLogic::activate_lazy_srcset_auto($m[0]);
            if (method_exists('wps_rewriteLogic', 'auto_sizes_for_lazy_img')) {
                $t = wps_rewriteLogic::auto_sizes_for_lazy_img($t);
            }
            return $t;
        }, $html);
    }

    /**
     * (v7.03.58) Stamp the rendered HTML with the plugin version + render UNIX time (tiny comment before
     * </body>) so freshness is verifiable at a glance via View-Source/curl. A page-cache (LiteSpeed/GridPane)
     * serves the BAKED-IN time, so an old r: value = you're looking at STALE cache → purge it. Ends the
     * "is this fresh?" guessing that masked the over-fetch/zone fixes behind 7-day server caches. The
     * ?wpc_fresh request also appends FRESH. Filter 'wpc_freshness_marker' => false to suppress (white-label).
     */
    public static function wpc_freshness_marker($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '</body>') === false) return $html;
        if (function_exists('apply_filters') && !apply_filters('wpc_freshness_marker', true)) return $html;
        $ver   = defined('WPC_PLUGIN_VERSION') ? WPC_PLUGIN_VERSION : '?';
        $now   = time();
        $fresh = !empty($_GET['wpc_fresh']) ? ' FRESH' : '';
        // (v7.03.59) Stamp the "Right-size Lazy Images" toggle (la:1/0) so freshness AND the over-fetch gate
        // are both verifiable from View-Source — la:0 on a page whose thumbnails over-fetch = the toggle just
        // isn't saved on, full stop (no more guessing whether it's code or config).
        $set   = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        $la    = (is_array($set) && !empty($set['lazy-auto-sizes'])) ? '1' : '0';
        $marker = "\n<!-- wpc " . $ver . ' r:' . $now . ' (' . gmdate('Y-m-d H:i:s', $now) . ' UTC) la:' . $la . $fresh . " -->\n";
        return preg_replace('#</body>#i', $marker . '</body>', $html, 1);
    }

    /**
     * (v7.03.75) LCP fetchpriority consumer. WPC's per-tag rewriter can't identify the LCP image — its
     * candidate logic only looks at the first-N images by DOM order, and it has no ancestor context to tell
     * the hero <img> apart from the grid (same class). The crit side renders per-viewport (Penthouse) and
     * DOES know the LCP element, so it provides a hint — per-viewport {stem,width} via option/filter
     * 'wpc_lcp_hint' (the filename STEM, because that's the only thing this per-tag pass can match through the
     * CDN rewrite). When an <img>'s src carries that stem we give it the Lighthouse-correct LCP shape:
     * fetchpriority="high", eager (NOT lazy — Lighthouse penalises a lazy LCP), and a FIXED sizes=<width>px
     * (auto is ignored by the spec on a non-lazy img, so eager+auto would over-fetch; the render-measured
     * width right-sizes it). INERT until the hint is populated => zero change on every site that hasn't wired
     * the crit side yet. Contract: wpc-lcp-fetchpriority-contract.md.
     */
    public static function wpc_lcp_hint_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '<img') === false) return $html;
        $hint = function_exists('apply_filters')
            ? apply_filters('wpc_lcp_hint', (function_exists('get_option') ? get_option('wpc_lcp_hint') : null))
            : (function_exists('get_option') ? get_option('wpc_lcp_hint') : null);
        if (empty($hint) || !is_array($hint)) return $html;
        // Accept a flat {stem,width} OR a per-viewport {desktop:{...},mobile:{...}}. Viewport via WP's UA
        // check (the same basis WPC uses to pick the per-viewport crit); fall back to whichever entry exists.
        if (isset($hint['stem'])) {
            $entry = $hint;
        } else {
            $vp = (function_exists('wp_is_mobile') && wp_is_mobile()) ? 'mobile' : 'desktop';
            $entry = (isset($hint[$vp]) && is_array($hint[$vp])) ? $hint[$vp]
                   : ((isset($hint['desktop']) && is_array($hint['desktop'])) ? $hint['desktop']
                   : ((isset($hint['mobile']) && is_array($hint['mobile'])) ? $hint['mobile'] : null));
        }
        if (!is_array($entry) || empty($entry['stem'])) return $html;
        $stem  = (string) $entry['stem'];
        $width = isset($entry['width']) ? (int) $entry['width'] : 0;
        if (strlen($stem) < 4) return $html; // too-short stem would match unrelated images — refuse it
        $applied = false;
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($stem, $width, &$applied) {
            $tag = $m[0];
            if ($applied || stripos($tag, $stem) === false) return $tag; // LCP is one element: first match only
            $applied = true;
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

    /**
     * (v7.03.56) Collapse a doubled SAME-extension that a naturalize/zoneify pass can produce when the
     * SOURCE is ALREADY next-gen — e.g. a .webp ORIGINAL used as a CSS background or an <img> fallback
     * (…-1.webp.webp → 404), common on .webp-upload portfolios. SAME-ext only (backreference) so it NEVER
     * touches a valid cross-ext like .webp.avif (natural AVIF from a webp source). Fast-path skips the regex
     * unless a double is actually present; idempotent + safe on already-correct URLs.
     */
    public static function wpc_collapse_double_ext($html)
    {
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
        // Negotiated delivery is a CDN-ON feature — its native .webp URLs are served by the
        // CDN container, so it dispatches ONLY from cdnRewriter() (the CDN-on buffer), never
        // here in the local/CDN-off path. See cdnRewriter() for the F.0 compose.

        // Heal mixed content (same-host http→https on https requests), mirroring cdnRewriter() — covers
        // the CDN-off local-delivery buffer too.
        $html = wpc_heal_mixed_content($html);

        $isUserLoggedIn = is_user_logged_in();

        if (!self::dontRunif()) {
            return $html;
        }

        if ((!empty($_GET['criticalCombine']) && $_GET['criticalCombine'] == 'true') || !empty(wpcGetHeader('criticalCombine'))) {
            $this->criticalCombine = true;
        }
        //Do something with the buffer (HTML)
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

        // Edge-negotiate Mode-B: when the resolver has proven CDN_EDGE with redirect_target='origin'
        // (the "Edge negotiate" radio: zone 302-negotiates, origin serves bytes), negotiated owns <img>
        // in the CDN-off buffer too. is_active() is the full gate. Mirrors the cdnRewriter dispatch;
        // cdnEnabled stays 0 so the CSS/JS/font passes run on origin — only images ride the zone.
        $wpcnd_local_stash = [];
        if (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active()) {
            $html = WPC_Negotiated_Delivery::rewrite_buffer($html);
            if (class_exists('wps_rewriteLogic')) {
                wps_rewriteLogic::$pictureWebpEnabled = false; // path-A/local picture builder stands down
            }
            // Stash the final negotiated tags so the LOCAL passes (local_image_tags, lazy,
            // set_image_sizes, css-bg) can't re-touch them — mirrors the cdnRewriter stash.
            // Keys deliberately NOT HTML comments (the comment-clear pass would strip them).
            $html = preg_replace_callback('/<img\b[^>]*\bdata-wpc-nd\b[^>]*>/i', function ($m) use (&$wpcnd_local_stash) {
                $k = '___WPCND_IMG_' . count($wpcnd_local_stash) . '___';
                $wpcnd_local_stash[$k] = $m[0];
                return $k;
            }, $html);
        }


        // Layzload Iframe - sets load="lazy" to iframe tag
        // TODO: Fix so that it checks does iframe already have load="lazy|auto"
        if (!empty(self::$settings['iframe-lazy']) && self::$settings['iframe-lazy'] == '1' && !$isUserLoggedIn) {
            $html = preg_replace_callback('/<iframe[^>]*>(.*?)<\/iframe>/si', [$this, 'replace_iframe_tags'], $html);
            $html = preg_replace_callback('/<source([^>]*)\ssrc=["\']([^"\']+)["\']/i', [$this, 'replace_source_tags'], $html);
        }

        // Add preload="none" to video tags — prevents browser from downloading video until play
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

        if (self::$cdnEnabled == 0) {
            $htmlBefore = $html;
            $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', [$this, 'local_script_encode'], $html);

            if (empty($html)) {
                $html = $htmlBefore;
            }

            // Protect existing <picture> blocks from double-wrapping
            $wpcLocalPictureBlocks = [];
            if (self::$rewriteLogic::$pictureWebpEnabled) {
                $html = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$wpcLocalPictureBlocks) {
                    $i = count($wpcLocalPictureBlocks);
                    $wpcLocalPictureBlocks[$i] = $m[0];
                    return '<!--WPC_LOCAL_PICTURE_' . $i . '-->';
                }, $html);
            }

            $html = preg_replace_callback('/(?<![\"|\'])<img[^>]*>/i', [$this, 'local_image_tags'], $html);

            // Restore protected <picture> blocks
            foreach ($wpcLocalPictureBlocks as $i => $block) {
                $html = str_replace('<!--WPC_LOCAL_PICTURE_' . $i . '-->', $block, $html);
            }

            if (self::$fonts == 1) {
                $html = self::$rewriteLogic->fonts($html);
            }

            $html = preg_replace_callback('/\[script\-wpc\](.*?)\[\/script\-wpc\]/i', [$this, 'local_script_decode'], $html);

            $html = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>?/is', [self::$rewriteLogic, 'replaceBackgroundImagesInCSSLocal'], $html);

            //Combine JS
            if ($this->doCacheCombine() && (isset(self::$settings['js_combine']) && self::$settings['js_combine'] == '1')) {
                $combine_js = new wps_ic_combine_js();
                $html = $combine_js->maybe_do_combine($html);
            }
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'setImageSize') {
            return $html;
        }

        $html = preg_replace_callback('/<img[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/si', [$this, 'set_image_sizes'], $html);
        $html = preg_replace_callback('/<picture>.*?<\/picture>/is', [$this, 'set_image_sizes'], $html);

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'combine_css') {
            return $html;
        }

        if (!empty($_GET['debug_preload_inject'])) {
            $dbg = 'Before:';
            $dbg .= $html;
        }


        $html = preg_replace_callback('/<head\b[^>]*>/si', [$this, 'injectPreloadImages'], $html, 1);

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
            // TODO: Maybe add something?
            $html = $combine_css->lazyFontawesome($html);
        }

        // Critical CSS Remove from Header
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
//            if (isset(self::$settings['inline-css']) && self::$settings['inline-css'] == '1') {
//                // TODO: Maybe add something?
//                if ($criticalActive && !empty($criticalCSSExists)) {
//                    //critical exists, dont inline
//                } else {
//                    $html = $combine_css->doInline($html);
//                }
//            } else {

            //Combine CSS
            if (($this->doCacheCombine() && (isset(self::$settings['css_combine']) && self::$settings['css_combine'] == '1')) || $this->criticalCombine) {
                if (empty($_GET['stopCombineCSS'])) {
                    $html = $combine_css->maybe_do_combine($html);
                }
            }

            #}
        }

        if ((empty($_GET['disableCritical']) && empty($_GET['generateCriticalAPI'])) && empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {
            if (!is_user_logged_in() && !is_admin_bar_showing()) {
                if (!empty($_GET['debugCriticalRunning'])) {
                    $html .= print_r([self::$settings['critical']['css'], $criticalCSSExists, $criticalRunning], true);
                }


                if (!empty($_GET['debugCritical_replace'])) {
                    #global $post;
                    $criticalCSS = new wps_criticalCss();
                    $criticalCSSExists = $criticalCSS->criticalExists();
                    $criticalCSSContent = file_get_contents($criticalCSSExists['file']);

                    // Adjusted function to create preload links only if the "/* Preload Fonts */" comment is found
                    $createPreloadLinks = function ($cssContent) {
                        $preloadLinks = '';
                        $loadedFonts = []; // Array to track already added URLs
                        $commentPos = strpos($cssContent, '/* Preload Fonts */');

                        // Proceed only if the comment is found
                        if ($commentPos !== false) {
                            $relevantContent = substr($cssContent, 0, $commentPos);
                            $fontPattern = '/url\((\'|")?(.+?\.(woff2?|ttf|otf|eot))\1?\)/i';
                            if (preg_match_all($fontPattern, $relevantContent, $matches, PREG_SET_ORDER)) {
                                foreach ($matches as $match) {
                                    $fontUrl = $match[2];
                                    if (strpos($fontUrl, 'icon') !== false || strpos($fontUrl, 'fa-') !== false || strpos($fontUrl, 'la-') !== false) {
                                        continue;
                                    }
                                    // Check if the font URL is already in the array
                                    if ((!empty(self::$settings['preload-crit-fonts'])) && self::$settings['preload-crit-fonts'] == '1') {
                                        if (!in_array($fontUrl, $loadedFonts)) {
                                            $preloadLinks .= "<link rel=\"preload\" href=\"$fontUrl\" as=\"font\" type=\"font/woff2\" crossorigin=\"anonymous\">\n";
                                            $loadedFonts[] = $fontUrl; // Add the URL to the tracking array
                                        }
                                    }
                                }
                            }
                        }
                        return $preloadLinks;
                    };

                    // Function to get the CSS content after the "/* Preload Fonts */" comment
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

                        #global $post;
                        $criticalCSS = new wps_criticalCss();
                        $criticalCSSExists = $criticalCSS->criticalExists();

                        if (!empty($criticalCSSExists)) {
                            $html = self::$rewriteLogic->addCritical($html);
                            if (strpos($html, 'wpc-critical-css') !== false) {
                                $html = self::$rewriteLogic->lazyCSS($html);
                            }
                        } else {
                            //this way should be ok for multisite
                        }
                    }
                }
            }
        }

        if (!$isUserLoggedIn) {
            $html = self::$rewriteLogic->decodeIframe($html);
        }

        // Theme Integrations
        $html = self::$themeIntegrations->getIntegration($html);

        //Delay JS
        if (empty($_GET['disableDelay']) && empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {
            $js_delay = new wps_ic_js_delay();


            #$delayActive = !(isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '0') && ((isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '1'));
            $delayActive = true;

            if (isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '0') {
                // Disable
                $delayActive = false;
            }

            $delayV2Active = true;
            if (isset(self::$page_excludes['delay_js_v2']) && self::$page_excludes['delay_js_v2'] == '0') {
                // Disable
                $delayV2Active = false;
            }


            if ((isset(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1')) {
                if (!self::$isAmp->isAmp() && empty($_GET['disableDelay']) && empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {
                    $js_delay = new wps_ic_js_delay_v2();

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
                    //$html = preg_replace_callback('/<script\s+src="([^"]+)"[^>]*>/si', [$this, 'gtagDelay'], $html);

                    return print_r([$html], true);
                }

            }
        }


        // Cache
        $cacheActive = !(isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '0') && ((isset(self::$settings['cache']['advanced']) && self::$settings['cache']['advanced'] == '1') || (isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '1'));

        //clean up all our placeholder comments if not used
        $html = preg_replace('/<!--WPC[\s\S]*?-->/', '', $html);

        // Local Fonts / Bunny localization — mirror of the CDN-on path (cdnRewriter ~5045) for CDN-OFF mode,
        // where this previously never ran, so Local Fonts didn't localize at all (faces stayed on gstatic).
        // Runs after lazyCSS (the deferred <link> exists) and outside the crit gate (applies crit-on AND -off).
        if (!empty(self::$settings['replace-fonts'])) {
            if (self::$settings['replace-fonts'] == 'local') {
                $fonts = new wps_ic_fonts();
                $html  = $fonts->replaceFrontend($html);
            } else if (self::$settings['replace-fonts'] == 'bunny') {
                // Bunny Fonts — GDPR-compliant Google Fonts drop-in (mirror of cdnRewriter)
                $html = str_replace('fonts.googleapis.com', 'fonts.bunny.net', $html);
                $html = preg_replace('/<link\b[^>]*\bhref=["\']https?:\/\/fonts\.gstatic\.com\/[^"\']+["\'][^>]*>\s*/i', '', $html);
                $html = str_replace('fonts.gstatic.com', 'fonts.bunny.net', $html);
            }
        }

        // 7.01.0 — Modern Image Delivery post-process (BETA, default OFF, self-gated)
        // Mirrors the same call in cdnRewriter (line ~3834) for when CDN is disabled.
        // Stands down when negotiated delivery is active (mutually exclusive); harmless no-op
        // in CDN-off mode where negotiated is never active.
        if (class_exists('WPC_Modern_Delivery') && WPC_Modern_Delivery::is_active()
            && !(class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active())) {
            $html = WPC_Modern_Delivery::rewrite_buffer($html);
        }

        // Restore the Edge-negotiate (Mode-B) stashed <img data-wpc-nd> tags.
        if (!empty($wpcnd_local_stash)) {
            $html = strtr($html, $wpcnd_local_stash);
        }

        return $html;
    }

    public function doCacheCombine()
    {
        //used to check if we should do cache or criticalCombine

        if (is_404()) {
            return false;
        }

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'direct') {
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

    /**
     * FrontEnd Editors Detection for various page builders
     * @return bool
     */
    public static function isPageBuilder()
    {
        $page_builders = ['run_compress', //wpc
            'run_restore', //wpc
            'elementor-preview', //elementor
            'fl_builder', //beaver builder
            'et_fb', //divi
            'preview', //WP Preview
            'builder', //builder
            'brizy', //brizy
            'fb-edit', //avada
            'bricks', //bricks
            'ct_template', //ct_template
            'ct_builder', //ct_builder
            'cs-render', //cornerstone
            'tatsu', //tatsu
            'trp-edit-translation', //thrive
            'brizy-edit-iframe', //brizy
            'ct_builder', //oxygen
            'livecomposer_editor', //livecomposer
            'tatsu', //tatsu
            'tatsu-header', //tatsu-header
            'tatsu-footer', //tatsu-footer
            'is-editor-iframe', //tatsu-footer
            'tve', //thrive
            'pagelayer-live'];

        if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], 'cornerstone') !== false) {
            return true;
        }

        if ((!empty($_GET['action']) && $_GET['action'] == 'in-front-editor')) {
            //brizyFrontend fix
            return true;
        }

        if ((!empty($_GET['action']) && sanitize_text_field($_GET['action']) == 'edit#op-builder') || !empty($_GET['op3editor'])) {
            //optimizePress builder fix
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

    /**
     * FrontEnd Editors Detection for various page builders
     * @return bool
     */
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

        if (!empty($_GET['dbg_checkCache'])) {
            die('Check cache');
        }

        if (self::dontRunif()) {
            /**
             * Check for cache first
             */

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

                    // Not required as get cache sorts this
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
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }

        if (isset($_SERVER['HTTP_USER_AGENT']) && (preg_match('#^.*(2.0\ MMP|240x320|400X240|AvantGo|BlackBerry|Blazer|Cellphone|Danger|DoCoMo|Elaine/3.0|EudoraWeb|Googlebot-Mobile|hiptop|IEMobile|KYOCERA/WX310K|LG/U990|MIDP-2.|MMEF20|MOT-V|NetFront|Newt|Nintendo\ Wii|Nitro|Nokia|Opera\ Mini|Palm|PlayStation\ Portable|portalmmm|Proxinet|ProxiNet|SHARP-TQ-GX10|SHG-i900|Small|SonyEricsson|Symbian\ OS|SymbianOS|TS21i-10|UP.Browser|UP.Link|webOS|Windows\ CE|WinWAP|YahooSeeker/M1A1-R2D2|iPhone|iPod|Android|BlackBerry9530|LG-TU915\ Obigo|LGE\ VX|webOS|Nokia5800).*#i', $_SERVER['HTTP_USER_AGENT']) || preg_match('#^(w3c\ |w3c-|acs-|alav|alca|amoi|audi|avan|benq|bird|blac|blaz|brew|cell|cldc|cmd-|dang|doco|eric|hipt|htc_|inno|ipaq|ipod|jigs|kddi|keji|leno|lg-c|lg-d|lg-g|lge-|lg/u|maui|maxo|midp|mits|mmef|mobi|mot-|moto|mwbp|nec-|newt|noki|palm|pana|pant|phil|play|port|prox|qwap|sage|sams|sany|sch-|sec-|send|seri|sgh-|shar|sie-|siem|smal|smar|sony|sph-|symb|t-mo|teli|tim-|tosh|tsm-|upg1|upsi|vk-v|voda|wap-|wapa|wapi|wapp|wapr|webc|winw|winw|xda\ |xda-).*#i', substr($_SERVER['HTTP_USER_AGENT'], 0, 4)))) {
            return true;
        }

        return false;
    }

    public function checkCache_plugins_loaded()
    {
        // Weglot rewrites $_SERVER['REQUEST_URI'] at plugins_loaded priority 10,
        // stripping the language prefix (e.g. /en/ → /).  We capture the real URL
        // here at priority 1 — before Weglot runs — so every subsequent cache key
        // lookup in this request uses the correct, language-aware URL.
        if (defined('WEGLOT_VERSION')) {
            wps_ic_url_key::captureRequestUrl();
        }

        if (!empty($_GET['disableCache']) || !empty($_GET['forceRecombine'])) {
            return true;
        }

        if (!empty($_GET['dbg_checkCache'])) {
            die('Check cache');
        }

        if (self::dontRunif()) {
            /**
             * Check for cache first
             */

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

                    // Not required as get cache sorts this
                    $isCacheValid = true;

                    if (!$isCacheExpired && $isCacheValid) {
                        $cache->getCache($prefix);
                    }

                } else {
                    if (!defined('WPS_IC_CACHE_BUFFER_STARTED')) {
                        //fallback cache buffer start, if we were unable to start in advanced-cache
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

        // URL exclusions (Exclude from Plugin) — bail BEFORE starting the output buffer
        // so cdnRewriter / injectPreloadImages / dnsPrefetch / etc. never even fire.
        // dontRunif() returns false when the URL is excluded.
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

        // Is an ajax request?
        self::$isAjax = (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX);

        // TODO: Check this for wpadmin and frontend ajax
        if (!self::$isAjax) {
            if (is_admin() || !empty($_GET['trp-edit-translation']) || (!empty($_GET['action']) && $_GET['action'] == 'in-front-editor') || (!empty($_GET['fl_builder']) || isset($_GET['fl_builder'])) || !empty($_GET['elementor-preview']) || !empty($_GET['preview']) || !empty($_GET['PageSpeed']) || !empty($_GET['et_fb']) || !empty($_GET['is-editor-iframe']) || !empty($_GET['tve']) || !empty($_GET['tatsu']) || !empty($_GET['ct_builder']) || !empty($_GET['fb-edit']) || (!empty($_GET['builder']) && !empty($_GET['builder_id'])) || !empty($_GET['bricks']) || (!empty($_SERVER['SCRIPT_URL']) && $_SERVER['SCRIPT_URL'] == "/wp-admin/customize.php") || (!empty($_GET['page']) && $_GET['page'] == 'livecomposer_editor') || !empty($_GET['pagelayer-live'])) {
                return true;
            }

            if (!empty($_GET['tatsu']) || !empty($_GET['tatsu-header']) || !empty($_GET['tatsu-footer'])) {
                return true;
            }
        }

        $init = $this->mainInit();

        if (!self::$cdnEnabled && !in_array($_SERVER['PHP_SELF'], ['/wp-login.php', '/wp-register.php'])) {
            // (v7.03.72) Determinism safety-net — never let a page-cache persist a TRANSIENT/internal CDN-off
            // render. When the CDN is configured ON (live-cdn=1) but THIS render fell to the local/origin path
            // only because of the crit-combine extraction pass (?criticalCombine / criticalCombine header) or
            // the orch zone-suppression (provisioning / healthcheck hold), the HTML carries full-size ORIGIN
            // images. If LiteSpeed/WP-cache/GridPane stores that render (the combine pass is keyed by a header a
            // URL-keyed cache ignores, so it can overwrite the canonical page), every visitor gets the
            // over-fetch markup until the next purge — the intermittent "flips back to the bad state" the field
            // reported. Tell the page-caches not to store THIS one, so only a real CDN-on render is ever cached.
            // Deliberate off-states (CDN genuinely off via live-cdn=0, per-page CDN exclude, CF-assets mode) are
            // NOT flagged — they never set these signals. Idempotent + header-safe. Filterable kill-switch.
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
            // Ignore Post Types
        } else {

//            if (!empty($_GET['generateCritical'])) {
//                if (!empty(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') {
//                    self::$criticalCss->sendCriticalUrl();
//                }
//            }

            // Generate Critical CSS if not exists
            if (!empty(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') {
                #self::$criticalCss->generateCriticalCSS();
                //$html = self::$rewriteLogic->runCriticalAjax($html);
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

        // Integrations
        include_once WPS_IC_DIR . 'integrations/addon/integrations.php';

        $wpcAddonIntegrations = new wpc_addon_integrations();
        if ($wpcAddonIntegrations->wpMaintenance()) {
            return true;
        }

        // Check if WP_CLI is being used
        if (defined('WP_CLI') && WP_CLI) {
            // WP_CLI detected, don't run the block
            return true;
        }

        // Check if WP REST API is being accessed
        if (defined('REST_REQUEST') && REST_REQUEST) {
            // WP REST API detected, don't run the block
            return true;
        }

        // Raise memory limit
        if (ini_get('memory_limit') !== '-1' && wpc_convert_to_bytes(ini_get('memory_limit')) < 1024 * 1024 * 1024) {
            ini_set('memory_limit', '1024M');
        }

        // Raise backtrack limit for regex
        ini_set('pcre.backtrack_limit', '10000000');

        global $post;
        self::$options = get_option(WPS_IC_OPTIONS);

        if (!isset(self::$options['api_key']) || empty(self::$options['api_key'])) {
            return true;
        }

        // Was only adding to home page
        if ($this->is_home_url()) {
            if (!self::is_mobile()) {
                #add_action('wp_head', [$this, 'preload_custom_assets'], 1);
            } else {
                #add_action('wp_head', [$this, 'preload_custom_assetsMobile'], 1);
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

        //Add files inline
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

        //Perfmatters settings check
        //$this->perfMattersOverride();

        //Rocket settings check
        //$this->rocketOverride();

        // default excluded keywords
        self::$default_excluded_list = ['wp-admin', 'redditstatic', 'ai-uncode', 'gtm', 'instagram.com', 'fbcdn.net', 'twitter', 'google', 'coinbase', 'cookie', 'schema', 'recaptcha', 'data:image', 'stats.jpg'];

        // Preload anything inside themes,elementor,wp-includes
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
            // (v7.03.112) GUARD: a per-page CDN "include" (cdn=='1') must NOT resurrect the CDN when the
            // GLOBAL CDN is OFF (live-cdn=='0'). Off means off. Without this, a stale per-page include
            // forced cdnEnabled=1 + js/css/serve=1 IN MEMORY while the saved settings were all 0 — so
            // assets kept routing through the zone after the CDN was switched off, and ONLY deactivating
            // the plugin stopped it (the "off but still serving / safe-mode doesn't help" bug).
            self::$cdnEnabled = 1;
            self::$settings['css'] = 1;
            self::$settings['js'] = 1;
            self::$settings['serve']['jpg'] = 1;
            self::$settings['serve']['png'] = 1;
            self::$settings['serve']['gif'] = 1;
            self::$settings['serve']['svg'] = 1;
        }


        // "Nothing to deliver → disable the CDN rewrite" guard. Must include `fonts`, else
        // Fonts-ON-alone (css/js/images all off) flips cdnEnabled=0 and the CDN rewrite path
        // (incl. rewriteInlineFontFaces) never runs → @font-face stays on origin.
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

        // Is an ajax request?
        self::$isAjax = (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX);

        // Don't run in admin side!
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
        // VERIFIED-GATE: emit the CF cname ONLY once provision-verified, so the front-end never
        // switches to a host that errors on every asset. Not-verified → fall back to
        // ic_custom_cname → ic_cdn_zone_name (always a working host). FAIL-OPEN: a never-set flag
        // (legacy zone / fresh connect) is treated as verified so the live fleet never degrades;
        // only an EXPLICITLY-cleared flag ('0', in-flight cname change) blanks it.
        $cfVerified = wpc_cf_cname_verified_ok();
        $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && $cfVerified) ? $cfCname : get_option('ic_custom_cname');

        if (empty($custom_cname) || !$custom_cname) {
            self::$zone_name = get_option('ic_cdn_zone_name');
        } else {
            self::$zone_name = $custom_cname;
        }

        // orch cdn_disabled master kill: blank the runtime zone + force CDN off so this request
        // emits ZERO CDN URLs. Empty zone_name trips the early-return below AND makes every
        // zone-keyed emitter bail on its empty-zone guard. Inert by default.
        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            self::$zone_name = '';
            self::$cdnEnabled = 0;
        }

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'direct') {
            if (!empty($_GET['custom_server'])) {
                $custom_server = sanitize_text_field($_GET['custom_server']);
                if (preg_match('/^[a-z0-9\-]+\.zapwp\.net$/i', $custom_server)) {
                    self::$zone_name = $custom_server . '/key:' . self::$options['api_key'];
                }
            }
        }

        // (v7.03.49) A zone host accidentally equal to the ORIGIN host (a misconfigured custom cname, or a
        // clone that stored its own domain as the zone) would make EVERY transform URL resolve back to the
        // origin → 404. Treat it as no-zone so the whole rewrite stands down (origin served — never a
        // transform→origin 404). Source-level guard covering all builders (fonts, external, same-site).
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

        // Picture tag WebP/AVIF delivery — BOTH gated on the RESOLVED Next-Gen ceiling.
        // effective_ceiling() is the single source of truth: 'off' → no next-gen; 'webp' → webp
        // only; 'avif' → both. Gate both because the legacy settings form / segmented "Off" can
        // leave picture_webp/picture_avif=1 while the resolver tier is jpeg, and <picture> <source>
        // selection is terminal (browsers don't fall through). Fall back to the raw legacy keys
        // only if the resolver class is unavailable.
        $wpc_nextgen_ceiling = class_exists('WPC_Delivery_Resolver')
            ? WPC_Delivery_Resolver::effective_ceiling(self::$settings)
            : 'avif';
        // Images-master: the <picture> webp/avif builder is image-CDN delivery, so it stands down
        // when the "Images" tile is OFF (no image serve-type on). Path A then serves the origin
        // <picture> instead. Mirrors the negotiated/path-A stand-downs.
        $wpc_cdn_images_on = !class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings);
        // Gate the <picture> AVIF/WebP blocks on the resolved DELIVERY ceiling, NOT the raw
        // picture_avif/picture_webp GENERATION flags: a CDN-on OTF zone synthesizes avif at the
        // edge regardless of local generation, so coupling delivery to the local-generate flag
        // would strand a Next-Gen-ON site at zero avif. The per-rung URL flavor (natural -WxH.avif
        // vs never-404 wp:2 vs dropped) is still decided downstream by the per-zone proof/witness
        // gate, so ceiling==='avif' here can never strand a 404ing source on an un-converged zone.
        self::$rewriteLogic::$pictureWebpEnabled = $wpc_nextgen_ceiling !== 'off'
            && !empty(self::$webp_enabled) && self::$webp_enabled == '1'
            && $wpc_cdn_images_on;
        self::$rewriteLogic::$pictureAvifEnabled = $wpc_nextgen_ceiling === 'avif' && $wpc_cdn_images_on;

        // Skip picture wrapping for JSON responses
        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            self::$rewriteLogic::$pictureWebpEnabled = false;
        }

        if (isset(self::$page_excludes['adaptive'])) {
            //self::$lazy_enabled = self::$page_excludes['adaptive'];
            //self::$native_lazy_enabled = self::$page_excludes['adaptive'];
            self::$adaptive_enabled = self::$page_excludes['adaptive'];
            //self::$webp_enabled = self::$page_excludes['adaptive'];
            //self::$retina_enabled = self::$page_excludes['adaptive'];
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

        // If Optimization Quality is Not set...
        if (empty(self::$settings['optimization']) || self::$settings['optimization'] == '' || self::$settings['optimization'] == '0') {
            self::$settings['optimization'] = 'i';
        }

        // Optimization Switch from Legacy
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

            if (!empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'Safari') && !strpos($_SERVER['HTTP_USER_AGENT'], 'Chrome')) {
                self::$webp_enabled = false;
                self::$webp = '0';
            }
        }

        if (!empty($_GET['test_zone'])) {
            if ($_GET['test_zone'] == 'cdn-rage4') {
                self::$zone_test = 1;
                self::$zone_name = $_GET['server'] . '.zapwp.net/key:' . self::$options['api_key'];
            } else {
                self::$zone_name = $_GET['test_zone'] . '.wpmediacompress.com/key:' . self::$options['api_key'];
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
            //check if page is preloaded, and add to list if not
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

        //todo: Why are we checking this again?
        if ((isset(self::$page_excludes['cdn']) && self::$page_excludes['cdn'] == '0') || !$allowLive) {
            self::$cdnEnabled = 0;
            self::$settings['css'] = 0;
            self::$settings['js'] = 0;
            self::$settings['serve']['jpg'] = 0;
            self::$settings['serve']['png'] = 0;
            self::$settings['serve']['gif'] = 0;
            self::$settings['serve']['svg'] = 0;
        } else if (isset(self::$page_excludes['cdn']) && self::$page_excludes['cdn'] == '1' && isset(self::$settings['live-cdn']) && self::$settings['live-cdn'] == '1') {
            // (v7.03.112) GUARD: a per-page CDN "include" (cdn=='1') must NOT resurrect the CDN when the
            // GLOBAL CDN is OFF (live-cdn=='0'). Off means off. Without this, a stale per-page include
            // forced cdnEnabled=1 + js/css/serve=1 IN MEMORY while the saved settings were all 0 — so
            // assets kept routing through the zone after the CDN was switched off, and ONLY deactivating
            // the plugin stopped it (the "off but still serving / safe-mode doesn't help" bug).
            self::$cdnEnabled = 1;
            self::$settings['css'] = 1;
            self::$settings['js'] = 1;
            self::$settings['serve']['jpg'] = 1;
            self::$settings['serve']['png'] = 1;
            self::$settings['serve']['gif'] = 1;
            self::$settings['serve']['svg'] = 1;
        }

        // "Nothing to deliver → disable the CDN rewrite" guard. Must include `fonts`, else
        // Fonts-ON-alone (css/js/images all off) flips cdnEnabled=0 and the CDN rewrite path
        // (incl. rewriteInlineFontFaces) never runs → @font-face stays on origin.
        if (self::$settings['css'] == 0 && self::$settings['js'] == 0 && empty(self::$settings['fonts']) && self::$settings['serve']['jpg'] == 0 && self::$settings['serve']['png'] == 0 && self::$settings['serve']['gif'] == 0 && self::$settings['serve']['svg'] == 0) {
            self::$cdnEnabled = 0;
        }


        // Default to swap if not explicitly set — fixes PageSpeed font-display warning
        if (empty(self::$settings['font-display'])) {
            self::$settings['font-display'] = 'swap';
        }
        if (self::$settings['font-display'] != 'off') {
            add_filter('style_loader_src', [$this, 'add_font_display_swap_to_url'], 1, 2);
            add_filter('style_loader_src', [$this, 'process_css_for_fonts'], 1, 4);
        }

        if (self::$cdnEnabled == 1) {
            if (self::dontRunif()) {

//                if (self::$settings['inline-css'] == '1' && (empty($_GET['criticalCombine']) || empty(wpcGetHeader('criticalCombine')))) {
//                    add_filter('style_loader_tag', [$this, 'inlineCSS'], 10, 4);
//                } else {
                if (self::$css == "1") {
                    add_filter('style_loader_src', [$this, 'adjust_src_url'], 10, 2);
                    add_filter('style_loader_tag', [$this, 'adjust_style_tag'], 10, 4);
                }
                #}

                if (self::$js == "1") {
                    add_filter('script_loader_tag', [$this, 'rewrite_script_tag'], 10, 3);
                }

                #add_filter('script_loader_tag', [$this, 'deferJSAssets'], 10, 3);
            }

            add_action("wp_head", [$this, 'dnsPrefetch'], 0);

            // Rewrite WooCommerce variation image URLs so they match CDN-rewritten DOM URLs
            add_filter('woocommerce_available_variation', [$this, 'rewrite_woo_variation_image_urls'], 10, 3);
        } else {

            // Local Mode
            if (self::dontRunif()) {
//                if (self::$settings['inline-css'] == '1' && (empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine')))) {
//                    add_filter('style_loader_tag', [$this, 'inlineCSS'], 10, 4);
//                }

                if (self::$css == "1") {
                    add_filter('style_loader_src', [$this, 'adjust_src_url'], 10, 2);
                    add_filter('style_loader_tag', [$this, 'adjust_style_tag'], 10, 4);
                }

                if (self::$js == "1") {
                    add_filter('script_loader_src', [$this, 'adjust_src_url'], 10, 3);
                    // WP 6.5+ script MODULES (interactivity API, block-library views) load via the
                    // import-map, which uses script_module_loader_src, NOT script_loader_src. The
                    // buffer pass alone is timing-dependent (import-map can print after the buffer
                    // flushes), so route modules through adjust_src_url to rewrite at the source.
                    add_filter('script_module_loader_src', [$this, 'adjust_src_url'], 10, 2);
                }
            }

            if (self::$js == "1" || self::$css == "1") {
                add_action("wp_head", [$this, 'dnsPrefetch'], 0);
            }
        }
    }

    public function add_font_display_swap_to_url($src, $handle)
    {
        if (strpos($src, 'fonts.googleapis.com') !== false && !empty(self::$settings['font-display'])) {
            $src = add_query_arg('display', self::$settings['font-display'], $src);
        }
        return $src;
    }

    public function process_css_for_fonts($src, $handle)
    {
        // Skip if not a CSS file
        if (strpos($src, '.css') === false) {
            return $src;
        }

        // Skip if not local
        $clean_src = strtok($src, '?');
        if (strpos($clean_src, home_url()) === false) {
            return $src;
        }

        if (!defined('WPS_IC_CSS')) {
            return $src;
        }

        // Serve external-CSS @font-face fonts from the CDN zone when CSS-combine is OFF. The
        // combine path already CDN-rewrites @font-face when combine is ON, so gate to combine-OFF
        // to avoid a double-rewrite (changeFontToCDN has no zone-skip guard). Requires the Fonts
        // toggle + live CDN + zone; flag-gated behind site option `wpc_fonts_cdn_serve` (default
        // ON — without it the Fonts tile drives nothing reachable on combine-OFF sites).
        $wpc_fonts_cdn = apply_filters('wpc_fonts_cdn_serve', (bool) get_site_option('wpc_fonts_cdn_serve', true))
            && !empty(self::$settings['live-cdn']) && self::$settings['live-cdn'] == '1' // no zone font URLs when CDN off
            && !empty(self::$settings['fonts']) && self::$settings['fonts'] == '1'
            && !empty(self::$zone_name)
            && (empty(self::$settings['css_combine']) || self::$settings['css_combine'] != '1');

        // v7.02.46 — Fonts naturalize in lockstep with every other asset surface. CSS/JS collapse
        // m:0/a:→natural via the ungated wpc_asset_naturalize buffer pass, but natural_assets_on() reads
        // false at this enqueue-time hook, so the icon-font url() was the single last m:0/a: request left
        // on the page. m:0 is a pass-through and the natural zone font is byte-identical — verified live:
        // 200 + font/woff2 + access-control-allow-origin:* + 1yr cache, identical to the m:0 form. Gate on
        // natural_assets_on() OR the same wpc_asset_naturalize_enabled off-ramp so fonts move with the rest
        // (one filter disables all asset naturalization). Only the m:0 passthrough is naturalized below —
        // font:true subsetting is a REAL transform and must keep its transform URL.
        $wpc_font_nat = $wpc_fonts_cdn
            && ((class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'natural_assets_on') && wps_rewriteLogic::natural_assets_on())
                || apply_filters('wpc_asset_naturalize_enabled', true));

        // Generate filename. When CDN font rewriting is active, fold the zone + subsetting into
        // the cache key so the optimized file self-invalidates if those change (the WPS_IC_CSS
        // cache has no settings-keyed purge); inactive → key unchanged → zero-risk. Basis is the
        // PARAM-STRIPPED src so a rotating ?ver= can't mint a new file per change (which would
        // grow wp-cio unbounded and leave dead filenames in still-cached HTML).
        $wpc_cache_basis = strtok($src, '?');
        if ($wpc_fonts_cdn) {
            $wpc_subset_key = (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') ? '1' : '0';
            $wpc_cache_basis .= '|wpccf|' . self::$zone_name . '|' . $wpc_subset_key;
            // Fold the natural-font-URL state so an already-written cio file rebuilds ONCE when
            // naturalization is active — without a key change the old m:0/a: proxy-font file is
            // returned from the file_exists cache below and the naturalize-on-write never lands.
            if ($wpc_font_nat) {
                $wpc_cache_basis .= '|wpcfontnat';
            }
        }
        // CSS-file SVG zoneify. This writer ABSOLUTIZES every url() against home_url() (below),
        // converting relative SVG refs into ORIGIN-absolute URLs — un-resolvable to the zone even
        // when the CSS file itself is zone-served. So rewrite uploads-SVG url() to the natural zone
        // host before save, and fold the zone into the cache key so the file self-rebuilds on zone
        // change / CDN-off (key unchanged when inactive → zero-risk).
        $wpc_svg_cdn = self::wpc_svg_zoneify_active();
        if ($wpc_svg_cdn) {
            // Marker folded into the key; bumping it forces every already-written cio file to
            // rebuild under a new name on the next render.
            $wpc_cache_basis .= '|wpccss3|' . self::$zone_name;
            // Fold the CSS-bg image-set state so an already-written cio file rebuilds ONCE when the
            // feature flips. The wp-cio hash is keyed on $wpc_cache_basis (NOT produced bytes) and
            // the fast-path returns by filename without re-reading content, so without this marker a
            // flip would keep returning the OLD same-ext-only file. Absent when off → byte-identical.
            if (self::wpc_css_bg_imageset_active()) {
                $wpc_cache_basis .= '|wpcbgis1';
            }
        }
        $hash = substr(md5($wpc_cache_basis), 0, 10);
        $new_filename = sanitize_file_name($handle . '-' . $hash . '.css');
        $new_filepath = WPS_IC_CSS . '/' . $new_filename;

        // If optimized file exists AND is non-empty, return its URL (the DOMINANT warm-serve path).
        // A bare file_exists() would treat a 0-byte/partial file (failed/short write, or a purge
        // unlink racing a writer) as a valid hit and bake its hashed href into cached HTML → durable
        // 404. Require filesize>0 so a stub falls through to the atomic re-mint below, which lands the
        // SAME hashed filename (hash is content/settings-derived) → self-heals next render, no
        // rotation. clearstatcache() so we read the CURRENT inode, not a sibling's stale stat.
        clearstatcache(true, $new_filepath);
        if (file_exists($new_filepath) && @filesize($new_filepath) > 0) {
            $new_url = WPS_IC_CSS_URL . '/' . $new_filename;
            return $new_url;
        }
        if (file_exists($new_filepath)) {
            // 0-byte residue (pre-fix incident file or foreign stub) — drop it so the
            // atomic re-write below replaces it under the same name this render.
            @unlink($new_filepath);
        }

        // Create optimized file
        $css_path = str_replace(home_url(), ABSPATH, $clean_src);
        $css_path = str_replace('/', DIRECTORY_SEPARATOR, $css_path);

        if (!file_exists($css_path) || !is_readable($css_path)) {
            return $src;
        }

        $css_content = @file_get_contents($css_path);

        if (empty($css_content)) {
            return $src;
        }

        // Don't hard-skip when there's no @font-face: some CSS (e.g. elementor post-*.css) carries
        // absolute origin uploads URLs but no @font-face, and would otherwise stay on origin. Also
        // process uploads-referencing CSS when the zone sweep is active; pure-no-asset CSS skips.
        $wpc_has_fontface = (stripos($css_content, '@font-face') !== false);
        if (!$wpc_has_fontface && !($wpc_svg_cdn && stripos($css_content, '/wp-content/uploads/') !== false)) {
            return $src;
        }

        // Get the base URL for the original CSS file (directory containing the CSS)
        $css_base_url = dirname($clean_src);

        // Convert relative URLs to absolute URLs
        $css_content = preg_replace_callback('/url\s*\(\s*(["\']?)([^"\')]+)\1\s*\)/i', function ($matches) use ($css_base_url) {
            $quote = $matches[1];
            $url = $matches[2];

            // Skip if already absolute URL or data URI
            if (preg_match('/^(https?:|data:|#)/i', $url)) {
                return $matches[0];
            }

            // Handle protocol-relative URLs
            if (strpos($url, '//') === 0) {
                $protocol = wpc_request_is_https() ? 'https:' : 'http:'; // proxy-aware (bare is_ssl() downgrades fonts behind Cloudflare)
                return 'url(' . $quote . $protocol . $url . $quote . ')';
            }

            // Handle root-relative URLs
            if (strpos($url, '/') === 0) {
                return 'url(' . $quote . home_url($url) . $quote . ')';
            }

            // Handle relative URLs (including ./ and ../)
            // Remove ./ prefix if present
            if (strpos($url, './') === 0) {
                $url = substr($url, 2);
            }

            // Build absolute URL from base
            $absolute_url = $css_base_url . '/' . $url;

            // Resolve ../ in the path
            while (strpos($absolute_url, '/../') !== false) {
                $absolute_url = preg_replace('/\/[^\/]+\/\.\.\//', '/', $absolute_url);
            }

            return 'url(' . $quote . $absolute_url . $quote . ')';
        }, $css_content);

        // Now that every font url() is absolute, route @font-face fonts through the CDN zone using
        // the SAME form as changeFontToCDN (m:0 passthrough; font:true when subsetting + non-icon)
        // so it inherits the zone's working font CORS. Scope to @font-face blocks (so the FAMILY is
        // in context) and detect icon fonts by BOTH family AND URL — icon fonts must never be
        // subsetted or their PUA glyphs mangle. Idempotent + same-site only.
        if ($wpc_fonts_cdn) {
            $wpc_subsetting = (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1');
            $wpc_site_host = wp_parse_url(home_url(), PHP_URL_HOST);
            $wpc_zone = (string) self::$zone_name;
            // Strip CSS comments first (same regex as combine_css::removeCommentsFromCSS) so a
            // stray '}' inside a comment can't truncate the @font-face block matcher below. Only
            // runs under this flag, so the optimized CSS is byte-identical when the feature is OFF.
            $css_content = preg_replace('/\/\*[^*]*\*+([^\/][^*]*\*+)*\//', '', $css_content);
            $css_content = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($block) use ($wpc_subsetting, $wpc_site_host, $wpc_zone, $wpc_font_nat) {
                $rule = $block[0];
                // Family-based icon detection (same list as findFontFace / the font-display
                // pass below) — catches fa-, material, dashicon, icomoon, etc. that the
                // URL alone would miss.
                $family_is_icon = false;
                if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $rule, $fam)) {
                    if (preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple.?line/i', strtolower(trim($fam[1])))) {
                        $family_is_icon = true;
                    }
                }
                return preg_replace_callback('/url\s*\(\s*(["\']?)(https?:[^"\')]+\.(?:woff2|woff|eot|ttf)(?:[?#][^"\')]*)?)\1\s*\)/i', function ($m) use ($wpc_subsetting, $wpc_site_host, $wpc_zone, $family_is_icon, $wpc_font_nat) {
                    $url = $m[2];
                    // Already on the zone? leave untouched (idempotent / no double-rewrite).
                    if ($wpc_zone !== '' && strpos($url, $wpc_zone) !== false) {
                        return $m[0];
                    }
                    // HOST GUARD (v7.03.48) — never build a transform on an empty/origin zone host: an empty
                    // zone yields "https:///…" → resolves to the ORIGIN → 404 (and a zone equal to the origin
                    // host 404s the same; also the suppressed-zone state). Mirrors the guard now in
                    // replaceFonts / cdn_rewrite_url / cdnExternalUrls. Stay on the natural URL otherwise.
                    if ($wpc_zone === '' || ($wpc_site_host !== '' && strcasecmp((string) $wpc_zone, (string) $wpc_site_host) === 0)) {
                        return $m[0];
                    }
                    // Same-site only — leave third-party fonts (gstatic, typekit, fontawesome
                    // CDN, etc.) untouched.
                    $host = wp_parse_url($url, PHP_URL_HOST);
                    if (empty($host) || empty($wpc_site_host) || strcasecmp($host, $wpc_site_host) !== 0) {
                        return $m[0];
                    }
                    // PATH SCOPE: only zoneify fonts under /wp-content/ — the paths the CDN serves
                    // with correct CORS. A custom store path (e.g. /storage/) is 302'd cross-origin
                    // back to the ORIGIN, and a redirected cross-origin font fetch is CORS-blocked →
                    // the font fails to load. Leaving such fonts on the same-origin URL is always safe.
                    $u_path = (string) wp_parse_url($url, PHP_URL_PATH);
                    if (stripos($u_path, '/wp-content/') === false) {
                        return $m[0];
                    }
                    // URL-based icon detection (the combine-path list: changeFontToCDN:1740 /
                    // replaceFonts:594) as a second signal alongside the family check.
                    $lower = strtolower($url);
                    $url_is_icon = (strpos($lower, 'icon') !== false || strpos($lower, 'awesome') !== false || strpos($lower, 'lightgallery') !== false || strpos($lower, 'gallery') !== false || strpos($lower, 'side-cart-woocommerce') !== false);
                    if ($wpc_subsetting && !$family_is_icon && !$url_is_icon) {
                        // font:true = a REAL subsetting transform (NOT a pass-through) → never naturalized.
                        $cdn_url = 'https://' . $wpc_zone . '/font:true/a:' . wps_cdn_rewrite::reformat_url($url);
                    } elseif ($wpc_font_nat) {
                        // m:0 is a pass-through → emit the clean natural zone URL (byte-identical delivery,
                        // CORS + font/woff2 verified live). Keeps the icon font in lockstep with CSS/JS/images.
                        $wpc_fnt_abs = wps_cdn_rewrite::reformat_url($url);
                        $wpc_fnt_pp = wp_parse_url($wpc_fnt_abs);
                        if (is_array($wpc_fnt_pp) && !empty($wpc_fnt_pp['path'])) {
                            $cdn_url = 'https://' . $wpc_zone . $wpc_fnt_pp['path'] . (isset($wpc_fnt_pp['query']) ? '?' . $wpc_fnt_pp['query'] : '');
                        } else {
                            $cdn_url = 'https://' . $wpc_zone . '/m:0/a:' . $wpc_fnt_abs; // unparsable → safe transform
                        }
                    } else {
                        $cdn_url = 'https://' . $wpc_zone . '/m:0/a:' . wps_cdn_rewrite::reformat_url($url);
                    }
                    return 'url(' . $m[1] . $cdn_url . $m[1] . ')';
                }, $rule);
            }, $css_content);
        }

        // Add or replace font-display (icon fonts get separate setting)
        $iconFontDisplay = !empty(self::$settings['icon-font-display']) ? self::$settings['icon-font-display'] : 'block';
        $css_content = preg_replace_callback('/(@font-face\s*\{)([^}]*)(})/is', function ($matches) use ($iconFontDisplay) {
            $content = $matches[2];

            // Remove existing font-display if present
            $content = preg_replace('/font-display\s*:\s*[^;]+;?/i', '', $content);

            // Detect icon fonts by font-family name — use block to prevent garbled characters
            $fontDisplayValue = self::$settings['font-display'] ?? 'swap';
            if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $content, $familyMatch)) {
                $family = strtolower(trim($familyMatch[1]));
                if (preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple.?line/i', $family)) {
                    $fontDisplayValue = $iconFontDisplay;
                }
            }

            // Leading ';' — minified CSS drops the last semicolon before '}', and fusing
            // onto the prior declaration (src:url(x)font-display:swap) invalidates it,
            // silently killing the font. A doubled ';;' is valid CSS (empty declaration).
            return $matches[1] . $content . ';font-display:' . $fontDisplayValue . ';' . $matches[3];
        }, $css_content);

        // Save optimized file
        if (!file_exists(WPS_IC_CSS)) {
            wp_mkdir_p(WPS_IC_CSS);
        }

        // Host-swap origin uploads-SVG url() to the natural zone URL (gates re-checked inside).
        if ($wpc_svg_cdn) {
            $css_content = self::wpc_svg_zoneify($css_content);
            // Rasters too: (1) zone TRANSFORM urls inside CSS (adaptive/combine-era w:N forms)
            // collapse via the same sweep the HTML passes use; (2) plain ORIGIN uploads rasters
            // host-swap SAME-EXT (alpha-safe — CSS has no onerror fallback).
            $css_content = self::wpc_raster_naturalize($css_content);
            $wpc_css_origin = wp_parse_url(home_url(), PHP_URL_HOST);
            if ($wpc_css_origin && strcasecmp((string) self::$zone_name, $wpc_css_origin) !== 0) { // zone must differ from origin
                // CSS-background raster: host-swap to the zone, SAME-EXT (NEVER-404 floor).
                // CSS url() has no <picture>/onerror fallback, so fabricating a .webp that 404s
                // (when only the avif is on disk) = a broken background. Same-ext host-swap always
                // points at a real file → always 200. Format upgrade returns via the image-set()
                // pass below. Scope: png/jpg/jpeg/gif uploads, same-site; svg via zoneify; webp/avif left.
                $o = preg_quote($wpc_css_origin, '#');
                // FORMAT-UPGRADE PASS (scoped to background[-image] raster url() only): offer the
                // two-declaration image-set() upgrade (on-disk avif/webp siblings, never-404,
                // type()-self-selected → CF vary-blind-safe). On any miss (no/corrupt sibling, flag
                // off, idempotent hit) it returns the SAME-EXT host-swap. gif/non-bg url() untouched.
                $css_content = preg_replace_callback(
                    '#(background(?:-image)?\s*:\s*[^;{}]*?url\(\s*)([\'"]?)https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?)\.(png|jpe?g)((?:\?[^"\'()\s<>]*)?)\2(\s*\))#i',
                    function ($m) use ($wpc_css_origin) {
                        // IDEMPOTENCY (layer 1): never re-wrap a declaration we already image-set'd.
                        if (stripos($m[0], 'image-set(') !== false) return $m[0];
                        $origin_url   = 'https://' . $wpc_css_origin . $m[3] . '.' . $m[4] . $m[5];
                        $sameext_zone = 'https://' . self::$zone_name . $m[3] . '.' . $m[4] . $m[5];
                        $iset = self::wpc_css_bg_imageset_build($origin_url, $sameext_zone, $m[2]);
                        if ($iset !== '') {
                            return $iset; // the matched 'background[-image]:...url(...)' is fully replaced
                        }
                        // Fall through: same-ext host-swap, preserving the matched prefix/quote/suffix.
                        return $m[1] . $m[2] . $sameext_zone . $m[2] . $m[6];
                    },
                    $css_content
                );
                // EXISTING same-ext sweep (UNCHANGED) — host-swaps any remaining raster the upgrade pass
                // did not touch: gif backgrounds, non-background url() (content:, mask, etc.), and any
                // png/jpg the scoped regex missed. Idempotent on already-zoned urls (origin-anchored).
                $css_bg_edge_webp = (class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active());
                $css_content = preg_replace_callback(
                    '#https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?)\.(png|jpe?g|gif)((?:\?[^"\'()\s<>]*)?)#i',
                    function ($m) use ($css_bg_edge_webp) {
                        // VERIFIED CDN-edge (Bunny+Vary): emit the negotiable .webp base for any raster
                        // CSS url() the image-set pass above missed (2nd+ url() of a multi-background,
                        // mask-image, content:, cursor, etc.). The edge Vary-negotiates AVIF/WebP/JPEG, so
                        // .webp is the one base; gif stays gif. Non-edge keeps the origin ext (never-404).
                        $ext = strtolower($m[2]);
                        // GIF to the zone ONLY on a CF-direct zone (no Bunny egress for an
                        // un-optimizable CSS-background GIF); on a Bunny zone leave it on origin.
                        if ($ext === 'gif' && !(class_exists('wps_rewriteLogic') && wps_rewriteLogic::cf_is_delivery())) {
                            return $m[0];
                        }
                        if ($css_bg_edge_webp && $ext !== 'gif') {
                            return 'https://' . self::$zone_name . $m[1] . '.webp' . $m[3];
                        }
                        // host-swap to zone, KEEP the original extension (real file → 200).
                        return 'https://' . self::$zone_name . $m[1] . '.' . $m[2] . $m[3];
                    },
                    $css_content
                );
                // Already-next-gen (webp/avif) uploads refs → same-ext natural (optimal).
                $css_content = preg_replace(
                    '#https?://' . $o . '(/wp-content/uploads/[^"\'()\s<>]+?\.(?:webp|avif)(?:\?[^"\'()\s<>]*)?)#i',
                    'https://' . self::$zone_name . '$1',
                    $css_content
                );
            }
        }

        // Naturalize the zone URLs in this written CSS file so a standalone stylesheet emits the
        // SAME clean font URL as the inline/preload @font-face in the page HTML. The buffer-level
        // naturalize only runs over HTML, never a separately-fetched CSS file's bytes — so without
        // this the file ships the m:0/a: proxy form while the buffer ships natural, and the SAME
        // woff2 downloads twice. Same natural_assets_on() gate as the buffer (and the |wpcfontnat
        // cache-key marker above) so both surfaces stay in one mode and content/key changes coincide.
        if ($wpc_fonts_cdn
            && class_exists('wps_rewriteLogic')
            && method_exists('wps_rewriteLogic', 'natural_assets_on')
            && wps_rewriteLogic::natural_assets_on()) {
            $css_content = wps_rewriteLogic::naturalize_asset_urls($css_content);
        }

        // ATOMIC, CHECKED, VERIFIED write. The hashed <link href> returned below is baked into the
        // page-HTML cache and re-validated NOTHING at serve time, so a failed/partial/raced write
        // would freeze a permanent 404 into cached HTML. Fix: write to a unique temp sibling (same
        // dir → atomic rename, no torn file ever visible to a reader), CHECK real bytes landed,
        // rename into the stable name (last-writer-wins), then EMIT-GUARD re-stat and only return
        // the hashed URL when present & non-empty — else fall back to $src (always-200 original).
        // The PUBLIC filename stays stable (<handle>-<hash>.css); only the .tmp rotates and is
        // never referenced by HTML → no double-fetch, nothing re-minted per render.
        $wpc_pid = function_exists('getmypid') ? getmypid() : 0;
        $wpc_tmp_path = $new_filepath . '.' . $wpc_pid . '.' . substr(md5(uniqid('', true)), 0, 8) . '.tmp';
        $wpc_bytes = @file_put_contents($wpc_tmp_path, $css_content);
        if ($wpc_bytes === false || $wpc_bytes <= 0) {
            if (file_exists($wpc_tmp_path)) {
                @unlink($wpc_tmp_path);
            }
            // A racing writer may have already landed the real file — honor it.
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

        // Final emit-time guard: the backing file MUST be present & non-empty right now
        // or we refuse to bake its hash into the (about-to-be-cached) HTML.
        clearstatcache(true, $new_filepath);
        if (!file_exists($new_filepath) || @filesize($new_filepath) <= 0) {
            return $src;
        }

        $new_url = WPS_IC_CSS_URL . '/' . $new_filename;
        return $new_url;
    }

    /**
     * Shared @font-face → CDN-zone rewriter. Rewrites SAME-SITE @font-face
     * font url() (woff2/woff/eot/ttf) to the zone using the same form as the combine path
     * (m:0/a: passthrough; font:true/a: when $subsetting + non-icon). Scoped to @font-face blocks;
     * icon-safe (family + URL); idempotent (skips URLs already on the zone); comments stripped so a
     * stray '}' can't truncate the block matcher. Operates on ABSOLUTE url() only (callers resolve
     * relatives first; relative inline url() is left untouched = safe). Returns $css unchanged when
     * $zone is empty. Used by rewriteInlineFontFaces() (inline <style> @font-face — block themes);
     * process_css_for_fonts() (external <link> CSS) keeps its own proven inline copy.
     */
    public static function rewrite_fontface_css($css, $zone, $subsetting, $site_host)
    {
        $zone = (string) $zone;
        if ($zone === '' || strpos($css, '@font-face') === false) return $css;
        $css = preg_replace('/\/\*[^*]*\*+([^\/][^*]*\*+)*\//', '', $css);
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
                // PATH SCOPE: only zoneify fonts under /wp-content/. A same-site custom store path
                // (e.g. /storage/) the CDN 302s cross-origin → CORS-blocked → font fails; same-origin
                // under /wp-content/ is always safe (no redirect, no CORS net for fonts).
                if (stripos((string) wp_parse_url($url, PHP_URL_PATH), '/wp-content/') === false) return $m[0];
                // Local-Fonts cache (self-host): leave on origin. The localized stylesheet is served as a
                // static file with origin woff2 URLs, so zoneifying the inline copy would diverge — inline +
                // preload land on the zone while the deferred .css stays origin → the browser binds the
                // (origin) deferred face, double-fetches, and the zone preload is wasted (FOUT).
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

        if (!empty($_GET['dbgPreload'])) {
            echo print_r($preloads, true);
        }

        if (!empty($preloads) && is_array($preloads)) {
            $allPreloadUrls = [];

            // Collect all URLs from both lcp and custom arrays
            if (!empty($preloads['lcp']) && is_array($preloads['lcp'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['lcp']);
            }

            if (!empty($preloads['custom']) && is_array($preloads['custom'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['custom']);
            }

            // Process each URL
            foreach ($allPreloadUrls as $preloadItem) {
                if (empty($preloadItem)) continue; // Skip empty URLs

                // Extract full URL from HTML if possible
                $fullUrl = $this->extractUrlFromHtml($preloadItem, $html);
                if (empty($fullUrl)) {
                    continue;
                }

                $extra = '';
                $type = '';

                // Parse URL to get extension without query parameters
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

    /**
     * Helper function to extract full URL from HTML for a given resource
     */
    private function extractUrlFromHtml($resource, $html)
    {
        if (empty($resource) || empty($html)) {
            return $resource;
        }

        // Escape special regex characters in the resource name
        $escapedResource = preg_quote($resource, '/');

        // Pattern to match URLs containing the resource between quotes
        // Matches: href="...resource..." or src="...resource..." or content="...resource..."
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

        if (!empty($_GET['dbgPreload'])) {
            echo print_r($preloads, true);
        }

        if (!empty($preloads) && is_array($preloads)) {
            $allPreloadUrls = [];

            // Collect all URLs from both lcp and custom arrays
            if (!empty($preloads['lcp']) && is_array($preloads['lcp'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['lcp']);
            }

            if (!empty($preloads['custom']) && is_array($preloads['custom'])) {
                $allPreloadUrls = array_merge($allPreloadUrls, $preloads['custom']);
            }

            // Process each URL
            foreach ($allPreloadUrls as $preloadItem) {
                if (empty($preloadItem)) continue; // Skip empty URLs

                // Extract full URL from HTML if possible
                $fullUrl = $this->extractUrlFromHtml($preloadItem, $html);
                if (empty($fullUrl)) {
                    continue;
                }

                $extra = '';
                $type = '';

                // Parse URL to get extension without query parameters
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

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'bas64_encode') {
            return print_r([$html], true);
        }

        return '[script-wpc]' . $html . '[/script-wpc]';
    }

    public function script_decode($html)
    {
        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'bas64_decode') {
            return print_r([$html], true);
        }

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'no_decode') {
            return $html[1];
        }

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'after_base64_replace') {
            return $html[1];
        }

        $html = base64_decode($html[1]);

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'after_base64_decode') {
            return $html;
        }

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'bas64_decode_after') {
            return print_r([str_replace('<iframe', 'framea', $html)], true);
        }

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

        // Optional: Safety check for valid decoded HTML
        if ($html === false) {
            return ''; // Or return $matches[0] to leave it unchanged
        }

        return $html; // Return decoded HTML, without the tags
    }

    public function jetsmart_ajax_rewrite($args)
    {
        $html = $args['content'];

        //Prep Site URL
        $escapedSiteURL = quotemeta(self::$home_url);
        $regExURL = '(https?:|)' . substr($escapedSiteURL, strpos($escapedSiteURL, '//'));

        //Prep Included Directories
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
            //mainInit() didnt run, we dont have to save cache, return the buffer.
            return $html;
        }

        $cacheActive = !(isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '0') && ((isset(self::$settings['cache']['advanced']) && self::$settings['cache']['advanced'] == '1') || (isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '1'));

        if ($cacheActive) {
            if ((!self::isExcludedFromCache($html) && $this->doCacheCombine())) {
                $prefix = '';
                if (self::is_mobile()) $prefix .= 'mobile';
                if (self::is_webp_request()) $prefix .= ($prefix ? '-' : '') . 'webp';

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

        // Is Woo commerce Cart
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

    public function cdnRewriter($html)
    {

        if (!empty($_GET['forceCritical'])) {
            $urlKey = new wps_ic_url_key();
            $requests = new wps_ic_requests();
            $postID = get_queried_object_id();
            $url = get_permalink($postID);
            $url_key = $urlKey->setup($url);
            $args = ['url' => $url . '?criticalCombine=true&testCompliant=true', 'version' => '6.60.60', 'async' => 'false', 'dbg' => 'true', 'hash' => time() . mt_rand(100, 9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];
            #$args = ['url' => $url.'?disableWPC=true', 'async' => 'false', 'dbg' => 'false', 'hash' => time().mt_rand(100,9999), 'apikey' => get_option(WPS_IC_OPTIONS)['api_key']];

            // (v7.03.121) FATAL FIX: was self::$API_URL — an UNDECLARED static (the property is lowercase
            // self::$apiUrl, line 77; PHP static-prop names are case-sensitive) → "Access to undeclared
            // static property wps_cdn_rewrite::$API_URL" white-screen, reachable via ?forceCritical=1 on a CDN-on render.
            $call = $requests->POST(self::$apiUrl, $args, ['timeout' => 0.1, 'blocking' => false, 'headers' => array('Content-Type' => 'application/json')]);

            return print_r(['key' => $url_key, 'url' => $url, 'call' => $call], true);
        }

        // Heal mixed content: upgrade same-host http://→https:// on an https request before any
        // rewriter runs, so a proxy-misdetected core enqueue (is_ssl() false at the origin behind
        // Cloudflare/WP Engine) can't leave blocked CSS/JS on the page. No-op when nothing to heal.
        $html = wpc_heal_mixed_content($html);

        // Negotiated delivery (COMPOSE, not bypass). When active, emit native .webp <img> tags here
        // (each marked data-wpc-nd) and CONTINUE the pipeline so the CSS-bg / font / JS / critical /
        // preload rewriters all still run. The legacy <img> rewriters skip data-wpc-nd tags, so each
        // image is delivered exactly once. is_active_jpeg() is the Next-Gen-OFF clean-URL sibling:
        // same gate, but emits natural .jpg URLs (no transforms/optimizer) instead of .webp. Either
        // mode owns image delivery for the request; rewrite_buffer() picks the URL flavour internally.
        if (class_exists('WPC_Negotiated_Delivery')
            && (WPC_Negotiated_Delivery::is_active() || WPC_Negotiated_Delivery::is_active_jpeg())) {
            $html = WPC_Negotiated_Delivery::rewrite_buffer($html);
            // Negotiated owns image delivery this request → stand down the legacy <picture>
            // builder (picture_webp). Negotiated <img> are marked data-wpc-nd and the
            // replaceImageTagsDo path already skips them, but forcing pictureWebpEnabled off
            // guarantees no <picture> wrap slips through. (WPC_Modern_Delivery is gated at its
            // own dispatch near the return — both <picture> builders must defer to negotiated.)
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

        /**
         * Woocommerce fix - store stops working
         */
        if (isset($_GET['wc-ajax']) || isset($_GET['product_sku']) || !empty($_POST['product_sku'])) {
            return $html;
        }

        /**
         * WP Datatables Fix
         */
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
            self::$rewriteLogic::$pictureWebpEnabled = false; // AMP doesn't allow <picture>
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'action') {
            return $html;
        }


        // This is for AJAX Replace, works on Jet Engine and some others - might need integration
        // TODO: Integration for other ajax loaders
        if (!empty($_POST['action'])) {
            // Find all URLs on page that have not been replaced
            // Images-master gate: replaceImageTagsDoSlash has no per-format gate and this branch
            // early-returns BEFORE the master image gate below, so without this an Images-OFF site
            // still transforms <img> on front-end POST/AJAX renders. CSS/JS/fonts unaffected.
            if (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
                $html = preg_replace_callback('/(?<![\"|\'])<img[^>]*>/i', [self::$rewriteLogic, 'replaceImageTagsDoSlash'], $html);
            }

            return $html;
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'wpc_disableCommentClear') {
            return $html;
        }

        if (empty($_GET['wpc_disableCommentClear'])) {
            //clear html comments (so combine doesn't pick them up)
            $html = preg_replace("/<!--->/ms", '', $html);
            $html = preg_replace_callback("/<!--(.*?)-->/ms", function ($matches) {
                if (strpos($matches[1], 'sc_project') !== false || strpos($matches[1], 'et-ajax') !== false) {
                    // important stuff inside comments
                    return $matches[0];
                } else {
                    return '';
                }
            }, $html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'scriptContent') {
            return $html;
        }


        //Prep Site URL
        $this->getRegexp();

        if (empty($_GET['wpc_disableStrip'])) {
            $html = self::$rewriteLogic->scriptContent($html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'replace_iframe_tags') {
            return $html;
        }

        // Layzload Iframe - sets load="lazy" to iframe tag
        // TODO: Fix so that it checks does iframe already have load="lazy|auto"
        if (!empty(self::$settings['iframe-lazy']) && self::$settings['iframe-lazy'] == '1' && !$isUserLoggedIn) {
            $html = preg_replace_callback('/<iframe[^>]*>(.*?)<\/iframe>/si', [$this, 'replace_iframe_tags'], $html);
            $html = preg_replace_callback('/<source([^>]*)\ssrc=["\']([^"\']+)["\']/i', [$this, 'replace_source_tags'], $html);
        }

        // Add preload="none" to video tags — prevents browser from downloading video until play
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

        // Replace Background
        if (!empty(self::$settings['background-sizing']) && self::$settings['background-sizing'] == '1') {
            $html = self::$rewriteLogic->backgroundSizing($html);
        } else {
            // Elementor slideshow background images must reach the CDN even when Background-Sizing is
            // OFF. The data-settings slideshow rewrite lives inside backgroundSizing() (gated above),
            // so run ONLY the slideshow data-settings pass here (clean same-ext CDN URL = always 200)
            // without the broader inline-CSS background:url() rewrite the operator left off.
            $html = self::$rewriteLogic->backgroundSlideshowOnly($html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'replaceImageTags') {
            return $html;
        }


        if (!empty($_GET['debug_preload_inject'])) {
            $dbg = 'Before:';
            $dbg .= $html;
        }

        $html = preg_replace_callback('/<head\b[^>]*>/is', [$this, 'injectPreloadImages'], $html, 1);

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

        // Protect existing <picture> blocks from double-wrapping by picture_webp feature
        $wpcPictureBlocks = [];
        if (self::$rewriteLogic::$pictureWebpEnabled) {
            $html = preg_replace_callback('/<picture\b[^>]*>.*?<\/picture>/is', function ($m) use (&$wpcPictureBlocks) {
                $i = count($wpcPictureBlocks);
                $wpcPictureBlocks[$i] = $m[0];
                return '<!--WPC_PICTURE_' . $i . '-->';
            }, $html);
        }

        // Replace <img> tags
        // Images-master gate. Images tile OFF ⇒ content-image CDN rewriting (<img> transforms +
        // srcset + the <picture> webp/avif builder) stands down so images serve from origin (path
        // A emits the origin <picture>). CSS/JS/font rewriting is a separate, unaffected pass.
        if (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
            $html = self::$rewriteLogic->replaceImageTags($html);
        }

        // Restore protected <picture> blocks
        foreach ($wpcPictureBlocks as $i => $block) {
            $html = str_replace('<!--WPC_PICTURE_' . $i . '-->', $block, $html);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'replaceImageTags2') {
            return $html;
        }

        // Inline @font-face rewrite: applies font-display:swap (or the user setting) to inline
        // <style> @font-face rules, which block themes emit and the external-CSS 'font-display'
        // path silently missed. Uses the same findFontFace() icon-font detection. (The LCP-image
        // and font preload passes that also fed the <head> placeholder are disabled just below.)
        $html = $combine_css->rewriteInlineFontFaces($html);

        // LCP <link rel=preload as=image> DISABLED: the hero is already eager via
        // fetchpriority="high", so the preload is redundant; and its imagesrcset doesn't byte-match
        // the <picture> source, so the browser can DOUBLE-FETCH the hero. Re-enable only after
        // guaranteeing the preload imagesrcset is byte-identical to the source.
        $preloadLCP = '';  // was: implode('', $combine_css->preloadLCP($html))

        // Font preloading REVERTED: on throttled connections it saturates the pipe ahead of the LCP
        // element (an LCP anti-pattern), and font-display:swap above already lets text paint with a
        // fallback so fonts never need to block. Re-enable only behind an explicit fast-connection opt-in.
        // $fontPreloadLinks = $combine_css->extractFontPreloadLinks($html);  // disabled
        $preloadFonts = '';

        $html = str_replace('<!--WPC_INSERT_PRELOAD_MAIN-->', $preloadLCP . $preloadFonts, $html);

        // Replace <picture> tags (both original restored ones and new wpc-picture ones)
        // Images-master gate: replaceSourceTags (inside) strips theme <source>/<img> srcset before
        // the per-URL isImage gate, degrading a theme's own responsive <picture> when Images is OFF.
        // Stand down entirely then (path A owns the origin <picture>).
        if (!class_exists('WPC_Negotiated_Delivery') || WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
            $html = self::$rewriteLogic->replacePictureTags($html);
        }

        // Lazy cache-buster, applied ONCE here AFTER replacePictureTags (which rebuilds <source>
        // srcsets, stripping any buster the picture builders added). ONLY when CDN lazy (smart) is
        // on. Appends a per-request rotating "?v=" to NOT-YET-LANDED transform urls (those with
        // "/q:i/") so the CDN re-checks origin until the native variant lands; landed/native urls
        // (no "/q:i/") cache normally and the buster self-removes once @file_exists flips to the
        // clean native url. Bunny keys its cache on the query string for these urls, so "?v=" busts.
        if (function_exists('wpc_v2_get_lazy_enabled') && wpc_v2_get_lazy_enabled()) {
            $lazy_v = substr(md5(microtime(true) . wp_rand(1, 9999999)), 0, 10);
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

        // Find revSlider Data-thumb
        $html = self::$rewriteLogic->revSliderReplace($html);

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'cdn_rewrite_url') {
            return $html;
        }

        // Critical CSS Remove from Header
        $criticalActive = !(isset(self::$page_excludes['critical_css']) && self::$page_excludes['critical_css'] == '0') && ((isset(self::$settings['critical']['css']) && self::$settings['critical']['css'] == '1') || (isset(self::$page_excludes['critical_css']) && self::$page_excludes['critical_css'] == '1')) && (empty($settings['developer_mode']) || $settings['developer_mode'] == '0');

        $criticalCSS = new wps_criticalCss();
        $criticalCSSExists = $criticalCSS->criticalExists();


        //Combine CSS
        if ($criticalCombine || (!empty(self::$settings['css_combine']) && self::$settings['css_combine'] == '1')) {
            if (empty($_GET['stopCombineCSS'])) {
                $html = $combine_css->maybe_do_combine($html);
            }
        }

        if (!$criticalCombine) {
//            if (isset(self::$settings['inline-css']) && self::$settings['inline-css'] == '1') {
//                // TODO: Maybe add something?
//                if ($criticalActive && !empty($criticalCSSExists)) {
//                    //critical exists, dont inline
//                } else {
//                    $html = $combine_css->doInline($html);
//                }
//            }
        }

        $addslashes = false;
        if (!empty($_POST['action'])) {
            $addslashes = true;
        }


        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'combine_css') {
            return $html;
        }

        if (isset(self::$settings['fontawesome-lazy']) && self::$settings['fontawesome-lazy'] == '1') {
            // TODO: Maybe add something?
            $html = $combine_css->lazyFontawesome($html);
        }

        if (isset(self::$settings['gtag-lazy']) && self::$settings['gtag-lazy'] == '1') {
            // TODO: Maybe add something?
            //$html = preg_replace_callback('/<script\b[^>]*(src="[^"]*gtag[^"]*")[^>]*>.*?<\/script>/si', [$this, 'gtagDelay'], $html);
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
                    #global $post;
                    $criticalCSS = new wps_criticalCss();
                    $criticalCSSExists = $criticalCSS->criticalExists();
                    $criticalCSSContent = file_get_contents($criticalCSSExists['file']);

                    // Adjusted function to create preload links only if the "/* Preload Fonts */" comment is found
                    $createPreloadLinks = function ($cssContent) {
                        $preloadLinks = '';
                        $loadedFonts = []; // Array to track already added URLs
                        $commentPos = strpos($cssContent, '/* Preload Fonts */');

                        // Proceed only if the comment is found
                        if ($commentPos !== false) {
                            $relevantContent = substr($cssContent, 0, $commentPos);
                            $fontPattern = '/url\((\'|")?(.+?\.(woff2?|ttf|otf|eot))\1?\)/i';
                            if (preg_match_all($fontPattern, $relevantContent, $matches, PREG_SET_ORDER)) {
                                foreach ($matches as $match) {
                                    $fontUrl = $match[2];
                                    if (strpos($fontUrl, 'icon') !== false || strpos($fontUrl, 'fa-') !== false || strpos($fontUrl, 'la-') !== false) {
                                        continue;
                                    }
                                    // Check if the font URL is already in the array
                                    if ((!empty(self::$settings['preload-crit-fonts'])) && self::$settings['preload-crit-fonts'] == '1') {
                                        if (!in_array($fontUrl, $loadedFonts)) {
                                            $preloadLinks .= "<link rel=\"preload\" href=\"$fontUrl\" as=\"font\" type=\"font/woff2\" crossorigin=\"anonymous\">\n";
                                            $loadedFonts[] = $fontUrl; // Add the URL to the tracking array
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

                        #global $post;
                        $criticalCSS = new wps_criticalCss();
                        $criticalCSSExists = $criticalCSS->criticalExists();

                        if (!empty($criticalCSSExists)) {
                            $html = self::$rewriteLogic->addCritical($html);
                            if (strpos($html, 'wpc-critical-css') !== false) {
                                $html = self::$rewriteLogic->lazyCSS($html);
                            }
                        } else {
                            //this way should be ok for multisite
                        }
                    }
                }
            }
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'cdn_rewrite_url_2') {
            return $html;
        }

        // encode meta and json tags so we dont replace urls
        if (empty(self::$settings['optimize_meta_images']) || self::$settings['optimize_meta_images'] == '0') {
            $metaData = $this->encodeMeta($html);
            $html = $metaData['html'];
        }

        // Protect negotiated <img data-wpc-nd> from the page-wide cdn_rewrite_url passes below.
        // Their src/srcset are already final zone-natural URLs, but the onerror fallback
        // (data-wpc-fb) is an ORIGIN url that cdn_rewrite_url would rewrite into a transform —
        // defeating the fallback's whole point (a non-edge origin file for when the edge fails).
        // Stash now, restore after the URL passes.
        $wpcnd_stash = [];
        if (class_exists('WPC_Negotiated_Delivery')
            && (WPC_Negotiated_Delivery::is_active() || WPC_Negotiated_Delivery::is_active_jpeg())) {
            $html = preg_replace_callback('/<img\b[^>]*\bdata-wpc-nd\b[^>]*>/i', function ($m) use (&$wpcnd_stash) {
                $k = '___WPCND_IMG_' . count($wpcnd_stash) . '___';
                $wpcnd_stash[$k] = $m[0];
                return $k;
            }, $html);
        }


        // Find all URLs on page that have not been replaced
        $regEx = '#(?<=url\(|[\"\']|&quot;)(?:' . self::$regExURL . ')?/(?:((?:' . self::$regExDir . ')[^\"\')]+)|([^/\"\']+\.[^/\"\')]+))(?=[\"\')]|&quot;)#';
        $html = preg_replace_callback($regEx, [$this, 'cdn_rewrite_url'], $html);

        //Find background images inlined in html, and pass only the url to cdn_rewrite_url (above regex does not capture relative urls)
        if (!empty(self::$settings['background-sizing']) && self::$settings['background-sizing'] == 1) {
            $regEx = '/background-image:\s*url\((\'|"|&quot;)(.*?)(\'|"|&quot;)\)/i';
            $html = preg_replace_callback($regEx, function ($matches) {
                $url = str_replace('&#039;', '', $matches[2]);

                return 'background-image: url(' . $this->cdn_rewrite_url([$url]) . ')';
            }, $html);
        }

        // process base64 encoded chunks and put images on cdn
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

        // Restore the stashed negotiated imgs (their data-wpc-fb origin fallback intact).
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
            // Find and Preload Fonts!!
            self::$wpcPreloadLinks = $combine_css->preparePreloads($html);

            if (!empty(self::$wpcPreloadLinks)) {
                // Extract href values from preload links
                preg_match_all('/href=["\']([^"\']+)["\']/', self::$wpcPreloadLinks, $matches);

                $html = str_replace('<!--WPC_INSERT_PRELOAD-->', self::$wpcPreloadLinks, $html);
            }
        }

        // decode meta and json tags
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

        # $html = preg_replace_callback('/\[noscript\-wpc\](.*?)\[\/noscript\-wpc\]/si', [$this, 'noscript_decode'], $html);
        #return print_r([$html],true);
        #$html = preg_replace_callback('/\[noscript\-wpc\](.*?)\[\/noscript\-wpc\]/i', [$this, 'noscript_decode'], $html);

        $html = preg_replace_callback('/\[noscript-wpc\](.*?)\[\/noscript-wpc\]/is', [$this, 'noscript_decode'], $html);

        #return print_r([$html],true);

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'Inline') {
            return $html;
        }


        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'combine_js') {
            return $html;
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'delay_js') {
            return $html;
        }

        //Delay JS
        #$delayActive = !(isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '0') && ((isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '1'));
        $delayActive = true;

        if (isset(self::$page_excludes['delay_js']) && self::$page_excludes['delay_js'] == '0') {
            // Disable
            $delayActive = false;
        }

        $delayV2Active = true;
        if (isset(self::$page_excludes['delay_js_v2']) && self::$page_excludes['delay_js_v2'] == '0') {
            // Disable
            $delayV2Active = false;
        }

        $html = self::$themeIntegrations->getIntegration($html);

        if ((isset(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1')) {
            if (!self::$isAmp->isAmp() && empty($_GET['disableDelay']) && empty($_GET['criticalCombine']) && empty(wpcGetHeader('criticalCombine'))) {
                $js_delay = new wps_ic_js_delay_v2();

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
                //$html = preg_replace_callback('/<script\s+src="([^"]+)"[^>]*>/si', [$this, 'gtagDelay'], $html);

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

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'cache_settings') {
            return print_r(['settings' => self::$settings, 'advanced' => self::$settings['cache']['advanced'], 'html' => self::$settings['cache']['html'], 'mobile' => self::$settings['cache']['mobile'], 'is_mobile' => self::is_mobile(), 'url_excluded_simple' => self::isURLExcluded('simple_caching'), 'url_excluded_advanced' => self::isURLExcluded('cache'), 'exclude_per_page' => isset(self::$page_excludes['advanced_cache']) ? self::$page_excludes['advanced_cache'] : ''], true);
        }

        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'cache_advanced') {
            return $html;
        }


        // Cache
        $cacheActive = !(isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '0') && ((isset(self::$settings['cache']['advanced']) && self::$settings['cache']['advanced'] == '1') || (isset(self::$page_excludes['advanced_cache']) && self::$page_excludes['advanced_cache'] == '1'));


        if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'cache_mobile') {
            return $html;
        }

        //clean up all our placeholder comments if not used
        $html = preg_replace('/<!--WPC[\s\S]*?-->/', '', $html);


        #if (!empty($_GET['replaceFonts'])) {
        #return print_r(self::$settings['replace-fonts'],true);
        if (!empty(self::$settings['replace-fonts'])) {
            if (self::$settings['replace-fonts'] == 'local') {
                $fonts = new wps_ic_fonts();
                $html = $fonts->replaceFrontend($html);
            } else if (self::$settings['replace-fonts'] == 'bunny') {
                // Bunny Fonts is a GDPR-compliant drop-in replacement for Google Fonts
                $html = str_replace('fonts.googleapis.com', 'fonts.bunny.net', $html);
                // Bunny serves font files at different paths than gstatic — a bare domain swap of
                // font-file URLs (fonts.gstatic.com/s/...) produces 404s. Remove those <link> tags
                // first, then swap the bare hostname on any remaining preconnect-only references.
                $html = preg_replace('/<link\b[^>]*\bhref=["\']https?:\/\/fonts\.gstatic\.com\/[^"\']+["\'][^>]*>\s*/i', '', $html);
                $html = str_replace('fonts.gstatic.com', 'fonts.bunny.net', $html);
            }
        }
        #}

        // 7.01.0 — Modern Image Delivery post-process (BETA <picture> builder). Stands down when
        // negotiated delivery is active for this request — they are mutually exclusive delivery
        // strategies; negotiated (clean <img> + edge Accept-negotiation) wins and the <picture>
        // builder must not re-wrap the negotiated tags back into <picture>.
        if (class_exists('WPC_Modern_Delivery') && WPC_Modern_Delivery::is_active()
            && !(class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active())) {
            $html = WPC_Modern_Delivery::rewrite_buffer($html);
        }

        // Natural-URL assets: convert css/js/font m:/font: passthrough transform URLs
        // to clean natural URLs on the cname (CDN-served + cached, no redirect hop). Image
        // transforms untouched. Gated + inert by default (negotiated GA + filter).
        if (class_exists('wps_rewriteLogic') && wps_rewriteLogic::natural_assets_on()) {
            $html = wps_rewriteLogic::naturalize_asset_urls($html);
            // onerror->origin self-heal on the now-natural css/js (transport-failure floor:
            // DNS/connection/TLS/5xx/abort, which <link>/<script> onerror DOES fire on; wrong-MIME-200
            // is covered by the origin floor, not here). Inert on the origin floor.
            $fb = self::add_asset_failover($html);
            if (is_string($fb) && $fb !== '') $html = $fb;
        }

        return $html;
    }

    /**
     * Regime-C onerror->origin self-heal. Decorates ONLY proven-natural css/js zone tags
     * (no /a: transform marker) with a one-shot onerror that reverts the failed asset to the customer
     * ORIGIN URL (different host → bypasses the CDN edge + any CF rule; the page already works from
     * origin so it is always-valid). Catches the TRANSPORT-FAILURE class only (DNS/connection/TLS/5xx/
     * abort) — the one class <link>/<script> onerror fires on; a wrong-MIME-200 does NOT fire onerror
     * and is structurally covered by the origin floor (we never emit an unproven CDN css/js URL).
     * One-shot + idempotent via data-wpc-fb (mirrors the image onerror pattern). Self-gated on
     * natural_assets_on() so it is inert under KILL/CDN-off/unproven and on the origin floor.
     */
    public static function add_asset_failover($html)
    {
        if (!is_string($html) || $html === '' || empty(self::$zone_name)) return $html;
        if (!class_exists('wps_rewriteLogic') || !wps_rewriteLogic::natural_assets_on()) return $html;
        $zoneHost = preg_replace('#/.*$#', '', (string) self::$zone_name);
        $origin   = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
        if ($origin === '' || strcasecmp((string) $zoneHost, (string) $origin) === 0) return $html; // never-loop
        $zq = preg_quote((string) $zoneHost, '#');
        // \s (whitespace) before href=/src=, NOT \b: a \b word boundary also matches
        // data-href=/data-src= (hyphen is a boundary), which would wrongly decorate third-party
        // lazy-deferral tags. \s requires a real attribute.
        $css = preg_replace_callback('#<link\b(?=[^>]*\srel=["\']?stylesheet)(?![^>]*\sdata-wpc-fb)[^>]*\shref=["\']https://' . $zq . '(/[^"\']+?\.css[^"\']*)["\'][^>]*>#i', function ($m) use ($origin) {
            if (strpos($m[0], '/a:') !== false) return $m[0]; // transform form (not natural) — skip
            $o = 'https://' . $origin . $m[1];
            return str_replace('<link', '<link data-wpc-fb="0" onerror="if(!this.dataset.wpcFb||this.dataset.wpcFb===\'0\'){this.dataset.wpcFb=1;this.href=\'' . esc_attr($o) . '\';}"', $m[0]);
        }, $html);
        if (is_string($css) && $css !== '') $html = $css; // preg failure → keep prior $html (never blank)
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

            //Prep Included Directories
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
            // If it does, remove the first fontawesome.css link
            $html = preg_replace('#<link[^>]+href=["\'][^"\']*fontawesome\.css[^"\']*["\'][^>]*>\s*#i', '', $html, 1);
        }

        return $html;
    }

    /**
     * Cleans up script templates from HTML, adds IDs
     *
     * @param string $html The original HTML content
     * @return array Associative array containing modified HTML and saved templates
     */
    function removeTemplates($html)
    {
        $templates = [];
        $templateIdPrefix = 'template_';
        $templateCounter = 0;

        // First, find all script tags with their content
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $html, $matches, PREG_SET_ORDER);

        // Process each script tag
        foreach ($matches as $match) {
            $fullTag = $match[0];
            $content = $match[1];

            // Check if this is a template script
            if (preg_match('/type\s*=\s*["\']text\/template["\']/i', $fullTag)) {
                // Generate a unique ID
                $templateId = $templateIdPrefix . $templateCounter++;

                // Save the content
                $templates[$templateId] = $content;

                // Check if there's already an id attribute
                if (preg_match('/\swpc_id\s*=\s*["\'][^"\']*["\']/i', $fullTag)) {
                    // Replace existing id
                    $newTag = preg_replace('/(\swpc_id\s*=\s*["\'])[^"\']*(["\'])/i', '$1' . $templateId . '$2', $fullTag);
                } else {
                    // Add id attribute before the closing >
                    $newTag = preg_replace('/(<script\b[^>]*)>/i', '$1 wpc_id="' . $templateId . '">', $fullTag);
                }

                // Remove the content
                $newTag = preg_replace('/(<script\b[^>]*>).*(<\/script>)/is', '$1$2', $newTag);

                // Replace in the original HTML
                $html = str_replace($fullTag, $newTag, $html);
            }
        }

        return ['html' => $html, 'templates' => $templates];
    }

    /**
     * Encode meta tags to protect them from URL rewriting
     * @param string $html
     * @return array ['html' => modified_html, 'store' => meta_tags_store]
     */
    public function encodeMeta($html)
    {
        $metaTagsStore = [];
        $metaCounter = 0;

        // Find and encode all meta tags with image content
        $html = preg_replace_callback('#<meta\s+(?:property=["\'](?:og:image|twitter:image)["\']|name=["\']twitter:image["\'])[^>]*>#i', function ($matches) use (&$metaTagsStore, &$metaCounter) {
            $placeholder = '<!--META_PLACEHOLDER_' . $metaCounter . '-->';
            $metaTagsStore[$metaCounter] = $matches[0];
            $metaCounter++;
            return $placeholder;
        }, $html);

        // Also handle JSON-LD scripts
        $html = preg_replace_callback('#<script\s+type=["\']application/ld\+json["\'][^>]*>.*?</script>#si', function ($matches) use (&$metaTagsStore, &$metaCounter) {
            $placeholder = '<!--JSONLD_PLACEHOLDER_' . $metaCounter . '-->';
            $metaTagsStore[$metaCounter] = $matches[0];
            $metaCounter++;
            return $placeholder;
        }, $html);

        return ['html' => $html, 'store' => $metaTagsStore];
    }

    // CF-DIRECT zone = the emit host IS the customer's configured Cloudflare CNAME (not a Bunny
    // zone). A CF-direct edge is a plain cache: no OTF resize, no Accept negotiation. So a .webp
    // source is delivered as its clean natural URL (the source file itself, never 404) instead of a
    // /q:i/wp:N/ transform. Distinct from zone_is_cf(), which trips on the CF-RAY header and
    // false-positives a CF-fronted Bunny origin.
    public static function wpc_zone_is_cf_direct()
    {
        if (!function_exists('get_option') || !defined('WPS_IC_CF_CNAME')) {
            return false;
        }
        $cname = trim((string) get_option(WPS_IC_CF_CNAME, ''));
        return ($cname !== '' && stripos((string) self::$zone_name, $cname) !== false);
    }

    // WEBP-ORIGIN NATURAL PASS-THROUGH for the dimensionless paths (plain <img>/srcset + CSS
    // backgrounds) that carry no per-image W×H. A .webp SOURCE's natural zone URL is the source file
    // itself → never-404, no /q: transform, CF-cacheable static (a Bunny edge negotiates it up to
    // avif). It's full-size (the edge OTF-resizes only by -WxH suffix, and these tags have no dims),
    // a clean-URL-over-resize choice → opt-in, default OFF. Raster keeps /q: (full original too large).
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

        // GIF never rides the Bunny zone — it gains nothing from a /q:i/wp:N/ transform, so serving it
        // off the zone is pure WPC egress. This is the central img-src + srcset transform chokepoint.
        // Allowed only on a true CF-direct zone (customer's own bandwidth) via cf_is_delivery() — NOT
        // zone_is_cf(), which trips on the CF-RAY header even when delivery is actually Bunny.
        if (preg_match('/\.gif(\?|#|\s|$)/i', $url)
            && !(class_exists('wps_rewriteLogic') && wps_rewriteLogic::cf_is_delivery())) {
            return $this->maybe_slash($url, $addslashes);
        }

        $siteUrl = self::$home_url;
        $newUrl = str_replace($siteUrl, '', $url);

        // Check if site url is staging url? Anything after .com/something?
        preg_match('/(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9][a-z0-9-]{0,61}[a-z0-9]\/([a-zA-Z0-9]+)/', $siteUrl, $isStaging);

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'isstaging') {
            return print_r([$isStaging, $siteUrl], true);
        }

        // TODO: This is required for STAGING TO WORK!!! Don't remove SiteURL!!! LOOK for next TODO!!!

        $originalUrl = $url;
        $newSrcSet = '';


        // TODO: Hrvoje fix for sites having bad srcset like https... 525w, https... without XYw
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
                    // Images-master gate: Images tile OFF ⇒ leave srcset entries at origin (no
                    // /q:i/wp:N/ transform). Mirrors the single-URL serve gates.
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

                    if ($srcset_width == '1') {
                        $srcsetWidthExtension = '';
                    } else {
                        $srcsetWidthExtension = $srcset_width . $extension;
                    }

                    // webp-origin natural pass-through: a webp srcset entry becomes its clean natural
                    // URL (-WxH stripped to the full source). See the single-src .webp branch below.
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

            // External is disabled?
            if (empty(self::$externalUrlEnabled) || self::$externalUrlEnabled == '0') {
                if (!self::image_url_matching_site_url($url)) {
                    return $this->maybe_slash($url, $addslashes);
                }
            } else {
                // Check if the URL is an image, then check if it's instagram etc...
                if (strpos($url, '.jpg') !== false || strpos($url, '.png') !== false || strpos($url, '.gif') !== false || strpos($url, '.svg') !== false || strpos($url, '.jpeg') !== false) {
                    foreach (self::$default_excluded_list as $i => $excluded_string) {
                        if (strpos($url, $excluded_string) !== false) {
                            return $this->maybe_slash($url, $addslashes);
                        }
                    }
                }
            }

            if (!empty($url)) {
                // Todo: Quick fix for Password Protected Pages
                if (strpos($url, 'login') !== false) {
                    return $this->maybe_slash($url, $addslashes);
                }

                if (strpos($url, '.css') !== false && self::$css == '1') {
                    // Local-Fonts cache stylesheet (wp-cio-fonts/{hash}.css): keep on natural origin so its
                    // @font-face URLs match the inline/preload set. Latent today (replaceFrontend injects it
                    // after this sweep) but guarded so a future reorder can't split font URLs across hosts.
                    if (stripos($url, '/cache/wp-cio-fonts/') !== false) {
                        return $this->maybe_slash($url, $addslashes);
                    }
                    $fileMinify = self::$css_minify;

                    if (self::isExcluded('css_minify', $url)) {
                        $fileMinify = '0';
                    }


                    if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') {
                        $fileMinify = '1';
                    }
                    /**
                     * CSS File
                     */
                    $newUrl = 'https://' . self::$zone_name . '/m:' . $fileMinify . '/a:' . self::reformat_url($url);

                    return $newUrl;
                } elseif (preg_match('/\.js(?:[?#]|$)/i', $url) && self::$js == '1') {
                    $fileMinify = self::$js_minify;
                    if (self::isExcluded('js_minify', $url)) {
                        $fileMinify = '0';
                    }

                    /**
                     * JS File
                     */
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
                        /**
                         * SVG File
                         */
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
                    /**
                     * Font file
                     */
                    // Local-Fonts cache (wp-cio-fonts): NEVER zoneify — keep the natural origin URL. The crit
                    // inline @font-face + preload are injected by addCritical AFTER rewrite_fontface_css runs,
                    // so THIS generic sweep is the only pass that can keep them natural. They must match the
                    // deferred localized .css (origin) so each font is declared at one URL → fetched once,
                    // preloaded once, no double-load, no FOUT (crit-on === crit-off).
                    if (stripos($url, '/cache/wp-cio-fonts/') !== false) {
                        return $this->maybe_slash($url, $addslashes);
                    }
                    // PATH SCOPE: leave same-site custom-path (e.g. /storage/) fonts on origin — the
                    // CDN 302s them cross-origin → CORS-blocked. This branch is already same-site-gated,
                    // so only the /wp-content/ check is needed (latent path, guarded anyway).
                    if (stripos((string) wp_parse_url($url, PHP_URL_PATH), '/wp-content/') === false) {
                        return $this->maybe_slash($url, $addslashes);
                    }
                    // HOST GUARD (v7.03.47) — never build a transform on an empty/origin zone host: an empty
                    // zone yields "https:///…" which resolves against the ORIGIN → 404 (also the suppressed-zone
                    // state). Stay on the natural origin URL when the zone host isn't safely available.
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

                // Skip CDN MC for locally-optimized images — they're served via <picture> tags instead
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
                    // Images-master gate: Images tile OFF ⇒ serve the origin .webp, never a
                    // /q:i/wp:N/ transform.
                    if (class_exists('WPC_Negotiated_Delivery') && !WPC_Negotiated_Delivery::cdn_images_enabled(self::$settings)) {
                        return self::reformat_url($url, false);
                    }
                    if (!self::is_excluded($url, $url)) {
                        // webp-ORIGIN natural pass-through (dimensionless path): the source file itself
                        // → never-404, clean, CF-cacheable (Bunny negotiates avif). Strip any -WxH to the
                        // always-present full original (a sub-size may not exist on offloaded /storage).
                        // Opt-in (default OFF). Raster (jpg/png branch above) keeps /q: — too big natural.
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

                if (!empty($_GET['dbg']) && $_GET['dbg'] == 'rewrite_url_to_file') {
                    $fp = fopen(WPS_IC_LOG . 'rewrite_url_file.txt', 'a+');
                    fwrite($fp, 'URL: ' . $url . "\r\n");
                    fwrite($fp, 'URL: ' . $newUrl . "\r\n");
                    fwrite($fp, '---' . "\r\n");
                    fclose($fp);
                }

                // TODO: This is required for STAGING TO WORK!!! Don't remove SiteURL!!! LOOK for next TODO!!!
                if (self::$is_multisite) {
                    return $this->maybe_slash($newUrl, $addslashes);
                } elseif (empty($isStaging) || empty($isStaging[0])) {
                    // Not a staging site
                    return $this->maybe_slash($newUrl, $addslashes);
                } else {
                    // It's a staging site
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

        preg_match("/([0-9]+)x([0-9]+)\.[a-zA-Z0-9]+/", $basename_original, $matches); //the filename suffix way
        if (empty($matches)) {
            // Full Image
            $basename = $basename_original;
        } else {
            // Some thumbnail
            $basename = str_replace('-' . $matches[1] . 'x' . $matches[2], '', $basename_original);
        }

        /**
         * Is this image lazy excluded?
         */
        if (!empty(self::$lazy_excluded_list) && !empty(self::$lazy_enabled) && self::$lazy_enabled == '1') {
            //return 'asd';
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

    /**
     * Decode meta tags back to their original form
     * @param string $html
     * @param array $metaTagsStore
     * @return string
     */
    public function decodeMeta($html, $metaTagsStore)
    {
        if (empty($metaTagsStore)) {
            return $html;
        }

        foreach ($metaTagsStore as $index => $originalTag) {
            $metaPlaceholder = '<!--META_PLACEHOLDER_' . $index . '-->';
            $jsonldPlaceholder = '<!--JSONLD_PLACEHOLDER_' . $index . '-->';

            // Try meta placeholder first, then JSON-LD placeholder
            if (strpos($html, $metaPlaceholder) !== false) {
                $html = str_replace($metaPlaceholder, $originalTag, $html);
            } elseif (strpos($html, $jsonldPlaceholder) !== false) {
                $html = str_replace($jsonldPlaceholder, $originalTag, $html);
            }
        }

        return $html;
    }

    /**
     * Restores script template content by ID from the saved templates array
     *
     * @param string $html The HTML with empty script templates
     * @param array $templates The array of saved template content indexed by ID
     * @return string The HTML with restored script template content
     */
    function restoreTemplates($html, $templates)
    {
        // Find all script tags
        preg_match_all('/<script\b[^>]*><\/script>/is', $html, $matches, PREG_SET_ORDER);

        // Process each empty script tag
        foreach ($matches as $match) {
            $fullTag = $match[0];

            // Check if this is a template script with an id
            if (preg_match('/type\s*=\s*["\']text\/template["\']/i', $fullTag) && preg_match('/wpc_id\s*=\s*["\']([^"\']+)["\']/i', $fullTag, $idMatch)) {

                $templateId = $idMatch[1];

                // Check if we have content for this ID
                if (isset($templates[$templateId])) {
                    // Restore the content
                    $newTag = str_replace('></script>', '>' . $templates[$templateId] . '</script>', $fullTag);

                    // Replace in the HTML
                    $html = str_replace($fullTag, $newTag, $html);
                }
            }
        }

        return $html;
    }

    public function set_image_sizes($matches)
    {

        // Skip images that have wpc-size="preserve"
        if (preg_match('/wpc-size=(["\'])preserve\1/', $matches[0])) {
            return $matches[0];
        }

        //Don't change existing size attributes
        if (preg_match('/\s(width|height)\s*=\s*["\']?\d+/i', $matches[0])) {
            return $matches[0];
        }

        if (empty(self::$settings['add-image-sizes']) || self::$settings['add-image-sizes'] == '0') {
            return $matches[0];
        }

        // Check if the image is within a <picture> tag
        if (strpos($matches[0], '<picture>') !== false) {
            // Extract the <img> tag src from the <picture>
            preg_match('/<img[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/si', $matches[0], $imgMatches);
            if (!$imgMatches) {
                return $matches[0]; // No <img> tag found within <picture>, return original
            }
            $imageUrl = $imgMatches[1];
        } else {
            // Direct <img> tag
            $imageUrl = $matches[1];
        }

        // Convert URL to local path for local images, or keep as URL for external images
        $localPath = $this->url_to_path($imageUrl);

        if (!$localPath) {
            // If the image is external and external image handling is disabled, return the tag unchanged
            return $matches[0];
        }

        // Get image dimensions
        $dimensions = $this->get_image_dimensions($localPath);
        if ($dimensions === false) {
            // Couldn't get dimensions, return the tag unchanged
            return $matches[0];
        }

        // Construct the width and height string
        $widthHeightStr = 'width="' . round($dimensions[0], 0) . '" height="' . round($dimensions[1], 0) . '"';

        if ($dimensions[0] <= 5 || $dimensions[1] <= 5) {
            $widthHeightStr = '';
        }

        // Insert width and height into the <img> tag
        if (isset($imgMatches)) {
            // For <picture>, reconstruct the <img> tag with dimensions added
            $newImgTag = preg_replace('/<img([^>]+)>/', '<img$1 ' . $widthHeightStr . '>', $imgMatches[0]);

            // Replace the old <img> tag with the new one within <picture>
            return str_replace($imgMatches[0], $newImgTag, $matches[0]);
        } else {
            // For direct <img> tags, add dimensions directly
            return preg_replace('/<img/', '<img ' . $widthHeightStr, $matches[0]);
        }
    }

    public function url_to_path($url)
    {
        $parsedUrl = parse_url($url);
        $siteUrl = parse_url(get_site_url());

        // Check if URL is external
        if (!isset($parsedUrl['host']) || !isset($siteUrl['host']) || $parsedUrl['host'] !== $siteUrl['host']) {
            return false; // URL is external, can't convert to local path
        }

        // Construct the path relative to WordPress root
        $relPath = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';

        // Get WordPress base directory path
        $wpBasePath = ABSPATH;

        // Sometimes, WordPress is installed in a subdirectory, adjust for that
        if (!empty($siteUrl['path']) && $siteUrl['path'] !== '/') {
            $wpBasePath = str_replace(trim($siteUrl['path'], '/'), '', $wpBasePath);
        }

        // Combine the base path with the relative path
        $localPath = realpath($wpBasePath . $relPath);

        // Check if the file exists and return the path, or false if it doesn't
        return file_exists($localPath) ? $localPath : false;
    }

    public function get_image_dimensions($filename)
    {
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'svg') {
            // Handle SVG files
            $svgfile = @simplexml_load_file(rawurlencode($filename), 'SimpleXMLElement', LIBXML_NOERROR | LIBXML_NOWARNING);
            if ($svgfile) {
                $attributes = $svgfile->attributes();
                $width = isset($attributes->width) ? (string)$attributes->width : null;
                $height = isset($attributes->height) ? (string)$attributes->height : null;

                // Clean and format width and height.
                $width = $this->format_svg_value($width);
                $height = $this->format_svg_value($height);

                if ($width && $height) {
                    // Return dimensions if directly available
                    return [$width, $height];
                } elseif (isset($attributes->viewBox)) {
                    // Parse viewBox for dimensions if width/height not available
                    $viewBox = explode(' ', $attributes->viewBox);
                    if (count($viewBox) === 4) {
                        $width = $viewBox[2];
                        $height = $viewBox[3];
                        return [$width, $height];
                    }
                }
            }
            // Return false if dimensions could not be determined
            return false;
        } else {
            // Handle other image types (JPG, PNG, etc.)
            $sizes = @getimagesize($filename);
            return $sizes ? [$sizes[0], $sizes[1]] : false;
        }
    }

    public function format_svg_value($value)
    {
        // No unit or empty, return the value directly.
        if (empty($value) || is_numeric($value)) {
            return $value;
        }

        // Pattern to find numbers possibly followed by 'px'
        $px_pattern = '/([0-9]+)\s*px/i';

        // If pixel unit or numeric, extract and return the numeric value.
        if (preg_match($px_pattern, $value, $matches)) {
            return $matches[1];
        }

        // Return an empty string for unsupported units.
        return '';
    }

    public function injectPreloadImages($matches)
    {
        $originalHead = $matches[0];

        $inject = $originalHead;
        $inject .= '<!--WPC_INSERT_CRITICAL-->';
        $inject .= '<!--WPC_INSERT_PRELOAD_MAIN-->';
        $inject .= '<!--WPC_INSERT_PRELOAD-->';

        // Picture tag CSS safety net — makes <picture> transparent to CSS layout
        if (self::$rewriteLogic::$pictureWebpEnabled) {
            $inject .= '<style id="wpc-picture-css">picture.wpc-picture{display:contents}</style>';
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

    var isAutomation = false;
    try { isAutomation = (navigator.webdriver === true); } catch (e) {}

    var isKnownBot =
      ua.includes("petalbot") ||
      ua.includes("sogou") ||
      ua.includes("baiduspider") ||
      ua.includes("yandexbot");

    if (!(forceBot || isAutomation || isKnownBot)) return;

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
            #$matches[0] = str_replace('elementor-invisible', '', $matches[0]);
            #$matches[0] = preg_replace('/(<div[^>]*\sclass="[^"]*)(")/si', "$1 " . "animated fadeInLeft" . " $2", $matches[0]);
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
        // TODO: We have already delayed things, but speed tests don't recognize it
        $tag = trim($src[0]);
        $srcToLower = strtolower($tag);

        //This is now done in delayJS class
        return $tag;

        if (self::$isAmp->isAmp()) {
            return $tag;
        }

        if (strpos($tag, 'wps-inline') !== false) {
            return $tag;
        }

        // Optimizer Exclude
        if (strpos($srcToLower, 'optimizer.pixel') !== false || strpos($srcToLower, 'optimizer.adaptive') !== false || strpos($srcToLower, 'optimizer.local') !== false) {
            return $tag;
        }

        if (strpos($srcToLower, 'googletag') !== false || strpos($srcToLower, 'gtag') !== false || strpos($srcToLower, 'facebook') !== false || strpos($srcToLower, 'recaptcha') !== false || strpos($srcToLower, 'tween') !== false || strpos($srcToLower, 'fontawesome') !== false) {

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
        $found = strlen($html[0]);

        $encoded = base64_encode($html[0]);
        $decode = base64_decode($encoded);
        $replaced = strlen($decode);

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'script') {
            return print_r([$html], true);
        }

        $slashed = addslashes($html[0]);
        $encoded = base64_encode($slashed);

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'bas64_encode') {
            return print_r($encoded, true);
        }

        return '[script-wpc]' . $encoded . '[/script-wpc]';
    }

    public function local_script_decode($html)
    {
        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'bas64_decode') {
            return print_r([$html], true);
        }

        $decode = str_replace('[script-wpc]', '', $html[0]);
        $decode = str_replace('[/script-wpc]', '', $decode);

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'bas64_decode_end') {
            return print_r([$decode], true);
        }

        $decode = base64_decode($decode);
        $decode = stripslashes($decode);

        return $decode;
    }

    public function crittr_replace_css($links)
    {
        preg_match_all('/([a-zA-Z\-\_]*)\s*\=["|\'](.*?)["|\']/is', $links[0], $linkAtts);

        if (!empty($_GET['dbg_links'])) {
            return print_r([$links], true);
        }

        if (!empty($_GET['dbg_links_atts'])) {
            return print_r([$linkAtts], true);
        }

        if (!empty($linkAtts[1])) {
            $linkHtml = '<link';
            $linkRel = '';

            $attNames = $linkAtts[1];
            $attValues = $linkAtts[2];

            foreach ($attNames as $i => $attName) {
                if ($attName == 'rel' && $attValues[$i] == 'dns-prefetch') {
                    $linkRel = $attValues[$i];
                } elseif ($attName == 'href') {
                    if (!empty($_GET['dbg_link_href'])) {
                        return print_r([$attValues[$i], substr($attValues[$i], 0, 11)], true);
                    }

                    if (strpos($attValues[$i], self::$site_url) === false) {

                    } else {

                        if (strpos($attValues[$i], self::$zone_name) === false) {
                            $attValues[$i] = WPS_IC_URI . 'fixCss.php?zoneName=' . self::$zone_name . '&css=' . urlencode($attValues[$i]) . '&rand=' . time();
                        }

                    }
                }

                $linkHtml .= ' ' . $attName . '="' . $attValues[$i] . '"';
            }

            if (!empty($_GET['dbg_links_output'])) {
                return print_r([$linkHtml], true);
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
        //if any problems with escaping characters, see replace_iframe_tags() and implement the same
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

    public function replace_iframe_tags($iframe)
    {
        if (strpos($iframe[0], 'gform') !== false || strpos($iframe[0], 'data-src-cmplz') !== false) {
            return $iframe[0];
        }

        // (v7.03.47) Do NOT lazy a HIDDEN / off-screen / JS-MANAGED iframe. Many embeds ship the iframe
        // hidden + parked off-screen and reveal it with their own JS once it loads + posts its height
        // (GoHighLevel/LeadConnector, HubSpot, chat/booking widgets). Moving its src→data-wpc-src hands
        // loading to our IntersectionObserver, which never fires for an off-screen element → the iframe
        // never loads → the widget's reveal JS has nothing to show → invisible form. Leave these untouched.
        // Detect via the embed's own hidden flag, hidden/off-screen inline styles, or a known widget host.
        $wpc_if    = $iframe[0];
        $wpc_if_ns = str_replace(' ', '', strtolower($wpc_if)); // normalize so CSS values match regardless of spacing
        if (stripos($wpc_if, 'data-initial-iframe-hidden') !== false
            || strpos($wpc_if_ns, 'visibility:hidden') !== false
            || strpos($wpc_if_ns, 'opacity:0') !== false
            || strpos($wpc_if_ns, 'display:none') !== false
            || strpos($wpc_if_ns, 'left:-9999') !== false
            || strpos($wpc_if_ns, 'left:-99999') !== false
            || stripos($wpc_if, 'leadconnectorhq.com') !== false
            || stripos($wpc_if, 'msgsndr') !== false) {
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
                    // Probably already delayed with a placeholder in src
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
            return $iframe[0]; // Return original if no attributes found
        }
    }

    private function conditionallyEscapeUrl($url)
    {
        // Common patterns that indicate the URL is already encoded
        $encodedPatterns = ['&amp;',     // & encoded
            '&#038;',    // WordPress-style & encoding
            '%20',       // Space encoded
            '%2C',       // Comma encoded
            '&quot;',    // Quote encoded
            '&lt;',      // < encoded
            '&gt;'       // > encoded
        ];

        foreach ($encodedPatterns as $pattern) {
            if (strpos($url, $pattern) !== false) {
                return $url; // Already encoded
            }
        }

        // Check for any HTML entity pattern
        if (preg_match('/&[a-zA-Z0-9#]+;/', $url)) {
            return $url; // Already encoded
        }

        // Not encoded, apply escaping only if needed
        if (strpos($url, '&') !== false || strpos($url, '"') !== false || strpos($url, '<') !== false || strpos($url, '>') !== false) {
            return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        }

        return $url; // Safe as-is
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

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'local_start') {
            return print_r($image, true);
        }

        // File has already been replaced
        if ($this->defaultExcluded($image[0])) {
            return $image[0];
        }

        // File is not an image
        if (strpos($image[0], '.webp') === false && strpos($image[0], '.jpg') === false && strpos($image[0], '.jpeg') === false && strpos($image[0], '.png') === false && strpos($image[0], '.ico') === false && strpos($image[0], '.svg') === false && strpos($image[0], '.gif') === false) {
            return $image[0];
        }

        // File is excluded
        if (self::is_excluded($image[0])) {
            $image_source = $image[0];
            $image_source = preg_replace('/class=["|\'](.*?)["|\']/is', 'class="$1 wps-ic-loaded"', $image_source);

            return $image_source;
        }

        if ((self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0') && !self::image_url_matching_site_url($image[0])) {
            return $image[0];
        }

        // Count images that were lazy loaded
        self::$lazyLoadedImages++;

        // Original URL was
        $original_img_tag = [];
        $original_img_tag['original_tags'] = $this->getAllTags($image[0], []);

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'searchImage') {
            return print_r([$image[0], $original_img_tag['original_tags']], true);
        }

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'local_original_tags') {
            return print_r($original_img_tag['original_tags'], true);
        }

        if (!empty($original_img_tag['original_tags']['src']) && empty($original_img_tag['original_tags']['data-src'])) {
            $image_source = $original_img_tag['original_tags']['src'];
        } else {
            $image_source = $original_img_tag['original_tags']['data-src'];
        }

        $original_img_tag['original_src'] = $image_source;

        // Old Code Below

        // Figure out image class
        preg_match('/srcset=["|\']([^"]+)["|\']/', $image_tag, $image_srcset);
        if (!empty($image_srcset[1])) {
            $original_img_tag['srcset'] = $image_srcset[1];
        }

        $size = self::get_image_size($image_source);

        $svgAPI = $source_svg = 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="' . $size[0] . '" height="' . $size[1] . '"><path d="M2 2h' . $size[0] . 'v' . $size[1] . 'H2z" fill="#fff" opacity="0"/></svg>');

        // OriginalImageSource
        $original_img_src = $image_source;

        // Path to CSS File
        $site_url = str_replace(['https://', 'http://'], '', self::$site_url);
        $image_path = str_replace(['https://' . $site_url . '/', 'http://' . $site_url . '/'], '', $image_source);
        $image_path = explode('?', $image_path);
        $image_path = ABSPATH . $image_path[0];

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'local_settings') {
            $webP = str_replace(['.jpeg', '.jpg', '.png'], '.webp', $image_path);

            return print_r([self::$webp, $image_path, $webP, file_exists($webP)], true);
        }

        /**
         * Local File does not exists?
         */
        if (!file_exists($image_path)) {
            return $image[0];
        } else {
            // The bare webp src-swap must respect the Next-Gen ceiling: when Next-Gen=Off the
            // visitor gets plain jpg/png even if a .webp is on disk, else "Next-Gen Off" would
            // silently keep serving webp via <img src>. (The <picture> path is gated separately.)
            $wpc_ng_ceiling = class_exists('WPC_Delivery_Resolver')
                ? WPC_Delivery_Resolver::effective_ceiling(self::$settings) : 'avif';
            if ($wpc_ng_ceiling !== 'off' && (self::$webp == 'true' || self::$webp == '1')) {
                // Check if WebP Exists in PATH?
                $webP = str_replace(['.jpeg', '.jpg', '.png'], '.webp', $image_path);

                if (!file_exists($webP)) {
                    $webP = false;
                    $image_source = $original_img_src;
                } else {
                    $original_img_src = str_replace(['.jpeg', '.jpg', '.png'], '.webp', $original_img_src);
                    $image_source = $original_img_src;
                }
            } else {
                $image_source = $original_img_src;
            }
        }


        // Is LazyLoading enabled in the plugin?
        if (!empty(self::$lazy_enabled) && self::$lazy_enabled == '1' && !self::$lazy_override) {

            if (self::$lazyLoadedImages >= self::$lazyLoadSkipFirstImages) {
                $isLazy = true;

                // If Logo remove wps-ic-lazy-image
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

                // If Logo remove wps-ic-lazy-image
                if (strpos($image_source, 'logo') !== false) {
                    // Image is for logo
                    $class_Addon .= $lazyClass . ' wps-ic-logo';
                } else {
                    // Image is not for logo
                    $class_Addon .= $lazyClass . ' ';
                }

            } else {
                $image_tag = 'src="' . $image_source . '"';
            }

        } else if ((!empty(self::$native_lazy_enabled) && self::$native_lazy_enabled == '1' && !self::$lazy_override)) {
            $image_tag = 'src="' . $image_source . '"';

            if (self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages) {
                // Don't lazy load
            } else {
                // If Logo remove wps-ic-lazy-image
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
            if (self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages) {
                $image_tag .= ' fetchpriority="high" decoding="async"';
            }
        }


        /**
         * Srcset to WebP
         */
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
                            // Guard against malformed srcset entries missing the width descriptor
                            // (we don't control upstream srcset formatting, e.g. from theme/plugins)
                            $real_src_width = $src_w[1] ?? '';
                            if ($real_src_width === '') continue;

                            $image_path = str_replace(self::$site_url . '/', '', $real_src);
                            $image_path_webP = ABSPATH . $image_path;

                            $webP = str_replace(['.jpeg', '.jpg', '.png'], '.webp', $real_src);
                            $image_path_webP = str_replace(['.jpeg', '.jpg', '.png'], '.webp', $image_path_webP);

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

        // Wrap in <picture> for bulletproof WebP delivery (local mode)
        // Gate the WHOLE local (CDN-OFF) <picture> (webp + avif sources) on the Next-Gen ceiling.
        // The bare <img src> swap above was already gated, but this <picture> builder was NOT, so a
        // Next-Gen=OFF site still emitted <source type="image/webp"> and the browser picked WebP
        // over the jpg <img>. pictureWebpEnabled is only ceiling-gated in the CDN-buffer setup,
        // which doesn't run on the CDN-OFF render path, so it is stale-true here — hence this gate.
        $wpc_pic_ceiling = class_exists('WPC_Delivery_Resolver')
            ? WPC_Delivery_Resolver::effective_ceiling(self::$settings) : 'avif';
        if (self::$rewriteLogic::$pictureWebpEnabled && $webP !== false && $wpc_pic_ceiling !== 'off') {
            $lowerSrc = strtolower($original_img_tag['original_src']);
            $skipFormats = (strpos($lowerSrc, '.svg') !== false || strpos($lowerSrc, '.gif') !== false || strpos($lowerSrc, '.ico') !== false || strpos($lowerSrc, '.webp') !== false);

            if (!$skipFormats) {
                // Build fallback tag with original (non-webp) URLs
                // Replace srcset FIRST (before src), otherwise src replacement corrupts the srcset match
                $fallbackTag = $finalTag;
                if (!empty($srcset_att) && !empty($original_img_tag['srcset'])) {
                    // When lazy, attribute is data-srcset — match that
                    $srcsetAttrInTag = $isLazy ? 'data-srcset' : 'srcset';
                    $fallbackTag = str_replace($srcsetAttrInTag . '="' . $srcset_att . '"', $srcsetAttrInTag . '="' . $original_img_tag['srcset'] . '"', $fallbackTag);
                }
                $fallbackTag = str_replace($image_source, $original_img_tag['original_src'], $fallbackTag);

                // WebP source — use data-srcset when lazy loading to prevent immediate load
                $srcsetKey = $isLazy ? 'data-srcset' : 'srcset';
                $sourceSrcset = !empty($srcset_att) ? ' ' . $srcsetKey . '="' . $srcset_att . '"' : ' ' . $srcsetKey . '="' . $image_source . '"';
                $sourceSizes = '';
                if (preg_match('/sizes="([^"]*)"/', $finalTag, $szMatch)) {
                    $sourceSizes = ' sizes="' . $szMatch[1] . '"';
                }

                // AVIF source — LOCAL (CDN-off) path: emit ONLY when the .avif exists on disk. No
                // optimistic emission here: with CDN delivery OFF there is NO edge to intercept the
                // 404, and <picture> <source> selection is terminal — an AVIF-capable browser does
                // NOT fall back to the webp <source>, it renders a BROKEN image (and lazy_cdn can
                // never land the variant). Only offer AVIF the browser can actually load; the webp
                // <source> and the <img> jpeg are the safe fallbacks.
                $avifSource = '';
                if (self::$rewriteLogic::$pictureAvifEnabled) {
                    // Derive the .avif disk path from a STABLE absolute base (the original src), NOT
                    // the loop-mutated $image_path: the srcset loop above reassigns $image_path to a
                    // RELATIVE last-entry path, so a fixed `ABSPATH .` prepend DOUBLED ABSPATH for the
                    // no-srcset branch → file_exists() always false → AVIF <source> silently dropped.
                    $avifBaseUrl  = preg_replace('/\?.*$/', '', (string) $original_img_tag['original_src']);
                    $avifBaseRel  = preg_replace('#^(?:https?:)?//[^/]+#', '', $avifBaseUrl); // strip scheme+host if present
                    $avifMainPath = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', ABSPATH . ltrim($avifBaseRel, '/'));
                    if (@file_exists($avifMainPath)) {
                        // Build the AVIF srcset PER-ENTRY from the ORIGINAL (jpg) srcset, swapping each
                        // to .avif ONLY where the .avif sibling exists on disk — NOT str_replace(
                        // '.webp','.avif',$sourceSrcset), which leaves .jpg gap-fill entries (widths
                        // where .webp was missing) unswapped → malformed avif <source>.
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
                            // No srcset (single image) — emit just the main .avif URL.
                            $avifEntries[] = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', $avifBaseUrl);
                        }
                        $avifSource = '<source ' . $srcsetKey . '="' . implode(', ', $avifEntries) . '"' . $sourceSizes . ' type="image/avif">';
                    }
                }

                $finalTag = '<picture class="wpc-picture">' . $avifSource . '<source' . $sourceSrcset . $sourceSizes . ' type="image/webp">' . $fallbackTag . '</picture>';
            }
        }

        return $finalTag;
    }

    public function getAllTags($image, $ignore_tags = ['src', 'srcset', 'data-src', 'data-srcset'])
    {
        $found_tags = [];

        // This pattern accounts for HTML entities like &quot; within attribute values
        preg_match_all('/([a-zA-Z_-]+(?:--[a-zA-Z_-]+)*)(?:\s*=\s*(?:"((?:[^"\\\\]|\\\\.|&[a-zA-Z0-9#]+;)*)"|\'((?:[^\'\\\\]|\\\\.|&[a-zA-Z0-9#]+;)*)\'|([^>\s]+)))?/', $image, $matches, PREG_SET_ORDER);

        if (!empty($_GET['dbg_img1'])) {
            return [$image, $matches];
        }

        $attributes = [];
        unset($matches[0]);

        foreach ($matches as $match) {
            $attrName = $match[1];
            $attrValue = null;

            // Determine the attribute value based on the capturing group that caught it
            foreach ([2, 3, 4] as $index) {
                if (!empty($match[$index])) {
                    $attrValue = $match[$index];
                    break;
                }
            }

            // Only decode HTML entities for non-JSON attributes
            // Check if this looks like JSON data (starts with [ or { and contains &quot;)
            if ($attrValue !== null && (strpos($attrName, 'data-') === 0) && (strpos($attrValue, '[{') !== false || strpos($attrValue, '{') !== false) && strpos($attrValue, '&quot;') !== false) {
                // This looks like JSON data - keep HTML entities encoded
                // but clean up any potential corruption from the original regex
                $attributes[$attrName] = $attrValue;
            } else {
                // For regular attributes, decode HTML entities as before
                $attributes[$attrName] = $attrValue ? html_entity_decode($attrValue) : $attrValue;
            }
        }

        if (!empty($_GET['dbg_img2'])) {
            return [$image, $attributes];
        }

        // Process the attributes
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
        preg_match("/([0-9]+)x([0-9]+)\.[a-zA-Z0-9]+/", $url, $matches); //the filename suffix way
        if (isset($matches[1]) && isset($matches[2])) {
            return [$matches[1], $matches[2]];
            $sizes = [$matches[1], $matches[2]];
        } else { //the file
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