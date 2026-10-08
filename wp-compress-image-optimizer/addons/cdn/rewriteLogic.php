<?php

/**
 * Plugin: WP Compress – Instant Performance & Speed Optimization
 * Description: Legitimate script handling for WP Compress Optimizer
 */
class wps_rewriteLogic
{

    /**
     * Take every @font-face out of a piece of markup into the render's face set and hand the
     * markup back without them. A <style> block left with nothing but whitespace goes too — an
     * empty shell is a block whose id later passes would still read as a declaration.
     *
     * This is how a lane that assembles CSS as markup hands its faces over: one call at the end
     * of the assembly, instead of a registration at each of the emitters inside it.
     */
    public static function harvestFontFaces($markup, $origin, wps_ic_font_face_set $set, $eager = true)
    {
        $markup = (string) $markup;
        if ($markup === '' || stripos($markup, '@font-face') === false) {
            return $markup;
        }
        $out = preg_replace_callback('/(<style\b[^>]*>)(.*?)(<\/style>)/is', function ($m) use ($set, $origin, $eager) {
            if (stripos($m[2], '@font-face') === false) {
                return $m[0];
            }
            $faces = [];
            $rest = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($f) use (&$faces) {
                $faces[] = $f[0];
                return '';
            }, $m[2]);
            if (!is_string($rest) || empty($faces)) {
                return $m[0];
            }
            $set->add(implode('', $faces), $origin, $eager);

            return trim($rest) === '' ? '' : $m[1] . $rest . $m[3];
        }, $markup);

        return is_string($out) ? $out : $markup;
    }

    public static function wpc_att_map_file()
    {
        if (!function_exists('wp_get_upload_dir')) {
            return '';
        }
        $up = wp_get_upload_dir();
        if (empty($up['basedir'])) {
            return '';
        }
        return rtrim((string) $up['basedir'], '/') . '/wpc-att-map.json';
    }

    public static function wpc_att_map_save($ours)
    {
        $file = self::wpc_att_map_file();
        $ttl  = (int) apply_filters('wpc_att_map_ttl', 7 * 86400);
        $cap  = (int) apply_filters('wpc_att_map_cap', 4000);
        if ($file !== '') {
            $fp = @fopen($file, 'c+');
            if ($fp && @flock($fp, LOCK_EX)) {
                $raw = stream_get_contents($fp);
                $cur = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
                $stored = (is_array($cur) && isset($cur['m'], $cur['t']) && is_array($cur['m'])
                    && (int) $cur['t'] > time() - $ttl) ? $cur['m'] : [];
                $merged = array_merge($stored, $ours);
                if (count($merged) > $cap) {
                    $merged = array_slice($merged, -$cap, null, true);
                }
                $enc = json_encode(['t' => time(), 'm' => $merged]);
                if (is_string($enc)) {
                    ftruncate($fp, 0);
                    rewind($fp);
                    fwrite($fp, $enc);
                }
                flock($fp, LOCK_UN);
                fclose($fp);
                return true;
            }
            if ($fp) {
                fclose($fp);
            }
        }
        if (function_exists('set_transient') && function_exists('get_transient')) {
            $stored = get_transient('wpc_att_url_map');
            $merged = is_array($stored) ? array_merge($stored, $ours) : $ours;
            if (count($merged) > $cap) {
                $merged = array_slice($merged, -$cap, null, true);
            }
            set_transient('wpc_att_url_map', $merged, $ttl);
            return true;
        }
        return false;
    }

    public static function wpc_att_map_load()
    {
        $ttl  = (int) apply_filters('wpc_att_map_ttl', 7 * 86400);
        $file = self::wpc_att_map_file();
        if ($file !== '' && @is_readable($file)) {
            $cur = json_decode((string) @file_get_contents($file), true);
            if (is_array($cur) && isset($cur['m'], $cur['t']) && is_array($cur['m'])
                && (int) $cur['t'] > time() - $ttl) {
                return $cur['m'];
            }
        }
        $legacy = function_exists('get_transient') ? get_transient('wpc_att_url_map') : false;
        return is_array($legacy) ? $legacy : [];
    }

    public static function wpc_att_id($url)
    {
        static $map = null, $dirty = false, $hooked = false;
        $url = preg_replace('/\?.*$/', '', (string) $url);
        if ($url === '' || !function_exists('attachment_url_to_postid')) {
            return 0;
        }
        if ($map === null) {
            $map = self::wpc_att_map_load();
        }
        if (array_key_exists($url, $map)) {
            return (int) $map[$url];
        }
        $id = (int) attachment_url_to_postid($url);
        $map[$url] = $id;
        $dirty = true;
        if (!$hooked && function_exists('register_shutdown_function')) {
            $hooked = true;
            register_shutdown_function(function () use (&$map, &$dirty) {
                if (!$dirty || !is_array($map)) {
                    return;
                }
                self::wpc_att_map_save($map);
            });
        }
        return $id;
    }

    /** @var bool  May this render park stylesheets? ONE verdict for the whole render, decided by
     *             stage_critical_and_lazy_css before addCritical runs and cleared when the stage
     *             is done. Every parker reads it: the used-CSS rest link inside addCriticalCSS,
     *             and the two lazyCSS callbacks. */
    private static $parkAllowed = false;
    public static $imageCounter;
    public static $settings;
    public static $options;
    public static $siteUrl;
    public static $homeUrl;
    public static $zoneName;
    public static $randomHash;
    public static $siteUrlScheme;
    public static $excludedList;
    public static $lazyExcludeList;
    public static $defaultExcludedList;
    public static $externalUrlEnabled;
    public static $externalUrlExcluded;
    public static $emojiRemove;
    public static $preloaderAPI;
    public static $replaceAllLinks;
    /** @var bool  The request's <picture> webp wrap, set by wps_cdn_rewrite::mainInit() from the
     *             next-gen ceiling. Request configuration: a render reads and squashes its own
     *             copy on the render context ($ctx->pictureWebpEnabled) and never writes this. */
    public static $pictureWebpEnabled = false;
    /** @var bool  The request's <picture> avif source, set by mainInit() from the next-gen
     *             ceiling and never changed by a render, so every reader (pre-buffer srcset
     *             builders included) reads it here. */
    public static $pictureAvifEnabled = false;

    // CSS / JS Variables
    public static $fonts;
    public static $css;
    public static $cssMinify;
    public static $cssImgUrl;
    public static $js;
    public static $jsMinify;

    // Integrations
    public static $perfMattersActive;
    public static $brizyActive;
    public static $brizyCache;
    public static $revSlider;

    // Lazy Tags
    public static $lazyLoadedImages;
    public static $lazyLoadedImagesLimit;
    public static $lazyLoadSkipFirstImages;
    public static $loadedImagesSt;
    public static $loadedImagesStLimit;
    public static $lazyOverride;
    public static $delayJsOverride;
    public static $deferJsOverride;
    public static $nativeLazyEnabled;

    // Api Params
    public static $apiUrl;
    public static $exif;
    public static $webp;
    public static $isRetina;
    public static $retinaEnabled;
    public static $adaptiveEnabled;
    public static $webpEnabled;
    public static $lazyEnabled;
    public static $removeSrcset;
    public static $isMobile;

    public static $removedCSS;
    public static $excludes;
    public static $excludes_class;
    public static $isAjax;

    public static $page_excludes;
    public static $post_id;
    public static $page_excludes_files;

    public function __construct()
    {
        self::$imageCounter = 0;
        self::$settings = get_option(WPS_IC_SETTINGS);
        self::$options = get_option(WPS_IC_OPTIONS);
        self::$randomHash = 0;
        self::$preloaderAPI = 0;
        self::$isMobile = false;

        self::$settings = $this->runMissingSettings(self::$settings);

        self::$isAjax = (function_exists("wp_doing_ajax") && wp_doing_ajax()) || (defined('DOING_AJAX') && DOING_AJAX);

        if (!self::$isAjax && !empty($_POST)) {
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'ajax') !== false) {
                    self::$isAjax = true;
                    break;
                }
            }
        }

        self::$excludes_class = new wps_ic_excludes();
        self::$excludes = get_option('wpc-excludes');
        global $post;

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

        // Lazy Limits
        self::$lazyLoadedImages = 0;
        self::$lazyLoadedImagesLimit = 1;

        if (empty(self::$settings['lazySkipCount'])) {
            self::$lazyLoadSkipFirstImages = 4;
        } else {
            self::$lazyLoadSkipFirstImages = self::$settings['lazySkipCount'];
        }

        if (!empty(self::$page_excludes) && isset(self::$page_excludes['skip_lazy']) && self::$page_excludes['skip_lazy'] !== '') {
            self::$lazyLoadSkipFirstImages = self::$page_excludes['skip_lazy'];
        }

        /**
         * self::$isAjax was required for Ajax Filtering to work in Precommerce
         */
        if ((!empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'PreloaderAPI') !== false) || !empty($_GET['dbg_preload'])) {
            self::$lazyLoadedImagesLimit = 9999;
            self::$preloaderAPI = 1;
            self::$lazyEnabled = 0;
            self::$nativeLazyEnabled = 0;
            self::$adaptiveEnabled = 0;
        }

        self::$loadedImagesSt = 0;
        self::$loadedImagesStLimit = 6;

        self::$nativeLazyEnabled = self::$settings['nativeLazy'];

        $this->setupSiteUrl();

        $this->setupExcludes();
        $this->setupApiParams();


        if ($this->isMobile()) {
            $this->setMobile();
        }

        $this->removeEmoji();
        $this->revSliderActive();
        $this->perfMatters();
        $this->Brizy();

        self::$externalUrlEnabled = 'false';

        // External URL Enabled?
        if (!empty(self::$settings['external-url'])) {
            self::$externalUrlEnabled = self::$settings['external-url'];
        }
    }

    public function runMissingSettings($settings)
    {
        $required = ['css', 'css_image_urls', 'js', 'js_minify', 'emoji-remove', 'preserve_exit', 'fonts'];
        foreach ($required as $key => $value) {
            if (empty($settings[$key]) || !isset($settings[$key])) {
                $settings[$key] = '';
            }
        }

        return $settings;
    }

    public function is_home_url()
    {
        $home_url = rtrim(home_url(), '/');
        $current_url = wpc_request_scheme() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $current_url = rtrim($current_url, '/');
        return $home_url === $current_url;
    }

    public function setupSiteUrl()
    {
        if (!is_multisite()) {
            self::$siteUrl = site_url();
            self::$homeUrl = home_url();
        } else {
            $current_blog_id = get_current_blog_id();
            switch_to_blog($current_blog_id);

            self::$siteUrl = network_site_url();
            self::$homeUrl = home_url();
        }

        self::$siteUrl = preg_replace('#^https?://#', '', self::$siteUrl);
        self::$homeUrl = preg_replace('#^https?://#', '', self::$homeUrl);


        self::$siteUrl = trim(self::$siteUrl, '/');
        self::$homeUrl = trim(self::$homeUrl, '/');

        $cfCname = get_option(WPS_IC_CF_CNAME);
        $cf = get_option(WPS_IC_CF);


        $cfVerified = (!function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok());
        $custom_cname = (!empty($cf['settings']['cdn']) && !empty($cfCname) && $cfVerified) ? $cfCname : get_option('ic_custom_cname');
        if (!empty($custom_cname) && function_exists('wpc_cdn_cname_is_reachable') && !wpc_cdn_cname_is_reachable($custom_cname)) { $custom_cname = ''; }
        if (empty($custom_cname) || !$custom_cname) {
            self::$zoneName = get_option('ic_cdn_zone_name');
        } else {
            self::$zoneName = $custom_cname;
        }


        if (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
            self::$zoneName = '';
        }


        if (!empty(self::$zoneName) && function_exists('home_url')) {
            $wpc_oh = (string) wp_parse_url(home_url(), PHP_URL_HOST);
            if ($wpc_oh !== '' && strcasecmp((string) self::$zoneName, $wpc_oh) === 0) {
                self::$zoneName = '';
            }
        }

        self::$siteUrlScheme = parse_url(self::$siteUrl, PHP_URL_SCHEME);
    }

    public function setupExcludes()
    {
        // v7.22.72 — ONE list, shared with wps_cdn_rewrite (defines.php). This copy had never
        // carried 'wp-admin'; the owner's has since 2025-07-10, and every guard in
        // wpc_zone_delayed_js_url()'s ladder reads THIS one.
        self::$defaultExcludedList = wpc_default_cdn_excludes();

        self::$lazyExcludeList = get_option('wpc-ic-lazy-exclude');
        self::$excludedList = get_option('wpc-ic-external-url-exclude');

        if (!is_array(self::$excludedList)) {
            self::$externalUrlExcluded = explode("\n", self::$excludedList);
        } else {
            self::$externalUrlExcluded = self::$excludedList;
        }
    }

    public function setupApiParams()
    {
        $conditions = ['css_image_urls', 'js_minify', 'preserve_exif', 'emoji-remove', 'css', 'js'];
        foreach ($conditions as $key => $condition) {
            if (is_array($condition)) {
                if (!isset(self::$settings[$condition[0]][$condition[1]])) {
                    self::$settings[$condition[0]][$condition[1]] = '0';
                }
            } else {
                if (!isset(self::$settings[$condition])) {
                    self::$settings[$condition] = '0';
                }
            }
        }

        self::$css = self::$settings['css'];
        self::$cssImgUrl = self::$settings['css_image_urls'];
        // The zone CSS URL's minify flag. It is empty, which is what every site without css_minify
        // has always sent, until font subsetting forces it on for the rest of the request.
        self::$cssMinify = '';
        self::$js = self::$settings['js'];
        self::$jsMinify = self::$settings['js_minify'];
        self::$emojiRemove = self::$settings['emoji-remove'];
        self::$exif = self::$settings['preserve_exif'];

        if (isset(self::$settings['fonts']) && !empty(self::$settings['fonts'])) {
            self::$fonts = self::$settings['fonts'];
        } else {
            self::$fonts = '0';
        }

        self::$isRetina = '0';
        self::$webp = '0';
        self::$externalUrlEnabled = 'false';

        if (empty(self::$settings['remove-srcset'])) {
            self::$settings['remove-srcset'] = '0';
        }

        self::$removeSrcset = self::$settings['remove-srcset'];
        self::$lazyEnabled = self::$settings['lazy'];
        self::$adaptiveEnabled = self::$settings['generate_adaptive'];

        if (isset(self::$page_excludes['adaptive'])) {
            self::$adaptiveEnabled = self::$page_excludes['adaptive'];
        }

        self::$webpEnabled = self::$settings['generate_webp'];
        self::$retinaEnabled = self::$settings['retina'];

        if (!empty(self::$settings['replace-all-link'])) {
            self::$replaceAllLinks = self::$settings['replace-all-link'];
        } else {
            self::$replaceAllLinks = '0';
        }

        if ((!empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'PreloaderAPI') !== false) || !empty($_GET['dbg_preload'])) {
            self::$lazyLoadedImagesLimit = 9999;
            self::$preloaderAPI = 1;
            self::$lazyEnabled = 0;
            self::$adaptiveEnabled = 0;
        }

        if (!empty($_GET['disableLazy'])) {
            self::$lazyEnabled = '0';
        }

        $swapLanesSuperseded = (class_exists('WPC_Negotiated_Delivery')
            && method_exists('WPC_Negotiated_Delivery', 'is_active')
            && (WPC_Negotiated_Delivery::is_active()
                || (method_exists('WPC_Negotiated_Delivery', 'is_active_jpeg') && WPC_Negotiated_Delivery::is_active_jpeg())))
            || (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed());
        if ($swapLanesSuperseded && apply_filters('wpc_nd_stands_down_swap_lanes', true)) {
            self::$lazyEnabled = '0';
            self::$adaptiveEnabled = '0';
        }

        //
        if (!empty(self::$webpEnabled) && self::$webpEnabled == '1') {
            self::$webp = '1';
        } else {
            self::$webp = '0';
        }

        if (!empty(self::$retinaEnabled) && self::$retinaEnabled == '1') {
            if (isset($_COOKIE["ic_pixel_ratio"])) {
                if ($_COOKIE["ic_pixel_ratio"] >= 2) {
                    self::$isRetina = '1';
                }
            }
        }

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

        if (!empty($_GET['dbg']) && $_GET['dbg'] == 'direct') {
            if (!empty($_GET['custom_server'])
                && function_exists('wpc_cdn_debug_is_allowed') && wpc_cdn_debug_is_allowed()) {
                $custom_server = sanitize_text_field($_GET['custom_server']);
                if (preg_match('/^[a-z0-9\-]+\.zapwp\.net$/i', $custom_server)) {
                    self::$zoneName = $custom_server . '/key:' . self::$options['api_key'];
                }
            }
        }

        if (!empty(self::$exif) && self::$exif == '1') {
            self::$apiUrl = 'https://' . self::$zoneName . '/q:' . self::$settings['optimization'] . '/e:1';
        } else {
            self::$apiUrl = 'https://' . self::$zoneName . '/q:' . self::$settings['optimization'];
        }
    }


    public function isMobile()
    {
        // v7.10.671 — the crit-choice detector MUST match the cache-bucket detector or a
        // mobile-bucket page inlines desktop crit. Both now delegate to one shared test.
        if (function_exists('wpc_ua_is_mobile')) {
            return wpc_ua_is_mobile();
        }

        // Fail-open fallback (defines.php absent): the original narrow set — no worse than
        // pre-.671 behaviour; wpc_ua_is_mobile() is the live path.
        if (!empty($_GET['simulate_mobile'])) {
            return true;
        }

        if (isset($_SERVER['HTTP_USER_AGENT'])) {
            $userAgent = strtolower($_SERVER['HTTP_USER_AGENT']);

            $mobileKeywords = ['android', 'iphone', 'ipad', 'windows phone', 'blackberry', 'tablet', 'mobile'];

            foreach ($mobileKeywords as $keyword) {
                if (strpos($userAgent, $keyword) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public function setMobile()
    {
        self::$isMobile = true;
        self::$retinaEnabled = false;
        self::$isRetina = '0';
    }


    public static function wpc_deploy_combined($settings_override = null)
    {
        // v7.10.669 — the mode the DEPLOYED CF rule should carry: DESIRE only, NO floors. The floors
        // gate the RENDER (wpc_combined_crit_on); the DEPLOY must device-key the edge whenever split
        // is desired, so a readback can then OBSERVE the key and unlock split. Using
        // wpc_combined_crit_on() here is the circular trap: .668 forces combined until a readback sees
        // the key, but the deploy is what PUTS it there — a floor-gated deploy strips it, the readback
        // sees none, and Refresh Connection can never bootstrap the edge. Device-keying the edge is
        // safe even while the render stays combined (two identical buckets until the readback flips
        // the render to split). Explicit combined-crit=1 opts out.
        $s = is_array($settings_override) ? $settings_override
            : (function_exists('get_option') ? get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings') : []);
        $cc = (is_array($s) && isset($s['combined-crit'])) ? (string) $s['combined-crit'] : '';
        if ($cc === '1') { return true; }   // explicit combined → do not device-key the edge
        if ($cc === '0') { return false; }  // explicit split → device-key the edge
        if (apply_filters('wpc_split_default_on', true)) { return false; } // AUTO prefers split → device-key
        $cf = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : [];
        return is_array($cf) && !empty($cf['token']) && !empty($cf['zone'])
            && !(is_array($s) && !empty($s['minimal-mobile-css']) && $s['minimal-mobile-css'] == '1');
    }

    // The service crit can carry a "/* wpc conceal-guard */" block that display:none's containers
    // until the full stylesheet stack applies. The service scopes every guard rule under
    // html:not(.wpc-css-live) itself (crit-push v3.189.2, conceal-guard.js CONCEAL_SCOPE), and
    // the used-css boot adds that class when the stack is live. The rule here: a crit that
    // carries a scoped guard must ship with that releaser, even on a page with no used-css rest
    // link, or the guarded element stays hidden for good (ridgeway /events/, div.brx-popup,
    // 2026-08: the boot was keyed on "did the plugin rewrite it" and never emitted for a
    // natively scoped guard). A guard in the pre-v3.189.2 unscoped shape is served as written.
    public static $concealGuardNeedsRelease = false;

    public static function wpc_note_conceal_guard($css)
    {
        $css = (string) $css;
        if (strpos($css, 'conceal-guard') !== false && strpos($css, 'wpc-css-live') !== false) {
            self::$concealGuardNeedsRelease = true;
        }

        return $css;
    }

    /**
     * v7.24.11 — WILL THIS CRIT BLOB PAINT ANYTHING?
     *
     * The one rule wpc_build_crit_style_tag applies below, as a function of the blob alone: the faces
     * split moves every @font-face block to the carrier, and a blob that is nothing but faces
     * (or nothing at all) leaves an empty payload, so no crit tag is emitted. The park verdict
     * has to answer this BEFORE addCritical runs, because the used-CSS lane inside it parks its
     * own rest link ~450 lines before the tag is built; asking the same question in two places
     * is how the two could disagree, so both ask here.
     */
    /**
     * The render's park verdict, set once by the stage that owns it. Returns the previous value
     * so a caller that opens a narrower window (lazyCSS) can restore it.
     */
    public static function set_park_allowed($allowed)
    {
        $was = self::$parkAllowed;
        self::$parkAllowed = (bool) $allowed;

        return $was;
    }

    public static function crit_payload_present($css)
    {
        if (!is_string($css) || trim($css) === '') {
            return false;
        }
        if (stripos($css, '@font-face') !== false && apply_filters('wpc_crit_faces_split', true)) {
            $rest = preg_replace('#@font-face\s*\{[^{}]*\}#i', '', $css);
            if (is_string($rest)) {
                $css = $rest;
            }
        }

        return trim((string) preg_replace('#/\*.*?\*/#s', '', $css)) !== '';
    }

    public static function wpc_build_crit_style_tag($attrs, $payload)
    {
        $payload = (string) $payload;
        // v7.21.195 — the emit chokepoint EVERY crit emission passes: repair unparseable
        // data: urls here too, so no upstream branch (combined payload, future lanes) can
        // ship a blob Blink's bad-url recovery guts. Idempotent; valid bytes untouched.
        if (method_exists(get_called_class(), 'wpc_css_requote_urls')) {
            $payload = self::wpc_css_requote_urls($payload);
        }
        // v7.20.04 — FONTS ARE A SITE CONSTANT: the crit artifact's embedded @font-face blocks
        // are routinely the document's ONLY letters-coverage for the theme's real faces (dalton:
        // Poppins w600/700/800 letter subsets lived only in crit; the late lane carried residual
        // faces whose unicode-range EXCLUDES letters). Any runtime crit remover — the service's
        // optimize.js does a naked #wpc-critical-css remove at ~3.5s, no hoist, no gate — then
        // deletes the faces with it and every headline falls to the metric fallback (Arial)
        // permanently: "starts as Poppins, goes to Arial". Split the faces OUT of the crit
        // payload at emission; addCritical hands every face it assembled to the render's face
        // owner once its own font post-processing is done, and that owner's block no remover
        // targets.
        $carrier = '';
        if (stripos($payload, '@font-face') !== false && apply_filters('wpc_crit_faces_split', true)) {
            $faces = [];
            $rest = preg_replace_callback('#@font-face\s*\{[^{}]*\}#i', function ($m) use (&$faces) {
                $faces[] = $m[0];
                return '';
            }, $payload);
            if (is_string($rest) && !empty($faces)) {
                $carrier = '<style type="text/css" id="wpc-crit-faces">' . implode('', $faces) . '</style>';
                $payload = $rest;
            }
        }
        // v7.22.22 — AN EMPTY CRIT TAG IS A LIE. Every presence gate (lazyCSS, the cache
        // armer, the edge) reads id="wpc-critical-css" as "crit painted"; an empty tag parked
        // every sheet behind nothing (columbus update window: browser-default blue links).
        // v7.24.11 — through crit_payload_present, so the park verdict's earlier answer and
        // this emit can never disagree. It also reads a comment-only blob (a truncated
        // artifact carrying nothing but its budget stamp) as empty, which trim() did not.
        if (!self::crit_payload_present($payload)) {
            if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_crit_empty22')) {
                set_transient('wpc_crit_empty22', 1, 600);
                wpc_cache_first_log('crit-tag-empty', '', '', ['attrs' => substr((string) $attrs, 0, 80)]);
            }
            return $carrier;
        }
        return $carrier . '<style type="text/css" ' . $attrs . '>' . $payload . '</style>';
    }

    // v7.22.22 / v7.24.11 — NO CRIT, NO DEFERRAL. Parked sheets are only ever correct behind a
    // painted crit, so the park verdict is decided before any parker runs (cdn-rewrite.php
    // stage_critical_and_lazy_css): a parked link or an href-less used-css rest link behind a
    // carrier that never paints is never minted, and nothing has to be restored after the fact.
    // v7.22.23 — A FAMILY WE CANNOT COMPLETE, WE DO NOT START. The carrier host gate (.22)
    // drops a third-party full face; the ATF subset and the metric fallback for that same
    // family kept coming, so the page rendered the family above the fold and a size-adjusted
    // Arial below it — and on a crit-less render only the fallback, which is wider than the
    // site's own sans-serif and wrapped the desktop menu (columbus: nav 46px -> 92px).
    // A family the carrier had to drop is backed only when THIS render declares it itself:
    // a Google/Bunny CSS link naming it, or an @font-face with a real src outside our
    // subset/fallback blocks. Unbacked = no subset, no fallback = the site's own rendering.
    public static function wpc_unbacked_font_families($html, $output = '', $extra = [])
    {
        try {
            if (!apply_filters('wpc_unbacked_family_gate', true)) {
                return [];
            }
            $hay = (string) $html . (string) $output;
            // v7.22.25 — BACKED BY THIS RENDER, NOT BY A STALE CARRIER FILE. Candidates are every
            // synthetic face we would emit (a "<family> Fallback" or an ATF subset) plus any
            // family a caller is about to emit; a family is backed when the render itself
            // declares it: a Google/Bunny link naming it, a real-src @font-face outside our
            // synthetic blocks (after the third-party filter), one of the site's own linked
            // stylesheets declaring it, or a non-Google font-service link (which we cannot
            // read and must assume backs anything).
            $cands = isset($GLOBALS['wpc_dropped_third_party_font_families']) && is_array($GLOBALS['wpc_dropped_third_party_font_families']) ? $GLOBALS['wpc_dropped_third_party_font_families'] : [];
            foreach ((array) $extra as $e) {
                $e = strtolower(trim((string) $e, " \t\"'"));
                if ($e !== '') {
                    $cands[$e] = 1;
                }
            }
            if (preg_match_all('/@font-face\s*\{[^{}]*font-family\s*:\s*["\']?([^"\';}]+?)\s+Fallback["\']?\s*;/i', $hay, $fbm)) {
                foreach ($fbm[1] as $f) {
                    $cands[strtolower(trim($f))] = 1;
                }
            }
            if (preg_match_all('/<style\b[^>]*\bid=(["\'])wpc-font-subsets\1[^>]*>(.*?)<\/style>/is', $hay, $sbm)) {
                foreach ($sbm[2] as $sb) {
                    if (preg_match_all('/@font-face\s*\{[^{}]*font-family\s*:\s*["\']?([^"\';}]+)/i', $sb, $sf)) {
                        foreach ($sf[1] as $f) {
                            $cands[strtolower(trim($f, " \t\"'"))] = 1;
                        }
                    }
                }
            }
            if (!$cands) {
                return [];
            }
            $links = '';
            $svc = false;
            if (preg_match_all('/<link\b[^>]*href=["\']([^"\']+)["\'][^>]*>/i', $hay, $lm)) {
                foreach ($lm[1] as $u) {
                    $u = html_entity_decode($u);
                    $h = strtolower((string) parse_url($u, PHP_URL_HOST));
                    if ($h === 'fonts.googleapis.com' || $h === 'fonts.bunny.net') {
                        $links .= ' ' . strtolower($u);
                    } elseif ($h !== '' && preg_match('/(^|\.)(typekit\.net|typekit\.com|fonts\.net|typography\.com|cdnfonts\.com|fontawesome\.com|fonts\.adobe\.com)$/', $h)) {
                        $svc = true;
                    }
                }
            }
            if ($svc) {
                return [];
            }
            $decl = preg_replace('/<style\b[^>]*\bid=(["\'])(?:wpc-font-subsets|wpc-font-fallbacks)\1[^>]*>.*?<\/style>/is', '', $hay);
            if (!is_string($decl)) {
                $decl = $hay;
            }
            $linked = self::wpc_linked_face_families($html);
            $out = [];
            foreach (array_keys($cands) as $fam) {
                $fam = strtolower(trim((string) $fam));
                if ($fam === '' || isset($linked[$fam])) {
                    continue;
                }
                $plus = str_replace(' ', '+', $fam);
                if ($links !== '' && (strpos($links, 'family=' . $plus) !== false || strpos($links, '|' . $plus) !== false
                    || strpos($links, 'family=' . rawurlencode($fam)) !== false || strpos($links, 'family=' . $fam) !== false
                    || strpos($links, '%7c' . $plus) !== false)) {
                    continue;
                }
                $q = preg_quote($fam, '/');
                if (preg_match_all('/@font-face\s*\{[^{}]*font-family\s*:\s*["\']?' . $q . '["\']?\s*;[^{}]*src\s*:[^{}]*url\(\s*["\']?([^"\')\s]+)/i', $decl, $dm)) {
                    $ok = false;
                    foreach ($dm[1] as $u) {
                        if (stripos($u, 'data:') === 0 || !function_exists('wpc_font_carrier_host_allowed') || wpc_font_carrier_host_allowed($u)) {
                            $ok = true;
                            break;
                        }
                    }
                    if ($ok) {
                        continue;
                    }
                }
                $out[$fam] = 1;
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // v7.22.25 — the site's own linked stylesheets ARE declarations. Same-host (origin, zone,
    // custom CNAME) .css links under wp-content|wp-includes are read from disk once and the
    // families they declare with a real src are cached per (path,size,mtime) for a day.
    private static $linkedFaceFamiliesCache = null;
    public static function wpc_linked_face_families($html)
    {
        if (self::$linkedFaceFamiliesCache !== null) {
            return self::$linkedFaceFamiliesCache;
        }
        $fams = [];
        try {
            if (!defined('ABSPATH') || !is_string($html)
                || !preg_match_all('/<link\b[^>]*href=(["\'])([^"\']+\.css(?:\?[^"\']*)?)\1[^>]*>/i', $html, $lm)) {
                return self::$linkedFaceFamiliesCache = $fams;
            }
            $n = 0;
            foreach ($lm[2] as $href) {
                if ($n++ >= 64) {
                    break;
                }
                $pu = parse_url(html_entity_decode($href));
                $h = isset($pu['host']) ? strtolower((string) $pu['host']) : '';
                if ($h !== '' && function_exists('wpc_font_carrier_host_allowed') && !wpc_font_carrier_host_allowed('https://' . $h . '/x.css')) {
                    continue;
                }
                $path = isset($pu['path']) ? (string) $pu['path'] : '';
                if (!preg_match('#^/(?:wp-content|wp-includes)/#', $path) || strpos($path, '..') !== false) {
                    continue;
                }
                $fp = rtrim(ABSPATH, '/') . $path;
                $sz = @is_readable($fp) ? (int) @filesize($fp) : 0;
                if ($sz <= 0 || $sz > 1048576) {
                    continue;
                }
                $key = 'wpc_lff25_' . md5($fp . '|' . $sz . '|' . (int) @filemtime($fp));
                $c = function_exists('get_transient') ? get_transient($key) : false;
                if (!is_array($c)) {
                    $c = [];
                    $css = (string) @file_get_contents($fp);
                    if (stripos($css, '@font-face') !== false && preg_match_all('/@font-face\s*\{[^{}]*\}/is', $css, $fb)) {
                        foreach ($fb[0] as $blk) {
                            if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $blk, $f) && preg_match('/src\s*:[^{}]*url\(/i', $blk)) {
                                $c[strtolower(trim($f[1]))] = 1;
                            }
                        }
                    }
                    if (function_exists('set_transient')) {
                        set_transient($key, $c, 86400);
                    }
                }
                foreach ($c as $k => $v) {
                    $fams[$k] = 1;
                }
            }
        } catch (\Throwable $e) {
        }
        return self::$linkedFaceFamiliesCache = $fams;
    }

    // v7.22.33 — DEVICE MODE IS AN INPUT TO ELEMENTOR'S INIT, AND IT LIVES IN CSS. Elementor's
    // frontend (native defer, never delayed) initialises every handler at DCL and reads the
    // current device mode from a CSS pseudo-element (#elementor-device-mode:after / body:after
    // content:"mobile"). On a crit page those rules sit in a PARKED sheet, so at init the mode
    // is "" and Pro's sticky deactivates (sticky_on.indexOf("") === -1) — staging: nav handler
    // bound at 1.1s, header sticky only at 1.9s after our replay-end resize; natively sticky
    // precedes the nav bind. Every tap in that window opened the menu in-flow (page slid down
    // with the menu animation) and the late sticky then baked the open height into its spacer.
    // The device-mode rules are ~300 bytes with no url(): they are extracted from the page's own
    // sheets (site breakpoints included) and emitted inline right after the crit tag whenever
    // no blocking CSS on the page carries them. Zero requests; the rest of the sheet stays parked.
    // v7.22.34 — A TAP BEFORE THE LOADER IS A TAP, NOT A DEAD ONE. The head arm already
    // records the first gesture (html.wpc-bgl255) for a loader that arrives after it; a tap on
    // the Elementor toggle in that window did nothing until .33 (native is dead there too, Pro
    // binds at ~1.7s on staging). The arm now records the tap as data-wpc-early-tap="<ms>:<index>";
    // a second tap clears it (changed mind), the loader consumes it at boot and feeds its own
    // queued-open path (opens at CSS-live), dropping it when older than 1.5s or the page has
    // scrolled — an intent that expired must never open a menu on its own. One emitter for
    // both used-css sites; stops recording the moment the loader is live.
    public static function wpc_early_gesture_arm_tag()
    {
        return '<script id="wpc-bgl255-arm" data-nodefer="1">(function(){var d=document.documentElement,f=0,L=["pointerdown","keydown","touchstart","wheel","scroll","mousemove"],a=function(){if(f)return;f=1;try{d.classList.add("wpc-bgl255")}catch(x){}L.forEach(function(e){try{window.removeEventListener(e,a,!0)}catch(x){}})};'
            . 'window.addEventListener("click",function(e){try{if(window.wpcHamburgerState)return;var t=e.target&&e.target.closest?e.target.closest(".elementor-menu-toggle"):null;if(!t)return;if(d.hasAttribute("data-wpc-early-tap")){d.removeAttribute("data-wpc-early-tap");return}var i=Array.prototype.indexOf.call(document.querySelectorAll(".elementor-menu-toggle"),t);d.setAttribute("data-wpc-early-tap",Math.round(performance.now())+":"+(i<0?0:i))}catch(x){}},{capture:!0,passive:!0});'
            . 'if((window.pageYOffset||d.scrollTop||0)>0){a();return}L.forEach(function(e){window.addEventListener(e,a,{passive:!0,capture:!0})});window.addEventListener("pageshow",function(e){e&&e.persisted&&a()},{once:!0})})();</script>';
    }

    public static function wpc_extract_device_mode_css($css)
    {
        $out = '';
        $css = (string) $css;
        if ($css === '' || (stripos($css, 'device-mode') === false && stripos($css, ':after') === false && stripos($css, '::after') === false)) {
            return '';
        }
        $sel = '(?:#elementor-device-mode|body)\s*::?after\s*\{[^{}]*\bcontent\s*:\s*["\'][a-z_]+["\'][^{}]*\}';
        $rest = preg_replace_callback('/@media[^{}]*\{\s*' . $sel . '\s*\}/i', function ($m) use (&$out) {
            $out .= $m[0];
            return '';
        }, $css);
        if (!is_string($rest)) {
            $rest = $css;
        }
        if (preg_match_all('/' . $sel . '/i', $rest, $bm)) {
            $out = implode('', $bm[0]) . $out;
        }
        return strlen($out) > 4096 ? '' : $out;
    }

    public static function wpc_device_mode_css_from_sheet($href)
    {
        try {
            if (!defined('ABSPATH')) {
                return '';
            }
            $pu = parse_url(html_entity_decode((string) $href));
            $h = isset($pu['host']) ? strtolower((string) $pu['host']) : '';
            if ($h !== '' && function_exists('wpc_font_carrier_host_allowed') && !wpc_font_carrier_host_allowed('https://' . $h . '/x.css')) {
                return '';
            }
            $path = isset($pu['path']) ? (string) $pu['path'] : '';
            if (!preg_match('#^/(?:wp-content|wp-includes)/#', $path) || strpos($path, '..') !== false) {
                return '';
            }
            $fp = rtrim(ABSPATH, '/') . $path;
            $sz = @is_readable($fp) ? (int) @filesize($fp) : 0;
            if ($sz <= 0 || $sz > 2097152) {
                return '';
            }
            $key = 'wpc_dm33_' . md5($fp . '|' . $sz . '|' . (int) @filemtime($fp));
            $c = function_exists('get_transient') ? get_transient($key) : false;
            if (!is_string($c)) {
                $c = self::wpc_extract_device_mode_css((string) @file_get_contents($fp));
                if (function_exists('set_transient')) {
                    set_transient($key, $c, 86400);
                }
            }
            return $c;
        } catch (\Throwable $e) {
            return '';
        }
    }

    // v7.22.35 — A CRIT THAT USES A CUSTOM PROPERTY MUST DEFINE IT. aliiadventureshack (Bricks):
    // the crit carried `.bricks-mobile-menu-toggle span{transition:var(--bricks-transition)}` but
    // not the `:root{--bricks-transition:all 0.2s}` that lives inside `@layer bricks{}` in the
    // parked bundle — an undefined var makes the declaration invalid at computed-value time, so
    // the hamburger SNAPPED to an X instead of animating until the parked CSS landed (measured:
    // 45° at the first frame vs native 0→14→32→40→45 over 200ms). Five vars on that page were used
    // and never defined (--btn-font-size, --default-font-size among them). The belt lists the
    // vars the crit uses but never defines, finds their root-level definitions (:root/html/body,
    // media wrappers preserved, @layer flattened — the crit already flattens layers) in the
    // page's own deferred sheets, and emits them inline after the crit tag. Zero requests.
    public static function wpc_sheet_root_vars($css)
    {
        $out = [];
        $css = (string) $css;
        if ($css === '' || strpos($css, '--') === false) {
            return $out;
        }
        // innermost @media extents, one linear brace walk
        $media = [];
        $len = strlen($css);
        if (preg_match_all('/@media\s*([^{]+)\{/i', $css, $mm, PREG_OFFSET_CAPTURE)) {
            foreach ($mm[0] as $k => $hit) {
                $start = $hit[1] + strlen($hit[0]);
                $depth = 1;
                $pos = $start;
                while ($pos < $len && $depth > 0) {
                    $c = $css[$pos];
                    if ($c === '{') { $depth++; } elseif ($c === '}') { $depth--; }
                    $pos++;
                }
                $media[] = [$start, $pos, trim($mm[1][$k][0])];
            }
        }
        if (!preg_match_all('/(?:(?<=[{};])|^)\s*((?::root|html|body)(?:\s*,\s*(?::root|html|body))*)\s*\{([^{}]*)\}/i', $css, $rm, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($rm[2] as $k => $body) {
            if (strpos($body[0], '--') === false) {
                continue;
            }
            $at = $body[1];
            $q = '';
            $span = PHP_INT_MAX;
            foreach ($media as $m) {
                if ($at > $m[0] && $at < $m[1] && ($m[1] - $m[0]) < $span) { $span = $m[1] - $m[0]; $q = $m[2]; }
            }
            $sel = preg_replace('/\s+/', '', $rm[1][$k][0]);
            if (preg_match_all('/(--[A-Za-z0-9_-]+)\s*:\s*([^;{}]+)/', $body[0], $dm)) {
                foreach ($dm[1] as $i => $name) {
                    if (count($out) >= 600) { break 2; }
                    $val = trim($dm[2][$i]);
                    if ($val === '' || stripos($val, 'url(') !== false || strlen($val) > 400) {
                        continue;
                    }
                    $out[$name][] = [$q, $sel, $val];
                }
            }
        }
        return $out;
    }

    public static function wpc_sheet_root_vars_cached($href)
    {
        try {
            if (!defined('ABSPATH')) {
                return [];
            }
            $pu = parse_url(html_entity_decode((string) $href));
            $h = isset($pu['host']) ? strtolower((string) $pu['host']) : '';
            if ($h !== '' && function_exists('wpc_font_carrier_host_allowed') && !wpc_font_carrier_host_allowed('https://' . $h . '/x.css')) {
                return [];
            }
            $path = isset($pu['path']) ? (string) $pu['path'] : '';
            if (!preg_match('#^/(?:wp-content|wp-includes)/#', $path) || strpos($path, '..') !== false) {
                return [];
            }
            $fp = rtrim(ABSPATH, '/') . $path;
            $sz = @is_readable($fp) ? (int) @filesize($fp) : 0;
            if ($sz <= 0 || $sz > 2097152) {
                return [];
            }
            $key = 'wpc_cv35_' . md5($fp . '|' . $sz . '|' . (int) @filemtime($fp));
            $c = function_exists('get_transient') ? get_transient($key) : false;
            if (!is_array($c)) {
                $c = self::wpc_sheet_root_vars((string) @file_get_contents($fp));
                if (function_exists('set_transient')) {
                    set_transient($key, $c, 86400);
                }
            }
            return $c;
        } catch (\Throwable $e) {
            return [];
        }
    }


    /**
     * Drops the @font-face rules of the families in $fams (a "<Family> Fallback" face goes with
     * its family). $where names the caller in the receipt.
     */
    public static function wpc_strip_family_faces($css, $fams, $where = 'faces')
    {
        if (!is_string($css) || $css === '' || !is_array($fams) || !$fams || stripos($css, '@font-face') === false) {
            return $css;
        }
        $n = 0;
        $out = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($m) use ($fams, &$n) {
            if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $m[0], $f)) {
                return $m[0];
            }
            $fam = strtolower(trim($f[1]));
            if (substr($fam, -9) === ' fallback') {
                $fam = substr($fam, 0, -9);
            }
            if (isset($fams[$fam])) {
                $n++;
                return '';
            }
            return $m[0];
        }, $css);
        if (!is_string($out)) {
            return $css;
        }
        // A synthetic face for a family this render does not back with a real face is dropped
        // (a theme that declares families it never uses). Sampled: the page's declarations are
        // the same on every render.
        if ($n > 0 && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('font-family-unbacked', [(string) $where => $n, 'fams' => substr(implode(',', array_keys($fams)), 0, 120)], true);
        }
        return $out;
    }

    // v7.22.26 — A COMBINED CRIT IS ONLY VALID AS A SUPERSET. The service's combined artifact
    // (`/*wpc-combined:v1 saved=42% shared=409*/`) dropped 43 selectors present in BOTH
    // device crits (`.elementor-kit-18 a`, `.elementor a`, `.elementor-icon-list-text`,
    // social icons …) and 10 desktop-only ones — header links painted browser-blue until
    // the parked sheets arrived (columbus, .25 live). Any shared selector missing, or more
    // than 10% of a device crit's selectors missing, rejects the combined artifact and the
    // per-device crit (which has the rules) serves instead. Verdict cached per artifact
    // triple (path|size|mtime) so a render never re-parses ~300KB.
    public static function wpc_combined_crit_selectors($css)
    {
        $out = [];
        if (!is_string($css) || $css === '') {
            return $out;
        }
        $css = preg_replace('#/\*.*?\*/#s', '', $css);
        $css = preg_replace('/@font-face\s*\{[^{}]*\}/is', '', $css);
        if (!is_string($css)) {
            return $out;
        }
        if (preg_match_all('/(?:^|[{}])\s*([^{}@;]{2,300}?)\s*\{/s', $css, $m)) {
            foreach ($m[1] as $s) {
                $s = strtolower(preg_replace('/\s+/', ' ', trim($s)));
                if ($s !== '' && $s[0] !== '@') {
                    $out[$s] = 1;
                }
            }
        }
        return $out;
    }

    public static function wpc_combined_crit_coverage($cmb, $cmbFile, $exists)
    {
        try {
            if (!apply_filters('wpc_cmb_coverage_gate', true) || !is_array($exists)
                || empty($exists['desktop']) || empty($exists['mobile'])) {
                return '';
            }
            $d = (string) $exists['desktop'];
            $mo = (string) $exists['mobile'];
            $key = 'wpc_cmbcov26_' . md5($cmbFile . '|' . (int) @filesize($cmbFile) . '|' . (int) @filemtime($cmbFile)
                . '|' . (int) @filesize($d) . '|' . (int) @filemtime($d) . '|' . (int) @filesize($mo) . '|' . (int) @filemtime($mo));
            $cached = function_exists('get_transient') ? get_transient($key) : false;
            if (is_string($cached)) {
                return $cached === 'ok' ? '' : $cached;
            }
            $sc = self::wpc_combined_crit_selectors($cmb);
            $sd = self::wpc_combined_crit_selectors((string) @file_get_contents($d));
            $sm = self::wpc_combined_crit_selectors((string) @file_get_contents($mo));
            $why = '';
            if ($sc && $sd) {
                $missD = array_diff_key($sd, $sc);
                $missM = $sm ? array_diff_key($sm, $sc) : [];
                $shared = array_intersect_key($missD, $sm);
                if (count($shared) > 0) {
                    $why = 'shared' . count($shared);
                } elseif (count($missD) > 0.1 * count($sd) || ($sm && count($missM) > 0.1 * count($sm))) {
                    $why = 'd' . count($missD) . 'm' . count($missM);
                }
            }
            if (function_exists('set_transient')) {
                set_transient($key, $why === '' ? 'ok' : $why, 6 * 3600);
            }
            return $why;
        } catch (\Throwable $e) {
            return '';
        }
    }

    // v7.20.19 — THE RELEASE DEADLINE IS THE CONTRACT. A conceal guard hides real UI
    // (primary navigation, checkout) behind a network request, so the release can never be
    // conditional on that request succeeding — nor on window.load, which is where the old
    // ultimate belt was armed: a page whose load event never fired kept the nav hidden
    // forever, and one that fired late added 15s on top. 0 = never conceal.
    public static function wpc_ucss_conceal_ms()
    {
        if (apply_filters('wpc_conceal_guard', true) === false) {
            return 0;
        }
        // The guard's premise is "a stylesheet is coming". When the used-css fetch is in its
        // known-failing window (the .18 404 memory), it is not — so the guard concedes at once
        // rather than hiding navigation for the deadline it can no longer justify.
        if (function_exists('get_transient') && get_transient('wpc_ucss_failing')) {
            return 0;
        }
        $ms = (int) apply_filters('wpc_conceal_release_ms', 2500);
        if ($ms < 0) {
            $ms = 0;
        }
        return $ms > 10000 ? 10000 : $ms;
    }

    // v7.24.12 — A VISITOR WHO NEVER MOVES STILL GETS THE SITE'S CSS. With used-css on, the
    // page ships every real sheet parked (type="wpc-stylesheet") and the rest link href-less;
    // the only thing that unparked them was the delay loader, which waits for a gesture. On
    // crawfordtree.com a desktop load with no mouse movement sat on critical CSS alone for the
    // whole 7s measurement — everything below the fold partially styled — and the full set
    // landed at once on the first mousemove, reflowing the document by 464px. That is the
    // customer's "extra CSS was added / sections broken". The boot now races three arms and
    // takes the first: a gesture, an idle callback after load, and a wall-clock timer that does
    // not depend on `load` ever firing. The settle is the lane's own conceal deadline (the
    // measured ucss boot delay) so both halves of the lane release on the same schedule.
    // 0 = no time fallback, i.e. the gesture-only behaviour this replaces.
    public static function wpc_ucss_boot_fallback_ms()
    {
        if (apply_filters('wpc_ucss_boot_fallback', true) === false) {
            return 0;
        }
        $settle = self::wpc_ucss_conceal_ms();
        if ($settle <= 0) {
            $settle = 2000;
        }
        $ms = (int) apply_filters('wpc_ucss_boot_fallback_ms', $settle);
        if ($ms < 0) {
            $ms = 0;
        }
        return $ms > 10000 ? 10000 : $ms;
    }

    /**
     * @param bool $unparkParked  The time fallback also hands the parked sheets back: the used-css
     *        lane's fallback (d8fc3dc3), for a page that serves used css, split (rest link) or whole
     *        (data-wpc-ucss). A page with no used css gets the boot only as the conceal guard's releaser, and there the
     *        parked sheets stay with the delay loader's release (gesture, an evidenced visit, or
     *        the trailing-carrier test): webdesign4u.com.au (2026-09-28), no used-css, the boot's
     *        2.5 s fallback unparked the Divi module-design sidecar and its Font Awesome rules
     *        pulled three FA woff2 files onto every load (38 requests / 2.4 MB against 23 /
     *        0.9 MB on 7.24.04, which had no fallback).
     */
    public static function wpc_ucss_boot_js($unparkParked = true)
    {
        $ms = self::wpc_ucss_conceal_ms();
        $fallbackMs = $unparkParked ? self::wpc_ucss_boot_fallback_ms() : 0;
        // Parked sheets are handed back to the parser exactly as the delay loader hands them
        // back — link rel, style type — and our own wpc-* blocks are left to their own lanes.
        // The background park is armed with them: a sheet whose url() declarations sit behind
        // html.wpc-bgl255 is not the site's CSS until that class is on.
        $unpark = $fallbackMs > 0
            ? 'function u(){var p=document.querySelectorAll(\'link[rel="wpc-stylesheet"],style[type="wpc-stylesheet"]\');for(var i=0;i<p.length;i++){var e=p[i];if(e.id&&e.id.indexOf("wpc-")===0){continue}try{if(e.tagName.toLowerCase()==="link"){e.setAttribute("rel","stylesheet")}else{e.setAttribute("type","text/css")}}catch(x){}}try{d.classList.add("wpc-bgl255")}catch(x){}}'
            : 'function u(){}';
        // The gesture is still the first arm: it fires the moment the visitor does anything,
        // ahead of both timers.
        $gesture = $fallbackMs > 0
            ? 'var G=["pointerdown","keydown","touchstart","wheel","scroll","mousemove"],h=function(){b();for(var k=0;k<G.length;k++){try{window.removeEventListener(G[k],h,!0)}catch(x){}}};for(var k0=0;k0<G.length;k0++){window.addEventListener(G[k0],h,{passive:!0,capture:!0})}setTimeout(b,' . $fallbackMs . ');'
            : '';
        return '<script id="wpc-ucss-boot">/*wpc-arm-sentinel*/(function(){var d=document.documentElement,f=0,g=function(){try{d.classList.add("wpc-css-live")}catch(x){}},g2=function(){try{if(document.querySelector(\'link[rel^="wpc-"]\'))return;g()}catch(x){g()}};function a(){var r=document.querySelectorAll(\'link[data-wpc-rest]:not([href])\'),armed=0;for(var j=0;j<r.length;j++){(function(e){var ru=e.getAttribute("data-wpc-rest"),rm=e.getAttribute("data-wpc-ucss-rest")||"all",rg=true;try{rg=!window.matchMedia||window.matchMedia(rm).matches}catch(x){rg=true}if(!rg||!ru)return;armed++;e.media="print";e.onload=function(){this.onload=null;this.media=rm;g2()};e.addEventListener("error",g);e.setAttribute("href",ru)})(r[j])}if(!armed){g2()}}'
            . $unpark
            . 'function b(){if(f)return;f=1;a();u()}'
            . $gesture
            . 'function q(){(window.requestIdleCallback||function(z){setTimeout(z,1200)})(b,{timeout:2500})}setTimeout(g,' . (int) $ms . ');if(document.readyState==="complete"){q()}else{window.addEventListener("load",q)}})();</script>';
    }

    public static function wpc_combined_crit_on($settings_override = null)
    {
        static $on = null;


        if ($settings_override === null && $on !== null) {
            return apply_filters('wpc_combined_crit', $on);
        }
        $s = is_array($settings_override) ? $settings_override
            : (is_array(self::$settings) ? self::$settings : (function_exists('get_option') ? get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings') : []));
        $v = (is_array($s) && isset($s['combined-crit'])) ? (string) $s['combined-crit'] : '';
        if ($v === '1') {
            $on = true;
        } elseif ($v === '0') {
            $on = false;
        } else {
            // v7.10.666 (R4 — THE FLIP): prefer device-SPLIT by default. The CF devkey floor and the
            // non-CF device-blind floor below force COMBINED wherever the front cache cannot key per
            // device, so this is safe by construction — only a proven device-capable site actually
            // splits. Kill switch: filter wpc_split_default_on => false restores the legacy
            // CF-combined default.
            if (apply_filters('wpc_split_default_on', true)) {
                $on = false;
            } else {
                $cf = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : [];
                $on = is_array($cf) && !empty($cf['token']) && !empty($cf['zone'])
                    && !(is_array($s) && !empty($s['minimal-mobile-css']) && $s['minimal-mobile-css'] == '1');
            }
        }
        // v7.10.568 — SAFETY FLOOR. Device-divergent HTML is only safe if the shared cache in
        // front of us can key per device. Where it cannot, the edge keeps ONE copy per URL: the
        // first device to warm it decides what every other device sees — a persistent wrong-page
        // bug, far worse than the bytes it saves. Earn it, do not assume it: only a readback that
        // OBSERVED the device key on the deployed rules unlocks split. Overrides an explicit
        // combined-crit=0 by design — a device-blind edge makes that setting unsafe regardless of
        // who asked for it. Filter to bypass with eyes open.
        // v7.10.685 — corrected: this comment used to assert "cache_by_device_type is Enterprise-
        // only". It is not. Proven live 2026-08-02 on a CF FREE zone (wpspeedkit): the rule
        // deploys, the buckets genuinely separate (warm desktop alone ⇒ mobile MISSes), and
        // purge-by-TAG evicts every device variant while purge-by-URL is the no-op. So the floor
        // is about PROVING this edge keys per device, never about the plan tier — and combined is
        // not a purge requirement. See cf-purge-probe.sh to re-verify any zone in ~90s.
        $floorWhy = '';
        if (!$on && apply_filters('wpc_combined_crit_devkey_floor', true)) {
            $cfx = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : [];
            if (is_array($cfx) && !empty($cfx['token']) && !empty($cfx['zone'])) {
                $dk = function_exists('get_option') ? get_option('wpc_cf_devkey_verified') : false;
                // v7.10.682 — ALLOWLIST, not blocklist. The old test rejected only src='probe',
                // so a src-LESS stamp (the cf-sdk deploy writer never stamped src) unlocked split
                // with no proof the deployed rules carry the device key — wpcompress served
                // mobile visitors desktop crit on exactly that gap. Split now requires the stamp
                // to POSITIVELY name a deploy readback; anything else stays device-universal.
                if (!is_array($dk) || empty($dk['devkey']) || (isset($dk['src']) ? (string) $dk['src'] : '') !== 'readback') {
                    $on = true; // stay device-universal until a readback proves the edge keys per device
                    $floorWhy = 'devkey-unproved';
                }
            }
        }
        // v7.10.801 — CF-FRONTED FLOOR. The two floors around this one both key off something we
        // OWN: the CF floor needs a token+zone in our settings, the foreign floor needs a caching
        // PLUGIN constant. A zone fronted by Cloudflare that never connected the integration, on a
        // site with no cache plugin, satisfies neither and splits onto a device-blind edge — the
        // first-device-wins bug the .568 floor exists to prevent, reached by the one path it does
        // not cover. The request itself carries the proof, so ask it rather than our settings.
        if (!$on && apply_filters('wpc_combined_crit_cf_fronted_floor', true)) {
            if (function_exists('wpc_devblind_edge') && wpc_devblind_edge()) {
                $on = true;
                $floorWhy = 'cf-fronted';
            }
        }
        // v7.10.664 — NON-CF SAFETY FLOOR (twin of the CF device-key floor above). A device-blind
        // FOREIGN page cache in front of device-split HTML is the same first-device-wins hazard as a
        // device-blind edge. When CF is not the front cache and we cannot prove the foreign cache
        // varies by device, stay device-universal. WPC's own cache is device-keyed, so it is exempt.
        if (!$on && apply_filters('wpc_combined_crit_devblind_floor', true)) {
            $cf_option = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : [];
            if (!(is_array($cf_option) && !empty($cf_option['token']) && !empty($cf_option['zone']))
                && function_exists('wpc_foreign_device_blind_cache') && wpc_foreign_device_blind_cache()) {
                $on = true;
                $floorWhy = 'foreign-blind';
            }
        }
        // Rule: device-split HTML only where the cache in front keys per device. A floor that
        // forced combined over a split the settings asked for is logged; the site's front cache
        // decides it on every render, so the line is sampled once an hour for the site.
        if ($floorWhy !== '' && function_exists('wpc_belt_receipt')) {
            wpc_belt_receipt('combined-crit-floor', ['why' => $floorWhy], true, '');
        }
        return apply_filters('wpc_combined_crit', $on);
    }


    public static function wpc_combined_both_blobs_required()
    {
        if (function_exists('apply_filters') && !apply_filters('wpc_combined_split_serve', true)) {
            return true;
        }
        // v7.10.801 — this gates the two-blob wrap, and asked only whether OUR CF edge cache was
        // on. A device-blind edge we do not own left the wrap unreachable, so the floors above
        // could resolve to combined while the branch below still wrote one device's blob. Shares
        // wpc_devblind_edge() with the decision so the two cannot diverge.
        if (function_exists('wpc_devblind_edge') && wpc_devblind_edge()) {
            return true;
        }
        $cf = function_exists('get_option') ? get_option(defined('WPS_IC_CF') ? WPS_IC_CF : 'wps-ic-cf') : [];
        return is_array($cf) && !empty($cf['token']) && !empty($cf['zone'])
            && !empty($cf['settings']['edge-cache']) && (string) $cf['settings']['edge-cache'] !== '0';
    }

    public function removeEmoji()
    {
        if (!empty(self::$emojiRemove) && self::$emojiRemove == '1') {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            // print_emoji_styles stays — content-embedded emoji imgs need core's 1em rule.
            remove_filter('the_content_feed', 'wp_staticize_emoji');
            remove_filter('comment_text_rss', 'wp_staticize_emoji');
            remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
            add_filter('emoji_svg_url', '__return_false');
            add_filter('tiny_mce_plugins', [$this, 'disable_emojicons_tinymce']);
        }
    }

    public function revSliderActive()
    {
        if (class_exists('RevSliderFront')) {
            self::$revSlider = true;
        }

        self::$revSlider = false;
    }

    public function perfMatters()
    {
        self::$perfMattersActive = false;

        //Perfmatters settings check
        if (function_exists('perfmatters_version_check')) {
            self::$perfMattersActive = self::isPerfMattersLazyActive();

            $perfmatters_options = get_option('perfmatters_options');

            if (!empty($perfmatters_options['assets']['delay_js']) && $perfmatters_options['assets']['delay_js']) {
                self::$delayJsOverride = 1;
            }

            if (!empty($perfmatters_options['assets']['defer_js']) && $perfmatters_options['assets']['defer_js']) {
                self::$deferJsOverride = 1;
            }

            if (!empty($perfmatters_options['lazyload']['lazy_loading']) && $perfmatters_options['lazyload']['lazy_loading']) {
                self::$lazyOverride = 1;
            }
        }
    }

    public static function isPerfMattersLazyActive()
    {
        if (defined('PERFMATTERS_ITEM_NAME')) {
            $options = get_option('perfmatters_options');
            if (!empty($options['lazyload']['lazy_loading'])) {
                return true;
            }
        }

        return false;
    }

    public function Brizy()
    {
        if (defined('BRIZY_VERSION')) {
            self::$brizyCache = get_option('wps_ic_brizy_cache');
            self::$brizyActive = true;
        } else {
            self::$brizyActive = false;
        }
    }

    public function disable_emojicons_tinymce($plugins)
    {
        if (is_array($plugins)) {
            return array_diff($plugins, ['wpemoji']);
        } else {
            return [];
        }
    }

    public function revSliderReplace($html)
    {
        $html = preg_replace_callback('/data-thumb=[\'|"](.*?)[\'|"]/i', [__CLASS__, 'revSlider_Replace_DataThumb'], $html);

        return $html;
    }

    public function revSlider_Replace_DataThumb($image)
    {
        $image_url = $image[1];

        // Check if it's a supported image format
        $supported_formats = ['jpg', 'jpeg', 'png', 'gif', 'svg', 'webp'];
        $extension = strtolower(pathinfo(parse_url($image_url, PHP_URL_PATH), PATHINFO_EXTENSION));

        if (!in_array($extension, $supported_formats)) {
            return $image[0];
        }

        $webp = '/wp:' . self::$webp;
        if (self::isExcludedFrom('webp', $image_url)) {
            $webp = '';
        }

        if (self::isExcludedLink($image_url) || $this->defaultExcluded($image_url) || empty($image_url)) {
            return $image[0];
        } else {
            $NewSrc = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this->getCurrentMaxWidth(1, false) . '/u:' . self::uForCdn($image_url);

            return 'data-thumb="' . $NewSrc . '"';
        }

        return $image[0];
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

        if ($setting == 'cdn') {
            // Fast string position check first, then regex if needed
            // Fix for i0.wp.com etc. image hosting
            if (strpos($link, '.wp.com') !== false && preg_match('/\bi[0-9a-zA-Z]{1,3}\.wp\.com\b/', $link)) {
                return true;
            }
        }

        return false;
    }

    public static function isExcludedLink($link)
    {
        /**
         * Is the link in excluded list?
         */
        if (empty($link)) {
            return false;
        }

        if (strpos($link, '.css') !== false || strpos($link, '.js') !== false) {
            foreach (self::$defaultExcludedList as $i => $excluded_string) {
                if (strpos($link, $excluded_string) !== false) {
                    return true;
                }
            }
        }

        if (!empty(self::$excludedList)) {
            foreach (self::$excludedList as $i => $value) {
                if (strpos($link, $value) !== false) {
                    // Link is excluded
                    return true;
                }
            }
        }

        if (self::isExcludedFrom('cdn', $link)) {
            return true;
        }

        return false;
    }

    public function defaultExcluded($string)
    {
        foreach (self::$defaultExcludedList as $i => $excluded_string) {
            if (strpos($string, $excluded_string) !== false) {
                return true;
            }
        }

        return false;
    }

    public function specialChars($url)
    {
        if (!self::$brizyActive) {
            $url = htmlspecialchars($url);
        }

        return $url;
    }

    public function fonts($html)
    {
        $html = preg_replace_callback('/https?:[^)\'\'"]+\.(woff2|woff|eot|ttf)/i', [__CLASS__, 'replaceFonts'], $html);

        return $html;
    }

    public function replaceFonts($url)
    {
        $url = $url[0];

        // Local-Fonts cache wp-cio-fonts — never subset (already subsets), never transform-lane.
        // Serve-time plain host swap to the zone only — every reservoir (sheets, carrier, crit
        // artifacts) keeps natural origin so the .32 host-heal invariant and .231 twin-heal hold;
        // this pass runs last over the full buffer so @font-face and preload swap identically.
        if (stripos($url, '/cache/wp-cio-fonts/') !== false) {
            return self::wpc_cio_font_zone_swap($url);
        }

        if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') {
            if (strpos($url, self::$zoneName) === false) {


                $f_host = wp_parse_url($url, PHP_URL_HOST);
                $f_site = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
                if (!empty($f_host) && !empty($f_site) && strcasecmp((string) $f_host, (string) $f_site) === 0
                    && stripos((string) wp_parse_url($url, PHP_URL_PATH), '/wp-content/') === false) {
                    return $url;
                }


                if (empty($f_host) || empty($f_site) || strcasecmp((string) $f_host, (string) $f_site) !== 0) {
                    return $url;
                }
                if (strpos($url, '.woff') !== false || strpos($url, '.woff2') !== false || strpos($url, '.eot') !== false || strpos($url, '.ttf') !== false) {


                    $wpc_z = (string) self::$zoneName;
                    if ($wpc_z === '' || strcasecmp($wpc_z, (string) $f_site) === 0) {
                        return $url;
                    }
                    if (strpos($url, 'icon') !== false || strpos($url, 'awesome') !== false || strpos($url, 'lightgallery') !== false || strpos($url, 'gallery') !== false || strpos($url, 'side-cart-woocommerce') !== false) {
                        $newUrl = 'https://' . $wpc_z . '/m:0/a:' . self::reformatUrl($url);
                    } else {
                        $newUrl = 'https://' . $wpc_z . '/font:true/a:' . self::reformatUrl($url);
                    }

                    return $newUrl;
                }
            }
        }

        return $url;
    }


    public static function wpc_cio_font_zone_swap($url)
    {
        $zone = (string) self::$zoneName;
        if ($zone === '' || strpos($zone, '/') !== false) {
            return $url;
        }
        if (!function_exists('home_url') || !function_exists('apply_filters')) {
            return $url;
        }
        $fontHost = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $siteHost = strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST));
        if ($fontHost === '' || $siteHost === '' || $fontHost !== $siteHost) {
            return $url;
        }
        if (!apply_filters('wpc_fonts_zone_serve', true)) {
            return $url;
        }
        return preg_replace('#^https?://[^/]+#', 'https://' . $zone, $url);
    }

    public static function cio_fonts_pass($html)
    {
        if (!is_string($html) || $html === '' || stripos($html, '/cache/wp-cio-fonts/') === false) {
            return $html;
        }
        $rewritten = preg_replace_callback('~https?://[^"\'\\)\s>]+/cache/wp-cio-fonts/[^"\'\\)\s>]+\.(?:woff2|woff|ttf)~i', function ($m) {
            return self::wpc_cio_font_zone_swap($m[0]);
        }, $html);
        return is_string($rewritten) ? $rewritten : $html;
    }

    public static function uForCdn($url, $remove_site_url = false)
    {
        $formatted = self::reformatUrl($url, $remove_site_url);
        if (empty(self::$zoneName)) return $formatted;
        $u_host = wp_parse_url($formatted, PHP_URL_HOST);
        if (!$u_host) return $formatted;
        if (strcasecmp((string) $u_host, (string) self::$zoneName) === 0) return $formatted;
        $site_host = wp_parse_url(self::$siteUrl, PHP_URL_HOST);
        if (!$site_host || strcasecmp((string) $u_host, (string) $site_host) !== 0) return $formatted;
        return preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $formatted);
    }

    /** Per-request cache for recoverAdaptiveVariant() globs — keyed "base_path|ext" → file list. */
    private static $variantGlobCache = [];


    /**
     * Measured dimensions of a same-site local raster image (['width' => w, 'height' => h]), or
     * null when unreadable. The read itself is the image-sizing owner's one bounded, receipted
     * file read (wps_ic_image_sizing::measuredRaster), so the rung caps, the placeholder minter
     * and the AVIF aspect check share its per-request memo and its cap instead of each opening
     * the file. An SVG answers null here, as getimagesize() always did for these callers.
     */
    public static function wpc_true_image_dimensions($url)
    {
        if (!is_string($url) || $url === '' || !class_exists('wps_ic_image_sizing')) {
            return null;
        }
        return wps_ic_image_sizing::measuredRaster($url);
    }

    private static function natural_ladder_url($base_no_ext, $width, $aspect_meta, $ext)
    {
        if (preg_match('/-\d{1,5}x\d{1,5}$/', (string) $base_no_ext)) {
            return $base_no_ext . '.' . $ext;
        }
        $suffix = function_exists('wpc_v2_adaptive_variant_suffix')
            ? wpc_v2_adaptive_variant_suffix($width, $aspect_meta)
            : '-' . (int) $width . 'w';
        return $base_no_ext . $suffix . '.' . $ext;
    }


    // (build_natural_url) and modern-delivery (build_srcset_for_format) call it too, so the "Source Hints"


    public static function src_hint_enabled()
    {


        return self::src_hint_mode() !== 'off';
    }


    public static function src_hint_mode()
    {
        $set  = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array();
        $raw  = (is_array($set) && isset($set['emit-src-hints'])) ? (string) $set['emit-src-hints'] : '1';
        $on   = ($raw !== '0' && $raw !== '' && strtolower($raw) !== 'off');
        // v7.20.15 — default flipped 'until' -> 'always' (service ask): a hint on EVERY
        // rewritten URL lets the edge skip its ~1.5s probe ladder even for on-disk
        // variants. 'until' remains reachable: emit-src-hints-until option or the
        // wpc_src_hint_mode filter.
        $mode = !$on ? 'off' : ((is_array($set) && !empty($set['emit-src-hints-until'])) ? 'until' : 'always');
        if (function_exists('apply_filters')) {
            $mode = (string) apply_filters('wpc_src_hint_mode', $mode);
            if (apply_filters('wpc_src_hint_enabled', true) === false) {
                $mode = 'off';
            }
        }
        return in_array($mode, array('off', 'until', 'always'), true) ? $mode : 'until';
    }


    /**
     * v7.20.17 — A LATER IDENTICAL DUPLICATE ONLY EVER POISONS THE CASCADE. The artifact's
     * append pass (wpc-mutproof stamp) re-serializes rules WITHOUT their @media context:
     * vincire's crit carried .grid-cols{...minmax(0,1fr)} early, the ≥576/≥1025 column
     * steps, then the appended bare copy (minmax(0, 1fr) serialization) AFTER them —
     * same specificity, later order, responsive grid dead until the theme sheet arrives
     * (LCP card at 1392px for ~3s, CDP matched-rules receipt). A rule whose selector and
     * declarations EXACTLY match an earlier unconditional rule contributes nothing but
     * that order poisoning — dropping the later copy is semantics-preserving by
     * construction. Only unconditional contexts (top level / @media all) are deduped;
     * anything under a real @media/@supports/@container is untouched; rules carrying
     * quotes are skipped (quoted strings are the one place whitespace is meaning).
     */
    // v7.21.194 — BAD-URL REPAIR. A customer-authored url(data:...) with RAW QUOTES and
    // inner parens is invalid CSS (bad-url token): Blink's recovery consumes to the FIRST
    // close-paren, the orphaned quote then opens a runaway string, and every rule until the
    // next apostrophe is eaten from the CSSOM while still present in the text (rosario:
    // 230 of 369 blocks parsed, .rs-btn computed display:inline, 4.3s unstyled hero — the
    // crit-team P0's real breaker; the fallback splice was only the first benign diff).
    // Verbatim carriage protects VALID bytes; bytes Blink cannot parse are repaired to the
    // author's evident intent: the body quoted, internal double-quotes %-escaped. data:
    // bodies only (bounded); already-quoted and plain urls untouched; idempotent.
    public static function wpc_css_requote_urls($css)
    {
        try {
            if (!is_string($css) || $css === '' || stripos($css, 'url(') === false) {
                return $css;
            }
            $out = '';
            $pos = 0;
            $len = strlen($css);
            $fixed = 0;
            while (($i = stripos($css, 'url(', $pos)) !== false) {
                $b = $i + 4;
                while ($b < $len && ($css[$b] === ' ' || $css[$b] === "\t")) {
                    $b++;
                }
                if ($b >= $len || $css[$b] === '"' || $css[$b] === "'"
                    || strtolower(substr($css, $b, 5)) !== 'data:') {
                    $out .= substr($css, $pos, $b - $pos);
                    $pos = $b;
                    continue;
                }
                $d = 1;
                $j = $b;
                while ($j < $len && $d > 0) {
                    if ($css[$j] === '(') {
                        $d++;
                    } elseif ($css[$j] === ')') {
                        $d--;
                        if ($d === 0) {
                            break;
                        }
                    }
                    $j++;
                }
                if ($d !== 0) {
                    $out .= substr($css, $pos, $b - $pos);
                    $pos = $b;
                    continue;
                }
                $body = substr($css, $b, $j - $b);
                if (strpos($body, "'") === false && strpos($body, '"') === false
                    && !preg_match('/\s/', $body) && strpos($body, '(') === false) {
                    $out .= substr($css, $pos, $j + 1 - $pos);
                    $pos = $j + 1;
                    continue;
                }
                $out .= substr($css, $pos, $b - $pos) . '"' . str_replace('"', '%22', $body) . '")';
                $pos = $j + 1;
                $fixed++;
            }
            $out .= substr($css, $pos);
            if ($fixed > 0 && function_exists('wpc_cache_first_log') && function_exists('get_transient')
                && !get_transient('wpc_requote194_logged')) {
                set_transient('wpc_requote194_logged', 1, 3600);
                wpc_cache_first_log('css-url-requoted', '', '', ['n' => $fixed]);
            }
            return $out;
        } catch (\Throwable $e) {
            return $css;
        }
    }

    public static function wpc_prune_duplicate_css_rules($css)
    {
        try {
            if (!is_string($css) || $css === '' || strpos($css, '{') === false
                || !apply_filters('wpc_crit_dupe_prune', true)) {
                return $css;
            }
            $len = strlen($css); $out = ''; $seen = []; $ctx = []; $i = 0; $dropped = 0;
            while ($i < $len) {
                $ob = strpos($css, '{', $i);
                $cb = strpos($css, '}', $i);
                if ($cb !== false && ($ob === false || $cb < $ob)) {
                    $out .= substr($css, $i, $cb - $i + 1);
                    array_pop($ctx);
                    $i = $cb + 1;
                    continue;
                }
                if ($ob === false) { $out .= substr($css, $i); break; }
                $head = substr($css, $i, $ob - $i);
                if (ltrim($head) !== '' && substr(ltrim($head), 0, 1) === '@') {
                    $atRule = strtolower((string) preg_replace('/\s+/', '', $head));
                    // block-less at-rules (@import/@charset ...;) never reach here with '{' first
                    $out .= substr($css, $i, $ob - $i + 1);
                    $ctx[] = $atRule;
                    $i = $ob + 1;
                    continue;
                }
                $end = strpos($css, '}', $ob);
                if ($end === false) { $out .= substr($css, $i); break; }
                $rule = substr($css, $i, $end - $i + 1);
                $uncond = true;
                foreach ($ctx as $c) {
                    if ($c !== '@mediaall' && $c !== '@media' ) { $uncond = false; break; }
                }
                if ($uncond && strpos($rule, '"') === false && strpos($rule, "'") === false) {
                    $selN  = trim((string) preg_replace('/\s+/', ' ', substr($rule, 0, $ob - $i)));
                    $declN = (string) preg_replace('/\s+/', '', substr($rule, $ob - $i));
                    $key   = $selN . '|' . $declN;
                    if (isset($seen[$key])) { $dropped++; $i = $end + 1; continue; }
                    $seen[$key] = 1;
                }
                $out .= $rule;
                $i = $end + 1;
            }
            if ($dropped > 0 && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('crit-dupe-pruned', '', '', ['n' => $dropped]);
            }
            return $out;
        } catch (\Throwable $e) {
            return $css;
        }
    }

    public static function src_hint_qs($src_ext, $on_disk = false)
    {
        if ($src_ext === '') return '';
        $mode = self::src_hint_mode();
        if ($mode === 'off') return '';
        if ($mode === 'until' && $on_disk) return '';
        return '?src=' . $src_ext;                     // 'until' (not-on-disk) or 'always'
    }

    public static function wpc_is_attachment_recorded($url)
    {
        // v7.20.18 — a bare-full next-gen guess (name.avif / name.webp with no width rung)
        // is only emitted for an attachment whose optimization record proves the twin exists:
        // ic_status=compressed, or the twin bytes already on local disk. Unrecorded falls to
        // the <picture>'s <img> original natural URL — one 200, zero redirects, no cold mint.
        static $recordedCache = [];
        if (apply_filters('wpc_twin_record_gate', true) === false) {
            return true;
        }
        $u = preg_replace('/[?#].*$/', '', (string) $url);
        $u = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $u);
        $u = preg_replace('/-\d+w(\.[a-z0-9]+)$/i', '$1', $u);
        if ($u === '' || $u === null) {
            return false;
        }
        if (array_key_exists($u, $recordedCache)) {
            return $recordedCache[$u];
        }
        $ok = false;
        $id = (int) self::wpc_att_id($u);
        if ($id > 0 && function_exists('get_post_meta')
            && get_post_meta($id, 'ic_status', true) === 'compressed') {
            $ok = true;
        }
        if (!$ok) {
            $site = function_exists('site_url') ? untrailingslashit(site_url()) : '';
            if ($site !== '' && strpos($u, $site . '/') === 0) {
                $rel = substr($u, strlen($site) + 1);
                if (strpos($rel, '..') === false) {
                    $twin = self::swap_ext_to(ABSPATH . $rel, 'webp');
                    $ok = ($twin !== ABSPATH . $rel) && @is_file($twin);
                }
            }
        }
        $recordedCache[$u] = $ok;
        return $ok;
    }

    private static function recoverAdaptiveVariant($natural_url, $base_no_ext, $width, $ext)
    {
        $site = trailingslashit(site_url());
        $path = str_replace($site, trailingslashit(ABSPATH), $natural_url);
        if (@is_file($path)) {
            return [$natural_url, $path];
        }
        $width = (int) $width;
        if ($width <= 0 || $base_no_ext === '' || $base_no_ext === null) {
            return [$natural_url, $path];
        }
        $base_path = str_replace($site, trailingslashit(ABSPATH), $base_no_ext);
        $base_name = basename($base_path);
        $key = $base_path . '|' . $ext;
        if (!isset(self::$variantGlobCache[$key])) {
            // Escape glob metacharacters in the literal base ([ ] ? * { } are legal in WP filenames);
            // the trailing "-*" is the intentional wildcard.
            $pattern = preg_replace('/([*?\[\]{}])/', '[$1]', $base_path) . '-*.' . $ext;
            $g = @glob($pattern);
            self::$variantGlobCache[$key] = is_array($g) ? $g : [];
        }


        $stem   = preg_quote($base_name, '/');
        $eq     = preg_quote($ext, '/');
        $re_xh  = '/^' . $stem . '-' . $width . 'x\d+\.' . $eq . '$/';
        $re_w   = '/^' . $stem . '-' . $width . 'w\.' . $eq . '$/';
        $legacy = null;
        foreach (self::$variantGlobCache[$key] as $f) {
            $bn = basename((string) $f);
            if (preg_match($re_xh, $bn)) {
                return [trailingslashit(dirname($natural_url)) . $bn, $f];
            }
            if ($legacy === null && preg_match($re_w, $bn)) {
                $legacy = [trailingslashit(dirname($natural_url)) . $bn, $f];
            }
        }
        if ($legacy !== null) {
            return $legacy;
        }
        return [$natural_url, $path];
    }

    public static function reformatUrl($url, $remove_site_url = false)
    {
        $url = trim($url);

        // Check if url is maybe a relative URL (no http or https)
        if (strpos($url, 'http') === false) {
            // Check if url is maybe absolute but without http/s
            if (strpos($url, '//') === 0) {
                // Just needs http/s
                $url = 'https:' . $url;
            } else {
                $url = str_replace('../wp-content', 'wp-content', $url);
                $url_replace = str_replace('/wp-content', 'wp-content', $url);
                $url = self::$siteUrl;
                $url = rtrim($url, '/');
                $url .= '/' . $url_replace;
            }
        }

        $formatted_url = $url;

        if (strpos($formatted_url, '?brizy_media') === false && strpos($formatted_url, '?resize') === false) {
            // Self-versioned artifacts (used-css ?uv=mtime) must keep their buster —
            // a re-store under the unchanged global icv would pin stale copies at the edge.
            $used_css_version = '';
            if (strpos($formatted_url, '/used-css/') !== false && preg_match('/[?&](uv=\d+)/', $formatted_url, $uv_match)) {
                $used_css_version = $uv_match[1];
            }
            $formatted_url = explode('?', $formatted_url);
            $formatted_url = $formatted_url[0];
            if ($used_css_version !== '') {
                $formatted_url .= '?' . $used_css_version;
            }
        }

        if ($remove_site_url) {
            $formatted_url = str_replace(self::$siteUrl, '', $formatted_url);
            $formatted_url = str_replace(str_replace(['https://', 'http://'], '', self::$siteUrl), '', $formatted_url);
            $formatted_url = str_replace(addcslashes(self::$siteUrl, '/'), '', $formatted_url);
            $formatted_url = ltrim($formatted_url, '\/');
            $formatted_url = ltrim($formatted_url, '/');
        }

        if (!empty(self::$cdnEnabled) && self::$cdnEnabled == '1') {
            if (self::$randomHash == 0 && (strpos($formatted_url, '.css') !== false)) {
                $formatted_url .= (strpos($formatted_url, '?') === false ? '?' : '&') . 'icv=' . wps_cdn_rewrite::asset_version($url);
            }

            if (self::$randomHash == 0 && strpos($formatted_url, '.js') !== false) {
                $formatted_url .= (strpos($formatted_url, '?') === false ? '?' : '&') . 'js_icv=' . wps_cdn_rewrite::asset_version($url);
            }
        }

        return $formatted_url;
    }


    public static function zone_is_cf()
    {
        if (!empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR'])) return true;
        if (function_exists('get_option') && get_option('wpc_v2_cf_assets_seen', 0)) return true;
        if (function_exists('get_option')) {
            $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
            $cfCname = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
            if (is_array($cf) && !empty($cf['settings']['cdn']) && $cfCname !== '') return true;
        }
        return false;
    }

    /**
     * Is CloudFlare the ACTIVE delivery CDN — cdn on + a (verified) cname, i.e. images emit to the CF
     * cname host, not the Bunny zone. Unlike zone_is_cf(), this does NOT trip on the CF-RAY header (the
     * origin merely sitting behind CF) — that false-positive let GIFs ride the Bunny zone. GIF routing
     * reads THIS, so a GIF only ever rides a true CF-direct zone, never Bunny.
     */
    public static function cf_is_delivery()
    {
        if (!function_exists('get_option')) return false;
        $cf = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
        if (!is_array($cf) || empty($cf['settings']['cdn'])) return false;
        $cfCname = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
        if ($cfCname === '') return false;
        return !function_exists('wpc_cf_cname_verified_ok') || wpc_cf_cname_verified_ok();
    }


    private static function asset_mime_proven()
    {
        if ((bool) apply_filters('wpc_natural_assets_on_cf', false)) return true;
        if ((string) get_option('wpc_natural_force', '') === '1') return true;
        if ((string) get_option('wpc_v2_cf_asset_mime_ok', '') !== '1') return false;
        // A proof is a measurement with an age, not a permanent licence. A zone that stops serving
        // natural paths (custom hostname unregistered, origin pull dead) must be able to revoke it.
        // Stale => kick the NON-BLOCKING re-probe and keep serving the proven shape until the probe
        // DISPROVES it, so the emitted URL shape never flaps on probe latency.
        $proofTimestamp  = (int) get_option('wpc_v2_cf_asset_mime_ts', 0);
        $proofTtl = (int) apply_filters('wpc_natural_proof_ttl', 12 * HOUR_IN_SECONDS);
        if ($proofTtl > 0 && (time() - $proofTimestamp) > $proofTtl) {
            self::maybe_reprove_asset_mime();
        }
        return true;
    }


    private static function maybe_reprove_asset_mime()
    {
        if (function_exists('get_transient') && get_transient('wpc_v2_cf_asset_reprobe') !== false) return;
        if (function_exists('set_transient')) {
            set_transient('wpc_v2_cf_asset_reprobe', 1, (int) apply_filters('wpc_natural_reprobe_throttle', 1800));
        }
        if ((is_admin() || (defined('DOING_CRON') && DOING_CRON)) && function_exists('wpc_v2_asset_mime_probe_run')) {
            wpc_v2_asset_mime_probe_run();
            return;
        }
        self::fire_asset_mime_probe_loopback();
    }


    public static function invalidate_asset_mime_proof()
    {
        if (function_exists('delete_option'))    delete_option('wpc_v2_cf_asset_mime_ok');
        if (function_exists('delete_transient')) {
            delete_transient('wpc_v2_cf_asset_mime_retry');
            delete_transient('wpc_v2_asset_probe_inflight');
        }
    }

    private static function maybe_probe_asset_mime()
    {


        $verdict = get_option('wpc_v2_cf_asset_mime_ok', false);
        if ($verdict === '1') return true;
        if (get_transient('wpc_v2_cf_asset_mime_retry') !== false) return false;

        // Probe context: admin/cron OR a cold live-CDN front-end render (instant proof) on a CF zone

        // CDN-off, no zone, suppressed, or a non-render context.
        $is_admin_cron  = is_admin() || (defined('DOING_CRON') && DOING_CRON);
        $na_s           = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : null;
        $cdn_live       = is_array($na_s) && !empty($na_s['live-cdn']) && (string) $na_s['live-cdn'] === '1';
        $zone_set       = is_string(self::$zoneName) && trim(self::$zoneName) !== '';
        $not_suppressed = !(function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed());
        $cold_frontend  = $cdn_live && $zone_set && $not_suppressed
            && !(defined('WPC_IS_BG_SWAP') && WPC_IS_BG_SWAP)
            && !(defined('DOING_AJAX') && DOING_AJAX)
            && !(defined('REST_REQUEST') && REST_REQUEST)
            && !(defined('WP_CLI') && WP_CLI)
            && !is_feed();
        if (!$is_admin_cron && !$cold_frontend) return false;
        // In-flight lock: exactly ONE caller runs the ≤3s GET per zone per window; concurrent cold
        // renders return false → emit the origin floor (safe) with zero added latency.
        if (get_transient('wpc_v2_asset_probe_inflight')) return false;
        set_transient('wpc_v2_asset_probe_inflight', 1, 15);


        $probe_zone = (is_string(self::$zoneName) && trim(self::$zoneName) !== '')
            ? preg_replace('#/.*$#', '', trim((string) self::$zoneName))
            : '';
        if ($probe_zone === '') {
            $cf_cname  = defined('WPS_IC_CF_CNAME') ? trim((string) get_option(WPS_IC_CF_CNAME, '')) : '';
            $cf_set    = defined('WPS_IC_CF') ? get_option(WPS_IC_CF) : false;
            $cf_cdn_on = is_array($cf_set) && !empty($cf_set['settings']['cdn']);
            $probe_zone = ($cf_cname !== '' && $cf_cdn_on)
                ? $cf_cname
                : (trim((string) get_option('ic_custom_cname', '')) ?: (string) get_option('ic_cdn_zone_name', ''));
        }
        if ($probe_zone === '') { delete_transient('wpc_v2_asset_probe_inflight'); return false; }


        if ($is_admin_cron) {
            if (function_exists('wpc_v2_asset_mime_probe_run')) {
                return wpc_v2_asset_mime_probe_run($probe_zone);
            }
            delete_transient('wpc_v2_asset_probe_inflight');
            return false;
        }
        // COLD FRONT-END visitor render: NEVER block the render with the 3s GET. Fire a non-blocking
        // loopback (the same fire-and-forget transport the CDN-liveness probe uses) so the admin-ajax


        self::fire_asset_mime_probe_loopback();
        return false;
    }


    private static function fire_asset_mime_probe_loopback()
    {
        if (function_exists('wpc_render_guard_active') && wpc_render_guard_active() && function_exists('wpc_net_defer')) {
            wpc_net_defer('mimeprobe', function () { self::fire_asset_mime_probe_loopback(); });
            return;
        }
        if (!function_exists('admin_url') || !function_exists('wp_create_nonce') || !class_exists('wps_ic_ajax')
            || !method_exists('wps_ic_ajax', 'wpc_loopback_open_socket')) return;
        $lvp = function_exists('wp_parse_url') ? wp_parse_url(admin_url('admin-ajax.php')) : null;
        if (empty($lvp['host'])) return;
        $lv_https = (!empty($lvp['scheme']) && $lvp['scheme'] === 'https');
        $lv_port  = !empty($lvp['port']) ? (int) $lvp['port'] : ($lv_https ? 443 : 80);
        $lv_host  = (string) $lvp['host'];
        $lv_path  = (!empty($lvp['path']) ? $lvp['path'] : '/') . '?action=wpc_asset_mime_probe';
        $lv_body  = http_build_query(['nonce' => wp_create_nonce('wpc_asset_mime')]);
        $lv_req   = "POST {$lv_path} HTTP/1.1\r\nHost: {$lv_host}\r\nContent-Type: application/x-www-form-urlencoded\r\n"
                  . "Content-Length: " . strlen($lv_body) . "\r\nConnection: close\r\nUser-Agent: WPCAssetMime/1.0\r\n\r\n" . $lv_body;
        $lv_fp = wps_ic_ajax::wpc_loopback_open_socket($lv_host, $lv_port, $lv_https, 0.2);
        if ($lv_fp) { @stream_set_timeout($lv_fp, 0, 100000); @fwrite($lv_fp, $lv_req); @fclose($lv_fp); }
    }

    public static function natural_assets_on()
    {
        // Per-request memo: this gate is called ~30x/render (cdn-rewrite + rewriteLogic) and is a pure


        static $na_cache = null;
        if ($na_cache !== null) return $na_cache;
        if (!class_exists('WPC_Negotiated_Delivery') || !method_exists('WPC_Negotiated_Delivery', 'emission_ready')) {
            return false;
        }
        $na_cache = (bool) self::natural_assets_on_uncached();
        return $na_cache;
    }

    public static $naturalAssetsRefusalReason = '';

    public static function wpc_natural_assets_reason()
    {
        $naturalOn = self::natural_assets_on();
        if ($naturalOn) {
            return 'natural';
        }
        return self::$naturalAssetsRefusalReason !== '' ? self::$naturalAssetsRefusalReason : 'off';
    }

    private static function natural_assets_on_uncached()
    {
        // Every refusal names its gate (read back through wpc_natural_assets_reason -> the wpc-nat
        // Server-Timing header): "why is this site still on transform URLs" must be one
        // DevTools look, never a support back-and-forth over invisible option state.
        if (!class_exists('WPC_Negotiated_Delivery') || !method_exists('WPC_Negotiated_Delivery', 'emission_ready')) {
            self::$naturalAssetsRefusalReason = 'no-negotiated-class';
            return false;
        }
        // Kill switch: WPC_NEGOTIATED_KILL is the single off-ramp for the whole next-gen system, so it
        // must cut the css/js/font naturalization path too (not just image negotiation).
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) {
            self::$naturalAssetsRefusalReason = 'kill-switch';
            return false;
        }

        $off_reason = '';
        if (function_exists('get_option') && defined('WPS_IC_SETTINGS')) {
            $na_bunny_s    = get_option(WPS_IC_SETTINGS);
            $na_bunny_zone = is_string(self::$zoneName) ? trim(self::$zoneName) : '';
            $na_bunny_live = is_array($na_bunny_s) && !empty($na_bunny_s['live-cdn']) && (string) $na_bunny_s['live-cdn'] === '1';
            if (!$na_bunny_live) {
                $off_reason = 'live-cdn-off';
            } elseif ($na_bunny_zone === '') {
                $off_reason = 'no-zone';
            } elseif (function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed()) {
                $off_reason = 'zone-suppressed';
            } elseif (self::zone_is_cf()) {
                $off_reason = 'zone-is-cf';
            } else {
                $na_bunny_origin = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
                $na_bunny_zh     = preg_replace('#/.*$#', '', $na_bunny_zone);
                if ($na_bunny_origin && strcasecmp($na_bunny_zh, (string) $na_bunny_origin) !== 0) {
                    if (apply_filters('wpc_natural_assets_enabled', true)) {
                        return true; // Bunny → natural, no probe
                    }
                    self::$naturalAssetsRefusalReason = 'filter-off';
                    return false;
                }
                $off_reason = 'zone-equals-origin';
            }
        }


        $cf_now_na = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']);
        if ($cf_now_na && !get_option('wpc_v2_cf_assets_seen', 0)) {
            update_option('wpc_v2_cf_assets_seen', time(), true);
        }
        if (($cf_now_na || get_option('wpc_v2_cf_assets_seen', 0))
            && !apply_filters('wpc_natural_assets_on_cf', false)) {


            if (self::asset_mime_proven()) {

            } else {


                if (self::maybe_probe_asset_mime()) {
                    return self::natural_assets_on();
                }
                self::$naturalAssetsRefusalReason = 'cf-mime-unproven';
                return false;
            }
        }


        $zone_ok = false;
        if (WPC_Negotiated_Delivery::emission_ready()) {
            $zone_ok = true;
        } elseif (class_exists('WPC_Delivery_Resolver') && method_exists('WPC_Delivery_Resolver', 'resolve_verbose')) {
            $rv_na = WPC_Delivery_Resolver::resolve_verbose();
            $zone_ok = !empty($rv_na['verify']['cdn']['ok']);
        }


        if ($zone_ok && self::zone_is_cf()) {
            $na_css_proven = self::asset_mime_proven();
            if (!$na_css_proven) {
                $zone_ok = false;
            }
        }


        if (!$zone_ok) {


            if (!self::asset_mime_proven() && self::maybe_probe_asset_mime()) {
                return self::natural_assets_on();
            }
            $na_mime_proven = self::asset_mime_proven();
            if ($na_mime_proven) {
                $na_s    = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : null;
                $na_zone = is_string(self::$zoneName) ? trim(self::$zoneName) : '';
                if ($na_zone !== ''
                    && is_array($na_s) && !empty($na_s['live-cdn']) && (string) $na_s['live-cdn'] === '1' // CDN is live
                    && !(function_exists('wpc_v2_zone_cdn_suppressed') && wpc_v2_zone_cdn_suppressed())) {
                    $na_origin = function_exists('home_url') ? wp_parse_url(home_url(), PHP_URL_HOST) : '';
                    if ($na_origin && strcasecmp($na_zone, (string) $na_origin) !== 0) {
                        $zone_ok = true;
                    }
                }
            }
        }
        if (!$zone_ok) {
            self::$naturalAssetsRefusalReason = $off_reason !== '' ? $off_reason : 'zone-unverified';
            return false;
        }
        if (!apply_filters('wpc_natural_assets_enabled', true)) {
            self::$naturalAssetsRefusalReason = 'filter-off';
            return false;
        }
        return true;
    }


    public static function avif_natural_source_ok()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;


        if (function_exists('wpc_force_natural') && wpc_force_natural()) {
            return (bool) apply_filters('wpc_avif_natural_source_ok', true);
        }
        // avif?src= is an OTF mint but it rides the zone's NATURAL routing layer — a pod that
        // 404s natural statics 404s the mint identically (both proven on the same zone). Same
        // witness, same stand-down.
        if (!self::natural_assets_on()) {
            return false;
        }
        $s = self::$settings;
        if (!is_array($s) || empty($s['avif-natural-source']) || (string) $s['avif-natural-source'] !== '1') {
            return false;
        }


        $nav = class_exists('WPC_Delivery_Resolver') ? WPC_Delivery_Resolver::orch_nav_signal() : null;
        if ($nav === true) {


            $cf = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']) || get_option('wpc_v2_cf_assets_seen', 0);
            $ok = $cf ? (bool) get_option('wpc_v2_cf_avif_live', 1) : true;
        } elseif ($nav === false) {
            $ok = false;
        } else {


            $cf = !empty($_SERVER['HTTP_CF_RAY']) || !empty($_SERVER['HTTP_CF_VISITOR']) || get_option('wpc_v2_cf_assets_seen', 0);
            $ok = $cf ? (bool) get_option('wpc_v2_cf_avif_live', 1) : true; // CF default-on but flippable; Bunny optimistic
        }
        return (bool) apply_filters('wpc_avif_natural_source_ok', $ok);
    }


    /**
     * Whether the CDN's natural lane can serve an image whose ORIGINAL has this extension, i.e.
     * whether a natural zone URL (same stem, any extension) may be emitted for it.
     *
     * Rule: an AVIF original is not a source the CDN accepts, so it keeps its own origin URL.
     * The CDN resolves a natural URL's source only among png/jpg/jpeg/webp (the `?src=` hint
     * check and the origin probe list in cdn-mc, `routes/natural.js` and
     * `utils/siblingFallback.js`, read at a0e7fc4 on 2026-09-24), and its never-404 fallback
     * then guesses an extension. Observed on noktaltema.com/tibet/teknikservis (2026-09-24,
     * every upload an .avif): Negotiated Delivery emitted
     * `<zone>/tibet/teknikservis/wp-content/uploads/2026/09/banner-gorsel.webp`, the zone
     * answered 302 to `…/banner-gorsel.jpg?wpc_o=1` and the origin 404; the `.avif` natural URL
     * on the same zone did the same. When the CDN learns to take an avif source, turn the
     * `wpc_natural_lane_takes_avif_source` filter on (the `?src=avif` hint is already emitted).
     */
    public static function natural_lane_takes_source($origin_ext)
    {
        $origin_ext = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $origin_ext));
        if ($origin_ext !== 'avif') return true;
        return function_exists('apply_filters') && (bool) apply_filters('wpc_natural_lane_takes_avif_source', false);
    }

    public static function picture_natural_fleet_enabled()
    {
        $opt = function_exists('get_option') ? get_option('wpc_picture_natural_fleet', 1) : 1;
        // Fleet default assumed every pod serves natural paths; a legacy pod disproved it (every
        // avif?src= source 404'd). The fleet flag now needs the same per-zone witness the CSS lane
        // has always had; wpc_force_natural stays the operator override.
        $naturalWitnessed = (function_exists('wpc_force_natural') && wpc_force_natural())
            || self::natural_assets_on();
        return (bool) apply_filters('wpc_picture_natural_fleet', !empty($opt) && $naturalWitnessed);
    }


    public static function picture_avif_natural_ok()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;

        $ok = self::picture_natural_fleet_enabled() ? true : self::avif_natural_source_ok();
        return (bool) apply_filters('wpc_picture_avif_natural_ok', $ok);
    }


    public static function picture_source_srcset_attr($build_image_tag)
    {
        // v7.21.254 — the natural short-circuit predates the true viewport park: it forced
        // live srcset on every <source> while vp227 parked only the <img>, so the preload
        // scanner fetched every below-fold AVIF at parse time (bestexteriorsinc: 70 live
        // sources, 24 parser-initiated image rows on a no-gesture load). A parked img
        // parks its sources regardless of URL shape; the pixel + belt restore both.
        $img_is_lazy = is_string($build_image_tag)
            && (strpos($build_image_tag, 'data-src=') !== false || strpos($build_image_tag, 'data-srcset=') !== false);
        if (!$img_is_lazy) return 'srcset';
        $on = (bool) apply_filters('wpc_picture_avif_lazy_source',
            (bool) (function_exists('get_option') ? get_option('wpc_picture_avif_lazy_source', 1) : 1));
        return $on ? 'data-srcset' : 'srcset';
    }


    public static function picture_avif_emit_natural()
    {
        // KILL reverts everything — every arm that ANDs this falls to wp:2/witness-floor.
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;


        if (self::picture_natural_fleet_enabled()) {
            return (self::$pictureAvifEnabled === true) && (self::$zoneName !== '');
        }
        // Operator override (default ON). A zone with a known-broken edge flips this off.
        $all_zones = (bool) apply_filters('wpc_picture_avif_all_zones',
            (bool) (function_exists('get_option') ? get_option('wpc_picture_avif_all_zones', 1) : 1));
        if ($all_zones) {


            if (class_exists('WPC_Delivery_Resolver')
                && WPC_Delivery_Resolver::orch_nav_signal() === false) {
                return false;
            }
            // Ceiling on (encoded in $pictureAvifEnabled) AND a real CDN-on zone. The caller's per-rung
            // -WxH gate confines this to the never-404 URL form; -Nw / bare-full are decided elsewhere.
            return (self::$pictureAvifEnabled === true) && (self::$zoneName !== '');
        }
        // Operator opted out → the proven per-zone witness.
        return self::picture_avif_natural_ok();
    }


    public static function avif_single_pathpart($avifUrl, $avifZoneBase, $avifSiteHost)
    {
        $avifUrl = (string) $avifUrl;

        if ($avifZoneBase !== '' && strpos($avifUrl, $avifZoneBase) === 0) {
            return substr($avifUrl, strlen($avifZoneBase));
        }
        // (2) Origin-hosted (the canonical theme-emitted case) → strip the known origin host.
        if ($avifSiteHost !== '' && strpos($avifUrl, $avifSiteHost) === 0) {
            return substr($avifUrl, strlen($avifSiteHost));
        }


        return preg_replace('#^https?://[^/]+#', '', $avifUrl);
    }


    public static function picture_avif_natural_full_ok()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        return (bool) apply_filters('wpc_picture_avif_natural_full_ok', self::avif_natural_source_ok());
    }

    public static function picture_webp_natural_ok()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;


        $ok = self::picture_natural_fleet_enabled() ? true : (class_exists('wps_cdn_rewrite') && wps_cdn_rewrite::wpc_webp_immediate_ok());
        return (bool) apply_filters('wpc_picture_webp_natural_ok', $ok);
    }


    public static function wpc_webp_otf_ready()
    {
        $opt = function_exists('get_option') ? get_option('wpc_webp_otf_ready', 0) : 0;
        return (bool) apply_filters('wpc_webp_otf_ready', !empty($opt));
    }


    public static function wpc_natural_nw()
    {
        // Default ON (the converged path; E1–E6 proven on a live CF/Laravel zone). Set option/filter
        // wpc_natural_nw=0 to revert a zone to the legacy /q: transforms.
        $opt = function_exists('get_option') ? get_option('wpc_natural_nw', 1) : 1;
        return (bool) apply_filters('wpc_natural_nw', !empty($opt));
    }


    public static function wpc_nw_url($src_url, $width, $fmt, $aspect_meta = null)
    {


        $base = preg_replace('/[?#].*$/', '', (string) $src_url);
        $base = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $base);
        $base = preg_replace('/-\d+w(\.[a-z0-9]+)$/i', '$1', $base);
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $base);
        $base = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $base);
        if (function_exists('wpc_v2_adaptive_variant_suffix') && is_array($aspect_meta)
            && !empty($aspect_meta['width']) && !empty($aspect_meta['height'])) {
            $suffix = wpc_v2_adaptive_variant_suffix((int) $width, $aspect_meta);   // -WxH (matches landed) or -Nw
        } else {
            $suffix = '-' . (int) $width . 'w';
        }
        return $base . $suffix . '.' . $fmt;
    }


    public static function wpc_natural_full_url($src_url, $fmt)
    {
        $base = preg_replace('/[?#].*$/', '', (string) $src_url);
        $base = preg_replace('/-\d+x\d+(\.[a-z0-9]+)$/i', '$1', $base);
        $base = preg_replace('/-\d+w(\.[a-z0-9]+)$/i', '$1', $base);
        $base = preg_replace('/\.[a-z0-9]+$/i', '', $base);
        $base = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $base);
        return $base . '.' . $fmt;
    }


    public static function swap_ext_to($url, $fmt)
    {
        return preg_replace('/\.(jpe?g|png)(?=[?#]|$)/i', '.' . $fmt, (string) $url);
    }


    public static function swap_ext_in_tag($tag, $fmt)
    {
        return preg_replace('/\.(jpe?g|png)(?=["\'\s?#)>])/i', '.' . $fmt, (string) $tag);
    }


    public static function legacy_upload_host($html, $zone, $site, $home = '')
    {
        $zone = strtolower(trim((string) $zone));
        $host = strtolower((string) wp_parse_url((string) $site, PHP_URL_HOST));
        if ($zone === '' || $host === '' || !is_string($html) || strpos($html, '/u:') === false) {
            return $html;
        }
        $bare = preg_replace('/^www\./', '', $host);
        $hbare = preg_replace('/^www\./', '', strtolower((string) wp_parse_url((string) $home, PHP_URL_HOST)));
        $rehosted = 0;
        $out = preg_replace_callback(
            '#(https?://' . preg_quote($zone, '#') . '/[^\s"\'<>]*?/u:)https?://([^/\s"\'<>]+)(/wp-content/[^\s"\'<>]*)#i',
            function ($m) use ($zone, $host, $bare, $hbare, &$rehosted) {
                $h = strtolower(preg_replace('/:\d+$/', '', $m[2]));
                $hb = preg_replace('/^www\./', '', $h);
                if ($h === $host || $h === $zone || $hb === $bare || ($hbare !== '' && $hb === $hbare)) {
                    return $m[0];
                }
                $rehosted++;
                return $m[1] . 'https://' . $host . $m[3];
            },
            $html
        );
        // A zone url names an old or cloned host inside /u:, from the site's migrated content.
        // Sampled: the stored content prints the same urls on every render.
        if ($rehosted > 0 && is_string($out) && $out !== '' && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('legacy-upload-host', ['n' => $rehosted], true);
        }
        return (is_string($out) && $out !== '') ? $out : $html;
    }


    public static function wpc_nw_widths($img_tag, $src_w_cap = 0)
    {
        $widths = [];
        if (!empty($img_tag['original_srcset'])) {
            foreach (explode(',', (string) $img_tag['original_srcset']) as $p) {
                if (preg_match('/\s(\d+)w$/', ' ' . trim((string) $p), $m)) {
                    $widths[] = (int) $m[1];
                }
            }
        }
        if (empty($widths)) {
            // No WP srcset: use the <img>'s intrinsic width (icons / fixed-size images) → that width + retina.
            foreach (['original_tags', 'additional_tags'] as $bag) {
                if (!empty($img_tag[$bag]['width']) && (int) $img_tag[$bag]['width'] > 0) {
                    $iw = (int) $img_tag[$bag]['width'];
                    $widths = [$iw, $iw * 2];
                    break;
                }
            }
            if (empty($widths)) {


                if ((int) $src_w_cap <= 0) {
                    return [];
                }
                $widths = [320, 480, 640, 768, 1024, 1366, 1600, 1920, 2560];
            }
        }
        if ((int) $src_w_cap > 0) {
            $cap = (int) $src_w_cap;
            $widths = array_filter($widths, function ($w) use ($cap) { return (int) $w <= $cap; });
            $widths[] = $cap;
        }
        $widths = array_values(array_unique(array_filter(array_map('intval', $widths), function ($w) {
            return $w >= 16;
        })));
        sort($widths);
        return $widths;
    }

    /**
     * BARE FULL-SIZE natural .webp. Symmetric with picture_avif_natural_full_ok: the bare full-size path
     * is the riskier edge object, so gate it on the proven webp witness rather than emit unconditionally.
     * Sized -WxH rungs stay on picture_webp_natural_ok (always natural).
     */
    public static function picture_webp_natural_full_ok()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        $witness = class_exists('wps_cdn_rewrite') && wps_cdn_rewrite::wpc_webp_immediate_ok();
        return (bool) apply_filters('wpc_picture_webp_natural_full_ok', $witness);
    }


    public static function wpc_single_url_format($origin_ext, $zone_is_cf = null, $witness_ok = null)
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        $origin_ext = strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $origin_ext));
        if ($origin_ext === '')    return false;
        if ($origin_ext === 'gif') return 'gif';

        if ($zone_is_cf === null) $zone_is_cf = self::zone_is_cf();
        if ($witness_ok === null) {
            $witness_ok = (function_exists('wpc_force_natural') && wpc_force_natural())
                || self::avif_natural_source_ok()
                || (class_exists('wps_cdn_rewrite') && wps_cdn_rewrite::wpc_webp_immediate_ok());
        }


        $jpeg_ceiling = class_exists('WPC_Negotiated_Delivery')
            && WPC_Negotiated_Delivery::is_active_jpeg()
            && !WPC_Negotiated_Delivery::is_active();

        $mode = self::single_url_format_mode();

        // FORCE modes (operator asserts their edge negotiates). KILL handled above; gif/jpeg-ceiling win.
        if ($mode === 'same-ext') return $origin_ext;
        if ($jpeg_ceiling)        return $origin_ext;
        if ($mode === 'webp')     return 'webp';
        if ($mode === 'avif')     return 'avif';


        if ($witness_ok) {
            if (!$zone_is_cf) return 'webp';        // Bunny / Vary-honored → promote
            $force = function_exists('wpc_force_natural') && wpc_force_natural();
            $nav   = class_exists('WPC_Delivery_Resolver') ? WPC_Delivery_Resolver::orch_nav_signal() : null;
            if ($force || $nav === true) return 'webp';
            return $origin_ext;
        }


        return $origin_ext;
    }

    /**
     * Read the Regime-B single-URL format control + filter. Whitelist-validated; empty/unknown → 'auto'.
     */
    private static function single_url_format_mode()
    {
        $s = self::$settings;
        $m = (is_array($s) && !empty($s['single-url-image-format'])) ? (string) $s['single-url-image-format'] : 'auto';
        if (!in_array($m, ['auto', 'same-ext', 'webp', 'avif'], true)) $m = 'auto';
        return (string) apply_filters('wpc_single_url_image_format', $m);
    }

    /**
     * Is the "prefer NATURAL single-URL" flag on for the single-<img> src naturalizer? When off,
     * maybe_naturalize_single_src() is a byte-identical no-op.
     */
    public static function single_url_natural_prefer()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;


        $opt = function_exists('get_option') ? get_option('wpc_single_url_natural_prefer', 1) : 1;
        return (bool) apply_filters('wpc_single_url_natural_prefer', !empty($opt));
    }


    public static function lazy_auto_aspect_safe($dw, $dh, $rw, $rh)
    {
        $dw = (int) $dw; $dh = (int) $dh; $rw = (int) $rw; $rh = (int) $rh;
        if ($dw <= 0 || $dh <= 0) return true;
        if ($rw <= 0 || $rh <= 0) return false;
        $declared = $dw / $dh;
        $real     = $rw / $rh;
        if ($real <= 0.0) return false;
        return (abs($declared - $real) / $real) <= 0.05;
    }

    /** Largest srcset candidate's intrinsic WxH (from URLs like …-WxH.ext NNNw). Returns [0,0] if none parseable. */
    public static function srcset_real_dims($tag)
    {
        $best = 0; $rw = 0; $rh = 0;
        if (preg_match('/\ssrcset\s*=\s*(["\'])(.*?)\1/is', (string) $tag, $ss)
            && preg_match_all('/(\d+)x(\d+)\.[a-z0-9]+\s+(\d+)w/i', $ss[2], $mm, PREG_SET_ORDER)) {
            foreach ($mm as $cand) {
                $cw = (int) $cand[3];
                if ($cw > $best) { $best = $cw; $rw = (int) $cand[1]; $rh = (int) $cand[2]; }
            }
        }
        return [$rw, $rh];
    }


    public static function auto_sizes_for_lazy_img($build_image_tag)
    {
        if (!is_string($build_image_tag) || $build_image_tag === '') return $build_image_tag;
        // nd/modern tags carry their own sizes policy — re-prefixing here re-broke what
        // .349 gated (invented sizes render the pre-layout UA fallback on CSS-auto themes).
        if (stripos($build_image_tag, 'data-wpc-nd') !== false || stripos($build_image_tag, 'data-wpc-md') !== false) return $build_image_tag;
        // Toggle: "Right-size Lazy Images" (Other Optimizations). Setting drives the default; filter overrides.
        // Default OFF ⇒ this is a pure no-op (byte-identical to <=7.03.26).


        $la_set = (is_array(self::$settings) && isset(self::$settings['lazy-auto-sizes']))
            ? self::$settings
            : ((function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array());
        $la_on = (is_array($la_set) && !empty($la_set['lazy-auto-sizes']));
        if (!apply_filters('wpc_auto_sizes_lazy', $la_on, $build_image_tag)) return $build_image_tag;
        if (!preg_match('/\sloading\s*=\s*(["\'])lazy\1/i', $build_image_tag)) return $build_image_tag;
        if (!preg_match('/\ssrcset\s*=\s*["\'][^"\']*?\d+w(?=[\s,"\'])/i', $build_image_tag)) return $build_image_tag;
        if (!preg_match('/\ssizes\s*=\s*(["\'])(.*?)\1/i', $build_image_tag, $m)) return $build_image_tag;
        if (stripos($m[2], 'auto') !== false) return $build_image_tag;
        // ASPECT-MATCH guard — never distort a mismatched-attr image (declared box vs real srcset aspect).
        $aw = preg_match('/\swidth\s*=\s*["\']?(\d+)/i', $build_image_tag, $mw) ? (int) $mw[1] : 0;
        $ah = preg_match('/\sheight\s*=\s*["\']?(\d+)/i', $build_image_tag, $mh) ? (int) $mh[1] : 0;
        list($rw, $rh) = self::srcset_real_dims($build_image_tag);
        if (!self::lazy_auto_aspect_safe($aw, $ah, $rw, $rh)) return $build_image_tag;


        if ($aw <= 0 || $ah <= 0) {
            if ($rw > 0 && $rh > 0) {
                $build_image_tag = preg_replace('/<img\b/i', '<img width="' . $rw . '" height="' . $rh . '"', $build_image_tag, 1);
            } else {
                return $build_image_tag;
            }
        }
        return str_replace($m[0], ' sizes=' . $m[1] . 'auto, ' . $m[2] . $m[1], $build_image_tag);
    }


    public static function activate_lazy_srcset_auto($build_image_tag)
    {
        if (!is_string($build_image_tag) || $build_image_tag === '') return $build_image_tag;
        // Toggle: "Right-size Lazy Images" (Other Optimizations). Default OFF ⇒ pure no-op.


        $la_set = (is_array(self::$settings) && isset(self::$settings['lazy-auto-sizes']))
            ? self::$settings
            : ((function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : array());
        $la_on = (is_array($la_set) && !empty($la_set['lazy-auto-sizes']));
        if (!apply_filters('wpc_auto_sizes_lazy', $la_on, $build_image_tag)) return $build_image_tag;
        if (!preg_match('/\sloading\s*=\s*(["\'])lazy\1/i', $build_image_tag)) return $build_image_tag;
        // Inert ladder present, no active srcset, not a JS-lazy/placeholder img, not a carousel.
        if (!preg_match('/\sdata-srcset\s*=\s*(["\'])(.*?)\1/is', $build_image_tag, $ds)) return $build_image_tag;
        if (!preg_match('/\d+w(?=[\s,"\'])/', $ds[2])) return $build_image_tag;
        if (preg_match('/(?<![-\w])srcset\s*=/i', $build_image_tag)) return $build_image_tag;
        if (preg_match('/\sdata-src\s*=/i', $build_image_tag)) return $build_image_tag;         // JS-lazy placeholder
        if (preg_match('/\sclass\s*=\s*["\'][^"\']*(swiper|slick|owl|carousel|flickity|splide|attachment-slider|size-slider)/i', $build_image_tag)) return $build_image_tag;
        // ASPECT-MATCH guard — never distort a mismatched-attr image (declared box vs the ladder's real aspect).
        $aw = preg_match('/\swidth\s*=\s*["\']?(\d+)/i', $build_image_tag, $mw) ? (int) $mw[1] : 0;
        $ah = preg_match('/\sheight\s*=\s*["\']?(\d+)/i', $build_image_tag, $mh) ? (int) $mh[1] : 0;
        list($rw, $rh) = self::srcset_real_dims(' srcset="' . $ds[2] . '"');
        if (!self::lazy_auto_aspect_safe($aw, $ah, $rw, $rh)) return $build_image_tag;
        // Promote: inert data-srcset → ACTIVE srcset, and stop the adaptive JS from re-touching it.
        $build_image_tag = preg_replace('/\sdata-srcset(\s*=)/i', ' srcset$1', $build_image_tag, 1);
        $build_image_tag = preg_replace('/\sdata-wpc-loaded\s*=\s*(["\'])true\1/i', '', $build_image_tag);
        return $build_image_tag;
    }


    public static function naturalize_svg_src($build_image_tag)
    {
        if (!is_string($build_image_tag) || $build_image_tag === '' || self::$zoneName === '') {
            return $build_image_tag;
        }
        if (stripos($build_image_tag, '.svg') === false || strpos($build_image_tag, '/u:') === false) {
            return $build_image_tag;
        }
        $zone_host = (string) self::$zoneName;
        $site_host = function_exists('site_url') ? (string) wp_parse_url(site_url(), PHP_URL_HOST) : '';
        $zone      = preg_quote(self::$zoneName, '#');
        return preg_replace_callback(
            '#((?:src|data-src)=")https://' . $zone . '(?:/q:[a-z0-9]+)?(?:/e:\d+)?/r:\d+/wp:\d+/w:\d+/u:(https?://[^"?]+?\.svg(?![\w-])(?:\?[^"]*)?)(")#i',
            function ($m) use ($zone_host, $site_host) {
                $origin = $m[2];
                $ohost  = (string) wp_parse_url($origin, PHP_URL_HOST);
                // Only naturalize a /u: URL on OUR zone host or the SAME-SITE origin host. A foreign host
                // (external SVG) is left exactly as-is — external assets must never be served from the CDN.
                if ($ohost === ''
                    || (strcasecmp($ohost, $zone_host) !== 0 && ($site_host === '' || strcasecmp($ohost, $site_host) !== 0))) {
                    return $m[0];
                }
                // Host-swap origin → zone, preserving the path + any ?query. The cacheable natural URL.
                $nat = preg_replace('#^https?://[^/]+#', 'https://' . $zone_host, $origin);
                return $m[1] . $nat . $m[3];
            },
            $build_image_tag
        );
    }


    public static function wpc_census_belowfold_map()
    {
        static $topByStem = null;
        if ($topByStem !== null) { return $topByStem; }
        $topByStem = [];
        try {
            if (!apply_filters('wpc_census_belowfold_veto', true)) {
                return $topByStem;
            }
            // The page's own measurement, this device's leg only: the owner keeps the smallest
            // top per file and never lends another layout's (or the home page's) positions.
            $isMobile = (!empty($_GET['simulate_mobile']) || (function_exists('wp_is_mobile') && wp_is_mobile()));
            foreach (wps_ic_atf_observation::topsByStem($isMobile ? 'mobile' : 'desktop') as $stem => $top) {
                if (preg_match('/^[a-z0-9._@-]{3,}$/', (string) $stem)) {
                    $topByStem[$stem] = $top;
                }
            }
        } catch (\Throwable $e) {
            $topByStem = [];
        }
        return $topByStem;
    }

    public static function wpc_is_census_below_fold($tag)
    {
        // POSITIVE measurement only: an image the census never saw yields no veto — the
        // positional window keeps its slot exactly as today. Fail-open on every miss.
        try {
            if (!is_string($tag) || $tag === '') { return false; }
            $topByStem = self::wpc_census_belowfold_map();
            if (empty($topByStem)) { return false; }
            if (!preg_match('/\b(?:src|data-src|data-cp-src)\s*=\s*["\']([^"\']+)/i', $tag, $srcMatch)) {
                return false;
            }
            $fileName = strtolower((string) basename((string) parse_url($srcMatch[1], PHP_URL_PATH)));
            if ($fileName === '') { return false; }
            $foldPx = (int) apply_filters('wpc_lcp_census_fold', 1200);
            foreach ($topByStem as $stem => $top) {
                if ($top <= $foldPx) { continue; }
                if (preg_match('/^' . preg_quote($stem, '/') . '(?:-scaled)?(?:-\d+x\d+)?\.[a-z0-9]+$/i', $fileName)) {
                    return true;
                }
            }
            return false;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function naturalize_srcset_widths($build_image_tag)
    {
        if (!is_string($build_image_tag) || $build_image_tag === '' || self::$zoneName === '') return $build_image_tag;
        if (stripos($build_image_tag, 'srcset=') === false) return $build_image_tag;
        $zone = (string) self::$zoneName;
        return preg_replace_callback('/((?:data-)?srcset=")([^"]+)(")/i', function ($mm) use ($zone) {
            $raw = array_values(array_filter(array_map('trim', explode(',', $mm[2])), 'strlen'));
            if (count($raw) < 2) return $mm[0];
            // Aspect (h/w) from any -WxH rung (file or transform u:) + the largest w-descriptor = source ceiling.
            $aspect = 0.0; $maxW = 0; $aspectW = 0;
            foreach ($raw as $e) {
                $p = preg_split('/\s+/', $e);
                if (count($p) < 2 || !preg_match('/^(\d+)w$/', $p[1], $dm)) continue;
                $maxW = max($maxW, (int) $dm[1]);


                if (preg_match('#-(\d+)x(\d+)\.[a-z0-9]+#i', $p[0], $a) && (int) $a[1] > $aspectW) {
                    $aspectW = (int) $a[1]; $aspect = (int) $a[2] / (int) $a[1];
                }
            }
            if ($aspect <= 0 || $maxW <= 0) return $mm[0];
            // v7.10.848 — next-gen-off srcsets stay 100% natural: a /w: transform of a plain
            // jpeg/png rung serves palette-quantized bytes from the edge (receipted: every PNG
            // transform returns 8-bit colormap vs the truecolor natural file), so when the rung
            // cannot naturalize it is DROPPED, not kept — the browser falls back to the real
            // -WxH intermediates and the full-size natural. Fail-open: only when >=2 rungs
            // survive as naturals, and only for zone transforms; kill: wpc_drop_plain_transform_rungs.
            $naturalRungCount = 0;
            foreach ($raw as $candidate) {
                $candidateParts = preg_split('/\s+/', $candidate);
                if (count($candidateParts) >= 2 && strpos($candidateParts[0], '/u:') === false) { $naturalRungCount++; }
            }
            $dropPlainTransforms = ($naturalRungCount >= 2) && apply_filters('wpc_drop_plain_transform_rungs', true);
            $out = []; $seen = [];
            foreach ($raw as $e) {
                $p = preg_split('/\s+/', $e);
                if (count($p) < 2 || !preg_match('/^(\d+)w$/', $p[1], $dm) || strpos($p[0], '//' . $zone . '/') === false) {
                    $out[] = $e; continue;
                }
                $D = (int) $dm[1];
                if (isset($seen[$D])) continue;
                $seen[$D] = true;
                $url = $p[0];
                $isTransform = (strpos($url, '/u:') !== false);
                // Resolve to the underlying natural path (a /w: transform → its u: source; natural → itself).
                $probe = ($isTransform && preg_match('#/u:(https?://\S+)$#i', $url, $um)) ? $um[1] : $url;
                $ppath = (string) wp_parse_url(preg_replace('/\?.*$/', '', $probe), PHP_URL_PATH);
                if ($ppath === '') { $out[] = $e; continue; }
                $noext = preg_replace('/\.[a-z0-9]+$/i', '', $ppath);
                $ext   = strtolower((string) pathinfo($ppath, PATHINFO_EXTENSION)); if ($ext === '') $ext = 'webp';
                $fw    = preg_match('#-(\d+)x(\d+)$#', $noext, $wx) ? (int) $wx[1] : 0;


                if (($fw === $D) || ($fw === 0 && $D >= $maxW)) {
                    $out[] = 'https://' . $zone . $ppath . ' ' . $D . 'w';
                    continue;
                }
                if ($D > $maxW) { $out[] = $e; continue; }


                $wpc_plainT = !$isTransform || strpos($url, '/wp:0/') !== false || strpos($url, '/wp:') === false;
                if (!in_array($ext, ['webp', 'avif'], true)
                    && (!$wpc_plainT || $D < 100 || apply_filters('wpc_naturalize_nextgen_only', false, $ppath))) {
                    if ($dropPlainTransforms && $isTransform && $wpc_plainT) { continue; }
                    $out[] = $e; continue;
                }
                $base = preg_replace('#-\d+x\d+$#', '', $noext);
                $h = (int) round($D * $aspect);
                if ($h <= 0) { $out[] = $e; continue; }
                $out[] = 'https://' . $zone . $base . '-' . $D . 'x' . $h . '.' . $ext . ' ' . $D . 'w';
            }
            return $mm[1] . implode(', ', $out) . $mm[3];
        }, $build_image_tag);
    }

    public static function maybe_naturalize_single_src($build_image_tag)
    {
        if (!is_string($build_image_tag) || $build_image_tag === '') return $build_image_tag;

        static $ctx = null;
        if ($ctx === null) {
            $ok = false; $base_paths = [];
            if (self::$zoneName !== '' && self::single_url_natural_prefer()) {
                $witness = (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'wpc_webp_immediate_ok') && wps_cdn_rewrite::wpc_webp_immediate_ok())
                    || self::natural_assets_on()
                    || (function_exists('wpc_force_natural') && wpc_force_natural());
                if ($witness) {


                    $bp = function_exists('wpc_v2_upload_base_paths') ? wpc_v2_upload_base_paths() : ['/wp-content/uploads'];
                    // Include /storage (the common offloaded page-builder media base) by default. Harmless
                    // on sites without it: still gated by same-site host + -WxH + webp/avif + witness.
                    $bp[] = '/storage';
                    $bp = array_values(array_unique(array_filter(array_map(function ($x) { return '/' . trim((string) $x, '/'); }, (array) $bp))));
                    $bp = (array) apply_filters('wpc_single_url_natural_bases', $bp);
                    if (!empty($bp)) { $ok = true; $base_paths = $bp; }
                }
            }
            $ctx = ['ok' => $ok, 'base_paths' => $base_paths];
        }
        if (empty($ctx['ok'])) return $build_image_tag;

        $base_paths = $ctx['base_paths'];
        $zone_host  = self::$zoneName;
        $site_host  = function_exists('site_url') ? (string) wp_parse_url(site_url(), PHP_URL_HOST) : '';
        $zone       = preg_quote(self::$zoneName, '#');

        // The transform prefix carries optional /q:<opt> (quality) and /e:<n> (exif) segments before
        // /r:. Match them optionally (non-capturing → group indices unchanged: 3=wp, 4=/u:).
        return preg_replace_callback(
            '#(?<![-\w])(src="|data-src=")(https://' . $zone . '(?:/q:[a-z0-9]+)?(?:/e:\d+)?/r:\d+/wp:(\d+)/w:\d+/u:(https?://[^"]+?))(")#i',
            function ($m) use ($base_paths, $zone_host, $site_host) {
                if ((int) $m[3] === 0) return $m[0];
                $origin = $m[4];


                $ohost = (string) wp_parse_url($origin, PHP_URL_HOST);
                if ($ohost === ''
                    || (strcasecmp($ohost, (string) $zone_host) !== 0 && ($site_host === '' || strcasecmp($ohost, $site_host) !== 0))) {
                    return $m[0];
                }
                $clean = preg_replace('/\?.*$/', '', $origin);


                if (!preg_match('#-\d+x\d+\.(webp|avif)$#i', $clean)) return $m[0];
                $p = (string) wp_parse_url($clean, PHP_URL_PATH);
                // Under an allowed media base, BOUNDARY-SAFE (must be "<base>/…" or exactly "<base>").
                $in_base = false;
                foreach ($base_paths as $bp) {
                    if ($p === $bp || strpos($p, $bp . '/') === 0) { $in_base = true; break; }
                }
                if (!$in_base) return $m[0];
                // Host-swap origin → zone (no-op when already the zone, e.g. /wp-content/uploads; rewrites
                // the ORIGIN host → zone for /storage), SAME extension. The cacheable CF HIT. Keeps any ?query.
                $nat_url = preg_replace('#^https?://[^/]+#', 'https://' . $zone_host, $origin);
                return $m[1] . $nat_url . $m[5];
            },
            $build_image_tag
        );
    }


    public static function picture_variant_dims_ok($disk_path, $native_w, $native_h)
    {
        if (defined('WPC_SKIP_PICTURE_VARIANT_VALIDATION') && WPC_SKIP_PICTURE_VARIANT_VALIDATION) return true;
        if (!is_string($disk_path) || $disk_path === '' || !@file_exists($disk_path)) return true;
        $native_w = (int) $native_w;
        $native_h = (int) $native_h;
        // MEMOIZE @getimagesize per request: it runs per rung per image (dozens–hundreds of uncached
        // disk reads on a Woo catalog). Stable per path within a request; pure cache, no logic change.
        static $gis_memo = [];
        if (array_key_exists($disk_path, $gis_memo)) {
            $vd = $gis_memo[$disk_path];
        } else {
            $vd = @getimagesize($disk_path);
            $gis_memo[$disk_path] = $vd;
        }
        if (!is_array($vd) || empty($vd[0]) || empty($vd[1])) return true;
        $rw = (int) $vd[0];
        $rh = (int) $vd[1];
        if ($rw <= 2 || $rh <= 2) return false;


        $maxDim = (int) apply_filters('big_image_size_threshold', 2560);
        if ($maxDim > 0 && $native_w <= 0 && $native_h <= 0) {
            $ar = ($rw > 0 && $rh > 0) ? max($rw / $rh, $rh / $rw) : 99;
            // (a) BOTH sides peg the ceiling → square/near-square mis-encode (proicon2 2560x2560).
            if ($rw >= $maxDim && $rh >= $maxDim) return false;


            if ($ar >= 5.0 && max($rw, $rh) >= $maxDim) return false;
        }
        // Native-relative (stricter when native known). Tolerance max(8px,10%) absorbs sub-size
        // rounding; the logo case (real 2560 vs native 60) blows past it.
        if ($native_w > 0 && $rw > $native_w + max(8, (int) ($native_w * 0.10))) return false;
        if ($native_h > 0 && $rh > $native_h + max(8, (int) ($native_h * 0.10))) return false;
        return true;
    }


    const VARIANT_NONE     = 0;
    const VARIANT_WITNESS  = 1;
    const VARIANT_RECORDED = 2;
    const VARIANT_ON_DISK  = 3;


    public static function wpc_variant_servable($attachment_id, $url, $fmt, $size_label = '', $disk_path = '', $width = 0)
    {
        static $c = [];
        $fmt = strtolower((string) $fmt);
        $attachment_id = (int) $attachment_id;
        $k = $attachment_id . '|' . $url . '|' . $fmt . '|' . $size_label . '|' . (int) $width;
        if (isset($c[$k])) return $c[$k];

        // T1 — LOCAL DISK + dims (byte-identical to the current per-rung gate). Derive the disk path
        // from the URL via the same site_url→ABSPATH map recoverAdaptiveVariant uses (~:686).
        if ($disk_path === '' && $url !== '' && function_exists('site_url')) {
            $disk_path = str_replace(trailingslashit(site_url()), trailingslashit(ABSPATH), preg_replace('/\?.*$/', '', (string) $url));
        }
        if ($disk_path !== '' && @is_file($disk_path)) {
            $nw = 0; $nh = 0;
            if ($attachment_id > 0 && function_exists('wp_get_attachment_metadata')) {
                $m = wp_get_attachment_metadata($attachment_id);
                if (is_array($m)) { $nw = (int) ($m['width'] ?? 0); $nh = (int) ($m['height'] ?? 0); }
            }
            if (self::picture_variant_dims_ok($disk_path, $nw, $nh)) return $c[$k] = self::VARIANT_ON_DISK;

        }

        // T2 — ic_local_variants RECORD (attachments only). Test record EXISTENCE + not-skipped ONLY —
        // NEVER byte-size: an offloaded variant has size 0 (local file gone) but is served from the edge.
        if ($attachment_id > 0 && function_exists('get_post_meta')) {
            static $lvc = [];
            if (!array_key_exists($attachment_id, $lvc)) {
                $lvc[$attachment_id] = get_post_meta($attachment_id, 'ic_local_variants', true);
            }
            $lv = $lvc[$attachment_id];
            if (is_array($lv)) {
                $sfx  = in_array($fmt, ['jpg', 'jpeg'], true) ? '' : '-' . $fmt;
                $keys = [];
                if ($size_label !== '') $keys[] = $size_label . $sfx;
                if ((int) $width > 0)   { $keys[] = 'wpc_' . (int) $width . $sfx; $keys[] = (int) $width . 'w' . $sfx; }
                foreach ($keys as $kk) {
                    if (isset($lv[$kk]) && is_array($lv[$kk])) {
                        $e = $lv[$kk];
                        $skipped = !empty($e['skipped'])
                            || (!empty($e['skipped_formats']) && is_array($e['skipped_formats']) && in_array($fmt, $e['skipped_formats'], true));
                        if ($skipped) return $c[$k] = self::VARIANT_NONE;
                        return $c[$k] = self::VARIANT_RECORDED;
                    }
                }
            }
        }


        $clean  = (string) preg_replace('/\?.*$/', '', (string) $url);
        $is_wxh = (bool) preg_match('/-\d+x\d+\.[a-z0-9]+$/i', $clean);
        if ($is_wxh) {
            if ($fmt === 'avif') {
                $w = self::picture_avif_emit_natural();
            } elseif ($fmt === 'webp') {
                $w = self::picture_webp_natural_ok();
            } else {
                $w = ($fmt === 'jpg' || $fmt === 'jpeg' || $fmt === 'png');
            }
            if ($w) return $c[$k] = self::VARIANT_WITNESS;
        }
        return $c[$k] = self::VARIANT_NONE;
    }

    /**
     * Best-existing format for ONE slot, tried avif→webp→origin through the oracle. Regime-aware:
     * 'picture' (typed <source>, browser self-selects) may use .avif; 'single' (bare <img>) NEVER bare
     * .avif (not vary-eligible → would pin). Returns [fmt, url]; never-404 floor = same-ext natural.
     */
    public static function wpc_best_servable_format($attachment_id, $base_natural_url, $origin_ext, $size_label, $regime, $width = 0)
    {
        $origin_ext = strtolower((string) $origin_ext);
        $chain = ($regime === 'picture') ? ['avif', 'webp', $origin_ext] : ['webp', $origin_ext];
        if (in_array('avif', $chain, true) && !preg_match('/^(jpe?g|png)$/i', $origin_ext)) {
            $chain = array_values(array_diff($chain, ['avif']));
        }
        foreach ($chain as $f) {
            $u = preg_replace('/\.[a-z0-9]+$/i', '.' . $f, $base_natural_url);
            if (self::wpc_variant_servable($attachment_id, $u, $f, $size_label, '', $width) !== self::VARIANT_NONE) {
                return [$f, $u];
            }
        }
        return [$origin_ext, preg_replace('/\.[a-z0-9]+$/i', '.' . $origin_ext, $base_natural_url)];
    }

    /**
     * Master gate for the variant oracle (Stage 1). DEFAULT OFF → every wired call-site uses its
     * ORIGINAL file_exists branch (byte-identical). WPC_NEGOTIATED_KILL is the absolute off-ramp.
     */
    public static function variant_oracle_enabled()
    {
        if (defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL) return false;
        $opt = function_exists('get_option') ? get_option('wpc_variant_oracle_enabled', 0) : 0;
        return (bool) apply_filters('wpc_variant_oracle_enabled', !empty($opt));
    }


    public static function naturalize_asset_urls($html)
    {
        $cname = self::$zoneName;
        if (empty($cname) || !is_string($html) || $html === '' || strpos($html, '/a:') === false) {
            return $html;
        }
        $zq = preg_quote((string) $cname, '#');


        $s = '\\\\?/';
        $re = '#https:' . $s . $s . $zq . $s . '(?:m:[01]|font:true)' . $s . 'a:((?:https?:)?(?:' . $s . $s . ')?[^"\'\s)>]+)#i';
        $collapsed = 0;
        $out = preg_replace_callback(
            $re,
            function ($m) use ($cname, &$collapsed) {
                $raw = $m[1];
                $escaped = (strpos($raw, '\\/') !== false);
                $unesc = $escaped ? str_replace('\\/', '/', $raw) : $raw;
                if (!self::imageUrlMatchingSiteUrl($unesc)) {
                    return $m[0];
                }
                $p = @parse_url($unesc);
                if (empty($p['path'])) return $m[0];
                // Scripts ride the page origin (v7.10.719: the zone connection chain is charged to
                // LCP and preconnect is never credited), so a script's transform URL collapses to
                // the origin URL it wraps, not to the zone.
                $collapsed++;
                if (substr(strtolower($p['path']), -3) === '.js' && apply_filters('wpc_scripts_same_origin', true)) {
                    return $escaped ? str_replace('/', '\\/', $unesc) : $unesc;
                }
                $q = (isset($p['query']) && $p['query'] !== '') ? '?' . $p['query'] : '';
                $natural = 'https://' . $cname . $p['path'] . $q;
                if ($escaped) $natural = str_replace('/', '\\/', $natural);
                return $natural;
            },
            $html
        );
        // Writers still mint the zone's transform grammar (m:0/a:, font:true/a:) for assets the
        // zone serves at their natural path; each is collapsed. Sampled: every render of a CDN
        // page carries them by construction.
        if ($collapsed > 0 && $out !== null && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('transform-urls-naturalized', ['assets' => $collapsed], true);
        }
        return ($out === null) ? $html : $out;
    }

    public function allLinks($html)
    {
        $html = preg_replace_callback('/https?:(\/\/[^"\']*\.(?:svg|css|js|ico|icon))/i', [__CLASS__, 'cdnAllLinks'], $html);

        return $html;
    }

    public function cdnAllLinks($image)
    {
        $src_url = $image[0];

        // v7.10.719 - scripts never zone (same-origin law); this pass was the second writer.
        if (strpos($src_url, '.js') !== false && apply_filters('wpc_scripts_same_origin', true)) {
            return $src_url;
        }

        if ($this->defaultExcluded($src_url)) {
            return $src_url;
        }

        if (self::isExcludedFrom('cdn', $src_url)) {
            return $src_url;
        }

        if (strpos($src_url, self::$zoneName) !== false) {
            return $src_url;
        }

        if (!self::isExcludedLink($src_url)) {
            // External is disabled?
            if (self::$externalUrlEnabled == '0' || empty(self::$externalUrlEnabled)) {
                if (!self::imageUrlMatchingSiteUrl($src_url)) {
                    return $src_url;
                }
            }

            if (strpos($src_url, self::$zoneName) === false) {


                if ((strpos($src_url, '.css') !== false || strpos($src_url, '.js') !== false) && !self::natural_assets_on()) {
                    return $src_url;
                }


                if (stripos($src_url, '/cache/wp-cio-fonts/') !== false) {
                    return $src_url;
                }
                if (strpos($src_url, '.css') !== false) {
                    if (self::$css == "1") {
                        $fileMinify = self::$cssMinify;

                        if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') {
                            $fileMinify = '1';
                        }

                        $newSrc = 'https://' . self::$zoneName . '/m:' . $fileMinify . '/a:' . self::reformatUrl($src_url);
                    }
                } elseif (strpos($src_url, '.js') !== false) {
                    if (self::$js == "1") {
                        // v7.10.719 - render-lane scripts ride the page origin (controlled
                        // ladder: zone scripts {97,99x7} vs page-origin {100x8}) - the writer
                        // stands down instead of being undone downstream.
                        if (apply_filters('wpc_scripts_same_origin', true)) {
                            return $src_url;
                        }
                        $fileMinify = self::$jsMinify;
                        if (self::isExcluded('js_minify', $src_url)) {
                            $fileMinify = '0';
                        }

                        $newSrc = 'https://' . self::$zoneName . '/m:' . $fileMinify . '/a:' . self::reformatUrl($src_url);
                    }
                } else {
                    $newSrc = 'https://' . self::$zoneName . '/m:0/a:' . self::reformatUrl($src_url);
                }

                return $newSrc;
            }
        }

        return $image[0];
    }


    public static function wpc_zone_delayed_js_url($url)
    {
        if (!is_string($url) || $url === '' || strpos($url, 'data:') === 0) {
            return $url;
        }
        if (!apply_filters('wpc_delayed_js_on_cdn', true)) {
            return $url;
        }
        // THIS CLASS DOES NOT DECLARE $cdnEnabled — wps_cdn_rewrite does, and empty() on an
        // undeclared static returns true WITHOUT throwing, so the original guard was false on
        // every render and the whole lane was a silent no-op. Read the owner's statics, falling
        // back to the settings both classes copy from.
        $liveCdnSetting = null;
        $jsCdnSetting  = null;
        if (class_exists('wps_cdn_rewrite')) {
            if (isset(wps_cdn_rewrite::$cdnEnabled)) { $liveCdnSetting = wps_cdn_rewrite::$cdnEnabled; }
            if (isset(wps_cdn_rewrite::$js))         { $jsCdnSetting  = wps_cdn_rewrite::$js; }
        }
        if ($liveCdnSetting === null || $jsCdnSetting === null) {
            $settings = (function_exists('get_option') && defined('WPS_IC_SETTINGS')) ? get_option(WPS_IC_SETTINGS) : null;
            if (!is_array($settings)) {
                return $url;
            }
            if ($liveCdnSetting === null) { $liveCdnSetting = isset($settings['live-cdn']) ? $settings['live-cdn'] : null; }
            if ($jsCdnSetting === null)  { $jsCdnSetting  = isset($settings['js']) ? $settings['js'] : null; }
        }
        if ((string) $liveCdnSetting !== '1' || (string) $jsCdnSetting !== '1') {
            return $url;
        }
        if (empty(self::$zoneName) || !is_string(self::$zoneName) || strpos(self::$zoneName, '/') !== false) {
            return $url;
        }
        if (!self::natural_assets_on()) {
            return $url;
        }
        if (strpos($url, self::$zoneName) !== false) {
            return $url;
        }
        $absoluteUrl = self::reformatUrl($url);
        if (!is_string($absoluteUrl) || strpos($absoluteUrl, 'http') !== 0) {
            return $url;
        }
        if (!self::imageUrlMatchingSiteUrl($absoluteUrl)) {
            return $url;
        }
        if (self::isExcludedLink($absoluteUrl) || self::isExcludedFrom('cdn', $absoluteUrl)) {
            return $url;
        }
        // The exclude list is applied ONCE, by its owner. Re-running wpc_cdn_excludes here over a
        // different default array meant a filter that computes from its input (array_diff,
        // array_slice, index-based) saw two different inputs and produced two different exclusion
        // sets on the same request.
        if (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'isExcludedFrom')
            && wps_cdn_rewrite::isExcludedFrom('cdn', $absoluteUrl)) {
            return $url;
        }
        $urlParts = @parse_url($absoluteUrl);
        if (empty($urlParts['path']) || !preg_match('/\.m?js$/i', $urlParts['path'])) {
            return $url;
        }
        if (stripos($urlParts['path'], '/wp-admin/') !== false) {
            return $url;
        }
        // reformatUrl() strips the caller's query, so the original is carried across explicitly:
        // without it a script reading its own currentScript.src query loses every parameter.
        $query = (string) @parse_url($url, PHP_URL_QUERY);
        // Rule: a zone URL carries the file's asset version (?js_icv=, mtime+size), written by the
        // one formatter that owns it, wps_cdn_rewrite::reformat_url(). The zone keys an object by
        // its full URL, so a bare natural path is the same object before and after the file
        // changes. This lane relied on reformatUrl() for the buster, and reformatUrl() gates it on
        // a $cdnEnabled this class never declares, so it never added one. Observed failure
        // (justmsp.com, ticket 11928, 2026-10-01): after an Elementor update the zone kept serving
        // the 14 Sep copies of frontend-modules.min.js and Pro's elements-handlers.min.js, whose
        // tags reached this lane without ?ver=, and Elementor Pro died on every load
        // (elementorModules is not defined).
        $assetVersion = (class_exists('wps_cdn_rewrite') && method_exists('wps_cdn_rewrite', 'reformat_url'))
            ? (string) @parse_url((string) wps_cdn_rewrite::reformat_url($absoluteUrl), PHP_URL_QUERY) : '';
        if ($assetVersion !== '' && !preg_match('/(?:^|&)(?:js_icv|icv_random)=/', $query)) {
            $query = ($query !== '') ? $query . '&' . $assetVersion : $assetVersion;
        }
        return 'https://' . self::$zoneName . $urlParts['path'] . ($query !== '' ? '?' . $query : '');
    }

    public static function imageUrlMatchingSiteUrl($image)
    {
        $site_url = self::$siteUrl;
        $stripped = str_replace(['https://', 'http://'], '', $image);
        $site_url = str_replace(['https://', 'http://'], '', $site_url);

        if (strpos($stripped, '.css') !== false || strpos($stripped, '.js') !== false) {
            foreach (self::$defaultExcludedList as $i => $excluded_string) {
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
            // Image not on site
            return false;
        } else {
            // Image on site
            return true;
        }
    }

    public static function isExcluded($image_element, $image_link = '')
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
            // Full Image
            $basename = $basename_original;
        } else {
            // Some thumbnail
            $basename = str_replace('-' . $matches[1] . 'x' . $matches[2], '', $basename_original);
        }

        /**
         * Is this image lazy excluded?
         */
        if (!empty(self::$lazyExcludeList) && !empty(self::$lazyEnabled) && self::$lazyEnabled == '1') {

            foreach (self::$lazyExcludeList as $i => $lazy_excluded) {
                if (strpos($basename, $lazy_excluded) !== false) {
                    return true;
                }
            }
        } elseif (!empty(self::$excludedList)) {
            foreach (self::$excludedList as $i => $excluded) {
                if (strpos($basename, $excluded) !== false) {
                    return true;
                }
            }
        }

        if (!empty(self::$lazyExcludeList) && in_array($basename, self::$lazyExcludeList)) {
            return true;
        }

        if (!empty(self::$excludedList) && in_array($basename, self::$excludedList)) {
            return true;
        }

        return false;
    }

    /** $isAmp is the render's AMP verdict ($ctx->isAmp), handed down by stage_external_urls. */
    public function externalUrls($html, $isAmp)
    {
        $html = preg_replace_callback('/https?:[^)\s"\'<>]+\.(jpg|jpeg|png|gif|svg|css|js|ico|icon)(?![^.\w]*\.[^.\w]*)/i', function ($image) use ($isAmp) {
            return $this->cdnExternalUrls($image, $isAmp);
        }, $html);

        return $html;
    }

    public function cdnExternalUrls($image, $isAmp)
    {
        $src_url = $image[0];
        $width = 1;

        if ($isAmp) {
            $width = 600;
        }

        if (strpos($src_url, 'optimize.js') !== false) {
            return $src_url;
        }

        if (self::isExcludedFrom('cdn', $src_url) || $src_url == 'https://www.ico') {
            return $src_url;
        }

        // Is URL Matching the Site Url?
        if (strpos($src_url, self::$zoneName) !== false) {
            return $src_url;
        }


        $wpc_z  = (string) self::$zoneName;
        $wpc_oh = function_exists('home_url') ? (string) wp_parse_url(home_url(), PHP_URL_HOST) : '';
        if ($wpc_z === '' || ($wpc_oh !== '' && strcasecmp($wpc_z, $wpc_oh) === 0)) {
            return $src_url;
        }


        // {zone}/m:N/a:{external} URL anywhere). Only same-site assets the main rewrite missed fall through.
        $wpc_ah = (string) wp_parse_url($src_url, PHP_URL_HOST);
        if ($wpc_ah !== '' && $wpc_oh !== '' && strcasecmp($wpc_ah, $wpc_oh) !== 0) {
            return $src_url;
        }

        $webp = '/wp:' . self::$webp;
        if (self::isExcludedFrom('webp', $src_url)) {
            $webp = '';
        }

        if (self::isExcludedFrom('cdn', $src_url)) {
            return $src_url;
        }

        if (!self::isExcludedLink($src_url)) {
            if (strpos($src_url, self::$zoneName) === false) {
                // Check if the URL is an image, then check if it's instagram etc...
                foreach (self::$defaultExcludedList as $i => $excluded_string) {
                    if (strpos($src_url, $excluded_string) !== false) {
                        return $src_url;
                    }
                }

                $newSrc = $src_url;
                // Local-Fonts cache stylesheet (wp-cio-fonts/{hash}.css): keep natural origin so its @font-face
                // URLs match the inline/preload set. Reorder-proof (latent today).
                if (stripos($src_url, '/cache/wp-cio-fonts/') !== false) {
                    return $src_url;
                }
                if (strpos($src_url, '.css') !== false) {
                    if (self::$css == "1") {

                        if (!empty(self::$settings['font-subsetting']) && self::$settings['font-subsetting'] == '1') {
                            self::$cssMinify = '1';
                        }

                        $newSrc = 'https://' . self::$zoneName . '/m:' . self::$cssMinify . '/a:' . self::reformatUrl($src_url);
                    }
                } elseif (strpos($src_url, '.js') !== false) {
                    // v7.10.722 - the FOURTH script writer (catch-all for assets the main
                    // rewrite missed) - receipted live: jquery + the adaptive pixel entered
                    // the zone through THIS pass on the flagship lane while the other three
                    // writers stood down. Same law, same filter.
                    if (self::$js == "1" && !apply_filters('wpc_scripts_same_origin', true)) {
                        $newSrc = 'https://' . self::$zoneName . '/m:' . self::$jsMinify . '/a:' . self::reformatUrl($src_url);
                    }
                } else {
                    if (strpos($src_url, '.svg') !== false) {
                        $newSrc = 'https://' . self::$zoneName . '/m:0/a:' . self::reformatUrl($src_url);
                    } elseif (preg_match('/\.gif(\?|#|$)/i', $src_url) && !self::cf_is_delivery()) {
                        // GIF never rides the Bunny zone (no next-gen gain → pure WPC egress); keep origin.
                        $newSrc = $src_url;
                    } else {
                        $newSrc = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth($width, self::isExcludedFrom('adaptive', $src_url)) . '/u:' . self::uForCdn($src_url);
                    }
                }
                return $newSrc;
            }
        }

        return $image[0];
    }

    public static function getCurrentMaxWidth($Width, $skipped = false)
    {
        if ($skipped) {
            return '1';
        }

        if (self::$isMobile && self::$adaptiveEnabled) {
            $mobile_width = get_option('wpc-min-mobile-width');
            return $mobile_width ? $mobile_width : 400;
        }

        if ($Width == 'logo') {
            return '1';
        }

        return $Width;
    }


    public static $wpc_census_dbg = [];


    /**
     * The measured slot for one stem: ['m' => width, 'd' => width], clamped for a shrunken
     * desktop state. Zero on a leg the service never measured, on a width no `sizes`
     * attribute should carry, and on any file the page uses more than once — see
     * wps_ic_atf_observation, which withholds those rather than bake one width onto them all.
     */
    public static function wpc_census_slot($stem)
    {
        $widthsByStem = wps_ic_atf_observation::widths(wps_ic_atf_observation::SCOPE_ALL);
        if ($stem === '' || !isset($widthsByStem[$stem])) { return ['m' => 0, 'd' => 0]; }
        $slot = $widthsByStem[$stem];
        return [
            'm' => self::wpc_census_width_usable($slot['m'], $stem) ? (int) $slot['m'] : 0,
            'd' => self::wpc_census_width_usable($slot['d'], $stem) ? (int) $slot['d'] : 0,
        ];
    }

    /** The bounds every census consumer has always applied to an observed width and stem. */
    public static function wpc_census_stem_usable($entry)
    {
        return self::wpc_census_width_usable((int) $entry['css_w'], (string) $entry['stem']);
    }

    private static function wpc_census_width_usable($width, $stem)
    {
        return $width >= 24 && $width <= 2000 && strlen($stem) >= 3
            && preg_match('/^[A-Za-z0-9._@-]+$/', $stem);
    }


    /**
     * The LCP lane's `sizes` when the page's observation gave it none. The page's own value
     * stays (WordPress's default is already the own-width ladder); the capped ladder this
     * plugin used to print becomes the own-width ladder; a tag with no `sizes` gets
     * wps_ic_atf_observation::fallback_sizes(). `auto, ` stays only when the LCP image is kept lazy.
     * Both lanes (CDN and local) call this. The rule and the customer case (acrystalglass.com,
     * a ~2,000 px hero served the 640 rung) are on fallback_sizes().
     */
    private static function apply_lcp_fallback_sizes(array &$original_img_tag, $pageSrcset, $image_source)
    {
        $widthAttr = isset($original_img_tag['original_tags']['width']) ? $original_img_tag['original_tags']['width'] : '';
        $pageSizes = wps_ic_atf_observation::replace_retired_capped_ladder(
            isset($original_img_tag['original_tags']['sizes']) ? $original_img_tag['original_tags']['sizes'] : '',
            $widthAttr,
            $pageSrcset
        );
        $lcpKeptLazy = (bool) apply_filters('wpc_lcp_lazy', false, $image_source);
        if ($pageSizes === '') {
            $ownWidthSizes = wps_ic_atf_observation::fallback_sizes($widthAttr, $pageSrcset);
            $pageSizes = ($ownWidthSizes !== '' && $lcpKeptLazy) ? 'auto, ' . $ownWidthSizes : $ownWidthSizes;
        } elseif (!$lcpKeptLazy) {
            // This lane makes the image eager, and `auto` means nothing on an eager image.
            $pageSizes = (string) preg_replace('/^auto\s*,\s*/i', '', $pageSizes);
        }
        if ($pageSizes === '') {
            unset($original_img_tag['original_tags']['sizes']);
        } else {
            $original_img_tag['original_tags']['sizes'] = $pageSizes;
        }
    }
    /**
     * The measured `sizes` for the delivery lanes that build a srcset or <picture> arms here (the
     * LCP branch, the picture lane, Modern Delivery): the image-sizing owner's answer, '' when it
     * withholds. This used to fill a missing leg with the tag's width or `100vw`, so a one-leg
     * observation still produced a two-leg value, one leg of it a guess.
     */
    public static function wpc_census_slot_sizes($imageUrl, $imgTag = '')
    {
        try {
            if (!apply_filters('wpc_nd_measured_sizes', true) || !class_exists('wps_ic_image_sizing')) { return ''; }
            $tagWidth = preg_match('/\swidth\s*=\s*["\']?(\d+)/', (string) $imgTag, $wm) ? (int) $wm[1] : 0;
            $tagHeight = preg_match('/\sheight\s*=\s*["\']?(\d+)/', (string) $imgTag, $hm) ? (int) $hm[1] : 0;
            $owner = new wps_ic_image_sizing();
            $answer = $owner->sizesFor((string) $imageUrl, $tagWidth, $tagHeight);
            return (string) $answer['sizes'];
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The lcp.json for this request: the page's own, or the home page's while the page has
     * none. The home stand-in exists for the image readers on a page's first renders (census
     * sizes, the LCP image preload), which match every entry against the page's own markup by
     * stem, so a home entry that is not on the page does nothing. Nothing about a page's fonts
     * may read through it: see wpc_page_lcp_json_file().
     */
    public static function wpc_lcp_json_file()
    {
        return self::wpc_resolve_lcp_json_file(true);
    }

    /**
     * The page's own lcp.json, or '' while the page has none. Rule: whatever is derived from
     * a page's own observation (its font subsets above all) comes from that page's
     * observation or from none; the home page's is never a stand-in. Through the home
     * fallback an interior page with no observation of its own built its processed copies
     * with the home page's subset families, so the unicode-range gate was paired with a
     * subset the page does not inline, and every copy was renamed the moment the page's own
     * observation landed (greenvalleytint /services/ 2026-09-24: copies named with the home
     * page's {Poppins} before its own land, renamed after).
     */
    public static function wpc_page_lcp_json_file()
    {
        return self::wpc_resolve_lcp_json_file(false);
    }

    private static function wpc_resolve_lcp_json_file($allowHomeFallback)
    {
        static $resolvedFiles = [];
        $memoKey = $allowHomeFallback ? 'page-or-home' : 'page-own';
        if (isset($resolvedFiles[$memoKey])) {
            return $resolvedFiles[$memoKey];
        }
        $lcpJsonFile = '';
        try {
            if (defined('WPS_IC_CRITICAL') && class_exists('wps_ic_url_key') && function_exists('home_url')) {
                $candidateKeys = [];
                try {
                    $requestUrl = isset($_SERVER['REQUEST_URI'])
                        ? home_url(strtok((string) $_SERVER['REQUEST_URI'], '?')) : '';
                    if ($requestUrl !== '') { $candidateKeys[] = (new wps_ic_url_key())->setup($requestUrl); }
                } catch (\Throwable $e) {
                }
                if ($allowHomeFallback) {
                    try {
                        $candidateKeys[] = (new wps_ic_url_key())->setup(home_url('/'));
                    } catch (\Throwable $e) {
                    }
                }
                foreach (array_unique(array_filter($candidateKeys)) as $candidateKey) {
                    if (@is_readable(WPS_IC_CRITICAL . $candidateKey . '/lcp.json')) {
                        $lcpJsonFile = WPS_IC_CRITICAL . $candidateKey . '/lcp.json';
                        break;
                    }
                }
            }
            // The page's own crit folder, as the critical file resolver keys it (the full
            // request URL, query included).
            if ($lcpJsonFile === '' && class_exists('wps_criticalCss')) {
                $criticalFiles = (new wps_criticalCss())->criticalExists(true);
                if (!empty($criticalFiles['desktop']) && @is_readable(dirname($criticalFiles['desktop']) . '/lcp.json')) {
                    $lcpJsonFile = dirname($criticalFiles['desktop']) . '/lcp.json';
                }
            }
        } catch (\Throwable $e) {
            $lcpJsonFile = '';
        }
        $resolvedFiles[$memoKey] = $lcpJsonFile;
        return $lcpJsonFile;
    }

    // True when the service §14 lcp.json carries a real hero preload directive.
    // The crit head then preloads the measured hero (fires by URL), so the
    // atf-fallback header-logo preload stands down — a tiny logo at High
    // priority was contending with the real LCP. The logo's CLS is still held
    // by the header-img-guard box clamp; only its priority-stealing preload goes.
    public static function wpc_lcp_has_hero_preload()
    {
        return !empty(wps_ic_atf_observation::lcpPreloadHints());
    }

    public static function wpc_census_rung_targets($imageUrl)
    {
        static $rungWidthsByStem = null;
        if ($rungWidthsByStem === null) {
            $rungWidthsByStem = [];
            try {
                // Rungs are accumulated per OBSERVATION, including for a file the page uses
                // more than once: each use needs a rung near its own box, and offering a rung
                // costs nothing — it is the `sizes` attribute that can only name one width,
                // which is why wpc_census_slot() withholds those stems instead.
                foreach (['mobile', 'desktop'] as $device) {
                    foreach (wps_ic_atf_observation::rows(wps_ic_atf_observation::SCOPE_ALL, $device) as $observation) {
                        if (empty($observation['css_w']) || !self::wpc_census_stem_usable($observation)) { continue; }
                        $cssWidth = (int) $observation['css_w'];
                        $observedStem = strtolower($observation['stem']);
                        if (!isset($rungWidthsByStem[$observedStem])) { $rungWidthsByStem[$observedStem] = []; }
                        // 340×519 slot). Filter wpc_census_rung_dpr to tune.
                        // 595w. 2× covers the iPhone class. Slight overshoot beats the
                        // skip-to-full-size undershoot every time.
                        $dprs = apply_filters('wpc_census_rung_dprs', [1.0, 1.75, 2.0]);
                        foreach ((array) $dprs as $dpr) {
                            $dpr = (float) $dpr;
                            if ($dpr < 1 || $dpr > 4) { continue; }
                            $rungWidthsByStem[$observedStem][(int) ceil($cssWidth * $dpr)] = true;
                        }
                    }
                }
            } catch (\Throwable $e) {
                $rungWidthsByStem = [];
            }
        }
        $imageStem = strtolower(basename((string) preg_replace('/[?#].*$/', '', (string) $imageUrl)));
        $imageStem = (string) preg_replace('/\.(?:jpe?g|png|webp|avif|gif)$/i', '', $imageStem);
        $imageStem = (string) preg_replace('/(?:-\d+x\d+)?$/', '', (string) preg_replace('/-scaled$/', '', $imageStem), 1);
        $targets = (!empty($rungWidthsByStem) && $imageStem !== '' && isset($rungWidthsByStem[$imageStem])) ? array_keys($rungWidthsByStem[$imageStem]) : [];
        if (isset($_GET['wpc_census_dbg']) && count(self::$wpc_census_dbg) < 60) {
            self::$wpc_census_dbg[] = ['stem' => $imageStem, 'map' => array_keys((array) $rungWidthsByStem), 'targets' => $targets];
        }
        return $targets;
    }


    public static function wpc_census_format_rungs($imageUrl, $format)
    {
        try {
            if (empty(self::$apiUrl)) { return []; }
            $targets = self::wpc_census_rung_targets($imageUrl);
            if (empty($targets)) { return []; }
            $flag = ($format === 'avif') ? '2' : '0';
            $out = [];
            foreach ($targets as $w) {
                $w = (int) $w;
                if ($w >= 100) {
                    $out[] = self::$apiUrl . '/r:' . self::$isRetina . '/wp:' . $flag . '/w:' . $w
                        . '/u:' . self::uForCdn($imageUrl) . ' ' . $w . 'w';
                }
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    public static function buildLcpSrcset($imageUrl, $srcWidthHint = 0)
    {
        $maxW = !empty(self::$settings['maxWidth']) ? (int) self::$settings['maxWidth'] : 2560;
        if ($maxW < 100) $maxW = 2560;


        $widths = [400, 480, 640, 720, 800, 960, 1100, 1200, 1280, 1366, 1440, 1600, 1800, 2048, 2560];


        if (self::$isMobile && self::$adaptiveEnabled) {
            $mobile_cap_raw = (int) get_option('wpc-min-mobile-width', 400);
            $mobile_cap = (int) apply_filters('wpc_mobile_srcset_cap', $mobile_cap_raw, $imageUrl);
            if ($mobile_cap > 0) {
                $widths_capped = array_values(array_filter($widths, function ($w) use ($mobile_cap) {
                    return $w <= $mobile_cap;
                }));
                if (!empty($widths_capped)) $widths = $widths_capped;
            }
        }


        $effective_max = $maxW;


        $src_w_for_cap = (int) $srcWidthHint;
        $attachment_id = (int) self::wpc_att_id($imageUrl);
        if ($attachment_id > 0 && function_exists('wp_get_attachment_metadata')) {
            $am = wp_get_attachment_metadata($attachment_id);
            if (is_array($am) && !empty($am['width']) && !empty($am['height'])) {
                $sw = (int) $am['width'];
                $sh = (int) $am['height'];
                if ($sh > $sw && $sh > 0) {
                    // Portrait — cap width so encoded height ≤ $maxW
                    $effective_max = (int) floor($maxW * ($sw / $sh));
                }
                if ($sw > 0) $src_w_for_cap = ($src_w_for_cap > 0) ? min($src_w_for_cap, $sw) : $sw;
            }
        }
        // The -WxH intermediate suffix is an intrinsic ceiling too (same idiom as
        // naturalize_srcset_widths): a 600x506 source cannot fill a 2560w descriptor, and the
        // attachment lookup above only resolves FULL-SIZE urls — an intermediate returns 0 and
        // the ladder ran uncapped to maxWidth.
        if (preg_match('#-(\d{2,5})x(\d{2,5})(?:-scaled)?\.(?:jpe?g|png|gif|webp|avif)(?:[?\#]|$)#i', (string) $imageUrl, $suffix_match)) {
            $suffix_width = (int) $suffix_match[1];
            if ($suffix_width >= 100) {
                $src_w_for_cap = ($src_w_for_cap > 0) ? min($src_w_for_cap, $suffix_width) : $suffix_width;
            }
        }
        // .298 — webp/avif uploads resolve no attachment id and carry no -WxH suffix:
        // the ladder ran uncapped to 2560w over a 92px logo and DPR-2 browsers halved the
        // paint (descriptor lie: the proxy never upscales). Measured local file is the cap.
        if ($src_w_for_cap === 0) {
            $true_dims = self::wpc_true_image_dimensions($imageUrl);
            if (is_array($true_dims)) {
                $true_width = (int) ($true_dims['width'] ?? $true_dims[0] ?? 0);
                if ($true_width > 0) { $src_w_for_cap = $true_width; }
            }
        }
        if ($src_w_for_cap > 0) $effective_max = min($effective_max, $src_w_for_cap);

        $widths = array_unique(array_map(function ($w) use ($effective_max) {
            return min($w, $effective_max);
        }, $widths));


        foreach (self::wpc_census_rung_targets($imageUrl) as $census_width) {
            $census_width = (int) $census_width;
            if ($census_width >= 100) {
                $widths[] = min($census_width, $effective_max);
            }
        }
        $widths = array_unique($widths);
        sort($widths);

        // Build the /wp:X segment matching the format used elsewhere in this file
        // (e.g. line 485). Respect per-URL webp exclusion.
        $webpSegment = '/wp:' . self::$webp;
        if (self::isExcludedFrom('webp', $imageUrl)) {
            $webpSegment = '';
        }

        $candidates = [];
        foreach ($widths as $w) {
            $candidates[] = self::$apiUrl . '/r:' . self::$isRetina . $webpSegment . '/w:' . $w . '/u:' . self::uForCdn($imageUrl) . ' ' . $w . 'w';
        }

        return implode(', ', $candidates);
    }

    public function favIcon($html)
    {
        $html = preg_replace_callback('/<link\s+([^>]+[\s\'"])?rel\s*=\s*[\'"]icon[\'"]/is', [__CLASS__, 'checkFavIcon'], $html);

        return $html;
    }

    public function checkFavIcon($html)
    {
        if (empty($html)) {
            return 'no favicon';
        } else {
            return print_r([$html], true);
        }
    }

    public function runCriticalAjax($html)
    {

        if (str_contains($html, 'wpcRunningCritical')) {
            return $html;
        } else {
            $html = preg_replace_callback('/<\/body>/si', [__CLASS__, 'addCriticalAjax'], $html, 1);
        }

        return $html;
    }

    public function addCriticalAjax($args)
    {
        global $post;

        // NEW API  does not need this code:
        //return '</body>';


        // $_SERVER['REQUEST_URI'] (attacker-influenced) straight into the page HTML = reflected XSS.
        if (!empty($_GET['test_adding_critical_ajax']) && function_exists('current_user_can') && current_user_can('manage_options')) {
            $script  = esc_html(print_r($post, true));
            $script .= esc_html((string) ($_SERVER['HTTP_HOST'] ?? '') . (string) ($_SERVER['REQUEST_URI'] ?? ''));
            return $script;
        }

        if ($this->isWooCartOrCheckout()) {
            return '</body>';
        }


        $wpc_crit_post_id = (isset($post) && !empty($post->ID)) ? $post->ID : '';
        if ($wpc_crit_post_id === '' && function_exists('is_front_page') && (is_front_page() || is_home())) {
            $wpc_crit_post_id = 'home';
        }

        $script = '';
        if (!empty($wpc_crit_post_id)) {


            $realUrl = rawurlencode((string) ($_SERVER['HTTP_HOST'] ?? '') . (string) ($_SERVER['REQUEST_URI'] ?? ''));

            // TODO: Issues if DelayJS is disabled
            $script = <<<SCRIPT
<script type="text/javascript">
    let wpcRunningCritical = false;

    function handleUserInteraction() {
        if (typeof ngf298gh738qwbdh0s87v_vars === 'undefined') {
            return;
        }

        if (wpcRunningCritical) {
            return;
        }

        wpcRunningCritical = true;

        var xhr = new XMLHttpRequest();
        xhr.open("POST", ngf298gh738qwbdh0s87v_vars.ajaxurl, true);
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.onreadystatechange = function() {
            if (xhr.readyState == 4 && xhr.status == 200) {
                var response = JSON.parse(xhr.responseText);
                if (response.success) {
                    console.log("Started Critical Call");
                }
            }
        };

        xhr.send("action=wpc_send_critical_remote&postID={$wpc_crit_post_id}&realUrl={$realUrl}");

        removeEventListeners();
    }

    function removeEventListeners() {
        document.removeEventListener("keydown", handleUserInteraction);
        document.removeEventListener("mousedown", handleUserInteraction);
        document.removeEventListener("mousemove", handleUserInteraction);
        document.removeEventListener("touchmove", handleUserInteraction);
        document.removeEventListener("touchstart", handleUserInteraction);
        document.removeEventListener("touchend", handleUserInteraction);
        document.removeEventListener("wheel", handleUserInteraction);
        document.removeEventListener("visibilitychange", handleUserInteraction);
        document.removeEventListener("load", handleUserInteraction);
    }

    document.addEventListener("keydown", handleUserInteraction);
    document.addEventListener("mousedown", handleUserInteraction);
    document.addEventListener("mousemove", handleUserInteraction);
    document.addEventListener("touchmove", handleUserInteraction);
    document.addEventListener("touchstart", handleUserInteraction);
    document.addEventListener("touchend", handleUserInteraction);
    document.addEventListener("wheel", handleUserInteraction);
    document.addEventListener("visibilitychange", handleUserInteraction);
    document.addEventListener("load", handleUserInteraction);
</script>
SCRIPT;


        }
        return $script . '</body>';
    }

    public function isWooCartOrCheckout()
    {
        // Check if WooCommerce is active
        if (class_exists('WooCommerce')) {
            // Check if current page is Cart or Checkout
            if (is_cart() || is_checkout()) {
                return true;
            }
        }
        return false;
    }

    /** $fontFaces is the render's @font-face owner ($ctx->fontFaces): every face the crit lane
     *  produces is registered with it, and stage_emit_font_faces writes them. */
    public function addCritical($html, wps_ic_font_face_set $fontFaces, wps_ic_image_preload_set $imagePreloads)
    {
        $criticalCss = $this->addCriticalCSS($html, $fontFaces, $imagePreloads);
        $criticalCss = $this->filterCriticalFontFaces($criticalCss);


        $gfFaces = $this->maybeInlineGoogleFontFaces($html, $criticalCss);
        if ($gfFaces !== ''
            && self::wpc_fonts_lane_should_yield([(function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop'])) {
            $gfFaces = '';
        }
        if ($gfFaces !== '') {
            $gfFaces = $this->filterCriticalFontFaces($gfFaces);
        }
        if ($gfFaces !== '' && apply_filters('wpc_gfaces_latin_only', self::wpc_gfaces_latin_default())) {
            $gfFaces = self::wpc_gfaces_prune_ranges($gfFaces);
        }
        if ($gfFaces !== '') {
            $criticalCss = $gfFaces . $criticalCss;


            if (stripos($criticalCss, 'fonts.gstatic.com') !== false) {
                // Remove only the exact faces the inlined set replaces: same family+weight+style,
                // latin-covering range. Other subsets and weights keep their original faces.
                $replacedFaceKeys = [];
                if (preg_match_all('/@font-face\s*\{[^}]*\}/is', $gfFaces, $gfFaceMatches)) {
                    foreach ($gfFaceMatches[0] as $gfFace) {
                        $k = self::wpc_face_key($gfFace);
                        if ($k !== '') {
                            $replacedFaceKeys[$k] = 1;
                        }
                    }
                }
                if ($replacedFaceKeys) {
                    $criticalCss = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($replacedFaceKeys) {
                        if (stripos($m[0], 'fonts.gstatic.com') === false) {
                            return $m[0];
                        }
                        if (!self::wpc_face_range_latin($m[0])) {
                            return $m[0];
                        }
                        $k = self::wpc_face_key($m[0]);
                        return ($k !== '' && isset($replacedFaceKeys[$k])) ? '' : $m[0];
                    }, $criticalCss);
                }
            }
        }


        // Kill: wpc_crit_font_localize.
        if (apply_filters('wpc_crit_font_localize', true)
            && stripos($criticalCss, 'fonts.gstatic.com') !== false
            && function_exists('wp_get_upload_dir')) {
            try {
                static $wpc_lf_map = null;
                if ($wpc_lf_map === null) {
                    $wpc_lf_map = [];
                    $wpc_up = wp_get_upload_dir();
                    $wpc_dirs = [rtrim((string) $wpc_up['basedir'], '/') . '/elementor/google-fonts'];
                    if (defined('WPS_IC_FONTS_DIR')) {
                        $wpc_dirs[] = rtrim(WPS_IC_FONTS_DIR, '/');
                    }
                    foreach ($wpc_dirs as $wpc_fd) {
                        if (!is_dir($wpc_fd)) {
                            continue;
                        }
                        $wpc_it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wpc_fd, FilesystemIterator::SKIP_DOTS));
                        $wpc_n  = 0;
                        foreach ($wpc_it as $wpc_f) {
                            if (strtolower($wpc_f->getExtension()) !== 'woff2' || $wpc_n >= 200) {
                                continue;
                            }
                            $wpc_rel = str_replace(rtrim((string) $wpc_up['basedir'], '/'), rtrim((string) $wpc_up['baseurl'], '/'), $wpc_f->getPathname());
                            if (strpos($wpc_rel, 'http') === 0 && !isset($wpc_lf_map[$wpc_f->getBasename()])) {
                                $wpc_lf_map[$wpc_f->getBasename()] = ['u' => $wpc_rel, 'p' => $wpc_f->getPathname(), 's' => (int) $wpc_f->getSize()];
                                $wpc_n++;
                            }
                        }
                    }
                }
                if (!empty($wpc_lf_map)) {
                    $wpc_done_lf = 0;
                    $criticalCss = preg_replace_callback('#https://fonts\.gstatic\.com/[^\s"\')]+/([^/\s"\')]+\.woff2)#i',
                        function ($m) use ($wpc_lf_map, &$wpc_done_lf) {
                            if ($wpc_done_lf < 30 && isset($wpc_lf_map[$m[1]])) {
                                $wpc_done_lf++;
                                return $wpc_lf_map[$m[1]]['u'];
                            }
                            return $m[0];
                        }, $criticalCss);


                    if ($wpc_done_lf > 0 && apply_filters('wpc_atf_face_preload', true)) {
                        $wpc_pl_out = '';
                        $wpc_pl_n   = 0;
                        $wpc_in_n   = 0;
                        $wpc_in_b   = 0;
                        if (preg_match_all('#@font-face\s*\{[^}]*\}#is', $criticalCss, $wpc_faces)) {
                            foreach ($wpc_faces[0] as $wpc_face) {
                                if (($wpc_pl_n + $wpc_in_n) >= 3 || stripos($wpc_face, 'gstatic') !== false) {
                                    continue;
                                }
                                if (preg_match('/unicode-range\s*:/i', $wpc_face)
                                    && !preg_match('/U\+0000/i', $wpc_face)) {
                                    continue;
                                }
                                if (!preg_match('/font-weight\s*:\s*(400|500|600|700)\b/i', $wpc_face)
                                    || preg_match('/font-style\s*:\s*italic/i', $wpc_face)) {
                                    continue;
                                }
                                if (!preg_match('#url\((["\']?)(https?://[^"\')]+/([^/"\')]+\.woff2))\1\)#i', $wpc_face, $wpc_pu)) {
                                    continue;
                                }
                                $wpc_bn = $wpc_pu[3];
                                if ($wpc_in_n < 2 && isset($wpc_lf_map[$wpc_bn]['p'])
                                    && $wpc_lf_map[$wpc_bn]['s'] > 0 && $wpc_lf_map[$wpc_bn]['s'] <= 20480
                                    && ($wpc_in_b + $wpc_lf_map[$wpc_bn]['s']) <= 49152
                                    && apply_filters('wpc_atf_face_inline', true)) {
                                    $wpc_bytes = @file_get_contents($wpc_lf_map[$wpc_bn]['p']);
                                    if ($wpc_bytes !== false && $wpc_bytes !== '') {
                                        $wpc_new_face = str_replace($wpc_pu[2], 'data:font/woff2;base64,' . base64_encode($wpc_bytes), $wpc_face);
                                        $criticalCss  = str_replace($wpc_face, $wpc_new_face, $criticalCss);
                                        $wpc_in_n++;
                                        $wpc_in_b += $wpc_lf_map[$wpc_bn]['s'];
                                        continue;
                                    }
                                }
                                $wpc_pl_out .= esc_url($wpc_pu[2]) . "\n";
                                $wpc_pl_n++;
                            }
                        }
                        if ($wpc_pl_out !== '' && function_exists('wpc_font_preload_postpaint_tag')) {
                            // v7.10.689 — post-paint injected; a static as=font tag render-holds Chrome 150.
                            $criticalCss = wpc_font_preload_postpaint_tag(array_filter(explode("\n", $wpc_pl_out))) . $criticalCss;
                        }
                    }
                }
            } catch (\Throwable $e) {
            }
        }

        // Extract font preloads AFTER filtering — only preload fonts that survive.
        // With base64-inlined ATF faces already in the crit blob, first paint is guaranteed
        // without the full files — their preloads would only occupy pre-paint bandwidth
        // (the 90KB Roboto pair on the flagship). They load naturally and swap in.
        $subsetsSeenAt = (int) get_option('wpc_subsets_seen', 0);
        if (!empty(self::$settings['preload-crit-fonts']) && self::$settings['preload-crit-fonts'] == '1'
            && (stripos($criticalCss, 'data:font/woff2;base64') === false
                || !apply_filters('wpc_subset_covers_preloads', true))
            && !($subsetsSeenAt && (time() - $subsetsSeenAt) < 7 * DAY_IN_SECONDS && apply_filters('wpc_subset_covers_preloads', true))) {
            $preloadLinks = $this->extractCriticalFontPreloads($criticalCss);
            $criticalCss = $preloadLinks . $criticalCss;


            if (function_exists('wpc_perf_debug_is_allowed') && wpc_perf_debug_is_allowed()
                && !empty(self::$wpc_font_preloads_emitted)) {
                $emittedPreloads  = (array) self::$wpc_font_preloads_emitted;
                $haystack = $html . $criticalCss;
                $foundCount  = 0; $missingPreloads = [];
                foreach ($emittedPreloads as $preloadUrl) {
                    if (strpos($haystack, (string) $preloadUrl) !== false) { $foundCount++; }
                    else { $missingPreloads[] = basename((string) $preloadUrl); }
                }
                $criticalCss .= "\r\n<!-- WPC-FONT-PRELOAD-PARITY " . $foundCount . '/' . count($emittedPreloads)
                    . ($missingPreloads ? ' MISS:' . implode(',', array_slice($missingPreloads, 0, 4)) : ' OK') . " -->";
            }
        }

        if (!empty($_GET['extractCrit'])) {
            return print_r([$criticalCss], true);
        }


        // Canonical WordPress screen-reader/skip-link hiding — always present with crit so a
        // pruned theme rule can never surface these links visibly.
        $firstFrameGuards = [];
        if (apply_filters('wpc_sr_guard', true)
            && (stripos($html, 'skip-link') !== false || stripos($html, 'screen-reader-text') !== false)) {
            $firstFrameGuards['sr'] = 1;
            $criticalCss .= "\r\n" . '<style id="wpc-sr-guard">.screen-reader-text,.skip-link{border:0;clip:rect(1px,1px,1px,1px);clip-path:inset(50%);height:1px;margin:-1px;overflow:hidden;padding:0;position:absolute;width:1px;word-wrap:normal}.screen-reader-text:focus,.skip-link:focus{clip:auto;clip-path:none;height:auto;width:auto;overflow:visible;position:absolute;left:6px;top:6px;z-index:100000;padding:8px 16px;background:#fff}</style>';
        }
        // Content-embedded <img class="emoji"> needs core's 1em rule at first paint.
        if (apply_filters('wpc_emoji_guard', true)
            && (strpos($html, 'class="emoji"') !== false || strpos($html, "class='emoji'") !== false || strpos($html, 'wp-smiley') !== false)) {
            $firstFrameGuards['emoji'] = 1;
            $criticalCss .= "\r\n" . '<style id="wpc-emoji-guard">img.wp-smiley,img.emoji{display:inline!important;border:none!important;box-shadow:none!important;height:1em!important;width:1em!important;margin:0 .07em!important;vertical-align:-0.1em!important;background:none!important;padding:0!important}</style>';
        }
        // A header image's displayed box must be fully determined at first paint: attribute
        // dims give the ratio, but without the container clamp (which normally arrives with
        // the deferred sheets) the image can paint at its full attribute width and re-lay the
        // header when the real CSS lands. The clamp makes the pre-CSS box = final box.
        if (apply_filters('wpc_header_img_guard', true)
            && preg_match('/<(?:header\b|div[^>]*elementor-location-header)[^>]*>.{0,3000}?<img/is', $html)) {
            // :where() keeps the guard at (0,0,1) so a theme's own header sizing wins ties (.354 class).
            $firstFrameGuards['header_clamp'] = 1;
            $criticalCss .= "\r\n" . '<style id="wpc-header-img-guard">:where(header) img,:where(.elementor-location-header) img{max-width:100%;height:auto}</style>';
        }
        // The header logo paints in every viewport's first frame — if the file lands after
        // paint, its arrival re-lays the header row (the visible menu drop). Preload the
        // first header image so it exists before the frame it appears in.
        if (apply_filters('wpc_header_logo_preload', true)
            && !self::wpc_lcp_has_hero_preload()
            && preg_match('/<(?:header\b|div[^>]*elementor-location-header)[^>]*>.{0,3000}?<img[^>]*src="([^"]+\.(?:svg|png|webp|avif|jpe?g))(?:\?[^"]*)?"/is', $html, $headerLogoMatch)
            && stripos($criticalCss, esc_url($headerLogoMatch[1])) === false
            && self::wpc_lcp_bg_url_allowed($headerLogoMatch[1])
            // v7.10.718 - an svg the eager lane will inline as data: needs no preload; the
            // preload would fetch a URL the page never uses.
            && !(function_exists('wpc_svg_inline_data') && wpc_svg_inline_data($headerLogoMatch[1]) !== '')) {
            $imagePreloads->add('wpc-header-logo-preload', esc_url($headerLogoMatch[1]), 'both', 'header-logo',
                wps_ic_image_preload_set::RANK_HEADER_LOGO);
        }
        // Icon-font box reservation: a delayed icon kit (FontAwesome et al.) leaves <i>
        // elements zero-height until it lands, then every line holding one grows — the
        // interaction snap. Reserve the glyph's final 1em box up front; the kit's own CSS
        // sets the identical metrics on arrival, so the box never changes.
        // v7.10.848 — markup alone is not evidence the icon font will ever arrive: on sites
        // that reference fa-* but never enqueue any FontAwesome (fa-duotone = Pro, not
        // installed), the reserve was a permanent phantom box (23x23 inside buttons). Two
        // layers: (1) emit only when the page plausibly delivers an icon font — an enqueued
        // fontawesome asset, an /fa-*.css sheet, or a font-family declaration; (2) a settle
        // belt that disables the guard after fonts settle if no FontAwesome family actually
        // LOADED (document.fonts entries with status==='loaded' — never fonts.check(), which
        // returns true for nonexistent families) and re-enables it if a late kit lands.
        if (apply_filters('wpc_icon_box_guard', true)
            && preg_match('/<(?:i|span)\s[^>]*class="(?:[^"]*\s)?fa[srlb]?[\s-]/', $html)) {
            $fontAwesomePresent = (stripos($html, 'fontawesome') !== false
                || stripos($html, 'font-awesome') !== false
                || preg_match('/<link[^>]+href="[^"]*\/fa-[^"]*\.css[^"]*"/i', $html)
                || preg_match('/font-family\s*:\s*["\']?\s*font.?awesome/i', $html . $criticalCss));
            if (apply_filters('wpc_icon_guard_witness', $fontAwesomePresent, $html)) {
                $firstFrameGuards['icon_box'] = 1;
                $criticalCss .= "\r\n" . '<style id="wpc-icon-guard">i[class^="fa-"],i[class*=" fa-"],span[class^="fa-"],span[class*=" fa-"]{display:inline-block;min-width:1em;height:1em;line-height:1}</style>';
                $criticalCss .= "\r\n" . '<script id="wpc-icon-belt">(function(){var g=null;function fa(){var ok=false;try{document.fonts.forEach(function(f){if(f.status===\'loaded\'&&/font.?awesome|fa-(?:brands|solid|regular|light|duotone|sharp)/i.test(f.family)){ok=true}})}catch(e){ok=true}return ok}function set(off){g=g||document.getElementById(\'wpc-icon-guard\');if(g){g.media=off?\'not all\':\'all\'}}function check(){set(!fa())}if(document.fonts&&document.fonts.ready&&document.fonts.forEach){document.fonts.ready.then(function(){setTimeout(check,3500)});if(document.fonts.addEventListener){document.fonts.addEventListener(\'loadingdone\',function(){if(fa()){set(false)}})}}})();</script>';
            }
        }
        // Native next-page prefetch (Speculation Rules): pointer-down prefetch of same-site
        // links so the next navigation paints near-instantly. Conservative eagerness fires
        // ~100ms before the click would anyway; state-changing and session URLs excluded.
        // Non-supporting browsers ignore the block entirely.
        // v7.21.130 — when the Instant Navigation toggle is ON, wps_ic_speculation_rules
        // (service-team class: prerender + core-suppress + Woo-derived excludes) is the single
        // owner of the speculationrules tag; this legacy prefetch-only block yields so exactly
        // one emitter exists. Toggle OFF keeps this block for pre-6.8 WP (on 6.8+ core's own
        // tag trips the dedupe below, which is the intended conservative fallback).
        $savedSettings = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        $speculationRulesOn = is_array($savedSettings) && !empty($savedSettings['speculation-rules']) && $savedSettings['speculation-rules'] == '1';
        if (!$speculationRulesOn && apply_filters('wpc_speculation_rules', true) && stripos($html, 'speculationrules') === false) {
            $criticalCss .= "\r\n" . '<script type="speculationrules" id="wpc-speculation">'
                . '{"prefetch":[{"source":"document","eagerness":"conservative","where":{"and":['
                . '{"href_matches":"/*","relative_to":"document"},'
                . '{"not":{"href_matches":["/wp-admin/*","/wp-login.php*","/cart/*","/checkout/*","/my-account/*","/feed/*"]}},'
                . '{"not":{"href_matches":"*add-to-cart=*"}},'
                . '{"not":{"href_matches":"*logout*"}},'
                . '{"not":{"selector_matches":"a[rel~=nofollow]"}}'
                . ']}}]}</script>';
        }
        // Below-fold sections skip style/layout until scrolled near. Conservative fold
        // heuristic: the first three top-level sections render normally on every device.
        // Elementor injects the hosted bg <video> via JS while its absolute-positioning
        // lives in a deferred sheet — between crit-paint and the late flip it sits in
        // normal flow and pushes the section (hawkeye 0.131). Pin the containment at crit.
        // v7.10.627 — NEVER-BLACK SHAPE DIVIDERS. An SVG path with no fill rule paints
        // BLACK by default, and every shape-divider fill is a per-section rule that lives
        // below the ATF cut — so between crit-paint and the late/REST flip a decorative
        // divider renders as a solid black band (James, /pricing/ + wpcompress.com home;
        // worse on tall screens, where more of the below-ATF region is visible at first
        // paint). Pinning transparent at crit makes the unstyled state INVISIBLE instead of
        // black; every real fill rule is more specific (.elementor-{post} .elementor-element-{id})
        // so it wins the moment it lands — including deliberately black dividers.
        if (apply_filters('wpc_shape_fill_guard', true) && stripos($html, 'elementor-shape') !== false) {
            // Specificity is deliberately ONE class (0,1,0) — the same as Elementor's own
            // default — so any equal-or-stronger rule later in the cascade wins, and the
            // loader removes this node outright once real CSS has landed. Only the class
            // Elementor puts on paths it expects CSS to fill; never a bare `svg path`,
            // which would also override a shape's own fill attribute.
            $firstFrameGuards['shape_fill'] = 1;
            $criticalCss .= "\r\n" . '<style id="wpc-shape-fill-guard">.elementor-shape-fill{fill:transparent}</style>';
        }
        // v7.21.58 — Flatsome shape dividers (ctfx desktop CLS 0.248, .ux-shape-divider--top
        // --style-arrow): the divider's absolute-positioning lives in flatsome.css, which is
        // PARKED — between crit-paint and activation the divider sits in normal flow at its
        // natural SVG height and shoves the section down; when the sheet lands it snaps
        // out-of-flow and everything shifts back up. Mirror the theme's own geometry rules
        // (same selectors, same specificity — re-application is a byte-identical no-op; inline
        // per-divider height styles still win) so first-frame layout equals final layout.
        // Same law as the Elementor bg-video pin below: an out-of-flow element must be
        // out-of-flow from the FIRST frame.
        if (apply_filters('wpc_ux_shape_divider_guard', true) && stripos($html, 'ux-shape-divider') !== false) {
            $firstFrameGuards['ux_divider'] = 1;
            $criticalCss .= "\r\n" . '<style id="wpc-ux-divider-guard">.ux-shape-divider{--divider-top-width:100%;--divider-width:100%;left:0;line-height:0;overflow:hidden;position:absolute;width:100%}.ux-shape-divider svg{display:block;height:150px;left:50%;position:relative;transform:translateX(-50%)}.ux-shape-divider--top{top:-1px;transform:rotate(180deg)}.ux-shape-divider--top svg{width:calc(var(--divider-top-width) + 2px)}.ux-shape-divider--bottom{bottom:-1px}.ux-shape-divider--bottom svg{width:calc(var(--divider-width) + 2px)}.ux-shape-divider--flip svg{transform:translateX(-50%) rotateY(180deg)}.ux-shape-divider--to-front{z-index:2}.ux-shape-divider .ux-shape-fill{fill:#fff}</style>';
        }
        // v7.21.61 — FLATSOME FIRST-FRAME GRID + SLIDER PRE-INIT. Measured on ctfx after the
        // .60 geometry blocks went live: the document still shrank ~4,100px at sheet
        // activation — two .row.slider lightbox-multi-gallery rows rendered STACKED
        // full-width (1780->254px, 2561->712px) because the grid width wheels and the
        // theme's own .slider:not(.flickity-enabled) pre-init containment all live in the
        // PARKED flatsome.css, and this site's crit did not carry them. Every rule below is
        // mirrored byte-for-byte from the theme's sheet (never
        // invent geometry): the per-col wheel (.small/.medium/.large-N, N/12), the
        // row-columns wheel (.X-columns-N>.col, 1/N), and the slider pre-init set. Fresh
        // sites with zero crit paint the correct first frame out of the box.
        if (apply_filters('wpc_flatsome_grid_guard', true) && stripos($html, 'themes/flatsome') !== false) {
            $twelveColumnWidths = ['8.3333333333%', '16.6666666667%', '25%', '33.3333333333%', '41.6666666667%', '50%',
                '58.3333333333%', '66.6666666667%', '75%', '83.3333333333%', '91.6666666667%', '100%'];
            $rowColumnWidths = ['100%', '50%', '33.3333333333%', '25%', '20%', '16.6666666667%', '14.2857142857%', '12.5%'];
            $flatsomeGridCss = '.row{display:flex;flex-flow:row wrap;width:100%}';
            foreach (['small' => '', 'medium' => '@media screen and (min-width:550px){', 'large' => '@media screen and (min-width:850px){'] as $breakpoint => $mediaOpen) {
                $flatsomeGridCss .= $mediaOpen;
                foreach ($twelveColumnWidths as $columnIndex => $columnWidth) {
                    $flatsomeGridCss .= '.' . $breakpoint . '-' . ($columnIndex + 1) . '{flex-basis:' . $columnWidth . ';max-width:' . $columnWidth . '}';
                }
                foreach ($rowColumnWidths as $columnCountIndex => $perColumnWidth) {
                    $flatsomeGridCss .= '.' . $breakpoint . '-columns-' . ($columnCountIndex + 1) . ' .flickity-slider>.col,.'
                        . $breakpoint . '-columns-' . ($columnCountIndex + 1) . '>.col{flex-basis:' . $perColumnWidth . ';max-width:' . $perColumnWidth . '}';
                }
                $flatsomeGridCss .= $mediaOpen !== '' ? '}' : '';
            }
            // v7.21.62 — the two rules that actually make a pre-init slider ONE ROW (measured:
            // with only the .61 set the galleries still stacked 1780px — the .row flex-wrap
            // won): the theme flips a slider row to display:block and its children to
            // inline-block inside the nowrap scroller. Both mirrored byte-for-byte.
            $flatsomeGridCss .= '.row-slider,.slider{position:relative;scrollbar-width:none}'
                . '.row.row-slider:not(.flickity-enabled){display:block}'
                . '.slider:not(.flickity-enabled){-ms-overflow-style:-ms-autohiding-scrollbar;overflow-x:scroll;overflow-y:hidden;white-space:nowrap;width:auto}'
                . '.slider:not(.flickity-enabled)>*{display:inline-block!important;vertical-align:top;white-space:normal!important}'
                . '.slider:not(.flickity-enabled)>*{position:relative!important}'
                . '.slider-load-first:not(.flickity-enabled){max-height:500px}'
                . '.slider-load-first:not(.flickity-enabled)>div{opacity:0}';
            $criticalCss .= "\r\n" . '<style id="wpc-flatsome-grid-guard">' . $flatsomeGridCss . '</style>';
        }
        if (apply_filters('wpc_bg_video_guard', true) && stripos($html, 'elementor-background-video') !== false) {
            $firstFrameGuards['bg_video'] = 1;
            $criticalCss .= "\r\n" . '<style id="wpc-bg-video-guard">.elementor-background-video-container{position:absolute;top:0;left:0;width:100%;height:100%;overflow:hidden;pointer-events:none}.elementor-background-video-hosted,.elementor-background-video-embed{position:absolute;max-width:none}</style>';
        }
        // The below-fold containment guard (id wpc-cv-guard) ships from the pass that stamps
        // the attribute it reads (cdn-rewrite.php), not from here: that pass runs on every
        // page, so containment must not depend on critical CSS being active.
        if (apply_filters('wpc_elementor_anim_start_state', true) && stripos($html, 'elementor-invisible') !== false) {
            $firstFrameGuards['anim_start'] = 1;
            $criticalCss .= "\r\n" . '<style id="wpc-elementor-anim-start">.elementor-invisible{visibility:hidden}</style>';
        }
        // v7.21.302 — NAV HOVER GUARD. Elementor nav dropdowns need smartmenus (delayed):
        // first hover ran the replay and the menu opened ~1.9s later (bestexteriorsinc,
        // measured hover->visible). Pure-CSS :hover fallback opens them instantly, scoped
        // under html:not(.wpc-js-live) — the loader stamps wpc-js-live the moment replay
        // completes, so the shim retires exactly when the real handlers arm. Zero JS,
        // zero fetches, invisible to labs (they never hover).
        if (apply_filters('wpc_nav_hover_guard', true) && stripos($html, 'elementor-nav-menu') !== false) {
            $firstFrameGuards['nav_hover'] = 1;
            $criticalCss .= "\r\n" . '<style id="wpc-nav-hover-guard">'
                . 'html:not(.wpc-js-live) .elementor-nav-menu li.menu-item-has-children:hover>ul.sub-menu{display:block;position:absolute;top:100%;left:0;z-index:99999;width:max-content;min-width:100%;max-width:90vw}'
                . 'html:not(.wpc-js-live) .elementor-nav-menu li.menu-item-has-children:hover>ul.sub-menu>li>a{white-space:nowrap}'
                . 'html:not(.wpc-js-live) .elementor-nav-menu li li.menu-item-has-children:hover>ul.sub-menu{top:0;left:100%}'
                . '</style>';
        }
        // v7.21.293 — ELEMENTOR CAROUSEL PRE-INIT GUARD. Delayed JS means swiper never inits
        // before the first gesture; un-inited slides render as a sparse strip (beucomply
        // Trusted-by bar: 3 of 12 logos clustered left). The widget's own data-settings
        // DECLARES the final geometry (slides_to_show + spacing) — mirror exactly what init
        // will compute, scoped to :not(.swiper-initialized) so the guard retires itself the
        // frame swiper arms. Never invent geometry: widgets without parseable settings are
        // skipped, undeclared spacing emits no margin.
        // v7.21.333 — the NESTED Carousel (n-carousel, justmsp badge bar) declares the same
        // slides_to_show/image_spacing_custom keys but a different wrapper (.e-n-carousel
        // .swiper) and widget class; uncovered, its pre-init state renders ONE full-width
        // slide until swiper arms at replay. Same guard, second matcher.
        if (apply_filters('wpc_swiper_preinit_guard', true)
            && (stripos($html, 'elementor-image-carousel-wrapper') !== false
                || stripos($html, 'elementor-widget-n-carousel') !== false)) {
            $carouselTags = [0 => []];
            $carouselSlideSelectors = [];
            if (preg_match_all('~<div[^>]+data-widget_type="image-carousel\.default"[^>]*>~i', $html, $imageCarouselMatches) > 0) {
                foreach ($imageCarouselMatches[0] as $wpc_t) {
                    $carouselTags[0][] = $wpc_t;
                    $carouselSlideSelectors[] = '.elementor-image-carousel-wrapper:not(.swiper-initialized) .swiper-slide';
                }
            }
            if (preg_match_all('~<div[^>]+class="[^"]*elementor-widget-n-carousel[^"]*"[^>]*>~i', $html, $nestedCarouselMatches) > 0) {
                foreach ($nestedCarouselMatches[0] as $wpc_t) {
                    $carouselTags[0][] = $wpc_t;
                    $carouselSlideSelectors[] = ' .e-n-carousel:not(.swiper-initialized) .swiper-slide';
                }
            }
            $preinitCss = '';
            $nestedCarouselUsed = false;
            foreach (array_slice($carouselTags[0], 0, 12) as $carouselIndex => $carouselTag) {
                if (!preg_match('~data-id="([a-f0-9]{4,10})"~i', $carouselTag, $idMatch)) { continue; }
                $elementId = $idMatch[1];
                if (!preg_match('~data-settings="([^"]*)"~i', $carouselTag, $settingsMatch)) { continue; }
                $carouselSettings = json_decode(html_entity_decode($settingsMatch[1], ENT_QUOTES), true);
                if (!is_array($carouselSettings)) { continue; }
                $desktopSlides = max(1, (int) (isset($carouselSettings['slides_to_show']) ? $carouselSettings['slides_to_show'] : 3));
                $tabletSlides = max(1, (int) (isset($carouselSettings['slides_to_show_tablet']) ? $carouselSettings['slides_to_show_tablet'] : $desktopSlides));
                $mobileSlides = max(1, (int) (isset($carouselSettings['slides_to_show_mobile']) ? $carouselSettings['slides_to_show_mobile'] : $tabletSlides));
                $slideSpacing = 0;
                if (isset($carouselSettings['image_spacing_custom']['size']) && is_numeric($carouselSettings['image_spacing_custom']['size'])) {
                    $slideSpacing = (int) $carouselSettings['image_spacing_custom']['size'];
                }
                // v7.21.340 — WIDESCREEN/LAPTOP TIERS MIRRORED TOO (justmsp on a >2400px
                // display: widget declared widescreen geometry the guard didn't mirror ->
                // pre-init/armed mismatch = the "loads cut off then double loads").
                // v7.21.341 — MIRROR THE ENGINE, NOT THE DECLARATION: Elementor's swiper
                // handler never consumes slides_to_show_widescreen (measured on the live
                // widget: declared 6, breakpoints map 2400 -> slidesPerView 4 = desktop;
                // widescreen SPACING does apply). A 6-up pre-init snapped to native 4-up
                // at arm. The widescreen rung keeps the DESKTOP count and mirrors only
                // the widescreen spacing; no rung at all when that spacing is undeclared
                // or equal (the desktop rule already matches native there).
                $laptopSlides = isset($carouselSettings['slides_to_show_laptop']) && (int) $carouselSettings['slides_to_show_laptop'] > 0
                    ? (int) $carouselSettings['slides_to_show_laptop'] : 0;
                $widescreenSpacing = -1;
                if (isset($carouselSettings['image_spacing_custom_widescreen']['size'])
                    && is_numeric($carouselSettings['image_spacing_custom_widescreen']['size'])) {
                    $widescreenSpacing = (int) $carouselSettings['image_spacing_custom_widescreen']['size'];
                }
                $slideSelector = isset($carouselSlideSelectors[$carouselIndex]) ? $carouselSlideSelectors[$carouselIndex]
                    : '.elementor-image-carousel-wrapper:not(.swiper-initialized) .swiper-slide';
                if (strpos($slideSelector, '.e-n-carousel') !== false) { $nestedCarouselUsed = true; }
                $elementSlideSelector = '.elementor-element-' . $elementId . ' ' . ltrim($slideSelector);
                // v7.21.343 — THE WRAPPER ALREADY HAS A GAP (measured on justmsp: Elementor's
                // n-carousel CSS puts column-gap 24px/48px on .swiper-wrapper pre-init; our
                // margin-right stacked on it -> pitch 350 vs native 326, the row ran 3 gaps
                // wide, the 4th card clipped in EVERY pre-init frame and snapped when
                // swiper's margins replaced the gap). n-carousel slides size against the
                // wrapper's own gap and carry NO margin; the legacy image-carousel wrapper
                // is flex only because WE make it so (no gap exists) and keeps margins.
                $wrapperHasGap = strpos($slideSelector, '.e-n-carousel') !== false;
                $slideWidthCss = function ($n, $sp = null) use ($slideSpacing, $wrapperHasGap) {
                    $sp = $sp === null ? $slideSpacing : (int) $sp;
                    if ($sp > 0 && $wrapperHasGap) {
                        return 'width:calc((100% - ' . (($n - 1) * $sp) . 'px)/' . $n . ')';
                    }
                    return $sp > 0
                        ? 'width:calc((100% - ' . (($n - 1) * $sp) . 'px)/' . $n . ');margin-right:' . $sp . 'px'
                        : 'width:calc(100%/' . $n . ')';
                };
                $preinitCss .= $elementSlideSelector . '{' . $slideWidthCss($mobileSlides) . '}'
                    . '@media(min-width:768px){' . $elementSlideSelector . '{' . $slideWidthCss($tabletSlides) . '}}'
                    . '@media(min-width:1025px){' . $elementSlideSelector . '{' . $slideWidthCss($laptopSlides > 0 ? $laptopSlides : $desktopSlides) . '}}'
                    . ($laptopSlides > 0 ? '@media(min-width:1367px){' . $elementSlideSelector . '{' . $slideWidthCss($desktopSlides) . '}}' : '')
                    . ($widescreenSpacing >= 0 && $widescreenSpacing !== $slideSpacing ? '@media(min-width:2400px){' . $elementSlideSelector . '{' . $slideWidthCss($desktopSlides, $widescreenSpacing) . '}}' : '');
                // v7.21.334 — the width calc subtracts (n-1) gaps but every slide carried
                // margin-right, so the row ran one gap wide and the LAST card clipped
                // (justmsp: 4th badge cut). Zero the last gap; total = exactly 100%.
                if (!$wrapperHasGap && ($slideSpacing > 0 || $widescreenSpacing > 0)) {
                    $preinitCss .= $elementSlideSelector . ':last-child{margin-right:0}';
                }
            }
            if ($preinitCss !== '') {
                $preinitCss = '.elementor-image-carousel-wrapper:not(.swiper-initialized){overflow:hidden}'
                    . '.elementor-image-carousel-wrapper:not(.swiper-initialized) .swiper-wrapper{display:flex;flex-wrap:nowrap;align-items:center}'
                    . ($nestedCarouselUsed
                        // v7.21.334 — n-carousel slides are boxed cards: initialized swiper
                        // stretches them equal-height; center made short cards float
                        // (justmsp Google badge shorter than its siblings pre-init).
                        ? '.e-n-carousel:not(.swiper-initialized){overflow:hidden}'
                          . '.e-n-carousel:not(.swiper-initialized) .swiper-wrapper{display:flex;flex-wrap:nowrap;align-items:stretch}'
                          . '.e-n-carousel:not(.swiper-initialized) .swiper-slide{height:auto}'
                        : '') . $preinitCss;
                $firstFrameGuards['swiper'] = count(array_slice($carouselTags[0], 0, 12));
                $criticalCss .= "\r\n" . '<style id="wpc-swiper-preinit-guard">' . $preinitCss . '</style>';
            }
        }



        if (apply_filters('wpc_cky_reveal_neutralize', true) && (stripos($html, 'cky-') !== false || stripos($html, 'cookieyes') !== false)) {


            $criticalCss .= "\r\n" . '<style id="wpc-cky-reveal">.cky-consent-container,.cky-consent-bar{animation:none!important;transform:none!important;transition:opacity .18s ease!important;}</style>';
        }


        $wpc_fa_s = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
        $wpc_fa_on = is_array($wpc_fa_s) && !empty($wpc_fa_s['fontawesome-optimize']) && $wpc_fa_s['fontawesome-optimize'] == '1';
        if (apply_filters('wpc_fa_optimize', $wpc_fa_on) && (stripos($html, 'font-awesome') !== false || stripos($html, 'fontawesome') !== false)) {
            $firstFrameGuards['fa_reserve'] = 1;
            $criticalCss .= "\r\n" . '<style id="wpc-fa-reserve">.fa,.fas,.far,.fab,.fal,.fad,.fak,.fass,.fasr,i[class^="fa-"],i[class*=" fa-"]{display:inline-block;min-width:1em;font-style:normal}</style>';
        }


        if (apply_filters('wpc_lazy_thumb_blackflash_guard', true)
            && preg_match('/\.post-thumbnail\s+a\s*\{[^}]*background[^;}]*(?:#0{3,6}\b|\bblack\b)/i', $criticalCss)) {
            $firstFrameGuards['thumb_bgfix'] = 1;
            $criticalCss .= "\r\n" . '<style type="text/css" id="wpc-lazy-thumb-bgfix">.post-thumbnail a[href]{background:transparent}</style>';
        }

        // First-frame guards stand in for rules the crit did not carry or the delay holds (the
        // parked sheets, swiper, smartmenus, the icon kit): one line names every guard this page
        // got. Sampled: the page's markup decides them, so each render of it gets the same set.
        if ($firstFrameGuards !== [] && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('first-frame-guards', $firstFrameGuards, true);
        }
        // Every @font-face this method assembled — the crit's own faces, the ATF subsets, the
        // metric stand-ins, the inlined Google faces — belongs to the render's face owner, which
        // emits them in its own two blocks. The hand-off is here and not at each emitter because
        // the font post-processing above (the gstatic localizer, the ATF preload/inline ladder,
        // the coverage gates) reads those faces where they are written.
        $criticalCss = self::harvestFontFaces($criticalCss, 'crit', $fontFaces);
        $html = str_replace('<!--WPC_INSERT_CRITICAL-->', $criticalCss, $html);
        // §8(c) (v7.10.680) — act on the wire manifest's font-family drop[] entries: a family the
        // service measured as below the fold is asked for late. No-op until a gen carries a
        // font-family drop (needs the wire consumed by §2, on disk).
        self::demoteWireFontFamilies($fontFaces);
        // §8.1 (v7.10.681) — act on the wire's LCP-asset decision: inline an SVG hero (local-read,
        // ≤12KB) as a data: URI + drop its preload, per the service verdict. No-op until a gen
        // carries an lcp entry with verdict:inline-data-uri.
        $html = self::wpc_inline_wire_lcp($html, $imagePreloads);
        return $html;
    }


    public function wpc_arm_sentinel_tag($html)
    {
        try {


            // 22.8s and 11.5s on consecutive hits, measured). A 404 gets no sentinel, no kick,
            // no crit, no warms — it just renders.
            if (function_exists('is_404') && is_404()) {
                return $html;
            }
            if (!apply_filters('wpc_arm_sentinel', true)
                || !class_exists('wps_ic_url_key') || !function_exists('admin_url')
                || strpos($html, 'wpc-arm-sentinel') !== false) {
                return $html;
            }
            $wpc_sk = (new wps_ic_url_key())->setup('');
            if (empty($wpc_sk)) {
                return $html;
            }
            if (function_exists('wpc_pipeline_admission_ok') && !wpc_pipeline_admission_ok()) {
                return $html; // query-string renders never mint kick chains
            }


            // Every limit (the service hold, dedupe, dead key, the per-minute throttle) is the
            // kick admission's, and so are its receipts: render-kick when admitted, kick-refused
            // or kick-throttled when not. The tag and beacon below are emitted either way, so a
            // later visit asks again.
            if (function_exists('wpc_kick_once_per_request')
                && !(function_exists('wpc_is_low_value_page') && wpc_is_low_value_page())
                && apply_filters('wpc_render_kick', true)) {
                wpc_kick_once_per_request($wpc_sk, 'render');
            }
            $wpc_su  = admin_url('admin-ajax.php') . '?action=wpc_repull_kick&k=' . rawurlencode($wpc_sk);
            $wpc_tag = '<script id="wpc-arm-sentinel">(function(){var u=' . json_encode($wpc_su) . ',d=0,'
                . 'go=function(){if(d)return;d=1;try{if(!(navigator.sendBeacon&&navigator.sendBeacon(u)))'
                . 'fetch(u,{mode:"no-cors",keepalive:true})}catch(e){try{(new Image).src=u}catch(z){}}};'
                . '["pointerdown","keydown","touchstart","scroll","mousemove"].forEach(function(e){'
                . 'addEventListener(e,go,{once:true,passive:true,capture:true})});'
                . 'setTimeout(go,3000);setTimeout(function(){d=0;go()},95000);})();</script>';
            if (strpos($html, '</head>') !== false) {
                return preg_replace('/<\/head>/i', $wpc_tag . '</head>', $html, 1);
            }
            return wpc_inject_before_body_close($html, $wpc_tag);
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * One receipt when the landed crit names none of the sections this page opens with.
     *
     * Whether an artifact fits the page it is returned for is the crit service's decision: the
     * dispatch carries the page, and the service does not reuse an artifact that misses the
     * pushed sections. So nothing is withheld, marked or left unparked here; the line is what a
     * report to the crit team starts from. The plugin's own gate used to decide it a second
     * time, and its mark outlived the evidence: on hawkeye.design a render of /sitemap/ on the
     * homepage's key marked the homepage's crit, the service kept returning the same bytes, and
     * the homepage served that crit with every stylesheet blocking for six days (2026-09-24 to
     * 2026-09-30) although the crit named 12 of its 12 sections.
     */
    public function crit_coverage_receipt($html, $criticalCSSExists)
    {
        if (empty($criticalCSSExists['desktop_path']) || !function_exists('wpc_atf_section_ids')
            || !function_exists('wpc_artifact_covers_atf') || !function_exists('wpc_belt_receipt')) {
            return;
        }
        $sectionIds = wpc_atf_section_ids($html);
        if (count($sectionIds) < 2) {
            return;
        }
        $critCss = (string) @file_get_contents((string) $criticalCSSExists['desktop_path']);
        if ($critCss === '' || wpc_artifact_covers_atf($critCss, $sectionIds)) {
            return;
        }
        wpc_belt_receipt('crit-misses-page-sections', [
            'ids' => implode(',', array_slice($sectionIds, 0, 4)),
            'n'   => count($sectionIds),
            'dev' => (function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop',
        ]);
    }

    public function addCriticalCSS($html, wps_ic_font_face_set $fontFaces, wps_ic_image_preload_set $imagePreloads)
    {
        $output = '';

        $criticalCSS = new wps_criticalCss();
        $criticalCSSExists = $criticalCSS->criticalExists(true);


        if (!empty($criticalCSSExists) && empty($_GET['removeCritical'])) {


            if (!empty($criticalCSSExists['desktop']) && function_exists('wpc_kick_once_per_request')) {
                $staleCritDir = dirname($criticalCSSExists['desktop']);
                if (@is_file($staleCritDir . '/stale.txt')) {
                    $staleUrlKey = basename($staleCritDir);
                    if ($staleUrlKey !== '' && !get_transient('wpc_kick_fire_' . md5($staleUrlKey))) {
                        // render-kick-stale is written by wpc_repull_kick_now() when the kick is admitted.
                        wpc_kick_once_per_request($staleUrlKey, 'stale');
                    }
                }
            }


            if (!empty($criticalCSSExists['desktop'])) {
                $wpc_lcp_file = dirname($criticalCSSExists['desktop']) . '/lcp.json';


                if (!is_readable($wpc_lcp_file)) {
                    $wpc_heal_dir  = dirname($criticalCSSExists['desktop']) . '/';
                    $wpc_heal_uf   = $wpc_heal_dir . 'lcp_url.txt';
                    $wpc_heal_lock = 'wpc_lcp_heal_' . md5($wpc_heal_dir);
                    $wpc_crit_mt   = (int) @filemtime($criticalCSSExists['desktop']);
                    $wpc_crit_age  = $wpc_crit_mt ? (time() - $wpc_crit_mt) : 0;
                    if (is_readable($wpc_heal_uf)
                        && apply_filters('wpc_lcp_hint_healer', true)
                        && $wpc_crit_age >= 30                       // .lcp.json should have landed (~28s post-regen)
                        && !get_transient($wpc_heal_lock)) {
                        $wpc_heal_url  = trim((string) file_get_contents($wpc_heal_uf));
                        $wpc_heal_nkey = ($wpc_heal_url !== '') ? 'wpc_lcp_healn_' . md5($wpc_heal_url) : '';
                        if ($wpc_heal_nkey !== '' && (int) get_transient($wpc_heal_nkey) >= 15) {
                            @unlink($wpc_heal_uf);
                        } elseif ($wpc_heal_url !== '' && filter_var($wpc_heal_url, FILTER_VALIDATE_URL)
                                  && self::wpc_lcp_heal_budget_ok()) {
                            $healFetchArgs = [$wpc_heal_url, $wpc_lcp_file, $wpc_heal_dir, $wpc_heal_nkey, $wpc_heal_lock];
                            if (function_exists('wpc_render_guard_active') && wpc_render_guard_active() && function_exists('wpc_net_defer')) {
                                wpc_net_defer('lcpheal:' . md5($wpc_heal_url), function () use ($healFetchArgs) { wps_rewriteLogic::wpc_lcp_heal_fetch($healFetchArgs, 'deferred'); });
                            } else {
                                self::wpc_lcp_heal_fetch($healFetchArgs, 'inline');
                            }
                        }
                    }
                }
                $measuredLcpByDevice = null;
                // The page's own observation is on disk; what it says is read through its one
                // reader, never decoded here.
                if (is_readable($wpc_lcp_file)) {
                    // v7.10.383 — hint from the measured LCP element per device (stem + css_w),
                    // NOT a flat one-width hint: that dressed the mobile bucket with the desktop
                    // slot (sizes=510px on a phone = +47KiB rung).
                    $lcpHintByDevice = [];
                    foreach (['mobile', 'desktop'] as $lcpDevice) {
                        $lcpElement = wps_ic_atf_observation::lcpElement($lcpDevice);
                        if (!empty($lcpElement['stem']) && is_string($lcpElement['stem'])) {
                            $lcpHintByDevice[$lcpDevice] = [
                                'stem'  => (string) $lcpElement['stem'],
                                'width' => (int) ($lcpElement['css_w'] ?? 0),
                            ];
                        }
                    }
                    $measuredLcpByDevice = empty($lcpHintByDevice) ? null : $lcpHintByDevice;
                    // Registered even when empty: a measured page with no LCP image must not fall
                    // back to the wpc_lcp_hint option, which the lane reads when no filter answers.
                    add_filter('wpc_lcp_hint', function () use ($lcpHintByDevice) { return $lcpHintByDevice; }, 5);


                    // §14 resource hints (v3.74.1 LIVE): preconnect the MEASURED hero/font/3p
                    // hosts so the LCP connection is warm — the ceiling model credits this, so
                    // without it a site can't reach its own predicted ceiling. The owner answers
                    // ≤4 https origins, host-deduped; additive (worst case = one idle socket).
                    if (apply_filters('wpc_lcp_resource_hints', true)) {
                        $preconnectLinks = '';
                        foreach (wps_ic_atf_observation::preconnectOrigins() as $preconnectOrigin) {
                            $preconnectLinks .= '<link rel="preconnect" href="' . esc_url($preconnectOrigin) . '">';
                        }
                        if ($preconnectLinks !== '') {
                            $output .= "\r\n" . $preconnectLinks;
                        }
                        // §14 v1.2.4 lcp_preload: the MEASURED hero URL to preload — fires by
                        // URL, so a hero the service found but couldn't give a unique selector
                        // (sel:"") is still preloaded. url = net_url (post-CDN fetch URL), so
                        // the browser reuses this preload for the eager hero <img> already in
                        // the DOM rather than double-fetching. Device-scoped so an off-device
                        // hero never contends at High. Its presence stands the atf-fallback
                        // logo preload down (checked at wpc_lcp_has_hero_preload) — the logo was
                        // stealing the hero's priority. imagesrcset/imagesizes stay filterable
                        // for the img lane to supply a responsive-set match when the hero has one.
                        $heroHints = wps_ic_atf_observation::lcpPreloadHints();
                        if (!empty($heroHints)) {
                            $heroPreloadUrlsSeen = [];
                            foreach ($heroHints as $heroHint) {
                                if (count($heroPreloadUrlsSeen) >= 2) { break; }
                                if (!is_array($heroHint) || empty($heroHint['url']) || !is_string($heroHint['url'])) { continue; }
                                $heroUrl = trim($heroHint['url']);
                                if (!preg_match('#^https?://#i', $heroUrl) || !self::wpc_lcp_bg_url_allowed($heroUrl)) { continue; }
                                $heroUrlKey = strtolower($heroUrl);
                                if (isset($heroPreloadUrlsSeen[$heroUrlKey])) { continue; }
                                $hintDevice = in_array((string) ($heroHint['device'] ?? 'both'), ['mobile', 'desktop', 'both'], true)
                                    ? (string) ($heroHint['device'] ?? 'both') : 'both';
                                // url_is_authoritative (service 3.79.0): true = non-responsive <img> /
                                // CSS-bg → preload the url VERBATIM (query intact). false = responsive
                                // <img srcset> → the browser picks from the LIVE srcset at the real
                                // device, so a bare-href preload prefetches a candidate it never uses
                                // (busyprosai: 73KB wasted + the hero fetched twice). Only preload a
                                // responsive hero WITH a matching imagesrcset (supplied by the img lane
                                // via the filter); otherwise SKIP and let the <img>'s own srcset load
                                // it. Absent (pre-3.79 gens) = authoritative = prior verbatim behavior.
                                $heroUrlIsAuthoritative = array_key_exists('url_is_authoritative', $heroHint)
                                    ? (bool) $heroHint['url_is_authoritative'] : true;
                                // css_w (service §14) = the MEASURED rendered slot in CSS px. It is the
                                // authoritative sizes value; markup can only be read pre-bake here, which
                                // yielded WP's default 100vw instead of the real 328px. Absent when the LCP
                                // is text/eager — then fall back to the markup read.
                                // A device:"both" entry carries the MOBILE measurement, and "both" emits no
                                // media attr — applying a mobile slot to desktop mis-picks the rung and the
                                // hero downloads twice. Scope such an entry to mobile; desktop then loads
                                // from the <img>'s own srcset (no preload, but never a wasted one).
                                $heroCssWidth = (isset($heroHint['css_w']) && is_numeric($heroHint['css_w']) && (int) $heroHint['css_w'] > 0)
                                    ? (int) $heroHint['css_w'] : 0;
                                $heroDevice = ($heroCssWidth > 0 && $hintDevice === 'both') ? 'mobile' : $hintDevice;
                                // Service v3.104.0 supplies the rung LADDER on the hint, so the
                                // imagesrcset no longer has to be scraped out of markup that has not
                                // been rewritten yet — the exact reason the preload was lost on warm
                                // renders (5x lcp-preload-skip-responsive receipted on busy). Trust
                                // the ladder only when rungs_complete: a partial ladder would preload
                                // a candidate the <img> can't pick, which is the double-fetch this
                                // guard exists to prevent. Markup scrape stays as the fallback.
                                // Encoding is AUTHORITATIVE (v3.104.0 emitter contract), not inferred:
                                //   rungs: Array<{url:string, w?:number, x?:number}> — flat, absolute,
                                //     query preserved, capped 16, deduped, authored order. The descriptor
                                //     is w OR x (whichever the source used, never both) and is ABSENT for
                                //     a bare candidate.
                                //   rungs_complete: true only when rungs.length === srcset_n; absent on a
                                //     non-responsive element. A partial ladder still ships rungs with
                                //     rungs_complete:false — present and useful, but claiming no authority.
                                // srcset forbids MIXING w and x descriptors, and a bare candidate is an
                                // implicit 1x, so bare-alongside-descriptored is equally invalid: any mixed
                                // ladder is refused whole rather than emitted malformed.
                                $heroLadderSrcset = '';
                                if (!empty($heroHint['rungs_complete'])
                                    && !empty($heroHint['rungs']) && is_array($heroHint['rungs'])) {
                                    $ladderCandidates = [];
                                    $ladderDescriptorKind  = null;
                                    foreach ($heroHint['rungs'] as $ladderRung) {
                                        if (!is_array($ladderRung) || empty($ladderRung['url']) || !is_string($ladderRung['url'])
                                            || !preg_match('#^https?://#i', trim($ladderRung['url']))) {
                                            $ladderCandidates = []; break;
                                        }
                                        $rungUrl = trim($ladderRung['url']);
                                        if (isset($ladderRung['w']) && (int) $ladderRung['w'] > 0) {
                                            $rungDescriptorKind = 'w';
                                            $rungDescriptor = ' ' . (int) $ladderRung['w'] . 'w';
                                        } elseif (isset($ladderRung['x']) && (float) $ladderRung['x'] > 0) {
                                            $rungDescriptorKind = 'x';
                                            $rungDescriptor = ' ' . rtrim(rtrim(sprintf('%.3F', (float) $ladderRung['x']), '0'), '.') . 'x';
                                        } else {
                                            $rungDescriptorKind = 'bare';
                                            $rungDescriptor = '';
                                        }
                                        if ($ladderDescriptorKind !== null && $ladderDescriptorKind !== $rungDescriptorKind) {
                                            $ladderCandidates = []; break;
                                        }
                                        $ladderDescriptorKind    = $rungDescriptorKind;
                                        $ladderCandidates[] = $rungUrl . $rungDescriptor;
                                        if (count($ladderCandidates) >= 16) { break; }
                                    }
                                    // A bare-only ladder carries no candidate information a preload can
                                    // act on (one implicit 1x) — let the markup path handle it instead.
                                    if (!empty($ladderCandidates) && $ladderDescriptorKind !== 'bare') {
                                        $heroLadderSrcset = implode(', ', $ladderCandidates);
                                    }
                                }
                                $heroSrcset  = apply_filters('wpc_lcp_preload_imagesrcset',
                                    ($heroLadderSrcset !== '' ? $heroLadderSrcset : self::wpc_lcp_img_responsive($html, $heroUrl, 'srcset')),
                                    $heroUrl, $hintDevice);
                                // The 2000-char ceiling was sized for a SCRAPED srcset of short
                                // relative URLs. A service ladder is 14 ABSOLUTE zone URLs at ~150
                                // chars each = 2,109 on busy — it failed by 109 characters, silently,
                                // and because url_is_authoritative was true the fallback emitted a
                                // BARE-HREF preload on a responsive image: the exact double-fetch this
                                // path exists to prevent. ~2KB of attribute in a 320KB document is
                                // 0.65% to avoid a duplicate image fetch.
                                $srcsetMaxLength = (int) apply_filters('wpc_lcp_preload_imagesrcset_max', 8192);
                                $heroSrcsetUsable = is_string($heroSrcset) && $heroSrcset !== ''
                                    && strlen($heroSrcset) <= max(2000, $srcsetMaxLength);
                                if (is_string($heroSrcset) && strlen($heroSrcset) > max(2000, $srcsetMaxLength)
                                    && function_exists('wpc_cache_first_log')) {
                                    wpc_cache_first_log('lcp-preload-srcset-oversize', '', '', [
                                        'dev' => $hintDevice, 'len' => strlen($heroSrcset), 'cap' => max(2000, $srcsetMaxLength),
                                    ]);
                                }
                                if ($heroLadderSrcset !== '' && function_exists('wpc_cache_first_log')) {
                                    wpc_cache_first_log('lcp-preload-ladder', '', '', ['dev' => $hintDevice, 'n' => count(explode(',', $heroLadderSrcset)), 'len' => strlen($heroLadderSrcset)]);
                                }
                                // sizes_attr is the service's measured sizes for THIS device — it beats
                                // both the css_w single value and any markup read.
                                $heroSizesFromService = (!empty($heroHint['sizes_attr']) && is_string($heroHint['sizes_attr'])
                                                && strlen($heroHint['sizes_attr']) <= 400)
                                    ? trim($heroHint['sizes_attr']) : '';
                                if (!$heroUrlIsAuthoritative && !$heroSrcsetUsable) {
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-preload-skip-responsive', '', '', ['dev' => $hintDevice]);
                                    }
                                    continue; // no bare-href preload on a responsive hero — it duplicates
                                }
                                $heroPreloadUrlsSeen[$heroUrlKey] = 1;
                                $heroSrcsetAttr = '';
                                $heroSizesAttr = '';
                                if ($heroSrcsetUsable) {
                                    $heroSrcsetAttr = esc_attr($heroSrcset);
                                    $heroSizes = apply_filters('wpc_lcp_preload_imagesizes',
                                        ($heroSizesFromService !== '' ? $heroSizesFromService
                                            : ($heroCssWidth > 0 ? $heroCssWidth . 'px'
                                               : self::wpc_lcp_img_responsive($html, $heroUrl, 'sizes'))),
                                        $heroUrl, $hintDevice);
                                    if (is_string($heroSizes) && $heroSizes !== '' && strlen($heroSizes) <= 400) {
                                        $heroSizesAttr = esc_attr($heroSizes);
                                    }
                                }
                                // Preserve any query (?src=png) on the href — esc_url keeps it; the byte
                                // form must byte-match the <img> the browser loads or it double-fetches.
                                // A hint never names a video file: the owner drops one
                                // (wps_ic_atf_observation::lcpPreloadHints).
                                // v7.21.09 — never preload a variant that does not exist. The measured
                                // lcp.json can name an .avif the generator never produced (only the
                                // .webp exists on disk), and that preload 404s on EVERY view — the
                                // page's highest-priority fetch slot spent on nothing. AVIF variants
                                // live on origin disk, so uploads-pathed URLs are disk-checkable;
                                // anything else (external, moved uploads dir) emits as before.
                                $heroUrlPath = (string) parse_url((string) $heroUrl, PHP_URL_PATH);
                                $heroAvifMissing = false;
                                if (preg_match('/\.avif$/i', $heroUrlPath)
                                    && ($uploadsPathOffset = strpos($heroUrlPath, '/wp-content/uploads/')) !== false
                                    && defined('ABSPATH')
                                    && apply_filters('wpc_lcp_preload_exists_gate', true)) {
                                    $heroAvifMissing = !@file_exists(rtrim(ABSPATH, '/') . rawurldecode(substr($heroUrlPath, $uploadsPathOffset)));
                                }
                                if ($heroAvifMissing) {
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-preload-skip-missing-avif', '', '', ['dev' => $hintDevice]);
                                    }
                                } elseif (!self::wpc_is_lcp_preload_in_doc($html, (string) $heroUrl)) {
                                    // A preload names a file this document fetches. The observation
                                    // can come from another page: a template-cache hit hands a
                                    // sibling's lcp.json to this URL (ganoderma: tongkat-ali preloaded
                                    // at high, gano2n1 is the LCP, resource load delay 8.7 s), the home
                                    // page's answers for a page with none, or the page changed since it
                                    // was measured. Since crit-push 3.198.293 the document says which
                                    // page it measured (`page.url_key`), which the receipt carries; the
                                    // test itself stays, because a sibling that shares this page's hero
                                    // file is still right to preload it.
                                    $imagePreloads->declined('not-in-doc');
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-preload-skip-stale-hero', '', '', ['dev' => $hintDevice,
                                            'observed' => substr(wps_ic_atf_observation::observedPageKey(), 0, 120)]);
                                    }
                                } else {
                                    // One picture, one preload per device is the set's rule, not
                                    // this writer's: a second hint or the background lane's rendition
                                    // of the same hero is settled there.
                                    $imagePreloads->add('wpc-lcp-hero-preload', esc_url($heroUrl), $heroDevice, 'hero-hint',
                                        wps_ic_image_preload_set::RANK_HERO_HINT, [
                                            'imagesrcset' => $heroSrcsetAttr,
                                            'imagesizes'  => $heroSizesAttr,
                                        ]);
                                }
                            }
                        }
                    }

                }


                if (self::wpc_combined_crit_on()) {
                    $output .= self::wpc_cls_reserve_style(dirname($criticalCSSExists['desktop']), true, $html);
                    $output .= str_replace('id="wpc-cls-reserve"', 'id="wpc-cls-reserve-d"',
                        self::wpc_cls_reserve_style(dirname($criticalCSSExists['desktop']), false, $html));
                } else {
                    $output .= self::wpc_cls_reserve_style(dirname($criticalCSSExists['desktop']), $this->isMobile(), $html);
                }
            }
            if (file_exists($criticalCSSExists['desktop']) && file_exists($criticalCSSExists['mobile'])) {
                $criticalCSSContent_Desktop = file_get_contents($criticalCSSExists['desktop']);
                $criticalCSSContent_Mobile = file_get_contents($criticalCSSExists['mobile']);


                // The ATF subsets: letter-coverage faces carrying their own bytes, which is what
                // lets the page paint in its real typeface before any font is fetched. They go
                // to the render's face owner like every other face this method assembles, so
                // they ride the owner's first-paint block and no block of their own.
                //
                // Never emit them as a <style id="wpc-font-subsets"> shell for a later harvest to
                // empty out: a v2 artifact opens with a /*wpc-subsets-v2*/ marker comment, and a
                // shell with a comment in it is not empty, so the harvest leaves an inert
                // <style id="wpc-font-subsets" data-wpc-v2="1">/*wpc-subsets-v2*/</style> beside
                // the real block, which the loader then treats as a live carrier.
                // (One registration per request, ever: two subset registrations from two crit
                // reads would be two copies of the same faces.)
                static $atfSubsetsRegistered = false;
                if (!$atfSubsetsRegistered
                    && apply_filters('wpc_atf_subset_inline', true) && is_string($criticalCSSContent_Desktop)) {
                    $wpc_sub_f = dirname($criticalCSSExists['desktop']) . '/font-subsets.css';
                    $wpc_sub_c = @is_readable($wpc_sub_f) ? (string) @file_get_contents($wpc_sub_f) : '';


                    if (apply_filters('wpc_atf_icon_subset_inline', true)) {
                        $wpc_isub_f = dirname($criticalCSSExists['desktop']) . '/icon-subsets.css';
                        if (@is_readable($wpc_isub_f)) {
                            $wpc_sub_c .= (string) @file_get_contents($wpc_isub_f);
                        }
                    }
                    if ($wpc_sub_c !== '') {


                        if (!self::wpc_fonts_lane_should_yield(['mobile', 'desktop'])) {


                            $wpc_sub_c = (string) self::wpc_strip_family_faces($wpc_sub_c, self::wpc_unbacked_font_families($html, $output), 'atf_subsets');
                            if (stripos($wpc_sub_c, '@font-face') !== false) {
                                $fontFaces->add($wpc_sub_c, 'crit');
                            }
                            $atfSubsetsRegistered = true;
                        }
                    }
                }


                if ($criticalCSSExists['desktop']) {
                    $wpc_trim_dir = dirname($criticalCSSExists['desktop']) . '/';
                    $criticalCSSContent_Desktop = self::wpc_trim_crit_fontface($criticalCSSContent_Desktop, $wpc_trim_dir);
                    $criticalCSSContent_Mobile  = self::wpc_trim_crit_fontface($criticalCSSContent_Mobile, $wpc_trim_dir);
                }


                if (is_string($criticalCSSContent_Desktop) || is_string($criticalCSSContent_Mobile)) {
                    $presetVarHaystack = $html . ' ' . (string) $criticalCSSContent_Desktop . ' ' . (string) $criticalCSSContent_Mobile;
                    $criticalCSSContent_Desktop = self::wpc_trim_preset_vars($criticalCSSContent_Desktop, $presetVarHaystack);
                    $criticalCSSContent_Mobile  = self::wpc_trim_preset_vars($criticalCSSContent_Mobile, $presetVarHaystack);
                    unset($presetVarHaystack);
                }

                if (str_contains($criticalCSSContent_Desktop, '<body>') || str_contains($criticalCSSContent_Mobile, '<body>')) {
                    // Do Nothing, it's html
                } else {

                    // Strip content before "/* Preload Fonts */" marker if present (legacy separator)
                    $getCSSContent = function ($cssContent) {
                        $commentPos = strpos($cssContent, '/* Preload Fonts */');
                        return $commentPos !== false ? substr($cssContent, $commentPos + strlen('/* Preload Fonts */')) : $cssContent;
                    };

                    $criticalCSSContent_Desktop = self::wpc_prune_duplicate_css_rules(self::wpc_css_requote_urls($getCSSContent($criticalCSSContent_Desktop)));
                    $criticalCSSContent_Mobile = self::wpc_prune_duplicate_css_rules(self::wpc_css_requote_urls($getCSSContent($criticalCSSContent_Mobile)));

                    // v7.10.718 - CDN off: the artifacts were minted in whatever mode was live
                    // at generation and keep zone URLs baked in their bytes. Translate at
                    // consumption (transform unwrap + existence-guarded host swap) instead of
                    // forcing a regeneration.
                    if ((empty(self::$cdnEnabled) || self::$cdnEnabled != '1') && function_exists('wpc_unzone_css')) {
                        $criticalCSSContent_Desktop = wpc_unzone_css($criticalCSSContent_Desktop);
                        $criticalCSSContent_Mobile  = wpc_unzone_css($criticalCSSContent_Mobile);
                    }


                    // DEVICE (it was already fully keyed on $wpc_dev_key internally — blob refs,
                    // DPR, authority media guards). Single-device mode = one iteration, unchanged.
                    $critDevices = self::wpc_combined_crit_on()
                        ? ['mobile', 'desktop']
                        : [$this->isMobile() ? 'mobile' : 'desktop'];
                    foreach ($critDevices as $wpc_dev_key) {
                    // A combined crit serves both devices from one copy, so each device's
                    // background is its own candidate (the desktop one keeps the -d id); a
                    // single-device crit's candidate is simply this render's.
                    $bgPreloadDevice = self::wpc_combined_crit_on() ? $wpc_dev_key : 'both';
                    $bgPreloadIdSuffix = (self::wpc_combined_crit_on() && $wpc_dev_key === 'desktop') ? '-d' : '';
                    $wpc_el = wps_ic_atf_observation::lcpElement($wpc_dev_key);
                    if (empty($wpc_el)) {
                        $wpc_el = null;
                    }
                    $wpc_pre_url = '';


                    if ($wpc_el && !empty($wpc_el['net_url']) && !empty($wpc_el['url'])
                        && $wpc_el['net_url'] !== $wpc_el['url'] && function_exists('wpc_cache_first_log')) {
                        wpc_cache_first_log('lcp-net-divergence', $wpc_dev_key, substr((string) $wpc_el['url'], 0, 200),
                            ['net' => substr((string) $wpc_el['net_url'], 0, 200)]);
                    }


                    $wpc_lcp_url = (is_array($wpc_el) && !empty($wpc_el['net_url']) && is_string($wpc_el['net_url']))
                        ? $wpc_el['net_url']
                        : ((is_array($wpc_el) && !empty($wpc_el['url']) && is_string($wpc_el['url'])) ? $wpc_el['url'] : '');
                    if ($wpc_el && isset($wpc_el['type']) && $wpc_el['type'] === 'bg'
                        && $wpc_lcp_url !== ''
                        && self::wpc_lcp_bg_url_allowed($wpc_lcp_url)
                        && apply_filters('wpc_lcp_bg_responder', true)) {
                        $wpc_dpr = wps_ic_atf_observation::measuredDpr($wpc_dev_key);
                        if ($wpc_dpr <= 0) { $wpc_dpr = ($wpc_dev_key === 'mobile') ? 2 : 1; }
                        $wpc_need_w = (int) ceil((float) (isset($wpc_el['css_w']) ? $wpc_el['css_w'] : 0) * $wpc_dpr);
                        $wpc_need_h = (int) ceil((float) (isset($wpc_el['css_h']) ? $wpc_el['css_h'] : 0) * $wpc_dpr);
                        // The URL as it actually appears in THIS device's crit blob (zone or origin
                        // form) — the swap and the preload must byte-match the CSS or it double-fetches.
                        if ($wpc_dev_key === 'mobile') {
                            $wpc_blob =& $criticalCSSContent_Mobile;
                        } else {
                            $wpc_blob =& $criticalCSSContent_Desktop;
                        }
                        $wpc_orig_file = basename((string) parse_url($wpc_lcp_url, PHP_URL_PATH));
                        $wpc_pre_url  = '';
                        $wpc_auth_sel = '';
                        $wpc_auth_ok  = false;
                        if ($wpc_orig_file !== ''
                            && preg_match('#url\(\s*["\']?([^"\')\s]*/' . preg_quote($wpc_orig_file, '#') . ')["\']?\s*\)#i', (string) $wpc_blob, $wpc_bm)) {
                            $wpc_css_url = $wpc_bm[1];

                            // The FULL stylesheet can declare this same image as a TRANSFORM-chain


                            if (self::wpc_lcp_repair_cio_transform($wpc_orig_file) > 0
                                && apply_filters('wpc_lcp_repair_bump', false)) {
                                try {
                                    $wpc_ro = get_option(WPS_IC_OPTIONS);
                                    if (is_array($wpc_ro)) {
                                        $wpc_rh = substr(md5(microtime(true)), 0, 6);
                                        $wpc_ro['css_hash'] = $wpc_rh;
                                        $wpc_ro['js_hash']  = strrev($wpc_rh);
                                        update_option(WPS_IC_OPTIONS, $wpc_ro);
                                    }
                                    if (class_exists('wps_ic_cache') && method_exists('wps_ic_cache', 'removeHtmlCacheFiles')) {
                                        wps_ic_cache::removeHtmlCacheFiles('all');
                                    }
                                } catch (\Throwable $e) {
                                }
                            }


                            $wpc_auth_sel = (isset($wpc_el['sel']) && is_string($wpc_el['sel'])) ? trim($wpc_el['sel']) : '';


                            // The owner hands out a selector only with the service's proof that it
                            // addresses one element (sel_unique: true, wps_ic_atf_observation::
                            // lcpElement); a pin on a shared selector paints every element that
                            // carries it (wpcompress /compare: the hero fill on every top section).
                            $wpc_auth_ok  = ($wpc_auth_sel !== '' && strlen($wpc_auth_sel) <= 240
                                && preg_match('/^[A-Za-z0-9 _\-#.\[\]="\':,>+~()]+$/', $wpc_auth_sel)
                                && apply_filters('wpc_lcp_bg_authority', true));
                            $wpc_painted = self::wpc_lcp_painted_form($wpc_orig_file, $wpc_css_url);
                            $wpc_sib     = self::wpc_lcp_sized_sibling($wpc_css_url, $wpc_need_w, $wpc_need_h);


                            // v7.10.718 - the string-synthesized rung has no disk proof; only the
                            // zone mints it on the fly. With the CDN lane off it would 404 - the
                            // disk-scanned sibling / painted / css_url paths (all existence-backed)
                            // remain.
                            if ($wpc_auth_ok && $wpc_sib === '' && $wpc_need_w >= 64 && $wpc_need_h >= 64
                                && $wpc_need_w <= 2560 && $wpc_need_h <= 2560
                                && !empty(self::$cdnEnabled) && self::$cdnEnabled == '1'
                                && apply_filters('wpc_lcp_rung_synth', true)
                                && preg_match('#^(https?://[^\s"\')]+)\.(webp|avif|jpe?g|png)$#i', $wpc_css_url, $wpc_rs_m)
                                && !preg_match('#-\d+x\d+$#', $wpc_rs_m[1])) {
                                $wpc_sib = $wpc_rs_m[1] . '-' . (int) $wpc_need_w . 'x' . (int) $wpc_need_h . '.' . $wpc_rs_m[2];
                            }
                            // v7.20.14 — service refutation accepted: WE are the 768 writer. The
                            // artifact arrives clean; this lane rewrote the blob's full URL to a
                            // sized sibling whose width came from a mobile-flavored record on the
                            // desktop leg (412css x 1.75dpr ~ 721 -> nearest disk rung 768). The
                            // .13 floor guarded only the AUTHORITY — the blob rewrite and the
                            // preload kept shipping the rung ("snapping then stretching": crit
                            // paints 768, theme sheet stretches to full). Floor the CANDIDATE, so
                            // every consumer inherits it: an undersized desktop rung neither
                            // rewrites the blob, nor preloads, nor pins. css_url (the full form)
                            // remains the fallthrough — preloading the full hero on desktop is
                            // correct, it IS the LCP.
                            if ($wpc_dev_key !== 'mobile'
                                && !apply_filters('wpc_lcp_bg_small_desktop_pin', false)) {
                                // Width from either URL shape: -WxH natural name, or the zone
                                // transform's /w:N/ segment. w:1 is the ORIGINAL-size flag, not a
                                // width — w<=1 is full-size and always passes the floor.
                                $rungWidthOf = function ($u) {
                                    if (preg_match('/-(\d+)x\d+\.(?:webp|avif|jpe?g|png)(?:[?#]|$)/i', (string) $u, $m)) { return (int) $m[1]; }
                                    if (preg_match('/\/w:(\d+)\//i', (string) $u, $m)) { return (int) $m[1]; }
                                    return 0;
                                };
                                $siblingRungWidth = $rungWidthOf($wpc_sib);
                                if ($wpc_sib !== '' && $siblingRungWidth > 1 && $siblingRungWidth < 1000) {
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-candidate-undersized-drop', $wpc_dev_key, substr((string) $wpc_sib, -80), ['w' => $siblingRungWidth, 'form' => 'sibling']);
                                    }
                                    $wpc_sib = '';
                                }
                                $paintedRungWidth = $rungWidthOf($wpc_painted);
                                if ($wpc_painted !== '' && $paintedRungWidth > 1 && $paintedRungWidth < 1000) {
                                    // The painted form IS what the blob carries: dropping it alone
                                    // would leave the rung in the CSS while the preload reverts to
                                    // the full URL (byte-mismatch = double fetch). UN-RUNG the blob
                                    // to the full css_url form, then fall through to it.
                                    $wpc_blob = str_replace($wpc_painted, $wpc_css_url, (string) $wpc_blob);
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-candidate-undersized-drop', $wpc_dev_key, substr((string) $wpc_painted, -80), ['w' => $paintedRungWidth, 'form' => 'painted-unrung']);
                                    }
                                    $wpc_painted = '';
                                }
                            }
                            if ($wpc_auth_ok && $wpc_sib !== '' && $wpc_sib !== $wpc_css_url) {
                                $wpc_blob    = str_replace($wpc_css_url, $wpc_sib, (string) $wpc_blob);
                                $wpc_pre_url = $wpc_sib;
                            } elseif ($wpc_painted !== '' && $wpc_painted !== $wpc_css_url) {
                                $wpc_blob    = str_replace($wpc_css_url, $wpc_painted, (string) $wpc_blob);
                                $wpc_pre_url = $wpc_painted;
                            } elseif ($wpc_sib !== '' && $wpc_sib !== $wpc_css_url) {
                                $wpc_blob    = str_replace($wpc_css_url, $wpc_sib, (string) $wpc_blob);
                                $wpc_pre_url = $wpc_sib;
                            } else {
                                $wpc_pre_url = $wpc_css_url;
                            }
                        }
                        unset($wpc_blob);
                        if ($wpc_pre_url !== '') {
                            // This lane's rendition is the one the authority pin paints, so it
                            // outranks every other preload of the same picture in the set.
                            $imagePreloads->add('wpc-lcp-bg-preload' . $bgPreloadIdSuffix, esc_url($wpc_pre_url), $bgPreloadDevice,
                                'lcp-bg', wps_ic_image_preload_set::RANK_LCP_BACKGROUND,
                                ['kind' => wps_ic_image_preload_set::KIND_BACKGROUND]);


                            // v7.20.13 — AN UNDERSIZED RUNG MUST NEVER BE PINNED IMPORTANT ON THE
                            // DESKTOP LEG. min-width:768px means the viewport is AT LEAST 768 —
                            // a rung narrower than ~1000px cannot cover it, and !important means
                            // the theme's full-size rule never reclaims the box (vincire: crit
                            // carried the 768x388 painted form; the pin held a 768px bitmap
                            // centered in a 1256px row, grey bands forever). Without the pin the
                            // crit's transient small paint self-heals at sheet arrival.
                            $authorityPinWidthOk = true;
                            if ($wpc_dev_key !== 'mobile'
                                && preg_match('/-(\d+)x\d+\.(?:webp|avif|jpe?g|png)(?:[?#]|$)/i', (string) $wpc_pre_url, $preloadRungMatch)
                                && (int) $preloadRungMatch[1] < 1000
                                && !apply_filters('wpc_lcp_bg_small_desktop_pin', false)) {
                                $authorityPinWidthOk = false;
                                if (function_exists('wpc_cache_first_log')) {
                                    wpc_cache_first_log('lcp-pin-undersized-skip', '', '', ['w' => (int) $preloadRungMatch[1], 'url' => substr((string) $wpc_pre_url, -80)]);
                                }
                            }
                            if ($wpc_auth_ok && $authorityPinWidthOk) {
                                $wpc_auth_media = ($wpc_dev_key === 'mobile') ? '(max-width: 767.98px)' : '(min-width: 768px)';
                                $authorityPinDeclaration = self::wpc_bg_pin_declaration($wpc_auth_sel, $wpc_pre_url, $output . $html);
                                if ($authorityPinDeclaration !== '') {
                                    $output .= '<style id="wpc-lcp-bg-authority' . $bgPreloadIdSuffix . '">@media ' . $wpc_auth_media . '{' . $wpc_auth_sel . '{' . $authorityPinDeclaration . '}}</style>';
                                }
                            }
                        }
                    }


                    // 'text' no longer suppresses the fallback: PSI flip-flops between the
                    // text and the hero overlay as LCP (perkzilla), and the ATF bg gates
                    // perceived paint either way. Only a real img LCP (own preload) skips.
                    $wpc_census_nonbg = (is_array($wpc_el) && isset($wpc_el['type'])
                        && in_array($wpc_el['type'], ['img'], true));
                    if ($wpc_pre_url === '' && !$wpc_census_nonbg
                        && apply_filters('wpc_lcp_bg_responder', true)) {
                        $wpc_ad_blob = ($wpc_dev_key === 'mobile') ? $criticalCSSContent_Mobile : $criticalCSSContent_Desktop;
                        $wpc_ad = self::wpc_lcp_autoderive_bg($html, (string) $wpc_ad_blob);
                        if (is_array($wpc_ad) && !empty($wpc_ad['url']) && is_string($wpc_ad['url'])
                            && self::wpc_lcp_bg_url_allowed($wpc_ad['url'])) {
                            $derivedMobileRungUrl = ($wpc_dev_key === 'mobile') ? self::wpc_lcp_bg_rung_url($wpc_ad['url']) : '';
                            $derivedBackgroundHref = ($derivedMobileRungUrl !== '') ? $derivedMobileRungUrl : $wpc_ad['url'];
                            $imagePreloads->add('wpc-lcp-bg-preload' . $bgPreloadIdSuffix, esc_url($derivedBackgroundHref), $bgPreloadDevice,
                                'lcp-bg-derived', wps_ic_image_preload_set::RANK_LCP_BACKGROUND,
                                ['kind' => wps_ic_image_preload_set::KIND_BACKGROUND]);
                            $wpc_ad_sel = isset($wpc_ad['sel']) ? trim((string) $wpc_ad['sel']) : '';
                            if ($derivedMobileRungUrl !== '' && $wpc_ad_sel !== '' && strlen($wpc_ad_sel) <= 240
                                && preg_match('/^[A-Za-z0-9 _\-#.\[\]="\':,>+~()]+$/', $wpc_ad_sel)
                                && preg_match('/[#][A-Za-z_][\w-]*|\.elementor-element-[0-9a-f]{6,8}(?![\w-])|\[data-id=/i', $wpc_ad_sel)) {
                                $rungPinDeclaration = self::wpc_bg_pin_declaration($wpc_ad_sel, $derivedMobileRungUrl, $output . $html);
                                if ($rungPinDeclaration !== '') {
                                    $output .= '<style id="wpc-lcp-bg-rung">@media (max-width: 767.98px){' . $wpc_ad_sel . '{' . $rungPinDeclaration . '}}</style>';
                                    if (function_exists('wpc_cache_first_log')) {
                                        wpc_cache_first_log('lcp-bg-rung', 'mobile', substr($derivedMobileRungUrl, 0, 180), ['sel' => substr($wpc_ad_sel, 0, 120)]);
                                    }
                                }
                            }
                            if ($wpc_ad_sel !== '' && strlen($wpc_ad_sel) <= 240
                                && preg_match('/^[A-Za-z0-9 _\-#.\[\]="\':,>+~()]+$/', $wpc_ad_sel)


                                && preg_match('/#[A-Za-z_][\w-]*|\.elementor-element-[0-9a-f]{6,8}(?![\w-])|\[data-id=/i', $wpc_ad_sel)
                                && apply_filters('wpc_lcp_autoderive_authority', false)) {
                                $wpc_ad_media = ($wpc_dev_key === 'mobile') ? '(max-width: 767.98px)' : '(min-width: 768px)';
                                $derivedAuthorityPinDeclaration = self::wpc_bg_pin_declaration($wpc_ad_sel, $wpc_ad['url'], $output . $html);
                                if ($derivedAuthorityPinDeclaration !== '') {
                                    $output .= '<style id="wpc-lcp-bg-authority' . $bgPreloadIdSuffix . '">@media ' . $wpc_ad_media . '{' . $wpc_ad_sel . '{' . $derivedAuthorityPinDeclaration . '}}</style>';
                                }
                            }
                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('lcp-autoderive', $wpc_dev_key, substr((string) $wpc_ad['url'], 0, 180), []);
                            }
                        }
                    }
                    }


                    if (apply_filters('wpc_bgvideo_contain', false)
                        && strpos($html, 'elementor-background-video') !== false) {
                        $output .= "\r\n" . '<style id="wpc-bgvideo-contain">'
                            . '.elementor-section:has(>.elementor-background-video-container),'
                            . '.elementor-top-section:has(>.elementor-background-video-container),'
                            . '.e-con:has(>.elementor-background-video-container){position:relative}'
                            . '.elementor-background-video-container{position:absolute!important;inset:0;width:100%;height:100%;overflow:hidden;z-index:0;pointer-events:none;contain:strict}'
                            . '.elementor-background-video-container video{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%)}</style>';
                    }


                    // The used-CSS lane carries the rules of the sheets this render parks, so it
                    // ships only when the render parks. On a refused render every sheet is live
                    // and carries all of these rules already: greenvalleytint's forfeit page
                    // shipped the 68.7 KB union link at body end on top of its 43 blocking sheets
                    // (59 KB unused per Lighthouse, 2026-09-24), because only the split rest
                    // variant asked the verdict. One question for every used-CSS output — union,
                    // split rest, header slice, face preloads — and lazyCSS asks the same one
                    // before the droplist removes anything these links would have carried.
                    if (!empty(self::$settings['used-css']) && self::$settings['used-css'] == '1'
                        && self::$parkAllowed
                        && function_exists('wpc_used_css_path') && defined('WPS_IC_CRITICAL_URL')) {
                        $critDir = dirname($criticalCSSExists['desktop']) . '/';


                        // v7.10.550 — used_tpl.txt has no producer on either side (service confirmed:
                        // never written, never read, no schema, no upload). tpl.txt is the real key.
                        $templateKey = @is_file($critDir . 'tpl.txt')
                            ? trim((string) @file_get_contents($critDir . 'tpl.txt')) : '';


                        if ($templateKey === '' && function_exists('wpc_compute_tpl_key')) {
                            $computedTemplateKey = (string) wpc_compute_tpl_key();
                            if ($computedTemplateKey !== '') {
                                wpc_fs_put($critDir . 'tpl.txt', $computedTemplateKey);
                                $templateKey = $computedTemplateKey;
                            }
                        }


                        // v7.10.605 — provenance, not just filename: used_tpl.txt names the template
                        // the service GENERATED this content from. Mismatched, the bytes describe
                        // another template and strip rules the live one needs. Skip used-css for
                        // this render and serve the full stylesheet instead.
                        if ($templateKey !== '' && function_exists('wpc_used_css_provenance_ok')
                            && !wpc_used_css_provenance_ok($critDir, $templateKey)) {
                            $templateKey = '';
                        }

                        $renderDevice = $this->isMobile() ? 'mobile' : 'desktop';


                        $usedCssMobilePath = '';
                        $usedCssDesktopPath = '';
                        if (self::wpc_combined_crit_on() && $templateKey !== '') {
                            $usedCssMobilePath = wpc_used_css_path($templateKey, 'mobile');
                            if ($usedCssMobilePath === '' || !(@filesize($usedCssMobilePath) > 64)) { $usedCssMobilePath = wpc_used_css_path($templateKey); }
                            $usedCssDesktopPath = wpc_used_css_path($templateKey, 'desktop');
                            if ($usedCssDesktopPath === '' || !(@filesize($usedCssDesktopPath) > 64)) { $usedCssDesktopPath = wpc_used_css_path($templateKey); }
                        }
                        $usedCssPath  = $templateKey !== '' ? wpc_used_css_path($templateKey, $renderDevice) : '';
                        if ($usedCssPath === '' || !(@filesize($usedCssPath) > 64)) {
                            $usedCssPath = $templateKey !== '' ? wpc_used_css_path($templateKey) : '';
                        }
                        if ($usedCssPath !== '' && @filesize($usedCssPath) > 64) {

                            // v7.21.336 — FACES THAT RIDE USED-CSS PRELOAD TOO (rosario: 3 hero
                            // woff2 chained behind the used-css fetch -> 1.3s text-LCP render
                            // delay + the rs-container swap shift 0.123/0.144 on both devices).
                            // The crit preload lane reads only the crit blob; used-css faces were
                            // invisible to it. Filter the used.css file's @font-face blocks to
                            // CRIT-REFERENCED families and route them through the SAME extractor
                            // (post-paint injected per .689 — a static as=font tag render-holds
                            // Chrome; icon fonts skipped; capped; deduped). Kill: wpc_ucss_font_preload.
                            if (!empty(self::$settings['preload-crit-fonts']) && self::$settings['preload-crit-fonts'] == '1'
                                && apply_filters('wpc_ucss_font_preload', true)
                                && strpos($output, 'data-wpc-ucss-font-preload') === false) {
                                $usedCssBytes = (string) @file_get_contents($usedCssPath, false, null, 0, 131072);
                                if ($usedCssBytes !== '' && stripos($usedCssBytes, '@font-face') !== false) {
                                    $critFamilies = [];
                                    $bothDevicesCrit = (string) (isset($criticalCSSContent_Desktop) ? $criticalCSSContent_Desktop : '')
                                        . (string) (isset($criticalCSSContent_Mobile) ? $criticalCSSContent_Mobile : '');
                                    if ($bothDevicesCrit !== '' && preg_match_all('/font-family\s*:\s*["\']?([^;,"\'}<]+)/i', $bothDevicesCrit, $critFamilyMatches)) {
                                        foreach ($critFamilyMatches[1] as $critFamily) {
                                            $critFamily = strtolower(trim($critFamily));
                                            if ($critFamily !== '' && strlen($critFamily) <= 40 && stripos($critFamily, 'fallback') === false
                                                && strpos($critFamily, 'var(') === false && strpos($critFamily, '--') === false) {
                                                $critFamilies[$critFamily] = 1;
                                            }
                                        }
                                    }
                                    $usedCssCritFaces = '';
                                    if (!empty($critFamilies) && preg_match_all('/@font-face\s*\{[^{}]*\}/is', $usedCssBytes, $usedCssFaceMatches)) {
                                        foreach ($usedCssFaceMatches[0] as $usedCssFaceBlock) {
                                            if (preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $usedCssFaceBlock, $faceFamilyMatch)
                                                && !empty($critFamilies[strtolower(trim($faceFamilyMatch[1]))])) {
                                                $usedCssCritFaces .= $usedCssFaceBlock;
                                            }
                                        }
                                    }
                                    if ($usedCssCritFaces !== '' && stripos($usedCssCritFaces, 'data:font') === false) {
                                        $usedCssFontPreloads = $this->extractCriticalFontPreloads($usedCssCritFaces);
                                        if ($usedCssFontPreloads !== '') {
                                            $output .= "\r\n" . '<span data-wpc-ucss-font-preload="1" hidden></span>' . $usedCssFontPreloads;
                                        }
                                    }
                                }
                            }


                            $usedCssRel = 'wpc-stylesheet';


                            $delayV3On = is_array(self::$settings)
                                && !empty(self::$settings['delay-js-v2']) && self::$settings['delay-js-v2'] == '1'
                                && (!isset(self::$settings['delay-js-v3']) || self::$settings['delay-js-v3'] != '0');
                            if ($delayV3On && apply_filters('wpc_used_css_late', true)) {
                                $usedCssRel = 'wpc-late-stylesheet';
                            } elseif ($this->isMobile()
                                && !empty(self::$settings['minimal-mobile-css']) && self::$settings['minimal-mobile-css'] == '1'
                                && apply_filters('wpc_minimal_mobile_css', true)) {
                                $usedCssRel = 'wpc-late-stylesheet';
                            }
                            // Eager-async: used.css exists to make below-fold safe WITHOUT interaction —
                            // it must never ride the interaction gate. media-swap + loader belt.
                            // Split artifacts (ATF eager + REST href-less until human evidence) arm
                            // per-device only when BOTH siblings exist; else the legacy union link.
                            // v7.10.828 — pages with builder conceal markers only consume bundles that
                            // DECLARE the dynamic-state safelist (service stamp wpc-safelist:v1). An
                            // unstamped bundle here is the keep-the-hide/drop-the-undo artifact class.
                            $hasBuilderConcealMarkers = (strpos((string) $html, 'et-waypoint') !== false
                                    || strpos((string) $html, 'et_animated') !== false
                                    || strpos((string) $html, 'elementor-invisible') !== false)
                                && apply_filters('wpc_used_safelist_gate', true);
                            $emitUsedCssLink = function ($path, $mediaTgt, $idSfx) use (&$output, $hasBuilderConcealMarkers) {
                                $usedCssBaseUrl = rtrim(WPS_IC_CRITICAL_URL, '/') . '/used-css/';
                                $restSheetPath = (string) preg_replace('/\.css$/', '.rest.css', $path);
                                if ($hasBuilderConcealMarkers && function_exists('wpc_used_css_has_safelist_stamp')) {
                                    $stampCheckedSheet = (apply_filters('wpc_used_css_split', true) && @filesize($restSheetPath) > 32)
                                        ? $restSheetPath : $path;
                                    if (!wpc_used_css_has_safelist_stamp($stampCheckedSheet)) {
                                        if (function_exists('wpc_cache_first_log')) {
                                            wpc_cache_first_log('used-unsafelisted-skip', '', '', ['f' => basename($stampCheckedSheet)]);
                                        }
                                        return;
                                    }
                                }
                                // v7.10.674 (wire-contract §4) — atf.css is OFF the ATF lane. The inline
                                // crit already paints the fold pixel-identically and 64% of atf matches no
                                // element, so we no longer emit or attach the atf sheet at all; only the
                                // REST sheet ships, href-less, attached AFTER the load event by the boot
                                // below. Gate on REST (not atf) so this survives the service retiring the
                                // atf object (§4.1: consumer stops asking before producer stops publishing).
                                if (apply_filters('wpc_used_css_split', true) && @filesize($restSheetPath) > 32) {
                                    $restSheetUrl = $usedCssBaseUrl . rawurlencode(basename($restSheetPath)) . '?uv=' . (int) @filemtime($restSheetPath);
                                    // An href-less link with media="print" is a parked sheet: nothing
                                    // loads it until the boot script below arms it. The whole used-CSS
                                    // block runs only when the park verdict allows (see its entry), so
                                    // this link is never emitted on a render that parks nothing.
                                    $output .= "\r\n" . '<link rel="stylesheet" id="wpc-used-css-rest' . $idSfx . '" data-wpc-rest="' . esc_url($restSheetUrl) . '" data-wpc-ucss-rest="' . $mediaTgt . '" media="print">';
                                    if (strpos($output, 'wpc-bgl255-arm') === false) {
                                        $output .= self::wpc_early_gesture_arm_tag();
                                    }
                                    return;
                                }
                                $usedCssSheetUrl = $usedCssBaseUrl . rawurlencode(basename($path)) . '?uv=' . (int) @filemtime($path);
                                // v7.21.252 — KILL the media="print" swap (lowest-priority
                                // fetch, 12s arrivals, flip race, CSP-hostile — head-budget
                                // doc). Plain stylesheet link relocated to body-end by the
                                // post-pass: normal priority, no race, blocks nothing above.
                                $output .= "\r\n" . '<link rel="stylesheet" id="wpc-used-css' . $idSfx . '" data-wpc-ucss="' . $mediaTgt . '" data-wpc-endbody="1" media="' . $mediaTgt . '" onload="this.onload=null;try{document.documentElement.classList.add(\'wpc-css-live\')}catch(x){}" onerror="try{document.documentElement.classList.add(\'wpc-css-live\')}catch(x){}" href="' . esc_url($usedCssSheetUrl) . '">';
                                // v7.21.255 — the bg-park armer. Stored used-css may carry its
                                // background url() declarations behind :where(html.wpc-bgl255)
                                // (see wpc_park_used_css_backgrounds): first gesture, a scrolled boot,
                                // or a bfcache restore arms the class; a page nobody touches
                                // fetches zero CSS images. One emit per page, rides the link.
                                if (strpos($output, 'wpc-bgl255-arm') === false) {
                                    $output .= self::wpc_early_gesture_arm_tag();
                                }
                            };
                            $unionUsedCssPath = $templateKey !== '' ? wpc_used_css_path($templateKey) : '';
                            if (self::wpc_combined_crit_on() && $unionUsedCssPath !== '' && @filesize($unionUsedCssPath) > 64
                                && apply_filters('wpc_combined_union_usedcss', true)) {
                                // v7.21.245 — combined crit pairs with the UNION used-css: one
                                // file, one request, correct at every width (media-scoped device
                                // links both download regardless of media - two 63KB rows).
                                $emitUsedCssLink($unionUsedCssPath, 'all', '');
                            } elseif (self::wpc_combined_crit_on() && $usedCssMobilePath !== '' && $usedCssDesktopPath !== ''
                                && @filesize($usedCssMobilePath) > 64 && @filesize($usedCssDesktopPath) > 64
                                && basename($usedCssMobilePath) !== basename($usedCssDesktopPath)) {


                                $emitUsedCssLink($usedCssMobilePath, '(max-width: 767.98px)', '');
                                $emitUsedCssLink($usedCssDesktopPath, '(min-width: 768px)', '-d');
                                if (strpos($output, 'data-wpc-rest') !== false) {
                                    // wpc-arm-sentinel: the delay pass must never touch this boot. §4 —
                                    // atf is never attached; the matching-device REST sheet attaches AFTER
                                    // the load event (fail-open: no matchMedia -> attach, styling wins).
                                    $output .= "\r\n" . self::wpc_ucss_boot_js();
                                }
                                // First-paint header rules ≡ final header rules: inline the header
                                // slice of the very stylesheet that later applies, so its arrival
                                // cannot move the header — regardless of crit coverage, forever.
                                if (apply_filters('wpc_header_css_slice', true) && function_exists('wpc_header_css_slice')
                                    && strpos($output, 'wpc-header-css-slice') === false) {
                                    $headerTokens = self::wpc_header_markup_tokens($html);
                                    $headerSliceMobile = wpc_header_css_slice($usedCssMobilePath, $headerTokens);
                                    $headerSliceDesktop = wpc_header_css_slice($usedCssDesktopPath, $headerTokens);
                                    if (function_exists('wpc_css_isolate_sheet')) {
                                        $headerSliceMobile = wpc_css_isolate_sheet((string) $headerSliceMobile);
                                        $headerSliceDesktop = wpc_css_isolate_sheet((string) $headerSliceDesktop);
                                    }
                                    $headerSliceCss  = ($headerSliceMobile !== '' ? '@media (max-width: 767.98px){' . $headerSliceMobile . '}' : '')
                                        . ($headerSliceDesktop !== '' ? '@media (min-width: 768px){' . $headerSliceDesktop . '}' : '');
                                    if ($headerSliceCss !== '') {
                                        $output .= "\r\n" . '<style id="wpc-header-css-slice">' . $headerSliceCss . '</style>';
                                    }
                                }
                            } else {
                                $emitUsedCssLink($usedCssPath, 'all', '');
                                if (apply_filters('wpc_header_css_slice', true) && function_exists('wpc_header_css_slice')
                                    && strpos($output, 'wpc-header-css-slice') === false && !empty($usedCssPath)) {
                                    $headerSliceCss = wpc_header_css_slice($usedCssPath, self::wpc_header_markup_tokens($html));
                                    if ($headerSliceCss !== '') {
                                        $output .= "\r\n" . '<style id="wpc-header-css-slice">' . $headerSliceCss . '</style>';
                                    }
                                }
                            }
                            // v7.10.674 (§4): emit the REST-after-load boot once for ANY path that made a
                            // href-less rest link (device-split branch above, or this single-device split).
                            // atf is never attached — only the matching-device REST sheet, after load.
                            if (strpos($output, 'data-wpc-rest') !== false && strpos($output, 'wpc-ucss-boot') === false) {
                                $output .= "\r\n" . self::wpc_ucss_boot_js();
                            }


                        }
                    }


                    $minimalMobileCss = $this->isMobile()
                        && !empty(self::$settings['minimal-mobile-css']) && self::$settings['minimal-mobile-css'] == '1'
                        && apply_filters('wpc_minimal_mobile_css', true);


                    $fontPreloadFileText = trim((string) @file_get_contents(dirname($criticalCSSExists['desktop']) . '/font-preload.txt'));
                    // v7.10.718 - stored preload URLs are generation-baked; translate when CDN off.
                    if ($fontPreloadFileText !== '' && (empty(self::$cdnEnabled) || self::$cdnEnabled != '1') && function_exists('wpc_unzone_url')) {
                        $fontPreloadFileText = implode("\n", array_map('wpc_unzone_url', preg_split('/\r?\n/', $fontPreloadFileText)));
                    }
                    // Inline ATF subsets already guarantee first paint in the real face —
                    // full-file preloads then only occupy pre-paint bandwidth (~82KB on the
                    // flagship). With covering subsets present, all font preloads stand down;
                    // the full files swap in naturally off the critical path.
                    $coveringSubsets = '';
                    if (apply_filters('wpc_subset_covers_preloads', true)) {
                        $coveringSubsets = (string) @file_get_contents(dirname($criticalCSSExists['desktop']) . '/font-subsets.css');
                        if (strlen($coveringSubsets) < 1024 || stripos($coveringSubsets, 'data:font') === false) {
                            $coveringSubsets = '';
                        }
                        // Sticky: a land is mid-rewrite of the subsets file — the standdown
                        // holds if subsets were seen recently, so no render can flap back to
                        // emitting the preload freight and mint a bad edge copy.
                        if ($coveringSubsets === '') {
                            $subsetsSeenAt = (int) get_option('wpc_subsets_seen', 0);
                            if ($subsetsSeenAt && (time() - $subsetsSeenAt) < 7 * DAY_IN_SECONDS) {
                                $coveringSubsets = 'sticky';
                            }
                        }
                    }
                    if (!$minimalMobileCss
                        && $coveringSubsets === ''
                        && $fontPreloadFileText !== ''
                        && strpos($output, 'wpc-dominant-font-preload') === false) {
                        $fontPreloadCount = 0;
                        $fontPreloadUrls = [];
                        $fontPreloadCritCss = (string) $criticalCSSContent_Mobile . (string) $criticalCSSContent_Desktop;
                        foreach (array_slice(preg_split('/\r?\n/', $fontPreloadFileText), 0, 3) as $fontPreloadUrl) {
                            $fontPreloadUrl = trim((string) $fontPreloadUrl);
                            if ($fontPreloadUrl === ''
                                || substr((string) parse_url($fontPreloadUrl, PHP_URL_PATH), -6) !== '.woff2'


                                || preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|fontello|themify|elegant|feather/i', $fontPreloadUrl)
                                || !self::wpc_lcp_bg_url_allowed($fontPreloadUrl)) {
                                continue;
                            }
                            // Preload the exact URL form the critical CSS requests for this file.
                            $fontPreloadBasename = strtolower((string) basename((string) parse_url($fontPreloadUrl, PHP_URL_PATH)));
                            if ($fontPreloadBasename !== ''
                                && preg_match('#url\(\s*(["\']?)(https?://[^"\')]*?/' . preg_quote($fontPreloadBasename, '#') . ')\1?\s*\)#i', $fontPreloadCritCss, $critFontUrlMatch)) {
                                $fontPreloadUrl = $critFontUrlMatch[2];
                            }
                            $fontPreloadCount++;
                            $fontPreloadUrls[] = esc_url($fontPreloadUrl);
                        }

                        // Artifact list short? Crit-declared first-party faces (regular/bold, latin)
                        // otherwise start late and become the longest critical-chain items.
                        if ($fontPreloadCount < 3 && apply_filters('wpc_crit_face_preload', true)
                            && preg_match_all('/@font-face\s*\{[^}]*\}/is', $fontPreloadCritCss, $critFaceMatches)) {
                            $critFaceUrlsSeen = [];
                            // A family that never paints above the fold has no business on the
                            // critical chain — when the artifact names the ATF families, preload
                            // only those. No artifact → previous behavior.
                            $atfFontFamilies = [];
                            if (!empty($criticalCSSExists['desktop'])) {
                                $delayManifest = json_decode((string) @file_get_contents(dirname($criticalCSSExists['desktop']) . '/delay.json'), true);
                                $atfGlyphs = (is_array($delayManifest) && isset($delayManifest['atf_glyphs']) && is_array($delayManifest['atf_glyphs'])) ? $delayManifest['atf_glyphs'] : [];
                                if (!$atfGlyphs && is_array($delayManifest)) {
                                    foreach ($delayManifest as $delayManifestEntry) {
                                        if (is_array($delayManifestEntry) && isset($delayManifestEntry['atf_glyphs']) && is_array($delayManifestEntry['atf_glyphs']) && $delayManifestEntry['atf_glyphs']) {
                                            $atfGlyphs = $delayManifestEntry['atf_glyphs'];
                                            break;
                                        }
                                    }
                                }
                                foreach (array_keys((array) $atfGlyphs) as $atfGlyphKey) {
                                    $atfFontFamilies[strtolower(trim((string) strtok((string) $atfGlyphKey, '|')))] = 1;
                                }
                            }
                            foreach ($critFaceMatches[0] as $critFaceBlock) {
                                if ($fontPreloadCount >= 3) {
                                    break;
                                }
                                if (preg_match('/font-style\s*:\s*(italic|oblique)/i', $critFaceBlock)
                                    || !preg_match('/font-weight\s*:\s*(400|700|normal|bold)\b/i', $critFaceBlock)
                                    || !self::wpc_face_range_latin($critFaceBlock)
                                    || !preg_match('#url\(\s*(["\']?)(https?://[^"\')]+\.woff2)\1?\s*\)#i', $critFaceBlock, $critFaceUrlMatch)) {
                                    continue;
                                }
                                if ($atfFontFamilies
                                    && preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $critFaceBlock, $critFaceFamilyMatch)
                                    && !isset($atfFontFamilies[strtolower(trim((string) $critFaceFamilyMatch[1]))])) {
                                    continue;
                                }
                                $critFaceUrl = $critFaceUrlMatch[2];
                                if (isset($critFaceUrlsSeen[$critFaceUrl]) || stripos($critFaceUrl, 'fonts.gstatic') !== false
                                    || preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|themify|elegant|feather/i', $critFaceUrl)
                                    || !self::wpc_lcp_bg_url_allowed($critFaceUrl)
                                    || stripos($output, esc_url($critFaceUrl)) !== false) {
                                    continue;
                                }
                                $critFaceUrlsSeen[$critFaceUrl] = 1;
                                $fontPreloadCount++;
                                $fontPreloadUrls[] = esc_url($critFaceUrl);
                            }
                        }
                        // v7.10.796 — same url form the @font-face requests, or the preload is freight.
                        if (!empty($fontPreloadUrls) && class_exists('wps_cdn_rewrite')
                            && method_exists('wps_cdn_rewrite', 'wpc_naturalize_font_preload_url')) {
                            $fontPreloadUrls = array_values(array_unique(array_map(
                                ['wps_cdn_rewrite', 'wpc_naturalize_font_preload_url'], $fontPreloadUrls)));
                        }
                        // v7.10.689 — one post-paint injector for the whole lane; a static as=font
                        // tag render-holds Chrome 150. The marker keeps the 3703 re-entry guard true.
                        if (!empty($fontPreloadUrls) && function_exists('wpc_font_preload_postpaint_tag')) {
                            $fontPreloadTag = wpc_font_preload_postpaint_tag($fontPreloadUrls, 'wpc-dominant-font-preload');
                            if ($fontPreloadTag !== '') {
                                $output .= "\r\n" . $fontPreloadTag;
                            }
                        }
                    }


                    $atfBackgroundCritCss = $this->isMobile() ? (string) $criticalCSSContent_Mobile : (string) $criticalCSSContent_Desktop;
                    if ($atfBackgroundCritCss === '' || self::wpc_combined_crit_on()) {
                        $atfBackgroundCritCss .= (string) $criticalCSSContent_Mobile . (string) $criticalCSSContent_Desktop;
                    }
                    if (apply_filters('wpc_atf_bg_preload', true)
                        && !$imagePreloads->hasOrigin(['atf-bg'])
                        && $atfBackgroundCritCss !== ''
                        && preg_match('/(?:elementor-element-|brxe-|et_pb_|fusion-|#)[A-Za-z0-9_-]{4,}[^{}]{0,200}\{[^{}]*?background-image\s*:\s*url\(\s*["\']?([^"\')]+\.(?:avif|webp|jpe?g|png|svg))(?:\?[^"\')]*)?["\']?\s*\)[^{}]*\}/i', $atfBackgroundCritCss, $atfBackgroundRuleMatch)) {
                        $atfBackgroundUrl = html_entity_decode((string) $atfBackgroundRuleMatch[1], ENT_QUOTES);
                        $atfBackgroundAvif = '';
                        if (preg_match('/image-set\(\s*url\(\s*["\']?([^"\')]+\.avif)(?:\?[^"\')]*)?["\']?\s*\)/i', (string) $atfBackgroundRuleMatch[0], $atfBackgroundAvifMatch)) {
                            $atfBackgroundAvif = html_entity_decode((string) $atfBackgroundAvifMatch[1], ENT_QUOTES);
                        }
                        if (self::wpc_lcp_bg_url_allowed($atfBackgroundUrl)
                            && stripos($output, esc_url($atfBackgroundUrl)) === false) {
                            $imagePreloads->add('wpc-atf-bg-preload', esc_url($atfBackgroundUrl), 'both', 'atf-bg', 0, [
                                'slot'        => wps_ic_image_preload_set::SLOT_ATF_BG,
                                'kind'        => wps_ic_image_preload_set::KIND_BACKGROUND,
                                'imagesrcset' => ($atfBackgroundAvif !== '' && self::wpc_lcp_bg_url_allowed($atfBackgroundAvif)) ? esc_url($atfBackgroundAvif) : '',
                            ]);
                        }
                    }


                    if (self::wpc_combined_crit_on()) {
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Mobile, $html);
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Desktop, $html);
                    } elseif ($this->isMobile()) {
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Mobile, $html);
                    } else {
                        $output .= self::wpc_font_metric_overrides($criticalCSSContent_Desktop, $html);
                    }


                    if (function_exists('wpc_perf_debug_is_allowed') && wpc_perf_debug_is_allowed()) {
                        $wpc_dbg_blob = ($wpc_dev_key === 'mobile') ? $criticalCSSContent_Mobile : $criticalCSSContent_Desktop;
                        $wpc_dbg_ad   = self::wpc_lcp_autoderive_bg($html, (string) $wpc_dbg_blob);
                        $wpc_dbg_sf   = dirname($criticalCSSExists['desktop']) . '/font-subsets.css';
                        $output .= "\r\n<!-- WPC-PERF-DEBUG dev=" . $wpc_dev_key
                            . " | census_lcp_json=" . (is_array($measuredLcpByDevice) ? 'present' : 'absent')
                            . " | census_dev_el=" . (is_array($wpc_el) ? (($wpc_el['type'] ?? '?') . ':' . substr((string) ($wpc_el['url'] ?? ''), -46)) : 'null')
                            . " | pin_pre_url=" . (($wpc_pre_url ?? '') !== '' ? 'EMITTED:' . substr((string) $wpc_pre_url, -46) : 'EMPTY(no pin)')
                            . " | autoderive=" . (is_array($wpc_dbg_ad) ? 'HERO:' . substr((string) $wpc_dbg_ad['url'], -46) : 'NULL(no hero found)')
                            . " | font_subsets_file=" . (@is_readable($wpc_dbg_sf) ? filesize($wpc_dbg_sf) . 'B' : 'ABSENT')
                            . " | crit_has_subset_base64=" . (strpos((string) $criticalCSSContent_Desktop, 'data:font/woff2;base64') !== false ? 'YES' : 'NO')
                            . " | a3_metric_override=" . (strpos((string) $criticalCSSContent_Desktop, 'size-adjust') !== false || strpos((string) $output, 'size-adjust') !== false ? 'present' : 'absent')
                            . " | used_css_setting=" . ((!empty(self::$settings['used-css']) && self::$settings['used-css'] == '1') ? 'ON' : 'off')
                            . " | tpl_key=" . substr(trim((string) @file_get_contents(dirname($criticalCSSExists['desktop']) . '/tpl.txt')), 0, 24)
                            . " | used_css_artifact=" . ((function_exists('wpc_used_css_path') && ($wpc_dbg_tk = trim((string) @file_get_contents(dirname($criticalCSSExists['desktop']) . '/tpl.txt'))) !== '' && ($wpc_dbg_up = wpc_used_css_path($wpc_dbg_tk)) !== '' && @is_readable($wpc_dbg_up)) ? filesize($wpc_dbg_up) . 'B' : 'ABSENT')
                            . " | crit_mtime_age=" . (($wpc_dbg_mt = (int) @filemtime($criticalCSSExists['desktop'])) ? (time() - $wpc_dbg_mt) . 's' : '?')
                            . " -->";
                    }


                    // (in combined mode every visitor carries both blobs).
                    if (($this->isMobile() || self::wpc_combined_crit_on()) && !empty($criticalCSSContent_Mobile) && $minimalMobileCss) {
                        $criticalCSSContent_Mobile = self::wpc_strip_covered_fullface($criticalCSSContent_Mobile);
                    }
                    // The crit's font-family stacks get their metric fallback names from the
                    // serve-door splice (stage stack_splice, every <style> block of the page).
                    if (!empty($criticalCSSContent_Mobile)) {
                        $criticalCSSContent_Mobile = self::wpc_align_crit_urls_to_zone($criticalCSSContent_Mobile);
                    }
                    if (!empty($criticalCSSContent_Desktop)) {
                        $criticalCSSContent_Desktop = self::wpc_align_crit_urls_to_zone($criticalCSSContent_Desktop);
                    }
                    // url() faces go to the face set as late; data: faces stay on the crit path.
                    $lateFaces = '';
                    $facesCovered = self::wpc_faces_covered(
                        $output,
                        $html,
                        (string) $criticalCSSContent_Desktop . (string) $criticalCSSContent_Mobile,
                        $fontFaces
                    );
                    if (!$facesCovered && function_exists('wpc_cache_first_log')
                        && !get_transient('wpc_lf_nocov_log')) {
                        set_transient('wpc_lf_nocov_log', 1, 3600);
                        wpc_cache_first_log('late-faces-no-coverage', '', '', ['sub186' => $coveringSubsets]);
                    }
                    if ($facesCovered && apply_filters('wpc_late_faces', true)) {
                        if (!empty($criticalCSSContent_Mobile)) {
                            $criticalCSSContent_Mobile = self::wpc_demote_url_faces($criticalCSSContent_Mobile, $lateFaces, $output);
                        }
                        if (!empty($criticalCSSContent_Desktop)) {
                            $criticalCSSContent_Desktop = self::wpc_demote_url_faces($criticalCSSContent_Desktop, $lateFaces, $output);
                        }
                        // These faces are harvested from the CRIT artifact, which bakes its own
                        // unicode-range at CRIT time — while fonts.json carries the authoritative
                        // remote_range from the OBS leg, minutes later. They disagree, and the crit
                        // copy wins the wire because it never passes the CSS-file consumer. busy kept
                        // U+0-34 (covering U+33) while the map correctly held U+0-32,U+34,… so the
                        // 91 KiB icon font stayed on the critical path. Normalise against the map.
                        if ($lateFaces !== '' && class_exists('wps_cdn_rewrite')
                            && method_exists('wps_cdn_rewrite', 'wpc_font_remote_ranges')) {
                            $remoteFontRanges = wps_cdn_rewrite::wpc_font_remote_ranges();
                            // v7.10.480 — THE SECOND WRITER. .478 enforced the pairing invariant at
                            // the CSS-file consumer (cdn-rewrite.php) and this crit-artifact path was
                            // left applying the same map unconditionally, so the gate survived a full
                            // rebuild on zinsenvergleich: new icv, new filenames, identical ranges,
                            // and U+F017/U+F09D/U+F3D1 still supplied by nothing. Two writers, one
                            // guarded. Exactly the exhaustive-grep-every-writer rule I already hold.
                            $subsetFontFamilies = (class_exists('wps_cdn_rewrite')
                                && method_exists('wps_cdn_rewrite', 'wpc_font_subset_families'))
                                ? wps_cdn_rewrite::wpc_font_subset_families() : [];
                            if (!empty($remoteFontRanges)) {
                                $lateFaces = (string) preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($fm) use ($subsetFontFamilies) {
                                    $blk = $fm[0];
                                    if (stripos($blk, 'data:') !== false) { return $blk; }
                                    if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $blk, $fa)) { return $blk; }
                                    $familyName = strtolower(trim($fa[1], " \t\"'"));
                                    $rangeGate = wps_cdn_rewrite::font_face_range_gate($familyName, $blk);
                                    if ($rangeGate === null) { return $blk; }
                                    // v7.10.759 — icon-font families are never range-gated (their
                                    // glyphs come from content:"" rules no census sees; the
                                    // complement forbids exactly those codepoints). Family is in
                                    // OUR map, so any baked range here is our gate: strip it.
                                    if (function_exists('wpc_css_is_icon_font') && wpc_css_is_icon_font($familyName)) {
                                        if (function_exists('wpc_cache_first_log')) {
                                            wpc_cache_first_log('font-gate-iconfont', '', '', [
                                                'family' => substr($familyName, 0, 28), 'src' => 'crit-artifact',
                                            ]);
                                        }
                                        $blk = (string) preg_replace('/\s*;?\s*unicode-range\s*:\s*[^;}]+;?/i', '', $blk);
                                        return (string) preg_replace('/;\s*\}/', '}', $blk);
                                    }
                                    // PAIRING INVARIANT: a range without its inline subset leaves
                                    // glyphs supplied by nothing. Withholding is the safe failure.
                                    if (empty($subsetFontFamilies[$familyName])) {
                                        if (function_exists('wpc_cache_first_log')) {
                                            wpc_cache_first_log('font-gate-unpaired', '', '', [
                                                'family'   => substr($familyName, 0, 28), 'src' => 'crit-artifact',
                                                'stripped' => preg_match('/unicode-range\s*:/i', $blk) ? 1 : 0,
                                            ]);
                                        }
                                        // STRIP, do not merely decline. These faces bake their OWN
                                        // unicode-range at crit time (see above), so returning the
                                        // block untouched leaves that gate in place and the glyphs
                                        // stay unsupplied. Safe to strip precisely because the family
                                        // is in OUR map, i.e. this is our gate, not a foundry subset.
                                        $blk = (string) preg_replace('/\s*;?\s*unicode-range\s*:\s*[^;}]+;?/i', '', $blk);
                                        return (string) preg_replace('/;\s*\}/', '}', $blk);
                                    }
                                    // A weightless face of a variable font gets the subset's weight range too
                                    // (see font_face_range_gate: without it the 400 text falls to the fallback).
                                    if ($rangeGate['weight'] !== null) {
                                        $blk = (string) preg_replace('/\}\s*$/', ';font-weight:' . $rangeGate['weight'] . '}', $blk, 1);
                                    }
                                    $want = 'unicode-range:' . $rangeGate['range'];
                                    if (preg_match('/unicode-range\s*:\s*[^;}]+/i', $blk)) {
                                        return (string) preg_replace('/unicode-range\s*:\s*[^;}]+/i', $want, $blk, 1);
                                    }
                                    return (string) preg_replace('/\}\s*$/', ';' . $want . '}', $blk, 1);
                                }, $lateFaces);
                            }
                        }
                    }
                    // Output critical CSS — preload links are now added by addCritical() after filtering


                    // A service-built combined artifact replaces the two-blob wrap when fresh:
                    // it carries both devices already deduped. Absent, stale, or carrying any
                    // remote font reference → the wrap below serves exactly as before.
                    $combinedCrit = '';
                    if (self::wpc_combined_crit_on() && apply_filters('wpc_crit_combined_artifact', true)
                        && !empty($criticalCSSExists['desktop'])) {
                        $combinedCritDir = dirname($criticalCSSExists['desktop']);
                        $combinedCritFile = $combinedCritDir . '/critical_combined.css';
                        // Combined must never shadow NEWER device files: pull lanes land
                        // devices, and the same-uuid law skips re-pulls — without this stat
                        // compare a stale combined serves forever (hawkeye receipt: fresh
                        // 118KB device files on disk, page inlining the old combined).
                        $combinedCritStale = @is_file($combinedCritFile)
                            && (int) @filemtime($combinedCritFile) < (int) @filemtime($criticalCSSExists['desktop']);
                        if ($combinedCritStale && function_exists('wpc_cache_first_log') && !get_transient('wpc_cmb_rej_log')) {
                            set_transient('wpc_cmb_rej_log', 1, 3600);
                            wpc_cache_first_log('cmb-rejected', basename($combinedCritDir), '', ['why' => 'stale-mtime']);
                        }
                        if (!$combinedCritStale && @is_readable($combinedCritFile) && @filesize($combinedCritFile) > 1024) {
                            $combinedCrit = (string) @file_get_contents($combinedCritFile);
                            $combinedRejectReason = '';
                            // The service's combined embeds raw gstatic faces; we already hold the
                            // gstatic→local map, so localize before judging. Unmapped refs still reject.
                            if ($combinedCrit !== '' && stripos($combinedCrit, 'fonts.gstatic.com') !== false
                                && defined('WPS_IC_FONTS_URL') && function_exists('get_option')) {
                                $googleFontsInlineMap = get_option('wps_ic_fonts_inline_map');
                                if (is_array($googleFontsInlineMap) && $googleFontsInlineMap) {
                                    // The map is an option and the files are in the cache directory: a
                                    // staging copy, or a site whose cache directory was emptied, has the
                                    // map without the files. This swap runs before the font stage, so
                                    // that stage never sees the gstatic URL and never asks the localizer
                                    // to fetch the file; the page kept naming fonts that answer 404
                                    // (Third Wing staging, ticket 12076: four inline/*.woff2). The swap
                                    // and the verdict below are unchanged; when any mapped file is
                                    // missing from disk the localizer is asked, and it fetches them all
                                    // (one of the four was named only by the font carrier, from an
                                    // earlier crit, not by this one).
                                    $combinedCritAsDelivered = $combinedCrit;
                                    $mappedFilesMissing = 0;
                                    foreach ($googleFontsInlineMap as $gstaticUrl => $localFontFile) {
                                        if (!is_string($gstaticUrl) || !is_string($localFontFile) || $localFontFile === '') {
                                            continue;
                                        }
                                        if (defined('WPS_IC_FONTS_DIR') && !file_exists(WPS_IC_FONTS_DIR . 'inline/' . $localFontFile)) {
                                            $mappedFilesMissing++;
                                        }
                                        $combinedCrit = str_replace($gstaticUrl, WPS_IC_FONTS_URL . 'inline/' . $localFontFile, $combinedCrit);
                                    }
                                    if ($mappedFilesMissing > 0 && class_exists('wps_ic_fonts')) {
                                        if (function_exists('wpc_cache_first_log') && !get_transient('wpc_inline_font_missing_log')) {
                                            set_transient('wpc_inline_font_missing_log', 1, 3600);
                                            wpc_cache_first_log('font-inline-files-missing', basename($combinedCritDir), '', ['n' => $mappedFilesMissing, 'lane' => 'combined-crit']);
                                        }
                                        (new wps_ic_fonts())->maybeScheduleInlineLocalize($combinedCritAsDelivered);
                                    }
                                }
                            }
                            if ($combinedCrit === '' || stripos($combinedCrit, '@media') === false) {
                                $combinedRejectReason = 'shape';
                            } elseif (stripos($combinedCrit, 'fonts.gstatic.com') !== false) {
                                $combinedRejectReason = 'gstatic';
                            } elseif (stripos($combinedCrit, '</style') !== false) {
                                $combinedRejectReason = 'breakout';
                            } elseif (($combinedCoverageGap = self::wpc_combined_crit_coverage($combinedCrit, $combinedCritFile, $criticalCSSExists)) !== '') {
                                $combinedRejectReason = 'coverage-' . $combinedCoverageGap;
                            }
                            if ($combinedRejectReason !== '') {
                                $combinedCrit = '';
                                if (function_exists('wpc_cache_first_log') && !get_transient('wpc_cmb_rej_log')) {
                                    set_transient('wpc_cmb_rej_log', 1, 3600);
                                    wpc_cache_first_log('cmb-rejected', basename($combinedCritDir), '', ['why' => $combinedRejectReason]);
                                }
                            }
                        }
                    }
                    if ($combinedCrit !== '') {
                        $combinedCrit = self::wpc_prune_duplicate_css_rules($combinedCrit);
                        $combinedCrit = self::wpc_align_crit_urls_to_zone($combinedCrit);
                        if (self::wpc_faces_covered($output, $html, $combinedCrit, $fontFaces)
                            && apply_filters('wpc_late_faces', true)) {
                            $combinedCrit = self::wpc_demote_url_faces($combinedCrit, $lateFaces, $output);
                        }
                        $googleFontFaces = '';
                        if (!self::wpc_fonts_lane_should_yield(['mobile', 'desktop'])) {
                            $googleFontFaces = $this->maybeInlineGoogleFontFaces($html, $combinedCrit);
                            // element-shaped return (standalone lanes); this lane embeds INSIDE a
                            // style tag — a nested <style> ends the outer block at its close (breakout)
                            if ($googleFontFaces !== '') {
                                $googleFontFaces = preg_replace('#</?style[^>]*>#i', '', $googleFontFaces);
                            }
                            if ($googleFontFaces !== '') {
                                $googleFontFaces = $this->filterCriticalFontFaces($googleFontFaces);
                            }
                            if ($googleFontFaces !== '' && apply_filters('wpc_gfaces_latin_only', self::wpc_gfaces_latin_default())) {
                                $googleFontFaces = self::wpc_gfaces_prune_ranges($googleFontFaces);
                            }
                            if ($googleFontFaces !== ''
                                && self::wpc_faces_covered($output, $html, $combinedCrit, $fontFaces)
                                && apply_filters('wpc_late_faces', true)) {
                                $googleFontFaces = self::wpc_demote_url_faces($googleFontFaces, $lateFaces, $output . $combinedCrit);
                            }
                        }
                        $combinedCritPayload = self::wpc_note_conceal_guard($googleFontFaces . $combinedCrit);
                        if (stripos($combinedCritPayload, '</style') !== false) {
                            $combinedCritPayload = preg_replace('#</?style[^>]*>#i', '', $combinedCritPayload);
                            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_cmb_nest_log')) {
                                set_transient('wpc_cmb_nest_log', 1, 3600);
                                wpc_cache_first_log('cmb-nested-style-stripped', '', '', []);
                            }
                        }
                        $output .= "\r\n" . self::wpc_build_crit_style_tag('id="wpc-critical-css" class="wpc-critical-css-combined" data-wpc-cmb="1"', $combinedCritPayload);
                    } elseif (self::wpc_combined_crit_on()
                        && !empty($criticalCSSContent_Mobile) && !empty($criticalCSSContent_Desktop)
                        && self::wpc_combined_both_blobs_required()) {


                        $wpc_mb = self::wpc_note_conceal_guard($criticalCSSContent_Mobile);
                        $wpc_db = self::wpc_note_conceal_guard($criticalCSSContent_Desktop);
                        if (function_exists('wpc_css_isolate_sheet')) {
                            $wpc_mb = wpc_css_isolate_sheet($wpc_mb);
                            $wpc_db = wpc_css_isolate_sheet($wpc_db);
                            $wpc_mo = 0;
                            $wpc_do = 0;
                        } else {
                            $wpc_mo = substr_count($wpc_mb, '{') - substr_count($wpc_mb, '}');
                            $wpc_do = substr_count($wpc_db, '{') - substr_count($wpc_db, '}');
                            if (substr_count($wpc_mb, '/*') > substr_count($wpc_mb, '*/')) { $wpc_mb .= '*/'; }
                            if (substr_count($wpc_db, '/*') > substr_count($wpc_db, '*/')) { $wpc_db .= '*/'; }
                        }
                        if ($wpc_mo >= 0 && $wpc_mo <= 64 && $wpc_do >= 0 && $wpc_do <= 64) {
                            if ($wpc_mo > 0) { $wpc_mb .= str_repeat('}', $wpc_mo); }
                            if ($wpc_do > 0) { $wpc_db .= str_repeat('}', $wpc_do); }
                            $output .= "\r\n" . self::wpc_build_crit_style_tag('id="wpc-critical-css" class="wpc-critical-css-combined"',
                                '@media (max-width: 767.98px){' . $wpc_mb . '}'
                                . '@media (min-width: 768px){' . $wpc_db . '}');
                        } else {


                            if (function_exists('wpc_cache_first_log')) {
                                wpc_cache_first_log('combined-brace-fallback', $this->isMobile() ? 'mobile' : 'desktop', '', ['mo' => $wpc_mo, 'do' => $wpc_do]);
                            }
                            if ($this->isMobile()) {
                                $output .= "\r\n" . self::wpc_build_crit_style_tag('id="wpc-critical-css" class="wpc-critical-css-mobile"', self::wpc_note_conceal_guard($criticalCSSContent_Mobile));
                            } else {
                                $output .= "\r\n" . self::wpc_build_crit_style_tag('id="wpc-critical-css" class="wpc-critical-css-desktop"', self::wpc_note_conceal_guard($criticalCSSContent_Desktop));
                            }
                        }
                    } elseif ($this->isMobile() && !empty($criticalCSSContent_Mobile)) {
                        $output .= "\r\n" . self::wpc_build_crit_style_tag('id="wpc-critical-css" class="wpc-critical-css-mobile"', self::wpc_note_conceal_guard($criticalCSSContent_Mobile));
                    } elseif (!$this->isMobile() && !empty($criticalCSSContent_Desktop)) {
                        $output .= "\r\n" . self::wpc_build_crit_style_tag('id="wpc-critical-css" class="wpc-critical-css-desktop"', self::wpc_note_conceal_guard($criticalCSSContent_Desktop));
                    }


                    // v7.10.704 — a scoped conceal-guard NEEDS its releaser on the page even when
                    // no used-css rest link exists (the boot's no-rest branch adds the class at
                    // load+idle). Invariant, not ordering: the crit emission above may run after
                    // both boot sites, so the guard-scoped page gets its own late emission here.
                    $critGuards = [];
                    if (self::$concealGuardNeedsRelease && strpos($output, 'wpc-ucss-boot') === false) {
                        // The fallback unparks only on a page that runs the used-css lane: a split rest
                        // link (data-wpc-rest) or the whole-sheet link (data-wpc-ucss=, greenvalleytint,
                        // whose Additional CSS and picture rule come back at 2.5 s this way).
                        $usedCssOnPage = strpos($output, 'data-wpc-rest') !== false || strpos($output, 'data-wpc-ucss=') !== false;
                        $output .= "\r\n" . self::wpc_ucss_boot_js($usedCssOnPage);
                        $critGuards['releaser'] = 1;
                    }
                    $critFile = !empty($criticalCSSExists['desktop']) ? $criticalCSSExists['desktop']
                        : (!empty($criticalCSSExists['mobile']) ? $criticalCSSExists['mobile'] : '');
                    if ($critFile !== '') {
                        $atfRevealCss = self::wpc_atf_reveal_css(dirname($critFile));
                        if ($atfRevealCss !== '') {
                            $output .= "\r\n" . '<style type="text/css" id="wpc-atf-reveal">' . $atfRevealCss . '</style>';
                        }
                    }
                    // ANIMATION-REVEAL BELT. Divi hides scroll-animated elements with the
                    // UNCONDITIONAL rule .et-waypoint:not(.et_pb_counters){opacity:0} and reveals
                    // them via .et_pb_animation_*.et-animated{animation:...}. used-css extraction
                    // keeps the hide and drops EVERY .et-animated selector while retaining the
                    // orphaned @keyframes — receipted on busyprosai: blurb icons at computed
                    // opacity:0, going invisible the moment the rest bundle lands (t+1004ms) and
                    // never recovering. Safe by construction: .et-animated is written by DIVI'S OWN
                    // JS, so it only ever marks elements Divi has already decided are visible.
                    // !important because the hide rule ships in a LATER sheet at equal specificity.
                    if (apply_filters('wpc_anim_reveal_belt', true)) {
                        $output .= "\r\n" . '<style id="wpc-anim-reveal">.et-waypoint.et-animated{opacity:1!important}</style>';
                        // The rule is written on every page; it can only act where Divi's markup is.
                        if (strpos((string) $html, 'et-waypoint') !== false) {
                            $critGuards['anim_reveal'] = 1;
                        }
                    }
                    // v7.10.606 — body-scope guard: a nested-document reset in the page-level
                    // used-css bundle turned staging black and absolutely positioned its body.
                    if (function_exists('wpc_body_scope_guard_css')) {
                        $bodyScopeGuard = wpc_body_scope_guard_css(
                            (string) $criticalCSSContent_Desktop . (string) $criticalCSSContent_Mobile,
                            (strpos($output, 'wpc-used-css') !== false || strpos((string) $html, 'wpc-used-css') !== false)
                        );
                        $output .= $bodyScopeGuard;
                        if ($bodyScopeGuard !== '') {
                            $critGuards['body_scope'] = 1;
                        }
                    }
                    // The guards that stand in for rules nothing owns at first paint: the conceal
                    // guard's release (no pass owns it), Divi's reveal and the body reset (both
                    // lost or added by the used-CSS bundle). Sampled: a page that needs one needs
                    // it on every render.
                    if ($critGuards !== [] && function_exists('wpc_render_belt_note')) {
                        wpc_render_belt_note('crit-tail-guards', $critGuards, true);
                    }
                    // The faces this lane demoted go to the render's face set, which decides what
                    // the late block holds and writes it once. The byte and tuple dedupes, the
                    // live-coverage strip and the icon split are the set's own rules on insert,
                    // and the loader's flip script rides the block it emits.
                    if (!empty($lateFaces)) {
                        $fontFaces->add($lateFaces, 'crit-demoted', false);
                    }

                }
            }
        }

        // v7.10.602 — record the declarations this healthy render produced, so a render the crit
        // cannot reach (logged-in) can replay them. .561's carrier above cannot cover that case:
        // it lives inside this method, and those renders never call it.
        if (function_exists('wpc_font_carrier_record')
            && !(function_exists('is_user_logged_in') && is_user_logged_in())
            && stripos((string) $output, '@font-face') !== false) {
            wpc_font_carrier_record((string) $output);
        }

        return $output;
    }

    function filterCriticalFontFaces(string $critical): string
    {
        $blockedFonts = get_option('wps_ic_remove_fonts');
        if (empty($blockedFonts)) {
            return $critical;
        }

        // Match @font-face { ... } blocks (multiline, non-greedy)
        $pattern = '/@font-face\s*\{.*?\}/is';

        $filteredCritical = preg_replace_callback($pattern, function ($match) use ($blockedFonts) {
            $fontFaceBlock = $match[0];

            // Compare against the declared family name only — a substring test over the
            // whole block also matches URLs and sibling families ("Roboto" vs "Roboto Slab").
            if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $fontFaceBlock, $familyMatch)) {
                return $fontFaceBlock;
            }
            $familyName = strtolower(trim($familyMatch[1]));
            foreach ($blockedFonts as $blocked) {
                if ($familyName === strtolower(trim((string) $blocked))) {
                    return '';
                }
            }

            return $fontFaceBlock;
        }, $critical);
        return is_string($filteredCritical) ? $filteredCritical : $critical;
    }


    private function extractCriticalFontPreloads(string $criticalCss): string
    {
        if (empty($criticalCss)) return '';

        // Extract only @font-face blocks — don't match random url() in other rules
        if (!preg_match_all('/@font-face\s*\{[^}]+\}/is', $criticalCss, $fontFaceBlocks)) {
            return '';
        }
        $fontFaceCss = implode(' ', $fontFaceBlocks[0]);

        // Extract font file URLs from the @font-face blocks
        $fontPattern = '/url\((\'|")?([^\'")\s]+\.(woff2|woff|ttf|otf|eot))\1?\)/i';
        if (!preg_match_all($fontPattern, $fontFaceCss, $matches, PREG_SET_ORDER)) {
            return '';
        }
        // v7.22.32 — url -> family, so the injector can skip faces no painted element uses
        $familyByUrl = [];
        foreach ($fontFaceBlocks[0] as $faceBlock) {
            if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $faceBlock, $familyMatch)) { continue; }
            if (preg_match_all($fontPattern, $faceBlock, $faceUrlMatches, PREG_SET_ORDER)) {
                foreach ($faceUrlMatches as $faceUrlMatch) { $familyByUrl[$faceUrlMatch[2]] = trim($familyMatch[1]); }
            }
        }

        // Prioritize woff2 (smallest, most modern), then woff
        usort($matches, function ($a, $b) {
            $order = ['woff2' => 0, 'woff' => 1, 'ttf' => 2, 'otf' => 3, 'eot' => 4];
            return ($order[strtolower($a[3])] ?? 5) - ($order[strtolower($b[3])] ?? 5);
        });

        $maxPreloads = 4;
        $loadedFonts = [];
        $preloadLinks = '';

        foreach ($matches as $match) {
            if (count($loadedFonts) >= $maxPreloads) break;

            $fontUrl = $match[2];

            // Skip icon fonts
            if (preg_match('/icon|awesome|fa[- 0-9]|material|dashicon|glyphicon|icomoon|ionicon|line.?awesome|themify|elegant|feather|simple.?line/i', $fontUrl)) {
                continue;
            }

            // Skip data URIs
            if (strpos($fontUrl, 'data:') === 0) continue;

            // Deduplicate by base URL (strip query strings)
            $baseUrl = strtok($fontUrl, '?');
            if (in_array($baseUrl, $loadedFonts)) continue;

            // Correct MIME type from extension
            $ext = strtolower($match[3]);
            $typeMap = [
                'woff2' => 'font/woff2',
                'woff'  => 'font/woff',
                'ttf'   => 'font/ttf',
                'otf'   => 'font/otf',
                'eot'   => 'application/vnd.ms-fontobject',
            ];
            $type = $typeMap[$ext] ?? 'font/woff2';


            $preloadEntries[] = isset($familyByUrl[$fontUrl]) ? [$fontUrl, $type, $familyByUrl[$fontUrl]] : [$fontUrl, $type];
            $loadedFonts[] = $baseUrl;
        }
        self::$wpc_font_preloads_emitted = $loadedFonts;

        // v7.10.689 — post-paint injected; a static as=font tag render-holds Chrome 150.
        // Raw URLs ride the JSON, so the .43 parity probe's strpos($hay, $baseUrl) still hits.
        return (!empty($preloadEntries) && function_exists('wpc_font_preload_postpaint_tag'))
            ? wpc_font_preload_postpaint_tag($preloadEntries) . "\n"
            : '';
    }


    public static $wpc_font_preloads_emitted = [];

    public function optimizeGoogleFonts($html)
    {
        $pattern = '/<link\s+[^>]*href=["\']([^"\']*fonts\.googleapis\.com\/css[^"\']*)["\'][^>]*>/i';
        $html = preg_replace_callback($pattern, [__CLASS__, 'optimizeGoogleFontsRewrite'], $html);
        return $html;
    }

    public function optimizeGoogleFontsRewrite($html)
    {
        $html = '';
        return $html;
    }


    private static function atfFontsFromCss($criticalCss, $html = '')
    {
        $cc  = (string) $criticalCss;
        $src = $cc . (is_string($html) ? $html : '');

        $famVar = $wVar = $styleVar = [];
        if ($src !== '' && preg_match_all('/(--[\w-]+?-font-(?:family|weight|style))\s*:\s*([^;}{]+)/i', $src, $vm, PREG_SET_ORDER)) {
            foreach ($vm as $v) {
                $name = strtolower(trim($v[1]));
                $val  = trim($v[2]);
                if (substr($name, -12) === '-font-family') {
                    $fam = strtolower(trim(trim(explode(',', $val)[0]), " \t\"'"));
                    if ($fam !== '' && strpos($fam, 'var(') === false) $famVar[$name] = $fam;
                } elseif (substr($name, -12) === '-font-weight') {
                    if (preg_match('/\b(\d{3})\b/', $val, $mw)) $wVar[$name] = $mw[1];
                    elseif (stripos($val, 'normal') !== false) $wVar[$name] = '400';
                    elseif (stripos($val, 'bold') !== false) $wVar[$name] = '700';
                } else { // -font-style
                    $styleVar[$name] = (stripos($val, 'italic') !== false || stripos($val, 'oblique') !== false) ? 'italic' : 'normal';
                }
            }
        }


        $families = []; $pairs = [];
        if (preg_match_all('/\{([^{}]*)\}/s', $cc, $blocks)) {
            foreach ($blocks[1] as $block) {
                if (!preg_match('/(?<![\w-])font-family\s*:\s*([^;]+)/i', $block, $fm)) continue;
                $ftok = strtolower(trim(trim(explode(',', $fm[1])[0]), " \t\"'"));
                if ($ftok === '') continue;
                $fam = null; $idWeightVar = null;
                if (strpos($ftok, 'var(') !== false) {
                    if (preg_match('/var\(\s*(--[\w-]+)/i', $ftok, $mv)) {
                        $vn = strtolower($mv[1]);
                        if (isset($famVar[$vn])) {
                            $fam = $famVar[$vn]; $idWeightVar = preg_replace('/-font-family$/', '-font-weight', $vn);
                        } else {


                            if (preg_match('/' . preg_quote($vn, '/') . '\s*:\s*([^;}{]+)/i', $src, $gv)) {
                                $gfam = strtolower(trim(trim(explode(',', trim($gv[1]))[0]), " \t\"'"));
                                if ($gfam !== '' && strpos($gfam, 'var(') === false
                                    && preg_match('/^[a-z][a-z0-9 _-]{1,48}$/', $gfam)) {
                                    $fam = $gfam;
                                }
                            }
                        }
                    }
                } else {
                    $fam = $ftok;
                }
                if ($fam === null) continue;
                $families[$fam] = true;
                $w = null;
                if (preg_match('/(?<![\w-])font-weight\s*:\s*([^;]+)/i', $block, $wm)) {
                    $wv = trim($wm[1]);
                    if (stripos($wv, 'var(') !== false) {
                        if (preg_match('/var\(\s*(--[\w-]+)/i', $wv, $mv2) && isset($wVar[strtolower($mv2[1])])) $w = $wVar[strtolower($mv2[1])];
                    } elseif (preg_match('/\b(\d{3})\b/', $wv, $mw3)) { $w = $mw3[1]; }
                    elseif (stripos($wv, 'bold') !== false) { $w = '700'; }
                    elseif (stripos($wv, 'normal') !== false) { $w = '400'; }
                }
                if ($w === null && $idWeightVar !== null && isset($wVar[$idWeightVar])) $w = $wVar[$idWeightVar];
                if ($w === null) $w = '400';


                $style = null;
                if (preg_match('/(?<![\w-])font-style\s*:\s*([^;]+)/i', $block, $sm)) {
                    $sv = trim($sm[1]);
                    if (stripos($sv, 'var(') !== false) {
                        if (preg_match('/var\(\s*(--[\w-]+)/i', $sv, $mv4) && isset($styleVar[strtolower($mv4[1])])) $style = $styleVar[strtolower($mv4[1])];
                    } elseif (stripos($sv, 'italic') !== false || stripos($sv, 'oblique') !== false) { $style = 'italic'; }
                    elseif (stripos($sv, 'normal') !== false) { $style = 'normal'; }
                }
                if ($style === null && $idWeightVar !== null) {
                    $idStyleVar = preg_replace('/-font-weight$/', '-font-style', $idWeightVar);
                    if (isset($styleVar[$idStyleVar])) $style = $styleVar[$idStyleVar];
                }
                if ($style === null) $style = 'normal';
                $pairs[$fam . '|' . $w . '|' . $style] = true;
            }
        }
        return [$families, $pairs];
    }

    /**
     * Pick the ATF face set: only faces whose (family, weight) the critical CSS actually uses above the fold
     * ($atfPairs) — so no over-fetch of unused cached weights. A family used ATF whose exact weight isn't in the
     * cache still gets ONE fallback face (coverage, never FOUT), never more. Capped at $cap. Returns raw
     * @font-face strings in order; the preloader dedups identical URLs.
     */
    private static function pickAtfFaces($faces, $atfFamilies, $atfPairs, $cap = 4)
    {
        $byFam = [];
        foreach ($faces as $f) {
            if (empty($f['family']) || !isset($atfFamilies[$f['family']]) || empty($f['latin'])) continue;
            $bucket = isset($atfPairs[$f['family'] . '|' . $f['weight'] . '|' . (isset($f['style']) ? $f['style'] : 'normal')]) ? 'exact' : 'other';
            $byFam[$f['family']][$bucket][] = $f['raw'];
        }
        if (empty($byFam)) return [];
        $keep = [];

        foreach ($byFam as $g) {
            if (empty($g['exact'])) continue;
            foreach ($g['exact'] as $raw) { if (count($keep) >= $cap) return $keep; $keep[] = $raw; }
        }
        // 2) coverage: a family used ATF whose exact weight isn't cached gets ONE fallback face (no FOUT)
        foreach ($byFam as $g) {
            if (count($keep) >= $cap) break;
            if (empty($g['exact']) && !empty($g['other'])) $keep[] = $g['other'][0];
        }
        return $keep;
    }


    public static function wpc_gfaces_latin_default()
    {
        $l = strtolower((string) (function_exists('get_locale') ? get_locale() : ''));
        foreach (['ru', 'uk', 'bg', 'sr', 'be', 'mk', 'kk', 'ky', 'mn', 'tg', 'el', 'vi', 'he', 'ar', 'fa', 'ur', 'ckb', 'azb', 'th', 'ka', 'hy', 'zh', 'ja', 'ko', 'hi', 'mr', 'ne', 'bn', 'ta', 'te', 'ml', 'kn', 'gu', 'pa', 'si', 'my', 'km', 'lo', 'am'] as $p) {
            if (strpos($l, $p) === 0) {
                return false;
            }
        }
        return true;
    }

    /** [$lo, $hi] weight span for a face block; keywords and variable ranges normalized. */
    public static function wpc_face_weight_span($block)
    {
        if (!preg_match('/font-weight\s*:\s*([^;}]+)/i', $block, $m)) {
            return [400, 400];
        }
        $w = strtolower(trim($m[1]));
        $w = str_replace(['normal', 'bold'], ['400', '700'], $w);
        if (preg_match('/^(\d{1,4})\s+(\d{1,4})$/', $w, $r)) {
            return [(int) $r[1], (int) $r[2]];
        }
        return preg_match('/^(\d{1,4})$/', $w, $r) ? [(int) $r[1], (int) $r[1]] : [400, 400];
    }

    /** True when the face has no unicode-range or one that includes base latin. */
    public static function wpc_face_range_latin($block)
    {
        if (!preg_match('/unicode-range\s*:\s*([^;}]+)/i', $block, $ur)) {
            return true;
        }
        $r = strtoupper($ur[1]);
        return strpos($r, 'U+0000') !== false || strpos($r, 'U+00-') !== false || strpos($r, 'U+0-') !== false;
    }

    /**
     * Drop the non-latin subsets out of a set of Google-Fonts faces about to be inlined into the
     * critical CSS. A gfont face set arrives one face per script, and on a latin-locale site
     * every script but latin is bytes in the first-paint block for a file the page never fetches.
     *
     * Scope is the gfont-inline lane and nothing else: a face from any other origin keeps every
     * range it declares, because only here does the plugin know the whole coverage set it is
     * pruning and that the locale rules the rest out. The call sites carry the locale guard
     * (wpc_gfaces_latin_only / wpc_gfaces_latin_default).
     *
     * Latin (U+0000-00FF) and latin-ext (U+0100-…) always stay, and so does a private-use range —
     * an icon font's own alphabet is not a script a locale can rule out. A face declaring no
     * unicode-range is full coverage and is never touched. Fails open: a prune that would leave
     * no face standing hands back the set it was given.
     */
    public static function wpc_gfaces_prune_ranges($faces)
    {
        $out = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) {
            if (!preg_match('/unicode-range\s*:\s*([^;}]+)/i', $m[0], $ur)) {
                return $m[0];
            }
            $r = strtoupper($ur[1]);
            $keep = strpos($r, 'U+0000') !== false || strpos($r, 'U+00-') !== false
                || strpos($r, 'U+0-') !== false || strpos($r, 'U+0100') !== false
                || self::wpc_face_range_private_use($r);

            return $keep ? $m[0] : '';
        }, $faces);
        if (!is_string($out) || stripos($out, '@font-face') === false) {
            return $faces;
        }

        return trim($out);
    }

    /** True when every segment of an upper-cased unicode-range list starts at or above U+E000:
     *  a private-use or symbol alphabet, which belongs to an icon font rather than to a script. */
    private static function wpc_face_range_private_use($range)
    {
        $segments = 0;
        foreach (explode(',', $range) as $segment) {
            if (!preg_match('/U\+([0-9A-F?]+)/', trim($segment), $m)) {
                return false;
            }
            if (hexdec(str_replace('?', '0', $m[1])) < 0xE000) {
                return false;
            }
            $segments++;
        }

        return $segments > 0;
    }

    /**
     * v7.10.726 — parse a face's unicode-range into merged [lo,hi] codepoint intervals.
     * Returns null when the face declares no range (which means "every codepoint", NOT a
     * set we may reason about) or when any token fails to parse. null is the fail-safe:
     * every caller treats it as "cannot prove anything".
     */
    public static function wpc_face_range_set($block)
    {
        if (!preg_match('/unicode-range\s*:\s*([^;}]+)/i', (string) $block, $m)) {
            return null;
        }
        $out = [];
        foreach (explode(',', strtoupper($m[1])) as $tok) {
            $tok = trim($tok);
            if ($tok === '') {
                continue;
            }
            if (strpos($tok, 'U+') !== 0) {
                return null;
            }
            $tok = substr($tok, 2);
            if (strpos($tok, '-') !== false) {
                $p = explode('-', $tok, 2);
                if (!preg_match('/^[0-9A-F]{1,6}$/', $p[0]) || !preg_match('/^[0-9A-F]{1,6}$/', $p[1])) {
                    return null;
                }
                $lo = hexdec($p[0]);
                $hi = hexdec($p[1]);
            } elseif (strpos($tok, '?') !== false) {
                if (!preg_match('/^[0-9A-F]*\?+$/', $tok)) {
                    return null;
                }
                $lo = hexdec(str_replace('?', '0', $tok));
                $hi = hexdec(str_replace('?', 'F', $tok));
            } else {
                if (!preg_match('/^[0-9A-F]{1,6}$/', $tok)) {
                    return null;
                }
                $lo = $hi = hexdec($tok);
            }
            if ($hi < $lo) {
                return null;
            }
            $out[] = [$lo, $hi];
        }
        if (!$out) {
            return null;
        }
        sort($out);
        $merged = [array_shift($out)];
        foreach ($out as $iv) {
            $last = count($merged) - 1;
            if ($iv[0] <= $merged[$last][1] + 1) {
                if ($iv[1] > $merged[$last][1]) {
                    $merged[$last][1] = $iv[1];
                }
            } else {
                $merged[] = $iv;
            }
        }
        return $merged;
    }

    /**
     * v7.10.726 — is every codepoint of $cand inside $cover? Both must be real sets: a null
     * (undeclared) range on EITHER side returns false, because "applies to everything" is a
     * declaration of applicability, not a proof of glyph coverage — the whole reason t601
     * refused to act on range-free faces.
     */
    public static function wpc_range_covers($cover, $cand)
    {
        if (!is_array($cover) || !is_array($cand) || !$cover || !$cand) {
            return false;
        }
        foreach ($cand as $c) {
            $in = false;
            foreach ($cover as $v) {
                if ($v[0] <= $c[0] && $v[1] >= $c[1]) {
                    $in = true;
                    break;
                }
            }
            if (!$in) {
                return false;
            }
        }
        return true;
    }

    /** family|lo-hi|style identity for a face block ('' when family is missing). */
    public static function wpc_face_key($block)
    {
        if (!preg_match('/font-family\s*:\s*["\']?([^;"\'}]+)/i', $block, $ff)) {
            return '';
        }
        $sp = self::wpc_face_weight_span($block);
        $s = preg_match('/font-style\s*:\s*(italic|oblique)/i', $block) ? 'i' : 'n';
        return strtolower(trim($ff[1])) . '|' . $sp[0] . '-' . $sp[1] . '|' . $s;
    }



    /**
     * §8.1 (v7.10.681) — act on the wire manifest's LCP-asset decision: inline an SVG hero as a
     * data: URI when the service's decideLcpDelivery() verdict says so. Local-read only — NEVER a
     * render-time fetch: the bytes come from the on-disk upload (the CDN url reverses to
     * WP_CONTENT_DIR/uploads/…), matched host-agnostically so it survives CDN rewriting.
     *
     * Contract v1 (locked with the service 2026-08-02): wire[dev].lcp = {selector, vehicle:
     * css-background|img, url, asset_type: svg|raster, verdict: inline-data-uri|keep|none,
     * preload_action: remove|keep, evidence:{bytes,compressible}}. Acts ONLY on verdict
     * 'inline-data-uri', and hard-belts SVG + ≤12KB on the local file regardless — a raster or an
     * oversized SVG on the render-blocking document is the §8.1 inversion that LOSES. preload_action
     * is honoured EXPLICITLY (never inferred). keep/none, non-svg, unreadable/oversized local, or an
     * absent lcp entry => no-op. No-op today (the manifest carries no lcp yet). Filter
     * wpc_wire_lcp_inline. NOTE (corrected v7.10.682): on a lean document Lantern DOES credit
     * this — the service measured −572ms simulated LCP on wpcompress — so judge it on PSI; the
     * cflog 'wire-lcp-inlined' receipt additionally proves the effect on real loads.
     */
    public static function wpc_inline_wire_lcp($html, $imagePreloads = null)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_wire_lcp_inline', true)
            || !defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
            return $html;
        }
        try {
            $urlKey = (string) (new wps_ic_url_key())->setup('');
            if ($urlKey === '') { return $html; }
            $wf = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim($urlKey, '/') . '/wire.json';
            if (!@is_readable($wf)) { return $html; }
            $wire = json_decode((string) @file_get_contents($wf), true);
            if (!is_array($wire) || empty($wire['wire'])) { return $html; }
            $dev = (function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop';
            $lcp = (isset($wire['wire'][$dev]['lcp']) && is_array($wire['wire'][$dev]['lcp'])) ? $wire['wire'][$dev]['lcp'] : null;
            if (!$lcp || ($lcp['verdict'] ?? '') !== 'inline-data-uri') { return $html; }  // keep/none/absent => no-op
            // P-B (v7.10.700, Verified Copy Contract): once the verdict says inline, the preload
            // lanes have stood down — so every path below that CANNOT deliver the inline must
            // emit the fallback pair (bg-preload + authority) instead of silently leaving the
            // hero uncovered. Receipted live: flapped edge copies with no data-URI, no preload,
            // no authority — "resource load delay" runs at PSI 97/96. The standdown now keys on
            // the OBSERVED inline, never the verdict.
            $url = (string) ($lcp['url'] ?? '');
            $coverWithFallback = function ($h, $why) use ($url, $dev, $urlKey, $lcp, $imagePreloads) {
                $GLOBALS['wpc_lcp_hero_cover_state'] = 'fallback';
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('wire-lcp-cover-fallback', $urlKey, '', ['dev' => $dev, 'why' => $why]);
                }
                if ($url === '' || !preg_match('#^https?://#i', $url)) {
                    $GLOBALS['wpc_lcp_hero_cover_state'] = 'none'; // nothing to cover WITH — admission holds the copy
                    return $h;
                }
                if (($imagePreloads instanceof wps_ic_image_preload_set && $imagePreloads->hasOrigin(['lcp-bg', 'lcp-bg-derived']))
                    || strpos($h, 'wpc-lcp-bg-authority') !== false) {
                    return $h; // an earlier lane already covered it
                }
                $coverMedia = ($dev === 'mobile') ? '(max-width: 767.98px)' : '(min-width: 768px)';
                if ($imagePreloads instanceof wps_ic_image_preload_set) {
                    $imagePreloads->add('wpc-lcp-bg-preload700', esc_url($url), $dev, 'wire-fallback',
                        wps_ic_image_preload_set::RANK_WIRE_FALLBACK, ['kind' => wps_ic_image_preload_set::KIND_BACKGROUND]);
                }
                $authorityStyle = '';
                $lcpElement = wps_ic_atf_observation::lcpElement($dev);
                $lcpSelector = (isset($lcpElement['sel']) && is_string($lcpElement['sel'])) ? trim($lcpElement['sel']) : '';
                if ($lcpSelector !== '' && (strlen($lcpSelector) > 240
                    || !preg_match('/^[A-Za-z0-9 _\-#.\[\]="\':,>+~()]+$/', $lcpSelector))) {
                    $lcpSelector = '';
                }
                if ($lcpSelector !== '' && apply_filters('wpc_lcp_bg_authority', true)) {
                    $pinDeclaration = self::wpc_bg_pin_declaration($lcpSelector, $url, $h);
                    if ($pinDeclaration !== '') {
                        $authorityStyle = '<style id="wpc-lcp-bg-authority700">@media ' . $coverMedia . '{' . $lcpSelector
                            . '{' . $pinDeclaration . '}}</style>';
                    }
                }
                if ($authorityStyle === '') {
                    return $h;
                }
                $headClose = strripos($h, '</head>');
                return ($headClose !== false)
                    ? substr($h, 0, $headClose) . $authorityStyle . substr($h, $headClose)
                    : $h . $authorityStyle;
            };
            if (strtolower((string) ($lcp['asset_type'] ?? '')) !== 'svg') { return $coverWithFallback($html, 'not-svg'); }  // belt: SVG only
            if ($url === '' || !preg_match('#(/wp-content/uploads/[^"\'()\s<>?]+\.svg)#i', $url, $mm)) { return $coverWithFallback($html, 'no-uploads-url'); }
            $uploadsPath = $mm[1];
            $local = (defined('WP_CONTENT_DIR') ? rtrim(WP_CONTENT_DIR, '/') : '') . substr($uploadsPath, strlen('/wp-content'));
            if (!@is_readable($local)) { return $coverWithFallback($html, 'local-unreadable'); }
            $bytes = (int) @filesize($local);
            if ($bytes <= 0 || $bytes > (int) apply_filters('wpc_wire_lcp_max_bytes', 12288)) { return $coverWithFallback($html, 'size'); }  // ≤12KB belt
            $svg = (string) @file_get_contents($local);
            if ($svg === '' || stripos($svg, '<svg') === false || stripos($svg, '</script') !== false) { return $coverWithFallback($html, 'svg-invalid'); }
            $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svg);  // base64 alphabet is preg-replace-safe
            $count = 0;
            // background url(...) — host-agnostic match on the uploads path, optional query, any quotes
            $html = preg_replace('#url\(\s*([\'"]?)[^"\'()\s]*' . preg_quote($uploadsPath, '#') . '(?:\?[^"\'()\s]*)?\1\s*\)#i',
                'url(' . $dataUri . ')', $html, -1, $c1); $count += (int) $c1;
            if (strtolower((string) ($lcp['vehicle'] ?? '')) === 'img') {
                $html = preg_replace('#(\bsrc=)([\'"])[^"\']*' . preg_quote($uploadsPath, '#') . '(?:\?[^"\']*)?\2#i',
                    '$1$2' . $dataUri . '$2', $html, -1, $c2); $count += (int) $c2;
            }
            if ($count === 0) { return $coverWithFallback($html, 'not-on-page'); }  // rule lives in a deferred sheet (lean crit) — cover via fallback
            $GLOBALS['wpc_lcp_hero_cover_state'] = 'inline';
            if (($lcp['preload_action'] ?? '') === 'remove') {
                // The file is inlined: no preload of it can be used any more, ours or the page's.
                if ($imagePreloads instanceof wps_ic_image_preload_set) {
                    $imagePreloads->dropKey(wps_ic_image_preload_set::imageKey($uploadsPath), 'inlined-svg');
                }
                $html = preg_replace('#<link\b(?=[^>]*\brel=(["\'])preload\1)[^>]*' . preg_quote($uploadsPath, '#') . '[^>]*>#i', '', $html);
            }
            if (function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('wire-lcp-inlined', $urlKey, '', ['dev' => $dev, 'bytes' => $bytes, 'n' => $count]);
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }


    /**
     * Distinctive selector tokens from the page's header markup (builder element IDs and
     * data-ids) so the header CSS slice covers rules addressed by opaque IDs, not just
     * header/nav keywords.
     */
    public static function wpc_header_markup_tokens($html)
    {
        try {
            if (!is_string($html)
                || !preg_match('/<(?:header\b|div[^>]*elementor-location-header)[^>]*>.{0,20000}?(?:<\/header>|<main\b|<div[^>]*data-elementor-type="wp-page")/is', $html, $headerMatch)) {
                return [];
            }
            $region = $headerMatch[0];
            $tokens = [];
            if (preg_match_all('/elementor-element-([a-f0-9]{6,8})/i', $region, $elementorIdMatches)) {
                foreach (array_unique($elementorIdMatches[1]) as $t) {
                    $tokens[] = 'elementor-element-' . $t;
                }
            }
            if (preg_match_all('/\bid="([A-Za-z][\w-]{3,40})"/', $region, $idMatches)) {
                foreach (array_unique($idMatches[1]) as $t) {
                    $tokens[] = '#' . $t;
                }
            }
            // Block themes carry no builder hashes or ids — their header layout lives on
            // wp-block-*/layout classes.
            if (preg_match_all('/\b(wp-block-[a-z][a-z0-9-]{2,40}|is-layout-[a-z-]{2,24}|items-justified-[a-z]{2,12}|has-global-padding|wp-container-[\w-]{2,40}|is-responsive)\b/', $region, $blockClassMatches)) {
                foreach (array_unique($blockClassMatches[1]) as $t) {
                    $tokens[] = $t;
                }
            }
            return array_slice(array_values(array_unique($tokens)), 0, 40);
        } catch (\Throwable $e) {
            return [];
        }
    }
    /**
     * §8(c) (v7.10.680) — the wire manifest's font-family drop[] entries.
     *
     * The service measures a family as below the fold and asks for it after load. With one face
     * owner the demotion is a request, not a document sweep: name the family and the set decides,
     * including the .798 discipline (a family with nothing left to paint in keeps its faces
     * eager). Reads the §2-cached wire from disk, never the network.
     */
    public static function demoteWireFontFamilies(wps_ic_font_face_set $set)
    {
        try {
            if (!apply_filters('wpc_wire_font_defer', true)
                || !defined('WPS_IC_CRITICAL') || !class_exists('wps_ic_url_key')) {
                return;
            }
            $urlKey = (string) (new wps_ic_url_key())->setup('');
            if ($urlKey === '') {
                return;
            }
            $file = rtrim(WPS_IC_CRITICAL, '/') . '/' . ltrim($urlKey, '/') . '/wire.json';
            if (!@is_readable($file)) {
                return;
            }
            $wire = json_decode((string) @file_get_contents($file), true);
            if (!is_array($wire) || empty($wire['wire']) || !is_array($wire['wire'])) {
                return;
            }
            $device = (function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop';
            $node = (isset($wire['wire'][$device]) && is_array($wire['wire'][$device])) ? $wire['wire'][$device] : [];
            $drops = (isset($node['drop']) && is_array($node['drop'])) ? $node['drop'] : [];
            foreach ($drops as $drop) {
                if (!is_array($drop) || ($drop['class'] ?? '') !== 'font-family'
                    || ($drop['action'] ?? '') !== 'defer-to-post-load') {
                    continue;
                }
                $family = strtolower(trim((string) ($drop['family'] ?? '')));
                if ($family === '' || !$set->has($family)) {
                    continue;
                }
                $set->demote($family, 'wire');
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('wire-font-deferred', $urlKey, '', ['fam' => $family, 'dev' => $device]);
                }
            }
        } catch (\Throwable $e) {
        }
    }



    public static function wpc_demote_url_faces($css, &$bag, $ctx = '')
    {
        try {
            if (!is_string($css) || $css === '' || stripos($css, '@font-face') === false) {
                return $css;
            }
            if (!preg_match_all('/@font-face\s*\{[^{}]*\}/is', $css, $fm)) {
                return $css;
            }
            $live = [];
            $cand = [];
            $ranged = [];
            // v7.10.726 — family|style => [weight span, codepoint set] for inline data: faces
            // that DECLARE a unicode-range. A banked face is not free: the loader flips
            // #wpc-late-faces to media=all after load, so it is a deferred fetch. When the
            // inline face declares what it actually contains (service v3.195.0 reads the
            // woff2 cmap), containment becomes provable and the fetch is pure duplication.
            // Range-free faces contribute NOTHING here — see wpc_range_covers().
            $cvr = [];
            $recordRangedCover = function ($blk, &$sink) {
                if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $blk, $f)) {
                    return '';
                }
                $fam = strtolower(trim($f[1], " \t\"'"));
                if ($fam === '') {
                    return '';
                }
                $rs = self::wpc_face_range_set($blk);
                if (is_array($rs)) {
                    $st = preg_match('/font-style\s*:\s*(italic|oblique)/i', $blk) ? 'i' : 'n';
                    $sink[$fam . '|' . $st][] = ['sp' => self::wpc_face_weight_span($blk), 'r' => $rs];
                }
                return $fam;
            };
            // #wpc-font-subsets is a SEPARATE style tag, so a family covered by the inline subset
            // has no data: face inside this blob — without the document as context every covered
            // family would keep a url() face too, and a rangeless full face outranks the subset.
            if (is_string($ctx) && $ctx !== '' && stripos($ctx, 'data:font') !== false
                && preg_match_all('/@font-face\s*\{[^{}]*\}/is', $ctx, $cm)) {
                foreach ($cm[0] as $cblk) {
                    if (stripos($cblk, 'data:') === false) {
                        continue;
                    }
                    $cfam = $recordRangedCover($cblk, $cvr);
                    // v7.10.799 — A RANGED SUBSET IS NOT A LIVE FAMILY. $live licenses banking
                    // every url() face of the family, and it was set by ANY data: face — so one
                    // inlined SUBSET banked every other weight into the media="not all" lane.
                    // Live justmsp crit: Manrope 600 + Playfair 400, both RANGED, with Playfair
                    // 500 (the headline) and Manrope 400 (the body) among 20 banked faces. A
                    // subset cannot synthesise a weight it lacks and cannot serve a codepoint
                    // outside its range, so the page fell to the metric fallback — serif set in
                    // sans, weights and sizes wrong. Only a RANGE-FREE data: face (a whole face,
                    // covering every codepoint) makes the family live. A ranged one still feeds
                    // $cvr, where containment is tested properly before anything is dropped.
                    if ($cfam !== '' && self::wpc_face_range_set($cblk) === null) {
                        $live[$cfam] = 1;
                    }
                }
            }
            foreach ($fm[0] as $blk) {
                if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $blk, $ff)) {
                    continue;
                }
                $fam = strtolower(trim($ff[1], " \t\"'"));
                if ($fam === '') {
                    continue;
                }
                if (stripos($blk, 'url(') === false || stripos($blk, 'data:') !== false) {
                    // Same rule for a face declared in THIS blob: a ranged data: subset feeds
                    // $cvr but never marks the family live. local()/rangeless faces are whole.
                    if (stripos($blk, 'data:') !== false) {
                        $recordRangedCover($blk, $cvr);
                        if (self::wpc_face_range_set($blk) === null) {
                            $live[$fam] = 1;
                        }
                    } else {
                        $live[$fam] = 1;
                    }
                    continue;
                }
                // v7.10.603 — RANGES DO NOT SYNTHESISE. One rescued face is enough for a WEIGHT
                // gap (the browser synthesises the nearest weight within the family) but a
                // unicode-range set is COVERAGE, not redundancy: keeping one face of a
                // range-split family leaves every codepoint outside that range with no live
                // face, so Cyrillic/Greek/Latin-Ext fall through while Latin looks fine. Jost on
                // hawkeye.design ships 4 distinct ranges across 58 ranged faces.
                if (preg_match('/unicode-range\s*:/i', $blk)) {
                    $ranged[$fam] = 1;
                }
                $sc = (preg_match('/font-style\s*:\s*(italic|oblique)/i', $blk) ? 0 : 2)
                    + (preg_match('/font-weight\s*:\s*(?:400|normal)\b/i', $blk) ? 1 : 0);
                if (!isset($cand[$fam]) || $sc > $cand[$fam]['s']) {
                    $cand[$fam] = ['s' => $sc, 'k' => md5($blk)];
                }
            }
            $keep = [];
            foreach ($cand as $fam => $c) {
                if (empty($live[$fam])) {
                    $keep[$c['k']] = 1;
                }
            }
            $out = preg_replace_callback('/@font-face\s*\{[^{}]*\}/is', function ($m) use (&$bag, $keep, $live, $ranged, $cvr) {
                if (stripos($m[0], 'url(') === false || stripos($m[0], 'data:') !== false) {
                    return $m[0];
                }
                $fam = preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $m[0], $ff)
                    ? trim($ff[1], " \t\"'")
                    : '';
                $flc = strtolower($fam);
                // Subsets are measured from page TEXT, so they never carry the private-use
                // codepoints an icon family needs; icon-subsets.css is optional and was never
                // part of the gate. Recoverable via wpc_icon_faces_live for the interaction path.
                if ($fam === ''
                    || (function_exists('wpc_css_is_icon_font')
                        && wpc_css_is_icon_font($fam)
                        && apply_filters('wpc_icon_faces_live', true))) {
                    return $m[0];
                }
                // v7.10.726 — DROP rather than bank, but ONLY on proof. Both sides must declare
                // a real codepoint set; the inline face's set must CONTAIN this face's, and its
                // weight span must contain this face's span. Undeclared on either side proves
                // nothing (t601: applicability is not coverage) and falls through, which is the
                // pre-.726 behaviour. Under-declaring costs bytes; over-declaring costs permanent
                // tofu — so every unproven path keeps or banks.
                // v7.10.799 — this test now runs BEFORE the single-face rescue below. A ranged
                // cover no longer marks its family live, so without this ordering the rescue
                // would pre-empt a drop the containment test had already proven (t726 D1).
                $familyStyleKey = $flc . '|' . (preg_match('/font-style\s*:\s*(italic|oblique)/i', $m[0]) ? 'i' : 'n');
                if ($flc !== '' && !empty($cvr[$familyStyleKey]) && apply_filters('wpc_drop_covered_faces', true)) {
                    $faceRangeSet = self::wpc_face_range_set($m[0]);
                    $faceWeightSpan = self::wpc_face_weight_span($m[0]);
                    foreach ($cvr[$familyStyleKey] as $inlineCover) {
                        if ($inlineCover['sp'][0] <= $faceWeightSpan[0] && $inlineCover['sp'][1] >= $faceWeightSpan[1]
                            && self::wpc_range_covers($inlineCover['r'], $faceRangeSet)) {
                            return '';
                        }
                    }
                }
                if (isset($keep[md5($m[0])])) {
                    return $m[0];
                }
                // Uncovered AND range-split: every face is coverage, so keep the whole set.
                if ($flc !== '' && !empty($ranged[$flc]) && empty($live[$flc])) {
                    return $m[0];
                }
                $bag .= $m[0];
                return '';
            }, $css);
            return is_string($out) ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }

    /**
     * Demotion is licensed by a covering subset being in THIS DOCUMENT. Whether the artifact
     * exists on disk is a different question: 'sticky' is a preload-standdown sentinel holding
     * no faces at all, and the inline emission has its own filter plus a one-per-request static.
     */
    // v7.10.798 — the subsets lane counts as coverage only when the element actually carries a
    // face with font bytes in it. Element present + zero faces is a vehicle, not a delivery.
    // THE DECLARED FONTS OWNER (crit-team #1, enum frozen in crit-push v3.198.132): one field
    // decides which side delivers font bytes; the plugin reads and obeys. Per DEVICE (their
    // staging receipt: mobile embedded 2 faces, desktop past-cap embedded none — a site-level
    // owner mis-states one lane on every combined page). Source is the wire manifest's
    // fonts_owner{} block ({mobile:{owner,complete,reason,delivered[]}, desktop:{...}} or one
    // flat block). Branch on owner ALONE — reason/delivered/bytes are diagnostic.
    public static function wpc_fonts_owner($device = '')
    {
        static $declaredOwners = null;
        if ($declaredOwners === null) {
            $declaredOwners = [];
            try {
                if (defined('WPS_IC_CRITICAL') && class_exists('wps_ic_url_key')) {
                    $urlKey = ltrim((string) (new wps_ic_url_key())->setup(), '/');
                    $wireFile = $urlKey !== '' ? rtrim(WPS_IC_CRITICAL, '/') . '/' . $urlKey . '/wire.json' : '';
                    if ($wireFile !== '' && @is_readable($wireFile)) {
                        $wire = json_decode((string) @file_get_contents($wireFile), true);
                        if (is_array($wire) && !empty($wire['fonts_owner']) && is_array($wire['fonts_owner'])) {
                            $declaredOwners = $wire['fonts_owner'];
                        }
                    }
                }
            } catch (\Throwable $e) {
                $declaredOwners = [];
            }
        }
        if ($device !== 'mobile' && $device !== 'desktop') {
            $device = (function_exists('wpc_ua_is_mobile') && wpc_ua_is_mobile()) ? 'mobile' : 'desktop';
        }
        $row = (isset($declaredOwners[$device]) && is_array($declaredOwners[$device])) ? $declaredOwners[$device]
            : (isset($declaredOwners['owner']) ? $declaredOwners : []);
        $own = (isset($row['owner']) && is_string($row['owner'])) ? $row['owner'] : '';
        if (!in_array($own, ['crit-inline', 'plugin-subsets', 'site'], true)) {
            $own = '';
        }
        return [
            'owner'    => $own,
            'complete' => !empty($row['complete']),
            'reason'   => isset($row['reason']) ? (string) $row['reason'] : '',
        ];
    }

    // true = our font lanes yield (crit-inline complete, or site, for EVERY asked device);
    // false = we deliver. A device with no declared owner means we deliver: a generation older
    // than crit-push v3.198.132 carries no fonts_owner, and the plugin does not guess from
    // the crit's bytes. Such a page gets the plugin's subsets beside whatever faces its old crit
    // embedded (the face set keeps one face per tuple), so it paints the site's typeface at the
    // cost of some duplicate font bytes until its next generation declares an owner.
    public static function wpc_fonts_lane_should_yield($devices)
    {
        try {
            $asked = 0;
            foreach ((array) $devices as $d) {
                $o = self::wpc_fonts_owner((string) $d);
                if ($o['owner'] === '' || $o['owner'] === 'plugin-subsets'
                    || ($o['owner'] === 'crit-inline' && empty($o['complete']))) {
                    return false;
                }
                $asked++;
            }

            return $asked > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    // v7.21.150 — crit-team #6: the crit-side stand-down gates counted a MARKER, not a
    // delivery ("both lanes stood down on a presence marker -> nobody delivered anything",
    // their v3.154.0 always-true gate class). Same .798 law, other side of the seam: our
    // subset lane may yield only to n>0 actually-embedded data-URI faces in the crit —
    // a wpc-fonts-embedded marker with zero faces is a vehicle, not a delivery.
    public static function wpc_crit_embedded_face_count($crit)
    {
        if (!is_string($crit) || $crit === '' || stripos($crit, 'data:font') === false) {
            return 0;
        }
        $n = preg_match_all('/@font-face\s*\{[^}]*?src\s*:[^}]*?data:font[^}]*\}/is', $crit, $m);
        return is_int($n) ? $n : 0;
    }

    public static function wpc_subsets_carry_faces($doc)
    {
        if (!is_string($doc) || $doc === '' || strpos($doc, 'id="wpc-font-subsets"') === false) {
            return false;
        }
        if (!preg_match('/<style\b[^>]*\bid="wpc-font-subsets"[^>]*>(.*?)<\/style>/is', $doc, $m)) {
            return false;
        }
        return stripos($m[1], 'data:font') !== false && stripos($m[1], '@font-face') !== false;
    }

    public static function wpc_faces_covered($output, $html, $crit, wps_ic_font_face_set $fontFaces)
    {
        // v7.10.798 — PRESENCE IS NOT SERVICE. This arm read the subsets ELEMENT, so an empty or
        // face-less #wpc-font-subsets licensed the demotion on its own existence. The per-family
        // pass downstream keeps a face for every uncovered family, so it was never destructive —
        // but a vehicle is not a delivery, and this is the same conflation twice in one lane.
        if (self::wpc_subsets_carry_faces((string) $output) || self::wpc_subsets_carry_faces((string) $html)) {
            return true;
        }
        // The ATF subsets are registered with the render's face owner now, not written as a
        // block of their own, so the owner's CSS is where the delivery can be seen. Same test,
        // same bytes: a face carrying a data: src is coverage wherever it is being held.
        if (self::wpc_crit_embedded_face_count($fontFaces->css()) > 0) {
            return true;
        }

        return self::wpc_crit_embedded_face_count((string) $crit) > 0;
    }




    public static function wpc_atf_reveal_css($dir)
    {
        $jf = rtrim((string) $dir, '/') . '/lcp.json';
        if (!@is_readable($jf)) {
            return '';
        }
        $cf = rtrim((string) $dir, '/') . '/atf_reveal.css';
        $cachedRevealCss = @is_readable($cf) ? (string) @file_get_contents($cf) : '';
        if ($cachedRevealCss !== '' && (int) @filemtime($cf) >= (int) @filemtime($jf)
            && strpos($cachedRevealCss, '/*wpc-ar713*/') !== false) {
            return $cachedRevealCss === '/*wpc-ar713*/' ? '' : $cachedRevealCss;
        }
        $css = '/*wpc-ar713*/';
        foreach (['mobile' => '@media (max-width: 767.98px){', 'desktop' => '@media (min-width: 768px){'] as $wpc_dev => $wpc_wrap) {
            // The owner answers the validated reveal set, or the LCP element alone when the
            // service observed no reveal items for this device (see revealRules()).
            $rules = '';
            foreach (wps_ic_atf_observation::revealRules($wpc_dev) as $revealRule) {
                $body = '';
                foreach ($revealRule['props'] as $property => $value) {
                    $body .= $property . ':' . $value . ' !important;';
                }
                $rules .= $revealRule['sel'] . '{' . $body . '}';
            }
            if ($rules !== '') {
                $css .= $wpc_wrap . $rules . '}';
            }
        }
        if (strlen($css) > 8192) {
            $css = '/*wpc-ar713*/';
        }
        wpc_fs_put($cf, $css);
        return $css === '/*wpc-ar713*/' ? '' : $css;
    }

    public static function wpc_strip_covered_fullface($crit)
    {
        try {
            if (!is_string($crit) || strpos($crit, '@font-face') === false || strpos($crit, 'base64') === false) {
                return $crit;
            }
            // v7.10.504 — COVERAGE IS PER FACE, NOT PER FAMILY. This keyed $covered on the family
            // name alone, so ONE base64 subset (Circular Std 400) marked the whole family covered and
            // step 2 then deleted every url() face for it — including the real 500 and 300 the subset
            // never provided. Receipted on wpcompress.com: h3.elementor-icon-box-title computes
            // font-weight:500, the crit's own CSS requests 300/400/500/600, and only 400/600 were
            // inlined; the faces that would have covered the gap were removed by us. A partial ATF
            // subset is survivable; a family-wide strip on top of it is what makes it fatal.
            $faceKeyOf = function ($bl) {
                // Absent font-weight means 400 per spec; normalise keywords and ranges.
                $w = '400';
                if (preg_match('/font-weight\s*:\s*([^;}]+)/i', $bl, $wm)) {
                    $w = strtolower(trim($wm[1]));
                }
                $w = str_replace(['normal', 'bold'], ['400', '700'], $w);
                $parts = preg_split('/\s+/', trim($w));
                $lo = isset($parts[0]) ? (int) $parts[0] : 400;
                $hi = isset($parts[1]) ? (int) $parts[1] : $lo;
                if ($lo < 1 || $lo > 1000) { $lo = 400; }
                if ($hi < $lo || $hi > 1000) { $hi = $lo; }
                $st = 'normal';
                if (preg_match('/font-style\s*:\s*([a-z]+)/i', $bl, $sm)) {
                    $st = strtolower(trim($sm[1]));
                }
                $fam = '';
                if (preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $bl, $fm)) {
                    $fam = strtolower(trim($fm[1]));
                }
                return [$fam, $lo, $hi, $st];
            };

            // 1) FACES (family + weight + style) that have an inline base64 subset (ATF-covered)
            $covered = [];
            if (preg_match_all('/@font-face\s*\{[^}]*\}/is', $crit, $blocks)) {
                foreach ($blocks[0] as $bl) {
                    if (stripos($bl, 'base64') === false) { continue; }
                    // v7.10.509 — a RANGE-LIMITED subset SUPPLEMENTS the full face, it does not replace
                    // it. The ATF subsets carry unicode-range covering only above-the-fold glyphs, so
                    // stripping the full face on their authority loses every glyph outside that range.
                    // Receipt: h3.elementor-icon-box-title rendered "Arial x27 + Circular Std Medium x1"
                    // — exactly one glyph inside the subset's range, 27 falling through to the fallback.
                    // Only a FULL-COVERAGE subset (no unicode-range) may retire its url() twin.
                    if (preg_match('/unicode-range\s*:/i', $bl)) { continue; }
                    list($fam, $lo, $hi, $st) = $faceKeyOf($bl);
                    if ($fam === '') { continue; }
                    // A range (font-weight: 400 600) covers every 100-step inside it.
                    for ($w = $lo; $w <= $hi; $w += 100) {
                        $covered[$fam . '|' . $w . '|' . $st] = 1;
                    }
                }
            }
            if (empty($covered)) { return $crit; }
            // 2) strip url()-src faces for covered families; keep everything else verbatim
            $coveredStripped = 0;
            $stripped = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($covered, $faceKeyOf, &$coveredStripped) {
                if (stripos($m[0], 'base64') !== false) { return $m[0]; }
                if (!preg_match('/src\s*:\s*[^;}]*url\(\s*["\']?(?!data:)/i', $m[0])) { return $m[0]; }
                list($fam, $lo, $hi, $st) = $faceKeyOf($m[0]);
                if ($fam === '') { return $m[0]; }
                // Strip ONLY when every weight this face serves is genuinely covered by a subset.
                for ($w = $lo; $w <= $hi; $w += 100) {
                    if (empty($covered[$fam . '|' . $w . '|' . $st])) {
                        return $m[0];
                    }
                }
                $coveredStripped++;
                return '';
            }, $crit);
            // A url() face that a full-coverage base64 subset already serves is dropped, because
            // the face set ranks the two equally and the later writer would win. Sampled: the
            // page's crit is the same on every render.
            if ($coveredStripped > 0 && is_string($stripped) && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('crit-fullface-covered', ['dropped' => $coveredStripped], true);
            }
            return $stripped;
        } catch (\Throwable $e) {
            return $crit;
        }
    }

    // v7.21.148 — "Fonts, settled" §2A: emit the WHOLE fallback stack, not its head. Under
    // font-display:swap a missing local() is a cosmetic delay; under optional the fallback IS
    // the page for that visit — a machine with no Arial (stock Android) read every fallback
    // face as status:unloaded. Row's fallback_stack (whitelisted at harvest) wins; a bare
    // 'local' name stays the single-entry behavior.
    public static function wpc_lcp_heal_fetch($d, $mode = 'inline')
    {
        try {
            list($wpc_heal_url, $wpc_lcp_file, $wpc_heal_dir, $wpc_heal_nkey, $wpc_heal_lock) = $d;
            if ($mode !== 'inline' && get_transient($wpc_heal_lock)) {
                return false;
            }
            set_transient($wpc_heal_nkey, (int) get_transient($wpc_heal_nkey) + 1, HOUR_IN_SECONDS);
            set_transient($wpc_heal_lock, 1, MINUTE_IN_SECONDS);
            $wpc_heal_ua = defined('WPS_IC_API_USERAGENT') ? WPS_IC_API_USERAGENT : 'WPCompress';
            $wpc_hr = wp_remote_get($wpc_heal_url, ['timeout' => 3, 'headers' => ['user-agent' => $wpc_heal_ua]]);
            $wpc_h_status = is_wp_error($wpc_hr) ? 0 : (int) wp_remote_retrieve_response_code($wpc_hr);
            $wpc_h_wrote  = false;
            if ($wpc_h_status === 200) {
                $wpc_hb = wp_remote_retrieve_body($wpc_hr);
                if (is_string($wpc_hb) && $wpc_hb !== '' && json_decode($wpc_hb) !== null) {
                    $wpc_h_wrote = (bool) wpc_fs_put($wpc_lcp_file, $wpc_hb);
                    if ($wpc_h_wrote && class_exists('wps_ic_cache_integrations')) {
                        $wpc_heal_pageurl = (is_ssl() ? 'https://' : 'http://')
                            . (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '')
                            . strtok((string) (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/'), '?');
                        $wpc_heal_key = class_exists('wps_ic_url_key')
                            ? (new wps_ic_url_key())->setup($wpc_heal_pageurl)
                            : basename(rtrim($wpc_heal_dir, '/'));
                        if ($wpc_heal_key !== '') {
                            wps_ic_cache_integrations::purgeCacheFiles($wpc_heal_key);
                            wpc_purge_foreign_caches($wpc_heal_key, 'self-heal');
                        }
                    }
                }
            }
            wpc_fs_put($wpc_heal_dir . 'lcp_heal.json', wp_json_encode([
                'at' => gmdate('c'), 'http_status' => $wpc_h_status, 'wrote' => $wpc_h_wrote, 'mode' => $mode,
            ]));
            return $wpc_h_wrote;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function wpc_fallback_local_src($row, $lf)
    {
        $stack = [];
        if (is_array($row) && !empty($row['fallback_stack']) && is_array($row['fallback_stack'])) {
            foreach ($row['fallback_stack'] as $n) {
                if (is_string($n) && preg_match('/^[A-Za-z0-9 -]{2,32}$/', trim($n))) {
                    $stack[] = trim($n);
                }
                if (count($stack) >= 5) {
                    break;
                }
            }
        }
        if (empty($stack)) {
            $stack = [(string) $lf];
        }
        $src = [];
        foreach ($stack as $n) {
            $src[] = 'local("' . $n . '")';
        }
        return implode(',', $src);
    }

    public static function wpc_font_metric_overrides(&$critBlob, $pageHtml = '')
    {
        if (!is_string($critBlob) || $critBlob === '' || stripos($critBlob, 'font-family') === false) {
            return '';
        }
        $table = apply_filters('wpc_font_fallback_metrics', []);
        if (!is_array($table)) {
            $table = [];
        }


        $genericFamilies = ['sans-serif' => 1, 'serif' => 1, 'monospace' => 1, 'cursive' => 1, 'fantasy' => 1,
            'system-ui' => 1, '-apple-system' => 1, 'blinkmacsystemfont' => 1, 'inherit' => 1, 'initial' => 1,
            'unset' => 1, 'arial' => 1, 'helvetica' => 1, 'helvetica neue' => 1, 'georgia' => 1, 'times' => 1,
            'times new roman' => 1, 'courier' => 1, 'courier new' => 1, 'verdana' => 1, 'tahoma' => 1, 'segoe ui' => 1];
        $cands = [];
        if (preg_match_all('/font-family\s*:\s*([^;}{]+)/i', $critBlob, $familyMatches)) {
            foreach ($familyMatches[1] as $familyValue) {
                $tok = trim(trim(explode(',', trim($familyValue))[0]), " \t\"'");
                if ($tok === '' || stripos($tok, 'var(') !== false) { continue; }
                $lc = strtolower($tok);
                if (isset($genericFamilies[$lc]) || substr($lc, -9) === ' fallback') { continue; }
                if (!isset($cands[$lc])) { $cands[$lc] = $tok; }
            }
        }
        if (empty($cands)) {
            return '';
        }
        $tlc = [];
        foreach ($table as $tk => $tv) {
            if (is_string($tk) && $tk !== '' && is_array($tv)) { $tlc[strtolower($tk)] = $tv; }
        }


        static $emittedFaces = [];
        $faces = '';
        // v7.21.58 — A FALLBACK FACE FOR A FONT THAT NEVER ARRIVES IS A RENDERING CHANGE, NOT
        // A HOLD. columbuschiropractors: the nav declares font-family:Poppins but NO Poppins
        // @font-face exists anywhere on the page — plugin-off renders plain sans-serif, while
        // our "Poppins Fallback" (Arial size-adjusted to Poppins geometry) rendered WIDER text
        // permanently and wrapped the GET STARTED nav item. A metric fallback exists to hold
        // space for a REAL incoming face; without evidence the family actually loads (an
        // @font-face block, a fonts-css link naming the family, or a font file bearing its
        // slug), skip the face AND the stack splice so rendering equals plugin-off.
        $faceEvidence = $critBlob . (string) $pageHtml;
        foreach ($cands as $lc => $fam) {
            if (apply_filters('wpc_fallback_requires_face', true)) {
                $quotedFamily = preg_quote($fam, '/');
                $familySlug = preg_quote(str_replace(' ', '-', strtolower($fam)), '/');
                $familyPlus = preg_quote(str_replace(' ', '+', $fam), '/');
                if (!preg_match('/@font-face\s*\{[^}]*font-family\s*:\s*["\']?\s*' . $quotedFamily . '\b/is', $faceEvidence)
                    && !preg_match('/href=["\'][^"\']*family=[^"\']*(?:' . $familyPlus . '|' . str_replace(' ', '%20', $quotedFamily) . ')/i', $faceEvidence)
                    && !preg_match('/[\/-]' . $familySlug . '[^"\'()\s]*\.(?:woff2?|ttf|otf)/i', $faceEvidence)) {
                    continue;
                }
            }
            $m = isset($tlc[$lc]) ? $tlc[$lc]
                : (function_exists('wpc_font_catalog_metrics') ? wpc_font_catalog_metrics($lc) : null);
            $fb = $fam . ' Fallback';
            $decl = '';
            if (is_array($m)) {
                foreach (['size-adjust', 'ascent-override', 'descent-override', 'line-gap-override'] as $k) {
                    if (!empty($m[$k]) && preg_match('/^[0-9.]+%$/', (string) $m[$k])) { $decl .= $k . ':' . $m[$k] . ';'; }
                }
            }

            // §3 per-weight rows ('family|weight|style'): SAME face name + font-weight/
            // font-style descriptors — the browser matches fallback faces by descriptor,
            // the stack splice stays family-level. The descriptor-less family face stays
            // as the catch-all so metric-less weights keep today's behavior. Render path:
            // string-build only, hard face cap.
            $weightFaces = '';
            foreach ($tlc as $weightKey => $weightMetrics) {
                if (count($emittedFaces) >= 24) { break; }
                if (!is_array($weightMetrics) || strpos((string) $weightKey, $lc . '|') !== 0) { continue; }
                if (isset($emittedFaces[$weightKey])) { continue; }
                $weightKeyParts = explode('|', (string) $weightKey);
                $faceWeight = isset($weightKeyParts[1]) ? trim((string) $weightKeyParts[1]) : '';
                $faceStyle = (isset($weightKeyParts[2]) && strtolower((string) $weightKeyParts[2]) === 'italic') ? 'italic' : 'normal';
                if ($faceWeight === '' || !preg_match('/^\d{1,4}( \d{1,4})?$/', $faceWeight)) { continue; }
                $weightDecl = '';
                foreach (['size-adjust', 'ascent-override', 'descent-override', 'line-gap-override'] as $k2) {
                    if (!empty($weightMetrics[$k2]) && preg_match('/^[0-9.]+%$/', (string) $weightMetrics[$k2])) { $weightDecl .= $k2 . ':' . $weightMetrics[$k2] . ';'; }
                }
                if ($weightDecl === '') { continue; }
                // v7.10.802 — inherit the FAMILY's measured local before falling back to Arial.
                // Per-weight rows routinely carry metrics and no 'local', and a hardcoded default
                // here shadows a serif with a sans for exactly the weights a page uses: justmsp's
                // H1 matched the weight-400/500 faces and painted Arial until the real Playfair
                // landed, while the descriptor-less face correctly said Times New Roman. Every twin
                // face for a family must shadow the same real font.
                $weightLocalFont = (isset($weightMetrics['local']) && is_string($weightMetrics['local']) && preg_match('/^[A-Za-z ]{3,32}$/', $weightMetrics['local']))
                    ? $weightMetrics['local']
                    : ((is_array($m) && isset($m['local']) && is_string($m['local']) && preg_match('/^[A-Za-z ]{3,32}$/', $m['local']))
                        ? $m['local'] : 'Arial');
                $emittedFaces[$weightKey] = 1;
                $weightLocalSrc = self::wpc_fallback_local_src(is_array($weightMetrics) && !empty($weightMetrics['fallback_stack']) ? $weightMetrics : $m, $weightLocalFont);
                $weightFaces .= '@font-face{font-family:"' . $fb . '";src:' . $weightLocalSrc . ';font-weight:' . $faceWeight . ';font-style:' . $faceStyle . ';' . $weightDecl . '}';
            }

            if ($decl === '' && $weightFaces === '') { continue; }

            // (matching a serif's box to Arial geometry is the wrong frame). Whitelisted names only;
            // absent/invalid → Arial (census entries carry no 'local').
            if ($decl !== '') {
                $wpc_lf = (isset($m['local']) && is_string($m['local']) && preg_match('/^[A-Za-z ]{3,32}$/', $m['local']))
                    ? $m['local'] : 'Arial';
                if (!isset($emittedFaces[$lc])) {
                    $emittedFaces[$lc] = 1;
                    $faces .= '@font-face{font-family:"' . $fb . '";src:' . self::wpc_fallback_local_src($m, $wpc_lf) . ';' . $decl . '}';
                }
            }
            $faces .= $weightFaces;


            $chainedBlob = preg_replace(
                '/font-family\s*:\s*([\'"]?)' . preg_quote($fam, '/') . '\1\s*,/i',
                'font-family:$1' . $fam . '$1,"' . $fb . '",',
                $critBlob
            );
            if (is_string($chainedBlob)) {
                $critBlob = $chainedBlob;
            }
            // Single-family declarations (no chain) get the fallback appended too —
            // with @font-face blocks masked: their font-family descriptor takes one name.
            $maskedFaces = [];
            $singleFamilyBlob = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($mm) use (&$maskedFaces) {
                $maskedFaces[] = $mm[0];
                return "\x02WPCFF" . (count($maskedFaces) - 1) . "\x02";
            }, $critBlob);
            if (is_string($singleFamilyBlob)) {
                $singleFamilyBlob = preg_replace(
                    '/font-family\s*:\s*([\'"]?)' . preg_quote($fam, '/') . '\1\s*([;}!])/i',
                    'font-family:$1' . $fam . '$1,"' . $fb . '"$2',
                    $singleFamilyBlob
                );
                if (is_string($singleFamilyBlob)) {
                    $singleFamilyBlob = preg_replace_callback('/\x02WPCFF(\d+)\x02/', function ($mm) use ($maskedFaces) {
                        return $maskedFaces[(int) $mm[1]];
                    }, $singleFamilyBlob);
                }
                if (is_string($singleFamilyBlob) && strpos($singleFamilyBlob, "\x02WPCFF") === false) {
                    $critBlob = $singleFamilyBlob;
                }
            }
        }
        if ($faces !== '' && function_exists('wpc_unify_fallback_face_locals')) {
            $faces = wpc_unify_fallback_face_locals($faces);
        }
        try {
            $weightGaps = self::wpc_font_weight_gaps($faceEvidence, $tlc);
            if (!empty($weightGaps) && function_exists('wpc_cache_first_log') && function_exists('get_transient')) {
                $weightGapLogKey = 'wpc_fmwg146_' . substr(md5(implode(';', $weightGaps)), 0, 12);
                if (!get_transient($weightGapLogKey)) {
                    set_transient($weightGapLogKey, 1, 86400);
                    wpc_cache_first_log('font-metrics-weight-gap', implode(';', $weightGaps), '', []);
                }
            }
        } catch (\Throwable $e) {
        }
        if ($faces !== '') {
            $faces = (string) self::wpc_strip_family_faces($faces, self::wpc_unbacked_font_families($pageHtml, ''), 'metric_fallbacks');
        }
        return $faces !== '' ? '<style id="wpc-font-fallbacks">' . $faces . '</style>' : '';
    }



    public static function wpc_font_weight_gaps($evidence, $tableLc)
    {
        $out = [];
        try {
            if (!is_string($evidence) || $evidence === '' || !is_array($tableLc) || empty($tableLc)) {
                return $out;
            }
            $rows = [];
            foreach ($tableLc as $tk => $tv) {
                if (!is_string($tk) || strpos($tk, '|') === false || !is_array($tv)) {
                    continue;
                }
                $tp  = explode('|', strtolower($tk));
                $tf  = trim((string) $tp[0]);
                $tw  = trim((string) (isset($tp[1]) ? $tp[1] : ''));
                if ($tf === '' || !preg_match('/^\d{1,4}( \d{1,4})?$/', $tw)) {
                    continue;
                }
                $twp = explode(' ', $tw);
                $lo  = (int) $twp[0];
                $hi  = isset($twp[1]) ? (int) $twp[1] : $lo;
                for ($w = $lo; $w <= $hi; $w += 100) {
                    $rows[$tf][$w] = 1;
                }
            }
            if (empty($rows)) {
                return $out;
            }
            $decl = [];
            if (preg_match_all('/@font-face\s*\{[^}]*\}/is', $evidence, $bm)) {
                foreach ($bm[0] as $blk) {
                    if (!preg_match('/font-family\s*:\s*["\']?([^"\';}]+)/i', $blk, $bf)) {
                        continue;
                    }
                    $bfam = strtolower(trim($bf[1]));
                    if ($bfam === '' || substr($bfam, -9) === ' fallback' || !isset($rows[$bfam])) {
                        continue;
                    }
                    $bw = '400';
                    if (preg_match('/font-weight\s*:\s*(normal|bold|\d{1,4}(?:\s+\d{1,4})?)/i', $blk, $bwm)) {
                        $bw = strtolower(trim($bwm[1]));
                        $bw = ($bw === 'normal') ? '400' : (($bw === 'bold') ? '700' : preg_replace('/\s+/', ' ', $bw));
                    }
                    $bwp = explode(' ', $bw);
                    $blo = (int) $bwp[0];
                    $bhi = isset($bwp[1]) ? (int) $bwp[1] : $blo;
                    for ($w = $blo; $w <= $bhi; $w += 100) {
                        $decl[$bfam][$w] = 1;
                    }
                }
            }
            foreach ($decl as $bfam => $ws) {
                $miss = array_keys(array_diff_key($ws, $rows[$bfam]));
                if (!empty($miss)) {
                    sort($miss);
                    $out[] = $bfam . ':' . implode(',', $miss);
                }
            }
            sort($out);
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }

    /**
     * Source basename for a sheet our own CSS cache renamed.
     * cdn-rewrite writes `{handle}-{10hex}.css` into wp-cio/css, so the served basename no longer
     * matches the source basename the used-css manifest keys on (`divi-style-ba3d881e1d.css` vs
     * `style-static.min.css`). An ABSORBED sheet therefore read as UNLISTED and kept loading — its
     * base shorthand then out-ordered the used-css module rules (the lost pill border) and it stayed
     * on the wire as render-blocking unused CSS. Recover the handle (link id, else the filename
     * pattern) and ask WP's own registry for the original src. No map, no option writes.
     */
    public static function wpc_used_css_source_basename($tag, $href)
    {
        $handle = '';
        if (is_string($tag) && preg_match('/\bid=(["\'])([^"\']+?)-css\1/i', $tag, $m)) {
            $handle = $m[2];
        }
        if ($handle === '') {
            $bn = (string) strtok(basename((string) parse_url((string) $href, PHP_URL_PATH)), '?');
            if (preg_match('/^(.+)-[a-f0-9]{10}\.css$/i', $bn, $m2)) { $handle = $m2[1]; }
        }
        if ($handle === '' || !function_exists('wp_styles')) { return ''; }
        $st = wp_styles();
        if (!is_object($st) || empty($st->registered[$handle]) || empty($st->registered[$handle]->src)) { return ''; }
        $src = (string) $st->registered[$handle]->src;
        if ($src === '') { return ''; }
        return strtolower((string) strtok(basename((string) parse_url($src, PHP_URL_PATH)), '?'));
    }

    /**
     * The measured hero's own responsive set, read off the <img> already in this buffer.
     * A `preload as=image` without imagesrcset/imagesizes does NOT match a responsive <img>,
     * so the browser fetches the bare href (full-size) at High while the <img> loads the rung
     * its srcset actually picks — the full-size bytes are pure waste on the critical path.
     * Returning the img's own srcset/sizes makes the preload resolve to the SAME candidate.
     * $want: 'srcset' | 'sizes'. Empty string when the hero is not responsive (then the
     * url_is_authoritative path keeps the verbatim preload, which is correct for that case).
     */
    public static function wpc_lcp_img_responsive($html, $url, $want = 'srcset')
    {
        if (!is_string($html) || $html === '' || !is_string($url) || $url === '') { return ''; }
        $file = basename((string) preg_replace('/\?.*$/', '', $url));
        if ($file === '') { return ''; }
        // Match any rung of the same image: drop the -WxH suffix and the extension.
        $stem = (string) preg_replace('/\.[a-z0-9]+$/i', '', $file);
        $stem = (string) preg_replace('/-\d+x\d+$/', '', $stem);
        if (strlen($stem) < 3) { return ''; }
        // Filename-boundary match (any rung of the SAME file) — a bare substring test would let a
        // short stem pull an unrelated image's srcset and preload the wrong resource.
        $wpc_re = '#/' . preg_quote($stem, '#') . '(?:-\d+x\d+)?\.[a-z0-9]+#i';
        if (!preg_match_all('#<img\b[^>]*>#i', $html, $wpc_m)) { return ''; }
        foreach ($wpc_m[0] as $tag) {
            if (!preg_match($wpc_re, $tag)) { continue; }
            if (!preg_match('#\bsrcset=(["\'])(.*?)\1#is', $tag, $ss)) { continue; }
            $srcset = trim((string) $ss[2]);
            if ($srcset === '' || strpos($srcset, 'w') === false) { continue; }
            if ($want === 'sizes') {
                return preg_match('#\bsizes=(["\'])(.*?)\1#is', $tag, $sz) ? trim((string) $sz[2]) : '';
            }
            return $srcset;
        }
        return '';
    }

    // v7.21.101 — AN AUTHORITY PIN MUST PRESERVE THE ELEMENT'S OTHER BACKGROUND LAYERS.
    // Divi/builder sections compose background-image as `linear-gradient(overlay), url(img)`;
    // pinning `background-image:url(img) !important` deletes the overlay (falknerei header:
    // dark overlay gone, customer escalation). When the document carries a layered
    // background-image for the pinned selector, our rung URL is substituted INTO that stack;
    // a single-url rule keeps today's pin; an invisible stack on a builder-section selector
    // stands down (preload alone still warms the rung). Returns the full declaration or ''.
    protected static function wpc_bg_pin_declaration($selector, $imageUrl, $cssHaystack)
    {
        try {
            $defaultDeclaration = 'background-image:url("' . esc_url($imageUrl) . '") !important';
            if (!is_string($selector) || !is_string($cssHaystack) || $cssHaystack === '') {
                return $defaultDeclaration;
            }
            $selectorToken = '';
            if (preg_match('/#([A-Za-z_][\w-]*)/', $selector, $idMatch)) {
                $selectorToken = '#' . $idMatch[1];
            } elseif (preg_match_all('/\.([A-Za-z_][\w-]*)/', $selector, $classMatches) && !empty($classMatches[1])) {
                $selectorToken = '.' . end($classMatches[1]);
            }
            if ($selectorToken === '') {
                return $defaultDeclaration;
            }
            $sawBackgroundImage = false;
            $gradientLayer = '';
            $gradientShorthand = false;
            $scanOffset = 0;
            // v7.23.25 — ONE LINEAR PASS, NOT A BACKTRACKING SCAN. With a background-image LCP and a
            // unique selector, this ran over $output . $html (the whole document) with
            // /[^{}]*\.tok(?![\w-])[^{}]*\{([^{}]*)\}/. The pattern has no anchor: from every offset
            // in a brace-free stretch of markup the leading [^{}]* ran to the stretch end and
            // backtracked looking for ".tok", which markup (class="tok", no dot) never contains —
            // quadratic in the stretch length and repeated after every match, so a large page spent
            // the whole request here and the server killed it (503). Same matches, found by strpos:
            // an occurrence of the token (not followed by [\w-]) counts when the next brace after it
            // is "{" and the brace after that is "}" (the rule body); the scan resumes past it.
            $haystackLength = strlen($cssHaystack);
            $tokenLength = strlen($selectorToken);
            while (($tokenPos = strpos($cssHaystack, $selectorToken, $scanOffset)) !== false) {
                $tokenEnd = $tokenPos + $tokenLength;
                if ($tokenEnd < $haystackLength && preg_match('/[\w-]/', $cssHaystack[$tokenEnd])) {
                    $scanOffset = $tokenPos + 1;
                    continue;
                }
                $openBrace = $tokenEnd + strcspn($cssHaystack, '{}', $tokenEnd);
                if ($openBrace >= $haystackLength) {
                    break;
                }
                if ($cssHaystack[$openBrace] !== '{') {
                    $scanOffset = $openBrace + 1;
                    continue;
                }
                $closeBrace = $openBrace + 1 + strcspn($cssHaystack, '{}', $openBrace + 1);
                if ($closeBrace >= $haystackLength) {
                    break;
                }
                if ($cssHaystack[$closeBrace] !== '}') {
                    $scanOffset = $openBrace + 1;
                    continue;
                }
                $scanOffset = $closeBrace + 1;
                $ruleBody = (string) substr($cssHaystack, $openBrace + 1, $closeBrace - $openBrace - 1);
                if (preg_match_all('/background-image\s*:\s*([^;}]*)/i', $ruleBody, $backgroundImageMatches)) {
                    foreach ($backgroundImageMatches[1] as $backgroundImageValue) {
                        $sawBackgroundImage = true;
                        if (stripos($backgroundImageValue, 'gradient(') !== false && stripos($backgroundImageValue, 'url(') !== false) {
                            $gradientLayer = trim($backgroundImageValue);
                        }
                    }
                } elseif (preg_match('/background\s*:\s*[^;}]*gradient\([^;}]*/i', $ruleBody)) {
                    $sawBackgroundImage = true;
                    $gradientShorthand = true;
                }
            }
            if ($gradientLayer !== '') {
                if (substr_count(strtolower($gradientLayer), 'url(') !== 1) {
                    return '';
                }
                $pinnedLayer = preg_replace('/url\(\s*["\']?[^"\')]*["\']?\s*\)/i', 'url("' . esc_url($imageUrl) . '")', $gradientLayer, 1);
                if (!is_string($pinnedLayer) || $pinnedLayer === '' || strpos($pinnedLayer, '}') !== false || strpos($pinnedLayer, '<') !== false) {
                    return '';
                }
                return 'background-image:' . rtrim($pinnedLayer, '; ') . ' !important';
            }
            if ($gradientShorthand) {
                return '';
            }
            // v7.21.104 — the layered rule usually lives in an EXTERNAL sheet (Divi's
            // dynamic css): standing down preserved the overlay but un-painted the hero
            // until swap time (the "loads again" percept). Before standing down, look the
            // selector up in the document's own same-host sheets on disk; a found layered
            // stack composes the pin exactly like the inline path. Cached per sel+url 6h.
            if (!$sawBackgroundImage && function_exists('get_transient')) {
                $sheetPinCacheKey = 'wpc_bgpin104_' . md5($selector . '|' . $imageUrl);
                $cachedPin = get_transient($sheetPinCacheKey);
                if (is_string($cachedPin)) {
                    return $cachedPin === '-' ? '' : $cachedPin;
                }
                $sheetFiles = [];
                if (defined('WP_CONTENT_DIR')
                    && preg_match_all('/<link\b[^>]*href=["\']([^"\']+\.css[^"\']*)["\']/i', $cssHaystack, $linkMatches)) {
                    foreach (array_unique($linkMatches[1]) as $linkHref) {
                        if (count($sheetFiles) >= 4) { break; }
                        $linkPath = (string) parse_url(html_entity_decode($linkHref), PHP_URL_PATH);
                        if (($wpContentPos = strpos($linkPath, '/wp-content/')) === false) { continue; }
                        $localSheetFile = rtrim(WP_CONTENT_DIR, '/') . rawurldecode(substr($linkPath, $wpContentPos + 11));
                        if (strpos($localSheetFile, '..') === false && @is_readable($localSheetFile)
                            && (int) @filesize($localSheetFile) <= 524288) {
                            $sheetFiles[] = $localSheetFile;
                        }
                    }
                }
                foreach ($sheetFiles as $sheetPath) {
                    $sheetCss = (string) @file_get_contents($sheetPath);
                    if ($sheetCss === '' || strpos($sheetCss, substr($selectorToken, 1)) === false) { continue; }
                    $sheetPin = self::wpc_bg_pin_declaration($selector, $imageUrl, '{}' . $sheetCss);
                    if ($sheetPin !== '' && stripos($sheetPin, 'gradient(') !== false) {
                        set_transient($sheetPinCacheKey, $sheetPin, 21600);
                        return $sheetPin;
                    }
                }
                set_transient($sheetPinCacheKey, '-', 21600);
            }
            if (!$sawBackgroundImage
                && preg_match('/(?:^|[\s.])(?:et_pb_section|et_pb_row|et_pb_column|elementor-section|elementor-element|wp-block-cover|fl-row|brxe-)/', $selector)) {
                if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_bgpin101_log')) {
                    set_transient('wpc_bgpin101_log', 1, 3600);
                    wpc_cache_first_log('lcp-bg-pin-standdown', '', '', ['sel' => substr($selector, 0, 80)]);
                }
                return '';
            }
            return $defaultDeclaration;
        } catch (\Throwable $tokenEnd) {
            return '';
        }
    }

    // v7.21.70 — DOCUMENT-AGREEMENT GATE for the measured hero preload. The preload's file
    // identity (basename of the innermost URL, query-stripped, decode-tolerant) must appear
    // somewhere in the served document — markup, srcset, inline style, or the inlined crit.
    // A CSS-bg hero painted only by an EXTERNAL stylesheet loses its preload under this gate;
    // that loss is bounded (the sheet still fetches it) while the false-preload it prevents
    // spends the page's highest-priority fetch on a ghost. Kill: wpc_lcp_preload_doc_gate.
    public static function wpc_is_lcp_preload_in_doc($html, $url)
    {
        if (!apply_filters('wpc_lcp_preload_doc_gate', true) || !is_string($html) || $html === '') {
            return true; // fail open — no document to disagree with
        }
        $u = (string) $url;
        // Innermost URL of a CDN transform wrapper (/u:https://...).
        $p = strrpos($u, '/u:http');
        if ($p !== false) {
            $u = substr($u, $p + 3);
        }
        $path = (string) parse_url($u, PHP_URL_PATH);
        $base = rawurldecode(basename($path));
        // Match on the STEM (extension and -WxH size suffix stripped): a document that
        // carries only sized variants (hero-500x500.webp) still vouches for the file.
        $stem = preg_replace('/\.[a-z0-9]{2,5}$/i', '', $base);
        $stem = preg_replace('/-\d{2,4}x\d{2,4}$/', '', (string) $stem);
        if (!is_string($stem) || strlen($stem) < 8) {
            return true; // too generic to test — never gate on a weak token
        }
        return strpos($html, $stem) !== false || strpos($html, rawurlencode($stem)) !== false;
    }

    public static function wpc_lcp_bg_url_allowed($url)
    {
        $h = strtolower((string) parse_url((string) $url, PHP_URL_HOST));
        if ($h === '') {
            return true;
        }
        if (strpos($h, 'zapwp') !== false || strpos($h, 'b-cdn') !== false) {
            return true;
        }
        $home  = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
        $strip = function ($x) { return strpos($x, 'www.') === 0 ? substr($x, 4) : $x; };


        $ok = false;
        if ($home !== '') {
            $hs = $strip($h);
            $ds = $strip($home);
            $ok = ($hs === $ds) || (substr($hs, -strlen('.' . $ds)) === '.' . $ds);
        }
        return (bool) apply_filters('wpc_lcp_bg_url_allowed', $ok, (string) $url, $h);
    }


    public static function wpc_read_atf_glyphs($critDir)
    {
        // v7.10.566 — one implementation, in defines.php (always loaded). Kept as a thin
        // delegate so existing callers and the standalone-load path are unchanged.
        if (function_exists('wpc_atf_glyphs_read')) {
            return wpc_atf_glyphs_read($critDir);
        }
        $dir = rtrim((string) $critDir, '/') . '/';
        foreach (['delay.json', 'lcp.json'] as $wpc_fn) {
            if (!@is_readable($dir . $wpc_fn)) { continue; }
            $j = json_decode((string) @file_get_contents($dir . $wpc_fn), true);
            if (!is_array($j)) { continue; }
            if (isset($j['atf_glyphs']) && is_array($j['atf_glyphs']) && !empty($j['atf_glyphs'])) {
                return $j['atf_glyphs'];
            }
            foreach ($j as $wpc_v) {
                if (is_array($wpc_v) && isset($wpc_v['atf_glyphs']) && is_array($wpc_v['atf_glyphs']) && !empty($wpc_v['atf_glyphs'])) {
                    return $wpc_v['atf_glyphs'];
                }
            }
        }
        return [];
    }


    // Consent-platform assets are never delayed, deferred, or dropped — the banner is a
    // legal-function UI (holex receipt: delayed Complianz JS left the placeholder banner
    // css unloaded and .cmplz-dismissed undefined = unclosable banner).
    public static function wpc_consent_family($s)
    {
        $consentTokens = ['cmplz', 'complianz', 'cookieyes', 'cky-consent', 'cky-style', 'cookie-law-info',
            'cookiebot', 'borlabs', 'iubenda', 'onetrust', 'usercentrics', 'surecookie', 'ccm19', 'consentmanager.net', 'cookiefirst', 'cookiehub', 'cookie-script.com', 'cookieinformation',
            'cookie-notice', 'cookie-consent', 'moove_gdpr', 'moove-gdpr', 'osano', 'termly',
            'tarteaucitron', 'quantcast', 'consently', 'didomi', 'wpl_cookie_consent'];
        if (function_exists('apply_filters')) {
            $consentTokens = (array) apply_filters('wpc_consent_tokens', $consentTokens);
        }
        foreach ($consentTokens as $t) {
            if (stripos((string) $s, $t) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * v7.10.729 — THE ONE PROOF. Does this selector provably address exactly ONE element?
     *
     * Four legs of wpc_cls_reserve_style() paint geometry, and before this they disagreed
     * about what licensed it: shifts had an explicit verdict plus a fallback pattern,
     * atf_conceal honoured an explicit false but had no fallback, atf_images demanded
     * NOTHING AT ALL, and prescriptions required verified_unique. So hardening the fallback
     * only closed the leg that was already best defended — the .727/.728 dialect fixes did
     * not reach atf_images at all, and that leg emits on any selector shaped like a selector.
     * atf_conceal is the worst case of the three: it emits `height:`, not `min-height:`.
     *
     * One helper, called by every leg. A new builder dialect is now a single regex edit that
     * takes effect everywhere, instead of four sites to remember.
     *
     * Order: an explicit verdict from the service wins in BOTH directions; then an id, which
     * is the only address that is unique by construction; then the measured dialect list.
     * Anything else is unproven and refuses — a missing reserve costs CLS, a wrong reserve
     * paints a fixed box on every element that shares the class (the tarlo 722px header).
     */
    public static function wpc_sel_addresses_one($sel, $meta = null, $leg = '', $verdicts = null)
    {
        if (is_array($meta) && array_key_exists('sel_unique', $meta)) {
            $serviceVerdict = (bool) $meta['sel_unique'];
            if (!$serviceVerdict && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('reserve-sel-refused', $leg, '', ['why' => 'service:false', 'sel' => substr((string) $sel, 0, 80)]);
            }
            return $serviceVerdict;
        }
        // v7.10.730 — the SAME artifact often carries a verdict for this exact selector on a
        // different node. Receipted on a live site: atf_images[] ships `img.wp-image-127` with
        // no sel_unique, while lcp_element ships the identical selector with sel_unique:true.
        // The service measured it against the rendered DOM; only the field placement differs.
        // Honour that measurement rather than re-deriving it from the selector's shape —
        // without this, .729 took the whole atf_images leg dark on that site.
        if (is_array($verdicts) && isset($verdicts[(string) $sel])) {
            $artifactVerdict = (bool) $verdicts[(string) $sel];
            if (!$artifactVerdict && function_exists('wpc_cache_first_log')) {
                wpc_cache_first_log('reserve-sel-refused', $leg, '', ['why' => 'artifact:false', 'sel' => substr((string) $sel, 0, 80)]);
            }
            return $artifactVerdict;
        }
        if (strpos((string) $sel, '#') !== false) {
            return true;
        }
        // v7.10.730 — FUSION REMOVED. Measured by the crit team on four live Avada sites:
        // .fusion-builder-column-7 = 5 elements, .fusion-megamenu-columns-3 = 6,
        // .fusion-builder-row-1 = 4. The indexes repeat across every row and container, so
        // there is no length or shape fix — same resolution as Bricks. Those pages carry
        // 106-248 ids each, so '#' remains the Avada address. Divi stays because 277/277 of
        // its tokens measured unique: the difference between two index-shaped dialects is a
        // fact about the builders, which is exactly why it has to be measured, not reasoned.
        // We have no Avada page of our own; this follows their measurement, not our guess.
        if (preg_match('/(?:(?<![\w-])elementor-element-[a-f0-9]{6,8}(?![\w-])|(?<![\w-])et_pb_[a-z]+_\d+(?![\w-]))/i', (string) $sel)) {
            return true;
        }
        // Journalled, not silent: this is the line that tells us how much reserve coverage
        // the tightening actually costs on real sites, per leg — rather than guessing.
        if (function_exists('wpc_cache_first_log')) {
            wpc_cache_first_log('reserve-sel-refused', $leg, '', ['why' => 'unproven', 'sel' => substr((string) $sel, 0, 80)]);
        }
        return false;
    }

    /**
     * The page with every comment and the bodies of its script, style, template and textarea
     * elements blanked to spaces: offsets still match the page, and markup written inside them
     * is not read as elements.
     */
    public static function wpc_page_inert_blanked($html)
    {
        $html = (string) $html;
        if ($html === '' || !preg_match_all('#<!--|<(script|style|template|textarea)\b[^>]*>#i', $html, $openers, PREG_OFFSET_CAPTURE)) {
            return $html;
        }
        $pieces = [];
        $cursor = 0;
        $length = strlen($html);
        foreach ($openers[0] as $n => $opener) {
            if ($opener[1] < $cursor) {
                continue;
            }
            if ($opener[0] === '<!--') {
                $blankFrom = $opener[1];
                $close = strpos($html, '-->', $blankFrom + 4);
                $blankTo = $close === false ? $length : $close + 3;
            } else {
                $blankFrom = $opener[1] + strlen($opener[0]);
                $close = stripos($html, '</' . $openers[1][$n][0], $blankFrom);
                $blankTo = $close === false ? $length : $close;
            }
            $pieces[] = substr($html, $cursor, $blankFrom - $cursor);
            $pieces[] = str_repeat(' ', $blankTo - $blankFrom);
            $cursor = $blankTo;
        }
        $pieces[] = (string) substr($html, $cursor);
        return implode('', $pieces);
    }

    /**
     * Where the one element a simple selector names sits in $page (a wpc_page_inert_blanked()
     * copy): [start of its start tag, end of its start tag, start of its end tag, tag name].
     * Null when the selector is not one compound of an optional tag, classes and at most one id,
     * when it names no element or more than one, when a '<' before one of its tokens cannot be
     * read as a start tag, or when the element's end tag cannot be found.
     */
    public static function wpc_page_element_span($sel, $page)
    {
        $sel = trim((string) $sel);
        $page = (string) $page;
        if ($sel === '' || $page === '' || strlen($sel) > 200
            || !preg_match('/^([a-zA-Z][a-zA-Z0-9]*)?((?:[.#]-?[A-Za-z_][A-Za-z0-9_-]*)+)$/', $sel, $parts)) {
            return null;
        }
        $tagName = strtolower((string) $parts[1]);
        preg_match_all('/([.#])(-?[A-Za-z_][A-Za-z0-9_-]*)/', $parts[2], $tokens, PREG_SET_ORDER);
        $wantId = null;
        $wantClasses = [];
        foreach ($tokens as $token) {
            if ($token[1] === '#') {
                if ($wantId !== null) {
                    return null;
                }
                $wantId = $token[2];
            } else {
                $wantClasses[] = $token[2];
            }
        }
        $needle = (string) $wantId;
        foreach ($wantClasses as $wantClass) {
            if (strlen($wantClass) > strlen($needle)) {
                $needle = $wantClass;
            }
        }
        $startTag = '#\G<([a-zA-Z][a-zA-Z0-9-]*)(?=[\s/>])((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#';
        $match = null;
        $checkedStarts = [];
        $tokenHits = 0;
        $offset = 0;
        while (($at = strpos($page, $needle, $offset)) !== false) {
            $offset = $at + strlen($needle);
            if (preg_match('/[\w-]/', ($at > 0 ? $page[$at - 1] : ' ') . (isset($page[$offset]) ? $page[$offset] : ' '))) {
                continue;
            }
            if (++$tokenHits > 200) {
                return null;
            }
            $lt = strrpos($page, '<', $at - strlen($page));
            if ($lt === false || isset($checkedStarts[$lt])) {
                continue;
            }
            $checkedStarts[$lt] = 1;
            if (!preg_match($startTag, $page, $tag, 0, $lt)) {
                if (!isset($page[$lt + 1]) || $page[$lt + 1] !== '/') {
                    return null;
                }
                continue;
            }
            if ($lt + strlen($tag[0]) <= $at || ($tagName !== '' && strtolower($tag[1]) !== $tagName)) {
                continue;
            }
            preg_match_all('#\s([^\s=/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?#', $tag[2], $attributes, PREG_SET_ORDER);
            $attributeValues = [];
            foreach ($attributes as $attribute) {
                $attributeName = strtolower($attribute[1]);
                if (!isset($attributeValues[$attributeName])) {
                    $attributeValues[$attributeName] = (string) (isset($attribute[4]) ? $attribute[4] : (isset($attribute[3]) && $attribute[3] !== '' ? $attribute[3] : (isset($attribute[2]) ? $attribute[2] : '')));
                }
            }
            if ($wantId !== null && (!isset($attributeValues['id']) || trim($attributeValues['id']) !== $wantId)) {
                continue;
            }
            $hasClasses = isset($attributeValues['class']) ? preg_split('/\s+/', trim($attributeValues['class'])) : [];
            if (array_diff($wantClasses, $hasClasses)) {
                continue;
            }
            if ($match !== null) {
                return null;
            }
            $match = [$lt, $lt + strlen($tag[0]), strtolower($tag[1])];
        }
        if ($match === null) {
            return null;
        }
        if (preg_match('/^(?:area|base|br|col|embed|hr|img|input|link|meta|param|source|track|wbr)$/', $match[2])) {
            return [$match[0], $match[1], $match[1], $match[2]];
        }
        $edgePattern = '#<(/?)' . preg_quote($match[2], '#') . '(?=[\s/>])#i';
        $depth = 1;
        $cursor = $match[1];
        for ($steps = 0; $steps < 20000 && preg_match($edgePattern, $page, $edge, PREG_OFFSET_CAPTURE, $cursor); $steps++) {
            $depth += $edge[1][0] === '/' ? -1 : 1;
            if ($depth === 0) {
                return [$match[0], $match[1], $edge[0][1], $match[2]];
            }
            $cursor = $edge[0][1] + 2;
        }
        return null;
    }

    /**
     * Whether $page between $from and $to is static text: it holds text, and every tag in it is a
     * text or layout tag that carries nothing but class, id, a style without url(), title, href,
     * target, rel, lang, dir, role, datetime or aria-*. Nothing in such markup loads, so it holds
     * no media now and none arrives later.
     */
    public static function wpc_page_span_is_static_text($page, $from, $to)
    {
        if ($to <= $from || $to - $from > 65536) {
            return false;
        }
        $span = (string) substr((string) $page, $from, $to - $from);
        if (!preg_match_all('#<([a-zA-Z][a-zA-Z0-9-]*)((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#', $span, $tags, PREG_SET_ORDER)) {
            return false;
        }
        foreach ($tags as $tag) {
            if (!preg_match('/^(?:div|section|article|aside|header|footer|main|nav|span|p|a|strong|b|em|i|u|s|small|sub|sup|mark|abbr|cite|code|kbd|q|blockquote|br|hr|h[1-6]|ul|ol|li|dl|dt|dd|pre|time|address|del|ins|wbr|figcaption)$/i', $tag[1])) {
                return false;
            }
            preg_match_all('#\s([^\s=/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?#', $tag[2], $attributes, PREG_SET_ORDER);
            foreach ($attributes as $attribute) {
                $attributeName = strtolower($attribute[1]);
                if ($attributeName === 'style') {
                    if (stripos(implode('', array_slice($attribute, 2)), 'url(') !== false) {
                        return false;
                    }
                    continue;
                }
                if (!preg_match('/^(?:class|id|title|href|target|rel|lang|dir|role|datetime|aria-[a-z-]+)$/', $attributeName)) {
                    return false;
                }
            }
        }
        return trim(preg_replace('/&(?:nbsp|#160|#xa0);/i', ' ', strip_tags($span))) !== '';
    }

    /**
     * What in the artifact or the page contradicts a reserve-rect prescription, or [] when nothing
     * does. Read off the page's own markup, so only for selectors that resolve to one element:
     *  - ancestor-shorter: at the same measured width another prescription's element, a block
     *    container measured at 24 px or more, holds this one and was measured shorter than this
     *    reserve;
     *  - no-media: an unsized-media prescription whose element is static text.
     * $spans memoises resolved selectors for one page; past 24 resolutions nothing more resolves.
     */
    public static function wpc_presc_reserve_contradiction(array $prescription, $reservePx, array $prescriptions, $page, array &$spans)
    {
        $locate = function ($sel) use ($page, &$spans) {
            $sel = trim((string) $sel);
            if (!array_key_exists($sel, $spans)) {
                $spans[$sel] = count($spans) < 24 ? self::wpc_page_element_span($sel, $page) : null;
            }
            return $spans[$sel];
        };
        $span = $locate(isset($prescription['fix']['payload']['sel']) ? $prescription['fix']['payload']['sel'] : '');
        if ($span === null) {
            return [];
        }
        $prescriptionId = strtolower((string) (isset($prescription['id']) ? $prescription['id'] : ''));
        if (isset($prescription['width']) && is_numeric($prescription['width'])) {
            foreach ($prescriptions as $other) {
                if (!is_array($other) || strtolower((string) (isset($other['id']) ? $other['id'] : '')) === $prescriptionId
                    || !isset($other['width']) || !is_numeric($other['width']) || (int) $other['width'] !== (int) $prescription['width']
                    || !isset($other['evidence']['final_box']['h']) || !is_numeric($other['evidence']['final_box']['h'])
                    || (float) $other['evidence']['final_box']['h'] < 24
                    || (float) $other['evidence']['final_box']['h'] + 2 >= (float) $reservePx) {
                    continue;
                }
                $otherSpan = $locate(isset($other['fix']['payload']['sel']) ? $other['fix']['payload']['sel'] : (isset($other['selector']) ? $other['selector'] : ''));
                if ($otherSpan !== null && $otherSpan[0] < $span[0] && $span[2] <= $otherSpan[2]
                    && preg_match('/^(?:div|section|article|main|header|footer|aside|nav|figure|form|ul|ol|li|dl|dd|blockquote)$/', $otherSpan[3])) {
                    return ['why' => 'ancestor-shorter', 'anc' => strtolower((string) $other['id']),
                        'anc_h' => (int) round((float) $other['evidence']['final_box']['h'])];
                }
            }
        }
        if (strtolower((string) (isset($prescription['class']) ? $prescription['class'] : '')) === 'unsized-media'
            && self::wpc_page_span_is_static_text($page, $span[0], $span[2])) {
            return ['why' => 'no-media'];
        }
        return [];
    }

    public static function wpc_cls_reserve_style($critDir, $isMobile, $html = '')
    {
        if (!apply_filters('wpc_cls_reserve', true)) {
            return '';
        }
        $dir = rtrim((string) $critDir, '/') . '/';
        // delay.json is optional — the lcp.json legs below must run without it.
        $j = @is_readable($dir . 'delay.json') ? json_decode((string) @file_get_contents($dir . 'delay.json'), true) : [];
        if (!is_array($j)) {
            $j = [];
        }
        $device = $isMobile ? 'mobile' : 'desktop';
        $media  = $isMobile ? '(max-width: 767.98px)' : '(min-width: 768px)';

        $shifts = [];
        if (isset($j['cls'][$device]['shifts']) && is_array($j['cls'][$device]['shifts'])) {
            $shifts = $j['cls'][$device]['shifts'];
        } elseif (isset($j[$device]['cls_sources']) && is_array($j[$device]['cls_sources'])) {
            $shifts = $j[$device]['cls_sources'];
        }
        // No early return on empty shifts — the atf_conceal leg below must
        // run without it (empty-shifts guillotine kept the conceal pin from ever emitting).

        // v7.10.730 — harvest every sel_unique verdict the artifacts DO carry, keyed by
        // selector, so a node missing the field can inherit the measurement the service
        // already made for that exact selector elsewhere in the same file. Built from the
        // whole decoded tree because the field's placement is not consistent across node
        // types (lcp_element has it; atf_images does not).
        $selectorVerdicts = [];
        $harvestVerdicts = function ($node) use (&$harvestVerdicts, &$selectorVerdicts) {
            if (is_array($node)) {
                if (isset($node['sel']) && is_string($node['sel']) && $node['sel'] !== ''
                    && array_key_exists('sel_unique', $node)) {
                    $nodeSelector = $node['sel'];
                    // A false anywhere is sticky: one node proving it non-unique outranks
                    // another node's true, because non-uniqueness is the dangerous direction.
                    if (!isset($selectorVerdicts[$nodeSelector]) || $selectorVerdicts[$nodeSelector]) {
                        $selectorVerdicts[$nodeSelector] = (bool) $node['sel_unique'];
                    }
                }
                foreach ($node as $childNode) {
                    if (is_array($childNode)) { $harvestVerdicts($childNode); }
                }
            }
        };
        $harvestVerdicts($j);
        // lcp.json's verdicts come from its one reader, harvested by the same sticky-false rule.
        $pageHasObservation = @is_readable($dir . 'lcp.json');
        if ($pageHasObservation) {
            foreach (wps_ic_atf_observation::selectorVerdicts() as $observedSelector => $observedVerdict) {
                if (!isset($selectorVerdicts[$observedSelector]) || $selectorVerdicts[$observedSelector]) {
                    $selectorVerdicts[$observedSelector] = $observedVerdict;
                }
            }
        }

        $selRx = '/^[A-Za-z0-9 _\-#.:\[\]=>+~()]+$/';
        $rules = [];
        foreach ($shifts as $s) {
            if (!is_array($s) || empty($s['reserve']) || !is_array($s['reserve'])) {
                continue;
            }
            $sel  = isset($s['sel']) ? trim((string) $s['sel']) : '';
            $type = isset($s['reserve']['type']) ? (string) $s['reserve']['type'] : '';
            $px   = isset($s['reserve']['px']) ? $s['reserve']['px'] : null;
            if ($sel === '' || strlen($sel) > 200 || !preg_match($selRx, $sel)) {
                continue;
            }


            if (array_key_exists('sel_unique', $s) && !$s['sel_unique']) {
                continue;
            }
            // v7.10.727 — brxe- REMOVED from the fallback identity list. Bricks instance
            // identity is the element's id (#brxe-xxxx) and only the id; the CLASS namespace
            // carries type classes and loop-content classes that repeat across the page.
            // Measured on a live Bricks page: .brxe-section 3 elements, .brxe-container 4,
            // .brxe-rdrmvh 24 — and brxe-[a-z0-9]{5,8} admitted all three as unique
            // addresses, so one measured min-height would have painted on 24 elements. That
            // is the tarlo 722px header incident, in a dialect this gate had never been run
            // against. A type-class blocklist cannot fix it (the 24-element class is not a
            // type class), so the pattern goes. #brxe-xxxx still qualifies via the '#' test
            // above. entrance-reveal.js already carried this law and its 68-entry type list.
            // v7.10.728 dialect soundness + v7.10.729 single proof — see
            // wpc_sel_addresses_one(). Divi was unsound the same way Bricks was:
            // et_pb_\w+_\d+ let \w+ swallow a digit, so the WIDTH classes
            // .et_pb_column_4_4 / _1_2 / _1_3 read as indexed instances (24, 12 and 18
            // elements on one page). wp-image-N is gone: an ATTACHMENT id is unique per
            // file, not per element. 0 non-unique admissions across 9 corpus pages after.
            if (!self::wpc_sel_addresses_one($sel, $s, 'shifts', $selectorVerdicts)) {
                continue;
            }
            if ($type !== 'min-height') {
                continue;
            }
            if (!is_numeric($px)) {
                continue;
            }
            $px = (int) round((float) $px);
            if ($px < 24 || $px > 2000) {
                continue;
            }
            $rules[] = $sel . '{min-height:' . $px . 'px}';
            if (count($rules) >= 12) {
                break;
            }
        }

        // No atf_images leg. It pinned min-height on an above-the-fold image from h, height,
        // box_h or box[1] — keys crit-push has never written on an atf_images entry (every
        // producer writes css_w/css_h: lcp-detect.js images map, hermetic-census.js), so the leg
        // emitted nothing on any artifact and was removed with its second decode of lcp.json.

        // atf_conceal (v3.48.1): top-of-page elements whose height SHRINKS between paint and
        // settle (script-collapsed headers) — pin the final height so below-content never leaps.
        // The owner resolves the device list, the {items} wrapper and the settled height.
        if (count($rules) < 12 && $pageHasObservation) {
            foreach (wps_ic_atf_observation::concealedBoxes($device) as $concealedBox) {
                $csel = $concealedBox['sel'];
                $ch   = $concealedBox['height'];
                if (strlen($csel) > 200 || !preg_match($selRx, $csel)) {
                    continue;
                }
                // This leg emits `height:` rather than `min-height:`, a fixed box, so an unproven
                // address would be the most damaging of the legs: the owner hands out only boxes
                // whose selector the service proved unique (`sel_unique: true`).
                $ch = (int) round($ch);
                if ($ch < 10 || $ch > 600) {
                    continue;
                }
                // v7.21.281 — SPEC-cls-reserve-transparent-header: a height pin on a header
                // template wrapper TRAPS the transparent-header overlap (margin-bottom:-100px
                // collapses out only while height is auto — staging hero dropped exactly
                // 100px, invisible to every computed-style diff). The template class encodes
                // the post id (elementor-11660, numeric — element ids are hex and never
                // match); a header/popup template's height is fixed by its own chrome and
                // cannot CLS-shift, so skipping costs no protection.
                if (preg_match('/\belementor-(\d{2,10})\b/', $csel, $templateIdMatch) && function_exists('get_post_meta')) {
                    $templateType = (string) get_post_meta((int) $templateIdMatch[1], '_elementor_template_type', true);
                    if ($templateType === 'header' || $templateType === 'popup') {
                        if (function_exists('wpc_cache_first_log') && function_exists('get_transient') && !get_transient('wpc_clsrh281')) {
                            set_transient('wpc_clsrh281', 1, 3600);
                            wpc_cache_first_log('cls-reserve-header-skip', '', '', ['sel' => $csel, 'type' => $templateType]);
                        }
                        continue;
                    }
                }
                $rules[] = $csel . '{height:' . $ch . 'px}';
                if (count($rules) >= 12) {
                    break;
                }
            }
        }

        // AUTO-100 §4 reserve-rect leg — own budget (≤8 on top of the legacy legs' shared
        // 12 cap): verdict-verified work must not compete with heuristic legs for slots.
        // A3: only verified_unique:true emits; the inline guard below is uniqueness count #2.
        $prescriptionRules = [];
        $refusedReserves = [];
        $prescriptionsRaw = @is_readable($dir . 'prescriptions.json') ? (string) @file_get_contents($dir . 'prescriptions.json') : '';
        // Artifact tag: the runtime not-unique gate is honored only when the mark's av
        // matches THIS file's av (a purge-and-refetch of identical bytes keeps the same
        // av; a genuine republish changes it). Mirrors wpc_presc_av_tag() server-side.
        $artifactVersionTag = $prescriptionsRaw !== '' ? substr(md5($prescriptionsRaw), 0, 12) : '';
        if ($prescriptionsRaw !== '' && apply_filters('wpc_prescriptions_reserve', true)) {
            $prescriptionsJson = json_decode($prescriptionsRaw, true);
            if (is_array($prescriptionsJson) && isset($prescriptionsJson['prescriptions']) && is_array($prescriptionsJson['prescriptions'])) {
                $seenSelectors = [];
                foreach ($rules as $existingRule) {
                    $seenSelectors[strtolower((string) substr($existingRule, 0, (int) strpos($existingRule, '{')))] = 1;
                }
                $knownClasses = function_exists('wpc_presc_known_classes') ? wpc_presc_known_classes() : [];
                // A3 gate 2 persistence: ids the runtime re-count found non-unique stop
                // emitting (else every pageload re-emits, re-strips, re-beacons). Reads
                // the av-stamped sticky store — eviction-proof, forced-re-apply-proof,
                // and version-precise (a stale-artifact verdict does not suppress a
                // freshly-republished id).
                static $notUniqueIds = null;
                if ($notUniqueIds === null) {
                    $notUniqueIds = (function_exists('wpc_presc_notuniq_get')) ? wpc_presc_notuniq_get()
                        : (function ($j) {
                            $o = [];
                            if (is_array($j)) {
                                foreach ($j as $k => $v) {
                                    if (is_array($v) && in_array((string) ($v['skipped'] ?? ''), ['not-unique', 'implausible'], true)) { $o[strtolower((string) $k)] = 1; }
                                }
                            }
                            return $o;
                        })(function_exists('get_option') ? get_option('wpc_presc_journal') : null);
                }
                $plausibilityPage = null;
                $plausibilitySpans = [];
                foreach ($prescriptionsJson['prescriptions'] as $prescription) {
                    if (count($prescriptionRules) >= 8) {
                        break;
                    }
                    if (!is_array($prescription) || !empty($prescription['applied_by_service'])) {
                        continue;
                    }
                    if (!isset($prescription['fix']['type']) || strtolower((string) $prescription['fix']['type']) !== 'reserve-rect') {
                        continue;
                    }
                    if (!isset($prescription['verified_unique']) || $prescription['verified_unique'] !== true) {
                        continue;
                    }
                    $prescriptionId = isset($prescription['id']) ? strtolower((string) $prescription['id']) : '';
                    $prescriptionClass = isset($prescription['class']) ? strtolower((string) $prescription['class']) : '';
                    if (!preg_match('/^[a-f0-9]{6,40}$/', $prescriptionId)
                        || (!empty($knownClasses) && !in_array($prescriptionClass, $knownClasses, true))) {
                        continue;
                    }
                    if (isset($notUniqueIds[$prescriptionId])
                        && ($notUniqueIds[$prescriptionId] === 1 || (string) $notUniqueIds[$prescriptionId] === $artifactVersionTag)) {
                        continue; // a runtime verdict for THIS artifact: not unique, or implausible
                        // (===1 is the journal-fallback sentinel when warm.php is absent)
                    }
                    $reserveSelector = isset($prescription['fix']['payload']['sel']) ? trim((string) $prescription['fix']['payload']['sel']) : '';
                    $reserveHeightPx  = isset($prescription['fix']['payload']['min_height_px']) ? $prescription['fix']['payload']['min_height_px'] : null;
                    if ($reserveSelector === '' || strlen($reserveSelector) > 400 || !preg_match($selRx, $reserveSelector) || !is_numeric($reserveHeightPx)) {
                        continue;
                    }
                    $reserveHeightPx = (int) round((float) $reserveHeightPx);
                    if ($reserveHeightPx < 24 || $reserveHeightPx > 2000) {
                        continue;
                    }
                    // device split on the prescription's measured width; no width = both
                    if (isset($prescription['width']) && is_numeric($prescription['width'])
                        && ($isMobile ? (int) $prescription['width'] >= 768 : (int) $prescription['width'] < 768)) {
                        continue;
                    }
                    if (isset($seenSelectors[strtolower($reserveSelector)])) {
                        continue; // first-leg-wins across all four legs
                    }
                    if ($plausibilityPage === null) {
                        $plausibilityPage = ((string) $html !== '' && strlen((string) $html) <= 3000000) ? self::wpc_page_inert_blanked($html) : '';
                    }
                    $contradiction = $plausibilityPage !== ''
                        ? self::wpc_presc_reserve_contradiction($prescription, $reserveHeightPx, $prescriptionsJson['prescriptions'], $plausibilityPage, $plausibilitySpans)
                        : [];
                    if (!empty($contradiction)) {
                        $refusedReserves[] = array_merge(['id' => $prescriptionId, 'h' => $reserveHeightPx], $contradiction);
                        continue;
                    }
                    $seenSelectors[strtolower($reserveSelector)] = 1;
                    $prescriptionRules[] = ['i' => $prescriptionId, 's' => $reserveSelector, 'r' => $reserveSelector . '{min-height:' . $reserveHeightPx . 'px}',
                        'h' => $reserveHeightPx, 'w' => (isset($prescription['width']) && is_numeric($prescription['width'])) ? (int) $prescription['width'] : 0];
                }
            }
        }

        if (!empty($refusedReserves) && function_exists('wpc_belt_receipt')) {
            wpc_belt_receipt('presc-reserve-refused', ['dev' => $device, 'refused' => array_slice($refusedReserves, 0, 8)], false, basename(rtrim($dir, '/')));
        }
        if (empty($rules) && empty($prescriptionRules)) {
            return '';
        }
        $reserveMarkup = '';
        if (!empty($rules)) {
            $reserveMarkup .= "\r\n" . '<style id="wpc-cls-reserve">@media ' . $media . '{' . implode('', $rules) . '}</style>';
        }
        if (!empty($prescriptionRules)) {
            $prescriptionStyleId = 'wpc-presc-reserve-' . ($isMobile ? 'm' : 'd');
            $prescriptionCss = '';
            foreach ($prescriptionRules as $prescriptionEntry) {
                $prescriptionCss .= $prescriptionEntry['r'];
            }
            $ajaxUrl = function_exists('admin_url') ? admin_url('admin-ajax.php') : '/wp-admin/admin-ajax.php';
            $reserveMarkup .= "\r\n" . '<style id="' . $prescriptionStyleId . '">@media ' . $media . '{' . $prescriptionCss . '}</style>'
                // A3 count #2. The loader traps load/readyState/DOMContentLoaded, so
                // parse-completion = a MutationObserver quiet window (2.5s floor, 600ms
                // quiet, 12s hard cap → do nothing = fail open). Count semantics: >1 =
                // strip + beacon (true non-unique); 0 = strip quietly (variant page —
                // never demote the prescription); selector throw = leave the rule (the
                // CSS parser drops it anyway).
                // Plausibility (P): 1.5s after the delayed scripts have run (or after the
                // quiet window when there is no loader), once the document is complete and no
                // stylesheet is parked or still loading, and every img/video inside is loaded,
                // with no iframe/embed/object, at a viewport within 0.75–1.5× the measured
                // width, a reserve taller than 1.5 × the element's own height + 48px on two
                // readings 1.5s apart (the same own height both times) is stripped and
                // beaconed implausible. Each reading disables the sheet in one synchronous pass.
                . '<script id="wpc-presc-guard-' . ($isMobile ? 'm' : 'd') . '">(function(){var T=Date.now(),L=T,O=null,s,N=0,H=null,'
                . 'Q=' . wp_json_encode($media) . ','
                . 'm=' . wp_json_encode(array_map(function ($rule) {
                    return ['i' => $rule['i'], 's' => $rule['s'], 'h' => $rule['h'], 'w' => $rule['w']];
                }, array_values($prescriptionRules))) . ','
                . 'A=function(){return!window.matchMedia||matchMedia(Q).matches},'
                . 'C=function(){var l=document.querySelectorAll("link[rel~=stylesheet]"),i;if(document.readyState!="complete"||document.querySelector(\'[rel^="wpc-"][rel$="stylesheet"],[type^="wpc-"][type$="stylesheet"],link[media="print"][onload],link[data-wpc-tm][media="print"]\'))return!1;for(i=0;i<l.length;i++)if(!l[i].sheet)return!1;return!0},'
                . 'B=function(a,k){var b=[],x;for(x=0;x<a.length&&x<4;x++)b.push(a[x].i);'
                . 'try{navigator.sendBeacon(' . wp_json_encode($ajaxUrl) . ',new URLSearchParams({action:"wpc_presc_seen",id:b.join(","),skipped:k,av:' . wp_json_encode($artifactVersionTag) . '}))}catch(e){}},'
                . 'W=function(k){var c="",j;for(j=0;j<k.length;j++)c+=k[j].s+"{min-height:"+k[j].h+"px}";s.textContent=c?"@media "+Q+"{"+c+"}":"";m=k},'
                . 'P=function(){try{var e=[],h=[],k=[],x=[],G={},i,j,q,n,t,ok,w=innerWidth;if(!C()){++N<30&&setTimeout(P,1e3);return}if(!A())return;'
                . 's.disabled=!0;try{for(i=0;i<m.length;i++){try{e[i]=document.querySelector(m[i].s)}catch(z){}h[i]=e[i]?e[i].offsetHeight:0}}finally{s.disabled=!1}'
                . 'for(i=0;i<m.length;i++){q=e[i];G[m[i].i]=h[i];ok=q&&h[i]>0&&w>=m[i].w*.75&&w<=m[i].w*1.5&&m[i].h>1.5*h[i]+48&&(!H||H[m[i].i]===h[i]);'
                . 'n=ok?q.querySelectorAll("img,video,iframe,embed,object,script,noscript,[data-src],[data-lazy-src],[data-srcset],[data-bg],[data-wpc-src]"):[];'
                . 'for(j=-1;ok&&j<n.length;j++){t=j<0?q:n[j];'
                . 'ok=/^(SCRIPT|NOSCRIPT)$/.test(t.tagName)||t.hasAttribute("data-src")||t.hasAttribute("data-lazy-src")||t.hasAttribute("data-srcset")||t.hasAttribute("data-bg")||t.hasAttribute("data-wpc-src")?!1:'
                . 't.tagName=="IMG"?t.complete&&t.naturalWidth>0&&!/^data:/.test(t.currentSrc||t.src):t.tagName=="VIDEO"?t.readyState>0:!/^(IFRAME|EMBED|OBJECT)$/.test(t.tagName)}'
                . '(ok?x:k).push(m[i])}'
                . 'if(!x.length)return;if(!H){H=G;setTimeout(P,1500);return}W(k);B(x,"implausible")}catch(z){}},'
                . 'F=function(){try{'
                . 'if(Date.now()-T>12e3){O&&O.disconnect();return}'
                . 'if(!document.body||Date.now()-L<600){setTimeout(F,700);return}'
                . 'O&&O.disconnect();'
                . 's=document.getElementById(' . wp_json_encode($prescriptionStyleId) . ');if(!s||!A())return;'
                . 'var keep=[],bad=[],i,c;'
                . 'for(i=0;i<m.length;i++){try{c=document.querySelectorAll(m[i].s).length}catch(e){c=1}'
                . 'if(c>1)bad.push(m[i]);else if(c!==0)keep.push(m[i])}'
                . 'if(keep.length<m.length){W(keep);bad.length&&B(bad,"not-unique")}'
                . 'if(!window.wpcStartDelayed||window.wpcScriptsLoadedAt)setTimeout(P,1500);'
                . 'else addEventListener("wpc-scripts-loaded",function(){setTimeout(P,1500)})'
                . '}catch(e){}};'
                . 'try{O=new MutationObserver(function(){L=Date.now()});O.observe(document.documentElement,{childList:!0,subtree:!0})}catch(e){}'
                . 'setTimeout(F,2500)})();</script>';
        }
        return $reserveMarkup;
    }


    public static function wpc_trim_preset_vars($crit, $usageHaystack)
    {
        if (!is_string($crit) || $crit === '' || strpos($crit, '--wp--preset--') === false) {
            return $crit;
        }
        if (function_exists('apply_filters') && !apply_filters('wpc_trim_preset_vars', true)) {
            return $crit;
        }
        try {
            $used = [];
            if (preg_match_all('/var\(\s*(--wp--preset--[a-z0-9_-]+)/i', (string) $usageHaystack, $um)) {
                foreach ($um[1] as $u) {
                    $used[strtolower($u)] = 1;
                }
            }
            $out = preg_replace_callback('/(--wp--preset--[a-z0-9_-]+)\s*:\s*[^;{}]*;?/i', function ($m) use ($used) {
                return isset($used[strtolower($m[1])]) ? $m[0] : '';
            }, $crit);
            return (is_string($out) && $out !== '') ? $out : $crit;
        } catch (\Throwable $e) {
            return $crit;
        }
    }


    public static function wpc_trim_crit_fontface($crit, $critDir)
    {
        if (!is_string($crit) || $crit === '' || stripos($crit, '@font-face') === false
            || !apply_filters('wpc_crit_fontface_trim', true)) {
            return $crit;
        }
        $ag = self::wpc_read_atf_glyphs($critDir);
        if (empty($ag)) {
            return $crit;
        }
        $keys = array_keys($ag);
        if (empty($keys) || !is_string($keys[0])) { $keys = array_values($ag); }
        $usedFam = []; $usedWt = [];
        foreach ($keys as $k) {
            if (!is_string($k) || strpos($k, '|') === false) { continue; }
            $p   = explode('|', strtolower($k));
            $fam = trim($p[0]);
            $wt  = isset($p[1]) ? preg_replace('/[^0-9]/', '', $p[1]) : '';
            if ($fam === '') { continue; }
            $usedFam[$fam] = 1;
            if ($wt !== '') { $usedWt[$fam][$wt] = 1; }
        }
        if (empty($usedFam)) {
            return $crit;
        }

        // RANGE (font-weight:100 900) that no single atf_glyphs weight matches, so a naive trim


        $keptFam = [];
        if (preg_match_all('/@font-face\s*\{[^}]*\}/is', $crit, $wpc_all)) {
            foreach ($wpc_all[0] as $wpc_f) {
                if (strpos($wpc_f, 'data:font/woff2;base64') !== false) { continue; }
                if (!preg_match('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $wpc_f, $wpc_fm)) { continue; }
                $wpc_fam = strtolower(trim($wpc_fm[1]));
                if (!isset($usedFam[$wpc_fam]) || empty($usedWt[$wpc_fam])) { continue; }
                if (preg_match('/font-weight\s*:\s*\d+\s+\d+/i', $wpc_f)) { $keptFam[$wpc_fam] = 1; continue; }
                $wpc_w = preg_match('/font-weight\s*:\s*(\d+)/i', $wpc_f, $wpc_wm) ? $wpc_wm[1] : '400';
                if (isset($usedWt[$wpc_fam][$wpc_w])) { $keptFam[$wpc_fam] = 1; }
            }
        }
        $trimmed = 0;
        $out = preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($m) use ($usedFam, $usedWt, $keptFam, &$trimmed) {
            $f = $m[0];
            if (strpos($f, 'data:font/woff2;base64') !== false) { return $m[0]; } // A1 subset — always keep
            if (!preg_match('/font-family\s*:\s*[\'"]?([^;\'"}]+)/i', $f, $fm)) { return $m[0]; }
            $fam = strtolower(trim($fm[1]));
            if ($fam === '' || !isset($usedFam[$fam])) { return $m[0]; }
            if (empty($usedWt[$fam]) || empty($keptFam[$fam])) { return $m[0]; }
            if (preg_match('/font-weight\s*:\s*\d+\s+\d+/i', $f)) { return $m[0]; }
            $wt = preg_match('/font-weight\s*:\s*(\d+)/i', $f, $wm) ? $wm[1] : '400';
            if (isset($usedWt[$fam][$wt])) {
                return $m[0];
            }
            $trimmed++;
            return '';
        }, $crit);
        // The service's crit carries faces for weights the fold never paints; they are dropped.
        // Sampled: the crit and its glyph map are the same on every render.
        if ($trimmed > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('crit-fontface-trimmed', ['dropped' => $trimmed], true);
        }
        return is_string($out) ? $out : $crit;
    }


    public static function wpc_lcp_bg_rung_url($url, $target = 0)
    {
        static $memo = [];
        $url = (string) $url;
        if ($url === '' || !apply_filters('wpc_lcp_bg_rung', true)) {
            return '';
        }
        if (isset($memo[$url])) {
            return $memo[$url];
        }
        $memo[$url] = '';
        $target = (int) ($target > 0 ? $target : apply_filters('wpc_lcp_bg_mobile_rung', 828));
        if ($target <= 0 || !function_exists('attachment_url_to_postid') || !function_exists('wp_get_attachment_metadata')) {
            return '';
        }
        $q = (string) parse_url($url, PHP_URL_QUERY);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $up = strpos($path, '/wp-content/uploads/');
        if ($up === false || !preg_match('#^(.*/)([^/]+)\.(avif|webp|jpe?g|png)$#i', $path, $pm)) {
            return '';
        }
        if (preg_match('/-\d{2,4}x\d{2,4}$/', $pm[2])) {
            return '';
        }
        $src_ext = '';
        if ($q !== '' && preg_match('/(?:^|&)src=(jpe?g|png)(?:&|$)/i', $q, $qm)) {
            $src_ext = strtolower($qm[1]);
        }
        $orig_ext = $src_ext !== '' ? $src_ext : strtolower($pm[3]);
        if (!in_array($orig_ext, ['jpg', 'jpeg', 'png'], true)) {
            return '';
        }
        $home = function_exists('home_url') ? rtrim((string) home_url(), '/') : '';
        if ($home === '') {
            return '';
        }
        $origin = $home . substr($path, $up);
        $origin = (string) preg_replace('/\.(avif|webp|jpe?g|png)$/i', '.' . $orig_ext, $origin);
        $att = (int) attachment_url_to_postid($origin);
        if ($att <= 0) {
            return '';
        }
        $meta = wp_get_attachment_metadata($att);
        if (!is_array($meta) || empty($meta['width']) || (int) $meta['width'] <= $target || empty($meta['sizes']) || !is_array($meta['sizes'])) {
            return '';
        }
        $best = 0;
        foreach ($meta['sizes'] as $sz) {
            if (!is_array($sz) || empty($sz['width']) || empty($sz['height'])) {
                continue;
            }
            $w = (int) $sz['width'];
            if ($w >= $target && ($best === 0 || $w < $best)) {
                $best = $w;
                $best_h = (int) $sz['height'];
            }
        }
        if ($best <= 0 || $best >= (int) $meta['width']) {
            return '';
        }
        $out = substr($url, 0, strpos($url, $path)) . $pm[1] . $pm[2] . '-' . $best . 'x' . $best_h . '.' . $pm[3] . ($q !== '' ? '?' . $q : '');
        return $memo[$url] = $out;
    }

    public static function wpc_lcp_autoderive_bg($html, $critBlob)
    {
        if (!is_string($html) || $html === '' || !apply_filters('wpc_lcp_autoderive', true)) {
            return null;
        }


        $off = PREG_OFFSET_CAPTURE | PREG_SET_ORDER;
        if (!preg_match_all('/class="[^"]*elementor-element-([0-9a-f]{4,})[^"]*"[^>]*data-element_type="container"[^>]*data-settings="([^"]*background_background[^"]*classic[^"]*)"/i', $html, $mm, $off)
            && !preg_match_all('/class="[^"]*elementor-element-([0-9a-f]{4,})[^"]*"[^>]*data-settings="([^"]*background_background[^"]*classic[^"]*)"/i', $html, $mm, $off)) {
            return null;
        }


        $bodyPos   = stripos($html, '<body');
        $bodyStart = ($bodyPos !== false) ? $bodyPos : 0;
        $bodyLen   = strlen($html) - $bodyStart;
        $id = '';
        foreach ($mm as $m) {
            // Decoy skip #1: the matched container names itself as chrome (menu/nav/header).
            if (preg_match('/menu|navbar|nav-|site-header|elementor-location-header|sticky-header/i', $m[0][0])) {
                continue;
            }
            // Decoy skip #2: the candidate sits inside an unclosed <header>/<nav> → chrome, not the LCP.
            // (strripos→false casts to 0; a real header/nav open never lives at byte 0.)
            $before   = substr($html, 0, $m[0][1]);
            $navOpen  = max((int) strripos($before, '<header'), (int) strripos($before, '<nav'));
            $navClose = max((int) strripos($before, '</header'), (int) strripos($before, '</nav'));
            if ($navOpen > 0 && $navOpen > $navClose) {
                continue;
            }
            // ATF: must sit in the top ~55% of the BODY.
            if ($bodyLen > 0 && ($m[0][1] - $bodyStart) > (int) ($bodyLen * 0.55)) {
                continue;
            }
            $id = $m[1][0];
            break;
        }
        if ($id === '') {
            return null;
        }
        $sel = '.elementor-element-' . $id;
        // The container's own bg rule → image url. Prefer the crit form (byte-match the paint).

        // v6-bg-fill.svg on the classic-bg <main>), so autoderive reported "no classic-bg hero

        // SVG bg is a first-class LCP paint; preloading it is exactly as valid as a raster.

        // (hero.jpg?v=3) and the old end-anchor dropped the match; capture the query so the preload

        $rx  = '/\.elementor-element-' . preg_quote($id, '/') . '(?![0-9a-fx])[^{]*\{[^}]*background-image\s*:\s*url\(\s*["\']?([^"\')\s]+\.(?:jpe?g|png|webp|avif|svg)(?:[?#][^"\')\s]*)?)["\']?\s*\)/i';
        $url = '';
        if (is_string($critBlob) && $critBlob !== '' && preg_match($rx, $critBlob, $cm)) {
            $url = $cm[1];
        } elseif (preg_match($rx, $html, $hm)) {
            $url = $hm[1];
        }
        if ($url === '') {
            return null;
        }
        return ['type' => 'bg', 'url' => $url, 'sel' => $sel, 'css_w' => 0, 'css_h' => 0];
    }


    public static function logo_rightsize($html, $ctx = null)
    {
        if (!is_string($html) || $html === '' || stripos($html, 'logo') === false
            || !apply_filters('wpc_logo_rightsize', true)) {
            return $html;
        }
        // A missing width/height comes from the image-sizing owner, for the file the tag loads.
        // This pass used to take the largest srcset candidate's -WxH, the dims of a file the tag
        // does not serve at that box.
        $logoDims = function ($tag) use ($ctx) {
            $src = preg_match('/\ssrc="([^"]*)"/i', $tag, $sm) ? $sm[1] : '';
            $class = preg_match('/\sclass="([^"]*)"/i', $tag, $cm) ? $cm[1] : '';
            $style = preg_match('/\sstyle="([^"]*)"/i', $tag, $stm) ? $stm[1] : '';
            $dims = ($ctx !== null && isset($ctx->imageSizing))
                ? $ctx->imageSizing->dimsFor($src, $class, true, $style)
                : (class_exists('wps_ic_image_sizing') ? wps_ic_image_sizing::fileDims($src, $class, true, $style) : null);
            return $dims === null ? [0, 0] : [(int) round($dims['w']), (int) round($dims['h'])];
        };
        $out = preg_replace_callback('/<img\b[^>]*>/i', function ($m) use ($logoDims) {
            $tag = $m[0];

            // nd/modern tags own their loading + sizes policy — this pass re-lazified the
            // header logo, stripped fetchpriority, and re-added the auto prefix the
            // .348-.350 arc removed (liam root; the FOURTH emitter).
            if (stripos($tag, 'data-wpc-nd') !== false || stripos($tag, 'data-wpc-md') !== false) {
                return $tag;
            }

            if (!preg_match('/\s(?:src|srcset)="[^"]*logo[^"]*"/i', $tag)) {
                return $tag;
            }
            if (stripos($tag, 'srcset=') === false) {
                return $tag;
            }
            $over = (stripos($tag, 'loading="eager"') !== false)
                || (stripos($tag, 'fetchpriority="high"') !== false)
                || preg_match('/sizes="[^"]*100vw[^"]*"/i', $tag);
            if (!$over) {


                if (!preg_match('/\bwidth\s*=\s*["\']?\d+/i', $tag) || !preg_match('/\bheight\s*=\s*["\']?\d+/i', $tag)) {
                    list($logoWidth, $logoHeight) = $logoDims($tag);
                    if ($logoWidth > 0 && $logoHeight > 0) {
                        $sizedTag = preg_replace('/<img\b/i', '<img width="' . $logoWidth . '" height="' . $logoHeight . '"', $tag, 1);
                        if (is_string($sizedTag)) { return $sizedTag; }
                    }
                }
                return $tag;
            }
            if (stripos($tag, 'id="wpc-lcp') !== false) {
                return $tag;
            }


            $wpc_lw = preg_match('/\bwidth\s*=\s*["\']?(\d+)/i', $tag, $widthMatch) ? (int) $widthMatch[1] : 0;
            $wpc_lh = preg_match('/\bheight\s*=\s*["\']?(\d+)/i', $tag, $heightMatch) ? (int) $heightMatch[1] : 0;
            $wpc_inject_dims = '';
            if ($wpc_lw <= 0 || $wpc_lh <= 0) {
                list($logoWidth, $logoHeight) = $logoDims($tag);
                if ($logoWidth > 0 && $logoHeight > 0) {
                    $wpc_inject_dims = ' width="' . $logoWidth . '" height="' . $logoHeight . '"';
                } else {
                    return $tag;
                }
            }
            $o = $tag;
            $o = preg_replace('/\bloading="[^"]*"/i', 'loading="lazy"', $o, 1, $lc);
            if ($lc === 0) {
                $o = preg_replace('/<img\b/i', '<img loading="lazy"', $o, 1);
            }
            $o = preg_replace('/\s*fetchpriority="[^"]*"/i', '', $o);
            if (preg_match('/\bsizes="([^"]*)"/i', $o, $sizesMatch)) {

                $lazySizes = (stripos($sizesMatch[1], 'auto') === false && trim($sizesMatch[1]) !== '')
                    ? 'auto, ' . $sizesMatch[1] : 'auto';
                $o = preg_replace('/\bsizes="[^"]*"/i', 'sizes="' . str_replace('$', '\\$', $lazySizes) . '"', $o, 1);
            } else {
                $o = preg_replace('/\bsrcset=/i', 'sizes="auto" srcset=', $o, 1);
            }
            if ($wpc_inject_dims !== '') {
                $o = preg_replace('/<img\b/i', '<img' . $wpc_inject_dims, $o, 1);
            }
            return is_string($o) ? $o : $tag;
        }, $html);
        return is_string($out) ? $out : $html;
    }


    public static function wpc_lcp_repair_cio_transform($basename)
    {
        if (!defined('WPS_IC_CSS') || $basename === '' || !is_dir(WPS_IC_CSS)
            || !apply_filters('wpc_lcp_repair_cio', true)) {
            return 0;
        }
        $files = (array) glob(rtrim(WPS_IC_CSS, '/') . '/*.css');
        if (count($files) > 200) {
            usort($files, function ($a, $b) { return @filemtime($b) <=> @filemtime($a); });
            $files = array_slice($files, 0, 200);
        }
        $rx = '#https?://[a-z0-9.-]+/(?:q:[a-z0-9]+/)?r:\d+/wp:\d+/w:1/u:(https?://[^"\'()\s]*' . preg_quote($basename, '#') . ')#i';
        $n  = 0;
        foreach ($files as $f) {
            $c = @file_get_contents($f);
            if (!is_string($c) || stripos($c, $basename) === false || stripos($c, '/w:1/u:') === false) {
                continue;
            }
            $r = preg_replace($rx, '$1', $c);
            if (is_string($r) && $r !== $c) {
                $tmp = $f . '.tmp-' . getmypid();
                if (wpc_fs_put($tmp, $r) !== false && @rename($tmp, $f)) {
                    $n++;
                } else {
                    @unlink($tmp);
                }
            }
        }
        return $n;
    }


    // Durable site-wide heal budget (30/h): the per-URL transient counters reset on cache flush
    public static function wpc_lcp_heal_budget_ok()
    {
        $b = get_option('wpc_lcp_heal_budget');
        $h = (int) floor(time() / 3600);
        if (!is_array($b) || (int) ($b['h'] ?? 0) !== $h) {
            $b = ['h' => $h, 'n' => 0];
        }
        if ((int) $b['n'] >= 30) {
            return false;
        }
        $b['n'] = (int) $b['n'] + 1;
        update_option('wpc_lcp_heal_budget', $b, false);
        return true;
    }

    public static function wpc_lcp_painted_form($basename, $cleanUrl)
    {
        if (!defined('WPS_IC_CSS') || $basename === '' || !is_dir(WPS_IC_CSS)) {
            return '';
        }
        $tkey = 'wpc_painted_' . substr(md5($basename . '|' . (string) get_option('css_hash')), 0, 24);
        $hit  = get_transient($tkey);
        if (is_string($hit)) {
            return $hit === (string) $cleanUrl ? '' : $hit;
        }
        // Durable sweep floor: a flushed object cache must not re-pay the ≤120-file scan per render
        $wpc_psw = (int) get_option('wpc_painted_sweep_at');
        if (time() - $wpc_psw < 60) {
            return '';
        }
        update_option('wpc_painted_sweep_at', time(), false);
        $found = '';
        $n     = 0;
        foreach ((array) glob(rtrim(WPS_IC_CSS, '/') . '/*.css') as $f) {
            if ($n++ > 120) {
                break;
            }
            $c = @file_get_contents($f);
            if (!is_string($c) || stripos($c, $basename) === false) {
                continue;
            }
            if (preg_match('#url\(\s*["\']?([^"\')\s]*' . preg_quote($basename, '#') . '(?:\?[^"\')\s]*)?)["\']?\s*\)#i', $c, $m)) {
                $u = html_entity_decode($m[1]);
                if (strpos($u, '/w:1/u:') !== false || strpos($u, '/u:http') !== false) {
                    $found = $u;
                    break;
                }
                if ($found === '') {
                    $found = $u;
                }
            }
        }
        set_transient($tkey, $found, 6 * HOUR_IN_SECONDS);
        return ($found === (string) $cleanUrl) ? '' : $found;
    }


    public static function wpc_lcp_sized_sibling($cssUrl, $needW, $needH)
    {
        $needW = (int) $needW;
        $needH = (int) $needH;
        if ($needW < 1 || !is_string($cssUrl) || $cssUrl === '') {
            return '';
        }
        $path = (string) parse_url($cssUrl, PHP_URL_PATH);
        $upos = stripos($path, '/wp-content/uploads/');
        if ($upos === false) {
            return '';
        }
        $file = basename($path);
        if (!preg_match('/^(.+)\.(webp|avif|png|jpe?g)$/i', $file, $fm)) {
            return '';
        }
        if (preg_match('/-\d+x\d+$/', $fm[1])) {
            return '';
        }
        if (!function_exists('wp_get_upload_dir')) {
            return '';
        }
        $up  = wp_get_upload_dir();
        $rel = substr($path, $upos + strlen('/wp-content/uploads/'));
        $dir = rtrim((string) $up['basedir'], '/') . '/' . ltrim(dirname($rel), '/');
        if (!is_dir($dir)) {
            return '';
        }
        $glob = glob($dir . '/' . $fm[1] . '-*x*.' . $fm[2]);
        if (empty($glob)) {
            return '';
        }


        $maxUp = (float) apply_filters('wpc_lcp_bg_max_upscale', 2.0);
        $bestSuff  = '';
        $bestSuffW = PHP_INT_MAX;
        $bestTol   = '';
        $bestTolW  = 0;
        foreach ($glob as $cand) {
            $cf = basename($cand);
            if (!preg_match('/-(\d+)x(\d+)\.' . preg_quote($fm[2], '/') . '$/i', $cf, $cm)) {
                continue;
            }
            $sw = (int) $cm[1];
            $sh = (int) $cm[2];
            if ($sw < 1 || $sh < 1 || @filesize($cand) < 1) {
                continue;
            }

            $up_w  = $needW / $sw;
            $up_h  = $needH > 0 ? ($needH / $sh) : 0;
            $scale = max($up_w, $up_h);
            if ($scale <= 1 && $sw < $bestSuffW) {
                $bestSuffW = $sw;
                $bestSuff  = $cf;
            } elseif ($scale > 1 && $scale <= $maxUp && $sw > $bestTolW) {
                $bestTolW = $sw;
                $bestTol  = $cf;
            }
        }
        $best = $bestSuff !== '' ? $bestSuff : $bestTol;
        if ($best === '') {
            return '';
        }
        return str_replace('/' . $file, '/' . $best, $cssUrl);
    }

    public function maybeInlineGoogleFontFaces($html, $criticalCss)
    {


        $preloadCritFonts = isset(self::$settings['preload-crit-fonts']) ? (string) self::$settings['preload-crit-fonts'] : '';
        if ($preloadCritFonts === '' && isset(self::$settings['replace-fonts']) && self::$settings['replace-fonts'] === 'local'
            && apply_filters('wpc_atf_faces_auto', true)) {
            $preloadCritFonts = '1';
        }
        if ($preloadCritFonts !== '1') return '';


        $rf = isset(self::$settings['replace-fonts']) ? (string) self::$settings['replace-fonts'] : '';
        if ($rf === 'local') return $this->inlineLocalAtfFaces($criticalCss, $html);
        if ($rf !== '') return '';
        if (!is_string($html) || stripos($html, 'fonts.googleapis.com/css') === false) return '';
        if (!preg_match_all('/<link\b[^>]*href=["\']([^"\']*fonts\.googleapis\.com\/css[^"\']*)["\']/i', $html, $lm)) return '';
        $urls = array_values(array_unique($lm[1]));


        list($atfFamilies, $atfPairs) = self::atfFontsFromCss($criticalCss, $html);
        if (empty($atfFamilies)) return '';

        // Read cached faces; warm any cold googleapis URL exactly once, post-response.
        $faces = [];
        $cold = [];
        foreach ($urls as $u) {
            $cached = get_transient('wpc_gff_' . md5($u));
            if ($cached === false) { $cold[] = $u; continue; }
            if (is_array($cached)) $faces = array_merge($faces, $cached);
        }
        if (!empty($cold) && get_transient('wpc_gff_warming') === false && function_exists('register_shutdown_function')) {
            set_transient('wpc_gff_warming', 1, 90);
            register_shutdown_function(['wps_rewriteLogic', 'gfontWarm'], $cold);
        }
        if (empty($faces)) return '';


        $atf_inline_cap = (int) apply_filters('wpc_atf_inline_faces_cap', 24);
        $keep = self::pickAtfFaces($faces, $atfFamilies, $atfPairs, $atf_inline_cap);
        if (empty($keep)) return '';


        $faceDisplay = (string) apply_filters('wpc_atf_face_display', 'swap');
        if ($faceDisplay !== '' && preg_match('/^[a-z-]+$/', $faceDisplay)) {
            foreach ($keep as $wpc_ki => $wpc_kf) {
                // A face carrying two font-display decls resolves to the LAST — strip all, set once.
                $wpc_kf = preg_replace('/font-display\s*:\s*[^;}]+;?/i', '', $wpc_kf);
                $keep[$wpc_ki] = preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $faceDisplay . ';', $wpc_kf, 1);
            }
        }

        return '<style id="wpc-gfont-atf">' . implode('', $keep) . '</style>';
    }

    /**
     * Post-response warmer (FPM-safe): fetch each googleapis CSS with a modern-Chrome UA (so Google returns
     * woff2), parse the @font-face blocks, cache them. Runs at shutdown so it never delays the render.
     */
    public static function gfontWarm($urls)
    {
        if ((function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'))) { wpc_finish_request(); }
        if (function_exists('wpc_bg_slot_take') && !wpc_bg_slot_take('gfont-warm')) {
            if (function_exists('delete_transient')) { delete_transient('wpc_gff_warming'); }
            return;
        }
        foreach ((array) $urls as $u) {
            $key = 'wpc_gff_' . md5($u);
            if (get_transient($key) !== false) continue;
            $css = '';


            $fetchUrl = html_entity_decode((string) $u, ENT_QUOTES);
            if (strpos($fetchUrl, 'wght@') !== false) {
                $fetchUrl = preg_replace('#(fonts\.googleapis\.com)/css\?#i', '$1/css2?', $fetchUrl, 1);
            }
            if (function_exists('wp_remote_get')) {
                $resp = wp_remote_get($fetchUrl, ['timeout' => 8, 'redirection' => 3, 'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    'Accept'     => 'text/css,*/*;q=0.1',
                ]]);
                if (!is_wp_error($resp)) {
                    $code = (int) wp_remote_retrieve_response_code($resp);
                    if ($code >= 200 && $code < 300) $css = (string) wp_remote_retrieve_body($resp);
                }
            }
            $faces = self::gfontParseFaces($css);
            // Success -> cache a week; transient failure -> short TTL so it retries soon.
            set_transient($key, $faces, !empty($faces) ? WEEK_IN_SECONDS : HOUR_IN_SECONDS);
        }
        delete_transient('wpc_gff_warming');
    }

    /** Parse googleapis @font-face blocks -> [family(lc), weight, latin(bool), raw woff2 block w/ display:swap]. */
    private static function gfontParseFaces($css, $display = 'swap')
    {
        $out = [];
        if (!is_string($css) || $css === '') return $out;
        if (!preg_match_all('/@font-face\s*\{[^}]*\}/is', $css, $blocks)) return $out;
        foreach ($blocks[0] as $raw) {
            if (stripos($raw, '.woff2') === false) continue;
            if (!preg_match('/font-family\s*:\s*([\'"]?)([^;\'"]+)\1/i', $raw, $fm)) continue;
            $family = strtolower(trim($fm[2]));
            $weight = '400';
            if (preg_match('/font-weight\s*:\s*([^;]+)/i', $raw, $wm)) {
                $w = strtolower(trim($wm[1]));
                if ($w === 'normal') $weight = '400';
                elseif ($w === 'bold') $weight = '700';
                else { $d = preg_replace('/\D/', '', $w); $weight = ($d !== '') ? $d : '400'; }
            }
            $style = (preg_match('/font-style\s*:\s*(italic|oblique)/i', $raw)) ? 'italic' : 'normal';
            $latin = true;
            if (preg_match('/unicode-range\s*:\s*([^;}]+)/i', $raw, $um)) {
                $latin = (stripos($um[1], 'U+0000') !== false || stripos($um[1], 'U+00-') !== false || stripos($um[1], 'U+0-') !== false);
            }


            $clean = trim($raw);
            if ($display !== '') {
                $clean = preg_replace('/font-display\s*:\s*[^;]+;?/i', '', $clean);
                $clean = preg_replace('/@font-face\s*\{/i', '@font-face{font-display:' . $display . ';', $clean, 1);
            }
            $out[] = ['family' => $family, 'weight' => $weight, 'style' => $style, 'latin' => $latin, 'raw' => $clean];
        }
        return $out;
    }


    public function inlineLocalAtfFaces($criticalCss, $html = '')
    {
        if (!defined('WPS_IC_FONTS_MAP') || !defined('WPS_IC_FONTS_DIR') || !defined('WPS_IC_FONTS_URL')) return '';
        if (!function_exists('get_option')) return '';


        list($atfFamilies, $atfPairs) = self::atfFontsFromCss($criticalCss, $html);
        if (empty($atfFamilies)) return '';

        $map = get_option(WPS_IC_FONTS_MAP);
        if (!is_array($map) || empty($map)) return '';

        // Collect @font-face from the on-disk localized stylesheets (woff2-only; gfontParseFaces forces swap).
        $faces = [];
        foreach ($map as $rd) {
            if (empty($rd['dir']) || empty($rd['filename'])) continue;
            $cssFile = WPS_IC_FONTS_DIR . $rd['dir'] . '/' . $rd['filename'];
            if (!is_readable($cssFile)) continue;
            $css = @file_get_contents($cssFile);
            if (!is_string($css) || $css === '') continue;


            foreach (self::gfontParseFaces($css, (string) apply_filters('wpc_atf_face_display', 'swap')) as $f) {
                if (!self::localFaceWoff2Exists($f['raw'])) continue;
                $faces[] = $f;
            }
        }
        if (empty($faces)) return '';


        $atf_inline_cap = (int) apply_filters('wpc_atf_inline_faces_cap', 24);
        $keep = self::pickAtfFaces($faces, $atfFamilies, $atfPairs, $atf_inline_cap);
        if (empty($keep)) return '';

        return '<style id="wpc-gfont-atf-local">' . implode('', $keep) . '</style>';
    }

    /** True if a localized @font-face block's woff2 (a WPS_IC_FONTS_URL url) exists on disk. */
    private static function localFaceWoff2Exists($rawFace)
    {
        if (!preg_match('/url\(\s*[\'"]?([^)\'"]+?\.woff2)/i', $rawFace, $m)) return false;
        $url = $m[1];
        if (strpos($url, WPS_IC_FONTS_URL) === false) return false;
        $path = strtok(str_replace(WPS_IC_FONTS_URL, WPS_IC_FONTS_DIR, $url), '?');
        $exists = is_string($path) && $path !== '' && file_exists($path);
        // The localizer's map (an option) names a woff2 the disk no longer has; the face is not
        // inlined. Never sampled: the map and the files have diverged.
        if (!$exists && function_exists('wpc_render_belt_note')) {
            wpc_render_belt_note('atf-local-face-missing', ['n' => 1, 'file' => substr(basename((string) $path), 0, 80)]);
        }
        return $exists;
    }


    // v7.21.225 — ONE RESTORE REQUEST, NOT 35. The parked lane re-fetches every original
    // sheet individually post-paint (bestexteriorsinc: 35 rows, 84-request report). Combine
    // the parked SAME-HOST wp-content/wp-includes sheets into one content-keyed cached file
    // and park THAT single link instead. Relative url()s are absolutized per source sheet;
    // sheets carrying @import, non-all media, foreign hosts, or unreadable paths keep their
    // own tag. Key includes path+mtime+href so any sheet update mints a new combined file.
    // v7.21.225 — CRIT-LANE URLS MUST SPELL LIKE THE DELIVERY LANE. GTmetrix receipt:
    // 13 images downloaded twice (addd.png 611KB x2) because the crit blob's url()s carry
    // the ORIGIN spelling while img tags carry the CDN form — different strings, no cache
    // reuse. URL-pass only (P0 law: never the minifier): swap the host of same-site
    // wp-content/uploads IMAGE url()s to the zone via uForCdn. Fonts stay natural origin
    // (their own contract); data:/foreign/quoted structure untouched.
    public static function wpc_align_crit_urls_to_zone($css)
    {
        try {
            if (!is_string($css) || $css === '' || empty(self::$zoneName)
                || !apply_filters('wpc_crit_url_align', true) || stripos($css, 'url(') === false) {
                return $css;
            }
            $aligned = 0;
            $out = preg_replace_callback('/url\(\s*([\'"]?)(https?:\/\/[^\'")\s]+\.(?:png|jpe?g|gif|webp|avif|svg))(\1)\s*\)/i', function ($m) use (&$aligned) {
                $u = self::uForCdn($m[2]);
                if (is_string($u) && $u !== '' && $u !== $m[2]) {
                    $aligned++;
                }
                return is_string($u) && $u !== '' ? 'url(' . $m[1] . $u . $m[3] . ')' : $m[0];
            }, $css);
            // The crit is built on origin bytes and the delivery lane rewrites the <img>: two URL
            // owners, so the crit's image urls are respelled here. Sampled: every render of a
            // zoned page with crit background images respells the same urls.
            if ($aligned > 0 && is_string($out) && function_exists('wpc_render_belt_note')) {
                wpc_render_belt_note('crit-urls-aligned', ['n' => $aligned], true);
            }
            return is_string($out) ? $out : $css;
        } catch (\Throwable $e) {
            return $css;
        }
    }

    public function wpc_combine_parked_css($html)
    {
        try {
            if (!apply_filters('wpc_parked_css_combine', true)
                || !defined('WPS_IC_CRITICAL') || !defined('WPS_IC_CRITICAL_URL')
                || !function_exists('home_url') || !defined('ABSPATH')) {
                return $html;
            }
            if (!preg_match_all('/<link\b[^>]*(?:rel|type)=["\']wpc-(mobile|late)-stylesheet["\'][^>]*>/i', $html, $parkedLinkMatches)) {
                $parkedLinkMatches = [[], []];
            }
            $minSheets = (int) apply_filters('wpc_parked_css_combine_min', 4);
            $homeHost = strtolower((string) parse_url(home_url('/'), PHP_URL_HOST));
            // v7.21.279 — CSS-via-CDN sites (css:1) serve sheet links from the ZONE host; the
            // home-host gate skipped every one before the local-twin check ran, so the combine
            // and park lanes stood down and ~35 originals rode the wire individually (staging
            // receipt). Our own zone hosts are combinable: the path is preserved on zone urls
            // and the readable-local-twin check below stays the proof. Foreign hosts stay out.
            $zoneHosts = [];
            if (!empty(self::$zoneName)) { $zoneHosts[strtolower((string) self::$zoneName)] = 1; }
            foreach (['ic_custom_cname', 'ic_cdn_zone_name'] as $zoneOption) {
                $zoneHost = function_exists('get_option') ? strtolower(trim((string) get_option($zoneOption))) : '';
                if ($zoneHost !== '') { $zoneHosts[$zoneHost] = 1; }
            }
            // v7.21.230 — the demoted PRELOAD form fetches immediately (20 rows at 186ms:
            // rel=preload as=style fetchpriority=low, our own deferral ride). The droplist's
            // late-demote leg is critfresh-gated and a plugin update's stale-mark can park it
            // OFF — so the combine swallows these too, into the late lane.
            $candidateTags = [];
            foreach ($parkedLinkMatches[0] as $matchIndex => $linkTag) {
                $candidateTags[] = [$linkTag, strtolower((string) $parkedLinkMatches[1][$matchIndex])];
            }
            if (preg_match_all('/<link\b[^>]*rel=["\'](?:preload|prefetch)["\'][^>]*>/i', $html, $preloadMatches)) {
                foreach ($preloadMatches[0] as $preloadTag) {
                    if (preg_match('/as=["\']style["\']/i', $preloadTag)
                        && (preg_match('/fetchpriority=["\']low["\']/i', $preloadTag) || preg_match('/rel=["\']prefetch["\']/i', $preloadTag))
                        && stripos($preloadTag, 'onload=') === false) {
                        $candidateTags[] = [$preloadTag, 'late'];
                    }
                }
            }
            $partsByLane = [];
            foreach ($candidateTags as $tagEntry) {
                $linkTag = $tagEntry[0];
                $lane = $tagEntry[1];
                if (stripos($linkTag, 'fonts.googleapis') !== false || stripos($linkTag, 'fonts.bunny') !== false) {
                    continue;
                }
                if (preg_match('/media=["\'](?!all["\'])/i', $linkTag)) {
                    continue;
                }
                if (!preg_match('/href=["\']([^"\']+)["\']/i', $linkTag, $hrefMatch)) {
                    continue;
                }
                $hrefParts = parse_url((string) $hrefMatch[1]);
                $hrefHost = isset($hrefParts['host']) ? strtolower((string) $hrefParts['host']) : '';
                if ($hrefHost !== '' && $hrefHost !== $homeHost
                    && !isset($zoneHosts[$hrefHost]) && substr($hrefHost, -10) !== '.zapwp.com') {
                    continue;
                }
                $hrefPath = isset($hrefParts['path']) ? (string) $hrefParts['path'] : '';
                if (!preg_match('#^/(?:wp-content|wp-includes)/#', $hrefPath)
                    || strpos($hrefPath, '..') !== false || substr($hrefPath, -4) !== '.css') {
                    continue;
                }
                $sheetFile = rtrim(ABSPATH, '/') . $hrefPath;
                $sheetSize = @is_readable($sheetFile) ? (int) @filesize($sheetFile) : 0;
                if ($sheetSize <= 0 || $sheetSize > 524288) {
                    continue;
                }
                $partsByLane[$lane][] = ['tag' => $linkTag, 'fp' => $sheetFile, 'web' => $hrefPath,
                    'mt' => (int) @filemtime($sheetFile), 'u' => (string) $hrefMatch[1]];
            }
            foreach ($partsByLane as $lane => $laneParts) {
                foreach (self::wpc_split_combine_runs($html, $laneParts) as $combineRun) {
                    $html = self::wpc_combine_css_lane($html, $lane, $combineRun, $minSheets);
                }
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    // v7.22.21 — A COMBINE MUST NOT MOVE A RULE PAST A SHEET IT DID NOT ABSORB. The bundle
    // lands at its LAST member (.325), so every earlier member's rules sink below any
    // stylesheet or site <style> that sat between them — columbuschiropractors: FA5
    // all.min.css (member) natively precedes the FA4 sheet (kept live); combined, FA5's
    // `.fa{font-family:"Font Awesome 5 Free";font-weight:900}` landed AFTER FA4's
    // `.fa{font-family:FontAwesome}` and every header icon switched typeface. Members are
    // split into runs at every non-member stylesheet link or non-wpc <style>, and each
    // run combines on its own, so the site's cascade order is preserved exactly.
    private static function wpc_split_combine_runs($html, $parts)
    {
        $pos = [];
        foreach ($parts as $i => $p) {
            $o = strpos($html, $p['tag']);
            if ($o !== false) {
                $pos[$i] = $o;
            }
        }
        if (count($pos) < 2) {
            return [$parts];
        }
        asort($pos);
        $own = [];
        foreach ($parts as $p) {
            $own[$p['tag']] = 1;
        }
        $cuts = [];
        if (preg_match_all('/<link\b[^>]*(?:rel|type)=["\'][^"\']*stylesheet[^"\']*["\'][^>]*>|<style\b[^>]*>/i', $html, $im, PREG_OFFSET_CAPTURE)) {
            foreach ($im[0] as $m) {
                if (isset($own[$m[0]])) {
                    continue;
                }
                if (stripos($m[0], '<style') === 0 && preg_match('/\bid=["\']wpc-/i', $m[0])) {
                    continue;
                }
                $cuts[] = (int) $m[1];
            }
        }
        sort($cuts);
        $runs = [];
        $cur  = [];
        $prev = -1;
        foreach ($pos as $i => $o) {
            if ($prev >= 0) {
                foreach ($cuts as $c) {
                    if ($c > $prev && $c < $o) {
                        $runs[] = $cur;
                        $cur = [];
                        break;
                    }
                }
            }
            $cur[] = $parts[$i];
            $prev  = $o;
        }
        if ($cur) {
            $runs[] = $cur;
        }
        return $runs;
    }

    private static function wpc_combine_css_lane($html, $lane, $parts, $minSheets)
    {
        try {
            if (count($parts) < $minSheets) {
                return $html;
            }
            $partSignatures = [];
            foreach ($parts as $part) {
                // v7.22.81 — THE QUERY STRING IS DELIVERY, NOT CONTENT. 'u' is the full href,
                // which carries ?icv= (cdn-rewrite.php:895); WPS_IC_HASH is $options['css_hash']
                // (:7865), and purgeCombinedFiles() rotates css_hash on every purge. So every
                // purge rotated EVERY key here, the next render wrote a complete new cmb-* set
                // (base + .keep + the .faces/.nofaces twins), and the purge then cleaned
                // WPS_IC_COMBINE — a different directory — leaving the old set forever. One
                // low-traffic staging site: 1.31GB / 2,494 files in 12 days, 714 of them from a
                // single purge event. 'web' (the path, already carrying the wp-cio marker md5)
                // plus 'mt' (filemtime) is a complete content identity on its own, and icv is
                // never baked into the combined bytes (measured: 0 occurrences in all three
                // live artifacts), so the query contributes nothing but volatility.
                $partSignatures[] = $part['web'] . ':' . $part['mt'] . ':' . strtok((string) $part['u'], '?');
            }
            // v7.21.261 — bgp261 salt: the combined file is now bg-parked at write, so the
            // derived artifact's content changed and the key must change with it (a stale
            // cmb under the old key would serve unparked bgs forever).
            $bundleKey = md5($lane . '|bgp261|iso|' . implode('|', $partSignatures));
            $bundleDir = rtrim(WPS_IC_CRITICAL, '/') . '/combined/';
            $bundleFile = $bundleDir . 'cmb-' . $bundleKey . '.css';
            $keptParts = [];
            if (!@is_readable($bundleFile)) {
                $bundleCss = '';
                $totalBytes = 0;
                foreach ($parts as $partIndex => $part) {
                    $partCss = (string) @file_get_contents($part['fp']);
                    if ($partCss === '' || stripos($partCss, '@import') !== false) {
                        $keptParts[$partIndex] = 1;
                        continue;
                    }
                    $totalBytes += strlen($partCss);
                    if ($totalBytes > 2097152) {
                        return $html;
                    }
                    $partCss = preg_replace('/@charset[^;]*;/i', '', $partCss);
                    $sheetDir = dirname($part['web']);
                    $partCss = preg_replace_callback('/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i', function ($mm) use ($sheetDir) {
                        $v = trim($mm[2]);
                        if ($v === '' || $v[0] === '/' || $v[0] === '#' || preg_match('#^(?:data:|https?:|//)#i', $v)) {
                            return $mm[0];
                        }
                        $full = $sheetDir . '/' . $v;
                        $g = 0;
                        while (strpos($full, '/../') !== false && $g++ < 12) {
                            $full = preg_replace('#/[^/]+/\.\./#', '/', $full, 1);
                        }
                        return 'url("' . $full . '")';
                    }, $partCss);
                    if (!is_string($partCss)) {
                        return $html;
                    }
                    $bundleCss .= (function_exists('wpc_css_isolate_sheet') ? wpc_css_isolate_sheet($partCss) : $partCss) . "\n";
                }
                if (count($parts) - count($keptParts) < $minSheets) {
                    return $html;
                }
                if (!is_dir($bundleDir) && function_exists('wp_mkdir_p')) {
                    @wp_mkdir_p($bundleDir);
                }
                // v7.21.261 — CSS-image park for the COMBINE lane too. On a site whose
                // used-css has not landed (service ABSENT), the parked originals restore
                // post-paint via this combined file and every background url() fetched with
                // zero interaction (columbuschiropractors: 643KB Section-11 tail, 59 rows).
                // Same parker, same armer class; the loader arms on gesture/scrolled boot.
                if (function_exists('wpc_park_used_css_backgrounds')) {
                    $bundleCss = (string) wpc_park_used_css_backgrounds($bundleCss);
                }
                if (wpc_fs_put($bundleFile . '.tmp', $bundleCss, LOCK_EX) === false) {
                    return $html;
                }
                @rename($bundleFile . '.tmp', $bundleFile);
                if (!empty($keptParts)) {
                    wpc_fs_put($bundleFile . '.keep', wp_json_encode(array_keys($keptParts)), LOCK_EX);
                }
            } else {
                $keptPartsJson = json_decode((string) @file_get_contents($bundleFile . '.keep'), true);
                if (is_array($keptPartsJson)) {
                    $keptParts = array_fill_keys(array_map('intval', $keptPartsJson), 1);
                }
            }
            $bundleUrl = rtrim(WPS_IC_CRITICAL_URL, '/') . '/combined/cmb-' . $bundleKey . '.css';
            $absorbedCount = count($parts) - count($keptParts);
            $bundleLink = '<link rel="wpc-' . $lane . '-stylesheet" id="wpc-cmb225-' . $lane . '" href="' . esc_url($bundleUrl) . '" media="all" data-wpc-n="' . (int) $absorbedCount . '" />';
            // v7.21.325 — CASCADE POSITION IS THE LAST ABSORBED SHEET'S (falknerei service
            // receipt: cmb bundles hoisted BEFORE divi-style-parent-inline + style.css, so at
            // equal specificity the theme defaults beat every absorbed customizer override —
            // Manrope->Open Sans x185, colors x155, gallery grid sizing lost). Replacing the
            // FIRST absorbed tag moves every absorbed rule UP past non-absorbed inline blocks;
            // occupying the LAST absorbed tag's slot preserves the override direction (theme
            // pattern: defaults first, overrides later — absorbed overrides must never move
            // earlier). Relative order among absorbed parts is the concatenation order (R3).
            $lastAbsorbedIndex = -1;
            foreach ($parts as $partIndex => $part) {
                if (!isset($keptParts[$partIndex])) {
                    $lastAbsorbedIndex = $partIndex;
                }
            }
            foreach ($parts as $partIndex => $part) {
                if (isset($keptParts[$partIndex])) {
                    continue;
                }
                $html = str_replace($part['tag'], $partIndex === $lastAbsorbedIndex ? $bundleLink : '', $html);
            }
            if (function_exists('wpc_cache_first_log') && !get_transient('wpc_cmb225log')) {
                set_transient('wpc_cmb225log', 1, 600);
                wpc_cache_first_log('parked-css-combined', '', '', ['n' => $absorbedCount, 'key' => substr($bundleKey, 0, 12)]);
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    public function wpc_used_css_droplist_pass($html)
    {
        // v7.10.542 — FIRST statement, before the guard and the try. If dh_entry holds the 40s,
        // the cost is in reaching/leaving this function, not in its body; if the guard label
        // holds it, the settings read is doing something unexpected. The 8 interior checkpoints
        // never fire, which no reading of the body explains.
        // v7.10.543 — THIS PASS IS AN OPTIMISATION AND MUST NEVER COST A VISITOR TIME.
        // Measured at 18-42s per render on the flagship (rx:dh_tplRead, 4/4). Dropping unused
        // CSS links is worth milliseconds, never seconds: past the budget we return the document
        // untouched and the page is correct, merely carrying a few more <link> tags. Bounds every
        // cause, including ones not yet identified.
        // ── THE LAYERING CONTRACT (locked with the service team, 2026-07-31) ─────────
        // 1. A rule may leave the crit only if the elements it affects still land in
        //    the same place without it — full paint equivalence above the fold,
        //    geometry equivalence below.
        // 2. Layer 2 (used-css) is non-blocking but NEVER delayed: it starts
        //    immediately and carries everything below the fold. Nothing style-related
        //    waits on load or interaction.
        // ─────────────────────────────────────────────────────────────────────────────
        $startedAt = microtime(true);
        $GLOBALS['wpc_droplist_budget_deadline'] = $startedAt
            + ((float) apply_filters('wpc_used_css_droplist_budget_ms', 400) / 1000);
        try {
            if (empty(self::$settings['used-css']) || self::$settings['used-css'] != '1'
                || !function_exists('wpc_used_css_path') || !defined('WPS_IC_CRITICAL_URL')) {
                return $html;
            }
            // v7.10.544 — criticalExists() defaults to $returnDir=FALSE, i.e. it returns the URL
            // variant. dirname() then yields a URL prefix and every subsequent file read here
            // becomes an HTTP loopback: used_tpl.txt has no writer anywhere, so it 302s, and
            // file_get_contents FOLLOWS redirects - rendering a full 708KB page to satisfy a
            // 20-byte text read, re-entering this same chain. TRUE returns the disk path.
            $critFiles = (new wps_criticalCss())->criticalExists(true);
            if (empty($critFiles['desktop'])) {
                return $html;
            }
            $critDir = dirname($critFiles['desktop']) . '/';
            // A render must NEVER open a network stream here: file_get_contents() on a URL uses
            // default_socket_timeout (60s by default) and bypasses the WP HTTP API, so it is
            // invisible to our http_n counter AND to the FPM slowlog. Local paths only.
            // Belt: with criticalExists(true) this can no longer be a URL. Kept so a future
            // caller change can never silently reintroduce a network read here.
            if (stripos($critDir, 'http:') === 0 || stripos($critDir, 'https:') === 0
                || strpos($critDir, '://') !== false) {
                return $html;
            }
            // v7.10.550 — used_tpl.txt has NO PRODUCER: never written by the plugin, and the
            // service confirms it is never written, never read, has no schema and no upload path.
            // The read always missed and always fell through to tpl.txt, which is the real key.
            $templateKey = @is_file($critDir . 'tpl.txt')
                ? trim((string) @file_get_contents($critDir . 'tpl.txt')) : '';
            if (microtime(true) > $GLOBALS['wpc_droplist_budget_deadline']) {
                if (function_exists('wpc_cache_first_log')) {
                    wpc_cache_first_log('droplist-budget-spent', '', '', ['at' => 'tplRead']);
                }
                return $html;
            }


            $usedCssFile = $templateKey !== '' ? wpc_used_css_path($templateKey, $this->isMobile() ? 'mobile' : 'desktop') : '';
            if ($usedCssFile === '' || !(@filesize($usedCssFile) > 64)) {
                $usedCssFile = $templateKey !== '' ? wpc_used_css_path($templateKey) : '';
            }
            if ($usedCssFile === '' || !(@filesize($usedCssFile) > 64)) {
                return $html;
            }
            // Liveness SIDECAR, not the artifact: rewriteLogic derives the bundle's
            // ?uv= cache-buster from its mtime (line ~3518), so touching the artifact
            // itself would rotate that URL daily — the exact disease .642 killed on
            // combine. The sidecar shares the family stem, so the retention sweep's
            // family rule reads it as liveness.
            if (function_exists('wpc_store_touch_if_stale')) {
                $livenessSidecar = $usedCssFile . '.live';
                if (!@is_file($livenessSidecar)) {
                    wpc_fs_put($livenessSidecar, '1');
                } else {
                    wpc_store_touch_if_stale($livenessSidecar);
                }
            }


            if (stripos($html, 'id="wpc-used-css"') === false && stripos($html, "id='wpc-used-css'") === false) {
                return $html;
            }
            if (microtime(true) > $GLOBALS['wpc_droplist_budget_deadline']) { return $html; }
            $usedCssSheets = function_exists('wpc_used_css_load_sheets') ? wpc_used_css_load_sheets($templateKey) : [];
            $absorbedSheets = [];
            foreach ($usedCssSheets as $sheet) {


                // disposition: 'keep' = leave serving, 'absorb' = defer/drop; legacy 'skip' honored when absent.
                $disposition = isset($sheet['disposition']) ? strtolower(trim((string) $sheet['disposition'])) : '';
                if ($disposition === 'keep' || ($disposition === '' && !empty($sheet['skip']))) {
                    continue;
                }
                if (!empty($sheet['url'])) {
                    $sheetBasename = strtok(basename((string) parse_url((string) $sheet['url'], PHP_URL_PATH)), '?');
                    if ($sheetBasename !== '' && $sheetBasename !== false) {
                        // Listed = absorbed: used.css carries its rules and the pair loads
                        // eagerly — the original never loads.
                        $absorbedSheets[strtolower($sheetBasename)] = 0;
                    }
                }
            }
            // Self-heal: the sheets manifest is a STATIC artifact whose URL we stored at the
            // last good land — a missing local copy re-fetches without any service compute.
            if (empty($absorbedSheets) && function_exists('wpc_used_css_sheets_path')
                && ((function_exists('wp_doing_ajax') && wp_doing_ajax())
                    || (defined('DOING_CRON') && DOING_CRON)
                    || !empty($_SERVER['HTTP_X_WPC_CACHE_WARM']))) {
                $sheetsManifestUrl = trim((string) @file_get_contents($critDir . 'used_css_sheets_url.txt'));
                if ($sheetsManifestUrl === '' || strpos($sheetsManifestUrl, 'http') !== 0) {
                    // Failed lands can empty the pointer file — the manifest sits beside the
                    // used.css artifact whose URL we also store; derive its sibling name.
                    foreach (['used_css_desktop_url.txt', 'used_css_mobile_url.txt'] as $usedCssUrlFile) {
                        $usedCssUrl = trim((string) @file_get_contents($critDir . $usedCssUrlFile));
                        if ($usedCssUrl !== '' && strpos($usedCssUrl, 'http') === 0
                            && preg_match('/^(.*tpl-[a-f0-9]{8,24})\.(?:desktop|mobile)\.css/i', $usedCssUrl, $usedCssUrlMatch)) {
                            $sheetsManifestUrl = $usedCssUrlMatch[1] . '.sheets.json';
                            break;
                        }
                    }
                }
                if ($sheetsManifestUrl !== '' && strpos($sheetsManifestUrl, 'http') === 0
                    && !get_transient('wpc_sheets_heal_' . md5($templateKey))) {
                    set_transient('wpc_sheets_heal_' . md5($templateKey), 1, 600);
                    $manifestResponse = wp_remote_get($sheetsManifestUrl, ['timeout' => 6]);
                    $manifestBody = !is_wp_error($manifestResponse) && (int) wp_remote_retrieve_response_code($manifestResponse) === 200
                        ? (string) wp_remote_retrieve_body($manifestResponse) : '';
                    $manifestSheets = json_decode($manifestBody, true);
                    if (is_array($manifestSheets) && isset($manifestSheets[0]['url'])) {
                        wpc_fs_put(wpc_used_css_sheets_path($templateKey), $manifestBody, LOCK_EX);
                        if (function_exists('wpc_cache_first_log')) {
                            wpc_cache_first_log('sheets-manifest-healed', (string) $this->urlKey, '', ['n' => count($manifestSheets)]);
                        }
                        foreach ($manifestSheets as $manifestSheet) {
                            if (!is_array($manifestSheet) || !empty($manifestSheet['skip'])) {
                                continue;
                            }
                            $manifestDisposition = isset($manifestSheet['disposition']) ? strtolower(trim((string) $manifestSheet['disposition'])) : '';
                            if ($manifestDisposition === 'keep') {
                                continue;
                            }
                            if (!empty($manifestSheet['url'])) {
                                $manifestBasename = strtok(basename((string) parse_url((string) $manifestSheet['url'], PHP_URL_PATH)), '?');
                                if ($manifestBasename !== '' && $manifestBasename !== false) {
                                    $absorbedSheets[strtolower($manifestBasename)] = 0;
                                }
                            }
                        }
                    }
                }
            }
            $haveSheetList = !empty($absorbedSheets) && apply_filters('wpc_used_css_droplist', true);

            // Unlisted sheets demote only against crit regenerated AFTER the last stale mark.
            $latestStaleMark = 0;
            foreach (['upgrade', 'all'] as $staleScope) {
                $staleMark = (int) get_transient('wpc_crit_stale_' . md5($staleScope));
                if ($staleMark > $latestStaleMark) {
                    $latestStaleMark = $staleMark;
                }
            }
            $critFresh = !$latestStaleMark || ((int) @filemtime($critFiles['desktop']) > $latestStaleMark);


            if ($haveSheetList) {


                if (microtime(true) > $GLOBALS['wpc_droplist_budget_deadline']) { return $html; }
                $rewritten = preg_replace_callback('/<link\b[^>]*rel=["\']preload["\'][^>]*>/i', function ($m) use ($absorbedSheets, $critFresh) {
                    if (!preg_match('/as=["\']style["\']/i', $m[0])) {
                        return $m[0];
                    }
                    if (stripos($m[0], 'fonts.googleapis') !== false || stripos($m[0], 'fonts.bunny') !== false) {
                        return $m[0];
                    }
                    if (self::wpc_consent_family($m[0])) {
                        return $m[0];
                    }
                    if (!preg_match('/href=["\']([^"\']+)["\']/i', $m[0], $hm)) {
                        return $m[0];
                    }
                    $wpc_pbn = strtolower((string) strtok(basename((string) parse_url($hm[1], PHP_URL_PATH)), '?'));
                    if ($wpc_pbn !== '' && !isset($absorbedSheets[$wpc_pbn])) {
                        $wpc_psrc = self::wpc_used_css_source_basename($m[0], $hm[1]);
                        if ($wpc_psrc !== '' && isset($absorbedSheets[$wpc_psrc])) { $wpc_pbn = $wpc_psrc; }
                    }
                    if ($wpc_pbn === '' || !isset($absorbedSheets[$wpc_pbn])) {
                        // UNLISTED — local sheets still demote; remote/3p keep the early tick.
                        if ($critFresh && !empty($absorbedSheets) && strpos($hm[1], 'wp-content/') !== false && apply_filters('wpc_unlisted_css_late', true)) {
                            $wpc_pt = preg_replace('/(rel)=(["\'])preload\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
                            $wpc_pt = preg_replace('/\s+onload=("[^"]*"|\'[^\']*\')/i', '', $wpc_pt);
                            return preg_replace('/\s+as=(["\'])style\1/i', '', $wpc_pt);
                        }
                        return $m[0];
                    }
                    if ($absorbedSheets[$wpc_pbn] === 0) {
                        return ''; // DEAD — covered and nothing kept; drop the download entirely
                    }
                    // REPLACED — demote to the late safety net; strip the self-activating bits
                    $wpc_pt = preg_replace('/(rel)=(["\'])preload\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
                    $wpc_pt = preg_replace('/\s+onload=("[^"]*"|\'[^\']*\')/i', '', $wpc_pt);
                    $wpc_pt = preg_replace('/\s+as=(["\'])style\1/i', '', $wpc_pt);
                    return $wpc_pt;
                }, $html);
                $html = is_string($rewritten) ? $rewritten : $html;
            }
            // v7.10.402: also re-examine wpc-late-stylesheet links. A sheet an earlier pass
            // demoted to late (e.g. divi-style) escaped this drop entirely, so an ABSORBED
            // sheet (manifest value 0 = used-css carries its rules) kept loading late and
            // its reset overrode the used-css (the lost pill border). Matching 'late-' lets
            // the droplist drop it — ONLY when the manifest marks it absorbed; unlisted/kept
            // late sheets fall through the demote branches unchanged (already late = no-op).
            $rewritten = preg_replace_callback('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:mobile-|late-)?stylesheet["\'][^>]*>/i', function ($m) use ($absorbedSheets, $haveSheetList, $critFresh) {
                if (stripos($m[0], 'fonts.googleapis') !== false || stripos($m[0], 'fonts.bunny') !== false) {
                    return $m[0];
                }
                if (stripos($m[0], 'wpc-used-css') !== false) {
                    return $m[0];
                }
                if ($haveSheetList) {
                    if (preg_match('/href=["\']([^"\']+)["\']/i', $m[0], $hm)) {
                        $linkBasename = strtolower((string) strtok(basename((string) parse_url($hm[1], PHP_URL_PATH)), '?'));
                        if (!isset($absorbedSheets[$linkBasename])) {
                            $sourceBasename = self::wpc_used_css_source_basename($m[0], $hm[1]);
                            if ($sourceBasename !== '' && isset($absorbedSheets[$sourceBasename])) { $linkBasename = $sourceBasename; }
                        }
                        if (!isset($absorbedSheets[$linkBasename])) {
                            // UNLISTED — local sheets still demote; remote/3p keep the early tick.
                            if ($critFresh && !empty($absorbedSheets) && strpos($hm[1], 'wp-content/') !== false && apply_filters('wpc_unlisted_css_late', true)) {
                                return preg_replace('/(rel|type)=(["\'])wpc-(?:mobile-)?stylesheet\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
                            }
                            return $m[0];
                        }
                        if ($absorbedSheets[$linkBasename] === 0) {
                            return ''; // DEAD — drop entirely (kills the sheet + its font downloads)
                        }
                    } else {
                        return $m[0];
                    }
                }
                // REPLACED (out>0) demotes; with NO manifest sheets keep the early tick —
                // a blanket late-flip applies dozens of sheets in one style recalc.
                if (!$haveSheetList) {
                    return $m[0];
                }

                return preg_replace('/(rel|type)=(["\'])wpc-(?:mobile-)?stylesheet\2/i', '$1=$2wpc-late-stylesheet$2', $m[0]);
            }, $html);
            $html = is_string($rewritten) ? $rewritten : $html;


            if ($haveSheetList) {
                $rewritten = preg_replace_callback('/<style\b[^>]*\btype=["\']wpc-(?:mobile-)?stylesheet["\'][^>]*>.*?<\/style>/is', function ($m) use ($absorbedSheets) {
                    if (!preg_match('/\bid=["\']([^"\']+)["\']/i', $m[0], $im)) {
                        return $m[0];
                    }
                    $styleId = strtolower(trim((string) $im[1]));
                    // wp-emoji sizing and the theme.json variable set are never droppable or late.
                    if (strpos($styleId, 'wp-emoji') !== false || strpos($styleId, 'global-styles') !== false || self::wpc_consent_family($styleId)) {
                        return $m[0];
                    }
                    if ($styleId === '' || !isset($absorbedSheets[$styleId])) {
                        return $m[0]; // UNLISTED — leave on the normal tick
                    }
                    if ($absorbedSheets[$styleId] === 0) {
                        return ''; // DEAD — remove the block entirely
                    }
                    return preg_replace('/(type)=(["\'])wpc-(?:mobile-)?stylesheet\2/i', '$1=$2wpc-late-stylesheet$2', $m[0], 1);
                }, $html);
                $html = is_string($rewritten) ? $rewritten : $html;
            }
            return $html;
        } catch (\Throwable $e) {
            return $html;
        }
    }

    /**
     * v7.24.09 — PARKING IS ONE VERDICT, DECIDED BEFORE THE PASS (belt cluster 1).
     *
     * The callbacks below never test the artifact on disk themselves: an artifact is not a
     * carrier, the crit tag can still fail to reach the buffer, and a page that parks on that
     * test serves its stylesheets deferred against a carrier that never arrives.
     *
     * $parkAllowed is that decision, made once by the caller against the TAG IN THE BUFFER
     * (cdn-rewrite.php stage_critical_and_lazy_css). False means every sheet comes back
     * untouched, so nothing is parked without a carrier and there is nothing to restore.
     *
     * $fontFaces is the render's @font-face owner ($ctx->fontFaces): the faces this pass
     * takes out of the crit tag are registered with it.
     */
    public function lazyCSS($html, $parkAllowed, wps_ic_font_face_set $fontFaces)
    {
        // v7.10.539 — the slow renders produce NO lz_* label, so they exit at the guard below.
        // This brackets the guard itself: lz_enter closes at lz_guard, so lz_enter == entry cost
        // and lz_guard == the preg_match. If lz_guard holds the 24s the guard is the bug; if
        // lz_enter holds it, the cost is in the CALL, not the body.
        // Run only if the marker exists (handles " or ')
        if (!preg_match('/id=(["\'])wpc-critical-css\1/si', $html)) {
            return $html;
        }


        // v7.10.597 — NAME THE PAGES WHERE THE CRIT PLACES A GLYPH IT CANNOT RENDER.
        // Receipted on staging /giveaway/: twelve Font Awesome <i> whose computed font-family is
        // the BODY TEXT stack, because the crit carries ::before{content:"\f06b"} while FA's own
        // sheet — which supplies .fa-duotone{font-family:...} and the @font-face — is routed to
        // the late lane at :6236. Glyph instruction without a font, so the browser draws its
        // missing-glyph box. Without the plugin you see nothing, because content and family
        // arrive together and there is no broken half-state to observe.
        // Detect only. The fix is a crit-extraction invariant (a content escape implies its font)
        // and it belongs to the generator, which can resolve the cascade; this is the receipt that
        // says which pages are affected and proves the day it stops happening.
        if (apply_filters('wpc_icon_content_audit', true)) {
            static $iconAuditDone = false;
            if (!$iconAuditDone && function_exists('wpc_cache_first_log')) {
                $iconAuditDone = true;
                $iconContentRules = (int) preg_match_all('/content\s*:\s*(["\'])\\\\[ef][0-9a-f]{3}\1/i', $html);
                if ($iconContentRules > 0
                    && preg_match('/<link\b[^>]*(?:fontawesome|font-awesome|eicons|dashicons)[^>]*>/i', $html, $iconLinkMatch)
                    && preg_match('/(?:rel|type)\s*=\s*["\']wpc-(?:late-|mobile-)?stylesheet["\']/i', $iconLinkMatch[0])) {
                    wpc_cache_first_log('crit-icon-content-deferred-face', '', '', [
                        'icon_content_rules' => $iconContentRules,
                        'deferred_sheet' => substr(preg_replace('/\s+/', ' ', $iconLinkMatch[0]), 0, 120),
                    ]);
                }
            }
        }
        // The callbacks are registered by name and take only the match, so the verdict travels
        // in the static; the render's standing verdict is restored when the two passes are done.
        $previousParkAllowed = self::set_park_allowed($parkAllowed);
        $rewrittenHtml = preg_replace_callback('/<link(.*?)>/si', [__CLASS__, 'cssLinkLazy'], $html);
        $html = is_string($rewrittenHtml) ? $rewrittenHtml : $html;
        $rewrittenHtml = preg_replace_callback('/(?<!<defs>)<style\b(.*?)<\/style>/si', [__CLASS__, 'cssStyleLazy'], $html);
        $html = is_string($rewrittenHtml) ? $rewrittenHtml : $html;
        self::set_park_allowed($previousParkAllowed);


        // The droplist removes sheets whose rules the used-CSS links carry. A render that parks
        // nothing emits no used-CSS link (see addCritical), so dropping a sheet there would lose
        // its rules outright: the page's own sheets are the only carrier it has.
        if ($parkAllowed) {
            $html = $this->wpc_used_css_droplist_pass($html);
        }
        $html = $this->wpc_combine_parked_css($html);
        try {
            $relocatedCritFaces = [];
            $alignedHtml = preg_replace_callback('/(<style\b[^>]*id="wpc-critical-css"[^>]*>)(.*?)(<\/style>)/is', function ($m) use (&$relocatedCritFaces) {
                $critCss = self::wpc_align_crit_urls_to_zone($m[2]);
                // v7.21.248 — CRIT'S URL FACES RELOCATE TO THE TYPE-PARKED DECLARER. With
                // used-css stripped and late-faces truly parked, the crit blob was the last
                // parsed sheet declaring url() optional faces — Chrome's idle prefetcher
                // fetched them at ~3s on every robot run (the final tail). Paint is
                // unaffected: optional + fitted fallbacks render; the faces arrive with the
                // late-faces reveal, which every path already runs. Verbatim move, no edit.
                if (apply_filters('wpc_crit_faces_relocate', true) && stripos($critCss, '@font-face') !== false) {
                    $critCss = (string) preg_replace_callback('/@font-face\s*\{[^}]*\}/is', function ($f) use (&$relocatedCritFaces) {
                        if (stripos($f[0], 'url(') === false || stripos($f[0], 'url(data:') !== false
                            || stripos($f[0], "url('data:") !== false || stripos($f[0], 'url("data:') !== false) {
                            return $f[0];
                        }
                        $relocatedCritFaces[] = $f[0];
                        return '';
                    }, $critCss);
                }
                return $m[1] . $critCss . $m[3];
            }, $html);
            $html = is_string($alignedHtml) ? $alignedHtml : $html;
            // v7.21.252 — GENERIC END-BODY RELOCATION (head-budget doctrine, the NitroPack
            // move): any tag stamped data-wpc-endbody="1" is lifted from where it was
            // emitted and re-inserted before </body>, verbatim. Only a <style>/<link> in
            // <head> blocks rendering (spec); at body-end it blocks nothing above it.
            if (stripos($html, 'data-wpc-endbody="1"') !== false && function_exists('wpc_inject_before_body_close')) {
                $endBodyTags = [];
                $htmlWithoutEndBodyTags = preg_replace_callback('/<(?:link|style)\b[^>]*data-wpc-endbody="1"[^>]*?(?:\/>|><\/style>|>)/is', function ($m) use (&$endBodyTags) {
                    $endBodyTags[] = $m[0];
                    return '';
                }, $html);
                if (is_string($htmlWithoutEndBodyTags) && !empty($endBodyTags)) {
                    $html = wpc_inject_before_body_close($htmlWithoutEndBodyTags, implode('', $endBodyTags));
                }
            }
            if (!empty($relocatedCritFaces)) {
                // The faces this relocation lifted out of the crit tag go to the render's face
                // owner, which decides where they land and mints the late block once.
                $fontFaces->add(implode('', $relocatedCritFaces), 'crit-tag-relocation', false);
            }
        } catch (\Throwable $e) {
        }


        try {
            $deferredHrefs = [];
            if (preg_match_all('/<link\b[^>]*(?:rel|type)=["\']wpc-(?:mobile-|late-)?stylesheet["\'][^>]*>/i', $html, $deferredLinkMatches)) {
                foreach ($deferredLinkMatches[0] as $deferredLinkTag) {
                    if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $deferredLinkTag, $hrefMatch)) {
                        $deferredHrefs[strtolower((string) preg_replace('/[?#].*$/', '', $hrefMatch[1]))] = 1;
                    }
                }
            }
            if (!empty($deferredHrefs)) {
                $rewrittenHtml = preg_replace_callback('/<link\b[^>]*\brel=["\']preload["\'][^>]*>/i', function ($m) use ($deferredHrefs) {
                    if (stripos($m[0], 'as="style"') === false && stripos($m[0], "as='style'") === false) { return $m[0]; }
                    if (stripos($m[0], 'onload') !== false) { return $m[0]; }
                    if (!preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $m[0], $pm)) { return $m[0]; }
                    $preloadHref = strtolower((string) preg_replace('/[?#].*$/', '', $pm[1]));
                    return isset($deferredHrefs[$preloadHref]) ? '' : $m[0];
                }, $html);
                $html = is_string($rewrittenHtml) ? $rewrittenHtml : $html;
            }
        } catch (\Throwable $e) {

        }


        return $html;
    }


    public static function wpc_own_style_tag_id($fullTag)
    {
        if (!is_string($fullTag) || $fullTag === '') {
            return '';
        }
        if (!preg_match('/<style\b[^>]*?\sid\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))/i', $fullTag, $m)) {
            return '';
        }
        $ownStyleId = '';
        for ($i = 1; $i <= 3; $i++) {
            if (isset($m[$i]) && $m[$i] !== '') {
                $ownStyleId = trim($m[$i]);
                break;
            }
        }
        return preg_match('/^wpc-[A-Za-z0-9_-]+$/i', $ownStyleId) === 1 ? $ownStyleId : '';
    }

    public static function wpc_is_own_style_live($fullTag)
    {
        $ownStyleId = self::wpc_own_style_tag_id($fullTag);
        if ($ownStyleId === '') {
            return false;
        }
        return (bool) apply_filters('wpc_own_guard_style_live', true, $ownStyleId);
    }

    // Divi's generated page CSS (et-cache/<id>/et-core-unified-*, et-divi-dynamic-*, and the
    // global et-divi-customizer sheet) is never parked. The host-twin sweep can repoint one of
    // those links to a healed copy under cache/wpc-hostfix/ that keeps the source basename, and
    // the copy's tag no longer contains "/et-cache/". Matching the path alone split Divi's pair:
    // the untouched dynamic sheet stayed live and re-declared .et_pb_section{position:relative}
    // after the critical CSS, while the parked unified sheet held the header's position:fixed
    // override. A fixed header sat in normal flow until activation, pushing the page down, and a
    // browser reload restored the scroll against that layout and landed below the top. The copy
    // is recognised by its basename.
    public static function wpc_etcache_sheet($fullTag)
    {
        if (stripos($fullTag, '/et-cache/') !== false) {
            return true;
        }
        if (stripos($fullTag, '/cache/wpc-hostfix/') === false
            || !preg_match('/(?:^|\s)href\s*=\s*(["\'])([^"\']+)\1/i', $fullTag, $m)) {
            return false;
        }
        $path = (string) parse_url($m[2], PHP_URL_PATH);
        if (stripos($path, '/cache/wpc-hostfix/') === false) {
            return false;
        }
        return preg_match('/^et-(?:core-unified|divi-dynamic|divi-customizer)-/i', basename($path)) === 1;
    }

    public function cssStyleLazy($html)
    {
        $fullTag = $html[0];

        // The park verdict, decided once against the crit tag in the buffer (see lazyCSS).
        if (!self::$parkAllowed) {
            return $fullTag;
        }

        if (apply_filters('wpc_etcache_live', true) && self::wpc_etcache_sheet($fullTag)) {
            return $fullTag;
        }

        // Not Mobile
        $lazyCss = 'wpc-stylesheet';


        if (strpos($fullTag, 'wpc-critical-css') !== false
            || strpos($fullTag, 'wpc-gfont-atf') !== false
            || strpos($fullTag, 'wpc-elementor-anim-start') !== false
            || strpos($fullTag, 'wpc-atf-reveal') !== false
            || strpos($fullTag, 'wpc-font-fallbacks') !== false
            // v7.10.602 — the carrier is the ONLY declaration source on logged-in renders;
            // type-swapped it would go inert and the family would have zero faces again.
            || strpos($fullTag, 'wpc-font-carrier') !== false
            // Inert, the black body from used-css wins again — this guard is the only thing
            // outranking it.
            || strpos($fullTag, 'wpc-body-guard') !== false
            || strpos($fullTag, 'wpc-lazy-thumb-bgfix') !== false


            || strpos($fullTag, 'wpc-lcp-bg-authority') !== false
            || strpos($fullTag, 'wpc-bgvideo-contain') !== false


            || strpos($fullTag, 'wpc-cls-reserve') !== false
            || strpos($fullTag, 'wpc-presc-reserve') !== false
            // Containment applied AFTER first paint is a layout change by definition: the page
            // paints uncontained, the type-swap flips content-visibility on ~330ms later, and
            // every stamped section that is not far below the fold reflows. Desktop CLS 0.072.
            || strpos($fullTag, 'wpc-cv-guard') !== false
            // Same shape — reserves icon box size. Deferred, icons paint unsized then snap.
            || strpos($fullTag, 'wpc-icon-guard') !== false


            || strpos($fullTag, 'wpc-late-faces') !== false
            // v7.10.591 — covers wpc-fonts-css, -css-rest and -css-faces. .589 emits these
            // @font-face declarations LIVE on purpose: inert, the family has no usable face and
            // matching falls through to sans-serif. The fonts pass runs AFTER this one
            // (stage_critical_and_lazy_css -> stage_fonts_replace_frontend), so the type-swap
            // never sees them — but that is stage order in another file, not an invariant, and
            // a second buffer pass over the same HTML would undo .589 silently.
            || strpos($fullTag, 'wpc-fonts-css') !== false
            // Sole writer is window.wpcIconFaces(); the lazy type-swap would hand it back
            // to the late-css barrier, which is the barrier this block exists to skip.
            || strpos($fullTag, 'wpc-icon-faces') !== false
            // Must be live before the rest bundle applies its unconditional opacity:0;
            // type-swapped it would go inert until exactly the barrier that hides them.
            || strpos($fullTag, 'wpc-anim-reveal') !== false
            || strpos($fullTag, 'wpc-emoji-guard') !== false
            || strpos($fullTag, 'wp-emoji') !== false
            || strpos($fullTag, 'global-styles') !== false

            || self::wpc_is_own_style_live($fullTag)

            || self::wpc_consent_family($fullTag)
            || strpos($fullTag, 'wpc-font-subsets') !== false


            // used.css self-applies via its onload media-flip; deferring its rel kills the
            // flip (unknown-rel links never load) and strands the page naked once crit drops.
            || strpos($fullTag, 'wpc-used-css') !== false
            || strpos($fullTag, 'data-wpc-ucss') !== false
            || (function_exists('wpc_font_localizer_sheet') && wpc_font_localizer_sheet($fullTag))) {
            return $fullTag;
        }

        if (strpos($fullTag, 'rs6') !== false) {
            //Removed in 6.60.39 - leftover from when we were excluding rev slider from delayJS?
            //return $fullTag;
        }

        // v7.21.64 — A THEME'S OWN CRITICAL CSS IS NEVER OURS TO DEFER. Divi 5 inlines its
        // entire page design as critical blocks (divi-dynamic-critical-inline-css 57KB +
        // et-critical-inline-css 32KB + divi-style-parent-inline 27KB on 4bullmann), plus its
        // user-font @font-face carrier and an off-canvas hide-on-load guard whose whole job is
        // first-frame state. Parking them serves an unstyled page until activation — the
        // systemic Divi 5 white-page, independent of the .63 animation class. Any inline style
        // whose id DECLARES itself critical stays live (theme-agnostic: Divi/Astra/anyone),
        // plus Divi's known first-frame carriers; NEVER DEFER A DECLARATION covers userfonts.
        if (apply_filters('wpc_theme_critical_live', true)
            && preg_match('/\b(?:id|class)=["\'][^"\']*(?:critical|et-divi-userfonts|divi-style-parent-inline|off-canvas-hide-on-load|et-vb-global-data)[^"\']*["\']/i', $fullTag)) {
            return $fullTag;
        }

        // v7.21.80 — DIVI'S GENERATED PAGE CSS IS THE PAGE'S DESIGN, NOT AN OPTIMIZATION
        // TARGET. et-cache/<id>/et-divi-dynamic-*.css / et-core-unified-*.css carry the
        // page's per-module layout (column widths, type scale): parked on falknerei the
        // hero column held 747px vs 1440px until gesture. Same class as the .64 inline
        // blocks, link form. Kill wpc_etcache_live.
        if (apply_filters('wpc_etcache_live', true)
            && self::wpc_etcache_sheet($fullTag)
            && stripos($fullTag, '<link') !== false) {
            return $fullTag;
        }

        // v7.21.60 — A BUILDER'S PER-ELEMENT INLINE GEOMETRY BLOCK IS ATF-CRITICAL BY
        // CONSTRUCTION. Flatsome (and UX Builder kin) print each section's padding/min-height
        // as a tiny <style> BESIDE the section (#section_NNN{padding-top:40px...}). Parking
        // those means every section's height arrives only at sheet activation: ctfx desktop
        // painted the hero short, then at ~4.5s the whole page re-stacked (measured shift wave
        // 0.061 + 0.244 — the ux-shape-divider was only the messenger riding its section).
        // A crit need not carry these (ctfx's did not), and a
        // FRESH site has no crit at all — the block itself is the reservation, so it stays
        // live. Tiny (<3KB), per-element-ID-selector, geometry-bearing blocks only.
        if (apply_filters('wpc_inline_geometry_live', true)
            && strlen($fullTag) <= 3072
            && preg_match('/<style\b[^>]*>(.*?)<\/style>/is', $fullTag, $geometryStyleMatch)
            && preg_match('/(?:^|[,{}\s])#(?:section|row|col|text|image|banner|gap|btn|video|title|divider)[_-]\d/i', (string) $geometryStyleMatch[1])
            && preg_match('/\b(?:padding|margin|min-height|height|width|top|bottom|left|right|flex-basis|line-height|font-size)(?:-[a-z]+)?\s*:/i', (string) $geometryStyleMatch[1])) {
            return $fullTag;
        }

        // v7.21.348 — SBY CSS RIDES THE FAMILY KEEP (columbus /videos-gallery/ pre-gesture:
        // parked sb-youtube.min.css left the JS-built feed unstyled — play-button SVGs
        // exploded viewport-wide, raw fallback text visible — while the page's crit was the
        // combined-era artifact with ~zero sby coverage. A family kept eager JS-side (.347)
        // keeps its structural CSS live too; .31 law: family keeps are atomic. Same class
        // as the .104 shapes.min.css in-flow ruling.
        if ((stripos($fullTag, 'sb-youtube') !== false || stripos($fullTag, 'sby_styles') !== false
                || stripos($fullTag, 'sby-styles') !== false)
            && apply_filters('wpc_keep_sby_family', true)) {
            return $fullTag;
        }

        if (strpos($fullTag, 'elementor-post') !== false || strpos($fullTag, '/elementor/') !== false || strpos($fullTag, 'admin-bar') !== false) {
            $lazyCss = 'wpc-mobile-stylesheet';
        } elseif (strpos($fullTag, 'preload') !== false) {
            $lazyCss = 'wpc-mobile-stylesheet';
        }


        if (stripos($fullTag, 'fontawesome') !== false || stripos($fullTag, 'font-awesome') !== false) {
            $wpc_fao = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
            if (apply_filters('wpc_fa_optimize', is_array($wpc_fao) && !empty($wpc_fao['fontawesome-optimize']) && $wpc_fao['fontawesome-optimize'] == '1')) {
                $lazyCss = 'wpc-late-stylesheet';
            }
        }

        if (self::$excludes_class->strInArray($fullTag, self::$excludes_class->criticalCSSExcludes())) {
            return $fullTag;
        }


        if (preg_match('/<style\b[^>]*\btype\s*=/i', $fullTag)) {
            // Define the regular expression pattern
            $pattern = '/<style(\s*[^>]*)\s+type=("|\')text\/css("|\')([^>]*)>/i';

            // Replace the type attribute in style tags
            $fullTag = preg_replace($pattern, '<style$1 type=\'' . $lazyCss . '\'$4>', $fullTag);
        } else {
            $fullTag = preg_replace('/<style\b/i', '<style type="' . $lazyCss . '"', $fullTag, 1);
        }

        // v7.21.23 — THEME-STATE RULES RIDE EAGER. State-scoped rules (e.g. Bricks'
        // :root[data-brx-theme="dark"]{--vars}) are gated on an html attribute a pre-paint
        // setter stamps — but crit never carries them (the renderer sees the default state,
        // the variant is pruned as unused), so a dark-mode visitor painted LIGHT until the
        // parked sheet flipped live: white->dark flash on every nav (ridgeway). Attribute-
        // scoped rules only apply in the state that wants them, so keeping a live copy is
        // paint-correct by construction; the parked original re-applying later is a no-op.
        // Top-level rules only, byte-capped, kill filter.
        if (strpos($fullTag, $lazyCss) !== false
            && apply_filters('wpc_theme_state_eager', true)
            && preg_match('/\[data-(?:[a-z]+-)?theme[=\]]|\[data-dark|\.dark-mode/i', $fullTag)
            && preg_match('/<style\b[^>]*>(.*?)<\/style>/is', $fullTag, $themeStyleMatch)) {
            $themeStateRules = '';
            // @-blocks (media/font-face/supports, one nesting level) are stripped FIRST so the
            // anchor-free rule matcher below can never harvest a rule from inside them.
            $topLevelCss = preg_replace('/@[^{}]*\{[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/s', '', (string) $themeStyleMatch[1]);
            if (preg_match_all('/([^{}@]+)(\{[^{}]*\})/s', (string) $topLevelCss, $ruleMatches, PREG_SET_ORDER)) {
                foreach ($ruleMatches as $rule) {
                    if (preg_match('/\[data-(?:[a-z]+-)?theme[=\]]|\[data-dark|\.dark-mode/i', $rule[1])
                        && strlen($themeStateRules) + strlen($rule[1]) + strlen($rule[2]) <= 32768) {
                        $themeStateRules .= trim($rule[1]) . $rule[2];
                    }
                }
            }
            if ($themeStateRules !== '' && strpos($themeStateRules, '</') === false) {
                $fullTag .= '<style data-wpc-tsv="1">' . $themeStateRules . '</style>';
            }
        }

        return $fullTag;
    }


    public static function wpc_maximum_mobile_on()
    {
        $s = function_exists('get_option') ? get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings') : [];
        return is_array($s) && !empty($s['maximum-mobile']) && $s['maximum-mobile'] == '1';
    }

    public function cssLinkLazy($html)
    {

        $fullTag = $html[0];

        if (preg_match('/\bas\s*=\s*["\']style["\']/i', $fullTag) && preg_match('/\brel\s*=\s*(["\'])preload\1/i', $fullTag)
            && preg_match('/\bonload\s*=\s*(?:"[^"]*this\.rel\s*=\s*\'stylesheet\'[^"]*"|\'[^\']*this\.rel\s*=\s*"stylesheet"[^\']*\')/i', $fullTag)) {
            $fullTag = (string) preg_replace('/\s*\bonload\s*=\s*(?:"[^"]*"|\'[^\']*\')/i', '', $fullTag);
            $fullTag = (string) preg_replace('/\brel\s*=\s*(["\'])preload\1/i', 'rel=$1stylesheet$1', $fullTag);
            $fullTag = (string) preg_replace('/\s*\bas\s*=\s*(["\'])style\1/i', '', $fullTag);
        }

        if (strpos($fullTag, 'preload') !== false || strpos($fullTag, 'prefetch') !== false) {
            return $fullTag;
        }

        // The park verdict, decided once against the crit tag in the buffer (see lazyCSS).
        if (!self::$parkAllowed) {
            return $fullTag;
        }

        // v7.21.82 — the .80 et-cache never-park lived at ONE seam; this second parker
        // kept parking et-core-unified/et-divi-dynamic (falknerei probe receipt). A
        // doctrine enforced at one write seam is not a doctrine.
        if (apply_filters('wpc_etcache_live', true) && self::wpc_etcache_sheet($fullTag)) {
            return $fullTag;
        }

        // Not Mobile
        $lazyCss = 'wpc-stylesheet';

        if (strpos($fullTag, 'wpc-critical-css') !== false || strpos($fullTag, 'wpc-atf-reveal') !== false
            || strpos($fullTag, 'wpc-font-fallbacks') !== false


            || self::wpc_consent_family($fullTag)


            // used.css self-applies via its onload media-flip; deferring its rel kills the
            // flip (unknown-rel links never load) and strands the page naked once crit drops.
            || strpos($fullTag, 'wpc-used-css') !== false
            || strpos($fullTag, 'data-wpc-ucss') !== false
            || (function_exists('wpc_font_localizer_sheet') && wpc_font_localizer_sheet($fullTag))) {
            return $fullTag;
        }

        if (strpos($fullTag, 'rs6') !== false) {
            //Removed in 6.60.39 - leftover from when we were excluding rev slider from delayJS?
            //return $fullTag;
        }


        if (strpos($fullTag, 'elementor-post') !== false || strpos($fullTag, '/elementor/') !== false || strpos($fullTag, 'admin-bar') !== false) {
            $lazyCss = 'wpc-mobile-stylesheet';
        } elseif (strpos($fullTag, 'preload') !== false) {
            $lazyCss = 'wpc-mobile-stylesheet';
        }

        if (self::$excludes_class->strInArray($fullTag, self::$excludes_class->criticalCSSExcludes())) {


            try {
                if (function_exists('wpc_auto_journal')
                    && preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $fullTag, $excludedHrefMatch)) {
                    $excludeJournalKey = 'wpc_exj_' . md5($excludedHrefMatch[1]);
                    if (!get_transient($excludeJournalKey)) {
                        set_transient($excludeJournalKey, 1, DAY_IN_SECONDS);
                        wpc_auto_journal('css-exclude-suppressed', ['href' => substr((string) preg_replace('/\?.*$/', '', $excludedHrefMatch[1]), -120)]);
                    }
                }
            } catch (\Throwable $e) {
            }
            return $fullTag;
        }

        preg_match('/(href)\s*\=["\']?((?:.(?!["\']?\s+(?:\S+)=|\s*\/?[>"\']))+.)["\']?/is', $fullTag, $href);

        if (!empty($href[2])) {

            // Lazy load google fonts?
            if (strpos($href[2], 'fonts.googleapis.com/css') !== false) {
                // Google resolves the FIRST display param — replace an existing value, never append after one.
                if (preg_match('/([?&](?:amp;)?)display=[a-z]+/i', $href[2])) {
                    $newHref = preg_replace('/([?&](?:amp;)?)display=[a-z]+/i', '$1display=swap', $href[2]);
                } elseif (strpos($href[2], '?') !== false) {
                    $newHref = $href[2] . '&display=swap';
                } else {
                    $newHref = $href[2] . '?display=swap';
                }
                $fontsIdAttr = preg_match('/\bid=(["\'])([^"\']+)\1/i', $fullTag, $idMatch) ? ' id="' . esc_attr($idMatch[2]) . '"' : '';
                $fontsMediaAttr = preg_match('/\bmedia=(["\'])([^"\']+)\1/i', $fullTag, $fontsMediaMatch) ? ' media="' . esc_attr($fontsMediaMatch[2]) . '"' : '';
                $gfonts = '<link rel="wpc-mobile-stylesheet"' . $fontsIdAttr . $fontsMediaAttr . ' href="' . esc_url($newHref) . '" as="style" onload="this.onload=null;this.rel=\'stylesheet\'"/>';
                return $gfonts;
            } elseif (strpos($href[2], self::$siteUrl) === false) {
                //Removed in 6.60.39
                //return $fullTag;
                $lazyCss = 'wpc-mobile-stylesheet';
            } else {
                $lazyCss = 'wpc-mobile-stylesheet';
            }
        }


        // FA 'wpc-mobile-stylesheet' — a NO-OP for elementor-pathed FA links (already mobile-deferred):
        // swapStyles() activates deferred sheets at tick (0-3s), the @font-face parses, and the icon


        if (preg_match('/font-?awesome|elementor-icons-fa-|\/fa-(?:solid|regular|brands)[^\/]*\.css/i', $fullTag)) {
            $fa_settings = get_option(defined('WPS_IC_SETTINGS') ? WPS_IC_SETTINGS : 'wps_ic_settings');
            if (apply_filters('wpc_fa_optimize', is_array($fa_settings) && !empty($fa_settings['fontawesome-optimize']) && $fa_settings['fontawesome-optimize'] == '1')) {
                $lazyCss = 'wpc-late-stylesheet';
            }
        }


        if (in_array($lazyCss, ['wpc-stylesheet', 'wpc-mobile-stylesheet'], true)
            && apply_filters('wpc_maximum_mobile', self::wpc_maximum_mobile_on())) {
            $lazyCss = 'wpc-late-stylesheet';
        }

        preg_match('/(rel)\s*\=["\']?((?:.(?!["\']?\s+(?:\S+)=|\s*\/?[>"\']))+.)["\']?/is', $fullTag, $linkRel);

        if (!empty($linkRel)) {
            if (!empty($linkRel[2])) {
                $relTag = $linkRel[0];
                $relKey = $linkRel[1];
                $relValue = $linkRel[2];

                if ($relValue == 'stylesheet') {
                    $newTag = str_replace($relValue, $lazyCss, $relTag);
                    // Attribute position only — never the copy inside an onload handler.
                    $fullTag = preg_replace('/(?<![\w.$])' . preg_quote($relTag, '/') . '/', addcslashes($newTag, '\\$'), $fullTag, 1);
                    static $prefetchedHrefs = [];
                    if (!empty($href[2]) && count($prefetchedHrefs) < 20 && !isset($prefetchedHrefs[$href[2]])
                        && apply_filters('wpc_defer_css_preload', false)) {
                        $prefetchedHrefs[$href[2]] = 1;
                        $crossoriginAttr = preg_match('/\bcrossorigin(?:\s*=\s*(["\'])[^"\']*\1)?/i', $fullTag, $crossoriginMatch) ? ' ' . $crossoriginMatch[0] : '';
                        $mediaAttr = preg_match('/\bmedia\s*=\s*(["\'])([^"\']+)\1/i', $fullTag, $mediaMatch) ? ' media="' . esc_attr($mediaMatch[2]) . '"' : '';
                        $fullTag .= '<link rel="prefetch" as="style" href="' . esc_attr($href[2]) . '"' . $crossoriginAttr . $mediaAttr . '>';
                    }
                }
            }
        }

        preg_match('/(type)\s*\=["\']?((?:.(?!["\']?\s+(?:\S+)=|\s*\/?[>"\']))+.)["\']?/is', $fullTag, $linkType);

        if (!empty($linkType)) {
            if (!empty($linkType[2])) {
                $relTag = $linkType[0];
                $relKey = $linkType[1];
                $relValue = $linkType[2];

                if ($relValue == 'text/css') {
                    $newTag = str_replace($relValue, 'wpc-text/css', $relTag);
                    $fullTag = preg_replace('/(?<![\w.$])' . preg_quote($relTag, '/') . '/', addcslashes($newTag, '\\$'), $fullTag, 1);
                }
            }
        }

        return $fullTag;
    }

    public function cssToFooter($html)
    {
        $html = preg_replace_callback('/<\/body>/si', [__CLASS__, 'cssToFooterRender'], $html);

        return $html;
    }

    public function cssToFooterRender($html)
    {
        return self::$removedCSS . '</body>';
    }

    public function encodeIframe($html)
    {
        $html = preg_replace_callback('/<iframe.*?\/iframe>/i', [__CLASS__, 'iframeEncode'], $html);

        return $html;
    }

    public function decodeIframe($html)
    {
        $html = preg_replace_callback('/\[iframe\-wpc\](.*?)\[\/iframe\-wpc\]/i', [__CLASS__, 'iframeDecode'], $html);

        return $html;
    }

    public function iframeEncode($html)
    {
        $html = base64_encode($html[0]);

        return '[iframe-wpc]' . $html . '[/iframe-wpc]';
    }

    public function iframeDecode($html)
    {
        $html = base64_decode($html[1]);

        return $html;
    }

    public function scriptContent($html)
    {
        $html = preg_replace_callback('/<script\s[^>]*(?<=type=\"text\/template\")*>.*?<\/script>/is', [__CLASS__, 'scriptContentTag'], $html);

        return $html;
    }

    public function scriptContentTag($html)
    {
        if (strpos($html[0], 'text/template') !== false || strpos($html[0], 'text/x-template') !== false) {
            return $html[0];
        }

        $html = preg_replace_callback('/<img[^>]*>/si', [__CLASS__, 'imageTagAsset'], $html[0]);

        return $html;
    }

    public function imageTagAsset($image)
    {

        $image[0] = trim($image[0]);
        $addslashes = false;

        if (strpos($image[0], '$') !== false) {
            return $image[0];
        }

        if (strpos($image[0], '=\"') !== false || strpos($image[0], "=\'") !== false) {
            $addslashes = true;
            $image[0] = stripslashes($image[0]);
        }

        if (strpos($image[0], '//') !== false) {
            // Replace any protocol-relative URLs with https: prefix
            // Pattern matches //domain.com/path pattern in HTML attributes
            $image[0] = preg_replace('/(["\']|\s|=)\/\/([a-zA-Z0-9.-]+\.[a-zA-Z]{2,}\/[^"\'\s>]*)/', '$1https://$2', $image[0]);
        }

        if (strpos($_SERVER['REQUEST_URI'], 'embed') !== false) {
            $image[0] = $this->maybe_addslashes($image[0], $addslashes);

            return $image[0];
        }

        // File has already been replaced
        if ($this->defaultExcluded($image[0])) {
            $image[0] = $this->maybe_addslashes($image[0], $addslashes);

            return $image[0];
        }

        // File is not an image
        if (!self::isImage($image[0])) {
            $image[0] = $this->maybe_addslashes($image[0], $addslashes);

            return $image[0];
        }

        if ((self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0') && !self::imageUrlMatchingSiteUrl($image[0])) {
            $image[0] = $this->maybe_addslashes($image[0], $addslashes);

            return $image[0];
        }

        // File is excluded
        if (self::isExcluded($image[0])) {
            $image[0] = $this->maybe_addslashes($image[0], $addslashes);

            return $image[0];
        }

        $img_tag = $image[0];
        $original_img_tag['original_tags'] = $this->getAllTags($image[0], []);
        $original_img_tag['original_tags'] = self::wpc_backfill_img_dimensions($original_img_tag['original_tags']);

        preg_match('/src=["|\']([^"]+)["|\']/', $img_tag, $image_src);

        if (strpos($image_src[1], '$') !== false) {
            $image[0] = $this->maybe_addslashes($image[0], $addslashes);

            return $image[0];
        }

        if (!empty($image_src[1])) {
            $NewSrc = 'https://' . self::$zoneName . '/m:0/a:' . $this->specialChars(self::reformatUrl($image_src[1]));
            $img_tag = str_replace($image_src[1], $NewSrc, $img_tag);
        }

        // TODO: Was required for some sites that were having slashes
        $img_tag = $this->maybe_addslashes($img_tag, true);

        return $img_tag;
    }

    public function maybe_addslashes($image, $addslashes = false)
    {
        if ($addslashes) {
            $image = addslashes($image);
        }

        return $image;
    }

    public static function isImage($image)
    {
        if (strpos($image, '.webp') === false && strpos($image, '.jpg') === false && strpos($image, '.jpeg') === false && strpos($image, '.png') === false && strpos($image, '.ico') === false && strpos($image, '.svg') === false && strpos($image, '.gif') === false) {
            return false;
        } else {
            // Serve JPG Enabled?
            if (strpos($image, '.jpg') !== false || strpos($image, '.jpeg') !== false) {

                if (empty(self::$settings['serve']['jpg']) || self::$settings['serve']['jpg'] == '0') {
                    return false;
                }
            }


            if (strpos($image, '.gif') !== false) {
                if (empty(self::$settings['serve']['gif']) || self::$settings['serve']['gif'] == '0'
                    || !self::cf_is_delivery()) {
                    return false;
                }
            }

            // Serve PNG Enabled?
            if (strpos($image, '.png') !== false) {

                if (empty(self::$settings['serve']['png']) || self::$settings['serve']['png'] == '0') {
                    return false;
                }
            }

            // Serve SVG Enabled?
            if (strpos($image, '.svg') !== false) {

                if (empty(self::$settings['serve']['svg']) || self::$settings['serve']['svg'] == '0') {
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

    public function getAllTags($image, $ignore_tags = ['src', 'srcset', 'data-src', 'data-srcset'])
    {
        $found_tags = [];

        if (strpos($image, 'trp-gettext') !== false) {
            //TRP inserts <trp-gettext data-trpgettextoriginal=19> ... </trp-gettext> to translate alt tag, breaks our usuall regex
            preg_match_all('/\s*([a-zA-Z-:]+)\s*=\s*("|\')(.*?)\2/is', $image, $image_tags);

            if (!empty($image_tags[1])) {
                $image_tags[2] = $image_tags[3];
            }

        } else {
            $image = html_entity_decode($image, ENT_NOQUOTES);
            #preg_match_all('/([a-zA-Z\-\_]*)\s*\=["\']?((?:.(?!["\']?\s+(?:\S+)=|\s*\/?[>"\']))+.)["\']?/is', $image, $image_tags);

            #preg_match_all('/(?:\s|^)(\w+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'))? /is', $image, $image_tags); was used before


            preg_match_all('/([a-zA-Z_-]+(?:--[a-zA-Z_-]+)*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^>\s]+)))?/', $image, $matches, PREG_SET_ORDER);

            $attributes = [];
            unset ($matches[0]);

            foreach ($matches as $match) {
                $attrName = $match[1];

                $attrValue = null;
                // Iterate through potential groups and assign the first non-empty value
                foreach ([2, 3, 4] as $index) {
                    if (!empty($match[$index])) {
                        $attrValue = $match[$index];
                        break; // Stop at the first non-empty value
                    }
                }

                // Save the attribute and its value (if any) as key => value pairs in the array
                $attributes[$attrName] = $attrValue;
            }

            foreach ($attributes as $tag => $value) {
                if (!empty($ignore_tags) && in_array($tag, $ignore_tags)) {
                    continue;
                }

                if ($tag == 'data-mk-image-src-set') {
                    $value = htmlspecialchars_decode($value);
                    $value = json_decode($value, true);
                    $value = $value['default'];
                }

                $found_tags[$tag] = $value;
            }

            return $found_tags;
        }

        if (!empty($image_tags[1])) {
            $tag_value = $image_tags[2];
            foreach ($image_tags[1] as $i => $tag) {
                if (!empty($ignore_tags) && in_array($tag, $ignore_tags)) {
                    continue;
                }

                if ($tag == 'data-mk-image-src-set') {
                    $value = htmlspecialchars_decode($tag_value[$i]);
                    $value = json_decode($value, true);
                    $value = $value['default'];
                    $tag_value[$i] = $value;
                } else {
                    if (strpos($tag_value[$i], '=') === false) {
                        $tag_value[$i] = str_replace(['"', '\''], '', $tag_value[$i]);
                    }
                }

                $found_tags[$tag] = $tag_value[$i];
            }
        }

        return $found_tags;
    }

    public function getPictureTags($image, $ignore_tags)
    {
        $extractedTags = [];
        $found_tags = [];
        $image = html_entity_decode($image);

        // Find all source tags
        preg_match_all('/<source[^>]*srcset="([^"]+)"/is', $image, $image_tags);

        // Gets All Tags - works
        #preg_match_all('/\s*([a-zA-Z-:]+)\s*=\s*("|\')(.*?)\2/is', $image, $image_tags);

        if (!empty($image_tags)) {
            $attributes = $image_tags[1];
            $values = $image_tags[3];

            if (!empty($attributes)) {
                foreach ($attributes as $index => $name) {
                    $value = $values[$index];
                    $extractedTags[$name] = $value;
                }
            }

            return $extractedTags;
        }

        return false;
    }


    // TODO: Will break sites if always active

    public function defferFontAwesome($html)
    {
        // TODO: Fix causes problems with Crsip on WP Compress Site

        if (preg_match("/<script\b[^>]*\bsrc=['\"]([^'\"]*kit\.fontawesome[^'\"]*)['\"][^>]*>.*?<\/script>/si", $html, $matches)) {
            $scriptTag = $matches[0];

            if (!empty($_GET['stop_before']) && $_GET['stop_before'] == 'defferFontAwesome') {
                return print_r([$matches], true);
            }

            if (strpos($scriptTag, 'defer') === false) {
                $scriptTag = str_replace('<script', '<script defer', $scriptTag);
            }

            $replace = str_replace($matches[0], $scriptTag, $html);
            return $replace;
        }

        return $html;
    }

    public function lazyWpFonts($html)
    {
        $pattern = '/<style[^>]*\s*id=[\'"]wp-fonts-local[\'"][^>]*>.*?<\/style>/is';
        $html = preg_replace($pattern, '', $html);
        return $html;
    }

    public function defferAssets($html)
    {
        // TODO: Fix causes problems with Crsip on WP Compress Site
        return $html;
    }

    public function backgroundSizing($html)
    {
        $html = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>?/is', [__CLASS__, 'replaceBackgroundImagesInCSS'], $html);
        $html = preg_replace_callback('/data-settings=(["\'])(.*?)\1/i', [__CLASS__, 'replaceBackgroundDataSetting'], $html);
        return $html;
    }

    /**
     * Run ONLY the Elementor slideshow data-settings rewrite (no inline-CSS
     * background:url() pass). Lets the CDN-rewrite caller deliver slideshow bg images even when
     * the Background-Sizing toggle is off, without turning on the heavier CSS bg-image rewrite.
     */
    public function backgroundSlideshowOnly($html)
    {
        return preg_replace_callback('/data-settings=(["\'])(.*?)\1/i', [__CLASS__, 'replaceBackgroundDataSetting'], $html);
    }

    public function replaceBackgroundImagesInCSS($image)
    {
        if (!empty($image[0])) {
            $html = preg_replace_callback('~\bbackground(-image)?\s*:(.*?)\(\s*(\'|")?(?<image>.*?)\3?\s*\)~i', [__CLASS__, 'replaceBackgroundImageStyles'], $image[0]);
        }

        return $html;
    }

    public function replaceBackgroundImagesInCSSLocal($image)
    {
        $style_content = $image[0];

        $html = preg_replace_callback('~\bbackground(-image)?\s*:(.*?)\(\s*(\'|")?(?<image>.*?)\3?\s*\)~i', [__CLASS__, 'replaceBackgroundImageStylesLocal'], $style_content);

        return $html;
    }

    public function replaceBackgroundImage($image)
    {
        $tag = $image[0];
        $url = $image['image'];
        $original_url = $url;

        if (!strpos($url, self::$zoneName)) {
            // File has already been replaced
            if ($this->defaultExcluded($url)) {
                return $tag;
            }

            // File is not an image
            if (!self::isImage($url)) {
                return $tag;
            }
        }

        if (self::isExcluded($url)) {
            return $tag;
        }

        if (self::isExcludedFrom('cdn', $url)) {
            return $tag;
        }

        // Third-party backgrounds stay DIRECT — same guard as replaceBackgroundImageStyles.
        if ((empty(self::$externalUrlEnabled) || self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0')
            && wp_parse_url($url, PHP_URL_HOST) && !self::imageUrlMatchingSiteUrl($url)) {
            return $tag;
        }

        $webp = '/wp:' . self::$webp;
        if (self::isExcludedFrom('webp', $url)) {
            $webp = '';
        }

        $newUrl = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $url)) . '/u:' . self::uForCdn($url);
        $return_tag = str_replace($original_url, $newUrl, $tag);

        if (self::$lazy_enabled) {
            $return_tag .= 'display:none;';
        }

        return $return_tag;
    }

    public function replaceBackgroundDataSetting($image)
    {
        if (!empty($image[2])) {
            $data = html_entity_decode($image[2]);

            if (!empty($data)) {
                $dataJson = json_decode($data);

                if (!empty($dataJson) && !empty($dataJson->background_slideshow_gallery)) {
                    $slides = $dataJson->background_slideshow_gallery;

                    if (!empty($slides)) {


                        $cf_zone = self::zone_is_cf();
                        foreach ($slides as $i => $slide) {
                            $origin = isset($slide->url) ? (string) $slide->url : '';
                            // m:0/a: passthrough is the always-200 floor.
                            $newSlideUrl = 'https://' . self::$zoneName . '/m:0/a:' . self::reformatUrl($origin);


                            if ($origin !== '' && self::imageUrlMatchingSiteUrl($origin) && self::$zoneName !== ''
                                && !(defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL)) {
                                $slideSiteHost = rtrim(trailingslashit(site_url()), '/');
                                $cleanOrigin   = preg_replace('/\?.*$/', '', $origin);
                                $natural = (strpos($cleanOrigin, $slideSiteHost) === 0)
                                    ? 'https://' . self::$zoneName . substr($cleanOrigin, strlen($slideSiteHost))
                                    : '';
                                // An AVIF slide keeps the m:0/a: floor: a natural URL for it 302s to
                                // a guessed .jpg that 404s (noktaltema.com, 2026-09-24).
                                $slideOriginExt = strtolower((string) pathinfo($cleanOrigin, PATHINFO_EXTENSION));
                                if (is_string($natural) && $natural !== '' && strpos($natural, '/m:') === false
                                    && self::natural_lane_takes_source($slideOriginExt)) {


                                    $newSlideUrl = $natural;
                                    $cur_ext = strtolower(pathinfo(preg_replace('/\?.*$/', '', $natural), PATHINFO_EXTENSION));
                                    $fmt = self::wpc_single_url_format($cur_ext, $cf_zone, null);
                                    if (is_string($fmt) && $fmt !== '' && $fmt !== $cur_ext) {
                                        $neg = preg_replace('/\.(jpe?g|png|gif|webp|avif)(\?.*)?$/i', '.' . $fmt . '$2', $natural);
                                        if (is_string($neg) && $neg !== '') $newSlideUrl = $neg;
                                    }
                                }
                            }
                            $dataJson->background_slideshow_gallery[$i]->url = $newSlideUrl;
                        }

                        $dataJsonNew = json_encode($dataJson);
                        $dataJsonHTML = htmlentities($dataJsonNew, ENT_QUOTES);

                        return ' data-settings="' . $dataJsonHTML . '" ';
                    }
                }
            }
        }

        // Return the ORIGINAL matched string unchanged
        return $image[0];
    }

    public function replaceBackgroundImageStylesLocal($image)
    {
        $tag = $image[0];
        $url = $image['image'];


        if (!strpos($url, self::$zoneName)) {

            if ($this->defaultExcluded($url)) {
                return $tag;
            }

            if (self::isExcludedFrom('webp', $url)) {
                return $tag;
            }

            $site_url = str_replace(['https://', 'http://'], '', self::$siteUrl);
            $image_path = str_replace(['https://' . $site_url . '/', 'http://' . $site_url . '/'], '', $url);
            $image_path = explode('?', $image_path);
            $image_path = ABSPATH . $image_path[0];


            if (!file_exists($image_path)) {
                return $tag;
            } else {
                if (self::$webp == 'true' || self::$webp == '1') {
                    // Check if WebP Exists in PATH?
                    $webP = self::swap_ext_to($image_path, 'webp');

                    if (!file_exists($webP)) {
                        return $tag;
                    } else {
                        return self::swap_ext_in_tag($tag, 'webp');
                    }
                } else {
                    return $tag;
                }
            }
        }
    }

    public function replaceBackgroundImageStyles($image)
    {
        if (!empty($image[0])) {
            $tag = $image[0];
            $url = $image['image'];
            $original_url = $url;

            if (!empty($url)) {
                if (!strpos($url, self::$zoneName)) {
                    // File has already been replaced
                    if ($this->defaultExcluded($url)) {
                        return $tag;
                    }

                    // File is not an image
                    if (!self::isImage($url)) {
                        return $tag;
                    }

                    if (self::isExcluded($url)) {
                        return $tag;
                    }

                    if (self::isExcludedFrom('cdn', $url)) {
                        return $tag;
                    }


                    if ((empty(self::$externalUrlEnabled) || self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0')
                        && wp_parse_url($url, PHP_URL_HOST) && !self::imageUrlMatchingSiteUrl($url)) {
                        return $tag;
                    }

                    $webp = '/wp:' . self::$webp;
                    if (self::isExcludedFrom('webp', $url)) {
                        $webp = '';
                    }

                    $newUrl = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $url)) . '/u:' . self::uForCdn($url);
                    $return_tag = str_replace($original_url, $newUrl, $tag);

                    if (!empty($return_tag)) {
                        return $return_tag;
                    } else {
                        return $tag;
                    }
                } else {
                    return $tag;
                }
            }
        }

        return $tag;
    }

    public function replacePictureTags($html)
    {
        $html = preg_replace_callback('/<picture\b[^>]*>(.*?)<\/picture>/is', [__CLASS__, 'replaceSourceTags'], $html);
        return $html;
    }

    // Media/tag rewrites must never see <script> bodies — themes embed HTML snippets in
    // JS strings; injected quoted attrs break the string = SyntaxError class
    public static function maskMediaScripts($html, &$mask)
    {
        static $gen = 0;
        $gen++;
        $pfx  = '<!--WPC_SCRMASK_' . $gen . '_';   // per-call prefix: nested mask/restore must not collide
        $mask = [];
        $out = preg_replace_callback('#<script\b[^>]*>.*?</script>#is', function ($m) use (&$mask, $pfx) {
            if (stripos($m[0], '<img') === false && stripos($m[0], '<picture') === false
                && stripos($m[0], '<iframe') === false && stripos($m[0], '<video') === false
                && stripos($m[0], '<source') === false) {
                return $m[0];
            }
            $k = $pfx . count($mask) . '-->';
            $mask[$k] = $m[0];
            return $k;
        }, $html);
        if (!is_string($out)) {
            $mask = [];
            return $html;
        }
        return $out;
    }

    public static function unmaskMediaScripts($html, $mask)
    {
        return (is_array($mask) && $mask) ? strtr($html, $mask) : $html;
    }

    /**
     * The CDN lane's <img> rewrite. $isAmp and $pictureWebpEnabled are the render's AMP verdict
     * and <picture> wrap flag, handed down by stage_replace_image_tags from $ctx. A caller
     * outside a render passes neither and gets the request's own values: the verdict wps_ic_amp
     * reached when mainInit() built it, and the wrap flag mainInit() set on this class.
     */
    public function replaceImageTags($html, $isAmp = null, $pictureWebpEnabled = null)
    {
        if ($isAmp === null) {
            $isAmp = wps_ic_amp::$isAmp;
        }
        if ($pictureWebpEnabled === null) {
            $pictureWebpEnabled = self::$pictureWebpEnabled;
        }
        // The images this device does not paint, read once for the whole pass.
        $deviceHiddenImages = function_exists('wpc_device_hidden_image_set')
            ? wpc_device_hidden_image_set($html, (bool) $this->isMobile()) : [];
        $html = preg_replace_callback('/(?<![\"|\'])<img[^>]*>/i', function ($image) use ($isAmp, $pictureWebpEnabled, $deviceHiddenImages) {
            return $this->replaceImageTagsDo($image, $isAmp, $pictureWebpEnabled, $deviceHiddenImages);
        }, $html);
        return $html;
    }

    public function replaceImageTagsDoSlash($image)
    {

        if (isset($image[0]) && strpos($image[0], 'data-wpc-nd') !== false) {
            return $image[0];
        }

        if (strpos($_SERVER['REQUEST_URI'], 'embed') !== false) {
            return $image[0];
        }

        if (!empty($_GET['dbgAjax']) && function_exists('current_user_can') && current_user_can('manage_options')) {

            return print_r([$_SERVER, wp_doing_ajax(), self::$isAjax, $image[0]], true);
        }

        if ($this->checkIsSlashed($image[0])) {
            $imageElement = stripslashes($image[0]);
        } else {
            $imageElement = $image[0];
        }

        $newImageElement = '';
        $original_img_tag = [];
        $original_img_tag['original_tags'] = $this->getAllTags($imageElement, []);
        $original_img_tag['original_tags'] = self::wpc_backfill_img_dimensions($original_img_tag['original_tags']);

        if (!empty($_GET['ajaxImage'])) {
            return print_r([$original_img_tag, $imageElement], true);
        }

        if (strpos($original_img_tag['original_tags']['src'], 'data:image') !== false || strpos($original_img_tag['original_tags']['src'], 'blank') !== false) {
            $newImageElement = $imageElement;
        } else {
            $newImageElement = '<img data-image-el-count="' . self::$imageCounter . '"';

            // Check if both src and data-src are defined
            $preferredSrc = '';
            if (isset($original_img_tag['original_tags']['src']) && isset($original_img_tag['original_tags']['data-src'])) {
                // If both are defined, use data-src. Src is probably a palceholder and real src is in data-src
                $preferredSrc = $original_img_tag['original_tags']['data-src'];
            }


            foreach ($original_img_tag['original_tags'] as $tag => $value) {
                if ($tag == 'src') {
                    $src = ($preferredSrc) ? $preferredSrc : $value;


                    if (!self::isImage($src)) {
                        $newImageElement .= 'src="' . $src . '" ';
                        continue;
                    }

                    $webp = '/wp:' . self::$webp;
                    if (self::isExcludedFrom('webp', $src)) {
                        $webp = '/wp:0';
                    }

                    $src = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $src)) . '/u:' . self::uForCdn($src);
                    $newImageElement .= 'src="' . $src . '" ';
                } else if ($tag == 'data-src' && $preferredSrc) {
                    // Skip adding data-src as separate attribute if we've already used it for src
                    continue;
                } else if (!is_null($value)) {
                    $newImageElement .= $tag . '="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '" ';
                } else {
                    $newImageElement .= $tag . ' ';
                }
            }
            // Inject loading="lazy" on LCP-optimized eager IMGs: without it, sizes="auto" on the picture


            $is_lcp_candidate = (!empty(self::$settings['optimize-lcp'])
                && self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages
                && strpos($newImageElement, 'loading=') === false
                && apply_filters('wpc_lcp_lazy', false, isset($original_img_tag['original_tags']['src']) ? $original_img_tag['original_tags']['src'] : ''));
            if ($is_lcp_candidate) {
                $newImageElement .= 'loading="lazy" ';
            }
            $newImageElement .= '/>';
        }


        $newImageElement = self::maybe_naturalize_single_src($newImageElement);
        $newImageElement = self::naturalize_svg_src($newImageElement);
        $newImageElement = self::activate_lazy_srcset_auto($newImageElement);
        $newImageElement = self::naturalize_srcset_widths($newImageElement);


        $newImageElement = self::auto_sizes_for_lazy_img($newImageElement);

        if ($this->checkIsSlashed($image[0])) {
            $newImageElement = addslashes($newImageElement);
        }

        return $newImageElement;
    }

    public function checkIsSlashed($string)
    {
        $pattern = "/\\\\[\"'\\\\]/";
        return preg_match($pattern, $string) > 0;
    }

    public function replaceSourceTags($html)
    {
        // Get just the inside of <picture> tag
        //$insideElements = $html[1];

        if (self::$isMobile) {


            if (!empty(self::$settings['optimize-lcp'])) {
                $html[0] = preg_replace('/(<(?:source|img)\b(?=[^>]*\ssrc=)(?![^>]*wpc-lcp-optimized)[^>]*)\s+srcset="[^"]*"([^>]*>)/i', '$1$2', $html[0]);
            } else {
                $html[0] = preg_replace('/(<(?:source|img)\b(?=[^>]*\ssrc=)[^>]*)\s+srcset="[^"]*"([^>]*>)/i', '$1$2', $html[0]);
            }
        }

        $html = preg_replace_callback('/(?:https?:\/\/|\/)[^\s]+\.(jpg|jpeg|png|gif|svg|webp)/i', [__CLASS__, 'replaceSourceSrcset'], $html);
        return $html[0];
    }

    public function replaceSourceSrcset($html)
    {
        $url = $html[0];

        if (empty($url)) return $html[0];

        if (strpos($url, 'data:image') !== false || strpos($url, 'blank') !== false || strpos($url, 'gform_ajax_spinner') !== false || strpos($url, 'spinner.svg') !== false) {
            return $html[0];
        }

        if (strpos($url, self::$zoneName) !== false) {
            // File has already been replaced
            return $url;
        }

        if ($this->defaultExcluded($url)) {
            return $url;
        }

        // File is not an image
        if (!self::isImage($url)) {
            return $url;
        }

        if (self::isExcluded($url)) {
            return $url;
        }

        if (self::isExcludedFrom('cdn', $url)) {
            return $url;
        }

        $webp = '/wp:' . self::$webp;
        if (self::isExcludedFrom('webp', $url)) {
            $webp = '';
        }

        $newUrl = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $url)) . '/u:' . self::uForCdn($url);
        return $newUrl;
    }


    public static function wpc_srcset_smallest_url($srcset)
    {
        $best = '';
        $bestScore = PHP_INT_MAX;
        foreach (explode(',', (string) $srcset) as $cand) {
            $cand = trim($cand);
            if ($cand === '') {
                continue;
            }
            $parts = preg_split('/\s+/', $cand);
            $url = trim((string) $parts[0]);
            if ($url === '' || stripos($url, 'data:') === 0 || strpos($url, '/') === false) {
                continue;
            }
            $desc = isset($parts[1]) ? strtolower(trim((string) $parts[1])) : '1x';
            $score = 1000000;
            if (preg_match('/^([0-9.]+)x$/', $desc, $dm)) {
                $score = (int) round((float) $dm[1] * 1000);
            } elseif (preg_match('/^(\d+)w$/', $desc, $dm)) {
                $score = (int) $dm[1];
            }
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $url;
            }
        }
        return $best;
    }

    public static function wpc_repoint_img_to_local_host($tag)
    {
        try {
            if (!is_string($tag) || $tag === '' || !defined('ABSPATH') || !defined('WP_CONTENT_DIR')
                || !apply_filters('wpc_img_host_twin', true)) {
                return $tag;
            }
            $siteHost = strtolower((string) parse_url((string) self::$siteUrl, PHP_URL_HOST));
            if ($siteHost === '') {
                return $tag;
            }
            $zone = strtolower((string) self::$zoneName);
            static $seen = [];
            static $n = 0;
            $out = preg_replace_callback('#(?:https?:)?//([a-z0-9.\-]+)(?::\d+)?(/wp-(?:content|includes)/[^\s"\'<>,)]+)#i', function ($m) use ($siteHost, $zone, &$seen, &$n) {
                $host = strtolower($m[1]);
                if ($host === $siteHost || ($zone !== '' && $host === $zone) || strpos($host, 'zapwp') !== false || strpos($host, 'b-cdn.net') !== false) {
                    return $m[0];
                }
                $path = (string) strtok($m[2], '?#');
                if ($path === '' || strpos($path, '..') !== false || !preg_match('/\.(?:png|jpe?g|gif|webp|avif|svg)$/i', $path)) {
                    return $m[0];
                }
                $k = $host . $path;
                if (!array_key_exists($k, $seen)) {
                    if ($n >= 64) {
                        return $m[0];
                    }
                    $n++;
                    $disk = (strpos($path, '/wp-content/') === 0)
                        ? rtrim(WP_CONTENT_DIR, '/') . substr($path, 11)
                        : rtrim(ABSPATH, '/') . $path;
                    $seen[$k] = @is_file($disk);
                }
                if (empty($seen[$k])) {
                    return $m[0];
                }
                return 'https://' . $siteHost . $m[2];
            }, $tag);
            return is_string($out) ? $out : $tag;
        } catch (\Throwable $e) {
            return $tag;
        }
    }

    public static function wpc_image_stem($s)
    {
        $s = strtolower((string) $s);
        if (strpos($s, '/') !== false || strpos($s, '.') !== false) {
            $s = (string) basename((string) strtok($s, '?#'));
            $s = (string) preg_replace('/\.[a-z0-9]{2,5}$/', '', $s);
        }
        return (string) preg_replace('/(-\d+x\d+|-scaled|@\dx)+$/', '', $s);
    }

    public static function wpc_measured_image_data()
    {
        static $m = null;
        if ($m !== null) {
            return $m;
        }
        $m = ['lcp' => []];
        try {
            if (!apply_filters('wpc_measured_dims', true)) {
                return $m;
            }
            // The page's own measured LCP image per device; the home page's never forces an
            // image eager on another page.
            foreach (['mobile', 'desktop'] as $dev) {
                $lcpElement = wps_ic_atf_observation::lcpElement($dev, true);
                if (isset($lcpElement['type']) && $lcpElement['type'] === 'img' && !empty($lcpElement['stem'])) {
                    $st = self::wpc_image_stem((string) $lcpElement['stem']);
                    if ($st !== '' && strlen($st) <= 120) {
                        $m['lcp'][$st] = 1;
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return $m;
    }

    public static function wpc_is_measured_lcp_match($src)
    {
        $m = self::wpc_measured_image_data();
        if (empty($m['lcp']) || !is_string($src) || $src === '' || stripos($src, 'data:') === 0) {
            return false;
        }
        return isset($m['lcp'][self::wpc_image_stem($src)]);
    }

    /**
     * Width and height for an <img> the escaped-markup passes rewrite (script-content images,
     * slashed AJAX fragments) — markup the image_dims stage cannot see. Same rule as that stage,
     * wps_ic_image_sizing::fileDims(): only an <img> with neither attribute is given both, from
     * what names this exact file. The old backfill took the attachment's FULL-size meta for a
     * sub-size URL and overwrote a width the page had written.
     */
    public static function wpc_backfill_img_dimensions($tags)
    {
        try {
            if (!is_array($tags) || !apply_filters('wpc_backfill_img_dimensions', true) || !class_exists('wps_ic_image_sizing')) {
                return $tags;
            }
            if (!empty($tags['width']) || !empty($tags['height']) || empty($tags['src'])) {
                return $tags;
            }
            $dims = wps_ic_image_sizing::fileDims((string) $tags['src'], isset($tags['class']) ? (string) $tags['class'] : '',
                !empty($tags['srcset']) || !empty($tags['data-srcset']), isset($tags['style']) ? (string) $tags['style'] : '');
            if ($dims === null || $dims['w'] < 6 || $dims['h'] < 6) {
                return $tags;
            }
            $tags['width'] = (string) (int) round($dims['w']);
            $tags['height'] = (string) (int) round($dims['h']);
            $tags[$dims['source'] === 'observed' ? 'data-wpc-md' : 'data-wpc-bf'] = '1';
            return $tags;
        } catch (\Throwable $e) {
            return $tags;
        }
    }
    public function replaceImageTagsDo($image, $isAmp, $pictureWebpEnabled, $deviceHiddenImages)
    {


        if (isset($image[0]) && strpos($image[0], 'data-wpc-nd') !== false) {
            return $image[0];
        }


        if (isset($image[0]) && (strpos($image[0], 'wps-ic-cdn') !== false
            || strpos($image[0], 'data-wpc-fb') !== false
            || strpos($image[0], 'wpc-size="preserve"') !== false
            || strpos($image[0], 'wps-ic-lazy-image') !== false)) {
            return $image[0];
        }

        // Set up local variables at the beginning - don't modify self:: directly
        $lazyEnabled = self::$lazyEnabled;
        $adaptiveEnabled = self::$adaptiveEnabled;


        if (preg_match('/<img[^>]+src="([^"]+)"[^>]*>/i', $image[0], $matches)) {
            $url = $matches[1];

            if (strpos($url, '/') === 0) {
                $absolute_url = site_url($url);

                $image_path = ABSPATH . $url;

                if (file_exists($image_path)) {
                    // Replace src attribute specifically
                    $image[0] = preg_replace('/src="' . preg_quote($url, '/') . '"/', 'src="' . $absolute_url . '"', $image[0]);

                    // Only process srcset if it actually contains relative URLs
                    if (preg_match('/srcset="[^"]*?' . preg_quote($url, '/') . '/', $image[0]) && !preg_match('/srcset="[^"]*?https?:\/\/[^"]*?' . preg_quote($url, '/') . '/', $image[0])) {
                        $image[0] = preg_replace('/srcset="([^"]*?)' . preg_quote($url, '/') . '/', 'srcset="$1' . $absolute_url, $image[0]);
                    }

                }
            }
        }

        if (strpos($_SERVER['REQUEST_URI'], 'embed') !== false) {
            return $image[0];
        }

        if (!empty($_GET['dbgAjax']) && function_exists('current_user_can') && current_user_can('manage_options')) {

            return print_r([$_SERVER, wp_doing_ajax(), self::$isAjax, $image[0]], true);
        }

        // Woocommerce ajax load more?
        if (strpos($image[0], 'attachment-woocommerce') !== false) {


        }

        if (self::$isAjax) {
            $AjaxImage = $this->ajaxImage($image[0]);
            return $AjaxImage;
        }


        if (strpos($_SERVER['REQUEST_URI'], 'pjax=') !== false) {
            $adaptiveEnabled = '0';
        }


        $lazyExcludes = ['breakdance', 'skip-lazy', 'notlazy', 'nolazy', 'jet-image', 'data-lazy'];

        foreach ($lazyExcludes as $exclude) {
            if (strpos($image[0], $exclude) !== false) {
                $lazyEnabled = '0';
                $adaptiveEnabled = '0';
                break;
            }
        }

        if (strpos($image[0], 'data:image') !== false || strpos($image[0], 'blank') !== false || strpos($image[0], 'gform_ajax_spinner') !== false || strpos($image[0], 'spinner.svg') !== false || preg_match('#/placeholder\.(?:png|gif|jpe?g|webp|svg)(?:[?"\']|$)#i', $image[0])) {
            return $image[0];
        }

        // v7.10.717 - an image the markup hides on THIS device must not consume an
        // eager-window slot, must not be promoted, and must end up lazy.
        $isHiddenOrBelowFold = false;
        if (!empty($deviceHiddenImages) && function_exists('wpc_device_hidden_has')) {
            foreach (['src', 'data-src', 'data-cp-src'] as $srcAttribute) {
                if (preg_match('/\b' . $srcAttribute . '="([^"]+)"/i', $image[0], $hiddenSrcMatch)
                    && wpc_device_hidden_has($deviceHiddenImages, $hiddenSrcMatch[1])) {
                    $isHiddenOrBelowFold = true;
                    break;
                }
            }
        }
        if (!$isHiddenOrBelowFold && self::wpc_is_census_below_fold($image[0])) {
            $isHiddenOrBelowFold = true;
        }

        if (!$isHiddenOrBelowFold) {
            self::$lazyLoadedImages++;
        }

        $skipLazy = false;
        $isLogo = false;
        $isSlider = false;

        if (!strpos($image[0], self::$zoneName)) {
            // File has already been replaced
            if ($this->defaultExcluded($image[0])) {
                return $image[0];
            }

            // File is not an image
            if (!self::isImage($image[0])) {
                return $image[0];
            }

            $image[0] = self::wpc_repoint_img_to_local_host($image[0]);
            if ((self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0') && !self::imageUrlMatchingSiteUrl($image[0])) {
                return $image[0];
            }

        } else {

            if (strpos($image[0], 'm:') !== false) {
                return $image[0];
            }
        }

        // Something for cookie??
        if (strpos($image[0], 'cookie') !== false) {
            $image[0] = stripslashes($image[0]);
            return $image[0];
        }


        // Remove fetchpriority attribute (both quote forms — a single-quoted or empty theme
        // stamp must not survive the strip and shadow the LCP branch's restore below)
        $hadHighFetchPriority = (bool) preg_match('/\bfetchpriority=(["\']?)high\1/i', $image[0]);
        $image[0] = preg_replace('/\bfetchpriority=(?:"[^"]*"|\'[^\']*\')\s*/si', '', $image[0]);
        // Remove decoding attribute
        $image[0] = preg_replace('/\bdecoding="[^"]*"\s*/si', '', $image[0]);

        if (!empty(self::$settings['remove-srcset']) && self::$settings['remove-srcset'] == '1') {
            $image[0] = preg_replace('/\bsrcset="[^"]*"\s*/si', '', $image[0]);
            $image[0] = preg_replace('/\bsizes="[^"]*"\s*/si', '', $image[0]);
        }


        $original_img_tag = [];
        $original_img_tag['original_tags'] = $this->getAllTags($image[0], []);
        if (empty($original_img_tag['original_tags']['src']) && !empty($original_img_tag['original_tags']['srcset'])) {
            $smallestSrcsetUrl = self::wpc_srcset_smallest_url((string) $original_img_tag['original_tags']['srcset']);
            if ($smallestSrcsetUrl !== '') {
                $original_img_tag['original_tags']['src'] = $smallestSrcsetUrl;
            }
        }


        // Width and height were decided at image_dims, before this pass, by the image-sizing
        // owner: a width found here is the page's or the owner's and is kept (wpc-size="preserve").

        if (!empty($original_img_tag['original_tags']['src'])) {
            // Check if the URL contains spaces or encoded spaces (%20)
            if (strpos($original_img_tag['original_tags']['src'], ' ') !== false || strpos($original_img_tag['original_tags']['src'], '%20') !== false) {
                return $image[0];
            }
        }

        /**
         * strpos blank is required to make it work when image has placeholder containing "blank" in it.
         */
        $image_source = '';
        if (!empty($original_img_tag['original_tags']['src'])) {
            $image_source = $original_img_tag['original_tags']['src'];
        } else {
            if (!empty($original_img_tag['original_tags']['data-src'])) {
                $image_source = $original_img_tag['original_tags']['data-src'];
            } elseif (!empty($original_img_tag['original_tags']['data-cp-src'])) {
                $image_source = $original_img_tag['original_tags']['data-cp-src'];
            } elseif (!empty($original_img_tag['original_tags']['data-oi'])) {
                // Porto Lazy Load
                $image_source = $original_img_tag['original_tags']['data-oi'];
            }
        }

        if (!empty($original_img_tag['original_tags']['data-src'])) {
            $image_source = $original_img_tag['original_tags']['data-src'];
        }


        if (!empty($original_img_tag['original_tags']['data-mk-image-src-set'])) {
            $jsonString = htmlspecialchars_decode($original_img_tag['original_tags']['data-mk-image-src-set']);
            $decodedArray = json_decode($jsonString, true);
            if (!empty($decodedArray['default'])) {
                $image_source = $decodedArray['default'];
            }
        }


        if (self::isExcludedFrom('cdn', $image_source)) {
            return $image[0];
        }

        if (!empty($original_img_tag['original_tags']['data-interchange'])) {

            return $image[0];
        }

        $original_img_tag['original_src'] = $image_source;
        $original_img_tag['original_srcset'] = !empty($original_img_tag['original_tags']['srcset'])
            ? $original_img_tag['original_tags']['srcset'] : '';

        /**
         * Fetch image actual size
         */
        $originalSizeTags = false;
        if (!empty($original_img_tag['original_tags']['width'])) {
            $size = [];
            $size[0] = $original_img_tag['original_tags']['width'];
            $size[1] = $original_img_tag['original_tags']['height'];
            $originalSizeTags = true;
        } else {
            $size = self::get_image_size($image_source);
        }

        // SVG Placeholder
        $source_svg = 'data:image/svg+xml;base64,' . base64_encode(((int) $size[0] > 0 && (int) $size[1] > 0)
            ? '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size[0] . '" height="' . $size[1] . '"><path d="M2 2h' . $size[0] . 'v' . $size[1] . 'H2z" fill="#fff" opacity="0"/></svg>'
            : '<svg xmlns="http://www.w3.org/2000/svg"></svg>');

        $image_source = $this->specialChars($image_source);

        if ($isAmp) {
            $source_svg = $image_source;
            $lazyEnabled = '0';
            $adaptiveEnabled = '0';
        }

        if (isset($_GET['preload']) && !empty($_GET['preload'])) {
            $source_svg = $image_source;
            $lazyEnabled = '0';
            $adaptiveEnabled = '0';
        }

        if (!empty($_GET['rl_gallery_no'])) {

            $source_svg = $image_source;
            $lazyEnabled = '0';
            $adaptiveEnabled = '0';
        }

        if (empty($original_img_tag['original_tags']['class']) || !isset($original_img_tag['original_tags']['class'])) {
            $original_img_tag['original_tags']['class'] = '';
        }

        if (empty($original_img_tag['class']) || !isset($original_img_tag['class'])) {
            $original_img_tag['class'] = '';
        }

        if (!empty($original_img_tag['class']) && strpos($original_img_tag['class'], 'kb-img') !== false) {
            $original_img_tag['class'] = '';
        }

        $lowerClass = strtolower($original_img_tag['original_tags']['class']);
        // v7.21.264 — THE FORK .262 MISSED. This is where slider images actually escape:
        // $source_svg becomes the real url and $isSlider skips the whole lazy pipeline, so
        // the vp227 guard downstream was sliced dead for every 'slide' class (columbus
        // Section-3: wps-ic-cdn swiper-slide-image, 7 live rows after .263). Fragile
        // engines (rs-/revslider/lgx/dynamic-image/breakdance) keep the bypass; generic
        // slide/swiper images take the NORMAL lazy pipeline (svg box + data-src, pixel
        // restores in-view, wpcWatchInjected covers swiper clones) behind the same
        // wpc_park_slider_imgs rollback filter. Dimensionless imgs still fall through to
        // a real src inside the pipeline (no svg mintable = no park), never blank.
        $isFragileSliderClass = (bool) preg_match('/(?:^|[^a-z0-9])rs[-_]|revslider|rev_slider|lgx_app|dynamic-image|breakdance/', $lowerClass);
        $isSlideClass = strpos($lowerClass, 'slide') !== false || strpos($lowerClass, 'swiper') !== false;
        if ($isFragileSliderClass || ($isSlideClass && !apply_filters('wpc_park_slider_imgs', true))) {
            $source_svg = $image_source;
            $isSlider = true;
        }

        $lowerImageUrl = $imageUrl = strtolower($image_source);

        if (strpos($lowerImageUrl, 'logo') !== false || (!empty($original_img_tag['class']) && strpos($lowerClass, 'logo')) !== false) {
            if (strpos($lowerImageUrl, 'wordpress') === false) {
                $isLogo = true;
            }
        }

        if (!empty($original_img_tag['sizes'])) {
            $original_img_tag['additional_tags']['sizes'] = $original_img_tag['sizes'];
        }


        $webp = '/wp:' . self::$webp;
        if (self::$excludes_class->isWebpExcluded($image_source, $original_img_tag['original_tags']['class'])) {
            $webp = '/wp:0';
            $original_img_tag['original_tags']['class'] .= ' wpc-excluded-webp';
            $original_img_tag['additional_tags']['wpc-data'] = 'excluded-webp ';
        }

        if (self::$excludes_class->isLazyExcluded($image_source, $original_img_tag['original_tags']['class'])) {
            $original_img_tag['additional_tags']['wpc-data'] = 'excluded-lazy ';
            $isLogo = true;
        }

        $original_img_tag['additional_tags']['data-wpc-loaded'] = 'true';


        // Is LazyLoading enabled in the plugin?
        if (!$isSlider && !empty($lazyEnabled) && $lazyEnabled == '1' && !self::$lazyOverride) {

            if ($isLogo) {
                // TODO: This is a fix for logo not being on CDN
                $logoWidth = $this::getCurrentMaxWidth('logo');
                #$logoWidth = 100;

                $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $logoWidth . '/u:' . self::uForCdn($image_source);
                $original_img_tag['original_tags']['src'] = $original_img_tag['src'];
                $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wps-ic-logo wpc-excluded-adaptive';
                $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
                unset($original_img_tag['additional_tags']['data-wpc-loaded']);
            } else if (!$isHiddenOrBelowFold && self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages) {
                // Don't lazy load LCP Fix !!
                // If we loaded less images than skip first variable
                $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth('logo') . '/u:' . self::uForCdn($image_source);
                $original_img_tag['original_tags']['src'] = $original_img_tag['src'];
                $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wpc-excluded-adaptive wpc-lazy-skipped1';
                $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
                unset($original_img_tag['additional_tags']['data-wpc-loaded']);
            } else {
                if ($isHiddenOrBelowFold || self::$lazyLoadedImages > self::$lazyLoadedImagesLimit) {
                    // We are over lazy limit, load placeholder
                    $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
                    $original_img_tag['src'] = $source_svg;
                    $original_img_tag['data-src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);
                    $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wps-ic-lazy-image';
                    $original_img_tag['additional_tags']['loading'] = 'lazy';
                } else {
                    // We are under lazy limit, load image
                    $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth(1, true) . '/u:' . self::uForCdn($image_source);
                    $original_img_tag['data-src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth(1, true) . '/u:' . self::uForCdn($image_source);
                    $original_img_tag['additional_tags']['class'] = 'wps-ic-live-cdn wpc-excluded-adaptive wpc-lazy-skipped2';
                    $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
                    unset($original_img_tag['additional_tags']['data-wpc-loaded']);
                }

                // Data cp-src
                if (!empty($original_img_tag['original_tags']['data-cp-src'])) {
                    $original_img_tag['original_tags']['data-cp-src'] = $original_img_tag['data-src'];
                }
            }
        } else {
            // We enter this if "isLOGO" == true because of lazy disabled
            if (!$isSlider && !empty($adaptiveEnabled) && $adaptiveEnabled == '1') {
                $original_img_tag['src'] = $source_svg;
                $original_img_tag['additional_tags']['class'] = 'wps-ic-cdn';

                /**
                 * If current image is logo then force image, don't lazy load
                 */
                if ($isLogo || strpos($lowerImageUrl, 'logo') !== false) {
                    // TODO: Fix for logos not on CDN
                    $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
                    $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);
                    $original_img_tag['original_tags']['src'] = $original_img_tag['src'];
                } else {
                    $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
                    $original_img_tag['src'] = $source_svg;
                    $original_img_tag['data-src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);

                    // Data cp-src
                    if (!empty($original_img_tag['original_tags']['data-cp-src'])) {
                        $original_img_tag['original_tags']['data-cp-src'] = $original_img_tag['data-src'];
                    }
                }
            } else {
                // Adaptive is Disabled
                $original_img_tag['additional_tags']['class'] = 'wps-ic-cdn';

                if (strpos($lowerClass, 'lazy') !== false) {
                    if (!empty($original_img_tag['original_tags']['data-src'])) {
                        $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $original_img_tag['original_tags']['data-src']));
                        $original_img_tag['data-src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($original_img_tag['original_tags']['data-src']);
                    } else {
                        $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
                        $original_img_tag['data-src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);
                    }

                    $original_img_tag['original_tags']['src'] = $original_img_tag['data-src'];
                    $original_img_tag['original_tags']['data-src'] = $original_img_tag['data-src'];
                    $original_img_tag['src'] = $original_img_tag['data-src'];

                    // Data cp-src
                    if (!empty($original_img_tag['original_tags']['data-cp-src'])) {
                        $original_img_tag['original_tags']['data-cp-src'] = $original_img_tag['data-src'];
                    }
                } else {
                    $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
                    $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);

                    // Data cp-src
                    if (!empty($original_img_tag['original_tags']['data-cp-src'])) {
                        $original_img_tag['original_tags']['data-cp-src'] = $original_img_tag['src'];
                    }
                }
            }
        }


        // Lazy Loading - Fix for LCP Lazy Issues
        $forceEagerLcp = !$isHiddenOrBelowFold && (!empty($hadHighFetchPriority) || self::wpc_is_measured_lcp_match($image_source))
            && apply_filters('wpc_lcp_force_eager', true, $image_source);
        if (!$isHiddenOrBelowFold && (self::$lazyLoadedImages <= self::$lazyLoadSkipFirstImages || $forceEagerLcp)) {
            $skipLazy = true;
            $maxWidth = $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $image_source));
            $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $maxWidth . '/u:' . self::uForCdn($image_source);
            $original_img_tag['data-count'] = self::$lazyLoadedImages;

            if (!empty(self::$settings['fetchpriority-high']) && self::$settings['fetchpriority-high'] == '1') {
                $original_img_tag['additional_tags']['fetchpriority'] = 'high';
            }


            if (!empty(self::$settings['optimize-lcp'])) {
                $mode = !empty(self::$zoneName) ? 'cdn' : 'local';
                if ($mode === 'cdn') {


                    $fallbackWidth = !empty(self::$settings['maxWidth']) ? (int) self::$settings['maxWidth'] : 2560;
                    if ($fallbackWidth < 400) $fallbackWidth = 400;

                    $fb_src_w = !empty($original_img_tag['original_tags']['width']) ? (int) $original_img_tag['original_tags']['width'] : 0;
                    if ($fb_src_w > 0 && $fb_src_w < $fallbackWidth) $fallbackWidth = $fb_src_w;
                    // v7.21.337 — THE MEASUREMENT WE WIRED IS THE AUTHORITY (rosario PSI:
                    // LCP w:1000 for a 763px display, w:800 for 504px — attribute width is a
                    // guess; lcp.json's css_w is the crit-team's measured render). When the
                    // census carries this stem, the src caps at the largest measured DPR rung
                    // (css_w x2 — never below measured, retina covered: the .682 floor law)
                    // and the srcset becomes the measured rung ladder so the browser picks
                    // css_w x DPR exactly. No measurement -> today's attribute path, unchanged.
                    $measuredRungWidths = self::wpc_census_rung_targets($image_source);
                    if (!empty($measuredRungWidths) && apply_filters('wpc_lcp_measured_width', true)) {
                        $measuredRungCap = (int) max($measuredRungWidths);
                        if ($measuredRungCap >= 100 && $measuredRungCap < $fallbackWidth) {
                            $fallbackWidth = $measuredRungCap;
                        }
                    }
                    $original_img_tag['src'] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $fallbackWidth . '/u:' . self::uForCdn($image_source);
                    $measuredRungSrcset = '';
                    if (!empty($measuredRungWidths) && apply_filters('wpc_lcp_measured_width', true)) {
                        $measuredRungCandidates = [];
                        foreach ($measuredRungWidths as $rungWidth) {
                            $rungWidth = (int) $rungWidth;
                            if ($rungWidth >= 100) {
                                $measuredRungCandidates[$rungWidth] = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $rungWidth
                                    . '/u:' . self::uForCdn($image_source) . ' ' . $rungWidth . 'w';
                            }
                        }
                        ksort($measuredRungCandidates);
                        $measuredRungSrcset = implode(', ', $measuredRungCandidates);
                    }
                    // A vector has no width rungs: the ladder repeated one SVG as 400w, 480w, 512w,
                    // and the later naturalize passes turned those candidates into origin URLs
                    // (noktaltema.com/tibet/nakliye, 2026-09-24, mobil-alt-bar-*.svg). The tag keeps
                    // the srcset and sizes the page gave it.
                    $lcpSourceIsVector = (bool) preg_match('/\.svgz?(?:[?#&]|$)/i', (string) preg_replace('#^.*/(?:u|a):#i', '', (string) $image_source));
                    $pageSrcset = isset($original_img_tag['original_tags']['srcset']) ? (string) $original_img_tag['original_tags']['srcset'] : '';
                    $measuredSlotSizesApplied = false;
                    if (!$lcpSourceIsVector) {
                        $original_img_tag['original_tags']['srcset'] = $measuredRungSrcset !== ''
                            ? $measuredRungSrcset
                            : self::buildLcpSrcset($image_source, !empty($original_img_tag['original_tags']['width']) ? (int) $original_img_tag['original_tags']['width'] : 0);
                        // measured sizes to match the measured rungs
                        if ($measuredRungSrcset !== '') {
                            $measuredSlotSizes = self::wpc_census_slot_sizes($image_source, '');
                            if (is_string($measuredSlotSizes) && $measuredSlotSizes !== '') {
                                $original_img_tag['original_tags']['sizes'] = $measuredSlotSizes;
                                $measuredSlotSizesApplied = true;
                            }
                        }
                        if (!$measuredSlotSizesApplied) {
                            self::apply_lcp_fallback_sizes($original_img_tag, $pageSrcset, $image_source);
                        }
                    }
                    if (function_exists('wpc_diagnostic_log')) {
                        wpc_diagnostic_log('LCP_BETA', 'cdn-mode img#' . self::$lazyLoadedImages . ' src=' . basename(parse_url($image_source, PHP_URL_PATH) ?: $image_source) . ' fallback-w=' . $fallbackWidth);
                    }
                } else {


                    self::apply_lcp_fallback_sizes($original_img_tag, isset($original_img_tag['original_tags']['srcset']) ? (string) $original_img_tag['original_tags']['srcset'] : '', $image_source);
                    if (function_exists('wpc_diagnostic_log')) {
                        wpc_diagnostic_log('LCP_BETA', 'local-mode img#' . self::$lazyLoadedImages . ' src=' . basename(parse_url($image_source, PHP_URL_PATH) ?: $image_source));
                    }
                }
                $original_img_tag['original_tags']['class'] .= ' wpc-lcp-optimized wpc-lazy-skipped3';
                // Don't stamp wpc-data: excluded-adaptive — this image IS adaptive now


                if (apply_filters('wpc_lcp_lazy', false, $image_source)) {
                    $original_img_tag['additional_tags']['loading'] = 'lazy';
                } else {
                    // WP core prints loading="lazy" natively; an LCP-optimized image must
                    // not stay lazy (lazy + fetchpriority=high contradict; LH flags it)
                    if (!empty($original_img_tag['original_tags']['loading'])) {
                        $original_img_tag['original_tags']['loading'] = 'eager';
                    } else {
                        $original_img_tag['additional_tags']['loading'] = 'eager';
                    }
                    // v7.21.51 — the strip above removed whatever fetchpriority the page had
                    // (ganoderma: theme stamped an EMPTY one) and nothing restored it, so the
                    // LCP image fetched at default priority and LH flagged "fetchpriority=high
                    // should be applied". The eager LCP leg carries high; the device-hidden
                    // guard below still clears it for hidden legs.
                    if (apply_filters('wpc_lcp_fetchpriority', true)) {
                        $original_img_tag['additional_tags']['fetchpriority'] = 'high';
                    }
                }
            } else {
                #$original_img_tag['original_tags']['srcset'] = $this->rewriteSrcset($original_img_tag, $original_img_tag['original_tags']['srcset']);
                $original_img_tag['original_tags']['class'] .= ' wpc-excluded-adaptive wpc-lazy-skipped3';
                $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
            }
            unset($original_img_tag['additional_tags']['data-wpc-loaded'], $original_img_tag['original_tags']['data-src'], $original_img_tag['data-src']);
        }


        // v7.10.717 - device-hidden safety net across every branch above: whatever
        // path built this tag, a hidden-on-this-device image ships lazy and never
        // carries a high fetch priority.
        if ($isHiddenOrBelowFold) {
            if (!empty($original_img_tag['original_tags']['loading'])) {
                $original_img_tag['original_tags']['loading'] = 'lazy';
            } else {
                $original_img_tag['additional_tags']['loading'] = 'lazy';
            }
            if (!empty($original_img_tag['original_tags']['fetchpriority'])) {
                $original_img_tag['original_tags']['fetchpriority'] = 'low';
            }
            unset($original_img_tag['additional_tags']['fetchpriority']);
            $original_img_tag['additional_tags']['class'] = trim((isset($original_img_tag['additional_tags']['class']) ? $original_img_tag['additional_tags']['class'] : '') . ' wpc-device-hidden');
        }

        // v7.10.718 - eager-lane small SVGs inline as data: URIs - zero fetch, zero chain.
        // Lazy ones stay lazy: they never charge the mark, so inlining them would only pay
        // document bytes for nothing.
        if (!$isHiddenOrBelowFold && function_exists('wpc_svg_inline_data') && empty($original_img_tag['data-src'])) {
            $loadingAttr = !empty($original_img_tag['additional_tags']['loading'])
                ? $original_img_tag['additional_tags']['loading']
                : (!empty($original_img_tag['original_tags']['loading']) ? $original_img_tag['original_tags']['loading'] : '');
            $inlineCandidateSrc = !empty($original_img_tag['original_tags']['src'])
                ? (string) $original_img_tag['original_tags']['src']
                : (!empty($original_img_tag['src']) ? (string) $original_img_tag['src'] : '');
            if ($loadingAttr !== 'lazy' && $inlineCandidateSrc !== '' && strpos($inlineCandidateSrc, 'data:') !== 0) {
                $svgDataUri = wpc_svg_inline_data($inlineCandidateSrc);
                if ($svgDataUri !== '') {
                    $original_img_tag['src'] = $svgDataUri;
                    $original_img_tag['original_tags']['src'] = $svgDataUri;
                    $original_img_tag['original_tags']['srcset'] = '';
                }
            }
        }

        // Recalculate dimensions once after all conditions
        if (empty($originalSizeTags)) {
            if (isset($maxWidth) && $maxWidth > 1 && !empty($original_img_tag['original_tags']['width']) && !empty($original_img_tag['original_tags']['height'])) {
                $originalWidth = $original_img_tag['original_tags']['width'];
                $originalHeight = $original_img_tag['original_tags']['height'];
                $original_img_tag['original_tags']['width'] = $maxWidth;
                $original_img_tag['original_tags']['height'] = round(($originalHeight / $originalWidth) * $maxWidth);
            }
        }

        // Patch for images that already have predefined size tag
        if (empty($originalSizeTags)) {
            if (empty(self::$settings['add-image-sizes']) || self::$settings['add-image-sizes'] == '0') {
                unset($original_img_tag['original_tags']['width'], $original_img_tag['original_tags']['height']);
            }
        } else {
            // It has original tags and preserve them
            $original_img_tag['original_tags']['wpc-size'] = 'preserve';
        }


        if ($adaptiveEnabled == '0') {
            $original_img_tag['original_tags']['class'] .= ' wpc-excluded-adaptive';
            $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
        }


        // PerfMatters Fix for lazy loading
        if (self::$perfMattersActive) {
            if (!empty($original_img_tag['data-src'])) {
                $original_img_tag['original_tags']['src'] = $original_img_tag['data-src'];
                $original_img_tag['src'] = $original_img_tag['data-src'];
                unset($original_img_tag['data-src']);
            }
        }

        if (empty($original_img_tag['original_tags']['srcset']) || !isset($original_img_tag['original_tags']['srcset'])) {
            $original_img_tag['original_tags']['srcset'] = '';
        }

	    if (!isset($original_img_tag['original_tags']['data-srcset'])) {
		    $original_img_tag['original_tags']['data-srcset'] = '';
	    }


        $isLcpOptimized = !empty(self::$settings['optimize-lcp'])
            && strpos($original_img_tag['original_tags']['class'], 'wpc-lcp-optimized') !== false;

        if (!$isLcpOptimized && !self::$excludes_class->isAdaptiveExcluded($image_source, $original_img_tag['original_tags']['class'])) {
            $original_img_tag['original_tags']['srcset'] = $this->rewriteSrcset($original_img_tag, $original_img_tag['original_tags']['srcset']);

            $original_img_tag['original_tags']['data-srcset'] = $this->cdnSrcsetOnly($original_img_tag['original_tags']['data-srcset']);
        } elseif ($isLcpOptimized) {
            // Also process data-srcset if any, but preserve the main srcset
            $original_img_tag['original_tags']['data-srcset'] = $this->cdnSrcsetOnly($original_img_tag['original_tags']['data-srcset']);
            if (function_exists('wpc_diagnostic_log')) {
                wpc_diagnostic_log('LCP_SRCSET_PRESERVED', 'bypassed rewriteSrcset mobile-bail for ' . basename(parse_url($image_source, PHP_URL_PATH) ?: $image_source));
            }
        } else {
            // TODO: For some reason this was commented out (class)
            $original_img_tag['original_tags']['class'] .= ' wpc-excluded-adaptive';
            $original_img_tag['additional_tags']['wpc-data'] = 'excluded-adaptive';
            $original_img_tag['additional_tags']['data-excluded-adaptive'] = 'true';


            $original_img_tag['src'] = $image_source;


            if (($pictureWebpEnabled || self::$pictureAvifEnabled)
                && self::$zoneName !== ''
                && (bool) apply_filters('wpc_excluded_adaptive_nextgen',
                        (bool) (function_exists('get_option') ? get_option('wpc_excluded_adaptive_nextgen', 1) : 1))) {
                $ea_clean = preg_replace('/\?.*$/', '', (string) $image_source);
                $ea_path  = (string) wp_parse_url($ea_clean, PHP_URL_PATH);
                $ea_ohost = (string) wp_parse_url($ea_clean, PHP_URL_HOST);


                $ea_bases = function_exists('wpc_v2_upload_base_paths') ? (array) wpc_v2_upload_base_paths() : ['/wp-content/uploads'];
                $ea_in_base = false;
                foreach ($ea_bases as $ea_bp) {
                    $ea_bp = '/' . trim((string) $ea_bp, '/');
                    if ($ea_path === $ea_bp || strpos($ea_path, $ea_bp . '/') === 0) { $ea_in_base = true; break; }
                }
                $ea_siteHost = (string) wp_parse_url(site_url(), PHP_URL_HOST);
                $ea_host_ok  = ($ea_ohost !== '')
                    && (strcasecmp($ea_ohost, (string) self::$zoneName) === 0
                        || ($ea_siteHost !== '' && strcasecmp($ea_ohost, $ea_siteHost) === 0));
                if ($ea_in_base && $ea_host_ok) {
                    // Host-swap origin/site host → zone (no-op if already zone), SAME extension, NO width.
                    $original_img_tag['src'] = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $ea_clean);
                }
            }
        }

        $build_image_tag = '<img ';

        // Patch, remove things
        unset($original_img_tag['original_tags']['fetchpriority'], $original_img_tag['original_tags']['decoding']);
        // Unset bricks attribute
        unset($original_img_tag['original_tags']['data-bricks-logo']);


        //Is native lazy enabled?
        if (self::$lazyLoadedImages > self::$lazyLoadSkipFirstImages) {
            if (!empty(self::$nativeLazyEnabled) && self::$nativeLazyEnabled == '1') {
                if (!$skipLazy && !$isLogo) {
                    if (!self::$lazyOverride && !self::isExcludedFrom('lazy', $image_source)) {
                        if (strpos($lowerClass, 'rs') === false && strpos($lowerClass, 'slide') === false && strpos($lowerClass, 'lgx_app') === false && strpos($lowerClass, 'dynamic-image') === false && strpos($lowerClass, 'breakdance') === false) {
                            $build_image_tag .= 'loading="lazy" data-count="' . self::$lazyLoadedImages . '" ';
                        }
                    }
                }
            }
        }

        // Inject loading="lazy" on LCP-optimized eager IMGs (the lazy block above skips them by design):


        if (!empty(self::$settings['optimize-lcp'])
            && strpos((string) $original_img_tag['original_tags']['class'], 'wpc-lcp-optimized') !== false
            && apply_filters('wpc_lcp_lazy', false, $image_source)) {

            if (strpos($build_image_tag, 'loading=') === false) {
                $build_image_tag .= 'loading="lazy" ';
            }
        }

        if (!empty($original_img_tag['original_src'])) {
            $original_img_tag['original_src'] = $this->specialChars($original_img_tag['original_src']);
        }

        if (!empty($original_img_tag['src'])) {
            $original_img_tag['src'] = $this->specialChars($original_img_tag['src']);
        }

        if (!empty($original_img_tag['original_tags']['data-src'])) {
            $original_img_tag['original_tags']['data-src'] = $this->specialChars($original_img_tag['original_tags']['data-src']);
        }

        if (!empty($original_img_tag['data-src'])) {
            $original_img_tag['data-src'] = $this->specialChars($original_img_tag['data-src']);
        }

        if (self::isExcluded($original_img_tag['original_src'], $original_img_tag['original_src'])) {
            // Image is excluded
            if (!empty($original_img_tag['original_src'])) {
                $original_img_tag['src'] = $original_img_tag['original_src'];
            } elseif (!empty($original_img_tag['data-src'])) {
                $original_img_tag['src'] = $original_img_tag['data-src'];
            }
        }

        /**
         * Is this image lazy excluded?
         */

        if (!empty($lazyEnabled) && $lazyEnabled == '1') {
            if (self::$excludes_class->isLazyExcluded($image_source, $original_img_tag['original_tags']['class'])) {
                //Don't add anything if lazy load is off
                $original_img_tag['src'] = $image_source;
            }
        }

        if ($isLogo || !empty(self::$removeSrcset) && self::$removeSrcset == '1') {
            // `sizes` goes with the srcset it describes. Left behind, it described a ladder that no
            // longer exists: greenvalleytint.com's 155 px header logo went out with
            // `sizes="(max-width: 985px) 100vw, 985px"` and no srcset.
            unset($original_img_tag['original_tags']['srcset'], $original_img_tag['original_tags']['data-srcset'],
                $original_img_tag['original_tags']['sizes'], $original_img_tag['original_tags']['data-sizes']);
        } elseif (!empty($lazyEnabled) && $lazyEnabled == '1' && !$skipLazy) {
            if (!empty($original_img_tag['original_tags']['srcset']) && strpos($original_img_tag['original_tags']['srcset'], 'lazy') === false && strpos($original_img_tag['original_tags']['srcset'], 'placeholder') === false) {
                $build_image_tag .= 'data-srcset="' . $original_img_tag['original_tags']['srcset'] . '" ';
            } else if (!empty($original_img_tag['original_tags']['data-srcset'])) {
                $build_image_tag .= 'data-srcset="' . $original_img_tag['original_tags']['data-srcset'] . '" ';
            }
            unset($original_img_tag['original_tags']['srcset'], $original_img_tag['original_tags']['data-srcset']);
        }

        if (!empty($_GET['remove_srcset'])) {
            unset($original_img_tag['original_tags']['srcset'], $original_img_tag['original_tags']['data-srcset']);
        }

        if (!empty($_GET['test_adaptive'])) {
            if (!empty($adaptiveEnabled) && $adaptiveEnabled == '1') {
                $build_image_tag .= 'data-src="' . $original_img_tag['data-src'] . '" ';
                $original_img_tag['original_tags']['data-src'] = $source_svg;
            }
        }

        // Add srcset - Remove SrcSet is Disabled!
        if (empty(self::$removeSrcset)) {
            $srcSetTag = 'srcset';

            if ((!empty($adaptiveEnabled) && $adaptiveEnabled == '1') || (!empty($lazyEnabled) && $lazyEnabled == '1')) {
                if (!$skipLazy) {
                    $srcSetTag = 'data-srcset';
                }
            }

            if (!empty($original_img_tag['original_tags']['srcset']) && strpos($original_img_tag['original_tags']['srcset'], 'lazy') === false && strpos($original_img_tag['original_tags']['srcset'], 'placeholder') === false) {
                $build_image_tag .= $srcSetTag . '="' . $original_img_tag['original_tags']['srcset'] . '" ';
            } else if (!empty($original_img_tag['original_tags']['data-srcset'])) {
                $build_image_tag .= $srcSetTag . '="' . $original_img_tag['original_tags']['data-srcset'] . '" ';
            }
        }


        if (empty($original_img_tag['data-src'])) {
            $original_img_tag['data-src'] = '';
        }

        /**
         * If image contains logo in filename, then it's a logo probably
         */
        if (strpos(strtolower($original_img_tag['original_tags']['class']), 'rs-lazyload') !== false || strpos(strtolower($original_img_tag['original_tags']['class']), 'rs') !== false || strpos(strtolower($image_source), 'logo') !== false || strpos(strtolower($original_img_tag['class']), 'logo') !== false) {
            $logoSrc = $original_img_tag['original_tags']['src'];

            // Check if it's a protocol-relative URL and convert it to https://
            if (strpos($logoSrc, '//') === 0 && strpos($logoSrc, 'https://') !== 0 && strpos($logoSrc, 'http://') !== 0) {
                $logoSrc = 'https:' . $logoSrc;
            }

            $build_image_tag .= 'src="' . $logoSrc . '" ';
        } else {

            if (!empty($lazyEnabled) && $lazyEnabled == '1') {
                // v7.21.227 — TRUE viewport park. With src left REAL, the browser fetches
                // every image natively (lab Chrome's threshold covers nearly the whole page)
                // and Viewport mode never got to park anything: 42 image rows on a page
                // showing ~12. Park BTF images behind the aspect-correct placeholder; the
                // eager pixel restores img[data-src] in-viewport (plus the .200 injected
                // watcher). Skip-first/LCP/logo/slider/excluded images keep the real src.
                // v7.21.262 — the 'rs' guard was a SUBSTRING: 'colors', 'doctors', 'sponsors'
                // all matched and their images silently kept live src (columbuschiropractors:
                // the whole Section-3 band on the no-gesture wire). Fragile engines match by
                // TOKEN now; generic slider/swiper images park by default (placeholder holds
                // the box, pixel/belt restore on view or first gesture) behind
                // wpc_park_slider_imgs for rollback.
                $parkBlockedByEngine = (bool) preg_match('/(?:^|[^a-z0-9])rs[-_]|rs-lazyload|revslider|rev_slider|lgx_app|dynamic-image|breakdance/', $lowerClass);
                $parkBlockedBySlider = (strpos($lowerClass, 'slide') !== false || strpos($lowerClass, 'swiper') !== false)
                    && !apply_filters('wpc_park_slider_imgs', true);
                $parkUntilViewport = (!$skipLazy && !$isLogo
                    && self::$lazyLoadedImages > self::$lazyLoadSkipFirstImages
                    && !self::$lazyOverride && !self::isExcludedFrom('lazy', $image_source)
                    && !$parkBlockedByEngine && !$parkBlockedBySlider
                    && strpos((string) $original_img_tag['original_tags']['class'], 'wpc-lcp-optimized') === false
                    && !empty($source_svg) && strpos((string) $source_svg, 'data:image/svg+xml') === 0
                    && empty($original_img_tag['data-src'])
                    && apply_filters('wpc_viewport_true_park', true, $image_source));
                if ($parkUntilViewport) {
                    $build_image_tag .= 'src="' . $source_svg . '" data-src="' . $original_img_tag['src'] . '" ';
                } else {
                    $build_image_tag .= 'src="' . $original_img_tag['src'] . '" ';

                    if (!empty($original_img_tag['data-src'])) {
                        $build_image_tag .= 'data-src="' . $original_img_tag['data-src'] . '" ';
                    }
                }

            } elseif (!empty($adaptiveEnabled) && $adaptiveEnabled == '1') {
                $build_image_tag .= 'src="' . $original_img_tag['src'] . '" ';

                if (!empty($original_img_tag['data-src'])) {
                    $build_image_tag .= 'data-src="' . $original_img_tag['data-src'] . '" ';
                }

            } else {
                if (!empty($original_img_tag['original_tags']['data-src'])) {
                    $build_image_tag .= 'src="' . $original_img_tag['original_tags']['data-src'] . '" ';
                } else {
                    if (!empty($original_img_tag['data-src'])) {
                        $build_image_tag .= 'src="' . $original_img_tag['data-src'] . '" ';
                    } else {
                        $build_image_tag .= 'src="' . $original_img_tag['src'] . '" ';
                    }
                }
            }
        }

        if (!empty($original_img_tag['original_tags'])) {
            foreach ($original_img_tag['original_tags'] as $tag => $value) {
                if (!empty($value)) {
                    if ($tag == 'class' || $tag == 'src' || $tag == 'srcset' || $tag == 'data-src' || $tag == 'data-mk-image-src-set' || $tag == 'data-prehidden' || $tag == 'alt') {

                        continue;
                    } elseif (!empty($value)) {
                        $build_image_tag .= $tag . '="' . esc_attr($value) . '" ';
                    } else {
                        $build_image_tag .= $tag . ' ';
                    }
                }
            }
        }

        if (strpos($lowerClass, 'slide') !== false || strpos($lowerClass, 'lgx_app') !== false || strpos($lowerClass, 'dynamic-image') !== false || strpos($lowerClass, 'rs') !== false) {
            unset($original_img_tag['additional_tags']['data-wpc-loaded']);
        }


        foreach ($original_img_tag['additional_tags'] as $tag => $value) {
            if ($tag == 'class') {
                $tag = 'class';

                if (strpos($lowerClass, 'rs-lazyload') !== false || strpos($lowerClass, 'rs') !== false || (strpos($lowerClass, 'lazy') !== false && strpos($lowerClass, 'skip-lazy') === false)) {

                    $value = $original_img_tag['original_tags']['class'];
                } else {
                    $value .= ' ' . $original_img_tag['original_tags']['class'];
                }
            }

            if ($tag == 'src' || $tag == 'data-src' || $tag == 'data-mk-image-src-set' || empty($value) || $tag == 'data-prehidden') {
                continue;
            }


            $value = trim($value);
            if (!empty($value)) {
                $build_image_tag .= $tag . '="' . esc_attr($value) . '" ';
            }
        }

        if (empty($original_img_tag['original_tags']['alt'])) {
            $original_img_tag['original_tags']['alt'] = '';
        }

        $build_image_tag .= 'alt="' . esc_attr($original_img_tag['original_tags']['alt']) . '" ';

        // A loader-managed image must never render srcless: a src-less <img> collapses to
        // 0x0 regardless of its dimension attributes, then materializes at full size when
        // the loader injects the src — re-laying its whole region. A same-dimensions SVG
        // placeholder holds the exact box until the swap.
        if ((strpos($build_image_tag, ' src=') === false || strpos($build_image_tag, 'src=""') !== false)
            && preg_match('/\bwidth="(\d+)"/', $build_image_tag, $widthAttrMatch)
            && preg_match('/\bheight="(\d+)"/', $build_image_tag, $heightAttrMatch)
            && apply_filters('wpc_img_placeholder_reserve', true)) {
            $placeholderSvgUri = 'data:image/svg+xml,%3Csvg%20xmlns=%27http://www.w3.org/2000/svg%27%20width=%27'
                . (int) $widthAttrMatch[1] . '%27%20height=%27' . (int) $heightAttrMatch[1] . '%27/%3E';
            if (strpos($build_image_tag, 'src=""') !== false) {
                $build_image_tag = str_replace('src=""', 'src="' . $placeholderSvgUri . '"', $build_image_tag);
            } else {
                $build_image_tag .= 'src="' . $placeholderSvgUri . '" ';
            }
        }

        $build_image_tag .= '/>';


        $build_image_tag = self::maybe_naturalize_single_src($build_image_tag);
        $build_image_tag = self::naturalize_svg_src($build_image_tag);
        $build_image_tag = self::activate_lazy_srcset_auto($build_image_tag);
        $build_image_tag = self::naturalize_srcset_widths($build_image_tag);


        $build_image_tag = self::auto_sizes_for_lazy_img($build_image_tag);


        static $wpc_nat_upbases = null;
        if ($wpc_nat_upbases === null) {
            $wpc_nat_upbases = function_exists('wpc_v2_upload_base_paths')
                ? array_map(function ($p) { return '/' . trim((string) $p, '/'); }, (array) wpc_v2_upload_base_paths())
                : ['/wp-content/uploads'];
        }
        $wpc_nat_in_base = false;
        foreach ($wpc_nat_upbases as $wpc_nb) {
            if (strpos($build_image_tag, $wpc_nb . '/') !== false) { $wpc_nat_in_base = true; break; }
        }


        $wpc_img_is_lazy = (strpos($build_image_tag, 'data-src=') !== false)
            || (strpos($build_image_tag, 'data-wpc-loaded="true"') !== false)
            || (strpos($build_image_tag, "data-wpc-loaded='true'") !== false);


        $wpc_img_otf_source = ((bool) preg_match('/\.(jpe?g|png)(\?|#|$)/i', (string) $image_source)
                || ((self::wpc_webp_otf_ready() || self::wpc_natural_nw()) && (bool) preg_match('/\.webp(\?|#|$)/i', (string) $image_source)))
            && (bool) apply_filters('wpc_lazy_raster_picture', true);
        $wpc_lazy_blocks_wrap = $wpc_img_is_lazy && !$wpc_img_otf_source;
        $wpc_natural_img_src = (strpos($build_image_tag, '/wp:') === false)
            && (self::$zoneName !== '' && strpos($build_image_tag, 'https://' . self::$zoneName . '/') !== false)
            && $wpc_nat_in_base
            && !$wpc_lazy_blocks_wrap;
        if ($pictureWebpEnabled && !$wpc_lazy_blocks_wrap && (strpos($build_image_tag, '/wp:1/') !== false || $wpc_natural_img_src)) {
            $lowerSrc = strtolower($image_source);
            $skipFormats = (strpos($lowerSrc, '.svg') !== false
                         || strpos($lowerSrc, '.gif') !== false
                         || strpos($lowerSrc, '.ico') !== false);
            // An AVIF original gets no next-gen <source>: its webp/avif rungs would be natural zone
            // URLs derived from a source the CDN cannot take, each a 302 to a guessed .jpg that
            // 404s (noktaltema.com, 2026-09-24). The <img> keeps its transform URL, which the CDN
            // passes to the origin file. See natural_lane_takes_source().
            if (!$skipFormats && preg_match('/\.avif(?:[?#&]|$)/i', (string) preg_replace('#^.*/(?:u|a):#', '', $lowerSrc))
                && !self::natural_lane_takes_source('avif')) {
                $skipFormats = true;
            }


            $wpc_src_for_host = (string) $image_source;
            if (stripos($wpc_src_for_host, '/u:') !== false
                && preg_match('~/u:(https?://[^"\'\s)]+)~i', $wpc_src_for_host, $wpc_um)) {
                $wpc_src_for_host = $wpc_um[1];
            }
            $wpc_src_host = (string) wp_parse_url(preg_replace('/[?#].*$/', '', $wpc_src_for_host), PHP_URL_HOST);
            $wpc_own_host = (string) wp_parse_url(site_url(), PHP_URL_HOST);
            $wpc_src_is_own = ($wpc_src_host === '')
                || (self::$zoneName !== '' && strcasecmp($wpc_src_host, (string) self::$zoneName) === 0)
                || ($wpc_own_host !== '' && strcasecmp(preg_replace('/^www\./i', '', $wpc_src_host), preg_replace('/^www\./i', '', $wpc_own_host)) === 0);

            if (!$skipFormats) {
                // Create non-WebP fallback — safe regex: only replaces /wp:1/ inside URLs (after ://)
                $fallbackTag = preg_replace('#(://[^"\'>\s]*/wp):1/#', '$1:0/', $build_image_tag);

                // Extract srcset for <source> (WebP version with /wp:1/)
                $sourceSrcset = '';
                if (preg_match('/(data-)?srcset="([^"]*)"/', $build_image_tag, $srcsetMatch)) {
                    $srcsetAttr = $srcsetMatch[1] ? 'data-srcset' : 'srcset';
                    $sourceSrcset = ' ' . $srcsetAttr . '="' . $srcsetMatch[2] . '"';
                }

                // Fallback: use src for images without srcset
                if (empty($sourceSrcset)) {
                    $srcAttrName = (strpos($build_image_tag, 'data-src="') !== false) ? 'data-srcset' : 'srcset';
                    if (preg_match('/(data-)?src="([^"]*)"/', $build_image_tag, $srcMatch)) {


                        $singleWebpSrc = $srcMatch[2];
                        if (self::picture_webp_natural_full_ok()
                            && !empty($image_source)
                            && strpos($singleWebpSrc, '/wp:') !== false) {
                            $cleanWebpSingle = preg_replace('/[?#].*$/', '', $image_source);
                            $natWebpSingle   = preg_replace('/\.(jpe?g|png|avif)$/i', '.webp', $cleanWebpSingle);
                            $webpSiteHostS   = rtrim(trailingslashit(site_url()), '/');
                            if (strpos($natWebpSingle, $webpSiteHostS) === 0) {
                                // v7.20.15 — the collapsed single-src natural carries the hint too
                                $singleSourceExt = strtolower((string) pathinfo($cleanWebpSingle, PATHINFO_EXTENSION));
                                $singleWebpSrc = 'https://' . self::$zoneName . str_replace($webpSiteHostS, '', $natWebpSingle)
                                    . ($singleSourceExt !== '' && $singleSourceExt !== 'webp' ? self::src_hint_qs($singleSourceExt) : '');
                            }
                        }
                        $sourceSrcset = ' ' . $srcAttrName . '="' . $singleWebpSrc . '"';
                    }
                }


                // BEFORE the extraction below, so the <source>s (whose OWN sizes governs picture


                $sizesFromCensus = false;
                if (!empty($image_source)) {
                    $censusSlotSizes = self::wpc_census_slot_sizes($image_source, $build_image_tag);
                    if ($censusSlotSizes !== '') {
                        $sizesFromCensus = true; // census-injected = INVENTED sizes, never auto-prefixed
                        $censusSizesAttr = 'sizes="' . $censusSlotSizes . '"';
                        $sizedTags = ['build_image_tag' => $build_image_tag, 'fallbackTag' => $fallbackTag];
                        foreach ($sizedTags as $tagName => $tagHtml) {
                            $resizedTag = preg_match('/\bsizes\s*=\s*"[^"]*"/i', $tagHtml)
                                ? preg_replace('/\bsizes\s*=\s*"[^"]*"/i', $censusSizesAttr, $tagHtml, 1)
                                : preg_replace('/<img\b/i', '<img ' . $censusSizesAttr . ' ', $tagHtml, 1);
                            if (is_string($resizedTag)) { $sizedTags[$tagName] = $resizedTag; }
                        }
                        $build_image_tag = $sizedTags['build_image_tag'];
                        $fallbackTag     = $sizedTags['fallbackTag'];
                    }
                }


                $sourceSizes = '';
                if (preg_match('/sizes="([^"]*)"/', $build_image_tag, $sizesMatch)) {
                    $sizes_value = $sizesMatch[1];


                    $is_eager_lcp = (stripos($build_image_tag, 'wpc-lcp-optimized') !== false)
                        && (stripos($build_image_tag, 'loading="lazy"') === false);
                    $auto_enabled = (bool) apply_filters('wpc_v2_sizes_auto_prefix', true);
                    if ($auto_enabled && !$is_eager_lcp && !$sizesFromCensus && stripos($sizes_value, 'auto') === false) {
                        $sizes_value = 'auto, ' . $sizes_value;
                    }
                    $sourceSizes = ' sizes="' . $sizes_value . '"';
                }


                $avifSource = '';
                if (self::$pictureAvifEnabled && !empty($image_source)) {
                    $cleanSource = preg_replace('/[?#].*$/', '', $image_source);
                    $avifUrl = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', $cleanSource);
                    $avifSiteUrl = trailingslashit(site_url());
                    $avifPath = str_replace($avifSiteUrl, trailingslashit(ABSPATH), $avifUrl);


                    $avif_src_transcodable = true;
                    if ((bool) apply_filters('wpc_avif_webp_native_floor', true)) {
                        $avif_tc_att = 0;
                        if (!empty($original_img_tag['original_tags']['class'])
                            && preg_match('/\bwp-image-(\d+)\b/', $original_img_tag['original_tags']['class'], $im_tc)) {
                            $avif_tc_att = (int) $im_tc[1];
                        }
                        $avif_tc_mime = ($avif_tc_att > 0 && function_exists('get_post_mime_type'))
                            ? (string) get_post_mime_type($avif_tc_att)
                            : '';


                        $avif_from_webp_ok = self::wpc_webp_otf_ready() || self::wpc_natural_nw()
                            || apply_filters('wpc_avif_from_webp', get_option('wpc_avif_from_webp') === '1');
                        if ($avif_tc_mime !== '') {


                            $avif_src_transcodable = in_array($avif_tc_mime, ['image/jpeg', 'image/jpg', 'image/png'], true)
                                || ($avif_from_webp_ok && $avif_tc_mime === 'image/webp');
                        } else {
                            // No resolvable attachment mime → trust the source extension (jpg/png only).
                            $avif_src_transcodable = (bool) preg_match('/\.(jpe?g|png)$/i', $cleanSource)
                                || ($avif_from_webp_ok && (bool) preg_match('/\.webp$/i', $cleanSource));
                        }
                    }


                    $src_hint_ext = '';
                    if (self::src_hint_enabled()) {
                        // Literal on-disk extension FIRST: jpg and jpeg are DISTINCT files at
                        // origin, and a normalized hint narrows the pod's probe to a family
                        // that may not exist (degrade-302 to a 404)
                        $sh_src = !empty($image_source) ? (string) $image_source
                            : (!empty($original_img_tag['src']) ? (string) $original_img_tag['src'] : '');
                        if ($sh_src !== '') {
                            $sh_ux = strtolower((string) pathinfo((string) parse_url($sh_src, PHP_URL_PATH), PATHINFO_EXTENSION));
                            if (in_array($sh_ux, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) $src_hint_ext = $sh_ux;
                        }
                        // Extensionless path (page-builder /storage + offloaded media) →
                        // attachment mime fallback; jpeg-family ambiguity resolves to jpg
                        if ($src_hint_ext === '') {
                            $sh_att = 0;
                            if (!empty($original_img_tag['original_tags']['class'])
                                && preg_match('/\bwp-image-(\d+)\b/', (string) $original_img_tag['original_tags']['class'], $sh_m)) {
                                $sh_att = (int) $sh_m[1];
                            }
                            $sh_mime = ($sh_att > 0 && function_exists('get_post_mime_type')) ? (string) get_post_mime_type($sh_att) : '';
                            if ($sh_mime === 'image/png') $src_hint_ext = 'png';
                            elseif ($sh_mime === 'image/jpeg' || $sh_mime === 'image/jpg') $src_hint_ext = 'jpg';
                        }
                    }

                    $optimistic_avif = $wpc_src_is_own
                                       && function_exists('wpc_v2_get_lazy_enabled')
                                       && wpc_v2_get_lazy_enabled();


                    $avif_otf_live = $wpc_src_is_own && self::picture_avif_natural_ok();


                    if (!$avif_src_transcodable) {
                        $optimistic_avif = false;
                        $avif_otf_live   = false;
                    }
                    $avif_emit_natural = $wpc_src_is_own && self::picture_avif_emit_natural() && $avif_src_transcodable;


                    $avif_ceiling_on = (self::$pictureAvifEnabled === true) && (self::$zoneName !== '')
                        && !(defined('WPC_NEGOTIATED_KILL') && WPC_NEGOTIATED_KILL);


                    if (file_exists($avifPath) || (($optimistic_avif || $avif_otf_live || $avif_ceiling_on) && $avif_src_transcodable)) {


                        $avifZoneBase = 'https://' . self::$zoneName;
                        $avifSiteHost = rtrim($avifSiteUrl, '/');

                        // When wpc_v2_lazy_cdn_use_original is ON, emit the un-scaled original as the CDN `u:`


                        $avif_attachment_id = 0;
                        if (!empty($original_img_tag['original_tags']['class'])
                            && preg_match('/\bwp-image-(\d+)\b/', $original_img_tag['original_tags']['class'], $im_avif)) {
                            $avif_attachment_id = (int) $im_avif[1];
                        }
                        $avif_original_u_url = '';
                        if ($avif_attachment_id > 0
                            && function_exists('wpc_v2_lazy_cdn_use_original')
                            && wpc_v2_lazy_cdn_use_original($avif_attachment_id)
                            && function_exists('wp_get_original_image_url')
                            && function_exists('wp_get_original_image_path')) {
                            $orig_u = wp_get_original_image_url($avif_attachment_id);
                            $orig_p = wp_get_original_image_path($avif_attachment_id);
                            if ($orig_u && $orig_p && @file_exists($orig_p)) {
                                $avif_original_u_url = $orig_u;
                            }
                        }


                        $avif_meta_nw  = ($avif_attachment_id > 0 && function_exists('wp_get_attachment_metadata'))
                            ? wp_get_attachment_metadata($avif_attachment_id)
                            : false;
                        $avif_native_w = (is_array($avif_meta_nw) && !empty($avif_meta_nw['width']))
                            ? (int) $avif_meta_nw['width']
                            : 0;
                        $avif_native_h = (is_array($avif_meta_nw) && !empty($avif_meta_nw['height']))
                            ? (int) $avif_meta_nw['height']
                            : 0;


                        $avif_aspect_meta = (is_array($avif_meta_nw) && !empty($avif_meta_nw['width']) && !empty($avif_meta_nw['height']))
                            ? $avif_meta_nw : false;
                        if (!is_array($avif_aspect_meta)) {
                            $asp_w = $avif_native_w; $asp_h = $avif_native_h;
                            // (a) the <img> intrinsic width/height attributes (survive for excluded-adaptive
                            // images that have no srcset/meta — e.g. b1-withsrcset 1887x2560).
                            if ($asp_w <= 0 || $asp_h <= 0) {
                                foreach (array('original_tags', 'additional_tags') as $asp_bag) {
                                    if (!empty($original_img_tag[$asp_bag]['width']) && !empty($original_img_tag[$asp_bag]['height'])) {
                                        $asp_w = (int) $original_img_tag[$asp_bag]['width'];
                                        $asp_h = (int) $original_img_tag[$asp_bag]['height'];
                                        break;
                                    }
                                }
                            }
                            // (b) else the first -WxH entry in the WP srcset (ratio is all the suffix needs).
                            if ($asp_w <= 0 || $asp_h <= 0) {
                                foreach (explode(',', (string) $original_img_tag['original_srcset']) as $asp_sp) {
                                    if (preg_match('#-(\d+)x(\d+)\.(?:jpe?g|png|webp|avif)#i', trim($asp_sp), $asp_m)) {
                                        $asp_w = (int) $asp_m[1]; $asp_h = (int) $asp_m[2]; break;
                                    }
                                }
                            }
                            if ($asp_w > 0 && $asp_h > 0) $avif_aspect_meta = ['width' => $asp_w, 'height' => $asp_h];
                            // v7.20.12 — ATTRS ARE A BOX, NOT AN ASPECT. The width/height
                            // attributes describe the layout slot; themes/widgets hardcode
                            // them (wp-social-reviews: 75x25 on EVERY platform icon). A rung
                            // ladder derived from a lying box mints distorted/cropped bitmaps
                            // (maisonpro: airbnb logo "cut off"). When the source file is
                            // local, its measured dimensions outrank any declaration.
                            if (is_array($avif_aspect_meta)) {
                                $measured_file_dims = self::wpc_true_image_dimensions($image_source);
                                if (is_array($measured_file_dims)
                                    && (int) $measured_file_dims['width'] * (int) $avif_aspect_meta['height']
                                       !== (int) $measured_file_dims['height'] * (int) $avif_aspect_meta['width']) {
                                    $avif_aspect_meta = $measured_file_dims;
                                }
                            }
                        }


                        $avif_src_w_cap = ($avif_native_w > 0) ? $avif_native_w
                            : ((is_array($avif_aspect_meta) && !empty($avif_aspect_meta['width'])) ? (int) $avif_aspect_meta['width'] : 0);


                        $avif_land_class = (string) (isset($original_img_tag['original_tags']['class'])
                            ? $original_img_tag['original_tags']['class'] : '');
                        $avif_can_queue = ($avif_attachment_id > 0)
                            && function_exists('wpc_v2_sized_trigger_queue')
                            && !preg_match('/\b(alignfull|alignwide|wp-block-cover|elementor|brz-|brxe-|et_pb)\b/i', $avif_land_class)
                            && function_exists('wpc_get_theme_content_width') && (int) wpc_get_theme_content_width() > 0
                            && apply_filters('wpc_picture_land_widths', true);
                        $avif_queue_w = function ($w) use ($avif_attachment_id, $avif_can_queue, $avif_native_w) {
                            $w = (int) $w;
                            if (!$avif_can_queue || $w < 200) return;
                            if ($avif_native_w > 0 && $w >= $avif_native_w) return;
                            wpc_v2_sized_trigger_queue($avif_attachment_id, $w, $w);
                        };


                        // -WxH on the fly (no 404/strand), so natural is safe on EVERY zone. When false, each
                        // rung keeps its conservative fallback (wp:2 / drop). ($avif_otf_live hoisted above the gate.)

                        if (self::wpc_natural_nw()) {


                            $avif_nw_entries = [];
                            $avif_nw_emitted_widths = [];
                            foreach (self::wpc_nw_widths($original_img_tag, $avif_src_w_cap) as $nw_w) {
                                $avif_nw_entries[] = self::wpc_nw_url($cleanSource, $nw_w, 'avif', $avif_aspect_meta) . self::src_hint_qs($src_hint_ext) . ' ' . $nw_w . 'w';
                                $avif_queue_w($nw_w);
                                $avif_nw_emitted_widths[(int) $nw_w] = 1;
                            }

                            // -WxH/wp:2 block below, so every census fix landed there (.125 rescue, .127


                            foreach (self::wpc_census_rung_targets($cleanSource) as $avif_census_w) {
                                $avif_census_w = (int) $avif_census_w;
                                if ($avif_census_w < 48 || isset($avif_nw_emitted_widths[$avif_census_w])) { continue; }
                                if ($avif_src_w_cap > 0 && $avif_census_w > $avif_src_w_cap) { continue; }
                                $avif_nw_entries[] = self::wpc_nw_url($cleanSource, $avif_census_w, 'avif', $avif_aspect_meta) . self::src_hint_qs($src_hint_ext) . ' ' . $avif_census_w . 'w';
                                $avif_queue_w($avif_census_w);
                                $avif_nw_emitted_widths[$avif_census_w] = 1;
                            }


                            // v7.21.122 — default rungs REQUIRE a known source width. wpc_nw_widths
                            // deliberately returns [] when nothing is known (no srcset, no width attr,
                            // no cap); this fallback then overrode that refusal, and with cap=0 its
                            // >cap filter was bypassed: a 250px Elementor thumb (hash filename, no
                            // dims anywhere) got a fabricated 360-1080w ladder — every rung an
                            // upscale, every descriptor a lie, and on a width/height-less img the
                            // picked rung's intrinsic size BECAME the layout box (beucomply footer
                            // logo 250x106 -> 333x141). Unknown width now falls through to the
                            // natural-full single entry: no descriptor, no lie, box identical to
                            // plugin-off.
                            if ($avif_src_w_cap > 0 && !self::wpc_census_rung_targets($cleanSource)) {
                                foreach ((array) apply_filters('wpc_nw_default_rungs', [360, 480, 640, 750, 828, 1080]) as $avif_default_rung_w) {
                                    $avif_default_rung_w = (int) $avif_default_rung_w;
                                    if ($avif_default_rung_w < 48 || isset($avif_nw_emitted_widths[$avif_default_rung_w])) { continue; }
                                    if ($avif_src_w_cap > 0 && $avif_default_rung_w > $avif_src_w_cap) { continue; }
                                    $avif_nw_entries[] = self::wpc_nw_url($cleanSource, $avif_default_rung_w, 'avif', $avif_aspect_meta) . self::src_hint_qs($src_hint_ext) . ' ' . $avif_default_rung_w . 'w';
                                    $avif_queue_w($avif_default_rung_w);
                                    $avif_nw_emitted_widths[$avif_default_rung_w] = 1;
                                }
                            }
                            if (empty($avif_nw_entries) && self::wpc_is_attachment_recorded($cleanSource)) {

                                $avif_full_nw = self::wpc_natural_full_url($cleanSource, 'avif');
                                if ($avif_full_nw !== '') $avif_nw_entries[] = $avif_full_nw . self::src_hint_qs($src_hint_ext);
                            }
                            if (!empty($avif_nw_entries)) {
                                $avifSource = '<source ' . self::picture_source_srcset_attr($build_image_tag) . '="' . implode(', ', $avif_nw_entries) . '"' . $sourceSizes . ' type="image/avif">';
                            }
                        } elseif (!empty($original_img_tag['original_srcset'])) {
                            $avifEntries = [];
                            $srcsetParts = explode(',', $original_img_tag['original_srcset']);

                            foreach ($srcsetParts as $part) {
                                $part = trim($part);
                                if (preg_match('/^(\S+)\s+(.+)$/', $part, $m)) {
                                    $srcUrl = preg_replace('/[?#].*$/', '', $m[1]);
                                    $descriptor = $m[2];
                                    $avifSrcUrl = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', $srcUrl);
                                    $avifSizePath = str_replace($avifSiteUrl, trailingslashit(ABSPATH), $avifSrcUrl);


                                    if (@file_exists($avifSizePath)
                                        && !self::picture_variant_dims_ok($avifSizePath, $avif_native_w, $avif_native_h)) {
                                        continue;
                                    }

                                    if (@file_exists($avifSizePath)) {


                                        $is_width_desc = (bool) preg_match('/^(\d+)w$/', trim((string) $descriptor), $avif_dm);
                                        $desc_w_of     = $is_width_desc ? (int) $avif_dm[1] : 0;


                                        $avif_entry_basename   = basename($srcUrl);
                                        $is_registered_subsize = false;
                                        if (is_array($avif_meta_nw) && !empty($avif_meta_nw['sizes'])) {
                                            foreach ($avif_meta_nw['sizes'] as $avif_sz) {
                                                if (!empty($avif_sz['file']) && basename((string) $avif_sz['file']) === $avif_entry_basename) {
                                                    $is_registered_subsize = true;
                                                    break;
                                                }
                                            }
                                        }
                                        if ($avif_native_w > 0 && $is_width_desc && !$is_registered_subsize
                                            && $desc_w_of > 0 && $desc_w_of < $avif_native_w) {
                                            $avif_sized_suffix = function_exists('wpc_v2_adaptive_variant_suffix')
                                                ? wpc_v2_adaptive_variant_suffix($desc_w_of, $avif_aspect_meta)
                                                : '';


                                            $avif_sized_wxh = (bool) preg_match('/-\d+x\d+$/', $avif_sized_suffix);
                                            if (($avif_emit_natural || $avif_otf_live) && $avif_sized_wxh) {


                                                $avif_base_no_ext = preg_replace('/\.avif$/i', '', $avifSrcUrl);
                                                $avif_sized_url   = $avif_base_no_ext . $avif_sized_suffix . '.avif';


                                                list($avif_sized_url, ) = self::recoverAdaptiveVariant($avif_sized_url, $avif_base_no_ext, $desc_w_of, 'avif');
                                                $avifEntries[]    = $avifZoneBase . str_replace($avifSiteHost, '', $avif_sized_url) . ' ' . $descriptor;
                                                $avif_queue_w($desc_w_of);
                                            } else {


                                                continue;
                                            }
                                        } else {


                                            if (@file_exists($avifSizePath)
                                                && !self::picture_variant_dims_ok($avifSizePath, $avif_native_w, $avif_native_h)) {
                                                continue;
                                            }
                                            $pathPart = str_replace($avifSiteHost, '', $avifSrcUrl);
                                            $avifEntries[] = $avifZoneBase . $pathPart . ' ' . $descriptor;
                                        }
                                    } elseif ($optimistic_avif || $avif_otf_live || $avif_emit_natural) {


                                        $width = (int) preg_replace('/[^\d]/', '', (string) $descriptor);
                                        if ($width <= 0) $width = 1;
                                        $u_src = $avif_original_u_url !== '' ? $avif_original_u_url : $srcUrl;
                                        $u_src_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src);


                                        $main_is_wxh       = (bool) preg_match('/-\d+x\d+\.avif$/i', $avifSrcUrl);
                                        $main_is_nw        = (bool) preg_match('/-\d+w\.avif$/i', $avifSrcUrl);
                                        $main_is_bare_full = !$main_is_wxh && !$main_is_nw;
                                        $main_emit_natural = ($main_is_wxh && $avif_emit_natural)
                                            || ($main_is_bare_full && self::picture_avif_natural_full_ok());
                                        if ($main_emit_natural) {

                                            $avifEntries[] = $avifZoneBase . str_replace($avifSiteHost, '', $avifSrcUrl) . self::src_hint_qs($src_hint_ext) . ' ' . $descriptor;
                                        } else {
                                            // -Nw (no meta), or a bare-full/-WxH on a non-witnessed zone → never-404 wp:2 transform.
                                            $avifEntries[] = $avifZoneBase . '/q:i/r:0/wp:2/w:' . $width . '/u:' . self::uForCdn($u_src_via_cdn) . ' ' . $descriptor;
                                        }
                                    }
                                }
                            }


                            $final_srcset_avif = isset($original_img_tag['original_tags']['srcset'])
                                ? (string) $original_img_tag['original_tags']['srcset']
                                : '';


                            $avif_has_census_rungs = !empty(self::wpc_census_rung_targets($cleanSource));
                            if ($final_srcset_avif !== '' || $avif_has_census_rungs) {
                                $existing_widths_in_avif = [];
                                foreach ($avifEntries as $existing_entry) {
                                    if (preg_match('/\s(\d+)w$/', $existing_entry, $wm_ex)) {
                                        $existing_widths_in_avif[(int) $wm_ex[1]] = true;
                                    }
                                }
                                $meta_for_extra_avif = (isset($avif_attachment_id) && $avif_attachment_id > 0
                                                        && function_exists('wp_get_attachment_metadata'))
                                    ? wp_get_attachment_metadata($avif_attachment_id)
                                    : false;
                                $upload_dir_for_extra_avif = wp_get_upload_dir();
                                $upload_baseurl_for_extra_avif = isset($upload_dir_for_extra_avif['baseurl']) ? $upload_dir_for_extra_avif['baseurl'] : '';
                                $main_dir_for_extra_avif = (is_array($meta_for_extra_avif) && !empty($meta_for_extra_avif['file']))
                                    ? dirname((string) $meta_for_extra_avif['file'])
                                    : '';

                                $base_url_for_avif_natural = $avif_original_u_url !== '' ? $avif_original_u_url : $cleanSource;
                                $base_no_ext_for_avif = preg_replace('/\.(jpe?g|png|webp)$/i', '', $base_url_for_avif_natural);


                                // × DPR 2.625) with an existing 1005w rung read as "close" at 1.3 and masked the
                                // needed rung; 1005/893 = 1.125, so 1.1 injects it. <10% savings is still churn, skip.
                                $avif_census_synth_entries = '';
                                foreach (self::wpc_census_rung_targets($cleanSource) as $avif_census_target_w) {
                                    if ($avif_census_target_w < 48) { continue; }
                                    if ($avif_src_w_cap > 0 && $avif_census_target_w > $avif_src_w_cap) { continue; }
                                    $avif_rung_is_close = false;
                                    foreach ($existing_widths_in_avif as $avif_existing_w => $avif_existing_flag) {
                                        if ($avif_existing_w >= $avif_census_target_w && $avif_existing_w <= (int) ($avif_census_target_w * 1.1)) { $avif_rung_is_close = true; break; }
                                    }
                                    if (!$avif_rung_is_close && preg_match_all('/\s(\d+)w\s*(?:,|$)/', ' ' . $final_srcset_avif, $avif_srcset_width_matches)) {
                                        foreach ($avif_srcset_width_matches[1] as $avif_srcset_w) {
                                            $avif_srcset_w = (int) $avif_srcset_w;
                                            if ($avif_srcset_w >= $avif_census_target_w && $avif_srcset_w <= (int) ($avif_census_target_w * 1.1)) { $avif_rung_is_close = true; break; }
                                        }
                                    }
                                    if ($avif_rung_is_close) { continue; }
                                    $avif_census_synth_entries .= ($avif_census_synth_entries === '' ? '' : ',') . 'wpc-census ' . $avif_census_target_w . 'w';
                                }
                                $extra_seen_avif = [];
                                foreach (explode(',', ($avif_census_synth_entries !== '' ? $avif_census_synth_entries . ',' : '') . $final_srcset_avif) as $entry) {
                                    $entry = trim($entry);
                                    if (!preg_match('/^(\S+)\s+(\d+)w$/', $entry, $em)) continue;
                                    $extra_width = (int) $em[2];
                                    if ($extra_width <= 0) continue;
                                    if ($avif_src_w_cap > 0 && $extra_width > $avif_src_w_cap) continue;
                                    if (isset($existing_widths_in_avif[$extra_width])) continue;
                                    if (isset($extra_seen_avif[$extra_width])) continue;
                                    $extra_seen_avif[$extra_width] = true;


                                    $natural_url_avif = '';
                                    if (is_array($meta_for_extra_avif) && !empty($meta_for_extra_avif['sizes']) && $upload_baseurl_for_extra_avif !== '') {
                                        foreach ($meta_for_extra_avif['sizes'] as $sz_extra) {
                                            if (empty($sz_extra['file']) || empty($sz_extra['width'])) continue;
                                            if ((int) $sz_extra['width'] === $extra_width) {
                                                $sub_no_ext_extra = preg_replace('/\.[^.]+$/', '', basename((string) $sz_extra['file']));
                                                if ($sub_no_ext_extra !== '' && $sub_no_ext_extra !== null) {
                                                    $sub_dir_part = ($main_dir_for_extra_avif !== '' && $main_dir_for_extra_avif !== '.')
                                                        ? trim($main_dir_for_extra_avif, '/') . '/'
                                                        : '';
                                                    $natural_url_avif = trailingslashit($upload_baseurl_for_extra_avif) . $sub_dir_part . $sub_no_ext_extra . '.avif';
                                                    break;
                                                }
                                            }
                                        }
                                    }
                                    // (2) Adaptive-maximizing fallback: <base>-{N}w.avif
                                    if ($natural_url_avif === '') {
                                        $natural_url_avif = self::natural_ladder_url($base_no_ext_for_avif, $extra_width, $avif_aspect_meta, 'avif');
                                    }
                                    list($natural_url_avif, $natural_path_avif) = self::recoverAdaptiveVariant($natural_url_avif, $base_no_ext_for_avif, $extra_width, 'avif');


                                    $extra_is_wxh = (bool) preg_match('/-\d+x\d+\.avif$/i', $natural_url_avif);
                                    if (@file_exists($natural_path_avif)) {
                                        $pathPart_extra = str_replace($avifSiteHost, '', $natural_url_avif);
                                        $avifEntries[] = $avifZoneBase . $pathPart_extra . self::src_hint_qs($src_hint_ext, true) . ' ' . $extra_width . 'w';
                                    } elseif ($optimistic_avif || $avif_otf_live || $avif_emit_natural) {
                                        $u_src_extra = $avif_original_u_url !== '' ? $avif_original_u_url : $cleanSource;
                                        $u_src_extra_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src_extra);
                                        if ($avif_emit_natural && $extra_is_wxh) {

                                            $avifEntries[] = $avifZoneBase . str_replace($avifSiteHost, '', $natural_url_avif) . self::src_hint_qs($src_hint_ext) . ' ' . $extra_width . 'w';
                                        } else {
                                            // -{N}w (no meta) or optimistic-only → never-404 wp:2 transform.
                                            $avifEntries[] = $avifZoneBase . '/q:i/r:0/wp:2/w:' . $extra_width . '/u:' . self::uForCdn($u_src_extra_via_cdn) . ' ' . $extra_width . 'w';
                                        }
                                    } elseif ($em[1] === 'wpc-census') {


                                        $u_src_census = $avif_original_u_url !== '' ? $avif_original_u_url : $cleanSource;
                                        $u_src_census_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src_census);
                                        $avifEntries[] = $avifZoneBase . '/q:i/r:0/wp:2/w:' . $extra_width . '/u:' . self::uForCdn($u_src_census_via_cdn) . ' ' . $extra_width . 'w';
                                    }
                                    $avif_queue_w($extra_width);
                                }
                            }


                            if (($optimistic_avif || $avif_otf_live || $avif_emit_natural) && !empty($avifEntries)) {
                                $maxW_uni = !empty(self::$settings['maxWidth']) ? (int) self::$settings['maxWidth'] : 2560;
                                if ($maxW_uni < 100) $maxW_uni = 2560;
                                $effective_max_uni = $maxW_uni;
                                if (is_array($meta_for_extra_avif)
                                    && !empty($meta_for_extra_avif['width'])
                                    && !empty($meta_for_extra_avif['height'])) {
                                    $sw_uni = (int) $meta_for_extra_avif['width'];
                                    $sh_uni = (int) $meta_for_extra_avif['height'];
                                    if ($sh_uni > $sw_uni && $sh_uni > 0) {
                                        $effective_max_uni = (int) floor($maxW_uni * ($sw_uni / $sh_uni));
                                    }
                                }
                                // CEILING CAP: never exceed the source width (covers landscape + no-meta, which
                                // the portrait branch above misses → otherwise a no-store-webp upscale).
                                if ($avif_src_w_cap > 0) $effective_max_uni = min($effective_max_uni, $avif_src_w_cap);
                                // Base LCP-style ladder
                                $ladder_uni = [400, 480, 640, 720, 800, 960, 1100, 1200, 1280, 1366, 1440, 1600, 1800, 2048, 2560];
                                // Retina doubles of all widths already in srcset entries
                                foreach ($existing_widths_in_avif as $ww => $_) {
                                    $ladder_uni[] = (int) $ww * 2;
                                }


                                foreach (self::wpc_census_rung_targets($cleanSource) as $avif_ladder_census_w) {
                                    if ((int) $avif_ladder_census_w >= 48) { $ladder_uni[] = (int) $avif_ladder_census_w; }
                                }
                                // Mobile srcset cap (see buildLcpSrcset).
                                if (self::$isMobile && self::$adaptiveEnabled) {
                                    $mob_cap = (int) apply_filters('wpc_mobile_srcset_cap',
                                        (int) get_option('wpc-min-mobile-width', 400),
                                        $cleanSource);
                                    if ($mob_cap > 0) {
                                        $ladder_uni = array_values(array_filter($ladder_uni, function ($w) use ($mob_cap) {
                                            return $w <= $mob_cap;
                                        }));
                                        if (empty($ladder_uni)) $ladder_uni = [$mob_cap];
                                    }
                                }
                                // Cap to effective_max + dedup + sort
                                $ladder_uni = array_values(array_unique(array_map(function ($w) use ($effective_max_uni) {
                                    return min($w, $effective_max_uni);
                                }, $ladder_uni)));
                                sort($ladder_uni);
                                // Emit hybrid for each ladder width not already present
                                foreach ($ladder_uni as $w_uni) {
                                    if ($w_uni <= 0) continue;
                                    if (isset($existing_widths_in_avif[$w_uni])) continue;
                                    $existing_widths_in_avif[$w_uni] = true;
                                    // Natural URL = <unscaled-base>-{N}w.avif per the
                                    // lazy_cdn ingest's adaptive-maximizing fallback.
                                    $base_url_uni = $avif_original_u_url !== '' ? $avif_original_u_url : $cleanSource;
                                    $base_no_ext_uni = preg_replace('/\.(jpe?g|png|webp)$/i', '', $base_url_uni);
                                    $natural_url_uni = self::natural_ladder_url($base_no_ext_uni, $w_uni, $avif_aspect_meta, 'avif');
                                    list($natural_url_uni, $natural_path_uni) = self::recoverAdaptiveVariant($natural_url_uni, $base_no_ext_uni, $w_uni, 'avif');
                                    // NEVER-404: natural only for a recovered on-disk file OR a -WxH-form URL
                                    // (OTF-proven); a degraded -{N}w → wp:2.
                                    $uni_is_wxh = (bool) preg_match('/-\d+x\d+\.avif$/i', $natural_url_uni);
                                    if (@file_exists($natural_path_uni)) {
                                        $pathPart_uni = str_replace($avifSiteHost, '', $natural_url_uni);
                                        $avifEntries[] = $avifZoneBase . $pathPart_uni . self::src_hint_qs($src_hint_ext, true) . ' ' . $w_uni . 'w';
                                    } else {
                                        $u_src_uni = $avif_original_u_url !== '' ? $avif_original_u_url : $cleanSource;
                                        $u_src_uni_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src_uni);
                                        if ($avif_emit_natural && $uni_is_wxh) {

                                            $avifEntries[] = $avifZoneBase . str_replace($avifSiteHost, '', $natural_url_uni) . self::src_hint_qs($src_hint_ext) . ' ' . $w_uni . 'w';
                                        } else {
                                            // -{N}w (no meta) or witness-off → never-404 wp:2 transform.
                                            $avifEntries[] = $avifZoneBase . '/q:i/r:0/wp:2/w:' . $w_uni . '/u:' . self::uForCdn($u_src_uni_via_cdn) . ' ' . $w_uni . 'w';
                                        }
                                    }
                                    $avif_queue_w($w_uni);
                                }
                            }


                            if (!empty($avifEntries) && $avif_attachment_id > 0
                                && function_exists('wp_get_attachment_metadata')
                                && function_exists('wp_get_attachment_image_url')) {
                                $avif_native_w  = 0;
                                $avif_full_nat  = '';
                                $avif_meta_ceil = wp_get_attachment_metadata($avif_attachment_id);
                                if (is_array($avif_meta_ceil) && !empty($avif_meta_ceil['width'])) {
                                    $avif_native_w = (int) $avif_meta_ceil['width'];
                                    $avif_full_src = wp_get_attachment_image_url($avif_attachment_id, 'full');


                                    if ($avif_full_src && strpos((string) $avif_full_src, $avifSiteHost) === 0) {
                                        $avif_full_url  = preg_replace('/\.(jpe?g|png|webp)$/i', '.avif', preg_replace('/[?#].*$/', '', $avif_full_src));
                                        $avif_full_disk = str_replace($avifSiteUrl, trailingslashit(ABSPATH), $avif_full_url);


                                        $avif_full_reach = (@file_exists($avif_full_disk)
                                                && self::picture_variant_dims_ok($avif_full_disk, $avif_native_w, $avif_native_h))
                                            || (self::picture_avif_natural_full_ok() && $avif_src_transcodable); // BARE full-size: proven witness (CDN bare-OTF bug); AND transcodable — don't fold to a bare natural .avif the edge can't OTF from a webp-native base
                                        if ($avif_full_reach) {
                                            $avif_full_nat = $avifZoneBase . str_replace($avifSiteHost, '', $avif_full_url);
                                        }
                                    }
                                }
                                if ($avif_native_w > 0 && $avif_full_nat !== '') {


                                    $avif_kept_ceil = [];
                                    $avif_collapsed = false;
                                    foreach ($avifEntries as $avif_e_ceil) {
                                        if (preg_match('/\s(\d+)w$/', $avif_e_ceil, $avif_w_ceil) && (int) $avif_w_ceil[1] >= $avif_native_w) {
                                            $avif_collapsed = true;
                                            continue;
                                        }
                                        $avif_kept_ceil[] = $avif_e_ceil;
                                    }
                                    if ($avif_collapsed) {
                                        $avif_kept_ceil[] = $avif_full_nat . ' ' . $avif_native_w . 'w';
                                        $avifEntries = $avif_kept_ceil;
                                    }
                                }
                            }


                            $avif_deep_enough = $optimistic_avif || $avif_otf_live || $avif_emit_natural || count($avifEntries) >= 2;
                            if (!empty($avifEntries) && $avif_deep_enough) {
                                $avifSource = '<source ' . self::picture_source_srcset_attr($build_image_tag) . '="' . implode(', ', $avifEntries) . '"' . $sourceSizes . ' type="image/avif">';
                            }
                        } else {
                            // Single-src fallback (no srcset on the img tag)
                            $avifCdnUrl = '';


                            $avif_single_ok = @file_exists($avifPath)
                                && self::picture_variant_dims_ok($avifPath, $avif_native_w, $avif_native_h);
                            if ($avif_single_ok) {
                                $pathPart   = self::avif_single_pathpart($avifUrl, $avifZoneBase, $avifSiteHost);
                                $avifCdnUrl = $avifZoneBase . $pathPart;
                            } elseif (self::picture_avif_natural_full_ok() && $avif_src_transcodable) {


                                $pathPart   = self::avif_single_pathpart($avifUrl, $avifZoneBase, $avifSiteHost);
                                $avifCdnUrl = $avifZoneBase . $pathPart;
                            } elseif ($optimistic_avif) {


                                $single_is_wxh = (bool) preg_match('/-\d+x\d+\.avif$/i', $avifUrl);
                                if ($single_is_wxh && self::picture_natural_fleet_enabled() && $avif_src_transcodable) {
                                    $pathPart   = self::avif_single_pathpart($avifUrl, $avifZoneBase, $avifSiteHost);
                                    $avifCdnUrl = $avifZoneBase . $pathPart;
                                } else {
                                    $u_single = $avif_original_u_url !== '' ? $avif_original_u_url : $cleanSource;
                                    $u_single_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_single);
                                    $avifCdnUrl = $avifZoneBase . '/q:i/r:0/wp:2/w:1/u:' . self::uForCdn($u_single_via_cdn);
                                }
                            }
                            if ($avifCdnUrl !== '') {
                                $avifSource = '<source ' . self::picture_source_srcset_attr($build_image_tag) . '="' . $avifCdnUrl . '"' . $sourceSizes . ' type="image/avif">';
                            }
                        }
                    }
                }

                // Rebuild WebP source srcset with the same hybrid emission as AVIF: .webp on disk → natural
                // URL via CDN passthrough; missing → wp:1 transform (CDN transforms JPG→WebP synchronously).
                if (self::wpc_natural_nw()) {


                    $webp_nw_cap  = isset($avif_src_w_cap) ? (int) $avif_src_w_cap : 0;
                    $webp_nw_hint = isset($src_hint_ext) ? $src_hint_ext : '';
                    $webp_aspect  = isset($avif_aspect_meta) ? $avif_aspect_meta : null;
                    $webp_nw_entries = [];
                    $webp_nw_emitted_widths = [];
                    foreach (self::wpc_nw_widths($original_img_tag, $webp_nw_cap) as $nw_w) {
                        $webp_nw_entries[] = self::wpc_nw_url($cleanSource, $nw_w, 'webp', $webp_aspect) . self::src_hint_qs($webp_nw_hint) . ' ' . $nw_w . 'w';
                        $webp_nw_emitted_widths[(int) $nw_w] = 1;
                    }

                    foreach (self::wpc_census_rung_targets($cleanSource) as $webp_census_w) {
                        $webp_census_w = (int) $webp_census_w;
                        if ($webp_census_w < 48 || isset($webp_nw_emitted_widths[$webp_census_w])) { continue; }
                        if ($webp_nw_cap > 0 && $webp_census_w > $webp_nw_cap) { continue; }
                        $webp_nw_entries[] = self::wpc_nw_url($cleanSource, $webp_census_w, 'webp', $webp_aspect) . self::src_hint_qs($webp_nw_hint) . ' ' . $webp_census_w . 'w';
                        $webp_nw_emitted_widths[$webp_census_w] = 1;
                    }

                    if ($webp_nw_cap > 0 && !self::wpc_census_rung_targets($cleanSource)) {
                        foreach ((array) apply_filters('wpc_nw_default_rungs', [360, 480, 640, 750, 828, 1080]) as $webp_default_rung_w) {
                            $webp_default_rung_w = (int) $webp_default_rung_w;
                            if ($webp_default_rung_w < 48 || isset($webp_nw_emitted_widths[$webp_default_rung_w])) { continue; }
                            if ($webp_nw_cap > 0 && $webp_default_rung_w > $webp_nw_cap) { continue; }
                            $webp_nw_entries[] = self::wpc_nw_url($cleanSource, $webp_default_rung_w, 'webp', $webp_aspect) . self::src_hint_qs($webp_nw_hint) . ' ' . $webp_default_rung_w . 'w';
                            $webp_nw_emitted_widths[$webp_default_rung_w] = 1;
                        }
                    }
                    if (empty($webp_nw_entries) && self::wpc_is_attachment_recorded($cleanSource)) {

                        $webp_full_nw = self::wpc_natural_full_url($cleanSource, 'webp');
                        if ($webp_full_nw !== '') $webp_nw_entries[] = $webp_full_nw . self::src_hint_qs($webp_nw_hint);
                    }
                    if (!empty($webp_nw_entries)) {
                        $sourceSrcset = ' ' . self::picture_source_srcset_attr($build_image_tag) . '="' . implode(', ', $webp_nw_entries) . '"';
                    }
                } elseif (!empty($original_img_tag['original_srcset'])) {
                    $webpSiteUrl = trailingslashit(site_url());
                    $webpSiteHost = rtrim($webpSiteUrl, '/');
                    $webpZoneBase = 'https://' . self::$zoneName;


                    $webpSrcsetAttr = self::picture_source_srcset_attr($build_image_tag);


                    $webp_attachment_id = 0;
                    if (!empty($original_img_tag['original_tags']['class'])
                        && preg_match('/\bwp-image-(\d+)\b/', $original_img_tag['original_tags']['class'], $im_webp)) {
                        $webp_attachment_id = (int) $im_webp[1];
                    }
                    $webp_original_u_url = '';
                    if ($webp_attachment_id > 0
                        && function_exists('wpc_v2_lazy_cdn_use_original')
                        && wpc_v2_lazy_cdn_use_original($webp_attachment_id)
                        && function_exists('wp_get_original_image_url')
                        && function_exists('wp_get_original_image_path')) {
                        $orig_u = wp_get_original_image_url($webp_attachment_id);
                        $orig_p = wp_get_original_image_path($webp_attachment_id);
                        if ($orig_u && $orig_p && @file_exists($orig_p)) {
                            $webp_original_u_url = $orig_u;
                        }
                    }

                    $webpEntries = [];
                    foreach (explode(',', $original_img_tag['original_srcset']) as $part) {
                        $part = trim($part);
                        if (!preg_match('/^(\S+)\s+(.+)$/', $part, $wm)) continue;
                        $jpgUrl = preg_replace('/[?#].*$/', '', $wm[1]);
                        $descriptor = $wm[2];
                        $webpUrl = preg_replace('/\.(jpe?g|png|avif)$/i', '.webp', $jpgUrl);
                        $webpDisk = str_replace($webpSiteUrl, trailingslashit(ABSPATH), $webpUrl);

                        // DIMS-VALIDITY (symmetric with the AVIF rung): drop a dimensionally-corrupt on-disk
                        // .webp so a type-pinned webp <source> can't render the wrong image. Fail-safe KEEP on undecodable.
                        if (@file_exists($webpDisk)) {
                            $wnw_meta = ($webp_attachment_id > 0 && function_exists('wp_get_attachment_metadata'))
                                ? wp_get_attachment_metadata($webp_attachment_id) : false;
                            $wnw = (is_array($wnw_meta) && !empty($wnw_meta['width'])) ? (int) $wnw_meta['width'] : 0;
                            $wnh = (is_array($wnw_meta) && !empty($wnw_meta['height'])) ? (int) $wnw_meta['height'] : 0;
                            if (!self::picture_variant_dims_ok($webpDisk, $wnw, $wnh)) {
                                continue;
                            }
                        }

                        if (@file_exists($webpDisk)) {
                            $pathPart = str_replace($webpSiteHost, '', $webpUrl);
                            $webpEntries[] = $webpZoneBase . $pathPart . ' ' . $descriptor;
                        } else {


                            $main_wp_is_wxh       = (bool) preg_match('/-\d+x\d+\.webp$/i', $webpUrl);
                            $main_wp_is_nw        = (bool) preg_match('/-\d+w\.webp$/i', $webpUrl);
                            $main_wp_is_bare_full = !$main_wp_is_wxh && !$main_wp_is_nw;
                            $main_wp_emit_natural = ($main_wp_is_wxh && self::picture_webp_natural_ok())
                                || ($main_wp_is_bare_full && self::picture_webp_natural_full_ok());
                            if ($main_wp_emit_natural) {
                                $pathPart = str_replace($webpSiteHost, '', $webpUrl);
                                $webpEntries[] = $webpZoneBase . $pathPart . self::src_hint_qs($src_hint_ext) . ' ' . $descriptor;
                            } else {
                                // Rewrite `u:` host to cdn-zone so CDN fetches via its own passthrough
                                // (fixes 302→origin when origin fetch is blocked).
                                $width = (int) preg_replace('/[^\d]/', '', (string) $descriptor);
                                if ($width <= 0) $width = 1;
                                $u_src = $webp_original_u_url !== '' ? $webp_original_u_url : $jpgUrl;
                                $u_src_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src);
                                $webpEntries[] = $webpZoneBase . '/q:i/r:0/wp:1/w:' . $width . '/u:' . self::uForCdn($u_src_via_cdn) . ' ' . $descriptor;
                            }
                        }
                    }

                    // Mirror of the AVIF extra-widths block above: img srcset has retina/adaptive widths
                    // absent from original_srcset, so without these slots those WebP widths can't be cached.
                    $final_srcset_webp = isset($original_img_tag['original_tags']['srcset'])
                        ? (string) $original_img_tag['original_tags']['srcset']
                        : '';

                    // (Bricks hero) must still receive its census rungs. See the avif twin.
                    $webp_has_census_rungs = !empty(self::wpc_census_rung_targets($image_source));
                    if ($final_srcset_webp !== '' || $webp_has_census_rungs) {
                        $existing_widths_in_webp = [];
                        foreach ($webpEntries as $existing_entry) {
                            if (preg_match('/\s(\d+)w$/', $existing_entry, $wm_ex_wp)) {
                                $existing_widths_in_webp[(int) $wm_ex_wp[1]] = true;
                            }
                        }
                        $meta_for_extra_webp = ($webp_attachment_id > 0 && function_exists('wp_get_attachment_metadata'))
                            ? wp_get_attachment_metadata($webp_attachment_id)
                            : false;
                        $upload_dir_for_extra_webp = wp_get_upload_dir();
                        $upload_baseurl_for_extra_webp = isset($upload_dir_for_extra_webp['baseurl']) ? $upload_dir_for_extra_webp['baseurl'] : '';
                        $main_dir_for_extra_webp = (is_array($meta_for_extra_webp) && !empty($meta_for_extra_webp['file']))
                            ? dirname((string) $meta_for_extra_webp['file'])
                            : '';

                        $base_url_for_webp_natural = $webp_original_u_url !== '' ? $webp_original_u_url : preg_replace('/\?.*$/', '', $image_source);
                        $base_no_ext_for_webp = preg_replace('/\.(jpe?g|png|avif)$/i', '', $base_url_for_webp_natural);


                        // MOBILE renders: the universal ladder below is capped at wpc-min-mobile-width (400
                        // default), so a measured 2×css_w like 680 never enters via the ladder and Safari


                        $webp_census_synth_entries = '';
                        foreach (self::wpc_census_rung_targets($image_source) as $webp_census_target_w) {
                            if ($webp_census_target_w < 48) { continue; }
                            if ($avif_src_w_cap > 0 && $webp_census_target_w > $avif_src_w_cap) { continue; }
                            $webp_rung_is_close = false;
                            foreach ($existing_widths_in_webp as $webp_existing_w => $webp_existing_flag) {
                                if ($webp_existing_w >= $webp_census_target_w && $webp_existing_w <= (int) ($webp_census_target_w * 1.1)) { $webp_rung_is_close = true; break; }
                            }
                            if (!$webp_rung_is_close && preg_match_all('/\s(\d+)w\s*(?:,|$)/', ' ' . $final_srcset_webp, $webp_srcset_width_matches)) {
                                foreach ($webp_srcset_width_matches[1] as $webp_srcset_w) {
                                    $webp_srcset_w = (int) $webp_srcset_w;
                                    if ($webp_srcset_w >= $webp_census_target_w && $webp_srcset_w <= (int) ($webp_census_target_w * 1.1)) { $webp_rung_is_close = true; break; }
                                }
                            }
                            if ($webp_rung_is_close) { continue; }
                            $webp_census_synth_entries .= ($webp_census_synth_entries === '' ? '' : ',') . 'wpc-census ' . $webp_census_target_w . 'w';
                        }
                        $extra_seen_webp = [];
                        foreach (explode(',', ($webp_census_synth_entries !== '' ? $webp_census_synth_entries . ',' : '') . $final_srcset_webp) as $entry) {
                            $entry = trim($entry);
                            if (!preg_match('/^(\S+)\s+(\d+)w$/', $entry, $em_wp)) continue;
                            $extra_width_wp = (int) $em_wp[2];
                            if ($extra_width_wp <= 0) continue;
                            if ($avif_src_w_cap > 0 && $extra_width_wp > $avif_src_w_cap) continue;
                            if (isset($existing_widths_in_webp[$extra_width_wp])) continue;
                            if (isset($extra_seen_webp[$extra_width_wp])) continue;
                            $extra_seen_webp[$extra_width_wp] = true;

                            $natural_url_webp = '';
                            if (is_array($meta_for_extra_webp) && !empty($meta_for_extra_webp['sizes']) && $upload_baseurl_for_extra_webp !== '') {
                                foreach ($meta_for_extra_webp['sizes'] as $sz_extra_wp) {
                                    if (empty($sz_extra_wp['file']) || empty($sz_extra_wp['width'])) continue;
                                    if ((int) $sz_extra_wp['width'] === $extra_width_wp) {
                                        $sub_no_ext_extra_wp = preg_replace('/\.[^.]+$/', '', basename((string) $sz_extra_wp['file']));
                                        if ($sub_no_ext_extra_wp !== '' && $sub_no_ext_extra_wp !== null) {
                                            $sub_dir_part_wp = ($main_dir_for_extra_webp !== '' && $main_dir_for_extra_webp !== '.')
                                                ? trim($main_dir_for_extra_webp, '/') . '/'
                                                : '';
                                            $natural_url_webp = trailingslashit($upload_baseurl_for_extra_webp) . $sub_dir_part_wp . $sub_no_ext_extra_wp . '.webp';
                                            break;
                                        }
                                    }
                                }
                            }
                            if ($natural_url_webp === '') {
                                $natural_url_webp = self::natural_ladder_url($base_no_ext_for_webp, $extra_width_wp, $avif_aspect_meta, 'webp');
                            }
                            list($natural_url_webp, $natural_path_webp) = self::recoverAdaptiveVariant($natural_url_webp, $base_no_ext_for_webp, $extra_width_wp, 'webp');
                            // NEVER-404 (symmetric with AVIF extra-widths): natural only for a recovered on-disk
                            // file OR the proven -WxH form; a degraded -{N}w → wp:1.
                            $extra_wp_is_wxh = (bool) preg_match('/-\d+x\d+\.webp$/i', $natural_url_webp);

                            if (@file_exists($natural_path_webp)) {
                                $pathPart_extra_wp = str_replace($webpSiteHost, '', $natural_url_webp);
                                $webpEntries[] = $webpZoneBase . $pathPart_extra_wp . self::src_hint_qs($src_hint_ext, true) . ' ' . $extra_width_wp . 'w';
                            } else {


                                if (self::picture_webp_natural_ok() && $extra_wp_is_wxh) {
                                    $pathPart_extra_wp = str_replace($webpSiteHost, '', $natural_url_webp);
                                    $webpEntries[] = $webpZoneBase . $pathPart_extra_wp . self::src_hint_qs($src_hint_ext) . ' ' . $extra_width_wp . 'w';
                                } else {
                                    // -{N}w (no meta) or witness-off → never-404 wp:1 transform.
                                    $u_src_extra_wp = $webp_original_u_url !== '' ? $webp_original_u_url : preg_replace('/\?.*$/', '', $image_source);
                                    $u_src_extra_wp_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src_extra_wp);
                                    $webpEntries[] = $webpZoneBase . '/q:i/r:0/wp:1/w:' . $extra_width_wp . '/u:' . self::uForCdn($u_src_extra_wp_via_cdn) . ' ' . $extra_width_wp . 'w';
                                }
                            }
                        }
                    }


                    if (!empty($webpEntries)) {
                        $maxW_uni_wp = !empty(self::$settings['maxWidth']) ? (int) self::$settings['maxWidth'] : 2560;
                        if ($maxW_uni_wp < 100) $maxW_uni_wp = 2560;
                        $effective_max_uni_wp = $maxW_uni_wp;
                        if (is_array($meta_for_extra_webp)
                            && !empty($meta_for_extra_webp['width'])
                            && !empty($meta_for_extra_webp['height'])) {
                            $sw_uni_wp = (int) $meta_for_extra_webp['width'];
                            $sh_uni_wp = (int) $meta_for_extra_webp['height'];
                            if ($sh_uni_wp > $sw_uni_wp && $sh_uni_wp > 0) {
                                $effective_max_uni_wp = (int) floor($maxW_uni_wp * ($sw_uni_wp / $sh_uni_wp));
                            }
                        }
                        // CEILING CAP (shared source width; covers landscape + no-meta).
                        if ($avif_src_w_cap > 0) $effective_max_uni_wp = min($effective_max_uni_wp, $avif_src_w_cap);
                        $ladder_uni_wp = [400, 480, 640, 720, 800, 960, 1100, 1200, 1280, 1366, 1440, 1600, 1800, 2048, 2560];
                        foreach ($existing_widths_in_webp as $ww_wp => $_) {
                            $ladder_uni_wp[] = (int) $ww_wp * 2;
                        }
                        // Mobile srcset cap (see buildLcpSrcset).
                        if (self::$isMobile && self::$adaptiveEnabled) {
                            $mob_cap_wp = (int) apply_filters('wpc_mobile_srcset_cap',
                                (int) get_option('wpc-min-mobile-width', 400),
                                $image_source);
                            if ($mob_cap_wp > 0) {
                                $ladder_uni_wp = array_values(array_filter($ladder_uni_wp, function ($w) use ($mob_cap_wp) {
                                    return $w <= $mob_cap_wp;
                                }));
                                if (empty($ladder_uni_wp)) $ladder_uni_wp = [$mob_cap_wp];
                            }
                        }
                        $ladder_uni_wp = array_values(array_unique(array_map(function ($w) use ($effective_max_uni_wp) {
                            return min($w, $effective_max_uni_wp);
                        }, $ladder_uni_wp)));
                        sort($ladder_uni_wp);
                        foreach ($ladder_uni_wp as $w_uni_wp) {
                            if ($w_uni_wp <= 0) continue;
                            if (isset($existing_widths_in_webp[$w_uni_wp])) continue;
                            $existing_widths_in_webp[$w_uni_wp] = true;
                            $base_url_uni_wp = $webp_original_u_url !== '' ? $webp_original_u_url : preg_replace('/\?.*$/', '', $image_source);
                            $base_no_ext_uni_wp = preg_replace('/\.(jpe?g|png|avif)$/i', '', $base_url_uni_wp);
                            $natural_url_uni_wp = self::natural_ladder_url($base_no_ext_uni_wp, $w_uni_wp, $avif_aspect_meta, 'webp');
                            list($natural_url_uni_wp, $natural_path_uni_wp) = self::recoverAdaptiveVariant($natural_url_uni_wp, $base_no_ext_uni_wp, $w_uni_wp, 'webp');
                            // NEVER-404 (symmetric with AVIF universal ladder): natural only for a recovered
                            // on-disk file OR the proven -WxH form; a degraded -{N}w → wp:1.
                            $uni_wp_is_wxh = (bool) preg_match('/-\d+x\d+\.webp$/i', $natural_url_uni_wp);
                            if (@file_exists($natural_path_uni_wp)) {
                                $pathPart_uni_wp = str_replace($webpSiteHost, '', $natural_url_uni_wp);
                                $webpEntries[] = $webpZoneBase . $pathPart_uni_wp . self::src_hint_qs($src_hint_ext, true) . ' ' . $w_uni_wp . 'w';
                            } else {


                                if (self::picture_webp_natural_ok() && $uni_wp_is_wxh) {
                                    $pathPart_uni_wp = str_replace($webpSiteHost, '', $natural_url_uni_wp);
                                    $webpEntries[] = $webpZoneBase . $pathPart_uni_wp . self::src_hint_qs($src_hint_ext) . ' ' . $w_uni_wp . 'w';
                                } else {
                                    // -{N}w (no meta) or witness-off → never-404 wp:1 transform.
                                    $u_src_uni_wp = $webp_original_u_url !== '' ? $webp_original_u_url : preg_replace('/\?.*$/', '', $image_source);
                                    $u_src_uni_wp_via_cdn = preg_replace('#^https?://[^/]+#', 'https://' . self::$zoneName, $u_src_uni_wp);
                                    $webpEntries[] = $webpZoneBase . '/q:i/r:0/wp:1/w:' . $w_uni_wp . '/u:' . self::uForCdn($u_src_uni_wp_via_cdn) . ' ' . $w_uni_wp . 'w';
                                }
                            }
                        }
                    }


                    if (!empty($webpEntries) && $webp_attachment_id > 0
                        && function_exists('wp_get_attachment_metadata')
                        && function_exists('wp_get_attachment_image_url')) {
                        $webp_native_w  = 0;
                        $webp_full_nat  = '';
                        $webp_meta_ceil = wp_get_attachment_metadata($webp_attachment_id);
                        if (is_array($webp_meta_ceil) && !empty($webp_meta_ceil['width'])) {
                            $webp_native_w = (int) $webp_meta_ceil['width'];
                            $webp_full_src = wp_get_attachment_image_url($webp_attachment_id, 'full');
                            // Same-host guard (see AVIF block): only host-swap a clean same-site
                            // uploads URL; skip if a filter rewrote it to a CDN/transform URL.
                            if ($webp_full_src && strpos((string) $webp_full_src, $webpSiteHost) === 0) {
                                $webp_full_url  = preg_replace('/\.(jpe?g|png|avif)$/i', '.webp', preg_replace('/\?.*$/', '', $webp_full_src));
                                $webp_full_disk = str_replace($webpSiteUrl, trailingslashit(ABSPATH), $webp_full_url);
                                // DIMS-VALIDITY (symmetric with avif full-reach): a corrupt on-disk full-size
                                // .webp must not satisfy the on-disk reach; use the witness.
                                $webp_native_h_ceil = (is_array($webp_meta_ceil) && !empty($webp_meta_ceil['height'])) ? (int) $webp_meta_ceil['height'] : 0;
                                $webp_full_reach = (@file_exists($webp_full_disk)
                                        && self::picture_variant_dims_ok($webp_full_disk, $webp_native_w, $webp_native_h_ceil))
                                    || self::picture_webp_natural_full_ok(); // BARE full-size: proven witness (symmetric with avif)
                                if ($webp_full_reach) {
                                    $webp_full_nat = $webpZoneBase . str_replace($webpSiteHost, '', $webp_full_url);
                                }
                            }
                        }
                        if ($webp_native_w > 0 && $webp_full_nat !== '') {
                            $webp_kept_ceil = [];
                            $webp_collapsed = false;
                            foreach ($webpEntries as $webp_e_ceil) {
                                if (preg_match('/\s(\d+)w$/', $webp_e_ceil, $webp_w_ceil) && (int) $webp_w_ceil[1] >= $webp_native_w) {
                                    $webp_collapsed = true;
                                    continue;
                                }
                                $webp_kept_ceil[] = $webp_e_ceil;
                            }
                            if ($webp_collapsed) {
                                $webp_kept_ceil[] = $webp_full_nat . ' ' . $webp_native_w . 'w';
                                $webpEntries = $webp_kept_ceil;
                            }
                        }
                    }

                    if (!empty($webpEntries)) {
                        $sourceSrcset = ' ' . $webpSrcsetAttr . '="' . implode(', ', $webpEntries) . '"';
                    }
                }


                if (!(class_exists('WPC_Negotiated_Delivery') && WPC_Negotiated_Delivery::is_active())) {


                    if (!empty($image_source) && strpos($fallbackTag, 'data-wpc-fb=') === false && stripos($fallbackTag, '<img') !== false) {
                        $wpc_fb_origin  = esc_attr(preg_replace('/\?.*$/', '', (string) $image_source));
                        $wpc_fb_handler = "this.onerror=null;var p=this.parentNode;if(p&&p.tagName==='PICTURE'){var s;while(s=p.getElementsByTagName('source')[0])s.parentNode.removeChild(s);}this.removeAttribute('srcset');this.src=this.getAttribute('data-wpc-fb');";
                        $fallbackTag = preg_replace('/<img\b/i', '<img data-wpc-fb="' . $wpc_fb_origin . '" onerror="' . $wpc_fb_handler . '"', $fallbackTag, 1);
                    }


                    if ((self::picture_natural_fleet_enabled() || self::wpc_natural_nw()) && self::$zoneName !== '') {


                        $fallbackTag = preg_replace_callback(
                            '#https?://' . preg_quote(self::$zoneName, '#') . '/[^"\x27\s,>]*?(?:/w:(\d+))?/u:(https?://[^"\x27\s,>]+?\.(?:webp|avif|jpe?g|png|gif)(?![\w-]))(?:\?[^"\x27\s,>]*)?#i',
                            function ($m) {
                                $w    = (isset($m[1]) && $m[1] !== '') ? (int) $m[1] : 0;
                                $path = (string) wp_parse_url($m[2], PHP_URL_PATH);
                                if ($path === '') return $m[0];
                                $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
                                if ($ext === '') $ext = 'webp';
                                $noext = preg_replace('/\.[a-z0-9]+$/i', '', $path);
                                // w:1..5 = legacy "no-resize" sentinel (excluded-adaptive), never a real pixel width
                                if ($w > 5 && preg_match('#^(.*)-(\d+)x(\d+)$#', $noext, $d) && (int) $d[2] > 0) {
                                    $sw = (int) $d[2]; $sh = (int) $d[3];
                                    $h  = (int) round($w * $sh / $sw);
                                    if ($h > 0) {
                                        return 'https://' . self::$zoneName . $d[1] . '-' . $w . 'x' . $h . '.' . $ext;
                                    }
                                }

                                // -WxH basis to synthesize from; "host-swap as-is" swapped W-wide bytes for
                                // the FULL-SIZE file while the rung's " Ww" descriptor (outside this match)


                                // Keep the transform: it resizes to W and never 404s. Only a no-resize URL
                                // (no /w: segment) may host-swap bare.
                                if ($w > 0) {
                                    return $m[0];
                                }
                                return 'https://' . self::$zoneName . $noext . '.' . $ext;
                            },
                            $fallbackTag
                        );
                    }


                    $fallback_src = (string) $image_source;
                    if ($fallback_src !== '' && stripos($fallback_src, '/u:') !== false
                        && preg_match('#/u:(https?://[^"\'\s]+)$#i', $fallback_src, $u_origin_match)) {
                        $u_origin_host = strtolower((string) parse_url($u_origin_match[1], PHP_URL_HOST));
                        $site_host = strtolower((string) parse_url(site_url(), PHP_URL_HOST));
                        if ($u_origin_host !== '' && ($u_origin_host === $site_host
                            || $u_origin_host === 'www.' . $site_host || 'www.' . $u_origin_host === $site_host)) {
                            $fallback_src = $u_origin_match[1];
                        }
                    }
                    if ($fallback_src !== '' && stripos($fallback_src, '/u:') === false) {
                        $wpc_fb_clean = preg_replace('/\?.*$/', '', $fallback_src);
                        $fallbackTag  = preg_replace_callback(
                            '/\s(src|data-src)="([^"]*)"/i',
                            function ($m) use ($wpc_fb_clean) {
                                if (stripos($m[2], '/u:') !== false) {
                                    return ' ' . $m[1] . '="' . esc_url($wpc_fb_clean) . '"';
                                }
                                return $m[0];
                            },
                            $fallbackTag
                        );
                    }


                    if (!empty($image_source)
                        && preg_match('/\.webp$/i', (string) preg_replace('/[?#].*$/', '', (string) $image_source))
                        && $sourceSrcset !== ''
                        && stripos($sourceSrcset, '/u:') === false
                        && preg_match('/="([^"]+)"\s*$/', $sourceSrcset, $natural_srcset_match)
                        && strpos($natural_srcset_match[1], ',') !== false) {
                        $fallbackTag = (string) preg_replace_callback(
                            '/\s(?:data-)?srcset\s*=\s*"[^"]*"/i',
                            function ($mm) use ($natural_srcset_match) {
                                return (stripos($mm[0], 'data-') === 1)
                                    ? ' data-srcset="' . $natural_srcset_match[1] . '"'
                                    : ' srcset="' . $natural_srcset_match[1] . '"';
                            },
                            $fallbackTag, 1);
                    }


                    // Tradeoff (deliberate, user-decided): retina phones render the DPR-1 file.
                    // Kill: filter wpc_precise_slot_arm → false restores DPR-true rungs.
                    $mobile_slot_sources = '';
                    try {
                        if (apply_filters('wpc_precise_slot_arm', true) && !empty($image_source)) {
                            $slot_stem = strtolower(basename((string) preg_replace('/[?#].*$/', '', (string) $image_source)));
                            $slot_stem = (string) preg_replace('/\.(?:jpe?g|png|webp|avif|gif)$/i', '', $slot_stem);
                            $slot_stem = (string) preg_replace('/(?:-\d+x\d+)?$/', '', (string) preg_replace('/-scaled$/', '', $slot_stem), 1);
                            $mobile_slot_w = (int) self::wpc_census_slot($slot_stem)['m'];
                            if ($mobile_slot_w >= 24) {


                                $mobile_slot_w2x = $mobile_slot_w * 2;
                                foreach ([['tag' => $avifSource, 'type' => 'avif'], ['tag' => '<source' . $sourceSrcset . $sourceSizes . '>', 'type' => 'webp']] as $source_lane) {
                                    if ($source_lane['tag'] === '' || strpos($source_lane['tag'], (string) $mobile_slot_w . 'w') === false) { continue; }
                                    if (preg_match('#(?:srcset|data-srcset)="(?:[^"]*?,\s*)?([^"\s,]+)\s+' . $mobile_slot_w . 'w#', $source_lane['tag'], $slot_url_match)
                                        && stripos($slot_url_match[1], '/u:') === false) {
                                        $mobile_slot_sources .= '<source media="(max-width: 767.98px) and (max-resolution: 1.9dppx)" srcset="' . $slot_url_match[1] . ' ' . $mobile_slot_w . 'w"'
                                            . ' sizes="' . $mobile_slot_w . 'px" type="image/' . $source_lane['type'] . '">';

                                        if (preg_match('#(?:srcset|data-srcset)="(?:[^"]*?,\s*)?([^"\s,]+)\s+' . $mobile_slot_w2x . 'w#', $source_lane['tag'], $slot_url_2x_match)
                                            && stripos($slot_url_2x_match[1], '/u:') === false) {
                                            $mobile_slot_sources .= '<source media="(max-width: 767.98px) and (min-resolution: 1.91dppx)" srcset="' . $slot_url_2x_match[1] . ' ' . $mobile_slot_w2x . 'w"'
                                                . ' sizes="' . $mobile_slot_w . 'px" type="image/' . $source_lane['type'] . '">';
                                        }
                                    }
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        $mobile_slot_sources = '';
                    }

                    // v7.20.12 — A SINGLE 1x RUNG MAY ONLY SERVE 1x SCREENS. A source whose
                    // srcset offers exactly one sized candidate forces every DPR>=2 display to
                    // upscale it (maisonpro: 75w icon soft on retina). Guard it to
                    // max-resolution:1.9dppx so high-DPR falls through to the fallback <img>
                    // (the zone-proxied original — full bytes, crisp downscale). Multi-rung
                    // srcsets keep letting the browser pick by DPR, unchanged.
                    $single_rung_dpr_guard = function ($tag) {
                        if (!is_string($tag) || $tag === '' || stripos($tag, ' media=') !== false
                            || !preg_match('/srcset="([^"]*)"/i', $tag, $g1m)
                            || strpos($g1m[1], ',') !== false
                            || !preg_match('/-\d+x\d+\.|\/w:\d+\//i', $g1m[1])
                            || !apply_filters('wpc_single_rung_dpr_guard', true)) {
                            return $tag;
                        }
                        return (string) preg_replace('/<source\b/i', '<source media="(max-resolution: 1.9dppx)"', $tag, 1);
                    };
                    $build_image_tag = '<picture class="wpc-picture">'
                        . $mobile_slot_sources
                        . $single_rung_dpr_guard($avifSource)
                        . $single_rung_dpr_guard('<source' . $sourceSrcset . $sourceSizes . ' type="image/webp">')
                        . $fallbackTag
                        . '</picture>';
                }
            }
        }


        if (!empty($_GET['dbgAjaxEnd']) && function_exists('current_user_can') && current_user_can('manage_options')) {
            return esc_html(print_r([$_POST, $_GET, wp_doing_ajax(), self::$isAjax, $image[0]], true));
        }

        if (!empty($_GET['dbg_buildimg']) && function_exists('current_user_can') && current_user_can('manage_options')) {
            return esc_html(print_r([$original_img_tag['original_tags'], $original_img_tag['additional_tags'], str_replace('<img', 'mgi', $build_image_tag)], true));
        }

        if (self::$isAjax) {
            $build_image_tag = addslashes($build_image_tag);
        }

        return $build_image_tag;
    }

    public function ajaxImage($imageElement)
    {
        if ($this->checkIsSlashed($imageElement)) {
            $imageElement = stripslashes($imageElement);
        }

        $newImageElement = '';
        $original_img_tag = [];
        $original_img_tag['original_tags'] = $this->getAllTags($imageElement, []);
        $original_img_tag['original_tags'] = self::wpc_backfill_img_dimensions($original_img_tag['original_tags']);

        if (!empty($_GET['ajaxImage'])) {
            return print_r([$original_img_tag, $imageElement], true);
        }

        if (strpos($original_img_tag['original_tags']['src'], 'data:image') !== false || strpos($original_img_tag['original_tags']['src'], 'blank') !== false) {

            $newImageElement = '<img ';

            foreach ($original_img_tag['original_tags'] as $tag => $value) {
                if ($tag == 'src') {
                    // Do nothing
                } elseif ($tag == 'data-src') {
                    $src = $value;

                    $webp = '/wp:' . self::$webp;
                    if (self::isExcludedFrom('webp', $src)) {
                        $webp = '/wp:0';
                    }

                    // GIF never rides the Bunny zone (no next-gen gain); keep origin. Else transform.
                    if (!(preg_match('/\.gif(\?|#|$)/i', $src) && !self::cf_is_delivery())) {
                        $src = self::$apiUrl . '/r:' . self::$isRetina . $webp . '/w:' . $this::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $src)) . '/u:' . self::uForCdn($src);
                    }
                    $newImageElement .= 'src="' . $src . '" ';
                } else if (!is_null($value)) {
                    $newImageElement .= $tag . '="' . $value . '" ';
                } else {
                    $newImageElement .= $tag . ' ';
                }
            }
            $newImageElement .= '/>';
        } else {
            $newImageElement = $imageElement;
        }

        if ($this->checkIsSlashed($imageElement)) {
            $newImageElement = stripslashes($newImageElement);
        }

        return $newImageElement;
    }

    public static function get_image_size($url)
    {
        preg_match("/([0-9]+)x([0-9]+)\.[a-zA-Z0-9]+/", $url, $matches);
        if (isset($matches[1]) && isset($matches[2])) {
            return [$matches[1], $matches[2]];
        }
        // v7.21.229 — NEVER GUESS SQUARE. The 1024x1024 fallback gave every suffixless
        // image a SQUARE placeholder, and aspect-ratio:auto var(--wpc-ar) prefers the
        // loaded placeholder's intrinsic ratio over the var — a 388x91 image reserved a
        // 388px square, then collapsed ~300px on arrival (bestexteriorsinc mobile CLS
        // 0.165, crit-team receipt). Read the real file; else return the 0x0 sentinel and
        // the minter emits a DIMENSIONLESS placeholder (no intrinsic ratio, the var wins).
        if (class_exists('wps_rewriteLogic') && method_exists('wps_rewriteLogic', 'wpc_true_image_dimensions')) {
            $trueDimensions = wps_rewriteLogic::wpc_true_image_dimensions($url);
            if (is_array($trueDimensions) && !empty($trueDimensions['width']) && !empty($trueDimensions['height'])) {
                return [(int) $trueDimensions['width'], (int) $trueDimensions['height']];
            }
        }
        return [0, 0];
    }

    public function rewriteSrcset($original_img_tag, $srcset)
    {
        if (empty($srcset)) {
            return $srcset;
        }

        if (self::$isMobile) {
            // We are forcing all widths on mobile, no srcset is needed.
            // the w: param has to match the w param from the srcset url or it can break mobile layouts.
            return '';
        }

        $newSrcSet = '';

        preg_match_all('/((https?\:\/\/|\/\/)[^\s]+\S+\.(jpg|jpeg|png|gif|svg|webp))\s(\d{1,5}+[wx])/si', $srcset, $srcset_links);

        // Fix max-width setting for img tag
        $maxWidthMatches = [];
        if (!empty($original_img_tag['original_tags']['sizes'])) {
            preg_match('/max-width:\s*(\d+)px/si', $original_img_tag['original_tags']['sizes'], $maxWidthMatches);
        }


        $largestWidth = 0;
        $largestSrc = '';

        if (!empty($srcset_links[0])) {
            foreach ($srcset_links[0] as $srcsetItem) {
                $parts = preg_split('/\s+/', trim($srcsetItem));
                if (count($parts) < 2) continue;

                $url = trim($parts[0]);
                $w = trim($parts[1]);

                // Only treat "w" candidates as width-based (ignore "x" densities for largest selection)
                if (strpos($w, 'w') !== false) {
                    $wi = (int)str_replace('w', '', $w);
                    if ($wi > $largestWidth) {
                        $largestWidth = $wi;
                        $largestSrc = $url;
                    }
                }
            }
        }

        $originalSrc = $original_img_tag['original_src'] ?? '';

        // Detect WP resized pattern in originalSrc: "-400x70.ext"
        $originalLooksResized = false;
        $originalWidthFromName = 0;

        if (!empty($originalSrc)) {
            if (preg_match('/-(\d{1,5})x(\d{1,5})\.(jpg|jpeg|png|gif|webp)$/i', $originalSrc, $m)) {
                $originalLooksResized = true;
                $originalWidthFromName = (int)$m[1];
            }
        }

        // Decide canonical source
        $fullSrc = $originalSrc;

        // If original is missing OR looks resized OR is smaller than the largest srcset width, promote largest srcset
        if (!empty($largestSrc)) {
            if (empty($fullSrc)) {
                $fullSrc = $largestSrc;
            } elseif ($originalLooksResized) {
                $fullSrc = $largestSrc;
            } elseif ($originalWidthFromName > 0 && $largestWidth > $originalWidthFromName) {
                $fullSrc = $largestSrc;
            }
        }


        $retina_native_w = (int) $largestWidth;


        if (!apply_filters('wpc_retina_clamp_enabled', true)) {
            $retina_native_w = 0;
        }


        if (!empty($srcset_links[0])) {
            $hasXDescriptor = false;

            foreach ($srcset_links[0] as $i => $srcsetItem) {

                $parts = preg_split('/\s+/', trim($srcsetItem));
                if (count($parts) < 2) continue;

                $srcset_url = trim($parts[0]);
                $srcset_width = trim($parts[1]);

                $webp = '/wp:' . self::$webp;
                if (self::isExcludedFrom('webp', $srcset_url)) {
                    $webp = '';
                }

                if (self::isExcludedLink($srcset_url)) {
                    $newSrcSet .= $srcset_url . ' ' . $srcset_width . ', ';
                    continue;
                }

                // Parse descriptor
                $isXDescriptor = (strpos($srcset_width, 'x') !== false);

                if ($isXDescriptor) {
                    $hasXDescriptor = true;
                    $width_val = (int)str_replace('x', '', $srcset_width);
                    $extension = 'x';
                } else {
                    $width_val = (int)str_replace('w', '', $srcset_width);
                    $extension = 'w';
                }

                // Already CDN URL
                if (strpos($srcset_url, self::$zoneName) !== false) {
                    $newSrcSet .= $srcset_url . ' ' . $width_val . $extension . ', ';
                    continue;
                }

                // SVG passthrough
                if (strpos($srcset_url, '.svg') !== false) {
                    $newSrcSet .= 'https://' . self::$zoneName . '/m:0/a:' . self::reformatUrl($srcset_url) . ' ' . $width_val . $extension . ', ';
                    continue;
                }


                if ($isXDescriptor) {
                    $isRetina = ($width_val >= 2) ? '1' : '0';

                    $webpFull = '/wp:' . self::$webp;
                    if (!empty($fullSrc) && self::isExcludedFrom('webp', $fullSrc)) {
                        $webpFull = '';
                    }

                    $rewriteUrl = !empty($fullSrc) ? $fullSrc : $srcset_url;

                    $newSrcSet .= self::$apiUrl . '/r:' . $isRetina . $webpFull . '/w:1/u:' . self::uForCdn($rewriteUrl) . ' ' . $width_val . 'x, ';
                    continue;
                }


                $width_url = $width_val;
                $srcsetWidthExtension = $width_val . 'w';

                // Non-retina URL (use the actual candidate URL)
                $newSrcSet .= self::$apiUrl . '/r:0' . $webp . '/w:' . self::getCurrentMaxWidth($width_url, self::isExcludedFrom('adaptive', $srcset_url)) . '/u:' . self::uForCdn($srcset_url) . ' ' . $srcsetWidthExtension . ', ';

                // Retina URL (use canonical fullSrc)
                if (self::$settings['retina-in-srcset'] == '1' && !empty($fullSrc)) {
                    $retinaWidth = (int)$width_url * 2;


                    if ($retina_native_w <= 0 || $retinaWidth <= $retina_native_w) {
                        $newSrcSet .= self::$apiUrl . '/r:1' . $webp . '/w:' . self::getCurrentMaxWidth($retinaWidth, self::isExcludedFrom('adaptive', $fullSrc)) . '/u:' . self::uForCdn($fullSrc) . ' ' . ($retinaWidth . 'w') . ', ';
                    }
                }
            }

            // Inject 480/960 only for w-descriptor srcsets


            if (!$hasXDescriptor && !empty($maxWidthMatches[1]) && (int)$maxWidthMatches[1] >= 480 && !empty($fullSrc)) {

                $webp = '/wp:' . self::$webp;
                if (self::isExcludedFrom('webp', $fullSrc)) {
                    $webp = '';
                }


                if (($retina_native_w <= 0 || 480 <= $retina_native_w)
                    && apply_filters('wpc_inject_480', true, $fullSrc)) {
                    $newSrcSet .= self::$apiUrl . '/r:0' . $webp . '/w:480/u:' . self::uForCdn($fullSrc) . ' 480w, ';
                }

                if (self::$settings['retina-in-srcset'] == '1' && ($retina_native_w <= 0 || 960 <= $retina_native_w)
                    && apply_filters('wpc_inject_960', true, $fullSrc)) {
                    $newSrcSet .= self::$apiUrl . '/r:1' . $webp . '/w:960/u:' . self::uForCdn($fullSrc) . ' 960w, ';
                }
            }

            $newSrcSet = rtrim($newSrcSet);
            $newSrcSet = rtrim($newSrcSet, ',');

            return $newSrcSet;
        }

        return $srcset;
    }

    public function replace_with_480w($srcset)
    {

        if (!apply_filters('wpc_inject_480', true, $srcset)) {
            return $srcset;
        }
        // First check if 480w already exists in the srcset
        if (preg_match('/\s480w/', $srcset)) {
            return $srcset;
        }

        // Extract both w: values and srcset widths (for URLs) using regex
        preg_match_all('/w:(\d+)/si', $srcset, $w_matches); // Matches the "w:" pattern widths
        preg_match_all('/(\S+)\s(\d+)w/si', $srcset, $srcset_matches); // Matches srcset widths

        $w_widths = array_map('intval', $w_matches[1]); // w: values
        $srcset_widths = array_map('intval', $srcset_matches[2]);

        // Find the nearest width larger than 480 in the srcset
        $nearest = null;
        foreach ($srcset_widths as $width) {
            if ($width > 480 && ($nearest === null || $width < $nearest)) {
                $nearest = $width;
            }
        }

        // Find the nearest "w:" width larger than 480
        $nearest_w = null;
        foreach ($w_widths as $w_width) {
            if ($w_width > 480 && ($nearest_w === null || $w_width < $nearest_w)) {
                $nearest_w = $w_width;
            }
        }

        // Get the URL pattern for the nearest width
        if ($nearest !== null) {
            preg_match('/(.*\s)' . $nearest . 'w/', $srcset, $matches);
            if (!empty($matches)) {
                $url_pattern = $matches[1];
                // Create new 480w entry using the same URL pattern
                $new_480w_entry = $url_pattern . '480w';

                // Insert the new 480w entry before the nearest width entry since it's smaller
                $srcset = str_replace($url_pattern . $nearest . 'w', $new_480w_entry . ', ' . $url_pattern . $nearest . 'w', $srcset);
            }
        }

        // Handle the "w:" part - add w:480 after the nearest w: value
        if ($nearest_w !== null) {
            // Get the full URL pattern containing w:{nearest_w}
            preg_match('/(.*w:)' . $nearest_w . '(.*)/', $srcset, $url_matches);
            if (!empty($url_matches)) {
                $before_w = $url_matches[1];
                $after_w = $url_matches[2];

                // Create a copy of the URL with w:480
                $new_url = str_replace('w:' . $nearest_w, 'w:480', $url_matches[0]);

                // Add the new URL before the existing one since it's smaller
                $parts = explode($url_matches[0], $srcset, 2);
                $srcset = $parts[0] . $new_url . ', ' . $url_matches[0] . (isset($parts[1]) ? $parts[1] : '');
            }
        }

        return $srcset;
    }

    public function cdnSrcsetOnly($srcset)
    {
        if (empty($srcset)) {
            return $srcset;
        }

        $parts = preg_split('/\s*,\s*/', trim($srcset));
        $rebuilt = [];

        foreach ($parts as $candidate) {
            if (empty($candidate)) {
                continue;
            }

            // Match: URL [optional descriptor]
            if (!preg_match('/^\s*(\S+)(?:\s+(.+))?\s*$/', $candidate, $m)) {
                $rebuilt[] = $candidate;
                continue;
            }

            $url = trim($m[1]);
            $descriptor = !empty($m[2]) ? trim($m[2]) : '';

            // Already CDN
            if (strpos($url, self::$zoneName) !== false) {
                $rebuilt[] = trim($url . ' ' . $descriptor);
                continue;
            }

            // Exclusions
            if ($this->defaultExcluded($url) || self::isExcluded($url) || self::isExcludedFrom('cdn', $url)) {
                $rebuilt[] = trim($url . ' ' . $descriptor);
                continue;
            }

            // Must be image and enabled for serving
            if (!self::isImage($url)) {
                $rebuilt[] = trim($url . ' ' . $descriptor);
                continue;
            }

            // Respect external-url setting
            if ((self::$externalUrlEnabled == 'false' || self::$externalUrlEnabled == '0') && !self::imageUrlMatchingSiteUrl($url)) {
                $rebuilt[] = trim($url . ' ' . $descriptor);
                continue;
            }

            // SVG should use asset endpoint, raster images use image endpoint
            if (stripos($url, '.svg') !== false) {
                $cdnUrl = 'https://' . self::$zoneName . '/m:0/a:' . self::reformatUrl($url);
            } else {
                $webp = '/wp:' . self::$webp;
                if (self::isExcludedFrom('webp', $url)) {
                    $webp = '';
                }

                $cdnUrl = self::$apiUrl
                    . '/r:' . self::$isRetina
                    . $webp
                    . '/w:' . self::getCurrentMaxWidth(1, self::isExcludedFrom('adaptive', $url))
                    . '/u:' . self::uForCdn($url);
            }

            $rebuilt[] = trim($cdnUrl . ' ' . $descriptor);
        }

        return implode(', ', $rebuilt);
    }


}